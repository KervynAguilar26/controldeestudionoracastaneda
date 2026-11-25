<?php
// /sistema_nora/dashboard.php
require_once 'config/db.php';
require_once 'includes/header.php';

// Consultas seguras a PostgreSQL con manejo de excepciones
try {
    $total_usuarios = $pdo->query("SELECT COUNT(*) FROM usuarios")->fetchColumn();
} catch (Exception $e) {
    $total_usuarios = 0;
}

try {
    // Las cohortes son las instancias de curso reales del sistema (la tabla
    // legada "cursos" está vacía); se cuentan las que aún no han finalizado.
    $total_cursos = $pdo->query("SELECT COUNT(*) FROM cohortes WHERE estatus IN ('Planificada', 'En Curso')")->fetchColumn();
} catch (Exception $e) {
    $total_cursos = 0;
}

try {
    $asistencia_hoy = $pdo->query("SELECT COUNT(*) FROM comedor_asistencia WHERE fecha_registro = CURRENT_DATE")->fetchColumn();
} catch (Exception $e) {
    $asistencia_hoy = 0;
}

try {
    // 'Cerrado' es el único estatus de caso finalizado (CHECK: En Proceso/Cerrado/Remitido)
    $casos_abiertos = $pdo->query("SELECT COUNT(*) FROM defensoria_casos WHERE estatus_caso IN ('En Proceso', 'Remitido')")->fetchColumn();
} catch (Exception $e) {
    $casos_abiertos = 0;
}

try {
    $visitas_hoy = $pdo->query("SELECT COUNT(*) FROM recepcion_visitas WHERE fecha_entrada = CURRENT_DATE")->fetchColumn();
    $alertas_qr_pendientes = $pdo->query("SELECT COUNT(*) FROM recepcion_alertas_qr WHERE atendida = false AND fecha_lectura = CURRENT_DATE")->fetchColumn();
} catch (Exception $e) {
    $visitas_hoy = 0;
    $alertas_qr_pendientes = 0;
}

// Cátedras pendientes de aceptar (solo para el docente autenticado).
$pendientes_docente = 0;
if (in_array('profesor', $roles_array, true)) {
    try {
        $st_p = $pdo->prepare("SELECT COUNT(*) FROM public.asignaciones_profesor
                                WHERE id_profesor = :p AND estatus_aceptacion = 'Pendiente'");
        $st_p->execute([':p' => $_SESSION['id_usuario']]);
        $pendientes_docente = (int) $st_p->fetchColumn();
    } catch (Exception $e) {
        $pendientes_docente = 0;
    }
}

// ¿Es un Monitor de Campo? (rol de ronda 5: solo comedor + infraestructura)
$es_monitor = in_array('monitor', $roles_array) && !tiene_permiso(['superadmin', 'admin'], $roles_array);
?>

<?php if ($es_monitor): ?>
<!-- ══════════ PORTAL DE MONITOR DE CAMPO (ronda 5) ══════════ -->
<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <h1 class="h3 mb-0 font-weight-bold" style="color: #521f29;">
        <i class="fas fa-hard-hat mr-2 text-warning"></i>Hola, <?= htmlspecialchars($_SESSION['nombre'] ?? 'Monitor') ?>
    </h1>
    <span class="badge px-3 py-2 text-white font-weight-bold" style="background-color: #521f29;">
        <?= date('d/m/Y') ?>
    </span>
</div>

<div class="alert alert-light border shadow-sm font-weight-bold" style="color: #521f29; border-left: 5px solid #ff8a00 !important;">
    <i class="fas fa-info-circle mr-1 text-warning"></i>
    Estos dos portales funcionan <strong>sin internet</strong>: abra la página al menos una vez con conexión,
    y podrá registrar asistencia e infraestructura en cualquier momento. Los datos se sincronizan solos al reconectarse.
</div>

<div class="row">
    <div class="col-md-6 mb-4">
        <a href="modulos/comedor/index.php" class="text-decoration-none">
            <div class="card shadow border-0 h-100" style="border-radius: 15px;">
                <div class="card-body text-center py-5 bg-white" style="border-bottom-left-radius: 15px; border-bottom-right-radius: 15px;">
                    <i class="fas fa-utensils fa-4x mb-3" style="color: #ff8a00;"></i>
                    <h4 class="font-weight-bold" style="color: #521f29;">Asistencia de Comedor</h4>
                    <p class="text-muted mb-2">Registro de comensales por <strong>cédula</strong> o <strong>escaneo QR</strong>.</p>
                    <span class="badge badge-dark px-3 py-2"><i class="fas fa-mobile-alt mr-1"></i> Funciona offline</span>
                </div>
            </div>
        </a>
    </div>
    <div class="col-md-6 mb-4">
        <a href="modulos/configuracion/infra_movil.php" class="text-decoration-none">
            <div class="card shadow border-0 h-100" style="border-radius: 15px;">
                <div class="card-body text-center py-5 bg-white" style="border-bottom-left-radius: 15px; border-bottom-right-radius: 15px;">
                    <i class="fas fa-building fa-4x mb-3" style="color: #ff8a00;"></i>
                    <h4 class="font-weight-bold" style="color: #521f29;">Infraestructura de la Escuela</h4>
                    <p class="text-muted mb-2">Registro de <strong>torres</strong>, <strong>espacios/dormitorios</strong> y reporte de <strong>estados</strong>.</p>
                    <span class="badge badge-dark px-3 py-2"><i class="fas fa-mobile-alt mr-1"></i> Funciona offline</span>
                </div>
            </div>
        </a>
    </div>
</div>

<?php elseif (in_array('profesor', $roles_array, true) && !tiene_permiso(['superadmin', 'admin'], $roles_array)): ?>
<!-- ══════════ PORTAL DESTACADO DEL DOCENTE ══════════ -->
<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <h1 class="h3 mb-0 font-weight-bold" style="color: #521f29;">
        <i class="fas fa-chalkboard-teacher mr-2 text-warning"></i>
        Hola, <?= htmlspecialchars($_SESSION['nombre'] ?? 'Docente') ?>
    </h1>
    <span class="badge px-3 py-2 text-white font-weight-bold" style="background-color: #521f29;">
        <?= date('d/m/Y') ?>
    </span>
</div>

<div class="alert alert-light border shadow-sm font-weight-bold mb-4" style="color: #521f29; border-left: 5px solid #ff8a00 !important;">
    <i class="fas fa-info-circle mr-1 text-warning"></i>
    Su horario ya no aparece de entrada: presione el botón <strong>Ver mi horario</strong> cuando desee consultarlo.
</div>

<div class="row">
    <div class="col-md-4 mb-4">
        <a href="modulos/profesor/index.php#mi-horario" class="text-decoration-none">
            <div class="card shadow border-0 h-100" style="border-radius: 15px;">
                <div class="card-body text-center py-5 bg-white" style="border-bottom-left-radius: 15px; border-bottom-right-radius: 15px;">
                    <i class="fas fa-calendar-week fa-4x mb-3" style="color: #ff8a00;"></i>
                    <h4 class="font-weight-bold" style="color: #521f29;">Mi Horario</h4>
                    <p class="text-muted mb-3">Despliegue u oculte su horario semanal con un solo botón.</p>
                    <span class="btn btn-warning text-dark font-weight-bold">
                        <i class="fas fa-eye mr-1"></i> Ver mi horario
                    </span>
                </div>
            </div>
        </a>
    </div>
    <div class="col-md-4 mb-4">
        <a href="modulos/profesor/index.php" class="text-decoration-none">
            <div class="card shadow border-0 h-100" style="border-radius: 15px;">
                <div class="card-body text-center py-5 bg-white" style="border-bottom-left-radius: 15px; border-bottom-right-radius: 15px;">
                    <i class="fas fa-chalkboard-teacher fa-4x mb-3" style="color: #ff8a00;"></i>
                    <h4 class="font-weight-bold" style="color: #521f29;">Portal Docente</h4>
                    <p class="text-muted mb-3">Asignaciones, secciones, asistencia, notas y evaluación.</p>
                    <span class="badge px-3 py-2 font-weight-bold" style="background-color: #521f29; color: #fff;">
                        Cátedras pendientes: <?= (int) $pendientes_docente ?>
                    </span>
                </div>
            </div>
        </a>
    </div>
    <div class="col-md-4 mb-4">
        <a href="modulos/foros/mis_foros.php" class="text-decoration-none">
            <div class="card shadow border-0 h-100" style="border-radius: 15px;">
                <div class="card-body text-center py-5 bg-white" style="border-bottom-left-radius: 15px; border-bottom-right-radius: 15px;">
                    <i class="fas fa-comments fa-4x mb-3" style="color: #ff8a00;"></i>
                    <h4 class="font-weight-bold" style="color: #521f29;">Mis Foros</h4>
                    <p class="text-muted mb-3">Foros, conversatorios y seminarios en los que participa como docente.</p>
                    <span class="btn btn-outline-primary font-weight-bold">
                        <i class="fas fa-arrow-right mr-1"></i> Abrir
                    </span>
                </div>
            </div>
        </a>
    </div>
</div>

<?php else: ?>

<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <h1 class="h3 mb-0 text-gray-800 font-weight-bold">Panel de Control</h1>
    <span class="badge px-3 py-2 text-white font-weight-bold" style="background-color: #521f29;">
        <?= date('d/m/Y') ?>
    </span>
</div>

<div class="row">

    <!-- Usuarios Activos -->
    <div class="col-xl-3 col-md-6 mb-4">
        <div class="card border-left-nora shadow h-100 py-2">
            <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-nora text-uppercase mb-1">Usuarios Registrados</div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $total_usuarios ?></div>
                    </div>
                    <div class="col-auto">
                        <i class="fas fa-users fa-2x text-gray-300"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Cursos Activos -->
    <div class="col-xl-3 col-md-6 mb-4">
        <div class="card border-left-success shadow h-100 py-2">
            <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Cursos Activos</div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $total_cursos ?></div>
                    </div>
                    <div class="col-auto">
                        <i class="fas fa-book-open fa-2x text-gray-300"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Comensales Hoy -->
    <div class="col-xl-3 col-md-6 mb-4">
        <div class="card border-left-info shadow h-100 py-2">
            <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Comedor (Hoy)</div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $asistencia_hoy ?></div>
                    </div>
                    <div class="col-auto">
                        <i class="fas fa-utensils fa-2x text-gray-300"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Defensoría -->
    <div class="col-xl-3 col-md-6 mb-4">
        <div class="card border-left-warning shadow h-100 py-2">
            <div class="card-body">
                <div class="row no-gutters align-items-center">
                    <div class="col mr-2">
                        <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Defensoría (Casos)</div>
                        <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $casos_abiertos ?></div>
                    </div>
                    <div class="col-auto">
                        <i class="fas fa-balance-scale fa-2x text-gray-300"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

<!-- Bienvenida al Sistema -->
<div class="row">
    <div class="col-lg-12">
        <div class="card shadow mb-4">
            <div class="card-header py-3" style="background-color: #521f29;">
                <h6 class="m-0 font-weight-bold text-white">Sistema de Gestión Institucional - Escuela Nora Castañeda</h6>
            </div>
            <div class="card-body">
                <p class="mb-0">
                    Bienvenido al panel de administración general. Desde el menú lateral desplegable podrás acceder a los módulos de <strong>Control de Estudio</strong>, <strong>Comedor</strong>, <strong>Defensoría</strong>, <strong>Guardería</strong>, <strong>Recepción y Visitantes</strong> y la <strong>Gestión de Usuarios</strong>.
                </p>
            </div>
        </div>
    </div>
</div>

<?php if (tiene_permiso(['superadmin', 'admin', 'recepcion'], $roles_array)): ?>
<div class="row">
    <div class="col-lg-12">
        <div class="card shadow mb-4" style="border-left: 5px solid #ff8a00 !important;">
            <div class="card-body d-flex flex-wrap align-items-center justify-content-between">
                <div>
                    <h5 class="font-weight-bold mb-1" style="color: #521f29;"><i class="fas fa-concierge-bell mr-2 text-warning"></i>Recepción y Visitantes</h5>
                    <p class="text-muted mb-0">
                        Visitas registradas hoy: <strong><?= (int)$visitas_hoy ?></strong> &nbsp;|&nbsp;
                        Alertas QR pendientes: <strong class="<?= $alertas_qr_pendientes > 0 ? 'text-danger' : 'text-success' ?>"><?= (int)$alertas_qr_pendientes ?></strong>
                    </p>
                </div>
                <a href="modulos/recepcion/index.php" class="btn font-weight-bold text-white mt-2 mt-md-0" style="background-color: #521f29;">
                    <i class="fas fa-arrow-right mr-1"></i> Abrir Recepción
                </a>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php endif; // fin del portal diferenciado por rol ?>

<?php require_once 'includes/footer.php'; ?>