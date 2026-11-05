<?php
// /sistema_nora/modulos/control_estudio/misiones_instalaciones.php
require_once '../../config/db.php';
require_once '../../includes/header.php';

// Control de acceso: misiones exclusivas de Control de Estudio / Administración
if (!tiene_permiso(['control_estudio', 'admin', 'superadmin'], $roles_array)) {
    echo "<div class='alert alert-danger font-weight-bold'>Acceso restringido a Control de Estudio.</div>";
    require_once '../../includes/footer.php';
    exit();
}

$error = '';

// 1. REGISTRAR PRÉSTAMO DE INSTALACIONES
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_prestamo'])) {
    try {
        $f_nac_f =$_POST['fecha_nacimiento_facilitador'] ?? null;
        $edad_f = 0;

        if (!empty($f_nac_f)) {
            $fecha_nac = new DateTimeImmutable($f_nac_f);
            $hoy = new DateTimeImmutable();$edad_f = $hoy->diff($fecha_nac)->y;
        }

        // Construir el nombre de la Misión o Institución Aliada
        $mision_seleccionada =$_POST['nombre_mision'];
        if ($mision_seleccionada === 'Otra Institución Aliada') {
            $inst_nombre = trim($_POST['nombre_institucion_aliada'] ?? '');
            $carrera_nom = trim($_POST['carrera_aliada'] ?? '');
            $mision_final = "Otra: " . $inst_nombre . ($carrera_nom ? " ({$carrera_nom})" : '');
        } else {
            $mision_final =$mision_seleccionada;
        }

        // Obtener salones seleccionados del Check List
        $salones_sel =$_POST['salones_seleccionados'] ?? [];
        $salon_asignado_final = !empty($salones_sel) ? implode(', ', $salones_sel) : trim($_POST['salon_asignado'] ?? '');

        if (empty($salon_asignado_final)) {
            throw new Exception("Debe seleccionar o especificar al menos un salón/aula asignada.");
        }

        $sql = "INSERT INTO public.prestamo_misiones 
                (nombre_mision, facilitador_responsable, cedula_facilitador, telefono_facilitador, cantidad_estudiantes, periodo_academico, salon_asignado, dias_horario, fecha_inicio, fecha_fin, observaciones, fecha_nacimiento_facilitador, edad_facilitador, estado_civil_facilitador) 
                VALUES (:mision, :fac, :ced, :tel, :cant, :periodo, :salon, :horario, :f_ini, :f_fin, :obs, :fnac_f, :edad_f, :est_f)";

        $stmt =$pdo->prepare($sql);$stmt->execute([
            ':mision'   => $mision_final,
            ':fac'      => trim($_POST['facilitador_responsable']),
            ':ced'      => trim($_POST['cedula_facilitador']),
            ':tel'      => trim($_POST['telefono_facilitador']),
            ':cant'     => intval($_POST['cantidad_estudiantes'] ?? 0), ':periodo'  => trim($_POST['periodo_academico']),
            ':salon'    => $salon_asignado_final,
            ':horario'  => trim($_POST['dias_horario'] ?? 'Según Horario Seleccionado'),
            ':f_ini'    => $_POST['fecha_inicio'],
            ':f_fin'    => $_POST['fecha_fin'],
            ':obs'      => trim($_POST['observaciones'] ?? ''),
            ':fnac_f'   => !empty($f_nac_f) ?$f_nac_f : null,
            ':edad_f'   => $edad_f,
            ':est_f'    => $_POST['estado_civil_facilitador'] ?? 'Soltero(a)'
        ]);

        echo "<script>alert('¡Espacio y Facilitador asignados exitosamente!'); window.location.href='misiones_instalaciones.php';</script>";
        exit();

    } catch (Exception $e) {$error = "Error al guardar el préstamo: " . $e->getMessage();
    }
}

// 2. CONSULTAR PRÉSTAMOS ACTIVOS
$sql_pr = "SELECT pm.*, 
                  (SELECT COUNT(*) FROM public.misiones_estudiantes me WHERE me.id_prestamo = pm.id_prestamo) AS total_censados
           FROM public.prestamo_misiones pm 
           ORDER BY pm.id_prestamo DESC";
$prestamos = $pdo->query($sql_pr)->fetchAll();
?>

<!-- ENCABEZADO DE SECCIÓN -->
<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <h1 class="h3 mb-0 font-weight-bold" style="color: #521f29;">
        <i class="fas fa-school mr-2 text-warning"></i>Préstamo de Instalaciones a Misiones Educativas
    </h1>
    <div>
        <button class="btn btn-sm font-weight-bold text-white shadow-sm mr-1" style="background-color: #521f29;" data-toggle="modal" data-target="#modalNuevoPrestamo">
            <i class="fas fa-plus-circle mr-1 text-warning"></i> Asignar Espacio a Misión
        </button>
        <a href="index.php" class="btn btn-sm btn-secondary font-weight-bold shadow-sm">
            <i class="fas fa-arrow-left mr-1"></i> Volver
        </a>
    </div>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger font-weight-bold shadow-sm mb-4"><i class="fas fa-exclamation-triangle mr-2"></i><?= $error ?></div>
<?php endif; ?>

<!-- TABLA DE CONTROL DE ESPACIOS PRESTADOS -->
<div class="card shadow mb-4" style="border-radius: 15px; border: none;">
    <div class="card-header py-3" style="background-color: #521f29; border-top-left-radius: 15px; border-top-right-radius: 15px;">
        <h6 class="m-0 font-weight-bold text-white"><i class="fas fa-list-alt mr-2"></i>Aulas, Horarios y Facilitadores Activos</h6>
    </div>
    <div class="card-body bg-white p-4">
        <div class="table-responsive">
            <table class="table table-hover align-middle" width="100%">
                <thead style="background-color: #ff8a00; color: #521f29;">
                    <tr>
                        <th>Misión / Institución</th>
                        <th>Facilitador Responsable</th>
                        <th>Período</th>
                        <th>Aula Asignada</th>
                        <th>Días y Horario</th>
                        <th class="text-center">Censo / Matrícula</th>
                        <th>Fechas Vigencia</th>
                        <th class="text-center">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($prestamos)): ?>
                        <tr><td colspan="8" class="text-center text-muted py-4 font-weight-bold">No hay préstamos de instalaciones registrados actualmente.</td></tr>
                    <?php else: ?>
                        <?php foreach ($prestamos as$pr): ?>
                            <tr>
                                <td><span class="badge badge-dark px-2 py-1"><?= htmlspecialchars($pr['nombre_mision']) ?></span></td>
                                <td class="font-weight-bold">
                                    <strong style="color: #521f29;"><?= htmlspecialchars($pr['facilitador_responsable']) ?></strong><br>
                                    <small class="text-muted">
                                        C.I: V-<?= htmlspecialchars($pr['cedula_facilitador']) ?> | 
                                        <?= !empty($pr['edad_facilitador']) ? "Edad: {$pr['edad_facilitador']} Años | " : '' ?>
                                        Tlf: <?= htmlspecialchars($pr['telefono_facilitador']) ?>
                                    </small>
                                </td>
                                <td><span class="badge badge-warning text-dark font-weight-bold"><?= htmlspecialchars($pr['periodo_academico']) ?></span></td>
                                <td class="font-weight-bold text-primary"><i class="fas fa-door-open mr-1"></i><?= htmlspecialchars($pr['salon_asignado']) ?></td>
                                <td><small class="font-weight-bold"><?= htmlspecialchars($pr['dias_horario']) ?></small></td>
                                <td class="text-center">
                                    <span class="badge badge-success px-3 py-2 font-weight-bold" style="font-size: 10pt;">
                                        <?= $pr['total_censados'] ?> Estudiantes
                                    </span>
                                </td>
                                <td><small class="font-weight-bold"><?= date('d/m/Y', strtotime($pr['fecha_inicio'])) ?> al <?= date('d/m/Y', strtotime($pr['fecha_fin'])) ?></small></td>
                                <td class="text-center">
                                    <a href="misiones_estudiantes.php?id_prestamo=<?= $pr['id_prestamo'] ?>" class="btn btn-sm btn-info font-weight-bold mb-1" title="Censar Estudiantes">
                                        <i class="fas fa-users mr-1"></i> Censo
                                    </a>
                                    <a href="imprimir_lista_mision.php?id_prestamo=<?= $pr['id_prestamo'] ?>" target="_blank" class="btn btn-sm btn-danger font-weight-bold mb-1" title="Imprimir PDF">
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

<!-- MODAL REGISTRAR PRÉSTAMO CON CAMPOS DINÁMICOS PARA OTRAS INSTITUCIONES -->
<div class="modal fade" id="modalNuevoPrestamo" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
        <div class="modal-content" style="border-radius: 15px;">
            <div class="modal-header text-white" style="background-color: #521f29; border-top-left-radius: 15px; border-top-right-radius: 15px;">
                <h5 class="modal-title font-weight-bold"><i class="fas fa-handshake mr-2 text-warning"></i>Asignar Espacio y Facilitador</h5>
                <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
            </div>
            <form method="POST" action="misiones_instalaciones.php">
            <?= csrf_field() ?>
                <div class="modal-body bg-light">
                    
                    <div class="form-row">
                        <div class="col-md-6 mb-2">
                            <label class="font-weight-bold" style="color: #521f29;">Misión / Institución *</label>
                            <select name="nombre_mision" id="select_nombre_mision" class="form-control font-weight-bold" onchange="toggleCamposAliada(this.value)" required>
                                <option value="Misión Robinson">Misión Robinson</option>
                                <option value="Misión Ribas">Misión Ribas</option>
                                <option value="Misión Sucre">Misión Sucre</option>
                                <option value="Otra Institución Aliada">Otra Institución Aliada</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-2">
                            <label class="font-weight-bold" style="color: #521f29;">Período Académico *</label>
                            <input type="text" name="periodo_academico" class="form-control font-weight-bold" placeholder="Ej: Trayecto I - 2026" required>
                        </div>
                    </div>

                    <!-- BLOQUE DINÁMICO PARA OTRA INSTITUCIÓN Y CARRERA -->
                    <div class="form-row d-none p-3 mb-3 rounded border bg-white" id="bloque_aliada_dinamico" style="border-left: 5px solid #ff8a00 !important;">
                        <div class="col-md-6 mb-2">
                            <label class="font-weight-bold small text-danger">Nombre de la Institución Aliada *</label>
                            <input type="text" name="nombre_institucion_aliada" id="input_inst_aliada" class="form-control font-weight-bold" placeholder="Ej: Universidad Bolivariana de Venezuela">
                        </div>
                        <div class="col-md-6 mb-2">
                            <label class="font-weight-bold small text-danger">Carrera / Programa Curricular *</label>
                            <input type="text" name="carrera_aliada" id="input_carrera_aliada" class="form-control font-weight-bold" placeholder="Ej: Licenciatura en Educación Matutino">
                        </div>
                    </div>
                    
                    <!-- BLOQUE DATOS DEL FACILITADOR -->
                    <div class="p-3 mb-3 rounded border bg-white">
                        <small class="font-weight-bold text-uppercase d-block mb-2" style="color: #521f29;"><i class="fas fa-chalkboard-teacher mr-1 text-warning"></i> Datos del Facilitador Responsable:</small>
                        <div class="form-row">
                            <div class="col-md-4 mb-2">
                                <label class="font-weight-bold small" style="color: #521f29;">Cédula *</label>
                                <div class="input-group">
                                    <input type="text" name="cedula_facilitador" id="cedula_fac_input" class="form-control font-weight-bold" placeholder="Ej: 12345678" onkeypress="if(event.key === 'Enter'){ event.preventDefault(); consultarPadronFacilitador(); }" required>
                                    <div class="input-group-append">
                                        <button type="button" onclick="consultarPadronFacilitador()" class="btn btn-warning font-weight-bold" title="Buscar en Padrón">
                                            <i class="fas fa-search" id="icon_fac"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-5 mb-2">
                                <label class="font-weight-bold small" style="color: #521f29;">Nombres y Apellidos *</label>
                                <input type="text" name="facilitador_responsable" id="nombre_fac_input" class="form-control font-weight-bold" required>
                            </div>
                            <div class="col-md-3 mb-2">
                                <label class="font-weight-bold small" style="color: #521f29;">Teléfono *</label>
                                <input type="text" name="telefono_facilitador" class="form-control font-weight-bold" required>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="col-md-6 mb-2">
                                <label class="font-weight-bold small" style="color: #521f29;">Fecha de Nacimiento</label>
                                <input type="date" name="fecha_nacimiento_facilitador" class="form-control font-weight-bold">
                            </div>
                            <div class="col-md-6 mb-2">
                                <label class="font-weight-bold small" style="color: #521f29;">Estado Civil</label>
                                <select name="estado_civil_facilitador" class="form-control font-weight-bold">
                                    <option value="Soltero(a)">Soltero(a)</option>
                                    <option value="Casado(a)">Casado(a)</option>
                                    <option value="Divorciado(a)">Divorciado(a)</option>
                                    <option value="Viudo(a)">Viudo(a)</option>
                                    <option value="Concubinato">Concubinato</option>
                                </select>
                            </div>
                        </div>
                    </div>

                    <!-- DATOS DE VIGENCIA DE USO -->
                    <div class="form-row">
                        <div class="col-md-4 mb-2">
                            <label class="font-weight-bold" style="color: #521f29;">Fecha Inicio *</label>
                            <input type="date" name="fecha_inicio" id="fFechaInicio" class="form-control font-weight-bold" value="<?= date('Y-m-d') ?>" onchange="actualizarChecklistSalones()" required>
                        </div>
                        <div class="col-md-4 mb-2">
                            <label class="font-weight-bold" style="color: #521f29;">Fecha Culminación *</label>
                            <input type="date" name="fecha_fin" id="fFechaFin" class="form-control font-weight-bold" value="<?= date('Y-m-d', strtotime('+6 months')) ?>" onchange="actualizarChecklistSalones()" required>
                        </div>
                        <div class="col-md-4 mb-2">
                            <label class="font-weight-bold" style="color: #521f29;">N° Estudiantes Estimado</label>
                            <input type="number" name="cantidad_estudiantes" class="form-control font-weight-bold text-center" value="20" min="1">
                        </div>
                    </div>

                    <!-- CHECK LIST DE DISPONIBILIDAD EN TIEMPO REAL -->
                    <div class="card border-0 shadow-sm my-3" style="border-radius: 12px; background-color: #fcf8f2;">
                        <div class="card-header font-weight-bold text-white" style="background-color: #521f29; border-top-left-radius: 12px; border-top-right-radius: 12px;">
                            <i class="fas fa-tasks mr-2 text-warning"></i> Check List de Salones Disponibles según Días y Horarios
                        </div>
                        <div class="card-body p-3">
                            <div class="form-row align-items-center mb-3">
                                <div class="col-md-6 mb-2">
                                    <label class="font-weight-bold small text-dark">1. Días Frecuentes *</label>
                                    <div class="d-flex flex-wrap justify-content-between bg-white p-2 border rounded">
                                        <div class="custom-control custom-checkbox"><input type="checkbox" class="custom-control-input chk-dia-filtro" id="fSab" value="Sábado" checked onchange="actualizarChecklistSalones()"><label class="custom-control-label small font-weight-bold" for="fSab">Sáb</label></div>
                                        <div class="custom-control custom-checkbox"><input type="checkbox" class="custom-control-input chk-dia-filtro" id="fDom" value="Domingo" checked onchange="actualizarChecklistSalones()"><label class="custom-control-label small font-weight-bold" for="fDom">Dom</label></div>
                                        <div class="custom-control custom-checkbox"><input type="checkbox" class="custom-control-input chk-dia-filtro" id="fLun" value="Lunes" onchange="actualizarChecklistSalones()"><label class="custom-control-label small font-weight-bold" for="fLun">Lun</label></div>
                                        <div class="custom-control custom-checkbox"><input type="checkbox" class="custom-control-input chk-dia-filtro" id="fMar" value="Martes" onchange="actualizarChecklistSalones()"><label class="custom-control-label small font-weight-bold" for="fMar">Mar</label></div>
                                        <div class="custom-control custom-checkbox"><input type="checkbox" class="custom-control-input chk-dia-filtro" id="fMie" value="Miércoles" onchange="actualizarChecklistSalones()"><label class="custom-control-label small font-weight-bold" for="fMie">Mié</label></div>
                                        <div class="custom-control custom-checkbox"><input type="checkbox" class="custom-control-input chk-dia-filtro" id="fJue" value="Jueves" onchange="actualizarChecklistSalones()"><label class="custom-control-label small font-weight-bold" for="fJue">Jue</label></div>
                                        <div class="custom-control custom-checkbox"><input type="checkbox" class="custom-control-input chk-dia-filtro" id="fVie" value="Viernes" onchange="actualizarChecklistSalones()"><label class="custom-control-label small font-weight-bold" for="fVie">Vie</label></div>
                                    </div>
                                </div>
                                <div class="col-md-3 mb-2">
                                    <label class="font-weight-bold small text-dark">2. Hora Inicio</label>
                                    <input type="time" id="fHoraInicio" class="form-control font-weight-bold text-center" value="08:00" onchange="actualizarChecklistSalones()">
                                </div>
                                <div class="col-md-3 mb-2">
                                    <label class="font-weight-bold small text-dark">3. Hora Fin</label>
                                    <input type="time" id="fHoraFin" class="form-control font-weight-bold text-center" value="12:00" onchange="actualizarChecklistSalones()">
                                </div>
                            </div>

                            <label class="font-weight-bold small text-danger"><i class="fas fa-check-square mr-1"></i> Seleccione el/los Salón(es) Habilitado(s):</label>
                            <div class="row" id="contenedorChecklistSalones">
                                <div class="col-12 text-center text-muted font-weight-bold py-3">
                                    <i class="fas fa-spinner fa-spin mr-2"></i> Verificando disponibilidad de salones...
                                </div>
                            </div>
                        </div>
                    </div>

                    <input type="hidden" name="salon_asignado" id="salon_asignado_input">
                    <input type="hidden" name="dias_horario" id="dias_horario_input">

                    <div class="form-group mb-0">
                        <label class="font-weight-bold" style="color: #521f29;">Observaciones</label>
                        <textarea name="observaciones" class="form-control" rows="2" placeholder="Observaciones o acuerdos del préstamo del aula..."></textarea>
                    </div>
                </div>
                <div class="modal-footer bg-white" style="border-bottom-left-radius: 15px; border-bottom-right-radius: 15px;">
                    <button type="button" class="btn btn-secondary font-weight-bold" data-dismiss="modal">Cancelar</button>
                    <button type="submit" name="guardar_prestamo" class="btn font-weight-bold text-white" style="background-color: #ff8a00;">
                        <i class="fas fa-save mr-1"></i> GUARDAR Y ASIGNAR ESPACIO
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- SCRIPTS JAVASCRIPT DE CONTROL Y VERIFICACIÓN AJAX -->
<script>
function toggleCamposAliada(valor) {
    const bloque = document.getElementById('bloque_aliada_dinamico');
    const inputInst = document.getElementById('input_inst_aliada');
    const inputCarrera = document.getElementById('input_carrera_aliada');

    if (valor === 'Otra Institución Aliada') {
        bloque.classList.remove('d-none');
        inputInst.required = true;
        inputCarrera.required = true;
    } else {
        bloque.classList.add('d-none');
        inputInst.required = false;
        inputCarrera.required = false;
        inputInst.value = '';
        inputCarrera.value = '';
    }
}

function consultarPadronFacilitador() {
    const cedulaInput = document.getElementById('cedula_fac_input');
    const icon = document.getElementById('icon_fac');
    const cedula = cedulaInput.value.trim();

    if (!cedula) return;

    icon.className = "fas fa-spinner fa-spin";

    fetch('api_buscar_padron.php?cedula=' + cedula)
        .then(res => res.json())
        .then(data => {
            icon.className = "fas fa-search";
            if (data.encontrado) {
                const nombreCompleto = (data.first_name + ' ' + (data.second_name || '') + ' ' + data.first_surname + ' ' + (data.second_surname || '')).trim();
                document.getElementById('nombre_fac_input').value = nombreCompleto;
            } else {
                alert('Cédula no encontrada en el Padrón Nacional. Ingrese los nombres manualmente.');
            }
        })
        .catch(err => {
            icon.className = "fas fa-search";
            console.error('Error al consultar padrón:', err);
        });
}

function actualizarChecklistSalones() {
    const dias = [];
    document.querySelectorAll('.chk-dia-filtro:checked').forEach(c => dias.push(c.value));

    const hIni = document.getElementById('fHoraInicio').value;
    const hFin = document.getElementById('fHoraFin').value;
    const fIni = document.getElementById('fFechaInicio').value;
    const fFin = document.getElementById('fFechaFin').value;

    document.getElementById('dias_horario_input').value = dias.join(',') + " " + hIni + " a " + hFin;

    const formData = new FormData();
    dias.forEach(d => formData.append('dias[]', d));
    formData.append('hora_inicio', hIni);
    formData.append('hora_fin', hFin);
    formData.append('fecha_inicio', fIni);
    formData.append('fecha_fin', fFin);
    formData.append('_csrf', (document.querySelector('meta[name="csrf-token"]') || {}).content || '');

    fetch('api_consultar_disponibilidad.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        const contenedor = document.getElementById('contenedorChecklistSalones');
        contenedor.innerHTML = '';

        if (data.success && data.salones.length > 0) {
            data.salones.forEach(sal => {
                const col = document.createElement('div');
                col.className = 'col-md-6 mb-2';

                if (sal.disponible === false) {
                    col.innerHTML = `
                        <div class="p-2 border rounded bg-white opacity-50 d-flex justify-content-between align-items-center">
                            <div>
                                <span class="font-weight-bold text-muted"><i class="fas fa-lock text-danger mr-1"></i> ${sal.nombre}</span>
                                <small class="d-block text-danger font-weight-bold">${sal.motivo}</small>
                            </div>
                            <span class="badge badge-secondary">${sal.capacidad} Cap.</span>
                        </div>
                    `;
                } else {
                    col.innerHTML = `
                        <div class="p-2 border rounded bg-white shadow-sm d-flex justify-content-between align-items-center" style="border-left: 5px solid #28a745 !important;">
                            <div class="custom-control custom-checkbox">
                                <input type="checkbox" name="salones_seleccionados[]" class="custom-control-input chk-salon-disponible" id="sal_${sal.id_espacio}" value="${sal.nombre}" onchange="sincronizarSalonesSeleccionados()">
                                <label class="custom-control-label font-weight-bold text-dark" for="sal_${sal.id_espacio}">
                                    ${sal.nombre}
                                </label>
                            </div>
                            <span class="badge badge-success font-weight-bold">${sal.capacidad} Cupos</span>
                        </div>
                    `;
                }
                contenedor.appendChild(col);
            });
        } else {
            contenedor.innerHTML = '<div class="col-12 text-center text-danger font-weight-bold">No se encontraron salones registrados o disponibles.</div>';
        }
    })
    .catch(err => {
        console.error('Error al consultar disponibilidad:', err);
    });
}

function sincronizarSalonesSeleccionados() {
    const seleccionados = [];
    document.querySelectorAll('.chk-salon-disponible:checked').forEach(chk => {
        seleccionados.push(chk.value);
    });
    document.getElementById('salon_asignado_input').value = seleccionados.join(', ');
}

document.addEventListener('DOMContentLoaded', function() {
    $('#modalNuevoPrestamo').on('shown.bs.modal', function () {
        actualizarChecklistSalones();
    });
});
</script>

<?php require_once '../../includes/footer.php'; ?>