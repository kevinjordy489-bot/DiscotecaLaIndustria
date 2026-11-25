<?php
// Forzar visualización de errores
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
$cliente_activo = $_SESSION['cliente_nombre'] ?? null;

require_once '../config/conexion.php';

$productos = [];
$error_msj = "";

try {
    // Consulta incluyendo la columna 'foto' de la base de datos
    $sql = "SELECT CodProducto, Producto, Descripcion, Prec_Venta, Stock_Marca, Estado_Producto, 
                   ISNULL(foto, 'Marcador de imagen no disponible.png') AS FotoProducto 
            FROM Tb_Producto 
            WHERE Estado_Producto = 'Activo'";
    $stmt = $conn->query($sql);
    $productos = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error_msj = "Error al consultar los productos en SQL Server: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Catálogo de Bebidas - La Industria Surco</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            background-color: #0b0b10;
            color: #f1f1f5;
            margin: 0;
            padding: 0;
        }
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
        .menu-toggle-btn {
            background: rgba(255, 0, 85, 0.1);
            border: 1px solid rgba(255, 0, 85, 0.3);
            border-radius: 8px;
            padding: 8px 12px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: background 0.2s;
        }
        .menu-toggle-btn:hover { background: rgba(255, 0, 85, 0.2); }
        .menu-toggle-btn span { color: #ff0055; font-size: 1.2rem; }

        .logo { font-weight: 800; font-size: 1.25rem; color: #ffffff; text-decoration: none; letter-spacing: 1px; }
        .logo span { color: #ff0055; }
        
        .nav-links {
            display: flex;
            gap: 20px;
            list-style: none;
            margin: 0;
            padding: 0;
            align-items: center;
        }
        .nav-links a {
            color: #b0b0c0;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.9rem;
            transition: color 0.2s;
        }
        .nav-links a:hover, .nav-links a.activo {
            color: #ff0055;
        }

        .sidebar {
            position: fixed;
            top: 0;
            left: -300px;
            width: 300px;
            height: 100%;
            background: #12121c;
            border-right: 1px solid rgba(255, 0, 85, 0.2);
            z-index: 2000;
            transition: left 0.3s ease;
            padding: 25px 20px;
            display: flex;
            flex-direction: column;
            box-shadow: 10px 0 30px rgba(0,0,0,0.8);
            overflow-y: auto;
        }
        .sidebar.activo { left: 0; }
        .sidebar-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            border-bottom: 1px solid #262638;
            padding-bottom: 15px;
        }
        .sidebar-title { font-weight: 800; font-size: 1.1rem; color: #fff; }
        .sidebar-title span { color: #ff0055; }
        .sidebar-close {
            background: none;
            border: none;
            color: #fff;
            font-size: 1.5rem;
            cursor: pointer;
        }
        .sidebar-section-title {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #777788;
            margin: 15px 0 8px 10px;
            font-weight: bold;
        }
        .sidebar-links {
            list-style: none;
            padding: 0;
            margin: 0;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .sidebar-links a {
            color: #c0c0d0;
            text-decoration: none;
            font-size: 0.95rem;
            font-weight: 600;
            display: block;
            padding: 10px 14px;
            border-radius: 8px;
            transition: all 0.2s;
        }
        .sidebar-links a:hover {
            background: rgba(255, 0, 85, 0.15);
            color: #ff0055;
            padding-left: 18px;
        }
        
        .overlay {
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.6);
            backdrop-filter: blur(3px);
            z-index: 1500;
            display: none;
        }
        .overlay.activo { display: block; }

        .nav-right { display: flex; align-items: center; gap: 15px; }
        .btn-login-top {
            background: linear-gradient(135deg, #ff0055 0%, #b3003c 100%);
            color: #ffffff; padding: 10px 22px; border-radius: 6px;
            text-decoration: none; font-weight: 600; font-size: 0.9rem;
            box-shadow: 0 4px 15px rgba(255, 0, 85, 0.3);
        }
        .user-greeting { font-size: 0.9rem; color: #ccc; }
        .user-greeting span { color: #00ffcc; font-weight: bold; }

        .container { max-width: 1200px; margin: 35px auto; padding: 0 30px; }
        .btn-volver {
            display: inline-block; color: #ff6699; text-decoration: none;
            font-weight: 600; margin-bottom: 20px; font-size: 0.95rem;
        }
        .btn-volver:hover { text-decoration: underline; }

        .hero-banner {
            background: linear-gradient(135deg, #1f1224 0%, #161622 100%);
            border: 1px solid rgba(255, 0, 85, 0.35);
            border-radius: 14px;
            padding: 30px;
            margin-bottom: 25px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.6);
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 20px;
        }
        .hero-banner h1 { margin: 0 0 8px 0; font-size: 2rem; color: #fff; }
        .hero-banner h1 span { color: #ff0055; }
        .hero-banner p { margin: 0; color: #b0b0c0; font-size: 0.95rem; }

        .search-box {
            position: relative;
            width: 100%;
            max-width: 350px;
        }
        .search-input {
            width: 100%;
            padding: 12px 18px 12px 42px;
            background: #0f0f17;
            border: 1px solid #323248;
            border-radius: 10px;
            color: #fff;
            font-size: 0.95rem;
            outline: none;
            transition: border-color 0.2s;
        }
        .search-input:focus { border-color: #ff0055; }
        .search-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #8c8c9e;
            font-size: 1rem;
        }

        .bebidas-grid {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 22px;
        }
        .bebida-card {
            background: #161622; border: 1px solid #262638; border-radius: 12px;
            overflow: hidden; display: flex; flex-direction: column; justify-content: space-between;
            transition: all 0.25s ease;
        }
        .bebida-card:hover { border-color: #ff0055; transform: translateY(-4px); }
        
        .bebida-card img {
            width: 100%;
            height: 180px;
            object-fit: cover;
            background: #0f0f17;
            border-bottom: 1px solid #262638;
        }

        .bebida-card-body {
            padding: 20px;
            display: flex;
            flex-direction: column;
            flex-grow: 1;
            justify-content: space-between;
        }

        .bebida-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px; }
        .bebida-nombre { font-size: 1.15rem; font-weight: 700; color: #fff; margin: 0; }
        .badge-cat {
            background: rgba(0, 255, 204, 0.1); color: #00ffcc; padding: 4px 10px;
            border-radius: 20px; font-size: 0.75rem; font-weight: bold; border: 1px solid rgba(0, 255, 204, 0.2);
        }
        .bebida-desc { color: #9e9eb3; font-size: 0.88rem; margin-bottom: 20px; line-height: 1.4; }
        .bebida-footer { display: flex; justify-content: space-between; align-items: center; border-top: 1px solid #222232; padding-top: 15px; }
        .bebida-precio { font-size: 1.4rem; font-weight: 800; color: #fff; }
        .bebida-precio small { font-size: 0.85rem; color: #ff0055; }
        
        .btn-pedir {
            background: #ff0055; color: #fff; padding: 10px 16px; border-radius: 8px;
            text-decoration: none; font-weight: bold; font-size: 0.88rem;
        }
        .btn-pedir:hover { background: #d60047; }

        .vacio-box, .error-box {
            background: #161622; border: 1px dashed #383852; padding: 35px;
            border-radius: 12px; text-align: center; color: #9e9eb3; grid-column: 1 / -1;
        }
        .error-box { border: 1px solid #ff0055; color: #ff80aa; background: rgba(255, 0, 85, 0.08); }
        .oculto { display: none !important; }
    </style>
</head>
<body>

<!-- Menú Lateral Desplegable (Sidebar) -->
<div class="overlay" id="overlay" onclick="toggleMenu()"></div>
<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="sidebar-title">LA INDUSTRIA <span>SURCO</span></div>
        <button class="sidebar-close" onclick="toggleMenu()">&times;</button>
    </div>
    
    <div class="sidebar-section-title">Navegación Principal</div>
    <ul class="sidebar-links">
        <li><a href="index.php">Inicio</a></li>
        <li><a href="catalogo_bebidas.php" class="activo" style="color: #ff0055;">Productos</a></li>
        <li><a href="disponibilidad_tickets.php">Tickets</a></li>
        <li><a href="informacion.php">Información</a></li>
        <li><a href="contacto.php">Contacto</a></li>
        <li><a href="nosotros.php">Nosotros</a></li>
    </ul>

    <?php if ($cliente_activo): ?>
        <div class="sidebar-section-title">Mi Cuenta y Compras</div>
        <ul class="sidebar-links">
            <li><a href="mis_tickets.php">🎟️ Mis Tickets Comprados</a></li>
            <li><a href="mis_pedidos.php">📦 Mis Pedidos de Bebidas</a></li>
        </ul>
    <?php endif; ?>

    <div class="sidebar-section-title">Sesión</div>
    <ul class="sidebar-links">
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

<!-- Barra Superior con el menú modificado -->
<nav>
    <div class="nav-left">
        <button class="menu-toggle-btn" onclick="toggleMenu()">
            <span>&#9776;</span>
        </button>
        <a href="index.php" class="logo">LA INDUSTRIA <span>SURCO</span></a>
        
        <ul class="nav-links">
            <li><a href="index.php">Inicio</a></li>
            <li><a href="catalogo_bebidas.php" class="activo" style="color: #ff0055; font-weight: bold;">Productos</a></li>
            <li><a href="disponibilidad_tickets.php">Tickets</a></li>
            <li><a href="informacion.php">Información</a></li>
            <li><a href="contacto.php">Contacto</a></li>
            <li><a href="nosotros.php">Nosotros</a></li>
        </ul>
    </div>

    <div class="nav-right">
        <?php if ($cliente_activo): ?>
            <div class="user-greeting">Hola, <span><?php echo htmlspecialchars($cliente_activo); ?></span></div>
            <a href="cerrar_sesion.php" class="btn-login-top" style="background: #252535;">Cerrar Sesión</a>
        <?php else: ?>
            <a href="login_cliente.php" class="btn-login-top">Iniciar Sesión</a>
            <a href="login_trabajador.php" style="color: #8888a0; text-decoration: none; font-size: 0.85rem; margin-left: 5px;">Trabajadores</a>
        <?php endif; ?>
    </div>
</nav>

<div class="container">
    <a href="index.php" class="btn-volver">&larr; Volver al Inicio</a>

    <div class="hero-banner">
        <div>
            <h1>Catálogo de <span>Bebidas & Cocktails</span></h1>
            <p>Explora nuestra carta de licores, tragos largos y bebidas exclusivas para boxes y barras.</p>
        </div>
        <div class="search-box">
            <span class="search-icon">🔍</span>
            <input type="text" id="buscador" class="search-input" placeholder="Buscar bebida o cóctel..." onkeyup="filtrarProductos()">
        </div>
    </div>

    <?php if ($error_msj): ?>
        <div class="error-box"><?php echo htmlspecialchars($error_msj); ?></div>
    <?php else: ?>
        <div class="bebidas-grid" id="grid-productos">
            <?php if (empty($productos)): ?>
                <div class="vacio-box">
                    No se encontraron productos activos registrados en la base de datos.
                </div>
            <?php else: ?>
                <?php foreach ($productos as $p): ?>
                    <?php 
                        $foto_prod = trim($p['FotoProducto']);
                        if (empty($foto_prod)) {
                            $foto_prod = 'Marcador de imagen no disponible.png';
                        }
                    ?>
                    <div class="bebida-card" data-nombre="<?php echo strtolower(htmlspecialchars($p['Producto'])); ?>" data-desc="<?php echo strtolower(htmlspecialchars($p['Descripcion'] ?? '')); ?>">
                        <img src="../web/wwwroot/imagenes/<?php echo htmlspecialchars($foto_prod); ?>" alt="<?php echo htmlspecialchars($p['Producto']); ?>" onerror="this.src='../web/wwwroot/imagenes/Marcador%20de%20imagen%20no%20disponible.png';">
                        
                        <div class="bebida-card-body">
                            <div>
                                <div class="bebida-header">
                                    <h3 class="bebida-nombre"><?php echo htmlspecialchars($p['Producto']); ?></h3>
                                    <span class="badge-cat">Stock: <?php echo (int)$p['Stock_Marca']; ?></span>
                                </div>
                                <p class="bebida-desc"><?php echo htmlspecialchars($p['Descripcion'] ?? 'Sin descripción adicional.'); ?></p>
                            </div>
                            <div class="bebida-footer">
                                <div class="bebida-precio">
                                    <small>S/</small> <?php echo number_format((float)$p['Prec_Venta'], 2); ?>
                                </div>
                                <a href="pedir_bebidas.php?cod_producto=<?php echo urlencode(trim($p['CodProducto'])); ?>" class="btn-pedir">
                                    Solicitar Pedido
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <div id="no-resultados" class="vacio-box oculto">
            No se encontraron productos que coincidan con tu búsqueda.
        </div>
    <?php endif; ?>
</div>

<script>
function toggleMenu() {
    let sidebar = document.getElementById('sidebar');
    let overlay = document.getElementById('overlay');
    sidebar.classList.toggle('activo');
    overlay.classList.toggle('activo');
}

function filtrarProductos() {
    let input = document.getElementById('buscador').value.toLowerCase().trim();
    let cards = document.querySelectorAll('.bebida-card');
    let contadorVisibles = 0;
    let mensajeVacio = document.getElementById('no-resultados');

    cards.forEach(card => {
        let nombre = card.getAttribute('data-nombre');
        let desc = card.getAttribute('data-desc');

        if (nombre.includes(input) || desc.includes(input)) {
            card.classList.remove('oculto');
            contadorVisibles++;
        } else {
            card.classList.add('oculto');
        }
    });

    if (contadorVisibles === 0) {
        mensajeVacio.classList.remove('oculto');
    } else {
        mensajeVacio.classList.add('oculto');
    }
}
</script>

</body>
</html>