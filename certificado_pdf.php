<?php
// /sistema_nora/certificado_pdf.php
// Descarga del Certificado de Culminación de Estudios (PDF tamaño carta).
// Acceso: administradores / Control de Estudio, o el dueño de la inscripción.

session_start();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/certificado_pdf.php';

$roles_usuario = $_SESSION['roles_asignados'] ?? [$_SESSION['tipo_usuario'] ?? ''];
$es_admin = in_array('admin', $roles_usuario)
         || in_array('superadmin', $roles_usuario)
         || in_array('control_estudio', $roles_usuario);

function cert_error_pagina(string $titulo, string $mensaje, int $codigo = 403)
{
    http_response_code($codigo);
    $t = htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8');
    $m = htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8');
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . $t . '</title>'
        . '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">'
        . '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">'
        . '</head><body style="background-color:#d6c4af;">'
        . '<div class="container py-5"><div class="card shadow mx-auto" style="max-width:640px; border-radius:15px;">'
        . '<div class="card-header text-white font-weight-bold" style="background-color:#521f29;">'
        . '<i class="fas fa-certificate mr-2"></i>' . $t . '</div>'
        . '<div class="card-body bg-white text-center p-4">'
        . '<div class="alert alert-warning font-weight-bold border-0" style="background-color:#ffe8c9;">' . $m . '</div>'
        . '<a href="javascript:history.back()" class="btn font-weight-bold text-white mr-2" style="background-color:#ff8a00;">'
        . '<i class="fas fa-arrow-left mr-1"></i> Volver</a>'
        . '<a href="index.php" class="btn font-weight-bold" style="background-color:#521f29; color:#fff;">'
        . '<i class="fas fa-home mr-1"></i> Inicio</a>'
        . '</div></div></div></body></html>';
    exit;
}

if (empty($_SESSION['id_usuario'])) {
    cert_error_pagina('Sesión requerida', 'Debe iniciar sesión para descargar el certificado.', 401);
}

$id_inscripcion = intval($_GET['id'] ?? 0);
if ($id_inscripcion <= 0) {
    cert_error_pagina('Solicitud inválida', 'No se indicó una inscripción válida.', 400);
}

try {
    $inscripcion = academico_estado_inscripcion($pdo, $id_inscripcion);
    if (!$inscripcion) {
        cert_error_pagina('Certificado no disponible', 'La inscripción solicitada no existe.', 404);
    }

    if (!$es_admin && (int) $inscripcion['id_estudiante'] !== (int) $_SESSION['id_usuario']) {
        cert_error_pagina('Acceso restringido', 'Solo puede descargar su propio certificado.', 403);
    }

    $certificado = certificado_obtener($pdo, $id_inscripcion, (int) $_SESSION['id_usuario']);
    $nombre = 'Certificado_' . preg_replace('/[^A-Za-z0-9-]/', '', $certificado['folio']) . '.pdf';

    certificado_pdf_output(
        [['ins' => $inscripcion, 'cert' => $certificado]],
        $nombre,
        true
    );
} catch (Throwable $e) {
    cert_error_pagina('Certificado no disponible', $e->getMessage(), 400);
}
