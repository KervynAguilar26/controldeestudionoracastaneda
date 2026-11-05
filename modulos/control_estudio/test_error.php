<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

echo "<h1>Prueba de Diagnóstico PHP</h1>";

require_once '../../config/db.php';
echo "<p>✅ Conexión a la base de datos exitosa.</p>";

try {
    require_once '../../includes/header.php';
    echo "<p>✅ Header cargado correctamente.</p>";
} catch (Throwable $e) {
    echo "<p style='color:red;'>❌ Error en header: " . $e->getMessage() . "</p>";
}