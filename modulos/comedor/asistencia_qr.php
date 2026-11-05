<?php
// /sistema_nora/modulos/comedor/asistencia_qr.php
require_once '../../config/db.php';
require_once '../../includes/header.php';

// Control de acceso: exclusivo de Comedor / Monitores / Administración
if (!tiene_permiso(['comedor', 'monitor', 'admin', 'superadmin'], $roles_array)) {
    echo "<div class='alert alert-danger font-weight-bold'>Acceso restringido a Comedor.</div>";
    require_once '../../includes/footer.php';
    exit();
}
?>

<div class="container py-3">
    <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap">
        <h3 class="font-weight-bold m-0 mb-2" style="color: #521f29;">
            <i class="fas fa-qrcode mr-2 text-warning"></i>Escanear Comensal (QR)
        </h3>
        <div>
            <span id="netStatusQr" class="badge badge-success px-3 py-2 font-weight-bold shadow-sm" style="font-size: 10pt;">
                <i class="fas fa-wifi mr-1"></i> EN LÍNEA
            </span>
            <button type="button" onclick="sincronizarPendientes()" class="btn btn-sm btn-dark font-weight-bold ml-2 shadow-sm">
                <i class="fas fa-sync-alt mr-1"></i> Sincronizar (<span id="countPendingQr">0</span>)
            </button>
            <a href="index.php" class="btn btn-sm btn-secondary font-weight-bold shadow-sm ml-1">
                <i class="fas fa-keyboard mr-1"></i> Modo Manual
            </a>
        </div>
    </div>

    <div class="row justify-content-center">
        <div class="col-md-6 text-center">
            
            <!-- Selector de Ingesta -->
            <div class="card shadow-sm mb-3 border-0" style="border-radius: 15px; background-color: #521f29;">
                <div class="card-body p-3 text-white">
                    <label class="font-weight-bold mb-1"><i class="fas fa-utensils mr-2"></i>Seleccionar Ingesta Actual:</label>
                    <select id="tipo_ingesta" class="form-control text-center font-weight-bold" style="border-radius: 10px; font-size: 12pt;">
                        <option value="Desayuno">☕ DESAYUNO</option>
                        <option value="Almuerzo" selected>🍔 ALMUERZO</option>
                        <option value="Merienda">🍎 MERIENDA</option>
                        <option value="Cena">🌙 CENA</option>
                    </select>
                </div>
            </div>

            <!-- Visor de la Cámara -->
            <div class="card shadow border-0 p-2" style="border-radius: 20px;">
                <div id="reader" style="width: 100%; border-radius: 15px; overflow: hidden;"></div>
            </div>

            <!-- Alerta de Respuesta del Registro -->
            <div id="feedback_qr" class="mt-3"></div>

        </div>
    </div>
</div>

<!-- Librería Oficial Html5Qrcode Scanner -->
<script src="https://unpkg.com/html5-qrcode"></script>

<script>
    let ultimoEscan = "";
    let estaProcesando = false;

    // ══════════ COLA OFFLINE (ronda 5) — usa la misma clave que el portal
    // manual ('comedor_pendientes') para que ambos formularios compartan la
    // cola y api_sincronizar.php (idempotente por UNIQUE) no duplique nada. ══
    function getColaComedor() {
        try { return JSON.parse(localStorage.getItem('comedor_pendientes') || '[]'); }
        catch (e) { return []; }
    }
    function actualizarContadorQr() {
        document.getElementById('countPendingQr').innerText = getColaComedor().length;
    }
    function tokenCSRF() {
        var m = document.querySelector('meta[name="csrf-token"]');
        return m ? m.content : '';
    }

    function actualizarEstadoRedQr() {
        var badge = document.getElementById('netStatusQr');
        if (navigator.onLine) {
            badge.className = "badge badge-success px-3 py-2 font-weight-bold shadow-sm";
            badge.innerHTML = '<i class="fas fa-wifi mr-1"></i> EN LÍNEA';
            sincronizarPendientes();
        } else {
            badge.className = "badge badge-danger px-3 py-2 font-weight-bold shadow-sm";
            badge.innerHTML = '<i class="fas fa-plane mr-1"></i> MODO OFFLINE';
        }
    }
    window.addEventListener('online', actualizarEstadoRedQr);
    window.addEventListener('offline', actualizarEstadoRedQr);

    function sincronizarPendientes() {
        var pendientes = getColaComedor();
        if (pendientes.length === 0) { actualizarContadorQr(); return; }
        if (!navigator.onLine) return;

        fetch('api_sincronizar.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': tokenCSRF()
            },
            body: JSON.stringify({ asistencias: pendientes })
        })
        .then(function (res) { return res.json(); })
        .then(function (data) {
            if (data.exito || data.status === 'success') {
                localStorage.setItem('comedor_pendientes', '[]');
                actualizarContadorQr();
            }
        })
        .catch(function () { /* sin red: la cola se conserva */ });
    }

    function alDetectarQR(textoCodificado, resultado) {
        if (estaProcesando || textoCodificado === ultimoEscan) return;
        
        estaProcesando = true;
        ultimoEscan = textoCodificado;

        const ingesta = document.getElementById('tipo_ingesta').value;
        const feedback = document.getElementById('feedback_qr');
        const ahora = new Date();

        // 1) Siempre guardar en la cola local (funciona sin internet)
        var cola = getColaComedor();
        cola.push({
            cedula: textoCodificado,
            turno: ingesta,
            fecha: ahora.toISOString().split('T')[0],
            hora: ahora.toLocaleTimeString()
        });
        localStorage.setItem('comedor_pendientes', JSON.stringify(cola));
        actualizarContadorQr();

        feedback.innerHTML = `
            <div class="alert alert-warning font-weight-bold shadow-sm">
                <i class="fas fa-save mr-2"></i> Guardado local: V-${textoCodificado} (${ingesta})
            </div>`;

        // 2) Si hay conexión, sincronizar de inmediato para obtener el nombre
        if (navigator.onLine) {
            fetch('api_registrar_qr.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-CSRF-Token': tokenCSRF()
                },
                body: new URLSearchParams({
                    'cedula': textoCodificado,
                    'ingesta': ingesta
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === 'success') {
                    feedback.innerHTML = `
                        <div class="alert alert-success font-weight-bold shadow-sm" style="font-size: 13pt;">
                            <i class="fas fa-check-circle mr-2"></i> ¡ATENCIÓN REGISTRADA!<br>
                            <span class="text-dark">${data.nombre_comensal}</span><br>
                            <small class="text-muted">Cédula: V-${textoCodificado} | ${ingesta}</small>
                        </div>`;
                    sincronizarPendientes(); // vacía la cola local (el servidor no duplica)
                } else {
                    feedback.innerHTML = `
                        <div class="alert alert-danger font-weight-bold shadow-sm">
                            <i class="fas fa-exclamation-triangle mr-2"></i> ${data.message}
                        </div>`;
                }
            })
            .catch(err => {
                feedback.innerHTML = `<div class="alert alert-info font-weight-bold shadow-sm">
                    <i class="fas fa-cloud mr-2"></i> Sin conexión: el registro quedó guardado en el dispositivo y se sincronizará solo.</div>`;
            });
        } else {
            feedback.innerHTML = `<div class="alert alert-info font-weight-bold shadow-sm">
                <i class="fas fa-cloud mr-2"></i> MODO OFFLINE: registro guardado en el dispositivo. Se sincronizará al volver la conexión.</div>`;
        }

        // Pausa de 2.5 segundos para evitar múltiples lecturas seguidas de la misma tarjeta
        setTimeout(() => {
            estaProcesando = false;
            ultimoEscan = "";
        }, 2500);
    }

    // Inicializar escáner para móviles y cámaras web
    let html5QrcodeScanner = new Html5QrcodeScanner(
        "reader", 
        { fps: 10, qrbox: { width: 220, height: 220 }, facingMode: "environment" },
        /* verbose= */ false
    );
    html5QrcodeScanner.render(alDetectarQR);

    document.addEventListener('DOMContentLoaded', function () {
        actualizarContadorQr();
        actualizarEstadoRedQr();
    });
</script>

<?php require_once '../../includes/footer.php'; ?>