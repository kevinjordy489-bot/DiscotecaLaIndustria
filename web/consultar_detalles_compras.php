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
$tipo_compra = trim($_GET['tipo'] ?? 'productos'); // 'productos' o 'insumos'
$filtro_buscar = trim($_GET['buscar'] ?? '');

$compras = [];

try {
    if ($tipo_compra === 'productos') {
        $sql = "SELECT oc.Nro_OrdenCompra_Producto AS NroOrden, oc.Fec_Orden, oc.Fec_Entrega, 
                       ep.Razon_Social AS Proveedor, s.Sede, oc.Total, oc.Estado_OrdenCompra_Producto AS Estado, oc.CodEmpleado
                FROM Tb_Orden_Compra_Producto oc
                INNER JOIN Tb_Proveedor p ON oc.CodProveedor = p.CodProveedor
                INNER JOIN Tb_Empresa_Proveedor ep ON p.CodEmpresa_Proveedor = ep.CodEmpresa_Proveedor
                INNER JOIN Tb_Sede s ON oc.CodSede = s.CodSede
                WHERE 1=1";
    } else {
        $sql = "SELECT oc.Nro_OrdenCompra_Insumo AS NroOrden, oc.Fec_Orden, oc.Fec_Entrega, 
                       ep.Razon_Social AS Proveedor, s.Sede, oc.Total, oc.Estado_OrdenCompra_Insumo AS Estado, oc.CodEmpleado
                FROM Tb_Orden_Compra_Insumo oc
                INNER JOIN Tb_Proveedor p ON oc.CodProveedor = p.CodProveedor
                INNER JOIN Tb_Empresa_Proveedor ep ON p.CodEmpresa_Proveedor = ep.CodEmpresa_Proveedor
                INNER JOIN Tb_Sede s ON oc.CodSede = s.CodSede
                WHERE 1=1";
    }

    $params = [];
    if (!empty($filtro_buscar)) {
        $campo_id = ($tipo_compra === 'productos') ? 'oc.Nro_OrdenCompra_Producto' : 'oc.Nro_OrdenCompra_Insumo';
        $sql .= " AND ({$campo_id} LIKE ? OR ep.Razon_Social LIKE ? OR s.Sede LIKE ?)";
        $params[] = "%" . $filtro_buscar . "%";
        $params[] = "%" . $filtro_buscar . "%";
        $params[] = "%" . $filtro_buscar . "%";
    }

    $sql .= " ORDER BY oc.Fec_Orden DESC";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $compras = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $error = "Error al consultar las compras: " . $e->getMessage();
}

// ==========================================
// PROCESAMIENTO AJAX INTERNO EN LA MISMA PÁGINA
// ==========================================
if (isset($_GET['ajax_detalle']) && $_GET['ajax_detalle'] === '1') {
    $nro_ajax = trim($_GET['nro'] ?? '');
    $tipo_ajax = trim($_GET['tipo'] ?? 'productos');
    
    try {
        if ($tipo_ajax === 'productos') {
            $sql_cab = "SELECT oc.Nro_OrdenCompra_Producto AS NroOrden, oc.Fec_Orden, oc.Fec_Entrega, 
                               ep.Razon_Social AS Proveedor, s.Sede, oc.Total, oc.Estado_OrdenCompra_Producto AS Estado, oc.CodEmpleado
                        FROM Tb_Orden_Compra_Producto oc
                        INNER JOIN Tb_Proveedor p ON oc.CodProveedor = p.CodProveedor
                        INNER JOIN Tb_Empresa_Proveedor ep ON p.CodEmpresa_Proveedor = ep.CodEmpresa_Proveedor
                        INNER JOIN Tb_Sede s ON oc.CodSede = s.CodSede
                        WHERE oc.Nro_OrdenCompra_Producto = ?";
        } else {
            $sql_cab = "SELECT oc.Nro_OrdenCompra_Insumo AS NroOrden, oc.Fec_Orden, oc.Fec_Entrega, 
                               ep.Razon_Social AS Proveedor, s.Sede, oc.Total, oc.Estado_OrdenCompra_Insumo AS Estado, oc.CodEmpleado
                        FROM Tb_Orden_Compra_Insumo oc
                        INNER JOIN Tb_Proveedor p ON oc.CodProveedor = p.CodProveedor
                        INNER JOIN Tb_Empresa_Proveedor ep ON p.CodEmpresa_Proveedor = ep.CodEmpresa_Proveedor
                        INNER JOIN Tb_Sede s ON oc.CodSede = s.CodSede
                        WHERE oc.Nro_OrdenCompra_Insumo = ?";
        }

        $stmt_c = $conn->prepare($sql_cab);
        $stmt_c->execute([$nro_ajax]);
        $cab = $stmt_c->fetch(PDO::FETCH_ASSOC);

        if ($tipo_ajax === 'productos') {
            $sql_det = "SELECT pr.Producto AS NombreItem, d.Cantidad, d.Prec_Unitario, d.Importe
                        FROM Tb_Detalle_Compra_Producto d
                        INNER JOIN Tb_Producto pr ON d.CodProducto = pr.CodProducto
                        WHERE d.Nro_OrdenCompra_Producto = ?";
        } else {
            $sql_det = "SELECT ins.Nombre_Insumo AS NombreItem, d.Cantidad, d.Prec_Unitario, d.Importe
                        FROM Tb_Detalle_Compra_Insumo d
                        INNER JOIN Tb_Insumo ins ON d.CodInsumo = ins.CodInsumo
                        WHERE d.Nro_OrdenCompra_Insumo = ?";
        }

        $stmt_d = $conn->prepare($sql_det);
        $stmt_d->execute([$nro_ajax]);
        $detalles = $stmt_d->fetchAll(PDO::FETCH_ASSOC);

        if ($cab):
        ?>
        <h3 style="color: #fff; margin-top: 0; font-size: 1.2rem; border-bottom: 1px solid #262638; padding-bottom: 10px;">Información General de la Orden</h3>
        <div class="info-grid">
            <div class="info-group">
                <label>Nro. de Orden:</label>
                <span><span style="color: #ff0055;"><?php echo htmlspecialchars($cab['NroOrden']); ?></span></span>
            </div>
            <div class="info-group">
                <label>Estado:</label>
                <span><?php echo htmlspecialchars($cab['Estado']); ?></span>
            </div>
            <div class="info-group">
                <label>Fecha Emisión:</label>
                <span><?php echo htmlspecialchars($cab['Fec_Orden']); ?></span>
            </div>
            <div class="info-group">
                <label>Fecha Entrega:</label>
                <span><?php echo htmlspecialchars($cab['Fec_Entrega']); ?></span>
            </div>
            <div class="info-group">
                <label>Proveedor:</label>
                <span><?php echo htmlspecialchars($cab['Proveedor']); ?></span>
            </div>
            <div class="info-group">
                <label>Sede Destino:</label>
                <span><?php echo htmlspecialchars($cab['Sede']); ?></span>
            </div>
            <div class="info-group">
                <label>Responsable:</label>
                <span><?php echo htmlspecialchars($cab['CodEmpleado']); ?></span>
            </div>
        </div>

        <h3 style="color: #fff; margin-top: 25px; font-size: 1.1rem;">Ítems Solicitados</h3>
        <table>
            <thead>
                <tr>
                    <th>Descripción</th>
                    <th>Cantidad</th>
                    <th>Costo Unit. (S/.)</th>
                    <th>Importe Total (S/.)</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($detalles as $it): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($it['NombreItem']); ?></strong></td>
                    <td><?php echo number_format((float)$it['Cantidad'], 2); ?></td>
                    <td>S/. <?php echo number_format((float)$it['Prec_Unitario'], 2); ?></td>
                    <td><strong>S/. <?php echo number_format((float)$it['Importe'], 2); ?></strong></td>
                </tr>
                <?php endforeach; ?>
                <tr style="background: #1f1f2e;">
                    <td colspan="3" style="text-align: right; font-weight: bold; color: #ff0055;">TOTAL GENERAL:</td>
                    <td style="font-weight: bold; color: #00ffcc; font-size: 1rem;">S/. <?php echo number_format((float)$cab['Total'], 2); ?></td>
                </tr>
            </tbody>
        </table>

        <!-- SECCIÓN DE FIRMA Y DNI (SOLO VISIBLE AL IMPRIMIR / DESCARGAR PDF) -->
        <div class="print-firma-section">
            <div class="firma-box">
                <div class="firma-linea"></div>
                <p><strong>Firma del Responsable / Recetor</strong></p>
                <p>DNI: ............................................</p>
            </div>
        </div>
        <?php
        else:
            echo '<div style="color: #ff0055; text-align: center; padding: 20px;">No se encontró la orden especificada.</div>';
        endif;
    } catch (Exception $e) {
        echo '<div style="color: #ff0055; text-align: center; padding: 20px;">Error al cargar datos.</div>';
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CU47: Consultar Detalles de Compras - La Industria Surco</title>
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

        .container { max-width: 1350px; margin: 35px auto; padding: 0 25px; }
        .btn-volver { display: inline-block; color: #ff6699; text-decoration: none; font-weight: 600; margin-bottom: 20px; }
        
        .header-flex { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; flex-wrap: wrap; gap: 15px; }
        .seccion-titulo { font-size: 1.4rem; color: #fff; border-left: 4px solid #ff0055; padding-left: 12px; font-weight: 700; margin: 0; }

        /* Pestañas de Navegación */
        .tabs-container { display: flex; gap: 10px; margin-bottom: 20px; }
        .tab-btn { background: #161622; border: 1px solid #323248; color: #aaa; padding: 10px 20px; border-radius: 8px; font-weight: bold; text-decoration: none; font-size: 0.9rem; }
        .tab-btn.active { background: #ff0055; color: #fff; border-color: #ff0055; }

        .search-card { background: #161622; border: 1px solid #262638; border-radius: 12px; padding: 20px; margin-bottom: 25px; box-shadow: 0 8px 25px rgba(0,0,0,0.4); }
        .search-grid { display: grid; grid-template-columns: 1fr 140px; gap: 15px; align-items: flex-end; }
        @media(max-width: 600px) { .search-grid { grid-template-columns: 1fr; } }
        
        .form-group label { display: block; margin-bottom: 6px; color: #ccc; font-size: 0.85rem; font-weight: 600; }
        .form-control { width: 100%; padding: 10px 12px; background: #0f0f17; border: 1px solid #323248; border-radius: 8px; color: #fff; font-size: 0.9rem; outline: none; }
        .form-control:focus { border-color: #ff0055; }
        
        .btn-buscar { background: #252535; color: #00ffcc; border: 1px solid #00ffcc; padding: 10px; border-radius: 8px; font-weight: bold; cursor: pointer; text-align: center; text-decoration: none; display: block; width: 100%; }
        .btn-buscar:hover { background: #00ffcc; color: #0b0b10; }
        .btn-limpiar { color: #aaa; text-decoration: none; font-size: 0.82rem; margin-top: 6px; display: inline-block; text-align: center; width: 100%; }
        .btn-limpiar:hover { color: #fff; }

        .table-container { background: #161622; border: 1px solid #262638; border-radius: 12px; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.5); }
        table { width: 100%; border-collapse: collapse; text-align: left; font-size: 0.86rem; }
        th { background: #1f1f2e; color: #ff80aa; padding: 14px; font-weight: 600; border-bottom: 1px solid #262638; }
        td { padding: 14px; border-bottom: 1px solid #222233; color: #d0d0d8; }
        tr:hover { background: #1a1a29; }

        .badge-oc { background: rgba(255,0,85,0.15); color: #ff80aa; border: 1px solid #ff0055; padding: 4px 10px; border-radius: 20px; font-size: 0.78rem; font-weight: bold; display: inline-block; }
        .badge-estado { background: rgba(255,193,7,0.15); color: #ffc107; padding: 4px 10px; border-radius: 20px; font-size: 0.78rem; font-weight: bold; display: inline-block; }

        .btn-detalle { background: #252535; color: #ff80aa; border: 1px solid #ff80aa; padding: 6px 12px; border-radius: 6px; font-size: 0.78rem; font-weight: bold; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 5px; }
        .btn-detalle:hover { background: #ff0055; color: #fff; }

        /* MODAL FLOTANTE */
        .modal-overlay {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.75); backdrop-filter: blur(5px);
            z-index: 3000; display: none; align-items: center; justify-content: center; padding: 20px;
        }
        .modal-overlay.active { display: flex; }
        .modal-content {
            background: #161622; border: 1px solid #323248; border-radius: 14px;
            width: 100%; max-width: 900px; max-height: 90vh; overflow-y: auto;
            box-shadow: 0 20px 50px rgba(0,0,0,0.8); padding: 30px; position: relative;
        }
        .modal-close {
            position: absolute; top: 20px; right: 25px; background: none; border: none;
            color: #aaa; font-size: 1.8rem; cursor: pointer; font-weight: bold;
        }
        .modal-close:hover { color: #ff0055; }

        .print-header { display: none; }
        .print-firma-section { display: none; }
        .modal-body-container { margin-top: 15px; }

        .info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 20px; }
        .info-group label { display: block; color: #8888a0; font-size: 0.78rem; font-weight: 600; margin-bottom: 3px; text-transform: uppercase; }
        .info-group span { color: #fff; font-size: 0.95rem; font-weight: 600; }

        /* ESTILOS DE IMPRESIÓN / DESCARGA PDF PROFESIONAL (A4) */
        @media print {
            body { background: #fff !important; color: #000 !important; }
            nav, .sidebar, .overlay, .search-card, .tabs-container, .btn-volver, .header-flex, .modal-close, .no-print, .table-container {
                display: none !important;
            }
            .modal-overlay { position: static !important; background: none !important; padding: 0 !important; display: block !important; width: 100% !important; height: auto !important; }
            .modal-content { border: none !important; box-shadow: none !important; max-width: 100% !important; width: 100% !important; padding: 0 !important; background: #fff !important; color: #000 !important; overflow: visible !important; }
            
            .print-header { display: block; text-align: center; margin-bottom: 25px; border-bottom: 2px solid #000; padding-bottom: 15px; }
            .print-header h1 { font-size: 1.6rem; margin: 0; color: #000; font-weight: bold; }
            .print-header p { font-size: 0.95rem; margin: 5px 0 0 0; color: #333; }
            
            .info-group label { color: #333 !important; font-size: 0.8rem; }
            .info-group span { color: #000 !important; font-size: 1rem; }
            .modal-content table th { background: #f0f0f0 !important; color: #000 !important; border-bottom: 2px solid #000 !important; font-size: 0.85rem; }
            .modal-content table td { color: #000 !important; border-bottom: 1px solid #ccc !important; font-size: 0.85rem; }

            /* SECCIÓN DE FIRMA VISIBLE SOLO EN IMPRESIÓN */
            .print-firma-section {
                display: flex !important;
                justify-content: flex-end;
                margin-top: 60px;
                page-break-inside: avoid;
            }
            .firma-box {
                width: 280px;
                text-align: center;
            }
            .firma-linea {
                border-top: 1px solid #000;
                margin-bottom: 8px;
            }
            .firma-box p {
                font-size: 0.85rem;
                color: #000;
                margin: 4px 0;
            }
        }

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
            <li><a href="consultar_detalles_compras.php" class="activo" style="color: #ff0055;">📄 Detalles de Compras</a></li>
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
        <h2 class="seccion-titulo">CU47: Consulta de Órdenes y Detalle de Ítems Comprados</h2>
    </div>

    <?php if ($error): ?>
        <div class="alerta-error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <!-- Pestañas de Navegación -->
    <div class="tabs-container">
        <a href="consultar_detalles_compras.php?tipo=productos" class="tab-btn <?php echo ($tipo_compra === 'productos') ? 'active' : ''; ?>">🍻 Compras de Productos</a>
        <a href="consultar_detalles_compras.php?tipo=insumos" class="tab-btn <?php echo ($tipo_compra === 'insumos') ? 'active' : ''; ?>">🌾 Compras de Insumos</a>
    </div>

    <!-- TARJETA DE BÚSQUEDA PREDICTIVA -->
    <div class="search-card">
        <form method="GET" action="consultar_detalles_compras.php">
            <input type="hidden" name="tipo" value="<?php echo htmlspecialchars($tipo_compra); ?>">
            <div class="search-grid">
                <div class="form-group" style="margin-bottom:0;">
                    <label>Búsqueda Predictiva (Nro. de Orden, Proveedor o Sede):</label>
                    <input type="text" name="buscar" list="listaSugerencias" class="form-control" placeholder="Escribe o selecciona..." value="<?php echo htmlspecialchars($filtro_buscar); ?>" autocomplete="off">
                    <datalist id="listaSugerencias">
                        <?php 
                        if ($tipo_compra === 'productos') {
                            $stmt_dl = $conn->query("SELECT DISTINCT oc.Nro_OrdenCompra_Producto AS Nro, ep.Razon_Social, s.Sede 
                                                     FROM Tb_Orden_Compra_Producto oc
                                                     INNER JOIN Tb_Proveedor p ON oc.CodProveedor = p.CodProveedor
                                                     INNER JOIN Tb_Empresa_Proveedor ep ON p.CodEmpresa_Proveedor = ep.CodEmpresa_Proveedor
                                                     INNER JOIN Tb_Sede s ON oc.CodSede = s.CodSede");
                        } else {
                            $stmt_dl = $conn->query("SELECT DISTINCT oc.Nro_OrdenCompra_Insumo AS Nro, ep.Razon_Social, s.Sede 
                                                     FROM Tb_Orden_Compra_Insumo oc
                                                     INNER JOIN Tb_Proveedor p ON oc.CodProveedor = p.CodProveedor
                                                     INNER JOIN Tb_Empresa_Proveedor ep ON p.CodEmpresa_Proveedor = ep.CodEmpresa_Proveedor
                                                     INNER JOIN Tb_Sede s ON oc.CodSede = s.CodSede");
                        }
                        while ($row_dl = $stmt_dl->fetch(PDO::FETCH_ASSOC)) {
                            echo '<option value="' . htmlspecialchars(trim($row_dl['Nro'])) . '">Orden: ' . htmlspecialchars(trim($row_dl['Nro'])) . '</option>';
                            echo '<option value="' . htmlspecialchars(trim($row_dl['Razon_Social'])) . '">Proveedor: ' . htmlspecialchars(trim($row_dl['Razon_Social'])) . '</option>';
                            echo '<option value="' . htmlspecialchars(trim($row_dl['Sede'])) . '">Sede: ' . htmlspecialchars(trim($row_dl['Sede'])) . '</option>';
                        }
                        ?>
                    </datalist>
                </div>
                <div>
                    <button type="submit" class="btn-buscar">🔍 Buscar</button>
                    <a href="consultar_detalles_compras.php?tipo=<?php echo htmlspecialchars($tipo_compra); ?>" class="btn-limpiar">Limpiar filtros</a>
                </div>
            </div>
        </form>
    </div>

    <!-- TABLA DE COMPRAS (CABECERAS) -->
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>Nro. Orden</th>
                    <th>Fecha Emisión</th>
                    <th>Fecha Entrega</th>
                    <th>Proveedor</th>
                    <th>Sede Destino</th>
                    <th>Total (S/.)</th>
                    <th>Estado</th>
                    <th style="text-align: center;">Acción Detalle</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($compras)): ?>
                    <tr>
                        <td colspan="8" style="text-align: center; color: #8888a0; padding: 30px;">No se encontraron órdenes de compra registradas o que coincidan con la búsqueda.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($compras as $compra): ?>
                        <tr>
                            <td><span class="badge-oc"><?php echo htmlspecialchars(trim($compra['NroOrden'])); ?></span></td>
                            <td><?php echo htmlspecialchars($compra['Fec_Orden']); ?></td>
                            <td><?php echo htmlspecialchars($compra['Fec_Entrega']); ?></td>
                            <td><?php echo htmlspecialchars($compra['Proveedor']); ?></td>
                            <td><strong><?php echo htmlspecialchars($compra['Sede']); ?></strong></td>
                            <td><strong>S/. <?php echo number_format((float)$compra['Total'], 2); ?></strong></td>
                            <td><span class="badge-estado"><?php echo htmlspecialchars($compra['Estado']); ?></span></td>
                            <td style="text-align: center;">
                                <button type="button" class="btn-detalle" onclick="cargarDetalleModal('<?php echo $tipo_compra; ?>', '<?php echo trim($compra['NroOrden']); ?>')">
                                    📄 Ver Detalle
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- MODAL FLOTANTE DE DETALLE E IMPRESIÓN / DESCARGA -->
<div class="modal-overlay" id="modalDetalle">
    <div class="modal-content">
        <button class="modal-close no-print" onclick="cerrarModal()">&times;</button>
        
        <div class="print-header">
            <h1>LA INDUSTRIA - ORDEN DE COMPRA</h1>
            <p>Reporte Oficial de Detalle de Adquisición</p>
        </div>

        <div id="contenidoModalAjax" class="modal-body-container">
            <div style="text-align: center; padding: 40px; color: #888;">Cargando detalles de la orden...</div>
        </div>

        <div style="margin-top: 25px; display: flex; justify-content: flex-end; gap: 15px;" class="no-print">
            <button type="button" onclick="window.print()" style="background: linear-gradient(135deg, #00ffcc 0%, #00b388 100%); color: #0b0b10; border: none; padding: 10px 20px; border-radius: 8px; font-weight: bold; cursor: pointer;">📥 Descargar / Imprimir Orden</button>
            <button type="button" onclick="cerrarModal()" style="background: #252535; color: #fff; border: 1px solid #444; padding: 10px 20px; border-radius: 8px; font-weight: bold; cursor: pointer;">Cerrar</button>
        </div>
    </div>
</div>

<script>
    function toggleMenu() {
        document.getElementById('sidebar').classList.toggle('active');
        document.getElementById('overlay').classList.toggle('active');
    }

    function cargarDetalleModal(tipo, nro) {
        const modal = document.getElementById('modalDetalle');
        const contenedor = document.getElementById('contenidoModalAjax');
        
        modal.classList.add('active');
        contenedor.innerHTML = '<div style="text-align: center; padding: 40px; color: #888;">Cargando información...</div>';

        // Petición asíncrona hacia la misma página pidiendo el detalle mediante AJAX
        fetch(`consultar_detalles_compras.php?ajax_detalle=1&tipo=${tipo}&nro=${encodeURIComponent(nro)}`)
            .then(response => response.text())
            .then(html => {
                contenedor.innerHTML = html;
            })
            .catch(err => {
                contenedor.innerHTML = '<div style="color: #ff0055; text-align: center; padding: 20px;">Error al cargar el detalle de la orden.</div>';
            });
    }

    function cerrarModal() {
        document.getElementById('modalDetalle').classList.remove('active');
    }
</script>

</body>
</html>