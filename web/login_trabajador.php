<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// Si ya hay una sesión activa de empleado, lo mandamos directo al panel de control
if (isset($_SESSION['rol']) && $_SESSION['rol'] === 'empleado') {
    header("Location: panel_control.php");
    exit;
}

require_once '../config/conexion.php';

$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usuario  = trim($_POST['usuario'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($usuario) || empty($password)) {
        $error = "Por favor, complete todos los campos.";
    } else {
        try {
            // Consulta corregida basada en tu DDL oficial (Tb_Usuario_Empleado, Tb_Empleado y Tb_Persona)
            $sql = "SELECT ue.CodEmpleado, ue.Usuario, ue.Perfil, ue.Estado_Usuario, p.Nombres, p.Apellidos 
                    FROM Tb_Usuario_Empleado ue
                    INNER JOIN Tb_Empleado e ON ue.CodEmpleado = e.CodEmpleado
                    INNER JOIN Tb_Persona p ON e.CodPersona = p.CodPersona
                    WHERE ue.Usuario = ? AND ue.Password = ?";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute([$usuario, $password]);
            $empleado = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($empleado) {
                // Verificar si el usuario del empleado está activo
                if ($empleado['Estado_Usuario'] !== 'Activo') {
                    $error = "Su cuenta de empleado se encuentra inactiva. Contacte al administrador.";
                } else {
                    // Establecer las variables de sesión del empleado de forma segura
                    $_SESSION['empleado_id']     = trim($empleado['CodEmpleado']);
                    $_SESSION['empleado_nombre'] = $empleado['Nombres'] . ' ' . $empleado['Apellidos'];
                    $_SESSION['empleado_perfil'] = $empleado['Perfil'];
                    $_SESSION['rol']             = 'empleado'; // Variable clave para los permisos

                    header("Location: panel_control.php");
                    exit;
                }
            } else {
                $error = "Usuario o contraseña de trabajador incorrectos.";
            }
        } catch (PDOException $e) {
            $error = "Error al conectar con la base de datos: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acceso Trabajadores - La Industria Surco</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            background-color: #0b0b10;
            color: #f1f1f5;
            margin: 0;
            padding: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
        }
        .login-card {
            background: #161622;
            border: 1px solid #262638;
            border-radius: 14px;
            padding: 40px;
            width: 100%;
            max-width: 420px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.6);
        }
        .logo { text-align: center; margin-bottom: 25px; }
        .logo h2 { margin: 0; font-size: 1.5rem; color: #fff; letter-spacing: 1px; }
        .logo h2 span { color: #ff0055; }
        .logo p { margin: 5px 0 0 0; color: #8888a0; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 2px; }
        
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; color: #ccc; font-weight: 600; font-size: 0.9rem; }
        .form-control {
            width: 100%; padding: 12px 15px; background: #0f0f17;
            border: 1px solid #323248; border-radius: 8px; color: #fff; font-size: 1rem; outline: none;
            transition: border-color 0.2s;
        }
        .form-control:focus { border-color: #ff0055; }
        
        .btn-submit {
            width: 100%; padding: 14px; border: none; border-radius: 8px;
            background: linear-gradient(135deg, #ff0055 0%, #b3003c 100%);
            color: #fff; font-size: 1rem; font-weight: bold; cursor: pointer;
            box-shadow: 0 4px 15px rgba(255, 0, 85, 0.3); transition: opacity 0.2s;
        }
        .btn-submit:hover { opacity: 0.9; }

        .alerta-error {
            background: rgba(255, 0, 85, 0.12); border: 1px solid #ff0055;
            color: #ff80aa; padding: 12px 15px; border-radius: 8px; margin-bottom: 20px; font-size: 0.9rem;
        }
        .volver-link {
            display: block; text-align: center; margin-top: 20px;
            color: #8888a0; text-decoration: none; font-size: 0.88px; font-size: 0.9rem;
        }
        .volver-link:hover { color: #ff0055; }
    </style>
</head>
<body>

<div class="login-card">
    <div class="logo">
        <h2>LA INDUSTRIA <span>SURCO</span></h2>
        <p>Panel de Empleados</p>
    </div>

    <?php if ($error): ?>
        <div class="alerta-error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <form method="POST" action="login_trabajador.php">
        <div class="form-group">
            <label>Usuario de Trabajador:</label>
            <input type="text" name="usuario" class="form-control" required autocomplete="off" placeholder="Ej. emp01">
        </div>

        <div class="form-group">
            <label>Contraseña:</label>
            <input type="password" name="password" class="form-control" required placeholder="••••••••">
        </div>

        <button type="submit" class="btn-submit">Ingresar al Sistema</button>
    </form>

    <a href="index.php" class="volver-link">&larr; Volver al portal de clientes</a>
</div>

</body>
</html>