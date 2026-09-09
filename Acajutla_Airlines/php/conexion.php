<?php

require_once __DIR__ . '/../config.php';

$conexion = obtenerConexionDB();

if (!$conexion) {
    die("Error de conexión con la base de datos.");
}

define('CONEXION_OK', true);