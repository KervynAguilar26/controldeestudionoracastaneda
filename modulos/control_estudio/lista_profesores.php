<?php
// /sistema_nora/modulos/control_estudio/lista_profesores.php
require_once '../../config/db.php';
require_once '../../includes/disponibilidad_docente.php';
require_once '../../includes/header.php';

$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // ASIGNAR MATERIA A MÚLTIPLES SECCIONES / COHORTES
    if (isset($_POST['asignar_materia_profesor'])) {
        $id_profesor   = intval($_POST['id_profesor'] ?? 0);
        $id_materia    = intval($_POST['id_materia'] ?? 0);
        $cohortes_sel  = $_POST['id_cohortes'] ?? []; 
        $justificacion = trim($_POST['justificacion'] ?? 'Asignación de Cátedra');

        try {
            if ($id_profesor <= 0 || $id_materia <= 0) {
                throw new Exception('Debe seleccionar el docente y la asignatura.');
            }

            if (empty($cohortes_sel)) {
                throw new Exception('Debe seleccionar al menos una sección o cohorte.');
            }

            // Verificación previa del docente (la carga se calcula al armar el horario)
            $u_prof = docente_datos($pdo, $id_profesor);
            if (!$u_prof) {
                throw new Exception('El docente seleccionado no existe.');
            }
            // La jornada se mide con los bloques del horario de trabajo, no con el
            // total de horas académicas de la asignatura (p. ej. 120 h del curso).

            $pdo->beginTransaction();

            $insertadas = 0;
            foreach ($cohortes_sel as $id_cohorte_val) {
                $id_cohorte = ($id_cohorte_val === 'general' || $id_cohorte_val === '') ? null : intval($id_cohorte_val);

                // Verificar si ya existe exactamente esta combinación (Profesor + Materia + Sección)
                $sql_check = "SELECT id_asignacion FROM public.asignaciones_profesor 
                              WHERE id_profesor = :p AND id_materia = :m AND (id_cohorte = :c OR (:c IS NULL AND id_cohorte IS NULL))";
                $stmt_check = $pdo->prepare($sql_check);
                $stmt_check->execute([':p' => $id_profesor, ':m' => $id_materia, ':c' => $id_cohorte]);
                
                if (!$stmt_check->fetch()) {
                    $sql_ins = "INSERT INTO public.asignaciones_profesor 
                                (id_profesor, id_materia, id_cohorte, justificacion_asignacion, estatus_aceptacion) 
                                VALUES (:p, :m, :c, :j, 'Aceptado')";
                    $stmt_ins = $pdo->prepare($sql_ins);
                    $stmt_ins->execute([
                        ':p' => $id_profesor,
                        ':m' => $id_materia,
                        ':c' => $id_cohorte,
                        ':j' => $justificacion
                    ]);
                    $insertadas++;
                }
            }

            $pdo->commit();
            $msg = "Cátedra asignada exitosamente a las secciones seleccionadas ({$insertadas} nuevas registradas). La carga semanal se rige por los bloques del horario de trabajo, no por el total de horas de la asignatura.";

        } catch (Exception $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $err = $e->getMessage();
        }
    }

    // GUARDAR JORNADA Y DISPONIBILIDAD DEL DOCENTE (botón de la tarjeta)
    if (isset($_POST['guardar_jornada_docente'])) {
        $id_prof = intval($_POST['id_profesor'] ?? 0);
        try {
            if ($id_prof <= 0) {
                throw new Exception('Debe seleccionar un docente válido.');
            }
            $hmin = intval($_POST['horas_semanales_min'] ?? 20);
            $hmax = intval($_POST['horas_semanales_max'] ?? 40);
            if ($hmin < 20 || $hmax > 40 || $hmin > $hmax) {
                throw new Exception('La jornada docente debe estar entre 20 y 40 horas semanales (mínimo ≤ máximo).');
            }

            // No se puede fijar un máximo menor a la carga ya registrada en el horario de trabajo.
            $carga_actual = docente_horas_cargadas($pdo, $id_prof);
            if (($carga_actual['total'] ?? 0) > $hmax) {
                throw new Exception(
                    "No puede establecer un máximo de {$hmax} h: el docente ya tiene "
                    . ($carga_actual['total'] ?? 0) . " h asignadas en su horario de trabajo. Retire bloques o aumente el máximo."
                );
            }

            if (empty($_POST['disponibilidad_docente'])) {
                throw new Exception('No se recibió la jornada a guardar.');
            }

            $disp_json = docente_disponibilidad_json((array) ($_POST['disponibilidad'] ?? []));

            $stmt_up = $pdo->prepare("
                UPDATE public.usuarios
                   SET horas_semanales_min = :hmin,
                       horas_semanales_max = :hmax,
                       disponibilidad_horaria = :disp,
                       espacios_permitidos = NULL
                 WHERE id_usuario = :id
            ");
            $stmt_up->execute([
                ':hmin' => $hmin,
                ':hmax' => $hmax,
                ':disp' => $disp_json === '' ? null : $disp_json,
                ':id'   => $id_prof,
            ]);

            $msg = "Jornada y días/horas del docente actualizados correctamente.";
        } catch (Exception $e) {
            $err = $e->getMessage();
        }
    }

    // RETIRAR ASIGNACIÓN INDIVIDUAL
    if (isset($_POST['eliminar_asignacion'])) {
        $id_asig = intval($_POST['id_asignacion'] ?? 0);
        if ($id_asig > 0) {
            $pdo->prepare("DELETE FROM public.asignaciones_profesor WHERE id_asignacion = :id")->execute([':id' => $id_asig]);
            $msg = "Asignación retirada correctamente.";
        }
    }
}

// Consultar profesores (con jornada y disponibilidad)
$profesores = $pdo->query("
    SELECT id_usuario, cedula, nombre, apellido, correo, telefono,
           horas_semanales_min, horas_semanales_max, disponibilidad_horaria
    FROM public.usuarios 
    WHERE tipo_usuario IN ('profesor', 'admin', 'superadmin') OR 'profesor' = ANY(roles_asignados)
    ORDER BY apellido ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Resumen de carga académica ya asignada en horarios
$carga_docentes = [];
foreach ($profesores as $p_row) {
    $carga_docentes[$p_row['id_usuario']] = docente_horas_cargadas($pdo, intval($p_row['id_usuario']));
}

// Consultar materias incluyendo id_malla para el filtrado dinámico
$materias = $pdo->query("
    SELECT mm.id_materia, mm.nombre_materia, mc.id_malla, mc.nombre_programa
    FROM public.malla_materias mm
    JOIN public.mallas_curriculares mc ON mm.id_malla = mc.id_malla
    ORDER BY mc.nombre_programa ASC, mm.nombre_materia ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Consultar cohortes activas con su respectivo id_malla
$cohortes = $pdo->query("
    SELECT c.id_cohorte, c.codigo_cohorte, c.id_malla, c.num_periodo, c.salon_aula, m.nombre_programa
    FROM public.cohortes c
    JOIN public.mallas_curriculares m ON c.id_malla = m.id_malla
    WHERE c.estatus IN ('Planificada', 'En Curso')
    ORDER BY c.codigo_cohorte ASC
")->fetchAll(PDO::FETCH_ASSOC);

?>

<style>
    .scroll-cohortes-box {
        max-height: 180px;
        overflow-y: auto;
        border: 1px solid #ced4da;
        border-radius: 8px;
        padding: 10px;
        background: #fff;
    }
</style>

<div class="container-fluid pt-3">
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 font-weight-bold" style="color: #521f29;">
            <i class="fas fa-chalkboard-teacher mr-2 text-warning"></i>Nómina y Asignación de Cátedras Multi-Sección
        </h1>
        <a href="index.php" class="btn btn-sm btn-secondary font-weight-bold shadow-sm">
            <i class="fas fa-arrow-left mr-1"></i> Volver a Control de Estudio
        </a>
    </div>

    <?php if (!empty($msg)): ?>
        <div class="alert alert-success font-weight-bold shadow-sm mb-4 alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle mr-2"></i><?= htmlspecialchars($msg) ?>
            <button type="button" class="close" data-dismiss="alert">&times;</button>
        </div>
    <?php endif; ?>

    <?php if (!empty($err)): ?>
        <div class="alert alert-danger font-weight-bold shadow-sm mb-4 alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-triangle mr-2"></i><?= htmlspecialchars($err) ?>
            <button type="button" class="close" data-dismiss="alert">&times;</button>
        </div>
    <?php endif; ?>

    <div class="card shadow border-0 mb-4" style="border-radius: 15px;">
        <div class="card-header py-3" style="background-color: #521f29; border-top-left-radius: 15px; border-top-right-radius: 15px;">
            <h6 class="m-0 font-weight-bold text-white"><i class="fas fa-user-graduate mr-2 text-warning"></i>Carga Académica Docente</h6>
        </div>
        <div class="card-body bg-white p-4">
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead style="background-color: #521f29; color: #fff;">
                        <tr>
                            <th>Cédula</th>
                            <th>Docente</th>
                            <th>Asignaturas Impartidas y Secciones</th>
                            <th class="text-center">Carga Académica</th>
                            <th class="text-center">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($profesores as $p): 
                            $sql_asig = "
                                SELECT ap.id_asignacion, mm.id_materia, mm.nombre_materia, mc.nombre_programa, co.id_cohorte, co.codigo_cohorte, co.salon_aula
                                FROM public.asignaciones_profesor ap
                                JOIN public.malla_materias mm ON ap.id_materia = mm.id_materia
                                JOIN public.mallas_curriculares mc ON mm.id_malla = mc.id_malla
                                LEFT JOIN public.cohortes co ON ap.id_cohorte = co.id_cohorte
                                WHERE ap.id_profesor = :id_p
                                ORDER BY mc.nombre_programa ASC, mm.nombre_materia ASC
                            ";
                            $stmt_a = $pdo->prepare($sql_asig);
                            $stmt_a->execute([':id_p' => $p['id_usuario']]);
                            $asigs = $stmt_a->fetchAll(PDO::FETCH_ASSOC);
                        ?>
                            <tr>
                                <td class="font-weight-bold">V-<?= htmlspecialchars($p['cedula']) ?></td>
                                <td class="font-weight-bold" style="color: #521f29;"><?= htmlspecialchars($p['nombre'] . ' ' . $p['apellido']) ?></td>
                                <td>
                                    <?php if (empty($asigs)): ?>
                                        <span class="text-muted font-italic">Sin carga académica asignada</span>
                                    <?php else: ?>
                                        <ul class="list-unstyled mb-0">
                                            <?php foreach ($asigs as $a): ?>
                                                <li class="mb-2 p-2 bg-light rounded border d-flex justify-content-between align-items-center">
                                                    <span>
                                                        <i class="fas fa-book text-warning mr-1"></i>
                                                        <strong class="text-dark"><?= htmlspecialchars($a['nombre_materia']) ?></strong> 
                                                        <small class="text-muted d-block">
                                                            <i class="fas fa-graduation-cap text-primary mr-1"></i><?= htmlspecialchars($a['nombre_programa']) ?> — 
                                                            <span class="badge badge-warning text-dark font-weight-bold">
                                                                <?= !empty($a['codigo_cohorte']) ? htmlspecialchars($a['codigo_cohorte']) . " (" . htmlspecialchars($a['salon_aula']) . ")" : 'Todas las Secciones / General' ?>
                                                            </span>
                                                        </small>
                                                    </span>
                                                    <form method="POST" action="lista_profesores.php" class="d-inline" onsubmit="return confirm('¿Desea retirar esta asignatura al docente en esta sección?');">
                                                        <?php if (function_exists('csrf_field')) { echo csrf_field(); } ?>
                                                        <input type="hidden" name="id_asignacion" value="<?= $a['id_asignacion'] ?>">
                                                        <button type="submit" name="eliminar_asignacion" class="btn btn-sm btn-outline-danger py-1" title="Retirar Cátedra"><i class="fas fa-times"></i></button>
                                                    </form>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                </td>
                                <?php
                                    $pid_doc = intval($p['id_usuario']);
                                    $carga_doc = $carga_docentes[$pid_doc]['total'] ?? 0;
                                    $maximo_doc = intval($p['horas_semanales_max']);
                                    $minimo_doc = intval($p['horas_semanales_min']);
                                    $excedido_doc = $carga_doc > $maximo_doc;
                                    $porcentaje_doc = $maximo_doc > 0 ? min(100, intval(round($carga_doc / $maximo_doc * 100))) : 0;
                                    $disp_doc = docente_disponibilidad_leer($p['disponibilidad_horaria'] ?? null);
                                ?>
                                <td class="text-center align-middle" style="min-width: 160px;">
                                    <div class="font-weight-bold <?= $excedido_doc ? 'text-danger' : 'text-success' ?>">
                                        <?= $carga_doc ?> / <?= $maximo_doc ?> h
                                        <?php if ($excedido_doc): ?><i class="fas fa-exclamation-triangle ml-1"></i><?php endif; ?>
                                    </div>
                                    <div class="progress mt-1" style="height: 6px;">
                                        <div class="progress-bar <?= $excedido_doc ? 'bg-danger' : 'bg-success' ?>" role="progressbar" style="width: <?= $porcentaje_doc ?>%;"></div>
                                    </div>
                                    <small class="text-muted">Rango: <?= $minimo_doc ?>-<?= $maximo_doc ?> h</small>
                                    <small class="d-block text-muted">
                                        <?= docente_tiene_restriccion($disp_doc) ? count($disp_doc) . ' días disponibles' : 'Sin restricción' ?>
                                    </small>
                                </td>
                                <td class="text-center align-middle" style="min-width: 220px;">
                                    <button type="button" class="btn btn-sm text-white font-weight-bold shadow-sm mr-1 mb-1" style="background-color: #521f29;" data-toggle="modal" data-target="#modalJornada_<?= $p['id_usuario'] ?>">
                                        <i class="fas fa-clock mr-1"></i> Jornada y disponibilidad
                                    </button>
                                    <button type="button" class="btn btn-sm text-dark font-weight-bold shadow-sm mr-1 mb-1" style="background-color: #ff8a00;" data-toggle="modal" data-target="#modalAsignar_<?= $p['id_usuario'] ?>">
                                        <i class="fas fa-plus-circle mr-1"></i> Asignar Materia
                                    </button>
                                    <a href="imprimir_horario_profesor.php?id_profesor=<?= $p['id_usuario'] ?>" target="_blank" class="btn btn-sm text-white font-weight-bold shadow-sm mb-1" style="background-color: #2b5876;">
                                        <i class="fas fa-calendar-alt mr-1"></i> Ver Horario
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- MODALES DE ASIGNACIÓN DINÁMICOS POR MATERIA -->
<?php foreach ($profesores as $p): ?>
    <div class="modal fade" id="modalAsignar_<?= $p['id_usuario'] ?>" tabindex="-1" role="dialog">
        <div class="modal-dialog modal-md modal-dialog-centered" role="document">
            <form method="POST" action="lista_profesores.php" class="modal-content shadow-lg" style="border-radius: 12px;">
                <?php if (function_exists('csrf_field')) { echo csrf_field(); } ?>
                <input type="hidden" name="id_profesor" value="<?= $p['id_usuario'] ?>">
                
                <div class="modal-header text-white" style="background-color: #521f29;">
                    <h5 class="modal-title font-weight-bold">Asignar Materia: <?= htmlspecialchars($p['nombre'] . ' ' . $p['apellido']) ?></h5>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body bg-light p-4">
                    <?php
                        $u_modal = docente_datos($pdo, intval($p['id_usuario']));
                        $disp_modal = $u_modal['disponibilidad'] ?? [];
                        $carga_modal = $carga_docentes[intval($p['id_usuario'])]['total'] ?? 0;
                    ?>
                    <div class="alert alert-info py-2 small mb-3">
                        <i class="fas fa-info-circle mr-1"></i>
                        Jornada: <strong><?= $u_modal['horas_semanales_min'] ?? 20 ?>-<?= $u_modal['horas_semanales_max'] ?? 40 ?> h</strong> |
                        Carga según horario de trabajo: <strong><?= $carga_modal ?> h</strong> |
                        Días disponibles: <strong><?= docente_tiene_restriccion($disp_modal) ? htmlspecialchars(implode(', ', array_keys($disp_modal))) : 'Todos' ?></strong>
                        <?php $disp_horas_modal = docente_horas_disponibles($disp_modal); ?>
                        <?php if ($disp_horas_modal !== null): ?> | Disponibilidad declarada: <strong><?= $disp_horas_modal ?> h semanales</strong><?php endif; ?>
                        <br><i class="fas fa-exclamation-circle mr-1"></i>El total de horas del programa no cuenta en la jornada; solo los bloques efectivamente asignados en el horario.
                    </div>
                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-dark">Materia / Asignatura *</label>
                        <select name="id_materia" id="select_materia_<?= $p['id_usuario'] ?>" class="form-control font-weight-bold select-materia-dinamica" data-userid="<?= $p['id_usuario'] ?>" required>
                            <option value="">-- Seleccionar Materia Primero --</option>
                            <?php $prog_act = ''; foreach ($materias as $m): ?>
                                <?php if ($m['nombre_programa'] !== $prog_act): $prog_act = $m['nombre_programa']; ?>
                                    <optgroup label="<?= htmlspecialchars($prog_act) ?>">
                                <?php endif; ?>
                                <option value="<?= $m['id_materia'] ?>" data-malla="<?= $m['id_malla'] ?>"><?= htmlspecialchars($m['nombre_materia']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-dark mb-1">Seleccionar Secciones / Cohortes:</label>
                        <div id="box_cohortes_<?= $p['id_usuario'] ?>" class="scroll-cohortes-box">
                            <p class="text-muted font-italic m-2 text-center" id="placeholder_msg_<?= $p['id_usuario'] ?>">
                                ⚠️ Debe seleccionar una materia para visualizar sus secciones disponibles.
                            </p>
                            <div class="listado-cohortes-real" style="display: none;">
                                <div class="custom-control custom-checkbox mb-2">
                                    <input type="checkbox" class="custom-control-input" id="chk_gen_<?= $p['id_usuario'] ?>" name="id_cohortes[]" value="general">
                                    <label class="custom-control-label font-weight-bold text-secondary" for="chk_gen_<?= $p['id_usuario'] ?>">🌐 Todas las Secciones / General</label>
                                </div>
                                <hr class="my-1">
                                <?php foreach ($cohortes as $c): ?>
                                    <div class="custom-control custom-checkbox mb-2 item-cohorte-dinamica" data-malla="<?= $c['id_malla'] ?>" style="display: none;">
                                        <input type="checkbox" class="custom-control-input input-cohorte-check" id="chk_coh_<?= $p['id_usuario'] ?>_<?= $c['id_cohorte'] ?>" name="id_cohortes[]" value="<?= $c['id_cohorte'] ?>">
                                        <label class="custom-control-label font-weight-bold text-dark" for="chk_coh_<?= $p['id_usuario'] ?>_<?= $c['id_cohorte'] ?>">
                                            [<?= htmlspecialchars($c['codigo_cohorte']) ?>] — <?= htmlspecialchars($c['nombre_programa']) ?> (Salón: <?= htmlspecialchars($c['salon_aula']) ?>)
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                                <div class="text-danger font-weight-bold small text-center alerta-sin-secciones" style="display: none;">
                                    ❌ No hay secciones creadas para esta carrera/malla curricular.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-white">
                    <button type="button" class="btn btn-secondary font-weight-bold" data-dismiss="modal">Cancelar</button>
                    <button type="submit" name="asignar_materia_profesor" id="btn_guardar_<?= $p['id_usuario'] ?>" class="btn font-weight-bold text-white" style="background-color: #ff8a00;" disabled>GUARDAR ASIGNACIONES</button>
                </div>
            </form>
        </div>
    </div>
<?php endforeach; ?>

<!-- MODALES DE JORNADA Y DÍAS/HORAS POR DOCENTE -->
<?php foreach ($profesores as $p): ?>
    <?php
        $id_jor    = intval($p['id_usuario']);
        $u_jor     = docente_datos($pdo, $id_jor);
        $disp_jor  = docente_disponibilidad_leer($p['disponibilidad_horaria'] ?? null);
        $carga_jor = $carga_docentes[$id_jor]['total'] ?? 0;
        $nombre_jor = htmlspecialchars($p['nombre'] . ' ' . $p['apellido'], ENT_QUOTES, 'UTF-8');
    ?>
    <div class="modal fade" id="modalJornada_<?= $id_jor ?>" tabindex="-1" role="dialog" aria-labelledby="tituloJornada_<?= $id_jor ?>">
        <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
            <form method="POST" action="lista_profesores.php" class="modal-content shadow-lg" style="border-radius: 12px;">
                <?php if (function_exists('csrf_field')) { echo csrf_field(); } ?>
                <input type="hidden" name="guardar_jornada_docente" value="1">
                <input type="hidden" name="id_profesor" value="<?= $id_jor ?>">
                <input type="hidden" name="disponibilidad_docente" value="1">

                <div class="modal-header text-white" style="background-color: #521f29;">
                    <h5 class="modal-title font-weight-bold" id="tituloJornada_<?= $id_jor ?>">
                        <i class="fas fa-clock mr-2"></i>Jornada y disponibilidad: <?= $nombre_jor ?>
                    </h5>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>

                <div class="modal-body bg-light p-4">
                    <div class="alert alert-info py-2 small mb-3">
                        <i class="fas fa-info-circle mr-1"></i>
                        Carga actual según horario de trabajo: <strong><?= $carga_jor ?> h</strong> |
                        Rango guardado: <strong><?= intval($u_jor['horas_semanales_min'] ?? 20) ?>-<?= intval($u_jor['horas_semanales_max'] ?? 40) ?> h</strong>.
                        El sistema impedirá asignar clases fuera de los días y horas marcados, evitará choques de aula y no superará el máximo semanal. Las horas totales de cada asignatura no se contabilizan aquí.
                    </div>

                    <div class="form-row mb-3">
                        <div class="col-md-6 form-group mb-2">
                            <label class="font-weight-bold small" style="color:#521f29;">Horas mínimas semanales de trabajo *</label>
                            <input type="number" name="horas_semanales_min" min="20" max="40" step="1" class="form-control font-weight-bold" value="<?= intval($u_jor['horas_semanales_min'] ?? 20) ?>" required>
                        </div>
                        <div class="col-md-6 form-group mb-2">
                            <label class="font-weight-bold small" style="color:#521f29;">Horas máximas semanales de trabajo *</label>
                            <input type="number" name="horas_semanales_max" min="20" max="40" step="1" class="form-control font-weight-bold" value="<?= intval($u_jor['horas_semanales_max'] ?? 40) ?>" required>
                        </div>
                    </div>

                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h6 class="m-0 font-weight-bold" style="color:#521f29;">
                            <i class="fas fa-calendar-week mr-2 text-warning"></i>Días y horas en que puede impartir clases
                        </h6>
                        <div>
                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="document.querySelectorAll('#tabla-jornada-<?= $id_jor ?> input[type=checkbox]').forEach(c => { if (!c.disabled) c.checked = true; });">Marcar todo</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="document.querySelectorAll('#tabla-jornada-<?= $id_jor ?> input[type=checkbox]').forEach(c => c.checked = false);">Limpiar</button>
                        </div>
                    </div>
                    <p class="small text-muted mb-2">
                        Marque cada bloque en el que el docente puede estar en la escuela. Si no marca ninguno, se interpreta que está disponible en todos los bloques.
                    </p>
                    <div class="table-responsive mb-4">
                        <table id="tabla-jornada-<?= $id_jor ?>" class="table table-sm table-bordered text-center mb-0">
                            <thead style="background-color:#521f29;color:#fff;">
                                <tr>
                                    <th class="text-left">Bloque / Hora</th>
                                    <?php foreach (docente_dias() as $dia): ?><th><?= htmlspecialchars($dia, ENT_QUOTES, 'UTF-8') ?></th><?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (docente_bloques() as $b): ?>
                                    <tr>
                                        <td class="text-left font-weight-bold small <?= !$b['clase'] ? 'text-muted' : '' ?>">
                                            <?= htmlspecialchars($b['etiqueta'], ENT_QUOTES, 'UTF-8') ?>
                                            <?php if (!$b['clase']): ?><span class="badge badge-light">No impartible</span><?php endif; ?>
                                        </td>
                                        <?php foreach (docente_dias() as $dia):
                                            $marcado = isset($disp_jor[$dia]) && in_array($b['inicio'], $disp_jor[$dia], true);
                                        ?>
                                            <td>
                                                <input type="checkbox" class="form-check-input"
                                                       name="disponibilidad[<?= htmlspecialchars($dia, ENT_QUOTES, 'UTF-8') ?>][]"
                                                       value="<?= htmlspecialchars($b['inicio'], ENT_QUOTES, 'UTF-8') ?>"
                                                       <?= $marcado ? 'checked' : '' ?>
                                                       <?= !$b['clase'] ? 'disabled' : '' ?>>
                                            </td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="modal-footer bg-white">
                    <button type="button" class="btn btn-secondary font-weight-bold" data-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn font-weight-bold text-white" style="background-color: #ff8a00;">
                        <i class="fas fa-save mr-1"></i> GUARDAR JORNADA
                    </button>
                </div>
            </form>
        </div>
    </div>
<?php endforeach; ?>

<script>
document.addEventListener("DOMContentLoaded", function() {
    // Filtrado dinámico de secciones según la materia seleccionada en cada modal
    document.querySelectorAll('.select-materia-dinamica').forEach(function(selectMateria) {
        selectMateria.addEventListener('change', function() {
            let userId = this.getAttribute('data-userid');
            let selectedOption = this.options[this.selectedIndex];
            let mallaId = selectedOption.getAttribute('data-malla');

            let placeholder = document.getElementById(`placeholder_msg_${userId}`);
            let containerReal = document.querySelector(`#box_cohortes_${userId} .listado-cohortes-real`);
            let btnGuardar = document.getElementById(`btn_guardar_${userId}`);
            let itemsCohorte = document.querySelectorAll(`#box_cohortes_${userId} .item-cohorte-dinamica`);
            let alertaSinSecc = document.querySelector(`#box_cohortes_${userId} .alerta-sin-secciones`);

            if (!this.value) {
                // Si deselecciona la materia
                placeholder.style.display = 'block';
                containerReal.style.display = 'none';
                btnGuardar.disabled = true;
                // Desmarcar todos los checkboxes
                document.querySelectorAll(`#box_cohortes_${userId} input[type="checkbox"]`).forEach(chk => chk.checked = false);
                return;
            }

            // Mostrar caja de cohortes
            placeholder.style.display = 'none';
            containerReal.style.display = 'block';
            btnGuardar.disabled = false;

            let seccionesEncontradas = 0;
            itemsCohorte.forEach(function(item) {
                let cohorteMalla = item.getAttribute('data-malla');
                let checkbox = item.querySelector('input[type="checkbox"]');

                if (cohorteMalla === mallaId) {
                    item.style.display = 'block';
                    seccionesEncontradas++;
                } else {
                    item.style.display = 'none';
                    checkbox.checked = false; // Desmarcar si estaba seleccionada de otra materia
                }
            });

            // Mostrar alerta si la carrera no tiene secciones registradas
            if (seccionesEncontradas === 0) {
                alertaSinSecc.style.display = 'block';
            } else {
                alertaSinSecc.style.display = 'none';
            }
        });
    });
});
</script>

<?php require_once '../../includes/footer.php'; ?>