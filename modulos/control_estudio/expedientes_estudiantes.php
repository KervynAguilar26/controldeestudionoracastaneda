<?php
// /sistema_nora/modulos/control_estudio/expedientes_estudiantes.php
// Control de Estudio: consulta el expediente de cualquier estudiante y puede
// certificar su información emitiendo el certificado correspondiente.

require_once '../../config/db.php';
require_once '../../includes/header.php';
require_once '../../includes/expediente.php';
require_once '../../includes/certificado_pdf.php';

if (!tiene_permiso(['control_estudio', 'admin', 'superadmin'], $roles_array)) {
    echo "<div class='alert alert-danger font-weight-bold'>Acceso restringido a Control de Estudio.</div>";
    require_once '../../includes/footer.php';
    exit();
}

$q = trim((string) ($_GET['q'] ?? ''));
$idSeleccionado = (int) ($_GET['id_estudiante'] ?? 0);
$mensaje = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (($_POST['accion'] ?? '') === 'emitir') {
            $idInscripcion = (int) ($_POST['id_inscripcion'] ?? 0);
            $cert = certificado_obtener($pdo, $idInscripcion, (int) $_SESSION['id_usuario']);
            $mensaje = 'Certificado emitido: ' . $cert['folio'] . ' (libro ' . $cert['libro']
                     . ', línea ' . $cert['linea_libro'] . ').';
            $idSeleccionado = (int) ($_POST['id_estudiante'] ?? $idSeleccionado);
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$sql = "SELECT u.id_usuario, u.cedula, u.nombre, u.apellido, u.correo,
                   COUNT(i.id_inscripcion) AS inscripciones
              FROM public.usuarios u
              LEFT JOIN public.inscripciones i ON i.id_estudiante = u.id_usuario
             WHERE (u.tipo_usuario = 'estudiante' OR 'estudiante' = ANY(u.roles_asignados))";
$params = [];
if ($q !== '') {
    $sql .= " AND (u.cedula LIKE :q OR (u.nombre || ' ' || u.apellido) ILIKE :q)";
    $params[':q'] = '%' . $q . '%';
}
$sql .= " GROUP BY u.id_usuario, u.cedula, u.nombre, u.apellido, u.correo
          ORDER BY u.apellido ASC, u.nombre ASC LIMIT 200";
$st = $pdo->prepare($sql);
$st->execute($params);
$estudiantes = $st->fetchAll();

$exp = $idSeleccionado > 0 ? expediente_datos($pdo, $idSeleccionado) : null;
?>
<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <div>
        <h1 class="h3 mb-0 font-weight-bold" style="color:#521f29;">
            <i class="fas fa-user-graduate mr-2 text-warning"></i> Expedientes Académicos
        </h1>
        <p class="text-muted mb-0 small mt-1">Consulte participación, notas certificadas y certificados por estudiante.</p>
    </div>
    <div>
        <a href="../foros/index.php" class="btn btn-sm font-weight-bold text-white shadow-sm mr-2" style="background-color:#2b5876; border-radius:8px;">
            <i class="fas fa-comments mr-1"></i> Foros, Conversatorios y Seminarios
        </a>
        <a href="certificados.php" class="btn btn-sm btn-success font-weight-bold shadow-sm mr-2">
            <i class="fas fa-certificate mr-1"></i> Certificados
        </a>
        <a href="index.php" class="btn btn-sm btn-secondary font-weight-bold shadow-sm">Volver</a>
    </div>
</div>

<?php if ($mensaje): ?><div class="alert alert-success font-weight-bold"><?= htmlspecialchars($mensaje) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger font-weight-bold"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="row">
    <div class="col-lg-4">
        <div class="card shadow border-0 mb-4" style="border-radius:15px;">
            <div class="card-header text-white font-weight-bold" style="background-color:#521f29;">
                <i class="fas fa-search mr-2"></i> Buscar estudiante
            </div>
            <div class="card-body bg-white p-3">
                <form method="GET" class="form-inline">
                    <input type="text" name="q" class="form-control form-control-sm w-75 mr-2"
                           placeholder="Cédula o nombre" value="<?= htmlspecialchars($q) ?>">
                    <button class="btn btn-sm font-weight-bold text-white" style="background-color:#cd1e3e;">Buscar</button>
                </form>
                <hr>
                <div style="max-height:520px; overflow-y:auto;">
                    <?php if (!$estudiantes): ?>
                        <p class="text-muted small mb-0">No se encontraron estudiantes.</p>
                    <?php else: ?>
                        <?php foreach ($estudiantes as $e): ?>
                            <a href="?q=<?= urlencode($q) ?>&id_estudiante=<?= (int) $e['id_usuario'] ?>"
                               class="d-block p-2 rounded text-decoration-none mb-1"
                               style="background-color:<?= (int) $e['id_usuario'] === $idSeleccionado ? '#f3e9dd' : 'transparent' ?>;">
                                <div class="font-weight-bold small" style="color:#521f29;">
                                    <?= htmlspecialchars(trim($e['nombre'] . ' ' . $e['apellido']), ENT_QUOTES, 'UTF-8') ?>
                                </div>
                                <div class="text-muted small">C.I. <?= htmlspecialchars($e['cedula']) ?> · <?= (int) $e['inscripciones'] ?> inscripción(es)</div>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-8">
        <?php if (!$exp): ?>
            <div class="card shadow border-0" style="border-radius:15px;">
                <div class="card-body bg-white text-center py-5 text-muted">
                    <i class="fas fa-user-circle fa-3x mb-3" style="color:#cd1e3e;"></i>
                    <p class="mb-0">Seleccione un estudiante para ver su expediente académico.</p>
                </div>
            </div>
        <?php else:
            $est = $exp['estudiante'];
            $certificados = $exp['certificados'];
            $foros = expediente_foros($pdo, $idSeleccionado);
        ?>
            <div class="card shadow border-0 mb-4" style="border-radius:15px;">
                <div class="card-header text-white font-weight-bold" style="background-color:#521f29;">
                    <i class="fas fa-id-card mr-2"></i> <?= htmlspecialchars(expediente_nombre($est), ENT_QUOTES, 'UTF-8') ?>
                </div>
                <div class="card-body bg-white">
                    <p class="mb-1 small"><strong>Cédula:</strong> <?= htmlspecialchars($est['cedula']) ?></p>
                    <p class="mb-1 small"><strong>Correo:</strong> <?= htmlspecialchars($est['correo'] ?: $est['email'] ?: 'No registrado') ?></p>
                    <p class="mb-0 small"><strong>Inscripciones:</strong> <?= count($exp['inscripciones']) ?>
                        &nbsp;|&nbsp; <strong>Foros:</strong> <?= count($foros) ?>
                        &nbsp;|&nbsp; <strong>Certificados:</strong> <?= count($certificados) ?></p>
                </div>
            </div>

            <div class="card shadow border-0 mb-4" style="border-radius:15px;">
                <div class="card-header text-white font-weight-bold" style="background-color:#521f29;">
                    <i class="fas fa-book mr-2"></i> Cursos, foros, conversatorios y seminarios
                </div>
                <div class="card-body bg-white p-0">
                    <?php if (!$exp['inscripciones']): ?>
                        <p class="text-muted p-4 mb-0">El estudiante no tiene inscripciones registradas.</p>
                    <?php else: ?>
                        <?php foreach ($exp['inscripciones'] as $i): $me = $i['malla_estado']; ?>
                            <div class="border-bottom p-3">
                                <div class="d-flex justify-content-between flex-wrap">
                                    <div>
                                        <h6 class="font-weight-bold mb-1" style="color:#521f29;">
                                            <?= htmlspecialchars($i['nombre_programa'], ENT_QUOTES, 'UTF-8') ?>
                                        </h6>
                                        <div class="small text-muted">
                                            <?= htmlspecialchars($i['codigo_cohorte'], ENT_QUOTES, 'UTF-8') ?> ·
                                            <?= htmlspecialchars($i['tipo_oferta'], ENT_QUOTES, 'UTF-8') ?> ·
                                            <?= htmlspecialchars($i['estatus_cohorte'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <span class="badge badge-<?= $me['completado'] ? 'success' : 'warning' ?>">
                                            <?= $me['aprobadas'] ?>/<?= $me['total_materias'] ?> aprobadas
                                        </span>
                                        <?php if ($me['completado']): ?>
                                            <form method="POST" class="mt-1">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="accion" value="emitir">
                                                <input type="hidden" name="id_inscripcion" value="<?= (int) $i['id_inscripcion'] ?>">
                                                <input type="hidden" name="id_estudiante" value="<?= (int) $idSeleccionado ?>">
                                                <button class="btn btn-sm btn-success font-weight-bold">Certificar</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="table-responsive mt-2">
                                    <table class="table table-sm mb-0">
                                        <thead class="thead-light"><tr><th>Módulo</th><th>Unidad curricular</th><th>Horas</th><th>Nota</th><th>Estado</th></tr></thead>
                                        <tbody>
                                            <?php foreach ($me['materias'] as $m): ?>
                                                <tr>
                                                    <td><?= (int) $m['modulo_num'] ?></td>
                                                    <td><?= htmlspecialchars($m['nombre_materia'], ENT_QUOTES, 'UTF-8') ?></td>
                                                    <td><?= (int) $m['horas_academicas'] ?> h</td>
                                                    <td class="font-weight-bold"><?= $m['nota_final'] !== null ? number_format((float) $m['nota_final'], 2) : '—' ?></td>
                                                    <td><?= htmlspecialchars($m['estado'], ENT_QUOTES, 'UTF-8') ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card shadow border-0 mb-4" style="border-radius:15px;">
                <div class="card-header text-white font-weight-bold d-flex justify-content-between align-items-center" style="background-color:#2b5876;">
                    <span><i class="fas fa-comments mr-2"></i> Foros, Conversatorios y Seminarios</span>
                    <a href="../foros/certificados.php" class="btn btn-sm btn-light font-weight-bold">Administrar certificados</a>
                </div>
                <div class="card-body bg-white p-0">
                    <?php if (!$foros): ?>
                        <p class="text-muted p-4 mb-0">El estudiante no tiene participación registrada en foros, conversatorios ni seminarios.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm mb-0">
                                <thead class="thead-light">
                                    <tr><th>Evento</th><th>Tipo</th><th>Asistencias</th><th>Mesas</th><th>Informes</th><th>Horas</th><th>Certificado</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($foros as $f): ?>
                                        <tr>
                                            <td class="font-weight-bold">
                                                <?= htmlspecialchars($f['nombre'], ENT_QUOTES, 'UTF-8') ?><br>
                                                <small class="text-muted"><?= htmlspecialchars($f['codigo_foro'], ENT_QUOTES, 'UTF-8') ?></small>
                                            </td>
                                            <td><?= htmlspecialchars($f['tipo_foro'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars($f['asistencias'] ?: 'Sin registrar', ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= htmlspecialchars($f['mesas'] ?: '—', ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><?= (int) $f['informes_enviados'] ?></td>
                                            <td><?= (int) $f['horas'] ?> h</td>
                                            <td>
                                                <?php if (!empty($f['folio_certificado'])): ?>
                                                    <span class="badge badge-success"><?= htmlspecialchars($f['rol_certificado'], ENT_QUOTES, 'UTF-8') ?></span>
                                                    <small class="d-block text-muted"><?= htmlspecialchars($f['folio_certificado'], ENT_QUOTES, 'UTF-8') ?></small>
                                                <?php else: ?>
                                                    <span class="badge badge-secondary">Pendiente</span>
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

            <?php if ($certificados): ?>
                <div class="card shadow border-0" style="border-radius:15px;">
                    <div class="card-header text-white font-weight-bold" style="background-color:#521f29;">
                        <i class="fas fa-award mr-2"></i> Certificados emitidos
                    </div>
                    <div class="card-body bg-white p-0">
                        <table class="table table-sm mb-0">
                            <thead class="thead-light"><tr><th>Folio</th><th>Programa</th><th>Libro</th><th>Línea</th><th>Estado</th><th></th></tr></thead>
                            <tbody>
                                <?php foreach ($certificados as $c): ?>
                                    <?php $esForo = ($c['origen'] ?? 'Academico') === 'Foro'; ?>
                                    <tr>
                                        <td class="font-weight-bold"><?= htmlspecialchars($c['folio']) ?></td>
                                        <td>
                                            <?= htmlspecialchars($esForo ? $c['foro_nombre'] : $c['nombre_programa']) ?>
                                            <?php if ($esForo): ?>
                                                <br><small class="badge badge-info"><?= htmlspecialchars($c['rol_certificado']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($c['libro']) ?></td>
                                        <td><?= (int) $c['linea_libro'] ?></td>
                                        <td><?= htmlspecialchars($c['estado']) ?></td>
                                        <td>
                                            <?php if ($esForo): ?>
                                                <a href="../foros/certificado_pdf.php?id=<?= (int) $c['id_certificado_foro'] ?>"
                                                   class="btn btn-sm btn-outline-primary">PDF</a>
                                            <?php else: ?>
                                                <a href="../../certificado_pdf.php?id=<?= (int) $c['id_inscripcion'] ?>"
                                                   class="btn btn-sm btn-outline-primary">PDF</a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>
