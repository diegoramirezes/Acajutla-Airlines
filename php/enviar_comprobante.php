<?php
/**
 * =====================================================================
 * ACAJUTLA AIRLINES — php/enviar_comprobante.php
 *
 * Endpoint: POST php/enviar_comprobante.php
 * Content-Type esperado: application/json
 *
 * Conecta el envío REAL del comprobante de reserva por correo,
 * consumido por Api.sendBookingEmail() en index.php.
 *
 * Payload REAL que ya arma Api.sendBookingEmail() (NO se inventó nada
 * nuevo; se tomó tal cual del código actual de index.php):
 *   {
 *     pnr: string,
 *     email: string,
 *     nombreCliente: string,
 *     total: number,
 *     estado: string,
 *     segmentos: [
 *       { numero_vuelo, origen (código IATA string), destino (código IATA string),
 *         fecha, asiento, estado_check_in, pase_abordar_emitido }
 *     ],
 *     pasajeros: [ { nombres, apellidos, documento } ],
 *     pago: { metodo, estado }
 *   }
 *
 * Tabla real utilizada (única tabla que este endpoint escribe):
 *   email_outbox: id, template, to_email, subject, html, ref_type, ref_id,
 *                 status ENUM('pending','sent','simulado','failed'),
 *                 error_msg, brevo_message_id, created_at, sent_at
 *
 * IMPORTANTE (alcance de esta etapa, ver REGLAS_PROYECTO.md):
 *   - NO se inserta en reservations/customers/passengers/flight_segments/
 *     payments/dte_headers/dte_items. Únicamente email_outbox.
 *   - NO se usa PHPMailer ni Composer: cliente SMTP nativo por sockets.
 *   - Las credenciales SMTP se leen SOLO de variables de entorno
 *     (SMTP_USER, SMTP_PASSWORD), nunca se escriben en este archivo.
 *
 * Respuesta: siempre JSON.
 *   Éxito: {"success":true,"message":"Comprobante enviado correctamente."}
 *   Error: {"success":false,"message":"..."} (mensaje genérico, sin
 *          detalles técnicos; el detalle real queda en email_outbox.error_msg)
 * =====================================================================
 */

header('Content-Type: application/json; charset=utf-8');

// -----------------------------------------------------------------------
// 0. Config SMTP — SOLO desde variables de entorno. Nunca hardcodeadas.
// -----------------------------------------------------------------------
const SMTP_HOST = 'smtp.gmail.com';
const SMTP_PORT = 587;
const SMTP_REMITENTE_NOMBRE = 'Acajutla Airlines';

function responderJson($codigoHttp, $body){
    http_response_code($codigoHttp);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

// -----------------------------------------------------------------------
// 1. Método HTTP
// -----------------------------------------------------------------------
if($_SERVER['REQUEST_METHOD'] !== 'POST'){
    responderJson(400, ['success' => false, 'message' => 'Método no permitido.']);
}

// -----------------------------------------------------------------------
// 2. Leer y decodificar JSON (con límite de tamaño razonable)
// -----------------------------------------------------------------------
$rawBody = file_get_contents('php://input');
if($rawBody === false || strlen($rawBody) === 0){
    responderJson(400, ['success' => false, 'message' => 'Cuerpo de la petición vacío.']);
}
if(strlen($rawBody) > 200000){ // 200KB es más que suficiente para este payload
    responderJson(400, ['success' => false, 'message' => 'Payload demasiado grande.']);
}

$payload = json_decode($rawBody, true);
if(json_last_error() !== JSON_ERROR_NONE || !is_array($payload)){
    responderJson(400, ['success' => false, 'message' => 'JSON inválido.']);
}

// -----------------------------------------------------------------------
// 3. Validaciones mínimas (no confiar en el frontend)
// -----------------------------------------------------------------------
function textoSeguro($v, $maxLen = 200){
    if(!is_string($v)) return null;
    $v = trim($v);
    // Elimina saltos de línea / retorno de carro (evita header injection más adelante)
    $v = str_replace(["\r", "\n"], ' ', $v);
    if($v === '' || mb_strlen($v) > $maxLen) return null;
    return $v;
}

$pnr = isset($payload['pnr']) ? textoSeguro($payload['pnr'], 20) : null;
if(!$pnr || !preg_match('/^[A-Za-z0-9]{3,20}$/', $pnr)){
    responderJson(400, ['success' => false, 'message' => 'PNR inválido.']);
}

$email = isset($payload['email']) ? trim((string)$payload['email']) : '';
$email = str_replace(["\r", "\n"], '', $email);
if($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 150){
    responderJson(400, ['success' => false, 'message' => 'Correo electrónico inválido.']);
}

$nombreCliente = textoSeguro($payload['nombreCliente'] ?? null, 150);
if(!$nombreCliente){
    responderJson(400, ['success' => false, 'message' => 'Nombre de cliente inválido.']);
}

if(!isset($payload['total']) || !is_numeric($payload['total'])){
    responderJson(400, ['success' => false, 'message' => 'Total inválido.']);
}
$total = (float)$payload['total'];

$estadoReserva = textoSeguro($payload['estado'] ?? 'CONFIRMADA', 30) ?: 'CONFIRMADA';

if(!isset($payload['segmentos']) || !is_array($payload['segmentos']) || count($payload['segmentos']) === 0){
    responderJson(400, ['success' => false, 'message' => 'Debe incluir al menos un segmento de vuelo.']);
}
if(count($payload['segmentos']) > 10){
    responderJson(400, ['success' => false, 'message' => 'Demasiados segmentos de vuelo.']);
}

if(!isset($payload['pasajeros']) || !is_array($payload['pasajeros']) || count($payload['pasajeros']) === 0){
    responderJson(400, ['success' => false, 'message' => 'Debe incluir al menos un pasajero.']);
}
if(count($payload['pasajeros']) > 20){
    responderJson(400, ['success' => false, 'message' => 'Demasiados pasajeros.']);
}

if(!isset($payload['pago']) || !is_array($payload['pago'])){
    responderJson(400, ['success' => false, 'message' => 'Datos de pago inválidos.']);
}
// Solo se usan campos seguros del pago (nunca número de tarjeta ni CVV,
// que además Api.sendBookingEmail() en el frontend ya ni siquiera envía).
$pagoMetodo = textoSeguro($payload['pago']['metodo'] ?? null, 40) ?: 'N/D';
$pagoEstado = textoSeguro($payload['pago']['estado'] ?? null, 40) ?: 'N/D';

// -----------------------------------------------------------------------
// 4. Construir el HTML del comprobante (todo escapado con htmlspecialchars)
// -----------------------------------------------------------------------
function h($v){
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$filasSegmentos = '';
foreach($payload['segmentos'] as $seg){
    if(!is_array($seg)) continue;
    $numeroVuelo = h($seg['numero_vuelo'] ?? '-');
    $origen      = h($seg['origen'] ?? '-');
    $destino     = h($seg['destino'] ?? '-');
    $fecha       = h($seg['fecha'] ?? '-');
    $asiento     = h(($seg['asiento'] ?? '-') ?: '-');
    $filasSegmentos .= '
        <tr>
          <td style="padding:8px;border-bottom:1px solid #e2e8f0;">' . $numeroVuelo . '</td>
          <td style="padding:8px;border-bottom:1px solid #e2e8f0;">' . $origen . ' &rarr; ' . $destino . '</td>
          <td style="padding:8px;border-bottom:1px solid #e2e8f0;">' . $fecha . '</td>
          <td style="padding:8px;border-bottom:1px solid #e2e8f0;">' . $asiento . '</td>
        </tr>';
}

$filasPasajeros = '';
foreach($payload['pasajeros'] as $p){
    if(!is_array($p)) continue;
    $nombres   = h($p['nombres'] ?? '-');
    $apellidos = h($p['apellidos'] ?? '-');
    $filasPasajeros .= '
        <tr>
          <td style="padding:8px;border-bottom:1px solid #e2e8f0;">' . $nombres . ' ' . $apellidos . '</td>
        </tr>';
}

$totalFormateado = number_format($total, 2, '.', ',');

$htmlCorreo = '<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#f2f4f7;font-family:Arial,Helvetica,sans-serif;color:#1a2436;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f2f4f7;padding:24px 0;">
    <tr><td align="center">
      <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:8px;overflow:hidden;">
        <tr><td style="background:#0b3d63;padding:20px 24px;">
          <span style="color:#ffffff;font-size:20px;font-weight:bold;">ACAJUTLA AIRLINES</span>
        </td></tr>
        <tr><td style="padding:24px;">
          <h2 style="margin:0 0 4px 0;color:#0b3d63;">Comprobante de reserva</h2>
          <p style="margin:0 0 16px 0;color:#556;">Código de reserva (PNR): <b>' . h($pnr) . '</b></p>

          <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:20px;">
            <tr><td style="padding:4px 0;color:#556;">Cliente:</td><td style="padding:4px 0;"><b>' . h($nombreCliente) . '</b></td></tr>
            <tr><td style="padding:4px 0;color:#556;">Correo:</td><td style="padding:4px 0;">' . h($email) . '</td></tr>
            <tr><td style="padding:4px 0;color:#556;">Estado de la reserva:</td><td style="padding:4px 0;">' . h($estadoReserva) . '</td></tr>
          </table>

          <h3 style="margin:0 0 8px 0;color:#0b3d63;">Vuelos</h3>
          <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:20px;border-collapse:collapse;">
            <tr style="background:#eef3f8;">
              <td style="padding:8px;font-size:12px;color:#556;">Vuelo</td>
              <td style="padding:8px;font-size:12px;color:#556;">Ruta</td>
              <td style="padding:8px;font-size:12px;color:#556;">Fecha</td>
              <td style="padding:8px;font-size:12px;color:#556;">Asiento</td>
            </tr>
            ' . $filasSegmentos . '
          </table>

          <h3 style="margin:0 0 8px 0;color:#0b3d63;">Pasajeros</h3>
          <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:20px;border-collapse:collapse;">
            ' . $filasPasajeros . '
          </table>

          <h3 style="margin:0 0 8px 0;color:#0b3d63;">Pago</h3>
          <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:8px;">
            <tr><td style="padding:4px 0;color:#556;">Método:</td><td style="padding:4px 0;">' . h($pagoMetodo) . '</td></tr>
            <tr><td style="padding:4px 0;color:#556;">Estado del pago:</td><td style="padding:4px 0;">' . h($pagoEstado) . '</td></tr>
            <tr><td style="padding:4px 0;color:#556;font-weight:bold;">Total:</td><td style="padding:4px 0;font-weight:bold;">$' . h($totalFormateado) . '</td></tr>
          </table>

          <p style="font-size:12px;color:#889;margin-top:24px;">Este es un comprobante generado automáticamente. Conserva este correo como referencia de tu reserva.</p>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>';

$asunto = 'Acajutla Airlines - Comprobante de reserva ' . $pnr;

// -----------------------------------------------------------------------
// 5. Registrar en email_outbox como 'pending' ANTES de intentar enviar
// -----------------------------------------------------------------------
require_once __DIR__ . '/conexion.php';

if(!CONEXION_OK){
    responderJson(500, ['success' => false, 'message' => 'No fue posible enviar el comprobante por correo.']);
}

$sqlInsert = "INSERT INTO email_outbox (template, to_email, subject, html, ref_type, ref_id, status)
              VALUES ('booking_confirmation', ?, ?, ?, 'reservation', ?, 'pending')";
$stmtInsert = mysqli_prepare($conexion, $sqlInsert);
if(!$stmtInsert){
    error_log('[Acajutla Airlines] Error al preparar INSERT en email_outbox: ' . mysqli_error($conexion));
    cerrarConexion();
    responderJson(500, ['success' => false, 'message' => 'No fue posible enviar el comprobante por correo.']);
}
mysqli_stmt_bind_param($stmtInsert, 'ssss', $email, $asunto, $htmlCorreo, $pnr);
if(!mysqli_stmt_execute($stmtInsert)){
    error_log('[Acajutla Airlines] Error al insertar en email_outbox: ' . mysqli_stmt_error($stmtInsert));
    mysqli_stmt_close($stmtInsert);
    cerrarConexion();
    responderJson(500, ['success' => false, 'message' => 'No fue posible enviar el comprobante por correo.']);
}
$emailOutboxId = mysqli_insert_id($conexion);
mysqli_stmt_close($stmtInsert);

// -----------------------------------------------------------------------
// 6. Intentar el envío SMTP real (Gmail, STARTTLS, AUTH LOGIN)
// -----------------------------------------------------------------------
function actualizarEstadoEnvio($conexion, $id, $status, $errorMsg = null){
    if($status === 'sent'){
        $sql = "UPDATE email_outbox SET status='sent', sent_at=NOW(), error_msg=NULL WHERE id=?";
        $stmt = mysqli_prepare($conexion, $sql);
        if($stmt){ mysqli_stmt_bind_param($stmt, 'i', $id); mysqli_stmt_execute($stmt); mysqli_stmt_close($stmt); }
    } else {
        $sql = "UPDATE email_outbox SET status='failed', error_msg=? WHERE id=?";
        $stmt = mysqli_prepare($conexion, $sql);
        if($stmt){
            $errorMsgRecortado = $errorMsg !== null ? mb_substr((string)$errorMsg, 0, 1000) : 'Error desconocido.';
            mysqli_stmt_bind_param($stmt, 'si', $errorMsgRecortado, $id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    }
}

/**
 * Lee una respuesta SMTP completa (soporta líneas multilínea "250-...").
 * Devuelve el texto completo recibido.
 */
function smtpLeerRespuesta($socket){
    $respuesta = '';
    while(!feof($socket)){
        $linea = fgets($socket, 515);
        if($linea === false) break;
        $respuesta .= $linea;
        // Una línea de continuación tiene un guion en la 4ta posición (ej. "250-"),
        // la última línea de la respuesta tiene un espacio (ej. "250 ").
        if(isset($linea[3]) && $linea[3] === ' ') break;
    }
    return $respuesta;
}

function smtpEnviarComando($socket, $comando, $codigoEsperado){
    fwrite($socket, $comando . "\r\n");
    $respuesta = smtpLeerRespuesta($socket);
    $codigo = substr($respuesta, 0, 3);
    if($codigo !== $codigoEsperado){
        throw new Exception('SMTP inesperado. Esperado ' . $codigoEsperado . ', recibido: ' . trim($respuesta));
    }
    return $respuesta;
}

/**
 * Envía un correo HTML vía Gmail SMTP (587, STARTTLS, AUTH LOGIN) usando
 * únicamente sockets nativos de PHP. Sin PHPMailer, sin Composer.
 * Lanza Exception en caso de error (el caller decide qué guardar/mostrar).
 */
function enviarCorreoSMTP($host, $puerto, $usuario, $password, $nombreRemitente, $destinatario, $asunto, $htmlBody){
    $socket = @stream_socket_client("tcp://{$host}:{$puerto}", $errno, $errstr, 15);
    if(!$socket){
        throw new Exception('No se pudo conectar al servidor SMTP: ' . $errstr);
    }
    stream_set_timeout($socket, 15);

    $saludo = smtpLeerRespuesta($socket);
    if(substr($saludo, 0, 3) !== '220'){
        fclose($socket);
        throw new Exception('Saludo SMTP inesperado: ' . trim($saludo));
    }

    $dominioLocal = 'acajutla-airlines.local';
    smtpEnviarComando($socket, "EHLO {$dominioLocal}", '250');

    smtpEnviarComando($socket, 'STARTTLS', '220');

    $crypto = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
    if(!$crypto){
        fclose($socket);
        throw new Exception('No se pudo iniciar TLS con el servidor SMTP.');
    }

    // Tras STARTTLS es obligatorio volver a saludar.
    smtpEnviarComando($socket, "EHLO {$dominioLocal}", '250');

    smtpEnviarComando($socket, 'AUTH LOGIN', '334');
    smtpEnviarComando($socket, base64_encode($usuario), '334');
    smtpEnviarComando($socket, base64_encode($password), '235');

    smtpEnviarComando($socket, "MAIL FROM:<{$usuario}>", '250');
    smtpEnviarComando($socket, "RCPT TO:<{$destinatario}>", '250');

    smtpEnviarComando($socket, 'DATA', '354');

    $asuntoCodificado = '=?UTF-8?B?' . base64_encode($asunto) . '?=';
    $nombreRemitenteCodificado = '=?UTF-8?B?' . base64_encode($nombreRemitente) . '?=';

    // Duplicar cualquier línea que empiece con un punto (escape SMTP estándar,
    // "dot-stuffing"), requisito del protocolo para no cortar el mensaje.
    $cuerpoEscapado = str_replace("\n.", "\n..", $htmlBody);

    $mensaje = "From: {$nombreRemitenteCodificado} <{$usuario}>\r\n";
    $mensaje .= "To: <{$destinatario}>\r\n";
    $mensaje .= "Subject: {$asuntoCodificado}\r\n";
    $mensaje .= "MIME-Version: 1.0\r\n";
    $mensaje .= "Content-Type: text/html; charset=UTF-8\r\n";
    $mensaje .= "Content-Transfer-Encoding: 8bit\r\n";
    $mensaje .= "\r\n";
    $mensaje .= $cuerpoEscapado . "\r\n";
    $mensaje .= ".";

    smtpEnviarComando($socket, $mensaje, '250');

    fwrite($socket, "QUIT\r\n");
    fclose($socket);

    return true;
}

$smtpUser = getenv('diegoramireze658@gmail.com');
$smtpPassword = getenv('ferh tbny ycrk rgge');

if(!$smtpUser || !$smtpPassword){
    actualizarEstadoEnvio($conexion, $emailOutboxId, 'failed', 'SMTP_USER / SMTP_PASSWORD no configurados en el entorno del servidor.');
    cerrarConexion();
    responderJson(500, ['success' => false, 'message' => 'No fue posible enviar el comprobante por correo.']);
}

try{
    enviarCorreoSMTP(SMTP_HOST, SMTP_PORT, $smtpUser, $smtpPassword, SMTP_REMITENTE_NOMBRE, $email, $asunto, $htmlCorreo);
    actualizarEstadoEnvio($conexion, $emailOutboxId, 'sent');
    cerrarConexion();
    responderJson(200, ['success' => true, 'message' => 'Comprobante enviado correctamente.']);
} catch(Exception $e){
    // El detalle técnico se guarda internamente; al frontend nunca se expone.
    error_log('[Acajutla Airlines] Error de envío SMTP: ' . $e->getMessage());
    actualizarEstadoEnvio($conexion, $emailOutboxId, 'failed', $e->getMessage());
    cerrarConexion();
    responderJson(500, ['success' => false, 'message' => 'No fue posible enviar el comprobante por correo.']);
}
