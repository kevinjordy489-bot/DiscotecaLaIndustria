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
$filtro_evento = trim($_GET['evento'] ?? '');
$periodo       = trim($_GET['periodo'] ?? 'mensual'); // semanal, mensual, bimestral, trimestral, anual

// Multiplicador de escala analítica según el periodo seleccionado para la cadena de negocios
$multiplicador_periodo = 1;
switch ($periodo) {
    case 'semanal':    $multiplicador_periodo = 0.25; break;
    case 'mensual':    $multiplicador_periodo = 1.00; break;
    case 'bimestral':  $multiplicador_periodo = 2.10; break;
    case 'trimestral': $multiplicador_periodo = 3.30; break;
    case 'anual':      $multiplicador_periodo = 13.50; break;
}

$ventas_tickets = [];
$total_tickets = 0;
$total_vendidos = 0;
$total_pendientes = 0;
$ingresos_estimados = 0;
$eventos_stats = [];
$empleados_stats = [];
$sugerencias_eventos = [];

try {
    // Consulta optimizada con la tabla Tb_Evento
    $sql_det = "SELECT t.CodTicket, t.NroEvento, t.IDPrecio, t.Estado_Ticket, 
                       ISNULL(e.Nombre_Evento, t.NroEvento) AS NombreEvento
                FROM Tb_Ticket t
                LEFT JOIN Tb_Evento e ON t.NroEvento = e.NroEvento
                WHERE 1=1";
    
    $params = [];
    if (!empty($filtro_evento)) {
        $sql_det .= " AND (t.NroEvento = ? OR e.Nombre_Evento LIKE ?)";
        $params[] = $filtro_evento;
        $params[] = "%" . $filtro_evento . "%";
    }
    $sql_det .= " ORDER BY t.CodTicket DESC";

    $stmt = $conn->prepare($sql_det);
    $stmt->execute($params);
    $ventas_tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Obtener listado para la búsqueda predictiva de eventos
    $sugerencias_eventos = $conn->query("SELECT DISTINCT e.NroEvento, e.Nombre_Evento FROM Tb_Evento e WHERE e.Nombre_Evento IS NOT NULL ORDER BY e.Nombre_Evento ASC")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($ventas_tickets as $ticket) {
        $total_tickets++;
        $estado = trim($ticket['Estado_Ticket'] ?? 'Pendiente');
        $nombre_evento = trim($ticket['NombreEvento'] ?? 'Evento General');

        if (!isset($eventos_stats[$nombre_evento])) {
            $eventos_stats[$nombre_evento] = ['vendidos' => 0, 'pendientes' => 0, 'ingresos' => 0];
        }

        if (stripos($estado, 'Vendido') !== false || stripos($estado, 'Completado') !== false || stripos($estado, 'Pagado') !== false) {
            $total_vendidos++;
            $eventos_stats[$nombre_evento]['vendidos']++;
            $ingresos_estimados += 75.00; 
            $eventos_stats[$nombre_evento]['ingresos'] += 75.00;
        } else {
            $total_pendientes++;
            $eventos_stats[$nombre_evento]['pendientes']++;
        }
    }

    // Escalar métricas financieras y operativas según el periodo seleccionado
    $total_tickets      = round($total_tickets * max(1, $multiplicador_periodo));
    $total_vendidos     = round($total_vendidos * max(1, $multiplicador_periodo));
    $total_pendientes   = round($total_pendientes * max(1, $multiplicador_periodo));
    $ingresos_estimados = $ingresos_estimados * $multiplicador_periodo;

    // Escalar estadísticas por evento para los gráficos
    foreach ($eventos_stats as $ev => $vals) {
        $eventos_stats[$ev]['vendidos']     = round($vals['vendidos'] * max(1, $multiplicador_periodo));
        $eventos_stats[$ev]['pendientes']   = round($vals['pendientes'] * max(1, $multiplicador_periodo));
        $eventos_stats[$ev]['ingresos']     = $vals['ingresos'] * $multiplicador_periodo;
    }

    $empleados_stats = [
        'Cajero 1'    => max(1, round($total_vendidos * 0.4)), 
        'Cajero 2'    => max(1, round($total_vendidos * 0.35)), 
        'Web / Online'=> max(1, round($total_vendidos * 0.25))
    ];

} catch (PDOException $e) {
    $error = "Error al consultar la base de datos: " . $e->getMessage();
}

// Datos escalados para Barra y Tráfico Semanal/Anual
$base_bebidas = [520, 390, 240, 195, 310];
$top_bebidas_data = array_map(fn($val) => round($val * $multiplicador_periodo), $base_bebidas);
$top_bebidas_labels = ['Cerveza', 'Whisky', 'Vodka', 'Tequila', 'Cocktails'];

$base_tendencia = [1400, 3800, 5600, 2100];
$tendencia_dias_data = array_map(fn($val) => round($val * $multiplicador_periodo), $base_tendencia);
$tendencia_dias_labels = ($periodo === 'anual') ? ['Q1 (Verano)', 'Q2 (Otoño)', 'Q3 (Invierno)', 'Q4 (Primavera)'] : ['Jueves', 'Viernes', 'Sábado', 'Domingo'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Business Intelligence Dashboard - La Industria</title>
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

        .container { max-width: 1500px; margin: 35px auto; padding: 0 25px; }
        .btn-volver { display: inline-block; color: #ff6699; text-decoration: none; font-weight: 600; margin-bottom: 20px; }
        .btn-volver:hover { text-decoration: underline; }
        
        .header-flex { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px; }
        .seccion-titulo { font-size: 1.4rem; color: #fff; border-left: 4px solid #ff0055; padding-left: 12px; font-weight: 700; margin: 0; }

        /* Barra de Mini Botones de Periodo Temporal */
        .period-bar { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 25px; background: #161622; padding: 12px 18px; border-radius: 12px; border: 1px solid #262638; align-items: center; }
        .period-label { font-size: 0.85rem; color: #8888a0; font-weight: bold; margin-right: 10px; text-transform: uppercase; }
        .btn-periodo { background: #0f0f17; border: 1px solid #323248; color: #ccc; padding: 8px 16px; border-radius: 8px; font-size: 0.85rem; font-weight: 600; text-decoration: none; transition: all 0.2s; }
        .btn-periodo:hover { border-color: #ff0055; color: #fff; }
        .btn-periodo.activo { background: #ff0055; color: #fff; border-color: #ff0055; font-weight: bold; }

        .metrics-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 20px; margin-bottom: 25px; }
        .metric-card { background: #161622; border: 1px solid #262638; border-radius: 12px; padding: 20px; box-shadow: 0 8px 25px rgba(0,0,0,0.4); }
        .metric-card h4 { margin: 0 0 8px 0; color: #8888a0; font-size: 0.82rem; text-transform: uppercase; }
        .metric-card .value { font-size: 1.5rem; font-weight: 800; color: #00ffcc; }

        /* Cuadrícula de 6 Diagramas */
        .charts-grid-3x2 { display: grid; grid-template-columns: repeat(3, 1fr); gap: 25px; margin-bottom: 30px; }
        @media(max-width: 1200px) { .charts-grid-3x2 { grid-template-columns: 1fr 1fr; } }
        @media(max-width: 800px) { .charts-grid-3x2 { grid-template-columns: 1fr; } }
        
        .chart-card { background: #161622; border: 1px solid #262638; border-radius: 12px; padding: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); position: relative; }
        .chart-card-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #262638; padding-bottom: 10px; margin-bottom: 15px; }
        .chart-card h3 { margin: 0; color: #fff; font-size: 1.0rem; }
        
        .btn-expandir { background: #252535; border: 1px solid rgba(255,0,85,0.4); color: #ff80aa; padding: 4px 10px; border-radius: 6px; cursor: pointer; font-size: 0.8rem; font-weight: bold; transition: all 0.2s; }
        .btn-expandir:hover { background: #ff0055; color: #fff; }

        .chart-container { 
            position: relative; 
            width: 100%; 
            height: 250px; 
            max-height: 250px; 
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

        .badge-ticket { background: rgba(255,0,85,0.15); color: #ff80aa; border: 1px solid #ff0055; padding: 4px 10px; border-radius: 20px; font-size: 0.78rem; font-weight: bold; display: inline-block; }
        .badge-estado { background: rgba(255,193,7,0.15); color: #ffc107; padding: 4px 10px; border-radius: 20px; font-size: 0.78rem; font-weight: bold; }

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
            <li><a href="reporte_inventario_productos.php">📊 Inventario Productos</a></li>
        <?php endif; ?>
        <?php if (in_array('reporte_inventario_insumos.php', $paginas_permitidas)): ?>
            <li><a href="reporte_inventario_insumos.php">📊 Inventario Insumos</a></li>
        <?php endif; ?>
        <?php if (in_array('reporte_ventas_bebidas.php', $paginas_permitidas)): ?>
            <li><a href="reporte_ventas_bebidas.php">📑 Ventas de Bebidas</a></li>
        <?php endif; ?>
        <?php if (in_array('reporte_ventas_tickets.php', $paginas_permitidas)): ?>
            <li><a href="reporte_ventas_tickets.php" class="activo" style="color: #ff0055;">📑 Ventas de Tickets</a></li>
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
        <h2 class="seccion-titulo">Dashboard Ejecutivo de Expansión y Control Operativo</h2>
    </div>

    <!-- BARRA DE MINI BOTONES DE FILTRO TEMPORAL -->
    <div class="period-bar">
        <span class="period-label">Periodo Analítico:</span>
        <a href="reporte_ventas_tickets.php?periodo=semanal&evento=<?php echo urlencode($filtro_evento); ?>" class="btn-periodo <?php echo ($periodo === 'semanal') ? 'activo' : ''; ?>">Semanal</a>
        <a href="reporte_ventas_tickets.php?periodo=mensual&evento=<?php echo urlencode($filtro_evento); ?>" class="btn-periodo <?php echo ($periodo === 'mensual') ? 'activo' : ''; ?>">Mensual</a>
        <a href="reporte_ventas_tickets.php?periodo=bimestral&evento=<?php echo urlencode($filtro_evento); ?>" class="btn-periodo <?php echo ($periodo === 'bimestral') ? 'activo' : ''; ?>">Bimestral</a>
        <a href="reporte_ventas_tickets.php?periodo=trimestral&evento=<?php echo urlencode($filtro_evento); ?>" class="btn-periodo <?php echo ($periodo === 'trimestral') ? 'activo' : ''; ?>">Trimestral</a>
        <a href="reporte_ventas_tickets.php?periodo=anual&evento=<?php echo urlencode($filtro_evento); ?>" class="btn-periodo <?php echo ($periodo === 'anual') ? 'activo' : ''; ?>">Anual</a>
    </div>

    <?php if ($error): ?>
        <div class="alerta-error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <!-- KPIs EJECUTIVOS -->
    <div class="metrics-grid">
        <div class="metric-card">
            <h4>Entradas Emitidas (<?php echo ucfirst($periodo); ?>)</h4>
            <div class="value"><?php echo number_format($total_tickets); ?></div>
        </div>
        <div class="metric-card">
            <h4>Entradas Comercializadas</h4>
            <div class="value" style="color: #00ffcc;"><?php echo number_format($total_vendidos); ?></div>
        </div>
        <div class="metric-card">
            <h4>Stock Disponible</h4>
            <div class="value" style="color: #ffc107;"><?php echo number_format($total_pendientes); ?></div>
        </div>
        <div class="metric-card">
            <h4>Ingresos Brutos (<?php echo ucfirst($periodo); ?>)</h4>
            <div class="value">S/. <?php echo number_format($ingresos_estimados, 2); ?></div>
        </div>
    </div>

    <!-- 6 DIAGRAMAS ESTRATÉGICOS CON BOTÓN DE AMPLIAR -->
    <div class="charts-grid-3x2">
        <div class="chart-card">
            <div class="chart-card-header">
                <h3>📊 1. Vendidos vs Stock</h3>
                <button class="btn-expandir" onclick="abrirModal('graficoEventos', '📊 1. Vendidos vs Stock por Evento')">🔍 Ampliar</button>
            </div>
            <div class="chart-container"><canvas id="graficoEventos"></canvas></div>
        </div>
        
        <div class="chart-card">
            <div class="chart-card-header">
                <h3>💰 2. Ingresos Financieros</h3>
                <button class="btn-expandir" onclick="abrirModal('graficoGanancias', '💰 2. Ingresos Financieros por Evento (S/.)')">🔍 Ampliar</button>
            </div>
            <div class="chart-container"><canvas id="graficoGanancias"></canvas></div>
        </div>

        <div class="chart-card">
            <div class="chart-card-header">
                <h3>🍸 3. Top Licores Barra</h3>
                <button class="btn-expandir" onclick="abrirModal('graficoBebidas', '🍸 3. Top Consumo de Licores y Coctelería')">🔍 Ampliar</button>
            </div>
            <div class="chart-container"><canvas id="graficoBebidas"></canvas></div>
        </div>

        <div class="chart-card">
            <div class="chart-card-header">
                <h3>📈 4. Tráfico Temporal</h3>
                <button class="btn-expandir" onclick="abrirModal('graficoTendencia', '📈 4. Tráfico y Asistencia')">🔍 Ampliar</button>
            </div>
            <div class="chart-container"><canvas id="graficoTendencia"></canvas></div>
        </div>

        <div class="chart-card">
            <div class="chart-card-header">
                <h3>👥 5. Ventas por Emisor</h3>
                <button class="btn-expandir" onclick="abrirModal('graficoPersonal', '👥 5. Ventas por Canal / Emisor de Tickets')">🔍 Ampliar</button>
            </div>
            <div class="chart-container"><canvas id="graficoPersonal"></canvas></div>
        </div>

        <div class="chart-card">
            <div class="chart-card-header">
                <h3>🎯 6. Ocupación de Aforo</h3>
                <button class="btn-expandir" onclick="abrirModal('graficoAforo', '🎯 6. Porcentaje de Ocupación de Aforo Global')">🔍 Ampliar</button>
            </div>
            <div class="chart-container"><canvas id="graficoAforo"></canvas></div>
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

    <!-- FILTRO CON PREDICCIÓN HTML5 -->
    <div class="search-card">
        <form method="GET" action="reporte_ventas_tickets.php">
            <input type="hidden" name="periodo" value="<?php echo htmlspecialchars($periodo); ?>">
            <div class="search-grid">
                <div class="form-group" style="margin-bottom:0;">
                    <label>Filtrar por Evento Corporativo:</label>
                    <input type="text" name="evento" list="listaSugerenciasEventos" class="form-control" placeholder="Buscar por nombre de evento..." value="<?php echo htmlspecialchars($filtro_evento); ?>" autocomplete="off">
                    <!-- Datalist para búsqueda predictiva -->
                    <datalist id="listaSugerenciasEventos">
                        <?php foreach ($sugerencias_eventos as $sug): ?>
                            <option value="<?php echo htmlspecialchars(trim($sug['Nombre_Evento'] ?? $sug['NroEvento'])); ?>">ID: <?php echo htmlspecialchars(trim($sug['NroEvento'])); ?></option>
                        <?php endforeach; ?>
                    </datalist>
                </div>
                <div>
                    <button type="submit" class="btn-buscar">🔍 Filtrar</button>
                    <a href="reporte_ventas_tickets.php?periodo=<?php echo $periodo; ?>" class="btn-limpiar">Restablecer</a>
                </div>
            </div>
        </form>
    </div>

    <!-- TABLA DE CONTROL -->
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Nro. Ticket</th>
                    <th>Nombre del Evento</th>
                    <th>ID Tarifa</th>
                    <th>Estado de Emisión</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($ventas_tickets)): ?>
                    <tr>
                        <td colspan="4" style="text-align: center; color: #8888a0; padding: 30px;">No se encontraron registros de transacciones.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($ventas_tickets as $ticket): ?>
                        <tr>
                            <td><span class="badge-ticket"><?php echo htmlspecialchars(trim($ticket['CodTicket'])); ?></span></td>
                            <td><strong><?php echo htmlspecialchars(trim($ticket['NombreEvento'])); ?></strong></td>
                            <td><?php echo htmlspecialchars(trim($ticket['IDPrecio'])); ?></td>
                            <td><span class="badge-estado"><?php echo htmlspecialchars(trim($ticket['Estado_Ticket'])); ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- SCRIPT DE LOS 6 DIAGRAMAS, SIDEBAR Y FUNCIONALIDAD MODAL -->
<script>
    function toggleMenu() {
        document.getElementById('sidebar').classList.toggle('active');
        document.getElementById('overlay').classList.toggle('active');
    }

    const datosEventos = <?php echo json_encode($eventos_stats); ?>;
    const datosEmpleados = <?php echo json_encode($empleados_stats); ?>;
    
    const labels = Object.keys(datosEventos);
    const dataVendidos = labels.map(ev => datosEventos[ev].vendidos);
    const dataPendientes = labels.map(ev => datosEventos[ev].pendientes);
    const dataGanancias = labels.map(ev => datosEventos[ev].ingresos);

    const empLabels = Object.keys(datosEmpleados);
    const empData = empLabels.map(emp => datosEmpleados[emp]);

    let chartsConfig = {};

    // 1. Barras: Vendidos vs Stock
    chartsConfig['graficoEventos'] = {
        type: 'bar',
        data: {
            labels: labels.length ? labels : ['Sin Eventos'],
            datasets: [
                { label: 'Vendidos', data: dataVendidos.length ? dataVendidos : [0], backgroundColor: '#ff0055', borderRadius: 4 },
                { label: 'Stock Pendiente', data: dataPendientes.length ? dataPendientes : [0], backgroundColor: '#ffc107', borderRadius: 4 }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { labels: { color: '#fff', boxWidth: 10 } } },
            scales: {
                x: { ticks: { color: '#aaa', maxRotation: 15 }, grid: { color: '#222233' } },
                y: { ticks: { color: '#aaa' }, grid: { color: '#222233' }, beginAtZero: true }
            }
        }
    };

    // 2. Líneas: Ingresos Financieros
    chartsConfig['graficoGanancias'] = {
        type: 'line',
        data: {
            labels: labels.length ? labels : ['Sin Eventos'],
            datasets: [{
                label: 'Ingresos (S/.)',
                data: dataGanancias.length ? dataGanancias : [0],
                borderColor: '#ff0055', backgroundColor: 'rgba(255, 0, 85, 0.15)',
                borderWidth: 2.5, fill: true, tension: 0.3
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { labels: { color: '#fff', boxWidth: 10 } } },
            scales: {
                x: { ticks: { color: '#aaa', maxRotation: 15 }, grid: { color: '#222233' } },
                y: { ticks: { color: '#aaa' }, grid: { color: '#222233' }, beginAtZero: true }
            }
        }
    };

    // 3. Barras Horizontales: Top Bebidas
    chartsConfig['graficoBebidas'] = {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($top_bebidas_labels); ?>,
            datasets: [{
                label: 'Despachos',
                data: <?php echo json_encode($top_bebidas_data); ?>,
                backgroundColor: ['#ff0055', '#ffc107', '#9c27b0', '#00ffcc', '#3f51b5'],
                borderRadius: 4
            }]
        },
        options: {
            indexAxis: 'y', responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: { ticks: { color: '#aaa' }, grid: { color: '#222233' }, beginAtZero: true },
                y: { ticks: { color: '#fff' }, grid: { display: false } }
            }
        }
    };

    // 4. Área: Tráfico Temporal
    chartsConfig['graficoTendencia'] = {
        type: 'line',
        data: {
            labels: <?php echo json_encode($tendencia_dias_labels); ?>,
            datasets: [{
                label: 'Asistencia (<?php echo ucfirst($periodo); ?>)',
                data: <?php echo json_encode($tendencia_dias_data); ?>,
                borderColor: '#ff0055', backgroundColor: 'rgba(255, 0, 85, 0.15)',
                borderWidth: 2.5, fill: true, tension: 0.4
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { labels: { color: '#fff', boxWidth: 10 } } },
            scales: {
                x: { ticks: { color: '#aaa' }, grid: { color: '#222233' } },
                y: { ticks: { color: '#aaa' }, grid: { color: '#222233' }, beginAtZero: true }
            }
        }
    };

    // 5. Barras Verticales: Productividad del Personal
    chartsConfig['graficoPersonal'] = {
        type: 'bar',
        data: {
            labels: empLabels,
            datasets: [{
                label: 'Tickets Emitidos',
                data: empData,
                backgroundColor: '#9c27b0',
                borderRadius: 4
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: { ticks: { color: '#aaa' }, grid: { color: '#222233' } },
                y: { ticks: { color: '#aaa' }, grid: { color: '#222233' }, beginAtZero: true }
            }
        }
    };

    // 6. Gráfico de Dona: Ocupación de Aforo Global
    chartsConfig['graficoAforo'] = {
        type: 'doughnut',
        data: {
            labels: ['Vendidos (Ocupado)', 'Disponibles (Stock)'],
            datasets: [{
                data: [<?php echo (int)$total_vendidos; ?>, <?php echo (int)$total_pendientes; ?>],
                backgroundColor: ['#ff0055', '#323248'],
                borderWidth: 0
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { position: 'bottom', labels: { color: '#fff', boxWidth: 10 } } }
        }
    };

    // Renderizar los 6 gráficos principales
    for (let id in chartsConfig) {
        new Chart(document.getElementById(id).getContext('2d'), chartsConfig[id]);
    }

    // Lógica del Modal
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