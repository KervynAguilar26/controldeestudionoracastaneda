<?php
// /sistema_nora/validar_certificado.php
// Verificación pública por QR / folio: confirma que el documento es oficial y
// que el estudiante pertenece a la Escuela "Nora Castañeda".

require_once 'config/db.php';
require_once 'includes/academico.php';
require_once 'includes/qr_generator.php';
require_once 'includes/certificado_pdf.php';

$folio = trim($_GET['folio'] ?? '');
$error = '';
$resultado = null;   // ['tipo' => ..., 'registro' => ...]

if ($folio !== '') {
    try {
        $prefijo = '';
        if (preg_match('/^([A-Za-z]+)-/', $folio, $mm)) {
            $prefijo = strtoupper($mm[1]);
        }

        // 1) Certificados de culminación emitidos (folio CERT-AAAA-00000)
        if ($prefijo === 'CERT') {
            $cert = certificado_por_folio($pdo, $folio);
            if ($cert) {
                $estado = academico_estado_malla($pdo, (int) $cert['id_estudiante'], (int) $cert['id_malla']);
                $resultado = [
                    'tipo'     => 'Certificado de Culminación de Estudios',
                    'registro' => [
                        'folio'            => $cert['folio'],
                        'nombre'           => $cert['nombre'],
                        'apellido'         => $cert['apellido'],
                        'cedula'           => $cert['cedula'],
                        'nombre_curso'     => $cert['nombre_programa'],
                        'tipo_oferta'      => $cert['tipo_oferta'],
                        'horas_academicas' => $cert['horas_academicas'],
                        'fecha_emision'    => $cert['fecha_emision'],
                        'fecha_inscripcion'=> $cert['fecha_inscripcion'] ?? null,
                        'resultado'        => 'Malla curricular completada (' . $estado['aprobadas'] . '/' . $estado['total_materias'] . ' materias)',
                        'cohorte'          => $cert['codigo_cohorte'] ?? '—',
                        'salon'            => $cert['salon_aula'] ?? '—',
                        'periodo'          => (int) ($cert['num_periodo'] ?? 0),
                        'total_periodos'   => (int) $cert['total_periodos'],
                        'estado_malla'     => $estado['completado'] ? 'COMPLETADA' : 'EN PROGRESO',
                        'total_materias'   => $estado['total_materias'],
                        'aprobadas'        => $estado['aprobadas'],
                    ],
                ];
            }
        }

        // 2) Constancias y boletas (folio registrado en inscripciones)
        if (!$resultado) {
            // BOL- y CONST- comparten el mismo identificador de inscripción:
            // si no se encuentra exactamente, se prueban los sufijos equivalentes.
            $candidatos = [$folio];
            if (in_array($prefijo, ['BOL', 'CONST'], true)) {
                $sufijo = substr($folio, strlen($prefijo));
                $candidatos[] = 'CONST' . $sufijo;
                $candidatos[] = 'BOL' . $sufijo;
            }
            $candidatos = array_values(array_unique($candidatos));
            $marcas = implode(',', array_fill(0, count($candidatos), '?'));

            $sql = "SELECT i.folio_certificado, i.nota_cualitativa, i.fecha_inscripcion, i.id_inscripcion,
                           u.nombre, u.apellido, u.cedula,
                           m.nombre_programa AS nombre_curso,
                           COALESCE(SUM(mm.horas_academicas), 0) AS horas_academicas,
                           co.codigo_cohorte, co.salon_aula, co.num_periodo, co.estatus AS estatus_cohorte
                      FROM public.inscripciones i
                      JOIN public.usuarios u ON i.id_estudiante = u.id_usuario
                      JOIN public.cohortes co ON i.id_curso = co.id_cohorte
                      JOIN public.mallas_curriculares m ON co.id_malla = m.id_malla
                      LEFT JOIN public.malla_materias mm ON mm.id_malla = m.id_malla
                     WHERE i.folio_certificado IN (" . $marcas . ")
                     GROUP BY i.folio_certificado, i.nota_cualitativa, i.fecha_inscripcion, i.id_inscripcion,
                              u.nombre, u.apellido, u.cedula, m.nombre_programa,
                              co.codigo_cohorte, co.salon_aula, co.num_periodo, co.estatus
                     LIMIT 1";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($candidatos);
            $ins = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($ins) {
                $tipo = ($prefijo === 'CONST') ? 'Constancia de Estudio'
                      : (($prefijo === 'BOL') ? 'Boleta de Calificaciones' : 'Documento Institucional');

                $estado = null;
                $stmtEst = $pdo->prepare("SELECT i.id_estudiante, co.id_malla FROM public.inscripciones i
                                           JOIN public.cohortes co ON co.id_cohorte = i.id_curso
                                          WHERE i.id_inscripcion = :i");
                $stmtEst->execute([':i' => $ins['id_inscripcion']]);
                $ref = $stmtEst->fetch();
                if ($ref) {
                    $estado = academico_estado_malla($pdo, (int) $ref['id_estudiante'], (int) $ref['id_malla']);
                }

                $resultado = [
                    'tipo'     => $tipo,
                    'registro' => [
                        'folio'            => $ins['folio_certificado'],
                        'nombre'           => $ins['nombre'],
                        'apellido'         => $ins['apellido'],
                        'cedula'           => $ins['cedula'],
                        'nombre_curso'     => $ins['nombre_curso'],
                        'tipo_oferta'      => '',
                        'horas_academicas' => $ins['horas_academicas'],
                        'fecha_emision'    => $ins['fecha_inscripcion'],
                        'fecha_inscripcion'=> $ins['fecha_inscripcion'],
                        'resultado'        => $ins['nota_cualitativa'] ?: 'En Progreso',
                        'cohorte'          => $ins['codigo_cohorte'],
                        'salon'            => $ins['salon_aula'],
                        'periodo'          => (int) $ins['num_periodo'],
                        'total_periodos'   => $estado ? $estado['total_periodos'] : 0,
                        'estado_malla'     => $estado ? ($estado['completado'] ? 'COMPLETADA' : 'EN PROGRESO') : '—',
                        'total_materias'   => $estado ? $estado['total_materias'] : 0,
                        'aprobadas'        => $estado ? $estado['aprobadas'] : 0,
                        'estatus_cohorte'  => $ins['estatus_cohorte'],
                    ],
                ];
            }
        }

        // 3) Respaldo: modelo antiguo de cursos
        if (!$resultado) {
            $sql_ant = "SELECT i.folio_certificado, i.nota_cualitativa, i.fecha_inscripcion,
                               u.nombre, u.apellido, u.cedula,
                               c.nombre_curso, c.horas_academicas, c.fecha_fin
                          FROM public.inscripciones i
                          JOIN public.usuarios u ON i.id_estudiante = u.id_usuario
                          JOIN public.cursos c ON i.id_curso = c.id_curso
                         WHERE i.folio_certificado = :folio LIMIT 1";
            $stmt_ant = $pdo->prepare($sql_ant);
            $stmt_ant->execute([':folio' => $folio]);
            $antiguo = $stmt_ant->fetch(PDO::FETCH_ASSOC);

            if ($antiguo) {
                $resultado = [
                    'tipo'     => 'Documento Institucional',
                    'registro' => [
                        'folio'            => $antiguo['folio_certificado'],
                        'nombre'           => $antiguo['nombre'],
                        'apellido'         => $antiguo['apellido'],
                        'cedula'           => $antiguo['cedula'],
                        'nombre_curso'     => $antiguo['nombre_curso'],
                        'tipo_oferta'      => '',
                        'horas_academicas' => $antiguo['horas_academicas'],
                        'fecha_emision'    => $antiguo['fecha_inscripcion'],
                        'fecha_inscripcion'=> $antiguo['fecha_inscripcion'],
                        'resultado'        => $antiguo['nota_cualitativa'] ?: '—',
                        'cohorte'          => '—',
                        'salon'            => '—',
                        'periodo'          => 0,
                        'total_periodos'   => 0,
                        'estado_malla'     => '—',
                        'total_materias'   => 0,
                        'aprobadas'        => 0,
                        'estatus_cohorte'  => '—',
                    ],
                ];
            }
        }

        if (!$resultado) {
            $error = "El número de Folio ingresado no corresponde a ningún documento emitido por la institución.";
        }
    } catch (Exception $e) {
        $error = "Error al consultar la base de datos. Intente nuevamente.";
    }
}

$qrUrl = ($resultado && $folio !== '') ? sistema_qr_url($folio) : '';
$qrImg = ($qrUrl !== '') ? sistema_qr_data_uri($qrUrl, 'val') : '';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Validación de Certificados - Escuela Nora Castañeda</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        body { background-color: #d6c4af; font-family: 'Nunito', sans-serif; }
        .card-validador { border-radius: 20px; border: none; box-shadow: 0 10px 30px rgba(0,0,0,0.15); }
        .header-nora { background-color: #521f29; border-bottom: 4px solid #ff8a00; border-top-left-radius: 20px; border-top-right-radius: 20px; }
        .bandera-pertenece { background-color: #1e7e34; color: #fff; border-radius: 10px; }
        .qr-verificacion img { width: 130px; height: 130px; border: 3px solid #521f29; border-radius: 8px; background: #fff; }
    </style>
</head>
<body>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-8 col-lg-6">
            <div class="card card-validador">
                <div class="header-nora p-4 text-center text-white">
                    <img src="assets/img/LogoEscuelaNora_NUEVO.PNG" alt="Logo" style="max-height: 60px;" class="mb-2" onerror="this.style.display='none'">
                    <h5 class="font-weight-bold mb-0">ESCUELA INTERNACIONAL "NORA CASTAÑEDA"</h5>
                    <small class="text-warning font-weight-bold">SISTEMA PÚBLICO DE VERIFICACIÓN DE CERTIFICADOS</small>
                </div>

                <div class="card-body bg-white p-4">
                    <form method="GET" action="validar_certificado.php" class="mb-4">
                        <label class="font-weight-bold" style="color: #521f29;">Número de Folio / Código de Verificación:</label>
                        <div class="input-group">
                            <input type="text" name="folio" class="form-control font-weight-bold text-center" placeholder="Ej: CERT-2026-00001" value="<?= htmlspecialchars($folio, ENT_QUOTES, 'UTF-8') ?>" required>
                            <div class="input-group-append">
                                <button type="submit" class="btn text-white font-weight-bold" style="background-color: #ff8a00;">
                                    <i class="fas fa-search mr-1"></i> VERIFICAR
                                </button>
                            </div>
                        </div>
                    </form>

                    <?php if (!empty($error)): ?>
                        <div class="alert alert-danger font-weight-bold text-center">
                            <i class="fas fa-exclamation-triangle mr-2"></i> <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($resultado): $r = $resultado['registro']; ?>
                        <div class="p-3 rounded border text-center" style="background-color: #fcf8f2; border-color: #ff8a00 !important;">
                            <span class="badge badge-success px-3 py-2 font-weight-bold mb-3" style="font-size: 11pt;">
                                <i class="fas fa-check-circle mr-1"></i> DOCUMENTO OFICIAL VÁLIDO
                            </span>
                            <div class="badge badge-dark px-3 py-2 font-weight-bold mb-3" style="font-size: 10pt; background-color:#521f29;">
                                <?= htmlspecialchars($resultado['tipo'], ENT_QUOTES, 'UTF-8') ?>
                            </div>

                            <h5 class="font-weight-bold text-uppercase mb-1" style="color: #521f29;">
                                <?= htmlspecialchars(mb_strtoupper($r['nombre'] . ' ' . $r['apellido'], 'UTF-8'), ENT_QUOTES, 'UTF-8') ?>
                            </h5>
                            <p class="font-weight-bold text-muted mb-2">Cédula: V-<?= htmlspecialchars($r['cedula'], ENT_QUOTES, 'UTF-8') ?></p>

                            <!-- PERTENENCIA A LA ESCUELA -->
                            <div class="bandera-pertenece p-2 mb-3 font-weight-bold">
                                <i class="fas fa-shield-alt mr-1"></i>
                                EL ESTUDIANTE PERTENECE A ESTA ESCUELA
                                <div class="small font-weight-normal mt-1">
                                    Inscrito en la cohorte
                                    <strong><?= htmlspecialchars($r['cohorte'], ENT_QUOTES, 'UTF-8') ?></strong>
                                    <?php if (!empty($r['salon']) && $r['salon'] !== '—'): ?>
                                        · Salón <strong><?= htmlspecialchars($r['salon'], ENT_QUOTES, 'UTF-8') ?></strong>
                                    <?php endif; ?>
                                    <?php if (!empty($r['periodo'])): ?>
                                        · Período <strong><?= (int) $r['periodo'] ?> de <?= (int) $r['total_periodos'] ?></strong>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <hr style="border-top: 1px dashed #ff8a00;">

                            <p class="mb-1"><small class="text-muted">Programa Aprobado:</small></p>
                            <h6 class="font-weight-bold" style="color: #521f29;"><?= htmlspecialchars($r['nombre_curso'], ENT_QUOTES, 'UTF-8') ?></h6>

                            <div class="row mt-3">
                                <div class="col-6">
                                    <small class="text-muted d-block">Duración:</small>
                                    <strong><?= (int) $r['horas_academicas'] ?> Horas</strong>
                                </div>
                                <div class="col-6">
                                    <small class="text-muted d-block">Resultado:</small>
                                    <strong class="text-success"><?= htmlspecialchars($r['resultado'], ENT_QUOTES, 'UTF-8') ?></strong>
                                </div>
                            </div>

                            <div class="row mt-3">
                                <div class="col-6">
                                    <small class="text-muted d-block">Avance de malla:</small>
                                    <strong><?= (int) $r['aprobadas'] ?> / <?= (int) $r['total_materias'] ?> materias</strong>
                                </div>
                                <div class="col-6">
                                    <small class="text-muted d-block">Estado:</small>
                                    <strong class="text-<?= $r['estado_malla'] === 'COMPLETADA' ? 'success' : 'warning' ?>">
                                        <?= htmlspecialchars($r['estado_malla'], ENT_QUOTES, 'UTF-8') ?>
                                    </strong>
                                </div>
                            </div>

                            <div class="mt-3 pt-2 border-top">
                                <small class="text-muted d-block">Folio de Registro: <strong style="color:#521f29;"><?= htmlspecialchars($r['folio'], ENT_QUOTES, 'UTF-8') ?></strong></small>
                                <?php if (!empty($r['fecha_emision'])): ?>
                                    <small class="text-muted d-block">Fecha del documento: <strong><?= academico_fecha($r['fecha_emision']) ?></strong></small>
                                <?php endif; ?>
                            </div>

                            <?php if ($qrImg !== ''): ?>
                                <div class="qr-verificacion mt-3">
                                    <img src="<?= $qrImg ?>" alt="QR de validación">
                                    <div class="small text-muted font-weight-bold mt-1">Código de verificación</div>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <div class="small text-muted text-center mt-3">
                        <i class="fas fa-info-circle mr-1"></i>
                        Folios válidos: <strong>CERT-... (certificados de culminación)</strong>,
                        <strong>CONST-... (constancias)</strong>, <strong>BOL-... (boletas de calificaciones)</strong>.
                    </div>
                </div>

                <div class="card-footer bg-light text-center py-3" style="border-bottom-left-radius: 20px; border-bottom-right-radius: 20px;">
                    <small class="text-muted font-weight-bold">Ministerio del Poder Popular para la Mujer y la Igualdad de Género</small>
                </div>
            </div>
        </div>
    </div>
</div>

</body>
</html>
