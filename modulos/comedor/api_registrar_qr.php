<?php
// /sistema_nora/modulos/comedor/api_registrar_qr.php
header('Content-Type: application/json');
require_once '../../config/db.php';

// Control de acceso: exclusivo de Comedor / Monitores / Administración
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$roles_qr = $_SESSION['roles_asignados'] ?? [$_SESSION['tipo_usuario'] ?? ''];
if (empty($_SESSION['id_usuario'])
    || !(in_array('superadmin', $roles_qr) || in_array('admin', $roles_qr)
         || in_array('comedor', $roles_qr) || in_array('monitor', $roles_qr))) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Acceso restringido a Comedor.']);
    exit;
}

$cedula = trim($_POST['cedula'] ?? '');
$ingesta = trim($_POST['ingesta'] ?? 'Almuerzo');

if (empty($cedula) || !is_numeric($cedula)) {
    echo json_encode(['status' => 'error', 'message' => 'Código QR / Cédula inválida.']);
    exit;
}

try {
    // 1. Buscar nombre del comensal en el Padrón Nacional (opcional)
    $nombre_completo = "Comensal Cédula V-" . $cedula;
    if (!empty($pdo_padron)) {
        $sql_padron = "SELECT first_name, second_name, first_surname, second_surname 
                       FROM datos WHERE document = :doc LIMIT 1";
        $stmt_p = $pdo_padron->prepare($sql_padron);
        $stmt_p->execute([':doc' => $cedula]);
        $persona = $stmt_p->fetch(PDO::FETCH_ASSOC);

        if ($persona) {
            $nombre_completo = trim("{$persona['first_name']} {$persona['second_name']} {$persona['first_surname']} {$persona['second_surname']}");
        }
    }

    // 2. Registrar el marcaje de asistencia en la base de datos local
    // (estructura real: id_usuario, fecha_registro, hora_registro, turno)
    $stmt_uid = $pdo->prepare("SELECT id_usuario FROM public.usuarios WHERE cedula = :ced LIMIT 1");
    $stmt_uid->execute([':ced' => $cedula]);
    $id_usuario = $stmt_uid->fetchColumn();

    if (!$id_usuario) {
        echo json_encode(['status' => 'error', 'message' => 'La cédula no pertenece a ningún usuario registrado.']);
        exit;
    }

    $sql_insert = "INSERT INTO public.comedor_asistencia (id_usuario, fecha_registro, hora_registro, turno) 
                   VALUES (:id_u, CURRENT_DATE, CURRENT_TIME, :turno)
                   ON CONFLICT (id_usuario, fecha_registro, turno) DO NOTHING";
    $stmt_i = $pdo->prepare($sql_insert);
    $stmt_i->execute([
        ':id_u'   => $id_usuario,
        ':turno'  => $ingesta
    ]);

    echo json_encode([
        'status'          => 'success',
        'nombre_comensal' => $nombre_completo
    ]);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'message' => 'Error BD: ' . $e->getMessage()]);
}