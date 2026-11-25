<?php
session_start();
require_once '../config/conexion.php';

$error_msj = "";
$exito_msj = "";

// Obtener la lista de distritos para el formulario
try {
    $stmtDist = $conn->query("SELECT CPostal, Distrito FROM Tb_Distrito");
    $listaDistritos = $stmtDist->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $listaDistritos = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombres   = trim($_POST['nombres'] ?? '');
    $apellidos = trim($_POST['apellidos'] ?? '');
    $fec_nac   = trim($_POST['fec_nac'] ?? '');
    $sexo      = trim($_POST['sexo'] ?? 'M');
    $nro_doc   = trim($_POST['nro_doc'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $usuario   = trim($_POST['usuario'] ?? '');
    $password  = trim($_POST['password'] ?? '');
    $cpostal   = trim($_POST['cpostal'] ?? '');
    $direccion = trim($_POST['direccion'] ?? 'No especificada');

    if (empty($nombres) || empty($apellidos) || empty($fec_nac) || empty($nro_doc) || empty($email) || empty($usuario) || empty($password) || empty($cpostal)) {
        $error_msj = "Por favor, completa todos los campos obligatorios, incluyendo el distrito.";
    } elseif (strlen($usuario) > 12) {
        $error_msj = "El nombre de usuario no puede superar los 12 caracteres.";
    } else {
        try {
            $conn->beginTransaction();

            // 1. Verificar si el correo o usuario ya existen
            $check = $conn->prepare("SELECT COUNT(*) FROM Tb_Persona WHERE Email = ?");
            $check->execute([$email]);
            if ($check->fetchColumn() > 0) {
                throw new Exception("El correo electrónico ya se encuentra registrado.");
            }

            $checkUser = $conn->prepare("SELECT COUNT(*) FROM Tb_Usuario_Cliente WHERE Usuario = ?");
            $checkUser->execute([$usuario]);
            if ($checkUser->fetchColumn() > 0) {
                throw new Exception("El nombre de usuario ya está en uso. Elige otro.");
            }

            // 2. Obtener catálogos seguros existentes
            $stmtNac = $conn->query("SELECT TOP 1 CodNacionalidad FROM Tb_Nacionalidad");
            $codNac = $stmtNac->fetchColumn() ?: 'NAC001';

            $stmtDoc = $conn->query("SELECT TOP 1 CodDoc_Identidad FROM Tb_Documento_Identidad");
            $codDoc = $stmtDoc->fetchColumn() ?: 'DOC01';

            // 3. Obtener el siguiente CodPersona (int)
            $stmtMaxP = $conn->query("SELECT ISNULL(MAX(CodPersona), 0) + 1 FROM Tb_Persona");
            $nuevoCodPersona = $stmtMaxP->fetchColumn();

            // 4. Insertar en Tb_Persona usando el CPostal seleccionado y la dirección
            $sqlPersona = "INSERT INTO Tb_Persona (CodPersona, Apellidos, Nombres, Fec_Nac, Sexo, CodNacionalidad, CodDoc_Identidad, NroDoc_Identidad, CPostal, Direccion, Email) 
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmtP = $conn->prepare($sqlPersona);
            $stmtP->execute([$nuevoCodPersona, $apellidos, $nombres, $fec_nac, $sexo, $codNac, $codDoc, $nro_doc, $cpostal, $direccion, $email]);

            // 5. Generar un CodCliente único (char 5)
            $stmtMaxC = $conn->query("SELECT COUNT(*) FROM Tb_Cliente");
            $totalClientes = (int)$stmtMaxC->fetchColumn() + 1;
            $nuevoCodCliente = 'CLI' . str_pad($totalClientes, 2, '0', STR_PAD_LEFT);

            while(true) {
                $chkC = $conn->prepare("SELECT COUNT(*) FROM Tb_Cliente WHERE CodCliente = ?");
                $chkC->execute([$nuevoCodCliente]);
                if($chkC->fetchColumn() == 0) break;
                $totalClientes++;
                $nuevoCodCliente = 'CLI' . str_pad($totalClientes, 2, '0', STR_PAD_LEFT);
            }

            // 6. Insertar en Tb_Cliente
            $sqlCliente = "INSERT INTO Tb_Cliente (CodCliente, CodPersona, Estado_Cliente, Obs_Estado_Cliente) VALUES (?, ?, 'Activo', 'Registro web autónomo')";
            $stmtC = $conn->prepare($sqlCliente);
            $stmtC->execute([$nuevoCodCliente, $nuevoCodPersona]);

            // 7. Insertar credenciales en Tb_Usuario_Cliente
            $sqlUser = "INSERT INTO Tb_Usuario_Cliente (CodCliente, Usuario, Password, Ultimo_Acceso, Estado_Usuario) VALUES (?, ?, ?, GETDATE(), 'Activo')";
            $stmtU = $conn->prepare($sqlUser);
            $stmtU->execute([$nuevoCodCliente, $usuario, $password]);

            $conn->commit();
            $exito_msj = "¡Cuenta creada con éxito! Ya puedes iniciar sesión con tu usuario ($usuario) o correo.";

        } catch (Exception $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            $error_msj = "Error al registrarse: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registro de Cliente - La Industria Surco</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            background-color: #0b0b10;
            color: #f1f1f5;
            margin: 0;
            padding: 30px 0;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }
        .registro-card {
            background: #161622;
            border: 1px solid #262638;
            border-radius: 14px;
            padding: 35px 40px;
            width: 100%;
            max-width: 520px;
            box-shadow: 0 10px 35px rgba(0,0,0,0.7);
        }
        .logo-area { text-align: center; margin-bottom: 20px; }
        .logo { font-weight: 800; font-size: 1.5rem; color: #ffffff; text-decoration: none; letter-spacing: 1px; }
        .logo span { color: #ff0055; }
        .registro-card h2 { margin: 0 0 8px 0; font-size: 1.3rem; color: #fff; text-align: center; }
        .registro-card p { color: #9e9eb3; font-size: 0.9rem; text-align: center; margin-bottom: 25px; }

        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        .form-group { margin-bottom: 15px; }
        .form-group.full { grid-column: span 2; }
        .form-group label { display: block; margin-bottom: 6px; color: #ccc; font-weight: 600; font-size: 0.85rem; }
        .form-control {
            width: 100%; padding: 10px 14px; background: #0f0f17;
            border: 1px solid #323248; border-radius: 8px; color: #fff;
            font-size: 0.95rem;
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
        .exito-alerta {
            background: rgba(0, 255, 204, 0.1); border: 1px solid #00ffcc;
            color: #00ffcc; padding: 15px; border-radius: 8px; margin-bottom: 20px;
            font-size: 0.9rem; text-align: center;
        }
        .links-container { margin-top: 20px; text-align: center; border-top: 1px solid #262638; padding-top: 15px; }
        .links-container a { color: #ff6699; text-decoration: none; font-size: 0.9rem; font-weight: 600; }
        .links-container a:hover { text-decoration: underline; }
    </style>
</head>
<body>

<div class="registro-card">
    <div class="logo-area">
        <a href="index.php" class="logo">LA INDUSTRIA <span>SURCO</span></a>
    </div>
    <h2>Registro de Nueva Cuenta</h2>
    <p>Crea tu perfil para pedir bebidas y gestionar tus accesos</p>

    <?php if ($error_msj): ?>
        <div class="error-alerta"><?php echo htmlspecialchars($error_msj); ?></div>
    <?php endif; ?>

    <?php if ($exito_msj): ?>
        <div class="exito-alerta">
            <?php echo htmlspecialchars($exito_msj); ?><br><br>
            <a href="login_cliente.php" style="background:#00ffcc; color:#0b0b10; padding:8px 15px; border-radius:6px; text-decoration:none; display:inline-block; font-weight:bold;">Ir a Iniciar Sesión</a>
        </div>
    <?php else: ?>

    <form method="POST" action="registro_cliente.php">
        <div class="form-grid">
            <div class="form-group">
                <label for="nombres">Nombres:</label>
                <input type="text" id="nombres" name="nombres" class="form-control" required placeholder="Tus nombres">
            </div>
            <div class="form-group">
                <label for="apellidos">Apellidos:</label>
                <input type="text" id="apellidos" name="apellidos" class="form-control" required placeholder="Tus apellidos">
            </div>

            <div class="form-group">
                <label for="fec_nac">Fec. Nacimiento:</label>
                <input type="date" id="fec_nac" name="fec_nac" class="form-control" required>
            </div>
            <div class="form-group">
                <label for="sexo">Sexo:</label>
                <select id="sexo" name="sexo" class="form-control">
                    <option value="M">Masculino</option>
                    <option value="F">Femenino</option>
                </select>
            </div>

            <div class="form-group">
                <label for="nro_doc">Nro. DNI:</label>
                <input type="text" id="nro_doc" name="nro_doc" class="form-control" maxlength="12" required placeholder="Número de DNI">
            </div>
            <div class="form-group">
                <label for="email">Correo Electrónico:</label>
                <input type="email" id="email" name="email" class="form-control" required placeholder="correo@mail.com">
            </div>

            <div class="form-group">
                <label for="cpostal">Distrito / C. Postal:</label>
                <select id="cpostal" name="cpostal" class="form-control" required>
                    <option value="">Selecciona tu distrito</option>
                    <?php foreach ($listaDistritos as $dist): ?>
                        <option value="<?php echo htmlspecialchars(trim($dist['CPostal'])); ?>">
                            <?php echo htmlspecialchars($dist['Distrito'] . ' (' . trim($dist['CPostal']) . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="direccion">Dirección:</label>
                <input type="text" id="direccion" name="direccion" class="form-control" required placeholder="Av. / Jr. / Calle">
            </div>

            <div class="form-group">
                <label for="usuario">Nombre de Usuario:</label>
                <input type="text" id="usuario" name="usuario" class="form-control" maxlength="12" required placeholder="Máx 12 carac.">
            </div>
            <div class="form-group">
                <label for="password">Contraseña:</label>
                <input type="password" id="password" name="password" class="form-control" required placeholder="••••••••">
            </div>
        </div>

        <button type="submit" class="btn-submit">Registrarme Ahora</button>
    </form>
    <?php endif; ?>

    <div class="links-container">
        <a href="login_cliente.php">&larr; ¿Ya tienes cuenta? Inicia sesión aquí</a>
    </div>
</div>

</body>
</html>