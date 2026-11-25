<?php
// /sistema_nora/modulos/control_estudio/imprimir_horario_profesor.php
session_start();
require_once '../../config/db.php';
require_once '../../includes/disponibilidad_docente.php';

$roles_p = $_SESSION['roles_asignados'] ?? [$_SESSION['tipo_usuario'] ?? ''];
$es_gestor = in_array('superadmin', $roles_p) || in_array('admin', $roles_p) || in_array('control_estudio', $roles_p);
if (empty($_SESSION['id_usuario']) || !($es_gestor || in_array('profesor', $roles_p))) {
    http_response_code(403);
    die("Acceso restringido.");
}

// El docente siempre ve su propio horario; Control de Estudio puede consultar cualquiera.
$id_profesor = $es_gestor
    ? intval($_GET['id_profesor'] ?? $_SESSION['id_usuario'])
    : intval($_SESSION['id_usuario']);

try {
    // Datos del profesor
    $stmt_p = $pdo->prepare("SELECT nombre, apellido, cedula, horas_semanales_min, horas_semanales_max, disponibilidad_horaria FROM public.usuarios WHERE id_usuario = :id LIMIT 1");
    $stmt_p->execute([':id' => $id_profesor]);
    $profesor = $stmt_p->fetch(PDO::FETCH_ASSOC);

    if (!$profesor) {
        die("Docente no encontrado.");
    }

    // Catálogo de bloques compartido con la carga de disponibilidad.
    $bloques_estandar = docente_bloques();

    // Horario ya cargado, con materia, sección y espacio/aula.
    $horario = docente_horario_detalle($pdo, $id_profesor);
    $horas_cargadas = $horario['horas'];
    $matriz_horario = $horario['matriz'];
    $detalle_clases = $horario['registros'];
    $disponibilidad_prof = docente_disponibilidad_leer($profesor['disponibilidad_horaria'] ?? null);

    $dias_semana = docente_dias();

} catch (Exception $e) {
    die("Error al generar el horario del profesor: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Horario del Docente - <?= htmlspecialchars($profesor['nombre'] . ' ' . $profesor['apellido']) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        body { background-color: #f8f9fa; font-family: Arial, sans-serif; }
        .sheet {
            width: 1000px;
            margin: 20px auto;
            background: #ffffff;
            padding: 30px;
            border: 4px solid #521f29;
            box-shadow: 0 0 15px rgba(0,0,0,0.1);
        }
        .table-horario th { background-color: #ff8a00 !important; color: #fff; text-align: center; font-size: 0.85rem; }
        .table-horario td { text-align: center; font-size: 0.75rem; vertical-align: middle !important; height: 50px; }
        .celda-clase { background-color: #fff8f0; border-left: 3px solid #ff8a00; padding: 4px; }
        .celda-no-disponible { background-color: #f1f1f1; color: #999 !important; }
        .celda-descanso { background-color: #fafafa; color: #aaa !important; }
        .badge-espacio { background-color: #521f29; color: #fff; }
        @media print {
            .no-imprimir { display: none !important; }
            body { background: #fff; }
            .sheet { border: none; box-shadow: none; margin: 0; width: 100%; padding: 10px; }
            @page { size: landscape; margin: 10mm; }
        }
    </style>
</head>
<body>

<div class="container text-center mt-3 no-imprimir">
    <button onclick="window.print()" class="btn font-weight-bold text-white shadow" style="background-color: #ff8a00;">
        <i class="fas fa-print mr-2"></i> IMPRIMIR / DESCARGAR HORARIO (PDF)
    </button>
    <a href="lista_profesores.php" class="btn btn-secondary font-weight-bold ml-2">Volver</a>
</div>

<div class="sheet">
    <!-- Encabezado Institucional -->
    <div class="d-flex justify-content-between align-items-center mb-3 border-bottom pb-3">
        <img src="../../assets/img/minmujer_logo.png" style="height: 45px;" onerror="this.style.display='none'">
        <div class="text-center">
            <h6 class="m-0 font-weight-bold" style="color: #521f29;">REPÚBLICA BOLIVARIANA DE VENEZUELA</h6>
            <small class="font-weight-bold text-muted">MINISTERIO DEL PODER POPULAR PARA LA MUJER Y LA IGUALDAD DE GÉNERO</small>
            <div class="font-weight-bold" style="color: #ff8a00; font-size: 0.9rem;">Escuela Internacional de Liderazgo de la Mujer "Nora Castañeda"</div>
        </div>
        <img src="../../assets/img/LogoEscuelaNora_NUEVO.PNG" style="height: 50px;" onerror="this.style.display='none'">
    </div>

    <div class="text-center mb-3">
        <h4 class="font-weight-bold text-uppercase" style="color: #521f29;">Mi Horario Académico — Docente</h4>
        <h5 class="text-dark font-weight-bold mb-1"><?= htmlspecialchars($profesor['nombre'] . ' ' . $profesor['apellido']) ?></h5>
        <span class="badge badge-warning text-dark px-3 py-1 font-weight-bold">Cédula: V-<?= htmlspecialchars($profesor['cedula']) ?></span>
        <div class="mt-2 small">
            <span class="badge badge-success px-3 py-1 font-weight-bold">Carga académica: <?= $horas_cargadas ?> / <?= intval($profesor['horas_semanales_max']) ?> h semanales</span>
            <span class="badge badge-light px-3 py-1 font-weight-bold">Jornada: <?= intval($profesor['horas_semanales_min']) ?> - <?= intval($profesor['horas_semanales_max']) ?> h</span>
            <span class="badge badge-info px-3 py-1 font-weight-bold">
                Disponibilidad: <?= docente_tiene_restriccion($disponibilidad_prof) ? htmlspecialchars(implode(', ', array_keys($disponibilidad_prof))) : 'Todos los bloques' ?>
            </span>
        </div>
    </div>

    <!-- Leyenda del horario -->
    <div class="small mb-2 text-center">
        <span class="badge badge-warning text-dark px-2 py-1">Clase programada (con espacio)</span>
        <span class="badge badge-light px-2 py-1">Disponible</span>
        <span class="badge badge-secondary px-2 py-1">No disponible</span>
        <span class="badge badge-light px-2 py-1">Descanso</span>
    </div>

    <!-- Tabla de Horarios -->
    <div class="table-responsive">
        <table class="table table-bordered table-horario">
            <thead>
                <tr>
                    <th style="width: 160px;">Bloque Horario</th>
                    <?php foreach ($dias_semana as $dia): ?>
                        <th><?= $dia ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($bloques_estandar as $bloque): ?>
                    <tr>
                        <td class="font-weight-bold text-dark bg-light" style="font-size: 0.75rem;">
                            <?= htmlspecialchars($bloque['etiqueta']) ?>
                        </td>
                        <?php foreach ($dias_semana as $dia):
                            $clase = $matriz_horario[$dia][$bloque['inicio']] ?? null;
                            $fuera_disponibilidad = docente_tiene_restriccion($disponibilidad_prof)
                                && !docente_esta_disponible($disponibilidad_prof, $dia, $bloque['inicio']);
                        ?>
                            <td class="<?= $fuera_disponibilidad && !$clase ? 'celda-no-disponible' : '' ?> <?= !$bloque['clase'] && !$clase ? 'celda-descanso' : '' ?>">
                                <?php if ($clase): ?>
                                    <div class="celda-clase shadow-sm rounded">
                                        <strong style="color: #521f29; font-size: 0.75rem; display: block;"><?= htmlspecialchars($clase['materia']) ?></strong>
                                        <span class="text-muted d-block" style="font-size: 0.65rem;"><?= htmlspecialchars($clase['programa']) ?></span>
                                        <span class="badge badge-secondary py-0 px-1" style="font-size: 0.6rem;">Sec: <?= htmlspecialchars($clase['seccion']) ?></span>
                                        <span class="badge badge-espacio py-0 px-1" style="font-size: 0.6rem;">ESPACIO: <?= htmlspecialchars($clase['salon'] !== '' ? $clase['salon'] : 'Por asignar') ?></span>
                                    </div>
                                <?php elseif (!$bloque['clase']): ?>
                                    <span class="text-muted" style="font-size: 0.65rem;">Descanso</span>
                                <?php elseif ($fuera_disponibilidad): ?>
                                    <span class="text-muted" style="font-size: 0.65rem;">No disponible</span>
                                <?php else: ?>
                                    <span class="text-muted" style="font-size: 0.65rem;">Disponible</span>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Detalle de clases y espacios -->
    <div class="mt-4">
        <h6 class="font-weight-bold mb-2" style="color: #521f29;">
            <i class="fas fa-door-open mr-2 text-warning"></i>Detalle de clases y espacios donde imparte
        </h6>
        <table class="table table-sm table-bordered mb-0">
            <thead>
                <tr>
                    <th>Día</th>
                    <th>Hora</th>
                    <th>Materia</th>
                    <th>Sección</th>
                    <th>Espacio / Aula</th>
                    <th>Horas</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($detalle_clases)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-3">Aún no hay clases cargadas en el horario de este docente.</td></tr>
                <?php else: ?>
                    <?php foreach ($detalle_clases as $clase): ?>
                        <tr>
                            <td class="font-weight-bold"><?= htmlspecialchars($clase['dia']) ?></td>
                            <td><?= docente_hora_formato($clase['inicio']) ?> - <?= docente_hora_formato($clase['fin']) ?></td>
                            <td><?= htmlspecialchars($clase['materia']) ?></td>
                            <td><?= htmlspecialchars($clase['seccion'] !== '' ? $clase['seccion'] : '—') ?></td>
                            <td class="font-weight-bold" style="color: #521f29;"><?= htmlspecialchars($clase['salon'] !== '' ? $clase['salon'] : 'Por asignar') ?></td>
                            <td class="text-center"><?= intval($clase['horas']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Firmas de Control de Estudio -->
    <div class="row mt-5 pt-4 text-center">
        <div class="col-6">
            <div style="border-top: 1px solid #521f29; width: 60%; margin: 0 auto;">
                <strong style="color: #521f29; font-size: 0.8rem;">Control de Estudio y Evaluación</strong><br>
                <small class="text-muted">Firma y Sello</small>
            </div>
        </div>
        <div class="col-6">
            <div style="border-top: 1px solid #521f29; width: 60%; margin: 0 auto;">
                <strong style="color: #521f29; font-size: 0.8rem;">Firma del Docente</strong><br>
                <small class="text-muted">Conforme</small>
            </div>
        </div>
    </div>
</div>

</body>
</html>