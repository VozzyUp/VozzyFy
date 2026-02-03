<?php
// =================================================================================
// ARQUIVO AUXILIAR: Lógica de envio de Push Notifications para Pedidos
// Inclua este arquivo em process_payment.php, api/process_payment.php e notification.php
// =================================================================================

if (!function_exists('log_push_pedidos')) {
    function log_push_pedidos($message) {
        $logFile = __DIR__ . '/push_pedidos_debug.log';
        @file_put_contents($logFile, date('Y-m-d H:i:s') . " - " . $message . "\n", FILE_APPEND);
    }
}

if (!function_exists('trigger_push_pedidos_notifications')) {
    /**
     * Dispara notificações push para vendedores sobre pedidos
     * @param int $usuario_id ID do vendedor
     * @param array $event_data Dados do evento (mesmo formato do UTMfy)
     * @param string $trigger_event Tipo de evento ('pending', 'approved', 'pix_created', etc)
     * @param int|null $produto_id ID do produto (opcional)
     * @return void
     */
    function trigger_push_pedidos_notifications($usuario_id, $event_data, $trigger_event, $produto_id = null) {
        global $pdo;
        
        // Log inicial para debug
        log_push_pedidos("=== INÍCIO trigger_push_pedidos_notifications ===");
        log_push_pedidos("Usuario ID: {$usuario_id} | Evento: {$trigger_event} | Produto ID: " . ($produto_id ?? 'N/A'));
        log_push_pedidos("Event Data: " . json_encode($event_data));
        
        try {
            // 1. Verificar se helper de push está disponível
            $push_helper_paths = [
                __DIR__ . '/../pwa/api/web_push_helper.php',
                __DIR__ . '/pwa/api/web_push_helper.php',
                dirname(__DIR__) . '/pwa/api/web_push_helper.php'
            ];
            
            $push_helper_loaded = false;
            foreach ($push_helper_paths as $push_path) {
                if (file_exists($push_path)) {
                    try {
                        require_once $push_path;
                        $push_helper_loaded = true;
                        log_push_pedidos("Push helper carregado de: {$push_path}");
                        break;
                    } catch (Exception $e) {
                        log_push_pedidos("Erro ao carregar push helper de {$push_path}: " . $e->getMessage());
                    }
                } else {
                    log_push_pedidos("Push helper não encontrado em: {$push_path}");
                }
            }
            
            if (!$push_helper_loaded) {
                log_push_pedidos("ERRO: Push helper não foi carregado de nenhum caminho!");
                return;
            }
            
            if (!function_exists('pwa_send_push_to_vendor')) {
                log_push_pedidos("ERRO: Função pwa_send_push_to_vendor não encontrada após carregar helper!");
                return;
            }
            
            log_push_pedidos("Push helper carregado com sucesso e função pwa_send_push_to_vendor disponível");
            
            // 2. Normalizar trigger_event
            $trigger_event = strtolower(trim($trigger_event));
            log_push_pedidos("Trigger event normalizado: '{$trigger_event}'");
            
            // 3. Determinar se deve enviar push baseado no evento
            // Envia push apenas para: pix_created/pending (Pix gerado) e approved (pedido aprovado)
            $should_send = false;
            $is_pix_pending = false;
            $is_approved = false;
            
            if (in_array($trigger_event, ['pix_created', 'pending'])) {
                // Verifica se é Pix
                $metodo = strtolower($event_data['metodo_pagamento'] ?? '');
                log_push_pedidos("Método de pagamento detectado: '{$metodo}'");
                if (stripos($metodo, 'pix') !== false) {
                    $should_send = true;
                    $is_pix_pending = true;
                    log_push_pedidos("Evento é Pix pendente - push será enviado");
                } else {
                    log_push_pedidos("Evento é pending mas NÃO é Pix - push NÃO será enviado");
                }
            } elseif (in_array($trigger_event, ['approved', 'paid', 'completed', 'succeeded'])) {
                $should_send = true;
                $is_approved = true;
                log_push_pedidos("Evento é aprovado - push será enviado | trigger_event: '{$trigger_event}' | método_pagamento: " . ($event_data['metodo_pagamento'] ?? 'N/A'));
            } else {
                log_push_pedidos("Evento '{$trigger_event}' não está na lista de eventos que requerem push | Lista esperada: ['approved', 'paid', 'completed', 'succeeded', 'pix_created', 'pending']");
            }
            
            if (!$should_send) {
                log_push_pedidos("Push NÃO será enviado - Evento: '{$trigger_event}' não requer push notification");
                return;
            }
            
            // 4. Preparar título e mensagem
            $valor_total = (float)($event_data['valor_total_compra'] ?? 0);
            $valor_formatado = 'R$ ' . number_format($valor_total, 2, ',', '.');
            $nome_cliente = $event_data['comprador']['nome'] ?? 'Cliente';
            
            // Busca nome do app para incluir no título (evita "from [app]")
            $app_name = null;
            try {
                if (isset($pdo) && $pdo instanceof PDO) {
                    $stmt_app = $pdo->query("SELECT app_name, short_name FROM pwa_config ORDER BY id DESC LIMIT 1");
                    $app_config = $stmt_app->fetch(PDO::FETCH_ASSOC);
                    if ($app_config) {
                        $app_name = $app_config['app_name'] ?? $app_config['short_name'] ?? null;
                    }
                }
            } catch (Exception $e) {
                // Ignora erro
            }
            
            if ($is_pix_pending) {
                $title_base = "Pix Gerado - Aguardando Pagamento";
                $message = "Novo pedido Pix de {$valor_formatado} - {$nome_cliente}";
            } elseif ($is_approved) {
                $title_base = "Pedido Aprovado!";
                $message = "Venda aprovada de {$valor_formatado} - {$nome_cliente}";
            } else {
                // Não deveria chegar aqui, mas por segurança
                return;
            }
            
            // Inclui nome do app no título para evitar "from [app]"
            $title = $app_name ? "{$app_name} - {$title_base}" : $title_base;
            
            // 5. Preparar URL de destino
            $transacao_id = $event_data['transacao_id'] ?? null;
            $url = '/index?pagina=vendas';
            
            if ($transacao_id) {
                // Tenta buscar ID da venda no banco para criar link direto
                try {
                    if (isset($pdo) && $pdo instanceof PDO) {
                        $stmt = $pdo->prepare("SELECT id FROM vendas WHERE transacao_id = ? LIMIT 1");
                        $stmt->execute([$transacao_id]);
                        $venda = $stmt->fetch(PDO::FETCH_ASSOC);
                        if ($venda && isset($venda['id'])) {
                            $url = '/index?pagina=vendas&id=' . (int)$venda['id'];
                        }
                    }
                } catch (Exception $e) {
                    log_push_pedidos("Erro ao buscar ID da venda: " . $e->getMessage());
                    // Usa URL padrão se falhar
                }
            }
            
            // 6. Enviar push notification
            log_push_pedidos("Preparando para enviar push - Vendedor ID: {$usuario_id} | Evento: {$trigger_event} | Título: {$title} | Mensagem: {$message} | URL: {$url}");
            log_push_pedidos("Dados do evento - transacao_id: " . ($event_data['transacao_id'] ?? 'N/A') . " | valor_total: " . ($event_data['valor_total_compra'] ?? 'N/A') . " | comprador: " . ($event_data['comprador']['nome'] ?? 'N/A'));
            
            $result = pwa_send_push_to_vendor($usuario_id, $title, $message, $url);
            
            log_push_pedidos("Resultado do envio: " . json_encode($result));
            if (isset($result['error'])) {
                log_push_pedidos("ERRO no envio de push: " . $result['error']);
            }
            
            if (isset($result['sent']) && $result['sent'] > 0) {
                log_push_pedidos("✓ Push enviado com sucesso! Enviadas: {$result['sent']}, Falhadas: {$result['failed']}, Total: {$result['total']}");
            } else {
                $error_msg = $result['error'] ?? 'N/A';
                $total_subs = $result['total'] ?? 0;
                log_push_pedidos("✗ Push não foi enviado. Total de subscriptions: {$total_subs} | Erro: {$error_msg}");
                
                if ($total_subs == 0) {
                    log_push_pedidos("AVISO: Vendedor ID {$usuario_id} não possui subscriptions de push registradas!");
                }
            }
            
            log_push_pedidos("=== FIM trigger_push_pedidos_notifications ===");
            
        } catch (Exception $e) {
            log_push_pedidos("ERRO GERAL ao enviar push: " . $e->getMessage());
            log_push_pedidos("Stack trace: " . $e->getTraceAsString());
            log_push_pedidos("=== FIM trigger_push_pedidos_notifications (COM ERRO) ===");
            // Não lança exceção para não quebrar o fluxo
        } catch (Error $e) {
            log_push_pedidos("ERRO FATAL ao enviar push: " . $e->getMessage());
            log_push_pedidos("=== FIM trigger_push_pedidos_notifications (COM ERRO FATAL) ===");
            // Não lança exceção para não quebrar o fluxo
        }
    }
}
?>

