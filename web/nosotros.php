<?php
session_start();

// SEGURIDAD: Si un empleado intenta entrar al portal de clientes, lo redirigimos a su panel
if (isset($_SESSION['rol']) && $_SESSION['rol'] === 'empleado') {
    header("Location: panel_control.php");
    exit;
}

$cliente_activo = $_SESSION['cliente_nombre'] ?? null;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nosotros - La Industria Surco</title>
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

        /* Contenido Principal */
        .container { max-width: 1000px; margin: 40px auto; padding: 0 30px; }
        
        .about-card {
            background: #161622; border: 1px solid #262638; border-radius: 14px;
            padding: 35px; margin-bottom: 30px; box-shadow: 0 10px 30px rgba(0,0,0,0.5);
        }
        .about-card h2 { color: #fff; font-size: 1.6rem; margin-top: 0; border-bottom: 1px solid #262638; padding-bottom: 12px; }
        .about-card h2 span { color: #ff0055; }
        .about-card p { color: #b0b0c0; line-height: 1.7; font-size: 1rem; margin-bottom: 15px; }

        .values-grid {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 20px; margin-top: 25px;
        }
        .value-box {
            background: #0f0f17; border: 1px solid #323248; border-radius: 10px; padding: 20px;
        }
        .value-box h4 { color: #00ffcc; margin: 0 0 10px 0; font-size: 1.1rem; }
        .value-box p { color: #9e9eb3; font-size: 0.9rem; margin: 0; line-height: 1.5; }

        .btn-volver { display: inline-block; color: #ff6699; text-decoration: none; font-weight: 600; margin-bottom: 20px; }
        .btn-volver:hover { text-decoration: underline; }
    </style>
</head>
<body>

<!-- Menú Lateral (Sidebar) -->
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
        <li><a href="contacto.php">Contacto</a></li>
        <li><a href="nosotros.php" class="activo" style="color: #ff0055;">Nosotros</a></li>
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

<!-- Barra Superior -->
<nav>
    <div class="nav-left">
        <button class="menu-btn" onclick="toggleMenu()"><span></span><span></span><span></span></button>
        <a href="index.php" class="logo">LA INDUSTRIA <span>SURCO</span></a>
        <ul class="nav-links">
            <li><a href="index.php">Inicio</a></li>
            <li><a href="catalogo_bebidas.php">Productos</a></li>
            <li><a href="disponibilidad_tickets.php">Tickets</a></li>
            <li><a href="informacion.php">Información</a></li>
            <li><a href="contacto.php">Contacto</a></li>
            <li><a href="nosotros.php" class="activo" style="color: #ff0055; font-weight: bold;">Nosotros</a></li>
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
    <h1>Sobre <span>Nosotros</span></h1>
    <p>Conoce la historia, visión y el equipo detrás de la mejor experiencia nocturna en Surco.</p>
</div>

<!-- Contenido Principal -->
<div class="container">
    <a href="index.php" class="btn-volver">&larr; Volver al Inicio</a>

    <div class="about-card">
        <h2>Nuestra <span>Historia</span></h2>
        <p><strong>La Industria Surco</strong> nació con el firme propósito de revolucionar la escena del entretenimiento nocturno y el restobar en la zona. Nos consolidamos como un punto de encuentro referente para quienes buscan pasar momentos inolvidables en un ambiente seguro, moderno y exclusivo.</p>
        <p>A lo largo de los años, hemos evolucionado integrando tecnología de vanguardia para la adquisición de entradas y el consumo en barras y boxes, sin perder la calidez y el servicio personalizado que nos caracteriza.</p>
    </div>

    <div class="about-card">
        <h2>Misión & <span>Visión</span></h2>
        <div class="values-grid">
            <div class="value-box">
                <h4>🎯 Nuestra Misión</h4>
                <p>Brindar experiencias de entretenimiento y gastronomía de alta calidad, garantizando diversión, seguridad y comodidad a cada uno de nuestros visitantes.</p>
            </div>
            <div class="value-box">
                <h4>🚀 Nuestra Visión</h4>
                <p>Ser reconocidos como el restobar y discoteca líder a nivel metropolitano, destacando por la innovación constante en nuestros eventos y servicios digitales.</p>
            </div>
        </div>
    </div>

    <div class="about-card">
        <h2>Nuestros <span>Valores</span></h2>
        <div class="values-grid">
            <div class="value-box">
                <h4>✨ Excelencia</h4>
                <p>Cuidamos cada detalle en la coctelería, la organización de eventos y la atención al cliente.</p>
            </div>
            <div class="value-box">
                <h4>🛡️ Seguridad</h4>
                <p>Implementamos estrictos estándares y aforos controlados para que disfrutes con total tranquilidad.</p>
            </div>
            <div class="value-box">
                <h4>💡 Innovación</h4>
                <p>Facilitamos reservas, compras de tickets y pedidos mediante plataformas digitales interactivas.</p>
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