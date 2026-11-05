<?php
// /sistema_nora/modulos/control_estudio/lista_mallas.php
require_once '../../config/db.php';
require_once '../../includes/header.php';

// Control de acceso: gestión de mallas exclusiva de Control de Estudio / Administración
if (!tiene_permiso(['control_estudio', 'admin', 'superadmin'], $roles_array)) {
    echo "<div class='alert alert-danger font-weight-bold'>Acceso restringido a la Unidad de Control de Estudio.</div>";
    require_once '../../includes/footer.php';
    exit();
}

$mensaje = '';
$tipo_alerta = '';

// ELIMINAR MALLA CURRICULAR Y SUS MATERIAS
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion_eliminar_malla'])) {
    try {
        $id_malla = intval($_POST['id_malla']);
        
        $pdo->beginTransaction();
        // Borrar materias asociadas primero
        $pdo->prepare("DELETE FROM public.malla_materias WHERE id_malla = :id")->execute([':id' => $id_malla]);
        // Borrar la malla principal
        $pdo->prepare("DELETE FROM public.mallas_curriculares WHERE id_malla = :id")->execute([':id' => $id_malla]);
        $pdo->commit();

        $mensaje = "Programa o Carrera eliminada satisfactoriamente junto a sus materias.";
        $tipo_alerta = "success";
    } catch (Exception $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $mensaje = "No se puede eliminar la carrera porque posee cohortes o datos vinculados.";
        $tipo_alerta = "danger";
    }
}

// CONSULTAR TODAS LAS MALLAS Y CONTAR SUS MATERIAS Y COHORTES VINCULADAS
$sql_mallas = "SELECT m.*, 
                     (SELECT COUNT(*) FROM public.malla_materias mm WHERE mm.id_malla = m.id_malla) AS total_materias,
                     (SELECT COUNT(*) FROM public.cohortes c WHERE c.id_malla = m.id_malla) AS total_cohortes
              FROM public.mallas_curriculares m 
              ORDER BY m.id_malla DESC";
$mallas = $pdo->query($sql_mallas)->fetchAll(PDO::FETCH_ASSOC);
?>

<!-- ENCABEZADO DE SECCIÓN -->
<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <h1 class="h3 mb-0 font-weight-bold" style="color: #521f29;">
        <i class="fas fa-graduation-cap mr-2 text-warning"></i>Lista de Carreras, Cursos y Oferta Académica
    </h1>
    <div>
        <a href="crear_malla.php" class="btn btn-sm font-weight-bold text-white shadow-sm mr-1" style="background-color: #ff8a00;">
            <i class="fas fa-plus-circle mr-1"></i> Diseñar Nueva Carrera / Curso
        </a>
        <a href="index.php" class="btn btn-sm btn-secondary font-weight-bold shadow-sm">
            <i class="fas fa-arrow-left mr-1"></i> Volver a Control de Estudio
        </a>
    </div>
</div>

<?php if (!empty($mensaje)): ?>
    <div class="alert alert-<?= $tipo_alerta ?> alert-dismissible fade show font-weight-bold shadow-sm" role="alert">
        <?= $mensaje ?>
        <button type="button" class="close" data-dismiss="alert">&times;</button>
    </div>
<?php endif; ?>

<!-- TABLA DE CONTROL DE PROGRAMAS -->
<div class="card shadow mb-4" style="border-radius: 15px; border: none;">
    <div class="card-header py-3" style="background-color: #521f29; border-top-left-radius: 15px; border-top-right-radius: 15px;">
        <h6 class="m-0 font-weight-bold text-white"><i class="fas fa-list-alt mr-2"></i>Programas Académicos Creados</h6>
    </div>
    <div class="card-body bg-white p-4">
        <div class="table-responsive">
            <table class="table table-hover align-middle" width="100%">
                <thead style="background-color: #ff8a00; color: #521f29;">
                    <tr>
                        <th>Programa / Carrera</th>
                        <th>Tipo Oferta</th>
                        <th>Régimen Académico</th>
                        <th class="text-center">Períodos / Módulos</th>
                        <th class="text-center">Materias Registradas</th>
                        <th class="text-center">Cohortes Aperturadas</th>
                        <th class="text-center">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($mallas)): ?>
                        <tr><td colspan="7" class="text-center text-muted py-4 font-weight-bold">No hay carreras o cursos creados en el sistema.</td></tr>
                    <?php else: ?>
                        <?php foreach ($mallas as $m): ?>
                            <tr>
                                <td class="font-weight-bold">
                                    <span style="color: #521f29;"><?= htmlspecialchars($m['nombre_programa']) ?></span>
                                    <?php if (!empty($m['requiere_pernocta']) && $m['requiere_pernocta'] !== 'false'): ?>
                                        <span class="badge badge-danger ml-1">Pernocta</span>
                                    <?php endif; ?>
                                    <small class="text-muted d-block"><?= htmlspecialchars($m['descripcion'] ?? 'Sin descripción') ?></small>
                                </td>
                                <td><span class="badge badge-dark"><?= htmlspecialchars($m['tipo_oferta']) ?></span></td>
                                <td><span class="badge badge-warning text-dark font-weight-bold"><?= htmlspecialchars($m['tipo_regimen'] ?? 'Módulo') ?></span></td>
                                <td class="text-center font-weight-bold"><?= intval($m['total_periodos'] ?? 1) ?></td>
                                <td class="text-center">
                                    <span class="badge badge-info px-2 py-1 font-weight-bold">
                                        <?= $m['total_materias'] ?> Asignaturas
                                    </span>
                                </td>
                                <td class="text-center">
                                    <span class="badge badge-success px-2 py-1 font-weight-bold">
                                        <?= $m['total_cohortes'] ?> Cohortes
                                    </span>
                                </td>
                                <td class="text-center">
                                    <div class="btn-group">
                                        <!-- ABRE DIRECTAMENTE EL FORMULARIO COMPLETO DE CREAR_MALLA PRECARGADO -->
                                        <a href="crear_malla.php?id=<?= $m['id_malla'] ?>" class="btn btn-sm btn-outline-primary font-weight-bold" title="Editar Formulario Completo">
                                            <i class="fas fa-edit"></i> Editar
                                        </a>
                                        <button class="btn btn-sm btn-outline-danger font-weight-bold" onclick="confirmarEliminacionMalla(<?= $m['id_malla'] ?>, '<?= htmlspecialchars($m['nombre_programa'], ENT_QUOTES) ?>')" title="Eliminar Programa">
                                            <i class="fas fa-trash-alt"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- FORMULARIO OCULTO ELIMINAR -->
<form method="POST" id="formEliminarMalla" action="lista_mallas.php">
<?= csrf_field() ?>
    <input type="hidden" name="accion_eliminar_malla" value="1">
    <input type="hidden" name="id_malla" id="eliminar_id_malla">
</form>

<script>
function confirmarEliminacionMalla(id, nombre) {
    if (confirm("¿Está seguro de eliminar la carrera o curso: '" + nombre + "'?\n\n¡Atención! Se eliminarán también todas sus materias registradas.")) {
        document.getElementById('eliminar_id_malla').value = id;
        document.getElementById('formEliminarMalla').submit();
    }
}
</script>

<?php require_once '../../includes/footer.php'; ?>