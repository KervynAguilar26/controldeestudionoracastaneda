<?php
// /sistema_nora/includes/academico.php
// Reglas académicas compartidas: avance de la malla curricular, notas por
// materia y determinación de culminación de estudios.

if (!function_exists('academico_normalizar_cedula')) {
    function academico_normalizar_cedula(string $cedula): string
    {
        return preg_replace('/[^0-9]/', '', trim($cedula));
    }
}

if (!function_exists('academico_fecha')) {
    function academico_fecha(?string $fecha): string
    {
        if (empty($fecha)) {
            return '—';
        }
        $ts = strtotime($fecha);
        return $ts ? date('d/m/Y', $ts) : '—';
    }
}

if (!function_exists('academico_calcular_materia')) {
    /**
     * Calcula la nota final de una materia a partir de sus evaluaciones.
     * Recibe filas con: porcentaje_peso, nota_cuantitativa, nota_recuperacion,
     * nota_cualitativa, id_nota, fecha_evaluacion, titulo_evaluacion.
     */
    function academico_calcular_materia(array $filas): array
    {
        $evaluaciones = [];
        $suma_pesos = 0.0;
        $suma_ponderada = 0.0;
        $suma_notas = 0.0;
        $cual_aprobada = false;
        $fecha_ultima = null;

        foreach ($filas as $f) {
            if ($f['id_nota'] === null || $f['id_nota'] === '') {
                continue;
            }

            $cuant = ($f['nota_cuantitativa'] === null || $f['nota_cuantitativa'] === '')
                ? 0.0 : (float) $f['nota_cuantitativa'];
            $rec = ($f['nota_recuperacion'] === null || $f['nota_recuperacion'] === '')
                ? null : (float) $f['nota_recuperacion'];
            $nota = ($rec !== null && $rec > 0) ? $rec : $cuant;
            $peso = (float) ($f['porcentaje_peso'] ?? 0);

            $cual = strtoupper(trim((string) ($f['nota_cualitativa'] ?? '')));
            if (in_array($cual, ['APROBADO', 'EXCELENTE', 'APROBÓ', 'APROBO'], true)) {
                $cual_aprobada = true;
            }

            $evaluaciones[] = [
                'titulo'      => $f['titulo_evaluacion'] ?? '',
                'peso'        => $peso,
                'cuantitativa'=> $cuant,
                'recuperacion'=> $rec,
                'nota'        => $nota,
                'cualitativa' => $f['nota_cualitativa'] ?? '',
                'id_nota'     => $f['id_nota'],
            ];

            $suma_pesos      += $peso;
            $suma_ponderada  += $nota * $peso;
            $suma_notas      += $nota;

            if (!empty($f['fecha_evaluacion'])) {
                $ts = strtotime($f['fecha_evaluacion']);
                if ($ts && ($fecha_ultima === null || $ts > $fecha_ultima)) {
                    $fecha_ultima = $ts;
                }
            }
        }

        $total_eval = count($evaluaciones);
        $nota_final = null;
        $aprobada = false;

        if ($total_eval > 0) {
            $nota_final = $suma_pesos > 0
                ? $suma_ponderada / $suma_pesos
                : $suma_notas / $total_eval;
            $nota_final = round($nota_final, 2);

            // Se considera aprobada con 10 o más. La nota cualitativa solo
            // aprueba cuando no hay calificación numérica válida (mayor que 0).
            $aprobada = ($nota_final >= 10) || (($nota_final <= 0) && $cual_aprobada);
        }

        return [
            'evaluaciones'   => $evaluaciones,
            'total_eval'     => $total_eval,
            'nota_final'     => $nota_final,
            'aprobada'       => $aprobada,
            'cual_aprobada'  => $cual_aprobada,
            'fecha_ultima'   => $fecha_ultima ? date('Y-m-d', $fecha_ultima) : null,
            'suma_pesos'     => round($suma_pesos, 2),
        ];
    }
}

if (!function_exists('academico_estado_malla')) {
    /**
     * Estado de avance de un estudiante en una malla curricular.
     * Devuelve todas las materias con su nota final y si la malla está completa.
     */
    function academico_estado_malla(PDO $pdo, int $id_estudiante, int $id_malla, ?int $id_cohorte = null): array
    {
        $malla = $pdo->prepare("SELECT id_malla, nombre_programa, tipo_oferta, tipo_regimen, total_periodos
                                  FROM public.mallas_curriculares WHERE id_malla = :m LIMIT 1");
        $malla->execute([':m' => $id_malla]);
        $datos_malla = $malla->fetch();

        $sql = "SELECT mm.id_materia, mm.modulo_num, mm.nombre_materia, mm.horas_academicas,
                       pe.id_plan, pe.titulo_evaluacion, pe.porcentaje_peso, pe.fecha_evaluacion,
                       ne.id_nota, ne.nota_cuantitativa, ne.nota_recuperacion, ne.nota_cualitativa
                  FROM public.malla_materias mm
                  LEFT JOIN public.plan_evaluacion pe ON pe.id_materia = mm.id_materia
                  LEFT JOIN public.notas_estudiantes ne
                         ON ne.id_plan = pe.id_plan AND ne.id_estudiante = :est
                 WHERE mm.id_malla = :malla
                 ORDER BY mm.modulo_num ASC, mm.id_materia ASC, pe.id_plan ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':est' => $id_estudiante, ':malla' => $id_malla]);
        $filas = $stmt->fetchAll();

        $por_materia = [];
        foreach ($filas as $f) {
            $idm = (int) $f['id_materia'];
            if (!isset($por_materia[$idm])) {
                $por_materia[$idm] = [
                    'id_materia'       => $idm,
                    'modulo_num'       => (int) $f['modulo_num'],
                    'nombre_materia'   => $f['nombre_materia'],
                    'horas_academicas' => (int) $f['horas_academicas'],
                    'filas'            => [],
                ];
            }
            $por_materia[$idm]['filas'][] = $f;
        }

        $materias = [];
        $total = 0;
        $aprobadas = 0;
        $horas_totales = 0;
        $horas_aprobadas = 0;

        foreach ($por_materia as $m) {
            $calc = academico_calcular_materia($m['filas']);
            $total++;
            $horas_totales += $m['horas_academicas'];
            if ($calc['aprobada']) {
                $aprobadas++;
                $horas_aprobadas += $m['horas_academicas'];
            }

            $estado = 'Pendiente';
            if ($calc['total_eval'] === 0) {
                $estado = 'Sin evaluar';
            } elseif ($calc['aprobada']) {
                $estado = 'Aprobada';
            } else {
                $estado = 'Reprobada';
            }

            $materias[] = [
                'id_materia'       => $m['id_materia'],
                'modulo_num'       => $m['modulo_num'],
                'nombre_materia'   => $m['nombre_materia'],
                'horas_academicas' => $m['horas_academicas'],
                'nota_final'       => $calc['nota_final'],
                'estado'           => $estado,
                'aprobada'         => $calc['aprobada'],
                'evaluaciones'     => $calc['evaluaciones'],
                'total_eval'       => $calc['total_eval'],
                'fecha_ultima'     => $calc['fecha_ultima'],
            ];
        }

        $completado = ($total > 0 && $aprobadas === $total);

        return [
            'id_malla'        => $id_malla,
            'nombre_programa' => $datos_malla['nombre_programa'] ?? 'Programa',
            'tipo_oferta'     => $datos_malla['tipo_oferta'] ?? '',
            'tipo_regimen'    => $datos_malla['tipo_regimen'] ?? '',
            'total_periodos'  => (int) ($datos_malla['total_periodos'] ?? 0),
            'total_materias'  => $total,
            'aprobadas'       => $aprobadas,
            'horas_totales'   => $horas_totales,
            'horas_aprobadas' => $horas_aprobadas,
            'completado'      => $completado,
            'porcentaje'      => $total > 0 ? round(($aprobadas / $total) * 100, 1) : 0.0,
            'materias'        => $materias,
        ];
    }
}

if (!function_exists('academico_inscripcion')) {
    /**
     * Datos completos de una inscripción (estudiante, cohorte y malla).
     */
    function academico_inscripcion(PDO $pdo, int $id_inscripcion): ?array
    {
        $sql = "SELECT i.*, u.nombre, u.apellido, u.cedula, u.fecha_nacimiento, u.sexo,
                       co.codigo_cohorte, co.salon_aula, co.dias_clase, co.num_periodo,
                       co.fecha_inicio, co.fecha_fin_estimada, co.estatus AS estatus_cohorte,
                       m.id_malla, m.nombre_programa, m.tipo_oferta, m.tipo_regimen, m.total_periodos
                  FROM public.inscripciones i
                  JOIN public.usuarios u ON u.id_usuario = i.id_estudiante
                  JOIN public.cohortes co ON co.id_cohorte = i.id_curso
                  JOIN public.mallas_curriculares m ON m.id_malla = co.id_malla
                 WHERE i.id_inscripcion = :id LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':id' => $id_inscripcion]);
        $fila = $stmt->fetch();
        return $fila ?: null;
    }
}

if (!function_exists('academico_estado_inscripcion')) {
    function academico_estado_inscripcion(PDO $pdo, int $id_inscripcion): ?array
    {
        $ins = academico_inscripcion($pdo, $id_inscripcion);
        if (!$ins) {
            return null;
        }
        $estado = academico_estado_malla($pdo, (int) $ins['id_estudiante'], (int) $ins['id_malla']);
        $ins['malla_estado'] = $estado;
        return $ins;
    }
}

if (!function_exists('academico_notas_materias_cohorte')) {
    /**
     * Notas de un estudiante en una cohorte, agrupadas por materia y con la
     * nota final ponderada de cada materia.
     */
    function academico_notas_materias_cohorte(PDO $pdo, int $id_cohorte, int $id_estudiante): array
    {
        $sql = "SELECT mm.id_materia, mm.modulo_num, mm.nombre_materia, mm.horas_academicas,
                       pe.id_plan, pe.titulo_evaluacion, pe.porcentaje_peso, pe.fecha_evaluacion,
                       ne.id_nota, ne.nota_cuantitativa, ne.nota_recuperacion, ne.nota_cualitativa
                  FROM public.plan_evaluacion pe
                  JOIN public.malla_materias mm ON mm.id_materia = pe.id_materia
                  LEFT JOIN public.notas_estudiantes ne
                         ON ne.id_plan = pe.id_plan AND ne.id_estudiante = :est
                 WHERE pe.id_cohorte = :coh
                 ORDER BY mm.modulo_num ASC, mm.id_materia ASC, pe.id_plan ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':est' => $id_estudiante, ':coh' => $id_cohorte]);
        $filas = $stmt->fetchAll();

        $por_materia = [];
        foreach ($filas as $f) {
            $idm = (int) $f['id_materia'];
            if (!isset($por_materia[$idm])) {
                $por_materia[$idm] = [
                    'id_materia'       => $idm,
                    'modulo_num'       => (int) $f['modulo_num'],
                    'nombre_materia'   => $f['nombre_materia'],
                    'horas_academicas' => (int) $f['horas_academicas'],
                    'filas'            => [],
                ];
            }
            $por_materia[$idm]['filas'][] = $f;
        }

        $resultado = [];
        foreach ($por_materia as $m) {
            $calc = academico_calcular_materia($m['filas']);
            $resultado[] = [
                'id_materia'       => $m['id_materia'],
                'modulo_num'       => $m['modulo_num'],
                'nombre_materia'   => $m['nombre_materia'],
                'horas_academicas' => $m['horas_academicas'],
                'evaluaciones'     => $calc['evaluaciones'],
                'nota_final'       => $calc['nota_final'],
                'aprobada'         => $calc['aprobada'],
                'total_eval'       => $calc['total_eval'],
                'suma_pesos'       => $calc['suma_pesos'],
            ];
        }

        return $resultado;
    }
}
