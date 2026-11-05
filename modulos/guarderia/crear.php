<?php
// /sistema_nora/modulos/guarderia/crear.php
require_once '../../config/db.php';
require_once '../../includes/header.php';

// Control de acceso: exclusivo de Guardería / Administración
if (!tiene_permiso(['guarderia', 'admin', 'superadmin'], $roles_array)) {
    echo "<div class='alert alert-danger font-weight-bold'>Acceso restringido a Centro de Cuidado Infantil.</div>";
    require_once '../../includes/footer.php';
    exit();
}

$mensaje = '';
$tipo_alerta = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cedula_rep       = trim($_POST['cedula_rep'] ?? '');
    $nombre_nino      = trim($_POST['nombre_nino'] ?? '');
    $apellido_nino    = trim($_POST['apellido_nino'] ?? '');
    $fecha_nacimiento = trim($_POST['fecha_nacimiento'] ?? '');
    $sexo             = trim($_POST['sexo'] ?? '');
    $observaciones    = trim($_POST['observaciones'] ?? '');

    if (!empty($cedula_rep) && !empty($nombre_nino) && !empty($apellido_nino) && !empty($fecha_nacimiento)) {
        
        // 1. Buscar o crear el ID de usuario del representante
        $stmt_u = $pdo->prepare("SELECT id_usuario FROM usuarios WHERE cedula = :cedula LIMIT 1");
        $stmt_u->execute([':cedula' => $cedula_rep]);
        $user_rep = $stmt_u->fetch();

        if ($user_rep) {
            $id_representante = $user_rep['id_usuario'];
        } else {
            // Si el representante no existe en usuarios, lo registramos como trabajador/estudiante
            $stmt_ins_u = $pdo->prepare("INSERT INTO usuarios (cedula, nombre, apellido, tipo_usuario, estatus_cuenta) VALUES (:cedula, 'Representante', 'NORA', 'estudiante', 'Activo') RETURNING id_usuario");
            $stmt_ins_u->execute([':cedula' => $cedula_rep]);
            $id_representante = $stmt_ins_u->fetchColumn();
        }

        // 2. Registrar al niño en guarderia_ninos
        $sql = "INSERT INTO guarderia_ninos (id_representante, nombre_nino, apellido_nino, fecha_nacimiento, sexo, observaciones, fecha_registro) 
                VALUES (:id_rep, :nombre, :apellido, :fecha_nac, :sexo, :obs, CURRENT_TIMESTAMP)";
        
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':id_rep'    => $id_representante,
                ':nombre'    => $nombre_nino,
                ':apellido'  => $apellido_nino,
                ':fecha_nac' => $fecha_nacimiento,
                ':sexo'      => $sexo,
                ':obs'       => $observaciones
            ]);

            echo "<script>alert('¡Niño(a) inscrito exitosamente en el Centro de Cuidado Infantil!'); window.location.href='index.php';</script>";
            exit();
        } catch (Exception $e) {
            $mensaje = "Error al inscribir: " . $e->getMessage();
            $tipo_alerta = "danger";
        }
    } else {
        $mensaje = "Por favor complete los campos requeridos (*).";
        $tipo_alerta = "warning";
    }
}
?>

<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <h1 class="h3 mb-0 font-weight-bold" style="color: #521f29;">
        <i class="fas fa-child mr-2"></i>Inscripción - Centro de Cuidado Infantil
    </h1>
    <a href="index.php" class="btn btn-sm btn-secondary font-weight-bold shadow-sm">
        <i class="fas fa-arrow-left mr-1"></i> Volver al Censo
    </a>
</div>

<div class="row justify-content-center">
    <div class="col-lg-9">
        <div class="card shadow mb-4" style="border-radius: 15px; border: none;">
            <div class="card-header py-3" style="background-color: #521f29; border-top-left-radius: 15px; border-top-right-radius: 15px;">
                <h6 class="m-0 font-weight-bold text-white">Ficha de Ingreso Infantil</h6>
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
                    
                    <h6 class="font-weight-bold mb-3" style="color: #ff8a00;"><i class="fas fa-user-shield mr-1"></i> Datos del Representante</h6>
                    <div class="form-row mb-4">
                        <div class="form-group col-md-6">
                            <label class="font-weight-bold" style="color: #521f29;">Cédula del Representante *</label>
                            <input type="text" name="cedula_rep" id="cedula_rep" class="form-control" placeholder="Ej: 12345678" required autofocus>
                        </div>
                    </div>

                    <hr>
                    <h6 class="font-weight-bold mb-3" style="color: #ff8a00;"><i class="fas fa-baby mr-1"></i> Datos del Niño o Niña</h6>
                    
                    <div class="form-row">
                        <div class="form-group col-md-6 mb-3">
                            <label class="font-weight-bold" style="color: #521f29;">Nombres del Niño(a) *</label>
                            <input type="text" name="nombre_nino" class="form-control" placeholder="Nombres" required>
                        </div>
                        <div class="form-group col-md-6 mb-3">
                            <label class="font-weight-bold" style="color: #521f29;">Apellidos del Niño(a) *</label>
                            <input type="text" name="apellido_nino" class="form-control" placeholder="Apellidos" required>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group col-md-6 mb-3">
                            <label class="font-weight-bold" style="color: #521f29;">Fecha de Nacimiento *</label>
                            <input type="date" name="fecha_nacimiento" class="form-control" required>
                        </div>
                        <div class="form-group col-md-6 mb-3">
                            <label class="font-weight-bold" style="color: #521f29;">Sexo *</label>
                            <select name="sexo" class="form-control" required>
                                <option value="">-- Seleccionar --</option>
                                <option value="Femenino">Femenino</option>
                                <option value="Masculino">Masculino</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group mb-4">
                        <label class="font-weight-bold" style="color: #521f29;">Observaciones / Alergias / Condición Médica</label>
                        <textarea name="observaciones" class="form-control" rows="3" placeholder="Alergias, medicamentos, dieta o detalles especiales de cuidado..."></textarea>
                    </div>

                    <button type="submit" class="btn btn-block font-weight-bold py-2 shadow-sm" style="background-color: #ff8a00; color: #521f29; border: none;">
                        <i class="fas fa-save mr-2"></i> Completar Inscripción
                    </button>
                </form>

            </div>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>