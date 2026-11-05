<?php
// /sistema_nora/index.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'config/db.php';

$error = '';

// ── Control de intentos de login (anti fuerza bruta) ──
// 5 fallos en 15 minutos por IP + cédula = bloqueo temporal del acceso.
const LOGIN_MAX_FALLOS  = 5;
const LOGIN_VENTANA_MIN = 15;

function login_registrar_fallo(PDO $pdo, string $ip, string $cedula): void {
    $pdo->prepare("INSERT INTO public.login_intentos (ip, cedula, exito) VALUES (:ip, :ced, FALSE)")
        ->execute([':ip' => $ip, ':ced' => $cedula]);
    // Depuración ocasional de registros antiguos (más de 30 días)
    if (random_int(1, 20) === 1) {
        $pdo->exec("DELETE FROM public.login_intentos WHERE fecha < now() - interval '30 days'");
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cedula        = trim($_POST['cedula'] ?? '');
    $password      = trim($_POST['password'] ?? '');
    $user_captcha  = strtoupper(trim($_POST['captcha_input'] ?? ''));
    $saved_captcha = strtoupper(trim($_SESSION['captcha_code'] ?? ''));
    $ip_login      = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

    // ¿Demasiados fallos recientes para esta IP + cédula?
    $stmt_bloq = $pdo->prepare(
        "SELECT COUNT(*) AS fallos,
                COALESCE(GREATEST(0, EXTRACT(EPOCH FROM (MIN(fecha) + make_interval(mins => "
                . LOGIN_VENTANA_MIN . ") - now()))), 0) AS seg_restantes
         FROM public.login_intentos
         WHERE ip = :ip AND cedula = :ced AND exito = FALSE
           AND fecha > now() - make_interval(mins => " . LOGIN_VENTANA_MIN . ")"
    );
    $stmt_bloq->execute([':ip' => $ip_login, ':ced' => $cedula]);
    $bloqueo = $stmt_bloq->fetch();

    if ((int)$bloqueo['fallos'] >= LOGIN_MAX_FALLOS) {
        // Bloqueado: no se verifica contraseña ni CAPTCHA
        $min_restantes = max(1, (int)ceil(((float)$bloqueo['seg_restantes']) / 60));
        $error = "Demasiados intentos fallidos de acceso. Vuelva a intentarlo en {$min_restantes} minuto(s).";
    } else if (empty($saved_captcha) || $user_captcha !== $saved_captcha) {
        $error = "El código de la imagen CAPTCHA es incorrecto. Intente de nuevo.";
        // Limpiar para forzar código nuevo al recargar
        unset($_SESSION['captcha_code']);
    } else if (!empty($cedula) && !empty($password)) {
        $stmt = $pdo->prepare("SELECT * FROM public.usuarios WHERE cedula = :cedula LIMIT 1");
        $stmt->execute([':cedula' => $cedula]);
        $usuario = $stmt->fetch();

        if ($usuario && password_verify($password, $usuario['password'])) {
            if ($usuario['estatus_cuenta'] === 'Activo') {
                // Login válido: limpiar fallos previos y registrar el acierto
                $pdo->prepare("DELETE FROM public.login_intentos WHERE ip = :ip AND cedula = :ced AND exito = FALSE")
                    ->execute([':ip' => $ip_login, ':ced' => $cedula]);
                $pdo->prepare("INSERT INTO public.login_intentos (ip, cedula, exito) VALUES (:ip, :ced, TRUE)")
                    ->execute([':ip' => $ip_login, ':ced' => $cedula]);

                // Evitar fijación de sesión: nuevo ID al autenticar
                session_regenerate_id(true);

                $_SESSION['id_usuario']   = $usuario['id_usuario'];
                $_SESSION['cedula']       = $usuario['cedula'];
                $_SESSION['nombre']       = $usuario['nombre'] . ' ' . $usuario['apellido'];
                $_SESSION['tipo_usuario'] = $usuario['tipo_usuario'];

                // Cargar roles multi-rol para el permisos del header
                $roles = $usuario['roles_asignados'] ?? [];
                if (!is_array($roles) || empty($roles)) {
                    $roles = [$usuario['tipo_usuario']];
                }
                if (!in_array($usuario['tipo_usuario'], $roles)) {
                    $roles[] = $usuario['tipo_usuario'];
                }
                $_SESSION['roles_asignados'] = $roles;

                unset($_SESSION['captcha_code']); // CAPTCHA de un solo uso

                header("Location: dashboard.php");
                exit();
            } else {
                login_registrar_fallo($pdo, $ip_login, $cedula);
                $error = "Su cuenta se encuentra " . htmlspecialchars($usuario['estatus_cuenta']) . ".";
            }
        } else {
            login_registrar_fallo($pdo, $ip_login, $cedula);
            $error = "Cédula o contraseña incorrectas.";
        }
    } else {
        $error = "Por favor, complete todos los campos.";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <title>Iniciar Sesión | Escuela Nora Castañeda</title>

    <link rel="icon" type="image/png" href="assets/img/icono.png">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">

    <style>
        body {
            background: url('assets/img/fondo_pantalla.png') no-repeat center center fixed;
            background-size: cover;
            min-height: 100vh;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }

        .main-wrapper {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            padding-right: 8%;
            padding-left: 5%;
        }

        .card-login {
            border: none;
            border-radius: 20px;
            background: #ffffff;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.4);
            width: 100%;
            max-width: 390px;
        }

        .logo-central-img {
            width: 80px;
            height: 80px;
            object-fit: contain;
        }

        .titulo-login { color: #521f29; font-size: 1.3rem; font-weight: 800; }
        .subtitulo-login { color: #6c757d; font-size: 0.85rem; }

        .btn-nora {
            background-color: #521f29;
            border-color: #521f29;
            color: #ffffff;
            font-weight: 700;
            border-radius: 8px;
            padding: 10px;
        }
        .btn-nora:hover { background-color: #3b161d; color: #ffffff; }

        .form-control { border-radius: 8px; height: 42px; font-size: 0.9rem; }
        .form-control:focus { border-color: #521f29; box-shadow: 0 0 0 0.2rem rgba(82, 31, 41, 0.15); }

        .link-recuperar { color: #cd1e3e; font-size: 0.85rem; font-weight: 700; text-decoration: none; }
        .link-recuperar:hover { color: #901229; text-decoration: underline; }

        .captcha-img-box {
            border-radius: 8px;
            border: 1px solid #ced4da;
            overflow: hidden;
            background: #f8f9fa;
        }

        /* ══════════ RESPONSIVE MÓVIL ══════════ */
        @media (max-width: 767.98px) {
            .main-wrapper {
                justify-content: center;
                padding: 15px;
            }
            .card-login {
                max-width: 420px;
            }
            .titulo-login { font-size: 1.15rem; }
        }
    </style>
</head>
<body>

    <div class="main-wrapper">
        <div class="card card-login">
            <div class="card-body p-4 text-center">
                
                <div class="mb-2">
                    <img src="assets/img/icono.png" alt="Escuela Nora Castañeda" class="logo-central-img">
                </div>

                <div class="mb-3">
                    <h1 class="titulo-login mb-1">Panel de Acceso</h1>
                    <p class="subtitulo-login mb-0">Escuela Internacional de liderazgo de la Mujer "Nora Castañeda"</p>
                </div>

                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger alert-dismissible fade show text-left small font-weight-bold" role="alert">
                        <i class="fas fa-exclamation-circle mr-1"></i> <?= $error ?>
                        <button type="button" class="close" data-dismiss="alert">&times;</button>
                    </div>
                <?php endif; ?>

                <form method="POST" action="index.php" class="text-left">
                    <?php if (function_exists('csrf_field')) { echo csrf_field(); } ?>
                    <div class="form-group mb-2">
                        <label class="small text-gray-700 font-weight-bold">Usuario o Cédula</label>
                        <input type="text" name="cedula" class="form-control" placeholder="Ej: V-123456789" required autofocus>
                    </div>

                    <div class="form-group mb-3">
                        <label class="small text-gray-700 font-weight-bold">Contraseña</label>
                        <div class="input-group">
                            <input type="password" name="password" id="password" class="form-control" placeholder="••••••••" required>
                            <div class="input-group-append">
                                <button type="button" class="btn btn-outline-secondary" id="togglePassword">
                                    <i class="fas fa-eye" id="eyeIcon"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- CASILLA DE IMAGEN CAPTCHA DINÁMICA -->
                    <div class="form-group mb-3 p-2 bg-light rounded border">
                        <label class="small font-weight-bold text-dark d-block mb-2">
                            <i class="fas fa-robot text-danger mr-1"></i> Verificación Humana (CAPTCHA)
                        </label>
                        <div class="d-flex align-items-center justify-content-between mb-2">
                            <div class="captcha-img-box">
                                <img src="captcha.php?t=<?= time() ?>" id="imgCaptcha" alt="CAPTCHA" style="height: 40px; width: 120px; object-fit: cover;">
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-secondary font-weight-bold" onclick="recargarCaptcha()" title="Cambiar Imagen">
                                <i class="fas fa-sync-alt"></i> Recargar
                            </button>
                        </div>
                        <input type="text" name="captcha_input" class="form-control text-center font-weight-bold text-uppercase" placeholder="Escriba los caracteres de la imagen" maxlength="4" autocomplete="off" required>
                    </div>

                    <button type="submit" class="btn btn-nora btn-block font-weight-bold shadow-sm">
                        Iniciar Sesión
                    </button>
                </form>

                <div class="mt-3">
                    <a class="link-recuperar" href="recuperar_clave.php">
                        ¿Olvidaste tu contraseña?
                    </a>
                </div>

                <div class="mt-2">
                    <a class="small text-muted font-weight-bold" href="registro.php">
                        Crear nueva cuenta (Requiere Aprobación)
                    </a>
                </div>

            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        function recargarCaptcha() {
            document.getElementById('imgCaptcha').src = 'captcha.php?' + new Date().getTime();
        }

        $(document).ready(function() {
            $('#togglePassword').on('click', function() {
                const passwordInput = $('#password');
                const eyeIcon = $('#eyeIcon');

                if (passwordInput.attr('type') === 'password') {
                    passwordInput.attr('type', 'text');
                    eyeIcon.removeClass('fa-eye').addClass('fa-eye-slash');
                } else {
                    passwordInput.attr('type', 'password');
                    eyeIcon.removeClass('fa-eye-slash').addClass('fa-eye');
                }
            });
        });
    </script>
</body>
</html>