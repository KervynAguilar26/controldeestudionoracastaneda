<?php
// /sistema_nora/modulos/usuarios/aprobar_estudiantes.php
require_once '../../config/db.php';
require_once '../../config/mailer.php';
require_once '../../includes/header.php';

if (!tiene_permiso(['superadmin', 'admin', 'control_estudio'], $roles_array)) {
    echo "<div class='alert alert-danger font-weight-bold'>Acceso restringido a Control de Estudio y Administradores.</div>";
    require_once '../../includes/footer.php';
    exit();
}

$mensaje = '';
$tipo_alerta = '';

// 1. PROCESAR APROBACIÓN INDIVIDUAL O MASIVA
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['procesar_aprobacion'])) {
    try {
        if (empty($pdo_web)) {
            throw new Exception("La base de datos del portal web no está disponible en este momento.");
        }

        $ids_solicitudes = $_POST['ids_solicitudes'] ?? [];
        $id_cohorte_destino = intval($_POST['id_cohorte_destino'] ?? 0);

        if (empty($ids_solicitudes) || $id_cohorte_destino <= 0) {
            throw new Exception("Debe seleccionar al menos una solicitud y una cohorte destino.");
        }

        // Validar que la cohorte exista y tenga cupo disponible
        $stmt_coh = $pdo->prepare("SELECT id_cohorte, cupos_maximos,
                                          (SELECT COUNT(*) FROM public.inscripciones i WHERE i.id_curso = c.id_cohorte) AS inscritos
                                   FROM public.cohortes c WHERE c.id_cohorte = :id LIMIT 1");
        $stmt_coh->execute([':id' => $id_cohorte_destino]);
        $cohorte_destino = $stmt_coh->fetch();
        if (!$cohorte_destino) {
            throw new Exception("La cohorte destino seleccionada no existe.");
        }

        $aprobados = 0;
        $actualizados = 0;
        $inscritos_nuevos = 0;
        $omitidos = 0;
        $correos_enviados = 0;

        foreach ($ids_solicitudes as $id_sol) {
            // Consultar datos de la solicitud en la BD Web
            $stmt_sol = $pdo_web->prepare("SELECT * FROM public.inscripciones WHERE id = :id LIMIT 1");
            $stmt_sol->execute([':id' => intval($id_sol)]);
            $sol = $stmt_sol->fetch();

            if (!$sol) continue;

            $cedula          = trim($sol['cedula']);
            $nombre_apellido = trim($sol['nombre_apellido']);
            $correo          = trim($sol['correo']);
            $telefono        = trim($sol['telefono']);

            // 1.1 Consultar Padrón Nacional para enriquecer nombres oficiales
            $first_name = ''; $second_name = ''; $first_surname = ''; $second_surname = '';
            if ($pdo_padron) {
                try {
                    $stmt_pad = $pdo_padron->prepare("SELECT first_name, second_name, first_surname, second_surname FROM public.datos WHERE document = :doc LIMIT 1");
                    $stmt_pad->execute([':doc' => $cedula]);
                    $padron = $stmt_pad->fetch();
                    if ($padron) {
                        $first_name     = $padron['first_name'];
                        $second_name    = $padron['second_name'];
                        $first_surname  = $padron['first_surname'];
                        $second_surname = $padron['second_surname'];
                    }
                } catch (Exception $e_p) {}
            }

            // Nombre y Apellido final
            if (!empty($first_name) && !empty($first_surname)) {
                $nombre_final   = trim($first_name . ' ' . $second_name);
                $apellido_final = trim($first_surname . ' ' . $second_surname);
            } else {
                $partes = explode(' ', $nombre_apellido, 2);
                $nombre_final   = $partes[0] ?? $nombre_apellido;
                $apellido_final = $partes[1] ?? '';
            }

            $pass_hash = password_hash($cedula, PASSWORD_BCRYPT);

            // 1.2 Verificar si el usuario ya existe en el sistema principal
            $stmt_u = $pdo->prepare("SELECT id_usuario, roles_asignados, tipo_usuario FROM public.usuarios WHERE cedula = :c LIMIT 1");
            $stmt_u->execute([':c' => $cedula]);
            $u_existente = $stmt_u->fetch();

            if ($u_existente) {
                $id_usuario = $u_existente['id_usuario'];

                // Activar la cuenta SIN degradar el rol actual (docentes, administrativos, etc.)
                // Solo se asegura el rol 'estudiante' dentro de roles_asignados.
                $pdo->prepare("UPDATE public.usuarios SET
                                estatus_cuenta = 'Activo',
                                tipo_usuario = CASE WHEN tipo_usuario IS NULL OR tipo_usuario = '' THEN 'estudiante' ELSE tipo_usuario END,
                                roles_asignados = CASE
                                    WHEN roles_asignados IS NULL OR roles_asignados = ARRAY[]::text[] THEN ARRAY['estudiante']::text[]
                                    WHEN 'estudiante' = ANY(roles_asignados) THEN roles_asignados
                                    ELSE roles_asignados || ARRAY['estudiante']::text[]
                                END
                               WHERE id_usuario = :id")->execute([':id' => $id_usuario]);
                $actualizados++;
            } else {
                // Crear Nuevo Usuario Estudiante
                $sql_new = "INSERT INTO public.usuarios 
                            (cedula, nombre, apellido, email, correo, password, telefono, tipo_usuario, estatus_cuenta, cargo_funcion, roles_asignados, etnia, tipo_discapacidad) 
                            VALUES (:cedula, :nom, :ape, :cor, :cor, :pass, :tel, 'estudiante', 'Activo', 'Estudiante', ARRAY['estudiante']::text[], 'Ninguna', 'Ninguna') 
                            RETURNING id_usuario";
                $stmt_new = $pdo->prepare($sql_new);
                $stmt_new->execute([
                    ':cedula' => $cedula,
                    ':nom'    => $nombre_final,
                    ':ape'    => $apellido_final,
                    ':cor'    => $correo,
                    ':pass'   => $pass_hash,
                    ':tel'    => $telefono
                ]);
                $id_usuario = $stmt_new->fetchColumn();
                $aprobados++;

                // Notificar credenciales iniciales por correo (si hay un correo válido)
                if (!empty($correo) && filter_var($correo, FILTER_VALIDATE_EMAIL)) {
                    try {
                        if (enviarCredencialesUsuario($correo, trim($nombre_final . ' ' . $apellido_final), $cedula, $cedula, 'Estudiante')) {
                            $correos_enviados++;
                        }
                    } catch (Exception $e_mail) {
                        // El alta del estudiante no debe fallar si el correo falla
                    }
                }
            }

            // 1.3 Inscribir al estudiante en la cohorte seleccionada (tabla inscripciones)
            if ($id_usuario > 0 && $id_cohorte_destino > 0) {
                $stmt_existe_ins = $pdo->prepare("SELECT COUNT(*) FROM public.inscripciones WHERE id_curso = :cohorte AND id_estudiante = :estudiante");
                $stmt_existe_ins->execute([':cohorte' => $id_cohorte_destino, ':estudiante' => $id_usuario]);

                if ($stmt_existe_ins->fetchColumn() == 0) {
                    try {
                        $stmt_ins = $pdo->prepare("INSERT INTO public.inscripciones (id_curso, id_estudiante, fecha_inscripcion)
                                                   VALUES (:cohorte, :estudiante, CURRENT_TIMESTAMP)");
                        $stmt_ins->execute([':cohorte' => $id_cohorte_destino, ':estudiante' => $id_usuario]);
                        $inscritos_nuevos++;
                    } catch (Exception $e_ins) {
                        $omitidos++;
                    }
                }

                // Registro de asistencia inicial (idempotente)
                $sql_asis = "INSERT INTO public.asistencia_clases (id_cohorte, id_estudiante, fecha_clase, estatus)
                             VALUES (:cohorte, :estudiante, CURRENT_DATE, 'Presente')
                             ON CONFLICT (id_cohorte, id_estudiante, fecha_clase) DO NOTHING";
                $pdo->prepare($sql_asis)->execute([':cohorte' => $id_cohorte_destino, ':estudiante' => $id_usuario]);
            }
        }

        $mensaje = "¡Procesamiento completado! Nuevos estudiantes: <strong>{$aprobados}</strong>. "
                 . "Cuentas previas actualizadas: <strong>{$actualizados}</strong>. "
                 . "Inscripciones creadas: <strong>{$inscritos_nuevos}</strong>. "
                 . "Correos de bienvenida enviados: <strong>{$correos_enviados}</strong>."
                 . ($omitidos > 0 ? " Inscripciones omitidas por error: <strong>{$omitidos}</strong>." : "");
        $tipo_alerta = "success";

    } catch (Exception $e) {
        $mensaje = "Error durante el procesamiento: " . $e->getMessage();
        $tipo_alerta = "danger";
    }
}

// 2. CONSULTAR LISTA DE ESPA Y PREINSCRIPCIONES
$solicitudes = [];
$cohortes = [];
try {
    if ($pdo_web) {
        $solicitudes = $pdo_web->query("SELECT * FROM public.inscripciones ORDER BY id DESC")->fetchAll();
    }
    $cohortes = $pdo->query("SELECT c.id_cohorte, c.codigo_cohorte, m.nombre_programa, c.cupos_maximos,
                             (SELECT COUNT(*) FROM public.asistencia_clases ac WHERE ac.id_cohorte = c.id_cohorte) as inscritos
                             FROM public.cohortes c 
                             JOIN public.mallas_curriculares m ON c.id_malla = m.id_malla 
                             WHERE c.estatus = 'Planificada' OR c.estatus = 'En Curso'
                             ORDER BY c.id_cohorte DESC")->fetchAll();
} catch (Exception $e) {}

// Arreglo auxiliar de cédulas para validar existencia previa
$cedulas_registradas = [];
try {
    $res = $pdo->query("SELECT cedula FROM public.usuarios")->fetchAll(PDO::FETCH_COLUMN);
    $cedulas_registradas = array_flip($res);
} catch (Exception $e) {}
?>

<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <h1 class="h3 mb-0 font-weight-bold" style="color: #521f29;">
        <i class="fas fa-user-check mr-2 text-warning"></i>Aprobación de Estudiantes y Verificación en Padrón
    </h1>
    <a href="../control_estudio/index.php" class="btn btn-sm btn-secondary font-weight-bold shadow-sm">
        <i class="fas fa-arrow-left mr-1"></i> Volver a Control de Estudio
    </a>
</div>

<?php if (!empty($mensaje)): ?>
    <div class="alert alert-<?= $tipo_alerta ?> alert-dismissible fade show font-weight-bold shadow-sm" role="alert">
        <?= $mensaje ?>
        <button type="button" class="close" data-dismiss="alert">&times;</button>
    </div>
<?php endif; ?>

<form method="POST" action="aprobar_estudiantes.php" id="formAprobar">
<?= csrf_field() ?>
    <input type="hidden" name="procesar_aprobacion" value="1">

    <!-- PANEL DE CONTROL Y ASIGNACIÓN COHORTE -->
    <div class="card shadow border-0 mb-4" style="border-radius: 15px;">
        <div class="card-header py-3 bg-white d-flex justify-content-between align-items-center" style="border-top-left-radius: 15px; border-top-right-radius: 15px;">
            <h6 class="m-0 font-weight-bold" style="color: #521f29;"><i class="fas fa-tasks mr-2 text-warning"></i>Acciones de Lote y Asignación Institucional</h6>
            <div class="d-flex align-items-center">
                <select name="id_cohorte_destino" class="form-control form-control-sm font-weight-bold mr-2" style="width: 320px;" required>
                    <option value="">-- Seleccionar Cohorte Destino --</option>
                    <?php foreach ($cohortes as $c): ?>
                        <option value="<?= $c['id_cohorte'] ?>">
                            <?= htmlspecialchars($c['codigo_cohorte'] . ' - ' . $c['nombre_programa']) ?> (<?= $c['inscritos'] ?>/<?= $c['cupos_maximos'] ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-sm font-weight-bold text-white shadow" style="background-color: #ff8a00;" onclick="return confirm('¿Confirmar aprobación y creación de usuarios para los registros seleccionados?')">
                    <i class="fas fa-user-plus mr-1"></i> APROBAR SELECCIONADOS
                </button>
            </div>
        </div>
        <div class="card-body bg-white p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead style="background-color: #521f29; color: #ffffff;">
                        <tr>
                            <th class="text-center" style="width: 40px;">
                                <input type="checkbox" id="checkAll" onclick="toggleAllCheckboxes(this)">
                            </th>
                            <th>Cédula / Padrón</th>
                            <th>Aspirante Web</th>
                            <th>Curso Interés</th>
                            <th>Validación de Usuario</th>
                            <th class="text-center">Carnet PVC</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($solicitudes)): ?>
                            <tr><td colspan="6" class="text-center py-4 text-muted font-weight-bold">No hay postulantes pendientes en la lista de espera web.</td></tr>
                        <?php else: ?>
                            <?php foreach ($solicitudes as $s): 
                                $existe = isset($cedulas_registradas[$s['cedula']]);
                            ?>
                                <tr>
                                    <td class="text-center">
                                        <input type="checkbox" name="ids_solicitudes[]" value="<?= $s['id'] ?>" class="check-sol">
                                    </td>
                                    <td class="font-weight-bold">
                                        V-<?= htmlspecialchars($s['cedula']) ?>
                                        <button type="button" class="btn btn-xs btn-outline-info ml-1" title="Verificar Padrón" onclick="verificarPadron('<?= $s['cedula'] ?>')">
                                            <i class="fas fa-search"></i>
                                        </button>
                                    </td>
                                    <td>
                                        <strong style="color: #521f29;"><?= htmlspecialchars($s['nombre_apellido']) ?></strong><br>
                                        <small class="text-muted"><i class="fas fa-envelope mr-1"></i><?= htmlspecialchars($s['correo']) ?> | <i class="fas fa-phone mr-1"></i><?= htmlspecialchars($s['telefono']) ?></small>
                                    </td>
                                    <td><span class="badge badge-warning text-dark font-weight-bold p-2"><?= htmlspecialchars($s['cursos_seleccionados']) ?></span></td>
                                    <td>
                                        <?php if ($existe): ?>
                                            <span class="badge badge-danger p-2 font-weight-bold" title="La cuenta ya existe. Se le actualizará el rol a estudiante.">
                                                <i class="fas fa-exclamation-triangle mr-1"></i>USUARIO REGISTRADO
                                            </span>
                                        <?php else: ?>
                                            <span class="badge badge-success p-2 font-weight-bold">
                                                <i class="fas fa-user-check mr-1"></i>NUEVO REGISTRO
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($existe): ?>
                                            <a href="../../imprimir_carnet.php?cedula=<?= $s['cedula'] ?>" target="_blank" class="btn btn-sm btn-outline-dark font-weight-bold shadow-sm">
                                                <i class="fas fa-id-card text-warning mr-1"></i> Carnet
                                            </a>
                                        <?php else: ?>
                                            <span class="small text-muted font-italic">Pendiente aprobación</span>
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
</form>

<script>
function toggleAllCheckboxes(master) {
    const checkboxes = document.querySelectorAll('.check-sol');
    checkboxes.forEach(cb => cb.checked = master.checked);
}

function verificarPadron(cedula) {
    fetch('../control_estudio/api_buscar_padron.php?cedula=' + encodeURIComponent(cedula))
        .then(response => response.json())
        .then(data => {
            if (data.encontrado) {
                alert("DATOS DEL PADRÓN NACIONAL:\n\nNombres: " + data.first_name + " " + (data.second_name || '') + "\nApellidos: " + data.first_surname + " " + (data.second_surname || ''));
            } else {
                alert("No se localizó la cédula " + cedula + " en el Padrón Nacional.");
            }
        })
        .catch(err => alert("Error al consultar el padrón nacional."));
}
</script>

<?php require_once '../../includes/footer.php'; ?>