<?php
require_once __DIR__ . '/conexion.php';
header('Content-Type: application/json; charset=utf-8');

if (!CONEXION_OK) {
    echo json_encode(['ok' => false, 'error' => 'No hay conexión a la base de datos']);
    exit;
}

try {
    $res = $conexion->query("DESCRIBE flight_ancillaries");
    $fa_cols = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

    $res = $conexion->query("DESCRIBE ancillaries");
    $anc_cols = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

    $res = $conexion->query("SELECT * FROM ancillaries");
    $ancillaries = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];

    $res = $conexion->query("
        SELECT COUNT(*) as total_asignaciones
        FROM flight_ancillaries fa
        JOIN flights f ON f.id = fa.flight_id
        WHERE f.departure_datetime >= '2026-11-01' AND f.departure_datetime <= '2026-11-30 23:59:59'
    ");
    $total_fa_nov = $res ? $res->fetch_assoc()['total_asignaciones'] : 0;

    $res = $conexion->query("
        SELECT COUNT(*) as total_vuelos_nov
        FROM flights
        WHERE departure_datetime >= '2026-11-01' AND departure_datetime <= '2026-11-30 23:59:59'
    ");
    $total_vuelos_nov = $res ? $res->fetch_assoc()['total_vuelos_nov'] : 0;

    echo json_encode([
        'ok' => true,
        'fa_cols' => $fa_cols,
        'anc_cols' => $anc_cols,
        'ancillaries' => $ancillaries,
        'total_vuelos_nov' => $total_vuelos_nov,
        'total_fa_nov' => $total_fa_nov
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
