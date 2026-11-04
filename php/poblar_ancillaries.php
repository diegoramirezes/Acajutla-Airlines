<?php
/**
 * Script de población de servicios adicionales (ancillaries) para los vuelos de noviembre 2026.
 * Utiliza INSERT masivo por lotes para ejecutarse en menos de 2 segundos.
 */

require_once __DIR__ . '/conexion.php';
header('Content-Type: application/json; charset=utf-8');

if (!CONEXION_OK) {
    echo json_encode(['ok' => false, 'error' => 'No hay conexión a la base de datos']);
    exit;
}

try {
    // 1. Obtener todos los ancillaries activos
    $resAnc = $conexion->query("SELECT id, code, name, type, base_price, default_stock FROM ancillaries WHERE active = 1");
    $ancillaries = [];
    while ($row = $resAnc->fetch_assoc()) {
        $ancillaries[] = $row;
    }

    if (empty($ancillaries)) {
        throw new Exception("No hay ancillaries configurados en la base de datos.");
    }

    // 2. Obtener los vuelos de noviembre 2026
    $resVuelos = $conexion->query("
        SELECT id, flight_number 
        FROM flights 
        WHERE departure_datetime >= '2026-11-01' 
          AND departure_datetime <= '2026-11-30 23:59:59'
          AND status <> 'cancelled'
    ");

    $flightIds = [];
    while ($row = $resVuelos->fetch_assoc()) {
        $flightIds[] = (int)$row['id'];
    }

    if (empty($flightIds)) {
        throw new Exception("No se encontraron vuelos para noviembre de 2026.");
    }

    // 3. Preparar filas e insertar por lotes de 1000 filas
    $batchSize = 1000;
    $rowsBuffer = [];
    $totalInsertados = 0;

    foreach ($flightIds as $fid) {
        foreach ($ancillaries as $anc) {
            $ancId = (int)$anc['id'];
            $defaultStock = (int)$anc['default_stock'];
            if ($defaultStock <= 0) {
                if ($anc['type'] === 'baggage') $stock = 150;
                elseif ($anc['type'] === 'seat') $stock = 30;
                elseif ($anc['type'] === 'meal') $stock = 80;
                elseif ($anc['type'] === 'priority') $stock = 40;
                else $stock = 50;
            } else {
                $stock = $defaultStock;
            }

            $rowsBuffer[] = "($fid, $ancId, $stock, NULL, 1)";

            if (count($rowsBuffer) >= $batchSize) {
                $sql = "INSERT INTO flight_ancillaries (flight_id, ancillary_id, stock, price_override, active) VALUES " . implode(',', $rowsBuffer) . " ON DUPLICATE KEY UPDATE stock = VALUES(stock), active = 1";
                if (!$conexion->query($sql)) {
                    throw new Exception("Error en batch insert: " . $conexion->error);
                }
                $totalInsertados += count($rowsBuffer);
                $rowsBuffer = [];
            }
        }
    }

    if (!empty($rowsBuffer)) {
        $sql = "INSERT INTO flight_ancillaries (flight_id, ancillary_id, stock, price_override, active) VALUES " . implode(',', $rowsBuffer) . " ON DUPLICATE KEY UPDATE stock = VALUES(stock), active = 1";
        if (!$conexion->query($sql)) {
            throw new Exception("Error en batch insert final: " . $conexion->error);
        }
        $totalInsertados += count($rowsBuffer);
    }

    // Verificar específicamente vuelo AJ11093
    $checkRes = $conexion->query("
        SELECT fa.id, fa.flight_id, f.flight_number, fa.ancillary_id, a.code, a.name, fa.stock
        FROM flight_ancillaries fa
        JOIN flights f ON f.id = fa.flight_id
        JOIN ancillaries a ON a.id = fa.ancillary_id
        WHERE f.flight_number = 'AJ11093' AND fa.active = 1
    ");
    $sample = $checkRes ? $checkRes->fetch_all(MYSQLI_ASSOC) : [];

    echo json_encode([
        'ok' => true,
        'mensaje' => 'Servicios adicionales asignados exitosamente por lotes',
        'vuelos_procesados' => count($flightIds),
        'total_registros_flight_ancillaries' => $totalInsertados,
        'ejemplo_AJ11093' => $sample
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
