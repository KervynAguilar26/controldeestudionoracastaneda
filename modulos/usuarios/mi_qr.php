<?php
// /sistema_nora/modulos/usuarios/mi_qr.php
require_once '../../config/db.php';
require_once '../../includes/header.php';

$id_user = $_SESSION['id_usuario'] ?? 0;

// Consultar datos actualizados del usuario logueado
$stmt = $pdo->prepare("SELECT id_usuario, cedula, nombre, apellido, cargo_funcion, tipo_usuario, roles_asignados, foto_perfil FROM public.usuarios WHERE id_usuario = :id LIMIT 1");
$stmt->execute([':id' => $id_user]);
$u = $stmt->fetch();

if (!$u) {
    echo "<div class='alert alert-danger font-weight-bold m-4'>Usuario no encontrado.</div>";
    require_once '../../includes/footer.php';
    exit();
}

$nombre_completo = mb_strtoupper(($u['nombre'] ?? '') . ' ' . ($u['apellido'] ?? ''));
$cedula = $u['cedula'] ?? '';
$cargo = !empty($u['cargo_funcion']) ? $u['cargo_funcion'] : ucfirst($u['tipo_usuario'] ?? 'Estudiante');

// Generar URL del QR dinámico basado en Cédula
$qr_api_url = "https://api.qrserver.com/v1/create-qr-code/?size=250x250&color=521f29&data=" . urlencode($cedula);
?>

<div class="row justify-content-center my-4">
    <div class="col-md-6 col-lg-5">
        
        <!-- TARJETA DEL CARNET DIGITAL -->
        <div class="card shadow-lg border-0" style="border-radius: 18px; overflow: hidden;">
            
            <!-- ENCABEZADO INSTITUCIONAL -->
            <div class="p-3 text-center text-white" style="background-color: #521f29;">
                <div class="d-flex justify-content-between align-items-center mb-1 px-2">
                    <img src="<?= $path_prefix ?>assets/img/Logo del Ministerio 999 x 150-01.png" style="height: 22px; filter: brightness(0) invert(1);" alt="MinMujer">
                    <img src="<?= $path_prefix ?>assets/img/icono.png" style="height: 28px;" alt="Logo Nora">
                </div>
                <div class="small font-weight-bold text-uppercase tracking-wide" style="letter-spacing: 1px; color: #ff8a00;">
                    CARNET DIGITAL DE ASISTENCIA
                </div>
            </div>

            <!-- CUERPO DEL CARNET Y QR -->
            <div class="card-body bg-white text-center p-4">
                
                <h5 class="font-weight-bold mb-1 text-uppercase" style="color: #521f29; font-size: 1.1rem;">
                    <?= htmlspecialchars($nombre_completo) ?>
                </h5>
                
                <span class="badge px-3 py-1 font-weight-bold text-white mb-3" style="background-color: #ff8a00; border-radius: 12px; font-size: 0.85rem;">
                    <?= htmlspecialchars($cargo) ?>
                </span>

                <!-- CÓDIGO QR GENERADO -->
                <div class="my-3 p-2 d-inline-block rounded border" style="background: #fafafa; border: 2px dashed #521f29 !important;">
                    <img id="qrImage" src="<?= $qr_api_url ?>" alt="Código QR Cédula" class="img-fluid" style="width: 210px; height: 210px;">
                </div>

                <div class="h4 font-weight-bold mb-1" style="color: #521f29;">
                    V-<?= htmlspecialchars($cedula) ?>
                </div>
                <p class="small text-muted mb-4">
                    Presente este QR en la cámara del comedor o control de acceso.
                </p>

                <!-- BOTONES DE ACCIÓN -->
                <div class="d-flex flex-column gap-2">
                    <!-- BOTÓN DESCARGAR PNG -->
                    <a href="<?= $qr_api_url ?>" download="QR_V-<?= $cedula ?>.png" target="_blank" class="btn font-weight-bold text-white shadow-sm mb-2 py-2" style="background-color: #ff8a00; border-radius: 10px;">
                        <i class="fas fa-download mr-2"></i> DESCARGAR MI CÓDIGO QR (PNG)
                    </a>

                    <!-- BOTÓN IMPRIMIR CARNET PVC NORMATIVO -->
                    <a href="<?= $path_prefix ?>imprimir_carnet.php?id_usuario=<?= $u['id_usuario'] ?>" target="_blank" class="btn btn-outline-secondary font-weight-bold py-2" style="border-radius: 10px; border-width: 2px; color: #521f29;">
                        <i class="fas fa-print mr-2"></i> Imprimir Carnet PVC
                    </a>
                </div>

            </div>
        </div>

    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>