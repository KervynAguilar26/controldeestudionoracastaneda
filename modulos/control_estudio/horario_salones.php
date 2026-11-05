<?php
// /sistema_nora/modulos/control_estudio/horario_salones.php
require_once '../../config/db.php';

// Verificación de sesión segura
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['id_usuario'])) {
    header('Location: ../../index.php');
    exit();
}

$id_cohorte = intval($_GET['id_cohorte'] ?? 0);
$salon_solicitado = trim($_GET['salon'] ?? '');

// 1. Buscar los datos de la sección
$cohorte = null;
if ($id_cohorte > 0) {
    $stmt = $pdo->prepare("SELECT c.*, m.nombre_programa, m.tipo_oferta, m.tipo_regimen
                             FROM public.cohortes c
                             JOIN public.mallas_curriculares m ON m.id_malla = c.id_malla
                            WHERE c.id_cohorte = :id LIMIT 1");
    $stmt->execute([':id' => $id_cohorte]);
    $cohorte = $stmt->fetch(PDO::FETCH_ASSOC);
} else {
    $stmt = $pdo->prepare("SELECT c.*, m.nombre_programa, m.tipo_oferta, m.tipo_regimen
                             FROM public.cohortes c
                             JOIN public.mallas_curriculares m ON m.id_malla = c.id_malla
                            WHERE c.salon_aula LIKE :salon
                              AND c.estatus IN ('Planificada', 'En Curso')
                            ORDER BY c.id_cohorte DESC LIMIT 1");
    $stmt->execute([':salon' => '%' . $salon_solicitado . '%']);
    $cohorte = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$cohorte) {
    die("No se encontró una cohorte activa registrada.");
}

$id_cohorte = intval($cohorte['id_cohorte']);

// 2. Leer el horario guardado en la tabla horarios_secciones
$stmt_h = $pdo->prepare("
    SELECT hs.dia_semana, hs.hora_inicio, hs.hora_fin, mm.nombre_materia, u.nombre, u.apellido
    FROM public.horarios_secciones hs
    JOIN public.malla_materias mm ON hs.id_materia = mm.id_materia
    LEFT JOIN public.usuarios u ON hs.id_profesor = u.id_usuario
    WHERE hs.id_cohorte = :id
");
$stmt_h->execute([':id' => $id_cohorte]);
$horarios_bd = $stmt_h->fetchAll(PDO::FETCH_ASSOC);

// 3. Armar la matriz con los bloques de hora estándar
$bloques_estandar = [
    ['inicio' => '07:00:00', 'fin' => '07:45:00', 'etiqueta' => '07:00 AM - 07:45 AM (Desayuno)'],
    ['inicio' => '08:00:00', 'fin' => '08:45:00', 'etiqueta' => '08:00 AM - 08:45 AM (Hora 1)'],
    ['inicio' => '08:45:00', 'fin' => '09:30:00', 'etiqueta' => '08:45 AM - 09:30 AM (Hora 2)'],
    ['inicio' => '09:30:00', 'fin' => '09:45:00', 'etiqueta' => '09:30 AM - 09:45 AM (Receso)'],
    ['inicio' => '09:45:00', 'fin' => '10:30:00', 'etiqueta' => '09:45 AM - 10:30 AM (Hora 3)'],
    ['inicio' => '10:30:00', 'fin' => '11:15:00', 'etiqueta' => '10:30 AM - 11:15 AM (Hora 4)'],
    ['inicio' => '11:15:00', 'fin' => '12:00:00', 'etiqueta' => '11:15 AM - 12:00 PM (Hora 5)'],
    ['inicio' => '12:00:00', 'fin' => '13:15:00', 'etiqueta' => '12:00 PM - 01:15 PM (Almuerzo)'],
    ['inicio' => '13:30:00', 'fin' => '14:15:00', 'etiqueta' => '01:30 PM - 02:15 PM (Hora 6)'],
    ['inicio' => '14:15:00', 'fin' => '15:00:00', 'etiqueta' => '02:15 PM - 03:00 PM (Hora 7)'],
    ['inicio' => '15:00:00', 'fin' => '15:15:00', 'etiqueta' => '03:00 PM - 03:15 PM (Merienda)'],
    ['inicio' => '15:15:00', 'fin' => '16:00:00', 'etiqueta' => '03:15 PM - 04:00 PM (Hora 8)'],
    ['inicio' => '18:00:00', 'fin' => '19:00:00', 'etiqueta' => '06:00 PM - 07:00 PM (Cena)'],
    ['inicio' => '19:00:00', 'fin' => '20:30:00', 'etiqueta' => '07:00 PM - 08:30 PM (Nocturno)']
];

$matriz = [];
foreach ($horarios_bd as $h) {
    $h_ini = date('H:i:s', strtotime($h['hora_inicio']));
    $docente = trim(($h['nombre'] ?? '') . ' ' . ($h['apellido'] ?? ''));
    $matriz[$h['dia_semana']][$h_ini] = [
        'materia' => $h['nombre_materia'],
        'docente' => $docente ?: 'Por Asignar'
    ];
}

// 4. Definir días de clase
$dias = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes'];
if (strpos($cohorte['dias_clase'] ?? '', 'Sábado') !== false) {
    $dias[] = 'Sábado';
}

// Función de colores
function horario_color_materia(string $materia): string {
    $paleta = ['#fdecef', '#fff1dd', '#eaf5ee', '#e9f0fb', '#f3ecfa', '#fdf6d8'];
    return $paleta[abs(crc32($materia)) % count($paleta)];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Horario de Sección - <?= htmlspecialchars($cohorte['codigo_cohorte']) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        :root { --vino: #521f29; --naranja: #ff8a00; }
        body { font-family: 'Segoe UI', Arial, sans-serif; background: #f4efe9; padding: 20px; color: #2d2d2d; }
        .hoja { max-width: 1100px; margin: 0 auto; background: #fff; padding: 25px; border-radius: 12px; border: 1px solid #d8cabb; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        .encabezado { border-bottom: 4px solid var(--naranja); padding-bottom: 10px; margin-bottom: 15px; text-align: center; }
        .titulo { font-size: 20px; font-weight: 800; color: var(--vino); text-transform: uppercase; }
        .subtitulo { font-size: 14px; font-weight: 700; color: var(--naranja); }
        .datos { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-bottom: 15px; }
        .dato { background: #fbf7f2; border-left: 4px solid var(--vino); padding: 8px; border-radius: 6px; font-size: 11px; }
        .dato strong { display: block; font-size: 13px; color: var(--vino); }
        table.horario { width: 100%; border-collapse: collapse; }
        table.horario th, table.horario td { border: 1px solid #cdbcaa; text-align: center; padding: 8px; }
        table.horario th { background: var(--vino); color: #fff; font-size: 12px; }
        td.hora { background: #f1e7dc; color: var(--vino); font-weight: bold; font-size: 11px; }
        td.materia .asignatura { font-weight: 800; color: #3b2226; font-size: 12px; display: block; }
        td.materia .docente { font-size: 10px; color: #6b5b4d; font-weight: 600; display: block; margin-top: 4px; border-top: 1px dashed #ccc; padding-top: 2px; }
        @media print { .no-print { display: none !important; } }
    </style>
</head>
<body>

<div class="hoja">
    <div class="encabezado">
        <div class="titulo">Horario de Clases por Sección</div>
        <div class="subtitulo">Sección: <?= htmlspecialchars($cohorte['codigo_cohorte']) ?> | Salón: <?= htmlspecialchars($cohorte['salon_aula']) ?></div>
    </div>

    <div class="datos">
        <div class="dato"><span>Carrera / Programa</span><strong><?= htmlspecialchars($cohorte['nombre_programa']) ?></strong></div>
        <div class="dato"><span>Semestre / Nivel</span><strong><?= htmlspecialchars($cohorte['tipo_regimen']) ?> <?= $cohorte['num_periodo'] ?></strong></div>
        <div class="dato"><span>Días de Clase</span><strong><?= htmlspecialchars($cohorte['dias_clase']) ?></strong></div>
        <div class="dato"><span>Aforo / Salón</span><strong><?= htmlspecialchars($cohorte['salon_aula']) ?></strong></div>
    </div>

    <table class="horario">
        <thead>
            <tr>
                <th style="width: 120px;">Hora</th>
                <?php foreach ($dias as $d): ?>
                    <th><?= htmlspecialchars($d) ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($bloques_estandar as $b): ?>
                <tr>
                    <td class="hora"><?= $b['etiqueta'] ?></td>
                    <?php foreach ($dias as $d): ?>
                        <?php $celda = $matriz[$d][$b['inicio']] ?? null; ?>
                        <?php if (!empty($celda['materia'])): ?>
                            <td class="materia" style="background: <?= horario_color_materia($celda['materia']) ?>;">
                                <span class="asignatura"><?= htmlspecialchars($celda['materia']) ?></span>
                                <span class="docente"><i class="fas fa-chalkboard-teacher"></i> Docente: <?= htmlspecialchars($celda['docente']) ?></span>
                            </td>
                        <?php else: ?>
                            <td style="color: #bbb; background: #fafafa;">&mdash;</td>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="text-center mt-4 no-print" style="text-align: center; margin-top: 20px;">
        <button onclick="window.print()" style="padding: 10px 20px; background: #ff8a00; color: #fff; border: none; border-radius: 5px; cursor: pointer; font-weight: bold; font-size: 14px;">
            <i class="fas fa-print"></i> Imprimir Horario
        </button>
    </div>
</div>

</body>
</html>