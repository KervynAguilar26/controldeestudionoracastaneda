<?php
// /sistema_nora/modulos/reportes/reporte_estadistico_general.php
require_once '../../config/db.php';
require_once '../../includes/header.php';

if (!tiene_permiso(['superadmin', 'admin', 'control_estudio'], $roles_array)) {
    echo "<div class='alert alert-danger font-weight-bold'>Acceso restringido a Control de Estudio y Administradores.</div>";
    require_once '../../includes/footer.php';
    exit();
}

// 1. RECOLECCIÓN DE MÉTRICAS ESTADÍSTICAS GENERALES DE LA BD PRINCIPAL
$m = [
    'total_usuarios'     => 0,
    'estudiantes'        => 0,
    'profesores'         => 0,
    'personal_general'   => 0,
    'cohortes_activas'   => 0,
    'mallas_diseñadas'   => 0,
    'total_asistencias'  => 0,
    'asistencias_hoy'    => 0,
    'etnias_total'       => 0,
    'discapacidad_total' => 0
];

try {
    // Usuarios por Roles Principales
    $m['total_usuarios']   =$pdo->query("SELECT COUNT(*) FROM public.usuarios WHERE estatus_cuenta = 'Activo'")->fetchColumn();
    $m['estudiantes']      =$pdo->query("SELECT COUNT(*) FROM public.usuarios WHERE 'estudiante' = ANY(roles_asignados) AND estatus_cuenta = 'Activo'")->fetchColumn();
    $m['profesores']       =$pdo->query("SELECT COUNT(*) FROM public.usuarios WHERE 'profesor' = ANY(roles_asignados) AND estatus_cuenta = 'Activo'")->fetchColumn();
    $m['personal_general'] =$pdo->query("SELECT COUNT(*) FROM public.usuarios WHERE tipo_usuario NOT IN ('estudiante', 'profesor') AND estatus_cuenta = 'Activo'")->fetchColumn();

    // Mallas y Cohortes
    $m['mallas_diseñadas'] = $pdo->query("SELECT COUNT(*) FROM public.mallas_curriculares")->fetchColumn();
    $m['cohortes_activas'] =$pdo->query("SELECT COUNT(*) FROM public.cohortes WHERE estatus IN ('Planificada', 'En Curso')")->fetchColumn();

    // Asistencias Registradas
    $m['total_asistencias'] =$pdo->query("SELECT COUNT(*) FROM public.asistencia_clases WHERE estatus = 'Presente'")->fetchColumn();
    $m['asistencias_hoy']   =$pdo->query("SELECT COUNT(*) FROM public.asistencia_clases WHERE fecha_clase = CURRENT_DATE AND estatus = 'Presente'")->fetchColumn();

    // Caracterización Social Interna
    $m['etnias_total']       =$pdo->query("SELECT COUNT(*) FROM public.usuarios WHERE etnia IS NOT NULL AND etnia NOT IN ('', 'Ninguna', 'No Aplica')")->fetchColumn();
    $m['discapacidad_total'] =$pdo->query("SELECT COUNT(*) FROM public.usuarios WHERE tipo_discapacidad IS NOT NULL AND tipo_discapacidad NOT IN ('', 'Ninguna', 'No Aplica')")->fetchColumn();

} catch (Exception $e) {}

// 2. MÉTRICAS DEL PORTAL WEB (SOLICITUDES Y CURSOS)
$m_web = [
    'cursos_publicados' => 0,
    'total_aspirantes'  => 0,
    'web_etnias'        => 0,
    'web_discapacidades'=> 0
];

try {
    if ($pdo_web) {
        $m_web['cursos_publicados']  =$pdo_web->query("SELECT COUNT(*) FROM public.cursos WHERE publicado = true")->fetchColumn();
        $m_web['total_aspirantes']   =$pdo_web->query("SELECT COUNT(*) FROM public.inscripciones")->fetchColumn();
        $m_web['web_etnias']         =$pdo_web->query("SELECT COUNT(*) FROM public.inscripciones WHERE etnia_id IS NOT NULL")->fetchColumn();
        $m_web['web_discapacidades'] =$pdo_web->query("SELECT COUNT(*) FROM public.inscripciones WHERE discapacidad_id IS NOT NULL")->fetchColumn();
    }
} catch (Exception $e) {}

// 3. DESGLOSE DE CARGOS Y PERSONAL REGISTRADO
$desglose_cargos = [];
try {
    $desglose_cargos =$pdo->query("SELECT cargo_funcion, COUNT(*) as cantidad 
                                    FROM public.usuarios 
                                    WHERE estatus_cuenta = 'Activo' 
                                    GROUP BY cargo_funcion 
                                    ORDER BY cantidad DESC")->fetchAll();
} catch (Exception $e) {}

// 4. CARGAR AUTORIDADES ACTIVAS PARA EL MEMBRETE Y PIE DE PÁGINA
$directora = null;
$control_estudio_jefa = null;

try {
    $directora =$pdo->query("SELECT * FROM public.configuracion_autoridades WHERE cargo = 'Directora de la Escuela' AND estatus = 'Activo' LIMIT 1")->fetch();
    $control_estudio_jefa =$pdo->query("SELECT * FROM public.configuracion_autoridades WHERE cargo = 'Director de Control de Estudio' AND estatus = 'Activo' LIMIT 1")->fetch();
} catch (Exception $e) {}

// Folio y Fecha del Sistema
$folio_reporte = "REP-STAT-" . date('Ymd-His');
$fecha_hoy_formateada = date('d/m/Y');$hora_actual = date('h:i A');
?>

<!-- BOTONERA SUPERIOR DE ACCIÓN (SE OCULTA EN LA IMPRESIÓN/PDF) -->
<div class="d-sm-flex align-items-center justify-content-between mb-4 no-print">
    <h1 class="h3 mb-0 font-weight-bold" style="color: #521f29;">
        <i class="fas fa-chart-line mr-2 text-warning"></i>Reporte Estadístico Consolidado
    </h1>
    <div>
        <button onclick="window.print()" class="btn font-weight-bold text-white shadow-sm mr-1" style="background-color: #521f29;">
            <i class="fas fa-file-pdf mr-1 text-warning"></i> IMPRIMIR / DESCARGAR PDF
        </button>
        <a href="../control_estudio/index.php" class="btn btn-secondary font-weight-bold shadow-sm">
            <i class="fas fa-arrow-left mr-1"></i> Volver a Control de Estudio
        </a>
    </div>
</div>

<!-- CONTENEDOR TIPO HOJA IMPRIMIBLE CON LOGOS Y MEMBRETE -->
<div class="card shadow border-0 p-4 p-md-5 bg-white sheet-print" style="border-radius: 15px; max-width: 1000px; margin: 0 auto;">

    <!-- ENCABEZADO MEMBRETE OFICIAL -->
    <div class="d-flex justify-content-between align-items-center pb-3 border-bottom mb-4">
        <img src="../../assets/img/Logo del Ministerio 999 x 150-01.png" style="height: 48px;" alt="MinMujer" onerror="this.style.display='none'">
        <div class="text-center px-2">
            <h6 class="m-0 font-weight-bold text-uppercase" style="color: #521f29; font-size: 11pt;">República Bolivariana de Venezuela</h6>
            <small class="font-weight-bold text-muted d-block" style="font-size: 8.5pt;">Ministerio del Poder Popular para la Mujer y la Igualdad de Género</small>
            <h5 class="m-0 font-weight-bold text-uppercase mt-1" style="color: #ff8a00; font-size: 12pt;">Escuela Internacional de Liderazgo de la Mujer "Nora Castañeda"</h5>
            <small class="font-weight-bold text-dark d-block" style="font-size: 8pt;">Sede Principal Bello Monte, Maracay Edo. Aragua</small>
        </div>
        <img src="../../assets/img/icono.png" style="height: 52px;" alt="Logo Nora" onerror="this.style.display='none'">
    </div>

    <!-- TÍTULO DEL INFORME Y FECHA DÍA -->
    <div class="d-flex justify-content-between align-items-end mb-4">
        <div>
            <h4 class="font-weight-bold text-uppercase m-0" style="color: #521f29; text-decoration: underline;">INFORME GENERAL DE MÉTRICAS Y ESTADÍSTICAS</h4>
            <small class="font-weight-bold text-muted"><i class="fas fa-barcode mr-1"></i>Folio Oficial: <?= $folio_reporte ?></small>
        </div>
        <div class="text-right">
            <span class="badge badge-light border font-weight-bold p-2 text-dark">
                <i class="fas fa-calendar-alt mr-1 text-danger"></i>Fecha Emisión: <strong><?= $fecha_hoy_formateada ?></strong> (<?= $hora_actual ?>)
            </span>
        </div>
    </div>

    <!-- SECCIÓN 1: RESUMEN GENERAL DE MATRÍCULA Y COMUNIDAD EDUCATIVA -->
    <div class="mb-4">
        <h6 class="font-weight-bold text-uppercase p-2 text-white rounded" style="background-color: #521f29;">
            1. Consolidado General de Comunidad Educativa e Infraestructura
        </h6>
        <div class="row text-center mt-3">
            <div class="col-3 mb-2">
                <div class="p-3 border rounded bg-light">
                    <small class="font-weight-bold d-block text-muted">Estudiantes Activos</small>
                    <h3 class="font-weight-bold m-0" style="color: #521f29;"><?= number_format($m['estudiantes']) ?></h3>
                </div>
            </div>
            <div class="col-3 mb-2">
                <div class="p-3 border rounded bg-light">
                    <small class="font-weight-bold d-block text-muted">Docentes / Facilitadores</small>
                    <h3 class="font-weight-bold m-0" style="color: #ff8a00;"><?= number_format($m['profesores']) ?></h3>
                </div>
            </div>
            <div class="col-3 mb-2">
                <div class="p-3 border rounded bg-light">
                    <small class="font-weight-bold d-block text-muted">Mallas / Programas</small>
                    <h3 class="font-weight-bold m-0" style="color: #521f29;"><?= number_format($m['mallas_diseñadas']) ?></h3>
                </div>
            </div>
            <div class="col-3 mb-2">
                <div class="p-3 border rounded bg-light">
                    <small class="font-weight-bold d-block text-muted">Cohortes en Curso</small>
                    <h3 class="font-weight-bold m-0 text-success"><?= number_format($m['cohortes_activas']) ?></h3>
                </div>
            </div>
        </div>
    </div>

    <!-- SECCIÓN 2: INTEGRACIÓN WEB Y SOLICITUDES EN LISTA DE ESPERA -->
    <div class="mb-4">
        <h6 class="font-weight-bold text-uppercase p-2 text-white rounded" style="background-color: #521f29;">
            2. Métricas del Portal Web e Integración Académica (`Nora_castaneda`)
        </h6>
        <div class="table-responsive mt-2">
            <table class="table table-bordered align-middle mb-0">
                <thead class="bg-light text-dark small font-weight-bold text-center">
                    <tr>
                        <th>Cursos Publicados en Web</th>
                        <th>Aspirantes en Lista de Espera</th>
                        <th>Preinscritos con Etnia Indígena</th>
                        <th>Preinscritos con Discapacidad</th>
                    </tr>
                </thead>
                <tbody class="text-center font-weight-bold">
                    <tr>
                        <td class="text-primary"><?= number_format($m_web['cursos_publicados']) ?> Ofertas</td>
                        <td class="text-danger"><?= number_format($m_web['total_aspirantes']) ?> Aspirantes</td>
                        <td><?= number_format($m_web['web_etnias']) ?> Registros</td>
                        <td><?= number_format($m_web['web_discapacidades']) ?> Registros</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- SECCIÓN 3: CARACTERIZACIÓN SOCIAL Y ASISTENCIA -->
    <div class="mb-4">
        <h6 class="font-weight-bold text-uppercase p-2 text-white rounded" style="background-color: #521f29;">
            3. Caracterización Social y Registro de Asistencias
        </h6>
        <div class="row">
            <div class="col-md-6 mb-2">
                <div class="p-3 border rounded bg-light">
                    <h6 class="font-weight-bold small text-muted">Inclusión y Población Vulnerable (Sistema Interno)</h6>
                    <div class="d-flex justify-content-between align-items-center mt-2">
                        <span>Población Indígena / Etnia:</span>
                        <strong class="h5 m-0" style="color: #521f29;"><?= number_format($m['etnias_total']) ?></strong>
                    </div>
                    <hr class="my-2">
                    <div class="d-flex justify-content-between align-items-center">
                        <span>Personas con Discapacidad:</span>
                        <strong class="h5 m-0 text-success"><?= number_format($m['discapacidad_total']) ?></strong>
                    </div>
                </div>
            </div>

            <div class="col-md-6 mb-2">
                <div class="p-3 border rounded bg-light">
                    <h6 class="font-weight-bold small text-muted">Control de Asistencia Digital (Lector QR / Comedor / Aulas)</h6>
                    <div class="d-flex justify-content-between align-items-center mt-2">
                        <span>Total Asistencias Históricas:</span>
                        <strong class="h5 m-0 text-primary"><?= number_format($m['total_asistencias']) ?></strong>
                    </div>
                    <hr class="my-2">
                    <div class="d-flex justify-content-between align-items-center">
                        <span>Asistencias Registradas Hoy:</span>
                        <strong class="h5 m-0" style="color: #ff8a00;"><?= number_format($m['asistencias_hoy']) ?></strong>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- SECCIÓN 4: DISTRIBUCIÓN DEL PERSONAL Y ROLES INSTITUCIONALES -->
    <div class="mb-4">
        <h6 class="font-weight-bold text-uppercase p-2 text-white rounded" style="background-color: #521f29;">
            4. Distribución por Cargos y Funciones del Personal
        </h6>
        <div class="table-responsive mt-2">
            <table class="table table-sm table-striped table-bordered align-middle mb-0">
                <thead style="background-color: #ff8a00; color: #521f29;" class="small font-weight-bold">
                    <tr>
                        <th>Cargo / Función Registrada</th>
                        <th class="text-center" style="width: 30%;">Total Personal Activo</th>
                    </tr>
                </thead>
                <tbody class="small font-weight-bold">
                    <?php if (empty($desglose_cargos)): ?>
                        <tr><td colspan="2" class="text-center py-2 text-muted">Sin datos de personal registrados.</td></tr>
                    <?php else: ?>
                        <?php foreach ($desglose_cargos as$dc): ?>
                            <tr>
                                <td><?= htmlspecialchars($dc['cargo_funcion'] ?? 'Sin Asignar') ?></td>
                                <td class="text-center" style="color: #521f29;"><?= number_format($dc['cantidad']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- FIRMAS DIGITALES Y AVAL DE AUTORIDADES -->
    <div class="row align-items-end mt-5 pt-4 text-center">
        <!-- DIRECTORA -->
        <div class="col-6">
            <?php if (!empty($directora['firma_digital']) && file_exists("../../" . $directora['firma_digital'])): ?>
                <img src="../../<?= $directora['firma_digital'] ?>" style="max-height: 60px;" class="d-block mx-auto mb-1">
            <?php endif; ?>
            <div style="border-top: 1px solid #333; width: 80%; margin: 0 auto; padding-top: 5px;">
                <strong class="d-block text-uppercase" style="font-size: 9pt; color: #521f29;"><?= htmlspecialchars($directora['nombre_apellido'] ?? 'Directora General') ?></strong>
                <small class="text-muted d-block" style="font-size: 8pt;">Directora General de la Escuela</small>
                <small class="text-muted" style="font-size: 7.5pt;"><?= htmlspecialchars($directora['tipo_nombramiento'] ?? '') ?> N° <?= htmlspecialchars($directora['numero_nombramiento'] ?? '') ?></small>
            </div>
        </div>

        <!-- CONTROL DE ESTUDIO -->
        <div class="col-6">
            <?php if (!empty($control_estudio_jefa['firma_digital']) && file_exists("../../" . $control_estudio_jefa['firma_digital'])): ?>
                <img src="../../<?= $control_estudio_jefa['firma_digital'] ?>" style="max-height: 60px;" class="d-block mx-auto mb-1">
            <?php endif; ?>
            <div style="border-top: 1px solid #333; width: 80%; margin: 0 auto; padding-top: 5px;">
                <strong class="d-block text-uppercase" style="font-size: 9pt; color: #521f29;"><?= htmlspecialchars($control_estudio_jefa['nombre_apellido'] ?? 'Director de Control de Estudio') ?></strong>
                <small class="text-muted d-block" style="font-size: 8pt;">Director de Control de Estudio</small>
                <small class="text-muted" style="font-size: 7.5pt;"><?= htmlspecialchars($control_estudio_jefa['tipo_nombramiento'] ?? '') ?> N° <?= htmlspecialchars($control_estudio_jefa['numero_nombramiento'] ?? '') ?></small>
            </div>
        </div>
    </div>

</div>

<!-- ESTILOS EXCLUSIVOS DE IMPRESIÓN Y CONVERSIÓN A PDF -->
<style>
@media print {
    .no-print, nav, footer, sidebar, .navbar { display: none !important; }
    body { background: #ffffff !important; padding: 0 !important; margin: 0 !important; }
    .sheet-print { border: none !important; shadow: none !important; padding: 0 !important; max-width: 100% !important; }
    .card { border: none !important; box-shadow: none !important; }
    @page { margin: 1.5cm; size: A4 portrait; }
}
</style>

<?php require_once '../../includes/footer.php'; ?>