<?php
/**
 * Central Payment Events Dispatcher
 * 
 * Função centralizada para disparar eventos de pagamento (UTMfy, Webhooks, Push Notifications)
 * Garante consistência entre todos os gateways e facilita manutenção
 * 
 * @param PDO $pdo Instância do PDO
 * @param string $payment_id ID da transação/pagamento
 * @param string $status Status do pagamento ('approved', 'pending', 'rejected', etc)
 * @param string $gateway_name Nome do gateway (opcional, para logs)
 * @param array|null $custom_event_data Dados customizados do evento (opcional, se não fornecido busca do banco)
 * @return bool True se sucesso, False se erro
 */
if (!function_exists('dispatch_payment_events')) {
    function dispatch_payment_events($pdo, $payment_id, $status, $gateway_name = '', $custom_event_data = null) {
        // Função de log
        $log_func = function($message) use ($gateway_name) {
            $log_file = __DIR__ . '/../webhook_log.txt';
            $prefix = !empty($gateway_name) ? "[$gateway_name] " : '';
            if (function_exists('secure_log')) {
                secure_log($log_file, $prefix . $message, 'info');
            } else {
                @file_put_contents($log_file, date('Y-m-d H:i:s') . " - " . $prefix . $message . "\n", FILE_APPEND);
            }
        };
        
        try {
            $log_func("=== DISPATCH PAYMENT EVENTS INICIADO ===");
            $log_func("Payment ID: $payment_id | Status: $status | Gateway: " . ($gateway_name ?: 'N/A'));
            
            // Normalizar status
            $status = strtolower(trim($status));
            $normalized_status = $status;
            
            // Mapear status para formato padrão
            if (in_array($status, ['paid', 'completed', 'succeeded'])) {
                $normalized_status = 'approved';
            } elseif (in_array($status, ['pix_created', 'in_process'])) {
                $normalized_status = 'pending';
            }
            
            $log_func("Status normalizado: '$status' -> '$normalized_status'");
            
            // Idempotência para pending/pix_created: evita webhook/PWA duplicados quando process_payment
            // e webhook order.created disparam quase ao mesmo tempo (SpacePag, PushinPay)
            if (in_array($normalized_status, ['pending', 'pix_created'])) {
                $temp_dir = dirname(__DIR__) . '/temp';
                if (!is_dir($temp_dir)) @mkdir($temp_dir, 0755, true);
                $idem_key = 'pending_' . md5($payment_id);
                $idem_file = $temp_dir . '/' . $idem_key;
                $idem_ttl = 120; // 2 minutos
                if (file_exists($idem_file) && (time() - filemtime($idem_file)) < $idem_ttl) {
                    $log_func("IDEMPOTÊNCIA: Evento pending já disparado para payment_id (evita duplicação). Pulando.");
                    return true;
                }
                @touch($idem_file);
            }
            
            // 1. Buscar dados da venda
            $venda_data = null;
            $all_sales = [];
            $main_sale = null;
            
            if ($custom_event_data !== null && is_array($custom_event_data)) {
                // Usar dados customizados fornecidos
                $log_func("Usando dados customizados fornecidos");
                $main_sale = $custom_event_data;
                $all_sales = [$custom_event_data];
            } else {
                // Buscar do banco
                $log_func("Buscando dados da venda no banco...");
                $stmt_venda = $pdo->prepare("
                    SELECT v.*, p.usuario_id, p.nome as produto_nome, p.checkout_config, p.checkout_hash 
                    FROM vendas v 
                    JOIN produtos p ON v.produto_id = p.id 
                    WHERE v.transacao_id = ? 
                    LIMIT 1
                ");
                $stmt_venda->execute([$payment_id]);
                $venda_data = $stmt_venda->fetch(PDO::FETCH_ASSOC);
                
                if (!$venda_data) {
                    $log_func("ERRO: Venda não encontrada para payment_id: $payment_id");
                    return false;
                }
                
                $main_sale = $venda_data;
                
                // Buscar todas as vendas relacionadas (incluindo order bumps)
                $checkout_session_uuid = $venda_data['checkout_session_uuid'] ?? null;
                if (!empty($checkout_session_uuid)) {
                    $stmt_all_sales = $pdo->prepare("
                        SELECT v.*, p.nome as produto_nome, p.checkout_config, p.checkout_hash 
                        FROM vendas v 
                        JOIN produtos p ON v.produto_id = p.id 
                        WHERE v.checkout_session_uuid = ?
                    ");
                    $stmt_all_sales->execute([$checkout_session_uuid]);
                    $all_sales = $stmt_all_sales->fetchAll(PDO::FETCH_ASSOC);
                } else {
                    $stmt_all_sales = $pdo->prepare("
                        SELECT v.*, p.nome as produto_nome, p.checkout_config, p.checkout_hash 
                        FROM vendas v 
                        JOIN produtos p ON v.produto_id = p.id 
                        WHERE v.transacao_id = ?
                    ");
                    $stmt_all_sales->execute([$payment_id]);
                    $all_sales = $stmt_all_sales->fetchAll(PDO::FETCH_ASSOC);
                }
                
                if (empty($all_sales)) {
                    $log_func("ERRO: Nenhuma venda encontrada para payment_id: $payment_id");
                    return false;
                }
            }
            
            $log_func("Total de vendas encontradas: " . count($all_sales));
            
            // 2. Preparar payloads padronizados
            $produtos_para_payload = [];
            $valor_total_compra = 0;
            
            // Se dados customizados foram fornecidos e já tem valor_total_compra, usar esse valor
            if ($custom_event_data !== null && is_array($custom_event_data) && isset($custom_event_data['valor_total_compra'])) {
                $valor_total_compra = (float)$custom_event_data['valor_total_compra'];
                $log_func("Usando valor_total_compra dos dados customizados: " . $valor_total_compra);
                
                // Preparar produtos para payload
                if (isset($custom_event_data['produto_id']) && isset($custom_event_data['produto_nome'])) {
                    $produtos_para_payload[] = [
                        'produto_id' => $custom_event_data['produto_id'],
                        'nome' => $custom_event_data['produto_nome'],
                        'valor' => $valor_total_compra
                    ];
                }
            } else {
                // Calcular a partir das vendas (busca do banco ou dados customizados sem valor_total_compra)
                foreach ($all_sales as $sale) {
                    $produto_valor = 0;
                    // Tentar pegar valor de diferentes campos possíveis
                    if (isset($sale['valor'])) {
                        $produto_valor = (float)$sale['valor'];
                    } elseif (isset($sale['valor_total_compra'])) {
                        $produto_valor = (float)$sale['valor_total_compra'];
                    } elseif (isset($sale['transaction_amount'])) {
                        $produto_valor = (float)$sale['transaction_amount'];
                    }
                    
                    $produtos_para_payload[] = [
                        'produto_id' => $sale['produto_id'] ?? $sale['id'] ?? 0,
                        'nome' => $sale['produto_nome'] ?? $sale['nome'] ?? 'Produto',
                        'valor' => $produto_valor
                    ];
                    $valor_total_compra += $produto_valor;
                }
            }
            
            $log_func("Valor total calculado: R$ " . number_format($valor_total_compra, 2, ',', '.'));
            
            // Preparar UTMs
            $utm_parameters = [];
            if (isset($main_sale['utm_source']) || isset($main_sale['utm_campaign'])) {
                $utm_parameters = [
                    'utm_source' => $main_sale['utm_source'] ?? null,
                    'utm_campaign' => $main_sale['utm_campaign'] ?? null,
                    'utm_medium' => $main_sale['utm_medium'] ?? null,
                    'utm_content' => $main_sale['utm_content'] ?? null,
                    'utm_term' => $main_sale['utm_term'] ?? null,
                    'src' => $main_sale['src'] ?? null,
                    'sck' => $main_sale['sck'] ?? null
                ];
            } elseif (isset($main_sale['utm_parameters']) && is_array($main_sale['utm_parameters'])) {
                $utm_parameters = $main_sale['utm_parameters'];
            }
            
            // Payload para UTMfy
            $utmfy_payload = [
                'transacao_id' => $payment_id,
                'valor_total_compra' => $valor_total_compra,
                'comprador' => [
                    'nome' => $main_sale['comprador_nome'] ?? '',
                    'email' => $main_sale['comprador_email'] ?? '',
                    'telefone' => $main_sale['comprador_telefone'] ?? '',
                    'cpf' => $main_sale['comprador_cpf'] ?? ''
                ],
                'metodo_pagamento' => $main_sale['metodo_pagamento'] ?? 'Pix',
                'produtos_comprados' => $produtos_para_payload,
                'data_venda' => $main_sale['data_venda'] ?? date('Y-m-d H:i:s'),
                'utm_parameters' => $utm_parameters
            ];
            
            // Payload para Webhooks
            $webhook_payload = [
                'transacao_id' => $payment_id,
                'status_pagamento' => $normalized_status,
                'valor_total_compra' => $valor_total_compra,
                'comprador' => [
                    'email' => $main_sale['comprador_email'] ?? '',
                    'nome' => $main_sale['comprador_nome'] ?? '',
                    'cpf' => $main_sale['comprador_cpf'] ?? '',
                    'telefone' => $main_sale['comprador_telefone'] ?? ''
                ],
                'metodo_pagamento' => $main_sale['metodo_pagamento'] ?? 'Pix',
                'produtos_comprados' => $produtos_para_payload,
                'data_venda' => $main_sale['data_venda'] ?? date('Y-m-d H:i:s'),
                'utm_parameters' => $utm_parameters
            ];
            
            $usuario_id = $main_sale['usuario_id'] ?? null;
            $produto_id = $main_sale['produto_id'] ?? null;
            
            if (!$usuario_id) {
                $log_func("ERRO: usuario_id não encontrado");
                return false;
            }
            
            $log_func("Usuario ID: $usuario_id | Produto ID: " . ($produto_id ?? 'NULL') . " | Valor Total: R$ " . number_format($valor_total_compra, 2, ',', '.'));
            
            // 3. Disparar UTMfy (apenas para approved, pending, rejected)
            $should_trigger_utmfy = in_array($normalized_status, ['approved', 'pending', 'rejected', 'pix_created']);
            if ($should_trigger_utmfy && function_exists('trigger_utmfy_integrations')) {
                $utmfy_event = $normalized_status;
                if ($normalized_status === 'approved') {
                    $utmfy_event = 'approved';
                } elseif ($normalized_status === 'pending' || $normalized_status === 'pix_created') {
                    $utmfy_event = 'pending';
                }
                
                $log_func("Disparando UTMfy para evento: '$utmfy_event'");
                try {
                    trigger_utmfy_integrations($usuario_id, $utmfy_payload, $utmfy_event, $produto_id);
                    $log_func("UTMfy disparado com sucesso");
                } catch (Exception $e) {
                    $log_func("ERRO ao disparar UTMfy: " . $e->getMessage());
                }
            } else {
                if (!$should_trigger_utmfy) {
                    $log_func("UTMfy não será disparado - status '$normalized_status' não requer UTMfy");
                } else {
                    $log_func("UTMfy não será disparado - função trigger_utmfy_integrations não encontrada");
                }
            }
            
            // 4. Disparar Webhooks personalizados
            $should_trigger_webhook = in_array($normalized_status, ['approved', 'pending', 'rejected', 'pix_created', 'refunded']);
            if ($should_trigger_webhook && function_exists('trigger_webhooks')) {
                $webhook_event = $normalized_status;
                if ($normalized_status === 'approved') {
                    $webhook_event = 'approved';
                } elseif ($normalized_status === 'pending' || $normalized_status === 'pix_created') {
                    $webhook_event = 'pending';
                }
                
                $log_func("Disparando Webhooks personalizados para evento: '$webhook_event'");
                try {
                    trigger_webhooks($usuario_id, $webhook_payload, $webhook_event, $produto_id);
                    $log_func("Webhooks personalizados disparados com sucesso");
                } catch (Exception $e) {
                    $log_func("ERRO ao disparar Webhooks personalizados: " . $e->getMessage());
                }
            } else {
                if (!$should_trigger_webhook) {
                    $log_func("Webhooks não serão disparados - status '$normalized_status' não requer webhook");
                } else {
                    $log_func("Webhooks não serão disparados - função trigger_webhooks não encontrada");
                }
            }
            
            // 5. Disparar Push Notifications
            $should_trigger_push = in_array($normalized_status, ['approved', 'pending', 'pix_created']);
            if ($should_trigger_push && function_exists('trigger_push_pedidos_notifications')) {
                $push_event = $normalized_status;
                if ($normalized_status === 'approved') {
                    $push_event = 'approved';
                } elseif ($normalized_status === 'pending' || $normalized_status === 'pix_created') {
                    $push_event = 'pending';
                }
                
                $log_func("Disparando Push Notifications para evento: '$push_event'");
                try {
                    trigger_push_pedidos_notifications($usuario_id, $utmfy_payload, $push_event, $produto_id);
                    $log_func("Push Notifications disparados com sucesso");
                } catch (Exception $e) {
                    $log_func("ERRO ao disparar Push Notifications: " . $e->getMessage());
                }
            } else {
                if (!$should_trigger_push) {
                    $log_func("Push Notifications não serão disparados - status '$normalized_status' não requer push");
                } else {
                    $log_func("Push Notifications não serão disparados - função trigger_push_pedidos_notifications não encontrada");
                }
            }
            
            $log_func("=== DISPATCH PAYMENT EVENTS CONCLUÍDO ===");
            return true;
            
        } catch (Exception $e) {
            $log_func("ERRO FATAL em dispatch_payment_events: " . $e->getMessage());
            $log_func("Stack trace: " . $e->getTraceAsString());
            return false;
        }
    }
}

