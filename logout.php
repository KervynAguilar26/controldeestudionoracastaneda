<?php
// /sistema_nora/logout.php
session_start();

// Ronda 5 (PWA): limpiar el caché de páginas del Service Worker antes de salir
// para no dejar contenido con datos del usuario en equipos compartidos.
$base_url = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/';
session_unset();
session_destroy();
?>
<!DOCTYPE html>
<html lang="es">
<head><meta charset="utf-8"><title>Cerrando sesión...</title></head>
<body style="margin:0;background:#d6c4af;color:#521f29;font-family:sans-serif;display:flex;align-items:center;justify-content:center;height:100vh">
<script>
(function () {
    function ir() { window.location.href = <?= json_encode($base_url . 'index.php') ?>; }
    if ('serviceWorker' in navigator && 'caches' in window) {
        navigator.serviceWorker.getRegistrations().then(function (regs) {
            regs.forEach(function (r) { r.active && r.postMessage('limpiar-caché-paginas'); });
        }).catch(function () {});
        caches.keys().then(function (claves) {
            return Promise.all(claves.filter(function (k) { return k.indexOf('nora-pages') === 0; })
                                       .map(function (k) { return caches.delete(k); }));
        }).then(ir).catch(ir);
        setTimeout(ir, 1500); // respaldo si algo no responde
    } else {
        ir();
    }
})();
</script>
<noscript><a href="<?= htmlspecialchars($base_url . 'index.php', ENT_QUOTES) ?>">Continuar</a></noscript>
</body>
</html>