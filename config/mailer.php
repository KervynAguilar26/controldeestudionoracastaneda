<?php
/**
 * SERVICIO DE CORREO DEL SISTEMA
 * Escuela Internacional de Liderazgo de la Mujer "Nora Castañeda"
 *
 * Adaptado del proyecto anterior para el sistema_nora (PHP/PostgreSQL).
 * Usa PHPMailer incluido en /libs/PHPMailer y credenciales SMTP en
 * config/mail_secrets.php (protegido contra acceso web).
 *
 * Uso:
 *   require_once __DIR__ . '/../config/mailer.php';
 *   enviarCorreoSistema('correo@dominio.com', 'Asunto', '<p>Hola</p>', 'Nombre');
 */

$__phpmailer_path = __DIR__ . '/../libs/PHPMailer/src/';
if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer') && file_exists($__phpmailer_path . 'PHPMailer.php')) {
    require_once $__phpmailer_path . 'Exception.php';
    require_once $__phpmailer_path . 'PHPMailer.php';
    require_once $__phpmailer_path . 'SMTP.php';
}

$__mail_secrets = __DIR__ . '/mail_secrets.php';
if (file_exists($__mail_secrets)) {
    require_once $__mail_secrets;
}

/**
 * URL base del sistema (para enlaces dentro de los correos).
 */
function url_base_sistema(): string
{
    if (defined('APP_URL')) {
        return rtrim(APP_URL, '/');
    }
    $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $esquema = $https ? 'https' : 'http';
    if (PHP_SAPI === 'cli' || empty($_SERVER['HTTP_HOST'])) {
        return 'http://localhost/sistema_nora';
    }
    return $esquema . '://' . $host . '/sistema_nora';
}

/**
 * Crea y configura una instancia de PHPMailer con SMTP.
 * Devuelve null si PHPMailer o las credenciales no están disponibles.
 */
function crear_mailer_smtp()
{
    if (!class_exists('PHPMailer\\PHPMailer\\PHPMailer')) {
        error_log('Mailer: PHPMailer no está disponible en /libs/PHPMailer.');
        return null;
    }
    if (!defined('MAIL_SMTP_USER') || !defined('MAIL_SMTP_PASS')) {
        error_log('Mailer: faltan las credenciales SMTP en config/mail_secrets.php.');
        return null;
    }

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

    $mail->isSMTP();
    $mail->Host       = defined('MAIL_SMTP_HOST') ? MAIL_SMTP_HOST : 'smtp.gmail.com';
    $mail->Port       = defined('MAIL_SMTP_PORT') ? (int) MAIL_SMTP_PORT : 587;
    $mail->SMTPAuth   = true;
    $mail->Username   = MAIL_SMTP_USER;
    $mail->Password   = MAIL_SMTP_PASS;
    $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    $mail->CharSet    = 'UTF-8';
    $mail->Timeout    = 20;

    // Solo si se habilita explícitamente (entornos locales con certificados propios)
    if (defined('MAIL_SMTP_INSECURE_SSL') && MAIL_SMTP_INSECURE_SSL === true) {
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true,
            ],
        ];
    }

    $mail->setFrom(MAIL_SMTP_USER, defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : 'Escuela Nora Castañeda');

    return $mail;
}

/**
 * Envía un correo HTML genérico. Devuelve true si se envió.
 */
function enviarCorreoSistema(string $destino, string $asunto, string $cuerpoHtml, string $nombreDestino = ''): bool
{
    if ($destino === '' || !filter_var($destino, FILTER_VALIDATE_EMAIL)) {
        error_log('Mailer: destinatario inválido (' . $destino . ').');
        return false;
    }

    $mail = crear_mailer_smtp();
    if ($mail === null) {
        return false;
    }

    try {
        $mail->addAddress($destino, $nombreDestino !== '' ? $nombreDestino : $destino);
        $mail->isHTML(true);
        $mail->Subject = $asunto;
        $mail->Body    = $cuerpoHtml;
        $mail->AltBody = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />', '</p>'], "\n", $cuerpoHtml)));

        $mail->send();
        return true;
    } catch (\PHPMailer\PHPMailer\Exception $e) {
        error_log('Mailer: error al enviar a ' . $destino . ': ' . $mail->ErrorInfo);
        return false;
    } catch (\Exception $e) {
        error_log('Mailer: error general al enviar a ' . $destino . ': ' . $e->getMessage());
        return false;
    }
}

/**
 * Plantilla visual institucional para los correos del sistema.
 */
function plantilla_correo(string $titulo, string $contenidoHtml): string
{
    return "
    <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; border: 1px solid #e0e0e0; border-radius: 12px; overflow: hidden; background-color: #fdf8f3;'>
        <div style='background-color: #521f29; padding: 20px; text-align: center;'>
            <h2 style='color: #ffffff; margin: 0; font-size: 18px;'>ESCUELA INTERNACIONAL DE LIDERAZGO DE LA MUJER</h2>
            <h3 style='color: #e5b871; margin: 5px 0 0 0; font-size: 16px;'>&quot;NORA CASTAÑEDA&quot;</h3>
        </div>
        <div style='padding: 25px; color: #333333;'>
            <h3 style='color: #521f29; margin-top: 0;'>{$titulo}</h3>
            {$contenidoHtml}
            <p style='font-size: 12px; color: #888; margin-top: 30px; border-top: 1px solid #eee; padding-top: 10px;'>
                Este mensaje fue generado automáticamente por el Sistema Administrativo de la Escuela Nora Castañeda.
                Por favor, no responda a este correo.
            </p>
        </div>
    </div>";
}

/**
 * Correo de bienvenida con las credenciales iniciales de una cuenta.
 */
function enviarCredencialesUsuario(string $emailDestino, string $nombreUsuario, string $usuarioAcceso, string $passwordPlana, string $rolAsignado): bool
{
    $nombreUsuario = htmlspecialchars($nombreUsuario, ENT_QUOTES, 'UTF-8');
    $usuarioAcceso = htmlspecialchars($usuarioAcceso, ENT_QUOTES, 'UTF-8');
    $passwordPlana = htmlspecialchars($passwordPlana, ENT_QUOTES, 'UTF-8');
    $rolAsignado   = htmlspecialchars($rolAsignado, ENT_QUOTES, 'UTF-8');
    $urlLogin      = url_base_sistema() . '/index.php';

    $contenido = "
        <p>Se ha creado exitosamente tu cuenta de acceso al Sistema Administrativo.</p>
        <div style='background-color: #ffffff; border-left: 4px solid #ff8a00; padding: 15px; margin: 20px 0; border-radius: 4px;'>
            <h4 style='color: #521f29; margin-top: 0;'>Tus Credenciales de Acceso:</h4>
            <p style='margin: 4px 0;'><strong>Usuario (Cédula):</strong> {$usuarioAcceso}</p>
            <p style='margin: 4px 0;'><strong>Contraseña Inicial:</strong> <code style='background: #f4f4f4; padding: 2px 6px; border-radius: 4px;'>{$passwordPlana}</code></p>
            <p style='margin: 4px 0;'><strong>Rol Asignado:</strong> {$rolAsignado}</p>
        </div>
        <p style='font-size: 13px; color: #666;'>Por razones de seguridad, te recomendamos iniciar sesión y cambiar tu contraseña en tu primer ingreso.</p>
        <div style='text-align: center; margin-top: 25px;'>
            <a href='{$urlLogin}' style='display: inline-block; padding: 12px 25px; color: #ffffff; background-color: #521f29; text-decoration: none; border-radius: 25px; font-weight: bold;'>Acceder al Sistema</a>
        </div>";

    return enviarCorreoSistema(
        $emailDestino,
        'Bienvenido/a al Sistema - Escuela Nora Castañeda',
        plantilla_correo('¡Bienvenido/a, ' . $nombreUsuario . '!', $contenido),
        $nombreUsuario
    );
}

/**
 * Correo con el enlace para restablecer la contraseña.
 */
function enviarEnlaceRecuperacion(string $emailDestino, string $nombreUsuario, string $enlace, int $minutosValidez = 60): bool
{
    $nombreUsuario = htmlspecialchars($nombreUsuario, ENT_QUOTES, 'UTF-8');
    $enlace        = htmlspecialchars($enlace, ENT_QUOTES, 'UTF-8');

    $contenido = "
        <p>Estimado(a) <strong>{$nombreUsuario}</strong>,</p>
        <p>Recibimos una solicitud para restablecer la contraseña de su cuenta. Haga clic en el siguiente botón para crear una nueva contraseña:</p>
        <div style='text-align: center; margin: 25px 0;'>
            <a href='{$enlace}' style='display: inline-block; padding: 12px 25px; color: #ffffff; background-color: #ff8a00; text-decoration: none; border-radius: 25px; font-weight: bold;'>Restablecer Contraseña</a>
        </div>
        <p style='font-size: 13px; color: #666;'>Este enlace es válido por {$minutosValidez} minutos y solo puede usarse una vez.</p>
        <p style='font-size: 13px; color: #666;'>Si usted no solicitó este cambio, ignore este mensaje; su contraseña actual seguirá funcionando.</p>";

    return enviarCorreoSistema(
        $emailDestino,
        'Recuperación de Contraseña - Escuela Nora Castañeda',
        plantilla_correo('Recuperación de Contraseña', $contenido),
        $nombreUsuario
    );
}
