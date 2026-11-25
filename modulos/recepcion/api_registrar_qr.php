<?php
// /sistema_nora/modulos/recepcion/api_registrar_qr.php
header('Content-Type: application/json');
require_once '../../config/db.php';
require_once __DIR__ . '/bitacora.php';
require_once __DIR__ . '/padron.php';

// Gate de roles (idéntico al de las páginas, sin renderizar HTML).
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$roles_qr = $_SESSION['roles_asignados'] ?? [$_SESSION['tipo_usuario'] ?? ''];
$permitido = false;
foreach (['superadmin', 'admin', 'recepcion'] as $rol_permitido) {
    if (in_array($rol_permitido, $roles_qr)) {
        $permitido = true;
        break;
    }
}
if (empty($_SESSION['id_usuario']) || !$permitido) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Acceso restringido a Recepción.']);
    exit;
}

// El QR del carnet contiene SOLO la cédula (sin V-, sin prefijos).
$datos = json_decode((string) file_get_contents('php://input'), true);
$cedula_entrada = '';
if (is_array($datos) && isset($datos['cedula'])) {
    $cedula_entrada = $datos['cedula'];
} elseif (isset($_POST['cedula'])) {
    $cedula_entrada = $_POST['cedula'];
}
$cedula = preg_replace('/\D+/', '', trim((string) $cedula_entrada));

if ($cedula === '') {
    echo json_encode(['status' => 'error', 'message' => 'Código QR / cédula inválida.']);
    exit;
}

// Inserta una alerta sin interrumpir el flujo del escáner si falla el INSERT.
$registrar_alerta = static function (string $tipo, string $area, string $nombre = '') use ($pdo, $cedula): bool {
    try {
        $st = $pdo->prepare("INSERT INTO public.recepcion_alertas_qr
                                (cedula, nombre_completo, id_usuario, fecha_lectura, hora_lectura,
                                 tipo_alerta, area_destino, atendida)
                             VALUES (:ced, :nom, NULL, CURRENT_DATE, CURRENT_TIME, :tipo, :area, false)");
        $st->execute([
            ':ced'  => $cedula,
            ':nom'  => $nombre !== '' ? (function_exists('mb_substr') ? mb_substr($nombre, 0, 150) : substr($nombre, 0, 150)) : null,
            ':tipo' => $tipo,
            ':area' => $area,
        ]);
        return true;
    } catch (Throwable $e) {
        error_log('RECEPCION alerta QR: ' . $e->getMessage());
        return false;
    }
};

try {
    // 0) No duplicar una visita que ya esté en curso hoy para esta cédula.
    $st_dup = $pdo->prepare("SELECT v.id_visita, a.nombre AS area_destino,
                                    TO_CHAR(v.hora_entrada, 'HH24:MI') AS hora_entrada
                             FROM public.recepcion_visitas v
                             JOIN public.recepcion_visitantes rv ON rv.id_visitante = v.id_visitante
                             LEFT JOIN public.recepcion_areas a ON a.id_area = v.id_area_destino
                             WHERE regexp_replace(rv.cedula, '[^0-9]', '', 'g') = :ced
                               AND v.estatus = 'En Curso'
                               AND v.fecha_entrada = CURRENT_DATE
                             ORDER BY v.id_visita DESC
                             LIMIT 1");
    $st_dup->execute([':ced' => $cedula]);
    $duplicado = $st_dup->fetch(PDO::FETCH_ASSOC);
    if ($duplicado) {
        echo json_encode([
            'status'        => 'duplicado',
            'message'       => 'La cédula V-' . $cedula . ' ya registró una visita en curso hoy.',
            'cedula'        => $cedula,
            'id_visita'     => (int) $duplicado['id_visita'],
            'area_destino'  => $duplicado['area_destino'],
            'hora_entrada'  => $duplicado['hora_entrada'],
        ]);
        exit;
    }

    // 1) Identidad por cédula: Padrón Nacional > usuarios del sistema.
    $identidad = recepcion_resolver_identidad($pdo, $pdo_padron ?? null, $cedula);
    $usuario = $identidad['usuario'];
    $id_usuario = $identidad['id_usuario'];
    $nombre_completo = $identidad['nombre_completo'];
    $telefono = $identidad['telefono'];
    $fuente_datos = $identidad['fuente_datos'];
    $fecha_nacimiento = $identidad['padron']['fecha_nacimiento'] ?? null;
    $genero_padron = $identidad['padron']['genero'] ?? null;

    if (!$usuario) {
        $persistida = $registrar_alerta('USUARIO_NO_EXISTE', 'Recepción', $nombre_completo);
        recepcion_bitacora($pdo, 'QR ALERTA USUARIO NO EXISTE: V-' . $cedula
            . ' | ' . ($nombre_completo !== '' ? $nombre_completo : 'sin identificar')
            . ' | fuente:' . $fuente_datos . ' -> Recepcion');
        $prefijo = $nombre_completo !== '' ? ' (' . $nombre_completo . ')' : '';
        echo json_encode([
            'status'           => 'alerta',
            'tipo_alerta'      => 'USUARIO_NO_EXISTE',
            'area_destino'     => 'Recepción',
            'cedula'           => $cedula,
            'nombre_completo'  => $nombre_completo,
            'fuente_datos'     => $fuente_datos,
            'mensaje'          => 'La cédula V-' . $cedula . $prefijo . ' no corresponde a ningún usuario del sistema. Diríjase a Recepción.',
            'alerta_registrada' => $persistida,
        ]);
        exit;
    }

    // 2) ¿Tiene al menos una inscripción? (id_curso apunta a cohortes, NO a cursos)
    $st_ins = $pdo->prepare("SELECT COUNT(*) FROM public.inscripciones WHERE id_estudiante = :id");
    $st_ins->execute([':id' => $id_usuario]);
    $tiene_inscripcion = (int) $st_ins->fetchColumn() > 0;

    $cohortes = [];
    if ($tiene_inscripcion) {
        $st_coh = $pdo->prepare("SELECT c.id_cohorte, c.codigo_cohorte, c.salon_aula,
                                        c.hora_inicio, c.hora_fin,
                                        m.nombre_programa,
                                        TRIM(CONCAT_WS(' ', u.nombre, u.apellido)) AS profesor_guia
                                 FROM public.inscripciones i
                                 JOIN public.cohortes c ON c.id_cohorte = i.id_curso
                                 LEFT JOIN public.mallas_curriculares m ON m.id_malla = c.id_malla
                                 LEFT JOIN public.usuarios u ON u.id_usuario = c.id_profesor_guia
                                 WHERE i.id_estudiante = :id
                                 ORDER BY i.id_inscripcion DESC NULLS LAST, i.id_curso DESC");
        $st_coh->execute([':id' => $id_usuario]);
        $cohortes = $st_coh->fetchAll(PDO::FETCH_ASSOC);
    }

    if (empty($cohortes)) {
        $persistida = $registrar_alerta('SIN_INSCRIPCION', 'Control de Estudio', $nombre_completo);
        recepcion_bitacora($pdo, 'QR ALERTA SIN INSCRIPCION: V-' . $cedula
            . ' | ' . ($nombre_completo !== '' ? $nombre_completo : 'sin identificar')
            . ' | fuente:' . $fuente_datos . ' -> Control de Estudio');
        $prefijo = $nombre_completo !== '' ? ' (' . $nombre_completo . ')' : '';
        echo json_encode([
            'status'           => 'alerta',
            'tipo_alerta'      => 'SIN_INSCRIPCION',
            'area_destino'     => 'Control de Estudio',
            'cedula'           => $cedula,
            'nombre_completo'  => $nombre_completo,
            'fuente_datos'     => $fuente_datos,
            'mensaje'          => 'El usuario' . $prefijo . ' está registrado pero no tiene inscripción activa. Diríjase a Control de Estudio.',
            'alerta_registrada' => $persistida,
        ]);
        exit;
    }

    // 3) Inscripción válida: registrar acceso automático al aula / cohorte.
    $cohorte = $cohortes[0]; // la más reciente según id_inscripcion

    $st_area = $pdo->prepare("SELECT id_area FROM public.recepcion_areas WHERE nombre = 'Aula / Cohorte' LIMIT 1");
    $st_area->execute();
    $id_area = (int) $st_area->fetchColumn();
    if ($id_area <= 0) {
        echo json_encode(['status' => 'error', 'message' => 'No está configurada el área "Aula / Cohorte".']);
        exit;
    }

    $pdo->beginTransaction();
    try {
        // Upsert del visitante (identidad resuelta por cédula).
        $st_vis = $pdo->prepare("INSERT INTO public.recepcion_visitantes
                                    (es_usuario_sistema, id_usuario, cedula, nombre_completo,
                                     telefono, entidad_origen, fuente_datos,
                                     fecha_nacimiento, genero, creado_en)
                                 VALUES (:es_sis, :u, :ced, :nom, :tel, :ori, :fuente,
                                         :fec_nac, :gen, CURRENT_TIMESTAMP)
                                 ON CONFLICT (cedula) DO UPDATE SET
                                     es_usuario_sistema = EXCLUDED.es_usuario_sistema,
                                     id_usuario         = EXCLUDED.id_usuario,
                                     nombre_completo    = EXCLUDED.nombre_completo,
                                     telefono           = COALESCE(EXCLUDED.telefono, recepcion_visitantes.telefono),
                                     entidad_origen     = COALESCE(EXCLUDED.entidad_origen, recepcion_visitantes.entidad_origen),
                                     fuente_datos       = EXCLUDED.fuente_datos,
                                     fecha_nacimiento   = COALESCE(EXCLUDED.fecha_nacimiento, recepcion_visitantes.fecha_nacimiento),
                                     genero             = COALESCE(EXCLUDED.genero, recepcion_visitantes.genero)
                                 RETURNING id_visitante");
        $st_vis->execute([
            ':es_sis'  => 'true',
            ':u'       => $id_usuario,
            ':ced'     => $cedula,
            ':nom'     => $nombre_completo,
            ':tel'     => $telefono !== '' ? $telefono : null,
            ':ori'     => 'Comunidad Nora Castañeda',
            ':fuente'  => $fuente_datos,
            ':fec_nac' => $fecha_nacimiento,
            ':gen'     => $genero_padron,
        ]);
        $id_visitante = (int) $st_vis->fetchColumn();

        $st_vista = $pdo->prepare("INSERT INTO public.recepcion_visitas
                                      (id_visitante, id_area_destino, id_cohorte, motivo,
                                       fecha_entrada, hora_entrada, estatus, id_usuario_registro,
                                       observaciones, destino_recomendado, fecha_registro)
                                   VALUES (:v, :a, :c, 'Acceso a clase',
                                           CURRENT_DATE, CURRENT_TIME, 'En Curso', :reg,
                                           :obs, :dest, CURRENT_TIMESTAMP)
                                   RETURNING id_visita, fecha_entrada, hora_entrada");
        $st_vista->execute([
            ':v'    => $id_visitante,
            ':a'    => $id_area,
            ':c'    => (int) $cohorte['id_cohorte'],
            ':reg'  => $_SESSION['id_usuario'],
            ':obs'  => 'Acceso validado por QR de carnet (inscripción activa).',
            ':dest' => !empty($cohorte['salon_aula']) ? $cohorte['salon_aula'] : null,
        ]);
        $visita = $st_vista->fetch(PDO::FETCH_ASSOC);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }

    // Auditoría fuera de la transacción: un fallo aquí no afecta la visita registrada.
    recepcion_bitacora($pdo, 'QR ACCESO REGISTRADO: V-' . $cedula . ' | ' . $nombre_completo
        . ' | fuente:' . $fuente_datos . ' -> ' . $cohorte['codigo_cohorte']);

    $resumen = [];
    foreach ($cohortes as $c) {
        $resumen[] = [
            'cohorte'        => $c['codigo_cohorte'],
            'programa'       => $c['nombre_programa'],
            'salon'          => $c['salon_aula'],
            'profesor_guia'  => $c['profesor_guia'],
        ];
    }

    echo json_encode([
        'status'        => 'ok',
        'message'       => 'Acceso registrado correctamente.',
        'cedula'        => $cedula,
        'nombre'        => $nombre_completo,
        'nombre_completo' => $nombre_completo,
        'fuente_datos'  => $fuente_datos,
        'cohorte'       => $cohorte['codigo_cohorte'],
        'programa'      => $cohorte['nombre_programa'],
        'salon'         => $cohorte['salon_aula'],
        'profesor_guia' => $cohorte['profesor_guia'],
        'cohortes'      => $resumen,
        'id_visita'     => (int) $visita['id_visita'],
        'fecha_entrada' => $visita['fecha_entrada'],
        'hora_entrada'  => substr((string) $visita['hora_entrada'], 0, 5),
    ]);
} catch (Throwable $e) {
    error_log('RECEPCION QR: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'No se pudo procesar el QR. Intente nuevamente.']);
}
