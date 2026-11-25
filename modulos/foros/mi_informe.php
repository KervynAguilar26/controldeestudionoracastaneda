<?php
// /sistema_nora/modulos/foros/mi_informe.php
require_once '../../config/db.php';
require_once '../../includes/header.php';
require_once __DIR__ . '/_funciones.php';

$id_usuario = (int) ($_SESSION['id_usuario'] ?? 0);
$id_participante = intval($_GET['id'] ?? $_POST['id_participante'] ?? 0);
$error = '';

$st = $pdo->prepare("SELECT p.*, f.nombre AS foro_nombre, f.codigo_foro,
                            u.nombre AS estudiante_nombre, u.apellido AS estudiante_apellido
                       FROM public.foro_participantes p
                       JOIN public.foros_eventos f ON f.id_foro = p.id_foro
                       JOIN public.usuarios u ON u.id_usuario = p.id_estudiante
                      WHERE p.id_participante = :p AND p.id_estudiante = :u LIMIT 1");
$st->execute([':p' => $id_participante, ':u' => $id_usuario]);
$participante = $st->fetch();

if (!$participante) {
    echo "<div class='container-fluid pt-4'><div class='alert alert-danger font-weight-bold'>No tiene acceso a este informe.</div></div>";
    require_once '../../includes/footer.php';
    exit();
}

$mesas = $pdo->prepare("SELECT m.*,
                               (SELECT i.contenido FROM public.foro_informes i
                                 WHERE i.id_mesa = m.id_mesa AND i.id_participante = :p LIMIT 1) AS contenido,
                               (SELECT i.estatus FROM public.foro_informes i
                                 WHERE i.id_mesa = m.id_mesa AND i.id_participante = :p2 LIMIT 1) AS estatus,
                               (SELECT i.fecha_envio FROM public.foro_informes i
                                 WHERE i.id_mesa = m.id_mesa AND i.id_participante = :p3 LIMIT 1) AS fecha_envio
                          FROM public.foro_mesas m
                          JOIN public.foro_mesa_participantes mp ON mp.id_mesa = m.id_mesa
                         WHERE mp.id_participante = :p4
                         ORDER BY m.numero_mesa");
$mesas->execute([':p' => $id_participante, ':p2' => $id_participante, ':p3' => $id_participante, ':p4' => $id_participante]);
$mesas = $mesas->fetchAll(PDO::FETCH_ASSOC);

if (isset($_POST['guardar_informe'])) {
    $idMesa = intval($_POST['id_mesa'] ?? 0);
    $contenido = trim($_POST['contenido'] ?? '');
    $enviar = !empty($_POST['enviar']);
    $pertenece = false;
    foreach ($mesas as $m) { if ((int) $m['id_mesa'] === $idMesa) { $pertenece = true; break; } }

    if (!$pertenece) {
        $error = 'La mesa indicada no le corresponde.';
    } elseif ($contenido === '') {
        $error = 'Debe escribir el contenido del informe.';
    } else {
        $estatus = $enviar ? 'Enviado' : 'Borrador';
        $st = $pdo->prepare("INSERT INTO public.foro_informes
            (id_mesa, id_participante, contenido, estatus, fecha_envio)
            VALUES (:m, :p, :c, :e, " . ($enviar ? 'CURRENT_TIMESTAMP' : 'NULL') . ")
            ON CONFLICT (id_mesa, id_participante)
            DO UPDATE SET contenido = EXCLUDED.contenido,
                          estatus = CASE WHEN public.foro_informes.estatus = 'Enviado' THEN 'Enviado' ELSE EXCLUDED.estatus END,
                          fecha_envio = COALESCE(public.foro_informes.fecha_envio, EXCLUDED.fecha_envio),
                          fecha_actualizacion = CURRENT_TIMESTAMP");
        $st->execute([':m' => $idMesa, ':p' => $id_participante, ':c' => $contenido, ':e' => $estatus]);
        foro_bitacora($pdo, $enviar ? 'INFORME_ENVIADO' : 'INFORME_GUARDADO', 'Participante ' . $id_participante . ' · Mesa ' . $idMesa);
        foro_redirect('mi_informe.php?id=' . $id_participante, $enviar
            ? 'Informe enviado correctamente al módulo de Foros.'
            : 'Borrador guardado correctamente.');
    }
}
?>
<div class="container-fluid pt-3">
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 font-weight-bold" style="color:#521f29;">
            <i class="fas fa-pen mr-2 text-warning"></i>Mi Informe de Mesa de Trabajo
        </h1>
        <a href="mis_foros.php" class="btn btn-sm btn-secondary font-weight-bold shadow-sm" style="border-radius:8px;">
            <i class="fas fa-arrow-left mr-1"></i> Mis Foros
        </a>
    </div>

    <?php if ($error !== ''): ?><div class="alert alert-danger font-weight-bold"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if (isset($_GET['msg'])): ?><div class="alert alert-success font-weight-bold"><?= htmlspecialchars(urldecode($_GET['msg'])) ?></div><?php endif; ?>

    <div class="card shadow border-0 mb-4" style="border-radius:15px;">
        <div class="card-header text-white font-weight-bold" style="background-color:#521f29; border-radius:15px 15px 0 0;">
            <?= htmlspecialchars($participante['foro_nombre']) ?> (<?= htmlspecialchars($participante['codigo_foro']) ?>)
        </div>
        <div class="card-body bg-white p-4">
            <p class="mb-0"><strong>Participante:</strong>
                <?= htmlspecialchars(trim($participante['estudiante_nombre'] . ' ' . $participante['estudiante_apellido'])) ?></p>
        </div>
    </div>

    <?php if (!$mesas): ?>
        <div class="alert alert-warning font-weight-bold">Aún no ha sido inscrito en una mesa de trabajo.</div>
    <?php else: ?>
        <?php foreach ($mesas as $m): ?>
            <div class="card shadow border-0 mb-4" style="border-radius:15px;">
                <div class="card-header d-flex justify-content-between align-items-center font-weight-bold" style="background-color:#f3e9dd; border-radius:15px 15px 0 0;">
                    <span><i class="fas fa-table mr-2"></i>Mesa <?= (int) $m['numero_mesa'] ?> · <?= htmlspecialchars($m['nombre_mesa'] ?? 'Mesa de trabajo') ?></span>
                    <?php if ($m['estatus'] === 'Enviado'): ?>
                        <span class="badge badge-success"><i class="fas fa-check mr-1"></i>ENVIADO</span>
                    <?php elseif ($m['estatus'] === 'Borrador'): ?>
                        <span class="badge badge-warning">BORRADOR</span>
                    <?php else: ?>
                        <span class="badge badge-secondary">SIN REDACTAR</span>
                    <?php endif; ?>
                </div>
                <div class="card-body bg-white p-4">
                    <?php if ($m['estatus'] === 'Enviado'): ?>
                        <div class="alert alert-success font-weight-bold">
                            Su informe fue enviado el <?= date('d/m/Y H:i', strtotime($m['fecha_envio'])) ?>.
                            Ya está disponible para el transcriptor y el módulo de Foros.
                        </div>
                    <?php endif; ?>
                    <form method="POST">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id_participante" value="<?= (int) $id_participante ?>">
                        <input type="hidden" name="id_mesa" value="<?= (int) $m['id_mesa'] ?>">
                        <div class="form-group">
                            <label class="font-weight-bold">Contenido del informe de la mesa *</label>
                            <textarea name="contenido" class="form-control" rows="10"
                                      placeholder="Escriba aquí lo tratado en la mesa de trabajo, aportes, conclusiones y compromisos..."
                                      <?= $m['estatus'] === 'Enviado' ? 'readonly' : '' ?>><?= htmlspecialchars($m['contenido'] ?? '') ?></textarea>
                            <small class="form-text text-muted">
                                Al enviar, el informe queda registrado en el módulo Foros y en su expediente académico.
                            </small>
                        </div>
                        <?php if ($m['estatus'] !== 'Enviado'): ?>
                            <button type="submit" name="guardar_informe" class="btn btn-secondary font-weight-bold mr-2" style="border-radius:8px;">
                                <i class="fas fa-save mr-1"></i> Guardar borrador
                            </button>
                            <button type="submit" name="guardar_informe" value="1"
                                    onclick="this.form.querySelector('[name=enviar]').value='1'"
                                    class="btn font-weight-bold text-white" style="background-color:#ff8a00; border-radius:8px;">
                                <i class="fas fa-paper-plane mr-1"></i> Enviar informe
                            </button>
                            <input type="hidden" name="enviar" value="0">
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php require_once '../../includes/footer.php'; ?>
