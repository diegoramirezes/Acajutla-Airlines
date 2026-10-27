<?php
/**
 * =====================================================================
 * ACAJUTLA AIRLINES — php/dte_service.php
 *
 * Emisión automática de Facturación Electrónica (DTE tipo 01)
 * compatible con SkyManager y Ministerio de Hacienda de El Salvador.
 * Se ejecuta de forma segura e independiente al completar una reserva.
 * =====================================================================
 */

function generarUuidV4Dte() {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return strtoupper(vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4)));
}

function emitirDteAutomatico($conexion, $reservationId, $pnr, $clienteNombre, $contactoDocTipo, $contactoDocNum, $contactoEmail, $contactoTel, $metodoPago, $montoTotal, $flightNumbers) {
    // Todo envuelto en try/catch para que NUNCA interrumpa la respuesta de reserva al cliente
    try {
        if(!$conexion || !$reservationId) return false;

        $tipoDte = '01'; // Factura Electrónica
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
        $codigoGeneracion = generarUuidV4Dte();
        $fecEmi = date('Y-m-d');
        $horEmi = date('H:i:s');

        // Mapeo según CAT-022 y CAT-017 del Ministerio de Hacienda
        $mapaDoc = ['DUI' => '13', 'PASAPORTE' => '3', 'NIT' => '36', 'CARNET_MENOR' => '37'];
        $docTipo = $mapaDoc[strtoupper($contactoDocTipo ?? '')] ?? '13';
        $docNum = $contactoDocNum ? (string)$contactoDocNum : null;

        $mapaMetodo = ['card' => '03', 'cash' => '01', 'transfer' => '05', 'paypal' => '08'];
        $codPago = $mapaMetodo[strtolower($metodoPago ?? '')] ?? '03';

        // 2. Obtener pasajeros
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

        // 3. Obtener líneas de impuestos
        $totalIva = 0.0;
        $totalOtros = 0.0;
        $otrosTributosMap = [];
        $stmtTaxes = mysqli_prepare($conexion, "SELECT code, name, amount FROM reservation_tax_lines WHERE reservation_id = ?");
        if($stmtTaxes){
            mysqli_stmt_bind_param($stmtTaxes, 'i', $reservationId);
            mysqli_stmt_execute($stmtTaxes);
            $resTaxes = mysqli_stmt_get_result($stmtTaxes);
            while($t = mysqli_fetch_assoc($resTaxes)){
                $code = strtoupper(trim($t['code'] ?? ''));
                $m = (float)$t['amount'];
                if($code === '20' || $code === 'IVA'){
                    $totalIva += $m;
                } else {
                    $totalOtros += $m;
                    if(!isset($otrosTributosMap[$code])){
                        $otrosTributosMap[$code] = ['codigo' => $code, 'descripcion' => $t['name'], 'valor' => 0.0];
                    }
                    $otrosTributosMap[$code]['valor'] += $m;
                }
            }
            mysqli_stmt_close($stmtTaxes);
        }

        if($totalIva <= 0 && $montoTotal > 0){
            $totalIva = round(($montoTotal / 1.13) * 0.13, 2);
        }

        $vuelosTxt = is_array($flightNumbers) ? implode(', ', $flightNumbers) : (string)$flightNumbers;
        $totalGravada = round($montoTotal - $totalOtros, 2);
        $precioUnitario = round($totalGravada / $numPax, 8);
        $ivaUnitario = round($totalIva / $numPax, 8);

        // 4. Construir cuerpo
        $cuerpo = [];
        $idx = 1;
        if(count($paxRows) > 0){
            foreach($paxRows as $p){
                $cuerpo[] = [
                    'numItem' => $idx++,
                    'tipoItem' => 2,
                    'codigo' => 'PAX-' . $p['id'],
                    'codTributo' => null,
                    'uniMedida' => 99,
                    'descripcion' => 'Boleto aéreo - ' . $p['first_names'] . ' ' . $p['last_names'] . ' (Vuelos: ' . $vuelosTxt . ')',
                    'precioUni' => $precioUnitario,
                    'montoDescu' => 0,
                    'ventaNoSuj' => 0,
                    'ventaExenta' => 0,
                    'ventaGravada' => $precioUnitario,
                    'tributos' => null,
                    'ivaItem' => $ivaUnitario,
                    'psv' => 0,
                    'noGravado' => 0
                ];
            }
        } else {
            $cuerpo[] = [
                'numItem' => 1,
                'tipoItem' => 2,
                'codigo' => 'PAX-WEB',
                'codTributo' => null,
                'uniMedida' => 99,
                'descripcion' => 'Boleto aéreo - ' . ($clienteNombre ?: 'CLIENTE FINAL') . ' (Vuelos: ' . $vuelosTxt . ')',
                'precioUni' => $precioUnitario,
                'montoDescu' => 0,
                'ventaNoSuj' => 0,
                'ventaExenta' => 0,
                'ventaGravada' => $precioUnitario,
                'tributos' => null,
                'ivaItem' => $ivaUnitario,
                'psv' => 0,
                'noGravado' => 0
            ];
        }

        $tributosResumen = array_values($otrosTributosMap);

        // 5. Objeto JSON oficial
        $dte = [
            'identificacion' => [
                'version' => 3,
                'ambiente' => '00',
                'tipoDte' => $tipoDte,
                'codigoGeneracion' => $codigoGeneracion,
                'numeroControl' => $numControl,
                'tipoModelo' => 1,
                'tipoOperacion' => 1,
                'tipoContingencia' => null,
                'motivoContin' => null,
                'fecEmi' => $fecEmi,
                'horEmi' => $horEmi,
                'tipoMoneda' => 'USD'
            ],
            'documentoRelacionado' => null,
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
                'tipoDocumento' => $docTipo,
                'numDocumento' => $docNum,
                'nit' => null,
                'nrc' => null,
                'nombre' => !empty($clienteNombre) ? $clienteNombre : 'CLIENTE FINAL',
                'codActividad' => null,
                'descActividad' => null,
                'nombreComercial' => null,
                'direccion' => null,
                'telefono' => $contactoTel ?: null,
                'correo' => $contactoEmail ?: null
            ],
            'otrosDocumentos' => null,
            'ventaTercero' => null,
            'compraTercero' => null,
            'cuerpoDocumento' => $cuerpo,
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
                'totalLetras' => null,
                'condicionOperacion' => 1,
                'pagos' => [
                    [
                        'codigo' => $codPago,
                        'montoPago' => round($montoTotal, 2),
                        'referencia' => null,
                        'plazo' => null,
                        'periodo' => null
                    ]
                ],
                'numPagoElectronico' => null,
                'observaciones' => null
            ],
            'apendice' => [
                ['campo' => 'PNR', 'etiqueta' => 'Código de reserva', 'valor' => $pnr],
                ['campo' => 'VUELOS', 'etiqueta' => 'Vuelos facturados', 'valor' => $vuelosTxt]
            ]
        ];

        $sello = 'SELLO-' . strtoupper(bin2hex(random_bytes(12)));
        $jsonStr = json_encode($dte, JSON_UNESCAPED_UNICODE);
        $receptorNombre = !empty($clienteNombre) ? $clienteNombre : 'CLIENTE FINAL';
        $totalPagar = round($montoTotal, 2);

        // 6. Inserción en dte_headers
        $stmtHead = mysqli_prepare($conexion,
            "INSERT INTO dte_headers (
                uuid_generation, dte_type, control_number, annual_correlative, emission_date, emission_time,
                environment, billing_model, operation_type, transmission_status, reception_seal, reception_date,
                reservation_id, full_json, issuer_nit, issuer_name, receiver_name, receiver_doc_type, receiver_doc_number,
                total_non_taxable, total_exempt, total_taxable, total_vat, total_to_pay, created_at
            ) VALUES (
                ?, ?, ?, ?, ?, ?,
                '00', 1, 1, 'accepted', ?, NOW(),
                ?, ?, '06141234561010', 'ACAJUTLA AIRLINES S.A. DE C.V.', ?, ?, ?,
                0.00, 0.00, ?, ?, ?, NOW()
            )"
        );

        if($stmtHead){
            mysqli_stmt_bind_param(
                $stmtHead,
                'sssisssissssddd',
                $codigoGeneracion,
                $tipoDte,
                $numControl,
                $seq,
                $fecEmi,
                $horEmi,
                $sello,
                $reservationId,
                $jsonStr,
                $receptorNombre,
                $docTipo,
                $docNum,
                $totalGravada,
                $totalIva,
                $totalPagar
            );
            $ejecutado = mysqli_stmt_execute($stmtHead);
            $headerId = $ejecutado ? mysqli_insert_id($conexion) : null;
            mysqli_stmt_close($stmtHead);

            // 7. Inserción en dte_items
            if($headerId){
                $stmtItem = mysqli_prepare($conexion,
                    "INSERT INTO dte_items (
                        header_id, item_number, item_type, quantity, unit_measure,
                        description, unit_price, discount_amount, sale_non_taxable, sale_exempt,
                        sale_taxable, vat_item, tribute_code
                    ) VALUES (?, ?, 2, 1.00000000, 99, ?, ?, 0.00000000, 0.00000000, 0.00000000, ?, ?, NULL)"
                );
                if($stmtItem){
                    foreach($cuerpo as $it){
                        $itNum = (int)$it['numItem'];
                        $itDesc = (string)$it['descripcion'];
                        $itPrice = (float)$it['precioUni'];
                        $itGrav = (float)$it['ventaGravada'];
                        $itVat = (float)$it['ivaItem'];
                        mysqli_stmt_bind_param($stmtItem, 'iisddd', $headerId, $itNum, $itDesc, $itPrice, $itGrav, $itVat);
                        mysqli_stmt_execute($stmtItem);
                    }
                    mysqli_stmt_close($stmtItem);
                }
                error_log("[Acajutla Airlines DTE] Éxito: DTE generado para PNR $pnr ($numControl)");
                return true;
            }
        }
        return false;
    } catch (Exception $e) {
        error_log('[Acajutla Airlines DTE] Excepción no crítica en DTE: ' . $e->getMessage());
        return false;
    }
}
