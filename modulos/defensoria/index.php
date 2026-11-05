<?php
// /sistema_nora/modulos/defensoria/index.php
require_once '../../config/db.php';
require_once '../../includes/header.php';

// Control de acceso: exclusivo de Defensoría / Administración
if (!tiene_permiso(['defensoria', 'admin', 'superadmin'], $roles_array)) {
    echo "<div class='alert alert-danger font-weight-bold'>Acceso restringido a Defensoría.</div>";
    require_once '../../includes/footer.php';
    exit();
}

// Cambiar estatus de expediente (acción POST para evitar mutaciones por enlace/CSRF)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cambiar_estatus'], $_POST['id'])) {
    $id_caso = intval($_POST['id']);
    $estatus_nuevo = $_POST['cambiar_estatus'];

    // Solo valores permitidos por la restricción CHECK de la base de datos
    if (in_array($estatus_nuevo, ['En Proceso', 'Cerrado', 'Remitido'], true)) {
        $stmt_up = $pdo->prepare("UPDATE defensoria_casos SET estatus_caso = :estatus WHERE id_caso = :id");
        $stmt_up->execute([':estatus' => $estatus_nuevo, ':id' => $id_caso]);
        $mensaje_def = "Estatus del expediente actualizado a: " . $estatus_nuevo;
    } else {
        $mensaje_def = "Estatus no válido.";
    }
}

// Consultar lista de expedientes
try {
    $casos = $pdo->query("SELECT c.*, u.nombre as defensora_nombre, u.apellido as defensora_apellido 
                          FROM defensoria_casos c 
                          LEFT JOIN usuarios u ON c.id_defensora = u.id_usuario 
                          ORDER BY c.id_caso DESC")->fetchAll();
} catch (Exception $e) {
    $casos = [];
}
?>

<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <h1 class="h3 mb-0 font-weight-bold" style="color: #521f29;">
        <i class="fas fa-balance-scale mr-2"></i>Módulo de Defensoría
    </h1>
    <a href="crear.php" class="btn btn-sm font-weight-bold shadow-sm" style="background-color: #ff8a00; color: #521f29;">
        <i class="fas fa-folder-plus mr-1"></i> Abrir Nuevo Expediente
    </a>
</div>

<?php if (!empty($mensaje_def)): ?>
    <div class="alert alert-info font-weight-bold"><?= htmlspecialchars($mensaje_def) ?></div>
<?php endif; ?>

<div class="card shadow mb-4" style="border-radius: 15px; border: none;">
    <div class="card-header py-3 d-flex justify-content-between align-items-center" style="background-color: #521f29; border-top-left-radius: 15px; border-top-right-radius: 15px;">
        <h6 class="m-0 font-weight-bold text-white"><i class="fas fa-list mr-2"></i>Expedientes Registrados</h6>
        
        <!-- Buscador Dinámico en el Encabezado -->
        <div class="w-50">
            <div class="input-group input-group-sm">
                <div class="input-group-prepend">
                    <span class="input-group-text bg-white border-0"><i class="fas fa-search text-gray-600"></i></span>
                </div>
                <input type="text" id="inputBuscador" class="form-control border-0" placeholder="Buscar por expediente, cédula, nombre o tipo de violencia...">
            </div>
        </div>
    </div>

    <div class="card-body bg-white" style="border-bottom-left-radius: 15px; border-bottom-right-radius: 15px;">
        
        <?php if (empty($casos)): ?>
            <div class="text-center py-5 text-muted">
                <i class="fas fa-folder-open fa-3x mb-3" style="color: #ff8a00;"></i>
                <p class="mb-0">No hay expedientes o casos registrados en la base de datos.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle" id="tablaExpedientes" width="100%">
                    <thead style="background-color: #521f29; color: #ffffff;">
                        <tr>
                            <th>Expediente</th>
                            <th>Cédula</th>
                            <th>Víctima / Afectada</th>
                            <th>Tipo Violencia</th>
                            <th>Registro</th>
                            <th>Estatus</th>
                            <th class="text-center">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($casos as $c): ?>
                            <tr>
                                <td class="font-weight-bold text-primary"><?= htmlspecialchars($c['codigo_expediente']) ?></td>
                                <td class="font-weight-bold"><?= htmlspecialchars($c['cedula_victimaria_afectada']) ?></td>
                                <td><?= htmlspecialchars($c['nombre_victima']) ?></td>
                                <td><span class="badge badge-light border font-weight-bold"><?= htmlspecialchars($c['tipo_violencia']) ?></span></td>
                                <td><?= date('d/m/Y', strtotime($c['fecha_registro'])) ?></td>
                                <td>
                                    <?php if ($c['estatus_caso'] === 'En Proceso'): ?>
                                        <span class="badge badge-warning px-2 py-1">En Proceso</span>
                                    <?php elseif ($c['estatus_caso'] === 'Remitido'): ?>
                                        <span class="badge badge-info px-2 py-1">Remitido</span>
                                    <?php else: ?>
                                        <span class="badge badge-success px-2 py-1">Cerrado</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <a href="ver.php?id=<?= $c['id_caso'] ?>" class="btn btn-sm btn-info font-weight-bold" title="Seguimiento / Ver Ficha">
                                        <i class="fas fa-eye mr-1"></i> Ver Ficha
                                    </a>
                                    <a href="imprimir_expediente.php?id=<?= $c['id_caso'] ?>" target="_blank" class="btn btn-sm font-weight-bold ml-1" style="background-color: #ff8a00; color: #521f29;" title="Imprimir PDF Oficio">
                                        <i class="fas fa-file-pdf mr-1"></i> Imprimir PDF
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>

<!-- Script de Búsqueda Dinámica en Tiempo Real -->
<script>
$(document).ready(function() {
    $("#inputBuscador").on("keyup", function() {
        var valor = $(this).val().toLowerCase();
        $("#tablaExpedientes tbody tr").filter(function() {
            $(this).toggle($(this).text().toLowerCase().indexOf(valor) > -1)
        });
    });
});
</script>