<?php
// /sistema_nora/modulos/control_estudio/publicar_curso_web.php
require_once '../../config/db.php';
require_once '../../includes/header.php';

if (!tiene_permiso(['superadmin', 'admin', 'control_estudio'], $roles_array)) {
    echo "<div class='alert alert-danger font-weight-bold'>Acceso restringido a Control de Estudio.</div>";
    require_once '../../includes/footer.php';
    exit();
}

$mensaje = '';
$tipo_alerta = '';

// PROCESAR PUBLICACIÓN EN LA PÁGINA WEB (BD Nora_castaneda) CON SUBIDA DE FLYER
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['publicar_curso_web'])) {
    try {
        $nombre       = trim($_POST['nombre']);
        $modalidad    = $_POST['modalidad'];
        $alcance      = trim($_POST['alcance']);
        $cupos        = intval($_POST['cupos']);
        $hora_inicio  = $_POST['hora_inicio'];
        $hora_fin     = $_POST['hora_fin'];
        $fecha_inicio = $_POST['fecha_inicio'];
        $fecha_cierre = $_POST['fecha_cierre'];
        $lugar        = trim($_POST['lugar']);
        $descripcion  = trim($_POST['descripcion']);
        
        // MANEJO DE IMAGEN DE FLYER
        $flyer_path = 'assets/img/flyer_default.jpg'; // Flyer por defecto

        if (isset($_FILES['flyer_imagen']) && $_FILES['flyer_imagen']['error'] === UPLOAD_ERR_OK) {
            $nombre_original = $_FILES['flyer_imagen']['name'];
            $ext = strtolower(pathinfo($nombre_original, PATHINFO_EXTENSION));
            $extensiones_permitidas = ['jpg', 'jpeg', 'png', 'webp'];

            if (in_array($ext, $extensiones_permitidas)) {
                $directorio_destino = '../../uploads/flyers/';
                if (!file_exists($directorio_destino)) {
                    mkdir($directorio_destino, 0777, true);
                }
                $nombre_archivo = 'flyer_' . time() . '_' . rand(100, 999) . '.' . $ext;
                $ruta_completa = $directorio_destino . $nombre_archivo;

                if (move_uploaded_file($_FILES['flyer_imagen']['tmp_name'], $ruta_completa)) {
                    $flyer_path = 'uploads/flyers/' . $nombre_archivo;
                }
            }
        }

        if ($pdo_web) {
            $sql = "INSERT INTO public.cursos 
                    (nombre, modalidad, alcance, cupos, hora_inicio, hora_fin, fecha_inicio, fecha_cierre, lugar, descripcion, flyer_imagen, publicado) 
                    VALUES (:nom, :mod, :alc, :cup, :h_ini, :h_fin, :f_ini, :f_cie, :lug, :desc, :flyer, true)";
            $stmt = $pdo_web->prepare($sql);
            $stmt->execute([
                ':nom'   => $nombre,
                ':mod'   => $modalidad,
                ':alc'   => $alcance,
                ':cup'   => $cupos,
                ':h_ini' => $hora_inicio,
                ':h_fin' => $hora_fin,
                ':f_ini' => $fecha_inicio,
                ':f_cie' => $fecha_cierre,
                ':lug'   => $lugar,
                ':desc'  => $descripcion,
                ':flyer' => $flyer_path
            ]);
            $mensaje = "¡Curso <strong>{$nombre}</strong> publicado exitosamente con su Flyer promocional!";
            $tipo_alerta = "success";
        } else {
            throw new Exception("No hay conexión con la base de datos web.");
        }
    } catch (Exception $e) {
        $mensaje = "Error al publicar: " . $e->getMessage();
        $tipo_alerta = "danger";
    }
}

// Cargar Cursos Web y Cohortes Internas
$cursos_web = [];
$cohortes_internas = [];

try {
    if ($pdo_web) {
        $cursos_web = $pdo_web->query("SELECT * FROM public.cursos ORDER BY id DESC")->fetchAll();
    }
    $cohortes_internas = $pdo->query("SELECT c.id_cohorte, c.codigo_cohorte, m.nombre_programa 
                                      FROM public.cohortes c 
                                      JOIN public.mallas_curriculares m ON c.id_malla = m.id_malla 
                                      ORDER BY c.id_cohorte DESC")->fetchAll();
} catch (Exception $e) {}
?>

<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <h1 class="h3 mb-0 font-weight-bold" style="color: #521f29;">
        <i class="fas fa-globe-americas mr-2 text-warning"></i>Publicación Web de Cursos y Cohortes
    </h1>
    <a href="index.php" class="btn btn-sm btn-secondary font-weight-bold shadow-sm">
        <i class="fas fa-arrow-left mr-1"></i> Volver a Control de Estudio
    </a>
</div>

<?php if (!empty($mensaje)): ?>
    <div class="alert alert-<?= $tipo_alerta ?> alert-dismissible fade show font-weight-bold shadow-sm" role="alert">
        <?= $mensaje ?>
        <button type="button" class="close" data-dismiss="alert">&times;</button>
    </div>
<?php endif; ?>

<div class="row">
    <!-- FORMULARIO CON SUBIDA DE FLYER ENCTYPE MULTIPART -->
    <div class="col-lg-5 mb-4">
        <div class="card shadow border-0" style="border-radius: 15px;">
            <div class="card-header py-3 text-white font-weight-bold" style="background-color: #521f29; border-top-left-radius: 15px; border-top-right-radius: 15px;">
                <i class="fas fa-plus-circle mr-2 text-warning"></i>Publicar Nuevo Curso en la Web
            </div>
            <div class="card-body bg-white p-4">
                <form method="POST" action="publicar_curso_web.php" enctype="multipart/form-data">
                <?= csrf_field() ?>
                    <input type="hidden" name="publicar_curso_web" value="1">
                    
                    <div class="form-group mb-3">
                        <label class="font-weight-bold small" style="color: #521f29;">Nombre del Curso / Taller *</label>
                        <input type="text" name="nombre" class="form-control font-weight-bold" required placeholder="Ej: Barbería Comunitaria">
                    </div>
                    
                    <div class="form-row">
                        <div class="col-6 form-group">
                            <label class="font-weight-bold small" style="color: #521f29;">Modalidad</label>
                            <select name="modalidad" class="form-control font-weight-bold">
                                <option value="Presencial">Presencial</option>
                                <option value="Virtual">Virtual</option>
                            </select>
                        </div>
                        <div class="col-6 form-group">
                            <label class="font-weight-bold small" style="color: #521f29;">Cupos</label>
                            <input type="number" name="cupos" class="form-control text-center font-weight-bold" value="30" required>
                        </div>
                    </div>

                    <div class="form-group mb-3">
                        <label class="font-weight-bold small" style="color: #521f29;">Alcance Territorial</label>
                        <input type="text" name="alcance" class="form-control" value="Solo para residentes de Maracay (Edo. Aragua)">
                    </div>

                    <div class="form-row">
                        <div class="col-6 form-group">
                            <label class="font-weight-bold small" style="color: #521f29;">Hora Inicio</label>
                            <input type="time" name="hora_inicio" class="form-control" value="08:00">
                        </div>
                        <div class="col-6 form-group">
                            <label class="font-weight-bold small" style="color: #521f29;">Hora Fin</label>
                            <input type="time" name="hora_fin" class="form-control" value="12:00">
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="col-6 form-group">
                            <label class="font-weight-bold small" style="color: #521f29;">Apertura Inscripción</label>
                            <input type="date" name="fecha_inicio" class="form-control" value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-6 form-group">
                            <label class="font-weight-bold small" style="color: #521f29;">Cierre Inscripción</label>
                            <input type="date" name="fecha_cierre" class="form-control" value="<?= date('Y-m-d', strtotime('+30 days')) ?>">
                        </div>
                    </div>

                    <div class="form-group mb-3">
                        <label class="font-weight-bold small" style="color: #521f29;">Sede / Lugar</label>
                        <input type="text" name="lugar" class="form-control" value="Sede Principal Bello Monte, Maracay">
                    </div>

                    <!-- CAMPO FLYER DE IMAGEN PROMOCIONAL -->
                    <div class="form-group mb-3">
                        <label class="font-weight-bold small" style="color: #521f29;"><i class="fas fa-image text-warning mr-1"></i> Flyer / Imagen Promocional (Opcional)</label>
                        <input type="file" name="flyer_imagen" class="form-control-file border p-1 rounded w-100" accept="image/*">
                    </div>

                    <div class="form-group mb-4">
                        <label class="font-weight-bold small" style="color: #521f29;">Descripción o Reseña</label>
                        <textarea name="descripcion" class="form-control" rows="2" required></textarea>
                    </div>

                    <button type="submit" class="btn btn-block font-weight-bold text-white shadow" style="background-color: #ff8a00; border-radius: 10px;">
                        <i class="fas fa-paper-plane mr-1"></i> PUBLICAR EN LA WEB
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- CHECKLIST DE CURSOS PUBLICADOS -->
    <div class="col-lg-7 mb-4">
        <div class="card shadow border-0" style="border-radius: 15px;">
            <div class="card-header py-3 text-white font-weight-bold" style="background-color: #521f29; border-top-left-radius: 15px; border-top-right-radius: 15px;">
                <i class="fas fa-check-square mr-2 text-warning"></i>Checklist de Cursos Publicados en Web
            </div>
            <div class="card-body bg-white p-0">
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light text-uppercase small font-weight-bold">
                            <tr>
                                <th>Flyer</th>
                                <th>Curso Web</th>
                                <th>Modalidad</th>
                                <th>Cupos</th>
                                <th>Cohortes Vinculadas</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($cursos_web)): ?>
                                <tr><td colspan="5" class="text-center py-4 text-muted">No existen cursos publicados en la web.</td></tr>
                            <?php else: ?>
                                <?php foreach ($cursos_web as $cw): ?>
                                    <tr>
                                        <td>
                                            <?php if (!empty($cw['flyer_imagen'])): ?>
                                                <img src="../../<?= htmlspecialchars($cw['flyer_imagen']) ?>" alt="Flyer" class="rounded shadow-sm" style="width: 45px; height: 45px; object-fit: cover;">
                                            <?php else: ?>
                                                <span class="badge badge-light border"><i class="fas fa-image text-muted"></i> Sin flyer</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="font-weight-bold" style="color: #521f29;"><?= htmlspecialchars($cw['nombre']) ?></td>
                                        <td><span class="badge badge-dark"><?= htmlspecialchars($cw['modalidad']) ?></span></td>
                                        <td><span class="badge badge-warning text-dark font-weight-bold"><?= intval($cw['cupos']) ?></span></td>
                                        <td>
                                            <div class="p-2 border rounded bg-light" style="max-height: 120px; overflow-y: auto;">
                                                <?php 
                                                $coincidencias = 0;
                                                foreach ($cohortes_internas as $ci): 
                                                    $coincide = (stripos($ci['nombre_programa'], $cw['nombre']) !== false);
                                                    if ($coincide) $coincidencias++;
                                                ?>
                                                    <div class="custom-control custom-checkbox mb-1">
                                                        <input type="checkbox" class="custom-control-input" id="check_<?= $cw['id'] ?>_<?= $ci['id_cohorte'] ?>" <?= $coincide ? 'checked' : '' ?> disabled>
                                                        <label class="custom-control-label small font-weight-bold text-dark" for="check_<?= $cw['id'] ?>_<?= $ci['id_cohorte'] ?>">
                                                            <?= htmlspecialchars($ci['codigo_cohorte']) ?> (<?= htmlspecialchars($ci['nombre_programa']) ?>)
                                                        </label>
                                                    </div>
                                                <?php endforeach; ?>
                                                <?php if ($coincidencias === 0): ?>
                                                    <span class="small text-muted font-italic"><i class="fas fa-info-circle mr-1"></i>Sin cohorte aperturada aún.</span>
                                                <?php endif; ?>
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

<?php require_once '../../includes/footer.php'; ?>