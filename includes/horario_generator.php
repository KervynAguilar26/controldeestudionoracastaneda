<?php
/**
 * GENERADOR AUTOMÁTICO DE HORARIOS POR SALÓN / COHORTE
 * Escuela Internacional de Liderazgo de la Mujer "Nora Castañeda"
 *
 * Reglas:
 *  - Un salón equivale a un grupo de estudiantes (cohorte).
 *  - Cada hora académica dura 45 minutos y tiene 15 minutos de intermedio.
 *  - Las materias se distribuyen entre los días de clase de la cohorte.
 *  - El horario se regenera automáticamente al crear/editar la cohorte.
 */

if (!defined('HORARIO_MINUTOS_CLASE')) define('HORARIO_MINUTOS_CLASE', 45);
if (!defined('HORARIO_MINUTOS_DESCANSO')) define('HORARIO_MINUTOS_DESCANSO', 15);

/**
 * Devuelve los días de la semana indicados en el texto de la cohorte.
 * Ej: "Lunes a Viernes (08:00 - 12:30)" => [Lunes, Martes, Miércoles, Jueves, Viernes]
 */
function horario_dias_desde_texto(string $texto): array
{
    $canonicos = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];
    $texto_norm = mb_strtolower($texto, 'UTF-8');

    if (strpos($texto_norm, 'lunes a viernes') !== false) {
        return array_slice($canonicos, 0, 5);
    }
    if (strpos($texto_norm, 'lunes a sábado') !== false || strpos($texto_norm, 'lunes a sabado') !== false) {
        return array_slice($canonicos, 0, 6);
    }
    if (strpos($texto_norm, 'lunes a domingo') !== false) {
        return $canonicos;
    }

    $encontrados = [];
    foreach ($canonicos as $dia) {
        $clave = mb_strtolower($dia, 'UTF-8');
        if (strpos($texto_norm, $clave) !== false) {
            $encontrados[] = $dia;
        }
    }
    return !empty($encontrados) ? $encontrados : array_slice($canonicos, 0, 5);
}

/**
 * Genera los bloques diarios: 45 min de clase + 15 min de intermedio.
 * Devuelve [ ['inicio' => 'H:i', 'fin' => 'H:i'], ... ]
 */
function horario_bloques(string $hora_inicio, string $hora_fin): array
{
    $inicio = DateTime::createFromFormat('H:i:s', strlen($hora_inicio) === 8 ? $hora_inicio : $hora_inicio . ':00');
    $fin    = DateTime::createFromFormat('H:i:s', strlen($hora_fin) === 8 ? $hora_fin : $hora_fin . ':00');

    if (!$inicio || !$fin || $fin <= $inicio) {
        return [];
    }

    $bloques = [];
    $cursor  = clone $inicio;
    $paso    = new DateInterval('PT' . HORARIO_MINUTOS_CLASE . 'M');
    $receso  = new DateInterval('PT' . HORARIO_MINUTOS_DESCANSO . 'M');

    // Límite de seguridad para evitar bucles infinitos
    for ($i = 0; $i < 24; $i++) {
        $bloque_fin = (clone $cursor)->add($paso);
        if ($bloque_fin > $fin) {
            break;
        }
        $bloques[] = [
            'inicio' => $cursor->format('H:i'),
            'fin'    => $bloque_fin->format('H:i'),
        ];
        $cursor = (clone $bloque_fin)->add($receso);
    }

    return $bloques;
}

/**
 * Reparte los cupos de clase entre las materias según sus horas académicas.
 * Garantiza que la suma coincida con el número de bloques disponibles.
 */
function horario_distribuir_horas(array $pesos, int $cupo): array
{
    $n = count($pesos);
    if ($n === 0 || $cupo <= 0) {
        return [];
    }

    $total = array_sum($pesos);
    if ($total <= 0) {
        $total = $n;
        $pesos = array_fill(0, $n, 1);
    }

    $asignacion = [];
    if ($total >= $cupo) {
        foreach ($pesos as $i => $peso) {
            $asignacion[$i] = max(1, (int) floor(($peso / $total) * $cupo));
        }
    } else {
        foreach ($pesos as $i => $peso) {
            $asignacion[$i] = max(1, (int) $peso);
        }
    }

    // Recorta si se excede el cupo disponible
    while (array_sum($asignacion) > $cupo) {
        $sobrante = null;
        $mayor = 1;
        foreach ($asignacion as $i => $cantidad) {
            if ($cantidad > $mayor) {
                $mayor = $cantidad;
                $sobrante = $i;
            }
        }
        if ($sobrante === null) {
            break;
        }
        $asignacion[$sobrante]--;
    }

    // Completa el cupo favoreciendo a las materias con más horas
    while (array_sum($asignacion) < $cupo) {
        $elegida = null;
        $mejor = -1;
        foreach ($pesos as $i => $peso) {
            $puntaje = $peso / ($asignacion[$i] + 1);
            if ($puntaje > $mejor) {
                $mejor = $puntaje;
                $elegida = $i;
            }
        }
        if ($elegida === null) {
            break;
        }
        $asignacion[$elegida]++;
    }

    return $asignacion;
}

/**
 * Genera (o regenera) el horario de una cohorte.
 * Devuelve un arreglo con el resultado de la operación.
 */
function horario_generar_cohorte(PDO $pdo, int $id_cohorte): array
{
    $stmt = $pdo->prepare(
        "SELECT c.*, m.nombre_programa, m.total_periodos
           FROM public.cohortes c
           JOIN public.mallas_curriculares m ON m.id_malla = c.id_malla
          WHERE c.id_cohorte = :id LIMIT 1"
    );
    $stmt->execute([':id' => $id_cohorte]);
    $cohorte = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$cohorte) {
        return ['ok' => false, 'mensaje' => 'La cohorte no existe.'];
    }

    $dias = horario_dias_desde_texto($cohorte['dias_clase'] ?? 'Lunes a Viernes');

    $hora_inicio = $cohorte['hora_inicio'] ?? null;
    $hora_fin    = $cohorte['hora_fin'] ?? null;

    if (empty($hora_inicio) || empty($hora_fin)) {
        if (preg_match('/(\d{1,2}:\d{2})\s*-\s*(\d{1,2}:\d{2})/', $cohorte['dias_clase'] ?? '', $m)) {
            $hora_inicio = $m[1];
            $hora_fin    = $m[2];
        } else {
            $hora_inicio = '08:00';
            $hora_fin    = '12:30';
        }
    }

    $bloques = horario_bloques($hora_inicio, $hora_fin);
    if (empty($bloques)) {
        return ['ok' => false, 'mensaje' => 'El rango de horas de la cohorte no permite generar bloques de clase.'];
    }

    $periodo = intval($cohorte['num_periodo'] ?? 1);
    $stmt_m = $pdo->prepare(
        "SELECT id_materia, nombre_materia, horas_academicas
           FROM public.malla_materias
          WHERE id_malla = :malla AND modulo_num = :periodo
          ORDER BY id_materia ASC"
    );
    $stmt_m->execute([':malla' => $cohorte['id_malla'], ':periodo' => $periodo]);
    $materias = $stmt_m->fetchAll(PDO::FETCH_ASSOC);

    if (empty($materias)) {
        $stmt_m = $pdo->prepare(
            "SELECT id_materia, nombre_materia, horas_academicas
               FROM public.malla_materias
              WHERE id_malla = :malla
              ORDER BY modulo_num ASC, id_materia ASC"
        );
        $stmt_m->execute([':malla' => $cohorte['id_malla']]);
        $materias = $stmt_m->fetchAll(PDO::FETCH_ASSOC);
    }

    if (empty($materias)) {
        return ['ok' => false, 'mensaje' => 'La malla de esta cohorte no tiene materias registradas.'];
    }

    $cupo = count($dias) * count($bloques);
    $pesos = [];
    foreach ($materias as $m) {
        $pesos[] = max(1, intval($m['horas_academicas']));
    }
    $asignacion = horario_distribuir_horas($pesos, $cupo);

    // Matriz [indice_dia][indice_bloque] => materia
    $matriz = [];
    foreach ($materias as $i => $materia) {
        $dia_cursor = 0; // reinicia para repartir cada materia entre los días
        for ($k = 0; $k < ($asignacion[$i] ?? 0); $k++) {
            $colocada = false;
            for ($intento = 0; $intento < count($dias); $intento++) {
                $dia_idx = $dia_cursor % count($dias);
                $dia_cursor++;

                for ($b = 0; $b < count($bloques); $b++) {
                    if (!isset($matriz[$dia_idx][$b])) {
                        $matriz[$dia_idx][$b] = $materia;
                        $colocada = true;
                        break 2;
                    }
                }
            }
            if (!$colocada) {
                break;
            }
        }
    }

    $propia = !$pdo->inTransaction();
    try {
        if ($propia) { $pdo->beginTransaction(); }

        $pdo->prepare("DELETE FROM public.horarios_clases WHERE id_cohorte = :id")
            ->execute([':id' => $id_cohorte]);

        $ins = $pdo->prepare(
            "INSERT INTO public.horarios_clases
                (id_cohorte, id_materia, nombre_materia, dia_semana, hora_inicio, hora_fin, orden)
             VALUES (:cohorte, :materia, :nombre, :dia, :inicio, :fin, :orden)"
        );

        $total = 0;
        foreach ($matriz as $dia_idx => $columnas) {
            foreach ($columnas as $b => $materia) {
                $ins->execute([
                    ':cohorte' => $id_cohorte,
                    ':materia' => $materia['id_materia'],
                    ':nombre'  => $materia['nombre_materia'],
                    ':dia'     => $dias[$dia_idx],
                    ':inicio'  => $bloques[$b]['inicio'],
                    ':fin'     => $bloques[$b]['fin'],
                    ':orden'   => $b,
                ]);
                $total++;
            }
        }

        // Guarda las horas normalizadas en la cohorte
        $pdo->prepare(
            "UPDATE public.cohortes
                SET hora_inicio = COALESCE(hora_inicio, :hi),
                    hora_fin = COALESCE(hora_fin, :hf)
              WHERE id_cohorte = :id"
        )->execute([':hi' => $hora_inicio . (strlen($hora_inicio) === 5 ? ':00' : ''), ':hf' => $hora_fin . (strlen($hora_fin) === 5 ? ':00' : ''), ':id' => $id_cohorte]);

        if ($propia) { $pdo->commit(); }

        return [
            'ok' => true,
            'mensaje' => "Horario generado: {$total} bloques en " . count($dias) . ' días.',
            'bloques' => count($bloques),
            'dias' => count($dias),
            'total' => $total,
        ];
    } catch (Exception $e) {
        if ($propia && $pdo->inTransaction()) { $pdo->rollBack(); }
        return ['ok' => false, 'mensaje' => 'No se pudo generar el horario: ' . $e->getMessage()];
    }
}

/**
 * Devuelve días, bloques y matriz del horario de una cohorte.
 * Si aún no existe, lo genera automáticamente.
 */
function horario_obtener_matriz(PDO $pdo, int $id_cohorte): array
{
    $stmt = $pdo->prepare("SELECT * FROM public.cohortes WHERE id_cohorte = :id LIMIT 1");
    $stmt->execute([':id' => $id_cohorte]);
    $cohorte = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$cohorte) {
        return ['ok' => false, 'mensaje' => 'Cohorte no encontrada.', 'dias' => [], 'bloques' => [], 'matriz' => []];
    }

    $leer = function () use ($pdo, $id_cohorte) {
        $stmt = $pdo->prepare(
            "SELECT h.*,
                    COALESCE(NULLIF(CONCAT_WS(' ', up.nombre, up.apellido), ''),
                             NULLIF(CONCAT_WS(' ', us.nombre, us.apellido), '')) AS docente
               FROM public.horarios_clases h
               LEFT JOIN public.asignaciones_profesor ap
                      ON ap.id_materia = h.id_materia
                     AND ap.id_cohorte = h.id_cohorte
                     AND ap.estatus_aceptacion = 'Aceptado'
               LEFT JOIN public.usuarios up ON up.id_usuario = ap.id_profesor
               LEFT JOIN public.malla_materias mm ON mm.id_materia = h.id_materia
               LEFT JOIN public.usuarios us ON us.id_usuario = mm.id_profesor_sugerido
              WHERE h.id_cohorte = :id
              ORDER BY h.hora_inicio ASC, h.dia_semana ASC"
        );
        $stmt->execute([':id' => $id_cohorte]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    };

    $filas = $leer();
    if (empty($filas)) {
        $generado = horario_generar_cohorte($pdo, $id_cohorte);
        if (!empty($generado['ok'])) {
            $filas = $leer();
        }
    }

    $dias = horario_dias_desde_texto($cohorte['dias_clase'] ?? 'Lunes a Viernes');
    foreach ($filas as $f) {
        if (!in_array($f['dia_semana'], $dias, true)) {
            $dias[] = $f['dia_semana'];
        }
    }
    $orden_dias = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];
    usort($dias, function ($a, $b) use ($orden_dias) {
        return array_search($a, $orden_dias) <=> array_search($b, $orden_dias);
    });

    $bloques = horario_bloques(
        $cohorte['hora_inicio'] ?? '08:00',
        $cohorte['hora_fin'] ?? '12:30'
    );
    if (empty($bloques) && !empty($filas)) {
        // Reconstruye los bloques desde las filas guardadas
        $vistos = [];
        foreach ($filas as $f) {
            $clave = substr($f['hora_inicio'], 0, 5) . '|' . substr($f['hora_fin'], 0, 5);
            if (!isset($vistos[$clave])) {
                $vistos[$clave] = ['inicio' => substr($f['hora_inicio'], 0, 5), 'fin' => substr($f['hora_fin'], 0, 5)];
            }
        }
        $bloques = array_values($vistos);
        usort($bloques, fn($a, $b) => strcmp($a['inicio'], $b['inicio']));
    }

    $matriz = [];
    foreach ($dias as $dia) {
        foreach ($bloques as $bloque) {
            $matriz[$dia][$bloque['inicio']] = null;
        }
    }
    foreach ($filas as $f) {
        $h = substr($f['hora_inicio'], 0, 5);
        if (isset($matriz[$f['dia_semana']])) {
            $matriz[$f['dia_semana']][$h] = [
                'materia' => $f['nombre_materia'],
                'docente' => !empty($f['docente']) ? trim($f['docente']) : null,
            ];
        }
    }

    return [
        'ok' => true,
        'cohorte' => $cohorte,
        'dias' => $dias,
        'bloques' => $bloques,
        'matriz' => $matriz,
    ];
}
