<?php
// /sistema_nora/modulos/configuracion/infra_movil.php
// ═══════════════════════════════════════════════════════════════════════════
// PORTAL MÓVIL DE INFRAESTRUCURA (ronda 5) — diseñado para monitores en
// teléfonos, con soporte OFFLINE: cada registro se guarda en una cola local
// (localStorage) y se sincroniza vía api_infra_sincronizar.php al volver la
// conexión. Idempotente por UUID: re-sincronizar nunca duplica registros.
// ═══════════════════════════════════════════════════════════════════════════
require_once '../../config/db.php';
require_once '../../includes/header.php';

// Control de acceso: Monitores y Administración
if (!tiene_permiso(['monitor', 'admin', 'superadmin'], $roles_array)) {
    echo "<div class='alert alert-danger font-weight-bold'>Acceso restringido a Monitores de Campo.</div>";
    require_once '../../includes/footer.php';
    exit();
}

$tipos_espacio_validos = ['Aula', 'Auditorio', 'Dormitorio/Residencia', 'Aula Virtual'];

// Torres y espacios actuales (también se cachean en el navegador vía JS)
try {
    $torres = $pdo->query("SELECT id_edificio, nombre_torre, total_pisos, estatus
                           FROM public.campus_edificios ORDER BY nombre_torre ASC")->fetchAll();
} catch (Exception $e) { $torres = []; }

try {
    $espacios = $pdo->query("SELECT e.id_espacio, e.nombre_espacio, e.tipo_espacio, e.estatus,
                                    e.id_edificio, COALESCE(t.nombre_torre, 'Sede Principal') AS torre
                             FROM public.campus_espacios e
                             LEFT JOIN public.campus_edificios t ON t.id_edificio = e.id_edificio
                             ORDER BY e.nombre_espacio ASC")->fetchAll();
} catch (Exception $e) { $espacios = []; }

// Últimos registros sincronizados (referencia visual para el monitor)
try {
    $recientes = $pdo->query("SELECT s.accion, s.fecha_servidor, s.resultado, u.nombre || ' ' || u.apellido AS monitor
                              FROM public.monitor_sincronizaciones s
                              JOIN public.usuarios u ON u.id_usuario = s.id_usuario
                              WHERE s.modulo = 'infraestructura'
                              ORDER BY s.fecha_servidor DESC LIMIT 8")->fetchAll();
} catch (Exception $e) { $recientes = []; }
?>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap">
    <h1 class="h3 mb-2 font-weight-bold" style="color: #521f29;">
        <i class="fas fa-building mr-2 text-warning"></i>Infraestructura del Campus
    </h1>
    <div class="mb-2">
        <span id="netStatus" class="badge badge-success px-3 py-2 font-weight-bold shadow-sm" style="font-size: 10pt;">
            <i class="fas fa-wifi mr-1"></i> EN LÍNEA
        </span>
        <button type="button" onclick="sincronizarInfra()" class="btn btn-sm btn-dark font-weight-bold ml-2 shadow-sm">
            <i class="fas fa-sync-alt mr-1"></i> Sincronizar (<span id="countPending">0</span>)
        </button>
    </div>
</div>

<div id="resumenSync" class="mb-3"></div>

<!-- ══════════ REGISTRAR TORRE / EDIFICIO ══════════ -->
<div class="card shadow mb-4 border-0" style="border-radius: 15px;">
    <div class="card-header py-3" style="background-color: #521f29; border-top-left-radius: 15px; border-top-right-radius: 15px;">
        <h6 class="m-0 font-weight-bold text-white"><i class="fas fa fa-city mr-2 text-warning"></i>1. Registrar Torre o Edificio</h6>
    </div>
    <div class="card-body bg-white" style="border-bottom-left-radius: 15px; border-bottom-right-radius: 15px;">
        <form id="formTorre" autocomplete="off">
            <div class="form-group">
                <label class="font-weight-bold" style="color: #521f29;">Nombre de la Torre / Edificio *</label>
                <input type="text" id="torre_nombre" class="form-control form-control-lg" placeholder="Ej: Torre B" required>
            </div>
            <div class="form-group">
                <label class="font-weight-bold" style="color: #521f29;">Ubicación / Sede</label>
                <input type="text" id="torre_ubicacion" class="form-control" placeholder="Ej: Sede Principal" value="Sede Principal">
            </div>
            <div class="form-group">
                <label class="font-weight-bold" style="color: #521f29;">Total de Pisos</label>
                <input type="number" id="torre_pisos" class="form-control" min="1" max="30" value="1">
            </div>
            <button type="submit" class="btn btn-block btn-lg font-weight-bold shadow-sm" style="background-color: #ff8a00; color: #521f29;">
                <i class="fas fa-plus-circle mr-2"></i>GUARDAR TORRE
            </button>
        </form>
    </div>
</div>

<!-- ══════════ REGISTRAR ESPACIO / SALÓN / DORMITORIO ══════════ -->
<div class="card shadow mb-4 border-0" style="border-radius: 15px;">
    <div class="card-header py-3" style="background-color: #521f29; border-top-left-radius: 15px; border-top-right-radius: 15px;">
        <h6 class="m-0 font-weight-bold text-white"><i class="fas fa-door-open mr-2 text-warning"></i>2. Registrar Espacio, Salón o Dormitorio</h6>
    </div>
    <div class="card-body bg-white" style="border-bottom-left-radius: 15px; border-bottom-right-radius: 15px;">
        <form id="formEspacio" autocomplete="off">
            <div class="form-group">
                <label class="font-weight-bold" style="color: #521f29;">Torre / Edificio *</label>
                <select id="esp_torre" class="form-control form-control-lg" required>
                    <option value="">-- Seleccione la torre --</option>
                    <?php foreach ($torres as $t): ?>
                        <option value="<?= (int)$t['id_edificio'] ?>" data-nombre="<?= htmlspecialchars($t['nombre_torre']) ?>">
                            <?= htmlspecialchars($t['nombre_torre']) ?> (<?= (int)$t['total_pisos'] ?> piso<?= ((int)$t['total_pisos'] === 1 ? '' : 's') ?>)
                        </option>
                    <?php endforeach; ?>
                    <option value="nueva">➕ Escribir una torre nueva...</option>
                </select>
            </div>
            <div class="form-group" id="grupoTorreNueva" style="display:none;">
                <label class="font-weight-bold text-danger">Nueva Torre (nombre)</label>
                <input type="text" id="esp_torre_nueva" class="form-control" placeholder="Ej: Torre C">
            </div>
            <div class="form-group">
                <label class="font-weight-bold" style="color: #521f29;">Tipo de Espacio *</label>
                <select id="esp_tipo" class="form-control form-control-lg" required onchange="toggleDormitorio()">
                    <?php foreach ($tipos_espacio_validos as $tp): ?>
                        <option value="<?= htmlspecialchars($tp) ?>"><?= htmlspecialchars($tp) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="font-weight-bold" style="color: #521f29;">Nombre o Código del Espacio *</label>
                <input type="text" id="esp_nombre" class="form-control form-control-lg" placeholder="Ej: Aula 101 / Residencia Mujer 1" required>
            </div>
            <div class="form-row">
                <div class="form-group col-6">
                    <label class="font-weight-bold" style="color: #521f29;">Piso</label>
                    <input type="number" id="esp_piso" class="form-control" min="0" max="30" value="1">
                </div>
                <div class="form-group col-6">
                    <label class="font-weight-bold" style="color: #521f29;">Capacidad</label>
                    <input type="number" id="esp_capacidad" class="form-control" min="0" max="500" value="30">
                </div>
            </div>
            <div class="form-row" id="grupoDormitorios" style="display:none;">
                <div class="form-group col-6">
                    <label class="font-weight-bold text-danger">Cant. Dormitorios</label>
                    <input type="number" id="esp_dormitorios" class="form-control" min="0" max="100" value="0">
                </div>
                <div class="form-group col-6">
                    <label class="font-weight-bold text-danger">Camas Totales</label>
                    <input type="number" id="esp_camas" class="form-control" min="0" max="500" value="0">
                </div>
            </div>
            <button type="submit" class="btn btn-block btn-lg font-weight-bold shadow-sm" style="background-color: #ff8a00; color: #521f29;">
                <i class="fas fa-plus-circle mr-2"></i>GUARDAR ESPACIO
            </button>
        </form>
    </div>
</div>

<!-- ══════════ REPORTAR ESTADO (DISPONIBLE / MANTENIMIENTO) ══════════ -->
<div class="card shadow mb-4 border-0" style="border-radius: 15px;">
    <div class="card-header py-3" style="background-color: #521f29; border-top-left-radius: 15px; border-top-right-radius: 15px;">
        <h6 class="m-0 font-weight-bold text-white"><i class="fas fa-tools mr-2 text-warning"></i>3. Reportar Estado de un Espacio</h6>
    </div>
    <div class="card-body bg-white" style="border-bottom-left-radius: 15px; border-bottom-right-radius: 15px;">
        <form id="formEstatus" autocomplete="off">
            <div class="form-group">
                <label class="font-weight-bold" style="color: #521f29;">Espacio / Salón *</label>
                <select id="est_espacio" class="form-control form-control-lg" required>
                    <option value="">-- Seleccione el espacio --</option>
                    <?php foreach ($espacios as $e): ?>
                        <option value="<?= (int)$e['id_espacio'] ?>">
                            <?= htmlspecialchars($e['nombre_espacio']) ?> — <?= htmlspecialchars($e['torre']) ?> (<?= htmlspecialchars($e['estatus']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="font-weight-bold" style="color: #521f29;">Nuevo Estado *</label>
                <select id="est_estado" class="form-control form-control-lg" required onchange="toggleMotivo()">
                    <option value="En Mantenimiento">🛠 En Mantenimiento (inhabilitar)</option>
                    <option value="Disponible">✅ Disponible (reaperturar)</option>
                </select>
            </div>
            <div id="grupoMantenimiento">
                <div class="form-group">
                    <label class="font-weight-bold text-danger">Motivo de la Falla / Inspección *</label>
                    <input type="text" id="est_motivo" class="form-control" placeholder="Ej: Gotera en el techo del piso 2">
                </div>
                <div class="form-group">
                    <label class="font-weight-bold text-danger">Inspector(a) Responsable *</label>
                    <input type="text" id="est_inspector" class="form-control" placeholder="Nombre del inspector" required>
                </div>
            </div>
            <div id="grupoApertura" style="display:none;">
                <div class="form-group">
                    <label class="font-weight-bold text-success">Trabajo Realizado *</label>
                    <input type="text" id="est_trabajo" class="form-control" placeholder="Ej: Se reparó la gotera y se repintó">
                </div>
                <div class="form-group">
                    <label class="font-weight-bold text-success">Autoridad que Apertura *</label>
                    <input type="text" id="est_apertura" class="form-control" placeholder="Ej: Directora L. Pérez" required>
                </div>
            </div>
            <button type="submit" class="btn btn-block btn-lg font-weight-bold shadow-sm" style="background-color: #ff8a00; color: #521f29;">
                <i class="fas fa-save mr-2"></i>REPORTAR ESTADO
            </button>
        </form>
    </div>
</div>

<!-- ══════════ ACTIVIDAD RECIENTE ══════════ -->
<div class="card shadow mb-4 border-0" style="border-radius: 15px;">
    <div class="card-header py-3" style="background-color: #521f29; border-top-left-radius: 15px; border-top-right-radius: 15px;">
        <h6 class="m-0 font-weight-bold text-white"><i class="fas fa-history mr-2 text-warning"></i>Registros Sincronizados Recientemente</h6>
    </div>
    <div class="card-body bg-white" style="border-bottom-left-radius: 15px; border-bottom-right-radius: 15px;">
        <?php if (empty($recientes)): ?>
            <p class="text-muted mb-0 text-center">Aún no hay registros sincronizados desde este portal.</p>
        <?php else: ?>
            <ul class="list-group list-group-flush">
                <?php foreach ($recientes as $r): ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                        <div>
                            <span class="badge badge-dark mr-2"><?= htmlspecialchars(str_replace('_', ' ', $r['accion'])) ?></span>
                            <?= htmlspecialchars($r['resultado'] ?? '') ?>
                            <br><small class="text-muted">por <?= htmlspecialchars($r['monitor']) ?></small>
                        </div>
                        <small class="text-muted text-right"><?= date('d/m/y h:i A', strtotime($r['fecha_servidor'])) ?></small>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>

<!-- ══════════ LÓGICA OFFLINE / COLA DE SINCRONIZACIÓN ══════════ -->
<script>
(function () {
    'use strict';

    // ── Utilidades de cola (localStorage) ──────────────────────────────────
    function getCola() {
        try { return JSON.parse(localStorage.getItem('infra_pendientes') || '[]'); }
        catch (e) { return []; }
    }
    function setCola(lista) {
        localStorage.setItem('infra_pendientes', JSON.stringify(lista));
        actualizarContador();
    }
    function nuevoUUID() {
        if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            var r = Math.random() * 16 | 0, v = c === 'x' ? r : (r & 0x3 | 0x8);
            return v.toString(16);
        });
    }
    function token() {
        var m = document.querySelector('meta[name="csrf-token"]');
        return m ? m.content : '';
    }
    function actualizarContador() {
        document.getElementById('countPending').innerText = getCola().length;
    }

    function resumen(tipo, html) {
        var caja = document.getElementById('resumenSync');
        caja.innerHTML = '<div class="alert alert-' + tipo + ' font-weight-bold shadow-sm mb-0">' + html + '</div>';
        setTimeout(function () { caja.innerHTML = ''; }, 6000);
    }

    // ── Estado de red ──────────────────────────────────────────────────────
    function actualizarEstadoRed() {
        var badge = document.getElementById('netStatus');
        if (navigator.onLine) {
            badge.className = 'badge badge-success px-3 py-2 font-weight-bold shadow-sm';
            badge.innerHTML = '<i class="fas fa-wifi mr-1"></i> EN LÍNEA';
            sincronizarInfra();
        } else {
            badge.className = 'badge badge-danger px-3 py-2 font-weight-bold shadow-sm';
            badge.innerHTML = '<i class="fas fa-plane mr-1"></i> MODO OFFLINE';
        }
    }
    window.addEventListener('online', actualizarEstadoRed);
    window.addEventListener('offline', actualizarEstadoRed);

    // ── Enqueue: guardado local (funciona sin internet) ────────────────────
    function encolar(accion, datos) {
        var cola = getCola();
        cola.push({ uuid: nuevoUUID(), accion: accion, datos: datos, fecha: new Date().toISOString() });
        setCola(cola);
        resumen('info', '<i class="fas fa-save mr-2"></i>Guardado en el dispositivo. Se sincronizará ' +
                        (navigator.onLine ? 'ahora.' : 'cuando vuelva la conexión.'));
        if (navigator.onLine) sincronizarInfra();
    }

    // ── Sincronización con el servidor (idempotente por UUID) ──────────────
    var sincronizando = false;
    window.sincronizarInfra = function () {
        var cola = getCola();
        if (cola.length === 0 || sincronizando) return;
        if (!navigator.onLine) return;
        sincronizando = true;

        fetch('api_infra_sincronizar.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': token()
            },
            body: JSON.stringify({ acciones: cola })
        })
        .then(function (res) {
            if (res.status === 401 || res.status === 403) {
                throw new Error('sesion'); // CSRF inválido o sesión expirada
            }
            return res.json();
        })
        .then(function (data) {
            sincronizando = false;
            if (!data.exito) {
                resumen('warning', '<i class="fas fa-exclamation-triangle mr-2"></i>' + (data.mensaje || 'No se pudo sincronizar.'));
                return;
            }
            // Retirar de la cola todo lo que el servidor confirmó (procesado u omitido)
            var resueltos = {};
            (data.procesados_uuids || []).concat(data.omitidos_uuids || []).forEach(function (u) { resueltos[u] = true; });
            var restantes = getCola().filter(function (item) { return !resueltos[item.uuid]; });
            setCola(restantes);

            var msg = '<i class="fas fa-check-circle mr-2"></i><strong>Sincronización completada:</strong> ' +
                      data.procesados + ' procesado(s), ' + data.omitidos + ' omitido(s) por duplicado.';
            if (data.errores && data.errores.length) {
                msg += '<br><span class="text-danger">Con error (' + data.errores.length + '): ' +
                       data.errores.map(function (e) { return e.mensaje; }).join(' | ') +
                       '</span><br><small>Los registros con error fueron retirados de la cola; corríjalos y regístrelos de nuevo.</small>';
            }
            resumen(data.errores && data.errores.length ? 'warning' : 'success', msg);
        })
        .catch(function (err) {
            sincronizando = false;
            if (err && err.message === 'sesion') {
                resumen('danger', '<i class="fas fa-lock mr-2"></i>Sesión expirada o token inválido. ' +
                                  'Vuelva a iniciar sesión para sincronizar (sus registros siguen guardados en el dispositivo).');
            }
            // Otros errores = sin red: se conserva la cola para el próximo intento.
        });
    };

    // ── Formulario: Registrar Torre ────────────────────────────────────────
    document.getElementById('formTorre').addEventListener('submit', function (ev) {
        ev.preventDefault();
        var nombre = document.getElementById('torre_nombre').value.trim();
        if (!nombre) return;
        encolar('crear_torre', {
            nombre_torre: nombre,
            ubicacion_sede: document.getElementById('torre_ubicacion').value.trim() || 'Sede Principal',
            total_pisos: parseInt(document.getElementById('torre_pisos').value, 10) || 1
        });
        this.reset();
        document.getElementById('torre_ubicacion').value = 'Sede Principal';
        document.getElementById('torre_pisos').value = 1;
    });

    // ── Formulario: Registrar Espacio ──────────────────────────────────────
    var selTorre = document.getElementById('esp_torre');
    selTorre.addEventListener('change', function () {
        document.getElementById('grupoTorreNueva').style.display =
            (this.value === 'nueva') ? 'block' : 'none';
    });

    window.toggleDormitorio = function () {
        var esDorm = document.getElementById('esp_tipo').value === 'Dormitorio/Residencia';
        document.getElementById('grupoDormitorios').style.display = esDorm ? 'flex' : 'none';
    };

    document.getElementById('formEspacio').addEventListener('submit', function (ev) {
        ev.preventDefault();
        var sel = document.getElementById('esp_torre');
        var nombreEspacio = document.getElementById('esp_nombre').value.trim();
        if (!nombreEspacio || !sel.value) return;

        var datos = {
            tipo_espacio: document.getElementById('esp_tipo').value,
            nombre_espacio: nombreEspacio,
            piso_numero: parseInt(document.getElementById('esp_piso').value, 10) || 1,
            capacidad_estudiantes: parseInt(document.getElementById('esp_capacidad').value, 10) || 30,
            cantidad_dormitorios: parseInt(document.getElementById('esp_dormitorios').value, 10) || 0,
            camas_totales: parseInt(document.getElementById('esp_camas').value, 10) || 0
        };

        if (sel.value === 'nueva') {
            var tn = document.getElementById('esp_torre_nueva').value.trim();
            if (!tn) { alert('Escriba el nombre de la torre nueva.'); return; }
            datos.torre_nombre = tn; // el servidor la resuelve (o la crea) al sincronizar
        } else {
            datos.id_edificio = parseInt(sel.value, 10);
            var opt = sel.options[sel.selectedIndex];
            datos.torre_nombre = opt.getAttribute('data-nombre') || '';
        }

        encolar('crear_espacio', datos);
        this.reset();
        document.getElementById('grupoTorreNueva').style.display = 'none';
        document.getElementById('grupoDormitorios').style.display = 'none';
    });

    // ── Formulario: Reportar Estado ────────────────────────────────────────
    window.toggleMotivo = function () {
        var enMant = document.getElementById('est_estado').value === 'En Mantenimiento';
        document.getElementById('grupoMantenimiento').style.display = enMant ? 'block' : 'none';
        document.getElementById('grupoApertura').style.display   = enMant ? 'none' : 'block';
    };

    document.getElementById('formEstatus').addEventListener('submit', function (ev) {
        ev.preventDefault();
        var idEspacio = parseInt(document.getElementById('est_espacio').value, 10);
        var estado    = document.getElementById('est_estado').value;
        if (!idEspacio) return;

        var datos = { tabla: 'espacio', id: idEspacio, estatus: estado };

        if (estado === 'En Mantenimiento') {
            datos.motivo_mantenimiento  = document.getElementById('est_motivo').value.trim();
            datos.inspector_responsable = document.getElementById('est_inspector').value.trim();
            if (!datos.motivo_mantenimiento || !datos.inspector_responsable) {
                alert('Indique el motivo de la falla y el inspector responsable.');
                return;
            }
        } else {
            datos.trabajo_realizado  = document.getElementById('est_trabajo').value.trim();
            datos.usuario_apertura   = document.getElementById('est_apertura').value.trim();
            if (!datos.trabajo_realizado || !datos.usuario_apertura) {
                alert('Indique el trabajo realizado y la autoridad que apertura.');
                return;
            }
        }
        encolar('cambiar_estatus', datos);
        this.reset();
        document.getElementById('grupoMantenimiento').style.display = 'block';
        document.getElementById('grupoApertura').style.display = 'none';
    });

    // ── Arranque ───────────────────────────────────────────────────────────
    document.addEventListener('DOMContentLoaded', function () {
        actualizarContador();
        actualizarEstadoRed(); // dispara la sincronización pendiente si hay red
    });
})();
</script>
