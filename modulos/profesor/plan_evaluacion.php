<?php
// /sistema_nora/modulos/profesor/plan_evaluacion.php
require_once '../../config/db.php';
require_once '../../includes/header.php';
require_once '../../includes/permisos_docente.php';
require_once '../../includes/contenidos_evaluacion.php';

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

// 1) Propiedad docente (admin/superadmin/control_estudio pasan por override).
// 2) Coherencia de malla: la materia debe pertenecer a la malla de la cohorte.
$acceso_ok = ($id_cohorte > 0 && $id_materia > 0)
    && docente_puede_gestionar($pdo, $id_profesor, $id_materia, $id_cohorte);
$info = null;

if ($acceso_ok) {
    try {
        $stmt_c = $pdo->prepare("
            SELECT co.id_cohorte, co.codigo_cohorte, co.salon_aula, co.num_periodo,
                   mm.id_materia, mm.nombre_materia, mm.modulo_num, mm.horas_academicas,
                   mm.contenido_detallado,
                   mc.nombre_programa, mc.tipo_oferta, mc.tipo_regimen
              FROM public.cohortes co
              JOIN public.malla_materias mm
                ON mm.id_materia = :mat AND mm.id_malla = co.id_malla
              JOIN public.mallas_curriculares mc ON mc.id_malla = co.id_malla
             WHERE co.id_cohorte = :coh
             LIMIT 1
        ");
        $stmt_c->execute([':coh' => $id_cohorte, ':mat' => $id_materia]);
        $info = $stmt_c->fetch(PDO::FETCH_ASSOC) ?: null;
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

$contenidos = evaluacion_contenidos_materia($pdo, $id_materia);
$ids_validos = [];
foreach ($contenidos as $c) {
    $ids_validos[(int) $c['id_contenido']] = true;
}

$instrumentos = [
    'Prueba escrita', 'Trabajo práctico', 'Exposición oral',
    'Proyecto / investigación', 'Lista de cotejo', 'Rúbrica de desempeño', 'Otro',
];

// Procesar Creación / Edición / Eliminación
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['guardar_evaluacion'])) {
        try {
            $id_plan_editar = intval($_POST['id_plan'] ?? 0);
            if ($id_plan_editar > 0 && !docente_plan_pertenece($pdo, $id_plan_editar, $id_materia, $id_cohorte)) {
                throw new Exception('La evaluación que intenta editar no pertenece a esta sección/materia.');
            }

            $titulo = trim((string) ($_POST['titulo_evaluacion'] ?? ''));
            $tipo_p = (($_POST['tipo_ponderacion'] ?? '') === 'Cualitativa') ? 'Cualitativa' : 'Cuantitativa';
            $peso_raw = $_POST['porcentaje_peso'] ?? '';
            $fecha = trim((string) ($_POST['fecha_evaluacion'] ?? ''));
            $instrumento = trim((string) ($_POST['instrumento_evaluacion'] ?? ''));
            if ($instrumento === 'Otro') {
                $instrumento = trim((string) ($_POST['instrumento_otro'] ?? ''));
            }
            $descripcion = trim((string) ($_POST['descripcion_libre'] ?? ''));

            $longitud_titulo = function_exists('mb_strlen') ? mb_strlen($titulo, 'UTF-8') : strlen($titulo);
            if ($titulo === '' || $longitud_titulo > 200) {
                throw new Exception('Indique un título válido para la evaluación.');
            }
            if ($instrumento === '' || (function_exists('mb_strlen') ? mb_strlen($instrumento, 'UTF-8') : strlen($instrumento)) > 120) {
                throw new Exception('Indique el instrumento de evaluación.');
            }
            if (!is_numeric($peso_raw)) {
                throw new Exception('La ponderación de la evaluación debe ser un valor numérico.');
            }
            $peso = (float) $peso_raw;
            if ($peso <= 0 || $peso > 100) {
                throw new Exception('La ponderación de la evaluación debe estar entre 0.01 y 100%.');
            }
            if ($fecha !== '' && !DateTime::createFromFormat('Y-m-d', $fecha)) {
                throw new Exception('La fecha de evaluación no es válida.');
            }

            // Contenidos marcados: de 1 a todos los de la asignatura.
            // La ponderación la define el docente únicamente a nivel de evaluación.
            $marcados = array_map('intval', (array) ($_POST['contenidos'] ?? []));
            if (count($marcados) < 1) {
                throw new Exception('Debe marcar al menos un contenido a evaluar.');
            }
            $contenidos_plan = [];
            foreach ($marcados as $id_contenido) {
                if (!isset($ids_validos[$id_contenido])) {
                    throw new Exception('Uno de los contenidos marcados no pertenece a esta asignatura.');
                }
                $contenidos_plan[] = ['id_contenido' => $id_contenido];
            }

            $pdo->beginTransaction();
            // Serializa la suma de pesos y el conteo de evaluaciones por (cohorte, materia).
            $pdo->prepare("SELECT pg_advisory_xact_lock(:coh::integer, :mat::integer)")
                ->execute([':coh' => $id_cohorte, ':mat' => $id_materia]);

            $stmt_s = $pdo->prepare("
                SELECT COALESCE(SUM(porcentaje_peso), 0), COUNT(*)
                  FROM public.plan_evaluacion
                 WHERE id_cohorte = :coh AND id_materia = :mat
            ");
            $stmt_s->execute([':coh' => $id_cohorte, ':mat' => $id_materia]);
            $fila = $stmt_s->fetch(PDO::FETCH_NUM);
            $suma = (float) $fila[0];
            $cantidad = (int) $fila[1];

            if ($id_plan_editar > 0) {
                $stmt_actual = $pdo->prepare("SELECT porcentaje_peso FROM public.plan_evaluacion WHERE id_plan = :id");
                $stmt_actual->execute([':id' => $id_plan_editar]);
                $suma -= (float) $stmt_actual->fetchColumn();
                $cantidad--;
            }

            if (($suma + $peso) > 100.0001) {
                $disponible = round(100.00 - $suma, 2);
                throw new Exception("La suma supera el 100%. Porcentaje disponible para esta sección: {$disponible}%.");
            }
            if ($cantidad >= 4 && $id_plan_editar === 0) {
                throw new Exception('El límite es de 4 evaluaciones por asignatura/sección.');
            }

            if ($id_plan_editar > 0) {
                $stmt_g = $pdo->prepare("
                    UPDATE public.plan_evaluacion
                       SET titulo_evaluacion = :tit,
                           tipo_ponderacion = :tipo,
                           porcentaje_peso = :peso,
                           fecha_evaluacion = :fec,
                           id_profesor = :prof,
                           instrumento_evaluacion = :inst,
                           descripcion_libre = :desc
                     WHERE id_plan = :id AND id_cohorte = :coh AND id_materia = :mat
                ");
                $stmt_g->execute([
                    ':tit' => $titulo, ':tipo' => $tipo_p, ':peso' => $peso,
                    ':fec' => ($fecha !== '' ? $fecha : null), ':prof' => $id_profesor,
                    ':inst' => $instrumento, ':desc' => $descripcion,
                    ':id' => $id_plan_editar, ':coh' => $id_cohorte, ':mat' => $id_materia,
                ]);
                $plan_guardado = $id_plan_editar;
                $accion_bitacora = 'PLAN_EDITAR';
            } else {
                $stmt_i = $pdo->prepare("
                    INSERT INTO public.plan_evaluacion
                        (id_cohorte, id_materia, titulo_evaluacion, tipo_ponderacion,
                         porcentaje_peso, fecha_evaluacion, id_profesor,
                         instrumento_evaluacion, descripcion_libre)
                    VALUES (:coh, :mat, :tit, :tipo, :peso, :fec, :prof, :inst, :desc)
                    RETURNING id_plan
                ");
                $stmt_i->execute([
                    ':coh' => $id_cohorte, ':mat' => $id_materia, ':tit' => $titulo,
                    ':tipo' => $tipo_p, ':peso' => $peso,
                    ':fec' => ($fecha !== '' ? $fecha : null), ':prof' => $id_profesor,
                    ':inst' => $instrumento, ':desc' => $descripcion,
                ]);
                $plan_guardado = (int) $stmt_i->fetchColumn();
                $accion_bitacora = 'PLAN_CREAR';
            }

            evaluacion_guardar_contenidos($pdo, $plan_guardado, $contenidos_plan);
            $pdo->commit();

            docente_registrar_bitacora($pdo, "{$accion_bitacora} cohorte:{$id_cohorte} materia:{$id_materia} id_plan:{$plan_guardado} peso:{$peso} contenidos:" . count($contenidos_plan));
            $msg = ($id_plan_editar > 0) ? 'edit' : 'ok';
            echo "<script>window.location.replace('plan_evaluacion.php?id_cohorte={$id_cohorte}&id_materia={$id_materia}&msg={$msg}');</script>";
            exit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $e->getMessage();
        }
    }

    if (isset($_POST['eliminar_evaluacion'])) {
        try {
            $id_plan = intval($_POST['id_plan'] ?? 0);
            if ($id_plan <= 0 || !docente_plan_pertenece($pdo, $id_plan, $id_materia, $id_cohorte)) {
                throw new Exception('La evaluación indicada no pertenece a esta sección/materia.');
            }

            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM public.notas_estudiantes WHERE id_plan = :id")
                ->execute([':id' => $id_plan]);

            $stmt_d = $pdo->prepare("
                DELETE FROM public.plan_evaluacion
                 WHERE id_plan = :id AND id_cohorte = :coh AND id_materia = :mat
            ");
            $stmt_d->execute([':id' => $id_plan, ':coh' => $id_cohorte, ':mat' => $id_materia]);
            if ($stmt_d->rowCount() < 1) {
                throw new Exception('No se pudo eliminar la evaluación.');
            }
            $pdo->commit();

            docente_registrar_bitacora($pdo, "PLAN_ELIMINAR cohorte:{$id_cohorte} materia:{$id_materia} id_plan:{$id_plan}");
            echo "<script>window.location.replace('plan_evaluacion.php?id_cohorte={$id_cohorte}&id_materia={$id_materia}&msg=del');</script>";
            exit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = $e->getMessage();
        }
    }
}

// Consultar evaluaciones y sus contenidos
$stmt_p = $pdo->prepare("
    SELECT * FROM public.plan_evaluacion
     WHERE id_cohorte = :coh AND id_materia = :mat
     ORDER BY fecha_evaluacion ASC NULLS LAST, id_plan ASC
");
$stmt_p->execute([':coh' => $id_cohorte, ':mat' => $id_materia]);
$planes = $stmt_p->fetchAll(PDO::FETCH_ASSOC);

$suma_actual = 0.0;
$contenidos_por_plan = [];
foreach ($planes as $pl) {
    $suma_actual += (float) $pl['porcentaje_peso'];
    $contenidos_por_plan[(int) $pl['id_plan']] = evaluacion_contenidos_plan($pdo, (int) $pl['id_plan']);
}
$disp = round(100.00 - $suma_actual, 2);

// Modo edición
$plan_editar = null;
$id_editar = intval($_GET['editar'] ?? 0);
if ($id_editar > 0) {
    foreach ($planes as $pl) {
        if ((int) $pl['id_plan'] === $id_editar) { $plan_editar = $pl; break; }
    }
}

$val = [
    'titulo'      => $plan_editar['titulo_evaluacion'] ?? '',
    'tipo'        => $plan_editar['tipo_ponderacion'] ?? 'Cuantitativa',
    'peso'        => $plan_editar ? (float) $plan_editar['porcentaje_peso'] : min(25, max(0, $disp)),
    'fecha'       => $plan_editar['fecha_evaluacion'] ?? date('Y-m-d'),
    'instrumento' => $plan_editar['instrumento_evaluacion'] ?? '',
    'descripcion' => $plan_editar['descripcion_libre'] ?? '',
];
$marcados_iniciales = [];
if ($plan_editar) {
    foreach ($contenidos_por_plan[(int) $plan_editar['id_plan']] as $cp) {
        $marcados_iniciales[(int) $cp['id_contenido']] = true;
    }
}
$disp_form = round($disp + ($plan_editar ? (float) $plan_editar['porcentaje_peso'] : 0), 2);
$es_instrumento_otro = !in_array($val['instrumento'], $instrumentos, true) && $val['instrumento'] !== '';
$url_base = "plan_evaluacion.php?id_cohorte={$id_cohorte}&id_materia={$id_materia}";
$url_pdf  = "plan_evaluacion_pdf.php?id_cohorte={$id_cohorte}&id_materia={$id_materia}";
?>
<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <div>
        <h1 class="h3 mb-0 font-weight-bold" style="color: #521f29;">
            <i class="fas fa-tasks mr-2 text-warning"></i>Plan de Evaluación por Contenidos
        </h1>
        <span class="text-muted font-weight-bold d-block mt-1">
            Carrera / Programa: <?= htmlspecialchars($info['nombre_programa']) ?>
            <?php if (!empty($info['tipo_oferta'])): ?> · <?= htmlspecialchars($info['tipo_oferta']) ?><?php endif; ?><br>
            Asignatura: Módulo <?= htmlspecialchars($info['modulo_num']) ?> — <?= htmlspecialchars($info['nombre_materia']) ?>
            | Sección: <?= htmlspecialchars($info['codigo_cohorte']) ?> (<?= htmlspecialchars($info['salon_aula']) ?>)
            | Profesor: <?= htmlspecialchars(evaluacion_nombre_profesor($pdo, $plan_editar ? (int)$plan_editar['id_plan'] : 0, $id_materia, $id_cohorte)) ?>
        </span>
    </div>
    <div class="no-print">
        <a href="<?= $url_pdf ?>" target="_blank" class="btn btn-sm font-weight-bold text-white shadow-sm" style="background:#521f29;">
            <i class="fas fa-file-pdf mr-1"></i> Descargar plan PDF
        </a>
        <a href="sabana_notas.php?id_cohorte=<?= $id_cohorte ?>&id_materia=<?= $id_materia ?>" class="btn btn-sm font-weight-bold text-dark shadow-sm" style="background-color: #ff8a00;"><i class="fas fa-table mr-1"></i> Sábana de notas</a>
        <a href="index.php" class="btn btn-sm btn-secondary font-weight-bold shadow-sm"><i class="fas fa-arrow-left mr-1"></i> Volver</a>
    </div>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger font-weight-bold shadow-sm"><i class="fas fa-exclamation-triangle mr-2"></i><?= htmlspecialchars($error) ?></div>
<?php endif; ?>
<?php if (($_GET['msg'] ?? '') === 'ok'): ?>
    <div class="alert alert-success font-weight-bold shadow-sm"><i class="fas fa-check-circle mr-2"></i>Evaluación creada correctamente.</div>
<?php elseif (($_GET['msg'] ?? '') === 'edit'): ?>
    <div class="alert alert-success font-weight-bold shadow-sm"><i class="fas fa-check-circle mr-2"></i>Evaluación actualizada correctamente.</div>
<?php elseif (($_GET['msg'] ?? '') === 'del'): ?>
    <div class="alert alert-info font-weight-bold shadow-sm"><i class="fas fa-trash-alt mr-2"></i>Evaluación eliminada correctamente.</div>
<?php endif; ?>

<div class="row">
    <div class="col-lg-5 mb-4">
        <div class="card shadow border-0" style="border-radius: 15px;">
            <div class="card-header text-white font-weight-bold d-flex justify-content-between align-items-center" style="background-color: #521f29; border-top-left-radius: 15px; border-top-right-radius: 15px;">
                <span><?= $plan_editar ? 'Editar evaluación' : 'Planificar evaluación' ?></span>
                <span class="badge badge-warning text-dark"><?= count($planes) ?> / 4</span>
            </div>
            <div class="card-body bg-white p-4">
                <form method="POST" action="<?= $url_base ?>" id="formPlan">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id_plan" value="<?= $plan_editar ? (int)$plan_editar['id_plan'] : 0 ?>">

                    <div class="form-group mb-2">
                        <label class="font-weight-bold small" style="color:#521f29;">Título de la evaluación *</label>
                        <input type="text" name="titulo_evaluacion" maxlength="200" class="form-control font-weight-bold"
                               placeholder="Ej: Evaluación del patronaje plano" value="<?= htmlspecialchars($val['titulo']) ?>" required>
                    </div>

                    <div class="form-row">
                        <div class="form-group col-md-6 mb-2">
                            <label class="font-weight-bold small" style="color:#521f29;">Modo de calificación *</label>
                            <select name="tipo_ponderacion" class="form-control font-weight-bold">
                                <option value="Cuantitativa" <?= $val['tipo'] === 'Cuantitativa' ? 'selected' : '' ?>>Cuantitativa (1–20)</option>
                                <option value="Cualitativa" <?= $val['tipo'] === 'Cualitativa' ? 'selected' : '' ?>>Cualitativa</option>
                            </select>
                        </div>
                        <div class="form-group col-md-6 mb-2">
                            <label class="font-weight-bold small" style="color:#521f29;">Fecha de evaluación</label>
                            <input type="date" name="fecha_evaluacion" class="form-control font-weight-bold" value="<?= htmlspecialchars($val['fecha']) ?>">
                        </div>
                    </div>

                    <div class="form-group mb-2">
                        <label class="font-weight-bold small" style="color:#521f29;">Instrumento de evaluación *</label>
                        <select name="instrumento_evaluacion" id="instrumentoSelect" class="form-control font-weight-bold">
                            <?php foreach ($instrumentos as $ins): ?>
                                <option value="<?= htmlspecialchars($ins) ?>" <?= $val['instrumento'] === $ins ? 'selected' : '' ?>><?= htmlspecialchars($ins) ?></option>
                            <?php endforeach; ?>
                            <?php if ($es_instrumento_otro): ?>
                                <option value="Otro" selected>Otro (personalizado)</option>
                            <?php endif; ?>
                        </select>
                        <input type="text" name="instrumento_otro" id="instrumentoOtro"
                               class="form-control form-control-sm mt-1 <?= $es_instrumento_otro ? '' : 'd-none' ?>"
                               placeholder="Especifique el instrumento" maxlength="120"
                               value="<?= $es_instrumento_otro ? htmlspecialchars($val['instrumento']) : '' ?>">
                    </div>

                    <div class="form-group mb-2">
                        <label class="font-weight-bold small" style="color:#521f29;">Descripción libre de la evaluación</label>
                        <textarea name="descripcion_libre" rows="3" class="form-control"
                                  placeholder="Criterios, indicadores, condiciones y desarrollo de la evaluación"><?= htmlspecialchars($val['descripcion']) ?></textarea>
                    </div>

                    <div class="form-group mb-2">
                        <label class="font-weight-bold small" style="color:#521f29;">Peso de esta evaluación en la nota final — Disponible: <?= $disp_form ?>% *</label>
                        <input type="number" step="0.01" min="0.01" max="100" name="porcentaje_peso" id="pesoEvaluacion"
                               class="form-control font-weight-bold" value="<?= number_format($val['peso'], 2, '.', '') ?>" required
                               <?= ($disp_form <= 0 && !$plan_editar) ? 'disabled' : '' ?>>
                    </div>

                    <hr>
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <label class="font-weight-bold small mb-0" style="color:#521f29;">Contenidos a evaluar * (marque de 1 a todos)</label>
                        <div>
                            <button type="button" class="btn btn-xs btn-outline-primary py-0 px-1" id="marcarTodos">Marcar todos</button>
                            <button type="button" class="btn btn-xs btn-outline-secondary py-0 px-1" id="desmarcarTodos">Desmarcar</button>
                        </div>
                    </div>

                    <?php if (empty($contenidos)): ?>
                        <div class="alert alert-warning small">Esta asignatura aún no tiene contenidos registrados en la malla.</div>
                    <?php else: ?>
                        <div class="border rounded p-2" style="max-height:260px;overflow:auto;background:#fdfaf6;">
                            <?php foreach ($contenidos as $c): ?>
                                <?php
                                    $cid = (int) $c['id_contenido'];
                                    $marcado = isset($marcados_iniciales[$cid]);
                                ?>
                                <div class="form-check d-flex align-items-center mb-2 contenido-fila">
                                    <input class="form-check-input mr-2 chk-contenido" type="checkbox"
                                           name="contenidos[]" value="<?= $cid ?>" id="cont_<?= $cid ?>"
                                           <?= $marcado ? 'checked' : '' ?>>
                                    <label class="form-check-label small flex-grow-1" for="cont_<?= $cid ?>">
                                        <?= htmlspecialchars($c['descripcion']) ?>
                                        <small class="text-muted">(<?= (int)$c['horas_academicas'] ?> hrs)</small>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <button type="submit" name="guardar_evaluacion" class="btn btn-block font-weight-bold text-white shadow mt-3"
                            style="background-color:#ff8a00; color:#521f29 !important;"
                            <?= ($disp_form <= 0 && !$plan_editar) || empty($contenidos) ? 'disabled' : '' ?>>
                        <i class="fas fa-save mr-1"></i> <?= $plan_editar ? 'ACTUALIZAR EVALUACIÓN' : 'GUARDAR EVALUACIÓN' ?>
                    </button>
                    <?php if ($plan_editar): ?>
                        <a href="<?= $url_base ?>" class="btn btn-block btn-sm btn-outline-secondary mt-1">Cancelar edición</a>
                    <?php endif; ?>
                    <small class="form-text text-muted font-weight-bold">
                        Máximo 4 evaluaciones por asignatura/sección. Marque un contenido o todos según lo que abarque la evaluación; la ponderación se define solo para la evaluación.
                    </small>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-7 mb-4">
        <div class="card shadow border-0" style="border-radius: 15px;">
            <div class="card-header text-white font-weight-bold d-flex justify-content-between align-items-center" style="background-color:#521f29; border-top-left-radius:15px; border-top-right-radius:15px;">
                <span>Plan de Evaluación</span>
                <span class="badge badge-warning text-dark font-weight-bold px-3 py-2">Suma: <?= number_format($suma_actual, 2) ?>% / 100%</span>
            </div>
            <div class="card-body bg-white p-4">
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead style="background-color:#ff8a00; color:#521f29;">
                            <tr>
                                <th>#</th>
                                <th>Evaluación / Instrumento</th>
                                <th>Contenidos evaluados</th>
                                <th>Peso</th>
                                <th>Fecha</th>
                                <th class="text-center no-print">Acción</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($planes)): ?>
                                <tr><td colspan="6" class="text-center text-muted font-weight-bold py-3">No hay evaluaciones planificadas para esta sección.</td></tr>
                            <?php else: ?>
                                <?php foreach ($planes as $i => $pl): ?>
                                    <?php $cps = $contenidos_por_plan[(int)$pl['id_plan']] ?? []; ?>
                                    <tr>
                                        <td class="font-weight-bold"><?= $i + 1 ?></td>
                                        <td>
                                            <span class="font-weight-bold" style="color:#521f29;"><?= htmlspecialchars($pl['titulo_evaluacion']) ?></span><br>
                                            <small class="text-muted"><?= htmlspecialchars($pl['instrumento_evaluacion'] ?: 'Sin instrumento') ?></small>
                                            <?php if (!empty($pl['descripcion_libre'])): ?>
                                                <br><small class="text-muted font-italic"><?= htmlspecialchars(mb_strimwidth($pl['descripcion_libre'], 0, 90, '…')) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (empty($cps)): ?>
                                                <span class="text-muted small">Sin contenidos (plan anterior)</span>
                                            <?php else: ?>
                                                <?php foreach ($cps as $cp): ?>
                                                    <div class="small">
                                                        <i class="fas fa-check text-success mr-1"></i>
                                                        <?= htmlspecialchars($cp['descripcion']) ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            <?php endif; ?>
                                        </td>
                                        <td class="font-weight-bold text-primary"><?= number_format((float)$pl['porcentaje_peso'], 2) ?>%</td>
                                        <td><?= !empty($pl['fecha_evaluacion']) ? date('d/m/Y', strtotime($pl['fecha_evaluacion'])) : '--' ?></td>
                                        <td class="text-center no-print">
                                            <div class="d-flex justify-content-center">
                                                <a href="<?= $url_base ?>&editar=<?= (int)$pl['id_plan'] ?>" class="btn btn-sm btn-warning text-dark font-weight-bold mr-1" title="Editar"><i class="fas fa-edit"></i></a>
                                                <a href="<?= $url_pdf ?>&id_plan=<?= (int)$pl['id_plan'] ?>" target="_blank" class="btn btn-sm btn-outline-danger font-weight-bold mr-1" title="Descargar PDF"><i class="fas fa-file-pdf"></i></a>
                                                <form method="POST" action="<?= $url_base ?>" onsubmit="return confirm('¿Eliminar esta evaluación?');">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="id_plan" value="<?= (int)$pl['id_plan'] ?>">
                                                    <button type="submit" name="eliminar_evaluacion" class="btn btn-sm btn-danger font-weight-bold py-0"><i class="fas fa-trash-alt"></i></button>
                                                </form>
                                            </div>
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

<script>
(function () {
    var chk = Array.prototype.slice.call(document.querySelectorAll('.chk-contenido'));
    var sel = document.getElementById('instrumentoSelect');
    var otro = document.getElementById('instrumentoOtro');

    var todos = document.getElementById('marcarTodos');
    if (todos) {
        todos.addEventListener('click', function () {
            chk.forEach(function (c) { c.checked = true; });
        });
    }
    var ninguno = document.getElementById('desmarcarTodos');
    if (ninguno) {
        ninguno.addEventListener('click', function () {
            chk.forEach(function (c) { c.checked = false; });
        });
    }
    if (sel && otro) {
        sel.addEventListener('change', function () {
            otro.classList.toggle('d-none', sel.value !== 'Otro');
            if (sel.value === 'Otro') { otro.focus(); }
        });
    }
})();
</script>

<?php require_once '../../includes/footer.php'; ?>
