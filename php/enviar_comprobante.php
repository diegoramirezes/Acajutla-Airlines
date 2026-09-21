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
 *   - NO se usa PHPMailer ni Composer.
 *   - El envío real se hace vía Nylas Email API v3 (POST
 *     https://api.us.nylas.com/v3/grants/{grant_id}/messages/send), usando
 *     una cuenta Gmail ya conectada a Nylas mediante OAuth (Grant ID ya
 *     generado y probado). Ya no se usa SMTP2GO, Resend, Brevo ni SMTP
 *     directo. Se usa cURL si está disponible en el entorno; si no, se
 *     hace fallback a file_get_contents() con contexto HTTPS (stream
 *     wrapper nativo de PHP, sin dependencias).
 *   - Credenciales SOLO por variables de entorno: NYLAS_API_KEY,
 *     NYLAS_GRANT_ID. Nunca escritas aquí.
 *   - NOTA: la columna email_outbox.brevo_message_id se sigue usando tal
 *     cual (sin migración de esquema, según regla del proyecto de no
 *     tocar la BD) para guardar el id del mensaje que devuelve Nylas
 *     (data.id). El nombre de la columna es historia previa (cuando se
 *     usaba Brevo); su contenido ahora es el identificador real de Nylas.
 *
 * Respuesta: siempre JSON.
 *   Éxito: {"success":true,"message":"Comprobante enviado correctamente."}
 *   Error: {"success":false,"message":"..."} (mensaje genérico, sin
 *          detalles técnicos; el detalle real queda en email_outbox.error_msg)
 * =====================================================================
 */

header('Content-Type: application/json; charset=utf-8');

// -----------------------------------------------------------------------
// 0. Config Nylas — SOLO desde variables de entorno. Nunca hardcodeadas.
// -----------------------------------------------------------------------
const NYLAS_API_BASE = 'https://api.us.nylas.com/v3';

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
    if($v === '' || strlen($v) > $maxLen) return null;
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

// -----------------------------------------------------------------------
// Conversión determinista de un monto en dólares a su representación en
// letras (español). Sin APIs externas, sin Math.random, sin mock. Usa
// EXACTAMENTE el mismo $total ya validado más arriba — no se recalcula ni
// se toma de otra fuente.
// -----------------------------------------------------------------------
function _numALetrasGrupo($num){
    // Convierte un número de 0 a 999 a letras (sin escalas como mil/millón).
    $num = (int)$num;
    if($num === 0) return '';
    if($num === 100) return 'cien';

    $unidades19 = ['', 'uno', 'dos', 'tres', 'cuatro', 'cinco', 'seis', 'siete', 'ocho', 'nueve',
        'diez', 'once', 'doce', 'trece', 'catorce', 'quince', 'dieciséis', 'diecisiete', 'dieciocho', 'diecinueve'];
    $decenas = ['', '', 'veinte', 'treinta', 'cuarenta', 'cincuenta', 'sesenta', 'setenta', 'ochenta', 'noventa'];
    $centenas = ['', 'ciento', 'doscientos', 'trescientos', 'cuatrocientos', 'quinientos', 'seiscientos', 'setecientos', 'ochocientos', 'novecientos'];

    $c = intdiv($num, 100);
    $resto = $num % 100;
    $partes = [];
    if($c > 0) $partes[] = $centenas[$c];

    if($resto > 0){
        if($resto < 20){
            $partes[] = $unidades19[$resto];
        } elseif($resto < 30){
            $partes[] = ($resto === 20) ? 'veinte' : 'veinti' . $unidades19[$resto - 20];
        } else {
            $d = intdiv($resto, 10);
            $u = $resto % 10;
            $texto = $decenas[$d];
            if($u > 0) $texto .= ' y ' . $unidades19[$u];
            $partes[] = $texto;
        }
    }
    return implode(' ', $partes);
}

/**
 * Convierte un entero no negativo (hasta 999,999,999) a letras en español.
 */
function numeroALetrasEntero($n){
    $n = (int)abs($n); // el total de una reserva nunca es negativo; defensivo, sin inventar signo
    if($n === 0) return 'cero';

    $millones = intdiv($n, 1000000);
    $resto1 = $n % 1000000;
    $miles = intdiv($resto1, 1000);
    $unidades = $resto1 % 1000;

    $partes = [];
    if($millones > 0){
        $partes[] = ($millones === 1) ? 'un millón' : (_numALetrasGrupo($millones) . ' millones');
    }
    if($miles > 0){
        $partes[] = ($miles === 1) ? 'mil' : (_numALetrasGrupo($miles) . ' mil');
    }
    if($unidades > 0){
        $partes[] = _numALetrasGrupo($unidades);
    }

    $resultado = trim(implode(' ', $partes));

    // Apócope: "uno"/"veintiuno" -> "un"/"veintiún" al anteceder un
    // sustantivo masculino (dólar/centavo), único uso de este texto.
    if(substr($resultado, -9) === 'veintiuno'){
        $resultado = substr($resultado, 0, -9) . 'veintiún';
    } elseif(substr($resultado, -3) === 'uno'){
        $resultado = substr($resultado, 0, -3) . 'un';
    }

    return $resultado;
}

/**
 * Convierte un monto monetario (dólares) a su representación en letras.
 * Usa el mismo redondeo a 2 decimales que $totalFormateado (number_format),
 * para que el texto corresponda exactamente al número ya mostrado.
 */
function montoEnLetras($total){
    $centavosTotales = (int)round(((float)$total) * 100);
    $enteros = intdiv($centavosTotales, 100);
    $centavos = $centavosTotales % 100;

    $textoEnteros = numeroALetrasEntero($enteros);
    $textoCentavos = numeroALetrasEntero($centavos);

    $palabraDolar = ($enteros === 1) ? 'dólar' : 'dólares';
    $palabraCentavo = ($centavos === 1) ? 'centavo' : 'centavos';

    return ucfirst($textoEnteros) . ' ' . $palabraDolar . ' con ' . $textoCentavos . ' ' . $palabraCentavo . '.';
}

$totalFormateado = number_format($total, 2, '.', ',');
$totalEnLetras = montoEnLetras($total);

// =========================================================================
// GENERADOR DE PDF — sin librerías externas (no existe ninguna en el
// proyecto: sin Composer, Dockerfile solo instala mysqli). Se escribe el
// documento PDF byte a byte, usando únicamente fuentes estándar de PDF
// (Helvetica / Helvetica-Bold), que no requieren embeber ningún archivo de
// fuente — cualquier lector de PDF las soporta de forma nativa. Esto evita
// agregar cualquier dependencia nueva y funciona igual en Render que en
// XAMPP, sin tocar el Dockerfile.
// =========================================================================

/**
 * Convierte UTF-8 a Latin-1/CP1252 de forma segura y sin depender de
 * mbstring ni iconv (ninguno de los dos está garantizado en este entorno,
 * ver aprendizajes previos del proyecto). Cubre exactamente el rango que
 * necesitan los acentos/ñ del español (U+0080–U+00FF). Los caracteres
 * fuera de ese rango se sustituyen por '?', nunca se inventan datos.
 */
function utf8ALatin1Seguro($texto){
    $resultado = '';
    $len = strlen($texto);
    for($i = 0; $i < $len; $i++){
        $byte = ord($texto[$i]);
        if($byte < 0x80){
            $resultado .= $texto[$i];
        } elseif(($byte & 0xE0) === 0xC0 && $i + 1 < $len){
            $byte2 = ord($texto[$i + 1]);
            $codepoint = (($byte & 0x1F) << 6) | ($byte2 & 0x3F);
            $resultado .= ($codepoint <= 0xFF) ? chr($codepoint) : '?';
            $i++;
        } else {
            $resultado .= '?';
        }
    }
    return $resultado;
}

/** Escapa un texto para insertarlo de forma segura dentro de "(...)" en un content stream PDF. */
function pdfEscaparTexto($texto){
    $texto = utf8ALatin1Seguro((string)$texto);
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $texto);
}

/**
 * Genera el PDF completo del comprobante como una cadena de bytes binaria
 * lista para adjuntar. Construye una lista de "líneas" (texto/fuente/
 * tamaño/color/separador) y las va colocando en páginas A4, abriendo una
 * página nueva automáticamente cuando el contenido no cabe — sin límite
 * artificial de segmentos/pasajeros distinto al que ya valida este mismo
 * archivo más arriba (máx. 10 segmentos, máx. 20 pasajeros).
 *
 * @param array $datos ['pnr','nombreCliente','email','estado','segmentos'
 *                       (payload real, con asientos por pasajero si vienen),
 *                       'pasajeros' (payload real), 'pagoMetodo','pagoEstado',
 *                       'totalFormateado','totalEnLetras']
 * @return string bytes del PDF
 */
function construirPdfComprobante($datos){
    $anchoPagina = 595.28; $altoPagina = 841.89; // A4 en puntos
    $margenIzq = 42; $margenDer = 42;
    $altoBanda = 64;
    $margenInferior = 56;
    $colorCorporativo = [0.043, 0.239, 0.388]; // mismo azul del correo HTML (#0b3d63)
    $colorGris = [0.34, 0.38, 0.44];
    $colorNegro = [0.1, 0.12, 0.16];

    // ---- 1) Construir la lista plana de líneas a dibujar ----
    $lineas = [];
    $agregar = function($texto, $fuente = 'F1', $tam = 10, $color = null, $espacioAntes = 4, $indent = 0) use (&$lineas, $colorNegro){
        $lineas[] = ['tipo' => 'texto', 'texto' => (string)$texto, 'fuente' => $fuente, 'tam' => $tam,
                      'color' => $color ?? $colorNegro, 'espacioAntes' => $espacioAntes, 'indent' => $indent];
    };
    $separador = function($espacioAntes = 8) use (&$lineas){
        $lineas[] = ['tipo' => 'separador', 'espacioAntes' => $espacioAntes];
    };

    $fechaEmision = date('d/m/Y H:i');

    $agregar('COMPROBANTE DE RESERVA Y BILLETE ELECTRÓNICO', 'F2', 14, $colorCorporativo, 0);
    $agregar('Código de reserva (PNR): ' . $datos['pnr'], 'F2', 12, $colorNegro, 6);
    $agregar('Fecha de emisión: ' . $fechaEmision, 'F1', 9, $colorGris, 2);
    $separador(10);

    $agregar('DATOS DEL CLIENTE', 'F2', 11, $colorCorporativo, 0);
    $agregar('Cliente: ' . $datos['nombreCliente'], 'F1', 10, $colorNegro, 6);
    $agregar('Correo de contacto: ' . $datos['email'], 'F1', 10, $colorNegro, 3);
    $agregar('Estado de la reserva: ' . $datos['estado'], 'F1', 10, $colorNegro, 3);
    $separador(10);

    $agregar('ITINERARIO', 'F2', 11, $colorCorporativo, 0);
    foreach($datos['segmentos'] as $seg){
        if(!is_array($seg)) continue;
        $numeroVuelo = $seg['numero_vuelo'] ?? '-';
        $origen = $seg['origen'] ?? '-';
        $destino = $seg['destino'] ?? '-';
        $fecha = $seg['fecha'] ?? '-';
        $agregar('Vuelo ' . $numeroVuelo . '  ·  ' . $origen . ' -> ' . $destino, 'F2', 10.5, $colorNegro, 8);
        $agregar('Fecha: ' . $fecha, 'F1', 9.5, $colorGris, 2, 8);
        if(isset($seg['fare_class']) && $seg['fare_class'] !== ''){
            $agregar('Clase/tarifa: ' . $seg['fare_class'], 'F1', 9.5, $colorGris, 1, 8);
        }
        // Un asiento por pasajero si el payload trae el arreglo por
        // pasajero (asientos[]); si no, se usa el campo simple existente.
        if(isset($seg['asientos']) && is_array($seg['asientos']) && isset($datos['pasajeros']) && is_array($datos['pasajeros'])){
            foreach($seg['asientos'] as $idx => $asientoPax){
                $pax = $datos['pasajeros'][$idx] ?? null;
                $nombrePax = $pax ? trim(($pax['nombres'] ?? '') . ' ' . ($pax['apellidos'] ?? '')) : ('Pasajero ' . ($idx + 1));
                $agregar('- ' . $nombrePax . '  ·  Asiento: ' . ($asientoPax ?: 'No requiere asiento'), 'F1', 9.5, $colorNegro, 1, 14);
            }
        } elseif(!empty($seg['asiento'])){
            $agregar('Asiento: ' . $seg['asiento'], 'F1', 9.5, $colorNegro, 1, 14);
        }
    }
    $separador(10);

    $agregar('PASAJEROS', 'F2', 11, $colorCorporativo, 0);
    foreach($datos['pasajeros'] as $p){
        if(!is_array($p)) continue;
        $nombreCompleto = trim(($p['nombres'] ?? '') . ' ' . ($p['apellidos'] ?? ''));
        $linea = $nombreCompleto;
        if(!empty($p['tipo'])) $linea .= '  ·  Tipo: ' . $p['tipo'];
        if(!empty($p['documento'])) $linea .= '  ·  Documento: ' . $p['documento'];
        if(!empty($p['nacionalidad'])) $linea .= '  ·  Nacionalidad: ' . $p['nacionalidad'];
        $agregar($linea, 'F1', 9.5, $colorNegro, 5);
    }
    $separador(10);

    $agregar('PAGO', 'F2', 11, $colorCorporativo, 0);
    $pagoMetodoPdf = is_array($datos['pago'] ?? null) ? ($datos['pago']['metodo'] ?? 'N/D') : 'N/D';
    $pagoEstadoPdf = is_array($datos['pago'] ?? null) ? ($datos['pago']['estado'] ?? 'N/D') : 'N/D';
    $agregar('Método: ' . $pagoMetodoPdf, 'F1', 10, $colorNegro, 6);
    $agregar('Estado del pago: ' . $pagoEstadoPdf, 'F1', 10, $colorNegro, 3);
    $separador(10);

    $agregar('TOTAL', 'F2', 11, $colorCorporativo, 0);
    $agregar('Total: $' . $datos['totalFormateado'] . ' USD', 'F2', 12, $colorNegro, 6);
    $agregar('Total en letras: ' . $datos['totalEnLetras'], 'F1', 9.5, $colorGris, 3);

    // ---- 2) Paginar: colocar cada línea en páginas A4 ----
    $paginas = []; // cada elemento: array de líneas ya con su 'y' calculado
    $paginaActual = [];
    $y = $altoPagina - $altoBanda - 34;
    foreach($lineas as $l){
        $alturaLinea = ($l['tipo'] === 'separador') ? $l['espacioAntes'] + 6 : ($l['tam'] * 1.35 + $l['espacioAntes']);
        if($y - $alturaLinea < $margenInferior){
            $paginas[] = $paginaActual;
            $paginaActual = [];
            $y = $altoPagina - $altoBanda - 34;
        }
        $y -= $alturaLinea;
        $l['y'] = $y;
        $paginaActual[] = $l;
    }
    if(!empty($paginaActual)) $paginas[] = $paginaActual;
    if(empty($paginas)) $paginas[] = [];

    // ---- 3) Construir el content stream de cada página ----
    $streamsPaginas = [];
    foreach($paginas as $indicePagina => $lineasPagina){
        $stream = '';
        // Banda de encabezado corporativa (se repite en cada página).
        $stream .= sprintf("%.3F %.3F %.3F rg\n0 %.2F %.2F %.2F re f\n",
            $colorCorporativo[0], $colorCorporativo[1], $colorCorporativo[2],
            $altoPagina - $altoBanda, $anchoPagina, $altoBanda);
        $stream .= "1 1 1 rg\nBT /F2 16 Tf 1 0 0 1 " . $margenIzq . " " . ($altoPagina - 38) . " Tm (ACAJUTLA AIRLINES) Tj ET\n";
        $stream .= "BT /F1 9 Tf 1 0 0 1 " . $margenIzq . " " . ($altoPagina - 54) . " Tm (Comprobante de reserva electronico) Tj ET\n";

        foreach($lineasPagina as $l){
            if($l['tipo'] === 'separador'){
                $yLinea = $l['y'] + 3;
                $stream .= sprintf("%.3F %.3F %.3F RG\n%.2F %.2F m %.2F %.2F l S\n",
                    0.82, 0.85, 0.88, $margenIzq, $yLinea, $anchoPagina - $margenDer, $yLinea);
                continue;
            }
            $x = $margenIzq + $l['indent'];
            $stream .= sprintf("%.3F %.3F %.3F rg\nBT /%s %.1F Tf 1 0 0 1 %.2F %.2F Tm (%s) Tj ET\n",
                $l['color'][0], $l['color'][1], $l['color'][2], $l['fuente'], $l['tam'], $x, $l['y'], pdfEscaparTexto($l['texto']));
        }
        $streamsPaginas[] = $stream;
    }

    // ---- 4) Ensamblar el archivo PDF (objetos, xref, trailer) ----
    $numPaginas = count($streamsPaginas);
    // Numeración de objetos: 1=Catalog, 2=Pages, 3=F1, 4=F2,
    // luego por cada página: (Page, Contents) en pares consecutivos.
    $objetos = [];
    $idPagesKids = [];
    $primerObjetoPagina = 5;
    for($i = 0; $i < $numPaginas; $i++){
        $idPagina = $primerObjetoPagina + ($i * 2);
        $idContenido = $idPagina + 1;
        $idPagesKids[] = $idPagina;
    }
    $kidsRefs = implode(' ', array_map(fn($id) => $id . ' 0 R', $idPagesKids));

    $objetos[1] = "<< /Type /Catalog /Pages 2 0 R >>";
    $objetos[2] = "<< /Type /Pages /Kids [$kidsRefs] /Count $numPaginas >>";
    $objetos[3] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>";
    $objetos[4] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>";

    for($i = 0; $i < $numPaginas; $i++){
        $idPagina = $primerObjetoPagina + ($i * 2);
        $idContenido = $idPagina + 1;
        $objetos[$idPagina] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 " . $anchoPagina . " " . $altoPagina . "] "
            . "/Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents " . $idContenido . " 0 R >>";
        $stream = $streamsPaginas[$i];
        $objetos[$idContenido] = "STREAM::" . $stream; // marcador especial, se serializa distinto (stream binario)
    }

    // ---- 5) Serializar a bytes con offsets reales para el xref ----
    $pdf = "%PDF-1.4\n";
    $offsets = [0 => 0]; // el objeto 0 siempre es libre, offset 0
    ksort($objetos);
    foreach($objetos as $id => $cuerpo){
        $offsets[$id] = strlen($pdf);
        if(strpos($cuerpo, 'STREAM::') === 0){
            $contenidoStream = substr($cuerpo, strlen('STREAM::'));
            $pdf .= $id . " 0 obj\n<< /Length " . strlen($contenidoStream) . " >>\nstream\n" . $contenidoStream . "endstream\nendobj\n";
        } else {
            $pdf .= $id . " 0 obj\n" . $cuerpo . "\nendobj\n";
        }
    }

    $totalObjetos = max(array_keys($objetos)) + 1;
    $offsetXref = strlen($pdf);
    $pdf .= "xref\n0 " . $totalObjetos . "\n";
    $pdf .= "0000000000 65535 f \n";
    for($id = 1; $id < $totalObjetos; $id++){
        if(isset($offsets[$id])){
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$id]);
        } else {
            $pdf .= "0000000000 00000 f \n";
        }
    }
    $pdf .= "trailer\n<< /Size " . $totalObjetos . " /Root 1 0 R >>\nstartxref\n" . $offsetXref . "\n%%EOF";

    return $pdf;
}

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
            <tr><td style="padding:4px 0;color:#556;">Total en letras:</td><td style="padding:4px 0;">' . h($totalEnLetras) . '</td></tr>
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
require_once __DIR__ . '/pdf_comprobante.php';

if(!CONEXION_OK){
    responderJson(500, ['success' => false, 'message' => 'No pudimos enviar el comprobante. Intenta nuevamente.']);
}

$sqlInsert = "INSERT INTO email_outbox (template, to_email, subject, html, ref_type, ref_id, status)
              VALUES ('booking_confirmation', ?, ?, ?, 'reservation', ?, 'pending')";
$stmtInsert = mysqli_prepare($conexion, $sqlInsert);
if(!$stmtInsert){
    error_log('[Acajutla Airlines] Error al preparar INSERT en email_outbox: ' . mysqli_error($conexion));
    cerrarConexion();
    responderJson(500, ['success' => false, 'message' => 'No pudimos enviar el comprobante. Intenta nuevamente.']);
}
mysqli_stmt_bind_param($stmtInsert, 'ssss', $email, $asunto, $htmlCorreo, $pnr);
if(!mysqli_stmt_execute($stmtInsert)){
    error_log('[Acajutla Airlines] Error al insertar en email_outbox: ' . mysqli_stmt_error($stmtInsert));
    mysqli_stmt_close($stmtInsert);
    cerrarConexion();
    responderJson(500, ['success' => false, 'message' => 'No pudimos enviar el comprobante. Intenta nuevamente.']);
}
$emailOutboxId = mysqli_insert_id($conexion);
mysqli_stmt_close($stmtInsert);

// -----------------------------------------------------------------------
// 6. Intentar el envío real vía Nylas Email API v3 (HTTPS), NO SMTP2GO
// -----------------------------------------------------------------------
function actualizarEstadoEnvio($conexion, $id, $status, $errorMsg = null, $nylasMessageId = null){
    if($status === 'sent'){
        // NOTA: la columna se sigue llamando brevo_message_id (no se migra
        // el esquema), pero ahora guarda el id del mensaje real de Nylas.
        $sql = "UPDATE email_outbox SET status='sent', sent_at=NOW(), error_msg=NULL, brevo_message_id=? WHERE id=?";
        $stmt = mysqli_prepare($conexion, $sql);
        if($stmt){
            mysqli_stmt_bind_param($stmt, 'si', $nylasMessageId, $id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    } else {
        $sql = "UPDATE email_outbox SET status='failed', error_msg=? WHERE id=?";
        $stmt = mysqli_prepare($conexion, $sql);
        if($stmt){
            $errorMsgRecortado = $errorMsg !== null ? substr((string)$errorMsg, 0, 1000) : 'Error desconocido.';
            mysqli_stmt_bind_param($stmt, 'si', $errorMsgRecortado, $id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }
    }
}

/**
 * Hace un POST HTTPS con cuerpo JSON. Usa cURL si la extensión está
 * cargada en el entorno (function_exists('curl_init')); si no, hace
 * fallback a file_get_contents() con un stream context HTTPS, que es
 * parte del núcleo de PHP y no requiere ninguna extensión adicional
 * ni tocar el Dockerfile.
 * $timeoutSegundos es configurable porque Nylas recomienda un timeout
 * de cliente de al menos 150s para POST /messages/send.
 * Devuelve ['codigo' => int, 'cuerpo' => string|false].
 * Lanza Exception solo si NINGÚN mecanismo de transporte está disponible
 * o si la conexión de red falla por completo (timeout, DNS, etc.).
 */
function postJsonHttps($url, array $headers, $cuerpoJson, $timeoutSegundos = 15){
    if(function_exists('curl_init')){
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $cuerpoJson,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeoutSegundos,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $cuerpoRespuesta = curl_exec($ch);
        if($cuerpoRespuesta === false){
            $error = curl_error($ch);
            curl_close($ch);
            throw new Exception('Fallo de conexión (cURL) hacia el servicio de correo: ' . $error);
        }
        $codigoHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['codigo' => (int)$codigoHttp, 'cuerpo' => $cuerpoRespuesta];
    }

    // Fallback sin cURL: file_get_contents con stream context HTTPS
    // (wrapper nativo de PHP, no requiere extensiones adicionales).
    $opciones = [
        'http' => [
            'method'  => 'POST',
            'header'  => implode("\r\n", $headers),
            'content' => $cuerpoJson,
            'timeout' => $timeoutSegundos,
            'ignore_errors' => true, // para poder leer el cuerpo también en 4xx/5xx
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ];
    $contexto = stream_context_create($opciones);
    $cuerpoRespuesta = @file_get_contents($url, false, $contexto);

    if($cuerpoRespuesta === false){
        throw new Exception('Fallo de conexión (stream HTTPS) hacia el servicio de correo.');
    }

    $codigoHttp = 0;
    if(isset($http_response_header) && is_array($http_response_header)){
        foreach($http_response_header as $cabecera){
            if(preg_match('#^HTTP/\S+\s+(\d{3})#', $cabecera, $m)){
                $codigoHttp = (int)$m[1];
            }
        }
    }
    return ['codigo' => $codigoHttp, 'cuerpo' => $cuerpoRespuesta];
}

/**
 * Envía un correo HTML vía Nylas Email API v3 (POST
 * https://api.us.nylas.com/v3/grants/{grant_id}/messages/send, HTTPS).
 * NO usa SMTP, fsockopen, STARTTLS, AUTH LOGIN, SMTP2GO, Resend ni Brevo.
 * Autenticación: header "Authorization: Bearer {NYLAS_API_KEY}".
 * El remitente es la cuenta Gmail ya conectada al Grant (no se envía un
 * campo "from": Nylas usa automáticamente la cuenta del grant).
 * Devuelve el id del mensaje (string) que entrega Nylas en data.id si el
 * envío fue realmente exitoso (HTTP 2xx Y un id presente en la respuesta).
 * Lanza Exception con detalle técnico en caso contrario (el caller decide
 * qué guardar en email_outbox.error_msg y qué mostrar al usuario).
 */
function enviarCorreoNylas($apiKey, $grantId, $destinatario, $asunto, $htmlBody, $adjuntoPdfBase64 = null, $nombreArchivoPdf = null){
    $cuerpo = [
        'subject' => $asunto,
        'to'      => [['email' => $destinatario]],
        'body'    => $htmlBody,
        // Explícito (aunque 'false' ya es el valor por defecto de la API de
        // Nylas): el body se envía como HTML real, nunca como texto plano.
        'is_plaintext' => false,
    ];

    // PDF adjunto real (no un enlace ni texto/base64 visible en el correo):
    // formato oficial de Nylas v3 — content_type + filename + content
    // (base64). Opcional: si no se pudo generar el PDF, el correo se envía
    // igual, sin adjunto, para no romper el envío del resumen HTML.
    if($adjuntoPdfBase64 !== null && $nombreArchivoPdf !== null){
        $cuerpo['attachments'] = [[
            'content_type' => 'application/pdf',
            'filename' => $nombreArchivoPdf,
            'content' => $adjuntoPdfBase64,
        ]];
    }

    $cuerpoJson = json_encode($cuerpo, JSON_UNESCAPED_UNICODE);

    $headers = [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $apiKey,
    ];

    $url = NYLAS_API_BASE . '/grants/' . rawurlencode($grantId) . '/messages/send';

    // Nylas recomienda un timeout de cliente de al menos 150s para este endpoint.
    $respuesta = postJsonHttps($url, $headers, $cuerpoJson, 160);
    $codigoHttp = $respuesta['codigo'];
    $cuerpoRespuesta = $respuesta['cuerpo'];

    $datos = json_decode((string)$cuerpoRespuesta, true);

    if($codigoHttp < 200 || $codigoHttp >= 300){
        // Nunca se expone el cuerpo de la respuesta de Nylas (puede incluir
        // detalles internos) al frontend; solo queda registrado internamente.
        throw new Exception('Nylas respondió HTTP ' . $codigoHttp . ': ' . substr((string)$cuerpoRespuesta, 0, 500));
    }

    $datosEnvio = (is_array($datos) && isset($datos['data']) && is_array($datos['data'])) ? $datos['data'] : null;
    $mensajeId = ($datosEnvio && isset($datosEnvio['id']) && is_string($datosEnvio['id']) && $datosEnvio['id'] !== '')
        ? $datosEnvio['id']
        : null;

    // Aunque el HTTP sea 2xx, solo se considera realmente exitoso si Nylas
    // entrega un id de mensaje válido en la respuesta.
    if($mensajeId === null){
        throw new Exception('Nylas respondió HTTP ' . $codigoHttp . ' pero sin un id de mensaje válido: ' . substr((string)$cuerpoRespuesta, 0, 500));
    }

    return $mensajeId;
}

$nylasApiKey = getenv('NYLAS_API_KEY');
$nylasGrantId = getenv('NYLAS_GRANT_ID');

if(!$nylasApiKey || !$nylasGrantId){
    actualizarEstadoEnvio($conexion, $emailOutboxId, 'failed', 'NYLAS_API_KEY / NYLAS_GRANT_ID no configurados correctamente en el entorno del servidor.');
    cerrarConexion();
    responderJson(500, ['success' => false, 'message' => 'No pudimos enviar el comprobante. Intenta nuevamente.']);
}

try{
    // Genera el PDF real reutilizando exactamente los mismos datos/valores
    // ya validados y calculados arriba — no se duplica ninguna lógica de
    // total, total en letras, ni datos de la reserva.
    $adjuntoPdfBase64 = null;
    $nombreArchivoPdf = null;
    try{
        $datosPdf = [
            'pnr' => $pnr,
            'email' => $email,
            'nombreCliente' => $nombreCliente,
            'estado' => $estadoReserva,
            'total' => $total,
            'totalFormateado' => $totalFormateado,
            'totalEnLetras' => $totalEnLetras,
            'segmentos' => $payload['segmentos'],
            'pasajeros' => $payload['pasajeros'],
            'pago' => ['metodo' => $pagoMetodo, 'estado' => $pagoEstado],
        ];
        $bytesPdf = construirPdfComprobante($datosPdf);
        $adjuntoPdfBase64 = base64_encode($bytesPdf);
        // Nombre profesional con el PNR real de la reserva.
        $nombreArchivoPdf = 'Acajutla_Airlines_Reserva_' . preg_replace('/[^A-Za-z0-9]/', '', $pnr) . '.pdf';
    } catch(Exception $ePdf){
        // Si el PDF falla por cualquier motivo, el correo HTML se envía
        // igual, sin adjunto — nunca se rompe el envío actual por esto.
        error_log('[Acajutla Airlines] No se pudo generar el PDF del comprobante: ' . $ePdf->getMessage());
        $adjuntoPdfBase64 = null;
        $nombreArchivoPdf = null;
    }

    $nylasMessageId = enviarCorreoNylas($nylasApiKey, $nylasGrantId, $email, $asunto, $htmlCorreo, $adjuntoPdfBase64, $nombreArchivoPdf);
    actualizarEstadoEnvio($conexion, $emailOutboxId, 'sent', null, $nylasMessageId);
    cerrarConexion();
    responderJson(200, ['success' => true, 'message' => 'Comprobante enviado correctamente.']);
} catch(Exception $e){
    // El detalle técnico se guarda internamente; al frontend nunca se expone.
    error_log('[Acajutla Airlines] Error de envío vía Nylas Email API: ' . $e->getMessage());
    actualizarEstadoEnvio($conexion, $emailOutboxId, 'failed', $e->getMessage());
    cerrarConexion();
    responderJson(500, ['success' => false, 'message' => 'No pudimos enviar el comprobante. Intenta nuevamente.']);
}