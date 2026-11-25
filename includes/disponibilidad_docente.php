<?php
/**
 * /sistema_nora/includes/disponibilidad_docente.php
 *
 * Reglas centralizadas de disponibilidad y carga académica del docente.
 * Se usa desde:
 *  - modulos/usuarios/crear.php y editar.php (carga de la disponibilidad)
 *  - modulos/control_estudio/lista_profesores.php (asignación de cátedras)
 *  - modulos/control_estudio/gestionar_horarios.php (cuadre de horarios)
 */

// ── Catálogo de bloques (idéntico al usado por el cuadre de horarios) ──
if (!function_exists('docente_bloques')) {
    function docente_bloques(): array
    {
        return [
            ['inicio' => '07:00:00', 'fin' => '07:45:00', 'etiqueta' => '07:00 AM - 07:45 AM (Matutino)',        'clase' => true],
            ['inicio' => '08:00:00', 'fin' => '08:45:00', 'etiqueta' => '08:00 AM - 08:45 AM (Hora 1)',          'clase' => true],
            ['inicio' => '08:45:00', 'fin' => '09:30:00', 'etiqueta' => '08:45 AM - 09:30 AM (Hora 2)',          'clase' => true],
            ['inicio' => '09:30:00', 'fin' => '09:45:00', 'etiqueta' => '09:30 AM - 09:45 AM (Receso)',          'clase' => false],
            ['inicio' => '09:45:00', 'fin' => '10:30:00', 'etiqueta' => '09:45 AM - 10:30 AM (Hora 3)',          'clase' => true],
            ['inicio' => '10:30:00', 'fin' => '11:15:00', 'etiqueta' => '10:30 AM - 11:15 AM (Hora 4)',          'clase' => true],
            ['inicio' => '11:15:00', 'fin' => '12:00:00', 'etiqueta' => '11:15 AM - 12:00 PM (Hora 5)',          'clase' => true],
            ['inicio' => '12:00:00', 'fin' => '13:15:00', 'etiqueta' => '12:00 PM - 01:15 PM (Almuerzo)',        'clase' => false],
            ['inicio' => '13:30:00', 'fin' => '14:15:00', 'etiqueta' => '01:30 PM - 02:15 PM (Hora 6 / Tarde)',  'clase' => true],
            ['inicio' => '14:15:00', 'fin' => '15:00:00', 'etiqueta' => '02:15 PM - 03:00 PM (Hora 7 / Tarde)',  'clase' => true],
            ['inicio' => '15:00:00', 'fin' => '15:15:00', 'etiqueta' => '03:00 PM - 03:15 PM (Merienda)',        'clase' => false],
            ['inicio' => '15:15:00', 'fin' => '16:00:00', 'etiqueta' => '03:15 PM - 04:00 PM (Hora 8 / Tarde)',  'clase' => true],
            ['inicio' => '18:00:00', 'fin' => '19:00:00', 'etiqueta' => '06:00 PM - 07:00 PM (Cena)',            'clase' => false],
            ['inicio' => '19:00:00', 'fin' => '20:30:00', 'etiqueta' => '07:00 PM - 08:30 PM (Nocturno)',        'clase' => true],
        ];
    }
}

if (!function_exists('docente_dias')) {
    function docente_dias(): array
    {
        return ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];
    }
}

if (!function_exists('docente_normalizar_hora')) {
    function docente_normalizar_hora($hora): string
    {
        $hora = trim((string) $hora);
        if ($hora === '') {
            return '';
        }
        $ts = strtotime('2000-01-01 ' . $hora);
        return $ts ? date('H:i:s', $ts) : '';
    }
}

/** 1 hora académica = 45 minutos. Bloques especiales de 90 min = 2 h. */
if (!function_exists('docente_horas_bloque')) {
    function docente_horas_bloque(string $inicio, string $fin): int
    {
        $ini = docente_normalizar_hora($inicio);
        $ff  = docente_normalizar_hora($fin);
        if ($ini === '' || $ff === '') {
            return 0;
        }
        $minutos = (strtotime('2000-01-01 ' . $ff) - strtotime('2000-01-01 ' . $ini)) / 60;
        if ($minutos <= 0) {
            return 0;
        }
        return max(1, (int) round($minutos / 45));
    }
}

if (!function_exists('docente_hora_formato')) {
    function docente_hora_formato(string $hora): string
    {
        $h = docente_normalizar_hora($hora);
        return $h ? date('h:i A', strtotime('2000-01-01 ' . $h)) : 'N/D';
    }
}

if (!function_exists('docente_bloque_solapa')) {
    function docente_bloque_solapa(string $ini1, string $fin1, string $ini2, string $fin2): bool
    {
        $a = docente_normalizar_hora($ini1);
        $b = docente_normalizar_hora($fin1);
        $c = docente_normalizar_hora($ini2);
        $d = docente_normalizar_hora($fin2);
        if ($a === '' || $b === '' || $c === '' || $d === '') {
            return false;
        }
        return $a < $d && $b > $c;
    }
}

/**
 * Horas académicas semanales cubiertas por la disponibilidad declarada del docente.
 * Devuelve null cuando no hay restricción (puede impartir cualquier bloque).
 *
 * Ojo: las horas_academicas de malla_materias son el total del curso (p. ej. 120 h)
 * y NO se usan para medir la jornada ni la carga semanal del docente.
 */
if (!function_exists('docente_horas_disponibles')) {
    function docente_horas_disponibles(array $disponibilidad): ?int
    {
        if (!docente_tiene_restriccion($disponibilidad)) {
            return null;
        }
        $fin_por_inicio = [];
        foreach (docente_bloques() as $b) {
            if ($b['clase']) {
                $fin_por_inicio[$b['inicio']] = $b['fin'];
            }
        }
        $total = 0;
        foreach ($disponibilidad as $horas_dia) {
            foreach ($horas_dia as $inicio) {
                $fin = $fin_por_inicio[$inicio] ?? null;
                $total += $fin ? docente_horas_bloque($inicio, $fin) : 1;
            }
        }
        return $total;
    }
}

/** Convierte el JSONB de disponibilidad a un arreglo [día => [inicios H:i:s]]. */
if (!function_exists('docente_disponibilidad_leer')) {
    function docente_disponibilidad_leer($raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw)) {
            return [];
        }

        $validos = [];
        foreach (docente_bloques() as $b) {
            if ($b['clase']) {
                $validos[$b['inicio']] = true;
            }
        }

        $salida = [];
        foreach (docente_dias() as $dia) {
            if (!isset($raw[$dia]) || !is_array($raw[$dia])) {
                continue;
            }
            foreach ($raw[$dia] as $h) {
                $hn = docente_normalizar_hora($h);
                if ($hn !== '' && isset($validos[$hn])) {
                    $salida[$dia][] = $hn;
                }
            }
            if (!empty($salida[$dia])) {
                $salida[$dia] = array_values(array_unique($salida[$dia]));
                sort($salida[$dia]);
            }
        }
        return $salida;
    }
}

/** Devuelve el JSONB a guardar (cadena vacía = sin restricción). */
if (!function_exists('docente_disponibilidad_json')) {
    function docente_disponibilidad_json($raw): string
    {
        $limpio = docente_disponibilidad_leer($raw);
        return empty($limpio) ? '' : json_encode($limpio, JSON_UNESCAPED_UNICODE);
    }
}

/** Espacios escolares autorizados: arreglo de id_espacio guardado como JSONB. */
if (!function_exists('docente_espacios_leer')) {
    function docente_espacios_leer($raw): array
    {
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        if (!is_array($raw)) {
            return [];
        }
        $salida = [];
        foreach ($raw as $valor) {
            if (is_array($valor) && isset($valor['id_espacio'])) {
                $valor = $valor['id_espacio'];
            }
            $id = intval($valor);
            if ($id > 0 && !in_array($id, $salida, true)) {
                $salida[] = $id;
            }
        }
        return $salida;
    }
}

if (!function_exists('docente_espacios_json')) {
    function docente_espacios_json($raw): string
    {
        $ids = docente_espacios_leer($raw);
        return empty($ids) ? '' : json_encode($ids);
    }
}

if (!function_exists('docente_espacios_nombres')) {
    function docente_espacios_nombres(PDO $pdo, array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (empty($ids)) {
            return [];
        }
        $marcadores = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare("SELECT nombre_espacio FROM public.campus_espacios WHERE id_espacio IN ($marcadores)");
        $stmt->execute($ids);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}

/** Sin disponibilidad guardada = sin restricción (compatibilidad con datos previos). */
if (!function_exists('docente_tiene_restriccion')) {
    function docente_tiene_restriccion(array $disponibilidad): bool
    {
        return !empty($disponibilidad);
    }
}

if (!function_exists('docente_esta_disponible')) {
    function docente_esta_disponible(array $disponibilidad, string $dia, string $inicio): bool
    {
        if (!docente_tiene_restriccion($disponibilidad)) {
            return true;
        }
        $hi = docente_normalizar_hora($inicio);
        return isset($disponibilidad[$dia]) && in_array($hi, $disponibilidad[$dia], true);
    }
}

if (!function_exists('docente_datos')) {
    function docente_datos(PDO $pdo, int $id_usuario): ?array
    {
        $stmt = $pdo->prepare("
            SELECT id_usuario, nombre, apellido, cedula, tipo_usuario, roles_asignados,
                   horas_semanales_min, horas_semanales_max,
                   disponibilidad_horaria, espacios_permitidos
              FROM public.usuarios
             WHERE id_usuario = :id
             LIMIT 1
        ");
        $stmt->execute([':id' => $id_usuario]);
        $u = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$u) {
            return null;
        }
        $u['horas_semanales_min'] = intval($u['horas_semanales_min']);
        $u['horas_semanales_max'] = intval($u['horas_semanales_max']);
        $u['disponibilidad'] = docente_disponibilidad_leer($u['disponibilidad_horaria']);
        $u['espacios_permitidos'] = docente_espacios_leer($u['espacios_permitidos'] ?? null);
        return $u;
    }
}

if (!function_exists('docente_nombre')) {
    function docente_nombre(array $u): string
    {
        $nombre = trim(($u['nombre'] ?? '') . ' ' . ($u['apellido'] ?? ''));
        return $nombre !== '' ? $nombre : 'Docente #' . ($u['id_usuario'] ?? '?');
    }
}

/** PostgreSQL devuelve los arrays como "{profesor,admin}"; normaliza a PHP array. */
if (!function_exists('docente_roles_array')) {
    function docente_roles_array($roles): array
    {
        if (is_array($roles)) {
            return array_values(array_filter(array_map('trim', $roles)));
        }
        $limpio = trim((string) $roles, "{} \t\n\r\0\x0B");
        if ($limpio === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $limpio))));
    }
}

if (!function_exists('docente_es_profesor')) {
    function docente_es_profesor(array $u): bool
    {
        return ($u['tipo_usuario'] ?? '') === 'profesor'
            || in_array('profesor', docente_roles_array($u['roles_asignados'] ?? []), true);
    }
}

/**
 * Horas semanales ya cargadas en el horario de trabajo (horarios_secciones).
 * $extra permite sumar bloques pendientes de insertar (['dia'=>, 'inicio'=>, 'fin'=>]).
 */
if (!function_exists('docente_horas_cargadas')) {
    function docente_horas_cargadas(PDO $pdo, int $id_profesor, array $extra = [], int $excluir_cohorte = 0): array
    {
        $sql = "
            SELECT hs.dia_semana, hs.hora_inicio, hs.hora_fin, hs.salon_aula, hs.id_materia,
                   mm.nombre_materia, co.codigo_cohorte
              FROM public.horarios_secciones hs
              LEFT JOIN public.malla_materias mm ON mm.id_materia = hs.id_materia
              LEFT JOIN public.cohortes co ON co.id_cohorte = hs.id_cohorte
             WHERE hs.id_profesor = :p
        ";
        if ($excluir_cohorte > 0) {
            $sql .= " AND hs.id_cohorte <> :exc ";
        }
        $sql .= " ORDER BY hs.dia_semana, hs.hora_inicio ";
        $stmt = $pdo->prepare($sql);
        $params = [':p' => $id_profesor];
        if ($excluir_cohorte > 0) {
            $params[':exc'] = $excluir_cohorte;
        }
        $stmt->execute($params);

        $total = 0;
        $por_dia = [];
        $detalle = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $h = docente_horas_bloque($r['hora_inicio'], $r['hora_fin']);
            $total += $h;
            $dia = $r['dia_semana'];
            $por_dia[$dia] = ($por_dia[$dia] ?? 0) + $h;
            $detalle[] = [
                'dia' => $dia,
                'inicio' => docente_normalizar_hora($r['hora_inicio']),
                'fin' => docente_normalizar_hora($r['hora_fin']),
                'horas' => $h,
                'materia' => $r['nombre_materia'] ?? '',
                'seccion' => $r['codigo_cohorte'] ?? '',
                'salon' => $r['salon_aula'] ?? '',
            ];
        }
        foreach ($extra as $e) {
            $h = docente_horas_bloque($e['inicio'], $e['fin']);
            $total += $h;
            $dia = $e['dia'];
            $por_dia[$dia] = ($por_dia[$dia] ?? 0) + $h;
            $detalle[] = [
                'dia' => $dia,
                'inicio' => docente_normalizar_hora($e['inicio']),
                'fin' => docente_normalizar_hora($e['fin']),
                'horas' => $h,
                'materia' => $e['materia'] ?? 'Nueva asignación',
                'seccion' => $e['seccion'] ?? '',
                'salon' => $e['salon'] ?? '',
            ];
        }
        return ['total' => $total, 'por_dia' => $por_dia, 'detalle' => $detalle];
    }
}

/**
 * Horario semanal del docente listo para mostrar en pantalla o impresión.
 * Incluye el espacio/aula donde imparte cada clase.
 */
if (!function_exists('docente_horario_detalle')) {
    function docente_horario_detalle(PDO $pdo, int $id_profesor): array
    {
        $stmt = $pdo->prepare("
            SELECT hs.dia_semana, hs.hora_inicio, hs.hora_fin, hs.salon_aula,
                   mm.nombre_materia, mc.nombre_programa, co.codigo_cohorte
              FROM public.horarios_secciones hs
              JOIN public.malla_materias mm ON mm.id_materia = hs.id_materia
              LEFT JOIN public.cohortes co ON co.id_cohorte = hs.id_cohorte
              LEFT JOIN public.mallas_curriculares mc ON mc.id_malla = co.id_malla
             WHERE hs.id_profesor = :p
             ORDER BY hs.dia_semana, hs.hora_inicio
        ");
        $stmt->execute([':p' => $id_profesor]);

        $registros = [];
        $matriz = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $inicio = docente_normalizar_hora($r['hora_inicio']);
            $item = [
                'dia' => $r['dia_semana'],
                'inicio' => $inicio,
                'fin' => docente_normalizar_hora($r['hora_fin']),
                'horas' => docente_horas_bloque($r['hora_inicio'], $r['hora_fin']),
                'materia' => $r['nombre_materia'] ?? '',
                'programa' => $r['nombre_programa'] ?? '',
                'seccion' => $r['codigo_cohorte'] ?? '',
                'salon' => $r['salon_aula'] ?? '',
            ];
            $registros[] = $item;
            $matriz[$item['dia']][$inicio] = $item;
        }

        $carga = docente_horas_cargadas($pdo, $id_profesor);
        return [
            'registros' => $registros,
            'matriz' => $matriz,
            'horas' => $carga['total'],
            'por_dia' => $carga['por_dia'],
        ];
    }
}

/**
 * Valida un bloque antes de cargarlo: disponibilidad, solapamiento de aulas
 * y límite de horas semanales del horario de trabajo.
 */
if (!function_exists('docente_validar_bloque')) {
    function docente_validar_bloque(
        PDO $pdo,
        int $id_profesor,
        string $dia,
        string $inicio,
        string $fin,
        string $salon_destino = '',
        int $excluir_horario = 0
    ): array {
        $u = docente_datos($pdo, $id_profesor);
        if (!$u) {
            return ['ok' => false, 'codigo' => 'NO_EXISTE', 'mensaje' => "El docente #{$id_profesor} no existe."];
        }
        $nombre = docente_nombre($u);
        $ini = docente_normalizar_hora($inicio);
        $ff  = docente_normalizar_hora($fin);
        if ($ini === '' || $ff === '') {
            return ['ok' => false, 'codigo' => 'HORA_INVALIDA', 'mensaje' => "Bloque horario inválido para {$nombre}."];
        }

        // 1) Días y horas disponibles del docente
        if (!docente_esta_disponible($u['disponibilidad'], $dia, $ini)) {
            return [
                'ok' => false,
                'codigo' => 'NO_DISPONIBLE',
                'mensaje' => "{$nombre} no tiene disponibilidad registrada para el día {$dia} a las " . docente_hora_formato($ini) . ". Ajuste su disponibilidad o cambie el bloque.",
            ];
        }

        // 1b) Espacios escolares autorizados para el docente
        if (!empty($u['espacios_permitidos']) && $salon_destino !== '') {
            $nombres_espacios = docente_espacios_nombres($pdo, $u['espacios_permitidos']);
            $autorizado = false;
            foreach ($nombres_espacios as $nombre_espacio) {
                if (stripos($salon_destino, $nombre_espacio) !== false) {
                    $autorizado = true;
                    break;
                }
            }
            if (!$autorizado) {
                return [
                    'ok' => false,
                    'codigo' => 'ESPACIO_NO_PERMITIDO',
                    'mensaje' => "{$nombre} no tiene autorizado el espacio \"{$salon_destino}\". Actualice sus espacios en la jornada docente.",
                ];
            }
        }

        // 2) Un docente no puede estar en dos aulas al mismo tiempo
        $sql_choque = "
            SELECT hs.id_horario, hs.salon_aula, hs.dia_semana, hs.hora_inicio, hs.hora_fin,
                   co.codigo_cohorte, mm.nombre_materia
              FROM public.horarios_secciones hs
              LEFT JOIN public.cohortes co ON co.id_cohorte = hs.id_cohorte
              LEFT JOIN public.malla_materias mm ON mm.id_materia = hs.id_materia
             WHERE hs.id_profesor = :p
               AND hs.dia_semana = :dia
               AND hs.hora_inicio < CAST(:fin AS time)
               AND hs.hora_fin > CAST(:ini AS time)
               AND hs.id_horario <> :exc
             LIMIT 1
        ";
        $stmt = $pdo->prepare($sql_choque);
        $stmt->execute([
            ':p'   => $id_profesor,
            ':dia' => $dia,
            ':ini' => $ini,
            ':fin' => $ff,
            ':exc' => $excluir_horario,
        ]);
        $choque = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($choque) {
            $mensaje = "Choque de aula: {$nombre} ya imparte " . ($choque['nombre_materia'] ?? 'una clase') .
                " el día {$choque['dia_semana']} de " . docente_hora_formato($choque['hora_inicio']) .
                " a " . docente_hora_formato($choque['hora_fin']) .
                " en el aula " . ($choque['salon_aula'] ?? 'N/D') .
                " (sección " . ($choque['codigo_cohorte'] ?? 'N/D') . ").";
            if ($salon_destino !== '' && $salon_destino !== ($choque['salon_aula'] ?? '')) {
                $mensaje .= " No se puede asignar también al aula {$salon_destino} en ese mismo bloque.";
            }
            return ['ok' => false, 'codigo' => 'CHOQUE', 'mensaje' => $mensaje];
        }

        // 3) Tope de horas semanales del horario de trabajo
        $cargadas = docente_horas_cargadas($pdo, $id_profesor);
        $nuevas   = docente_horas_bloque($ini, $ff);
        $maximo   = $u['horas_semanales_max'];
        if (($cargadas['total'] + $nuevas) > $maximo) {
            return [
                'ok' => false,
                'codigo' => 'EXCEDIDO',
                'mensaje' => "Límite de carga superado: {$nombre} tiene {$cargadas['total']} h asignadas en su horario de trabajo de un máximo de {$maximo} h semanales. No se pueden asignar {$nuevas} h adicionales.",
                'cargadas' => $cargadas['total'],
                'maximo' => $maximo,
            ];
        }

        return [
            'ok' => true,
            'codigo' => 'OK',
            'docente' => $nombre,
            'cargadas' => $cargadas['total'],
            'nuevas' => $nuevas,
            'proyectadas' => $cargadas['total'] + $nuevas,
            'maximo' => $maximo,
        ];
    }
}
