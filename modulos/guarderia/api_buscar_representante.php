<?php
// /sistema_nora/modulos/guarderia/api_buscar_representante.php
// Búsqueda de representantes SOLO en sistema_nora_v2 (personas que hacen vida en la escuela).
header('Content-Type: application/json; charset=utf-8');
require_once '../../config/db.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }

$roles_api = $_SESSION['roles_asignados'] ?? [$_SESSION['tipo_usuario'] ?? ''];
if (empty($_SESSION['id_usuario']) || !array_intersect(['guarderia', 'admin', 'superadmin'], $roles_api)) {
    http_response_code(403);
    echo json_encode(['encontrado' => false, 'mensaje' => 'Acceso restringido al Centro de Cuidados Infantiles.']);
    exit;
}

$cedula = preg_replace('/\D+/', '', trim((string) ($_GET['cedula'] ?? '')));
if ($cedula === '') {
    echo json_encode(['encontrado' => false, 'mensaje' => 'Cédula no proporcionada.']);
    exit;
}

try {
    $st = $pdo->prepare("SELECT id_usuario, cedula, nombre, apellido, telefono,
                                tipo_usuario, estatus_cuenta
                         FROM public.usuarios
                         WHERE regexp_replace(cedula, '[^0-9]', '', 'g') = :ced
                         LIMIT 1");
    $st->execute([':ced' => $cedula]);
    $u = $st->fetch(PDO::FETCH_ASSOC);

    if (!$u) {
        echo json_encode([
            'encontrado' => false,
            'cedula'     => $cedula,
            'mensaje'    => 'La cédula no corresponde a ninguna persona registrada en el sistema escolar.',
        ]);
        exit;
    }

    $activo = ($u['estatus_cuenta'] === 'Activo');
    echo json_encode([
        'encontrado'       => true,
        'activo'           => $activo,
        'id_usuario'       => (int) $u['id_usuario'],
        'cedula'           => preg_replace('/\D+/', '', (string) $u['cedula']),
        'nombre_completo'  => trim(((string) $u['nombre']) . ' ' . ((string) $u['apellido'])),
        'nombre'           => $u['nombre'],
        'apellido'         => $u['apellido'],
        'telefono'         => $u['telefono'] ?? '',
        'tipo_usuario'     => $u['tipo_usuario'],
        'estatus_cuenta'   => $u['estatus_cuenta'],
        'mensaje'          => $activo
            ? 'Persona localizada en sistema_nora_v2.'
            : 'La persona existe en el sistema pero su cuenta no está activa.',
    ]);
} catch (Throwable $e) {
    error_log('GUARDERIA representante: ' . $e->getMessage());
    echo json_encode(['encontrado' => false, 'mensaje' => 'No se pudo consultar el sistema escolar.']);
}
