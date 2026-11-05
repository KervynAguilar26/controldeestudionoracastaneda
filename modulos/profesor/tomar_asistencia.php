<?php
// /sistema_nora/modulos/profesor/tomar_asistencia.php
require_once '../../config/db.php';
require_once '../../includes/header.php';

// Control de acceso: docentes y administradores
if (!tiene_permiso(['profesor', 'admin', 'superadmin'], $roles_array)) {
    echo "<div class='alert alert-danger font-weight-bold'>Acceso restringido únicamente a Docentes.</div>";
    require_once '../../includes/footer.php';
    exit();
}

$id_cohorte = intval($_GET['id_cohorte'] ?? 0);
$id_profesor = $_SESSION['id_usuario'] ?? 0;

// Verificar que la cohorte exista y pertenezca a una asignación del profesor
$es_admin = in_array('admin', $roles_array) || in_array('superadmin', $roles_array);
$cohorte_ok = false;
if ($id_cohorte > 0) {
    $stmt_c = $pdo->prepare("SELECT id_cohorte FROM public.cohortes WHERE id_cohorte = :id");
    $stmt_c->execute([':id' => $id_cohorte]);
    if ($stmt_c->fetch()) {
        if ($es_admin) {
            $cohorte_ok = true;
        } else {
            $stmt_o = $pdo->prepare("SELECT COUNT(*) FROM public.asignaciones_profesor WHERE id_cohorte = :c AND id_profesor = :p");
            $stmt_o->execute([':c' => $id_cohorte, ':p' => $id_profesor]);
            $cohorte_ok = ($stmt_o->fetchColumn() > 0);
        }
    }
}

if (!$cohorte_ok) {
    echo "<div class='alert alert-danger font-weight-bold'>Cohorte no encontrada o sin asignación a su perfil docente.</div>";
    require_once '../../includes/footer.php';
    exit();
}

// Registrar Asistencia (Soporta llamadas AJAX asíncronas para el modo continuo)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_cedula'])) {
    header('Content-Type: application/json');
    $cedula = trim($_POST['ajax_cedula']);
    
    $stmt_u = $pdo->prepare("SELECT id_usuario, nombre, apellido FROM public.usuarios WHERE cedula = :c LIMIT 1");
    $stmt_u->execute([':c' => $cedula]);
    $estudiante = $stmt_u->fetch();

    if (!$estudiante) {
        echo json_encode(['status' => 'error', 'message' => "Cédula V-{$cedula} no encontrada en el sistema."]);
        exit;
    }

    $stmt_ins = $pdo->prepare("SELECT COUNT(*) FROM public.inscripciones WHERE id_curso = :coh AND id_estudiante = :est");
    $stmt_ins->execute([':coh' => $id_cohorte, ':est' => $estudiante['id_usuario']]);
    if ($stmt_ins->fetchColumn() == 0) {
        echo json_encode(['status' => 'error', 'message' => "V-{$cedula} ({$estudiante['nombre']} {$estudiante['apellido']}) no está inscrito en esta sección."]);
        exit;
    }

    $sql = "INSERT INTO public.asistencia_clases (id_cohorte, id_estudiante, fecha_clase, estatus) 
            VALUES (:coh, :est, CURRENT_DATE, 'Presente')
            ON CONFLICT (id_cohorte, id_estudiante, fecha_clase) DO UPDATE SET hora_registro = CURRENT_TIME";
    $pdo->prepare($sql)->execute([':coh' => $id_cohorte, ':est' => $estudiante['id_usuario']]);

    echo json_encode([
        'status' => 'success', 
        'message' => "¡Asistencia registrada: {$estudiante['nombre']} {$estudiante['apellido']} (V-{$cedula})!"
    ]);
    exit;
}

// Registro tradicional por formulario POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['marcar_asistencia'])) {
    $cedula = trim($_POST['cedula_estudiante']);
    $stmt_u = $pdo->prepare("SELECT id_usuario, nombre, apellido FROM public.usuarios WHERE cedula = :c LIMIT 1");
    $stmt_u->execute([':c' => $cedula]);
    $estudiante = $stmt_u->fetch();

    if ($estudiante) {
        $stmt_ins = $pdo->prepare("SELECT COUNT(*) FROM public.inscripciones WHERE id_curso = :coh AND id_estudiante = :est");
        $stmt_ins->execute([':coh' => $id_cohorte, ':est' => $estudiante['id_usuario']]);
        if ($stmt_ins->fetchColumn() == 0) {
            $msg_error = "V-{$cedula} ({$estudiante['nombre']} {$estudiante['apellido']}) no figura inscrito en esta cohorte.";
        } else {
            $sql = "INSERT INTO public.asistencia_clases (id_cohorte, id_estudiante, fecha_clase, estatus) 
                    VALUES (:coh, :est, CURRENT_DATE, 'Presente')
                    ON CONFLICT (id_cohorte, id_estudiante, fecha_clase) DO UPDATE SET hora_registro = CURRENT_TIME";
            $pdo->prepare($sql)->execute([':coh' => $id_cohorte, ':est' => $estudiante['id_usuario']]);
            $msg_exito = "¡Asistencia marcada para V-{$cedula} ({$estudiante['nombre']} {$estudiante['apellido']})!";
        }
    } else {
        $msg_error = "Estudiante con cédula V-{$cedula} no encontrado en el sistema.";
    }
}

// Consultar listado de asistencia del día
$sql_lista = "SELECT u.id_usuario, u.cedula, u.nombre, u.apellido,
                     ac.hora_registro, ac.estatus
              FROM public.inscripciones i
              JOIN public.usuarios u ON i.id_estudiante = u.id_usuario
              LEFT JOIN public.asistencia_clases ac ON ac.id_estudiante = u.id_usuario AND ac.id_cohorte = :coh AND ac.fecha_clase = CURRENT_DATE
              WHERE i.id_curso = :coh
              ORDER BY u.apellido ASC";

$stmt_l = $pdo->prepare($sql_lista);
$stmt_l->execute([':coh' => $id_cohorte]);
$asistencias_hoy = $stmt_l->fetchAll();
?>

<!-- Cargar librería QR Scanner -->
<script src="https://unpkg.com/html5-qrcode"></script>

<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <h1 class="h3 mb-0 font-weight-bold" style="color: #521f29;">
        <i class="fas fa-qrcode mr-2 text-warning"></i>Control de Asistencia de Clases
    </h1>
    <a href="index.php" class="btn btn-sm btn-secondary font-weight-bold shadow-sm"><i class="fas fa-arrow-left mr-1"></i> Volver</a>
</div>

<?php if (isset($msg_exito)): ?>
    <div class="alert alert-success font-weight-bold shadow-sm"><?= htmlspecialchars($msg_exito) ?></div>
<?php endif; ?>
<?php if (isset($msg_error)): ?>
    <div class="alert alert-danger font-weight-bold shadow-sm"><?= htmlspecialchars($msg_error) ?></div>
<?php endif; ?>

<!-- Alerta flotante para retroalimentación instantánea del escáner continuo -->
<div id="scannerAlert" class="alert font-weight-bold shadow-sm" style="display: none;"></div>

<div class="row">
    <!-- PANEL DE LECTURA CÉDULA / CAMARA QR -->
    <div class="col-lg-5 mb-4">
        <div class="card shadow border-left-nora" style="border-radius: 15px; border: none;">
            <div class="card-header py-3" style="background-color: #521f29; border-top-left-radius: 15px; border-top-right-radius: 15px;">
                <h6 class="m-0 font-weight-bold text-white"><i class="fas fa-id-card mr-2"></i>Escanear o Ingresar Cédula</h6>
            </div>
            <div class="card-body bg-white p-4">
                <form method="POST" action="tomar_asistencia.php?id_cohorte=<?= $id_cohorte ?>">
                    <?php if (function_exists('csrf_field')) { echo csrf_field(); } ?>
                    <div class="form-group mb-3">
                        <label class="font-weight-bold" style="color: #521f29;">Cédula de Identidad *</label>
                        <div class="input-group">
                            <input type="text" name="cedula_estudiante" id="cedulaEst" class="form-control form-control-lg font-weight-bold text-center" placeholder="Ej: 12345678" required autofocus>
                            <div class="input-group-append">
                                <button type="button" class="btn text-white font-weight-bold" style="background-color: #521f29;" data-toggle="modal" data-target="#modalQRProfesor" onclick="iniciarCamaraDocente()">
                                    <i class="fas fa-camera text-warning"></i> Escáner QR
                                </button>
                            </div>
                        </div>
                    </div>
                    <button type="submit" name="marcar_asistencia" class="btn btn-block font-weight-bold text-white py-3 shadow" style="background-color: #ff8a00;">
                        <i class="fas fa-check-circle mr-1"></i> REGISTRAR ASISTENCIA HOY
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- TABLA DE ESTUDIANTES DE LA SECCIÓN -->
    <div class="col-lg-7 mb-4">
        <div class="card shadow" style="border-radius: 15px; border: none;">
            <div class="card-header py-3" style="background-color: #521f29; border-top-left-radius: 15px; border-top-right-radius: 15px;">
                <h6 class="m-0 font-weight-bold text-white"><i class="fas fa-users mr-2"></i>Asistencia del Día (<?= date('d/m/Y') ?>)</h6>
            </div>
            <div class="card-body bg-white p-4">
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead style="background-color: #ff8a00; color: #521f29;">
                            <tr>
                                <th>Estudiante / Cédula</th>
                                <th>Hora Marcaje</th>
                                <th>Estatus</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($asistencias_hoy as $ash): ?>
                                <tr>
                                    <td class="font-weight-bold" style="color: #521f29;">
                                        <?= htmlspecialchars($ash['nombre'] . ' ' . $ash['apellido']) ?><br>
                                        <small class="text-muted">C.I: V-<?= htmlspecialchars($ash['cedula']) ?></small>
                                    </td>
                                    <td><?= $ash['hora_registro'] ? date('h:i:s A', strtotime($ash['hora_registro'])) : '--:--' ?></td>
                                    <td>
                                        <?php if ($ash['estatus'] === 'Presente'): ?>
                                            <span class="badge badge-success px-2 py-1"><i class="fas fa-check mr-1"></i> Presente</span>
                                        <?php else: ?>
                                            <span class="badge badge-light border text-muted">Ausente</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- MODAL CÁMARA QR CONTINUO CON BOTÓN STOP -->
<div class="modal fade" id="modalQRProfesor" tabindex="-1" role="dialog" aria-hidden="true" data-backdrop="static">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content" style="border-radius: 15px;">
            <div class="modal-header text-white" style="background-color: #521f29;">
                <h5 class="modal-title font-weight-bold"><i class="fas fa-qrcode mr-2 text-warning"></i>Modo Escáner Continuo</h5>
                <button type="button" class="close text-white" data-dismiss="modal" onclick="detenerCamaraDocente()">&times;</button>
            </div>
            <div class="modal-body text-center bg-light">
                <div id="lector_camara_docente" style="width: 100%; max-width: 350px; margin: 0 auto; border-radius: 10px; overflow: hidden;"></div>
                <div id="qrStatusMsg" class="mt-2 font-weight-bold text-info" style="min-height: 24px;"></div>
            </div>
            <div class="modal-footer bg-white justify-content-center">
                <button type="button" class="btn btn-danger font-weight-bold px-4 py-2 shadow" data-dismiss="modal" onclick="detenerCamaraDocente()">
                    <i class="fas fa-stop-circle mr-2"></i> DETENER Y SALIR (STOP)
                </button>
            </div>
        </div>
    </div>
</div>

<script>
let qrDocente = null;
let ultimoEscaneo = "";
let tiempoUltimoEscaneo = 0;

function iniciarCamaraDocente() {
    if (!qrDocente) {
        qrDocente = new Html5Qrcode("lector_camara_docente");
    }
    
    document.getElementById('qrStatusMsg').innerText = "Cámara activa. Escanee códigos QR seguidos...";

    qrDocente.start(
        { facingMode: "environment" }, 
        { fps: 15, qrbox: 220 }, 
        (decodedText) => {
            let ahora = Date.now();
            // Evitar lecturas duplicadas en menos de 3 segundos del mismo carnet
            if (decodedText === ultimoEscaneo && (ahora - tiempoUltimoEscaneo) < 3000) {
                return;
            }
            ultimoEscaneo = decodedText;
            tiempoUltimoEscaneo = ahora;

            document.getElementById('qrStatusMsg').innerText = "Procesando cédula: " + decodedText + "...";

            // Enviar petición asíncrona (AJAX) para registrar sin cerrar la cámara
            let formData = new FormData();
            formData.append('ajax_cedula', decodedText);

            fetch('tomar_asistencia.php?id_cohorte=<?= $id_cohorte ?>', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                let alertBox = document.getElementById('scannerAlert');
                alertBox.style.display = 'block';
                alertBox.className = data.status === 'success' ? 'alert alert-success font-weight-bold shadow-sm' : 'alert alert-danger font-weight-bold shadow-sm';
                alertBox.innerText = data.message;

                document.getElementById('qrStatusMsg').innerText = data.message;

                // Ocultar la alerta a los 4 segundos y refrescar tabla si fue exitoso
                setTimeout(() => {
                    alertBox.style.display = 'none';
                    if(data.status === 'success') {
                        location.reload(); // Recarga para actualizar el listado de la derecha en tiempo real
                    }
                }, 3000);
            })
            .catch(error => {
                console.error('Error:', error);
            });
        },
        (errorMessage) => {
            // Errores de escaneo en tiempo real por fotograma se ignoran para no interrumpir
        }
    ).catch(err => {
        console.error("Error al iniciar la cámara:", err);
        document.getElementById('qrStatusMsg').innerText = "Error al iniciar la cámara del dispositivo.";
    });
}

function detenerCamaraDocente() {
    if (qrDocente && qrDocente.isScanning) {
        qrDocente.stop().then(() => {
            qrDocente.clear();
        }).catch(err => {
            console.error("Error al detener la cámara:", err);
        });
    }
}

// Asegurar que la cámara se detenga al cerrar el modal por cualquier medio
$('#modalQRProfesor').on('hidden.bs.modal', function () {
    detenerCamaraDocente();
});
</script>

<?php require_once '../../includes/footer.php'; ?>