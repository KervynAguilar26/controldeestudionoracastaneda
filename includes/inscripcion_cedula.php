<?php
// /sistema_nora/includes/inscripcion_cedula.php
// Inscripción de estudiantes usando SOLAMENTE el número de cédula.
// La cédula es la llave: si no existe cuenta se crea (con Padrón Nacional si
// está disponible; si no, con datos "POR COMPLETAR"). El proceso nunca se
// detiene por falta de perfil, por lo que el profesor puede cargar notas de
// inmediato. Cuando el estudiante complete su perfil, verá toda su información.

require_once __DIR__ . '/pernocta.php';
require_once __DIR__ . '/xlsx.php';
require_once __DIR__ . '/../modulos/recepcion/padron.php';

if (!function_exists('inscripcion_cedula_limpia')) {
    function inscripcion_cedula_limpia($valor): string
    {
        return function_exists('pernocta_cedula_limpia')
            ? pernocta_cedula_limpia($valor)
            : preg_replace('/\D+/', '', trim((string) $valor));
    }
}

if (!function_exists('inscripcion_cedula_valida')) {
    function inscripcion_cedula_valida(string $cedula): bool
    {
        return (bool) preg_match('/^[0-9]{6,9}$/', $cedula);
    }
}

if (!function_exists('inscripcion_cedula_resolver')) {
    /**
     * Resuelve (o crea) un usuario a partir de una cédula.
     *
     * @return array{ok:bool,id_usuario:?int,nombre:string,cedula:string,creado:bool,fuente:string,error:string}
     */
    function inscripcion_cedula_resolver(PDO $pdo, $pdo_padron, string $cedula): array
    {
        $cedula = inscripcion_cedula_limpia($cedula);
        $res = [
            'ok' => false, 'id_usuario' => null, 'nombre' => '', 'cedula' => $cedula,
            'creado' => false, 'fuente' => 'SISTEMA', 'error' => '',
        ];

        if (!inscripcion_cedula_valida($cedula)) {
            $res['error'] = 'Cédula inválida (debe tener entre 6 y 9 dígitos).';
            return $res;
        }

        $usuario = pernocta_usuario_por_cedula($pdo, $cedula);
        if ($usuario) {
            $res['ok'] = true;
            $res['id_usuario'] = (int) $usuario['id_usuario'];
            $res['nombre'] = trim(((string) $usuario['nombre']) . ' ' . ((string) ($usuario['apellido'] ?? '')));
            return $res;
        }

        // Intentar completar la identidad desde el Padrón Nacional. Si falla,
        // se crea igualmente la cuenta para no detener el proceso.
        $padron = null;
        if ($pdo_padron !== null && function_exists('recepcion_buscar_padron')) {
            try {
                $padron = recepcion_buscar_padron($pdo_padron, $cedula);
            } catch (Throwable $e) {
                $padron = null;
            }
        }

        $nombre   = trim((string) ($padron['nombre'] ?? ''));
        $apellido = trim((string) ($padron['apellido'] ?? ''));
        $fuente   = 'PADRON_NACIONAL';
        if (trim($nombre . ' ' . $apellido) === '') {
            $nombre   = 'POR COMPLETAR';
            $apellido = 'POR COMPLETAR';
            $fuente   = 'SOLO_CEDULA';
        }

        $sexo = null;
        $genero = strtoupper((string) ($padron['genero'] ?? ''));
        if (strpos($genero, 'FEMEN') !== false || $genero === 'F') { $sexo = 'Femenino'; }
        elseif (strpos($genero, 'MASCUL') !== false || $genero === 'M') { $sexo = 'Masculino'; }

        $fnac = $padron['fecha_nacimiento'] ?? null;
        $edad = null;
        if ($fnac) {
            try {
                $edad = (new DateTime($fnac))->diff(new DateTime())->y;
            } catch (Throwable $e) { $edad = null; }
        }

        try {
            $st = $pdo->prepare("INSERT INTO public.usuarios
                    (cedula, nombre, apellido, email, correo, password, telefono, tipo_usuario,
                     estatus_cuenta, roles_asignados, cargo_funcion, fecha_nacimiento, sexo, edad,
                     estado_civil, etnia, tipo_discapacidad)
                VALUES
                    (:ced, :nom, :ape, NULL, NULL, :pass, NULL, 'estudiante',
                     'Activo', ARRAY['estudiante']::text[], 'Estudiante', :fnac, :sexo, :edad,
                     NULL, 'Ninguna', 'Ninguna')
                ON CONFLICT (cedula) DO NOTHING
                RETURNING id_usuario");
            $st->execute([
                ':ced'  => $cedula,
                ':nom'  => mb_substr($nombre, 0, 100),
                ':ape'  => mb_substr($apellido, 0, 100),
                ':pass' => password_hash('Nora123456+-', PASSWORD_BCRYPT),
                ':fnac' => $fnac,
                ':sexo' => $sexo,
                ':edad' => $edad,
            ]);
            $idNuevo = $st->fetchColumn();
            if (!$idNuevo) {
                $usuario = pernocta_usuario_por_cedula($pdo, $cedula);
                if (!$usuario) {
                    $res['error'] = 'No se pudo crear la cuenta de usuario.';
                    return $res;
                }
                $idNuevo = $usuario['id_usuario'];
                $res['nombre'] = trim(((string) $usuario['nombre']) . ' ' . ((string) ($usuario['apellido'] ?? '')));
                $res['fuente'] = 'SISTEMA';
            } else {
                $res['creado'] = true;
                $res['nombre'] = trim($nombre . ' ' . $apellido);
                $res['fuente'] = $fuente;
            }
            $res['ok'] = true;
            $res['id_usuario'] = (int) $idNuevo;
            return $res;
        } catch (Throwable $e) {
            $res['error'] = 'No se pudo crear la cuenta: ' . $e->getMessage();
            return $res;
        }
    }
}

if (!function_exists('inscripcion_cedula_inscribir')) {
    /**
     * Inscribe al usuario en una cohorte/sección, respetando cupo.
     *
     * @return array{ok:bool,ya:bool,error:string,codigo:string}
     */
    function inscripcion_cedula_inscribir(PDO $pdo, int $id_cohorte, int $id_usuario): array
    {
        $res = ['ok' => false, 'ya' => false, 'error' => '', 'codigo' => ''];
        $propia = !$pdo->inTransaction();
        try {
            if ($propia) { $pdo->beginTransaction(); }

            $st = $pdo->prepare("SELECT c.cupos_maximos, c.codigo_cohorte,
                                        (SELECT COUNT(*) FROM public.inscripciones i
                                          WHERE i.id_curso = c.id_cohorte) AS inscritos
                                   FROM public.cohortes c
                                  WHERE c.id_cohorte = :id
                                    AND c.estatus IN ('Planificada', 'En Curso')
                                  FOR UPDATE OF c");
            $st->execute([':id' => $id_cohorte]);
            $cohorte = $st->fetch(PDO::FETCH_ASSOC);
            if (!$cohorte) {
                throw new Exception('La sección indicada no existe o no admite inscripciones.');
            }
            $res['codigo'] = (string) $cohorte['codigo_cohorte'];

            $cupo = (int) $cohorte['cupos_maximos'];
            if ($cupo > 0 && (int) $cohorte['inscritos'] >= $cupo) {
                throw new Exception('La sección ' . $cohorte['codigo_cohorte'] . ' alcanzó su cupo máximo (' . $cupo . ').');
            }

            $st2 = $pdo->prepare("INSERT INTO public.inscripciones
                    (id_curso, id_estudiante, fecha_inscripcion, nota_cualitativa)
                    VALUES (:c, :u, CURRENT_TIMESTAMP, 'En Progreso')
                    ON CONFLICT (id_curso, id_estudiante) DO NOTHING");
            $st2->execute([':c' => $id_cohorte, ':u' => $id_usuario]);

            if ($st2->rowCount() === 0) {
                $res['ya'] = true;
            } else {
                $res['ok'] = true;
            }

            if ($propia) { $pdo->commit(); }
            return $res;
        } catch (Throwable $e) {
            if ($propia && $pdo->inTransaction()) { $pdo->rollBack(); }
            $res['error'] = $e->getMessage();
            return $res;
        }
    }
}

if (!function_exists('inscripcion_cedula_bitacora')) {
    function inscripcion_cedula_bitacora(PDO $pdo, string $accion): void
    {
        try {
            $st = $pdo->prepare("INSERT INTO public.bitacora (id_usuario, modulo, accion, ip_maquina)
                                 VALUES (:u, 'INSCRIPCION_CEDULA', :a, :ip)");
            $st->execute([
                ':u'  => $_SESSION['id_usuario'] ?? null,
                ':a'  => mb_substr($accion, 0, 900),
                ':ip' => trim((string) ($_SERVER['REMOTE_ADDR'] ?? '')) ?: 'local-cli',
            ]);
        } catch (Throwable $e) {
            error_log('INSCRIPCION_CEDULA bitácora: ' . $e->getMessage());
        }
    }
}

if (!function_exists('inscripcion_cedula_extraer')) {
    /**
     * Extrae cédulas desde una línea de texto (CSV, TXT o celda de XLSX).
     */
    function inscripcion_cedula_extraer(string $linea): array
    {
        $linea = trim($linea);
        if ($linea === '') { return []; }
        $min = strtolower(trim(str_replace(['á', 'é', 'í', 'ó', 'ú'], ['a','e','i','o','u'], $linea)));
        if (in_array($min, ['cedula', 'cédula', 'cedula del estudiante', 'documento', 'documento de identidad'], true)) {
            return [];
        }
        preg_match_all('/\d{6,9}/', $linea, $m);
        return array_values(array_unique($m[0]));
    }
}

if (!function_exists('inscripcion_cedula_leer_origen')) {
    /**
     * Devuelve la lista de cédulas desde texto pegado o archivo subido.
     *
     * @return array{cedulas:array, errores:array}
     */
    function inscripcion_cedula_leer_origen(string $texto, array $archivo = []): array
    {
        $cedulas = [];
        $errores = [];

        foreach (preg_split('/\R+/u', $texto) ?: [] as $linea) {
            foreach (inscripcion_cedula_extraer($linea) as $c) {
                $cedulas[$c] = $c;
            }
        }

        if (!empty($archivo['tmp_name']) && is_uploaded_file($archivo['tmp_name'])) {
            $ext = strtolower(pathinfo((string) $archivo['name'], PATHINFO_EXTENSION));
            try {
                if ($ext === 'xlsx') {
                    $filas = xlsx_leer_filas($archivo['tmp_name']);
                    foreach ($filas as $fila) {
                        foreach ((array) ($fila['celdas'] ?? $fila) as $celda) {
                            $valor = is_array($celda) ? (string) ($celda['v'] ?? '') : (string) $celda;
                            foreach (inscripcion_cedula_extraer($valor) as $c) {
                                $cedulas[$c] = $c;
                            }
                        }
                    }
                } elseif (in_array($ext, ['csv', 'txt'], true)) {
                    $contenido = file_get_contents($archivo['tmp_name']);
                    if ($contenido === false) { throw new Exception('No se pudo leer el archivo.'); }
                    foreach (preg_split('/\R+/u', $contenido) ?: [] as $linea) {
                        foreach (inscripcion_cedula_extraer($linea) as $c) {
                            $cedulas[$c] = $c;
                        }
                    }
                } else {
                    $errores[] = 'Formato de archivo no admitido. Use .csv, .txt o .xlsx.';
                }
            } catch (Throwable $e) {
                $errores[] = 'Error al leer el archivo: ' . $e->getMessage();
            }
        }

        return ['cedulas' => array_values($cedulas), 'errores' => $errores];
    }
}
