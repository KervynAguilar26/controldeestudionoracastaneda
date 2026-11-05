<?php
// /sistema_nora/modulos/configuracion/api_infra_sincronizar.php
// ═══════════════════════════════════════════════════════════════════════════
// API de sincronización OFFLINE de infraestructura (ronda 5).
// Recibe un lote de acciones encoladas por el portal móvil (infra_movil.php):
//   [{ "uuid": "...", "accion": "crear_torre|crear_espacio|cambiar_estatus",
//      "datos": {...}, "fecha": "ISO" }, ...]
//
// IDEMPOTENCIA DOBLE:
//   1) Por UUID del cliente (tabla monitor_sincronizaciones): si el UUID ya
//      fue procesado, la acción se omite (re-sincronizar nunca duplica).
//   2) Por clave natural: torre por nombre, espacio por nombre+torre.
// El CSRF se verifica de forma central en config/db.php (header X-CSRF-Token
// o campo _csrf).
// ═══════════════════════════════════════════════════════════════════════════
require_once '../../config/db.php';

header('Content-Type: application/json');

// Control de acceso: Monitores y Administración
$roles_is = $_SESSION['roles_asignados'] ?? [$_SESSION['tipo_usuario'] ?? ''];
if (empty($_SESSION['id_usuario'])
    || !(in_array('superadmin', $roles_is) || in_array('admin', $roles_is)
         || in_array('monitor', $roles_is))) {
    http_response_code(403);
    echo json_encode(['exito' => false, 'mensaje' => 'Acceso restringido a Monitores de Campo.']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);
$acciones = $input['acciones'] ?? null;

if (!is_array($acciones) || empty($acciones)) {
    echo json_encode(['exito' => false, 'mensaje' => 'No hay acciones para sincronizar.']);
    exit();
}
if (count($acciones) > 200) {
    echo json_encode(['exito' => false, 'mensaje' => 'Lote demasiado grande (máx. 200 acciones).']);
    exit();
}

$id_monitor = (int)$_SESSION['id_usuario'];
$tipos_espacio_validos = ['Aula', 'Auditorio', 'Dormitorio/Residencia', 'Aula Virtual'];
$estatus_validos       = ['Disponible', 'En Mantenimiento'];

// Orden de procesamiento: primero torres (otros registros pueden referenciarlas)
$orden_accion = ['crear_torre' => 0, 'crear_espacio' => 1, 'cambiar_estatus' => 2];
usort($acciones, function ($a, $b) use ($orden_accion) {
    $pa = $orden_accion[$a['accion'] ?? ''] ?? 9;
    $pb = $orden_accion[$b['accion'] ?? ''] ?? 9;
    return $pa <=> $pb;
});

$procesados = 0;
$omitidos   = 0;
$errores    = [];
$procesados_uuids = [];
$omitidos_uuids   = [];

// Resolución torre_nombre -> id dentro del lote actual
$mapa_torres = [];

foreach ($acciones as $item) {
    $uuid  = trim($item['uuid'] ?? '');
    $accion = $item['accion'] ?? '';
    $datos = is_array($item['datos'] ?? null) ? $item['datos'] : [];
    $fecha_cliente = !empty($item['fecha']) ? date('Y-m-d H:i:s', strtotime($item['fecha'])) : null;

    if ($uuid === '' || strlen($uuid) > 64) {
        $errores[] = ['accion' => $accion, 'mensaje' => 'UUID inválido.'];
        continue;
    }

    try {
        // ── IDEMPOTENCIA POR UUID: registrar intención antes de procesar ──
        $stmt_id = $pdo->prepare("INSERT INTO public.monitor_sincronizaciones
                (uuid_cliente, id_usuario, modulo, accion, fecha_cliente)
              VALUES (:u, :i, 'infraestructura', :a, :f)
              ON CONFLICT (uuid_cliente) DO NOTHING");
        $stmt_id->execute([':u' => $uuid, ':i' => $id_monitor, ':a' => $accion, ':f' => $fecha_cliente]);

        if ($stmt_id->rowCount() === 0) {
            // UUID ya procesado en una sincronización anterior → omitir.
            $omitidos++;
            $omitidos_uuids[] = $uuid;
            continue;
        }

        $resultado = '';

        // ══════════ ACCIÓN: CREAR TORRE ══════════
        if ($accion === 'crear_torre') {
            $nombre  = trim($datos['nombre_torre'] ?? '');
            $pisos   = max(1, intval($datos['total_pisos'] ?? 1));
            $ubic    = trim($datos['ubicacion_sede'] ?? '') ?: 'Sede Principal';

            if ($nombre === '') {
                throw new Exception('La torre no tiene nombre.');
            }

            // Dedupe por clave natural: mismo nombre de torre ya existente
            $stmt_chk = $pdo->prepare("SELECT id_edificio FROM public.campus_edificios
                                       WHERE upper(trim(nombre_torre)) = upper(:n) LIMIT 1");
            $stmt_chk->execute([':n' => $nombre]);
            $id_torre = $stmt_chk->fetchColumn();

            if ($id_torre) {
                $resultado = "Torre '$nombre' ya existía (id $id_torre); no se duplicó.";
            } else {
                $stmt_t = $pdo->prepare("INSERT INTO public.campus_edificios
                        (nombre_torre, ubicacion_sede, total_pisos)
                     VALUES (:n, :u, :p) RETURNING id_edificio");
                $stmt_t->execute([':n' => $nombre, ':u' => $ubic, ':p' => $pisos]);
                $id_torre = $stmt_t->fetchColumn();
                $resultado = "Torre '$nombre' registrada ($pisos piso(s), id $id_torre).";
            }
            $mapa_torres[strtoupper($nombre)] = (int)$id_torre;
        }

        // ══════════ ACCIÓN: CREAR ESPACIO ══════════
        elseif ($accion === 'crear_espacio') {
            $tipo = $datos['tipo_espacio'] ?? '';
            if (!in_array($tipo, $tipos_espacio_validos, true)) {
                throw new Exception("Tipo de espacio no válido ($tipo).");
            }
            $nombre_esp = trim($datos['nombre_espacio'] ?? '');
            if ($nombre_esp === '') {
                throw new Exception('El espacio no tiene nombre.');
            }

            // Resolver la torre: id directo, nombre en el lote, o nombre en BD
            $id_edificio = !empty($datos['id_edificio']) ? (int)$datos['id_edificio'] : null;
            $torre_nombre = trim($datos['torre_nombre'] ?? '');

            if (!$id_edificio && $torre_nombre !== '') {
                $clave = strtoupper($torre_nombre);
                if (isset($mapa_torres[$clave])) {
                    $id_edificio = $mapa_torres[$clave];
                } else {
                    $stmt_tt = $pdo->prepare("SELECT id_edificio FROM public.campus_edificios
                                              WHERE upper(trim(nombre_torre)) = upper(:n) LIMIT 1");
                    $stmt_tt->execute([':n' => $torre_nombre]);
                    $id_edificio = $stmt_tt->fetchColumn() ?: null;
                }
                if (!$id_edificio) {
                    // La torre aún no existe: se crea con los datos mínimos
                    // (permite registrar offline una torre nueva + su espacio).
                    $stmt_nt = $pdo->prepare("INSERT INTO public.campus_edificios
                            (nombre_torre, ubicacion_sede, total_pisos)
                         VALUES (:n, 'Sede Principal', 1) RETURNING id_edificio");
                    $stmt_nt->execute([':n' => $torre_nombre]);
                    $id_edificio = $stmt_nt->fetchColumn();
                    $mapa_torres[$clave] = (int)$id_edificio;
                }
            }

            $piso  = max(0, intval($datos['piso_numero'] ?? 1));
            $cap   = max(0, intval($datos['capacidad_estudiantes'] ?? 30));
            $dorm  = max(0, intval($datos['cantidad_dormitorios'] ?? 0));
            $camas = max(0, intval($datos['camas_totales'] ?? 0));

            // Reajuste del piso al máximo real de la torre (misma regla del CRUD admin)
            if ($id_edificio) {
                $stmt_p = $pdo->prepare("SELECT total_pisos FROM public.campus_edificios WHERE id_edificio = :id");
                $stmt_p->execute([':id' => $id_edificio]);
                $max_pisos = $stmt_p->fetchColumn();
                if ($max_pisos && $piso > (int)$max_pisos) {
                    $piso = (int)$max_pisos;
                }
            }

            // Dedupe por clave natural: mismo nombre del espacio en la misma torre
            $stmt_ce = $pdo->prepare("SELECT id_espacio FROM public.campus_espacios
                                      WHERE upper(trim(nombre_espacio)) = upper(:n)
                                        AND tipo_espacio = :t
                                        AND id_edificio IS NOT DISTINCT FROM :e
                                      LIMIT 1");
            $stmt_ce->execute([':n' => $nombre_esp, ':t' => $tipo, ':e' => $id_edificio]);
            $id_existente = $stmt_ce->fetchColumn();

            if ($id_existente) {
                $resultado = "Espacio '$nombre_esp' ya existía (id $id_existente); no se duplicó.";
            } else {
                $stmt_e = $pdo->prepare("INSERT INTO public.campus_espacios
                        (id_edificio, tipo_espacio, nombre_espacio, piso_numero,
                         capacidad_estudiantes, cantidad_dormitorios, camas_totales)
                     VALUES (:ed, :t, :n, :p, :c, :d, :cm)");
                $stmt_e->execute([
                    ':ed' => $id_edificio, ':t' => $tipo, ':n' => $nombre_esp,
                    ':p' => $piso, ':c' => $cap, ':d' => $dorm, ':cm' => $camas
                ]);
                $resultado = "Espacio '$nombre_esp' ($tipo) registrado en la torre.";
            }
        }

        // ══════════ ACCIÓN: CAMBIAR ESTATUS ══════════
        elseif ($accion === 'cambiar_estatus') {
            $tabla = $datos['tabla'] ?? '';
            $id_target = intval($datos['id'] ?? 0);
            $estatus   = $datos['estatus'] ?? '';

            if (!in_array($tabla, ['torre', 'espacio'], true)) {
                throw new Exception('Tipo de infraestructura no válido.');
            }
            if ($id_target <= 0) {
                throw new Exception('Registro no válido para cambiar estatus.');
            }
            if (!in_array($estatus, $estatus_validos, true)) {
                throw new Exception('Estatus no válido.');
            }

            $tabla_bd = ($tabla === 'torre') ? 'public.campus_edificios' : 'public.campus_espacios';
            $col_id   = ($tabla === 'torre') ? 'id_edificio' : 'id_espacio';

            // Verificar que el registro exista antes de actualizar
            $stmt_ve = $pdo->prepare("SELECT 1 FROM $tabla_bd WHERE $col_id = :id");
            $stmt_ve->execute([':id' => $id_target]);
            if (!$stmt_ve->fetchColumn()) {
                throw new Exception("El registro solicitado (id $id_target) no existe en el servidor.");
            }

            if ($estatus === 'En Mantenimiento') {
                $motivo    = trim($datos['motivo_mantenimiento'] ?? '');
                $inspector = trim($datos['inspector_responsable'] ?? '');
                if ($motivo === '' || $inspector === '') {
                    throw new Exception('Debe indicar el motivo de la falla y el inspector responsable.');
                }
                $stmt_ce = $pdo->prepare("UPDATE $tabla_bd
                    SET estatus = 'En Mantenimiento', motivo_mantenimiento = :m,
                        inspector_responsable = :i, trabajo_realizado = NULL, usuario_apertura = NULL
                    WHERE $col_id = :id");
                $stmt_ce->execute([':m' => $motivo, ':i' => $inspector, ':id' => $id_target]);
                $resultado = "$tabla id $id_target inhabilitado por mantenimiento: $motivo.";
            } else {
                $trabajo  = trim($datos['trabajo_realizado'] ?? '');
                $apertura = trim($datos['usuario_apertura'] ?? '');
                if ($trabajo === '' || $apertura === '') {
                    throw new Exception('Debe indicar el trabajo realizado y la autoridad que apertura.');
                }
                $stmt_cd = $pdo->prepare("UPDATE $tabla_bd
                    SET estatus = 'Disponible', trabajo_realizado = :t, usuario_apertura = :u
                    WHERE $col_id = :id");
                $stmt_cd->execute([':t' => $trabajo, ':u' => $apertura, ':id' => $id_target]);
                $resultado = "$tabla id $id_target reaperturado como Disponible.";
            }
        }

        // ══════════ ACCIÓN DESCONOCIDA ══════════
        else {
            throw new Exception("Acción no reconocida ($accion).");
        }

        // Confirmar el resultado en el registro de sincronización
        $stmt_res = $pdo->prepare("UPDATE public.monitor_sincronizaciones
                                   SET resultado = :r WHERE uuid_cliente = :u");
        $stmt_res->execute([':r' => $resultado, ':u' => $uuid]);

        $procesados++;
        $procesados_uuids[] = $uuid;
    } catch (Exception $e) {
        // Liberar el UUID para que el monitor pueda corregir y reintentar
        $pdo->prepare("DELETE FROM public.monitor_sincronizaciones WHERE uuid_cliente = :u")
            ->execute([':u' => $uuid]);
        $errores[] = ['uuid' => $uuid, 'accion' => $accion, 'mensaje' => $e->getMessage()];
    }
}

echo json_encode([
    'exito'           => true,
    'procesados'      => $procesados,
    'omitidos'        => $omitidos,
    'errores'         => $errores,
    'procesados_uuids'=> $procesados_uuids,
    'omitidos_uuids'  => $omitidos_uuids
]);
