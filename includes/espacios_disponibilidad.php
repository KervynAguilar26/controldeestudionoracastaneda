<?php
/**
 * /sistema_nora/includes/espacios_disponibilidad.php
 *
 * Estado dinámico de los espacios del campus.
 *
 * Regla central: un salón NO se bloquea "por día", sino solo durante la
 * franja exacta en la que está asignado (día + hora_inicio / hora_fin).
 * Fuera de esa franja el espacio queda DESBLOQUEADO, aunque el mismo día
 * tenga otras clases asignadas más temprano o más tarde.
 *
 * Fuentes de bloqueo:
 *  - horarios_secciones (bloques reales cargados en el cuadre de horarios)
 *  - cohortes.hora_inicio / hora_fin (rango base, cuando aún no hay bloques)
 *  - prestamo_misiones.dias_horario (préstamos ya otorgados)
 *  - campus_espacios.estatus ('En Mantenimiento' bloquea siempre)
 *
 * Se usa desde:
 *  - modulos/control_estudio/api_consultar_disponibilidad.php (checklist de préstamos)
 *  - modulos/control_estudio/misiones_instalaciones.php (render del checklist)
 *  - modulos/configuracion/gestion_infraestructura.php (estado "ahora" del inventario)
 *  - modulos/configuracion/infra_movil.php (estado "ahora" en el portal móvil)
 *  - modulos/control_estudio/crear_cohorte.php (choque de salón al aperturar/editar)
 *  - modulos/control_estudio/gestionar_horarios.php (validación al cuadrar horarios)
 *  - modulos/control_estudio/horario_salones.php (estado actual en la impresión)
 */

require_once __DIR__ . '/disponibilidad_docente.php';
require_once __DIR__ . '/horario_generator.php';

/** Días canónicos en español (lunes = 0). */
if (!function_exists('espacios_dias')) {
    function espacios_dias(): array
    {
        return ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];
    }
}

/** Quita tildes y pasa a minúsculas para comparar textos de horarios. */
if (!function_exists('espacios_texto_plano')) {
    function espacios_texto_plano(string $texto): string
    {
        $texto = mb_strtolower($texto, 'UTF-8');
        return strtr($texto, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'ü' => 'u', 'ñ' => 'n',
        ]);
    }
}

/**
 * Normaliza una hora escrita de cualquier forma usual a 'H:i:s'.
 * Acepta: '07:00', '7:00:00', '7am', '7:00 PM', '12 pm', '19:30'.
 */
if (!function_exists('espacios_normalizar_hora')) {
    function espacios_normalizar_hora($valor): string
    {
        $valor = trim((string) $valor);
        if ($valor === '') {
            return '';
        }
        $valor = preg_replace('/\s+/', ' ', $valor);
        $valor = str_replace(['a.m.', 'p.m.', 'a. m.', 'p. m.'], ['am', 'pm', 'am', 'pm'], $valor);
        $valor = espacios_texto_plano($valor);

        if (!preg_match('/^(\d{1,2})(?::(\d{1,2}))?(?::(\d{1,2}))?\s*(am|pm)?$/', $valor, $m)) {
            return docente_normalizar_hora($valor);
        }

        $h = (int) $m[1];
        $mi = isset($m[2]) ? (int) $m[2] : 0;
        $se = isset($m[3]) ? (int) $m[3] : 0;
        $mer = $m[4] ?? '';

        if ($mer !== '') {
            if ($h < 1 || $h > 12) {
                return '';
            }
            if ($mer === 'pm' && $h < 12) {
                $h += 12;
            } elseif ($mer === 'am' && $h === 12) {
                $h = 0;
            }
        } elseif ($h > 23) {
            return '';
        }

        if ($mi > 59 || $se > 59) {
            return '';
        }

        return sprintf('%02d:%02d:%02d', $h, $mi, $se);
    }
}

/**
 * Parsea los días de un texto libre o de los valores guardados en BD.
 * Reconoce rangos ("Lunes a Viernes"), nombres completos y abreviaturas
 * ("Sáb,Dom 08:00 a 12:00").
 */
if (!function_exists('espacios_dias_desde_texto')) {
    function espacios_dias_desde_texto(string $texto): array
    {
        $canon = espacios_dias();
        $norm = espacios_texto_plano($texto);

        $rangos = [
            'lunes a domingo'   => $canon,
            'lunes-domingo'     => $canon,
            'lunes a sabado'    => array_slice($canon, 0, 6),
            'lunes-sabado'      => array_slice($canon, 0, 6),
            'lunes a viernes'   => array_slice($canon, 0, 5),
            'lunes-viernes'     => array_slice($canon, 0, 5),
        ];
        foreach ($rangos as $aguja => $dias) {
            if (strpos($norm, $aguja) !== false) {
                return $dias;
            }
        }

        $prefijos = ['lun', 'mar', 'mie', 'jue', 'vie', 'sab', 'dom'];
        $encontrados = [];
        foreach ($prefijos as $i => $prefijo) {
            if (preg_match('/(^|[^\p{L}\p{N}])' . preg_quote($prefijo, '/') . '/u', $norm)) {
                $encontrados[] = $canon[$i];
            }
        }

        if (empty($encontrados)) {
            // Fallback para textos con nombres completos no contemplados arriba.
            foreach (horario_dias_desde_texto($texto) as $dia) {
                if (strpos($norm, espacios_texto_plano($dia)) !== false) {
                    $encontrados[] = $dia;
                }
            }
        }

        return array_values(array_unique($encontrados));
    }
}

/**
 * Extrae el rango horario de un texto tipo "Sáb,Dom 08:00 a 12:00"
 * o "Lunes 7am - 12pm". Devuelve ['inicio' => 'H:i:s', 'fin' => 'H:i:s'].
 */
if (!function_exists('espacios_rango_desde_texto')) {
    function espacios_rango_desde_texto(string $texto): ?array
    {
        preg_match_all(
            '/\d{1,2}:\d{2}(?::\d{2})?\s*(?:a\.?m\.?|p\.?m\.?)?|\d{1,2}\s*(?:a\.?m\.?|p\.?m\.?)/iu',
            $texto,
            $coincidencias
        );

        $horas = [];
        foreach ($coincidencias[0] ?? [] as $token) {
            $hora = espacios_normalizar_hora($token);
            if ($hora !== '' && !in_array($hora, $horas, true)) {
                $horas[] = $hora;
            }
        }

        if (count($horas) < 2) {
            return null;
        }

        return ['inicio' => $horas[0], 'fin' => $horas[count($horas) - 1]];
    }
}

/** true cuando dos franjas horarias se solapan (se tocan en los bordes = no solape). */
if (!function_exists('espacios_franjas_solapan')) {
    function espacios_franjas_solapan(string $ini1, string $fin1, string $ini2, string $fin2): bool
    {
        $a = espacios_normalizar_hora($ini1);
        $b = espacios_normalizar_hora($fin1);
        $c = espacios_normalizar_hora($ini2);
        $d = espacios_normalizar_hora($fin2);
        if ($a === '' || $b === '' || $c === '' || $d === '') {
            return false;
        }
        if ($b <= $a) {
            $b = '23:59:59';
        }
        if ($d <= $c) {
            $d = '23:59:59';
        }
        return $a < $d && $b > $c;
    }
}

/** true cuando dos listas de días tienen algún día en común. */
if (!function_exists('espacios_dias_coinciden')) {
    function espacios_dias_coinciden(array $solicitados, array $asignados): bool
    {
        foreach ($solicitados as $dia) {
            if (in_array($dia, $asignados, true)) {
                return true;
            }
        }
        return false;
    }
}

/** Comprueba si el nombre de un espacio aparece en un texto de referencia. */
if (!function_exists('espacios_nombre_en_texto')) {
    function espacios_nombre_en_texto(string $texto, string $nombre): bool
    {
        $nombre = trim($nombre);
        if ($nombre === '' || $texto === '') {
            return false;
        }
        $patron = '/\b' . preg_quote($nombre, '/') . '\b/iu';
        return (bool) preg_match($patron, $texto);
    }
}

/** Día en español a partir de un objeto DateTime (o de la fecha actual). */
if (!function_exists('espacios_dia_de_fecha')) {
    function espacios_dia_de_fecha(DateTimeInterface $fecha): string
    {
        $mapa = [
            'Monday' => 'Lunes', 'Tuesday' => 'Martes', 'Wednesday' => 'Miércoles',
            'Thursday' => 'Jueves', 'Friday' => 'Viernes', 'Saturday' => 'Sábado',
            'Sunday' => 'Domingo',
        ];
        return $mapa[$fecha->format('l')] ?? 'Lunes';
    }
}

/**
 * Devuelve todos los bloqueos aplicables a un espacio en la ventana pedida.
 *
 * @return array<int,array{tipo:string,referencia:string,dias:array,inicio:string,fin:string,detalle:string}>
 */
if (!function_exists('espacios_bloqueos')) {
    function espacios_bloqueos(
        PDO $pdo,
        string $nombre_espacio,
        array $dias_solicitados,
        string $hora_ini,
        string $hora_fin,
        string $fecha_ini,
        string $fecha_fin,
        ?int $excluir_prestamo = null,
        ?int $excluir_cohorte = null
    ): array {
        $ini = espacios_normalizar_hora($hora_ini);
        $fin = espacios_normalizar_hora($hora_fin);
        if ($ini === '' || $fin === '') {
            return [];
        }
        $fecha_ini = $fecha_ini !== '' ? $fecha_ini : date('Y-m-d');
        $fecha_fin = $fecha_fin !== '' ? $fecha_fin : $fecha_ini;
        if ($fecha_fin < $fecha_ini) {
            [$fecha_ini, $fecha_fin] = [$fecha_fin, $fecha_ini];
        }

        $dias_solicitados = array_values(array_filter($dias_solicitados, 'strlen'));
        if (empty($dias_solicitados)) {
            return [];
        }

        $bloqueos = [];

        // 1) Cohortes activas: se priorizan los bloques reales de horarios_secciones.
        $stmt = $pdo->prepare("
            SELECT c.id_cohorte, c.codigo_cohorte, c.dias_clase, c.hora_inicio, c.hora_fin, c.salon_aula
            FROM public.cohortes c
            WHERE c.estatus IN ('Planificada', 'En Curso')
              AND c.fecha_inicio <= :ff
              AND COALESCE(c.fecha_fin_estimada, :fi) >= :fi
            ORDER BY c.codigo_cohorte
        ");
        $stmt->execute([':fi' => $fecha_ini, ':ff' => $fecha_fin]);
        $cohortes = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt_h = $pdo->prepare("
            SELECT dia_semana, hora_inicio, hora_fin, salon_aula
            FROM public.horarios_secciones
            WHERE id_cohorte = :id
            ORDER BY hora_inicio
        ");

        foreach ($cohortes as $coh) {
            $id_cohorte = (int) $coh['id_cohorte'];
            if ($excluir_cohorte !== null && $id_cohorte === $excluir_cohorte) {
                continue;
            }
            // El salón puede estar en la cohorte, en el bloque o en ambos.
            $salon_cohorte = (string) ($coh['salon_aula'] ?? '');
            $cohorte_uso_este_espacio = $salon_cohorte !== ''
                && espacios_nombre_en_texto($salon_cohorte, $nombre_espacio);
            if (!$cohorte_uso_este_espacio) {
                continue;
            }

            $referencia_texto = (string) $coh['codigo_cohorte'];
            $dias_cohorte = espacios_dias_desde_texto((string) $coh['dias_clase']);

            $stmt_h->execute([':id' => $id_cohorte]);
            $filas_h = $stmt_h->fetchAll(PDO::FETCH_ASSOC);

            $por_dia = [];
            foreach ($filas_h as $h) {
                $salon_bloque = (string) ($h['salon_aula'] ?? '');
                if ($salon_bloque !== '' && !espacios_nombre_en_texto($salon_bloque, $nombre_espacio)) {
                    continue; // Bloque de otra sección/aula dentro de la misma cohorte.
                }
                $por_dia[$h['dia_semana']][] = [
                    'inicio' => espacios_normalizar_hora($h['hora_inicio']),
                    'fin'    => espacios_normalizar_hora($h['hora_fin']),
                ];
            }

            if (empty($por_dia)) {
                $rango_base = [
                    'inicio' => espacios_normalizar_hora($coh['hora_inicio']),
                    'fin'    => espacios_normalizar_hora($coh['hora_fin']),
                ];
                if ($rango_base['inicio'] !== '' && $rango_base['fin'] !== '') {
                    $por_dia = array_fill_keys($dias_cohorte, [$rango_base]);
                }
            }

            foreach ($por_dia as $dia => $franjas) {
                if (!in_array($dia, $dias_solicitados, true)) {
                    continue;
                }
                foreach ($franjas as $franja) {
                    if (!espacios_franjas_solapan($ini, $fin, $franja['inicio'], $franja['fin'])) {
                        continue; // Mismo día pero otra franja => NO bloquea.
                    }
                    $bloqueos[] = [
                        'tipo'       => 'Sección',
                        'referencia' => $referencia_texto,
                        'dias'       => [$dia],
                        'inicio'     => $franja['inicio'],
                        'fin'        => $franja['fin'],
                        'detalle'    => $referencia_texto . ' · ' . $dia . ' ' .
                                        substr($franja['inicio'], 0, 5) . ' - ' . substr($franja['fin'], 0, 5),
                    ];
                }
            }
        }

        // 2) Préstamos a misiones ya registrados.
        $sql = "
            SELECT id_prestamo, nombre_mision, salon_asignado, dias_horario
            FROM public.prestamo_misiones
            WHERE fecha_inicio <= :ff AND fecha_fin >= :fi
        ";
        $params = [':fi' => $fecha_ini, ':ff' => $fecha_fin];
        if ($excluir_prestamo !== null) {
            $sql .= ' AND id_prestamo != :excluir';
            $params[':excluir'] = $excluir_prestamo;
        }
        $stmt_p = $pdo->prepare($sql . ' ORDER BY fecha_inicio DESC');
        $stmt_p->execute($params);
        foreach ($stmt_p->fetchAll(PDO::FETCH_ASSOC) as $prestamo) {
            $salon_prestamo = (string) ($prestamo['salon_asignado'] ?? '');
            if ($salon_prestamo === '' || !espacios_nombre_en_texto($salon_prestamo, $nombre_espacio)) {
                continue;
            }
            $texto = (string) $prestamo['dias_horario'];
            $dias = espacios_dias_desde_texto($texto);
            $rango = espacios_rango_desde_texto($texto);
            if ($rango === null || !espacios_dias_coinciden($dias_solicitados, $dias)) {
                continue;
            }
            if (!espacios_franjas_solapan($ini, $fin, $rango['inicio'], $rango['fin'])) {
                continue;
            }
            $bloqueos[] = [
                'tipo'       => 'Préstamo',
                'referencia' => (string) $prestamo['nombre_mision'],
                'dias'       => $dias,
                'inicio'     => $rango['inicio'],
                'fin'        => $rango['fin'],
                'detalle'    => 'Préstamo ' . $prestamo['nombre_mision'] . ' · ' .
                                implode(', ', $dias) . ' ' .
                                substr($rango['inicio'], 0, 5) . ' - ' . substr($rango['fin'], 0, 5),
            ];
        }

        // 3) Agrupa los bloques contiguos de cada sección/préstamo para mostrar
        //    un solo rango legible (p. ej. "Lunes 07:00 - 11:15") en vez de
        //    una larga lista de bloques de 45 minutos.
        $grupos = [];
        foreach ($bloqueos as $b) {
            foreach ($b['dias'] as $dia) {
                $clave = $b['tipo'] . '|' . $b['referencia'] . '|' . $dia;
                $grupos[$clave][] = [
                    'inicio' => $b['inicio'],
                    'fin'    => $b['fin'],
                ];
            }
        }

        $resultado = [];
        foreach ($grupos as $clave => $franjas) {
            [$tipo, $referencia, $dia] = explode('|', $clave, 3);
            usort($franjas, static fn($a, $b) => strcmp($a['inicio'], $b['inicio']));

            $fusionadas = [];
            foreach ($franjas as $franja) {
                $ultima = count($fusionadas) - 1;
                $hueco = $ultima >= 0
                    ? (strtotime('2000-01-01 ' . $franja['inicio']) - strtotime('2000-01-01 ' . $fusionadas[$ultima]['fin']))
                    : PHP_INT_MAX;
                // Se unen bloques contiguos y también los separados por un recreo
                // de hasta 15 minutos, que forman parte de la misma franja activa.
                if ($hueco <= 15 * 60) {
                    if ($franja['fin'] > $fusionadas[$ultima]['fin']) {
                        $fusionadas[$ultima]['fin'] = $franja['fin'];
                    }
                } else {
                    $fusionadas[] = $franja;
                }
            }

            foreach ($fusionadas as $franja) {
                $resultado[] = [
                    'tipo'       => $tipo,
                    'referencia' => $referencia,
                    'dias'       => [$dia],
                    'inicio'     => $franja['inicio'],
                    'fin'        => $franja['fin'],
                    'detalle'    => $referencia . ' · ' . $dia . ' ' .
                                    substr($franja['inicio'], 0, 5) . ' - ' . substr($franja['fin'], 0, 5),
                ];
            }
        }

        return $resultado;
    }
}

/**
 * Estado completo de un espacio para una ventana concreta.
 *
 * @return array{bloqueado:bool,estado:string,motivo:string,bloqueos:array}
 */
if (!function_exists('espacios_estado')) {
    function espacios_estado(
        PDO $pdo,
        string $nombre_espacio,
        array $dias_solicitados,
        string $hora_ini,
        string $hora_fin,
        string $fecha_ini,
        string $fecha_fin,
        ?int $excluir_prestamo = null,
        ?int $excluir_cohorte = null
    ): array {
        $estatus = 'Disponible';
        $motivo_mantenimiento = '';
        try {
            $stmt = $pdo->prepare("SELECT estatus, motivo_mantenimiento FROM public.campus_espacios WHERE nombre_espacio = :n LIMIT 1");
            $stmt->execute([':n' => $nombre_espacio]);
            $fila = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($fila) {
                $estatus = (string) $fila['estatus'];
                $motivo_mantenimiento = (string) ($fila['motivo_mantenimiento'] ?? '');
            }
        } catch (Throwable $e) {
            // Si falla la consulta de mantenimiento se evalúan solo los horarios.
        }

        if ($estatus !== 'Disponible') {
            return [
                'bloqueado' => true,
                'estado'    => 'BLOQUEADO',
                'motivo'    => 'En mantenimiento' . ($motivo_mantenimiento !== '' ? ': ' . $motivo_mantenimiento : ''),
                'bloqueos'  => [],
            ];
        }

        $bloqueos = espacios_bloqueos(
            $pdo,
            $nombre_espacio,
            $dias_solicitados,
            $hora_ini,
            $hora_fin,
            $fecha_ini,
            $fecha_fin,
            $excluir_prestamo,
            $excluir_cohorte
        );

        if (empty($bloqueos)) {
            return [
                'bloqueado' => false,
                'estado'    => 'DESBLOQUEADO',
                'motivo'    => 'Sin asignación en la franja solicitada',
                'bloqueos'  => [],
            ];
        }

        $detalles = array_map(static fn($b) => $b['detalle'], $bloqueos);
        return [
            'bloqueado' => true,
            'estado'    => 'BLOQUEADO',
            'motivo'    => implode(' · ', $detalles),
            'bloqueos'  => $bloqueos,
        ];
    }
}

/**
 * Estado del espacio en este momento (día y hora actuales del servidor).
 */
if (!function_exists('espacios_estado_actual')) {
    function espacios_estado_actual(PDO $pdo, string $nombre_espacio, ?DateTimeInterface $ahora = null): array
    {
        $ahora = $ahora ?? new DateTimeImmutable();
        $dia = espacios_dia_de_fecha($ahora);
        $ini = $ahora->format('H:i:s');
        $fin = (clone $ahora)->modify('+1 minute')->format('H:i:s');
        $hoy = $ahora->format('Y-m-d');

        return espacios_estado($pdo, $nombre_espacio, [$dia], $ini, $fin, $hoy, $hoy);
    }
}
