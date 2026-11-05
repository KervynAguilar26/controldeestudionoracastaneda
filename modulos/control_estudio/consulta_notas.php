<?php
// /sistema_nora/modulos/control_estudio/consulta_notas.php
require_once '../../config/db.php';
require_once '../../includes/header.php';

if (!tiene_permiso(['control_estudio', 'admin', 'superadmin', 'profesor'], $roles_array)) {
    echo "<div class='alert alert-danger font-weight-bold'>Acceso denegado a Control de Estudio.</div>";
    require_once '../../includes/footer.php';
    exit();
}

$msg = '';
$err = '';

$id_cohorte_sel = intval($_GET['id_cohorte'] ?? 0);
$id_materia_sel = intval($_GET['id_materia'] ?? 0);

// REGISTRAR / ACTUALIZAR NOTAS
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_notas'])) {
    try {
        $pdo->beginTransaction();
        $id_plan = intval($_POST['id_plan'] ?? 0);

        if ($id_plan <= 0) {
            throw new Exception("Debe seleccionar una evaluación del plan.");
        }

        if (!empty($_POST['notas'])) {
            $sql_nota = "
                INSERT INTO public.notas_estudiantes (id_plan, id_estudiante, nota_cuantitativa, nota_cualitativa, observaciones)
                VALUES (:plan, :est, :nota_num, :nota_txt, :obs)
                ON CONFLICT (id_plan, id_estudiante) 
                DO UPDATE SET nota_cuantitativa = EXCLUDED.nota_cuantitativa,
                              nota_cualitativa = EXCLUDED.nota_cualitativa,
                              observaciones = EXCLUDED.observaciones
            ";
            $stmt_n = $pdo->prepare($sql_nota);

            foreach ($_POST['notas'] as $id_estudiante => $datos_nota) {
                $num = !empty($datos_nota['cuantitativa']) ? floatval($datos_nota['cuantitativa']) : 0.00;
                $txt = ($num >= 10.0) ? 'Aprobado' : 'Reprobado';
                $obs = trim($datos_nota['observacion'] ?? '');

                $stmt_n->execute([
                    ':plan'     => $id_plan,
                    ':est'      => intval($id_estudiante),
                    ':nota_num' => $num,
                    ':nota_txt' => $txt,
                    ':obs'      => $obs
                ]);
            }
        }

        $pdo->commit();
        $msg = "Calificaciones registradas correctamente en la sección.";
    } catch (Exception $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $err = $e->getMessage();
    }
}

// Consultar Cohortes activas
$cohortes = $pdo->query("
    SELECT c.id_cohorte, c.codigo_cohorte, mc.nombre_programa, c.num_periodo, mc.id_malla
    FROM public.cohortes c
    JOIN public.mallas_curriculares mc ON c.id_malla = mc.id_malla
    WHERE c.estatus IN ('Planificada', 'En Curso')
    ORDER BY mc.nombre_programa ASC, c.codigo_cohorte ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Consultar Materias si hay cohorte seleccionada
$materias = [];
if ($id_cohorte_sel > 0) {
    $stmt_m = $pdo->prepare("
        SELECT mm.id_materia, mm.nombre_materia
        FROM public.cohortes c
        JOIN public.malla_materias mm ON mm.id_malla = c.id_malla AND mm.modulo_num = c.num_periodo
        WHERE c.id_cohorte = :cohorte
        ORDER BY mm.nombre_materia ASC
    ");
    $stmt_m->execute([':cohorte' => $id_cohorte_sel]);
    $materias = $stmt_m->fetchAll(PDO::FETCH_ASSOC);
}

// Consultar Estudiantes e Historial de Calificaciones
$estudiantes = [];
$planes_evaluacion = [];

if ($id_cohorte_sel > 0 && $id_materia_sel > 0) {
    // Consultar Plan de Evaluación de esa materia/cohorte
    $stmt_pe = $pdo->prepare("
        SELECT id_plan, titulo_evaluacion, porcentaje_peso 
        FROM public.plan_evaluacion 
        WHERE id_cohorte = :c AND id_materia = :m 
        ORDER BY id_plan ASC
    ");
    $stmt_pe->execute([':c' => $id_cohorte_sel, ':m' => $id_materia_sel]);
    $planes_evaluacion = $stmt_pe->fetchAll(PDO::FETCH_ASSOC);

    // Consultar Estudiantes inscritos
    $stmt_est = $pdo->prepare("
        SELECT u.id_usuario, u.cedula, u.nombre, u.apellido
        FROM public.inscripciones i
        JOIN public.usuarios u ON u.id_usuario = i.id_estudiante
        WHERE i.id_curso = :c
        ORDER BY u.apellido ASC, u.nombre ASC
    ");
    $stmt_est->execute([':c' => $id_cohorte_sel]);
    $estudiantes = $stmt_est->fetchAll(PDO::FETCH_ASSOC);
}
?>

<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <h1 class="h3 mb-0 font-weight-bold" style="color: #521f29;">
        <i class="fas fa-clipboard-check mr-2 text-warning"></i>Carga y Actas de Calificaciones
    </h1>
    <a href="index.php" class="btn btn-sm btn-secondary font-weight-bold"><i class="fas fa-arrow-left mr-1"></i> Volver</a>
</div>

<?php if ($msg): ?><div class="alert alert-success font-weight-bold shadow-sm"><?= $msg ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger font-weight-bold shadow-sm"><?= $err ?></div><?php endif; ?>

<!-- FILTRO DE SECCIÓN Y MATERIA -->
<div class="card shadow border-0 mb-4" style="border-radius: 15px;">
    <div class="card-body bg-white p-4">
        <form method="GET" class="form-row align-items-end">
            <div class="col-md-5 mb-2">
                <label class="font-weight-bold text-dark">1. Seleccionar Sección / Cohorte *</label>
                <select name="id_cohorte" class="form-control font-weight-bold" onchange="this.form.submit()" required>
                    <option value="">-- Seleccionar Sección --</option>
                    <?php foreach ($cohortes as $co): ?>
                        <option value="<?= $co['id_cohorte'] ?>" <?= $id_cohorte_sel === (int)$co['id_cohorte'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($co['nombre_programa']) ?> - <?= htmlspecialchars($co['codigo_cohorte']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-5 mb-2">
                <label class="font-weight-bold text-dark">2. Seleccionar Asignatura *</label>
                <select name="id_materia" class="form-control font-weight-bold" <?= empty($materias) ? 'disabled' : '' ?> onchange="this.form.submit()" required>
                    <option value="">-- Seleccionar Materia --</option>
                    <?php foreach ($materias as $mat): ?>
                        <option value="<?= $mat['id_materia'] ?>" <?= $id_materia_sel === (int)$mat['id_materia'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($mat['nombre_materia']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-2 mb-2">
                <button type="submit" class="btn btn-block font-weight-bold text-white" style="background-color: #521f29;">
                    <i class="fas fa-filter mr-1"></i> CARGAR
                </button>
            </div>
        </form>
    </div>
</div>

<?php if ($id_cohorte_sel > 0 && $id_materia_sel > 0): ?>
    <div class="card shadow border-0 mb-4" style="border-radius: 15px;">
        <div class="card-header text-white font-weight-bold" style="background-color: #521f29;">
            <i class="fas fa-pen-alt mr-2 text-warning"></i>Planilla de Evaluación y Notas
        </div>
        <div class="card-body bg-white p-4">
            <?php if (empty($planes_evaluacion)): ?>
                <div class="alert alert-warning font-weight-bold">
                    <i class="fas fa-exclamation-circle mr-2"></i>No se ha configurado un Plan de Evaluación para esta materia en la sección. Registre evaluaciones previas.
                </div>
            <?php else: ?>
                <form method="POST" action="consulta_notas.php?id_cohorte=<?= $id_cohorte_sel ?>&id_materia=<?= $id_materia_sel ?>">
                    <?php if (function_exists('csrf_field')) { echo csrf_field(); } ?>

                    <div class="form-group mb-4">
                        <label class="font-weight-bold text-dark h6">Seleccionar Evaluación a Calificar:</label>
                        <select name="id_plan" class="form-control font-weight-bold col-md-6" required>
                            <?php foreach ($planes_evaluacion as $pe): ?>
                                <option value="<?= $pe['id_plan'] ?>">
                                    <?= htmlspecialchars($pe['titulo_evaluacion']) ?> (Ponderación: <?= $pe['porcentaje_peso'] ?>%)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead style="background-color: #ff8a00; color: #521f29;">
                                <tr>
                                    <th>Cédula</th>
                                    <th>Estudiante</th>
                                    <th class="text-center" style="width: 180px;">Nota (0 a 20 Pts)</th>
                                    <th>Observación / Estado</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($estudiantes as $e): ?>
                                    <tr>
                                        <td class="font-weight-bold">V-<?= htmlspecialchars($e['cedula']) ?></td>
                                        <td class="font-weight-bold" style="color: #521f29;"><?= htmlspecialchars($e['nombre'] . ' ' . $e['apellido']) ?></td>
                                        <td>
                                            <input type="number" step="0.1" min="0" max="20" name="notas[<?= $e['id_usuario'] ?>][cuantitativa]" class="form-control text-center font-weight-bold" value="0.00" required>
                                        </td>
                                        <td>
                                            <input type="text" name="notas[<?= $e['id_usuario'] ?>][observacion]" class="form-control" placeholder="Ej. Presentó asignación completa">
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <button type="submit" name="guardar_notas" class="btn btn-block font-weight-bold text-white shadow-lg py-3 mt-4" style="background-color: #ff8a00; border-radius: 10px;">
                        <i class="fas fa-save mr-2"></i> GUARDAR Y CONSOLIDAD ACTA DE NOTAS
                    </button>
                </form>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php require_once '../../includes/footer.php'; ?>