<?php
// /sistema_nora/modulos/recepcion/padron.php
// Resolución de identidad por cédula usando padron_nacional_db.public.datos.
// Se usa tanto en el alta manual de visitas como en el escáner QR.

if (!function_exists('recepcion_cedula_limpia')) {
    /** Normaliza una cédula a solo dígitos (acepta V-12345678). */
    function recepcion_cedula_limpia($valor): string
    {
        return preg_replace('/\D+/', '', trim((string) $valor));
    }
}

if (!function_exists('recepcion_nombre_valido')) {
    /** Detecta nombres vacíos o marcadores de "pendiente" que no deben prevalecer. */
    function recepcion_nombre_valido($nombre): bool
    {
        $nombre = strtoupper(trim((string) $nombre));
        if ($nombre === '') { return false; }
        return !in_array($nombre, ['POR COMPLETAR', 'SIN NOMBRE', 'NO REGISTRADO', 'NN', 'N/A', 'ND'], true);
    }
}

if (!function_exists('recepcion_buscar_padron')) {
    /**
     * Busca una persona en el Padrón Nacional por cédula.
     * El campo document es varchar e indexado, por lo que se prueban variantes
     * exactas (sin scans regex sobre 35M filas) y se elige la fila más completa.
     *
     * @return array|null Datos normalizados o null si no hay coincidencia.
     */
    function recepcion_buscar_padron($pdo_padron, string $cedula): ?array
    {
        $cedula = recepcion_cedula_limpia($cedula);
        if ($cedula === '' || empty($pdo_padron)) { return null; }

        $sin_ceros = ltrim($cedula, '0');
        if ($sin_ceros === '') { $sin_ceros = '0'; }

        $candidatos = [];
        foreach ([
            $cedula,
            $sin_ceros,
            str_pad($sin_ceros, 8, '0', STR_PAD_LEFT),
            str_pad($sin_ceros, 9, '0', STR_PAD_LEFT),
        ] as $doc) {
            if ($doc !== '' && !in_array($doc, $candidatos, true)) { $candidatos[] = $doc; }
        }

        $sql = "SELECT first_name, second_name, first_surname, second_surname,
                       birth_date, genero, estado_civil
                  FROM public.datos
                 WHERE document = :doc
                 ORDER BY
                    (CASE WHEN COALESCE(BTRIM(first_name), '') <> '' THEN 1 ELSE 0 END)
                  + (CASE WHEN COALESCE(BTRIM(first_surname), '') <> '' THEN 1 ELSE 0 END) DESC,
                    LENGTH(CONCAT_WS(' ', first_name, second_name, first_surname, second_surname)) DESC
                 LIMIT 1";

        $fila = null;
        try {
            $stmt = $pdo_padron->prepare($sql);
            foreach ($candidatos as $doc) {
                $stmt->execute([':doc' => $doc]);
                $fila = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($fila) { break; }
            }
        } catch (Throwable $e) {
            error_log('RECEPCION padron: ' . $e->getMessage());
            return null;
        }

        if (!$fila) { return null; }

        $nombres   = trim(((string)($fila['first_name'] ?? '')) . ' ' . ((string)($fila['second_name'] ?? '')));
        $apellidos = trim(((string)($fila['first_surname'] ?? '')) . ' ' . ((string)($fila['second_surname'] ?? '')));
        $completo  = trim($nombres . ' ' . $apellidos);
        if (!recepcion_nombre_valido($completo)) { return null; }

        $fecha_nac = null;
        if (!empty($fila['birth_date'])) {
            try { $fecha_nac = (new DateTime((string) $fila['birth_date']))->format('Y-m-d'); }
            catch (Throwable $e) { $fecha_nac = null; }
        }

        $generos = ['F' => 'Femenino', 'M' => 'Masculino'];
        $genero_raw = strtoupper(trim((string)($fila['genero'] ?? '')));
        $estados = [
            '1' => 'Soltero(a)', '2' => 'Casado(a)', '3' => 'Divorciado(a)',
            '4' => 'Viudo(a)', '5' => 'Concubinato',
        ];
        $estado_raw = trim((string)($fila['estado_civil'] ?? ''));

        return [
            'cedula'           => $cedula,
            'nombre'           => $nombres,
            'apellido'         => $apellidos,
            'nombre_completo'  => $completo,
            'fecha_nacimiento' => $fecha_nac,
            'genero'           => $generos[$genero_raw] ?? ($fila['genero'] ?? null),
            'estado_civil'     => $estados[$estado_raw] ?? ($estado_raw !== '' ? $estado_raw : null),
            'fuente'           => 'PADRON_NACIONAL',
        ];
    }
}

if (!function_exists('recepcion_resolver_identidad')) {
    /**
     * Resuelve la identidad de una cédula para Recepción.
     * Precedencia: Padrón Nacional > usuarios del sistema > nombre escrito manualmente.
     *
     * @return array{
     *   cedula:string, es_usuario_sistema:bool, id_usuario:?int,
     *   nombre_completo:string, telefono:string, entidad_origen:string,
     *   fuente_datos:string, padron:?array, usuario:?array
     * }
     */
    function recepcion_resolver_identidad($pdo, $pdo_padron, string $cedula, string $nombre_manual = ''): array
    {
        $cedula = recepcion_cedula_limpia($cedula);

        $usuario = null;
        try {
            $st = $pdo->prepare("SELECT id_usuario, nombre, apellido, telefono
                                   FROM public.usuarios
                                  WHERE regexp_replace(cedula, '[^0-9]', '', 'g') = :ced
                                  LIMIT 1");
            $st->execute([':ced' => $cedula]);
            $usuario = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (Throwable $e) {
            error_log('RECEPCION identidad usuarios: ' . $e->getMessage());
        }

        $padron = recepcion_buscar_padron($pdo_padron, $cedula);

        $nombre_usuario = '';
        if ($usuario) {
            $nombre_usuario = trim(((string)($usuario['nombre'] ?? '')) . ' ' . ((string)($usuario['apellido'] ?? '')));
        }

        $nombre_final = '';
        $fuente = 'MANUAL';
        if ($padron && recepcion_nombre_valido($padron['nombre_completo'])) {
            $nombre_final = $padron['nombre_completo'];
            $fuente = 'PADRON_NACIONAL';
        } elseif (recepcion_nombre_valido($nombre_usuario)) {
            $nombre_final = $nombre_usuario;
            $fuente = 'SISTEMA';
        } elseif (recepcion_nombre_valido($nombre_manual)) {
            $nombre_final = trim($nombre_manual);
            $fuente = 'MANUAL';
        }

        $telefono = trim((string)($usuario['telefono'] ?? ''));
        $entidad = $usuario ? 'Comunidad Nora Castañeda' : '';

        return [
            'cedula'            => $cedula,
            'es_usuario_sistema' => (bool) $usuario,
            'id_usuario'        => $usuario ? (int) $usuario['id_usuario'] : null,
            'nombre_completo'   => $nombre_final,
            'telefono'          => $telefono,
            'entidad_origen'    => $entidad,
            'fuente_datos'      => $fuente,
            'padron'            => $padron,
            'usuario'           => $usuario,
        ];
    }
}
