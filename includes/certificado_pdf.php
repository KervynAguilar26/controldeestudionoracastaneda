<?php
// /sistema_nora/includes/certificado_pdf.php
// Emisión y renderizado de certificados de culminación de estudios en PDF.

require_once __DIR__ . '/academico.php';
require_once __DIR__ . '/qr_generator.php';

if (!function_exists('certificado_meses')) {
    function certificado_meses(): array
    {
        return ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio',
                'Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
    }
}

if (!function_exists('certificado_fecha_larga')) {
    function certificado_fecha_larga(?string $fecha): string
    {
        $ts = $fecha ? strtotime($fecha) : time();
        if (!$ts) {
            $ts = time();
        }
        $meses = certificado_meses();
        return date('d', $ts) . ' días del mes de ' . $meses[(int) date('n', $ts) - 1] . ' de ' . date('Y', $ts);
    }
}

if (!function_exists('certificado_autoridades')) {
    function certificado_autoridades(PDO $pdo): array
    {
        try {
            return $pdo->query("SELECT * FROM public.configuracion_autoridades
                                 WHERE estatus = 'Activo'
                                 ORDER BY id_autoridad ASC")->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('certificado_obtener')) {
    /**
     * Devuelve el certificado emitido para la inscripción o lo emite si el
     * estudiante ya completó todas las materias de su malla curricular.
     */
    function certificado_obtener(PDO $pdo, int $id_inscripcion, int $id_emisor = 0): array
    {
        $ins = academico_estado_inscripcion($pdo, $id_inscripcion);
        if (!$ins) {
            throw new Exception('La inscripción indicada no existe.');
        }

        // ¿Ya tiene certificado? Se devuelve el folio original (aunque luego
        // cambien las notas, el documento emitido conserva su validez).
        $stmt = $pdo->prepare("SELECT * FROM public.certificados_emitidos
                                WHERE id_estudiante = :est AND id_malla = :malla AND tipo = 'Culminacion'
                                LIMIT 1");
        $stmt->execute([':est' => $ins['id_estudiante'], ':malla' => $ins['id_malla']]);
        $existente = $stmt->fetch();
        if ($existente) {
            return $existente;
        }

        $estado = $ins['malla_estado'];
        if (empty($estado['completado'])) {
            throw new Exception(
                'El estudiante aún no ha completado su malla curricular ('
                . $estado['aprobadas'] . ' de ' . $estado['total_materias'] . ' materias aprobadas).'
            );
        }

        $propia = !$pdo->inTransaction();
        if ($propia) {
            $pdo->beginTransaction();
        }

        // El emisor solo se registra si efectivamente existe en usuarios
        if ($id_emisor > 0) {
            $chk = $pdo->prepare("SELECT 1 FROM public.usuarios WHERE id_usuario = :u");
            $chk->execute([':u' => $id_emisor]);
            if (!$chk->fetchColumn()) {
                $id_emisor = 0;
            }
        }

        try {
            $tmp = 'TMP-' . bin2hex(random_bytes(8));
            $stmt = $pdo->prepare("INSERT INTO public.certificados_emitidos
                    (id_inscripcion, id_estudiante, id_malla, tipo, folio, id_usuario_emisor)
                    VALUES (:ins, :est, :malla, 'Culminacion', :folio, :emisor)
                    RETURNING id_certificado");
            $stmt->execute([
                ':ins'    => $id_inscripcion,
                ':est'    => $ins['id_estudiante'],
                ':malla'  => $ins['id_malla'],
                ':folio'  => $tmp,
                ':emisor' => $id_emisor > 0 ? $id_emisor : null,
            ]);
            $idCertificado = (int) $stmt->fetchColumn();
            $folio = 'CERT-' . date('Y') . '-' . str_pad((string) $idCertificado, 5, '0', STR_PAD_LEFT);

            $upd = $pdo->prepare("UPDATE public.certificados_emitidos SET folio = :folio WHERE id_certificado = :id");
            $upd->execute([':folio' => $folio, ':id' => $idCertificado]);

            if ($propia) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($propia && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            // Otra sesión pudo emitirlo al mismo tiempo: se devuelve el existente
            if ($e instanceof PDOException && ($e->errorInfo[0] ?? '') === '23505') {
                $stmt = $pdo->prepare("SELECT * FROM public.certificados_emitidos
                                        WHERE id_estudiante = :est AND id_malla = :malla AND tipo = 'Culminacion'
                                        LIMIT 1");
                $stmt->execute([':est' => $ins['id_estudiante'], ':malla' => $ins['id_malla']]);
                $dup = $stmt->fetch();
                if ($dup) {
                    return $dup;
                }
            }
            throw $e;
        }

        $stmt = $pdo->prepare("SELECT * FROM public.certificados_emitidos WHERE id_certificado = :id");
        $stmt->execute([':id' => $idCertificado]);
        return $stmt->fetch();
    }
}

if (!function_exists('certificado_por_folio')) {
    function certificado_por_folio(PDO $pdo, string $folio): ?array
    {
        $sql = "SELECT ce.*, u.nombre, u.apellido, u.cedula,
                       m.nombre_programa, m.tipo_oferta, m.tipo_regimen, m.total_periodos,
                       co.codigo_cohorte, co.salon_aula, co.num_periodo,
                       i.fecha_inscripcion,
                       (SELECT COALESCE(SUM(mm.horas_academicas), 0)
                          FROM public.malla_materias mm WHERE mm.id_malla = ce.id_malla) AS horas_academicas,
                       (SELECT COUNT(*) FROM public.malla_materias mm WHERE mm.id_malla = ce.id_malla) AS total_materias
                  FROM public.certificados_emitidos ce
                  JOIN public.usuarios u ON u.id_usuario = ce.id_estudiante
                  JOIN public.mallas_curriculares m ON m.id_malla = ce.id_malla
                  LEFT JOIN public.inscripciones i ON i.id_inscripcion = ce.id_inscripcion
                  LEFT JOIN public.cohortes co ON co.id_cohorte = i.id_curso
                 WHERE ce.folio = :folio LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':folio' => $folio]);
        $fila = $stmt->fetch();
        return $fila ?: null;
    }
}

if (!function_exists('certificado_css')) {
    function certificado_css(): string
    {
        $frente = sistema_asset_data_uri('assets/img/plantilla_frente.jpg');
        $reverso = sistema_asset_data_uri('assets/img/plantilla_reverso.jpg');

        return '
        @page { size: letter landscape; margin: 0; }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 0; font-family: "Times New Roman", Times, serif; color: #1a1a1a; }
        .hoja { width: 11in; height: 8.5in; position: relative; page-break-after: always; overflow: hidden; }
        .hoja.ultima { page-break-after: auto; }
        .frente { background-image: url("' . $frente . '"); background-repeat: no-repeat; background-position: center; background-size: 100% 100%; }
        .reverso { background-image: url("' . $reverso . '"); background-repeat: no-repeat; background-position: center; background-size: 100% 100%; }
        .nombre-estudiante {
            position: absolute; top: 42%; left: 15%; width: 80%; text-align: center;
            font-size: 30pt; font-weight: bold; color: #1a1a1a; text-transform: uppercase;
        }
        .curso-realizado {
            position: absolute; top: 62%; left: 15%; width: 80%; text-align: center;
            font-size: 18pt; color: #630E2B; font-weight: bold; text-transform: uppercase;
        }
        .fecha-maracay {
            position: absolute; bottom: 30%; left: 10%; width: 80%; text-align: center;
            font-size: 13pt; color: #333; font-style: italic;
        }
        .titulo-curso-rev {
            position: absolute; top: 12%; left: 8%; width: 84%;
            font-size: 14pt; font-weight: bold; color: #630E2B; text-transform: uppercase;
        }
        .tabla-materias { position: absolute; top: 23%; left: 8%; width: 58%; border-collapse: collapse; }
        .tabla-materias th {
            background: #521f29; color: #ffffff; font-size: 8.5pt; padding: 3px 4px;
            text-align: center; border: 1px solid #521f29;
        }
        .tabla-materias td { border-bottom: 1px solid #cfc0b2; padding: 2.5px 4px; vertical-align: middle; }
        .tabla-materias .num { text-align: center; width: 6%; }
        .tabla-materias .mat { text-align: left; }
        .tabla-materias .mod { text-align: center; width: 10%; }
        .tabla-materias .hor { text-align: center; width: 12%; }
        .tabla-materias .not { text-align: center; width: 11%; font-weight: bold; }
        .tabla-materias .est { text-align: center; width: 14%; }
        .tabla-materias tr.total td {
            background: #f3e9dd; color: #521f29; font-weight: bold; border-top: 2px solid #521f29;
        }
        .texto-autenticidad {
            position: absolute; bottom: 11%; left: 8%; width: 52%;
            font-size: 8.5pt; color: #555555; text-align: justify; line-height: 1.45;
        }
        .texto-autenticidad strong { color: #521f29; }
        .qr-contenedor { position: absolute; bottom: 8%; right: 8%; text-align: center; width: 1.35in; }
        .qr-contenedor img { width: 1.15in; height: 1.15in; }
        .qr-contenedor .folio { font-size: 7.5pt; font-weight: bold; color: #521f29; margin-top: 3px; }
        .qr-contenedor .qr-txt { font-size: 6.5pt; color: #6b5b4d; }
        .sin-firmas { font-size: 8pt; color: #6b5b4d; margin-top: 6px; }
        ';
    }
}

if (!function_exists('certificado_html_hoja')) {
    /**
     * Construye las dos caras (frente y reverso) de un certificado.
     * $item = ['ins' => datos inscripción, 'cert' => registro emitido]
     */
    function certificado_html_hoja(array $item, bool $esUltima = false): string
    {
        $ins = $item['ins'];
        $cert = $item['cert'];
        $estado = $ins['malla_estado'];

        $nombre = htmlspecialchars(mb_strtoupper(trim($ins['nombre'] . ' ' . $ins['apellido']), 'UTF-8'), ENT_QUOTES, 'UTF-8');
        $programa = htmlspecialchars(mb_strtoupper($ins['nombre_programa'], 'UTF-8'), ENT_QUOTES, 'UTF-8');
        $folio = htmlspecialchars($cert['folio'], ENT_QUOTES, 'UTF-8');
        $fecha = certificado_fecha_larga($cert['fecha_emision'] ?? null);

        $qrTexto = sistema_qr_url($cert['folio']);
        $qrSrc = sistema_qr_data_uri($qrTexto, 'cert_' . preg_replace('/[^A-Za-z0-9]/', '', $cert['folio']));

        $totalMaterias = count($estado['materias']);
        $fuenteTabla = '8.2pt';
        if ($totalMaterias > 9)  { $fuenteTabla = '7.4pt'; }
        if ($totalMaterias > 13) { $fuenteTabla = '6.6pt'; }
        if ($totalMaterias > 18) { $fuenteTabla = '5.9pt'; }

        $filas = '';
        $n = 0;
        foreach ($estado['materias'] as $m) {
            $n++;
            $nota = $m['nota_final'] !== null ? number_format((float) $m['nota_final'], 2) : '—';
            $estadoTxt = $m['aprobada'] ? 'APROBADA' : ($m['total_eval'] > 0 ? 'REPROBADA' : 'SIN EVALUAR');
            $color = $m['aprobada'] ? '#1e7e34' : ($m['total_eval'] > 0 ? '#b02a37' : '#8a7562');
            $filas .= '<tr>'
                . '<td class="num">' . $n . '</td>'
                . '<td class="mat">' . htmlspecialchars($m['nombre_materia'], ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td class="mod">' . (int) $m['modulo_num'] . '</td>'
                . '<td class="hor">' . (int) $m['horas_academicas'] . ' h</td>'
                . '<td class="not">' . $nota . '</td>'
                . '<td class="est" style="color:' . $color . ';">' . $estadoTxt . '</td>'
                . '</tr>';
        }

        if ($n === 0) {
            $filas = '<tr><td colspan="6" class="mat" style="text-align:center; padding:8px;">La malla curricular no tiene materias registradas.</td></tr>';
        }

        $claseUltima = $esUltima ? ' ultima' : '';

        return '
        <div class="hoja frente">
            <div class="nombre-estudiante">' . $nombre . '</div>
            <div class="curso-realizado">' . $programa . '</div>
            <div class="fecha-maracay">Dado en Maracay, Estado Aragua, a los ' . htmlspecialchars($fecha, ENT_QUOTES, 'UTF-8') . '.</div>
        </div>
        <div class="hoja reverso' . $claseUltima . '">
            <div class="titulo-curso-rev">TÍTULO: ' . $programa . '</div>
            <table class="tabla-materias" style="font-size:' . $fuenteTabla . ';">
                <thead>
                    <tr>
                        <th>N°</th>
                        <th>Contenido programático / Materia</th>
                        <th>Mód.</th>
                        <th>Horas</th>
                        <th>Nota</th>
                        <th>Estado</th>
                    </tr>
                </thead>
                <tbody>
                    ' . $filas . '
                    <tr class="total">
                        <td colspan="3" style="text-align:right; padding:4px;">TOTAL HORAS ACADÉMICAS:</td>
                        <td style="text-align:center;">' . (int) $estado['horas_totales'] . ' h</td>
                        <td colspan="2" style="text-align:center;">MALLA COMPLETADA</td>
                    </tr>
                </tbody>
            </table>
            <div class="texto-autenticidad">
                La autenticidad de este documento puede ser verificada escaneando el código QR adjunto
                o ingresando el folio <strong>' . $folio . '</strong> en el Sistema Público de Verificación
                de la Escuela Internacional de Liderazgo de la Mujer &quot;Nora Castañeda&quot;.<br><br>
                Estudiante: <strong>' . $nombre . '</strong> &nbsp;|&nbsp; Cédula: <strong>V-' . htmlspecialchars($ins['cedula'], ENT_QUOTES, 'UTF-8') . '</strong><br>
                Programa: <strong>' . htmlspecialchars($ins['nombre_programa'], ENT_QUOTES, 'UTF-8') . '</strong><br>
                Cohorte: <strong>' . htmlspecialchars($ins['codigo_cohorte'], ENT_QUOTES, 'UTF-8') . '</strong>
                &nbsp;|&nbsp; Período: <strong>' . (int) $ins['num_periodo'] . '</strong>
            </div>
            <div class="qr-contenedor">'
                . ($qrSrc !== '' ? '<img src="' . $qrSrc . '" alt="QR">' : '')
                . '<div class="folio">' . $folio . '</div>
                   <div class="qr-txt">VALIDACIÓN DIGITAL</div>
            </div>
        </div>';
    }
}

if (!function_exists('certificado_html_documento')) {
    /**
     * @param array $items Lista de ['ins' => ..., 'cert' => ...]
     */
    function certificado_html_documento(array $items): string
    {
        $cuerpo = '';
        $total = count($items);
        foreach ($items as $i => $item) {
            $cuerpo .= certificado_html_hoja($item, ($i === $total - 1));
        }

        return '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8">'
            . '<title>Certificado de Culminación</title>'
            . '<style>' . certificado_css() . '</style></head><body>'
            . $cuerpo
            . '</body></html>';
    }
}

if (!function_exists('certificado_pdf_output')) {
    /**
     * Genera el PDF (una o varias hojas) y lo envía/retorna.
     */
    function certificado_pdf_output(array $items, string $nombreArchivo, bool $descargar = true): string
    {
        if (empty($items)) {
            throw new Exception('No hay certificados para generar.');
        }

        $options = new \Dompdf\Options();
        $options->setChroot(dirname(__DIR__));
        $options->setIsRemoteEnabled(false);
        $options->setIsHtml5ParserEnabled(true);
        $options->setDefaultFont('Times New Roman');
        $options->setTempDir(sys_get_temp_dir());

        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->loadHtml(certificado_html_documento($items), 'UTF-8');
        $dompdf->setPaper('letter', 'landscape');
        $dompdf->render();

        $pdf = $dompdf->output();

        if ($descargar) {
            $nombreArchivo = preg_replace('/[^A-Za-z0-9._-]/', '_', $nombreArchivo);
            if (!headers_sent()) {
                header('Content-Type: application/pdf');
                header('Content-Disposition: attachment; filename="' . $nombreArchivo . '"');
                header('Content-Length: ' . strlen($pdf));
                header('Cache-Control: private, max-age=0, must-revalidate');
                header('Pragma: public');
            }
            echo $pdf;
            exit;
        }

        return $pdf;
    }
}
