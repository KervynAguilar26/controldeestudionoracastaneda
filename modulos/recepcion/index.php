<?php
// /sistema_nora/modulos/recepcion/index.php
require_once '../../config/db.php';
require_once '../../includes/header.php';
require_once '../../includes/buscador_lista.php';

// Control de acceso: Recepción / Administración
if (!tiene_permiso(['superadmin', 'admin', 'recepcion'], $roles_array)) {
    echo "<div class='alert alert-danger font-weight-bold'>Acceso restringido a Recepción.</div>";
    require_once '../../includes/footer.php';
    exit();
}

function rc_e($valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}

// ══════════ Indicadores del día ══════════
$kpi = ['en_curso' => 0, 'finalizadas' => 0, 'alertas' => 0, 'estudiantes' => 0];
$visitas_en_curso = [];
$alertas_pendientes = [];

try {
    $kpi['en_curso'] = (int) $pdo->query("SELECT COUNT(*) FROM public.recepcion_visitas
                                           WHERE estatus = 'En Curso' AND fecha_entrada = CURRENT_DATE")->fetchColumn();
    $kpi['finalizadas'] = (int) $pdo->query("SELECT COUNT(*) FROM public.recepcion_visitas
                                              WHERE estatus = 'Finalizada' AND fecha_salida = CURRENT_DATE")->fetchColumn();
    $kpi['alertas'] = (int) $pdo->query("SELECT COUNT(*) FROM public.recepcion_alertas_qr
                                          WHERE atendida = false")->fetchColumn();
    // Se cuentan las llegadas registradas por Recepción, no los marcajes artificiales de asistencia_clases.
    $kpi['estudiantes'] = (int) $pdo->query("SELECT COUNT(*) FROM public.recepcion_visitas v
                                              JOIN public.recepcion_areas a ON a.id_area = v.id_area_destino
                                              WHERE v.fecha_entrada = CURRENT_DATE
                                                AND a.nombre = 'Aula / Cohorte'")->fetchColumn();

    $sql_visitas = "SELECT v.id_visita, v.motivo,
                           TO_CHAR(v.hora_entrada, 'HH24:MI') AS hora_entrada,
                           rv.cedula, rv.nombre_completo, rv.fuente_datos,
                           a.nombre AS area_destino
                    FROM public.recepcion_visitas v
                    JOIN public.recepcion_visitantes rv ON rv.id_visitante = v.id_visitante
                    LEFT JOIN public.recepcion_areas a ON a.id_area = v.id_area_destino
                    WHERE v.estatus = 'En Curso' AND v.fecha_entrada = CURRENT_DATE
                    ORDER BY v.hora_entrada DESC, v.id_visita DESC
                    LIMIT 100";
    $visitas_en_curso = $pdo->query($sql_visitas)->fetchAll(PDO::FETCH_ASSOC);

    $sql_alertas = "SELECT al.id_alerta, al.cedula, al.tipo_alerta, al.area_destino,
                           TO_CHAR(al.hora_lectura, 'HH24:MI') AS hora_lectura,
                           COALESCE(NULLIF(al.nombre_completo, ''),
                                    TRIM(CONCAT_WS(' ', u.nombre, u.apellido))) AS persona
                    FROM public.recepcion_alertas_qr al
                    LEFT JOIN public.usuarios u
                           ON regexp_replace(u.cedula, '[^0-9]', '', 'g') = al.cedula
                    WHERE al.atendida = false
                    ORDER BY al.fecha_lectura DESC, al.hora_lectura DESC, al.id_alerta DESC
                    LIMIT 100";
    $alertas_pendientes = $pdo->query($sql_alertas)->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('RECEPCION panel: ' . $e->getMessage());
    echo "<div class='alert alert-warning font-weight-bold shadow-sm'>No fue posible cargar algunos datos del panel. "
       . "Consulte al administrador del sistema.</div>";
}
?>

<div class="container-fluid py-3">
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 font-weight-bold" style="color: #521f29;">
            <i class="fas fa-door-open mr-2 text-warning"></i>Recepción — Panel del Día
        </h1>
        <div>
            <a href="registrar_visita.php" class="btn btn-sm text-white font-weight-bold shadow-sm mr-1" style="background-color: #521f29;">
                <i class="fas fa-user-plus mr-1"></i> Registrar visita
            </a>
            <a href="registrar_estudiante_pernocta.php" class="btn btn-sm text-white font-weight-bold shadow-sm mr-1" style="background-color: #6c343e;">
                <i class="fas fa-user-check mr-1"></i> Pre-registro de participantes
            </a>
            <button type="button" id="btnAbrirEscaner" class="btn btn-sm font-weight-bold shadow-sm" style="background-color: #ff8a00; color: #521f29;">
                <i class="fas fa-qrcode mr-1"></i> Escanear QR
            </button>
        </div>
    </div>

    <!-- Token CSRF reutilizable para todas las peticiones fetch del módulo -->
    <input type="hidden" id="csrf_recepcion" name="_csrf" value="<?= rc_e(csrf_token()) ?>">

    <div id="feedback_panel"></div>

    <!-- ══════════ KPIs ══════════ -->
    <div class="row mb-4">
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card shadow border-left-nora h-100">
                <div class="card-body">
                    <div class="font-weight-bold text-uppercase mb-1" style="font-size: 10pt; color: #521f29;">Visitas en curso hoy</div>
                    <div class="h2 mb-0 font-weight-bold" id="kpiEnCurso" style="color: #521f29;"><?= (int) $kpi['en_curso'] ?></div>
                    <i class="fas fa-clock fa-2x text-warning float-right" style="opacity: .4;"></i>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card shadow h-100" style="border-left: .25rem solid #ff8a00 !important;">
                <div class="card-body">
                    <div class="font-weight-bold text-uppercase mb-1" style="font-size: 10pt; color: #521f29;">Visitas finalizadas hoy</div>
                    <div class="h2 mb-0 font-weight-bold" id="kpiFinalizadas" style="color: #521f29;"><?= (int) $kpi['finalizadas'] ?></div>
                    <i class="fas fa-check-circle fa-2x text-success float-right" style="opacity: .4;"></i>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card shadow h-100" style="border-left: .25rem solid #dc3545 !important;">
                <div class="card-body">
                    <div class="font-weight-bold text-uppercase mb-1" style="font-size: 10pt; color: #521f29;">Alertas QR pendientes</div>
                    <div class="h2 mb-0 font-weight-bold" id="kpiAlertas" style="color: #521f29;"><?= (int) $kpi['alertas'] ?></div>
                    <i class="fas fa-exclamation-triangle fa-2x text-danger float-right" style="opacity: .4;"></i>
                </div>
            </div>
        </div>
        <div class="col-xl-3 col-md-6 mb-3">
            <div class="card shadow h-100" style="border-left: .25rem solid #521f29 !important;">
                <div class="card-body">
                    <div class="font-weight-bold text-uppercase mb-1" style="font-size: 10pt; color: #521f29;">Estudiantes llegados hoy</div>
                    <div class="h2 mb-0 font-weight-bold" id="kpiEstudiantes" style="color: #521f29;"><?= (int) $kpi['estudiantes'] ?></div>
                    <i class="fas fa-user-graduate fa-2x text-warning float-right" style="opacity: .4;"></i>
                </div>
            </div>
        </div>
    </div>

    <?php buscador_lista_render('Buscar visita o alerta por nombre o cédula…', '.card-body table'); ?>

    <!-- ══════════ Visitas en curso ══════════ -->
    <div class="card shadow mb-4 border-0" style="border-radius: 15px;">
        <div class="card-header py-3 text-white" style="background-color: #521f29; border-top-left-radius: 15px; border-top-right-radius: 15px;">
            <h6 class="m-0 font-weight-bold"><i class="fas fa-users mr-2 text-warning"></i>Visitas en curso</h6>
        </div>
        <div class="card-body bg-white p-0">
            <table class="table table-hover mb-0">
                <thead style="background-color: #f8f1e7;">
                    <tr>
                        <th>Visitante</th>
                        <th>Cédula</th>
                        <th>Área destino</th>
                        <th>Motivo</th>
                        <th>Hora entrada</th>
                        <th class="text-center">Acción</th>
                    </tr>
                </thead>
                <tbody id="tbodyVisitasCurso">
                    <?php if (empty($visitas_en_curso)): ?>
                        <tr><td colspan="6" class="text-center text-muted py-4 font-weight-bold">No hay visitas en curso registradas hoy.</td></tr>
                    <?php else: ?>
                        <?php foreach ($visitas_en_curso as $v): ?>
                            <tr data-id-visita="<?= (int) $v['id_visita'] ?>">
                                <td class="font-weight-bold">
                                    <?= rc_e($v['nombre_completo']) ?>
                                    <?php if (($v['fuente_datos'] ?? '') === 'PADRON_NACIONAL'): ?>
                                        <span class="badge badge-success ml-1" title="Identidad verificada en el Padrón Nacional">Padrón</span>
                                    <?php endif; ?>
                                </td>
                                <td>V-<?= rc_e($v['cedula']) ?></td>
                                <td><span class="badge badge-warning text-dark px-2 py-1"><?= rc_e($v['area_destino'] ?: '—') ?></span></td>
                                <td><?= rc_e($v['motivo']) ?></td>
                                <td class="font-weight-bold"><?= rc_e(substr((string) $v['hora_entrada'], 0, 5)) ?></td>
                                <td class="text-center">
                                    <button type="button" class="btn btn-sm btn-success font-weight-bold shadow-sm btn-registrar-salida"
                                            data-id="<?= (int) $v['id_visita'] ?>"
                                            data-nombre="<?= rc_e($v['nombre_completo']) ?>">
                                        <i class="fas fa-sign-out-alt mr-1"></i> Registrar salida
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ══════════ Alertas QR pendientes ══════════ -->
    <div class="card shadow mb-4 border-0" style="border-radius: 15px;">
        <div class="card-header py-3 text-white" style="background-color: #521f29; border-top-left-radius: 15px; border-top-right-radius: 15px;">
            <h6 class="m-0 font-weight-bold"><i class="fas fa-exclamation-circle mr-2 text-warning"></i>Alertas QR pendientes</h6>
        </div>
        <div class="card-body bg-white p-0">
            <table class="table table-hover mb-0">
                <thead style="background-color: #f8f1e7;">
                    <tr>
                        <th>Cédula</th>
                        <th>Persona</th>
                        <th>Tipo de alerta</th>
                        <th>Área recomendada</th>
                        <th>Lectura</th>
                        <th class="text-center">Acción</th>
                    </tr>
                </thead>
                <tbody id="tbodyAlertas">
                    <?php if (empty($alertas_pendientes)): ?>
                        <tr><td colspan="6" class="text-center text-muted py-4 font-weight-bold">No hay alertas pendientes.</td></tr>
                    <?php else: ?>
                        <?php foreach ($alertas_pendientes as $al): ?>
                            <tr data-id-alerta="<?= (int) $al['id_alerta'] ?>">
                                <td class="font-weight-bold">V-<?= rc_e($al['cedula']) ?></td>
                                <td><?= rc_e($al['persona'] !== '' && $al['persona'] !== null ? $al['persona'] : 'No registrado') ?></td>
                                <td>
                                    <span class="badge badge-danger px-2 py-1"><?= rc_e($al['tipo_alerta']) ?></span>
                                </td>
                                <td><span class="badge badge-warning text-dark px-2 py-1"><?= rc_e($al['area_destino']) ?></span></td>
                                <td><?= rc_e(substr((string) $al['hora_lectura'], 0, 5)) ?></td>
                                <td class="text-center">
                                    <button type="button" class="btn btn-sm font-weight-bold shadow-sm btn-atender-alerta"
                                            style="background-color: #ff8a00; color: #521f29;"
                                            data-id="<?= (int) $al['id_alerta'] ?>"
                                            data-cedula="<?= rc_e($al['cedula']) ?>">
                                        <i class="fas fa-check mr-1"></i> Atender
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modal: atención de alerta QR -->
<div class="modal fade" id="modalAtenderAlerta" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content" style="border-radius: 15px;">
            <div class="modal-header text-white" style="background-color: #521f29; border-top-left-radius: 15px; border-top-right-radius: 15px;">
                <h5 class="modal-title font-weight-bold"><i class="fas fa-clipboard-check mr-2 text-warning"></i>Atender alerta QR</h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
            </div>
            <div class="modal-body bg-light">
                <input type="hidden" id="atender_id_alerta" value="">
                <p class="font-weight-bold mb-3">Cédula: <span id="atender_cedula" class="text-nora">—</span></p>
                <label class="font-weight-bold" style="color: #521f29;">Observaciones de la atención</label>
                <textarea id="atender_observaciones" class="form-control" rows="3"
                          placeholder="Ej: Se orientó al estudiante a Control de Estudio para regularizar su inscripción."></textarea>
                <div id="feedback_atender" class="mt-3"></div>
            </div>
            <div class="modal-footer bg-white">
                <button type="button" class="btn btn-secondary font-weight-bold" data-dismiss="modal">Cancelar</button>
                <button type="button" id="btnConfirmarAtencion" class="btn font-weight-bold shadow-sm" style="background-color: #ff8a00; color: #521f29;">
                    <i class="fas fa-check-circle mr-1"></i> Marcar como atendida
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal: escáner QR del carnet (contiene SOLO la cédula) -->
<div class="modal fade" id="modalEscanerQR" tabindex="-1" role="dialog" aria-hidden="true" data-backdrop="static">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content" style="border-radius: 15px;">
            <div class="modal-header text-white" style="background-color: #521f29; border-top-left-radius: 15px; border-top-right-radius: 15px;">
                <h5 class="modal-title font-weight-bold"><i class="fas fa-qrcode mr-2 text-warning"></i>Escáner QR de Recepción</h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
            </div>
            <div class="modal-body text-center bg-light">
                <p class="small text-muted font-weight-bold mb-2">Mantenga el QR del carnet frente a la cámara. El sistema validará la inscripción y registrará el acceso automáticamente.</p>
                <div id="lector_qr_recepcion" class="shadow border bg-white" style="width: 100%; max-width: 380px; margin: 0 auto; border-radius: 15px; overflow: hidden;"></div>
                <div id="feedback_qr_recepcion" class="mt-3"></div>
            </div>
            <div class="modal-footer justify-content-between bg-white">
                <span id="estado_camara_qr" class="badge badge-secondary px-3 py-2"><i class="fas fa-circle mr-1"></i> CÁMARA EN ESPERA</span>
                <button type="button" class="btn btn-secondary font-weight-bold" data-dismiss="modal">Cerrar escáner</button>
            </div>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>

<!-- Librería QR local + capa común móvil (sin dependencia de CDN) -->
<script src="../../assets/js/html5-qrcode.min.js"></script>
<script src="../../assets/js/nora_qr.js?v=4"></script>
<script>
(function () {
    'use strict';

    function tokenCSRF() {
        var input = document.getElementById('csrf_recepcion');
        if (input && input.value) { return input.value; }
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.content : '';
    }

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    function postJSON(url, payload) {
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': tokenCSRF() },
            body: JSON.stringify(payload)
        }).then(function (res) {
            return res.json().catch(function () {
                return { status: 'error', message: 'Respuesta inválida del servidor.' };
            }).then(function (data) {
                if (!res.ok) { throw new Error(data.message || ('Error HTTP ' + res.status)); }
                return data;
            });
        });
    }

    function ajustarKpi(id, delta) {
        var el = document.getElementById(id);
        if (!el) { return; }
        var n = parseInt(el.textContent || '0', 10);
        if (isNaN(n)) { n = 0; }
        el.textContent = String(Math.max(0, n + delta));
    }

    function asegurarFilaVacia(tbodyId, colspan, texto) {
        var tbody = document.getElementById(tbodyId);
        if (tbody && tbody.children.length === 0) {
            var tr = document.createElement('tr');
            tr.innerHTML = '<td colspan="' + colspan + '" class="text-center text-muted py-4 font-weight-bold">' + esc(texto) + '</td>';
            tbody.appendChild(tr);
        }
    }

    function mostrarPanel(mensaje, clase) {
        var fb = document.getElementById('feedback_panel');
        if (!fb) { return; }
        fb.innerHTML = '<div class="alert alert-' + clase + ' font-weight-bold shadow-sm">' + mensaje + '</div>';
    }

    // ── Registrar salida ──────────────────────────────────────────────
    document.querySelectorAll('.btn-registrar-salida').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = this.getAttribute('data-id');
            var nombre = this.getAttribute('data-nombre');
            var self = this;
            if (!window.confirm('¿Registrar la salida de ' + (nombre || 'esta visita') + '?')) { return; }

            btn.disabled = true;
            postJSON('api_registrar_salida.php', { id_visita: id })
                .then(function (data) {
                    if (data.status !== 'success') { throw new Error(data.message || 'No se pudo registrar la salida.'); }
                    var fila = self.closest('tr');
                    if (fila) { fila.remove(); }
                    asegurarFilaVacia('tbodyVisitasCurso', 6, 'No hay visitas en curso registradas hoy.');
                    ajustarKpi('kpiEnCurso', -1);
                    ajustarKpi('kpiFinalizadas', 1);
                    mostrarPanel('<i class="fas fa-check-circle mr-2"></i>Salida registrada correctamente.', 'success');
                })
                .catch(function (err) {
                    btn.disabled = false;
                    mostrarPanel('<i class="fas fa-exclamation-triangle mr-2"></i>' + esc(err.message), 'danger');
                });
        });
    });

    // ── Atender alerta QR ─────────────────────────────────────────────
    var idAlertaActual = 0;
    document.querySelectorAll('.btn-atender-alerta').forEach(function (btn) {
        btn.addEventListener('click', function () {
            idAlertaActual = parseInt(this.getAttribute('data-id'), 10) || 0;
            document.getElementById('atender_id_alerta').value = String(idAlertaActual);
            document.getElementById('atender_cedula').textContent = 'V-' + (this.getAttribute('data-cedula') || '');
            document.getElementById('atender_observaciones').value = '';
            document.getElementById('feedback_atender').innerHTML = '';
            if (window.jQuery) { jQuery('#modalAtenderAlerta').modal('show'); }
        });
    });

    var btnConfirmar = document.getElementById('btnConfirmarAtencion');
    if (btnConfirmar) {
        btnConfirmar.addEventListener('click', function () {
            if (idAlertaActual <= 0) { return; }
            var obs = document.getElementById('atender_observaciones').value.trim();
            var fb = document.getElementById('feedback_atender');
            btnConfirmar.disabled = true;
            fb.innerHTML = '<div class="alert alert-info font-weight-bold mb-0"><i class="fas fa-spinner fa-spin mr-2"></i>Registrando...</div>';

            postJSON('api_atender_alerta.php', { id_alerta: idAlertaActual, observaciones: obs })
                .then(function (data) {
                    if (data.status !== 'success') { throw new Error(data.message || 'No se pudo atender la alerta.'); }
                    var fila = document.querySelector('tr[data-id-alerta="' + idAlertaActual + '"]');
                    if (fila) { fila.remove(); }
                    asegurarFilaVacia('tbodyAlertas', 6, 'No hay alertas pendientes.');
                    ajustarKpi('kpiAlertas', -1);
                    if (window.jQuery) { jQuery('#modalAtenderAlerta').modal('hide'); }
                    mostrarPanel('<i class="fas fa-check-circle mr-2"></i>Alerta marcada como atendida.', 'success');
                })
                .catch(function (err) {
                    btnConfirmar.disabled = false;
                    fb.innerHTML = '<div class="alert alert-danger font-weight-bold mb-0">' + esc(err.message) + '</div>';
                });
        });
    }

    // ── Escáner QR (cámara trasera en móvil + alternativa por foto) ─────
    var qrScanner = null;
    var procesandoQR = false;
    var ultimoQR = '';
    var huboCambioQR = false;

    function alDetectarQR(textoCodificado) {
        if (procesandoQR || textoCodificado === ultimoQR) { return; }
        procesandoQR = true;
        ultimoQR = textoCodificado;

        var cedula = String(textoCodificado).replace(/\D/g, '');
        var fb = document.getElementById('feedback_qr_recepcion');

        if (cedula === '') {
            fb.innerHTML = '<div class="alert alert-danger font-weight-bold mb-0">QR inválido: no se encontró una cédula.</div>';
            window.setTimeout(function () { procesandoQR = false; ultimoQR = ''; }, 2500);
            return;
        }

        fb.innerHTML = '<div class="alert alert-info font-weight-bold mb-0"><i class="fas fa-spinner fa-spin mr-2"></i>Validando V-' + esc(cedula) + '...</div>';

        postJSON('api_registrar_qr.php', { cedula: cedula })
            .then(function (data) {
                if (data.status === 'ok' || data.status === 'success') {
                    huboCambioQR = true;
                    fb.innerHTML = '<div class="alert alert-success font-weight-bold mb-0" style="font-size: 12pt;">'
                        + '<i class="fas fa-check-circle mr-2"></i>ACCESO REGISTRADO<br>'
                        + '<span class="text-dark">' + esc(data.nombre_completo || data.nombre || '') + '</span><br>'
                        + '<small class="text-muted">V-' + esc(data.cedula) + ' | Cohorte: ' + esc(data.cohorte || '—')
                        + ' | Salón: ' + esc(data.salon || '—') + '</small></div>';
                } else if (data.status === 'duplicado') {
                    fb.innerHTML = '<div class="alert alert-warning font-weight-bold mb-0">'
                        + '<i class="fas fa-exclamation-triangle mr-2"></i>' + esc(data.message) + '</div>';
                } else if (data.status === 'alerta') {
                    huboCambioQR = true;
                    fb.innerHTML = '<div class="alert alert-warning font-weight-bold mb-0">'
                        + '<i class="fas fa-user-times mr-2"></i>' + esc(data.mensaje)
                        + '<br><small>Área recomendada: ' + esc(data.area_destino) + '</small></div>';
                } else {
                    fb.innerHTML = '<div class="alert alert-danger font-weight-bold mb-0">'
                        + '<i class="fas fa-times-circle mr-2"></i>' + esc(data.message || 'No se pudo procesar el QR.') + '</div>';
                }
            })
            .catch(function (err) {
                fb.innerHTML = '<div class="alert alert-danger font-weight-bold mb-0">'
                    + '<i class="fas fa-times-circle mr-2"></i>' + esc(err.message) + '</div>';
            });

        // Pausa de 2,5 segundos para evitar lecturas repetidas del mismo carnet.
        window.setTimeout(function () { procesandoQR = false; ultimoQR = ''; }, 2500);
    }

    function configurarModalQR() {
        if (!window.jQuery) { return; }
        jQuery('#btnAbrirEscaner').on('click', function () {
            jQuery('#modalEscanerQR').modal('show');
        });
        jQuery('#modalEscanerQR').on('shown.bs.modal', function () {
            procesandoQR = false;
            ultimoQR = '';
            huboCambioQR = false;
            document.getElementById('feedback_qr_recepcion').innerHTML = '';

            if (!qrScanner) {
                qrScanner = NoraQR.create({
                    elementId: 'lector_qr_recepcion',
                    onDetect: alDetectarQR,
                    onState: function (estado, mensaje) {
                        var badge = document.getElementById('estado_camara_qr');
                        if (!badge) { return; }
                        var mapa = {
                            starting: ['warning', '<i class="fas fa-spinner fa-spin mr-1"></i> ACTIVANDO...'],
                            started:  ['success', '<i class="fas fa-circle mr-1"></i> CÁMARA ACTIVA'],
                            stopped:  ['secondary', '<i class="fas fa-circle mr-1"></i> CÁMARA DETENIDA'],
                            file:     ['success', '<i class="fas fa-image mr-1"></i> QR POR FOTO'],
                            error:    ['danger', '<i class="fas fa-exclamation-triangle mr-1"></i> SIN CÁMARA']
                        };
                        var conf = mapa[estado] || mapa.stopped;
                        badge.className = 'badge badge-' + conf[0] + ' px-3 py-2';
                        badge.innerHTML = conf[1];
                    }
                });
            }
            qrScanner.start();
        });

        jQuery('#modalEscanerQR').on('hidden.bs.modal', function () {
            if (qrScanner) { qrScanner.stop(); }
            procesandoQR = false;
            ultimoQR = '';
            if (huboCambioQR) { window.location.reload(); }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', configurarModalQR);
    } else {
        configurarModalQR();
    }
})();
</script>
