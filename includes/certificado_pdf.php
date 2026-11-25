<?php
// /sistema_nora/includes/certificado_pdf.php
// Emisión y renderizado de certificados de culminación de estudios en PDF.
// Incluye folio, libro, línea del libro, código de validación, firma de la
// máxima autoridad y plantillas de fondo configurables.

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
        if (!$ts) { $ts = time(); }
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

if (!function_exists('certificado_config')) {
    /**
     * Configuración del módulo (plantillas, libro, folio, línea y autoridad).
     */
    function certificado_config(PDO $pdo): array
    {
        $cfg = [
            'plantilla_frente'  => 'assets/img/plantilla_frente.jpg',
            'plantilla_reverso' => 'assets/img/plantilla_reverso.jpg',
            'libro_numero'      => 'Libro 1',
            'folio_inicial'     => 1,
            'linea_inicial'     => 1,
            'folio_actual'      => 1,
            'linea_actual'      => 1,
            'lineas_por_folio'  => 20,
            'id_autoridad'      => null,
            'autoridad_cargo'   => '',
            'autoridad_nombre'  => '',
            'autoridad_firma'   => '',
        ];

        try {
            $st = $pdo->query("SELECT c.*, a.cargo AS autoridad_cargo,
                                      a.nombre_apellido AS autoridad_nombre,
                                      a.firma_digital AS autoridad_firma
                                 FROM public.certificado_configuracion c
                                 LEFT JOIN public.configuracion_autoridades a
                                        ON a.id_autoridad = c.id_autoridad
                                WHERE c.id_config = 1 LIMIT 1");
            $fila = $st->fetch();
            if ($fila) { $cfg = array_merge($cfg, $fila); }
        } catch (Throwable $e) {
            // Si la migración aún no se ejecuta, se usan los valores por defecto.
        }

        if (empty($cfg['autoridad_nombre'])) {
            $autoridades = certificado_autoridades($pdo);
            if ($autoridades) {
                $cfg['autoridad_cargo']  = $autoridades[0]['cargo'] ?? '';
                $cfg['autoridad_nombre'] = $autoridades[0]['nombre_apellido'] ?? '';
                $cfg['autoridad_firma']  = $autoridades[0]['firma_digital'] ?? '';
            }
        }

        $cfg['frente_data'] = sistema_asset_data_uri((string) $cfg['plantilla_frente']);
        $cfg['reverso_data'] = sistema_asset_data_uri((string) $cfg['plantilla_reverso']);
        $firma = trim((string) ($cfg['autoridad_firma'] ?? ''));
        $cfg['firma_data'] = $firma !== '' ? sistema_asset_data_uri($firma) : '';

        return $cfg;
    }
}

if (!function_exists('certificado_asignar_folio')) {
    /**
     * Reserva de forma atómica el folio y la línea del libro.
     * Un folio admite varias líneas (configurables, 20 por defecto); al
     * agotarse se pasa al folio siguiente y la línea vuelve a 1.
     * Debe llamarse dentro de una transacción abierta.
     */
    function certificado_asignar_folio(PDO $pdo): array
    {
        $st = $pdo->query("SELECT folio_actual, folio_inicial, linea_actual, linea_inicial,
                                  lineas_por_folio, libro_numero
                             FROM public.certificado_configuracion
                            WHERE id_config = 1 FOR UPDATE");
        $cfg = $st->fetch();
        if (!$cfg) {
            throw new Exception('No existe la configuración de certificados. Ejecute las migraciones 004 y 008.');
        }

        $porFolio = max(1, min(100, (int) ($cfg['lineas_por_folio'] ?? 20)));
        $folioNum = max((int) $cfg['folio_actual'], (int) $cfg['folio_inicial'], 1);
        $linea    = max((int) $cfg['linea_actual'], (int) $cfg['linea_inicial'], 1);

        if ($linea > $porFolio) {
            $folioNum++;
            $linea = 1;
        }

        $folio  = 'CERT-' . date('Y') . '-' . str_pad((string) $folioNum, 5, '0', STR_PAD_LEFT);
        $codigo = 'VAL-' . strtoupper(substr(bin2hex(random_bytes(10)), 0, 12));

        $folioSiguiente = $folioNum;
        $lineaSiguiente = $linea + 1;
        if ($lineaSiguiente > $porFolio) {
            $folioSiguiente++;
            $lineaSiguiente = 1;
        }

        $upd = $pdo->prepare("UPDATE public.certificado_configuracion
                                 SET folio_actual = :f, linea_actual = :l, fecha_actualizacion = CURRENT_TIMESTAMP
                               WHERE id_config = 1");
        $upd->execute([':f' => $folioSiguiente, ':l' => $lineaSiguiente]);

        return [
            'folio'             => $folio,
            'libro'             => (string) $cfg['libro_numero'],
            'linea_libro'       => $linea,
            'codigo_validacion' => $codigo,
        ];
    }
}

if (!function_exists('certificado_obtener')) {
    /**
     * Devuelve el certificado emitido para la inscripción, o lo emite si la
     * malla está completada. Asigna folio, libro y línea de forma atómica.
     */
    function certificado_obtener(PDO $pdo, int $id_inscripcion, int $id_emisor = 0): array
    {
        $ins = academico_estado_inscripcion($pdo, $id_inscripcion);
        if (!$ins) {
            throw new Exception('La inscripción indicada no existe.');
        }

        // El documento emitido conserva su folio original aunque cambien las notas.
        $stmt = $pdo->prepare("SELECT * FROM public.certificados_emitidos
                                WHERE id_estudiante = :est AND id_malla = :malla AND tipo = 'Culminacion'
                                LIMIT 1");
        $stmt->execute([':est' => $ins['id_estudiante'], ':malla' => $ins['id_malla']]);
        $existente = $stmt->fetch();
        if ($existente) { return $existente; }

        $estado = $ins['malla_estado'];
        if (empty($estado['completado'])) {
            throw new Exception(
                'El estudiante aún no ha completado su malla curricular ('
                . $estado['aprobadas'] . ' de ' . $estado['total_materias'] . ' materias aprobadas).'
            );
        }

        $propia = !$pdo->inTransaction();
        if ($propia) { $pdo->beginTransaction(); }

        if ($id_emisor > 0) {
            $chk = $pdo->prepare("SELECT 1 FROM public.usuarios WHERE id_usuario = :u");
            $chk->execute([':u' => $id_emisor]);
            if (!$chk->fetchColumn()) { $id_emisor = 0; }
        }

        try {
            $datos = certificado_asignar_folio($pdo);

            $stmt = $pdo->prepare("INSERT INTO public.certificados_emitidos
                    (id_inscripcion, id_estudiante, id_malla, tipo, folio, id_usuario_emisor,
                     libro, linea_libro, codigo_validacion)
                    VALUES (:ins, :est, :malla, 'Culminacion', :folio, :emisor,
                            :libro, :linea, :codigo)
                    RETURNING id_certificado");
            $stmt->execute([
                ':ins'    => $id_inscripcion,
                ':est'    => $ins['id_estudiante'],
                ':malla'  => $ins['id_malla'],
                ':folio'  => $datos['folio'],
                ':emisor' => $id_emisor > 0 ? $id_emisor : null,
                ':libro'  => $datos['libro'],
                ':linea'  => $datos['linea_libro'],
                ':codigo' => $datos['codigo_validacion'],
            ]);
            $idCertificado = (int) $stmt->fetchColumn();

            if ($propia) { $pdo->commit(); }
        } catch (Throwable $e) {
            if ($propia && $pdo->inTransaction()) { $pdo->rollBack(); }
            if ($e instanceof PDOException && ($e->errorInfo[0] ?? '') === '23505') {
                $stmt = $pdo->prepare("SELECT * FROM public.certificados_emitidos
                                        WHERE id_estudiante = :est AND id_malla = :malla AND tipo = 'Culminacion'
                                        LIMIT 1");
                $stmt->execute([':est' => $ins['id_estudiante'], ':malla' => $ins['id_malla']]);
                $dup = $stmt->fetch();
                if ($dup) { return $dup; }
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
                       f.nombre AS foro_nombre, f.codigo_foro, f.tipo_foro,
                       f.fecha_inicio AS foro_fecha_inicio, f.fecha_fin AS foro_fecha_fin,
                       fc.rol_certificado,
                       (SELECT COALESCE(SUM(mm.horas_academicas), 0)
                          FROM public.malla_materias mm WHERE mm.id_malla = ce.id_malla) AS horas_academicas,
                       (SELECT COUNT(*) FROM public.malla_materias mm WHERE mm.id_malla = ce.id_malla) AS total_materias,
                       (SELECT COALESCE(SUM(t.horas_academicas), 0)
                          FROM public.foro_temas t WHERE t.id_foro = f.id_foro) AS foro_horas,
                       (SELECT COUNT(*) FROM public.foro_temas t WHERE t.id_foro = f.id_foro) AS foro_temas
                  FROM public.certificados_emitidos ce
                  JOIN public.usuarios u ON u.id_usuario = ce.id_estudiante
                  LEFT JOIN public.mallas_curriculares m ON m.id_malla = ce.id_malla
                  LEFT JOIN public.inscripciones i ON i.id_inscripcion = ce.id_inscripcion
                  LEFT JOIN public.cohortes co ON co.id_cohorte = i.id_curso
                  LEFT JOIN public.foros_eventos f ON f.id_foro = ce.id_foro
                  LEFT JOIN public.foro_certificados fc ON fc.id_certificado_emitido = ce.id_certificado
                 WHERE ce.folio = :folio LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':folio' => $folio]);
        $fila = $stmt->fetch();
        return $fila ?: null;
    }
}

if (!function_exists('certificado_css')) {
    function certificado_css(array $config = []): string
    {
        $frente  = $config['frente_data'] ?? sistema_asset_data_uri('assets/img/plantilla_frente.jpg');
        $reverso = $config['reverso_data'] ?? sistema_asset_data_uri('assets/img/plantilla_reverso.jpg');

        return '
        @page { size: letter landscape; margin: 0; }
        * { box-sizing: border-box; }
        body { margin: 0; padding: 0; font-family: "Times New Roman", Times, serif; color: #1a1a1a; }
        .hoja { width: 11in; height: 8.5in; position: relative; page-break-after: always; overflow: hidden; }
        .hoja.ultima { page-break-after: auto; }
        .frente { background-image: url("' . $frente . '"); background-repeat: no-repeat; background-position: center; background-size: 100% 100%; }
        .reverso { background-image: url("' . $reverso . '"); background-repeat: no-repeat; background-position: center; background-size: 100% 100%; }
        .nombre-estudiante {
            position: absolute; top: 38%; left: 15%; width: 80%; text-align: center;
            font-size: 29pt; font-weight: bold; color: #1a1a1a; text-transform: uppercase;
        }
        .cedula-estudiante {
            position: absolute; top: 48%; left: 15%; width: 80%; text-align: center;
            font-size: 14pt; color: #521f29; font-weight: bold;
        }
        .curso-realizado {
            position: absolute; top: 62%; left: 15%; width: 80%; text-align: center;
            font-size: 18pt; color: #630E2B; font-weight: bold; text-transform: uppercase;
        }
        .fecha-maracay {
            position: absolute; bottom: 30%; left: 10%; width: 80%; text-align: center;
            font-size: 13pt; color: #333; font-style: italic;
        }
        .firma-bloque { position: absolute; bottom: 9%; right: 7%; width: 3.4in; text-align: center; }
        .firma-bloque img { height: 0.65in; max-width: 3in; display: block; margin: 0 auto 2px auto; }
        .firma-bloque .linea { border-top: 1px solid #521f29; height: 0.42in; }
        .firma-bloque .nombre { font-size: 10pt; font-weight: bold; color: #521f29; text-transform: uppercase; }
        .firma-bloque .cargo { font-size: 8.5pt; color: #6b5b4d; }
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
            position: absolute; bottom: 17%; left: 8%; width: 52%;
            font-size: 8.2pt; color: #555555; text-align: justify; line-height: 1.35;
        }
        .texto-autenticidad strong { color: #521f29; }
        .libro-linea {
            position: absolute; bottom: 9%; left: 8%; width: 52%;
            font-size: 9pt; color: #521f29; font-weight: bold;
        }
        .libro-linea span { display: inline-block; margin-right: 22px; }
        .qr-contenedor { position: absolute; bottom: 8%; right: 8%; text-align: center; width: 1.55in; }
        .qr-contenedor img { width: 1.15in; height: 1.15in; }
        .qr-contenedor .folio { font-size: 7.5pt; font-weight: bold; color: #521f29; margin-top: 3px; }
        .qr-contenedor .codigo { font-size: 6pt; color: #521f29; letter-spacing: 0.3px; }
        .qr-contenedor .qr-txt { font-size: 6.5pt; color: #6b5b4d; }
        ';
    }
}

if (!function_exists('certificado_html_hoja')) {
    /**
     * Construye las dos caras (frente y reverso) de un certificado.
     */
    function certificado_html_hoja(array $item, bool $esUltima = false, array $config = []): string
    {
        $ins = $item['ins'];
        $cert = $item['cert'];
        $estado = $ins['malla_estado'];

        $nombre = htmlspecialchars(mb_strtoupper(trim($ins['nombre'] . ' ' . $ins['apellido']), 'UTF-8'), ENT_QUOTES, 'UTF-8');
        $cedula = htmlspecialchars(trim((string) ($ins['cedula'] ?? '')), ENT_QUOTES, 'UTF-8');
        $programa = htmlspecialchars(mb_strtoupper($ins['nombre_programa'], 'UTF-8'), ENT_QUOTES, 'UTF-8');
        $folio = htmlspecialchars($cert['folio'], ENT_QUOTES, 'UTF-8');
        $codigo = htmlspecialchars((string) ($cert['codigo_validacion'] ?? ''), ENT_QUOTES, 'UTF-8');
        $libro = htmlspecialchars((string) ($cert['libro'] ?? 'Libro 1'), ENT_QUOTES, 'UTF-8');
        $linea = (int) ($cert['linea_libro'] ?? 0);
        $fecha = certificado_fecha_larga($cert['fecha_emision'] ?? null);

        $qrTexto = sistema_qr_url($cert['folio'], (string) ($cert['codigo_validacion'] ?? ''));
        $qrSrc = sistema_qr_data_uri($qrTexto, 'cert_' . preg_replace('/[^A-Za-z0-9]/', '', $cert['folio']));

        $autoridadNombre = htmlspecialchars(mb_strtoupper((string) ($config['autoridad_nombre'] ?? ''), 'UTF-8'), ENT_QUOTES, 'UTF-8');
        $autoridadCargo  = htmlspecialchars((string) ($config['autoridad_cargo'] ?? ''), ENT_QUOTES, 'UTF-8');
        $firmaSrc        = (string) ($config['firma_data'] ?? '');
        $firmaHtml = $firmaSrc !== ''
            ? '<img src="' . htmlspecialchars($firmaSrc, ENT_QUOTES, 'UTF-8') . '" alt="Firma">'
            : '<div class="linea"></div>';

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
            <div class="cedula-estudiante">CÉDULA: ' . $cedula . '</div>
            <div class="curso-realizado">' . $programa . '</div>
            <div class="fecha-maracay">Dado en Maracay, Estado Aragua, a los ' . htmlspecialchars($fecha, ENT_QUOTES, 'UTF-8') . '.</div>
            <div class="firma-bloque">
                ' . $firmaHtml . '
                <div class="nombre">' . $autoridadNombre . '</div>
                <div class="cargo">' . $autoridadCargo . '</div>
            </div>
        </div>
        <div class="hoja reverso' . $claseUltima . '">
            <div class="titulo-curso-rev">TÍTULO: ' . $programa . '</div>
            <table class="tabla-materias" style="font-size:' . $fuenteTabla . ';">
                <thead>
                    <tr>
                        <th>N°</th>
                        <th>Contenido programático / Módulo</th>
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
                o ingresando el folio <strong>' . $folio . '</strong> y el código <strong>' . $codigo . '</strong>
                en el Sistema Público de Verificación de la Escuela Internacional de Liderazgo de la Mujer &quot;Nora Castañeda&quot;.<br>
                Estudiante: <strong>' . $nombre . '</strong> &nbsp;|&nbsp; Cédula: <strong>' . $cedula . '</strong>
                &nbsp;|&nbsp; Cohorte: <strong>' . htmlspecialchars($ins['codigo_cohorte'], ENT_QUOTES, 'UTF-8') . '</strong>
            </div>
            <div class="libro-linea">
                <span>LIBRO: ' . $libro . '</span>
                <span>FOLIO: ' . $folio . '</span>
                <span>LÍNEA DEL LIBRO: ' . str_pad((string) $linea, 4, '0', STR_PAD_LEFT) . '</span>
            </div>
            <div class="qr-contenedor">'
                . ($qrSrc !== '' ? '<img src="' . $qrSrc . '" alt="QR">' : '')
                . '<div class="folio">' . $folio . '</div>'
                . '<div class="codigo">' . $codigo . '</div>'
                . '<div class="qr-txt">VALIDACIÓN DIGITAL</div>'
            . '</div>
        </div>';
    }
}

if (!function_exists('certificado_html_documento')) {
    function certificado_html_documento(array $items, array $config = []): string
    {
        $cuerpo = '';
        $total = count($items);
        foreach ($items as $i => $item) {
            $cuerpo .= certificado_html_hoja($item, ($i === $total - 1), $config);
        }

        return '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8">'
            . '<title>Certificado de Culminación</title>'
            . '<style>' . certificado_css($config) . '</style></head><body>'
            . $cuerpo
            . '</body></html>';
    }
}

if (!function_exists('certificado_pdf_output')) {
    /**
     * Genera el PDF (una o varias hojas) y lo envía/retorna.
     */
    function certificado_pdf_output(array $items, string $nombreArchivo, bool $descargar = true, array $config = []): string
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
        $dompdf->loadHtml(certificado_html_documento($items, $config), 'UTF-8');
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
