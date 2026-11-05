<?php
// /sistema_nora/modulos/profesor/index.php
require_once '../../config/db.php';
require_once '../../includes/header.php';

// Control de acceso
if (!tiene_permiso(['profesor', 'admin', 'superadmin'], $roles_array)) {
    echo "<div class='alert alert-danger font-weight-bold'>Acceso restringido únicamente a Docentes.</div>";
    require_once '../../includes/footer.php';
    exit();
}

$id_profesor = $_SESSION['id_usuario'] ?? 0;

// Responder Aceptación / Rechazo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['responder_asignacion'])) {
    $id_asig = intval($_POST['id_asignacion']);
    $accion  = $_POST['respuesta'];

    if (in_array($accion, ['Aceptado', 'Rechazado'], true)) {
        $stmt_r = $pdo->prepare("UPDATE public.asignaciones_profesor SET estatus_aceptacion = :est, fecha_respuesta = CURRENT_TIMESTAMP WHERE id_asignacion = :id AND id_profesor = :prof");
        $stmt_r->execute([':est' => $accion, ':id' => $id_asig, ':prof' => $id_profesor]);
    }
}

// 1. Asignaciones Pendientes por Aprobar
$sql_notif = "SELECT ap.*, mm.nombre_materia, mc.nombre_programa 
              FROM public.asignaciones_profesor ap
              JOIN public.malla_materias mm ON ap.id_materia = mm.id_materia
              JOIN public.mallas_curriculares mc ON mm.id_malla = mc.id_malla
              WHERE ap.id_profesor = :prof AND ap.estatus_aceptacion = 'Pendiente'";
$stmt_n = $pdo->prepare($sql_notif);
$stmt_n->execute([':prof' => $id_profesor]);
$notificaciones = $stmt_n->fetchAll(PDO::FETCH_ASSOC);

// 2. Cohortes y Materias Aceptadas que el profesor dicta
$sql_activas = "
    SELECT 
        ap.id_asignacion,
        mm.id_materia,
        mm.nombre_materia,
        mc.nombre_programa,
        co.id_cohorte,
        co.codigo_cohorte,
        co.salon_aula,
        co.dias_clase,
        co.hora_inicio,
        co.hora_fin
    FROM public.asignaciones_profesor ap
    JOIN public.malla_materias mm ON ap.id_materia = mm.id_materia
    JOIN public.mallas_curriculares mc ON mm.id_malla = mc.id_malla
    JOIN public.cohortes co ON co.id_malla = mc.id_malla
    WHERE ap.id_profesor = :prof AND ap.estatus_aceptacion = 'Aceptado'
    ORDER BY co.codigo_cohorte ASC, mm.nombre_materia ASC
";
$stmt_act = $pdo->prepare($sql_activas);
$stmt_act->execute([':prof' => $id_profesor]);
$materias_activas = $stmt_act->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <h1 class="h3 mb-0 font-weight-bold" style="color: #521f29;">
        <i class="fas fa-chalkboard-teacher mr-2 text-warning"></i>Portal Docente — Aulas y Cargas Académicas
    </h1>
    <a href="../control_estudio/imprimir_horario_profesor.php?id_profesor=<?= $id_profesor ?>" target="_blank" class="btn font-weight-bold text-white shadow-sm py-2 px-3" style="background-color: #2b5876; border-radius: 8px;">
        <i class="fas fa-print mr-1"></i> Imprimir Mi Horario Oficial
    </a>
</div>

<!-- NOTIFICACIONES -->
<?php foreach ($notificaciones as $not): ?>
    <div class="alert alert-warning border-left-warning shadow-sm p-4 mb-4" style="border-left: 5px solid #ff8a00 !important;">
        <h5 class="font-weight-bold text-dark mb-1"><i class="fas fa-bell text-danger mr-2"></i>Nueva Cátedra Asignada Pendiente</h5>
        <p class="mb-1"><strong>Materia:</strong> <?= htmlspecialchars($not['nombre_materia']) ?> (<?= htmlspecialchars($not['nombre_programa']) ?>)</p>
        <p class="mb-2"><strong>Nota de Control de Estudio:</strong> <em>"<?= htmlspecialchars($not['justificacion_asignacion']) ?>"</em></p>
        
        <form method="POST" action="index.php" class="d-inline">
            <?php if (function_exists('csrf_field')) { echo csrf_field(); } ?>
            <input type="hidden" name="id_asignacion" value="<?= $not['id_asignacion'] ?>">
            <button type="submit" name="responder_asignacion" value="1" onclick="this.form.respuesta.value='Aceptado'" class="btn btn-success font-weight-bold px-4 mr-2">
                <i class="fas fa-check mr-1"></i> ACEPTAR
            </button>
            <button type="submit" name="responder_asignacion" value="1" onclick="this.form.respuesta.value='Rechazado'" class="btn btn-outline-danger font-weight-bold">
                <i class="fas fa-times mr-1"></i> RECHAZAR
            </button>
            <input type="hidden" name="respuesta" value="">
        </form>
    </div>
<?php endforeach; ?>

<!-- LISTADO DE SECCIONES / COHORTES QUE ATIENDE EL PROFESOR -->
<div class="card shadow border-0 mb-4" style="border-radius: 15px;">
    <div class="card-header text-white font-weight-bold" style="background-color: #521f29; border-top-left-radius: 15px; border-top-right-radius: 15px;">
        <i class="fas fa-door-open mr-2 text-warning"></i>Mis Secciones y Asignaturas Activas
    </div>
    <div class="card-body p-4 bg-white">
        <?php if (empty($materias_activas)): ?>
            <p class="text-center text-muted font-weight-bold py-4">No tiene asignaturas o secciones asignadas en este momento.</p>
        <?php else: ?>
            <div class="row">
                <?php foreach ($materias_activas as $ma): ?>
                    <div class="col-md-6 mb-4">
                        <div class="card border-left-warning shadow-sm h-100 p-3" style="border-left: 5px solid #ff8a00 !important; border-radius: 10px;">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <h5 class="font-weight-bold mb-1" style="color: #521f29;"><?= htmlspecialchars($ma['nombre_materia']) ?></h5>
                                    <span class="text-muted small font-weight-bold d-block"><i class="fas fa-graduation-cap mr-1"></i><?= htmlspecialchars($ma['nombre_programa']) ?></span>
                                </div>
                                <span class="badge badge-warning text-dark font-weight-bold px-3 py-1">
                                    <?= htmlspecialchars($ma['codigo_cohorte']) ?>
                                </span>
                            </div>

                            <div class="bg-light p-2 rounded my-3 border">
                                <small class="d-block text-dark font-weight-bold mb-1">
                                    <i class="fas fa-door-open text-info mr-1"></i> Salón / Aula: <?= htmlspecialchars($ma['salon_aula']) ?>
                                </small>
                                <small class="d-block text-dark font-weight-bold">
                                    <i class="fas fa-clock text-danger mr-1"></i> Horario: <?= htmlspecialchars($ma['dias_clase']) ?>
                                    <?= !empty($ma['hora_inicio']) ? ' ('.date('h:i A', strtotime($ma['hora_inicio'])).' - '.date('h:i A', strtotime($ma['hora_fin'])).')' : '' ?>
                                </small>
                            </div>

                            <div class="d-flex flex-wrap justify-content-between pt-2 border-top">
                                <a href="tomar_asistencia.php?id_cohorte=<?= $ma['id_cohorte'] ?>&id_materia=<?= $ma['id_materia'] ?>" class="btn btn-sm btn-info font-weight-bold mb-1"><i class="fas fa-qrcode mr-1"></i> Asistencia</a>
                                <a href="plan_evaluacion.php?id_cohorte=<?= $ma['id_cohorte'] ?>&id_materia=<?= $ma['id_materia'] ?>" class="btn btn-sm btn-warning text-dark font-weight-bold mb-1"><i class="fas fa-tasks mr-1"></i> Plan Eval. Autónomo</a>
                                <a href="cargar_notas.php?id_cohorte=<?= $ma['id_cohorte'] ?>&id_materia=<?= $ma['id_materia'] ?>" class="btn btn-sm text-white font-weight-bold mb-1" style="background-color: #521f29;"><i class="fas fa-star mr-1"></i> Cargar Calificaciones</a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>