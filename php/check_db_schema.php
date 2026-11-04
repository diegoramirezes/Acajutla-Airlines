<?php
require_once __DIR__ . '/conexion.php';
header('Content-Type: application/json');

try {
    $pdo = getDBConnection();
    
    // Check crew_members schema
    $stmt = $pdo->query("DESCRIBE crew_members");
    $crew_cols = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Check existing crew members
    $stmt = $pdo->query("SELECT * FROM crew_members LIMIT 10");
    $crew = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Check flight_crew schema
    $stmt = $pdo->query("DESCRIBE flight_crew");
    $fc_cols = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Check routes for SFO
    $stmt = $pdo->query("SELECT * FROM airports WHERE iata_code = 'SFO'");
    $sfo = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Check fare classes
    $stmt = $pdo->query("SELECT * FROM fare_classes");
    $fares = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'ok' => true,
        'crew_cols' => $crew_cols,
        'crew' => $crew,
        'fc_cols' => $fc_cols,
        'sfo' => $sfo,
        'fares' => $fares
    ], JSON_PRETTY_PRINT);
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
