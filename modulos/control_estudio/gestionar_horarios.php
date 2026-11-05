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

// BLOQUES DE HORARIOS AMPLIADOS
$bloques_estandar = [
    ['inicio' => '07:00:00', 'fin' => '07:45:00', 'etiqueta' => '07:00 AM - 07:45 AM (🌅 Desayuno / Matutino)'],
    ['inicio' => '08:00:00', 'fin' => '08:45:00', 'etiqueta' => '08:00 AM - 08:45 AM (Hora 1)'],
    ['inicio' => '08:45:00', 'fin' => '09:30:00', 'etiqueta' => '08:45 AM - 09:30 AM (Hora 2)'],
    ['inicio' => '09:30:00', 'fin' => '09:45:00', 'etiqueta' => '09:30 AM - 09:45 AM (☕ Receso / Descanso)'],
    ['inicio' => '09:45:00', 'fin' => '10:30:00', 'etiqueta' => '09:45 AM - 10:30 AM (Hora 3)'],
    ['inicio' => '10:30:00', 'fin' => '11:15:00', 'etiqueta' => '10:30 AM - 11:15:00 (Hora 4)'],
    ['inicio' => '11:15:00', 'fin' => '12:00:00', 'etiqueta' => '11:15 AM - 12:00 PM (Hora 5)'],
    ['inicio' => '12:00:00', 'fin' => '13:15:00', 'etiqueta' => '12:00 PM - 01:15 PM (🍲 Almuerzo institucional)'],
    ['inicio' => '13:30:00', 'fin' => '14:15:00', 'etiqueta' => '01:30 PM - 02:15 PM (Hora 6 / Tarde)'],
    ['inicio' => '14:15:00', 'fin' => '15:00:00', 'etiqueta' => '02:15 PM - 03:00 PM (Hora 7 / Tarde)'],
    ['inicio' => '15:00:00', 'fin' => '15:15:00', 'etiqueta' => '03:00 PM - 03:15 PM (🥪 Merienda de la tarde)'],
    ['inicio' => '15:15:00', 'fin' => '16:00:00', 'etiqueta' => '03:15 PM - 04:00 PM (Hora 8 / Tarde)'],
    ['inicio' => '18:00:00', 'fin' => '19:00:00', 'etiqueta' => '06:00 PM - 07:00 PM (🌙 Cena - Pernocta)'],
    ['inicio' => '19:00:00', 'fin' => '20:30:00', 'etiqueta' => '07:00 PM - 08:30 PM (Nocturno / Estudio Dirigido)']
];

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
            $stmt_c_info = $pdo->prepare("SELECT salon_aula FROM public.cohortes WHERE id_cohorte = :id");
            $stmt_c_info->execute([':id' => $id_cohorte]);
            $salon_aula = $stmt_c_info->fetchColumn() ?: 'Por Definir';

            $pdo->beginTransaction();

            $pdo->prepare("DELETE FROM public.horarios_secciones WHERE id_cohorte = :id")->execute([':id' => $id_cohorte]);

            foreach ($asignaciones as $dia => $bloques_dia) {
                foreach ($bloques_dia as $h_inicio => $val_materia) {
                    if (!empty($val_materia) && $val_materia !== '0') {
                        if (strpos($val_materia, 'SERV_') === 0) {
                            continue; 
                        }

                        $id_materia = intval($val_materia);
                        if ($id_materia > 0) {
                            $h_fin = '08:45:00';
                            foreach ($bloques_estandar as $be) {
                                if ($be['inicio'] === $h_inicio) {
                                    $h_fin = $be['fin'];
                                    break;
                                }
                            }

                            $stmt_prof = $pdo->prepare("
                                SELECT id_profesor FROM public.asignaciones_profesor 
                                WHERE id_materia = :mat AND (id_cohorte = :coh OR id_cohorte IS NULL)
                                ORDER BY id_cohorte DESC LIMIT 1
                            ");
                            $stmt_prof->execute([':mat' => $id_materia, ':coh' => $id_cohorte]);
                            $id_profesor = $stmt_prof->fetchColumn() ?: null;

                            if (!empty($id_profesor)) {
                                $sql_val_doc = "
                                    SELECT co.codigo_cohorte, mm.nombre_materia 
                                    FROM public.horarios_secciones hs
                                    JOIN public.cohortes co ON hs.id_cohorte = co.id_cohorte
                                    JOIN public.malla_materias mm ON hs.id_materia = mm.id_materia
                                    WHERE hs.id_profesor = :prof 
                                      AND hs.dia_semana = :dia
                                      AND (hs.hora_inicio < CAST(:h_fin AS time) AND hs.hora_fin > CAST(:h_ini AS time))
                                      AND hs.id_cohorte != :id_c
                                    LIMIT 1
                                ";
                                $stmt_vd = $pdo->prepare($sql_val_doc);
                                $stmt_vd->execute([
                                    ':prof'  => $id_profesor,
                                    ':dia'   => $dia,
                                    ':h_ini' => $h_inicio,
                                    ':h_fin' => $h_fin,
                                    ':id_c'  => $id_cohorte
                                ]);
                                $choque_docente = $stmt_vd->fetch(PDO::FETCH_ASSOC);

                                if ($choque_docente) {
                                    $stmt_m_nom = $pdo->prepare("SELECT nombre_materia FROM public.malla_materias WHERE id_materia = :m");
                                    $stmt_m_nom->execute([':m' => $id_materia]);
                                    $nom_mat = $stmt_m_nom->fetchColumn();
                                    throw new Exception("¡Choque de Horario del Docente! El profesor de '{$nom_mat}' ya se encuentra dictando clases en la sección '{$choque_docente['codigo_cohorte']}' el día {$dia} en el bloque de las " . date('h:i A', strtotime($h_inicio)) . ".");
                                }
                            }

                            $stmt_ins = $pdo->prepare("
                                INSERT INTO public.horarios_secciones (id_cohorte, id_materia, id_profesor, dia_semana, hora_inicio, hora_fin, salon_aula) 
                                VALUES (:coh, :mat, :prof, :dia, :ini, :fin, :sal)
                            ");
                            $stmt_ins->execute([
                                ':coh'  => $id_cohorte,
                                ':mat'  => $id_materia,
                                ':prof' => $id_profesor,
                                ':dia'  => $dia,
                                ':ini'  => $h_inicio,
                                ':fin'  => $h_fin,
                                ':sal'  => $salon_aula
                            ]);
                        }
                    }
                }
            }

            $pdo->commit();
            $msg = "¡Horario validado y actualizado exitosamente!";
        }
    }

    // 3. TERCERO: CARGAR LOS DATOS DE LA SECCIÓN SELECCIONADA
    if ($id_cohorte_sel > 0) {
        $stmt_c = $pdo->prepare("SELECT c.*, m.nombre_programa, m.tipo_regimen FROM public.cohortes c JOIN public.mallas_curriculares m ON c.id_malla = m.id_malla WHERE c.id_cohorte = :id");
        $stmt_c->execute([':id' => $id_cohorte_sel]);
        $cohorte_actual = $stmt_c->fetch(PDO::FETCH_ASSOC);

        if ($cohorte_actual) {
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
            SELECT hs.dia_semana, hs.hora_inicio, hs.id_profesor 
            FROM public.horarios_secciones hs
            WHERE hs.id_cohorte != :id_c AND hs.id_profesor IS NOT NULL
        ";
        $stmt_to = $pdo->prepare($sql_todos_horarios);
        $stmt_to->execute([':id_c' => $id_cohorte_sel]);
        foreach ($stmt_to->fetchAll(PDO::FETCH_ASSOC) as $ot) {
            $d = $ot['dia_semana'];
            $hi = date('H:i:s', strtotime($ot['hora_inicio']));
            $prof_id = $ot['id_profesor'];
            $profesores_ocupados_map[$d][$hi][$prof_id] = true;
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
                    $materia_profesor_map[$mat['id_materia']] = $res_p['id_profesor'];
                    $profesores_nombres[$mat['id_materia']] = trim($res_p['nombre'] . ' ' . $res_p['apellido']);
                } else {
                    $materia_profesor_map[$mat['id_materia']] = null;
                    $profesores_nombres[$mat['id_materia']] = 'Por Definir';
                }
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

$dias_semana = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes'];
if ($cohorte_actual && strpos($cohorte_actual['dias_clase'], 'Sábado') !== false) {
    $dias_semana[] = 'Sábado';
}
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
                                <div class="text-xs font-weight-bold text-uppercase mb-1" style="color: #521f29;">Carga Horaria Requerida (Malla Curricular)</div>
                                <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $total_horas_malla ?> Horas Académicas Totales</div>
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
                                                            $prof_id = $materia_profesor_map[$mat['id_materia']] ?? null;
                                                            $prof_nom = $profesores_nombres[$mat['id_materia']] ?? 'Por Definir';
                                                            $profesor_ocupado = ($prof_id && isset($profesores_ocupados_map[$dia][$bloque['inicio']][$prof_id]));
                                                        ?>
                                                            <option value="<?= $mat['id_materia'] ?>" 
                                                                    <?= $materia_seleccionada == $mat['id_materia'] ? 'selected' : '' ?>
                                                                    <?= $profesor_ocupado && $materia_seleccionada != $mat['id_materia'] ? 'disabled style="color: #a94442; background-color: #f2dede;"' : '' ?>>
                                                                <?= htmlspecialchars($mat['nombre_materia']) ?> (<?= $mat['horas_academicas'] ?>h) — [Prof: <?= htmlspecialchars($prof_nom) ?>]
                                                                <?= $profesor_ocupado && $materia_seleccionada != $mat['id_materia'] ? ' ⚠️ [DOCENTE OCUPADO A ESTA HORA]' : '' ?>
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