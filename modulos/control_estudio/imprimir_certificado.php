<?php
// /sistema_nora/modulos/control_estudio/imprimir_certificado.php
session_start();
require_once '../../config/db.php';

// Control de acceso: exclusivo de Control de Estudio / Administración
$roles_ic = $_SESSION['roles_asignados'] ?? [$_SESSION['tipo_usuario'] ?? ''];
if (empty($_SESSION['id_usuario'])
    || !(in_array('superadmin', $roles_ic) || in_array('admin', $roles_ic) || in_array('control_estudio', $roles_ic))) {
    http_response_code(403);
    die("Acceso restringido a Control de Estudio.");
}

$id_inscripcion = intval($_GET['id'] ?? 0);

try {
    // Consulta detallada del estudiante, programa, notas y folio.
    // Nota: inscripciones.id_curso es FK de cohortes(id_cohorte) (nombre de columna heredado);
    // los datos académicos se resuelven vía cohorte -> malla curricular.
    $sql = "SELECT i.*, 
                   u.nombre, u.apellido, u.cedula,
                   m.nombre_programa AS nombre_curso,
                   m.tipo_oferta,
                   COALESCE((SELECT SUM(hm.horas_academicas)
                               FROM public.malla_materias hm
                              WHERE hm.id_malla = m.id_malla), 0) AS horas_academicas,
                   co.codigo_cohorte
            FROM public.inscripciones i
            JOIN public.usuarios u ON i.id_estudiante = u.id_usuario
            JOIN public.cohortes co ON i.id_curso = co.id_cohorte
            JOIN public.mallas_curriculares m ON co.id_malla = m.id_malla
            WHERE i.id_inscripcion = :id LIMIT 1";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $id_inscripcion]);
    $cert = $stmt->fetch();

    if (!$cert) {
        die("Certificado no encontrado o inscripción no válida.");
    }

    // Generar Folio si no existe
    $folio = $cert['folio_certificado'] ?? ("FOL-" . date('Y') . "-" . str_pad($cert['id_inscripcion'], 5, '0', STR_PAD_LEFT));

} catch (Exception $e) {
    die("Error al cargar el certificado: " . $e->getMessage());
}

// URL de validación del código QR
$url_validacion = "http://" . $_SERVER['HTTP_HOST'] . "/sistema_nora/validar_certificado.php?folio=" . urlencode($folio);
$qr_api = "https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=" . urlencode($url_validacion) . "&color=521f29";
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Certificado - <?= htmlspecialchars($cert['nombre'] . ' ' . $cert['apellido']) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <style>
        body {
            background-color: #f0f0f0;
            font-family: 'Georgia', serif;
        }
        .certificado-border {
            width: 1000px;
            height: 700px;
            margin: 20px auto;
            padding: 40px;
            background: #ffffff;
            border: 12px double #521f29;
            position: relative;
            box-shadow: 0 0 15px rgba(0,0,0,0.2);
        }
        .marco-interno {
            border: 2px solid #ff8a00;
            height: 100%;
            padding: 30px;
            position: relative;
        }
        .titulo-certificado {
            color: #521f29;
            font-size: 28pt;
            font-weight: bold;
            letter-spacing: 2px;
            text-transform: uppercase;
        }
        .nombre-estudiante {
            color: #ff8a00;
            font-size: 24pt;
            font-weight: bold;
            border-bottom: 2px solid #521f29;
            display: inline-block;
            padding-bottom: 5px;
            margin: 15px 0;
        }
        @media print {
            .no-imprimir { display: none !important; }
            body { background: #fff; }
            .certificado-border { box-shadow: none; margin: 0; width: 100%; height: 100%; }
            @page { size: landscape; margin: 0; }
        }
    </style>
</head>
<body>

<div class="container text-center mt-3 no-imprimir">
    <button onclick="window.print()" class="btn btn-danger font-weight-bold shadow">
        <i class="fas fa-print mr-2"></i> IMPRIMIR / DESCARGAR CERTIFICADO (PDF)
    </button>
    <a href="index.php" class="btn btn-secondary font-weight-bold ml-2">Volver</a>
</div>

<div class="certificado-border">
    <div class="marco-interno text-center">
        
        <!-- Encabezado con Logos -->
        <div class="d-flex justify-content-between align-items-center mb-3">
            <img src="../../assets/img/minmujer_logo.png" style="height: 50px;" onerror="this.style.display='none'">
            <div>
                <h6 class="m-0 font-weight-bold" style="color: #521f29;">REPÚBLICA BOLIVARIANA DE VENEZUELA</h6>
                <small class="font-weight-bold text-muted">MINISTERIO DEL PODER POPULAR PARA LA MUJER Y LA IGUALDAD DE GÉNERO</small>
            </div>
            <img src="../../assets/img/LogoEscuelaNora_NUEVO.PNG" style="height: 55px;" onerror="this.style.display='none'">
        </div>

        <div class="my-2">
            <small class="font-weight-bold text-uppercase" style="letter-spacing: 3px; color: #521f29;">Escuela Internacional de Liderazgo de la Mujer "Nora Castañeda"</small>
            <div class="titulo-certificado mt-2">CERTIFICADO DE APROBACIÓN</div>
            <p class="text-muted italic mb-1">Otorgado a la ciudadana:</p>
        </div>

        <!-- Nombre del Titular -->
        <div class="nombre-estudiante">
            <?= htmlspecialchars($cert['nombre'] . ' ' . $cert['apellido']) ?>
        </div>
        <p class="font-weight-bold text-dark m-0" style="font-size: 13pt;">Cédula de Identidad: V-<?= htmlspecialchars($cert['cedula']) ?></p>

        <!-- Detalle de Aprobación -->
        <p class="mt-3 px-4" style="font-size: 11pt; color: #333; line-height: 1.6;">
            Por haber cumplido y aprobado satisfactoriamente los requisitos académicos exigidos en el programa formativo:<br>
            <strong style="color: #521f29; font-size: 14pt;"><?= htmlspecialchars($cert['nombre_curso']) ?></strong><br>
            Con una intensidad horaria de <strong><?= $cert['horas_academicas'] ?> Horas Académicas</strong>. Evaluado como: <strong><?= htmlspecialchars($cert['nota_cualitativa']) ?></strong>.
        </p>

        <!-- Firmas y Código QR de Validación -->
        <div class="row align-items-end mt-4 pt-2">
            <div class="col-4 text-center">
                <div style="border-top: 1px solid #521f29; width: 80%; margin: 0 auto;">
                    <strong style="color: #521f29; font-size: 9pt;">Directora General</strong><br>
                    <small class="text-muted">Escuela Nora Castañeda</small>
                </div>
            </div>
            
            <!-- QR Código de Verificación en BD -->
            <div class="col-4 text-center">
                <img src="<?= $qr_api ?>" style="width: 85px; height: 85px; border: 1px solid #ddd; padding: 3px; background: #fff;">
                <div class="small font-weight-bold mt-1" style="color: #521f29; font-size: 8pt;"><?= $folio ?></div>
            </div>

            <div class="col-4 text-center">
                <div style="border-top: 1px solid #521f29; width: 80%; margin: 0 auto;">
                    <strong style="color: #521f29; font-size: 9pt;">Coordinación Académica</strong><br>
                    <small class="text-muted">Control de Estudio</small>
                </div>
            </div>
        </div>

    </div>
</div>

</body>
</html>