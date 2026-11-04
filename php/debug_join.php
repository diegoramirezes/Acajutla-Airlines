<?php
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/conexion.php';

$res = mysqli_query($conexion, "SELECT f.id, f.flight_number, f.departure_datetime, f.route_id, r.origin_id, r.destination_id, r.active, f.aircraft_id, ac.id AS ac_id, at.id AS at_id, at.total_capacity
    FROM flights f
    INNER JOIN routes r ON f.route_id = r.id
    LEFT JOIN aircraft ac ON f.aircraft_id = ac.id
    LEFT JOIN aircraft_types at ON ac.type_id = at.id
    WHERE DATE(f.departure_datetime) = '2026-11-04'
    LIMIT 5");

$filas = [];
while ($row = mysqli_fetch_assoc($res)) {
    $filas[] = $row;
}

echo json_encode([
    'total' => count($filas),
    'ejemplos' => $filas
], JSON_PRETTY_PRINT);
