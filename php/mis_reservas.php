<?php
/**
 * =====================================================================
 * ACAJUTLA AIRLINES — php/mis_reservas.php
 *
 * Endpoint: GET php/mis_reservas.php?customer_id=NN
 *
 * Lista REAL (no mock) de las reservas del cliente autenticado, usando
 * la misma relación que ya usa php/crear_reserva.php al crear una
 * reserva: reservations.customer_id = customers.id.
 *
 * IMPORTANTE — limitación real y honesta del esquema actual: una reserva
 * hecha SIN haber iniciado sesión (invitado) se guarda con
 * reservations.customer_id = NULL (así lo hace crear_reserva.php). Esa
 * reserva de invitado NO puede vincularse retroactivamente a una cuenta
 * por este endpoint, porque no existe ninguna columna que guarde el
 * correo de contacto en `reservations` ni en `passengers` (crear_reserva.php
 * no lo inserta). Esto no es un bug de este endpoint: es el
 * comportamiento correcto dado lo que el esquema realmente guarda hoy.
 * Solo se listan reservas hechas MIENTRAS la cuenta estaba autenticada.
 *
 * Mapeo de reservations.status (ENUM real) a las etiquetas en español
 * que ya usa Vistas (mismo mapa que usa el resto del proyecto para
 * mostrar reservas, sin inventar estados nuevos):
 *   pending   -> PENDIENTE
 *   waiting   -> PENDIENTE
 *   paid      -> CONFIRMADA
 *   confirmed -> CONFIRMADA
 *   completed -> CONFIRMADA
 *   cancelled -> CANCELADA
 *
 * Respuesta:
 *   { "ok": true, "data": [ {pnr, creado_en, estado, total}, ... ] }
 *   { "ok": false, "error": "..." }
 * =====================================================================
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/conexion.php';

function responderError($mensaje, $codigo){
    http_response_code($codigo);
    echo json_encode(['ok' => false, 'error' => $mensaje], JSON_UNESCAPED_UNICODE);
    exit;
}

if(!CONEXION_OK){
    responderError('No se pudo conectar con la base de datos.', 500);
}

$customerId = isset($_GET['customer_id']) ? $_GET['customer_id'] : '';
if(!ctype_digit((string)$customerId)){
    cerrarConexion();
    responderError('customer_id debe ser un identificador numérico válido.', 400);
}
$customerIdInt = (int)$customerId;

$mapaEstado = [
    'pending' => 'PENDIENTE', 'waiting' => 'PENDIENTE',
    'paid' => 'CONFIRMADA', 'confirmed' => 'CONFIRMADA', 'completed' => 'CONFIRMADA',
    'cancelled' => 'CANCELADA',
];

$sql = "SELECT pnr, status, estimated_total, paid_total, created_at
        FROM reservations
        WHERE customer_id = ?
        ORDER BY created_at DESC";
$stmt = mysqli_prepare($conexion, $sql);
if(!$stmt){
    error_log('[Acajutla Airlines] Error al preparar mis_reservas: ' . mysqli_error($conexion));
    cerrarConexion();
    responderError('No se pudieron obtener tus reservas.', 500);
}
mysqli_stmt_bind_param($stmt, 'i', $customerIdInt);
if(!mysqli_stmt_execute($stmt)){
    error_log('[Acajutla Airlines] Error al ejecutar mis_reservas: ' . mysqli_stmt_error($stmt));
    mysqli_stmt_close($stmt);
    cerrarConexion();
    responderError('No se pudieron obtener tus reservas.', 500);
}
$resultado = mysqli_stmt_get_result($stmt);
if($resultado === false){
    mysqli_stmt_close($stmt);
    cerrarConexion();
    responderError('No se pudieron obtener tus reservas.', 500);
}

$reservas = [];
while($fila = mysqli_fetch_assoc($resultado)){
    $estadoReal = strtolower((string)$fila['status']);
    $total = $fila['paid_total'] !== null ? (float)$fila['paid_total'] : (float)$fila['estimated_total'];
    $reservas[] = [
        'pnr' => $fila['pnr'],
        'creado_en' => $fila['created_at'],
        'estado' => $mapaEstado[$estadoReal] ?? strtoupper($estadoReal),
        'total' => $total
    ];
}
mysqli_free_result($resultado);
mysqli_stmt_close($stmt);
cerrarConexion();

http_response_code(200);
echo json_encode(['ok' => true, 'data' => $reservas], JSON_UNESCAPED_UNICODE);
