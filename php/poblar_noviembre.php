<?php
/**
 * Script de población de vuelos para todo noviembre 2026 (Nov 1 al Nov 30).
 * Genera frecuencias de ida y vuelta para todas las rutas principales hacia/desde SAL,
 * incluyendo San Francisco (SFO), Miami (MIA), México (MEX), Guatemala (GUA),
 * San José (SJO), Panamá (PTY), Liberia (LIR), La Unión (LUA), Tegucigalpa (TGU), Managua (MGA).
 * Asigna tarifas para las 4 clases (ECONOMICA, PREMIUM, BUSINESS, PRIMERA) con variación de precios
 * para activar los colores verde ($), amarillo ($$) y rojo ($$$) en el calendario.
 * Asigna tripulación técnica mínima obligatoria (Piloto y Copiloto en flight_crew).
 */

require_once __DIR__ . '/conexion.php';
header('Content-Type: application/json; charset=utf-8');

if (!CONEXION_OK) {
    echo json_encode(['ok' => false, 'error' => 'No hay conexión a la base de datos']);
    exit;
}

set_time_limit(300); // 5 minutos máximo

try {
    // 1. Obtener pilotos y copilotos disponibles
    $pilotsRes = $conexion->query("SELECT id FROM crew_members WHERE role_operativo = 'pilot' AND active = 1");
    $copilotsRes = $conexion->query("SELECT id FROM crew_members WHERE role_operativo = 'copilot' AND active = 1");

    $pilotIds = [];
    while ($row = $pilotsRes->fetch_assoc()) {
        $pilotIds[] = (int)$row['id'];
    }
    $copilotIds = [];
    while ($row = $copilotsRes->fetch_assoc()) {
        $copilotIds[] = (int)$row['id'];
    }

    if (empty($pilotIds) || empty($copilotIds)) {
        throw new Exception("No hay pilotos o copilotos activos en crew_members.");
    }

    // 2. Obtener aeronaves disponibles
    $acRes = $conexion->query("SELECT id FROM aircraft WHERE active = 1");
    $aircraftIds = [];
    while ($row = $acRes->fetch_assoc()) {
        $aircraftIds[] = (int)$row['id'];
    }
    if (empty($aircraftIds)) {
        $aircraftIds = [1];
    }

    // 3. Obtener clases tarifarias
    $fareRes = $conexion->query("SELECT id, code, multiplier FROM fare_classes WHERE active = 1");
    $fareClasses = [];
    while ($row = $fareRes->fetch_assoc()) {
        $fareClasses[] = $row;
    }

    // 4. Mapear rutas SAL <-> destinos
    // Obtenemos id del aeropuerto SAL (1)
    $salId = 1;
    // Aeropuertos destino a cubrir
    // MIA (2), GUA (3), SJO (4), MEX (5), PTY (6), LIR (7), LUA (8), TGU (9), MGA (10), SFO (11)
    $destinosConfig = [
        11 => ['code' => 'SFO', 'dist' => 4600, 'dur' => 400, 'base' => 320.00], // San Francisco
        2  => ['code' => 'MIA', 'dist' => 1650, 'dur' => 165, 'base' => 180.00], // Miami
        5  => ['code' => 'MEX', 'dist' => 2100, 'dur' => 190, 'base' => 160.00], // México
        6  => ['code' => 'PTY', 'dist' => 1350, 'dur' => 140, 'base' => 150.00], // Panamá
        4  => ['code' => 'SJO', 'dist' => 780,  'dur' => 105, 'base' => 120.00], // Costa Rica
        3  => ['code' => 'GUA', 'dist' => 180,  'dur' => 90,  'base' => 85.00],  // Guatemala
        7  => ['code' => 'LIR', 'dist' => 600,  'dur' => 95,  'base' => 110.00], // Liberia
        8  => ['code' => 'LUA', 'dist' => 130,  'dur' => 35,  'base' => 45.00],  // La Unión
        9  => ['code' => 'TGU', 'dist' => 480,  'dur' => 75,  'base' => 95.00],  // Tegucigalpa
        10 => ['code' => 'MGA', 'dist' => 550,  'dur' => 80,  'base' => 100.00], // Managua
    ];

    // Asegurar que las rutas existan en la tabla routes
    $rutasMap = []; // 'origen-destino' => route_id
    foreach ($destinosConfig as $destId => $cfg) {
        // Ida: SAL -> DEST
        $stmt = $conexion->prepare("SELECT id FROM routes WHERE origin_id = ? AND destination_id = ? AND active = 1");
        $stmt->bind_param("ii", $salId, $destId);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $rutasMap["$salId-$destId"] = (int)$row['id'];
        } else {
            $ins = $conexion->prepare("INSERT INTO routes (origin_id, destination_id, distance_km, estimated_duration_minutes, route_code, active) VALUES (?, ?, ?, ?, ?, 1)");
            $code = "SAL-" . $cfg['code'];
            $ins->bind_param("iiiis", $salId, $destId, $cfg['dist'], $cfg['dur'], $code);
            $ins->execute();
            $rutasMap["$salId-$destId"] = $conexion->insert_id;
        }

        // Regreso: DEST -> SAL
        $stmt = $conexion->prepare("SELECT id FROM routes WHERE origin_id = ? AND destination_id = ? AND active = 1");
        $stmt->bind_param("ii", $destId, $salId);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($row = $res->fetch_assoc()) {
            $rutasMap["$destId-$salId"] = (int)$row['id'];
        } else {
            $ins = $conexion->prepare("INSERT INTO routes (origin_id, destination_id, distance_km, estimated_duration_minutes, route_code, active) VALUES (?, ?, ?, ?, ?, 1)");
            $code = $cfg['code'] . "-SAL";
            $ins->bind_param("iiiis", $destId, $salId, $cfg['dist'], $cfg['dur'], $code);
            $ins->execute();
            $rutasMap["$destId-$salId"] = $conexion->insert_id;
        }
    }

    // Horarios para 3 frecuencias diarias por ruta (mañana, tarde, noche)
    $horarios = [
        ['salida' => '07:00:00', 'pref' => '07'],
        ['salida' => '13:30:00', 'pref' => '13'],
        ['salida' => '18:45:00', 'pref' => '18']
    ];

    // Preparar statements de inserción masiva
    $stmtFlight = $conexion->prepare("
        INSERT INTO flights (route_id, aircraft_id, flight_number, departure_datetime, arrival_datetime, status, base_price, gate, terminal)
        VALUES (?, ?, ?, ?, ?, 'scheduled', ?, ?, ?)
    ");

    $stmtFare = $conexion->prepare("
        INSERT INTO flight_fares (flight_id, fare_class_id, price, seats_allocated, seats_sold)
        VALUES (?, ?, ?, ?, 0)
        ON DUPLICATE KEY UPDATE price = VALUES(price)
    ");

    $stmtCrew = $conexion->prepare("
        INSERT INTO flight_crew (flight_id, crew_member_id, role, duty_start, duty_end)
        VALUES (?, ?, ?, ?, ?)
    ");

    $totalVuelos = 0;
    $totalTarifas = 0;
    $totalCrew = 0;

    $gates = ['G1', 'G2', 'G3', 'G4', 'G5', 'G6', 'G7', 'G8'];
    $terminals = ['T1', 'T2'];

    $pIndex = 0;
    $cIndex = 0;
    $acIndex = 0;

    // Recorremos todo noviembre 2026: del día 1 al 30
    for ($dia = 1; $dia <= 30; $dia++) {
        $fechaStr = sprintf("2026-11-%02d", $dia);
        $dayOfWeek = (int)date('N', strtotime($fechaStr)); // 1=Lunes .. 7=Domingo

        // Factor de variación de precio por día para activar verde, amarillo y rojo en calendario:
        // Días de semana (Martes, Miércoles): más baratos (factor ~0.85 a 0.90) -> VERDE ($)
        // Jueves, Lunes: medio (factor ~1.00) -> AMARILLO ($$)
        // Viernes, Sábado, Domingo: más caros (factor ~1.20 a 1.35) -> ROJO ($$$)
        if ($dayOfWeek == 2 || $dayOfWeek == 3) {
            $factorDia = 0.85; // Económico / Verde
        } elseif ($dayOfWeek == 1 || $dayOfWeek == 4) {
            $factorDia = 1.05; // Medio / Amarillo
        } else {
            $factorDia = 1.30; // Alto / Rojo
        }

        foreach ($destinosConfig as $destId => $cfg) {
            $routeIda = $rutasMap["$salId-$destId"];
            $routeReg = $rutasMap["$destId-$salId"];
            $durMin = $cfg['dur'];
            $baseRef = $cfg['base'];

            // Para SFO y MIA generamos 3 frecuencias diarias; para el resto 2 o 3
            $frecuencias = ($destId == 11 || $destId == 2 || $destId == 5) ? $horarios : array_slice($horarios, 0, 2);

            foreach ($frecuencias as $hIdx => $h) {
                $factorVuelo = $factorDia * (1.0 + ($hIdx * 0.05)); // ligero ajuste por horario
                $precioBaseIda = round($baseRef * $factorVuelo, 2);
                $precioBaseReg = round($baseRef * ($factorVuelo * 0.98), 2);

                // --- 1. VUELO DE IDA (SAL -> DEST) ---
                $depTimeIda = "$fechaStr {$h['salida']}";
                $arrTimeIda = date('Y-m-d H:i:s', strtotime($depTimeIda) + ($durMin * 60));
                $fnIda = "AJ" . sprintf("%02d%02d%d", $destId, $dia, $hIdx + 1);
                $aircraftId = $aircraftIds[$acIndex % count($aircraftIds)];
                $acIndex++;
                $gate = $gates[array_rand($gates)];
                $terminal = $terminals[array_rand($terminals)];

                // Verificar si ya existe este flight_number en esa fecha para no duplicar
                $check = $conexion->prepare("SELECT id FROM flights WHERE flight_number = ? AND DATE(departure_datetime) = ?");
                $check->bind_param("ss", $fnIda, $fechaStr);
                $check->execute();
                $cRes = $check->get_result();
                if ($cRow = $cRes->fetch_assoc()) {
                    $flightIdIda = (int)$cRow['id'];
                } else {
                    $stmtFlight->bind_param("iisssdss", $routeIda, $aircraftId, $fnIda, $depTimeIda, $arrTimeIda, $precioBaseIda, $gate, $terminal);
                    $stmtFlight->execute();
                    $flightIdIda = $conexion->insert_id;
                    $totalVuelos++;
                }

                // Tarifas de ida
                foreach ($fareClasses as $fc) {
                    $multiplier = (float)$fc['multiplier'];
                    $farePrice = round($precioBaseIda * $multiplier, 2);
                    $seatsAlloc = ($fc['code'] === 'ECONOMICA') ? 120 : (($fc['code'] === 'PREMIUM') ? 24 : 12);
                    $stmtFare->bind_param("iidi", $flightIdIda, $fc['id'], $farePrice, $seatsAlloc);
                    $stmtFare->execute();
                    $totalTarifas++;
                }

                // Tripulación de ida (Piloto y Copiloto)
                $pilotId = $pilotIds[$pIndex % count($pilotIds)];
                $pIndex++;
                $copilotId = $copilotIds[$cIndex % count($copilotIds)];
                $cIndex++;
                $dutyStart = date('Y-m-d H:i:s', strtotime($depTimeIda) - 3600); // 1 hora antes
                $dutyEnd = date('Y-m-d H:i:s', strtotime($arrTimeIda) + 1800);    // 30 min después

                // Comprobar si ya tiene crew
                $cCheck = $conexion->query("SELECT id FROM flight_crew WHERE flight_id = $flightIdIda LIMIT 1");
                if (!$cCheck || $cCheck->num_rows == 0) {
                    $roleP = 'pilot';
                    $stmtCrew->bind_param("iisss", $flightIdIda, $pilotId, $roleP, $dutyStart, $dutyEnd);
                    $stmtCrew->execute();

                    $roleC = 'copilot';
                    $stmtCrew->bind_param("iisss", $flightIdIda, $copilotId, $roleC, $dutyStart, $dutyEnd);
                    $stmtCrew->execute();
                    $totalCrew += 2;
                }

                // --- 2. VUELO DE REGRESO (DEST -> SAL) ---
                $depOffset = ($hIdx == 0) ? "09:30:00" : (($hIdx == 1) ? "15:45:00" : "21:15:00");
                $depTimeReg = "$fechaStr $depOffset";
                $arrTimeReg = date('Y-m-d H:i:s', strtotime($depTimeReg) + ($durMin * 60));
                $fnReg = "AJ" . sprintf("%02d%02d%d", $destId + 50, $dia, $hIdx + 1);
                $aircraftIdReg = $aircraftIds[$acIndex % count($aircraftIds)];
                $acIndex++;

                $check->bind_param("ss", $fnReg, $fechaStr);
                $check->execute();
                $cRes = $check->get_result();
                if ($cRow = $cRes->fetch_assoc()) {
                    $flightIdReg = (int)$cRow['id'];
                } else {
                    $stmtFlight->bind_param("iisssdss", $routeReg, $aircraftIdReg, $fnReg, $depTimeReg, $arrTimeReg, $precioBaseReg, $gate, $terminal);
                    $stmtFlight->execute();
                    $flightIdReg = $conexion->insert_id;
                    $totalVuelos++;
                }

                // Tarifas de regreso
                foreach ($fareClasses as $fc) {
                    $multiplier = (float)$fc['multiplier'];
                    $farePrice = round($precioBaseReg * $multiplier, 2);
                    $seatsAlloc = ($fc['code'] === 'ECONOMICA') ? 120 : (($fc['code'] === 'PREMIUM') ? 24 : 12);
                    $stmtFare->bind_param("iidi", $flightIdReg, $fc['id'], $farePrice, $seatsAlloc);
                    $stmtFare->execute();
                    $totalTarifas++;
                }

                // Tripulación de regreso
                $pilotIdReg = $pilotIds[$pIndex % count($pilotIds)];
                $pIndex++;
                $copilotIdReg = $copilotIds[$cIndex % count($copilotIds)];
                $cIndex++;
                $dutyStartReg = date('Y-m-d H:i:s', strtotime($depTimeReg) - 3600);
                $dutyEndReg = date('Y-m-d H:i:s', strtotime($arrTimeReg) + 1800);

                $cCheck = $conexion->query("SELECT id FROM flight_crew WHERE flight_id = $flightIdReg LIMIT 1");
                if (!$cCheck || $cCheck->num_rows == 0) {
                    $roleP = 'pilot';
                    $stmtCrew->bind_param("iisss", $flightIdReg, $pilotIdReg, $roleP, $dutyStartReg, $dutyEndReg);
                    $stmtCrew->execute();

                    $roleC = 'copilot';
                    $stmtCrew->bind_param("iisss", $flightIdReg, $copilotIdReg, $roleC, $dutyStartReg, $dutyEndReg);
                    $stmtCrew->execute();
                    $totalCrew += 2;
                }
            }
        }
    }

    echo json_encode([
        'ok' => true,
        'mensaje' => 'Población completa de noviembre 2026 exitosa',
        'vuelos_creados' => $totalVuelos,
        'tarifas_creadas' => $totalTarifas,
        'tripulantes_asignados' => $totalCrew,
        'destinos_cubiertos' => array_column($destinosConfig, 'code')
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
