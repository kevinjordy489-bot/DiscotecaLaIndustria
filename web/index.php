<?php
session_start();

// SEGURIDAD: Si un empleado intenta entrar al portal de clientes, lo redirigimos a su panel
if (isset($_SESSION['rol']) && $_SESSION['rol'] === 'empleado') {
    header("Location: panel_control.php");
    exit;
}

$cliente_activo = $_SESSION['cliente_nombre'] ?? null;

require_once '../config/conexion.php';

$eventos = [];
$error_bd = "";

try {
    // Consulta adaptada a los nombres exactos de columnas de la tabla Tb_Evento en SQL Server
    $sql = "SELECT NroEvento AS id_evento, Nombre_Evento AS nombre, Fec_Inicio_Evento AS fecha, Hora_Inicio AS hora, Descripcion_Evento AS descripcion FROM Tb_Evento WHERE Estado_Evento = 'Activo'";
    $stmt = $conn->query($sql);
    $eventos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error_bd = "No se pudieron cargar los eventos desde SQL Server: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>La Industria Surco - Restobar & Discoteca</title>
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
        
        /* Menú superior con 'Nosotros' al final */
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
        .container { max-width: 1200px; margin: 35px auto; padding: 0 30px; }
        .seccion-titulo {
            font-size: 1.35rem; color: #fff; margin: 35px 0 20px 0;
            border-left: 4px solid #ff0055; padding-left: 12px; font-weight: 700;
        }

        /* Tarjetas de Eventos desde SQL Server */
        .events-grid {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 25px; margin-bottom: 30px;
        }
        .event-card {
            background: #161622; border-radius: 12px; overflow: hidden;
            border: 1px solid #262638; box-shadow: 0 8px 25px rgba(0,0,0,0.5);
            transition: all 0.25s ease;
        }
        .event-card:hover { transform: translateY(-5px); border-color: #ff0055; }
        .event-header {
            background: linear-gradient(135deg, #1f1224 0%, #161622 100%);
            padding: 20px; border-bottom: 1px solid #262638;
        }
        .event-date {
            background-color: rgba(255, 0, 85, 0.15); color: #ff6699;
            border: 1px solid rgba(255, 0, 85, 0.3); display: inline-block;
            padding: 4px 10px; border-radius: 6px; font-size: 0.78rem; font-weight: bold; margin-bottom: 10px;
        }
        .event-title { font-size: 1.25rem; margin: 0; font-weight: 700; color: #fff; }
        .event-body { padding: 20px; }
        .event-info { margin-bottom: 10px; font-size: 0.92rem; color: #b0b0c0; }
        .event-info strong { color: #fff; }
        .btn-ticket {
            display: block; text-align: center; background: #ff0055; color: #fff; padding: 12px;
            border-radius: 8px; text-decoration: none; font-weight: bold; margin-top: 18px; font-size: 0.92rem;
        }
        .btn-ticket:hover { background: #d60047; }

        .vacio-box {
            background: #161622; border: 1px dashed #383852; padding: 30px;
            border-radius: 12px; text-align: center; color: #9e9eb3;
        }
        .error-box {
            background: rgba(255, 0, 85, 0.1); border: 1px solid #ff0055;
            color: #ff80aa; padding: 15px 20px; border-radius: 8px; margin-bottom: 20px;
        }

        /* Cuadrícula de Transacciones y Accesos */
        .grid {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 20px; margin-bottom: 30px;
        }
        .card {
            background: #161622; padding: 22px; border-radius: 12px; border: 1px solid #262638;
            text-decoration: none; color: #fff; transition: all 0.25s ease; display: block;
        }
        .card:hover { border-color: #ff0055; background: #1c1c2b; transform: translateY(-3px); }
        .card strong { font-size: 1.05rem; color: #fff; display: block; margin-bottom: 6px; }
        .card span { font-size: 0.88rem; color: #9e9eb3; display: block; }
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
        <li><a href="index.php" class="activo" style="color: #ff0055;">Inicio</a></li>
        <li><a href="catalogo_bebidas.php">Productos</a></li>
        <li><a href="disponibilidad_tickets.php">Tickets</a></li>
        <li><a href="informacion.php">Información</a></li>
        <li><a href="contacto.php">Contacto</a></li>
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

<!-- Barra Superior con 'Nosotros' al final -->
<nav>
    <div class="nav-left">
        <button class="menu-btn" onclick="toggleMenu()"><span></span><span></span><span></span></button>
        <a href="index.php" class="logo">LA INDUSTRIA <span>SURCO</span></a>
        <ul class="nav-links">
            <li><a href="index.php" class="activo" style="color: #ff0055; font-weight: bold;">Inicio</a></li>
            <li><a href="catalogo_bebidas.php">Productos</a></li>
            <li><a href="disponibilidad_tickets.php">Tickets</a></li>
            <li><a href="informacion.php">Información</a></li>
            <li><a href="contacto.php">Contacto</a></li>
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
    <h1>Sistema de Gestión <span>La Industria</span></h1>
    <p>Cartelera de espectáculos en vivo y servicios exclusivos del restobar.</p>
</div>

<!-- Contenido Principal -->
<div class="container">
    
    <!-- CONSULTAR EVENTOS DESDE SQL SERVER -->
    <div class="seccion-titulo" style="margin-top: 10px;">Cartelera de Eventos en Vivo</div>

    <?php if ($error_bd): ?>
        <div class="error-box"><?php echo htmlspecialchars($error_bd); ?></div>
    <?php elseif (empty($eventos)): ?>
        <div class="vacio-box">
            No hay eventos registrados actualmente en la base de datos SQL Server.
        </div>
    <?php else: ?>
        <div class="events-grid">
            <?php foreach ($eventos as $ev): ?>
                <?php
                    $id_evento   = trim($ev['id_evento'] ?? '');
                    $titulo      = $ev['nombre'] ?? 'Evento Sin Título';
                    $fecha       = $ev['fecha'] ?? 'Fecha por confirmar';
                    $hora        = $ev['hora'] ?? '';
                    $descripcion = $ev['descripcion'] ?? 'Por confirmar';
                ?>
                <div class="event-card">
                    <div class="event-header">
                        <span class="event-date">
                            <?php echo htmlspecialchars($fecha . ($hora ? " - " . $hora : "")); ?>
                        </span>
                        <h3 class="event-title"><?php echo htmlspecialchars($titulo); ?></h3>
                    </div>
                    <div class="event-body">
                        <div class="event-info"><strong>Descripción:</strong> <?php echo htmlspecialchars($descripcion); ?></div>
                        <a href="disponibilidad_tickets.php?id_evento=<?php echo urlencode($id_evento); ?>" class="btn-ticket">Comprar Tickets</a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- ZONA DE TRANSACCIONES Y ACCESOS RÁPIDOS -->
    <div class="seccion-titulo">Accesos Rápidos & Transacciones</div>
    <div class="grid">
        <a href="disponibilidad_tickets.php" class="card">
            <strong>Disponibilidad de Tickets</strong>
            <span>Consulta los eventos activos y zonas disponibles.</span>
        </a>
        <a href="catalogo_bebidas.php" class="card">
            <strong>Catálogo de Bebidas</strong>
            <span>Explora nuestra carta de licores y cócteles.</span>
        </a>
        <a href="mis_tickets.php" class="card">
            <strong>Consultar Mis Tickets</strong>
            <span>Visualiza el historial y comprobantes de tus entradas.</span>
        </a>
        <a href="mis_pedidos.php" class="card">
            <strong>Mis Pedidos y Solicitudes</strong>
            <span>Consulta el estado de tus consumos y bebidas.</span>
        </a>
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