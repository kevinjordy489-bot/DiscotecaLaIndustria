<?php
// ACTIVAR ERRORES PARA DIAGNÓSTICO
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
$resultado_busqueda = null;

// PROCESAR FORMULARIOS SEGÚN LA ACCIÓN ENVIADA
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = trim($_POST['accion'] ?? '');

    try {
        if ($accion === 'crear_categoria') {
            $nombre_cat = trim($_POST['Nombre_Categoria_Input'] ?? '');

            if (!empty($nombre_cat)) {
                $chk = $conn->prepare("SELECT CodCategoria FROM Tb_Categoria WHERE Categoria = ?");
                $chk->execute([$nombre_cat]);
                if (!$chk->fetch()) {
                    $max = $conn->query("SELECT MAX(CAST(CodCategoria AS INT)) FROM Tb_Categoria WHERE ISNUMERIC(CodCategoria) = 1")->fetchColumn();
                    $nuevo_cod_cat = $max ? ($max + 1) : 1;

                    $stmt = $conn->prepare("INSERT INTO Tb_Categoria (CodCategoria, Categoria) VALUES (?, ?)");
                    $stmt->execute([$nuevo_cod_cat, $nombre_cat]);
                    $mensaje = "¡Categoría '$nombre_cat' registrada con éxito!";
                } else {
                    $error = "La categoría ya se encuentra registrada en el sistema.";
                }
            }

        } elseif ($accion === 'crear_marca') {
            $nombre_marca = trim($_POST['Nombre_Marca_Input'] ?? '');

            if (!empty($nombre_marca)) {
                $chk = $conn->prepare("SELECT CodMarca FROM Tb_Marca WHERE Marca = ?");
                $chk->execute([$nombre_marca]);
                if (!$chk->fetch()) {
                    $max = $conn->query("SELECT MAX(CAST(CodMarca AS INT)) FROM Tb_Marca WHERE ISNUMERIC(CodMarca) = 1")->fetchColumn();
                    $nuevo_cod_marca = $max ? ($max + 1) : 1;

                    $stmt = $conn->prepare("INSERT INTO Tb_Marca (CodMarca, Marca) VALUES (?, ?)");
                    $stmt->execute([$nuevo_cod_marca, $nombre_marca]);
                    $mensaje = "¡Marca '$nombre_marca' registrada con éxito!";
                } else {
                    $error = "La marca ya se encuentra registrada en el sistema.";
                }
            }

        } elseif ($accion === 'crear_producto') {
            $nombre_prod = trim($_POST['Producto'] ?? '');
            $desc_prod   = trim($_POST['Descripcion'] ?? '');
            $cat_input   = trim($_POST['Categoria_Input'] ?? '');
            $marca_input = trim($_POST['Marca_Input'] ?? '');
            $precio_vta  = (float)($_POST['Prec_Venta'] ?? 0);
            $stock_ini   = (float)($_POST['Stock_Marca'] ?? 0);
            $estado_prod = 'Activo';

            if (!empty($nombre_prod) && $precio_vta > 0) {
                // 1. Verificar o registrar Categoría dinámicamente
                $cod_categoria = $cat_input;
                $val_cat = $conn->prepare("SELECT CodCategoria FROM Tb_Categoria WHERE Categoria = ? OR CodCategoria = ?");
                $val_cat->execute([$cat_input, $cat_input]);
                $res_cat = $val_cat->fetch(PDO::FETCH_ASSOC);

                if (!$res_cat) {
                    $max_c = $conn->query("SELECT MAX(CAST(CodCategoria AS INT)) FROM Tb_Categoria WHERE ISNUMERIC(CodCategoria) = 1")->fetchColumn();
                    $cod_categoria = $max_c ? ($max_c + 1) : 1;
                    $ins_c = $conn->prepare("INSERT INTO Tb_Categoria (CodCategoria, Categoria) VALUES (?, ?)");
                    $ins_c->execute([$cod_categoria, $cat_input]);
                } else {
                    $cod_categoria = $res_cat['CodCategoria'];
                }

                // 2. Verificar o registrar Marca dinámicamente
                $cod_marca = $marca_input;
                $val_marca = $conn->prepare("SELECT CodMarca FROM Tb_Marca WHERE Marca = ? OR CodMarca = ?");
                $val_marca->execute([$marca_input, $marca_input]);
                $res_marca = $val_marca->fetch(PDO::FETCH_ASSOC);

                if (!$res_marca) {
                    $max_m = $conn->query("SELECT MAX(CAST(CodMarca AS INT)) FROM Tb_Marca WHERE ISNUMERIC(CodMarca) = 1")->fetchColumn();
                    $cod_marca = $max_m ? ($max_m + 1) : 1;
                    $ins_m = $conn->prepare("INSERT INTO Tb_Marca (CodMarca, Marca) VALUES (?, ?)");
                    $ins_m->execute([$cod_marca, $marca_input]);
                } else {
                    $cod_marca = $res_marca['CodMarca'];
                }

                // 3. Generar código automático para el producto
                $max_prod = $conn->query("SELECT MAX(CAST(SUBSTRING(CodProducto, 4, LEN(CodProducto)) AS INT)) FROM Tb_Producto WHERE CodProducto LIKE 'PRD%' AND ISNUMERIC(SUBSTRING(CodProducto, 4, LEN(CodProducto))) = 1")->fetchColumn();
                $num_prod_base = $max_prod ? (int)$max_prod : 100;
                $num_prod_base++;
                $cod_producto = 'PRD' . str_pad($num_prod_base, 3, '0', STR_PAD_LEFT);

                // 4. Insertar en Tb_Producto
                $stmt = $conn->prepare("INSERT INTO Tb_Producto (CodProducto, Producto, Descripcion, CodMarca, CodCategoria, Prec_Venta, Stock_Marca, Estado_Producto) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$cod_producto, $nombre_prod, $desc_prod, $cod_marca, $cod_categoria, $precio_vta, $stock_ini, $estado_prod]);
                $mensaje = "¡Producto '$nombre_prod' registrado con éxito bajo el código $cod_producto!";
            } else {
                $error = "Verifique que el nombre del producto y el precio de venta sean válidos.";
            }
        }
    } catch (PDOException $e) {
        $error = "Error en la base de datos: " . $e->getMessage();
    }
}

// PROCESAR CENTRO DE BÚSQUEDA DE PRODUCTOS
if (isset($_GET['buscar_producto']) && !empty(trim($_GET['buscar_producto']))) {
    $criterio = trim($_GET['buscar_producto']);
    
    $sql_busqueda = "SELECT p.CodProducto, p.Producto, p.Descripcion, p.Prec_Venta, p.Stock_Marca, p.Estado_Producto, 
                            c.Categoria AS Nombre_Categoria, m.Marca AS Nombre_Marca
                     FROM Tb_Producto p
                     INNER JOIN Tb_Categoria c ON p.CodCategoria = c.CodCategoria
                     LEFT JOIN Tb_Marca m ON p.CodMarca = m.CodMarca
                     WHERE p.CodProducto LIKE ? OR p.Producto LIKE ? OR c.Categoria LIKE ? OR m.Marca LIKE ?";
    
    $stmt_b = $conn->prepare($sql_busqueda);
    $stmt_b->execute(["%$criterio%", "%$criterio%", "%$criterio%", "%$criterio%"]);
    $resultado_busqueda = $stmt_b->fetchAll(PDO::FETCH_ASSOC);
}

// OBTENER LISTADOS PARA LAS TABLAS, SELECTS Y SUGERENCIAS PREDICTIVAS
$categorias = $conn->query("SELECT * FROM Tb_Categoria")->fetchAll(PDO::FETCH_ASSOC);
$marcas = $conn->query("SELECT * FROM Tb_Marca")->fetchAll(PDO::FETCH_ASSOC);
$sugerencias_predictivas = $conn->query("SELECT CodProducto, Producto FROM Tb_Producto")->fetchAll(PDO::FETCH_ASSOC);

$productos_registrados = $conn->query("SELECT TOP 12 p.CodProducto, p.Producto, c.Categoria AS Nombre_Categoria, m.Marca AS Nombre_Marca, p.Prec_Venta, p.Stock_Marca, p.Estado_Producto 
                                       FROM Tb_Producto p 
                                       INNER JOIN Tb_Categoria c ON p.CodCategoria = c.CodCategoria 
                                       LEFT JOIN Tb_Marca m ON p.CodMarca = m.CodMarca 
                                       ORDER BY TRY_CAST(SUBSTRING(p.CodProducto, 4, LEN(p.CodProducto)) AS INT) DESC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Productos y Catálogo - La Industria Surco</title>
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

        .container { max-width: 1550px; margin: 35px auto; padding: 0 25px; }
        .btn-volver { display: inline-block; color: #ff6699; text-decoration: none; font-weight: 600; margin-bottom: 20px; }
        .btn-volver:hover { text-decoration: underline; }
        
        .header-flex { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px; }
        .seccion-titulo { font-size: 1.4rem; color: #fff; border-left: 4px solid #ff0055; padding-left: 12px; font-weight: 700; margin: 0; }

        .card-panel { background: #161622; border: 1px solid #262638; border-radius: 12px; padding: 25px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); margin-bottom: 30px; }
        .card-panel h3 { margin-top: 0; color: #ff0055; font-size: 1.1rem; border-bottom: 1px solid #262638; padding-bottom: 10px; margin-bottom: 20px; }

        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 6px; color: #ccc; font-size: 0.85rem; font-weight: 600; }
        .form-control { width: 100%; padding: 10px 12px; background: #0f0f17; border: 1px solid #323248; border-radius: 8px; color: #fff; font-size: 0.9rem; outline: none; }
        .form-control:focus { border-color: #ff0055; }

        .btn-accion { background: #ff0055; color: #fff; border: none; padding: 12px; border-radius: 8px; font-weight: bold; cursor: pointer; width: 100%; font-size: 0.95rem; transition: background 0.2s; }
        .btn-accion:hover { background: #d60047; }

        .btn-secundario { background: rgba(255, 0, 85, 0.15); color: #ff80aa; border: 1px solid #ff0055; padding: 8px 14px; border-radius: 6px; font-weight: 600; cursor: pointer; font-size: 0.82rem; transition: background 0.2s; }
        .btn-secundario:hover { background: rgba(255, 0, 85, 0.25); }

        .flex-row-input { display: flex; gap: 10px; align-items: center; }

        .search-center-card { background: #161622; border: 1px solid rgba(255,0,85,0.4); border-radius: 12px; padding: 25px; margin-bottom: 30px; box-shadow: 0 10px 30px rgba(255,0,85,0.1); }
        .search-flex { display: flex; gap: 15px; }

        .table-container { background: #161622; border: 1px solid #262638; border-radius: 12px; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.5); margin-bottom: 30px; }
        table { width: 100%; border-collapse: collapse; text-align: left; font-size: 0.86rem; }
        th { background: #1f1f2e; color: #ff80aa; padding: 14px; font-weight: 600; border-bottom: 1px solid #262638; }
        td { padding: 14px; border-bottom: 1px solid #222233; color: #d0d0d8; }
        tr:hover { background: #1a1a29; }

        .alerta-exito { background: rgba(0,255,204,0.12); border: 1px solid #00ffcc; color: #80ffdf; padding: 12px 15px; border-radius: 8px; margin-bottom: 20px; }
        .alerta-error { background: rgba(255,0,85,0.12); border: 1px solid #ff0055; color: #ff80aa; padding: 12px 15px; border-radius: 8px; margin-bottom: 20px; }
        .badge { background: rgba(255,0,85,0.15); color: #ff80aa; border: 1px solid #ff0055; padding: 4px 10px; border-radius: 20px; font-size: 0.78rem; font-weight: bold; display: inline-block; }

        /* ESTILOS PARA LOS MODALES */
        .modal-overlay-custom { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.75); justify-content: center; align-items: center; z-index: 3000; backdrop-filter: blur(4px); }
        .modal-box { background: #161622; border: 1px solid #ff0055; border-radius: 12px; width: 450px; max-width: 90%; padding: 25px; box-shadow: 0 15px 40px rgba(255,0,85,0.2); position: relative; }
        .modal-box h3 { margin-top: 0; color: #ff0055; font-size: 1.1rem; border-bottom: 1px solid #262638; padding-bottom: 10px; margin-bottom: 20px; }
        .close-modal { position: absolute; top: 20px; right: 20px; color: #888; font-size: 1.2rem; cursor: pointer; font-weight: bold; background: none; border: none; }
        .close-modal:hover { color: #fff; }
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
            <li><a href="gestion_productos.php" class="activo" style="color: #ff0055;">🍸 Gestión de Productos</a></li>
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
        <h2 class="seccion-titulo">Módulo de Gestión de Productos y Catálogo</h2>
    </div>

    <?php if ($mensaje): ?>
        <div class="alerta-exito"><?php echo htmlspecialchars($mensaje); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alerta-error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <!-- 1. FORMULARIO DE REGISTRO PRIMERO -->
    <div class="card-panel">
        <h3>📦 Registrar Nuevo Producto (Código Automático)</h3>
        <form method="POST" action="gestion_productos.php">
            <input type="hidden" name="accion" value="crear_producto">
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <div class="form-group">
                    <label>Nombre del Producto:</label>
                    <input type="text" name="Producto" class="form-control" required placeholder="Ej. Whisky Black Label 750ml">
                </div>
                <div class="form-group">
                    <label>Descripción:</label>
                    <input type="text" name="Descripcion" class="form-control" placeholder="Botella de 750ml importada">
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <div class="form-group">
                    <label>Categoría:</label>
                    <div class="flex-row-input">
                        <input type="text" name="Categoria_Input" list="lista_categorias" class="form-control" required placeholder="Seleccione o escriba..." autocomplete="off">
                        <button type="button" class="btn-secundario" onclick="openModal('modalCat')">+ Categoría</button>
                    </div>
                    <datalist id="lista_categorias">
                        <?php foreach($categorias as $cat): ?>
                            <option value="<?php echo htmlspecialchars(trim($cat['Categoria'])); ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>

                <div class="form-group">
                    <label>Marca:</label>
                    <div class="flex-row-input">
                        <input type="text" name="Marca_Input" list="lista_marcas" class="form-control" required placeholder="Seleccione o escriba..." autocomplete="off">
                        <button type="button" class="btn-secundario" onclick="openModal('modalMar')">+ Marca</button>
                    </div>
                    <datalist id="lista_marcas">
                        <?php foreach($marcas as $mar): ?>
                            <option value="<?php echo htmlspecialchars(trim($mar['Marca'])); ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                <div class="form-group">
                    <label>Precio de Venta (S/.):</label>
                    <input type="number" step="0.01" name="Prec_Venta" class="form-control" required placeholder="150.00">
                </div>
                <div class="form-group">
                    <label>Stock Inicial:</label>
                    <input type="number" step="0.01" name="Stock_Marca" class="form-control" value="20" required placeholder="20">
                </div>
            </div>

            <button type="submit" class="btn-accion" style="margin-top: 10px;">Registrar Producto en el Sistema</button>
        </form>
    </div>

    <!-- 2. CENTRO DE BÚSQUEDA PREDICTIVA MÁS ABAJO -->
    <div class="search-center-card">
        <h3 style="margin-top:0; color: #ff0055; font-size: 1.1rem; margin-bottom: 15px;">🔍 Centro de Búsqueda Predictiva y Auditoría</h3>
        <form method="GET" action="gestion_productos.php">
            <div class="search-flex">
                <input type="text" name="buscar_producto" list="listaSugerenciasProductos" class="form-control" placeholder="Escribe o selecciona código, nombre, categoría o marca..." value="<?php echo htmlspecialchars($_GET['buscar_producto'] ?? ''); ?>" autocomplete="off" required>
                <datalist id="listaSugerenciasProductos">
                    <?php foreach ($sugerencias_predictivas as $sug): ?>
                        <option value="<?php echo htmlspecialchars(trim($sug['CodProducto'])); ?>">Código: <?php echo htmlspecialchars(trim($sug['CodProducto'])); ?></option>
                        <option value="<?php echo htmlspecialchars(trim($sug['Producto'])); ?>">Producto: <?php echo htmlspecialchars(trim($sug['Producto'])); ?></option>
                    <?php endforeach; ?>
                    <?php foreach ($categorias as $cat): ?>
                        <option value="<?php echo htmlspecialchars(trim($cat['Categoria'])); ?>">Categoría: <?php echo htmlspecialchars(trim($cat['Categoria'])); ?></option>
                    <?php endforeach; ?>
                    <?php foreach ($marcas as $mar): ?>
                        <option value="<?php echo htmlspecialchars(trim($mar['Marca'])); ?>">Marca: <?php echo htmlspecialchars(trim($mar['Marca'])); ?></option>
                    <?php endforeach; ?>
                </datalist>
                <button type="submit" class="btn-accion" style="width: 220px;">Buscar Producto</button>
            </div>
        </form>

        <?php if (isset($_GET['buscar_producto'])): ?>
            <div style="margin-top: 20px;">
                <h4 style="color: #fff; font-size: 0.95rem; margin-bottom: 10px;">Resultados de la Búsqueda:</h4>
                <div class="table-container" style="margin-bottom:0;">
                    <table>
                        <thead>
                            <tr><th>Código</th><th>Producto</th><th>Descripción</th><th>Categoría</th><th>Marca</th><th>Precio Venta</th><th>Stock Marca</th><th>Estado</th></tr>
                        </thead>
                        <tbody>
                            <?php if (empty($resultado_busqueda)): ?>
                                <tr><td colspan="8" style="text-align: center; color: #888;">No se encontró ningún producto con ese criterio de búsqueda.</td></tr>
                            <?php else: foreach($resultado_busqueda as $rb): ?>
                                <tr>
                                    <td><span class="badge"><?php echo htmlspecialchars(trim($rb['CodProducto'])); ?></span></td>
                                    <td><strong><?php echo htmlspecialchars(trim($rb['Producto'])); ?></strong></td>
                                    <td><?php echo htmlspecialchars(trim($rb['Descripcion'] ?? '')); ?></td>
                                    <td><?php echo htmlspecialchars(trim($rb['Nombre_Categoria'])); ?></td>
                                    <td><?php echo htmlspecialchars(trim($rb['Nombre_Marca'] ?? 'Sin marca')); ?></td>
                                    <td>S/. <?php echo number_format((float)$rb['Prec_Venta'], 2); ?></td>
                                    <td><strong style="color: #00ffcc;"><?php echo number_format((float)$rb['Stock_Marca'], 2); ?> unids.</strong></td>
                                    <td><?php echo htmlspecialchars(trim($rb['Estado_Producto'])); ?></td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- 3. TABLA DE ÚLTIMOS PRODUCTOS REGISTRADOS -->
    <div class="table-container">
        <h3 style="padding: 15px; margin:0; background: #1f1f2e; color: #ff80aa; font-size: 0.95rem;">Últimos Productos Registrados</h3>
        <table>
            <thead><tr><th>Código</th><th>Producto</th><th>Cat.</th><th>Marca</th><th>Precio Venta</th><th>Stock</th></tr></thead>
            <tbody>
                <?php if(empty($productos_registrados)): ?>
                <tr><td colspan="6" style="text-align: center; color: #888;">No hay productos registrados todavía.</td></tr>
                <?php else: foreach($productos_registrados as $pr): ?>
                <tr>
                    <td><span class="badge"><?php echo htmlspecialchars(trim($pr['CodProducto'])); ?></span></td>
                    <td><strong><?php echo htmlspecialchars(trim($pr['Producto'])); ?></strong></td>
                    <td><?php echo htmlspecialchars(trim($pr['Nombre_Categoria'])); ?></td>
                    <td><?php echo htmlspecialchars(trim($pr['Nombre_Marca'] ?? 'N/D')); ?></td>
                    <td>S/. <?php echo number_format((float)$pr['Prec_Venta'], 2); ?></td>
                    <td><strong style="color: #00ffcc;"><?php echo number_format((float)$pr['Stock_Marca'], 2); ?></strong></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- MODAL PARA NUEVA CATEGORÍA -->
<div id="modalCat" class="modal-overlay-custom">
    <div class="modal-box">
        <button class="close-modal" onclick="closeModal('modalCat')">&times;</button>
        <h3>🏷️ Registrar Nueva Categoría</h3>
        <form method="POST" action="gestion_productos.php">
            <input type="hidden" name="accion" value="crear_categoria">
            <div class="form-group">
                <label>Nombre de la Categoría:</label>
                <input type="text" name="Nombre_Categoria_Input" class="form-control" required placeholder="Ej. Cocteles, Vinos..." autocomplete="off">
            </div>
            <button type="submit" class="btn-accion">Guardar Categoría</button>
        </form>
    </div>
</div>

<!-- MODAL PARA NUEVA MARCA -->
<div id="modalMar" class="modal-overlay-custom">
    <div class="modal-box">
        <button class="close-modal" onclick="closeModal('modalMar')">&times;</button>
        <h3>⭐ Registrar Nueva Marca</h3>
        <form method="POST" action="gestion_productos.php">
            <input type="hidden" name="accion" value="crear_marca">
            <div class="form-group">
                <label>Nombre de la Marca:</label>
                <input type="text" name="Nombre_Marca_Input" class="form-control" required placeholder="Ej. Absolut, Smirnoff..." autocomplete="off">
            </div>
            <button type="submit" class="btn-accion">Guardar Marca</button>
        </form>
    </div>
</div>

<script>
    function toggleMenu() {
        document.getElementById('sidebar').classList.toggle('active');
        document.getElementById('overlay').classList.toggle('active');
    }

    function openModal(id) {
        document.getElementById(id).style.display = 'flex';
    }
    function closeModal(id) {
        document.getElementById(id).style.display = 'none';
    }
    window.onclick = function(event) {
        if (event.target.classList.contains('modal-overlay-custom')) {
            event.target.style.display = 'none';
        }
    }
</script>

</body>
</html>