<?php
// /sistema_nora/modulos/defensoria/ver.php
require_once '../../config/db.php';
require_once '../../includes/header.php';

// Control de acceso: exclusivo de Defensoría / Administración
if (!tiene_permiso(['defensoria', 'admin', 'superadmin'], $roles_array)) {
    echo "<div class='alert alert-danger font-weight-bold'>Acceso restringido a Defensoría.</div>";
    require_once '../../includes/footer.php';
    exit();
}

$id_caso = isset($_GET['id']) ? intval($_GET['id']) : 0;

// Registrar nuevo seguimiento de atención
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['nueva_observacion'])) {
    $observacion          = trim($_POST['nueva_observacion']);
    $acuerdos_compromisos = trim($_POST['acuerdos_compromisos'] ?? '');
    $id_responsable       = $_SESSION['id_usuario'] ?? null;

    if (!empty($observacion)) {
        $stmt_seg = $pdo->prepare("INSERT INTO defensoria_seguimiento (id_caso, observacion, acuerdos_compromisos, id_responsable, fecha_atencion) 
                                   VALUES (:id_caso, :obs, :acuerdos, :resp, CURRENT_TIMESTAMP)");
        $stmt_seg->execute([
            ':id_caso'  => $id_caso,
            ':obs'      => $observacion,
            ':acuerdos' => $acuerdos_compromisos,
            ':resp'     => $id_responsable
        ]);
        echo "<script>alert('¡Seguimiento registrado!'); window.location.href='ver.php?id=$id_caso';</script>";
        exit();
    }
}

// Consultar datos del caso
$stmt = $pdo->prepare("SELECT * FROM defensoria_casos WHERE id_caso = :id LIMIT 1");
$stmt->execute([':id' => $id_caso]);
$caso = $stmt->fetch();

if (!$caso) {
    header("Location: index.php");
    exit();
}

// Consultar historial
$stmt_hist = $pdo->prepare("SELECT s.*, u.nombre, u.apellido FROM defensoria_seguimiento s LEFT JOIN usuarios u ON s.id_responsable = u.id_usuario WHERE s.id_caso = :id ORDER BY s.id_seguimiento DESC");
$stmt_hist->execute([':id' => $id_caso]);
$seguimientos = $stmt_hist->fetchAll();
?>

<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <h1 class="h3 mb-0 font-weight-bold" style="color: #521f29;">
        <i class="fas fa-folder-open mr-2"></i>Expediente: <?= htmlspecialchars($caso['codigo_expediente']) ?>
    </h1>
    <div>
        <!-- Botón corregido que abre directamente la plantilla de oficio -->
        <a href="imprimir_expediente.php?id=<?= $id_caso ?>" target="_blank" class="btn btn-sm btn-info font-weight-bold mr-2">
            <i class="fas fa-file-pdf mr-1"></i> Imprimir Documento Legal
        </a>
        <a href="index.php" class="btn btn-sm btn-secondary font-weight-bold">
            <i class="fas fa-arrow-left mr-1"></i> Volver
        </a>
    </div>
</div>

<div class="row">
    <!-- Ficha de Datos General -->
    <div class="col-lg-5 mb-4">
        <div class="card shadow border-left-nora h-100">
            <div class="card-header py-3" style="background-color: #521f29;">
                <h6 class="m-0 font-weight-bold text-white">Datos Generales de la Víctima</h6>
            </div>
            <div class="card-body">
                <p><strong>Cédula:</strong> <?= htmlspecialchars($caso['cedula_victimaria_afectada']) ?></p>
                <p><strong>Nombre Completo:</strong> <?= htmlspecialchars($caso['nombre_victima']) ?></p>
                <p><strong>Teléfono:</strong> <?= htmlspecialchars($caso['telefono_victima'] ?? 'N/A') ?></p>
                <p><strong>Dirección:</strong> <?= htmlspecialchars($caso['direccion_victima'] ?? 'N/A') ?></p>
                <p><strong>Tipo de Violencia:</strong> <span class="badge badge-warning"><?= htmlspecialchars($caso['tipo_violencia']) ?></span></p>
                <p><strong>Estatus del Caso:</strong> <span class="badge badge-success"><?= htmlspecialchars($caso['estatus_caso']) ?></span></p>
                <hr>
                <p><strong>Descripción de los Hechos:</strong></p>
                <div class="p-3 bg-light rounded small"><?= nl2br(htmlspecialchars($caso['descripcion_hechos'])) ?></div>
            </div>
        </div>
    </div>

    <!-- Bitácora de Seguimiento -->
    <div class="col-lg-7 mb-4">
        <div class="card shadow">
            <div class="card-header py-3" style="background-color: #ff8a00;">
                <h6 class="m-0 font-weight-bold" style="color: #521f29;"><i class="fas fa-history mr-1"></i> Bitácora de Atención y Seguimientos</h6>
            </div>
            <div class="card-body">

                <!-- Formulario agregar seguimiento -->
                <form method="POST" action="ver.php?id=<?= $id_caso ?>
                <?= csrf_field() ?>" class="mb-4 p-3 border rounded bg-light">
                    <h6 class="font-weight-bold" style="color: #521f29;">Agregar Nuevo Seguimiento / Cita</h6>
                    <div class="form-group mb-2">
                        <textarea name="nueva_observacion" class="form-control" rows="2" placeholder="Observaciones de la atención..." required></textarea>
                    </div>
                    <div class="form-group mb-2">
                        <input type="text" name="acuerdos_compromisos" class="form-control" placeholder="Acuerdos / Compromisos alcanzados (opcional)">
                    </div>
                    <button type="submit" class="btn btn-sm font-weight-bold px-3" style="background-color: #521f29; color: #fff;">
                        <i class="fas fa-plus-circle mr-1"></i> Registrar Sesión
                    </button>
                </form>

                <!-- Lista de Seguimientos -->
                <?php if (empty($seguimientos)): ?>
                    <p class="text-muted small text-center my-3">No hay sesiones de seguimiento registradas para este expediente.</p>
                <?php else: ?>
                    <div class="timeline">
                        <?php foreach ($seguimientos as $s): ?>
                            <div class="border-bottom pb-2 mb-3">
                                <small class="text-muted float-right"><?= date('d/m/Y h:i A', strtotime($s['fecha_atencion'])) ?></small>
                                <strong style="color: #521f29;">Atendido por: <?= htmlspecialchars(($s['nombre'] ?? 'KERVYN ROBERTO AGUILAR MONSALVE') . ' ' . ($s['apellido'] ?? '')) ?></strong>
                                <p class="mb-1 small"><?= nl2br(htmlspecialchars($s['observacion'])) ?></p>
                                <?php if (!empty($s['acuerdos_compromisos'])): ?>
                                    <small class="d-block text-success font-weight-bold"><i class="fas fa-check-square mr-1"></i> Acuerdos: <?= htmlspecialchars($s['acuerdos_compromisos']) ?></small>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

            </div>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>