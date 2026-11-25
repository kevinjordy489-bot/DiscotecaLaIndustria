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
$resultado_busqueda = null;

// PROCESAR FORMULARIOS SEGÚN LA ACCIÓN ENVIADA
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = trim($_POST['accion'] ?? '');

    try {
        if ($accion === 'crear_evento') {
            $sede         = trim($_POST['CodSede'] ?? '');
            $tipo_ev_input= trim($_POST['CodTipo_Evento_Input'] ?? '');
            $nombre       = trim($_POST['Nombre_Evento'] ?? '');
            $desc         = trim($_POST['Descripcion_Evento'] ?? '');
            $fec_ini      = trim($_POST['Fec_Inicio_Evento'] ?? '');
            $fec_ter      = trim($_POST['Fec_Termino_Evento'] ?? '');
            $h_ini        = trim($_POST['Hora_Inicio'] ?? '');
            $h_fin        = trim($_POST['Hora_Fin'] ?? '');
            $aforo        = (int)($_POST['Aforo'] ?? 0);

            // Manejo de la imagen del evento
            $ruta_imagen = null;
            if (isset($_FILES['img']) && $_FILES['img']['error'] === UPLOAD_ERR_OK) {
                $file_tmp  = $_FILES['img']['tmp_name'];
                $file_name = $_FILES['img']['name'];
                $file_ext  = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                
                $permitidos = ['jpg', 'jpeg', 'png', 'webp'];
                if (in_array($file_ext, $permitidos)) {
                    $nuevo_nombre = 'evento_' . time() . '.' . $file_ext;
                    $directorio   = '../uploads/eventos/';
                    
                    if (!is_dir($directorio)) {
                        mkdir($directorio, 0777, true);
                    }
                    
                    $ruta_destino = $directorio . $nuevo_nombre;
                    if (move_uploaded_file($file_tmp, $ruta_destino)) {
                        $ruta_imagen = $ruta_destino;
                    }
                } else {
                    $error = "Formato de imagen no permitido. Utiliza JPG, JPEG, PNG o WEBP.";
                }
            }

            if (empty($error)) {
                $tipo_ev_id = $tipo_ev_input;
                $val_te = $conn->prepare("SELECT CodTipo_Evento FROM Tb_Tipo_Evento WHERE Tipo_Evento = ? OR CodTipo_Evento = ?");
                $val_te->execute([$tipo_ev_input, $tipo_ev_input]);
                $res_te = $val_te->fetch(PDO::FETCH_ASSOC);

                if (!$res_te) {
                    $max_te = $conn->query("SELECT MAX(CAST(CodTipo_Evento AS INT)) FROM Tb_Tipo_Evento WHERE ISNUMERIC(CodTipo_Evento) = 1")->fetchColumn();
                    $tipo_ev_id = $max_te ? ($max_te + 1) : 1;
                    $ins_te = $conn->prepare("INSERT INTO Tb_Tipo_Evento (CodTipo_Evento, Tipo_Evento) VALUES (?, ?)");
                    $ins_te->execute([$tipo_ev_id, $tipo_ev_input]);
                } else {
                    $tipo_ev_id = $res_te['CodTipo_Evento'];
                }

                $max_ev = $conn->query("SELECT COUNT(*) FROM Tb_Evento")->fetchColumn();
                $nro_evento = 'EV' . str_pad($max_ev + 1, 3, '0', STR_PAD_LEFT);

                $stmt = $conn->prepare("INSERT INTO Tb_Evento (NroEvento, CodSede, CodTipo_Evento, Nombre_Evento, Descripcion_Evento, Fec_Inicio_Evento, Fec_Termino_Evento, Hora_Inicio, Hora_Fin, Aforo, Estado_Evento, Obs_Estado_Evento, img) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Programado', 'Ninguna', ?)");
                $stmt->execute([$nro_evento, $sede, $tipo_ev_id, $nombre, $desc, $fec_ini, $fec_ter, $h_ini, $h_fin, $aforo, $ruta_imagen]);
                $mensaje = "¡Evento '$nombre' programado con código automático $nro_evento y afiche cargado!";
            }

        } elseif ($accion === 'crear_tipo_ticket') {
            $nombre = trim($_POST['Tipo_Ticket_Input'] ?? '');
            $desc = trim($_POST['Descripcion_Ticket'] ?? 'General');
            $estado = 'Activo';

            if (!empty($nombre)) {
                $chk = $conn->prepare("SELECT CodTipoTicket FROM Tb_Tipo_Ticket WHERE Tipo_Ticket = ?");
                $chk->execute([$nombre]);
                if (!$chk->fetch()) {
                    $max = $conn->query("SELECT MAX(CAST(CodTipoTicket AS INT)) FROM Tb_Tipo_Ticket WHERE ISNUMERIC(CodTipoTicket) = 1")->fetchColumn();
                    $nuevo_cod = $max ? ($max + 1) : 1;

                    $stmt = $conn->prepare("INSERT INTO Tb_Tipo_Ticket (CodTipoTicket, Tipo_Ticket, Descripcion_Ticket, Estado_Ticket) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$nuevo_cod, $nombre, $desc, $estado]);
                    $mensaje = "¡Tipo de ticket '$nombre' registrado con éxito!";
                } else {
                    $error = "El tipo de ticket ya se encuentra registrado.";
                }
            }

        } elseif ($accion === 'crear_precio_ticket') {
            $cod_tipo_ticket = trim($_POST['CodTipoTicket'] ?? '');
            $precio          = (float)($_POST['Precio'] ?? 0);
            $tipo_venta      = trim($_POST['Tipo_Venta'] ?? 'Presencial');

            if (!empty($cod_tipo_ticket) && $precio > 0) {
                $max_p = $conn->query("SELECT MAX(CAST(IDPrecio AS INT)) FROM Precio_Ticket WHERE ISNUMERIC(IDPrecio) = 1")->fetchColumn();
                $nuevo_id_precio = $max_p ? ($max_p + 1) : 1;

                $stmt = $conn->prepare("INSERT INTO Precio_Ticket (IDPrecio, CodTipoTicket, Precio, Tipo_Venta) VALUES (?, ?, ?, ?)");
                $stmt->execute([$nuevo_id_precio, $cod_tipo_ticket, $precio, $tipo_venta]);
                $mensaje = "¡Precio de S/. " . number_format($precio, 2) . " registrado con éxito para el ticket!";
            } else {
                $error = "Debe seleccionar un tipo de ticket válido y un precio mayor a 0.";
            }

        } elseif ($accion === 'generar_lote_tickets') {
            $nro_evento = trim($_POST['NroEvento'] ?? '');
            $id_precio  = trim($_POST['IDPrecio'] ?? '');
            $cantidad   = (int)($_POST['Cantidad_Tickets'] ?? 0);

            if ($cantidad > 0 && !empty($id_precio)) {
                $max_tck = $conn->query("SELECT MAX(CAST(SUBSTRING(CodTicket, 4, LEN(CodTicket)) AS INT)) FROM Tb_Ticket WHERE CodTicket LIKE 'TCK%' AND ISNUMERIC(SUBSTRING(CodTicket, 4, LEN(CodTicket))) = 1")->fetchColumn();
                $num_base = $max_tck ? (int)$max_tck : 1000;

                $generados = 0;
                for ($i = 1; $i <= $cantidad; $i++) {
                    $num_base++;
                    $cod_ticket = 'TCK' . str_pad($num_base, 4, '0', STR_PAD_LEFT);

                    $val_dup = $conn->prepare("SELECT COUNT(*) FROM Tb_Ticket WHERE CodTicket = ?");
                    $val_dup->execute([$cod_ticket]);
                    if ($val_dup->fetchColumn() > 0) {
                        $num_base += 10;
                        $cod_ticket = 'TCK' . str_pad($num_base, 4, '0', STR_PAD_LEFT);
                    }

                    $stmt = $conn->prepare("INSERT INTO Tb_Ticket (CodTicket, NroEvento, IDPrecio, Estado_Ticket) VALUES (?, ?, ?, 'Disponible')");
                    if ($stmt->execute([$cod_ticket, $nro_evento, $id_precio])) {
                        $generados++;
                    }
                }
                $mensaje = "¡Se han generado exitosamente $generados tickets en lote de forma limpia!";
            } else {
                $error = "Debe seleccionar un precio válido y una cantidad mayor a 0.";
            }
        }
    } catch (PDOException $e) {
        $error = "Error en la base de datos: " . $e->getMessage();
    }
}

// PROCESAR CENTRO DE BÚSQUEDA DE TICKETS
if (isset($_GET['buscar_ticket']) && !empty(trim($_GET['buscar_ticket']))) {
    $criterio = trim($_GET['buscar_ticket']);
    
    $sql_busqueda = "SELECT t.CodTicket, t.Estado_Ticket, e.Nombre_Evento, p.Precio, tt.Tipo_Ticket,
                            v.Nro_Venta_Ticket, c.Nom_Cliente, c.Ape_Cliente
                     FROM Tb_Ticket t
                     INNER JOIN Tb_Evento e ON t.NroEvento = e.NroEvento
                     INNER JOIN Precio_Ticket p ON t.IDPrecio = p.IDPrecio
                     INNER JOIN Tb_Tipo_Ticket tt ON p.CodTipoTicket = tt.CodTipoTicket
                     LEFT JOIN Tb_Detalle_Venta dv ON t.CodTicket = dv.CodTicket
                     LEFT JOIN Tb_Venta_Ticket v ON dv.Nro_Venta_Ticket = v.Nro_Venta_Ticket
                     LEFT JOIN Tb_Cliente c ON v.CodCliente = c.CodCliente
                     WHERE t.CodTicket LIKE ? OR c.Nom_Cliente LIKE ? OR c.Ape_Cliente LIKE ? OR v.Nro_Venta_Ticket LIKE ?";
    
    $stmt_b = $conn->prepare($sql_busqueda);
    $stmt_b->execute(["%$criterio%", "%$criterio%", "%$criterio%", "%$criterio%"]);
    $resultado_busqueda = $stmt_b->fetchAll(PDO::FETCH_ASSOC);
}

// OBTENER LISTADOS PARA LAS TABLAS, SELECTS Y SUGERENCIAS PREDICTIVAS
$tipos_ticket = $conn->query("SELECT * FROM Tb_Tipo_Ticket")->fetchAll(PDO::FETCH_ASSOC);
$eventos = $conn->query("SELECT e.NroEvento, e.Nombre_Evento, s.Sede, e.Fec_Inicio_Evento FROM Tb_Evento e INNER JOIN Tb_Sede s ON e.CodSede = s.CodSede")->fetchAll(PDO::FETCH_ASSOC);
$sedes = $conn->query("SELECT CodSede, Sede FROM Tb_Sede WHERE Estado_Sede = 'Activo'")->fetchAll(PDO::FETCH_ASSOC);
$tipos_evento = $conn->query("SELECT CodTipo_Evento, Tipo_Evento FROM Tb_Tipo_Evento")->fetchAll(PDO::FETCH_ASSOC);
$precios_tickets = $conn->query("SELECT p.IDPrecio, t.Tipo_Ticket, p.Precio, p.Tipo_Venta FROM Precio_Ticket p INNER JOIN Tb_Tipo_Ticket t ON p.CodTipoTicket = t.CodTipoTicket")->fetchAll(PDO::FETCH_ASSOC);

$sugerencias_tickets = $conn->query("SELECT CodTicket FROM Tb_Ticket")->fetchAll(PDO::FETCH_ASSOC);

$tickets_registrados = $conn->query("SELECT TOP 10 t.CodTicket, e.Nombre_Evento, p.Precio, t.Estado_Ticket 
                                    FROM Tb_Ticket t 
                                    INNER JOIN Tb_Evento e ON t.NroEvento = e.NroEvento 
                                    INNER JOIN Precio_Ticket p ON t.IDPrecio = p.IDPrecio 
                                    ORDER BY TRY_CAST(SUBSTRING(t.CodTicket, 4, LEN(t.CodTicket)) AS INT) DESC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Tickets y Eventos - La Industria Surco</title>
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

        /* Estilos de los Botones de Apertura de Modales */
        .action-buttons-container {
            display: flex;
            gap: 15px;
            margin-bottom: 25px;
            flex-wrap: wrap;
        }
        .btn-modal-trigger {
            background: #161622;
            color: #fff;
            border: 1px solid rgba(255, 0, 85, 0.4);
            padding: 14px 22px;
            border-radius: 10px;
            font-weight: bold;
            cursor: pointer;
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            gap: 10px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.3);
            transition: all 0.2s ease;
        }
        .btn-modal-trigger:hover {
            background: #ff0055;
            border-color: #ff0055;
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(255, 0, 85, 0.3);
        }

        /* Estilos de los Modales (Ventanas Emergentes) */
        .modal-overlay {
            position: fixed;
            top: 0; left: 0; width: 100%; height: 100%;
            background: rgba(0, 0, 0, 0.75);
            backdrop-filter: blur(5px);
            z-index: 3000;
            display: none;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        .modal-overlay.active {
            display: flex;
        }
        .modal-content {
            background: #161622;
            border: 1px solid rgba(255, 0, 85, 0.4);
            border-radius: 14px;
            width: 100%;
            max-width: 600px;
            max-height: 90vh;
            overflow-y: auto;
            padding: 30px;
            box-shadow: 0 15px 40px rgba(0,0,0,0.8);
            position: relative;
            animation: modalFadeIn 0.3s ease;
        }
        @keyframes modalFadeIn {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .modal-close {
            position: absolute;
            top: 20px; right: 20px;
            background: none; border: none;
            color: #aaa; font-size: 1.5rem;
            cursor: pointer;
            transition: color 0.2s;
        }
        .modal-close:hover { color: #ff0055; }
        .modal-content h3 {
            margin-top: 0;
            color: #ff0055;
            font-size: 1.25rem;
            border-bottom: 1px solid #262638;
            padding-bottom: 12px;
            margin-bottom: 20px;
        }

        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 6px; color: #ccc; font-size: 0.85rem; font-weight: 600; }
        .form-control { width: 100%; padding: 10px 12px; background: #0f0f17; border: 1px solid #323248; border-radius: 8px; color: #fff; font-size: 0.9rem; outline: none; }
        .form-control:focus { border-color: #ff0055; }

        .btn-accion { background: #ff0055; color: #fff; border: none; padding: 12px; border-radius: 8px; font-weight: bold; cursor: pointer; width: 100%; font-size: 0.95rem; transition: background 0.2s; margin-top: 10px; }
        .btn-accion:hover { background: #d60047; }

        .search-center-card { background: #161622; border: 1px solid rgba(255,0,85,0.4); border-radius: 12px; padding: 25px; margin-bottom: 30px; box-shadow: 0 10px 30px rgba(255,0,85,0.1); }
        .search-flex { display: flex; gap: 15px; }
        @media(max-width: 768px) { .search-flex { flex-direction: column; } }

        .table-container { background: #161622; border: 1px solid #262638; border-radius: 12px; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.5); margin-bottom: 30px; }
        table { width: 100%; border-collapse: collapse; text-align: left; font-size: 0.86rem; }
        th { background: #1f1f2e; color: #ff80aa; padding: 14px; font-weight: 600; border-bottom: 1px solid #262638; }
        td { padding: 14px; border-bottom: 1px solid #222233; color: #d0d0d8; }
        tr:hover { background: #1a1a29; }

        .alerta-exito { background: rgba(0,255,204,0.12); border: 1px solid #00ffcc; color: #80ffdf; padding: 12px 15px; border-radius: 8px; margin-bottom: 20px; }
        .alerta-error { background: rgba(255,0,85,0.12); border: 1px solid #ff0055; color: #ff80aa; padding: 12px 15px; border-radius: 8px; margin-bottom: 20px; }
        .badge { background: rgba(255,0,85,0.15); color: #ff80aa; border: 1px solid #ff0055; padding: 4px 10px; border-radius: 20px; font-size: 0.78rem; font-weight: bold; display: inline-block; }
        .badge-disp { background: rgba(0,255,204,0.2); color: #00ffcc; border: 1px solid #00ffcc; padding: 4px 10px; border-radius: 20px; font-size: 0.78rem; font-weight: bold; }
        .badge-vend { background: rgba(255,0,85,0.2); color: #ff0055; border: 1px solid #ff0055; padding: 4px 10px; border-radius: 20px; font-size: 0.78rem; font-weight: bold; }
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
            <li><a href="gestion_tickets.php" class="activo" style="color: #ff0055;">🎟️ Gestión de Tickets</a></li>
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
        <h2 class="seccion-titulo">Módulo Inteligente de Eventos, Tickets y Centro de Búsqueda</h2>
    </div>

    <?php if ($mensaje): ?>
        <div class="alerta-exito"><?php echo htmlspecialchars($mensaje); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alerta-error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <!-- BOTONES PRINCIPALES QUE ABREN LOS MODALES -->
    <div class="action-buttons-container">
        <button class="btn-modal-trigger" onclick="openModal('modalEvento')">
            🎉 Programar Nuevo Evento
        </button>
        <button class="btn-modal-trigger" onclick="openModal('modalTipoTicket')">
            🎟️ Registrar Tipo de Ticket
        </button>
        <button class="btn-modal-trigger" onclick="openModal('modalPrecioTicket')">
            💲 Registrar Tarifa / Precio de Ticket
        </button>
    </div>

    <!-- ---------------- MODALES FLOTANTES ---------------- -->

    <!-- MODAL 1: Programar Nuevo Evento -->
    <div class="modal-overlay" id="modalEvento">
        <div class="modal-content">
            <button class="modal-close" onclick="closeModal('modalEvento')">&times;</button>
            <h3>🎉 Programar Nuevo Evento</h3>
            <form method="POST" action="gestion_tickets.php" enctype="multipart/form-data">
                <input type="hidden" name="accion" value="crear_evento">
                <div class="form-group">
                    <label>Sede:</label>
                    <select name="CodSede" class="form-control" required>
                        <?php foreach($sedes as $s): ?>
                            <option value="<?php echo htmlspecialchars($s['CodSede']); ?>"><?php echo htmlspecialchars($s['Sede']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Tipo de Evento:</label>
                    <input type="text" name="CodTipo_Evento_Input" list="lista_tipos_evento" class="form-control" required placeholder="Ej. Concierto, Fiesta..." autocomplete="off">
                    <datalist id="lista_tipos_evento">
                        <?php foreach($tipos_evento as $te): ?>
                            <option value="<?php echo htmlspecialchars($te['Tipo_Evento']); ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>
                <div class="form-group">
                    <label>Nombre del Evento:</label>
                    <input type="text" name="Nombre_Evento" class="form-control" required placeholder="Fiesta Aniversario">
                </div>
                <div class="form-group">
                    <label>Descripción:</label>
                    <input type="text" name="Descripcion_Evento" class="form-control" required placeholder="Concierto en vivo">
                </div>
                <div class="form-group">
                    <label>Imagen / Afiche del Evento:</label>
                    <input type="file" name="img" class="form-control" accept="image/png, image/jpeg, image/webp">
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
                    <div class="form-group">
                        <label>Fec. Inicio:</label>
                        <input type="date" name="Fec_Inicio_Evento" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Fec. Término:</label>
                        <input type="date" name="Fec_Termino_Evento" class="form-control" required>
                    </div>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 10px;">
                    <div class="form-group">
                        <label>Hora Inicio:</label>
                        <input type="text" name="Hora_Inicio" class="form-control" value="22:00:00" required>
                    </div>
                    <div class="form-group">
                        <label>Hora Fin:</label>
                        <input type="text" name="Hora_Fin" class="form-control" value="05:00:00" required>
                    </div>
                    <div class="form-group">
                        <label>Aforo:</label>
                        <input type="number" name="Aforo" class="form-control" value="500" required>
                    </div>
                </div>
                <button type="submit" class="btn-accion">Registrar Evento</button>
            </form>
        </div>
    </div>

    <!-- MODAL 2: Registrar Tipo de Ticket -->
    <div class="modal-overlay" id="modalTipoTicket">
        <div class="modal-content">
            <button class="modal-close" onclick="closeModal('modalTipoTicket')">&times;</button>
            <h3>🎟️ Registrar Tipo de Ticket</h3>
            <form method="POST" action="gestion_tickets.php">
                <input type="hidden" name="accion" value="crear_tipo_ticket">
                <div class="form-group">
                    <label>Nombre del Tipo de Ticket:</label>
                    <input type="text" name="Tipo_Ticket_Input" list="lista_tipos_ticket" class="form-control" required placeholder="Ej. VIP Box, General..." autocomplete="off">
                    <datalist id="lista_tipos_ticket">
                        <?php foreach($tipos_ticket as $tt): ?>
                            <option value="<?php echo htmlspecialchars($tt['Tipo_Ticket']); ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>
                <div class="form-group">
                    <label>Descripción / Beneficios:</label>
                    <input type="text" name="Descripcion_Ticket" class="form-control" placeholder="Acceso preferencial + barra libre">
                </div>
                <button type="submit" class="btn-accion">Guardar Tipo de Ticket</button>
            </form>
        </div>
    </div>

    <!-- MODAL 3: Registrar Tarifa / Precio de Ticket -->
    <div class="modal-overlay" id="modalPrecioTicket">
        <div class="modal-content">
            <button class="modal-close" onclick="closeModal('modalPrecioTicket')">&times;</button>
            <h3>💲 Registrar Tarifa / Precio de Ticket</h3>
            <form method="POST" action="gestion_tickets.php">
                <input type="hidden" name="accion" value="crear_precio_ticket">
                <div class="form-group">
                    <label>Seleccionar Tipo de Ticket:</label>
                    <select name="CodTipoTicket" class="form-control" required>
                        <option value="">-- Seleccione Tipo --</option>
                        <?php foreach($tipos_ticket as $tt): ?>
                            <option value="<?php echo htmlspecialchars($tt['CodTipoTicket']); ?>"><?php echo htmlspecialchars($tt['Tipo_Ticket']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Precio (S/.):</label>
                    <input type="number" step="0.01" name="Precio" class="form-control" required placeholder="Ej. 50.00">
                </div>
                <div class="form-group">
                    <label>Tipo de Venta:</label>
                    <select name="Tipo_Venta" class="form-control" required>
                        <option value="Presencial">Presencial</option>
                        <option value="Web">Web / Online</option>
                    </select>
                </div>
                <button type="submit" class="btn-accion">Guardar Precio</button>
            </form>
        </div>
    </div>

    <!-- ---------------- FIN DE MODALES ---------------- -->

    <!-- 4. EMISIÓN MASIVA DE TICKETS EN LOTE -->
    <div class="card-panel" style="background: #161622; border: 1px solid #262638; border-radius: 12px; padding: 25px; box-shadow: 0 10px 30px rgba(0,0,0,0.5); margin-bottom: 30px;">
        <h3 style="margin-top: 0; color: #ff0055; font-size: 1.1rem; border-bottom: 1px solid #262638; padding-bottom: 10px; margin-bottom: 20px;">🎫 Generación Masiva de Tickets en Lote (Código Automático)</h3>
        <form method="POST" action="gestion_tickets.php">
            <input type="hidden" name="accion" value="generar_lote_tickets">
            <div style="display: grid; grid-template-columns: 2fr 2fr 1fr 1fr; gap: 15px; align-items: flex-end;">
                <div class="form-group" style="margin-bottom:0;">
                    <label>Seleccionar Evento:</label>
                    <select name="NroEvento" class="form-control" required>
                        <option value="">-- Seleccione Evento --</option>
                        <?php foreach($eventos as $ev): ?>
                            <option value="<?php echo htmlspecialchars($ev['NroEvento']); ?>"><?php echo htmlspecialchars($ev['Nombre_Evento'] . ' (' . $ev['Sede'] . ')'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label>Tipo y Tarifa Asociada:</label>
                    <select name="IDPrecio" class="form-control" required>
                        <option value="">-- Seleccione Tipo y Tarifa --</option>
                        <?php foreach($precios_tickets as $pt): ?>
                            <option value="<?php echo htmlspecialchars($pt['IDPrecio']); ?>">
                                [Tipo: <?php echo htmlspecialchars($pt['Tipo_Ticket']); ?>] - S/. <?php echo number_format($pt['Precio'], 2); ?> (<?php echo htmlspecialchars($pt['Tipo_Venta']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label>Cantidad a Generar:</label>
                    <input type="number" name="Cantidad_Tickets" class="form-control" min="1" max="500" value="2" required placeholder="Ej. 2">
                </div>
                <div>
                    <button type="submit" class="btn-accion" style="margin-top:0;">Generar Lote</button>
                </div>
            </div>
        </form>
    </div>

    <!-- 5. CENTRO DE BÚSQUEDA PREDICTIVA -->
    <div class="search-center-card">
        <h3 style="margin-top:0; color: #ff0055; font-size: 1.1rem; margin-bottom: 15px;">🔍 Centro de Búsqueda Predictiva y Verificación de Tickets</h3>
        <form method="GET" action="gestion_tickets.php">
            <div class="search-flex">
                <input type="text" name="buscar_ticket" list="listaSugerenciasTickets" class="form-control" placeholder="Escribe o selecciona código de ticket..." value="<?php echo htmlspecialchars($_GET['buscar_ticket'] ?? ''); ?>" autocomplete="off" required>
                <datalist id="listaSugerenciasTickets">
                    <?php foreach ($sugerencias_tickets as $st): ?>
                        <option value="<?php echo htmlspecialchars(trim($st['CodTicket'])); ?>">Ticket: <?php echo htmlspecialchars(trim($st['CodTicket'])); ?></option>
                    <?php endforeach; ?>
                </datalist>
                <button type="submit" class="btn-accion" style="width: 220px; margin-top:0;">Buscar Estado</button>
            </div>
        </form>

        <?php if (isset($_GET['buscar_ticket'])): ?>
            <div style="margin-top: 20px;">
                <h4 style="color: #fff; font-size: 0.95rem; margin-bottom: 10px;">Resultados de la Búsqueda:</h4>
                <div class="table-container" style="margin-bottom:0;">
                    <table>
                        <thead>
                            <tr><th>Código Ticket</th><th>Evento</th><th>Tipo</th><th>Precio</th><th>Estado</th><th>Titular / Cliente Asignado</th></tr>
                        </thead>
                        <tbody>
                            <?php if (empty($resultado_busqueda)): ?>
                                <tr><td colspan="6" style="text-align: center; color: #888;">No se encontró información registrada con ese criterio.</td></tr>
                            <?php else: foreach($resultado_busqueda as $rb): ?>
                                <tr>
                                    <td><span class="badge"><?php echo htmlspecialchars($rb['CodTicket']); ?></span></td>
                                    <td><?php echo htmlspecialchars($rb['Nombre_Evento']); ?></td>
                                    <td><?php echo htmlspecialchars($rb['Tipo_Ticket']); ?></td>
                                    <td>S/. <?php echo number_format($rb['Precio'], 2); ?></td>
                                    <td>
                                        <?php if(empty($rb['Nom_Cliente'])): ?>
                                            <span class="badge-disp">Disponible</span>
                                        <?php else: ?>
                                            <span class="badge-vend">Vendido / Asignado</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if(!empty($rb['Nom_Cliente'])): ?>
                                            <strong><?php echo htmlspecialchars($rb['Nom_Cliente'] . ' ' . $rb['Ape_Cliente']); ?></strong> (Venta: <?php echo htmlspecialchars($rb['Nro_Venta_Ticket'] ?? 'N/D'); ?>)
                                        <?php else: ?>
                                            <span style="color: #888; font-style: italic;">Sin asignar (Disponible en almacén)</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- 6. TABLAS DE RESUMEN -->
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 25px;">
        <div class="table-container">
            <h3 style="padding: 15px; margin:0; background: #1f1f2e; color: #ff80aa; font-size: 0.95rem;">Tipos de Tickets Registrados</h3>
            <table>
                <thead>
                    <tr><th>Cód</th><th>Nombre</th><th>Descripción</th><th>Estado</th></tr>
                </thead>
                <tbody>
                    <?php foreach($tipos_ticket as $tt): ?>
                    <tr>
                        <td><span class="badge"><?php echo htmlspecialchars($tt['CodTipoTicket']); ?></span></td>
                        <td><strong><?php echo htmlspecialchars($tt['Tipo_Ticket']); ?></strong></td>
                        <td><?php echo htmlspecialchars($tt['Descripcion_Ticket']); ?></td>
                        <td><?php echo htmlspecialchars($tt['Estado_Ticket']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="table-container">
            <h3 style="padding: 15px; margin:0; background: #1f1f2e; color: #ff80aa; font-size: 0.95rem;">Últimos Tickets Emitidos en el Sistema</h3>
            <table>
                <thead>
                    <tr><th>Cód. Ticket</th><th>Evento</th><th>Precio</th><th>Estado</th></tr>
                </thead>
                <tbody>
                    <?php if(empty($tickets_registrados)): ?>
                    <tr><td colspan="4" style="text-align: center; color: #888;">No hay tickets emitidos todavía.</td></tr>
                    <?php else: foreach($tickets_registrados as $tr): ?>
                    <tr>
                        <td><span class="badge"><?php echo htmlspecialchars($tr['CodTicket']); ?></span></td>
                        <td><?php echo htmlspecialchars($tr['Nombre_Evento']); ?></td>
                        <td>S/. <?php echo number_format($tr['Precio'], 2); ?></td>
                        <td style="color: #00ffcc; font-weight: bold;"><?php echo htmlspecialchars($tr['Estado_Ticket']); ?></td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
    function toggleMenu() {
        document.getElementById('sidebar').classList.toggle('active');
        document.getElementById('overlay').classList.toggle('active');
    }

    // Funciones para controlar los modales
    function openModal(modalId) {
        document.getElementById(modalId).classList.add('active');
    }

    function closeModal(modalId) {
        document.getElementById(modalId).classList.remove('active');
    }

    // Cerrar modal haciendo clic fuera de la ventana
    window.onclick = function(event) {
        if (event.target.classList.contains('modal-overlay')) {
            event.target.classList.remove('active');
        }
    }
</script>

</body>
</html>