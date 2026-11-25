<?php
// /sistema_nora/modulos/recepcion/registrar_visita.php
require_once '../../config/db.php';
require_once '../../includes/header.php';
require_once __DIR__ . '/bitacora.php';
require_once __DIR__ . '/padron.php';

// Control de acceso: Recepción / Administración
if (!tiene_permiso(['superadmin', 'admin', 'recepcion'], $roles_array)) {
    echo "<div class='alert alert-danger font-weight-bold'>Acceso restringido a Recepción.</div>";
    require_once '../../includes/footer.php';
    exit();
}

function rv_e($valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}

function rv_cedula($valor): string
{
    return preg_replace('/\D+/', '', trim((string) $valor));
}

$mensaje = '';
$tipo_alerta = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    try {
        $cedula     = rv_cedula($_POST['cedula'] ?? '');
        $nombre     = trim((string) ($_POST['nombre_completo'] ?? ''));
        $telefono   = trim((string) ($_POST['telefono'] ?? ''));
        $entidad    = trim((string) ($_POST['entidad_origen'] ?? ''));
        $motivo     = trim((string) ($_POST['motivo'] ?? ''));
        $observaciones = trim((string) ($_POST['observaciones'] ?? ''));
        $id_area    = intval($_POST['id_area'] ?? 0);
        $id_cohorte = intval($_POST['id_cohorte'] ?? 0);
        $id_cohorte = $id_cohorte > 0 ? $id_cohorte : null;

        if ($cedula === '') {
            throw new Exception('La cédula es obligatoria y debe contener solo números.');
        }
        if ($motivo === '') {
            throw new Exception('El motivo de la visita es obligatorio.');
        }
        if ($id_area <= 0) {
            throw new Exception('Debe seleccionar un área destino.');
        }

        // Validar área activa.
        $st_area = $pdo->prepare("SELECT id_area, nombre FROM public.recepcion_areas
                                  WHERE id_area = :id AND activo = true LIMIT 1");
        $st_area->execute([':id' => $id_area]);
        $area = $st_area->fetch(PDO::FETCH_ASSOC);
        if (!$area) {
            throw new Exception('El área destino seleccionada no existe o está inactiva.');
        }

        $cohorte = null;
        if ($id_cohorte !== null) {
            $st_coh = $pdo->prepare("SELECT c.id_cohorte, c.codigo_cohorte, c.salon_aula
                                     FROM public.cohortes c
                                     WHERE c.id_cohorte = :id
                                       AND c.estatus IN ('Planificada', 'En Curso')
                                     LIMIT 1");
            $st_coh->execute([':id' => $id_cohorte]);
            $cohorte = $st_coh->fetch(PDO::FETCH_ASSOC);
            if (!$cohorte) {
                throw new Exception('La cohorte seleccionada no está disponible.');
            }
        }

        // Identidad por cédula: primero Padrón Nacional, luego usuarios del sistema.
        $identidad = recepcion_resolver_identidad($pdo, $pdo_padron ?? null, $cedula, $nombre);

        $es_usuario_sistema = $identidad['es_usuario_sistema'];
        $id_usuario         = $identidad['id_usuario'];
        $nombre             = $identidad['nombre_completo'];
        $fuente_datos       = $identidad['fuente_datos'];
        $fecha_nacimiento   = $identidad['padron']['fecha_nacimiento'] ?? null;
        $genero_padron      = $identidad['padron']['genero'] ?? null;

        if ($telefono === '') {
            $telefono = $identidad['telefono'];
        }
        if ($entidad === '' && $es_usuario_sistema) {
            $entidad = $identidad['entidad_origen'];
        }
        if ($nombre === '') {
            throw new Exception('No fue posible identificar la cédula. Escriba el nombre completo del visitante.');
        }

        $pdo->beginTransaction();
        try {
            $sql_visitante = "INSERT INTO public.recepcion_visitantes
                                  (es_usuario_sistema, id_usuario, cedula, nombre_completo,
                                   telefono, entidad_origen, fuente_datos,
                                   fecha_nacimiento, genero, creado_en)
                               VALUES (:es_sis, :u, :ced, :nom, :tel, :ori, :fuente,
                                       :fec_nac, :gen, CURRENT_TIMESTAMP)
                               ON CONFLICT (cedula) DO UPDATE SET
                                   es_usuario_sistema = EXCLUDED.es_usuario_sistema,
                                   id_usuario         = EXCLUDED.id_usuario,
                                   nombre_completo    = EXCLUDED.nombre_completo,
                                   telefono           = COALESCE(EXCLUDED.telefono, recepcion_visitantes.telefono),
                                   entidad_origen     = COALESCE(EXCLUDED.entidad_origen, recepcion_visitantes.entidad_origen),
                                   fuente_datos       = EXCLUDED.fuente_datos,
                                   fecha_nacimiento   = COALESCE(EXCLUDED.fecha_nacimiento, recepcion_visitantes.fecha_nacimiento),
                                   genero             = COALESCE(EXCLUDED.genero, recepcion_visitantes.genero)
                               RETURNING id_visitante";

            $st_v = $pdo->prepare($sql_visitante);
            $st_v->execute([
                ':es_sis'   => $es_usuario_sistema ? 'true' : 'false',
                ':u'        => $id_usuario,
                ':ced'      => $cedula,
                ':nom'      => $nombre,
                ':tel'      => $telefono !== '' ? $telefono : null,
                ':ori'      => $entidad !== '' ? $entidad : null,
                ':fuente'   => $fuente_datos,
                ':fec_nac'  => $fecha_nacimiento,
                ':gen'      => $genero_padron,
            ]);
            $id_visitante = (int) $st_v->fetchColumn();

            $st_visita = $pdo->prepare("INSERT INTO public.recepcion_visitas
                                           (id_visitante, id_area_destino, id_cohorte, motivo,
                                            fecha_entrada, hora_entrada, estatus,
                                            id_usuario_registro, observaciones, fecha_registro)
                                        VALUES (:v, :a, :c, :mot,
                                                CURRENT_DATE, CURRENT_TIME, 'En Curso',
                                                :reg, :obs, CURRENT_TIMESTAMP)
                                        RETURNING id_visita, fecha_entrada, hora_entrada");
            $st_visita->execute([
                ':v'   => $id_visitante,
                ':a'   => $id_area,
                ':c'   => $cohorte ? (int) $cohorte['id_cohorte'] : null,
                ':mot' => $motivo,
                ':reg' => $_SESSION['id_usuario'],
                ':obs' => $observaciones !== '' ? $observaciones : null,
            ]);
            $visita = $st_visita->fetch(PDO::FETCH_ASSOC);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }

        // Auditoría fuera de la transacción: un fallo aquí no afecta la visita registrada.
        recepcion_bitacora($pdo, 'VISITA REGISTRAR: V-' . $cedula . ' -> ' . $area['nombre']
            . ' | fuente:' . $fuente_datos . ' | motivo:' . substr($motivo, 0, 120));

        $etiqueta_fuente = $fuente_datos === 'PADRON_NACIONAL'
            ? '<span class="badge badge-success ml-1">Padrón Nacional</span>'
            : ($fuente_datos === 'SISTEMA'
                ? '<span class="badge badge-info ml-1">Sistema</span>'
                : '<span class="badge badge-secondary ml-1">Manual</span>');
        $mensaje = 'Visita registrada para <strong>' . rv_e($nombre) . '</strong> (V-' . rv_e($cedula)
            . ')' . $etiqueta_fuente . ' hacia <strong>' . rv_e($area['nombre']) . '</strong>. Entrada: '
            . rv_e(substr((string) $visita['hora_entrada'], 0, 5)) . '.';
        $tipo_alerta = 'success';

        // Limpiar los campos de texto tras un registro correcto.
        $_POST['cedula'] = $_POST['nombre_completo'] = $_POST['telefono'] = '';
        $_POST['entidad_origen'] = $_POST['motivo'] = $_POST['observaciones'] = '';
    } catch (Throwable $e) {
        $mensaje = 'No se pudo registrar la visita: ' . rv_e($e->getMessage());
        $tipo_alerta = 'danger';
    }
}

// Catálogos para el formulario.
$areas = [];
$cohortes = [];
try {
    $areas = $pdo->query("SELECT id_area, nombre FROM public.recepcion_areas
                          WHERE activo = true ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);
    $cohortes = $pdo->query("SELECT c.id_cohorte, c.codigo_cohorte, c.salon_aula, m.nombre_programa
                             FROM public.cohortes c
                             LEFT JOIN public.mallas_curriculares m ON m.id_malla = c.id_malla
                             WHERE c.estatus IN ('Planificada', 'En Curso')
                             ORDER BY c.codigo_cohorte ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $mensaje .= ($mensaje !== '' ? '<br>' : '') . 'Advertencia: no se pudieron cargar los catálogos.';
    if ($tipo_alerta === '') { $tipo_alerta = 'warning'; }
}
?>

<div class="container py-3">
    <div class="d-sm-flex align-items-center justify-content-between mb-4">
        <h1 class="h3 mb-0 font-weight-bold" style="color: #521f29;">
            <i class="fas fa-clipboard-check mr-2 text-warning"></i>Registrar Visita
        </h1>
        <a href="index.php" class="btn btn-sm btn-secondary font-weight-bold shadow-sm">
            <i class="fas fa-arrow-left mr-1"></i> Volver al panel
        </a>
    </div>

    <?php if ($mensaje !== ''): ?>
        <div class="alert alert-<?= rv_e($tipo_alerta) ?> font-weight-bold shadow-sm">
            <?= $mensaje ?>
        </div>
    <?php endif; ?>

    <div class="card shadow border-0 mx-auto" style="max-width: 960px; border-radius: 15px;">
        <div class="card-header py-3 text-white" style="background-color: #521f29; border-top-left-radius: 15px; border-top-right-radius: 15px;">
            <h6 class="m-0 font-weight-bold"><i class="fas fa-user-plus mr-2 text-warning"></i>Datos de la visita</h6>
        </div>
        <div class="card-body bg-white p-4" style="border-bottom-left-radius: 15px; border-bottom-right-radius: 15px;">
            <form method="POST" action="registrar_visita.php">
                <?= csrf_field() ?>

                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label class="font-weight-bold" style="color: #521f29;">Cédula *</label>
                        <input type="text" name="cedula" id="cedula_visitante" class="form-control font-weight-bold" required
                               inputmode="numeric" autocomplete="off" maxlength="20"
                               placeholder="Ej: 12345678"
                               value="<?= rv_e($_POST['cedula'] ?? '') ?>"
                               style="border: 2px solid #521f29;">
                        <small class="form-text text-muted">Solo números. Al escribirla se buscará automáticamente en el Padrón Nacional.</small>
                        <div id="padron_feedback" class="mt-1 small"></div>
                    </div>
                    <div class="form-group col-md-6">
                        <label class="font-weight-bold" style="color: #521f29;">Nombre completo *</label>
                        <input type="text" name="nombre_completo" id="nombre_visitante" class="form-control font-weight-bold"
                               maxlength="180" placeholder="Nombre y apellido del visitante"
                               value="<?= rv_e($_POST['nombre_completo'] ?? '') ?>">
                        <small class="form-text text-muted">Obligatorio solo para visitantes foráneos.</small>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label class="font-weight-bold" style="color: #521f29;">Teléfono</label>
                        <input type="text" name="telefono" class="form-control" maxlength="40"
                               value="<?= rv_e($_POST['telefono'] ?? '') ?>" placeholder="Opcional">
                    </div>
                    <div class="form-group col-md-6">
                        <label class="font-weight-bold" style="color: #521f29;">Entidad / Origen</label>
                        <input type="text" name="entidad_origen" class="form-control" maxlength="150"
                               value="<?= rv_e($_POST['entidad_origen'] ?? '') ?>" placeholder="Empresa, familiar, institución...">
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group col-md-6">
                        <label class="font-weight-bold" style="color: #521f29;">Área destino *</label>
                        <select name="id_area" class="form-control font-weight-bold" required style="border: 2px solid #521f29;">
                            <option value="">-- Seleccione un área --</option>
                            <?php foreach ($areas as $a): ?>
                                <option value="<?= (int) $a['id_area'] ?>"
                                    <?= (intval($_POST['id_area'] ?? 0) === (int) $a['id_area']) ? 'selected' : '' ?>>
                                    <?= rv_e($a['nombre']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group col-md-6">
                        <label class="font-weight-bold" style="color: #521f29;">Cohorte (opcional)</label>
                        <select name="id_cohorte" class="form-control">
                            <option value="">-- Sin cohorte --</option>
                            <?php foreach ($cohortes as $c): ?>
                                <option value="<?= (int) $c['id_cohorte'] ?>"
                                    <?= (intval($_POST['id_cohorte'] ?? 0) === (int) $c['id_cohorte']) ? 'selected' : '' ?>>
                                    <?= rv_e($c['codigo_cohorte']) ?>
                                    <?= !empty($c['nombre_programa']) ? ' - ' . rv_e($c['nombre_programa']) : '' ?>
                                    <?= !empty($c['salon_aula']) ? ' (' . rv_e($c['salon_aula']) . ')' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label class="font-weight-bold" style="color: #521f29;">Motivo de la visita *</label>
                    <input type="text" name="motivo" class="form-control" required maxlength="200"
                           placeholder="Ej: Entrega de documentos, entrevista, acceso a clase..."
                           value="<?= rv_e($_POST['motivo'] ?? '') ?>">
                </div>

                <div class="form-group">
                    <label class="font-weight-bold" style="color: #521f29;">Observaciones</label>
                    <textarea name="observaciones" class="form-control" rows="3" maxlength="1000"
                              placeholder="Datos adicionales (opcional)"><?= rv_e($_POST['observaciones'] ?? '') ?></textarea>
                </div>

                <div class="text-right mt-4">
                    <a href="index.php" class="btn btn-secondary font-weight-bold shadow-sm mr-2">
                        <i class="fas fa-times mr-1"></i> Cancelar
                    </a>
                    <button type="submit" class="btn font-weight-bold shadow-sm" style="background-color: #ff8a00; color: #521f29;">
                        <i class="fas fa-save mr-1"></i> Registrar entrada
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';

    function iniciar() {
        var cedula = document.getElementById('cedula_visitante');
        var nombre = document.getElementById('nombre_visitante');
        var fb = document.getElementById('padron_feedback');
        if (!cedula || !nombre || !fb) { return; }

        var temporizador = null;
        var cedula_anterior = cedula.value.replace(/\D/g, '');
        var token = (document.querySelector('input[name="_csrf"]') || {}).value || '';

        function escapeHtml(s) {
            return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
        }

        function mostrar(mensaje, clase) {
            fb.innerHTML = '<span class="badge badge-' + clase + ' px-2 py-1">' + mensaje + '</span>';
        }

        function buscar() {
            var doc = cedula.value.replace(/\D/g, '');
            if (doc.length < 6) {
                fb.innerHTML = '';
                return;
            }
            if (doc === cedula_anterior && nombre.value.trim() !== '') { return; }
            cedula_anterior = doc;

            mostrar('<i class="fas fa-spinner fa-spin mr-1"></i> Consultando Padrón Nacional...', 'info');

            fetch('api_buscar_padron.php?cedula=' + encodeURIComponent(doc), {
                headers: { 'X-CSRF-Token': token }
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.encontrado) {
                    nombre.value = data.nombre_completo;
                    nombre.setAttribute('data-origen', 'padron');
                    var detalle = data.fecha_nacimiento ? ' · Nac.: ' + escapeHtml(data.fecha_nacimiento) : '';
                    if (data.genero) { detalle += ' · ' + escapeHtml(data.genero); }
                    mostrar('<i class="fas fa-check-circle mr-1"></i> Identidad del Padrón Nacional: '
                        + escapeHtml(data.nombre_completo) + detalle, 'success');
                } else {
                    nombre.setAttribute('data-origen', 'manual');
                    mostrar('<i class="fas fa-exclamation-triangle mr-1"></i> No está en el Padrón. '
                        + escapeHtml(data.mensaje || 'Escriba el nombre manualmente.'), 'warning');
                }
            })
            .catch(function () {
                mostrar('<i class="fas fa-exclamation-triangle mr-1"></i> No se pudo consultar el Padrón Nacional.', 'warning');
            });
        }

        cedula.addEventListener('input', function () {
            cedula.value = cedula.value.replace(/\D/g, '');
            if (temporizador) { window.clearTimeout(temporizador); }
            temporizador = window.setTimeout(buscar, 600);
        });
        cedula.addEventListener('blur', buscar);
        nombre.addEventListener('input', function () {
            nombre.setAttribute('data-origen', 'manual');
        });

        if (cedula.value.replace(/\D/g, '').length >= 6) { buscar(); }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', iniciar);
    } else {
        iniciar();
    }
})();
</script>

<?php require_once '../../includes/footer.php'; ?>
