<?php
// permisos.php - Sistema centralizado de control de acceso y seguridad por URL

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. Seguridad base: Verificar que haya iniciado sesión como empleado
if (!isset($_SESSION['rol']) || $_SESSION['rol'] !== 'empleado' || !isset($_SESSION['empleado_perfil'])) {
    header("Location: login_trabajador.php");
    exit;
}

// 2. Definición de permisos por cada perfil operativo del sistema
function obtenerPaginasPermitidas($perfil) {
    $mapa_permisos = [
        'Administrador' => [
            'panel_control.php', 
            'gestion_trabajadores.php', 
            'nuevo_credencial.php', 
            'nuevo_trabajador.php',
            'consultar_proveedores.php', 
            'consultar_sedes.php', 
            'registrar_orden_compra_producto.php',
            'generar_orden_compra_insumo.php',
            'aprobar_ordenes_compra.php',
            'registrar_recepcion_compra.php',
            'consultar_ordenes_compra_producto.php',
            'consultar_detalles_compras.php',
            'gestion_mesas.php',
            'gestion_productos.php',
            'gestion_tickets.php',
            'verificar_stock.php',
            'kardex_productos.php',
            'kardex_insumos.php',
            'reporte_ingresos_pagos.php',
            'reporte_inventario_productos.php',
            'reporte_inventario_insumos.php',
            'reporte_ventas_bebidas.php',
            'reporte_ventas_tickets.php'
        ],
        'Almacen' => [
            'panel_control.php',
            'verificar_stock.php',
            'kardex_productos.php',
            'kardex_insumos.php',
            'registrar_recepcion_compra.php',
            'consultar_detalles_compras.php'
        ],
        'Bartender' => [
            'panel_control.php',
            'verificar_stock.php'
        ],
        'Vendedor entr' => [
            'panel_control.php',
            'gestion_tickets.php',
            'reporte_ventas_tickets.php'
        ],
        'Vendedor pro' => [
            'panel_control.php',
            'gestion_productos.php',
            'reporte_ventas_bebidas.php'
        ],
        'Seguridad' => [
            'panel_control.php',
            'gestion_mesas.php'
        ]
    ];

    // Devuelve las páginas permitidas según el perfil; por defecto solo el panel de control si no coincide
    return $mapa_permisos[$perfil] ?? ['panel_control.php'];
}

// 3. BLOQUEO AUTOMÁTICO DE ACCESO DIRECTO POR URL
$empleado_perfil = $_SESSION['empleado_perfil'];
$pagina_actual = basename($_SERVER['PHP_SELF']);
$paginas_permitidas = obtenerPaginasPermitidas($empleado_perfil);

// Si el usuario intenta entrar a una página que NO está en su lista permitida:
if (!in_array($pagina_actual, $paginas_permitidas)) {
    header("Location: panel_control.php?error=acceso_denegado");
    exit;
}
?>