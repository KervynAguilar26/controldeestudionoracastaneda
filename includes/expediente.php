<?php
// /sistema_nora/includes/expediente.php
// Expediente académico de un estudiante: todas sus inscripciones (cursos,
// seminarios, foros o conversatorios registrados como eventos), notas
// certificadas por malla y certificados emitidos.

require_once __DIR__ . '/academico.php';

if (!function_exists('expediente_datos')) {
    function expediente_datos(PDO $pdo, int $id_estudiante): ?array
    {
        $st = $pdo->prepare("SELECT id_usuario, cedula, nombre, apellido, correo, email, telefono,
                                    tipo_usuario, estatus_cuenta, roles_asignados
                               FROM public.usuarios WHERE id_usuario = :id LIMIT 1");
        $st->execute([':id' => $id_estudiante]);
        $estudiante = $st->fetch();
        if (!$estudiante) { return null; }

        $st = $pdo->prepare("SELECT i.id_inscripcion, i.id_curso, i.fecha_inscripcion, i.nota_cualitativa,
                                    co.codigo_cohorte, co.num_periodo, co.fecha_inicio, co.fecha_fin_estimada,
                                    co.estatus AS estatus_cohorte, co.id_malla,
                                    m.nombre_programa, m.tipo_oferta, m.tipo_regimen, m.total_periodos
                               FROM public.inscripciones i
                               JOIN public.cohortes co ON co.id_cohorte = i.id_curso
                               JOIN public.mallas_curriculares m ON m.id_malla = co.id_malla
                              WHERE i.id_estudiante = :id
                              ORDER BY co.fecha_inicio DESC NULLS LAST, i.id_inscripcion DESC");
        $st->execute([':id' => $id_estudiante]);
        $inscripciones = $st->fetchAll();

        $cache = [];
        foreach ($inscripciones as &$ins) {
            $idMalla = (int) $ins['id_malla'];
            if (!isset($cache[$idMalla])) {
                $cache[$idMalla] = academico_estado_malla($pdo, $id_estudiante, $idMalla, (int) $ins['id_curso']);
            }
            $ins['malla_estado'] = $cache[$idMalla];
        }
        unset($ins);

        $st = $pdo->prepare("SELECT ce.id_certificado, ce.id_inscripcion, ce.folio, ce.libro,
                                    ce.linea_libro, ce.codigo_validacion, ce.fecha_emision,
                                    ce.tipo, ce.estado, ce.origen, ce.id_foro,
                                    m.nombre_programa, co.codigo_cohorte,
                                    f.nombre AS foro_nombre, f.codigo_foro, f.tipo_foro,
                                    fc.id_certificado_foro, fc.rol_certificado
                               FROM public.certificados_emitidos ce
                               LEFT JOIN public.mallas_curriculares m ON m.id_malla = ce.id_malla
                               LEFT JOIN public.inscripciones i ON i.id_inscripcion = ce.id_inscripcion
                               LEFT JOIN public.cohortes co ON co.id_cohorte = i.id_curso
                               LEFT JOIN public.foros_eventos f ON f.id_foro = ce.id_foro
                               LEFT JOIN public.foro_certificados fc ON fc.id_certificado_emitido = ce.id_certificado
                              WHERE ce.id_estudiante = :id
                              ORDER BY ce.linea_libro ASC, ce.id_certificado ASC");
        $st->execute([':id' => $id_estudiante]);
        $certificados = $st->fetchAll();

        return [
            'estudiante'   => $estudiante,
            'inscripciones' => $inscripciones,
            'certificados' => $certificados,
        ];
    }
}

if (!function_exists('expediente_foros')) {
    /**
     * Historial de un estudiante en Foros, Conversatorios y Seminarios: participación,
     * asistencias, mesas, informes y certificados. Todo queda guardado aquí
     * porque forma parte de su record académico.
     */
    function expediente_foros(PDO $pdo, int $id_estudiante): array
    {
        $st = $pdo->prepare("SELECT f.id_foro, f.codigo_foro, f.nombre, f.tipo_foro,
                                   f.fecha_inicio, f.fecha_fin,
                                   p.id_participante, p.estatus AS estatus_participante,
                                   (SELECT string_agg(a.tipo_asistencia, ', ' ORDER BY a.tipo_asistencia)
                                      FROM public.foro_asistencias a
                                     WHERE a.id_participante = p.id_participante) AS asistencias,
                                   (SELECT string_agg('Mesa ' || m.numero_mesa, ', ' ORDER BY m.numero_mesa)
                                      FROM public.foro_mesa_participantes mp
                                      JOIN public.foro_mesas m ON m.id_mesa = mp.id_mesa
                                     WHERE mp.id_participante = p.id_participante) AS mesas,
                                   (SELECT COUNT(*) FROM public.foro_informes i
                                     WHERE i.id_participante = p.id_participante
                                       AND i.estatus = 'Enviado') AS informes_enviados,
                                   (SELECT COALESCE(SUM(t.horas_academicas), 0)
                                      FROM public.foro_temas t WHERE t.id_foro = f.id_foro) AS horas,
                                   fc.rol_certificado, ce.folio AS folio_certificado,
                                   fc.id_certificado_foro
                              FROM public.foro_participantes p
                              JOIN public.foros_eventos f ON f.id_foro = p.id_foro
                              LEFT JOIN public.foro_certificados fc ON fc.id_participante = p.id_participante
                              LEFT JOIN public.certificados_emitidos ce ON ce.id_certificado = fc.id_certificado_emitido
                             WHERE p.id_estudiante = :id
                             ORDER BY f.fecha_inicio DESC NULLS LAST, f.id_foro DESC");
        $st->execute([':id' => $id_estudiante]);
        return $st->fetchAll();
    }
}

if (!function_exists('expediente_nombre')) {
    function expediente_nombre(array $estudiante): string
    {
        return trim((string) $estudiante['nombre'] . ' ' . (string) $estudiante['apellido']);
    }
}
