<?php
session_start();
require_once '../config/conexion.php';

$error_msj = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $identificador = trim($_POST['identificador'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($identificador) || empty($password)) {
        $error_msj = "Por favor, completa todos los campos.";
    } else {
        try {
            // Buscamos al cliente haciendo JOIN permitiendo ingresar Correo o Usuario
            $sql = "SELECT c.CodCliente, u.Password, p.Nombres, p.Apellidos
                    FROM Tb_Cliente c
                    INNER JOIN Tb_Persona p ON c.CodPersona = p.CodPersona
                    INNER JOIN Tb_Usuario_Cliente u ON c.CodCliente = u.CodCliente
                    WHERE (p.Email = ? OR u.Usuario = ?) 
                      AND u.Estado_Usuario = 'Activo' 
                      AND c.Estado_Cliente = 'Activo'";

            $stmt = $conn->prepare($sql);
            $stmt->execute([$identificador, $identificador]);
            $cliente = $stmt->fetch(PDO::FETCH_ASSOC);

            // Verificamos la contraseña en texto plano
            if ($cliente && $password === $cliente['Password']) {
                $_SESSION['cliente_id'] = trim($cliente['CodCliente']);
                $_SESSION['cliente_nombre'] = trim($cliente['Nombres']) . " " . trim($cliente['Apellidos']);

                // Actualizar último acceso
                $upd = $conn->prepare("UPDATE Tb_Usuario_Cliente SET Ultimo_Acceso = GETDATE() WHERE CodCliente = ?");
                $upd->execute([$cliente['CodCliente']]);

                header("Location: index.php");
                exit;
            } else {
                $error_msj = "Credenciales incorrectas. Verifica tu usuario/correo y contraseña.";
            }
        } catch (PDOException $e) {
            $error_msj = "Error al conectar con la base de datos: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar Sesión - La Industria Surco</title>
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
            min-height: 100vh;
        }
        .login-card {
            background: #161622;
            border: 1px solid #262638;
            border-radius: 14px;
            padding: 40px;
            width: 100%;
            max-width: 420px;
            box-shadow: 0 10px 35px rgba(0,0,0,0.7);
        }
        .logo-area { text-align: center; margin-bottom: 25px; }
        .logo { font-weight: 800; font-size: 1.5rem; color: #ffffff; text-decoration: none; letter-spacing: 1px; }
        .logo span { color: #ff0055; }
        .login-card h2 { margin: 0 0 10px 0; font-size: 1.3rem; color: #fff; text-align: center; }
        .login-card p { color: #9e9eb3; font-size: 0.9rem; text-align: center; margin-bottom: 25px; }

        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; color: #ccc; font-weight: 600; font-size: 0.9rem; }
        .form-control {
            width: 100%; padding: 12px 15px; background: #0f0f17;
            border: 1px solid #323248; border-radius: 8px; color: #fff;
            font-size: 1rem;
        }
        .form-control:focus { outline: none; border-color: #ff0055; }

        .btn-submit {
            width: 100%; padding: 13px; border: none; border-radius: 8px;
            background: linear-gradient(135deg, #ff0055 0%, #b3003c 100%);
            color: #fff; font-size: 1rem; font-weight: bold; cursor: pointer;
            box-shadow: 0 4px 15px rgba(255, 0, 85, 0.3);
            margin-top: 10px;
        }
        .btn-submit:hover { opacity: 0.93; }

        .error-alerta {
            background: rgba(255, 0, 85, 0.12); border: 1px solid #ff0055;
            color: #ff80aa; padding: 12px; border-radius: 8px; margin-bottom: 20px;
            font-size: 0.9rem; text-align: center;
        }
        .links-container { margin-top: 22px; text-align: center; border-top: 1px solid #262638; padding-top: 18px; }
        .links-container a {
            display: block; color: #9e9eb3; text-decoration: none; font-size: 0.9rem; margin-bottom: 10px;
        }
        .links-container a:hover { color: #ff0055; text-decoration: underline; }
        .links-container a.trabajador-link { color: #ff6699; font-weight: 600; }
        .links-container a.registro-link { color: #00ffcc; font-weight: 600; }
    </style>
</head>
<body>

<div class="login-card">
    <div class="logo-area">
        <a href="index.php" class="logo">LA INDUSTRIA <span>SURCO</span></a>
    </div>
    <h2>Acceso de Clientes</h2>
    <p>Ingresa con tu correo o nombre de usuario</p>

    <?php if ($error_msj): ?>
        <div class="error-alerta"><?php echo htmlspecialchars($error_msj); ?></div>
    <?php endif; ?>

    <form method="POST" action="login_cliente.php">
        <div class="form-group">
            <label for="identificador">Correo o Usuario:</label>
            <input type="text" id="identificador" name="identificador" class="form-control" required placeholder="ej. cgutierre1 o correo@mail.com">
        </div>

        <div class="form-group">
            <label for="password">Contraseña:</label>
            <input type="password" id="password" name="password" class="form-control" required placeholder="••••••••">
        </div>

        <button type="submit" class="btn-submit">Iniciar Sesión</button>
    </form>

    <div class="links-container">
        <a href="registro_cliente.php" class="registro-link">✨ ¿No tienes cuenta? Regístrate aquí</a>
        <a href="login_trabajador.php" class="trabajador-link">🔐 ¿Eres trabajador? Accede aquí</a>
        <a href="index.php">&larr; Volver a la página principal</a>
    </div>
</div>

</body>
</html>