<?php
// /sistema_nora/modulos/guarderia/asistencia.php
require_once '../../config/db.php';
require_once '../../includes/header.php';

// Control de acceso: exclusivo de Guardería / Administración
if (!tiene_permiso(['guarderia', 'admin', 'superadmin'], $roles_array)) {
    echo "<div class='alert alert-danger font-weight-bold'>Acceso restringido a Centro de Cuidado Infantil.</div>";
    require_once '../../includes/footer.php';
    exit();
}

// Registrar Entrada (POST para evitar mutaciones por enlace/CSRF)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['entrada_id'])) {
    $id_nino = intval($_POST['entrada_id']);
    $sql = "INSERT INTO public.guarderia_asistencia (id_nino, fecha_asistencia, hora_entrada, estatus) 
            VALUES (:id, CURRENT_DATE, CURRENT_TIME, 'Presente')
            ON CONFLICT DO NOTHING";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $id_nino]);
    header("Location: asistencia.php");
    exit;
}

// Registrar Salida / Retiro (POST para evitar mutaciones por enlace/CSRF)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['salida_id'])) {
    $id_asis = intval($_POST['salida_id']);
    $sql = "UPDATE public.guarderia_asistencia 
            SET hora_salida = CURRENT_TIME, estatus = 'Retirado' 
            WHERE id_asistencia = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $id_asis]);
    header("Location: asistencia.php");
    exit;
}

// Consultar lista de asistencia del día
$sql_asis = "SELECT n.id_nino, n.nombre_nino, n.apellido_nino, n.sexo,
                    u.nombre AS nombre_rep, u.cedula AS cedula_rep, u.telefono AS telefono_rep,
                    a.id_asistencia, a.hora_entrada, a.hora_salida, a.estatus
             FROM public.guarderia_ninos n
             JOIN public.usuarios u ON n.id_representante = u.id_usuario
             LEFT JOIN public.guarderia_asistencia a ON n.id_nino = a.id_nino AND a.fecha_asistencia = CURRENT_DATE
             ORDER BY n.nombre_nino ASC";
$asistencias = $pdo->query($sql_asis)->fetchAll();
?>

<div class="d-sm-flex align-items-center justify-content-between mb-4">
    <h1 class="h3 mb-0 font-weight-bold" style="color: #521f29;">
        <i class="fas fa-clipboard-check mr-2 text-warning"></i>Control Diario de Asistencia - Guardería
    </h1>
    <div>
        <span class="badge badge-warning text-dark font-weight-bold px-3 py-2 mr-2" style="font-size: 10pt;">
            FECHA HOY: <?= date('d/m/Y') ?>
        </span>
        <a href="index.php" class="btn btn-sm btn-secondary font-weight-bold shadow-sm">
            <i class="fas fa-arrow-left mr-1"></i> Volver a Matrícula
        </a>
    </div>
</div>

<div class="card shadow mb-4" style="border-radius: 15px; border: none;">
    <div class="card-header py-3" style="background-color: #521f29; border-top-left-radius: 15px; border-top-right-radius: 15px;">
        <h6 class="m-0 font-weight-bold text-white"><i class="fas fa-clock mr-2"></i>Marcaje de Entrada y Retiro</h6>
    </div>
    <div class="card-body bg-white p-4">
        <div class="table-responsive">
            <table class="table table-hover align-middle" width="100%">
                <thead style="background-color: #ff8a00; color: #521f29;">
                    <tr>
                        <th>Niño / Niña</th>
                        <th>Representante</th>
                        <th>Teléfono</th>
                        <th>Hora Entrada</th>
                        <th>Hora Salida</th>
                        <th>Estatus Hoy</th>
                        <th class="text-center">Acción Marcaje</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($asistencias as $a): ?>
                        <tr>
                            <td class="font-weight-bold" style="color: #521f29; font-size: 11pt;">
                                <?= htmlspecialchars($a['nombre_nino'] . ' ' . $a['apellido_nino']) ?>
                            </td>
                            <td>
                                <strong><?= htmlspecialchars($a['nombre_rep']) ?></strong><br>
                                <small class="text-muted">C.I: V-<?= htmlspecialchars($a['cedula_rep']) ?></small>
                            </td>
                            <td><?= htmlspecialchars($a['telefono_rep'] ?? 'S/D') ?></td>
                            <td>
                                <?= $a['hora_entrada'] ? date('h:i:s A', strtotime($a['hora_entrada'])) : '<span class="text-muted">--:--</span>' ?>
                            </td>
                            <td>
                                <?= $a['hora_salida'] ? date('h:i:s A', strtotime($a['hora_salida'])) : '<span class="text-muted">--:--</span>' ?>
                            </td>
                            <td>
                                <?php if ($a['estatus'] === 'Presente'): ?>
                                    <span class="badge badge-success px-2 py-1"><i class="fas fa-check-circle mr-1"></i> En Guardería</span>
                                <?php elseif ($a['estatus'] === 'Retirado'): ?>
                                    <span class="badge badge-secondary px-2 py-1"><i class="fas fa-home mr-1"></i> Retirado</span>
                                <?php else: ?>
                                    <span class="badge badge-light border text-muted">Ausente</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if (!$a['id_asistencia']): ?>
                                    <form method="POST" class="d-inline">
                                    <?= csrf_field() ?>
                                        <input type="hidden" name="entrada_id" value="<?= $a['id_nino'] ?>">
                                        <button type="submit" class="btn btn-sm btn-success font-weight-bold shadow-sm">
                                            <i class="fas fa-sign-in-alt mr-1"></i> MARCAR ENTRADA
                                        </button>
                                    </form>
                                <?php elseif ($a['estatus'] === 'Presente'): ?>
                                    <form method="POST" class="d-inline">
                                    <?= csrf_field() ?>
                                        <input type="hidden" name="salida_id" value="<?= $a['id_asistencia'] ?>">
                                        <button type="submit" class="btn btn-sm btn-warning font-weight-bold text-dark shadow-sm">
                                            <i class="fas fa-sign-out-alt mr-1"></i> MARCAR RETIRO
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span class="text-muted small font-weight-bold">Completado</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>