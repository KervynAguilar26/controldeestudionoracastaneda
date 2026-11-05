<?php
// /sistema_nora/modulos/control_estudio/certificados_masivos.php
// Descarga masiva: un solo archivo PDF con todos los certificados de
// culminación de los estudiantes seleccionados.

session_start();
require_once '../../config/db.php';
require_once '../../includes/certificado_pdf.php';

$roles_usuario = $_SESSION['roles_asignados'] ?? [$_SESSION['tipo_usuario'] ?? ''];
$es_admin = in_array('admin', $roles_usuario)
         || in_array('superadmin', $roles_usuario)
         || in_array('control_estudio', $roles_usuario);

function masivos_pagina(string $titulo, string $mensaje, array $detalles = [], int $codigo = 400)
{
    http_response_code($codigo);
    $t = htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8');
    $m = htmlspecialchars($mensaje, ENT_QUOTES, 'UTF-8');
    $lista = '';
    foreach ($detalles as $d) {
        $lista .= '<li class="mb-1">' . htmlspecialchars($d, ENT_QUOTES, 'UTF-8') . '</li>';
    }
    $listaHtml = $lista !== '' ? '<ul class="text-left small mb-0">' . $lista . '</ul>' : '';
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><title>' . $t . '</title>'
        . '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">'
        . '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">'
        . '</head><body style="background-color:#d6c4af;">'
        . '<div class="container py-5"><div class="card shadow mx-auto" style="max-width:700px; border-radius:15px;">'
        . '<div class="card-header text-white font-weight-bold" style="background-color:#521f29;">'
        . '<i class="fas fa-certificate mr-2"></i>' . $t . '</div>'
        . '<div class="card-body bg-white text-center p-4">'
        . '<div class="alert alert-warning font-weight-bold border-0" style="background-color:#ffe8c9;">' . $m . '</div>'
        . $listaHtml
        . '<a href="javascript:history.back()" class="btn font-weight-bold text-white mt-3" style="background-color:#ff8a00;">'
        . '<i class="fas fa-arrow-left mr-1"></i> Volver a la Matrícula General</a>'
        . '</div></div></div></body></html>';
    exit;
}

if (empty($_SESSION['id_usuario'])) {
    masivos_pagina('Sesión requerida', 'Debe iniciar sesión para descargar certificados.', [], 401);
}
if (!$es_admin) {
    masivos_pagina('Acceso restringido', 'Esta función es exclusiva de Control de Estudio y Administración.', [], 403);
}

// ---------------------------------------------------------------- selección
$ids = [];
foreach ((array) ($_REQUEST['ids'] ?? []) as $v) {
    $v = intval($v);
    if ($v > 0) {
        $ids[$v] = $v;
    }
}

// Opción "todos los completados" (aplicando los filtros recibidos)
if (!empty($_REQUEST['completados'])) {
    $id_malla = intval($_REQUEST['id_malla'] ?? 0);
    $id_cohorte = intval($_REQUEST['id_cohorte'] ?? 0);

    $sql = "SELECT i.id_inscripcion, i.id_estudiante, co.id_malla
              FROM public.inscripciones i
              JOIN public.cohortes co ON co.id_cohorte = i.id_curso
             WHERE 1 = 1";
    $params = [];
    if ($id_malla > 0) {
        $sql .= " AND co.id_malla = :malla";
        $params[':malla'] = $id_malla;
    }
    if ($id_cohorte > 0) {
        $sql .= " AND i.id_curso = :cohorte";
        $params[':cohorte'] = $id_cohorte;
    }
    $sql .= " ORDER BY i.id_inscripcion ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $cache = [];
    foreach ($stmt->fetchAll() as $fila) {
        $clave = $fila['id_estudiante'] . '_' . $fila['id_malla'];
        if (!isset($cache[$clave])) {
            $cache[$clave] = academico_estado_malla($pdo, (int) $fila['id_estudiante'], (int) $fila['id_malla']);
        }
        if ($cache[$clave]['completado']) {
            $ids[(int) $fila['id_inscripcion']] = (int) $fila['id_inscripcion'];
        }
    }
}

$ids = array_slice(array_values($ids), 0, 300, true);

if (empty($ids)) {
    masivos_pagina(
        'Sin certificados que descargar',
        'No se seleccionó ningún estudiante. Marque los certificados que desea descargar o use la opción de descargar todos los completados.'
    );
}

// ------------------------------------------------------------ construcción
$items = [];
$errores = [];

foreach ($ids as $id_inscripcion) {
    try {
        $inscripcion = academico_estado_inscripcion($pdo, $id_inscripcion);
        if (!$inscripcion) {
            $errores[] = 'La inscripción #' . $id_inscripcion . ' no existe.';
            continue;
        }
        $certificado = certificado_obtener($pdo, $id_inscripcion, (int) $_SESSION['id_usuario']);
        $items[] = ['ins' => $inscripcion, 'cert' => $certificado];
    } catch (Throwable $e) {
        $errores[] = 'Inscripción #' . $id_inscripcion . ': ' . $e->getMessage();
    }
}

if (empty($items)) {
    masivos_pagina(
        'No fue posible generar los certificados',
        'Ninguno de los estudiantes seleccionados puede recibir certificado en este momento.',
        $errores
    );
}

$nombre = 'Certificados_Culminacion_' . date('Ymd_His') . '.pdf';
certificado_pdf_output($items, $nombre, true);
