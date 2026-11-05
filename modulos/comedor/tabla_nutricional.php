<?php
// /sistema_nora/modulos/comedor/tabla_nutricional.php
require_once '../../config/db.php';
require_once '../../includes/header.php';

// Control de acceso: exclusivo de Comedor / Administración
if (!tiene_permiso(['comedor', 'admin', 'superadmin'], $roles_array)) {
    echo "<div class='alert alert-danger font-weight-bold'>Acceso restringido a Comedor.</div>";
    require_once '../../includes/footer.php';
    exit();
}

try {
    $lista_inn = $pdo->query("SELECT * FROM alimentos_tabla_venezuela ORDER BY nombre_alimento ASC")->fetchAll();
} catch (Exception $e) {
    $lista_inn = [];
}
?>

<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <h1 class="h3 mb-0 font-weight-bold" style="color: #521f29;">
        <i class="fas fa-leaf mr-2"></i>Tabla Nutricional INN (Venezuela)
    </h1>
    <a href="inventario.php" class="btn btn-sm btn-secondary font-weight-bold shadow-sm">
        <i class="fas fa-arrow-left mr-1"></i> Volver al Inventario
    </a>
</div>

<div class="card shadow mb-4" style="border-radius: 15px; border: none;">
    <div class="card-header py-3 d-flex justify-content-between align-items-center" style="background-color: #521f29; border-top-left-radius: 15px; border-top-right-radius: 15px;">
        <h6 class="m-0 font-weight-bold text-white"><i class="fas fa-search mr-2"></i>Valores Nutricionales por cada 100g de Alimento</h6>
        <input type="text" id="buscarINN" class="form-control form-control-sm w-50" placeholder="🔍 Buscar en catálogo INN (ej: Arroz, Pollo, Caraotas)...">
    </div>
    <div class="card-body bg-white" style="border-bottom-left-radius: 15px; border-bottom-right-radius: 15px;">
        <div class="table-responsive">
            <table class="table table-hover table-striped align-middle" id="tablaNutricion" width="100%">
                <thead style="background-color: #ff8a00; color: #521f29;">
                    <tr>
                        <th>Cód</th>
                        <th>Alimento</th>
                        <th>Grupo</th>
                        <th>Calorías</th>
                        <th>Proteínas</th>
                        <th>Grasas</th>
                        <th>Carbohidratos</th>
                        <th>Hierro (mg)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($lista_inn)): ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted py-3">No hay datos registrados en la tabla nutricional.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($lista_inn as $item): ?>
                            <tr>
                                <td class="font-weight-bold">#<?= $item['codigo_alimento'] ?></td>
                                <td class="font-weight-bold"><?= htmlspecialchars($item['nombre_alimento']) ?></td>
                                <td><span class="badge badge-light border"><?= htmlspecialchars($item['grupo_alimento']) ?></span></td>
                                <td class="text-danger font-weight-bold"><?= number_format($item['calorias_kcal'], 1) ?> kcal</td>
                                <td class="text-primary font-weight-bold"><?= number_format($item['proteinas_g'], 1) ?> g</td>
                                <td><?= number_format($item['grasas_totales_g'], 1) ?> g</td>
                                <td><?= number_format($item['carbohidratos_g'], 1) ?> g</td>
                                <td><?= number_format($item['hierro_mg'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>

<script>
$(document).ready(function() {
    $("#buscarINN").on("keyup", function() {
        var valor = $(this).val().toLowerCase();
        $("#tablaNutricion tbody tr").filter(function() {
            $(this).toggle($(this).text().toLowerCase().indexOf(valor) > -1)
        });
    });
});
</script>