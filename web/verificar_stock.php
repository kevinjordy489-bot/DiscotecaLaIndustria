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
$tipo_almacen = trim($_GET['tipo'] ?? 'productos'); // 'productos' o 'insumos'
$filtro_sede = trim($_GET['sede'] ?? '');

$sedes = [];
$inventario = [];

try {
    // 1. Cargar listado de sedes para el filtro
    $sedes = $conn->query("SELECT CodSede, Sede FROM Tb_Sede WHERE Estado_Sede = 'Activo'")->fetchAll(PDO::FETCH_ASSOC);

    // 2. Consultar inventario según el tipo seleccionado (Productos o Insumos)
    if ($tipo_almacen === 'productos') {
        $sql = "SELECT ap.CodSede, s.Sede, ap.CodProducto AS Codigo, pr.Producto AS Nombre, 
                       ap.Stock_Minimo, ap.Stock_Maximo, ap.Stock_Actual, ap.Estado_Stock_Producto AS Estado
                FROM Tb_Almacen_Producto ap
                INNER JOIN Tb_Sede s ON ap.CodSede = s.CodSede
                INNER JOIN Tb_Producto pr ON ap.CodProducto = pr.CodProducto
                WHERE 1=1";
    } else {
        $sql = "SELECT ai.CodSede, s.Sede, ai.CodInsumo AS Codigo, ins.Nombre_Insumo AS Nombre, 
                       ai.Stock_Minimo, ai.Stock_Maximo, ai.Stock_Actual, ai.Estado_Stock_Insumo AS Estado
                FROM Tb_Almacen_Insumo ai
                INNER JOIN Tb_Sede s ON ai.CodSede = s.CodSede
                INNER JOIN Tb_Insumo ins ON ai.CodInsumo = ins.CodInsumo
                WHERE 1=1";
    }

    $params = [];
    if (!empty($filtro_sede)) {
        if ($tipo_almacen === 'productos') {
            $sql .= " AND ap.CodSede = ?";
        } else {
            $sql .= " AND ai.CodSede = ?";
        }
        $params[] = $filtro_sede;
    }

    $sql .= " ORDER BY s.Sede ASC, Nombre ASC";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $inventario = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error = "Error al consultar el almacén: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Control de Stock Mínimo y Reposición - La Industria</title>
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

        .container { max-width: 1350px; margin: 35px auto; padding: 0 25px; }
        .btn-volver { display: inline-block; color: #ff6699; text-decoration: none; font-weight: 600; margin-bottom: 20px; }
        .btn-volver:hover { text-decoration: underline; }
        
        .header-flex { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; flex-wrap: wrap; gap: 15px; }
        .seccion-titulo { font-size: 1.4rem; color: #fff; border-left: 4px solid #ff0055; padding-left: 12px; font-weight: 700; margin: 0; }

        /* Pestañas de Navegación (Productos / Insumos) */
        .tabs-container { display: flex; gap: 10px; margin-bottom: 20px; }
        .tab-btn { background: #161622; border: 1px solid #323248; color: #aaa; padding: 10px 20px; border-radius: 8px; font-weight: bold; text-decoration: none; font-size: 0.9rem; }
        .tab-btn.active { background: #ff0055; color: #fff; border-color: #ff0055; }

        .search-card { background: #161622; border: 1px solid #262638; border-radius: 12px; padding: 20px; margin-bottom: 25px; box-shadow: 0 8px 25px rgba(0,0,0,0.4); }
        .search-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)) 140px; gap: 15px; align-items: flex-end; }
        
        .form-group label { display: block; margin-bottom: 6px; color: #ccc; font-size: 0.85rem; font-weight: 600; }
        .form-control { width: 100%; padding: 10px 12px; background: #0f0f17; border: 1px solid #323248; border-radius: 8px; color: #fff; font-size: 0.9rem; outline: none; }
        .form-control:focus { border-color: #ff0055; }
        
        .btn-buscar { background: #252535; color: #ff80aa; border: 1px solid rgba(255,0,85,0.4); padding: 10px; border-radius: 8px; font-weight: bold; cursor: pointer; text-align: center; text-decoration: none; display: block; width: 100%; transition: background 0.2s, color 0.2s; }
        .btn-buscar:hover { background: #ff0055; color: #fff; }
        .btn-limpiar { color: #aaa; text-decoration: none; font-size: 0.82rem; margin-top: 6px; display: inline-block; text-align: center; width: 100%; }
        .btn-limpiar:hover { color: #fff; }

        .table-container { background: #161622; border: 1px solid #262638; border-radius: 12px; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.5); }
        table { width: 100%; border-collapse: collapse; text-align: left; font-size: 0.86rem; }
        th { background: #1f1f2e; color: #ff80aa; padding: 14px; font-weight: 600; border-bottom: 1px solid #262638; }
        td { padding: 14px; border-bottom: 1px solid #222233; color: #d0d0d8; }
        tr:hover { background: #1a1a29; }

        /* Insignias de Alerta de Stock */
        .badge-critico { background: rgba(255,0,85,0.15); color: #ff4466; border: 1px solid #ff4466; padding: 5px 10px; border-radius: 20px; font-size: 0.78rem; font-weight: bold; display: inline-block; }
        .badge-optimo { background: rgba(0,255,204,0.15); color: #00ffcc; border: 1px solid #00ffcc; padding: 5px 10px; border-radius: 20px; font-size: 0.78rem; font-weight: bold; display: inline-block; }
        
        .btn-reponer { background: #252535; color: #ffc107; padding: 6px 12px; border-radius: 6px; text-decoration: none; font-size: 0.78rem; font-weight: bold; border: 1px solid #ffc107; display: inline-flex; align-items: center; gap: 5px; }
        .btn-reponer:hover { background: #ffc107; color: #0b0b10; }

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
            <li><a href="verificar_stock.php" class="activo" style="color: #ff0055;">⚠️ Verificar Stock</a></li>
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
    <a href="panel_control.php" class="btn-volver">&larr; Volver al Panel de Control</a>

    <div class="header-flex">
        <h2 class="seccion-titulo">Auditoría de Stock Mínimo y Necesidades de Reposición</h2>
    </div>

    <?php if ($error): ?>
        <div class="alerta-error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <!-- Pestañas para alternar entre Almacén de Productos y de Insumos -->
    <div class="tabs-container">
        <a href="verificar_stock.php?tipo=productos" class="tab-btn <?php echo ($tipo_almacen === 'productos') ? 'active' : ''; ?>">🍻 Almacén de Productos</a>
        <a href="verificar_stock.php?tipo=insumos" class="tab-btn <?php echo ($tipo_almacen === 'insumos') ? 'active' : ''; ?>">🌾 Almacén de Insumos</a>
    </div>

    <!-- FILTROS DE BÚSQUEDA -->
    <div class="search-card">
        <form method="GET" action="verificar_stock.php">
            <input type="hidden" name="tipo" value="<?php echo htmlspecialchars($tipo_almacen); ?>">
            <div class="search-grid">
                <div class="form-group" style="margin-bottom:0;">
                    <label>Filtrar por Sede:</label>
                    <select name="sede" class="form-control">
                        <option value="">-- Todas las Sedes --</option>
                        <?php foreach ($sedes as $s): ?>
                            <option value="<?php echo htmlspecialchars(trim($s['CodSede'])); ?>" <?php echo ($filtro_sede === trim($s['CodSede'])) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($s['Sede']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <button type="submit" class="btn-buscar">🔍 Filtrar</button>
                    <a href="verificar_stock.php?tipo=<?php echo htmlspecialchars($tipo_almacen); ?>" class="btn-limpiar">Limpiar filtros</a>
                </div>
            </div>
        </form>
    </div>

    <!-- TABLA DE INVENTARIO Y ESTADO DE STOCK -->
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Sede</th>
                    <th>Código</th>
                    <th><?php echo ($tipo_almacen === 'productos') ? 'Producto' : 'Insumo'; ?></th>
                    <th>Stock Mínimo</th>
                    <th>Stock Máximo</th>
                    <th>Stock Actual</th>
                    <th>Estado de Alerta</th>
                    <th>Acción Sugerida</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($inventario)): ?>
                    <tr>
                        <td colspan="8" style="text-align: center; color: #8888a0; padding: 30px;">No se encontraron registros de inventario en almacén.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($inventario as $item): 
                        $stock_actual = (float)$item['Stock_Actual'];
                        $stock_minimo = (float)$item['Stock_Minimo'];
                        
                        $es_critico = ($stock_actual <= $stock_minimo);
                    ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($item['Sede']); ?></strong></td>
                            <td><?php echo htmlspecialchars(trim($item['Codigo'])); ?></td>
                            <td><?php echo htmlspecialchars($item['Nombre']); ?></td>
                            <td><?php echo number_format($stock_minimo, 3); ?></td>
                            <td><?php echo number_format((float)$item['Stock_Maximo'], 3); ?></td>
                            <td><strong><?php echo number_format($stock_actual, 3); ?></strong></td>
                            <td>
                                <?php if ($es_critico): ?>
                                    <span class="badge-critico">⚠️ Stock Bajo / Reponer</span>
                                <?php else: ?>
                                    <span class="badge-optimo">✅ Óptimo</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($es_critico): ?>
                                    <?php if ($tipo_almacen === 'productos'): ?>
                                        <a href="registrar_orden_compra_producto.php" class="btn-reponer" title="Generar Orden de Compra">📄 Solicitar Pedido</a>
                                    <?php else: ?>
                                        <a href="generar_orden_compra_insumo.php" class="btn-reponer" title="Generar Orden de Compra de Insumos">📦 Solicitar Insumo</a>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="color: #666; font-size: 0.82rem;">Stock suficiente</span>
                                <?php endif; ?>
                            </td>
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
</script>

</body>
</html>