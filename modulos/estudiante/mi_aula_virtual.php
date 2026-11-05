<?php
// /sistema_nora/modulos/estudiante/mi_aula_virtual.php
require_once '../../config/db.php';
require_once '../../includes/academico.php';
require_once '../../includes/header.php';

// Verificar que el usuario tenga rol o permisos de estudiante
if (!tiene_permiso(['estudiante', 'admin', 'superadmin'], $roles_array)) {
    echo "<div class='alert alert-danger font-weight-bold'>Acceso restringido únicamente a Estudiantes matriculados.</div>";
    require_once '../../includes/footer.php';
    exit();
}

$id_estudiante = $_SESSION['id_usuario'] ?? 0;

// Cargar información completa del Estudiante
$stmt_usr = $pdo->prepare("SELECT * FROM public.usuarios WHERE id_usuario = :id LIMIT 1");
$stmt_usr->execute([':id' => $id_estudiante]);
$estudiante = $stmt_usr->fetch();

// 1. Cargar las Cohortes / Cursos donde está inscrito el estudiante
$sql_cohortes = "SELECT i.id_inscripcion, i.fecha_inscripcion, i.nota_cualitativa,
                        c.id_cohorte, c.codigo_cohorte, c.salon_aula, c.dias_clase, c.horas_diarias, c.fecha_inicio, c.fecha_fin_estimada, c.estatus as estatus_cohorte,
                        m.id_malla, m.nombre_programa, m.tipo_oferta, m.requiere_pernocta,
                        CONCAT(u.nombre, ' ', u.apellido) as profesor_guia, u.telefono as telefono_profesor
                 FROM public.inscripciones i
                 JOIN public.cohortes c ON i.id_curso = c.id_cohorte
                 JOIN public.mallas_curriculares m ON c.id_malla = m.id_malla
                 LEFT JOIN public.usuarios u ON c.id_profesor_guia = u.id_usuario
                 WHERE i.id_estudiante = :est
                 ORDER BY i.id_inscripcion DESC";

$stmt_coh = $pdo->prepare($sql_cohortes);
$stmt_coh->execute([':est' => $id_estudiante]);
$mis_cohortes = $stmt_coh->fetchAll();

// Estado de cada malla curricular (avance) y certificados de culminación emitidos
$estado_mallas = [];
$certificados_emitidos = [];
foreach ($mis_cohortes as $coh_estado) {
    if (!isset($estado_mallas[$coh_estado['id_malla']])) {
        $estado_mallas[$coh_estado['id_malla']] = academico_estado_malla($pdo, (int) $id_estudiante, (int) $coh_estado['id_malla']);
    }
}
try {
    $stmt_ce = $pdo->prepare("SELECT id_malla, folio FROM public.certificados_emitidos
                               WHERE id_estudiante = :est AND tipo = 'Culminacion'");
    $stmt_ce->execute([':est' => $id_estudiante]);
    foreach ($stmt_ce->fetchAll() as $ce) {
        $certificados_emitidos[$ce['id_malla']] = $ce['folio'];
    }
} catch (Throwable $e) {
    $certificados_emitidos = [];
}

// 2. Cargar Asistencias del Estudiante
$sql_asis = "SELECT id_cohorte, COUNT(*) as total_clases,
                    SUM(CASE WHEN estatus = 'Presente' THEN 1 ELSE 0 END) as asistencias
             FROM public.asistencia_clases
             WHERE id_estudiante = :est
             GROUP BY id_cohorte";
$stmt_a = $pdo->prepare($sql_asis);
$stmt_a->execute([':est' => $id_estudiante]);
$res_asistencia = $stmt_a->fetchAll(PDO::FETCH_ASSOC);

$asistencias_map = [];
foreach ($res_asistencia as $ra) {
    $asistencias_map[$ra['id_cohorte']] = $ra;
}
?>

<!-- ENCABEZADO AULA VIRTUAL -->
<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <div>
        <h1 class="h3 mb-0 font-weight-bold" style="color: #521f29;">
            <i class="fas fa-laptop-code mr-2 text-warning"></i>Mi Aula Virtual y Portal de Estudios
        </h1>
        <p class="text-muted small mb-0 font-weight-bold">Bienvenida(o), <?= htmlspecialchars($estudiante['nombre'] . ' ' . $estudiante['apellido'], ENT_QUOTES, 'UTF-8') ?> | C.I. V-<?= htmlspecialchars($estudiante['cedula'], ENT_QUOTES, 'UTF-8') ?></p>
    </div>
    <div>
        <a href="../../imprimir_carnet.php?cedula=<?= htmlspecialchars($estudiante['cedula'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" class="btn btn-sm font-weight-bold text-white shadow-sm" style="background-color: #521f29;">
            <i class="fas fa-id-card text-warning mr-1"></i> Mi Carnet QR
        </a>
    </div>
</div>

<!-- TARJETAS DE COHORTES E INSCRIPCIONES ACTIVAS -->
<?php if (empty($mis_cohortes)): ?>
    <div class="card shadow border-0 p-5 text-center" style="border-radius: 15px;">
        <i class="fas fa-graduation-cap fa-4x text-muted mb-3"></i>
        <h5 class="font-weight-bold text-dark">No posees inscripciones activas registradas.</h5>
        <p class="text-muted mb-0">Una vez que Control de Estudio apruebe tu preinscripción web, tus programas y materias aparecerán listados en este panel.</p>
    </div>
<?php else: ?>
    <div class="row">
        <?php foreach ($mis_cohortes as $coh): 
            $id_c = $coh['id_cohorte'];
            $tot_clases = $asistencias_map[$id_c]['total_clases'] ?? 0;
            $tot_asist = $asistencias_map[$id_c]['asistencias'] ?? 0;
            $porcentaje_asis = ($tot_clases > 0) ? round(($tot_asist / $tot_clases) * 100) : 100;

            // Cargar materias de la malla para el desglose
            $stmt_mat = $pdo->prepare("SELECT * FROM public.malla_materias WHERE id_malla = :id ORDER BY modulo_num ASC");
            $stmt_mat->execute([':id' => $coh['id_malla']]);
            $materias = $stmt_mat->fetchAll();
        ?>
            <div class="col-lg-12 mb-4">
                <div class="card shadow border-0" style="border-radius: 15px; overflow: hidden;">
                    <!-- ENCABEZADO PROGRAMA -->
                    <div class="card-header py-3 bg-white d-flex flex-wrap justify-content-between align-items-center" style="border-bottom: 2px solid #f2f2f2;">
                        <div>
                            <span class="badge badge-warning text-dark font-weight-bold px-3 py-1 mb-1"><?= htmlspecialchars($coh['tipo_oferta'], ENT_QUOTES, 'UTF-8') ?></span>
                            <h5 class="m-0 font-weight-bold" style="color: #521f29;"><?= htmlspecialchars($coh['nombre_programa'], ENT_QUOTES, 'UTF-8') ?></h5>
                            <small class="text-muted font-weight-bold"><i class="fas fa-layer-group mr-1"></i>Cohorte: <strong><?= htmlspecialchars($coh['codigo_cohorte'], ENT_QUOTES, 'UTF-8') ?></strong> | Salón: <strong><?= htmlspecialchars($coh['salon_aula'], ENT_QUOTES, 'UTF-8') ?></strong></small>
                        </div>
                        <div class="mt-2 mt-sm-0 text-right">
                            <span class="badge badge-success px-3 py-2 font-weight-bold"><i class="fas fa-check-circle mr-1"></i>Estatus: <?= htmlspecialchars($coh['nota_cualitativa'], ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                    </div>

                    <div class="card-body bg-white p-4">
                        <div class="row mb-4">
                            <!-- DETALLES Y HORARIOS -->
                            <div class="col-md-4 mb-3">
                                <div class="p-3 rounded bg-light border h-100">
                                    <h6 class="font-weight-bold text-uppercase small" style="color: #521f29;"><i class="fas fa-clock mr-1 text-warning"></i> Horario de Clases</h6>
                                    <p class="small mb-1 font-weight-bold">Días: <span class="text-dark"><?= htmlspecialchars($coh['dias_clase'], ENT_QUOTES, 'UTF-8') ?></span></p>
                                    <p class="small mb-1 font-weight-bold">Horas diarias: <span class="text-dark"><?= (int)$coh['horas_diarias'] ?> hrs</span></p>
                                    <p class="small mb-0 font-weight-bold">Fecha Inicio: <span class="text-dark"><?= date('d/m/Y', strtotime($coh['fecha_inicio'])) ?></span></p>
                                </div>
                            </div>

                            <!-- PROFESOR GUÍA Y ASISTENCIA -->
                            <div class="col-md-4 mb-3">
                                <div class="p-3 rounded bg-light border h-100">
                                    <h6 class="font-weight-bold text-uppercase small" style="color: #521f29;"><i class="fas fa-chalkboard-teacher mr-1 text-warning"></i> Facilitador / Profesor Guía</h6>
                                    <p class="small mb-1 font-weight-bold">Nombre: <span class="text-dark"><?= htmlspecialchars($coh['profesor_guia'] ?? 'Sin Asignar', ENT_QUOTES, 'UTF-8') ?></span></p>
                                    <p class="small mb-0 font-weight-bold">Contacto: <span class="text-dark"><?= htmlspecialchars($coh['telefono_profesor'] ?? 'S/I', ENT_QUOTES, 'UTF-8') ?></span></p>
                                </div>
                            </div>

                            <!-- DOCUMENTOS DESCARGABLES EN PDF -->
                            <div class="col-md-4 mb-3">
                                <div class="p-3 rounded border h-100 d-flex flex-column justify-content-center" style="background-color: #fcf8f2; border: 1px dashed #ff8a00 !important;">
                                    <h6 class="font-weight-bold text-uppercase small mb-2 text-center" style="color: #521f29;"><i class="fas fa-file-pdf mr-1 text-danger"></i> Documentos Oficiales</h6>
                                    
                                    <a href="imprimir_horario_estudiante.php?id=<?= (int)$coh['id_inscripcion'] ?>" target="_blank" class="btn btn-sm btn-block font-weight-bold text-dark mb-1 shadow-sm" style="background-color: #ff8a00;">
                                        <i class="fas fa-calendar-alt mr-1"></i> Mi Horario de Estudios
                                    </a>

                                    <a href="constancia_estudio.php?id=<?= (int)$coh['id_inscripcion'] ?>" target="_blank" class="btn btn-sm btn-block font-weight-bold text-white mb-1 shadow-sm" style="background-color: #521f29;">
                                        <i class="fas fa-certificate mr-1 text-warning"></i> Constancia de Estudio PDF
                                    </a>

                                    <a href="imprimir_boleta.php?id=<?= (int)$coh['id_inscripcion'] ?>" target="_blank" class="btn btn-sm btn-block btn-outline-danger font-weight-bold mb-1 shadow-sm">
                                        <i class="fas fa-list-ol mr-1"></i> Notas Certificadas (Boleta)
                                    </a>

                                    <?php
                                        $est_malla = $estado_mallas[$coh['id_malla']] ?? null;
                                        $folio_ce  = $certificados_emitidos[$coh['id_malla']] ?? null;
                                    ?>
                                    <?php if ($est_malla && $est_malla['completado']): ?>
                                        <a href="../../certificado_pdf.php?id=<?= (int)$coh['id_inscripcion'] ?>" target="_blank"
                                           class="btn btn-sm btn-block font-weight-bold text-white mb-1 shadow-sm" style="background-color: #1e7e34;">
                                            <i class="fas fa-award mr-1"></i> Certificado de Culminación (PDF)
                                        </a>
                                        <?php if ($folio_ce): ?>
                                            <p class="small text-center text-muted font-weight-bold mb-1" style="font-size:8pt;">
                                                Folio: <span style="color:#521f29;"><?= htmlspecialchars($folio_ce, ENT_QUOTES, 'UTF-8') ?></span>
                                            </p>
                                        <?php endif; ?>
                                    <?php elseif ($est_malla): ?>
                                        <p class="small text-center text-muted font-weight-bold mb-1" style="font-size:8pt;">
                                            <i class="fas fa-hourglass-half mr-1 text-warning"></i>
                                            Malla: <?= (int)$est_malla['aprobadas'] ?>/<?= (int)$est_malla['total_materias'] ?> materias aprobadas
                                        </p>
                                    <?php endif; ?>

                                    <a href="imprimir_malla.php?id_malla=<?= (int)$coh['id_malla'] ?>" target="_blank" class="btn btn-sm btn-block btn-outline-dark font-weight-bold">
                                        <i class="fas fa-book mr-1"></i> Ver Malla Curricular
                                    </a>
                                </div>
                            </div>
                        </div>

                        <!-- CONTENIDO CURRICULAR Y MÓDULOS -->
                        <h6 class="font-weight-bold mb-3" style="color: #521f29;"><i class="fas fa-book-open mr-2 text-warning"></i>Unidades Curriculares del Programa</h6>
                        <div class="table-responsive">
                            <table class="table table-bordered align-middle mb-0">
                                <thead style="background-color: #521f29; color: #ffffff;" class="small text-center">
                                    <tr>
                                        <th style="width: 10%;">Módulo</th>
                                        <th style="width: 35%;">Materia / Unidad</th>
                                        <th style="width: 45%;">Contenido Temático</th>
                                        <th style="width: 10%;">Horas</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($materias as $m): ?>
                                        <tr>
                                            <td class="text-center font-weight-bold"><?= (int)$m['modulo_num'] ?></td>
                                            <td class="font-weight-bold" style="color: #521f29;"><?= htmlspecialchars($m['nombre_materia'], ENT_QUOTES, 'UTF-8') ?></td>
                                            <td><small><?= nl2br(htmlspecialchars($m['contenido_detallado'], ENT_QUOTES, 'UTF-8')) ?></small></td>
                                            <td class="text-center font-weight-bold"><?= (int)$m['horas_academicas'] ?> hrs</td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require_once '../../includes/footer.php'; ?>