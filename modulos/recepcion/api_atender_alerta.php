<?php
// /sistema_nora/modulos/recepcion/api_atender_alerta.php
header('Content-Type: application/json');
require_once '../../config/db.php';
require_once __DIR__ . '/bitacora.php';

// Gate de roles (idéntico al de las páginas, sin renderizar HTML).
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$roles_recepcion = $_SESSION['roles_asignados'] ?? [$_SESSION['tipo_usuario'] ?? ''];
$permitido = false;
foreach (['superadmin', 'admin', 'recepcion'] as $rol_permitido) {
    if (in_array($rol_permitido, $roles_recepcion)) {
        $permitido = true;
        break;
    }
}
if (empty($_SESSION['id_usuario']) || !$permitido) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'Acceso restringido a Recepción.']);
    exit;
}

// Acepta JSON (fetch) y también urlencoded (compatibilidad).
$datos = json_decode((string) file_get_contents('php://input'), true);
$id_alerta = 0;
$observaciones = '';
if (is_array($datos)) {
    $id_alerta     = intval($datos['id_alerta'] ?? 0);
    $observaciones = trim((string) ($datos['observaciones'] ?? ''));
} elseif (isset($_POST['id_alerta'])) {
    $id_alerta     = intval($_POST['id_alerta']);
    $observaciones = trim((string) ($_POST['observaciones'] ?? ''));
}

if ($id_alerta <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Identificador de alerta inválido.']);
    exit;
}

try {
    $stmt = $pdo->prepare("UPDATE public.recepcion_alertas_qr
                           SET atendida            = true,
                               id_usuario_atencion = :u,
                               fecha_atencion      = NOW(),
                               observaciones       = :obs
                           WHERE id_alerta = :id AND atendida = false");
    $stmt->execute([
        ':u'   => $_SESSION['id_usuario'],
        ':obs' => $observaciones !== '' ? $observaciones : null,
        ':id'  => $id_alerta,
    ]);

    if ($stmt->rowCount() < 1) {
        echo json_encode([
            'status'    => 'error',
            'message'   => 'La alerta ya fue atendida por otro usuario o no existe.',
            'id_alerta' => $id_alerta,
        ]);
        exit;
    }

    recepcion_bitacora($pdo, 'ALERTA QR ATENDIDA: id=' . $id_alerta);

    echo json_encode([
        'status'    => 'success',
        'message'   => 'Alerta marcada como atendida.',
        'id_alerta' => $id_alerta,
    ]);
} catch (Throwable $e) {
    error_log('RECEPCION atender alerta: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'No se pudo atender la alerta. Intente nuevamente.']);
}
