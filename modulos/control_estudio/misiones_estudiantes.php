<?php
// /sistema_nora/modulos/control_estudio/misiones_estudiantes.php
require_once '../../config/db.php';
require_once '../../includes/header.php';

// Control de acceso: misiones exclusivas de Control de Estudio / Administración
if (!tiene_permiso(['control_estudio', 'admin', 'superadmin'], $roles_array)) {
    echo "<div class='alert alert-danger font-weight-bold'>Acceso restringido a Control de Estudio.</div>";
    require_once '../../includes/footer.php';
    exit();
}

$id_prestamo = intval($_GET['id_prestamo'] ?? 0);

// Cargar datos del grupo / aula de la Misión
$stmt_p = $pdo->prepare("SELECT * FROM public.prestamo_misiones WHERE id_prestamo = :id");
$stmt_p->execute([':id' => $id_prestamo]);
$mision = $stmt_p->fetch();

if (!$mision) {
    echo "<div class='container my-4'><div class='alert alert-danger font-weight-bold'>Grupo de Misión Educativa no encontrado.</div></div>";
    require_once '../../includes/footer.php';
    exit;
}

// Cargar etnias y discapacidades
$etnias = $pdo->query("SELECT id, nombre FROM public.etnia WHERE activo = 1 ORDER BY nombre ASC")->fetchAll();
$discapacidades = $pdo->query("SELECT id, nombre FROM public.discapacidad WHERE activo = 1 ORDER BY nombre ASC")->fetchAll();

// PROCESAR REGISTRO DE ESTUDIANTE
$mensaje = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_estudiante'])) {
    try {
        $cedula    = trim($_POST['cedula']);
        $nombre    = trim($_POST['nombre']);
        $apellido  = trim($_POST['apellido']);
        $f_nac     = $_POST['fecha_nacimiento'];
        $estado_c  = $_POST['estado_civil'];
        $telefono  = trim($_POST['telefono']);
        $etnia     = !empty($_POST['id_etnia']) ? intval($_POST['id_etnia']) : null;
        $discap    = !empty($_POST['id_discapacidad']) ? intval($_POST['id_discapacidad']) : null;

        // Calcular edad automáticamente
        $edad = 0;
        if (!empty($f_nac)) {
            $fecha_nac = new DateTime($f_nac);
            $hoy = new DateTime();
            $edad = $hoy->diff($fecha_nac)->y;
        }

        $sql_in = "INSERT INTO public.misiones_estudiantes 
                   (id_prestamo, cedula, nombre, apellido, fecha_nacimiento, edad, estado_civil, telefono, id_etnia, id_discapacidad) 
                   VALUES (:id_p, :ced, :nom, :ape, :fnac, :edad, :est, :tel, :etn, :disc)";
        
        $stmt_in = $pdo->prepare($sql_in);
        $stmt_in->execute([
            ':id_p' => $id_prestamo,
            ':ced'  => $cedula,
            ':nom'  => $nombre,
            ':ape'  => $apellido,
            ':fnac' => $f_nac ?: null,
            ':edad' => $edad,
            ':est'  => $estado_c,
            ':tel'  => $telefono,
            ':etn'  => $etnia,
            ':disc' => $discap
        ]);

        $mensaje = "<div class='alert alert-success font-weight-bold shadow-sm'><i class='fas fa-check-circle mr-2'></i>Estudiante registrado exitosamente.</div>";
    } catch (Exception $e) {
        $mensaje = "<div class='alert alert-danger font-weight-bold shadow-sm'><i class='fas fa-exclamation-triangle mr-2'></i>La cédula ingresada ya está registrada en este grupo.</div>";
    }
}

// Consultar estudiantes censados
$sql_est = "SELECT e.*, etn.nombre as etnia_nombre, disc.nombre as discapacidad_nombre 
            FROM public.misiones_estudiantes e 
            LEFT JOIN public.etnia etn ON e.id_etnia = etn.id
            LEFT JOIN public.discapacidad disc ON e.id_discapacidad = disc.id
            WHERE e.id_prestamo = :id ORDER BY e.apellido ASC";
$stmt_est = $pdo->prepare($sql_est);
$stmt_est->execute([':id' => $id_prestamo]);
$estudiantes = $stmt_est->fetchAll();
?>

<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <h1 class="h3 mb-0 font-weight-bold" style="color: #521f29;">
        <i class="fas fa-users-cog mr-2 text-warning"></i>Censo de Estudiantes: <?= htmlspecialchars($mision['nombre_mision']) ?>
    </h1>
    <div>
        <a href="imprimir_lista_mision.php?id_prestamo=<?= $id_prestamo ?>" target="_blank" class="btn btn-sm btn-danger font-weight-bold shadow-sm mr-2">
            <i class="fas fa-file-pdf mr-1"></i> IMPRIMIR REPORTE (PDF)
        </a>
        <a href="misiones_instalaciones.php" class="btn btn-sm btn-secondary font-weight-bold shadow-sm">
            <i class="fas fa-arrow-left mr-1"></i> Volver
        </a>
    </div>
</div>

<?= $mensaje ?>

<!-- CARD DE INFORMACIÓN DEL AULA -->
<div class="card shadow mb-4" style="border-radius: 15px; border: none;">
    <div class="card-body bg-white p-3">
        <div class="row align-items-center">
            <div class="col-md-4 border-right">
                <small class="text-muted font-weight-bold d-block">FACILITADOR RESPONSABLE:</small>
                <strong style="color: #521f29; font-size: 11pt;"><?= htmlspecialchars($mision['facilitador_responsable']) ?></strong><br>
                <small class="text-muted">C.I: V-<?= htmlspecialchars($mision['cedula_facilitador']) ?> | Tlf: <?= htmlspecialchars($mision['telefono_facilitador']) ?></small>
            </div>
            <div class="col-md-4 border-right">
                <small class="text-muted font-weight-bold d-block">AULA / ESPACIO ASIGNADO:</small>
                <strong class="text-primary" style="font-size: 11pt;"><?= htmlspecialchars($mision['salon_asignado']) ?></strong><br>
                <small class="text-muted"><?= htmlspecialchars($mision['dias_horario']) ?></small>
            </div>
            <div class="col-md-4 text-center">
                <small class="text-muted font-weight-bold d-block">MATRÍCULA CENSADA:</small>
                <span class="badge badge-warning text-dark font-weight-bold px-3 py-2" style="font-size: 12pt;">
                    <?= count($estudiantes) ?> Estudiantes
                </span>
            </div>
        </div>
    </div>
</div>

<!-- FORMULARIO DE CARGA CON AUTO-COMPLETADO PADRÓN NACIONAL -->
<div class="card shadow mb-4" style="border-radius: 15px; border: none;">
    <div class="card-header py-3" style="background-color: #521f29; border-top-left-radius: 15px; border-top-right-radius: 15px;">
        <h6 class="m-0 font-weight-bold text-white"><i class="fas fa-user-plus mr-2"></i>Formulario de Censo de Estudiantes</h6>
    </div>
    <div class="card-body bg-white p-4">
        <form method="POST" action="misiones_estudiantes.php?id_prestamo=<?= $id_prestamo ?>
        <?= csrf_field() ?>">
            <div class="form-row">
                <div class="col-md-3 mb-2">
                    <label class="font-weight-bold" style="color: #521f29;">Cédula de Identidad *</label>
                    <div class="input-group">
                        <input type="text" name="cedula" id="cedula_input" class="form-control font-weight-bold" placeholder="Ej: 12345678" onkeypress="if(event.key === 'Enter'){ event.preventDefault(); consultarPadronMision(); }" required autofocus>
                        <div class="input-group-append">
                            <button type="button" onclick="consultarPadronMision()" class="btn btn-warning font-weight-bold" title="Consultar en Padrón">
                                <i class="fas fa-search" id="icon_buscar"></i>
                            </button>
                        </div>
                    </div>
                    <small class="text-muted">Ingrese la cédula y presione ENTER</small>
                </div>
                <div class="col-md-3 mb-2">
                    <label class="font-weight-bold" style="color: #521f29;">Nombres *</label>
                    <input type="text" name="nombre" id="nombre_input" class="form-control font-weight-bold" required>
                </div>
                <div class="col-md-3 mb-2">
                    <label class="font-weight-bold" style="color: #521f29;">Apellidos *</label>
                    <input type="text" name="apellido" id="apellido_input" class="form-control font-weight-bold" required>
                </div>
                <div class="col-md-3 mb-2">
                    <label class="font-weight-bold" style="color: #521f29;">Teléfono *</label>
                    <input type="text" name="telefono" class="form-control font-weight-bold" placeholder="Ej: 0412-1234567" required>
                </div>
            </div>

            <div class="form-row">
                <div class="col-md-3 mb-2">
                    <label class="font-weight-bold" style="color: #521f29;">Fecha de Nacimiento</label>
                    <input type="date" name="fecha_nacimiento" id="fnac_input" class="form-control">
                </div>
                <div class="col-md-3 mb-2">
                    <label class="font-weight-bold" style="color: #521f29;">Estado Civil</label>
                    <select name="estado_civil" class="form-control font-weight-bold">
                        <option value="Soltero(a)">Soltero(a)</option>
                        <option value="Casado(a)">Casado(a)</option>
                        <option value="Divorciado(a)">Divorciado(a)</option>
                        <option value="Viudo(a)">Viudo(a)</option>
                        <option value="Concubinato">Concubinato</option>
                    </select>
                </div>
                <div class="col-md-3 mb-2">
                    <label class="font-weight-bold" style="color: #521f29;">Etnia (Si aplica)</label>
                    <select name="id_etnia" class="form-control">
                        <option value="">-- Sin Etnia --</option>
                        <?php foreach ($etnias as $et): ?>
                            <option value="<?= $et['id'] ?>"><?= htmlspecialchars($et['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 mb-2">
                    <label class="font-weight-bold" style="color: #521f29;">Discapacidad (Si aplica)</label>
                    <select name="id_discapacidad" class="form-control">
                        <option value="">-- Ninguna --</option>
                        <?php foreach ($discapacidades as $ds): ?>
                            <option value="<?= $ds['id'] ?>"><?= htmlspecialchars($ds['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <button type="submit" name="guardar_estudiante" class="btn btn-block font-weight-bold text-white shadow mt-3 py-2" style="background-color: #ff8a00; border-radius: 10px;">
                <i class="fas fa-plus-circle mr-2"></i> CENSAR ESTUDIANTE EN ESTE GRUPO
            </button>
        </form>
    </div>
</div>

<!-- LISTA DE ESTUDIANTES -->
<div class="card shadow mb-4" style="border-radius: 15px; border: none;">
    <div class="card-header py-3" style="background-color: #521f29; border-top-left-radius: 15px; border-top-right-radius: 15px;">
        <h6 class="m-0 font-weight-bold text-white"><i class="fas fa-list-ol mr-2"></i>Lista de Participantes Censo Misión</h6>
    </div>
    <div class="card-body bg-white">
        <div class="table-responsive">
            <table class="table table-hover table-striped align-middle" width="100%">
                <thead style="background-color: #ff8a00; color: #521f29;">
                    <tr>
                        <th>N°</th>
                        <th>Cédula</th>
                        <th>Nombres y Apellidos</th>
                        <th>Edad / F. Nac</th>
                        <th>Estado Civil</th>
                        <th>Teléfono</th>
                        <th>Inclusión Social</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($estudiantes)): ?>
                        <tr><td colspan="7" class="text-center text-muted py-4">No hay estudiantes cargados en este grupo.</td></tr>
                    <?php else: ?>
                        <?php $n = 1; foreach ($estudiantes as $e): ?>
                            <tr>
                                <td class="font-weight-bold"><?= $n++ ?></td>
                                <td class="font-weight-bold">V-<?= htmlspecialchars($e['cedula']) ?></td>
                                <td class="font-weight-bold" style="color: #521f29;"><?= htmlspecialchars($e['nombre'] . ' ' . $e['apellido']) ?></td>
                                <td>
                                    <?= $e['edad'] ? "<strong>{$e['edad']} Años</strong>" : 'S/D' ?><br>
                                    <small class="text-muted"><?= $e['fecha_nacimiento'] ? date('d/m/Y', strtotime($e['fecha_nacimiento'])) : '' ?></small>
                                </td>
                                <td><?= htmlspecialchars($e['estado_civil']) ?></td>
                                <td><i class="fas fa-phone mr-1 text-muted"></i><?= htmlspecialchars($e['telefono']) ?></td>
                                <td>
                                    <small class="d-block">Etnia: <?= htmlspecialchars($e['etnia_nombre'] ?? 'Ninguna') ?></small>
                                    <small class="d-block">Disc: <?= htmlspecialchars($e['discapacidad_nombre'] ?? 'Ninguna') ?></small>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- SCRIPT JS DE AUTO-COMPLETADO DESDE PADRÓN -->
<script>
function consultarPadronMision() {
    const cedulaInput = document.getElementById('cedula_input');
    const icon = document.getElementById('icon_buscar');
    const cedula = cedulaInput.value.trim();

    if (!cedula) return;

    icon.className = "fas fa-spinner fa-spin";

    fetch('api_buscar_padron.php?cedula=' + cedula)
        .then(res => res.json())
        .then(data => {
            icon.className = "fas fa-search";
            if (data.encontrado) {
                document.getElementById('nombre_input').value = (data.first_name + ' ' + (data.second_name || '')).trim();
                document.getElementById('apellido_input').value = (data.first_surname + ' ' + (data.second_surname || '')).trim();
            } else {
                alert('Cédula no encontrada en el Padrón Nacional. Complete los campos manualmente.');
            }
        })
        .catch(err => {
            icon.className = "fas fa-search";
            console.log('Error al consultar el padrón:', err);
        });
}
</script>

<?php require_once '../../includes/footer.php'; ?>