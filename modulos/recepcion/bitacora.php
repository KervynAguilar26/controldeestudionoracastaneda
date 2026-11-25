<?php
// /sistema_nora/modulos/recepcion/bitacora.php
// Helper local de auditoría del módulo Recepción.
// La auditoría es NO CRÍTICA: nunca debe detener la operación principal.

if (!function_exists('recepcion_bitacora')) {
    function recepcion_bitacora(PDO $pdo, string $accion): void
    {
        try {
            $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
            if ($ip === '') {
                $ip = 'local-cli';
            }

            $stmt = $pdo->prepare("INSERT INTO public.bitacora (id_usuario, modulo, accion, ip_maquina)
                                   VALUES (:u, 'RECEPCION', :a, :ip)");
            $stmt->execute([
                ':u'  => $_SESSION['id_usuario'] ?? null,
                ':a'  => substr($accion, 0, 900),
                ':ip' => $ip,
            ]);
        } catch (Throwable $e) {
            // Registro de auditoría no crítico: solo se reporta en logs del servidor.
            error_log('RECEPCION bitacora: ' . $e->getMessage());
        }
    }
}
