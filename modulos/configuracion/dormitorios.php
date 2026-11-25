<?php
// /sistema_nora/modulos/configuracion/dormitorios.php
// Pernocta vinculada SIEMPRE a un evento extraordinario (Foro/Conversatorio/Seminario).
// Registro simplificado: dormitorio + cédula; las fechas se derivan del foro.
// Fuente de verdad de las camas: public.dormitorios (id_dormitorio, capacidad_camas).
require_once '../../config/db.php';
require_once __DIR__ . '/../../includes/pernocta.php';
require_once '../../includes/header.php';
require_once '../../includes/buscador_lista.php';

$gestores = ['admin', 'control_estudio', 'profesor', 'recepcion'];
if (!tiene_permiso($gestores, $roles_array)) {
    echo "<div class='alert alert-danger font-weight-bold m-4'><i class='fas fa-ban mr-2'></i>Acceso restringido.</div>";
    require_once '../../includes/footer.php';
    exit();
}

$mensaje     = '';
$tipo_alerta = 'success';

// =============================================================================
// A. REGISTRAR PERNOCTA: solo dormitorio + cédula + foro
// =============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['registrar_pernocta'])) {
    try {
        $id_dormitorio = intval($_POST['id_dormitorio'] ?? 0);
        $cedula        = pernocta_cedula_limpia($_POST['cedula'] ?? '');
        $id_foro       = intval($_POST['id_foro'] ?? 0);
        $observaciones = trim((string) ($_POST['observaciones'] ?? ''));

        if ($id_dormitorio <= 0) { throw new Exception('Debe seleccionar un dormitorio válido.'); }
        if ($cedula === '') { throw new Exception('Indique únicamente el número de cédula del estudiante.'); }
        if ($id_foro <= 0) { throw new Exception('Debe seleccionar el Foro o Conversatorio al que pertenece la pernocta.'); }

        $pdo->beginTransaction();

        // Dormitorio con bloqueo de fila para evitar sobreocupación.
        $st = $pdo->prepare("SELECT id_dormitorio, nombre, planta, capacidad_camas, estatus
                               FROM public.dormitorios
                              WHERE id_dormitorio = :id FOR UPDATE");
        $st->execute([':id' => $id_dormitorio]);
        $dorm = $st->fetch(PDO::FETCH_ASSOC);
        if (!$dorm) { throw new Exception('El dormitorio seleccionado no existe.'); }
        if (!in_array($dorm['estatus'], ['Disponible', 'Ocupada'], true)) {
            throw new Exception('El dormitorio <strong>' . pernocta_e($dorm['nombre'])
                . '</strong> no está disponible (estatus: ' . pernocta_e($dorm['estatus']) . ').');
        }

        // El evento extraordinario es obligatorio y define las fechas de la cama.
        $st = $pdo->prepare("SELECT id_foro, codigo_foro, nombre, fecha_inicio, fecha_fin, estatus
                               FROM public.foros_eventos
                              WHERE id_foro = :id AND pernocta_habilitada = true
                                AND estatus IN ('Planificado', 'Activo') FOR UPDATE");
        $st->execute([':id' => $id_foro]);
        $foro = $st->fetch(PDO::FETCH_ASSOC);
        if (!$foro) {
            throw new Exception('El evento seleccionado no admite pernocta. Habilítelo en la configuración del evento.');
        }

        $d_ent = DateTime::createFromFormat('Y-m-d', (string) ($foro['fecha_inicio'] ?? '')) ?: new DateTime();
        $fecha_ent = $d_ent->format('Y-m-d');
        $hora_ent  = '08:00';
        $d_sal = DateTime::createFromFormat('Y-m-d', (string) ($foro['fecha_fin'] ?? '')) ?: (clone $d_ent)->modify('+1 day');
        if ($d_sal <= $d_ent) { $d_sal = (clone $d_ent)->modify('+1 day'); }
        $fecha_sal = $d_sal->format('Y-m-d');

        // Resolver estudiante por cédula (crea usuario desde el Padrón si hace falta).
        $res = pernocta_resolver_estudiante($pdo, $pdo_padron ?? null, $cedula);
        if (!$res['ok']) { throw new Exception($res['error']); }
        $id_usuario = $res['id_usuario'];
        $nombre_h   = $res['nombre'];

        // No dos pernoctas activas para la misma persona.
        $st = $pdo->prepare("SELECT p.id_pernocta, d.nombre
                               FROM public.dormitorio_pernoctas p
                               JOIN public.dormitorios d ON d.id_dormitorio = p.id_dormitorio
                              WHERE p.estado = 'En Curso'
                                AND (p.id_usuario = :u OR regexp_replace(p.cedula, '[^0-9]', '', 'g') = :ced)
                              LIMIT 1");
        $st->execute([':u' => $id_usuario, ':ced' => $cedula]);
        if ($dup = $st->fetch(PDO::FETCH_ASSOC)) {
            throw new Exception('<strong>' . pernocta_e($nombre_h) . '</strong> ya tiene una pernocta activa en '
                . pernocta_e($dup['nombre']) . '. Use "Mudanza" para cambiarlo de cuarto.');
        }

        // Capacidad atómica bajo la fila bloqueada del dormitorio.
        $st = $pdo->prepare("SELECT COUNT(*) FROM public.dormitorio_pernoctas
                              WHERE id_dormitorio = :d AND estado = 'En Curso'");
        $st->execute([':d' => $id_dormitorio]);
        $ocupadas = (int) $st->fetchColumn();
        if ($ocupadas >= (int) $dorm['capacidad_camas']) {
            $stq = $pdo->prepare("INSERT INTO public.dormitorio_lista_espera
                    (id_foro, id_usuario, cedula, nombre_completo, telefono, id_usuario_registro)
                    VALUES (:f, :u, :ced, :nom, :tel, :reg)
                    ON CONFLICT (id_foro, cedula) WHERE id_foro IS NOT NULL DO UPDATE SET
                        estatus = 'En Espera', fecha_solicitud = CURRENT_TIMESTAMP,
                        id_usuario = EXCLUDED.id_usuario");
            $stq->execute([
                ':f' => $id_foro, ':u' => $id_usuario, ':ced' => $cedula,
                ':nom' => $nombre_h, ':tel' => null, ':reg' => $_SESSION['id_usuario'],
            ]);
            $insc = pernocta_inscribir_foro($pdo, $id_foro, (int) $id_usuario);
            if (!empty($insc['error'])) { throw new Exception($insc['error']); }
            $pdo->commit();
            pernocta_bitacora($pdo, 'LISTA DE ESPERA: ' . $nombre_h . ' V-' . $cedula
                . ' -> dormitorio ' . $dorm['nombre'] . ' | foro ' . $foro['codigo_foro']);
            $mensaje = 'El dormitorio <strong>' . pernocta_e($dorm['nombre'])
                . '</strong> está lleno (' . $ocupadas . '/' . (int) $dorm['capacidad_camas']
                . '). <strong>' . pernocta_e($nombre_h) . '</strong> quedó en <strong>lista de espera</strong> del evento '
                . pernocta_e($foro['codigo_foro']) . ' y fue inscrito como participante.';
            $tipo_alerta = 'warning';
        } else {

        $motivo = 'Pernocta — ' . $foro['codigo_foro'] . ' ' . $foro['nombre'];
        $st = $pdo->prepare("INSERT INTO public.dormitorio_pernoctas
                (id_dormitorio, id_usuario, es_visitante_foraneo, nombre_completo, cedula, telefono,
                 id_cohorte, id_foro, fecha_entrada, hora_entrada, fecha_salida_prevista, motivo,
                 observaciones, id_usuario_registro, estado)
                VALUES (:d, :u, false, :nom, :ced, :tel, NULL, :foro, :fent, :hent, :fsal, :mot, :obs, :reg, 'En Curso')
                RETURNING id_pernocta");
        $st->execute([
            ':d'    => $id_dormitorio,
            ':u'    => $id_usuario,
            ':nom'  => mb_substr($nombre_h, 0, 150),
            ':ced'  => $cedula,
            ':tel'  => null,
            ':foro' => $id_foro,
            ':fent' => $fecha_ent,
            ':hent' => $hora_ent,
            ':fsal' => $fecha_sal,
            ':mot'  => mb_substr($motivo, 0, 255),
            ':obs'  => $observaciones !== '' ? mb_substr($observaciones, 0, 255) : null,
            ':reg'  => $_SESSION['id_usuario'],
        ]);
        $id_pernocta = (int) $st->fetchColumn();

        // Toda participación queda guardada en el expediente del estudiante.
        $insc = pernocta_inscribir_foro($pdo, $id_foro, (int) $id_usuario);
        if (!empty($insc['error'])) { throw new Exception($insc['error']); }

        $pdo->prepare("UPDATE public.dormitorio_lista_espera
                          SET estatus = 'Asignada', id_pernocta_asignada = :p
                        WHERE id_foro = :f AND cedula = :ced AND estatus = 'En Espera'")
            ->execute([':p' => $id_pernocta, ':f' => $id_foro, ':ced' => $cedula]);

        $pdo->commit();
        pernocta_bitacora($pdo, 'REGISTRO: ' . $nombre_h . ' V-' . $cedula . ' -> dormitorio '
            . $dorm['nombre'] . ' | foro ' . $foro['codigo_foro']);
        $mensaje = 'Pernocta registrada para <strong>' . pernocta_e($nombre_h)
            . '</strong> en <strong>' . pernocta_e($dorm['nombre']) . '</strong> ('
            . pernocta_e($dorm['planta'] ?: 'Planta no indicada') . ') del evento <strong>'
            . pernocta_e($foro['codigo_foro'] . ' — ' . $foro['nombre']) . '</strong>.';
        if ($res['creado']) {
            $mensaje .= ' Se creó su cuenta con la cédula como usuario (clave genérica Nora123456+-).';
        }
        if ($tipo_alerta !== 'warning') { $tipo_alerta = 'success'; }
        }
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
        $mensaje = 'Error al registrar la pernocta: ' . $e->getMessage();
        $tipo_alerta = 'danger';
    }
}

// =============================================================================
// A2. ALTA / EDICIÓN DE DORMITORIOS EN UNA PLANTA FÍSICA
// =============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_dormitorio'])) {
    try {
        $id_dorm = intval($_POST['id_dormitorio_edit'] ?? 0);
        $nombre  = trim((string) ($_POST['nombre_dormitorio'] ?? ''));
        $planta  = trim((string) ($_POST['planta'] ?? ''));
        $capacidad = intval($_POST['capacidad_camas'] ?? 0);
        $descripcion = trim((string) ($_POST['descripcion_dormitorio'] ?? ''));
        $estatus = $_POST['estatus_dormitorio'] ?? 'Disponible';

        if ($nombre === '') { throw new Exception('Indique el nombre del dormitorio.'); }
        if ($capacidad <= 0) { throw new Exception('La capacidad de camas debe ser mayor a cero.'); }
        if (!in_array($estatus, ['Disponible', 'Ocupada', 'En Mantenimiento', 'Inactiva'], true)) {
            throw new Exception('Estatus de dormitorio inválido.');
        }

        if ($id_dorm > 0) {
            $st = $pdo->prepare("UPDATE public.dormitorios
                                    SET nombre = :n, planta = :p, capacidad_camas = :c,
                                        descripcion = :d, estatus = :e
                                  WHERE id_dormitorio = :id");
            $st->execute([':n' => mb_substr($nombre, 0, 100),
                ':p' => $planta !== '' ? mb_substr($planta, 0, 80) : null,
                ':c' => $capacidad, ':d' => $descripcion !== '' ? mb_substr($descripcion, 0, 255) : null,
                ':e' => $estatus, ':id' => $id_dorm]);
            $accion = 'Dormitorio actualizado';
        } else {
            $st = $pdo->prepare("INSERT INTO public.dormitorios
                    (nombre, planta, capacidad_camas, descripcion, estatus)
                    VALUES (:n, :p, :c, :d, :e)");
            $st->execute([':n' => mb_substr($nombre, 0, 100),
                ':p' => $planta !== '' ? mb_substr($planta, 0, 80) : null,
                ':c' => $capacidad, ':d' => $descripcion !== '' ? mb_substr($descripcion, 0, 255) : null,
                ':e' => $estatus]);
            $accion = 'Dormitorio registrado';
        }
        pernocta_bitacora($pdo, $accion . ': ' . $nombre . ' | planta ' . ($planta ?: 'sin indicar'));
        $mensaje = $accion . ': <strong>' . pernocta_e($nombre) . '</strong> — '
            . pernocta_e($planta !== '' ? $planta : 'Sin planta asignada') . '.';
        $tipo_alerta = 'success';
    } catch (Throwable $e) {
        $mensaje = 'Error al guardar el dormitorio: ' . $e->getMessage();
        $tipo_alerta = 'danger';
    }
}

// =============================================================================
// B. FINALIZAR UNA PERNOCTA (libera cama)
// =============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['finalizar_pernocta'])) {
    try {
        $id_pern = intval($_POST['id_pernocta'] ?? 0);
        if ($id_pern <= 0) { throw new Exception('Identificador de pernocta inválido.'); }
        $st = $pdo->prepare("UPDATE public.dormitorio_pernoctas
                                SET estado = 'Finalizada', fecha_salida_real = CURRENT_TIMESTAMP
                              WHERE id_pernocta = :id AND estado = 'En Curso'");
        $st->execute([':id' => $id_pern]);
        if ($st->rowCount() === 0) { throw new Exception('La pernocta ya estaba finalizada o no existe.'); }
        pernocta_bitacora($pdo, 'FINALIZAR PERNOCTA #' . $id_pern);
        $mensaje = 'Pernocta finalizada. Cama liberada.';
        $tipo_alerta = 'success';
    } catch (Throwable $e) {
        $mensaje = 'Error al finalizar: ' . $e->getMessage();
        $tipo_alerta = 'danger';
    }
}

// =============================================================================
// C. ASIGNAR / CAMBIAR RESPONSABLE DEL CUARTO (uno solo, con motivo)
// =============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_responsable'])) {
    try {
        $id_dorm  = intval($_POST['id_dormitorio_resp'] ?? 0);
        $id_nuevo = intval($_POST['id_usuario_responsable'] ?? 0);
        $motivo   = trim((string) ($_POST['motivo_responsable'] ?? ''));
        $telefono = trim((string) ($_POST['telefono_responsable'] ?? ''));

        if ($id_dorm <= 0 || $id_nuevo <= 0) {
            throw new Exception('Seleccione el dormitorio y la persona responsable.');
        }

        $pdo->beginTransaction();
        $st = $pdo->prepare("SELECT id_dormitorio, nombre FROM public.dormitorios
                              WHERE id_dormitorio = :id FOR UPDATE");
        $st->execute([':id' => $id_dorm]);
        $dorm = $st->fetch(PDO::FETCH_ASSOC);
        if (!$dorm) { throw new Exception('Dormitorio no encontrado.'); }

        $st = $pdo->prepare("SELECT id_usuario, nombre, apellido, cedula, telefono FROM public.usuarios
                              WHERE id_usuario = :id");
        $st->execute([':id' => $id_nuevo]);
        $nuevo = $st->fetch(PDO::FETCH_ASSOC);
        if (!$nuevo) { throw new Exception('La persona seleccionada no existe.'); }

        $actual = pernocta_responsable_actual($pdo, $id_dorm);
        $cambio = $actual && (int) $actual['id_usuario'] !== $id_nuevo;
        if ($cambio && $motivo === '') {
            throw new Exception('Debe indicar el motivo del cambio de responsable (enfermedad, urgencia familiar, regreso a su estado, etc.).');
        }
        if ($cambio) {
            $st_vive = $pdo->prepare("SELECT COUNT(*) FROM public.dormitorio_pernoctas
                                       WHERE id_usuario = :u AND id_dormitorio = :d AND estado = 'En Curso'");
            $st_vive->execute([':u' => $id_nuevo, ':d' => $id_dorm]);
            if ((int) $st_vive->fetchColumn() === 0) {
                throw new Exception('La persona seleccionada no tiene una pernocta activa en este dormitorio. '
                    . 'El responsable debe ser alguien que haga vida en el cuarto.');
            }
        }
        if ($actual) {
            $pdo->prepare("UPDATE public.dormitorio_responsables
                              SET fecha_hasta = CURRENT_TIMESTAMP, motivo_cambio = :mot
                            WHERE id_responsable_registro = :id AND fecha_hasta IS NULL")
                ->execute([':mot' => $motivo !== '' ? mb_substr($motivo, 0, 500) : null,
                           ':id' => $actual['id_responsable_registro']]);
        }
        $pdo->prepare("INSERT INTO public.dormitorio_responsables
                (id_dormitorio, id_usuario, motivo_cambio, id_usuario_registro)
                VALUES (:d, :u, :mot, :reg)")
            ->execute([':d' => $id_dorm, ':u' => $id_nuevo,
                       ':mot' => $cambio ? mb_substr($motivo, 0, 500) : null,
                       ':reg' => $_SESSION['id_usuario']]);

        $tel_final = $telefono !== '' ? $telefono : (string) ($nuevo['telefono'] ?? '');
        $pdo->prepare("UPDATE public.dormitorios
                          SET id_responsable = :u, telefono_responsable = :tel, fecha_ultima_responsable = CURRENT_TIMESTAMP
                        WHERE id_dormitorio = :d")
            ->execute([':u' => $id_nuevo, ':tel' => $tel_final !== '' ? mb_substr($tel_final, 0, 30) : null,
                       ':d' => $id_dorm]);

        $pdo->commit();
        pernocta_bitacora($pdo, 'RESPONSABLE ' . ($cambio ? 'CAMBIADO' : 'ASIGNADO') . ' dormitorio '
            . $dorm['nombre'] . ' -> ' . trim($nuevo['nombre'] . ' ' . $nuevo['apellido'])
            . ($motivo !== '' ? ' | motivo: ' . $motivo : ''));
        $mensaje = ($cambio ? 'Responsable cambiado' : 'Responsable asignado') . ' en <strong>'
            . pernocta_e($dorm['nombre']) . '</strong>: <strong>'
            . pernocta_e(trim($nuevo['nombre'] . ' ' . $nuevo['apellido'])) . '</strong>.';
        $tipo_alerta = 'success';
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
        $mensaje = 'Error al guardar el responsable: ' . $e->getMessage();
        $tipo_alerta = 'danger';
    }
}

// =============================================================================
// D. MUDANZA a otro dormitorio (mismo evento)
// =============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['registrar_mudanza'])) {
    try {
        $id_pern  = intval($_POST['id_pernocta_mud'] ?? 0);
        $id_dest  = intval($_POST['id_dormitorio_destino'] ?? 0);
        $motivo   = trim((string) ($_POST['motivo_mudanza'] ?? ''));
        if ($id_pern <= 0 || $id_dest <= 0) { throw new Exception('Seleccione la pernocta y el dormitorio destino.'); }
        if ($motivo === '') { throw new Exception('Indique el motivo de la mudanza.'); }

        $pdo->beginTransaction();
        $st = $pdo->prepare("SELECT p.*, d.nombre AS origen FROM public.dormitorio_pernoctas p
                              JOIN public.dormitorios d ON d.id_dormitorio = p.id_dormitorio
                             WHERE p.id_pernocta = :id AND p.estado = 'En Curso' FOR UPDATE OF p");
        $st->execute([':id' => $id_pern]);
        $pern = $st->fetch(PDO::FETCH_ASSOC);
        if (!$pern) { throw new Exception('La pernocta no existe o ya finalizó.'); }
        if ((int) $pern['id_dormitorio'] === $id_dest) { throw new Exception('El huésped ya está en ese dormitorio.'); }

        $st = $pdo->prepare("SELECT id_dormitorio, nombre, capacidad_camas, estatus
                               FROM public.dormitorios WHERE id_dormitorio = :id FOR UPDATE");
        $st->execute([':id' => $id_dest]);
        $dest = $st->fetch(PDO::FETCH_ASSOC);
        if (!$dest || !in_array($dest['estatus'], ['Disponible', 'Ocupada'], true)) {
            throw new Exception('El dormitorio destino no está disponible.');
        }
        $st = $pdo->prepare("SELECT COUNT(*) FROM public.dormitorio_pernoctas
                              WHERE id_dormitorio = :d AND estado = 'En Curso'");
        $st->execute([':d' => $id_dest]);
        if ((int) $st->fetchColumn() >= (int) $dest['capacidad_camas']) {
            throw new Exception('El dormitorio destino <strong>' . pernocta_e($dest['nombre']) . '</strong> está lleno.');
        }

        $pdo->prepare("INSERT INTO public.dormitorio_mudanzas
                (id_pernocta, id_dormitorio_origen, id_dormitorio_destino, motivo, id_usuario_registro)
                VALUES (:p, :o, :d, :m, :reg)")
            ->execute([':p' => $id_pern, ':o' => $pern['id_dormitorio'], ':d' => $id_dest,
                       ':m' => mb_substr($motivo, 0, 500), ':reg' => $_SESSION['id_usuario']]);
        $pdo->prepare("UPDATE public.dormitorio_pernoctas SET id_dormitorio = :d WHERE id_pernocta = :p")
            ->execute([':d' => $id_dest, ':p' => $id_pern]);

        $pdo->commit();
        pernocta_bitacora($pdo, 'MUDANZA #' . $id_pern . ': ' . $pern['origen'] . ' -> ' . $dest['nombre'] . ' | ' . $motivo);
        $mensaje = 'Mudanza registrada: <strong>' . pernocta_e($pern['nombre_completo'])
            . '</strong> de ' . pernocta_e($pern['origen']) . ' a <strong>' . pernocta_e($dest['nombre']) . '</strong>.';
        $tipo_alerta = 'success';
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
        $mensaje = 'Error en la mudanza: ' . $e->getMessage();
        $tipo_alerta = 'danger';
    }
}

// =============================================================================
// E. PRÓRROGA (transporte no llegó, accidente, dificultad del transporte, etc.)
// =============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['registrar_prorroga'])) {
    try {
        $id_pern  = intval($_POST['id_pernocta_prorroga'] ?? 0);
        $dias     = intval($_POST['dias_concedidos'] ?? 0);
        $motivo   = trim((string) ($_POST['motivo_prorroga'] ?? ''));
        if ($id_pern <= 0) { throw new Exception('Seleccione una pernocta.'); }
        if ($dias < 1 || $dias > 30) { throw new Exception('Los días concedidos deben estar entre 1 y 30.'); }
        if ($motivo === '') { throw new Exception('Indique el motivo de la prórroga.'); }

        $pdo->beginTransaction();
        $st = $pdo->prepare("SELECT id_pernocta, nombre_completo, fecha_salida_prevista, estado
                               FROM public.dormitorio_pernoctas WHERE id_pernocta = :id FOR UPDATE");
        $st->execute([':id' => $id_pern]);
        $pern = $st->fetch(PDO::FETCH_ASSOC);
        if (!$pern || $pern['estado'] !== 'En Curso') { throw new Exception('La pernocta no está activa.'); }

        $anterior = $pern['fecha_salida_prevista'];
        $nueva = date('Y-m-d', strtotime($anterior . ' +' . $dias . ' days'));
        $pdo->prepare("INSERT INTO public.dormitorio_prorrogas
                (id_pernocta, dias_concedidos, fecha_salida_anterior, fecha_salida_nueva, motivo, id_usuario)
                VALUES (:p, :d, :ant, :nue, :mot, :reg)")
            ->execute([':p' => $id_pern, ':d' => $dias, ':ant' => $anterior, ':nue' => $nueva,
                       ':mot' => mb_substr($motivo, 0, 255), ':reg' => $_SESSION['id_usuario']]);
        $pdo->prepare("UPDATE public.dormitorio_pernoctas SET fecha_salida_prevista = :nue WHERE id_pernocta = :p")
            ->execute([':nue' => $nueva, ':p' => $id_pern]);
        $pdo->commit();

        pernocta_bitacora($pdo, 'PRORROGA #' . $id_pern . ': +' . $dias . ' días hasta ' . $nueva . ' | ' . $motivo);
        $mensaje = 'Prórroga concedida a <strong>' . pernocta_e($pern['nombre_completo'])
            . '</strong>: nueva fecha de salida <strong>' . $nueva . '</strong>.';
        $tipo_alerta = 'success';
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo->inTransaction()) { $pdo->rollBack(); }
        $mensaje = 'Error al conceder la prórroga: ' . $e->getMessage();
        $tipo_alerta = 'danger';
    }
}

// =============================================================================
// F. DAR DE BAJA DE LISTA DE ESPERA
// =============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancelar_espera'])) {
    try {
        $id_espera = intval($_POST['id_lista_espera'] ?? 0);
        $pdo->prepare("UPDATE public.dormitorio_lista_espera SET estatus = 'Cancelada'
                        WHERE id_lista_espera = :id AND estatus = 'En Espera'")
            ->execute([':id' => $id_espera]);
        $mensaje = 'La persona fue retirada de la lista de espera.';
        $tipo_alerta = 'success';
    } catch (Throwable $e) {
        $mensaje = 'Error: ' . $e->getMessage();
        $tipo_alerta = 'danger';
    }
}

// =============================================================================
// G. HABILITAR / CERRAR PERNOCTA DE UN FORO O CONVERSATORIO
// =============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_pernocta_foro'])) {
    try {
        $id_foro = intval($_POST['id_foro_evento'] ?? 0);
        $activar = isset($_POST['pernocta_habilitada']);
        if ($id_foro <= 0) { throw new Exception('Seleccione el evento.'); }
        $st = $pdo->prepare("UPDATE public.foros_eventos
                                SET pernocta_habilitada = :h
                              WHERE id_foro = :id AND estatus IN ('Planificado', 'Activo')");
        $st->execute([':h' => $activar ? 'true' : 'false', ':id' => $id_foro]);
        if ($st->rowCount() === 0) { throw new Exception('El evento no existe o ya finalizó.'); }
        pernocta_bitacora($pdo, ($activar ? 'HABILITAR' : 'DESHABILITAR') . ' PERNOCTA FORO #' . $id_foro);
        $mensaje = $activar
            ? 'Pernocta habilitada. Ya se pueden registrar dormitorios y cédulas para este evento.'
            : 'Pernocta deshabilitada para este evento.';
        $tipo_alerta = 'success';
    } catch (Throwable $e) {
        $mensaje = 'Error: ' . $e->getMessage();
        $tipo_alerta = 'danger';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['finalizar_foro_pernocta'])) {
    try {
        $id_foro = intval($_POST['id_foro_evento'] ?? 0);
        if ($id_foro <= 0) { throw new Exception('Seleccione el evento.'); }
        $st = $pdo->prepare("UPDATE public.foros_eventos
                                SET estatus = 'Finalizado', pernocta_habilitada = false
                              WHERE id_foro = :id");
        $st->execute([':id' => $id_foro]);
        if ($st->rowCount() === 0) { throw new Exception('El evento no existe.'); }
        pernocta_bitacora($pdo, 'FINALIZAR FORO PERNOCTA #' . $id_foro);
        $mensaje = 'Evento finalizado y pernocta cerrada.';
        $tipo_alerta = 'success';
    } catch (Throwable $e) {
        $mensaje = 'Error: ' . $e->getMessage();
        $tipo_alerta = 'danger';
    }
}

// =============================================================================
// Consultas para la vista
// =============================================================================
$dormitorios   = pernocta_dormitorios($pdo);
$foros         = [];
$foros_abiertos = pernocta_foros_disponibles($pdo);
try {
    $foros = $pdo->query("SELECT f.*,
                                 (SELECT COUNT(*) FROM public.foro_participantes p WHERE p.id_foro = f.id_foro) AS inscritos
                            FROM public.foros_eventos f
                           ORDER BY f.fecha_inicio DESC NULLS LAST, f.id_foro DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$pernoctas_activas = [];
try {
    $pernoctas_activas = $pdo->query("SELECT p.*, d.nombre AS dormitorio, d.planta, d.capacidad_camas,
                                             f.codigo_foro, f.nombre AS foro_nombre, f.tipo_foro
                                        FROM public.dormitorio_pernoctas p
                                        JOIN public.dormitorios d ON d.id_dormitorio = p.id_dormitorio
                                        LEFT JOIN public.foros_eventos f ON f.id_foro = p.id_foro
                                       WHERE p.estado = 'En Curso'
                                       ORDER BY d.planta NULLS LAST, d.nombre, p.fecha_entrada")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$lista_espera = [];
try {
    $lista_espera = $pdo->query("SELECT le.*, f.codigo_foro, f.nombre AS foro_nombre,
                                        d.nombre AS dormitorio_asignado
                                   FROM public.dormitorio_lista_espera le
                                   LEFT JOIN public.foros_eventos f ON f.id_foro = le.id_foro
                                   LEFT JOIN public.dormitorio_pernoctas p ON p.id_pernocta = le.id_pernocta_asignada
                                   LEFT JOIN public.dormitorios d ON d.id_dormitorio = p.id_dormitorio
                                  WHERE le.estatus = 'En Espera'
                                  ORDER BY le.fecha_solicitud")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$historial_responsables = [];
try {
    $historial_responsables = $pdo->query("SELECT r.*, d.nombre AS dormitorio,
                                                  CONCAT(u.nombre, ' ', COALESCE(u.apellido, '')) AS responsable
                                             FROM public.dormitorio_responsables r
                                             JOIN public.dormitorios d ON d.id_dormitorio = r.id_dormitorio
                                             JOIN public.usuarios u ON u.id_usuario = r.id_usuario
                                            ORDER BY r.fecha_desde DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$historial_mudanzas = [];
try {
    $historial_mudanzas = $pdo->query("SELECT m.*, do.nombre AS origen, dd.nombre AS destino,
                                              p.nombre_completo
                                         FROM public.dormitorio_mudanzas m
                                         JOIN public.dormitorios do ON do.id_dormitorio = m.id_dormitorio_origen
                                         JOIN public.dormitorios dd ON dd.id_dormitorio = m.id_dormitorio_destino
                                         JOIN public.dormitorio_pernoctas p ON p.id_pernocta = m.id_pernocta
                                        ORDER BY m.fecha_mudanza DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$prorrogas = [];
try {
    $prorrogas = $pdo->query("SELECT pr.*, p.nombre_completo, d.nombre AS dormitorio
                                FROM public.dormitorio_prorrogas pr
                                JOIN public.dormitorio_pernoctas p ON p.id_pernocta = pr.id_pernocta
                                JOIN public.dormitorios d ON d.id_dormitorio = p.id_dormitorio
                               ORDER BY pr.fecha_registro DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$usuarios_selector = [];
try {
    $usuarios_selector = $pdo->query("SELECT id_usuario, cedula, nombre, apellido FROM public.usuarios
                                       WHERE estatus_cuenta = 'Activo'
                                       ORDER BY apellido, nombre LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$por_planta = [];
foreach ($dormitorios as $d) {
    $planta = trim((string) ($d['planta'] ?? ''));
    $clave = $planta !== '' ? $planta : 'Sin planta asignada';
    $por_planta[$clave][] = $d;
}

// Vista "lista por cada dormitorio": agrupa las pernoctas activas por habitación.
// Un mismo dormitorio puede reutilizarse en varios foros en fechas distintas, por
// eso se muestra el evento de cada ocupante y se puede filtrar por foro.
$por_dormitorio = [];
foreach ($dormitorios as $d) {
    $por_dormitorio[(int) $d['id_dormitorio']] = [
        'dormitorio' => $d,
        'ocupantes'  => [],
    ];
}
foreach ($pernoctas_activas as $p) {
    $idD = (int) $p['id_dormitorio'];
    if (!isset($por_dormitorio[$idD])) {
        $por_dormitorio[$idD] = [
            'dormitorio' => [
                'id_dormitorio' => $idD,
                'nombre' => $p['dormitorio'],
                'planta' => $p['planta'],
                'capacidad_camas' => $p['capacidad_camas'],
                'responsable_nombre' => '',
            ],
            'ocupantes' => [],
        ];
    }
    $por_dormitorio[$idD]['ocupantes'][] = $p;
}
?>
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap">
        <h1 class="h3 font-weight-bold mb-0" style="color: #521f29;">
            <i class="fas fa-bed mr-2" style="color: #ff8a00;"></i>Pernocta y Dormitorios — Foros, Conversatorios y Seminarios
        </h1>
        <div class="d-flex flex-wrap">
            <a href="../foros/index.php" class="btn btn-sm font-weight-bold text-white shadow-sm mr-2 mb-2"
               style="background-color:#2b5876; border-radius:10px;">
                <i class="fas fa-comments mr-1"></i> Panel de Foros
            </a>
            <a href="../recepcion/registrar_estudiante_pernocta.php" class="btn font-weight-bold text-white shadow-sm mb-2"
               style="background-color: #521f29; border-radius: 10px;">
                <i class="fas fa-user-check mr-1 text-warning"></i> Pre-registro de participantes
            </a>
        </div>
    </div>

    <?php if ($mensaje !== ''): ?>
        <div class="alert alert-<?= pernocta_e($tipo_alerta) ?> font-weight-bold shadow-sm">
            <i class="fas fa-info-circle mr-2"></i><?= $mensaje ?>
        </div>
    <?php endif; ?>

    <!-- ===================== REGISTRO SIMPLIFICADO ===================== -->
    <div class="card shadow border-0 mb-4" style="border-radius: 12px;">
        <div class="card-header text-white font-weight-bold py-3" style="background-color: #521f29;">
            <i class="fas fa-moon mr-2" style="color: #ff8a00;"></i> Registrar Pernocta (Dormitorio + Cédula)
        </div>
        <div class="card-body bg-white p-4">
            <?php if (empty($foros_abiertos)): ?>
                <div class="alert alert-warning font-weight-bold mb-3">
                    <i class="fas fa-exclamation-triangle mr-2"></i>
                    No hay Foros/Conversatorios/Seminarios con pernocta habilitada. Habilítelos más abajo o desde
                    <a href="../foros/gestionar_evento.php" class="font-weight-bold">Configurar Evento y Malla</a>.
                </div>
            <?php endif; ?>
            <form method="POST" class="form-row">
                <?= csrf_field() ?>
                <div class="form-group col-md-4">
                    <label class="font-weight-bold">Dormitorio *</label>
                    <select name="id_dormitorio" class="form-control" required>
                        <option value="">— Seleccione —</option>
                        <?php foreach ($dormitorios as $d):
                            $libres = (int) $d['capacidad_camas'] - (int) $d['ocupadas'];
                        ?>
                            <option value="<?= (int) $d['id_dormitorio'] ?>">
                                <?= pernocta_e(trim($d['planta'] ?: 'Sin planta') . ' / ' . $d['nombre']
                                    . ' — ' . $d['ocupadas'] . '/' . $d['capacidad_camas'] . ' camas'
                                    . ($libres <= 0 ? ' (LLENO → lista de espera)' : '')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="form-text text-muted">En una planta física pueden existir varios dormitorios.</small>
                </div>
                <div class="form-group col-md-3">
                    <label class="font-weight-bold">Número de Cédula *</label>
                    <input type="text" name="cedula" class="form-control font-weight-bold" required
                           inputmode="numeric" maxlength="20" placeholder="Ej: 12345678"
                           oninput="this.value=this.value.replace(/[^0-9]/g,'')">
                    <small class="form-text text-muted">Con esa cédula se resuelve o crea la cuenta del estudiante.</small>
                </div>
                <div class="form-group col-md-3">
                    <label class="font-weight-bold">Evento extraordinario *</label>
                    <select name="id_foro" class="form-control" required>
                        <option value="">— Seleccione —</option>
                        <?php foreach ($foros_abiertos as $f): ?>
                            <option value="<?= (int) $f['id_foro'] ?>">
                                <?= pernocta_e($f['codigo_foro'] . ' — ' . $f['nombre']
                                    . ' (' . $f['tipo_foro'] . ')') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="form-text text-muted">La entrada y salida se toman de la malla del evento.</small>
                </div>
                <div class="form-group col-md-2">
                    <label class="font-weight-bold">Observaciones</label>
                    <input type="text" name="observaciones" class="form-control" maxlength="255">
                </div>
                <div class="form-group col-md-12 mb-0">
                    <button type="submit" name="registrar_pernocta" value="1"
                            class="btn font-weight-bold text-white py-2 px-4 shadow" style="background-color: #521f29; border-radius: 10px;">
                        <i class="fas fa-bed mr-2" style="color: #ff8a00;"></i> REGISTRAR PERNOCTA
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ===================== PLANTAS FÍSICAS Y DORMITORIOS ===================== -->
    <div class="card shadow border-0 mb-4" style="border-radius: 12px;">
        <div class="card-header text-white font-weight-bold py-3 d-flex justify-content-between align-items-center flex-wrap"
             style="background-color: #6c343e;">
            <span><i class="fas fa-building mr-2" style="color: #ff8a00;"></i> Plantas Físicas y Dormitorios</span>
            <button type="button" class="btn btn-sm btn-light font-weight-bold" data-toggle="modal" data-target="#modalDormitorio"
                    onclick="limpiarDormitorio()">
                <i class="fas fa-plus mr-1"></i> Nuevo dormitorio
            </button>
        </div>
        <div class="card-body bg-white p-0">
            <?php if (!empty($por_planta)): ?>
                <div class="px-4 pt-3"><?php buscador_lista_render('Buscar dormitorio o responsable…', '#listaDormitorios table'); ?></div>
            <?php endif; ?>
            <div id="listaDormitorios">
            <?php if (empty($por_planta)): ?>
                <p class="text-muted p-4 mb-0">No hay dormitorios registrados. Use <strong>Nuevo dormitorio</strong>.</p>
            <?php else: foreach ($por_planta as $planta => $dorms): ?>
                <div class="px-4 pt-3">
                    <h6 class="font-weight-bold mb-2" style="color:#521f29;">
                        <i class="fas fa-layer-group mr-2 text-warning"></i><?= pernocta_e($planta) ?>
                        <span class="badge badge-secondary"><?= count($dorms) ?> dormitorio(s)</span>
                    </h6>
                </div>
                <div class="table-responsive px-4 pb-3">
                    <table class="table table-sm table-striped table-bordered mb-0 small align-middle">
                        <thead style="background-color:#ff8a00; color:#521f29;" class="font-weight-bold">
                            <tr><th>Dormitorio</th><th>Descripción</th><th class="text-center">Camas</th>
                                <th class="text-center">Ocupación</th><th>Estatus</th><th class="text-center">Acciones</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($dorms as $d):
                                $ocup = (int) $d['ocupadas'];
                                $cap  = (int) $d['capacidad_camas'];
                                $libres = max(0, $cap - $ocup);
                            ?>
                                <tr>
                                    <td class="font-weight-bold"><?= pernocta_e($d['nombre']) ?></td>
                                    <td><?= pernocta_e($d['descripcion'] ?? '—') ?></td>
                                    <td class="text-center"><?= $cap ?></td>
                                    <td class="text-center">
                                        <span class="badge <?= $libres > 0 ? 'badge-primary' : 'badge-danger' ?>">
                                            <?= $ocup ?> / <?= $cap ?> (<?= $libres ?> libres)
                                        </span>
                                    </td>
                                    <td><span class="badge badge-secondary"><?= pernocta_e($d['estatus']) ?></span></td>
                                    <td class="text-center text-nowrap">
                                        <button type="button" class="btn btn-sm btn-outline-primary"
                                                data-toggle="modal" data-target="#modalDormitorio"
                                                onclick='editarDormitorio(<?= json_encode([
                                                    'id' => (int) $d['id_dormitorio'],
                                                    'nombre' => $d['nombre'],
                                                    'planta' => $d['planta'] ?? '',
                                                    'capacidad' => (int) $d['capacidad_camas'],
                                                    'descripcion' => $d['descripcion'] ?? '',
                                                    'estatus' => $d['estatus'],
                                                ], JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
                                            <i class="fas fa-edit"></i> Editar
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-success font-weight-bold"
                                                data-toggle="modal" data-target="#modalResp<?= (int) $d['id_dormitorio'] ?>">
                                            <i class="fas fa-user-cog mr-1"></i> Responsable
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endforeach; endif; ?>
            </div>
        </div>
    </div>

    <!-- Modal único de alta/edición de dormitorio -->
    <div class="modal fade" id="modalDormitorio" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <form method="POST" class="modal-content">
                <?= csrf_field() ?>
                <input type="hidden" name="id_dormitorio_edit" id="dormId" value="0">
                <div class="modal-header text-white" style="background-color:#521f29;">
                    <h5 class="modal-title font-weight-bold"><i class="fas fa-door-open mr-2 text-warning"></i>
                        <span id="dormTitulo">Nuevo dormitorio</span></h5>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body bg-white">
                    <div class="form-group">
                        <label class="font-weight-bold">Planta física *</label>
                        <input type="text" name="planta" id="dormPlanta" class="form-control"
                               maxlength="80" placeholder="Ej: Planta 1, Planta 2" required>
                        <small class="form-text text-muted">Varios dormitorios pueden compartir la misma planta.</small>
                    </div>
                    <div class="form-group">
                        <label class="font-weight-bold">Nombre del dormitorio *</label>
                        <input type="text" name="nombre_dormitorio" id="dormNombre" class="form-control"
                               maxlength="100" required placeholder="Ej: Dormitorio A-1">
                    </div>
                    <div class="form-group">
                        <label class="font-weight-bold">Capacidad de camas *</label>
                        <input type="number" name="capacidad_camas" id="dormCapacidad" class="form-control"
                               min="1" max="500" value="10" required>
                    </div>
                    <div class="form-group">
                        <label class="font-weight-bold">Descripción</label>
                        <input type="text" name="descripcion_dormitorio" id="dormDescripcion" class="form-control" maxlength="255">
                    </div>
                    <div class="form-group mb-0">
                        <label class="font-weight-bold">Estatus</label>
                        <select name="estatus_dormitorio" id="dormEstatus" class="form-control">
                            <option>Disponible</option><option>Ocupada</option>
                            <option>En Mantenimiento</option><option>Inactiva</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer bg-white">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
                    <button type="submit" name="guardar_dormitorio" value="1" class="btn font-weight-bold text-white"
                            style="background-color:#521f29;">Guardar dormitorio</button>
                </div>
            </form>
        </div>
    </div>
    <script>
    function limpiarDormitorio() {
        document.getElementById('dormId').value = '0';
        document.getElementById('dormTitulo').textContent = 'Nuevo dormitorio';
        document.getElementById('dormPlanta').value = '';
        document.getElementById('dormNombre').value = '';
        document.getElementById('dormCapacidad').value = '10';
        document.getElementById('dormDescripcion').value = '';
        document.getElementById('dormEstatus').value = 'Disponible';
    }
    function editarDormitorio(d) {
        document.getElementById('dormId').value = d.id;
        document.getElementById('dormTitulo').textContent = 'Editar: ' + d.nombre;
        document.getElementById('dormPlanta').value = d.planta || '';
        document.getElementById('dormNombre').value = d.nombre || '';
        document.getElementById('dormCapacidad').value = d.capacidad;
        document.getElementById('dormDescripcion').value = d.descripcion || '';
        document.getElementById('dormEstatus').value = d.estatus;
    }
    </script>

    <!-- Modales de responsable por dormitorio -->
    <?php foreach ($dormitorios as $d):
        $resp_actual = pernocta_responsable_actual($pdo, (int) $d['id_dormitorio']);
        $es_cambio = (bool) $resp_actual;
        $ocupantes = [];
        try {
            $st_oc = $pdo->prepare("SELECT id_usuario, nombre_completo, cedula FROM public.dormitorio_pernoctas
                                     WHERE id_dormitorio = :d AND estado = 'En Curso' AND id_usuario IS NOT NULL
                                     ORDER BY nombre_completo");
            $st_oc->execute([':d' => $d['id_dormitorio']]);
            $ocupantes = $st_oc->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {}
    ?>
    <div class="modal fade" id="modalResp<?= (int) $d['id_dormitorio'] ?>" tabindex="-1" role="dialog">
        <div class="modal-dialog" role="document">
            <form method="POST" class="modal-content">
                <?= csrf_field() ?>
                <div class="modal-header text-white" style="background-color: #521f29;">
                    <h5 class="modal-title font-weight-bold"><i class="fas fa-user-cog mr-2 text-warning"></i>
                        <?= $es_cambio ? 'Cambiar' : 'Asignar' ?> responsable — <?= pernocta_e($d['nombre']) ?></h5>
                    <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                </div>
                <div class="modal-body bg-white">
                    <input type="hidden" name="id_dormitorio_resp" value="<?= (int) $d['id_dormitorio'] ?>">
                    <?php if ($es_cambio): ?>
                        <div class="alert alert-info small font-weight-bold">
                            Responsable actual: <strong><?= pernocta_e($resp_actual['nombre']) ?></strong>.
                            Al guardar se registrará en el historial. El nuevo responsable debe hacer vida en el cuarto.
                        </div>
                        <?php if (!empty($ocupantes)): ?>
                            <div class="alert alert-light small mb-3">
                                <strong class="d-block mb-1">Personas con pernocta activa en este cuarto:</strong>
                                <ul class="mb-0 pl-3">
                                    <?php foreach ($ocupantes as $oc): ?>
                                        <li><?= pernocta_e($oc['nombre_completo']) ?> (V-<?= pernocta_e($oc['cedula']) ?>)</li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                    <div class="form-group">
                        <label class="font-weight-bold">Persona responsable *</label>
                        <select name="id_usuario_responsable" class="form-control" required>
                            <option value="">— Seleccione —</option>
                            <?php foreach ($usuarios_selector as $u): ?>
                                <option value="<?= (int) $u['id_usuario'] ?>"
                                    <?= ($resp_actual && (int) $resp_actual['id_usuario'] === (int) $u['id_usuario']) ? 'selected' : '' ?>>
                                    <?= pernocta_e(trim($u['nombre'] . ' ' . $u['apellido'])) ?> (V-<?= pernocta_e($u['cedula']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="font-weight-bold">Teléfono del responsable</label>
                        <input type="text" name="telefono_responsable" class="form-control"
                               value="<?= pernocta_e($d['telefono_responsable'] ?? '') ?>" maxlength="30">
                    </div>
                    <?php if ($es_cambio): ?>
                        <div class="form-group">
                            <label class="font-weight-bold text-danger">Motivo del cambio *</label>
                            <select name="motivo_responsable" class="form-control" required>
                                <option value="">— Seleccione el motivo —</option>
                                <option>Enfermedad</option><option>Urgencia familiar</option>
                                <option>Tuvo que irse a su estado</option>
                                <option>Incumplimiento de responsabilidades</option>
                                <option>Solicitud voluntaria</option><option>Otro</option>
                            </select>
                        </div>
                    <?php else: ?>
                        <input type="hidden" name="motivo_responsable" value="Asignación inicial">
                    <?php endif; ?>
                </div>
                <div class="modal-footer bg-white">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
                    <button type="submit" name="guardar_responsable" value="1" class="btn font-weight-bold text-white"
                            style="background-color: #521f29;">Guardar responsable</button>
                </div>
            </form>
        </div>
    </div>
    <?php endforeach; ?>

    <!-- ===================== FOROS / CONVERSATORIOS CON PERNOCTA ===================== -->
    <div class="card shadow border-0 mb-4" style="border-radius: 12px;">
        <div class="card-header text-white font-weight-bold py-3" style="background-color: #6c343e;">
            <i class="fas fa-calendar-alt mr-2" style="color: #ff8a00;"></i> Eventos extraordinarios — Pernocta
        </div>
        <div class="card-body bg-white p-0">
            <div class="table-responsive">
                <table class="table table-sm table-striped table-bordered mb-0 small align-middle">
                    <thead style="background-color: #ff8a00; color: #521f29;" class="font-weight-bold">
                        <tr><th>Evento</th><th>Tipo</th><th>Inicio</th><th>Final</th>
                            <th class="text-center">Inscritos</th><th class="text-center">Pernocta</th>
                            <th class="text-center">Acciones</th></tr>
                    </thead>
                    <tbody>
                        <?php if (empty($foros)): ?>
                            <tr><td colspan="7" class="text-center text-muted py-4">
                                No hay foros, conversatorios ni seminarios registrados.
                                <a href="../foros/gestionar_evento.php" class="font-weight-bold">Crear evento</a>.
                            </td></tr>
                        <?php else: foreach ($foros as $f): ?>
                            <tr>
                                <td class="font-weight-bold">
                                    <?= pernocta_e($f['codigo_foro'] . ' — ' . $f['nombre']) ?>
                                </td>
                                <td><?= pernocta_e($f['tipo_foro']) ?></td>
                                <td><?= pernocta_e($f['fecha_inicio'] ?? '—') ?></td>
                                <td><?= pernocta_e($f['fecha_fin'] ?? '—') ?></td>
                                <td class="text-center"><?= (int) $f['inscritos'] ?></td>
                                <td class="text-center">
                                    <span class="badge badge-<?= $f['pernocta_habilitada'] ? 'success' : 'secondary' ?>">
                                        <?= $f['pernocta_habilitada'] ? 'Habilitada' : 'Deshabilitada' ?>
                                    </span>
                                    <br><small class="text-muted"><?= pernocta_e($f['estatus']) ?></small>
                                </td>
                                <td class="text-center text-nowrap">
                                    <?php if (in_array($f['estatus'], ['Planificado', 'Activo'], true)): ?>
                                        <form method="POST" class="d-inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id_foro_evento" value="<?= (int) $f['id_foro'] ?>">
                                            <?php if ($f['pernocta_habilitada']): ?>
                                                <input type="hidden" name="pernocta_habilitada" value="">
                                            <?php endif; ?>
                                            <button type="submit" name="toggle_pernocta_foro" value="1"
                                                    class="btn btn-sm font-weight-bold <?= $f['pernocta_habilitada'] ? 'btn-outline-danger' : 'btn-success' ?>">
                                                <i class="fas <?= $f['pernocta_habilitada'] ? 'fa-lock' : 'fa-unlock' ?> mr-1"></i>
                                                <?= $f['pernocta_habilitada'] ? 'Cerrar' : 'Habilitar' ?>
                                            </button>
                                        </form>
                                        <form method="POST" class="d-inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id_foro_evento" value="<?= (int) $f['id_foro'] ?>">
                                            <button type="submit" name="finalizar_foro_pernocta" value="1"
                                                    class="btn btn-sm btn-outline-secondary font-weight-bold"
                                                    onclick="return confirm('¿Finalizar el evento y cerrar su pernocta?');">
                                                <i class="fas fa-flag-checkered mr-1"></i> Finalizar
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span class="text-muted">Evento finalizado</span>
                                    <?php endif; ?>
                                    <a href="../foros/gestionar_evento.php?id=<?= (int) $f['id_foro'] ?>"
                                       class="btn btn-sm btn-info font-weight-bold" title="Configurar malla">
                                        <i class="fas fa-sitemap"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ===================== LISTA POR CADA DORMITORIO ===================== -->
    <div class="card shadow border-0 mb-4" id="listaPorDormitorio" style="border-radius: 12px;">
        <div class="card-header text-white font-weight-bold py-3" style="background-color: #521f29;">
            <i class="fas fa-list-ul mr-2 text-warning"></i> Lista por cada dormitorio
        </div>
        <div class="card-body bg-light p-3">
            <div class="d-flex flex-wrap align-items-end justify-content-between mb-3">
                <div style="min-width:260px; flex:1;">
                    <label class="font-weight-bold small mb-1">Filtrar por evento</label>
                    <select id="filtroForoDormitorio" class="form-control form-control-sm font-weight-bold">
                        <option value="">Todos los eventos</option>
                        <?php
                        $foros_en_pernocta = [];
                        foreach ($pernoctas_activas as $p) {
                            if (!empty($p['id_foro'])) {
                                $foros_en_pernocta[(int) $p['id_foro']] = $p['codigo_foro'] . ' · ' . $p['foro_nombre'];
                            }
                        }
                        foreach ($foros_en_pernocta as $idF => $etiqueta): ?>
                            <option value="<?= (int) $idF ?>"><?= pernocta_e($etiqueta) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <small class="text-muted">Un dormitorio puede usarse en varios foros; elija el evento para ver solo sus ocupantes.</small>
                </div>
                <div class="ml-2">
                    <?php buscador_lista_render('Buscar ocupante por nombre o cédula…', '#listaPorDormitorio table'); ?>
                </div>
            </div>

            <?php if (empty($por_dormitorio)): ?>
                <div class="alert alert-info mb-0">No hay dormitorios registrados. Créelos desde Gestión de Infraestructura.</div>
            <?php else: ?>
                <?php foreach ($por_planta as $planta => $dorms_planta): ?>
                    <h6 class="font-weight-bold mt-3 mb-2" style="color:#521f29;">
                        <i class="fas fa-layer-group mr-1 text-warning"></i><?= pernocta_e($planta) ?>
                    </h6>
                    <div class="row">
                    <?php foreach ($dorms_planta as $d):
                        $idD = (int) $d['id_dormitorio'];
                        $ocup = $por_dormitorio[$idD]['ocupantes'];
                        $cap = (int) $d['capacidad_camas'];
                        $responsable = trim((string) ($d['responsable_nombre'] ?? ''));
                        $cedulaResp = trim((string) ($d['responsable_cedula'] ?? ''));
                    ?>
                        <div class="col-lg-6 mb-3">
                            <div class="card h-100 shadow-sm border-0" style="border-radius:10px;">
                                <div class="card-header bg-white py-2">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <strong style="color:#521f29;">
                                            <i class="fas fa-bed mr-1 text-warning"></i><?= pernocta_e($d['nombre']) ?>
                                        </strong>
                                        <span class="badge <?= count($ocup) >= $cap ? 'badge-danger' : 'badge-success' ?>">
                                            <?= count($ocup) ?> / <?= $cap ?> camas
                                        </span>
                                    </div>
                                    <small class="text-muted">
                                        Responsable:
                                        <strong><?= $responsable !== '' ? pernocta_e($responsable) : 'Sin responsable asignado' ?></strong>
                                        <?php if ($cedulaResp !== ''): ?> · V-<?= pernocta_e($cedulaResp) ?><?php endif; ?>
                                    </small>
                                </div>
                                <div class="card-body bg-white p-2">
                                    <?php if (empty($ocup)): ?>
                                        <p class="text-muted small mb-0">Sin ocupantes activos.</p>
                                    <?php else: ?>
                                        <div class="table-responsive">
                                            <table class="table table-sm table-hover mb-0 small">
                                                <thead class="thead-light">
                                                    <tr><th>Ocupante</th><th>Cédula</th><th>Evento</th><th>Entrada</th><th>Salida</th></tr>
                                                </thead>
                                                <tbody>
                                                <?php foreach ($ocup as $p): ?>
                                                    <tr data-id-foro="<?= (int) ($p['id_foro'] ?? 0) ?>">
                                                        <td class="font-weight-bold"><?= pernocta_e($p['nombre_completo']) ?></td>
                                                        <td>V-<?= pernocta_e($p['cedula'] ?? '—') ?></td>
                                                        <td>
                                                            <?php if (!empty($p['codigo_foro'])): ?>
                                                                <span class="badge badge-info"><?= pernocta_e($p['codigo_foro']) ?></span>
                                                            <?php else: ?> academia <?php endif; ?>
                                                        </td>
                                                        <td><?= date('d/m/Y', strtotime($p['fecha_entrada'])) ?></td>
                                                        <td><?= date('d/m/Y', strtotime($p['fecha_salida_prevista'])) ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    <script>
    (function () {
        var sel = document.getElementById('filtroForoDormitorio');
        if (!sel) { return; }
        function aplicarFiltroForo() {
            var foro = sel.value;
            document.querySelectorAll('#listaPorDormitorio tbody tr[data-id-foro]').forEach(function (fila) {
                var oculto = (foro !== '' && fila.getAttribute('data-id-foro') !== foro);
                fila.classList.toggle('filtro-oculto', oculto);
            });
            // Recalcula también el buscador por nombre/cédula.
            document.querySelectorAll('#listaPorDormitorio .buscador-lista input').forEach(function (input) {
                input.dispatchEvent(new Event('input', { bubbles: true }));
            });
        }
        sel.addEventListener('change', aplicarFiltroForo);
        aplicarFiltroForo();
    })();
    </script>

    <!-- ===================== PERNOCTAS EN CURSO ===================== -->
    <div class="card shadow border-0 mb-4" style="border-radius: 12px;">
        <div class="card-header text-white font-weight-bold py-3" style="background-color: #ff8a00;">
            <span style="color:#521f29;"><i class="fas fa-moon mr-2"></i> Pernoctas en Curso (<?= count($pernoctas_activas) ?>)</span>
        </div>
        <div class="card-body bg-white p-0">
            <?php if (!empty($pernoctas_activas)): ?>
                <div class="px-4 pt-3"><?php buscador_lista_render('Buscar huésped por nombre o cédula…', '#tablaPernoctasActivas table'); ?></div>
            <?php endif; ?>
            <div id="tablaPernoctasActivas">
            <div class="table-responsive">
                <table class="table table-sm table-striped table-bordered mb-0 small align-middle">
                    <thead style="background-color: #521f29; color: #fff;" class="font-weight-bold">
                        <tr>
                            <th>Huésped</th><th>Planta / Dormitorio</th><th>Evento</th>
                            <th class="text-center">Entrada</th><th class="text-center">Salida Prevista</th>
                            <th class="text-center" style="width: 25%;">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($pernoctas_activas)): ?>
                            <tr><td colspan="6" class="text-center text-muted py-4">No hay pernoctas activas.</td></tr>
                        <?php else: foreach ($pernoctas_activas as $p): ?>
                            <tr>
                                <td>
                                    <span class="font-weight-bold"><?= pernocta_e($p['nombre_completo']) ?></span>
                                    <br><small class="text-muted">V-<?= pernocta_e($p['cedula'] ?? '—') ?></small>
                                </td>
                                <td><?= pernocta_e(trim($p['planta'] ?: 'Sin planta') . ' / ' . $p['dormitorio']) ?></td>
                                <td>
                                    <?php if (!empty($p['codigo_foro'])): ?>
                                        <span class="badge badge-info"><?= pernocta_e($p['codigo_foro']) ?></span>
                                        <small class="d-block text-muted"><?= pernocta_e($p['foro_nombre']) ?></small>
                                    <?php else: ?>
                                        <small class="text-muted">Evento académico anterior</small>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center"><?= date('d/m/Y', strtotime($p['fecha_entrada'])) ?></td>
                                <td class="text-center font-weight-bold"><?= date('d/m/Y', strtotime($p['fecha_salida_prevista'])) ?></td>
                                <td class="text-center">
                                    <button type="button" class="btn btn-sm btn-info font-weight-bold mb-1"
                                            data-toggle="modal" data-target="#modalMud<?= (int) $p['id_pernocta'] ?>">
                                        <i class="fas fa-truck-moving mr-1"></i> Mudanza
                                    </button>
                                    <button type="button" class="btn btn-sm btn-warning font-weight-bold mb-1"
                                            data-toggle="modal" data-target="#modalProrroga<?= (int) $p['id_pernocta'] ?>">
                                        <i class="fas fa-clock mr-1"></i> Prórroga
                                    </button>
                                    <form method="POST" class="d-inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id_pernocta" value="<?= (int) $p['id_pernocta'] ?>">
                                        <button type="submit" name="finalizar_pernocta" value="1"
                                                class="btn btn-sm btn-success font-weight-bold mb-1"
                                                onclick="return confirm('¿Liberar la cama de este huésped?');">
                                            <i class="fas fa-check mr-1"></i> Finalizar
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
            </div>
        </div>
    </div>

    <!-- Modales de mudanza / prórroga -->
    <?php foreach ($pernoctas_activas as $p): ?>
        <div class="modal fade" id="modalMud<?= (int) $p['id_pernocta'] ?>" tabindex="-1" role="dialog">
            <div class="modal-dialog" role="document">
                <form method="POST" class="modal-content">
                    <?= csrf_field() ?>
                    <div class="modal-header text-white" style="background-color: #521f29;">
                        <h5 class="modal-title font-weight-bold"><i class="fas fa-truck-moving mr-2 text-warning"></i>
                            Mudanza — <?= pernocta_e($p['nombre_completo']) ?></h5>
                        <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                    </div>
                    <div class="modal-body bg-white">
                        <input type="hidden" name="id_pernocta_mud" value="<?= (int) $p['id_pernocta'] ?>">
                        <div class="form-group">
                            <label class="font-weight-bold">Dormitorio destino *</label>
                            <select name="id_dormitorio_destino" class="form-control" required>
                                <option value="">— Seleccione —</option>
                                <?php foreach ($dormitorios as $d):
                                    if ((int) $d['id_dormitorio'] === (int) $p['id_dormitorio']) { continue; }
                                    $libres = (int) $d['capacidad_camas'] - (int) $d['ocupadas'];
                                ?>
                                    <option value="<?= (int) $d['id_dormitorio'] ?>" <?= $libres <= 0 ? 'disabled' : '' ?>>
                                        <?= pernocta_e(trim($d['planta'] ?: 'Sin planta') . ' / ' . $d['nombre'] . ' (' . $libres . ' libres)') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="font-weight-bold">Motivo de la mudanza *</label>
                            <input type="text" name="motivo_mudanza" class="form-control" required
                                   placeholder="Ej: solicitud del estudiante, compatibilidad, mantenimiento">
                        </div>
                    </div>
                    <div class="modal-footer bg-white">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
                        <button type="submit" name="registrar_mudanza" value="1" class="btn btn-info font-weight-bold">Registrar mudanza</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="modal fade" id="modalProrroga<?= (int) $p['id_pernocta'] ?>" tabindex="-1" role="dialog">
            <div class="modal-dialog" role="document">
                <form method="POST" class="modal-content">
                    <?= csrf_field() ?>
                    <div class="modal-header text-white" style="background-color: #521f29;">
                        <h5 class="modal-title font-weight-bold"><i class="fas fa-clock mr-2 text-warning"></i>
                            Prórroga — <?= pernocta_e($p['nombre_completo']) ?></h5>
                        <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                    </div>
                    <div class="modal-body bg-white">
                        <input type="hidden" name="id_pernocta_prorroga" value="<?= (int) $p['id_pernocta'] ?>">
                        <div class="alert alert-info small font-weight-bold">
                            Salida prevista actual: <strong><?= date('d/m/Y', strtotime($p['fecha_salida_prevista'])) ?></strong>
                        </div>
                        <div class="form-group">
                            <label class="font-weight-bold">Días concedidos *</label>
                            <input type="number" name="dias_concedidos" class="form-control" value="1" min="1" max="30" required>
                        </div>
                        <div class="form-group">
                            <label class="font-weight-bold">Motivo de la prórroga *</label>
                            <select name="motivo_prorroga" class="form-control" required>
                                <option value="">— Seleccione —</option>
                                <option>El transporte no llegó</option><option>Accidente</option>
                                <option>Dificultad del transporte</option><option>Enfermedad</option>
                                <option>Urgencia familiar</option><option>Otro</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-footer bg-white">
                        <button type="button" class="btn btn-secondary" data-dismiss="modal">Cancelar</button>
                        <button type="submit" name="registrar_prorroga" value="1" class="btn btn-warning font-weight-bold">Conceder prórroga</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endforeach; ?>

    <!-- ===================== LISTA DE ESPERA ===================== -->
    <div class="card shadow border-0 mb-4" style="border-radius: 12px;">
        <div class="card-header text-white font-weight-bold py-3" style="background-color: #521f29;">
            <i class="fas fa-list-ol mr-2" style="color: #ff8a00;"></i> Lista de Espera (<?= count($lista_espera) ?>)
        </div>
        <div class="card-body bg-white p-0">
            <div class="table-responsive">
                <table class="table table-sm table-striped table-bordered mb-0 small align-middle">
                    <thead style="background-color: #ff8a00; color: #521f29;" class="font-weight-bold">
                        <tr><th>Persona</th><th>Cédula</th><th>Evento</th>
                            <th class="text-center">Solicitud</th><th class="text-center">Acción</th></tr>
                    </thead>
                    <tbody>
                        <?php if (empty($lista_espera)): ?>
                            <tr><td colspan="5" class="text-center text-muted py-4">No hay personas en lista de espera.</td></tr>
                        <?php else: foreach ($lista_espera as $le): ?>
                            <tr>
                                <td class="font-weight-bold"><?= pernocta_e($le['nombre_completo']) ?></td>
                                <td>V-<?= pernocta_e($le['cedula']) ?></td>
                                <td><?= pernocta_e($le['codigo_foro'] ? ($le['codigo_foro'] . ' — ' . $le['foro_nombre']) : 'Evento académico anterior') ?></td>
                                <td class="text-center"><?= date('d/m/Y H:i', strtotime($le['fecha_solicitud'])) ?></td>
                                <td class="text-center">
                                    <form method="POST" class="d-inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id_lista_espera" value="<?= (int) $le['id_lista_espera'] ?>">
                                        <button type="submit" name="cancelar_espera" value="1" class="btn btn-sm btn-outline-danger font-weight-bold"
                                                onclick="return confirm('¿Retirar de la lista de espera?');">
                                            <i class="fas fa-times mr-1"></i> Retirar
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ===================== HISTORIALES ===================== -->
    <div class="row">
        <div class="col-lg-4">
            <div class="card shadow border-0 mb-4" style="border-radius: 12px;">
                <div class="card-header text-white font-weight-bold py-3" style="background-color: #521f29;">
                    <i class="fas fa-history mr-2" style="color: #ff8a00;"></i> Historial de Responsables
                </div>
                <div class="card-body bg-white p-0">
                    <div class="table-responsive" style="max-height: 320px; overflow-y: auto;">
                        <table class="table table-sm table-bordered mb-0 small">
                            <thead class="font-weight-bold"><tr><th>Dormitorio</th><th>Responsable</th><th>Desde</th><th>Motivo</th></tr></thead>
                            <tbody>
                                <?php if (empty($historial_responsables)): ?>
                                    <tr><td colspan="4" class="text-center text-muted py-3">Sin registros.</td></tr>
                                <?php else: foreach ($historial_responsables as $h): ?>
                                    <tr>
                                        <td><?= pernocta_e($h['dormitorio']) ?></td>
                                        <td><?= pernocta_e($h['responsable']) ?></td>
                                        <td><?= date('d/m/Y H:i', strtotime($h['fecha_desde'])) ?></td>
                                        <td><?= pernocta_e($h['motivo_cambio'] ?? '—') ?></td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card shadow border-0 mb-4" style="border-radius: 12px;">
                <div class="card-header text-white font-weight-bold py-3" style="background-color: #521f29;">
                    <i class="fas fa-truck-moving mr-2" style="color: #ff8a00;"></i> Historial de Mudanzas
                </div>
                <div class="card-body bg-white p-0">
                    <div class="table-responsive" style="max-height: 320px; overflow-y: auto;">
                        <table class="table table-sm table-bordered mb-0 small">
                            <thead class="font-weight-bold"><tr><th>Persona</th><th>De</th><th>A</th><th>Motivo</th></tr></thead>
                            <tbody>
                                <?php if (empty($historial_mudanzas)): ?>
                                    <tr><td colspan="4" class="text-center text-muted py-3">Sin registros.</td></tr>
                                <?php else: foreach ($historial_mudanzas as $h): ?>
                                    <tr>
                                        <td><?= pernocta_e($h['nombre_completo']) ?></td>
                                        <td><?= pernocta_e($h['origen']) ?></td>
                                        <td><?= pernocta_e($h['destino']) ?></td>
                                        <td><?= pernocta_e($h['motivo']) ?></td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card shadow border-0 mb-4" style="border-radius: 12px;">
                <div class="card-header text-white font-weight-bold py-3" style="background-color: #521f29;">
                    <i class="fas fa-clock mr-2" style="color: #ff8a00;"></i> Historial de Prórrogas
                </div>
                <div class="card-body bg-white p-0">
                    <div class="table-responsive" style="max-height: 320px; overflow-y: auto;">
                        <table class="table table-sm table-bordered mb-0 small">
                            <thead class="font-weight-bold"><tr><th>Persona</th><th>Días</th><th>Nueva salida</th><th>Motivo</th></tr></thead>
                            <tbody>
                                <?php if (empty($prorrogas)): ?>
                                    <tr><td colspan="4" class="text-center text-muted py-3">Sin registros.</td></tr>
                                <?php else: foreach ($prorrogas as $pr): ?>
                                    <tr>
                                        <td><?= pernocta_e($pr['nombre_completo']) ?></td>
                                        <td class="text-center"><?= (int) $pr['dias_concedidos'] ?></td>
                                        <td><?= date('d/m/Y', strtotime($pr['fecha_salida_nueva'])) ?></td>
                                        <td><?= pernocta_e($pr['motivo']) ?></td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>
<?php require_once '../../includes/footer.php'; ?>
