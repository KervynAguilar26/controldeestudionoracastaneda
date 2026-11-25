<?php
// /sistema_nora/modulos/control_estudio/carga_masiva_cedulas.php
// Carga masiva de estudiantes usando SOLAMENTE números de cédula y una
// sección/cohorte destino. No requiere nombre, correo ni perfil previo.

require_once '../../config/db.php';
require_once '../../includes/header.php';
require_once '../../includes/inscripcion_cedula.php';

if (!tiene_permiso(['control_estudio', 'admin', 'superadmin'], $roles_array)) {
    echo "<div class='alert alert-danger font-weight-bold'>Acceso restringido a Control de Estudio.</div>";
    require_once '../../includes/footer.php';
    exit();
}

$cohortes = $pdo->query("SELECT c.id_cohorte, c.codigo_cohorte, c.estatus, c.cupos_maximos,
                                m.nombre_programa,
                                (SELECT COUNT(*) FROM public.inscripciones i WHERE i.id_curso = c.id_cohorte) AS inscritos
                           FROM public.cohortes c
                           JOIN public.mallas_curriculares m ON m.id_malla = c.id_malla
                          WHERE c.estatus IN ('Planificada', 'En Curso')
                          ORDER BY c.fecha_inicio DESC, c.codigo_cohorte DESC")->fetchAll(PDO::FETCH_ASSOC);

$resultados = [];
$mensaje    = '';
$error      = '';
$cohorteSel = null;
$cedulasLeidas = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $idCohorte = (int) ($_POST['id_cohorte'] ?? 0);
    $texto     = (string) ($_POST['cedulas'] ?? '');
    $archivo   = $_FILES['archivo_cedulas'] ?? [];

    foreach ($cohortes as $c) {
        if ((int) $c['id_cohorte'] === $idCohorte) { $cohorteSel = $c; break; }
    }

    if ($idCohorte <= 0 || $cohorteSel === null) {
        $error = 'Debe seleccionar una sección válida.';
    } else {
        $lectura = inscripcion_cedula_leer_origen($texto, $archivo);
        $error   = $lectura['errores'] ? implode(' ', $lectura['errores']) : '';
        $cedulasLeidas = $lectura['cedulas'];

        if (!$error && empty($cedulasLeidas)) {
            $error = 'No se encontraron números de cédula. Pegue la lista en el cuadro o suba un archivo .csv, .txt o .xlsx.';
        } elseif (!$error) {
            if (count($cedulasLeidas) > 500) {
                $error = 'El límite por carga es de 500 cédulas. Se recibieron ' . count($cedulasLeidas) . '.';
            } else {
                foreach ($cedulasLeidas as $cedula) {
                    $fila = ['cedula' => $cedula, 'estado' => 'Error', 'nombre' => '—', 'detalle' => ''];
                    $r = inscripcion_cedula_resolver($pdo, $pdo_padron, $cedula);
                    if (!$r['ok']) {
                        $fila['detalle'] = $r['error'];
                        $resultados[] = $fila;
                        continue;
                    }
                    $fila['nombre'] = $r['nombre'];
                    $ins = inscripcion_cedula_inscribir($pdo, $idCohorte, (int) $r['id_usuario']);
                    if ($ins['ok']) {
                        $fila['estado'] = $r['creado'] ? 'Creado e inscrito' : 'Inscrito';
                        $fila['detalle'] = ($r['fuente'] === 'SOLO_CEDULA')
                            ? 'Cuenta creada solo con cédula; el estudiante podrá completar su perfil después.'
                            : 'Identidad obtenida del ' . strtolower(str_replace('_', ' ', $r['fuente']));
                    } elseif ($ins['ya']) {
                        $fila['estado'] = 'Ya inscrito';
                        $fila['detalle'] = 'El estudiante ya estaba inscrito en esta sección.';
                    } else {
                        $fila['detalle'] = $ins['error'];
                    }
                    $resultados[] = $fila;
                }
                $ok = count(array_filter($resultados, function ($r) {
                    return in_array($r['estado'], ['Inscrito', 'Creado e inscrito', 'Ya inscrito'], true);
                }));
                $mensaje = 'Proceso terminado: ' . $ok . ' de ' . count($resultados) . ' cédulas quedaron inscritas en la sección '
                         . $cohorteSel['codigo_cohorte'] . '. El profesor ya puede cargar sus notas.';
                inscripcion_cedula_bitacora($pdo, 'CARGA_MASIVA_CEDULAS seccion=' . $cohorteSel['codigo_cohorte']
                    . ' procesadas=' . count($resultados) . ' ok=' . $ok);
            }
        }
    }
}
?>
<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <div>
        <h1 class="h3 mb-0 font-weight-bold" style="color: #521f29;">
            <i class="fas fa-id-card mr-2 text-warning"></i>Carga Masiva por Cédula
        </h1>
        <p class="text-muted mb-0 small mt-1">Registre estudiantes solo con su número de cédula y asígnelos a una sección para cargar notas de inmediato.</p>
    </div>
    <a href="index.php" class="btn btn-secondary font-weight-bold shadow-sm">
        <i class="fas fa-arrow-left mr-1"></i> Volver a Control
    </a>
</div>

<?php if ($mensaje): ?><div class="alert alert-success font-weight-bold shadow-sm"><?= htmlspecialchars($mensaje) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger font-weight-bold shadow-sm"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="card shadow border-0 mb-4" style="border-radius: 15px;">
    <div class="card-header text-white font-weight-bold" style="background-color: #521f29;">
        <i class="fas fa-upload mr-2"></i> Nueva carga
    </div>
    <div class="card-body bg-white p-4">
        <form method="POST" enctype="multipart/form-data">
            <?= csrf_field() ?>
            <div class="form-row">
                <div class="form-group col-md-6">
                    <label class="font-weight-bold text-dark">Sección / Cohorte destino <span class="text-danger">*</span></label>
                    <select name="id_cohorte" class="form-control font-weight-bold" required>
                        <option value="">-- Seleccione la sección --</option>
                        <?php foreach ($cohortes as $c): ?>
                            <option value="<?= (int) $c['id_cohorte'] ?>"
                                <?= ($cohorteSel && (int) $cohorteSel['id_cohorte'] === (int) $c['id_cohorte']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c['codigo_cohorte']) ?> — <?= htmlspecialchars($c['nombre_programa']) ?>
                                (<?= (int) $c['inscritos'] ?>/<?= (int) $c['cupos_maximos'] ?: '∞' ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="form-text text-muted">Todos los estudiantes de esta carga quedarán en la misma sección.</small>
                </div>
                <div class="form-group col-md-6">
                    <label class="font-weight-bold text-dark">Archivo opcional (.csv, .txt, .xlsx)</label>
                    <input type="file" name="archivo_cedulas" class="form-control-file" accept=".csv,.txt,.xlsx">
                    <small class="form-text text-muted">Una cédula por línea o celda. Se ignoran encabezados y se aceptan prefijos V-/E-.</small>
                </div>
            </div>
            <div class="form-group">
                <label class="font-weight-bold text-dark">O pegue aquí la lista de cédulas</label>
                <textarea name="cedulas" class="form-control font-weight-bold" rows="8"
                          placeholder="12345678&#10;V-98765432&#10;E-11223344"><?= htmlspecialchars((string) ($_POST['cedulas'] ?? '')) ?></textarea>
            </div>
            <button type="submit" class="btn font-weight-bold text-white shadow-sm" style="background-color: #cd1e3e;">
                <i class="fas fa-user-plus mr-1"></i> PROCESAR INSCRIPCIONES
            </button>
        </form>
    </div>
</div>

<?php if ($resultados): ?>
    <div class="card shadow border-0" style="border-radius: 15px;">
        <div class="card-header text-white font-weight-bold" style="background-color: #521f29;">
            <i class="fas fa-list-check mr-2"></i> Reporte de la carga
        </div>
        <div class="card-body bg-white p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead class="thead-light">
                        <tr>
                            <th>#</th>
                            <th>Cédula</th>
                            <th>Nombre en el sistema</th>
                            <th>Resultado</th>
                            <th>Detalle</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($resultados as $i => $r): ?>
                            <tr>
                                <td><?= $i + 1 ?></td>
                                <td class="font-weight-bold"><?= htmlspecialchars($r['cedula']) ?></td>
                                <td><?= htmlspecialchars($r['nombre']) ?></td>
                                <td>
                                    <?php
                                    $badge = 'secondary';
                                    if (in_array($r['estado'], ['Inscrito', 'Creado e inscrito'], true)) { $badge = 'success'; }
                                    elseif ($r['estado'] === 'Ya inscrito') { $badge = 'info'; }
                                    elseif ($r['estado'] === 'Error') { $badge = 'danger'; }
                                    ?>
                                    <span class="badge badge-<?= $badge ?>"><?= htmlspecialchars($r['estado']) ?></span>
                                </td>
                                <td class="small text-muted"><?= htmlspecialchars($r['detalle']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php require_once '../../includes/footer.php'; ?>
