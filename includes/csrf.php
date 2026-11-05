<?php
// /sistema_nora/includes/csrf.php
// Token CSRF por sesión: protección contra Cross-Site Request Forgery.
//
// La verificación es CENTRAL: config/db.php llama a csrf_verificar() en toda
// petición POST, por lo que todos los formularios deben incluir csrf_field()
// y las llamadas fetch en JS deben enviar el token (campo '_csrf' en el body
// o header HTTP 'X-CSRF-Token').

function csrf_token() {
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field() {
    return '<input type="hidden" name="_csrf" value="'
         . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
}

function csrf_verificar() {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return;
    }
    $esperado = $_SESSION['csrf_token'] ?? '';
    $enviado  = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');

    if (!is_string($esperado) || strlen($esperado) !== 64
        || !is_string($enviado) || $enviado === ''
        || !hash_equals($esperado, $enviado)) {
        http_response_code(403);
        exit('Error de seguridad (CSRF): token inválido o ausente. '
           . 'Vuelva a la página anterior e intente de nuevo.');
    }
}
