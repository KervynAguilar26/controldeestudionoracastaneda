<?php
// /sistema_nora/modulos/control_estudio/index.php
require_once '../../config/db.php';
require_once '../../includes/header.php';

if (!function_exists('tiene_permiso') || !tiene_permiso(['control_estudio', 'admin', 'superadmin'], $roles_array ?? [])) {
    echo "<div class='container-fluid pt-4'><div class='alert alert-danger font-weight-bold'>Acceso restringido a Control de Estudio.</div></div>";
    require_once '../../includes/footer.php';
    exit();
}

// Lógica para eliminar cohorte / sección
if (isset($_GET['action']) && $_GET['action'] === 'eliminar_cohorte') {
    $id_del = intval($_GET['id'] ?? 0);
    if ($id_del > 0) {
        try {
            // Verificar si la cohorte tiene inscritos antes de borrar
            $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM public.inscripciones WHERE id_curso = :id");
            $stmt_check->execute([':id' => $id_del]);
            if ($stmt_check->fetchColumn() > 0) {
                echo "<script>alert('No se puede eliminar la sección porque ya cuenta con estudiantes inscritos.'); window.location.href='index.php';</script>";
                exit();
            }

            $stmt_del = $pdo->prepare("DELETE FROM public.cohortes WHERE id_cohorte = :id");
            $stmt_del->execute([':id' => $id_del]);
            echo "<script>window.location.href='index.php?msg=cohorte_eliminada';</script>";
            exit();
        } catch (Exception $e) {
            echo "<script>alert('Error al eliminar la cohorte: " . addslashes($e->getMessage()) . "'); window.location.href='index.php';</script>";
            exit();
        }
    }
}

// Estadísticas generales
$total_mallas   = $pdo->query("SELECT COUNT(*) FROM public.mallas_curriculares")->fetchColumn();
$total_cohortes = $pdo->query("SELECT COUNT(*) FROM public.cohortes WHERE estatus IN ('Planificada', 'En Curso')")->fetchColumn();
$total_alumnos  = $pdo->query("SELECT COUNT(DISTINCT id_estudiante) FROM public.inscripciones")->fetchColumn();
$total_docentes = $pdo->query("SELECT COUNT(DISTINCT id_profesor) FROM public.asignaciones_profesor")->fetchColumn();

// Consultar secciones activas
$sql_cohortes = "
    SELECT 
        c.*,
        mc.nombre_programa,
        (SELECT COUNT(*) FROM public.inscripciones i WHERE i.id_curso = c.id_cohorte) AS total_inscritos
    FROM public.cohortes c
    LEFT JOIN public.mallas_curriculares mc ON c.id_malla = mc.id_malla
    ORDER BY c.fecha_inicio DESC, c.codigo_cohorte ASC
";
$cohortes = $pdo->query($sql_cohortes)->fetchAll(PDO::FETCH_ASSOC);
?>

<style>
    .btn-quick-nav {
        border: none;
        border-radius: 12px;
        color: #ffffff !important;
        font-weight: 700;
        transition: all 0.3s ease;
        box-shadow: 0 4px 10px rgba(0,0,0,0.12);
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        padding: 20px 15px;
    }
    .btn-quick-nav:hover {
        transform: translateY(-4px);
        box-shadow: 0 8px 20px rgba(0,0,0,0.2);
        color: #ffffff !important;
        text-decoration: none;
    }
    .btn-quick-1 { background: linear-gradient(135deg, #521f29 0%, #3a151d 100%); }
    .btn-quick-2 { background: linear-gradient(135deg, #ff8a00 0%, #e07600 100%); }
    .btn-quick-3 { background: linear-gradient(135deg, #2b5876 0%, #4e4376 100%); }
    .btn-quick-4 { background: linear-gradient(135deg, #cd1e3e 0%, #8e1229 100%); }

    .action-btn {
        border-radius: 8px;
        padding: 5px 10px;
        font-size: 0.85rem;
        transition: all 0.2s ease;
    }
    .action-btn:hover {
        transform: scale(1.05);
    }
</style>

<div class="container-fluid pt-3">
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 font-weight-bold" style="color: #521f29;">
            <i class="fas fa-university mr-2 text-warning"></i>Gestión Académica e Infraestructura
        </h1>
        <div class="d-flex flex-wrap gap-2">
            <a href="crear_malla.php" class="btn btn-sm btn-dark font-weight-bold shadow-sm mr-2" style="border-radius: 8px;">
                <i class="fas fa-sitemap mr-1"></i> Diseñar Malla
            </a>
            <a href="crear_cohorte.php" class="btn btn-sm text-dark font-weight-bold shadow-sm mr-2" style="background-color: #ff8a00; border-radius: 8px;">
                <i class="fas fa-plus-circle mr-1"></i> Aperturar Sección
            </a>
            <a href="matricula_general.php" class="btn btn-sm font-weight-bold text-white shadow-sm" style="background-color: #cd1e3e; border-radius: 8px;">
                <i class="fas fa-user-graduate mr-1"></i> Matrícula / Promoción
            </a>
        </div>
    </div>

    <?php if (isset($_GET['msg']) && $_GET['msg'] === 'cohorte_guardada'): ?>
        <div class="alert alert-success font-weight-bold shadow-sm mb-4 alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle mr-2"></i> ¡La sección ha sido guardada y aperturada con éxito!
            <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>
        </div>
    <?php elseif (isset($_GET['msg']) && $_GET['msg'] === 'cohorte_eliminada'): ?>
        <div class="alert alert-info font-weight-bold shadow-sm mb-4 alert-dismissible fade show" role="alert">
            <i class="fas fa-info-circle mr-2"></i> La sección ha sido eliminada del sistema.
            <button type="button" class="close" data-dismiss="alert" aria-label="Close"><span aria-hidden="true">&times;</span></button>
        </div>
    <?php endif; ?>

    <!-- TARJETAS DE MÉTRICAS -->
    <div class="row mb-4">
        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-primary shadow h-100 py-2" style="border-left: 5px solid #521f29 !important; border-radius: 12px;">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-uppercase mb-1" style="color: #521f29;">Carreras / Mallas</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $total_mallas ?></div>
                        </div>
                        <div class="col-auto"><i class="fas fa-book-reader fa-2x text-gray-300"></i></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-success shadow h-100 py-2" style="border-left: 5px solid #ff8a00 !important; border-radius: 12px;">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Secciones Activas</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $total_cohortes ?></div>
                        </div>
                        <div class="col-auto"><i class="fas fa-layer-group fa-2x text-gray-300"></i></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-info shadow h-100 py-2" style="border-left: 5px solid #2b5876 !important; border-radius: 12px;">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-info text-uppercase mb-1">Estudiantes Matriculados</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $total_alumnos ?></div>
                        </div>
                        <div class="col-auto"><i class="fas fa-user-graduate fa-2x text-gray-300"></i></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6 mb-4">
            <div class="card border-left-warning shadow h-100 py-2" style="border-left: 5px solid #cd1e3e !important; border-radius: 12px;">
                <div class="card-body">
                    <div class="row no-gutters align-items-center">
                        <div class="col mr-2">
                            <div class="text-xs font-weight-bold text-secondary text-uppercase mb-1">Cuerpo Docente</div>
                            <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $total_docentes ?></div>
                        </div>
                        <div class="col-auto"><i class="fas fa-chalkboard-teacher fa-2x text-gray-300"></i></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- MENÚ RÁPIDO DE NAVEGACIÓN PROFESIONAL -->
    <div class="row mb-4">
        <div class="col-md-3 mb-2">
            <a href="lista_mallas.php" class="btn-quick-nav btn-quick-1">
                <i class="fas fa-list-alt fa-2x mb-2"></i>
                <span>Ver Mallas Curriculares</span>
            </a>
        </div>
        <div class="col-md-3 mb-2">
            <a href="gestionar_horarios.php" class="btn-quick-nav btn-quick-2">
                <i class="fas fa-calendar-alt fa-2x mb-2"></i>
                <span>Ajuste y Cuadre de Horarios</span>
            </a>
        </div>
        <div class="col-md-3 mb-2">
            <a href="lista_profesores.php" class="btn-quick-nav btn-quick-3">
                <i class="fas fa-chalkboard-teacher fa-2x mb-2"></i>
                <span>Nómina de Docentes</span>
            </a>
        </div>
        <div class="col-md-3 mb-2">
            <a href="consulta_notas.php" class="btn-quick-nav btn-quick-4">
                <i class="fas fa-clipboard-check fa-2x mb-2"></i>
                <span>Carga de Notas</span>
            </a>
        </div>
    </div>

    <!-- TABLA DE SECCIONES CON PALETA INSTITUCIONAL -->
    <div class="card shadow border-0 mb-4" style="border-radius: 15px;">
        <div class="card-header text-white font-weight-bold" style="background-color: #521f29; border-top-left-radius: 15px; border-top-right-radius: 15px;">
            <i class="fas fa-list-alt mr-2 text-warning"></i>Secciones, Aulas Asignadas y Capacidad
        </div>
        <div class="card-body p-4 bg-white">
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead style="background-color: #ff8a00; color: #ffffff;">
                        <tr>
                            <th>Sección / Carrera</th>
                            <th>Salón y Horario</th>
                            <th class="text-center">Matrícula</th>
                            <th>Apertura</th>
                            <th>Estatus</th>
                            <th class="text-center">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($cohortes)): ?>
                            <tr>
                                <td colspan="6" class="text-center text-muted font-weight-bold py-4">
                                    No hay secciones registradas actualmente.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($cohortes as $co): ?>
                                <tr>
                                    <td class="font-weight-bold" style="color: #521f29;">
                                        <?= htmlspecialchars($co['codigo_cohorte']) ?><br>
                                        <small class="text-muted"><i class="fas fa-book mr-1"></i><?= htmlspecialchars($co['nombre_programa'] ?? 'Programa') ?></small>
                                    </td>
                                    <td>
                                        <span class="font-weight-bold text-dark"><i class="fas fa-door-open mr-1" style="color: #ff8a00;"></i><?= htmlspecialchars($co['salon_aula']) ?></span><br>
                                        <small class="text-muted"><i class="fas fa-clock mr-1" style="color: #cd1e3e;"></i><?= htmlspecialchars($co['dias_clase']) ?> (<?= !empty($co['hora_inicio']) ? date('h:i A', strtotime($co['hora_inicio'])) : '' ?> - <?= !empty($co['hora_fin']) ? date('h:i A', strtotime($co['hora_fin'])) : '' ?>)</small>
                                    </td>
                                    <td class="text-center">
                                        <span class="badge font-weight-bold px-3 py-2 text-dark" style="background-color: #eecfb1;">
                                            <?= $co['total_inscritos'] ?> / <?= $co['cupos_maximos'] ?> Estudiantes
                                        </span>
                                    </td>
                                    <td><?= date('d/m/Y', strtotime($co['fecha_inicio'])) ?></td>
                                    <td>
                                        <span class="badge font-weight-bold px-3 py-2 text-white" style="background-color: #beab94;">
                                            <?= htmlspecialchars($co['estatus']) ?>
                                        </span>
                                    </td>
                                    <td class="text-center">
                                        <div class="btn-group" role="group">
                                            <!-- Botón Editar -->
                                            <a href="crear_cohorte.php?id=<?= $co['id_cohorte'] ?>" class="btn action-btn text-white font-weight-bold mr-1" style="background-color: #521f29;" title="Editar Sección">
                                                <i class="fas fa-edit mr-1"></i> Editar
                                            </a>
                                            
                                            <!-- Botón Clonar / Crear con Mismos Parámetros -->
                                            <a href="crear_cohorte.php?clonar=<?= $co['id_cohorte'] ?>" class="btn action-btn text-white font-weight-bold mr-1" style="background-color: #2b5876;" title="Aperturar otra sección con estos mismos parámetros">
                                                <i class="fas fa-copy mr-1"></i> Clonar
                                            </a>

                                            <!-- Botón Eliminar -->
                                            <a href="javascript:void(0);" onclick="confirmarEliminacion(<?= $co['id_cohorte'] ?>, '<?= htmlspecialchars($co['codigo_cohorte']) ?>')" class="btn action-btn text-white font-weight-bold" style="background-color: #cd1e3e;" title="Eliminar Sección">
                                                <i class="fas fa-trash-alt"></i>
                                            </a>
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
</div>

<script>
function confirmarEliminacion(id, codigo) {
    if (confirm(`¿Está seguro de que desea eliminar permanentemente la sección '${codigo}'?`)) {
        window.location.href = `index.php?action=eliminar_cohorte&id=${id}`;
    }
}
</script>

<?php require_once '../../includes/footer.php'; ?>