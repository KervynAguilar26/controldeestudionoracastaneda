<?php
// /sistema_nora/modulos/control_estudio/procesar_inscripcion.php
session_start();
require_once '../../config/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['procesar_inscripcion'])) {
    try {
        $id_estudiante = intval($_POST['id_estudiante'] ?? 0);
        $id_cohorte    = intval($_POST['id_cohorte'] ?? 0);

        if ($id_estudiante <= 0 || $id_cohorte <= 0) {
            throw new Exception("Debe seleccionar un estudiante y una sección válida.");
        }

        // 1. Verificar si la sección/cohorte tiene cupos disponibles
        $stmt_c = $pdo->prepare("
            SELECT cupos_maximos, codigo_cohorte,
                (SELECT COUNT(*) FROM public.inscripciones WHERE id_curso = :id_cohorte) AS inscritos
            FROM public.cohortes WHERE id_cohorte = :id_cohorte
        ");
        $stmt_c->execute([':id_cohorte' => $id_cohorte]);
        $cohorte_info = $stmt_c->fetch(PDO::FETCH_ASSOC);

        if (!$cohorte_info) {
            throw new Exception("La sección seleccionada no existe.");
        }

        if ($cohorte_info['inscritos'] >= $cohorte_info['cupos_maximos']) {
            throw new Exception("La sección '{$cohorte_info['codigo_cohorte']}' ha alcanzado su capacidad máxima (Capacidad: {$cohorte_info['cupos_maximos']} estudiantes). Inscriba en otra sección o abra la Sección B.");
        }

        // 2. Verificar si el estudiante ya está inscrito en esa sección exacta
        $stmt_dup = $pdo->prepare("SELECT COUNT(*) FROM public.inscripciones WHERE id_estudiante = :id_est AND id_curso = :id_cohorte");
        $stmt_dup->execute([':id_est' => $id_estudiante, ':id_cohorte' => $id_cohorte]);
        
        if ($stmt_dup->fetchColumn() > 0) {
            throw new Exception("El estudiante ya se encuentra matriculado en esta misma sección.");
        }

        // 3. Registrar Inscripción
        $sql_ins = "INSERT INTO public.inscripciones (id_estudiante, id_curso, nota_cualitativa, fecha_inscripcion) 
                    VALUES (:id_est, :id_cohorte, 'En Progreso', CURRENT_TIMESTAMP)";
        $stmt_i = $pdo->prepare($sql_ins);
        $stmt_i->execute([':id_est' => $id_estudiante, ':id_cohorte' => $id_cohorte]);

        $_SESSION['mensaje'] = "Inscripción/Promoción realizada exitosamente en la sección '{$cohorte_info['codigo_cohorte']}'.";
        header("Location: matricula_general.php");
        exit();

    } catch (Exception $e) {
        $_SESSION['error'] = $e->getMessage();
        header("Location: matricula_general.php");
        exit();
    }
}
?>