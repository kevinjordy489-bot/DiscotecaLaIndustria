<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
$cliente_activo = $_SESSION['cliente_nombre'] ?? null;
$id_cliente     = $_SESSION['cliente_id'] ?? null; 

require_once '../config/conexion.php';

$cod_producto = isset($_REQUEST['cod_producto']) ? trim($_REQUEST['cod_producto']) : null;

$detalle_bebida = null;
$mesas_disponibles = [];
$tipos_pedido = [];
$error_msj = "";
$exito_msj = "";

// 1. Obtener el detalle del producto desde Tb_Producto
if ($cod_producto) {
    try {
        $sql_info = "SELECT CodProducto, Producto AS Nombre_Bebida, Descripcion, Prec_Venta AS Precio_Bebida, Stock_Marca AS Stock_Bebida 
                     FROM Tb_Producto 
                     WHERE CodProducto = ? AND Estado_Producto = 'Activo'";
        
        $stmt_info = $conn->prepare($sql_info);
        $stmt_info->execute([$cod_producto]);
        $detalle_bebida = $stmt_info->fetch(PDO::FETCH_ASSOC);

        if (!$detalle_bebida) {
            $error_msj = "El producto seleccionado no existe o no se encuentra activo.";
        }

        // Obtener mesas activas y disponibles de la sede 'SED01' desde Tb_Mesa
        $sql_mesas = "SELECT NroMesa, Descripcion_Mesa FROM Tb_Mesa WHERE CodSede = 'SED01' AND Estado_Mesa = 'Disponible'";
        $stmt_mesas = $conn->query($sql_mesas);
        $mesas_disponibles = $stmt_mesas->fetchAll(PDO::FETCH_ASSOC);

        // Obtener tipos de pedido desde Tb_Tipo_Pedido
        $sql_tipos = "SELECT CodTipo_Pedido, Tipo_Pedido FROM Tb_Tipo_Pedido";
        $stmt_tipos = $conn->query($sql_tipos);
        $tipos_pedido = $stmt_tipos->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        $error_msj = "Error al consultar la base de datos: " . $e->getMessage();
    }
} else {
    $error_msj = "No se especificó ninguna bebida para solicitar.";
}

// 2. Procesar el pedido con los datos seleccionados por el cliente
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirmar_pedido']) && $detalle_bebida && !$error_msj) {
    if (!$id_cliente) {
        $error_msj = "Debes iniciar sesión como cliente para solicitar bebidas.";
    } else {
        $cantidad     = isset($_POST['cantidad']) ? (int)$_POST['cantidad'] : 0;
        $metodo_pago  = isset($_POST['metodo_pago']) ? trim($_POST['metodo_pago']) : 'Efectivo';
        $nro_mesa     = isset($_POST['nro_mesa']) ? trim($_POST['nro_mesa']) : '';
        $cod_tipo_ped = isset($_POST['cod_tipo_pedido']) ? trim($_POST['cod_tipo_pedido']) : '';
        $observacion  = isset($_POST['observacion']) ? trim($_POST['observacion']) : 'Solicitado desde la web';
        $stock_actual = (int)$detalle_bebida['Stock_Bebida'];

        // Validaciones
        if ($cantidad <= 0 || $cantidad > $stock_actual) {
            $error_msj = "La cantidad ingresada supera el stock disponible en almacén ($stock_actual unidades).";
        } elseif (empty($nro_mesa)) {
            $error_msj = "Por favor, seleccione una mesa para su consumo.";
        } elseif (empty($cod_tipo_ped)) {
            $error_msj = "Por favor, seleccione un tipo de pedido válido.";
        } else {
            try {
                // Iniciar transacción en SQL Server
                $conn->beginTransaction();

                $total_pagado = $cantidad * (float)$detalle_bebida['Precio_Bebida'];

                // A. Generar número de pedido único adaptado a char(12)
                $nro_pedido = 'PED' . date('ymdHi');
                if (strlen($nro_pedido) > 12) {
                    $nro_pedido = substr($nro_pedido, 0, 12);
                }

                // B. Insertar cabecera en Tb_Pedido usando SED01
                $sql_pedido = "INSERT INTO Tb_Pedido (NroPedido, CodSede, CodCliente, CodEmpleado, Fec_Pedido, CodTipo_Pedido, NroMesa, Total, Estado_Pedido, Obs_Estado_Pedido)
                               VALUES (?, 'SED01', ?, 'EMP01', GETDATE(), ?, ?, ?, 'Pendiente', ?)";
                $stmt_ped = $conn->prepare($sql_pedido);
                $stmt_ped->execute([
                    $nro_pedido, 
                    $id_cliente, 
                    $cod_tipo_ped,
                    $nro_mesa,
                    $total_pagado, 
                    "$observacion (Pago: $metodo_pago)"
                ]);

                // C. Insertar detalle en Tb_Detalle_Pedido usando SED01
                $sql_detalle = "INSERT INTO Tb_Detalle_Pedido (NroPedido, CodSede, CodProducto, Prec_Venta, Cantidad, Importe)
                                VALUES (?, 'SED01', ?, ?, ?, ?)";
                $stmt_det = $conn->prepare($sql_detalle);
                $stmt_det->execute([
                    $nro_pedido,
                    $cod_producto,
                    (float)$detalle_bebida['Precio_Bebida'],
                    $cantidad,
                    $total_pagado
                ]);

                // D. Descontar stock en Tb_Producto
                $sql_stock = "UPDATE Tb_Producto SET Stock_Marca = Stock_Marca - ? WHERE CodProducto = ?";
                $stmt_stk = $conn->prepare($sql_stock);
                $stmt_stk->execute([$cantidad, $cod_producto]);

                // Confirmar transacción
                $conn->commit();

                $exito_msj = "¡Pedido registrado con éxito! Nro. de Pedido: <strong>$nro_pedido</strong>. Mesa: <strong>$nro_mesa</strong>. Total: S/ " . number_format($total_pagado, 2) . ".";
                $detalle_bebida['Stock_Bebida'] -= $cantidad;

            } catch (PDOException $e) {
                if ($conn->inTransaction()) {
                    $conn->rollBack();
                }
                $error_msj = "Error al procesar el pedido en la base de datos: " . $e->getMessage();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Solicitar Bebida y Mesa - La Industria Surco</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            background-color: #0b0b10;
            color: #f1f1f5;
            margin: 0;
            padding: 0;
        }
        nav {
            background: rgba(16, 16, 24, 0.95);
            border-bottom: 1px solid rgba(255, 0, 85, 0.2);
            padding: 16px 40px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .logo { font-weight: 800; font-size: 1.25rem; color: #ffffff; text-decoration: none; letter-spacing: 1px; }
        .logo span { color: #ff0055; }
        .nav-right { display: flex; align-items: center; gap: 15px; }
        .btn-login-top {
            background: linear-gradient(135deg, #ff0055 0%, #b3003c 100%);
            color: #ffffff; padding: 10px 22px; border-radius: 6px;
            text-decoration: none; font-weight: 600; font-size: 0.9rem;
        }
        .user-greeting { font-size: 0.9rem; color: #ccc; }
        .user-greeting span { color: #00ffcc; font-weight: bold; }

        .container { max-width: 750px; margin: 40px auto; padding: 0 25px; }
        .btn-volver {
            display: inline-block; color: #ff6699; text-decoration: none;
            font-weight: 600; margin-bottom: 20px; font-size: 0.95rem;
        }
        .btn-volver:hover { text-decoration: underline; }

        .checkout-card {
            background: #161622;
            border: 1px solid #262638;
            border-radius: 14px;
            padding: 35px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.6);
        }
        .checkout-card h1 {
            margin: 0 0 20px 0; font-size: 1.7rem; color: #fff;
            border-bottom: 1px solid #262638; padding-bottom: 15px;
        }
        .checkout-card h1 span { color: #ff0055; }

        .resumen-tabla { width: 100%; border-collapse: collapse; margin-bottom: 25px; }
        .resumen-tabla td { padding: 12px 0; border-bottom: 1px solid #222232; font-size: 0.96rem; }
        .resumen-tabla td.label { color: #9e9eb3; width: 40%; }
        .resumen-tabla td.valor { color: #fff; font-weight: 600; text-align: right; }

        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; margin-bottom: 8px; color: #ccc; font-weight: 600; font-size: 0.92rem; }
        .form-control {
            width: 100%; padding: 12px 15px; background: #0f0f17;
            border: 1px solid #323248; border-radius: 8px; color: #fff;
            font-size: 1rem; margin-top: 4px; outline: none;
        }
        .form-control:focus { border-color: #ff0055; box-shadow: 0 0 8px rgba(255, 0, 85, 0.25); }

        .metodos-pago {
            display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 25px;
        }
        .metodo-card {
            background: #0f0f17; border: 2px solid #323248; border-radius: 10px;
            padding: 18px 12px; cursor: pointer; text-align: center;
            transition: all 0.25s ease;
        }
        .metodo-card input[type="radio"] { display: none; }
        .metodo-card .icon { font-size: 1.6rem; display: block; margin-bottom: 6px; }
        .metodo-card .titulo-metodo { color: #b0b0c0; font-weight: 600; font-size: 0.9rem; }
        .metodo-card.active { border-color: #ff0055; background: rgba(255, 0, 85, 0.08); }
        .metodo-card.active .titulo-metodo { color: #fff; }

        .panel-pago {
            display: none; background: #101018; border: 1px solid #2b2b3d;
            border-radius: 10px; padding: 22px; margin-bottom: 25px;
        }
        .panel-pago h3 { margin-top: 0; color: #00ffcc; font-size: 1.1rem; margin-bottom: 15px; }
        .qr-box {
            background: #fff; color: #000; padding: 15px; border-radius: 8px;
            width: 140px; margin: 10px auto; text-align: center; font-weight: bold; font-size: 0.85rem;
        }

        .total-box {
            background: #101018; border: 1px solid rgba(0, 255, 204, 0.25);
            border-radius: 10px; padding: 18px 22px;
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 25px;
        }
        .total-box span { font-size: 1.1rem; color: #b0b0c0; }
        .total-box strong { font-size: 1.8rem; color: #00ffcc; }

        .acciones-flex { display: flex; gap: 15px; }
        .btn-confirmar {
            flex: 2; padding: 15px; border: none; border-radius: 8px;
            background: linear-gradient(135deg, #ff0055 0%, #b3003c 100%);
            color: #fff; font-size: 1.05rem; font-weight: bold; cursor: pointer;
            box-shadow: 0 5px 20px rgba(255, 0, 85, 0.35); text-align: center;
        }
        .btn-confirmar:hover { opacity: 0.9; }
        
        .btn-cancelar-form {
            flex: 1; padding: 15px; border: 1px solid #383852; border-radius: 8px;
            background: #1f1f2e; color: #ff80aa; font-size: 1.05rem; font-weight: bold;
            text-align: center; text-decoration: none; display: flex; align-items: center; justify-content: center;
        }
        .btn-cancelar-form:hover { background: #2a2a3d; }

        .alerta-error {
            background: rgba(255, 0, 85, 0.12); border: 1px solid #ff0055;
            color: #ff80aa; padding: 16px; border-radius: 8px; margin-bottom: 20px;
        }
        .alerta-exito {
            background: rgba(0, 255, 204, 0.1); border: 1px solid #00ffcc;
            color: #00ffcc; padding: 20px; border-radius: 8px; margin-bottom: 20px;
            line-height: 1.6;
        }
        .btn-secundario {
            display: inline-block; margin-top: 15px; background: #252538; color: #fff;
            padding: 10px 18px; border-radius: 6px; text-decoration: none; font-weight: 600;
            margin-right: 10px;
        }
        .btn-secundario:hover { background: #ff0055; color: #fff; }
    </style>
</head>
<body>

<nav>
    <a href="catalogo_bebidas.php" class="logo">LA INDUSTRIA <span>SURCO</span></a>
    <div class="nav-right">
        <?php if ($cliente_activo): ?>
            <div class="user-greeting">Hola, <span><?php echo htmlspecialchars($cliente_activo); ?></span></div>
            <a href="cerrar_sesion.php" class="btn-login-top" style="background: #252535;">Cerrar Sesión</a>
        <?php else: ?>
            <a href="login_cliente.php" class="btn-login-top">Iniciar Sesión</a>
        <?php endif; ?>
    </div>
</nav>

<div class="container">
    <a href="catalogo_bebidas.php" class="btn-volver">&larr; Volver al Catálogo de Bebidas</a>

    <div class="checkout-card">
        <h1>Detalles de tu <span>Pedido y Mesa</span></h1>

        <?php if ($error_msj): ?>
            <div class="alerta-error">
                <?php echo htmlspecialchars($error_msj); ?>
                <?php if (!$cliente_activo): ?>
                    <br><a href="login_cliente.php" class="btn-secundario">Ir a Iniciar Sesión</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($exito_msj): ?>
            <div class="alerta-exito">
                <strong><?php echo $exito_msj; ?></strong><br>
                <a href="mis_pedidos.php" class="btn-secundario">📦 Ver Mis Pedidos Ahora</a>
                <a href="catalogo_bebidas.php" class="btn-secundario" style="background: #1f1f2e;">🍹 Seguir Comprando</a>
            </div>
        <?php endif; ?>

        <?php if ($detalle_bebida && !$exito_msj): ?>
            <?php 
                $stock_disponible = (int)$detalle_bebida['Stock_Bebida'];
                $cantidad_pedida = isset($_POST['cantidad']) ? max(1, min((int)$_POST['cantidad'], $stock_disponible)) : 1;
                $monto_total = $cantidad_pedida * (float)$detalle_bebida['Precio_Bebida'];
            ?>

            <table class="resumen-tabla">
                <tr>
                    <td class="label">Producto:</td>
                    <td class="valor"><?php echo htmlspecialchars($detalle_bebida['Nombre_Bebida']); ?></td>
                </tr>
                <tr>
                    <td class="label">Precio Unitario:</td>
                    <td class="valor">S/ <?php echo number_format((float)$detalle_bebida['Precio_Bebida'], 2); ?></td>
                </tr>
                <tr>
                    <td class="label">Stock Disponible:</td>
                    <td class="valor" style="color: #00ffcc;"><?php echo $stock_disponible; ?> unidades</td>
                </tr>
            </table>

            <?php if ($stock_disponible > 0): ?>
                <form method="POST" action="pedir_bebidas.php?cod_producto=<?php echo urlencode($cod_producto); ?>">
                    
                    <!-- SELECCIONAR MESA -->
                    <div class="form-group">
                        <label>Selecciona tu Mesa / Box / Barra (SED01):</label>
                        <select name="nro_mesa" class="form-control" required>
                            <option value="">-- Seleccione una Ubicación --</option>
                            <?php foreach ($mesas_disponibles as $mesa): ?>
                                <option value="<?php echo htmlspecialchars($mesa['NroMesa']); ?>">
                                    <?php echo htmlspecialchars($mesa['NroMesa'] . ' - ' . $mesa['Descripcion_Mesa']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- SELECCIONAR TIPO DE PEDIDO -->
                    <div class="form-group">
                        <label>Tipo de Pedido:</label>
                        <select name="cod_tipo_pedido" class="form-control" required>
                            <?php foreach ($tipos_pedido as $tipo): ?>
                                <option value="<?php echo htmlspecialchars($tipo['CodTipo_Pedido']); ?>">
                                    <?php echo htmlspecialchars($tipo['Tipo_Pedido']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Cantidad a solicitar:</label>
                        <input type="number" id="cantidad_input" name="cantidad" class="form-control" value="<?php echo $cantidad_pedida; ?>" min="1" max="<?php echo $stock_disponible; ?>" oninput="actualizarTotal()">
                    </div>

                    <div class="form-group">
                        <label>Observaciones / Notas para el mozo o barra:</label>
                        <input type="text" name="observacion" class="form-control" placeholder="Ej: Sin hielo, vaso extra, etc.">
                    </div>

                    <div class="form-group">
                        <label>Selecciona tu Método de Pago:</label>
                        <div class="metodos-pago">
                            <div class="metodo-card active" id="card_efectivo" onclick="seleccionarMetodo('Efectivo')">
                                <input type="radio" id="pago_efectivo" name="metodo_pago" value="Efectivo" checked>
                                <span class="icon">💵</span>
                                <span class="titulo-metodo">Efectivo</span>
                            </div>
                            <div class="metodo-card" id="card_tarjeta" onclick="seleccionarMetodo('Tarjeta')">
                                <input type="radio" id="pago_tarjeta" name="metodo_pago" value="Tarjeta">
                                <span class="icon">💳</span>
                                <span class="titulo-metodo">Tarjeta</span>
                            </div>
                            <div class="metodo-card" id="card_yape" onclick="seleccionarMetodo('Yape / Plin')">
                                <input type="radio" id="pago_yape" name="metodo_pago" value="Yape / Plin">
                                <span class="icon">📱</span>
                                <span class="titulo-metodo">Yape / Plin</span>
                            </div>
                        </div>
                    </div>

                    <!-- PANEL DE TARJETA -->
                    <div id="panel_tarjeta" class="panel-pago">
                        <h3>💳 Ingrese los datos de su Tarjeta</h3>
                        <div class="form-group">
                            <label>Número de Tarjeta</label>
                            <input type="text" class="form-control" placeholder="4532 •••• •••• 8920" maxlength="19">
                        </div>
                    </div>

                    <!-- PANEL DE YAPE / PLIN -->
                    <div id="panel_yape" class="panel-pago">
                        <h3>📱 Pago con Billetera Digital (Yape / Plin)</h3>
                        <p style="color: #b0b0c0; font-size: 0.9rem; margin-top:0;">Transfiere al número de la discoteca:</p>
                        <div style="font-size: 1.3rem; color: #00ffcc; font-weight: bold; text-align: center; margin: 8px 0;">987 654 321</div>
                        <div class="qr-box">[ QR YAPE / PLIN ]</div>
                    </div>

                    <div class="total-box">
                        <span>Total a Pagar:</span>
                        <strong id="total_pagar">S/ <?php echo number_format($monto_total, 2); ?></strong>
                    </div>

                    <div class="acciones-flex">
                        <button type="submit" name="confirmar_pedido" value="1" class="btn-confirmar" id="btn_texto_pagar">Confirmar y Pagar S/ <?php echo number_format($monto_total, 2); ?></button>
                        <a href="catalogo_bebidas.php" class="btn-cancelar-form">Cancelar</a>
                    </div>
                </form>

                <script>
                    const precioUnitario = <?php echo (float)$detalle_bebida['Precio_Bebida']; ?>;
                    const stockMaximo = <?php echo $stock_disponible; ?>;

                    function actualizarTotal() {
                        let inputElem = document.getElementById('cantidad_input');
                        let cant = parseInt(inputElem.value) || 1;

                        if (cant > stockMaximo) { cant = stockMaximo; inputElem.value = stockMaximo; }
                        if (cant < 1) { cant = 1; }

                        let total = cant * precioUnitario;
                        let totalFormateado = 'S/ ' + total.toFixed(2);
                        document.getElementById('total_pagar').innerText = totalFormateado;
                        document.getElementById('btn_texto_pagar').innerText = 'Confirmar y Pagar ' + totalFormateado;
                    }

                    function seleccionarMetodo(metodo) {
                        document.getElementById('panel_tarjeta').style.display = 'none';
                        document.getElementById('panel_yape').style.display = 'none';

                        document.getElementById('card_efectivo').classList.remove('active');
                        document.getElementById('card_tarjeta').classList.remove('active');
                        document.getElementById('card_yape').classList.remove('active');

                        if (metodo === 'Tarjeta') {
                            document.getElementById('pago_tarjeta').checked = true;
                            document.getElementById('card_tarjeta').classList.add('active');
                            document.getElementById('panel_tarjeta').style.display = 'block';
                        } else if (metodo === 'Yape / Plin') {
                            document.getElementById('pago_yape').checked = true;
                            document.getElementById('card_yape').classList.add('active');
                            document.getElementById('panel_yape').style.display = 'block';
                        } else if (metodo === 'Efectivo') {
                            document.getElementById('pago_efectivo').checked = true;
                            document.getElementById('card_efectivo').classList.add('active');
                        }
                    }
                </script>
            <?php else: ?>
                <div class="alerta-error">Producto agotado temporalmente. No hay stock disponible.</div>
                <a href="catalogo_bebidas.php" class="btn-secundario">Regresar al Catálogo</a>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

</body>
</html>