<?php
// /sistema_nora/registro.php
require_once 'config/db.php';

$error = '';

$etnias_list = [];
$discapacidades_list = [];
try {
    $etnias_list = $pdo->query("SELECT id, nombre FROM public.etnia WHERE activo = 1 ORDER BY nombre ASC")->fetchAll();
    $discapacidades_list = $pdo->query("SELECT id, nombre FROM public.discapacidad WHERE activo = 1 ORDER BY nombre ASC")->fetchAll();
} catch (Exception $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $cedula = preg_replace('/[^0-9]/', '', trim($_POST['cedula']));
        $cedula_validada = $_POST['cedula_validada'] ?? '0';

        if ($cedula === '' || $cedula_validada !== '1') {
            throw new Exception("ERROR DE SEGURIDAD: Su cédula no ha sido autorizada previamente por la Administración del Sistema.");
        }

        // ═══ VALIDACIÓN REAL EN SERVIDOR ═══
        // La autorización debe existir en BD y la cuenta debe estar en estado 'Pendiente'
        // (pre-autorizada por Control de Estudio / Superusuario). El campo oculto del
        // formulario NUNCA se toma como válido por sí solo.
        $stmt_val = $pdo->prepare("SELECT id_usuario, estatus_cuenta, nombre, apellido, fecha_nacimiento, sexo, edad, estado_civil FROM public.usuarios WHERE cedula = :c LIMIT 1");
        $stmt_val->execute([':c' => $cedula]);
        $cuenta = $stmt_val->fetch();

        if (!$cuenta) {
            throw new Exception("Su Cédula no está autorizada por la Administración del Sistema. Contacte a Control de Estudio.");
        }
        if ($cuenta['estatus_cuenta'] !== 'Pendiente') {
            throw new Exception("Esta cuenta ya fue registrada o está inactiva. Si olvidó su contraseña, utilice la opción «Recuperar Contraseña».");
        }

        $correo        = trim($_POST['correo']);
        $telefono      = trim($_POST['telefono']);
        $etnia         = $_POST['etnia'] ?? 'Ninguna';
        $discapacidad  = $_POST['tipo_discapacidad'] ?? 'Ninguna';
        $estado        = trim($_POST['estado']);
        $municipio     = trim($_POST['municipio']);
        $parroquia     = trim($_POST['parroquia']);
        $dir_completa  = trim($_POST['direccion_completa']);
        $inscrito_cne  = $_POST['inscrito_cne'] ?? 'No';
        $cne_estado    = trim($_POST['cne_estado'] ?? '');
        $cne_municipio = trim($_POST['cne_municipio'] ?? '');
        $cne_parroquia = trim($_POST['cne_parroquia'] ?? '');
        $cne_centro    = trim($_POST['cne_centro_electoral'] ?? '');
        $tiene_hijos   = $_POST['tiene_hijos'] ?? 'No';
        $cantidad_hijos= intval($_POST['cantidad_hijos'] ?? 0);
        $password      =$_POST['password'] ?? '';
        $confirm_pass  =$_POST['confirmar_password'] ?? '';

        if ($password !==$confirm_pass) {
            throw new Exception("Las contraseñas ingresadas no coinciden.");
        }

        if (strlen($password) < 8 || !preg_match('/[\W_]/',$password)) {
            throw new Exception("La contraseña debe tener mínimo 8 caracteres y al menos un carácter especial.");
        }

        // Si los datos básicos están pendientes, rescatarlos del Padrón Nacional en el servidor
        if (($cuenta['nombre'] ?? '') === 'POR COMPLETAR' || empty($cuenta['fecha_nacimiento'])) {
            try {
                if (!empty($pdo_padron)) {
                    $stmt_pad = $pdo_padron->prepare("SELECT first_name, second_name, first_surname, second_surname, birth_date, genero, estado_civil FROM public.datos WHERE document = :c LIMIT 1");
                    $stmt_pad->execute([':c' => $cedula]);
                    $pad = $stmt_pad->fetch();
                    if ($pad) {
                        $nombres = trim(($pad['first_name'] ?? '') . ' ' . ($pad['second_name'] ?? ''));
                        $apellidos = trim(($pad['first_surname'] ?? '') . ' ' . ($pad['second_surname'] ?? ''));
                        $f_nac = null; $edad_p = null; $sexo_p = null; $ec_p = null;
                        if (!empty($pad['birth_date'])) {
                            $f_obj = new DateTime($pad['birth_date']);
                            $f_nac = $f_obj->format('Y-m-d');
                            $edad_p = (new DateTime())->diff($f_obj)->y;
                        }
                        $gen_map = ['F' => 'Femenino', 'M' => 'Masculino'];
                        $sexo_p = $gen_map[strtoupper($pad['genero'] ?? '')] ?? null;
                        $ec_map = ['1' => 'Soltero(a)', '2' => 'Casado(a)', '3' => 'Divorciado(a)', '4' => 'Viudo(a)', '5' => 'Concubinato'];
                        $ec_p = $ec_map[strval($pad['estado_civil'] ?? '')] ?? null;

                        $stmt_updatos = $pdo->prepare("UPDATE public.usuarios SET nombre = :n, apellido = :a, fecha_nacimiento = COALESCE(fecha_nacimiento, :fn), sexo = COALESCE(sexo, :sx), edad = COALESCE(edad, :ed), estado_civil = COALESCE(estado_civil, :ec) WHERE id_usuario = :id");
                        $stmt_updatos->execute([':n' => $nombres, ':a' => $apellidos, ':fn' => $f_nac, ':sx' => $sexo_p, ':ed' => $edad_p, ':ec' => $ec_p, ':id' => $cuenta['id_usuario']]);
                    }
                }
            } catch (Exception $e) { /* se completa el registro con los datos ingresados */ }
        }

        // CREAR DIRECTORIO SI NO EXISTE PARA EVITAR "Failed to open stream"
        $dir_perfiles = __DIR__ . '/public/uploads/perfiles/';
        if (!is_dir($dir_perfiles)) {
            mkdir($dir_perfiles, 0777, true);
        }

        $foto_nombre = null;
        if (!empty($_POST['foto_camara_base64'])) {
            $data =$_POST['foto_camara_base64'];
            if (preg_match('/^data:image\/(\w+);base64,/', $data)) {
                $data = substr($data, strpos($data, ',') + 1);$data = base64_decode($data);$foto_nombre = 'foto_webcam_' . time() . '_' . rand(100,999) . '.jpg';
                file_put_contents($dir_perfiles . $foto_nombre,$data);
            }
        } else if (isset($_FILES['foto_file']) &&$_FILES['foto_file']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['foto_file']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                $foto_nombre = 'foto_file_' . time() . '_' . rand(100,999) . '.' . $ext;
                move_uploaded_file($_FILES['foto_file']['tmp_name'], $dir_perfiles .$foto_nombre);
            }
        }

        $clave_hash = password_hash($password, PASSWORD_BCRYPT);

        // UPDATE asignando el correo a las columnas "email" y "correo" de PostgreSQL
        $sql_up = "UPDATE public.usuarios SET 
            email = :cor, correo = :cor2, telefono = :tel, etnia = :et, tipo_discapacidad = :disc, 
            estado = :est, municipio = :mun, parroquia = :parr, direccion_completa = :dir, 
            inscrito_cne = :cne, cne_estado = :cne_est, cne_municipio = :cne_mun, cne_parroquia = :cne_parr, 
            cne_centro_electoral = :cne_cent, tiene_hijos = :hijos, cantidad_hijos = :cant_h, 
            password = :p, estatus_cuenta = 'Activo'" . 
            ($foto_nombre ? ", foto_perfil = :foto" : "") . 
            " WHERE cedula = :c AND estatus_cuenta = 'Pendiente'";

        $params = [
            ':cor' => $correo, ':cor2' => $correo, ':tel' =>$telefono, ':et' => $etnia, ':disc' =>$discapacidad,
            ':est' => $estado, ':mun' =>$municipio, ':parr' => $parroquia, ':dir' =>$dir_completa,
            ':cne' => $inscrito_cne, ':cne_est' => $cne_estado, ':cne_mun' =>$cne_municipio,
            ':cne_parr' => $cne_parroquia, ':cne_cent' => $cne_centro, ':hijos' =>$tiene_hijos,
            ':cant_h' => $cantidad_hijos, ':p' => $clave_hash, ':c' =>$cedula
        ];
        if ($foto_nombre) $params[':foto'] =$foto_nombre;

        $stmt = $pdo->prepare($sql_up);
        $stmt->execute($params);

        // Confirmar que la cuenta quedó realmente activada
        if ($stmt->rowCount() === 0) {
            throw new Exception("No fue posible completar el registro. Contacte a Control de Estudio.");
        }

        echo "<script>alert('¡Registro completado exitosamente! Ya puede iniciar sesión con sus credenciales.'); window.location.href='index.php';</script>";
        exit();
    } catch (Exception $e) {
        $error =$e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Registro de Usuarios | Escuela Nora Castañeda</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
</head>
<body style="background-color: #d6c4af;" class="py-4">

<div class="container" style="max-width: 900px;">
    <div class="card shadow border-0" style="border-radius: 15px;">
        <div class="card-header text-white text-center py-3" style="background-color: #521f29; border-top-left-radius: 15px; border-top-right-radius: 15px;">
            <h4 class="m-0 font-weight-bold"><i class="fas fa-user-plus mr-2"></i>Completar Registro de Usuario Autorizado</h4>
        </div>
        <div class="card-body p-4 bg-white">

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger font-weight-bold shadow-sm"><i class="fas fa-exclamation-triangle mr-2"></i><?= $error ?></div>
            <?php endif; ?>

            <form method="POST" action="registro.php" enctype="multipart/form-data">
            <?= csrf_field() ?>
                <input type="hidden" name="cedula_validada" id="cedulaValidada" value="0">
                
                <div class="form-row bg-light p-3 rounded border mb-4">
                    <div class="col-md-6 mb-2">
                        <label class="font-weight-bold text-dark">Cédula de Identidad * (Llave Autorizada)</label>
                        <div class="input-group">
                            <input type="text" name="cedula" id="cedulaInput" class="form-control font-weight-bold" placeholder="Ej: 12345678" required>
                            <div class="input-group-append">
                                <button type="button" class="btn btn-warning font-weight-bold" onclick="consultarPadron()"><i class="fas fa-search mr-1"></i> Verificar Cédula</button>
                            </div>
                        </div>
                        <small id="statusPadron" class="form-text font-weight-bold text-danger mt-1">Ingrese su cédula autorizada para desbloquear el formulario.</small>
                    </div>
                    <div class="col-md-6 mb-2">
                        <label class="font-weight-bold text-dark">Correo Electrónico * (Para Recuperación)</label>
                        <input type="email" name="correo" id="correoInput" class="form-control campo-bloqueable" disabled required>
                    </div>
                </div>

                <fieldset id="fieldSetForm" disabled>

                    <h6 class="font-weight-bold text-uppercase border-bottom pb-2" style="color: #521f29;"><i class="fas fa-camera mr-2"></i>Fotografía de Perfil</h6>
                    <div class="form-row align-items-center mb-4">
                        <div class="col-md-4 text-center">
                            <img id="imgPreview" src="assets/img/imagen_perfil_generico.jpg" class="rounded-circle shadow border" style="width: 120px; height: 120px; object-fit: cover; border: 3px solid #ff8a00 !important;">
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
                                <i class="fas fa-video mr-1"></i> Tomar Foto con Cámara Web
                            </button>
                        </div>
                    </div>

                    <div class="modal fade" id="modalCamara" tabindex="-1" role="dialog" aria-hidden="true">
                        <div class="modal-dialog modal-dialog-centered" role="document">
                            <div class="modal-content">
                                <div class="modal-header bg-dark text-white">
                                    <h5 class="modal-title font-weight-bold">Capturar Foto de Perfil</h5>
                                    <button type="button" class="close text-white" onclick="cerrarCamara()">&times;</button>
                                </div>
                                <div class="modal-body text-center">
                                    <video id="webcam" autoplay playsinline style="width: 100%; max-height: 280px; border-radius: 8px;"></video>
                                    <canvas id="canvasCam" style="display:none;"></canvas>
                                </div>
                                <div class="modal-footer justify-content-between">
                                    <button type="button" class="btn btn-secondary font-weight-bold" onclick="cerrarCamara()">Cancelar</button>
                                    <button type="button" class="btn btn-danger font-weight-bold" onclick="tomarCaptura()"><i class="fas fa-camera mr-1"></i> Capturar</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <h6 class="font-weight-bold text-uppercase border-bottom pb-2" style="color: #521f29;"><i class="fas fa-id-card mr-2"></i>Datos Personales Base</h6>
                    <div class="form-row">
                        <div class="col-md-6 mb-3">
                            <label class="font-weight-bold small">Nombre *</label>
                            <input type="text" name="nombre" id="nombreInput" class="form-control font-weight-bold" readonly required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="font-weight-bold small">Apellido *</label>
                            <input type="text" name="apellido" id="apellidoInput" class="form-control font-weight-bold" readonly required>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="col-md-3 mb-3">
                            <label class="font-weight-bold small">Fecha Nacimiento</label>
                            <input type="date" name="fecha_nacimiento" id="fnacInput" class="form-control" readonly>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="font-weight-bold small">Sexo</label>
                            <input type="text" name="sexo" id="sexoInput" class="form-control" readonly>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="font-weight-bold small">Edad Calculada</label>
                            <input type="number" name="edad" id="edadInput" class="form-control text-center font-weight-bold" readonly>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="font-weight-bold small">Estado Civil</label>
                            <input type="text" name="estado_civil" id="ecivilInput" class="form-control" readonly>
                        </div>
                    </div>

                    <h6 class="font-weight-bold text-uppercase border-bottom pb-2 mt-2" style="color: #521f29;"><i class="fas fa-user-tag mr-2"></i>Cargo y Función Asignada</h6>
                    <div class="form-group mb-3">
                        <label class="font-weight-bold small">Cargo Institucional Asignado</label>
                        <input type="text" name="cargo_funcion" id="cargoInput" class="form-control font-weight-bold text-danger" readonly>
                    </div>

                    <h6 class="font-weight-bold text-uppercase border-bottom pb-2 mt-2" style="color: #521f29;"><i class="fas fa-map-marker-alt mr-2"></i>Ubicación y Contacto</h6>
                    <div class="form-row">
                        <div class="col-md-4 mb-3">
                            <label class="font-weight-bold small">Teléfono *</label>
                            <input type="text" name="telefono" id="telefonoInput" class="form-control" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="font-weight-bold small">Etnia</label>
                            <select name="etnia" id="etniaInput" class="form-control font-weight-bold">
                                <option value="Ninguna">Ninguna / No aplica</option>
                                <?php foreach ($etnias_list as$e): ?>
                                    <option value="<?= htmlspecialchars($e['nombre']) ?>"><?= htmlspecialchars($e['nombre']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="font-weight-bold small">Tipo de Discapacidad</label>
                            <select name="tipo_discapacidad" id="discInput" class="form-control font-weight-bold">
                                <option value="Ninguna">Ninguna</option>
                                <?php foreach ($discapacidades_list as$d): ?>
                                    <option value="<?= htmlspecialchars($d['nombre']) ?>"><?= htmlspecialchars($d['nombre']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="col-md-4 mb-3">
                            <label class="font-weight-bold small">Estado *</label>
                            <input type="text" name="estado" id="estadoInput" class="form-control" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="font-weight-bold small">Municipio *</label>
                            <input type="text" name="municipio" id="municipioInput" class="form-control" required>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="font-weight-bold small">Parroquia *</label>
                            <input type="text" name="parroquia" id="parroquiaInput" class="form-control" required>
                        </div>
                    </div>
                    <div class="form-group mb-3">
                        <label class="font-weight-bold small">Dirección Completa *</label>
                        <textarea name="direccion_completa" id="dirInput" class="form-control" rows="2" required></textarea>
                    </div>

                    <h6 class="font-weight-bold text-uppercase border-bottom pb-2 mt-2" style="color: #521f29;"><i class="fas fa-vote-yea mr-2"></i>Información Electoral y Familiar</h6>
                    <div class="form-row">
                        <div class="col-md-3 mb-3">
                            <label class="font-weight-bold small">¿Inscrito en CNE?</label>
                            <select name="inscrito_cne" id="cneSelect" class="form-control font-weight-bold" onchange="toggleCneFields(this.value)">
                                <option value="No">No</option>
                                <option value="Sí">Sí</option>
                            </select>
                        </div>
                        <div class="col-md-3 mb-3 cne-field" style="display:none;">
                            <label class="font-weight-bold small">CNE Estado</label>
                            <input type="text" name="cne_estado" id="cneEstadoInput" class="form-control">
                        </div>
                        <div class="col-md-3 mb-3 cne-field" style="display:none;">
                            <label class="font-weight-bold small">CNE Municipio</label>
                            <input type="text" name="cne_municipio" id="cneMunInput" class="form-control">
                        </div>
                        <div class="col-md-3 mb-3 cne-field" style="display:none;">
                            <label class="font-weight-bold small">CNE Parroquia</label>
                            <input type="text" name="cne_parroquia" id="cneParrInput" class="form-control">
                        </div>
                    </div>
                    <div class="form-group mb-3 cne-field" style="display:none;">
                        <label class="font-weight-bold small">Centro Electoral (CNE)</label>
                        <input type="text" name="cne_centro_electoral" id="cneCentroInput" class="form-control">
                    </div>

                    <div class="form-row">
                        <div class="col-md-6 mb-3">
                            <label class="font-weight-bold small">¿Tiene Hijos?</label>
                            <select name="tiene_hijos" id="hijosSelect" class="form-control font-weight-bold" onchange="toggleHijosFields(this.value)">
                                <option value="No">No</option>
                                <option value="Sí">Sí</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3 hijos-field" style="display:none;">
                            <label class="font-weight-bold small">Cantidad de Hijos</label>
                            <input type="number" name="cantidad_hijos" id="cantHijosInput" class="form-control text-center font-weight-bold" value="0" min="0">
                        </div>
                    </div>

                    <h6 class="font-weight-bold text-uppercase border-bottom pb-2 mt-2" style="color: #521f29;"><i class="fas fa-lock mr-2"></i>Establecer Contraseña de Acceso</h6>
                    <div class="form-row mb-4">
                        <div class="col-md-6 mb-3">
                            <label class="font-weight-bold small">Contraseña Personal *</label>
                            <div class="input-group">
                                <input type="password" name="password" id="password" class="form-control" placeholder="••••••••" required>
                                <div class="input-group-append">
                                    <button class="btn btn-outline-secondary" type="button" onclick="togglePass('password')">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>
                            <small class="text-muted">Mínimo 8 caracteres y 1 carácter especial.</small>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="font-weight-bold small">Confirmar Contraseña *</label>
                            <div class="input-group">
                                <input type="password" name="confirmar_password" id="confirmar_password" class="form-control" placeholder="••••••••" required>
                                <div class="input-group-append">
                                    <button class="btn btn-outline-secondary" type="button" onclick="togglePass('confirmar_password')">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>
                            <div id="passMatchMsg" class="mt-1 font-weight-bold small"></div>
                        </div>
                    </div>

                    <button type="submit" id="btnSubmit" class="btn btn-block font-weight-bold text-white py-3 shadow" style="background-color: #521f29; border-radius: 10px;">
                        COMPLETAR REGISTRO Y ACTIVAR CUENTA
                    </button>
                </fieldset>
            </form>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function consultarPadron() {
    const ced = $('#cedulaInput').val().trim();
    if (!ced) { alert('Ingrese su Número de Cédula de Identidad.'); return; }

    $('#statusPadron').html('<span class="text-info"><i class="fas fa-spinner fa-spin"></i> Verificando Autorización de Cédula...</span>');

    fetch(`verificar_llave.php?cedula=${ced}`)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const d = data.datos;
                $('#nombreInput').val(d.nombre);
                $('#apellidoInput').val(d.apellido);
                $('#fnacInput').val(d.fecha_nacimiento);
                $('#sexoInput').val(d.sexo);
                $('#edadInput').val(d.edad);
                $('#ecivilInput').val(d.estado_civil);
                $('#cargoInput').val(d.cargo_funcion);

                if (d.email && !d.email.includes('pendiente_')) $('#correoInput').val(d.email);
                if (d.telefono) $('#telefonoInput').val(d.telefono);
                if (d.etnia) $('#etniaInput').val(d.etnia);
                if (d.tipo_discapacidad) $('#discInput').val(d.tipo_discapacidad);
                $('#estadoInput').val(d.estado);
                $('#municipioInput').val(d.municipio);
                $('#parroquiaInput').val(d.parroquia);
                $('#dirInput').val(d.direccion_completa);

                $('#cedulaValidada').val('1');
                $('#fieldSetForm').prop('disabled', false);
                $('.campo-bloqueable').prop('disabled', false);$('#cedulaInput').prop('readonly', true);

                $('#statusPadron').html('<span class="text-success"><i class="fas fa-check-circle"></i> Cédula Pre-autorizada correctamente. Complete sus datos.</span>');
            } else {
                $('#cedulaValidada').val('0');
                $('#fieldSetForm').prop('disabled', true);
                $('.campo-bloqueable').prop('disabled', true);$('#statusPadron').html('<span class="text-danger font-weight-bold"><i class="fas fa-times-circle"></i> ' + data.message + '</span>');
            }
        })
        .catch(() => {
            $('#statusPadron').html('<span class="text-danger">Error de conexión al verificar cédula.</span>');
        });
}

function toggleCneFields(val) {
    if (val === 'Sí') { $('.cne-field').show(); } else {$('.cne-field').hide(); }
}

function toggleHijosFields(val) {
    if (val === 'Sí') { $('.hijos-field').show(); } else {$('.hijos-field').hide(); }
}

function togglePass(fieldId) {
    const input = document.getElementById(fieldId);
    if (input.type === "password") { input.type = "text"; } else { input.type = "password"; }
}

function previewFile(input) {
    if (input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function(e) {
            $('#imgPreview').attr('src', e.target.result);
            $('#fotoBase64').val('');
        }
        reader.readAsDataURL(input.files[0]);
    }
}

let videoStream = null;

function abrirCamara() {
    $('#modalCamara').modal('show');
    navigator.mediaDevices.getUserMedia({ video: true })
        .then(stream => {
            videoStream = stream;
            document.getElementById('webcam').srcObject = stream;
        })
        .catch(err => { alert('No se pudo acceder a la cámara web.'); });
}

function tomarCaptura() {
    const video = document.getElementById('webcam');
    const canvas = document.getElementById('canvasCam');
    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    canvas.getContext('2d').drawImage(video, 0, 0);
    const dataData = canvas.toDataURL('image/jpeg');
    $('#imgPreview').attr('src', dataData);
    $('#fotoBase64').val(dataData);
    cerrarCamara();
}

function cerrarCamara() {
    if (videoStream) {
        videoStream.getTracks().forEach(track => track.stop());
        videoStream = null;
    }
    const video = document.getElementById('webcam');
    if (video) { video.srcObject = null; }
    $('#modalCamara').modal('hide');
}

$(document).ready(function() {$('#modalCamara').on('hidden.bs.modal', function () { cerrarCamara(); });

    $('#confirmar_password, #password').on('keyup', function () {
        const p1 = $('#password').val();
        const p2 = $('#confirmar_password').val();
        
        if (p1.length > 0 || p2.length > 0) {
            if (p1 === p2) {
                $('#passMatchMsg').html('<span class="text-success"><i class="fas fa-check-circle"></i> Las contraseñas coinciden</span>');
            } else {
                $('#passMatchMsg').html('<span class="text-danger"><i class="fas fa-times-circle"></i> Las contraseñas no coinciden</span>');
            }
        } else {
            $('#passMatchMsg').empty();
        }
    });
});
</script>
</body>
</html>