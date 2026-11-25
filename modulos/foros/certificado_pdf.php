<?php
// /sistema_nora/modulos/foros/certificado_pdf.php
// Descarga del certificado de Foro/Conversatorio/Seminario (frente y reverso).
require_once '../../config/db.php';
require_once '../../includes/foro_certificado_pdf.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$id = intval($_GET['id'] ?? 0);
$id_usuario = (int) ($_SESSION['id_usuario'] ?? 0);
if ($id <= 0 || $id_usuario <= 0) {
    http_response_code(400);
    exit('Solicitud inválida.');
}

$roles = $_SESSION['roles_asignados'] ?? [$_SESSION['tipo_usuario'] ?? ''];
$es_admin = in_array('superadmin', $roles) || in_array('admin', $roles)
         || in_array('control_estudio', $roles) || in_array('profesor', $roles);

$datos = foro_certificado_datos($pdo, $id);
if (!$datos) {
    http_response_code(404);
    exit('Certificado no encontrado.');
}

// Un estudiante solo puede descargar sus propios certificados.
if (!$es_admin && (int) ($datos['id_estudiante'] ?? 0) !== $id_usuario) {
    http_response_code(403);
    exit('Acceso restringido.');
}
if (strtolower((string) ($datos['estado'] ?? 'Emitido')) !== 'emitido') {
    http_response_code(403);
    exit('Este certificado fue anulado y no tiene validez.');
}

$config = certificado_config($pdo);
$nombre = 'Certificado_' . preg_replace('/[^A-Za-z0-9-]/', '_', (string) $datos['folio']) . '.pdf';
foro_certificado_pdf_output($datos, $nombre, true, $config);
exit;
