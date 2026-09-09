<?php

require_once "conexion.php";

header("Content-Type: application/json; charset=utf-8");

$origen = $_GET['origen'] ?? '';
$destino = $_GET['destino'] ?? '';
$fecha = $_GET['fecha'] ?? '';

if ($origen === '' || $destino === '' || $fecha === '') {
    http_response_code(400);

    echo json_encode([
        "ok" => false,
        "error" => "Faltan parámetros"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

$sql = "
    SELECT
        f.id,
        f.flight_number,
        f.route_id,
        f.aircraft_id,
        f.status,
        f.base_price,
        f.departure_datetime,
        f.arrival_datetime,
        f.gate,
        f.terminal,

        r.distance_km,
        r.estimated_duration_minutes,

        ao.id AS origen_id,
        ao.iata_code AS origen_iata,
        ao.icao_code AS origen_icao,
        ao.name AS origen_nombre,
        ao.city AS origen_ciudad,
        ao.country_code AS origen_codigo_pais,
        ao.timezone AS origen_zona_horaria,

        ad.id AS destino_id,
        ad.iata_code AS destino_iata,
        ad.icao_code AS destino_icao,
        ad.name AS destino_nombre,
        ad.city AS destino_ciudad,
        ad.country_code AS destino_codigo_pais,
        ad.timezone AS destino_zona_horaria,

        ac.id AS aeronave_id,
        ac.registration AS matricula,
        ac.serial_number AS numero_serie,
        ac.status AS aeronave_estado,

        at.id AS tipo_aeronave_id,
        at.manufacturer AS fabricante,
        at.model AS modelo,
        at.total_capacity AS capacidad_total,
        at.seat_configuration AS configuracion_asientos,
        at.range_km AS alcance_km

    FROM flights f

    INNER JOIN routes r
        ON f.route_id = r.id

    INNER JOIN airports ao
        ON r.origin_id = ao.id

    INNER JOIN airports ad
        ON r.destination_id = ad.id

    LEFT JOIN aircraft ac
        ON f.aircraft_id = ac.id

    LEFT JOIN aircraft_types at
        ON ac.type_id = at.id

    WHERE ao.iata_code = ?
      AND ad.iata_code = ?
      AND DATE(f.departure_datetime) = ?
      AND f.status <> 'cancelled'
      AND r.active = 1

    ORDER BY f.departure_datetime ASC
";

$stmt = mysqli_prepare($conexion, $sql);

if (!$stmt) {
    http_response_code(500);

    echo json_encode([
        "ok" => false,
        "error" => "No se pudo preparar la consulta"
    ], JSON_UNESCAPED_UNICODE);

    exit;
}

mysqli_stmt_bind_param(
    $stmt,
    "sss",
    $origen,
    $destino,
    $fecha
);

if (!mysqli_stmt_execute($stmt)) {
    http_response_code(500);

    echo json_encode([
        "ok" => false,
        "error" => "Error ejecutando la consulta"
    ], JSON_UNESCAPED_UNICODE);

    mysqli_stmt_close($stmt);
    exit;
}

$resultado = mysqli_stmt_get_result($stmt);

$vuelos = [];

while ($fila = mysqli_fetch_assoc($resultado)) {

    $configuracion = null;

    if (!empty($fila["configuracion_asientos"])) {
        $configuracion = json_decode(
            $fila["configuracion_asientos"],
            true
        );
    }

    $vuelos[] = [
        "id" => (int)$fila["id"],
        "numero_vuelo" => $fila["flight_number"],
        "ruta_id" => (int)$fila["route_id"],
        "aeronave_id" => (int)$fila["aircraft_id"],
        "estado" => $fila["status"],
        "precio" => (float)$fila["base_price"],
        "moneda" => "USD",

        "salida_programada" => $fila["departure_datetime"],
        "llegada_programada" => $fila["arrival_datetime"],
        "puerta" => $fila["gate"],
        "terminal" => $fila["terminal"],

        "distancia_km" => $fila["distance_km"],
        "duracion_estimada_minutos" => $fila["estimated_duration_minutes"],

        "origen" => [
            "id" => (int)$fila["origen_id"],
            "codigo_iata" => $fila["origen_iata"],
            "codigo_icao" => $fila["origen_icao"],
            "nombre" => $fila["origen_nombre"],
            "ciudad" => $fila["origen_ciudad"],
            "codigo_pais" => $fila["origen_codigo_pais"],
            "zona_horaria" => $fila["origen_zona_horaria"]
        ],

        "destino" => [
            "id" => (int)$fila["destino_id"],
            "codigo_iata" => $fila["destino_iata"],
            "codigo_icao" => $fila["destino_icao"],
            "nombre" => $fila["destino_nombre"],
            "ciudad" => $fila["destino_ciudad"],
            "codigo_pais" => $fila["destino_codigo_pais"],
            "zona_horaria" => $fila["destino_zona_horaria"]
        ],

        "aeronave" => [
            "id" => (int)$fila["aeronave_id"],
            "matricula" => $fila["matricula"],
            "numero_serie" => $fila["numero_serie"],
            "tipo_aeronave_id" => $fila["tipo_aeronave_id"] !== null
                ? (int)$fila["tipo_aeronave_id"]
                : null,
            "estado" => $fila["aeronave_estado"],
            "fabricante" => $fila["fabricante"],
            "modelo" => $fila["modelo"],
            "capacidad_total" => $fila["capacidad_total"],
            "configuracion_asientos" => $configuracion,
            "alcance_km" => $fila["alcance_km"]
        ]
    ];
}

echo json_encode([
    "ok" => true,
    "data" => $vuelos
], JSON_UNESCAPED_UNICODE);

mysqli_free_result($resultado);
mysqli_stmt_close($stmt);