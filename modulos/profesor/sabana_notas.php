<?php
// /sistema_nora/modulos/profesor/sabana_notas.php
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

if (!function_exists('sabana_calcular_final')) {
    /**
     * Misma fórmula de includes/academico.php: promedio ponderado por los
     * pesos de las evaluaciones con nota registrada; recuperación prevalece.
     */
    function sabana_calcular_final(array $planes, array $notas_estudiante): array
    {
        $suma_pesos = 0.0;
        $suma_ponderada = 0.0;
        $con_notas = 0;
        $cual_aprobada = false;

        foreach ($planes as $pl) {
            $pid = (int) $pl['id_plan'];
            $reg = $notas_estudiante[$pid] ?? null;
            if (!$reg) {
                continue;
            }
            $con_notas++;
            $peso = (float) $pl['porcentaje_peso'];
            $rec = ($reg['nota_recuperacion'] !== null && $reg['nota_recuperacion'] !== '')
                ? (float) $reg['nota_recuperacion'] : null;
            $cuant = ($reg['nota_cuantitativa'] !== null && $reg['nota_cuantitativa'] !== '')
                ? (float) $reg['nota_cuantitativa'] : 0.0;
            $nota = ($rec !== null) ? $rec : $cuant;

            $suma_ponderada += $nota * $peso;
            $suma_pesos += $peso;

            $cual = strtoupper(trim((string) ($reg['nota_cualitativa'] ?? '')));
            if (in_array($cual, ['APROBADO', 'EXCELENTE', 'APROBÓ', 'APROBO'], true)) {
                $cual_aprobada = true;
            }
        }

        if ($con_notas === 0) {
            return ['nota' => null, 'estado' => 'Sin notas'];
        }

        $final = $suma_pesos > 0 ? ($suma_ponderada / $suma_pesos) : null;
        $estado = 'Reprobado';
        if ($final !== null && $final >= 10) {
            $estado = 'Aprobado';
        } elseif (($final === null || $final <= 0) && $cual_aprobada) {
            $estado = 'Aprobado';
        }
        return ['nota' => ($final !== null ? round($final, 2) : null), 'estado' => $estado];
    }
}

// 1) Propiedad docente y 2) coherencia materia <-> malla de la cohorte
$acceso_ok = ($id_cohorte > 0 && $id_materia > 0)
    && docente_puede_gestionar($pdo, $id_profesor, $id_materia, $id_cohorte);
$info = null;
if ($acceso_ok) {
    try {
        $stmt_v = $pdo->prepare("
            SELECT co.id_cohorte, co.codigo_cohorte, co.salon_aula, co.num_periodo,
                   mm.id_materia, mm.nombre_materia, mm.modulo_num, mm.horas_academicas,
                   mc.id_malla, mc.nombre_programa, mc.tipo_oferta
              FROM public.cohortes co
              JOIN public.malla_materias mm
                ON mm.id_materia = :mat AND mm.id_malla = co.id_malla
              JOIN public.mallas_curriculares mc ON mc.id_malla = co.id_malla
             WHERE co.id_cohorte = :coh
             LIMIT 1
        ");
        $stmt_v->execute([':coh' => $id_cohorte, ':mat' => $id_materia]);
        $info = $stmt_v->fetch(PDO::FETCH_ASSOC) ?: null;
        $acceso_ok = ($info !== null);
    } catch (Throwable $e) {
        $acceso_ok = false;
    }
}
if (!$acceso_ok) {
    echo "<div class='alert alert-danger font-weight-bold'>Acceso denegado: sección/materia no válida o sin asignación docente aceptada.</div>";
    require_once '../../includes/footer.php';
    exit();
}

// Evaluaciones de ESTA cohorte y ESTA materia
$stmt_p = $pdo->prepare("
    SELECT pe.* FROM public.plan_evaluacion pe
     WHERE pe.id_cohorte = :coh AND pe.id_materia = :mat
     ORDER BY pe.fecha_evaluacion ASC NULLS LAST, pe.id_plan ASC
");
$stmt_p->execute([':coh' => $id_cohorte, ':mat' => $id_materia]);
$evaluaciones = $stmt_p->fetchAll(PDO::FETCH_ASSOC);
$plan_map = [];
$suma_ponderaciones = 0.0;
foreach ($evaluaciones as $ev) {
    $plan_map[(int) $ev['id_plan']] = $ev;
    $suma_ponderaciones += (float) $ev['porcentaje_peso'];
}

// Guardado masivo (UPSERT con validación equivalente a cargar_notas.php)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_sabana'])) {
    try {
        $notas_post = $_POST['notas'] ?? [];
        if (!is_array($notas_post) || empty($notas_post)) {
            throw new Exception('No se recibieron calificaciones para guardar.');
        }

        $stmt_i = $pdo->prepare("SELECT DISTINCT id_estudiante FROM public.inscripciones WHERE id_curso = :coh");
        $stmt_i->execute([':coh' => $id_cohorte]);
        $inscritos = [];
        foreach ($stmt_i->fetchAll(PDO::FETCH_COLUMN) as $id_ins) {
            $inscritos[(int) $id_ins] = true;
        }

        $opciones = docente_opciones_cualitativa();
        $upserts = [];
        $borrados = [];
        foreach ($notas_post as $id_est_raw => $por_plan) {
            $id_est = intval($id_est_raw);
            if (!isset($inscritos[$id_est])) {
                throw new Exception("El estudiante #{$id_est} no está inscrito en esta sección.");
            }
            if (!is_array($por_plan)) {
                throw new Exception('Datos de calificación mal formados.');
            }

            foreach ($por_plan as $id_plan_raw => $datos) {
                $id_plan = intval($id_plan_raw);
                if (!isset($plan_map[$id_plan])) {
                    throw new Exception('La evaluación #' . $id_plan . ' no pertenece a esta sección/materia.');
                }
                if (!is_array($datos)) {
                    throw new Exception('Datos de calificación mal formados.');
                }

                $es_cualitativa = ($plan_map[$id_plan]['tipo_ponderacion'] === 'Cualitativa');
                if ($es_cualitativa) {
                    $cual = trim((string) ($datos['cualitativa'] ?? ''));
                    if ($cual === '') {
                        $borrados[] = [':p' => $id_plan, ':e' => $id_est];
                        continue;
                    }
                    if (!in_array($cual, $opciones, true)) {
                        throw new Exception('Calificación cualitativa no permitida.');
                    }
                    $num = docente_nota_cualitativa_a_numerica($cual);
                    $rec = null;
                    $cual_guardar = $cual;
                } else {
                    $num_raw = trim((string) ($datos['cuantitativa'] ?? ''));
                    $rec_raw = trim((string) ($datos['recuperacion'] ?? ''));
                    if ($num_raw === '' && $rec_raw === '') {
                        $borrados[] = [':p' => $id_plan, ':e' => $id_est];
                        continue;
                    }
                    if (!is_numeric($num_raw)) {
                        throw new Exception('Toda nota debe ser numérica (escala 1-20).');
                    }
                    $num = (float) $num_raw;
                    if ($num < 1 || $num > 20) {
                        throw new Exception('Las notas deben estar entre 1 y 20.');
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
                    $cual_guardar = ($rec !== null ? $rec : $num) >= 10 ? 'Aprobado' : 'Reprobado';
                }

                $upserts[] = [
                    ':p'    => $id_plan,
                    ':e'    => $id_est,
                    ':num'  => $num,
                    ':cual' => $cual_guardar,
                    ':rec'  => $rec,
                ];
            }
        }

        $pdo->beginTransaction();
        if (!empty($upserts)) {
            $sql = "
                INSERT INTO public.notas_estudiantes
                    (id_plan, id_estudiante, nota_cuantitativa, nota_cualitativa, nota_recuperacion)
                VALUES (:p, :e, :num, :cual, :rec)
                ON CONFLICT (id_plan, id_estudiante) DO UPDATE SET
                    nota_cuantitativa = EXCLUDED.nota_cuantitativa,
                    nota_cualitativa  = EXCLUDED.nota_cualitativa,
                    nota_recuperacion = EXCLUDED.nota_recuperacion
            ";
            $stmt_n = $pdo->prepare($sql);
            foreach ($upserts as $fila) {
                $stmt_n->execute($fila);
            }
        }
        if (!empty($borrados)) {
            $stmt_del = $pdo->prepare("DELETE FROM public.notas_estudiantes WHERE id_plan = :p AND id_estudiante = :e");
            foreach ($borrados as $fila) {
                $stmt_del->execute($fila);
            }
        }
        $pdo->commit();

        $n = count($upserts) + count($borrados);
        docente_registrar_bitacora($pdo, "SABANA_GUARDAR cohorte:{$id_cohorte} materia:{$id_materia} filas:{$n}");
        // header.php ya envió salida: redirección por JS para evitar "headers already sent".
        echo "<script>window.location.replace('sabana_notas.php?id_cohorte={$id_cohorte}&id_materia={$id_materia}&msg=ok');</script>";
        exit;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
    }
}

// Estudiantes inscritos en la cohorte
$stmt_al = $pdo->prepare("
    SELECT u.id_usuario, u.cedula, u.nombre, u.apellido
      FROM public.inscripciones i
      JOIN public.usuarios u ON u.id_usuario = i.id_estudiante
     WHERE i.id_curso = :coh
     ORDER BY u.apellido ASC, u.nombre ASC
");
$stmt_al->execute([':coh' => $id_cohorte]);
$estudiantes = $stmt_al->fetchAll(PDO::FETCH_ASSOC);

// Notas existentes de las evaluaciones de esta cohorte/materia
$notas_map = [];
if (!empty($evaluaciones)) {
    $stmt_notas = $pdo->prepare("
        SELECT n.id_plan, n.id_estudiante, n.nota_cuantitativa,
               n.nota_recuperacion, n.nota_cualitativa
          FROM public.notas_estudiantes n
          JOIN public.plan_evaluacion pe ON pe.id_plan = n.id_plan
         WHERE pe.id_cohorte = :coh AND pe.id_materia = :mat
    ");
    $stmt_notas->execute([':coh' => $id_cohorte, ':mat' => $id_materia]);
    foreach ($stmt_notas->fetchAll(PDO::FETCH_ASSOC) as $fila) {
        $notas_map[(int) $fila['id_estudiante']][(int) $fila['id_plan']] = $fila;
    }
}

$pesos_incompletos = abs($suma_ponderaciones - 100.0) > 0.01;
$num_columnas = 3; // estudiante + nota final + estado
foreach ($evaluaciones as $ev) {
    $num_columnas += ($ev['tipo_ponderacion'] === 'Cualitativa') ? 1 : 2;
}
?>
<style>
@media print {
    .no-print, .sidebar, .topbar, .sticky-footer, .scroll-to-top { display: none !important; }
    body, #content-wrapper { background: #fff !important; color: #000 !important; }
    .card { border: 0 !important; box-shadow: none !important; }
    .table td input, .table td select { border: 0 !important; }
}
</style>

<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <div>
        <h1 class="h3 mb-0 font-weight-bold" style="color: #521f29;">
            <i class="fas fa-table mr-2 text-warning"></i>Sábana de Notas
        </h1>
        <span class="text-muted font-weight-bold">
            Programa: <?= htmlspecialchars($info['nombre_programa']) ?>
            <?php if (!empty($info['tipo_oferta'])): ?> · <?= htmlspecialchars($info['tipo_oferta']) ?><?php endif; ?>
            | Módulo <?= htmlspecialchars($info['modulo_num']) ?> — <?= htmlspecialchars($info['nombre_materia']) ?>
            | Cohorte: <?= htmlspecialchars($info['codigo_cohorte']) ?>
            | Salón: <?= htmlspecialchars($info['salon_aula']) ?>
        </span>
    </div>
    <div class="no-print">
        <button type="button" class="btn btn-sm font-weight-bold text-white shadow-sm mr-1" style="background-color: #521f29;" onclick="window.print()">
            <i class="fas fa-print mr-1"></i> Imprimir / Exportar
        </button>
        <a href="plan_evaluacion.php?id_cohorte=<?= $id_cohorte ?>&id_materia=<?= $id_materia ?>" class="btn btn-sm btn-warning text-dark font-weight-bold shadow-sm mr-1"><i class="fas fa-tasks mr-1"></i> Plan Eval.</a>
        <a href="index.php" class="btn btn-sm btn-secondary font-weight-bold shadow-sm"><i class="fas fa-arrow-left mr-1"></i> Volver</a>
    </div>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger font-weight-bold shadow-sm"><i class="fas fa-exclamation-triangle mr-2"></i><?= htmlspecialchars($error) ?></div>
<?php endif; ?>
<?php if (($_GET['msg'] ?? '') === 'ok'): ?>
    <div class="alert alert-success font-weight-bold shadow-sm no-print"><i class="fas fa-check-circle mr-2"></i>Sábana actualizada correctamente.</div>
<?php endif; ?>

<div class="card shadow border-0 mb-4" style="border-radius: 15px;">
    <div class="card-header text-white font-weight-bold d-flex flex-wrap justify-content-between align-items-center" style="background-color: #521f29; border-top-left-radius: 15px; border-top-right-radius: 15px;">
        <span><i class="fas fa-graduation-cap mr-2 text-warning"></i>Estudiantes inscritos — escala 1 a 20</span>
        <span class="badge font-weight-bold px-3 py-2 <?= $pesos_incompletos ? 'badge-danger' : 'badge-warning text-dark' ?>">
            Suma de ponderaciones: <?= number_format($suma_ponderaciones, 2) ?>% / 100%
        </span>
    </div>
    <div class="card-body bg-white p-4">
        <?php if ($pesos_incompletos): ?>
            <div class="alert alert-danger font-weight-bold py-2">
                <i class="fas fa-exclamation-triangle mr-2"></i>La suma de ponderaciones debe ser exactamente 100%.
                Ajuste el <a href="plan_evaluacion.php?id_cohorte=<?= $id_cohorte ?>&id_materia=<?= $id_materia ?>" class="alert-link">Plan de Evaluación</a>
                para que la nota final sea válida.
            </div>
        <?php endif; ?>

        <?php if (empty($evaluaciones)): ?>
            <div class="alert alert-info font-weight-bold mb-0">
                No hay evaluaciones configuradas para esta sección/materia.
                Créelas en el <a href="plan_evaluacion.php?id_cohorte=<?= $id_cohorte ?>&id_materia=<?= $id_materia ?>">Plan de Evaluación</a>.
            </div>
        <?php else: ?>
            <form method="POST" action="sabana_notas.php?id_cohorte=<?= $id_cohorte ?>&id_materia=<?= $id_materia ?>">
                <?= csrf_field() ?>
                <div class="table-responsive">
                    <table class="table table-bordered table-hover align-middle mb-0">
                        <thead class="text-center" style="background-color: #ff8a00; color: #521f29;">
                            <tr>
                                <th rowspan="2" class="text-left align-middle">Estudiante / Cédula</th>
                                <?php foreach ($evaluaciones as $ev): ?>
                                    <th colspan="<?= $ev['tipo_ponderacion'] === 'Cualitativa' ? 1 : 2 ?>">
                                        <?= htmlspecialchars($ev['titulo_evaluacion']) ?>
                                        <?php if (!empty($ev['es_asistencia'])): ?><i class="fas fa-check-double ml-1" title="Asistencia"></i><?php endif; ?>
                                        <br><span class="badge badge-light text-dark font-weight-bold"><?= number_format((float) $ev['porcentaje_peso'], 2) ?>%</span>
                                        <br><small><?= htmlspecialchars($ev['tipo_ponderacion']) ?></small>
                                    </th>
                                <?php endforeach; ?>
                                <th rowspan="2" class="align-middle">Nota final</th>
                                <th rowspan="2" class="align-middle">Estado</th>
                            </tr>
                            <tr>
                                <?php foreach ($evaluaciones as $ev): ?>
                                    <?php if ($ev['tipo_ponderacion'] !== 'Cualitativa'): ?>
                                        <th style="font-size: .75rem;">Nota (1-20)</th>
                                        <th style="font-size: .75rem;">Recup. (1-20)</th>
                                    <?php else: ?>
                                        <th style="font-size: .75rem;">Calificación</th>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($estudiantes)): ?>
                                <tr><td colspan="<?= $num_columnas ?>" class="text-center text-muted font-weight-bold py-3">No hay estudiantes inscritos en esta sección.</td></tr>
                            <?php else: ?>
                                <?php foreach ($estudiantes as $est): ?>
                                    <?php
                                        $id_est = (int) $est['id_usuario'];
                                        $notas_est = $notas_map[$id_est] ?? [];
                                        $calc = sabana_calcular_final($evaluaciones, $notas_est);
                                    ?>
                                    <tr>
                                        <td class="font-weight-bold" style="color: #521f29;">
                                            <?= htmlspecialchars($est['apellido'] . ' ' . $est['nombre']) ?><br>
                                            <small class="text-muted">C.I: V-<?= htmlspecialchars($est['cedula']) ?></small>
                                        </td>
                                        <?php foreach ($evaluaciones as $ev): ?>
                                            <?php
                                                $pid = (int) $ev['id_plan'];
                                                $reg = $notas_est[$pid] ?? [];
                                            ?>
                                            <?php if ($ev['tipo_ponderacion'] === 'Cualitativa'): ?>
                                                <td>
                                                    <select name="notas[<?= $id_est ?>][<?= $pid ?>][cualitativa]" class="form-control form-control-sm font-weight-bold">
                                                        <option value="">—</option>
                                                        <?php foreach (docente_opciones_cualitativa() as $opcion): ?>
                                                            <option value="<?= htmlspecialchars($opcion) ?>" <?= ($reg['nota_cualitativa'] ?? '') === $opcion ? 'selected' : '' ?>><?= htmlspecialchars($opcion) ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </td>
                                            <?php else: ?>
                                                <td>
                                                    <input type="number" min="1" max="20" step="0.1" name="notas[<?= $id_est ?>][<?= $pid ?>][cuantitativa]" class="form-control form-control-sm text-center font-weight-bold" value="<?= htmlspecialchars($reg['nota_cuantitativa'] ?? '') ?>" placeholder="1-20">
                                                </td>
                                                <td>
                                                    <input type="number" min="1" max="20" step="0.1" name="notas[<?= $id_est ?>][<?= $pid ?>][recuperacion]" class="form-control form-control-sm text-center font-weight-bold" value="<?= htmlspecialchars($reg['nota_recuperacion'] ?? '') ?>" placeholder="1-20">
                                                </td>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                        <td class="text-center font-weight-bold h5 mb-0" style="color: #521f29;">
                                            <?= $calc['nota'] !== null ? number_format($calc['nota'], 2) : '—' ?>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge <?= $calc['estado'] === 'Aprobado' ? 'badge-success' : ($calc['estado'] === 'Reprobado' ? 'badge-danger' : 'badge-secondary') ?> font-weight-bold">
                                                <?= htmlspecialchars($calc['estado']) ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if (!empty($estudiantes)): ?>
                    <button type="submit" name="guardar_sabana" class="btn btn-block font-weight-bold text-white shadow-lg py-3 mt-4 no-print" style="background-color: #ff8a00; color: #521f29 !important; border-radius: 10px;">
                        <i class="fas fa-save mr-2"></i> GUARDAR SÁBANA COMPLETA
                    </button>
                <?php endif; ?>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>
