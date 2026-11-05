<?php
// /sistema_nora/includes/dompdf_autoload.php
// Cargador PSR-4 mínimo para Dompdf (vendor local, sin Composer).
// Permite generar archivos PDF reales desde el servidor.

spl_autoload_register(function ($clase) {
    $base = dirname(__DIR__) . '/vendor/';

    // Clases heredadas de Dompdf que viven en lib/ (no siguen PSR-4)
    if ($clase === 'Dompdf\\Cpdf') {
        require_once $base . 'dompdf/dompdf/lib/Cpdf.php';
        return;
    }

    $mapa = [
        'Dompdf\\'          => $base . 'dompdf/dompdf/src/',
        'FontLib\\'         => $base . 'dompdf/php-font-lib/src/FontLib/',
        'Svg\\'             => $base . 'dompdf/php-svg-lib/src/Svg/',
        'Masterminds\\'     => $base . 'masterminds/html5/src/',
        'Sabberworm\\CSS\\' => $base . 'sabberworm/php-css-parser/src/',
        'Endroid\\QrCode\\'  => $base . 'endroid/qr-code/src/',
        'BaconQrCode\\'     => $base . 'bacon/bacon-qr-code/src/',
        'DASPRiD\\Enum\\'   => $base . 'dasprid/enum/src/',
    ];

    foreach ($mapa as $prefijo => $ruta) {
        if (strncmp($clase, $prefijo, strlen($prefijo)) !== 0) {
            continue;
        }
        $relativa = substr($clase, strlen($prefijo));
        $archivo  = $ruta . str_replace('\\', '/', $relativa) . '.php';
        if (file_exists($archivo)) {
            require_once $archivo;
        }
        return;
    }
});
