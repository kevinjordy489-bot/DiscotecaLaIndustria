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
$datos_impresion = null;

// Arreglos para catálogos
$nacionalidades  = [];
$documentos      = [];
$sedes           = [];
$areas           = [];
$cargos          = [];
$tipos_contrato  = [];
$lista_perfiles  = [];

try {
    $nacionalidades  = $conn->query("SELECT CodNacionalidad, Nacionalidad FROM Tb_Nacionalidad ORDER BY Nacionalidad")->fetchAll(PDO::FETCH_ASSOC);
    $documentos      = $conn->query("SELECT CodDoc_Identidad, Documento_Identidad FROM Tb_Documento_Identidad ORDER BY Documento_Identidad")->fetchAll(PDO::FETCH_ASSOC);
    $sedes           = $conn->query("SELECT CodSede, Sede FROM Tb_Sede ORDER BY Sede")->fetchAll(PDO::FETCH_ASSOC);
    $areas           = $conn->query("SELECT CodArea, Area FROM Tb_Area ORDER BY Area")->fetchAll(PDO::FETCH_ASSOC);
    $cargos          = $conn->query("SELECT CodCargo, Cargo FROM Tb_Cargo ORDER BY Cargo")->fetchAll(PDO::FETCH_ASSOC);
    $tipos_contrato  = $conn->query("SELECT CodTipo_Contrato, Tipo_Contrato FROM Tb_Tipo_Contrato ORDER BY Tipo_Contrato")->fetchAll(PDO::FETCH_ASSOC);

    $stmt_perfiles = $conn->query("SELECT DISTINCT Perfil FROM Tb_Usuario_Empleado WHERE Perfil IS NOT NULL AND Perfil <> '' ORDER BY Perfil ASC");
    $lista_perfiles = $stmt_perfiles->fetchAll(PDO::FETCH_COLUMN);
    if (empty($lista_perfiles)) {
        $lista_perfiles = ['Administrador', 'Almacen', 'Bartender', 'Vendedor entr', 'Vendedor pro', 'Seguridad'];
    }

    $siguiente_codigo = "EMP01";
    $ultimo_emp = $conn->query("SELECT TOP 1 CodEmpleado FROM Tb_Empleado ORDER BY CodEmpleado DESC")->fetch(PDO::FETCH_ASSOC);
    if ($ultimo_emp) {
        $numero = (int)substr(trim($ultimo_emp['CodEmpleado']), 3);
        $siguiente_codigo = "EMP" . str_pad($numero + 1, 2, "0", STR_PAD_LEFT);
    }
} catch (PDOException $e) {
    $error = "Error al cargar catálogos de la base de datos: " . $e->getMessage();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombres           = trim($_POST['nombres'] ?? '');
    $apellidos         = trim($_POST['apellidos'] ?? '');
    $cod_nacionalidad  = trim($_POST['cod_nacionalidad'] ?? '');
    $cod_doc_identidad = trim($_POST['cod_doc_identidad'] ?? '');
    $nro_doc           = trim($_POST['nro_doc'] ?? '');
    $email             = trim($_POST['email'] ?? '');
    $direccion         = trim($_POST['direccion'] ?? '');
    $fec_nac           = trim($_POST['fec_nac'] ?? '');
    $sexo              = trim($_POST['sexo'] ?? 'M');

    $cod_empleado      = $siguiente_codigo;
    $cod_sede          = trim($_POST['cod_sede'] ?? 'SED01');
    $cod_area          = trim($_POST['cod_area'] ?? '');
    $cod_cargo         = trim($_POST['cod_cargo'] ?? '');
    $cod_tipo_contrato = trim($_POST['cod_tipo_contrato'] ?? '');
    $estado_civil      = trim($_POST['estado_civil'] ?? 'S');
    $nro_hijos         = (int)($_POST['nro_hijos'] ?? 0);
    $estado_empleado   = trim($_POST['estado_empleado'] ?? 'Activo');
    $obs               = trim($_POST['obs'] ?? 'Contrato registrado formalmente');

    $perfil            = trim($_POST['perfil'] ?? 'Vendedor pro');
    $usuario           = trim($_POST['usuario'] ?? '');
    $password          = trim($_POST['password'] ?? '');

    if (empty($nombres) || empty($apellidos) || empty($nro_doc) || empty($cod_area) || empty($cod_cargo) || empty($cod_tipo_contrato)) {
        $error = "Por favor, complete los campos obligatorios de identificación, área, cargo y tipo de contrato.";
    } else {
        try {
            $conn->beginTransaction();

            $cod_persona = random_int(10000, 999999);
            $sql_per = "INSERT INTO Tb_Persona (CodPersona, Apellidos, Nombres, Fec_Nac, Sexo, CodNacionalidad, CodDoc_Identidad, NroDoc_Identidad, CPostal, Direccion, Email) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, '150101', ?, ?)";
            $conn->prepare($sql_per)->execute([$cod_persona, $apellidos, $nombres, $fec_nac, $sexo, $cod_nacionalidad, $cod_doc_identidad, $nro_doc, $direccion, $email]);

            $sql_emp = "INSERT INTO Tb_Empleado (
                            CodEmpleado, CodPersona, Estado_Civil, Nro_Hijos, CodSede, 
                            Estado_Empleado, Obs_Estado_Empleado, CodArea, CodCargo, CodTipo_Contrato
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $conn->prepare($sql_emp)->execute([
                $cod_empleado, $cod_persona, $estado_civil, $nro_hijos, $cod_sede, 
                $estado_empleado, $obs, $cod_area, $cod_cargo, $cod_tipo_contrato
            ]);

            if (!empty($usuario) && !empty($password)) {
                $sql_usu = "INSERT INTO Tb_Usuario_Empleado (CodEmpleado, Usuario, Password, Password_Encriptado, Perfil, Fec_Creacion, Estado_Usuario) 
                            VALUES (?, ?, ?, HASHBYTES('SHA2_256', ?), ?, GETDATE(), 'Activo')";
                $conn->prepare($sql_usu)->execute([$cod_empleado, $usuario, $password, $password, $perfil]);
            }

            $conn->commit();

            $txt_sede = $txt_area = $txt_cargo = $txt_tipo = $txt_nac = $txt_doc = "";
            foreach($sedes as $s) if(trim($s['CodSede']) == $cod_sede) $txt_sede = $s['Sede'];
            foreach($areas as $a) if(trim($a['CodArea']) == $cod_area) $txt_area = $a['Area'];
            foreach($cargos as $c) if(trim($c['CodCargo']) == $cod_cargo) $txt_cargo = $c['Cargo'];
            foreach($tipos_contrato as $tc) if(trim($tc['CodTipo_Contrato']) == $cod_tipo_contrato) $txt_tipo = $tc['Tipo_Contrato'];
            foreach($nacionalidades as $n) if(trim($n['CodNacionalidad']) == $cod_nacionalidad) $txt_nac = $n['Nacionalidad'];
            foreach($documentos as $d) if(trim($d['CodDoc_Identidad']) == $cod_doc_identidad) $txt_doc = $d['Documento_Identidad'];

            $datos_impresion = [
                'codigo' => $cod_empleado,
                'nombre_completo' => "$nombres $apellidos",
                'nacionalidad' => $txt_nac,
                'documento' => "$txt_doc N° $nro_doc",
                'direccion' => $direccion,
                'sede' => $txt_sede,
                'area' => $txt_area,
                'cargo' => $txt_cargo,
                'tipo_contrato' => $txt_tipo
            ];
            
            $mensaje = "¡Trabajador y contrato registrados con éxito! Código asignado: <strong>$cod_empleado</strong><br><br>
                        <button type='button' onclick='imprimirContratoDirecto()' style='background: #00ffcc; color: #0b0b10; border: none; padding: 12px 24px; border-radius: 6px; font-weight: bold; cursor: pointer; font-size: 1rem;'>🖨️ Imprimir Contrato Oficial para Firma</button>";

            $siguiente_numero = ((int)substr($siguiente_codigo, 3)) + 1;
            $siguiente_codigo = "EMP" . str_pad($siguiente_numero, 2, "0", STR_PAD_LEFT);
        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            $error = "Error al guardar el contrato en la base de datos: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registro de Contrato y Trabajador - La Industria Surco</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Segoe UI', system-ui, -apple-system, sans-serif; background-color: #0b0b10; color: #f1f1f5; margin: 0; padding: 0; }
        
        nav {
            background: rgba(16, 16, 24, 0.95); backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(255, 0, 85, 0.2); padding: 16px 40px;
            display: flex; justify-content: space-between; align-items: center;
            position: sticky; top: 0; z-index: 1000;
        }
        .nav-left { display: flex; align-items: center; gap: 20px; }
        .menu-btn {
            background: rgba(255, 0, 85, 0.1); border: 1px solid rgba(255, 0, 85, 0.3);
            cursor: pointer; display: flex; flex-direction: column; gap: 5px; padding: 10px; border-radius: 8px;
        }
        .menu-btn span { display: block; width: 20px; height: 2px; background-color: #ff0055; }
        .logo { font-weight: 800; font-size: 1.25rem; color: #ffffff; text-decoration: none; letter-spacing: 1px; }
        .logo span { color: #ff0055; }
        
        .nav-links { display: flex; gap: 20px; list-style: none; margin: 0; padding: 0; align-items: center; }
        .nav-links a { text-decoration: none; color: #b0b0c0; font-size: 0.9rem; font-weight: 600; }
        .nav-links a:hover, .nav-links a.activo { color: #ff0055; }

        .nav-right { display: flex; align-items: center; gap: 15px; }
        .btn-logout-top {
            background: #252535; color: #ff80aa; padding: 8px 18px; border-radius: 6px;
            text-decoration: none; font-weight: 600; font-size: 0.88rem;
        }
        .btn-logout-top:hover { background: #ff0055; color: #fff; }
        .user-greeting { font-size: 0.9rem; color: #ccc; }
        .user-greeting span { color: #00ffcc; font-weight: bold; }

        .sidebar {
            position: fixed; top: 0; left: -310px; width: 310px; height: 100%;
            background-color: #12121c; border-right: 1px solid rgba(255, 0, 85, 0.2);
            box-shadow: 10px 0 30px rgba(0,0,0,0.8); z-index: 2000;
            transition: left 0.3s ease; padding: 25px 20px; overflow-y: auto; display: flex; flex-direction: column;
        }
        .sidebar.active { left: 0; }
        .sidebar-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #262638; padding-bottom: 15px; margin-bottom: 20px; }
        .sidebar-title { font-weight: 800; font-size: 1.1rem; color: #fff; }
        .sidebar-title span { color: #ff0055; }
        .close-btn { background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #fff; }
        .sidebar-section-title { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 1px; color: #777788; margin: 16px 0 8px 10px; font-weight: bold; }
        .sidebar-menu { list-style: none; padding: 0; margin: 0; display: flex; flex-direction: column; gap: 6px; }
        .sidebar-menu a { text-decoration: none; color: #c0c0d0; font-size: 0.9rem; font-weight: 600; display: block; padding: 9px 14px; border-radius: 8px; transition: all 0.2s; }
        .sidebar-menu a:hover { background: rgba(255, 0, 85, 0.15); color: #ff0055; padding-left: 18px; }

        .overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.6); backdrop-filter: blur(3px); z-index: 1500; display: none; }
        .overlay.active { display: block; }

        .container { max-width: 950px; margin: 35px auto; padding: 0 25px; }
        .btn-volver { display: inline-block; color: #ff6699; text-decoration: none; font-weight: 600; margin-bottom: 20px; }
        .form-card { background: #161622; border: 1px solid #262638; border-radius: 14px; padding: 35px; box-shadow: 0 10px 30px rgba(0,0,0,0.6); }
        .form-card h2 { margin: 0 0 8px 0; font-size: 1.5rem; color: #fff; }
        .form-card h2 span { color: #ff0055; }
        .form-subtext { color: #8888a0; font-size: 0.9rem; margin-bottom: 25px; border-bottom: 1px solid #262638; padding-bottom: 15px; }
        
        .section-divider {
            grid-column: span 2; color: #00ffcc; font-size: 0.95rem; font-weight: 700;
            text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid #262638;
            padding-bottom: 8px; margin-top: 10px;
        }

        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
        .form-group { margin-bottom: 10px; }
        .form-group.full { grid-column: span 2; }
        .form-group label { display: block; margin-bottom: 6px; color: #ccc; font-weight: 600; font-size: 0.85rem; }
        .form-control { width: 100%; padding: 11px 14px; background: #0f0f17; border: 1px solid #323248; border-radius: 8px; color: #fff; font-size: 0.92rem; outline: none; }
        .form-control:focus { border-color: #ff0055; }
        
        .btn-submit { width: 100%; padding: 14px; border: none; border-radius: 8px; background: linear-gradient(135deg, #00ffcc 0%, #00b386 100%); color: #0b0b10; font-size: 1rem; font-weight: bold; cursor: pointer; margin-top: 20px; }
        .btn-submit:hover { opacity: 0.9; }

        .alerta-exito { background: rgba(0,255,204,0.1); border: 1px solid #00ffcc; color: #00ffcc; padding: 15px; border-radius: 8px; margin-bottom: 20px; }
        .alerta-error { background: rgba(255,0,85,0.12); border: 1px solid #ff0055; color: #ff80aa; padding: 15px; border-radius: 8px; margin-bottom: 20px; }

        #print-document { display: none; }
        @media print {
            body * { visibility: hidden; }
            #print-document, #print-document * { visibility: visible; }
            #print-document { display: block; position: absolute; left: 0; top: 0; width: 100%; background: #fff; color: #000; padding: 30px; font-family: 'Times New Roman', serif; font-size: 12pt; }
        }
    </style>
</head>
<body>

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
            <li><a href="nuevo_trabajador.php" class="activo" style="color: #ff0055;">➕ Nuevo Trabajador</a></li>
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
    <a href="gestion_trabajadores.php" class="btn-volver">&larr; Volver a la Gestión de Trabajadores</a>

    <div class="form-card">
        <h2>Registro de Trabajador y <span>Contrato Laboral</span></h2>
        <div class="form-subtext">Complete los datos de identidad, área, cargo y modalidad de contrato correspondientes.</div>

        <?php if ($mensaje): ?>
            <div class="alerta-exito"><?php echo $mensaje; ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alerta-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST" action="nuevo_trabajador.php">
            <div class="form-grid">
                <div class="section-divider">1. Datos Personales</div>

                <div class="form-group">
                    <label>Código de Empleado (Autogenerado):</label>
                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($siguiente_codigo); ?>" disabled style="background: #12121c; color: #00ffcc; font-weight: bold;">
                </div>
                
                <div class="form-group">
                    <label>Tipo de Documento:</label>
                    <select name="cod_doc_identidad" class="form-control" required>
                        <option value="">-- Seleccione Documento --</option>
                        <?php foreach ($documentos as $doc): ?>
                            <option value="<?php echo htmlspecialchars(trim($doc['CodDoc_Identidad'])); ?>"><?php echo htmlspecialchars($doc['Documento_Identidad']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Nro. Documento:</label>
                    <input type="text" name="nro_doc" class="form-control" required placeholder="Nro. de documento">
                </div>

                <div class="form-group">
                    <label>Nacionalidad:</label>
                    <select name="cod_nacionalidad" class="form-control" required>
                        <option value="">-- Seleccione Nacionalidad --</option>
                        <?php foreach ($nacionalidades as $nac): ?>
                            <option value="<?php echo htmlspecialchars(trim($nac['CodNacionalidad'])); ?>"><?php echo htmlspecialchars($nac['Nacionalidad']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Nombres:</label>
                    <input type="text" name="nombres" class="form-control" required placeholder="Nombres completos">
                </div>
                <div class="form-group">
                    <label>Apellidos:</label>
                    <input type="text" name="apellidos" class="form-control" required placeholder="Apellidos completos">
                </div>

                <div class="form-group">
                    <label>Fecha de Nacimiento:</label>
                    <input type="date" name="fec_nac" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>Sexo:</label>
                    <select name="sexo" class="form-control">
                        <option value="M">Masculino</option>
                        <option value="F">Femenino</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Correo Electrónico:</label>
                    <input type="email" name="email" class="form-control" placeholder="correo@laindustria.com">
                </div>
                <div class="form-group">
                    <label>Estado Civil:</label>
                    <select name="estado_civil" class="form-control">
                        <option value="S">Soltero(a)</option>
                        <option value="C">Casado(a)</option>
                        <option value="V">Viudo(a)</option>
                        <option value="D">Divorciado(a)</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Nro. de Hijos:</label>
                    <input type="number" name="nro_hijos" class="form-control" value="0" min="0">
                </div>
                <div class="form-group">
                    <label>Dirección:</label>
                    <input type="text" name="direccion" class="form-control" placeholder="Av. Principal Surco">
                </div>

                <div class="section-divider">2. Datos de Contrato, Área y Cargo</div>

                <div class="form-group">
                    <label>Sede Asignada:</label>
                    <select name="cod_sede" class="form-control" required>
                        <?php foreach ($sedes as $s): ?>
                            <option value="<?php echo htmlspecialchars(trim($s['CodSede'])); ?>"><?php echo htmlspecialchars($s['Sede']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Área de Trabajo:</label>
                    <select name="cod_area" class="form-control" required>
                        <option value="">-- Seleccione Área --</option>
                        <?php foreach ($areas as $a): ?>
                            <option value="<?php echo htmlspecialchars(trim($a['CodArea'])); ?>"><?php echo htmlspecialchars($a['Area']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Cargo:</label>
                    <select name="cod_cargo" class="form-control" required>
                        <option value="">-- Seleccione Cargo --</option>
                        <?php foreach ($cargos as $c): ?>
                            <option value="<?php echo htmlspecialchars(trim($c['CodCargo'])); ?>"><?php echo htmlspecialchars($c['Cargo']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Tipo de Contrato:</label>
                    <select name="cod_tipo_contrato" class="form-control" required>
                        <option value="">-- Seleccione Tipo de Contrato --</option>
                        <?php foreach ($tipos_contrato as $tc): ?>
                            <option value="<?php echo htmlspecialchars(trim($tc['CodTipo_Contrato'])); ?>"><?php echo htmlspecialchars($tc['Tipo_Contrato']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label>Estado del Empleado:</label>
                    <select name="estado_empleado" class="form-control">
                        <option value="Activo">Activo</option>
                        <option value="Vacaciones">En Vacaciones</option>
                        <option value="Suspendido">Suspendido</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Observación del Contrato:</label>
                    <input type="text" name="obs" class="form-control" value="Contrato registrado formalmente">
                </div>

                <div class="section-divider">3. Credenciales de Acceso al Sistema (Opcional)</div>

                <div class="form-group">
                    <label>Perfil / Rol en el Sistema:</label>
                    <select name="perfil" class="form-control">
                        <?php foreach ($lista_perfiles as $p): ?>
                            <option value="<?php echo htmlspecialchars($p); ?>"><?php echo htmlspecialchars($p); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Usuario de Acceso:</label>
                    <input type="text" name="usuario" class="form-control" placeholder="ej. jcruz">
                </div>

                <div class="form-group full">
                    <label>Contraseña para el Sistema:</label>
                    <input type="password" name="password" class="form-control" placeholder="••••••••">
                </div>
            </div>

            <button type="submit" class="btn-submit">Registrar Trabajador y Contrato Oficial</button>
        </form>
    </div>
</div>

<?php if ($datos_impresion): ?>
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
        don(ña) <strong><?php echo htmlspecialchars($datos_impresion['nombre_completo']); ?></strong>, de nacionalidad <?php echo htmlspecialchars($datos_impresion['nacionalidad']); ?>, 
        identificado(a) con <strong><?php echo htmlspecialchars($datos_impresion['documento']); ?></strong>, 
        con domicilio en <?php echo htmlspecialchars($datos_impresion['direccion']); ?>, a quien en adelante se le denominará <strong>EL TRABAJADOR</strong>; 
        en los términos y condiciones siguientes:
    </p>

    <div style="font-weight: bold; margin-top: 20px; margin-bottom: 5px; text-transform: uppercase; font-size: 10.5pt;">PRIMERO: DEL OBJETO DEL CONTRATO</div>
    <p style="text-align: justify; margin-bottom: 15px; text-indent: 40px;">
        <strong>EL EMPLEADOR</strong> requiere los servicios de <strong>EL TRABAJADOR</strong> bajo la modalidad de 
        <strong><?php echo htmlspecialchars($datos_impresion['tipo_contrato']); ?></strong>, para que desempeñe las funciones propias del cargo de 
        <strong><?php echo htmlspecialchars($datos_impresion['cargo']); ?></strong>, perteneciente al área de <strong><?php echo htmlspecialchars($datos_impresion['area']); ?></strong>, 
        prestando sus servicios en la sede ubicada en <strong><?php echo htmlspecialchars($datos_impresion['sede']); ?></strong>.
    </p>

    <div style="font-weight: bold; margin-top: 20px; margin-bottom: 5px; text-transform: uppercase; font-size: 10.5pt;">SEGUNDO: DE LA JORNADA Y HORARIO DE TRABAJO</div>
    <p style="text-align: justify; margin-bottom: 15px; text-indent: 40px;">
        <strong>EL TRABAJADOR</strong> se obliga a cumplir estrictamente con la jornada ordinaria de trabajo establecida por <strong>EL EMPLEADOR</strong>, 
        la misma que se ajustará a los turnos rotativos y operativos propios de la actividad de entretenimiento y discoteca, respetando los límites máximos legales establecidos.
    </p>

    <div style="font-weight: bold; margin-top: 20px; margin-bottom: 5px; text-transform: uppercase; font-size: 10.5pt;">TERCERO: DE LA REMUNERACIÓN Y CONDICIONES</div>
    <p style="text-align: justify; margin-bottom: 15px; text-indent: 40px;">
        <strong>EL EMPLEADOR</strong> abonará a <strong>EL TRABAJADOR</strong> la remuneración acordada conforme a las políticas internas de la empresa, 
        efectuándose los descuentos de ley correspondientes al régimen de pensiones y salud.
    </p>

    <div style="font-weight: bold; margin-top: 20px; margin-bottom: 5px; text-transform: uppercase; font-size: 10.5pt;">CUARTO: DE LA VIGENCIA</div>
    <p style="text-align: justify; margin-bottom: 15px; text-indent: 40px;">
        El presente contrato entra en vigencia a partir de la fecha de suscripción del registro bajo el código interno 
        <strong><?php echo htmlspecialchars($datos_impresion['codigo']); ?></strong>, sujeto a los periodos de prueba de ley.
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
            <strong>EL TRABAJADOR</strong><br><?php echo htmlspecialchars($datos_impresion['documento']); ?><br>Huella Digital
        </div>
    </div>
</div>
<?php endif; ?>

<script>
    function toggleMenu() {
        document.getElementById('sidebar').classList.toggle('active');
        document.getElementById('overlay').classList.toggle('active');
    }

    function imprimirContratoDirecto() {
        window.print();
    }
</script>

</body>
</html>