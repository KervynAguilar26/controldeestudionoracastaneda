<?php
// /sistema_nora/includes/header.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Verificar si el usuario está logueado
if (!isset($_SESSION['id_usuario'])) {
    // Calcular ruta relativa al root
    $root_path = file_exists('index.php') ? '' : (file_exists('../index.php') ? '../' : '../../');
    header("Location: " . $root_path . "index.php");
    exit();
}

// Calcular prefijo de ruta dinámico según la profundidad del archivo
$path_prefix = file_exists('assets') ? '' : (file_exists('../assets') ? '../' : '../../');

// Ruta absoluta de la raíz de la aplicación (scope del Service Worker PWA)
$base_url = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
for ($i = 0; $i < substr_count($path_prefix, '../'); $i++) {
    $base_url = dirname($base_url);
}
$base_url = rtrim($base_url, '/') . '/';

// Roles del usuario actual
$rol_actual  = $_SESSION['tipo_usuario'] ?? 'trabajador';
$roles_array = $_SESSION['roles_asignados'] ?? [$rol_actual];

// Función para verificar permisos
if (!function_exists('tiene_permiso')) {
    function tiene_permiso($roles_permitidos, $roles_usuario) {
        if (in_array('superadmin', $roles_usuario) || in_array('admin', $roles_usuario)) {
            return true;
        }
        return !empty(array_intersect((array)$roles_permitidos, (array)$roles_usuario));
    }
}

// Obtener el Token CSRF de forma segura
$token_csrf_header = '';
if (function_exists('csrf_token')) {
    $token_csrf_header = csrf_token();
} else if (isset($_SESSION['csrf_token'])) {
    $token_csrf_header = $_SESSION['csrf_token'];
} else {
    // Si no existe, lo creamos para que el header no quede vacío
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $token_csrf_header = $_SESSION['csrf_token'];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no">
    <meta name="csrf-token" content="<?= htmlspecialchars($token_csrf_header, ENT_QUOTES) ?>">
    <title>Escuela Internacional Nora Castañeda</title>

    <!-- Favicon -->
    <link rel="icon" type="image/png" href="<?= $path_prefix ?>assets/img/icono.png">

    <!-- PWA: Manifest de aplicación -->
    <link rel="manifest" href="<?= $path_prefix ?>assets/manifest.json">
    <meta name="theme-color" content="#521f29">
    <meta name="sw-base" content="<?= htmlspecialchars($base_url, ENT_QUOTES) ?>">

    <!-- Fuentes y CSS CDN -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <link href="https://fonts.googleapis.com/css?family=Nunito:200,300,400,600,700,800,900" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/startbootstrap-sb-admin-2@4.1.4/css/sb-admin-2.min.css">

    <style>
        body, #content-wrapper {
            background-color: #d6c4af !important;
            color: #521f29 !important;
            font-family: 'Nunito', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }

        .bg-gradient-nora {
            background-color: #ff8a00 !important;
            background-image: linear-gradient(180deg, #ff8a00 10%, #ffa32b 100%) !important;
            background-size: cover;
        }

        .sidebar .nav-item .nav-link,
        .sidebar .sidebar-brand-text,
        .sidebar .sidebar-heading {
            color: #521f29 !important;
            font-weight: 700;
        }
        .sidebar .nav-item .nav-link i {
            color: #521f29 !important;
        }
        .sidebar-dark .sidebar-divider {
            border-top: 1px solid rgba(82, 31, 41, 0.2);
        }

        .topbar {
            background-color: #ffffff !important;
            border-bottom: 3px solid #ff8a00;
        }
        .topbar-logos img {
            max-height: 38px;
            width: auto;
            margin-right: 15px;
            object-fit: contain;
        }

        h1, h2, h3, h4, h5, h6, .text-gray-800, .text-gray-900 {
            color: #521f29 !important;
            font-weight: 800;
        }

        .border-left-nora {
            border-left: 0.25rem solid #ff8a00 !important;
        }
        .text-nora {
            color: #521f29 !important;
        }

        /* ══════════ RESPONSIVE MÓVIL ══════════ */
        @media (max-width: 767.98px) {
            .container-fluid { padding-left: 12px; padding-right: 12px; }
            h1.h3 { font-size: 1.25rem; }
            .table { font-size: 0.85rem; }
            .table th, .table td { padding: 0.45rem 0.4rem; }
            .btn { padding: 0.45rem 0.8rem; }
            .card .card-body { padding: 1rem; }
            .form-control { font-size: 16px; }
            .topbar { padding-left: 0.25rem; padding-right: 0.5rem; }
            .topbar .nav-item .nav-link { padding: 0.35rem 0.6rem; }
            .btn-block-mobile { width: 100%; margin-bottom: 0.5rem; }
        }
    </style>
</head>

<body id="page-top">
    <div id="wrapper">

        <!-- Sidebar -->
        <ul class="navbar-nav bg-gradient-nora sidebar sidebar-dark accordion" id="accordionSidebar">
            <a class="sidebar-brand d-flex align-items-center justify-content-center py-3" href="<?= $path_prefix ?>dashboard.php">
                <div class="sidebar-brand-icon">
                    <img src="<?= $path_prefix ?>assets/img/icono.png" alt="Logo" style="max-height: 42px;">
                </div>
                <div class="sidebar-brand-text mx-2 text-capitalize">Nora Castañeda</div>
            </a>

            <hr class="sidebar-divider my-0">

            <?php if (tiene_permiso(['superadmin', 'admin', 'control_estudio', 'guarderia', 'profesor', 'comedor', 'defensoria', 'monitor'], $roles_array)): ?>
                <li class="nav-item active">
                    <a class="nav-link" href="<?= $path_prefix ?>dashboard.php">
                        <i class="fas fa-fw fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
            <?php endif; ?>

            <li class="nav-item">
                <a class="nav-link" href="<?= $path_prefix ?>mi_perfil.php">
                    <i class="fas fa-fw fa-id-card"></i>
                    <span>Mi Perfil y Carnet</span>
                </a>
            </li>

            <hr class="sidebar-divider">
            <div class="sidebar-heading">Módulos</div>

            <li class="nav-item">
                <a class="nav-link collapsed" href="#" data-toggle="collapse" data-target="#collapseModulos" aria-expanded="true" aria-controls="collapseModulos">
                    <i class="fas fa-fw fa-folder"></i>
                    <span>Gestión Académica</span>
                </a>
                <div id="collapseModulos" class="collapse" data-parent="#accordionSidebar">
                    <div class="bg-white py-2 collapse-inner rounded">
                        
                        <?php if (tiene_permiso(['superadmin', 'admin', 'control_estudio'], $roles_array)): ?>
                            <a class="collapse-item font-weight-bold" style="color: #521f29;" href="<?= $path_prefix ?>modulos/control_estudio/index.php">Control de Estudio</a>
                        <?php endif; ?>

                        <?php if (tiene_permiso(['superadmin', 'comedor', 'monitor'], $roles_array)): ?>
                            <a class="collapse-item font-weight-bold" style="color: #521f29;" href="<?= $path_prefix ?>modulos/comedor/index.php">Comedor</a>
                            <a class="collapse-item font-weight-bold" style="color: #521f29;" href="<?= $path_prefix ?>modulos/comedor/asistencia_qr.php">Escáner QR Comedor</a>
                        <?php endif; ?>

                        <?php if (tiene_permiso(['monitor'], $roles_array)): ?>
                            <a class="collapse-item font-weight-bold" style="color: #521f29;" href="<?= $path_prefix ?>modulos/configuracion/infra_movil.php">Registro de Infraestructura</a>
                        <?php endif; ?>

                        <?php if (tiene_permiso(['superadmin', 'defensoria'], $roles_array)): ?>
                            <a class="collapse-item font-weight-bold" style="color: #521f29;" href="<?= $path_prefix ?>modulos/defensoria/index.php">Defensoría</a>
                        <?php endif; ?>

                        <?php if (tiene_permiso(['superadmin', 'guarderia'], $roles_array)): ?>
                            <a class="collapse-item font-weight-bold" style="color: #521f29;" href="<?= $path_prefix ?>modulos/guarderia/index.php">Centro de Cuidado Infantil</a>
                        <?php endif; ?>

                        <?php if (tiene_permiso(['superadmin', 'profesor'], $roles_array)): ?>
                            <a class="collapse-item font-weight-bold" style="color: #521f29;" href="<?= $path_prefix ?>modulos/profesor/index.php">Módulo Profesor</a>
                        <?php endif; ?>

                        <?php if (tiene_permiso(['superadmin', 'admin', 'control_estudio', 'estudiante'], $roles_array)): ?>
                            <a class="collapse-item font-weight-bold" style="color: #521f29;" href="<?= $path_prefix ?>modulos/estudiante/mi_aula_virtual.php">Mi Aula Virtual (Horario y Notas)</a>
                        <?php endif; ?>

                        <?php if (tiene_permiso(['superadmin', 'admin'], $roles_array)): ?>
                            <div class="dropdown-divider"></div>
                            <a class="collapse-item font-weight-bold" style="color: #521f29;" href="<?= $path_prefix ?>modulos/usuarios/index.php">Gestión de Usuarios</a>
                            <a class="collapse-item font-weight-bold" style="color: #521f29;" href="<?= $path_prefix ?>modulos/reportes/informes_movimiento.php">Reportes e Informes</a>
                            <a class="collapse-item font-weight-bold" style="color: #521f29;" href="<?= $path_prefix ?>modulos/configuracion/autoridades.php">Autoridades Escuela</a>
                            <a class="collapse-item font-weight-bold" style="color: #521f29;" href="<?= $path_prefix ?>modulos/configuracion/gestion_infraestructura.php">Gestión de Infraestructura</a>
                        <?php endif; ?>

                        <?php if (tiene_permiso(['superadmin', 'admin', 'control_estudio', 'profesor'], $roles_array) && !tiene_permiso(['superadmin', 'admin'], $roles_array)): ?>
                            <div class="dropdown-divider"></div>
                        <?php endif; ?>
                        <?php if (tiene_permiso(['superadmin', 'admin', 'control_estudio', 'profesor'], $roles_array)): ?>
                            <a class="collapse-item font-weight-bold" style="color: #521f29;" href="<?= $path_prefix ?>modulos/usuarios/carga_masiva_estudiantes.php">Matrícula Masiva Estudiantes</a>
                            <a class="collapse-item font-weight-bold" style="color: #521f29;" href="<?= $path_prefix ?>modulos/configuracion/dormitorios.php">Dormitorios y Pernoctas</a>
                        <?php endif; ?>

                    </div>
                </div>
            </li>

            <hr class="sidebar-divider d-none d-md-block">
            <div class="text-center d-none d-md-inline">
                <button class="rounded-circle border-0" id="sidebarToggle" style="background-color: #521f29;"></button>
            </div>
        </ul>

        <div id="content-wrapper" class="d-flex flex-column">
            <div id="content">

                <!-- Topbar -->
                <nav class="navbar navbar-expand navbar-light topbar mb-4 static-top shadow justify-content-between">
                    <button id="sidebarToggleTop" class="btn btn-link d-md-none rounded-circle mr-3">
                        <i class="fa fa-bars text-nora"></i>
                    </button>

                    <!-- Logos Institucionales Superior -->
                    <div class="topbar-logos d-none d-md-flex align-items-center">
                        <img src="<?= $path_prefix ?>assets/img/Logo del Ministerio 999 x 150-01.png" alt="Ministerio">
                        <img src="<?= $path_prefix ?>assets/img/GMVM.png" alt="GMVM">
                    </div>

                    <ul class="navbar-nav ml-auto">
                        <div class="topbar-divider d-none d-sm-block"></div>
                        <li class="nav-item dropdown no-arrow">
                            <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                                <span class="mr-2 d-none d-lg-inline text-nora font-weight-bold small"><?= htmlspecialchars($_SESSION['nombre'] ?? 'Usuario') ?></span>
                                <img class="img-profile rounded-circle" src="<?= $path_prefix ?>assets/img/icono.png" style="object-fit: cover;">
                            </a>
                            <div class="dropdown-menu dropdown-menu-right shadow animated--grow-in" aria-labelledby="userDropdown">
                                <a class="dropdown-item font-weight-bold" style="color: #521f29;" href="<?= $path_prefix ?>mi_perfil.php">
                                    <i class="fas fa-id-card fa-sm fa-fw mr-2 text-warning"></i> Mi Perfil y Carnet
                                </a>
                                <div class="dropdown-divider"></div>
                                <a class="dropdown-item font-weight-bold" style="color: #521f29;" href="<?= $path_prefix ?>logout.php">
                                    <i class="fas fa-sign-out-alt fa-sm fa-fw mr-2 text-gray-400"></i> Cerrar Sesión
                                </a>
                            </div>
                        </li>
                    </ul>
                </nav>

                <div class="container-fluid">