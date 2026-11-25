<?php
// /sistema_nora/modulos/foros/certificados.php
require_once '../../config/db.php';
require_once '../../config/mailer.php';
require_once '../../includes/foro_certificado_pdf.php';
require_once '../../includes/header.php';
require_once '../../includes/buscador_lista.php';
require_once __DIR__ . '/_funciones.php';

if (!foro_puede_administrar()) {
    echo "<div class='container-fluid pt-4'><div class='alert alert-danger font-weight-bold'>Acceso restringido al módulo de Foros, Conversatorios y Seminarios.</div></div>";
    require_once '../../includes/footer.php';
    exit();
}

$id_foro = intval($_GET['id'] ?? $_POST['id_foro'] ?? 0);
$config = certificado_config($pdo);
$msg = '';
$tipoMsg = 'success';

/* ------------------------------------------------------------------ */
/* Envío por correo (individual o de todos los certificados del foro)  */
/* ------------------------------------------------------------------ */
function foro_enviar_certificado(PDO $pdo, array $config, int $idCertificadoForo): array
{
    require_once dirname(__DIR__, 2) . '/includes/foro_certificado_pdf.php';
    $datos = foro_certificado_datos($pdo, $idCertificadoForo);
    if (!$datos) {
        return ['ok' => false, 'mensaje' => 'Certificado no encontrado.'];
    }
    $correo = trim((string) ($datos['email'] ?: ($datos['correo'] ?? '')));
    if ($correo === '' || !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'mensaje' => 'El participante no tiene un correo válido registrado.'];
    }

    $nombre = trim($datos['nombre'] . ' ' . ($datos['apellido'] ?? ''));
    $rol = mb_strtoupper((string) $datos['rol_certificado'], 'UTF-8');
    $pdf = foro_certificado_pdf_output($datos, 'Certificado_' . $datos['folio'] . '.pdf', false, $config);
    $cuerpo = plantilla_correo('Su certificado de foro está disponible', '
        <p>Estimado(a) <strong>' . htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8') . '</strong>:</p>
        <p>Adjunto encontrará su <strong>Certificado de ' . htmlspecialchars($rol, ENT_QUOTES, 'UTF-8') . '</strong>
           correspondiente al evento <strong>' . htmlspecialchars($datos['foro_nombre'], ENT_QUOTES, 'UTF-8') . '</strong>,
           folio <strong>' . htmlspecialchars($datos['folio'], ENT_QUOTES, 'UTF-8') . '</strong>,
           libro <strong>' . htmlspecialchars($datos['libro'], ENT_QUOTES, 'UTF-8') . '</strong>,
           línea <strong>' . (int) $datos['linea_libro'] . '</strong>.</p>
        <p>La autenticidad puede verificarse escaneando el QR del reverso o ingresando el folio y el código
           <strong>' . htmlspecialchars($datos['codigo_validacion'], ENT_QUOTES, 'UTF-8') . '</strong>
           en el sistema público de verificación.</p>
        <p>También puede consultarlo en cualquier momento desde su usuario, en “Mis Certificados”.</p>
    ');
    $ok = enviarCorreoSistema(
        $correo,
        'Certificado de ' . $datos['rol_certificado'] . ' - ' . $datos['foro_nombre'] . ' - Escuela Nora Castañeda',
        $cuerpo,
        $nombre,
        [['contenido' => $pdf, 'nombre' => 'Certificado_' . $datos['folio'] . '.pdf', 'tipo' => 'application/pdf']]
    );

    $stLog = $pdo->prepare("INSERT INTO public.certificado_envios
            (id_certificado, correo, estatus, mensaje) VALUES (:c, :e, :s, :m)");
    $stLog->execute([
        ':c' => $datos['id_certificado'], ':e' => $correo,
        ':s' => $ok ? 'Enviado' : 'Fallido',
        ':m' => $ok ? 'PDF adjunto enviado (Foro).' : 'No se pudo enviar por SMTP.',
    ]);

    return [
        'ok' => $ok,
        'mensaje' => $datos['folio'] . ': ' . ($ok ? 'enviado a ' . $correo : 'error al enviar por SMTP.'),
    ];
}

/* ------------------------------------------------------------------ */
/* Acciones POST                                                       */
/* ------------------------------------------------------------------ */
if (isset($_POST['emitir']) && $id_foro > 0) {
    $idPart = intval($_POST['id_participante'] ?? 0);
    try {
        $res = foro_certificado_emitir($pdo, $idPart, (int) ($_SESSION['id_usuario'] ?? 0));
        foro_bitacora($pdo, 'CERTIFICADO_FORO_EMITIDO',
            'Participante ' . $idPart . ' · ' . $res['rol'] . ' · ' . $res['certificado']['folio']);
        $msg = 'Certificado ' . $res['rol'] . ' emitido. Folio: ' . $res['certificado']['folio']
             . ($res['nuevo'] ? '' : ' (folio existente actualizado).');
    } catch (Throwable $e) {
        $msg = 'No se pudo emitir: ' . $e->getMessage();
        $tipoMsg = 'error';
    }
    foro_redirect('certificados.php?id=' . $id_foro, $msg, $tipoMsg);
}

if (isset($_POST['emitir_todos']) && $id_foro > 0) {
    $st = $pdo->prepare("SELECT p.id_participante FROM public.foro_participantes p
                          WHERE p.id_foro = :f
                            AND EXISTS (SELECT 1 FROM public.foro_asistencias a
                                         WHERE a.id_participante = p.id_participante
                                           AND a.tipo_asistencia = 'Ponencia')");
    $st->execute([':f' => $id_foro]);
    $ids = $st->fetchAll(PDO::FETCH_COLUMN);
    $emitidos = 0;
    $omitidos = 0;
    foreach ($ids as $idp) {
        try {
            foro_certificado_emitir($pdo, (int) $idp, (int) ($_SESSION['id_usuario'] ?? 0));
            $emitidos++;
        } catch (Throwable $e) {
            $omitidos++;
        }
    }
    foro_bitacora($pdo, 'CERTIFICADOS_FORO_MASIVO', 'Foro ' . $id_foro . ' · ' . $emitidos . ' emitidos');
    foro_redirect('certificados.php?id=' . $id_foro,
        "Certificados procesados: $emitidos. Sin asistencia a ponencia o con error: $omitidos.", $emitidos ? 'success' : 'error');
}

if (isset($_POST['enviar_correo']) && $id_foro > 0) {
    $idCf = intval($_POST['id_certificado_foro'] ?? 0);
    $res = foro_enviar_certificado($pdo, $config, $idCf);
    foro_bitacora($pdo, 'CERTIFICADO_FORO_ENVIADO', $res['mensaje']);
    foro_redirect('certificados.php?id=' . $id_foro, $res['mensaje'], $res['ok'] ? 'success' : 'error');
}

if (isset($_POST['enviar_todos']) && $id_foro > 0) {
    $st = $pdo->prepare("SELECT fc.id_certificado_foro FROM public.foro_certificados fc
                          JOIN public.foro_participantes p ON p.id_participante = fc.id_participante
                         WHERE p.id_foro = :f");
    $st->execute([':f' => $id_foro]);
    $ids = $st->fetchAll(PDO::FETCH_COLUMN);
    $ok = 0;
    $fallos = [];
    foreach ($ids as $idcf) {
        $r = foro_enviar_certificado($pdo, $config, (int) $idcf);
        if ($r['ok']) { $ok++; } else { $fallos[] = $r['mensaje']; }
    }
    $msg = "Correos enviados: $ok de " . count($ids) . '.';
    if ($fallos) { $msg .= ' Fallos: ' . implode('; ', array_slice($fallos, 0, 5)); }
    foro_bitacora($pdo, 'CERTIFICADOS_FORO_CORREO_MASIVO', $msg);
    foro_redirect('certificados.php?id=' . $id_foro, $msg, $ok ? 'success' : 'error');
}

/* ------------------------------------------------------------------ */
/* Datos de pantalla                                                   */
/* ------------------------------------------------------------------ */
$eventos = $pdo->query('SELECT id_foro, codigo_foro, nombre FROM public.foros_eventos ORDER BY fecha_inicio DESC NULLS LAST, id_foro DESC')->fetchAll(PDO::FETCH_ASSOC);
$evento = foro_evento($pdo, $id_foro);

$participantes = [];
if ($id_foro > 0) {
    $st = $pdo->prepare("SELECT p.id_participante, u.nombre, u.apellido, u.cedula, u.email, u.correo,
                               MAX(CASE WHEN a.tipo_asistencia = 'Ponencia' THEN 1 ELSE 0 END) AS ponencia,
                               MAX(CASE WHEN a.tipo_asistencia = 'Mesa de Trabajo' THEN 1 ELSE 0 END) AS mesa,
                               (SELECT COUNT(*) FROM public.foro_mesa_participantes mp WHERE mp.id_participante = p.id_participante) AS en_mesa,
                               (SELECT COUNT(*) FROM public.foro_informes i WHERE i.id_participante = p.id_participante AND i.estatus = 'Enviado') AS informes,
                               fc.id_certificado_foro, fc.rol_certificado AS rol_guardado,
                               ce.folio, ce.libro, ce.linea_libro, ce.estado AS estado_certificado,
                               ce.id_certificado,
                               (SELECT ce2.estatus FROM public.certificado_envios ce2
                                 WHERE ce2.id_certificado = ce.id_certificado
                                 ORDER BY ce2.fecha_envio DESC LIMIT 1) AS ultimo_envio
                          FROM public.foro_participantes p
                          JOIN public.usuarios u ON u.id_usuario = p.id_estudiante
                          LEFT JOIN public.foro_asistencias a ON a.id_participante = p.id_participante
                          LEFT JOIN public.foro_certificados fc ON fc.id_participante = p.id_participante
                          LEFT JOIN public.certificados_emitidos ce ON ce.id_certificado = fc.id_certificado_emitido
                         WHERE p.id_foro = :f
                         GROUP BY p.id_participante, u.nombre, u.apellido, u.cedula, u.email, u.correo,
                                  fc.id_certificado_foro, fc.rol_certificado, ce.folio, ce.libro,
                                  ce.linea_libro, ce.estado, ce.id_certificado
                         ORDER BY u.apellido, u.nombre");
    $st->execute([':f' => $id_foro]);
    $participantes = $st->fetchAll(PDO::FETCH_ASSOC);
}
$stats = foro_stats($pdo, $id_foro);
?>
<div class="container-fluid pt-3">
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 font-weight-bold" style="color:#521f29;">
            <i class="fas fa-award mr-2 text-warning"></i>Certificados de Foros, Conversatorios y Seminarios
        </h1>
        <a href="index.php" class="btn btn-sm btn-secondary font-weight-bold shadow-sm" style="border-radius:8px;">
            <i class="fas fa-arrow-left mr-1"></i> Volver
        </a>
    </div>

    <?php if (isset($_GET['msg'])): ?>
        <div class="alert alert-<?= ($_GET['tipo'] ?? 'success') === 'error' ? 'danger' : 'success' ?> font-weight-bold">
            <?= htmlspecialchars(urldecode($_GET['msg'])) ?>
        </div>
    <?php endif; ?>

    <div class="card shadow border-0 mb-4" style="border-radius:15px;">
        <div class="card-body bg-white p-4">
            <form method="GET" class="form-inline">
                <label class="font-weight-bold mr-2">Evento:</label>
                <select name="id" class="form-control mr-2" onchange="this.form.submit()" style="min-width:340px;">
                    <option value="0">-- Seleccione un evento --</option>
                    <?php foreach ($eventos as $e): ?>
                        <option value="<?= (int) $e['id_foro'] ?>" <?= $id_foro === (int) $e['id_foro'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($e['codigo_foro'] . ' · ' . $e['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($id_foro > 0): ?>
                    <span class="ml-auto font-weight-bold">
                        Emitidos: <span class="badge badge-success"><?= (int) $stats['certificados'] ?></span>
                        &nbsp;|&nbsp;Participantes: <span class="badge badge-primary"><?= (int) $stats['participantes'] ?></span>
                    </span>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <?php if ($id_foro > 0): ?>
        <div class="alert alert-info font-weight-bold">
            <i class="fas fa-info-circle mr-2"></i>
            El certificado se emite como <strong>Oyente</strong> si el estudiante solo escuchó la ponencia,
            y como <strong>Participante</strong> si además asistió a una mesa de trabajo o envió su informe.
            Todos comparten el libro físico y se registra su folio y línea.
        </div>

        <div class="card shadow border-0 mb-4" style="border-radius:15px;">
            <div class="card-header text-white d-flex justify-content-between align-items-center font-weight-bold"
                 style="background-color:#521f29; border-radius:15px 15px 0 0;">
                <span><i class="fas fa-book mr-2"></i>Libro: <?= htmlspecialchars($config['libro_numero']) ?>
                    &nbsp;|&nbsp;Folio actual: <?= htmlspecialchars($config['folio_actual']) ?>
                    &nbsp;|&nbsp;Línea actual: <?= htmlspecialchars($config['linea_actual']) ?></span>
                <form method="POST" class="mb-0">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id_foro" value="<?= (int) $id_foro ?>">
                    <button type="submit" name="emitir_todos" class="btn btn-sm btn-light font-weight-bold"
                            onclick="return confirm('¿Emitir certificados a todos los participantes con asistencia a ponencia?');">
                        <i class="fas fa-layer-group mr-1"></i> Emitir todos
                    </button>
                    <button type="submit" name="enviar_todos" class="btn btn-sm btn-light font-weight-bold ml-1"
                            onclick="return confirm('¿Enviar todos los certificados por correo?');">
                        <i class="fas fa-envelope mr-1"></i> Enviar todos
                    </button>
                </form>
            </div>
            <div class="card-body bg-white p-4">
                <?php if ($participantes) { buscador_lista_render('Buscar participante por nombre o cédula…', '#tablaCertificadosForo table'); } ?>
                <?php if (!$participantes): ?>
                    <div class="alert alert-warning mb-0">No hay participantes inscritos en este evento.</div>
                <?php else: ?>
                    <div class="table-responsive" id="tablaCertificadosForo">
                        <table class="table table-hover table-sm">
                            <thead class="thead-light">
                                <tr>
                                    <th>Participante</th><th>Cédula</th><th>Asistencia</th>
                                    <th>Rol calculado</th><th>Folio / Libro / Línea</th>
                                    <th>Correo</th><th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($participantes as $p): ?>
                                <?php
                                $rolCalculado = ((int) $p['ponencia'] > 0)
                                    ? (((int) $p['mesa'] > 0 || (int) $p['en_mesa'] > 0 || (int) $p['informes'] > 0) ? 'Participante' : 'Oyente')
                                    : 'Sin certificado';
                                $tieneCert = !empty($p['id_certificado_foro']) && !empty($p['folio']);
                                ?>
                                <tr>
                                    <td class="font-weight-bold"><?= htmlspecialchars(trim($p['nombre'] . ' ' . $p['apellido'])) ?></td>
                                    <td><?= htmlspecialchars($p['cedula']) ?></td>
                                    <td>
                                        <?php if ((int) $p['ponencia']): ?>
                                            <span class="badge badge-success" title="Escuchó la ponencia">Ponencia</span>
                                        <?php endif; ?>
                                        <?php if ((int) $p['mesa'] || (int) $p['en_mesa']): ?>
                                            <span class="badge badge-warning" title="Participó en mesa de trabajo">Mesa</span>
                                        <?php endif; ?>
                                        <?php if (!(int) $p['ponencia'] && !(int) $p['mesa'] && !(int) $p['en_mesa']): ?>
                                            <span class="badge badge-secondary">Sin asistencia</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge badge-<?= $rolCalculado === 'Participante' ? 'primary' : ($rolCalculado === 'Oyente' ? 'info' : 'secondary') ?>">
                                            <?= $rolCalculado ?>
                                        </span>
                                        <?php if ($tieneCert && $p['rol_guardado'] !== $rolCalculado): ?>
                                            <small class="text-danger d-block">Guardado: <?= htmlspecialchars($p['rol_guardado']) ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($tieneCert): ?>
                                            <small>
                                                <strong><?= htmlspecialchars($p['folio']) ?></strong><br>
                                                <?= htmlspecialchars($p['libro']) ?> · Línea <?= (int) $p['linea_libro'] ?><br>
                                                <span class="badge badge-<?= strtolower((string) $p['estado_certificado']) === 'emitido' ? 'success' : 'danger' ?>">
                                                    <?= htmlspecialchars($p['estado_certificado']) ?>
                                                </span>
                                                <?php if (!empty($p['ultimo_envio'])): ?>
                                                    <span class="badge badge-<?= $p['ultimo_envio'] === 'Enviado' ? 'success' : 'warning' ?>">
                                                        <?= htmlspecialchars($p['ultimo_envio']) ?>
                                                    </span>
                                                <?php endif; ?>
                                            </small>
                                        <?php else: ?>
                                            <span class="text-muted">Sin emitir</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><small><?= htmlspecialchars($p['email'] ?: ($p['correo'] ?? '—')) ?></small></td>
                                    <td class="text-nowrap">
                                        <?php if (!$tieneCert): ?>
                                            <?php if ((int) $p['ponencia']): ?>
                                                <form method="POST" class="d-inline">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="id_foro" value="<?= (int) $id_foro ?>">
                                                    <input type="hidden" name="id_participante" value="<?= (int) $p['id_participante'] ?>">
                                                    <button type="submit" name="emitir" class="btn btn-sm btn-success font-weight-bold"
                                                            title="Emitir certificado">
                                                        <i class="fas fa-certificate"></i> Emitir
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <button class="btn btn-sm btn-outline-secondary" disabled title="Requiere asistencia a ponencia">
                                                    <i class="fas fa-ban"></i>
                                                </button>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <?php if ($p['rol_guardado'] !== $rolCalculado): ?>
                                                <form method="POST" class="d-inline">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="id_foro" value="<?= (int) $id_foro ?>">
                                                    <input type="hidden" name="id_participante" value="<?= (int) $p['id_participante'] ?>">
                                                    <button type="submit" name="emitir" class="btn btn-sm btn-warning font-weight-bold"
                                                            title="Actualizar rol conservando el folio">
                                                        <i class="fas fa-sync"></i> Actualizar
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                            <a href="certificado_pdf.php?id=<?= (int) $p['id_certificado_foro'] ?>"
                                               class="btn btn-sm btn-danger font-weight-bold" title="Descargar PDF">
                                                <i class="fas fa-file-pdf"></i>
                                            </a>
                                            <form method="POST" class="d-inline">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="id_foro" value="<?= (int) $id_foro ?>">
                                                <input type="hidden" name="id_certificado_foro" value="<?= (int) $p['id_certificado_foro'] ?>">
                                                <button type="submit" name="enviar_correo" class="btn btn-sm btn-primary font-weight-bold"
                                                        title="Enviar por correo">
                                                    <i class="fas fa-envelope"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php else: ?>
        <div class="alert alert-warning font-weight-bold">Seleccione un evento para administrar sus certificados.</div>
    <?php endif; ?>
</div>

<?php require_once '../../includes/footer.php'; ?>
