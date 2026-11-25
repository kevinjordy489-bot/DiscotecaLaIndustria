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

// Recoger proveedor preseleccionado por GET (si viene de consultar_proveedores.php)
$proveedor_preseleccionado = trim($_GET['cod_proveedor'] ?? '');

$proveedores = [];
$sedes = [];
$empleados = [];
$productos = [];

try {
    // 1. Cargar catálogos para los selects
    $proveedores = $conn->query("SELECT p.CodProveedor, ep.Razon_Social FROM Tb_Proveedor p INNER JOIN Tb_Empresa_Proveedor ep ON p.CodEmpresa_Proveedor = ep.CodEmpresa_Proveedor WHERE p.Estado_Proveedor = 'Activo'")->fetchAll(PDO::FETCH_ASSOC);
    $sedes = $conn->query("SELECT CodSede, Sede FROM Tb_Sede WHERE Estado_Sede = 'Activo'")->fetchAll(PDO::FETCH_ASSOC);
    $empleados = $conn->query("SELECT CodEmpleado FROM Tb_Empleado WHERE Estado_Empleado = 'Activo'")->fetchAll(PDO::FETCH_ASSOC);
    $productos = $conn->query("SELECT CodProducto, Producto, Prec_Unitario FROM Tb_Producto WHERE Estado_Producto = 'Activo'")->fetchAll(PDO::FETCH_ASSOC);

    // 2. Generar número de orden automático (ej. OCP000000001)
    $siguiente_orden = "OCP000000001";
    $stmt_ord = $conn->query("SELECT TOP 1 Nro_OrdenCompra_Producto FROM Tb_Orden_Compra_Producto ORDER BY Nro_OrdenCompra_Producto DESC");
    $ultima_ord = $stmt_ord->fetch(PDO::FETCH_ASSOC);

    if ($ultima_ord) {
        $ultimo_codigo = trim($ultima_ord['Nro_OrdenCompra_Producto']);
        $numero = (int)substr($ultimo_codigo, 3);
        $siguiente_numero = $numero + 1;
        $siguiente_orden = "OCP" . str_pad($siguiente_numero, 9, "0", STR_PAD_LEFT);
    }
} catch (PDOException $e) {
    $error = "Error al cargar catálogos: " . $e->getMessage();
}

// 3. PROCESAR EL REGISTRO DE LA ORDEN DE COMPRA CON MÚLTIPLES PRODUCTOS
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guardar_orden'])) {
    $nro_orden     = $siguiente_orden;
    $cod_sede      = trim($_POST['cod_sede'] ?? '');
    $cod_empleado  = trim($_POST['cod_empleado'] ?? '');
    $cod_proveedor = trim($_POST['cod_proveedor'] ?? '');
    $fec_entrega   = trim($_POST['fec_entrega'] ?? '');
    
    // Arrays de los productos enviados desde el formulario dinámico
    $arr_productos = $_POST['cod_producto'] ?? [];
    $arr_cantidades = $_POST['cantidad'] ?? [];
    $arr_precios    = $_POST['precio_unitario'] ?? [];
    $arr_obs        = $_POST['observacion'] ?? [];

    if (empty($cod_sede) || empty($cod_empleado) || empty($cod_proveedor) || empty($fec_entrega) || empty($arr_productos)) {
        $error = "Por favor, complete los datos generales de la orden y agregue al menos un producto.";
    } else {
        try {
            $conn->beginTransaction();

            $total_orden = 0;
            $detalles_a_guardar = [];

            // Calcular importes y validar filas
            for ($i = 0; $i < count($arr_productos); $i++) {
                $prod = trim($arr_productos[$i]);
                $cant = (float)($arr_cantidades[$i] ?? 0);
                $prec = (float)($arr_precios[$i] ?? 0);
                $obs  = trim($arr_obs[$i] ?? 'Sin observación');

                if (!empty($prod) && $cant > 0 && $prec >= 0) {
                    $importe = $cant * $prec;
                    $total_orden += $importe;

                    $detalles_a_guardar[] = [
                        'producto' => $prod,
                        'cantidad' => $cant,
                        'precio'   => $prec,
                        'importe'  => $importe,
                        'obs'      => $obs
                    ];
                }
            }

            if (empty($detalles_a_guardar)) {
                throw new Exception("Debe ingresar al menos un producto válido con cantidad mayor a 0.");
            }

            // A. Insertar Cabecera (Tb_Orden_Compra_Producto)
            $sql_cab = "INSERT INTO Tb_Orden_Compra_Producto (Nro_OrdenCompra_Producto, CodSede, CodEmpleado, CodProveedor, Fec_Orden, Fec_Entrega, Total, Estado_OrdenCompra_Producto) 
                        VALUES (?, ?, ?, ?, GETDATE(), ?, ?, 'Pendiente')";
            $stmt_cab = $conn->prepare($sql_cab);
            $stmt_cab->execute([$nro_orden, $cod_sede, $cod_empleado, $cod_proveedor, $fec_entrega, $total_orden]);

            // B. Insertar Detalles (Tb_Detalle_Compra_Producto)
            $sql_det = "INSERT INTO Tb_Detalle_Compra_Producto (Nro_OrdenCompra_Producto, CodSede, CodProducto, Prec_Unitario, Cantidad, Importe, Observacion_Recepcion) 
                        VALUES (?, ?, ?, ?, ?, ?, ?)";
            $stmt_det = $conn->prepare($sql_det);

            foreach ($detalles_a_guardar as $det) {
                $stmt_det->execute([
                    $nro_orden, 
                    $cod_sede, 
                    $det['producto'], 
                    $det['precio'], 
                    $det['cantidad'], 
                    $det['importe'], 
                    $det['obs']
                ]);
            }

            $conn->commit();
            $mensaje = "¡Orden de Compra <strong>$nro_orden</strong> registrada exitosamente con múltiples productos!";
            
            // Refrescar código siguiente
            $siguiente_numero = (int)substr($nro_orden, 3) + 1;
            $siguiente_orden = "OCP" . str_pad($siguiente_numero, 9, "0", STR_PAD_LEFT);

        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            $error = "Error al registrar la orden de compra: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nueva Orden de Compra de Productos - La Industria Surco</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Segoe UI', system-ui, -apple-system, sans-serif; background-color: #0b0b10; color: #f1f1f5; margin: 0; padding: 0; }
        
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

        /* Menú Lateral (Sidebar) con el orden idéntico */
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
        .btn-volver { display: inline-block; color: #00ffcc; text-decoration: none; font-weight: 600; margin-bottom: 20px; }
        .btn-volver:hover { text-decoration: underline; }
        
        .seccion-titulo { font-size: 1.4rem; color: #fff; border-left: 4px solid #00ffcc; padding-left: 12px; font-weight: 700; margin-bottom: 25px; }

        .card-form { background: #161622; border: 1px solid #262638; border-radius: 12px; padding: 30px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); margin-bottom: 30px; }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 18px; margin-bottom: 25px; }
        .form-group label { display: block; margin-bottom: 6px; color: #ccc; font-size: 0.85rem; font-weight: 600; }
        .form-control { width: 100%; padding: 10px 12px; background: #0f0f17; border: 1px solid #323248; border-radius: 8px; color: #fff; font-size: 0.9rem; outline: none; }
        .form-control:focus { border-color: #00ffcc; }

        .tabla-productos { width: 100%; border-collapse: collapse; margin-top: 15px; margin-bottom: 20px; }
        .tabla-productos th { background: #1f1f2e; color: #00ffcc; padding: 12px; font-size: 0.85rem; text-align: left; border-bottom: 1px solid #262638; }
        .tabla-productos td { padding: 10px; border-bottom: 1px solid #222233; }
        
        .btn-add { background: #252535; color: #00ffcc; border: 1px solid #00ffcc; padding: 8px 15px; border-radius: 6px; font-weight: bold; cursor: pointer; font-size: 0.85rem; }
        .btn-add:hover { background: #00ffcc; color: #0b0b10; }
        
        .btn-del { background: #252535; color: #ff4466; border: 1px solid #ff4466; padding: 8px 12px; border-radius: 6px; font-weight: bold; cursor: pointer; }
        .btn-del:hover { background: #ff4466; color: #fff; }

        .btn-guardar { background: linear-gradient(135deg, #00ffcc 0%, #00b388 100%); color: #0b0b10; padding: 12px 25px; border-radius: 6px; border: none; font-weight: bold; font-size: 1rem; cursor: pointer; }

        .alerta-exito { background: rgba(0,255,204,0.1); border: 1px solid #00ffcc; color: #00ffcc; padding: 12px 15px; border-radius: 8px; margin-bottom: 20px; }
        .alerta-error { background: rgba(255,0,85,0.12); border: 1px solid #ff0055; color: #ff80aa; padding: 12px 15px; border-radius: 8px; margin-bottom: 20px; }
    </style>
</head>
<body>

<!-- Menú Lateral (Sidebar) -->
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
            <li><a href="registrar_orden_compra_producto.php" class="activo" style="color: #ff0055;">🛒 Orden Compra (Productos)</a></li>
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
            <li><a href="reporte_ventas_tickets.php">📑 Ventas de Tickets</a></li>
        <?php endif; ?>
    </ul>

    <div class="sidebar-section-title">Sesión</div>
    <ul class="sidebar-menu">
        <li><a href="cerrar_sesion.php" style="color: #ff6699;">🚪 Cerrar Sesión</a></li>
    </ul>
</div>

<!-- Barra Superior -->
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
                <li><a href="registrar_orden_compra_producto.php" class="activo" style="color: #ff0055; font-weight: bold;">Compras</a></li>
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

    <h2 class="seccion-titulo">Generar Orden de Compra de Productos (Múltiples Items)</h2>

    <?php if ($mensaje): ?>
        <div class="alerta-exito"><?php echo $mensaje; ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alerta-error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div class="card-form">
        <form method="POST" action="registrar_orden_compra_producto.php">
            <!-- DATOS GENERALES DE LA ORDEN -->
            <div class="form-grid">
                <div class="form-group">
                    <label>Nro. Orden de Compra:</label>
                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($siguiente_orden); ?>" disabled style="color: #00ffcc; font-weight: bold;">
                </div>
                <div class="form-group">
                    <label>Sede de Destino:</label>
                    <select name="cod_sede" class="form-control" required>
                        <option value="">-- Seleccione Sede --</option>
                        <?php foreach ($sedes as $s): ?>
                            <option value="<?php echo htmlspecialchars(trim($s['CodSede'])); ?>"><?php echo htmlspecialchars($s['Sede']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Proveedor:</label>
                    <select name="cod_proveedor" class="form-control" required>
                        <option value="">-- Seleccione Proveedor --</option>
                        <?php foreach ($proveedores as $prov): 
                            $sel = (trim($prov['CodProveedor']) === $proveedor_preseleccionado) ? 'selected' : '';
                        ?>
                            <option value="<?php echo htmlspecialchars(trim($prov['CodProveedor'])); ?>" <?php echo $sel; ?>><?php echo htmlspecialchars($prov['Razon_Social']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Empleado Responsable:</label>
                    <select name="cod_empleado" class="form-control" required>
                        <option value="">-- Seleccione Empleado --</option>
                        <?php foreach ($empleados as $emp): ?>
                            <option value="<?php echo htmlspecialchars(trim($emp['CodEmpleado'])); ?>"><?php echo htmlspecialchars($emp['CodEmpleado']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Fecha Estimada de Entrega:</label>
                    <input type="date" name="fec_entrega" class="form-control" required value="<?php echo date('Y-m-d'); ?>">
                </div>
            </div>

            <hr style="border: 0; border-top: 1px solid #262638; margin: 25px 0;">

            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                <h3 style="margin: 0; font-size: 1.1rem; color: #fff;">Detalle de Productos</h3>
                <button type="button" class="btn-add" onclick="agregarFila()">+ Añadir Producto</button>
            </div>

            <!-- TABLA DINÁMICA DE PRODUCTOS -->
            <div style="overflow-x: auto;">
                <table class="tabla-productos" id="tablaDetalle">
                    <thead>
                        <tr>
                            <th style="width: 35%;">Producto</th>
                            <th style="width: 15%;">Cantidad</th>
                            <th style="width: 15%;">Costo Unit. (S/.)</th>
                            <th style="width: 25%;">Observación</th>
                            <th style="width: 10%;">Acción</th>
                        </tr>
                    </thead>
                    <tbody id="cuerpoDetalle">
                        <tr>
                            <td>
                                <select name="cod_producto[]" class="form-control" required onchange="actualizarPrecio(this)">
                                    <option value="">-- Seleccione Producto --</option>
                                    <?php foreach ($productos as $p): ?>
                                        <option value="<?php echo htmlspecialchars(trim($p['CodProducto'])); ?>" data-precio="<?php echo $p['Prec_Unitario']; ?>">
                                            <?php echo htmlspecialchars($p['Producto']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td><input type="number" step="0.001" name="cantidad[]" class="form-control" required placeholder="0.000" value="1"></td>
                            <td><input type="number" step="0.01" name="precio_unitario[]" class="form-control" required placeholder="0.00"></td>
                            <td><input type="text" name="observacion[]" class="form-control" placeholder="Ej. Lote buen estado"></td>
                            <td><button type="button" class="btn-del" onclick="eliminarFila(this)">X</button></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div style="margin-top: 30px; text-align: right;">
                <button type="submit" name="guardar_orden" class="btn-guardar">Guardar Orden de Compra Completa</button>
            </div>
        </form>
    </div>
</div>

<script>
    function toggleMenu() {
        document.getElementById('sidebar').classList.toggle('active');
        document.getElementById('overlay').classList.toggle('active');
    }

    const opcionesProductos = `
        <option value="">-- Seleccione Producto --</option>
        <?php foreach ($productos as $p): ?>
            <option value="<?php echo htmlspecialchars(trim($p['CodProducto'])); ?>" data-precio="<?php echo $p['Prec_Unitario']; ?>">
                <?php echo htmlspecialchars($p['Producto']); ?>
            </option>
        <?php endforeach; ?>
    `;

    function agregarFila() {
        const tbody = document.getElementById('cuerpoDetalle');
        const nuevaFila = document.createElement('tr');
        nuevaFila.innerHTML = `
            <td>
                <select name="cod_producto[]" class="form-control" required onchange="actualizarPrecio(this)">
                    ${opcionesProductos}
                </select>
            </td>
            <td><input type="number" step="0.001" name="cantidad[]" class="form-control" required placeholder="0.000" value="1"></td>
            <td><input type="number" step="0.01" name="precio_unitario[]" class="form-control" required placeholder="0.00"></td>
            <td><input type="text" name="observacion[]" class="form-control" placeholder="Ej. Lote buen estado"></td>
            <td><button type="button" class="btn-del" onclick="eliminarFila(this)">X</button></td>
        `;
        tbody.appendChild(nuevaFila);
    }

    function eliminarFila(boton) {
        const tbody = document.getElementById('cuerpoDetalle');
        if (tbody.rows.length > 1) {
            boton.closest('tr').remove();
        } else {
            alert("La orden debe tener al menos un producto.");
        }
    }

    function actualizarPrecio(select) {
        const option = select.options[select.selectedIndex];
        const precio = option.getAttribute('data-precio');
        const fila = select.closest('tr');
        const inputPrecio = fila.querySelector('input[name="precio_unitario[]"]');
        if (precio && inputPrecio) {
            inputPrecio.value = precio;
        }
    }
</script>

</body>
</html>