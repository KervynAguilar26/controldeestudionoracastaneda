<?php
// /sistema_nora/modulos/control_estudio/gestionar_horarios.php
error_reporting(E_ALL);
ini_set('display_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Inyección preventiva del token para evitar el bloqueo del validador central
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['actualizar_matriz_horario'])) {
    if (empty($_POST['_csrf']) && !empty($_SESSION['csrf_token'])) {
        $_POST['_csrf'] = $_SESSION['csrf_token'];
    }
}

require_once '../../config/db.php';
require_once '../../includes/disponibilidad_docente.php';
require_once '../../includes/horario_generator.php';
require_once '../../includes/espacios_disponibilidad.php';

if (empty($_SESSION['id_usuario'])) {
    header('Location: ../../index.php');
    exit();
}

$id_cohorte_sel = intval($_GET['id_cohorte'] ?? $_POST['id_cohorte'] ?? 0);
$msg = '';
$err = '';
$cohortes = [];
$cohorte_actual = null;
$materias_cohorte = [];
$matriz_cargada = [];
$total_horas_malla = 0;
$profesores_ocupados_map = [];
$materia_profesor_map = [];
$profesores_nombres = [];
$resumen_docentes = [];
$carga_base_docentes = [];
$carga_matriz_docentes = [];
$celda_profesor = [];
$celda_horas = [];
$bloque_por_inicio = [];
$salon_estado_celdas = [];

// BLOQUES DE HORARIOS AMPLIADOS (catálogo único compartido con la disponibilidad docente)
$bloques_estandar = docente_bloques();
foreach ($bloques_estandar as $be) {
    $bloque_por_inicio[$be['inicio']] = $be;
}

try {
    // 1. PRIMERO: CONSULTAR TODAS LAS SECCIONES (Para que el selector siempre tenga datos)
    $cohortes = $pdo->query("
        SELECT c.id_cohorte, c.codigo_cohorte, c.salon_aula, c.num_periodo, c.dias_clase, c.hora_inicio, c.hora_fin, 
               COALESCE(m.nombre_programa, 'Sin Malla Asociada') AS nombre_programa
        FROM public.cohortes c
        LEFT JOIN public.mallas_curriculares m ON c.id_malla = m.id_malla
        WHERE c.estatus IN ('Planificada', 'En Curso')
        ORDER BY c.codigo_cohorte ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // 2. SEGUNDO: PROCESAR GUARDADO DE LA MATRIZ DE HORARIO
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['actualizar_matriz_horario'])) {
        $id_cohorte = intval($_POST['id_cohorte'] ?? 0);
        $asignaciones = $_POST['celda_materia'] ?? []; 

        if ($id_cohorte <= 0) {
            $err = "Debe seleccionar una sección válida del menú desplegable antes de guardar.";
        } else {
            $stmt_c_info = $pdo->prepare("SELECT salon_aula, fecha_inicio, fecha_fin_estimada FROM public.cohortes WHERE id_cohorte = :id");
            $stmt_c_info->execute([':id' => $id_cohorte]);
            $info_cohorte = $stmt_c_info->fetch(PDO::FETCH_ASSOC) ?: [];
            $salon_aula = $info_cohorte['salon_aula'] ?? 'Por Definir';
            $fecha_ini_guardado = (string) ($info_cohorte['fecha_inicio'] ?? date('Y-m-d'));
            $fecha_fin_guardado = (string) ($info_cohorte['fecha_fin_estimada'] ?? $fecha_ini_guardado);

            // 2a. Resolver docente y construir propuestas antes de escribir
            $propuestas = [];
            $stmt_prof = $pdo->prepare("
                SELECT id_profesor FROM public.asignaciones_profesor 
                WHERE id_materia = :mat AND (id_cohorte = :coh OR id_cohorte IS NULL)
                ORDER BY id_cohorte DESC LIMIT 1
            ");
            $stmt_nom_mat = $pdo->prepare("SELECT nombre_materia FROM public.malla_materias WHERE id_materia = :m");

            foreach ($asignaciones as $dia => $bloques_dia) {
                if (!is_array($bloques_dia)) {
                    continue;
                }
                foreach ($bloques_dia as $h_inicio => $val_materia) {
                    $h_inicio = docente_normalizar_hora($h_inicio);
                    if (empty($val_materia) || $val_materia === '0' || strpos((string) $val_materia, 'SERV_') === 0) {
                        continue;
                    }
                    $id_materia = intval($val_materia);
                    if ($id_materia <= 0 || !isset($bloque_por_inicio[$h_inicio])) {
                        continue;
                    }
                    $h_fin = $bloque_por_inicio[$h_inicio]['fin'];

                    $stmt_prof->execute([':mat' => $id_materia, ':coh' => $id_cohorte]);
                    $id_profesor = $stmt_prof->fetchColumn() ?: null;

                    $stmt_nom_mat->execute([':m' => $id_materia]);
                    $nom_mat = $stmt_nom_mat->fetchColumn() ?: "Materia #{$id_materia}";

                    $propuestas[] = [
                        'id_materia'  => $id_materia,
                        'id_profesor' => $id_profesor ? intval($id_profesor) : null,
                        'materia'     => $nom_mat,
                        'dia'         => $dia,
                        'inicio'      => $h_inicio,
                        'fin'         => $h_fin,
                    ];
                }
            }

            // 2b. Guardado atómico con validación docente en cada bloque
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM public.horarios_secciones WHERE id_cohorte = :id")->execute([':id' => $id_cohorte]);

            $stmt_ins = $pdo->prepare("
                INSERT INTO public.horarios_secciones (id_cohorte, id_materia, id_profesor, dia_semana, hora_inicio, hora_fin, salon_aula) 
                VALUES (:coh, :mat, :prof, :dia, :ini, :fin, :sal)
            ");

            foreach ($propuestas as $prop) {
                if (!empty($prop['id_profesor'])) {
                    $validacion = docente_validar_bloque(
                        $pdo,
                        (int) $prop['id_profesor'],
                        $prop['dia'],
                        $prop['inicio'],
                        $prop['fin'],
                        $salon_aula
                    );
                    if (!$validacion['ok']) {
                        throw new Exception(
                            "No se pudo cargar {$prop['materia']} el día {$prop['dia']} a las " .
                            docente_hora_formato($prop['inicio']) . ": " . $validacion['mensaje']
                        );
                    }
                }

                if ($salon_aula !== '' && $salon_aula !== 'Por Definir') {
                    $estado_salon = espacios_estado(
                        $pdo,
                        $salon_aula,
                        [$prop['dia']],
                        $prop['inicio'],
                        $prop['fin'],
                        $fecha_ini_guardado,
                        $fecha_fin_guardado,
                        null,
                        $id_cohorte
                    );
                    if (!empty($estado_salon['bloqueado'])) {
                        throw new Exception(
                            "El salón '{$salon_aula}' está bloqueado el día {$prop['dia']} a las " .
                            docente_hora_formato($prop['inicio']) . ': ' . $estado_salon['motivo']
                        );
                    }
                }

                $stmt_ins->execute([
                    ':coh'  => $id_cohorte,
                    ':mat'  => $prop['id_materia'],
                    ':prof' => $prop['id_profesor'],
                    ':dia'  => $prop['dia'],
                    ':ini'  => $prop['inicio'],
                    ':fin'  => $prop['fin'],
                    ':sal'  => $salon_aula
                ]);
            }

            $pdo->commit();
            $msg = "¡Horario validado y actualizado exitosamente! (" . count($propuestas) . " bloques cargados)";
        }
    }

    // 3. TERCERO: CARGAR LOS DATOS DE LA SECCIÓN SELECCIONADA
    if ($id_cohorte_sel > 0) {
        $stmt_c = $pdo->prepare("SELECT c.*, m.nombre_programa, m.tipo_regimen FROM public.cohortes c JOIN public.mallas_curriculares m ON c.id_malla = m.id_malla WHERE c.id_cohorte = :id");
        $stmt_c->execute([':id' => $id_cohorte_sel]);
        $cohorte_actual = $stmt_c->fetch(PDO::FETCH_ASSOC);

        if ($cohorte_actual) {
            $salon_cohorte = (string) ($cohorte_actual['salon_aula'] ?? '');
            $fecha_ini_cohorte = (string) ($cohorte_actual['fecha_inicio'] ?? date('Y-m-d'));
            $fecha_fin_cohorte = (string) ($cohorte_actual['fecha_fin_estimada'] ?? $fecha_ini_cohorte);

            $stmt_m = $pdo->prepare("SELECT id_materia, nombre_materia, horas_academicas FROM public.malla_materias WHERE id_malla = :malla AND modulo_num = :periodo ORDER BY nombre_materia ASC");
            $stmt_m->execute([':malla' => $cohorte_actual['id_malla'], ':periodo' => $cohorte_actual['num_periodo']]);
            $materias_cohorte = $stmt_m->fetchAll(PDO::FETCH_ASSOC);

            foreach ($materias_cohorte as $mc) {
                $total_horas_malla += intval($mc['horas_academicas']);
            }

            $stmt_h = $pdo->prepare("SELECT dia_semana, hora_inicio, id_materia FROM public.horarios_secciones WHERE id_cohorte = :id");
            $stmt_h->execute([':id' => $id_cohorte_sel]);
            foreach ($stmt_h->fetchAll(PDO::FETCH_ASSOC) as $h) {
                $h_ini = date('H:i:s', strtotime($h['hora_inicio']));
                $matriz_cargada[$h['dia_semana']][$h_ini] = $h['id_materia'];
            }
        }

        $sql_todos_horarios = "
            SELECT hs.dia_semana, hs.hora_inicio, hs.hora_fin, hs.id_profesor, hs.salon_aula,
                   co.codigo_cohorte, mm.nombre_materia
            FROM public.horarios_secciones hs
            LEFT JOIN public.cohortes co ON co.id_cohorte = hs.id_cohorte
            LEFT JOIN public.malla_materias mm ON mm.id_materia = hs.id_materia
            WHERE hs.id_cohorte != :id_c AND hs.id_profesor IS NOT NULL
        ";
        $stmt_to = $pdo->prepare($sql_todos_horarios);
        $stmt_to->execute([':id_c' => $id_cohorte_sel]);
        foreach ($stmt_to->fetchAll(PDO::FETCH_ASSOC) as $ot) {
            $profesores_ocupados_map[$ot['dia_semana']][] = [
                'profesor' => intval($ot['id_profesor']),
                'inicio'   => docente_normalizar_hora($ot['hora_inicio']),
                'fin'      => docente_normalizar_hora($ot['hora_fin']),
                'seccion'  => $ot['codigo_cohorte'] ?? '',
                'salon'    => $ot['salon_aula'] ?? '',
                'materia'  => $ot['nombre_materia'] ?? '',
            ];
        }

        if (!empty($materias_cohorte)) {
            foreach ($materias_cohorte as $mat) {
                $stmt_mp = $pdo->prepare("
                    SELECT ap.id_profesor, u.nombre, u.apellido 
                    FROM public.asignaciones_profesor ap
                    LEFT JOIN public.usuarios u ON ap.id_profesor = u.id_usuario
                    WHERE ap.id_materia = :mat AND (ap.id_cohorte = :coh OR ap.id_cohorte IS NULL)
                    ORDER BY ap.id_cohorte DESC LIMIT 1
                ");
                $stmt_mp->execute([':mat' => $mat['id_materia'], ':coh' => $id_cohorte_sel]);
                $res_p = $stmt_mp->fetch(PDO::FETCH_ASSOC);

                if ($res_p && !empty($res_p['id_profesor'])) {
                    $materia_profesor_map[$mat['id_materia']] = intval($res_p['id_profesor']);
                    $profesores_nombres[$mat['id_materia']] = trim($res_p['nombre'] . ' ' . $res_p['apellido']);
                } else {
                    $materia_profesor_map[$mat['id_materia']] = null;
                    $profesores_nombres[$mat['id_materia']] = 'Por Definir';
                }
            }
        }

        // Resumen de carga académica y disponibilidad de cada docente interviniente
        foreach ($materia_profesor_map as $id_prof) {
            if (empty($id_prof) || isset($resumen_docentes[$id_prof])) {
                continue;
            }
            $u = docente_datos($pdo, (int) $id_prof);
            if (!$u) {
                continue;
            }
            $resumen_docentes[$id_prof] = [
                'nombre'        => docente_nombre($u),
                'min'           => $u['horas_semanales_min'],
                'max'           => $u['horas_semanales_max'],
                'disponibilidad'=> $u['disponibilidad'],
                'total'         => docente_horas_cargadas($pdo, (int) $id_prof)['total'],
                'otras'         => docente_horas_cargadas($pdo, (int) $id_prof, [], $id_cohorte_sel)['total'],
            ];
            $carga_base_docentes[$id_prof] = $resumen_docentes[$id_prof]['otras'];
        }

        // Horas ya cargadas dentro de la matriz que se está editando.
        // Se guarda también el docente de cada celda para no contar dos veces
        // el bloque que se está reemplazando al calcular la carga proyectada.
        foreach ($matriz_cargada as $dia => $bloques) {
            foreach ($bloques as $ini => $id_mat) {
                $pid = $materia_profesor_map[$id_mat] ?? null;
                if (empty($pid) || !isset($bloque_por_inicio[$ini])) {
                    continue;
                }
                $h = docente_horas_bloque($ini, $bloque_por_inicio[$ini]['fin']);
                $carga_matriz_docentes[$pid] = ($carga_matriz_docentes[$pid] ?? 0) + $h;
                $celda_profesor[$dia][$ini] = (int) $pid;
                $celda_horas[$dia][$ini] = $h;
            }
        }
    }
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $err = $e->getMessage();
}

require_once '../../includes/header.php';

$cohorte_h_ini_seg = ($cohorte_actual && !empty($cohorte_actual['hora_inicio'])) ? strtotime($cohorte_actual['hora_inicio']) - 1800 : strtotime('07:30:00');
$cohorte_h_fin_seg = ($cohorte_actual && !empty($cohorte_actual['hora_fin'])) ? strtotime($cohorte_actual['hora_fin']) + 1800 : strtotime('13:00:00');

// Los días de la matriz son los de la cohorte: "Sábado y Domingo" habilita
// ambas columnas, siempre que el docente tenga disponibilidad en esos bloques.
$dias_semana = $cohorte_actual
    ? horario_dias_desde_texto((string) ($cohorte_actual['dias_clase'] ?? ''))
    : ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes'];
?>

<div class="container-fluid pt-3">
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 font-weight-bold" style="color: #521f29;">
            <i class="fas fa-calendar-alt mr-2 text-warning"></i>Cuadre de Horarios y Validación de Docentes
        </h1>
        <a href="index.php" class="btn btn-sm btn-secondary font-weight-bold shadow-sm">
            <i class="fas fa-arrow-left mr-1"></i> Volver a Control de Estudio
        </a>
    </div>

    <?php if (!empty($msg)): ?><div class="alert alert-success font-weight-bold shadow-sm mb-4"><i class="fas fa-check-circle mr-2"></i><?= htmlspecialchars($msg) ?></div><?php endif; ?>
    <?php if (!empty($err)): ?><div class="alert alert-danger font-weight-bold shadow-sm mb-4"><i class="fas fa-exclamation-triangle mr-2"></i><?= htmlspecialchars($err) ?></div><?php endif; ?>

    <!-- SELECTOR DE SECCIÓN -->
    <div class="card shadow border-0 mb-4" style="border-radius: 12px;">
        <div class="card-body bg-white p-3">
            <form method="GET" action="gestionar_horarios.php" class="row align-items-center">
                <div class="col-md-8 form-group mb-0">
                    <label class="font-weight-bold text-dark mb-1"><i class="fas fa-layer-group text-warning mr-1"></i> Seleccionar Sección Registrada:</label>
                    <select name="id_cohorte" class="form-control font-weight-bold" onchange="this.form.submit()" required>
                        <option value="">-- Seleccionar Sección --</option>
                        <?php foreach ($cohortes as $c): ?>
                            <option value="<?= $c['id_cohorte'] ?>" <?= $id_cohorte_sel == $c['id_cohorte'] ? 'selected' : '' ?>>
                                [<?= htmlspecialchars($c['codigo_cohorte']) ?>] — <?= htmlspecialchars($c['nombre_programa']) ?> (Nivel <?= $c['num_periodo'] ?>) — Horario Base: <?= !empty($c['hora_inicio']) ? date('h:i A', strtotime($c['hora_inicio'])) : 'N/A' ?> a <?= !empty($c['hora_fin']) ? date('h:i A', strtotime($c['hora_fin'])) : 'N/A' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($id_cohorte_sel > 0): ?>
                    <div class="col-md-4 text-right pt-3">
                        <a href="horario_salones.php?id_cohorte=<?= $id_cohorte_sel ?>" target="_blank" class="btn font-weight-bold text-white shadow-sm py-2 px-3" style="background-color: #2b5876; border-radius: 8px;">
                            <i class="fas fa-print mr-1"></i> Ver / Imprimir Horario
                        </a>
                    </div>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <?php if ($cohorte_actual): ?>
        <div class="row mb-4">
            <div class="col-md-6">
                <div class="card shadow border-left-warning py-2" style="border-left: 5px solid #ff8a00 !important; border-radius: 12px;">
                    <div class="card-body py-2">
                        <div class="row align-items-center">
                            <div class="col">
                                <div class="text-xs font-weight-bold text-uppercase mb-1" style="color: #521f29;">Horas totales del plan de estudio (informativas)</div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $total_horas_malla ?> h del programa</div>
                                <small class="text-muted">No inciden en la jornada semanal del docente.</small>
                            </div>
                            <div class="col-auto"><i class="fas fa-book fa-2x text-gray-300"></i></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card shadow border-left-success py-2" style="border-left: 5px solid #2b5876 !important; border-radius: 12px;">
                    <div class="card-body py-2">
                        <div class="row align-items-center">
                            <div class="col">
                                <div class="text-xs font-weight-bold text-uppercase mb-1 text-info">Rango Permitido (con tolerancia ±30 min)</div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800">
                                    <?= !empty($cohorte_actual['hora_inicio']) ? date('h:i A', strtotime($cohorte_actual['hora_inicio'])) : '08:00 AM' ?> 
                                    a 
                                    <?= !empty($cohorte_actual['hora_fin']) ? date('h:i A', strtotime($cohorte_actual['hora_fin'])) : '12:30 PM' ?>
                                </div>
                            </div>
                            <div class="col-auto"><i class="fas fa-clock fa-2x text-gray-300"></i></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php if (!empty($resumen_docentes)): ?>
            <div class="card shadow border-0 mb-4" style="border-radius: 12px;">
                <div class="card-header text-white font-weight-bold py-2" style="background-color: #2b5876;">
                    <i class="fas fa-chart-bar mr-2 text-warning"></i>Resumen de Carga Académica de los Docentes de esta Sección
                </div>
                <div class="card-body bg-white p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-bordered mb-0 text-center">
                            <thead class="bg-light">
                                <tr>
                                    <th class="text-left">Docente</th>
                                    <th>Rango contratado</th>
                                    <th>Otras secciones</th>
                                    <th>En esta matriz</th>
                                    <th>Total (proyectado)</th>
                                    <th>Disponibilidad</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($resumen_docentes as $pid => $rd):
                                    $en_matriz  = $carga_matriz_docentes[$pid] ?? 0;
                                    $proyectado = $rd['otras'] + $en_matriz;
                                    $excedido   = $proyectado > $rd['max'];
                                ?>
                                    <tr class="<?= $excedido ? 'table-danger' : '' ?>">
                                        <td class="font-weight-bold text-left"><?= htmlspecialchars($rd['nombre']) ?></td>
                                        <td><?= $rd['min'] ?> - <?= $rd['max'] ?> h</td>
                                        <td><?= $rd['otras'] ?> h</td>
                                        <td><?= $en_matriz ?> h</td>
                                        <td class="font-weight-bold <?= $excedido ? 'text-danger' : 'text-success' ?>">
                                            <?= $proyectado ?> / <?= $rd['max'] ?> h
                                            <?php if ($excedido): ?><span class="d-block small">⚠️ EXCEDIDO</span><?php endif; ?>
                                        </td>
                                        <td>
                                            <?php $horas_disp = docente_horas_disponibles($rd['disponibilidad']); ?>
                                            <?php if ($horas_disp === null): ?>
                                                <span class="badge badge-secondary">Sin restricción (todos los bloques)</span>
                                            <?php else: ?>
                                                <span class="badge badge-info"><?= count($rd['disponibilidad']) ?> días · <?= $horas_disp ?> h declaradas</span>
                                                <small class="d-block text-muted">Carga actual: <?= $rd['otras'] + $en_matriz ?> h / <?= $rd['max'] ?> h</small>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <form method="POST" action="gestionar_horarios.php">
            <input type="hidden" name="id_cohorte" value="<?= $id_cohorte_sel ?>">
            
            <?= function_exists('csrf_field') ? csrf_field() : '<input type="hidden" name="_csrf" value="' . ($_SESSION['csrf_token'] ?? '') . '">' ?>

            <div class="card shadow border-0 mb-4" style="border-radius: 15px;">
                <div class="card-header text-white font-weight-bold d-flex justify-content-between align-items-center" style="background-color: #521f29; border-top-left-radius: 15px; border-top-right-radius: 15px;">
                    <span><i class="fas fa-table mr-2 text-warning"></i>Cuadro Matriz — Sección: <?= htmlspecialchars($cohorte_actual['codigo_cohorte']) ?></span>
                    <button type="submit" name="actualizar_matriz_horario" class="btn font-weight-bold text-white shadow-sm py-2 px-4" style="background-color: #ff8a00; border-radius: 8px;">
                        <i class="fas fa-save mr-1"></i> GUARDAR CAMBIOS
                    </button>
                </div>
                <div class="card-body bg-white p-3">
                    <div class="alert alert-light border small mb-3">
                        <i class="fas fa-info-circle mr-1"></i>
                        Se muestran los días de clase de la sección (incluido <strong>Domingo</strong> cuando aplica).
                        El docente puede impartir en <strong>otras secciones</strong> siempre que el bloque esté dentro de su
                        disponibilidad, no se solape con otra clase suya y no supere su máximo semanal. Las horas totales
                        del programa no limitan esta carga; solo cuentan los bloques del horario.
                        El salón aparece <strong>BLOQUEADO</strong> únicamente en los bloques donde ya tiene otra sección o préstamo asignado;
                        fuera de esa franja permanece <strong>DESBLOQUEADO</strong>.
                    </div>
                    <div class="table-responsive">
                        <table class="table table-bordered align-middle text-center">
                            <thead style="background-color: #ff8a00; color: #ffffff;">
                                <tr>
                                    <th style="width: 210px;">Hora / Bloque</th>
                                    <?php foreach ($dias_semana as $dia): ?>
                                        <th><?= htmlspecialchars($dia) ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($bloques_estandar as $bloque): 
                                    $b_ini_seg = strtotime($bloque['inicio']);
                                    $b_fin_seg = strtotime($bloque['fin']);
                                    $fuera_de_rango = ($b_fin_seg < $cohorte_h_ini_seg || $b_ini_seg > $cohorte_h_fin_seg);
                                ?>
                                    <tr>
                                        <td class="font-weight-bold <?= $fuera_de_rango ? 'text-muted bg-light' : '' ?>" style="font-size: 0.85rem;">
                                            <?= htmlspecialchars($bloque['etiqueta']) ?>
                                            <?php if ($fuera_de_rango): ?>
                                                <span class="d-block text-danger small font-italic">Fuera de rango base</span>
                                            <?php endif; ?>
                                        </td>
                                        <?php foreach ($dias_semana as $dia): 
                                            $materia_seleccionada = $matriz_cargada[$dia][$bloque['inicio']] ?? 0;

                                            $clave_estado = $dia . '|' . $bloque['inicio'];
                                            if (!isset($salon_estado_celdas[$clave_estado])) {
                                                $salon_estado_celdas[$clave_estado] = ($salon_cohorte === '')
                                                    ? ['bloqueado' => false, 'motivo' => '']
                                                    : espacios_estado(
                                                        $pdo,
                                                        $salon_cohorte,
                                                        [$dia],
                                                        $bloque['inicio'],
                                                        $bloque['fin'],
                                                        $fecha_ini_cohorte,
                                                        $fecha_fin_cohorte,
                                                        null,
                                                        $id_cohorte_sel
                                                    );
                                            }
                                            $motivos_salon = !empty($salon_estado_celdas[$clave_estado]['bloqueado'])
                                                ? 'SALÓN BLOQUEADO · ' . $salon_estado_celdas[$clave_estado]['motivo']
                                                : '';
                                        ?>
                                            <td>
                                                <select name="celda_materia[<?= $dia ?>][<?= $bloque['inicio'] ?>]" 
                                                        class="form-control form-control-sm font-weight-bold text-dark <?= $fuera_de_rango ? 'bg-secondary text-white' : 'bg-light' ?>" 
                                                        style="border-radius: 6px; font-size: 0.85rem;" 
                                                        <?= $fuera_de_rango ? 'disabled' : '' ?>>
                                                    <option value="0" <?= $materia_seleccionada == 0 ? 'selected' : '' ?>>-- ⚪ Hora Libre / Vacante --</option>
                                                    <optgroup label="🍽️ Servicios y Pernocta">
                                                        <option value="SERV_DESAYUNO" style="color: #d35400; font-weight: bold;">[ 🌅 Desayuno ]</option>
                                                        <option value="SERV_ALMUERZO" style="color: #d35400; font-weight: bold;">[ 🍲 Almuerzo ]</option>
                                                        <option value="SERV_MERIENDA" style="color: #d35400; font-weight: bold;">[ 🥪 Merienda ]</option>
                                                        <option value="SERV_CENA" style="color: #d35400; font-weight: bold;">[ 🌙 Cena ]</option>
                                                    </optgroup>
                                                    <optgroup label="📚 Asignaturas Académicas (Malla)">
                                                        <?php foreach ($materias_cohorte as $mat):
                                                            $prof_id  = $materia_profesor_map[$mat['id_materia']] ?? null;
                                                            $prof_nom = $profesores_nombres[$mat['id_materia']] ?? 'Por Definir';
                                                            $es_actual = ($materia_seleccionada == $mat['id_materia']);
                                                            $motivos = [];
                                                            if ($motivos_salon !== '') {
                                                                $motivos[] = $motivos_salon;
                                                            }

                                                            if (!empty($prof_id)) {
                                                                if (!$bloque['clase']) {
                                                                    $motivos[] = 'BLOQUE NO IMPARTIBLE';
                                                                } elseif (!docente_esta_disponible($resumen_docentes[$prof_id]['disponibilidad'] ?? [], $dia, $bloque['inicio'])) {
                                                                    $motivos[] = 'DOCENTE SIN DISPONIBILIDAD';
                                                                }

                                                                foreach ($profesores_ocupados_map[$dia] ?? [] as $ocu) {
                                                                    if ((int) $ocu['profesor'] === (int) $prof_id
                                                                        && docente_bloque_solapa($bloque['inicio'], $bloque['fin'], $ocu['inicio'], $ocu['fin'])) {
                                                                        $motivos[] = 'DOCENTE EN EL AULA ' . $ocu['salon'] . ' (' . $ocu['seccion'] . ')';
                                                                        break;
                                                                    }
                                                                }

                                                                $horas_bloque = docente_horas_bloque($bloque['inicio'], $bloque['fin']);
                                                                // Si la celda ya era de este docente, ese bloque ya está
                                                                // dentro de carga_matriz_docentes: se resta antes de sumar
                                                                // el bloque que se está evaluando (evita doble conteo).
                                                                $horas_celda = 0;
                                                                if (isset($celda_profesor[$dia][$bloque['inicio']])
                                                                    && (int) $celda_profesor[$dia][$bloque['inicio']] === (int) $prof_id) {
                                                                    $horas_celda = $celda_horas[$dia][$bloque['inicio']];
                                                                }
                                                                $proyectada  = ($carga_base_docentes[$prof_id] ?? 0)
                                                                             + ($carga_matriz_docentes[$prof_id] ?? 0)
                                                                             - $horas_celda
                                                                             + $horas_bloque;
                                                                $tope = $resumen_docentes[$prof_id]['max'] ?? 40;
                                                                if ($proyectada > $tope) {
                                                                    $motivos[] = "EXCEDE CARGA ({$proyectada}/{$tope} h)";
                                                                }
                                                            } else {
                                                                $motivos[] = 'SIN DOCENTE ASIGNADO';
                                                            }

                                                            $bloquear_opcion = !$es_actual && !empty($motivos);
                                                        ?>
                                                            <option value="<?= $mat['id_materia'] ?>"
                                                                    <?= $es_actual ? 'selected' : '' ?>
                                                                    <?= $bloquear_opcion ? 'disabled style="color:#a94442;background-color:#f2dede;"' : '' ?>>
                                                                <?= htmlspecialchars($mat['nombre_materia']) ?> (<?= $mat['horas_academicas'] ?> h del plan) — [Prof: <?= htmlspecialchars($prof_nom) ?>]
                                                                <?php if (!empty($motivos)): ?> ⚠️ [<?= htmlspecialchars(implode(' | ', $motivos)) ?>]<?php endif; ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </optgroup>
                                                </select>
                                                <?php if ($fuera_de_rango): ?>
                                                    <input type="hidden" name="celda_materia[<?= $dia ?>][<?= $bloque['inicio'] ?>]" value="0">
                                                <?php endif; ?>
                                            </td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="text-center mt-3">
                        <button type="submit" name="actualizar_matriz_horario" class="btn font-weight-bold text-white shadow-lg py-3 px-5" style="background-color: #ff8a00; border-radius: 10px; font-size: 1.1rem;">
                            <i class="fas fa-save mr-2"></i> GUARDAR Y ACTUALIZAR HORARIO COMPLETO
                        </button>
                    </div>
                </div>
            </div>
        </form>
    <?php endif; ?>
</div>

<?php 
try {
    require_once '../../includes/footer.php'; 
} catch (Exception $e) {
    // Pie de página seguro
}
?>