<?php
// /sistema_nora/modulos/foros/_funciones.php
// Funciones compartidas del módulo Foros, Conversatorios y Seminarios.
// Se incluye después de config/db.php e includes/header.php.

if (!function_exists('foro_roles_admin')) {
    function foro_roles_admin(): array
    {
        return ['superadmin', 'admin', 'control_estudio', 'profesor'];
    }
}

if (!function_exists('foro_puede_administrar')) {
    function foro_puede_administrar(): bool
    {
        global $roles_array;
        $roles = $roles_array ?? [$_SESSION['tipo_usuario'] ?? ''];
        return function_exists('tiene_permiso') && tiene_permiso(foro_roles_admin(), $roles);
    }
}

if (!function_exists('foro_es_estudiante')) {
    function foro_es_estudiante(): bool
    {
        global $roles_array;
        $roles = $roles_array ?? [$_SESSION['tipo_usuario'] ?? ''];
        return function_exists('tiene_permiso') && tiene_permiso(['estudiante'], $roles);
    }
}

if (!function_exists('foro_evento')) {
    function foro_evento(PDO $pdo, int $id_foro): ?array
    {
        if ($id_foro <= 0) { return null; }
        $sql = "SELECT f.*, m.nombre_programa AS malla_nombre,
                       (SELECT COALESCE(SUM(t.horas_academicas), 0)
                          FROM public.foro_temas t WHERE t.id_foro = f.id_foro) AS horas_temas,
                       (SELECT COUNT(*) FROM public.foro_temas t WHERE t.id_foro = f.id_foro) AS total_temas
                  FROM public.foros_eventos f
                  LEFT JOIN public.mallas_curriculares m ON m.id_malla = f.id_malla
                 WHERE f.id_foro = :id LIMIT 1";
        $st = $pdo->prepare($sql);
        $st->execute([':id' => $id_foro]);
        $fila = $st->fetch();
        return $fila ?: null;
    }
}

if (!function_exists('foro_stats')) {
    function foro_stats(PDO $pdo, int $id_foro): array
    {
        $sql = "SELECT
                    (SELECT COUNT(*) FROM public.foro_participantes WHERE id_foro = :id) AS participantes,
                    (SELECT COUNT(*) FROM public.foro_asistencias a
                       JOIN public.foro_participantes p ON p.id_participante = a.id_participante
                      WHERE p.id_foro = :id AND a.tipo_asistencia = 'Ponencia') AS asistencia_ponencia,
                    (SELECT COUNT(*) FROM public.foro_asistencias a
                       JOIN public.foro_participantes p ON p.id_participante = a.id_participante
                      WHERE p.id_foro = :id AND a.tipo_asistencia = 'Mesa de Trabajo') AS asistencia_mesa,
                    (SELECT COUNT(*) FROM public.foro_mesas WHERE id_foro = :id) AS mesas,
                    (SELECT COUNT(*) FROM public.foro_informes i
                       JOIN public.foro_mesas me ON me.id_mesa = i.id_mesa
                      WHERE me.id_foro = :id AND i.estatus = 'Enviado') AS informes,
                    (SELECT COUNT(*) FROM public.foro_certificados fc
                       JOIN public.foro_participantes p ON p.id_participante = fc.id_participante
                      WHERE p.id_foro = :id) AS certificados";
        $st = $pdo->prepare($sql);
        $st->execute([':id' => $id_foro]);
        $fila = $st->fetch(PDO::FETCH_ASSOC);
        return $fila ?: [
            'participantes' => 0, 'asistencia_ponencia' => 0, 'asistencia_mesa' => 0,
            'mesas' => 0, 'informes' => 0, 'certificados' => 0,
        ];
    }
}

if (!function_exists('foro_rol_certificado')) {
    /**
     * Oyente: solo confirma que escuchó la ponencia.
     * Participante: además asistió a una mesa de trabajo / envió su informe.
     * Devuelve null cuando no hay asistencia a la ponencia.
     */
    function foro_rol_certificado(PDO $pdo, int $id_participante): ?string
    {
        $st = $pdo->prepare("SELECT 1 FROM public.foro_asistencias
                              WHERE id_participante = :p AND tipo_asistencia = 'Ponencia' LIMIT 1");
        $st->execute([':p' => $id_participante]);
        if (!$st->fetchColumn()) { return null; }

        $st = $pdo->prepare("SELECT 1 FROM public.foro_asistencias
                              WHERE id_participante = :p AND tipo_asistencia = 'Mesa de Trabajo' LIMIT 1");
        $st->execute([':p' => $id_participante]);
        if ($st->fetchColumn()) { return 'Participante'; }

        $st = $pdo->prepare("SELECT 1 FROM public.foro_informes
                              WHERE id_participante = :p AND estatus = 'Enviado' LIMIT 1");
        $st->execute([':p' => $id_participante]);
        return $st->fetchColumn() ? 'Participante' : 'Oyente';
    }
}

if (!function_exists('foro_certificado_emitir')) {
    /**
     * Emite (o actualiza) el certificado de un participante en el libro general.
     * Respeta el folio ya asignado: si el rol cambia de Oyente a Participante
     * se conserva el mismo folio físico.
     *
     * @return array ['certificado' => fila de certificados_emitidos, 'rol' => Oyente|Participante, 'nuevo' => bool]
     */
    function foro_certificado_emitir(PDO $pdo, int $id_participante, int $id_emisor = 0): array
    {
        require_once dirname(__DIR__, 2) . '/includes/certificado_pdf.php';

        $rol = foro_rol_certificado($pdo, $id_participante);
        if ($rol === null) {
            throw new Exception('El participante no tiene registrada la asistencia a la ponencia; no se puede emitir certificado.');
        }

        $st = $pdo->prepare("SELECT p.id_estudiante, p.id_foro, f.nombre AS foro_nombre
                               FROM public.foro_participantes p
                               JOIN public.foros_eventos f ON f.id_foro = p.id_foro
                              WHERE p.id_participante = :p LIMIT 1");
        $st->execute([':p' => $id_participante]);
        $part = $st->fetch();
        if (!$part) { throw new Exception('Participante no encontrado.'); }

        // Si ya existe, solo se actualiza el rol (mismo folio del libro físico).
        $st = $pdo->prepare("SELECT fc.*, ce.folio, ce.estado, ce.id_certificado
                               FROM public.foro_certificados fc
                               JOIN public.certificados_emitidos ce ON ce.id_certificado = fc.id_certificado_emitido
                              WHERE fc.id_participante = :p LIMIT 1");
        $st->execute([':p' => $id_participante]);
        $anterior = $st->fetch();
        if ($anterior) {
            if ($anterior['rol_certificado'] !== $rol) {
                $upd = $pdo->prepare('UPDATE public.foro_certificados SET rol_certificado = :r WHERE id_participante = :p');
                $upd->execute([':r' => $rol, ':p' => $id_participante]);
            }
            $st = $pdo->prepare('SELECT * FROM public.certificados_emitidos WHERE id_certificado = :id');
            $st->execute([':id' => $anterior['id_certificado']]);
            return ['certificado' => $st->fetch(), 'rol' => $rol, 'nuevo' => false];
        }

        $propia = !$pdo->inTransaction();
        if ($propia) { $pdo->beginTransaction(); }
        try {
            if ($id_emisor > 0) {
                $chk = $pdo->prepare('SELECT 1 FROM public.usuarios WHERE id_usuario = :u');
                $chk->execute([':u' => $id_emisor]);
                if (!$chk->fetchColumn()) { $id_emisor = 0; }
            }
            $datos = certificado_asignar_folio($pdo);
            $st = $pdo->prepare("INSERT INTO public.certificados_emitidos
                (id_inscripcion, id_estudiante, id_malla, tipo, folio, id_usuario_emisor,
                 libro, linea_libro, codigo_validacion, origen, id_foro)
                VALUES (NULL, :est, NULL, 'Foro', :folio, :emisor,
                        :libro, :linea, :codigo, 'Foro', :foro)
                RETURNING id_certificado");
            $st->execute([
                ':est' => $part['id_estudiante'], ':folio' => $datos['folio'],
                ':emisor' => $id_emisor > 0 ? $id_emisor : null,
                ':libro' => $datos['libro'], ':linea' => $datos['linea_libro'],
                ':codigo' => $datos['codigo_validacion'], ':foro' => $part['id_foro'],
            ]);
            $idCertificado = (int) $st->fetchColumn();

            $st = $pdo->prepare("INSERT INTO public.foro_certificados
                (id_participante, rol_certificado, id_certificado_emitido, id_usuario_emisor)
                VALUES (:p, :r, :c, :u)");
            $st->execute([
                ':p' => $id_participante, ':r' => $rol,
                ':c' => $idCertificado, ':u' => $id_emisor > 0 ? $id_emisor : null,
            ]);

            if ($propia) { $pdo->commit(); }
        } catch (Throwable $e) {
            if ($propia && $pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }

        $st = $pdo->prepare('SELECT * FROM public.certificados_emitidos WHERE id_certificado = :id');
        $st->execute([':id' => $idCertificado]);
        return ['certificado' => $st->fetch(), 'rol' => $rol, 'nuevo' => true];
    }
}

if (!function_exists('foro_bitacora')) {
    function foro_bitacora(PDO $pdo, string $accion, string $detalle): void
    {
        try {
            $st = $pdo->prepare("INSERT INTO public.bitacora
                (id_usuario, modulo, accion, ip_maquina, fecha_hora)
                VALUES (:u, 'FOROS', :accion, :ip, CURRENT_TIMESTAMP)");
            $st->execute([
                ':u' => $_SESSION['id_usuario'] ?? null,
                ':accion' => mb_substr($accion . ' | ' . $detalle, 0, 500),
                ':ip' => substr((string) ($_SERVER['REMOTE_ADDR'] ?? 'local'), 0, 45),
            ]);
        } catch (Throwable $e) {
            // La bitácora nunca debe detener una operación del módulo.
        }
    }
}

if (!function_exists('foro_codigo_nuevo')) {
    function foro_codigo_nuevo(PDO $pdo): string
    {
        $anio = date('Y');
        $prefijo = 'FOR-' . $anio . '-';
        $st = $pdo->prepare("SELECT COUNT(*) FROM public.foros_eventos WHERE codigo_foro LIKE :p");
        $st->execute([':p' => $prefijo . '%']);
        $n = (int) $st->fetchColumn() + 1;
        do {
            $codigo = $prefijo . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
            $chk = $pdo->prepare('SELECT 1 FROM public.foros_eventos WHERE codigo_foro = :c');
            $chk->execute([':c' => $codigo]);
            $existe = (bool) $chk->fetchColumn();
            $n++;
        } while ($existe);
        return $codigo;
    }
}

if (!function_exists('foro_redirect')) {
    function foro_redirect(string $url, string $msg = '', string $tipo = 'success'): void
    {
        $sep = strpos($url, '?') === false ? '?' : '&';
        if ($msg !== '') {
            $url .= $sep . 'msg=' . urlencode($msg) . '&tipo=' . urlencode($tipo);
        }
        if (!headers_sent()) {
            header('Location: ' . $url);
        } else {
            // Los módulos incluyen el header antes de procesar el POST; en ese
            // caso header() ya no es válido, así que se redirige por JS.
            echo '<script>window.location.replace(' . json_encode($url, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ');</script>';
        }
        exit();
    }
}
