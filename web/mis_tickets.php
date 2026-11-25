<?php
session_start();

// Validar que el cliente haya iniciado sesión. Si no, redirigir al login.
if (!isset($_SESSION['cliente_id'])) {
    header("Location: login_cliente.php");
    exit;
}

$cliente_id = $_SESSION['cliente_id'];
$cliente_nombre = $_SESSION['cliente_nombre'] ?? 'Cliente';

require_once '../config/conexion.php';

$tickets = [];
$error_bd = "";

try {
    // Consulta de las ventas / tickets del cliente
    $sql = "SELECT v.NroVenta_Ticket AS id_venta,
                   'TK-' + CAST(v.NroVenta_Ticket AS VARCHAR) AS nro_ticket, 
                   'Entrada General - La Industria Surco' AS evento, 
                   v.Fec_Venta AS fecha_compra, 
                   v.NroTickets_Vendidos AS cantidad, 
                   v.Total_Pagar AS total, 
                   'Activo' AS estado
            FROM Tb_Venta_Ticket v
            WHERE v.CodCliente = ?
            ORDER BY v.Fec_Venta DESC";
            
    $stmt = $conn->prepare($sql);
    $stmt->execute([$cliente_id]);
    $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error_bd = "No se pudieron cargar tus tickets desde SQL Server: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mis Comprobantes y Tickets - La Industria Surco</title>
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

        /* Contenedor Principal */
        .container { max-width: 1100px; margin: 40px auto; padding: 0 30px; }
        .seccion-titulo {
            font-size: 1.5rem; color: #fff; margin-bottom: 25px;
            border-left: 4px solid #ff0055; padding-left: 12px; font-weight: 700;
        }

        /* Tabla de Tickets */
        .table-container {
            background: #161622; border-radius: 12px; border: 1px solid #262638;
            overflow: hidden; box-shadow: 0 8px 25px rgba(0,0,0,0.5);
        }
        table { width: 100%; border-collapse: collapse; text-align: left; }
        th { background: #1f1224; color: #fff; padding: 15px 20px; font-size: 0.9rem; font-weight: 600; border-bottom: 1px solid #262638; }
        td { padding: 15px 20px; color: #b0b0c0; font-size: 0.92rem; border-bottom: 1px solid #1f1224; vertical-align: middle; }
        tr:last-child td { border-bottom: none; }
        tr:hover td { background: rgba(255, 0, 85, 0.03); color: #fff; }

        .badge-activo {
            background: rgba(0, 255, 204, 0.1); color: #00ffcc;
            border: 1px solid rgba(0, 255, 204, 0.3); padding: 4px 10px;
            border-radius: 6px; font-size: 0.78rem; font-weight: bold; display: inline-block;
        }

        .btn-comprobante {
            background: rgba(0, 255, 204, 0.15); color: #00ffcc;
            border: 1px solid rgba(0, 255, 204, 0.4); padding: 6px 14px;
            border-radius: 6px; font-size: 0.82rem; font-weight: bold;
            cursor: pointer; transition: all 0.2s ease; text-decoration: none; display: inline-block;
        }
        .btn-comprobante:hover {
            background: #00ffcc; color: #0b0b10;
        }

        /* Modal / Ventana Emergente de Comprobante */
        .modal-overlay {
            display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0, 0, 0, 0.75); backdrop-filter: blur(5px);
            justify-content: center; align-items: center; z-index: 2000;
        }
        .modal-content {
            background: #161622; border: 1px solid #383852; border-radius: 14px;
            width: 100%; max-width: 500px; padding: 30px; box-shadow: 0 15px 35px rgba(0,0,0,0.7);
            position: relative; animation: zoomIn 0.25s ease;
        }
        @keyframes zoomIn { from { transform: scale(0.95); opacity: 0; } to { transform: scale(1); opacity: 1; } }
        
        .comprobante-header { text-align: center; border-bottom: 2px dashed #2e2e42; padding-bottom: 15px; margin-bottom: 20px; }
        .comprobante-header h2 { margin: 0; color: #fff; font-size: 1.3rem; }
        .comprobante-header span { color: #ff0055; font-size: 0.85rem; font-weight: bold; letter-spacing: 1px; }

        .comprobante-body { margin-bottom: 25px; }
        .comprobante-row { display: flex; justify-content: space-between; margin-bottom: 10px; font-size: 0.95rem; }
        .comprobante-row span.label { color: #9e9eb3; }
        .comprobante-row span.value { color: #fff; font-weight: 600; }

        .comprobante-total {
            background: #101018; border: 1px solid rgba(0, 255, 204, 0.2);
            border-radius: 8px; padding: 12px 18px; display: flex; justify-content: space-between; align-items: center;
            margin-top: 15px;
        }
        .comprobante-total span { font-size: 1rem; color: #b0b0c0; }
        .comprobante-total strong { font-size: 1.4rem; color: #00ffcc; }

        .modal-acciones { display: flex; gap: 10px; }
        .btn-imprimir {
            flex: 1; background: #252538; color: #fff; border: none; padding: 12px;
            border-radius: 8px; font-weight: bold; cursor: pointer; text-align: center;
        }
        .btn-imprimir:hover { background: #35354f; }
        .btn-cerrar-modal {
            flex: 1; background: #ff0055; color: #fff; border: none; padding: 12px;
            border-radius: 8px; font-weight: bold; cursor: pointer; text-align: center;
        }
        .btn-cerrar-modal:hover { background: #e0004c; }

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

    <?php if ($cliente_id): ?>
        <div class="sidebar-section-title">Mi Cuenta y Compras</div>
        <ul class="sidebar-links">
            <li><a href="mis_tickets.php" class="activo" style="color: #ff0055;">🎟️ Mis Tickets Comprados</a></li>
            <li><a href="mis_pedidos.php">📦 Mis Pedidos de Bebidas</a></li>
        </ul>
    <?php endif; ?>

    <div class="sidebar-section-title">Sesión</div>
    <ul class="sidebar-links">
        <li><a href="logout.php" style="color: #ff6699;">🚪 Cerrar Sesión</a></li>
    </ul>
</div>

<!-- Barra Superior unificada -->
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
            <li><a href="mis_pedidos.php">Mis Pedidos</a></li>
            <li><a href="mis_tickets.php" class="activo" style="color: #ff0055; font-weight: bold;">Mis Tickets</a></li>
        </ul>
    </div>
    <div class="nav-right">
        <div class="user-greeting">Hola, <span><?php echo htmlspecialchars($cliente_nombre); ?></span></div>
        <a href="logout.php" class="btn-logout">Cerrar Sesión</a>
    </div>
</nav>

<!-- Contenido Principal -->
<div class="container">
    
    <div class="seccion-titulo">Mis Comprobantes y Entradas</div>

    <?php if ($error_bd): ?>
        <div class="error-box"><?php echo htmlspecialchars($error_bd); ?></div>
    <?php elseif (empty($tickets)): ?>
        <div class="vacio-box">
            Aún no cuentas con tickets ni comprobantes registrados en el sistema.<br><br>
            <a href="index.php">&larr; Ver cartelera de eventos y adquirir entradas</a>
        </div>
    <?php else: ?>
        <div class="table-container">
            <table>
                <thead>
                    <tr>
                        <th>Nro. Ticket</th>
                        <th>Concepto / Evento</th>
                        <th>Fecha de Compra</th>
                        <th>Cant.</th>
                        <th>Total (S/.)</th>
                        <th>Estado</th>
                        <th>Comprobante</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tickets as $t): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($t['nro_ticket']); ?></strong></td>
                            <td><?php echo htmlspecialchars($t['evento']); ?></td>
                            <td><?php echo htmlspecialchars($t['fecha_compra']); ?></td>
                            <td><?php echo htmlspecialchars($t['cantidad']); ?></td>
                            <td>S/. <?php echo number_format((float)$t['total'], 2); ?></td>
                            <td><span class="badge-activo"><?php echo htmlspecialchars($t['estado']); ?></span></td>
                            <td>
                                <button type="button" class="btn-comprobante" onclick="verComprobante('<?php echo htmlspecialchars($t['nro_ticket']); ?>', '<?php echo htmlspecialchars($t['evento']); ?>', '<?php echo htmlspecialchars($t['fecha_compra']); ?>', '<?php echo htmlspecialchars($t['cantidad']); ?>', '<?php echo number_format((float)$t['total'], 2); ?>', '<?php echo htmlspecialchars($cliente_nombre); ?>')">
                                    Ver Comprobante
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

    <a href="index.php" class="btn-volver">&larr; Volver al panel principal</a>

</div>

<!-- VENTANA MODAL DEL COMPROBANTE -->
<div id="modalComprobante" class="modal-overlay">
    <div class="modal-content">
        <div class="comprobante-header">
            <span>COMPROBANTE ELECTRÓNICO</span>
            <h2>LA INDUSTRIA SURCO</h2>
            <p style="margin: 5px 0 0 0; font-size: 0.8rem; color: #8e8eab;">RUC: 20601234567 | Av. Surco 123, Lima</p>
        </div>
        
        <div class="comprobante-body">
            <div class="comprobante-row">
                <span class="label">Nro. de Ticket:</span>
                <span class="value" id="m_ticket">TK-000</span>
            </div>
            <div class="comprobante-row">
                <span class="label">Cliente:</span>
                <span class="value" id="m_cliente">Nombre</span>
            </div>
            <div class="comprobante-row">
                <span class="label">Concepto / Evento:</span>
                <span class="value" id="m_evento">Entrada General</span>
            </div>
            <div class="comprobante-row">
                <span class="label">Fecha de Emisión:</span>
                <span class="value" id="m_fecha">2026-11-22</span>
            </div>
            <div class="comprobante-row">
                <span class="label">Cantidad de Entradas:</span>
                <span class="value" id="m_cantidad">1</span>
            </div>

            <div class="comprobante-total">
                <span>Total Cancelado:</span>
                <strong id="m_total">S/. 0.00</strong>
            </div>
        </div>

        <div class="modal-acciones">
            <button type="button" class="btn-imprimir" onclick="window.print()">Imprimir</button>
            <button type="button" class="btn-cerrar-modal" onclick="cerrarModal()">Cerrar</button>
        </div>
    </div>
</div>

<script>
    function toggleMenu() {
        let sidebar = document.getElementById('sidebar');
        let overlay = document.getElementById('overlay');
        sidebar.classList.toggle('activo');
        overlay.classList.toggle('activo');
    }

    function verComprobante(ticket, evento, fecha, cantidad, total, cliente) {
        document.getElementById('m_ticket').innerText = ticket;
        document.getElementById('m_evento').innerText = evento;
        document.getElementById('m_fecha').innerText = fecha;
        document.getElementById('m_cantidad').innerText = cantidad;
        document.getElementById('m_total').innerText = 'S/. ' + total;
        document.getElementById('m_cliente').innerText = cliente;

        document.getElementById('modalComprobante').style.display = 'flex';
    }

    function cerrarModal() {
        document.getElementById('modalComprobante').style.display = 'none';
    }

    // Cerrar modal haciendo clic fuera del contenido
    window.onclick = function(event) {
        let modal = document.getElementById('modalComprobante');
        if (event.target == modal) {
            modal.style.display = 'none';
        }
    }
</script>

</body>
</html>