<?php
// /sistema_nora/modulos/foros/asistencias.php
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

if (isset($_POST['marcar_asistencia']) && $id_foro > 0) {
    $idPart = intval($_POST['id_participante'] ?? 0);
    $tipo = $_POST['tipo'] ?? '';
    if (!in_array($tipo, ['Ponencia', 'Mesa de Trabajo'], true)) {
        foro_redirect('asistencias.php?id=' . $id_foro, 'Tipo de asistencia inválido.', 'error');
    }
    $verificar = $pdo->prepare('SELECT 1 FROM public.foro_participantes WHERE id_participante = :p AND id_foro = :f');
    $verificar->execute([':p' => $idPart, ':f' => $id_foro]);
    if (!$verificar->fetchColumn()) {
        foro_redirect('asistencias.php?id=' . $id_foro, 'El participante no pertenece a este evento.', 'error');
    }
    if (!empty($_POST['quitar'])) {
        $st = $pdo->prepare('DELETE FROM public.foro_asistencias WHERE id_participante = :p AND tipo_asistencia = :t');
        $st->execute([':p' => $idPart, ':t' => $tipo]);
        foro_bitacora($pdo, 'ASISTENCIA_QUITADA', 'Participante ' . $idPart . ' · ' . $tipo);
        foro_redirect('asistencias.php?id=' . $id_foro, 'Asistencia retirada.');
    }
    $st = $pdo->prepare("INSERT INTO public.foro_asistencias
        (id_participante, tipo_asistencia, registrado_por)
        VALUES (:p, :t, :r)
        ON CONFLICT (id_participante, tipo_asistencia) DO NOTHING");
    $st->execute([':p' => $idPart, ':t' => $tipo, ':r' => $_SESSION['id_usuario'] ?? null]);
    foro_bitacora($pdo, 'ASISTENCIA_REGISTRADA', 'Participante ' . $idPart . ' · ' . $tipo);
    foro_redirect('asistencias.php?id=' . $id_foro, 'Asistencia registrada correctamente.');
}

$eventos = $pdo->query('SELECT id_foro, codigo_foro, nombre FROM public.foros_eventos ORDER BY fecha_inicio DESC NULLS LAST, id_foro DESC')->fetchAll(PDO::FETCH_ASSOC);
$evento = foro_evento($pdo, $id_foro);

$participantes = [];
if ($id_foro > 0) {
    $st = $pdo->prepare("SELECT p.id_participante, u.cedula, u.nombre, u.apellido,
                               MAX(CASE WHEN a.tipo_asistencia = 'Ponencia' THEN 1 ELSE 0 END) AS ponencia,
                               MAX(CASE WHEN a.tipo_asistencia = 'Mesa de Trabajo' THEN 1 ELSE 0 END) AS mesa
                          FROM public.foro_participantes p
                          JOIN public.usuarios u ON u.id_usuario = p.id_estudiante
                          LEFT JOIN public.foro_asistencias a ON a.id_participante = p.id_participante
                         WHERE p.id_foro = :f
                         GROUP BY p.id_participante, u.cedula, u.nombre, u.apellido
                         ORDER BY u.apellido, u.nombre");
    $st->execute([':f' => $id_foro]);
    $participantes = $st->fetchAll(PDO::FETCH_ASSOC);
}
$stats = foro_stats($pdo, $id_foro);
?>
<div class="container-fluid pt-3">
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 font-weight-bold" style="color:#521f29;">
            <i class="fas fa-clipboard-check mr-2 text-warning"></i>Asistencia Interna
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
                <select name="id" class="form-control mr-2" onchange="this.form.submit()" style="min-width:320px;">
                    <option value="0">-- Seleccione un evento --</option>
                    <?php foreach ($eventos as $e): ?>
                        <option value="<?= (int) $e['id_foro'] ?>" <?= $id_foro === (int) $e['id_foro'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($e['codigo_foro'] . ' · ' . $e['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($id_foro > 0): ?>
                    <span class="ml-auto font-weight-bold">
                        Ponencia: <span class="badge badge-success"><?= (int) $stats['asistencia_ponencia'] ?></span>
                        &nbsp; Mesas: <span class="badge badge-warning"><?= (int) $stats['asistencia_mesa'] ?></span>
                    </span>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <?php if ($id_foro > 0): ?>
        <div class="alert alert-info font-weight-bold">
            La asistencia a <strong>Ponencia</strong> confirma que el estudiante escuchó la presentación.
            La asistencia a <strong>Mesa de Trabajo</strong> se registra al inscribirlo en una mesa
            (también puede marcarse manualmente). Con ambas, el certificado se emite como
            <strong>Participante</strong>; solo con la ponencia, como <strong>Oyente</strong>.
        </div>
        <div class="card shadow border-0" style="border-radius:15px;">
            <div class="card-header text-white font-weight-bold" style="background-color:#521f29; border-radius:15px 15px 0 0;">
                Registro de asistencia por participante
            </div>
            <div class="card-body bg-white p-4">
                <?php if ($participantes) { buscador_lista_render('Buscar por nombre o cédula…', '#tablaAsistenciaForo table'); } ?>
                <?php if (!$participantes): ?>
                    <div class="alert alert-warning mb-0">No hay participantes. Regístrelos desde “Participantes”.</div>
                <?php else: ?>
                    <div class="table-responsive" id="tablaAsistenciaForo">
                        <table class="table table-hover table-sm">
                            <thead class="thead-light">
                                <tr><th>Participante</th><th>Cédula</th><th>Ponencia</th><th>Mesa de Trabajo</th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($participantes as $p): ?>
                                <tr>
                                    <td class="font-weight-bold"><?= htmlspecialchars(trim($p['nombre'] . ' ' . $p['apellido'])) ?></td>
                                    <td><?= htmlspecialchars($p['cedula']) ?></td>
                                    <td>
                                        <form method="POST" class="form-inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id_foro" value="<?= (int) $id_foro ?>">
                                            <input type="hidden" name="id_participante" value="<?= (int) $p['id_participante'] ?>">
                                            <input type="hidden" name="tipo" value="Ponencia">
                                            <button type="submit" name="marcar_asistencia"
                                                    class="btn btn-sm <?= (int) $p['ponencia'] ? 'btn-success' : 'btn-outline-secondary' ?> font-weight-bold">
                                                <i class="fas fa-<?= (int) $p['ponencia'] ? 'check' : 'times' ?> mr-1"></i>
                                                <?= (int) $p['ponencia'] ? 'Asistió' : 'Marcar' ?>
                                            </button>
                                            <?php if ((int) $p['ponencia']): ?>
                                                <button type="submit" name="marcar_asistencia" value="1"
                                                        onclick="this.form.querySelector('[name=quitar]').value='1'"
                                                        class="btn btn-sm btn-link text-danger" title="Quitar asistencia">
                                                    <i class="fas fa-undo"></i>
                                                </button>
                                                <input type="hidden" name="quitar" value="0">
                                            <?php endif; ?>
                                        </form>
                                    </td>
                                    <td>
                                        <form method="POST" class="form-inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id_foro" value="<?= (int) $id_foro ?>">
                                            <input type="hidden" name="id_participante" value="<?= (int) $p['id_participante'] ?>">
                                            <input type="hidden" name="tipo" value="Mesa de Trabajo">
                                            <input type="hidden" name="quitar" value="0">
                                            <button type="submit" name="marcar_asistencia"
                                                    class="btn btn-sm <?= (int) $p['mesa'] ? 'btn-warning' : 'btn-outline-secondary' ?> font-weight-bold">
                                                <i class="fas fa-<?= (int) $p['mesa'] ? 'check' : 'times' ?> mr-1"></i>
                                                <?= (int) $p['mesa'] ? 'Asistió' : 'Marcar' ?>
                                            </button>
                                        </form>
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
        <div class="alert alert-warning font-weight-bold">Seleccione un evento para registrar la asistencia.</div>
    <?php endif; ?>
</div>

<?php require_once '../../includes/footer.php'; ?>
