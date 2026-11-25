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

// Recoger parámetro de búsqueda (GET)
$filtro_nombre = trim($_GET['nombre'] ?? '');

// 1. GENERAR AUTOMÁTICAMENTE EL SIGUIENTE CÓDIGO DE SEDE EN CADENA (Ej: SED02)
$siguiente_codigo_sede = "SED01";
try {
    $stmt_cod = $conn->query("SELECT TOP 1 CodSede FROM Tb_Sede ORDER BY CodSede DESC");
    $ultima_sede = $stmt_cod->fetch(PDO::FETCH_ASSOC);

    if ($ultima_sede) {
        $ultimo_codigo = trim($ultima_sede['CodSede']); // Ej: 'SED01'
        $numero = (int)substr($ultimo_codigo, 3); // Extraer los números (1)
        $siguiente_numero = $numero + 1;
        $siguiente_codigo_sede = "SED0" . $siguiente_numero; // Formato SED02 (o más si pasa de 9)
    }
} catch (PDOException $e) {
    // Si hay error en la consulta del código, se mantiene el valor por defecto
}

// 2. PROCESAR REGISTRO DE NUEVA SEDE
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['registrar_sede'])) {
    $cod_sede  = $siguiente_codigo_sede; // Usamos el código autogenerado de forma segura
    $sede      = trim($_POST['sede'] ?? '');
    $cpostal   = trim($_POST['cpostal'] ?? '150101'); 
    $direccion = trim($_POST['direccion'] ?? '');
    $telefono  = trim($_POST['telefono'] ?? '');
    $anexo     = trim($_POST['anexo'] ?? '0000');
    $email     = trim($_POST['email'] ?? 'contacto@laindustria.com');

    if (empty($sede) || empty($direccion)) {
        $error = "Por favor, complete los campos obligatorios de la sede.";
    } else {
        try {
            $sql_ins = "INSERT INTO Tb_Sede (CodSede, Sede, CPostal, Direccion, Telefono, Anexo, Email, Estado_Sede) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, 'Activo')";
            $stmt_ins = $conn->prepare($sql_ins);
            $stmt_ins->execute([$cod_sede, $sede, $cpostal, $direccion, $telefono, $anexo, $email]);
            $mensaje = "¡Sede <strong>$sede</strong> registrada con éxito con código <strong>$cod_sede</strong>!";

            // Actualizar el código para el siguiente registro en la misma vista si lo desea
            $siguiente_numero_nuevo = $siguiente_numero + 1;
            $siguiente_codigo_sede = "SED0" . $siguiente_numero_nuevo;
        } catch (PDOException $e) {
            $error = "Error al registrar la sede: " . $e->getMessage();
        }
    }
}

// 3. CONSULTAR TODAS LAS SEDES REGISTRADAS (CON SOPORTE DE FILTRO)
try {
    $sql_list = "SELECT CodSede, Sede, Direccion, Telefono, Email, Estado_Sede FROM Tb_Sede WHERE 1=1";
    $params = [];
    
    if (!empty($filtro_nombre)) {
        $sql_list .= " AND Sede LIKE ?";
        $params[] = "%" . $filtro_nombre . "%";
    }
    
    $sql_list .= " ORDER BY CodSede ASC";
    $stmt_sedes = $conn->prepare($sql_list);
    $stmt_sedes->execute($params);
    $sedes = $stmt_sedes->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Error al consultar las sedes: " . $e->getMessage();
    $sedes = [];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Sedes - La Industria Surco</title>
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

        .container { max-width: 1200px; margin: 35px auto; padding: 0 25px; }
        .btn-volver { display: inline-block; color: #ff6699; text-decoration: none; font-weight: 600; margin-bottom: 20px; }
        .btn-volver:hover { text-decoration: underline; }
        
        .header-flex { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; flex-wrap: wrap; gap: 15px; }
        .seccion-titulo { font-size: 1.4rem; color: #fff; border-left: 4px solid #ff0055; padding-left: 12px; font-weight: 700; margin: 0; }

        /* Estilo de Tarjeta de Búsqueda Predictiva */
        .search-card { background: #161622; border: 1px solid #262638; border-radius: 12px; padding: 20px; margin-bottom: 25px; box-shadow: 0 8px 25px rgba(0,0,0,0.4); }
        .search-grid { display: grid; grid-template-columns: 1fr 140px; gap: 15px; align-items: flex-end; }
        @media(max-width: 600px) { .search-grid { grid-template-columns: 1fr; } }

        /* Estilos de la Ventana Modal Flotante */
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.75); backdrop-filter: blur(4px); z-index: 2500; justify-content: center; align-items: center; padding: 20px; }
        .modal-overlay.active { display: flex; }
        
        .modal-content { background: #161622; border: 1px solid #323248; border-radius: 14px; padding: 30px; width: 100%; max-width: 700px; max-height: 90vh; overflow-y: auto; box-shadow: 0 15px 40px rgba(0,0,0,0.7); position: relative; animation: modalFade 0.3s ease; }
        @keyframes modalFade { from { opacity: 0; transform: translateY(-20px); } to { opacity: 1; transform: translateY(0); } }

        .modal-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #262638; padding-bottom: 12px; margin-bottom: 20px; }
        .modal-header h3 { margin: 0; color: #fff; font-size: 1.25rem; }
        .btn-cerrar-modal { background: none; border: none; color: #ff4466; font-size: 1.4rem; font-weight: bold; cursor: pointer; }

        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 15px; }
        .form-group { margin-bottom: 15px; }
        .form-group.full { grid-column: span 2; }
        .form-group label { display: block; margin-bottom: 6px; color: #ccc; font-size: 0.85rem; font-weight: 600; }
        .form-control { width: 100%; padding: 10px 12px; background: #0f0f17; border: 1px solid #323248; border-radius: 8px; color: #fff; font-size: 0.9rem; outline: none; }
        .form-control:focus { border-color: #ff0055; }
        
        .btn-accion { background: linear-gradient(135deg, #ff0055 0%, #b3003c 100%); color: #fff; padding: 11px 22px; border-radius: 6px; border: none; font-weight: bold; font-size: 0.9rem; cursor: pointer; }
        .btn-toggle { background: linear-gradient(135deg, #00ffcc 0%, #00b388 100%); color: #0b0b10; border: none; padding: 10px 18px; border-radius: 6px; font-weight: bold; cursor: pointer; }
        
        .btn-buscar { background: #252535; color: #00ffcc; border: 1px solid #00ffcc; padding: 10px; border-radius: 8px; font-weight: bold; cursor: pointer; text-align: center; text-decoration: none; display: block; width: 100%; }
        .btn-buscar:hover { background: #00ffcc; color: #0b0b10; }
        .btn-limpiar { color: #aaa; text-decoration: none; font-size: 0.82rem; margin-top: 6px; display: inline-block; text-align: center; width: 100%; }
        .btn-limpiar:hover { color: #fff; }

        .grid-sedes { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 20px; }
        .sede-card { background: #161622; border: 1px solid #262638; border-radius: 12px; padding: 25px; box-shadow: 0 8px 25px rgba(0,0,0,0.4); display: flex; flex-direction: column; justify-content: space-between; }
        .sede-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 15px; }
        .sede-codigo { background: rgba(255,0,85,0.15); color: #ff80aa; padding: 4px 10px; border-radius: 20px; font-size: 0.78rem; font-weight: bold; }
        .sede-nombre { font-size: 1.25rem; font-weight: bold; color: #fff; margin: 0; }
        
        .sede-info { font-size: 0.9rem; color: #9e9eb3; margin-bottom: 20px; line-height: 1.5; }
        .sede-info strong { color: #ccc; }

        .btn-gestionar-personal { display: block; text-align: center; background: #252535; color: #00ffcc; border: 1px solid #00ffcc; padding: 10px; border-radius: 8px; text-decoration: none; font-weight: bold; font-size: 0.9rem; transition: all 0.2s; }
        .btn-gestionar-personal:hover { background: #00ffcc; color: #0b0b10; }

        .alerta-exito { background: rgba(0,255,204,0.1); border: 1px solid #00ffcc; color: #00ffcc; padding: 12px 15px; border-radius: 8px; margin-bottom: 20px; }
        .alerta-error { background: rgba(255,0,85,0.12); border: 1px solid #ff0055; color: #ff80aa; padding: 12px 15px; border-radius: 8px; margin-bottom: 20px; }
    </style>
</head>
<body>

<!-- Menú Lateral Desplegable (Sidebar) Unificado -->
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
            <li><a href="consultar_sedes.php" class="activo" style="color: #ff0055;">🏢 Sedes</a></li>
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
        <h2 class="seccion-titulo">Consulta y Gestión de Sedes</h2>
        <button class="btn-toggle" onclick="abrirModal()">+ Añadir Nueva Sede</button>
    </div>

    <?php if ($mensaje): ?>
        <div class="alerta-exito"><?php echo $mensaje; ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alerta-error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <!-- TARJETA DE BÚSQUEDA PREDICTIVA -->
    <div class="search-card">
        <form method="GET" action="consultar_sedes.php">
            <div class="search-grid">
                <div class="form-group" style="margin-bottom:0;">
                    <label>Buscar por Nombre de Sede:</label>
                    <input type="text" name="nombre" list="listaSedes" class="form-control" placeholder="Escribe o selecciona una sede..." value="<?php echo htmlspecialchars($filtro_nombre); ?>" autocomplete="off">
                    <datalist id="listaSedes">
                        <?php 
                        // Cargar todas las sedes para el datalist predictivo
                        $stmt_all = $conn->query("SELECT Sede FROM Tb_Sede ORDER BY Sede ASC");
                        while ($row_s = $stmt_all->fetch(PDO::FETCH_ASSOC)) {
                            echo '<option value="' . htmlspecialchars($row_s['Sede']) . '">';
                        }
                        ?>
                    </datalist>
                </div>
                <div>
                    <button type="submit" class="btn-buscar">🔍 Buscar</button>
                    <a href="consultar_sedes.php" class="btn-limpiar">Limpiar filtros</a>
                </div>
            </div>
        </form>
    </div>

    <!-- VENTANA MODAL FLOTANTE PARA NUEVA SEDE -->
    <div class="modal-overlay" id="modalSede">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Registrar Nueva Sede</h3>
                <button class="btn-cerrar-modal" onclick="cerrarModal()">&times;</button>
            </div>
            <form method="POST" action="consultar_sedes.php">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Código de Sede (Autogenerado):</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($siguiente_codigo_sede); ?>" disabled style="background: #12121c; color: #00ffcc; font-weight: bold;">
                    </div>
                    <div class="form-group">
                        <label>Nombre de la Sede:</label>
                        <input type="text" name="sede" class="form-control" required placeholder="Ej. La Industria Miraflores">
                    </div>
                    <div class="form-group">
                        <label>Código Postal (CPostal):</label>
                        <input type="text" name="cpostal" class="form-control" value="150101" required placeholder="150101">
                    </div>
                    <div class="form-group">
                        <label>Dirección:</label>
                        <input type="text" name="direccion" class="form-control" required placeholder="Av. Larco 123">
                    </div>
                    <div class="form-group">
                        <label>Teléfono:</label>
                        <input type="text" name="telefono" class="form-control" placeholder="987654321">
                    </div>
                    <div class="form-group">
                        <label>Email de la Sede:</label>
                        <input type="email" name="email" class="form-control" value="miraflores@laindustria.com" placeholder="sede@laindustria.com">
                    </div>
                </div>
                <div style="margin-top: 20px; display: flex; justify-content: flex-end; gap: 10px;">
                    <button type="button" onclick="cerrarModal()" style="background: #252535; color: #aaa; border: none; padding: 11px 18px; border-radius: 6px; cursor: pointer; font-weight: bold;">Cancelar</button>
                    <button type="submit" name="registrar_sede" class="btn-accion">Guardar Nueva Sede</button>
                </div>
            </form>
        </div>
    </div>

    <!-- LISTADO DE SEDES EN TARJETAS -->
    <div class="grid-sedes">
        <?php if (empty($sedes)): ?>
            <p style="color: #9e9eb3; grid-column: span 2; text-align: center; padding: 40px;">No hay sedes registradas en el sistema que coincidan con la búsqueda.</p>
        <?php else: ?>
            <?php foreach ($sedes as $sede_item): ?>
                <div class="sede-card">
                    <div>
                        <div class="sede-header">
                            <h3 class="sede-nombre"><?php echo htmlspecialchars($sede_item['Sede']); ?></h3>
                            <span class="sede-codigo"><?php echo htmlspecialchars(trim($sede_item['CodSede'])); ?></span>
                        </div>
                        <div class="sede-info">
                            <p style="margin: 5px 0;"><strong>Dirección:</strong> <?php echo htmlspecialchars($sede_item['Direccion']); ?></p>
                            <p style="margin: 5px 0;"><strong>Teléfono:</strong> <?php echo htmlspecialchars($sede_item['Telefono'] ?? 'No registrado'); ?></p>
                            <p style="margin: 5px 0;"><strong>Email:</strong> <?php echo htmlspecialchars($sede_item['Email'] ?? 'No registrado'); ?></p>
                            <p style="margin: 5px 0;"><strong>Estado:</strong> <span style="color: #00ffcc;"><?php echo htmlspecialchars($sede_item['Estado_Sede']); ?></span></p>
                        </div>
                    </div>
                    <a href="gestion_trabajadores.php?sede=<?php echo urlencode(trim($sede_item['CodSede'])); ?>" class="btn-gestionar-personal">
                        👥 Gestionar Trabajadores de esta Sede
                    </a>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<script>
    function toggleMenu() {
        document.getElementById('sidebar').classList.toggle('active');
        document.getElementById('overlay').classList.toggle('active');
    }

    function abrirModal() {
        document.getElementById('modalSede').classList.add('active');
    }

    function cerrarModal() {
        document.getElementById('modalSede').classList.remove('active');
    }
</script>

</body>
</html>