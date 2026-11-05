<?php
// /sistema_nora/includes/xlsx.php
// Escritor y lector XLSX en PHP puro, sin depender de la extensión "zip"
// (no instalada en este XAMPP). El ZIP se construye con método "store"
// (sin comprimir), formato que Excel, LibreOffice y Windows aceptan sin
// problemas. El lector soporta method 0 (store) y 8 (deflate) para poder
// leer archivos re-guardados por Excel.

// ---------------------------------------------------------------------------
// Convertir índice de columna (0 = A) a letra de Excel
// ---------------------------------------------------------------------------
if (!function_exists('xlsx_col_letra')) {
    function xlsx_col_letra(int $indice): string
    {
        $letra = '';
        $indice++;
        while ($indice > 0) {
            $resto = ($indice - 1) % 26;
            $letra = chr(65 + $resto) . $letra;
            $indice = intdiv($indice - 1, 26);
        }
        return $letra;
    }
}

// ---------------------------------------------------------------------------
// Limpieza de texto para XML (elimina caracteres de control inválidos)
// ---------------------------------------------------------------------------
if (!function_exists('xlsx_xml_texto')) {
    function xlsx_xml_texto($valor): string
    {
        $texto = (string) $valor;
        $texto = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $texto);
        return htmlspecialchars($texto, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}

// ---------------------------------------------------------------------------
// Empaquetado ZIP mínimo (solo entradas "store", sin comentarios ni zip64)
// ---------------------------------------------------------------------------
if (!function_exists('xlsx_zip')) {
    function xlsx_zip(array $archivos): string
    {
        $dt = getdate();
        $hora_dos = ($dt['hours'] << 11) | ($dt['minutes'] << 5) | ($dt['seconds'] >> 1);
        $fecha_dos = (($dt['year'] - 1980) << 9) | ($dt['mon'] << 5) | $dt['mday'];

        $salida   = '';
        $central  = '';
        $entradas = 0;

        foreach ($archivos as $nombre => $datos) {
            $crc    = hexdec(hash('crc32b', $datos));
            $tamano = strlen($datos);
            $nlen   = strlen($nombre);
            $offset = strlen($salida);

            // Encabezado local (PK\x03\x04) — 30 bytes fijos + nombre
            $salida .= pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, $hora_dos, $fecha_dos, $crc, $tamano, $tamano, $nlen, 0)
                     . $nombre . $datos;

            // Directorio central (PK\x01\x02) — 46 bytes fijos + nombre
            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, $hora_dos, $fecha_dos, $crc, $tamano, $tamano, $nlen, 0, 0, 0, 0, 0, $offset)
                      . $nombre;

            $entradas++;
        }

        // Fin del directorio central (PK\x05\x06) — 22 bytes
        $salida .= $central
                 . pack('VvvvvVVv', 0x06054b50, 0, 0, $entradas, $entradas, strlen($central), strlen($salida), 0);

        return $salida;
    }
}

// ---------------------------------------------------------------------------
// Catálogo fijo de estilos (styles.xml)
// Índices: 0 normal | 1 membrete grande | 2 membrete texto | 3 título naranja
//          4 encabezado de columna | 5 celda con borde | 6 ejemplo (gris itálica)
//          7 celda borde centrada | 8 celda borde negrita | 9 etiqueta negrita vino
//          10 nota itálica sin borde
// ---------------------------------------------------------------------------
if (!function_exists('xlsx_estilos_xml')) {
    function xlsx_estilos_xml(): string
    {
        $alineacion = function (string $extra = ''): string {
            return '<alignment horizontal="center" vertical="center" wrapText="1"/>';
        };
        $xf = function (int $fuente, int $relleno, int $borde, string $al = '', bool $aplicarAl = false) use ($alineacion): string {
            $al = $aplicarAl ? '<alignment ' . $al . '/>' : '';
            return '<xf numFmtId="0" fontId="' . $fuente . '" fillId="' . $relleno . '" borderId="' . $borde . '" xfId="0"'
                . ($aplicarAl ? ' applyAlignment="1"' : '') . '>' . $al . '</xf>';
        };

        $xfs = [
            $xf(0, 0, 0),                                                                 // 0 normal
            $xf(3, 0, 0, 'horizontal="center" vertical="center"', true),                  // 1 membrete grande
            $xf(0, 0, 0, 'horizontal="center" vertical="center"', true),                  // 2 membrete texto
            $xf(6, 4, 0, 'horizontal="left" vertical="center"', true),                    // 3 título naranja
            $xf(2, 2, 1, 'horizontal="center" vertical="center" wrapText="1"', true),     // 4 encabezado columna
            $xf(0, 0, 1, 'horizontal="left" vertical="center" wrapText="1"', true),       // 5 celda borde
            $xf(5, 0, 1, 'horizontal="left" vertical="center" wrapText="1"', true),       // 6 ejemplo gris
            $xf(0, 0, 1, 'horizontal="center" vertical="center"', true),                  // 7 borde centrada
            $xf(1, 0, 1, 'horizontal="left" vertical="center"', true),                    // 8 borde negrita
            $xf(4, 0, 0, 'horizontal="left" vertical="center"', true),                    // 9 etiqueta negrita vino
            $xf(5, 0, 0, 'horizontal="left" vertical="center" wrapText="1"', true),       // 10 nota itálica
        ];

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="7">'
            . '<font><sz val="11"/><color theme="1"/><name val="Calibri"/><family val="2"/></font>'                                                          // 0
            . '<font><b/><sz val="11"/><color theme="1"/><name val="Calibri"/><family val="2"/></font>'                                                      // 1
            . '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/><family val="2"/></font>'                                                  // 2
            . '<font><b/><sz val="13"/><color rgb="FF521F29"/><name val="Calibri"/><family val="2"/></font>'                                                  // 3
            . '<font><b/><sz val="10"/><color rgb="FF521F29"/><name val="Calibri"/><family val="2"/></font>'                                                  // 4
            . '<font><i/><sz val="10"/><color rgb="FF8A7562"/><name val="Calibri"/><family val="2"/></font>'                                                  // 5
            . '<font><b/><sz val="11"/><color rgb="FFFF8A00"/><name val="Calibri"/><family val="2"/></font>'                                                  // 6
            . '</fonts>'
            . '<fills count="5">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF521F29"/><bgColor indexed="64"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFFF8A00"/><bgColor indexed="64"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFF3E9DF"/><bgColor indexed="64"/></patternFill></fill>'
            . '</fills>'
            . '<borders count="2">'
            . '<border><left/><right/><top/><bottom/><diagonal/></border>'
            . '<border><left style="thin"><color rgb="FFBFA98F"/></left><right style="thin"><color rgb="FFBFA98F"/></right>'
            . '<top style="thin"><color rgb="FFBFA98F"/></top><bottom style="thin"><color rgb="FFBFA98F"/></bottom><diagonal/></border>'
            . '</borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="' . count($xfs) . '">' . implode('', $xfs) . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }
}

// ---------------------------------------------------------------------------
// Generar un archivo XLSX completo.
// $hoja = [
//   'nombre'    => 'Hoja1',
//   'anchos'    => [14, 24, ...],          // anchos de columna (opcional)
//   'combinadas'=> ['A1:G1', ...],         // combinaciones extra (opcional)
//   'filas'     => [
//       ['alto' => 22,                     // alto de fila opcional
//        'celdas' => [['v' => 'texto', 's' => 1, 'span' => 7], ...]],
//   ],
// ]
// 'span' combina horizontalmente desde la celda actual (se auto-agrega a combinadas)
// ---------------------------------------------------------------------------
if (!function_exists('xlsx_generar')) {
    function xlsx_generar(array $hoja): string
    {
        $combinadas = $hoja['combinadas'] ?? [];
        $filas_xml  = '';

        foreach ($hoja['filas'] as $num => $fila) {
            $fila_num  = $num + 1;
            $atributos = '';
            if (!empty($fila['alto'])) {
                $atributos = ' ht="' . (float) $fila['alto'] . '" customHeight="1"';
            }
            $celdas_xml = '';
            $col        = 0;

            foreach ($fila['celdas'] ?? [] as $celda) {
                $ref = xlsx_col_letra($col) . $fila_num;
                $estilo = (int) ($celda['s'] ?? 0);
                $valor  = $celda['v'] ?? '';
                $span   = max(1, (int) ($celda['span'] ?? 1));

                if ($span > 1) {
                    $combinadas[] = $ref . ':' . xlsx_col_letra($col + $span - 1) . $fila_num;
                }

                $celdas_xml .= '<c r="' . $ref . '" s="' . $estilo . '" t="inlineStr">'
                    . '<is><t xml:space="preserve">' . xlsx_xml_texto($valor) . '</t></is></c>';

                $col += $span;
            }

            $filas_xml .= '<row r="' . $fila_num . '"' . $atributos . '>' . $celdas_xml . '</row>';
        }

        $cols_xml = '';
        foreach ((array) ($hoja['anchos'] ?? []) as $i => $ancho) {
            $cols_xml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . (float) $ancho . '" customWidth="1"/>';
        }

        $merge_xml = '';
        if (!empty($combinadas)) {
            $merge_xml = '<mergeCells count="' . count($combinadas) . '">';
            foreach ($combinadas as $rango) {
                $merge_xml .= '<mergeCell ref="' . $rango . '"/>';
            }
            $merge_xml .= '</mergeCells>';
        }

        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . ($cols_xml !== '' ? '<cols>' . $cols_xml . '</cols>' : '')
            . '<sheetData>' . $filas_xml . '</sheetData>'
            . $merge_xml
            . '</worksheet>';

        $nombre_hoja = substr(preg_replace('/[^A-Za-z0-9 ]/', '', $hoja['nombre'] ?? 'Hoja1'), 0, 31);

        $archivos = [
            '[Content_Types].xml' =>
                '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                . '<Default Extension="xml" ContentType="application/xml"/>'
                . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
                . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
                . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
                . '</Types>',
            '_rels/.rels' =>
                '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
                . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
                . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
                . '</Relationships>',
            'docProps/core.xml' =>
                '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties"'
                . ' xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/"'
                . ' xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
                . '<dc:creator>Sistema Escuela Nora Castañeda</dc:creator>'
                . '<cp:lastModifiedBy>Sistema Escuela Nora Castañeda</cp:lastModifiedBy>'
                . '<dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('Y-m-d\TH:i:s\Z') . '</dcterms:created>'
                . '</cp:coreProperties>',
            'docProps/app.xml' =>
                '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties">'
                . '<Application>Sistema Escuela Nora Castañeda</Application></Properties>',
            'xl/workbook.xml' =>
                '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
                . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                . '<sheets><sheet name="' . xlsx_xml_texto($nombre_hoja) . '" sheetId="1" r:id="rId1"/></sheets>'
                . '</workbook>',
            'xl/_rels/workbook.xml.rels' =>
                '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
                . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
                . '</Relationships>',
            'xl/styles.xml'      => xlsx_estilos_xml(),
            'xl/worksheets/sheet1.xml' => $sheet,
        ];

        return xlsx_zip($archivos);
    }
}

// ---------------------------------------------------------------------------
// Lector XLSX: devuelve la matriz de filas (lista de filas, cada una con sus
// valores como texto indexados desde 0). Soporta strings compartidos, strings
// inline, números y fechas las deja como número serial (las convierte el
// caller si hace falta).
// ---------------------------------------------------------------------------
if (!function_exists('xlsx_leer_filas')) {
    function xlsx_leer_filas(string $ruta): array
    {
        $datos = file_get_contents($ruta);
        if ($datos === false || strlen($datos) < 22) {
            throw new Exception('El archivo XLSX está vacío o dañado.');
        }

        // Lectores por offset (unpack con nombre no se usa: los nombres con
        // letras de formato romperían el parseo)
        $u16 = function (string $d, int $o): int { $v = unpack('v', substr($d, $o, 2)); return (int) $v[1]; };
        $u32 = function (string $d, int $o): int { $v = unpack('V', substr($d, $o, 4)); return (int) $v[1]; };

        // Buscar fin del directorio central (PK\x05\x06)
        $pos_eocd = strrpos($datos, "PK\x05\x06");
        if ($pos_eocd === false) {
            throw new Exception('El archivo no tiene estructura ZIP válida.');
        }
        $total_entradas = $u16($datos, $pos_eocd + 10);
        $pos_central    = $u32($datos, $pos_eocd + 16);

        // Recorrer el directorio central buscando las partes que interesan
        $entradas = [];
        $pos      = $pos_central;
        for ($i = 0; $i < $total_entradas; $i++) {
            if ($u32($datos, $pos) !== 0x02014b50) {
                break;
            }
            $method    = $u16($datos, $pos + 10);
            $usize     = $u32($datos, $pos + 24);
            $namelen   = $u16($datos, $pos + 28);
            $extraelen = $u16($datos, $pos + 30);
            $comlen   = $u16($datos, $pos + 32);
            $localoff = $u32($datos, $pos + 42);
            $nombre    = substr($datos, $pos + 46, $namelen);
            $entradas[$nombre] = ['offset' => $localoff, 'tamano' => $usize, 'method' => $method];
            $pos += 46 + $namelen + $extraelen + $comlen;
        }

        $extraer = function (string $nombre) use ($datos, $entradas, $u16, $u32) {
            if (!isset($entradas[$nombre])) {
                return null;
            }
            $e       = $entradas[$nombre];
            $namelen = $u16($datos, $e['offset'] + 26);
            $extra   = $u16($datos, $e['offset'] + 28);
            $ini     = $e['offset'] + 30 + $namelen + $extra;
            $bruto   = substr($datos, $ini, $e['tamano']);
            if ($e['method'] === 8) {
                $inflado = @gzinflate($bruto);
                if ($inflado === false) {
                    throw new Exception('No se pudo descomprimir una parte del archivo XLSX.');
                }
                return $inflado;
            }
            return $bruto;
        };

        // Strings compartidos (Excel los usa por defecto al guardar)
        $compartidos = [];
        $xml_shared  = $extraer('xl/sharedStrings.xml');
        if ($xml_shared !== null && preg_match_all('#<si>(.*?)</si>#s', $xml_shared, $match_si)) {
            foreach ($match_si[1] as $si) {
                $texto = '';
                if (preg_match_all('#<t[^>]*>(.*?)</t>#s', $si, $match_t)) {
                    $texto = implode('', $match_t[1]);
                }
                $compartidos[] = html_entity_decode($texto, ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
        }

        // Hoja de cálculo: preferir sheet1, si no la primera encontrada
        $hoja_nombre = 'xl/worksheets/sheet1.xml';
        if (!isset($entradas[$hoja_nombre])) {
            $hojas = [];
            foreach (array_keys($entradas) as $n) {
                if (preg_match('#^xl/worksheets/[^/]+\.xml$#', $n)) {
                    $hojas[] = $n;
                }
            }
            sort($hojas);
            $hoja_nombre = $hojas[0] ?? null;
        }
        $xml_hoja = $hoja_nombre !== null ? $extraer($hoja_nombre) : null;
        if ($xml_hoja === null) {
            throw new Exception('El archivo no contiene hojas de cálculo.');
        }

        // Filas y celdas
        $filas = [];
        if (!preg_match_all('#<row[^>]*>(.*?)</row>#s', $xml_hoja, $match_rows)) {
            return [];
        }
        foreach ($match_rows[1] as $cuerpo_fila) {
            $fila = [];
            if (preg_match_all('#<c\s([^>]*?)(?:/>|>(.*?)</c>)#s', $cuerpo_fila, $match_celdas, PREG_SET_ORDER)) {
                foreach ($match_celdas as $celda) {
                    $atributos = $celda[1];
                    $contenido = $celda[2] ?? '';
                    if (!preg_match('#r="([A-Z]+)[0-9]+"#', $atributos, $m_ref)) {
                        continue;
                    }
                    $col = 0;
                    foreach (str_split($m_ref[1]) as $letra) {
                        $col = $col * 26 + (ord($letra) - 64);
                    }
                    $col--;

                    $tipo = '';
                    if (preg_match('#t="([^"]+)"#', $atributos, $m_t)) {
                        $tipo = $m_t[1];
                    }

                    if ($tipo === 'inlineStr') {
                        $valor = '';
                        if (preg_match('#<t[^>]*>(.*?)</t>#s', $contenido, $m_t)) {
                            $valor = html_entity_decode($m_t[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
                        }
                    } elseif ($tipo === 's') {
                        $indice = -1;
                        if (preg_match('#<v>(-?\d+)</v>#', $contenido, $m_v)) {
                            $indice = (int) $m_v[1];
                        }
                        $valor = $compartidos[$indice] ?? '';
                    } elseif ($tipo === 'b') {
                        $valor = (strpos($contenido, '<v>1') !== false) ? 'TRUE' : 'FALSE';
                    } else {
                        // Número o cadena calculada (t="str" o sin tipo)
                        $valor = '';
                        if (preg_match('#<v>(.*?)</v>#s', $contenido, $m_v)) {
                            $valor = html_entity_decode($m_v[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
                        }
                    }
                    // Rellenar huecos para mantener la posición de columna
                    for ($k = 0; $k < $col; $k++) {
                        if (!isset($fila[$k])) {
                            $fila[$k] = '';
                        }
                    }
                    $fila[$col] = $valor;
                }
            }
            $filas[] = $fila;
        }

        return $filas;
    }
}
