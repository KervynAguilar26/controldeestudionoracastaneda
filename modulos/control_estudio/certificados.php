<?php
// /sistema_nora/modulos/control_estudio/certificados.php
// Centro de certificados: plantillas, libro/folio/línea, emisión, anulación,
// envío por correo, Excel de folios y descarga masiva en PDF.

require_once '../../config/db.php';
require_once '../../includes/academico.php';
require_once '../../includes/certificado_pdf.php';
require_once '../../includes/xlsx.php';
require_once '../../config/mailer.php';

$roles_usuario = $_SESSION['roles_asignados'] ?? [$_SESSION['tipo_usuario'] ?? ''];
$permitido = in_array('control_estudio', $roles_usuario, true)
          || in_array('admin', $roles_usuario, true)
          || in_array('superadmin', $roles_usuario, true);
if (!$permitido) {
    echo "<div class='alert alert-danger font-weight-bold'>Acceso restringido a Control de Estudio.</div>";
    exit();
}

function cert_ui_e($valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}

$config = certificado_config($pdo);
$mensaje = '';
$error = '';

/* --------------------------------------------------------- Excel temprano */
if (($_GET['action'] ?? '') === 'excel') {
    $filas = [];
    $filas[] = ['celdas' => [
        ['v' => 'N°', 's' => 1], ['v' => 'FOLIO', 's' => 1], ['v' => 'CÉDULA', 's' => 1],
        ['v' => 'ESTUDIANTE', 's' => 1], ['v' => 'PROGRAMA', 's' => 1], ['v' => 'LIBRO', 's' => 1],
        ['v' => 'LÍNEA', 's' => 1], ['v' => 'CÓDIGO DE VALIDACIÓN', 's' => 1],
        ['v' => 'FECHA DE EMISIÓN', 's' => 1], ['v' => 'ESTADO', 's' => 1],
    ]];
    $st = $pdo->query("SELECT ce.*, u.cedula, u.nombre, u.apellido, COALESCE(m.nombre_programa, fe.nombre) AS nombre_programa
                         FROM public.certificados_emitidos ce
                         JOIN public.usuarios u ON u.id_usuario = ce.id_estudiante
                         LEFT JOIN public.mallas_curriculares m ON m.id_malla = ce.id_malla
                         LEFT JOIN public.foros_eventos fe ON fe.id_foro = ce.id_foro
                        ORDER BY ce.linea_libro ASC, ce.id_certificado ASC");
    $n = 0;
    foreach ($st->fetchAll() as $f) {
        $n++;
        $filas[] = ['celdas' => [
            ['v' => $n], ['v' => $f['folio']], ['v' => $f['cedula']],
            ['v' => trim($f['nombre'] . ' ' . $f['apellido'])], ['v' => $f['nombre_programa']],
            ['v' => $f['libro']], ['v' => (int) $f['linea_libro']],
            ['v' => $f['codigo_validacion']], ['v' => $f['fecha_emision']], ['v' => $f['estado']],
        ]];
    }
    if ($n === 0) {
        $filas[] = ['celdas' => [['v' => 'No hay certificados emitidos todavía.', 'span' => 10]]];
    }
    $xlsx = xlsx_generar([
        'nombre' => 'Folios y Lineas',
        'anchos' => [7, 22, 15, 34, 34, 14, 10, 24, 22, 14],
        'filas'  => $filas,
    ]);
    if (!headers_sent()) {
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="Folios_Certificados_' . date('Ymd_His') . '.xlsx"');
        header('Content-Length: ' . strlen($xlsx));
        header('Cache-Control: private, max-age=0, must-revalidate');
    }
    echo $xlsx;
    exit;
}

/* ----------------------------------------------------------- subida de fondo */
function cert_subir_plantilla(array $archivo, string $prefijo): ?string
{
    if (empty($archivo['tmp_name']) || !is_uploaded_file($archivo['tmp_name'])) {
        return null;
    }
    if ((int) ($archivo['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new Exception('Error al subir la imagen (' . (int) $archivo['error'] . ').');
    }
    if ((int) ($archivo['size'] ?? 0) > 4 * 1024 * 1024) {
        throw new Exception('La imagen debe pesar menos de 4 MB.');
    }
    $info = @getimagesize($archivo['tmp_name']);
    $mime = $info['mime'] ?? '';
    if (!$info || !in_array($mime, ['image/jpeg', 'image/png'], true)) {
        throw new Exception('La plantilla debe ser un archivo JPG o PNG válido.');
    }
    $ext = $mime === 'image/png' ? 'png' : 'jpg';
    $raiz = dirname(__DIR__, 2);
    $destinoDir = $raiz . '/assets/img/plantillas';
    if (!is_dir($destinoDir) && !@mkdir($destinoDir, 0777, true) && !is_dir($destinoDir)) {
        throw new Exception('No se pudo crear el directorio assets/img/plantillas.');
    }
    $nombre = $prefijo . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $rutaAbs = $destinoDir . '/' . $nombre;
    if (!move_uploaded_file($archivo['tmp_name'], $rutaAbs)) {
        throw new Exception('No se pudo guardar la imagen en el servidor.');
    }
    return 'assets/img/plantillas/' . $nombre;
}

/* -------------------------------------------------------------- POST actions */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = (string) ($_POST['accion'] ?? '');

    try {
        if ($accion === 'guardar_config') {
            $libro = trim((string) ($_POST['libro_numero'] ?? 'Libro 1'));
            $folioInicial = max(1, (int) ($_POST['folio_inicial'] ?? 1));
            $lineaInicial = max(1, (int) ($_POST['linea_inicial'] ?? 1));
            $folioActual = max($folioInicial, (int) ($_POST['folio_actual'] ?? $folioInicial));
            $lineaActual = max($lineaInicial, (int) ($_POST['linea_actual'] ?? $lineaInicial));
            $lineasPorFolio = max(1, min(100, (int) ($_POST['lineas_por_folio'] ?? 20)));
            if ($lineaActual > $lineasPorFolio) {
                $folioActual++;
                $lineaActual = 1;
            }
            $idAutoridad = (int) ($_POST['id_autoridad'] ?? 0);
            if ($idAutoridad <= 0) { $idAutoridad = null; }

            $frente = cert_subir_plantilla($_FILES['plantilla_frente'] ?? [], 'frente');
            $reverso = cert_subir_plantilla($_FILES['plantilla_reverso'] ?? [], 'reverso');

            $sql = "UPDATE public.certificado_configuracion
                       SET libro_numero = :libro,
                           folio_inicial = :fi, linea_inicial = :li,
                           folio_actual = :fa, linea_actual = :la,
                            lineas_por_folio = :lpf,
                           id_autoridad = :aut,
                           fecha_actualizacion = CURRENT_TIMESTAMP";
            $params = [
                ':libro' => mb_substr($libro !== '' ? $libro : 'Libro 1', 0, 100),
                ':fi' => $folioInicial, ':li' => $lineaInicial,
                ':fa' => $folioActual, ':la' => $lineaActual,
                ':lpf' => $lineasPorFolio, ':aut' => $idAutoridad,
            ];
            if ($frente !== null) { $sql .= ", plantilla_frente = :pf"; $params[':pf'] = $frente; }
            if ($reverso !== null) { $sql .= ", plantilla_reverso = :pr"; $params[':pr'] = $reverso; }
            $sql .= " WHERE id_config = 1";
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $config = certificado_config($pdo);
            $mensaje = 'Configuración guardada. Próximo folio: ' . $config['folio_actual']
                     . ' · Próxima línea: ' . $config['linea_actual'] . '.';
        } elseif ($accion === 'emitir') {
            $idInscripcion = (int) ($_POST['id_inscripcion'] ?? 0);
            $certificado_obtener = certificado_obtener($pdo, $idInscripcion, (int) $_SESSION['id_usuario']);
            $mensaje = 'Certificado emitido: ' . $certificado_obtener['folio']
                     . ' · Libro ' . $certificado_obtener['libro']
                     . ' · Línea ' . $certificado_obtener['linea_libro'] . '.';
        } elseif ($accion === 'anular') {
            $idCertificado = (int) ($_POST['id_certificado'] ?? 0);
            $st = $pdo->prepare("UPDATE public.certificados_emitidos
                                    SET estado = 'Anulado' WHERE id_certificado = :id");
            $st->execute([':id' => $idCertificado]);
            $mensaje = 'Certificado anulado. Ya no aparecerá como válido en el verificador público.';
        } elseif ($accion === 'enviar' || $accion === 'enviar_masivo') {
            $ids = $accion === 'enviar'
                ? [(int) ($_POST['id_certificado'] ?? 0)]
                : array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? []))));
            $ids = array_slice(array_unique($ids), 0, 100);
            $enviados = 0;
            $fallos = [];

            $stCert = $pdo->prepare("SELECT ce.*, u.correo, u.email,
                                            TRIM(u.nombre || ' ' || COALESCE(u.apellido, '')) AS nombre_estudiante
                                       FROM public.certificados_emitidos ce
                                       JOIN public.usuarios u ON u.id_usuario = ce.id_estudiante
                                      WHERE ce.id_certificado = :id LIMIT 1");
            foreach ($ids as $idCertificado) {
                if ($idCertificado <= 0) { continue; }
                $stCert->execute([':id' => $idCertificado]);
                $cert = $stCert->fetch();
                if (!$cert) { $fallos[] = 'Certificado #' . $idCertificado . ' no existe.'; continue; }

                $destino = trim((string) ($cert['correo'] ?? ''));
                if ($destino === '' || !filter_var($destino, FILTER_VALIDATE_EMAIL)) {
                    $destino = trim((string) ($cert['email'] ?? ''));
                }
                if ($destino === '' || !filter_var($destino, FILTER_VALIDATE_EMAIL)) {
                    $fallos[] = $cert['folio'] . ': el estudiante no tiene correo registrado.';
                    continue;
                }

                try {
                    $ins = academico_estado_inscripcion($pdo, (int) $cert['id_inscripcion']);
                    if (!$ins) { throw new Exception('No se encontró la inscripción asociada.'); }
                    $pdf = certificado_pdf_output(
                        [['ins' => $ins, 'cert' => $cert]],
                        'Certificado_' . $cert['folio'] . '.pdf',
                        false,
                        $config
                    );
                    $cuerpo = plantilla_correo('Su certificado está disponible', '
                        <p>Estimado(a) <strong>' . cert_ui_e($cert['nombre_estudiante']) . '</strong>:</p>
                        <p>Adjunto encontrará su <strong>Certificado de Culminación de Estudios</strong>,
                           folio <strong>' . cert_ui_e($cert['folio']) . '</strong>,
                           libro <strong>' . cert_ui_e($cert['libro']) . '</strong>,
                           línea <strong>' . (int) $cert['linea_libro'] . '</strong>.</p>
                        <p>La autenticidad puede verificarse escaneando el QR del reverso o ingresando el folio y el código
                           <strong>' . cert_ui_e($cert['codigo_validacion']) . '</strong> en el sistema público de verificación.</p>
                    ');
                    $ok = enviarCorreoSistema(
                        $destino,
                        'Certificado de Culminación - Escuela Nora Castañeda',
                        $cuerpo,
                        (string) $cert['nombre_estudiante'],
                        [['contenido' => $pdf, 'nombre' => 'Certificado_' . $cert['folio'] . '.pdf']]
                    );
                    $stLog = $pdo->prepare("INSERT INTO public.certificado_envios
                            (id_certificado, correo, estatus, mensaje) VALUES (:c, :e, :s, :m)");
                    $stLog->execute([
                        ':c' => $idCertificado, ':e' => $destino,
                        ':s' => $ok ? 'Enviado' : 'Fallido',
                        ':m' => $ok ? 'PDF adjunto enviado.' : 'No se pudo enviar por SMTP.',
                    ]);
                    if ($ok) { $enviados++; } else { $fallos[] = $cert['folio'] . ': error al enviar por SMTP.'; }
                } catch (Throwable $e) {
                    $fallos[] = $cert['folio'] . ': ' . $e->getMessage();
                }
            }
            $mensaje = 'Correos enviados: ' . $enviados . ' de ' . count($ids) . '.';
            if ($fallos) { $error = implode(' ', $fallos); }
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

/* ------------------------------------------------------------------ listados */
$emitidos = $pdo->query("SELECT ce.*, u.cedula, u.nombre, u.apellido, COALESCE(m.nombre_programa, fe.nombre) AS nombre_programa,
                                (SELECT COUNT(*) FROM public.certificado_envios e WHERE e.id_certificado = ce.id_certificado) AS envios
                           FROM public.certificados_emitidos ce
                           JOIN public.usuarios u ON u.id_usuario = ce.id_estudiante
                           LEFT JOIN public.mallas_curriculares m ON m.id_malla = ce.id_malla
                         LEFT JOIN public.foros_eventos fe ON fe.id_foro = ce.id_foro
                          ORDER BY ce.linea_libro ASC, ce.id_certificado ASC")->fetchAll();

$completados = [];
$inscripciones = $pdo->query("SELECT i.id_inscripcion, i.id_estudiante, i.id_curso,
                                     u.cedula, u.nombre, u.apellido,
                                     co.id_malla, co.codigo_cohorte, m.nombre_programa
                                FROM public.inscripciones i
                                JOIN public.usuarios u ON u.id_usuario = i.id_estudiante
                                JOIN public.cohortes co ON co.id_cohorte = i.id_curso
                                JOIN public.mallas_curriculares m ON m.id_malla = co.id_malla
                               ORDER BY u.apellido, u.nombre")->fetchAll();
$cacheMalla = [];
$conCertificado = $pdo->query("SELECT DISTINCT id_estudiante, id_malla FROM public.certificados_emitidos")->fetchAll();
$yaCertificado = [];
foreach ($conCertificado as $c) { $yaCertificado[$c['id_estudiante'] . '_' . $c['id_malla']] = true; }
foreach ($inscripciones as $i) {
    $clave = $i['id_estudiante'] . '_' . $i['id_malla'];
    if (isset($yaCertificado[$clave])) { continue; }
    if (!isset($cacheMalla[$clave])) {
        $cacheMalla[$clave] = academico_estado_malla($pdo, (int) $i['id_estudiante'], (int) $i['id_malla']);
    }
    if ($cacheMalla[$clave]['completado']) {
        $i['malla_estado'] = $cacheMalla[$clave];
        $completados[] = $i;
    }
}

$autoridades = $pdo->query("SELECT id_autoridad, cargo, nombre_apellido
                              FROM public.configuracion_autoridades
                             WHERE estatus = 'Activo' ORDER BY id_autoridad")->fetchAll();
$mallasMasivas = $pdo->query("SELECT id_malla, nombre_programa
                                FROM public.mallas_curriculares ORDER BY nombre_programa")->fetchAll(PDO::FETCH_ASSOC);
$cohortesMasivas = $pdo->query("SELECT co.id_cohorte, co.codigo_cohorte, m.nombre_programa
                                  FROM public.cohortes co
                                  JOIN public.mallas_curriculares m ON m.id_malla = co.id_malla
                                 ORDER BY co.codigo_cohorte")->fetchAll(PDO::FETCH_ASSOC);

require_once '../../includes/header.php';
require_once '../../includes/buscador_lista.php';
?>
<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <div>
        <h1 class="h3 mb-0 font-weight-bold" style="color: #521f29;">
            <i class="fas fa-certificate mr-2 text-warning"></i>Certificados y Expediente Académico
        </h1>
        <p class="text-muted mb-0 small mt-1">Plantillas, folios, libro, línea, emisión, correo e impresión masiva.</p>
    </div>
    <div class="d-flex flex-wrap">
        <a href="certificados.php?action=excel" class="btn btn-sm btn-success font-weight-bold shadow-sm mr-2">
            <i class="fas fa-file-excel mr-1"></i> Excel de folios y líneas
        </a>
        <a href="#descarga-masiva" class="btn btn-sm text-dark font-weight-bold shadow-sm" style="background-color:#ff8a00;">
            <i class="fas fa-file-pdf mr-1"></i> PDF masivo por curso
        </a>
    </div>
</div>

<!-- DESCARGA MASIVA FILTRADA POR CURSO -->
<div class="card shadow border-0 mb-4" id="descarga-masiva" style="border-radius:15px;">
    <div class="card-header py-3 text-white font-weight-bold" style="background-color:#521f29; border-top-left-radius:15px; border-top-right-radius:15px;">
        <i class="fas fa-file-pdf mr-2 text-warning"></i>Descarga masiva de certificados por curso
    </div>
    <div class="card-body bg-white p-4">
        <form method="GET" action="certificados_masivos.php" class="form-row align-items-end">
            <div class="form-group col-md-4">
                <label class="font-weight-bold small">Curso / Carrera</label>
                <select name="id_malla" class="form-control form-control-sm font-weight-bold">
                    <option value="0">-- Todos los cursos --</option>
                    <?php foreach ($mallasMasivas as $m): ?>
                        <option value="<?= (int) $m['id_malla'] ?>"><?= cert_ui_e($m['nombre_programa']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group col-md-4">
                <label class="font-weight-bold small">Sección (opcional)</label>
                <select name="id_cohorte" class="form-control form-control-sm font-weight-bold">
                    <option value="0">-- Todas las secciones --</option>
                    <?php foreach ($cohortesMasivas as $c): ?>
                        <option value="<?= (int) $c['id_cohorte'] ?>">
                            <?= cert_ui_e($c['codigo_cohorte']) ?> — <?= cert_ui_e($c['nombre_programa']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group col-md-4">
                <label class="font-weight-bold small">Qué descargar</label>
                <select name="modo" class="form-control form-control-sm font-weight-bold">
                    <option value="completados">Generar los completados del curso</option>
                    <option value="emitidos">Solo los ya emitidos del curso</option>
                </select>
            </div>
            <div class="form-group col-12 mb-0">
                <button type="submit" name="completados" value="1" class="btn btn-sm font-weight-bold text-white shadow-sm" style="background-color:#ff8a00;">
                    <i class="fas fa-download mr-1"></i> DESCARGAR PDF DEL CURSO FILTRADO
                </button>
                <small class="text-muted ml-2">El filtro aplica tanto a cursos como a secciones; se descargan máximo 300 certificados por archivo.</small>
            </div>
        </form>
    </div>
</div>

<?php if ($mensaje): ?><div class="alert alert-success font-weight-bold shadow-sm"><?= cert_ui_e($mensaje) ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger font-weight-bold shadow-sm"><?= cert_ui_e($error) ?></div><?php endif; ?>

<div class="row">
    <div class="col-lg-5">
        <div class="card shadow border-0 mb-4" style="border-radius:15px;">
            <div class="card-header text-white font-weight-bold" style="background-color:#521f29;">
                <i class="fas fa-cogs mr-2"></i> Configuración del certificado
            </div>
            <div class="card-body bg-white p-4">
                <form method="POST" enctype="multipart/form-data">
                    <?= csrf_field() ?>
                    <input type="hidden" name="accion" value="guardar_config">
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label class="font-weight-bold small">Libro</label>
                            <input type="text" name="libro_numero" class="form-control form-control-sm" value="<?= cert_ui_e($config['libro_numero']) ?>">
                        </div>
                        <div class="form-group col-md-6">
                            <label class="font-weight-bold small">Máxima autoridad (firma)</label>
                            <select name="id_autoridad" class="form-control form-control-sm">
                                <option value="0">-- Autoridad activa por defecto --</option>
                                <?php foreach ($autoridades as $a): ?>
                                    <option value="<?= (int) $a['id_autoridad'] ?>"
                                        <?= (int) ($config['id_autoridad'] ?? 0) === (int) $a['id_autoridad'] ? 'selected' : '' ?>>
                                        <?= cert_ui_e($a['nombre_apellido']) ?> — <?= cert_ui_e($a['cargo']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label class="font-weight-bold small">Folio inicial</label>
                            <input type="number" min="1" name="folio_inicial" class="form-control form-control-sm" value="<?= (int) $config['folio_inicial'] ?>">
                        </div>
                        <div class="form-group col-md-6">
                            <label class="font-weight-bold small">Línea inicial</label>
                            <input type="number" min="1" name="linea_inicial" class="form-control form-control-sm" value="<?= (int) $config['linea_inicial'] ?>">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label class="font-weight-bold small">Folio actual (próximo a usar)</label>
                            <input type="number" min="1" name="folio_actual" class="form-control form-control-sm" value="<?= (int) $config['folio_actual'] ?>">
                        </div>
                        <div class="form-group col-md-6">
                            <label class="font-weight-bold small">Línea actual (próxima a usar)</label>
                            <input type="number" min="1" name="linea_actual" class="form-control form-control-sm" value="<?= (int) $config['linea_actual'] ?>">
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group col-md-6">
                            <label class="font-weight-bold small">Líneas por folio (1–100)</label>
                            <input type="number" min="1" max="100" name="lineas_por_folio" class="form-control form-control-sm" value="<?= (int) ($config['lineas_por_folio'] ?? 20) ?>">
                            <small class="text-muted">Un folio admite varias líneas; al agotarse se abre el folio siguiente y la línea vuelve a 1.</small>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="font-weight-bold small">Fondo del frente (JPG/PNG)</label>
                        <input type="file" name="plantilla_frente" accept=".jpg,.jpeg,.png" class="form-control-file">
                        <small class="text-muted">Actual: <?= cert_ui_e($config['plantilla_frente']) ?></small>
                    </div>
                    <div class="form-group">
                        <label class="font-weight-bold small">Fondo del reverso (JPG/PNG)</label>
                        <input type="file" name="plantilla_reverso" accept=".jpg,.jpeg,.png" class="form-control-file">
                        <small class="text-muted">Actual: <?= cert_ui_e($config['plantilla_reverso']) ?></small>
                    </div>
                    <div class="alert alert-info small py-2">
                        Plantillas de muestra:
                        <a href="../../assets/img/plantillas_muestra/frente_muestra.png" target="_blank">Frente</a> ·
                        <a href="../../assets/img/plantillas_muestra/reverso_muestra.png" target="_blank">Reverso</a>
                    </div>
                    <button type="submit" class="btn btn-sm font-weight-bold text-white" style="background-color:#cd1e3e;">
                        <i class="fas fa-save mr-1"></i> GUARDAR CONFIGURACIÓN
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-7" id="listaCertificadosControlEstudio">
        <?php buscador_lista_render('Buscar por nombre o cédula…', '#listaCertificadosControlEstudio table'); ?>
        <div class="card shadow border-0 mb-4" style="border-radius:15px;">
            <div class="card-header text-white font-weight-bold" style="background-color:#521f29;">
                <i class="fas fa-list-check mr-2"></i> Listos para certificar (<?= count($completados) ?>)
            </div>
            <div class="card-body bg-white p-0">
                <?php if (!$completados): ?>
                    <p class="text-muted p-4 mb-0">No hay estudiantes con malla completada sin certificar.</p>
                <?php else: ?>
                    <div class="table-responsive" style="max-height:340px; overflow-y:auto;">
                        <table class="table table-sm table-hover mb-0">
                            <thead class="thead-light"><tr><th>Cédula</th><th>Estudiante</th><th>Programa</th><th>Sección</th><th></th></tr></thead>
                            <tbody>
                                <?php foreach ($completados as $c): ?>
                                    <tr>
                                        <td><?= cert_ui_e($c['cedula']) ?></td>
                                        <td class="font-weight-bold"><?= cert_ui_e(trim($c['nombre'] . ' ' . $c['apellido'])) ?></td>
                                        <td class="small"><?= cert_ui_e($c['nombre_programa']) ?></td>
                                        <td class="small"><?= cert_ui_e($c['codigo_cohorte']) ?></td>
                                        <td>
                                            <form method="POST">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="accion" value="emitir">
                                                <input type="hidden" name="id_inscripcion" value="<?= (int) $c['id_inscripcion'] ?>">
                                                <button type="submit" class="btn btn-sm btn-success font-weight-bold">Emitir</button>
                                            </form>
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
</div>

<div class="card shadow border-0" style="border-radius:15px;">
    <div class="card-header text-white font-weight-bold" style="background-color:#521f29;">
        <i class="fas fa-award mr-2"></i> Certificados emitidos (<?= count($emitidos) ?>)
    </div>
    <div class="card-body bg-white p-0">
        <?php if (!$emitidos): ?>
            <p class="text-muted p-4 mb-0">Aún no se han emitido certificados.</p>
        <?php else: ?>
            <!-- Formularios ocultos para las acciones por fila (evita anidar forms) -->
            <?php foreach ($emitidos as $e): ?>
                <form method="POST" id="form_enviar_<?= (int) $e['id_certificado'] ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="accion" value="enviar">
                    <input type="hidden" name="id_certificado" value="<?= (int) $e['id_certificado'] ?>">
                </form>
                <?php if (strtolower($e['estado']) === 'emitido'): ?>
                    <form method="POST" id="form_anular_<?= (int) $e['id_certificado'] ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="accion" value="anular">
                        <input type="hidden" name="id_certificado" value="<?= (int) $e['id_certificado'] ?>">
                    </form>
                <?php endif; ?>
            <?php endforeach; ?>

            <form method="POST" id="formEnvios">
                <?= csrf_field() ?>
                <input type="hidden" name="accion" value="enviar_masivo">
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead class="thead-light">
                            <tr>
                                <th></th><th>Línea</th><th>Folio</th><th>Cédula</th><th>Estudiante</th>
                                <th>Programa</th><th>Libro</th><th>Código</th><th>Estado</th><th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($emitidos as $e): ?>
                                <tr>
                                    <td><input type="checkbox" name="ids[]" value="<?= (int) $e['id_certificado'] ?>"></td>
                                    <td><?= (int) $e['linea_libro'] ?></td>
                                    <td class="font-weight-bold"><?= cert_ui_e($e['folio']) ?></td>
                                    <td><?= cert_ui_e($e['cedula']) ?></td>
                                    <td><?= cert_ui_e(trim($e['nombre'] . ' ' . $e['apellido'])) ?></td>
                                    <td class="small"><?= cert_ui_e($e['nombre_programa']) ?></td>
                                    <td><?= cert_ui_e($e['libro']) ?></td>
                                    <td class="small"><?= cert_ui_e($e['codigo_validacion']) ?></td>
                                    <td>
                                        <span class="badge badge-<?= strtolower($e['estado']) === 'emitido' ? 'success' : 'danger' ?>">
                                            <?= cert_ui_e($e['estado']) ?>
                                        </span>
                                    </td>
                                    <td class="text-nowrap">
                                        <a href="../../certificado_pdf.php?id=<?= (int) $e['id_inscripcion'] ?>"
                                           class="btn btn-sm btn-outline-primary" title="Descargar PDF">
                                            <i class="fas fa-download"></i>
                                        </a>
                                        <button type="submit"
                                                form="form_enviar_<?= (int) $e['id_certificado'] ?>"
                                                class="btn btn-sm btn-outline-success" title="Enviar por correo">
                                            <i class="fas fa-envelope"></i>
                                        </button>
                                        <?php if (strtolower($e['estado']) === 'emitido'): ?>
                                            <button type="submit"
                                                    form="form_anular_<?= (int) $e['id_certificado'] ?>"
                                                    onclick="return confirm('¿Anular este certificado?');"
                                                    class="btn btn-sm btn-outline-danger" title="Anular">
                                                <i class="fas fa-ban"></i>
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="p-3">
                    <button type="submit" class="btn btn-sm font-weight-bold text-white" style="background-color:#521f29;">
                        <i class="fas fa-envelope mr-1"></i> Enviar correos a seleccionados
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>
