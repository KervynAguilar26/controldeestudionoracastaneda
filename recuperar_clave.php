<?php
// /sistema_nora/recuperar_clave.php
require_once 'config/db.php';
require_once 'config/mailer.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$mensaje = '';
$tipo = '';

// Límite anti abuso: máximo 3 solicitudes cada 10 minutos por sesión/IP
$ahora = time();
$intentos = $_SESSION['recuperar_intentos'] ?? [];
$intentos = array_filter($intentos, fn($t) => ($ahora - $t) < 600);
$_SESSION['recuperar_intentos'] = $intentos;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (count($intentos) >= 3) {
        $mensaje = "Demasiadas solicitudes. Espere unos minutos antes de intentar nuevamente.";
        $tipo = "warning";
    } else {
        $_SESSION['recuperar_intentos'][] = $ahora;
        $correo_cedula = trim($_POST['correo_cedula'] ?? '');

        // Buscar por correo o cédula (también 'email' como alias de contacto)
        $stmt = $pdo->prepare("SELECT id_usuario, correo, email, nombre, apellido, estatus_cuenta FROM public.usuarios WHERE correo = :val OR email = :val OR cedula = :val LIMIT 1");
        $stmt->execute([':val' => $correo_cedula]);
        $user = $stmt->fetch();

        // Respuesta SIEMPRE uniforme: no revela si la cuenta existe (anti enumeración)
        $mensaje = "Si existe una cuenta asociada a los datos ingresados, se ha generado un enlace de recuperación. Revise su correo electrónico o contacte a Control de Estudio si no lo recibe.";
        $tipo = "info";

        if ($user && $user['estatus_cuenta'] === 'Activo') {
            try {
                // Generar token de seguridad y tiempo de expiración (1 hora)
                $token = bin2hex(random_bytes(32));
                $expira = date('Y-m-d H:i:s', strtotime('+1 hour'));

                $stmt_up = $pdo->prepare("UPDATE public.usuarios SET reset_token = :t, reset_expira = :e WHERE id_usuario = :id");
                $stmt_up->execute([':t' => $token, ':e' => $expira, ':id' => $user['id_usuario']]);

                $link = url_base_sistema() . "/restablecer_clave.php?token=" . $token;
                $correo_principal = $user['correo'] ?? '';
                if ($correo_principal === '' || strpos($correo_principal, 'pendiente_') === 0) {
                    $correo_principal = $user['email'] ?? '';
                }
                $destinatario = $correo_principal;

                // 1) Enviar el enlace por SMTP (PHPMailer)
                $enviado = false;
                if (!empty($destinatario)) {
                    $enviado = enviarEnlaceRecuperacion(
                        $destinatario,
                        trim(($user['nombre'] ?? '') . ' ' . ($user['apellido'] ?? '')),
                        $link,
                        60
                    );
                }

                // 2) Registro en bitácora local (solo para auditoría del administrador)
                $ruta_log = __DIR__ . '/logs';
                if (!is_dir($ruta_log)) { mkdir($ruta_log, 0755, true); }
                @file_put_contents($ruta_log . '/recuperaciones.log',
                    "[" . date('Y-m-d H:i:s') . "] Solicitud: {$correo_cedula} | Destino: {$destinatario} | Envío: "
                    . ($enviado ? 'OK' : 'FALLIDO') . " | Enlace: {$link}\n",
                    FILE_APPEND | LOCK_EX);
            } catch (Exception $e) {
                // No se revela el error interno
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Recuperar Contraseña | Escuela Nora Castañeda</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
</head>
<body style="background-color: #f4f6f9;" class="d-flex align-items-center min-vh-100 py-5">

<div class="container" style="max-width: 500px;">
    <div class="card shadow border-0" style="border-radius: 15px;">
        <div class="card-header text-white text-center py-3" style="background-color: #521f29; border-top-left-radius: 15px; border-top-right-radius: 15px;">
            <h5 class="m-0 font-weight-bold"><i class="fas fa-key mr-2"></i>Recuperación de Contraseña</h5>
        </div>
        <div class="card-body p-4 bg-white">

            <?php if (!empty($mensaje)): ?>
                <div class="alert alert-<?= $tipo ?> shadow-sm small font-weight-bold"><?= $mensaje ?></div>
            <?php endif; ?>

            <form method="POST" action="recuperar_clave.php">
            <?= csrf_field() ?>
                <div class="form-group mb-4">
                    <label class="font-weight-bold text-dark">Ingrese su Cédula o Correo Electrónico *</label>
                    <input type="text" name="correo_cedula" class="form-control form-control-lg" placeholder="Ej: V12345678 o correo@ejemplo.com" required>
                </div>

                <button type="submit" class="btn btn-block font-weight-bold text-white py-3 shadow" style="background-color: #ff8a00; border-radius: 10px;">
                    ENVIAR CÓDIGO DE RECUPERACIÓN
                </button>
                
                <div class="text-center mt-3">
                    <a href="index.php" class="text-secondary font-weight-bold small"><i class="fas fa-arrow-left mr-1"></i> Volver al Inicio de Sesión</a>
                </div>
            </form>

        </div>
    </div>
</div>

</body>
</html>