<?php
// /sistema_nora/modulos/foros/informe_pdf.php
// Descarga en PDF de un informe de mesa de trabajo.
require_once '../../config/db.php';
require_once '../../includes/dompdf_autoload.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$id_informe = intval($_GET['id'] ?? 0);
$id_usuario = (int) ($_SESSION['id_usuario'] ?? 0);
if ($id_informe <= 0 || $id_usuario <= 0) {
    http_response_code(400);
    exit('Solicitud inválida.');
}

$roles = $_SESSION['roles_asignados'] ?? [$_SESSION['tipo_usuario'] ?? ''];
$es_admin = in_array('superadmin', $roles) || in_array('admin', $roles)
         || in_array('control_estudio', $roles) || in_array('profesor', $roles);

$st = $pdo->prepare("SELECT i.*, m.numero_mesa, m.nombre_mesa, m.informe_transcriptor,
                            f.nombre AS foro_nombre, f.codigo_foro, f.tipo_foro, f.lugar,
                            f.fecha_inicio, f.fecha_fin,
                            ut.nombre AS trans_nombre, ut.apellido AS trans_apellido, ut.cedula AS trans_cedula,
                            p.id_estudiante AS id_estudiante,
                            ue.nombre AS est_nombre, ue.apellido AS est_apellido, ue.cedula AS est_cedula
                       FROM public.foro_informes i
                       JOIN public.foro_mesas m ON m.id_mesa = i.id_mesa
                       JOIN public.foros_eventos f ON f.id_foro = m.id_foro
                       LEFT JOIN public.usuarios ut ON ut.id_usuario = m.id_transcriptor
                       JOIN public.foro_participantes p ON p.id_participante = i.id_participante
                       JOIN public.usuarios ue ON ue.id_usuario = p.id_estudiante
                      WHERE i.id_informe = :id LIMIT 1");
$st->execute([':id' => $id_informe]);
$inf = $st->fetch();

if (!$inf) {
    http_response_code(404);
    exit('Informe no encontrado.');
}
if (!$es_admin && (int) $inf['id_estudiante'] !== $id_usuario) {
    http_response_code(403);
    exit('Acceso restringido.');
}

$st = $pdo->prepare("SELECT u.nombre, u.apellido, u.cedula
                       FROM public.foro_mesa_participantes mp
                       JOIN public.foro_participantes p ON p.id_participante = mp.id_participante
                       JOIN public.usuarios u ON u.id_usuario = p.id_estudiante
                      WHERE mp.id_mesa = :m ORDER BY u.apellido, u.nombre");
$st->execute([':m' => $inf['id_mesa']]);
$integrantes = $st->fetchAll(PDO::FETCH_ASSOC);

$h = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
$fechas = !empty($inf['fecha_inicio']) ? date('d/m/Y', strtotime($inf['fecha_inicio'])) : '—';
if (!empty($inf['fecha_fin']) && $inf['fecha_fin'] !== $inf['fecha_inicio']) {
    $fechas .= ' al ' . date('d/m/Y', strtotime($inf['fecha_fin']));
}

$transcriptor = !empty($inf['id_transcriptor'])
    ? trim((string) $inf['trans_nombre'] . ' ' . (string) $inf['trans_apellido']) . ' (V-' . $inf['trans_cedula'] . ')'
    : 'Sin transcriptor asignado';

$lista = '';
foreach ($integrantes as $n => $int) {
    $lista .= '<tr><td>' . ($n + 1) . '</td><td>' . $h(trim($int['nombre'] . ' ' . $int['apellido'])) . '</td><td>' . $h($int['cedula']) . '</td></tr>';
}
if ($lista === '') {
    $lista = '<tr><td colspan="3" style="text-align:center;">No hay integrantes registrados.</td></tr>';
}

$html = '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><title>Informe de Mesa</title>
<style>
    body { font-family: "Times New Roman", Times, serif; color: #222; font-size: 11pt; }
    .enc { text-align: center; border-bottom: 3px solid #521f29; padding-bottom: 8px; margin-bottom: 16px; }
    .enc h1 { margin: 0; color: #521f29; font-size: 17pt; }
    .enc p { margin: 2px 0; font-size: 10pt; color: #555; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
    th, td { border: 1px solid #c9b8a6; padding: 5px 7px; vertical-align: top; }
    th { background: #521f29; color: #fff; text-align: left; font-size: 9.5pt; }
    .datos td:first-child { width: 25%; font-weight: bold; background: #f3e9dd; }
    .contenido { white-space: pre-wrap; line-height: 1.5; min-height: 160px; border: 1px solid #c9b8a6; padding: 10px; }
    .pie { margin-top: 18px; font-size: 9pt; color: #666; border-top: 1px solid #c9b8a6; padding-top: 6px; }
</style></head><body>
<div class="enc">
    <h1>ESCUELA INTERNACIONAL DE LIDERAZGO DE LA MUJER &quot;NORA CASTAÑEDA&quot;</h1>
    <p>Informe de Mesa de Trabajo · ' . $h($inf['codigo_foro']) . '</p>
</div>
<table class="datos">
    <tr><td>Evento</td><td>' . $h($inf['foro_nombre']) . ' (' . $h($inf['tipo_foro']) . ')</td></tr>
    <tr><td>Fechas / Lugar</td><td>' . $h($fechas) . ' · ' . $h($inf['lugar'] ?? '—') . '</td></tr>
    <tr><td>Mesa de trabajo</td><td>Mesa ' . (int) $inf['numero_mesa'] . ' · ' . $h($inf['nombre_mesa'] ?? '—') . '</td></tr>
    <tr><td>Transcriptor</td><td>' . $h($transcriptor) . '</td></tr>
    <tr><td>Participante</td><td>' . $h(trim($inf['est_nombre'] . ' ' . $inf['est_apellido'])) . ' (V-' . $h($inf['est_cedula']) . ')</td></tr>
    <tr><td>Fecha de envío</td><td>' . (!empty($inf['fecha_envio']) ? date('d/m/Y H:i', strtotime($inf['fecha_envio'])) : '—') . '</td></tr>
</table>
<p><strong>Integrantes de la mesa de trabajo:</strong></p>
<table><thead><tr><th>N°</th><th>Nombre y apellido</th><th>Cédula</th></tr></thead><tbody>' . $lista . '</tbody></table>
<p><strong>Contenido del informe presentado por el participante:</strong></p>
<div class="contenido">' . nl2br($h($inf['contenido'])) . '</div>
' . (!empty($inf['informe_transcriptor']) ? '<p><strong>Síntesis del transcriptor:</strong></p><div class="contenido">' . nl2br($h($inf['informe_transcriptor'])) . '</div>' : '') . '
<div class="pie">
    Documento generado por el Sistema Nora · Módulo Foros, Conversatorios y Seminarios ·
    Emitido el ' . date('d/m/Y H:i') . '.
</div>
</body></html>';

$options = new \Dompdf\Options();
$options->setChroot(dirname(__DIR__, 2));
$options->setIsRemoteEnabled(false);
$options->setIsHtml5ParserEnabled(true);
$options->setDefaultFont('Times New Roman');

$dompdf = new \Dompdf\Dompdf($options);
$dompdf->loadHtml($html, 'UTF-8');
$dompdf->setPaper('letter', 'portrait');
$dompdf->render();

$nombre = 'Informe_Mesa_' . (int) $inf['numero_mesa'] . '_' . preg_replace('/[^A-Za-z0-9]/', '_', $inf['est_cedula']) . '.pdf';
if (!headers_sent()) {
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $nombre . '"');
    header('Content-Length: ' . strlen($dompdf->output()));
    header('Cache-Control: private, max-age=0, must-revalidate');
}
echo $dompdf->output();
exit;
