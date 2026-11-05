<?php
// /sistema_nora/modulos/usuarios/carga_masiva_estudiantes.php
// Matrícula masiva de estudiantes: las profesoras descargan el formato XLSX
// con membrete (formato_estudiantes.php), lo llenan en Excel y lo cargan aquí.
// Cada fila válida crea un usuario estudiante ACTIVO con la clave genérica
// "Nora123456+-" (acceso directo sin registro) y, si el archivo lleva un curso
// (detectado por el código de cohorte en el membrete) o se selecciona uno en el
// formulario, inscribe al estudiante en ese curso (cohorte). También se pueden
// inscribir estudiantes que ya tengan cuenta. Acepta XLSX y CSV.

require_once '../../config/db.php';
require_once '../../includes/header.php';
require_once '../../includes/xlsx.php';

// Acceso: Superadmin, Admin, Control de Estudio y Profesores (matrícula ágil)
if (!tiene_permiso(['control_estudio', 'profesor'], $roles_array)) {
    echo "<div class='alert alert-danger font-weight-bold m-4'><i class='fas fa-ban mr-2'></i>Acceso restringido. Solo Administradores, Control de Estudio y Profesores pueden realizar matrícula masiva.</div>";
    require_once '../../includes/footer.php';
    exit();
}

const CLAVE_GENERICA = 'Nora123456+-';
const MAX_ARCHIVO    = 2097152; // 2 MB
const MAX_FILAS      = 500;

$resultados = null; // lista de resultados por fila procesada
$resumen    = null; // ['creados' => x, 'inscritos' => y, 'omitidos' => z]
$curso_info = null; // ['codigo' => .., 'fuente' => 'archivo'|'formulario', 'id' => int]
$error      = '';

// Cohortes disponibles (para el selector de descarga y como respaldo al cargar)
$cohortes_disponibles = [];
try {
    $cohortes_disponibles = $pdo->query("SELECT c.id_cohorte, c.codigo_cohorte, c.estatus, c.fecha_inicio,
                                                m.nombre_programa, m.requiere_pernocta
                                         FROM public.cohortes c
                                         LEFT JOIN public.mallas_curriculares m ON m.id_malla = c.id_malla
                                         WHERE c.estatus IN ('Planificada', 'En Curso')
                                         ORDER BY c.fecha_inicio DESC, c.id_cohorte DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['archivo'])) {
    $archivo = $_FILES['archivo'];

    try {
        if (($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new Exception('No se pudo recibir el archivo. Verifique que no supere el tamaño permitido e intente de nuevo.');
        }
        if ($archivo['size'] > MAX_ARCHIVO) {
            throw new Exception('El archivo supera el tamaño máximo permitido (2 MB).');
        }

        $extension = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, ['csv', 'txt', 'xlsx'], true)) {
            throw new Exception('El archivo debe estar en formato XLSX (recomendado) o CSV. Descargue el formato desde este módulo y no cambie su estructura.');
        }

        // -------------------------------------------------------------------
        // 1) Convertir el archivo a una matriz unificada de filas/columnas
        // -------------------------------------------------------------------
        $matriz = []; // lista de filas; cada fila = lista de celdas (texto)

        if ($extension === 'xlsx') {
            try {
                $filas_xlsx = xlsx_leer_filas($archivo['tmp_name']);
            } catch (Exception $eX) {
                throw new Exception('No se pudo leer el XLSX: ' . $eX->getMessage());
            }
            if (count($filas_xlsx) < 2) {
                throw new Exception('El archivo debe contener la fila de encabezados y al menos un estudiante.');
            }
            foreach ($filas_xlsx as $fila) {
                $max = $fila === [] ? -1 : max(array_keys($fila));
                $celdas = [];
                for ($c = 0; $c <= $max; $c++) {
                    $celdas[] = trim((string) ($fila[$c] ?? ''));
                }
                $matriz[] = $celdas;
            }
        } else {
            $contenido = file_get_contents($archivo['tmp_name']);
            if ($contenido === false || trim($contenido) === '') {
                throw new Exception('El archivo está vacío.');
            }
            // Quitar BOM UTF-8 si lo tiene
            if (strncmp($contenido, "\xEF\xBB\xBF", 3) === 0) {
                $contenido = substr($contenido, 3);
            }

            $lineas = preg_split('/\r\n|\r|\n/', $contenido);
            if (count($lineas) < 2) {
                throw new Exception('El archivo debe contener la fila de encabezados y al menos un estudiante.');
            }

            // Detectar delimitador: coma (formato oficial) o punto y coma
            $delimitador = ',';
            foreach ($lineas as $linea) {
                $linea_trim = trim($linea);
                if ($linea_trim === '' || stripos($linea_trim, 'sep=') === 0) {
                    continue;
                }
                if (strpos($linea_trim, ';') !== false && strpos($linea_trim, ',') === false) {
                    $delimitador = ';';
                }
                break;
            }

            foreach ($lineas as $linea) {
                if (trim($linea) === '' || stripos(trim($linea), 'sep=') === 0) {
                    continue;
                }
                $celdas = str_getcsv($linea, $delimitador, '"', '\\');
                $matriz[] = array_map(static function ($v): string {
                    return trim((string) $v);
                }, $celdas);
            }
        }

        if (count($matriz) < 2) {
            throw new Exception('El archivo debe contener la fila de encabezados y al menos un estudiante.');
        }

        // -------------------------------------------------------------------
        // 2) Detectar el curso (cohorte) por el código impreso en las celdas
        // -------------------------------------------------------------------
        $codigos_mapa = []; // codigo_en_minusculas => id_cohorte
        try {
            $tods = $pdo->query("SELECT id_cohorte, codigo_cohorte FROM public.cohortes")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($tods as $c) {
                $codigos_mapa[strtolower(trim($c['codigo_cohorte']))] = (int) $c['id_cohorte'];
            }
        } catch (Throwable $e) {}

        $id_curso = null;
        $codigo_detectado = '';
        $fuente_curso = '';

        foreach ($matriz as $fila) {
            foreach ($fila as $celda) {
                $t = trim((string) $celda);
                if ($t === '') {
                    continue;
                }
                if (isset($codigos_mapa[strtolower($t)])) {
                    $id_curso = $codigos_mapa[strtolower($t)];
                    $codigo_detectado = $t;
                    break 2;
                }
                // Tokens tipo "COH-2026-NORA-01" dentro de textos del membrete
                if (preg_match_all('/\b[A-Z][A-Z0-9]*-[A-Z0-9]+(?:-[A-Z0-9]+)*\b/i', $t, $mt)) {
                    foreach ($mt[0] as $token) {
                        if (isset($codigos_mapa[strtolower($token)])) {
                            $id_curso = $codigos_mapa[strtolower($token)];
                            $codigo_detectado = $token;
                            break 3;
                        }
                    }
                }
            }
        }

        if ($id_curso !== null) {
            $fuente_curso = 'archivo';
        } elseif (!empty($_POST['cohorte'])) {
            $id_sel = intval($_POST['cohorte']);
            try {
                $stc = $pdo->prepare("SELECT codigo_cohorte FROM public.cohortes WHERE id_cohorte = :id");
                $stc->execute([':id' => $id_sel]);
                $cod_sel = $stc->fetchColumn();
                if ($cod_sel) {
                    $id_curso = $id_sel;
                    $codigo_detectado = (string) $cod_sel;
                    $fuente_curso = 'formulario';
                }
            } catch (Throwable $e) {}
        }

        // -------------------------------------------------------------------
        // 3) Localizar la fila de encabezados y mapear columnas
        // -------------------------------------------------------------------
        $norm = static function (string $txt): string {
            // Primero resolver acentos/mayúsculas acentuadas (strtolower no toca
            // los bytes UTF-8 y el preg_replace los eliminaría), luego limpiar.
            $txt = strtr($txt, ['Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'Ñ' => 'n', 'Ü' => 'u',
                                'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u']);
            $txt = strtolower(trim($txt));
            return preg_replace('/[^a-z0-9_ ]/', '', $txt);
        };

        $indice_encabezado = -1;
        $columnas = []; // campo => índice de celda
        foreach ($matriz as $i => $fila) {
            $claves_fila = array_map($norm, $fila);
            $joined = implode(' | ', $claves_fila);
            if (strpos($joined, 'cedula') !== false && (strpos($joined, 'nombre') !== false || strpos($joined, 'apellido') !== false)) {
                $indice_encabezado = $i;
                foreach ($claves_fila as $pos => $clave) {
                    if ($clave === '' || isset($columnas[$clave])) {
                        continue;
                    }
                    $columnas[$clave] = $pos;
                }
                break;
            }
        }

        if ($indice_encabezado < 0) {
            throw new Exception('No se encontró la fila de encabezados. Use el formato descargado desde este módulo sin modificar sus encabezados.');
        }

        // Resolver cada campo por coincidencia parcial (tolera variaciones de encabezado)
        $campo = static function (array $columnas, string ...$alias) {
            foreach ($columnas as $clave => $pos) {
                foreach ($alias as $a) {
                    if (strpos($clave, $a) !== false) {
                        return $pos;
                    }
                }
            }
            return null;
        };
        $pos_cedula  = $campo($columnas, 'cedula');
        $pos_nombre  = $campo($columnas, 'nombre', 'primer');
        $pos_apellido= $campo($columnas, 'apellido', 'apellido');
        $pos_correo  = $campo($columnas, 'correo', 'email');
        $pos_telefono= $campo($columnas, 'telefono');
        $pos_sexo    = $campo($columnas, 'sexo', 'genero');
        $pos_fnac    = $campo($columnas, 'nacimiento', 'fnacimiento');

        if ($pos_cedula === null || $pos_nombre === null || $pos_apellido === null) {
            throw new Exception('La fila de encabezados no contiene las columnas obligatorias (cedula, nombre, apellido). Use el formato descargado desde este módulo sin modificar sus encabezados.');
        }

        $leer = static function (array $fila, ?int $pos): string {
            return $pos !== null && isset($fila[$pos]) ? trim((string) $fila[$pos]) : '';
        };

        // Información del curso aplicado (para el banner de resultados)
        if ($id_curso !== null) {
            try {
                $stq = $pdo->prepare("SELECT c.codigo_cohorte, c.estatus, c.hora_inicio, c.hora_fin, m.nombre_programa
                                      FROM public.cohortes c
                                      LEFT JOIN public.mallas_curriculares m ON m.id_malla = c.id_malla
                                      WHERE c.id_cohorte = :id");
                $stq->execute([':id' => $id_curso]);
                $curso_info = $stq->fetch(PDO::FETCH_ASSOC) ?: null;
                if ($curso_info !== false && $curso_info !== null) {
                    $curso_info['fuente'] = $fuente_curso;
                    $curso_info['id'] = $id_curso;
                }
            } catch (Throwable $e) {
                $curso_info = null;
            }
        }

        // -------------------------------------------------------------------
        // 4) Procesar filas de datos
        // -------------------------------------------------------------------
        $resultados = [];
        $creados    = 0;
        $inscritos  = 0;
        $omitidos   = 0;
        $vistos     = []; // cédulas ya procesadas en este archivo
        $procesadas = 0;

        foreach ($matriz as $i => $fila) {
            if ($i <= $indice_encabezado) {
                continue; // saltar membrete, encabezados y fila de ejemplo arriba
            }
            if (count(array_filter($fila, static fn ($v) => $v !== '')) < 2) {
                continue; // líneas de indicaciones / separadores / vacías
            }

            $cedula_raw = $leer($fila, $pos_cedula);
            // Saltar filas de ejemplo
            $es_ejemplo = false;
            foreach ($fila as $celda) {
                if (stripos((string) $celda, 'EJEMPLO') !== false) {
                    $es_ejemplo = true;
                    break;
                }
            }
            if ($es_ejemplo) {
                continue;
            }

            $cedula = preg_replace('/[^0-9]/', '', $cedula_raw);
            $nombre = trim(preg_replace('/\s+/', ' ', $leer($fila, $pos_nombre)));
            $apellido = trim(preg_replace('/\s+/', ' ', $leer($fila, $pos_apellido)));
            $correo = $leer($fila, $pos_correo);
            $telefono = $leer($fila, $pos_telefono);
            $sexo_raw = $leer($fila, $pos_sexo);
            $fnac_raw = $leer($fila, $pos_fnac);

            $fila_txt = $i + 1; // número de fila visible en Excel / línea del CSV

            // Fila totalmente vacía de datos o texto largo que no es un registro
            if ($cedula === '' && $nombre === '' && $apellido === '') {
                continue;
            }
            if ($cedula_raw !== '' && strlen($cedula_raw) > 14) {
                continue; // línea de indicaciones u otra fila que no es un estudiante
            }

            $procesadas++;
            if ($procesadas > MAX_FILAS) {
                $resultados[] = ['fila' => $fila_txt, 'cedula' => '', 'estudiante' => '', 'estado' => 'omitido',
                    'detalle' => 'Se alcanzó el límite de ' . MAX_FILAS . ' filas por archivo. Cargue el resto en un archivo adicional.'];
                $omitidos++;
                break;
            }

            $motivo = '';
            if ($cedula === '' || strlen($cedula) < 6 || strlen($cedula) > 8) {
                $motivo = 'Cédula inválida (debe tener entre 6 y 8 dígitos).';
            } elseif (isset($vistos[$cedula])) {
                $motivo = 'La cédula V-' . $cedula . ' aparece más de una vez en el archivo.';
            } elseif ($nombre === '' || mb_strlen($nombre) < 2) {
                $motivo = 'Falta el nombre del estudiante.';
            } elseif ($apellido === '' || mb_strlen($apellido) < 2) {
                $motivo = 'Falta el apellido del estudiante.';
            }

            if ($motivo === '' && $correo !== '' && !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
                $motivo = 'El correo "' . $correo . '" no es válido.';
            }

            // Normalizar sexo
            $sexo = '';
            $sexo_norm = strtoupper($sexo_raw);
            if ($sexo_norm === 'F' || $sexo_norm === 'FEMENINO' || $sexo_norm === 'MUJER') {
                $sexo = 'Femenino';
            } elseif ($sexo_norm === 'M' || $sexo_norm === 'MASCULINO' || $sexo_norm === 'VARON' || $sexo_norm === 'VARÓN') {
                $sexo = 'Masculino';
            } elseif ($sexo_raw !== '') {
                $motivo = $motivo ?: 'El sexo debe ser "Femenino" o "Masculino".';
            }

            // Normalizar fecha de nacimiento (AAAA-MM-DD, DD/MM/AAAA o serial de Excel)
            $fnac = null;
            $edad = null;
            if ($motivo === '' && $fnac_raw !== '') {
                $fecha = null;
                if (preg_match('/^\d{4,5}(\.0+)?$/', $fnac_raw)) {
                    // Serial de fecha de Excel (días desde 1899-12-30)
                    $serial = (int) floatval($fnac_raw);
                    if ($serial > 1000 && $serial < 80000) {
                        $fecha = new DateTime('1899-12-30');
                        $fecha->modify('+' . $serial . ' days');
                    }
                } else {
                    $fecha = DateTime::createFromFormat('Y-m-d', $fnac_raw)
                        ?: DateTime::createFromFormat('d/m/Y', $fnac_raw)
                        ?: DateTime::createFromFormat('d-m-Y', $fnac_raw);
                }
                if (!$fecha || $fecha->format('Y-m-d') === '0000-00-00' || $fecha->getTimestamp() > time()) {
                    $motivo = 'La fecha de nacimiento "' . $fnac_raw . '" no es válida (use AAAA-MM-DD o DD/MM/AAAA).';
                } else {
                    $fnac = $fecha->format('Y-m-d');
                    $edad = (new DateTime())->diff($fecha)->y;
                }
            }

            if ($motivo !== '') {
                $omitidos++;
                $resultados[] = ['fila' => $fila_txt, 'cedula' => $cedula, 'estudiante' => trim($nombre . ' ' . $apellido),
                    'estado' => 'omitido', 'detalle' => $motivo];
                if ($cedula !== '') {
                    $vistos[$cedula] = true;
                }
                continue;
            }

            // -------------------------------------------------------------------
            // Insertar inscripción en el curso (cohorte) — helper reutilizable
            // -------------------------------------------------------------------
            $inscribir = static function (int $id_estudiante) use ($pdo, $id_curso): array {
                if ($id_curso === null) {
                    return ['ok' => false, 'ya' => false];
                }
                try {
                    $stq = $pdo->prepare("SELECT 1 FROM public.inscripciones WHERE id_curso = :c AND id_estudiante = :e");
                    $stq->execute([':c' => $id_curso, ':e' => $id_estudiante]);
                    if ($stq->fetchColumn()) {
                        return ['ok' => false, 'ya' => true];
                    }
                    $pdo->prepare("INSERT INTO public.inscripciones (id_curso, id_estudiante)
                                   VALUES (:c, :e)
                                   ON CONFLICT (id_curso, id_estudiante) DO NOTHING")
                        ->execute([':c' => $id_curso, ':e' => $id_estudiante]);
                    return ['ok' => true, 'ya' => false];
                } catch (Throwable $e) {
                    return ['ok' => false, 'ya' => false, 'error' => $e->getMessage()];
                }
            };

            // -------------------------------------------------------------------
            // Buscar cuenta existente por cédula
            // -------------------------------------------------------------------
            $id_existente = null;
            $tipo_existente = '';
            try {
                $chk = $pdo->prepare("SELECT id_usuario, tipo_usuario FROM public.usuarios WHERE cedula = :c LIMIT 1");
                $chk->execute([':c' => $cedula]);
                $existente = $chk->fetch(PDO::FETCH_ASSOC);
                if ($existente) {
                    $id_existente = (int) $existente['id_usuario'];
                    $tipo_existente = (string) $existente['tipo_usuario'];
                }
            } catch (Throwable $e) {
                $omitidos++;
                $resultados[] = ['fila' => $fila_txt, 'cedula' => $cedula, 'estudiante' => trim($nombre . ' ' . $apellido),
                    'estado' => 'omitido', 'detalle' => 'Error al consultar la base de datos.'];
                $vistos[$cedula] = true;
                continue;
            }

            $vistos[$cedula] = true;
            $etiqueta = trim($nombre . ' ' . $apellido);

            // --- Caso A: la cuenta ya existe --------------------------------
            if ($id_existente !== null) {
                if ($id_curso === null) {
                    $omitidos++;
                    $resultados[] = ['fila' => $fila_txt, 'cedula' => $cedula, 'estudiante' => $etiqueta,
                        'estado' => 'omitido',
                        'detalle' => 'La cédula V-' . $cedula . ' ya tiene una cuenta registrada en el sistema.'];
                    continue;
                }
                if ($tipo_existente !== 'estudiante') {
                    $omitidos++;
                    $resultados[] = ['fila' => $fila_txt, 'cedula' => $cedula, 'estudiante' => $etiqueta,
                        'estado' => 'omitido',
                        'detalle' => 'La cédula V-' . $cedula . ' pertenece a una cuenta que no es de estudiante; no se puede inscribir en el curso.'];
                    continue;
                }
                $res_insc = $inscribir($id_existente);
                if (!empty($res_insc['error'])) {
                    $omitidos++;
                    $resultados[] = ['fila' => $fila_txt, 'cedula' => $cedula, 'estudiante' => $etiqueta,
                        'estado' => 'omitido', 'detalle' => 'Error al inscribir en el curso: ' . $res_insc['error']];
                    continue;
                }
                if ($res_insc['ok']) {
                    $inscritos++;
                    $resultados[] = ['fila' => $fila_txt, 'cedula' => $cedula, 'estudiante' => $etiqueta,
                        'estado' => 'inscrito',
                        'detalle' => 'Cuenta existente. Inscripción registrada en el curso ' . $codigo_detectado . '.'];
                } else {
                    $omitidos++;
                    $resultados[] = ['fila' => $fila_txt, 'cedula' => $cedula, 'estudiante' => $etiqueta,
                        'estado' => 'omitido',
                        'detalle' => 'La cuenta V-' . $cedula . ' ya estaba inscrita en el curso ' . $codigo_detectado . '.'];
                }
                continue;
            }

            // --- Caso B: crear la cuenta nueva ------------------------------
            try {
                if ($correo === '') {
                    $correo = 'estudiante_' . $cedula . '@escuelanora.gob.ve';
                } else {
                    $chk_correo = $pdo->prepare("SELECT id_usuario FROM public.usuarios WHERE correo = :e OR email = :e2 LIMIT 1");
                    $chk_correo->execute([':e' => $correo, ':e2' => $correo]);
                    if ($chk_correo->fetch()) {
                        $omitidos++;
                        $resultados[] = ['fila' => $fila_txt, 'cedula' => $cedula, 'estudiante' => $etiqueta,
                            'estado' => 'omitido', 'detalle' => 'El correo "' . $correo . '" ya está en uso por otra cuenta.'];
                        continue;
                    }
                }

                $pass_hash = password_hash(CLAVE_GENERICA, PASSWORD_BCRYPT);

                $stmt = $pdo->prepare("INSERT INTO public.usuarios
                        (cedula, nombre, apellido, fecha_nacimiento, sexo, edad, estado_civil, cargo_funcion,
                         tipo_usuario, roles_asignados, estatus_cuenta, password, correo, telefono)
                        VALUES
                        (:c, :n, :a, :fn, :sx, :ed, '', 'Estudiante', 'estudiante', '{\"estudiante\"}', 'Activo', :p, :cor, :tel)");
                $stmt->execute([
                    ':c'   => $cedula,
                    ':n'   => $nombre,
                    ':a'   => $apellido,
                    ':fn'  => $fnac,
                    ':sx'  => $sexo,
                    ':ed'  => $edad,
                    ':p'   => $pass_hash,
                    ':cor' => $correo,
                    ':tel' => $telefono !== '' ? substr(preg_replace('/[^0-9+]/', '', $telefono), 0, 20) : null,
                ]);

                $id_nuevo = (int) $pdo->lastInsertId();
                $creados++;

                $detalle = 'Cuenta activa. Ingreso con cédula V-' . $cedula . ' y la clave genérica.';
                $estado  = 'creado';

                if ($id_curso !== null) {
                    $res_insc = $inscribir($id_nuevo);
                    if (!empty($res_insc['error'])) {
                        $detalle = 'Cuenta creada, pero NO se pudo inscribir en el curso: ' . $res_insc['error'];
                    } elseif ($res_insc['ok'] || $res_insc['ya']) {
                        $detalle = 'Cuenta activa con la clave genérica e inscrita en el curso ' . $codigo_detectado . '.';
                    }
                }

                $resultados[] = ['fila' => $fila_txt, 'cedula' => $cedula, 'estudiante' => $etiqueta,
                    'estado' => $estado, 'detalle' => $detalle];
            } catch (Throwable $e) {
                $omitidos++;
                $resultados[] = ['fila' => $fila_txt, 'cedula' => $cedula, 'estudiante' => $etiqueta,
                    'estado' => 'omitido', 'detalle' => 'Error al guardar: ' . $e->getMessage()];
            }
        }

        if ($procesadas === 0) {
            $resultados = null;
            throw new Exception('El archivo no contiene filas de estudiantes para procesar (las filas de ejemplo se ignoran automáticamente).');
        }

        $resumen = ['creados' => $creados, 'inscritos' => $inscritos, 'omitidos' => $omitidos];

        // Auditoría en bitácora (no crítico)
        try {
            $accion_bit = 'CARGA MASIVA ESTUDIANTES'
                . ($id_curso !== null ? ' curso:' . $codigo_detectado . ' fuente:' . $fuente_curso : ' sin-curso')
                . ' - creados:' . $creados . ' inscritos:' . $inscritos . ' omitidos:' . $omitidos;
            $pdo->prepare("INSERT INTO public.bitacora (id_usuario, modulo, accion, ip_maquina)
                           VALUES (:u, 'CONTROL DE ESTUDIO', :acc, :ip)")
                ->execute([':u' => $_SESSION['id_usuario'], ':acc' => $accion_bit, ':ip' => $_SERVER['REMOTE_ADDR'] ?? null]);
        } catch (Throwable $e) {}
    } catch (Exception $e) {
        $error = $e->getMessage();
        $resultados = null;
        $resumen = null;
        $curso_info = null;
    }
}
?>

<div class="row justify-content-center my-4">
    <div class="col-xl-10">

        <h1 class="h3 mb-3 font-weight-bold" style="color: #521f29;">
            <i class="fas fa-file-excel mr-2" style="color: #ff8a00;"></i>Matrícula Masiva de Estudiantes
        </h1>

        <!-- PASOS -->
        <div class="card shadow border-0 mb-4" style="border-radius: 12px; overflow: hidden;">
            <div class="card-header text-white font-weight-bold py-3" style="background-color: #521f29;">
                <i class="fas fa-bolt mr-2" style="color: #ff8a00;"></i>Matrícula ágil para cursos cortos y eventos de pernocta — 3 pasos
            </div>
            <div class="card-body bg-white p-4">
                <div class="row text-center">
                    <div class="col-md-4 mb-3">
                        <div class="p-3 border rounded bg-light h-100">
                            <i class="fas fa-download fa-2x mb-2" style="color: #ff8a00;"></i>
                            <h6 class="font-weight-bold" style="color: #521f29;">1. Descargue el formato</h6>
                            <p class="small text-muted mb-2">Excel (.xlsx) con membrete institucional y el curso seleccionado. Opcional para documentos oficiales.</p>
                            <div class="form-group text-left">
                                <label class="small font-weight-bold mb-1" for="cohorte_descarga">Curso / cohorte del formato</label>
                                <select id="cohorte_descarga" class="form-control form-control-sm">
                                    <option value="">— Genérico (sin curso) —</option>
                                    <?php foreach ($cohortes_disponibles as $cd): ?>
                                        <option value="<?= (int) $cd['id_cohorte'] ?>">
                                            <?= htmlspecialchars($cd['codigo_cohorte'] . ' — ' . ($cd['nombre_programa'] ?? 'Programa') . ($cd['requiere_pernocta'] ? ' [PERNOCTA]' : '')) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <a href="formato_estudiantes.php" id="btn_descarga_formato" class="btn btn-sm font-weight-bold text-white" style="background-color: #ff8a00;">
                                <i class="fas fa-file-excel mr-1"></i> Descargar formato XLSX
                            </a>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="p-3 border rounded bg-light h-100">
                            <i class="fas fa-edit fa-2x mb-2" style="color: #ff8a00;"></i>
                            <h6 class="font-weight-bold" style="color: #521f29;">2. Llene los datos</h6>
                            <p class="small text-muted mb-2">Una fila por estudiante. Elimine la fila de ejemplo. No cambie los encabezados de las columnas.</p>
                            <span class="badge badge-light border font-weight-bold text-dark p-2">Columnas: cédula, nombre, apellido, correo, teléfono, sexo, fecha de nacimiento</span>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="p-3 border rounded bg-light h-100">
                            <i class="fas fa-upload fa-2x mb-2" style="color: #ff8a00;"></i>
                            <h6 class="font-weight-bold" style="color: #521f29;">3. Cargue el archivo</h6>
                            <p class="small text-muted mb-2">Quedan ACTIVOS al instante y, si el formato lleva curso, quedan <strong>inscritos en ese curso</strong>.</p>
                            <span class="badge badge-success font-weight-bold p-2"><i class="fas fa-key mr-1"></i>Clave de ingreso: <?= CLAVE_GENERICA ?></span>
                        </div>
                    </div>
                </div>

                <div class="alert alert-warning font-weight-bold small mb-0 mt-2">
                    <i class="fas fa-exclamation-triangle mr-1"></i>
                    Cada estudiante creado ingresa al sistema con su <strong>número de cédula</strong> y la clave genérica
                    <strong><?= CLAVE_GENERICA ?></strong>. Recomiende a los estudiantes cambiarla desde "Mi Perfil" después del primer ingreso.
                    Los estudiantes que <strong>ya tengan cuenta</strong> e inscripción pendiente también se inscriben al curso con este mismo archivo.
                </div>
            </div>
        </div>

        <!-- FORMULARIO DE CARGA -->
        <div class="card shadow border-0 mb-4" style="border-radius: 12px; overflow: hidden;">
            <div class="card-header text-white font-weight-bold py-3" style="background-color: #ff8a00;">
                <i class="fas fa-cloud-upload-alt mr-2" style="color: #521f29;"></i> Cargar archivo de matrícula (XLSX o CSV, máximo 2 MB / 500 filas)
            </div>
            <div class="card-body bg-white p-4">

                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger font-weight-bold shadow-sm">
                        <i class="fas fa-exclamation-triangle mr-2"></i><?= htmlspecialchars($error) ?>
                    </div>
                <?php endif; ?>

                <?php if ($resumen !== null): ?>
                    <?php if ($curso_info !== null): ?>
                        <div class="alert alert-info font-weight-bold shadow-sm">
                            <i class="fas fa-graduation-cap mr-2" style="color: #521f29;"></i>
                            Curso aplicado: <strong><?= htmlspecialchars($curso_info['codigo_cohorte']) ?></strong>
                            <?php if (!empty($curso_info['nombre_programa'])): ?>
                                — <?= htmlspecialchars($curso_info['nombre_programa']) ?>
                            <?php endif; ?>
                            <?php if (!empty($curso_info['estatus'])): ?>
                                <span class="badge badge-dark ml-1"><?= htmlspecialchars($curso_info['estatus']) ?></span>
                            <?php endif; ?>
                            <span class="badge badge-light border ml-1">
                                <?= $curso_info['fuente'] === 'archivo' ? 'detectado en el membrete del archivo' : 'seleccionado en el formulario' ?>
                            </span>
                        </div>
                    <?php endif; ?>
                    <?php if ($resumen['creados'] > 0): ?>
                        <div class="alert alert-success font-weight-bold shadow-sm">
                            <i class="fas fa-check-circle mr-2"></i>
                            Se crearon <strong><?= $resumen['creados'] ?> cuenta(s) de estudiante(s)</strong> con éxito, activas de inmediato
                            con la clave genérica <strong><?= CLAVE_GENERICA ?></strong>.
                        </div>
                    <?php endif; ?>
                    <?php if ($resumen['inscritos'] > 0): ?>
                        <div class="alert alert-success font-weight-bold shadow-sm">
                            <i class="fas fa-user-graduate mr-2"></i>
                            <strong><?= $resumen['inscritos'] ?> estudiante(s) con cuenta existente</strong> fueron inscritos en el curso seleccionado.
                        </div>
                    <?php endif; ?>
                    <?php if ($resumen['omitidos'] > 0): ?>
                        <div class="alert alert-warning font-weight-bold shadow-sm mb-0">
                            <i class="fas fa-exclamation-circle mr-2"></i>
                            <strong><?= $resumen['omitidos'] ?> fila(s)</strong> fueron omitidas. Revise el detalle en la tabla inferior, corrija el archivo y vuelva a cargarlo.
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <form method="POST" enctype="multipart/form-data" class="mt-3">
                <?= csrf_field() ?>
                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-dark">Archivo con los estudiantes a matricular (XLSX recomendado) *</label>
                        <div class="custom-file">
                            <input type="file" name="archivo" class="custom-file-input" id="archivo" accept=".xlsx,.csv,.txt" required>
                            <label class="custom-file-label" for="archivo" id="archivo_label">Seleccione el archivo descargado...</label>
                        </div>
                        <small class="text-muted">Si el formato no lleva curso en el membrete (CSV genérico), elija aquí el curso correspondiente:</small>
                    </div>
                    <div class="form-group mb-3">
                        <label class="font-weight-bold text-dark">Curso / cohorte (respaldo si el archivo no trae uno)</label>
                        <select name="cohorte" id="cohorte_carga" class="form-control">
                            <option value="">— Ninguno (solo crear las cuentas) —</option>
                            <?php foreach ($cohortes_disponibles as $cd): ?>
                                <option value="<?= (int) $cd['id_cohorte'] ?>">
                                    <?= htmlspecialchars($cd['codigo_cohorte'] . ' — ' . ($cd['nombre_programa'] ?? 'Programa') . ($cd['requiere_pernocta'] ? ' [PERNOCTA]' : '')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">Si el archivo trae el código del curso en el membrete, ese tiene prioridad sobre esta selección.</small>
                    </div>
                    <button type="submit" class="btn font-weight-bold text-white py-2 px-4 shadow" style="background-color: #521f29; border-radius: 10px;">
                        <i class="fas fa-users mr-2" style="color: #ff8a00;"></i> PROCESAR MATRÍCULA MASIVA
                    </button>
                </form>

            </div>
        </div>

        <!-- RESULTADOS -->
        <?php if (!empty($resultados)): ?>
            <div class="card shadow border-0 mb-4" style="border-radius: 12px; overflow: hidden;">
                <div class="card-header text-white font-weight-bold py-3" style="background-color: #521f29;">
                    <i class="fas fa-list-ol mr-2" style="color: #ff8a00;"></i> Resultado del procesamiento (<?= count($resultados) ?> filas)
                </div>
                <div class="card-body bg-white p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-striped table-bordered mb-0 small align-middle">
                            <thead style="background-color: #ff8a00; color: #521f29;" class="font-weight-bold">
                                <tr>
                                    <th style="width: 7%;">Fila</th>
                                    <th style="width: 12%;">Cédula</th>
                                    <th style="width: 28%;">Estudiante</th>
                                    <th style="width: 12%;">Estado</th>
                                    <th>Detalle</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($resultados as $r): ?>
                                    <tr>
                                        <td class="text-center"><?= (int) $r['fila'] ?></td>
                                        <td><?= $r['cedula'] !== '' ? 'V-' . htmlspecialchars($r['cedula']) : '—' ?></td>
                                        <td><?= htmlspecialchars($r['estudiante'] !== '' ? $r['estudiante'] : '—') ?></td>
                                        <td class="text-center">
                                            <?php if ($r['estado'] === 'creado'): ?>
                                                <span class="badge badge-success font-weight-bold"><i class="fas fa-check mr-1"></i>Creado</span>
                                            <?php elseif ($r['estado'] === 'inscrito'): ?>
                                                <span class="badge badge-info font-weight-bold"><i class="fas fa-user-graduate mr-1"></i>Inscrito</span>
                                            <?php else: ?>
                                                <span class="badge badge-danger font-weight-bold"><i class="fas fa-times mr-1"></i>Omitido</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($r['detalle']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="text-center mb-3">
            <a href="formato_estudiantes.php" class="btn btn-outline-secondary font-weight-bold mr-1">
                <i class="fas fa-download mr-1"></i> Descargar formato
            </a>
            <?php if (tiene_permiso(['superadmin', 'admin'], $roles_array)): ?>
                <a href="index.php" class="btn btn-outline-secondary font-weight-bold mr-1">
                    <i class="fas fa-users mr-1"></i> Gestión de Usuarios
                </a>
            <?php endif; ?>
            <a href="aprobar_estudiantes.php" class="btn btn-outline-secondary font-weight-bold">
                <i class="fas fa-user-check mr-1"></i> Aprobación de Estudiantes
            </a>
        </div>

    </div>
</div>

<script>
document.addEventListener('change', function (e) {
    if (e.target && e.target.id === 'archivo') {
        var etiqueta = document.getElementById('archivo_label');
        etiqueta.textContent = e.target.files.length ? e.target.files[0].name : 'Seleccione el archivo descargado...';
    }
    // Mantener sincronizado el curso del enlace de descarga
    if (e.target && e.target.id === 'cohorte_descarga') {
        var enlace = document.getElementById('btn_descarga_formato');
        var v = e.target.value;
        enlace.href = v ? ('formato_estudiantes.php?cohorte=' + encodeURIComponent(v)) : 'formato_estudiantes.php';
        var selCarga = document.getElementById('cohorte_carga');
        if (selCarga) { selCarga.value = v; }
    }
});
</script>

<?php require_once '../../includes/footer.php'; ?>
