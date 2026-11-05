<?php
// /sistema_nora/modulos/defensoria/imprimir_expediente.php
require_once '../../config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Control de acceso: exclusivo de Defensoría / Administración
$roles_de = $_SESSION['roles_asignados'] ?? [$_SESSION['tipo_usuario'] ?? ''];
if (empty($_SESSION['id_usuario'])
    || !(in_array('superadmin', $roles_de) || in_array('admin', $roles_de) || in_array('defensoria', $roles_de))) {
    http_response_code(403);
    die("Acceso restringido a Defensoría.");
}

$id_caso = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id_caso === 0) {
    die("Expediente no válido.");
}

// Consultar datos del expediente y usuario que atiende
$stmt = $pdo->prepare("SELECT c.*, 
                              u.nombre as defensora_nombre, 
                              u.apellido as defensora_apellido, 
                              u.cedula as defensora_cedula 
                       FROM defensoria_casos c 
                       LEFT JOIN usuarios u ON c.id_defensora = u.id_usuario 
                       WHERE c.id_caso = :id LIMIT 1");
$stmt->execute([':id' => $id_caso]);
$caso = $stmt->fetch();

if (!$caso) {
    die("El expediente solicitado no existe.");
}

// Consultar historial de actuaciones/seguimientos
$stmt_seg = $pdo->prepare("SELECT s.*, u.nombre, u.apellido, u.cedula 
                           FROM defensoria_seguimiento s 
                           LEFT JOIN usuarios u ON s.id_responsable = u.id_usuario 
                           WHERE s.id_caso = :id 
                           ORDER BY s.id_seguimiento ASC");
$stmt_seg->execute([':id' => $id_caso]);
$seguimientos = $stmt_seg->fetchAll();

// Datos del Servidor Público / Defensor
$defensora_nombre = trim(($caso['defensora_nombre'] ?? $_SESSION['nombre'] ?? 'KERVYN ROBERTO AGUILAR MONSALVE') . ' ' . ($caso['defensora_apellido'] ?? ''));
$defensora_cedula = $caso['defensora_cedula'] ?? $_SESSION['cedula'] ?? '12345678';

// CONVERSIÓN DE LA IMAGEN DE FONDO A BASE64 PARA IMPRESIÓN GARANTIZADA
$path_img = '../../assets/img/defensoria.png';
$bg_base64 = '';

if (file_exists($path_img)) {
    $type = pathinfo($path_img, PATHINFO_EXTENSION);
    $data = file_get_contents($path_img);
    $bg_base64 = 'data:image/' . $type . ';base64,' . base64_encode($data);
} else {
    // Intento con ruta JPG por si la extensión varía en el disco
    $path_img_jpg = '../../assets/img/defensoria.jpg';
    if (file_exists($path_img_jpg)) {
        $data = file_get_contents($path_img_jpg);
        $bg_base64 = 'data:image/jpeg;base64,' . base64_encode($data);
    }
}

// Fecha en formato texto
$meses = ["01"=>"Enero","02"=>"Febrero","03"=>"Marzo","04"=>"Abril","05"=>"Mayo","06"=>"Junio","07"=>"Julio","08"=>"Agosto","09"=>"Septiembre","10"=>"Octubre","11"=>"Noviembre","12"=>"Diciembre"];
$dia_f = date('d', strtotime($caso['fecha_registro']));
$mes_f = $meses[date('m', strtotime($caso['fecha_registro']))] ?? date('m', strtotime($caso['fecha_registro']));
$anio_f = date('Y', strtotime($caso['fecha_registro']));
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>EXPEDIENTE_LEGAL_<?= htmlspecialchars($caso['codigo_expediente']) ?></title>
    <style>
        /* Configuración Hoja Oficio (8.5in x 13in / 215.9mm x 330.2mm) */
        @page {
            size: 8.5in 13in;
            margin: 0;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 0;
            font-family: "Times New Roman", Times, serif;
            font-size: 11pt;
            color: #000000;
            background-color: #f2f2f2;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }

        /* Barra de Control Discreta */
        .bar-control {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            background: #ffffff;
            border-bottom: 2px solid #521f29;
            padding: 8px 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            z-index: 9999;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }
        .bar-info {
            font-family: Arial, sans-serif;
            font-size: 10pt;
            color: #521f29;
            font-weight: bold;
        }
        .btn-imprimir-mini {
            background-color: #521f29;
            color: #ffffff;
            border: none;
            padding: 6px 16px;
            font-family: Arial, sans-serif;
            font-size: 9pt;
            font-weight: bold;
            border-radius: 4px;
            cursor: pointer;
            transition: background 0.2s;
        }
        .btn-imprimir-mini:hover {
            background-color: #ff8a00;
            color: #521f29;
        }

        /* Hoja de Oficio con Fondo Base64 Directo */
        .hoja-oficio {
            width: 8.5in;
            min-height: 13in;
            margin: 55px auto 30px auto;
            background: #ffffff <?php if (!empty($bg_base64)): ?>url('<?= $bg_base64 ?>')<?php endif; ?> no-repeat center top;
            background-size: 100% 100%;
            position: relative;
            padding: 1.8in 0.8in 1in 0.8in;
            box-shadow: 0 0 12px rgba(0,0,0,0.2);
        }

        @media print {
            .bar-control {
                display: none !important;
            }
            body {
                background: none;
            }
            .hoja-oficio {
                margin: 0;
                width: 100%;
                height: 100%;
                box-shadow: none;
                page-break-after: always;
                background-size: 100% 100% !important;
            }
        }

        .expediente-header {
            text-align: right;
            font-family: Arial, sans-serif;
            font-size: 10pt;
            font-weight: bold;
            color: #521f29;
            margin-bottom: 10px;
        }

        .encabezado-doc {
            text-align: center;
            font-weight: bold;
            text-transform: uppercase;
            font-size: 12.5pt;
            margin-bottom: 18px;
            color: #521f29;
            border-bottom: 2px solid #521f29;
            padding-bottom: 4px;
        }

        p.redaccion-legal {
            text-align: justify;
            line-height: 1.45;
            text-indent: 25px;
            margin-bottom: 12px;
        }

        .tabla-legal {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 12px;
        }
        .tabla-legal th, .tabla-legal td {
            border: 1px solid #521f29;
            padding: 5px 8px;
            font-size: 10pt;
        }
        .tabla-legal th {
            background-color: rgba(82, 31, 41, 0.08);
            color: #521f29;
            text-align: left;
            text-transform: uppercase;
            font-family: Arial, sans-serif;
            font-size: 9pt;
        }

        .seccion-titulo {
            font-family: Arial, sans-serif;
            font-weight: bold;
            font-size: 10pt;
            color: #521f29;
            text-transform: uppercase;
            margin-top: 14px;
            margin-bottom: 6px;
            border-bottom: 1px solid #521f29;
        }

        /* Firmas y Sello */
        .contenedor-firmas {
            margin-top: 45px;
            width: 100%;
            page-break-inside: avoid;
        }
        .bloque-firma {
            width: 46%;
            float: left;
            text-align: center;
            font-size: 9.5pt;
            font-family: Arial, sans-serif;
        }
        .bloque-sello {
            width: 46%;
            float: right;
            height: 120px;
            border: 2px dashed #521f29;
            border-radius: 8px;
            text-align: center;
            padding-top: 40px;
            color: #777777;
            font-weight: bold;
            font-size: 9pt;
            font-family: Arial, sans-serif;
        }
        .linea-firma {
            border-top: 1px solid #000000;
            margin-top: 55px;
            margin-bottom: 4px;
            width: 85%;
            margin-left: auto;
            margin-right: auto;
        }
        .clear {
            clear: both;
        }
    </style>
</head>
<body>

    <!-- Barra de Control Discreta -->
    <div class="bar-control">
        <div class="bar-info">
            📄 Expediente de Atención Defensorial: <strong><?= htmlspecialchars($caso['codigo_expediente']) ?></strong>
        </div>
        <div>
            <button onclick="window.print();" class="btn-imprimir-mini">
                🖨️ Imprimir / Guardar PDF
            </button>
        </div>
    </div>

    <!-- Documento con Fondo Base64 Incrustado -->
    <div class="hoja-oficio">

        <div class="expediente-header">
            EXPEDIENTE N°: <?= htmlspecialchars($caso['codigo_expediente']) ?>
        </div>

        <div class="encabezado-doc">
            ACTA OFICIAL DE RECEPCIÓN Y ATENCIÓN DE DEFENSORÍA
        </div>

        <p class="redaccion-legal">
            En la ciudad de Caracas, a los <strong><?= $dia_f ?></strong> días del mes de <strong><?= $mes_f ?></strong> del año <strong><?= $anio_f ?></strong>, comparece por ante la Defensoría de la <strong>Escuela Internacional de Liderazgo de la Mujer "Nora Castañeda"</strong>, la ciudadana infraescrita que se detalla a continuación:
        </p>

        <!-- I. IDENTIFICACIÓN -->
        <div class="seccion-titulo">I. Identificación de la Persona Atendida</div>
        <table class="tabla-legal">
            <tr>
                <th width="32%">Nombres y Apellidos:</th>
                <td width="68%"><strong><?= htmlspecialchars($caso['nombre_victima']) ?></strong></td>
            </tr>
            <tr>
                <th>Cédula de Identidad:</th>
                <td>V-<?= htmlspecialchars($caso['cedula_victimaria_afectada']) ?></td>
            </tr>
            <tr>
                <th>Teléfono de Contacto:</th>
                <td><?= htmlspecialchars($caso['telefono_victima'] ?? 'No suministrado') ?></td>
            </tr>
            <tr>
                <th>Dirección de Habitación:</th>
                <td><?= htmlspecialchars($caso['direccion_victima'] ?? 'No especificada') ?></td>
            </tr>
            <tr>
                <th>Tipo de Violencia / Causa:</th>
                <td><strong><?= htmlspecialchars($caso['tipo_violencia']) ?></strong></td>
            </tr>
        </table>

        <!-- II. HECHOS -->
        <div class="seccion-titulo">II. Relación Sucinta de los Hechos</div>
        <p class="redaccion-legal" style="text-indent: 0; text-align: justify; border: 1px solid #521f29; padding: 8px 12px; background: rgba(255,255,255,0.85); border-radius: 4px; min-height: 60px;">
            <?= nl2br(htmlspecialchars($caso['descripcion_hechos'])) ?>
        </p>

        <!-- III. ACTUACIONES -->
        <div class="seccion-titulo">III. Actuaciones y Acuerdos de Seguimiento</div>
        <?php if (empty($seguimientos)): ?>
            <p style="font-size: 9.5pt; font-style: italic; color: #555;">A la fecha no se registran actuaciones posteriores en el expediente.</p>
        <?php else: ?>
            <table class="tabla-legal">
                <thead>
                    <tr>
                        <th width="22%">Fecha / Hora</th>
                        <th width="43%">Observaciones de la Sesión</th>
                        <th width="35%">Acuerdos y Compromisos</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($seguimientos as $s): ?>
                        <tr>
                            <td><?= date('d/m/Y h:i A', strtotime($s['fecha_atencion'])) ?></td>
                            <td><?= htmlspecialchars($s['observacion']) ?></td>
                            <td><?= htmlspecialchars($s['acuerdos_compromisos'] ?? 'Sin acuerdos') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <!-- IV. FIRMAS Y SELLO LEGAL -->
        <div class="contenedor-firmas">
            
            <div class="bloque-firma">
                <div class="linea-firma"></div>
                <strong><?= htmlspecialchars($defensora_nombre) ?></strong><br>
                <span>Defensor(a) / Servidor(a) Público(a) Responsable</span><br>
                <span>C.I. N°: V-<?= htmlspecialchars($defensora_cedula) ?></span><br>
                <span>N° IMPRE / Registro: _________________</span>
            </div>

            <div class="bloque-sello">
                SELLO OFICIAL HÚMEDO<br>DE LA INSTITUCIÓN
            </div>

            <div class="clear"></div>

            <div style="margin-top: 35px; text-align: center;" class="bloque-firma" style="float: none; margin-left: auto; margin-right: auto;">
                <div class="linea-firma" style="width: 70%;"></div>
                <strong><?= htmlspecialchars($caso['nombre_victima']) ?></strong><br>
                <span>Firma de la Persona Solicitante / Afectada</span><br>
                <span>C.I. N°: V-<?= htmlspecialchars($caso['cedula_victimaria_afectada']) ?></span>
            </div>
        </div>

    </div>

</body>
</html>