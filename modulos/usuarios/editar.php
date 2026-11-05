<?php
// /sistema_nora/modulos/usuarios/editar.php
require_once '../../config/db.php';
require_once '../../includes/header.php';

if (!tiene_permiso(['superadmin', 'admin'], $roles_array)) {
    echo "<div class='alert alert-danger font-weight-bold'>Acceso restringido a Administradores.</div>";
    require_once '../../includes/footer.php';
    exit();
}

$id_user = intval($_GET['id'] ?? 0);
$mensaje = '';
$error = '';

$cargos_disponibles = [
    'Servicio Generales',
    'Profesora (or)',
    'Cocinera',
    'Madre Cuidadora',
    'Defensora(or)',
    'Doctor(a)',
    'Enfermera (o)',
    'Seguridad',
    'Monitor',
    'Administración',
    'Administrativo',
    'Control de Estudio',
    'Tecnología de la Información',
    'Prensa y Comunicación',
    'Director(a)',
    'Estudiante'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_cambios_usuario'])) {
    try {
        $nombre        = trim($_POST['nombre']);
        $apellido      = trim($_POST['apellido']);
        $telefono      = trim($_POST['telefono']);
        $correo        = trim($_POST['correo']);
        $tipo_usuario  = $_POST['tipo_usuario'];
        $cargo_funcion = $_POST['cargo_funcion'];
        $estatus_cuenta= $_POST['estatus_cuenta'];

        $sql = "UPDATE public.usuarios SET 
                nombre = :nom,
                apellido = :ape,
                telefono = :tel,
                email = :cor,
                correo = :cor,
                tipo_usuario = :tipo,
                cargo_funcion = :cargo,
                estatus_cuenta = :est
                WHERE id_usuario = :id";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':nom'   => $nombre,
            ':ape'   => $apellido,
            ':tel'   => $telefono,
            ':cor'   => $correo,
            ':tipo'  => $tipo_usuario,
            ':cargo' => $cargo_funcion,
            ':est'   => $estatus_cuenta,
            ':id'    => $id_user
        ]);

        $mensaje = "¡Cargo y datos actualizados correctamente!";
    } catch (Exception $e) {
        $error = "Error al guardar: " . $e->getMessage();
    }
}

$stmt = $pdo->prepare("SELECT * FROM public.usuarios WHERE id_usuario = :id LIMIT 1");
$stmt->execute([':id' => $id_user]);
$u = $stmt->fetch();

if (!$u) {
    echo "<div class='alert alert-danger font-weight-bold'>Usuario no localizado.</div>";
    require_once '../../includes/footer.php';
    exit();
}
?>

<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <h1 class="h3 mb-0 font-weight-bold" style="color: #521f29;">
        <i class="fas fa-user-edit mr-2 text-warning"></i>Modificar Registro: V-<?= htmlspecialchars($u['cedula']) ?>
    </h1>
    <a href="index.php" class="btn font-weight-bold text-white shadow-sm" style="background-color: #521f29;">
        <i class="fas fa-arrow-left mr-1"></i> Volver al Listado
    </a>
</div>

<?php if (!empty($mensaje)): ?><div class="alert alert-success font-weight-bold shadow-sm"><i class="fas fa-check-circle mr-2"></i><?= $mensaje ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="alert alert-danger font-weight-bold shadow-sm"><i class="fas fa-exclamation-triangle mr-2"></i><?= $error ?></div><?php endif; ?>

<div class="card shadow border-0 mb-4" style="border-radius: 15px;">
    <div class="card-header text-white font-weight-bold py-3" style="background-color: #521f29; border-top-left-radius: 15px; border-top-right-radius: 15px;">
        Asignación Administrativa de Cargo y Permisos
    </div>
    <div class="card-body p-4 bg-white">
        <form method="POST" action="editar.php?id=<?= $id_user ?>
        <?= csrf_field() ?>">
            <input type="hidden" name="guardar_cambios_usuario" value="1">

            <div class="form-row">
                <div class="col-md-6 form-group">
                    <label class="font-weight-bold small" style="color: #521f29;">Nombre *</label>
                    <input type="text" name="nombre" class="form-control font-weight-bold" value="<?= htmlspecialchars($u['nombre']) ?>" required>
                </div>
                <div class="col-md-6 form-group">
                    <label class="font-weight-bold small" style="color: #521f29;">Apellido *</label>
                    <input type="text" name="apellido" class="form-control font-weight-bold" value="<?= htmlspecialchars($u['apellido'] ?? '') ?>" required>
                </div>
            </div>

            <div class="form-row">
                <div class="col-md-6 form-group">
                    <label class="font-weight-bold small" style="color: #521f29;">Correo Electrónico</label>
                    <input type="email" name="correo" class="form-control font-weight-bold" value="<?= htmlspecialchars($u['correo'] ?? $u['email'] ?? '') ?>">
                </div>
                <div class="col-md-6 form-group">
                    <label class="font-weight-bold small" style="color: #521f29;">Teléfono</label>
                    <input type="text" name="telefono" class="form-control font-weight-bold" value="<?= htmlspecialchars($u['telefono'] ?? '') ?>">
                </div>
            </div>

            <div class="form-row">
                <div class="col-md-4 form-group">
                    <label class="font-weight-bold small" style="color: #521f29;">Rol de Acceso Principal</label>
                    <select name="tipo_usuario" class="form-control font-weight-bold" required>
                        <option value="trabajador" <?= ($u['tipo_usuario'] === 'trabajador') ? 'selected' : '' ?>>Trabajador General</option>
                        <option value="monitor" <?= ($u['tipo_usuario'] === 'monitor') ? 'selected' : '' ?>>Monitor de Campo (Comedor + Infraestructura)</option>
                        <option value="control_estudio" <?= ($u['tipo_usuario'] === 'control_estudio') ? 'selected' : '' ?>>Control de Estudio</option>
                        <option value="profesor" <?= ($u['tipo_usuario'] === 'profesor') ? 'selected' : '' ?>>Profesor / Docente</option>
                        <option value="comedor" <?= ($u['tipo_usuario'] === 'comedor') ? 'selected' : '' ?>>Comedor (Cocinera)</option>
                        <option value="guarderia" <?= ($u['tipo_usuario'] === 'guarderia') ? 'selected' : '' ?>>Centro Cuidado Infantil</option>
                        <option value="defensoria" <?= ($u['tipo_usuario'] === 'defensoria') ? 'selected' : '' ?>>Defensoría</option>
                        <option value="admin" <?= ($u['tipo_usuario'] === 'admin') ? 'selected' : '' ?>>Administrador</option>
                        <option value="superadmin" <?= ($u['tipo_usuario'] === 'superadmin') ? 'selected' : '' ?>>Superadmin</option>
                    </select>
                </div>

                <!-- DESPLEGABLE DE CARGO OFICIAL DE LA INSTITUCIÓN -->
                <div class="col-md-4 form-group">
                    <label class="font-weight-bold small" style="color: #521f29;">Cargo / Función Institucional *</label>
                    <select name="cargo_funcion" class="form-control font-weight-bold text-danger" required>
                        <?php foreach ($cargos_disponibles as $c): ?>
                            <option value="<?= htmlspecialchars($c) ?>" <?= (($u['cargo_funcion'] ?? '') === $c) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-4 form-group">
                    <label class="font-weight-bold small" style="color: #521f29;">Estatus de la Cuenta</label>
                    <select name="estatus_cuenta" class="form-control font-weight-bold" required>
                        <option value="Activo" <?= ($u['estatus_cuenta'] === 'Activo') ? 'selected' : '' ?>>Activo</option>
                        <option value="Pendiente" <?= ($u['estatus_cuenta'] === 'Pendiente') ? 'selected' : '' ?>>Pendiente</option>
                        <option value="Inactivo" <?= ($u['estatus_cuenta'] === 'Inactivo') ? 'selected' : '' ?>>Inactivo</option>
                    </select>
                </div>
            </div>

            <button type="submit" class="btn btn-block font-weight-bold text-white py-3 mt-3 shadow" style="background-color: #ff8a00; border-radius: 10px;">
                <i class="fas fa-save mr-1"></i> ACTUALIZAR CARGO Y PERMISOS
            </button>
        </form>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>