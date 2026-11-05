<?php
// /sistema_nora/modulos/defensoria/buscar_padron.php
require_once '../../config/db.php';

header('Content-Type: application/json');

// Control de acceso: requiere sesión y rol de Defensoría / Administración
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$roles_bp = $_SESSION['roles_asignados'] ?? [$_SESSION['tipo_usuario'] ?? ''];
if (empty($_SESSION['id_usuario'])
    || !(in_array('superadmin', $roles_bp) || in_array('admin', $roles_bp)
         || in_array('defensoria', $roles_bp))) {
    http_response_code(403);
    echo json_encode(['exito' => false, 'mensaje' => 'Acceso restringido a Defensoría.']);
    exit();
}

$cedula = trim($_GET['cedula'] ?? '');
// Extraer únicamente los dígitos
$cedula_num = preg_replace('/[^0-9]/', '', $cedula);

if (!empty($cedula_num) && isset($pdo_padron) && $pdo_padron !== null) {
    try {
        // Consulta directa por igualdad indexada
        $sql = "SELECT first_name, second_name, first_surname, second_surname 
                FROM datos 
                WHERE document = :num 
                LIMIT 1";

        $stmt = $pdo_padron->prepare($sql);
        $stmt->execute([':num' => $cedula_num]);
        
        $persona = $stmt->fetch();

        if ($persona) {
            $nombre_completo = trim(implode(' ', array_filter([
                $persona['first_name'] ?? '',
                $persona['second_name'] ?? '',
                $persona['first_surname'] ?? '',
                $persona['second_surname'] ?? ''
            ])));

            if (!empty($nombre_completo)) {
                echo json_encode([
                    'exito' => true, 
                    'nombre_completo' => mb_strtoupper($nombre_completo, 'UTF-8')
                ]);
                exit();
            }
        }
    } catch (Exception $e) {
        echo json_encode(['exito' => false, 'error' => $e->getMessage()]);
        exit();
    }
}

echo json_encode(['exito' => false, 'mensaje' => 'No encontrado']);
?>