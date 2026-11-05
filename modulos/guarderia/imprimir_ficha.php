<?php
// /sistema_nora/modulos/guarderia/imprimir_ficha.php
require_once '../../config/db.php';

// Control de acceso: exclusivo de Guardería / Administración
session_start();
$roles_gf = $_SESSION['roles_asignados'] ?? [$_SESSION['tipo_usuario'] ?? ''];
if (empty($_SESSION['id_usuario'])
    || !(in_array('superadmin', $roles_gf) || in_array('admin', $roles_gf) || in_array('guarderia', $roles_gf))) {
    http_response_code(403);
    die("Acceso restringido a Centro de Cuidado Infantil.");
}

$id_nino = intval($_GET['id'] ?? 0);

$sql = "SELECT n.*, 
               u.nombre AS nombre_rep, u.apellido AS apellido_rep, u.cedula AS cedula_rep, u.telefono AS telefono_rep, u.email AS email_rep
        FROM public.guarderia_ninos n
        JOIN public.usuarios u ON n.id_representante = u.id_usuario
        WHERE n.id_nino = :id LIMIT 1";

$stmt = $pdo->prepare($sql);
$stmt->execute([':id' => $id_nino]);
$nino = $stmt->fetch();

if (!$nino) die("Ficha no encontrada.");

$fnac = new DateTime($nino['fecha_nacimiento']);
$hoy = new DateTime();
$edad = $hoy->diff($fnac)->y;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Ficha de Inscripción - Guardería</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <style>
        body { background: #fff; font-family: sans-serif; }
        .hoja { max-width: 800px; margin: 20px auto; border: 2px solid #521f29; padding: 25px; border-radius: 10px; }
        @media print { .no-print { display: none; } .hoja { border: none; margin: 0; width: 100%; } }
    </style>
</head>
<body>

<div class="text-right container mt-3 no-print">
    <button onclick="window.print()" class="btn btn-danger font-weight-bold"><i class="fas fa-print"></i> IMPRIMIR FICHA</button>
</div>

<div class="hoja">
    <div class="d-flex justify-content-between align-items-center pb-3 border-bottom mb-3">
        <img src="../../assets/img/Logo del Ministerio 999 x 150-01.png" style="height: 40px;" onerror="this.style.display='none'">
        <div class="text-center">
            <h6 class="m-0 font-weight-bold" style="color: #521f29;">ESCUELA INTERNACIONAL "NORA CASTAÑEDA"</h6>
            <small class="font-weight-bold text-muted">CENTRO DE CUIDADO INFANTIL / GUARDERÍA</small>
        </div>
        <img src="../../assets/img/LogoEscuelaNora_NUEVO.PNG" style="height: 50px;" onerror="this.style.display='none'">
    </div>

    <h5 class="text-center font-weight-bold mb-4" style="color: #ff8a00;">FICHA TÉCNICA DE MATRÍCULA INFANTIL</h5>

    <div class="p-3 bg-light rounded border mb-3">
        <h6 class="font-weight-bold" style="color: #521f29;">1. DATOS DEL NIÑO / NIÑA</h6>
        <p class="mb-1"><strong>Nombres y Apellidos:</strong> <?= htmlspecialchars($nino['nombre_nino'] . ' ' . $nino['apellido_nino']) ?></p>
        <p class="mb-1"><strong>Fecha de Nacimiento:</strong> <?= date('d/m/Y', strtotime($nino['fecha_nacimiento'])) ?> (<strong><?= $edad ?> Años</strong>)</p>
        <p class="mb-0"><strong>Sexo:</strong> <?= $nino['sexo'] ?></p>
    </div>

    <div class="p-3 bg-light rounded border mb-3">
        <h6 class="font-weight-bold" style="color: #521f29;">2. DATOS DE LA MADRE / REPRESENTANTE</h6>
        <p class="mb-1"><strong>Nombre Completo:</strong> <?= htmlspecialchars($nino['nombre_rep'] . ' ' . $nino['apellido_rep']) ?></p>
        <p class="mb-1"><strong>Cédula de Identidad:</strong> V-<?= htmlspecialchars($nino['cedula_rep']) ?></p>
        <p class="mb-0"><strong>Teléfono de Contacto:</strong> <?= htmlspecialchars($nino['telefono_rep'] ?? 'S/D') ?></p>
    </div>

    <div class="p-3 bg-light rounded border mb-4">
        <h6 class="font-weight-bold" style="color: #521f29;">3. OBSERVACIONES MÉDICAS Y CUIDADOS ESPECIALES</h6>
        <p class="mb-0 text-muted"><?= htmlspecialchars($nino['observaciones'] ?? 'Sin observaciones registradas.') ?></p>
    </div>

    <div class="row text-center mt-5 pt-4">
        <div class="col-6">
            <div style="border-top: 1px solid #333; width: 80%; margin: 0 auto;">Firma del Representante</div>
        </div>
        <div class="col-6">
            <div style="border-top: 1px solid #333; width: 80%; margin: 0 auto;">Coordinación Guardería</div>
        </div>
    </div>
</div>

</body>
</html>