<?php
// /sistema_nora/includes/calculador_fechas.php

function calcularFechaFinPlanificada($pdo, $fecha_inicio_str, $horas_totales, $horas_diarias, $dias_semana_array) {
    if ($horas_diarias <= 0 || empty($dias_semana_array)) return $fecha_inicio_str;

    // 1. Obtener lista de feriados nacionales
    $stmt = $pdo->query("SELECT fecha FROM public.feriados_venezuela");
    $feriados = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $dias_requeridos = ceil($horas_totales / $horas_diarias);
    $dias_contados = 0;
    $fecha_actual = new DateTime($fecha_inicio_str);

    // Mapeo de días en español a formato N de DateTime (1 = Lunes, 7 = Domingo)
    $mapa_dias = ['Lunes' => 1, 'Martes' => 2, 'Miércoles' => 3, 'Jueves' => 4, 'Viernes' => 5, 'Sábado' => 6, 'Domingo' => 7];
    $dias_habiles_num = array_map(function($d) use ($mapa_dias) { return $mapa_dias[$d] ?? 0; }, $dias_semana_array);

    while ($dias_contados < $dias_requeridos) {
        $num_dia_semana = (int)$fecha_actual->format('N');
        $fecha_iso = $fecha_actual->format('Y-m-d');

        // Verificar si el día corresponde al horario y no es feriado nacional
        if (in_array($num_dia_semana, $dias_habiles_num) && !in_array($fecha_iso, $feriados)) {
            $dias_contados++;
        }

        if ($dias_contados < $dias_requeridos) {
            $fecha_actual->modify('+1 day');
        }
    }

    return $fecha_actual->format('Y-m-d');
}
?>