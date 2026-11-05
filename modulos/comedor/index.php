<?php
// /sistema_nora/modulos/comedor/index.php
require_once '../../config/db.php';
require_once '../../includes/header.php';

// Control de acceso: exclusivo de Comedor / Monitores / Administración
if (!tiene_permiso(['comedor', 'monitor', 'admin', 'superadmin'], $roles_array)) {
    echo "<div class='alert alert-danger font-weight-bold'>Acceso restringido a Comedor.</div>";
    require_once '../../includes/footer.php';
    exit();
}

// Consultar menú del día
$menu_hoy = null;
try {
    $stmt_m =$pdo->prepare("SELECT * FROM comedor_menu WHERE fecha = CURRENT_DATE LIMIT 1");
    $stmt_m->execute();
    $menu_hoy =$stmt_m->fetch();
} catch (Exception $e) {}

// Consultar asistencias registradas hoy en el servidor
try {
    $sql_c = "SELECT a.*, u.cedula, u.nombre, u.apellido, u.tipo_usuario 
              FROM comedor_asistencia a 
              JOIN usuarios u ON a.id_usuario = u.id_usuario 
              WHERE DATE(a.fecha_registro) = CURRENT_DATE 
              ORDER BY a.id_asistencia DESC";
    $asistencias_hoy = $pdo->query($sql_c)->fetchAll();
} catch (Exception $e) {$asistencias_hoy = [];
}
?>

<!-- Cargar librería oficial Html5Qrcode Scanner -->
<script src="https://unpkg.com/html5-qrcode"></script>

<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <h1 class="h3 mb-0 font-weight-bold" style="color: #521f29;">
        <i class="fas fa-utensils mr-2"></i>Módulo de Comedor (Sincronizado Offline)
    </h1>
    
    <!-- Indicador de Conexión Offline / Online -->
    <div>
        <span id="netStatus" class="badge badge-success px-3 py-2 font-weight-bold shadow-sm" style="font-size: 10pt;">
            <i class="fas fa-wifi mr-1"></i> EN LÍNEA
        </span>
        <button id="btnSync" onclick="sincronizarPendientes();" class="btn btn-sm btn-dark font-weight-bold ml-2 shadow-sm">
            <i class="fas fa-sync-alt mr-1"></i> Sincronizar (<span id="countPending">0</span>)
        </button>
        <a href="imprimir_comensales.php" class="btn btn-sm font-weight-bold shadow-sm text-white ml-1" style="background-color: #521f29;">
            <i class="fas fa-print mr-1"></i> Reporte Ingestas
        </a>
        <a href="inventario.php" class="btn btn-sm font-weight-bold shadow-sm ml-1" style="background-color: #ff8a00; color: #521f29;">
            <i class="fas fa-boxes mr-1"></i> Inventario de Alimentos
        </a>
    </div>
</div>

<div class="row">
    
    <!-- Panel Táctil / Escáner de Marcaje Offline + QR Continuo -->
    <div class="col-lg-5 mb-4">
        <div class="card shadow border-left-nora h-100" style="border-radius: 15px; border: none;">
            <div class="card-header py-3" style="background-color: #521f29; border-top-left-radius: 15px; border-top-right-radius: 15px;">
                <h6 class="m-0 font-weight-bold text-white"><i class="fas fa-id-card mr-2"></i>Registro Rápido de Comensal</h6>
            </div>
            <div class="card-body bg-white p-4" style="border-bottom-left-radius: 15px; border-bottom-right-radius: 15px;">
                
                <form id="formMarcaje" onsubmit="registrarMarcajeLocal(event);">
                    <div class="form-group mb-3">
                        <label class="font-weight-bold" style="color: #521f29;">Seleccionar Turno *</label>
                        <select id="turno" class="form-control font-weight-bold" required style="border: 2px solid #521f29;">
                            <option value="Almuerzo" selected>Almuerzo</option>
                            <option value="Desayuno">Desayuno</option>
                            <option value="Merienda">Merienda</option>
                            <option value="Cena">Cena</option>
                        </select>
                    </div>

                    <div class="form-group mb-3">
                        <label class="font-weight-bold" style="color: #521f29;">Número de Cédula *</label>
                        <div class="input-group">
                            <input type="text" id="cedulaComensal" class="form-control form-control-lg font-weight-bold text-center" placeholder="Ej: 12345678" required autofocus autocomplete="off" style="font-size: 16pt;">
                            <div class="input-group-append">
                                <button type="button" class="btn text-white font-weight-bold shadow-sm" style="background-color: #521f29;" data-toggle="modal" data-target="#modalEscanerQR" onclick="iniciarCamaraRapida()">
                                    <i class="fas fa-qrcode mr-1 text-warning"></i> ESCANEAR QR
                                </button>
                            </div>
                        </div>
                        <small class="form-text text-muted text-center">Escriba o escanee la cédula y presione ENTER</small>
                    </div>

                    <button type="submit" class="btn btn-block font-weight-bold py-3 shadow-sm" style="background-color: #ff8a00; color: #521f29; border: none; font-size: 12pt;">
                        <i class="fas fa-check-circle mr-2"></i> REGISTRAR PLATO / SERVICIO
                    </button>
                </form>

                <div id="alertaMarcaje" class="mt-3"></div>

                <!-- Resumen de Plato del Día -->
                <div class="mt-4 p-3 rounded" style="background-color: rgba(82, 31, 41, 0.05); border: 1px dashed #521f29;">
                    <strong style="color: #521f29;"><i class="fas fa-drumstick-bite mr-1"></i> Menú de Hoy:</strong>
                    <p class="mb-0 small text-muted font-weight-bold"><?= htmlspecialchars($menu_hoy['descripcion_plato'] ?? 'Menú no publicado para la fecha.') ?></p>
                </div>

            </div>
        </div>
    </div>

    <!-- Lista de Comensales del Día -->
    <div class="col-lg-7 mb-4">
        <div class="card shadow mb-4" style="border-radius: 15px; border: none;">
            <div class="card-header py-3" style="background-color: #521f29; border-top-left-radius: 15px; border-top-right-radius: 15px;">
                <h6 class="m-0 font-weight-bold text-white"><i class="fas fa-list-alt mr-2"></i>Comensales Atendidos Hoy</h6>
            </div>
            <div class="card-body bg-white" style="border-bottom-left-radius: 15px; border-bottom-right-radius: 15px;">
                
                <div class="table-responsive">
                    <table class="table table-hover align-middle" id="tablaAsistencia" width="100%">
                        <thead style="background-color: #ff8a00; color: #521f29;">
                            <tr>
                                <th>Cédula / Nombre</th>
                                <th>Turno</th>
                                <th>Hora</th>
                                <th>Estatus Sincronización</th>
                            </tr>
                        </thead>
                        <tbody id="bodyAsistencia">
                            <?php if (empty($asistencias_hoy)): ?>
                                <tr id="rowSinDatos">
                                    <td colspan="4" class="text-center text-muted py-3">No hay atenciones registradas hoy en el servidor.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($asistencias_hoy as$a): ?>
                                    <tr>
                                        <td class="font-weight-bold">V-<?= htmlspecialchars($a['cedula']) ?> - <?= htmlspecialchars($a['nombre'] . ' ' .$a['apellido']) ?></td>
                                        <td><span class="badge badge-dark"><?= htmlspecialchars($a['turno'] ?? $a['tipo_ingesta'] ?? 'Almuerzo') ?></span></td>
                                        <td><?= date('h:i:s A', strtotime($a['fecha_registro'] ?? $a['fecha_hora'])) ?></td>
                                        <td><span class="badge badge-success px-2 py-1"><i class="fas fa-cloud-upload-alt mr-1"></i> Sincronizado</span></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

            </div>
        </div>
    </div>

</div>

<!-- MODAL CÁMARA CONTINUA PARA FILA RÁPIDA -->
<div class="modal fade" id="modalEscanerQR" tabindex="-1" role="dialog" aria-hidden="true" data-backdrop="static">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content" style="border-radius: 20px;">
            <div class="modal-header text-white" style="background-color: #521f29; border-top-left-radius: 20px; border-top-right-radius: 20px;">
                <h5 class="modal-title font-weight-bold"><i class="fas fa-camera mr-2 text-warning"></i>Escáner Continuo de Comensales</h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close" onclick="detenerCamara()">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body text-center bg-light">
                <p class="small text-muted font-weight-bold mb-2">Mantenga el QR frente a la cámara. El sistema registrará cada cédula automáticamente sin apagar el lector.</p>
                
                <div id="lector_camara" style="width: 100%; max-width: 380px; margin: 0 auto; border-radius: 15px; overflow: hidden;" class="shadow border"></div>

                <div id="alerta_marcaje_rapido" class="mt-3"></div>
            </div>
            <div class="modal-footer justify-content-between bg-white" style="border-bottom-left-radius: 20px; border-bottom-right-radius: 20px;">
                <span class="badge badge-success px-3 py-2"><i class="fas fa-circle mr-1 fa-spin"></i> CÁMARA ACTIVA</span>
                <button type="button" class="btn btn-secondary font-weight-bold" data-dismiss="modal" onclick="detenerCamara()">Cerrar Escáner</button>
            </div>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>

<!-- SCRIPT PWA & LOCALSTORAGE SYNC & CÁMARA CONTINUA -->
<script>
function actualizarEstadoRed() {
    const statusBad = document.getElementById('netStatus');
    if (navigator.onLine) {
        statusBad.className = "badge badge-success px-3 py-2 font-weight-bold shadow-sm";
        statusBad.innerHTML = '<i class="fas fa-wifi mr-1"></i> EN LÍNEA';
        sincronizarPendientes();
    } else {
        statusBad.className = "badge badge-danger px-3 py-2 font-weight-bold shadow-sm";
        statusBad.innerHTML = '<i class="fas fa-plane mr-1"></i> MODO OFFLINE';
    }
}

window.addEventListener('online', actualizarEstadoRed);
window.addEventListener('offline', actualizarEstadoRed);

function getMarcajesLocales() {
    return JSON.parse(localStorage.getItem('comedor_pendientes') || '[]');
}

function updatePendingCount() {
    const list = getMarcajesLocales();
    document.getElementById('countPending').innerText = list.length;
}

function agregarFilaTabla(cedula, turno, hora, estatusTexto, estatusClase) {
    const rowSinDatos = document.getElementById('rowSinDatos');
    if (rowSinDatos) rowSinDatos.remove();

    const body = document.getElementById('bodyAsistencia');
    const fila = document.createElement('tr');
    fila.innerHTML = `
        <td class="font-weight-bold">V-${cedula}</td>
        <td><span class="badge badge-dark">${turno}</span></td>
        <td>${hora}</td>
        <td><span class="badge ${estatusClase} px-2 py-1"><i class="fas fa-clock mr-1"></i> ${estatusTexto}</span></td>
    `;
    body.insertBefore(fila, body.firstChild);
}

function registrarMarcajeLocal(e) {
    if (e) e.preventDefault();
    const cedulaInput = document.getElementById('cedulaComensal');
    const turnoInput  = document.getElementById('turno');
    const cedula = cedulaInput.value.trim();
    const turno  = turnoInput.value;

    if (!cedula) return;

    const ahora = new Date();
    const fecha = ahora.toISOString().split('T')[0];
    const hora  = ahora.toLocaleTimeString();

    const nuevoMarcaje = { cedula, turno, fecha, hora };

    let pendientes = getMarcajesLocales();
    pendientes.push(nuevoMarcaje);
    localStorage.setItem('comedor_pendientes', JSON.stringify(pendientes));

    agregarFilaTabla(cedula, turno, hora, 'Guardado Local', 'badge-warning text-dark');

    cedulaInput.value = '';
    cedulaInput.focus();
    updatePendingCount();

    if (navigator.onLine) {
        sincronizarPendientes();
    }
}

function sincronizarPendientes() {
    let pendientes = getMarcajesLocales();
    if (pendientes.length === 0) return;

    fetch('api_sincronizar.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': (document.querySelector('meta[name="csrf-token"]') || {}).content || ''
        },
        body: JSON.stringify({ asistencias: pendientes })
    })
    .then(res => res.json())
    .then(data => {
        if (data.exito || data.status === 'success') {
            localStorage.setItem('comedor_pendientes', '[]');
            updatePendingCount();
            console.log('Sincronización exitosa con servidor.');
        }
    })
    .catch(err => console.log('Sincronización diferida por falla de red.'));
}

// --- LÓGICA DE CÁMARA CONTINUA (CÓDIGO QR EN BUCLE) ---
let html5QrCode = null;
let procesandoScan = false;
let ultimaCedula = "";

function iniciarCamaraRapida() {
    procesandoScan = false;
    ultimaCedula = "";
    document.getElementById('alerta_marcaje_rapido').innerHTML = '';

    if (!html5QrCode) {
        html5QrCode = new Html5Qrcode("lector_camara");
    }

    const config = { fps: 15, qrbox: { width: 220, height: 220 } };

    html5QrCode.start(
        { facingMode: "environment" }, 
        config, 
        (decodedText) => {
            if (procesandoScan || decodedText === ultimaCedula) return;
            
            procesandoScan = true;
            ultimaCedula = decodedText;

            const turno = document.getElementById('turno').value;
            const alerta = document.getElementById('alerta_marcaje_rapido');

            alerta.innerHTML = `
                <div class="alert alert-warning font-weight-bold mb-0 shadow-sm">
                    <i class="fas fa-spinner fa-spin mr-2"></i>Registrando V-${decodedText}...
                </div>`;

            const ahora = new Date();
            const hora = ahora.toLocaleTimeString();

            let pendientes = getMarcajesLocales();
            pendientes.push({ cedula: decodedText, turno: turno, fecha: ahora.toISOString().split('T')[0], hora: hora });
            localStorage.setItem('comedor_pendientes', JSON.stringify(pendientes));
            updatePendingCount();

            agregarFilaTabla(decodedText, turno, hora, 'Guardado QR', 'badge-warning text-dark');

            if (navigator.onLine) {
                fetch('api_registrar_qr.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-CSRF-Token': (document.querySelector('meta[name="csrf-token"]') || {}).content || ''
                    },
                    body: new URLSearchParams({ 'cedula': decodedText, 'ingesta': turno })
                })
                .then(res => res.json())
                .then(data => {
                    let nombreMostrar = data.nombre_comensal || ("V-" + decodedText);
                    alerta.innerHTML = `
                        <div class="alert alert-success font-weight-bold mb-0 shadow-sm" style="font-size: 11pt;">
                            <i class="fas fa-check-circle mr-2"></i>¡REGISTRO EXITOSO!<br>
                            <span class="text-dark">${nombreMostrar}</span><br>
                            <small class="text-muted">Turno: ${turno}</small>
                        </div>`;
                })
                .catch(e => {
                    alerta.innerHTML = `
                        <div class="alert alert-info font-weight-bold mb-0 shadow-sm">
                            <i class="fas fa-save mr-2"></i>Registrado Offline (Local).
                        </div>`;
                });
            } else {
                alerta.innerHTML = `
                    <div class="alert alert-info font-weight-bold mb-0 shadow-sm">
                        <i class="fas fa-save mr-2"></i>Registrado Offline (Local).
                    </div>`;
            }

            let audio = new Audio('https://actions.google.com/sounds/v1/badges/positive_notification-beep.ogg');
            audio.play().catch(e => {});

            setTimeout(() => {
                procesandoScan = false;
                ultimaCedula = "";
            }, 2000);
        }
    ).catch(err => {
        document.getElementById('alerta_marcaje_rapido').innerHTML = `
            <div class="alert alert-danger font-weight-bold mb-0">No se pudo activar la cámara del dispositivo.</div>`;
    });
}

function detenerCamara() {
    if (html5QrCode && html5QrCode.isScanning) {
        html5QrCode.stop().then(() => {
            html5QrCode.clear();
        }).catch(err => {});
    }
}

document.addEventListener('DOMContentLoaded', () => {
    actualizarEstadoRed();
    updatePendingCount();
});
</script>