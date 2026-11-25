<?php
// Forzar visualización de errores
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
$cliente_activo = $_SESSION['cliente_nombre'] ?? null;
$id_cliente     = $_SESSION['cliente_id'] ?? null; // CodCliente (ej: 'CLI01')

require_once '../config/conexion.php';

$id_evento = isset($_REQUEST['id_evento']) ? trim($_REQUEST['id_evento']) : null;
$id_precio = isset($_REQUEST['id_precio']) ? trim($_REQUEST['id_precio']) : null;

$evento = null;
$detalle_precio = null;
$tickets_disponibles = [];
$lista_eventos = [];
$mesas_disponibles = [];
$error_msj = "";
$exito_msj = "";
$tickets_ya_comprados = 0;
$cupo_restante = 5;

try {
    if ($id_evento) {
        // 1. Obtener datos del evento seleccionado
        $sql_ev = "SELECT NroEvento, Nombre_Evento, Descripcion_Evento, Fec_Inicio_Evento, Hora_Inicio, Hora_Fin, Aforo 
                   FROM Tb_Evento 
                   WHERE NroEvento = ? AND Estado_Evento = 'Activo'";
        $stmt_ev = $conn->prepare($sql_ev);
        $stmt_ev->execute([$id_evento]);
        $evento = $stmt_ev->fetch(PDO::FETCH_ASSOC);

        if (!$evento) {
            $error_msj = "El evento solicitado no existe o no se encuentra activo.";
        } else {
            // 2. Si el cliente ha iniciado sesión, calculamos cuántos tickets compró para ESTE evento
            if ($id_cliente) {
                $sql_l = "SELECT COUNT(dt.CodTicket) 
                          FROM Tb_Detalle_Venta dt 
                          INNER JOIN Tb_Venta_Ticket vt ON dt.NroVentaTicket = vt.NroVenta_Ticket 
                          INNER JOIN Tb_Ticket tk ON dt.CodTicket = tk.CodTicket 
                          WHERE vt.CodCliente = ? AND tk.NroEvento = ?";
                $stmt_l = $conn->prepare($sql_l);
                $stmt_l->execute([$id_cliente, $id_evento]);
                $tickets_ya_comprados = (int)$stmt_l->fetchColumn();

                $cupo_restante = 5 - $tickets_ya_comprados;
                if ($cupo_restante < 0) $cupo_restante = 0;
            }

            // 3. Consultar precios y stock disponible para este evento
            $sql_tk = "SELECT 
                            tt.CodTipoTicket,
                            tt.Tipo_Ticket,
                            tt.Descripcion_Ticket,
                            pt.IDPrecio,
                            pt.Tipo_Venta,
                            pt.Precio,
                            pt.Fec_Inicio,
                            pt.Fec_Termino,
                            COUNT(t.CodTicket) AS Stock_Disponible
                       FROM Tb_Ticket t
                       INNER JOIN Precio_Ticket pt ON t.IDPrecio = pt.IDPrecio
                       INNER JOIN Tb_Tipo_Ticket tt ON pt.CodTipoTicket = tt.CodTipoTicket
                       WHERE t.NroEvento = ? 
                         AND t.Estado_Ticket = 'Disponible'
                         AND pt.Estado_Precio = 'Activo'
                       GROUP BY 
                            tt.CodTipoTicket, 
                            tt.Tipo_Ticket, 
                            tt.Descripcion_Ticket, 
                            pt.IDPrecio, 
                            pt.Tipo_Venta, 
                            pt.Precio, 
                            pt.Fec_Inicio, 
                            pt.Fec_Termino
                       ORDER BY pt.Precio ASC";
            
            $stmt_tk = $conn->prepare($sql_tk);
            $stmt_tk->execute([$id_evento]);
            $tickets_disponibles = $stmt_tk->fetchAll(PDO::FETCH_ASSOC);

            // 4. Si seleccionaron un precio, cargamos las mesas disponibles de la sede SED01
            if ($id_precio) {
                $sql_pr = "SELECT pt.IDPrecio, pt.Precio, pt.Tipo_Venta, tt.Tipo_Ticket 
                           FROM Precio_Ticket pt 
                           INNER JOIN Tb_Tipo_Ticket tt ON pt.CodTipoTicket = tt.CodTipoTicket 
                           WHERE pt.IDPrecio = ? AND pt.Estado_Precio = 'Activo'";
                $stmt_pr = $conn->prepare($sql_pr);
                $stmt_pr->execute([$id_precio]);
                $detalle_precio = $stmt_pr->fetch(PDO::FETCH_ASSOC);

                $sql_mesas = "SELECT NroMesa, Descripcion_Mesa, Nro_Sillas FROM Tb_Mesa WHERE CodSede = 'SED01' AND Estado_Mesa = 'Disponible'";
                $stmt_mesas = $conn->query($sql_mesas);
                $mesas_disponibles = $stmt_mesas->fetchAll(PDO::FETCH_ASSOC);
            }
        }
    } else {
        // Listado general de eventos activos
        $stmt_all = $conn->query("SELECT NroEvento, Nombre_Evento, Fec_Inicio_Evento, Hora_Inicio FROM Tb_Evento WHERE Estado_Evento = 'Activo' ORDER BY Fec_Inicio_Evento ASC");
        $lista_eventos = $stmt_all->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    $error_msj = "Error en SQL Server: " . $e->getMessage();
}

// 5. Procesar la compra cuando envían el formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirmar_compra'])) {
    if (!$id_cliente) {
        $error_msj = "Debe iniciar sesión para realizar la compra.";
    } else {
        $nro_mesa = !empty($_POST['nro_mesa']) ? trim($_POST['nro_mesa']) : null;
        $cantidad_comprar = 1;

        try {
            $conn->beginTransaction();

            // Verificar límite de 5 tickets
            $sql_lim = "SELECT COUNT(dt.CodTicket) 
                        FROM Tb_Detalle_Venta dt 
                        INNER JOIN Tb_Venta_Ticket vt ON dt.NroVentaTicket = vt.NroVenta_Ticket 
                        INNER JOIN Tb_Ticket tk ON dt.CodTicket = tk.CodTicket 
                        WHERE vt.CodCliente = ? AND tk.NroEvento = ?";
            $stmt_lim = $conn->prepare($sql_lim);
            $stmt_lim->execute([$id_cliente, $id_evento]);
            $comprados_actuales = (int)$stmt_lim->fetchColumn();

            if (($comprados_actuales + $cantidad_comprar) > 5) {
                throw new Exception("Has superado el límite máximo de 5 tickets permitidos por evento.");
            }

            // Buscar ticket disponible
            $stmt_tk_disp = $conn->prepare("SELECT TOP 1 CodTicket FROM Tb_Ticket WHERE NroEvento = ? AND IDPrecio = ? AND Estado_Ticket = 'Disponible'");
            $stmt_tk_disp->execute([$id_evento, $id_precio]);
            $ticket_encontrado = $stmt_tk_disp->fetch(PDO::FETCH_ASSOC);

            if (!$ticket_encontrado) {
                throw new Exception("Lo sentimos, ya no quedan entradas disponibles para esta zona.");
            }

            $cod_ticket = $ticket_encontrado['CodTicket'];

            // Obtener precio actual
            $stmt_p = $conn->prepare("SELECT Precio FROM Precio_Ticket WHERE IDPrecio = ?");
            $stmt_p->execute([$id_precio]);
            $precio_unitario = (float)$stmt_p->fetchColumn();

            // Actualizar ticket a Comprado
            $stmt_upd = $conn->prepare("UPDATE Tb_Ticket SET Estado_Ticket = 'Comprado' WHERE CodTicket = ?");
            $stmt_upd->execute([$cod_ticket]);

            // Generar número de venta único
            $nro_venta_ticket = (int)(date('mdHis') . rand(1, 9));

            // Registrar cabecera de venta (Tb_Venta_Ticket)
            $sql_venta = "INSERT INTO Tb_Venta_Ticket (NroVenta_Ticket, CodCliente, CodEmpleado, Fec_Venta, NroTickets_Vendidos, Total_Pagar) 
                          VALUES (?, ?, 'EMP01', GETDATE(), 1, ?)";
            $stmt_v = $conn->prepare($sql_venta);
            $stmt_v->execute([$nro_venta_ticket, $id_cliente, $precio_unitario]);

            // Registrar detalle de venta (Tb_Detalle_Venta)
            $sql_det_vta = "INSERT INTO Tb_Detalle_Venta (NroVentaTicket, CodTicket, Prec_Venta, Estado_VentaTicket) 
                            VALUES (?, ?, ?, 'Completado')";
            $stmt_dv = $conn->prepare($sql_det_vta);
            $stmt_dv->execute([$nro_venta_ticket, $cod_ticket, $precio_unitario]);

            $conn->commit();
            $info_mesa = $nro_mesa ? " Ubicación asignada: <strong>$nro_mesa</strong>." : " (Entrada General / Sin Mesa asignada).";
            $exito_msj = "¡Ticket comprado con éxito! Código de Ticket: <strong>$cod_ticket</strong>.$info_mesa";

        } catch (Exception $e) {
            if ($conn->inTransaction()) {
                $conn->rollBack();
            }
            $error_msj = "Error al procesar la compra: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Disponibilidad y Compra de Tickets - La Industria Surco</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Segoe UI', system-ui, sans-serif; background-color: #0b0b10; color: #f1f1f5; margin: 0; padding: 0; }
        
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
        
        .nav-links { display: flex; gap: 20px; list-style: none; margin: 0; padding: 0; align-items: center; }
        .nav-links a { text-decoration: none; color: #b0b0c0; font-size: 0.9rem; font-weight: 600; transition: color 0.2s; }
        .nav-links a:hover, .nav-links a.activo { color: #ff0055; }

        /* Menú Lateral (Sidebar) */
        .sidebar {
            position: fixed; top: 0; left: -300px; width: 300px; height: 100%;
            background-color: #12121c; border-right: 1px solid rgba(255, 0, 85, 0.2);
            box-shadow: 10px 0 30px rgba(0,0,0,0.8); z-index: 2000;
            transition: left 0.3s ease; padding: 25px 20px; overflow-y: auto;
            display: flex; flex-direction: column;
        }
        .sidebar.activo { left: 0; }
        .sidebar-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #262638; padding-bottom: 15px; margin-bottom: 25px; }
        .sidebar-title { font-weight: 800; font-size: 1.1rem; color: #fff; }
        .sidebar-title span { color: #ff0055; }
        .sidebar-close { background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #fff; }
        
        .sidebar-section-title {
            font-size: 0.75rem; text-transform: uppercase; letter-spacing: 1px;
            color: #777788; margin: 15px 0 8px 10px; font-weight: bold;
        }
        .sidebar-links { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 8px; }
        .sidebar-links a {
            text-decoration: none; color: #c0c0d0; font-size: 0.95rem; font-weight: 600;
            display: block; padding: 10px 14px; border-radius: 8px; transition: all 0.2s;
        }
        .sidebar-links a:hover { background: rgba(255, 0, 85, 0.15); color: #ff0055; padding-left: 18px; }

        .overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); backdrop-filter: blur(3px); z-index: 1500; display: none; }
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

        .container { max-width: 900px; margin: 35px auto; padding: 0 30px; }
        .btn-volver { display: inline-block; color: #ff6699; text-decoration: none; font-weight: 600; margin-bottom: 20px; font-size: 0.95rem; }
        .btn-volver:hover { text-decoration: underline; }

        .evento-banner { background: linear-gradient(135deg, #1f1224 0%, #161622 100%); border: 1px solid rgba(255, 0, 85, 0.35); border-radius: 14px; padding: 25px; margin-bottom: 25px; }
        .evento-tag { background: rgba(255, 0, 85, 0.15); color: #ff6699; padding: 5px 12px; border-radius: 6px; font-size: 0.8rem; font-weight: bold; display: inline-block; margin-bottom: 10px; }
        .evento-banner h1 { margin: 0 0 10px 0; font-size: 1.8rem; color: #fff; }
        .evento-banner p { margin: 0; color: #b0b0c0; font-size: 0.95rem; }

        .checkout-card { background: #161622; border: 1px solid #262638; border-radius: 14px; padding: 35px; box-shadow: 0 10px 30px rgba(0,0,0,0.6); }
        .checkout-card h2 { margin-top: 0; color: #fff; border-bottom: 1px solid #262638; padding-bottom: 15px; font-size: 1.4rem; }
        .checkout-card h2 span { color: #ff0055; }

        .resumen-tabla { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
        .resumen-tabla td { padding: 10px 0; border-bottom: 1px solid #222232; font-size: 0.95rem; }
        .resumen-tabla td.label { color: #9e9eb3; width: 40%; }
        .resumen-tabla td.valor { color: #fff; font-weight: 600; text-align: right; }

        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; color: #ccc; font-weight: 600; font-size: 0.92rem; }
        .form-control { width: 100%; padding: 12px 15px; background: #0f0f17; border: 1px solid #323248; border-radius: 8px; color: #fff; font-size: 1rem; outline: none; }
        .form-control:focus { border-color: #ff0055; }

        .metodos-pago { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 20px; }
        .metodo-card { background: #0f0f17; border: 2px solid #323248; border-radius: 10px; padding: 15px; cursor: pointer; text-align: center; }
        .metodo-card input[type="radio"] { display: none; }
        .metodo-card .icon { font-size: 1.5rem; display: block; margin-bottom: 5px; }
        .metodo-card .titulo-metodo { color: #b0b0c0; font-weight: 600; font-size: 0.85rem; }
        .metodo-card.active { border-color: #ff0055; background: rgba(255, 0, 85, 0.08); }
        .metodo-card.active .titulo-metodo { color: #fff; }

        .total-box { background: #101018; border: 1px solid rgba(0, 255, 204, 0.25); border-radius: 10px; padding: 15px 20px; display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .total-box span { font-size: 1rem; color: #b0b0c0; }
        .total-box strong { font-size: 1.6rem; color: #00ffcc; }

        .acciones-flex { display: flex; gap: 15px; }
        .btn-confirmar { flex: 2; padding: 14px; border: none; border-radius: 8px; background: linear-gradient(135deg, #ff0055 0%, #b3003c 100%); color: #fff; font-size: 1rem; font-weight: bold; cursor: pointer; text-align: center; }
        .btn-cancelar-form { flex: 1; padding: 14px; border: 1px solid #383852; border-radius: 8px; background: #1f1f2e; color: #ff80aa; font-size: 1rem; font-weight: bold; text-align: center; text-decoration: none; display: flex; align-items: center; justify-content: center; }

        .tickets-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; }
        .ticket-card { background: #161622; border: 1px solid #262638; border-radius: 12px; padding: 22px; display: flex; flex-direction: column; justify-content: space-between; }
        .ticket-tipo { font-size: 1.2rem; font-weight: 700; color: #fff; margin: 0 0 8px 0; }
        .ticket-precio { font-size: 1.8rem; font-weight: 800; color: #fff; margin-bottom: 12px; }
        .ticket-precio small { font-size: 0.9rem; color: #ff0055; }
        .btn-seleccionar { display: block; text-align: center; background: linear-gradient(135deg, #ff0055 0%, #b3003c 100%); color: #fff; padding: 12px; border-radius: 8px; text-decoration: none; font-weight: bold; }

        .alerta-error { background: rgba(255, 0, 85, 0.12); border: 1px solid #ff0055; color: #ff80aa; padding: 15px; border-radius: 8px; margin-bottom: 20px; }
        .alerta-exito { background: rgba(0, 255, 204, 0.1); border: 1px solid #00ffcc; color: #00ffcc; padding: 20px; border-radius: 8px; margin-bottom: 20px; line-height: 1.6; }
        .btn-secundario { display: inline-block; margin-top: 10px; background: #252538; color: #fff; padding: 8px 15px; border-radius: 6px; text-decoration: none; font-weight: 600; margin-right: 10px; }
    </style>
</head>
<body>

<!-- Menú Lateral (Sidebar) con la estructura unificada -->
<div class="overlay" id="overlay" onclick="toggleMenu()"></div>
<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="sidebar-title">LA INDUSTRIA <span>SURCO</span></div>
        <button class="sidebar-close" onclick="toggleMenu()">&times;</button>
    </div>
    
    <div class="sidebar-section-title">Navegación Principal</div>
    <ul class="sidebar-links">
        <li><a href="index.php">Inicio</a></li>
        <li><a href="catalogo_bebidas.php">Productos</a></li>
        <li><a href="disponibilidad_tickets.php" class="activo" style="color: #ff0055;">Tickets</a></li>
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

<!-- Barra Superior con el menú sincronizado -->
<nav>
    <div class="nav-left">
        <button class="menu-toggle-btn" onclick="toggleMenu()">
            <span>&#9776;</span>
        </button>
        <a href="index.php" class="logo">LA INDUSTRIA <span>SURCO</span></a>
        <ul class="nav-links">
            <li><a href="index.php">Inicio</a></li>
            <li><a href="catalogo_bebidas.php">Productos</a></li>
            <li><a href="disponibilidad_tickets.php" class="activo" style="color: #ff0055; font-weight: bold;">Tickets</a></li>
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
    <?php if ($id_precio && $evento && $detalle_precio): ?>
        <!-- PASO 2: FORMULARIO DE COMPRA (MESA OPCIONAL) -->
        <a href="disponibilidad_tickets.php?id_evento=<?php echo urlencode($id_evento); ?>" class="btn-volver">&larr; Volver a Zonas</a>

        <div class="checkout-card">
            <h2>Confirmar Entrada y <span>Ubicación</span></h2>

            <?php if ($error_msj): ?>
                <div class="alerta-error"><?php echo htmlspecialchars($error_msj); ?></div>
            <?php endif; ?>

            <?php if ($exito_msj): ?>
                <div class="alerta-exito">
                    <strong><?php echo $exito_msj; ?></strong><br>
                    <a href="mis_tickets.php" class="btn-secundario">🎟️ Ver Mis Tickets</a>
                    <a href="disponibilidad_tickets.php" class="btn-secundario" style="background: #1f1f2e;">Comprar Otro Ticket</a>
                </div>
            <?php else: ?>
                <table class="resumen-tabla">
                    <tr>
                        <td class="label">Evento:</td>
                        <td class="valor"><?php echo htmlspecialchars($evento['Nombre_Evento']); ?></td>
                    </tr>
                    <tr>
                        <td class="label">Zona / Tipo:</td>
                        <td class="valor"><?php echo htmlspecialchars($detalle_precio['Tipo_Ticket'] . ' (' . $detalle_precio['Tipo_Venta'] . ')'); ?></td>
                    </tr>
                    <tr>
                        <td class="label">Precio:</td>
                        <td class="valor" style="color: #00ffcc;">S/ <?php echo number_format((float)$detalle_precio['Precio'], 2); ?></td>
                    </tr>
                </table>

                <form method="POST" action="disponibilidad_tickets.php?id_evento=<?php echo urlencode($id_evento); ?>&id_precio=<?php echo urlencode($id_precio); ?>">
                    
                    <!-- SELECTOR DE MESA / BOX / BARRA OPCIONAL -->
                    <div class="form-group">
                        <label>Selecciona tu Mesa / Box / Barra (Opcional):</label>
                        <select name="nro_mesa" class="form-control">
                            <option value="">-- Sin Mesa / Solo General (Para pararse / barra) --</option>
                            <?php foreach ($mesas_disponibles as $mesa): ?>
                                <option value="<?php echo htmlspecialchars($mesa['NroMesa']); ?>">
                                    <?php echo htmlspecialchars($mesa['NroMesa'] . ' - ' . $mesa['Descripcion_Mesa'] . ' (' . $mesa['Nro_Sillas'] . ' sillas)'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small style="color: #777788; display: block; margin-top: 5px;">Si solo vas a consumir de pie o ingresar a la zona general, déjalo en la opción por defecto.</small>
                    </div>

                    <div class="form-group">
                        <label>Método de Pago:</label>
                        <div class="metodos-pago">
                            <div class="metodo-card active" id="card_efectivo" onclick="seleccionarMetodo('Efectivo')">
                                <input type="radio" name="metodo_pago" value="Efectivo" checked>
                                <span class="icon">💵</span>
                                <span class="titulo-metodo">Efectivo</span>
                            </div>
                            <div class="metodo-card" id="card_tarjeta" onclick="seleccionarMetodo('Tarjeta')">
                                <input type="radio" name="metodo_pago" value="Tarjeta">
                                <span class="icon">💳</span>
                                <span class="titulo-metodo">Tarjeta</span>
                            </div>
                            <div class="metodo-card" id="card_yape" onclick="seleccionarMetodo('Yape / Plin')">
                                <input type="radio" name="metodo_pago" value="Yape / Plin">
                                <span class="icon">📱</span>
                                <span class="titulo-metodo">Yape / Plin</span>
                            </div>
                        </div>
                    </div>

                    <div class="total-box">
                        <span>Total a Pagar:</span>
                        <strong>S/ <?php echo number_format((float)$detalle_precio['Precio'], 2); ?></strong>
                    </div>

                    <div class="acciones-flex">
                        <button type="submit" name="confirmar_compra" value="1" class="btn-confirmar">Confirmar Compra</button>
                        <a href="disponibilidad_tickets.php?id_evento=<?php echo urlencode($id_evento); ?>" class="btn-cancelar-form">Cancelar</a>
                    </div>
                </form>

                <script>
                    function seleccionarMetodo(metodo) {
                        document.getElementById('card_efectivo').classList.remove('active');
                        document.getElementById('card_tarjeta').classList.remove('active');
                        document.getElementById('card_yape').classList.remove('active');
                        event.currentTarget.classList.add('active');
                        event.currentTarget.querySelector('input').checked = true;
                    }
                </script>
            <?php endif; ?>
        </div>

    <?php elseif ($id_evento && $evento): ?>
        <!-- PASO 1: LISTADO DE ZONAS Y PRECIOS DEL EVENTO -->
        <a href="disponibilidad_tickets.php" class="btn-volver">&larr; Volver a la Lista de Eventos</a>

        <div class="evento-banner">
            <span class="evento-tag">CÓDIGO: <?php echo htmlspecialchars(trim($evento['NroEvento'])); ?></span>
            <h1><?php echo htmlspecialchars($evento['Nombre_Evento']); ?></h1>
            <p><?php echo htmlspecialchars($evento['Descripcion_Evento']); ?></p>
        </div>

        <?php if ($cliente_activo): ?>
            <div style="background: rgba(255,170,0,0.1); border:1px solid #ffaa00; color:#ffcc66; padding:12px 18px; border-radius:8px; margin-bottom:20px; font-size:0.9rem;">
                🎟️ Límite por evento: Has adquirido <strong><?php echo $tickets_ya_comprados; ?></strong> de 5 tickets permitidos. Cupo restante: <strong><?php echo $cupo_restante; ?></strong>.
            </div>
        <?php endif; ?>

        <h3 style="color:#fff; margin-bottom:15px;">Selecciona una Zona / Tipo de Ticket</h3>

        <?php if (empty($tickets_disponibles)): ?>
            <div style="background:#161622; padding:30px; text-align:center; border-radius:10px; color:#9e9eb3;">No hay entradas disponibles para este evento actualmente.</div>
        <?php else: ?>
            <div class="tickets-grid">
                <?php foreach ($tickets_disponibles as $tk): ?>
                    <div class="ticket-card">
                        <div>
                            <h4 class="ticket-tipo"><?php echo htmlspecialchars($tk['Tipo_Ticket']); ?></h4>
                            <p style="color:#9e9eb3; font-size:0.88rem; margin-bottom:15px;"><?php echo htmlspecialchars($tk['Descripcion_Ticket']); ?></p>
                            <div class="ticket-precio"><small>S/ </small><?php echo number_format((float)$tk['Precio'], 2); ?></div>
                            <div style="font-size:0.85rem; color:#00ffcc; margin-bottom:15px;">Disponibles: <strong><?php echo (int)$tk['Stock_Disponible']; ?></strong></div>
                        </div>

                        <?php if (!$cliente_activo): ?>
                            <a href="login_cliente.php" class="btn-seleccionar" style="background:#333;">Inicia sesión para comprar</a>
                        <?php elseif ($cupo_restante <= 0): ?>
                            <span style="display:block; text-align:center; background:#252535; color:#777788; padding:12px; border-radius:8px; font-size:0.9rem;">Límite de 5 alcanzado</span>
                        <?php else: ?>
                            <a href="disponibilidad_tickets.php?id_evento=<?php echo urlencode($id_evento); ?>&id_precio=<?php echo urlencode($tk['IDPrecio']); ?>" class="btn-seleccionar">
                                Comprar Entrada
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    <?php else: ?>
        <!-- VISTA INICIAL: LISTADO DE EVENTOS ACTIVOS -->
        <h2 style="color:#fff; margin-bottom:20px;">Cartelera de Eventos Disponibles</h2>

        <?php if (empty($lista_eventos)): ?>
            <div style="background:#161622; padding:30px; text-align:center; border-radius:10px; color:#9e9eb3;">No hay eventos activos programados.</div>
        <?php else: ?>
            <div class="tickets-grid">
                <?php foreach ($lista_eventos as $ev_item): ?>
                    <div class="ticket-card">
                        <div>
                            <span class="evento-tag"><?php echo htmlspecialchars($ev_item['Fec_Inicio_Evento']); ?></span>
                            <h4 class="ticket-tipo" style="margin-top:10px;"><?php echo htmlspecialchars($ev_item['Nombre_Evento']); ?></h4>
                        </div>
                        <a href="disponibilidad_tickets.php?id_evento=<?php echo urlencode(trim($ev_item['NroEvento'])); ?>" class="btn-seleccionar" style="margin-top:15px;">
                            Ver Zonas y Tickets
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<script>
    function toggleMenu() {
        document.getElementById('sidebar').classList.toggle('activo');
        document.getElementById('overlay').classList.toggle('activo');
    }
</script>

</body>
</html>