<?php
/**
 * =====================================================================
 * ACAJUTLA AIRLINES — php/dte_helper.php
 *
 * Emisión directa de Documentos Tributarios Electrónicos (DTE) tipo 01 (Factura Electrónica)
 * en la base de datos compartida Aiven (tablas dte_headers y dte_items).
 *
 * Esto garantiza que cada compra completada en la página web aparezca
 * de forma inmediata en el módulo "Facturación Electrónica (DTE)"
 * del sistema SkyManager / Backoffice de tu compañero, con el formato
 * exacto exigido por el Ministerio de Hacienda (El Salvador) y su backend.
 * =====================================================================
 */

function generarUuidV4(){
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // versión 4
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // variante RFC 4122
    return strtoupper(vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4)));
}

function emitirDteWeb($conexion, $reservationId, $pnr, $clienteNombre, $contactoDocTipo, $contactoDocNum, $contactoEmail, $contactoTel, $metodoPago, $montoTotal, $flightNumbers) {
    try {
        $tipoDte = '01'; // 01 = Factura Electrónica consumidor final
        $anio = (int)date('Y');

        // 1. Obtener siguiente correlativo anual
        $stmtCorr = mysqli_prepare($conexion, "SELECT COALESCE(MAX(annual_correlative), 0) + 1 AS n FROM dte_headers WHERE dte_type = ? AND YEAR(emission_date) = ?");
        if(!$stmtCorr) return false;
        mysqli_stmt_bind_param($stmtCorr, 'si', $tipoDte, $anio);
        mysqli_stmt_execute($stmtCorr);
        $resCorr = mysqli_stmt_get_result($stmtCorr);
        $rowCorr = mysqli_fetch_assoc($resCorr);
        $seq = $rowCorr ? (int)$rowCorr['n'] : 1;
        mysqli_stmt_close($stmtCorr);

        $establecimiento = 'M001';
        $puntoVenta = 'P001';
        $numControl = 'DTE-' . $tipoDte . '-' . $establecimiento . $puntoVenta . '-' . str_pad($seq, 15, '0', STR_PAD_LEFT);
        $codigoGeneracion = generarUuidV4();
        $fecEmi = date('Y-m-d');
        $horEmi = date('H:i:s');

        // 2. Mapeos según CAT-022 y CAT-017 del Ministerio de Hacienda
        $mapaDoc = ['DUI' => '13', 'PASAPORTE' => '3', 'NIT' => '36', 'CARNET_MENOR' => '02'];
        $receptorDocTipo = $mapaDoc[strtoupper($contactoDocTipo ?? '')] ?? '13';
        $receptorDocNum = $contactoDocNum ?: null;

        $mapaPagoCat = ['card' => '03', 'cash' => '01', 'transfer' => '05', 'paypal' => '08'];
        $cat017 = $mapaPagoCat[strtolower($metodoPago ?? '')] ?? '03';

        // 3. Consultar pasajeros reales de la reserva para generar los items
        $paxRows = [];
        $stmtPax = mysqli_prepare($conexion, "SELECT id, first_names, last_names FROM passengers WHERE reservation_id = ? ORDER BY id");
        if($stmtPax){
            mysqli_stmt_bind_param($stmtPax, 'i', $reservationId);
            mysqli_stmt_execute($stmtPax);
            $resPax = mysqli_stmt_get_result($stmtPax);
            while($p = mysqli_fetch_assoc($resPax)){
                $paxRows[] = $p;
            }
            mysqli_stmt_close($stmtPax);
        }
        $numPax = count($paxRows) > 0 ? count($paxRows) : 1;

        // 4. Consultar líneas de impuestos congeladas para esta reserva
        $totalIva = 0.0;
        $totalOtrosTributos = 0.0;
        $otrosTributosMap = [];
        $stmtTaxes = mysqli_prepare($conexion, "SELECT code, name, amount FROM reservation_tax_lines WHERE reservation_id = ?");
        if($stmtTaxes){
            mysqli_stmt_bind_param($stmtTaxes, 'i', $reservationId);
            mysqli_stmt_execute($stmtTaxes);
            $resTaxes = mysqli_stmt_get_result($stmtTaxes);
            while($tax = mysqli_fetch_assoc($resTaxes)){
                $code = strtoupper(trim($tax['code'] ?? ''));
                $amount = (float)$tax['amount'];
                if($code === '20' || $code === 'IVA'){
                    $totalIva += $amount;
                } else {
                    $totalOtrosTributos += $amount;
                    if(!isset($otrosTributosMap[$code])){
                        $otrosTributosMap[$code] = ['codigo' => $code, 'descripcion' => $tax['name'], 'valor' => 0.0];
                    }
                    $otrosTributosMap[$code]['valor'] += $amount;
                }
            }
            mysqli_stmt_close($stmtTaxes);
        }

        // Si no había líneas de IVA registradas en reservation_tax_lines, aproximamos el cálculo estándar (13% incluido)
        if($totalIva <= 0 && $montoTotal > 0){
            $totalIva = round(($montoTotal / 1.13) * 0.13, 2);
        }

        $vuelosStr = is_array($flightNumbers) ? implode(', ', $flightNumbers) : (string)$flightNumbers;
        $totalGravada = round($montoTotal - $totalOtrosTributos, 2);
        $precioUnitarioItem = round($totalGravada / $numPax, 8);
        $ivaPorPax = round($totalIva / $numPax, 8);

        // 5. Construir el cuerpo de items del DTE
        $cuerpoItems = [];
        $idx = 1;
        if(count($paxRows) > 0){
            foreach($paxRows as $p){
                $cuerpoItems[] = [
                    'numItem' => $idx++,
                    'tipoItem' => 2, // Servicio
                    'codigo' => 'PAX-' . $p['id'],
                    'codTributo' => null,
                    'uniMedida' => 99,
                    'descripcion' => 'Boleto aéreo - ' . $p['first_names'] . ' ' . $p['last_names'] . ' (Vuelos: ' . $vuelosStr . ')',
                    'precioUni' => $precioUnitarioItem,
                    'montoDescu' => 0,
                    'ventaNoSuj' => 0,
                    'ventaExenta' => 0,
                    'ventaGravada' => $precioUnitarioItem,
                    'tributos' => null,
                    'ivaItem' => $ivaPorPax,
                    'psv' => 0,
                    'noGravado' => 0
                ];
            }
        } else {
            $cuerpoItems[] = [
                'numItem' => 1,
                'tipoItem' => 2,
                'codigo' => 'PAX-WEB',
                'codTributo' => null,
                'uniMedida' => 99,
                'descripcion' => 'Boleto aéreo - ' . $clienteNombre . ' (Vuelos: ' . $vuelosStr . ')',
                'precioUni' => $precioUnitarioItem,
                'montoDescu' => 0,
                'ventaNoSuj' => 0,
                'ventaExenta' => 0,
                'ventaGravada' => $precioUnitarioItem,
                'tributos' => null,
                'ivaItem' => $ivaPorPax,
                'psv' => 0,
                'noGravado' => 0
            ];
        }

        $tributosResumen = array_values($otrosTributosMap);

        // 6. JSON conforme a la estructura de Hacienda exigida por SkyManager
        $fullJson = [
            'identificacion' => [
                'version' => 3,
                'ambiente' => '00',
                'tipoDte' => $tipoDte,
                'codigoGeneracion' => $codigoGeneracion,
                'numeroControl' => $numControl,
                'tipoModelo' => 1,
                'tipoOperacion' => 1,
                'fecEmi' => $fecEmi,
                'horEmi' => $horEmi,
                'tipoMoneda' => 'USD'
            ],
            'emisor' => [
                'nit' => '06141234561010',
                'nrc' => '1234567',
                'nombre' => 'ACAJUTLA AIRLINES S.A. DE C.V.',
                'codActividad' => '51100',
                'descActividad' => 'Transporte aéreo de pasajeros',
                'nombreComercial' => 'ACAJUTLA AIRLINES',
                'direccion' => [
                    'departamento' => '03',
                    'municipio' => '18',
                    'distrito' => '01',
                    'complemento' => 'Puerto de Acajutla, Sonsonate, Distrito Acajutla'
                ],
                'telefono' => '24580000',
                'correo' => 'facturacion@acajutlaairlines.com',
                'codEstable' => $establecimiento,
                'codPuntoVenta' => $puntoVenta
            ],
            'receptor' => [
                'tipoDocumento' => $receptorDocTipo,
                'numDocumento' => $receptorDocNum,
                'nombre' => !empty($clienteNombre) ? $clienteNombre : 'CLIENTE FINAL',
                'telefono' => $contactoTel ?: null,
                'correo' => $contactoEmail ?: null
            ],
            'cuerpoDocumento' => $cuerpoItems,
            'resumen' => [
                'totalNoSuj' => 0,
                'totalExenta' => 0,
                'totalGravada' => $totalGravada,
                'subTotalVentas' => $totalGravada,
                'descuNoSuj' => 0,
                'descuExenta' => 0,
                'descuGravada' => 0,
                'porcentajeDescuento' => 0,
                'totalDescu' => 0,
                'tributos' => count($tributosResumen) ? $tributosResumen : null,
                'subTotal' => $totalGravada,
                'ivaRete' => 0,
                'totalIva' => round($totalIva, 2),
                'montoTotalOperacion' => round($montoTotal, 2),
                'totalNoGravado' => 0,
                'totalPagar' => round($montoTotal, 2),
                'condicionOperacion' => 1,
                'pagos' => [
                    [
                        'codigo' => $cat017,
                        'montoPago' => round($montoTotal, 2),
                        'referencia' => null,
                        'plazo' => null,
                        'periodo' => null
                    ]
                ]
            ],
            'apendice' => [
                ['campo' => 'PNR', 'etiqueta' => 'Código de reserva', 'valor' => $pnr],
                ['campo' => 'VUELOS', 'etiqueta' => 'Vuelos facturados', 'valor' => $vuelosStr]
            ]
        ];

        // 7. Simular sello de recepción de Hacienda (mismo algoritmo que dteService.js)
        $selloRecepcion = 'SELLO-' . strtoupper(bin2hex(random_bytes(12)));
        $transmissionStatus = 'accepted';

        // 8. Inserción en dte_headers
        $stmtHead = mysqli_prepare($conexion,
            "INSERT INTO dte_headers (
                uuid_generation, dte_type, control_number, annual_correlative, emission_date, emission_time,
                environment, billing_model, operation_type, transmission_status, reception_seal, reception_date,
                reservation_id, full_json, issuer_nit, issuer_name, receiver_name, receiver_doc_type, receiver_doc_number,
                total_non_taxable, total_exempt, total_taxable, total_vat, total_to_pay, created_at
            ) VALUES (
                ?, ?, ?, ?, ?, ?,
                '00', 1, 1, ?, ?, NOW(),
                ?, ?, '06141234561010', 'ACAJUTLA AIRLINES S.A. DE C.V.', ?, ?, ?,
                0, 0, ?, ?, ?, NOW()
            )"
        );

        if(!$stmtHead){
            error_log('[Acajutla Airlines DTE] Error preparando dte_headers: ' . mysqli_error($conexion));
            return false;
        }

        $fullJsonStr = json_encode($fullJson, JSON_UNESCAPED_UNICODE);
        $recDocNumStr = (string)$receptorDocNum;

        mysqli_stmt_bind_param(
            $stmtHead,
            'sssisssssssssdddd',
            $codigoGeneracion,
            $tipoDte,
            $numControl,
            $seq,
            $fecEmi,
            $horEmi,
            $transmissionStatus,
            $selloRecepcion,
            $reservationId,
            $fullJsonStr,
            $clienteNombre,
            $receptorDocTipo,
            $recDocNumStr,
            $totalGravada,
            $totalIva,
            $montoTotal
        );

        if(!mysqli_stmt_execute($stmtHead)){
            error_log('[Acajutla Airlines DTE] Error insertando dte_headers: ' . mysqli_stmt_error($stmtHead));
            mysqli_stmt_close($stmtHead);
            return false;
        }

        $headerId = mysqli_insert_id($conexion);
        mysqli_stmt_close($stmtHead);

        // 9. Inserción en dte_items
        $stmtItem = mysqli_prepare($conexion,
            "INSERT INTO dte_items (
                header_id, item_number, item_type, quantity, unit_measure,
                description, unit_price, discount_amount, sale_non_taxable, sale_exempt,
                sale_taxable, vat_item, tribute_code
            ) VALUES (?, ?, 2, 1, 99, ?, ?, 0, 0, 0, ?, ?, NULL)"
        );

        if($stmtItem){
            foreach($cuerpoItems as $it){
                $itNum = (int)$it['numItem'];
                $itDesc = (string)$it['descripcion'];
                $itPrecio = (float)$it['precioUni'];
                $itGravada = (float)$it['ventaGravada'];
                $itIva = (float)$it['ivaItem'];
                mysqli_stmt_bind_param($stmtItem, 'iisddd', $headerId, $itNum, $itDesc, $itPrecio, $itGravada, $itIva);
                mysqli_stmt_execute($stmtItem);
            }
            mysqli_stmt_close($stmtItem);
        }

        error_log("[Acajutla Airlines DTE] DTE emitido exitosamente para PNR $pnr (UUID: $codigoGeneracion, Control: $numControl)");
        return true;

    } catch (Exception $e) {
        error_log('[Acajutla Airlines DTE] Excepción al emitir DTE: ' . $e->getMessage());
        return false;
    }
}
