<?php
// /sistema_nora/modulos/control_estudio/api_consultar_disponibilidad.php
header('Content-Type: application/json');
require_once '../../config/db.php';

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

if (empty($dias_solicitados) || empty($hora_inicio) || empty($hora_fin)) {
    echo json_encode(['success' => false, 'message' => 'Parámetros incompletos para consultar disponibilidad.']);
    exit;
}

try {
    // 1. Obtener todos los salones activos en Infraestructura
    $sql_salones = "SELECT e.id_espacio, e.nombre_espacio, e.capacidad_estudiantes, t.nombre_torre, e.piso_numero 
                    FROM public.campus_espacios e 
                    LEFT JOIN public.campus_edificios t ON e.id_edificio = t.id_edificio 
                    WHERE e.estatus = 'Disponible' 
                    ORDER BY t.nombre_torre, e.nombre_espacio ASC";
    $salones = $pdo->query($sql_salones)->fetchAll(PDO::FETCH_ASSOC);

    // 2. Consultar ocupaciones en Cohortes Activas
    $sql_cohortes = "SELECT salon_aula, dias_clase, fecha_inicio, fecha_fin_estimada, codigo_cohorte 
                     FROM public.cohortes 
                     WHERE estatus IN ('Planificada', 'En Curso') 
                       AND (fecha_inicio <= :ff AND fecha_fin_estimada >= :fi)";
    $stmt_c = $pdo->prepare($sql_cohortes);
    $stmt_c->execute([':fi' => $fecha_inicio, ':ff' => $fecha_fin]);
    $ocupadas_cohortes = $stmt_c->fetchAll(PDO::FETCH_ASSOC);

    // 3. Consultar ocupaciones en Préstamos a Misiones Educativas
    $sql_misiones = "SELECT salon_asignado, dias_horario, fecha_inicio, fecha_fin, nombre_mision 
                     FROM public.prestamo_misiones 
                     WHERE (fecha_inicio <= :ff AND fecha_fin >= :fi)";
    $stmt_m = $pdo->prepare($sql_misiones);
    $stmt_m->execute([':fi' => $fecha_inicio, ':ff' => $fecha_fin]);
    $ocupadas_misiones = $stmt_m->fetchAll(PDO::FETCH_ASSOC);

    $resultado_checklist = [];

    foreach ($salones as $sal) {
        $nombre_sal = $sal['nombre_espacio'];
        $esta_disponible = true;
        $motivo_ocupacion = '';

        // Comprobar contra cohortes
        foreach ($ocupadas_cohortes as $oc) {
            if (strpos($oc['salon_aula'], $nombre_sal) !== false) {
                foreach ($dias_solicitados as $dia) {
                    if (strpos($oc['dias_clase'], $dia) !== false) {
                        $esta_disponible = false;
                        $motivo_ocupacion = "Ocupado por Sección/Cohorte " . $oc['codigo_cohorte'];
                        break 2;
                    }
                }
            }
        }

        // Comprobar contra misiones si sigue disponible
        if ($esta_disponible) {
            foreach ($ocupadas_misiones as $om) {
                if (strpos($om['salon_asignado'], $nombre_sal) !== false) {
                    foreach ($dias_solicitados as $dia) {
                        if (strpos($om['dias_horario'], $dia) !== false) {
                            $esta_disponible = false;
                            $motivo_ocupacion = "Ocupado por " . $om['nombre_mision'];
                            break 2;
                        }
                    }
                }
            }
        }

        $resultado_checklist[] = [
            'id_espacio'  => $sal['id_espacio'],
            'nombre'      => $sal['nombre_torre'] . ' - ' . $sal['nombre_espacio'] . ' (Piso ' . $sal['piso_numero'] . ')',
            'capacidad'   => $sal['capacidad_estudiantes'],
            'disponible'  => $esta_disponible,
            'motivo'      => $motivo_ocupacion
        ];
    }

    echo json_encode(['success' => true, 'salones' => $resultado_checklist]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>