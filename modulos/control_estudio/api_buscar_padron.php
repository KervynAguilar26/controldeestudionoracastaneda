<?php
// /sistema_nora/modulos/control_estudio/api_buscar_padron.php
header('Content-Type: application/json');
require_once '../../config/db.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Control de acceso: solo Administración y Control de Estudio
$roles_api = $_SESSION['roles_asignados'] ?? [$_SESSION['tipo_usuario'] ?? ''];
if (empty($_SESSION['id_usuario']) || !array_intersect(['superadmin', 'admin', 'control_estudio'], $roles_api)) {
    http_response_code(403);
    echo json_encode(['encontrado' => false, 'mensaje' => 'Acceso no autorizado al Padrón Nacional.']);
    exit;
}

$cedula = preg_replace('/[^0-9]/', '', trim($_GET['cedula'] ?? ''));

if (empty($cedula)) {
    echo json_encode(['encontrado' => false, 'mensaje' => 'Cédula no proporcionada.']);
    exit;
}

try {
    if (empty($pdo_padron)) {
        echo json_encode(['encontrado' => false, 'mensaje' => 'Conexión al Padrón Nacional no disponible.']);
        exit;
    }

    // Consulta optimizada a la base de datos del padrón
    $sql = "SELECT first_name, second_name, first_surname, second_surname 
            FROM public.datos 
            WHERE document = :doc LIMIT 1";
    
    $stmt = $pdo_padron->prepare($sql);
    $stmt->execute([':doc' => $cedula]);
    $persona = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($persona) {
        echo json_encode([
            'encontrado'     => true,
            'first_name'     => $persona['first_name'],
            'second_name'    => $persona['second_name'],
            'first_surname'  => $persona['first_surname'],
            'second_surname' => $persona['second_surname']
        ]);
    } else {
        echo json_encode(['encontrado' => false, 'mensaje' => 'Cédula no encontrada en el Padrón Nacional.']);
    }
} catch (Exception $e) {
    echo json_encode(['encontrado' => false, 'mensaje' => 'Error al consultar el Padrón Nacional.']);
}
?>