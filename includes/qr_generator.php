<?php
// /sistema_nora/includes/qr_generator.php
// Generación local de códigos QR (sin servicios externos) con caché en disco.

require_once __DIR__ . '/dompdf_autoload.php';

if (!function_exists('sistema_qr_png')) {
    /**
     * Genera (o reutiliza) un PNG con el código QR del texto indicado.
     * Devuelve la ruta absoluta del archivo o null si no fue posible generarlo.
     */
    function sistema_qr_png(string $texto, string $prefijo = 'qr'): ?string
    {
        $texto = trim($texto);
        if ($texto === '') {
            return null;
        }

        $carpeta = dirname(__DIR__) . '/assets/qr';
        if (!is_dir($carpeta) && !@mkdir($carpeta, 0777, true) && !is_dir($carpeta)) {
            return null;
        }

        $prefijo = preg_replace('/[^a-zA-Z0-9_-]/', '', $prefijo) ?: 'qr';
        $archivo = $carpeta . '/' . $prefijo . '_' . sha1($texto) . '.png';
        if (is_file($archivo) && filesize($archivo) > 0) {
            return $archivo;
        }

        // 1) Generación local con Endroid (funciona sin internet)
        try {
            $builder = \Endroid\QrCode\Builder\Builder::create()
                ->writer(new \Endroid\QrCode\Writer\PngWriter())
                ->data($texto)
                ->encoding(new \Endroid\QrCode\Encoding\Encoding('UTF-8'))
                ->errorCorrectionLevel(new \Endroid\QrCode\ErrorCorrectionLevel\ErrorCorrectionLevelMedium())
                ->size(360)
                ->margin(10)
                ->foregroundColor(new \Endroid\QrCode\Color\Color(82, 31, 41))
                ->backgroundColor(new \Endroid\QrCode\Color\Color(255, 255, 255));

            $resultado = $builder->build();
            $png = $resultado->getString();
            if ($png !== '') {
                file_put_contents($archivo, $png);
                return $archivo;
            }
        } catch (\Throwable $e) {
            // continúa con el respaldo
        }

        // 2) Respaldo: API pública de QR (solo si hay internet)
        $url = 'https://api.qrserver.com/v1/create-qr-code/?size=360x360&data=' . urlencode($texto) . '&color=521f29';
        $png = @file_get_contents($url);
        if ($png !== false && strlen($png) > 0) {
            file_put_contents($archivo, $png);
            return $archivo;
        }

        return null;
    }
}

if (!function_exists('sistema_qr_url')) {
    /**
     * URL absoluta del validador público para un folio.
     */
    function sistema_qr_url(string $folio): string
    {
        $es_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $protocolo = $es_https ? 'https://' : 'http://';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return $protocolo . $host . '/sistema_nora/validar_certificado.php?folio=' . urlencode($folio);
    }
}

if (!function_exists('sistema_asset_reducir')) {
    /**
     * Crea (una sola vez) una versión reducida de una imagen grande para poder
     * incrustarla en PDF. Devuelve la ruta de la copia reducida o null.
     */
    function sistema_asset_reducir(string $ruta, int $anchoMax): ?string
    {
        if ($anchoMax <= 0 || !function_exists('imagecreatefromstring')) {
            return null;
        }

        $cacheDir = dirname(__DIR__) . '/assets/cache';
        if (!is_dir($cacheDir) && !@mkdir($cacheDir, 0777, true) && !is_dir($cacheDir)) {
            return null;
        }

        $clave = sha1($ruta . '|' . $anchoMax) . '.png';
        $salida = $cacheDir . '/' . $clave;
        if (is_file($salida) && filesize($salida) > 0) {
            return $salida;
        }

        $info = @getimagesize($ruta);
        if (empty($info[0])) {
            return null;
        }

        $datos = file_get_contents($ruta);
        if ($datos === false) {
            return null;
        }

        $pixeles = (int) $info[0] * (int) $info[1];
        $memoriaAnterior = ini_get('memory_limit');
        if ($pixeles > 2000000) {
            @ini_set('memory_limit', '2048M');
        }

        $src = @imagecreatefromstring($datos);
        if (!$src) {
            if ($memoriaAnterior !== false) {
                @ini_set('memory_limit', $memoriaAnterior);
            }
            return null;
        }

        $w = imagesx($src);
        $h = imagesy($src);
        $nuevoAncho = min($anchoMax, $w);
        $nuevoAlto = max(1, (int) round($h * $nuevoAncho / $w));

        $dst = imagecreatetruecolor($nuevoAncho, $nuevoAlto);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparente = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefilledrectangle($dst, 0, 0, $nuevoAncho, $nuevoAlto, $transparente);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nuevoAncho, $nuevoAlto, $w, $h);
        imagepng($dst, $salida, 7);
        imagedestroy($dst);
        imagedestroy($src);

        if ($memoriaAnterior !== false) {
            @ini_set('memory_limit', $memoriaAnterior);
        }

        return (is_file($salida) && filesize($salida) > 0) ? $salida : null;
    }
}

if (!function_exists('sistema_asset_data_uri')) {
    /**
     * Convierte una imagen del proyecto en Data URI (para incrustarla en PDF).
     * Si se indica $anchoMax, la imagen se reduce para aligerar el documento.
     */
    function sistema_asset_data_uri(string $rutaRelativa, int $anchoMax = 0): string
    {
        $ruta = dirname(__DIR__) . '/' . ltrim(str_replace('\\', '/', $rutaRelativa), '/');
        if (!is_file($ruta)) {
            return '';
        }
        $info = @getimagesize($ruta);
        $mime = $info['mime'] ?? 'image/png';
        $anchoOriginal = (int) ($info[0] ?? 0);

        if ($anchoMax > 0 && $anchoOriginal > $anchoMax) {
            $reducida = sistema_asset_reducir($ruta, $anchoMax);
            if ($reducida) {
                $ruta = $reducida;
                $mime = 'image/png';
            } elseif ($anchoOriginal > 3000) {
                // Imagen gigante que no pudo reducirse: se omite para no romper el PDF
                return '';
            }
        }

        $datos = file_get_contents($ruta);
        if ($datos === false) {
            return '';
        }

        return 'data:' . $mime . ';base64,' . base64_encode($datos);
    }
}

if (!function_exists('sistema_qr_data_uri')) {
    function sistema_qr_data_uri(string $texto, string $prefijo = 'qr'): string
    {
        $ruta = sistema_qr_png($texto, $prefijo);
        if (!$ruta) {
            return '';
        }
        return 'data:image/png;base64,' . base64_encode(file_get_contents($ruta));
    }
}
