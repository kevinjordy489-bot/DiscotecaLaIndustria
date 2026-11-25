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
$nacionalidades = [];
$documentos = [];
$proveedor_editar = null;

// Recoger parámetros de búsqueda (GET)
$filtro_razon = trim($_GET['razon'] ?? '');
$filtro_ruc   = trim($_GET['ruc'] ?? '');

try {
    // 1. Cargar catálogos
    $stmt_nac = $conn->query("SELECT CodNacionalidad, Nacionalidad FROM Tb_Nacionalidad");
    $nacionalidades = $stmt_nac->fetchAll(PDO::FETCH_ASSOC);

    $stmt_doc = $conn->query("SELECT CodDoc_Identidad, Documento_Identidad FROM Tb_Documento_Identidad");
    $documentos = $stmt_doc->fetchAll(PDO::FETCH_ASSOC);

    // 2. Generar código automático para nuevo proveedor
    $siguiente_codigo_proveedor = "PRV0001";
    $stmt_cod = $conn->query("SELECT TOP 1 CodProveedor FROM Tb_Proveedor ORDER BY CodProveedor DESC");
    $ultimo_prov = $stmt_cod->fetch(PDO::FETCH_ASSOC);

    if ($ultimo_prov) {
        $ultimo_codigo = trim($ultimo_prov['CodProveedor']); 
        $numero = (int)substr($ultimo_codigo, 3); 
        $siguiente_numero = $numero + 1;
        $siguiente_codigo_proveedor = "PRV" . str_pad($siguiente_numero, 4, "0", STR_PAD_LEFT); 
    }
} catch (PDOException $e) {
    $error = "Error al cargar catálogos: " . $e->getMessage();
}

// 3. PROCESAR ELIMINACIÓN DE PROVEEDOR
if (isset($_GET['eliminar'])) {
    $cod_prov_elim = trim($_GET['eliminar']);
    try {
        $conn->beginTransaction();

        $stmt_ep = $conn->prepare("SELECT CodEmpresa_Proveedor FROM Tb_Proveedor WHERE CodProveedor = ?");
        $stmt_ep->execute([$cod_prov_elim]);
        $res_ep = $stmt_ep->fetch(PDO::FETCH_ASSOC);

        if ($res_ep) {
            $cod_empresa_prov = $res_ep['CodEmpresa_Proveedor'];

            $stmt_del_p = $conn->prepare("DELETE FROM Tb_Proveedor WHERE CodProveedor = ?");
            $stmt_del_p->execute([$cod_prov_elim]);

            $stmt_del_ep = $conn->prepare("DELETE FROM Tb_Empresa_Proveedor WHERE CodEmpresa_Proveedor = ?");
            $stmt_del_ep->execute([$cod_empresa_prov]);

            $conn->commit();
            header("Location: consultar_proveedores.php?msg=eliminado");
            exit;
        }
    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        $error = "No se puede eliminar el proveedor porque tiene compras o registros asociados.";
    }
}

if (isset($_GET['msg']) && $_GET['msg'] === 'eliminado') {
    $mensaje = "¡Proveedor eliminado correctamente del sistema!";
}

// 4. PROCESAR EDICIÓN DE PROVEEDOR
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['editar_proveedor'])) {
    $cod_prov_edit   = trim($_POST['cod_proveedor_edit'] ?? '');
    $razon_social    = trim($_POST['razon_social'] ?? '');
    $ruc             = trim($_POST['ruc'] ?? '');
    $direccion_fisc  = trim($_POST['direccion_fisc'] ?? '');
    $email           = trim($_POST['email'] ?? '');
    $telefono        = trim($_POST['telefono'] ?? '');
    $estado_prov     = trim($_POST['estado_proveedor'] ?? 'Activo');

    if (empty($cod_prov_edit) || empty($razon_social)) {
        $error = "Faltan datos obligatorios para actualizar el proveedor.";
    } else {
        try {
            $conn->beginTransaction();

            $stmt_ep = $conn->prepare("SELECT CodEmpresa_Proveedor FROM Tb_Proveedor WHERE CodProveedor = ?");
            $stmt_ep->execute([$cod_prov_edit]);
            $res_ep = $stmt_ep->fetch(PDO::FETCH_ASSOC);

            if ($res_ep) {
                $cod_empresa_prov = $res_ep['CodEmpresa_Proveedor'];

                $sql_upd_ep = "UPDATE Tb_Empresa_Proveedor SET Razon_Social = ?, RUC = ?, Direccion_Fiscal = ?, Email = ?, Telefono = ? WHERE CodEmpresa_Proveedor = ?";
                $conn->prepare($sql_upd_ep)->execute([$razon_social, $ruc, $direccion_fisc, $email, $telefono, $cod_empresa_prov]);

                $sql_upd_p = "UPDATE Tb_Proveedor SET Estado_Proveedor = ? WHERE CodProveedor = ?";
                $conn->prepare($sql_upd_p)->execute([$estado_prov, $cod_prov_edit]);

                $conn->commit();
                $mensaje = "¡Proveedor <strong>$razon_social</strong> actualizado correctamente!";
            }
        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            $error = "Error al actualizar el proveedor: " . $e->getMessage();
        }
    }
}

// 5. PROCESAR REGISTRO DE NUEVO PROVEEDOR
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['registrar_proveedor'])) {
    $cod_proveedor   = $siguiente_codigo_proveedor;
    $razon_social    = trim($_POST['razon_social'] ?? '');
    $ruc             = trim($_POST['ruc'] ?? '');
    $cpostal         = trim($_POST['cpostal'] ?? '150101');
    $direccion_fisc  = trim($_POST['direccion_fisc'] ?? '');
    $email           = trim($_POST['email'] ?? '');
    $telefono        = trim($_POST['telefono'] ?? '');
    $nombres_cont    = trim($_POST['nombres_cont'] ?? 'Representante');
    $apellidos_cont  = trim($_POST['apellidos_cont'] ?? 'Proveedor');
    $cod_nacionalidad= trim($_POST['cod_nacionalidad'] ?? '');
    $cod_doc_identidad= trim($_POST['cod_doc_identidad'] ?? '');
    $nro_doc_contacto= trim($_POST['nro_doc_contacto'] ?? '');

    if (empty($razon_social) || empty($ruc) || empty($direccion_fisc) || empty($cod_nacionalidad) || empty($cod_doc_identidad) || empty($nro_doc_contacto)) {
        $error = "Por favor, complete todos los campos obligatorios.";
    } else {
        try {
            $stmt_val_ruc = $conn->prepare("SELECT CodEmpresa_Proveedor FROM Tb_Empresa_Proveedor WHERE RUC = ?");
            $stmt_val_ruc->execute([$ruc]);
            if ($stmt_val_ruc->fetch()) {
                throw new Exception("El número de RUC '$ruc' ya está registrado en otra empresa proveedor.");
            }

            $conn->beginTransaction();
            $cod_empresa_prov = 'EP' . str_pad(random_int(1, 999), 3, "0", STR_PAD_LEFT);

            $sql_ep = "INSERT INTO Tb_Empresa_Proveedor (CodEmpresa_Proveedor, Razon_Social, RUC, CPostal, Direccion_Fiscal, Email, Telefono, Estado_Empresa) VALUES (?, ?, ?, ?, ?, ?, ?, 'Activo')";
            $conn->prepare($sql_ep)->execute([$cod_empresa_prov, $razon_social, $ruc, $cpostal, $direccion_fisc, $email, $telefono]);

            $cod_persona = random_int(10000, 999999);
            $sql_per = "INSERT INTO Tb_Persona (CodPersona, Apellidos, Nombres, Fec_Nac, Sexo, CodNacionalidad, CodDoc_Identidad, NroDoc_Identidad, CPostal, Direccion, Email) VALUES (?, ?, ?, '1990-01-01', 'M', ?, ?, ?, ?, ?, ?)";
            $conn->prepare($sql_per)->execute([$cod_persona, $apellidos_cont, $nombres_cont, $cod_nacionalidad, $cod_doc_identidad, $nro_doc_contacto, $cpostal, $direccion_fisc, $email]);

            $sql_prov = "INSERT INTO Tb_Proveedor (CodProveedor, CodPersona, Fec_Registro, CodEmpresa_Proveedor, Estado_Proveedor) VALUES (?, ?, GETDATE(), ?, 'Activo')";
            $conn->prepare($sql_prov)->execute([$cod_proveedor, $cod_persona, $cod_empresa_prov]);

            $conn->commit();
            $mensaje = "¡Proveedor registrado con éxito! Código: <strong>$cod_proveedor</strong>";

            $siguiente_numero = (int)substr($siguiente_codigo_proveedor, 3) + 1;
            $siguiente_codigo_proveedor = "PRV" . str_pad($siguiente_numero, 4, "0", STR_PAD_LEFT);

        } catch (Exception $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            $error = "Error al registrar el proveedor: " . $e->getMessage();
        }
    }
}

// 6. CARGAR DATOS PARA EDITAR SI SE SOLICITÓ
if (isset($_GET['editar'])) {
    $id_editar = trim($_GET['editar']);
    try {
        $stmt_ed = $conn->prepare("SELECT p.CodProveedor, ep.Razon_Social, ep.RUC, ep.Direccion_Fiscal, ep.Email, ep.Telefono, p.Estado_Proveedor 
                                   FROM Tb_Proveedor p 
                                   INNER JOIN Tb_Empresa_Proveedor ep ON p.CodEmpresa_Proveedor = ep.CodEmpresa_Proveedor 
                                   WHERE p.CodProveedor = ?");
        $stmt_ed->execute([$id_editar]);
        $proveedor_editar = $stmt_ed->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error = "Error al cargar datos para editar.";
    }
}

// 7. CONSULTAR PROVEEDORES Y VERIFICAR SI TIENEN COMPRAS (PARA EL KARDEX)
$proveedores = [];
try {
    $sql_list = "SELECT p.CodProveedor, ep.Razon_Social, ep.RUC, ep.Direccion_Fiscal, ep.Email, ep.Telefono, p.Estado_Proveedor,
                 (SELECT COUNT(*) FROM Tb_Orden_Compra_Insumo oci WHERE oci.CodProveedor = p.CodProveedor) AS Tiene_Insumos,
                 (SELECT COUNT(*) FROM Tb_Orden_Compra_Producto ocp WHERE ocp.CodProveedor = p.CodProveedor) AS Tiene_Productos
                 FROM Tb_Proveedor p
                 INNER JOIN Tb_Empresa_Proveedor ep ON p.CodEmpresa_Proveedor = ep.CodEmpresa_Proveedor
                 WHERE 1=1";
    
    $params = [];
    if (!empty($filtro_razon)) {
        $sql_list .= " AND ep.Razon_Social LIKE ?";
        $params[] = "%" . $filtro_razon . "%";
    }
    if (!empty($filtro_ruc)) {
        $sql_list .= " AND ep.RUC LIKE ?";
        $params[] = "%" . $filtro_ruc . "%";
    }
    $sql_list .= " ORDER BY p.CodProveedor ASC";

    $stmt_proveedores = $conn->prepare($sql_list);
    $stmt_proveedores->execute($params);
    $proveedores = $stmt_proveedores->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Error al consultar la tabla de proveedores: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Proveedores - La Industria Surco</title>
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

        /* Menú Lateral (Sidebar) */
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

        .search-card { background: #161622; border: 1px solid #262638; border-radius: 12px; padding: 20px; margin-bottom: 25px; box-shadow: 0 8px 25px rgba(0,0,0,0.4); }
        .search-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)) 140px; gap: 15px; align-items: flex-end; }
        
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.75); backdrop-filter: blur(4px); z-index: 1000; justify-content: center; align-items: center; padding: 20px; }
        .modal-overlay.active { display: flex; }
        
        .modal-content { background: #161622; border: 1px solid #323248; border-radius: 14px; padding: 30px; width: 100%; max-width: 750px; max-height: 90vh; overflow-y: auto; box-shadow: 0 15px 40px rgba(0,0,0,0.7); position: relative; animation: modalFade 0.3s ease; }
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
        .btn-limpiar { color: #aaa; text-decoration: none; font-size: 0.82rem; margin-top: 6px; display: inline-block; text-align: center; width: 100%; cursor: pointer; }
        .btn-limpiar:hover { color: #fff; }

        .table-container { background: #161622; border: 1px solid #262638; border-radius: 12px; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.5); }
        table { width: 100%; border-collapse: collapse; text-align: left; font-size: 0.85rem; }
        th { background: #1f1f2e; color: #ff80aa; padding: 15px; font-weight: 600; border-bottom: 1px solid #262638; }
        td { padding: 15px; border-bottom: 1px solid #222233; color: #d0d0d8; }
        tr:hover { background: #1a1a29; }

        .acciones-td { display: flex; gap: 5px; align-items: center; flex-wrap: wrap; }
        .btn-editar { background: #252535; color: #ff80aa; padding: 5px 8px; border-radius: 5px; text-decoration: none; font-size: 0.73rem; font-weight: bold; border: 1px solid #ff80aa; }
        .btn-editar:hover { background: #ff0055; color: #fff; }
        .btn-eliminar { background: #252535; color: #ff4466; padding: 5px 8px; border-radius: 5px; text-decoration: none; font-size: 0.73rem; font-weight: bold; border: 1px solid #ff4466; }
        .btn-eliminar:hover { background: #ff4466; color: #fff; }
        
        .btn-crear-orden { background: #252535; color: #00ffcc; padding: 5px 8px; border-radius: 5px; text-decoration: none; font-size: 0.73rem; font-weight: bold; border: 1px solid #00ffcc; }
        .btn-crear-orden:hover { background: #00ffcc; color: #0b0b10; }

        .btn-kardex-insumo { background: #252535; color: #ffc107; padding: 5px 8px; border-radius: 5px; text-decoration: none; font-size: 0.73rem; font-weight: bold; border: 1px solid #ffc107; }
        .btn-kardex-insumo:hover { background: #ffc107; color: #0b0b10; }
        .btn-kardex-prod { background: #252535; color: #00ffcc; padding: 5px 8px; border-radius: 5px; text-decoration: none; font-size: 0.73rem; font-weight: bold; border: 1px solid #00ffcc; }
        .btn-kardex-prod:hover { background: #00ffcc; color: #0b0b10; }

        .estado-activo { color: #00ffcc; font-weight: bold; }
        .estado-inactivo { color: #ff4466; font-weight: bold; }
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
            <li><a href="consultar_proveedores.php" class="activo" style="color: #ff0055;">🚚 Proveedores</a></li>
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
        <h2 class="seccion-titulo">Consulta y Gestión de Proveedores</h2>
        <button class="btn-toggle" onclick="abrirModal()">+ Añadir Nuevo Proveedor</button>
    </div>

    <?php if ($mensaje): ?>
        <div class="alerta-exito"><?php echo $mensaje; ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alerta-error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <!-- CENTRO DE BÚSQUEDA PREDICTIVA Y FILTROS -->
    <div class="search-card">
        <form method="GET" action="consultar_proveedores.php">
            <div class="search-grid">
                <div class="form-group" style="margin-bottom:0;">
                    <label>Buscar por Razón Social:</label>
                    <input type="text" id="inputRazon" name="razon" list="listaRazones" class="form-control" placeholder="Escribe para filtrar al instante..." value="<?php echo htmlspecialchars($filtro_razon); ?>" autocomplete="off">
                    <datalist id="listaRazones">
                        <?php foreach ($proveedores as $p): ?>
                            <option value="<?php echo htmlspecialchars($p['Razon_Social']); ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label>Buscar por RUC:</label>
                    <input type="text" id="inputRuc" name="ruc" list="listaRucs" class="form-control" placeholder="Nro. RUC..." value="<?php echo htmlspecialchars($filtro_ruc); ?>" autocomplete="off">
                    <datalist id="listaRucs">
                        <?php foreach ($proveedores as $p): ?>
                            <option value="<?php echo htmlspecialchars($p['RUC']); ?>"><?php echo htmlspecialchars($p['Razon_Social']); ?></option>
                        <?php endforeach; ?>
                    </datalist>
                </div>
                <div>
                    <button type="submit" class="btn-buscar">🔍 Buscar</button>
                    <a href="consultar_proveedores.php" class="btn-limpiar">Limpiar filtros</a>
                </div>
            </div>
        </form>
    </div>

    <!-- VENTANA MODAL FLOTANTE PARA NUEVO PROVEEDOR -->
    <div class="modal-overlay" id="modalProveedor">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Registrar Nuevo Proveedor</h3>
                <button class="btn-cerrar-modal" onclick="cerrarModal()">&times;</button>
            </div>
            <form method="POST" action="consultar_proveedores.php">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Código (Autogenerado):</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($siguiente_codigo_proveedor); ?>" disabled style="background: #12121c; color: #00ffcc; font-weight: bold;">
                    </div>
                    <div class="form-group">
                        <label>RUC Empresa (11 dígitos):</label>
                        <input type="text" name="ruc" class="form-control" required maxlength="11" placeholder="20123456789">
                    </div>
                    <div class="form-group full">
                        <label>Razón Social de la Empresa:</label>
                        <input type="text" name="razon_social" class="form-control" required placeholder="Ej. Distribuidora S.A.C.">
                    </div>
                    
                    <div class="form-group">
                        <label>Tipo de Documento (Contacto):</label>
                        <select name="cod_doc_identidad" class="form-control" required>
                            <option value="">-- Seleccione Tipo --</option>
                            <?php foreach ($documentos as $doc): ?>
                                <option value="<?php echo htmlspecialchars(trim($doc['CodDoc_Identidad'])); ?>"><?php echo htmlspecialchars($doc['Documento_Identidad']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Nro. Documento (Contacto):</label>
                        <input type="text" name="nro_doc_contacto" class="form-control" required placeholder="Ej. 45678912">
                    </div>

                    <div class="form-group">
                        <label>Nacionalidad (Contacto):</label>
                        <select name="cod_nacionalidad" class="form-control" required>
                            <option value="">-- Seleccione Nacionalidad --</option>
                            <?php foreach ($nacionalidades as $nac): ?>
                                <option value="<?php echo htmlspecialchars(trim($nac['CodNacionalidad'])); ?>"><?php echo htmlspecialchars($nac['Nacionalidad']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Código Postal:</label>
                        <input type="text" name="cpostal" class="form-control" value="150101" required>
                    </div>
                    <div class="form-group">
                        <label>Teléfono:</label>
                        <input type="text" name="telefono" class="form-control" placeholder="987654321">
                    </div>
                    <div class="form-group full">
                        <label>Dirección Fiscal:</label>
                        <input type="text" name="direccion_fisc" class="form-control" required placeholder="Av. Industrial 456">
                    </div>
                    <div class="form-group">
                        <label>Correo Electrónico:</label>
                        <input type="email" name="email" class="form-control" placeholder="ventas@proveedor.com">
                    </div>
                    <div class="form-group">
                        <label>Nombre de Contacto:</label>
                        <input type="text" name="nombres_cont" class="form-control" placeholder="Representante">
                    </div>
                </div>
                <div style="margin-top: 20px; display: flex; justify-content: flex-end; gap: 10px;">
                    <button type="button" onclick="cerrarModal()" style="background: #252535; color: #aaa; border: none; padding: 11px 18px; border-radius: 6px; cursor: pointer; font-weight: bold;">Cancelar</button>
                    <button type="submit" name="registrar_proveedor" class="btn-accion">Guardar Proveedor</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL DE EDICIÓN FLOTANTE -->
    <?php if ($proveedor_editar): ?>
        <div class="modal-overlay active">
            <div class="modal-content" style="border-color: #00ffcc;">
                <div class="modal-header">
                    <h3>Editar Proveedor: <?php echo htmlspecialchars($proveedor_editar['CodProveedor']); ?></h3>
                    <a href="consultar_proveedores.php" class="btn-cerrar-modal" style="text-decoration: none;">&times;</a>
                </div>
                <form method="POST" action="consultar_proveedores.php">
                    <input type="hidden" name="cod_proveedor_edit" value="<?php echo htmlspecialchars(trim($proveedor_editar['CodProveedor'])); ?>">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>RUC:</label>
                            <input type="text" name="ruc" class="form-control" value="<?php echo htmlspecialchars($proveedor_editar['RUC']); ?>" required maxlength="11">
                        </div>
                        <div class="form-group full">
                            <label>Razón Social:</label>
                            <input type="text" name="razon_social" class="form-control" value="<?php echo htmlspecialchars($proveedor_editar['Razon_Social']); ?>" required>
                        </div>
                        <div class="form-group full">
                            <label>Dirección Fiscal:</label>
                            <input type="text" name="direccion_fisc" class="form-control" value="<?php echo htmlspecialchars($proveedor_editar['Direccion_Fiscal']); ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Teléfono:</label>
                            <input type="text" name="telefono" class="form-control" value="<?php echo htmlspecialchars($proveedor_editar['Telefono']); ?>">
                        </div>
                        <div class="form-group">
                            <label>Correo:</label>
                            <input type="email" name="email" class="form-control" value="<?php echo htmlspecialchars($proveedor_editar['Email']); ?>">
                        </div>
                        <div class="form-group">
                            <label>Estado del Proveedor:</label>
                            <select name="estado_proveedor" class="form-control">
                                <option value="Activo" <?php echo ($proveedor_editar['Estado_Proveedor'] === 'Activo') ? 'selected' : ''; ?>>Activo</option>
                                <option value="Inactivo" <?php echo ($proveedor_editar['Estado_Proveedor'] === 'Inactivo') ? 'selected' : ''; ?>>Inactivo</option>
                            </select>
                        </div>
                    </div>
                    <div style="margin-top: 20px; display: flex; justify-content: flex-end; gap: 10px;">
                        <a href="consultar_proveedores.php" style="background: #252535; color: #aaa; border: none; padding: 11px 18px; border-radius: 6px; cursor: pointer; text-decoration: none; font-weight: bold; display: inline-block;">Cancelar</a>
                        <button type="submit" name="editar_proveedor" class="btn-accion">Actualizar Proveedor</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <!-- TABLA DE PROVEEDORES -->
    <div class="table-container">
        <table id="tablaProveedores">
            <thead>
                <tr>
                    <th>Código</th>
                    <th>Razón Social</th>
                    <th>RUC</th>
                    <th>Dirección Fiscal</th>
                    <th>Teléfono</th>
                    <th>Estado</th>
                    <th>Acciones y Operaciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($proveedores)): ?>
                    <tr id="filaSinResultadosBD">
                        <td colspan="7" style="text-align: center; color: #8888a0; padding: 30px;">No hay proveedores registrados. Añade uno nuevo usando el botón superior.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($proveedores as $prov): 
                        $tiene_insumos = (int)$prov['Tiene_Insumos'] > 0;
                        $tiene_productos = (int)$prov['Tiene_Productos'] > 0;
                        $cod_prov = trim($prov['CodProveedor']);
                    ?>
                        <tr class="fila-proveedor">
                            <td><strong><?php echo htmlspecialchars($cod_prov); ?></strong></td>
                            <td class="col-razon"><?php echo htmlspecialchars($prov['Razon_Social']); ?></td>
                            <td class="col-ruc"><?php echo htmlspecialchars($prov['RUC']); ?></td>
                            <td><?php echo htmlspecialchars($prov['Direccion_Fiscal']); ?></td>
                            <td><?php echo htmlspecialchars($prov['Telefono'] ?? 'N/D'); ?></td>
                            <td>
                                <span class="<?php echo ($prov['Estado_Proveedor'] === 'Activo') ? 'estado-activo' : 'estado-inactivo'; ?>">
                                    <?php echo htmlspecialchars($prov['Estado_Proveedor']); ?>
                                </span>
                            </td>
                            <td>
                                <div class="acciones-td">
                                    <a href="consultar_proveedores.php?editar=<?php echo urlencode($cod_prov); ?>" class="btn-editar">✏️ Editar</a>
                                    
                                    <?php if (!$tiene_insumos && !$tiene_productos): ?>
                                        <a href="consultar_proveedores.php?eliminar=<?php echo urlencode($cod_prov); ?>" class="btn-eliminar" onclick="return confirm('¿Está seguro de eliminar este proveedor?');">🗑️ Eliminar</a>
                                    <?php endif; ?>
                                    
                                    <a href="generar_orden_compra_insumo.php?cod_proveedor=<?php echo urlencode($cod_prov); ?>" class="btn-crear-orden" title="Generar Orden de Insumo">OC Insumo</a>
                                    <a href="registrar_orden_compra_producto.php?cod_proveedor=<?php echo urlencode($cod_prov); ?>" class="btn-crear-orden" style="color: #ff80aa; border-color: #ff80aa;" title="Generar Orden de Producto">OC Prod</a>

                                    <?php if ($tiene_insumos): ?>
                                        <a href="kardex_insumos.php?cod_proveedor=<?php echo urlencode($cod_prov); ?>" class="btn-kardex-insumo" title="Kardex de Insumos">🌾 Insumos</a>
                                    <?php endif; ?>
                                    
                                    <?php if ($tiene_productos): ?>
                                        <a href="kardex_productos.php?cod_proveedor=<?php echo urlencode($cod_prov); ?>" class="btn-kardex-prod" title="Kardex de Productos">🍻 Productos</a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <tr id="filaSinResultadosJS" style="display: none;">
                        <td colspan="7" style="text-align: center; color: #8888a0; padding: 30px;">No se encontraron proveedores que coincidan con tu búsqueda.</td>
                    </tr>
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

    function abrirModal() {
        document.getElementById('modalProveedor').classList.add('active');
    }

    function cerrarModal() {
        document.getElementById('modalProveedor').classList.remove('active');
    }

    // Búsqueda predictiva y filtrado instantáneo en tiempo real
    document.addEventListener('DOMContentLoaded', function() {
        const inputRazon = document.getElementById('inputRazon');
        const inputRuc = document.getElementById('inputRuc');
        const filas = document.querySelectorAll('.fila-proveedor');
        const filaVacia = document.getElementById('filaSinResultadosJS');

        function filtrarTabla() {
            const textoRazon = inputRazon.value.toLowerCase().trim();
            const textoRuc = inputRuc.value.toLowerCase().trim();
            let visibles = 0;

            filas.forEach(fila => {
                const razon = fila.querySelector('.col-razon').textContent.toLowerCase();
                const ruc = fila.querySelector('.col-ruc').textContent.toLowerCase();

                const coincideRazon = razon.includes(textoRazon);
                const coincideRuc = ruc.includes(textoRuc);

                if (coincideRazon && coincideRuc) {
                    fila.style.display = '';
                    visibles++;
                } else {
                    fila.style.display = 'none';
                }
            });

            if (filaVacia) {
                filaVacia.style.display = (visibles === 0 && filas.length > 0) ? '' : 'none';
            }
        }

        if (inputRazon && inputRuc) {
            inputRazon.addEventListener('input', filtrarTabla);
            inputRuc.addEventListener('input', filtrarTabla);
        }
    });
</script>

</body>
</html>