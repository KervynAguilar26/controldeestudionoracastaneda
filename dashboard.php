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
                    Bienvenido al panel de administración general. Desde el menú lateral desplegable podrás acceder a los módulos de <strong>Control de Estudio</strong>, <strong>Comedor</strong>, <strong>Defensoría</strong>, <strong>Guardería</strong> y la <strong>Gestión de Usuarios</strong>.
                </p>
            </div>
        </div>
    </div>
</div>

<?php endif; // fin del portal diferenciado por rol ?>

<?php require_once 'includes/footer.php'; ?>