<?php
// /sistema_nora/modulos/foros/mesas.php
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

if (isset($_POST['crear_mesa']) && $id_foro > 0) {
    $numero = max(1, intval($_POST['numero_mesa'] ?? 0));
    $nombre = trim($_POST['nombre_mesa'] ?? '');
    $capacidad = max(1, intval($_POST['capacidad'] ?? 10));
    try {
        // La mesa se crea primero; el transcriptor se habilita después desde sus integrantes.
        $st = $pdo->prepare("INSERT INTO public.foro_mesas
            (id_foro, numero_mesa, nombre_mesa, capacidad)
            VALUES (:f, :n, :m, :c)");
        $st->execute([':f' => $id_foro, ':n' => $numero, ':m' => $nombre, ':c' => $capacidad]);
        foro_bitacora($pdo, 'MESA_CREADA', 'Mesa ' . $numero . ' del foro ' . $id_foro . ' (sin transcriptor)');
        foro_redirect('mesas.php?id=' . $id_foro, 'Mesa creada. Ahora inscriba integrantes y habilite al transcriptor.');
    } catch (Throwable $e) {
        foro_redirect('mesas.php?id=' . $id_foro, 'No se pudo crear la mesa: ' . $e->getMessage(), 'error');
    }
}

if (isset($_POST['inscribir_participante']) && $id_foro > 0) {
    $idMesa = intval($_POST['id_mesa'] ?? 0);
    $idPart = intval($_POST['id_participante'] ?? 0);
    try {
        $pdo->beginTransaction();
        $st = $pdo->prepare("INSERT INTO public.foro_mesa_participantes (id_mesa, id_participante)
                             SELECT :m, :p
                             WHERE EXISTS (SELECT 1 FROM public.foro_mesas WHERE id_mesa = :m AND id_foro = :f)
                               AND EXISTS (SELECT 1 FROM public.foro_participantes WHERE id_participante = :p AND id_foro = :f)
                             ON CONFLICT (id_mesa, id_participante) DO NOTHING");
        $st->execute([':m' => $idMesa, ':p' => $idPart, ':f' => $id_foro]);
        if ($st->rowCount() > 0) {
            $asist = $pdo->prepare("INSERT INTO public.foro_asistencias
                (id_participante, tipo_asistencia, registrado_por, observaciones)
                VALUES (:p, 'Mesa de Trabajo', :r, :o)
                ON CONFLICT (id_participante, tipo_asistencia) DO NOTHING");
            $asist->execute([':p' => $idPart, ':r' => $_SESSION['id_usuario'] ?? null, ':o' => 'Registrado desde mesa de trabajo']);
            foro_bitacora($pdo, 'PARTICIPANTE_EN_MESA', 'Participante ' . $idPart . ' en mesa ' . $idMesa);
        }
        $pdo->commit();
        foro_redirect('mesas.php?id=' . $id_foro, 'Participante inscrito en la mesa.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        foro_redirect('mesas.php?id=' . $id_foro, 'No se pudo inscribir: ' . $e->getMessage(), 'error');
    }
}

if (isset($_POST['quitar_de_mesa']) && $id_foro > 0) {
    $idMp = intval($_POST['id_mesa_participante'] ?? 0);
    $st = $pdo->prepare("DELETE FROM public.foro_mesa_participantes
                          WHERE id_mesa_participante = :mp
                            AND id_mesa IN (SELECT id_mesa FROM public.foro_mesas WHERE id_foro = :f)");
    $st->execute([':mp' => $idMp, ':f' => $id_foro]);
    foro_bitacora($pdo, 'PARTICIPANTE_RETIRADO_MESA', 'Registro ' . $idMp);
    foro_redirect('mesas.php?id=' . $id_foro, 'Participante retirado de la mesa.');
}

if (isset($_POST['habilitar_transcriptor']) && $id_foro > 0) {
    $idMp = intval($_POST['id_mesa_participante'] ?? 0);
    try {
        $pdo->beginTransaction();
        $st = $pdo->prepare("SELECT mp.id_mesa, p.id_estudiante, u.nombre, u.apellido
                               FROM public.foro_mesa_participantes mp
                               JOIN public.foro_mesas m ON m.id_mesa = mp.id_mesa
                               JOIN public.foro_participantes p ON p.id_participante = mp.id_participante
                               JOIN public.usuarios u ON u.id_usuario = p.id_estudiante
                              WHERE mp.id_mesa_participante = :mp AND m.id_foro = :f
                              LIMIT 1");
        $st->execute([':mp' => $idMp, ':f' => $id_foro]);
        $elegido = $st->fetch();
        if (!$elegido) { throw new Exception('El integrante no pertenece a una mesa de este evento.'); }
        $up = $pdo->prepare('UPDATE public.foro_mesas SET id_transcriptor = :t WHERE id_mesa = :m AND id_foro = :f');
        $up->execute([':t' => $elegido['id_estudiante'], ':m' => $elegido['id_mesa'], ':f' => $id_foro]);
        foro_bitacora($pdo, 'TRANSCRIPTOR_HABILITADO',
            trim($elegido['nombre'] . ' ' . $elegido['apellido']) . ' en mesa ' . $elegido['id_mesa']);
        $pdo->commit();
        foro_redirect('mesas.php?id=' . $id_foro, 'Transcriptor habilitado correctamente para esta mesa.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        foro_redirect('mesas.php?id=' . $id_foro, 'No se pudo habilitar el transcriptor: ' . $e->getMessage(), 'error');
    }
}

if (isset($_POST['deshabilitar_transcriptor']) && $id_foro > 0) {
    $idMesa = intval($_POST['id_mesa'] ?? 0);
    $st = $pdo->prepare('UPDATE public.foro_mesas SET id_transcriptor = NULL WHERE id_mesa = :m AND id_foro = :f');
    $st->execute([':m' => $idMesa, ':f' => $id_foro]);
    foro_bitacora($pdo, 'TRANSCRIPTOR_DESHABILITADO', 'Mesa ' . $idMesa);
    foro_redirect('mesas.php?id=' . $id_foro, 'El transcriptor fue deshabilitado. Puede elegir otro integrante.');
}

if (isset($_POST['cambiar_estatus_mesa']) && $id_foro > 0) {
    $idMesa = intval($_POST['id_mesa'] ?? 0);
    $estatus = $_POST['nuevo_estatus'] === 'Cerrada' ? 'Cerrada' : 'Abierta';
    $st = $pdo->prepare('UPDATE public.foro_mesas SET estatus = :e WHERE id_mesa = :m AND id_foro = :f');
    $st->execute([':e' => $estatus, ':m' => $idMesa, ':f' => $id_foro]);
    foro_bitacora($pdo, 'MESA_' . strtoupper($estatus), 'Mesa ' . $idMesa);
    foro_redirect('mesas.php?id=' . $id_foro, 'Estatus de la mesa actualizado.');
}

$eventos = $pdo->query('SELECT id_foro, codigo_foro, nombre FROM public.foros_eventos ORDER BY fecha_inicio DESC NULLS LAST, id_foro DESC')->fetchAll(PDO::FETCH_ASSOC);
$evento = foro_evento($pdo, $id_foro);

$mesas = [];
$participantes = [];
if ($id_foro > 0) {
    $st = $pdo->prepare("SELECT m.*, u.nombre, u.apellido, u.cedula AS cedula_transcriptor,
                               (SELECT COUNT(*) FROM public.foro_mesa_participantes mp WHERE mp.id_mesa = m.id_mesa) AS total_integrantes,
                               (SELECT COUNT(*) FROM public.foro_informes i WHERE i.id_mesa = m.id_mesa AND i.estatus = 'Enviado') AS informes_enviados
                          FROM public.foro_mesas m
                          LEFT JOIN public.usuarios u ON u.id_usuario = m.id_transcriptor
                         WHERE m.id_foro = :f
                         ORDER BY m.numero_mesa");
    $st->execute([':f' => $id_foro]);
    $mesas = $st->fetchAll(PDO::FETCH_ASSOC);

    $st = $pdo->prepare("SELECT p.id_participante, u.nombre, u.apellido, u.cedula,
                               (SELECT COUNT(*) FROM public.foro_mesa_participantes mp WHERE mp.id_participante = p.id_participante) AS en_mesas
                          FROM public.foro_participantes p
                          JOIN public.usuarios u ON u.id_usuario = p.id_estudiante
                         WHERE p.id_foro = :f ORDER BY u.apellido, u.nombre");
    $st->execute([':f' => $id_foro]);
    $participantes = $st->fetchAll(PDO::FETCH_ASSOC);
}

$integrantes_por_mesa = [];
if ($mesas) {
    $st = $pdo->prepare("SELECT mp.id_mesa_participante, mp.id_mesa, mp.id_participante, p.id_estudiante AS id_usuario,
                                u.nombre, u.apellido, u.cedula,
                                (SELECT i.estatus FROM public.foro_informes i
                                  WHERE i.id_mesa = mp.id_mesa AND i.id_participante = mp.id_participante LIMIT 1) AS estatus_informe
                           FROM public.foro_mesa_participantes mp
                           JOIN public.foro_participantes p ON p.id_participante = mp.id_participante
                           JOIN public.usuarios u ON u.id_usuario = p.id_estudiante
                          WHERE mp.id_mesa = :m ORDER BY u.apellido, u.nombre");
    foreach ($mesas as $m) {
        $st->execute([':m' => $m['id_mesa']]);
        $integrantes_por_mesa[$m['id_mesa']] = $st->fetchAll(PDO::FETCH_ASSOC);
    }
}
?>
<div class="container-fluid pt-3">
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 font-weight-bold" style="color:#521f29;">
            <i class="fas fa-table mr-2 text-warning"></i>Mesas de Trabajo
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
                    <a href="informes.php?id=<?= (int) $id_foro ?>" class="btn btn-sm btn-info ml-auto font-weight-bold">Ver informes enviados</a>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <?php if ($id_foro > 0): ?>
    <div class="row mb-4">
        <div class="col-lg-5 mb-3">
            <div class="card shadow border-0 h-100" style="border-radius:15px;">
                <div class="card-header text-white font-weight-bold" style="background-color:#521f29; border-radius:15px 15px 0 0;">
                    <i class="fas fa-plus mr-1"></i> Registrar una mesa de trabajo
                </div>
                <div class="card-body bg-white p-4">
                    <form method="POST">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id_foro" value="<?= (int) $id_foro ?>">
                        <div class="row">
                            <div class="col-md-4 form-group">
                                <label class="font-weight-bold">N° de mesa</label>
                                <input type="number" min="1" name="numero_mesa" class="form-control" value="<?= count($mesas) + 1 ?>" required>
                            </div>
                            <div class="col-md-8 form-group">
                                <label class="font-weight-bold">Nombre / tema de la mesa</label>
                                <input type="text" name="nombre_mesa" class="form-control" placeholder="Ej. Mesa 1 - Liderazgo">
                            </div>
                        </div>
                        <div class="alert alert-light border font-weight-bold mb-3" style="color:#521f29;">
                            <i class="fas fa-info-circle mr-1 text-warning"></i>
                            Ordene así: <strong>1)</strong> cree la mesa, <strong>2)</strong> inscriba integrantes y
                            <strong>3)</strong> habilite al transcriptor desde la lista de participantes de la mesa.
                        </div>
                        <div class="form-group">
                            <label class="font-weight-bold">Capacidad de participantes</label>
                            <input type="number" min="1" name="capacidad" class="form-control" value="10">
                        </div>
                        <button type="submit" name="crear_mesa" class="btn font-weight-bold text-white" style="background-color:#ff8a00; border-radius:8px;">
                            <i class="fas fa-save mr-1"></i> Crear Mesa
                        </button>
                    </form>
                </div>
            </div>
        </div>
        <div class="col-lg-7 mb-3">
            <div class="card shadow border-0 h-100" style="border-radius:15px;">
                <div class="card-header text-white font-weight-bold" style="background-color:#ff8a00; border-radius:15px 15px 0 0;">
                    Resumen de mesas
                </div>
                <div class="card-body bg-white p-4">
                    <?php if (!$mesas): ?>
                        <div class="alert alert-info mb-0">Aún no hay mesas registradas en este evento.</div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover mb-0">
                                <thead class="thead-light"><tr><th>Mesa</th><th>Transcriptor</th><th>Integrantes</th><th>Informes</th><th>Estatus</th></tr></thead>
                                <tbody>
                                <?php foreach ($mesas as $m): ?>
                                    <tr>
                                        <td class="font-weight-bold">Mesa <?= (int) $m['numero_mesa'] ?><br><small><?= htmlspecialchars($m['nombre_mesa'] ?? '—') ?></small></td>
                                        <td><?= !empty($m['id_transcriptor'])
                                            ? htmlspecialchars(trim((string) $m['nombre'] . ' ' . (string) $m['apellido']))
                                            : '<span class="badge badge-warning text-dark">Sin asignar</span>' ?></td>
                                        <td><?= (int) $m['total_integrantes'] ?> / <?= (int) $m['capacidad'] ?></td>
                                        <td><?= (int) $m['informes_enviados'] ?></td>
                                        <td><span class="badge badge-<?= $m['estatus'] === 'Abierta' ? 'success' : 'secondary' ?>"><?= htmlspecialchars($m['estatus']) ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php foreach ($mesas as $m): ?>
        <div class="card shadow border-0 mb-3" style="border-radius:15px;">
            <div class="card-header d-flex justify-content-between align-items-center font-weight-bold" style="background-color:#f3e9dd; border-radius:15px 15px 0 0;">
                <span><i class="fas fa-table mr-2"></i>Mesa <?= (int) $m['numero_mesa'] ?> · <?= htmlspecialchars($m['nombre_mesa'] ?? 'Mesa de trabajo') ?></span>
                <?php if (!empty($m['id_transcriptor'])): ?>
                <form method="POST" class="form-inline mb-0 mr-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id_foro" value="<?= (int) $id_foro ?>">
                    <input type="hidden" name="id_mesa" value="<?= (int) $m['id_mesa'] ?>">
                    <button type="submit" name="deshabilitar_transcriptor" class="btn btn-sm btn-outline-danger font-weight-bold">
                        <i class="fas fa-user-times mr-1"></i> Quitar transcriptor
                    </button>
                </form>
                <?php endif; ?>
                <form method="POST" class="form-inline mb-0">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id_foro" value="<?= (int) $id_foro ?>">
                    <input type="hidden" name="id_mesa" value="<?= (int) $m['id_mesa'] ?>">
                    <input type="hidden" name="nuevo_estatus" value="<?= $m['estatus'] === 'Abierta' ? 'Cerrada' : 'Abierta' ?>">
                    <button type="submit" name="cambiar_estatus_mesa" class="btn btn-sm btn-outline-secondary font-weight-bold">
                        <?= $m['estatus'] === 'Abierta' ? 'Cerrar mesa' : 'Reabrir mesa' ?>
                    </button>
                </form>
            </div>
            <div class="card-body bg-white p-4">
                <p class="mb-3">
                    <?php if (!empty($m['id_transcriptor'])): ?>
                        <strong>Transcriptor:</strong>
                        <?= htmlspecialchars(trim((string) $m['nombre'] . ' ' . (string) $m['apellido'])) ?>
                        &nbsp;|&nbsp; <strong>Cédula:</strong> V-<?= htmlspecialchars($m['cedula_transcriptor'] ?? '') ?>
                    <?php else: ?>
                        <span class="badge badge-warning text-dark font-weight-bold">Transcriptor pendiente</span>
                        <small class="text-muted">Inscriba integrantes y use “Habilitar transcriptor”.</small>
                    <?php endif; ?>
                    &nbsp;|&nbsp; <strong>Estatus:</strong> <?= htmlspecialchars($m['estatus']) ?>
                </p>

                <?php if ($m['estatus'] === 'Abierta'): ?>
                <form method="POST" class="form-inline mb-4">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id_foro" value="<?= (int) $id_foro ?>">
                    <input type="hidden" name="id_mesa" value="<?= (int) $m['id_mesa'] ?>">
                    <select name="id_participante" class="form-control mr-2" required style="min-width:280px;">
                        <option value="">-- Participante a inscribir --</option>
                        <?php foreach ($participantes as $p): ?>
                            <option value="<?= (int) $p['id_participante'] ?>">
                                <?= htmlspecialchars(trim($p['nombre'] . ' ' . $p['apellido'])) ?> (V-<?= htmlspecialchars($p['cedula']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" name="inscribir_participante" class="btn btn-sm btn-success font-weight-bold">
                        <i class="fas fa-user-plus mr-1"></i> Inscribir en la mesa
                    </button>
                </form>
                <?php endif; ?>

                <?php if (!empty($integrantes_por_mesa[$m['id_mesa']])) { buscador_lista_render('Buscar integrante por nombre o cédula…', '#integrantesMesa' . (int) $m['id_mesa'] . ' table'); } ?>
                <?php if (empty($integrantes_por_mesa[$m['id_mesa']])): ?>
                    <div class="alert alert-warning mb-0">Esta mesa aún no tiene participantes registrados.</div>
                <?php else: ?>
                    <div class="table-responsive" id="integrantesMesa<?= (int) $m['id_mesa'] ?>">
                        <table class="table table-sm table-hover mb-0">
                            <thead class="thead-light"><tr><th>Participante</th><th>Cédula</th><th>Informe</th><th class="text-center">Transcriptor</th><th class="text-center">Acciones</th></tr></thead>
                            <tbody>
                            <?php foreach ($integrantes_por_mesa[$m['id_mesa']] as $int): ?>
                                <tr>
                                    <td class="font-weight-bold"><?= htmlspecialchars(trim($int['nombre'] . ' ' . $int['apellido'])) ?></td>
                                    <td><?= htmlspecialchars($int['cedula']) ?></td>
                                    <td><?= !empty($int['estatus_informe'])
                                        ? '<span class="badge badge-' . ($int['estatus_informe'] === 'Enviado' ? 'success' : 'warning') . '">' . htmlspecialchars($int['estatus_informe']) . '</span>'
                                        : '<span class="badge badge-secondary">Sin redactar</span>' ?></td>
                                    <td class="text-center">
                                        <?php if ((int) $m['id_transcriptor'] === (int) $int['id_usuario']): ?>
                                            <span class="badge badge-primary font-weight-bold">Transcriptor actual</span>
                                        <?php else: ?>
                                            <form method="POST" class="mb-0">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="id_foro" value="<?= (int) $id_foro ?>">
                                                <input type="hidden" name="id_mesa_participante" value="<?= (int) $int['id_mesa_participante'] ?>">
                                                <button type="submit" name="habilitar_transcriptor"
                                                        class="btn btn-sm btn-outline-primary font-weight-bold"
                                                        onclick="return confirm('¿Habilitar a este integrante como transcriptor de la mesa?');">
                                                    <i class="fas fa-pen mr-1"></i> Habilitar transcriptor
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <form method="POST" class="mb-0">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id_foro" value="<?= (int) $id_foro ?>">
                                            <input type="hidden" name="id_mesa_participante" value="<?= (int) $int['id_mesa_participante'] ?>">
                                            <button type="submit" name="quitar_de_mesa" class="btn btn-sm btn-outline-danger"
                                                    onclick="return confirm('¿Quitar este participante de la mesa?');">
                                                <i class="fas fa-trash"></i>
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
    <?php endforeach; ?>
    <?php else: ?>
        <div class="alert alert-warning font-weight-bold">Seleccione un evento para administrar sus mesas de trabajo.</div>
    <?php endif; ?>
</div>

<?php require_once '../../includes/footer.php'; ?>
