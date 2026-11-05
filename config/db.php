<?php
// /sistema_nora/config/db.php

$host     = '127.0.0.1';
$port     = '5432';
$user     = 'postgres';
$password = '123456';

// 1. Conexión Principal: Sistema Nora (Administrativo)
try {
    $pdo = new PDO("pgsql:host=$host;port=$port;dbname=sistema_nora_v2", $user, $password, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    die("Error de conexión a sistema_nora_v2: " . $e->getMessage());
}

// 2. Conexión Padrón Nacional
try {
    $pdo_padron = new PDO("pgsql:host=$host;port=$port;dbname=padron_nacional_db", $user, $password, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    $pdo_padron = null;
}

// 3. Conexión Portal Web
try {
    $pdo_web = new PDO("pgsql:host=$host;port=$port;dbname=Nora_castaneda", $user, $password, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
    ]);
} catch (PDOException $e) {
    $pdo_web = null;
}

// ═══════════ SEGURIDAD (ronda 4) ═══════════
// 1) Errores: nunca en pantalla, solo al registro (doble capa sobre php.ini)
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');

// 2) Sesión activa en todas las páginas (requisito del token CSRF central)
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start();
}

// 3) Verificación central de CSRF para toda petición POST del sistema
require_once __DIR__ . '/../includes/csrf.php';
csrf_verificar();
?>