<?php
// /sistema_nora/modulos/foros/mis_certificados.php
require_once '../../config/db.php';
require_once '../../includes/header.php';
require_once __DIR__ . '/_funciones.php';

$id_usuario = (int) ($_SESSION['id_usuario'] ?? 0);
if ($id_usuario <= 0) {
    echo "<div class='container-fluid pt-4'><div class='alert alert-danger font-weight-bold'>Debe iniciar sesión para ver sus certificados.</div></div>";
    require_once '../../includes/footer.php';
    exit();
}

$st = $pdo->prepare("SELECT fc.id_certificado_foro, fc.rol_certificado, ce.folio, ce.libro,
                            ce.linea_libro, ce.fecha_emision, ce.estado, ce.codigo_validacion,
                            f.nombre AS foro_nombre, f.codigo_foro, f.tipo_foro,
                            f.fecha_inicio, f.fecha_fin,
                            (SELECT COALESCE(SUM(t.horas_academicas), 0) FROM public.foro_temas t WHERE t.id_foro = f.id_foro) AS horas,
                            (SELECT COUNT(*) FROM public.foro_temas t WHERE t.id_foro = f.id_foro) AS temas
                       FROM public.foro_certificados fc
                       JOIN public.certificados_emitidos ce ON ce.id_certificado = fc.id_certificado_emitido
                       JOIN public.foro_participantes p ON p.id_participante = fc.id_participante
                       JOIN public.foros_eventos f ON f.id_foro = p.id_foro
                      WHERE p.id_estudiante = :u
                      ORDER BY ce.fecha_emision DESC");
$st->execute([':u' => $id_usuario]);
$certificados = $st->fetchAll(PDO::FETCH_ASSOC);
?>
<div class="container-fluid pt-3">
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 font-weight-bold" style="color:#521f29;">
            <i class="fas fa-certificate mr-2 text-warning"></i>Mis Certificados de Foros, Conversatorios y Seminarios
        </h1>
        <div>
            <a href="mis_foros.php" class="btn btn-sm btn-secondary font-weight-bold shadow-sm mr-2" style="border-radius:8px;">
                <i class="fas fa-arrow-left mr-1"></i> Mis Foros
            </a>
            <a href="../../modulos/estudiante/expediente_academico.php" class="btn btn-sm btn-primary font-weight-bold shadow-sm" style="border-radius:8px;">
                <i class="fas fa-folder-open mr-1"></i> Mi Expediente Académico
            </a>
        </div>
    </div>

    <?php if (isset($_GET['msg'])): ?>
        <div class="alert alert-success font-weight-bold"><?= htmlspecialchars(urldecode($_GET['msg'])) ?></div>
    <?php endif; ?>

    <?php if (!$certificados): ?>
        <div class="alert alert-info font-weight-bold">
            Todavía no tiene certificados de foros, conversatorios ni seminarios. Cuando el módulo de Foros emita su certificado,
            podrá descargarlo y verificarlo aquí.
        </div>
    <?php else: ?>
        <div class="row">
            <?php foreach ($certificados as $c): ?>
                <div class="col-lg-6 mb-4">
                    <div class="card shadow border-0 h-100" style="border-radius:15px;">
                        <div class="card-header text-white d-flex justify-content-between align-items-center font-weight-bold"
                             style="background-color:#521f29; border-radius:15px 15px 0 0;">
                            <span><?= htmlspecialchars($c['foro_nombre']) ?></span>
                            <span class="badge badge-<?= $c['rol_certificado'] === 'Participante' ? 'warning' : 'info' ?>">
                                <?= htmlspecialchars($c['rol_certificado']) ?>
                            </span>
                        </div>
                        <div class="card-body bg-white p-4">
                            <p class="mb-2"><strong>Código del evento:</strong> <?= htmlspecialchars($c['codigo_foro']) ?>
                                &nbsp;·&nbsp; <?= htmlspecialchars($c['tipo_foro']) ?></p>
                            <p class="mb-2"><strong>Folio:</strong> <?= htmlspecialchars($c['folio']) ?>
                                &nbsp;|&nbsp; <strong>Libro:</strong> <?= htmlspecialchars($c['libro']) ?>
                                &nbsp;|&nbsp; <strong>Línea:</strong> <?= (int) $c['linea_libro'] ?></p>
                            <p class="mb-2"><strong>Fecha de emisión:</strong>
                                <?= !empty($c['fecha_emision']) ? date('d/m/Y', strtotime($c['fecha_emision'])) : '—' ?></p>
                            <p class="mb-2"><strong>Horas certificadas:</strong> <?= (int) $c['horas'] ?> h
                                &nbsp;·&nbsp; <strong>Temas en la malla:</strong> <?= (int) $c['temas'] ?></p>
                            <p class="mb-3"><strong>Estado:</strong>
                                <span class="badge badge-<?= strtolower((string) $c['estado']) === 'emitido' ? 'success' : 'danger' ?>">
                                    <?= htmlspecialchars($c['estado']) ?>
                                </span>
                            </p>
                            <div class="d-flex flex-wrap align-items-center">
                                <?php if (strtolower((string) $c['estado']) === 'emitido'): ?>
                                    <a href="certificado_pdf.php?id=<?= (int) $c['id_certificado_foro'] ?>"
                                       class="btn btn-sm font-weight-bold text-white mr-2 mb-2" style="background-color:#ff8a00; border-radius:8px;">
                                        <i class="fas fa-download mr-1"></i> Descargar PDF
                                    </a>
                                <?php endif; ?>
                                <a href="<?= $path_prefix ?>validar_certificado.php?folio=<?= urlencode($c['folio']) ?>&codigo=<?= urlencode($c['codigo_validacion']) ?>"
                                   target="_blank" class="btn btn-sm btn-outline-primary font-weight-bold mb-2" style="border-radius:8px;">
                                    <i class="fas fa-qrcode mr-1"></i> Verificar autenticidad
                                </a>
                            </div>
                            <small class="text-muted d-block mt-2">
                                Código de validación: <?= htmlspecialchars($c['codigo_validacion']) ?>
                            </small>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="alert alert-light border font-weight-bold">
        <i class="fas fa-shield-alt mr-2"></i>
        Cada certificado queda registrado en el libro físico de la institución con su folio y línea,
        y forma parte de su record académico. El reverso del PDF contiene el código QR de validación,
        los temas de la malla y las horas académicas.
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>
