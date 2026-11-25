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

$proveedores = [];
$empleados = [];
$sedes = [];
$insumos_catalogo = [];

try {
    $proveedores = $conn->query("SELECT p.CodProveedor, ep.Razon_Social FROM Tb_Proveedor p INNER JOIN Tb_Empresa_Proveedor ep ON p.CodEmpresa_Proveedor = ep.CodEmpresa_Proveedor WHERE p.Estado_Proveedor = 'Activo'")->fetchAll(PDO::FETCH_ASSOC);
    $empleados = $conn->query("SELECT CodEmpleado FROM Tb_Empleado WHERE Estado_Empleado = 'Activo'")->fetchAll(PDO::FETCH_ASSOC);
    $sedes = $conn->query("SELECT CodSede, Sede FROM Tb_Sede WHERE Estado_Sede = 'Activo'")->fetchAll(PDO::FETCH_ASSOC);
    $insumos_catalogo = $conn->query("SELECT CodInsumo, Nombre_Insumo, Costo_Unitario FROM Tb_Insumo WHERE Estado_Insumo = 'Activo'")->fetchAll(PDO::FETCH_ASSOC);

    // Generar código siguiente para Nro_OrdenCompra_Insumo (ej. OCI0000001)
    $siguiente_orden = "OCI0000001";
    $stmt_oc = $conn->query("SELECT Nro_OrdenCompra_Insumo FROM Tb_Orden_Compra_Insumo");
    $todas_ocs = $stmt_oc->fetchAll(PDO::FETCH_ASSOC);
    
    if (!empty($todas_ocs)) {
        $max_num = 0;
        foreach ($todas_ocs as $oc) {
            $num = (int)substr(trim($oc['Nro_OrdenCompra_Insumo']), 3);
            if ($num > $max_num) $max_num = $num;
        }
        $siguiente_orden = "OCI" . str_pad($max_num + 1, 7, "0", STR_PAD_LEFT);
    }
} catch (PDOException $e) {
    $error = "Error al cargar catálogos: " . $e->getMessage();
}

// PROCESAR GUARDADO DE ORDEN DE COMPRA DE INSUMOS
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['registrar_orden'])) {
    $nro_orden     = $siguiente_orden;
    $cod_empleado  = trim($_POST['cod_empleado'] ?? '');
    $cod_proveedor = trim($_POST['cod_proveedor'] ?? '');
    $cod_sede      = trim($_POST['cod_sede'] ?? '');
    $fec_entrega   = trim($_POST['fec_entrega'] ?? '');
    $observacion   = trim($_POST['observacion'] ?? 'Orden de compra de insumos');

    $detalle_items = $_POST['detalle_insumo'] ?? [];

    if (empty($cod_empleado) || empty($cod_proveedor) || empty($cod_sede) || empty($fec_entrega) || empty($detalle_items)) {
        $error = "Complete todos los campos obligatorios de cabecera y asegúrese de incluir al menos un insumo en el detalle.";
    } else {
        try {
            $conn->beginTransaction();

            $total_orden = 0;
            $items_procesados = [];

            foreach ($detalle_items as $item) {
                $cod_insumo = (int)($item['cod_insumo'] ?? 0);
                $cantidad   = (float)($item['cantidad'] ?? 0);
                $precio     = (float)($item['precio'] ?? 0);

                if ($cod_insumo > 0 && $cantidad > 0 && $precio >= 0) {
                    $importe = $cantidad * $precio;
                    $total_orden += $importe;
                    $items_procesados[] = [
                        'cod_insumo' => $cod_insumo,
                        'cantidad'   => $cantidad,
                        'precio'     => $precio,
                        'importe'    => $importe
                    ];
                }
            }

            if (empty($items_procesados)) {
                throw new Exception("El detalle de la orden contiene datos inválidos.");
            }

            // 1. Insertar Cabecera Orden de Compra Insumo
            $sql_cab = "INSERT INTO Tb_Orden_Compra_Insumo (Nro_OrdenCompra_Insumo, CodEmpleado, CodProveedor, CodSede, Fec_Orden, Fec_Entrega, Total, Estado_OrdenCompra_Insumo, Obs_OrdenCompra_Insumo) 
                        VALUES (?, ?, ?, ?, GETDATE(), ?, ?, 'Pendiente', ?)";
            $stmt_cab = $conn->prepare($sql_cab);
            $stmt_cab->execute([$nro_orden, $cod_empleado, $cod_proveedor, $cod_sede, $fec_entrega, $total_orden, $observacion]);

            // 2. Insertar Detalle Orden de Compra Insumo
            $sql_det = "INSERT INTO Tb_Detalle_Compra_Insumo (Nro_OrdenCompra_Insumo, CodSede, CodInsumo, Prec_Unitario, Cantidad, Importe) 
                        VALUES (?, ?, ?, ?, ?, ?)";
            $stmt_det = $conn->prepare($sql_det);

            foreach ($items_procesados as $det) {
                $stmt_det->execute([
                    $nro_orden,
                    $cod_sede,
                    $det['cod_insumo'],
                    $det['precio'],
                    $det['cantidad'],
                    $det['importe']
                ]);
            }

            $conn->commit();
            header("Location: generar_orden_compra_insumo.php?msg=registrado&op=" . urlencode($nro_orden));
            exit;

        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            $error = "Error al guardar la Orden de Compra: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generar Orden de Compra de Insumos - La Industria Surco</title>
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

        .container { max-width: 1250px; margin: 35px auto; padding: 0 25px; }
        .btn-volver { display: inline-block; color: #ff6699; text-decoration: none; font-weight: 600; margin-bottom: 20px; }
        .btn-volver:hover { text-decoration: underline; }
        
        .seccion-titulo { font-size: 1.4rem; color: #fff; border-left: 4px solid #ff0055; padding-left: 12px; font-weight: 700; margin-bottom: 25px; }
        
        .card { background: #161622; border: 1px solid #262638; border-radius: 12px; padding: 25px; margin-bottom: 25px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 20px; }
        .form-group { margin-bottom: 15px; }
        .form-group.full { grid-column: span 2; }
        .form-group label { display: block; margin-bottom: 6px; color: #ccc; font-size: 0.85rem; font-weight: 600; }
        .form-control { width: 100%; padding: 10px 12px; background: #0f0f17; border: 1px solid #323248; border-radius: 8px; color: #fff; font-size: 0.9rem; outline: none; }
        .form-control:focus { border-color: #ff0055; }

        table { width: 100%; border-collapse: collapse; text-align: left; font-size: 0.86rem; margin-top: 15px; }
        th { background: #1f1f2e; color: #ff80aa; padding: 12px; font-weight: 600; border-bottom: 1px solid #262638; }
        td { padding: 10px 12px; border-bottom: 1px solid #222233; color: #d0d0d8; }
        
        .btn-accion { background: linear-gradient(135deg, #ff0055 0%, #b3003c 100%); color: #fff; padding: 12px 25px; border-radius: 8px; border: none; font-weight: bold; font-size: 0.95rem; cursor: pointer; }
        .btn-agregar { background: #252535; color: #00ffcc; border: 1px solid #00ffcc; padding: 8px 15px; border-radius: 6px; font-weight: bold; cursor: pointer; font-size: 0.85rem; }
        .btn-agregar:hover { background: #00ffcc; color: #0b0b10; }
        .btn-eliminar { background: rgba(255,0,85,0.15); color: #ff4466; border: 1px solid #ff4466; padding: 6px 10px; border-radius: 6px; cursor: pointer; font-weight: bold; }
        .btn-eliminar:hover { background: #ff4466; color: #fff; }
        
        .alerta-exito { background: rgba(0,255,204,0.1); border: 1px solid #00ffcc; color: #00ffcc; padding: 12px 15px; border-radius: 8px; margin-bottom: 20px; }
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
            <li><a href="generar_orden_compra_insumo.php" class="activo" style="color: #ff0055;">📦 Orden Compra (Insumos)</a></li>
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
    <a href="verificar_stock.php?tipo=insumos" class="btn-volver">&larr; Volver a Verificación de Stock</a>

    <h2 class="seccion-titulo">Generar Orden de Compra de Insumos</h2>

    <?php if (isset($_GET['msg']) && $_GET['msg'] === 'registrado'): ?>
        <div class="alerta-exito">¡Orden de compra de insumos <strong><?php echo htmlspecialchars($_GET['op']); ?></strong> registrada exitosamente!</div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alerta-error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <form method="POST" action="generar_orden_compra_insumo.php">
        <div class="card">
            <h3 style="color: #fff; margin-top:0; margin-bottom:15px; font-size:1.1rem; border-bottom:1px solid #262638; padding-bottom:8px;">1. Datos de Cabecera</h3>
            <div class="form-grid">
                <div class="form-group">
                    <label>Nro. Orden:</label>
                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($siguiente_orden); ?>" disabled style="background: #12121c; color: #00ffcc; font-weight: bold;">
                </div>
                <div class="form-group">
                    <label>Empleado Responsable:</label>
                    <select name="cod_empleado" class="form-control" required>
                        <option value="">-- Seleccione Empleado --</option>
                        <?php foreach ($empleados as $emp): ?>
                            <option value="<?php echo htmlspecialchars(trim($emp['CodEmpleado'])); ?>"><?php echo htmlspecialchars(trim($emp['CodEmpleado'])); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Proveedor:</label>
                    <select name="cod_proveedor" class="form-control" required>
                        <option value="">-- Seleccione Proveedor --</option>
                        <?php foreach ($proveedores as $prov): ?>
                            <option value="<?php echo htmlspecialchars(trim($prov['CodProveedor'])); ?>"><?php echo htmlspecialchars($prov['Razon_Social']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Sede de Destino:</label>
                    <select name="cod_sede" id="selectSede" class="form-control" required>
                        <option value="">-- Seleccione Sede --</option>
                        <?php foreach ($sedes as $s): ?>
                            <option value="<?php echo htmlspecialchars(trim($s['CodSede'])); ?>">
                                <?php echo htmlspecialchars($s['Sede']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Fecha Estimada de Entrega:</label>
                    <input type="date" name="fec_entrega" class="form-control" required value="<?php echo date('Y-m-d', strtotime('+3 days')); ?>">
                </div>
                <div class="form-group full">
                    <label>Observación:</label>
                    <input type="text" name="observacion" class="form-control" placeholder="Detalle adicional de la orden...">
                </div>
            </div>
        </div>

        <div class="card">
            <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #262638; padding-bottom: 8px; margin-bottom: 15px;">
                <h3 style="color: #fff; margin:0; font-size:1.1rem;">2. Detalle de Insumos a Comprar</h3>
                <button type="button" class="btn-agregar" onclick="agregarFila()">+ Añadir Insumo</button>
            </div>

            <table id="tablaDetalle">
                <thead>
                    <tr>
                        <th style="width: 45%;">Insumo</th>
                        <th style="width: 15%;">Cantidad</th>
                        <th style="width: 15%;">Costo Unit. (S/.)</th>
                        <th style="width: 15%;">Importe Total</th>
                        <th style="width: 10%; text-align: center;">Acción</th>
                    </tr>
                </thead>
                <tbody id="cuerpoDetalle">
                    <!-- Filas dinámicas -->
                </tbody>
            </table>
        </div>

        <div style="display: flex; justify-content: flex-end;">
            <button type="submit" name="registrar_orden" class="btn-accion">💾 Guardar Orden de Compra</button>
        </div>
    </form>
</div>

<script>
    function toggleMenu() {
        document.getElementById('sidebar').classList.toggle('active');
        document.getElementById('overlay').classList.toggle('active');
    }

    const catalogoInsumos = <?php echo json_encode($insumos_catalogo); ?>;
    let indiceFila = 0;

    function agregarFila(codInsumoPre = '', cantidadPre = 1) {
        const tbody = document.getElementById('cuerpoDetalle');
        const tr = document.createElement('tr');
        tr.id = `fila_${indiceFila}`;

        let opcionesInsumos = '<option value="">-- Seleccione Insumo --</option>';
        catalogoInsumos.forEach(ins => {
            const selected = (ins.CodInsumo == codInsumoPre) ? 'selected' : '';
            opcionesInsumos += `<option value="${ins.CodInsumo}" data-costo="${ins.Costo_Unitario || 0}" ${selected}>${ins.Nombre_Insumo}</option>`;
        });

        tr.innerHTML = `
            <td>
                <select name="detalle_insumo[${indiceFila}][cod_insumo]" class="form-control" required onchange="actualizarCosto(this, ${indiceFila})">
                    ${opcionesInsumos}
                </select>
            </td>
            <td>
                <input type="number" step="0.01" name="detalle_insumo[${indiceFila}][cantidad]" id="cant_${indiceFila}" class="form-control" value="${cantidadPre}" required min="0.01" oninput="calcularImporte(${indiceFila})">
            </td>
            <td>
                <input type="number" step="0.01" name="detalle_insumo[${indiceFila}][precio]" id="prec_${indiceFila}" class="form-control" value="0.00" required min="0" oninput="calcularImporte(${indiceFila})">
            </td>
            <td>
                <input type="text" id="imp_${indiceFila}" class="form-control" value="0.00" readonly style="background: #12121c; color: #00ffcc; font-weight: bold;">
            </td>
            <td style="text-align: center;">
                <button type="button" class="btn-eliminar" onclick="eliminarFila(${indiceFila})">X</button>
            </td>
        `;

        tbody.appendChild(tr);

        if (codInsumoPre) {
            const select = tr.querySelector('select');
            actualizarCosto(select, indiceFila);
        }

        indiceFila++;
    }

    function actualizarCosto(select, index) {
        const option = select.options[select.selectedIndex];
        const costo = option.getAttribute('data-costo') || 0;
        document.getElementById(`prec_${index}`).value = parseFloat(costo).toFixed(2);
        calcularImporte(index);
    }

    function calcularImporte(index) {
        const cantidad = parseFloat(document.getElementById(`cant_${index}`).value) || 0;
        const precio = parseFloat(document.getElementById(`prec_${index}`).value) || 0;
        const importe = cantidad * precio;
        document.getElementById(`imp_${index}`).value = importe.toFixed(2);
    }

    function eliminarFila(index) {
        const fila = document.getElementById(`fila_${index}`);
        if (fila) fila.remove();
    }

    window.onload = function() {
        const urlParams = new URLSearchParams(window.location.search);
        const items = urlParams.getAll('items[]');

        if (items.length > 0) {
            items.forEach(param => {
                const partes = param.split('|');
                if (partes.length >= 2) {
                    const sede = partes[0];
                    const codInsumo = partes[1];
                    const cantidadSugerida = partes[2] || 1;

                    document.getElementById('selectSede').value = sede;
                    agregarFila(codInsumo, cantidadSugerida);
                }
            });
        } else {
            agregarFila();
        }
    };
</script>

</body>
</html>