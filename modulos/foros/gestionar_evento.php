<?php
// /sistema_nora/modulos/foros/gestionar_evento.php
require_once '../../config/db.php';
require_once '../../includes/header.php';
require_once __DIR__ . '/_funciones.php';

if (!foro_puede_administrar()) {
    echo "<div class='container-fluid pt-4'><div class='alert alert-danger font-weight-bold'>Acceso restringido al módulo de Foros, Conversatorios y Seminarios.</div></div>";
    require_once '../../includes/footer.php';
    exit();
}

$id_foro = intval($_GET['id'] ?? $_POST['id_foro'] ?? 0);
$mensaje = '';
$error = '';

// Eliminar tema
if (isset($_GET['action'], $_GET['id_tema']) && $_GET['action'] === 'eliminar_tema') {
    $idTema = intval($_GET['id_tema']);
    $st = $pdo->prepare('DELETE FROM public.foro_temas WHERE id_tema = :t AND id_foro = :f');
    $st->execute([':t' => $idTema, ':f' => $id_foro]);
    foro_bitacora($pdo, 'TEMA_ELIMINADO', 'Tema ' . $idTema . ' del foro ' . $id_foro);
    foro_redirect('gestionar_evento.php?id=' . $id_foro, 'Tema eliminado correctamente.');
}

// Guardar evento
if (isset($_POST['guardar_evento'])) {
    $nombre = trim($_POST['nombre'] ?? '');
    $tipo = $_POST['tipo_foro'] ?? 'Foro';
    $fechaIni = trim($_POST['fecha_inicio'] ?? '') ?: null;
    $fechaFin = trim($_POST['fecha_fin'] ?? '') ?: null;
    $lugar = trim($_POST['lugar'] ?? '');
    $horas = max(0, intval($_POST['horas_academicas'] ?? 0));
    $idMalla = !empty($_POST['id_malla']) ? intval($_POST['id_malla']) : null;
    $estatus = $_POST['estatus'] ?? 'Planificado';
    $descripcion = trim($_POST['descripcion'] ?? '');
    $pernocta = !empty($_POST['pernocta_habilitada']);

    if ($nombre === '') {
        $error = 'Debe indicar el nombre del evento.';
    } else {
        try {
            if ($id_foro > 0) {
                $st = $pdo->prepare("UPDATE public.foros_eventos
                    SET nombre = :n, tipo_foro = :t, fecha_inicio = :fi, fecha_fin = :ff,
                        lugar = :l, horas_academicas = :h, id_malla = :m, estatus = :e,
                        descripcion = :d, pernocta_habilitada = :per
                    WHERE id_foro = :id");
                $st->execute([
                    ':n' => $nombre, ':t' => $tipo, ':fi' => $fechaIni, ':ff' => $fechaFin,
                    ':l' => $lugar, ':h' => $horas, ':m' => $idMalla, ':e' => $estatus,
                    ':d' => $descripcion, ':per' => $pernocta ? 'true' : 'false', ':id' => $id_foro,
                ]);
                foro_bitacora($pdo, 'EVENTO_ACTUALIZADO', $nombre);
                $mensaje = 'Evento actualizado correctamente.';
            } else {
                $codigo = foro_codigo_nuevo($pdo);
                $st = $pdo->prepare("INSERT INTO public.foros_eventos
                    (codigo_foro, nombre, tipo_foro, descripcion, fecha_inicio, fecha_fin,
                     lugar, horas_academicas, id_malla, estatus, id_usuario_creador, pernocta_habilitada)
                    VALUES (:c, :n, :t, :d, :fi, :ff, :l, :h, :m, :e, :u, :per) RETURNING id_foro");
                $st->execute([
                    ':c' => $codigo, ':n' => $nombre, ':t' => $tipo, ':d' => $descripcion,
                    ':fi' => $fechaIni, ':ff' => $fechaFin, ':l' => $lugar, ':h' => $horas,
                    ':m' => $idMalla, ':e' => $estatus, ':u' => $_SESSION['id_usuario'] ?? null,
                    ':per' => $pernocta ? 'true' : 'false',
                ]);
                $id_foro = (int) $st->fetchColumn();
                foro_bitacora($pdo, 'EVENTO_CREADO', $codigo . ' - ' . $nombre);
                $mensaje = 'Evento creado correctamente. Cargue la malla de temas; los participantes se inscriben desde Recepción.';
            }
            foro_redirect('gestionar_evento.php?id=' . $id_foro, $mensaje);
        } catch (Throwable $e) {
            $error = 'No se pudo guardar el evento: ' . $e->getMessage();
        }
    }
}

// Guardar tema
if (isset($_POST['guardar_tema']) && $id_foro > 0) {
    $tema = trim($_POST['nombre_tema'] ?? '');
    $ponente = trim($_POST['ponente'] ?? '');
    $horasTema = max(0, intval($_POST['horas_tema'] ?? 0));
    $contenido = trim($_POST['contenido'] ?? '');
    if ($tema === '') {
        $error = 'Debe indicar el nombre del tema.';
    } else {
        $st = $pdo->prepare("INSERT INTO public.foro_temas
            (id_foro, nombre_tema, ponente, horas_academicas, contenido, orden_tema)
            VALUES (:f, :t, :p, :h, :c,
                    (SELECT COALESCE(MAX(orden_tema),0) + 1 FROM public.foro_temas WHERE id_foro = :f2))");
        $st->execute([':f' => $id_foro, ':f2' => $id_foro, ':t' => $tema, ':p' => $ponente, ':h' => $horasTema, ':c' => $contenido]);
        foro_bitacora($pdo, 'TEMA_AGREGADO', $tema . ' (' . $horasTema . ' h)');
        foro_redirect('gestionar_evento.php?id=' . $id_foro, 'Tema agregado a la malla del evento.');
    }
}

$evento = foro_evento($pdo, $id_foro);
$mallas = $pdo->query("SELECT id_malla, nombre_programa, tipo_oferta
                          FROM public.mallas_curriculares
                         WHERE tipo_oferta NOT IN ('Foros', 'Conversatorio', 'Seminarios')
                         ORDER BY nombre_programa")->fetchAll(PDO::FETCH_ASSOC);
$temas = $id_foro > 0
    ? $pdo->prepare('SELECT * FROM public.foro_temas WHERE id_foro = :f ORDER BY orden_tema, id_tema')
    : null;
if ($temas) { $temas->execute([':f' => $id_foro]); $temas = $temas->fetchAll(PDO::FETCH_ASSOC); }
else { $temas = []; }
?>
<div class="container-fluid pt-3">
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 font-weight-bold" style="color:#521f29;">
            <i class="fas fa-sitemap mr-2 text-warning"></i>
            <?= $evento ? 'Configurar: ' . htmlspecialchars($evento['nombre']) : 'Nuevo Foro, Conversatorio o Seminario' ?>
        </h1>
        <a href="index.php" class="btn btn-sm btn-secondary font-weight-bold shadow-sm" style="border-radius:8px;">
            <i class="fas fa-arrow-left mr-1"></i> Volver a Foros
        </a>
    </div>

    <?php if ($error !== ''): ?><div class="alert alert-danger font-weight-bold"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if (isset($_GET['msg'])): ?>
        <div class="alert alert-success font-weight-bold"><?= htmlspecialchars(urldecode($_GET['msg'])) ?></div>
    <?php endif; ?>

    <div class="row">
        <div class="col-lg-7 mb-4">
            <div class="card shadow border-0" style="border-radius:15px;">
                <div class="card-header text-white font-weight-bold" style="background-color:#521f29; border-radius:15px 15px 0 0;">
                    Datos generales del evento
                </div>
                <div class="card-body bg-white p-4">
                    <form method="POST">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id_foro" value="<?= (int) $id_foro ?>">
                        <div class="form-group">
                            <label class="font-weight-bold">Nombre del Evento *</label>
                            <input type="text" name="nombre" class="form-control" required
                                   value="<?= htmlspecialchars($evento['nombre'] ?? '') ?>"
                                   placeholder="Ej. Foro Internacional de Liderazgo Femenino">
                        </div>
                        <div class="row">
                            <div class="col-md-6 form-group">
                                <label class="font-weight-bold">Tipo *</label>
                                <select name="tipo_foro" class="form-control">
                                    <option value="Foro" <?= ($evento['tipo_foro'] ?? 'Foro') === 'Foro' ? 'selected' : '' ?>>Foro</option>
                                    <option value="Conversatorio" <?= ($evento['tipo_foro'] ?? '') === 'Conversatorio' ? 'selected' : '' ?>>Conversatorio</option>
                                    <option value="Seminario" <?= ($evento['tipo_foro'] ?? '') === 'Seminario' ? 'selected' : '' ?>>Seminario</option>
                                </select>
                            </div>
                            <div class="col-md-6 form-group">
                                <label class="font-weight-bold">Estatus</label>
                                <select name="estatus" class="form-control">
                                    <?php foreach (['Planificado', 'Activo', 'Finalizado'] as $est): ?>
                                        <option value="<?= $est ?>" <?= ($evento['estatus'] ?? 'Planificado') === $est ? 'selected' : '' ?>><?= $est ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6 form-group">
                                <label class="font-weight-bold">Fecha de inicio</label>
                                <input type="date" name="fecha_inicio" class="form-control" value="<?= htmlspecialchars($evento['fecha_inicio'] ?? '') ?>">
                            </div>
                            <div class="col-md-6 form-group">
                                <label class="font-weight-bold">Fecha de fin</label>
                                <input type="date" name="fecha_fin" class="form-control" value="<?= htmlspecialchars($evento['fecha_fin'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-8 form-group">
                                <label class="font-weight-bold">Lugar</label>
                                <input type="text" name="lugar" class="form-control" value="<?= htmlspecialchars($evento['lugar'] ?? '') ?>">
                            </div>
                            <div class="col-md-4 form-group">
                                <label class="font-weight-bold">Horas académicas</label>
                                <input type="number" min="0" name="horas_academicas" class="form-control" value="<?= (int) ($evento['horas_academicas'] ?? 0) ?>">
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="font-weight-bold">Programa académico relacionado (opcional)</label>
                            <select name="id_malla" class="form-control">
                                <option value="">-- Sin relación --</option>
                                <?php foreach ($mallas as $m): ?>
                                    <option value="<?= (int) $m['id_malla'] ?>" <?= ($evento['id_malla'] ?? 0) == $m['id_malla'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($m['nombre_programa']) ?> (<?= htmlspecialchars($m['tipo_oferta']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="form-text text-muted">
                                Solo programas de curso/carrera. La malla de temas del evento se carga más abajo en este módulo.
                            </small>
                        </div>
                        <div class="form-group">
                            <div class="custom-control custom-checkbox">
                                <input type="checkbox" class="custom-control-input" id="pernocta_habilitada"
                                       name="pernocta_habilitada" value="1"
                                       <?= !empty($evento['pernocta_habilitada']) ? 'checked' : '' ?>>
                                <label class="custom-control-label font-weight-bold" for="pernocta_habilitada">
                                    Habilitar pernocta para este evento
                                </label>
                            </div>
                            <small class="form-text text-muted">
                                Las pernoctas se registrarán por dormitorio y cédula; la entrada y salida se derivan de estas fechas.
                            </small>
                        </div>
                        <div class="form-group">
                            <label class="font-weight-bold">Descripción</label>
                            <textarea name="descripcion" class="form-control" rows="3"><?= htmlspecialchars($evento['descripcion'] ?? '') ?></textarea>
                        </div>
                        <button type="submit" name="guardar_evento" class="btn font-weight-bold text-white" style="background-color:#ff8a00; border-radius:8px;">
                            <i class="fas fa-save mr-1"></i> GUARDAR EVENTO
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-5 mb-4">
            <div class="card shadow border-0" style="border-radius:15px;">
                <div class="card-header text-white font-weight-bold" style="background-color:#521f29; border-radius:15px 15px 0 0;">
                    Malla / Temas del evento
                </div>
                <div class="card-body bg-white p-4">
                    <?php if (!$id_foro): ?>
                        <div class="alert alert-info mb-0">Guarde primero el evento para poder cargar sus temas.</div>
                    <?php else: ?>
                        <form method="POST" class="mb-4">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id_foro" value="<?= (int) $id_foro ?>">
                            <div class="form-group">
                                <label class="font-weight-bold">Tema / Ponencia *</label>
                                <input type="text" name="nombre_tema" class="form-control" required>
                            </div>
                            <div class="row">
                                <div class="col-md-8 form-group">
                                    <label class="font-weight-bold">Ponente</label>
                                    <input type="text" name="ponente" class="form-control">
                                </div>
                                <div class="col-md-4 form-group">
                                    <label class="font-weight-bold">Horas</label>
                                    <input type="number" min="0" name="horas_tema" class="form-control" value="1">
                                </div>
                            </div>
                            <div class="form-group">
                                <label class="font-weight-bold">Contenido del tema</label>
                                <textarea name="contenido" class="form-control" rows="2"></textarea>
                            </div>
                            <button type="submit" name="guardar_tema" class="btn btn-sm btn-success font-weight-bold">
                                <i class="fas fa-plus mr-1"></i> Agregar tema
                            </button>
                        </form>

                        <?php if (!$temas): ?>
                            <div class="alert alert-warning mb-0">Aún no hay temas cargados.</div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-sm table-hover mb-0">
                                    <thead class="thead-light"><tr><th>#</th><th>Tema</th><th>Ponente</th><th>Horas</th><th></th></tr></thead>
                                    <tbody>
                                    <?php foreach ($temas as $t): ?>
                                        <tr>
                                            <td><?= (int) $t['orden_tema'] ?></td>
                                            <td><?= htmlspecialchars($t['nombre_tema']) ?></td>
                                            <td><?= htmlspecialchars($t['ponente'] ?? '—') ?></td>
                                            <td><?= (int) $t['horas_academicas'] ?> h</td>
                                            <td><a href="?id=<?= (int) $id_foro ?>&action=eliminar_tema&id_tema=<?= (int) $t['id_tema'] ?>"
                                                   class="btn btn-sm btn-outline-danger"
                                                   onclick="return confirm('¿Eliminar este tema?');"><i class="fas fa-trash"></i></a></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                    <tfoot><tr class="font-weight-bold"><td colspan="3">TOTAL</td><td><?= (int) ($evento['horas_temas'] ?? 0) ?> h</td><td></td></tr></tfoot>
                                </table>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>
