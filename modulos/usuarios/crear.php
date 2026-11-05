<?php
// /sistema_nora/modulos/usuarios/crear.php
require_once '../../config/db.php';
require_once '../../includes/header.php';

// Verificar permisos de Superusuario / Administrador
if (!in_array('superadmin', $roles_array) && !in_array('admin', $roles_array)) {
    echo "<div class='alert alert-danger font-weight-bold m-4'>Acceso denegado. Solo el Superusuario o Administrador puede crear pre-autorizaciones.</div>";
    require_once '../../includes/footer.php';
    exit();
}

$msg = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $cedula = preg_replace('/[^0-9]/', '', trim($_POST['cedula']));
        $cargo  = $_POST['cargo_funcion'] ?? 'Servicio Generales';
        $estatus= $_POST['estatus_cuenta'] ?? 'Pendiente';
        $roles_sel = $_POST['roles'] ?? ['trabajador'];
        $roles_sel = array_values(array_intersect((array)$roles_sel, ['superadmin','admin','defensoria','comedor','cocina','control_estudio','profesor','estudiante','guarderia','trabajador','monitor']));
        if (empty($roles_sel)) {
            throw new Exception("Debe seleccionar al menos un rol válido.");
        }

        if (empty($cedula)) {
            throw new Exception("Debe ingresar un número de cédula válido.");
        }

        // Consultar Padrón para obtener nombres automáticamente
        $nombre = 'POR COMPLETAR';
        $apellido = 'POR COMPLETAR';
        $f_nac = null; $sexo = ''; $edad = 0; $est_civil = '';

        try {
            $conexion_padron = $pdo_padron ?? $pdo;
            $stmt_p = $conexion_padron->prepare("SELECT * FROM public.datos WHERE document = :c LIMIT 1");
            $stmt_p->execute([':c' => $cedula]);
            $pad = $stmt_p->fetch();

            if ($pad) {
                $nombre   = trim(($pad['first_name'] ?? '') . ' ' . ($pad['second_name'] ?? ''));
                $apellido = trim(($pad['first_surname'] ?? '') . ' ' . ($pad['second_surname'] ?? ''));
                if (!empty($pad['birth_date'])) {
                    $f_obj = new DateTime($pad['birth_date']);
                    $f_nac = $f_obj->format('Y-m-d');
                    $edad  = (new DateTime())->diff($f_obj)->y;
                }
                $genero_map = ['F' => 'Femenino', 'M' => 'Masculino'];
                $sexo = $genero_map[strtoupper($pad['genero'] ?? '')] ?? ($pad['genero'] ?? '');
                $ec_map = ['1' => 'Soltero(a)', '2' => 'Casado(a)', '3' => 'Divorciado(a)', '4' => 'Viudo(a)', '5' => 'Concubinato'];
                $est_civil = $ec_map[strval($pad['estado_civil'] ?? '')] ?? ($pad['estado_civil'] ?? '');
            }
        } catch (Exception $e) {}

        // Verificar si la Cédula ya existe en la tabla de usuarios
        $stmt_chk = $pdo->prepare("SELECT id_usuario FROM public.usuarios WHERE cedula = :c LIMIT 1");
        $stmt_chk->execute([':c' => $cedula]);
        if ($stmt_chk->fetch()) {
            throw new Exception("La cédula V-$cedula ya se encuentra registrada o pre-autorizada.");
        }

        // Determinar tipo de usuario primario
        $tipo_primario = $roles_sel[0] ?? 'trabajador';

        // Convertir arreglo de roles a formato array PostgreSQL
        $roles_pg = '{' . implode(',', array_map(function($r){ return '"'.trim($r).'"'; }, $roles_sel)) . '}';

        // Contraseña temporal por defecto: número de cédula
        $pass_hash = password_hash($cedula, PASSWORD_BCRYPT);

        $sql_ins = "INSERT INTO public.usuarios 
            (cedula, nombre, apellido, fecha_nacimiento, sexo, edad, estado_civil, cargo_funcion, tipo_usuario, roles_asignados, estatus_cuenta, password, correo) 
            VALUES 
            (:c, :n, :a, :fn, :sx, :ed, :ec, :car, :tp, :roles, :est, :p, :cor)";

        $stmt_i = $pdo->prepare($sql_ins);
        $stmt_i->execute([
            ':c' => $cedula, ':n' => $nombre, ':a' => $apellido, ':fn' => $f_nac,
            ':sx' => $sexo, ':ed' => $edad, ':ec' => $est_civil, ':car' => $cargo,
            ':tp' => $tipo_primario, ':roles' => $roles_pg, ':est' => $estatus,
            ':p' => $pass_hash, ':cor' => 'pendiente_' . $cedula . '@escuelanora.gob.ve'
        ]);

        $msg = "¡Llave de Cédula V-$cedula pre-autorizada exitosamente! El usuario ya puede registrarse.";
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
?>

<div class="row justify-content-center my-4">
    <div class="col-md-8">
        <div class="card shadow border-0" style="border-radius: 12px; overflow: hidden;">
            <div class="card-header text-white font-weight-bold py-3" style="background-color: #521f29;">
                <i class="fas fa-key mr-2" style="color: #ff8a00;"></i> Habilitación de Cuenta y Asignación de Permisos
            </div>
            <div class="card-body bg-white p-4">

                <?php if (!empty($msg)): ?>
                    <div class="alert alert-success font-weight-bold shadow-sm"><i class="fas fa-check-circle mr-2"></i><?= $msg ?></div>
                <?php endif; ?>
                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger font-weight-bold shadow-sm"><i class="fas fa-exclamation-triangle mr-2"></i><?= $error ?></div>
                <?php endif; ?>

                <form method="POST">
                <?= csrf_field() ?>
                    <div class="form-group mb-4">
                        <label class="font-weight-bold text-dark">Número de Cédula de Identidad * (Llave de Acceso)</label>
                        <input type="text" name="cedula" class="form-control form-control-lg font-weight-bold" placeholder="Ej: 12345678" required>
                        <small class="form-text text-muted">La contraseña inicial por defecto será el número de cédula.</small>
                    </div>

                    <!-- SECCIÓN DE ROLES DIVERSIFICADOS -->
                    <div class="p-3 mb-4 rounded" style="background-color: #fff8f0; border: 2px dashed #ff8a00;">
                        <label class="font-weight-bold text-danger mb-2"><i class="fas fa-user-shield mr-1"></i> Seleccione uno o varios Roles (Multi-rol Permitido):</label>
                        
                        <div class="custom-control custom-checkbox mb-2">
                            <input type="checkbox" name="roles[]" value="trabajador" class="custom-control-input" id="rol_trab" checked>
                            <label class="custom-control-label font-weight-bold text-dark" for="rol_trab">Trabajador General (Servicios Generales)</label>
                        </div>
                        <div class="custom-control custom-checkbox mb-2">
                            <input type="checkbox" name="roles[]" value="control_estudio" class="custom-control-input" id="rol_ce">
                            <label class="custom-control-label font-weight-bold text-dark" for="rol_ce">Control de Estudio</label>
                        </div>
                        <div class="custom-control custom-checkbox mb-2">
                            <input type="checkbox" name="roles[]" value="profesor" class="custom-control-input" id="rol_prof">
                            <label class="custom-control-label font-weight-bold text-dark" for="rol_prof">Profesor / Docente</label>
                        </div>
                        <div class="custom-control custom-checkbox mb-2">
                            <input type="checkbox" name="roles[]" value="comedor" class="custom-control-input" id="rol_com">
                            <label class="custom-control-label font-weight-bold text-dark" for="rol_com">Comedor (Cocinera)</label>
                        </div>
                        <div class="custom-control custom-checkbox mb-2">
                            <input type="checkbox" name="roles[]" value="guarderia" class="custom-control-input" id="rol_guard">
                            <label class="custom-control-label font-weight-bold text-dark" for="rol_guard">Centro de Cuidado Infantil (Madre Cuidadora)</label>
                        </div>
                        <div class="custom-control custom-checkbox mb-2">
                            <input type="checkbox" name="roles[]" value="monitor" class="custom-control-input" id="rol_mon">
                            <label class="custom-control-label font-weight-bold text-dark" for="rol_mon">Monitor de Campo (Comedor + Infraestructura, offline)</label>
                        </div>
                        <div class="custom-control custom-checkbox mb-2">
                            <input type="checkbox" name="roles[]" value="defensoria" class="custom-control-input" id="rol_def">
                            <label class="custom-control-label font-weight-bold text-dark" for="rol_def">Defensoría</label>
                        </div>
                        <div class="custom-control custom-checkbox mb-2">
                            <input type="checkbox" name="roles[]" value="admin" class="custom-control-input" id="rol_adm">
                            <label class="custom-control-label font-weight-bold text-danger" for="rol_adm">Administrador del Sistema</label>
                        </div>
                    </div>

                    <div class="form-group mb-4">
                        <label class="font-weight-bold text-dark">Cargo / Función del Trabajador *</label>
                        <select name="cargo_funcion" class="form-control font-weight-bold" required>
                            <option value="Servicio Generales">Servicio Generales</option>
                            <option value="Profesora (or)">Profesora (or)</option>
                            <option value="Cocinera">Cocinera</option>
                            <option value="Madre Cuidadora">Madre Cuidadora</option>
                            <option value="Defensora(or)">Defensora(or)</option>
                            <option value="Doctor(a)">Doctor(a)</option>
                            <option value="Enfermera (o)">Enfermera (o)</option>
                            <option value="Seguridad">Seguridad</option>
                            <option value="Monitor">Monitor</option>
                            <option value="Administración">Administración</option>
                            <option value="Administrativo">Administrativo</option>
                            <option value="Control de Estudio">Control de Estudio</option>
                            <option value="Tecnología de la Información">Tecnología de la Información</option>
                            <option value="Prensa y Comunicación">Prensa y Comunicación</option>
                            <option value="Director(a)">Director(a)</option>
                            <option value="Estudiante">Estudiante</option>

                        </select>
                    </div>

                    <div class="form-group mb-4">
                        <label class="font-weight-bold text-dark">Estatus Inicial de la Cuenta *</label>
                        <select name="estatus_cuenta" class="form-control font-weight-bold text-success" required>
                            <option value="Activo">Activo (Habilitado para ingresar directamente)</option>
                            <option value="Pendiente" selected>Pendiente (Requiere que el usuario complete su registro)</option>
                        </select>
                    </div>

                    <button type="submit" class="btn btn-block font-weight-bold text-white py-3 shadow" style="background-color: #ff8a00; border-radius: 10px;">
                        <i class="fas fa-save mr-2"></i> GUARDAR Y PRE-AUTORIZAR LLAVE DE CÉDULA
                    </button>
                </form>

            </div>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>