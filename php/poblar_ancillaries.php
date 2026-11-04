<?php
/**
 * Script de población de servicios adicionales (ancillaries) para todos los vuelos de noviembre 2026.
 * Asigna a cada vuelo activo en noviembre los servicios adicionales en flight_ancillaries con stock e inventario.
 */

require_once __DIR__ . '/conexion.php';
header('Content-Type: application/json; charset=utf-8');

if (!CONEXION_OK) {
    echo json_encode(['ok' => false, 'error' => 'No hay conexión a la base de datos']);
    exit;
}

set_time_limit(300);

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

    // 2. Obtener todos los vuelos de noviembre 2026
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

    // 3. Insertar o actualizar flight_ancillaries masivamente
    $stmt = $conexion->prepare("
        INSERT INTO flight_ancillaries (flight_id, ancillary_id, stock, price_override, active)
        VALUES (?, ?, ?, ?, 1)
        ON DUPLICATE KEY UPDATE 
            stock = VALUES(stock),
            price_override = VALUES(price_override),
            active = 1
    ");

    $totalAsignados = 0;
    foreach ($flightIds as $fid) {
        foreach ($ancillaries as $anc) {
            $ancId = (int)$anc['id'];
            // Stock por defecto: si tiene default_stock > 0 lo usamos, de lo contrario asignamos según el tipo
            $defaultStock = (int)$anc['default_stock'];
            if ($defaultStock <= 0) {
                if ($anc['type'] === 'baggage') $stock = 150;
                elseif ($anc['type'] === 'seat') $stock = 30;
                elseif ($anc['type'] === 'meal') $stock = 80;
                elseif ($anc['type'] === 'priority') $stock = 40;
                else $stock = 50; // lounge u otros
            } else {
                $stock = $defaultStock;
            }

            $priceOverride = null; // usa base_price del ancillary

            $stmt->bind_param("iiid", $fid, $ancId, $stock, $priceOverride);
            $stmt->execute();
            $totalAsignados++;
        }
    }

    echo json_encode([
        'ok' => true,
        'mensaje' => 'Servicios adicionales asignados exitosamente a todos los vuelos de noviembre 2026',
        'vuelos_procesados' => count($flightIds),
        'servicios_por_vuelo' => count($ancillaries),
        'total_registros_flight_ancillaries' => $totalAsignados
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
