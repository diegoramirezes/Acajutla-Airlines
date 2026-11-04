<?php
require_once __DIR__ . '/conexion.php';
header('Content-Type: application/json');

if (!CONEXION_OK) {
    echo json_encode(['ok' => false, 'error' => 'No DB connection']);
    exit;
}

try {
    // Check crew_members schema
    $res = $conexion->query("DESCRIBE crew_members");
    $crew_cols = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

    // Check existing crew members
    $res = $conexion->query("SELECT * FROM crew_members LIMIT 10");
    $crew = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

    // Check flight_crew schema
    $res = $conexion->query("DESCRIBE flight_crew");
    $fc_cols = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

    // Check routes for SFO
    $res = $conexion->query("SELECT * FROM airports WHERE iata_code = 'SFO'");
    $sfo = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

    // Check fare classes
    $res = $conexion->query("SELECT * FROM fare_classes");
    $fares = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

    // Check airports list
    $res = $conexion->query("SELECT id, iata_code, name, city, country, active FROM airports");
    $airports = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

    // Check aircraft list
    $res = $conexion->query("SELECT id, registration, model, active FROM aircraft");
    $aircraft = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

    echo json_encode([
        'ok' => true,
        'crew_cols' => $crew_cols,
        'crew' => $crew,
        'fc_cols' => $fc_cols,
        'sfo' => $sfo,
        'fares' => $fares,
        'airports' => $airports,
        'aircraft' => $aircraft
    ], JSON_PRETTY_PRINT);
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
