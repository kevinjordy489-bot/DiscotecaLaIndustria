<?php
$serverName = "(localdb)\\MSSQLLocalDB";
$database = "BDDiscoteca_LaIndustria";

try {
    // Conexión PDO para SQL Server LocalDB
    $conn = new PDO("sqlsrv:Server=$serverName;Database=$database", "", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Error de conexión a la base de datos: " . $e->getMessage());
}
?>