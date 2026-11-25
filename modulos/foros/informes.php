<?php
// /sistema_nora/modulos/foros/informes.php
require_once '../../config/db.php';
require_once '../../includes/header.php';
require_once '../../includes/buscador_lista.php';
require_once __DIR__ . '/_funciones.php';

if (!foro_puede_administrar()) {
    echo "<div class='container-fluid pt-4'><div class='alert alert-danger font-weight-bold'>Acceso restringido al módulo de Foros, Conversatorios y Seminarios.</div></div>";
    require_once '../../includes/footer.php';
    exit();
}

$id_foro = intval($_GET['id'] ?? $_POST['id_foro'] ?? 0);

if (isset($_POST['guardar_informe_mesa']) && $id_foro > 0) {
    $idMesa = intval($_POST['id_mesa'] ?? 0);
    $contenido = trim($_POST['informe_transcriptor'] ?? '');
    // La síntesis solo existe cuando la mesa ya tiene un transcriptor habilitado.
    $st = $pdo->prepare('SELECT id_transcriptor FROM public.foro_mesas WHERE id_mesa = :m AND id_foro = :f LIMIT 1');
    $st->execute([':m' => $idMesa, ':f' => $id_foro]);
    $mesaActual = $st->fetch();
    if (!$mesaActual) {
        foro_redirect('informes.php?id=' . $id_foro, 'La mesa no pertenece a este evento.', 'error');
    }
    if (empty($mesaActual['id_transcriptor'])) {
        foro_redirect('informes.php?id=' . $id_foro,
            'Habilite primero al transcriptor desde Mesas de Trabajo > integrantes de la mesa.', 'error');
    }
    $st = $pdo->prepare("UPDATE public.foro_mesas
                            SET informe_transcriptor = :c, fecha_informe_transcriptor = CURRENT_TIMESTAMP
                          WHERE id_mesa = :m AND id_foro = :f");
    $st->execute([':c' => $contenido, ':m' => $idMesa, ':f' => $id_foro]);
    foro_bitacora($pdo, 'INFORME_TRANSCRIPTOR', 'Mesa ' . $idMesa);
    foro_redirect('informes.php?id=' . $id_foro, 'Informe del transcriptor guardado.');
}

$eventos = $pdo->query('SELECT id_foro, codigo_foro, nombre FROM public.foros_eventos ORDER BY fecha_inicio DESC NULLS LAST, id_foro DESC')->fetchAll(PDO::FETCH_ASSOC);
$evento = foro_evento($pdo, $id_foro);

$informes = [];
$mesas = [];
if ($id_foro > 0) {
    $st = $pdo->prepare("SELECT i.*, m.numero_mesa, m.nombre_mesa, m.id_transcriptor,
                               ut.nombre AS trans_nombre, ut.apellido AS trans_apellido,
                               ue.nombre AS est_nombre, ue.apellido AS est_apellido, ue.cedula AS est_cedula
                          FROM public.foro_informes i
                          JOIN public.foro_mesas m ON m.id_mesa = i.id_mesa
                          LEFT JOIN public.usuarios ut ON ut.id_usuario = m.id_transcriptor
                          JOIN public.foro_participantes p ON p.id_participante = i.id_participante
                          JOIN public.usuarios ue ON ue.id_usuario = p.id_estudiante
                         WHERE m.id_foro = :f
                         ORDER BY m.numero_mesa, ue.apellido, ue.nombre");
    $st->execute([':f' => $id_foro]);
    $informes = $st->fetchAll(PDO::FETCH_ASSOC);

    $st = $pdo->prepare("SELECT m.*, u.nombre, u.apellido,
                               (SELECT COUNT(*) FROM public.foro_mesa_participantes mp WHERE mp.id_mesa = m.id_mesa) AS integrantes
                          FROM public.foro_mesas m
                          LEFT JOIN public.usuarios u ON u.id_usuario = m.id_transcriptor
                         WHERE m.id_foro = :f ORDER BY m.numero_mesa");
    $st->execute([':f' => $id_foro]);
    $mesas = $st->fetchAll(PDO::FETCH_ASSOC);
}
$stats = foro_stats($pdo, $id_foro);
?>
<div class="container-fluid pt-3">
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 font-weight-bold" style="color:#521f29;">
            <i class="fas fa-file-alt mr-2 text-warning"></i>Informes de Mesas de Trabajo
        </h1>
        <a href="index.php" class="btn btn-sm btn-secondary font-weight-bold shadow-sm" style="border-radius:8px;">
            <i class="fas fa-arrow-left mr-1"></i> Volver
        </a>
    </div>

    <?php if (isset($_GET['msg'])): ?>
        <div class="alert alert-success font-weight-bold"><?= htmlspecialchars(urldecode($_GET['msg'])) ?></div>
    <?php endif; ?>

    <div class="card shadow border-0 mb-4" style="border-radius:15px;">
        <div class="card-body bg-white p-4">
            <form method="GET" class="form-inline">
                <label class="font-weight-bold mr-2">Evento:</label>
                <select name="id" class="form-control mr-2" onchange="this.form.submit()" style="min-width:320px;">
                    <option value="0">-- Seleccione un evento --</option>
                    <?php foreach ($eventos as $e): ?>
                        <option value="<?= (int) $e['id_foro'] ?>" <?= $id_foro === (int) $e['id_foro'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($e['codigo_foro'] . ' · ' . $e['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($id_foro > 0): ?>
                    <span class="ml-auto font-weight-bold">Informes enviados:
                        <span class="badge badge-success"><?= (int) $stats['informes'] ?></span>
                    </span>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <?php if ($id_foro > 0): ?>
        <?php if ($mesas): ?>
            <div class="card shadow border-0 mb-4" style="border-radius:15px;">
                <div class="card-header text-white font-weight-bold" style="background-color:#521f29; border-radius:15px 15px 0 0;">
                    Informe general de cada mesa (registrado por el transcriptor)
                </div>
                <div class="card-body bg-white p-4">
                    <?php foreach ($mesas as $m): ?>
                        <form method="POST" class="border-bottom pb-3 mb-3">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id_foro" value="<?= (int) $id_foro ?>">
                            <input type="hidden" name="id_mesa" value="<?= (int) $m['id_mesa'] ?>">
                            <label class="font-weight-bold">Mesa <?= (int) $m['numero_mesa'] ?> ·
                                <?php if (!empty($m['id_transcriptor'])): ?>
                                    Transcriptor: <?= htmlspecialchars(trim((string) $m['nombre'] . ' ' . (string) $m['apellido'])) ?>
                                <?php else: ?>
                                    <span class="badge badge-warning text-dark">Sin transcriptor asignado</span>
                                <?php endif; ?>
                                <small class="text-muted">(<?= (int) $m['integrantes'] ?> integrantes)</small>
                            </label>
                            <?php if (empty($m['id_transcriptor'])): ?>
                                <div class="alert alert-light border mb-0 font-weight-bold">
                                    Habilite al transcriptor desde <a href="mesas.php?id=<?= (int) $id_foro ?>">Mesas de Trabajo</a>
                                    para poder registrar la síntesis de esta mesa.
                                </div>
                            <?php else: ?>
                                <textarea name="informe_transcriptor" class="form-control" rows="3"
                                          placeholder="Síntesis general de la mesa, conclusiones y acuerdos..."><?= htmlspecialchars($m['informe_transcriptor'] ?? '') ?></textarea>
                                <button type="submit" name="guardar_informe_mesa" class="btn btn-sm btn-outline-primary mt-2 font-weight-bold">
                                    <i class="fas fa-save mr-1"></i> Guardar informe de la mesa
                                </button>
                            <?php endif; ?>
                        </form>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="card shadow border-0" style="border-radius:15px;">
            <div class="card-header text-white font-weight-bold" style="background-color:#521f29; border-radius:15px 15px 0 0;">
                Informes enviados por los participantes
            </div>
            <div class="card-body bg-white p-4">
                <?php if ($informes) { buscador_lista_render('Buscar participante o transcriptor por nombre o cédula…', '#tablaInformesForo table'); } ?>
                <?php if (!$informes): ?>
                    <div class="alert alert-info mb-0">Todavía no hay informes enviados. Los participantes pueden redactarlos desde su usuario.</div>
                <?php else: ?>
                    <div class="table-responsive" id="tablaInformesForo">
                        <table class="table table-hover table-sm">
                            <thead class="thead-light">
                                <tr><th>Mesa</th><th>Participante</th><th>Cédula</th><th>Transcriptor</th><th>Fecha envío</th><th>Estado</th><th>PDF</th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($informes as $i): ?>
                                <tr>
                                    <td>Mesa <?= (int) $i['numero_mesa'] ?><br><small><?= htmlspecialchars($i['nombre_mesa'] ?? '') ?></small></td>
                                    <td class="font-weight-bold"><?= htmlspecialchars(trim($i['est_nombre'] . ' ' . $i['est_apellido'])) ?></td>
                                    <td><?= htmlspecialchars($i['est_cedula']) ?></td>
                                    <td><?= !empty($i['id_transcriptor'])
                                        ? htmlspecialchars(trim((string) $i['trans_nombre'] . ' ' . (string) $i['trans_apellido']))
                                        : '<span class="badge badge-secondary">Sin asignar</span>' ?></td>
                                    <td><?= !empty($i['fecha_envio']) ? date('d/m/Y H:i', strtotime($i['fecha_envio'])) : '—' ?></td>
                                    <td><span class="badge badge-<?= $i['estatus'] === 'Enviado' ? 'success' : 'warning' ?>"><?= htmlspecialchars($i['estatus']) ?></span></td>
                                    <td><a href="informe_pdf.php?id=<?= (int) $i['id_informe'] ?>" class="btn btn-sm btn-danger font-weight-bold" target="_blank">
                                        <i class="fas fa-file-pdf mr-1"></i> Descargar
                                    </a></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <hr>
                    <p class="text-muted mb-0 small">
                        Cada PDF incluye el contenido del informe, el nombre del transcriptor y la lista de integrantes de la mesa,
                        como documento final que confirma el cumplimiento de los objetivos del evento.
                    </p>
                <?php endif; ?>
            </div>
        </div>
    <?php else: ?>
        <div class="alert alert-warning font-weight-bold">Seleccione un evento para ver sus informes.</div>
    <?php endif; ?>
</div>

<?php require_once '../../includes/footer.php'; ?>
