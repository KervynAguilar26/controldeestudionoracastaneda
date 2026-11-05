<?php
// /sistema_nora/captcha.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Limpiar cualquier salida previa para evitar corrupción de imagen
if (ob_get_level()) {
    ob_clean();
}

// Generar código aleatorio de 4 caracteres
$caracteres = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
$codigo_captcha = '';
for ($i = 0; $i < 4; $i++) {
    $codigo_captcha .= $caracteres[rand(0, strlen($caracteres) - 1)];
}

$_SESSION['captcha_code'] = $codigo_captcha;

// Forzar deshabilitar caché en el navegador
header('Content-Type: image/png');
header('Cache-Control: no-store, no-cache, must-revalidate, post-check=0, pre-check=0');
header('Pragma: no-cache');
header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');

$imagen = imagecreatetruecolor(130, 45);

$color_fondo = imagecolorallocate($imagen, 240, 242, 245);
$color_texto = imagecolorallocate($imagen, 82, 31, 41);      // Vinotinto institucional
$color_linea = imagecolorallocate($imagen, 255, 138, 0);     // Naranja
$color_ruido = imagecolorallocate($imagen, 180, 180, 180);

imagefilledrectangle($imagen, 0, 0, 130, 45, $color_fondo);

for ($i = 0; $i < 150; $i++) {
    imagesetpixel($imagen, rand(0, 130), rand(0, 45), $color_ruido);
}

for ($i = 0; $i < 4; $i++) {
    imageline($imagen, rand(0, 130), rand(0, 45), rand(0, 130), rand(0, 45), $color_linea);
}

for ($i = 0; $i < strlen($codigo_captcha); $i++) {
    $x = 20 + ($i * 25);
    $y = rand(10, 18);
    imagechar($imagen, 5, $x, $y, $codigo_captcha[$i], $color_texto);
}

imagepng($imagen);
imagedestroy($imagen);
exit();
?>