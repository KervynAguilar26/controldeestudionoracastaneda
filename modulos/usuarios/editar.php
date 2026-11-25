<?php
// /sistema_nora/modulos/usuarios/editar.php
require_once '../../config/db.php';
require_once '../../includes/disponibilidad_docente.php';
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
    'Estudiante',
    'Recepcionista'
];

$stmt = $pdo->prepare("SELECT * FROM public.usuarios WHERE id_usuario = :id LIMIT 1");
$stmt->execute([':id' => $id_user]);
$u = $stmt->fetch();

if (!$u) {
    echo "<div class='alert alert-danger font-weight-bold'>Usuario no localizado.</div>";
    require_once '../../includes/footer.php';
    exit();
}

$es_profesor    = docente_es_profesor($u);
$disponibilidad = docente_disponibilidad_leer($u['disponibilidad_horaria'] ?? null);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_cambios_usuario'])) {
    try {
        $nombre        = trim($_POST['nombre']);
        $apellido      = trim($_POST['apellido']);
        $telefono      = trim($_POST['telefono']);
        $correo        = trim($_POST['correo']);
        $tipo_usuario  = $_POST['tipo_usuario'];
        $cargo_funcion = $_POST['cargo_funcion'];
        $estatus_cuenta= $_POST['estatus_cuenta'];

        // Campos docentes: rango 20 - 40 h y días/bloques disponibles
        $hmin = intval($_POST['horas_semanales_min'] ?? $u['horas_semanales_min']);
        $hmax = intval($_POST['horas_semanales_max'] ?? $u['horas_semanales_max']);
        if ($hmin < 20 || $hmax > 40 || $hmin > $hmax) {
            throw new Exception('La jornada docente debe estar entre 20 y 40 horas semanales (mínimo ≤ máximo).');
        }

        $disp_param = $u['disponibilidad_horaria'];
        if (!empty($_POST['disponibilidad_docente'])) {
            $json = docente_disponibilidad_json((array) ($_POST['disponibilidad'] ?? []));
            $disp_param = $json === '' ? null : $json;
            $disponibilidad = docente_disponibilidad_leer($json);
        }

        $sql = "UPDATE public.usuarios SET 
                nombre = :nom,
                apellido = :ape,
                telefono = :tel,
                email = :cor,
                correo = :cor,
                tipo_usuario = :tipo,
                cargo_funcion = :cargo,
                estatus_cuenta = :est,
                horas_semanales_min = :hmin,
                horas_semanales_max = :hmax,
                disponibilidad_horaria = :disp
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
            ':hmin'  => $hmin,
            ':hmax'  => $hmax,
            ':disp'  => $disp_param,
            ':id'    => $id_user
        ]);

        $u['horas_semanales_min'] = $hmin;
        $u['horas_semanales_max'] = $hmax;
        $es_profesor = ($tipo_usuario === 'profesor') || $es_profesor;
        $mensaje = "¡Cargo y datos actualizados correctamente!";
    } catch (Exception $e) {
        $error = "Error al guardar: " . $e->getMessage();
    }
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
        <form method="POST" action="editar.php?id=<?= $id_user ?>">
            <?= csrf_field() ?>
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
                        <option value="recepcion" <?= ($u['tipo_usuario'] === 'recepcion') ? 'selected' : '' ?>>Recepción</option>
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

            <?php if ($es_profesor): ?>
                <hr>
                <div class="p-3 mb-4 rounded" style="background-color:#fff8f0;border:2px dashed #ff8a00;">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h6 class="m-0 font-weight-bold" style="color:#521f29;">
                            <i class="fas fa-clock mr-2 text-warning"></i>Jornada y Disponibilidad Académica del Docente
                        </h6>
                        <div>
                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="document.querySelectorAll('#tabla-disponibilidad input[type=checkbox]').forEach(c => c.checked = true);">Marcar todo</button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="document.querySelectorAll('#tabla-disponibilidad input[type=checkbox]').forEach(c => c.checked = false);">Limpiar</button>
                        </div>
                    </div>
                    <input type="hidden" name="disponibilidad_docente" value="1">
                    <div class="form-row mb-3">
                        <div class="col-md-6 form-group">
                            <label class="font-weight-bold small" style="color:#521f29;">Horas académicas mínimas semanales *</label>
                            <input type="number" name="horas_semanales_min" min="20" max="40" step="1" class="form-control font-weight-bold" value="<?= intval($u['horas_semanales_min']) ?>" required>
                        </div>
                        <div class="col-md-6 form-group">
                            <label class="font-weight-bold small" style="color:#521f29;">Horas académicas máximas semanales *</label>
                            <input type="number" name="horas_semanales_max" min="20" max="40" step="1" class="form-control font-weight-bold" value="<?= intval($u['horas_semanales_max']) ?>" required>
                        </div>
                    </div>
                    <p class="small text-muted mb-2">
                        Marque los días y bloques en los que el docente puede impartir clases. Si no marca ninguno, el sistema lo considerará disponible en todos los bloques.
                        El cuadre de horarios impedirá cargar asignaturas fuera de esta disponibilidad.
                    </p>
                    <div class="table-responsive">
                        <table id="tabla-disponibilidad" class="table table-sm table-bordered text-center mb-0">
                            <thead style="background-color:#521f29;color:#fff;">
                                <tr>
                                    <th class="text-left">Bloque</th>
                                    <?php foreach (docente_dias() as $dia): ?><th><?= htmlspecialchars($dia) ?></th><?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (docente_bloques() as $b): ?>
                                    <tr>
                                        <td class="text-left font-weight-bold small <?= !$b['clase'] ? 'text-muted' : '' ?>">
                                            <?= htmlspecialchars($b['etiqueta']) ?>
                                            <?php if (!$b['clase']): ?><span class="badge badge-light">No impartible</span><?php endif; ?>
                                        </td>
                                        <?php foreach (docente_dias() as $dia):
                                            $marcado = isset($disponibilidad[$dia]) && in_array($b['inicio'], $disponibilidad[$dia], true);
                                        ?>
                                            <td>
                                                <input type="checkbox" class="form-check-input"
                                                       name="disponibilidad[<?= htmlspecialchars($dia) ?>][]"
                                                       value="<?= $b['inicio'] ?>"
                                                       <?= $marcado ? 'checked' : '' ?>
                                                       <?= !$b['clase'] ? 'disabled' : '' ?>>
                                            </td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <button type="submit" class="btn btn-block font-weight-bold text-white py-3 mt-3 shadow" style="background-color: #ff8a00; border-radius: 10px;">
                <i class="fas fa-save mr-1"></i> ACTUALIZAR CARGO Y PERMISOS
            </button>
        </form>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>