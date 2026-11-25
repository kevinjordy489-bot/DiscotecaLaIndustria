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

$error = "";

// Recoger filtros de búsqueda (GET)
$filtro_orden = trim($_GET['nro_orden'] ?? '');
$filtro_estado = trim($_GET['estado'] ?? '');

// Consultar listado general de órdenes de compra
$ordenes = [];
try {
    $sql_list = "SELECT ocp.Nro_OrdenCompra_Producto, s.Sede, ep.Razon_Social, ocp.CodEmpleado, ocp.Fec_Orden, ocp.Fec_Entrega, ocp.Total, ocp.Estado_OrdenCompra_Producto
                 FROM Tb_Orden_Compra_Producto ocp
                 INNER JOIN Tb_Sede s ON ocp.CodSede = s.CodSede
                 INNER JOIN Tb_Proveedor p ON ocp.CodProveedor = p.CodProveedor
                 INNER JOIN Tb_Empresa_Proveedor ep ON p.CodEmpresa_Proveedor = ep.CodEmpresa_Proveedor
                 WHERE 1=1";
    
    $params = [];
    if (!empty($filtro_orden)) {
        $sql_list .= " AND (ocp.Nro_OrdenCompra_Producto LIKE ? OR s.Sede LIKE ? OR ep.Razon_Social LIKE ?)";
        $params[] = "%" . $filtro_orden . "%";
        $params[] = "%" . $filtro_orden . "%";
        $params[] = "%" . $filtro_orden . "%";
    }
    if (!empty($filtro_estado)) {
        $sql_list .= " AND ocp.Estado_OrdenCompra_Producto = ?";
        $params[] = $filtro_estado;
    }
    $sql_list .= " ORDER BY ocp.Fec_Orden DESC";

    $stmt_ordenes = $conn->prepare($sql_list);
    $stmt_ordenes->execute($params);
    $ordenes = $stmt_ordenes->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Error al consultar las órdenes de compra: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CU47: Consulta y Detalle de Órdenes de Compra - La Industria Surco</title>
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

        .container { max-width: 1300px; margin: 35px auto; padding: 0 25px; }
        .btn-volver { display: inline-block; color: #ff6699; text-decoration: none; font-weight: 600; margin-bottom: 20px; }
        .btn-volver:hover { text-decoration: underline; }
        
        .header-flex { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; flex-wrap: wrap; gap: 15px; }
        .seccion-titulo { font-size: 1.4rem; color: #fff; border-left: 4px solid #ff0055; padding-left: 12px; font-weight: 700; margin: 0; }

        .search-card { background: #161622; border: 1px solid #262638; border-radius: 12px; padding: 20px; margin-bottom: 25px; box-shadow: 0 8px 25px rgba(0,0,0,0.4); }
        .search-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)) 140px; gap: 15px; align-items: flex-end; }
        
        .form-group label { display: block; margin-bottom: 6px; color: #ccc; font-size: 0.85rem; font-weight: 600; }
        .form-control { width: 100%; padding: 10px 12px; background: #0f0f17; border: 1px solid #323248; border-radius: 8px; color: #fff; font-size: 0.9rem; outline: none; }
        .form-control:focus { border-color: #ff0055; }
        
        .btn-buscar { background: #252535; color: #00ffcc; border: 1px solid #00ffcc; padding: 10px; border-radius: 8px; font-weight: bold; cursor: pointer; text-align: center; text-decoration: none; display: block; width: 100%; }
        .btn-buscar:hover { background: #00ffcc; color: #0b0b10; }
        .btn-limpiar { color: #aaa; text-decoration: none; font-size: 0.82rem; margin-top: 6px; display: inline-block; text-align: center; width: 100%; }
        .btn-limpiar:hover { color: #fff; }
        .btn-nuevo { background: linear-gradient(135deg, #ff0055 0%, #b3003c 100%); color: #fff; padding: 10px 18px; border-radius: 6px; font-weight: bold; text-decoration: none; display: inline-block; }
        .btn-nuevo:hover { filter: brightness(1.1); }

        .table-container { background: #161622; border: 1px solid #262638; border-radius: 12px; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.5); margin-bottom: 30px; }
        table { width: 100%; border-collapse: collapse; text-align: left; font-size: 0.86rem; }
        th { background: #1f1f2e; color: #ff80aa; padding: 14px; font-weight: 600; border-bottom: 1px solid #262638; }
        td { padding: 14px; border-bottom: 1px solid #222233; color: #d0d0d8; }
        tr:hover { background: #1a1a29; }

        .badge-oc { background: rgba(255,0,85,0.15); color: #ff80aa; border: 1px solid #ff0055; padding: 4px 10px; border-radius: 20px; font-size: 0.78rem; font-weight: bold; }
        .estado-pendiente { color: #ffc107; font-weight: bold; }
        .estado-completado { color: #00ffcc; font-weight: bold; }

        .btn-ver-pagina { background: #252535; color: #ff80aa; padding: 6px 14px; border-radius: 6px; text-decoration: none; font-size: 0.78rem; font-weight: bold; border: 1px solid #ff0055; display: inline-flex; align-items: center; gap: 5px; }
        .btn-ver-pagina:hover { background: #ff0055; color: #fff; }

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
            <li><a href="consultar_ordenes_compra_producto.php" class="activo" style="color: #ff0055;">🔎 Consultar Órdenes</a></li>
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
        <h2 class="seccion-titulo">CU47: Consulta de Órdenes de Compra y Detalle de Productos</h2>
        <a href="registrar_orden_compra_producto.php" class="btn-nuevo">+ Nueva Orden de Compra</a>
    </div>

    <?php if ($error): ?>
        <div class="alerta-error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <!-- FILTROS DE BÚSQUEDA PREDICTIVA -->
    <div class="search-card">
        <form method="GET" action="consultar_ordenes_compra_producto.php">
            <div class="search-grid">
                <div class="form-group" style="margin-bottom:0;">
                    <label>Búsqueda Predictiva (Orden, Sede o Proveedor):</label>
                    <input type="text" name="nro_orden" list="listaSugerencias" class="form-control" placeholder="Escribe o selecciona..." value="<?php echo htmlspecialchars($filtro_orden); ?>" autocomplete="off">
                    <datalist id="listaSugerencias">
                        <?php 
                        $stmt_dl = $conn->query("SELECT DISTINCT ocp.Nro_OrdenCompra_Producto AS Nro, s.Sede, ep.Razon_Social 
                                                 FROM Tb_Orden_Compra_Producto ocp
                                                 INNER JOIN Tb_Sede s ON ocp.CodSede = s.CodSede
                                                 INNER JOIN Tb_Proveedor p ON ocp.CodProveedor = p.CodProveedor
                                                 INNER JOIN Tb_Empresa_Proveedor ep ON p.CodEmpresa_Proveedor = ep.CodEmpresa_Proveedor");
                        while ($row_dl = $stmt_dl->fetch(PDO::FETCH_ASSOC)) {
                            echo '<option value="' . htmlspecialchars(trim($row_dl['Nro'])) . '">Orden: ' . htmlspecialchars(trim($row_dl['Nro'])) . '</option>';
                            echo '<option value="' . htmlspecialchars(trim($row_dl['Sede'])) . '">Sede: ' . htmlspecialchars(trim($row_dl['Sede'])) . '</option>';
                            echo '<option value="' . htmlspecialchars(trim($row_dl['Razon_Social'])) . '">Proveedor: ' . htmlspecialchars(trim($row_dl['Razon_Social'])) . '</option>';
                        }
                        ?>
                    </datalist>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label>Estado:</label>
                    <select name="estado" class="form-control">
                        <option value="">-- Todos --</option>
                        <option value="Pendiente" <?php echo ($filtro_estado === 'Pendiente') ? 'selected' : ''; ?>>Pendiente</option>
                        <option value="Completado" <?php echo ($filtro_estado === 'Completado') ? 'selected' : ''; ?>>Completado</option>
                    </select>
                </div>
                <div>
                    <button type="submit" class="btn-buscar">🔍 Buscar</button>
                    <a href="consultar_ordenes_compra_producto.php" class="btn-limpiar">Limpiar filtros</a>
                </div>
            </div>
        </form>
    </div>

    <!-- TABLA DE ÓRDENES -->
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Nro. Orden</th>
                    <th>Proveedor</th>
                    <th>Sede Destino</th>
                    <th>Fec. Orden</th>
                    <th>Fec. Entrega</th>
                    <th>Total (S/.)</th>
                    <th>Estado</th>
                    <th>Acción Detalle</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($ordenes)): ?>
                    <tr>
                        <td colspan="8" style="text-align: center; color: #8888a0; padding: 30px;">No se encontraron órdenes de compra registradas que coincidan con la búsqueda.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($ordenes as $ord): 
                        $nro_oc = trim($ord['Nro_OrdenCompra_Producto']);
                        $estado = $ord['Estado_OrdenCompra_Producto'];
                        $clase_estado = ($estado === 'Completado') ? 'estado-completado' : 'estado-pendiente';
                    ?>
                        <tr>
                            <td><span class="badge-oc"><?php echo htmlspecialchars($nro_oc); ?></span></td>
                            <td><strong><?php echo htmlspecialchars($ord['Razon_Social']); ?></strong></td>
                            <td><?php echo htmlspecialchars($ord['Sede']); ?></td>
                            <td><?php echo htmlspecialchars($ord['Fec_Orden']); ?></td>
                            <td><?php echo htmlspecialchars($ord['Fec_Entrega']); ?></td>
                            <td><strong>S/. <?php echo number_format($ord['Total'], 2); ?></strong></td>
                            <td><span class="<?php echo $clase_estado; ?>"><?php echo htmlspecialchars($estado); ?></span></td>
                            <td>
                                <!-- Enlace directo a la página dedicada de detalles -->
                                <a href="ver_detalle_compra.php?tipo=productos&nro=<?php echo urlencode($nro_oc); ?>" class="btn-ver-pagina">
                                    📄 Ver Detalle
                                </a>
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