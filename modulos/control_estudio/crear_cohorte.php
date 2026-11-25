<?php
// /sistema_nora/modulos/control_estudio/crear_cohorte.php
require_once '../../config/db.php';
require_once '../../includes/espacios_disponibilidad.php';
require_once '../../includes/header.php';

if (!function_exists('tiene_permiso') || !tiene_permiso(['control_estudio', 'admin', 'superadmin'], $roles_array ?? [])) {
    echo "<div class='container-fluid pt-4'><div class='alert alert-danger font-weight-bold'>Acceso denegado a Control de Estudio.</div></div>";
    require_once '../../includes/footer.php';
    exit();
}

/**
 * Convierte un número ordinal de secuencia en letras mayúsculas (1=>A, 2=>B, 26=>Z, 27=>AA, 28=>AB...)
 */
function obtenerLetraSecuencia($num) {
    $letra = '';
    while ($num > 0) {
        $mod = ($num - 1) % 26;
        $letra = chr(65 + $mod) . $letra;
        $num = (int)(($num - $mod) / 26);
    }
    return $letra;
}

$id_cohorte_edit   = intval($_GET['id'] ?? 0);
$id_cohorte_clonar = intval($_GET['clonar'] ?? 0);
$error = '';

// Variables de estado inicial
$dias_clase  = $_POST['dias_clase'] ?? 'Lunes a Viernes';
$hora_inicio = $_POST['hora_inicio'] ?? '08:00:00';
$hora_fin    = $_POST['hora_fin'] ?? '12:30:00';

// Cargar datos si viene con orden de clonación
$cohorte_datos = null;
if ($id_cohorte_clonar > 0 && $id_cohorte_edit == 0) {
    $stmt_clon = $pdo->prepare("SELECT * FROM public.cohortes WHERE id_cohorte = :id");
    $stmt_clon->execute([':id' => $id_cohorte_clonar]);
    $cohorte_datos = $stmt_clon->fetch(PDO::FETCH_ASSOC);
    if ($cohorte_datos) {
        $dias_clase  = $cohorte_datos['dias_clase'] ?? $dias_clase;
        $hora_inicio = $cohorte_datos['hora_inicio'] ?? $hora_inicio;
        $hora_fin    = $cohorte_datos['hora_fin'] ?? $hora_fin;
        // Limpiamos la cohorte para que obligue a autogenerar el siguiente código (ej: B, C, D...)
        unset($cohorte_datos['codigo_cohorte']);
        unset($cohorte_datos['id_cohorte']);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_cohorte'])) {
    try {
        $id_malla       = intval($_POST['id_malla'] ?? 0);
        $num_periodo    = intval($_POST['num_periodo'] ?? 1);
        $fecha_inicio   = !empty($_POST['fecha_inicio']) ? $_POST['fecha_inicio'] : date('Y-m-d');
        $fecha_fin      = !empty($_POST['fecha_fin_estimada']) ? $_POST['fecha_fin_estimada'] : null;
        $salones_sel    = $_POST['salones'] ?? [];
        $cupos_max      = intval($_POST['cupos_maximos'] ?? 30);
        $dias_clase     = trim($_POST['dias_clase'] ?? 'Lunes a Viernes');
        $hora_inicio    = $_POST['hora_inicio'] ?? '08:00:00';
        $hora_fin       = $_POST['hora_fin'] ?? '12:30:00';
        $id_prof_guia   = !empty($_POST['id_profesor_guia']) ? intval($_POST['id_profesor_guia']) : null;
        $abrir_pernocta = isset($_POST['inscripcion_pernocta_abierta']) && $_POST['inscripcion_pernocta_abierta'] === '1';

        if ($id_malla <= 0 || empty($salones_sel)) {
            throw new Exception('Debe seleccionar la oferta académica y al menos un salón asignado.');
        }
        $h_ini_norm = espacios_normalizar_hora($hora_inicio);
        $h_fin_norm = espacios_normalizar_hora($hora_fin);
        if ($h_ini_norm === '' || $h_fin_norm === '' || $h_fin_norm <= $h_ini_norm) {
            throw new Exception('El rango horario de la sección no es válido.');
        }
        $hora_inicio = $h_ini_norm;
        $hora_fin = $h_fin_norm;

        // Solo se puede abrir inscripción con pernocta si la malla lo permite.
        $requiere_pernocta = false;
        $st_mp = $pdo->prepare("SELECT requiere_pernocta FROM public.mallas_curriculares WHERE id_malla = :id");
        $st_mp->execute([':id' => $id_malla]);
        $requiere_pernocta = (bool) $st_mp->fetchColumn();
        if ($abrir_pernocta && !$requiere_pernocta) {
            throw new Exception('La oferta seleccionada no está marcada como "Con Pernocta". Márquela primero en la Malla Curricular.');
        }

        // Generar o procesar Código de la Sección
        if ($id_cohorte_edit == 0) {
            // Obtener el nombre del programa para generar las iniciales
            $stmt_malla = $pdo->prepare("SELECT nombre_programa FROM public.mallas_curriculares WHERE id_malla = :id");
            $stmt_malla->execute([':id' => $id_malla]);
            $nombre_programa = $stmt_malla->fetchColumn() ?: 'GENERAL';

            // Extraer palabras principales
            $palabras = explode(' ', preg_replace('/[^a-zA-Z0-9 ]/', '', $nombre_programa));
            $iniciales = '';
            foreach ($palabras as $w) {
                if (mb_strlen($w) > 2) {
                    $iniciales .= mb_strtoupper(mb_substr($w, 0, 1));
                }
            }
            if (empty($iniciales)) { $iniciales = 'GEN'; }

            $prefijo_base = "SEC-" . $iniciales . $num_periodo;

            // Consultar la secuencia existente en la BD para este prefijo
            $stmt_cant = $pdo->prepare("SELECT COUNT(*) FROM public.cohortes WHERE codigo_cohorte LIKE :prefijo");
            $stmt_cant->execute([':prefijo' => $prefijo_base . '%']);
            $total_existentes = intval($stmt_cant->fetchColumn()) + 1;

            $letra_seccion = obtenerLetraSecuencia($total_existentes);
            $codigo_cohorte = $prefijo_base . $letra_seccion; // Ejemplo: SEC-CS1A
        } else {
            $codigo_cohorte = trim($_POST['codigo_cohorte'] ?? '');
            if (empty($codigo_cohorte)) {
                throw new Exception('El código de la sección no puede estar vacío.');
            }
        }

        $salon_aula = implode(', ', $salones_sel);

        // Validar Choque de Salón usando la misma regla dinámica de espacios:
        // solo se considera ocupado si coinciden el día y la franja horaria.
        foreach ($salones_sel as $salon_nombre) {
            $estado_salon = espacios_estado(
                $pdo,
                trim($salon_nombre),
                espacios_dias_desde_texto($dias_clase),
                $hora_inicio,
                $hora_fin,
                $fecha_inicio,
                $fecha_fin ?: $fecha_inicio,
                null,
                $id_cohorte_edit > 0 ? $id_cohorte_edit : null
            );
            if (!empty($estado_salon['bloqueado'])) {
                throw new Exception(
                    "¡Choque de Aula! El salón '{$salon_nombre}' está " .
                    strtolower($estado_salon['estado']) . ': ' . $estado_salon['motivo'] . '.'
                );
            }
        }

        if ($id_cohorte_edit > 0) {
            $sql = "UPDATE public.cohortes 
                    SET codigo_cohorte = :cod, id_malla = :malla, num_periodo = :num, salon_aula = :sal,
                        dias_clase = :dias, hora_inicio = :h_ini, hora_fin = :h_fin, fecha_inicio = :f_ini,
                        fecha_fin_estimada = :f_fin, cupos_maximos = :cupos, id_profesor_guia = :prof,
                        inscripcion_pernocta_abierta = :pernocta
                    WHERE id_cohorte = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':cod'   => $codigo_cohorte,
                ':malla' => $id_malla,
                ':num'   => $num_periodo,
                ':sal'   => $salon_aula,
                ':dias'  => $dias_clase,
                ':h_ini' => $hora_inicio,
                ':h_fin' => $hora_fin,
                ':f_ini' => $fecha_inicio,
                ':f_fin' => $fecha_fin,
                ':cupos'    => $cupos_max,
                ':prof'     => $id_prof_guia,
                ':pernocta' => $abrir_pernocta ? 'true' : 'false',
                ':id'       => $id_cohorte_edit
            ]);
            $msg = "cohorte_actualizada";
        } else {
            $sql = "INSERT INTO public.cohortes (codigo_cohorte, id_malla, num_periodo, salon_aula, dias_clase, hora_inicio, hora_fin, fecha_inicio, fecha_fin_estimada, cupos_maximos, id_profesor_guia, inscripcion_pernocta_abierta, estatus)
                    VALUES (:cod, :malla, :num, :sal, :dias, :h_ini, :h_fin, :f_ini, :f_fin, :cupos, :prof, :pernocta, 'Planificada')";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':cod'   => $codigo_cohorte,
                ':malla' => $id_malla,
                ':num'   => $num_periodo,
                ':sal'   => $salon_aula,
                ':dias'  => $dias_clase,
                ':h_ini' => $hora_inicio,
                ':h_fin' => $hora_fin,
                ':f_ini' => $fecha_inicio,
                ':f_fin'    => $fecha_fin,
                ':cupos'    => $cupos_max,
                ':prof'     => $id_prof_guia,
                ':pernocta' => $abrir_pernocta ? 'true' : 'false'
            ]);
            $msg = "cohorte_guardada";
        }

        echo "<script>window.location.href = 'index.php?msg={$msg}';</script>";
        exit();

    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Cargar datos de edición si no viene de clonar
if ($id_cohorte_edit > 0 && null === $cohorte_datos) {
    $stmt_e = $pdo->prepare("SELECT * FROM public.cohortes WHERE id_cohorte = :id");
    $stmt_e->execute([':id' => $id_cohorte_edit]);
    $cohorte_datos = $stmt_e->fetch(PDO::FETCH_ASSOC);
    if ($cohorte_datos) {
        $dias_clase  = $cohorte_datos['dias_clase'] ?? $dias_clase;
        $hora_inicio = $cohorte_datos['hora_inicio'] ?? $hora_inicio;
        $hora_fin    = $cohorte_datos['hora_fin'] ?? $hora_fin;
    }
}

// Carga de catálogos
try {
    $mallas = $pdo->query("SELECT id_malla, nombre_programa, tipo_oferta, tipo_regimen, total_periodos, requiere_pernocta FROM public.mallas_curriculares ORDER BY nombre_programa ASC")->fetchAll(PDO::FETCH_ASSOC);
    $materias_mallas = $pdo->query("SELECT id_materia, id_malla, modulo_num, nombre_materia, horas_academicas FROM public.malla_materias ORDER BY modulo_num ASC, nombre_materia ASC")->fetchAll(PDO::FETCH_ASSOC);
    $profesores = $pdo->query("SELECT id_usuario, nombre, apellido, cedula FROM public.usuarios WHERE 'profesor' = ANY(roles_asignados) OR tipo_usuario = 'profesor' ORDER BY apellido ASC")->fetchAll(PDO::FETCH_ASSOC);

    // Salones con ocupación dinámica: se bloquea solo quien tenga una franja
    // que realmente solape con los días/horarios elegidos para la nueva sección.
    $fecha_ini_salones = !empty($_POST['fecha_inicio'])
        ? $_POST['fecha_inicio']
        : ($cohorte_datos['fecha_inicio'] ?? date('Y-m-d'));
    $fecha_fin_salones = !empty($_POST['fecha_fin_estimada'])
        ? $_POST['fecha_fin_estimada']
        : ($cohorte_datos['fecha_fin_estimada'] ?? date('Y-m-d', strtotime('+6 months')));

    $salones_disponibles = $pdo->query("
        SELECT e.id_espacio, e.nombre_espacio, e.capacidad_estudiantes, e.estatus AS estatus_mantenimiento
        FROM public.campus_espacios e
        WHERE e.tipo_espacio IN ('Aula', 'Laboratorio', 'Auditorio', 'Salón')
        ORDER BY e.nombre_espacio ASC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $dias_sel = espacios_dias_desde_texto($dias_clase);
    foreach ($salones_disponibles as &$sal) {
        $estado_sal = espacios_estado(
            $pdo,
            (string) $sal['nombre_espacio'],
            $dias_sel,
            $hora_inicio,
            $hora_fin,
            $fecha_ini_salones,
            $fecha_fin_salones,
            null,
            $id_cohorte_edit > 0 ? $id_cohorte_edit : null
        );
        $sal['ocupado_por_cohorte'] = !empty($estado_sal['bloqueado'])
            ? $estado_sal['motivo']
            : '';
        $sal['estado_dinamico'] = $estado_sal['estado'];
    }
    unset($sal);
} catch (Exception $e) {
    $salones_disponibles = [];
    $mallas = [];
    $materias_mallas = [];
    $profesores = [];
    $error = "Error al cargar datos del sistema: " . $e->getMessage();
}

$salones_seleccionados = !empty($cohorte_datos['salon_aula']) ? array_map('trim', explode(',', $cohorte_datos['salon_aula'])) : [];
?>

<div class="container-fluid pt-3">
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 font-weight-bold" style="color: #521f29;">
            <i class="fas fa-layer-group mr-2 text-warning"></i><?= $id_cohorte_clonar > 0 ? 'Clonar Sección' : ($id_cohorte_edit > 0 ? 'Editar Sección' : 'Apertura de Sección / Cohorte') ?>
        </h1>
        <a href="index.php" class="btn btn-sm btn-secondary font-weight-bold shadow-sm"><i class="fas fa-arrow-left mr-1"></i> Volver</a>
    </div>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger font-weight-bold shadow-sm mb-4"><i class="fas fa-exclamation-triangle mr-2"></i><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="card shadow border-0 mb-4" style="border-radius: 15px;">
        <div class="card-header text-white font-weight-bold" style="background-color: #521f29; border-top-left-radius: 15px; border-top-right-radius: 15px;">
            Configuración de Horario, Aula y Asignaturas por Sección
        </div>
        <div class="card-body bg-white p-4">
            <form method="POST" action="crear_cohorte.php<?= $id_cohorte_edit > 0 ? '?id='.$id_cohorte_edit : '' ?>">
                <?php if (function_exists('csrf_field')) { echo csrf_field(); } ?>

                <div class="row">
                    <div class="col-md-4 form-group">
                        <label class="font-weight-bold text-dark">
                            Código de la Sección 
                            <small class="text-primary font-italic">(Autogenerado)</small>
                        </label>
                        <input type="text" name="codigo_cohorte" id="input_codigo_cohorte" class="form-control font-weight-bold bg-light" 
                               value="<?= htmlspecialchars($cohorte_datos['codigo_cohorte'] ?? 'SEC-AUTOGEN') ?>" 
                               placeholder="Se generará automáticamente" <?= $id_cohorte_edit == 0 ? 'readonly' : 'required' ?>>
                    </div>

                    <div class="col-md-5 form-group">
                        <label class="font-weight-bold text-dark">Programa / Carrera *</label>
                        <select name="id_malla" id="select_malla" class="form-control font-weight-bold" onchange="cargarNivelesYMaterias(); actualizarPernocta();" required>
                            <option value="">-- Seleccionar Oferta --</option>
                            <?php foreach ($mallas as $m): ?>
                                <option value="<?= $m['id_malla'] ?>" data-regimen="<?= htmlspecialchars($m['tipo_regimen'] ?? 'Semestre') ?>" data-periodos="<?= intval($m['total_periodos'] ?? 1) ?>" data-pernocta="<?= !empty($m['requiere_pernocta']) ? '1' : '0' ?>" <?= ($cohorte_datos['id_malla'] ?? 0) == $m['id_malla'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($m['nombre_programa']) ?> (<?= htmlspecialchars($m['tipo_oferta']) ?><?= !empty($m['requiere_pernocta']) ? ' · PERNOCTA' : '' ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-md-3 form-group">
                        <label class="font-weight-bold text-dark">Nivel / Semestre / Año *</label>
                        <select name="num_periodo" id="select_periodo" class="form-control font-weight-bold" onchange="filtrarChecklistMaterias()" required>
                            <option value="1">Semestre 1</option>
                        </select>
                    </div>
                </div>

                <!-- CHECKLIST DE MATERIAS DE LA MALLA CURRICULAR -->
                <div class="card mb-4 border-0 p-3" style="background-color: #fcf8f2; border-left: 5px solid #ff8a00 !important; border-radius: 10px;">
                    <label class="font-weight-bold text-dark h6 mb-2">
                        <i class="fas fa-book-reader text-warning mr-2"></i>Asignaturas Registradas en la Malla Curricular para este Nivel:
                    </label>
                    <div id="contenedor_checklist_materias" class="row pt-2">
                        <div class="col-12 text-muted font-italic">Seleccione una Carrera / Oferta para ver las materias correspondientes.</div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-3 form-group">
                        <label class="font-weight-bold text-dark">Días de Clase *</label>
                        <select name="dias_clase" id="dias_clase" class="form-control font-weight-bold" onchange="programarActualizacionSalones()">
                            <option value="Lunes a Viernes" <?= $dias_clase === 'Lunes a Viernes' ? 'selected' : '' ?>>Lunes a Viernes</option>
                            <option value="Sábado y Domingo" <?= $dias_clase === 'Sábado y Domingo' ? 'selected' : '' ?>>Sábado y Domingo</option>
                        </select>
                    </div>

                    <div class="col-md-3 form-group">
                        <label class="font-weight-bold text-dark">Hora Inicio *</label>
                        <input type="time" name="hora_inicio" id="hora_inicio" class="form-control font-weight-bold" value="<?= htmlspecialchars($hora_inicio) ?>" onchange="programarActualizacionSalones()" oninput="programarActualizacionSalones()" required>
                    </div>

                    <div class="col-md-3 form-group">
                        <label class="font-weight-bold text-dark">Hora Fin *</label>
                        <input type="time" name="hora_fin" id="hora_fin" class="form-control font-weight-bold" value="<?= htmlspecialchars($hora_fin) ?>" onchange="programarActualizacionSalones()" oninput="programarActualizacionSalones()" required>
                    </div>

                    <div class="col-md-3 form-group">
                        <label class="font-weight-bold text-dark">Capacidad Cupos *</label>
                        <input type="number" name="cupos_maximos" class="form-control font-weight-bold" value="<?= $cohorte_datos['cupos_maximos'] ?? 30 ?>" required>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-4 form-group">
                        <label class="font-weight-bold text-dark">Fecha Inicio Clases</label>
                        <input type="date" name="fecha_inicio" id="fecha_inicio" class="form-control font-weight-bold" value="<?= htmlspecialchars($cohorte_datos['fecha_inicio'] ?? date('Y-m-d')) ?>" onchange="programarActualizacionSalones()" required>
                    </div>

                    <div class="col-md-4 form-group">
                        <label class="font-weight-bold text-dark">Fecha Estimada Culminación</label>
                        <input type="date" name="fecha_fin_estimada" id="fecha_fin_estimada" class="form-control font-weight-bold" value="<?= htmlspecialchars($cohorte_datos['fecha_fin_estimada'] ?? date('Y-m-d', strtotime('+6 months'))) ?>" onchange="programarActualizacionSalones()">
                    </div>

                    <div class="col-md-4 form-group">
                        <label class="font-weight-bold text-dark">Docente Guía / Tutor</label>
                        <select name="id_profesor_guia" class="form-control font-weight-bold">
                            <option value="">-- Por Asignar --</option>
                            <?php foreach ($profesores as $p): ?>
                                <option value="<?= $p['id_usuario'] ?>" <?= ($cohorte_datos['id_profesor_guia'] ?? 0) == $p['id_usuario'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($p['nombre'] . ' ' . $p['apellido']) ?> (V-<?= htmlspecialchars($p['cedula']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- ═══════ EVENTO CON PERNOCTA ═══════ -->
                <div class="card mb-4 border-0 p-3" id="card_pernocta" style="background-color: #fff5e6; border-left: 5px solid #521f29 !important; border-radius: 10px;">
                    <label class="font-weight-bold text-dark h6 mb-2">
                        <i class="fas fa-moon text-warning mr-2"></i>Evento con Pernocta
                    </label>
                    <div class="custom-control custom-checkbox">
                        <input type="checkbox" class="custom-control-input" id="chk_pernocta"
                               name="inscripcion_pernocta_abierta" value="1"
                               <?= !empty($cohorte_datos['inscripcion_pernocta_abierta']) ? 'checked' : '' ?>>
                        <label class="custom-control-label font-weight-bold text-dark" for="chk_pernocta">
                            Abrir inscripción de estudiantes (solo con cédula) para este evento con pernocta
                        </label>
                    </div>
                    <small class="form-text text-muted mt-2" id="ayuda_pernocta">
                        Solo disponible cuando la oferta esté marcada como "Con Pernocta" en la Malla Curricular.
                        La fecha de inicio se prellena; la fecha final se carga al finalizar el evento desde Dormitorios.
                    </small>
                </div>

                <hr class="my-4">

                <div class="form-group mb-4">
                    <label class="font-weight-bold text-dark h5"><i class="fas fa-building text-warning mr-2"></i>Asignación de Salón de Clase</label>
                    <small class="text-muted d-block mb-2" id="estado_salones_mensaje">
                        <i class="fas fa-info-circle mr-1"></i>
                        El estado se recalcula automáticamente al cambiar días, horas o fechas. Solo se bloquea el aula si otra sección o préstamo tiene clase en esa misma franja.
                    </small>
                    <div class="row pt-2" id="contenedor_salones">
                        <?php if (empty($salones_disponibles)): ?>
                            <div class="col-12 text-muted font-italic">No hay aulas o salones registrados en el módulo de Campus Espacios.</div>
                        <?php else: ?>
                            <?php foreach ($salones_disponibles as $sal): 
                                $ocupado  = $sal['ocupado_por_cohorte'];
                                $esta_seleccionado = in_array($sal['nombre_espacio'], $salones_seleccionados);
                                $esta_inhabilitado = !empty($ocupado) && !$esta_seleccionado;
                            ?>
                                <div class="col-md-4 mb-3">
                                    <div class="card h-100 p-3 border <?= $esta_inhabilitado ? 'bg-light text-muted border-danger' : ($esta_seleccionado ? 'border-primary bg-white' : 'border-success bg-white') ?>" style="border-radius: 12px;">
                                        <div class="custom-control custom-checkbox">
                                            <input type="checkbox" class="custom-control-input" id="salon_<?= $sal['id_espacio'] ?>" name="salones[]" value="<?= htmlspecialchars($sal['nombre_espacio']) ?>" <?= $esta_seleccionado ? 'checked' : '' ?> <?= $esta_inhabilitado ? 'disabled' : '' ?>>
                                            <label class="custom-control-label font-weight-bold d-block text-dark" for="salon_<?= $sal['id_espacio'] ?>">
                                                <?= htmlspecialchars($sal['nombre_espacio']) ?>
                                                <span class="badge badge-info float-right"><?= $sal['capacidad_estudiantes'] ?> Alumnos Cap.</span>
                                            </label>
                                        </div>
                                        <?php if (!empty($ocupado)): ?>
                                            <small class="text-danger font-weight-bold mt-2 d-block"><i class="fas fa-lock mr-1"></i> <?= htmlspecialchars($sal['estado_dinamico'] ?? 'BLOQUEADO') ?>: <?= htmlspecialchars($ocupado) ?></small>
                                        <?php else: ?>
                                            <small class="text-success font-weight-bold mt-2 d-block"><i class="fas fa-lock-open mr-1"></i> DESBLOQUEADO · sin solape en esta franja</small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <button type="submit" name="guardar_cohorte" class="btn btn-block font-weight-bold text-white shadow-lg py-3 mt-4" style="background-color: #ff8a00; border-radius: 10px;">
                    <i class="fas fa-save mr-2"></i> GUARDAR Y REGISTRAR SECCIÓN
                </button>
            </form>
        </div>
    </div>
</div>

<script>
const todasLasMaterias = <?= json_encode($materias_mallas) ?>;
const periodoActualEdit = <?= intval($cohorte_datos['num_periodo'] ?? 1) ?>;
const esEdicion = <?= $id_cohorte_edit > 0 ? 'true' : 'false' ?>;
const idCohorteEdit = <?= intval($id_cohorte_edit) ?>;
let salonesSeleccionados = new Set();
let temporizadorSalones = null;
let versionSalones = 0;

function generarCodigoSugerido() {
    if (esEdicion) return;

    const selectMalla = document.getElementById('select_malla');
    const selectPeriodo = document.getElementById('select_periodo');
    const inputCodigo = document.getElementById('input_codigo_cohorte');

    if (!selectMalla || !selectPeriodo || !inputCodigo) return;

    const optSelected = selectMalla.options[selectMalla.selectedIndex];
    if (!optSelected || !optSelected.value) {
        inputCodigo.value = 'SEC-AUTOGEN';
        return;
    }

    const nombrePrograma = optSelected.text.split('(')[0].trim();
    const palabras = nombrePrograma.split(' ');
    let iniciales = '';

    palabras.forEach(word => {
        const limpia = word.replace(/[^a-zA-Z0-9]/g, '');
        if (limpia.length > 2) {
            iniciales += limpia[0].toUpperCase();
        }
    });

    if (!iniciales) iniciales = 'GEN';

    const numPeriodo = selectPeriodo.value || '1';
    inputCodigo.value = `SEC-${iniciales}${numPeriodo}A`;
}

function actualizarPernocta() {
    const selectMalla = document.getElementById('select_malla');
    const chk = document.getElementById('chk_pernocta');
    const ayuda = document.getElementById('ayuda_pernocta');
    if (!selectMalla || !chk) return;
    const opt = selectMalla.options[selectMalla.selectedIndex];
    const requiere = opt && opt.getAttribute('data-pernocta') === '1';
    chk.disabled = !requiere;
    if (!requiere) {
        chk.checked = false;
        if (ayuda) ayuda.innerHTML = 'Esta oferta <strong>no</strong> requiere pernocta. Márquela como "Con Pernocta" en la Malla Curricular para habilitar el pre-registro por cédula.';
    } else {
        // Registrar la cohorte con pernocta habilita de una vez la inscripción por cédula.
        if (!esEdicion) { chk.checked = true; }
        if (ayuda) ayuda.innerHTML = 'Inscripción habilitada automáticamente. Fecha de inicio prellenada; la fecha final se carga al finalizar el evento desde <a href="../configuracion/dormitorios.php" class="font-weight-bold">Dormitorios</a>.';
    }
}

function cargarNivelesYMaterias() {
    const selectMalla = document.getElementById('select_malla');
    const selectPeriodo = document.getElementById('select_periodo');
    if (!selectMalla || !selectPeriodo) return;

    actualizarPernocta();

    const optSelected = selectMalla.options[selectMalla.selectedIndex];

    if (!optSelected || !optSelected.value) {
        selectPeriodo.innerHTML = '<option value="1">Nivel / Semestre 1</option>';
        document.getElementById('contenedor_checklist_materias').innerHTML = '<div class="col-12 text-muted font-italic">Seleccione una Carrera / Oferta para ver las materias correspondientes.</div>';
        generarCodigoSugerido();
        return;
    }

    const regimen = optSelected.getAttribute('data-regimen') || 'Semestre';
    const totalPeriodos = parseInt(optSelected.getAttribute('data-periodos')) || 1;

    selectPeriodo.innerHTML = '';
    for (let i = 1; i <= totalPeriodos; i++) {
        const opt = document.createElement('option');
        opt.value = i;
        opt.innerText = `${regimen} ${i}`;
        if (i === periodoActualEdit) { opt.selected = true; }
        selectPeriodo.appendChild(opt);
    }

    filtrarChecklistMaterias();
}

function filtrarChecklistMaterias() {
    const selectMalla = document.getElementById('select_malla');
    const selectPeriodo = document.getElementById('select_periodo');
    const contenedor = document.getElementById('contenedor_checklist_materias');
    if (!selectMalla || !selectPeriodo || !contenedor) return;

    const idMallaSel = parseInt(selectMalla.value) || 0;
    const numPeriodoSel = parseInt(selectPeriodo.value) || 1;

    generarCodigoSugerido();

    if (idMallaSel <= 0) {
        contenedor.innerHTML = '<div class="col-12 text-muted font-italic">Seleccione una Carrera / Oferta para ver las materias correspondientes.</div>';
        return;
    }

    const materiasFiltradas = todasLasMaterias.filter(m => parseInt(m.id_malla) === idMallaSel && parseInt(m.modulo_num) === numPeriodoSel);

    if (materiasFiltradas.length === 0) {
        contenedor.innerHTML = '<div class="col-12 text-danger font-weight-bold py-2"><i class="fas fa-exclamation-triangle mr-2"></i>No hay materias cargadas en la malla para este periodo/nivel.</div>';
        return;
    }

    let htmlChecklist = '';
    materiasFiltradas.forEach(mat => {
        htmlChecklist += `
            <div class="col-md-6 mb-2">
                <div class="custom-control custom-checkbox p-2 bg-white rounded border shadow-sm">
                    <input type="checkbox" class="custom-control-input" id="mat_chk_${mat.id_materia}" checked disabled>
                    <label class="custom-control-label font-weight-bold text-dark d-flex justify-content-between pr-2" for="mat_chk_${mat.id_materia}">
                        <span><i class="fas fa-check-circle text-success mr-1"></i> ${mat.nombre_materia}</span>
                        <span class="badge badge-warning text-dark font-weight-bold">${mat.horas_academicas} hrs</span>
                    </label>
                </div>
            </div>
        `;
    });

    contenedor.innerHTML = htmlChecklist;
}

function escapeHtml(valor) {
    return String(valor == null ? '' : valor).replace(/[&<>"']/g, function(c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[c];
    });
}

function actualizarSeleccionSalones() {
    document.querySelectorAll('#contenedor_salones input[name="salones[]"]').forEach(function(chk) {
        if (chk.checked) salonesSeleccionados.add(chk.value);
        else salonesSeleccionados.delete(chk.value);
    });
}

function programarActualizacionSalones() {
    if (temporizadorSalones) clearTimeout(temporizadorSalones);
    temporizadorSalones = setTimeout(function() {
        temporizadorSalones = null;
        actualizarSalonesPorHorario();
    }, 250);
}

function diasDelSelect() {
    const select = document.getElementById('dias_clase');
    const texto = (select ? select.value : 'Lunes a Viernes').toLowerCase();
    if (texto.indexOf('lunes a viernes') !== -1) {
        return ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes'];
    }
    if (texto.indexOf('sábado y domingo') !== -1 || texto.indexOf('sabado y domingo') !== -1) {
        return ['Sábado', 'Domingo'];
    }
    if (texto.indexOf('sábado') !== -1 || texto.indexOf('sabado') !== -1) {
        return ['Sábado'];
    }
    if (texto.indexOf('domingo') !== -1) {
        return ['Domingo'];
    }
    return ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes'];
}

async function actualizarSalonesPorHorario() {
    actualizarSeleccionSalones();

    // Si el usuario vuelve a cambiar los datos, se ignora la respuesta anterior.
    const version = ++versionSalones;

    const contenedor = document.getElementById('contenedor_salones');
    const mensaje = document.getElementById('estado_salones_mensaje');
    if (!contenedor) return;

    const datos = new FormData();
    diasDelSelect().forEach(function(dia) { datos.append('dias[]', dia); });

    const ini = document.getElementById('hora_inicio');
    const fin = document.getElementById('hora_fin');
    const fIni = document.getElementById('fecha_inicio');
    const fFin = document.getElementById('fecha_fin_estimada');

    datos.append('hora_inicio', ini ? ini.value : '');
    datos.append('hora_fin', fin ? fin.value : '');
    datos.append('fecha_inicio', fIni ? fIni.value : '');
    datos.append('fecha_fin', (fFin && fFin.value) ? fFin.value : (fIni ? fIni.value : ''));

    ['Aula', 'Laboratorio', 'Auditorio', 'Salón'].forEach(function(tipo) {
        datos.append('tipos[]', tipo);
    });

    if (idCohorteEdit > 0) datos.append('id_cohorte', idCohorteEdit);

    const tokenMeta = document.querySelector('meta[name="csrf-token"]');
    if (tokenMeta) datos.append('_csrf', tokenMeta.getAttribute('content'));

    if (mensaje) {
        mensaje.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> Recalculando disponibilidad según días, horas y fechas...';
    }

    try {
        const respuesta = await fetch('api_consultar_disponibilidad.php', {
            method: 'POST',
            body: datos,
            credentials: 'same-origin'
        });
        const resultado = await respuesta.json();
        if (!resultado.success) throw new Error(resultado.message || 'No se pudo consultar la disponibilidad.');

        if (version !== versionSalones) return;

        const salones = resultado.salones || [];
        renderizarSalones(salones);

        if (mensaje) {
            const libres = salones.filter(function(s) { return s.disponible; }).length;
            const ocupados = salones.length - libres;
            mensaje.innerHTML = '<i class="fas fa-sync-alt mr-1"></i> Estado actualizado: '
                + libres + ' desbloqueado(s) · ' + ocupados + ' bloqueado(s) por solape en esta franja.';
        }
    } catch (error) {
        if (version !== versionSalones) return;
        if (mensaje) {
            mensaje.innerHTML = '<i class="fas fa-exclamation-triangle text-danger mr-1"></i> '
                + 'No se pudo recalcular la disponibilidad. Intente nuevamente.';
        }
    }
}

function renderizarSalones(salones) {
    const contenedor = document.getElementById('contenedor_salones');
    if (!contenedor) return;

    if (!salones.length) {
        contenedor.innerHTML = '<div class="col-12 text-muted font-italic">No hay aulas o salones registrados en el módulo de Campus Espacios.</div>';
        return;
    }

    let html = '';
    salones.forEach(function(sal) {
        const bloqueado = !sal.disponible || String(sal.estado || '').toUpperCase() === 'BLOQUEADO';
        const nombre = sal.nombre_espacio || '';
        const idCheckbox = 'salon_' + sal.id_espacio;

        // Si la nueva franja entra en conflicto, se libera automáticamente la selección anterior.
        if (bloqueado) salonesSeleccionados.delete(nombre);
        const seleccionado = salonesSeleccionados.has(nombre);
        const claseBorde = bloqueado ? 'bg-light text-muted border-danger'
            : (seleccionado ? 'border-primary bg-white' : 'border-success bg-white');
        const motivo = bloqueado
            ? (sal.motivo || 'Ocupado en esta franja')
            : 'sin solape en esta franja';

        html += '<div class="col-md-4 mb-3">'
            + '<div class="card h-100 p-3 border ' + claseBorde + '" style="border-radius: 12px;">'
            + '<div class="custom-control custom-checkbox">'
            + '<input type="checkbox" class="custom-control-input" id="' + escapeHtml(idCheckbox) + '" '
            + 'name="salones[]" value="' + escapeHtml(nombre) + '" '
            + (seleccionado ? 'checked ' : '') + (bloqueado ? 'disabled ' : '') + '>'
            + '<label class="custom-control-label font-weight-bold d-block text-dark" for="' + escapeHtml(idCheckbox) + '" '
            + 'title="' + escapeHtml(sal.nombre || nombre) + '">'
            + escapeHtml(nombre)
            + '<span class="badge badge-info float-right">' + escapeHtml(sal.capacidad) + ' Alumnos Cap.</span>'
            + '</label></div>'
            + (bloqueado
                ? '<small class="text-danger font-weight-bold mt-2 d-block"><i class="fas fa-lock mr-1"></i> '
                    + escapeHtml(sal.estado || 'BLOQUEADO') + ': ' + escapeHtml(motivo) + '</small>'
                : '<small class="text-success font-weight-bold mt-2 d-block"><i class="fas fa-lock-open mr-1"></i> '
                    + 'DESBLOQUEADO · ' + escapeHtml(motivo) + '</small>')
            + '</div></div>';
    });

    contenedor.innerHTML = html;
}

document.addEventListener('DOMContentLoaded', function() {
    cargarNivelesYMaterias();
    actualizarSeleccionSalones();
    actualizarSalonesPorHorario();
});
</script>

<?php require_once '../../includes/footer.php'; ?>