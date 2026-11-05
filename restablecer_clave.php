<?php
// /sistema_nora/restablecer_clave.php
require_once 'config/db.php';

$token = $_GET['token'] ?? '';
$error = '';
$exito = '';

if (empty($token)) {
    die("Token no válido o expirado.");
}

// Verificar token (solo cuentas activas) — consulta defensiva para no exponer errores SQL
try {
    $stmt = $pdo->prepare("SELECT id_usuario FROM public.usuarios WHERE reset_token = :t AND reset_expira > NOW() AND estatus_cuenta = 'Activo' LIMIT 1");
    $stmt->execute([':t' => $token]);
    $user = $stmt->fetch();
} catch (PDOException $e) {
    error_log('[restablecer_clave] Error al verificar token: ' . $e->getMessage());
    http_response_code(500);
    die("No fue posible validar el enlace de recuperación. Intente de nuevo más tarde.");
}

if (!$user) {
    die("El token de recuperación no existe, ha expirado o la cuenta no está activa.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nueva_p = $_POST['password'] ?? '';
    $conf_p  = $_POST['confirmar_password'] ?? '';
    
    if ($nueva_p !== $conf_p) {
        $error = "Las contraseñas ingresadas no coinciden.";
    } elseif (strlen($nueva_p) < 8 || !preg_match('/[\W_]/', $nueva_p)) {
        $error = "La contraseña debe tener mínimo 8 caracteres y contener al menos un carácter especial (@, #, $, %, etc.).";
    } else {
        $hash = password_hash($nueva_p, PASSWORD_BCRYPT);
        
        try {
            $stmt_up = $pdo->prepare("UPDATE public.usuarios SET password = :p, reset_token = NULL, reset_expira = NULL WHERE id_usuario = :id");
            $stmt_up->execute([':p' => $hash, ':id' => $user['id_usuario']]);
        } catch (PDOException $e) {
            error_log('[restablecer_clave] Error al actualizar clave: ' . $e->getMessage());
            http_response_code(500);
            $error = "No fue posible guardar la nueva contraseña. Intente de nuevo más tarde.";
        }

        if (empty($error)) {
            echo "<script>alert('¡Su contraseña ha sido restablecida con éxito!'); window.location.href='index.php';</script>";
            exit();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Restablecer Contraseña | Escuela Nora Castañeda</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
</head>
<body style="background-color: #f4f6f9;" class="d-flex align-items-center min-vh-100 py-5">

<div class="container" style="max-width: 500px;">
    <div class="card shadow border-0" style="border-radius: 15px;">
        <div class="card-header text-white text-center py-3" style="background-color: #521f29;">
            <h5 class="m-0 font-weight-bold">Ingrese Nueva Contraseña</h5>
        </div>
        <div class="card-body p-4 bg-white">

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger font-weight-bold small"><?= $error ?></div>
            <?php endif; ?>

            <form method="POST">
            <?= csrf_field() ?>
                <div class="form-group mb-3">
                    <label class="font-weight-bold">Nueva Contraseña * (Mínimo 8 caracteres y 1 especial)</label>
                    <input type="password" name="password" class="form-control form-control-lg" placeholder="••••••••" required>
                </div>
                <div class="form-group mb-4">
                    <label class="font-weight-bold">Confirmar Nueva Contraseña *</label>
                    <input type="password" name="confirmar_password" class="form-control form-control-lg" placeholder="••••••••" required>
                </div>

                <button type="submit" class="btn btn-block font-weight-bold text-white py-3 shadow" style="background-color: #521f29; border-radius: 10px;">
                    GUARDAR NUEVA CONTRASEÑA
                </button>
            </form>

        </div>
    </div>
</div>

</body>
</html>