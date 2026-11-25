<?php
// /sistema_nora/modulos/recepcion/registrar_estudiante_pernocta.php
// Pre-registro de participantes por cédula en Foros/Conversatorios/Seminarios.
// Recepción es la única entrada de inscripción. La cama es un dato aparte:
// solo se ofrece cuando el evento tiene pernocta habilitada.
require_once '../../config/db.php';
require_once __DIR__ . '/../../includes/pernocta.php';
require_once '../../includes/header.php';
require_once __DIR__ . '/padron.php';
require_once __DIR__ . '/bitacora.php';

if (!tiene_permiso(['superadmin', 'admin', 'recepcion', 'control_estudio'], $roles_array)) {
    echo "<div class='alert alert-danger font-weight-bold m-4'>Acceso restringido a Recepción / Control de Estudio.</div>";
    require_once '../../includes/footer.php';
    exit();
}

$mensaje = '';
$tipo_alerta = 'success';

// ---------------------------------------------------------------------------
// Alta del pre-registro (participación por cédula)
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['preregistrar'])) {
    try {
        $cedula     = pernocta_cedula_limpia($_POST['cedula'] ?? '');
        $id_foro    = intval($_POST['id_foro'] ?? 0);
        $nombre     = trim((string) ($_POST['nombre_manual'] ?? ''));
        $telefono   = trim((string) ($_POST['telefono'] ?? ''));

        if ($cedula === '') { throw new Exception('Indique la cédula del estudiante.'); }
        if ($id_foro <= 0) { throw new Exception('Seleccione el Foro o Conversatorio.'); }

        $foros = foro_eventos_inscripcion_disponibles($pdo, $id_foro);
        if (empty($foros)) {
            throw new Exception('El evento seleccionado no admite pre-registro.');
        }

        // Resolver/crear al estudiante por cédula.
        $res = pernocta_resolver_estudiante($pdo, $pdo_padron ?? null, $cedula, $nombre, $telefono);
        if (!$res['ok']) { throw new Exception($res['error']); }

        $insc = pernocta_inscribir_foro($pdo, $id_foro, (int) $res['id_usuario']);
        if (!empty($insc['error'])) { throw new Exception($insc['error']); }

        recepcion_bitacora($pdo, 'PREREGISTRO FORO PERNOCTA: V-' . $cedula . ' -> foro #' . $id_foro
            . ($res['creado'] ? ' (usuario creado)' : ''));

        if ($insc['ya']) {
            $mensaje = 'El estudiante <strong>' . pernocta_e($res['nombre']) . '</strong> ya estaba inscrito en este evento.';
            $tipo_alerta = 'info';
        } else {
            $mensaje = 'Pre-registro exitoso: <strong>' . pernocta_e($res['nombre']) . '</strong> (V-'
                . pernocta_e($cedula) . ') inscrito en <strong>' . pernocta_e($foros[0]['codigo_foro'])
                . ' — ' . pernocta_e($foros[0]['nombre']) . '</strong>.';
            if (!empty($foros[0]['pernocta_habilitada'])) {
                $mensaje .= ' Este evento admite pernocta; asigne la cama desde Dormitorios y Pernoctas.';
            }
            if ($res['creado']) {
                $mensaje .= ' Se creó su cuenta; puede ingresar con su cédula y la clave <code>Nora123456+-</code>.';
            }
            $tipo_alerta = 'success';
        }
        $_POST['cedula'] = $_POST['nombre_manual'] = $_POST['telefono'] = '';
    } catch (Throwable $e) {
        $mensaje = 'No se pudo completar el pre-registro: ' . $e->getMessage();
        $tipo_alerta = 'danger';
    }
}

// Baja del pre-registro (también la gestiona Recepción).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['quitar_participante'])) {
    $idPart = intval($_POST['id_participante'] ?? 0);
    $idForo = intval($_POST['id_foro'] ?? 0);
    if ($idPart > 0 && $idForo > 0) {
        try {
            $st = $pdo->prepare('DELETE FROM public.foro_participantes
                                  WHERE id_participante = :p AND id_foro = :f');
            $st->execute([':p' => $idPart, ':f' => $idForo]);
            if ($st->rowCount() > 0) {
                recepcion_bitacora($pdo, 'BAJA PARTICIPANTE FORO: id_participante ' . $idPart . ' del foro #' . $idForo);
                $mensaje = 'El participante fue retirado del evento.';
                $tipo_alerta = 'info';
            }
        } catch (Throwable $e) {
            $mensaje = 'No se pudo retirar al participante: ' . pernocta_e($e->getMessage());
            $tipo_alerta = 'danger';
        }
    }
}

$eventos = foro_eventos_inscripcion_disponibles($pdo);
$cedula_buscar = pernocta_cedula_limpia($_GET['cedula'] ?? ($_POST['cedula'] ?? ''));
$inscritos = [];
if ($cedula_buscar !== '') {
    try {
        $st = $pdo->prepare("SELECT f.id_foro, f.codigo_foro, f.nombre, f.tipo_foro,
                                   f.fecha_inicio, f.fecha_fin, f.horas_academicas,
                                   p.id_participante, p.estatus AS estatus_participante,
                                   (SELECT COUNT(*) FROM public.foro_temas t WHERE t.id_foro = f.id_foro) AS total_temas,
                                   (SELECT d.nombre FROM public.dormitorio_pernoctas dp
                                      JOIN public.dormitorios d ON d.id_dormitorio = dp.id_dormitorio
                                     WHERE dp.id_foro = f.id_foro
                                       AND regexp_replace(dp.cedula, '[^0-9]', '', 'g') = :ced
                                       AND dp.estado = 'En Curso'
                                     LIMIT 1) AS dormitorio
                              FROM public.foro_participantes p
                              JOIN public.foros_eventos f ON f.id_foro = p.id_foro
                              JOIN public.usuarios u ON u.id_usuario = p.id_estudiante
                             WHERE regexp_replace(u.cedula, '[^0-9]', '', 'g') = :ced
                             ORDER BY f.fecha_inicio DESC NULLS LAST, p.id_participante DESC");
        $st->execute([':ced' => $cedula_buscar]);
        $inscritos = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}
}
?>
<div class="container-fluid py-4">
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 font-weight-bold" style="color: #521f29;">
            <i class="fas fa-user-check mr-2 text-warning"></i>Pre-registro de Participantes — Foros, Conversatorios y Seminarios
        </h1>
        <div class="d-flex flex-wrap">
            <a href="../configuracion/dormitorios.php" class="btn btn-sm btn-secondary font-weight-bold shadow-sm mr-2 mb-2">
                <i class="fas fa-arrow-left mr-1"></i> Dormitorios y Pernoctas
            </a>
            <a href="index.php" class="btn btn-sm btn-secondary font-weight-bold shadow-sm mb-2">
                <i class="fas fa-arrow-left mr-1"></i> Recepción
            </a>
        </div>
    </div>

    <?php if ($mensaje !== ''): ?>
        <div class="alert alert-<?= pernocta_e($tipo_alerta) ?> font-weight-bold shadow-sm"><?= $mensaje ?></div>
    <?php endif; ?>

    <?php if (empty($eventos)): ?>
        <div class="alert alert-warning font-weight-bold">
            <i class="fas fa-exclamation-triangle mr-2"></i>
            No hay eventos abiertos para pre-registro. Créelos desde
            <a href="../foros/gestionar_evento.php" class="font-weight-bold">Foros, Conversatorios y Seminarios</a>.
        </div>
    <?php endif; ?>

    <div class="card shadow border-0 mx-auto mb-4" style="max-width: 1100px; border-radius: 15px;">
        <div class="card-header py-3 text-white" style="background-color: #521f29;">
            <h6 class="m-0 font-weight-bold"><i class="fas fa-id-card mr-2 text-warning"></i>
                Inscribir con solo la cédula</h6>
        </div>
        <div class="card-body bg-white p-4">
            <form method="POST" class="form-row">
                <?= csrf_field() ?>
                <div class="form-group col-md-3">
                    <label class="font-weight-bold">Cédula del estudiante *</label>
                    <input type="text" name="cedula" class="form-control font-weight-bold" required
                           inputmode="numeric" maxlength="20" placeholder="Ej: 12345678"
                           value="<?= pernocta_e($_POST['cedula'] ?? '') ?>"
                           oninput="this.value=this.value.replace(/[^0-9]/g,'')">
                    <small class="form-text text-muted">La cédula es la llave del registro.</small>
                </div>
                <div class="form-group col-md-4">
                    <label class="font-weight-bold">Foro / Conversatorio / Seminario *</label>
                    <select name="id_foro" class="form-control" required>
                        <option value="">— Seleccione —</option>
                        <?php foreach ($eventos as $e): ?>
                            <option value="<?= (int) $e['id_foro'] ?>"
                                <?= (intval($_POST['id_foro'] ?? 0) === (int) $e['id_foro']) ? 'selected' : '' ?>>
                                <?= pernocta_e($e['codigo_foro'] . ' — ' . $e['nombre'] . ' [' . $e['tipo_foro'] . ']') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="form-text text-muted">
                        Todos los eventos activos. Si admite pernocta, la cama se asigna aparte desde Dormitorios.
                    </small>
                </div>
                <div class="form-group col-md-3">
                    <label class="font-weight-bold">Nombre (solo si no está en el sistema)</label>
                    <input type="text" name="nombre_manual" class="form-control" maxlength="150"
                           value="<?= pernocta_e($_POST['nombre_manual'] ?? '') ?>"
                           placeholder="Opcional: se toma del Padrón">
                </div>
                <div class="form-group col-md-2">
                    <label class="font-weight-bold">Teléfono (opcional)</label>
                    <input type="text" name="telefono" class="form-control" maxlength="30"
                           value="<?= pernocta_e($_POST['telefono'] ?? '') ?>">
                </div>
                <div class="form-group col-md-12 mb-0">
                    <button type="submit" name="preregistrar" value="1"
                            class="btn font-weight-bold text-white" style="background-color: #521f29; border-radius: 10px;">
                        <i class="fas fa-user-plus mr-2" style="color: #ff8a00;"></i> REGISTRAR ESTUDIANTE
                    </button>
                </div>
            </form>

            <hr>
            <form method="GET" class="form-row align-items-end">
                <div class="form-group col-md-8 mb-0">
                    <label class="font-weight-bold">Consultar inscripciones por cédula</label>
                    <input type="text" name="cedula" class="form-control" value="<?= pernocta_e($cedula_buscar) ?>"
                           placeholder="Ingrese la cédula y presione Buscar">
                </div>
                <div class="form-group col-md-4 mb-0">
                    <button type="submit" class="btn btn-outline-primary font-weight-bold btn-block">
                        <i class="fas fa-search mr-1"></i> Buscar
                    </button>
                </div>
            </form>
        </div>
    </div>

    <?php if ($cedula_buscar !== ''): ?>
    <div class="card shadow border-0 mx-auto mb-4" style="max-width: 1100px; border-radius: 15px;">
        <div class="card-header py-3 text-white" style="background-color: #ff8a00;">
            <h6 class="m-0 font-weight-bold" style="color: #521f29;">
                <i class="fas fa-list mr-2"></i>Eventos de la cédula V-<?= pernocta_e($cedula_buscar) ?>
            </h6>
        </div>
        <div class="card-body bg-white p-0">
            <?php if (empty($inscritos)): ?>
                <p class="text-muted text-center py-4 mb-0">Esta cédula no tiene inscripciones en foros, conversatorios ni seminarios.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-striped table-bordered mb-0 small align-middle">
                        <thead class="font-weight-bold" style="background-color: #521f29; color: #fff;">
                            <tr><th>Evento</th><th>Tipo</th><th>Inicio</th><th>Final</th>
                                <th>Dormitorio</th><th class="text-center">Malla</th><th class="text-center">Acción</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($inscritos as $in): ?>
                                <tr>
                                    <td class="font-weight-bold">
                                        <?= pernocta_e($in['codigo_foro'] . ' — ' . $in['nombre']) ?>
                                    </td>
                                    <td><?= pernocta_e($in['tipo_foro']) ?></td>
                                    <td><?= pernocta_e($in['fecha_inicio'] ?? '—') ?></td>
                                    <td><?= pernocta_e($in['fecha_fin'] ?? '—') ?></td>
                                    <td><?= pernocta_e($in['dormitorio'] ?: 'Sin cama asignada') ?></td>
                                    <td class="text-center">
                                        <button type="button" class="btn btn-sm btn-outline-primary"
                                                data-toggle="modal" data-target="#temas<?= (int) $in['id_foro'] ?>">
                                            Ver (<?= (int) $in['total_temas'] ?>)
                                        </button>
                                    </td>
                                    <td class="text-center">
                                        <form method="POST" class="mb-0">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id_participante" value="<?= (int) $in['id_participante'] ?>">
                                            <input type="hidden" name="id_foro" value="<?= (int) $in['id_foro'] ?>">
                                            <button type="submit" name="quitar_participante" value="1"
                                                    class="btn btn-sm btn-outline-danger font-weight-bold"
                                                    onclick="return confirm('¿Retirar al participante de este evento?');">
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

    <?php foreach ($inscritos as $in):
        $temas = pernocta_temas_foro($pdo, (int) $in['id_foro']);
    ?>
    <div class="modal fade" id="temas<?= (int) $in['id_foro'] ?>" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header text-white" style="background-color: #521f29;">
                    <h5 class="modal-title font-weight-bold">Malla del evento — <?= pernocta_e($in['nombre']) ?></h5>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body bg-white">
                    <p class="font-weight-bold">Horas académicas: <?= (int) $in['horas_academicas'] ?> h</p>
                    <?php if (empty($temas)): ?>
                        <p class="text-muted mb-0">No hay temas registrados en la malla de este evento.</p>
                    <?php else: foreach ($temas as $t): ?>
                        <div class="border-bottom py-2">
                            <strong><?= pernocta_e($t['nombre_tema']) ?></strong>
                            <span class="badge badge-warning float-right"><?= (int) $t['horas_academicas'] ?> h</span>
                            <div class="small text-muted mt-1">
                                Ponente: <?= pernocta_e($t['ponente'] ?: 'Por asignar') ?>
                            </div>
                            <div class="small text-muted mt-1" style="white-space: pre-line;"><?= pernocta_e($t['contenido']) ?></div>
                        </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>
<?php require_once '../../includes/footer.php'; ?>
