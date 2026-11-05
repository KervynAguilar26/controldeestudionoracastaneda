<?php
// /sistema_nora/verificar_llave.php
// API JSON de pre-autorización para registro.php
// SOLO responde para cuentas pre-autorizadas con estatus 'Pendiente'.
// No escribe en la base de datos ni expone datos sensibles de cuentas activas.
header('Content-Type: application/json');
require_once 'config/db.php';

$cedula = preg_replace('/[^0-9]/', '', $_GET['cedula'] ?? '');

if (empty($cedula)) {
    echo json_encode(['success' => false, 'message' => 'Cédula no proporcionada']);
    exit();
}

try {
    // 1. Buscar la pre-autorización realizada por Control de Estudio / Superusuario
    $stmt_u = $pdo->prepare("SELECT id_usuario, nombre, apellido, fecha_nacimiento, sexo, edad, estado_civil, estatus_cuenta 
                             FROM public.usuarios WHERE cedula = :c LIMIT 1");
    $stmt_u->execute([':c' => $cedula]);
    $u = $stmt_u->fetch();

    if (!$u || $u['estatus_cuenta'] !== 'Pendiente') {
        // Respuesta uniforme: no revelar si la cédula existe como cuenta activa
        echo json_encode(['success' => false, 'autorizado' => false, 'message' => 'Su Cédula V-' . $cedula . ' no está autorizada para registro en línea. Contacte a la Unidad de Control de Estudio.']);
        exit();
    }

    $nombre    = $u['nombre'] ?? '';
    $apellido  = $u['apellido'] ?? '';
    $f_nac     = $u['fecha_nacimiento'] ?? '';
    $sexo      = $u['sexo'] ?? '';
    $edad      = $u['edad'] ?? 0;
    $est_civil = $u['estado_civil'] ?? '';

    // 2. Si los nombres están pendientes, rescatarlos SOLO LECTURA del padrón
    //    (la escritura ocurre únicamente al enviar el formulario en registro.php)
    if (($nombre === 'POR COMPLETAR' || empty($nombre) || empty($f_nac)) && !empty($pdo_padron)) {
        $stmt_p = $pdo_padron->prepare("SELECT first_name, second_name, first_surname, second_surname, birth_date, genero, estado_civil FROM public.datos WHERE document = :c LIMIT 1");
        $stmt_p->execute([':c' => $cedula]);
        $pad = $stmt_p->fetch();

        if ($pad) {
            $nombre   = trim(($pad['first_name'] ?? '') . ' ' . ($pad['second_name'] ?? ''));
            $apellido = trim(($pad['first_surname'] ?? '') . ' ' . ($pad['second_surname'] ?? ''));

            if (!empty($pad['birth_date'])) {
                $f_obj = new DateTime($pad['birth_date']);
                $f_nac = $f_obj->format('Y-m-d');
                $edad  = (new DateTime())->diff($f_obj)->y;
            }
            $genero_map = ['F' => 'Femenino', 'M' => 'Masculino'];
            $sexo = $genero_map[strtoupper($pad['genero'] ?? '')] ?? ($pad['genero'] ?? '');
            $ec_map = ['1' => 'Soltero(a)', '2' => 'Casado(a)', '3' => 'Divorciado(a)', '4' => 'Viudo(a)', '5' => 'Concubinato'];
            $est_civil = $ec_map[strval($pad['estado_civil'] ?? '')] ?? ($pad['estado_civil'] ?? '');
        }
    }

    // 3. Respuesta mínima: únicamente datos de prellenado del formulario de registro
    echo json_encode([
        'success' => true,
        'autorizado' => true,
        'datos'   => [
            'nombre'           => $nombre,
            'apellido'         => $apellido,
            'fecha_nacimiento' => $f_nac,
            'sexo'             => $sexo,
            'edad'             => $edad,
            'estado_civil'     => $est_civil
        ]
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error de consulta. Intente nuevamente.']);
}
?>
