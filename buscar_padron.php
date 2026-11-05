<?php
// /sistema_nora/buscar_padron.php
// API JSON restringida: consulta del Padrón Nacional únicamente para
// usuarios autenticados con rol de administración o Control de Estudio.
header('Content-Type: application/json');
require_once 'config/db.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ═══ CONTROL DE ACCESO ═══
$roles_usuario = $_SESSION['roles_asignados'] ?? [$_SESSION['tipo_usuario'] ?? ''];
if (empty($_SESSION['id_usuario']) || !array_intersect(['superadmin', 'admin', 'control_estudio'], $roles_usuario)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Acceso no autorizado al Padrón Nacional.']);
    exit();
}

$cedula = preg_replace('/[^0-9]/', '', $_GET['cedula'] ?? '');

if (empty($cedula)) {
    echo json_encode(['success' => false, 'message' => 'Cédula no proporcionada']);
    exit();
}

if (empty($pdo_padron)) {
    echo json_encode(['success' => false, 'message' => 'Servicio del Padrón Nacional no disponible en este momento.']);
    exit();
}

try {
    // Consulta exacta a la tabla 'datos' buscando por el campo 'document'
    $stmt = $pdo_padron->prepare("SELECT * FROM public.datos WHERE document = :c LIMIT 1");
    $stmt->execute([':c' => $cedula]);
    $row = $stmt->fetch();

    if ($row) {
        // Concatenación de Nombres y Apellidos
        $nombre_comp   = trim(($row['first_name'] ?? '') . ' ' . ($row['second_name'] ?? ''));
        $apellido_comp = trim(($row['first_surname'] ?? '') . ' ' . ($row['second_surname'] ?? ''));

        // Mapeo de Género (F / M)
        $genero_map = [
            'F' => 'Femenino',
            'M' => 'Masculino'
        ];
        $sexo = $genero_map[strtoupper($row['genero'] ?? '')] ?? ($row['genero'] ?? '');

        // Mapeo de Estado Civil
        $estado_civil_map = [
            '1' => 'Soltero(a)',
            '2' => 'Casado(a)',
            '3' => 'Divorciado(a)',
            '4' => 'Viudo(a)',
            '5' => 'Concubinato'
        ];
        $ecivil_raw = strval($row['estado_civil'] ?? '');
        $estado_civil = $estado_civil_map[$ecivil_raw] ?? $ecivil_raw;

        // Cálculo dinámico de Edad Exacta desde 'birth_date'
        $edad = 0;
        $f_nac = '';
        if (!empty($row['birth_date'])) {
            $f_nac_obj = new DateTime($row['birth_date']);
            $f_nac     = $f_nac_obj->format('Y-m-d');
            $hoy       = new DateTime();
            $edad      = $hoy->diff($f_nac_obj)->y;
        }

        echo json_encode([
            'success' => true,
            'datos'   => [
                'nombre'               => $nombre_comp,
                'apellido'             => $apellido_comp,
                'fecha_nacimiento'     => $f_nac,
                'sexo'                 => $sexo,
                'edad'                 => $edad,
                'estado_civil'         => $estado_civil,
                'correo'               => $row['correo'] ?? '',
                'telefono'             => $row['telefono'] ?? '',
                'etnia'                => $row['etnia'] ?? 'Ninguna',
                'tipo_discapacidad'    => $row['tipo_discapacidad'] ?? 'Ninguna',
                'estado'               => $row['estado'] ?? '',
                'municipio'            => $row['municipio'] ?? '',
                'parroquia'            => $row['parroquia'] ?? '',
                'direccion_completa'   => $row['direccion_completa'] ?? '',
                'inscrito_cne'         => $row['inscrito_cne'] ?? 'No',
                'cne_estado'           => $row['cne_estado'] ?? '',
                'cne_municipio'        => $row['cne_municipio'] ?? '',
                'cne_parroquia'        => $row['cne_parroquia'] ?? '',
                'cne_centro_electoral' => $row['cne_centro_electoral'] ?? '',
                'tiene_hijos'          => $row['tiene_hijos'] ?? 'No',
                'cantidad_hijos'       => intval($row['cantidad_hijos'] ?? 0)
            ]
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Cédula no localizada en el Padrón Nacional']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error en la consulta del padrón. Intente nuevamente.']);
}
?>