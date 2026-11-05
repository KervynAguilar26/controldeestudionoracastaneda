<?php
// /sistema_nora/includes/horario_pdf.php
// Generación de horarios en archivo PDF real (tamaño carta) con Dompdf.

require_once __DIR__ . '/dompdf_autoload.php';
require_once __DIR__ . '/qr_generator.php';
require_once __DIR__ . '/horario_generator.php';

if (!function_exists('horario_pdf_html_celda')) {
    function horario_pdf_html_celda(?array $celda, string $colorFondo = ''): string
    {
        if (empty($celda['materia'])) {
            return '<td style="background:#ffffff;border:1px solid #cdbcaa;text-align:center;vertical-align:middle;font-size:8pt;color:#b8a99a;">&mdash;</td>';
        }

        $estilo = 'background:' . ($colorFondo !== '' ? $colorFondo : '#fdf6d8') . ';border:1px solid #cdbcaa;text-align:center;vertical-align:middle;padding:3px;';
        $docente = !empty($celda['docente'])
            ? '<span style="font-size:7pt;color:#6b5b4d;">' . htmlspecialchars($celda['docente'], ENT_QUOTES, 'UTF-8') . '</span>'
            : '<span style="font-size:7pt;color:#a89583;font-style:italic;">Docente por asignar</span>';

        return '<td style="' . $estilo . '">'
            . '<span style="font-size:8pt;font-weight:bold;color:#3b2226;">' . htmlspecialchars($celda['materia'], ENT_QUOTES, 'UTF-8') . '</span><br>'
            . $docente
            . '</td>';
    }
}

if (!function_exists('horario_pdf_html')) {
    /**
     * @param array $cohorte Datos de la cohorte (salon_aula, codigo_cohorte, ...)
     * @param array $horario Matriz devuelta por horario_obtener_matriz()
     * @param array $meta    Datos adicionales: bloque de estudiante (titulo, cedula, inscripcion, ...)
     */
    function horario_pdf_html(array $cohorte, array $horario, array $meta = []): string
    {
        $dias = $horario['dias'];
        $bloques = $horario['bloques'];
        $matriz = $horario['matriz'];

        $logoMin = sistema_asset_data_uri('assets/img/Logo del Ministerio 999 x 150-01.png', 420);
        $logoEsc = sistema_asset_data_uri('assets/img/LogoEscuelaNora_NUEVO.png', 300);
        $logoGmv = sistema_asset_data_uri('assets/img/GMVM.png', 300);

        $salon = htmlspecialchars($cohorte['salon_aula'], ENT_QUOTES, 'UTF-8');
        $titulo = $meta['titulo'] ?? 'Horario de Clases';

        $bloqueEst = '';
        if (!empty($meta['cedula'])) {
            $bloqueEst = '
            <table width="100%" cellspacing="0" cellpadding="5" style="border-collapse:collapse; background:#fbf7f2; border-left:4px solid #521f29; border:1px solid #e3d6c8; border-left:4px solid #521f29; margin-bottom:8px;">
                <tr>
                    <td width="34%" style="font-size:8pt;color:#8a7562;font-weight:bold;text-transform:uppercase;">Estudiante</td>
                    <td width="33%" style="font-size:9pt;color:#521f29;font-weight:bold;">'
                        . htmlspecialchars(mb_strtoupper($meta['estudiante'] ?? '', 'UTF-8'), ENT_QUOTES, 'UTF-8') . '</td>
                    <td width="33%" style="font-size:9pt;color:#521f29;font-weight:bold;">C.I. V-'
                        . htmlspecialchars($meta['cedula'], ENT_QUOTES, 'UTF-8') . '</td>
                </tr>
            </table>';
        }

        $cuerpoTabla = '';
        if (empty($bloques)) {
            $cuerpoTabla = '<tr><td colspan="' . (count($dias) + 1) . '" style="border:1px solid #cdbcaa;text-align:center;padding:8pt;font-size:8pt;color:#8a7562;">No hay bloques configurados para esta cohorte.</td></tr>';
        } else {
            foreach ($bloques as $bloque) {
                $cuerpoTabla .= '<tr>'
                    . '<td style="background:#f1e7dc;border:1px solid #cdbcaa;text-align:center;font-size:8pt;font-weight:bold;color:#521f29;padding:3px;">'
                        . htmlspecialchars($bloque['inicio'], ENT_QUOTES, 'UTF-8') . ' - ' . htmlspecialchars($bloque['fin'], ENT_QUOTES, 'UTF-8')
                        . '<br><span style="font-size:6.5pt;color:#8a7562;font-weight:normal;">Hora académica</span></td>';
                foreach ($dias as $dia) {
                    $celda = $matriz[$dia][$bloque['inicio']] ?? null;
                    $color = !empty($celda['materia']) ? horario_color_materia_pdf($celda['materia']) : '';
                    $cuerpoTabla .= horario_pdf_html_celda($celda, $color);
                }
                $cuerpoTabla .= '</tr>';
            }
        }

        $encabezadoDias = '<th style="background:#3d161e;border:1px solid #3d161e;color:#ffffff;font-size:8pt;padding:5px;text-transform:uppercase;width:78pt;">Hora</th>';
        foreach ($dias as $dia) {
            $encabezadoDias .= '<th style="background:#521f29;border:1px solid #521f29;color:#ffffff;font-size:8pt;padding:5px;text-transform:uppercase;">'
                . htmlspecialchars($dia, ENT_QUOTES, 'UTF-8') . '</th>';
        }

        return '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><title>'
            . htmlspecialchars($titulo . ' - ' . $salon, ENT_QUOTES, 'UTF-8')
            . '</title><style>@page { size: letter portrait; margin: 12mm 10mm; } body { font-family: "DejaVu Sans", Arial, Helvetica, sans-serif; color:#2d2d2d; font-size:8pt; } table { border-collapse: collapse; }</style></head><body>'

            . '<table width="100%" cellspacing="0" cellpadding="0" style="border-bottom:3px solid #ff8a00;"><tr>'
                . '<td width="24%" align="left" valign="middle">' . ($logoMin !== '' ? '<img src="' . $logoMin . '" style="height:44px;">' : '') . '</td>'
                . '<td width="52%" align="center" valign="middle">'
                    . '<div style="font-size:7.5pt;font-weight:bold;color:#521f29;letter-spacing:.5px;">REPÚBLICA BOLIVARIANA DE VENEZUELA</div>'
                    . '<div style="font-size:12pt;font-weight:bold;color:#521f29;line-height:1.2;">Escuela Internacional de Liderazgo de la Mujer "Nora Castañeda"</div>'
                    . '<div style="font-size:7pt;color:#6b5b4d;">Sede Principal Bello Monte · Maracay, Edo. Aragua</div>'
                . '</td>'
                . '<td width="12%" align="center" valign="middle">' . ($logoEsc !== '' ? '<img src="' . $logoEsc . '" style="height:56px;">' : '') . '</td>'
                . '<td width="12%" align="center" valign="middle">' . ($logoGmv !== '' ? '<img src="' . $logoGmv . '" style="height:42px;">' : '') . '</td>'
            . '</tr></table>'

            . '<div style="text-align:center;font-size:15pt;font-weight:bold;color:#521f29;text-transform:uppercase;margin-top:12px;">'
                . htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8') . '</div>'
            . '<div style="text-align:center;font-size:10pt;font-weight:bold;color:#ff8a00;text-transform:uppercase;margin-bottom:10px;">'
                . htmlspecialchars($salon, ENT_QUOTES, 'UTF-8') . '</div>'

            . $bloqueEst

            . '<table width="100%" cellspacing="0" cellpadding="6" style="border-collapse:collapse;margin-bottom:10px;"><tr>'
                . horario_pdf_dato('Salón / Aula', $salon)
                . horario_pdf_dato('Cohorte / Grupo', $cohorte['codigo_cohorte'])
                . horario_pdf_dato('Programa', $cohorte['nombre_programa'] ?? '')
                . horario_pdf_dato('Período', ($cohorte['tipo_regimen'] ?: 'Período') . ' ' . intval($cohorte['num_periodo'] ?? 1))
            . '</tr></table>'

            . '<table width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;table-layout:fixed;">'
                . '<thead><tr>' . $encabezadoDias . '</tr></thead>'
                . '<tbody>' . $cuerpoTabla . '</tbody>'
            . '</table>'

            . '<table width="100%" cellspacing="0" cellpadding="0" style="margin-top:8px;"><tr>'
                . '<td align="left" style="font-size:7pt;color:#6b5b4d;">Cada hora académica consta de ' . HORARIO_MINUTOS_CLASE . ' minutos de clase y ' . HORARIO_MINUTOS_DESCANSO . ' minutos de intermedio.</td>'
                . '<td align="right" style="font-size:7pt;color:#6b5b4d;">Generado el ' . date('d/m/Y H:i') . '</td>'
            . '</tr></table>'

            . '</body></html>';
    }
}

if (!function_exists('horario_pdf_dato')) {
    function horario_pdf_dato(string $etiqueta, string $valor): string
    {
        return '<td width="25%" style="background:#fbf7f2;border:1px solid #e3d6c8;border-left:4px solid #521f29;padding:5px;">'
            . '<span style="font-size:6.5pt;color:#8a7562;font-weight:bold;text-transform:uppercase;letter-spacing:.4px;">'
            . htmlspecialchars($etiqueta, ENT_QUOTES, 'UTF-8') . '</span><br>'
            . '<span style="font-size:9pt;color:#521f29;font-weight:bold;">' . htmlspecialchars($valor, ENT_QUOTES, 'UTF-8') . '</span></td>';
    }
}

if (!function_exists('horario_color_materia_pdf')) {
    function horario_color_materia_pdf(string $materia): string
    {
        $paleta = ['#fdecef', '#fff1dd', '#eaf5ee', '#e9f0fb', '#f3ecfa', '#fdf6d8', '#e8f6f7'];
        return $paleta[abs(crc32($materia)) % count($paleta)];
    }
}

if (!function_exists('horario_pdf_renderizar')) {
    function horario_pdf_renderizar(string $html, bool $descargar = true, string $nombreArchivo = 'horario.pdf'): string
    {
        $options = new \Dompdf\Options();
        $options->setChroot(dirname(__DIR__));
        $options->setIsRemoteEnabled(false);
        $options->setIsHtml5ParserEnabled(true);
        $options->setDefaultFont('DejaVu Sans');
        $options->setTempDir(sys_get_temp_dir());

        $dompdf = new \Dompdf\Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('letter', 'portrait');
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

if (!function_exists('horario_pdf_cohorte')) {
    function horario_pdf_cohorte(PDO $pdo, int $id_cohorte, bool $descargar = true): string
    {
        $stmt = $pdo->prepare("SELECT c.*, m.nombre_programa, m.tipo_oferta, m.tipo_regimen
                                 FROM public.cohortes c
                                 JOIN public.mallas_curriculares m ON m.id_malla = c.id_malla
                                WHERE c.id_cohorte = :id LIMIT 1");
        $stmt->execute([':id' => $id_cohorte]);
        $cohorte = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$cohorte) {
            throw new Exception('No se encontró la cohorte solicitada.');
        }

        $horario = horario_obtener_matriz($pdo, $id_cohorte);
        if (empty($horario['ok'])) {
            throw new Exception($horario['mensaje'] ?? 'No se pudo construir el horario.');
        }

        $html = horario_pdf_html($cohorte, $horario, ['titulo' => 'Horario de Clases']);
        $nombre = 'Horario_' . preg_replace('/[^A-Za-z0-9-]/', '', $cohorte['codigo_cohorte']) . '.pdf';

        return horario_pdf_renderizar($html, $descargar, $nombre);
    }
}

if (!function_exists('horario_pdf_estudiante')) {
    function horario_pdf_estudiante(PDO $pdo, int $id_inscripcion, bool $descargar = true): string
    {
        $stmt = $pdo->prepare("SELECT i.*, u.nombre, u.apellido, u.cedula,
                                       co.codigo_cohorte, co.salon_aula, co.num_periodo, co.estatus,
                                       m.nombre_programa, m.tipo_regimen
                                  FROM public.inscripciones i
                                  JOIN public.usuarios u ON u.id_usuario = i.id_estudiante
                                  JOIN public.cohortes co ON co.id_cohorte = i.id_curso
                                  JOIN public.mallas_curriculares m ON m.id_malla = co.id_malla
                                 WHERE i.id_inscripcion = :id LIMIT 1");
        $stmt->execute([':id' => $id_inscripcion]);
        $ins = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$ins) {
            throw new Exception('No se encontró la inscripción solicitada.');
        }

        $horario = horario_obtener_matriz($pdo, (int) $ins['id_curso']);
        if (empty($horario['ok'])) {
            throw new Exception($horario['mensaje'] ?? 'No se pudo construir el horario.');
        }

        $cohorte = [
            'salon_aula'      => $ins['salon_aula'],
            'codigo_cohorte'  => $ins['codigo_cohorte'],
            'nombre_programa' => $ins['nombre_programa'],
            'tipo_regimen'    => $ins['tipo_regimen'],
            'num_periodo'     => $ins['num_periodo'],
        ];

        $html = horario_pdf_html($cohorte, $horario, [
            'titulo'     => 'Horario de Clases del Estudiante',
            'estudiante' => trim($ins['nombre'] . ' ' . $ins['apellido']),
            'cedula'     => $ins['cedula'],
        ]);

        $nombre = 'Horario_Estudiante_' . preg_replace('/[^A-Za-z0-9]/', '', $ins['cedula']) . '.pdf';
        return horario_pdf_renderizar($html, $descargar, $nombre);
    }
}
