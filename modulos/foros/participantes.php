<?php
// /sistema_nora/modulos/foros/participantes.php
require_once '../../config/db.php';
require_once '../../includes/header.php';
require_once '../../includes/buscador_lista.php';
require_once __DIR__ . '/_funciones.php';

if (!foro_puede_administrar()) {
    echo "<div class='container-fluid pt-4'><div class='alert alert-danger font-weight-bold'>Acceso restringido al módulo de Foros, Conversatorios y Seminarios.</div></div>";
    require_once '../../includes/footer.php';
    exit();
}

$id_foro = intval($_GET['id'] ?? 0);
$puede_preregistrar = tiene_permiso(['superadmin', 'admin', 'control_estudio', 'recepcion'], $roles_array);

// La inscripción la hace Recepción. Este módulo solo consulta a los inscritos
// y los organiza en mesas de trabajo.
$eventos = $pdo->query("SELECT id_foro, codigo_foro, nombre FROM public.foros_eventos ORDER BY fecha_inicio DESC NULLS LAST, id_foro DESC")->fetchAll(PDO::FETCH_ASSOC);
$evento = foro_evento($pdo, $id_foro);

$participantes = [];
if ($id_foro > 0) {
    $sql = "SELECT p.*, u.nombre, u.apellido, u.cedula, u.email, u.correo,
                   (SELECT string_agg(a.tipo_asistencia, ', ' ORDER BY a.tipo_asistencia)
                      FROM public.foro_asistencias a WHERE a.id_participante = p.id_participante) AS asistencias,
                   (SELECT string_agg(m.numero_mesa::text, ', ' ORDER BY m.numero_mesa)
                      FROM public.foro_mesa_participantes mp
                      JOIN public.foro_mesas m ON m.id_mesa = mp.id_mesa
                     WHERE mp.id_participante = p.id_participante) AS mesas,
                   (SELECT fc.rol_certificado FROM public.foro_certificados fc
                      WHERE fc.id_participante = p.id_participante LIMIT 1) AS certificado
              FROM public.foro_participantes p
              JOIN public.usuarios u ON u.id_usuario = p.id_estudiante
             WHERE p.id_foro = :f
             ORDER BY u.apellido, u.nombre";
    $st = $pdo->prepare($sql);
    $st->execute([':f' => $id_foro]);
    $participantes = $st->fetchAll(PDO::FETCH_ASSOC);
}
?>
<div class="container-fluid pt-3">
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 font-weight-bold" style="color:#521f29;">
            <i class="fas fa-users mr-2 text-warning"></i>Participantes del Evento (inscripción desde Recepción)
        </h1>
        <a href="index.php" class="btn btn-sm btn-secondary font-weight-bold shadow-sm" style="border-radius:8px;">
            <i class="fas fa-arrow-left mr-1"></i> Volver
        </a>
    </div>

    <?php if (isset($_GET['msg'])): ?>
        <div class="alert alert-<?= ($_GET['tipo'] ?? 'success') === 'error' ? 'danger' : 'success' ?> font-weight-bold">
            <?= htmlspecialchars(urldecode($_GET['msg'])) ?>
        </div>
    <?php endif; ?>

    <div class="card shadow border-0 mb-4" style="border-radius:15px;">
        <div class="card-body bg-white p-4">
            <form method="GET" class="form-inline">
                <label class="font-weight-bold mr-2">Evento:</label>
                <select name="id" class="form-control mr-2" onchange="this.form.submit()" style="min-width:320px;">
                    <option value="0">-- Seleccione un evento --</option>
                    <?php foreach ($eventos as $e): ?>
                        <option value="<?= (int) $e['id_foro'] ?>" <?= $id_foro === (int) $e['id_foro'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($e['codigo_foro'] . ' · ' . $e['nombre']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($id_foro > 0): ?>
                    <a href="asistencias.php?id=<?= (int) $id_foro ?>" class="btn btn-sm btn-success ml-auto font-weight-bold">Asistencia Interna</a>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <?php if ($id_foro > 0): ?>
    <div class="row mb-4">
        <div class="col-lg-5 mb-3">
            <div class="card shadow border-0 h-100" style="border-radius:15px;">
                <div class="card-header font-weight-bold text-white" style="background-color:#521f29; border-radius:15px 15px 0 0;">
                    <i class="fas fa-info-circle mr-1"></i> ¿Cómo se inscribe un participante?
                </div>
                <div class="card-body bg-white p-4">
                    <p class="font-weight-bold" style="color:#521f29;">
                        La inscripción y el pre-registro los realiza <strong>Recepción</strong> con la cédula del estudiante.
                    </p>
                    <p class="text-muted mb-3">
                        En este módulo solo se consulta la lista y se organiza a los inscritos en mesas de trabajo.
                        Así se evita duplicar participantes y se mantiene un solo registro académico.
                    </p>
                    <?php if ($puede_preregistrar): ?>
                        <a href="../recepcion/registrar_estudiante_pernocta.php"
                           class="btn font-weight-bold text-white shadow-sm" style="background-color:#ff8a00; border-radius:8px;">
                            <i class="fas fa-user-check mr-1"></i> Ir al pre-registro de Recepción
                        </a>
                    <?php else: ?>
                        <div class="alert alert-light border font-weight-bold mb-0">
                            Solicite el pre-registro al personal de Recepción.
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-7 mb-3">
            <div class="card shadow border-0 h-100" style="border-radius:15px;">
                <div class="card-header font-weight-bold text-white" style="background-color:#ff8a00; border-radius:15px 15px 0 0;">
                    <?= htmlspecialchars($evento['codigo_foro'] ?? '') ?> · <?= htmlspecialchars($evento['nombre'] ?? '') ?>
                </div>
                <div class="card-body bg-white p-4">
                    <p class="mb-2"><strong>Tipo:</strong> <?= htmlspecialchars($evento['tipo_foro'] ?? '—') ?></p>
                    <p class="mb-2"><strong>Fechas:</strong>
                        <?= !empty($evento['fecha_inicio']) ? date('d/m/Y', strtotime($evento['fecha_inicio'])) : '—' ?>
                        <?= !empty($evento['fecha_fin']) ? ' al ' . date('d/m/Y', strtotime($evento['fecha_fin'])) : '' ?>
                    </p>
                    <p class="mb-2"><strong>Horas de la malla:</strong> <?= (int) ($evento['horas_temas'] ?? $evento['horas_academicas'] ?? 0) ?> h</p>
                    <p class="mb-0"><strong>Total de participantes:</strong> <?= count($participantes) ?></p>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow border-0" style="border-radius:15px;">
        <div class="card-header text-white font-weight-bold" style="background-color:#521f29; border-radius:15px 15px 0 0;">
            Lista de participantes (estudiantes)
        </div>
        <div class="card-body bg-white p-4">
            <?php if ($participantes) { buscador_lista_render('Buscar por nombre o cédula…', '#tablaParticipantesForo table'); } ?>
            <?php if (!$participantes): ?>
                <div class="alert alert-info mb-0">No hay participantes inscritos en este evento.</div>
            <?php else: ?>
                <div class="table-responsive" id="tablaParticipantesForo">
                    <table class="table table-hover table-sm">
                        <thead class="thead-light">
                            <tr><th>Estudiante</th><th>Cédula</th><th>Correo</th><th>Asistencias</th><th>Mesa(s)</th><th>Certificado</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($participantes as $p): ?>
                            <tr>
                                <td class="font-weight-bold"><?= htmlspecialchars(trim($p['nombre'] . ' ' . $p['apellido'])) ?></td>
                                <td><?= htmlspecialchars($p['cedula']) ?></td>
                                <td><?= htmlspecialchars($p['email'] ?: ($p['correo'] ?? '—')) ?></td>
                                <td><?= htmlspecialchars($p['asistencias'] ?? '—') ?></td>
                                <td><?= $p['mesas'] !== null && $p['mesas'] !== '' ? 'Mesa ' . htmlspecialchars($p['mesas']) : '—' ?></td>
                                <td><?= !empty($p['certificado']) ? '<span class="badge badge-success">' . htmlspecialchars($p['certificado']) . '</span>' : '<span class="badge badge-secondary">Sin emitir</span>' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php else: ?>
        <div class="alert alert-warning font-weight-bold">Seleccione un evento para ver y gestionar sus participantes.</div>
    <?php endif; ?>
</div>

<?php require_once '../../includes/footer.php'; ?>
