<?php
session_start();

// Validar que el cliente haya iniciado sesión
if (!isset($_SESSION['cliente_id'])) {
    header("Location: login_cliente.php");
    exit;
}

$cliente_id = $_SESSION['cliente_id'];
$cliente_nombre = $_SESSION['cliente_nombre'] ?? 'Cliente';

require_once '../config/conexion.php';

$pedidos = [];
$error_bd = "";

try {
    // Consulta SQL exacta utilizando el ID del cliente logueado y las tablas reales
    $sql = "SELECT 
                ped.NroPedido AS nro_pedido,
                p.Producto AS nombre_producto,
                dp.Prec_Venta AS precio_unitario,
                dp.Cantidad AS cantidad,
                dp.Importe AS importe_total,
                ped.Estado_Pedido AS estado
            FROM Tb_Pedido ped
            INNER JOIN Tb_Detalle_Pedido dp ON ped.NroPedido = dp.NroPedido
            INNER JOIN Tb_Producto p ON dp.CodProducto = p.CodProducto
            WHERE ped.CodCliente = :cliente_id
            ORDER BY ped.NroPedido DESC";
            
    $stmt = $conn->prepare($sql);
    $stmt->execute(['cliente_id' => $cliente_id]);
    $pedidos = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error_bd = "No se pudieron consultar los pedidos desde SQL Server: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mis Pedidos - La Industria Surco</title>
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
            gap: 22px;
            list-style: none;
            margin: 0;
            padding: 0;
            align-items: center;
        }
        .nav-links a {
            color: #b0b0c0;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.92rem;
            transition: color 0.2s;
        }
        .nav-links a:hover, .nav-links a.activo {
            color: #ff0055;
        }

        /* Menú lateral desplegable (Sidebar) */
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
        .sidebar.activo {
            left: 0;
        }
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
        .btn-logout {
            background: #252535; color: #ffffff; padding: 8px 16px; border-radius: 6px;
            text-decoration: none; font-weight: 600; font-size: 0.85rem;
        }
        .btn-logout:hover { background: #ff0055; }
        .user-greeting { font-size: 0.9rem; color: #ccc; }
        .user-greeting span { color: #00ffcc; font-weight: bold; }

        .container { max-width: 1100px; margin: 40px auto; padding: 0 30px; }
        .seccion-titulo {
            font-size: 1.5rem; color: #fff; margin-bottom: 25px;
            border-left: 4px solid #ff0055; padding-left: 12px; font-weight: 700;
        }

        .table-container {
            background: #161622; border-radius: 12px; border: 1px solid #262638;
            overflow: hidden; box-shadow: 0 8px 25px rgba(0,0,0,0.5);
        }
        table { width: 100%; border-collapse: collapse; text-align: left; }
        th { background: #1f1224; color: #fff; padding: 15px 20px; font-size: 0.9rem; font-weight: 600; border-bottom: 1px solid #262638; }
        td { padding: 15px 20px; color: #b0b0c0; font-size: 0.92rem; border-bottom: 1px solid #1f1224; vertical-align: middle; }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background: rgba(255, 0, 85, 0.03); color: #fff; }

        .badge-estado {
            background: rgba(0, 255, 204, 0.1); color: #00ffcc;
            border: 1px solid rgba(0, 255, 204, 0.3); padding: 4px 10px;
            border-radius: 6px; font-size: 0.78rem; font-weight: bold; display: inline-block;
        }

        .vacio-box {
            background: #161622; border: 1px dashed #383852; padding: 40px;
            border-radius: 12px; text-align: center; color: #9e9eb3;
        }
        .vacio-box a { color: #ff0055; text-decoration: none; font-weight: bold; }
        .vacio-box a:hover { text-decoration: underline; }

        .error-box {
            background: rgba(255, 0, 85, 0.1); border: 1px solid #ff0055;
            color: #ff80aa; padding: 15px 20px; border-radius: 8px; margin-bottom: 20px;
        }
        
        .btn-volver {
            display: inline-block; margin-top: 20px; color: #b0b0c0; text-decoration: none; font-size: 0.9rem;
        }
        .btn-volver:hover { color: #fff; }
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
        <li><a href="index.php">🏠 Inicio / Cartelera</a></li>
        <li><a href="disponibilidad_tickets.php">🎟️ Disponibilidad de Tickets</a></li>
        <li><a href="catalogo_bebidas.php">🍹 Catálogo de Bebidas</a></li>
    </ul>

    <div class="sidebar-section-title">Mi Cuenta y Compras</div>
    <ul class="sidebar-links">
        <li><a href="mis_tickets.php">🎟️ Mis Tickets Comprados</a></li>
        <li><a href="mis_pedidos.php">📦 Mis Pedidos de Bebidas</a></li>
    </ul>

    <div class="sidebar-section-title">Sesión</div>
    <ul class="sidebar-links">
        <li><a href="cerrar_sesion.php" style="color: #ff6699;">🚪 Cerrar Sesión</a></li>
    </ul>
</div>

<nav>
    <div class="nav-left">
        <button class="menu-toggle-btn" onclick="toggleMenu()">
            <span>&#9776;</span>
        </button>
        <a href="index.php" class="logo">LA INDUSTRIA <span>SURCO</span></a>
        
        <ul class="nav-links">
            <li><a href="index.php">Inicio</a></li>
            <li><a href="disponibilidad_tickets.php">Tickets</a></li>
            <li><a href="catalogo_bebidas.php">Bebidas</a></li>
            <li><a href="mis_pedidos.php" class="activo" style="color: #ff0055; font-weight: bold;">Mis Pedidos</a></li>
            <li><a href="mis_tickets.php">Mis Tickets</a></li>
        </ul>
    </div>

    <div class="nav-right">
        <div class="user-greeting">Hola, <span><?php echo htmlspecialchars($cliente_nombre); ?></span></div>
        <a href="cerrar_sesion.php" class="btn-logout">Cerrar Sesión</a>
    </div>
</nav>

<div class="container">
    <div class="seccion-titulo">Estado de Mis Pedidos y Consumos</div>

    <?php if ($error_bd): ?>
        <div class="error-box"><?php echo htmlspecialchars($error_bd); ?></div>
    <?php elseif (empty($pedidos)): ?>
        <div class="vacio-box">
            Aún no tienes pedidos de bebidas registrados en tu cuenta.<br><br>
            <a href="catalogo_bebidas.php">&larr; Ir al Catálogo de Bebidas</a>
        </div>
    <?php else: ?>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Nro. Pedido</th>
                        <th>Producto / Bebida</th>
                        <th>Cant.</th>
                        <th>Precio Unit.</th>
                        <th>Importe Total</th>
                        <th>Estado Actual</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($pedidos as $p): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($p['nro_pedido']); ?></strong></td>
                            <td><?php echo htmlspecialchars($p['nombre_producto']); ?></td>
                            <td><?php echo htmlspecialchars($p['cantidad']); ?></td>
                            <td>S/. <?php echo number_format((float)$p['precio_unitario'], 2); ?></td>
                            <td><strong>S/. <?php echo number_format((float)$p['importe_total'], 2); ?></strong></td>
                            <td><span class="badge-estado"><?php echo htmlspecialchars($p['estado']); ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <a href="catalogo_bebidas.php" class="btn-volver">&larr; Volver al Catálogo de Bebidas</a>
</div>

<script>
function toggleMenu() {
    let sidebar = document.getElementById('sidebar');
    let overlay = document.getElementById('overlay');
    sidebar.classList.toggle('activo');
    overlay.classList.toggle('activo');
}
</script>

</body>
</html>