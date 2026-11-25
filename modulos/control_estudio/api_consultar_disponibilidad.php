<?php
// /sistema_nora/modulos/control_estudio/api_consultar_disponibilidad.php
header('Content-Type: application/json');
require_once '../../config/db.php';
require_once '../../includes/espacios_disponibilidad.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }

$roles_ad = $_SESSION['roles_asignados'] ?? [$_SESSION['tipo_usuario'] ?? ''];
$es_ce_ad = in_array('superadmin', $roles_ad) || in_array('admin', $roles_ad) || in_array('control_estudio', $roles_ad);

if (empty($_SESSION['id_usuario']) || !$es_ce_ad) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Acceso restringido a Control de Estudio.']);
    exit;
}

$dias_solicitados = $_POST['dias'] ?? []; // Ejemplo: ['Lunes', 'Martes']
$hora_inicio      = $_POST['hora_inicio'] ?? '';
$hora_fin         = $_POST['hora_fin'] ?? '';
$fecha_inicio     = $_POST['fecha_inicio'] ?? date('Y-m-d');
$fecha_fin        = $_POST['fecha_fin'] ?? date('Y-m-d');
$excluir_prestamo = !empty($_POST['id_prestamo']) ? intval($_POST['id_prestamo']) : null;
// Al editar una sección, sus propios bloques no deben bloquear su salón.
$excluir_cohorte  = !empty($_POST['id_cohorte']) ? intval($_POST['id_cohorte']) : null;
$tipos_solicitados = $_POST['tipos'] ?? [];
if (!is_array($tipos_solicitados)) {
    $tipos_solicitados = array_filter(array_map('trim', explode(',', (string) $tipos_solicitados)), 'strlen');
}

if (!is_array($dias_solicitados)) {
    $dias_solicitados = array_filter(array_map('trim', explode(',', (string) $dias_solicitados)), 'strlen');
}

if (empty($dias_solicitados) || $hora_inicio === '' || $hora_fin === '') {
    echo json_encode(['success' => false, 'message' => 'Parámetros incompletos para consultar disponibilidad.']);
    exit;
}

$ini = espacios_normalizar_hora($hora_inicio);
$fin = espacios_normalizar_hora($hora_fin);
if ($ini === '' || $fin === '' || $fin <= $ini) {
    echo json_encode(['success' => false, 'message' => 'El rango horario indicado no es válido.']);
    exit;
}

try {
    // Espacios de Infraestructura (se pueden filtrar tipos desde crear_cohorte.php).
    $sql_salones = "SELECT e.id_espacio, e.nombre_espacio, e.tipo_espacio, e.capacidad_estudiantes,
                           t.nombre_torre, e.piso_numero
                    FROM public.campus_espacios e
                    LEFT JOIN public.campus_edificios t ON e.id_edificio = t.id_edificio";
    $params_salones = [];
    if (!empty($tipos_solicitados)) {
        $marcadores = [];
        foreach (array_values(array_unique($tipos_solicitados)) as $i => $tipo) {
            $clave = ':tipo' . $i;
            $marcadores[] = $clave;
            $params_salones[$clave] = (string) $tipo;
        }
        $sql_salones .= ' WHERE e.tipo_espacio IN (' . implode(', ', $marcadores) . ')';
    }
    $sql_salones .= ' ORDER BY t.nombre_torre, e.nombre_espacio ASC';
    $stmt_salones = $pdo->prepare($sql_salones);
    $stmt_salones->execute($params_salones);
    $salones = $stmt_salones->fetchAll(PDO::FETCH_ASSOC);

    $resultado_checklist = [];
    foreach ($salones as $sal) {
        $estado = espacios_estado(
            $pdo,
            (string) $sal['nombre_espacio'],
            $dias_solicitados,
            $ini,
            $fin,
            (string) $fecha_inicio,
            (string) $fecha_fin,
            $excluir_prestamo,
            $excluir_cohorte
        );

        $resultado_checklist[] = [
            'id_espacio'     => $sal['id_espacio'],
            'nombre_espacio' => $sal['nombre_espacio'],
            'tipo_espacio'   => $sal['tipo_espacio'],
            'nombre'         => $sal['nombre_torre'] . ' - ' . $sal['nombre_espacio'] . ' (Piso ' . $sal['piso_numero'] . ')',
            'capacidad'      => $sal['capacidad_estudiantes'],
            // Se mantiene "disponible" por compatibilidad con el checklist actual.
            'disponible'  => !$estado['bloqueado'],
            'estado'      => $estado['estado'],
            'motivo'      => $estado['motivo'],
            'bloqueos'    => $estado['bloqueos'],
        ];
    }

    echo json_encode(['success' => true, 'salones' => $resultado_checklist]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
