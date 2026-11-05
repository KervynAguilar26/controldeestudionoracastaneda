<?php
// /sistema_nora/modulos/estudiante/imprimir_horario_estudiante.php
// Horario oficial del estudiante por cohorte / salón (imprimible)
require_once '../../config/db.php';
require_once '../../includes/horario_generator.php';
session_start();

if (empty($_SESSION['id_usuario'])) {
    header("Location: ../../index.php");
    exit();
}

$id_inscripcion = intval($_GET['id'] ?? 0);

// También se permite indicar el estudiante (el Control de Estudio usa id_estudiante)
if ($id_inscripcion <= 0 && intval($_GET['id_estudiante'] ?? 0) > 0) {
    $stmt_tmp = $pdo->prepare("SELECT MAX(id_inscripcion) FROM public.inscripciones WHERE id_estudiante = :est");
    $stmt_tmp->execute([':est' => intval($_GET['id_estudiante'])]);
    $id_inscripcion = intval($stmt_tmp->fetchColumn());
}

$id_session     = intval($_SESSION['id_usuario'] ?? 0);
$es_admin       = in_array('admin', $_SESSION['roles_asignados'] ?? [])
               || in_array('superadmin', $_SESSION['roles_asignados'] ?? [])
               || in_array('control_estudio', $_SESSION['roles_asignados'] ?? []);

// Cargar datos de la inscripción, estudiante, cohorte y programa
$sql = "SELECT i.*, u.nombre, u.apellido, u.cedula,
               co.codigo_cohorte, co.salon_aula, co.dias_clase, co.horas_diarias, co.fecha_inicio, co.fecha_fin_estimada,
               co.num_periodo, co.hora_inicio, co.hora_fin,
               m.id_malla, m.nombre_programa, m.tipo_oferta, m.tipo_regimen
        FROM public.inscripciones i
        JOIN public.usuarios u ON i.id_estudiante = u.id_usuario
        JOIN public.cohortes co ON i.id_curso = co.id_cohorte
        JOIN public.mallas_curriculares m ON co.id_malla = m.id_malla
        WHERE i.id_inscripcion = :id LIMIT 1";

$stmt = $pdo->prepare($sql);
$stmt->execute([':id' => $id_inscripcion]);
$horario = $stmt->fetch();

if (!$horario) die("Horario no encontrado.");

// Control de propiedad: solo el propio estudiante (o administración) puede descargarlo
if (!$es_admin && intval($horario['id_estudiante']) !== $id_session) {
    die("No tiene permiso para descargar este horario.");
}

// Horario semanal real de la cohorte / salón (se genera si aún no existe)
$semana = horario_obtener_matriz($pdo, intval($horario['id_curso']));
$dias    = $semana['dias'] ?? [];
$bloques = $semana['bloques'] ?? [];
$matriz  = $semana['matriz'] ?? [];

// Si el salón es virtual, obtener el enlace del aula virtual
$enlace_virtual = null;
try {
    $stmt_v = $pdo->prepare("SELECT enlace_virtual FROM public.campus_espacios WHERE nombre_espacio = :nom AND enlace_virtual IS NOT NULL AND enlace_virtual <> '' LIMIT 1");
    $stmt_v->execute([':nom' => $horario['salon_aula']]);
    $enlace_virtual = $stmt_v->fetchColumn() ?: null;
} catch (Exception $e) { $enlace_virtual = null; }

// Autoridad: Jefa de Control de Estudio
$jefa = $pdo->query("SELECT * FROM public.configuracion_autoridades WHERE cargo = 'Jefa de Control de Estudio' LIMIT 1")->fetch();

function horario_color_celda(string $materia): string
{
    $paleta = ['#fdecef', '#fff1dd', '#eaf5ee', '#e9f0fb', '#f3ecfa', '#fdf6d8', '#e8f6f7'];
    return $paleta[abs(crc32($materia)) % count($paleta)];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Horario de Estudios - <?= htmlspecialchars($horario['nombre'] . ' ' . $horario['apellido']) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        :root { --vino: #521f29; --naranja: #ff8a00; --arena: #d6c4af; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 20px;
            background: #f4efe9;
            font-family: 'Segoe UI', Arial, Helvetica, sans-serif;
            color: #2d2d2d;
            font-size: 12px;
        }
        .hoja {
            max-width: 1000px;
            margin: 0 auto;
            background: #fff;
            border: 1px solid #d8cabb;
            border-radius: 14px;
            padding: 22px 26px 26px;
            box-shadow: 0 8px 24px rgba(82,31,41,.10);
        }
        .encabezado {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            padding-bottom: 10px;
            border-bottom: 4px solid var(--naranja);
        }
        .encabezado img { object-fit: contain; }
        .logo-min { height: 46px; max-width: 185px; }
        .logo-escuela { height: 64px; max-width: 84px; }
        .logo-gmvm { height: 50px; max-width: 105px; }
        .centro { text-align: center; flex: 1; }
        .centro .pais { font-size: 9px; font-weight: 700; color: var(--vino); letter-spacing: .4px; }
        .centro .escuela {
            margin: 2px 0;
            font-size: 16px;
            font-weight: 800;
            color: var(--vino);
            text-transform: uppercase;
            line-height: 1.2;
        }
        .centro .sede { font-size: 9px; color: #6b5b4d; font-weight: 600; }
        .titulo {
            margin: 14px 0 10px;
            text-align: center;
            text-transform: uppercase;
            font-size: 17px;
            font-weight: 800;
            letter-spacing: .8px;
            color: var(--vino);
            text-decoration: underline;
            text-decoration-color: var(--naranja);
            text-underline-offset: 4px;
        }
        .datos { display: grid; grid-template-columns: repeat(4, 1fr); gap: 7px; margin-bottom: 10px; }
        .dato {
            background: #fbf7f2;
            border: 1px solid #e3d6c8;
            border-left: 4px solid var(--vino);
            border-radius: 8px;
            padding: 6px 9px;
        }
        .dato span {
            display: block;
            font-size: 8px;
            text-transform: uppercase;
            letter-spacing: .4px;
            color: #8a7562;
            font-weight: 700;
        }
        .dato strong { display: block; margin-top: 2px; font-size: 11px; color: var(--vino); line-height: 1.25; }
        .estudiante {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            background: #fbf7f2;
            border: 1px solid #e3d6c8;
            border-radius: 8px;
            padding: 8px 11px;
            margin-bottom: 10px;
            font-size: 11px;
        }
        .estudiante .bloque-dato strong { color: var(--vino); }
        table.horario { width: 100%; border-collapse: collapse; table-layout: fixed; }
        table.horario th, table.horario td { border: 1px solid #cdbcaa; text-align: center; vertical-align: middle; }
        table.horario thead th {
            background: var(--vino);
            color: #fff;
            padding: 8px 4px;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: .4px;
        }
        table.horario thead th.hora-col { background: #3d161e; width: 110px; }
        table.horario tbody td { height: 58px; padding: 5px 4px; font-size: 10.5px; }
        td.hora {
            background: #f1e7dc;
            color: var(--vino);
            font-weight: 800;
            font-size: 10px !important;
            white-space: nowrap;
            line-height: 1.35;
        }
        td.hora small { display: block; font-size: 8px; color: #8a7562; font-weight: 600; }
        td.materia { text-align: center; }
        td.materia .asignatura {
            display: block;
            font-weight: 800;
            text-transform: uppercase;
            color: #3b2226;
            line-height: 1.25;
            letter-spacing: .2px;
            font-size: 10.5px;
        }
        td.materia .docente {
            display: block;
            margin-top: 4px;
            padding-top: 3px;
            border-top: 1px dashed rgba(82, 31, 41, .25);
            font-size: 9px;
            font-weight: 600;
            color: #6b5b4d;
            line-height: 1.2;
        }
        td.materia .docente i { color: var(--naranja); font-size: 8.5px; margin-right: 3px; }
        td.materia .docente.sin-docente { color: #a89583; font-style: italic; font-weight: 500; }
        td.vacia { color: #b8a99a; }
        .aviso {
            margin-top: 10px;
            font-size: 10px;
            color: #6b5b4d;
            text-align: justify;
            line-height: 1.45;
        }
        .firmas { display: flex; justify-content: space-between; gap: 40px; margin-top: 34px; text-align: center; }
        .firma { flex: 1; }
        .firma .linea { border-top: 1px solid #333; width: 82%; margin: 0 auto; padding-top: 5px; font-size: 11px; }
        .acciones { max-width: 1000px; margin: 0 auto 14px; text-align: right; }
        .btn {
            border: 0;
            border-radius: 8px;
            padding: 9px 16px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            color: #fff;
            background: var(--vino);
        }

        @page { size: letter portrait; margin: 10mm; }
        @media print {
            body { background: #fff; padding: 0; }
            .hoja { border: none; box-shadow: none; max-width: 100%; padding: 0; border-radius: 0; }
            .no-print { display: none !important; }
            .acciones { display: none !important; }
            .encabezado .logo-min { height: 40px; }
            .encabezado .logo-escuela { height: 54px; }
            .encabezado .logo-gmvm { height: 42px; }
            .encabezado .escuela { font-size: 15px; }
            .titulo { font-size: 17px; margin-top: 10px; }
            .datos { margin-bottom: 8px; }
            .dato { padding: 5px 8px; }
            table.horario thead th { padding: 7px 3px; font-size: 10.5px; }
            table.horario thead th.hora-col { width: 84px; }
            table.horario tbody td { height: 50px; padding: 5px 3px; font-size: 10.5px; }
            td.hora { font-size: 9.5px !important; white-space: normal; }
            td.materia .asignatura { font-size: 9.5px; }
            td.materia .docente { font-size: 8.5px; margin-top: 3px; padding-top: 3px; }
            .nota { font-size: 9px; }
            .firmas { margin-top: 22px; }
            table.horario { page-break-inside: avoid; }
        }
    </style>
</head>
<body>

<div class="acciones no-print">
    <a class="btn" href="horario_pdf.php?id=<?= intval($id_inscripcion) ?>" target="_blank" style="background:#ff8a00; color:#2d2d2d; text-decoration:none; border:0; border-radius:8px; padding:9px 16px; font-size:12px; font-weight:700; display:inline-block;">
        📄 Descargar PDF (carta)
    </a>
    <button type="button" class="btn" onclick="window.print()">🖨 Imprimir / Guardar PDF</button>
</div>

<div class="hoja">
    <!-- ENCABEZADO INSTITUCIONAL CON LOGOS -->
    <div class="encabezado">
        <img src="../../assets/img/Logo del Ministerio 999 x 150-01.png" alt="Ministerio" class="logo-min">
        <div class="centro">
            <div class="pais">REPÚBLICA BOLIVARIANA DE VENEZUELA</div>
            <div class="escuela">Escuela Internacional de Liderazgo de la Mujer "Nora Castañeda"</div>
            <div class="sede">Sede Principal Bello Monte · Maracay, Edo. Aragua</div>
        </div>
        <img src="../../assets/img/LogoEscuelaNora_NUEVO.png" alt="Escuela Nora Castañeda" class="logo-escuela">
        <img src="../../assets/img/GMVM.png" alt="GMVM" class="logo-gmvm">
    </div>

    <div class="titulo">Horario Oficial de Estudios</div>

    <div class="estudiante">
        <div class="bloque-dato">ESTUDIANTE: <strong><?= htmlspecialchars($horario['nombre'] . ' ' . $horario['apellido']) ?></strong></div>
        <div class="bloque-dato">CÉDULA: <strong>V-<?= htmlspecialchars($horario['cedula']) ?></strong></div>
        <div class="bloque-dato">COHORTE: <strong><?= htmlspecialchars($horario['codigo_cohorte']) ?></strong></div>
    </div>

    <div class="datos">
        <div class="dato"><span>Salón / Aula</span><strong><?= htmlspecialchars($horario['salon_aula']) ?></strong></div>
        <div class="dato"><span>Programa</span><strong><?= htmlspecialchars($horario['nombre_programa']) ?> (<?= htmlspecialchars($horario['tipo_oferta']) ?>)</strong></div>
        <div class="dato"><span>Período</span><strong><?= htmlspecialchars(($horario['tipo_regimen'] ?: 'Período') . ' ' . intval($horario['num_periodo'] ?? 1)) ?></strong></div>
        <div class="dato"><span>Horario</span><strong><?= htmlspecialchars($horario['dias_clase']) ?></strong></div>
    </div>

    <?php if ($enlace_virtual): ?>
        <div class="aviso" style="background:#e9f0fb; padding:7px 10px; border-radius:6px; text-align:center; font-weight:700;">
            Modalidad VIRTUAL — Enlace del aula: <?= htmlspecialchars($enlace_virtual) ?>
        </div>
    <?php endif; ?>

    <!-- GRILLA SEMANAL: SOLO ASIGNATURA / MATERIA -->
    <table class="horario">
        <thead>
            <tr>
                <th class="hora-col">Hora</th>
                <?php foreach ($dias as $dia): ?>
                    <th><?= htmlspecialchars($dia) ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($bloques)): ?>
                <tr><td colspan="<?= count($dias) + 1 ?>" class="vacia" style="padding:18px;">El horario de este salón aún no ha sido generado. Contacte a Control de Estudio.</td></tr>
            <?php else: ?>
                <?php foreach ($bloques as $bloque): ?>
                    <tr>
                        <td class="hora">
                            <?= htmlspecialchars($bloque['inicio']) ?> - <?= htmlspecialchars($bloque['fin']) ?>
                            <small>Hora académica</small>
                        </td>
                        <?php foreach ($dias as $dia): ?>
                            <?php $celda = $matriz[$dia][$bloque['inicio']] ?? null; ?>
                            <?php if (!empty($celda['materia'])): ?>
                                <td class="materia" style="background: <?= horario_color_celda($celda['materia']) ?>;">
                                    <span class="asignatura"><?= htmlspecialchars($celda['materia']) ?></span>
                                    <?php if (!empty($celda['docente'])): ?>
                                        <span class="docente"><i class="fas fa-user-tie"></i><?= htmlspecialchars($celda['docente']) ?></span>
                                    <?php else: ?>
                                        <span class="docente sin-docente"><i class="fas fa-user-tie"></i>Docente por asignar</span>
                                    <?php endif; ?>
                                </td>
                            <?php else: ?>
                                <td class="vacia">&mdash;</td>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>

    <p class="aviso">
        Cada hora académica consta de <?= HORARIO_MINUTOS_CLASE ?> minutos de clase y <?= HORARIO_MINUTOS_DESCANSO ?> minutos de intermedio.
        Período académico: del <?= date('d/m/Y', strtotime($horario['fecha_inicio'])) ?>
        <?= $horario['fecha_fin_estimada'] ? 'al ' . date('d/m/Y', strtotime($horario['fecha_fin_estimada'])) : '' ?>.
        Documento generado el <?= date('d/m/Y H:i') ?>. El horario puede variar según actualizaciones de la Unidad de Control de Estudio.
    </p>

    <!-- FIRMAS -->
    <div class="firmas">
        <div class="firma">
            <div class="linea">
                <strong><?= htmlspecialchars($horario['nombre'] . ' ' . $horario['apellido']) ?></strong><br>
                Estudiante
            </div>
        </div>
        <div class="firma">
            <?php if (!empty($jefa['firma_digital'])): ?>
                <img src="../../<?= htmlspecialchars($jefa['firma_digital']) ?>" style="max-height: 60px;" onerror="this.style.display='none'">
            <?php endif; ?>
            <div class="linea">
                <strong><?= htmlspecialchars($jefa['nombre_apellido'] ?? 'Jefa de Control de Estudio') ?></strong><br>
                Jefa de Control de Estudio
            </div>
        </div>
    </div>
</div>

</body>
</html>
