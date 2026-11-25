<?php
// Activar errores para diagnóstico
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

require_once '../config/conexion.php';
require_once 'permisos.php'; // Valida sesión y mapeo de páginas permitidas por rol

$empleado_nombre = $_SESSION['empleado_nombre'] ?? 'Administrador';
$empleado_perfil = $_SESSION['empleado_perfil'] ?? 'Administrador';
$paginas_permitidas = obtenerPaginasPermitidas($empleado_perfil);

$mensaje = "";
$error = "";

// PROCESAR ACCIONES (Registrar nueva mesa, actualizar estado)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = trim($_POST['accion'] ?? '');

    try {
        if ($accion === 'registrar_mesa') {
            $nro_mesa    = strtoupper(trim($_POST['NroMesa'] ?? ''));
            $descripcion = trim($_POST['Descripcion_Mesa'] ?? '');
            $nro_sillas  = (int)($_POST['Nro_Sillas'] ?? 0);
            $cod_sede    = trim($_POST['CodSede'] ?? 'SED01');
            $estado      = trim($_POST['Estado_Mesa'] ?? 'Disponible');

            if (empty($nro_mesa) || empty($descripcion) || $nro_sillas <= 0 || empty($cod_sede)) {
                throw new Exception("Complete todos los campos obligatorios y asegúrese de que el número de sillas sea mayor a 0.");
            }

            if (strlen($nro_mesa) > 8) {
                throw new Exception("El código de mesa (NroMesa) no puede exceder los 8 caracteres.");
            }

            $stmtCheck = $conn->prepare("SELECT COUNT(*) FROM Tb_Mesa WHERE NroMesa = ?");
            $stmtCheck->execute([$nro_mesa]);
            if ($stmtCheck->fetchColumn() > 0) {
                throw new Exception("El código de mesa '$nro_mesa' ya se encuentra registrado.");
            }

            $stmtInsert = $conn->prepare("INSERT INTO Tb_Mesa (NroMesa, Descripcion_Mesa, Nro_Sillas, CodSede, Estado_Mesa) VALUES (?, ?, ?, ?, ?)");
            $stmtInsert->execute([$nro_mesa, $descripcion, $nro_sillas, $cod_sede, $estado]);

            $mensaje = "¡Mesa '$nro_mesa' registrada correctamente en la sede $cod_sede!";
        } elseif ($accion === 'cambiar_estado') {
            $nro_mesa     = trim($_POST['NroMesa'] ?? '');
            $nuevo_estado = trim($_POST['Estado_Mesa'] ?? '');

            if (empty($nro_mesa) || empty($nuevo_estado)) {
                throw new Exception("Datos incompletos para actualizar el estado de la mesa.");
            }

            $stmtUpdate = $conn->prepare("UPDATE Tb_Mesa SET Estado_Mesa = ? WHERE NroMesa = ?");
            $stmtUpdate->execute([$nuevo_estado, $nro_mesa]);

            $mensaje = "Estado de la mesa '$nro_mesa' actualizado a '$nuevo_estado'.";
        }
    } catch (Exception $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// OBTENER SEDES DISPONIBLES
$sedes = [];
try {
    $sedes = $conn->query("SELECT CodSede, Sede FROM Tb_Sede WHERE Estado_Sede = 'Activo'")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $sedes = [['CodSede' => 'SED01', 'Sede' => 'Sede Principal']];
}

// FILTROS
$filtro_sede   = trim($_GET['sede'] ?? '');
$filtro_estado = trim($_GET['estado'] ?? '');

$mesas = [];
try {
    $sql = "SELECT m.NroMesa, m.Descripcion_Mesa, m.Nro_Sillas, m.CodSede, s.Sede, m.Estado_Mesa,
                   (SELECT COUNT(*) FROM Tb_Pedido p WHERE p.NroMesa = m.NroMesa AND p.Estado_Pedido IN ('Pendiente', 'En Proceso')) AS PedidosActivos
            FROM Tb_Mesa m 
            LEFT JOIN Tb_Sede s ON m.CodSede = s.CodSede 
            WHERE 1=1";
    $params = [];

    if ($filtro_sede !== '') {
        $sql .= " AND m.CodSede = ?";
        $params[] = $filtro_sede;
    }
    if ($filtro_estado !== '') {
        $sql .= " AND m.Estado_Mesa = ?";
        $params[] = $filtro_estado;
    }

    $sql .= " ORDER BY m.CodSede ASC, m.NroMesa ASC";
    $stmtMesas = $conn->prepare($sql);
    $stmtMesas->execute($params);
    $mesas = $stmtMesas->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $error = "No se pudieron cargar las mesas: " . $e->getMessage();
}

// MÉTRICAS DE AFORO
$total_mesas = count($mesas);
$disponibles = 0;
$ocupadas    = 0;
$reservadas  = 0;
$aforo_total = 0;

foreach ($mesas as $m) {
    $aforo_total += (int)$m['Nro_Sillas'];
    $est = trim($m['Estado_Mesa']);
    if ($est === 'Disponible') $disponibles++;
    elseif ($est === 'Ocupada') $ocupadas++;
    elseif ($est === 'Reservada') $reservadas++;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Mesas y Aforo - La Industria Surco</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Segoe UI', system-ui, -apple-system, sans-serif; background-color: #0b0b10; color: #f1f1f5; margin: 0; padding: 0; }
        
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
        .btn-logout-top {
            background: #252535;
            color: #ff80aa;
            padding: 8px 18px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.88rem;
            transition: background 0.2s, color 0.2s;
        }
        .btn-logout-top:hover { background: #ff0055; color: #fff; }
        .user-greeting { font-size: 0.9rem; color: #ccc; }
        .user-greeting span { color: #00ffcc; font-weight: bold; }

        /* Menú Lateral (Sidebar) unificado */
        .sidebar {
            position: fixed; top: 0; left: -310px; width: 310px; height: 100%;
            background-color: #12121c; border-right: 1px solid rgba(255, 0, 85, 0.2);
            box-shadow: 10px 0 30px rgba(0,0,0,0.8); z-index: 2000;
            transition: left 0.3s ease; padding: 25px 20px; overflow-y: auto;
            display: flex; flex-direction: column;
        }
        .sidebar.active { left: 0; }
        .sidebar-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #262638; padding-bottom: 15px; margin-bottom: 20px; }
        .sidebar-title { font-weight: 800; font-size: 1.1rem; color: #fff; }
        .sidebar-title span { color: #ff0055; }
        .close-btn { background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #fff; }
        
        .sidebar-section-title {
            font-size: 0.72rem; text-transform: uppercase; letter-spacing: 1px;
            color: #777788; margin: 16px 0 8px 10px; font-weight: bold;
        }
        .sidebar-menu { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 6px; }
        .sidebar-menu a {
            text-decoration: none; color: #c0c0d0; font-size: 0.9rem; font-weight: 600;
            display: block; padding: 9px 14px; border-radius: 8px; transition: all 0.2s;
        }
        .sidebar-menu a:hover { background: rgba(255, 0, 85, 0.15); color: #ff0055; padding-left: 18px; }

        .overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); backdrop-filter: blur(3px); z-index: 1500; display: none; }
        .overlay.active { display: block; }

        .container { max-width: 1350px; margin: 35px auto; padding: 0 30px; }
        .btn-volver { display: inline-block; color: #ff6699; text-decoration: none; font-weight: 600; margin-bottom: 20px; }
        .btn-volver:hover { text-decoration: underline; }

        .seccion-titulo {
            font-size: 1.4rem; color: #fff; border-left: 4px solid #ff0055;
            padding-left: 12px; font-weight: 700; margin: 0 0 25px 0;
        }

        .kpi-grid {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 18px; margin-bottom: 30px;
        }
        .kpi-card {
            background: #161622; border-radius: 12px; padding: 20px;
            border: 1px solid #262638; box-shadow: 0 8px 25px rgba(0,0,0,0.4);
            display: flex; justify-content: space-between; align-items: center;
        }
        .kpi-info span { font-size: 0.8rem; color: #9e9eb3; text-transform: uppercase; font-weight: 600; }
        .kpi-info h2 { font-size: 1.9rem; margin: 6px 0 0 0; color: #fff; }
        .kpi-icon { font-size: 2.2rem; }

        .card-panel {
            background: #161622; border: 1px solid #262638; border-radius: 12px;
            padding: 25px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); margin-bottom: 30px;
        }
        .card-panel h3 {
            margin-top: 0; color: #ff0055; font-size: 1.1rem;
            border-bottom: 1px solid #262638; padding-bottom: 10px; margin-bottom: 20px;
        }
        .form-grid {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 18px;
        }
        .form-group label { display: block; margin-bottom: 6px; color: #ccc; font-size: 0.85rem; font-weight: 600; }
        .form-control {
            width: 100%; padding: 10px 12px; background: #0f0f17; border: 1px solid #323248;
            border-radius: 8px; color: #fff; font-size: 0.9rem; outline: none;
        }
        .form-control:focus { border-color: #ff0055; }

        .btn-accion {
            background: #ff0055; color: #fff; border: none; padding: 11px 22px;
            border-radius: 8px; font-weight: bold; cursor: pointer; font-size: 0.92rem; transition: 0.2s;
        }
        .btn-accion:hover { background: #d60047; }

        .alerta-exito { background: rgba(0,255,204,0.12); border: 1px solid #00ffcc; color: #80ffdf; padding: 12px 15px; border-radius: 8px; margin-bottom: 20px; }
        .alerta-error { background: rgba(255,0,85,0.12); border: 1px solid #ff0055; color: #ff80aa; padding: 12px 15px; border-radius: 8px; margin-bottom: 20px; }

        .mesas-grid {
            display: grid; grid-template-columns: repeat(auto-fill, minmax(270px, 1fr));
            gap: 20px; margin-bottom: 35px;
        }
        .mesa-box {
            background: #161622; border: 1px solid #262638; border-radius: 12px;
            padding: 20px; box-shadow: 0 8px 25px rgba(0,0,0,0.4);
            display: flex; flex-direction: column; justify-content: space-between;
            transition: transform 0.2s, border-color 0.2s;
        }
        .mesa-box:hover { transform: translateY(-3px); border-color: #454566; }
        .mesa-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
        .mesa-codigo { font-size: 1.2rem; font-weight: 800; color: #fff; }
        .mesa-sede { font-size: 0.78rem; color: #9e9eb3; background: #0f0f17; padding: 3px 8px; border-radius: 6px; border: 1px solid #262638; }
        .mesa-desc { font-size: 0.95rem; color: #d0d0e0; margin-bottom: 6px; font-weight: 600; }
        .mesa-sillas { font-size: 0.85rem; color: #9e9eb3; margin-bottom: 12px; }
        
        .alerta-pedido-cliente {
            background: rgba(255, 170, 0, 0.12); border: 1px solid #ffaa00; color: #ffcc66;
            padding: 6px 10px; border-radius: 6px; font-size: 0.78rem; font-weight: bold; margin-bottom: 12px;
        }

        .badge { padding: 4px 10px; border-radius: 20px; font-size: 0.78rem; font-weight: bold; display: inline-block; margin-bottom: 15px; }
        .estado-Disponible { background: rgba(0, 255, 204, 0.15); color: #00ffcc; border: 1px solid #00ffcc; }
        .estado-Ocupada { background: rgba(255, 0, 85, 0.15); color: #ff6699; border: 1px solid #ff0055; }
        .estado-Reservada { background: rgba(255, 170, 0, 0.15); color: #ffaa00; border: 1px solid #ffaa00; }
        .estado-Mantenimiento { background: rgba(150, 150, 170, 0.15); color: #b0b0c0; border: 1px solid #777; }

        .estado-form { display: flex; gap: 8px; margin-top: auto; border-top: 1px solid #262638; padding-top: 12px; }
        .estado-select {
            flex: 1; padding: 7px 10px; background: #0f0f17; border: 1px solid #323248;
            border-radius: 6px; color: #fff; font-size: 0.82rem; outline: none;
        }
        .btn-mini {
            background: #252538; color: #00ffcc; border: 1px solid rgba(0, 255, 204, 0.3);
            padding: 7px 12px; border-radius: 6px; font-size: 0.8rem; font-weight: bold; cursor: pointer;
        }
        .btn-mini:hover { background: #00ffcc; color: #0b0b10; }
    </style>
</head>
<body>

<!-- Menú Lateral (Sidebar) unificado -->
<div class="overlay" id="overlay" onclick="toggleMenu()"></div>
<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="sidebar-title">LA INDUSTRIA <span>ADMIN</span></div>
        <button class="close-btn" onclick="toggleMenu()">&times;</button>
    </div>
    
    <div class="sidebar-section-title">Panel Gerencial</div>
    <ul class="sidebar-menu">
        <?php if (in_array('panel_control.php', $paginas_permitidas)): ?>
            <li><a href="panel_control.php">📊 Inicio / Dashboard</a></li>
        <?php endif; ?>
        <?php if (in_array('gestion_trabajadores.php', $paginas_permitidas)): ?>
            <li><a href="gestion_trabajadores.php">👥 Gestión de Trabajadores</a></li>
        <?php endif; ?>
        <?php if (in_array('nuevo_trabajador.php', $paginas_permitidas)): ?>
            <li><a href="nuevo_trabajador.php">➕ Nuevo Trabajador</a></li>
        <?php endif; ?>
        <?php if (in_array('consultar_proveedores.php', $paginas_permitidas)): ?>
            <li><a href="consultar_proveedores.php">🚚 Proveedores</a></li>
        <?php endif; ?>
        <?php if (in_array('consultar_sedes.php', $paginas_permitidas)): ?>
            <li><a href="consultar_sedes.php">🏢 Sedes</a></li>
        <?php endif; ?>
    </ul>

    <div class="sidebar-section-title">Logística y Compras</div>
    <ul class="sidebar-menu">
        <?php if (in_array('registrar_orden_compra_producto.php', $paginas_permitidas)): ?>
            <li><a href="registrar_orden_compra_producto.php">🛒 Orden Compra (Productos)</a></li>
        <?php endif; ?>
        <?php if (in_array('generar_orden_compra_insumo.php', $paginas_permitidas)): ?>
            <li><a href="generar_orden_compra_insumo.php">📦 Orden Compra (Insumos)</a></li>
        <?php endif; ?>
        <?php if (in_array('aprobar_ordenes_compra.php', $paginas_permitidas)): ?>
            <li><a href="aprobar_ordenes_compra.php">✅ Aprobar Órdenes Compra</a></li>
        <?php endif; ?>
        <?php if (in_array('registrar_recepcion_compra.php', $paginas_permitidas)): ?>
            <li><a href="registrar_recepcion_compra.php">📥 Recepción de Compras</a></li>
        <?php endif; ?>
        <?php if (in_array('consultar_ordenes_compra_producto.php', $paginas_permitidas)): ?>
            <li><a href="consultar_ordenes_compra_producto.php">🔎 Consultar Órdenes</a></li>
        <?php endif; ?>
        <?php if (in_array('consultar_detalles_compras.php', $paginas_permitidas)): ?>
            <li><a href="consultar_detalles_compras.php">📄 Detalles de Compras</a></li>
        <?php endif; ?>
    </ul>

    <div class="sidebar-section-title">Inventario y Operaciones</div>
    <ul class="sidebar-menu">
        <?php if (in_array('gestion_mesas.php', $paginas_permitidas)): ?>
            <li><a href="gestion_mesas.php" class="activo" style="color: #ff0055;">🪑 Gestión de Mesas y Aforo</a></li>
        <?php endif; ?>
        <?php if (in_array('gestion_productos.php', $paginas_permitidas)): ?>
            <li><a href="gestion_productos.php">🍸 Gestión de Productos</a></li>
        <?php endif; ?>
        <?php if (in_array('gestion_tickets.php', $paginas_permitidas)): ?>
            <li><a href="gestion_tickets.php">🎟️ Gestión de Tickets</a></li>
        <?php endif; ?>
        <?php if (in_array('verificar_stock.php', $paginas_permitidas)): ?>
            <li><a href="verificar_stock.php">⚠️ Verificar Stock</a></li>
        <?php endif; ?>
        <?php if (in_array('kardex_productos.php', $paginas_permitidas)): ?>
            <li><a href="kardex_productos.php">📋 Kardex de Productos</a></li>
        <?php endif; ?>
        <?php if (in_array('kardex_insumos.php', $paginas_permitidas)): ?>
            <li><a href="kardex_insumos.php">📋 Kardex de Insumos</a></li>
        <?php endif; ?>
    </ul>

    <div class="sidebar-section-title">Reportes y Estadísticas</div>
    <ul class="sidebar-menu">
        <?php if (in_array('reporte_ingresos_pagos.php', $paginas_permitidas)): ?>
            <li><a href="reporte_ingresos_pagos.php">📈 Ingresos y Pagos</a></li>
        <?php endif; ?>
        <?php if (in_array('reporte_inventario_productos.php', $paginas_permitidas)): ?>
            <li><a href="reporte_inventario_productos.php">📊 Inventario Productos</a></li>
        <?php endif; ?>
        <?php if (in_array('reporte_inventario_insumos.php', $paginas_permitidas)): ?>
            <li><a href="reporte_inventario_insumos.php">📊 Inventario Insumos</a></li>
        <?php endif; ?>
        <?php if (in_array('reporte_ventas_bebidas.php', $paginas_permitidas)): ?>
            <li><a href="reporte_ventas_bebidas.php">📑 Ventas de Bebidas</a></li>
        <?php endif; ?>
        <?php if (in_array('reporte_ventas_tickets.php', $paginas_permitidas)): ?>
            <li><a href="reporte_ventas_tickets.php">📑 Ventas de Tickets</a></li>
        <?php endif; ?>
    </ul>

    <div class="sidebar-section-title">Sesión</div>
    <ul class="sidebar-menu">
        <li><a href="cerrar_sesion.php" style="color: #ff6699;">🚪 Cerrar Sesión</a></li>
    </ul>
</div>

<!-- Barra Superior Unificada -->
<nav>
    <div class="nav-left">
        <button class="menu-btn" onclick="toggleMenu()"><span></span><span></span><span></span></button>
        <a href="panel_control.php" class="logo">LA INDUSTRIA <span>ADMIN</span></a>
        <ul class="nav-links">
            <?php if (in_array('panel_control.php', $paginas_permitidas)): ?>
                <li><a href="panel_control.php">Panel</a></li>
            <?php endif; ?>
            <?php if (in_array('gestion_mesas.php', $paginas_permitidas)): ?>
                <li><a href="gestion_mesas.php" class="activo" style="color: #ff0055;">Mesas</a></li>
            <?php endif; ?>
            <?php if (in_array('registrar_orden_compra_producto.php', $paginas_permitidas)): ?>
                <li><a href="registrar_orden_compra_producto.php">Compras</a></li>
            <?php endif; ?>
            <?php if (in_array('registrar_recepcion_compra.php', $paginas_permitidas)): ?>
                <li><a href="registrar_recepcion_compra.php">Recepción</a></li>
            <?php endif; ?>
            <?php if (in_array('kardex_productos.php', $paginas_permitidas)): ?>
                <li><a href="kardex_productos.php">Kardex</a></li>
            <?php endif; ?>
            <?php if (in_array('reporte_ingresos_pagos.php', $paginas_permitidas)): ?>
                <li><a href="reporte_ingresos_pagos.php">Reportes</a></li>
            <?php endif; ?>
        </ul>
    </div>
    <div class="nav-right">
        <div class="user-greeting">Admin: <span><?php echo htmlspecialchars($empleado_nombre); ?></span></div>
        <a href="cerrar_sesion.php" class="btn-logout-top">Cerrar Sesión</a>
    </div>
</nav>

<div class="container">
    <a href="panel_control.php" class="btn-volver">&larr; Volver al Panel de Control</a>
    <h2 class="seccion-titulo">Control de Mesas, Boxes y Aforo en Tiempo Real</h2>

    <?php if ($mensaje): ?><div class="alerta-exito"><?php echo htmlspecialchars($mensaje); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alerta-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

    <!-- RESUMEN DE AFORO -->
    <div class="kpi-grid">
        <div class="kpi-card">
            <div class="kpi-info">
                <span>Total Mesas / Boxes</span>
                <h2><?php echo $total_mesas; ?></h2>
            </div>
            <div class="kpi-icon">🪑</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-info">
                <span>Disponibles</span>
                <h2 style="color: #00ffcc;"><?php echo $disponibles; ?></h2>
            </div>
            <div class="kpi-icon">✅</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-info">
                <span>Ocupadas / Reservadas</span>
                <h2 style="color: #ff6699;"><?php echo ($ocupadas + $reservadas); ?></h2>
            </div>
            <div class="kpi-icon">🥂</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-info">
                <span>Capacidad Total (Sillas)</span>
                <h2 style="color: #ffaa00;"><?php echo $aforo_total; ?></h2>
            </div>
            <div class="kpi-icon">👥</div>
        </div>
    </div>

    <!-- FORMULARIO PARA REGISTRAR NUEVA MESA -->
    <div class="card-panel">
        <h3>➕ Registrar Nueva Mesa o Box</h3>
        <form method="POST" action="gestion_mesas.php">
            <input type="hidden" name="accion" value="registrar_mesa">
            <div class="form-grid">
                <div class="form-group">
                    <label>Código Mesa (Máx. 8 car.):</label>
                    <input type="text" name="NroMesa" maxlength="8" class="form-control" required placeholder="Ej. MESA-01">
                </div>
                <div class="form-group">
                    <label>Descripción / Zona:</label>
                    <input type="text" name="Descripcion_Mesa" maxlength="40" class="form-control" required placeholder="Ej. Box VIP Zona Norte">
                </div>
                <div class="form-group">
                    <label>Nro. de Sillas / Capacidad:</label>
                    <input type="number" name="Nro_Sillas" min="1" max="50" class="form-control" required placeholder="Ej. 6">
                </div>
                <div class="form-group">
                    <label>Sede:</label>
                    <select name="CodSede" class="form-control" required>
                        <?php foreach ($sedes as $s): ?>
                            <option value="<?php echo trim($s['CodSede']); ?>">
                                <?php echo htmlspecialchars(trim($s['Sede'])); ?> (<?php echo trim($s['CodSede']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Estado Inicial:</label>
                    <select name="Estado_Mesa" class="form-control" required>
                        <option value="Disponible">Disponible</option>
                        <option value="Reservada">Reservada</option>
                        <option value="Ocupada">Ocupada</option>
                        <option value="Mantenimiento">Mantenimiento</option>
                    </select>
                </div>
            </div>
            <div style="margin-top: 18px; display: flex; justify-content: flex-end;">
                <button type="submit" class="btn-accion">Guardar Mesa</button>
            </div>
        </form>
    </div>

    <!-- FILTROS DE VISUALIZACIÓN -->
    <div class="card-panel" style="padding: 18px 25px;">
        <form method="GET" action="gestion_mesas.php" style="display: flex; flex-wrap: wrap; gap: 15px; align-items: flex-end;">
            <div class="form-group" style="min-width: 200px;">
                <label>Filtrar por Sede:</label>
                <select name="sede" class="form-control">
                    <option value="">Todas las Sedes</option>
                    <?php foreach ($sedes as $s): ?>
                        <option value="<?php echo trim($s['CodSede']); ?>" <?php echo ($filtro_sede === trim($s['CodSede'])) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars(trim($s['Sede'])); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="min-width: 200px;">
                <label>Filtrar por Estado:</label>
                <select name="estado" class="form-control">
                    <option value="">Todos los Estados</option>
                    <option value="Disponible" <?php echo ($filtro_estado === 'Disponible') ? 'selected' : ''; ?>>Disponible</option>
                    <option value="Ocupada" <?php echo ($filtro_estado === 'Ocupada') ? 'selected' : ''; ?>>Ocupada</option>
                    <option value="Reservada" <?php echo ($filtro_estado === 'Reservada') ? 'selected' : ''; ?>>Reservada</option>
                    <option value="Mantenimiento" <?php echo ($filtro_estado === 'Mantenimiento') ? 'selected' : ''; ?>>Mantenimiento</option>
                </select>
            </div>
            <div>
                <button type="submit" class="btn-accion" style="background: #252538; border: 1px solid #454566;">Filtrar</button>
                <a href="gestion_mesas.php" style="color: #9e9eb3; text-decoration: none; font-size: 0.88rem; margin-left: 12px;">Limpiar</a>
            </div>
        </form>
    </div>

    <!-- MAPA DE MESAS REGISTRADAS -->
    <h3 style="color: #fff; margin-bottom: 18px;">Salón y Distribución de Mesas</h3>
    <?php if (empty($mesas)): ?>
        <div class="card-panel" style="text-align: center; color: #9e9eb3;">
            No se encontraron mesas registradas con los criterios seleccionados.
        </div>
    <?php else: ?>
        <div class="mesas-grid">
            <?php foreach ($mesas as $m): 
                $nro_mesa      = trim($m['NroMesa']);
                $estado_actual = trim($m['Estado_Mesa']);
                $tiene_pedido  = (int)($m['PedidosActivos'] ?? 0) > 0;
            ?>
                <div class="mesa-box">
                    <div>
                        <div class="mesa-header">
                            <span class="mesa-codigo"><?php echo htmlspecialchars($nro_mesa); ?></span>
                            <span class="mesa-sede"><?php echo htmlspecialchars(trim($m['Sede'] ?? $m['CodSede'])); ?></span>
                        </div>
                        <div class="mesa-desc"><?php echo htmlspecialchars(trim($m['Descripcion_Mesa'])); ?></div>
                        <div class="mesa-sillas">👥 Capacidad: <strong><?php echo (int)$m['Nro_Sillas']; ?> sillas</strong></div>

                        <?php if ($tiene_pedido): ?>
                            <div class="alerta-pedido-cliente">
                                🔔 Ocupada por Pedido Activo de Cliente
                            </div>
                        <?php endif; ?>

                        <span class="badge estado-<?php echo htmlspecialchars($estado_actual); ?>">
                            Estado: <?php echo htmlspecialchars($estado_actual); ?>
                        </span>
                    </div>

                    <form method="POST" action="gestion_mesas.php" class="estado-form">
                        <input type="hidden" name="accion" value="cambiar_estado">
                        <input type="hidden" name="NroMesa" value="<?php echo htmlspecialchars($nro_mesa); ?>">
                        <select name="Estado_Mesa" class="estado-select">
                            <option value="Disponible" <?php echo ($estado_actual === 'Disponible') ? 'selected' : ''; ?>>Disponible</option>
                            <option value="Ocupada" <?php echo ($estado_actual === 'Ocupada') ? 'selected' : ''; ?>>Ocupada</option>
                            <option value="Reservada" <?php echo ($estado_actual === 'Reservada') ? 'selected' : ''; ?>>Reservada</option>
                            <option value="Mantenimiento" <?php echo ($estado_actual === 'Mantenimiento') ? 'selected' : ''; ?>>Mantenimiento</option>
                        </select>
                        <button type="submit" class="btn-mini">Actualizar</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<script>
    function toggleMenu() {
        document.getElementById('sidebar').classList.toggle('active');
        document.getElementById('overlay').classList.toggle('active');
    }
</script>

</body>
</html>