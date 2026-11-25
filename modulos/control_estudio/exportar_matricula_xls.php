<?php
// /sistema_nora/modulos/control_estudio/exportar_matricula_xls.php
// Descarga del padrón de matrícula en XLSX, con los mismos filtros que
// matricula_general.php (q, id_malla e id_cohorte).

require_once '../../config/db.php';
require_once '../../includes/academico.php';
require_once '../../includes/xlsx.php';

$roles_usuario = $_SESSION['roles_asignados'] ?? [$_SESSION['tipo_usuario'] ?? ''];
$permitido = in_array('control_estudio', $roles_usuario, true)
          || in_array('admin', $roles_usuario, true)
          || in_array('superadmin', $roles_usuario, true);
if (!$permitido) {
    http_response_code(403);
    echo 'Acceso restringido a Control de Estudio.';
    exit();
}

try {
    $f_malla   = intval($_GET['id_malla'] ?? 0);
    $f_cohorte = intval($_GET['id_cohorte'] ?? 0);
    $f_q       = trim($_GET['q'] ?? '');

    $sql = "SELECT i.id_inscripcion, i.fecha_inscripcion,
                   u.id_usuario AS id_estudiante, u.cedula, u.nombre, u.apellido, u.correo, u.telefono,
                   co.id_cohorte, co.codigo_cohorte, co.num_periodo,
                   m.id_malla, m.nombre_programa, m.tipo_oferta, m.tipo_regimen, m.total_periodos
              FROM public.inscripciones i
              JOIN public.usuarios u ON u.id_usuario = i.id_estudiante
              JOIN public.cohortes co ON co.id_cohorte = i.id_curso
              JOIN public.mallas_curriculares m ON m.id_malla = co.id_malla
             WHERE 1 = 1";

    $params = [];
    if ($f_malla > 0) {
        $sql .= " AND m.id_malla = :malla";
        $params[':malla'] = $f_malla;
    }
    if ($f_cohorte > 0) {
        $sql .= " AND co.id_cohorte = :cohorte";
        $params[':cohorte'] = $f_cohorte;
    }
    if ($f_q !== '') {
        $sql .= " AND (u.cedula LIKE :q OR (u.nombre || ' ' || u.apellido) ILIKE :q)";
        $params[':q'] = '%' . $f_q . '%';
    }
    $sql .= " ORDER BY u.apellido ASC, u.nombre ASC, co.num_periodo ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $inscripciones = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Igual agrupación que la pantalla: un renglón por estudiante y carrera.
    $grupos = [];
    foreach ($inscripciones as $fila) {
        $clave = $fila['id_estudiante'] . '_' . $fila['id_malla'];
        if (!isset($grupos[$clave])) {
            $grupos[$clave] = [
                'id_estudiante'   => (int) $fila['id_estudiante'],
                'cedula'          => $fila['cedula'],
                'estudiante'      => trim($fila['nombre'] . ' ' . $fila['apellido']),
                'correo'          => $fila['correo'],
                'telefono'        => $fila['telefono'],
                'id_malla'        => (int) $fila['id_malla'],
                'nombre_programa' => $fila['nombre_programa'],
                'tipo_oferta'     => $fila['tipo_oferta'],
                'tipo_regimen'    => $fila['tipo_regimen'] ?? 'Semestre',
                'total_periodos'  => (int) $fila['total_periodos'],
                'ultimo_periodo'  => 0,
                'ultima_cohorte'  => '',
                'cohortes'        => [],
            ];
        }
        $g = &$grupos[$clave];
        if (!in_array($fila['codigo_cohorte'], $g['cohortes'], true)) {
            $g['cohortes'][] = $fila['codigo_cohorte'];
        }
        if ((int) $fila['num_periodo'] >= $g['ultimo_periodo']) {
            $g['ultimo_periodo'] = (int) $fila['num_periodo'];
            $g['ultima_cohorte'] = $fila['codigo_cohorte'];
        }
        unset($g);
    }

    foreach ($grupos as $clave => $g) {
        $grupos[$clave]['estado'] = academico_estado_malla($pdo, $g['id_estudiante'], $g['id_malla']);
    }

    $filtros = [];
    if ($f_q !== '') { $filtros[] = 'Búsqueda: ' . $f_q; }
    if ($f_malla > 0) {
        $st = $pdo->prepare("SELECT nombre_programa FROM public.mallas_curriculares WHERE id_malla = :id");
        $st->execute([':id' => $f_malla]);
        $filtros[] = 'Carrera: ' . ($st->fetchColumn() ?: ('ID ' . $f_malla));
    }
    if ($f_cohorte > 0) {
        $st = $pdo->prepare("SELECT codigo_cohorte FROM public.cohortes WHERE id_cohorte = :id");
        $st->execute([':id' => $f_cohorte]);
        $filtros[] = 'Sección: ' . ($st->fetchColumn() ?: ('ID ' . $f_cohorte));
    }

    $filas = [];
    $filas[] = ['alto' => 30, 'celdas' => [
        ['v' => 'PADRÓN DE MATRÍCULA · ESCUELA NORA CASTAÑEDA', 's' => 1, 'span' => 11],
    ]];
    $filas[] = ['celdas' => [
        ['v' => 'Generado: ' . date('d/m/Y H:i:s') . '   |   ' . ($filtros ? implode('   |   ', $filtros) : 'Todas las carreras y secciones'), 's' => 10, 'span' => 11],
    ]];
    $filas[] = ['celdas' => [
        ['v' => 'Estudiantes: ' . count($grupos), 's' => 9, 'span' => 11],
    ]];
    $filas[] = ['alto' => 30, 'celdas' => [
        ['v' => 'N°', 's' => 4],
        ['v' => 'CÉDULA', 's' => 4],
        ['v' => 'ESTUDIANTE', 's' => 4],
        ['v' => 'CORREO', 's' => 4],
        ['v' => 'TELÉFONO', 's' => 4],
        ['v' => 'CARRERA / OFERTA', 's' => 4],
        ['v' => 'RÉGIMEN', 's' => 4],
        ['v' => 'NIVEL', 's' => 4],
        ['v' => 'AVANCE', 's' => 4],
        ['v' => 'ÚLTIMA SECCIÓN', 's' => 4],
        ['v' => 'ESTATUS ACADÉMICO', 's' => 4],
    ]];

    $n = 0;
    foreach ($grupos as $g) {
        $n++;
        $e = $g['estado'];
        $filas[] = ['celdas' => [
            ['v' => $n, 's' => 7],
            ['v' => 'V-' . $g['cedula'], 's' => 5],
            ['v' => $g['estudiante'], 's' => 5],
            ['v' => $g['correo'] ?? '', 's' => 5],
            ['v' => $g['telefono'] ?? '', 's' => 5],
            ['v' => $g['nombre_programa'] . ($g['tipo_oferta'] ? ' (' . $g['tipo_oferta'] . ')' : ''), 's' => 5],
            ['v' => $g['tipo_regimen'], 's' => 7],
            ['v' => $g['ultimo_periodo'] . ' de ' . $g['total_periodos'], 's' => 7],
            ['v' => $e['porcentaje'] . '% (' . $e['aprobadas'] . '/' . $e['total_materias'] . ')', 's' => 7],
            ['v' => $g['ultima_cohorte'], 's' => 7],
            ['v' => $e['completado'] ? 'Completo' : 'En curso', 's' => 7],
        ]];
    }

    $xlsx = xlsx_generar([
        'nombre'  => 'Padron Matricula',
        'anchos'  => [6, 16, 28, 30, 16, 34, 12, 12, 18, 18, 18],
        'filas'   => $filas,
    ]);

    if (ob_get_level() > 0 && PHP_SAPI !== 'cli') { ob_end_clean(); }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="Padron_Matricula_' . date('Ymd_His') . '.xlsx"');
    header('Content-Length: ' . strlen($xlsx));
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo $xlsx;
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'No se pudo generar el archivo XLS: ' . $e->getMessage();
}
exit();
