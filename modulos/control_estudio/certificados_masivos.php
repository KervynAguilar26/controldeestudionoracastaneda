<?php
// /sistema_nora/modulos/control_estudio/certificados_masivos.php
// Descarga masiva de certificados académicos, opcionalmente filtrada por
// curso (malla) y sección (cohorte):
//   - modo=completados: genera/recupera los certificados de quienes completaron la malla.
//   - modo=emitidos: solo descarga los certificados ya emitidos del curso.

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
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

// ---------------------------------------------------------------- filtros
$id_malla   = intval($_REQUEST['id_malla'] ?? 0);
$id_cohorte = intval($_REQUEST['id_cohorte'] ?? 0);
$modo       = (string) ($_REQUEST['modo'] ?? 'completados');
if (!in_array($modo, ['completados', 'emitidos'], true)) {
    $modo = 'completados';
}

$nombreCurso = 'Todos los cursos';
$archivoCurso = 'Todos';
if ($id_malla > 0) {
    $st = $pdo->prepare("SELECT nombre_programa FROM public.mallas_curriculares WHERE id_malla = :id");
    $st->execute([':id' => $id_malla]);
    $nombre = $st->fetchColumn();
    if ($nombre === false) {
        masivos_pagina('Curso no válido', 'El curso seleccionado no existe.', [], 404);
    }
    $nombreCurso = $nombre;
    $archivoCurso = preg_replace('/[^A-Za-z0-9_-]/', '_', $nombre);
}
if ($id_cohorte > 0) {
    $st = $pdo->prepare("SELECT co.codigo_cohorte, co.id_malla, m.nombre_programa
                           FROM public.cohortes co
                           JOIN public.mallas_curriculares m ON m.id_malla = co.id_malla
                          WHERE co.id_cohorte = :id");
    $st->execute([':id' => $id_cohorte]);
    $cohorte = $st->fetch(PDO::FETCH_ASSOC);
    if (!$cohorte) {
        masivos_pagina('Sección no válida', 'La sección seleccionada no existe.', [], 404);
    }
    if ($id_malla > 0 && (int) $cohorte['id_malla'] !== $id_malla) {
        masivos_pagina('Filtros incompatibles', 'La sección elegida no pertenece al curso seleccionado.', [], 400);
    }
    $id_malla = (int) $cohorte['id_malla'];
    $nombreCurso = ($id_malla > 0 ? $cohorte['nombre_programa'] : $nombreCurso)
        . ' · Sección ' . $cohorte['codigo_cohorte'];
    $archivoCurso = $cohorte['codigo_cohorte'];
}

// Condición SQL reutilizable para ambos modos.
$whereCurso = [];
$paramsCurso = [];
if ($id_malla > 0) {
    $whereCurso[] = 'co.id_malla = :malla';
    $paramsCurso[':malla'] = $id_malla;
}
if ($id_cohorte > 0) {
    $whereCurso[] = 'i.id_curso = :cohorte';
    $paramsCurso[':cohorte'] = $id_cohorte;
}
$filtroSql = $whereCurso ? ' AND ' . implode(' AND ', $whereCurso) : '';

$items = [];
$errores = [];

if ($modo === 'emitidos') {
    // Descargar únicamente los certificados ya emitidos del curso filtrado.
    $sql = "SELECT ce.*
              FROM public.certificados_emitidos ce
              JOIN public.inscripciones i ON i.id_inscripcion = ce.id_inscripcion
              JOIN public.cohortes co ON co.id_cohorte = i.id_curso
             WHERE ce.origen = 'Academico'
               AND ce.tipo = 'Culminacion'
               AND ce.estado <> 'Anulado'
               AND ce.id_inscripcion IS NOT NULL
               $filtroSql
             ORDER BY ce.folio ASC, ce.linea_libro ASC, ce.id_certificado ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($paramsCurso);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $fila) {
        try {
            $inscripcion = academico_estado_inscripcion($pdo, (int) $fila['id_inscripcion']);
            if (!$inscripcion) {
                $errores[] = 'La inscripción #' . $fila['id_inscripcion'] . ' no existe.';
                continue;
            }
            $items[] = ['ins' => $inscripcion, 'cert' => $fila];
        } catch (Throwable $e) {
            $errores[] = 'Certificado #' . $fila['id_certificado'] . ': ' . $e->getMessage();
        }
    }
} else {
    // Selección explícita de inscripciones.
    $ids = [];
    foreach ((array) ($_REQUEST['ids'] ?? []) as $v) {
        $v = intval($v);
        if ($v > 0) {
            $ids[$v] = $v;
        }
    }

    // Opción "todos los completados" del curso filtrado.
    if (!empty($_REQUEST['completados'])) {
        $sql = "SELECT i.id_inscripcion, i.id_estudiante, co.id_malla
                  FROM public.inscripciones i
                  JOIN public.cohortes co ON co.id_cohorte = i.id_curso
                 WHERE 1 = 1 $filtroSql
                 ORDER BY i.id_inscripcion ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($paramsCurso);

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
            'No hay certificados para descargar en ' . $nombreCurso
            . '. Marque estudiantes o use la opción de todos los completados del curso filtrado.'
        );
    }

    foreach ($ids as $id_inscripcion) {
        try {
            $inscripcion = academico_estado_inscripcion($pdo, $id_inscripcion);
            if (!$inscripcion) {
                $errores[] = 'La inscripción #' . $id_inscripcion . ' no existe.';
                continue;
            }
            if ($id_malla > 0 && (int) $inscripcion['id_malla'] !== $id_malla) {
                $errores[] = 'La inscripción #' . $id_inscripcion . ' no pertenece a ' . $nombreCurso . '.';
                continue;
            }
            $certificado = certificado_obtener($pdo, $id_inscripcion, (int) $_SESSION['id_usuario']);
            $items[] = ['ins' => $inscripcion, 'cert' => $certificado];
        } catch (Throwable $e) {
            $errores[] = 'Inscripción #' . $id_inscripcion . ': ' . $e->getMessage();
        }
    }
}

$items = array_slice($items, 0, 300);

if (empty($items)) {
    masivos_pagina(
        'No fue posible generar los certificados',
        'No se encontraron certificados descargables para ' . $nombreCurso . '.',
        $errores
    );
}

$nombre = 'Certificados_' . $archivoCurso . '_' . date('Ymd_His') . '.pdf';
certificado_pdf_output($items, $nombre, true, certificado_config($pdo));
