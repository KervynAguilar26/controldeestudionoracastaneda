<?php
// /sistema_nora/modulos/profesor/plan_evaluacion.php
require_once '../../config/db.php';
require_once '../../includes/header.php';

$id_cohorte = intval($_GET['id_cohorte'] ?? 0);
$id_materia = intval($_GET['id_materia'] ?? 0);
$id_profesor = $_SESSION['id_usuario'] ?? 0;

// Validar cohorte y materia
$stmt_c = $pdo->prepare("
    SELECT co.id_cohorte, co.codigo_cohorte, co.salon_aula, mm.nombre_materia 
    FROM public.cohortes co
    JOIN public.malla_materias mm ON mm.id_materia = :mat
    WHERE co.id_cohorte = :coh
");
$stmt_c->execute([':coh' => $id_cohorte, ':mat' => $id_materia]);
$info = $stmt_c->fetch(PDO::FETCH_ASSOC);

if (!$info) {
    echo "<div class='alert alert-danger font-weight-bold'>Sección u homóloga de materia no encontrada.</div>";
    require_once '../../includes/footer.php';
    exit();
}

$error = '';

// Procesar Creación / Edición / Eliminación
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['guardar_evaluacion'])) {
        try {
            $titulo = trim($_POST['titulo_evaluacion'] ?? '');
            $tipo_p = ($_POST['tipo_ponderacion'] ?? '') === 'Cualitativa' ? 'Cualitativa' : 'Cuantitativa';
            $peso   = floatval($_POST['porcentaje_peso'] ?? 0);
            $fecha  = $_POST['fecha_evaluacion'] ?? null;

            if (empty($titulo) || $peso <= 0) {
                throw new Exception('Indique el título y una ponderación válida.');
            }

            // Validar que la suma no supere el 100% para ESTA cohorte y ESTA materia específica
            $stmt_s = $pdo->prepare("SELECT COALESCE(SUM(porcentaje_peso), 0) FROM public.plan_evaluacion WHERE id_cohorte = :coh AND id_materia = :mat");
            $stmt_s->execute([':coh' => $id_cohorte, ':mat' => $id_materia]);
            $suma = floatval($stmt_s->fetchColumn());

            if (($suma + $peso) > 100.001) {
                $disponible = round(100.00 - $suma, 2);
                throw new Exception("Suma excede 100%. Porcentaje disponible para esta sección: {$disponible}%.");
            }

            $sql = "INSERT INTO public.plan_evaluacion (id_cohorte, id_materia, titulo_evaluacion, tipo_ponderacion, porcentaje_peso, fecha_evaluacion) 
                    VALUES (:coh, :mat, :tit, :tipo, :peso, :fec)";
            $pdo->prepare($sql)->execute([
                ':coh'  => $id_cohorte,
                ':mat'  => $id_materia,
                ':tit'  => $titulo,
                ':tipo' => $tipo_p,
                ':peso' => $peso,
                ':fec'  => $fecha ?: null
            ]);

            header("Location: plan_evaluacion.php?id_cohorte={$id_cohorte}&id_materia={$id_materia}&msg=ok");
            exit();
        } catch (Exception $e) { $error = $e->getMessage(); }
    }

    if (isset($_POST['eliminar_evaluacion'])) {
        $id_plan = intval($_POST['id_plan'] ?? 0);
        $pdo->prepare("DELETE FROM public.notas_estudiantes WHERE id_plan = :id")->execute([':id' => $id_plan]);
        $pdo->prepare("DELETE FROM public.plan_evaluacion WHERE id_plan = :id AND id_cohorte = :coh")->execute([':id' => $id_plan, ':coh' => $id_cohorte]);
        header("Location: plan_evaluacion.php?id_cohorte={$id_cohorte}&id_materia={$id_materia}&msg=del");
        exit();
    }
}

// Consultar Evaluaciones Autónomas creadas
$stmt_p = $pdo->prepare("SELECT * FROM public.plan_evaluacion WHERE id_cohorte = :coh AND id_materia = :mat ORDER BY id_plan ASC");
$stmt_p->execute([':coh' => $id_cohorte, ':mat' => $id_materia]);
$planes = $stmt_p->fetchAll(PDO::FETCH_ASSOC);

$suma_actual = 0;
foreach ($planes as $pl) { $suma_actual += floatval($pl['porcentaje_peso']); }
$disp = round(100.00 - $suma_actual, 2);
?>

<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <div>
        <h1 class="h3 mb-0 font-weight-bold" style="color: #521f29;">
            <i class="fas fa-tasks mr-2 text-warning"></i>Plan de Evaluación Autónomo
        </h1>
        <span class="text-muted font-weight-bold">
            Materia: <?= htmlspecialchars($info['nombre_materia']) ?> | Sección: <?= htmlspecialchars($info['codigo_cohorte']) ?> (<?= htmlspecialchars($info['salon_aula']) ?>)
        </span>
    </div>
    <a href="index.php" class="btn btn-sm btn-secondary font-weight-bold shadow-sm"><i class="fas fa-arrow-left mr-1"></i> Volver a Mis Materias</a>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger font-weight-bold shadow-sm"><i class="fas fa-exclamation-triangle mr-2"></i><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="row">
    <div class="col-lg-4 mb-4">
        <div class="card shadow border-0" style="border-radius: 15px;">
            <div class="card-header text-white font-weight-bold" style="background-color: #521f29; border-top-left-radius: 15px; border-top-right-radius: 15px;">
                Crear Evaluación para esta Sección
            </div>
            <div class="card-body bg-white p-4">
                <form method="POST" action="plan_evaluacion.php?id_cohorte=<?= $id_cohorte ?>&id_materia=<?= $id_materia ?>">
                    <?php if (function_exists('csrf_field')) { echo csrf_field(); } ?>
                    
                    <div class="form-group mb-2">
                        <label class="font-weight-bold small" style="color: #521f29;">Título de Evaluación *</label>
                        <input type="text" name="titulo_evaluacion" class="form-control font-weight-bold" placeholder="Ej: Exposición del Módulo 1" required>
                    </div>
                    <div class="form-group mb-2">
                        <label class="font-weight-bold small" style="color: #521f29;">Modo Calificación *</label>
                        <select name="tipo_ponderacion" class="form-control font-weight-bold">
                            <option value="Cuantitativa">Cuantitativa (0.0 - 20.0 Pts)</option>
                            <option value="Cualitativa">Cualitativa (Aprobado / Aplazado)</option>
                        </select>
                    </div>
                    <div class="form-group mb-2">
                        <label class="font-weight-bold small" style="color: #521f29;">Porcentaje (%) (Disponible: <?= $disp ?>%) *</label>
                        <input type="number" step="0.01" min="0.01" max="<?= $disp ?>" name="porcentaje_peso" class="form-control font-weight-bold" value="<?= $disp > 0 ? min(25, $disp) : 0 ?>" required <?= $disp <= 0 ? 'disabled' : '' ?>>
                    </div>
                    <div class="form-group mb-3">
                        <label class="font-weight-bold small" style="color: #521f29;">Fecha de Evaluación</label>
                        <input type="date" name="fecha_evaluacion" class="form-control font-weight-bold" value="<?= date('Y-m-d') ?>">
                    </div>

                    <button type="submit" name="guardar_evaluacion" class="btn btn-block font-weight-bold text-white shadow" style="background-color: #ff8a00;" <?= $disp <= 0 ? 'disabled' : '' ?>>
                        <i class="fas fa-save mr-1"></i> GUARDAR EVALUACIÓN
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-8 mb-4">
        <div class="card shadow border-0" style="border-radius: 15px;">
            <div class="card-header text-white font-weight-bold d-flex justify-content-between align-items-center" style="background-color: #521f29; border-top-left-radius: 15px; border-top-right-radius: 15px;">
                <span>Estructura Cargada para la Sección</span>
                <span class="badge badge-warning text-dark font-weight-bold px-3 py-2">Suma: <?= $suma_actual ?>% / 100%</span>
            </div>
            <div class="card-body bg-white p-4">
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead style="background-color: #ff8a00; color: #521f29;">
                            <tr>
                                <th>Evaluación</th>
                                <th>Tipo</th>
                                <th>Ponderación</th>
                                <th>Fecha</th>
                                <th class="text-center">Acción</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($planes)): ?>
                                <tr><td colspan="5" class="text-center text-muted font-weight-bold py-3">No hay evaluaciones configuradas para esta sección.</td></tr>
                            <?php else: ?>
                                <?php foreach ($planes as $pl): ?>
                                    <tr>
                                        <td class="font-weight-bold" style="color: #521f29;"><?= htmlspecialchars($pl['titulo_evaluacion']) ?></td>
                                        <td><?= htmlspecialchars($pl['tipo_ponderacion']) ?></td>
                                        <td class="font-weight-bold text-primary"><?= number_format($pl['porcentaje_peso'], 2) ?>%</td>
                                        <td><?= $pl['fecha_evaluacion'] ? date('d/m/Y', strtotime($pl['fecha_evaluacion'])) : '--' ?></td>
                                        <td class="text-center">
                                            <form method="POST" action="plan_evaluacion.php?id_cohorte=<?= $id_cohorte ?>&id_materia=<?= $id_materia ?>" onsubmit="return confirm('¿Eliminar evaluación?');">
                                                <?php if (function_exists('csrf_field')) { echo csrf_field(); } ?>
                                                <input type="hidden" name="id_plan" value="<?= $pl['id_plan'] ?>">
                                                <button type="submit" name="eliminar_evaluacion" class="btn btn-sm btn-danger font-weight-bold py-0"><i class="fas fa-trash-alt"></i></button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>