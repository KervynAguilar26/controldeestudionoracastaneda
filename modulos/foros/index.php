<?php
// /sistema_nora/modulos/foros/index.php
require_once '../../config/db.php';
require_once '../../includes/header.php';
require_once __DIR__ . '/_funciones.php';

if (!foro_puede_administrar()) {
    if (foro_es_estudiante()) {
        header('Location: mis_foros.php');
        exit();
    }
    echo "<div class='container-fluid pt-4'><div class='alert alert-danger font-weight-bold'>Acceso restringido al módulo de Foros, Conversatorios y Seminarios.</div></div>";
    require_once '../../includes/footer.php';
    exit();
}

$eventos = $pdo->query("
    SELECT f.*,
           (SELECT COUNT(*) FROM public.foro_participantes p WHERE p.id_foro = f.id_foro) AS total_participantes,
           (SELECT COUNT(*) FROM public.foro_mesas m WHERE m.id_foro = f.id_foro) AS total_mesas,
           (SELECT COALESCE(SUM(t.horas_academicas), 0) FROM public.foro_temas t WHERE t.id_foro = f.id_foro) AS horas_temas,
           (SELECT COUNT(*) FROM public.foro_certificados c
              JOIN public.foro_participantes p ON p.id_participante = c.id_participante
             WHERE p.id_foro = f.id_foro) AS total_certificados
      FROM public.foros_eventos f
     ORDER BY f.fecha_inicio DESC NULLS LAST, f.id_foro DESC
")->fetchAll(PDO::FETCH_ASSOC);

$total_eventos = count($eventos);
$total_participantes = array_sum(array_column($eventos, 'total_participantes'));
$total_mesas = array_sum(array_column($eventos, 'total_mesas'));
$total_certificados = array_sum(array_column($eventos, 'total_certificados'));
?>
<style>
    .foro-quick {
        border: none; border-radius: 12px; color: #fff !important; font-weight: 700;
        transition: all .25s ease; box-shadow: 0 4px 10px rgba(0,0,0,.12);
        display: flex; flex-direction: column; align-items: center; justify-content: center;
        padding: 18px 10px; min-height: 104px; text-align: center;
    }
    .foro-quick:hover { transform: translateY(-4px); box-shadow: 0 8px 20px rgba(0,0,0,.2); color: #fff !important; }
    .foro-q1 { background: linear-gradient(135deg,#521f29,#3a151d); }
    .foro-q2 { background: linear-gradient(135deg,#ff8a00,#e07600); }
    .foro-q3 { background: linear-gradient(135deg,#2b5876,#4e4376); }
    .foro-q4 { background: linear-gradient(135deg,#cd1e3e,#8e1229); }
    .foro-q5 { background: linear-gradient(135deg,#1e7e34,#146628); }
    .foro-q6 { background: linear-gradient(135deg,#6f42c1,#54309b); }
</style>

<div class="container-fluid pt-3">
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 font-weight-bold" style="color:#521f29;">
            <i class="fas fa-comments mr-2 text-warning"></i>Foros, Conversatorios y Seminarios
        </h1>
        <a href="gestionar_evento.php" class="btn btn-sm font-weight-bold text-white shadow-sm" style="background-color:#ff8a00; border-radius:8px;">
            <i class="fas fa-plus-circle mr-1"></i> Nuevo Evento
        </a>
    </div>

    <?php if (isset($_GET['msg'])): ?>
        <div class="alert alert-<?= ($_GET['tipo'] ?? 'success') === 'error' ? 'danger' : 'success' ?> alert-dismissible fade show font-weight-bold" role="alert">
            <?= htmlspecialchars(urldecode($_GET['msg']), ENT_QUOTES, 'UTF-8') ?>
            <button type="button" class="close" data-dismiss="alert"><span>&times;</span></button>
        </div>
    <?php endif; ?>

    <!-- MÉTRICAS -->
    <div class="row mb-4">
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card border-left-primary shadow h-100 py-2"><div class="card-body">
                <div class="row no-gutters align-items-center"><div class="col mr-2">
                    <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">Eventos</div>
                    <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $total_eventos ?></div>
                </div><div class="col-auto"><i class="fas fa-calendar-alt fa-2x text-gray-300"></i></div></div>
            </div></div>
        </div>
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card border-left-success shadow h-100 py-2"><div class="card-body">
                <div class="row no-gutters align-items-center"><div class="col mr-2">
                    <div class="text-xs font-weight-bold text-success text-uppercase mb-1">Participantes</div>
                    <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $total_participantes ?></div>
                </div><div class="col-auto"><i class="fas fa-users fa-2x text-gray-300"></i></div></div>
            </div></div>
        </div>
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card border-left-warning shadow h-100 py-2"><div class="card-body">
                <div class="row no-gutters align-items-center"><div class="col mr-2">
                    <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">Mesas de trabajo</div>
                    <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $total_mesas ?></div>
                </div><div class="col-auto"><i class="fas fa-table fa-2x text-gray-300"></i></div></div>
            </div></div>
        </div>
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card border-left-danger shadow h-100 py-2"><div class="card-body">
                <div class="row no-gutters align-items-center"><div class="col mr-2">
                    <div class="text-xs font-weight-bold text-danger text-uppercase mb-1">Certificados emitidos</div>
                    <div class="h5 mb-0 font-weight-bold text-gray-800"><?= $total_certificados ?></div>
                </div><div class="col-auto"><i class="fas fa-certificate fa-2x text-gray-300"></i></div></div>
            </div></div>
        </div>
    </div>

    <!-- BOTONES DE CADA SECCIÓN -->
    <div class="card shadow border-0 mb-4" style="border-radius:15px;">
        <div class="card-header text-white font-weight-bold" style="background-color:#521f29; border-radius:15px 15px 0 0;">
            Gestión del Módulo
        </div>
        <div class="card-body bg-white p-4">
            <div class="row">
                <div class="col-md-4 col-sm-6 mb-3"><a href="gestionar_evento.php" class="foro-quick foro-q1"><i class="fas fa-sitemap fa-2x mb-2"></i><span>Configurar Malla del Evento</span></a></div>
                <div class="col-md-4 col-sm-6 mb-3"><a href="participantes.php" class="foro-quick foro-q2"><i class="fas fa-users fa-2x mb-2"></i><span>Ver Participantes</span></a></div>
                <div class="col-md-4 col-sm-6 mb-3"><a href="asistencias.php" class="foro-quick foro-q3"><i class="fas fa-clipboard-check fa-2x mb-2"></i><span>Asistencia Interna</span></a></div>
                <div class="col-md-4 col-sm-6 mb-3"><a href="mesas.php" class="foro-quick foro-q4"><i class="fas fa-table fa-2x mb-2"></i><span>Mesas de Trabajo</span></a></div>
                <div class="col-md-4 col-sm-6 mb-3"><a href="informes.php" class="foro-quick foro-q5"><i class="fas fa-file-alt fa-2x mb-2"></i><span>Informes y PDF</span></a></div>
                <div class="col-md-4 col-sm-6 mb-3"><a href="certificados.php" class="foro-quick foro-q6"><i class="fas fa-award fa-2x mb-2"></i><span>Certificados y Correo</span></a></div>
            </div>
        </div>
    </div>

    <!-- LISTADO DE EVENTOS -->
    <div class="card shadow border-0" style="border-radius:15px;">
        <div class="card-header text-white font-weight-bold" style="background-color:#521f29; border-radius:15px 15px 0 0;">
            Eventos registrados
        </div>
        <div class="card-body bg-white p-4">
            <?php if (!$eventos): ?>
                <div class="alert alert-info font-weight-bold mb-0">Aún no hay foros, conversatorios ni seminarios registrados. Cree el primero desde “Nuevo Evento”.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover table-sm">
                        <thead class="thead-light">
                            <tr>
                                <th>Código</th><th>Evento</th><th>Tipo</th><th>Fechas</th><th>Horas</th>
                                <th>Participantes</th><th>Mesas</th><th>Cert.</th><th>Estatus</th><th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($eventos as $e): ?>
                                <tr>
                                    <td class="font-weight-bold"><?= htmlspecialchars($e['codigo_foro']) ?></td>
                                    <td><?= htmlspecialchars($e['nombre']) ?></td>
                                    <td><?= htmlspecialchars($e['tipo_foro']) ?></td>
                                    <td>
                                        <?= $e['fecha_inicio'] ? date('d/m/Y', strtotime($e['fecha_inicio'])) : '—' ?>
                                        <?php if (!empty($e['fecha_fin']) && $e['fecha_fin'] !== $e['fecha_inicio']): ?>
                                            al <?= date('d/m/Y', strtotime($e['fecha_fin'])) ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= (int) ($e['horas_temas'] ?: $e['horas_academicas']) ?> h</td>
                                    <td><?= (int) $e['total_participantes'] ?></td>
                                    <td><?= (int) $e['total_mesas'] ?></td>
                                    <td><?= (int) $e['total_certificados'] ?></td>
                                    <td><span class="badge badge-<?= $e['estatus'] === 'Finalizado' ? 'secondary' : ($e['estatus'] === 'Activo' ? 'success' : 'warning') ?>"><?= htmlspecialchars($e['estatus']) ?></span></td>
                                    <td class="text-nowrap">
                                        <a href="gestionar_evento.php?id=<?= (int) $e['id_foro'] ?>" class="btn btn-sm btn-outline-primary" title="Editar evento y malla"><i class="fas fa-edit"></i></a>
                                        <a href="participantes.php?id=<?= (int) $e['id_foro'] ?>" class="btn btn-sm btn-outline-success" title="Participantes"><i class="fas fa-users"></i></a>
                                        <a href="mesas.php?id=<?= (int) $e['id_foro'] ?>" class="btn btn-sm btn-outline-warning" title="Mesas"><i class="fas fa-table"></i></a>
                                        <a href="informes.php?id=<?= (int) $e['id_foro'] ?>" class="btn btn-sm btn-outline-info" title="Informes"><i class="fas fa-file-alt"></i></a>
                                        <a href="certificados.php?id=<?= (int) $e['id_foro'] ?>" class="btn btn-sm btn-outline-danger" title="Certificados"><i class="fas fa-certificate"></i></a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>
