<?php
// ACTIVAR ERRORES PARA DIAGNÓSTICO
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

require_once '../config/conexion.php';
require_once 'permisos.php'; // Valida sesión, rol y mapeo de páginas permitidas

// Validación de sesión y rol de Administrador (o según permisos.php)
if (!isset($_SESSION['rol']) || $_SESSION['rol'] !== 'empleado' || !isset($_SESSION['empleado_perfil']) || $_SESSION['empleado_perfil'] !== 'Administrador') {
    header("Location: login_trabajador.php");
    exit;
}

$empleado_nombre = $_SESSION['empleado_nombre'] ?? 'Administrador';
$empleado_perfil = $_SESSION['empleado_perfil'] ?? 'Administrador';
$paginas_permitidas = obtenerPaginasPermitidas($empleado_perfil);

// Consultas rápidas para métricas gerenciales en el panel principal
$total_empleados = 0;
$ordenes_pendientes = 0;
$eventos_activos = 0;
$mesas_ocupadas = 0;

try {
    $total_empleados = $conn->query("SELECT COUNT(*) FROM Tb_Empleado WHERE Estado_Empleado = 'Activo'")->fetchColumn();
    $ordenes_pendientes = $conn->query("SELECT COUNT(*) FROM Tb_Orden_Compra_Producto WHERE Estado_OrdenCompra_Producto = 'Pendiente'")->fetchColumn();
    $eventos_activos = $conn->query("SELECT COUNT(*) FROM Tb_Evento WHERE Estado_Evento = 'Activo'")->fetchColumn();
    $mesas_ocupadas = $conn->query("SELECT COUNT(*) FROM Tb_Mesa WHERE Estado_Mesa IN ('Ocupada', 'Reservada')")->fetchColumn();
} catch (PDOException $e) {
    // Si alguna consulta falla, continúa mostrando el panel sin bloquear la interfaz
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Panel de Control Gerencial - La Industria Surco</title>
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

        /* Menú Lateral (Sidebar) dinámico por permisos */
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

        /* Banner Principal */
        .hero-banner {
            background: linear-gradient(135deg, #14141f 0%, #1f1224 100%);
            padding: 40px; border-bottom: 2px solid rgba(255, 0, 85, 0.3);
        }
        .hero-banner h1 { font-size: 2.2rem; margin: 0 0 8px 0; color: #fff; }
        .hero-banner h1 span { color: #ff0055; }
        .hero-banner p { font-size: 1.02rem; color: #9e9eb3; margin: 0; }

        /* Contenedor Principal */
        .container { max-width: 1250px; margin: 35px auto; padding: 0 30px; }
        .seccion-titulo {
            font-size: 1.3rem; color: #fff; margin: 35px 0 20px 0;
            border-left: 4px solid #ff0055; padding-left: 12px; font-weight: 700;
        }

        /* Tarjetas de Resumen KPI */
        .kpi-grid {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px; margin-bottom: 30px;
        }
        .kpi-card {
            background: #161622; border-radius: 12px; padding: 22px;
            border: 1px solid #262638; box-shadow: 0 8px 25px rgba(0,0,0,0.4);
            display: flex; justify-content: space-between; align-items: center;
        }
        .kpi-info span { font-size: 0.8rem; color: #9e9eb3; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; }
        .kpi-info h2 { font-size: 1.8rem; margin: 6px 0 0 0; color: #00ffcc; }
        .kpi-icon { font-size: 2.2rem; opacity: 0.85; }

        /* Cuadrícula de Módulos Administrativos */
        .grid {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(270px, 1fr));
            gap: 20px; margin-bottom: 30px;
        }
        .card {
            background: #161622; padding: 22px; border-radius: 12px; border: 1px solid #262638;
            text-decoration: none; color: #fff; transition: all 0.25s ease; display: block;
            box-shadow: 0 6px 20px rgba(0,0,0,0.35);
        }
        .card:hover { border-color: #ff0055; background: #1c1c2b; transform: translateY(-3px); }
        .card strong { font-size: 1.05rem; color: #fff; display: block; margin-bottom: 6px; }
        .card span { font-size: 0.88rem; color: #9e9eb3; display: block; line-height: 1.4; }
    </style>
</head>
<body>

<!-- Menú Lateral Desplegable (Sidebar) Dinámico por Permisos -->
<div class="overlay" id="overlay" onclick="toggleMenu()"></div>
<div class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="sidebar-title">LA INDUSTRIA <span>ADMIN</span></div>
        <button class="close-btn" onclick="toggleMenu()">&times;</button>
    </div>
    
    <div class="sidebar-section-title">Panel Gerencial</div>
    <ul class="sidebar-menu">
        <?php if (in_array('panel_control.php', $paginas_permitidas)): ?>
            <li><a href="panel_control.php" class="activo" style="color: #ff0055;">📊 Inicio / Dashboard</a></li>
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
            <li><a href="gestion_mesas.php" style="color: #00ffcc;">🪑 Gestión de Mesas y Aforo</a></li>
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

<!-- Barra Superior Dinámica por Permisos -->
<nav>
    <div class="nav-left">
        <button class="menu-btn" onclick="toggleMenu()"><span></span><span></span><span></span></button>
        <a href="panel_control.php" class="logo">LA INDUSTRIA <span>ADMIN</span></a>
        <ul class="nav-links">
            <?php if (in_array('panel_control.php', $paginas_permitidas)): ?>
                <li><a href="panel_control.php" class="activo" style="color: #ff0055; font-weight: bold;">Panel</a></li>
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

<!-- Banner -->
<div class="hero-banner">
    <h1>Panel de Control <span>Gerencial</span></h1>
    <p>Administración integral de personal, logística, almacenes, mesas y reportes financieros de La Industria.</p>
</div>

<!-- Contenido Principal -->
<div class="container">

    <!-- INDICADORES RÁPIDOS -->
    <div class="kpi-grid">
        <div class="kpi-card">
            <div class="kpi-info">
                <span>Personal Activo</span>
                <h2><?php echo (int)$total_empleados; ?></h2>
            </div>
            <div class="kpi-icon">👥</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-info">
                <span>Mesas Ocupadas</span>
                <h2><?php echo (int)$mesas_ocupadas; ?></h2>
            </div>
            <div class="kpi-icon">🪑</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-info">
                <span>Órdenes Pendientes</span>
                <h2><?php echo (int)$ordenes_pendientes; ?></h2>
            </div>
            <div class="kpi-icon">🛒</div>
        </div>
        <div class="kpi-card">
            <div class="kpi-info">
                <span>Eventos Activos</span>
                <h2><?php echo (int)$eventos_activos; ?></h2>
            </div>
            <div class="kpi-icon">🎟️</div>
        </div>
    </div>

    <!-- SECCIÓN 1: PERSONAL Y DIRECTORIO -->
    <div class="seccion-titulo">Gerencia, Personal y Directorio</div>
    <div class="grid">
        <?php if (in_array('gestion_trabajadores.php', $paginas_permitidas)): ?>
            <a href="gestion_trabajadores.php" class="card">
                <strong>👥 Gestión de Trabajadores</strong>
                <span>Controla accesos, perfiles y estado activo del personal.</span>
            </a>
        <?php endif; ?>
        <?php if (in_array('nuevo_trabajador.php', $paginas_permitidas)): ?>
            <a href="nuevo_trabajador.php" class="card">
                <strong>➕ Registrar Nuevo Trabajador</strong>
                <span>Da de alta nuevos empleados y asigna sus credenciales.</span>
            </a>
        <?php endif; ?>
        <?php if (in_array('consultar_proveedores.php', $paginas_permitidas)): ?>
            <a href="consultar_proveedores.php" class="card">
                <strong>🚚 Consultar Proveedores</strong>
                <span>Revisa el directorio de empresas proveedoras y RUC.</span>
            </a>
        <?php endif; ?>
        <?php if (in_array('consultar_sedes.php', $paginas_permitidas)): ?>
            <a href="consultar_sedes.php" class="card">
                <strong>🏢 Consultar Sedes</strong>
                <span>Información operativa de los locales registrados.</span>
            </a>
        <?php endif; ?>
    </div>

    <!-- SECCIÓN 2: COMPRAS Y LOGÍSTICA -->
    <div class="seccion-titulo">Logística, Órdenes de Compra y Recepción</div>
    <div class="grid">
        <?php if (in_array('registrar_orden_compra_producto.php', $paginas_permitidas)): ?>
            <a href="registrar_orden_compra_producto.php" class="card">
                <strong>🛒 Orden de Compra (Productos)</strong>
                <span>Emite órdenes de compra de bebidas y licores.</span>
            </a>
        <?php endif; ?>
        <?php if (in_array('generar_orden_compra_insumo.php', $paginas_permitidas)): ?>
            <a href="generar_orden_compra_insumo.php" class="card">
                <strong>📦 Orden de Compra (Insumos)</strong>
                <span>Registra pedidos de insumos y materia prima.</span>
            </a>
        <?php endif; ?>
        <?php if (in_array('aprobar_ordenes_compra.php', $paginas_permitidas)): ?>
            <a href="aprobar_ordenes_compra.php" class="card">
                <strong>✅ Aprobar Órdenes de Compra</strong>
                <span>Autoriza las órdenes pendientes antes de su despacho.</span>
            </a>
        <?php endif; ?>
        <?php if (in_array('registrar_recepcion_compra.php', $paginas_permitidas)): ?>
            <a href="registrar_recepcion_compra.php" class="card">
                <strong>📥 Recepción Física de Compra</strong>
                <span>Valida la mercadería recibida e ingresa stock al almacén.</span>
            </a>
        <?php endif; ?>
        <?php if (in_array('consultar_ordenes_compra_producto.php', $paginas_permitidas)): ?>
            <a href="consultar_ordenes_compra_producto.php" class="card">
                <strong>🔎 Consultar Órdenes de Compra</strong>
                <span>Busca y haz seguimiento a las órdenes emitidas.</span>
            </a>
        <?php endif; ?>
        <?php if (in_array('consultar_detalles_compras.php', $paginas_permitidas)): ?>
            <a href="consultar_detalles_compras.php" class="card">
                <strong>📄 Consultar Detalles de Compras</strong>
                <span>Revisa ítems, costos unitarios y cantidades adquiridas.</span>
            </a>
        <?php endif; ?>
        <?php if (in_array('ver_detalle_compra.php', $paginas_permitidas)): ?>
            <a href="ver_detalle_compra.php" class="card">
                <strong>🧾 Ver Detalle de Compra</strong>
                <span>Inspección individual por número de orden de compra.</span>
            </a>
        <?php endif; ?>
    </div>

    <!-- SECCIÓN 3: ALMACÉN Y OPERACIONES -->
    <div class="seccion-titulo">Almacén, Mesas, Productos y Tickets</div>
    <div class="grid">
        <?php if (in_array('gestion_mesas.php', $paginas_permitidas)): ?>
            <a href="gestion_mesas.php" class="card" style="border-color: rgba(0, 255, 204, 0.4);">
                <strong>🪑 Gestión de Mesas y Aforo</strong>
                <span>Control de mesas, boxes, capacidad y sincronización con pedidos de clientes.</span>
            </a>
        <?php endif; ?>
        <?php if (in_array('gestion_productos.php', $paginas_permitidas)): ?>
            <a href="gestion_productos.php" class="card">
                <strong>🍸 Gestión de Productos</strong>
                <span>Mantenimiento de catálogo, precios, marcas y categorías.</span>
            </a>
        <?php endif; ?>
        <?php if (in_array('gestion_tickets.php', $paginas_permitidas)): ?>
            <a href="gestion_tickets.php" class="card">
                <strong>🎟️ Gestión de Tickets</strong>
                <span>Configura tipos de entradas, precios y horarios de eventos.</span>
            </a>
        <?php endif; ?>
        <?php if (in_array('verificar_stock.php', $paginas_permitidas)): ?>
            <a href="verificar_stock.php" class="card">
                <strong>⚠️ Verificar Stock</strong>
                <span>Monitorea niveles mínimos y máximos en los almacenes.</span>
            </a>
        <?php endif; ?>
        <?php if (in_array('kardex_productos.php', $paginas_permitidas)): ?>
            <a href="kardex_productos.php" class="card">
                <strong>📋 Kardex de Productos</strong>
                <span>Auditoría de ingresos y salidas de bebidas y productos.</span>
            </a>
        <?php endif; ?>
        <?php if (in_array('kardex_insumos.php', $paginas_permitidas)): ?>
            <a href="kardex_insumos.php" class="card">
                <strong>📋 Kardex de Insumos</strong>
                <span>Trazabilidad de movimientos y vencimientos de insumos.</span>
            </a>
        <?php endif; ?>
    </div>

    <!-- SECCIÓN 4: REPORTES GERENCIALES -->
    <div class="seccion-titulo">Reportes Financieros y Estadísticos</div>
    <div class="grid">
        <?php if (in_array('reporte_ingresos_pagos.php', $paginas_permitidas)): ?>
            <a href="reporte_ingresos_pagos.php" class="card">
                <strong>📈 Reporte de Ingresos y Pagos</strong>
                <span>Consolidado financiero de ventas y métodos de pago.</span>
            </a>
        <?php endif; ?>
        <?php if (in_array('reporte_inventario_productos.php', $paginas_permitidas)): ?>
            <a href="reporte_inventario_productos.php" class="card">
                <strong>📊 Inventario de Productos</strong>
                <span>Balance y valorización actual del stock de productos.</span>
            </a>
        <?php endif; ?>
        <?php if (in_array('reporte_inventario_insumos.php', $paginas_permitidas)): ?>
            <a href="reporte_inventario_insumos.php" class="card">
                <strong>📊 Inventario de Insumos</strong>
                <span>Disponibilidad general de insumos operativos por sede.</span>
            </a>
        <?php endif; ?>
        <?php if (in_array('reporte_ventas_bebidas.php', $paginas_permitidas)): ?>
            <a href="reporte_ventas_bebidas.php" class="card">
                <strong>📑 Reporte de Ventas de Bebidas</strong>
                <span>Estadísticas de consumo y pedidos en mesas y barra.</span>
            </a>
        <?php endif; ?>
        <?php if (in_array('reporte_ventas_tickets.php', $paginas_permitidas)): ?>
            <a href="reporte_ventas_tickets.php" class="card">
                <strong>📑 Reporte de Ventas de Tickets</strong>
                <span>Resumen de entradas vendidas e ingresos por evento.</span>
            </a>
        <?php endif; ?>
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