<?php
session_start();
echo "<h3>Configuración de Sesiones PHP</h3>";
echo "<b>session.save_handler:</b> " . ini_get('session.save_handler') . "<br>";
echo "<b>session.save_path:</b> " . ini_get('session.save_path') . "<br>";
echo "<b>session.gc_maxlifetime:</b> " . ini_get('session.gc_maxlifetime') . " segundos<br>";
echo "<b>session.cookie_lifetime:</b> " . ini_get('session.cookie_lifetime') . " segundos<br>";
echo "<b>session.use_strict_mode:</b> " . ini_get('session.use_strict_mode') . "<br>";
echo "<b>Estado de la sesión actual:</b> " . session_status() . "<br>";
?>