<?php
// /sistema_nora/imprimir_carnet.php
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once 'config/db.php';

// Control de acceso: sesión obligatoria; cada usuario solo ve su carné
// (salvo administración / Control de Estudio que pueden ver cualquiera).
$id_sesion = intval($_SESSION['id_usuario'] ?? 0);
if ($id_sesion === 0) {
    http_response_code(401);
    die("Debe iniciar sesión para imprimir el carné.");
}

$roles_carnet = $_SESSION['roles_asignados'] ?? [$_SESSION['tipo_usuario'] ?? ''];
$es_admin_carnet = in_array('superadmin', $roles_carnet) || in_array('admin', $roles_carnet)
                 || in_array('control_estudio', $roles_carnet);

$id_u = intval($_GET['id_usuario'] ?? $id_sesion);
if ($id_u === 0) { die("ID de usuario no válido."); }

if (!$es_admin_carnet && $id_u !== $id_sesion) {
    http_response_code(403);
    die("No tiene permiso para imprimir el carné de otro usuario.");
}

try {
    $stmt = $pdo->prepare("SELECT * FROM public.usuarios WHERE id_usuario = :id LIMIT 1");
    $stmt->execute([':id' => $id_u]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$u) { die("Usuario no encontrado."); }

    // Obtenemos la Directora Activa
    $nombre_directora = 'DRA. NORA CASTAÑEDA';
    $firma_directora  = null;
    try {
        $stmt_aut = $pdo->query("SELECT * FROM public.configuracion_autoridades WHERE (cargo ILIKE '%Directora%' OR cargo ILIKE '%Director de la Escuela%') ORDER BY id_autoridad DESC LIMIT 1");
        $autoridad = $stmt_aut->fetch(PDO::FETCH_ASSOC);
        if ($autoridad) {
            $nombre_directora = $autoridad['nombre_apellido'];
            if (!empty($autoridad['firma_digital']) && file_exists(__DIR__ . '/' . $autoridad['firma_digital'])) {
                $firma_directora = $autoridad['firma_digital'];
            }
        }
    } catch (Exception $e_a) {}

    $foto_carnet = (!empty($u['foto_perfil']) && file_exists(__DIR__ . '/public/uploads/perfiles/' . $u['foto_perfil']))
        ? 'public/uploads/perfiles/' . $u['foto_perfil']
        : 'assets/img/imagen_perfil_generico.jpg';

    $qr_url = "https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=" . urlencode($u['cedula']);

} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Carnet PVC Oficial | Escuela Nora Castañeda</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        @media print {
            body { background: none; margin: 0; padding: 0; }
            .no-print { display: none !important; }
            .carnet-wrapper { page-break-after: always; }
        }
        body { font-family: 'Helvetica Neue', Arial, sans-serif; background: #d6c4af; display: flex; flex-direction: column; align-items: center; padding: 20px; }
        .carnet-card { width: 54mm; height: 85mm; background: #ffffff; border-radius: 4mm; box-shadow: 0 4px 10px rgba(0,0,0,0.3); position: relative; overflow: hidden; border: 1px solid #ccc; margin-bottom: 20px; box-sizing: border-box; }
        .header-bandera { width: 100%; height: 38mm; background: url('assets/img/bandera.jpg') no-repeat top center; background-size: cover; }
        .foto-container { position: absolute; top: 22mm; left: 0; right: 0; text-align: center; z-index: 2; }
        .foto-container img { width: 25mm; height: 29mm; object-fit: cover; border-radius: 2mm; border: 2px solid #ffffff; box-shadow: 0 2px 6px rgba(0,0,0,0.25); }
        .datos-container { text-align: center; padding: 0 2mm; margin-top: 16mm; }
        .nombre-funcionario { font-size: 8pt; font-weight: 800; color: #521f29; text-transform: uppercase; line-height: 1.1; }
        .cargo-badge { font-size: 6.5pt; font-weight: 700; color: #ff8a00; margin-top: 1mm; text-transform: uppercase; }
        .cedula-text { font-size: 6pt; font-weight: 700; color: #666666; margin-top: 0.5mm; }
        .pie-logos-oficiales { position: absolute; bottom: 2mm; left: 2mm; right: 2mm; display: flex; align-items: center; justify-content: space-between; height: 8mm; border-top: 1px solid #eeeeee; padding-top: 1mm; }
        .pie-logos-oficiales img.min-logo { height: 7.5mm; max-width: 28mm; object-fit: contain; }
        .pie-logos-oficiales img.escuela-logo { height: 8mm; max-width: 18mm; object-fit: contain; }
        .reverso-header { background: #521f29; color: #ffffff; text-align: center; font-size: 5.5pt; font-weight: 800; padding: 2mm 0; }
        .qr-section { text-align: center; margin-top: 3mm; }
        .qr-section img { width: 22mm; height: 22mm; }
        .leyenda-legal { font-size: 4.2pt; color: #444; text-align: justify; padding: 0 3mm; margin-top: 2mm; line-height: 1.1; }
        .firma-container { text-align: center; margin-top: 3mm; }
        .firma-container img { max-height: 8mm; max-width: 28mm; object-fit: contain; display: block; margin: 0 auto; }
        .linea-firma { width: 28mm; border-bottom: 0.8pt solid #333; margin: 0 auto 1px auto; }
        .autoridad-nombre { font-size: 4.5pt; font-weight: 800; color: #521f29; text-transform: uppercase; }
        .autoridad-cargo { font-size: 4pt; font-weight: 700; color: #ff8a00; text-transform: uppercase; }
        .franja-tricolor-pie { position: absolute; bottom: 0; left: 0; right: 0; height: 2mm; background: linear-gradient(90deg, #FFC907 0%, #FFC907 33%, #273376 33%, #273376 66%, #A70336 66%, #A70336 100%); }
        .btn-accion { padding: 10px 18px; font-weight: bold; border: none; border-radius: 6px; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; box-shadow: 0 2px 5px rgba(0,0,0,0.2); margin: 0 5px; }
    </style>
</head>
<body>

    <div class="no-print" style="margin-bottom: 20px;">
        <button onclick="window.print()" class="btn-accion" style="background: #521f29; color: #ffffff;">
            <i class="fas fa-print" style="margin-right: 8px;"></i> IMPRIMIR CARNET PVC (ID-1)
        </button>
        <a href="<?= $qr_url ?>" download="QR_<?= $u['cedula'] ?>.png" class="btn-accion" style="background: #ff8a00; color: #ffffff;">
            <i class="fas fa-download" style="margin-right: 8px;"></i> DESCARGAR CÓDIGO QR
        </a>
    </div>

    <!-- ANVERSO DEL CARNET -->
    <div class="carnet-wrapper">
        <div class="carnet-card">
            <div class="header-bandera"></div>
            <div class="foto-container">
                <img src="<?= $foto_carnet ?>" alt="Foto Funcionario">
            </div>
            <div class="datos-container">
                <div class="nombre-funcionario"><?= htmlspecialchars(($u['nombre'] ?? '') . ' ' . ($u['apellido'] ?? '')) ?></div>
                <!-- MOSTRAR CARGO O FUNCIÓN EN EL CARNET -->
                <div class="cargo-badge"><?= htmlspecialchars($u['cargo_funcion'] ?? 'Personal Institucional') ?></div>
                <div class="cedula-text">C.I. V-<?= htmlspecialchars($u['cedula'] ?? '') ?></div>
            </div>
            <div class="pie-logos-oficiales">
                <img src="assets/img/Logo del Ministerio 999 x 150-01.png" class="min-logo" alt="MinMujer">
                <img src="assets/img/LogoEscuelaNora_NUEVO.png" class="escuela-logo" alt="Escuela Nora" onerror="this.src='assets/img/icono.png'">
            </div>
        </div>
    </div>

    <!-- REVERSO DEL CARNET -->
    <div class="carnet-wrapper">
        <div class="carnet-card">
            <div class="reverso-header">REPÚBLICA BOLIVARIANA DE VENEZUELA</div>
            <div class="qr-section">
                <img src="<?= $qr_url ?>" alt="QR Control">
                <div style="font-size: 5pt; font-weight: 800; color: #521f29; margin-top: 1px;">
                    C.I. V-<?= htmlspecialchars($u['cedula'] ?? '') ?>
                </div>
            </div>
            <div class="leyenda-legal">
                Documento de identificación institucional personal e intransferible expedido por la Escuela Internacional de Liderazgo de la Mujer "Nora Castañeda". Valida el acceso a las instalaciones, asistencia y sistema de comensales.
            </div>
            <div class="firma-container">
                <?php if ($firma_directora): ?>
                    <img src="<?= $firma_directora ?>" alt="Firma Directora">
                <?php else: ?>
                    <div style="height: 6mm;"></div>
                <?php endif; ?>
                <div class="linea-firma"></div>
                <div class="autoridad-nombre"><?= htmlspecialchars($nombre_directora) ?></div>
                <div class="autoridad-cargo">DIRECTORA DE LA ESCUELA</div>
            </div>
            <div class="franja-tricolor-pie"></div>
        </div>
    </div>

</body>
</html>