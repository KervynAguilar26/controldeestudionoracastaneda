<?php
// /sistema_nora/modulos/estudiante/imprimir_boleta.php
// Boleta Oficial de Calificaciones con Nota Final Ponderada
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

// Cargar estudiante y curso
$sql = "SELECT i.*, u.nombre, u.apellido, u.cedula,
               co.codigo_cohorte, co.salon_aula, co.dias_clase, co.fecha_inicio,
               m.id_malla, m.nombre_programa, m.tipo_oferta
        FROM public.inscripciones i
        JOIN public.usuarios u ON i.id_estudiante = u.id_usuario
        JOIN public.cohortes co ON i.id_curso = co.id_cohorte
        JOIN public.mallas_curriculares m ON co.id_malla = m.id_malla
        WHERE i.id_inscripcion = :id LIMIT 1";

$stmt = $pdo->prepare($sql);
$stmt->execute([':id' => $id_inscripcion]);
$cert = $stmt->fetch();

if (!$cert) die("Boleta no encontrada.");

// Control de propiedad: solo el propio estudiante (o la administración) puede descargarla
if (!$es_admin && intval($cert['id_estudiante']) !== $id_session) {
    die("No tiene permiso para descargar esta boleta.");
}

// Cargar notas de módulos evaluados/aprobados
$sql_notas = "SELECT pe.titulo_evaluacion, pe.porcentaje_peso, pe.tipo_ponderacion,
                     ne.nota_cuantitativa, ne.nota_cualitativa, ne.nota_recuperacion
              FROM public.plan_evaluacion pe
              JOIN public.notas_estudiantes ne ON ne.id_plan = pe.id_plan
              WHERE pe.id_cohorte = :coh AND ne.id_estudiante = :est
              ORDER BY pe.id_plan ASC";

$stmt_n = $pdo->prepare($sql_notas);
$stmt_n->execute([':coh' => $cert['id_curso'], ':est' => $cert['id_estudiante']]);
$notas = $stmt_n->fetchAll();

// Calcular NOTA FINAL PONDERADA (0 a 20): suma de (nota definitiva × peso / 100)
$nota_final_ponderada = 0.0;
$suma_pesos_notas = 0.0;
foreach ($notas as $n) {
    $nota_item    = !empty($n['nota_recuperacion']) ? floatval($n['nota_recuperacion']) : floatval($n['nota_cuantitativa']);
    $peso_item    = floatval($n['porcentaje_peso']);
    $suma_pesos_notas += $peso_item;
    $nota_final_ponderada += ($nota_item * $peso_item) / 100;
}
$nota_final_ponderada = round($nota_final_ponderada, 2);
$aprobado_global = ($nota_final_ponderada >= 10 && !empty($notas));

// Cargar Autoridad: Jefa de Control de Estudio
$sql_j = "SELECT * FROM public.configuracion_autoridades WHERE cargo = 'Jefa de Control de Estudio' LIMIT 1";
$jefa = $pdo->query($sql_j)->fetch();

// Folio persistido (o generado por primera vez) para validación pública por QR
$folio = $cert['folio_certificado'];
if (empty($folio)) {
    $folio = "BOL-" . date('Y') . "-" . str_pad($cert['id_inscripcion'], 5, '0', STR_PAD_LEFT);
    try {
        $stmt_f = $pdo->prepare("UPDATE public.inscripciones SET folio_certificado = :fol WHERE id_inscripcion = :id AND folio_certificado IS NULL");
        $stmt_f->execute([':fol' => $folio, ':id' => $id_inscripcion]);
    } catch (Exception $e) { /* se conserva el folio generado sin persistir */ }
} elseif (preg_match('/^(CONST|CERT)-/', $folio, $mf)) {
    // El folio de la inscripción sirve para todas las boletas: se muestra con prefijo BOL-
    $folio = 'BOL' . substr($folio, strlen($mf[1]));
}

$qr_src = sistema_qr_data_uri(sistema_qr_url($folio), 'bol');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Boleta de Calificaciones - <?= htmlspecialchars($cert['nombre'] . ' ' . $cert['apellido']) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <style>
        body { background: #fff; font-family: 'Times New Roman', Times, serif; font-size: 11pt; }
        .hoja { max-width: 800px; margin: 20px auto; padding: 35px; border: 1px solid #ccc; }
        .membrete { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        .membrete td { vertical-align: middle; }
        .membrete .logo-min { max-height: 50px; max-width: 180px; }
        .membrete .logo-escuela { max-height: 60px; max-width: 74px; }
        .membrete .logo-gmvm { max-height: 46px; max-width: 90px; }
        .membrete .texto { text-align: center; }
        .membrete .texto .pais { font-size: 10pt; font-weight: bold; color: #521f29; }
        .membrete .texto .min { font-size: 8pt; font-weight: bold; color: #6b5b4d; }
        .membrete .texto .escuela { font-size: 11.5pt; font-weight: bold; color: #ff8a00; text-transform: uppercase; margin-top: 3px; }
        .membrete .texto .sede { font-size: 8pt; color: #6b5b4d; font-weight: bold; }
        .membrete-borde { border-bottom: 3px solid #ff8a00; }
        @page { size: letter portrait; margin: 15mm 16mm; }
        @media print { .no-print { display: none; } .hoja { border: none; margin: 0; padding: 0; width: 100%; } }
    </style>
</head>
<body>

<div class="text-right container mt-3 no-print">
    <button onclick="window.print()" class="btn btn-danger font-weight-bold"><i class="fas fa-print"></i> IMPRIMIR BOLETA (PDF)</button>
</div>

<div class="hoja">
    <!-- MEMBRETE INSTITUCIONAL CON LOGOS -->
    <table class="membrete membrete-borde">
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
                <img src="../../assets/img/LogoEscuelaNora_NUEVO.PNG" alt="Escuela Nora Castañeda" class="logo-escuela" onerror="this.style.display='none'">
            </td>
            <td width="12%" align="center">
                <img src="../../assets/img/GMVM.png" alt="GMVM" class="logo-gmvm" onerror="this.style.display='none'">
            </td>
        </tr>
    </table>

    <div class="text-center my-3">
        <h5 class="font-weight-bold text-uppercase" style="color: #521f29; text-decoration: underline;">BOLETÍN OFICIAL DE CALIFICACIONES</h5>
    </div>

    <div class="p-3 bg-light rounded border mb-3">
        <div class="row small font-weight-bold">
            <div class="col-7">
                ESTUDIANTE: <span class="text-uppercase" style="color: #521f29;"><?= htmlspecialchars($cert['nombre'] . ' ' . $cert['apellido']) ?></span><br>
                CÉDULA: V-<?= htmlspecialchars($cert['cedula']) ?>
            </div>
            <div class="col-5 text-right">
                PROGRAMA: <?= htmlspecialchars($cert['nombre_programa']) ?><br>
                COHORTE: <?= htmlspecialchars($cert['codigo_cohorte']) ?> | AULA: <?= htmlspecialchars($cert['salon_aula']) ?>
            </div>
        </div>
    </div>

    <!-- TABLA DE MÓDULOS Y NOTAS -->
    <table class="table table-bordered align-middle my-4">
        <thead style="background-color: #521f29; color: #fff;" class="text-center small">
            <tr>
                <th>Módulo / Evaluación</th>
                <th>Peso (%)</th>
                <th>Nota Obtenida</th>
                <th>Evaluación Cualitativa</th>
                <th>Nota Recuperación</th>
                <th>Estatus Módulo</th>
            </tr>
        </thead>
        <tbody class="small font-weight-bold text-center">
            <?php if (empty($notas)): ?>
                <tr><td colspan="6" class="text-muted py-3">No hay calificaciones publicadas para esta cohorte.</td></tr>
            <?php else: ?>
                <?php foreach ($notas as $n): 
                    $nota_final = !empty($n['nota_recuperacion']) ? $n['nota_recuperacion'] : $n['nota_cuantitativa'];
                    $aprobado = ($nota_final >= 10 || $n['nota_cualitativa'] === 'Aprobado' || $n['nota_cualitativa'] === 'Excelente');
                ?>
                    <tr>
                        <td class="text-left" style="color: #521f29;"><?= htmlspecialchars($n['titulo_evaluacion']) ?></td>
                        <td><?= $n['porcentaje_peso'] ?>%</td>
                        <td class="text-primary font-weight-bold"><?= $n['nota_cuantitativa'] ?? '--' ?></td>
                        <td><?= $n['nota_cualitativa'] ?? '--' ?></td>
                        <td class="text-danger font-weight-bold"><?= $n['nota_recuperacion'] ?? '--' ?></td>
                        <td>
                            <?php if ($aprobado): ?>
                                <span class="badge badge-success px-2 py-1">APROBADO</span>
                            <?php else: ?>
                                <span class="badge badge-danger px-2 py-1">REPROBADO</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <!-- NOTA FINAL PONDERADA -->
                <tr style="background-color: #fcf8f2;">
                    <td colspan="4" class="text-right text-uppercase font-weight-bold">Nota Final Ponderada (Escala 0 a 20):</td>
                    <td colspan="2" class="font-weight-bold" style="font-size: 13pt; color: #521f29;">
                        <?= number_format($nota_final_ponderada, 2) ?>
                        <?php if ($aprobado_global): ?>
                            <span class="badge badge-success px-2 py-1">APROBADO</span>
                        <?php else: ?>
                            <span class="badge badge-danger px-2 py-1">REPROBADO</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php if (abs($suma_pesos_notas - 100) >= 0.01): ?>
                    <tr>
                        <td colspan="6" class="text-center small text-danger font-weight-bold">
                            <i class="fas fa-exclamation-triangle mr-1"></i> El plan de evaluación suma solo <?= $suma_pesos_notas ?>% (debe totalizar 100%).
                        </td>
                    </tr>
                <?php endif; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- FIRMA OFICIAL DE CONTROL DE ESTUDIO -->
    <div class="row align-items-end mt-5 pt-3 text-center">
        <div class="col-8">
            <?php if (!empty($jefa['firma_digital'])): ?>
                <img src="../../<?= $jefa['firma_digital'] ?>" style="max-height: 65px;" class="d-block mx-auto mb-1" onerror="this.style.display='none'">
            <?php endif; ?>
            <div style="border-top: 1px solid #333; width: 85%; margin: 0 auto; padding-top: 5px;">
                <strong><?= htmlspecialchars($jefa['nombre_apellido'] ?? 'Jefa de Control de Estudio') ?></strong><br>
                <small class="text-muted d-block">Jefa de Control de Estudio</small>
                <small class="text-muted" style="font-size: 8pt;"><?= htmlspecialchars($jefa['tipo_nombramiento'] ?? '') ?> <?= htmlspecialchars($jefa['numero_nombramiento'] ?? '') ?></small>
            </div>
        </div>
        <div class="col-4">
            <?php if ($qr_src !== ''): ?>
                <img src="<?= $qr_src ?>" style="width: 100px; height: 100px; border: 1px solid #ccc; padding: 2px;">
            <?php endif; ?>
            <div class="small font-weight-bold mt-1" style="font-size: 8pt; color: #521f29;"><?= $folio ?></div>
        </div>
    </div>
</div>

</body>
</html>