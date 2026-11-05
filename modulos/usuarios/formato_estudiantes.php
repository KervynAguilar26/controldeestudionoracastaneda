<?php
// /sistema_nora/modulos/usuarios/formato_estudiantes.php
// Descarga del formato XLSX con membrete institucional para la matrícula
// masiva de estudiantes (cursos cortos / eventos de pernocta). Las profesoras
// lo llenan en Excel y lo cargan en modulos/usuarios/carga_masiva_estudiantes.php
// Si se indica ?cohorte=ID, el código del curso queda impreso en el membrete y
// el cargador lo detecta automáticamente para inscribir a cada estudiante en
// ese curso (cohorte).

require_once '../../config/db.php';
require_once '../../includes/xlsx.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['id_usuario'])) {
    header('Location: ../../index.php');
    exit();
}

$roles_usuario = $_SESSION['roles_asignados'] ?? [$_SESSION['tipo_usuario'] ?? 'trabajador'];

function ft_tiene_permiso(array $permitidos, array $roles): bool
{
    if (in_array('superadmin', $roles, true) || in_array('admin', $roles, true)) {
        return true;
    }
    return !empty(array_intersect($permitidos, $roles));
}

if (!ft_tiene_permiso(['control_estudio', 'profesor'], $roles_usuario)) {
    http_response_code(403);
    exit('Acceso restringido. Solo Administradores, Control de Estudio y Profesores pueden descargar el formato.');
}

// --- Cohorte (curso) opcional indicada en la URL ---------------------------
$cohorte = null;
if (!empty($_GET['cohorte'])) {
    try {
        $stmt = $pdo->prepare("SELECT c.id_cohorte, c.codigo_cohorte, c.estatus, c.hora_inicio, c.hora_fin,
                                      c.fecha_inicio, c.fecha_fin_estimada, c.salon_aula, c.dias_clase,
                                      m.nombre_programa, m.requiere_pernocta
                               FROM public.cohortes c
                               LEFT JOIN public.mallas_curriculares m ON m.id_malla = c.id_malla
                               WHERE c.id_cohorte = :id");
        $stmt->execute([':id' => intval($_GET['cohorte'])]);
        $cohorte = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
        $cohorte = null;
    }
}

// Registro en bitácora (auditoría, no crítico)
try {
    $accion_bit = 'DESCARGA FORMATO MATRICULA MASIVA (XLSX)'
        . ($cohorte ? ' curso:' . $cohorte['codigo_cohorte'] : '');
    $pdo->prepare("INSERT INTO public.bitacora (id_usuario, modulo, accion, ip_maquina)
                   VALUES (:u, 'CONTROL DE ESTUDIO', :acc, :ip)")
        ->execute([':u' => $_SESSION['id_usuario'], ':acc' => $accion_bit, ':ip' => $_SERVER['REMOTE_ADDR'] ?? null]);
} catch (Throwable $e) {}

// --- Datos del membrete ----------------------------------------------------
$usuario_emisor = $_SESSION['nombre_completo'] ?? trim(($_SESSION['nombre'] ?? '') . ' ' . ($_SESSION['apellido'] ?? ''));
$usuario_emisor = trim($usuario_emisor !== '' ? $usuario_emisor : ($_SESSION['cedula'] ?? ''));
$fecha_emision  = date('d/m/Y');
$titulo_curso   = 'FORMATO DE MATRÍCULA MASIVA DE ESTUDIANTES';

$fila_curso = 'SIN CURSO ASIGNADO — indique el curso al cargar el archivo';
if ($cohorte !== null) {
    $fila_curso = 'CURSO: ' . $cohorte['codigo_cohorte']
        . ($cohorte['nombre_programa'] !== null ? ' — ' . strtoupper($cohorte['nombre_programa']) : '')
        . ($cohorte['estatus'] ? ' — Estado: ' . $cohorte['estatus'] : '');
}

$columnas = ['CÉDULA', 'NOMBRE', 'APELLIDO', 'CORREO', 'TELÉFONO', 'SEXO (Femenino/Masculino)', 'FECHA NACIMIENTO (AAAA-MM-DD)'];
$ejemplo  = [
    '12345678',
    'EJEMPLO: María Alexandra',
    'EJEMPLO: Pérez González (elimine esta fila antes de cargar)',
    'maria.perez@correo.com',
    '0412-1234567',
    'Femenino',
    '1995-05-20',
];

// --- Construcción de la hoja ----------------------------------------------
$filas = [];

// Membrete institucional (celdas combinadas A:G)
$filas[] = ['alto' => 24, 'celdas' => [['v' => 'REPÚBLICA BOLIVARIANA DE VENEZUELA', 's' => 1, 'span' => 7]]];
$filas[] = ['celdas' => [['v' => 'MINISTERIO DEL PODER POPULAR PARA LA MUJER Y LA IGUALDAD DE GÉNERO', 's' => 2, 'span' => 7]]];
$filas[] = ['alto' => 22, 'celdas' => [['v' => 'ESCUELA INTERNACIONAL DE LIDERAZGO DE LA MUJER «NORA CASTAÑEDA»', 's' => 1, 'span' => 7]]];
$filas[] = ['alto' => 20, 'celdas' => [['v' => $titulo_curso, 's' => 3, 'span' => 7]]];

// Fila del curso (el código de cohorte queda en una celda propia para la
// detección automática en la carga)
$filas[] = ['celdas' => [
    ['v' => 'Código de curso:', 's' => 9],
    ['v' => $cohorte !== null ? $cohorte['codigo_cohorte'] : '', 's' => 8],
    ['v' => 'Programa:', 's' => 9],
    ['v' => $cohorte !== null && $cohorte['nombre_programa'] !== null ? $cohorte['nombre_programa'] : '—', 's' => 5, 'span' => 2],
    ['v' => 'Fecha de emisión:', 's' => 9],
    ['v' => $fecha_emision, 's' => 5],
]];

// Datos complementarios del curso (horario que el sistema conoce)
$horario_txt = '—';
if ($cohorte !== null) {
    $horario_txt = trim(
        ($cohorte['dias_clase'] !== '' ? $cohorte['dias_clase'] . ' ' : '')
        . (($cohorte['hora_inicio'] && $cohorte['hora_fin']) ? 'de ' . substr($cohorte['hora_inicio'], 0, 5) . ' a ' . substr($cohorte['hora_fin'], 0, 5) : '')
    );
    if ($horario_txt === '' || $horario_txt === '—') {
        $horario_txt = '—';
    }
}
$filas[] = ['celdas' => [
    ['v' => 'Horario del curso:', 's' => 9],
    ['v' => $horario_txt, 's' => 5, 'span' => 2],
    ['v' => 'Salón / Aula:', 's' => 9],
    ['v' => $cohorte !== null && $cohorte['salon_aula'] !== '' ? $cohorte['salon_aula'] : '—', 's' => 5],
    ['v' => 'Emitido por:', 's' => 9],
    ['v' => $usuario_emisor !== '' ? $usuario_emisor : '—', 's' => 5],
]];

$filas[] = ['alto' => 8, 'celdas' => []]; // separador

// Encabezados de columna
$filas[] = ['alto' => 30, 'celdas' => array_map(static function (string $c): array {
    return ['v' => $c, 's' => 4];
}, $columnas)];

// Fila de ejemplo (gris, itálica)
$filas[] = ['celdas' => array_map(static function (string $c): array {
    return ['v' => $c, 's' => 6];
}, $ejemplo)];

$filas[] = ['alto' => 8, 'celdas' => []]; // separador

// Indicaciones
$filas[] = ['celdas' => [[
    'v' => 'INDICACIONES: 1) Elimine la fila de ejemplo. 2) Una fila por estudiante; la cédula debe tener de 6 a 8 dígitos; '
         . 'el sexo debe ser "Femenino" o "Masculino"; la fecha de nacimiento en formato AAAA-MM-DD o DD/MM/AAAA. '
         . '3) No repita cédulas dentro del archivo.',
    's' => 10, 'span' => 7,
]]];
$filas[] = ['celdas' => [[
    'v' => '4) Al cargar, cada estudiante queda ACTIVO con la clave genérica ' . 'Nora123456+-'
         . ' y, si este formato lleva un curso, queda inscrito automáticamente en ese curso. '
         . '5) Guarde el archivo sin cambiar el nombre de las columnas y súbelo en "Matrícula Masiva de Estudiantes".',
    's' => 10, 'span' => 7,
]]];

$contenido = xlsx_generar([
    'nombre'   => 'Matrícula Masiva',
    'anchos'   => [14, 26, 30, 30, 18, 24, 26],
    'filas'    => $filas,
]);

// --- Envío como descarga ---------------------------------------------------
$nombre_archivo = 'formato_matricula'
    . ($cohorte !== null ? '_' . preg_replace('/[^A-Za-z0-9_-]/', '', $cohorte['codigo_cohorte']) : '')
    . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $nombre_archivo . '"');
header('Content-Length: ' . strlen($contenido));
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

echo $contenido;
exit();
