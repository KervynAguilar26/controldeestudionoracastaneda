<?php
// /sistema_nora/modulos/estudiante/constancia_estudio.php
require_once '../../config/db.php';
require_once '../../includes/qr_generator.php';
session_start();

// Control de acceso: requiere sesión activa
if (empty($_SESSION['id_usuario'])) {
    header("Location: ../../index.php");
    exit();
}

$id_inscripcion = intval($_GET['id'] ?? 0);
$id_session     = intval($_SESSION['id_usuario'] ?? 0);
$roles_usuario  = $_SESSION['roles_asignados'] ?? [$_SESSION['tipo_usuario'] ?? ''];
$es_admin       = in_array('admin', $roles_usuario) || in_array('superadmin', $roles_usuario) || in_array('control_estudio', $roles_usuario);

// Cargar datos de la inscripción y estudiante
$sql = "SELECT i.*, u.nombre, u.apellido, u.cedula,
               co.codigo_cohorte, co.salon_aula, co.dias_clase, co.horas_diarias,
               m.nombre_programa, m.tipo_oferta
        FROM public.inscripciones i
        JOIN public.usuarios u ON i.id_estudiante = u.id_usuario
        JOIN public.cohortes co ON i.id_curso = co.id_cohorte
        JOIN public.mallas_curriculares m ON co.id_malla = m.id_malla
        WHERE i.id_inscripcion = :id LIMIT 1";

$stmt = $pdo->prepare($sql);
$stmt->execute([':id' => $id_inscripcion]);
$cert = $stmt->fetch();

if (!$cert) die("Constancia no encontrada.");

// Control de propiedad: solo el propio estudiante (o la administración) puede descargarla
if (!$es_admin && intval($cert['id_estudiante']) !== $id_session) {
    die("No tiene permiso para descargar esta constancia.");
}

// Cargar Autoridad: Jefa de Control de Estudio
$sql_j = "SELECT * FROM public.configuracion_autoridades WHERE cargo = 'Jefa de Control de Estudio' LIMIT 1";
$jefa = $pdo->query($sql_j)->fetch();

// Folio persistido (o generado por primera vez) para validación pública por QR
$folio = $cert['folio_certificado'];
if (empty($folio)) {
    $folio = "CONST-" . date('Y') . "-" . str_pad($cert['id_inscripcion'], 5, '0', STR_PAD_LEFT);
    try {
        $stmt_f = $pdo->prepare("UPDATE public.inscripciones SET folio_certificado = :fol WHERE id_inscripcion = :id AND folio_certificado IS NULL");
        $stmt_f->execute([':fol' => $folio, ':id' => $id_inscripcion]);
    } catch (Exception $e) { /* se conserva el folio generado sin persistir */ }
} elseif (preg_match('/^(BOL|CERT)-/', $folio, $mf)) {
    // El folio de la inscripción sirve para todas las constancias: se muestra con prefijo CONST-
    $folio = 'CONST' . substr($folio, strlen($mf[1]));
}

$url_val = sistema_qr_url($folio);
$qr_src  = sistema_qr_data_uri($url_val, 'const');

$meses_constancia = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
$fecha_constancia = date('d') . ' de ' . $meses_constancia[(int) date('n') - 1] . ' de ' . date('Y');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Constancia de Estudio - <?= htmlspecialchars($cert['nombre'] . ' ' . $cert['apellido']) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <style>
        body { background: #fff; font-family: 'Times New Roman', Times, serif; font-size: 12pt; line-height: 1.6; }
        .hoja { max-width: 800px; margin: 20px auto; padding: 40px; border: 1px solid #ccc; }
        .membrete { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        .membrete td { vertical-align: middle; }
        .membrete .logo-min { max-height: 52px; max-width: 190px; }
        .membrete .logo-escuela { max-height: 62px; max-width: 76px; }
        .membrete .logo-gmvm { max-height: 48px; max-width: 92px; }
        .membrete .texto { text-align: center; }
        .membrete .texto .pais { font-size: 10.5pt; font-weight: bold; color: #521f29; }
        .membrete .texto .min { font-size: 8.5pt; font-weight: bold; color: #6b5b4d; }
        .membrete .texto .escuela { font-size: 12pt; font-weight: bold; color: #ff8a00; text-transform: uppercase; margin-top: 3px; }
        .membrete .texto .sede { font-size: 8.5pt; color: #6b5b4d; font-weight: bold; }
        .membrete-legal { border-bottom: 3px solid #ff8a00; margin-bottom: 18px; }
        @page { size: letter portrait; margin: 16mm 18mm; }
        @media print {
            .no-print { display: none; }
            .hoja { border: none; margin: 0; padding: 0; width: 100%; }
        }
    </style>
</head>
<body>

<div class="text-right container mt-3 no-print">
    <button onclick="window.print()" class="btn btn-danger font-weight-bold"><i class="fas fa-print"></i> IMPRIMIR CONSTANCIA (PDF)</button>
</div>

<div class="hoja text-justify">
    
    <!-- MEMBRETE INSTITUCIONAL CON LOGOS Y SOBERANÍA -->
    <table class="membrete membrete-legal">
        <tr>
            <td width="24%" align="left">
                <img src="../../assets/img/Logo del Ministerio 999 x 150-01.png" alt="Ministerio" class="logo-min" onerror="this.style.display='none'">
            </td>
            <td width="52%" class="texto">
                <div class="pais">REPÚBLICA BOLIVARIANA DE VENEZUELA</div>
                <div class="min">MINISTERIO DEL PODER POPULAR PARA LA MUJER Y LA IGUALDAD DE GÉNERO</div>
                <div class="escuela">Escuela Internacional de Liderazgo de la Mujer "Nora Castañeda"</div>
                <div class="sede">Sede Principal Bello Monte, Maracay Edo. Aragua</div>
            </td>
            <td width="12%" align="center">
                <img src="../../assets/img/LogoEscuelaNora_NUEVO.png" alt="Escuela Nora Castañeda" class="logo-escuela" onerror="this.style.display='none'">
            </td>
            <td width="12%" align="center">
                <img src="../../assets/img/GMVM.png" alt="GMVM" class="logo-gmvm" onerror="this.style.display='none'">
            </td>
        </tr>
    </table>

    <div class="text-center my-4">
        <h4 class="font-weight-bold text-uppercase" style="color: #521f29; text-decoration: underline;">CONSTANCIA DE ESTUDIO</h4>
    </div>

    <p>
        Quien suscribe, <strong><?= htmlspecialchars($jefa['nombre_apellido'] ?? 'Jefa de Control de Estudio') ?></strong>, titular de la Cédula de Identidad N° <strong>V-<?= htmlspecialchars($jefa['cedula'] ?? '') ?></strong>, en su carácter de <strong>Jefa de la Unidad de Control de Estudio</strong> de la Escuela Internacional de Liderazgo de la Mujer "Nora Castañeda", designada mediante <strong><?= htmlspecialchars($jefa['tipo_nombramiento'] ?? '') ?> N° <?= htmlspecialchars($jefa['numero_nombramiento'] ?? '') ?></strong>, hace constar por medio de la presente que la ciudadana:
    </p>

    <div class="text-center my-3 p-3 bg-light rounded border">
        <h5 class="font-weight-bold m-0" style="color: #521f29;"><?= htmlspecialchars($cert['nombre'] . ' ' . $cert['apellido']) ?></h5>
        <strong class="text-dark">Cédula de Identidad: V-<?= htmlspecialchars($cert['cedula']) ?></strong>
    </div>

    <p>
        Se encuentra debidamente inscrita y cursando activamente el programa formativo de <strong><?= htmlspecialchars($cert['nombre_programa']) ?></strong> (<?= htmlspecialchars($cert['tipo_oferta']) ?>), en la cohorte <strong><?= htmlspecialchars($cert['codigo_cohorte']) ?></strong>, asistiendo al aula <strong><?= htmlspecialchars($cert['salon_aula']) ?></strong> en el horario programado de <strong><?= htmlspecialchars($cert['dias_clase']) ?></strong>.
    </p>

    <p>
        Constancia que se expide a petición de la parte interesada, en la ciudad de Maracay, Estado Aragua, a los <?= htmlspecialchars($fecha_constancia, ENT_QUOTES, 'UTF-8') ?>.
    </p>

    <!-- FIRMAS Y CÓDIGO QR -->
    <div class="row align-items-end mt-5 pt-4 text-center">
        <div class="col-8">
            <?php if (!empty($jefa['firma_digital'])): ?>
                <img src="../../<?= $jefa['firma_digital'] ?>" style="max-height: 70px;" class="d-block mx-auto mb-1" onerror="this.style.display='none'">
            <?php endif; ?>
            <div style="border-top: 1px solid #333; width: 85%; margin: 0 auto; padding-top: 5px;">
                <strong><?= htmlspecialchars($jefa['nombre_apellido'] ?? 'Jefa de Control de Estudio') ?></strong><br>
                <small class="text-muted d-block">Jefa de Control de Estudio</small>
                <small class="text-muted" style="font-size: 8pt;"><?= htmlspecialchars($jefa['tipo_nombramiento'] ?? '') ?> <?= htmlspecialchars($jefa['numero_nombramiento'] ?? '') ?></small>
            </div>
        </div>
        <div class="col-4">
            <?php if ($qr_src !== ''): ?>
                <img src="<?= $qr_src ?>" style="width: 110px; height: 110px; border: 1px solid #ccc; padding: 2px;">
            <?php endif; ?>
            <div class="small font-weight-bold mt-1" style="font-size: 8pt; color: #521f29;"><?= $folio ?></div>
        </div>
    </div>

</div>

</body>
</html>