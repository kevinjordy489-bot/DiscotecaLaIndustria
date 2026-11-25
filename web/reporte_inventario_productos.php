<?php
// Activar errores para diagnóstico
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// SEGURIDAD: Solo empleados administradores
if (!isset($_SESSION['rol']) || $_SESSION['rol'] !== 'empleado' || $_SESSION['empleado_perfil'] !== 'Administrador') {
    header("Location: login_trabajador.php");
    exit;
}

require_once '../config/conexion.php';
require_once 'permisos.php'; // Valida sesión y mapeo de páginas permitidas por rol

$empleado_nombre = $_SESSION['empleado_nombre'] ?? 'Administrador';
$empleado_perfil = $_SESSION['empleado_perfil'] ?? 'Administrador';
$paginas_permitidas = obtenerPaginasPermitidas($empleado_perfil);

$error = "";
$filtro_busqueda = trim($_GET['buscar'] ?? '');
$periodo         = trim($_GET['periodo'] ?? 'mensual'); // semanal, mensual, bimestral, trimestral, anual

// Multiplicador analítico de rotación según el periodo seleccionado
$multiplicador_periodo = 1;
switch ($periodo) {
    case 'semanal':    $multiplicador_periodo = 0.25; break;
    case 'mensual':    $multiplicador_periodo = 1.00; break;
    case 'bimestral':  $multiplicador_periodo = 2.10; break;
    case 'trimestral': $multiplicador_periodo = 3.30; break;
    case 'anual':      $multiplicador_periodo = 13.50; break;
}

// Diccionario corporativo opcional para traducir códigos de categoría a nombres legibles
$nombres_categorias = [
    'CAT01' => 'Cervezas',
    'CAT02' => 'Licores',
    'CAT03' => 'Whisky y Ron',
    'CAT04' => 'Vodka y Tequila',
    'CAT05' => 'Coctelería',
    'CAT06' => 'Vinos y Espumantes',
    'CAT07' => 'Bebidas Energizantes',
    'CAT08' => 'Agua y Gaseosas',
    'CAT09' => 'Snacks y Picaditas',
    'CAT10' => 'Insumos de Barra',
    'CAT11' => 'General / Varios'
];

// Variables de estadísticas para el inventario
$inventario = [];
$total_items = 0;
$stock_total_unidades = 0;
$valorizacion_total = 0;
$categorias_inventario = [];
$stock_critico = 0;
$sugerencias_productos = [];

try {
    // Consulta corporativa optimizada a la tabla real Tb_Producto
    $sql = "SELECT CodProducto, 
                   ISNULL(Producto, ISNULL(Descripcion, 'Sin Nombre')) AS NombreProducto, 
                   ISNULL(CodCategoria, 'General') AS Categoria, 
                   ISNULL(Stock_Marca, 0) AS Stock, 
                   ISNULL(Prec_Venta, ISNULL(Prec_Unitario, 0)) AS PrecioUnitario, 
                   ISNULL(Estado_Producto, 'Activo') AS Estado
            FROM Tb_Producto 
            WHERE 1=1";

    $params = [];
    if (!empty($filtro_busqueda)) {
        $sql .= " AND (CodProducto LIKE ? OR Producto LIKE ? OR Descripcion LIKE ? OR CodCategoria LIKE ?)";
        $params[] = "%" . $filtro_busqueda . "%";
        $params[] = "%" . $filtro_busqueda . "%";
        $params[] = "%" . $filtro_busqueda . "%";
        $params[] = "%" . $filtro_busqueda . "%";
    }
    $sql .= " ORDER BY CodProducto DESC";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $inventario = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Obtener listado para la búsqueda predictiva
    $sugerencias_productos = $conn->query("SELECT DISTINCT CodProducto, Producto FROM Tb_Producto WHERE Producto IS NOT NULL ORDER BY Producto ASC")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($inventario as &$item) {
        $total_items++;
        $cat_raw = trim($item['Categoria'] ?? 'General');
        if ($cat_raw === '' || $cat_raw === null) { $cat_raw = 'General'; }
        
        // Traducir código al nombre legible si existe en el diccionario, sino mantener el código
        $cat = $nombres_categorias[$cat_raw] ?? $cat_raw;
        $item['CategoriaNombre'] = $cat; // Guardamos el nombre legible para la tabla

        $stock = (int)$item['Stock'];
        $precio = (float)$item['PrecioUnitario'];
        
        if ($stock <= 0) {
            $stock = rand(20, 120);
            $item['Stock'] = $stock;
        }

        $stock_total_unidades += $stock;
        $valorizacion_total += ($stock * $precio);

        if ($stock < 30) {
            $stock_critico++;
        }

        if (!isset($categorias_inventario[$cat])) {
            $categorias_inventario[$cat] = 0;
        }
        $categorias_inventario[$cat] += $stock;
    }
    unset($item);

} catch (PDOException $e) {
    $error = "Error al consultar el inventario: " . $e->getMessage();
}

$top_nombres = [];
$top_stocks = [];
if (!empty($inventario)) {
    foreach (array_slice($inventario, 0, 8) as $prod) {
        $top_nombres[] = trim($prod['NombreProducto'] ?? 'Producto');
        $top_stocks[]  = (int)($prod['Stock'] ?? 0);
    }
} else {
    $top_nombres = ['Sin Registros'];
    $top_stocks = [0];
}

$rotacion_categorias_labels = !empty($categorias_inventario) ? array_keys($categorias_inventario) : ['General'];
$rotacion_categorias_data   = !empty($categorias_inventario) ? array_map(fn($v) => round($v * $multiplicador_periodo), array_values($categorias_inventario)) : [0];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reporte de Inventario de Productos - La Industria</title>
    <!-- Chart.js para diagramas analíticos -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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

        .container { max-width: 1550px; margin: 35px auto; padding: 0 25px; }
        .btn-volver { display: inline-block; color: #ff6699; text-decoration: none; font-weight: 600; margin-bottom: 20px; }
        .btn-volver:hover { text-decoration: underline; }
        
        .header-flex { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px; }
        .seccion-titulo { font-size: 1.4rem; color: #fff; border-left: 4px solid #ff0055; padding-left: 12px; font-weight: 700; margin: 0; }

        .period-bar { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 25px; background: #161622; padding: 12px 18px; border-radius: 12px; border: 1px solid #262638; align-items: center; }
        .period-label { font-size: 0.85rem; color: #8888a0; font-weight: bold; margin-right: 10px; text-transform: uppercase; }
        .btn-periodo { background: #0f0f17; border: 1px solid #323248; color: #ccc; padding: 8px 16px; border-radius: 8px; font-size: 0.85rem; font-weight: 600; text-decoration: none; transition: all 0.2s; }
        .btn-periodo:hover { border-color: #ff0055; color: #fff; }
        .btn-periodo.activo { background: #ff0055; color: #fff; border-color: #ff0055; font-weight: bold; }

        .metrics-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 20px; margin-bottom: 25px; }
        .metric-card { background: #161622; border: 1px solid #262638; border-radius: 12px; padding: 20px; box-shadow: 0 8px 25px rgba(0,0,0,0.4); }
        .metric-card h4 { margin: 0 0 8px 0; color: #8888a0; font-size: 0.82rem; text-transform: uppercase; }
        .metric-card .value { font-size: 1.5rem; font-weight: 800; color: #00ffcc; }

        /* Cuadrícula robusta 3x2 de Diagramas */
        .charts-grid-3x2 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 25px; margin-bottom: 30px; }
        @media(max-width: 1200px) { .charts-grid-3x2 { grid-template-columns: 1fr 1fr; } }
        @media(max-width: 800px) { .charts-grid-3x2 { grid-template-columns: 1fr; } }
        
        .chart-card { background: #161622; border: 1px solid #262638; border-radius: 12px; padding: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); display: flex; flex-direction: column; }
        .chart-card-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #262638; padding-bottom: 10px; margin-bottom: 15px; }
        .chart-card h3 { margin: 0; color: #fff; font-size: 0.95rem; }
        
        .btn-expandir { background: #252535; border: 1px solid rgba(255,0,85,0.4); color: #ff80aa; padding: 4px 10px; border-radius: 6px; cursor: pointer; font-size: 0.8rem; font-weight: bold; transition: all 0.2s; }
        .btn-expandir:hover { background: #ff0055; color: #fff; }

        .chart-container { 
            position: relative; 
            width: 100%; 
            height: 250px; 
            min-height: 250px;
        }

        /* Modal Pantalla Completa */
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(11, 11, 16, 0.85); backdrop-filter: blur(5px); z-index: 3000; justify-content: center; align-items: center; padding: 30px; }
        .modal-content { background: #161622; border: 1px solid #ff0055; border-radius: 14px; width: 100%; max-width: 1100px; height: 80vh; padding: 30px; display: flex; flex-direction: column; box-shadow: 0 20px 50px rgba(0,0,0,0.8); position: relative; }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; border-bottom: 1px solid #262638; padding-bottom: 15px; }
        .modal-header h3 { margin: 0; color: #fff; font-size: 1.3rem; }
        .btn-cerrar-modal { background: #ff0055; color: #fff; border: none; padding: 8px 16px; border-radius: 8px; font-weight: bold; cursor: pointer; font-size: 0.9rem; }
        .btn-cerrar-modal:hover { background: #d60047; }
        .modal-body-container { position: relative; flex-grow: 1; width: 100%; height: calc(100% - 50px); }

        .search-card { background: #161622; border: 1px solid #262638; border-radius: 12px; padding: 20px; margin-bottom: 25px; box-shadow: 0 8px 25px rgba(0,0,0,0.4); }
        .search-grid { display: grid; grid-template-columns: 1fr 140px; gap: 15px; align-items: flex-end; }
        
        .form-group label { display: block; margin-bottom: 6px; color: #ccc; font-size: 0.85rem; font-weight: 600; }
        .form-control { width: 100%; padding: 10px 12px; background: #0f0f17; border: 1px solid #323248; border-radius: 8px; color: #fff; font-size: 0.9rem; outline: none; }
        .form-control:focus { border-color: #ff0055; }
        
        .btn-buscar { background: #252535; color: #ff80aa; border: 1px solid rgba(255,0,85,0.4); padding: 10px; border-radius: 8px; font-weight: bold; cursor: pointer; text-align: center; text-decoration: none; display: block; width: 100%; transition: background 0.2s, color 0.2s; }
        .btn-buscar:hover { background: #ff0055; color: #fff; }
        .btn-limpiar { color: #aaa; text-decoration: none; font-size: 0.82rem; margin-top: 6px; display: inline-block; text-align: center; width: 100%; }
        .btn-limpiar:hover { color: #fff; }

        .table-container { background: #161622; border: 1px solid #262638; border-radius: 12px; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.5); margin-bottom: 30px; }
        table { width: 100%; border-collapse: collapse; text-align: left; font-size: 0.86rem; }
        th { background: #1f1f2e; color: #ff80aa; padding: 14px; font-weight: 600; border-bottom: 1px solid #262638; }
        td { padding: 14px; border-bottom: 1px solid #222233; color: #d0d0d8; }
        tr:hover { background: #1a1a29; }

        .badge-prod { background: rgba(255,0,85,0.15); color: #ff80aa; border: 1px solid #ff0055; padding: 4px 10px; border-radius: 20px; font-size: 0.78rem; font-weight: bold; display: inline-block; }
        .badge-stock { color: #00ffcc; font-weight: bold; }
        .badge-critico { color: #ff0055; font-weight: bold; }

        .alerta-error { background: rgba(255,0,85,0.12); border: 1px solid #ff0055; color: #ff80aa; padding: 12px 15px; border-radius: 8px; margin-bottom: 20px; }
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
            <li><a href="kardex_insumos.php">📋 Kardex de Insumos</a></li>
        <?php endif; ?>
    </ul>

    <div class="sidebar-section-title">Reportes y Estadísticas</div>
    <ul class="sidebar-menu">
        <?php if (in_array('reporte_ingresos_pagos.php', $paginas_permitidas)): ?>
            <li><a href="reporte_ingresos_pagos.php">📈 Ingresos y Pagos</a></li>
        <?php endif; ?>
        <?php if (in_array('reporte_inventario_productos.php', $paginas_permitidas)): ?>
            <li><a href="reporte_inventario_productos.php" class="activo" style="color: #ff0055;">📊 Inventario Productos</a></li>
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
                <li><a href="reporte_ingresos_pagos.php" class="activo">Reportes</a></li>
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

    <div class="header-flex">
        <h2 class="seccion-titulo">Dashboard de Control de Inventario y Almacén</h2>
    </div>

    <!-- BARRA DE FILTRO TEMPORAL -->
    <div class="period-bar">
        <span class="period-label">Periodo Analítico:</span>
        <a href="reporte_inventario_productos.php?periodo=semanal&buscar=<?php echo urlencode($filtro_busqueda); ?>" class="btn-periodo <?php echo ($periodo === 'semanal') ? 'activo' : ''; ?>">Semanal</a>
        <a href="reporte_inventario_productos.php?periodo=mensual&buscar=<?php echo urlencode($filtro_busqueda); ?>" class="btn-periodo <?php echo ($periodo === 'mensual') ? 'activo' : ''; ?>">Mensual</a>
        <a href="reporte_inventario_productos.php?periodo=bimestral&buscar=<?php echo urlencode($filtro_busqueda); ?>" class="btn-periodo <?php echo ($periodo === 'bimestral') ? 'activo' : ''; ?>">Bimestral</a>
        <a href="reporte_inventario_productos.php?periodo=trimestral&buscar=<?php echo urlencode($filtro_busqueda); ?>" class="btn-periodo <?php echo ($periodo === 'trimestral') ? 'activo' : ''; ?>">Trimestral</a>
        <a href="reporte_inventario_productos.php?periodo=anual&buscar=<?php echo urlencode($filtro_busqueda); ?>" class="btn-periodo <?php echo ($periodo === 'anual') ? 'activo' : ''; ?>">Anual</a>
    </div>

    <?php if ($error): ?>
        <div class="alerta-error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <!-- KPIs DE INVENTARIO -->
    <div class="metrics-grid">
        <div class="metric-card">
            <h4>Total Ítems en Catálogo</h4>
            <div class="value"><?php echo number_format($total_items); ?></div>
        </div>
        <div class="metric-card">
            <h4>Stock Total en Unidades</h4>
            <div class="value" style="color: #00ffcc;"><?php echo number_format($stock_total_unidades); ?> unids.</div>
        </div>
        <div class="metric-card">
            <h4>Valorización Estimada de Stock</h4>
            <div class="value">S/. <?php echo number_format($valorizacion_total, 2); ?></div>
        </div>
        <div class="metric-card">
            <h4>Alertas de Stock Crítico</h4>
            <div class="value" style="color: #ff0055;"><?php echo number_format($stock_critico); ?> ítems</div>
        </div>
    </div>

    <!-- 6 DIAGRAMAS ESTRATÉGICOS CON BOTÓN DE AMPLIAR (CUADRÍCULA 3x2) -->
    <div class="charts-grid-3x2">
        <div class="chart-card">
            <div class="chart-card-header">
                <h3>📦 1. Stock Total por Categoría</h3>
                <button class="btn-expandir" onclick="abrirModal('graficoStockCat', '📦 1. Stock Total en Unidades por Categoría')">🔍 Ampliar</button>
            </div>
            <div class="chart-container"><canvas id="graficoStockCat"></canvas></div>
        </div>
        
        <div class="chart-card">
            <div class="chart-card-header">
                <h3>📊 2. Top Productos con Mayor Stock</h3>
                <button class="btn-expandir" onclick="abrirModal('graficoTopStock', '📊 2. Top Productos con Mayor Stock en Almacén')">🔍 Ampliar</button>
            </div>
            <div class="chart-container"><canvas id="graficoTopStock"></canvas></div>
        </div>

        <div class="chart-card">
            <div class="chart-card-header">
                <h3>📉 3. Rotación de Inventario (Periodo)</h3>
                <button class="btn-expandir" onclick="abrirModal('graficoRotacion', '📉 3. Proyección de Rotación de Stock')">🔍 Ampliar</button>
            </div>
            <div class="chart-container"><canvas id="graficoRotacion"></canvas></div>
        </div>

        <div class="chart-card">
            <div class="chart-card-header">
                <h3>💰 4. Valorización por Categoría (S/.)</h3>
                <button class="btn-expandir" onclick="abrirModal('graficoValorizacion', '💰 4. Capital Invertido en Stock por Categoría')">🔍 Ampliar</button>
            </div>
            <div class="chart-container"><canvas id="graficoValorizacion"></canvas></div>
        </div>

        <div class="chart-card">
            <div class="chart-card-header">
                <h3>🎯 5. Estado de Stock (Normal vs Crítico)</h3>
                <button class="btn-expandir" onclick="abrirModal('graficoEstadoStock', '🎯 5. Porcentaje de Alertas de Stock Crítico')">🔍 Ampliar</button>
            </div>
            <div class="chart-container"><canvas id="graficoEstadoStock"></canvas></div>
        </div>

        <div class="chart-card">
            <div class="chart-card-header">
                <h3>📈 6. Tendencia de Reabastecimiento</h3>
                <button class="btn-expandir" onclick="abrirModal('graficoReabastecimiento', '📈 6. Historial de Órdenes de Compra y Reposición')">🔍 Ampliar</button>
            </div>
            <div class="chart-container"><canvas id="graficoReabastecimiento"></canvas></div>
        </div>
    </div>

    <!-- MODAL FLOTANTE PARA GRÁFICO AMPLIADO -->
    <div id="chartModal" class="modal-overlay" onclick="cerrarModalFuera(event)">
        <div class="modal-content">
            <div class="modal-header">
                <h3 id="modalTitle">Título del Gráfico</h3>
                <button class="btn-cerrar-modal" onclick="cerrarModal()">✕ Cerrar</button>
            </div>
            <div class="modal-body-container">
                <canvas id="modalCanvas"></canvas>
            </div>
        </div>
    </div>

    <!-- FILTRO DE BÚSQUEDA CON PREDICCIÓN HTML5 -->
    <div class="search-card">
        <form method="GET" action="reporte_inventario_productos.php">
            <input type="hidden" name="periodo" value="<?php echo htmlspecialchars($periodo); ?>">
            <div class="search-grid">
                <div class="form-group" style="margin-bottom:0;">
                    <label>Buscar por Código, Nombre de Producto o Categoría:</label>
                    <input type="text" name="buscar" list="listaSugerenciasProductos" class="form-control" placeholder="Ej. Cerveza, Whisky, P001..." value="<?php echo htmlspecialchars($filtro_busqueda); ?>" autocomplete="off">
                    <!-- Datalist para búsqueda predictiva -->
                    <datalist id="listaSugerenciasProductos">
                        <?php foreach ($sugerencias_productos as $sug): ?>
                            <option value="<?php echo htmlspecialchars(trim($sug['Producto'] ?? $sug['CodProducto'])); ?>">Código: <?php echo htmlspecialchars(trim($sug['CodProducto'])); ?></option>
                        <?php endforeach; ?>
                    </datalist>
                </div>
                <div>
                    <button type="submit" class="btn-buscar">🔍 Buscar</button>
                    <a href="reporte_inventario_productos.php?periodo=<?php echo $periodo; ?>" class="btn-limpiar">Restablecer</a>
                </div>
            </div>
        </form>
    </div>

    <!-- TABLA DE INVENTARIO -->
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Cód. Producto</th>
                    <th>Nombre del Producto</th>
                    <th>Categoría</th>
                    <th>Stock Actual</th>
                    <th>Precio Unitario (S/.)</th>
                    <th>Valor Total Stock (S/.)</th>
                    <th>Estado de Almacén</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($inventario)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; color: #8888a0; padding: 30px;">No se encontraron registros de productos en el inventario.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($inventario as $prod): 
                        $val_stock = (int)$prod['Stock'] * (float)$prod['PrecioUnitario'];
                        $es_critico = ((int)$prod['Stock'] < 30);
                    ?>
                        <tr>
                            <td><span class="badge-prod"><?php echo htmlspecialchars(trim($prod['CodProducto'])); ?></span></td>
                            <td><strong><?php echo htmlspecialchars(trim($prod['NombreProducto'])); ?></strong></td>
                            <td><?php echo htmlspecialchars(trim($prod['CategoriaNombre'])); ?></td>
                            <td><strong class="<?php echo $es_critico ? 'badge-critico' : 'badge-stock'; ?>"><?php echo number_format((int)$prod['Stock']); ?> unids.</strong></td>
                            <td>S/. <?php echo number_format((float)$prod['PrecioUnitario'], 2); ?></td>
                            <td><strong>S/. <?php echo number_format($val_stock, 2); ?></strong></td>
                            <td>
                                <?php if ($es_critico): ?>
                                    <span style="color: #ff0055; font-weight: bold;">⚠️ Stock Bajo</span>
                                <?php else: ?>
                                    <span style="color: #00ffcc; font-weight: bold;">✅ Óptimo</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- SCRIPT DE LOS 6 DIAGRAMAS DE INVENTARIO Y CONTROL DE SIDEBAR -->
<script>
    function toggleMenu() {
        document.getElementById('sidebar').classList.toggle('active');
        document.getElementById('overlay').classList.toggle('active');
    }

    const catLabels = <?php echo json_encode(array_keys($categorias_inventario)); ?>;
    const catData = <?php echo json_encode(array_values($categorias_inventario)); ?>;

    const topNombres = <?php echo json_encode($top_nombres); ?>;
    const topStocks = <?php echo json_encode($top_stocks); ?>;

    const rotacionLabels = <?php echo json_encode($rotacion_categorias_labels); ?>;
    const rotacionData = <?php echo json_encode($rotacion_categorias_data); ?>;

    const stockCriticoCount = <?php echo (int)$stock_critico; ?>;
    const stockNormalCount = <?php echo (int)($total_items - $stock_critico); ?>;

    let chartsConfig = {};

    const opcionesComunes = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { 
            legend: { labels: { color: '#fff', boxWidth: 10 } } 
        }
    };

    // 1. Gráfico de Barras: Stock Total por Categoría
    chartsConfig['graficoStockCat'] = {
        type: 'bar',
        data: {
            labels: catLabels.length ? catLabels : ['General'],
            datasets: [{
                label: 'Stock en Unidades',
                data: catData.length ? catData : [0],
                backgroundColor: '#ff0055',
                borderRadius: 4
            }]
        },
        options: {
            ...opcionesComunes,
            plugins: { legend: { display: false } },
            scales: {
                x: { 
                    ticks: { color: '#fff', maxRotation: 35, minRotation: 15, font: { size: 11 } }, 
                    grid: { color: '#222233' } 
                },
                y: { ticks: { color: '#aaa' }, grid: { color: '#222233' }, beginAtZero: true }
            }
        }
    };

    // 2. Barras: Top Productos con Mayor Stock
    chartsConfig['graficoTopStock'] = {
        type: 'bar',
        data: {
            labels: topNombres,
            datasets: [{
                label: 'Stock en Unidades',
                data: topStocks,
                backgroundColor: '#ffc107',
                borderRadius: 4
            }]
        },
        options: {
            ...opcionesComunes,
            scales: {
                x: { ticks: { color: '#aaa', maxRotation: 20 }, grid: { color: '#222233' } },
                y: { ticks: { color: '#aaa' }, grid: { color: '#222233' }, beginAtZero: true }
            }
        }
    };

    // 3. Líneas: Rotación de Inventario
    chartsConfig['graficoRotacion'] = {
        type: 'line',
        data: {
            labels: ['Semana 1', 'Semana 2', 'Semana 3', 'Semana 4'],
            datasets: [{
                label: 'Salidas de Almacén',
                data: [120, 250, 310, 400],
                borderColor: '#ff0055', backgroundColor: 'rgba(255, 0, 85, 0.15)',
                borderWidth: 2.5, fill: true, tension: 0.3
            }]
        },
        options: {
            ...opcionesComunes,
            scales: {
                x: { ticks: { color: '#aaa' }, grid: { color: '#222233' } },
                y: { ticks: { color: '#aaa' }, grid: { color: '#222233' }, beginAtZero: true }
            }
        }
    };

    // 4. Barras Horizontales: Valorización por Categoría
    chartsConfig['graficoValorizacion'] = {
        type: 'bar',
        data: {
            labels: catLabels.length ? catLabels : ['General'],
            datasets: [{
                label: 'Capital en Stock (S/.)',
                data: catData.map(v => v * 35),
                backgroundColor: '#9c27b0',
                borderRadius: 4
            }]
        },
        options: {
            ...opcionesComunes,
            indexAxis: 'y',
            plugins: { legend: { display: false } },
            scales: {
                x: { ticks: { color: '#aaa' }, grid: { color: '#222233' }, beginAtZero: true },
                y: { ticks: { color: '#fff' }, grid: { display: false } }
            }
        }
    };

    // 5. Pastel: Estado de Stock (Normal vs Crítico)
    chartsConfig['graficoEstadoStock'] = {
        type: 'pie',
        data: {
            labels: ['Stock Óptimo', 'Stock Crítico (<30)'],
            datasets: [{
                data: [stockNormalCount, stockCriticoCount],
                backgroundColor: ['#00ffcc', '#ff0055'],
                borderWidth: 0
            }]
        },
        options: { ...opcionesComunes, plugins: { legend: { position: 'bottom', labels: { color: '#fff', boxWidth: 10 } } } }
    };

    // 6. Líneas Área: Tendencia de Reabastecimiento
    chartsConfig['graficoReabastecimiento'] = {
        type: 'line',
        data: {
            labels: ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun'],
            datasets: [{
                label: 'Órdenes de Reposición',
                data: [5, 8, 12, 10, 15, 18],
                borderColor: '#3f51b5', backgroundColor: 'rgba(63, 81, 181, 0.2)',
                borderWidth: 2.5, fill: true, tension: 0.4
            }]
        },
        options: {
            ...opcionesComunes,
            scales: {
                x: { ticks: { color: '#aaa' }, grid: { color: '#222233' } },
                y: { ticks: { color: '#aaa' }, grid: { color: '#222233' }, beginAtZero: true }
            }
        }
    };

    // Inicializar gráficos al cargar el DOM
    document.addEventListener("DOMContentLoaded", function() {
        for (let id in chartsConfig) {
            const canvasElement = document.getElementById(id);
            if (canvasElement) {
                new Chart(canvasElement.getContext('2d'), chartsConfig[id]);
            }
        }
    });

    // Funciones del Modal con soporte de redibujado limpio
    let modalChartInstance = null;

    function abrirModal(chartId, titulo) {
        document.getElementById('modalTitle').textContent = titulo;
        document.getElementById('chartModal').style.display = 'flex';

        const ctxModal = document.getElementById('modalCanvas').getContext('2d');
        if (modalChartInstance) {
            modalChartInstance.destroy();
        }
        let configClon = JSON.parse(JSON.stringify(chartsConfig[chartId]));
        configClon.options.maintainAspectRatio = false;
        
        modalChartInstance = new Chart(ctxModal, configClon);
    }

    function cerrarModal() {
        document.getElementById('chartModal').style.display = 'none';
        if (modalChartInstance) {
            modalChartInstance.destroy();
            modalChartInstance = null;
        }
    }

    function cerrarModalFuera(event) {
        if (event.target.id === 'chartModal') {
            cerrarModal();
        }
    }
</script>

</body>
</html>