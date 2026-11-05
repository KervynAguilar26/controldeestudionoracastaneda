<?php
// /sistema_nora/modulos/reportes/reporte_lista_espera.php
require_once '../../config/db.php';
require_once '../../includes/header.php';

if (!tiene_permiso(['superadmin', 'admin', 'control_estudio'], $roles_array)) {
    echo "<div class='alert alert-danger font-weight-bold'>Acceso restringido a Control de Estudio y Administradores.</div>";
    require_once '../../includes/footer.php';
    exit();
}

// Filtros de Reporte
$filtro_curso       = trim($_GET['curso_web'] ?? '');
$filtro_etnia       = trim($_GET['etnia'] ?? '');
$filtro_discapacidad= trim($_GET['discapacidad'] ?? '');

$registros = [];
$totales = [
    'total_aspirantes' => 0,
    'con_etnia'        => 0,
    'con_discapacidad' => 0
];

$cursos_disponibles = [];
$etnias_list = [];
$discapacidades_list = [];

try {
    // Catálogos para Filtros
    if ($pdo_web) {
        $cursos_disponibles = $pdo_web->query("SELECT DISTINCT nombre FROM public.cursos ORDER BY nombre ASC")->fetchAll(PDO::FETCH_COLUMN);
    }
    $etnias_list = $pdo->query("SELECT nombre FROM public.etnia WHERE activo = 1 ORDER BY nombre ASC")->fetchAll(PDO::FETCH_COLUMN);
    $discapacidades_list = $pdo->query("SELECT nombre FROM public.discapacidad WHERE activo = 1 ORDER BY nombre ASC")->fetchAll(PDO::FETCH_COLUMN);

    // Consulta de Solicitudes con Caracterización Social
    if ($pdo_web) {
        $sql = "SELECT i.*, e.nombre as etnia_nombre, d.nombre as discapacidad_nombre 
                FROM public.inscripciones i
                LEFT JOIN public.etnia e ON i.etnia_id = e.id
                LEFT JOIN public.discapacidad d ON i.discapacidad_id = d.id
                WHERE 1=1";
        
        $params = [];

        if (!empty($filtro_curso)) {
            $sql .= " AND i.cursos_seleccionados LIKE :curso";
            $params[':curso'] = "%{$filtro_curso}%";
        }
        if (!empty($filtro_etnia)) {
            $sql .= " AND e.nombre = :etnia";
            $params[':etnia'] = $filtro_etnia;
        }
        if (!empty($filtro_discapacidad)) {
            $sql .= " AND d.nombre = :discapacidad";
            $params[':discapacidad'] = $filtro_discapacidad;
        }

        $sql .= " ORDER BY i.id DESC";

        $stmt = $pdo_web->prepare($sql);
        $stmt->execute($params);
        $registros = $stmt->fetchAll();

        // Métrica Consolidada
        $totales['total_aspirantes'] = count($registros);
        foreach ($registros as $r) {
            if (!empty($r['etnia_nombre']) && $r['etnia_nombre'] !== 'Ninguna') $totales['con_etnia']++;
            if (!empty($r['discapacidad_nombre']) && $r['discapacidad_nombre'] !== 'Ninguna') $totales['con_discapacidad']++;
        }
    }
} catch (Exception $e) {
    $error = $e->getMessage();
}
?>

<div class="d-sm-flex align-items-center justify-content-between mb-4 no-print">
    <h1 class="h3 mb-0 font-weight-bold" style="color: #521f29;">
        <i class="fas fa-chart-pie mr-2 text-warning"></i>Reporte Analítico de Lista de Espera Web
    </h1>
    <div>
        <button onclick="window.print()" class="btn btn-sm font-weight-bold text-white shadow-sm mr-1" style="background-color: #521f29;">
            <i class="fas fa-print mr-1"></i> Imprimir Reporte
        </button>
        <a href="../control_estudio/index.php" class="btn btn-sm btn-secondary font-weight-bold shadow-sm">
            <i class="fas fa-arrow-left mr-1"></i> Volver a Control de Estudio
        </a>
    </div>
</div>

<!-- FORMULARIO DE FILTROS (SE OCULTA AL IMPRIMIR) -->
<div class="card shadow border-0 mb-4 no-print" style="border-radius: 15px;">
    <div class="card-body bg-white p-3">
        <form method="GET" action="reporte_lista_espera.php" class="form-row align-items-end">
            <div class="col-md-4 mb-2">
                <label class="font-weight-bold small" style="color: #521f29;">Curso Seleccionado Web</label>
                <select name="curso_web" class="form-control font-weight-bold">
                    <option value="">-- Todos los Cursos --</option>
                    <?php foreach ($cursos_disponibles as $c): ?>
                        <option value="<?= htmlspecialchars($c) ?>" <?= ($filtro_curso === $c) ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 mb-2">
                <label class="font-weight-bold small" style="color: #521f29;">Filtro por Etnia</label>
                <select name="etnia" class="form-control font-weight-bold">
                    <option value="">-- Todas las Etnias --</option>
                    <?php foreach ($etnias_list as $e): ?>
                        <option value="<?= htmlspecialchars($e) ?>" <?= ($filtro_etnia === $e) ? 'selected' : '' ?>><?= htmlspecialchars($e) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3 mb-2">
                <label class="font-weight-bold small" style="color: #521f29;">Tipo de Discapacidad</label>
                <select name="discapacidad" class="form-control font-weight-bold">
                    <option value="">-- Todas las Discapacidades --</option>
                    <?php foreach ($discapacidades_list as $d): ?>
                        <option value="<?= htmlspecialchars($d) ?>" <?= ($filtro_discapacidad === $d) ? 'selected' : '' ?>><?= htmlspecialchars($d) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 mb-2">
                <button type="submit" class="btn btn-block font-weight-bold text-white shadow" style="background-color: #ff8a00;">
                    <i class="fas fa-filter mr-1"></i> FILTRAR
                </button>
            </div>
        </form>
    </div>
</div>

<!-- TARJETAS DE INDICADORES CARACTERIZACIÓN -->
<div class="row mb-4">
    <div class="col-md-4 mb-2">
        <div class="card border-left-warning shadow h-100 py-2 bg-white" style="border-radius: 12px;">
            <div class="card-body text-center">
                <span class="text-xs font-weight-bold text-uppercase d-block mb-1" style="color: #ff8a00;">Total Aspirantes Registrados</span>
                <h3 class="font-weight-bold mb-0" style="color: #521f29;"><?= $totales['total_aspirantes'] ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-4 mb-2">
        <div class="card border-left-nora shadow h-100 py-2 bg-white" style="border-radius: 12px; border-left-color: #521f29 !important;">
            <div class="card-body text-center">
                <span class="text-xs font-weight-bold text-uppercase d-block mb-1" style="color: #521f29;">Población Indígena / Etnia</span>
                <h3 class="font-weight-bold mb-0" style="color: #521f29;"><?= $totales['con_etnia'] ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-4 mb-2">
        <div class="card border-left-success shadow h-100 py-2 bg-white" style="border-radius: 12px;">
            <div class="card-body text-center">
                <span class="text-xs font-weight-bold text-success text-uppercase d-block mb-1">Personas con Discapacidad</span>
                <h3 class="font-weight-bold mb-0" style="color: #521f29;"><?= $totales['con_discapacidad'] ?></h3>
            </div>
        </div>
    </div>
</div>

<!-- TABLA DE REPORTE IMPRIMIBLE -->
<div class="card shadow border-0 mb-4" style="border-radius: 15px;">
    <div class="card-header py-3 bg-white border-0">
        <h6 class="m-0 font-weight-bold" style="color: #521f29;"><i class="fas fa-list-alt mr-2 text-warning"></i>Consolidado Demográfico de Aspirantes</h6>
    </div>
    <div class="card-body bg-white p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead style="background-color: #521f29; color: #ffffff;">
                    <tr>
                        <th>Cédula</th>
                        <th>Nombre y Apellido</th>
                        <th>Contacto</th>
                        <th>Curso Web Solicitado</th>
                        <th>Etnia</th>
                        <th>Discapacidad</th>
                        <th>Fecha Registro</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($registros)): ?>
                        <tr><td colspan="7" class="text-center py-4 text-muted font-weight-bold">No se encontraron aspirantes registrados con los criterios seleccionados.</td></tr>
                    <?php else: ?>
                        <?php foreach ($registros as $r): ?>
                            <tr>
                                <td class="font-weight-bold">V-<?= htmlspecialchars($r['cedula']) ?></td>
                                <td><strong style="color: #521f29;"><?= htmlspecialchars($r['nombre_apellido']) ?></strong></td>
                                <td>
                                    <small><?= htmlspecialchars($r['correo']) ?></small><br>
                                    <small class="text-muted"><?= htmlspecialchars($r['telefono']) ?></small>
                                </td>
                                <td><span class="badge badge-warning text-dark font-weight-bold p-2"><?= htmlspecialchars($r['cursos_seleccionados']) ?></span></td>
                                <td><small class="font-weight-bold"><?= htmlspecialchars($r['etnia_nombre'] ?? 'Ninguna') ?></small></td>
                                <td><small class="font-weight-bold"><?= htmlspecialchars($r['discapacidad_nombre'] ?? 'Ninguna') ?></small></td>
                                <td><small><?= date('d/m/Y h:i A', strtotime($r['fecha_registro'])) ?></small></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<style>
@media print {
    .no-print { display: none !important; }
    body { background: #fff !important; }
    .card { shadow: none !important; border: 1px solid #ccc !important; }
}
</style>

<?php require_once '../../includes/footer.php'; ?>