<?php
// /sistema_nora/modulos/comedor/imprimir_comensales.php
require_once '../../config/db.php';

// Control de acceso: exclusivo de Comedor / Administración
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$roles_cm = $_SESSION['roles_asignados'] ?? [$_SESSION['tipo_usuario'] ?? ''];
if (empty($_SESSION['id_usuario'])
    || !(in_array('superadmin', $roles_cm) || in_array('admin', $roles_cm)
         || in_array('comedor', $roles_cm) || in_array('control_estudio', $roles_cm))) {
    http_response_code(403);
    die("Acceso restringido a Comedor.");
}

// Filtro de fecha (Por defecto el día de hoy)
$fecha_reporte = $_GET['fecha'] ?? date('Y-m-d');

try {
    // Consulta agrupada por tipo de ingesta (Desayuno, Almuerzo, Merienda, Cena)
    $sql = "SELECT 
                tipo_ingesta,
                menu_ofrecido,
                COUNT(id_marcaje) as total_comensales,
                MIN(fecha_hora) as hora_inicio,
                MAX(fecha_hora) as hora_fin
            FROM comedor_asistencia
            WHERE DATE(fecha_hora) = :fecha
            GROUP BY tipo_ingesta, menu_ofrecido
            ORDER BY hora_inicio ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':fecha' => $fecha_reporte]);
    $resumen_ingestas = $stmt->fetchAll();

    // Total general de comensales en el día
    $sql_total = "SELECT COUNT(id_marcaje) FROM comedor_asistencia WHERE DATE(fecha_hora) = :fecha";
    $stmt_total = $pdo->prepare($sql_total);
    $stmt_total->execute([':fecha' => $fecha_reporte]);
    $grand_total = $stmt_total->fetchColumn();

} catch (Exception $e) {
    $resumen_ingestas = [];
    $grand_total = 0;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reporte de Comensales por Ingesta - Escuela Nora Castañeda</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.net/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    
    <style>
        body {
            background-color: #f8f9fa;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: #333;
        }

        .hoja-impresion {
            background: #fff;
            max-width: 900px;
            margin: 20px auto;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }

        .encabezado-logo {
            border-bottom: 3px solid #ff8a00;
            padding-bottom: 15px;
            margin-bottom: 20px;
        }

        .titulo-reporte {
            color: #521f29;
            font-weight: bold;
            text-transform: uppercase;
            font-size: 16pt;
        }

        .tabla-comensales th {
            background-color: #521f29 !important;
            color: #ffffff !important;
            text-align: center;
        }

        /* Reglas exclusivas para cuando se presiona Imprimir / PDF */
        @media print {
            .no-imprimir {
                display: none !important;
            }
            body {
                background-color: #fff;
            }
            .hoja-impresion {
                box-shadow: none;
                margin: 0;
                padding: 0;
                width: 100%;
                max-width: 100%;
            }
            @page {
                size: letter portrait;
                margin: 1.5cm;
            }
        }
    </style>
</head>
<body>

<!-- Panel de Filtros y Botón de Impresión (Se oculta al imprimir) -->
<div class="container mt-4 no-imprimir">
    <div class="row align-items-center mb-3">
        <div class="col-md-6">
            <form method="GET" action="imprimir_comensales.php" class="form-inline">
                <label class="font-weight-bold mr-2" style="color: #521f29;">Seleccionar Fecha:</label>
                <input type="date" name="fecha" class="form-control mr-2 font-weight-bold" value="<?= htmlspecialchars($fecha_reporte) ?>">
                <button type="submit" class="btn btn-sm text-white font-weight-bold" style="background-color: #521f29;">
                    <i class="fas fa-search mr-1"></i> Consultar
                </button>
            </form>
        </div>
        <div class="col-md-6 text-right">
            <button onclick="window.print()" class="btn btn-danger font-weight-bold shadow-sm">
                <i class="fas fa-print mr-1"></i> IMPRIMIR / DESCARGAR PDF
            </button>
            <a href="reportes.php" class="btn btn-secondary font-weight-bold shadow-sm ml-2">
                <i class="fas fa-arrow-left mr-1"></i> Volver
            </a>
        </div>
    </div>
</div>

<!-- HOJA FORMATO IMPRESO -->
<div class="hoja-impresion">
    
    <!-- Encabezado con Logotipos Oficiales -->
    <div class="row align-items-center encabezado-logo">
        <div class="col-3 text-left">
            <img src="../../assets/img/minmujer_logo.png" alt="MinMujer" style="max-height: 55px;" onerror="this.style.display='none'">
        </div>
        <div class="col-6 text-center">
            <h6 class="m-0 font-weight-bold" style="color: #521f29;">ESCUELA INTERNACIONAL DE LIDERAZGO DE LA MUJER</h6>
            <h5 class="m-0 font-weight-bold" style="color: #ff8a00;">"NORA CASTAÑEDA"</h5>
            <small class="text-muted font-weight-bold">SISTEMA DE CONTROL DE COMEDOR E INVENTARIO</small>
        </div>
        <div class="col-3 text-right">
            <img src="../../assets/img/LogoEscuelaNora_NUEVO.PNG" alt="Logo Escuela" style="max-height: 65px; width: auto;" onerror="this.style.display='none'">
        </div>
    </div>

    <!-- Título y Datos del Reporte -->
    <div class="row mb-3 align-items-end">
        <div class="col-8">
            <div class="titulo-reporte">
                <i class="fas fa-clipboard-list mr-2"></i>Reporte Diario de Comensales por Ingesta
            </div>
            <span class="badge badge-warning text-dark font-weight-bold px-3 py-1 mt-1" style="font-size: 10pt;">
                FECHA: <?= date('d/m/Y', strtotime($fecha_reporte)) ?>
            </span>
        </div>
        <div class="col-4 text-right">
            <small class="text-muted font-weight-bold">
                Generado: <?= date('d/m/Y h:i A') ?><br>
                Usuario: <?= htmlspecialchars($_SESSION['usuario'] ?? 'Administrador') ?>
            </small>
        </div>
    </div>

    <!-- Tabla de Ingestas y Cantidad de Comensales -->
    <div class="table-responsive mt-3">
        <table class="table table-bordered table-striped align-middle tabla-comensales">
            <thead>
                <tr>
                    <th style="width: 20%;">INGESTA</th>
                    <th style="width: 45%;">MENÚ SERVIDO / PREPARACIÓN</th>
                    <th style="width: 15%;">HORARIOS</th>
                    <th style="width: 20%;" class="text-center">TOTAL COMENSALES</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($resumen_ingestas)): ?>
                    <tr>
                        <td colspan="4" class="text-center text-muted py-4">
                            <em>No se registraron atenciones de comensales para la fecha seleccionada.</em>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($resumen_ingestas as $ing): ?>
                        <tr>
                            <td class="font-weight-bold text-uppercase" style="color: #521f29; font-size: 11pt;">
                                <?php
                                    $icono = 'fa-utensils';
                                    if (stripos($ing['tipo_ingesta'], 'desayuno') !== false) $icono = 'fa-coffee';
                                    elseif (stripos($ing['tipo_ingesta'], 'almuerzo') !== false) $icono = 'fa-hamburger';
                                    elseif (stripos($ing['tipo_ingesta'], 'merienda') !== false) $icono = 'fa-apple-alt';
                                    elseif (stripos($ing['tipo_ingesta'], 'cena') !== false) $icono = 'fa-moon';
                                ?>
                                <i class="fas <?= $icono ?> text-warning mr-1"></i>
                                <?= htmlspecialchars($ing['tipo_ingesta']) ?>
                            </td>
                            <td class="font-weight-bold">
                                <?= !empty($ing['menu_ofrecido']) ? htmlspecialchars($ing['menu_ofrecido']) : 'Menú Estándar de Comedor' ?>
                            </td>
                            <td class="small text-muted font-weight-bold">
                                <?= date('h:i A', strtotime($ing['hora_inicio'])) ?> - <?= date('h:i A', strtotime($ing['hora_fin'])) ?>
                            </td>
                            <td class="text-center font-weight-bold text-primary" style="font-size: 14pt;">
                                <?= number_format($ing['total_comensales'], 0) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr style="background-color: #f2f2f2;">
                    <td colspan="3" class="text-right font-weight-bold text-uppercase" style="color: #521f29; font-size: 12pt;">
                        TOTAL ATENCIONES DEL DÍA:
                    </td>
                    <td class="text-center font-weight-bold text-success" style="font-size: 16pt; background-color: #e8f5e9;">
                        <?= number_format($grand_total, 0) ?>
                    </td>
                </tr>
            </tfoot>
        </table>
    </div>

    <!-- Firmas e Impresión Oficial -->
    <div class="row mt-5 pt-4 text-center">
        <div class="col-6">
            <div style="border-top: 1px solid #333; width: 80%; margin: 0 auto; padding-top: 5px;">
                <strong style="color: #521f29;">Responsable de Cocina / Chef</strong><br>
                <small class="text-muted">Firma y Sello</small>
            </div>
        </div>
        <div class="col-6">
            <div style="border-top: 1px solid #333; width: 80%; margin: 0 auto; padding-top: 5px;">
                <strong style="color: #521f29;">Coordinación de Comedor</strong><br>
                <small class="text-muted">Escuela Internacional Nora Castañeda</small>
            </div>
        </div>
    </div>

</div>

</body>
</html>