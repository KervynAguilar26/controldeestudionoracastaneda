<?php
// /sistema_nora/modulos/comedor/api_sincronizar.php
require_once '../../config/db.php';

header('Content-Type: application/json');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Control de acceso: exclusivo de Comedor / Monitores / Administración
$roles_sn = $_SESSION['roles_asignados'] ?? [$_SESSION['tipo_usuario'] ?? ''];
if (empty($_SESSION['id_usuario'])
    || !(in_array('superadmin', $roles_sn) || in_array('admin', $roles_sn)
         || in_array('comedor', $roles_sn) || in_array('monitor', $roles_sn))) {
    http_response_code(403);
    echo json_encode(['exito' => false, 'mensaje' => 'Acceso restringido a Comedor.']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode(['exito' => false, 'mensaje' => 'Datos de sincronización inválidos']);
    exit();
}

$marcajes_procesados = 0;
$errores = 0;

if (!empty($input['asistencias']) && is_array($input['asistencias'])) {
    foreach ($input['asistencias'] as $item) {
        $cedula = trim($item['cedula'] ?? '');
        $turno  = trim($item['turno'] ?? 'Almuerzo');
        $fecha  = $item['fecha'] ?? date('Y-m-d');
        $hora   = $item['hora'] ?? date('H:i:s');

        if (!empty($cedula)) {
            try {
                // 1. Obtener ID de usuario a partir de la Cédula
                $stmt_u = $pdo->prepare("SELECT id_usuario FROM usuarios WHERE cedula = :cedula LIMIT 1");
                $stmt_u->execute([':cedula' => $cedula]);
                $id_usuario = $stmt_u->fetchColumn();

                if ($id_usuario) {
                    // 2. Insertar marcaje ignorando duplicados por (id_usuario, fecha, turno)
                    $sql = "INSERT INTO comedor_asistencia (id_usuario, fecha_registro, hora_registro, turno) 
                            VALUES (:id_u, :fecha, :hora, :turno) 
                            ON CONFLICT (id_usuario, fecha_registro, turno) DO NOTHING";
                    
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([
                        ':id_u'  => $id_usuario,
                        ':fecha' => $fecha,
                        ':hora'  => $hora,
                        ':turno' => $turno
                    ]);
                    $marcajes_procesados++;
                }
            } catch (Exception $e) {
                $errores++;
            }
        }
    }
}

echo json_encode([
    'exito'      => true, 
    'procesados' => $marcajes_procesados, 
    'errores'    => $errores,
    'mensaje'    => "Sincronización completada exitosamente."
]);
?>