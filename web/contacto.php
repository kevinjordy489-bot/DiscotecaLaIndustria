<?php
session_start();

// SEGURIDAD: Si un empleado intenta entrar al portal de clientes, lo redirigimos a su panel
if (isset($_SESSION['rol']) && $_SESSION['rol'] === 'empleado') {
    header("Location: panel_control.php");
    exit;
}

$cliente_activo = $_SESSION['cliente_nombre'] ?? null;
$exito_msj = "";
$error_msj = "";

// Procesar formulario de contacto si es enviado
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enviar_contacto'])) {
    $nombre_contacto  = trim($_POST['nombre'] ?? '');
    $correo_contacto  = trim($_POST['correo'] ?? '');
    $asunto_contacto  = trim($_POST['asunto'] ?? '');
    $mensaje_contacto = trim($_POST['mensaje'] ?? '');

    if (empty($nombre_contacto) || empty($correo_contacto) || empty($mensaje_contacto)) {
        $error_msj = "Por favor, completa todos los campos obligatorios.";
    } elseif (!filter_var($correo_contacto, FILTER_VALIDATE_EMAIL)) {
        $error_msj = "El correo electrónico ingresado no es válido.";
    } else {
        // Aquí puedes agregar lógica adicional como enviar un correo o guardar en base de datos si lo deseas.
        $exito_msj = "¡Gracias por contactarnos, <strong>$nombre_contacto</strong>! Tu mensaje ha sido enviado correctamente. Te responderemos a la brevedad.";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contacto - La Industria Surco</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            background-color: #0b0b10;
            color: #f1f1f5;
            margin: 0;
            padding: 0;
        }
        
        /* Barra de navegación superior */
        nav {
            background: rgba(16, 16, 24, 0.95);
            backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(255, 0, 85, 0.2);
            padding: 16px 40px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 1000;
        }
        .nav-left { display: flex; align-items: center; gap: 20px; }
        .menu-btn {
            background: rgba(255, 0, 85, 0.1);
            border: 1px solid rgba(255, 0, 85, 0.3);
            cursor: pointer;
            display: flex;
            flex-direction: column;
            gap: 5px;
            padding: 10px;
            border-radius: 8px;
            transition: background 0.2s;
        }
        .menu-btn:hover { background: rgba(255, 0, 85, 0.2); }
        .menu-btn span { display: block; width: 20px; height: 2px; background-color: #ff0055; }
        
        .logo { font-weight: 800; font-size: 1.25rem; color: #ffffff; text-decoration: none; letter-spacing: 1px; }
        .logo span { color: #ff0055; }
        
        .nav-links { display: flex; gap: 20px; list-style: none; margin: 0; padding: 0; align-items: center; }
        .nav-links a { text-decoration: none; color: #b0b0c0; font-size: 0.9rem; font-weight: 600; transition: color 0.2s; }
        .nav-links a:hover, .nav-links a.activo { color: #ff0055; }

        .nav-right { display: flex; align-items: center; gap: 15px; }
        .btn-login-top {
            background: linear-gradient(135deg, #ff0055 0%, #b3003c 100%);
            color: #ffffff; padding: 10px 22px; border-radius: 6px;
            text-decoration: none; font-weight: 600; font-size: 0.9rem;
            box-shadow: 0 4px 15px rgba(255, 0, 85, 0.3);
        }
        .user-greeting { font-size: 0.9rem; color: #ccc; }
        .user-greeting span { color: #00ffcc; font-weight: bold; }

        /* Menú Lateral (Sidebar) */
        .sidebar {
            position: fixed; top: 0; left: -300px; width: 300px; height: 100%;
            background-color: #12121c; border-right: 1px solid rgba(255, 0, 85, 0.2);
            box-shadow: 10px 0 30px rgba(0,0,0,0.8); z-index: 2000;
            transition: left 0.3s ease; padding: 25px 20px; overflow-y: auto;
            display: flex; flex-direction: column;
        }
        .sidebar.active { left: 0; }
        .sidebar-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #262638; padding-bottom: 15px; margin-bottom: 25px; }
        .sidebar-title { font-weight: 800; font-size: 1.1rem; color: #fff; }
        .sidebar-title span { color: #ff0055; }
        .close-btn { background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #fff; }
        
        .sidebar-section-title {
            font-size: 0.75rem; text-transform: uppercase; letter-spacing: 1px;
            color: #777788; margin: 15px 0 8px 10px; font-weight: bold;
        }
        .sidebar-menu { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 8px; }
        .sidebar-menu a {
            text-decoration: none; color: #c0c0d0; font-size: 0.95rem; font-weight: 600;
            display: block; padding: 10px 14px; border-radius: 8px; transition: all 0.2s;
        }
        .sidebar-menu a:hover { background: rgba(255, 0, 85, 0.15); color: #ff0055; padding-left: 18px; }

        .overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); backdrop-filter: blur(3px); z-index: 1500; display: none; }
        .overlay.active { display: block; }

        /* Banner Principal */
        .hero-banner {
            background: linear-gradient(135deg, #14141f 0%, #1f1224 100%);
            padding: 45px 40px; border-bottom: 2px solid rgba(255, 0, 85, 0.3);
        }
        .hero-banner h1 { font-size: 2.4rem; margin: 0 0 10px 0; color: #fff; }
        .hero-banner h1 span { color: #ff0055; }
        .hero-banner p { font-size: 1.05rem; color: #9e9eb3; margin: 0; }

        /* Contenedor Principal */
        .container { max-width: 1100px; margin: 40px auto; padding: 0 30px; }
        
        .contacto-grid {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 30px; margin-bottom: 30px;
        }
        
        .contacto-card {
            background: #161622; border: 1px solid #262638; border-radius: 14px;
            padding: 35px; box-shadow: 0 10px 30px rgba(0,0,0,0.5);
        }
        .contacto-card h2 { color: #fff; font-size: 1.5rem; margin-top: 0; border-bottom: 1px solid #262638; padding-bottom: 12px; }
        .contacto-card h2 span { color: #ff0055; }
        
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; color: #ccc; font-weight: 600; font-size: 0.92rem; }
        .form-control { width: 100%; padding: 12px 15px; background: #0f0f17; border: 1px solid #323248; border-radius: 8px; color: #fff; font-size: 1rem; outline: none; }
        .form-control:focus { border-color: #ff0055; }
        
        .btn-enviar {
            width: 100%; padding: 14px; border: none; border-radius: 8px;
            background: linear-gradient(135deg, #ff0055 0%, #b3003c 100%);
            color: #fff; font-size: 1rem; font-weight: bold; cursor: pointer; text-align: center;
        }
        .btn-enviar:hover { opacity: 0.9; }

        .info-detalle { margin-bottom: 20px; font-size: 0.95rem; color: #b0b0c0; line-height: 1.6; }
        .info-detalle strong { color: #fff; display: block; margin-bottom: 4px; font-size: 1.05rem; }

        .alerta-error { background: rgba(255, 0, 85, 0.12); border: 1px solid #ff0055; color: #ff80aa; padding: 15px; border-radius: 8px; margin-bottom: 20px; }
        .alerta-exito { background: rgba(0, 255, 204, 0.1); border: 1px solid #00ffcc; color: #00ffcc; padding: 15px; border-radius: 8px; margin-bottom: 20px; }

        .btn-volver { display: inline-block; color: #ff6699; text-decoration: none; font-weight: 600; margin-bottom: 20px; }
        .btn-volver:hover { text-decoration: underline; }
    </style>
</head>
<body>

<!-- Menú Lateral (Sidebar) ordenado -->
<div class="overlay" id="overlay" onclick="toggleMenu()"></div>
<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="sidebar-title">LA INDUSTRIA <span>SURCO</span></div>
        <button class="close-btn" onclick="toggleMenu()">&times;</button>
    </div>
    
    <div class="sidebar-section-title">Navegación Principal</div>
    <ul class="sidebar-menu">
        <li><a href="index.php">Inicio</a></li>
        <li><a href="catalogo_bebidas.php">Productos</a></li>
        <li><a href="disponibilidad_tickets.php">Tickets</a></li>
        <li><a href="informacion.php">Información</a></li>
        <li><a href="contacto.php" class="activo" style="color: #ff0055;">Contacto</a></li>
        <li><a href="nosotros.php">Nosotros</a></li>
    </ul>

    <?php if ($cliente_activo): ?>
        <div class="sidebar-section-title">Mi Cuenta y Compras</div>
        <ul class="sidebar-menu">
            <li><a href="mis_tickets.php">🎟️ Mis Tickets Comprados</a></li>
            <li><a href="mis_pedidos.php">📦 Mis Pedidos de Bebidas</a></li>
        </ul>
    <?php endif; ?>

    <div class="sidebar-section-title">Sesión</div>
    <ul class="sidebar-menu">
        <?php if ($cliente_activo): ?>
            <li><a href="cerrar_sesion.php" style="color: #ff6699;">🚪 Cerrar Sesión</a></li>
        <?php else: ?>
            <li><a href="login_cliente.php">👤 Iniciar Sesión (Cliente)</a></li>
            <li><a href="registro_cliente.php">📝 Registrarse como Cliente</a></li>
            <li style="margin-top: 15px; border-top: 1px solid #262638; padding-top: 10px;">
                <a href="login_trabajador.php" style="color: #8888a0; font-size: 0.85rem;">🔒 Acceso Trabajadores</a>
            </li>
        <?php endif; ?>
    </ul>
</div>

<!-- Barra Superior ordenada -->
<nav>
    <div class="nav-left">
        <button class="menu-btn" onclick="toggleMenu()"><span></span><span></span><span></span></button>
        <a href="index.php" class="logo">LA INDUSTRIA <span>SURCO</span></a>
        <ul class="nav-links">
            <li><a href="index.php">Inicio</a></li>
            <li><a href="catalogo_bebidas.php">Productos</a></li>
            <li><a href="disponibilidad_tickets.php">Tickets</a></li>
            <li><a href="informacion.php">Información</a></li>
            <li><a href="contacto.php" class="activo" style="color: #ff0055; font-weight: bold;">Contacto</a></li>
            <li><a href="nosotros.php">Nosotros</a></li>
        </ul>
    </div>
    <div class="nav-right">
        <?php if ($cliente_activo): ?>
            <div class="user-greeting">Hola, <span><?php echo htmlspecialchars($cliente_activo); ?></span></div>
            <a href="cerrar_sesion.php" class="btn-login-top" style="background: #252535; box-shadow: none;">Cerrar Sesión</a>
        <?php else: ?>
            <a href="login_cliente.php" class="btn-login-top">Iniciar Sesión</a>
            <a href="login_trabajador.php" style="color: #8888a0; text-decoration: none; font-size: 0.85rem; margin-left: 5px;">Trabajadores</a>
        <?php endif; ?>
    </div>
</nav>

<!-- Banner -->
<div class="hero-banner">
    <h1>Ponte en <span>Contacto</span></h1>
    <p>¿Tienes dudas sobre reservas, eventos corporativos o tus entradas? Escríbenos y te responderemos pronto.</p>
</div>

<!-- Contenido Principal -->
<div class="container">
    <a href="index.php" class="btn-volver">&larr; Volver al Inicio</a>

    <?php if ($error_msj): ?>
        <div class="alerta-error"><?php echo htmlspecialchars($error_msj); ?></div>
    <?php endif; ?>

    <?php if ($exito_msj): ?>
        <div class="alerta-exito"><?php echo $exito_msj; ?></div>
    <?php endif; ?>

    <div class="contacto-grid">
        <!-- Formulario de Contacto -->
        <div class="contacto-card">
            <h2>Envíanos un <span>Mensaje</span></h2>
            <form method="POST" action="contacto.php">
                <div class="form-group">
                    <label>Tu Nombre:</label>
                    <input type="text" name="nombre" class="form-control" placeholder="Ej. Juan Pérez" required>
                </div>
                <div class="form-group">
                    <label>Correo Electrónico:</label>
                    <input type="email" name="correo" class="form-control" placeholder="correo@ejemplo.com" required>
                </div>
                <div class="form-group">
                    <label>Asunto:</label>
                    <input type="text" name="asunto" class="form-control" placeholder="Ej. Consulta sobre Box o Mesa">
                </div>
                <div class="form-group">
                    <label>Mensaje:</label>
                    <textarea name="mensaje" class="form-control" rows="4" placeholder="Escribe tu consulta aquí..." required></textarea>
                </div>
                <button type="submit" name="enviar_contacto" value="1" class="btn-enviar">Enviar Mensaje</button>
            </form>
        </div>

        <!-- Información de Ubicación y Canales -->
        <div class="contacto-card">
            <h2>Información de <span>Contacto</span></h2>
            
            <div class="info-detalle">
                <strong>📍 Ubicación / Sede:</strong>
                Santiago de Surco, Lima - Perú (Sede Principal: SED01).
            </div>
            
            <div class="info-detalle">
                <strong>📞 Teléfono / WhatsApp:</strong>
                +51 999 888 777
            </div>
            
            <div class="info-detalle">
                <strong>📧 Correo Electrónico:</strong>
                contacto@laindustriasurco.com
            </div>

            <div class="info-detalle">
                <strong>⏰ Horario de Atención (Soporte):</strong>
                Lunes a Sábado de 10:00 AM a 8:00 PM.
            </div>
        </div>
    </div>
</div>

<script>
    function toggleMenu() {
        document.getElementById('sidebar').classList.toggle('active');
        document.getElementById('overlay').classList.toggle('active');
    }
</script>

</body>
</html>