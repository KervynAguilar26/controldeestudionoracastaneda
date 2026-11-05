<?php
// /sistema_nora/modulos/estudiante/imprimir_malla.php
if (session_status() === PHP_SESSION_NONE) { session_start(); }

// Control de acceso: requiere sesión (usuarios autenticados del sistema)
if (empty($_SESSION['id_usuario'])) {
    header('Location: ../../index.php');
    exit();
}

require_once '../../config/db.php';

$id_malla = intval($_GET['id_malla'] ?? 0);

// Cargar Malla
$stmt_m = $pdo->prepare("SELECT * FROM public.mallas_curriculares WHERE id_malla = :id");
$stmt_m->execute([':id' => $id_malla]);
$malla = $stmt_m->fetch();

if (!$malla) die("Malla curricular no encontrada.");

// Cargar Materias
$stmt_mat = $pdo->prepare("SELECT * FROM public.malla_materias WHERE id_malla = :id ORDER BY modulo_num ASC, id_materia ASC");
$stmt_mat->execute([':id' => $id_malla]);
$materias = $stmt_mat->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Malla Curricular - <?= htmlspecialchars($malla['nombre_programa']) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <style>
        body { background: #fff; font-family: sans-serif; }
        .hoja { max-width: 850px; margin: 20px auto; padding: 30px; border: 2px solid #521f29; border-radius: 10px; }
        @media print { .no-print { display: none; } .hoja { border: none; margin: 0; width: 100%; } }
    </style>
</head>
<body>

<div class="text-right container mt-3 no-print">
    <button onclick="window.print()" class="btn btn-danger font-weight-bold"><i class="fas fa-print"></i> IMPRIMIR MALLA PDF</button>
</div>

<div class="hoja">
    <div class="d-flex justify-content-between align-items-center pb-3 border-bottom mb-3">
        <img src="../../assets/img/Logo del Ministerio 999 x 150-01.png" style="height: 40px;" onerror="this.style.display='none'">
        <div class="text-center">
            <h6 class="m-0 font-weight-bold" style="color: #521f29;">ESCUELA INTERNACIONAL "NORA CASTAÑEDA"</h6>
            <small class="font-weight-bold text-muted">MARACAY, EDO. ARAGUA</small>
        </div>
        <img src="../../assets/img/LogoEscuelaNora_NUEVO.PNG" style="height: 45px;" onerror="this.style.display='none'">
    </div>

    <div class="text-center mb-4">
        <h5 class="font-weight-bold text-uppercase" style="color: #521f29;"><?= htmlspecialchars($malla['nombre_programa']) ?></h5>
        <span class="badge badge-warning text-dark font-weight-bold px-3 py-1"><?= htmlspecialchars($malla['tipo_oferta']) ?><?= $malla['requiere_pernocta'] ? ' - Con Pernocta' : '' ?></span>
    </div>

    <table class="table table-bordered align-middle">
        <thead style="background-color: #521f29; color: #fff;" class="text-center">
            <tr>
                <th style="width: 10%;">Módulo</th>
                <th style="width: 35%;">Materia / Unidad Curricular</th>
                <th style="width: 45%;">Contenidos Principales</th>
                <th style="width: 10%;">Horas</th>
            </tr>
        </thead>
        <tbody>
            <?php $total_h = 0; foreach ($materias as $mat): $total_h += $mat['horas_academicas']; ?>
                <tr>
                    <td class="text-center font-weight-bold"><?= $mat['modulo_num'] ?></td>
                    <td class="font-weight-bold" style="color: #521f29;"><?= htmlspecialchars($mat['nombre_materia']) ?></td>
                    <td><small><?= nl2br(htmlspecialchars($mat['contenido_detallado'])) ?></small></td>
                    <td class="text-center font-weight-bold"><?= $mat['horas_academicas'] ?> hrs</td>
                </tr>
            <?php endforeach; ?>
            <tr class="bg-light font-weight-bold">
                <td colspan="3" class="text-right">TOTAL HORAS ACADÉMICAS DEL PROGRAMA:</td>
                <td class="text-center" style="color: #521f29;"><?= $total_h ?> hrs</td>
            </tr>
        </tbody>
    </table>
</div>

</body>
</html>