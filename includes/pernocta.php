<?php
// /sistema_nora/includes/pernocta.php
// Funciones compartidas del flujo de eventos con pernocta:
//   - Resolución de estudiantes por cédula (crea cuenta si hace falta).
//   - Inscripción a eventos con pernocta.
//   - Ocupación de camas, responsables, mudanzas y prórrogas.
// Fuente de verdad de las camas: public.dormitorios (id_dormitorio, capacidad_camas).

if (!function_exists('pernocta_cedula_limpia')) {
    /** Normaliza una cédula a solo dígitos (acepta V-12345678 / E-...). */
    function pernocta_cedula_limpia($valor): string
    {
        return preg_replace('/\D+/', '', trim((string) $valor));
    }
}

if (!function_exists('pernocta_e')) {
    /** Escape seguro para salida HTML. */
    function pernocta_e($valor): string
    {
        return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('pernocta_usuario_por_cedula')) {
    /**
     * Busca un usuario del sistema por cédula (tolera prefijos V-/E- y espacios).
     *
     * @return array|null
     */
    function pernocta_usuario_por_cedula(PDO $pdo, string $cedula): ?array
    {
        $cedula = pernocta_cedula_limpia($cedula);
        if ($cedula === '') {
            return null;
        }
        try {
            $st = $pdo->prepare("SELECT id_usuario, nombre, apellido, cedula, telefono, correo, email,
                                        fecha_nacimiento, sexo, tipo_usuario, estatus_cuenta
                                   FROM public.usuarios
                                  WHERE regexp_replace(cedula, '[^0-9]', '', 'g') = :ced
                                  LIMIT 1");
            $st->execute([':ced' => $cedula]);
            $fila = $st->fetch(PDO::FETCH_ASSOC);
            return $fila ?: null;
        } catch (Throwable $e) {
            error_log('PERNOCTA usuario cédula: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('pernocta_resolver_estudiante')) {
    /**
     * Resuelve (o crea) un usuario a partir de una cédula para inscribirlo en un evento.
     * Usa Padrón Nacional para completar identidad; si no hay cuenta, la crea con clave genérica.
     *
     * @return array{ok:bool, id_usuario:?int, nombre:string, cedula:string, creado:bool, error:string}
     */
    function pernocta_resolver_estudiante(
        PDO $pdo,
        $pdo_padron,
        string $cedula,
        string $nombre_manual = '',
        string $telefono_manual = ''
    ): array {
        $cedula = pernocta_cedula_limpia($cedula);
        $resultado = [
            'ok' => false, 'id_usuario' => null, 'nombre' => '', 'cedula' => $cedula,
            'creado' => false, 'error' => '', 'fuente' => 'SISTEMA',
        ];

        if ($cedula === '') {
            $resultado['error'] = 'Indique una cédula válida.';
            return $resultado;
        }

        $usuario = pernocta_usuario_por_cedula($pdo, $cedula);
        if ($usuario) {
            $resultado['ok'] = true;
            $resultado['id_usuario'] = (int) $usuario['id_usuario'];
            $resultado['nombre'] = trim(((string) $usuario['nombre']) . ' ' . ((string) ($usuario['apellido'] ?? '')));
            $resultado['fuente'] = 'SISTEMA';
            return $resultado;
        }

        // No existe en el sistema: completar desde el Padrón Nacional.
        $padron = null;
        if (function_exists('recepcion_buscar_padron')) {
            $padron = recepcion_buscar_padron($pdo_padron, $cedula);
        }
        if (!$padron) {
            $resultado['error'] = 'La cédula ' . $cedula . ' no está registrada en el sistema ni en el Padrón Nacional. '
                . 'Verifique el número o solicite el registro previo del usuario.';
            return $resultado;
        }

        $nombre  = trim((string) ($padron['nombre'] ?? ''));
        $apellido = trim((string) ($padron['apellido'] ?? ''));
        $completo = trim($nombre . ' ' . $apellido);
        if ($completo === '' && trim($nombre_manual) !== '') {
            $completo = trim($nombre_manual);
            $partes = explode(' ', $completo);
            $nombre = array_shift($partes) ?: $completo;
            $apellido = implode(' ', $partes);
        }
        if ($completo === '') {
            $resultado['error'] = 'No fue posible obtener el nombre de la cédula. Indique el nombre manualmente.';
            return $resultado;
        }
        if ($nombre === '') { $nombre = $completo; }

        $sexo = null;
        $genero = strtoupper((string) ($padron['genero'] ?? ''));
        if (strpos($genero, 'FEMEN') !== false || $genero === 'F') { $sexo = 'Femenino'; }
        elseif (strpos($genero, 'MASCUL') !== false || $genero === 'M') { $sexo = 'Masculino'; }

        $fnac = $padron['fecha_nacimiento'] ?? null;
        $edad = null;
        if ($fnac) {
            try {
                $edad = (new DateTime($fnac))->diff(new DateTime())->y;
            } catch (Throwable $e) { $edad = null; }
        }

        $telefono = preg_replace('/[^0-9+]/', '', $telefono_manual);
        if ($telefono === '' && $sexo !== null) {
            // sin teléfono del padrón, se deja NULL
        }
        $clave_hash = password_hash('Nora123456+-', PASSWORD_BCRYPT);

        try {
            $st = $pdo->prepare("INSERT INTO public.usuarios
                    (cedula, nombre, apellido, email, correo, password, telefono, tipo_usuario,
                     estatus_cuenta, roles_asignados, cargo_funcion, fecha_nacimiento, sexo, edad,
                     estado_civil, etnia, tipo_discapacidad)
                VALUES
                    (:ced, :nom, :ape, NULL, NULL, :pass, :tel, 'estudiante',
                     'Activo', ARRAY['estudiante']::text[], 'Estudiante', :fnac, :sexo, :edad,
                     NULL, 'Ninguna', 'Ninguna')
                ON CONFLICT (cedula) DO NOTHING
                RETURNING id_usuario");
            $st->execute([
                ':ced'  => $cedula,
                ':nom'  => mb_substr($nombre, 0, 100),
                ':ape'  => mb_substr($apellido, 0, 100),
                ':pass' => $clave_hash,
                ':tel'  => $telefono !== '' ? mb_substr($telefono, 0, 20) : null,
                ':fnac' => $fnac,
                ':sexo' => $sexo,
                ':edad' => $edad,
            ]);
            $id_nuevo = $st->fetchColumn();
            if (!$id_nuevo) {
                // Otro proceso pudo crearla: reconsultar.
                $usuario = pernocta_usuario_por_cedula($pdo, $cedula);
                if (!$usuario) {
                    $resultado['error'] = 'No se pudo crear la cuenta de usuario.';
                    return $resultado;
                }
                $id_nuevo = $usuario['id_usuario'];
            }
            $resultado['ok'] = true;
            $resultado['id_usuario'] = (int) $id_nuevo;
            $resultado['nombre'] = $completo;
            $resultado['creado'] = true;
            $resultado['fuente'] = 'PADRON_NACIONAL';
            return $resultado;
        } catch (Throwable $e) {
            $resultado['error'] = 'No se pudo crear la cuenta: ' . $e->getMessage();
            return $resultado;
        }
    }
}

if (!function_exists('pernocta_eventos_abiertos')) {
    /**
     * Eventos (cohortes) con pernocta habilitados para inscripción.
     *
     * @return array
     */
    function pernocta_eventos_abiertos(PDO $pdo, $id_cohorte = null): array
    {
        $sql = "SELECT c.id_cohorte, c.codigo_cohorte, c.fecha_inicio, c.fecha_fin_estimada,
                       c.cupos_maximos, c.estatus, c.id_profesor_guia, c.salon_aula,
                       m.id_malla, m.nombre_programa, m.tipo_oferta, m.requiere_pernocta,
                       m.descripcion,
                       COUNT(i.id_inscripcion) AS inscritos,
                       CONCAT(pf.nombre, ' ', COALESCE(pf.apellido, '')) AS exponente
                  FROM public.cohortes c
                  JOIN public.mallas_curriculares m ON m.id_malla = c.id_malla
                  LEFT JOIN public.usuarios pf ON pf.id_usuario = c.id_profesor_guia
                  LEFT JOIN public.inscripciones i ON i.id_curso = c.id_cohorte
                 WHERE m.requiere_pernocta = true
                   AND c.inscripcion_pernocta_abierta = true
                   AND c.estatus IN ('Planificada', 'En Curso')";
        $params = [];
        if ($id_cohorte !== null && (int) $id_cohorte > 0) {
            $sql .= " AND c.id_cohorte = :id";
            $params[':id'] = (int) $id_cohorte;
        }
        $sql .= " GROUP BY c.id_cohorte, m.id_malla, pf.id_usuario
                  ORDER BY c.fecha_inicio DESC, c.id_cohorte DESC";
        try {
            $st = $pdo->prepare($sql);
            $st->execute($params);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('PERNOCTA eventos abiertos: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('foro_eventos_inscripcion_disponibles')) {
    /**
     * Eventos extraordinarios (Foro/Conversatorio/Seminario) abiertos a inscripción.
     * La inscripción la hace Recepción; la pernocta es un dato opcional del evento.
     */
    function foro_eventos_inscripcion_disponibles(PDO $pdo, $id_foro = null): array
    {
        $sql = "SELECT f.id_foro, f.codigo_foro, f.nombre, f.tipo_foro, f.descripcion,
                        f.fecha_inicio, f.fecha_fin, f.lugar, f.horas_academicas,
                        f.estatus, f.pernocta_habilitada,
                        (SELECT COUNT(*) FROM public.foro_participantes p
                          WHERE p.id_foro = f.id_foro) AS inscritos,
                        (SELECT COUNT(*) FROM public.dormitorio_pernoctas dp
                          WHERE dp.id_foro = f.id_foro AND dp.estado = 'En Curso') AS pernoctados
                   FROM public.foros_eventos f
                  WHERE f.estatus IN ('Planificado', 'Activo')";
        $params = [];
        if ($id_foro !== null && (int) $id_foro > 0) {
            $sql .= " AND f.id_foro = :id";
            $params[':id'] = (int) $id_foro;
        }
        $sql .= " ORDER BY f.fecha_inicio DESC NULLS LAST, f.id_foro DESC";
        try {
            $st = $pdo->prepare($sql);
            $st->execute($params);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('FOROS eventos inscripción: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('pernocta_foros_disponibles')) {
    /**
     * Eventos extraordinarios (Foro/Conversatorio/Seminario) con pernocta habilitada.
     * Son la única fuente válida para vincular una pernocta nueva.
     */
    function pernocta_foros_disponibles(PDO $pdo, $id_foro = null): array
    {
        $sql = "SELECT f.id_foro, f.codigo_foro, f.nombre, f.tipo_foro, f.descripcion,
                       f.fecha_inicio, f.fecha_fin, f.lugar, f.horas_academicas,
                       f.estatus, f.pernocta_habilitada,
                       (SELECT COUNT(*) FROM public.foro_participantes p
                         WHERE p.id_foro = f.id_foro) AS inscritos,
                       (SELECT COUNT(*) FROM public.dormitorio_pernoctas dp
                         WHERE dp.id_foro = f.id_foro AND dp.estado = 'En Curso') AS pernoctados
                  FROM public.foros_eventos f
                 WHERE f.pernocta_habilitada = true
                   AND f.estatus IN ('Planificado', 'Activo')";
        $params = [];
        if ($id_foro !== null && (int) $id_foro > 0) {
            $sql .= " AND f.id_foro = :id";
            $params[':id'] = (int) $id_foro;
        }
        $sql .= " ORDER BY f.fecha_inicio DESC NULLS LAST, f.id_foro DESC";
        try {
            $st = $pdo->prepare($sql);
            $st->execute($params);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('PERNOCTA foros disponibles: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('pernocta_inscribir_foro')) {
    /**
     * Registra al estudiante como participante del Foro/Conversatorio/Seminario usando
     * solo su cédula (resuelta previamente a id_usuario).
     *
     * @return array{ok:bool, ya:bool, error:string}
     */
    function pernocta_inscribir_foro(PDO $pdo, int $id_foro, int $id_usuario): array
    {
        $res = ['ok' => false, 'ya' => false, 'error' => ''];
        try {
            $st = $pdo->prepare("INSERT INTO public.foro_participantes
                    (id_foro, id_estudiante, estatus, id_usuario_registro)
                    VALUES (:f, :u, 'Inscrito', :reg)
                    ON CONFLICT (id_foro, id_estudiante) DO NOTHING");
            $st->execute([
                ':f' => $id_foro, ':u' => $id_usuario,
                ':reg' => $_SESSION['id_usuario'] ?? null,
            ]);
            if ($st->rowCount() === 0) {
                $res['ya'] = true;
            } else {
                $res['ok'] = true;
            }
            return $res;
        } catch (Throwable $e) {
            $res['error'] = $e->getMessage();
            return $res;
        }
    }
}

if (!function_exists('pernocta_inscribir')) {
    /**
     * Inscribe a un usuario en un evento con pernocta, respetando cupo.
     *
     * @return array{ok:bool, ya:bool, error:string}
     */
    function pernocta_inscribir(PDO $pdo, int $id_cohorte, int $id_usuario): array
    {
        $res = ['ok' => false, 'ya' => false, 'error' => ''];
        $transaccion_propia = !$pdo->inTransaction();
        try {
            if ($transaccion_propia) { $pdo->beginTransaction(); }

            $st = $pdo->prepare("SELECT c.cupos_maximos, c.codigo_cohorte,
                                        (SELECT COUNT(*) FROM public.inscripciones i
                                          WHERE i.id_curso = c.id_cohorte) AS inscritos
                                   FROM public.cohortes c
                                  WHERE c.id_cohorte = :id
                                    AND c.inscripcion_pernocta_abierta = true
                                    AND c.estatus IN ('Planificada', 'En Curso')
                                 FOR UPDATE OF c");
            $st->execute([':id' => $id_cohorte]);
            $cohorte = $st->fetch(PDO::FETCH_ASSOC);
            if (!$cohorte) {
                throw new Exception('El evento ya no admite inscripciones.');
            }
            if ((int) $cohorte['inscritos'] >= (int) $cohorte['cupos_maximos']) {
                throw new Exception('El evento ' . $cohorte['codigo_cohorte'] . ' ya alcanzó su cupo máximo ('
                    . (int) $cohorte['cupos_maximos'] . ').');
            }

            $st2 = $pdo->prepare("INSERT INTO public.inscripciones (id_curso, id_estudiante, fecha_inscripcion, nota_cualitativa)
                                  VALUES (:c, :u, CURRENT_TIMESTAMP, 'En Progreso')
                                  ON CONFLICT (id_curso, id_estudiante) DO NOTHING");
            $st2->execute([':c' => $id_cohorte, ':u' => $id_usuario]);
            if ($st2->rowCount() === 0) {
                $res['ya'] = true;
            } else {
                $res['ok'] = true;
            }

            if ($transaccion_propia) { $pdo->commit(); }
            return $res;
        } catch (Throwable $e) {
            if ($transaccion_propia && $pdo->inTransaction()) { $pdo->rollBack(); }
            $res['error'] = $e->getMessage();
            return $res;
        }
    }
}

if (!function_exists('pernocta_ocupacion')) {
    /** Mapa id_dormitorio => ocupadas (pernoctas en curso). */
    function pernocta_ocupacion(PDO $pdo): array
    {
        try {
            $filas = $pdo->query("SELECT id_dormitorio, COUNT(*) AS n
                                  FROM public.dormitorio_pernoctas
                                  WHERE estado = 'En Curso'
                                  GROUP BY id_dormitorio")->fetchAll(PDO::FETCH_KEY_PAIR);
            return $filas ?: [];
        } catch (Throwable $e) {
            error_log('PERNOCTA ocupación: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('pernocta_responsable_actual')) {
    /** Responsable activo de un dormitorio (desde el historial). */
    function pernocta_responsable_actual(PDO $pdo, int $id_dormitorio): ?array
    {
        try {
            $st = $pdo->prepare("SELECT r.id_responsable_registro, r.id_usuario, r.fecha_desde, r.motivo_cambio,
                                        CONCAT(u.nombre, ' ', COALESCE(u.apellido, '')) AS nombre,
                                        u.telefono, u.cedula
                                   FROM public.dormitorio_responsables r
                                   JOIN public.usuarios u ON u.id_usuario = r.id_usuario
                                  WHERE r.id_dormitorio = :d AND r.fecha_hasta IS NULL
                                  LIMIT 1");
            $st->execute([':d' => $id_dormitorio]);
            $fila = $st->fetch(PDO::FETCH_ASSOC);
            return $fila ?: null;
        } catch (Throwable $e) {
            error_log('PERNOCTA responsable: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('pernocta_dormitorios')) {
    /** Listado de dormitorios (fuente de verdad) con ocupación y responsable. */
    function pernocta_dormitorios(PDO $pdo): array
    {
        try {
            return $pdo->query("SELECT d.*,
                                       (SELECT COUNT(*) FROM public.dormitorio_pernoctas p
                                         WHERE p.id_dormitorio = d.id_dormitorio AND p.estado = 'En Curso') AS ocupadas,
                                       CONCAT(u.nombre, ' ', COALESCE(u.apellido, '')) AS responsable_nombre,
                                       u.cedula AS responsable_cedula,
                                       COALESCE(d.telefono_responsable, u.telefono) AS responsable_telefono
                                  FROM public.dormitorios d
                                  LEFT JOIN public.usuarios u ON u.id_usuario = d.id_responsable
                                 ORDER BY COALESCE(NULLIF(TRIM(d.planta), ''), 'ZZ'), d.nombre")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('PERNOCTA dormitorios: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('pernocta_temas_foro')) {
    /** Temas de la malla de un Foro/Conversatorio/Seminario. */
    function pernocta_temas_foro(PDO $pdo, int $id_foro): array
    {
        try {
            $st = $pdo->prepare("SELECT nombre_tema, ponente, horas_academicas, contenido
                                   FROM public.foro_temas
                                  WHERE id_foro = :f
                                  ORDER BY orden_tema, id_tema");
            $st->execute([':f' => $id_foro]);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('pernocta_bitacora')) {
    /** Auditoría no crítica del flujo de pernocta. */
    function pernocta_bitacora(PDO $pdo, string $accion): void
    {
        try {
            $st = $pdo->prepare("INSERT INTO public.bitacora (id_usuario, modulo, accion, ip_maquina)
                                 VALUES (:u, 'PERNOCTA', :a, :ip)");
            $st->execute([
                ':u'  => $_SESSION['id_usuario'] ?? null,
                ':a'  => mb_substr($accion, 0, 900),
                ':ip' => trim((string) ($_SERVER['REMOTE_ADDR'] ?? '')) ?: 'local-cli',
            ]);
        } catch (Throwable $e) {
            error_log('PERNOCTA bitácora: ' . $e->getMessage());
        }
    }
}

if (!function_exists('pernocta_tematicas')) {
    /** Temáticas (contenido) de la malla asociada a un evento. */
    function pernocta_tematicas(PDO $pdo, int $id_cohorte): array
    {
        try {
            $st = $pdo->prepare("SELECT m.nombre_materia, m.contenido_detallado, m.horas_academicas
                                   FROM public.malla_materias m
                                   JOIN public.cohortes c ON c.id_malla = m.id_malla
                                  WHERE c.id_cohorte = :id
                                  ORDER BY m.modulo_num, m.nombre_materia");
            $st->execute([':id' => $id_cohorte]);
            return $st->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
    }
}
