<?php
// /sistema_nora/modulos/configuracion/autoridades.php
require_once '../../config/db.php';
require_once '../../includes/header.php';

// Control de acceso: únicamente administración
if (!tiene_permiso(['admin', 'superadmin'], $roles_array)) {
    echo "<div class='alert alert-danger font-weight-bold'>Acceso restringido a la Administración del Sistema.</div>";
    require_once '../../includes/footer.php';
    exit();
}

$mensaje = '';

/**
 * Sube una firma digital de forma segura:
 * extensión permitida, MIME real verificado, imagen válida, nombre aleatorio
 * y permisos restrictivos (previene la subida de scripts maliciosos).
 */
function subir_firma_segura(array $archivo, string $prefijo): string
{
    if ($archivo['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('No se recibió el archivo de la firma. Verifique el tamaño (máx. 2 MB).');
    }
    if ($archivo['size'] > 2 * 1024 * 1024) {
        throw new Exception('La imagen de la firma no debe superar 2 MB.');
    }

    $ext = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
        throw new Exception('Formato de firma no permitido. Use JPG, PNG o WEBP.');
    }

    // Verificar el tipo MIME real del contenido (no la extensión)
    $mime_real = function_exists('finfo_file')
        ? (new finfo(FILEINFO_MIME_TYPE))->file($archivo['tmp_name'])
        : null;
    $mimes_permitidos = ['image/jpeg' => ['jpg', 'jpeg'], 'image/png' => ['png'], 'image/webp' => ['webp']];
    if ($mime_real === null || !isset($mimes_permitidos[$mime_real]) || !in_array($ext, $mimes_permitidos[$mime_real], true)) {
        throw new Exception('El contenido del archivo no corresponde a una imagen válida.');
    }
    if (@getimagesize($archivo['tmp_name']) === false) {
        throw new Exception('El archivo subido no es una imagen legible.');
    }

    $dir_dest = "../../assets/img/firmas/";
    if (!is_dir($dir_dest)) { mkdir($dir_dest, 0755, true); }

    $nombre_seguro = "firma_" . $prefijo . "_" . bin2hex(random_bytes(8)) . "." . $ext;
    $ruta_relativa = "assets/img/firmas/" . $nombre_seguro;

    if (!move_uploaded_file($archivo['tmp_name'], "../../" . $ruta_relativa)) {
        throw new Exception('No fue posible guardar la imagen de la firma en el servidor.');
    }
    return $ruta_relativa;
}

// A. REGISTRAR NUEVA AUTORIDAD (Designación)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['registrar_nueva_autoridad'])) {
    try {
        $cargo      = trim($_POST['cargo']);
        $nombre     = trim($_POST['nombre_apellido']);
        $cedula     = trim($_POST['cedula']);
        $tipo_nom   = $_POST['tipo_nombramiento'];
        $num_nom    = trim($_POST['numero_nombramiento']);
        $fecha_g    = !empty($_POST['fecha_gaceta_nombramiento']) ? $_POST['fecha_gaceta_nombramiento'] : date('Y-m-d');

        // Procesar Firma Digital (validada)
        $rutafirma = 'assets/img/firmas/default_firma.png';
        if (!empty($_FILES['firma_image']['name'])) {
            $rutafirma = subir_firma_segura($_FILES['firma_image'], time());
        }

        $pdo->beginTransaction();

        // 1. Desactivar autoridad anterior de ese mismo cargo
        $stmt_off = $pdo->prepare("UPDATE public.configuracion_autoridades SET estatus = 'Inactivo', fecha_fin_gestion = CURRENT_DATE WHERE cargo = :cargo AND estatus = 'Activo'");
        $stmt_off->execute([':cargo' => $cargo]);

        // 2. Insertar nueva autoridad activa
        $stmt_ins = $pdo->prepare("INSERT INTO public.configuracion_autoridades (cargo, nombre_apellido, cedula, tipo_nombramiento, numero_nombramiento, fecha_gaceta_nombramiento, firma_digital, estatus, fecha_inicio_gestion) VALUES (:cargo, :nom, :ced, :tipo, :num, :fec, :firma, 'Activo', CURRENT_DATE)");
        $stmt_ins->execute([
            ':cargo' => $cargo,
            ':nom'   => $nombre,
            ':ced'   => $cedula,
            ':tipo'  => $tipo_nom,
            ':num'   => $num_nom,
            ':fec'   => $fecha_g,
            ':firma' => $rutafirma
        ]);

        $pdo->commit();
        $mensaje = "<div class='alert alert-success font-weight-bold shadow-sm'><i class='fas fa-check-circle mr-2'></i>¡Nueva autoridad registrada y activada correctamente!</div>";
    } catch (Exception $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $mensaje = "<div class='alert alert-danger font-weight-bold shadow-sm'>Error: " . $e->getMessage() . "</div>";
    }
}

// B. MODIFICAR/CORREGIR ERRORES DE AUTORIDAD EXISTENTE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['editar_autoridad'])) {
    try {
        $id_aut     = intval($_POST['id_autoridad']);
        $nombre     = trim($_POST['nombre_apellido']);
        $cedula     = trim($_POST['cedula']);
        $tipo_nom   = $_POST['tipo_nombramiento'];
        $num_nom    = trim($_POST['numero_nombramiento']);
        $fecha_g    = !empty($_POST['fecha_gaceta_nombramiento']) ? $_POST['fecha_gaceta_nombramiento'] : null;

        $rutafirma = null;
        if (!empty($_FILES['firma_image']['name'])) {
            $rutafirma = subir_firma_segura($_FILES['firma_image'], 'edit' . $id_aut);
        }

        $sql = "UPDATE public.configuracion_autoridades 
                SET nombre_apellido = :nom, 
                    cedula = :ced, 
                    tipo_nombramiento = :tipo, 
                    numero_nombramiento = :num, 
                    fecha_gaceta_nombramiento = :fec" .
                    ($rutafirma ? ", firma_digital = :firma" : "") . 
                " WHERE id_autoridad = :id";
        
        $params = [
            ':nom'  => $nombre,
            ':ced'  => $cedula,
            ':tipo' => $tipo_nom,
            ':num'  => $num_nom,
            ':fec'  => $fecha_g,
            ':id'   => $id_aut
        ];
        if ($rutafirma) { $params[':firma'] = $rutafirma; }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $mensaje = "<div class='alert alert-success font-weight-bold shadow-sm'><i class='fas fa-edit mr-2'></i>¡Datos corregidos exitosamente!</div>";
    } catch (Exception $e) {
        $mensaje = "<div class='alert alert-danger font-weight-bold shadow-sm'>Error: " . $e->getMessage() . "</div>";
    }
}

// Consultar autoridades activas e inactivas
$autoridades_activas   = $pdo->query("SELECT * FROM public.configuracion_autoridades WHERE estatus = 'Activo' ORDER BY id_autoridad ASC")->fetchAll();
$autoridades_historial = $pdo->query("SELECT * FROM public.configuracion_autoridades WHERE estatus = 'Inactivo' ORDER BY id_autoridad DESC")->fetchAll();
?>

<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <h1 class="h3 mb-0 font-weight-bold" style="color: #521f29;">
        <i class="fas fa-user-tie mr-2 text-warning"></i>Gestión e Historial de Autoridades
    </h1>
    <button class="btn font-weight-bold text-white shadow-sm" style="background-color: #521f29;" data-toggle="modal" data-target="#modalNuevaAutoridad">
        <i class="fas fa-plus-circle mr-1 text-warning"></i> Registrar Nueva Autoridad
    </button>
</div>

<?= $mensaje ?>

<!-- SECCIÓN 1: AUTORIDADES ACTIVAS -->
<div class="row">
    <div class="col-12 mb-2">
        <h5 class="font-weight-bold text-uppercase" style="color: #521f29;"><i class="fas fa-check-circle mr-2 text-success"></i>Autoridades Vigentes en Cargo</h5>
    </div>
    <?php foreach ($autoridades_activas as $aut): ?>
        <div class="col-lg-6 mb-4">
            <div class="card shadow" style="border-radius: 12px; border: none; border-left: 6px solid #ff8a00 !important;">
                <div class="card-header py-3 bg-white d-flex justify-content-between align-items-center">
                    <h6 class="m-0 font-weight-bold text-uppercase" style="color: #521f29;">
                        <i class="fas fa-pen-nib mr-2 text-warning"></i> <?= htmlspecialchars($aut['cargo']) ?>
                    </h6>
                    <span class="badge badge-success px-2 py-1">ACTIVO</span>
                </div>
                <div class="card-body bg-white p-4">
                    <form method="POST" action="autoridades.php" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                        <input type="hidden" name="id_autoridad" value="<?= $aut['id_autoridad'] ?>">
                        
                        <div class="form-row">
                            <div class="col-md-7 mb-2">
                                <label class="font-weight-bold small" style="color: #521f29;">Nombre y Apellido</label>
                                <input type="text" name="nombre_apellido" class="form-control font-weight-bold" value="<?= htmlspecialchars($aut['nombre_apellido']) ?>" required>
                            </div>
                            <div class="col-md-5 mb-2">
                                <label class="font-weight-bold small" style="color: #521f29;">Cédula</label>
                                <input type="text" name="cedula" class="form-control font-weight-bold" value="<?= htmlspecialchars($aut['cedula']) ?>" required>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="col-md-6 mb-2">
                                <label class="font-weight-bold small" style="color: #521f29;">Tipo Nombramiento</label>
                                <select name="tipo_nombramiento" class="form-control font-weight-bold" required>
                                    <option value="Gaceta Oficial" <?= $aut['tipo_nombramiento'] === 'Gaceta Oficial' ? 'selected' : '' ?>>Gaceta Oficial</option>
                                    <option value="Providencia Administrativa" <?= $aut['tipo_nombramiento'] === 'Providencia Administrativa' ? 'selected' : '' ?>>Providencia Administrativa</option>
                                    <option value="Punto de Cuenta" <?= $aut['tipo_nombramiento'] === 'Punto de Cuenta' ? 'selected' : '' ?>>Punto de Cuenta</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-2">
                                <label class="font-weight-bold small" style="color: #521f29;">N° Documento</label>
                                <input type="text" name="numero_nombramiento" class="form-control font-weight-bold" value="<?= htmlspecialchars($aut['numero_nombramiento']) ?>" required>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="col-md-12 mb-2">
                                <label class="font-weight-bold small" style="color: #521f29;">Fecha Nombramiento</label>
                                <input type="date" name="fecha_gaceta_nombramiento" class="form-control font-weight-bold" value="<?= htmlspecialchars($aut['fecha_gaceta_nombramiento'] ?? '') ?>">
                            </div>
                        </div>

                        <div class="form-row align-items-center mt-2">
                            <div class="col-md-7 mb-2">
                                <label class="font-weight-bold small" style="color: #521f29;">Cambiar Firma Digital</label>
                                <input type="file" name="firma_image" class="form-control-file">
                            </div>
                            <div class="col-md-5 text-center">
                                <?php if (!empty($aut['firma_digital']) && file_exists("../../" . $aut['firma_digital'])): ?>
                                    <img src="../../<?= $aut['firma_digital'] ?>" style="max-height: 45px; border: 1px dashed #ccc; padding: 2px;">
                                <?php endif; ?>
                            </div>
                        </div>

                        <button type="submit" name="editar_autoridad" class="btn btn-block font-weight-bold text-white shadow-sm mt-3" style="background-color: #ff8a00;">
                            <i class="fas fa-save mr-1"></i> CORREGIR / ACTUALIZAR DATOS
                        </button>
                    </form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- SECCIÓN 2: HISTORIAL DE AUTORIDADES -->
<div class="row mt-4">
    <div class="col-12">
        <div class="card shadow border-0" style="border-radius: 12px;">
            <div class="card-header py-3 bg-white">
                <h6 class="m-0 font-weight-bold text-uppercase" style="color: #521f29;">
                    <i class="fas fa-history mr-2 text-warning"></i>Histórico de Autoridades Anteriores
                </h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-striped table-hover mb-0">
                        <thead style="background-color: #521f29; color: #ffffff;">
                            <tr>
                                <th>Cargo</th>
                                <th>Nombre y Apellido</th>
                                <th>Cédula</th>
                                <th>Nombramiento</th>
                                <th>Inicio Gestión</th>
                                <th>Fin Gestión</th>
                                <th>Acción</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($autoridades_historial)): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-3 text-muted font-weight-bold">No hay autoridades registradas en el historial anterior.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($autoridades_historial as $h): ?>
                                    <tr>
                                        <td class="font-weight-bold"><?= htmlspecialchars($h['cargo']) ?></td>
                                        <td><?= htmlspecialchars($h['nombre_apellido']) ?></td>
                                        <td><?= htmlspecialchars($h['cedula']) ?></td>
                                        <td><?= htmlspecialchars($h['tipo_nombramiento'] . ' N° ' . $h['numero_nombramiento']) ?></td>
                                        <td><?= htmlspecialchars($h['fecha_inicio_gestion'] ?? 'N/A') ?></td>
                                        <td><?= htmlspecialchars($h['fecha_fin_gestion'] ?? 'N/A') ?></td>
                                        <td>
                                            <button class="btn btn-sm btn-warning text-dark font-weight-bold" data-toggle="modal" data-target="#modalEditarHistorial<?= $h['id_autoridad'] ?>">
                                                <i class="fas fa-edit"></i> Editar
                                            </button>
                                        </td>
                                    </tr>

                                    <!-- Modal de Corrección para Historial -->
                                    <div class="modal fade" id="modalEditarHistorial<?= $h['id_autoridad'] ?>" tabindex="-1" role="dialog">
                                        <div class="modal-dialog" role="document">
                                            <div class="modal-content">
                                                <div class="modal-header text-white" style="background-color: #521f29;">
                                                    <h5 class="modal-title font-weight-bold">Corregir Registro Histórico</h5>
                                                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                                                </div>
                                                <form method="POST" action="autoridades.php" enctype="multipart/form-data">
                                                <?= csrf_field() ?>
                                                    <div class="modal-body">
                                                        <input type="hidden" name="id_autoridad" value="<?= $h['id_autoridad'] ?>">
                                                        <div class="form-group">
                                                            <label class="font-weight-bold">Cargo</label>
                                                            <input type="text" class="form-control" value="<?= htmlspecialchars($h['cargo']) ?>" disabled>
                                                        </div>
                                                        <div class="form-group">
                                                            <label class="font-weight-bold">Nombre y Apellido</label>
                                                            <input type="text" name="nombre_apellido" class="form-control" value="<?= htmlspecialchars($h['nombre_apellido']) ?>" required>
                                                        </div>
                                                        <div class="form-group">
                                                            <label class="font-weight-bold">Cédula</label>
                                                            <input type="text" name="cedula" class="form-control" value="<?= htmlspecialchars($h['cedula']) ?>" required>
                                                        </div>
                                                        <div class="form-group">
                                                            <label class="font-weight-bold">Tipo Nombramiento</label>
                                                            <select name="tipo_nombramiento" class="form-control" required>
                                                                <option value="Gaceta Oficial" <?= $h['tipo_nombramiento'] === 'Gaceta Oficial' ? 'selected' : '' ?>>Gaceta Oficial</option>
                                                                <option value="Providencia Administrativa" <?= $h['tipo_nombramiento'] === 'Providencia Administrativa' ? 'selected' : '' ?>>Providencia Administrativa</option>
                                                                <option value="Punto de Cuenta" <?= $h['tipo_nombramiento'] === 'Punto de Cuenta' ? 'selected' : '' ?>>Punto de Cuenta</option>
                                                            </select>
                                                        </div>
                                                        <div class="form-group">
                                                            <label class="font-weight-bold">N° Nombramiento</label>
                                                            <input type="text" name="numero_nombramiento" class="form-control" value="<?= htmlspecialchars($h['numero_nombramiento']) ?>" required>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
                                                        <button type="submit" name="editar_autoridad" class="btn text-white font-weight-bold" style="background-color: #ff8a00;">Guardar Cambios</button>
                                                    </div>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- MODAL REGISTRAR NUEVA AUTORIDAD -->
<div class="modal fade" id="modalNuevaAutoridad" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header text-white" style="background-color: #521f29;">
                <h5 class="modal-title font-weight-bold"><i class="fas fa-user-plus mr-2 text-warning"></i>Registrar Nueva Autoridad</h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <form method="POST" action="autoridades.php" enctype="multipart/form-data">
            <?= csrf_field() ?>
                <div class="modal-body">
                    <div class="alert alert-info small font-weight-bold">
                        <i class="fas fa-info-circle mr-1"></i> Al registrar una nueva autoridad, la autoridad activa actual de ese cargo pasará automáticamente al historial como inactiva.
                    </div>
                    <div class="form-group">
                        <label class="font-weight-bold" style="color: #521f29;">Cargo A Asignar *</label>
                        <select name="cargo" class="form-control font-weight-bold" required>
                            <option value="Directora de la Escuela">Directora de la Escuela</option>
                            <option value="Director de Control de Estudio">Director de Control de Estudio</option>
                        </select>
                    </div>
                    <div class="form-row">
                        <div class="col-md-7 form-group">
                            <label class="font-weight-bold" style="color: #521f29;">Nombre y Apellido *</label>
                            <input type="text" name="nombre_apellido" class="form-control" placeholder="Ej: Dra. María Pérez" required>
                        </div>
                        <div class="col-md-5 form-group">
                            <label class="font-weight-bold" style="color: #521f29;">Cédula *</label>
                            <input type="text" name="cedula" class="form-control" placeholder="Ej: 12345678" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="col-md-6 form-group">
                            <label class="font-weight-bold" style="color: #521f29;">Tipo de Nombramiento *</label>
                            <select name="tipo_nombramiento" class="form-control" required>
                                <option value="Gaceta Oficial">Gaceta Oficial</option>
                                <option value="Providencia Administrativa">Providencia Administrativa</option>
                                <option value="Punto de Cuenta">Punto de Cuenta</option>
                            </select>
                        </div>
                        <div class="col-md-6 form-group">
                            <label class="font-weight-bold" style="color: #521f29;">N° de Documento / Gaceta *</label>
                            <input type="text" name="numero_nombramiento" class="form-control" placeholder="Ej: PA-2026-003" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="col-md-6 form-group">
                            <label class="font-weight-bold" style="color: #521f29;">Fecha del Nombramiento</label>
                            <input type="date" name="fecha_gaceta_nombramiento" class="form-control" value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-md-6 form-group">
                            <label class="font-weight-bold" style="color: #521f29;">Firma Digitalizada (PNG/JPG)</label>
                            <input type="file" name="firma_image" class="form-control-file">
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary font-weight-bold" data-dismiss="modal">Cancelar</button>
                    <button type="submit" name="registrar_nueva_autoridad" class="btn font-weight-bold text-white" style="background-color: #ff8a00;">
                        <i class="fas fa-check-circle mr-1"></i> REGISTRAR Y ACTIVAR
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>