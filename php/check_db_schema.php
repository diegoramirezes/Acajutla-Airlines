<?php
require_once __DIR__ . '/conexion.php';
header('Content-Type: application/json');

if (!CONEXION_OK) {
    echo json_encode(['ok' => false, 'error' => 'No DB connection']);
    exit;
}

try {
    $res = $conexion->query("
        SELECT r.id, r.origin_id, r.destination_id, ao.iata_code AS origen, ad.iata_code AS destino, r.distance_km, r.estimated_duration_minutes, r.active
        FROM routes r
        JOIN airports ao ON ao.id = r.origin_id
        JOIN airports ad ON ad.id = r.destination_id
    ");
    $routes = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

    // Also get active aircraft
    $res2 = $conexion->query("SELECT id, registration, active FROM aircraft");
    $aircraft = $res2 ? $res2->fetch_all(MYSQLI_ASSOC) : [];

    // Also get active crew members
    $res3 = $conexion->query("SELECT id, full_name, role_operativo, active FROM crew_members WHERE active = 1");
    $crew = $res3 ? $res3->fetch_all(MYSQLI_ASSOC) : [];

    echo json_encode([
        'ok' => true,
        'routes' => $routes,
        'aircraft' => $aircraft,
        'crew' => $crew
    ], JSON_PRETTY_PRINT);
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
