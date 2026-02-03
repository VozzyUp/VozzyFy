<?php
// =================================================================================
// ARQUIVO AUXILIAR: Lógica de envio para UTMfy
// Inclua este arquivo em process_payment.php e notification.php
// =================================================================================

if (!function_exists('log_utmfy_helper')) {
    function log_utmfy_helper($message) {
        $logFile = __DIR__ . '/utmfy_debug.log';
        file_put_contents($logFile, date('Y-m-d H:i:s') . " - " . $message . "\n", FILE_APPEND);
    }
}

if (!function_exists('trigger_utmfy_integrations')) {
    /**
     * Dispara eventos para integração com a UTMfy.
     */
    function trigger_utmfy_integrations($usuario_id, $event_data, $trigger_event, $produto_id = null, $gateway_data = []) {
        global $pdo;

        // 1. NORMALIZAÇÃO
        $trigger_event = strtolower(trim($trigger_event));
        
        // 2. MAPEAMENTO DE STATUS
        $status_map = [
            'approved'     => 'paid',
            'paid'         => 'paid',
            'completed'    => 'paid', 
            'succeeded'    => 'paid',
            'pix_created'  => 'waiting_payment',
            'pending'      => 'waiting_payment',
            'in_process'   => 'waiting_payment',
            'rejected'     => 'refused',
            'cancelled'    => 'refused',
            'refunded'     => 'refunded',
            'charged_back' => 'chargedback'
        ];

        $utmfy_status = $status_map[$trigger_event] ?? 'waiting_payment';
        
        log_utmfy_helper("=== Disparo UTMfy Iniciado ===");
        log_utmfy_helper("Evento: '$trigger_event' -> Status UTMfy: '$utmfy_status' | ID Transação: " . ($event_data['transacao_id'] ?? 'N/A'));

        // 3. MAPEAMENTO DE COLUNAS (CORREÇÃO DO ERRO SQL)
        // Define qual coluna do banco verificar (event_approved, event_pending, etc)
        $event_field = 'event_' . $trigger_event; 
        
        // CORREÇÃO: Se for aprovado, usa 'event_approved' (que existe no banco) em vez de 'event_purchase'
        if (in_array($trigger_event, ['approved', 'paid', 'completed', 'succeeded'])) {
            $event_field = 'event_approved'; 
        }
        if ($trigger_event == 'pix_created')  $event_field = 'event_pending';
        if ($trigger_event == 'in_process')   $event_field = 'event_pending';
        if ($trigger_event == 'cancelled')    $event_field = 'event_rejected';
        if ($trigger_event == 'charged_back') $event_field = 'event_chargeback';

        try {
            // Busca as integrações ativas
            $sql = "SELECT api_token, name, product_id 
                    FROM utmfy_integrations 
                    WHERE usuario_id = :uid 
                    AND {$event_field} = 1 
                    AND (product_id IS NULL OR product_id = :pid)";
                    
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':uid' => $usuario_id, ':pid' => $produto_id]);
            $integrations = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($integrations)) {
                log_utmfy_helper("AVISO: Nenhuma integração ativa encontrada para User: $usuario_id na coluna '$event_field'.");
                return;
            }

            // 4. PREPARAÇÃO DO PAYLOAD
            $payment_method_raw = $event_data['metodo_pagamento'] ?? '';
            $utmfy_payment_method = 'free_price'; 
            
            if (stripos($payment_method_raw, 'pix') !== false) $utmfy_payment_method = 'pix';
            elseif (stripos($payment_method_raw, 'boleto') !== false) $utmfy_payment_method = 'boleto';
            elseif (stripos($payment_method_raw, 'cart') !== false || stripos($payment_method_raw, 'credit') !== false) $utmfy_payment_method = 'credit_card';

            $customer_phone = preg_replace('/[^0-9]/', '', $event_data['comprador']['telefone']);
            $customer_document = preg_replace('/[^0-9]/', '', $event_data['comprador']['cpf']);
            
            // TRATAMENTO DOS PRODUTOS
            $products_payload = [];
            $total_cents = 0;
            
            log_utmfy_helper("Produtos recebidos: " . (isset($event_data['produtos_comprados']) ? count($event_data['produtos_comprados']) : 0));
            
            if (!empty($event_data['produtos_comprados']) && is_array($event_data['produtos_comprados'])) {
                foreach ($event_data['produtos_comprados'] as $idx => $prod) {
                    $cents = (int)(round((float)$prod['valor'], 2) * 100);
                    
                    // Garante que o nome não vá vazio
                    $prod_name = !empty($prod['nome']) ? $prod['nome'] : ($prod['produto_nome'] ?? '');
                    if (empty($prod_name) || trim($prod_name) === '') {
                        $prod_name = "Produto ID " . ($prod['produto_id'] ?? 'Unknown');
                    }

                    $products_payload[] = [
                        'id' => (string)($prod['produto_id'] ?? $produto_id), 
                        'name' => (string)$prod_name, 
                        'planId' => null, 'planName' => null, 'quantity' => 1, 'priceInCents' => $cents
                    ];
                    $total_cents += $cents;
                    
                    log_utmfy_helper("Produto $idx: ID=" . ($prod['produto_id'] ?? 'N/A') . ", Nome=" . $prod_name . ", Valor=" . $prod['valor']);
                }
            } else {
                log_utmfy_helper("Nenhum produto encontrado, usando valor total: " . ($event_data['valor_total_compra'] ?? 'N/A'));
                $total_cents = (int)(round((float)$event_data['valor_total_compra'], 2) * 100);
                $products_payload[] = [
                    'id' => (string)($produto_id ?? 'DEFAULT'), 
                    'name' => 'Produto Principal', 
                    'planId' => null, 'planName' => null, 'quantity' => 1, 'priceInCents' => $total_cents
                ];
            }
            
            log_utmfy_helper("Total processado: " . count($products_payload) . " produtos, Total em centavos: " . $total_cents);

            $convert_to_utc = function($date_string) {
                if (empty($date_string)) return gmdate('Y-m-d H:i:s');
                try {
                    $datetime = new DateTime($date_string, new DateTimeZone('America/Sao_Paulo'));
                    $datetime->setTimezone(new DateTimeZone('UTC'));
                    return $datetime->format('Y-m-d H:i:s');
                } catch (Exception $e) { return gmdate('Y-m-d H:i:s'); }
            };

            $created_at = $convert_to_utc($event_data['data_venda'] ?? null);
            $approved_date = null;
            if ($utmfy_status === 'paid') {
                $approved_date = isset($gateway_data['date_approved']) ? $convert_to_utc($gateway_data['date_approved']) : gmdate('Y-m-d H:i:s');
            }
            $refunded_at = ($trigger_event === 'refunded') ? gmdate('Y-m-d H:i:s') : null;

            // Aplicar filtro no mapeamento de status antes de criar payload
            if (function_exists('apply_filters')) {
                $utmfy_status = apply_filters('utmfy_status_mapping', $utmfy_status, $trigger_event, $event_data);
            }
            
            // Aplicar filtro nos dados do evento antes de criar payload
            if (function_exists('apply_filters')) {
                $event_data = apply_filters('utmfy_event_data', $event_data, $trigger_event, $usuario_id, $produto_id);
            }
            
            $payload = [
                'orderId' => (string)$event_data['transacao_id'],
                'platform' => 'Getfy',
                'paymentMethod' => $utmfy_payment_method,
                'status' => $utmfy_status,
                'createdAt' => $created_at,
                'approvedDate' => $approved_date,
                'refundedAt' => $refunded_at,
                'customer' => [
                    'name' => $event_data['comprador']['nome'],
                    'email' => $event_data['comprador']['email'],
                    'phone' => $customer_phone,
                    'document' => $customer_document,
                    'country' => 'BR',
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
                ],
                'products' => $products_payload,
                'trackingParameters' => [
                    'src' => $event_data['utm_parameters']['src'] ?? null,
                    'sck' => $event_data['utm_parameters']['sck'] ?? null,
                    'utm_source' => $event_data['utm_parameters']['utm_source'] ?? null,
                    'utm_campaign' => $event_data['utm_parameters']['utm_campaign'] ?? null,
                    'utm_medium' => $event_data['utm_parameters']['utm_medium'] ?? null,
                    'utm_content' => $event_data['utm_parameters']['utm_content'] ?? null,
                    'utm_term' => $event_data['utm_parameters']['utm_term'] ?? null
                ],
                'commission' => [
                    'totalPriceInCents' => $total_cents,
                    'gatewayFeeInCents' => 0, 
                    'userCommissionInCents' => $total_cents,
                    'currency' => 'BRL'
                ],
                'isTest' => false
            ];

            // Aplicar filtro no payload antes de enviar
            if (function_exists('apply_filters')) {
                $payload = apply_filters('utmfy_payload', $payload, $trigger_event, $event_data, $usuario_id, $produto_id);
            }
            
            $json_payload = json_encode($payload);
            log_utmfy_helper("Payload JSON gerado (" . strlen($json_payload) . " bytes)");

            if (empty($integrations)) {
                log_utmfy_helper("ERRO: Nenhuma integração encontrada para enviar!");
                return;
            }

            foreach ($integrations as $int) {
                log_utmfy_helper("Enviando para integração: " . $int['name'] . " (Token: " . substr($int['api_token'], 0, 10) . "...)");
                
                // Validação SSRF antes de fazer requisição
                $api_url = 'https://api.utmify.com.br/api-credentials/orders';
                
                // Aplicar filtro na URL da API (permitir endpoints customizados)
                if (function_exists('apply_filters')) {
                    $api_url = apply_filters('utmfy_api_url', $api_url, $int, $trigger_event, $usuario_id, $produto_id);
                }
                
                require_once __DIR__ . '/security_helper.php';
                if (function_exists('validate_url_for_ssrf')) {
                    $ssrf_validation = validate_url_for_ssrf($api_url);
                    if (!$ssrf_validation['valid']) {
                        log_utmfy_helper("ERRO SSRF: URL bloqueada - " . $ssrf_validation['error']);
                        // Executar hook de falha
                        if (function_exists('do_action')) {
                            do_action('utmfy_failed', $api_url, $int['name'], $trigger_event, $event_data, 'SSRF validation failed');
                        }
                        continue; // Pula esta integração
                    }
                }
                
                // Executar hook antes de disparar UTMfy
                if (function_exists('do_action')) {
                    do_action('before_trigger_utmfy', $api_url, $int, $trigger_event, $event_data, $usuario_id, $produto_id);
                }
                
                $headers = ['Content-Type: application/json', 'x-api-token: ' . $int['api_token']];
                
                $ch = curl_init($api_url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $json_payload);
                curl_setopt($ch, CURLOPT_TIMEOUT, 5); // Timeout reduzido para 5 segundos
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true); // Habilitar verificação SSL
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false); // Desabilitar redirects para prevenir SSRF

                $response = curl_exec($ch);
                $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curl_error = curl_error($ch);
                curl_close($ch);

                // Executar hook depois de disparar UTMfy
                if (function_exists('do_action')) {
                    do_action('after_trigger_utmfy', $api_url, $int, $trigger_event, $event_data, $http_code, $response, $usuario_id, $produto_id);
                }

                if ($curl_error) {
                    log_utmfy_helper("ERRO cURL: " . $curl_error);
                    // Executar hook de falha
                    if (function_exists('do_action')) {
                        do_action('utmfy_failed', $api_url, $int['name'], $trigger_event, $event_data, $curl_error);
                    }
                }
                
                log_utmfy_helper("Resposta UTMfy ($http_code): " . substr($response, 0, 300));
                
                if ($http_code >= 200 && $http_code < 300) {
                    log_utmfy_helper("✓ Evento enviado com sucesso para " . $int['name']);
                    // Executar hook de sucesso
                    if (function_exists('do_action')) {
                        do_action('utmfy_sent', $api_url, $int['name'], $trigger_event, $event_data, $http_code, $response);
                    }
                } else {
                    log_utmfy_helper("✗ Falha ao enviar para " . $int['name'] . " (HTTP $http_code)");
                    // Executar hook de falha
                    if (function_exists('do_action')) {
                        do_action('utmfy_failed', $api_url, $int['name'], $trigger_event, $event_data, "HTTP $http_code: $response");
                    }
                }
            }
            
            log_utmfy_helper("=== Disparo UTMfy Finalizado ===");

        } catch (Exception $e) {
            log_utmfy_helper("ERRO GERAL UTMfy: " . $e->getMessage());
        }
    }
}
?>