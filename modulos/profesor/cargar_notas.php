<?php
// /sistema_nora/modulos/profesor/cargar_notas.php
require_once '../../config/db.php';
require_once '../../includes/header.php';
require_once '../../includes/permisos_docente.php';

// Control de acceso
if (!tiene_permiso(['profesor', 'admin', 'superadmin', 'control_estudio'], $roles_array)) {
    echo "<div class='alert alert-danger font-weight-bold'>Acceso restringido únicamente a Docentes y Control de Estudio.</div>";
    require_once '../../includes/footer.php';
    exit();
}

$id_cohorte  = intval($_GET['id_cohorte'] ?? 0);
$id_materia  = intval($_GET['id_materia'] ?? 0);
$id_profesor = (int) ($_SESSION['id_usuario'] ?? 0);
$error = '';

if (!function_exists('obtenerCualitativa')) {
    function obtenerCualitativa($nota)
    {
        $nota = floatval($nota);
        if ($nota >= 19.0) return 'Excelente';
        if ($nota >= 16.0) return 'Muy Bueno';
        if ($nota >= 13.0) return 'Bueno';
        if ($nota >= 10.0) return 'Regular';
        return 'Aplazado';
    }
}

// 1) Propiedad docente y 2) coherencia materia <-> malla de la cohorte
$acceso_ok = ($id_cohorte > 0 && $id_materia > 0)
    && docente_puede_gestionar($pdo, $id_profesor, $id_materia, $id_cohorte);
if ($acceso_ok) {
    try {
        $stmt_v = $pdo->prepare("
            SELECT 1 FROM public.malla_materias mm
            JOIN public.cohortes co ON co.id_malla = mm.id_malla
            WHERE mm.id_materia = :mat AND co.id_cohorte = :coh
            LIMIT 1
        ");
        $stmt_v->execute([':mat' => $id_materia, ':coh' => $id_cohorte]);
        $acceso_ok = (bool) $stmt_v->fetchColumn();
    } catch (Throwable $e) {
        $acceso_ok = false;
    }
}
if (!$acceso_ok) {
    echo "<div class='alert alert-danger font-weight-bold'>Acceso denegado: sección/materia no válida o sin asignación docente aceptada.</div>";
    require_once '../../includes/footer.php';
    exit();
}

// Consultar plan de evaluaciones creadas
$stmt_e = $pdo->prepare("
    SELECT * FROM public.plan_evaluacion
     WHERE id_cohorte = :coh AND id_materia = :mat
     ORDER BY fecha_evaluacion ASC NULLS LAST, id_plan ASC
");
$stmt_e->execute([':coh' => $id_cohorte, ':mat' => $id_materia]);
$evaluaciones = $stmt_e->fetchAll(PDO::FETCH_ASSOC);

$id_plan_sel = intval($_GET['id_plan'] ?? 0);
if ($id_plan_sel <= 0 || !docente_plan_pertenece($pdo, $id_plan_sel, $id_materia, $id_cohorte)) {
    $id_plan_sel = (int) ($evaluaciones[0]['id_plan'] ?? 0);
}
$plan_actual = null;
foreach ($evaluaciones as $ev) {
    if ((int) $ev['id_plan'] === $id_plan_sel) {
        $plan_actual = $ev;
        break;
    }
}
$es_cualitativa = ($plan_actual && $plan_actual['tipo_ponderacion'] === 'Cualitativa');

// Guardar Notas (validación PHP estricta: escala 1-20 y estudiantes inscritos)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_notas'])) {
    try {
        $id_plan_post = intval($_POST['id_plan'] ?? 0);
        if ($id_plan_post <= 0 || !docente_plan_pertenece($pdo, $id_plan_post, $id_materia, $id_cohorte)) {
            throw new Exception('Evaluación no válida para esta sección/materia.');
        }

        $stmt_t = $pdo->prepare("SELECT tipo_ponderacion FROM public.plan_evaluacion WHERE id_plan = :p");
        $stmt_t->execute([':p' => $id_plan_post]);
        $es_cualitativa_post = ($stmt_t->fetchColumn() === 'Cualitativa');

        $notas_post = $_POST['notas'] ?? [];
        if (!is_array($notas_post) || empty($notas_post)) {
            throw new Exception('No se recibieron calificaciones para guardar.');
        }

        // Conjunto autorizado de estudiantes de la cohorte
        $stmt_i = $pdo->prepare("SELECT DISTINCT id_estudiante FROM public.inscripciones WHERE id_curso = :coh");
        $stmt_i->execute([':coh' => $id_cohorte]);
        $inscritos = [];
        foreach ($stmt_i->fetchAll(PDO::FETCH_COLUMN) as $id_ins) {
            $inscritos[(int) $id_ins] = true;
        }

        $opciones = docente_opciones_cualitativa();
        $filas = [];
        foreach ($notas_post as $id_est_raw => $datos) {
            $id_est = intval($id_est_raw);
            if (!isset($inscritos[$id_est])) {
                throw new Exception("El estudiante #{$id_est} no está inscrito en esta sección.");
            }
            if (!is_array($datos)) {
                throw new Exception('Datos de calificación mal formados.');
            }

            if ($es_cualitativa_post) {
                $cual = trim((string) ($datos['cualitativa'] ?? ''));
                if ($cual === '') {
                    continue; // celda vacía: no se modifica
                }
                if (!in_array($cual, $opciones, true)) {
                    throw new Exception('Calificación cualitativa no permitida.');
                }
                $num  = docente_nota_cualitativa_a_numerica($cual);
                $rec  = null;
                $cual_guardar = $cual;
            } else {
                $num_raw = trim((string) ($datos['cuantitativa'] ?? ''));
                $rec_raw = trim((string) ($datos['recuperacion'] ?? ''));
                if ($num_raw === '' && $rec_raw === '') {
                    continue; // fila sin cambios
                }
                if (!is_numeric($num_raw)) {
                    throw new Exception('La nota cuantitativa debe ser numérica (escala 1-20).');
                }
                $num = (float) $num_raw;
                if ($num < 1 || $num > 20) {
                    throw new Exception('La nota cuantitativa debe estar entre 1 y 20.');
                }
                if ($rec_raw !== '') {
                    if (!is_numeric($rec_raw)) {
                        throw new Exception('La recuperación debe ser numérica (escala 1-20).');
                    }
                    $rec = (float) $rec_raw;
                    if ($rec < 1 || $rec > 20) {
                        throw new Exception('La recuperación debe estar entre 1 y 20.');
                    }
                } else {
                    $rec = null;
                }
                $cual_guardar = obtenerCualitativa($rec !== null ? $rec : $num);
            }

            $obs = trim((string) ($datos['observaciones'] ?? ''));
            if ($obs !== '' && function_exists('mb_substr')) {
                $obs = mb_substr($obs, 0, 400, 'UTF-8');
            } elseif ($obs !== '') {
                $obs = substr($obs, 0, 400);
            }

            $filas[] = [
                ':p'    => $id_plan_post,
                ':e'    => $id_est,
                ':num'  => $num,
                ':cual' => $cual_guardar,
                ':rec'  => $rec,
                ':obs'  => $obs,
            ];
        }

        if (empty($filas)) {
            throw new Exception('No se recibieron notas válidas para guardar.');
        }

        $pdo->beginTransaction();
        $sql = "
            INSERT INTO public.notas_estudiantes
                (id_plan, id_estudiante, nota_cuantitativa, nota_cualitativa, nota_recuperacion, observaciones)
            VALUES (:p, :e, :num, :cual, :rec, :obs)
            ON CONFLICT (id_plan, id_estudiante) DO UPDATE SET
                nota_cuantitativa = EXCLUDED.nota_cuantitativa,
                nota_cualitativa  = EXCLUDED.nota_cualitativa,
                nota_recuperacion = EXCLUDED.nota_recuperacion,
                observaciones     = EXCLUDED.observaciones
        ";
        $stmt_n = $pdo->prepare($sql);
        foreach ($filas as $fila) {
            $stmt_n->execute($fila);
        }
        $pdo->commit();

        $n = count($filas);
        docente_registrar_bitacora($pdo, "NOTAS_GUARDAR cohorte:{$id_cohorte} materia:{$id_materia} id_plan:{$id_plan_post} filas:{$n}");
        echo "<script>alert('¡Calificaciones guardadas exitosamente!'); window.location.href='cargar_notas.php?id_cohorte={$id_cohorte}&id_materia={$id_materia}&id_plan={$id_plan_post}';</script>";
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
    }
}

// Estudiantes inscritos en la cohorte
$sql_alumnos = "SELECT u.id_usuario, u.cedula, u.nombre, u.apellido,
                       n.nota_cuantitativa, n.nota_recuperacion, n.nota_cualitativa, n.observaciones
                  FROM public.inscripciones i
                  JOIN public.usuarios u ON i.id_estudiante = u.id_usuario
                  LEFT JOIN public.notas_estudiantes n
                         ON n.id_estudiante = u.id_usuario AND n.id_plan = :p
                 WHERE i.id_curso = :coh
                 ORDER BY u.apellido ASC, u.nombre ASC";
$stmt_a = $pdo->prepare($sql_alumnos);
$stmt_a->execute([':p' => $id_plan_sel, ':coh' => $id_cohorte]);
$alumnos = $stmt_a->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <h1 class="h3 mb-0 font-weight-bold" style="color: #521f29;">
        <i class="fas fa-star mr-2 text-warning"></i>Carga de Calificaciones
    </h1>
    <div>
        <?php if ($id_plan_sel > 0): ?>
            <a href="sabana_notas.php?id_cohorte=<?= $id_cohorte ?>&id_materia=<?= $id_materia ?>" class="btn btn-sm font-weight-bold text-dark shadow-sm" style="background-color: #ff8a00;"><i class="fas fa-table mr-1"></i> Sábana de notas</a>
        <?php endif; ?>
        <a href="index.php" class="btn btn-sm btn-secondary font-weight-bold shadow-sm"><i class="fas fa-arrow-left mr-1"></i> Volver</a>
    </div>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger font-weight-bold shadow-sm"><i class="fas fa-exclamation-triangle mr-2"></i><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="card shadow border-0 mb-4" style="border-radius: 15px;">
    <div class="card-header text-white font-weight-bold" style="background-color: #521f29; border-top-left-radius: 15px; border-top-right-radius: 15px;">
        Seleccionar Evaluación
    </div>
    <div class="card-body bg-white p-4">
        <?php if (empty($evaluaciones)): ?>
            <div class="alert alert-info font-weight-bold mb-0">
                Aún no hay evaluaciones configuradas. Cree una en el
                <a href="plan_evaluacion.php?id_cohorte=<?= $id_cohorte ?>&id_materia=<?= $id_materia ?>">Plan de Evaluación</a>.
            </div>
        <?php else: ?>
            <form method="GET" action="cargar_notas.php" class="mb-4">
                <input type="hidden" name="id_cohorte" value="<?= $id_cohorte ?>">
                <input type="hidden" name="id_materia" value="<?= $id_materia ?>">
                <select name="id_plan" class="form-control font-weight-bold" onchange="this.form.submit()">
                    <?php foreach ($evaluaciones as $ev): ?>
                        <option value="<?= $ev['id_plan'] ?>" <?= $ev['id_plan'] == $id_plan_sel ? 'selected' : '' ?>>
                            <?= htmlspecialchars($ev['titulo_evaluacion']) ?> (Peso: <?= number_format((float) $ev['porcentaje_peso'], 2) ?>% — <?= htmlspecialchars($ev['tipo_ponderacion']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>

            <form method="POST" action="cargar_notas.php?id_cohorte=<?= $id_cohorte ?>&id_materia=<?= $id_materia ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="id_plan" value="<?= $id_plan_sel ?>">

                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead style="background-color: #ff8a00; color: #521f29;">
                            <tr>
                                <th>Estudiante / Cédula</th>
                                <?php if ($es_cualitativa): ?>
                                    <th style="width: 35%;">Calificación cualitativa</th>
                                <?php else: ?>
                                    <th style="width: 25%;">Nota Cuantitativa (1.0 - 20.0)</th>
                                    <th style="width: 25%;">Recuperación (1.0 - 20.0)</th>
                                <?php endif; ?>
                                <th>Observaciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($alumnos as $al): ?>
                                <tr>
                                    <td class="font-weight-bold" style="color: #521f29;">
                                        <?= htmlspecialchars($al['nombre'] . ' ' . $al['apellido']) ?><br>
                                        <small class="text-muted">C.I: V-<?= htmlspecialchars($al['cedula']) ?></small>
                                    </td>
                                    <?php if ($es_cualitativa): ?>
                                        <td>
                                            <select name="notas[<?= $al['id_usuario'] ?>][cualitativa]" class="form-control font-weight-bold">
                                                <option value="">— Sin calificar —</option>
                                                <?php foreach (docente_opciones_cualitativa() as $opcion): ?>
                                                    <option value="<?= htmlspecialchars($opcion) ?>" <?= ($al['nota_cualitativa'] ?? '') === $opcion ? 'selected' : '' ?>><?= htmlspecialchars($opcion) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                    <?php else: ?>
                                        <td>
                                            <input type="number" step="0.1" min="1" max="20" name="notas[<?= $al['id_usuario'] ?>][cuantitativa]" class="form-control font-weight-bold text-center" value="<?= $al['nota_cuantitativa'] ?? '' ?>" placeholder="1.0 - 20.0">
                                        </td>
                                        <td>
                                            <input type="number" step="0.1" min="1" max="20" name="notas[<?= $al['id_usuario'] ?>][recuperacion]" class="form-control font-weight-bold text-center border-danger" value="<?= $al['nota_recuperacion'] ?? '' ?>" placeholder="1.0 - 20.0">
                                        </td>
                                    <?php endif; ?>
                                    <td>
                                        <input type="text" maxlength="400" name="notas[<?= $al['id_usuario'] ?>][observaciones]" class="form-control" value="<?= htmlspecialchars($al['observaciones'] ?? '') ?>" placeholder="Opcional">
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <button type="submit" name="guardar_notas" class="btn btn-block font-weight-bold text-white shadow-lg py-3 mt-3" style="background-color: #ff8a00; color: #521f29 !important; border-radius: 10px;">
                    <i class="fas fa-save mr-2"></i> GUARDAR NOTAS DE LA SECCIÓN
                </button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>
