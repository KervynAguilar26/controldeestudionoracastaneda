<?php
// /sistema_nora/includes/contenidos_evaluacion.php
// Contenidos por asignatura y su ponderación dentro de cada evaluación.

if (!function_exists('evaluacion_parsear_contenidos')) {
    /**
     * Convierte el texto con viñetas de malla_materias.contenido_detallado
     * en arreglos [descripcion, horas_academicas, orden].
     */
    function evaluacion_parsear_contenidos(string $texto): array
    {
        $texto = str_replace(["\r\n", "\r"], "\n", $texto);
        $salida = [];
        $orden = 0;
        foreach (preg_split('/\n+/', $texto) as $linea) {
            $linea = trim($linea);
            if ($linea === '') { continue; }

            $horas = 0;
            if (preg_match('/\((\d+)\s*hrs?\)/iu', $linea, $m)) {
                $horas = (int) $m[1];
            }
            $descripcion = preg_replace('/\((\d+)\s*hrs?\)/iu', '', $linea);
            $descripcion = trim($descripcion, " \t\n\r\0\x0B•-*");
            $descripcion = preg_replace('/\s+/', ' ', $descripcion);
            if ($descripcion === '') { continue; }

            $orden++;
            $salida[] = [
                'descripcion'      => $descripcion,
                'horas_academicas' => $horas,
                'orden'            => $orden,
            ];
        }
        return $salida;
    }
}

if (!function_exists('evaluacion_sincronizar_contenidos')) {
    /**
     * Inserta contenidos nuevos de la malla sin borrar los que ya están en uso.
     * Respeta la unicidad (id_materia, orden): si el tema ya existe por
     * descripción se omite; si su orden está ocupado por otro tema se usa el
     * siguiente libre, para que la página nunca quede en blanco por una
     * violación de llave única. Las líneas sin horas declaradas se ignoran:
     * en las mallas de prueba son texto descriptivo del módulo, no temas reales.
     */
    function evaluacion_sincronizar_contenidos(PDO $pdo, int $id_materia): void
    {
        if ($id_materia <= 0) { return; }

        try {
            $stmt = $pdo->prepare("SELECT contenido_detallado FROM public.malla_materias WHERE id_materia = :id LIMIT 1");
            $stmt->execute([':id' => $id_materia]);
            $texto = (string) $stmt->fetchColumn();
            if (trim($texto) === '') { return; }

            $actuales = $pdo->prepare("
                SELECT descripcion, orden FROM public.materia_contenidos WHERE id_materia = :mat
            ");
            $actuales->execute([':mat' => $id_materia]);

            $normalizar = static function ($texto): string {
                $texto = preg_replace('/\s+/u', ' ', trim((string) $texto));
                return function_exists('mb_strtolower')
                    ? mb_strtolower($texto, 'UTF-8')
                    : strtolower($texto);
            };

            $porDescripcion = [];
            $ordenesOcupados = [];
            foreach ($actuales->fetchAll(PDO::FETCH_ASSOC) as $f) {
                $porDescripcion[$normalizar($f['descripcion'])] = true;
                $ordenesOcupados[(int) $f['orden']] = true;
            }

            $ins = $pdo->prepare("
                INSERT INTO public.materia_contenidos (id_materia, descripcion, horas_academicas, orden)
                VALUES (:mat, :desc, :hor, :ord)
            ");
            foreach (evaluacion_parsear_contenidos($texto) as $c) {
                if ((int) $c['horas_academicas'] <= 0) { continue; }
                $clave = $normalizar($c['descripcion']);
                if (isset($porDescripcion[$clave])) { continue; }

                $orden = max(1, (int) $c['orden']);
                while (isset($ordenesOcupados[$orden])) { $orden++; }

                $ins->execute([
                    ':mat'  => $id_materia,
                    ':desc' => $c['descripcion'],
                    ':hor'  => $c['horas_academicas'],
                    ':ord'  => $orden,
                ]);
                $porDescripcion[$clave] = true;
                $ordenesOcupados[$orden] = true;
            }
        } catch (Throwable $e) {
            // La sincronización es auxiliar: un problema aquí no debe impedir
            // que el docente abra su plan de evaluación.
        }
    }
}

if (!function_exists('evaluacion_contenidos_materia')) {
    /** Todos los contenidos disponibles de una asignatura. */
    function evaluacion_contenidos_materia(PDO $pdo, int $id_materia): array
    {
        evaluacion_sincronizar_contenidos($pdo, $id_materia);
        $stmt = $pdo->prepare("
            SELECT id_contenido, descripcion, horas_academicas, orden
              FROM public.materia_contenidos
             WHERE id_materia = :mat
             ORDER BY orden ASC, id_contenido ASC
        ");
        $stmt->execute([':mat' => $id_materia]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('evaluacion_contenidos_plan')) {
    /** Contenidos marcados en una evaluación (sin ponderación por contenido). */
    function evaluacion_contenidos_plan(PDO $pdo, int $id_plan): array
    {
        $stmt = $pdo->prepare("
            SELECT mc.id_contenido, mc.descripcion, mc.horas_academicas, mc.orden
              FROM public.plan_evaluacion_contenidos epc
              JOIN public.materia_contenidos mc ON mc.id_contenido = epc.id_contenido
             WHERE epc.id_plan = :plan
             ORDER BY mc.orden ASC, mc.id_contenido ASC
        ");
        $stmt->execute([':plan' => $id_plan]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('evaluacion_guardar_contenidos')) {
    /** Reemplaza la relación de contenidos de un plan (se llama dentro de una transacción). */
    function evaluacion_guardar_contenidos(PDO $pdo, int $id_plan, array $contenidos): void
    {
        $pdo->prepare("DELETE FROM public.plan_evaluacion_contenidos WHERE id_plan = :plan")
            ->execute([':plan' => $id_plan]);

        $ins = $pdo->prepare("
            INSERT INTO public.plan_evaluacion_contenidos (id_plan, id_contenido)
            VALUES (:plan, :con)
        ");
        foreach ($contenidos as $c) {
            $ins->execute([
                ':plan' => $id_plan,
                ':con'  => (int) $c['id_contenido'],
            ]);
        }
    }
}

if (!function_exists('evaluacion_nombre_profesor')) {
    /** Nombre del docente responsable: del plan si lo tiene, o de la asignación aceptada. */
    function evaluacion_nombre_profesor(PDO $pdo, int $id_plan, int $id_materia, int $id_cohorte): string
    {
        $stmt = $pdo->prepare("
            SELECT trim(u.nombre || ' ' || u.apellido) AS nombre
              FROM public.plan_evaluacion pe
              JOIN public.usuarios u ON u.id_usuario = pe.id_profesor
             WHERE pe.id_plan = :plan AND pe.id_profesor IS NOT NULL
             LIMIT 1
        ");
        $stmt->execute([':plan' => $id_plan]);
        $nombre = trim((string) $stmt->fetchColumn());
        if ($nombre !== '') { return $nombre; }

        $stmt = $pdo->prepare("
            SELECT trim(u.nombre || ' ' || u.apellido) AS nombre
              FROM public.asignaciones_profesor ap
              JOIN public.usuarios u ON u.id_usuario = ap.id_profesor
             WHERE ap.id_materia = :mat
               AND ap.estatus_aceptacion = 'Aceptado'
               AND (ap.id_cohorte = :coh OR ap.id_cohorte IS NULL)
             ORDER BY (ap.id_cohorte IS NULL) ASC, ap.id_asignacion DESC
             LIMIT 1
        ");
        $stmt->execute([':mat' => $id_materia, ':coh' => $id_cohorte]);
        return trim((string) $stmt->fetchColumn()) ?: 'Docente por asignar';
    }
}
