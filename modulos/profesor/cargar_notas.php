<?php
// /sistema_nora/modulos/profesor/cargar_notas.php
require_once '../../config/db.php';
require_once '../../includes/header.php';

$id_cohorte = intval($_GET['id_cohorte'] ?? 0);
$id_materia = intval($_GET['id_materia'] ?? 0);

function obtenerCualitativa($nota) {
    $nota = floatval($nota);
    if ($nota >= 19.0) return 'Excelente';
    if ($nota >= 16.0) return 'Muy Bueno';
    if ($nota >= 13.0) return 'Bueno';
    if ($nota >= 10.0) return 'Regular';
    return 'Aplazado';
}

// Consultar plan de evaluaciones creadas
$stmt_e = $pdo->prepare("SELECT * FROM public.plan_evaluacion WHERE id_cohorte = :coh AND id_materia = :mat ORDER BY id_plan ASC");
$stmt_e->execute([':coh' => $id_cohorte, ':mat' => $id_materia]);
$evaluaciones = $stmt_e->fetchAll(PDO::FETCH_ASSOC);

$id_plan_sel = intval($_GET['id_plan'] ?? ($evaluaciones[0]['id_plan'] ?? 0));

// Guardar Notas
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_notas'])) {
    try {
        $id_plan = intval($_POST['id_plan'] ?? 0);
        if ($id_plan <= 0 || empty($_POST['notas'])) { throw new Exception('Datos insuficientes.'); }

        $pdo->beginTransaction();
        foreach ($_POST['notas'] as $id_estudiante => $datos) {
            $num = floatval($datos['cuantitativa'] ?? 0);
            $rec = (isset($datos['recuperacion']) && $datos['recuperacion'] !== '') ? floatval($datos['recuperacion']) : null;
            $cual = obtenerCualitativa($rec !== null ? $rec : $num);

            $sql = "INSERT INTO public.notas_estudiantes (id_plan, id_estudiante, nota_cuantitativa, nota_cualitativa, nota_recuperacion) 
                    VALUES (:p, :e, :num, :cual, :rec)
                    ON CONFLICT (id_plan, id_estudiante) DO UPDATE SET 
                        nota_cuantitativa = EXCLUDED.nota_cuantitativa,
                        nota_cualitativa = EXCLUDED.nota_cualitativa,
                        nota_recuperacion = EXCLUDED.nota_recuperacion";
            $pdo->prepare($sql)->execute([':p' => $id_plan, ':e' => $id_estudiante, ':num' => $num, ':cual' => $cual, ':rec' => $rec]);
        }
        $pdo->commit();
        echo "<script>alert('¡Calificaciones guardadas exitosamente!'); window.location.href='cargar_notas.php?id_cohorte={$id_cohorte}&id_materia={$id_materia}&id_plan={$id_plan}';</script>";
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $error = $e->getMessage();
    }
}

// Estudiantes inscritos en la cohorte
$sql_alumnos = "SELECT u.id_usuario, u.cedula, u.nombre, u.apellido, n.nota_cuantitativa, n.nota_recuperacion
                FROM public.inscripciones i
                JOIN public.usuarios u ON i.id_estudiante = u.id_usuario
                LEFT JOIN public.notas_estudiantes n ON n.id_estudiante = u.id_usuario AND n.id_plan = :p
                WHERE i.id_curso = :coh
                ORDER BY u.apellido ASC";
$stmt_a = $pdo->prepare($sql_alumnos);
$stmt_a->execute([':p' => $id_plan_sel, ':coh' => $id_cohorte]);
$alumnos = $stmt_a->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <h1 class="h3 mb-0 font-weight-bold" style="color: #521f29;">
        <i class="fas fa-star mr-2 text-warning"></i>Carga de Calificaciones
    </h1>
    <a href="index.php" class="btn btn-sm btn-secondary font-weight-bold shadow-sm"><i class="fas fa-arrow-left mr-1"></i> Volver</a>
</div>

<div class="card shadow border-0 mb-4" style="border-radius: 15px;">
    <div class="card-header text-white font-weight-bold" style="background-color: #521f29; border-top-left-radius: 15px; border-top-right-radius: 15px;">
        Seleccionar Evaluación
    </div>
    <div class="card-body bg-white p-4">
        <form method="GET" action="cargar_notas.php" class="mb-4">
            <input type="hidden" name="id_cohorte" value="<?= $id_cohorte ?>">
            <input type="hidden" name="id_materia" value="<?= $id_materia ?>">
            <select name="id_plan" class="form-control font-weight-bold" onchange="this.form.submit()">
                <?php foreach ($evaluaciones as $ev): ?>
                    <option value="<?= $ev['id_plan'] ?>" <?= $ev['id_plan'] == $id_plan_sel ? 'selected' : '' ?>>
                        <?= htmlspecialchars($ev['titulo_evaluacion']) ?> (Peso: <?= $ev['porcentaje_peso'] ?>%)
                    </option>
                <?php endforeach; ?>
            </select>
        </form>

        <form method="POST" action="cargar_notas.php?id_cohorte=<?= $id_cohorte ?>&id_materia=<?= $id_materia ?>">
            <?php if (function_exists('csrf_field')) { echo csrf_field(); } ?>
            <input type="hidden" name="id_plan" value="<?= $id_plan_sel ?>">
            
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead style="background-color: #ff8a00; color: #521f29;">
                        <tr>
                            <th>Estudiante / Cédula</th>
                            <th style="width: 25%;">Nota Cuantitativa (0.0 - 20.0)</th>
                            <th style="width: 25%;">Recuperación</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($alumnos as $al): ?>
                            <tr>
                                <td class="font-weight-bold" style="color: #521f29;">
                                    <?= htmlspecialchars($al['nombre'] . ' ' . $al['apellido']) ?><br>
                                    <small class="text-muted">C.I: V-<?= htmlspecialchars($al['cedula']) ?></small>
                                </td>
                                <td>
                                    <input type="number" step="0.1" min="0" max="20" name="notas[<?= $al['id_usuario'] ?>][cuantitativa]" class="form-control font-weight-bold text-center" value="<?= $al['nota_cuantitativa'] ?? '' ?>" placeholder="0.0">
                                </td>
                                <td>
                                    <input type="number" step="0.1" min="0" max="20" name="notas[<?= $al['id_usuario'] ?>][recuperacion]" class="form-control font-weight-bold text-center border-danger" value="<?= $al['nota_recuperacion'] ?? '' ?>" placeholder="0.0">
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <button type="submit" name="guardar_notas" class="btn btn-block font-weight-bold text-white shadow-lg py-3 mt-3" style="background-color: #ff8a00; border-radius: 10px;">
                <i class="fas fa-save mr-2"></i> GUARDAR NOTAS DE LA SECCIÓN
            </button>
        </form>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>