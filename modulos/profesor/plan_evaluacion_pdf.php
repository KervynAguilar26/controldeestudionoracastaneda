<?php
// /sistema_nora/modulos/profesor/plan_evaluacion_pdf.php
// Descarga del Plan de Evaluación por Contenidos en PDF.
// ?id_cohorte=N&id_materia=N[&id_plan=N]

session_start();
require_once '../../config/db.php';
require_once '../../includes/permisos_docente.php';
require_once '../../includes/contenidos_evaluacion.php';
require_once '../../includes/plan_evaluacion_pdf.php';

function plan_evaluacion_pdf_error(string $titulo, string $mensaje, int $codigo = 400): void
{
    http_response_code($codigo);
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><title>'
        . htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8') . '</title>'
        . '</head><body style="background-color:#d6c4af;font-family:Arial,sans-serif;">'
        . '<div style="max-width:640px;margin:60px auto;background:#fff;border-radius:15px;box-shadow:0 4px 16px rgba(0,0,0,.2);">'
        . '<div style="background:#521f29;color:#fff;font-weight:bold;padding:14px 18px;border-radius:15px 15px 0 0;">'
        . htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8') . '</div>'
        . '<div style="padding:24px;text-align:center;">'
        . '<div style="background:#ffe8c9;color:#521f29;font-weight:bold;padding:12px;border-radius:8px;">'
        . htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8') . '</div>'
        . '<br><a href="javascript:history.back()" style="background:#ff8a00;color:#521f29;font-weight:bold;padding:10px 18px;text-decoration:none;border-radius:8px;display:inline-block;">Volver</a>'
        . '</div></div></body></html>';
    exit;
}

if (empty($_SESSION['id_usuario'])) {
    plan_evaluacion_pdf_error('Sesión requerida', 'Debe iniciar sesión para descargar el plan de evaluación.', 401);
}

$GLOBALS['roles_array'] = $_SESSION['roles_asignados'] ?? [$_SESSION['tipo_usuario'] ?? ''];
$roles_usuario = $GLOBALS['roles_array'];
$roles_permitidos = ['profesor', 'admin', 'superadmin', 'control_estudio'];
$tiene_acceso = false;
foreach ($roles_permitidos as $rol) {
    if (in_array($rol, (array) $roles_usuario, true)) {
        $tiene_acceso = true;
        break;
    }
}
if (!$tiene_acceso) {
    plan_evaluacion_pdf_error('Acceso restringido', 'Solo docentes y control de estudio pueden descargar el plan.', 403);
}

$id_cohorte = intval($_GET['id_cohorte'] ?? 0);
$id_materia = intval($_GET['id_materia'] ?? 0);
$id_plan = intval($_GET['id_plan'] ?? 0);
$id_profesor = (int) $_SESSION['id_usuario'];

if ($id_cohorte <= 0 || $id_materia <= 0) {
    plan_evaluacion_pdf_error('Solicitud inválida', 'No se indicaron una sección y asignatura válidas.', 400);
}
if (!docente_puede_gestionar($pdo, $id_profesor, $id_materia, $id_cohorte)) {
    plan_evaluacion_pdf_error('Acceso denegado', 'La sección/asignatura no válida o no está asignada a su persona.', 403);
}
if ($id_plan > 0 && !docente_plan_pertenece($pdo, $id_plan, $id_materia, $id_cohorte)) {
    plan_evaluacion_pdf_error('Acceso denegado', 'La evaluación indicada no pertenece a esta sección/asignatura.', 403);
}

try {
    $stmt = $pdo->prepare("
        SELECT co.id_cohorte, co.codigo_cohorte, co.salon_aula, co.num_periodo,
               mm.id_materia, mm.nombre_materia, mm.modulo_num, mm.horas_academicas,
               mc.nombre_programa, mc.tipo_oferta, mc.tipo_regimen
          FROM public.cohortes co
          JOIN public.malla_materias mm
            ON mm.id_materia = :mat AND mm.id_malla = co.id_malla
          JOIN public.mallas_curriculares mc ON mc.id_malla = co.id_malla
         WHERE co.id_cohorte = :coh
         LIMIT 1
    ");
    $stmt->execute([':coh' => $id_cohorte, ':mat' => $id_materia]);
    $ctx = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$ctx) {
        plan_evaluacion_pdf_error('Datos no disponibles', 'No se encontró la información académica de esta sección/asignatura.', 404);
    }

    $sql = "SELECT * FROM public.plan_evaluacion
             WHERE id_cohorte = :coh AND id_materia = :mat";
    $params = [':coh' => $id_cohorte, ':mat' => $id_materia];
    if ($id_plan > 0) {
        $sql .= " AND id_plan = :plan";
        $params[':plan'] = $id_plan;
    }
    $sql .= " ORDER BY fecha_evaluacion ASC NULLS LAST, id_plan ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $planes = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    foreach ($planes as &$pl) {
        $pl['contenidos'] = evaluacion_contenidos_plan($pdo, (int) $pl['id_plan']);
    }
    unset($pl);

    $ctx['nombre_profesor'] = evaluacion_nombre_profesor(
        $pdo,
        $id_plan > 0 ? $id_plan : 0,
        $id_materia,
        $id_cohorte
    );

    $nombre = 'plan_evaluacion_' . preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $ctx['codigo_cohorte']);
    if ($id_plan > 0) {
        $nombre .= '_eval' . $id_plan;
    }
    plan_evaluacion_pdf_renderizar(plan_evaluacion_pdf_html($ctx, $planes), $nombre . '.pdf');
} catch (Throwable $e) {
    plan_evaluacion_pdf_error('No se pudo generar el PDF', $e->getMessage());
}
