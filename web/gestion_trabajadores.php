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

// 1. PROCESAR ACTUALIZACIÓN RÁPIDA DESDE EL MODAL
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'actualizar_empleado') {
    $cod_empleado    = trim($_POST['cod_empleado'] ?? '');
    $estado_empleado = trim($_POST['estado_empleado'] ?? 'Activo');
    $obs             = trim($_POST['obs'] ?? '');
    $perfil_nuevo    = trim($_POST['perfil'] ?? '');

    try {
        $conn->beginTransaction();

        // Actualizar estado y observaciones en Tb_Empleado
        $sql_up_emp = "UPDATE Tb_Empleado SET Estado_Empleado = ?, Obs_Estado_Empleado = ? WHERE CodEmpleado = ?";
        $conn->prepare($sql_up_emp)->execute([$estado_empleado, $obs, $cod_empleado]);

        // Actualizar perfil de usuario si existe registro en Tb_Usuario_Empleado
        if (!empty($perfil_nuevo)) {
            $sql_up_usu = "UPDATE Tb_Usuario_Empleado SET Perfil = ? WHERE CodEmpleado = ?";
            $conn->prepare($sql_up_usu)->execute([$perfil_nuevo, $cod_empleado]);
        }

        $conn->commit();
        $mensaje = "¡Datos del trabajador $cod_empleado actualizados correctamente!";
    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        $error = "Error al actualizar los datos: " . $e->getMessage();
    }
}

// 2. CONSULTAR LISTA DE TRABAJADORES CON LAS COLUMNAS Y RELACIONES CORRECTAS
$trabajadores = [];
$lista_perfiles = ['Administrador', 'Almacen', 'Bartender', 'Vendedor entr', 'Vendedor pro', 'Seguridad'];

try {
    $sql_list = "SELECT e.CodEmpleado, e.CodPersona, e.Estado_Civil, e.Nro_Hijos, e.CodSede, e.Estado_Empleado, e.Obs_Estado_Empleado,
                        p.Nombres, p.Apellidos, p.NroDoc_Identidad, p.Direccion, p.Email, p.Fec_Nac, p.Sexo,
                        td.Documento_Identidad, n.Nacionalidad, s.Sede,
                        u.Usuario, u.Perfil, u.Estado_Usuario
                 FROM Tb_Empleado e
                 INNER JOIN Tb_Persona p ON e.CodPersona = p.CodPersona
                 INNER JOIN Tb_Documento_Identidad td ON p.CodDoc_Identidad = td.CodDoc_Identidad
                 INNER JOIN Tb_Nacionalidad n ON p.CodNacionalidad = n.CodNacionalidad
                 INNER JOIN Tb_Sede s ON e.CodSede = s.CodSede
                 LEFT JOIN Tb_Usuario_Empleado u ON e.CodEmpleado = u.CodEmpleado
                 ORDER BY e.CodEmpleado DESC";
    $trabajadores = $conn->query($sql_list)->fetchAll(PDO::FETCH_ASSOC);

    // Cargar perfiles únicos del sistema
    $stmt_p = $conn->query("SELECT DISTINCT Perfil FROM Tb_Usuario_Empleado WHERE Perfil IS NOT NULL AND Perfil <> ''");
    $db_perfiles = $stmt_p->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($db_perfiles)) {
        $lista_perfiles = $db_perfiles;
    }
} catch (PDOException $e) {
    $error = "Error al consultar la lista de trabajadores: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Trabajadores - La Industria Surco</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Segoe UI', system-ui, -apple-system, sans-serif; background-color: #0b0b10; color: #f1f1f5; margin: 0; padding: 0; }
        
        /* Barra de navegación superior idéntica al panel de control */
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

        /* Contenedor Principal */
        .container { max-width: 1250px; margin: 35px auto; padding: 0 25px; }
        .page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; flex-wrap: wrap; gap: 15px; }
        .page-header h2 { margin: 0; font-size: 1.8rem; color: #fff; }
        .page-header h2 span { color: #ff0055; }
        
        /* Barra de Búsqueda con Sugerencias Desplegables */
        .search-wrapper {
            position: relative;
            width: 340px;
        }
        .search-box {
            display: flex; align-items: center; background: #161622; border: 1px solid #323248;
            border-radius: 10px; padding: 6px 15px; width: 100%; box-shadow: 0 4px 15px rgba(0,0,0,0.4);
            transition: border-color 0.2s;
        }
        .search-box:focus-within { border-color: #00ffcc; }
        .search-box input {
            background: transparent; border: none; color: #fff; font-size: 0.92rem; outline: none; width: 100%; padding: 6px;
        }
        .search-box input::placeholder { color: #777788; }
        .search-box span { color: #00ffcc; font-size: 1.1rem; }
        .btn-clear-search {
            background: none; border: none; color: #8888a0; cursor: pointer; font-size: 1rem; padding: 0 4px; display: none;
        }
        .btn-clear-search:hover { color: #ff0055; }

        /* Lista de Sugerencias */
        .suggestions-dropdown {
            position: absolute; top: calc(100% + 6px); left: 0; width: 100%;
            background: #1a1a28; border: 1px solid #323248; border-radius: 10px;
            box-shadow: 0 12px 30px rgba(0,0,0,0.85); z-index: 1200;
            max-height: 260px; overflow-y: auto; display: none;
        }
        .suggestions-dropdown.active { display: block; }
        .suggestion-item {
            padding: 10px 15px; border-bottom: 1px solid #262638; cursor: pointer;
            display: flex; justify-content: space-between; align-items: center; transition: background 0.15s;
        }
        .suggestion-item:last-child { border-bottom: none; }
        .suggestion-item:hover { background: rgba(0, 255, 204, 0.1); }
        .suggestion-name { font-weight: 600; font-size: 0.88rem; color: #fff; }
        .suggestion-meta { font-size: 0.78rem; color: #8888a0; }
        .suggestion-code { font-size: 0.78rem; font-weight: bold; color: #00ffcc; background: rgba(0,255,204,0.1); padding: 2px 7px; border-radius: 4px; }

        .btn-nuevo {
            background: linear-gradient(135deg, #ff0055 0%, #b3003c 100%);
            color: #fff; padding: 11px 20px; border-radius: 8px; text-decoration: none;
            font-weight: bold; font-size: 0.9rem; transition: opacity 0.2s; display: inline-block;
        }
        .btn-nuevo:hover { opacity: 0.9; }

        /* Tabla de Trabajadores */
        .table-card { background: #161622; border: 1px solid #262638; border-radius: 14px; padding: 25px; box-shadow: 0 10px 30px rgba(0,0,0,0.6); overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; text-align: left; }
        th { background: #1f1f2e; color: #00ffcc; padding: 12px 15px; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 2px solid #323248; }
        td { padding: 12px 15px; border-bottom: 1px solid #262638; font-size: 0.9rem; color: #d0d0d8; }
        tr:hover td { background: rgba(255, 255, 255, 0.02); }

        .badge { padding: 5px 10px; border-radius: 6px; font-size: 0.78rem; font-weight: bold; display: inline-block; }
        .badge-activo { background: rgba(0,255,204,0.15); color: #00ffcc; border: 1px solid rgba(0,255,204,0.3); }
        .badge-suspendido { background: rgba(255,0,85,0.15); color: #ff80aa; border: 1px solid rgba(255,0,85,0.3); }
        .badge-vacaciones { background: rgba(255,204,0,0.15); color: #ffcc00; border: 1px solid rgba(255,204,0,0.3); }

        .btn-accion {
            background: #252535; color: #00ffcc; border: 1px solid rgba(0,255,204,0.3);
            padding: 6px 12px; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 0.82rem;
            transition: all 0.2s; text-decoration: none; display: inline-block;
        }
        .btn-accion:hover { background: #00ffcc; color: #0b0b10; }

        /* MODAL DE VISUALIZACIÓN Y EDICIÓN */
        .modal-overlay {
            position: fixed; top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0,0,0,0.75); backdrop-filter: blur(4px); z-index: 3000;
            display: none; justify-content: center; align-items: center; padding: 20px;
        }
        .modal-overlay.active { display: flex; }
        .modal-box {
            background: #161622; border: 1px solid #323248; border-radius: 14px;
            width: 100%; max-width: 850px; max-height: 90vh; overflow-y: auto;
            box-shadow: 0 15px 40px rgba(0,0,0,0.9); padding: 30px; position: relative;
        }
        .modal-close {
            position: absolute; top: 20px; right: 20px; background: none; border: none;
            color: #aaa; font-size: 1.5rem; cursor: pointer; font-weight: bold;
        }
        .modal-close:hover { color: #ff0055; }
        .modal-title { font-size: 1.3rem; font-weight: 800; color: #fff; margin-bottom: 5px; border-bottom: 1px solid #262638; padding-bottom: 12px; }
        .modal-subtitle { font-size: 0.85rem; color: #ff0055; font-weight: bold; margin-bottom: 20px; text-transform: uppercase; letter-spacing: 0.5px; }

        .seccion-titulo { color: #00ffcc; font-size: 0.9rem; font-weight: bold; text-transform: uppercase; margin: 20px 0 10px 0; border-bottom: 1px solid #262638; padding-bottom: 5px; grid-column: span 2; }
        .info-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 20px; }
        .info-item { background: #0f0f17; padding: 10px 14px; border-radius: 8px; border: 1px solid #262638; }
        .info-item label { display: block; font-size: 0.75rem; color: #8888a0; text-transform: uppercase; margin-bottom: 3px; font-weight: bold; }
        .info-item span { font-size: 0.95rem; color: #fff; font-weight: 600; }
        .info-item.full { grid-column: span 2; }

        .modal-form-section { border-top: 1px solid #262638; padding-top: 20px; margin-top: 20px; }
        .modal-form-section h3 { font-size: 1rem; color: #00ffcc; margin-bottom: 15px; }
        
        .alerta-exito { background: rgba(0,255,204,0.1); border: 1px solid #00ffcc; color: #00ffcc; padding: 12px; border-radius: 8px; margin-bottom: 20px; }
        .alerta-error { background: rgba(255,0,85,0.12); border: 1px solid #ff0055; color: #ff80aa; padding: 12px; border-radius: 8px; margin-bottom: 20px; }

        /* Estilo oculto para impresión del contrato desde el modal */
        #print-document { display: none; }
        @media print {
            body * { visibility: hidden; }
            #print-document, #print-document * { visibility: visible; }
            #print-document { display: block; position: absolute; left: 0; top: 0; width: 100%; background: #fff; color: #000; padding: 30px; font-family: 'Times New Roman', serif; font-size: 12pt; }
        }
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
            <li><a href="gestion_trabajadores.php" class="activo" style="color: #ff0055;">👥 Gestión de Trabajadores</a></li>
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
            <li><a href="reporte_ventas_tickets.php">📑 Ventas de Tickets</a></li>
        <?php endif; ?>
    </ul>

    <div class="sidebar-section-title">Sesión</div>
    <ul class="sidebar-menu">
        <li><a href="cerrar_sesion.php" style="color: #ff6699;">🚪 Cerrar Sesión</a></li>
    </ul>
</div>

<!-- Barra Superior idéntica a tu imagen de Panel de Control -->
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
    <div class="page-header">
        <h2>Gestión y Control de <span>Trabajadores</span></h2>
        
        <!-- Sistema de Búsqueda Instantánea con Sugerencias -->
        <div class="search-wrapper" id="searchWrapper">
            <div class="search-box">
                <span>🔍</span>
                <input type="text" id="inputBusqueda" autocomplete="off" onkeyup="manejarBusqueda()" onfocus="manejarBusqueda()" placeholder="Buscar por nombre, DNI, código...">
                <button type="button" class="btn-clear-search" id="btnClearSearch" onclick="limpiarBusqueda()" title="Limpiar búsqueda">&times;</button>
            </div>
            <div class="suggestions-dropdown" id="listaSugerencias"></div>
        </div>

        <div>
            <a href="nuevo_trabajador.php" class="btn-nuevo">➕ Registrar Nuevo Trabajador / Contrato</a>
        </div>
    </div>

    <?php if ($mensaje): ?>
        <div class="alerta-exito"><?php echo $mensaje; ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alerta-error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div class="table-card">
        <table id="tablaTrabajadores">
            <thead>
                <tr>
                    <th>Código</th>
                    <th>Apellidos y Nombres</th>
                    <th>Documento</th>
                    <th>Sede</th>
                    <th>Perfil Sistema</th>
                    <th>Estado</th>
                    <th style="text-align: center;">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($trabajadores)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; color: #777;">No hay trabajadores registrados en el sistema.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($trabajadores as $t): ?>
                        <tr>
                            <td><strong style="color: #00ffcc;"><?php echo htmlspecialchars(trim($t['CodEmpleado'])); ?></strong></td>
                            <td><?php echo htmlspecialchars($t['Apellidos'] . ', ' . $t['Nombres']); ?></td>
                            <td><?php echo htmlspecialchars($t['Documento_Identidad'] . ': ' . $t['NroDoc_Identidad']); ?></td>
                            <td><?php echo htmlspecialchars($t['Sede']); ?></td>
                            <td>
                                <span style="color: #ff80aa; font-weight: bold;">
                                    <?php echo htmlspecialchars($t['Perfil'] ?? 'Sin Acceso'); ?>
                                </span>
                            </td>
                            <td>
                                <?php 
                                    $est = trim($t['Estado_Empleado'] ?? 'Activo');
                                    $clase_badge = 'badge-activo';
                                    if ($est === 'Suspendido') $clase_badge = 'badge-suspendido';
                                    if ($est === 'Vacaciones') $clase_badge = 'badge-vacaciones';
                                ?>
                                <span class="badge <?php echo $clase_badge; ?>"><?php echo htmlspecialchars($est); ?></span>
                            </td>
                            <td style="text-align: center;">
                                <button type="button" class="btn-accion" onclick="abrirModalVisualizar(<?php echo htmlspecialchars(json_encode($t)); ?>)">
                                    👁️ Visualizar / Contrato
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- MODAL DE VISUALIZACIÓN, CONTRATO Y EDICIÓN RÁPIDA -->
<div class="modal-overlay" id="modalVisualizar">
    <div class="modal-box">
        <button class="modal-close" onclick="cerrarModal()">&times;</button>
        <div class="modal-title" id="m_nombre">Detalle del Trabajador</div>
        <div class="modal-subtitle" id="m_codigo_sede">Código y Sede</div>

        <div class="info-grid">
            <div class="seccion-titulo">1. Datos Personales e Identificación</div>
            <div class="info-item">
                <label>Tipo y Nro. Documento</label>
                <span id="m_documento">-</span>
            </div>
            <div class="info-item">
                <label>Nacionalidad / Sexo</label>
                <span id="m_nac_sexo">-</span>
            </div>
            <div class="info-item">
                <label>Fecha de Nacimiento</label>
                <span id="m_fec_nac">-</span>
            </div>
            <div class="info-item">
                <label>Correo Electrónico</label>
                <span id="m_email">-</span>
            </div>
            <div class="info-item full">
                <label>Dirección</label>
                <span id="m_direccion">-</span>
            </div>
            <div class="info-item">
                <label>Estado Civil / Hijos</label>
                <span id="m_civil_hijos">-</span>
            </div>
            <div class="info-item">
                <label>Usuario de Acceso al Sistema</label>
                <span id="m_usuario">-</span>
            </div>

            <div class="seccion-titulo">2. Partes del Contrato y Datos Laborales</div>
            <div class="info-item">
                <label>Sede Asignada</label>
                <span id="m_sede">-</span>
            </div>
            <div class="info-item">
                <label>Estado Actual</label>
                <span id="m_estado_label">-</span>
            </div>
            <div class="info-item full">
                <label>Observación y Cláusula de Estado</label>
                <span id="m_obs">-</span>
            </div>
            <div class="info-item full" style="text-align: center; background: rgba(0,255,204,0.05); border: 1px dashed rgba(0,255,204,0.3);">
                <label style="margin-bottom: 8px;">Documento Legal del Contrato</label>
                <button type="button" onclick="imprimirContratoModal()" style="background: #00ffcc; color: #0b0b10; border: none; padding: 10px 20px; border-radius: 6px; font-weight: bold; cursor: pointer; font-size: 0.9rem;">
                    🖨️ Visualizar e Imprimir Contrato Completo para Firma
                </button>
            </div>
        </div>

        <!-- FORMULARIO DE EDICIÓN RÁPIDA DENTRO DEL MODAL -->
        <form method="POST" action="gestion_trabajadores.php">
            <input type="hidden" name="accion" value="actualizar_empleado">
            <input type="hidden" name="cod_empleado" id="input_cod_empleado">

            <div class="modal-form-section">
                <h3>⚙️ Edición Rápida de Estado y Perfil</h3>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                    <div>
                        <label style="display: block; font-size: 0.85rem; color: #ccc; margin-bottom: 5px; font-weight: bold;">Estado del Empleado:</label>
                        <select name="estado_empleado" id="select_estado" style="width: 100%; padding: 10px; background: #0f0f17; border: 1px solid #323248; border-radius: 8px; color: #fff;">
                            <option value="Activo">Activo</option>
                            <option value="Vacaciones">Vacaciones</option>
                            <option value="Suspendido">Suspendido</option>
                        </select>
                    </div>
                    <div>
                        <label style="display: block; font-size: 0.85rem; color: #ccc; margin-bottom: 5px; font-weight: bold;">Perfil / Rol en Sistema:</label>
                        <select name="perfil" id="select_perfil" style="width: 100%; padding: 10px; background: #0f0f17; border: 1px solid #323248; border-radius: 8px; color: #fff;">
                            <?php foreach ($lista_perfiles as $p): ?>
                                <option value="<?php echo htmlspecialchars($p); ?>"><?php echo htmlspecialchars($p); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div style="margin-bottom: 15px;">
                    <label style="display: block; font-size: 0.85rem; color: #ccc; margin-bottom: 5px; font-weight: bold;">Observación del Estado:</label>
                    <input type="text" name="obs" id="input_obs" style="width: 100%; padding: 10px; background: #0f0f17; border: 1px solid #323248; border-radius: 8px; color: #fff;" placeholder="Ej. Contrato vigente / Permiso autorizado">
                </div>
                <button type="submit" style="width: 100%; padding: 12px; background: linear-gradient(135deg, #00ffcc 0%, #00b386 100%); color: #0b0b10; border: none; border-radius: 8px; font-weight: bold; cursor: pointer; font-size: 0.95rem;">
                    💾 Guardar Cambios del Trabajador
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Plantilla Oculta para Impresión del Contrato desde el Modal -->
<div id="print-document">
    <div style="text-align: center; border-bottom: 2px solid #000; padding-bottom: 15px; margin-bottom: 30px;">
        <h1 style="margin: 0; font-size: 18pt; text-transform: uppercase;">INVERSIONES LA INDUSTRIA SURCO S.A.C.</h1>
        <p style="margin: 5px 0 0 0; font-size: 10pt;">Av. Principal Surco - Lima | RUC: 20600000001</p>
    </div>

    <h2 style="text-align: center; font-size: 14pt; text-transform: uppercase; margin-bottom: 25px; text-decoration: underline;">Contrato Individual de Trabajo</h2>
    
    <p style="text-align: justify; margin-bottom: 15px; text-indent: 40px;">
        Conste por el presente documento, que se celebra por triplicado al amparo de la legislación laboral vigente, de una parte 
        <strong>INVERSIONES LA INDUSTRIA SURCO S.A.C.</strong>, con RUC N° 20600000001, debidamente representada por el área de Administración y Recursos Humanos, 
        con domicilio legal en Av. Principal Surco, a quien en adelante se le denominará <strong>EL EMPLEADOR</strong>; y de la otra parte, 
        don(ña) <strong id="print_nombre">-</strong>, de nacionalidad <span id="print_nacionalidad">-</span>, 
        identificado(a) con <span id="print_documento">-</span>, 
        con domicilio en <span id="print_direccion">-</span>, a quien en adelante se le denominará <strong>EL TRABAJADOR</strong>; 
        en los términos y condiciones siguientes:
    </p>

    <div style="font-weight: bold; margin-top: 20px; margin-bottom: 5px; text-transform: uppercase; font-size: 10.5pt;">PRIMERO: DEL OBJETO DEL CONTRATO Y SEDE</div>
    <p style="text-align: justify; margin-bottom: 15px; text-indent: 40px;">
        <strong>EL EMPLEADOR</strong> requiere los servicios de <strong>EL TRABAJADOR</strong> bajo la modalidad de contrato formal, prestando sus servicios en la sede ubicada en <strong id="print_sede">-</strong>.
    </p>

    <div style="font-weight: bold; margin-top: 20px; margin-bottom: 5px; text-transform: uppercase; font-size: 10.5pt;">SEGUNDO: DE LA JORNADA Y HORARIO DE TRABAJO</div>
    <p style="text-align: justify; margin-bottom: 15px; text-indent: 40px;">
        <strong>EL TRABAJADOR</strong> se obliga a cumplir estrictamente con la jornada ordinaria de trabajo establecida por <strong>EL EMPLEADOR</strong>, 
        la misma que se ajustará a los turnos rotativos y operativos propios de la actividad de entretenimiento y discoteca.
    </p>

    <div style="font-weight: bold; margin-top: 20px; margin-bottom: 5px; text-transform: uppercase; font-size: 10.5pt;">TERCERO: DE LA VIGENCIA Y CONDICIÓN</div>
    <p style="text-align: justify; margin-bottom: 15px; text-indent: 40px;">
        El presente contrato entra en vigencia a partir de la fecha de suscripción del registro bajo el código interno 
        <strong id="print_codigo">-</strong>, sujeto a las disposiciones vigentes de la empresa.
    </p>

    <p style="text-align: justify; margin-top: 30px; text-indent: 40px;">
        Y en señal de conformidad y aceptación con todas las cláusulas precedentes, las partes firman el presente documento en Lima, 
        a los <?php echo date('d'); ?> días del mes de <?php echo date('m'); ?> del año <?php echo date('Y'); ?>.
    </p>

    <div style="margin-top: 90px; display: flex; justify-content: space-between;">
        <div style="width: 40%; text-align: center; border-top: 1px solid #000; padding-top: 10px;">
            <strong>---------------------------------------------------</strong><br>
            <strong>EL EMPLEADOR</strong><br>La Industria Surco S.A.C.
        </div>
        <div style="width: 40%; text-align: center; border-top: 1px solid #000; padding-top: 10px;">
            <strong>---------------------------------------------------</strong><br>
            <strong>EL TRABAJADOR</strong><br><span id="print_doc_firma">-</span><br>Huella Digital
        </div>
    </div>
</div>

<script>
    const listaTrabajadores = <?php echo json_encode($trabajadores, JSON_UNESCAPED_UNICODE); ?>;
    let trabajadorSeleccionado = null;

    function toggleMenu() {
        document.getElementById('sidebar').classList.toggle('active');
        document.getElementById('overlay').classList.toggle('active');
    }

    // Búsqueda en tiempo real con lista desplegable de sugerencias
    function manejarBusqueda() {
        const input = document.getElementById('inputBusqueda');
        const btnClear = document.getElementById('btnClearSearch');
        const dropdown = document.getElementById('listaSugerencias');
        const filtro = input.value.trim().toLowerCase();

        btnClear.style.display = filtro.length > 0 ? 'block' : 'none';
        filtrarTabla(filtro);

        if (filtro.length === 0) {
            dropdown.innerHTML = '';
            dropdown.classList.remove('active');
            return;
        }

        const coincidencias = listaTrabajadores.filter(t => {
            const nombreCompleto = `${t.Apellidos} ${t.Nombres}`.toLowerCase();
            const codigo = (t.CodEmpleado || '').trim().toLowerCase();
            const doc = (t.NroDoc_Identidad || '').trim().toLowerCase();
            return nombreCompleto.includes(filtro) || codigo.includes(filtro) || doc.includes(filtro);
        }).slice(0, 6);

        if (coincidencias.length === 0) {
            dropdown.innerHTML = '<div class="suggestion-item" style="cursor: default; color: #777788;">No se encontraron coincidencias</div>';
            dropdown.classList.add('active');
            return;
        }

        dropdown.innerHTML = '';
        coincidencias.forEach(t => {
            const item = document.createElement('div');
            item.className = 'suggestion-item';
            item.innerHTML = `
                <div>
                    <div class="suggestion-name">${t.Apellidos}, ${t.Nombres}</div>
                    <div class="suggestion-meta">${t.Documento_Identidad}: ${t.NroDoc_Identidad} • ${t.Sede}</div>
                </div>
                <span class="suggestion-code">${t.CodEmpleado.trim()}</span>
            `;
            item.onclick = () => {
                input.value = `${t.Apellidos}, ${t.Nombres}`;
                dropdown.classList.remove('active');
                filtrarTabla(input.value.toLowerCase());
                abrirModalVisualizar(t);
            };
            dropdown.appendChild(item);
        });
        dropdown.classList.add('active');
    }

    function limpiarBusqueda() {
        const input = document.getElementById('inputBusqueda');
        input.value = '';
        document.getElementById('btnClearSearch').style.display = 'none';
        document.getElementById('listaSugerencias').classList.remove('active');
        filtrarTabla('');
        input.focus();
    }

    function filtrarTabla(filtro) {
        const tabla = document.getElementById('tablaTrabajadores');
        const filas = tabla.getElementsByTagName('tr');

        for (let i = 1; i < filas.length; i++) {
            const textoFila = (filas[i].textContent || filas[i].innerText).toLowerCase();
            filas[i].style.display = textoFila.indexOf(filtro) > -1 ? "" : "none";
        }
    }

    // Cerrar sugerencias al hacer clic fuera del buscador
    document.addEventListener('click', function(e) {
        const wrapper = document.getElementById('searchWrapper');
        if (wrapper && !wrapper.contains(e.target)) {
            document.getElementById('listaSugerencias').classList.remove('active');
        }
    });

    function abrirModalVisualizar(t) {
        trabajadorSeleccionado = t;

        document.getElementById('m_nombre').innerText = t.Apellidos + ', ' + t.Nombres;
        document.getElementById('m_codigo_sede').innerText = 'Código: ' + t.CodEmpleado.trim() + ' | Sede: ' + t.Sede;
        
        document.getElementById('m_documento').innerText = t.Documento_Identidad + ': ' + t.NroDoc_Identidad;
        document.getElementById('m_nac_sexo').innerText = t.Nacionalidad + ' / ' + (t.Sexo === 'M' ? 'Masculino' : 'Femenino');
        document.getElementById('m_fec_nac').innerText = t.Fec_Nac ? t.Fec_Nac.substring(0, 10) : 'No especificada';
        document.getElementById('m_email').innerText = t.Email ? t.Email : 'No registrado';
        document.getElementById('m_direccion').innerText = t.Direccion ? t.Direccion : 'No registrada';
        
        let civil = t.Estado_Civil === 'S' ? 'Soltero(a)' : (t.Estado_Civil === 'C' ? 'Casado(a)' : (t.Estado_Civil === 'V' ? 'Viudo(a)' : 'Divorciado(a)'));
        document.getElementById('m_civil_hijos').innerText = civil + ' | Hijos: ' + (t.Nro_Hijos ?? 0);
        document.getElementById('m_obs').innerText = t.Obs_Estado_Empleado ? t.Obs_Estado_Empleado : 'Sin observaciones';
        document.getElementById('m_usuario').innerText = t.Usuario ? t.Usuario + ' (' + t.Perfil + ')' : 'Sin credenciales de acceso';

        // Partes del contrato en el modal
        document.getElementById('m_sede').innerText = t.Sede;
        document.getElementById('m_estado_label').innerText = t.Estado_Empleado ? t.Estado_Empleado.trim() : 'Activo';

        // Cargar valores en el formulario de edición rápida del modal
        document.getElementById('input_cod_empleado').value = t.CodEmpleado.trim();
        document.getElementById('select_estado').value = t.Estado_Empleado ? t.Estado_Empleado.trim() : 'Activo';
        document.getElementById('select_perfil').value = t.Perfil ? t.Perfil.trim() : '';
        document.getElementById('input_obs').value = t.Obs_Estado_Empleado ? t.Obs_Estado_Empleado : '';

        document.getElementById('modalVisualizar').classList.add('active');
    }

    function cerrarModal() {
        document.getElementById('modalVisualizar').classList.remove('active');
    }

    function imprimirContratoModal() {
        if (!trabajadorSeleccionado) return;
        let t = trabajadorSeleccionado;

        document.getElementById('print_nombre').innerText = t.Nombres + ' ' + t.Apellidos;
        document.getElementById('print_nacionalidad').innerText = t.Nacionalidad;
        document.getElementById('print_documento').innerText = t.Documento_Identidad + ' N° ' + t.NroDoc_Identidad;
        document.getElementById('print_direccion').innerText = t.Direccion ? t.Direccion : 'Lima';
        document.getElementById('print_sede').innerText = t.Sede;
        document.getElementById('print_codigo').innerText = t.CodEmpleado.trim();
        document.getElementById('print_doc_firma').innerText = t.Documento_Identidad + ': ' + t.NroDoc_Identidad;

        window.print();
    }
</script>

</body>
</html>