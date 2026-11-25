<?php
// /sistema_nora/modulos/configuracion/gestion_infraestructura.php
require_once '../../config/db.php';
require_once '../../includes/espacios_disponibilidad.php';
require_once '../../includes/header.php';

// Control de acceso: solo administración puede modificar la infraestructura
if (!tiene_permiso(['admin', 'superadmin'], $roles_array)) {
    echo "<div class='alert alert-danger font-weight-bold shadow-sm'>Acceso restringido a la Administración del Sistema.</div>";
    require_once '../../includes/footer.php';
    exit();
}

$mensaje = '';
$tipo_alerta = '';

// Acciones válidas para el cambio de estatus
$estatus_validos = ['Disponible', 'En Mantenimiento'];
$tipos_espacio_validos = ['Aula', 'Auditorio', 'Dormitorio/Residencia', 'Aula Virtual'];

/** Escapa metacaracteres de regex ARE de PostgreSQL en un nombre de espacio. */
function refs_regex_escapar(string $texto): string {
    $especiales = ['\\', '.', '*', '+', '?', '(', ')', '[', ']', '{', '}', '|', '^', '$'];
    $reemplazos = ['\\\\', '\\.', '\\*', '\\+', '\\?', '\\(', '\\)', '\\[', '\\]', '\\{', '\\}', '\\|', '\\^', '\\$'];
    return str_replace($especiales, $reemplazos, $texto);
}

/**
 * Construye el array de patrones regex y sus parámetros para buscar referencias
 * a uno o varios espacios en cohortes/horarios/préstamos (columnas de texto).
 * Se usan límites de palabra (\y) para que "Aula 1" no coincida con "Aula 10".
 */
function refs_espacios_parametros(array $nombres): array {
    $nombres = array_values(array_filter(array_unique($nombres), 'strlen'));
    $items = [];
    $params = [];
    foreach ($nombres as $i => $nombre) {
        $key = ':n' . $i;
        $params[$key] = '\\y' . refs_regex_escapar($nombre) . '\\y';
        $items[] = $key;
    }
    return ['ARRAY[' . implode(', ', $items) . ']', $params];
}

/** Cuenta referencias de texto a los espacios indicados (para la confirmación). */
function contar_referencias_espacios(PDO $pdo, array $nombres): int {
    if (empty($nombres)) { return 0; }
    [$arr, $params] = refs_espacios_parametros($nombres);
    $sql = "SELECT
              (SELECT COUNT(*) FROM public.cohortes WHERE salon_aula ~* ANY($arr)) +
              (SELECT COUNT(*) FROM public.horarios_secciones WHERE salon_aula ~* ANY($arr)) +
              (SELECT COUNT(*) FROM public.prestamo_misiones WHERE salon_asignado ~* ANY($arr)) AS total";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

/**
 * Limpia las referencias de texto para que no queden aulas inexistentes.
 * horarios_sections permite NULL; cohortes y préstamos requieren texto.
 * Devuelve el número total de registros ajustados.
 */
function limpiar_referencias_espacios(PDO $pdo, array $nombres): int {
    if (empty($nombres)) { return 0; }
    [$arr, $params] = refs_espacios_parametros($nombres);
    $sentencias = [
        "UPDATE public.horarios_secciones SET salon_aula = NULL WHERE salon_aula ~* ANY($arr)",
        "UPDATE public.cohortes SET salon_aula = 'Por asignar' WHERE salon_aula ~* ANY($arr)",
        "UPDATE public.prestamo_misiones SET salon_asignado = 'Por asignar' WHERE salon_asignado ~* ANY($arr)",
    ];
    $total = 0;
    foreach ($sentencias as $sql) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $total += $stmt->rowCount();
    }
    return $total;
}

// A. REGISTRAR O EDITAR TORRE / EDIFICIO
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_torre'])) {
    try {
        $id_torre     = !empty($_POST['id_edificio']) ? intval($_POST['id_edificio']) : null;
        $nombre_torre = trim($_POST['nombre_torre']);
        $total_pisos  = intval($_POST['total_pisos']);
        $ubicacion    = trim($_POST['ubicacion_sede']);

        if ($id_torre) {
            $stmt = $pdo->prepare("UPDATE public.campus_edificios SET nombre_torre = :nom, ubicacion_sede = :ubi, total_pisos = :pisos WHERE id_edificio = :id");
            $stmt->execute([':nom' => $nombre_torre, ':ubi' => $ubicacion, ':pisos' => $total_pisos, ':id' => $id_torre]);
            $mensaje = "Torre <strong>{$nombre_torre}</strong> actualizada con éxito ({$total_pisos} pisos).";
        } else {
            $stmt = $pdo->prepare("INSERT INTO public.campus_edificios (nombre_torre, ubicacion_sede, total_pisos) VALUES (:nom, :ubi, :pisos)");
            $stmt->execute([':nom' => $nombre_torre, ':ubi' => $ubicacion, ':pisos' => $total_pisos]);
            $mensaje = "Torre <strong>{$nombre_torre}</strong> registrada con éxito ({$total_pisos} pisos).";
        }
        $tipo_alerta = "success";
    } catch (Exception $e) { $mensaje = "Error: " . $e->getMessage(); $tipo_alerta = "danger"; }
}

// B. ELIMINAR TORRE O ESPACIO (borrado físico en cascada, transaccional y auditado)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['eliminar_infraestructura'])) {
    try {
        $accion = $_POST['eliminar_infraestructura'];
        $id_del = intval($_POST['id_eliminar'] ?? 0);

        if ($id_del <= 0) {
            throw new Exception('Registro no válido para eliminar.');
        }

        $pdo->beginTransaction();

        $nombres_espacios = [];
        $num_espacios = 0;

        if ($accion === 'torre') {
            $stmt = $pdo->prepare("SELECT nombre_torre FROM public.campus_edificios WHERE id_edificio = :id");
            $stmt->execute([':id' => $id_del]);
            $nombre = $stmt->fetchColumn();
            if ($nombre === false) {
                throw new Exception('La torre / edificio ya no existe.');
            }

            // Los módulos y aulas se eliminan por CASCADE (FK campus_espacios.id_edificio).
            $stmt = $pdo->prepare("SELECT nombre_espacio FROM public.campus_espacios WHERE id_edificio = :id ORDER BY id_espacio");
            $stmt->execute([':id' => $id_del]);
            $nombres_espacios = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $num_espacios = count($nombres_espacios);
        } elseif ($accion === 'espacio') {
            $stmt = $pdo->prepare("SELECT nombre_espacio FROM public.campus_espacios WHERE id_espacio = :id");
            $stmt->execute([':id' => $id_del]);
            $nombre = $stmt->fetchColumn();
            if ($nombre === false) {
                throw new Exception('El espacio ya no existe.');
            }
            $nombres_espacios = [$nombre];
            $num_espacios = 1;
        } else {
            throw new Exception('Acción de eliminación no reconocida.');
        }

        // Evita que cohortes/horarios/préstamos sigan apuntando a un aula borrada.
        $refs_ajustadas = limpiar_referencias_espacios($pdo, $nombres_espacios);

        if ($accion === 'torre') {
            $stmt = $pdo->prepare("DELETE FROM public.campus_edificios WHERE id_edificio = :id");
        } else {
            $stmt = $pdo->prepare("DELETE FROM public.campus_espacios WHERE id_espacio = :id");
        }
        $stmt->execute([':id' => $id_del]);
        if ($stmt->rowCount() !== 1) {
            throw new Exception('No se pudo confirmar la eliminación.');
        }

        $accion_bit = ($accion === 'torre' ? 'TORRE ELIMINAR' : 'ESPACIO ELIMINAR')
            . ': ' . $nombre . ' (id ' . $id_del . ')'
            . ' - espacios:' . $num_espacios . ' referencias_ajustadas:' . $refs_ajustadas;
        $pdo->prepare("INSERT INTO public.bitacora (id_usuario, modulo, accion, ip_maquina)
                       VALUES (:u, 'INFRAESTRUCTURA', :a, :ip)")
            ->execute([
                ':u'  => $_SESSION['id_usuario'] ?? null,
                ':a'  => $accion_bit,
                ':ip' => $_SERVER['REMOTE_ADDR'] ?? 'local-cli',
            ]);

        $pdo->commit();

        $detalle = ($accion === 'torre')
            ? " Se eliminaron {$num_espacios} módulo(s)/aula(s) asociado(s)."
            : '';
        $mensaje = htmlspecialchars($nombre) . " eliminado correctamente." . $detalle
            . ($refs_ajustadas > 0 ? " Se ajustaron {$refs_ajustadas} referencia(s) en cohortes/horarios/misiones." : '');
        $tipo_alerta = 'warning';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $mensaje = "No se pudo eliminar el registro: " . $e->getMessage();
        $tipo_alerta = "danger";
    }
}

// C. REGISTRAR O EDITAR ESPACIO CON VALIDACIÓN ESTRICТА DE PISOS
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_espacio'])) {
    try {
        $id_espacio     = !empty($_POST['id_espacio']) ? intval($_POST['id_espacio']) : null;
        $id_edificio    = !empty($_POST['id_edificio']) ? intval($_POST['id_edificio']) : null;
        $tipo_espacio   = $_POST['tipo_espacio'];
        if (!in_array($tipo_espacio, $tipos_espacio_validos, true)) {
            throw new Exception('Categoría de espacio no válida.');
        }
        $nombre_espacio = trim($_POST['nombre_espacio'] ?? '');
        if ($nombre_espacio === '') {
            throw new Exception('El nombre o código del espacio es obligatorio.');
        }
        $piso_num       = intval($_POST['piso_numero'] ?? 1);
        $capacidad      = intval($_POST['capacidad_estudiantes'] ?? 0);
        $num_dorm       = intval($_POST['cantidad_dormitorios'] ?? 0);
        $camas          = intval($_POST['camas_totales'] ?? 0);
        $enlace_v       = trim($_POST['enlace_virtual'] ?? '');

        // VALIDACIÓN BACKEND: Limitar piso_num a los pisos reales de la torre
        if ($id_edificio) {
            $stmt_chk = $pdo->prepare("SELECT total_pisos FROM public.campus_edificios WHERE id_edificio = :id");
            $stmt_chk->execute([':id' => $id_edificio]);
            $max_pisos = $stmt_chk->fetchColumn();
            if ($max_pisos && $piso_num > $max_pisos) {
                $piso_num = $max_pisos; // Reajusta al máximo si venía un dato fuera de rango
            }
        }

        $detalle_dormitorios = '';
        if ($id_espacio) {
            $sql = "UPDATE public.campus_espacios SET id_edificio = :edif, tipo_espacio = :tipo, nombre_espacio = :nom, piso_numero = :piso, capacidad_estudiantes = :cap, cantidad_dormitorios = :dorm, camas_totales = :camas, enlace_virtual = :link WHERE id_espacio = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':edif' => $id_edificio, ':tipo' => $tipo_espacio, ':nom' => $nombre_espacio, ':piso' => $piso_num, ':cap' => $capacidad, ':dorm' => $num_dorm, ':camas' => $camas, ':link' => $enlace_v, ':id' => $id_espacio]);
            $mensaje = "Espacio <strong>{$nombre_espacio}</strong> actualizado correctamente (Piso {$piso_num}).";
        } else {
            $sql = "INSERT INTO public.campus_espacios (id_edificio, tipo_espacio, nombre_espacio, piso_numero, capacidad_estudiantes, cantidad_dormitorios, camas_totales, enlace_virtual) VALUES (:edif, :tipo, :nom, :piso, :cap, :dorm, :camas, :link)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':edif' => $id_edificio, ':tipo' => $tipo_espacio, ':nom' => $nombre_espacio, ':piso' => $piso_num, ':cap' => $capacidad, ':dorm' => $num_dorm, ':camas' => $camas, ':link' => $enlace_v]);$mensaje = "Espacio <strong>{$nombre_espacio}</strong> registrado correctamente (Piso {$piso_num}).";
        }
        if ($tipo_espacio === 'Dormitorio/Residencia') {
            $sync = infra_sincronizar_dormitorios($pdo, $nombre_espacio, $piso_num, $num_dorm, $camas);
            $detalle_dormitorios = $sync['creados'] > 0
                ? ' Se crearon ' . $sync['creados'] . ' dormitorio(s) reales para pernocta: ' . htmlspecialchars(implode(', ', $sync['nombres'])) . '.'
                : ' Los dormitorios de este espacio ya estaban registrados.';
            $mensaje .= $detalle_dormitorios;
        }
        $tipo_alerta = "success";
    } catch (Exception $e) {$mensaje = "Error: " . $e->getMessage();$tipo_alerta = "danger"; }
}

// C2. SINCRONIZAR DORMITORIOS REALES (public.dormitorios) DESDE INFRAESTRUCTURA
/**
 * Al registrar un espacio tipo "Dormitorio/Residencia" se crean las habitaciones
 * reales que usa la pernocta. No borra ni renombra dormitorios existentes.
 */
function infra_sincronizar_dormitorios(PDO $pdo, string $nombre_espacio, int $piso, int $cantidad, int $camas_totales): array
{
    $creados = 0;
    if ($cantidad <= 0 || $camas_totales <= 0) {
        return ['creados' => 0, 'nombres' => []];
    }
    $base = floor($camas_totales / $cantidad);
    $extra = $camas_totales % $cantidad;
    $nombres = [];
    $stExiste = $pdo->prepare('SELECT 1 FROM public.dormitorios WHERE nombre = :n');
    $stIns = $pdo->prepare("INSERT INTO public.dormitorios
        (nombre, descripcion, capacidad_camas, estatus, planta)
        VALUES (:n, :d, :c, 'Disponible', :p)");
    for ($i = 1; $i <= $cantidad; $i++) {
        $nombre = $nombre_espacio . ' - Dormitorio ' . $i;
        $stExiste->execute([':n' => $nombre]);
        if ($stExiste->fetchColumn()) {
            continue;
        }
        $capacidad = max(1, (int) $base + ($i <= $extra ? 1 : 0));
        $stIns->execute([
            ':n' => $nombre,
            ':d' => 'Creado desde Gestión de Infraestructura (Piso ' . $piso . ')',
            ':c' => $capacidad,
            ':p' => 'Piso ' . $piso,
        ]);
        $nombres[] = $nombre;
        $creados++;
    }
    return ['creados' => $creados, 'nombres' => $nombres];
}

// C3. ALTA DIRECTA DE UN DORMITORIO DE PERNOCTA DESDE INFRAESTRUCTURA
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_dormitorio_infra'])) {
    try {
        $nombre = trim($_POST['nombre_dormitorio'] ?? '');
        $planta = trim($_POST['planta_dormitorio'] ?? 'Piso 1');
        $capacidad = max(1, intval($_POST['capacidad_dormitorio'] ?? 1));
        $descripcion = trim($_POST['descripcion_dormitorio'] ?? 'Creado desde Gestión de Infraestructura');
        $cedula_resp = preg_replace('/\D+/', '', $_POST['cedula_responsable'] ?? '');
        if ($nombre === '') {
            throw new Exception('El nombre del dormitorio es obligatorio.');
        }
        $stExiste = $pdo->prepare('SELECT 1 FROM public.dormitorios WHERE nombre = :n');
        $stExiste->execute([':n' => $nombre]);
        if ($stExiste->fetchColumn()) {
            throw new Exception('Ya existe un dormitorio con ese nombre.');
        }
        $id_responsable = null;
        if ($cedula_resp !== '') {
            $stResp = $pdo->prepare('SELECT id_usuario FROM public.usuarios WHERE regexp_replace(cedula, \'[^0-9]\', \'\', \'g\') = :c LIMIT 1');
            $stResp->execute([':c' => $cedula_resp]);
            $id_responsable = $stResp->fetchColumn() ?: null;
            if ($id_responsable === null) {
                throw new Exception('La cédula del responsable no corresponde a un usuario del sistema.');
            }
        }
        $pdo->beginTransaction();
        $stIns = $pdo->prepare("INSERT INTO public.dormitorios
            (nombre, descripcion, capacidad_camas, id_responsable, estatus, planta)
            VALUES (:n, :d, :c, :r, 'Disponible', :p)");
        $stIns->execute([':n' => $nombre, ':d' => $descripcion, ':c' => $capacidad, ':r' => $id_responsable, ':p' => $planta]);
        $id_dorm = $pdo->lastInsertId('public.dormitorios_id_dormitorio_seq');
        if ($id_responsable) {
            $pdo->prepare("INSERT INTO public.dormitorio_responsables (id_dormitorio, id_usuario, motivo_cambio)
                           VALUES (:d, :u, 'Responsable asignado desde Gestión de Infraestructura')")
                ->execute([':d' => $id_dorm, ':u' => $id_responsable]);
        }
        $pdo->prepare("INSERT INTO public.bitacora (id_usuario, modulo, accion, ip_maquina)
                       VALUES (:u, 'INFRAESTRUCTURA', :a, :ip)")
            ->execute([
                ':u'  => $_SESSION['id_usuario'] ?? null,
                ':a'  => 'DORMITORIO CREAR: ' . $nombre . ' (capacidad ' . $capacidad . ', ' . $planta . ')',
                ':ip' => $_SERVER['REMOTE_ADDR'] ?? 'local-cli',
            ]);
        $pdo->commit();
        $mensaje = "Dormitorio <strong>" . htmlspecialchars($nombre) . "</strong> creado correctamente y disponible para pernoctas.";
        $tipo_alerta = 'success';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $mensaje = 'No se pudo crear el dormitorio: ' . $e->getMessage();
        $tipo_alerta = 'danger';
    }
}

// D. CAMBIAR ESTATUS (INHABILITAR / MANTENIMIENTO O HABILITAR / APERTURA)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cambiar_estatus'])) {
    try {
        $tabla         = $_POST['tabla_destino'] ?? '';
        $id_target     = intval($_POST['id_target'] ?? 0);
        $nuevo_estatus = $_POST['nuevo_estatus'] ?? '';

        if (!in_array($tabla, ['torre', 'espacio'], true)) {
            throw new Exception('Tipo de infraestructura no válido.');
        }
        if ($id_target <= 0) {
            throw new Exception('Registro no válido.');
        }
        if (!in_array($nuevo_estatus, $estatus_validos, true)) {
            throw new Exception('Estatus no válido.');
        }

        if ($nuevo_estatus === 'En Mantenimiento') {
            $motivo    = trim($_POST['motivo_mantenimiento'] ?? '');
            $inspector = trim($_POST['inspector_responsable'] ?? '');
            if ($motivo === '' || $inspector === '') {
                throw new Exception('Debe indicar el motivo de la falla y el inspector responsable.');
            }

            if ($tabla === 'torre') {
                $stmt =$pdo->prepare("UPDATE public.campus_edificios SET estatus = 'En Mantenimiento', motivo_mantenimiento = :m, inspector_responsable = :i, trabajo_realizado = NULL, usuario_apertura = NULL WHERE id_edificio = :id");
            } else {
                $stmt =$pdo->prepare("UPDATE public.campus_espacios SET estatus = 'En Mantenimiento', motivo_mantenimiento = :m, inspector_responsable = :i, trabajo_realizado = NULL, usuario_apertura = NULL WHERE id_espacio = :id");
            }
            $stmt->execute([':m' => $motivo, ':i' =>$inspector, ':id' => $id_target]);$mensaje = "Infraestructura inhabilitada por motivo de mantenimiento / inspección.";
        } else {
            $trabajo  = trim($_POST['trabajo_realizado'] ?? '');
            $apertura = trim($_POST['usuario_apertura'] ?? '');
            if ($trabajo === '' || $apertura === '') {
                throw new Exception('Debe indicar el trabajo realizado y la autoridad que apertura.');
            }

            if ($tabla === 'torre') {
                $stmt =$pdo->prepare("UPDATE public.campus_edificios SET estatus = 'Disponible', trabajo_realizado = :t, usuario_apertura = :u WHERE id_edificio = :id");
            } else {
                $stmt =$pdo->prepare("UPDATE public.campus_espacios SET estatus = 'Disponible', trabajo_realizado = :t, usuario_apertura = :u WHERE id_espacio = :id");
            }
            $stmt->execute([':t' => $trabajo, ':u' =>$apertura, ':id' => $id_target]);$mensaje = "Infraestructura habilitada y reaperturada tras culminación de trabajos.";
        }
        $tipo_alerta = "info";
    } catch (Exception $e) {$mensaje = "Error al cambiar estatus: " . $e->getMessage();$tipo_alerta = "danger"; }
}

// Cargar Torres y Espacios
$torres =$pdo->query("SELECT * FROM public.campus_edificios ORDER BY id_edificio ASC")->fetchAll();
$espacios =$pdo->query("SELECT e.*, t.nombre_torre, t.total_pisos AS edificio_max_pisos FROM public.campus_espacios e LEFT JOIN public.campus_edificios t ON e.id_edificio = t.id_edificio ORDER BY e.id_espacio DESC")->fetchAll();

// Estado dinámico: BLOQUEADO solo dentro de la franja horaria asignada hoy;
// DESBLOQUEADO fuera de ella. Se deriva de los horarios cargados, no de un
// bloqueo manual.
$estado_horarios_por_espacio = [];
foreach ($espacios as $esp_estado) {
    $estado_horarios_por_espacio[$esp_estado['id_espacio']] = espacios_estado_actual(
        $pdo,
        (string) $esp_estado['nombre_espacio']
    );
}

// Conteos de impacto para la confirmación de borrado en cascada
$espacios_por_torre = [];
foreach ($espacios as $esp) {
    if (!empty($esp['id_edificio'])) {
        $espacios_por_torre[$esp['id_edificio']][] = $esp['nombre_espacio'];
    }
}
$refs_por_torre = [];
foreach ($espacios_por_torre as $id_torre => $nombres) {
    $refs_por_torre[$id_torre] = contar_referencias_espacios($pdo, $nombres);
}
$refs_por_espacio = [];
foreach ($espacios as $esp) {
    $refs_por_espacio[$esp['id_espacio']] = contar_referencias_espacios($pdo, [$esp['nombre_espacio']]);
}
?>

<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <h1 class="h3 mb-0 font-weight-bold" style="color: #521f29;">
        <i class="fas fa-building mr-2 text-warning"></i>Infraestructura y Espacios del Campus
    </h1>
    <div>
        <button class="btn font-weight-bold text-white shadow-sm mr-1" style="background-color: #521f29;" data-toggle="modal" data-target="#modalTorre" onclick="limpiarFormTorre()">
            <i class="fas fa-plus-circle mr-1 text-warning"></i> Registrar Torre / Edificio
        </button>
        <button class="btn font-weight-bold text-white shadow-sm" style="background-color: #ff8a00;" data-toggle="modal" data-target="#modalEspacio" onclick="limpiarFormEspacio()">
            <i class="fas fa-door-open mr-1"></i> Registrar Aula / Auditorio / Pernocta
        </button>
        <button class="btn font-weight-bold text-white shadow-sm ml-1" style="background-color: #521f29;" data-toggle="modal" data-target="#modalDormitorioInfra">
            <i class="fas fa-bed mr-1 text-warning"></i> Registrar Dormitorio
        </button>
        <a class="btn btn-outline-dark font-weight-bold ml-1" href="dormitorios.php">
            <i class="fas fa-list mr-1"></i> Ver dormitorios y pernoctas
        </a>
    </div>
</div>

<?php if (!empty($mensaje)): ?>
    <div class="alert alert-<?= $tipo_alerta ?> alert-dismissible fade show font-weight-bold shadow-sm" role="alert">
        <?= $mensaje ?>
        <button type="button" class="close" data-dismiss="alert">&times;</button>
    </div>
<?php endif; ?>

<!-- BLOQUE DE TORRES REGISTRADAS CON BOTONES -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card shadow border-0" style="border-radius: 15px;">
            <div class="card-header bg-white py-3">
                <h6 class="m-0 font-weight-bold" style="color: #521f29;"><i class="fas fa-city mr-2 text-warning"></i>Torres y Edificios Registrados</h6>
            </div>
            <div class="card-body bg-light p-3">
                <div class="row">
                    <?php if (empty($torres)): ?>
                        <div class="col-12 text-center text-muted font-weight-bold py-2">No se han registrado torres.</div>
                    <?php else: ?>
                        <?php foreach ($torres as$tor): ?>
                            <div class="col-md-6 mb-3">
                                <div class="card shadow-sm bg-white p-3" style="border-radius: 10px; border-left: 5px solid <?= ($tor['estatus'] === 'En Mantenimiento') ? '#dc3545' : '#ff8a00' ?> !important;">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <h6 class="font-weight-bold mb-1" style="color: #521f29;"><?= htmlspecialchars($tor['nombre_torre']) ?></h6>
                                            <small class="text-muted d-block"><i class="fas fa-map-marker-alt mr-1"></i><?= htmlspecialchars($tor['ubicacion_sede']) ?></small>
                                            <small class="font-weight-bold text-primary"><i class="fas fa-layer-group mr-1"></i><?= $tor['total_pisos'] ?> Pisos Configurados</small>

                                            <?php if ($tor['estatus'] === 'En Mantenimiento'): ?>
                                                <div class="mt-2 p-2 rounded bg-danger text-white small font-weight-bold">
                                                    <i class="fas fa-exclamation-triangle mr-1"></i> INHABILITADO / MANTENIMIENTO<br>
                                                    Motivo: <?= htmlspecialchars($tor['motivo_mantenimiento']) ?><br>
                                                    Inspector: <?= htmlspecialchars($tor['inspector_responsable']) ?>
                                                </div>
                                            <?php elseif (!empty($tor['trabajo_realizado'])): ?>
                                                <div class="mt-2 p-2 rounded bg-light border text-dark small font-weight-bold">
                                                    <i class="fas fa-check-circle text-success mr-1"></i> Trabajo: <?= htmlspecialchars($tor['trabajo_realizado']) ?><br>
                                                    Habilitó: <?= htmlspecialchars($tor['usuario_apertura']) ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>

                                        <div class="text-right">
                                            <div class="btn-group mb-2">
                                                <button class="btn btn-sm btn-outline-secondary" onclick='editarTorre(<?= json_encode($tor) ?>)'><i class="fas fa-edit"></i></button>
                                                <button type="button" class="btn btn-sm btn-outline-danger" onclick='confirmarEliminar("torre", <?= $tor['id_edificio'] ?>, <?= json_encode($tor['nombre_torre']) ?>, <?= count($espacios_por_torre[$tor['id_edificio']] ?? []) ?>, <?= $refs_por_torre[$tor['id_edificio']] ?? 0 ?>)'><i class="fas fa-trash"></i></button>
                                            </div>
                                            <br>
                                            <?php if ($tor['estatus'] === 'En Mantenimiento'): ?>
                                                <button class="btn btn-xs btn-success font-weight-bold" onclick="modalCambiarEstatus('torre', <?= $tor['id_edificio'] ?>, 'Disponible')"><i class="fas fa-key mr-1"></i> Habilitar</button>
                                            <?php else: ?>
                                                <button class="btn btn-xs btn-danger font-weight-bold" onclick="modalCambiarEstatus('torre', <?= $tor['id_edificio'] ?>, 'En Mantenimiento')"><i class="fas fa-tools mr-1"></i> Inhabilitar / Inspección</button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- INVENTARIO DE ESPACIOS Y SALONES -->
<div class="card shadow border-0" style="border-radius: 15px;">
    <div class="card-header py-3 bg-white">
        <h6 class="m-0 font-weight-bold" style="color: #521f29;"><i class="fas fa-th-list mr-2 text-warning"></i>Inventario de Aulas, Auditorios y Pernocta</h6>
        <small class="text-muted d-block mt-1">
            <i class="fas fa-info-circle mr-1"></i>
            El estado se calcula automáticamente: <strong class="text-danger">BLOQUEADO</strong> solo durante la franja asignada hoy
            (día + hora de los horarios cargados); <strong class="text-success">DESBLOQUEADO</strong> fuera de esa franja.
            No requiere bloqueo manual.
        </small>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead style="background-color: #521f29; color: #ffffff;">
                    <tr>
                        <th>Ubicación / Torre</th>
                        <th>Tipo y Nombre</th>
                        <th>Piso Asignado</th>
                        <th>Capacidad</th>
                        <th>Estatus / Mantenimiento</th>
                        <th>Estado según Horarios (ahora)</th>
                        <th class="text-center">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($espacios)): ?>
                        <tr><td colspan="7" class="text-center py-4 text-muted font-weight-bold">No hay espacios registrados.</td></tr>
                    <?php else: ?>
                        <?php foreach ($espacios as$esp): ?>
                            <tr>
                                <td class="font-weight-bold" style="color: #521f29;"><?= htmlspecialchars($esp['nombre_torre'] ?? 'Campus Virtual') ?></td>
                                <td>
                                    <strong><?= htmlspecialchars($esp['nombre_espacio']) ?></strong><br>
                                    <small class="text-muted"><?= htmlspecialchars($esp['tipo_espacio']) ?></small>
                                </td>
                                <td><span class="badge badge-light border font-weight-bold">Piso <?= $esp['piso_numero'] ?></span></td>
                                <td><strong class="text-primary"><?= $esp['capacidad_estudiantes'] ?> Est.</strong></td>
                                <td>
                                    <?php if ($esp['estatus'] === 'En Mantenimiento'): ?>
                                        <span class="badge badge-danger p-2"><i class="fas fa-tools mr-1"></i> Inhabilitado</span><br>
                                        <small class="text-danger font-weight-bold">Motivo: <?= htmlspecialchars($esp['motivo_mantenimiento']) ?></small><br>
                                        <small class="text-muted">Inspector: <?= htmlspecialchars($esp['inspector_responsable']) ?></small>
                                    <?php else: ?>
                                        <span class="badge badge-success px-2 py-1"><i class="fas fa-check-circle mr-1"></i> Disponible</span>
                                        <?php if (!empty($esp['trabajo_realizado'])): ?>
                                            <small class="d-block text-muted">Trabajo: <?= htmlspecialchars($esp['trabajo_realizado']) ?></small>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php
                                    $estado_esp = $estado_horarios_por_espacio[$esp['id_espacio']] ?? [
                                        'estado' => 'DESBLOQUEADO',
                                        'motivo' => 'Sin asignación en la franja actual',
                                    ];
                                    $bloqueado_ahora = !empty($estado_esp['bloqueado']);
                                    ?>
                                    <span class="badge p-2 <?= $bloqueado_ahora ? 'badge-danger' : 'badge-success' ?>">
                                        <i class="fas <?= $bloqueado_ahora ? 'fa-lock' : 'fa-lock-open' ?> mr-1"></i>
                                        <?= htmlspecialchars($estado_esp['estado']) ?>
                                    </span>
                                    <small class="d-block text-muted mt-1"><?= htmlspecialchars($estado_esp['motivo']) ?></small>
                                </td>
                                <td class="text-center">
                                    <button class="btn btn-sm btn-warning text-dark font-weight-bold" onclick='editarEspacio(<?= json_encode($esp) ?>)'><i class="fas fa-edit"></i></button>
                                    <button type="button" class="btn btn-sm btn-danger font-weight-bold" onclick='confirmarEliminar("espacio", <?= $esp['id_espacio'] ?>, <?= json_encode($esp['nombre_espacio']) ?>, 0, <?= $refs_por_espacio[$esp['id_espacio']] ?? 0 ?>)'><i class="fas fa-trash"></i></button>
                                    <?php if ($esp['estatus'] === 'En Mantenimiento'): ?>
                                        <button class="btn btn-sm btn-success font-weight-bold" onclick="modalCambiarEstatus('espacio', <?= $esp['id_espacio'] ?>, 'Disponible')"><i class="fas fa-lock-open"></i> Habilitar</button>
                                    <?php else: ?>
                                        <button class="btn btn-sm btn-outline-danger font-weight-bold" onclick="modalCambiarEstatus('espacio', <?= $esp['id_espacio'] ?>, 'En Mantenimiento')"><i class="fas fa-wrench"></i> Inhabilitar</button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Formulario seguro de eliminación (solo POST) -->
<form method="POST" action="gestion_infraestructura.php" id="formEliminarInfra" class="d-none">
<?= csrf_field() ?>
    <input type="hidden" name="eliminar_infraestructura" id="del_accion" value="">
    <input type="hidden" name="id_eliminar" id="del_id" value="">
</form>

<!-- MODAL CAMBIAR ESTATUS (INHABILITAR / HABILITAR) -->
<div class="modal fade" id="modalEstatus" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content" style="border-radius: 15px;">
            <div class="modal-header text-white" style="background-color: #521f29;">
                <h5 class="modal-title font-weight-bold"><i class="fas fa-tools mr-2 text-warning"></i>Gestión de Inspección y Mantenimiento</h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <form method="POST" action="gestion_infraestructura.php">
            <?= csrf_field() ?>
                <div class="modal-body bg-light">
                    <input type="hidden" name="cambiar_estatus" value="1">
                    <input type="hidden" name="tabla_destino" id="estatus_tabla">
                    <input type="hidden" name="id_target" id="estatus_id">
                    <input type="hidden" name="nuevo_estatus" id="estatus_nuevo_val">

                    <div id="bloque_inhabilitar">
                        <div class="form-group mb-3">
                            <label class="font-weight-bold text-danger">Motivo de Inhabilitación / Falla *</label>
                            <textarea name="motivo_mantenimiento" class="form-control font-weight-bold" rows="3" placeholder="Ej: Filtración en techo / Daño en sistema eléctrico..."></textarea>
                        </div>
                        <div class="form-group mb-2">
                            <label class="font-weight-bold" style="color: #521f29;">Inspector / Técnico Responsable *</label>
                            <input type="text" name="inspector_responsable" class="form-control font-weight-bold" placeholder="Ej: Ing. Carlos Mendoza (Servicios Generales)">
                        </div>
                    </div>

                    <div id="bloque_habilitar" class="d-none">
                        <div class="form-group mb-3">
                            <label class="font-weight-bold text-success">Trabajo / Reparación Realizada *</label>
                            <textarea name="trabajo_realizado" class="form-control font-weight-bold" rows="3" placeholder="Ej: Pintura de paredes y reemplazo de luminarias completado..."></textarea>
                        </div>
                        <div class="form-group mb-2">
                            <label class="font-weight-bold" style="color: #521f29;">Autoridad que Apertura *</label>
                            <input type="text" name="usuario_apertura" class="form-control font-weight-bold" placeholder="Ej: Lic. Elena Pérez (Dirección)">
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-white">
                    <button type="button" class="btn btn-secondary font-weight-bold" data-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn font-weight-bold text-white" id="btnGuardarEstatus" style="background-color: #ff8a00;">Confirmar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL REGISTRAR / EDITAR TORRE -->
<div class="modal fade" id="modalTorre" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content" style="border-radius: 15px;">
            <div class="modal-header text-white" style="background-color: #521f29;">
                <h5 class="modal-title font-weight-bold"><i class="fas fa-building mr-2 text-warning"></i>Registrar / Editar Torre</h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <form method="POST" action="gestion_infraestructura.php">
            <?= csrf_field() ?>
                <div class="modal-body bg-light">
                    <input type="hidden" name="guardar_torre" value="1">
                    <input type="hidden" name="id_edificio" id="edit_id_edificio">

                    <div class="form-group mb-3">
                        <label class="font-weight-bold small" style="color: #521f29;">Nombre de la Torre / Edificio *</label>
                        <input type="text" name="nombre_torre" id="edit_nombre_torre" class="form-control font-weight-bold" placeholder="Ej: Torre A" required>
                    </div>
                    <div class="form-group mb-3">
                        <label class="font-weight-bold small" style="color: #521f29;">Sede / Ubicación *</label>
                        <input type="text" name="ubicacion_sede" id="edit_ubicacion_sede" class="form-control font-weight-bold" value="Sede Principal" required>
                    </div>
                    <div class="form-group mb-2">
                        <label class="font-weight-bold small" style="color: #521f29;">Cantidad de Pisos *</label>
                        <input type="number" name="total_pisos" id="edit_total_pisos" class="form-control font-weight-bold text-center" value="1" min="1" max="50" required>
                    </div>
                </div>
                <div class="modal-footer bg-white">
                    <button type="button" class="btn btn-secondary font-weight-bold" data-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn font-weight-bold text-white" style="background-color: #ff8a00;">Guardar Torre</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL REGISTRAR / EDITAR ESPACIO CON SELECTOR DE PISOS DINÁMICO RESTRINGIDO -->
<div class="modal fade" id="modalEspacio" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content" style="border-radius: 15px;">
            <div class="modal-header text-white" style="background-color: #521f29;">
                <h5 class="modal-title font-weight-bold"><i class="fas fa-door-open mr-2 text-warning"></i>Registrar / Editar Espacio</h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <form method="POST" action="gestion_infraestructura.php">
            <?= csrf_field() ?>
                <div class="modal-body bg-light">
                    <input type="hidden" name="guardar_espacio" value="1">
                    <input type="hidden" name="id_espacio" id="edit_id_espacio">

                    <div class="form-row">
                        <div class="col-md-6 mb-3">
                            <label class="font-weight-bold small" style="color: #521f29;">Categoría de Espacio *</label>
                            <select name="tipo_espacio" id="edit_tipo_espacio" class="form-control font-weight-bold" required>
                                <option value="Aula">Aula de Clases Estándar</option>
                                <option value="Auditorio">Auditorio / Eventos Masivos</option>
                                <option value="Dormitorio/Residencia">Pernocta / Residencia Estudiantil</option>
                                <option value="Aula Virtual">Aula Virtual / Plataforma Online</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="font-weight-bold small" style="color: #521f29;">Torre Perteneciente *</label>
                            <select name="id_edificio" id="edit_id_edificio_esp" class="form-control font-weight-bold" onchange="actualizarPisosDisponibles(this)">
                                <option value="">-- Seleccionar Torre --</option>
                                <?php foreach ($torres as$t): ?>
                                    <option value="<?= $t['id_edificio'] ?>" data-pisos="<?= $t['total_pisos'] ?>">
                                        <?= htmlspecialchars($t['nombre_torre']) ?> (<?=$t['total_pisos'] ?> Pisos)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="col-md-7 mb-3">
                            <label class="font-weight-bold small" style="color: #521f29;">Nombre o Código *</label>
                            <input type="text" name="nombre_espacio" id="edit_nombre_espacio" class="form-control font-weight-bold" placeholder="Ej: Aula 101" required>
                        </div>
                        
                        <!-- SELECTOR DINÁMICO RESTRINGIDO A LOS PISOS REALES -->
                        <div class="col-md-5 mb-3">
                            <label class="font-weight-bold small" style="color: #521f29;">Piso N° (Restringido según Torre) *</label>
                            <select name="piso_numero" id="select_piso_dinamico" class="form-control font-weight-bold text-center" required>
                                <option value="1">Piso 1</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group mb-2">
                        <label class="font-weight-bold small" style="color: #521f29;">Capacidad Máxima de Estudiantes / Aforo *</label>
                        <input type="number" name="capacidad_estudiantes" id="edit_capacidad_estudiantes" class="form-control font-weight-bold text-center" value="30" min="1">
                    </div>

                    <div class="form-row" id="bloqueDormitorios" style="display:none;">
                        <div class="col-md-6 mb-3">
                            <label class="font-weight-bold small" style="color: #521f29;">Cantidad de dormitorios</label>
                            <input type="number" name="cantidad_dormitorios" id="edit_cantidad_dormitorios" class="form-control font-weight-bold text-center" value="1" min="0">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="font-weight-bold small" style="color: #521f29;">Camas totales</label>
                            <input type="number" name="camas_totales" id="edit_camas_totales" class="form-control font-weight-bold text-center" value="1" min="0">
                        </div>
                        <div class="col-12">
                            <small class="text-muted">Al guardar un espacio de tipo “Pernocta / Residencia Estudiantil” se crean automáticamente los dormitorios reales usados por el módulo de Pernocta.</small>
                        </div>
                    </div>

                </div>
                <div class="modal-footer bg-white">
                    <button type="button" class="btn btn-secondary font-weight-bold" data-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn font-weight-bold text-white" style="background-color: #ff8a00;">Guardar Espacio</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- MODAL ALTA DIRECTA DE DORMITORIO DE PERNOCTA -->
<div class="modal fade" id="modalDormitorioInfra" tabindex="-1" role="dialog">
    <div class="modal-dialog" role="document">
        <div class="modal-content" style="border-radius: 15px;">
            <div class="modal-header text-white" style="background-color: #521f29;">
                <h5 class="modal-title font-weight-bold"><i class="fas fa-bed mr-2 text-warning"></i>Registrar Dormitorio (Pernocta)</h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <form method="POST" action="gestion_infraestructura.php">
                <?= csrf_field() ?>
                <input type="hidden" name="guardar_dormitorio_infra" value="1">
                <div class="modal-body bg-light">
                    <div class="form-group">
                        <label class="font-weight-bold small">Nombre del dormitorio *</label>
                        <input type="text" name="nombre_dormitorio" class="form-control font-weight-bold" placeholder="Ej: Dormitorio Norte 1" required>
                    </div>
                    <div class="form-row">
                        <div class="col-md-7 mb-3">
                            <label class="font-weight-bold small">Planta física</label>
                            <input type="text" name="planta_dormitorio" class="form-control font-weight-bold" value="Piso 1" placeholder="Ej: Piso 1 / Torre Norte">
                        </div>
                        <div class="col-md-5 mb-3">
                            <label class="font-weight-bold small">Capacidad (camas) *</label>
                            <input type="number" name="capacidad_dormitorio" class="form-control font-weight-bold text-center" value="5" min="1" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="font-weight-bold small">Cédula del responsable (opcional)</label>
                        <input type="text" name="cedula_responsable" class="form-control" placeholder="Ej: V-24388241">
                        <small class="text-muted">Debe corresponder a un usuario registrado en el sistema.</small>
                    </div>
                    <div class="form-group mb-0">
                        <label class="font-weight-bold small">Descripción</label>
                        <input type="text" name="descripcion_dormitorio" class="form-control" placeholder="Observaciones del dormitorio">
                    </div>
                </div>
                <div class="modal-footer bg-white">
                    <button type="button" class="btn btn-secondary font-weight-bold" data-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn font-weight-bold text-white" style="background-color: #521f29;">Crear dormitorio</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Mostrar campos de dormitorio solo cuando el tipo de espacio lo requiere
function actualizarBloqueDormitorios() {
    var tipo = document.getElementById('edit_tipo_espacio');
    var bloque = document.getElementById('bloqueDormitorios');
    if (!tipo || !bloque) { return; }
    bloque.style.display = (tipo.value === 'Dormitorio/Residencia') ? '' : 'none';
}
document.addEventListener('DOMContentLoaded', function () {
    var tipo = document.getElementById('edit_tipo_espacio');
    if (tipo) { tipo.addEventListener('change', actualizarBloqueDormitorios); }
    actualizarBloqueDormitorios();
});

// Confirmación y envío por POST de la eliminación de infraestructura
function confirmarEliminar(tipo, id, nombre, numEspacios, numRefs) {
    var detalle = '¿Está seguro de eliminar ' + (nombre || 'este registro') + '?';
    if (tipo === 'torre') {
        detalle += '\n\nSe eliminarán también ' + numEspacios + ' módulo(s)/aula(s) asociado(s).';
    }
    if (numRefs > 0) {
        detalle += '\nSe ajustarán ' + numRefs + ' referencia(s) en cohortes, horarios o misiones.';
    }
    detalle += '\n\nEsta acción no se puede deshacer.';
    if (!confirm(detalle)) {
        return false;
    }
    document.getElementById('del_accion').value = tipo;
    document.getElementById('del_id').value = id;
    document.getElementById('formEliminarInfra').submit();
    return false;
}

// Construir desplegable de pisos en base a la torre elegida
function actualizarPisosDisponibles(selectTorre, pisoSeleccionado = 1) {
    const optSelected = selectTorre.options[selectTorre.selectedIndex];
    const totalPisos = optSelected ? (parseInt(optSelected.getAttribute('data-pisos')) || 1) : 1;
    const selectPiso = document.getElementById('select_piso_dinamico');

    selectPiso.innerHTML = '';
    for (let i = 1; i <= totalPisos; i++) {
        const opt = document.createElement('option');
        opt.value = i;
        opt.innerText = `Piso ${i}`;
        if (i === parseInt(pisoSeleccionado)) { opt.selected = true; }
        selectPiso.appendChild(opt);
    }
}

function modalCambiarEstatus(tabla, id, nuevoEstatus) {
    document.getElementById('estatus_tabla').value = tabla;
    document.getElementById('estatus_id').value = id;
    document.getElementById('estatus_nuevo_val').value = nuevoEstatus;

    const bInhab = document.getElementById('bloque_inhabilitar');
    const bHab = document.getElementById('bloque_habilitar');
    const btn = document.getElementById('btnGuardarEstatus');

    if (nuevoEstatus === 'En Mantenimiento') {
        bInhab.classList.remove('d-none');
        bHab.classList.add('d-none');
        btn.className = 'btn font-weight-bold text-white btn-danger';
        btn.innerText = 'Confirmar Inhabilitación';
    } else {
        bInhab.classList.add('d-none');
        bHab.classList.remove('d-none');
        btn.className = 'btn font-weight-bold text-white btn-success';
        btn.innerText = 'Confirmar Habilitación';
    }

    $('#modalEstatus').modal('show');
}

function limpiarFormTorre() {
    document.getElementById('edit_id_edificio').value = '';
    document.getElementById('edit_nombre_torre').value = '';
    document.getElementById('edit_total_pisos').value = '1';
}

function limpiarFormEspacio() {
    document.getElementById('edit_id_espacio').value = '';
    document.getElementById('edit_nombre_espacio').value = '';
    document.getElementById('edit_capacidad_estudiantes').value = '30';
    document.getElementById('edit_cantidad_dormitorios').value = '1';
    document.getElementById('edit_camas_totales').value = '1';
    const selectTorre = document.getElementById('edit_id_edificio_esp');
    selectTorre.selectedIndex = 0;
    actualizarPisosDisponibles(selectTorre, 1);
    actualizarBloqueDormitorios();
}

function editarTorre(torre) {
    document.getElementById('edit_id_edificio').value = torre.id_edificio;
    document.getElementById('edit_nombre_torre').value = torre.nombre_torre;
    document.getElementById('edit_ubicacion_sede').value = torre.ubicacion_sede;
    document.getElementById('edit_total_pisos').value = torre.total_pisos;
    $('#modalTorre').modal('show');
}

function editarEspacio(espacio) {
    document.getElementById('edit_id_espacio').value = espacio.id_espacio;
    document.getElementById('edit_tipo_espacio').value = espacio.tipo_espacio;
    document.getElementById('edit_nombre_espacio').value = espacio.nombre_espacio;
    document.getElementById('edit_capacidad_estudiantes').value = espacio.capacidad_estudiantes;
    document.getElementById('edit_cantidad_dormitorios').value = espacio.cantidad_dormitorios || 1;
    document.getElementById('edit_camas_totales').value = espacio.camas_totales || 1;
    actualizarBloqueDormitorios();

    const selectTorre = document.getElementById('edit_id_edificio_esp');
    selectTorre.value = espacio.id_edificio;
    
    // Reconstruir la lista desplegable de pisos para la torre elegida y preseleccionar el piso correcto
    actualizarPisosDisponibles(selectTorre, espacio.piso_numero);

    $('#modalEspacio').modal('show');
}
</script>

<?php require_once '../../includes/footer.php'; ?>