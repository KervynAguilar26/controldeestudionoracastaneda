<?php
// /sistema_nora/modulos/guarderia/asistencia.php
require_once '../../config/db.php';
require_once '../../includes/header.php';
require_once '../../includes/representante_ubicacion.php';
require_once '../../includes/buscador_lista.php';

// Control de acceso: exclusivo de Centro de Cuidados Infantiles  / Administración
if (!tiene_permiso(['guarderia', 'admin', 'superadmin'], $roles_array)) {
    echo "<div class='alert alert-danger font-weight-bold'>Acceso restringido a Centro de Cuidados Infantiles.</div>";
    require_once '../../includes/footer.php';
    exit();
}

// Registrar Entrada (POST para evitar mutaciones por enlace/CSRF)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['entrada_id'])) {
    $id_nino = intval($_POST['entrada_id']);
    $sql = "INSERT INTO public.guarderia_asistencia (id_nino, fecha_asistencia, hora_entrada, estatus) 
            VALUES (:id, CURRENT_DATE, CURRENT_TIME, 'Presente')
            ON CONFLICT (id_nino, fecha_asistencia) DO NOTHING";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $id_nino]);
    header("Location: asistencia.php");
    exit;
}

// Registrar Salida / Retiro (POST para evitar mutaciones por enlace/CSRF)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['salida_id'])) {
    $id_asis = intval($_POST['salida_id']);
    $sql = "UPDATE public.guarderia_asistencia 
            SET hora_salida = CURRENT_TIME, estatus = 'Retirado' 
            WHERE id_asistencia = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $id_asis]);
    header("Location: asistencia.php");
    exit;
}

// Consultar lista de asistencia del día
$sql_asis = "SELECT n.id_nino, n.id_representante, n.nombre_nino, n.apellido_nino, n.sexo,
                    u.nombre AS nombre_rep, u.cedula AS cedula_rep, u.telefono AS telefono_rep,
                    a.id_asistencia, a.hora_entrada, a.hora_salida, a.estatus
             FROM public.guarderia_ninos n
             JOIN public.usuarios u ON n.id_representante = u.id_usuario
             LEFT JOIN public.guarderia_asistencia a ON n.id_nino = a.id_nino AND a.fecha_asistencia = CURRENT_DATE
             ORDER BY n.nombre_nino ASC";
$asistencias = $pdo->query($sql_asis)->fetchAll();
?>

<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <h1 class="h3 mb-0 font-weight-bold" style="color: #521f29;">
        <i class="fas fa-clipboard-check mr-2 text-warning"></i>Control Diario de Asistencia - Centro de Cuidados Infantiles 
    </h1>
    <div>
        <span class="badge badge-warning text-dark font-weight-bold px-3 py-2 mr-2" style="font-size: 10pt;">
            FECHA HOY: <?= date('d/m/Y') ?>
        </span>
        <a href="index.php" class="btn btn-sm btn-secondary font-weight-bold shadow-sm">
            <i class="fas fa-arrow-left mr-1"></i> Volver a Matrícula
        </a>
    </div>
</div>

<div class="card shadow mb-4" style="border-radius: 15px; border: none;">
    <div class="card-header py-3" style="background-color: #521f29; border-top-left-radius: 15px; border-top-right-radius: 15px;">
        <h6 class="m-0 font-weight-bold text-white"><i class="fas fa-clock mr-2"></i>Marcaje de Entrada y Retiro</h6>
    </div>
    <div class="card-body bg-white p-4">
        <input type="hidden" id="csrf_asistencia" value="<?= csrf_token() ?>">
        <div id="feedback_asistencia" class="mb-2"></div>
        <?php buscador_lista_render('Buscar niño/a o representante por nombre o cédula…', '#tablaAsistencia'); ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle" id="tablaAsistencia" width="100%">
                <thead style="background-color: #ff8a00; color: #521f29;">
                    <tr>
                        <th>Niño / Niña</th>
                        <th>Representante</th>
                        <th>Teléfono</th>
                        <th>Hora Entrada</th>
                        <th>Hora Salida</th>
                        <th>Estatus Hoy</th>
                        <th class="text-center">Acción Marcaje</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($asistencias as $a): 
                        $ubicacion_rep = representante_ubicacion($pdo, (int) $a['id_representante']);
                    ?>
                        <tr id="fila-<?= (int) $a['id_nino'] ?>">
                            <td class="font-weight-bold" style="color: #521f29; font-size: 11pt;">
                                <?= htmlspecialchars($a['nombre_nino'] . ' ' . $a['apellido_nino']) ?>
                            </td>
                            <td>
                                <strong><?= htmlspecialchars($a['nombre_rep']) ?></strong><br>
                                <small class="text-muted">C.I: V-<?= htmlspecialchars($a['cedula_rep']) ?></small><br>
                                 <small class="text-muted"><i class="fas fa-map-marker-alt mr-1 text-warning"></i><?= htmlspecialchars($ubicacion_rep['texto']) ?></small>
                            </td>
                            <td><?= htmlspecialchars($a['telefono_rep'] ?? 'S/D') ?></td>
                            <td id="hora-entrada-<?= (int) $a['id_nino'] ?>">
                                <?= $a['hora_entrada'] ? date('h:i:s A', strtotime($a['hora_entrada'])) : '<span class="text-muted">--:--</span>' ?>
                            </td>
                            <td id="hora-salida-<?= (int) $a['id_nino'] ?>">
                                <?= $a['hora_salida'] ? date('h:i:s A', strtotime($a['hora_salida'])) : '<span class="text-muted">--:--</span>' ?>
                            </td>
                            <td id="estatus-<?= (int) $a['id_nino'] ?>">
                                <?php if ($a['estatus'] === 'Presente'): ?>
                                    <span class="badge badge-success px-2 py-1"><i class="fas fa-check-circle mr-1"></i> En Centro de Cuidados Infantiles </span>
                                <?php elseif ($a['estatus'] === 'Retirado'): ?>
                                    <span class="badge badge-secondary px-2 py-1"><i class="fas fa-home mr-1"></i> Retirado</span>
                                <?php else: ?>
                                    <span class="badge badge-light border text-muted">Ausente</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center" id="accion-<?= (int) $a['id_nino'] ?>">
                                <?php if (!$a['id_asistencia']): ?>
                                    <form method="POST" class="d-inline form-marcar" data-tipo="entrada">
                                    <?= csrf_field() ?>
                                        <input type="hidden" name="entrada_id" value="<?= $a['id_nino'] ?>">
                                        <button type="submit" class="btn btn-sm btn-success font-weight-bold shadow-sm">
                                            <i class="fas fa-sign-in-alt mr-1"></i> MARCAR ENTRADA
                                        </button>
                                    </form>
                                <?php elseif ($a['estatus'] === 'Presente'): ?>
                                    <form method="POST" class="d-inline form-marcar" data-tipo="salida">
                                    <?= csrf_field() ?>
                                        <input type="hidden" name="salida_id" value="<?= $a['id_asistencia'] ?>">
                                        <button type="submit" class="btn btn-sm btn-warning font-weight-bold text-dark shadow-sm">
                                            <i class="fas fa-sign-out-alt mr-1"></i> MARCAR RETIRO
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span class="text-muted small font-weight-bold">Completado</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';
    var tabla = document.getElementById('tablaAsistencia');
    if (!tabla) { return; }
    var csrf = <?= json_encode(csrf_token()) ?>;
    var fb = document.getElementById('feedback_asistencia');

    function escapar(valor) {
        return String(valor == null ? '' : valor).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[c];
        });
    }

    function badge(estatus) {
        if (estatus === 'Presente') {
            return '<span class="badge badge-success px-2 py-1"><i class="fas fa-check-circle mr-1"></i> En Centro de Cuidados Infantiles</span>';
        }
        if (estatus === 'Retirado') {
            return '<span class="badge badge-secondary px-2 py-1"><i class="fas fa-home mr-1"></i> Retirado</span>';
        }
        return '<span class="badge badge-light border text-muted">Ausente</span>';
    }

    function botonRetiro(idAsistencia) {
        return '<form method="POST" class="d-inline form-marcar" data-tipo="salida">'
            + '<input type="hidden" name="_csrf" value="' + csrf + '">'
            + '<input type="hidden" name="salida_id" value="' + escapar(idAsistencia) + '">'
            + '<button type="submit" class="btn btn-sm btn-warning font-weight-bold text-dark shadow-sm">'
            + '<i class="fas fa-sign-out-alt mr-1"></i> MARCAR RETIRO</button></form>';
    }

    tabla.addEventListener('submit', function (e) {
        var form = e.target.closest('form.form-marcar');
        if (!form) { return; }
        e.preventDefault();

        var boton = form.querySelector('button[type="submit"]');
        if (!boton || boton.disabled) { return; }
        var htmlOriginal = boton.innerHTML;
        boton.disabled = true;
        boton.innerHTML = '<i class="fas fa-spinner fa-spin mr-1"></i> PROCESANDO...';

        fetch('api_marcar.php', {
            method: 'POST',
            body: new FormData(form),
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'fetch' }
        })
        .then(function (r) { return r.json().then(function (d) { return { status: r.status, data: d }; }); })
        .then(function (res) {
            var d = res.data;
            if (res.status >= 400 || !d.ok) { throw new Error(d.mensaje || 'No se pudo registrar el marcaje.'); }
            var id = d.id_nino;

            var he = document.getElementById('hora-entrada-' + id);
            var hs = document.getElementById('hora-salida-' + id);
            var es = document.getElementById('estatus-' + id);
            var ac = document.getElementById('accion-' + id);

            if (he) { he.innerHTML = d.hora_entrada ? escapar(d.hora_entrada) : '<span class="text-muted">--:--</span>'; }
            if (hs) { hs.innerHTML = d.hora_salida ? escapar(d.hora_salida) : '<span class="text-muted">--:--</span>'; }
            if (es) { es.innerHTML = badge(d.estatus); }
            if (ac) {
                ac.innerHTML = d.proxima_accion === 'salida'
                    ? botonRetiro(d.id_asistencia)
                    : '<span class="text-muted small font-weight-bold">Completado</span>';
            }
            if (fb) {
                fb.innerHTML = '<div class="alert alert-success py-1 px-2 mb-0 small">'
                    + escapar(d.mensaje) + '</div>';
            }
        })
        .catch(function (err) {
            if (fb) {
                fb.innerHTML = '<div class="alert alert-danger py-1 px-2 mb-0 small">'
                    + escapar(err.message || 'Error inesperado.') + '</div>';
            }
            boton.disabled = false;
            boton.innerHTML = htmlOriginal;
        });
    });
}());
</script>

<?php require_once '../../includes/footer.php'; ?>