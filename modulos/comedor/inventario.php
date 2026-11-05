<?php
// /sistema_nora/modulos/comedor/inventario.php
require_once '../../config/db.php';
require_once '../../includes/header.php';

// Control de acceso: exclusivo de Comedor / Administración
if (!tiene_permiso(['comedor', 'admin', 'superadmin'], $roles_array)) {
    echo "<div class='alert alert-danger font-weight-bold'>Acceso restringido a Comedor.</div>";
    require_once '../../includes/footer.php';
    exit();
}

$mensaje = '';
$tipo_alerta = '';

// Checklist de alimentos con vinculación a la Tabla INN de Venezuela
$checklist_alimentos = [
    // Víveres y Granos
    ['nombre' => 'Arroz Blanco', 'categoria' => 'Víveres', 'unidad' => 'Kg', 'inn' => 1],
    ['nombre' => 'Harina de Maíz Blanco', 'categoria' => 'Víveres', 'unidad' => 'Kg', 'inn' => 33],
    ['nombre' => 'Harina de Maíz Amarillo', 'categoria' => 'Víveres', 'unidad' => 'Kg', 'inn' => 22],
    ['nombre' => 'Harina de Trigo', 'categoria' => 'Víveres', 'unidad' => 'Kg', 'inn' => 45],
    ['nombre' => 'Pasta / Espagueti / Macarrones', 'categoria' => 'Víveres', 'unidad' => 'Kg', 'inn' => 54],
    ['nombre' => 'Caraotas Negras', 'categoria' => 'Víveres', 'unidad' => 'Kg', 'inn' => 313],
    ['nombre' => 'Arvejas', 'categoria' => 'Víveres', 'unidad' => 'Kg', 'inn' => 306],
    ['nombre' => 'Lentejas', 'categoria' => 'Víveres', 'unidad' => 'Kg', 'inn' => 329],
    ['nombre' => 'Quinchoncho', 'categoria' => 'Víveres', 'unidad' => 'Kg', 'inn' => 325],
    ['nombre' => 'Frijoles', 'categoria' => 'Víveres', 'unidad' => 'Kg', 'inn' => 320],
    ['nombre' => 'Avena en Hojuelas', 'categoria' => 'Víveres', 'unidad' => 'Kg', 'inn' => 13],
    ['nombre' => 'Maizena (Fécula de Maíz)', 'categoria' => 'Víveres', 'unidad' => 'Kg', 'inn' => 25],
    ['nombre' => 'Azúcar', 'categoria' => 'Víveres', 'unidad' => 'Kg', 'inn' => 500],
    ['nombre' => 'Sal', 'categoria' => 'Víveres', 'unidad' => 'Kg', 'inn' => 510],
    ['nombre' => 'Café', 'categoria' => 'Víveres', 'unidad' => 'Kg', 'inn' => 515],

    // Verduras, Tubérculos y Hortalizas
    ['nombre' => 'Plátano Maduro / Verde', 'categoria' => 'Perecederos', 'unidad' => 'Kg', 'inn' => 476],
    ['nombre' => 'Tomate', 'categoria' => 'Perecederos', 'unidad' => 'Kg', 'inn' => 395],
    ['nombre' => 'Cebolla', 'categoria' => 'Perecederos', 'unidad' => 'Kg', 'inn' => 368],
    ['nombre' => 'Cebollín', 'categoria' => 'Perecederos', 'unidad' => 'Kg', 'inn' => 369],
    ['nombre' => 'Pimentón', 'categoria' => 'Perecederos', 'unidad' => 'Kg', 'inn' => 385],
    ['nombre' => 'Ají Dulce', 'categoria' => 'Perecederos', 'unidad' => 'Kg', 'inn' => 386],
    ['nombre' => 'Ají Picante', 'categoria' => 'Perecederos', 'unidad' => 'Kg', 'inn' => 387],
    ['nombre' => 'Ajo', 'categoria' => 'Perecederos', 'unidad' => 'Kg', 'inn' => 360],
    ['nombre' => 'Cilantro', 'categoria' => 'Perecederos', 'unidad' => 'Kg', 'inn' => 370],
    ['nombre' => 'España / Célere (Apio España)', 'categoria' => 'Perecederos', 'unidad' => 'Kg', 'inn' => 371],
    ['nombre' => 'Yuca', 'categoria' => 'Perecederos', 'unidad' => 'Kg', 'inn' => 351],
    ['nombre' => 'Papa', 'categoria' => 'Perecederos', 'unidad' => 'Kg', 'inn' => 348],
    ['nombre' => 'Zanahoria', 'categoria' => 'Perecederos', 'unidad' => 'Kg', 'inn' => 400],
    ['nombre' => 'Apio (Raza / Tubérculo)', 'categoria' => 'Perecederos', 'unidad' => 'Kg', 'inn' => 338],
    ['nombre' => 'Ñame', 'categoria' => 'Perecederos', 'unidad' => 'Kg', 'inn' => 345],
    ['nombre' => 'Ocumo', 'categoria' => 'Perecederos', 'unidad' => 'Kg', 'inn' => 346],
    ['nombre' => 'Ahuyama (Calabaza)', 'categoria' => 'Perecederos', 'unidad' => 'Kg', 'inn' => 362],
    ['nombre' => 'Berenjena', 'categoria' => 'Perecederos', 'unidad' => 'Kg', 'inn' => 364],

    // Frutas
    ['nombre' => 'Cambur', 'categoria' => 'Perecederos', 'unidad' => 'Kg', 'inn' => 445],
    ['nombre' => 'Piña', 'categoria' => 'Perecederos', 'unidad' => 'Kg', 'inn' => 465],
    ['nombre' => 'Patilla', 'categoria' => 'Perecederos', 'unidad' => 'Kg', 'inn' => 470],
    ['nombre' => 'Guayaba', 'categoria' => 'Perecederos', 'unidad' => 'Kg', 'inn' => 450],
    ['nombre' => 'Guanábana', 'categoria' => 'Perecederos', 'unidad' => 'Kg', 'inn' => 452],
    ['nombre' => 'Manzana', 'categoria' => 'Perecederos', 'unidad' => 'Kg', 'inn' => 458],
    ['nombre' => 'Pera', 'categoria' => 'Perecederos', 'unidad' => 'Kg', 'inn' => 462],

    // Proteínas, Carnes, Embutidos y Charcutería
    ['nombre' => 'Pollo Entero / Beneficiado', 'categoria' => 'Proteínas', 'unidad' => 'Kg_Unidad', 'inn' => 86],
    ['nombre' => 'Carne Molida (Res)', 'categoria' => 'Proteínas', 'unidad' => 'Kg', 'inn' => 154],
    ['nombre' => 'Carne de Guisar (Res)', 'categoria' => 'Proteínas', 'unidad' => 'Kg', 'inn' => 150],
    ['nombre' => 'Carne de Mechar (Res)', 'categoria' => 'Proteínas', 'unidad' => 'Kg', 'inn' => 152],
    ['nombre' => 'Costilla de Res', 'categoria' => 'Proteínas', 'unidad' => 'Kg', 'inn' => 156],
    ['nombre' => 'Hueso de Res', 'categoria' => 'Proteínas', 'unidad' => 'Kg', 'inn' => 158],
    ['nombre' => 'Carne de Cerdo', 'categoria' => 'Proteínas', 'unidad' => 'Kg', 'inn' => 105],
    ['nombre' => 'Chuleta de Cerdo', 'categoria' => 'Proteínas', 'unidad' => 'Kg', 'inn' => 106],
    ['nombre' => 'Chuleta Ahumada', 'categoria' => 'Proteínas', 'unidad' => 'Kg', 'inn' => 107],
    ['nombre' => 'Costilla de Cerdo', 'categoria' => 'Proteínas', 'unidad' => 'Kg', 'inn' => 108],
    ['nombre' => 'Hueso Ahumado', 'categoria' => 'Proteínas', 'unidad' => 'Kg', 'inn' => 109],
    ['nombre' => 'Tocineta', 'categoria' => 'Proteínas', 'unidad' => 'Kg', 'inn' => 112],
    ['nombre' => 'Salchichas', 'categoria' => 'Proteínas', 'unidad' => 'Kg', 'inn' => 120],
    ['nombre' => 'Mortadela de Carne / Res', 'categoria' => 'Proteínas', 'unidad' => 'Kg', 'inn' => 122],
    ['nombre' => 'Mortadela de Pollo', 'categoria' => 'Proteínas', 'unidad' => 'Kg', 'inn' => 124],
    ['nombre' => 'Huevos de Gallina', 'categoria' => 'Proteínas', 'unidad' => 'Unidades', 'inn' => 255],

    // Lácteos, Salsas y Grasas
    ['nombre' => 'Leche Líquida', 'categoria' => 'Líquidos', 'unidad' => 'Litros', 'inn' => 266],
    ['nombre' => 'Leche en Polvo Completa', 'categoria' => 'Líquidos', 'unidad' => 'Kg', 'inn' => 268],
    ['nombre' => 'Queso Blanco Duro', 'categoria' => 'Lácteos', 'unidad' => 'Kg', 'inn' => 281],
    ['nombre' => 'Queso Amarillo', 'categoria' => 'Lácteos', 'unidad' => 'Kg', 'inn' => 285],
    ['nombre' => 'Mantequilla / Margarina', 'categoria' => 'Lácteos', 'unidad' => 'Kg', 'inn' => 290],
    ['nombre' => 'Aceite Vegetal', 'categoria' => 'Líquidos', 'unidad' => 'Litros', 'inn' => 536],
    ['nombre' => 'Salsa de Tomate', 'categoria' => 'Víveres', 'unidad' => 'Kg', 'inn' => 520],
    ['nombre' => 'Mayonesa', 'categoria' => 'Víveres', 'unidad' => 'Kg', 'inn' => 525]
];

// Procesar Registro de Entrada / Salida
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $tipo_movimiento     = trim($_POST['tipo_movimiento'] ?? 'Entrada');
    $nombre_producto     = trim($_POST['nombre_producto'] ?? '');
    $id_producto         = intval($_POST['id_producto'] ?? 0);
    $categoria           = trim($_POST['categoria'] ?? 'Víveres');
    $unidad_medida       = trim($_POST['unidad_medida'] ?? 'Kg');
    $codigo_alimento_inn = !empty($_POST['codigo_alimento_inn']) ? intval($_POST['codigo_alimento_inn']) : null;
    $cantidad            = floatval($_POST['cantidad'] ?? 0);
    $cant_unidades       = floatval($_POST['cant_unidades'] ?? 0);
    $observacion         = trim($_POST['observacion'] ?? '');
    $id_usuario          = $_SESSION['id_usuario'] ?? null;

    if ($tipo_movimiento === 'Entrada') {
        if (!empty($nombre_producto) && $cantidad > 0) {
            try {
                $pdo->beginTransaction();

                $stmt_check = $pdo->prepare("SELECT id_producto FROM comedor_inventario WHERE LOWER(nombre_producto) = LOWER(:nombre) LIMIT 1");
                $stmt_check->execute([':nombre' => $nombre_producto]);
                $prod_existente = $stmt_check->fetch();

                if ($prod_existente) {
                    $prod_id = $prod_existente['id_producto'];
                    $stmt_up = $pdo->prepare("UPDATE comedor_inventario SET existencia_actual = existencia_actual + :cant, codigo_alimento_inn = COALESCE(:inn, codigo_alimento_inn), fecha_actualizacion = CURRENT_TIMESTAMP WHERE id_producto = :id");
                    $stmt_up->execute([':cant' => $cantidad, ':inn' => $codigo_alimento_inn, ':id' => $prod_id]);
                } else {
                    $stmt_in = $pdo->prepare("INSERT INTO comedor_inventario (nombre_producto, categoria, existencia_actual, unidad_medida, codigo_alimento_inn) VALUES (:nombre, :cat, :cant, :uni, :inn) RETURNING id_producto");
                    $stmt_in->execute([
                        ':nombre' => $nombre_producto,
                        ':cat'    => $categoria,
                        ':cant'   => $cantidad,
                        ':uni'    => $unidad_medida,
                        ':inn'    => $codigo_alimento_inn
                    ]);
                    $prod_id = $stmt_in->fetchColumn();
                }

                $obs_final = $observacion;
                if ($cant_unidades > 0) {
                    $obs_final .= " (Piezas/Unidades estimadas: $cant_unidades)";
                }

                $stmt_mov = $pdo->prepare("INSERT INTO comedor_movimientos (id_producto, tipo_movimiento, cantidad, motivo_observacion, id_usuario_registro) VALUES (:p, 'Entrada', :cant, :obs, :u)");
                $stmt_mov->execute([':p' => $prod_id, ':cant' => $cantidad, ':obs' => $obs_final, ':u' => $id_usuario]);

                $pdo->commit();
                $mensaje = "¡Entrada de alimento registrada correctamente!";
                $tipo_alerta = "success";
            } catch (Exception $e) {
                $pdo->rollBack();
                $mensaje = "Error al registrar la entrada: " . $e->getMessage();
                $tipo_alerta = "danger";
            }
        }
    } elseif ($tipo_movimiento === 'Salida') {
        if ($id_producto > 0 && $cantidad > 0) {
            try {
                $stmt_stock = $pdo->prepare("SELECT existencia_actual, nombre_producto FROM comedor_inventario WHERE id_producto = :id LIMIT 1");
                $stmt_stock->execute([':id' => $id_producto]);
                $stock = $stmt_stock->fetch();

                if ($stock && $stock['existencia_actual'] >= $cantidad) {
                    $pdo->beginTransaction();

                    $stmt_up = $pdo->prepare("UPDATE comedor_inventario SET existencia_actual = existencia_actual - :cant, fecha_actualizacion = CURRENT_TIMESTAMP WHERE id_producto = :id");
                    $stmt_up->execute([':cant' => $cantidad, ':id' => $id_producto]);

                    $obs_final = $observacion;
                    if ($cant_unidades > 0) {
                        $obs_final .= " (Piezas/Unidades consumidas: $cant_unidades)";
                    }

                    $stmt_mov = $pdo->prepare("INSERT INTO comedor_movimientos (id_producto, tipo_movimiento, cantidad, motivo_observacion, id_usuario_registro) VALUES (:p, 'Salida', :cant, :obs, :u)");
                    $stmt_mov->execute([':p' => $id_producto, ':cant' => $cantidad, ':obs' => $obs_final, ':u' => $id_usuario]);

                    $pdo->commit();
                    $mensaje = "¡Salida a cocina registrada con éxito!";
                    $tipo_alerta = "success";
                } else {
                    $mensaje = "La cantidad a retirar supera lo disponible en almacén.";
                    $tipo_alerta = "warning";
                }
            } catch (Exception $e) {
                $mensaje = "Error al procesar la salida: " . $e->getMessage();
                $tipo_alerta = "danger";
            }
        }
    }
}

// Consultar lista de inventario disponible
try {
    $inventario_completo = $pdo->query("SELECT i.*, a.calorias_kcal, a.proteinas_g FROM comedor_inventario i LEFT JOIN alimentos_tabla_venezuela a ON i.codigo_alimento_inn = a.codigo_alimento ORDER BY i.nombre_producto ASC")->fetchAll();
    $inventario_disponible = $pdo->query("SELECT * FROM comedor_inventario WHERE existencia_actual > 0 ORDER BY nombre_producto ASC")->fetchAll();
} catch (Exception $e) {
    $inventario_completo = [];
    $inventario_disponible = [];
}
?>

<!-- Estilos CSS de Select2 y Bootstrap 4 Theme -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<link href="https://cdn.jsdelivr.net/npm/@ttskch/select2-bootstrap4-theme@1.5.2/dist/select2-bootstrap4.min.css" rel="stylesheet" />

<style>
    .select2-container--bootstrap4 .select2-selection--single {
        height: calc(2.25rem + 10px) !important;
        font-size: 11pt !important;
        font-weight: bold !important;
    }
    .select2-container--bootstrap4 .select2-selection--single .select2-selection__rendered {
        line-height: 2.25rem !important;
    }
</style>

<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <h1 class="h3 mb-0 font-weight-bold" style="color: #521f29;">
        <i class="fas fa-utensils mr-2"></i>Control de Cocina e Inventario
    </h1>
    <div>
        <a href="tabla_nutricional.php" class="btn btn-sm btn-success font-weight-bold shadow-sm mr-2" style="background-color: #2e7d32; border: none;">
            <i class="fas fa-leaf mr-1"></i> Tabla INN Venezuela
        </a>
        <a href="reportes.php" class="btn btn-sm btn-info font-weight-bold shadow-sm mr-2">
            <i class="fas fa-file-alt mr-1"></i> Reportes
        </a>
        <a href="index.php" class="btn btn-sm btn-secondary font-weight-bold shadow-sm">
            <i class="fas fa-arrow-left mr-1"></i> Volver al Marcaje
        </a>
    </div>
</div>

<?php if (!empty($mensaje)): ?>
    <div class="alert alert-<?= $tipo_alerta ?> alert-dismissible fade show font-weight-bold" style="font-size: 12pt;" role="alert">
        <?= $mensaje ?>
        <button type="button" class="close" data-dismiss="alert">&times;</button>
    </div>
<?php endif; ?>

<div class="row">

    <!-- Panel de Entradas y Salidas de Cocina -->
    <div class="col-lg-5 mb-4">
        <div class="card shadow border-left-nora" style="border-radius: 15px; border: none;">
            <div class="card-header py-3" style="background-color: #521f29; border-top-left-radius: 15px; border-top-right-radius: 15px;">
                <ul class="nav nav-pills nav-fill" id="tabMov" role="tablist">
                    <li class="nav-item">
                        <a class="nav-link active font-weight-bold py-2" id="entrada-tab" data-toggle="tab" href="#formEntrada" role="tab" style="background-color: #ff8a00; color: #521f29; font-size: 11pt;">
                            <i class="fas fa-arrow-down mr-1"></i> ENTRADA (Recibir)
                        </a>
                    </li>
                    <li class="nav-item ml-2">
                        <a class="nav-link text-white font-weight-bold py-2" id="salida-tab" data-toggle="tab" href="#formSalida" role="tab" style="font-size: 11pt; background-color: #3f181f;">
                            <i class="fas fa-fire mr-1"></i> SALIDA (Cocinar)
                        </a>
                    </li>
                </ul>
            </div>

            <div class="card-body bg-white p-4" style="border-bottom-left-radius: 15px; border-bottom-right-radius: 15px;">
                <div class="tab-content" id="tabMovContent">

                    <!-- FORMULARIO 1: ENTRADA DE PRODUCTOS -->
                    <div class="tab-pane fade show active" id="formEntrada" role="tabpanel">
                        <form method="POST" action="inventario.php">
                        <?= csrf_field() ?>
                            <input type="hidden" name="tipo_movimiento" value="Entrada">
                            
                            <div class="form-group mb-3 bg-light p-2 rounded border">
                                <label class="font-weight-bold" style="color: #521f29; font-size: 11pt;">
                                    <i class="fas fa-search text-success mr-1"></i> Buscador Rápido de Alimento
                                </label>
                                <select id="selectChecklist" class="form-control select2bs4 font-weight-bold">
                                    <option value="">🔍 Escribe para buscar (ej: cilantro, caraotas, pollo)...</option>
                                    <?php foreach ($checklist_alimentos as $item): ?>
                                        <option value="<?= htmlspecialchars($item['nombre']) ?>" 
                                                data-cat="<?= $item['categoria'] ?>" 
                                                data-uni="<?= $item['unidad'] ?>"
                                                data-inn="<?= $item['inn'] ?>">
                                            <?= htmlspecialchars($item['nombre']) ?> (<?= $item['categoria'] ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group mb-3">
                                <label class="font-weight-bold" style="color: #521f29;">Nombre del Alimento</label>
                                <input type="text" name="nombre_producto" id="inputNombreProducto" class="form-control font-weight-bold" placeholder="Escribe el nombre del alimento" required>
                            </div>

                            <div class="form-row">
                                <div class="form-group col-md-6 mb-3">
                                    <label class="font-weight-bold" style="color: #521f29;">Categoría</label>
                                    <select name="categoria" id="selectCategoria" class="form-control font-weight-bold" required>
                                        <option value="Víveres">Víveres</option>
                                        <option value="Proteínas">Proteínas</option>
                                        <option value="Líquidos">Líquidos</option>
                                        <option value="Perecederos">Perecederos / Verduras</option>
                                        <option value="Lácteos">Lácteos</option>
                                    </select>
                                </div>
                                <div class="form-group col-md-6 mb-3">
                                    <label class="font-weight-bold" style="color: #521f29;">Unidad de Medida</label>
                                    <select name="unidad_medida" id="selectUnidad" class="form-control font-weight-bold" onchange="evaluarUnidadMedida(this.value)" required>
                                        <option value="Kg">Kilos (Kg)</option>
                                        <option value="Litros">Litros (Lts)</option>
                                        <option value="Unidades">Unidades / Piezas</option>
                                    </select>
                                </div>
                            </div>

                            <input type="hidden" name="codigo_alimento_inn" id="inputCodigoINN" value="">

                            <div class="form-row">
                                <div class="form-group col-md-6 mb-3">
                                    <label class="font-weight-bold" style="color: #521f29; font-size: 11pt;">Cantidad Recibida *</label>
                                    <input type="number" step="0.01" name="cantidad" class="form-control form-control-lg font-weight-bold text-center border-warning" placeholder="0.00" required>
                                </div>
                                <div class="form-group col-md-6 mb-3" id="boxPiezas" style="display:none;">
                                    <label class="font-weight-bold text-primary">N° Pollos / Piezas</label>
                                    <input type="number" name="cant_unidades" class="form-control form-control-lg font-weight-bold text-center border-primary" placeholder="Ej: 12">
                                </div>
                            </div>

                            <div class="form-group mb-3">
                                <label class="font-weight-bold" style="color: #521f29;">Observación / Proveedor (Opcional)</label>
                                <input type="text" name="observacion" class="form-control" placeholder="Ej: Proveedor Misión Alimentación">
                            </div>

                            <button type="submit" class="btn btn-block font-weight-bold py-3 shadow" style="background-color: #ff8a00; color: #521f29; border: none; font-size: 12pt;">
                                <i class="fas fa-plus-circle mr-1"></i> GUARDAR ENTRADA EN ALMACÉN
                            </button>
                        </form>
                    </div>

                    <!-- FORMULARIO 2: SALIDA DE PRODUCTOS A COCINA -->
                    <div class="tab-pane fade" id="formSalida" role="tabpanel">
                        <form method="POST" action="inventario.php">
                        <?= csrf_field() ?>
                            <input type="hidden" name="tipo_movimiento" value="Salida">

                            <div class="form-group mb-3">
                                <label class="font-weight-bold" style="color: #521f29; font-size: 11pt;">¿Qué alimento van a cocinar hoy? *</label>
                                <select name="id_producto" class="form-control select2bs4 font-weight-bold" required>
                                    <option value="">🔍 Escribe para buscar en la despensa...</option>
                                    <?php foreach ($inventario_disponible as $inv): ?>
                                        <option value="<?= $inv['id_producto'] ?>">
                                            <?= htmlspecialchars($inv['nombre_producto']) ?> (Disponible: <?= number_format($inv['existencia_actual'], 2) ?> <?= htmlspecialchars($inv['unidad_medida']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group mb-3">
                                <label class="font-weight-bold" style="color: #521f29; font-size: 11pt;">Cantidad a retirar para la cocina *</label>
                                <input type="number" step="0.01" name="cantidad" class="form-control form-control-lg font-weight-bold text-center border-danger" placeholder="0.00" required>
                            </div>

                            <div class="form-group mb-3">
                                <label class="font-weight-bold" style="color: #521f29;">Menú / Preparación</label>
                                <input type="text" name="observacion" class="form-control" placeholder="Ej: Almuerzo - Pabellón Criollo">
                            </div>

                            <button type="submit" class="btn btn-block font-weight-bold py-3 text-white shadow" style="background-color: #521f29; border: none; font-size: 12pt;">
                                <i class="fas fa-utensil-spoon mr-1"></i> REGISTRAR SALIDA A COCINA
                            </button>
                        </form>
                    </div>

                </div>
            </div>
        </div>
    </div>

    <!-- Tabla Visual de Existencias en Despensa -->
    <div class="col-lg-7 mb-4">
        <div class="card shadow mb-4" style="border-radius: 15px; border: none;">
            <div class="card-header py-3 d-flex justify-content-between align-items-center" style="background-color: #521f29; border-top-left-radius: 15px; border-top-right-radius: 15px;">
                <h6 class="m-0 font-weight-bold text-white"><i class="fas fa-boxes mr-2"></i>Alimentos Disponibles en Despensa</h6>
                <input type="text" id="buscarInventario" class="form-control form-control-sm w-50" placeholder="🔍 Buscar alimento en tabla...">
            </div>
            <div class="card-body bg-white" style="border-bottom-left-radius: 15px; border-bottom-right-radius: 15px;">
                <div class="table-responsive">
                    <table class="table table-hover align-middle" id="tablaStock" width="100%">
                        <thead style="background-color: #ff8a00; color: #521f29;">
                            <tr>
                                <th>Alimento</th>
                                <th>Categoría</th>
                                <th>Cantidad Disponible</th>
                                <th>Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($inventario_completo as $item): ?>
                                <tr>
                                    <td class="font-weight-bold text-dark" style="font-size: 11pt;">
                                        <?= htmlspecialchars($item['nombre_producto']) ?>
                                        <?php if (!empty($item['calorias_kcal'])): ?>
                                            <br><small class="text-success font-weight-normal"><i class="fas fa-leaf"></i> INN: <?= number_format($item['calorias_kcal'], 0) ?> kcal/100g</small>
                                        <?php endif; ?>
                                    </td>
                                    <td><span class="badge badge-light border font-weight-bold"><?= htmlspecialchars($item['categoria']) ?></span></td>
                                    <td class="font-weight-bold text-primary" style="font-size: 12pt;">
                                        <?= number_format($item['existencia_actual'], 2) ?> <?= htmlspecialchars($item['unidad_medida']) ?>
                                    </td>
                                    <td>
                                        <?php if ($item['existencia_actual'] <= 0): ?>
                                            <span class="badge badge-danger px-2 py-1" style="font-size: 10pt;">Agotado</span>
                                        <?php elseif ($item['existencia_actual'] <= 5): ?>
                                            <span class="badge badge-warning px-2 py-1 text-dark" style="font-size: 10pt;">Comprar pronto</span>
                                        <?php else: ?>
                                            <span class="badge badge-success px-2 py-1" style="font-size: 10pt;">Suficiente</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

</div>

<?php require_once '../../includes/footer.php'; ?>

<!-- Script JS de Select2 -->
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
$(document).ready(function() {
    // Inicializar Select2 con el buscador automático
    $('.select2bs4').select2({
        theme: 'bootstrap4',
        allowClear: true,
        width: '100%'
    });

    // Evento al seleccionar en el buscador desplegable
    $('#selectChecklist').on('change', function() {
        const opt = this.options[this.selectedIndex];
        if (opt.value !== '') {
            document.getElementById('inputNombreProducto').value = opt.value;
            document.getElementById('selectCategoria').value = opt.getAttribute('data-cat');
            document.getElementById('inputCodigoINN').value = opt.getAttribute('data-inn');
            
            const uni = opt.getAttribute('data-uni');
            if (uni === 'Kg_Unidad') {
                document.getElementById('selectUnidad').value = 'Kg';
                document.getElementById('boxPiezas').style.display = 'block';
            } else {
                document.getElementById('selectUnidad').value = uni;
                document.getElementById('boxPiezas').style.display = 'none';
            }
        }
    });

    // Filtro dinámico para la tabla de inventario
    $("#buscarInventario").on("keyup", function() {
        var valor = $(this).val().toLowerCase();
        $("#tablaStock tbody tr").filter(function() {
            $(this).toggle($(this).text().toLowerCase().indexOf(valor) > -1)
        });
    });
});

function evaluarUnidadMedida(val) {
    if (val === 'Kg' && document.getElementById('inputNombreProducto').value.toLowerCase().includes('pollo')) {
        document.getElementById('boxPiezas').style.display = 'block';
    } else {
        document.getElementById('boxPiezas').style.display = 'none';
    }
}
</script>