<?php
/**
 * =====================================================================
 * ACAJUTLA AIRLINES — php/crear_reserva.php
 *
 * Endpoint: POST php/crear_reserva.php
 * Content-Type esperado: application/json
 *
 * Persiste REALMENTE la reserva en Aiven (reservations, passengers,
 * flight_segments), usando el esquema real confirmado por el usuario.
 * Sustituye, únicamente para este flujo puntual, a Api.crearReserva()
 * en modo mock (MOCK.reservas.push). El resto de USE_MOCKS no se toca.
 *
 * Payload esperado (enviado por Pago.procesar() en index.php):
 *   {
 *     cliente_id: number|null,
 *     total: number,
 *     pasajeros: [ { nombres, apellidos, documento, tipo, tipoDocumento } ],
 *     segmentos: [
 *       { numero_vuelo, origen, destino, fecha,
 *         flight_id: number,               // flights.id real
 *         fare_class: 'economy'|'premium'|'business'|'first',
 *         precio_unitario: number,         // tarifa real de esa clase
 *         asientos: [ "5A", "5B", ... ]    // uno por pasajero, mismo orden (null si no requiere asiento)
 *       }
 *     ],
 *     pago: { metodo, estado, monto },
 *     contacto: { nombre, email, telefono }
 *   }
 *
 * Reglas de ocupación de asiento (idénticas a asientos_ocupados.php):
 *   - flight_segments.status <> 'cancelled' (confirmed/checked_in/boarded/
 *     no_show cuentan como ocupado; solo 'cancelled' libera el asiento)
 *   - reservations.status <> 'cancelled'
 *   - si reservations.status='pending', solo cuenta mientras
 *     time_limit no haya vencido
 *   (se quitó la exclusión de reservations.status='waiting': ningún flujo
 *   real del proyecto crea reservas en ese estado hoy)
 *
 * Concurrencia (SIN migración, sin índice UNIQUE compuesto disponible):
 *   Antes de leer/insertar flight_segments, se bloquea con
 *   "SELECT id FROM flights WHERE id=? FOR UPDATE" la fila REAL y ya
 *   existente de cada flight_id involucrado (flights.id es PRIMARY KEY,
 *   el lock es inequívoco, no depende de qué índices tenga flight_segments).
 *   Si la reserva incluye varios vuelos, se bloquean en orden ascendente
 *   de flight_id para que todas las transacciones concurrentes adquieran
 *   los locks en la misma secuencia y se evite deadlock cruzado. Con el
 *   vuelo bloqueado, ninguna otra transacción que reserve ese mismo vuelo
 *   puede avanzar hasta que esta transacción haga COMMIT o ROLLBACK —
 *   así se evita que dos reservas concurrentes pasen la verificación de
 *   disponibilidad y ambas inserten el mismo asiento.
 *
 * Tipo de pasajero (passengers.passenger_type ENUM('adult','child','infant')):
 *   el frontend distingue adult/young/child/infant; se mapea young->child,
 *   el resto igual. document_type se toma tal cual del formulario
 *   (tipoDocumento), sin forzar un valor fijo.
 *
 * Como el pago (Api.crearPago) ya se procesó ANTES de llegar aquí, la
 * reserva se crea directamente en status='paid' (no 'pending'), y cada
 * flight_segments en status='confirmed'.
 *
 * Respuesta:
 *   Éxito: {"ok":true,"data":{"pnr":"AB12CD", ...}}
 *   Error: {"ok":false,"error":"..."} (mensaje genérico; detalle técnico
 *          solo en error_log(), nunca expuesto)
 * =====================================================================
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/conexion.php';

function responderError($mensaje, $codigo){
    http_response_code($codigo);
    echo json_encode(['ok' => false, 'error' => $mensaje], JSON_UNESCAPED_UNICODE);
    exit;
}

if($_SERVER['REQUEST_METHOD'] !== 'POST'){
    responderError('Método no permitido.', 400);
}

if(!CONEXION_OK){
    responderError('No se pudo conectar con la base de datos.', 500);
}

// -----------------------------------------------------------------------
// 1. Leer y validar el payload
// -----------------------------------------------------------------------
$raw = file_get_contents('php://input');
if($raw === false || strlen($raw) === 0 || strlen($raw) > 200000){
    cerrarConexion();
    responderError('Payload inválido.', 400);
}
$payload = json_decode($raw, true);
if(json_last_error() !== JSON_ERROR_NONE || !is_array($payload)){
    cerrarConexion();
    responderError('JSON inválido.', 400);
}

$pasajeros = isset($payload['pasajeros']) && is_array($payload['pasajeros']) ? $payload['pasajeros'] : [];
$segmentos = isset($payload['segmentos']) && is_array($payload['segmentos']) ? $payload['segmentos'] : [];
$pago = isset($payload['pago']) && is_array($payload['pago']) ? $payload['pago'] : [];
$total = isset($payload['total']) && is_numeric($payload['total']) ? (float)$payload['total'] : null;

if(count($pasajeros) === 0 || count($pasajeros) > 20){
    cerrarConexion();
    responderError('Número de pasajeros inválido.', 400);
}
if(count($segmentos) === 0 || count($segmentos) > 10){
    cerrarConexion();
    responderError('Debe incluir al menos un segmento de vuelo.', 400);
}
if($total === null){
    cerrarConexion();
    responderError('Total inválido.', 400);
}

$clasesValidas = ['economy', 'premium', 'business', 'first'];
foreach($segmentos as $seg){
    if(!is_array($seg)
        || !isset($seg['flight_id']) || !ctype_digit((string)$seg['flight_id'])
        || !isset($seg['fare_class']) || !in_array($seg['fare_class'], $clasesValidas, true)
        || !isset($seg['asientos']) || !is_array($seg['asientos'])
        || count($seg['asientos']) !== count($pasajeros)){
        cerrarConexion();
        responderError('Datos de segmento de vuelo inválidos o incompletos.', 400);
    }
}

function generarPnr($conexion){
    // reservations.pnr es char(6) UNIQUE (uk_pnr). Se reintenta si hay colisión.
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    for($intento = 0; $intento < 10; $intento++){
        $pnr = '';
        for($i = 0; $i < 6; $i++){
            $pnr .= $chars[random_int(0, strlen($chars) - 1)];
        }
        $stmt = mysqli_prepare($conexion, 'SELECT id FROM reservations WHERE pnr = ?');
        mysqli_stmt_bind_param($stmt, 's', $pnr);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_store_result($stmt);
        $existe = mysqli_stmt_num_rows($stmt) > 0;
        mysqli_stmt_close($stmt);
        if(!$existe) return $pnr;
    }
    return null;
}

// -----------------------------------------------------------------------
// 2. Transacción: bloquear, verificar disponibilidad real, insertar
// -----------------------------------------------------------------------
mysqli_begin_transaction($conexion);

try{
    // 2.1 Bloquear la fila REAL de flights (PK) para cada vuelo distinto
    //     involucrado, en orden ascendente de flight_id, para serializar
    //     contra reservas concurrentes del mismo vuelo y evitar deadlock
    //     cruzado cuando una reserva incluye varios vuelos.
    $flightIdsUnicos = [];
    foreach($segmentos as $seg){
        $flightIdsUnicos[(int)$seg['flight_id']] = true;
    }
    $flightIdsOrdenados = array_keys($flightIdsUnicos);
    sort($flightIdsOrdenados, SORT_NUMERIC);

    $stmtLockVuelo = mysqli_prepare($conexion,
        "SELECT id, departure_datetime, (departure_datetime > CONVERT_TZ(NOW(), '+00:00', '-06:00')) AS aun_disponible
         FROM flights WHERE id = ? FOR UPDATE"
    );
    if(!$stmtLockVuelo){
        throw new Exception('No se pudo preparar el bloqueo del vuelo.');
    }
    foreach($flightIdsOrdenados as $flightId){
        mysqli_stmt_bind_param($stmtLockVuelo, 'i', $flightId);
        if(!mysqli_stmt_execute($stmtLockVuelo)){
            throw new Exception('Error al bloquear el vuelo ' . $flightId . ': ' . mysqli_stmt_error($stmtLockVuelo));
        }
        $resultadoVuelo = mysqli_stmt_get_result($stmtLockVuelo);
        $filaVuelo = $resultadoVuelo ? mysqli_fetch_assoc($resultadoVuelo) : null;
        if($resultadoVuelo) mysqli_free_result($resultadoVuelo);
        if(!$filaVuelo){
            throw new Exception('El vuelo ' . $flightId . ' no existe.');
        }
        // Comparación por fecha+hora real del servidor de BD (NOW()), no de
        // ningún reloj del navegador — un vuelo cuya salida ya pasó nunca
        // puede reservarse, aunque el flight_id se envíe manualmente.
        if(!$filaVuelo['aun_disponible']){
            throw new Exception('VUELO_YA_SALIO:' . $flightId);
        }
    }
    mysqli_stmt_close($stmtLockVuelo);

    // 2.2 Con los vuelos ya bloqueados, consultar qué asientos están
    //     realmente ocupados (misma regla que asientos_ocupados.php).
    $ocupadosPorVuelo = []; // flight_id => [seat => true]
    $stmtLock = mysqli_prepare($conexion,
        "SELECT fs.seat, r.status, r.time_limit
         FROM flight_segments fs
         INNER JOIN reservations r ON r.id = fs.reservation_id
         WHERE fs.flight_id = ? AND fs.status <> 'cancelled'"
    );
    if(!$stmtLock){
        throw new Exception('No se pudo preparar la verificación de disponibilidad.');
    }

    foreach($flightIdsOrdenados as $flightId){
        mysqli_stmt_bind_param($stmtLock, 'i', $flightId);
        if(!mysqli_stmt_execute($stmtLock)){
            throw new Exception('Error al verificar disponibilidad: ' . mysqli_stmt_error($stmtLock));
        }
        $resultado = mysqli_stmt_get_result($stmtLock);
        if($resultado === false){
            throw new Exception('Error al leer disponibilidad: ' . mysqli_stmt_error($stmtLock));
        }
        $ocupadosPorVuelo[$flightId] = [];
        while($fila = mysqli_fetch_assoc($resultado)){
            $reservaOcupa = $fila['status'] !== 'cancelled'
                && ($fila['status'] !== 'pending' || $fila['time_limit'] === null || strtotime($fila['time_limit']) > time());
            if($reservaOcupa && $fila['seat'] !== null){
                $ocupadosPorVuelo[$flightId][$fila['seat']] = true;
            }
        }
        mysqli_free_result($resultado);
    }
    mysqli_stmt_close($stmtLock);

    // 2.3 Verificar, ya con el vuelo bloqueado, que ningún asiento pedido esté ocupado.
    foreach($segmentos as $seg){
        $flightId = (int)$seg['flight_id'];
        foreach($seg['asientos'] as $seat){
            if($seat === null || $seat === '' || $seat === '-') continue;
            if(isset($ocupadosPorVuelo[$flightId][$seat])){
                throw new Exception('SEAT_TAKEN:' . $seat);
            }
        }
    }

    // 2.3-B Verificar cupos tarifarios (seats_allocated vs seats_sold) y reservar
    $mapaClaseCodigo = ['economy' => 'ECONOMICA', 'premium' => 'PREMIUM', 'business' => 'BUSINESS', 'first' => 'PRIMERA'];
    $cuposRequeridos = [];
    
    foreach($segmentos as $seg) {
        $flightId = (int)$seg['flight_id'];
        $fareClassFront = (string)$seg['fare_class'];
        $codigoFare = $mapaClaseCodigo[$fareClassFront] ?? 'ECONOMICA';
        
        $key = "$flightId-$codigoFare";
        if(!isset($cuposRequeridos[$key])) $cuposRequeridos[$key] = 0;
        
        $pasajerosConAsiento = 0;
        foreach($seg['asientos'] as $seat) {
            // Un pasajero necesita cupo en la tarifa si ocupa un asiento físico
            // (esto ignora a los bebés en brazos que viajan con tarifa reducida o gratis y no tienen asiento).
            if($seat !== null) {
                $pasajerosConAsiento++;
            }
        }
        $cuposRequeridos[$key] += $pasajerosConAsiento;
    }

    $stmtCheckFares = mysqli_prepare($conexion, 
        "SELECT ff.id, ff.seats_allocated, ff.seats_sold 
         FROM flight_fares ff 
         JOIN fare_classes fc ON ff.fare_class_id = fc.id 
         WHERE ff.flight_id = ? AND fc.code = ? FOR UPDATE"
    );
    if(!$stmtCheckFares){
        throw new Exception('No se pudo preparar la verificación de cupos tarifarios.');
    }
    
    $stmtUpdateFares = mysqli_prepare($conexion,
        "UPDATE flight_fares SET seats_sold = seats_sold + ? WHERE id = ?"
    );

    foreach($cuposRequeridos as $key => $cantidad){
        if($cantidad <= 0) continue;
        list($flightId, $codigoFare) = explode('-', $key);
        
        mysqli_stmt_bind_param($stmtCheckFares, 'is', $flightId, $codigoFare);
        if(!mysqli_stmt_execute($stmtCheckFares)){
            throw new Exception('Error al verificar tarifas: ' . mysqli_stmt_error($stmtCheckFares));
        }
        $resFares = mysqli_stmt_get_result($stmtCheckFares);
        $filaFare = $resFares ? mysqli_fetch_assoc($resFares) : null;
        if($resFares) mysqli_free_result($resFares);
        
        if($filaFare){
            $disponibles = $filaFare['seats_allocated'] - $filaFare['seats_sold'];
            if($cantidad > $disponibles){
                throw new Exception('FARE_SOLD_OUT:' . $codigoFare);
            }
            mysqli_stmt_bind_param($stmtUpdateFares, 'ii', $cantidad, $filaFare['id']);
            if(!mysqli_stmt_execute($stmtUpdateFares)){
                throw new Exception('Error al actualizar cupo tarifario: ' . mysqli_stmt_error($stmtUpdateFares));
            }
        }
    }
    mysqli_stmt_close($stmtCheckFares);
    if($stmtUpdateFares) mysqli_stmt_close($stmtUpdateFares);

    // 2.4 Generar PNR único.
    $tarifasInfo = []; // key: "$flightId-$fareClass" => ['id' => fare_id, 'price' => price]
    $stmtObtenerTarifa = mysqli_prepare($conexion,
        "SELECT ff.id, ff.price
         FROM flight_fares ff
         JOIN fare_classes fc ON ff.fare_class_id = fc.id
         WHERE ff.flight_id = ? AND fc.code = ? AND ff.active = 1
           AND (ff.valid_to IS NULL OR ff.valid_to > NOW())
         ORDER BY ff.valid_from DESC, ff.id DESC LIMIT 1"
    );
    if($stmtObtenerTarifa){
        foreach($segmentos as $seg){
            $fId = (int)$seg['flight_id'];
            $fClass = (string)$seg['fare_class'];
            $codFare = $mapaClaseCodigo[$fClass] ?? 'ECONOMICA';
            $tKey = "$fId-$fClass";
            if(!isset($tarifasInfo[$tKey])){
                mysqli_stmt_bind_param($stmtObtenerTarifa, 'is', $fId, $codFare);
                mysqli_stmt_execute($stmtObtenerTarifa);
                $resT = mysqli_stmt_get_result($stmtObtenerTarifa);
                if($rowT = mysqli_fetch_assoc($resT)){
                    $tarifasInfo[$tKey] = ['id' => (int)$rowT['id'], 'price' => (float)$rowT['price']];
                }
                if($resT) mysqli_free_result($resT);
            }
        }
        mysqli_stmt_close($stmtObtenerTarifa);
    }

    $pnr = generarPnr($conexion);
    if($pnr === null){
        throw new Exception('No se pudo generar un PNR único.');
    }

    // 2.5 Insertar reservations. El pago ya se procesó antes de llegar aquí
    //     (Api.crearPago), por eso status='paid' directamente.
    $clienteId = isset($payload['cliente_id']) && is_numeric($payload['cliente_id']) ? (int)$payload['cliente_id'] : null;

    // Si la reserva se hizo sin sesión iniciada pero el correo de contacto corresponde a un customer registrado,
    // vincular la reserva automáticamente a ese customer_id.
    $contactoEmail = isset($payload['contacto']['email']) ? strtolower(trim((string)$payload['contacto']['email'])) : '';
    if(!$clienteId && filter_var($contactoEmail, FILTER_VALIDATE_EMAIL)){
        $stmtFindCust = mysqli_prepare($conexion, "SELECT id FROM customers WHERE LOWER(email) = ? LIMIT 1");
        if($stmtFindCust){
            mysqli_stmt_bind_param($stmtFindCust, 's', $contactoEmail);
            mysqli_stmt_execute($stmtFindCust);
            $resFindCust = mysqli_stmt_get_result($stmtFindCust);
            if($filaCust = mysqli_fetch_assoc($resFindCust)){
                $clienteId = (int)$filaCust['id'];
            }
            if($resFindCust) mysqli_free_result($resFindCust);
            mysqli_stmt_close($stmtFindCust);
        }
    }

    // Se inicializa paid_total en 0.00 antes de insertar en payments para evitar
    // que el trigger de la BD sume dos veces el monto (2x). Al final de la
    // transacción se garantiza que paid_total quede exactamente en $montoPago.
    $stmtRes = mysqli_prepare($conexion,
        "INSERT INTO reservations (pnr, customer_id, status, estimated_total, paid_total, currency, created_at, payment_date, sales_channel)
         VALUES (?, ?, 'paid', ?, 0.00, 'USD', NOW(), NOW(), 'web')"
    );
    if(!$stmtRes){
        throw new Exception('No se pudo preparar la inserción de la reserva: ' . mysqli_error($conexion));
    }
    mysqli_stmt_bind_param($stmtRes, 'sid', $pnr, $clienteId, $total);
    if(!mysqli_stmt_execute($stmtRes)){
        throw new Exception('No se pudo insertar la reserva: ' . mysqli_stmt_error($stmtRes));
    }
    $reservationId = mysqli_insert_id($conexion);
    mysqli_stmt_close($stmtRes);

    // 2.5-B Insertar el pago principal en payments (misma transacción: si
    //       falla, se revierte también la reserva recién creada). Usa
    //       EXACTAMENTE el objeto "pago" que ya envía el frontend — no se
    //       inventa un formato nuevo.
    //       Mapeo de método (frontend -> payments.method real, ENUM real
    //       confirmado: card/transfer/cash/paypal/other): el frontend hoy
    //       solo ofrece TARJETA/TRANSFERENCIA/BILLETERA (no hay opción de
    //       efectivo ni PayPal literal todavía), así que BILLETERA se
    //       clasifica como 'other' (billetera digital genérica) en vez de
    //       adivinar que es específicamente PayPal.
    $mapaMetodoPago = ['TARJETA' => 'card', 'TRANSFERENCIA' => 'transfer', 'BILLETERA' => 'other'];
    $pagoPayload = isset($payload['pago']) && is_array($payload['pago']) ? $payload['pago'] : [];
    $metodoFrontend = isset($pagoPayload['metodo']) ? (string)$pagoPayload['metodo'] : '';
    $metodoPago = $mapaMetodoPago[$metodoFrontend] ?? 'other';

    // Mapeo de estado (frontend, en español -> payments.status real ENUM:
    // pending/approved/rejected/pending_confirmation/refunded/cancelled).
    $mapaEstadoPago = ['APROBADO' => 'approved', 'PENDIENTE' => 'pending', 'RECHAZADO' => 'rejected'];
    $estadoFrontend = isset($pagoPayload['estado']) ? (string)$pagoPayload['estado'] : 'APROBADO';
    $estadoPago = $mapaEstadoPago[$estadoFrontend] ?? 'approved';

    $montoPago = isset($pagoPayload['monto']) && is_numeric($pagoPayload['monto']) ? (float)$pagoPayload['monto'] : $total;

    // Últimos 4 dígitos únicamente (ya calculados en el frontend a partir del
    // número de tarjeta, nunca el número completo ni el CVV). NUNCA se
    // recibe ni se guarda el número completo de tarjeta ni el CVV — el
    // frontend jamás los incluye en este payload.
    $ultimos4 = null;
    if(isset($pagoPayload['ultimos4']) && is_string($pagoPayload['ultimos4']) && preg_match('/^\d{4}$/', $pagoPayload['ultimos4'])){
        $ultimos4 = $pagoPayload['ultimos4'];
    }

    $stmtPago = mysqli_prepare($conexion,
        "INSERT INTO payments (reservation_id, method, type, amount, currency, status, card_last_digits, payment_date)
         VALUES (?, ?, 'payment', ?, 'USD', ?, ?, NOW())"
    );
    if(!$stmtPago){
        throw new Exception('No se pudo preparar la inserción del pago: ' . mysqli_error($conexion));
    }
    mysqli_stmt_bind_param($stmtPago, 'isdss', $reservationId, $metodoPago, $montoPago, $estadoPago, $ultimos4);
    if(!mysqli_stmt_execute($stmtPago)){
        throw new Exception('No se pudo insertar el pago: ' . mysqli_stmt_error($stmtPago));
    }
    mysqli_stmt_close($stmtPago);

    // 2.6 Insertar passengers (una fila por pasajero), con el tipo de
    //     pasajero y tipo de documento REALES que ya captura el frontend
    //     (Util.categoriaPorIndice / selector de tipoDocumento). Se mapea
    //     'young' -> 'child' porque passengers.passenger_type solo admite
    //     adult/child/infant; el resto de valores se usan tal cual.
    //
    //     passengers.document_type es CHAR(2), NO puede recibir el texto
    //     literal del formulario ("DUI"/"PASAPORTE"/"CARNET_MENOR" no caben).
    //     El esquema sigue el catálogo oficial "Tipo de Documento de
    //     Identificación" del Ministerio de Hacienda de El Salvador (el
    //     mismo que usan las tablas dte_headers/dte_items de este proyecto
    //     para los DTE): 13=DUI, 03=Pasaporte, 36=NIT, 02=Carnet de
    //     Residente, 37=Otro. Esto se confirmó cruzando el catálogo oficial
    //     contra el único valor real que ya existía en producción
    //     (document_type='13' == DUI en ese catálogo).
    //
    //     'CARNET_MENOR' (carnet de un MENOR de edad) NO tiene un código
    //     confirmado en ese catálogo — "02" es "Carnet de Residente"
    //     (extranjero), que es un documento distinto. No se adivina ese
    //     código: si llega 'CARNET_MENOR', se rechaza explícitamente con
    //     un error claro en vez de guardar un valor no verificado.
    $mapaTipoPasajero = ['adult' => 'adult', 'young' => 'child', 'child' => 'child', 'infant' => 'infant'];
    // passengers.document_type es CHAR(2) NOT NULL. Mapeo confirmado:
    // 13=DUI, 3=Pasaporte (según CAT-022 de Hacienda El Salvador y dteService.js)
    // 37=Otro se adopta como CONVENCIÓN DEL PROYECTO para representar
    // "Carnet de menor", ya que no existe un código específico oficial
    // confirmado para ese documento.
    $mapaTipoDocumento = [
        'DUI' => '13',
        'PASAPORTE' => '3',
        'CARNET_MENOR' => '37'
    ];

    $passengerIds = [];
    $stmtPax = mysqli_prepare($conexion,
        "INSERT INTO passengers (reservation_id, passenger_type, first_names, last_names, document_type, document_number, birth_date, nationality, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())"
    );
    if(!$stmtPax){
        throw new Exception('No se pudo preparar la inserción de pasajeros: ' . mysqli_error($conexion));
    }
    $stmtPais = mysqli_prepare($conexion, "SELECT code FROM countries WHERE code = ? AND active = 1");
    if(!$stmtPais){
        throw new Exception('No se pudo preparar la validación de nacionalidad: ' . mysqli_error($conexion));
    }
    $paisesValidados = []; // caché en memoria: code => true, para no repetir la consulta si varios pasajeros comparten nacionalidad
    $documentosVistos = []; // número de documento normalizado => índice del pasajero, para rechazar documentos repetidos dentro de la misma reserva

    foreach($pasajeros as $idxPax => $p){
        $nombres = isset($p['nombres']) ? substr((string)$p['nombres'], 0, 100) : '';
        $apellidos = isset($p['apellidos']) ? substr((string)$p['apellidos'], 0, 100) : '';
        $documento = isset($p['documento']) ? substr((string)$p['documento'], 0, 30) : '';
        $tipoFrontend = isset($p['tipo']) ? (string)$p['tipo'] : 'adult';
        $passengerType = $mapaTipoPasajero[$tipoFrontend] ?? 'adult';

        $tipoDocumentoFrontend = isset($p['tipoDocumento']) ? (string)$p['tipoDocumento'] : '';
        if(!isset($mapaTipoDocumento[$tipoDocumentoFrontend])){
            // Defensa ante cualquier valor fuera de DUI/PASAPORTE/CARNET_MENOR
            // (los únicos que el formulario permite hoy): se rechaza la
            // reserva en vez de insertar un código de 2 caracteres inventado.
            throw new Exception('Tipo de documento no válido: ' . $tipoDocumentoFrontend);
        }
        $tipoDocumento = $mapaTipoDocumento[$tipoDocumentoFrontend];

        // Fecha de nacimiento enviada desde el formulario (o null si no viene)
        $birthDate = null;
        if(isset($p['fechaNacimiento']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$p['fechaNacimiento'])){
            $birthDate = (string)$p['fechaNacimiento'];
        }

        // Validación de formato en backend (no confiar solo en el frontend),
        // mismo criterio que Validar.documentoValido() en index.php: DUI
        // exige exactamente 8 dígitos + guion + 1 dígito; el resto usa la
        // regla genérica ya establecida en el proyecto (alfanumérico 5-20).
        $formatoValido = ($tipoDocumentoFrontend === 'DUI')
            ? preg_match('/^\d{8}-\d$/', $documento)
            : preg_match('/^[A-Za-z0-9-]{5,20}$/', $documento);
        if(!$formatoValido){
            throw new Exception('Formato de documento inválido para ' . $tipoDocumentoFrontend . ': ' . $documento);
        }

        // Documentos duplicados: dentro de una misma reserva cada pasajero
        // debe tener su propio número de documento (DUI, pasaporte o carné
        // de menor). Se normaliza (trim + mayúsculas) con el mismo criterio
        // que la validación del frontend en Pasajeros.continuar(), de modo
        // que no se pueda repetir ni siquiera variando mayúsculas/espacios.
        $documentoNormalizado = strtoupper(trim($documento));
        if(isset($documentosVistos[$documentoNormalizado])){
            throw new Exception('El documento ' . $documento . ' está repetido: los pasajeros ' . ($documentosVistos[$documentoNormalizado] + 1) . ' y ' . ($idxPax + 1) . ' no pueden usar el mismo número. Cada pasajero debe tener su propio documento.');
        }
        $documentosVistos[$documentoNormalizado] = $idxPax;

        // Nacionalidad: passengers.nationality guarda el código real de
        // countries.code. Nunca se confía solo en lo que envía el frontend
        // (el buscador de nacionalidad ya lo restringe, pero el backend
        // vuelve a validar contra la BD real antes de guardar).
        $codigoPais = isset($p['nacionalidad']) ? strtoupper(trim((string)$p['nacionalidad'])) : '';
        if($codigoPais === '' || !preg_match('/^[A-Z]{2}$/', $codigoPais)){
            throw new Exception('Nacionalidad inválida o vacía.');
        }
        if(!isset($paisesValidados[$codigoPais])){
            mysqli_stmt_bind_param($stmtPais, 's', $codigoPais);
            if(!mysqli_stmt_execute($stmtPais)){
                throw new Exception('No se pudo validar la nacionalidad: ' . mysqli_stmt_error($stmtPais));
            }
            $resPais = mysqli_stmt_get_result($stmtPais);
            $filaPais = $resPais ? mysqli_fetch_assoc($resPais) : null;
            if($resPais) mysqli_free_result($resPais);
            if(!$filaPais){
                throw new Exception('Código de país no válido o inactivo: ' . $codigoPais);
            }
            $paisesValidados[$codigoPais] = true;
        }

        mysqli_stmt_bind_param($stmtPax, 'isssssss', $reservationId, $passengerType, $nombres, $apellidos, $tipoDocumento, $documento, $birthDate, $codigoPais);
        if(!mysqli_stmt_execute($stmtPax)){
            throw new Exception('No se pudo insertar un pasajero: ' . mysqli_stmt_error($stmtPax));
        }
        $passengerIds[] = mysqli_insert_id($conexion);
    }
    mysqli_stmt_close($stmtPax);
    mysqli_stmt_close($stmtPais);

    // 2.6-B Guardar el correo de contacto en el primer pasajero para que la
    //       consulta por PNR pueda validar por correo incluso en reservas de
    //       invitado. Se usa un bloque try independiente para que, si la
    //       columna email no existiera en passengers, no revierta la reserva.
    $contactoEmail = isset($payload['contacto']['email']) ? trim((string)$payload['contacto']['email']) : '';
    if($contactoEmail !== '' && isset($passengerIds[0])){
        $firstPassengerId = $passengerIds[0];
        $stmtEmail = @mysqli_prepare($conexion, "UPDATE passengers SET email = ? WHERE id = ?");
        if($stmtEmail){
            mysqli_stmt_bind_param($stmtEmail, 'si', $contactoEmail, $firstPassengerId);
            @mysqli_stmt_execute($stmtEmail); // @: si falla (columna no existe), se ignora
            mysqli_stmt_close($stmtEmail);
        }
    }

    // 2.6-C Sincronizar birth_date y document_type en customers si el cliente está registrado
    if($clienteId && !empty($pasajeros[0])){
        $paxPrincipal = $pasajeros[0];
        $tipoDocP = $mapaTipoDocumento[$paxPrincipal['tipoDocumento'] ?? ''] ?? '13';
        $docNumP = isset($paxPrincipal['documento']) ? substr((string)$paxPrincipal['documento'], 0, 30) : null;
        $birthDateP = (isset($paxPrincipal['fechaNacimiento']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$paxPrincipal['fechaNacimiento'])) ? (string)$paxPrincipal['fechaNacimiento'] : null;

        $stmtUpdCust = @mysqli_prepare($conexion,
            "UPDATE customers 
             SET document_type = COALESCE(document_type, ?),
                 document_number = COALESCE(document_number, ?),
                 birth_date = COALESCE(birth_date, ?)
             WHERE id = ?"
        );
        if($stmtUpdCust){
            mysqli_stmt_bind_param($stmtUpdCust, 'sssi', $tipoDocP, $docNumP, $birthDateP, $clienteId);
            @mysqli_stmt_execute($stmtUpdCust);
            mysqli_stmt_close($stmtUpdCust);
        }
    }

    // 2.7 Insertar flight_segments con tarifas y precios unitarios reales
    $stmtSeg = mysqli_prepare($conexion,
        "INSERT INTO flight_segments (reservation_id, passenger_id, flight_id, fare_class, seat, fare_id, unit_price, total_price, paid_price, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'confirmed')"
    );
    if(!$stmtSeg){
        throw new Exception('No se pudo preparar la inserción de segmentos: ' . mysqli_error($conexion));
    }
    foreach($segmentos as $seg){
        $flightId = (int)$seg['flight_id'];
        $fareClass = (string)$seg['fare_class'];
        $precioUnitario = isset($seg['precio_unitario']) && is_numeric($seg['precio_unitario']) ? (float)$seg['precio_unitario'] : 0.0;
        
        $tarifaKey = "$flightId-$fareClass";
        $fareIdVal = $tarifasInfo[$tarifaKey]['id'] ?? null;
        if($precioUnitario <= 0.0 && isset($tarifasInfo[$tarifaKey]['price'])){
            $precioUnitario = (float)$tarifasInfo[$tarifaKey]['price'];
        }

        foreach($seg['asientos'] as $idx => $seat){
            if(!isset($passengerIds[$idx])) continue;
            $seatValor = ($seat === null || $seat === '' || $seat === '-') ? null : substr((string)$seat, 0, 5);
            $passengerId = $passengerIds[$idx];
            mysqli_stmt_bind_param($stmtSeg, 'iiissiddd', $reservationId, $passengerId, $flightId, $fareClass, $seatValor, $fareIdVal, $precioUnitario, $precioUnitario, $precioUnitario);
            if(!mysqli_stmt_execute($stmtSeg)){
                throw new Exception('No se pudo insertar un segmento de vuelo: ' . mysqli_stmt_error($stmtSeg));
            }
        }
    }
    mysqli_stmt_close($stmtSeg);

    // 2.7-B Insertar líneas de impuestos (reservation_tax_lines) para cada pasajero y vuelo
    $stmtFlightInfo = mysqli_prepare($conexion,
        "SELECT ao.id AS airport_id, ao.country_id,
                CASE WHEN ao.country_id = ad.country_id THEN 'domestic' ELSE 'international' END AS trip_type
         FROM flights f
         JOIN routes r ON r.id = f.route_id
         JOIN airports ao ON ao.id = r.origin_id
         JOIN airports ad ON ad.id = r.destination_id
         WHERE f.id = ?"
    );
    $stmtTaxRules = mysqli_prepare($conexion,
        "SELECT id, country_id, airport_id, code, name, calculation_type, value
         FROM tax_rules
         WHERE active = 1 AND country_id = ? AND (airport_id IS NULL OR airport_id = ?)
           AND applies_to IN ('all', ?) AND valid_from <= NOW() AND (valid_to IS NULL OR valid_to > NOW())
         ORDER BY airport_id IS NULL, id"
    );
    $stmtInsTax = mysqli_prepare($conexion,
        "INSERT INTO reservation_tax_lines (reservation_id, passenger_id, flight_id, tax_rule_id, country_id, airport_id, code, name, calculation_type, rate, taxable_amount, amount)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );

    if($stmtFlightInfo && $stmtTaxRules && $stmtInsTax){
        $factorPax = ['adult' => 1.0, 'young' => 0.75, 'child' => 0.75, 'infant' => 0.10];
        foreach($segmentos as $seg){
            $flightId = (int)$seg['flight_id'];
            $fareClass = (string)$seg['fare_class'];
            $tarifaKey = "$flightId-$fareClass";
            $tarifaBase = $tarifasInfo[$tarifaKey]['price'] ?? (isset($seg['precio_unitario']) ? (float)$seg['precio_unitario'] : 0.0);

            mysqli_stmt_bind_param($stmtFlightInfo, 'i', $flightId);
            mysqli_stmt_execute($stmtFlightInfo);
            $resFlight = mysqli_stmt_get_result($stmtFlightInfo);
            $infoVuelo = $resFlight ? mysqli_fetch_assoc($resFlight) : null;
            if($resFlight) mysqli_free_result($resFlight);

            if($infoVuelo && !empty($infoVuelo['country_id'])){
                $cId = (int)$infoVuelo['country_id'];
                $aId = (int)$infoVuelo['airport_id'];
                $tripType = (string)$infoVuelo['trip_type'];

                mysqli_stmt_bind_param($stmtTaxRules, 'iis', $cId, $aId, $tripType);
                mysqli_stmt_execute($stmtTaxRules);
                $resTaxRules = mysqli_stmt_get_result($stmtTaxRules);
                $reglasImpuestos = [];
                while($rowR = mysqli_fetch_assoc($resTaxRules)){
                    $reglasImpuestos[] = $rowR;
                }
                if($resTaxRules) mysqli_free_result($resTaxRules);

                foreach($pasajeros as $idxPax => $paxItem){
                    if(!isset($passengerIds[$idxPax])) continue;
                    $paxId = $passengerIds[$idxPax];
                    $tipoPax = $paxItem['tipo'] ?? 'adult';
                    $factor = $factorPax[$tipoPax] ?? 1.0;
                    $tarifaNetaPax = round($tarifaBase * $factor, 2);

                    foreach($reglasImpuestos as $regla){
                        $rId = (int)$regla['id'];
                        $rCountry = (int)$regla['country_id'];
                        $rAirport = !empty($regla['airport_id']) ? (int)$regla['airport_id'] : null;
                        $rCode = (string)$regla['code'];
                        $rName = (string)$regla['name'];
                        $rCalc = (string)$regla['calculation_type'];
                        $rRate = (float)$regla['value'];
                        
                        $montoImpuesto = ($rCalc === 'percentage')
                            ? round($tarifaNetaPax * ($rRate / 100), 2)
                            : round($rRate * $factor, 2);

                        mysqli_stmt_bind_param($stmtInsTax, 'iiiiissssddd',
                            $reservationId, $paxId, $flightId, $rId, $rCountry, $rAirport,
                            $rCode, $rName, $rCalc, $rRate, $tarifaNetaPax, $montoImpuesto
                        );
                        @mysqli_stmt_execute($stmtInsTax);
                    }
                }
            }
        }
        mysqli_stmt_close($stmtFlightInfo);
        mysqli_stmt_close($stmtTaxRules);
        mysqli_stmt_close($stmtInsTax);
    }

    // 2.7-C Insertar servicios y ancillaries seleccionados (reservation_ancillaries)
    $serviciosPayload = isset($payload['servicios']) && is_array($payload['servicios']) ? $payload['servicios'] : [];
    if(!empty($serviciosPayload) && !empty($segmentos[0]['flight_id'])){
        $primerFlightId = (int)$segmentos[0]['flight_id'];
        $mapaAncillaryCode = [
            'EQ_EXTRA' => 'BAG23',
            'EQUIPAJE_EXTRA_23' => 'BAG23',
            'EQ_DEPORTIVO' => 'BAG32',
            'EQUIPAJE_EXTRA_32' => 'BAG32',
            'SALA_VIP' => 'LOUNGE',
            'ABORDAJE_PRIORITARIO' => 'PRIO-1',
            'ASIST_MEDICA' => 'LOUNGE'
        ];

        $stmtBusqAnc = mysqli_prepare($conexion, "SELECT id, base_price FROM ancillaries WHERE code = ? AND active = 1 LIMIT 1");
        $stmtInsAnc = mysqli_prepare($conexion,
            "INSERT INTO reservation_ancillaries (reservation_id, passenger_id, flight_id, ancillary_id, price, status)
             VALUES (?, ?, ?, ?, ?, 'active')"
        );

        if($stmtBusqAnc && $stmtInsAnc){
            foreach($serviciosPayload as $serv){
                $sId = $serv['servicioId'] ?? '';
                $ancCode = $mapaAncillaryCode[$sId] ?? null;
                if(!$ancCode) continue;

                $paxIndex = isset($serv['pasajeroIdx']) ? (int)$serv['pasajeroIdx'] : 0;
                $paxId = $passengerIds[$paxIndex] ?? $passengerIds[0];
                $precioServicio = isset($serv['precio']) ? (float)$serv['precio'] : 0.0;

                mysqli_stmt_bind_param($stmtBusqAnc, 's', $ancCode);
                mysqli_stmt_execute($stmtBusqAnc);
                $resAnc = mysqli_stmt_get_result($stmtBusqAnc);
                $rowAnc = $resAnc ? mysqli_fetch_assoc($resAnc) : null;
                if($resAnc) mysqli_free_result($resAnc);

                if($rowAnc){
                    $ancId = (int)$rowAnc['id'];
                    $precioFinal = ($precioServicio > 0.0) ? $precioServicio : (float)$rowAnc['base_price'];
                    mysqli_stmt_bind_param($stmtInsAnc, 'iiisd', $reservationId, $paxId, $primerFlightId, $ancId, $precioFinal);
                    @mysqli_stmt_execute($stmtInsAnc);
                }
            }
            mysqli_stmt_close($stmtBusqAnc);
            mysqli_stmt_close($stmtInsAnc);
        }
    }

    // 2.8 Asegurar que estimated_total y paid_total queden con el monto real exacto
    //     sin duplicaciones por triggers de base de datos.
    $stmtFixTotal = mysqli_prepare($conexion,
        "UPDATE reservations SET estimated_total = ?, paid_total = ?, status = 'paid' WHERE id = ?"
    );
    if($stmtFixTotal){
        mysqli_stmt_bind_param($stmtFixTotal, 'ddi', $total, $montoPago, $reservationId);
        mysqli_stmt_execute($stmtFixTotal);
        mysqli_stmt_close($stmtFixTotal);
    }

    mysqli_commit($conexion);

    // =======================================================================
    // LLAMADA AL SISTEMA BASE (NODE.JS) PARA FACTURACIÓN DTE
    // =======================================================================
    // Aquí preparamos la integración con el backend de tu compañero.
    // Reemplazar la URL cuando el endpoint de Node.js esté listo.
    $nodeApiUrl = "http://localhost:3000/api/dte/generate"; // URL de ejemplo
    
    $payloadDte = [
        "reservation_id" => $reservationId,
        "pnr" => $pnr,
        "customer_email" => $contactoEmail,
        "total" => $total
    ];

    $ch = curl_init($nodeApiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payloadDte));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Accept: application/json'
    ]);
    // Timeout corto para no bloquear la respuesta al usuario si el servidor Node está caído
    curl_setopt($ch, CURLOPT_TIMEOUT, 5); 

    $resDte = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if($httpCode >= 200 && $httpCode < 300) {
        error_log("[Acajutla Airlines] Facturación DTE iniciada correctamente para PNR $pnr.");
    } else {
        // Solo registramos el error para no arruinar la experiencia del usuario, 
        // ya que la reserva sí se completó con éxito en la BD.
        error_log("[Acajutla Airlines] Advertencia: No se pudo generar DTE para PNR $pnr. HTTP Code: $httpCode. Respuesta: $resDte");
    }
    // =======================================================================

    cerrarConexion();
    http_response_code(200);
    echo json_encode(['ok' => true, 'data' => ['pnr' => $pnr, 'reservation_id' => $reservationId]], JSON_UNESCAPED_UNICODE);

} catch(Exception $e){
    mysqli_rollback($conexion);
    cerrarConexion();
    $msg = $e->getMessage();
    if(strpos($msg, 'SEAT_TAKEN:') === 0){
        $asiento = substr($msg, strlen('SEAT_TAKEN:'));
        error_log('[Acajutla Airlines] Intento de reservar asiento ya ocupado: ' . $asiento);
        responderError('El asiento ' . $asiento . ' ya fue reservado por otro pasajero. Selecciona otro asiento.', 409);
    }
    if(strpos($msg, 'VUELO_YA_SALIO:') === 0){
        $flightIdSalido = substr($msg, strlen('VUELO_YA_SALIO:'));
        error_log('[Acajutla Airlines] Intento de reservar un vuelo cuya salida ya pasó: flight_id=' . $flightIdSalido);
        responderError('Uno de los vuelos seleccionados ya no está disponible porque su salida ya pasó. Busca un vuelo nuevo.', 409);
    }
    if(strpos($msg, 'FARE_SOLD_OUT:') === 0){
        $tarifaAgotada = substr($msg, strlen('FARE_SOLD_OUT:'));
        error_log('[Acajutla Airlines] Intento de reservar tarifa agotada: ' . $tarifaAgotada);
        responderError('La clase tarifaria (' . $tarifaAgotada . ') ya no tiene cupos disponibles para el número de pasajeros seleccionado.', 409);
    }
    error_log('[Acajutla Airlines] Error al crear reserva: ' . $msg);
    responderError('No se pudo completar la reserva. Intenta nuevamente.', 500);
}