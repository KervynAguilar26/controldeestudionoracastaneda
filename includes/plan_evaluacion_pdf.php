<?php
// /sistema_nora/includes/plan_evaluacion_pdf.php
// Generación del Plan de Evaluación por Contenidos en PDF real (carta) con Dompdf.

require_once __DIR__ . '/dompdf_autoload.php';
require_once __DIR__ . '/qr_generator.php';
require_once __DIR__ . '/contenidos_evaluacion.php';

if (!function_exists('plan_evaluacion_pdf_dato')) {
    function plan_evaluacion_pdf_dato(string $etiqueta, string $valor, int $ancho = 25): string
    {
        return '<td width="' . $ancho . '%" style="background:#fbf7f2;border:1px solid #e3d6c8;border-left:4px solid #521f29;padding:5px;vertical-align:top;">'
            . '<span style="font-size:6.5pt;color:#8a7562;font-weight:bold;text-transform:uppercase;letter-spacing:.4px;">'
            . htmlspecialchars($etiqueta, ENT_QUOTES, 'UTF-8') . '</span><br>'
            . '<span style="font-size:8.5pt;color:#521f29;font-weight:bold;">'
            . htmlspecialchars($valor, ENT_QUOTES, 'UTF-8') . '</span></td>';
    }
}

if (!function_exists('plan_evaluacion_pdf_html')) {
    /**
     * @param array $ctx      cohorte/programa/asignatura/profesor (nombre_programa, tipo_oferta,
     *                        codigo_cohorte, salon_aula, num_periodo, nombre_materia, modulo_num,
     *                        horas_academicas, nombre_profesor, tipo_regimen)
     * @param array $planes   filas de plan_evaluacion (cada una con 'contenidos' => array)
     */
    function plan_evaluacion_pdf_html(array $ctx, array $planes): string
    {
        $logoMin = sistema_asset_data_uri('assets/img/Logo del Ministerio 999 x 150-01.png', 420);
        $logoEsc = sistema_asset_data_uri('assets/img/LogoEscuelaNora_NUEVO.png', 300);

        $programa = (string) ($ctx['nombre_programa'] ?? '');
        $tipoOferta = (string) ($ctx['tipo_oferta'] ?? '');
        $cohorte = (string) ($ctx['codigo_cohorte'] ?? '');
        $seccionLabel = $cohorte !== '' ? $cohorte : 'Sin sección';
        $asignatura = 'Módulo ' . ($ctx['modulo_num'] ?? '') . ' — ' . ($ctx['nombre_materia'] ?? '');
        $profesor = (string) ($ctx['nombre_profesor'] ?? 'Docente por asignar');
        $regimen = (string) ($ctx['tipo_regimen'] ?? 'Período');
        $periodo = $regimen !== '' ? $regimen : 'Período';
        $sumaTotal = 0.0;
        foreach ($planes as $pl) {
            $sumaTotal += (float) $pl['porcentaje_peso'];
        }

        $html = '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><title>Plan de Evaluación</title>'
            . '<style>@page { size: letter portrait; margin: 12mm 10mm; } '
            . 'body { font-family: "DejaVu Sans", Arial, Helvetica, sans-serif; color:#2d2d2d; font-size:8pt; } '
            . 'table { border-collapse: collapse; } </style></head><body>';

        // Membrete institucional
        $html .= '<table width="100%" cellspacing="0" cellpadding="0" style="border-bottom:3px solid #ff8a00;"><tr>'
            . '<td width="25%" align="left" valign="middle">' . ($logoMin !== '' ? '<img src="' . $logoMin . '" style="height:48px;">' : '') . '</td>'
            . '<td width="50%" align="center" valign="middle">'
                . '<div style="font-size:7.5pt;font-weight:bold;color:#521f29;letter-spacing:.5px;">REPÚBLICA BOLIVARIANA DE VENEZUELA</div>'
                . '<div style="font-size:8pt;font-weight:bold;color:#521f29;">Ministerio del Poder Popular para la Mujer y la Igualdad de Género</div>'
                . '<div style="font-size:12pt;font-weight:bold;color:#521f29;line-height:1.2;">Escuela Internacional de Liderazgo de la Mujer "Nora Castañeda"</div>'
                . '<div style="font-size:7pt;color:#6b5b4d;">Sede Principal Bello Monte · Maracay, Edo. Aragua</div>'
            . '</td>'
            . '<td width="25%" align="right" valign="middle">' . ($logoEsc !== '' ? '<img src="' . $logoEsc . '" style="height:60px;">' : '') . '</td>'
        . '</tr></table>';

        // Título (tabla de una sola celda: centrado confiable en Dompdf)
        $html .= '<table width="100%" cellspacing="0" cellpadding="0" style="margin-top:12px;"><tr>'
            . '<td align="center" style="text-align:center;font-size:16pt;font-weight:bold;color:#521f29;text-transform:uppercase;">Plan de Evaluación por Contenidos</td>'
            . '</tr></table>'
            . '<table width="100%" cellspacing="0" cellpadding="0" style="margin-bottom:10px;"><tr>'
            . '<td align="center" style="text-align:center;font-size:10pt;font-weight:bold;color:#ff8a00;text-transform:uppercase;">'
            . htmlspecialchars($seccionLabel, ENT_QUOTES, 'UTF-8') . '</td>'
            . '</tr></table>';

        // Datos de cabecera (carrera/programa, sección, profesor, asignatura)
        $html .= '<table width="100%" cellspacing="0" cellpadding="6" style="border-collapse:collapse;margin-bottom:4px;"><tr>'
            . plan_evaluacion_pdf_dato('Carrera / Programa', $programa . ($tipoOferta !== '' ? ' (' . $tipoOferta . ')' : ''), 34)
            . plan_evaluacion_pdf_dato('Sección', $seccionLabel . ' · Salón ' . (string)($ctx['salon_aula'] ?? ''), 22)
            . plan_evaluacion_pdf_dato('Período', $periodo . ' ' . intval($ctx['num_periodo'] ?? 1), 12)
            . plan_evaluacion_pdf_dato('Horas', (string) intval($ctx['horas_academicas'] ?? 0) . ' hrs', 10)
            . '</tr><tr>'
            . plan_evaluacion_pdf_dato('Profesor', $profesor, 50)
            . plan_evaluacion_pdf_dato('Asignatura', $asignatura, 50)
            . '</tr></table>';

        $html .= '<table width="100%" cellspacing="0" cellpadding="0" style="margin-bottom:8px;"><tr>'
            . '<td align="left" style="font-size:7pt;color:#6b5b4d;">Evaluaciones planificadas: ' . count($planes) . ' de 4 · Ponderación total asignada: ' . number_format($sumaTotal, 2) . '% / 100%</td>'
            . '<td align="right" style="font-size:7pt;color:#6b5b4d;">Generado el ' . date('d/m/Y H:i') . '</td>'
            . '</tr></table>';

        // Detalle del plan de evaluación
        if (empty($planes)) {
            $html .= '<div style="border:1px solid #e3d6c8;background:#fbf7f2;padding:12px;text-align:center;color:#8a7562;font-weight:bold;">'
                . 'No hay evaluaciones planificadas para esta sección y asignatura.</div>';
        } else {
            foreach ($planes as $i => $pl) {
                $contenidos = $pl['contenidos'] ?? [];
                $peso = (float) $pl['porcentaje_peso'];

                $fecha = '';
                if (!empty($pl['fecha_evaluacion'])) {
                    $ts = strtotime($pl['fecha_evaluacion']);
                    $fecha = $ts ? date('d/m/Y', $ts) : (string) $pl['fecha_evaluacion'];
                }

                $html .= '<table width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;margin-top:10px;">'
                    . '<tr><td style="background:#521f29;color:#ffffff;font-size:10pt;font-weight:bold;padding:6px 8px;">'
                        . 'Evaluación ' . ($i + 1) . ': ' . htmlspecialchars((string) $pl['titulo_evaluacion'], ENT_QUOTES, 'UTF-8')
                        . '</td><td style="background:#ff8a00;color:#521f29;font-size:10pt;font-weight:bold;padding:6px 8px;text-align:right;width:90px;">'
                        . number_format($peso, 2) . '%</td></tr></table>';

                $html .= '<table width="100%" cellspacing="0" cellpadding="5" style="border-collapse:collapse;border:1px solid #e3d6c8;">'
                    . '<tr>'
                    . '<td width="33%" style="background:#fbf7f2;border:1px solid #e3d6c8;font-size:7pt;color:#8a7562;font-weight:bold;">MODO DE CALIFICACIÓN<br><span style="color:#521f29;font-size:8.5pt;">'
                        . htmlspecialchars((string) $pl['tipo_ponderacion'], ENT_QUOTES, 'UTF-8') . '</span></td>'
                    . '<td width="34%" style="background:#fbf7f2;border:1px solid #e3d6c8;font-size:7pt;color:#8a7562;font-weight:bold;">FECHA DE EVALUACIÓN<br><span style="color:#521f29;font-size:8.5pt;">'
                        . htmlspecialchars($fecha !== '' ? $fecha : 'No definida', ENT_QUOTES, 'UTF-8') . '</span></td>'
                    . '<td width="33%" style="background:#fbf7f2;border:1px solid #e3d6c8;font-size:7pt;color:#8a7562;font-weight:bold;">INSTRUMENTO<br><span style="color:#521f29;font-size:8.5pt;">'
                        . htmlspecialchars((string) ($pl['instrumento_evaluacion'] ?: 'No definido'), ENT_QUOTES, 'UTF-8') . '</span></td>'
                    . '</tr></table>';

                if (!empty($pl['descripcion_libre'])) {
                    $html .= '<table width="100%" cellspacing="0" cellpadding="6" style="border-collapse:collapse;border:1px solid #e3d6c8;border-top:0;">'
                        . '<tr><td style="font-size:7pt;color:#8a7562;font-weight:bold;">DESCRIPCIÓN LIBRE DE LA EVALUACIÓN<br>'
                        . '<span style="font-size:8.5pt;color:#2d2d2d;font-weight:normal;">'
                        . nl2br(htmlspecialchars((string) $pl['descripcion_libre'], ENT_QUOTES, 'UTF-8')) . '</span></td></tr></table>';
                }

                $html .= '<div style="font-size:8.5pt;font-weight:bold;color:#521f29;margin:8px 0 4px;">Contenidos evaluados: '
                    . count($contenidos) . '</div>';

                if (empty($contenidos)) {
                    $html .= '<div style="border:1px dashed #cdbcaa;padding:6px;color:#8a7562;font-size:8pt;">Sin contenidos asociados (plan anterior).</div>';
                } else {
                    $html .= '<table width="100%" cellspacing="0" cellpadding="5" style="border-collapse:collapse;font-size:8pt;">'
                        . '<thead><tr>'
                        . '<th style="background:#3d161e;color:#ffffff;border:1px solid #3d161e;width:28px;">N°</th>'
                        . '<th style="background:#521f29;color:#ffffff;border:1px solid #521f29;text-align:left;">Contenido</th>'
                        . '<th style="background:#521f29;color:#ffffff;border:1px solid #521f29;width:70px;">Horas</th>'
                        . '</tr></thead><tbody>';
                    foreach ($contenidos as $j => $c) {
                        $html .= '<tr>'
                            . '<td style="border:1px solid #cdbcaa;text-align:center;font-weight:bold;color:#521f29;">' . ($j + 1) . '</td>'
                            . '<td style="border:1px solid #cdbcaa;">' . htmlspecialchars((string) $c['descripcion'], ENT_QUOTES, 'UTF-8') . '</td>'
                            . '<td style="border:1px solid #cdbcaa;text-align:center;">' . intval($c['horas_academicas']) . '</td>'
                            . '</tr>';
                    }
                    $html .= '</tbody></table>';
                }
            }
        }

        $html .= '<table width="100%" cellspacing="0" cellpadding="0" style="margin-top:16px;border-top:1px solid #cdbcaa;padding-top:6px;"><tr>'
            . '<td align="center" style="font-size:8pt;color:#6b5b4d;">'
            . 'Firma del Profesor: ______________________________________<br>'
            . '<span style="font-weight:bold;color:#521f29;">' . htmlspecialchars($profesor, ENT_QUOTES, 'UTF-8') . '</span></td>'
            . '<td align="center" style="font-size:8pt;color:#6b5b4d;">'
            . 'Visto Bueno de Control de Estudio: ______________________</td>'
            . '</tr></table>';

        return $html . '</body></html>';
    }
}

if (!function_exists('plan_evaluacion_pdf_renderizar')) {
    function plan_evaluacion_pdf_renderizar(string $html, string $nombreArchivo = 'plan_evaluacion.pdf'): void
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
}
