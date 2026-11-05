<?php
// /sistema_nora/modulos/reportes/informes_movimiento.php
// Informe consolidado de movimientos de la escuela: matrículas, asistencias
// (clases, comedor, guardería), inventario del comedor, préstamos de misiones,
// defensoría, certificados, notas, usuarios, horarios y bitácora.
// Incluye descarga del informe completo en PDF (dompdf).

require_once '../../config/db.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['id_usuario'])) {
    header('Location: ../../index.php');
    exit();
}

$roles_usuario = $_SESSION['roles_asignados'] ?? [$_SESSION['tipo_usuario'] ?? 'trabajador'];

// Función local de permisos (no depende del header para poder generar el PDF sin HTML)
function im_tiene_permiso(array $permitidos, array $roles): bool
{
    if (in_array('superadmin', $roles, true) || in_array('admin', $roles, true)) {
        return true;
    }
    return !empty(array_intersect($permitidos, $roles));
}

if (!im_tiene_permiso(['control_estudio'], $roles_usuario)) {
    if (($_GET['pdf'] ?? '') === '1') {
        http_response_code(403);
        exit('Acceso restringido. Solo Administradores y Control de Estudio pueden generar el informe.');
    }
    require_once '../../includes/header.php';
    echo "<div class='alert alert-danger font-weight-bold m-4'><i class='fas fa-ban mr-2'></i>Acceso restringido. Solo Administradores y Control de Estudio pueden consultar el informe de movimientos.</div>";
    require_once '../../includes/footer.php';
    exit();
}

// ---------------------------------------------------------------------------
// Filtro de fechas (solo se aceptan fechas con formato YYYY-MM-DD estricto)
// ---------------------------------------------------------------------------
function im_fecha(?string $valor): ?string
{
    $valor = trim((string) $valor);
    if ($valor === '') {
        return null;
    }
    $fecha = DateTime::createFromFormat('Y-m-d', $valor);
    return ($fecha && $fecha->format('Y-m-d') === $valor) ? $valor : null;
}

$desde = im_fecha($_GET['desde'] ?? null);
$hasta = im_fecha($_GET['hasta'] ?? null);
if ($desde !== null && $hasta !== null && $desde > $hasta) {
    [$desde, $hasta] = [$hasta, $desde];
}
$es_pdf = ($_GET['pdf'] ?? '') === '1';

// Condición de rango sobre una columna de fecha (fechas ya validadas arriba)
function im_cond(string $col, ?string $desde, ?string $hasta): string
{
    $sql = "$col IS NOT NULL";
    if ($desde !== null) {
        $sql .= " AND $col >= DATE '$desde'";
    }
    if ($hasta !== null) {
        $sql .= " AND $col < DATE '$hasta' + INTERVAL '1 day'";
    }
    return $sql;
}

// ---------------------------------------------------------------------------
// Recolección de datos (usada por la vista HTML y por el PDF para que ambos
// informes muestren exactamente lo mismo)
// ---------------------------------------------------------------------------
function im_datos(PDO $pdo, ?string $desde, ?string $hasta): array
{
    $cnt = function (string $sql) use ($pdo): int {
        try {
            return (int) $pdo->query($sql)->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    };
    $filas = function (string $sql) use ($pdo): array {
        try {
            return $pdo->query($sql)->fetchAll();
        } catch (Throwable $e) {
            return [];
        }
    };

    // Tablas con columna de fecha que alimentan el resumen general
    $tablas_fecha = [
        ['public.inscripciones', 'fecha_inscripcion'],
        ['public.asistencia_clases', 'fecha_clase'],
        ['public.comedor_asistencia', 'fecha_registro'],
        ['public.comedor_movimientos', 'fecha_movimiento'],
        ['public.guarderia_asistencia', 'fecha_asistencia'],
        ['public.prestamo_misiones', 'fecha_inicio'],
        ['public.defensoria_casos', 'fecha_registro'],
        ['public.defensoria_seguimiento', 'fecha_atencion'],
        ['public.certificados_emitidos', 'fecha_emision'],
        ['public.usuarios', 'fecha_registro'],
        ['public.horarios_clases', 'fecha_generacion'],
        ['public.dormitorio_pernoctas', 'fecha_entrada'],
        ['public.dormitorio_prorrogas', 'fecha_registro'],
        ['public.bitacora', 'fecha_hora'],
    ];

    $resumen = ['total' => 0, 'hoy' => 0, 'mes' => 0];
    foreach ($tablas_fecha as [$tabla, $col]) {
        $resumen['total'] += $cnt("SELECT COUNT(*) FROM $tabla WHERE " . im_cond($col, $desde, $hasta));
        $resumen['hoy']   += $cnt("SELECT COUNT(*) FROM $tabla WHERE $col >= CURRENT_DATE AND $col < CURRENT_DATE + INTERVAL '1 day'");
        $resumen['mes']   += $cnt("SELECT COUNT(*) FROM $tabla WHERE $col >= date_trunc('month', CURRENT_DATE) AND $col < CURRENT_DATE + INTERVAL '1 day'");
    }

    $bloques = [];

    // 1. MATRÍCULAS / INSCRIPCIONES -----------------------------------------
    $Wi = im_cond('i.fecha_inscripcion', $desde, $hasta);
    $bloques[] = [
        'titulo'  => 'Matrículas e Inscripciones',
        'icono'   => 'fa-user-plus',
        'kpis'    => [
            ['Inscripciones en el rango', $cnt("SELECT COUNT(*) FROM public.inscripciones i WHERE $Wi")],
            ['Este mes', $cnt("SELECT COUNT(*) FROM public.inscripciones i WHERE i.fecha_inscripcion >= date_trunc('month', CURRENT_DATE)")],
            ['Hoy', $cnt("SELECT COUNT(*) FROM public.inscripciones i WHERE i.fecha_inscripcion >= CURRENT_DATE AND i.fecha_inscripcion < CURRENT_DATE + INTERVAL '1 day'")],
            ['Estudiantes únicos', $cnt("SELECT COUNT(DISTINCT i.id_estudiante) FROM public.inscripciones i WHERE $Wi")],
        ],
        'dist'    => ['titulo' => 'Inscripciones por mes', 'cols' => ['Mes', 'Inscripciones'],
            'filas' => array_map(fn($f) => [$f['periodo'], (int) $f['total']],
                $filas("SELECT to_char(i.fecha_inscripcion, 'YYYY-MM') periodo, COUNT(*) total
                          FROM public.inscripciones i WHERE $Wi GROUP BY 1 ORDER BY 1 DESC LIMIT 12"))],
        'ultimos' => ['titulo' => 'Últimas inscripciones registradas', 'cols' => ['Fecha', 'Estudiante', 'Cédula', 'Cohorte'],
            'filas' => array_map(fn($f) => [
                substr($f['fecha_inscripcion'], 0, 16),
                $f['estudiante'],
                $f['cedula'],
                $f['cohorte'],
            ], $filas("SELECT i.fecha_inscripcion,
                              COALESCE(NULLIF(TRIM(u.nombre || ' ' || u.apellido), ''), 'Estudiante') estudiante,
                              COALESCE(u.cedula, '—') cedula,
                              COALESCE(c.codigo_cohorte, '—') cohorte
                         FROM public.inscripciones i
                         LEFT JOIN public.usuarios u ON u.id_usuario = i.id_estudiante
                         LEFT JOIN public.cohortes c ON c.id_cohorte = i.id_curso
                        WHERE $Wi ORDER BY i.fecha_inscripcion DESC LIMIT 8"))],
    ];

    // 2. ASISTENCIA A CLASES -------------------------------------------------
    $Wa = im_cond('a.fecha_clase', $desde, $hasta);
    $bloques[] = [
        'titulo'  => 'Asistencia a Clases (Aulas)',
        'icono'   => 'fa-chalkboard-teacher',
        'kpis'    => [
            ['Registros en el rango', $cnt("SELECT COUNT(*) FROM public.asistencia_clases a WHERE $Wa")],
            ['Presentes', $cnt("SELECT COUNT(*) FROM public.asistencia_clases a WHERE $Wa AND a.estatus = 'Presente'")],
            ['Faltas / Ausencias', $cnt("SELECT COUNT(*) FROM public.asistencia_clases a WHERE $Wa AND COALESCE(a.estatus, '') <> 'Presente'")],
            ['Días con clase', $cnt("SELECT COUNT(DISTINCT a.fecha_clase) FROM public.asistencia_clases a WHERE $Wa")],
        ],
        'dist'    => ['titulo' => 'Distribución por estatus', 'cols' => ['Estatus', 'Registros'],
            'filas' => array_map(fn($f) => [$f['estatus'], (int) $f['total']],
                $filas("SELECT COALESCE(a.estatus, 'Sin registrar') estatus, COUNT(*) total
                          FROM public.asistencia_clases a WHERE $Wa GROUP BY 1 ORDER BY 2 DESC LIMIT 10"))],
        'ultimos' => ['titulo' => 'Últimos registros de asistencia', 'cols' => ['Fecha', 'Estudiante', 'Cohorte', 'Estatus'],
            'filas' => array_map(fn($f) => [
                $f['fecha_clase'],
                $f['estudiante'],
                $f['cohorte'],
                $f['estatus'] ?? 'Sin registrar',
            ], $filas("SELECT a.fecha_clase,
                              COALESCE(NULLIF(TRIM(u.nombre || ' ' || u.apellido), ''), 'Estudiante #' || a.id_estudiante) estudiante,
                              COALESCE(c.codigo_cohorte, '—') cohorte,
                              a.estatus
                         FROM public.asistencia_clases a
                         LEFT JOIN public.usuarios u ON u.id_usuario = a.id_estudiante
                         LEFT JOIN public.cohortes c ON c.id_cohorte = a.id_cohorte
                        WHERE $Wa ORDER BY a.fecha_clase DESC, a.id_asistencia_clase DESC LIMIT 8"))],
    ];

    // 3. COMEDOR (asistencia) ------------------------------------------------
    $Wc = im_cond('ca.fecha_registro', $desde, $hasta);
    $bloques[] = [
        'titulo'  => 'Comedor Escolar (Asistencia a alimentos)',
        'icono'   => 'fa-utensils',
        'kpis'    => [
            ['Raciones en el rango', $cnt("SELECT COUNT(*) FROM public.comedor_asistencia ca WHERE $Wc")],
            ['Este mes', $cnt("SELECT COUNT(*) FROM public.comedor_asistencia ca WHERE ca.fecha_registro >= date_trunc('month', CURRENT_DATE)")],
            ['Hoy', $cnt("SELECT COUNT(*) FROM public.comedor_asistencia ca WHERE ca.fecha_registro >= CURRENT_DATE AND ca.fecha_registro < CURRENT_DATE + INTERVAL '1 day'")],
            ['Beneficiarios únicos', $cnt("SELECT COUNT(DISTINCT ca.id_usuario) FROM public.comedor_asistencia ca WHERE $Wc")],
        ],
        'dist'    => ['titulo' => 'Distribución por turno', 'cols' => ['Turno', 'Registros'],
            'filas' => array_map(fn($f) => [$f['turno'], (int) $f['total']],
                $filas("SELECT COALESCE(NULLIF(ca.turno, ''), 'Sin turno') turno, COUNT(*) total
                          FROM public.comedor_asistencia ca WHERE $Wc GROUP BY 1 ORDER BY 2 DESC LIMIT 10"))],
        'ultimos' => ['titulo' => 'Últimos registros de comedor', 'cols' => ['Fecha', 'Hora', 'Beneficiario', 'Turno'],
            'filas' => array_map(fn($f) => [
                $f['fecha_registro'],
                $f['hora_registro'],
                $f['beneficiario'],
                $f['turno'] ?? 'Sin turno',
            ], $filas("SELECT ca.fecha_registro, COALESCE(ca.hora_registro::text, '—') hora_registro,
                              COALESCE(NULLIF(TRIM(u.nombre || ' ' || u.apellido), ''), 'Usuario #' || ca.id_usuario) beneficiario,
                              ca.turno
                         FROM public.comedor_asistencia ca
                         LEFT JOIN public.usuarios u ON u.id_usuario = ca.id_usuario
                        WHERE $Wc ORDER BY ca.fecha_registro DESC, ca.id_asistencia DESC LIMIT 8"))],
    ];

    // 4. COMEDOR (movimientos de inventario) --------------------------------
    $Wm = im_cond('cm.fecha_movimiento', $desde, $hasta);
    $bloques[] = [
        'titulo'  => 'Movimientos de Inventario del Comedor',
        'icono'   => 'fa-boxes',
        'kpis'    => [
            ['Movimientos en el rango', $cnt("SELECT COUNT(*) FROM public.comedor_movimientos cm WHERE $Wm")],
            ['Entradas de producto', $cnt("SELECT COUNT(*) FROM public.comedor_movimientos cm WHERE $Wm AND cm.tipo_movimiento = 'Entrada'")],
            ['Salidas de producto', $cnt("SELECT COUNT(*) FROM public.comedor_movimientos cm WHERE $Wm AND cm.tipo_movimiento = 'Salida'")],
            ['Unidades movilizadas', $cnt("SELECT COALESCE(SUM(cm.cantidad), 0) FROM public.comedor_movimientos cm WHERE $Wm")],
        ],
        'dist'    => ['titulo' => 'Distribución por tipo de movimiento', 'cols' => ['Tipo', 'Movimientos'],
            'filas' => array_map(fn($f) => [$f['tipo'], (int) $f['total']],
                $filas("SELECT COALESCE(cm.tipo_movimiento, 'Sin clasificar') tipo, COUNT(*) total
                          FROM public.comedor_movimientos cm WHERE $Wm GROUP BY 1 ORDER BY 2 DESC LIMIT 10"))],
        'ultimos' => ['titulo' => 'Últimos movimientos de inventario', 'cols' => ['Fecha', 'Producto', 'Tipo', 'Cantidad'],
            'filas' => array_map(fn($f) => [
                substr($f['fecha_movimiento'], 0, 16),
                $f['producto'],
                $f['tipo'] ?? 'Sin clasificar',
                $f['cantidad'],
            ], $filas("SELECT cm.fecha_movimiento,
                              COALESCE(ci.nombre_producto, 'Producto #' || cm.id_producto) producto,
                              cm.tipo_movimiento, cm.cantidad
                         FROM public.comedor_movimientos cm
                         LEFT JOIN public.comedor_inventario ci ON ci.id_producto = cm.id_producto
                        WHERE $Wm ORDER BY cm.fecha_movimiento DESC, cm.id_movimiento DESC LIMIT 8"))],
    ];

    // 5. CENTRO DE CUIDADO INFANTIL (GUARDERÍA) ------------------------------
    $Wg = im_cond('ga.fecha_asistencia', $desde, $hasta);
    $bloques[] = [
        'titulo'  => 'Centro de Cuidado Infantil (Guardería)',
        'icono'   => 'fa-baby',
        'kpis'    => [
            ['Asistencias en el rango', $cnt("SELECT COUNT(*) FROM public.guarderia_asistencia ga WHERE $Wg")],
            ['Hoy', $cnt("SELECT COUNT(*) FROM public.guarderia_asistencia ga WHERE ga.fecha_asistencia = CURRENT_DATE")],
            ['Retirados hoy', $cnt("SELECT COUNT(*) FROM public.guarderia_asistencia ga WHERE ga.fecha_asistencia = CURRENT_DATE AND ga.estatus = 'Retirado'")],
            ['Niños atendidos únicos', $cnt("SELECT COUNT(DISTINCT ga.id_nino) FROM public.guarderia_asistencia ga WHERE $Wg")],
        ],
        'dist'    => ['titulo' => 'Distribución por estatus', 'cols' => ['Estatus', 'Registros'],
            'filas' => array_map(fn($f) => [$f['estatus'], (int) $f['total']],
                $filas("SELECT COALESCE(ga.estatus, 'Sin registrar') estatus, COUNT(*) total
                          FROM public.guarderia_asistencia ga WHERE $Wg GROUP BY 1 ORDER BY 2 DESC LIMIT 10"))],
        'ultimos' => ['titulo' => 'Últimos registros de guardería', 'cols' => ['Fecha', 'Niño(a)', 'Entrada', 'Salida', 'Estatus'],
            'filas' => array_map(fn($f) => [
                $f['fecha_asistencia'],
                $f['nino'],
                $f['hora_entrada'],
                $f['hora_salida'],
                $f['estatus'] ?? 'Sin registrar',
            ], $filas("SELECT ga.fecha_asistencia,
                              COALESCE(NULLIF(TRIM(gn.nombre_nino || ' ' || gn.apellido_nino), ''), 'Niño #' || ga.id_nino) nino,
                              COALESCE(ga.hora_entrada::text, '—') hora_entrada,
                              COALESCE(ga.hora_salida::text, '—') hora_salida,
                              ga.estatus
                         FROM public.guarderia_asistencia ga
                         LEFT JOIN public.guarderia_ninos gn ON gn.id_nino = ga.id_nino
                        WHERE $Wg ORDER BY ga.fecha_asistencia DESC, ga.id_asistencia DESC LIMIT 8"))],
    ];

    // 6. PRÉSTAMOS DE MISIONES ----------------------------------------------
    $Wp = im_cond('fecha_inicio', $desde, $hasta);
    $bloques[] = [
        'titulo'  => 'Préstamos de Misiones Educativas',
        'icono'   => 'fa-handshake',
        'kpis'    => [
            ['Préstamos en el rango', $cnt("SELECT COUNT(*) FROM public.prestamo_misiones WHERE $Wp")],
            ['Estudiantes cubiertos', $cnt("SELECT COALESCE(SUM(cantidad_estudiantes), 0) FROM public.prestamo_misiones WHERE $Wp")],
            ['Facilitadores', $cnt("SELECT COUNT(DISTINCT cedula_facilitador) FROM public.prestamo_misiones WHERE $Wp")],
            ['En curso hoy', $cnt("SELECT COUNT(*) FROM public.prestamo_misiones WHERE $Wp AND fecha_inicio <= CURRENT_DATE AND (fecha_fin IS NULL OR fecha_fin >= CURRENT_DATE)")],
        ],
        'dist'    => ['titulo' => 'Distribución por período académico', 'cols' => ['Período', 'Préstamos'],
            'filas' => array_map(fn($f) => [$f['periodo'], (int) $f['total']],
                $filas("SELECT COALESCE(NULLIF(periodo_academico, ''), 'Sin período') periodo, COUNT(*) total
                          FROM public.prestamo_misiones WHERE $Wp GROUP BY 1 ORDER BY 2 DESC LIMIT 10"))],
        'ultimos' => ['titulo' => 'Últimos préstamos registrados', 'cols' => ['Misión', 'Facilitador', 'Estudiantes', 'Salón', 'Inicio'],
            'filas' => array_map(fn($f) => [
                $f['mision'],
                $f['facilitador'],
                (int) $f['estudiantes'],
                $f['salon'],
                $f['fecha_inicio'],
            ], $filas("SELECT nombre_mision mision,
                              COALESCE(NULLIF(TRIM(facilitador_responsable), ''), '—') facilitador,
                              COALESCE(cantidad_estudiantes, 0) estudiantes,
                              COALESCE(NULLIF(salon_asignado, ''), '—') salon,
                              fecha_inicio
                         FROM public.prestamo_misiones WHERE $Wp
                        ORDER BY fecha_inicio DESC, id_prestamo DESC LIMIT 8"))],
    ];

    // 7. DEFENSORÍA (CASOS) --------------------------------------------------
    $Wdc = im_cond('dc.fecha_registro', $desde, $hasta);
    $bloques[] = [
        'titulo'  => 'Defensoría (Casos recibidos)',
        'icono'   => 'fa-hands-helping',
        'kpis'    => [
            ['Casos en el rango', $cnt("SELECT COUNT(*) FROM public.defensoria_casos dc WHERE $Wdc")],
            ['En proceso', $cnt("SELECT COUNT(*) FROM public.defensoria_casos dc WHERE $Wdc AND dc.estatus_caso = 'En Proceso'")],
            ['Atendidos / Cerrados', $cnt("SELECT COUNT(*) FROM public.defensoria_casos dc WHERE $Wdc AND COALESCE(dc.estatus_caso, '') <> 'En Proceso'")],
            ['Víctimas únicas', $cnt("SELECT COUNT(DISTINCT dc.cedula_victimaria_afectada) FROM public.defensoria_casos dc WHERE $Wdc")],
        ],
        'dist'    => ['titulo' => 'Distribución por tipo de violencia', 'cols' => ['Tipo de violencia', 'Casos'],
            'filas' => array_map(fn($f) => [$f['tipo'], (int) $f['total']],
                $filas("SELECT COALESCE(NULLIF(dc.tipo_violencia, ''), 'Sin clasificar') tipo, COUNT(*) total
                          FROM public.defensoria_casos dc WHERE $Wdc GROUP BY 1 ORDER BY 2 DESC LIMIT 10"))],
        'ultimos' => ['titulo' => 'Últimos casos registrados', 'cols' => ['Expediente', 'Víctima', 'Tipo', 'Estatus', 'Fecha'],
            'filas' => array_map(fn($f) => [
                $f['expediente'],
                $f['victima'],
                $f['tipo'] ?? 'Sin clasificar',
                $f['estatus'] ?? 'Sin estatus',
                substr($f['fecha_registro'], 0, 16),
            ], $filas("SELECT dc.codigo_expediente expediente,
                              COALESCE(NULLIF(TRIM(dc.nombre_victima), ''), 'Víctima #' || dc.id_caso) victima,
                              dc.tipo_violencia, dc.estatus_caso, dc.fecha_registro
                         FROM public.defensoria_casos dc
                        WHERE $Wdc ORDER BY dc.fecha_registro DESC, dc.id_caso DESC LIMIT 8"))],
    ];

    // 8. DEFENSORÍA (SEGUIMIENTOS) ------------------------------------------
    $Wds = im_cond('ds.fecha_atencion', $desde, $hasta);
    $bloques[] = [
        'titulo'  => 'Defensoría (Atenciones y seguimientos)',
        'icono'   => 'fa-clipboard-check',
        'kpis'    => [
            ['Atenciones en el rango', $cnt("SELECT COUNT(*) FROM public.defensoria_seguimiento ds WHERE $Wds")],
            ['Últimos 30 días', $cnt("SELECT COUNT(*) FROM public.defensoria_seguimiento ds WHERE ds.fecha_atencion >= CURRENT_DATE - 30")],
            ['Expedientes con seguimiento', $cnt("SELECT COUNT(DISTINCT ds.id_caso) FROM public.defensoria_seguimiento ds WHERE $Wds")],
            ['Seguimientos hoy', $cnt("SELECT COUNT(*) FROM public.defensoria_seguimiento ds WHERE ds.fecha_atencion >= CURRENT_DATE AND ds.fecha_atencion < CURRENT_DATE + INTERVAL '1 day'")],
        ],
        'dist'    => ['titulo' => 'Atenciones por mes', 'cols' => ['Mes', 'Atenciones'],
            'filas' => array_map(fn($f) => [$f['periodo'], (int) $f['total']],
                $filas("SELECT to_char(ds.fecha_atencion, 'YYYY-MM') periodo, COUNT(*) total
                          FROM public.defensoria_seguimiento ds WHERE $Wds GROUP BY 1 ORDER BY 1 DESC LIMIT 12"))],
        'ultimos' => ['titulo' => 'Últimas atenciones registradas', 'cols' => ['Fecha', 'Expediente', 'Responsable', 'Observación'],
            'filas' => array_map(fn($f) => [
                substr($f['fecha_atencion'], 0, 16),
                $f['expediente'],
                $f['responsable'],
                $f['observacion'],
            ], $filas("SELECT ds.fecha_atencion,
                              COALESCE(dc.codigo_expediente, '—') expediente,
                              COALESCE(NULLIF(TRIM(u.nombre || ' ' || u.apellido), ''), '—') responsable,
                              LEFT(COALESCE(NULLIF(TRIM(ds.observacion), ''), 'Sin observación'), 60) observacion
                         FROM public.defensoria_seguimiento ds
                         LEFT JOIN public.defensoria_casos dc ON dc.id_caso = ds.id_caso
                         LEFT JOIN public.usuarios u ON u.id_usuario = ds.id_responsable
                        WHERE $Wds ORDER BY ds.fecha_atencion DESC, ds.id_seguimiento DESC LIMIT 8"))],
    ];

    // 9. CERTIFICADOS EMITIDOS ----------------------------------------------
    $Wce = im_cond('ce.fecha_emision', $desde, $hasta);
    $bloques[] = [
        'titulo'  => 'Certificados Emitidos',
        'icono'   => 'fa-award',
        'kpis'    => [
            ['Certificados en el rango', $cnt("SELECT COUNT(*) FROM public.certificados_emitidos ce WHERE $Wce")],
            ['De culminación', $cnt("SELECT COUNT(*) FROM public.certificados_emitidos ce WHERE $Wce AND ce.tipo = 'Culminacion'")],
            ['Emitidos (vigentes)', $cnt("SELECT COUNT(*) FROM public.certificados_emitidos ce WHERE $Wce AND ce.estado = 'Emitido'")],
            ['Anulados / Otros', $cnt("SELECT COUNT(*) FROM public.certificados_emitidos ce WHERE $Wce AND COALESCE(ce.estado, '') <> 'Emitido'")],
        ],
        'dist'    => ['titulo' => 'Distribución por tipo de certificado', 'cols' => ['Tipo', 'Emitidos'],
            'filas' => array_map(fn($f) => [$f['tipo'], (int) $f['total']],
                $filas("SELECT COALESCE(ce.tipo, 'Sin especificar') tipo, COUNT(*) total
                          FROM public.certificados_emitidos ce WHERE $Wce GROUP BY 1 ORDER BY 2 DESC LIMIT 10"))],
        'ultimos' => ['titulo' => 'Últimos certificados emitidos', 'cols' => ['Folio', 'Tipo', 'Estado', 'Fecha de emisión'],
            'filas' => array_map(fn($f) => [
                $f['folio'],
                $f['tipo'] ?? 'Sin especificar',
                $f['estado'] ?? '—',
                substr($f['fecha_emision'], 0, 16),
            ], $filas("SELECT ce.folio, ce.tipo, ce.estado, ce.fecha_emision
                         FROM public.certificados_emitidos ce
                        WHERE $Wce ORDER BY ce.fecha_emision DESC, ce.id_certificado DESC LIMIT 8"))],
    ];

    // 10. NOTAS CARGADAS (tabla sin columna de fecha: totales históricos) ----
    $bloques[] = [
        'titulo'  => 'Notas Cargadas (histórico)',
        'icono'   => 'fa-star',
        'nota'    => 'La tabla de notas no registra fecha de carga; estos totales corresponden al histórico completo.',
        'kpis'    => [
            ['Notas históricas', $cnt("SELECT COUNT(*) FROM public.notas_estudiantes")],
            ['Estudiantes con notas', $cnt("SELECT COUNT(DISTINCT ne.id_estudiante) FROM public.notas_estudiantes ne")],
            ['Aprobados (>= 10)', $cnt("SELECT COUNT(*) FROM public.notas_estudiantes WHERE nota_cuantitativa >= 10")],
            ['En recuperación', $cnt("SELECT COUNT(*) FROM public.notas_estudiantes WHERE nota_recuperacion IS NOT NULL")],
        ],
        'dist'    => ['titulo' => 'Distribución por resultado', 'cols' => ['Resultado', 'Notas'],
            'filas' => array_map(fn($f) => [$f['resultado'], (int) $f['total']],
                $filas("SELECT CASE
                                 WHEN nota_cuantitativa >= 10 THEN 'Aprobada'
                                 WHEN nota_recuperacion IS NOT NULL THEN 'En recuperación'
                                 ELSE 'Reprobada'
                               END resultado, COUNT(*) total
                          FROM public.notas_estudiantes GROUP BY 1 ORDER BY 2 DESC"))],
        'ultimos' => ['titulo' => 'Últimas notas registradas', 'cols' => ['Estudiante', 'Evaluación', 'Nota'],
            'filas' => array_map(fn($f) => [
                $f['estudiante'],
                $f['evaluacion'],
                (int) $f['nota'],
            ], $filas("SELECT COALESCE(NULLIF(TRIM(u.nombre || ' ' || u.apellido), ''), 'Estudiante #' || ne.id_estudiante) estudiante,
                              COALESCE(NULLIF(TRIM(pe.titulo_evaluacion), ''), 'Evaluación #' || ne.id_plan) evaluacion,
                              COALESCE(ne.nota_cuantitativa, 0) nota
                         FROM public.notas_estudiantes ne
                         LEFT JOIN public.usuarios u ON u.id_usuario = ne.id_estudiante
                         LEFT JOIN public.plan_evaluacion pe ON pe.id_plan = ne.id_plan
                        ORDER BY ne.id_nota DESC LIMIT 8"))],
    ];

    // 11. USUARIOS NUEVOS ----------------------------------------------------
    $Wu = im_cond('u.fecha_registro', $desde, $hasta);
    $bloques[] = [
        'titulo'  => 'Usuarios Registrados en el Sistema',
        'icono'   => 'fa-users',
        'kpis'    => [
            ['Cuentas creadas en el rango', $cnt("SELECT COUNT(*) FROM public.usuarios u WHERE $Wu")],
            ['Estudiantes', $cnt("SELECT COUNT(*) FROM public.usuarios u WHERE $Wu AND 'estudiante' = ANY(u.roles_asignados)")],
            ['Profesores / Personal', $cnt("SELECT COUNT(*) FROM public.usuarios u WHERE $Wu AND NOT ('estudiante' = ANY(u.roles_asignados))")],
            ['Cuentas activas (histórico)', $cnt("SELECT COUNT(*) FROM public.usuarios WHERE estatus_cuenta = 'Activo'")],
        ],
        'dist'    => ['titulo' => 'Cuentas creadas por tipo de usuario', 'cols' => ['Tipo de usuario', 'Cuentas'],
            'filas' => array_map(fn($f) => [$f['tipo'], (int) $f['total']],
                $filas("SELECT COALESCE(NULLIF(u.tipo_usuario, ''), 'sin tipo') tipo, COUNT(*) total
                          FROM public.usuarios u WHERE $Wu GROUP BY 1 ORDER BY 2 DESC LIMIT 10"))],
        'ultimos' => ['titulo' => 'Últimas cuentas creadas', 'cols' => ['Fecha', 'Cédula', 'Nombre', 'Tipo', 'Estatus'],
            'filas' => array_map(fn($f) => [
                substr($f['fecha_registro'], 0, 16),
                $f['cedula'],
                $f['nombre'],
                $f['tipo'] ?? '—',
                $f['estatus'] ?? '—',
            ], $filas("SELECT u.fecha_registro, u.cedula,
                              COALESCE(NULLIF(TRIM(u.nombre || ' ' || u.apellido), ''), '—') nombre,
                              u.tipo_usuario, u.estatus_cuenta
                         FROM public.usuarios u WHERE $Wu
                        ORDER BY u.fecha_registro DESC, u.id_usuario DESC LIMIT 8"))],
    ];

    // 12. HORARIOS DE CLASES GENERADOS --------------------------------------
    $Wh = im_cond('h.fecha_generacion', $desde, $hasta);
    $bloques[] = [
        'titulo'  => 'Horarios de Clases Generados',
        'icono'   => 'fa-calendar-week',
        'kpis'    => [
            ['Bloques de horario', $cnt("SELECT COUNT(*) FROM public.horarios_clases h WHERE $Wh")],
            ['Cohortes con horario', $cnt("SELECT COUNT(DISTINCT h.id_cohorte) FROM public.horarios_clases h WHERE $Wh")],
            ['Días programados', $cnt("SELECT COUNT(DISTINCT h.dia_semana) FROM public.horarios_clases h WHERE $Wh")],
            ['Generaciones de hoy', $cnt("SELECT COUNT(*) FROM public.horarios_clases h WHERE h.fecha_generacion >= CURRENT_DATE AND h.fecha_generacion < CURRENT_DATE + INTERVAL '1 day'")],
        ],
        'dist'    => ['titulo' => 'Bloques por día de la semana', 'cols' => ['Día', 'Bloques'],
            'filas' => array_map(fn($f) => [$f['dia'], (int) $f['total']],
                $filas("SELECT COALESCE(NULLIF(h.dia_semana, ''), 'Sin día') dia, COUNT(*) total
                          FROM public.horarios_clases h WHERE $Wh GROUP BY 1 ORDER BY 2 DESC LIMIT 10"))],
        'ultimos' => ['titulo' => 'Últimas generaciones de horario', 'cols' => ['Generado el', 'Cohorte', 'Bloques'],
            'filas' => array_map(fn($f) => [
                substr($f['fecha_generacion'], 0, 16),
                $f['cohorte'],
                (int) $f['bloques'],
            ], $filas("SELECT h.fecha_generacion,
                              COALESCE(c.codigo_cohorte, 'Cohorte #' || h.id_cohorte) cohorte,
                              COUNT(*) bloques
                         FROM public.horarios_clases h
                         LEFT JOIN public.cohortes c ON c.id_cohorte = h.id_cohorte
                        WHERE $Wh GROUP BY h.fecha_generacion, c.codigo_cohorte
                        ORDER BY h.fecha_generacion DESC LIMIT 8"))],
    ];

    // 13. DORMITORIOS Y PERNOCTAS -------------------------------------------
    $Wp = im_cond('p.fecha_entrada', $desde, $hasta);
    $Wpr = im_cond('pr.fecha_registro', $desde, $hasta);
    $camas_totales = $cnt("SELECT COALESCE(SUM(d.capacidad_camas), 0) FROM public.dormitorios d WHERE d.estatus IN ('Disponible', 'Ocupada')");
    $camas_ocupadas = $cnt("SELECT COUNT(*) FROM public.dormitorio_pernoctas p WHERE p.estado = 'En Curso'");
    $bloques[] = [
        'titulo'  => 'Dormitorios y Pernoctas',
        'icono'   => 'fa-bed',
        'kpis'    => [
            ['Pernoctas en el rango', $cnt("SELECT COUNT(*) FROM public.dormitorio_pernoctas p WHERE $Wp")],
            ['Pernoctas en curso (ocupación actual)', $cnt("SELECT COUNT(*) FROM public.dormitorio_pernoctas p WHERE p.estado = 'En Curso'")],
            ['Prórrogas en el rango', $cnt("SELECT COUNT(*) FROM public.dormitorio_prorrogas pr WHERE $Wpr")],
            ['Camas disponibles hoy', max(0, $camas_totales - $camas_ocupadas) . ' de ' . $camas_totales],
        ],
        'dist'    => ['titulo' => 'Pernoctas por habitación (rango)', 'cols' => ['Habitación', 'Pernoctas'],
            'filas' => array_map(fn($f) => [$f['dormitorio'], (int) $f['total']],
                $filas("SELECT COALESCE(d.nombre, 'Habitación #' || p.id_dormitorio) dormitorio, COUNT(*) total
                          FROM public.dormitorio_pernoctas p
                          LEFT JOIN public.dormitorios d ON d.id_dormitorio = p.id_dormitorio
                         WHERE $Wp GROUP BY 1 ORDER BY 2 DESC LIMIT 10"))],
        'ultimos' => ['titulo' => 'Últimos registros de pernocta', 'cols' => ['Entrada', 'Huésped', 'Habitación', 'Salida prevista', 'Tipo', 'Estado'],
            'filas' => array_map(fn($f) => [
                $f['entrada'],
                $f['huesped'],
                $f['dormitorio'],
                $f['salida'],
                $f['tipo'],
                $f['estado'],
            ], $filas("SELECT to_char(p.fecha_entrada, 'DD/MM/YYYY') || ' ' || to_char(p.hora_entrada, 'HH24:MI') entrada,
                              COALESCE(NULLIF(TRIM(p.nombre_completo), ''), '—') huesped,
                              COALESCE(d.nombre, '—') dormitorio,
                              to_char(p.fecha_salida_prevista, 'DD/MM/YYYY') salida,
                              CASE WHEN p.es_visitante_foraneo THEN 'Foráneo' ELSE 'Usuario' END tipo,
                              p.estado
                         FROM public.dormitorio_pernoctas p
                         LEFT JOIN public.dormitorios d ON d.id_dormitorio = p.id_dormitorio
                        WHERE $Wp ORDER BY p.fecha_entrada DESC, p.hora_entrada DESC LIMIT 8"))],
    ];

    // 14. BITÁCORA DEL SISTEMA ----------------------------------------------
    $Wb = im_cond('b.fecha_hora', $desde, $hasta);
    $bloques[] = [
        'titulo'  => 'Bitácora del Sistema (Auditoría)',
        'icono'   => 'fa-history',
        'kpis'    => [
            ['Eventos en el rango', $cnt("SELECT COUNT(*) FROM public.bitacora b WHERE $Wb")],
            ['Usuarios con actividad', $cnt("SELECT COUNT(DISTINCT b.id_usuario) FROM public.bitacora b WHERE $Wb")],
            ['Ingresos al sistema', $cnt("SELECT COUNT(*) FROM public.bitacora b WHERE $Wb AND b.accion LIKE 'ACCESO%'")],
            ['Últimos 30 días', $cnt("SELECT COUNT(*) FROM public.bitacora b WHERE b.fecha_hora >= CURRENT_DATE - 30")],
        ],
        'dist'    => ['titulo' => 'Eventos por módulo', 'cols' => ['Módulo', 'Eventos'],
            'filas' => array_map(fn($f) => [$f['modulo'], (int) $f['total']],
                $filas("SELECT COALESCE(NULLIF(b.modulo, ''), 'SIN MÓDULO') modulo, COUNT(*) total
                          FROM public.bitacora b WHERE $Wb GROUP BY 1 ORDER BY 2 DESC LIMIT 12"))],
        'ultimos' => ['titulo' => 'Últimos eventos registrados', 'cols' => ['Fecha y hora', 'Usuario', 'Módulo', 'Acción'],
            'filas' => array_map(fn($f) => [
                substr($f['fecha_hora'], 0, 19),
                $f['usuario'],
                $f['modulo'] ?? '—',
                $f['accion'],
            ], $filas("SELECT b.fecha_hora,
                              COALESCE(NULLIF(TRIM(u.nombre || ' ' || u.apellido), ''), 'Usuario #' || COALESCE(b.id_usuario, 0)) usuario,
                              b.modulo, LEFT(b.accion, 70) accion
                         FROM public.bitacora b
                         LEFT JOIN public.usuarios u ON u.id_usuario = b.id_usuario
                        WHERE $Wb ORDER BY b.fecha_hora DESC, b.id_log DESC LIMIT 8"))],
    ];

    // Etiqueta del rango consultado
    if ($desde !== null && $hasta !== null) {
        $rango_txt = 'Del ' . DateTime::createFromFormat('Y-m-d', $desde)->format('d/m/Y') . ' al ' . DateTime::createFromFormat('Y-m-d', $hasta)->format('d/m/Y');
    } elseif ($desde !== null) {
        $rango_txt = 'Desde el ' . DateTime::createFromFormat('Y-m-d', $desde)->format('d/m/Y');
    } elseif ($hasta !== null) {
        $rango_txt = 'Hasta el ' . DateTime::createFromFormat('Y-m-d', $hasta)->format('d/m/Y');
    } else {
        $rango_txt = 'Histórico completo de la escuela';
    }

    return [
        'desde'     => $desde,
        'hasta'     => $hasta,
        'rango_txt' => $rango_txt,
        'resumen'   => $resumen,
        'bloques'   => $bloques,
    ];
}

$datos = im_datos($pdo, $desde, $hasta);

// ---------------------------------------------------------------------------
// MODO PDF (dompdf) — se sirve el informe completo y se sale sin HTML de sesión
// ---------------------------------------------------------------------------
if ($es_pdf) {
    require_once '../../includes/dompdf_autoload.php';

    $img_data = function (string $ruta): string {
        if (!is_file($ruta)) {
            return '';
        }
        $ext  = strtolower(pathinfo($ruta, PATHINFO_EXTENSION));
        $mime = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif'][$ext] ?? 'application/octet-stream';
        return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($ruta));
    };

    $directora = null;
    $jefa_control = null;
    try {
        $directora = $pdo->query("SELECT * FROM public.configuracion_autoridades WHERE cargo = 'Directora de la Escuela' AND estatus = 'Activo' LIMIT 1")->fetch();
        $jefa_control = $pdo->query("SELECT * FROM public.configuracion_autoridades WHERE cargo = 'Director de Control de Estudio' AND estatus = 'Activo' LIMIT 1")->fetch();
    } catch (Throwable $e) {}

    $folio   = 'REP-MOV-' . date('Ymd-His');
    $min = function (string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); };

    $css = '
        body { font-family: DejaVu Sans, sans-serif; color: #333; font-size: 8.5pt; }
        h1 { font-size: 13pt; color: #521f29; margin: 0; text-transform: uppercase; }
        h2 { font-size: 10pt; color: #521f29; background: #f3e9df; padding: 4px 8px; margin: 12px 0 6px 0;
             border-left: 4px solid #ff8a00; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 6px; }
        th { background: #521f29; color: #fff; font-size: 7.5pt; padding: 4px; text-align: left; }
        td { border: 1px solid #ddd0c2; padding: 3px 4px; font-size: 7.5pt; }
        tr:nth-child(even) td { background: #faf6f1; }
        .kpi td { text-align: center; width: 25%; }
        .kpi .v { font-size: 13pt; font-weight: bold; color: #521f29; }
        .kpi .l { font-size: 6.5pt; color: #8a7562; text-transform: uppercase; }
        .sec { page-break-inside: avoid; }
        .muted { color: #8a7562; font-size: 7pt; font-style: italic; }
        .firma { text-align: center; font-size: 7.5pt; margin-top: 10px; }
        .firma .linea { border-top: 1px solid #333; width: 70%; margin: 3px auto 0 auto; padding-top: 3px; }
    ';

    $html = '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><style>' . $css . '</style></head><body>';

    // Membrete
    $html .= '<table style="border:none;"><tr>'
        . '<td style="border:none;width:22%;">' . ($img_data('../../assets/img/Logo del Ministerio 999 x 150-01.png') ? '<img src="' . $img_data('../../assets/img/Logo del Ministerio 999 x 150-01.png') . '" style="max-height:42px;">' : '') . '</td>'
        . '<td style="border:none;text-align:center;">'
        . '<div style="font-size:8pt;font-weight:bold;text-transform:uppercase;color:#521f29;">República Bolivariana de Venezuela</div>'
        . '<div style="font-size:6.5pt;color:#6b5b4d;">Ministerio del Poder Popular para la Mujer y la Igualdad de Género</div>'
        . '<div style="font-size:9.5pt;font-weight:bold;text-transform:uppercase;color:#ff8a00;margin-top:2px;">Escuela Internacional de Liderazgo de la Mujer "Nora Castañeda"</div>'
        . '<div style="font-size:6.5pt;color:#6b5b4d;">Sede Principal Bello Monte, Maracay Edo. Aragua</div>'
        . '</td>'
        . '<td style="border:none;text-align:right;width:22%;">' . ($img_data('../../assets/img/icono.png') ? '<img src="' . $img_data('../../assets/img/icono.png') . '" style="max-height:44px;">' : '') . '</td>'
        . '</tr></table>';

    $html .= '<hr style="border:none;border-top:2px solid #521f29;margin:6px 0 10px 0;">';

    $html .= '<h1>Informe General de Movimientos de la Escuela</h1>'
        . '<div style="font-size:8pt;margin:3px 0 10px 0;">'
        . '<strong>Folio:</strong> ' . $folio . ' &nbsp;|&nbsp; '
        . '<strong>Período consultado:</strong> ' . $min($datos['rango_txt']) . ' &nbsp;|&nbsp; '
        . '<strong>Emitido:</strong> ' . date('d/m/Y h:i A') . ' &nbsp;|&nbsp; '
        . '<strong>Generado por:</strong> ' . $min($_SESSION['nombre'] ?? 'Usuario') . '</div>';

    // Resumen general
    $html .= '<table class="kpi"><tr>'
        . '<td><span class="v">' . number_format($datos['resumen']['total']) . '</span><br><span class="l">Movimientos en el rango</span></td>'
        . '<td><span class="v">' . number_format($datos['resumen']['hoy']) . '</span><br><span class="l">Movimientos de hoy</span></td>'
        . '<td><span class="v">' . number_format($datos['resumen']['mes']) . '</span><br><span class="l">Movimientos del mes</span></td>'
        . '<td><span class="v">' . count($datos['bloques']) . '</span><br><span class="l">Áreas de actividad</span></td>'
        . '</tr></table>';

    $num = 0;
    foreach ($datos['bloques'] as $bloque) {
        $num++;
        $html .= '<div class="sec">';
        $html .= '<h2>' . $num . '. ' . $min($bloque['titulo']) . '</h2>';
        if (!empty($bloque['nota'])) {
            $html .= '<div class="muted">' . $min($bloque['nota']) . '</div>';
        }

        $html .= '<table class="kpi"><tr>';
        foreach ($bloque['kpis'] as [$etiqueta, $valor]) {
            $html .= '<td><span class="v">' . number_format((int) $valor) . '</span><br><span class="l">' . $min($etiqueta) . '</span></td>';
        }
        $html .= '</tr></table>';

        if (!empty($bloque['dist']['filas'])) {
            $html .= '<div style="font-size:7.5pt;font-weight:bold;color:#521f29;margin:6px 0 2px 0;">' . $min($bloque['dist']['titulo']) . '</div>';
            $html .= '<table><tr><th style="width:70%;">' . $min($bloque['dist']['cols'][0]) . '</th><th>' . $min($bloque['dist']['cols'][1]) . '</th></tr>';
            foreach ($bloque['dist']['filas'] as $fila) {
                $html .= '<tr><td>' . $min((string) $fila[0]) . '</td><td style="text-align:right;">' . $min((string) $fila[1]) . '</td></tr>';
            }
            $html .= '</table>';
        }

        if (!empty($bloque['ultimos']['filas'])) {
            $html .= '<div style="font-size:7.5pt;font-weight:bold;color:#521f29;margin:6px 0 2px 0;">' . $min($bloque['ultimos']['titulo']) . '</div>';
            $html .= '<table><tr>';
            foreach ($bloque['ultimos']['cols'] as $col) {
                $html .= '<th>' . $min($col) . '</th>';
            }
            $html .= '</tr>';
            foreach ($bloque['ultimos']['filas'] as $fila) {
                $html .= '<tr>';
                foreach ($fila as $celda) {
                    $html .= '<td>' . $min((string) $celda) . '</td>';
                }
                $html .= '</tr>';
            }
            $html .= '</table>';
        }

        $html .= '</div>';
    }

    // Firmas de autoridades
    $html .= '<div class="sec"><table style="border:none;margin-top:16px;"><tr>';
    foreach ([['Directora General de la Escuela', $directora], ['Dirección de Control de Estudio', $jefa_control]] as [$cargo, $aut]) {
        $firma = (!empty($aut['firma_digital']) && is_file('../../' . $aut['firma_digital']))
            ? '<img src="' . $img_data('../../' . $aut['firma_digital']) . '" style="max-height:44px;"><br>' : '';
        $html .= '<td style="border:none;width:50%;text-align:center;">' . $firma
            . '<div class="firma"><div class="linea"><strong>' . $min($aut['nombre_apellido'] ?? '—') . '</strong><br>'
            . $min($cargo) . '<br>' . $min(($aut['tipo_nombramiento'] ?? '') . ' N° ' . ($aut['numero_nombramiento'] ?? '')) . '</div></div></td>';
    }
    $html .= '</tr></table></div>';

    $html .= '<div style="text-align:center;font-size:6.5pt;color:#8a7562;margin-top:10px;">Informe generado automáticamente por el Sistema de Gestión de la Escuela Internacional Nora Castañeda — Folio ' . $folio . '</div>';
    $html .= '</body></html>';

    $options = new \Dompdf\Options();
    $options->setChroot(dirname(__DIR__, 3));
    $options->setIsRemoteEnabled(false);
    $options->setIsHtml5ParserEnabled(true);
    $options->setDefaultFont('DejaVu Sans');
    $options->setTempDir(sys_get_temp_dir());

    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('letter', 'portrait');
    $dompdf->render();

    $pdf = $dompdf->output();
    $nombreArchivo = 'informe_movimientos_' . date('Ymd_His') . '.pdf';
    if (!headers_sent()) {
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $nombreArchivo . '"');
        header('Content-Length: ' . strlen($pdf));
        header('Cache-Control: private, max-age=0, must-revalidate');
    }
    echo $pdf;
    exit();
}

// ---------------------------------------------------------------------------
// VISTA HTML
// ---------------------------------------------------------------------------
require_once '../../includes/header.php';

$params_pdf = http_build_query(array_filter(['desde' => $desde, 'hasta' => $hasta]));
$qs_base    = $params_pdf !== '' ? '&' . $params_pdf : '';
?>

<!-- BOTONERA SUPERIOR -->
<div class="d-sm-flex align-items-center justify-content-between mb-4 no-print">
    <h1 class="h3 mb-0 font-weight-bold" style="color: #521f29;">
        <i class="fas fa-exchange-alt mr-2 text-warning"></i>Reportes e Informes de Movimientos
    </h1>
    <div>
        <a href="informes_movimiento.php?pdf=1<?= $qs_base ?>" class="btn font-weight-bold text-white shadow-sm mr-1" style="background-color: #b02a37;">
            <i class="fas fa-file-pdf mr-1 text-warning"></i> DESCARGAR INFORME PDF
        </a>
        <button onclick="window.print()" class="btn font-weight-bold text-white shadow-sm mr-1" style="background-color: #521f29;">
            <i class="fas fa-print mr-1 text-warning"></i> IMPRIMIR
        </button>
        <a href="../../dashboard.php" class="btn btn-secondary font-weight-bold shadow-sm">
            <i class="fas fa-arrow-left mr-1"></i> Volver
        </a>
    </div>
</div>

<!-- FILTRO DE FECHAS -->
<div class="card shadow border-0 mb-4 no-print" style="border-radius: 12px;">
    <div class="card-body bg-white py-3">
        <form method="GET" class="form-row align-items-end">
            <div class="form-group col-md-3">
                <label class="font-weight-bold small text-dark">Desde</label>
                <input type="date" name="desde" class="form-control form-control-sm" value="<?= htmlspecialchars($desde ?? '') ?>">
            </div>
            <div class="form-group col-md-3">
                <label class="font-weight-bold small text-dark">Hasta</label>
                <input type="date" name="hasta" class="form-control form-control-sm" value="<?= htmlspecialchars($hasta ?? '') ?>">
            </div>
            <div class="form-group col-md-4">
                <button type="submit" class="btn btn-sm font-weight-bold text-white mr-1" style="background-color: #ff8a00;">
                    <i class="fas fa-filter mr-1"></i> Aplicar filtro
                </button>
                <a href="informes_movimiento.php" class="btn btn-sm btn-secondary font-weight-bold">
                    <i class="fas fa-times mr-1"></i> Histórico completo
                </a>
            </div>
        </form>
    </div>
</div>

<!-- HOJA DEL INFORME -->
<div class="card shadow border-0 p-4 bg-white sheet-print" style="border-radius: 15px; max-width: 1100px; margin: 0 auto;">

    <!-- MEMBRETE -->
    <div class="d-flex justify-content-between align-items-center pb-3 border-bottom mb-4">
        <img src="../../assets/img/Logo del Ministerio 999 x 150-01.png" style="height: 48px;" alt="MinMujer" onerror="this.style.display='none'">
        <div class="text-center px-2">
            <h6 class="m-0 font-weight-bold text-uppercase" style="color: #521f29; font-size: 11pt;">República Bolivariana de Venezuela</h6>
            <small class="font-weight-bold text-muted d-block" style="font-size: 8.5pt;">Ministerio del Poder Popular para la Mujer y la Igualdad de Género</small>
            <h5 class="m-0 font-weight-bold text-uppercase mt-1" style="color: #ff8a00; font-size: 12pt;">Escuela Internacional de Liderazgo de la Mujer "Nora Castañeda"</h5>
            <small class="font-weight-bold text-dark d-block" style="font-size: 8pt;">Sede Principal Bello Monte, Maracay Edo. Aragua</small>
        </div>
        <img src="../../assets/img/icono.png" style="height: 52px;" alt="Logo Nora" onerror="this.style.display='none'">
    </div>

    <div class="d-flex justify-content-between align-items-end mb-4">
        <div>
            <h4 class="font-weight-bold text-uppercase m-0" style="color: #521f29; text-decoration: underline;">INFORME GENERAL DE MOVIMIENTOS DE LA ESCUELA</h4>
            <small class="font-weight-bold text-muted"><i class="fas fa-calendar-day mr-1"></i>Período consultado: <?= htmlspecialchars($datos['rango_txt']) ?></small>
        </div>
        <div class="text-right">
            <span class="badge badge-light border font-weight-bold p-2 text-dark">
                <i class="fas fa-calendar-alt mr-1 text-danger"></i>Emisión: <strong><?= date('d/m/Y') ?></strong> (<?= date('h:i A') ?>)
            </span>
        </div>
    </div>

    <!-- RESUMEN GENERAL -->
    <div class="row text-center mb-4">
        <div class="col-3 mb-2">
            <div class="p-3 border rounded bg-light">
                <small class="font-weight-bold d-block text-muted">Movimientos en el rango</small>
                <h3 class="font-weight-bold m-0" style="color: #521f29;"><?= number_format($datos['resumen']['total']) ?></h3>
            </div>
        </div>
        <div class="col-3 mb-2">
            <div class="p-3 border rounded bg-light">
                <small class="font-weight-bold d-block text-muted">Movimientos de hoy</small>
                <h3 class="font-weight-bold m-0" style="color: #ff8a00;"><?= number_format($datos['resumen']['hoy']) ?></h3>
            </div>
        </div>
        <div class="col-3 mb-2">
            <div class="p-3 border rounded bg-light">
                <small class="font-weight-bold d-block text-muted">Movimientos del mes</small>
                <h3 class="font-weight-bold m-0 text-success"><?= number_format($datos['resumen']['mes']) ?></h3>
            </div>
        </div>
        <div class="col-3 mb-2">
            <div class="p-3 border rounded bg-light">
                <small class="font-weight-bold d-block text-muted">Áreas de actividad</small>
                <h3 class="font-weight-bold m-0 text-primary"><?= count($datos['bloques']) ?></h3>
            </div>
        </div>
    </div>

    <?php $num = 0; foreach ($datos['bloques'] as $bloque): $num++; ?>
        <!-- BLOQUE <?= $num ?> -->
        <div class="mb-4">
            <h6 class="font-weight-bold text-uppercase p-2 text-white rounded" style="background-color: #521f29;">
                <i class="fas <?= htmlspecialchars($bloque['icono']) ?> mr-1" style="color: #ff8a00;"></i>
                <?= $num ?>. <?= htmlspecialchars($bloque['titulo']) ?>
            </h6>

            <?php if (!empty($bloque['nota'])): ?>
                <small class="text-muted font-italic d-block mt-1"><i class="fas fa-info-circle mr-1"></i><?= htmlspecialchars($bloque['nota']) ?></small>
            <?php endif; ?>

            <div class="row text-center mt-2">
                <?php foreach ($bloque['kpis'] as [$etiqueta, $valor]): ?>
                    <div class="col-3 mb-2">
                        <div class="p-2 border rounded bg-light h-100">
                            <small class="font-weight-bold d-block text-muted" style="font-size: 7.5pt;"><?= htmlspecialchars($etiqueta) ?></small>
                            <h4 class="font-weight-bold m-0" style="color: #521f29;"><?= number_format((int) $valor) ?></h4>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if (!empty($bloque['dist']['filas'])): ?>
                <div class="table-responsive mt-2">
                    <table class="table table-sm table-striped table-bordered align-middle mb-0 small">
                        <thead style="background-color: #ff8a00; color: #521f29;" class="font-weight-bold">
                            <tr>
                                <th style="width: 70%;"><?= htmlspecialchars($bloque['dist']['cols'][0]) ?></th>
                                <th class="text-center"><?= htmlspecialchars($bloque['dist']['cols'][1]) ?></th>
                            </tr>
                        </thead>
                        <tbody class="font-weight-bold">
                            <?php foreach ($bloque['dist']['filas'] as $fila): ?>
                                <tr>
                                    <td><?= htmlspecialchars((string) $fila[0]) ?></td>
                                    <td class="text-center" style="color: #521f29;"><?= $fila[1] ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <?php if (!empty($bloque['ultimos']['filas'])): ?>
                <div class="table-responsive mt-3">
                    <table class="table table-sm table-hover table-bordered align-middle mb-0 small">
                        <thead class="bg-light text-dark font-weight-bold">
                            <tr>
                                <?php foreach ($bloque['ultimos']['cols'] as $col): ?>
                                    <th><?= htmlspecialchars($col) ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($bloque['ultimos']['filas'] as $fila): ?>
                                <tr>
                                    <?php foreach ($fila as $celda): ?>
                                        <td><?= htmlspecialchars((string) $celda) ?></td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

</div>

<!-- ESTILOS DE IMPRESIÓN -->
<style>
@media print {
    .no-print, nav, footer, sidebar, .navbar { display: none !important; }
    body { background: #ffffff !important; padding: 0 !important; margin: 0 !important; }
    .sheet-print { border: none !important; box-shadow: none !important; padding: 0 !important; max-width: 100% !important; }
    .card { border: none !important; box-shadow: none !important; }
    @page { margin: 1.5cm; size: A4 portrait; }
}
</style>

<?php require_once '../../includes/footer.php'; ?>
