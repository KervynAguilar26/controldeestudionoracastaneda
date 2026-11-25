<?php
// /sistema_nora/includes/representante_ubicacion.php
// Ubicación escolar del representante de un niño del Centro de Cuidados Infantiles.
// La información se deriva de los datos ya cargados en sistema_nora_v2:
// estudiantes -> inscripción/cohorte/salón; docentes -> horarios/asignaciones;
// personal -> cargo y espacios permitidos.

if (!function_exists('rep_roles_leer')) {
    function rep_roles_leer($raw): array
    {
        if (is_array($raw)) { return $raw; }
        $raw = trim((string) $raw);
        if ($raw === '' || $raw === '{}') { return []; }
        if ($raw[0] === '{') {
            $raw = substr($raw, 1, -1);
        }
        $salida = [];
        foreach (explode(',', $raw) as $valor) {
            $valor = trim(trim($valor), '"');
            if ($valor !== '') { $salida[] = $valor; }
        }
        return $salida;
    }
}

if (!function_exists('rep_espacio_fisico')) {
    /** Devuelve edificio/piso de un salón según el inventario de infraestructura. */
    function rep_espacio_fisico(PDO $pdo, string $nombre): array
    {
        $nombre = trim($nombre);
        if ($nombre === '') { return []; }
        try {
            $st = $pdo->prepare("SELECT e.nombre_espacio, e.piso_numero, t.nombre_torre, t.ubicacion_sede
                                   FROM public.campus_espacios e
                                   LEFT JOIN public.campus_edificios t ON t.id_edificio = e.id_edificio
                                  WHERE lower(trim(e.nombre_espacio)) = lower(:nom)
                                  LIMIT 1");
            $st->execute([':nom' => $nombre]);
            $fila = $st->fetch();
            return $fila ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('representante_ubicacion')) {
    /**
     * @return array{rol:string, detalle:array<int,array{0:string,1:string}>, salones:array, texto:string}
     */
    function representante_ubicacion(PDO $pdo, int $id_usuario): array
    {
        $resultado = [
            'rol'     => 'Persona del sistema escolar',
            'detalle' => [],
            'salones' => [],
            'texto'   => 'Sin ubicación registrada en el sistema.',
        ];

        try {
            $st = $pdo->prepare("SELECT id_usuario, nombre, apellido, tipo_usuario, roles_asignados,
                                        cargo_funcion, espacios_permitidos
                                   FROM public.usuarios WHERE id_usuario = :id LIMIT 1");
            $st->execute([':id' => $id_usuario]);
            $u = $st->fetch();
        } catch (Throwable $e) {
            return $resultado;
        }
        if (!$u) { return $resultado; }

        $roles = rep_roles_leer($u['roles_asignados'] ?? null);
        $tipo  = strtolower(trim((string) $u['tipo_usuario']));
        $esEstudiante = ($tipo === 'estudiante' || in_array('estudiante', $roles, true));
        $esDocente    = ($tipo === 'profesor' || in_array('profesor', $roles, true));

        // ── Estudiante: sección y salón donde cursa ──
        if ($esEstudiante) {
            $resultado['rol'] = 'Estudiante';
            try {
                $st = $pdo->prepare("SELECT c.codigo_cohorte, c.salon_aula, c.dias_clase,
                                            c.hora_inicio, c.hora_fin, c.estatus AS estatus_seccion,
                                            m.nombre_programa, m.tipo_oferta
                                       FROM public.inscripciones i
                                       JOIN public.cohortes c ON c.id_cohorte = i.id_curso
                                       LEFT JOIN public.mallas_curriculares m ON m.id_malla = c.id_malla
                                      WHERE i.id_estudiante = :id
                                        AND c.estatus <> 'Cancelada'
                                      ORDER BY CASE c.estatus
                                                   WHEN 'En Curso' THEN 0
                                                   WHEN 'Planificada' THEN 1
                                                   ELSE 2 END,
                                               i.fecha_inscripcion DESC
                                      LIMIT 1");
                $st->execute([':id' => $id_usuario]);
                $ins = $st->fetch();

                if ($ins) {
                    if (!empty($ins['nombre_programa'])) {
                        $resultado['detalle'][] = ['Programa', (string) $ins['nombre_programa']];
                    }
                    if (!empty($ins['codigo_cohorte'])) {
                        $resultado['detalle'][] = ['Sección', (string) $ins['codigo_cohorte']];
                    }
                    if (!empty($ins['dias_clase'])) {
                        $resultado['detalle'][] = ['Días de clase', (string) $ins['dias_clase']];
                    }
                    if (!empty($ins['hora_inicio']) || !empty($ins['hora_fin'])) {
                        $hi = !empty($ins['hora_inicio']) ? date('h:i A', strtotime($ins['hora_inicio'])) : '--';
                        $hf = !empty($ins['hora_fin']) ? date('h:i A', strtotime($ins['hora_fin'])) : '--';
                        $resultado['detalle'][] = ['Horario', $hi . ' - ' . $hf];
                    }

                    $salones = array_filter(array_map('trim', explode(',', (string) ($ins['salon_aula'] ?? ''))));
                    foreach ($salones as $salon) {
                        if ($salon === '' || $salon === 'Por asignar') { continue; }
                        $info = rep_espacio_fisico($pdo, $salon);
                        $ubicacion = $salon;
                        if (!empty($info['nombre_torre'])) {
                            $ubicacion .= ' · ' . $info['nombre_torre'];
                        }
                        if (isset($info['piso_numero']) && $info['piso_numero'] !== '') {
                            $ubicacion .= ' · Piso ' . (int) $info['piso_numero'];
                        }
                        $resultado['salones'][] = $ubicacion;
                    }
                    if ($resultado['salones']) {
                        $resultado['detalle'][] = ['Salón / Aula', implode('; ', $resultado['salones'])];
                    }
                } else {
                    $resultado['detalle'][] = ['Situación', 'Sin sección activa registrada'];
                }
            } catch (Throwable $e) {
                $resultado['detalle'][] = ['Situación', 'No fue posible consultar su sección'];
            }

        // ── Docente: aulas donde imparte clases ──
        } elseif ($esDocente) {
            $resultado['rol'] = 'Docente';
            try {
                $st = $pdo->prepare("SELECT h.dia_semana, h.hora_inicio, h.salon_aula,
                                            mm.nombre_materia, c.codigo_cohorte
                                       FROM public.horarios_secciones h
                                       JOIN public.malla_materias mm ON mm.id_materia = h.id_materia
                                       LEFT JOIN public.cohortes c ON c.id_cohorte = h.id_cohorte
                                      WHERE h.id_profesor = :id
                                      ORDER BY h.dia_semana, h.hora_inicio");
                $st->execute([':id' => $id_usuario]);
                $filas = $st->fetchAll();

                if (!$filas) {
                    $st = $pdo->prepare("SELECT c.salon_aula, c.codigo_cohorte, mm.nombre_materia
                                           FROM public.asignaciones_profesor ap
                                           JOIN public.malla_materias mm ON mm.id_materia = ap.id_materia
                                           LEFT JOIN public.cohortes c ON c.id_cohorte = ap.id_cohorte
                                          WHERE ap.id_profesor = :id");
                    $st->execute([':id' => $id_usuario]);
                    $filas = $st->fetchAll();
                }

                $vistos = [];
                foreach ($filas as $f) {
                    $salon = trim((string) ($f['salon_aula'] ?? ''));
                    if ($salon === '' || $salon === 'Por asignar' || isset($vistos[$salon])) { continue; }
                    $vistos[$salon] = true;
                    $info = rep_espacio_fisico($pdo, $salon);
                    $ubicacion = $salon;
                    if (!empty($info['nombre_torre'])) { $ubicacion .= ' · ' . $info['nombre_torre']; }
                    if (isset($info['piso_numero']) && $info['piso_numero'] !== '') {
                        $ubicacion .= ' · Piso ' . (int) $info['piso_numero'];
                    }
                    $resultado['salones'][] = $ubicacion;
                }

                if ($filas) {
                    $materias = [];
                    foreach ($filas as $f) {
                        if (!empty($f['nombre_materia'])) { $materias[] = $f['nombre_materia']; }
                    }
                    if ($materias) {
                        $resultado['detalle'][] = ['Materias', implode(', ', array_unique($materias))];
                    }
                    $resultado['detalle'][] = ['Salones / Aulas', $resultado['salones']
                        ? implode('; ', $resultado['salones'])
                        : 'Sin salón asignado'];
                } else {
                    $resultado['detalle'][] = ['Situación', 'Sin horario asignado registrado'];
                }
            } catch (Throwable $e) {
                $resultado['detalle'][] = ['Situación', 'No fue posible consultar su horario'];
            }

        // ── Personal administrativo / de apoyo ──
        } else {
            $etiqueta = ucfirst(str_replace('_', ' ', $tipo));
            $resultado['rol'] = $etiqueta !== '' ? $etiqueta : 'Personal del sistema escolar';
            if (!empty($u['cargo_funcion'])) {
                $resultado['detalle'][] = ['Cargo / Función', (string) $u['cargo_funcion']];
            }
            $espacios = json_decode((string) ($u['espacios_permitidos'] ?? ''), true);
            if (is_array($espacios)) {
                $ids = [];
                foreach ($espacios as $valor) {
                    if (is_array($valor) && isset($valor['id_espacio'])) { $valor = $valor['id_espacio']; }
                    $id = intval($valor);
                    if ($id > 0) { $ids[] = $id; }
                }
                if ($ids) {
                    try {
                        $marcadores = implode(',', array_fill(0, count($ids), '?'));
                        $st = $pdo->prepare("SELECT e.nombre_espacio, e.piso_numero, t.nombre_torre
                                               FROM public.campus_espacios e
                                               LEFT JOIN public.campus_edificios t ON t.id_edificio = e.id_edificio
                                              WHERE e.id_espacio IN ($marcadores)");
                        $st->execute($ids);
                        foreach ($st->fetchAll() as $esp) {
                            $txt = (string) $esp['nombre_espacio'];
                            if (!empty($esp['nombre_torre'])) { $txt .= ' · ' . $esp['nombre_torre']; }
                            if (isset($esp['piso_numero']) && $esp['piso_numero'] !== '') {
                                $txt .= ' · Piso ' . (int) $esp['piso_numero'];
                            }
                            $resultado['salones'][] = $txt;
                        }
                        if ($resultado['salones']) {
                            $resultado['detalle'][] = ['Espacios', implode('; ', $resultado['salones'])];
                        }
                    } catch (Throwable $e) {
                        // Sin inventario disponible: se conserva solo el cargo.
                    }
                }
            }
        }

        $partes = [$resultado['rol']];
        foreach ($resultado['detalle'] as $d) {
            $partes[] = $d[0] . ': ' . $d[1];
        }
        $resultado['texto'] = implode(' · ', $partes);
        return $resultado;
    }
}
