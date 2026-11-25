<?php
// /sistema_nora/modulos/foros/mis_foros.php
require_once '../../config/db.php';
require_once '../../includes/header.php';
require_once __DIR__ . '/_funciones.php';

$id_usuario = (int) ($_SESSION['id_usuario'] ?? 0);
if ($id_usuario <= 0) {
    echo "<div class='container-fluid pt-4'><div class='alert alert-danger font-weight-bold'>Debe iniciar sesión para ver sus foros.</div></div>";
    require_once '../../includes/footer.php';
    exit();
}

$sql = "SELECT f.*, p.id_participante, p.estatus AS estatus_participante,
               (SELECT string_agg(a.tipo_asistencia, ', ' ORDER BY a.tipo_asistencia)
                  FROM public.foro_asistencias a WHERE a.id_participante = p.id_participante) AS asistencias,
               (SELECT string_agg('Mesa ' || m.numero_mesa, ', ' ORDER BY m.numero_mesa)
                  FROM public.foro_mesa_participantes mp
                  JOIN public.foro_mesas m ON m.id_mesa = mp.id_mesa
                 WHERE mp.id_participante = p.id_participante) AS mesas,
               (SELECT i.estatus FROM public.foro_informes i
                 WHERE i.id_participante = p.id_participante LIMIT 1) AS estatus_informe,
               (SELECT fc.rol_certificado FROM public.foro_certificados fc
                 WHERE fc.id_participante = p.id_participante LIMIT 1) AS certificado,
               (SELECT COALESCE(SUM(t.horas_academicas), 0) FROM public.foro_temas t
                  WHERE t.id_foro = f.id_foro) AS horas_temas
          FROM public.foro_participantes p
          JOIN public.foros_eventos f ON f.id_foro = p.id_foro
         WHERE p.id_estudiante = :u
         ORDER BY f.fecha_inicio DESC NULLS LAST, f.id_foro DESC";
$st = $pdo->prepare($sql);
$st->execute([':u' => $id_usuario]);
$foros = $st->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="container-fluid pt-3">
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 font-weight-bold" style="color:#521f29;">
            <i class="fas fa-comments mr-2 text-warning"></i>Mis Foros, Conversatorios y Seminarios
        </h1>
        <div>
            <a href="mis_certificados.php" class="btn btn-sm btn-success font-weight-bold shadow-sm mr-2" style="border-radius:8px;">
                <i class="fas fa-certificate mr-1"></i> Mis Certificados
            </a>
            <a href="../../modulos/estudiante/mi_aula_virtual.php" class="btn btn-sm btn-secondary font-weight-bold shadow-sm" style="border-radius:8px;">
                <i class="fas fa-arrow-left mr-1"></i> Mi Aula Virtual
            </a>
        </div>
    </div>

    <?php if (isset($_GET['msg'])): ?>
        <div class="alert alert-success font-weight-bold"><?= htmlspecialchars(urldecode($_GET['msg'])) ?></div>
    <?php endif; ?>

    <?php if (!$foros): ?>
        <div class="alert alert-info font-weight-bold">Todavía no está inscrito en ningún foro o conversatorio.</div>
    <?php else: ?>
        <div class="row">
            <?php foreach ($foros as $f): ?>
                <div class="col-lg-6 mb-4">
                    <div class="card shadow border-0 h-100" style="border-radius:15px;">
                        <div class="card-header text-white font-weight-bold d-flex justify-content-between" style="background-color:#521f29; border-radius:15px 15px 0 0;">
                            <span><?= htmlspecialchars($f['nombre']) ?></span>
                            <span class="badge badge-light"><?= htmlspecialchars($f['tipo_foro']) ?></span>
                        </div>
                        <div class="card-body bg-white p-4">
                            <p class="mb-2"><strong>Código:</strong> <?= htmlspecialchars($f['codigo_foro']) ?></p>
                            <p class="mb-2"><strong>Fechas:</strong>
                                <?= !empty($f['fecha_inicio']) ? date('d/m/Y', strtotime($f['fecha_inicio'])) : '—' ?>
                                <?= !empty($f['fecha_fin']) ? ' al ' . date('d/m/Y', strtotime($f['fecha_fin'])) : '' ?>
                            </p>
                            <p class="mb-2"><strong>Horas:</strong> <?= (int) ($f['horas_temas'] ?: $f['horas_academicas']) ?> h</p>
                            <p class="mb-2"><strong>Asistencias:</strong> <?= htmlspecialchars($f['asistencias'] ?: 'Sin registrar') ?></p>
                            <p class="mb-2"><strong>Mesa(s):</strong> <?= htmlspecialchars($f['mesas'] ?: 'No asignado') ?></p>
                            <p class="mb-3"><strong>Certificado:</strong>
                                <?php if (!empty($f['certificado'])): ?>
                                    <span class="badge badge-success"><?= htmlspecialchars($f['certificado']) ?></span>
                                <?php else: ?>
                                    <span class="badge badge-secondary">Pendiente</span>
                                <?php endif; ?>
                            </p>
                            <div class="d-flex flex-wrap">
                                <?php if (!empty($f['mesas'])): ?>
                                    <a href="mi_informe.php?id=<?= (int) $f['id_participante'] ?>" class="btn btn-sm font-weight-bold text-white mr-2 mb-2" style="background-color:#ff8a00; border-radius:8px;">
                                        <i class="fas fa-pen mr-1"></i>
                                        <?= $f['estatus_informe'] === 'Enviado' ? 'Ver mi informe' : 'Escribir mi informe' ?>
                                    </a>
                                <?php endif; ?>
                                <a href="mis_certificados.php" class="btn btn-sm btn-outline-success font-weight-bold mb-2" style="border-radius:8px;">
                                    <i class="fas fa-download mr-1"></i> Certificados
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="alert alert-light border font-weight-bold">
        <i class="fas fa-info-circle mr-2"></i>
        Todo lo que registre en sus foros (asistencias, mesa de trabajo e informe) queda guardado en su expediente académico.
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>
