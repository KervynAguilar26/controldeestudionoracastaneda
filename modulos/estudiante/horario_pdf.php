<?php
// /sistema_nora/modulos/estudiante/horario_pdf.php
// Descarga en PDF (tamaño carta) del horario del estudiante autenticado.

session_start();
require_once '../../config/db.php';
require_once '../../includes/horario_pdf.php';

function horario_est_pdf_error(string $titulo, string $mensaje, int $codigo = 400)
{
    http_response_code($codigo);
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><title>' . htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8') . '</title>'
        . '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">'
        . '</head><body style="background-color:#d6c4af;">'
        . '<div class="container py-5"><div class="card shadow mx-auto" style="max-width:640px; border-radius:15px;">'
        . '<div class="card-header text-white font-weight-bold" style="background-color:#521f29;">Horario</div>'
        . '<div class="card-body bg-white text-center p-4">'
        . '<div class="alert alert-warning font-weight-bold" style="background-color:#ffe8c9;">' . htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8') . '</div>'
        . '<a href="javascript:history.back()" class="btn font-weight-bold text-white" style="background-color:#ff8a00;">Volver</a>'
        . '</div></div></div></body></html>';
    exit;
}

if (empty($_SESSION['id_usuario'])) {
    horario_est_pdf_error('Sesión requerida', 'Debe iniciar sesión para descargar su horario.', 401);
}

$roles_usuario = $_SESSION['roles_asignados'] ?? [$_SESSION['tipo_usuario'] ?? ''];
$es_admin = in_array('admin', $roles_usuario)
         || in_array('superadmin', $roles_usuario)
         || in_array('control_estudio', $roles_usuario);

$id_inscripcion = intval($_GET['id'] ?? 0);
if ($id_inscripcion <= 0) {
    horario_est_pdf_error('Solicitud inválida', 'No se indicó una inscripción válida.', 400);
}

try {
    $stmt = $pdo->prepare("SELECT id_estudiante FROM public.inscripciones WHERE id_inscripcion = :id");
    $stmt->execute([':id' => $id_inscripcion]);
    $id_estudiante = intval($stmt->fetchColumn());

    if (!$id_estudiante) {
        horario_est_pdf_error('Horario no disponible', 'La inscripción indicada no existe.', 404);
    }
    if (!$es_admin && $id_estudiante !== (int) $_SESSION['id_usuario']) {
        horario_est_pdf_error('Acceso restringido', 'Solo puede descargar su propio horario.', 403);
    }

    horario_pdf_estudiante($pdo, $id_inscripcion, true);
} catch (Throwable $e) {
    horario_est_pdf_error('No se pudo generar el horario', $e->getMessage());
}
