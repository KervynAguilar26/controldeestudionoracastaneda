<?php
// /sistema_nora/modulos/recepcion/api_buscar_padron.php
// Búsqueda de identidad por cédula para el formulario de Recepción.
header('Content-Type: application/json; charset=utf-8');
require_once '../../config/db.php';
require_once __DIR__ . '/padron.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }

$roles_api = $_SESSION['roles_asignados'] ?? [$_SESSION['tipo_usuario'] ?? ''];
if (empty($_SESSION['id_usuario']) || !array_intersect(['superadmin', 'admin', 'recepcion'], $roles_api)) {
    http_response_code(403);
    echo json_encode(['encontrado' => false, 'mensaje' => 'Acceso restringido a Recepción.']);
    exit;
}

$cedula = recepcion_cedula_limpia($_GET['cedula'] ?? '');
if ($cedula === '') {
    echo json_encode(['encontrado' => false, 'mensaje' => 'Cédula no proporcionada.']);
    exit;
}

$padron = recepcion_buscar_padron($pdo_padron ?? null, $cedula);
if (!$padron) {
    echo json_encode([
        'encontrado' => false,
        'cedula'     => $cedula,
        'mensaje'    => 'Cédula no localizada en el Padrón Nacional. Puede escribir el nombre manualmente.',
    ]);
    exit;
}

echo json_encode([
    'encontrado'       => true,
    'cedula'           => $cedula,
    'nombre_completo'  => $padron['nombre_completo'],
    'nombre'           => $padron['nombre'],
    'apellido'         => $padron['apellido'],
    'fecha_nacimiento' => $padron['fecha_nacimiento'],
    'genero'           => $padron['genero'],
    'estado_civil'     => $padron['estado_civil'],
    'fuente'           => $padron['fuente'],
    'mensaje'          => 'Datos obtenidos del Padrón Nacional.',
]);
