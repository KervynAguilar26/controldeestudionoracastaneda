<?php
// /sistema_nora/includes/foro_certificado_pdf.php
// Generación del certificado de Foros, Conversatorios y Seminarios (frente y reverso).
// Reutiliza plantillas, firma de autoridad, folio/libro/línea y QR del sistema.

require_once __DIR__ . '/certificado_pdf.php';

if (!function_exists('foro_certificado_html')) {
    function foro_certificado_html(array $d, array $config = []): string
    {
        $h = function ($v) {
            return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        };

        $nombre = mb_strtoupper(trim($d['nombre'] . ' ' . ($d['apellido'] ?? '')), 'UTF-8');
        $cedula = (string) ($d['cedula'] ?? '');
        $rol = mb_strtoupper((string) ($d['rol_certificado'] ?? 'Oyente'), 'UTF-8');
        $foro = mb_strtoupper((string) ($d['foro_nombre'] ?? ''), 'UTF-8');
        $folio = (string) ($d['folio'] ?? '');
        $codigo = (string) ($d['codigo_validacion'] ?? '');
        $libro = (string) ($d['libro'] ?? 'Libro 1');
        $linea = (int) ($d['linea_libro'] ?? 0);
        $fecha = certificado_fecha_larga($d['fecha_emision'] ?? null);

        $qrSrc = sistema_qr_data_uri(
            sistema_qr_url($folio, $codigo),
            'foro_' . preg_replace('/[^A-Za-z0-9]/', '', $folio)
        );
        $autoridadNombre = mb_strtoupper((string) ($config['autoridad_nombre'] ?? ''), 'UTF-8');
        $autoridadCargo = (string) ($config['autoridad_cargo'] ?? '');
        $firmaSrc = (string) ($config['firma_data'] ?? '');
        $firmaHtml = $firmaSrc !== ''
            ? '<img src="' . $h($firmaSrc) . '" alt="Firma">'
            : '<div class="linea"></div>';

        $temas = $d['temas'] ?? [];
        $filas = '';
        $n = 0;
        $horas = 0;
        foreach ($temas as $t) {
            $n++;
            $horasTema = (int) ($t['horas_academicas'] ?? 0);
            $horas += $horasTema;
            $filas .= '<tr>'
                . '<td class="num">' . $n . '</td>'
                . '<td class="mat">' . $h($t['nombre_tema']) . '</td>'
                . '<td class="mod">' . $n . '</td>'
                . '<td class="hor">' . $horasTema . ' h</td>'
                . '<td class="not">—</td>'
                . '<td class="est">CERTIFICADO</td>'
                . '</tr>';
        }
        if ($n === 0) {
            $filas = '<tr><td colspan="6" class="mat" style="text-align:center;padding:8px;">'
                . 'El evento no tiene temas registrados en su malla.</td></tr>';
        }
        $fuente = count($temas) > 12 ? '6.8pt' : '8.2pt';

        $atribucion = $rol === 'PARTICIPANTE'
            ? 'Por haber confirmado su asistencia a la ponencia y participado activamente en la mesa de trabajo correspondiente.'
            : 'Por haber asistido y escuchado la ponencia del evento, según el registro interno de asistencia.';

        return '
        <div class="hoja frente">
            <div class="nombre-estudiante">' . $h($nombre) . '</div>
            <div class="cedula-estudiante">CÉDULA: ' . $h($cedula) . '</div>
            <div class="curso-realizado">' . $h($rol) . ' · ' . $h($foro) . '</div>
            <div class="fecha-maracay">Dado en Maracay, Estado Aragua, a los ' . $h($fecha) . '.</div>
            <div class="firma-bloque">
                ' . $firmaHtml . '
                <div class="nombre">' . $h($autoridadNombre) . '</div>
                <div class="cargo">' . $h($autoridadCargo) . '</div>
            </div>
        </div>
        <div class="hoja reverso ultima">
            <div class="titulo-curso-rev">' . $h($foro) . ' · ' . $h($rol) . '</div>
            <table class="tabla-materias" style="font-size:' . $fuente . ';">
                <thead><tr>
                    <th>N°</th><th>Tema / Ponencia de la malla</th><th>Mód.</th>
                    <th>Horas</th><th>Nota</th><th>Condición</th>
                </tr></thead>
                <tbody>' . $filas . '
                    <tr class="total">
                        <td colspan="3" style="text-align:right;padding:4px;">TOTAL HORAS ACADÉMICAS:</td>
                        <td style="text-align:center;">' . $horas . ' h</td>
                        <td colspan="2" style="text-align:center;">ASISTENCIA VERIFICADA</td>
                    </tr>
                </tbody>
            </table>
            <div class="texto-autenticidad">
                ' . $h($atribucion) . '<br>
                La autenticidad de este documento puede ser verificada escaneando el código QR adjunto
                o ingresando el folio <strong>' . $h($folio) . '</strong> y el código <strong>' . $h($codigo) . '</strong>
                en el Sistema Público de Verificación de la Escuela Internacional de Liderazgo de la Mujer &quot;Nora Castañeda&quot;.<br>
                Participante: <strong>' . $h($nombre) . '</strong> &nbsp;|&nbsp; Cédula: <strong>' . $h($cedula) . '</strong>
            </div>
            <div class="libro-linea">
                <span>LIBRO: ' . $h($libro) . '</span>
                <span>FOLIO: ' . $h($folio) . '</span>
                <span>LÍNEA DEL LIBRO: ' . str_pad((string) $linea, 4, '0', STR_PAD_LEFT) . '</span>
            </div>
            <div class="qr-contenedor">'
                . ($qrSrc !== '' ? '<img src="' . $h($qrSrc) . '" alt="QR">' : '')
                . '<div class="folio">' . $h($folio) . '</div>'
                . '<div class="codigo">' . $h($codigo) . '</div>'
                . '<div class="qr-txt">VALIDACIÓN DIGITAL</div>'
            . '</div>
        </div>';
    }
}

if (!function_exists('foro_certificado_pdf_output')) {
    function foro_certificado_pdf_output(array $d, string $nombreArchivo, bool $descargar = true, array $config = []): string
    {
        $html = '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8">'
            . '<title>Certificado de Foro</title>'
            . '<style>' . certificado_css($config) . '</style></head><body>'
            . foro_certificado_html($d, $config)
            . '</body></html>';

        $options = new \Dompdf\Options();
        $options->setChroot(dirname(__DIR__));
        $options->setIsRemoteEnabled(false);
        $options->setIsHtml5ParserEnabled(true);
        $options->setDefaultFont('Times New Roman');
        $options->setTempDir(sys_get_temp_dir());

        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
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
            }
            echo $pdf;
            exit;
        }
        return $pdf;
    }
}

if (!function_exists('foro_certificado_datos')) {
    function foro_certificado_datos(PDO $pdo, int $id_certificado_foro): ?array
    {
        $st = $pdo->prepare("SELECT fc.rol_certificado, ce.*, u.nombre, u.apellido, u.cedula,
                                    u.email, u.correo, f.nombre AS foro_nombre, f.codigo_foro,
                                    f.fecha_inicio, f.fecha_fin
                               FROM public.foro_certificados fc
                               JOIN public.certificados_emitidos ce ON ce.id_certificado = fc.id_certificado_emitido
                               JOIN public.foro_participantes p ON p.id_participante = fc.id_participante
                               JOIN public.usuarios u ON u.id_usuario = p.id_estudiante
                               JOIN public.foros_eventos f ON f.id_foro = p.id_foro
                              WHERE fc.id_certificado_foro = :id LIMIT 1");
        $st->execute([':id' => $id_certificado_foro]);
        $d = $st->fetch();
        if (!$d) { return null; }
        $st = $pdo->prepare('SELECT nombre_tema, horas_academicas, ponente FROM public.foro_temas WHERE id_foro = :f ORDER BY orden_tema, id_tema');
        $st->execute([':f' => $d['id_foro']]);
        $d['temas'] = $st->fetchAll(PDO::FETCH_ASSOC);
        return $d;
    }
}
