<?php
// /sistema_nora/modulos/recepcion/api_registrar_salida.php
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
$id_visita = 0;
if (is_array($datos) && isset($datos['id_visita'])) {
    $id_visita = intval($datos['id_visita']);
} elseif (isset($_POST['id_visita'])) {
    $id_visita = intval($_POST['id_visita']);
}

if ($id_visita <= 0) {
    echo json_encode(['status' => 'error', 'message' => 'Identificador de visita inválido.']);
    exit;
}

try {
    $stmt = $pdo->prepare("UPDATE public.recepcion_visitas
                           SET fecha_salida = CURRENT_DATE,
                               hora_salida  = CURRENT_TIME,
                               estatus      = 'Finalizada'
                           WHERE id_visita = :id AND estatus = 'En Curso'");
    $stmt->execute([':id' => $id_visita]);

    if ($stmt->rowCount() < 1) {
        $st_est = $pdo->prepare("SELECT estatus FROM public.recepcion_visitas WHERE id_visita = :id LIMIT 1");
        $st_est->execute([':id' => $id_visita]);
        $estatus = $st_est->fetchColumn();

        echo json_encode([
            'status'  => 'error',
            'message' => $estatus === 'Finalizada'
                ? 'Esta visita ya tenía registrada su salida.'
                : 'No se encontró una visita en curso con ese identificador.',
            'id_visita' => $id_visita,
        ]);
        exit;
    }

    recepcion_bitacora($pdo, 'VISITA SALIDA: id=' . $id_visita);

    echo json_encode([
        'status'    => 'success',
        'message'   => 'Salida registrada correctamente.',
        'id_visita' => $id_visita,
    ]);
} catch (Throwable $e) {
    error_log('RECEPCION salida: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'No se pudo registrar la salida. Intente nuevamente.']);
}
