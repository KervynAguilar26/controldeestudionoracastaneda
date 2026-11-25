<?php
// /sistema_nora/modulos/estudiante/expediente_academico.php
// Vista del estudiante: todos sus cursos, notas certificadas y certificados.

require_once '../../config/db.php';
require_once '../../includes/header.php';
require_once '../../includes/expediente.php';

if (empty($_SESSION['id_usuario'])) {
    echo "<div class='alert alert-danger font-weight-bold'>Debe iniciar sesión para ver su expediente.</div>";
    require_once '../../includes/footer.php';
    exit();
}

$idEstudiante = (int) $_SESSION['id_usuario'];
$exp = expediente_datos($pdo, $idEstudiante);
if (!$exp) {
    echo "<div class='alert alert-danger font-weight-bold'>No se encontró su información académica.</div>";
    require_once '../../includes/footer.php';
    exit();
}

$est = $exp['estudiante'];
$nombre = expediente_nombre($est);
$inscripciones = $exp['inscripciones'];
$certificados = $exp['certificados'];
$foros = expediente_foros($pdo, $idEstudiante);
$totalAprobadas = 0;
$totalMaterias = 0;
foreach ($inscripciones as $i) {
    $totalAprobadas += (int) $i['malla_estado']['aprobadas'];
    $totalMaterias += (int) $i['malla_estado']['total_materias'];
}
?>
<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <div>
        <h1 class="h3 mb-0 font-weight-bold" style="color:#521f29;">
            <i class="fas fa-folder-open mr-2 text-warning"></i>Mi Expediente Académico
        </h1>
        <p class="text-muted mb-0 small mt-1">
            <?= htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8') ?> · Cédula
            <?= htmlspecialchars($est['cedula'], ENT_QUOTES, 'UTF-8') ?>
        </p>
    </div>
    <div>
        <a href="../foros/mis_foros.php" class="btn btn-sm font-weight-bold text-white shadow-sm mr-2" style="background-color:#2b5876; border-radius:8px;">
            <i class="fas fa-comments mr-1"></i> Mis Foros
        </a>
        <a href="../foros/mis_certificados.php" class="btn btn-sm font-weight-bold text-white shadow-sm mr-2" style="background-color:#1e7e34; border-radius:8px;">
            <i class="fas fa-certificate mr-1"></i> Mis Certificados
        </a>
        <a href="mi_aula_virtual.php" class="btn btn-secondary btn-sm font-weight-bold shadow-sm">
            <i class="fas fa-arrow-left mr-1"></i> Mi Aula Virtual
        </a>
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-4">
        <div class="card shadow border-0 text-white" style="background-color:#521f29; border-radius:15px;">
            <div class="card-body text-center py-3">
                <div style="font-size:26px;" class="font-weight-bold"><?= count($inscripciones) + count($foros) ?></div>
                <div class="small">Cursos y Foros / Conversatorios</div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card shadow border-0 text-dark" style="background-color:#ff8a00; border-radius:15px;">
            <div class="card-body text-center py-3">
                <div style="font-size:26px;" class="font-weight-bold"><?= $totalAprobadas ?>/<?= $totalMaterias ?></div>
                <div class="small">Unidades curriculares aprobadas</div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card shadow border-0 text-white" style="background-color:#cd1e3e; border-radius:15px;">
            <div class="card-body text-center py-3">
                <div style="font-size:26px;" class="font-weight-bold"><?= count($certificados) ?></div>
                <div class="small">Certificados otorgados</div>
            </div>
        </div>
    </div>
</div>

<div class="card shadow border-0 mb-4" style="border-radius:15px;">
    <div class="card-header text-white font-weight-bold" style="background-color:#521f29;">
        <i class="fas fa-history mr-2"></i> Trayectoria académica
    </div>
    <div class="card-body bg-white p-0">
        <?php if (!$inscripciones): ?>
            <p class="text-muted p-4 mb-0">Todavía no tiene inscripciones registradas.</p>
        <?php else: ?>
            <?php foreach ($inscripciones as $i): $me = $i['malla_estado']; ?>
                <div class="border-bottom p-4">
                    <div class="d-flex justify-content-between flex-wrap">
                        <div>
                            <h5 class="font-weight-bold mb-1" style="color:#521f29;">
                                <?= htmlspecialchars($i['nombre_programa'], ENT_QUOTES, 'UTF-8') ?>
                            </h5>
                            <div class="small text-muted">
                                Sección: <strong><?= htmlspecialchars($i['codigo_cohorte'], ENT_QUOTES, 'UTF-8') ?></strong>
                                &nbsp;|&nbsp; Período: <?= (int) $i['num_periodo'] ?>
                                &nbsp;|&nbsp; Inicio: <?= htmlspecialchars($i['fecha_inicio'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                                &nbsp;|&nbsp; Tipo: <?= htmlspecialchars($i['tipo_oferta'], ENT_QUOTES, 'UTF-8') ?>
                            </div>
                        </div>
                        <div class="text-right">
                            <span class="badge badge-<?= $me['completado'] ? 'success' : 'warning' ?> px-3 py-2">
                                <?= $me['completado'] ? 'COMPLETADO' : 'EN PROGRESO' ?>
                            </span>
                            <div class="small text-muted mt-1"><?= $me['porcentaje'] ?>% aprobado</div>
                        </div>
                    </div>
                    <div class="progress mt-3" style="height:7px;">
                        <div class="progress-bar" role="progressbar"
                             style="width:<?= (float) $me['porcentaje'] ?>%; background-color:#cd1e3e;"
                             aria-valuenow="<?= (float) $me['porcentaje'] ?>" aria-valuemin="0" aria-valuemax="100"></div>
                    </div>
                    <div class="table-responsive mt-3">
                        <table class="table table-sm table-hover mb-0">
                            <thead class="thead-light">
                                <tr><th>Mód.</th><th>Unidad curricular</th><th>Horas</th><th>Nota certificada</th><th>Estado</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($me['materias'] as $m): ?>
                                    <tr>
                                        <td><?= (int) $m['modulo_num'] ?></td>
                                        <td><?= htmlspecialchars($m['nombre_materia'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><?= (int) $m['horas_academicas'] ?> h</td>
                                        <td class="font-weight-bold"><?= $m['nota_final'] !== null ? number_format((float) $m['nota_final'], 2) : '—' ?></td>
                                        <td>
                                            <span class="badge badge-<?= $m['aprobada'] ? 'success' : ($m['total_eval'] > 0 ? 'danger' : 'secondary') ?>">
                                                <?= htmlspecialchars($m['estado'], ENT_QUOTES, 'UTF-8') ?>
                                            </span>
                                        </td>
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

<!-- FOROS Y CONVERSATORIOS (record académico del estudiante) -->
<div class="card shadow border-0 mb-4" style="border-radius:15px;">
    <div class="card-header text-white font-weight-bold d-flex justify-content-between align-items-center" style="background-color:#2b5876;">
        <span><i class="fas fa-comments mr-2"></i> Foros, Conversatorios y Seminarios</span>
        <a href="../foros/mis_foros.php" class="btn btn-sm btn-light font-weight-bold">Ver detalle</a>
    </div>
    <div class="card-body bg-white p-0">
        <?php if (!$foros): ?>
            <p class="text-muted p-4 mb-0">Todavía no tiene participación registrada en foros, conversatorios ni seminarios.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="thead-light">
                        <tr>
                            <th>Evento</th><th>Tipo</th><th>Fechas</th><th>Asistencias</th>
                            <th>Mesas</th><th>Informes</th><th>Horas</th><th>Certificado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($foros as $f): ?>
                            <tr>
                                <td class="font-weight-bold">
                                    <?= htmlspecialchars($f['nombre'], ENT_QUOTES, 'UTF-8') ?><br>
                                    <small class="text-muted"><?= htmlspecialchars($f['codigo_foro'], ENT_QUOTES, 'UTF-8') ?></small>
                                </td>
                                <td><?= htmlspecialchars($f['tipo_foro'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <?= !empty($f['fecha_inicio']) ? date('d/m/Y', strtotime($f['fecha_inicio'])) : '—' ?>
                                    <?= !empty($f['fecha_fin']) ? ' al ' . date('d/m/Y', strtotime($f['fecha_fin'])) : '' ?>
                                </td>
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

<div class="card shadow border-0" style="border-radius:15px;">
    <div class="card-header text-white font-weight-bold d-flex justify-content-between align-items-center" style="background-color:#521f29;">
        <span><i class="fas fa-award mr-2"></i> Certificados otorgados</span>
        <a href="../foros/mis_certificados.php" class="btn btn-sm btn-light font-weight-bold">Certificados de foros</a>
    </div>
    <div class="card-body bg-white p-0">
        <?php if (!$certificados): ?>
            <p class="text-muted p-4 mb-0">Aún no se le han otorgado certificados. Cuando finalice un curso aparecerán aquí.</p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="thead-light">
                        <tr><th>Folio</th><th>Programa</th><th>Libro</th><th>Línea</th><th>Fecha</th><th>Estado</th><th></th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($certificados as $c): ?>
                            <?php $esForo = ($c['origen'] ?? 'Academico') === 'Foro'; ?>
                            <tr>
                                <td class="font-weight-bold"><?= htmlspecialchars($c['folio'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <?= htmlspecialchars($esForo ? $c['foro_nombre'] : $c['nombre_programa'], ENT_QUOTES, 'UTF-8') ?>
                                    <?php if ($esForo): ?>
                                        <br><small class="badge badge-info"><?= htmlspecialchars($c['rol_certificado'], ENT_QUOTES, 'UTF-8') ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($c['libro'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= (int) $c['linea_libro'] ?></td>
                                <td><?= htmlspecialchars($c['fecha_emision'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <span class="badge badge-<?= strtolower($c['estado']) === 'emitido' ? 'success' : 'danger' ?>">
                                        <?= htmlspecialchars($c['estado'], ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($esForo): ?>
                                        <a href="../foros/certificado_pdf.php?id=<?= (int) $c['id_certificado_foro'] ?>"
                                           class="btn btn-sm btn-outline-primary" title="Descargar certificado de foro">
                                            <i class="fas fa-download mr-1"></i> PDF
                                        </a>
                                    <?php else: ?>
                                        <a href="../../certificado_pdf.php?id=<?= (int) $c['id_inscripcion'] ?>"
                                           class="btn btn-sm btn-outline-primary" title="Descargar certificado">
                                            <i class="fas fa-download mr-1"></i> PDF
                                        </a>
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

<?php require_once '../../includes/footer.php'; ?>
