<?php
// /sistema_nora/modulos/defensoria/crear.php
require_once '../../config/db.php';
require_once '../../includes/header.php';

// Control de acceso: exclusivo de Defensoría / Administración
if (!tiene_permiso(['defensoria', 'admin', 'superadmin'], $roles_array)) {
    echo "<div class='alert alert-danger font-weight-bold'>Acceso restringido a Defensoría.</div>";
    require_once '../../includes/footer.php';
    exit();
}

$mensaje = '';
$tipo_alerta = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $codigo_expediente          = trim($_POST['codigo_expediente'] ?? '');
    $cedula_victimaria_afectada = trim($_POST['cedula_victimaria_afectada'] ?? '');
    $nombre_victima             = trim($_POST['nombre_victima'] ?? '');
    $telefono_victima           = trim($_POST['telefono_victima'] ?? '');
    $direccion_victima          = trim($_POST['direccion_victima'] ?? '');
    $tipo_violencia             = trim($_POST['tipo_violencia'] ?? '');
    $descripcion_hechos         = trim($_POST['descripcion_hechos'] ?? '');
    $id_defensora               = $_SESSION['id_usuario'] ?? null;

    if (!empty($codigo_expediente) && !empty($cedula_victimaria_afectada) && !empty($nombre_victima) && !empty($tipo_violencia)) {
        
        $sql = "INSERT INTO defensoria_casos (codigo_expediente, cedula_victimaria_afectada, nombre_victima, telefono_victima, direccion_victima, tipo_violencia, descripcion_hechos, estatus_caso, id_defensora, fecha_registro) 
                VALUES (:codigo_expediente, :cedula, :nombre, :telefono, :direccion, :tipo_violencia, :descripcion, 'En Proceso', :id_defensora, CURRENT_TIMESTAMP)";
        
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':codigo_expediente' => $codigo_expediente,
                ':cedula'            => $cedula_victimaria_afectada,
                ':nombre'            => $nombre_victima,
                ':telefono'          => $telefono_victima,
                ':direccion'         => $direccion_victima,
                ':tipo_violencia'    => $tipo_violencia,
                ':descripcion'      => $descripcion_hechos,
                ':id_defensora'      => $id_defensora
            ]);

            echo "<script>alert('¡Expediente de Defensoría registrado con éxito!'); window.location.href='index.php';</script>";
            exit();
        } catch (Exception $e) {
            $mensaje = "Error al guardar el expediente: " . $e->getMessage();
            $tipo_alerta = "danger";
        }
    } else {
        $mensaje = "Por favor complete todos los campos obligatorios (*).";
        $tipo_alerta = "warning";
    }
}

// Generar código de expediente autogenerado
$correlativo = 'EXP-' . date('Y') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
?>

<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <h1 class="h3 mb-0 font-weight-bold" style="color: #521f29;">
        <i class="fas fa-folder-plus mr-2"></i>Apertura de Expediente de Defensoría
    </h1>
    <a href="index.php" class="btn btn-sm btn-secondary font-weight-bold shadow-sm">
        <i class="fas fa-arrow-left mr-1"></i> Volver a la Lista
    </a>
</div>

<div class="row justify-content-center">
    <div class="col-lg-10">
        <div class="card shadow mb-4" style="border-radius: 15px; border: none;">
            <div class="card-header py-3" style="background-color: #521f29; border-top-left-radius: 15px; border-top-right-radius: 15px;">
                <h6 class="m-0 font-weight-bold text-white">Ficha Oficial de Caso y Hechos</h6>
            </div>
            <div class="card-body p-4 bg-white" style="border-bottom-left-radius: 15px; border-bottom-right-radius: 15px;">

                <?php if (!empty($mensaje)): ?>
                    <div class="alert alert-<?= $tipo_alerta ?> alert-dismissible fade show small" role="alert">
                        <?= $mensaje ?>
                        <button type="button" class="close" data-dismiss="alert">&times;</button>
                    </div>
                <?php endif; ?>

                <form method="POST" action="crear.php">
                <?= csrf_field() ?>
                    <div class="form-row">
                        <div class="form-group col-md-4 mb-3">
                            <label class="font-weight-bold" style="color: #521f29;">Código de Expediente *</label>
                            <input type="text" name="codigo_expediente" class="form-control font-weight-bold" value="<?= $correlativo ?>" required readonly style="background-color: #f8f9fa;">
                        </div>
                        <div class="form-group col-md-4 mb-3">
                            <label class="font-weight-bold" style="color: #521f29;">Cédula Afectada / Víctima *</label>
                            <div class="input-group">
                                <input type="text" name="cedula_victimaria_afectada" id="cedula_victimaria_afectada" class="form-control" placeholder="Ej: 12345678" required autofocus autocomplete="off">
                                <div class="input-group-append">
                                    <button class="btn btn-outline-secondary" type="button" id="btnBuscarPadron">
                                        <i class="fas fa-search" id="iconBuscar"></i>
                                    </button>
                                </div>
                            </div>
                            <small id="msgPadron" class="form-text font-weight-bold"></small>
                        </div>
                        <div class="form-group col-md-4 mb-3">
                            <label class="font-weight-bold" style="color: #521f29;">Nombre de la Víctima *</label>
                            <input type="text" name="nombre_victima" id="nombre_victima" class="form-control" placeholder="Nombres y Apellidos" required>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group col-md-4 mb-3">
                            <label class="font-weight-bold" style="color: #521f29;">Teléfono *</label>
                            <input type="text" name="telefono_victima" class="form-control" placeholder="0412-0000000" required>
                        </div>
                        <div class="form-group col-md-4 mb-3">
                            <label class="font-weight-bold" style="color: #521f29;">Tipo de Violencia / Atención *</label>
                            <select name="tipo_violencia" class="form-control" required>
                                <option value="">-- Seleccionar --</option>
                                <option value="Violencia Física">Violencia Física</option>
                                <option value="Violencia Psicológica">Violencia Psicológica</option>
                                <option value="Violencia Económica/Patrimonial">Violencia Económica/Patrimonial</option>
                                <option value="Violencia Institucional">Violencia Institucional</option>
                                <option value="Asesoría / Orientación Legal">Asesoría / Orientación Legal</option>
                                <option value="Acompañamiento">Acompañamiento</option>
                            </select>
                        </div>
                        <div class="form-group col-md-4 mb-3">
                            <label class="font-weight-bold" style="color: #521f29;">Dirección de Habitación</label>
                            <input type="text" name="direccion_victima" class="form-control" placeholder="Municipio / Parroquia / Sector">
                        </div>
                    </div>

                    <div class="form-group mb-4">
                        <label class="font-weight-bold" style="color: #521f29;">Descripción Breve de los Hechos *</label>
                        <textarea name="descripcion_hechos" class="form-control" rows="4" placeholder="Relato de la situación denunciada o motivo de la consulta..." required></textarea>
                    </div>

                    <button type="submit" class="btn btn-block font-weight-bold py-2 shadow-sm" style="background-color: #ff8a00; color: #521f29; border: none;">
                        <i class="fas fa-folder-plus mr-2"></i> Registrar y Abrir Expediente
                    </button>
                </form>

            </div>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>

<!-- Script AJAX corregido -->
<script>
$(document).ready(function() {
    function buscarEnPadron() {
        var cedula = $('#cedula_victimaria_afectada').val().trim();
        if (cedula.length >= 6) {
            $('#iconBuscar').removeClass('fa-search').addClass('fa-spinner fa-spin');
            $('#msgPadron').text('Buscando en padrón...').css('color', '#ff8a00');

            $.ajax({
                url: 'buscar_padron.php',
                type: 'GET',
                data: { cedula: cedula },
                dataType: 'json',
                success: function(response) {
                    $('#iconBuscar').removeClass('fa-spinner fa-spin').addClass('fa-search');
                    if (response.exito && response.nombre_completo !== '') {
                        $('#nombre_victima').val(response.nombre_completo);
                        $('#msgPadron').text('✓ Encontrado').css('color', '#28a745');
                    } else {
                        $('#msgPadron').text('No registrado (Ingresar manualmente)').css('color', '#dc3545');
                    }
                },
                error: function() {
                    $('#iconBuscar').removeClass('fa-spinner fa-spin').addClass('fa-search');
                    $('#msgPadron').text('Error en la consulta').css('color', '#dc3545');
                }
            });
        }
    }

    $('#btnBuscarPadron').on('click', buscarEnPadron);
    $('#cedula_victimaria_afectada').on('blur', function() {
        if ($('#nombre_victima').val() === '') {
            buscarEnPadron();
        }
    });
});
</script>