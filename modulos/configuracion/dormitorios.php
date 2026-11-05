<?php
// /sistema_nora/modulos/configuracion/dormitorios.php
// Módulo optimizado de Pernoctas: Las habitaciones se administran exclusivamente
// desde el módulo de Infraestructura (tipo 'Dormitorio/Residencia').
require_once '../../config/db.php';
require_once '../../includes/header.php';

$gestores = ['admin', 'control_estudio', 'profesor'];
if (!tiene_permiso($gestores, $roles_array)) {
    echo "<div class='alert alert-danger font-weight-bold m-4'><i class='fas fa-ban mr-2'></i>Acceso restringido.</div>";
    require_once '../../includes/footer.php';
    exit();
}

$mensaje  = '';
$tipo_alerta = 'success';

/**
 * Ocupación actual de cada espacio de pernocta (pernoctas en curso).
 */
$fn_ocupacion = static function (PDO $pdo): array {
    $ocupadas = [];
    try {
        $filas = $pdo->query("SELECT id_espacio, COUNT(*) AS n
                              FROM public.dormitorio_pernoctas
                              WHERE estado = 'En Curso'
                              GROUP BY id_espacio")->fetchAll(PDO::FETCH_KEY_PAIR);
        $ocupadas = $filas;
    } catch (Throwable $e) {}
    return $ocupadas;
};

// =============================================================================
// A. REGISTRAR PERNOCTA
// =============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['registrar_pernocta'])) {
    try {
        $id_espacio   = intval($_POST['id_espacio'] ?? 0);
        $tipo_huesped = $_POST['tipo_huesped'] ?? 'usuario';
        $cedula_huesp = preg_replace('/[^0-9]/', '', $_POST['cedula_huesped'] ?? '');
        $nombre_foran = trim($_POST['nombre_foraneo'] ?? '');
        $tel_foran    = trim($_POST['telefono_foraneo'] ?? '');
        $id_cohorte   = !empty($_POST['id_cohorte']) ? intval($_POST['id_cohorte']) : null;
        $fecha_ent    = $_POST['fecha_entrada'] ?? date('Y-m-d');
        $hora_ent     = $_POST['hora_entrada'] ?? date('H:i');
        $fecha_sal    = $_POST['fecha_salida_prevista'] ?? date('Y-m-d', strtotime('+1 day'));
        $motivo       = trim($_POST['motivo'] ?? '');
        $observaciones= trim($_POST['observaciones'] ?? '');

        if ($id_espacio <= 0) {
            throw new Exception('Debe seleccionar una habitación o residencia válida.');
        }

        // Verificar que el espacio sea de tipo Dormitorio/Residencia en infraestructura
        $st = $pdo->prepare("SELECT e.*, COALESCE(t.nombre_torre, 'Sede Principal') AS torre 
                             FROM public.campus_espacios e 
                             LEFT JOIN public.campus_edificios t ON t.id_edificio = e.id_edificio 
                             WHERE e.id_espacio = :id AND e.tipo_espacio = 'Dormitorio/Residencia'");
        $st->execute([':id' => $id_espacio]);
        $espacio = $st->fetch(PDO::FETCH_ASSOC);
        
        if (!$espacio) {
            throw new Exception('El espacio seleccionado no está configurado como Dormitorio/Residencia en el módulo de Infraestructura.');
        }
        if ($espacio['estatus'] !== 'Disponible') {
            throw new Exception('La residencia <strong>' . htmlspecialchars($espacio['nombre_espacio']) . '</strong> se encuentra en estado: ' . $espacio['estatus'] . '.');
        }

        $d_ent = DateTime::createFromFormat('Y-m-d', $fecha_ent) ?: new DateTime();
        $fecha_ent = $d_ent->format('Y-m-d');
        $d_sal = DateTime::createFromFormat('Y-m-d', $fecha_sal) ?: (clone $d_ent)->modify('+1 day');
        if ($d_sal <= $d_ent) { $d_sal = (clone $d_ent)->modify('+1 day'); }
        $fecha_sal = $d_sal->format('Y-m-d');

        $id_usuario = null;
        $es_foraneo = false;
        $nombre_h = '';
        $cedula_h = $cedula_huesp !== '' ? $cedula_huesp : null;
        $tel_h = '';

        if ($tipo_huesped === 'usuario') {
            if ($cedula_huesp === '') {
                throw new Exception('Indique la cédula del usuario registrado.');
            }
            $st = $pdo->prepare("SELECT id_usuario, nombre, apellido, telefono FROM public.usuarios WHERE cedula = :c");
            $st->execute([':c' => $cedula_huesp]);
            $usu = $st->fetch(PDO::FETCH_ASSOC);
            if (!$usu) {
                throw new Exception('La cédula V-' . $cedula_huesp . ' no tiene cuenta en el sistema. Regístrela como visitante foráneo.');
            }
            $id_usuario = (int) $usu['id_usuario'];
            $nombre_h = trim($usu['nombre'] . ' ' . $usu['apellido']);
            $tel_h = trim((string) ($usu['telefono'] ?? ''));

            $st = $pdo->prepare("SELECT p.id_pernocta, e.nombre_espacio FROM public.dormitorio_pernoctas p
                                 JOIN public.campus_espacios e ON e.id_espacio = p.id_espacio
                                 WHERE p.id_usuario = :u AND p.estado = 'En Curso'");
            $st->execute([':u' => $id_usuario]);
            if ($dup = $st->fetch(PDO::FETCH_ASSOC)) {
                throw new Exception($nombre_h . ' ya tiene una pernocta activa en <strong>' . htmlspecialchars($dup['nombre_espacio']) . '</strong>.');
            }
        } else {
            $es_foraneo = true;
            if ($nombre_foran === '' || $cedula_huesp === '' || $tel_foran === '') {
                throw new Exception('Para visitantes foráneos, el nombre, la cédula y el teléfono son obligatorios.');
            }
            $nombre_h = trim($nombre_foran);
            $tel_h = trim($tel_foran);

            $st = $pdo->prepare("SELECT p.id_pernocta, e.nombre_espacio FROM public.dormitorio_pernoctas p
                                 JOIN public.campus_espacios e ON e.id_espacio = p.id_espacio
                                 WHERE p.cedula = :c AND p.estado = 'En Curso'");
            $st->execute([':c' => $cedula_huesp]);
            if ($dup = $st->fetch(PDO::FETCH_ASSOC)) {
                throw new Exception('La cédula V-' . $cedula_huesp . ' ya registra pernocta activa en <strong>' . htmlspecialchars($dup['nombre_espacio']) . '</strong>.');
            }
        }

        // Inserción validando capacidad máxima (capacidad_estudiantes del espacio físico)
        $sql = "INSERT INTO public.dormitorio_pernoctas
                    (id_espacio, id_usuario, es_visitante_foraneo, nombre_completo, cedula, telefono,
                     id_cohorte, fecha_entrada, hora_entrada, fecha_salida_prevista, motivo, observaciones,
                     id_usuario_registro, estado)
                SELECT :esp, :usu, :foran, :nom, :ced, :tel, :coh, :fent, :hent, :fsal, :mot, :obs, :reg, 'En Curso'
                WHERE (SELECT COUNT(*) FROM public.dormitorio_pernoctas
                       WHERE id_espacio = :esp2 AND estado = 'En Curso')
                      < (SELECT capacidad_estudiantes FROM public.campus_espacios WHERE id_espacio = :esp3)";
        $st = $pdo->prepare($sql);
        $ok = $st->execute([
            ':esp' => $id_espacio, ':esp2' => $id_espacio, ':esp3' => $id_espacio,
            ':usu' => $id_usuario, ':foran' => $es_foraneo ? 1 : 0, ':nom' => $nombre_h, ':ced' => $cedula_h,
            ':tel' => $tel_h !== '' ? $tel_h : null, ':coh' => $id_cohorte,
            ':fent' => $fecha_ent, ':hent' => $hora_ent, ':fsal' => $fecha_sal,
            ':mot' => $motivo !== '' ? $motivo : null, ':obs' => $observaciones !== '' ? $observaciones : null,
            ':reg' => $_SESSION['id_usuario'],
        ]);

        if (!$ok || $st->rowCount() === 0) {
            throw new Exception('La residencia <strong>' . htmlspecialchars($espacio['nombre_espacio']) . '</strong> ha alcanzado su capacidad máxima de camas.');
        }

        $mensaje = 'Pernocta registrada con éxito para <strong>' . htmlspecialchars($nombre_h) . '</strong> en <strong>' . htmlspecialchars($espacio['nombre_espacio']) . '</strong>.';
        $tipo_alerta = 'success';
    } catch (Exception $e) {
        $mensaje = 'Error al registrar la pernocta: ' . $e->getMessage();
        $tipo_alerta = 'danger';
    }
}

// =============================================================================
// B. FINALIZAR PERNOCTA
// =============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['finalizar_pernocta'])) {
    try {
        $id_pern = intval($_POST['id_pernocta'] ?? 0);
        $pdo->prepare("UPDATE public.dormitorio_pernoctas SET estado = 'Finalizada', fecha_salida_real = CURRENT_TIMESTAMP WHERE id_pernocta = :id")
            ->execute([':id' => $id_pern]);
        $mensaje = 'Pernocta finalizada correctamente. Cama liberada.';
        $tipo_alerta = 'success';
    } catch (Exception $e) {
        $mensaje = 'Error al finalizar: ' . $e->getMessage();
        $tipo_alerta = 'danger';
    }
}

// Consultas para la vista
$ocupadas_map = $fn_ocupacion($pdo);

// Cargar únicamente los espacios que son Dormitorios/Residencias desde Infraestructura
$residencias = [];
try {
    $residencias = $pdo->query("SELECT e.*, COALESCE(t.nombre_torre, 'Sede Principal') AS torre 
                                FROM public.campus_espacios e 
                                LEFT JOIN public.campus_edificios t ON t.id_edificio = e.id_edificio 
                                WHERE e.tipo_espacio = 'Dormitorio/Residencia' 
                                ORDER BY e.nombre_espacio")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$cohortes = [];
try {
    $cohortes = $pdo->query("SELECT c.id_cohorte, c.codigo_cohorte, m.nombre_programa, m.requiere_pernocta
                             FROM public.cohortes c
                             LEFT JOIN public.mallas_curriculares m ON m.id_malla = c.id_malla
                             WHERE c.estatus IN ('Planificada', 'En Curso')
                             ORDER BY c.fecha_inicio DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$pernoctas_activas = [];
try {
    $pernoctas_activas = $pdo->query("SELECT p.*, e.nombre_espacio AS dormitorio, e.capacidad_estudiantes AS capacidad_camas,
                                             c.codigo_cohorte, r.nombre || ' ' || r.apellido AS nombre_registro
                                      FROM public.dormitorio_pernoctas p
                                      JOIN public.campus_espacios e ON e.id_espacio = p.id_espacio
                                      LEFT JOIN public.cohortes c ON c.id_cohorte = p.id_cohorte
                                      LEFT JOIN public.usuarios r ON r.id_usuario = p.id_usuario_registro
                                      WHERE p.estado = 'En Curso'
                                      ORDER BY e.nombre_espacio, p.fecha_entrada")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}
?>

<div class="row justify-content-center my-4">
    <div class="col-xl-11">

        <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap">
            <h1 class="h3 font-weight-bold mb-0" style="color: #521f29;">
                <i class="fas fa-bed mr-2" style="color: #ff8a00;"></i>Control de Pernoctas y Residencias
            </h1>
            <a href="../configuracion/gestion_infraestructura.php" class="btn font-weight-bold text-white shadow-sm" style="background-color: #521f29; border-radius: 10px;">
                <i class="fas fa-building mr-1 text-warning"></i> Gestionar Residencias en Infraestructura
            </a>
        </div>

        <?php if (!empty($mensaje)): ?>
            <div class="alert alert-<?= $tipo_alerta ?> font-weight-bold shadow-sm">
                <i class="fas fa-info-circle mr-2"></i><?= $mensaje ?>
            </div>
        <?php endif; ?>

        <!-- ============================ RESUMEN DE HABITACIONES (DESDE INFRAESTRUCTURA) ============================ -->
        <div class="card shadow border-0 mb-4" style="border-radius: 12px; overflow: hidden;">
            <div class="card-header text-white font-weight-bold py-3" style="background-color: #521f29;">
                <i class="fas fa-door-open mr-2" style="color: #ff8a00;"></i> Estado de Residencias y Camas (Sincronizado con Infraestructura)
            </div>
            <div class="card-body bg-white p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-striped table-bordered mb-0 small align-middle">
                        <thead style="background-color: #ff8a00; color: #521f29;" class="font-weight-bold">
                            <tr>
                                <th>Residencia / Habitación</th>
                                <th>Torre / Ubicación</th>
                                <th class="text-center">Capacidad Camas</th>
                                <th class="text-center">Ocupación Actual</th>
                                <th class="text-center">Estatus Físico</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($residencias)): ?>
                                <tr><td colspan="5" class="text-center text-muted py-4">
                                    No hay espacios marcados como <strong>"Dormitorio/Residencia"</strong> en el módulo de Infraestructura. 
                                    <a href="../configuracion/gestion_infraestructura.php" class="font-weight-bold text-primary">Regístrelos aquí</a>.
                                </td></tr>
                            <?php else: foreach ($residencias as $r):
                                $ocup = (int) ($ocupadas_map[$r['id_espacio']] ?? 0);
                                $cap  = (int) $r['capacidad_estudiantes'];
                                $libres = max(0, $cap - $ocup);
                            ?>
                                <tr>
                                    <td class="font-weight-bold"><?= htmlspecialchars($r['nombre_espacio']) ?></td>
                                    <td><?= htmlspecialchars($r['torre']) ?> (Piso <?= $r['piso_numero'] ?>)</td>
                                    <td class="text-center"><?= $cap ?></td>
                                    <td class="text-center">
                                        <span class="badge <?= $libres > 0 ? 'badge-success' : 'badge-danger' ?> font-weight-bold">
                                            <?= $ocup ?> / <?= $cap ?> ocupadas (<?= $libres ?> libres)
                                        </span>
                                    </td>
                                    <td class="text-center"><span class="badge badge-primary"><?= htmlspecialchars($r['estatus']) ?></span></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ======================= REGISTRAR PERNOCTA ======================= -->
        <div class="card shadow border-0 mb-4" style="border-radius: 12px; overflow: hidden;">
            <div class="card-header text-white font-weight-bold py-3" style="background-color: #521f29;">
                <i class="fas fa-moon mr-2" style="color: #ff8a00;"></i> Registrar Pernocta (Carga por Curso o Caso Particular)
            </div>
            <div class="card-body bg-white p-4">
                <form method="POST" class="row">
                <?= csrf_field() ?>
                    <div class="form-group col-md-4">
                        <label class="font-weight-bold">Habitación / Residencia *</label>
                        <select name="id_espacio" class="form-control" required>
                            <option value="">— Seleccione espacio —</option>
                            <?php foreach ($residencias as $r): 
                                $ocup = (int) ($ocupadas_map[$r['id_espacio']] ?? 0);
                                $cap  = (int) $r['capacidad_estudiantes'];
                                $libres = max(0, $cap - $ocup);
                            ?>
                                <option value="<?= (int)$r['id_espacio'] ?>" <?= $libres <= 0 ? 'disabled' : '' ?>>
                                    <?= htmlspecialchars($r['nombre_espacio'] . ' (' . $r['torre'] . ') — ' . $ocup . '/' . $cap . ' camas ocupadas') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group col-md-4">
                        <label class="font-weight-bold">Tipo de Huésped *</label>
                        <div class="btn-group btn-group-toggle w-100 mt-1" data-toggle="buttons">
                            <label class="btn btn-outline-dark active">
                                <input type="radio" name="tipo_huesped" value="usuario" checked> Estudiante / Usuario
                            </label>
                            <label class="btn btn-outline-dark">
                                <input type="radio" name="tipo_huesped" value="foraneo"> Visitante Foráneo
                            </label>
                        </div>
                    </div>
                    <div class="form-group col-md-4">
                        <label class="font-weight-bold">Cédula del Huésped *</label>
                        <input type="text" name="cedula_huesped" class="form-control" required placeholder="Ej: 12345678" oninput="this.value=this.value.replace(/[^0-9]/g,'')">
                    </div>

                    <div class="form-group col-md-4 foraneo-campo d-none">
                        <label class="font-weight-bold text-danger">Nombre Completo (Foráneo) *</label>
                        <input type="text" name="nombre_foraneo" class="form-control" placeholder="Nombres y apellidos">
                    </div>
                    <div class="form-group col-md-4 foraneo-campo d-none">
                        <label class="font-weight-bold text-danger">Teléfono (Foráneo) *</label>
                        <input type="text" name="telefono_foraneo" class="form-control" placeholder="04xx-1234567">
                    </div>

                    <div class="form-group col-md-4">
                        <label class="font-weight-bold">Curso / Cohorte (Opcional)</label>
                        <select name="id_cohorte" class="form-control">
                            <option value="">— Ninguno (Evento sobrevenido / particular) —</option>
                            <?php foreach ($cohortes as $c): ?>
                                <option value="<?= (int)$c['id_cohorte'] ?>">
                                    <?= htmlspecialchars($c['codigo_cohorte'] . ' — ' . $c['nombre_programa'] . ($c['requiere_pernocta'] ? ' [PERNOCTA]' : '')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group col-md-2">
                        <label class="font-weight-bold">Fecha Entrada</label>
                        <input type="date" name="fecha_entrada" class="form-control" value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="form-group col-md-2">
                        <label class="font-weight-bold">Hora Entrada</label>
                        <input type="time" name="hora_entrada" class="form-control" value="<?= date('H:i') ?>">
                    </div>
                    <div class="form-group col-md-2">
                        <label class="font-weight-bold">Salida Prevista</label>
                        <input type="date" name="fecha_salida_prevista" class="form-control" value="<?= date('Y-m-d', strtotime('+1 day')) ?>">
                    </div>

                    <div class="form-group col-md-6">
                        <label class="font-weight-bold">Motivo / Evento</label>
                        <input type="text" name="motivo" class="form-control" placeholder="Ej: Curso intensivo de pernocta">
                    </div>
                    <div class="form-group col-md-6">
                        <label class="font-weight-bold">Observaciones</label>
                        <input type="text" name="observaciones" class="form-control" placeholder="Notas adicionales">
                    </div>

                    <div class="form-group col-md-12 mb-0">
                        <button type="submit" name="registrar_pernocta" value="1" class="btn font-weight-bold text-white py-2 px-4 shadow" style="background-color: #521f29; border-radius: 10px;">
                            <i class="fas fa-bed mr-2" style="color: #ff8a00;"></i> REGISTRAR PERNOCTA
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- ===================== PERNOCTAS EN CURSO ===================== -->
        <div class="card shadow border-0 mb-4" style="border-radius: 12px; overflow: hidden;">
            <div class="card-header text-white font-weight-bold py-3" style="background-color: #ff8a00;">
                <i class="fas fa-moon mr-2" style="color: #521f29;"></i> Pernoctas en Curso (<?= count($pernoctas_activas) ?>)
            </div>
            <div class="card-body bg-white p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-striped table-bordered mb-0 small align-middle">
                        <thead style="background-color: #521f29; color: #ffffff;" class="font-weight-bold">
                            <tr>
                                <th>Huésped</th>
                                <th>Residencia / Habitación</th>
                                <th>Curso Asociado</th>
                                <th class="text-center">Entrada</th>
                                <th class="text-center">Salida Prevista</th>
                                <th class="text-center" style="width: 15%;">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($pernoctas_activas)): ?>
                                <tr><td colspan="6" class="text-center text-muted py-4">No hay pernoctas activas actualmente.</td></tr>
                            <?php else: foreach ($pernoctas_activas as $p): ?>
                                <tr>
                                    <td>
                                        <span class="font-weight-bold"><?= htmlspecialchars($p['nombre_completo']) ?></span>
                                        <?= $p['es_visitante_foraneo'] ? '<span class="badge badge-secondary ml-1">Foráneo</span>' : '' ?>
                                        <br><small class="text-muted">V-<?= htmlspecialchars($p['cedula'] ?? '—') ?> · <?= htmlspecialchars($p['telefono'] ?? 'S/I') ?></small>
                                    </td>
                                    <td><?= htmlspecialchars($p['dormitorio']) ?></td>
                                    <td><?= htmlspecialchars($p['codigo_cohorte'] ?? '— (Caso particular)') ?></td>
                                    <td class="text-center"><?= date('d/m/Y', strtotime($p['fecha_entrada'])) ?></td>
                                    <td class="text-center font-weight-bold"><?= date('d/m/Y', strtotime($p['fecha_salida_prevista'])) ?></td>
                                    <td class="text-center">
                                        <form method="POST" class="d-inline">
                                        <?= csrf_field() ?>
                                            <input type="hidden" name="id_pernocta" value="<?= (int)$p['id_pernocta'] ?>">
                                            <button type="submit" name="finalizar_pernocta" value="1" class="btn btn-sm btn-success font-weight-bold" onclick="return confirm('¿Liberar la cama de este huésped?');">
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
</div>

<script>
document.addEventListener('change', function (e) {
    if (e.target && e.target.name === 'tipo_huesped') {
        var esForaneo = e.target.value === 'foraneo';
        document.querySelectorAll('.foraneo-campo').forEach(function (el) {
            el.classList.toggle('d-none', !esForaneo);
        });
        document.querySelectorAll('.foraneo-campo input').forEach(function (el) {
            el.required = esForaneo;
        });
    }
});
</script>

<?php require_once '../../includes/footer.php'; ?>