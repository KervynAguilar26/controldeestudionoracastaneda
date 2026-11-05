<?php
// /sistema_nora/modulos/control_estudio/matricula_general.php
require_once '../../config/db.php';
require_once '../../includes/academico.php';
require_once '../../includes/header.php';

if (!tiene_permiso(['control_estudio', 'admin', 'superadmin'], $roles_array)) {
    echo "<div class='alert alert-danger font-weight-bold'>Acceso restringido a Control de Estudio.</div>";
    require_once '../../includes/footer.php';
    exit();
}

$mensaje = $_SESSION['mensaje'] ?? '';
$error   = $_SESSION['error'] ?? '';
unset($_SESSION['mensaje'], $_SESSION['error']);

// Filtros de búsqueda
$f_malla   = intval($_GET['id_malla'] ?? 0);
$f_cohorte = intval($_GET['id_cohorte'] ?? 0);
$f_q       = trim($_GET['q'] ?? '');

$sql = "SELECT i.id_inscripcion, i.fecha_inscripcion, i.nota_cualitativa,
               u.id_usuario AS id_estudiante, u.cedula, u.nombre, u.apellido, u.correo, u.telefono,
               co.id_cohorte, co.codigo_cohorte, co.salon_aula, co.num_periodo, co.cupos_maximos,
               co.estatus AS estatus_cohorte, co.fecha_inicio, co.fecha_fin_estimada,
               m.id_malla, m.nombre_programa, m.tipo_oferta, m.tipo_regimen, m.total_periodos
          FROM public.inscripciones i
          JOIN public.usuarios u ON u.id_usuario = i.id_estudiante
          JOIN public.cohortes co ON co.id_cohorte = i.id_curso
          JOIN public.mallas_curriculares m ON m.id_malla = co.id_malla
         WHERE 1 = 1";

$params = [];
if ($f_malla > 0) {
    $sql .= " AND m.id_malla = :malla";
    $params[':malla'] = $f_malla;
}
if ($f_cohorte > 0) {
    $sql .= " AND co.id_cohorte = :cohorte";
    $params[':cohorte'] = $f_cohorte;
}
if ($f_q !== '') {
    $sql .= " AND (u.cedula LIKE :q OR (u.nombre || ' ' || u.apellido) ILIKE :q)";
    $params[':q'] = '%' . $f_q . '%';
}
$sql .= " ORDER BY u.apellido ASC, u.nombre ASC, co.num_periodo ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$inscripciones = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Agrupar estudiantes por Malla/Carrera
$grupos = [];
foreach ($inscripciones as $fila) {
    $clave = $fila['id_estudiante'] . '_' . $fila['id_malla'];
    if (!isset($grupos[$clave])) {
        $grupos[$clave] = [
            'id_estudiante'   => (int) $fila['id_estudiante'],
            'cedula'          => $fila['cedula'],
            'estudiante'      => trim($fila['nombre'] . ' ' . $fila['apellido']),
            'correo'          => $fila['correo'],
            'telefono'        => $fila['telefono'],
            'id_malla'        => (int) $fila['id_malla'],
            'nombre_programa' => $fila['nombre_programa'],
            'tipo_oferta'     => $fila['tipo_oferta'],
            'tipo_regimen'    => $fila['tipo_regimen'] ?? 'Semestre',
            'total_periodos'  => (int) $fila['total_periodos'],
            'ultimo_periodo'  => 0,
            'ultima_cohorte'  => '',
            'ultima_inscripcion' => 0,
            'cohortes'        => [],
        ];
    }
    $g = &$grupos[$clave];
    if (!in_array($fila['codigo_cohorte'], $g['cohortes'], true)) {
        $g['cohortes'][] = $fila['codigo_cohorte'];
    }
    if ((int)$fila['num_periodo'] >= $g['ultimo_periodo']) {
        $g['ultimo_periodo'] = (int) $fila['num_periodo'];
        $g['ultima_cohorte'] = $fila['codigo_cohorte'];
        $g['ultima_inscripcion'] = (int) $fila['id_inscripcion'];
    }
    unset($g);
}

// Calcular avance académico
foreach ($grupos as $clave => $g) {
    $estado_malla = academico_estado_malla($pdo, $g['id_estudiante'], $g['id_malla']);
    $grupos[$clave]['estado'] = $estado_malla;
}

$mallasLista = $pdo->query("SELECT id_malla, nombre_programa FROM public.mallas_curriculares ORDER BY nombre_programa")->fetchAll(PDO::FETCH_ASSOC);
$cohortesLista = $pdo->query("SELECT id_cohorte, codigo_cohorte FROM public.cohortes ORDER BY codigo_cohorte")->fetchAll(PDO::FETCH_ASSOC);
$estudiantesLista = $pdo->query("SELECT id_usuario, cedula, nombre, apellido FROM public.usuarios WHERE tipo_usuario = 'estudiante' OR 'estudiante' = ANY(roles_asignados) ORDER BY apellido ASC")->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <h1 class="h3 mb-0 font-weight-bold" style="color: #521f29;">
        <i class="fas fa-user-graduate mr-2 text-warning"></i>Gestión de Matrícula y Promoción Académica
    </h1>
    <div>
        <button type="button" class="btn btn-warning text-dark font-weight-bold shadow-sm mr-2" data-toggle="modal" data-target="#modalNuevaInscripcion">
            <i class="fas fa-user-plus mr-1"></i> Nueva Inscripción / Promoción
        </button>
        <a href="index.php" class="btn btn-secondary font-weight-bold shadow-sm">
            <i class="fas fa-arrow-left mr-1"></i> Volver a Control
        </a>
    </div>
</div>

<?php if ($mensaje): ?><div class="alert alert-success font-weight-bold shadow-sm"><?= $mensaje ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger font-weight-bold shadow-sm"><?= $error ?></div><?php endif; ?>

<!-- FILTROS -->
<div class="card shadow border-0 mb-4" style="border-radius: 15px;">
    <div class="card-body bg-white p-4">
        <form method="GET" class="form-row align-items-end">
            <div class="form-group col-md-4">
                <label class="font-weight-bold text-dark">Buscar Estudiante (Cédula o Nombre)</label>
                <input type="text" name="q" value="<?= htmlspecialchars($f_q) ?>" class="form-control font-weight-bold" placeholder="Ej. 18234567">
            </div>
            <div class="form-group col-md-4">
                <label class="font-weight-bold text-dark">Malla / Carrera</label>
                <select name="id_malla" class="form-control font-weight-bold">
                    <option value="0">-- Todas las Carreras --</option>
                    <?php foreach ($mallasLista as $ml): ?>
                        <option value="<?= $ml['id_malla'] ?>" <?= $f_malla === (int)$ml['id_malla'] ? 'selected' : '' ?>><?= htmlspecialchars($ml['nombre_programa']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group col-md-4">
                <button type="submit" class="btn btn-block font-weight-bold text-white" style="background-color: #521f29;">
                    <i class="fas fa-search mr-1"></i> FILTRAR MATRÍCULA
                </button>
            </div>
        </form>
    </div>
</div>

<!-- PADRÓN DE ESTUDIANTES -->
<div class="card shadow border-0 mb-4" style="border-radius: 15px;">
    <div class="card-header py-3 text-white font-weight-bold" style="background-color: #521f29; border-top-left-radius: 15px; border-top-right-radius: 15px;">
        <i class="fas fa-list-alt mr-2 text-warning"></i>Padrón Matriculado y Avance por Semestre / Año
    </div>
    <div class="card-body bg-white p-4">
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead style="background-color: #ff8a00; color: #521f29;">
                    <tr>
                        <th>Cédula</th>
                        <th>Estudiante</th>
                        <th>Carrera / Oferta</th>
                        <th class="text-center">Nivel Actual</th>
                        <th>Porcentaje de Avance</th>
                        <th class="text-center">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($grupos)): ?>
                        <tr><td colspan="6" class="text-center text-muted font-weight-bold py-4">No existen registros en la matrícula con los criterios ingresados.</td></tr>
                    <?php else: ?>
                        <?php foreach ($grupos as $g): 
                            $e = $g['estado'];
                            $pct = $e['porcentaje'];
                            $colorBarra = $e['completado'] ? '#28a745' : '#ff8a00';
                        ?>
                            <tr>
                                <td class="font-weight-bold">V-<?= htmlspecialchars($g['cedula']) ?></td>
                                <td class="font-weight-bold" style="color:#521f29;">
                                    <?= htmlspecialchars($g['estudiante']) ?><br>
                                    <small class="text-muted"><i class="fas fa-envelope mr-1"></i><?= htmlspecialchars($g['correo'] ?? 'Sin correo') ?></small>
                                </td>
                                <td>
                                    <span class="font-weight-bold text-dark"><?= htmlspecialchars($g['nombre_programa']) ?></span><br>
                                    <small class="text-muted"><i class="fas fa-users mr-1"></i>Última Sección: <strong><?= htmlspecialchars($g['ultima_cohorte']) ?></strong></small>
                                </td>
                                <td class="text-center">
                                    <span class="badge badge-info px-3 py-2 font-weight-bold">
                                        <?= htmlspecialchars($g['tipo_regimen']) ?> <?= $g['ultimo_periodo'] ?> de <?= $g['total_periodos'] ?>
                                    </span>
                                </td>
                                <td style="min-width: 180px;">
                                    <div class="progress mb-1" style="height: 15px; background-color: #eee;">
                                        <div class="progress-bar font-weight-bold" role="progressbar" style="width: <?= $pct ?>%; background-color: <?= $colorBarra ?>; font-size: 8pt;">
                                            <?= $pct ?>%
                                        </div>
                                    </div>
                                    <small class="font-weight-bold text-muted"><?= $e['aprobadas'] ?> de <?= $e['total_materias'] ?> materias aprobadas</small>
                                </td>
                                <td class="text-center">
                                    <!-- Botón para Inscribir en el Siguiente Semestre / Sección -->
                                    <button type="button" class="btn btn-sm btn-success font-weight-bold mr-1" data-toggle="modal" data-target="#modalPromover_<?= $g['id_estudiante'] ?>_<?= $g['id_malla'] ?>" title="Promover al Siguiente Semestre / Año">
                                        <i class="fas fa-arrow-up mr-1"></i> Inscribir / Promover
                                    </button>
                                    <a href="imprimir_horario_estudiante.php?id_estudiante=<?= $g['id_estudiante'] ?>" target="_blank" class="btn btn-sm btn-outline-dark font-weight-bold" title="Imprimir Horario Estudiante">
                                        <i class="fas fa-calendar-alt"></i>
                                    </a>
                                </td>
                            </tr>

                            <!-- MODAL PROMOVER / INSCRIBIR SECCIÓN -->
                            <div class="modal fade" id="modalPromover_<?= $g['id_estudiante'] ?>_<?= $g['id_malla'] ?>" tabindex="-1" role="dialog">
                                <div class="modal-dialog modal-md modal-dialog-centered" role="document">
                                    <form method="POST" action="procesar_inscripcion.php" class="modal-content shadow-lg" style="border-radius: 12px;">
                                        <?php if (function_exists('csrf_field')) { echo csrf_field(); } ?>
                                        <input type="hidden" name="id_estudiante" value="<?= $g['id_estudiante'] ?>">
                                        <input type="hidden" name="id_malla" value="<?= $g['id_malla'] ?>">

                                        <div class="modal-header text-white" style="background-color: #521f29;">
                                            <h5 class="modal-title font-weight-bold"><i class="fas fa-graduation-cap text-warning mr-2"></i>Inscribir Nivel / Sección</h5>
                                            <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                                        </div>
                                        <div class="modal-body bg-light p-4 text-left">
                                            <p class="font-weight-bold text-dark mb-1">Estudiante: <?= htmlspecialchars($g['estudiante']) ?></p>
                                            <p class="small text-muted mb-3">Carrera: <?= htmlspecialchars($g['nombre_programa']) ?></p>

                                            <div class="form-group mb-3">
                                                <label class="font-weight-bold text-dark">Seleccionar Sección / Cohorte Activa *</label>
                                                <select name="id_cohorte" class="form-control font-weight-bold" required>
                                                    <option value="">-- Seleccionar Sección / Aula --</option>
                                                    <?php 
                                                    $stmt_co = $pdo->prepare("
                                                        SELECT c.id_cohorte, c.codigo_cohorte, c.num_periodo, c.salon_aula, c.cupos_maximos,
                                                            (SELECT COUNT(*) FROM public.inscripciones i WHERE i.id_curso = c.id_cohorte) AS inscritos
                                                        FROM public.cohortes c 
                                                        WHERE c.id_malla = :malla AND c.estatus IN ('Planificada', 'En Curso')
                                                        ORDER BY c.num_periodo ASC, c.codigo_cohorte ASC
                                                    ");
                                                    $stmt_co->execute([':malla' => $g['id_malla']]);
                                                    $cohs_dispo = $stmt_co->fetchAll(PDO::FETCH_ASSOC);

                                                    foreach ($cohs_dispo as $cd):
                                                        $disponibles = $cd['cupos_maximos'] - $cd['inscritos'];
                                                    ?>
                                                        <option value="<?= $cd['id_cohorte'] ?>" <?= $disponibles <= 0 ? 'disabled' : '' ?>>
                                                            <?= htmlspecialchars($cd['codigo_cohorte']) ?> (<?= $g['tipo_regimen'] ?> <?= $cd['num_periodo'] ?>) - Aula: <?= htmlspecialchars($cd['salon_aula']) ?> [Cupos: <?= $disponibles ?>/<?= $cd['cupos_maximos'] ?>]
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                        <div class="modal-footer bg-white">
                                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
                                            <button type="submit" name="procesar_inscripcion" class="btn font-weight-bold text-white" style="background-color: #ff8a00;">CONFIRMAR INSCRIPCIÓN</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- MODAL NUEVA INSCRIPCIÓN GENERAL -->
<div class="modal fade" id="modalNuevaInscripcion" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-md modal-dialog-centered" role="document">
        <form method="POST" action="procesar_inscripcion.php" class="modal-content shadow-lg" style="border-radius: 12px;">
            <?php if (function_exists('csrf_field')) { echo csrf_field(); } ?>
            <div class="modal-header text-white" style="background-color: #521f29;">
                <h5 class="modal-title font-weight-bold"><i class="fas fa-user-plus text-warning mr-2"></i>Nueva Inscripción a Oferta Académica</h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body bg-light p-4">
                <div class="form-group mb-3">
                    <label class="font-weight-bold text-dark">Seleccionar Estudiante *</label>
                    <select name="id_estudiante" class="form-control font-weight-bold" required>
                        <option value="">-- Buscar Estudiante --</option>
                        <?php foreach ($estudiantesLista as $est): ?>
                            <option value="<?= $est['id_usuario'] ?>">V-<?= htmlspecialchars($est['cedula']) ?> - <?= htmlspecialchars($est['nombre'] . ' ' . $est['apellido']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group mb-3">
                    <label class="font-weight-bold text-dark">Seleccionar Sección / Cohorte Activa *</label>
                    <select name="id_cohorte" class="form-control font-weight-bold" required>
                        <option value="">-- Seleccionar Sección / Aula --</option>
                        <?php 
                        $stmt_todas_co = $pdo->query("
                            SELECT c.id_cohorte, c.codigo_cohorte, c.salon_aula, c.cupos_maximos, mc.nombre_programa,
                                (SELECT COUNT(*) FROM public.inscripciones i WHERE i.id_curso = c.id_cohorte) AS inscritos
                            FROM public.cohortes c 
                            JOIN public.mallas_curriculares mc ON mc.id_malla = c.id_malla
                            WHERE c.estatus IN ('Planificada', 'En Curso')
                            ORDER BY mc.nombre_programa ASC, c.codigo_cohorte ASC
                        ");
                        while ($rowc = $stmt_todas_co->fetch(PDO::FETCH_ASSOC)):
                            $disp = $rowc['cupos_maximos'] - $rowc['inscritos'];
                        ?>
                            <option value="<?= $rowc['id_cohorte'] ?>" <?= $disp <= 0 ? 'disabled' : '' ?>>
                                <?= htmlspecialchars($rowc['nombre_programa']) ?> - <?= htmlspecialchars($rowc['codigo_cohorte']) ?> [Disponibles: <?= $disp ?>]
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>
            </div>
            <div class="modal-footer bg-white">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
                <button type="submit" name="procesar_inscripcion" class="btn font-weight-bold text-white" style="background-color: #ff8a00;">PROCESAR INSCRIPCIÓN</button>
            </div>
        </form>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>