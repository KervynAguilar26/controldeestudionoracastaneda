<?php
// /sistema_nora/modulos/control_estudio/imprimir_horario_profesor.php
session_start();
require_once '../../config/db.php';

$roles_p = $_SESSION['roles_asignados'] ?? [$_SESSION['tipo_usuario'] ?? ''];
if (empty($_SESSION['id_usuario']) || !(in_array('superadmin', $roles_p) || in_array('admin', $roles_p) || in_array('control_estudio', $roles_p) || in_array('profesor', $roles_p))) {
    http_response_code(403);
    die("Acceso restringido.");
}

$id_profesor = intval($_GET['id_profesor'] ?? $_SESSION['id_usuario']);

try {
    // Datos del profesor
    $stmt_p = $pdo->prepare("SELECT nombre, apellido, cedula FROM public.usuarios WHERE id_usuario = :id LIMIT 1");
    $stmt_p->execute([':id' => $id_profesor]);
    $profesor = $stmt_p->fetch(PDO::FETCH_ASSOC);

    if (!$profesor) {
        die("Docente no encontrado.");
    }

    // Bloques estándar de horario
    $bloques_estandar = [
        ['inicio' => '07:00:00', 'fin' => '07:45:00', 'etiqueta' => '07:00 AM - 07:45 AM'],
        ['inicio' => '08:00:00', 'fin' => '08:45:00', 'etiqueta' => '08:00 AM - 08:45 AM (Hora 1)'],
        ['inicio' => '08:45:00', 'fin' => '09:30:00', 'etiqueta' => '08:45 AM - 09:30 AM (Hora 2)'],
        ['inicio' => '09:30:00', 'fin' => '09:45:00', 'etiqueta' => '09:30 AM - 09:45 AM (Receso)'],
        ['inicio' => '09:45:00', 'fin' => '10:30:00', 'etiqueta' => '09:45 AM - 10:30 AM (Hora 3)'],
        ['inicio' => '10:30:00', 'fin' => '11:15:00', 'etiqueta' => '10:30 AM - 11:15:00 (Hora 4)'],
        ['inicio' => '11:15:00', 'fin' => '12:00:00', 'etiqueta' => '11:15 AM - 12:00 PM (Hora 5)'],
        ['inicio' => '12:00:00', 'fin' => '13:15:00', 'etiqueta' => '12:00 PM - 01:15 PM (Almuerzo)'],
        ['inicio' => '13:30:00', 'fin' => '14:15:00', 'etiqueta' => '01:30 PM - 02:15 PM (Hora 6)'],
        ['inicio' => '14:15:00', 'fin' => '15:00:00', 'etiqueta' => '02:15 PM - 03:00 PM (Hora 7)'],
        ['inicio' => '15:00:00', 'fin' => '15:15:00', 'etiqueta' => '03:00 PM - 03:15 PM (Merienda)'],
        ['inicio' => '15:15:00', 'fin' => '16:00:00', 'etiqueta' => '03:15 PM - 04:00 PM (Hora 8)'],
        ['inicio' => '18:00:00', 'fin' => '19:00:00', 'etiqueta' => '06:00 PM - 07:00 PM (Cena)'],
        ['inicio' => '19:00:00', 'fin' => '20:30:00', 'etiqueta' => '07:00 PM - 08:30 PM (Nocturno)']
    ];

    // Consultar el horario asignado al profesor con detalles de Materia, Carrera, Sección y Salón
    $stmt_h = $pdo->prepare("
        SELECT hs.dia_semana, hs.hora_inicio, mm.nombre_materia, mc.nombre_programa, co.codigo_cohorte, hs.salon_aula
        FROM public.horarios_secciones hs
        JOIN public.malla_materias mm ON hs.id_materia = mm.id_materia
        JOIN public.cohortes co ON hs.id_cohorte = co.id_cohorte
        JOIN public.mallas_curriculares mc ON co.id_malla = mc.id_malla
        WHERE hs.id_profesor = :id_prof
    ");
    $stmt_h->execute([':id_prof' => $id_profesor]);
    $horario_registros = $stmt_h->fetchAll(PDO::FETCH_ASSOC);

    $matriz_horario = [];
    foreach ($horario_registros as $hr) {
        $h_ini = date('H:i:s', strtotime($hr['hora_inicio']));
        $matriz_horario[$hr['dia_semana']][$h_ini] = [
            'materia' => $hr['nombre_materia'],
            'programa' => $hr['nombre_programa'],
            'seccion' => $hr['codigo_cohorte'],
            'salon' => $hr['salon_aula']
        ];
    }

    $dias_semana = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'];

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
        <h4 class="font-weight-bold text-uppercase" style="color: #521f29;">Horario de Act académica — Docente</h4>
        <h5 class="text-dark font-weight-bold mb-1"><?= htmlspecialchars($profesor['nombre'] . ' ' . $profesor['apellido']) ?></h5>
        <span class="badge badge-warning text-dark px-3 py-1 font-weight-bold">Cédula: V-<?= htmlspecialchars($profesor['cedula']) ?></span>
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
                        ?>
                            <td>
                                <?php if ($clase): ?>
                                    <div class="celda-clase shadow-sm rounded">
                                        <strong style="color: #521f29; font-size: 0.75rem; display: block;"><?= htmlspecialchars($clase['materia']) ?></strong>
                                        <span class="text-muted d-block" style="font-size: 0.65rem;"><?= htmlspecialchars($clase['programa']) ?></span>
                                        <span class="badge badge-secondary py-0 px-1" style="font-size: 0.6rem;">Sec: <?= htmlspecialchars($clase['seccion']) ?></span>
                                        <span class="badge badge-success py-0 px-1" style="font-size: 0.6rem;">Salón: <?= htmlspecialchars($clase['salon']) ?></span>
                                    </div>
                                <?php else: ?>
                                    <span class="text-muted" style="font-size: 0.7rem;">—</span>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
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