<?php
// /sistema_nora/modulos/usuarios/cambiar_clave.php
require_once '../../config/db.php';
require_once '../../includes/header.php';

// Control de acceso: gestión de claves solo para administración
if (!tiene_permiso(['superadmin', 'admin'], $roles_array)) {
    echo "<div class='alert alert-danger font-weight-bold shadow-sm'>Acceso restringido a la Administración del Sistema.</div>";
    require_once '../../includes/footer.php';
    exit();
}

// El id llega por GET al abrir la página y por POST (campo oculto) al enviar.
$id_usuario = ($_SERVER['REQUEST_METHOD'] === 'POST')
    ? intval($_POST['id_usuario'] ?? 0)
    : intval($_GET['id'] ?? $_GET['id_usuario'] ?? 0);

$mensaje = '';
$error   = '';

// Los roles pueden venir como arreglo PHP o como arreglo textual de PostgreSQL.
function cc_normalizar_roles($roles_asignados)
{
    if (is_array($roles_asignados)) {
        $roles = $roles_asignados;
    } else {
        $roles = explode(',', trim((string)$roles_asignados, '{}'));
    }

    $salida = [];
    foreach ($roles as $rol) {
        $rol = trim(trim((string)$rol), "\"'");
        if ($rol !== '') {
            $salida[] = $rol;
        }
    }
    return $salida;
}

try {
    $stmt = $pdo->prepare("SELECT id_usuario, cedula, nombre, apellido, password,
                                  roles_asignados, tipo_usuario, estatus_cuenta
                           FROM public.usuarios WHERE id_usuario = :id LIMIT 1");
    $stmt->execute([':id' => $id_usuario]);
    $u = $stmt->fetch();
} catch (Throwable $e) {
    error_log('[cambiar_clave] Error al cargar usuario: ' . $e->getMessage());
    $u = false;
    $error = 'No fue posible cargar la información del usuario. Intente de nuevo más tarde.';
}

if (!$u) {
    echo "<div class='alert alert-danger font-weight-bold shadow-sm'>"
       . ($error !== '' ? htmlspecialchars($error) : 'Usuario no localizado.')
       . "</div>";
    echo "<a href='index.php' class='btn font-weight-bold text-white shadow-sm' style='background-color: #521f29;'>"
       . "<i class='fas fa-arrow-left mr-1'></i> Volver al Listado</a>";
    require_once '../../includes/footer.php';
    exit();
}

// Un administrador no puede alterar la clave de una cuenta Superadministrador.
$roles_destino = cc_normalizar_roles($u['roles_asignados']);
$destino_es_superadmin = in_array('superadmin', $roles_destino, true)
    || ($u['tipo_usuario'] ?? '') === 'superadmin';
$actor_es_superadmin = in_array('superadmin', $roles_array, true);

if ($destino_es_superadmin && !$actor_es_superadmin) {
    echo "<div class='alert alert-danger font-weight-bold shadow-sm'>"
       . "Acceso denegado: solo el Superusuario puede cambiar la contraseña de una cuenta Superadministrador."
       . "</div>";
    echo "<a href='index.php' class='btn font-weight-bold text-white shadow-sm' style='background-color: #521f29;'>"
       . "<i class='fas fa-arrow-left mr-1'></i> Volver al Listado</a>";
    require_once '../../includes/footer.php';
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cambiar_clave_usuario'])) {
    $nueva     = (string)($_POST['nueva_password'] ?? '');
    $confirmar = (string)($_POST['confirmar_password'] ?? '');

    if ($nueva !== $confirmar) {
        $error = 'Las contraseñas ingresadas no coinciden.';
    } elseif (strlen($nueva) < 8 || !preg_match('/[\W_]/', $nueva)) {
        $error = 'La contraseña debe tener mínimo 8 caracteres y contener al menos un carácter especial (@, #, $, %, etc.).';
    } else {
        $cedula_sin_v = preg_replace('/^V-/i', '', trim((string)$u['cedula']));
        $hash_actual  = (string)($u['password'] ?? '');

        if ($nueva === $cedula_sin_v) {
            $error = 'La contraseña no puede ser igual a la cédula del usuario.';
        } elseif ($nueva === 'Nora123456+-') {
            $error = 'La contraseña indicada no está permitida por política de seguridad.';
        } elseif ($hash_actual !== '' && password_verify($nueva, $hash_actual)) {
            $error = 'La nueva contraseña no puede ser igual a la contraseña actual.';
        }
    }

    if ($error === '') {
        try {
            $hash = password_hash($nueva, PASSWORD_BCRYPT);
            $stmt_up = $pdo->prepare("UPDATE public.usuarios
                                      SET password = :p, reset_token = NULL, reset_expira = NULL
                                      WHERE id_usuario = :id");
            $stmt_up->execute([':p' => $hash, ':id' => (int)$u['id_usuario']]);

            // Auditoría de la misma operación. NUNCA se guarda la clave ni su hash.
            try {
                $cedula_bit = preg_replace('/[\r\n]+/', ' ', trim((string)$u['cedula']));
                $accion_bit = 'CAMBIO_CLAVE_USUARIO id_destino=' . (int)$u['id_usuario']
                            . ' cedula=' . $cedula_bit . ' resultado=OK';
                $pdo->prepare("INSERT INTO public.bitacora (id_usuario, modulo, accion, ip_maquina)
                               VALUES (:u, 'SEGURIDAD', :a, :ip)")
                    ->execute([
                        ':u'  => $_SESSION['id_usuario'] ?? null,
                        ':a'  => $accion_bit,
                        ':ip' => $_SERVER['REMOTE_ADDR'] ?? 'local-cli',
                    ]);
            } catch (Throwable $e) {
                error_log('[cambiar_clave] Error al registrar auditoría: ' . $e->getMessage());
            }

            $mensaje = 'La contraseña del usuario fue actualizada correctamente.';
        } catch (Throwable $e) {
            error_log('[cambiar_clave] Error al actualizar clave: ' . $e->getMessage());
            $error = 'No fue posible actualizar la contraseña. Intente de nuevo más tarde.';
        }
    }

    unset($nueva, $confirmar);
}

$nombre_completo = trim((string)($u['nombre'] ?? '') . ' ' . (string)($u['apellido'] ?? ''));
?>

<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <h1 class="h3 mb-0 font-weight-bold" style="color: #521f29;">
        <i class="fas fa-key mr-2 text-warning"></i>Cambiar Contraseña de Usuario
    </h1>
    <a href="index.php" class="btn font-weight-bold text-white shadow-sm" style="background-color: #521f29;">
        <i class="fas fa-arrow-left mr-1"></i> Volver al Listado
    </a>
</div>

<div class="row justify-content-center">
    <div class="col-md-8">
        <div class="card shadow border-0" style="border-radius: 15px; overflow: hidden;">
            <div class="card-header text-white font-weight-bold py-3" style="background-color: #521f29;">
                <i class="fas fa-user-lock mr-2" style="color: #ff8a00;"></i> Asignación de Nueva Contraseña
            </div>
            <div class="card-body bg-white p-4">

                <div class="alert alert-light border font-weight-bold mb-4" style="color: #521f29;">
                    <i class="fas fa-user mr-2"></i>V-<?= htmlspecialchars($u['cedula']) ?>
                    <?php if ($nombre_completo !== ''): ?>
                        — <?= htmlspecialchars($nombre_completo) ?>
                    <?php endif; ?>
                    <span class="badge badge-secondary ml-2"><?= htmlspecialchars($u['estatus_cuenta'] ?? '') ?></span>
                </div>

                <?php if (!empty($mensaje)): ?>
                    <div class="alert alert-success font-weight-bold shadow-sm">
                        <i class="fas fa-check-circle mr-2"></i><?= htmlspecialchars($mensaje) ?>
                    </div>
                <?php endif; ?>
                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger font-weight-bold shadow-sm">
                        <i class="fas fa-exclamation-triangle mr-2"></i><?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="cambiar_clave.php" onsubmit="return confirm('¿Confirma que desea cambiar la contraseña de este usuario?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="cambiar_clave_usuario" value="1">
                    <input type="hidden" name="id_usuario" value="<?= (int)$u['id_usuario'] ?>">

                    <div class="form-group mb-3">
                        <label class="font-weight-bold small" style="color: #521f29;">Nueva Contraseña * (mínimo 8 caracteres y 1 carácter especial)</label>
                        <input type="password" name="nueva_password" class="form-control form-control-lg font-weight-bold" placeholder="••••••••" autocomplete="new-password" required>
                    </div>
                    <div class="form-group mb-4">
                        <label class="font-weight-bold small" style="color: #521f29;">Confirmar Nueva Contraseña *</label>
                        <input type="password" name="confirmar_password" class="form-control form-control-lg font-weight-bold" placeholder="••••••••" autocomplete="new-password" required>
                    </div>

                    <button type="submit" class="btn btn-block font-weight-bold text-white py-3 shadow" style="background-color: #ff8a00; border-radius: 10px;">
                        <i class="fas fa-key mr-2"></i> ACTUALIZAR CONTRASEÑA
                    </button>
                </form>

            </div>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>
