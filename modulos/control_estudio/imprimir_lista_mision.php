<?php
// /sistema_nora/modulos/control_estudio/imprimir_lista_mision.php
session_start();
require_once '../../config/db.php';

// Control de acceso: exclusivo de Control de Estudio / Administración
$roles_lm = $_SESSION['roles_asignados'] ?? [$_SESSION['tipo_usuario'] ?? ''];
if (empty($_SESSION['id_usuario'])
    || !(in_array('superadmin', $roles_lm) || in_array('admin', $roles_lm) || in_array('control_estudio', $roles_lm))) {
    http_response_code(403);
    die("Acceso restringido a Control de Estudio.");
}

$id_prestamo = intval($_GET['id_prestamo'] ?? 0);

// Consultar datos del préstamo de misión
$stmt_p = $pdo->prepare("SELECT * FROM public.prestamo_misiones WHERE id_prestamo = :id");
$stmt_p->execute([':id' => $id_prestamo]);
$mision = $stmt_p->fetch();

if (!$mision) {
    die("Registro no encontrado.");
}

// Consultar lista de estudiantes censados
$sql_est = "SELECT e.*, etn.nombre as etnia_nombre, disc.nombre as discapacidad_nombre 
            FROM public.misiones_estudiantes e 
            LEFT JOIN public.etnia etn ON e.id_etnia = etn.id
            LEFT JOIN public.discapacidad disc ON e.id_discapacidad = disc.id
            WHERE e.id_prestamo = :id ORDER BY e.apellido ASC";
$stmt_est = $pdo->prepare($sql_est);
$stmt_est->execute([':id' => $id_prestamo]);
$estudiantes = $stmt_est->fetchAll();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reporte de Misión Educativa - <?= htmlspecialchars($mision['nombre_mision']) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <style>
        body { background: #fff; font-family: 'Segoe UI', Tahoma, sans-serif; color: #333; }
        .hoja-impresion { max-width: 950px; margin: 15px auto; padding: 25px; }
        .encabezado-logo { border-bottom: 3px solid #ff8a00; padding-bottom: 12px; margin-bottom: 15px; }
        .tabla-mision th { background-color: #521f29 !important; color: #fff !important; text-align: center; font-size: 9pt; }
        .tabla-mision td { font-size: 9pt; }
        @media print {
            .no-imprimir { display: none !important; }
            .hoja-impresion { margin: 0; width: 100%; max-width: 100%; padding: 0; }
            @page { size: letter portrait; margin: 1.2cm; }
        }
    </style>
</head>
<body>

<div class="container text-right mt-3 no-imprimir">
    <button onclick="window.print()" class="btn btn-danger font-weight-bold shadow">
        <i class="fas fa-print mr-1"></i> IMPRIMIR / DESCARGAR PDF
    </button>
</div>

<div class="hoja-impresion">
    
    <!-- MEMBRETE CON LOGOTIPOS OFICIALES -->
    <div class="row align-items-center encabezado-logo">
        <div class="col-3 text-left">
            <img src="../../assets/img/Logo del Ministerio 999 x 150-01.png" style="max-height: 45px; max-width: 100%;" onerror="this.style.display='none'">
        </div>
        <div class="col-6 text-center">
            <h6 class="m-0 font-weight-bold" style="color: #521f29;">ESCUELA INTERNACIONAL DE LIDERAZGO DE LA MUJER</h6>
            <h5 class="m-0 font-weight-bold" style="color: #ff8a00;">"NORA CASTAÑEDA"</h5>
            <small class="text-muted font-weight-bold">CONTROL DE AULAS Y MISIONES EDUCATIVAS</small>
        </div>
        <div class="col-3 text-right">
            <img src="../../assets/img/LogoEscuelaNora_NUEVO.PNG" style="max-height: 55px; max-width: 100%;" onerror="this.style.display='none'">
        </div>
    </div>

    <!-- FICHA TÉCNICA DEL GRUPO Y FACILITADOR -->
    <div class="p-3 mb-3 rounded" style="border: 2px solid #521f29; background-color: #fdfaf6;">
        <div class="row">
            <div class="col-8">
                <h5 class="font-weight-bold mb-1" style="color: #521f29;">LISTADO OFICIAL: <?= htmlspecialchars($mision['nombre_mision']) ?></h5>
                <small class="font-weight-bold text-dark">PERÍODO: <?= htmlspecialchars($mision['periodo_academico']) ?> | AULA: <?= htmlspecialchars($mision['salon_asignado']) ?></small>
            </div>
            <div class="col-4 text-right">
                <span class="badge badge-warning text-dark font-weight-bold px-3 py-1" style="font-size: 10pt;">
                    FECHA: <?= date('d/m/Y') ?>
                </span>
            </div>
        </div>
        <hr class="my-2" style="border-top: 1px dashed #ff8a00;">
        <div class="row small font-weight-bold">
            <div class="col-6">
                FACILITADOR: <span class="text-uppercase" style="color: #521f29;"><?= htmlspecialchars($mision['facilitador_responsable']) ?></span><br>
                CÉDULA: V-<?= htmlspecialchars($mision['cedula_facilitador']) ?> | TELÉFONO: <?= htmlspecialchars($mision['telefono_facilitador']) ?>
            </div>
            <div class="col-6 text-right">
                HORARIO DE USO: <?= htmlspecialchars($mision['dias_horario']) ?><br>
                VIGENCIA: <?= date('d/m/Y', strtotime($mision['fecha_inicio'])) ?> al <?= date('d/m/Y', strtotime($mision['fecha_fin'])) ?>
            </div>
        </div>
    </div>

    <!-- TABLA CONSOLIDADA DE ESTUDIANTES -->
    <table class="table table-bordered table-striped align-middle tabla-mision">
        <thead>
            <tr>
                <th style="width: 5%;">N°</th>
                <th style="width: 12%;">CÉDULA</th>
                <th style="width: 28%;">NOMBRES Y APELLIDOS</th>
                <th style="width: 12%;">EDAD / F. NAC</th>
                <th style="width: 13%;">ESTADO CIVIL</th>
                <th style="width: 15%;">TELÉFONO</th>
                <th style="width: 15%;">INCLUSIÓN SOCIAL</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($estudiantes)): ?>
                <tr>
                    <td colspan="7" class="text-center text-muted py-3">No hay estudiantes censados en esta lista.</td>
                </tr>
            <?php else: ?>
                <?php $idx = 1; foreach ($estudiantes as $e): ?>
                    <tr>
                        <td class="text-center font-weight-bold"><?= $idx++ ?></td>
                        <td class="font-weight-bold">V-<?= htmlspecialchars($e['cedula']) ?></td>
                        <td class="font-weight-bold text-uppercase" style="color: #521f29;"><?= htmlspecialchars($e['nombre'] . ' ' . $e['apellido']) ?></td>
                        <td class="text-center">
                            <?= $e['edad'] ? "{$e['edad']} Años" : 'S/D' ?><br>
                            <small class="text-muted"><?= $e['fecha_nacimiento'] ? date('d/m/Y', strtotime($e['fecha_nacimiento'])) : '' ?></small>
                        </td>
                        <td class="text-center"><?= htmlspecialchars($e['estado_civil']) ?></td>
                        <td class="text-center"><?= htmlspecialchars($e['telefono']) ?></td>
                        <td>
                            <small class="d-block">Etnia: <?= htmlspecialchars($e['etnia_nombre'] ?? 'Ninguna') ?></small>
                            <small class="d-block">Disc: <?= htmlspecialchars($e['discapacidad_nombre'] ?? 'Ninguna') ?></small>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- FIRMAS DE CONFORMIDAD -->
    <div class="row mt-5 pt-3 text-center">
        <div class="col-6">
            <div style="border-top: 1px solid #333; width: 75%; margin: 0 auto; padding-top: 4px;">
                <strong style="color: #521f29; font-size: 9pt;">Facilitador Responsable de Misión</strong><br>
                <small class="text-muted">Firma y Sello</small>
            </div>
        </div>
        <div class="col-6">
            <div style="border-top: 1px solid #333; width: 75%; margin: 0 auto; padding-top: 4px;">
                <strong style="color: #521f29; font-size: 9pt;">Coordinación de Control de Estudio</strong><br>
                <small class="text-muted">Escuela Internacional Nora Castañeda</small>
            </div>
        </div>
    </div>

</div>

</body>
</html>