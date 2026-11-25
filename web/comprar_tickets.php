<?php
session_start();
$cliente_activo = $_SESSION['cliente_nombre'] ?? null;
$id_cliente     = $_SESSION['cliente_id'] ?? null; 

require_once '../config/conexion.php';

$id_evento = isset($_REQUEST['id_evento']) ? trim($_REQUEST['id_evento']) : null;
$id_precio = isset($_REQUEST['id_precio']) ? trim($_REQUEST['id_precio']) : null;

$detalle_compra = null;
$error_msj = "";
$exito_msj = "";
$tickets_asignados = [];

// 1. Obtener el detalle del evento, tipo de ticket, precio y stock actual
if ($id_evento && $id_precio) {
    try {
        $sql_info = "SELECT 
                        e.NroEvento,
                        e.Nombre_Evento,
                        e.Fec_Inicio_Evento,
                        e.Hora_Inicio,
                        e.Hora_Fin,
                        tt.Tipo_Ticket,
                        tt.Descripcion_Ticket,
                        pt.IDPrecio,
                        pt.Tipo_Venta,
                        pt.Precio,
                        COUNT(t.CodTicket) AS Stock_Disponible
                     FROM Tb_Ticket t
                     INNER JOIN Tb_Evento e ON t.NroEvento = e.NroEvento
                     INNER JOIN Precio_Ticket pt ON t.IDPrecio = pt.IDPrecio
                     INNER JOIN Tb_Tipo_Ticket tt ON pt.CodTipoTicket = tt.CodTipoTicket
                     WHERE t.NroEvento = ? 
                       AND t.IDPrecio = ? 
                       AND t.Estado_Ticket = 'Disponible'
                       AND e.Estado_Evento = 'Activo'
                       AND pt.Estado_Precio = 'Activo'
                     GROUP BY 
                        e.NroEvento, e.Nombre_Evento, e.Fec_Inicio_Evento, e.Hora_Inicio, e.Hora_Fin,
                        tt.Tipo_Ticket, tt.Descripcion_Ticket, pt.IDPrecio, pt.Tipo_Venta, pt.Precio";
        
        $stmt_info = $conn->prepare($sql_info);
        $stmt_info->execute([$id_evento, $id_precio]);
        $detalle_compra = $stmt_info->fetch(PDO::FETCH_ASSOC);

        if (!$detalle_compra) {
            $error_msj = "La tarifa seleccionada ya no tiene tickets disponibles o no es válida.";
        }
    } catch (PDOException $e) {
        $error_msj = "Error al consultar disponibilidad: " . $e->getMessage();
    }
} else {
    $error_msj = "Faltan parámetros del evento o de la tarifa seleccionada.";
}

// 2. Procesar la compra cuando envían el formulario de pago final (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirmar_pago']) && $detalle_compra && !$error_msj) {
    if (!$id_cliente) {
        $error_msj = "Debes iniciar sesión como cliente para completar la compra de tickets.";
    } else {
        $cantidad = isset($_POST['cantidad']) ? (int)$_POST['cantidad'] : 0;
        $metodo_pago = isset($_POST['metodo_pago']) ? trim($_POST['metodo_pago']) : 'Efectivo';
        $stock_actual = (int)$detalle_compra['Stock_Disponible'];

        if ($cantidad <= 0 || $cantidad > $stock_actual) {
            $error_msj = "La cantidad ingresada no es válida o supera el stock disponible ($stock_actual).";
        } else {
            try {
                // Iniciar transacción en SQL Server
                $conn->beginTransaction();

                // Seleccionar los N primeros códigos de ticket disponibles con bloqueo de fila
                $sql_select = "SELECT TOP ($cantidad) CodTicket 
                               FROM Tb_Ticket WITH (UPDLOCK, ROWLOCK)
                               WHERE NroEvento = ? AND IDPrecio = ? AND Estado_Ticket = 'Disponible'
                               ORDER BY CodTicket ASC";
                $stmt_sel = $conn->prepare($sql_select);
                $stmt_sel->execute([$id_evento, $id_precio]);
                $tickets_asignados = $stmt_sel->fetchAll(PDO::FETCH_COLUMN);

                if (count($tickets_asignados) < $cantidad) {
                    $conn->rollBack();
                    $error_msj = "Lo sentimos, ya no quedan suficientes tickets disponibles.";
                } else {
                    $total_pagado = $cantidad * (float)$detalle_compra['Precio'];
                    $empleado_default = 'EMP02'; // Empleado por defecto para ventas web

                    // Obtener el siguiente número de venta de forma segura
                    $sql_max = "SELECT ISNULL(MAX(NroVenta_Ticket), 0) + 1 FROM Tb_Venta_Ticket";
                    $nro_venta = $conn->query($sql_max)->fetchColumn();

                    // Registrar la venta en Tb_Venta_Ticket
                    $sql_venta = "INSERT INTO Tb_Venta_Ticket (NroVenta_Ticket, CodCliente, CodEmpleado, Fec_Venta, NroTickets_Vendidos, Total_Pagar) 
                                  VALUES (?, ?, ?, GETDATE(), ?, ?)";
                    $stmt_vta = $conn->prepare($sql_venta);
                    $stmt_vta->execute([$nro_venta, $id_cliente, $empleado_default, $cantidad, $total_pagado]);

                    // Actualizar el estado de los tickets asignados a 'Vendido'
                    $placeholders = implode(',', array_fill(0, count($tickets_asignados), '?'));
                    $sql_upd = "UPDATE Tb_Ticket 
                                SET Estado_Ticket = 'Vendido' 
                                WHERE CodTicket IN ($placeholders)";
                    $stmt_upd = $conn->prepare($sql_upd);
                    $stmt_upd->execute($tickets_asignados);

                    $conn->commit();

                    $exito_msj = "¡Pago procesado con éxito mediante $metodo_pago! Has adquirido $cantidad entrada(s) por S/ " . number_format($total_pagado, 2) . ".";
                    $detalle_compra['Stock_Disponible'] -= $cantidad;
                }
            } catch (PDOException $e) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                $error_msj = "Error al procesar la compra: " . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout y Pago - La Industria Surco</title>
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
        
        /* Menú superior general */
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
        .btn-login-top {
            background: linear-gradient(135deg, #ff0055 0%, #b3003c 100%);
            color: #ffffff; padding: 10px 22px; border-radius: 6px;
            text-decoration: none; font-weight: 600; font-size: 0.9rem;
        }
        .user-greeting { font-size: 0.9rem; color: #ccc; }
        .user-greeting span { color: #00ffcc; font-weight: bold; }

        .container { max-width: 750px; margin: 40px auto; padding: 0 25px; }
        .btn-volver {
            display: inline-block; color: #ff6699; text-decoration: none;
            font-weight: 600; margin-bottom: 20px; font-size: 0.95rem;
        }
        .btn-volver:hover { text-decoration: underline; }

        .checkout-card {
            background: #161622;
            border: 1px solid #262638;
            border-radius: 14px;
            padding: 35px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.6);
        }
        .checkout-card h1 {
            margin: 0 0 20px 0; font-size: 1.7rem; color: #fff;
            border-bottom: 1px solid #262638; padding-bottom: 15px;
        }
        .checkout-card h1 span { color: #ff0055; }

        .resumen-tabla { width: 100%; border-collapse: collapse; margin-bottom: 25px; }
        .resumen-tabla td { padding: 12px 0; border-bottom: 1px solid #222232; font-size: 0.96rem; }
        .resumen-tabla td.label { color: #9e9eb3; width: 40%; }
        .resumen-tabla td.valor { color: #fff; font-weight: 600; text-align: right; }

        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; color: #ccc; font-weight: 600; font-size: 0.92rem; }
        .form-control {
            width: 100%; padding: 12px 15px; background: #0f0f17;
            border: 1px solid #323248; border-radius: 8px; color: #fff;
            font-size: 1rem; margin-top: 4px;
        }
        .form-control:focus { outline: none; border-color: #ff0055; box-shadow: 0 0 8px rgba(255, 0, 85, 0.25); }

        /* Estilo Pasarela Web moderna (Tabs / Tarjetas de selección) */
        .metodos-pago {
            display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 25px;
        }
        .metodo-card {
            background: #0f0f17; border: 2px solid #323248; border-radius: 10px;
            padding: 18px 12px; cursor: pointer; text-align: center;
            transition: all 0.25s ease; position: relative;
        }
        .metodo-card input[type="radio"] { display: none; }
        .metodo-card .icon { font-size: 1.6rem; display: block; margin-bottom: 6px; }
        .metodo-card .titulo-metodo { color: #b0b0c0; font-weight: 600; font-size: 0.9rem; }
        
        /* Estado activo de la tarjeta de pago */
        .metodo-card.active {
            border-color: #ff0055;
            background: rgba(255, 0, 85, 0.08);
        }
        .metodo-card.active .titulo-metodo { color: #fff; }

        /* Paneles dinámicos de pago profesional */
        .panel-pago {
            display: none; background: #101018; border: 1px solid #2b2b3d;
            border-radius: 10px; padding: 22px; margin-bottom: 25px; animation: fadeIn 0.3s ease;
        }
        .panel-pago h3 { margin-top: 0; color: #00ffcc; font-size: 1.1rem; margin-bottom: 15px; display: flex; align-items: center; gap: 8px; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(-4px); } to { opacity: 1; transform: translateY(0); } }

        .qr-box {
            background: #fff; color: #000; padding: 15px; border-radius: 8px;
            width: 140px; margin: 10px auto; text-align: center; font-weight: bold; font-size: 0.85rem;
            box-shadow: 0 4px 15px rgba(0,0,0,0.3);
        }

        .total-box {
            background: #101018; border: 1px solid rgba(0, 255, 204, 0.25);
            border-radius: 10px; padding: 18px 22px;
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 25px;
        }
        .total-box span { font-size: 1.1rem; color: #b0b0c0; }
        .total-box strong { font-size: 1.8rem; color: #00ffcc; }

        .acciones-flex { display: flex; gap: 15px; }
        .btn-confirmar {
            flex: 2; padding: 15px; border: none; border-radius: 8px;
            background: linear-gradient(135deg, #ff0055 0%, #b3003c 100%);
            color: #fff; font-size: 1.05rem; font-weight: bold; cursor: pointer;
            box-shadow: 0 5px 20px rgba(255, 0, 85, 0.35); text-align: center; text-decoration: none;
            transition: opacity 0.2s;
        }
        .btn-confirmar:hover { opacity: 0.9; }
        
        .btn-cancelar-form {
            flex: 1; padding: 15px; border: 1px solid #383852; border-radius: 8px;
            background: #1f1f2e; color: #ff80aa; font-size: 1.05rem; font-weight: bold;
            cursor: pointer; text-align: center; text-decoration: none;
        }
        .btn-cancelar-form:hover { background: #2a2a3d; color: #ff4d88; }

        .alerta-error {
            background: rgba(255, 0, 85, 0.12); border: 1px solid #ff0055;
            color: #ff80aa; padding: 16px; border-radius: 8px; margin-bottom: 20px;
        }
        .alerta-exito {
            background: rgba(0, 255, 204, 0.1); border: 1px solid #00ffcc;
            color: #00ffcc; padding: 20px; border-radius: 8px; margin-bottom: 20px;
        }
        .tickets-list {
            margin-top: 12px; padding-left: 20px; color: #fff; font-family: monospace; font-size: 0.95rem;
        }
        .btn-secundario {
            display: inline-block; margin-top: 15px; background: #252538; color: #fff;
            padding: 10px 18px; border-radius: 6px; text-decoration: none; font-weight: 600;
        }
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
        <?php endif; ?>
    </ul>
</div>

<nav>
    <div class="nav-left">
        <!-- Botón de hamburguesa a la izquierda del logo -->
        <button class="menu-toggle-btn" onclick="toggleMenu()">
            <span>&#9776;</span>
        </button>
        <a href="index.php" class="logo">LA INDUSTRIA <span>SURCO</span></a>
        
        <ul class="nav-links">
            <li><a href="index.php">Inicio</a></li>
            <li><a href="disponibilidad_tickets.php">Tickets</a></li>
            <li><a href="catalogo_bebidas.php">Bebidas</a></li>
            <?php if ($cliente_activo): ?>
                <li><a href="mis_pedidos.php">Mis Pedidos</a></li>
                <li><a href="mis_tickets.php">Mis Tickets</a></li>
            <?php endif; ?>
        </ul>
    </div>

    <div class="nav-right">
        <?php if ($cliente_activo): ?>
            <div class="user-greeting">Hola, <span><?php echo htmlspecialchars($cliente_activo); ?></span></div>
            <a href="cerrar_sesion.php" class="btn-login-top" style="background: #252535;">Cerrar Sesión</a>
        <?php else: ?>
            <a href="login_cliente.php" class="btn-login-top">Iniciar Sesión</a>
        <?php endif; ?>
    </div>
</nav>

<div class="container">
    <a href="index.php" class="btn-volver">&larr; Volver a la Cartelera</a>

    <div class="checkout-card">
        <h1>Finalizar <span>Compra y Pago</span></h1>

        <?php if ($error_msj): ?>
            <div class="alerta-error">
                <?php echo htmlspecialchars($error_msj); ?>
                <?php if (!$cliente_activo): ?>
                    <br><a href="login_cliente.php" class="btn-secundario">Ir a Iniciar Sesión</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($exito_msj): ?>
            <div class="alerta-exito">
                <strong><?php echo htmlspecialchars($exito_msj); ?></strong>
                <p style="margin: 10px 0 5px 0; color: #ccc;">Códigos de tus entradas asignadas:</p>
                <ul class="tickets-list">
                    <?php foreach ($tickets_asignados as $cod): ?>
                        <li><?php echo htmlspecialchars(trim($cod)); ?></li>
                    <?php endforeach; ?>
                </ul>
                <a href="mis_tickets.php" class="btn-secundario" style="background: #00ffcc; color: #0b0b10;">Ver Mis Tickets</a>
                <a href="index.php" class="btn-secundario">Volver a Cartelera</a>
            </div>
        <?php endif; ?>

        <?php if ($detalle_compra && !$exito_msj): ?>
            <?php 
                $cantidad_pedida = isset($_POST['cantidad']) ? (int)$_POST['cantidad'] : 1;
                $monto_total = $cantidad_pedida * (float)$detalle_compra['Precio'];
            ?>

            <table class="resumen-tabla">
                <tr>
                    <td class="label">Evento:</td>
                    <td class="valor"><?php echo htmlspecialchars($detalle_compra['Nombre_Evento']); ?></td>
                </tr>
                <tr>
                    <td class="label">Zona / Tarifa:</td>
                    <td class="valor"><?php echo htmlspecialchars($detalle_compra['Tipo_Ticket'] . " (" . $detalle_compra['Tipo_Venta'] . ")"); ?></td>
                </tr>
                <tr>
                    <td class="label">Cantidad Solicitada:</td>
                    <td class="valor" style="color: #00ffcc;"><?php echo $cantidad_pedida; ?> entrada(s)</td>
                </tr>
                <tr>
                    <td class="label">Precio Unitario:</td>
                    <td class="valor">S/ <?php echo number_format((float)$detalle_compra['Precio'], 2); ?></td>
                </tr>
            </table>

            <?php if ((int)$detalle_compra['Stock_Disponible'] > 0): ?>
                <form method="POST" action="comprar_tickets.php?id_evento=<?php echo urlencode($id_evento); ?>&id_precio=<?php echo urlencode($id_precio); ?>">
                    <input type="hidden" name="id_evento" value="<?php echo htmlspecialchars(trim($detalle_compra['NroEvento'])); ?>">
                    <input type="hidden" name="id_precio" value="<?php echo htmlspecialchars(trim($detalle_compra['IDPrecio'])); ?>">
                    <input type="hidden" name="cantidad" value="<?php echo $cantidad_pedida; ?>">

                    <div class="form-group">
                        <label>Selecciona tu Método de Pago:</label>
                        <div class="metodos-pago">
                            <div class="metodo-card active" id="card_efectivo" onclick="seleccionarMetodo('Efectivo')">
                                <input type="radio" id="pago_efectivo" name="metodo_pago" value="Efectivo" checked>
                                <span class="icon">💵</span>
                                <span class="titulo-metodo">Efectivo</span>
                            </div>
                            <div class="metodo-card" id="card_tarjeta" onclick="seleccionarMetodo('Tarjeta')">
                                <input type="radio" id="pago_tarjeta" name="metodo_pago" value="Tarjeta">
                                <span class="icon">💳</span>
                                <span class="titulo-metodo">Tarjeta</span>
                            </div>
                            <div class="metodo-card" id="card_yape" onclick="seleccionarMetodo('Yape / Plin')">
                                <input type="radio" id="pago_yape" name="metodo_pago" value="Yape / Plin">
                                <span class="icon">📱</span>
                                <span class="titulo-metodo">Yape / Plin</span>
                            </div>
                        </div>
                    </div>

                    <!-- PANEL DE TARJETA -->
                    <div id="panel_tarjeta" class="panel-pago">
                        <h3>💳 Ingrese los datos de su Tarjeta</h3>
                        <div class="form-group">
                            <label>Número de Tarjeta</label>
                            <input type="text" class="form-control" placeholder="4532 •••• •••• 8920" maxlength="19">
                        </div>
                        <div style="display: flex; gap: 15px;">
                            <div class="form-group" style="flex: 1; margin-bottom: 0;">
                                <label>Vencimiento (MM/AA)</label>
                                <input type="text" class="form-control" placeholder="MM/AA" maxlength="5">
                            </div>
                            <div class="form-group" style="flex: 1; margin-bottom: 0;">
                                <label>CVV / CVC</label>
                                <input type="password" class="form-control" placeholder="123" maxlength="4">
                            </div>
                        </div>
                    </div>

                    <!-- PANEL DE YAPE / PLIN -->
                    <div id="panel_yape" class="panel-pago">
                        <h3>📱 Pago con Billetera Digital (Yape / Plin)</h3>
                        <p style="color: #b0b0c0; font-size: 0.9rem; margin-top:0;">Escanea el código QR desde tu celular o transfiere al número:</p>
                        <div style="font-size: 1.3rem; color: #00ffcc; font-weight: bold; text-align: center; margin: 8px 0;">987 654 321</div>
                        <div class="qr-box">
                            [ QR YAPE / PLIN ]<br><span style="font-size:0.75rem; color:#555;">La Industria Surco</span>
                        </div>
                        <div class="form-group" style="margin-top: 15px; margin-bottom: 0;">
                            <label>Número de Operación / Voucher</label>
                            <input type="text" class="form-control" placeholder="Ingresa el código de la transferencia" name="nro_operacion">
                        </div>
                    </div>

                    <!-- PANEL DE EFECTIVO -->
                    <div id="panel_efectivo" class="panel-pago" style="display: block;">
                        <h3>💵 Pago en Efectivo (En Caja)</h3>
                        <p style="color: #b0b0c0; font-size: 0.92rem; margin-bottom: 0;">Al confirmar tu reserva, se generará tu código de ticket. Podrás acercarte a cancelar directamente en la boletería o caja al ingresar a la discoteca.</p>
                    </div>

                    <div class="total-box">
                        <span>Total a Pagar:</span>
                        <strong id="total_pagar">S/ <?php echo number_format($monto_total, 2); ?></strong>
                    </div>

                    <div class="acciones-flex">
                        <button type="submit" name="confirmar_pago" value="1" class="btn-confirmar">Pagar S/ <?php echo number_format($monto_total, 2); ?></button>
                        <a href="index.php" class="btn-cancelar-form">Cancelar</a>
                    </div>
                </form>

                <script>
                    function toggleMenu() {
                        let sidebar = document.getElementById('sidebar');
                        let overlay = document.getElementById('overlay');
                        sidebar.classList.toggle('activo');
                        overlay.classList.toggle('activo');
                    }

                    function seleccionarMetodo(metodo) {
                        // Ocultar todos los paneles y quitar clases activas a las tarjetas
                        document.getElementById('panel_tarjeta').style.display = 'none';
                        document.getElementById('panel_yape').style.display = 'none';
                        document.getElementById('panel_efectivo').style.display = 'none';

                        document.getElementById('card_efectivo').classList.remove('active');
                        document.getElementById('card_tarjeta').classList.remove('active');
                        document.getElementById('card_yape').classList.remove('active');

                        // Activar el seleccionado
                        if (metodo === 'Tarjeta') {
                            document.getElementById('pago_tarjeta').checked = true;
                            document.getElementById('card_tarjeta').classList.add('active');
                            document.getElementById('panel_tarjeta').style.display = 'block';
                        } else if (metodo === 'Yape / Plin') {
                            document.getElementById('pago_yape').checked = true;
                            document.getElementById('card_yape').classList.add('active');
                            document.getElementById('panel_yape').style.display = 'block';
                        } else if (metodo === 'Efectivo') {
                            document.getElementById('pago_efectivo').checked = true;
                            document.getElementById('card_efectivo').classList.add('active');
                            document.getElementById('panel_efectivo').style.display = 'block';
                        }
                    }
                </script>
            <?php else: ?>
                <div class="alerta-error">Entradas agotadas para esta zona.</div>
                <a href="index.php" class="btn-secundario">Regresar a Cartelera</a>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

</body>
</html>