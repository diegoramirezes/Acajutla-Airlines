<?php
/**
 * =====================================================================
 * Endpoint temporal para insertar vuelos programados (03 al 06 Nov 2026)
 * Se ejecuta en Render (donde el PHP sí tiene conexión a la BD de Aiven).
 * =====================================================================
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/conexion.php';

if (!CONEXION_OK) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No se pudo conectar a la base de datos']);
    exit;
}

// 1. Obtener clases tarifarias activas
$resClases = mysqli_query($conexion, "SELECT id, code, multiplier FROM fare_classes WHERE active = 1");
$fareClasses = [];
while ($fc = mysqli_fetch_assoc($resClases)) {
    $fareClasses[] = [
        'id' => (int)$fc['id'],
        'code' => $fc['code'],
        'multiplier' => (float)$fc['multiplier']
    ];
}
mysqli_free_result($resClases);

// 2. Obtener lista de aeropuertos activos
$resAirports = mysqli_query($conexion, "SELECT id, iata_code FROM airports WHERE active = 1");
$airports = [];
while ($a = mysqli_fetch_assoc($resAirports)) {
    $airports[$a['iata_code']] = (int)$a['id'];
}
mysqli_free_result($resAirports);

// 3. Obtener aeronaves operativas
$resPlanes = mysqli_query($conexion, "SELECT id, registration FROM aircraft LIMIT 10");
$planes = [];
while ($p = mysqli_fetch_assoc($resPlanes)) {
    $planes[] = (int)$p['id'];
}
mysqli_free_result($resPlanes);
$defaultAircraftId = !empty($planes) ? $planes[0] : 1;

// 4. Parámetros de rutas hacia/desde SAL (Hub principal)
$destinosConfig = [
    'MIA' => ['precio' => 180.00, 'duracionMin' => 195, 'km' => 1648],
    'GUA' => ['precio' => 95.00,  'duracionMin' => 50,  'km' => 190],
    'SJO' => ['precio' => 115.00, 'duracionMin' => 75,  'km' => 540],
    'MEX' => ['precio' => 160.00, 'duracionMin' => 140, 'km' => 1250],
    'PTY' => ['precio' => 140.00, 'duracionMin' => 110, 'km' => 950],
    'LIR' => ['precio' => 110.00, 'duracionMin' => 65,  'km' => 450],
    'LUA' => ['precio' => 65.00,  'duracionMin' => 35,  'km' => 130],
    'TGU' => ['precio' => 90.00,  'duracionMin' => 45,  'km' => 170],
    'MGA' => ['precio' => 105.00, 'duracionMin' => 55,  'km' => 360],
];

// Helper para asegurar que la ruta exista en la tabla routes
function asegurarRuta($conexion, $originId, $destId, $originIata, $destIata, $km, $duracionMin) {
    $stmt = mysqli_prepare($conexion, "SELECT id FROM routes WHERE origin_id = ? AND destination_id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'ii', $originId, $destId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($res);
    mysqli_stmt_close($stmt);

    if ($row) {
        return (int)$row['id'];
    }

    $routeCode = $originIata . '-' . $destIata;
    $stmtIns = mysqli_prepare($conexion, "INSERT INTO routes (origin_id, destination_id, route_code, distance_km, estimated_duration_minutes, active) VALUES (?, ?, ?, ?, ?, 1)");
    mysqli_stmt_bind_param($stmtIns, 'iisii', $originId, $destId, $routeCode, $km, $duracionMin);
    mysqli_stmt_execute($stmtIns);
    $newId = mysqli_insert_id($conexion);
    mysqli_stmt_close($stmtIns);
    return (int)$newId;
}

$salId = $airports['SAL'] ?? 1;

// 5. Definir horarios y puertas para los 5 vuelos diarios
$horariosSalida = [
    1 => ['salida' => '07:00:00', 'gate' => 'G1', 'sufijo' => '1'],
    2 => ['salida' => '10:30:00', 'gate' => 'G2', 'sufijo' => '3'],
    3 => ['salida' => '14:00:00', 'gate' => 'G3', 'sufijo' => '5'],
    4 => ['salida' => '17:30:00', 'gate' => 'G4', 'sufijo' => '7'],
    5 => ['salida' => '21:00:00', 'gate' => 'G5', 'sufijo' => '9'],
];

$horariosRegreso = [
    1 => ['salida' => '08:30:00', 'gate' => 'A1', 'sufijo' => '2'],
    2 => ['salida' => '12:00:00', 'gate' => 'A2', 'sufijo' => '4'],
    3 => ['salida' => '15:30:00', 'gate' => 'A3', 'sufijo' => '6'],
    4 => ['salida' => '19:00:00', 'gate' => 'B1', 'sufijo' => '8'],
    5 => ['salida' => '22:30:00', 'gate' => 'B2', 'sufijo' => '0'],
];

$fechas = ['2026-11-03', '2026-11-04', '2026-11-05', '2026-11-06'];

$vuelosInsertados = 0;
$tarifasInsertadas = 0;
$errores = [];

mysqli_begin_transaction($conexion);

try {
    foreach ($destinosConfig as $iata => $cfg) {
        $destId = $airports[$iata] ?? null;
        if (!$destId) continue;

        // Rutas
        $rutaIdaId = asegurarRuta($conexion, $salId, $destId, 'SAL', $iata, $cfg['km'], $cfg['duracionMin']);
        $rutaRegresoId = asegurarRuta($conexion, $destId, $salId, $iata, 'SAL', $cfg['km'], $cfg['duracionMin']);

        foreach ($fechas as $fecha) {
            // 5 vuelos de IDA (SAL -> Destino)
            for ($i = 1; $i <= 5; $i++) {
                $hInfo = $horariosSalida[$i];
                $salidaTs = strtotime("$fecha " . $hInfo['salida']);
                $llegadaTs = $salidaTs + ($cfg['duracionMin'] * 60);
                $salidaStr = date('Y-m-d H:i:s', $salidaTs);
                $llegadaStr = date('Y-m-d H:i:s', $llegadaTs);

                $numVuelo = "YS-" . $destId . $hInfo['sufijo'] . ($i % 2 === 0 ? "A" : "B");
                $aeronaveId = !empty($planes) ? $planes[($i - 1) % count($planes)] : $defaultAircraftId;

                // Verificar si ya existe el vuelo
                $stmtChk = mysqli_prepare($conexion, "SELECT id FROM flights WHERE flight_number = ? AND departure_datetime = ? LIMIT 1");
                mysqli_stmt_bind_param($stmtChk, 'ss', $numVuelo, $salidaStr);
                mysqli_stmt_execute($stmtChk);
                $resChk = mysqli_stmt_get_result($stmtChk);
                $existe = mysqli_fetch_assoc($resChk);
                mysqli_stmt_close($stmtChk);

                if (!$existe) {
                    $stmtInsF = mysqli_prepare($conexion, "INSERT INTO flights (route_id, aircraft_id, flight_number, departure_datetime, arrival_datetime, status, base_price, gate, terminal) VALUES (?, ?, ?, ?, ?, 'scheduled', ?, ?, 'T1')");
                    mysqli_stmt_bind_param($stmtInsF, 'iisssds', $rutaIdaId, $aeronaveId, $numVuelo, $salidaStr, $llegadaStr, $cfg['precio'], $hInfo['gate']);
                    mysqli_stmt_execute($stmtInsF);
                    $flightId = mysqli_insert_id($conexion);
                    mysqli_stmt_close($stmtInsF);
                    $vuelosInsertados++;

                    // Insertar tarifas
                    foreach ($fareClasses as $fc) {
                        $pTarifa = round($cfg['precio'] * $fc['multiplier'], 2);
                        $stmtT = mysqli_prepare($conexion, "INSERT INTO flight_fares (flight_id, fare_class_id, price, seats_allocated, seats_sold, active) VALUES (?, ?, ?, 0, 0, 1)");
                        mysqli_stmt_bind_param($stmtT, 'iid', $flightId, $fc['id'], $pTarifa);
                        mysqli_stmt_execute($stmtT);
                        mysqli_stmt_close($stmtT);
                        $tarifasInsertadas++;
                    }
                }
            }

            // 5 vuelos de REGRESO (Destino -> SAL)
            for ($j = 1; $j <= 5; $j++) {
                $hInfo = $horariosRegreso[$j];
                $salidaTs = strtotime("$fecha " . $hInfo['salida']);
                $llegadaTs = $salidaTs + ($cfg['duracionMin'] * 60);
                $salidaStr = date('Y-m-d H:i:s', $salidaTs);
                $llegadaStr = date('Y-m-d H:i:s', $llegadaTs);

                $numVuelo = "YS-" . $destId . $hInfo['sufijo'] . ($j % 2 === 0 ? "R" : "V");
                $aeronaveId = !empty($planes) ? $planes[($j - 1) % count($planes)] : $defaultAircraftId;

                $stmtChk = mysqli_prepare($conexion, "SELECT id FROM flights WHERE flight_number = ? AND departure_datetime = ? LIMIT 1");
                mysqli_stmt_bind_param($stmtChk, 'ss', $numVuelo, $salidaStr);
                mysqli_stmt_execute($stmtChk);
                $resChk = mysqli_stmt_get_result($stmtChk);
                $existe = mysqli_fetch_assoc($resChk);
                mysqli_stmt_close($stmtChk);

                if (!$existe) {
                    $stmtInsF = mysqli_prepare($conexion, "INSERT INTO flights (route_id, aircraft_id, flight_number, departure_datetime, arrival_datetime, status, base_price, gate, terminal) VALUES (?, ?, ?, ?, ?, 'scheduled', ?, ?, 'T1')");
                    mysqli_stmt_bind_param($stmtInsF, 'iisssds', $rutaRegresoId, $aeronaveId, $numVuelo, $salidaStr, $llegadaStr, $cfg['precio'], $hInfo['gate']);
                    mysqli_stmt_execute($stmtInsF);
                    $flightId = mysqli_insert_id($conexion);
                    mysqli_stmt_close($stmtInsF);
                    $vuelosInsertados++;

                    // Insertar tarifas
                    foreach ($fareClasses as $fc) {
                        $pTarifa = round($cfg['precio'] * $fc['multiplier'], 2);
                        $stmtT = mysqli_prepare($conexion, "INSERT INTO flight_fares (flight_id, fare_class_id, price, seats_allocated, seats_sold, active) VALUES (?, ?, ?, 0, 0, 1)");
                        mysqli_stmt_bind_param($stmtT, 'iid', $flightId, $fc['id'], $pTarifa);
                        mysqli_stmt_execute($stmtT);
                        mysqli_stmt_close($stmtT);
                        $tarifasInsertadas++;
                    }
                }
            }
        }
    }

    mysqli_commit($conexion);
    cerrarConexion();

    echo json_encode([
        'ok' => true,
        'mensaje' => 'Vuelos y tarifas insertados correctamente',
        'vuelos_insertados' => $vuelosInsertados,
        'tarifas_insertadas' => $tarifasInsertadas,
        'fechas' => $fechas
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    mysqli_rollback($conexion);
    cerrarConexion();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
