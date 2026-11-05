<?php
// /sistema_nora/modulos/comedor/reportes.php
require_once '../../config/db.php';
require_once '../../includes/header.php';

// Control de acceso: exclusivo de Comedor / Administración
if (!tiene_permiso(['comedor', 'admin', 'superadmin'], $roles_array)) {
    echo "<div class='alert alert-danger font-weight-bold'>Acceso restringido a Comedor.</div>";
    require_once '../../includes/footer.php';
    exit();
}

// Filtros por fecha (por defecto muestra el mes actual)
$fecha_desde = $_GET['fecha_desde'] ?? date('Y-m-01');
$fecha_hasta = $_GET['fecha_hasta'] ?? date('Y-m-d');
$tipo_filtro = $_GET['tipo_movimiento'] ?? 'Todos';

// Consulta de movimientos con datos nutricionales acumulados
try {
    $sql = "SELECT m.*, i.nombre_producto, i.unidad_medida, i.categoria,
                   a.calorias_kcal, a.proteinas_g, a.carbohidratos_g
            FROM comedor_movimientos m
            JOIN comedor_inventario i ON m.id_producto = i.id_producto
            LEFT JOIN alimentos_tabla_venezuela a ON i.codigo_alimento_inn = a.codigo_alimento
            WHERE DATE(m.fecha_movimiento) BETWEEN :desde AND :hasta";

    if ($tipo_filtro !== 'Todos') {
        $sql .= " AND m.tipo_movimiento = :tipo";
    }

    $sql .= " ORDER BY m.fecha_movimiento DESC";

    $stmt = $pdo->prepare($sql);
    $params = [':desde' => $fecha_desde, ':hasta' => $fecha_hasta];
    if ($tipo_filtro !== 'Todos') {
        $params[':tipo'] = $tipo_filtro;
    }
    $stmt->execute($params);
    $movimientos = $stmt->fetchAll();

    // Acumuladores de totales y valores nutricionales
    $total_entradas = 0;
    $total_salidas = 0;
    $calorias_totales_cocinadas = 0;
    $proteinas_totales_cocinadas = 0;

    foreach ($movimientos as $mov) {
        if ($mov['tipo_movimiento'] === 'Entrada') {
            $total_entradas += $mov['cantidad'];
        } else {
            $total_salidas += $mov['cantidad'];
            // Aporte nutricional estimado en salidas de cocina (base 100g = 0.1kg)
            if ($mov['calorias_kcal'] > 0 && $mov['unidad_medida'] === 'Kg') {
                $calorias_totales_cocinadas += ($mov['cantidad'] * 10) * $mov['calorias_kcal'];
                $proteinas_totales_cocinadas += ($mov['cantidad'] * 10) * $mov['proteinas_g'];
            }
        }
    }
} catch (Exception $e) {
    $movimientos = [];
    $total_entradas = 0;
    $total_salidas = 0;
    $calorias_totales_cocinadas = 0;
    $proteinas_totales_cocinadas = 0;
}
?>

<div class="d-sm-flex align-items-center justify-content-between mb-4 d-print-none">
    <h1 class="h3 mb-0 font-weight-bold" style="color: #521f29;">
        <i class="fas fa-file-alt mr-2"></i>Reportes de Inventario y Nutrición
    </h1>
    <div>
        <button onclick="window.print()" class="btn btn-sm btn-danger font-weight-bold shadow-sm mr-2">
            <i class="fas fa-print mr-1"></i> Imprimir / Descargar PDF
        </button>
         <a href="imprimir_comensales.php" class="btn btn-sm btn-warning font-weight-bold shadow-sm mr-2" style="color: #521f29;">
            <i class="fas fa-print mr-1"></i> Imprimir Reporte de Comensales
        </a>
        <a href="inventario.php" class="btn btn-sm btn-secondary font-weight-bold shadow-sm">
            <i class="fas fa-arrow-left mr-1"></i> Volver al Inventario
        </a>

    </div>
</div>

<!-- Formulario de Filtros (No imprimible) -->
<div class="card shadow mb-4 d-print-none" style="border-radius: 15px; border: none;">
    <div class="card-body bg-light rounded">
        <form method="GET" action="reportes.php" class="form-row align-items-end">
            <div class="col-md-3 mb-2">
                <label class="font-weight-bold" style="color: #521f29;">Desde:</label>
                <input type="date" name="fecha_desde" class="form-control" value="<?= htmlspecialchars($fecha_desde) ?>">
            </div>
            <div class="col-md-3 mb-2">
                <label class="font-weight-bold" style="color: #521f29;">Hasta:</label>
                <input type="date" name="fecha_hasta" class="form-control" value="<?= htmlspecialchars($fecha_hasta) ?>">
            </div>
            <div class="col-md-3 mb-2">
                <label class="font-weight-bold" style="color: #521f29;">Movimiento:</label>
                <select name="tipo_movimiento" class="form-control font-weight-bold">
                    <option value="Todos" <?= $tipo_filtro === 'Todos' ? 'selected' : '' ?>>Todos los Movimientos</option>
                    <option value="Entrada" <?= $tipo_filtro === 'Entrada' ? 'selected' : '' ?>>Solo Entradas (Almacén)</option>
                    <option value="Salida" <?= $tipo_filtro === 'Salida' ? 'selected' : '' ?>>Solo Salidas (Cocina)</option>
                </select>
            </div>
            <div class="col-md-3 mb-2">
                <button type="submit" class="btn btn-block font-weight-bold text-white shadow-sm" style="background-color: #521f29;">
                    <i class="fas fa-filter mr-1"></i> FILTRAR
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Tarjetas con Resumen Global -->
<div class="row mb-4">
    <div class="col-md-3 mb-2">
        <div class="card border-left-success shadow h-100 py-2">
            <div class="card-body">
                <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Registros Encontrados</div>
                <div class="h5 mb-0 font-weight-bold text-gray-800"><?= count($movimientos) ?> Movimientos</div>
            </div>
        </div>
    </div>
    <div class="col-md-3 mb-2">
        <div class="card border-left-warning shadow h-100 py-2">
            <div class="card-body">
                <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Aporte Calórico Cocinado</div>
                <div class="h5 mb-0 font-weight-bold text-gray-800"><?= number_format($calorias_totales_cocinadas, 0) ?> Kcal</div>
            </div>
        </div>
    </div>
    <div class="col-md-3 mb-2">
        <div class="card border-left-primary shadow h-100 py-2">
            <div class="card-body">
                <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Proteínas Servidas</div>
                <div class="h5 mb-0 font-weight-bold text-gray-800"><?= number_format($proteinas_totales_cocinadas / 1000, 2) ?> Kg Est.</div>
            </div>
        </div>
    </div>
    <div class="col-md-3 mb-2">
        <div class="card border-left-danger shadow h-100 py-2">
            <div class="card-body">
                <div class="text-xs font-weight-bold text-danger text-uppercase mb-1">Rango de Fechas</div>
                <div class="small font-weight-bold text-gray-800"><?= date('d/m/Y', strtotime($fecha_desde)) ?> - <?= date('d/m/Y', strtotime($fecha_hasta)) ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Tabla Detallada para Reporte e Impresión -->
<div class="card shadow mb-4" style="border-radius: 15px; border: none;">
    <div class="card-header py-3" style="background-color: #521f29; border-top-left-radius: 15px; border-top-right-radius: 15px;">
        <h6 class="m-0 font-weight-bold text-white"><i class="fas fa-list mr-2"></i>Historial Detallado de Entradas y Despachos de Cocina</h6>
    </div>
    <div class="card-body bg-white">
        <div class="table-responsive">
            <table class="table table-bordered table-hover align-middle" width="100%">
                <thead style="background-color: #ff8a00; color: #521f29;">
                    <tr>
                        <th>Fecha / Hora</th>
                        <th>Tipo</th>
                        <th>Alimento / Insumo</th>
                        <th>Categoría</th>
                        <th>Cantidad</th>
                        <th>Aporte INN</th>
                        <th>Observación / Menú</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($movimientos)): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">No hay movimientos registrados para el rango de fechas seleccionado.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($movimientos as $m): ?>
                            <tr>
                                <td><?= date('d/m/Y h:i A', strtotime($m['fecha_movimiento'])) ?></td>
                                <td>
                                    <?php if ($m['tipo_movimiento'] === 'Entrada'): ?>
                                        <span class="badge badge-success px-2 py-1"><i class="fas fa-arrow-down"></i> Entrada</span>
                                    <?php else: ?>
                                        <span class="badge badge-danger px-2 py-1"><i class="fas fa-fire"></i> Salida Cocina</span>
                                    <?php endif; ?>
                                </td>
                                <td class="font-weight-bold"><?= htmlspecialchars($m['nombre_producto']) ?></td>
                                <td><span class="badge badge-light border"><?= htmlspecialchars($m['categoria']) ?></span></td>
                                <td class="font-weight-bold text-primary">
                                    <?= number_format($m['cantidad'], 2) ?> <?= htmlspecialchars($m['unidad_medida']) ?>
                                </td>
                                <td>
                                    <?php if (!empty($m['calorias_kcal'])): ?>
                                        <small class="text-success font-weight-bold"><i class="fas fa-leaf"></i> <?= number_format($m['calorias_kcal'], 0) ?> kcal/100g</small>
                                    <?php else: ?>
                                        <small class="text-muted">N/A</small>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($m['motivo_observacion'] ?? '-') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>