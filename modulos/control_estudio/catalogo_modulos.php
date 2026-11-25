<?php
// /sistema_nora/modulos/control_estudio/catalogo_modulos.php
// Catálogo académico completo: todas las mallas/carreras, sus módulos
// (asignaturas), los temas de cada uno y las horas correspondientes,
// independientemente de que estén o no asignados a un docente.

require_once '../../config/db.php';
require_once '../../includes/header.php';
require_once '../../includes/buscador_lista.php';

$f_q      = trim($_GET['q'] ?? '');
$f_malla  = intval($_GET['id_malla'] ?? 0);

$sql = "SELECT m.id_malla, m.nombre_programa, m.tipo_oferta, m.tipo_regimen, m.total_periodos,
               (SELECT COUNT(*) FROM public.malla_materias mm WHERE mm.id_malla = m.id_malla) AS modulos,
               (SELECT COALESCE(SUM(mm.horas_academicas), 0) FROM public.malla_materias mm WHERE mm.id_malla = m.id_malla) AS horas,
               (SELECT COUNT(*) FROM public.cohortes c WHERE c.id_malla = m.id_malla) AS cohortes
          FROM public.mallas_curriculares m
         WHERE 1 = 1";
$params = [];
if ($f_malla > 0) {
    $sql .= " AND m.id_malla = :malla";
    $params[':malla'] = $f_malla;
}
$sql .= " ORDER BY m.nombre_programa ASC, m.id_malla ASC";
$st = $pdo->prepare($sql);
$st->execute($params);
$mallas = $st->fetchAll(PDO::FETCH_ASSOC);

$stMaterias = $pdo->prepare("
    SELECT mm.id_materia, mm.modulo_num, mm.nombre_materia, mm.horas_academicas,
           mm.contenido_detallado,
           (SELECT COUNT(*) FROM public.materia_contenidos mc WHERE mc.id_materia = mm.id_materia) AS temas,
           (SELECT COALESCE(SUM(mc.horas_academicas), 0) FROM public.materia_contenidos mc WHERE mc.id_materia = mm.id_materia) AS horas_temas
      FROM public.malla_materias mm
     WHERE mm.id_malla = :malla
     ORDER BY mm.modulo_num ASC, mm.id_materia ASC
");
$stTemas = $pdo->prepare("
    SELECT mc.descripcion, mc.horas_academicas, mc.orden
      FROM public.materia_contenidos mc
     WHERE mc.id_materia = :materia
     ORDER BY mc.orden ASC, mc.id_contenido ASC
");

$catalogo = [];
foreach ($mallas as $m) {
    $stMaterias->execute([':malla' => $m['id_malla']]);
    $materias = $stMaterias->fetchAll(PDO::FETCH_ASSOC);
    foreach ($materias as &$ma) {
        $stTemas->execute([':materia' => $ma['id_materia']]);
        $ma['lista_temas'] = $stTemas->fetchAll(PDO::FETCH_ASSOC);
    }
    unset($ma);
    $catalogo[] = ['malla' => $m, 'materias' => $materias];
}

if ($f_q !== '') {
    $aguja = mb_strtolower($f_q, 'UTF-8');
    $catalogo = array_values(array_filter($catalogo, static function ($bloque) use ($aguja) {
        if (mb_strpos(mb_strtolower($bloque['malla']['nombre_programa'], 'UTF-8'), $aguja) !== false) {
            return true;
        }
        foreach ($bloque['materias'] as $ma) {
            if (mb_strpos(mb_strtolower($ma['nombre_materia'], 'UTF-8'), $aguja) !== false) {
                return true;
            }
            foreach ($ma['lista_temas'] as $tema) {
                if (mb_strpos(mb_strtolower($tema['descripcion'], 'UTF-8'), $aguja) !== false) {
                    return true;
                }
            }
        }
        return false;
    }));
}

$mallasLista = $pdo->query("SELECT id_malla, nombre_programa FROM public.mallas_curriculares ORDER BY nombre_programa")->fetchAll(PDO::FETCH_ASSOC);
$totalModulos = 0;
$totalTemas = 0;
foreach ($catalogo as $bloque) {
    $totalModulos += count($bloque['materias']);
    foreach ($bloque['materias'] as $ma) {
        $totalTemas += count($ma['lista_temas']);
    }
}
?>
<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <div>
        <h1 class="h3 mb-0 font-weight-bold" style="color:#521f29;">
            <i class="fas fa-th-list mr-2 text-warning"></i>Catálogo Académico — Todos los módulos
        </h1>
        <p class="small text-muted mb-0 mt-1">Mallas, asignaturas, temas y horas de toda la escuela, estén o no asignadas a un docente.</p>
    </div>
    <a href="../profesor/index.php" class="btn btn-outline-secondary font-weight-bold shadow-sm">
        <i class="fas fa-arrow-left mr-1"></i> Volver al Portal Docente
    </a>
</div>

<div class="card shadow border-0 mb-4" style="border-radius:15px;">
    <div class="card-body bg-white p-4">
        <form method="GET" class="form-row align-items-end">
            <div class="form-group col-md-5 mb-2">
                <label class="font-weight-bold small">Buscar por carrera, módulo o tema</label>
                <input type="text" name="q" value="<?= htmlspecialchars($f_q, ENT_QUOTES, 'UTF-8') ?>" class="form-control form-control-sm" placeholder="Ej. Emprendimiento, Módulo 1,Plan de negocio">
            </div>
            <div class="form-group col-md-5 mb-2">
                <label class="font-weight-bold small">Carrera / Malla</label>
                <select name="id_malla" class="form-control form-control-sm">
                    <option value="0">-- Todas las carreras --</option>
                    <?php foreach ($mallasLista as $ml): ?>
                        <option value="<?= (int) $ml['id_malla'] ?>" <?= $f_malla === (int) $ml['id_malla'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($ml['nombre_programa'], ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group col-md-2 mb-2">
                <button type="submit" class="btn btn-block btn-sm font-weight-bold text-white" style="background-color:#521f29;">
                    <i class="fas fa-search mr-1"></i> Filtrar
                </button>
            </div>
        </form>
        <div class="small font-weight-bold text-muted mt-2">
            Carreras: <?= count($catalogo) ?> · Módulos/asignaturas: <?= $totalModulos ?> · Temas: <?= $totalTemas ?>
        </div>
    </div>
</div>

<?php if (empty($catalogo)): ?>
    <div class="alert alert-warning font-weight-bold">No hay módulos que coincidan con el filtro.</div>
<?php endif; ?>

<?php foreach ($catalogo as $bloque): $m = $bloque['malla']; ?>
    <div class="card shadow border-0 mb-4 catalogo-malla" style="border-radius:15px;">
        <div class="card-header py-3 text-white font-weight-bold" style="background-color:#521f29; border-top-left-radius:15px; border-top-right-radius:15px;">
            <i class="fas fa-graduation-cap mr-2 text-warning"></i>
            <?= htmlspecialchars($m['nombre_programa'], ENT_QUOTES, 'UTF-8') ?>
            <span class="badge badge-light ml-2 text-dark">
                <?= (int) $m['modulos'] ?> módulos · <?= (int) $m['horas'] ?> h · <?= (int) $m['cohortes'] ?> secciones
            </span>
            <span class="badge badge-warning text-dark ml-1"><?= htmlspecialchars($m['tipo_regimen'] ?: 'Semestre', ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <div class="card-body bg-white p-4">
            <?php if (empty($bloque['materias'])): ?>
                <p class="text-muted small font-weight-bold mb-0">Esta carrera aún no tiene módulos registrados.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead style="background-color:#ff8a00; color:#521f29;">
                            <tr>
                                <th style="width:8%;">N°</th>
                                <th>Módulo / Asignatura</th>
                                <th class="text-center" style="width:12%;">Horas</th>
                                <th class="text-center" style="width:12%;">Temas</th>
                                <th style="width:45%;">Contenido / Temas</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($bloque['materias'] as $ma): ?>
                                <tr>
                                    <td class="font-weight-bold"><?= (int) $ma['modulo_num'] ?></td>
                                    <td class="font-weight-bold" style="color:#521f29;">
                                        <?= htmlspecialchars($ma['nombre_materia'], ENT_QUOTES, 'UTF-8') ?>
                                        <?php if ($ma['horas_temas'] > 0 && (int) $ma['horas_temas'] !== (int) $ma['horas_academicas']): ?>
                                            <br><small class="text-danger font-weight-bold">Horas de temas (<?= (int) $ma['horas_temas'] ?>) ≠ horas del módulo (<?= (int) $ma['horas_academicas'] ?>)</small>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center font-weight-bold"><?= (int) $ma['horas_academicas'] ?></td>
                                    <td class="text-center font-weight-bold"><?= count($ma['lista_temas']) ?></td>
                                    <td>
                                        <?php if (empty($ma['lista_temas'])): ?>
                                            <small class="text-muted">
                                                <?= htmlspecialchars(mb_substr(trim(strip_tags((string) $ma['contenido_detallado'])), 0, 180, 'UTF-8'), ENT_QUOTES, 'UTF-8') ?: 'Sin temas registrados' ?>
                                            </small>
                                        <?php else: ?>
                                            <ol class="small mb-0 pl-3">
                                                <?php foreach ($ma['lista_temas'] as $t): ?>
                                                    <li>
                                                        <?= htmlspecialchars($t['descripcion'], ENT_QUOTES, 'UTF-8') ?>
                                                        <span class="text-muted">(<?= (int) $t['horas_academicas'] ?> h)</span>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ol>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endforeach; ?>

<?php require_once '../../includes/footer.php'; ?>
