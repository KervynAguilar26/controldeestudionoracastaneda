<?php
// /sistema_nora/acceso_movil_qr.php
// MÓDULO TEMPORAL DE PRUEBA: genera un QR con la URL de acceso según la IP
// de red detectada en el servidor. Eliminar este archivo al terminar las pruebas.
require_once __DIR__ . '/config/db.php';

$roles_qr = $_SESSION['roles_asignados'] ?? [$_SESSION['tipo_usuario'] ?? ''];
if (!is_array($roles_qr)) {
    $roles_qr = [$roles_qr];
}
if (!in_array('superadmin', $roles_qr, true) && !in_array('admin', $roles_qr, true)) {
    http_response_code(403);
    die('Este módulo temporal solo está disponible para administradores.');
}

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/includes/qr_generator.php';

/** Prioridad para ordenar IPs: las privadas de LAN primero. */
function nora_prioridad_ip($ip)
{
    $o = array_map('intval', explode('.', $ip));
    if ($o[0] === 192 && $o[1] === 168) { return 300; }
    if ($o[0] === 10) { return 290; }
    if ($o[0] === 172 && $o[1] >= 16 && $o[1] <= 31) { return 280; }
    return 100;
}

/** Detecta las IPv4 de red del equipo (sin loopback ni APIPA). */
function nora_ips_red()
{
    $ips = [];

    if (function_exists('shell_exec')) {
        $salida = @shell_exec('ipconfig');
        if ($salida && preg_match_all('/IPv4[^:]*:?\s*((?:\d{1,3}\.){3}\d{1,3})/i', $salida, $m)) {
            foreach ($m[1] as $ip) {
                $ips[] = $ip;
            }
        }
    }

    $host = gethostname();
    if ($host) {
        $ips[] = gethostbyname($host);
    }
    if (!empty($_SERVER['SERVER_ADDR'])) {
        $ips[] = $_SERVER['SERVER_ADDR'];
    }

    $validas = [];
    foreach (array_unique($ips) as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) { continue; }
        if (strpos($ip, '127.') === 0 || strpos($ip, '169.254.') === 0 || $ip === '0.0.0.0') { continue; }
        $validas[] = $ip;
    }

    usort($validas, function ($a, $b) {
        $pa = nora_prioridad_ip($a);
        $pb = nora_prioridad_ip($b);
        if ($pa === $pb) { return strcmp($a, $b); }
        return ($pa > $pb) ? -1 : 1;
    });

    return array_values(array_unique($validas));
}

$ips_red = nora_ips_red();

// Ruta base del proyecto (ej. /sistema_nora)
$script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/index.php');
$base_path = rtrim(dirname($script), '/');

$ip_sel = (string)($_GET['ip'] ?? '');
if ($ip_sel !== '' && !in_array($ip_sel, $ips_red, true)) {
    $ip_sel = '';
}
if ($ip_sel === '') {
    $ip_sel = $ips_red[0] ?? '127.0.0.1';
}

$gen = !empty($_GET['gen']);
$puerto = (int)($_GET['puerto'] ?? 443);
if ($puerto < 1 || $puerto > 65535) {
    $puerto = 443;
}

// La cámara en vivo del móvil (getUserMedia) exige contexto seguro: HTTPS.
// Por eso el QR se genera en HTTPS/443 por defecto. El campo oculto "gen"
// distingue la primera carga de un formulario ya enviado (donde se puede
// desmarcar HTTPS sin que se vuelva a activar solo).
$https = $gen ? !empty($_GET['https']) : true;

$aviso_ssl = '';
if ($https && $puerto === 80) {
    $https = false;
    $aviso_ssl = 'Apache no tiene SSL en el puerto 80. Se generó HTTP. ' .
                 'Marque «Usar HTTPS» con el puerto 443 para activar la cámara.';
} elseif (!$https) {
    $aviso_ssl = 'Está usando HTTP: el navegador bloqueará la cámara en vivo. ' .
                 'Solo funcionará «Tomar foto del QR». Marque «Usar HTTPS» y puerto 443 para escanear con la cámara.';
}
$proto = $https ? 'https' : 'http';
$puerto_defecto = $https ? 443 : 80;
$trozo_puerto = ($puerto && $puerto !== $puerto_defecto) ? ':' . $puerto : '';
$url_acceso = $proto . '://' . $ip_sel . $trozo_puerto . $base_path . '/index.php';

// QR local (sin servicios externos) y sin dejar archivos en assets/qr.
$ruta_qr = sistema_qr_png($url_acceso, 'acceso_movil');
$qr_data = '';
if ($ruta_qr && is_file($ruta_qr)) {
    $qr_data = 'data:image/png;base64,' . base64_encode((string)file_get_contents($ruta_qr));
    @unlink($ruta_qr);
}
?>

<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h1 class="h4 font-weight-bold mb-1" style="color:#521f29;">
                <i class="fas fa-qrcode mr-2"></i>Acceso móvil por QR
            </h1>
            <p class="text-muted mb-0 small">Módulo temporal de prueba · generado con la IP de red detectada</p>
        </div>
        <a href="<?= $path_prefix ?>dashboard.php" class="btn btn-outline-secondary btn-sm font-weight-bold">
            <i class="fas fa-arrow-left mr-1"></i> Volver al panel
        </a>
    </div>

    <div class="alert alert-warning font-weight-bold small">
        <i class="fas fa-exclamation-triangle mr-2"></i>
        Este módulo es <strong>temporal</strong>. Elimine <code>acceso_movil_qr.php</code> cuando termine las pruebas.
    </div>

    <?php if ($aviso_ssl !== ''): ?>
        <div class="alert alert-danger font-weight-bold small">
            <i class="fas fa-times-circle mr-2"></i><?= htmlspecialchars($aviso_ssl) ?>
        </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-lg-5 mb-4">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header font-weight-bold text-white" style="background:#521f29;">
                    <i class="fas fa-cog mr-2"></i> Parámetros de red
                </div>
                <div class="card-body">
                    <form method="get" action="">
                        <input type="hidden" name="gen" value="1">
                        <div class="form-group">
                            <label class="font-weight-bold small">IP del servidor en la red</label>
                            <?php if (count($ips_red) > 1): ?>
                                <select name="ip" class="form-control form-control-sm">
                                    <?php foreach ($ips_red as $ip): ?>
                                        <option value="<?= htmlspecialchars($ip) ?>" <?= $ip === $ip_sel ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($ip) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="form-text text-muted">Se detectaron varias interfaces; elija la de la Wi-Fi donde está el móvil.</small>
                            <?php else: ?>
                                <input type="text" class="form-control form-control-sm" value="<?= htmlspecialchars($ip_sel) ?>" readonly>
                                <input type="hidden" name="ip" value="<?= htmlspecialchars($ip_sel) ?>">
                                <small class="form-text text-muted">IP detectada automáticamente.</small>
                            <?php endif; ?>
                        </div>

                        <div class="form-row">
                            <div class="form-group col-md-7">
                                <label class="font-weight-bold small">Puerto de Apache</label>
                                <input type="number" name="puerto" min="1" max="65535" class="form-control form-control-sm"
                                       value="<?= (int)$puerto ?>">
                            </div>
                            <div class="form-group col-md-5">
                                <label class="font-weight-bold small">Protocolo</label>
                                <div class="form-check mt-1">
                                    <input class="form-check-input" type="checkbox" name="https" value="1" id="chkHttps" <?= $https ? 'checked' : '' ?>>
                                    <label class="form-check-label small" for="chkHttps">Usar HTTPS (necesario para cámara)</label>
                                </div>
                                <small class="form-text text-muted">Requerido para la cámara en vivo del móvil. Use puerto 443.</small>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-sm btn-warning font-weight-bold btn-block">
                            <i class="fas fa-sync-alt mr-1"></i> Generar QR
                        </button>
                    </form>

                    <hr>
                    <p class="small text-muted mb-2"><strong>URL que contiene el QR:</strong></p>
                    <input type="text" class="form-control form-control-sm" value="<?= htmlspecialchars($url_acceso) ?>" readonly id="urlQr">
                    <a href="<?= htmlspecialchars($url_acceso) ?>" target="_blank" class="small mt-2 d-inline-block">
                        <i class="fas fa-external-link-alt mr-1"></i> Probar esta URL desde el navegador
                    </a>

                    <div class="alert alert-light border small mt-3 mb-0">
                        <strong>Instrucciones:</strong>
                        <ol class="mb-0 pl-3">
                            <li>El móvil debe estar en la <strong>misma Wi-Fi</strong> que esta PC.</li>
                            <li>Escanee el QR; se abrirá <strong>https://<?= htmlspecialchars($ip_sel) ?></strong>.</li>
                            <li>Aparecerá un aviso de certificado autofirmado: toque <strong>Avanzado → Continuar</strong> (solo la primera vez).</li>
                            <li>Ingrese cédula, clave y CAPTCHA; luego en el módulo QR toque <strong>Activar cámara</strong>.</li>
                        </ol>
                        <span class="d-block mt-2 text-danger">
                            El certificado es autofirmado de pruebas. Si no abre, confirme que Apache escuche en el puerto 443 y que el firewall lo permita.
                        </span>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card shadow-sm border-0 text-center">
                <div class="card-header font-weight-bold text-white" style="background:#521f29;">
                    <i class="fas fa-mobile-alt mr-2"></i> QR de acceso
                </div>
                <div class="card-body py-4">
                    <?php if ($qr_data): ?>
                        <img src="<?= $qr_data ?>" alt="QR de acceso móvil"
                             class="img-fluid" style="max-width:320px; image-rendering:pixelated;">
                        <div class="mt-3">
                            <strong class="d-block" style="color:#521f29; font-size:1.05rem;"><?= htmlspecialchars($url_acceso) ?></strong>
                            <small class="text-muted">Código generado localmente · válido para la red actual</small>
                        </div>
                    <?php else: ?>
                        <div class="alert alert-danger font-weight-bold mb-0">
                            No se pudo generar el QR. Use la URL de la izquierda manualmente.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
