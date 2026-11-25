<?php
// Activar errores para diagnóstico
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

if (!isset($_SESSION['rol']) || $_SESSION['rol'] !== 'empleado' || $_SESSION['empleado_perfil'] !== 'Administrador') {
    header("Location: login_trabajador.php");
    exit;
}

require_once '../config/conexion.php';
require_once 'permisos.php'; // Valida sesión y mapeo de páginas permitidas por rol

$empleado_nombre = $_SESSION['empleado_nombre'] ?? 'Administrador';
$empleado_perfil = $_SESSION['empleado_perfil'] ?? 'Administrador';
$paginas_permitidas = obtenerPaginasPermitidas($empleado_perfil);

$mensaje = "";
$error = "";

if (isset($_GET['msg']) && $_GET['msg'] === 'registrado') {
    $op_reciente = htmlspecialchars($_GET['op'] ?? '');
    $mensaje = "¡Movimiento de insumo registrado correctamente! Operación: <strong>{$op_reciente}</strong>";
}

$filtro_buscar = trim($_GET['buscar'] ?? '');
$fec_inicio   = trim($_GET['fec_inicio'] ?? '');
$fec_fin      = trim($_GET['fec_fin'] ?? '');

$insumos = [];
$sedes = [];
$empleados = [];
$tipos_operacion = [];
$tipos_facturacion = [];
$sugerencias_ops = [];

try {
    $insumos = $conn->query("SELECT CodInsumo, Nombre_Insumo FROM Tb_Insumo WHERE Estado_Insumo = 'Activo'")->fetchAll(PDO::FETCH_ASSOC);
    $sedes = $conn->query("SELECT CodSede, Sede FROM Tb_Sede WHERE Estado_Sede = 'Activo'")->fetchAll(PDO::FETCH_ASSOC);
    $empleados = $conn->query("SELECT CodEmpleado FROM Tb_Empleado WHERE Estado_Empleado = 'Activo'")->fetchAll(PDO::FETCH_ASSOC);
    $tipos_operacion = $conn->query("SELECT CodTipo_Operacion, Tipo_Operacion FROM Tb_Tipo_Operacion")->fetchAll(PDO::FETCH_ASSOC);
    $tipos_facturacion = $conn->query("SELECT CodTipoFacturacion, Tipo_Facturacion FROM Tb_Tipo_Facturacion")->fetchAll(PDO::FETCH_ASSOC);

    // Obtener listado para la búsqueda predictiva
    $sugerencias_ops = $conn->query("SELECT DISTINCT Nro_Operacion_Insumo FROM Tb_Kardex_Insumo ORDER BY Nro_Operacion_Insumo DESC")->fetchAll(PDO::FETCH_ASSOC);

    $siguiente_operacion = "OPI0000001";
    $stmt_op = $conn->query("SELECT Nro_Operacion_Insumo FROM Tb_Kardex_Insumo");
    $todas_ops = $stmt_op->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($todas_ops)) {
        $max_num = 0;
        foreach ($todas_ops as $op) {
            $num = (int)substr(trim($op['Nro_Operacion_Insumo']), 3);
            if ($num > $max_num) $max_num = $num;
        }
        $siguiente_operacion = "OPI" . str_pad($max_num + 1, 7, "0", STR_PAD_LEFT);
    }
} catch (PDOException $e) {
    $error = "Error al cargar catálogos: " . $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['registrar_movimiento'])) {
    $nro_operacion      = $siguiente_operacion;
    $cod_empleado       = trim($_POST['cod_empleado'] ?? '');
    $cod_tipo_operacion = trim($_POST['cod_tipo_operacion'] ?? '');
    $cod_tipo_fact      = trim($_POST['cod_tipo_fact'] ?? '');
    $nro_serie_fact     = trim($_POST['nro_serie_fact'] ?? 'F001-00001');
    $cod_sede           = trim($_POST['cod_sede'] ?? '');
    $cod_insumo         = (int)($_POST['cod_insumo'] ?? 0);
    $costo_unitario     = (float)($_POST['costo_unitario'] ?? 0);
    $cantidad           = (float)($_POST['cantidad'] ?? 0);
    $observacion        = trim($_POST['observacion'] ?? '');
    if (empty($observacion)) $observacion = 'Ingreso/Salida de almacén de insumos';

    $costo_total = $costo_unitario * $cantidad;

    if (empty($cod_empleado) || empty($cod_tipo_operacion) || empty($cod_sede) || $cod_insumo <= 0 || $cantidad <= 0) {
        $error = "Complete todos los campos obligatorios y asegúrese de que la cantidad sea mayor a 0.";
    } else {
        try {
            $conn->beginTransaction();

            $stmt_stk = $conn->prepare("SELECT Stock_Actual FROM Tb_Almacen_Insumo WHERE CodSede = ? AND CodInsumo = ?");
            $stmt_stk->execute([$cod_sede, $cod_insumo]);
            $almacen = $stmt_stk->fetch(PDO::FETCH_ASSOC);

            if (!$almacen) {
                $sql_init_alm = "INSERT INTO Tb_Almacen_Insumo (CodSede, CodInsumo, Stock_Minimo, Stock_Maximo, Stock_Actual, Estado_Stock_Insumo) VALUES (?, ?, 5, 500, ?, 'Disponible')";
                $conn->prepare($sql_init_alm)->execute([$cod_sede, $cod_insumo, $cantidad]);
            } else {
                $nuevo_stock = (float)$almacen['Stock_Actual'] + $cantidad;
                $sql_upd_alm = "UPDATE Tb_Almacen_Insumo SET Stock_Actual = ? WHERE CodSede = ? AND CodInsumo = ?";
                $conn->prepare($sql_upd_alm)->execute([$nuevo_stock, $cod_sede, $cod_insumo]);
            }

            $sql_kardex = "INSERT INTO Tb_Kardex_Insumo (Nro_Operacion_Insumo, CodEmpleado, CodTipo_Operacion, Fec_Operacion, CodTipoFacturacion, NroSerie_Facturacion, CodSede, CodInsumo, Costo_Unitario, Cantidad, Costo_Total, Obs_TipoOperacion_Insumo) 
                           VALUES (?, ?, ?, GETDATE(), ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt_ins = $conn->prepare($sql_kardex);
            $stmt_ins->execute([
                $nro_operacion, $cod_empleado, $cod_tipo_operacion, $cod_tipo_fact, 
                $nro_serie_fact, $cod_sede, $cod_insumo, $costo_unitario, 
                $cantidad, $costo_total, $observacion
            ]);

            $conn->commit();
            header("Location: kardex_insumos.php?msg=registrado&op=" . urlencode($nro_operacion));
            exit;

        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            $error = "Error SQL al guardar: " . $e->getMessage();
        }
    }
}

// CONSULTAR HISTORIAL CON BÚSQUEDA Y RANGO DE FECHAS
$movimientos = [];
try {
    $sql_mov = "SELECT ki.Nro_Operacion_Insumo, ki.Fec_Operacion, s.Sede, ins.Nombre_Insumo, t_op.Tipo_Operacion, ki.Costo_Unitario, ki.Cantidad, ki.Costo_Total, ki.Obs_TipoOperacion_Insumo, ki.CodEmpleado
                FROM Tb_Kardex_Insumo ki
                LEFT JOIN Tb_Sede s ON ki.CodSede = s.CodSede
                LEFT JOIN Tb_Insumo ins ON ki.CodInsumo = ins.CodInsumo
                LEFT JOIN Tb_Tipo_Operacion t_op ON ki.CodTipo_Operacion = t_op.CodTipo_Operacion
                WHERE 1=1";

    $params = [];

    if (!empty($filtro_buscar)) {
        $sql_mov .= " AND (ki.Nro_Operacion_Insumo LIKE ? OR s.Sede LIKE ? OR ki.CodEmpleado LIKE ?)";
        $params[] = "%" . $filtro_buscar . "%";
        $params[] = "%" . $filtro_buscar . "%";
        $params[] = "%" . $filtro_buscar . "%";
    }

    if (!empty($fec_inicio)) {
        $sql_mov .= " AND CAST(ki.Fec_Operacion AS DATE) >= ?";
        $params[] = $fec_inicio;
    }

    if (!empty($fec_fin)) {
        $sql_mov .= " AND CAST(ki.Fec_Operacion AS DATE) <= ?";
        $params[] = $fec_fin;
    }

    $sql_mov .= " ORDER BY ki.Nro_Operacion_Insumo DESC";

    $stmt_mov = $conn->prepare($sql_mov);
    $stmt_mov->execute($params);
    $movimientos = $stmt_mov->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error .= " | Error al leer el historial: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kardex de Insumos - La Industria</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Segoe UI', system-ui, -apple-system, sans-serif; background-color: #0b0b10; color: #f1f1f5; margin: 0; padding: 0; }
        
        /* Barra de navegación superior unificada */
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

        .container { max-width: 1250px; margin: 35px auto; padding: 0 25px; }
        .btn-volver { display: inline-block; color: #ff6699; text-decoration: none; font-weight: 600; margin-bottom: 20px; }
        .btn-volver:hover { text-decoration: underline; }
        
        .header-flex { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; flex-wrap: wrap; gap: 15px; }
        .seccion-titulo { font-size: 1.4rem; color: #fff; border-left: 4px solid #ff0055; padding-left: 12px; font-weight: 700; margin: 0; }

        .search-card { background: #161622; border: 1px solid #262638; border-radius: 12px; padding: 20px; margin-bottom: 25px; box-shadow: 0 8px 25px rgba(0,0,0,0.4); }
        .search-grid { display: grid; grid-template-columns: 2fr 1fr 1fr 140px; gap: 15px; align-items: flex-end; }
        @media(max-width: 900px) { .search-grid { grid-template-columns: 1fr; } }
        
        .btn-buscar { background: #252535; color: #ff80aa; border: 1px solid rgba(255,0,85,0.4); padding: 10px; border-radius: 8px; font-weight: bold; cursor: pointer; text-align: center; text-decoration: none; display: block; width: 100%; height: 41px; transition: background 0.2s, color 0.2s; }
        .btn-buscar:hover { background: #ff0055; color: #fff; }
        .btn-limpiar { color: #aaa; text-decoration: none; font-size: 0.82rem; margin-top: 6px; display: inline-block; text-align: center; width: 100%; }
        .btn-limpiar:hover { color: #fff; }

        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.75); backdrop-filter: blur(4px); z-index: 3000; justify-content: center; align-items: center; padding: 20px; }
        .modal-overlay.active { display: flex; }
        .modal-content { background: #161622; border: 1px solid rgba(255,0,85,0.4); border-radius: 14px; padding: 30px; width: 100%; max-width: 750px; max-height: 90vh; overflow-y: auto; box-shadow: 0 15px 40px rgba(0,0,0,0.7); position: relative; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #262638; padding-bottom: 12px; margin-bottom: 20px; }
        .modal-header h3 { margin: 0; color: #ff0055; font-size: 1.25rem; }
        .btn-cerrar-modal { background: none; border: none; color: #ff4466; font-size: 1.4rem; font-weight: bold; cursor: pointer; }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 15px; }
        .form-group { margin-bottom: 15px; }
        .form-group.full { grid-column: span 2; }
        .form-group label { display: block; margin-bottom: 6px; color: #ccc; font-size: 0.85rem; font-weight: 600; }
        .form-control { width: 100%; padding: 10px 12px; background: #0f0f17; border: 1px solid #323248; border-radius: 8px; color: #fff; font-size: 0.9rem; outline: none; }
        .form-control:focus { border-color: #ff0055; }
        .btn-accion { background: #ff0055; color: #fff; padding: 11px 22px; border-radius: 6px; border: none; font-weight: bold; font-size: 0.9rem; cursor: pointer; transition: background 0.2s; }
        .btn-accion:hover { background: #d60047; }
        .btn-toggle { background: #ff0055; color: #fff; border: none; padding: 10px 18px; border-radius: 8px; font-weight: bold; cursor: pointer; transition: background 0.2s; }
        .btn-toggle:hover { background: #d60047; }

        .table-container { background: #161622; border: 1px solid #262638; border-radius: 12px; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.5); }
        table { width: 100%; border-collapse: collapse; text-align: left; font-size: 0.86rem; }
        th { background: #1f1f2e; color: #ff80aa; padding: 14px; font-weight: 600; border-bottom: 1px solid #262638; }
        td { padding: 14px; border-bottom: 1px solid #222233; color: #d0d0d8; }
        tr:hover { background: #1a1a29; }
        .alerta-exito { background: rgba(0,255,204,0.12); border: 1px solid #00ffcc; color: #80ffdf; padding: 12px 15px; border-radius: 8px; margin-bottom: 20px; }
        .alerta-error { background: rgba(255,0,85,0.12); border: 1px solid #ff0055; color: #ff80aa; padding: 12px 15px; border-radius: 8px; margin-bottom: 20px; }
        .badge-op { background: rgba(255,0,85,0.15); color: #ff80aa; border: 1px solid #ff0055; padding: 4px 10px; border-radius: 20px; font-size: 0.78rem; font-weight: bold; display: inline-block; }
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
            <li><a href="gestion_mesas.php">🪑 Gestión de Mesas y Aforo</a></li>
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
            <li><a href="kardex_insumos.php" class="activo" style="color: #ff0055;">📋 Kardex de Insumos</a></li>
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
                <li><a href="gestion_mesas.php">Mesas</a></li>
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
    <a href="consultar_proveedores.php" class="btn-volver">&larr; Volver a Consulta de Proveedores</a>

    <div class="header-flex">
        <h2 class="seccion-titulo">Kardex de Insumos</h2>
        <button class="btn-toggle" onclick="abrirModal()">+ Registrar Movimiento</button>
    </div>

    <?php if ($mensaje): ?>
        <div class="alerta-exito"><?php echo $mensaje; ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alerta-error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <!-- TARJETA DE BÚSQUEDA AVANZADA CON PREDICCIÓN HTML5 -->
    <div class="search-card">
        <form method="GET" action="kardex_insumos.php">
            <div class="search-grid">
                <div class="form-group" style="margin-bottom:0;">
                    <label>Buscar (Sede, Nro. Op. o Empleado):</label>
                    <input type="text" name="buscar" list="listaSugerenciasOpsInsumos" class="form-control" placeholder="Ej. Lima, OPI0000001..." value="<?php echo htmlspecialchars($filtro_buscar); ?>" autocomplete="off">
                    <!-- Datalist para búsqueda predictiva de operaciones de insumos -->
                    <datalist id="listaSugerenciasOpsInsumos">
                        <?php foreach ($sugerencias_ops as $sug): ?>
                            <option value="<?php echo htmlspecialchars(trim($sug['Nro_Operacion_Insumo'])); ?>">Operación: <?php echo htmlspecialchars(trim($sug['Nro_Operacion_Insumo'])); ?></option>
                        <?php endforeach; ?>
                    </datalist>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label>Fecha Inicio:</label>
                    <input type="date" name="fec_inicio" class="form-control" value="<?php echo htmlspecialchars($fec_inicio); ?>">
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label>Fecha Fin:</label>
                    <input type="date" name="fec_fin" class="form-control" value="<?php echo htmlspecialchars($fec_fin); ?>">
                </div>
                <div>
                    <button type="submit" class="btn-buscar">🔍 Filtrar</button>
                    <a href="kardex_insumos.php" class="btn-limpiar">Limpiar filtros</a>
                </div>
            </div>
        </form>
    </div>

    <!-- MODAL DE REGISTRO -->
    <div class="modal-overlay" id="modalKardexInsumo">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Registrar Movimiento en Kardex de Insumos</h3>
                <button class="btn-cerrar-modal" onclick="cerrarModal()">&times;</button>
            </div>
            <form method="POST" action="kardex_insumos.php">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Nro. Operación:</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($siguiente_operacion); ?>" disabled style="background: #12121c; color: #ff80aa; font-weight: bold;">
                    </div>
                    <div class="form-group">
                        <label>Empleado Responsable:</label>
                        <select name="cod_empleado" class="form-control" required>
                            <option value="">-- Seleccione --</option>
                            <?php foreach ($empleados as $emp): ?>
                                <option value="<?php echo htmlspecialchars(trim($emp['CodEmpleado'])); ?>"><?php echo htmlspecialchars(trim($emp['CodEmpleado'])); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Sede:</label>
                        <select name="cod_sede" class="form-control" required>
                            <option value="">-- Seleccione Sede --</option>
                            <?php foreach ($sedes as $s): ?>
                                <option value="<?php echo htmlspecialchars(trim($s['CodSede'])); ?>"><?php echo htmlspecialchars($s['Sede']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group full">
                        <label>Insumo:</label>
                        <select name="cod_insumo" class="form-control" required>
                            <option value="">-- Seleccione Insumo --</option>
                            <?php foreach ($insumos as $ins): ?>
                                <option value="<?php echo htmlspecialchars($ins['CodInsumo']); ?>"><?php echo htmlspecialchars($ins['Nombre_Insumo']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Tipo de Operación:</label>
                        <select name="cod_tipo_operacion" class="form-control" required>
                            <option value="">-- Seleccione --</option>
                            <?php foreach ($tipos_operacion as $to): ?>
                                <option value="<?php echo htmlspecialchars(trim($to['CodTipo_Operacion'])); ?>"><?php echo htmlspecialchars($to['Tipo_Operacion']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Tipo de Facturación:</label>
                        <select name="cod_tipo_fact" class="form-control" required>
                            <option value="">-- Seleccione --</option>
                            <?php foreach ($tipos_facturacion as $tf): ?>
                                <option value="<?php echo htmlspecialchars(trim($tf['CodTipoFacturacion'])); ?>"><?php echo htmlspecialchars($tf['Tipo_Facturacion']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Nro. Serie / Comprobante:</label>
                        <input type="text" name="nro_serie_fact" class="form-control" value="F001-00001" required maxlength="11">
                    </div>
                    <div class="form-group">
                        <label>Costo Unitario (S/.):</label>
                        <input type="number" step="0.01" name="costo_unitario" class="form-control" required placeholder="0.00">
                    </div>
                    <div class="form-group">
                        <label>Cantidad:</label>
                        <input type="number" step="0.01" name="cantidad" class="form-control" required placeholder="0.00">
                    </div>
                    <div class="form-group full">
                        <label>Observación:</label>
                        <input type="text" name="observacion" class="form-control" placeholder="Detalle de la operación...">
                    </div>
                </div>
                <div style="margin-top: 20px; display: flex; justify-content: flex-end; gap: 10px;">
                    <button type="button" onclick="cerrarModal()" style="background: #252535; color: #aaa; border: none; padding: 11px 18px; border-radius: 6px; cursor: pointer; font-weight: bold;">Cancelar</button>
                    <button type="submit" name="registrar_movimiento" class="btn-accion">Guardar Movimiento</button>
                </div>
            </form>
        </div>
    </div>

    <!-- TABLA DE MOVIMIENTOS -->
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Nro Operación</th>
                    <th>Fecha</th>
                    <th>Empleado</th>
                    <th>Sede</th>
                    <th>Insumo</th>
                    <th>Operación</th>
                    <th>Costo Unit.</th>
                    <th>Cantidad</th>
                    <th>Costo Total</th>
                    <th>Observación</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($movimientos)): ?>
                    <tr>
                        <td colspan="10" style="text-align: center; color: #8888a0; padding: 30px;">No hay movimientos de insumos registrados o que coincidan con los filtros de búsqueda y fecha.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($movimientos as $mov): ?>
                        <tr>
                            <td><span class="badge-op"><?php echo htmlspecialchars(trim($mov['Nro_Operacion_Insumo'])); ?></span></td>
                            <td><?php echo htmlspecialchars($mov['Fec_Operacion']); ?></td>
                            <td><?php echo htmlspecialchars($mov['CodEmpleado']); ?></td>
                            <td><?php echo htmlspecialchars($mov['Sede'] ?? 'N/D'); ?></td>
                            <td><?php echo htmlspecialchars($mov['Nombre_Insumo'] ?? 'N/D'); ?></td>
                            <td><?php echo htmlspecialchars($mov['Tipo_Operacion'] ?? 'N/D'); ?></td>
                            <td>S/. <?php echo number_format($mov['Costo_Unitario'], 2); ?></td>
                            <td><?php echo number_format($mov['Cantidad'], 2); ?></td>
                            <td><strong>S/. <?php echo number_format($mov['Costo_Total'], 2); ?></strong></td>
                            <td><?php echo htmlspecialchars($mov['Obs_TipoOperacion_Insumo']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
    function toggleMenu() {
        document.getElementById('sidebar').classList.toggle('active');
        document.getElementById('overlay').classList.toggle('active');
    }

    function abrirModal() {
        document.getElementById('modalKardexInsumo').classList.add('active');
    }
    
    function cerrarModal() {
        document.getElementById('modalKardexInsumo').classList.remove('active');
    }

    window.onclick = function(event) {
        let modal = document.getElementById('modalKardexInsumo');
        if (event.target === modal) {
            cerrarModal();
        }
    }
</script>

</body>
</html>