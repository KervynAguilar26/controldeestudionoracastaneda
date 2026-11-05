<?php
// /sistema_nora/modulos/control_estudio/crear_malla.php
require_once '../../config/db.php';

$id_malla_editar = isset($_GET['id']) ? intval($_GET['id']) : 0;
$malla_data = null;
$materias_cargadas = [];

if ($id_malla_editar > 0) {
    $stmt_m = $pdo->prepare("SELECT * FROM public.mallas_curriculares WHERE id_malla = :id");
    $stmt_m->execute([':id' => $id_malla_editar]);
    $malla_data = $stmt_m->fetch(PDO::FETCH_ASSOC);

    if ($malla_data) {
        $stmt_mat = $pdo->prepare("SELECT * FROM public.malla_materias WHERE id_malla = :id ORDER BY modulo_num, id_materia ASC");
        $stmt_mat->execute([':id' => $id_malla_editar]);
        $materias_db = $stmt_mat->fetchAll(PDO::FETCH_ASSOC);

        foreach ($materias_db as $mat) {
            $lineas = explode("\n", $mat['contenido_detallado'] ?? '');
            $contenidos_parsed = [];
            foreach ($lineas as $l) {
                $l = trim($l);
                if (!empty($l)) {
                    if (preg_match('/^(?:•\s*)?(.*?)\s*\((?:(\d+)\s*hrs?)\)$/i', $l, $coincidencias)) {
                        $contenidos_parsed[] = [
                            'titulo' => trim($coincidencias[1]),
                            'horas'  => intval($coincidencias[2])
                        ];
                    } else {
                        $contenidos_parsed[] = [
                            'titulo' => ltrim($l, '• '),
                            'horas'  => 8
                        ];
                    }
                }
            }
            if (empty($contenidos_parsed)) {
                $contenidos_parsed[] = ['titulo' => '', 'horas' => 8];
            }

            $materias_cargadas[] = [
                'modulo'     => $mat['modulo_num'],
                'nombre'     => $mat['nombre_materia'],
                'contenidos' => $contenidos_parsed
            ];
        }
    }
}

$redireccionar_exito = false;
$mensaje = ''; 
$tipo_alerta = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_malla'])) {
    try {
        $pdo->beginTransaction();

        $nombre_programa = trim($_POST['nombre_programa'] ?? '');
        $tipo_oferta     = $_POST['tipo_oferta'] ?? 'Curso';
        $tipo_regimen    = $_POST['tipo_regimen'] ?? 'Semestre';
        $total_periodos  = intval($_POST['total_periodos'] ?? 1);
        $pernocta        = (!empty($_POST['requiere_pernocta']) && $_POST['requiere_pernocta'] !== 'false') ? 'true' : 'false';
        $descripcion     = trim($_POST['descripcion'] ?? '');
        $id_malla_post   = intval($_POST['id_malla'] ?? 0);

        if ($total_periodos < 1) { $total_periodos = 1; }

        if ($id_malla_post > 0) {
            $sql_malla = "UPDATE public.mallas_curriculares 
                          SET nombre_programa = :nombre, tipo_oferta = :tipo, tipo_regimen = :regimen, 
                              total_periodos = :periodos, requiere_pernocta = :pernocta, descripcion = :desc 
                          WHERE id_malla = :id";
            $stmt_m = $pdo->prepare($sql_malla);
            $stmt_m->execute([
                ':nombre'   => $nombre_programa,
                ':tipo'     => $tipo_oferta,
                ':regimen'  => $tipo_regimen,
                ':periodos' => $total_periodos,
                ':pernocta' => $pernocta,
                ':desc'     => $descripcion,
                ':id'       => $id_malla_post
            ]);
            $id_malla = $id_malla_post;
            $pdo->prepare("DELETE FROM public.malla_materias WHERE id_malla = :id")->execute([':id' => $id_malla]);
        } else {
            $sql_malla = "INSERT INTO public.mallas_curriculares 
                          (nombre_programa, tipo_oferta, tipo_regimen, total_periodos, requiere_pernocta, descripcion) 
                          VALUES (:nombre, :tipo, :regimen, :periodos, :pernocta, :desc) RETURNING id_malla";
            $stmt_m = $pdo->prepare($sql_malla);
            $stmt_m->execute([
                ':nombre'   => $nombre_programa,
                ':tipo'     => $tipo_oferta,
                ':regimen'  => $tipo_regimen,
                ':periodos' => $total_periodos,
                ':pernocta' => $pernocta,
                ':desc'     => $descripcion
            ]);
            $id_malla = $stmt_m->fetchColumn();
        }

        if (!empty($_POST['materias'])) {
            $sql_mat = "INSERT INTO public.malla_materias 
                        (id_malla, modulo_num, nombre_materia, contenido_detallado, horas_academicas) 
                        VALUES (:malla, :modulo, :nombre, :contenido, :horas)";
            $stmt_mat = $pdo->prepare($sql_mat);

            foreach ($_POST['materias'] as $m) {
                if (!empty($m['nombre'])) {
                    $contenidos_array = [];
                    $total_horas_materia = 0;

                    if (!empty($m['contenidos'])) {
                        foreach ($m['contenidos'] as $c) {
                            $titulo_c = trim($c['titulo']);
                            $horas_c  = intval($c['horas']);
                            if (!empty($titulo_c)) {
                                $contenidos_array[] = "• " . $titulo_c . " (" . $horas_c . " hrs)";
                                $total_horas_materia += $horas_c;
                            }
                        }
                    }

                    $stmt_mat->execute([
                        ':malla'     => $id_malla,
                        ':modulo'    => intval($m['modulo']),
                        ':nombre'    => trim($m['nombre']),
                        ':contenido' => implode("\n", $contenidos_array),
                        ':horas'     => $total_horas_materia
                    ]);
                }
            }
        }

        $pdo->commit();
        $redireccionar_exito = true;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $mensaje = "Error al guardar la malla: " . $e->getMessage();
        $tipo_alerta = "danger";
    }
}

if ($redireccionar_exito) {
    header("Location: lista_mallas.php");
    exit();
}

require_once '../../includes/header.php';
?>

<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <h1 class="h3 mb-0 font-weight-bold" style="color: #521f29;">
        <i class="fas fa-sitemap mr-2 text-warning"></i>Diseño Modular de Malla Curricular
    </h1>
    <a href="lista_mallas.php" class="btn btn-sm btn-secondary font-weight-bold"><i class="fas fa-arrow-left mr-1"></i> Volver</a>
</div>

<?php if (!empty($mensaje)): ?>
    <div class="alert alert-<?= $tipo_alerta ?> font-weight-bold"><?= $mensaje ?></div>
<?php endif; ?>

<form method="POST" action="crear_malla.php<?= $malla_data ? '?id=' . $malla_data['id_malla'] : '' ?>">
    <?php if (function_exists('csrf_field')) { echo csrf_field(); } ?>
    <input type="hidden" name="id_malla" value="<?= $malla_data['id_malla'] ?? 0 ?>">
    
    <div class="card shadow mb-4" style="border-radius: 15px;">
        <div class="card-header py-3 text-white font-weight-bold" style="background-color: #521f29;">
            Configuración de Oferta Académica
        </div>
        <div class="card-body bg-white p-4">
            <div class="form-row">
                <div class="col-md-5 mb-3">
                    <label class="font-weight-bold text-dark">Nombre del Programa / Carrera *</label>
                    <input type="text" name="nombre_programa" class="form-control font-weight-bold" value="<?= htmlspecialchars($malla_data['nombre_programa'] ?? '') ?>" placeholder="Ej: Técnico en Mecánica / Licenciatura en Administración" required>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="font-weight-bold text-dark">Nivel / Tipo de Oferta *</label>
                    <?php $tipo_actual = $malla_data['tipo_oferta'] ?? 'Pregrado'; ?>
                    <select name="tipo_oferta" class="form-control font-weight-bold" required onchange="actualizarRegimenSegunOferta(this.value)">
                        <optgroup label="Educación Universitaria y Superior">
                            <option value="Pregrado" <?= $tipo_actual === 'Pregrado' ? 'selected' : '' ?>>Pregrado / Licenciatura (8-10 Semestres)</option>
                            <option value="TSU" <?= $tipo_actual === 'TSU' ? 'selected' : '' ?>>Técnico Superior Universitario (4-6 Semestres)</option>
                            <option value="Postgrado" <?= $tipo_actual === 'Postgrado' ? 'selected' : '' ?>>Postgrado / Especialización</option>
                            <option value="Maestría" <?= $tipo_actual === 'Maestría' ? 'selected' : '' ?>>Maestría</option>
                            <option value="Doctorado" <?= $tipo_actual === 'Doctorado' ? 'selected' : '' ?>>Doctorado</option>
                        </optgroup>
                        <optgroup label="Técnicos y Cursos Cortos">
                            <option value="Carrera Corta" <?= $tipo_actual === 'Carrera Corta' ? 'selected' : '' ?>>Carrera Técnica (1050 Horas)</option>
                            <option value="Diplomado Intensivo" <?= $tipo_actual === 'Diplomado Intensivo' ? 'selected' : '' ?>>Diplomado</option>
                            <option value="Curso" <?= $tipo_actual === 'Curso' ? 'selected' : '' ?>>Curso Corto / Taller</option>
                            <option value="Foro / Taller" <?= $tipo_actual === 'Foro / Taller' ? 'selected' : '' ?>>Foro / Actividad Académica</option>
                        </optgroup>
                        <optgroup label="Educación Básica y Media">
                            <option value="Educación Básica" <?= $tipo_actual === 'Educación Básica' ? 'selected' : '' ?>>Educación Básica (Por Años)</option>
                            <option value="Bachillerato" <?= $tipo_actual === 'Bachillerato' ? 'selected' : '' ?>>Bachillerato / Educación Media (Por Años)</option>
                        </optgroup>
                    </select>
                </div>
                <div class="col-md-3 mb-3 d-flex align-items-center pt-4">
                    <div class="custom-control custom-checkbox">
                        <?php $chk_per = !empty($malla_data['requiere_pernocta']) && $malla_data['requiere_pernocta'] !== 'false'; ?>
                        <input type="checkbox" class="custom-control-input" id="pernoctaCheck" name="requiere_pernocta" <?= $chk_per ? 'checked' : '' ?>>
                        <label class="custom-control-label font-weight-bold text-danger" for="pernoctaCheck">¿Requiere Pernocta?</label>
                    </div>
                </div>
            </div>

            <div class="form-row p-3 mb-3 rounded" style="background-color: #fcf8f2; border: 2px dashed #ff8a00;">
                <div class="col-md-6 mb-2">
                    <label class="font-weight-bold text-dark">Régimen Académico *</label>
                    <?php $reg_actual = $malla_data['tipo_regimen'] ?? 'Semestre'; ?>
                    <select name="tipo_regimen" id="tipo_regimen" class="form-control font-weight-bold" required onchange="reconstruirPeriodos()">
                        <option value="Semestre" <?= $reg_actual === 'Semestre' ? 'selected' : '' ?>>Semestral (Semestres)</option>
                        <option value="Año" <?= $reg_actual === 'Año' ? 'selected' : '' ?>>Anual (Años Académicos)</option>
                        <option value="Trimestre" <?= $reg_actual === 'Trimestre' ? 'selected' : '' ?>>Trimestral (Trimestres)</option>
                        <option value="Módulo" <?= $reg_actual === 'Módulo' ? 'selected' : '' ?>>Módulos / Bloques</option>
                    </select>
                </div>
                <div class="col-md-6 mb-2">
                    <label class="font-weight-bold text-dark">Total de Periodos / Semestres / Años *</label>
                    <input type="number" name="total_periodos" id="total_periodos" class="form-control font-weight-bold text-center" value="<?= $malla_data['total_periodos'] ?? 10 ?>" min="1" max="12" required onchange="reconstruirPeriodos()" onkeyup="reconstruirPeriodos()">
                </div>
            </div>
        </div>
    </div>

    <!-- CÁLCULO GENERAL DE HORAS -->
    <div class="card shadow mb-4 p-3" style="border-left: 5px solid #ff8a00; background-color: #fff8f0;">
        <div class="row align-items-center">
            <div class="col-md-4 text-center border-right">
                <span class="text-uppercase small font-weight-bold text-muted">Total Horas de la Carrera</span>
                <span class="h2 font-weight-bold text-danger d-block mb-0" id="granTotalHoras">0 hrs</span>
            </div>
            <div class="col-md-8">
                <span class="text-uppercase small font-weight-bold d-block mb-1 text-dark">Desglose acumulado por periodo:</span>
                <div class="d-flex flex-wrap" id="contenedorResumenPeriodos"></div>
            </div>
        </div>
    </div>

    <!-- MATERIAS -->
    <div class="card shadow mb-4" style="border-radius: 15px;">
        <div class="card-header py-3 d-flex justify-content-between align-items-center" style="background-color: #521f29;">
            <h6 class="m-0 font-weight-bold text-white"><i class="fas fa-book mr-2"></i>Asignaturas y Horas por Contenido</h6>
            <button type="button" class="btn btn-sm text-white font-weight-bold" style="background-color: #ff8a00;" onclick="agregarMateria()">
                <i class="fas fa-plus mr-1"></i> Agregar Materia
            </button>
        </div>
        <div class="card-body bg-white p-4" id="contenedorMaterias"></div>

        <div class="p-4 bg-white border-top">
            <button type="submit" name="guardar_malla" class="btn btn-block font-weight-bold text-white py-3" style="background-color: #ff8a00; border-radius: 10px;">
                <i class="fas fa-save mr-2"></i> GUARDAR Y REGISTRAR MALLA CURRICULAR
            </button>
        </div>
    </div>
</form>

<script>
let contadorMaterias = 0;
let contadoresContenidos = {};
const datosEdicion = <?= json_encode($materias_cargadas) ?>;

document.addEventListener('DOMContentLoaded', function() {
    if (datosEdicion && datosEdicion.length > 0) {
        datosEdicion.forEach(mat => agregarMateriaConDatos(mat));
    } else {
        agregarMateria();
    }
    reconstruirPeriodos();
});

function reconstruirPeriodos() {
    const totalP = parseInt(document.getElementById('total_periodos').value) || 1;
    const regimenNombre = document.getElementById('tipo_regimen').value || 'Semestre';
    const selectores = document.querySelectorAll('.select-periodo-dinamico');

    selectores.forEach(sel => {
        const valPrevio = sel.getAttribute('data-val') || sel.value || 1;
        sel.innerHTML = '';
        for (let i = 1; i <= totalP; i++) {
            const opt = document.createElement('option');
            opt.value = i;
            opt.innerText = `${regimenNombre} ${i}`;
            if (i === parseInt(valPrevio)) { opt.selected = true; }
            sel.appendChild(opt);
        }
    });
    calcularTotales();
}

function agregarMateriaConDatos(matData) {
    const contenedor = document.getElementById('contenedorMaterias');
    const idMat = contadorMaterias;
    contadoresContenidos[idMat] = 0;

    const div = document.createElement('div');
    div.className = 'card mb-4 border-left-warning shadow-sm bloque-materia';
    div.id = `materia_${idMat}`;
    div.style.borderRadius = '10px';
    div.style.borderLeft = '5px solid #ff8a00 !important';

    div.innerHTML = `
        <div class="card-header bg-light d-flex justify-content-between align-items-center py-2">
            <span class="font-weight-bold text-uppercase" style="color: #521f29;"><i class="fas fa-journal-whills mr-1"></i> Asignatura N° <span class="num-materia">${idMat + 1}</span></span>
            <button type="button" class="btn btn-sm btn-danger font-weight-bold" onclick="eliminarMateria('materia_${idMat}')"><i class="fas fa-trash mr-1"></i> Eliminar Materia</button>
        </div>
        <div class="card-body p-3">
            <div class="form-row">
                <div class="col-md-4 mb-2">
                    <label class="font-weight-bold small text-dark">Periodo / Semestre / Año *</label>
                    <select name="materias[${idMat}][modulo]" class="form-control font-weight-bold select-periodo-dinamico" onchange="calcularTotales()" required data-val="${matData.modulo}"></select>
                </div>
                <div class="col-md-8 mb-2">
                    <label class="font-weight-bold small text-dark">Nombre de la Asignatura *</label>
                    <input type="text" name="materias[${idMat}][nombre]" class="form-control font-weight-bold" value="${matData.nombre || ''}" placeholder="Ej: Matemática I / Física Aplicada" required>
                </div>
            </div>

            <div class="p-3 mt-2 rounded bg-light" style="border: 1px solid #ddd;">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <label class="font-weight-bold small text-uppercase m-0 text-dark"><i class="fas fa-list-ol mr-1"></i> Desglose de Contenidos y Horas Académicas</label>
                    <button type="button" class="btn btn-xs btn-success font-weight-bold" onclick="agregarContenido(${idMat})"><i class="fas fa-plus mr-1"></i> Agregar Tema/Contenido</button>
                </div>
                <div id="contenedorContenidos_${idMat}"></div>
                <div class="text-right mt-2 pt-2 border-top">
                    <span class="font-weight-bold small text-dark">Total Horas de la Materia: <strong id="totalHoras_${idMat}" class="h6 text-danger font-weight-bold">0 hrs</strong></span>
                </div>
            </div>
        </div>
    `;

    contenedor.appendChild(div);

    if (matData.contenidos && matData.contenidos.length > 0) {
        matData.contenidos.forEach(c => agregarContenidoConDatos(idMat, c.titulo, c.horas));
    } else {
        agregarContenido(idMat);
    }
    contadorMaterias++;
}

function agregarMateria() {
    agregarMateriaConDatos({ modulo: 1, nombre: '', contenidos: [] });
    reconstruirPeriodos();
}

function agregarContenidoConDatos(idMateria, titulo = '', horas = 8) {
    const contenedor = document.getElementById(`contenedorContenidos_${idMateria}`);
    const idCont = contadoresContenidos[idMateria];

    const div = document.createElement('div');
    div.className = `form-row align-items-center mb-2 fila-contenido-${idMateria}`;
    div.innerHTML = `
        <div class="col-md-8">
            <input type="text" name="materias[${idMateria}][contenidos][${idCont}][titulo]" class="form-control form-control-sm font-weight-bold" value="${titulo}" placeholder="Título del tema o unidad..." required>
        </div>
        <div class="col-md-3">
            <div class="input-group input-group-sm">
                <input type="number" name="materias[${idMateria}][contenidos][${idCont}][horas]" class="form-control form-control-sm text-center font-weight-bold input-horas-${idMateria} input-horas-global" value="${horas}" min="1" required onchange="calcularMateria(${idMateria})" onkeyup="calcularMateria(${idMateria})">
                <div class="input-group-append"><span class="input-group-text font-weight-bold">hrs</span></div>
            </div>
        </div>
        <div class="col-md-1 text-center">
            <button type="button" class="btn btn-sm btn-outline-danger" onclick="eliminarContenido(this, ${idMateria})"><i class="fas fa-times"></i></button>
        </div>
    `;

    contenedor.appendChild(div);
    contadoresContenidos[idMateria]++;
    calcularMateria(idMateria);
}

function agregarContenido(idMateria) {
    agregarContenidoConDatos(idMateria, '', 8);
}

function eliminarContenido(btn, idMateria) {
    const filas = document.querySelectorAll(`.fila-contenido-${idMateria}`);
    if (filas.length > 1) {
        btn.closest('.form-row').remove();
        calcularMateria(idMateria);
    }
}

function eliminarMateria(idElemento) {
    if (document.querySelectorAll('.bloque-materia').length > 1) {
        document.getElementById(idElemento).remove();
        calcularTotales();
    }
}

function calcularMateria(idMateria) {
    const inputs = document.querySelectorAll(`.input-horas-${idMateria}`);
    let total = 0;
    inputs.forEach(inp => total += parseInt(inp.value) || 0);
    const label = document.getElementById(`totalHoras_${idMateria}`);
    if (label) label.innerText = total + " hrs";
    calcularTotales();
}

function calcularTotales() {
    let granTotal = 0;
    const totalP = parseInt(document.getElementById('total_periodos').value) || 1;
    const regimenNombre = document.getElementById('tipo_regimen').value || 'Semestre';
    let horasPorPeriodo = {};

    for (let i = 1; i <= totalP; i++) horasPorPeriodo[i] = 0;

    document.querySelectorAll('.bloque-materia').forEach(mat => {
        const selectP = mat.querySelector('.select-periodo-dinamico');
        const numP = selectP ? (parseInt(selectP.value) || 1) : 1;
        let sumaMat = 0;
        mat.querySelectorAll('.input-horas-global').forEach(inp => sumaMat += parseInt(inp.value) || 0);

        granTotal += sumaMat;
        if (horasPorPeriodo[numP] !== undefined) horasPorPeriodo[numP] += sumaMat;
    });

    document.getElementById('granTotalHoras').innerText = granTotal + " hrs";
    const contenedor = document.getElementById('contenedorResumenPeriodos');
    contenedor.innerHTML = '';

    for (let i = 1; i <= totalP; i++) {
        const badge = document.createElement('span');
        badge.className = 'badge badge-warning text-dark font-weight-bold p-2 mr-2 mb-1 shadow-sm';
        badge.innerText = `${regimenNombre} ${i}: ${horasPorPeriodo[i] || 0} hrs`;
        contenedor.appendChild(badge);
    }
}

function actualizarRegimenSegunOferta(tipo) {
    const regSelect = document.getElementById('tipo_regimen');
    const perInput = document.getElementById('total_periodos');

    if (tipo === 'Pregrado') { regSelect.value = 'Semestre'; perInput.value = 10; }
    else if (tipo === 'TSU') { regSelect.value = 'Semestre'; perInput.value = 6; }
    else if (tipo === 'Bachillerato' || tipo === 'Educación Básica') { regSelect.value = 'Año'; perInput.value = 5; }
    else if (tipo === 'Carrera Corta') { regSelect.value = 'Módulo'; perInput.value = 3; }
    else if (tipo === 'Postgrado' || tipo === 'Maestría' || tipo === 'Doctorado') { regSelect.value = 'Trimestre'; perInput.value = 4; }
    else { regSelect.value = 'Módulo'; perInput.value = 1; }

    reconstruirPeriodos();
}
</script>

<?php require_once '../../includes/footer.php'; ?>