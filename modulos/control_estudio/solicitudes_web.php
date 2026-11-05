<?php
// /sistema_nora/modulos/control_estudio/solicitudes_web.php
require_once '../../config/db.php';
require_once '../../includes/header.php';

if (!tiene_permiso(['superadmin', 'admin', 'control_estudio'], $roles_array)) {
    echo "<div class='alert alert-danger font-weight-bold'>Acceso restringido a Control de Estudio y Administradores.</div>";
    require_once '../../includes/footer.php';
    exit();
}

$mensaje = '';
$tipo_alerta = '';

// Helper para procesar la aprobación de un registro
function procesarAprobacionEstudiante($pdo, $sol, $id_cohorte_destino) {
    $cedula = trim($sol['cedula']);
    $nombre_apellido = trim($sol['nombre_apellido']);
    $correo = trim($sol['correo']);
    $telefono = trim($sol['telefono']);

    $partes = explode(' ', $nombre_apellido, 2);
    $nombre = $partes[0] ?? $nombre_apellido;
    $apellido = $partes[1] ?? '';
    $password_hash = password_hash($cedula, PASSWORD_BCRYPT);

    // 1. Crear o actualizar usuario con rol estudiante
    $stmt_check = $pdo->prepare("SELECT id_usuario, roles_asignados FROM public.usuarios WHERE cedula = :c LIMIT 1");
    $stmt_check->execute([':c' => $cedula]);
    $usr_existente = $stmt_check->fetch();

    if ($usr_existente) {
        $id_usuario = $usr_existente['id_usuario'];
        // Garantizar que tenga rol estudiante
        $pdo->prepare("UPDATE public.usuarios SET tipo_usuario = 'estudiante', estatus_cuenta = 'Activo' WHERE id_usuario = :id")
            ->execute([':id' => $id_usuario]);
    } else {
        $sql_usr = "INSERT INTO public.usuarios (cedula, nombre, apellido, email, correo, password, telefono, tipo_usuario, estatus_cuenta, roles_asignados, cargo_funcion) 
                    VALUES (:cedula, :nombre, :apellido, :email, :email, :pass, :telefono, 'estudiante', 'Activo', ARRAY['estudiante']::text[], 'Estudiante') 
                    RETURNING id_usuario";
        $stmt_usr = $pdo->prepare($sql_usr);
        $stmt_usr->execute([
            ':cedula'   => $cedula,
            ':nombre'   => $nombre,
            ':apellido' => $apellido,
            ':email'    => $correo,
            ':pass'     => $password_hash,
            ':telefono' => $telefono
        ]);
        $id_usuario = $stmt_usr->fetchColumn();
    }

    // 2. Vincular Asistencia / Cohorte en el Sistema Interno
    if ($id_cohorte_destino > 0 && $id_usuario > 0) {
        $sql_ins = "INSERT INTO public.asistencia_clases (id_cohorte, id_estudiante, fecha_clase, estatus)
                    VALUES (:cohorte, :estudiante, CURRENT_DATE, 'Presente')
                    ON CONFLICT (id_cohorte, id_estudiante, fecha_clase) DO NOTHING";
        $stmt_ins = $pdo->prepare($sql_ins);
        $stmt_ins->execute([':cohorte' => $id_cohorte_destino, ':estudiante' => $id_usuario]);
    }

    return true;
}

// --- PROCESAMIENTO DE APROBACIÓN MASIVA O INDIVIDUAL ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Aprobación Individual
    if (isset($_POST['aprobar_individual'])) {
        $id_sol = intval($_POST['id_solicitud']);
        $id_cohorte = intval($_POST['id_cohorte_destino']);
        try {
            $stmt = $pdo_web->prepare("SELECT * FROM public.inscripciones WHERE id = :id");
            $stmt->execute([':id' => $id_sol]);
            $sol = $stmt->fetch();
            if ($sol) {
                procesarAprobacionEstudiante($pdo, $sol, $id_cohorte);
                // Marcar procesado o eliminar de lista web si se requiere
                $mensaje = "Solicitud de <strong>" . htmlspecialchars($sol['nombre_apellido']) . "</strong> aprobada exitosamente.";
                $tipo_alerta = "success";
            }
        } catch (Exception $e) {
            $mensaje = "Error al aprobar: " . $e->getMessage();
            $tipo_alerta = "danger";
        }
    }

    // Aprobación Masiva
    if (isset($_POST['aprobar_masivo']) && !empty($_POST['ids_seleccionados'])) {
        $ids = $_POST['ids_seleccionados'];
        $id_cohorte = intval($_POST['id_cohorte_masivo']);
        $contador = 0;
        try {
            foreach ($ids as $id_sol) {
                $stmt = $pdo_web->prepare("SELECT * FROM public.inscripciones WHERE id = :id");
                $stmt->execute([':id' => intval($id_sol)]);
                $sol = $stmt->fetch();
                if ($sol) {
                    procesarAprobacionEstudiante($pdo, $sol, $id_cohorte);
                    $contador++;
                }
            }
            $mensaje = "¡Se aprobanron y crearon cuentas a <strong>{$contador}</strong> estudiantes en lote con éxito!";
            $tipo_alerta = "success";
        } catch (Exception $e) {
            $mensaje = "Error durante el procesamiento masivo: " . $e->getMessage();
            $tipo_alerta = "danger";
        }
    }
}

// --- BÚSQUEDA Y LISTADO DESDE BD WEB ---
$filtro_buscar = trim($_GET['buscar'] ?? '');
$solicitudes = [];
$cohortes_activas = [];

try {
    // Cargar Cohortes de Control de Estudio
    $cohortes_activas = $pdo->query("SELECT c.id_cohorte, c.codigo_cohorte, m.nombre_programa 
                                     FROM public.cohortes c 
                                     JOIN public.mallas_curriculares m ON c.id_malla = m.id_malla 
                                     ORDER BY c.id_cohorte DESC")->fetchAll();

    if ($pdo_web) {
        $sql = "SELECT i.*, e.nombre as etnia_nombre, d.nombre as discapacidad_nombre 
                FROM public.inscripciones i 
                LEFT JOIN public.etnia e ON i.etnia_id = e.id 
                LEFT JOIN public.discapacidad d ON i.discapacidad_id = d.id WHERE 1=1";
        $params = [];
        if (!empty($filtro_buscar)) {
            $sql .= " AND (i.cedula LIKE :b OR LOWER(i.nombre_apellido) LIKE LOWER(:b))";
            $params[':b'] = "%{$filtro_buscar}%";
        }
        $sql .= " ORDER BY i.id DESC";
        $stmt_w = $pdo_web->prepare($sql);
        $stmt_w->execute($params);
        $solicitudes = $stmt_w->fetchAll();
    }
} catch (Exception $e) {}

// Obtener Cédulas registradas en el sistema interno para cruzarlas y emitir alertas
$cedulas_registradas = [];
try {
    $res = $pdo->query("SELECT cedula FROM public.usuarios")->fetchAll(PDO::FETCH_COLUMN);
    $cedulas_registradas = array_flip($res);
} catch (Exception $e) {}
?>

<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <h1 class="h3 mb-0 font-weight-bold" style="color: #521f29;">
        <i class="fas fa-user-clock mr-2 text-warning"></i>Lista de Espera y Pre-Inscripciones Web
    </h1>
    <a href="index.php" class="btn btn-sm btn-secondary font-weight-bold shadow-sm">
        <i class="fas fa-arrow-left mr-1"></i> Volver a Control de Estudio
    </a>
</div>

<?php if (!empty($mensaje)): ?>
    <div class="alert alert-<?= $tipo_alerta ?> alert-dismissible fade show font-weight-bold shadow-sm" role="alert">
        <?= $mensaje ?>
        <button type="button" class="close" data-dismiss="alert">&times;</button>
    </div>
<?php endif; ?>

<!-- FORMULARIO CON TABLA Y ACCIÓN MASIVA -->
<form method="POST" action="solicitudes_web.php" id="formMasivo">
<?= csrf_field() ?>
    <div class="card shadow border-0 mb-4" style="border-radius: 15px;">
        <div class="card-header py-3 bg-white d-flex justify-content-between align-items-center">
            <h6 class="m-0 font-weight-bold" style="color: #521f29;"><i class="fas fa-tasks mr-2 text-warning"></i>Aprobación Masiva e Individual</h6>
            <div class="d-flex align-items-center">
                <select name="id_cohorte_masivo" class="form-control form-control-sm font-weight-bold mr-2" style="width: 280px;" required>
                    <option value="">-- Seleccionar Cohorte Destino --</option>
                    <?php foreach ($cohortes_activas as $coh): ?>
                        <option value="<?= $coh['id_cohorte'] ?>"><?= htmlspecialchars($coh['codigo_cohorte'] . ' - ' . $coh['nombre_programa']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" name="aprobar_masivo" value="1" class="btn btn-sm font-weight-bold text-white shadow-sm" style="background-color: #ff8a00;" onclick="return confirm('¿Aprobar masivamente los seleccionados?')">
                    <i class="fas fa-check-double mr-1"></i> APROBAR SELECCIONADOS
                </button>
            </div>
        </div>
        <div class="card-body bg-white p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead style="background-color: #521f29; color: #ffffff;">
                        <tr>
                            <th class="text-center" style="width: 40px;">
                                <input type="checkbox" id="checkAll" onclick="toggleSelectAll(this)">
                            </th>
                            <th>Cédula</th>
                            <th>Aspirante</th>
                            <th>Curso Interés Web</th>
                            <th>Estado Usuario</th>
                            <th class="text-center">Acción Individual</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($solicitudes)): ?>
                            <tr><td colspan="6" class="text-center py-4 text-muted font-weight-bold">No hay solicitudes en lista de espera.</td></tr>
                        <?php else: ?>
                            <?php foreach ($solicitudes as $s): 
                                $existe = isset($cedulas_registradas[$s['cedula']]);
                            ?>
                                <tr>
                                    <td class="text-center">
                                        <input type="checkbox" name="ids_seleccionados[]" value="<?= $s['id'] ?>" class="check-item">
                                    </td>
                                    <td class="font-weight-bold">V-<?= htmlspecialchars($s['cedula']) ?></td>
                                    <td>
                                        <strong style="color: #521f29;"><?= htmlspecialchars($s['nombre_apellido']) ?></strong><br>
                                        <small class="text-muted"><i class="fas fa-envelope mr-1"></i><?= htmlspecialchars($s['correo']) ?> | <i class="fas fa-phone mr-1"></i><?= htmlspecialchars($s['telefono']) ?></small>
                                    </td>
                                    <td><span class="badge badge-warning font-weight-bold p-2"><?= htmlspecialchars($s['cursos_seleccionados']) ?></span></td>
                                    <td>
                                        <?php if ($existe): ?>
                                            <span class="badge badge-danger p-2 font-weight-bold"><i class="fas fa-exclamation-circle mr-1"></i>USUARIO YA REGISTRADO EN SISTEMA</span>
                                        <?php else: ?>
                                            <span class="badge badge-success p-2 font-weight-bold"><i class="fas fa-user-plus mr-1"></i>NUEVO ASPIRANTE</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <button type="button" class="btn btn-sm btn-outline-success font-weight-bold shadow-sm" data-toggle="modal" data-target="#modalAprobarInd<?= $s['id'] ?>">
                                            <i class="fas fa-check mr-1"></i> Aprobar
                                        </button>

                                        <!-- MODAL APROBACIÓN INDIVIDUAL -->
                                        <div class="modal fade text-left" id="modalAprobarInd<?= $s['id'] ?>" tabindex="-1" role="dialog">
                                            <div class="modal-dialog modal-dialog-centered" role="document">
                                                <div class="modal-content" style="border-radius: 15px;">
                                                    <div class="modal-header text-white" style="background-color: #521f29;">
                                                        <h5 class="modal-title font-weight-bold"><i class="fas fa-user-check text-warning mr-2"></i>Aprobar Inscripción</h5>
                                                        <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                                                    </div>
                                                    <div class="modal-body bg-light">
                                                        <?php if ($existe): ?>
                                                            <div class="alert alert-warning font-weight-bold small">
                                                                <i class="fas fa-exclamation-triangle mr-1"></i> Alerta: La cédula V-<?= htmlspecialchars($s['cedula']) ?> ya existe en el sistema. Se conservará su cuenta previa y se le asignará el rol de Estudiante.
                                                            </div>
                                                        <?php else: ?>
                                                            <p class="font-weight-bold small text-muted">Se creará un nuevo usuario con la cédula <strong>V-<?= htmlspecialchars($s['cedula']) ?></strong> y clave inicial igual a su cédula.</p>
                                                        <?php endif; ?>

                                                        <input type="hidden" name="id_solicitud" value="<?= $s['id'] ?>">
                                                        <div class="form-group">
                                                            <label class="font-weight-bold small" style="color: #521f29;">Seleccionar Cohorte de Asignación *</label>
                                                            <select name="id_cohorte_destino" class="form-control font-weight-bold" required>
                                                                <?php foreach ($cohortes_activas as $coh): ?>
                                                                    <option value="<?= $coh['id_cohorte'] ?>"><?= htmlspecialchars($coh['codigo_cohorte'] . ' - ' . $coh['nombre_programa']) ?></option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>
                                                    </div>
                                                    <div class="modal-footer bg-white">
                                                        <button type="button" class="btn btn-secondary font-weight-bold" data-dismiss="modal">Cancelar</button>
                                                        <button type="submit" name="aprobar_individual" value="1" class="btn font-weight-bold text-white" style="background-color: #ff8a00;">
                                                            <i class="fas fa-check mr-1"></i> Confirmar Aprobación
                                                        </button>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

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
function toggleSelectAll(master) {
    const checkboxes = document.querySelectorAll('.check-item');
    checkboxes.forEach(cb => cb.checked = master.checked);
}
</script>

<?php require_once '../../includes/footer.php'; ?>