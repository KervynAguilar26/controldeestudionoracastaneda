<?php
// /sistema_nora/mi_perfil.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'config/db.php';
require_once 'includes/header.php';

$id_user = $_SESSION['id_usuario'] ?? 0;
$msg = '';
$error = '';

// 1. Catálogos
$etnias_list = [];
$discapacidades_list = [];
try {
    $etnias_list = $pdo->query("SELECT id, nombre FROM public.etnia WHERE activo = 1 ORDER BY nombre ASC")->fetchAll();
    $discapacidades_list = $pdo->query("SELECT id, nombre FROM public.discapacidad WHERE activo = 1 ORDER BY nombre ASC")->fetchAll();
} catch (Exception $e) {}

// 2. Materias y Profesor
$es_profesor_asignado = false;
$materias_disponibles = [];
try {
    if ($id_user > 0) {
        $stmt_prof = $pdo->prepare("SELECT ap.id_asignacion FROM public.asignaciones_profesor ap WHERE ap.id_profesor = :id_prof");
        $stmt_prof->execute([':id_prof' => $id_user]);
        if ($stmt_prof->fetch()) { $es_profesor_asignado = true; }
    }
    if (!$es_profesor_asignado) {
        $materias_disponibles = $pdo->query("SELECT mm.id_materia, mm.nombre_materia, mc.nombre_programa FROM public.malla_materias mm JOIN public.mallas_curriculares mc ON mm.id_malla = mc.id_malla ORDER BY mc.nombre_programa, mm.nombre_materia ASC")->fetchAll();
    }
} catch (Exception $e) {}

// 3. Procesar Actualización de Perfil (NO se procesa cargo_funcion aquí)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['actualizar_perfil'])) {
    try {
        $correo         = trim($_POST['correo']);
        $telefono       = trim($_POST['telefono']);
        $etnia          = $_POST['etnia'] ?? 'Ninguna';
        $discapacidad   = $_POST['tipo_discapacidad'] ?? 'Ninguna';
        $estado         = trim($_POST['estado']);
        $municipio      = trim($_POST['municipio']);
        $parroquia      = trim($_POST['parroquia']);
        $dir_completa   = trim($_POST['direccion_completa']);
        $inscrito_cne   = $_POST['inscrito_cne'] ?? 'No';
        $cne_estado     = trim($_POST['cne_estado'] ?? '');
        $cne_municipio  = trim($_POST['cne_municipio'] ?? '');
        $cne_parroquia  = trim($_POST['cne_parroquia'] ?? '');
        $cne_centro     = trim($_POST['cne_centro_electoral'] ?? '');
        $tiene_hijos    = $_POST['tiene_hijos'] ?? 'No';
        $cantidad_hijos = intval($_POST['cantidad_hijos'] ?? 0);
        $nueva_p        = trim($_POST['nueva_password'] ?? '');
        $confirmar_p    = trim($_POST['confirmar_password'] ?? '');

        if (!empty($nueva_p)) {
            if ($nueva_p !== $confirmar_p) { throw new Exception("Las contraseñas no coinciden."); }
            if (strlen($nueva_p) < 8 || !preg_match('/[\W_]/', $nueva_p)) { throw new Exception("La contraseña debe tener mínimo 8 caracteres y un carácter especial."); }
        }

        $dir_perfiles = __DIR__ . '/public/uploads/perfiles/';
        if (!is_dir($dir_perfiles)) { mkdir($dir_perfiles, 0777, true); }

        $foto_nombre = null;
        if (!empty($_POST['foto_camara_base64'])) {
            $data = $_POST['foto_camara_base64'];
            if (preg_match('/^data:image\/(\w+);base64,/', $data)) {
                $data = substr($data, strpos($data, ',') + 1);
                $foto_nombre = 'foto_webcam_' . $id_user . '_' . time() . '.jpg';
                file_put_contents($dir_perfiles . $foto_nombre, base64_decode($data));
            }
        } else if (isset($_FILES['foto_file']) && $_FILES['foto_file']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['foto_file']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                $foto_nombre = 'foto_user_' . $id_user . '_' . time() . '.' . $ext;
                move_uploaded_file($_FILES['foto_file']['tmp_name'], $dir_perfiles . $foto_nombre);
            }
        }

        $sql_up = "UPDATE public.usuarios SET 
            email = :cor, correo = :cor, telefono = :tel, etnia = :et, tipo_discapacidad = :disc, 
            estado = :est, municipio = :mun, parroquia = :parr, direccion_completa = :dir, 
            inscrito_cne = :cne, cne_estado = :cne_est, cne_municipio = :cne_mun, cne_parroquia = :cne_parr, 
            cne_centro_electoral = :cne_cent, tiene_hijos = :hijos, cantidad_hijos = :cant_h" . 
            ($foto_nombre ? ", foto_perfil = :foto" : "") . 
            " WHERE id_usuario = :id";

        $params = [
            ':cor' => $correo, ':tel' => $telefono, ':et' => $etnia, ':disc' => $discapacidad,
            ':est' => $estado, ':mun' => $municipio, ':parr' => $parroquia, ':dir' => $dir_completa,
            ':cne' => $inscrito_cne, ':cne_est' => $cne_estado, ':cne_mun' => $cne_municipio,
            ':cne_parr' => $cne_parroquia, ':cne_cent' => $cne_centro, ':hijos' => $tiene_hijos,
            ':cant_h' => $cantidad_hijos, ':id' => $id_user
        ];
        if ($foto_nombre) $params[':foto'] = $foto_nombre;

        $stmt = $pdo->prepare($sql_up);
        $stmt->execute($params);

        if (!empty($nueva_p)) {
            $hash = password_hash($nueva_p, PASSWORD_BCRYPT);
            $pdo->prepare("UPDATE public.usuarios SET password = :p WHERE id_usuario = :id")->execute([':p' => $hash, ':id' => $id_user]);
        }

        $msg = "Perfil actualizado con éxito.";
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// 4. Cargar Usuario
$stmt_u = $pdo->prepare("SELECT * FROM public.usuarios WHERE id_usuario = :id LIMIT 1");
$stmt_u->execute([':id' => $id_user]);
$u = $stmt_u->fetch() ?: [];

$tiene_foto_valida = !empty($u['foto_perfil']) && $u['foto_perfil'] !== 'imagen_perfil_generico.jpg' && file_exists(__DIR__ . '/public/uploads/perfiles/' . $u['foto_perfil']);
$foto_ruta = $tiene_foto_valida ? 'public/uploads/perfiles/' . $u['foto_perfil'] : 'assets/img/imagen_perfil_generico.jpg';
$qr_url = "https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=" . urlencode($u['cedula'] ?? '');
?>

<div class="row justify-content-center my-3">
    <div class="col-lg-8 mb-4">
        <div class="card shadow border-0" style="border-radius: 15px;">
            <div class="card-header text-white font-weight-bold py-3" style="background-color: #521f29; border-top-left-radius: 15px; border-top-right-radius: 15px;">
                <i class="fas fa-user-cog mr-2"></i> Actualización de Mi Perfil de Usuario
            </div>
            <div class="card-body p-4 bg-white">

                <?php if (!empty($msg)): ?><div class="alert alert-success font-weight-bold shadow-sm"><i class="fas fa-check-circle mr-2"></i><?= $msg ?></div><?php endif; ?>
                <?php if (!empty($error)): ?><div class="alert alert-danger font-weight-bold shadow-sm"><i class="fas fa-exclamation-triangle mr-2"></i><?= $error ?></div><?php endif; ?>

                <form method="POST" action="mi_perfil.php" enctype="multipart/form-data">
                <?= csrf_field() ?>
                    <input type="hidden" name="actualizar_perfil" value="1">

                    <!-- FOTO -->
                    <div class="form-row align-items-center mb-4">
                        <div class="col-md-4 text-center">
                            <img id="imgPreview" src="<?= $foto_ruta ?>" class="rounded-circle shadow border" style="width: 130px; height: 130px; object-fit: cover; border: 4px solid #ff8a00 !important;">
                        </div>
                        <div class="col-md-8">
                            <input type="hidden" name="foto_camara_base64" id="fotoBase64">
                            <div class="mb-2">
                                <label class="btn btn-outline-danger font-weight-bold btn-block">
                                    <i class="fas fa-upload mr-1"></i> Seleccionar Imagen
                                    <input type="file" name="foto_file" accept="image/*" style="display: none;" onchange="previewFile(this)">
                                </label>
                            </div>
                            <button type="button" class="btn btn-outline-dark font-weight-bold btn-block" onclick="abrirCamara()">
                                <i class="fas fa-camera mr-1"></i> Tomar Foto con Cámara Web
                            </button>
                        </div>
                    </div>

                    <!-- DATOS PERSONALES -->
                    <h6 class="font-weight-bold text-uppercase border-bottom pb-2" style="color: #521f29;"><i class="fas fa-id-card mr-2"></i>Datos Personales Base</h6>
                    <div class="form-row">
                        <div class="col-md-6 mb-3">
                            <label class="font-weight-bold small">Cédula</label>
                            <input type="text" class="form-control font-weight-bold" value="V-<?= htmlspecialchars($u['cedula'] ?? '') ?>" readonly>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="font-weight-bold small">Nombre y Apellido</label>
                            <input type="text" class="form-control font-weight-bold" value="<?= htmlspecialchars(($u['nombre'] ?? '') . ' ' . ($u['apellido'] ?? '')) ?>" readonly>
                        </div>
                    </div>

                    <!-- CARGO / FUNCIÓN DEL TRABAJADOR (BLOQUEADO SOLO LECTURA) -->
                    <h6 class="font-weight-bold text-uppercase border-bottom pb-2 mt-2" style="color: #521f29;"><i class="fas fa-user-tag mr-2"></i>Cargo y Función Institucional</h6>
                    <div class="form-group mb-3">
                        <label class="font-weight-bold small" style="color: #521f29;">Cargo Asignado (Solo Modificable por Administrador)</label>
                        <input type="text" class="form-control font-weight-bold text-danger bg-light" value="<?= htmlspecialchars($u['cargo_funcion'] ?? 'Estudiante') ?>" readonly>
                    </div>

                    <!-- CONTACTO, ETNIA Y DISCAPACIDAD -->
                    <h6 class="font-weight-bold text-uppercase border-bottom pb-2 mt-2" style="color: #521f29;"><i class="fas fa-edit mr-2"></i>Datos de Contacto y Perfil</h6>
                    <div class="form-row">
                        <div class="col-md-6 mb-3">
                            <label class="font-weight-bold small">Correo Electrónico *</label>
                            <input type="email" name="correo" class="form-control font-weight-bold" value="<?= htmlspecialchars($u['correo'] ?? $u['email'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="font-weight-bold small">Teléfono *</label>
                            <input type="text" name="telefono" class="form-control font-weight-bold" value="<?= htmlspecialchars($u['telefono'] ?? '') ?>" required>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="col-md-6 mb-3">
                            <label class="font-weight-bold small">Etnia</label>
                            <select name="etnia" class="form-control font-weight-bold">
                                <option value="Ninguna">Ninguna / No aplica</option>
                                <?php foreach ($etnias_list as $e): ?>
                                    <option value="<?= htmlspecialchars($e['nombre']) ?>" <?= (($u['etnia'] ?? '') === $e['nombre']) ? 'selected' : '' ?>><?= htmlspecialchars($e['nombre']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="font-weight-bold small">Tipo de Discapacidad</label>
                            <select name="tipo_discapacidad" class="form-control font-weight-bold">
                                <option value="Ninguna">Ninguna</option>
                                <?php foreach ($discapacidades_list as $d): ?>
                                    <option value="<?= htmlspecialchars($d['nombre']) ?>" <?= (($u['tipo_discapacidad'] ?? '') === $d['nombre']) ? 'selected' : '' ?>><?= htmlspecialchars($d['nombre']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- DIRECCIÓN -->
                    <h6 class="font-weight-bold text-uppercase border-bottom pb-2 mt-2" style="color: #521f29;"><i class="fas fa-map-marker-alt mr-2"></i>Dirección de Habitación</h6>
                    <div class="form-row">
                        <div class="col-md-4 mb-3">
                            <label class="font-weight-bold small">Estado *</label>
                            <input type="text" name="estado" class="form-control" value="<?= htmlspecialchars($u['estado'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="font-weight-bold small">Municipio *</label>
                            <input type="text" name="municipio" class="form-control" value="<?= htmlspecialchars($u['municipio'] ?? '') ?>" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="font-weight-bold small">Parroquia *</label>
                            <input type="text" name="parroquia" class="form-control" value="<?= htmlspecialchars($u['parroquia'] ?? '') ?>" required>
                        </div>
                    </div>
                    <div class="form-group mb-3">
                        <label class="font-weight-bold small">Dirección Completa *</label>
                        <textarea name="direccion_completa" class="form-control" rows="2" required><?= htmlspecialchars($u['direccion_completa'] ?? '') ?></textarea>
                    </div>

                    <button type="submit" class="btn btn-block font-weight-bold text-white py-3 shadow" style="background-color: #521f29; border-radius: 10px;">
                        ACTUALIZAR MIS DATOS
                    </button>
                </form>

            </div>
        </div>
    </div>

    <!-- CARNET DIGITAL Y PASE QR -->
    <div class="col-lg-4 mb-4">
        <div class="card shadow border-0 sticky-top" style="border-radius: 15px; top: 20px;">
            <div class="card-header text-white font-weight-bold py-3 text-center" style="background-color: #521f29; border-top-left-radius: 15px; border-top-right-radius: 15px;">
                <i class="fas fa-id-card mr-2 text-warning"></i>Carnet Digital y Pase QR
            </div>
            <div class="card-body p-4 bg-white text-center">
                <?php if (!$tiene_foto_valida): ?>
                    <div class="alert alert-warning border-0 p-3 shadow-sm" style="border-radius: 10px;">
                        <i class="fas fa-exclamation-triangle fa-2x text-warning mb-2"></i>
                        <h6 class="font-weight-bold" style="color: #521f29;">Foto no cargada</h6>
                        <p class="small font-weight-bold mb-0">Para habilitar tu carnet y pase QR, debes subir tu foto tipo carnet y guardar cambios.</p>
                    </div>
                <?php else: ?>
                    <div class="p-3 mb-3 bg-light rounded border">
                        <h6 class="font-weight-bold text-uppercase small" style="color: #521f29;">Pase de Comensal y Asistencia</h6>
                        <img src="<?= $qr_url ?>" alt="QR Asistencia" class="img-fluid my-2" style="max-width: 170px;">
                        <div class="small font-weight-bold" style="color: #521f29;">C.I. V-<?= htmlspecialchars($u['cedula'] ?? '') ?></div>
                    </div>

                    <a href="imprimir_carnet.php?id_usuario=<?= $u['id_usuario'] ?>" target="_blank" class="btn btn-block font-weight-bold text-white mb-2 shadow-sm" style="background-color: #521f29;">
                        <i class="fas fa-print mr-1"></i> Ver e Imprimir Carnet PVC
                    </a>
                    <a href="<?= $qr_url ?>" download="QR_<?= $u['cedula'] ?>.png" class="btn btn-block font-weight-bold text-white shadow-sm" style="background-color: #ff8a00;">
                        <i class="fas fa-download mr-1"></i> Descargar Código QR
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>