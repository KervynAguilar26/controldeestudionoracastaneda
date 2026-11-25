<?php
// /sistema_nora/modulos/profesor/index.php
require_once '../../config/db.php';
require_once '../../includes/header.php';
require_once '../../includes/permisos_docente.php';
require_once '../../includes/disponibilidad_docente.php';

// Control de acceso
if (!tiene_permiso(['profesor', 'admin', 'superadmin', 'control_estudio'], $roles_array)) {
    echo "<div class='alert alert-danger font-weight-bold'>Acceso restringido únicamente a Docentes y Control de Estudio.</div>";
    require_once '../../includes/footer.php';
    exit();
}

$id_profesor = (int) ($_SESSION['id_usuario'] ?? 0);

// Responder Aceptación / Rechazo (bloque conservado)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['responder_asignacion'])) {
    $id_asig = intval($_POST['id_asignacion'] ?? 0);
    $accion  = $_POST['respuesta'] ?? '';

    if ($id_asig > 0 && in_array($accion, ['Aceptado', 'Rechazado'], true)) {
        try {
            $stmt_r = $pdo->prepare("UPDATE public.asignaciones_profesor SET estatus_aceptacion = :est, fecha_respuesta = CURRENT_TIMESTAMP WHERE id_asignacion = :id AND id_profesor = :prof");
            $stmt_r->execute([':est' => $accion, ':id' => $id_asig, ':prof' => $id_profesor]);
            docente_registrar_bitacora($pdo, 'ASIGNACION_' . ($accion === 'Aceptado' ? 'ACEPTAR' : 'RECHAZAR') . " id_asignacion:{$id_asig}");
        } catch (Throwable $e) {
            $error_asignacion = 'No se pudo registrar la respuesta de la asignación.';
        }
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

// 2. Cargas académicas aceptadas (cohortes resueltas desde la malla)
$materias_activas = docente_materias_asignadas($pdo, $id_profesor);

// 3. Horario semanal con espacios, para que el docente lo vea directamente
$horario_docente = docente_horario_detalle($pdo, $id_profesor);
$datos_docente = docente_datos($pdo, $id_profesor);
$disponibilidad_docente = $datos_docente['disponibilidad'] ?? [];
$dias_horario = docente_dias();
$bloques_horario = docente_bloques();

// Resumen legible del contenido programático de cada módulo
$resumen_contenido = static function ($texto, $limite = 190) {
    $texto = trim(strip_tags((string) $texto));
    $texto = preg_replace('/\s+/u', ' ', $texto);
    if (function_exists('mb_substr')) {
        return mb_substr($texto, 0, $limite, 'UTF-8')
             . (mb_strlen($texto, 'UTF-8') > $limite ? '…' : '');
    }
    return substr($texto, 0, $limite) . (strlen($texto) > $limite ? '…' : '');
};
?>

<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <div>
        <h1 class="h3 mb-0 font-weight-bold" style="color: #521f29;">
            <i class="fas fa-chalkboard-teacher mr-2 text-warning"></i>Portal Docente — Aulas y Cargas Académicas
        </h1>
        <div class="small font-weight-bold text-muted mt-1">
            <i class="fas fa-clock mr-1 text-warning"></i> Carga semanal:
            <strong><?= intval($horario_docente['horas']) ?> / <?= intval($datos_docente['horas_semanales_max'] ?? 40) ?> h</strong>
        </div>
    </div>
    <div class="d-flex flex-wrap mt-2 mt-sm-0">
        <button type="button" id="btnToggleHorario" class="btn btn-warning text-dark font-weight-bold shadow-sm mr-2 mb-2" style="border-radius: 8px;" data-toggle="collapse" data-target="#mi-horario" aria-expanded="false" aria-controls="mi-horario">
            <i class="fas fa-calendar-week mr-1"></i> <span id="txtToggleHorario">Ver mi horario</span>
        </button>
        <a href="#mis-secciones" class="btn btn-outline-secondary font-weight-bold shadow-sm mr-2 mb-2" style="border-radius: 8px;">
            <i class="fas fa-door-open mr-1"></i> Mis secciones
        </a>
        <a href="../foros/mis_foros.php" class="btn btn-outline-primary font-weight-bold shadow-sm mr-2 mb-2" style="border-radius: 8px;">
            <i class="fas fa-comments mr-1"></i> Mis foros
        </a>
        <a href="../control_estudio/catalogo_modulos.php" class="btn btn-outline-info font-weight-bold shadow-sm mr-2 mb-2" style="border-radius: 8px;">
            <i class="fas fa-th-list mr-1"></i> Catálogo de módulos
        </a>
        <a href="../control_estudio/imprimir_horario_profesor.php?id_profesor=<?= $id_profesor ?>" target="_blank" class="btn btn-outline-dark font-weight-bold shadow-sm mb-2" style="border-radius: 8px;">
            <i class="fas fa-print mr-1"></i> Imprimir horario
        </a>
    </div>
</div>

<!-- MI HORARIO SEMANAL CON ESPACIOS (oculto hasta presionar el botón) -->
<div class="card shadow border-0 mb-4 collapse" id="mi-horario" style="border-radius: 15px;">
    <div class="card-header text-white font-weight-bold d-flex justify-content-between align-items-center" style="background-color: #521f29;">
        <span><i class="fas fa-calendar-week mr-2 text-warning"></i>Mi Horario Semanal y Espacios</span>
        <button type="button" class="close text-white" data-toggle="collapse" data-target="#mi-horario" aria-label="Ocultar horario">
            <span aria-hidden="true">&times;</span>
        </button>
    </div>
    <div class="card-body p-3 bg-white">
        <?php if (empty($horario_docente['registros'])): ?>
            <p class="text-center text-muted font-weight-bold py-3 mb-0">
                Aún no tiene clases cargadas. Cuando Control de Estudio cargue el horario, verá aquí el día, la hora y el espacio de cada clase.
            </p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-bordered text-center mb-0" style="font-size: 0.72rem;">
                    <thead style="background-color:#521f29;color:#fff;">
                        <tr>
                            <th class="text-left">Bloque</th>
                            <?php foreach ($dias_horario as $dia): ?><th><?= htmlspecialchars($dia) ?></th><?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bloques_horario as $bloque): ?>
                            <tr>
                                <td class="text-left font-weight-bold small <?= !$bloque['clase'] ? 'text-muted' : '' ?>">
                                    <?= htmlspecialchars($bloque['etiqueta']) ?>
                                </td>
                                <?php foreach ($dias_horario as $dia):
                                    $clase = $horario_docente['matriz'][$dia][$bloque['inicio']] ?? null;
                                    $fuera_disponibilidad = docente_tiene_restriccion($disponibilidad_docente)
                                        && !docente_esta_disponible($disponibilidad_docente, $dia, $bloque['inicio']);
                                ?>
                                    <td>
                                        <?php if ($clase): ?>
                                            <strong class="d-block" style="color:#521f29;"><?= htmlspecialchars($clase['materia']) ?></strong>
                                            <small class="text-muted d-block"><?= htmlspecialchars($clase['seccion'] !== '' ? $clase['seccion'] : '—') ?></small>
                                            <span class="badge badge-dark py-0 px-1" style="font-size:0.6rem;">
                                                ESPACIO: <?= htmlspecialchars($clase['salon'] !== '' ? $clase['salon'] : 'Por asignar') ?>
                                            </span>
                                        <?php elseif (!$bloque['clase']): ?>
                                            <span class="text-muted">-</span>
                                        <?php elseif ($fuera_disponibilidad): ?>
                                            <span class="text-muted">No disp.</span>
                                        <?php else: ?>
                                            <span class="text-muted">Libre</span>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="small text-muted mt-2 mb-0">
                “No disp.” = bloque fuera de su jornada registrada. El espacio/aula de cada clase aparece en la etiqueta oscura.
            </p>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($error_asignacion)): ?>
    <div class="alert alert-danger font-weight-bold"><?= htmlspecialchars($error_asignacion) ?></div>
<?php endif; ?>

<!-- NOTIFICACIONES -->
<?php foreach ($notificaciones as $not): ?>
    <div class="alert alert-warning border-left-warning shadow-sm p-4 mb-4" style="border-left: 5px solid #ff8a00 !important;">
        <h5 class="font-weight-bold text-dark mb-1"><i class="fas fa-bell text-danger mr-2"></i>Nueva Cátedra Asignada Pendiente</h5>
        <p class="mb-1"><strong>Materia:</strong> <?= htmlspecialchars($not['nombre_materia']) ?> (<?= htmlspecialchars($not['nombre_programa']) ?>)</p>
        <p class="mb-2"><strong>Nota de Control de Estudio:</strong> <em>"<?= htmlspecialchars($not['justificacion_asignacion']) ?>"</em></p>

        <form method="POST" action="index.php" class="d-inline">
            <?= csrf_field() ?>
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

<!-- LISTADO DE SECCIONES / COHORTES QUE ATIENDE EL DOCENTE -->
<div class="card shadow border-0 mb-4" id="mis-secciones" style="border-radius: 15px; scroll-margin-top: 90px;">
    <div class="card-header text-white font-weight-bold" style="background-color: #521f29; border-top-left-radius: 15px; border-top-right-radius: 15px;">
        <i class="fas fa-door-open mr-2 text-warning"></i>Mis Secciones y Asignaturas Activas
    </div>
    <div class="card-body p-4 bg-white">
        <?php if (empty($materias_activas)): ?>
            <p class="text-center text-muted font-weight-bold py-4">No tiene asignaturas o secciones asignadas en este momento.</p>
        <?php else: ?>
            <div class="row">
                <?php foreach ($materias_activas as $ma): ?>
                    <?php
                        $es_general = !empty($ma['es_todas_secciones']) || empty($ma['id_cohorte']);
                        $cohorte_id = (int) ($ma['id_cohorte'] ?? 0);
                        $etiqueta_cohorte = $es_general
                            ? 'Todas las secciones de la malla'
                            : ('Cohorte ' . htmlspecialchars($ma['codigo_cohorte'] ?? ('#' . $cohorte_id)));
                    ?>
                    <div class="col-md-6 mb-4">
                        <div class="card border-left-warning shadow-sm h-100 p-3" style="border-left: 5px solid #ff8a00 !important; border-radius: 10px;">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <span class="badge badge-info text-white font-weight-bold px-2 py-1 mb-1">
                                        MÓDULO <?= htmlspecialchars($ma['modulo_num'] ?? '—') ?>
                                    </span>
                                    <h5 class="font-weight-bold mb-1" style="color: #521f29;"><?= htmlspecialchars($ma['nombre_materia']) ?></h5>
                                    <span class="text-muted small font-weight-bold d-block">
                                        <i class="fas fa-graduation-cap mr-1"></i><?= htmlspecialchars($ma['nombre_programa']) ?>
                                        <?php if (!empty($ma['tipo_oferta'])): ?> · <?= htmlspecialchars($ma['tipo_oferta']) ?><?php endif; ?>
                                    </span>
                                    <span class="text-muted small font-weight-bold d-block mt-1">
                                        <i class="fas fa-clock mr-1"></i>Horas del programa: <?= htmlspecialchars($ma['horas_academicas']) ?> (informativas)
                                    </span>
                                </div>
                                <span class="badge badge-warning text-dark font-weight-bold px-3 py-1">
                                    <?= $etiqueta_cohorte ?>
                                </span>
                            </div>

                            <?php if (!empty($ma['contenido_detallado'])): ?>
                                <p class="small text-muted mb-2 mt-2" title="<?= htmlspecialchars(strip_tags($ma['contenido_detallado'])) ?>">
                                    <i class="fas fa-list-alt mr-1"></i><?= htmlspecialchars($resumen_contenido($ma['contenido_detallado'])) ?>
                                </p>
                            <?php endif; ?>

                            <div class="bg-light p-2 rounded my-3 border">
                                <small class="d-block text-dark font-weight-bold mb-1">
                                    <i class="fas fa-door-open text-info mr-1"></i> Salón / Aula: <?= htmlspecialchars($ma['salon_aula'] ?? '—') ?>
                                </small>
                                <small class="d-block text-dark font-weight-bold mb-1">
                                    <i class="fas fa-clock text-danger mr-1"></i> Horario: <?= htmlspecialchars($ma['dias_clase'] ?? '—') ?>
                                    <?= !empty($ma['hora_inicio']) ? ' (' . date('h:i A', strtotime($ma['hora_inicio'])) . ' - ' . date('h:i A', strtotime($ma['hora_fin'])) . ')' : '' ?>
                                </small>
                                <?php if ($es_general && !empty($ma['codigo_cohorte'])): ?>
                                    <small class="d-block text-dark font-weight-bold mb-1">
                                        <i class="fas fa-layer-group mr-1"></i> Sección resuelta: <?= htmlspecialchars($ma['codigo_cohorte']) ?>
                                        <?php if (!empty($ma['num_periodo'])): ?> · Período <?= htmlspecialchars($ma['num_periodo']) ?><?php endif; ?>
                                    </small>
                                <?php endif; ?>
                                <small class="d-block font-weight-bold">
                                    <i class="fas fa-check-circle mr-1"></i> Estatus:
                                    <span class="<?= $ma['estatus_aceptacion'] === 'Aceptado' ? 'text-success' : 'text-warning' ?>">
                                        <?= htmlspecialchars($ma['estatus_aceptacion']) ?>
                                    </span>
                                </small>
                            </div>

                            <?php if ($cohorte_id > 0): ?>
                                <div class="d-flex flex-wrap justify-content-between pt-2 border-top">
                                    <a href="tomar_asistencia.php?id_cohorte=<?= $cohorte_id ?>&id_materia=<?= (int) $ma['id_materia'] ?>" class="btn btn-sm btn-info font-weight-bold mb-1"><i class="fas fa-qrcode mr-1"></i> Asistencia</a>
                                    <a href="plan_evaluacion.php?id_cohorte=<?= $cohorte_id ?>&id_materia=<?= (int) $ma['id_materia'] ?>" class="btn btn-sm btn-warning text-dark font-weight-bold mb-1"><i class="fas fa-tasks mr-1"></i> Plan de Evaluación</a>
                                    <a href="cargar_notas.php?id_cohorte=<?= $cohorte_id ?>&id_materia=<?= (int) $ma['id_materia'] ?>" class="btn btn-sm text-white font-weight-bold mb-1" style="background-color: #521f29;"><i class="fas fa-star mr-1"></i> Cargar Calificaciones</a>
                                    <a href="sabana_notas.php?id_cohorte=<?= $cohorte_id ?>&id_materia=<?= (int) $ma['id_materia'] ?>" class="btn btn-sm font-weight-bold mb-1" style="background-color: #ff8a00; color: #521f29;"><i class="fas fa-table mr-1"></i> Sábana de notas</a>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-secondary small font-weight-bold mb-0 py-2">
                                    Esta malla aún no tiene secciones creadas; no se pueden abrir herramientas de notas.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
(function () {
    var btn = document.getElementById('btnToggleHorario');
    var panel = document.getElementById('mi-horario');
    if (!btn || !panel) return;

    var label = document.getElementById('txtToggleHorario');

    // Si se entra desde "Mi Horario (presione el botón)", se despliega y se enfoca.
    window.addEventListener('load', function () {
        if (window.jQuery) {
            window.jQuery(panel)
                .on('shown.bs.collapse', function () {
                    if (label) { label.textContent = 'Ocultar mi horario'; }
                    btn.setAttribute('aria-expanded', 'true');
                })
                .on('hidden.bs.collapse', function () {
                    if (label) { label.textContent = 'Ver mi horario'; }
                    btn.setAttribute('aria-expanded', 'false');
                });
        }

        if (location.hash === '#mi-horario' && !panel.classList.contains('show')) {
            btn.click();
            window.setTimeout(function () {
                panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }, 120);
        }
    });
})();
</script>

<?php require_once '../../includes/footer.php'; ?>
