<?php
// /sistema_nora/modulos/guarderia/index.php
require_once '../../config/db.php';
require_once '../../includes/header.php';
require_once '../../includes/representante_ubicacion.php';
require_once '../../includes/buscador_lista.php';

// Control de acceso: exclusivo de Centro de Cuidados Infantiles  / Administración
if (!tiene_permiso(['guarderia', 'admin', 'superadmin'], $roles_array)) {
    echo "<div class='alert alert-danger font-weight-bold'>Acceso restringido a Centro de Cuidados Infantiles.</div>";
    require_once '../../includes/footer.php';
    exit();
}

$mensaje = '';
$tipo_alerta = '';

// Registrar Niño / Niña
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_nino'])) {
    try {
        $cedula_rep = trim($_POST['cedula_representante']);
        $nombre_nino = trim($_POST['nombre_nino']);
        $apellido_nino = trim($_POST['apellido_nino']);
        $f_nac = $_POST['fecha_nacimiento'];
        $sexo = $_POST['sexo'];
        $observaciones = trim($_POST['observaciones']);

        // 1. El representante debe ser una persona activa de sistema_nora_v2.
        //    No se crean usuarios nuevos desde Centro de Cuidados Infantiles.
        $stmt_u = $pdo->prepare("SELECT id_usuario
                                 FROM public.usuarios
                                 WHERE regexp_replace(cedula, '[^0-9]', '', 'g') = :c
                                   AND estatus_cuenta = 'Activo'
                                 LIMIT 1");
        $stmt_u->execute([':c' => preg_replace('/\D+/', '', $cedula_rep)]);
        $id_representante = $stmt_u->fetchColumn();

        if (!$id_representante) {
            throw new Exception('El representante debe estar registrado y activo en sistema_nora_v2 (persona que haga vida en la escuela).');
        }

        // 2. Registrar al niño/a
        $sql_n = "INSERT INTO public.guarderia_ninos (id_representante, nombre_nino, apellido_nino, fecha_nacimiento, sexo, observaciones) 
                  VALUES (:id_rep, :nom, :ape, :fnac, :sexo, :obs)";
        $stmt_n = $pdo->prepare($sql_n);
        $stmt_n->execute([
            ':id_rep' => $id_representante,
            ':nom'   => $nombre_nino,
            ':ape'   => $apellido_nino,
            ':fnac'  => $f_nac,
            ':sexo'  => $sexo,
            ':obs'   => $observaciones
        ]);

        $mensaje = "¡Niño/a <strong>{$nombre_nino} {$apellido_nino}</strong> registrado/a con éxito en el Centro de Cuidados Infantiles!";
        $tipo_alerta = "success";
    } catch (Exception $e) {
        $mensaje = "Error al registrar: " . $e->getMessage();
        $tipo_alerta = "danger";
    }
}

// Consultar lista de niños registrados con datos del representante
$sql_lista = "SELECT n.*, 
                     CONCAT(u.nombre, ' ', u.apellido) AS nombre_representante,
                     u.cedula AS cedula_representante,
                     u.telefono AS telefono_representante
              FROM public.guarderia_ninos n
              JOIN public.usuarios u ON n.id_representante = u.id_usuario
              ORDER BY n.id_nino DESC";
$ninos = $pdo->query($sql_lista)->fetchAll();
?>

<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <h1 class="h3 mb-0 font-weight-bold" style="color: #521f29;">
        <i class="fas fa-baby mr-2 text-warning"></i> Centro de Cuidados Infantiles 
    </h1>
    <div>
        <a href="asistencia.php" class="btn btn-sm font-weight-bold shadow-sm text-white mr-1" style="background-color: #521f29;">
            <i class="fas fa-clipboard-check mr-1 text-warning"></i> Control Asistencia Hoy
        </a>
        <button class="btn btn-sm font-weight-bold text-dark shadow-sm" style="background-color: #ff8a00;" data-toggle="modal" data-target="#modalNuevoNino">
            <i class="fas fa-plus-circle mr-1"></i> Registrar Niño / Niña
        </button>
    </div>
</div>

<?php if (!empty($mensaje)): ?>
    <div class="alert alert-<?= $tipo_alerta ?> alert-dismissible fade show font-weight-bold shadow-sm" role="alert">
        <?= $mensaje ?>
        <button type="button" class="close" data-dismiss="alert" aria-label="Close">&times;</button>
    </div>
<?php endif; ?>

<!-- LISTADO DE NIÑOS CENSADOS -->
<div class="card shadow mb-4" style="border-radius: 15px; border: none;">
    <div class="card-header py-3" style="background-color: #521f29; border-top-left-radius: 15px; border-top-right-radius: 15px;">
        <h6 class="m-0 font-weight-bold text-white"><i class="fas fa-child mr-2"></i>Matrícula Infantil Registrada</h6>
    </div>
    <div class="card-body bg-white p-4">
        <?php buscador_lista_render('Buscar niño/a o representante por nombre o cédula…', '#tablaMatriculaInfantil table'); ?>
        <div class="table-responsive" id="tablaMatriculaInfantil">
            <table class="table table-hover align-middle" width="100%">
                <thead style="background-color: #ff8a00; color: #521f29;">
                    <tr>
                        <th>N°</th>
                        <th>Nombre del Niño / Niña</th>
                        <th>Edad / F. Nacimiento</th>
                        <th>Sexo</th>
                        <th>Representante (Madre / Padre)</th>
                        <th>Contacto</th>
                        <th>Observaciones / Alergias</th>
                        <th class="text-center">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($ninos)): ?>
                        <tr><td colspan="8" class="text-center text-muted py-4">No hay niños inscritos en el Centro de Cuidados Infantiles.</td></tr>
                    <?php else: ?>
                        <?php $idx = 1; foreach ($ninos as $n): 
                            $fnac = new DateTime($n['fecha_nacimiento']);
                            $hoy = new DateTime();
                            $edad = $hoy->diff($fnac)->y;
                            $meses = $hoy->diff($fnac)->m;
                            $ubicacion_rep = representante_ubicacion($pdo, (int) $n['id_representante']);
                        ?>
                            <tr>
                                <td class="font-weight-bold"><?= $idx++ ?></td>
                                <td class="font-weight-bold" style="color: #521f29; font-size: 11pt;">
                                    <?= htmlspecialchars($n['nombre_nino'] . ' ' . $n['apellido_nino']) ?>
                                </td>
                                <td>
                                    <strong><?= $edad ?> Años<?= $edad < 3 ? " con {$meses} meses" : '' ?></strong><br>
                                    <small class="text-muted"><?= date('d/m/Y', strtotime($n['fecha_nacimiento'])) ?></small>
                                </td>
                                <td><span class="badge badge-<?= $n['sexo'] === 'Femenino' ? 'danger' : 'primary' ?>"><?= $n['sexo'] ?></span></td>
                                <td>
                                    <strong style="color: #521f29;"><?= htmlspecialchars($n['nombre_representante']) ?></strong><br>
                                    <small class="text-muted">C.I: V-<?= htmlspecialchars($n['cedula_representante']) ?></small><br>
                                    <small class="text-muted"><i class="fas fa-map-marker-alt mr-1 text-warning"></i><?= htmlspecialchars($ubicacion_rep['texto']) ?></small>
                                </td>
                                <td><i class="fas fa-phone mr-1 text-muted"></i><?= htmlspecialchars($n['telefono_representante'] ?? 'S/D') ?></td>
                                <td><small><?= htmlspecialchars($n['observaciones'] ?? 'Sin observaciones') ?></small></td>
                                <td class="text-center">
                                    <a href="imprimir_ficha.php?id=<?= $n['id_nino'] ?>" target="_blank" class="btn btn-sm btn-danger font-weight-bold" title="Ficha PDF">
                                        <i class="fas fa-file-pdf"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- MODAL PARA INSCRIBIR NIÑO/NIÑA -->
<div class="modal fade" id="modalNuevoNino" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
        <div class="modal-content" style="border-radius: 15px;">
            <div class="modal-header text-white" style="background-color: #521f29; border-top-left-radius: 15px; border-top-right-radius: 15px;">
                <h5 class="modal-title font-weight-bold"><i class="fas fa-baby mr-2 text-warning"></i>Inscripción de Niño / Niña</h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <form method="POST" action="index.php">
            <?= csrf_field() ?>
                <div class="modal-body bg-light">
                    
                    <!-- DATOS DEL REPRESENTANTE -->
                    <div class="p-3 mb-3 bg-white rounded border">
                        <small class="font-weight-bold text-uppercase d-block mb-2" style="color: #521f29;"><i class="fas fa-id-card mr-1 text-warning"></i> Datos del Representante (Madre / Estudiante / Trabajador):</small>
                        <small class="text-muted d-block mb-2">Solo se permite registrar personas activas en sistema_nora_v2. La búsqueda no usa el Padrón Nacional.</small>
                        <div class="form-row">
                            <div class="col-md-4 mb-2">
                                <label class="font-weight-bold small" style="color: #521f29;">Cédula del Representante *</label>
                                <div class="input-group">
                                    <input type="text" name="cedula_representante" id="ced_rep_input" class="form-control font-weight-bold" placeholder="Ej: 12345678" onkeypress="if(event.key === 'Enter'){ event.preventDefault(); buscarRep(); }" required>
                                    <div class="input-group-append">
                                        <button type="button" onclick="buscarRep()" class="btn btn-warning font-weight-bold"><i class="fas fa-search" id="icon_rep"></i></button>
                                    </div>
                                </div>
                                <small id="feedback_rep" class="form-text text-muted"></small>
                            </div>
                            <div class="col-md-5 mb-2">
                                <label class="font-weight-bold small" style="color: #521f29;">Nombre del Representante *</label>
                                <input type="text" name="nombre_representante" id="nom_rep_input" class="form-control font-weight-bold" required>
                            </div>
                            <div class="col-md-3 mb-2">
                                <label class="font-weight-bold small" style="color: #521f29;">Teléfono *</label>
                                <input type="text" name="telefono_representante" id="tel_rep_input" class="form-control font-weight-bold" required>
                            </div>
                        </div>
                    </div>

                    <!-- DATOS DEL NIÑO / NIÑA -->
                    <div class="p-3 bg-white rounded border">
                        <small class="font-weight-bold text-uppercase d-block mb-2" style="color: #521f29;"><i class="fas fa-child mr-1 text-warning"></i> Datos del Niño / Niña:</small>
                        <div class="form-row">
                            <div class="col-md-6 mb-2">
                                <label class="font-weight-bold small" style="color: #521f29;">Nombres *</label>
                                <input type="text" name="nombre_nino" class="form-control font-weight-bold" required>
                            </div>
                            <div class="col-md-6 mb-2">
                                <label class="font-weight-bold small" style="color: #521f29;">Apellidos *</label>
                                <input type="text" name="apellido_nino" class="form-control font-weight-bold" required>
                            </div>
                        </div>
                        <div class="form-row">
                            <div class="col-md-6 mb-2">
                                <label class="font-weight-bold small" style="color: #521f29;">Fecha de Nacimiento *</label>
                                <input type="date" name="fecha_nacimiento" class="form-control font-weight-bold" required>
                            </div>
                            <div class="col-md-6 mb-2">
                                <label class="font-weight-bold small" style="color: #521f29;">Sexo *</label>
                                <select name="sexo" class="form-control font-weight-bold" required>
                                    <option value="Femenino">Femenino</option>
                                    <option value="Masculino">Masculino</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-group mb-0">
                            <label class="font-weight-bold small" style="color: #521f29;">Observaciones Médicas / Alergias / Medicamentos</label>
                            <textarea name="observaciones" class="form-control" rows="2" placeholder="Ej: Alérgico a la penicilina, intolerante a la lactosa..."></textarea>
                        </div>
                    </div>

                </div>
                <div class="modal-footer bg-white" style="border-bottom-left-radius: 15px; border-bottom-right-radius: 15px;">
                    <button type="button" class="btn btn-secondary font-weight-bold" data-dismiss="modal">Cancelar</button>
                    <button type="submit" name="guardar_nino" class="btn font-weight-bold text-white" style="background-color: #ff8a00;">
                        <i class="fas fa-save mr-1"></i> GUARDAR E INSCRIBIR NIÑO/A
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function buscarRep() {
    const ced = document.getElementById('ced_rep_input').value.replace(/\D/g, '');
    const fb = document.getElementById('feedback_rep');
    if (!ced) { fb.textContent = 'Ingrese una cédula para buscar.'; return; }
    document.getElementById('icon_rep').className = "fas fa-spinner fa-spin";
    fb.textContent = 'Consultando sistema_nora_v2...';

    fetch('api_buscar_representante.php?cedula=' + encodeURIComponent(ced))
        .then(res => res.json())
        .then(data => {
            document.getElementById('icon_rep').className = "fas fa-search";
            if (data.encontrado && data.activo) {
                document.getElementById('nom_rep_input').value = data.nombre_completo;
                document.getElementById('tel_rep_input').value = data.telefono || '';
                fb.textContent = data.mensaje + ' (' + data.tipo_usuario + ')';
                fb.className = 'form-text text-success font-weight-bold';
            } else {
                document.getElementById('nom_rep_input').value = '';
                document.getElementById('tel_rep_input').value = '';
                fb.textContent = data.mensaje || 'No se encontró la persona en el sistema escolar.';
                fb.className = 'form-text text-danger font-weight-bold';
                alert(fb.textContent);
            }
        })
        .catch(e => {
            document.getElementById('icon_rep').className = "fas fa-search";
            fb.textContent = 'No se pudo consultar el sistema escolar.';
            fb.className = 'form-text text-danger font-weight-bold';
        });
}
</script>

<?php require_once '../../includes/footer.php'; ?>