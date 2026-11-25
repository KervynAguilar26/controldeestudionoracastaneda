<?php
// /sistema_nora/modulos/guarderia/api_marcar.php
// Marcaje rápido de entrada/retiro del Centro de Cuidados Infantiles.
// Responde JSON para no recargar toda la página en cada clic.

header('Content-Type: application/json; charset=utf-8');
require_once '../../config/db.php';

$roles_api = $_SESSION['roles_asignados'] ?? [$_SESSION['tipo_usuario'] ?? ''];
if (!is_array($roles_api)) { $roles_api = [$roles_api]; }
if (empty($_SESSION['id_usuario'])
    || !(in_array('superadmin', $roles_api, true)
         || in_array('admin', $roles_api, true)
         || in_array('guarderia', $roles_api, true))) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'mensaje' => 'Acceso restringido al Centro de Cuidados Infantiles.']);
    exit;
}

function gf_hora($valor): ?string
{
    if (empty($valor)) { return null; }
    $ts = strtotime((string) $valor);
    return $ts ? date('h:i:s A', $ts) : (string) $valor;
}

function gf_respuesta(array $fila, string $mensaje): array
{
    $estatus = (string) ($fila['estatus'] ?? 'Presente');
    return [
        'ok'             => true,
        'id_nino'        => (int) $fila['id_nino'],
        'id_asistencia'  => (int) $fila['id_asistencia'],
        'hora_entrada'   => gf_hora($fila['hora_entrada'] ?? null),
        'hora_salida'    => gf_hora($fila['hora_salida'] ?? null),
        'estatus'        => $estatus,
        'proxima_accion' => ($estatus === 'Presente') ? 'salida' : 'ninguna',
        'mensaje'        => $mensaje,
    ];
}

try {
    if (isset($_POST['entrada_id'])) {
        $id_nino = intval($_POST['entrada_id']);
        if ($id_nino <= 0) { throw new Exception('Niño/a no válido.'); }

        $stmt = $pdo->prepare("INSERT INTO public.guarderia_asistencia
                                  (id_nino, fecha_asistencia, hora_entrada, estatus)
                               VALUES (:id, CURRENT_DATE, CURRENT_TIME, 'Presente')
                               ON CONFLICT (id_nino, fecha_asistencia)
                               DO UPDATE SET id_nino = EXCLUDED.id_nino
                               RETURNING id_asistencia, id_nino, hora_entrada, hora_salida, estatus");
        $stmt->execute([':id' => $id_nino]);
        $fila = $stmt->fetch();
        echo json_encode(gf_respuesta($fila, 'Entrada registrada correctamente.'));
        exit;
    }

    if (isset($_POST['salida_id'])) {
        $id_asis = intval($_POST['salida_id']);
        if ($id_asis <= 0) { throw new Exception('Registro de asistencia no válido.'); }

        $stmt = $pdo->prepare("UPDATE public.guarderia_asistencia
                                  SET hora_salida = CURRENT_TIME, estatus = 'Retirado'
                                WHERE id_asistencia = :id AND estatus = 'Presente'
                                RETURNING id_asistencia, id_nino, hora_entrada, hora_salida, estatus");
        $stmt->execute([':id' => $id_asis]);
        $fila = $stmt->fetch();
        if (!$fila) {
            echo json_encode(['ok' => false, 'mensaje' => 'El registro ya fue retirado o no existe.']);
            exit;
        }
        echo json_encode(gf_respuesta($fila, 'Retiro registrado correctamente.'));
        exit;
    }

    echo json_encode(['ok' => false, 'mensaje' => 'Acción no reconocida.']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'mensaje' => 'No se pudo registrar el marcaje. Intente nuevamente.']);
}
