<?php
// /sistema_nora/modulos/control_estudio/lista_profesores.php
require_once '../../config/db.php';
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
            $msg = "Cátedra asignada exitosamente a las secciones seleccionadas ({$insertadas} nuevas registradas).";

        } catch (Exception $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
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

// Consultar profesores
$profesores = $pdo->query("
    SELECT id_usuario, cedula, nombre, apellido, correo, telefono 
    FROM public.usuarios 
    WHERE tipo_usuario IN ('profesor', 'admin', 'superadmin') OR 'profesor' = ANY(roles_asignados)
    ORDER BY apellido ASC
")->fetchAll(PDO::FETCH_ASSOC);

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
                                <td class="text-center align-middle">
                                    <button type="button" class="btn btn-sm text-dark font-weight-bold shadow-sm mr-1" style="background-color: #ff8a00;" data-toggle="modal" data-target="#modalAsignar_<?= $p['id_usuario'] ?>">
                                        <i class="fas fa-plus-circle mr-1"></i> Asignar Materia
                                    </button>
                                    <a href="imprimir_horario_profesor.php?id_profesor=<?= $p['id_usuario'] ?>" target="_blank" class="btn btn-sm text-white font-weight-bold shadow-sm" style="background-color: #2b5876;">
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