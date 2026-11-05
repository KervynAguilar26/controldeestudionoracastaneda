<?php
// /sistema_nora/modulos/usuarios/index.php
require_once '../../config/db.php';
require_once '../../includes/header.php';

// Control de acceso: gestión de usuarios solo para administración
if (!tiene_permiso(['superadmin', 'admin'], $roles_array)) {
    echo "<div class='alert alert-danger font-weight-bold shadow-sm'>Acceso restringido a la Administración del Sistema.</div>";
    require_once '../../includes/footer.php';
    exit();
}

$mensaje = '';
$tipo_alerta = '';

// Procesar Bloqueo / Desbloqueo de Usuario
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cambiar_estatus_usuario'])) {
    try {
        $id_objetivo = intval($_POST['id_usuario_cambio'] ?? 0);
        $nuevo_estatus = trim($_POST['nuevo_estatus'] ?? '');

        if ($id_objetivo === intval($_SESSION['id_usuario'] ?? 0)) {
            throw new Exception("No puedes bloquear tu propia cuenta activa.");
        }

        if (!in_array($nuevo_estatus, ['Activo', 'Inactivo'], true)) {
            throw new Exception("Estatus no válido.");
        }

        $stmt_upd = $pdo->prepare("UPDATE public.usuarios SET estatus_cuenta = :est WHERE id_usuario = :id");
        $stmt_upd->execute([':est' => $nuevo_estatus, ':id' => $id_objetivo]);

        $mensaje = "El estatus del usuario fue actualizado a <strong>{$nuevo_estatus}</strong> exitosamente.";
        $tipo_alerta = "success";
    } catch (Exception $e) {
        $mensaje = "Error: " . $e->getMessage();
        $tipo_alerta = "danger";
    }
}

$buscar       = trim($_GET['buscar'] ?? '');
$filtro_tipo  = trim($_GET['tipo'] ?? '');
$filtro_est   = trim($_GET['estatus'] ?? '');

$tipos_validos  = ['superadmin','admin','defensoria','comedor','cocina','control_estudio','profesor','estudiante','guarderia','trabajador','monitor'];
$estatus_validos = ['Pendiente','Activo','Inactivo'];

$where  = [];
$params = [];

if ($buscar !== '') {
    $where[] = "(cedula ILIKE :q OR nombre ILIKE :q OR apellido ILIKE :q OR correo ILIKE :q OR email ILIKE :q)";
    $params[':q'] = '%' . $buscar . '%';
}
if (in_array($filtro_tipo, $tipos_validos, true)) {
    $where[] = "tipo_usuario = :tipo";
    $params[':tipo'] = $filtro_tipo;
}
if (in_array($filtro_est, $estatus_validos, true)) {
    $where[] = "estatus_cuenta = :est";
    $params[':est'] = $filtro_est;
}

try {
    $stats = $pdo->query("SELECT
                            COUNT(*) AS total,
                            COUNT(*) FILTER (WHERE estatus_cuenta = 'Activo') AS activos,
                            COUNT(*) FILTER (WHERE estatus_cuenta = 'Pendiente') AS pendientes,
                            COUNT(*) FILTER (WHERE tipo_usuario = 'estudiante') AS estudiantes,
                            COUNT(*) FILTER (WHERE tipo_usuario = 'profesor') AS profesores
                          FROM public.usuarios")->fetch();

    $sql = "SELECT id_usuario, cedula, nombre, apellido, email, correo, telefono,
                   tipo_usuario, estatus_cuenta, cargo_funcion, roles_asignados, foto_perfil
            FROM public.usuarios";
    if (!empty($where)) {
        $sql .= " WHERE " . implode(' AND ', $where);
    }
    $sql .= " ORDER BY apellido ASC, nombre ASC LIMIT 300";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $usuarios = $stmt->fetchAll();
} catch (Exception $e) {
    $stats = ['total' => 0, 'activos' => 0, 'pendientes' => 0, 'estudiantes' => 0, 'profesores' => 0];
    $usuarios = [];
    echo "<div class='alert alert-danger font-weight-bold'>No fue posible cargar la lista de usuarios.</div>";
}
?>

<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <h1 class="h3 mb-0 font-weight-bold" style="color: #521f29;">
        <i class="fas fa-users-cog mr-2 text-warning"></i>Gestión de Usuarios
    </h1>
    <div class="d-flex flex-wrap">
        <a href="crear.php" class="btn btn-sm font-weight-bold text-white shadow-sm mr-1 mb-1" style="background-color: #ff8a00;">
            <i class="fas fa-user-plus mr-1"></i> Pre-autorizar Usuario
        </a>
        <a href="aprobar_estudiantes.php" class="btn btn-sm font-weight-bold text-white shadow-sm mr-1 mb-1" style="background-color: #28a745;">
            <i class="fas fa-user-check mr-1"></i> Aprobar Estudiantes
        </a>
        <a href="mi_qr.php" class="btn btn-sm font-weight-bold text-white shadow-sm mb-1" style="background-color: #521f29;">
            <i class="fas fa-qrcode mr-1 text-warning"></i> Mi Código QR
        </a>
    </div>
</div>

<?php if (!empty($mensaje)): ?>
    <div class="alert alert-<?= $tipo_alerta ?> alert-dismissible fade show font-weight-bold shadow-sm" role="alert">
        <?= $mensaje ?>
        <button type="button" class="close" data-dismiss="alert">&times;</button>
    </div>
<?php endif; ?>

<!-- INDICADORES -->
<div class="row mb-4">
    <?php
    $tarjetas = [
        ['Usuarios Totales',      $stats['total'],       'fa-users',            '#521f29', ''],
        ['Cuentas Activas',       $stats['activos'],     'fa-user-check',       '#28a745', 'success'],
        ['Cuentas Pendientes',    $stats['pendientes'],  'fa-user-clock',       '#ff8a00', 'warning'],
        ['Estudiantes',           $stats['estudiantes'], 'fa-user-graduate',    '#17a2b8', 'info'],
    ];
    foreach ($tarjetas as $t): ?>
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card shadow h-100 py-2 bg-white border-0" style="border-radius: 12px; border-left: 5px solid <?= $t[3] ?> !important;">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-uppercase mb-1" style="color: <?= $t[3] ?>;"><?= $t[0] ?></div>
                            <div class="h4 mb-0 font-weight-bold" style="color: #521f29;"><?= number_format((int)$t[1]) ?></div>
                        </div>
                        <div class="col-auto"><i class="fas <?= $t[2] ?> fa-2x text-gray-300"></i></div>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<!-- FILTROS -->
<div class="card shadow border-0 mb-4" style="border-radius: 15px;">
    <div class="card-body bg-white py-3">
        <form method="GET" action="index.php" class="form-row align-items-end">
            <div class="col-md-5 mb-2">
                <label class="font-weight-bold small" style="color: #521f29;">Buscar por cédula, nombre o correo</label>
                <input type="text" name="buscar" class="form-control font-weight-bold" value="<?= htmlspecialchars($buscar) ?>" placeholder="Ej: V-12345678 o María">
            </div>
            <div class="col-md-3 mb-2">
                <label class="font-weight-bold small" style="color: #521f29;">Tipo de usuario</label>
                <select name="tipo" class="form-control font-weight-bold">
                    <option value="">-- Todos --</option>
                    <?php foreach ($tipos_validos as $tp): ?>
                        <option value="<?= $tp ?>" <?= $filtro_tipo === $tp ? 'selected' : '' ?>><?= ucfirst(str_replace('_', ' ', $tp)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 mb-2">
                <label class="font-weight-bold small" style="color: #521f29;">Estatus</label>
                <select name="estatus" class="form-control font-weight-bold">
                    <option value="">-- Todos --</option>
                    <?php foreach ($estatus_validos as $es): ?>
                        <option value="<?= $es ?>" <?= $filtro_est === $es ? 'selected' : '' ?>><?= $es ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 mb-2">
                <button type="submit" class="btn btn-block font-weight-bold text-white" style="background-color: #521f29;">
                    <i class="fas fa-search mr-1"></i> Filtrar
                </button>
            </div>
        </form>
    </div>
</div>

<!-- LISTADO -->
<div class="card shadow border-0" style="border-radius: 15px;">
    <div class="card-header py-3 d-flex justify-content-between align-items-center" style="background-color: #521f29; border-top-left-radius: 15px; border-top-right-radius: 15px;">
        <h6 class="m-0 font-weight-bold text-white"><i class="fas fa-list mr-2"></i>Usuarios Registrados (<?= count($usuarios) ?>)</h6>
        <?php if ($buscar !== '' || $filtro_tipo !== '' || $filtro_est !== ''): ?>
            <a href="index.php" class="btn btn-sm btn-light font-weight-bold"><i class="fas fa-times mr-1"></i> Limpiar filtros</a>
        <?php endif; ?>
    </div>
    <div class="card-body p-0 bg-white" style="border-bottom-left-radius: 15px; border-bottom-right-radius: 15px;">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead style="background-color: #ff8a00; color: #521f29;">
                    <tr>
                        <th>Cédula</th>
                        <th>Nombre y Apellido</th>
                        <th>Contacto</th>
                        <th>Tipo / Roles</th>
                        <th>Estatus</th>
                        <th class="text-center">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($usuarios)): ?>
                        <tr><td colspan="6" class="text-center py-4 text-muted font-weight-bold">No se encontraron usuarios con los criterios indicados.</td></tr>
                    <?php else: ?>
                        <?php foreach ($usuarios as $u):
                            $roles = [];
                            if (!empty($u['roles_asignados'])) {
                                $roles = is_array($u['roles_asignados'])
                                    ? $u['roles_asignados']
                                    : array_filter(array_map('trim', explode(',', trim($u['roles_asignados'], '{}'))));
                            }
                            $correo_mostrar = $u['correo'] ?: $u['email'];
                        ?>
                            <tr>
                                <td class="font-weight-bold" style="color: #521f29;">V-<?= htmlspecialchars($u['cedula']) ?></td>
                                <td>
                                    <strong><?= htmlspecialchars(trim($u['nombre'] . ' ' . ($u['apellido'] ?? ''))) ?></strong>
                                    <?php if (!empty($u['cargo_funcion'])): ?>
                                        <br><small class="text-muted"><?= htmlspecialchars($u['cargo_funcion']) ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <small class="d-block"><i class="fas fa-envelope mr-1 text-muted"></i><?= htmlspecialchars($correo_mostrar ?: 'Sin correo') ?></small>
                                    <small class="d-block"><i class="fas fa-phone mr-1 text-muted"></i><?= htmlspecialchars($u['telefono'] ?: 'Sin teléfono') ?></small>
                                </td>
                                <td>
                                    <span class="badge badge-dark text-uppercase"><?= htmlspecialchars(str_replace('_', ' ', $u['tipo_usuario'])) ?></span>
                                    <?php foreach ($roles as $r): ?>
                                        <?php if (trim($r) !== ''): ?>
                                            <span class="badge badge-light border text-dark"><?= htmlspecialchars(trim($r)) ?></span>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </td>
                                <td>
                                    <?php if ($u['estatus_cuenta'] === 'Activo'): ?>
                                        <span class="badge badge-success px-2 py-1"><i class="fas fa-check-circle mr-1"></i> Activo</span>
                                    <?php elseif ($u['estatus_cuenta'] === 'Pendiente'): ?>
                                        <span class="badge badge-warning text-dark px-2 py-1"><i class="fas fa-clock mr-1"></i> Pendiente</span>
                                    <?php else: ?>
                                        <span class="badge badge-secondary px-2 py-1"><i class="fas fa-ban mr-1"></i> Inactivo</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <a href="editar.php?id=<?= $u['id_usuario'] ?>" class="btn btn-sm btn-warning text-dark font-weight-bold mr-1" title="Editar usuario">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <a href="../../imprimir_carnet.php?cedula=<?= urlencode($u['cedula']) ?>" target="_blank" class="btn btn-sm btn-outline-dark font-weight-bold mr-1" title="Imprimir carnet">
                                        <i class="fas fa-id-card"></i>
                                    </a>
                                    
                                    <!-- BOTÓN DE BLOQUEAR / ACTIVAR USUARIO -->
                                    <form method="POST" action="index.php" class="d-inline" onsubmit="return confirm('¿Confirma que desea cambiar el estatus de este usuario?')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="cambiar_estatus_usuario" value="1">
                                        <input type="hidden" name="id_usuario_cambio" value="<?= $u['id_usuario'] ?>">
                                        <?php if ($u['estatus_cuenta'] === 'Inactivo'): ?>
                                            <input type="hidden" name="nuevo_estatus" value="Activo">
                                            <button type="submit" class="btn btn-sm btn-success font-weight-bold" title="Desbloquear / Activar usuario">
                                                <i class="fas fa-unlock"></i>
                                            </button>
                                        <?php else: ?>
                                            <input type="hidden" name="nuevo_estatus" value="Inactivo">
                                            <button type="submit" class="btn btn-sm btn-danger font-weight-bold" title="Bloquear cuenta de usuario">
                                                <i class="fas fa-ban"></i>
                                            </button>
                                        <?php endif; ?>
                                    </form>
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