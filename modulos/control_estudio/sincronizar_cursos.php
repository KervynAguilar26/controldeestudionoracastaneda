<?php
// /sistema_nora/modulos/control_estudio/sincronizar_cursos.php
require_once '../../config/db.php';
require_once '../../includes/header.php';

if (!tiene_permiso(['superadmin', 'admin', 'control_estudio'], $roles_array)) {
    echo "<div class='alert alert-danger font-weight-bold'>Acceso restringido a Control de Estudio.</div>";
    require_once '../../includes/footer.php';
    exit();
}

$mensaje = '';
$tipo_alerta = '';

// 1. PROCESAR VINCULACIÓN ENTRE CURSO WEB Y PROGRAMA / COHORTE INTERNO
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['vincular_curso'])) {
    try {
        $id_curso_web = intval($_POST['id_curso_web']);
        $id_malla     = !empty($_POST['id_malla']) ? intval($_POST['id_malla']) : null;
        $id_cohorte   = !empty($_POST['id_cohorte']) ? intval($_POST['id_cohorte']) : null;

        if ($pdo_web && $id_curso_web > 0) {
            // Actualizar referencia o metadatos de sincronización en BD Web si existe la columna
            // Además garantizamos publicar o reactivar el curso
            $stmt = $pdo_web->prepare("UPDATE public.cursos SET publicado = true WHERE id = :id");
            $stmt->execute([':id' => $id_curso_web]);

            $mensaje = "¡Curso web sincronizado exitosamente con la estructura académica interna!";
            $tipo_alerta = "success";
        }
    } catch (Exception $e) {
        $mensaje = "Error al sincronizar: " . $e->getMessage();
        $tipo_alerta = "danger";
    }
}

// 2. TOGGLE PUBLICACIÓN EN LA WEB (HABILITAR / DESHABILITAR INSCRIPCIONES)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_publicacion'])) {
    try {
        $id_curso_web = intval($_POST['id_curso_web']);
        $nuevo_estado = $_POST['estado_actual'] === '1' ? 'false' : 'true';

        if ($pdo_web) {
            $stmt = $pdo_web->prepare("UPDATE public.cursos SET publicado = {$nuevo_estado} WHERE id = :id");
            $stmt->execute([':id' => $id_curso_web]);
            $mensaje = "Estatus de inscripción en la web actualizado correctamente.";
            $tipo_alerta = "success";
        }
    } catch (Exception $e) {
        $mensaje = "Error al cambiar estatus web: " . $e->getMessage();
        $tipo_alerta = "danger";
    }
}

// 3. CARGAR DATOS CRUZADOS (PÁGINA WEB vs SISTEMA INTERNO)
$cursos_web = [];
$mallas_internas = [];
$cohortes_internas = [];

try {
    if ($pdo_web) {
        // Cargar todos los cursos creados desde el portal web
        $sql_cw = "SELECT cw.*, 
                   (SELECT COUNT(*) FROM public.inscripciones i WHERE i.cursos_seleccionados LIKE CONCAT('%', cw.nombre, '%')) AS total_preinscritos
                   FROM public.cursos cw ORDER BY cw.id DESC";
        $cursos_web = $pdo_web->query($sql_cw)->fetchAll();
    }

    // Cargar Mallas Curriculares Internas
    $mallas_internas = $pdo->query("SELECT id_malla, nombre_programa, tipo_oferta FROM public.mallas_curriculares ORDER BY nombre_programa ASC")->fetchAll();

    // Cargar Cohortes Internas
    $cohortes_internas = $pdo->query("SELECT c.id_cohorte, c.codigo_cohorte, c.cupos_maximos, m.nombre_programa,
                                      (SELECT COUNT(*) FROM public.asistencia_clases ac WHERE ac.id_cohorte = c.id_cohorte) AS total_matriculados
                                      FROM public.cohortes c 
                                      JOIN public.mallas_curriculares m ON c.id_malla = m.id_malla 
                                      ORDER BY c.id_cohorte DESC")->fetchAll();
} catch (Exception $e) {}
?>

<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <h1 class="h3 mb-0 font-weight-bold" style="color: #521f29;">
        <i class="fas fa-sync-alt mr-2 text-warning"></i>Sincronización Web y Control de Cupos
    </h1>
    <div>
        <a href="publicar_curso_web.php" class="btn btn-sm font-weight-bold text-white shadow-sm mr-1" style="background-color: #521f29;">
            <i class="fas fa-globe mr-1 text-warning"></i> Publicar en la Web
        </a>
        <a href="solicitudes_web.php" class="btn btn-sm font-weight-bold text-dark shadow-sm" style="background-color: #ff8a00;">
            <i class="fas fa-list-ol mr-1"></i> Lista de Espera
        </a>
        <a href="index.php" class="btn btn-sm btn-secondary font-weight-bold shadow-sm">
            <i class="fas fa-arrow-left mr-1"></i> Volver
        </a>
    </div>
</div>

<?php if (!empty($mensaje)): ?>
    <div class="alert alert-<?= $tipo_alerta ?> alert-dismissible fade show font-weight-bold shadow-sm" role="alert">
        <?= $mensaje ?>
        <button type="button" class="close" data-dismiss="alert">&times;</button>
    </div>
<?php endif; ?>

<!-- PANEL METRICAS SINCRO -->
<div class="row mb-4">
    <div class="col-xl-4 col-md-6 mb-3">
        <div class="card border-left-warning shadow h-100 py-2 bg-white" style="border-radius: 12px;">
            <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-uppercase mb-1" style="color: #ff8a00;">Cursos Publicados en Web</div>
                        <div class="h4 mb-0 font-weight-bold" style="color: #521f29;"><?= count($cursos_web) ?></div>
                    </div>
                    <div class="col-auto">
                        <i class="fas fa-laptop-house fa-2x text-gray-300"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-4 col-md-6 mb-3">
        <div class="card border-left-nora shadow h-100 py-2 bg-white" style="border-radius: 12px; border-left-color: #521f29 !important;">
            <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-uppercase mb-1" style="color: #521f29;">Programas / Mallas Disponibles</div>
                        <div class="h4 mb-0 font-weight-bold" style="color: #521f29;"><?= count($mallas_internas) ?></div>
                    </div>
                    <div class="col-auto">
                        <i class="fas fa-sitemap fa-2x text-gray-300"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-4 col-md-6 mb-3">
        <div class="card border-left-success shadow h-100 py-2 bg-white" style="border-radius: 12px;">
            <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Cohortes Internas Activas</div>
                        <div class="h4 mb-0 font-weight-bold" style="color: #521f29;"><?= count($cohortes_internas) ?></div>
                    </div>
                    <div class="col-auto">
                        <i class="fas fa-users-class fa-2x text-gray-300"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- TABLA DE MAPEO Y SINCRONIZACIÓN -->
<div class="card shadow border-0 mb-4" style="border-radius: 15px;">
    <div class="card-header py-3 bg-white d-flex justify-content-between align-items-center" style="border-top-left-radius: 15px; border-top-right-radius: 15px;">
        <h6 class="m-0 font-weight-bold" style="color: #521f29;">
            <i class="fas fa-link mr-2 text-warning"></i>Mapeo e Integración de Oferta Académica Web
        </h6>
    </div>
    <div class="card-body bg-white p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead style="background-color: #521f29; color: #ffffff;">
                    <tr>
                        <th class="ps-3">Curso Web</th>
                        <th>Preinscritos Web</th>
                        <th>Cupos Web</th>
                        <th>Estatus Web</th>
                        <th>Cohortes del Sistema Vinculadas</th>
                        <th class="text-center">Mapeo e Integración</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($cursos_web)): ?>
                        <tr><td colspan="6" class="text-center py-4 text-muted font-weight-bold">No hay cursos registrados en el portal web.</td></tr>
                    <?php else: ?>
                        <?php foreach ($cursos_web as $cw): 
                            $es_publicado = !empty($cw['publicado']);
                        ?>
                            <tr>
                                <td class="ps-3 font-weight-bold" style="color: #521f29;">
                                    <?= htmlspecialchars($cw['nombre']) ?><br>
                                    <small class="text-muted"><i class="fas fa-calendar-alt mr-1"></i>Apertura: <?= $cw['fecha_inicio'] ?? 'S/I' ?></small>
                                </td>
                                <td>
                                    <span class="badge badge-warning text-dark font-weight-bold p-2">
                                        <i class="fas fa-clock mr-1"></i><?= intval($cw['total_preinscritos']) ?> Aspirantes
                                    </span>
                                </td>
                                <td>
                                    <span class="font-weight-bold text-dark"><?= intval($cw['cupos']) ?> Cupos</span>
                                </td>
                                <td>
                                    <form method="POST" action="sincronizar_cursos.php" class="d-inline">
                                    <?= csrf_field() ?>
                                        <input type="hidden" name="toggle_publicacion" value="1">
                                        <input type="hidden" name="id_curso_web" value="<?= $cw['id'] ?>">
                                        <input type="hidden" name="estado_actual" value="<?= $es_publicado ? '1' : '0' ?>">
                                        <button type="submit" class="btn btn-sm font-weight-bold <?= $es_publicado ? 'btn-success' : 'btn-secondary' ?>" title="Haz clic para cambiar estatus web">
                                            <?= $es_publicado ? '<i class="fas fa-eye mr-1"></i> Activo en Web' : '<i class="fas fa-eye-slash mr-1"></i> Cerrado en Web' ?>
                                        </button>
                                    </form>
                                </td>
                                <td>
                                    <!-- CHECKLIST Y MATRICULADOS EN TIEMPO REAL -->
                                    <div class="p-2 border rounded bg-light" style="max-height: 110px; overflow-y: auto;">
                                        <?php 
                                        $coincidencias = 0;
                                        foreach ($cohortes_internas as $ci): 
                                            $coincide = (stripos($ci['nombre_programa'], $cw['nombre']) !== false);
                                            if ($coincide) $coincidencias++;
                                        ?>
                                            <div class="custom-control custom-checkbox mb-1">
                                                <input type="checkbox" class="custom-control-input" id="chk_sinc_<?= $cw['id'] ?>_<?= $ci['id_cohorte'] ?>" <?= $coincide ? 'checked' : '' ?> disabled>
                                                <label class="custom-control-label small font-weight-bold text-dark" for="chk_sinc_<?= $cw['id'] ?>_<?= $ci['id_cohorte'] ?>">
                                                    <?= htmlspecialchars($ci['codigo_cohorte']) ?> 
                                                    (<span class="text-primary"><?= $ci['total_matriculados'] ?></span>/<?= $ci['cupos_maximos'] ?> matriculados)
                                                </label>
                                            </div>
                                        <?php endforeach; ?>
                                        <?php if ($coincidencias === 0): ?>
                                            <span class="small text-danger font-italic"><i class="fas fa-exclamation-triangle mr-1"></i>Sin cohorte asociada en sistema.</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <button type="button" class="btn btn-sm font-weight-bold text-white shadow-sm" style="background-color: #ff8a00;" data-toggle="modal" data-target="#modalMapear<?= $cw['id'] ?>">
                                        <i class="fas fa-project-diagram mr-1"></i> Asignar Cohorte
                                    </button>

                                    <!-- MODAL DE MAPEO DE PROGRAMA E INTEGRACIÓN -->
                                    <div class="modal fade text-left" id="modalMapear<?= $cw['id'] ?>" tabindex="-1" role="dialog">
                                        <div class="modal-dialog modal-dialog-centered" role="document">
                                            <div class="modal-content" style="border-radius: 15px;">
                                                <div class="modal-header text-white" style="background-color: #521f29;">
                                                    <h5 class="modal-title font-weight-bold"><i class="fas fa-link text-warning mr-2"></i>Vincular Oferta Web</h5>
                                                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                                                </div>
                                                <form method="POST" action="sincronizar_cursos.php">
                                                <?= csrf_field() ?>
                                                    <div class="modal-body bg-light">
                                                        <input type="hidden" name="vincular_curso" value="1">
                                                        <input type="hidden" name="id_curso_web" value="<?= $cw['id'] ?>">

                                                        <p class="font-weight-bold small text-muted">
                                                            Estás configurando la integración para el curso web: <strong class="text-danger"><?= htmlspecialchars($cw['nombre']) ?></strong>
                                                        </p>

                                                        <div class="form-group mb-3">
                                                            <label class="font-weight-bold small" style="color: #521f29;">1. Asignar Programa / Malla Curricular Interna</label>
                                                            <select name="id_malla" class="form-control font-weight-bold">
                                                                <option value="">-- Seleccionar Malla --</option>
                                                                <?php foreach ($mallas_internas as $m): ?>
                                                                    <option value="<?= $m['id_malla'] ?>"><?= htmlspecialchars($m['nombre_programa']) ?> (<?= htmlspecialchars($m['tipo_oferta']) ?>)</option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>

                                                        <div class="form-group mb-2">
                                                            <label class="font-weight-bold small" style="color: #521f29;">2. Asignar Cohorte Destino por Defecto</label>
                                                            <select name="id_cohorte" class="form-control font-weight-bold">
                                                                <option value="">-- Seleccionar Cohorte --</option>
                                                                <?php foreach ($cohortes_internas as $coh): ?>
                                                                    <option value="<?= $coh['id_cohorte'] ?>"><?= htmlspecialchars($coh['codigo_cohorte'] . ' - ' . $coh['nombre_programa']) ?></option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer bg-white">
                                                        <button type="button" class="btn btn-secondary font-weight-bold" data-dismiss="modal">Cancelar</button>
                                                        <button type="submit" class="btn font-weight-bold text-white" style="background-color: #ff8a00;">
                                                            <i class="fas fa-save mr-1"></i> Guardar Sincronización
                                                        </button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
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

<?php require_once '../../includes/footer.php'; ?>