<?php
/**
 * Helper de Webhooks
 * 
 * Funções para disparar webhooks quando eventos de pagamento ocorrem
 */

/**
 * Função de log para webhooks (se não existir)
 */
if (!function_exists('log_webhook')) {
    function log_webhook($message) {
        $log_file = __DIR__ . '/../webhook_log.txt';
        // Usar secure_log ao invés de file_put_contents direto
        if (function_exists('secure_log')) {
            secure_log($log_file, $message, 'info');
        } else {
            @file_put_contents($log_file, date('Y-m-d H:i:s') . " - " . $message . "\n", FILE_APPEND);
        }
    }
}

/**
 * Dispara webhooks configurados para um evento específico
 * 
 * @param int $usuario_id ID do infoprodutor
 * @param array $event_data Dados do evento a serem enviados
 * @param string $trigger_event O evento que disparou (ex: 'approved', 'pending', 'pix_created')
 * @param int|null $produto_id O ID do produto associado à venda, se houver
 */
if (!function_exists('trigger_webhooks')) {
    function trigger_webhooks($usuario_id, $event_data, $trigger_event, $produto_id = null) {
        global $pdo;
        
        if (!$pdo) {
            log_webhook("WEBHOOKS: PDO não disponível. Não é possível disparar webhooks.");
            return;
        }
        
        $trigger_event = strtolower($trigger_event);
        $event_field = 'event_' . $trigger_event;
        
        // Mapear eventos para campos do banco
        if (in_array($trigger_event, ['approved', 'paid'])) {
            $event_field = 'event_approved';
        }
        if ($trigger_event == 'pix_created') {
            $event_field = 'event_pending';
        }
        
        try {
            $stmt = $pdo->prepare("SELECT url FROM webhooks WHERE usuario_id = :uid AND {$event_field} = 1 AND (produto_id IS NULL OR produto_id = :pid)");
            $stmt->execute([':uid' => $usuario_id, ':pid' => $produto_id]);
            $webhooks = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            if (empty($webhooks)) {
                log_webhook("WEBHOOKS: Nenhum webhook encontrado para evento '$trigger_event' (usuario_id: $usuario_id, produto_id: " . ($produto_id ?? 'NULL') . ")");
                return;
            }
            
            // Aplicar filtros nos dados do evento antes de criar payload
            if (function_exists('apply_filters')) {
                $event_data = apply_filters('webhook_event_data', $event_data, $trigger_event, $usuario_id, $produto_id);
            }
            
            $json_payload = json_encode([
                'event' => $trigger_event,
                'timestamp' => date('Y-m-d H:i:s'),
                'data' => $event_data
            ]);
            
            // Aplicar filtro no payload antes de enviar
            if (function_exists('apply_filters')) {
                $json_payload = apply_filters('webhook_payload', $json_payload, $trigger_event, $event_data, $usuario_id, $produto_id);
            }
            
            // Validar URLs de webhook contra SSRF
            if (!function_exists('validate_url_for_ssrf')) {
                require_once __DIR__ . '/security_helper.php';
            }
            
            if (!function_exists('get_client_ip')) {
                require_once __DIR__ . '/security_helper.php';
            }
            
            if (!function_exists('log_security_event')) {
                require_once __DIR__ . '/security_helper.php';
            }
            
            foreach ($webhooks as $url) {
                // Aplicar filtro na URL antes de validar
                if (function_exists('apply_filters')) {
                    $url = apply_filters('webhook_url', $url, $trigger_event, $usuario_id, $produto_id);
                }
                
                // Validar URL contra SSRF antes de fazer requisição
                $ssrf_validation = validate_url_for_ssrf($url);
                if (!$ssrf_validation['valid']) {
                    log_webhook("WEBHOOKS: Webhook bloqueado por SSRF: " . $url . " - " . ($ssrf_validation['error'] ?? 'Erro desconhecido'));
                    if (function_exists('log_security_event')) {
                        log_security_event('ssrf_blocked_webhook', [
                            'url' => $url,
                            'ip' => get_client_ip(),
                            'error' => $ssrf_validation['error']
                        ]);
                    }
                    // Executar hook de falha
                    if (function_exists('do_action')) {
                        do_action('webhook_failed', $url, $trigger_event, $event_data, 'SSRF validation failed');
                    }
                    continue; // Pula este webhook
                }
                
                // Preparar headers
                $headers = [
                    'Content-Type: application/json',
                    'X-Starfy-Event: ' . $trigger_event
                ];
                
                // Aplicar filtro nos headers
                if (function_exists('apply_filters')) {
                    $headers = apply_filters('webhook_headers', $headers, $trigger_event, $url, $usuario_id, $produto_id);
                }
                
                // Executar hook antes de disparar webhook
                if (function_exists('do_action')) {
                    do_action('before_trigger_webhook', $url, $trigger_event, $event_data, $usuario_id, $produto_id);
                }
                
                log_webhook("WEBHOOKS: Disparando webhook para evento '$trigger_event' - URL: $url");
                
                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $json_payload);
                curl_setopt($ch, CURLOPT_TIMEOUT, 5);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false); // Não seguir redirecionamentos (prevenção SSRF)
                
                $response = curl_exec($ch);
                $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curl_error = curl_error($ch);
                curl_close($ch);
                
                // Executar hook depois de disparar webhook
                if (function_exists('do_action')) {
                    do_action('after_trigger_webhook', $url, $trigger_event, $event_data, $http_code, $response, $usuario_id, $produto_id);
                }
                
                if ($curl_error) {
                    log_webhook("WEBHOOKS: Erro cURL ao disparar webhook para $url: " . $curl_error);
                    // Executar hook de falha
                    if (function_exists('do_action')) {
                        do_action('webhook_failed', $url, $trigger_event, $event_data, $curl_error);
                    }
                } else {
                    log_webhook("WEBHOOKS: Webhook disparado com sucesso para $url (HTTP $http_code)");
                    // Executar hook de sucesso
                    if (function_exists('do_action')) {
                        do_action('webhook_sent', $url, $trigger_event, $event_data, $http_code, $response);
                    }
                }
            }
        } catch (Exception $e) {
            log_webhook("WEBHOOKS: Erro ao disparar webhooks: " . $e->getMessage());
            // Não lança exceção para não interromper o fluxo de pagamento
        }
    }
}
?>

