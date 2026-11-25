<?php
// /sistema_nora/includes/permisos_docente.php
// Reglas compartidas de propiedad docente para el módulo PROFESOR.
//
// La fuente de verdad es public.asignaciones_profesor:
//   - id_cohorte = NULL  => "todas las secciones" de la malla a la que
//                          pertenece la materia (mm.id_malla = co.id_malla).
//   - id_cohorte = N     => únicamente esa cohorte.
// Solo se consideran asignaciones con estatus_aceptacion = 'Aceptado'.
//
// Los roles administrativos (admin, superadmin y control_estudio) conservan
// el override total que ya otorga tiene_permiso() en includes/header.php.
// En docente_materias_asignadas(), de forma simple y documentada, ese override
// lista TODAS las asignaciones aceptadas del sistema (no filtra por
// id_profesor); el docente usual solo ve las suyas.

if (!function_exists('docente_rol_con_override')) {
    /**
     * true cuando el rol en sesión tiene privilegios administrativos.
     * Se lee $roles_array (global que prepara includes/header.php) y se
     * respeta el mismo criterio de override de tiene_permiso().
     */
    function docente_rol_con_override(): bool
    {
        global $roles_array;
        $roles = is_array($roles_array) ? $roles_array : [];
        if (function_exists('tiene_permiso')) {
            return tiene_permiso(['admin', 'superadmin', 'control_estudio'], $roles);
        }
        foreach (['admin', 'superadmin', 'control_estudio'] as $rol) {
            if (in_array($rol, $roles, true)) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('docente_puede_gestionar')) {
    /**
     * ¿Puede este usuario gestionar (materia, cohorte)?
     * Administración/control_estudio pasan sin consultar; el resto debe tener
     * una asignación ACEPTADA para la materia, bien a esa cohorte o bien a
     * "todas las secciones" (id_cohorte IS NULL), con coherencia de malla.
     */
    function docente_puede_gestionar(PDO $pdo, int $id_profesor, int $id_materia, int $id_cohorte): bool
    {
        if ($id_profesor <= 0 || $id_materia <= 0 || $id_cohorte <= 0) {
            return false;
        }
        if (docente_rol_con_override()) {
            return true;
        }

        try {
            $sql = "
                SELECT 1
                  FROM public.asignaciones_profesor ap
                  JOIN public.malla_materias mm ON mm.id_materia = ap.id_materia
                  JOIN public.cohortes co
                    ON co.id_cohorte = :coh1 AND co.id_malla = mm.id_malla
                 WHERE ap.id_profesor = :prof
                   AND ap.id_materia = :mat
                   AND ap.estatus_aceptacion = 'Aceptado'
                   AND (ap.id_cohorte = :coh2 OR ap.id_cohorte IS NULL)
                 LIMIT 1
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':prof' => $id_profesor,
                ':mat'  => $id_materia,
                ':coh1' => $id_cohorte,
                ':coh2' => $id_cohorte,
            ]);
            return (bool) $stmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('docente_materias_asignadas')) {
    /**
     * Cargas académicas aceptadas listas para el portal docente.
     *
     * Una asignación general (ap.id_cohorte IS NULL) se expande a una fila
     * por cada cohorte de su malla mediante un LEFT JOIN condicional, de modo
     * que el docente pueda entrar a plan de evaluación / notas / sábana de
     * cada sección real. Se devuelve además:
     *   - id_cohorte_asignacion: valor original de la asignación (NULL = general).
     *   - es_todas_secciones: 1 si la asignación era general, 0 en caso contrario.
     * Si la malla aún no tiene cohortes, se devuelve una única fila con
     * id_cohorte NULL para poder mostrar la etiqueta correspondiente.
     */
    function docente_materias_asignadas(PDO $pdo, int $id_profesor): array
    {
        $params = [];
        $where  = ["ap.estatus_aceptacion = 'Aceptado'"];

        // Override administrativo: se listan todas las asignaciones aceptadas.
        if (!docente_rol_con_override()) {
            $where[] = 'ap.id_profesor = :prof';
            $params[':prof'] = $id_profesor;
        }

        $sql = "
            SELECT ap.id_asignacion,
                   ap.id_materia,
                   ap.id_cohorte AS id_cohorte_asignacion,
                   COALESCE(co.id_cohorte, ap.id_cohorte) AS id_cohorte,
                   CASE WHEN ap.id_cohorte IS NULL THEN 1 ELSE 0 END AS es_todas_secciones,
                   mm.modulo_num,
                   mm.nombre_materia,
                   mm.contenido_detallado,
                   mm.horas_academicas,
                   mm.id_malla,
                   mc.nombre_programa,
                   mc.tipo_oferta,
                   co.codigo_cohorte,
                   co.num_periodo,
                   co.salon_aula,
                   co.dias_clase,
                   co.hora_inicio,
                   co.hora_fin,
                   ap.estatus_aceptacion,
                   ap.justificacion_asignacion,
                   u.nombre AS nombre_profesor,
                   u.apellido AS apellido_profesor
              FROM public.asignaciones_profesor ap
              JOIN public.malla_materias mm ON mm.id_materia = ap.id_materia
              JOIN public.mallas_curriculares mc ON mc.id_malla = mm.id_malla
              LEFT JOIN public.usuarios u ON u.id_usuario = ap.id_profesor
              LEFT JOIN public.cohortes co
                     ON (ap.id_cohorte IS NOT NULL AND co.id_cohorte = ap.id_cohorte)
                     OR (ap.id_cohorte IS NULL AND co.id_malla = mm.id_malla)
             WHERE " . implode(' AND ', $where) . "
             ORDER BY mc.nombre_programa ASC, mm.modulo_num ASC,
                      mm.nombre_materia ASC, co.codigo_cohorte ASC,
                      ap.id_asignacion ASC
        ";

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('docente_plan_pertenece')) {
    /**
     * Verifica que un plan de evaluación pertenezca exactamente a la
     * materia/cohorte que se está gestionando (evita id_plan foráneos).
     */
    function docente_plan_pertenece(PDO $pdo, int $id_plan, int $id_materia, int $id_cohorte): bool
    {
        if ($id_plan <= 0 || $id_materia <= 0 || $id_cohorte <= 0) {
            return false;
        }
        try {
            $stmt = $pdo->prepare("
                SELECT 1
                  FROM public.plan_evaluacion pe
                  JOIN public.malla_materias mm ON mm.id_materia = pe.id_materia
                  JOIN public.cohortes co ON co.id_cohorte = pe.id_cohorte AND co.id_malla = mm.id_malla
                 WHERE pe.id_plan = :plan
                   AND pe.id_materia = :mat
                   AND pe.id_cohorte = :coh
                 LIMIT 1
            ");
            $stmt->execute([':plan' => $id_plan, ':mat' => $id_materia, ':coh' => $id_cohorte]);
            return (bool) $stmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('docente_opciones_cualitativa')) {
    /** Catálogo controlado de calificaciones cualitativas. */
    function docente_opciones_cualitativa(): array
    {
        return ['Excelente', 'Muy Bueno', 'Bueno', 'Aprobado', 'Regular', 'Aplazado'];
    }
}

if (!function_exists('docente_nota_cualitativa_a_numerica')) {
    /**
     * Equivalencia numérica (escala 1-20) de una calificación cualitativa.
     * Se guarda junto a la etiqueta para que la fórmula ponderada de notas
     * (academico_calcular_materia / boletas) siga siendo consistente.
     */
    function docente_nota_cualitativa_a_numerica(string $etiqueta): ?float
    {
        $map = [
            'Excelente' => 20.0,
            'Muy Bueno' => 17.0,
            'Bueno'     => 14.0,
            'Aprobado'  => 11.0,
            'Regular'   => 10.0,
            'Aplazado'  => 5.0,
        ];
        return $map[$etiqueta] ?? null;
    }
}

if (!function_exists('docente_registrar_bitacora')) {
    /** Auditoría no crítica del módulo PROFESOR (nunca rompe la operación). */
    function docente_registrar_bitacora(PDO $pdo, string $accion): void
    {
        try {
            $pdo->prepare("
                INSERT INTO public.bitacora (id_usuario, modulo, accion, ip_maquina)
                VALUES (:u, 'PROFESOR', :a, :ip)
            ")->execute([
                ':u'  => $_SESSION['id_usuario'] ?? null,
                ':a'  => $accion,
                ':ip' => !empty($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'local-cli',
            ]);
        } catch (Throwable $e) {
            // La bitácora es auxiliar: no debe afectar la gestión académica.
        }
    }
}
