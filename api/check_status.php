<?php
// Registra handler de erro fatal (mensagens genéricas para usuários)
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== NULL && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        // Log detalhado para administradores (não expor ao usuário)
        require_once __DIR__ . '/../helpers/security_helper.php';
        $log_file = __DIR__ . '/../check_status_log.txt';
        secure_log($log_file, "Erro fatal: {$error['message']} em {$error['file']}:{$error['line']}");
        
        ob_clean();
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'error', 
            'message' => 'Erro interno do servidor. Tente novamente mais tarde.'
        ]);
        exit;
    }
});

// Inicia buffer de saída
ob_start();

// Aplicar headers de segurança antes de qualquer output
require_once __DIR__ . '/../config/security_headers.php';
if (function_exists('apply_security_headers')) {
    apply_security_headers(false); // CSP permissivo para APIs
}

// Desabilita exibição de erros
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Define header JSON imediatamente
header('Content-Type: application/json');

// Função para retornar erro JSON
function returnJsonError($message, $code = 500) {
    ob_clean();
    http_response_code($code);
    echo json_encode(['status' => 'error', 'message' => $message]);
    exit;
}

// Função para retornar sucesso JSON
function returnJsonSuccess($data) {
    ob_clean();
    http_response_code(200);
    echo json_encode($data);
    exit;
}

// Função para log de check_status (sanitizada)
function log_check_status($message) {
    require_once __DIR__ . '/../helpers/security_helper.php';
    $log_file = __DIR__ . '/../check_status_log.txt';
    secure_log($log_file, $message);
}

// Importa Helper da UTMfy se disponível
if (file_exists(__DIR__ . '/../helpers/utmfy_helper.php')) {
    require_once __DIR__ . '/../helpers/utmfy_helper.php';
}

// Importa Helper de Push Notifications se disponível
if (file_exists(__DIR__ . '/../helpers/push_pedidos_helper.php')) {
    require_once __DIR__ . '/../helpers/push_pedidos_helper.php';
}

// Importa Helper de Webhooks se disponível
if (file_exists(__DIR__ . '/../helpers/webhook_helper.php')) {
    require_once __DIR__ . '/../helpers/webhook_helper.php';
}

// Importa Helper de Disparo Centralizado de Eventos
if (file_exists(__DIR__ . '/../helpers/payment_events_dispatcher.php')) {
    require_once __DIR__ . '/../helpers/payment_events_dispatcher.php';
}

// NÃO incluir notification.php diretamente pois ele executa código no nível superior
// Copiar apenas as funções necessárias aqui

if (!function_exists('sendFacebookConversionEvent')) {
    function sendFacebookConversionEvent($pixel_id, $api_token, $event_name, $sale_details, $event_source_url) {
        if (empty($pixel_id) || empty($api_token)) {
            log_check_status("TRACKING FB: Pixel ID ou API Token vazio. Pixel: " . ($pixel_id ?: 'vazio') . " | Token: " . ($api_token ? 'configurado' : 'vazio'));
            return;
        }
        
        $url = "https://graph.facebook.com/v19.0/" . $pixel_id . "/events?access_token=" . $api_token;
        
        $user_data = [
            'em' => [hash('sha256', strtolower($sale_details['comprador_email'] ?? ''))],
            'ph' => [hash('sha256', preg_replace('/[^0-9]/', '', $sale_details['comprador_telefone'] ?? ''))],
        ];
        $name_parts = explode(' ', $sale_details['comprador_nome'] ?? '', 2);
        $user_data['fn'] = [hash('sha256', strtolower($name_parts[0]))];
        if (isset($name_parts[1])) $user_data['ln'] = [hash('sha256', strtolower($name_parts[1]))];

        // Priorizar valor_total_compra se disponível (inclui order bumps), senão usar valor
        $valor_evento = isset($sale_details['valor_total_compra']) ? (float)$sale_details['valor_total_compra'] : (float)($sale_details['valor'] ?? 0);
        
        $payload = [
            'data' => [[
                'event_name' => $event_name,
                'event_time' => time(),
                'event_source_url' => $event_source_url,
                'user_data' => $user_data,
                'custom_data' => ['currency' => 'BRL', 'value' => $valor_evento],
                'action_source' => 'website',
            ]]
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code >= 200 && $http_code < 300) {
            log_check_status("TRACKING FB: Sucesso! Evento '$event_name' enviado para Pixel ID $pixel_id. HTTP $http_code");
        } else {
            log_check_status("TRACKING FB: ERRO! Falha ao enviar evento '$event_name' para Pixel ID $pixel_id. HTTP $http_code. Resposta: " . substr($response, 0, 200));
        }
    }
}

if (!function_exists('handle_tracking_events')) {
    function handle_tracking_events($status, $sale_details, $checkout_config) {
        $tracking_config = $checkout_config['tracking'] ?? [];
        if (empty($tracking_config)) {
            log_check_status("TRACKING: Configuração de rastreamento vazia para o produto.");
            return;
        }
        
        $status = strtolower($status);

        $event_map = [
            'approved' => ['key' => 'purchase', 'fb_name' => 'Purchase'],
            'paid' => ['key' => 'purchase', 'fb_name' => 'Purchase'],
            'completed' => ['key' => 'purchase', 'fb_name' => 'Purchase'],
            'pix_created' => ['key' => 'pending', 'fb_name' => 'PaymentPending'], 
            'pending' => ['key' => 'pending', 'fb_name' => 'PaymentPending'],
            'rejected' => ['key' => 'rejected', 'fb_name' => 'PaymentRejected'],
            'refunded' => ['key' => 'refund', 'fb_name' => 'Refund'],
            'charged_back' => ['key' => 'chargeback', 'fb_name' => 'Chargeback']
        ];
        
        if (!isset($event_map[$status])) {
            if (function_exists('log_check_status')) {
                log_check_status("TRACKING: Status '$status' não mapeado para evento de rastreamento.");
            }
            return;
        }
        
        $event_info = $event_map[$status];
        $event_key = $event_info['key'];
        $fb_event_name = $event_info['fb_name'];
        
        // Verifica se o evento está habilitado no Facebook
        $fb_events_enabled = $tracking_config['events']['facebook'] ?? [];
        if (empty($fb_events_enabled[$event_key])) {
            if (function_exists('log_check_status')) {
                log_check_status("TRACKING FB: Evento '$event_key' não está habilitado para Facebook. Eventos habilitados: " . json_encode($fb_events_enabled));
            }
            return;
        }
        
        $pixel_id = $tracking_config['facebookPixelId'] ?? '';
        $api_token = $tracking_config['facebookApiToken'] ?? '';
        
        if (empty($pixel_id)) {
            if (function_exists('log_check_status')) {
                log_check_status("TRACKING FB: Facebook Pixel ID não configurado.");
            }
            return;
        }
        
        if (empty($api_token)) {
            if (function_exists('log_check_status')) {
                log_check_status("TRACKING FB: Facebook API Token não configurado. Evento '$fb_event_name' não será enviado.");
            }
            return;
        }
        
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
        $checkout_url = $protocol . $_SERVER['HTTP_HOST'] . '/checkout?p=' . ($sale_details['checkout_hash'] ?? '');
        
        if (function_exists('log_check_status')) {
            log_check_status("TRACKING FB: Disparando evento '$fb_event_name' para Pixel ID: $pixel_id | Status: $status | Event Key: $event_key");
        }
        sendFacebookConversionEvent($pixel_id, $api_token, $fb_event_name, $sale_details, $checkout_url);
    }
}

/**
 * Função helper para disparar webhooks, UTMfy e Meta Ads diretamente quando status é 'approved'
 * Reutilizável por todos os gateways no check_status.php
 */
function process_approved_payment_dispatches($pdo, $payment_id, $gateway_name = '') {
    try {
        log_check_status("$gateway_name: Iniciando process_approved_payment_dispatches para payment_id: $payment_id");
        
        // Usar função centralizada para disparar eventos
        if (function_exists('dispatch_payment_events')) {
            log_check_status("$gateway_name: Usando função centralizada dispatch_payment_events");
            $result = dispatch_payment_events($pdo, $payment_id, 'approved', $gateway_name);
            if ($result) {
                log_check_status("$gateway_name: Função centralizada executada com sucesso");
            } else {
                log_check_status("$gateway_name: Função centralizada retornou false");
            }
        } else {
            log_check_status("$gateway_name: ERRO - Função dispatch_payment_events não encontrada!");
            return false;
        }
        
        // Disparar Meta Ads (mantém lógica específica, não está na função centralizada)
        try {
            $stmt_venda = $pdo->prepare("
                SELECT v.*, p.nome as produto_nome, p.checkout_config, p.checkout_hash 
                FROM vendas v 
                JOIN produtos p ON v.produto_id = p.id 
                WHERE v.transacao_id = ? 
                LIMIT 1
            ");
            $stmt_venda->execute([$payment_id]);
            $main_sale = $stmt_venda->fetch(PDO::FETCH_ASSOC);
            
            if ($main_sale) {
                // Buscar todas as vendas para calcular valor total
                $checkout_session_uuid = $main_sale['checkout_session_uuid'] ?? null;
                if (!empty($checkout_session_uuid)) {
                    $stmt_all = $pdo->prepare("SELECT valor FROM vendas WHERE checkout_session_uuid = ?");
                    $stmt_all->execute([$checkout_session_uuid]);
                } else {
                    $stmt_all = $pdo->prepare("SELECT valor FROM vendas WHERE transacao_id = ?");
                    $stmt_all->execute([$payment_id]);
                }
                $all_sales = $stmt_all->fetchAll(PDO::FETCH_ASSOC);
                $valor_total_compra = array_sum(array_column($all_sales, 'valor'));
                
                $produto_config = json_decode($main_sale['checkout_config'] ?? '{}', true);
                if (function_exists('handle_tracking_events')) {
                    log_check_status("$gateway_name: Disparando Meta Ads para evento 'approved' | Valor total: R$ " . number_format($valor_total_compra, 2, ',', '.'));
                    $sale_details = [
                        'transacao_id' => $payment_id,
                        'valor' => $valor_total_compra,
                        'valor_total_compra' => $valor_total_compra,
                        'comprador_nome' => $main_sale['comprador_nome'] ?? '',
                        'comprador_email' => $main_sale['comprador_email'] ?? '',
                        'comprador_telefone' => $main_sale['comprador_telefone'] ?? '',
                        'comprador_cpf' => $main_sale['comprador_cpf'] ?? '',
                        'checkout_hash' => $main_sale['checkout_hash'] ?? ''
                    ];
                    try {
                        handle_tracking_events('approved', $sale_details, $produto_config);
                        log_check_status("$gateway_name: Meta Ads disparado com sucesso");
                    } catch (Exception $e) {
                        log_check_status("$gateway_name: Erro ao disparar Meta Ads: " . $e->getMessage());
                    }
                }
            }
        } catch (Exception $e) {
            log_check_status("$gateway_name: Erro ao buscar dados para Meta Ads: " . $e->getMessage());
        }
        
        return true;
    } catch (Exception $e) {
        log_check_status("$gateway_name: Erro ao processar disparos: " . $e->getMessage());
        return false;
    }
}

// Tenta carregar config
$config_paths = [
    __DIR__ . '/../config/config.php',
    __DIR__ . '/../config.php'
];

$config_loaded = false;
$config_error = null;
foreach ($config_paths as $config_path) {
    if (file_exists($config_path)) {
        try {
            ob_start();
            // Captura qualquer output do config
            $old_error_handler = set_error_handler(function($errno, $errstr, $errfile, $errline) use (&$config_error) {
                $config_error = $errstr;
                return true; // Suprime o erro
            });
            
            require $config_path;
            
            // Restaura o error handler
            if ($old_error_handler !== null) {
                set_error_handler($old_error_handler);
            } else {
                restore_error_handler();
            }
            
            ob_end_clean();
            
            // Verifica se $pdo foi criado
            if (isset($pdo) && $pdo instanceof PDO) {
                $config_loaded = true;
                break;
            } else {
                $config_error = 'Variável $pdo não foi definida no arquivo de configuração.';
            }
        } catch (Exception $e) {
            ob_end_clean();
            $config_error = 'Erro ao carregar configuração: ' . $e->getMessage();
        } catch (Error $e) {
            ob_end_clean();
            $config_error = 'Erro fatal ao carregar configuração: ' . $e->getMessage();
        } catch (Throwable $e) {
            ob_end_clean();
            $config_error = 'Erro ao carregar configuração: ' . $e->getMessage();
        }
    }
}

if (!$config_loaded) {
    $error_msg = $config_error ?: 'Arquivo de configuração não encontrado ou inválido.';
    returnJsonError($error_msg, 500);
}

// Verifica se $pdo foi definido
if (!isset($pdo) || !$pdo) {
    returnJsonError('Conexão com banco de dados não configurada.', 500);
}

// Limpa o buffer inicial
ob_end_clean();

// Proteção: Rate limiting robusto por IP usando banco de dados
require_once __DIR__ . '/../helpers/security_helper.php';
$client_ip = get_client_ip();
$rate_limit_key = 'check_status';

// Verifica rate limiting (máximo 60 requisições por minuto por IP)
$rate_check = check_rate_limit_db($rate_limit_key, 60, 60, $client_ip);

if (!$rate_check['allowed']) {
    log_security_event('rate_limit_exceeded', [
        'ip' => $client_ip,
        'endpoint' => 'check_status',
        'reset_at' => $rate_check['reset_at'] ?? null
    ]);
    returnJsonError('Muitas requisições. Tente novamente em alguns instantes.', 429);
}

// Recebe ID, ID do Vendedor e o Gateway usado
$payment_id = $_GET['id'] ?? null;
$seller_id = $_GET['seller_id'] ?? null;
$gateway = $_GET['gateway'] ?? 'mercadopago'; // Padrão para retrocompatibilidade

// Validação e sanitização rigorosa de inputs
if (!$payment_id || !$seller_id) {
    returnJsonError('Dados insuficientes.', 400);
}

// Whitelist de caracteres permitidos para payment_id (alphanumeric, underscore, hyphen)
if (!preg_match('/^[a-zA-Z0-9_-]+$/', $payment_id)) {
    log_security_event('invalid_payment_id_format', [
        'ip' => $client_ip,
        'payment_id_length' => strlen($payment_id),
        'payment_id_preview' => substr($payment_id, 0, 20)
    ]);
    returnJsonError('ID do pagamento inválido.', 400);
}

// Limitar tamanho do payment_id (prevenção de DoS)
if (strlen($payment_id) > 255) {
    returnJsonError('ID do pagamento muito longo.', 400);
}

$seller_id = filter_var($seller_id, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1, 'max_range' => PHP_INT_MAX]
]);

if ($seller_id === false) {
    returnJsonError('ID do vendedor inválido.', 400);
}

// Whitelist de gateways permitidos
$allowed_gateways = ['mercadopago', 'pushinpay', 'efi', 'efi_card', 'beehive', 'hypercash', 'asaas', 'applyfy', 'spacepag'];
$gateway = strtolower(trim($gateway));
if (!in_array($gateway, $allowed_gateways)) {
    $gateway = 'mercadopago'; // Fallback seguro
}

// Validação rigorosa de ownership (verificação dupla)
try {
    // Primeira verificação: venda existe e pertence ao vendedor
    $stmt_validate = $pdo->prepare("
        SELECT v.id, v.transacao_id, v.status_pagamento, p.usuario_id, p.nome as produto_nome
        FROM vendas v 
        JOIN produtos p ON v.produto_id = p.id 
        WHERE v.transacao_id = ? AND p.usuario_id = ?
        LIMIT 1
    ");
    $stmt_validate->execute([$payment_id, $seller_id]);
    $validation = $stmt_validate->fetch(PDO::FETCH_ASSOC);
    
    if (!$validation) {
        // Segunda verificação: verificar se a venda existe mas pertence a outro vendedor
        $stmt_check_other = $pdo->prepare("
            SELECT p.usuario_id 
            FROM vendas v 
            JOIN produtos p ON v.produto_id = p.id 
            WHERE v.transacao_id = ?
            LIMIT 1
        ");
        $stmt_check_other->execute([$payment_id]);
        $other_owner = $stmt_check_other->fetch(PDO::FETCH_ASSOC);
        
        log_security_event('unauthorized_check_status_access', [
            'ip' => $client_ip,
            'payment_id_preview' => substr($payment_id, 0, 20),
            'seller_id' => $seller_id,
            'actual_owner_id' => $other_owner['usuario_id'] ?? null,
            'attempted_gateway' => $gateway,
            'is_other_owner' => ($other_owner && $other_owner['usuario_id'] != $seller_id)
        ]);
        
        // Mensagem genérica para não expor informações
        returnJsonError('Acesso negado.', 403);
    }
    
    // Verificação adicional: garantir que seller_id corresponde ao usuário logado (se aplicável)
    // Isso previne que um usuário use ID de outro vendedor
    if (isset($_SESSION['id']) && $_SESSION['id'] != $seller_id && $_SESSION['tipo'] !== 'admin') {
        log_security_event('seller_id_mismatch', [
            'ip' => $client_ip,
            'session_user_id' => $_SESSION['id'],
            'requested_seller_id' => $seller_id,
            'payment_id_preview' => substr($payment_id, 0, 20)
        ]);
        returnJsonError('Acesso negado.', 403);
    }
    
} catch (PDOException $e) {
    log_security_event('check_status_validation_error', [
        'ip' => $client_ip,
        'error' => sanitize_log_message($e->getMessage())
    ]);
    returnJsonError('Erro ao processar requisição.', 500);
}

try {
    // Busca tokens do vendedor
    $stmt = $pdo->prepare("SELECT mp_access_token, pushinpay_token, efi_client_id, efi_client_secret, efi_certificate_path, efi_pix_key, efi_payee_code, beehive_secret_key, hypercash_secret_key, asaas_api_key, asaas_environment, applyfy_public_key, applyfy_secret_key, spacepag_public_key, spacepag_secret_key FROM usuarios WHERE id = ?");
    $stmt->execute([$seller_id]);
    $tokens = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$tokens) {
        returnJsonError('Vendedor não encontrado.', 404);
    }

    if ($gateway === 'beehive') {
        // --- Lógica Beehive ---
        require_once __DIR__ . '/../gateways/beehive.php';
        
        $secret_key = $tokens['beehive_secret_key'] ?? '';
        if (!$secret_key) {
            returnJsonError('Credenciais Beehive não configuradas.', 400);
        }
        
        log_check_status("Beehive: Consultando status do pagamento - ID: " . substr($payment_id, 0, 20) . '...');
        
        $status_data = beehive_get_payment_status($secret_key, $payment_id);
        
        if ($status_data === false) {
            log_check_status("Beehive: ERRO - beehive_get_payment_status retornou false.");
            returnJsonSuccess(['status' => 'pending', 'message' => 'Status não disponível']);
        }
        
        if ($status_data && isset($status_data['status'])) {
            $status = $status_data['status'];
            log_check_status("Beehive: Status obtido da API: " . $status);
            
            // Se o status for aprovado, atualiza no banco e dispara webhook interno
            if ($status === 'approved') {
                try {
                    // Verifica se o status no banco já está aprovado
                    $stmt_check = $pdo->prepare("SELECT status_pagamento FROM vendas WHERE transacao_id = ? LIMIT 1");
                    $stmt_check->execute([$payment_id]);
                    $venda_check = $stmt_check->fetch(PDO::FETCH_ASSOC);
                    
                    if ($venda_check) {
                        // Só atualizar e disparar eventos quando status REALMENTE muda para approved
                        // Evita duplicação quando webhook e polling detectam ao mesmo tempo
                        if ($venda_check['status_pagamento'] !== 'approved') {
                            $pdo->prepare("UPDATE vendas SET status_pagamento = 'approved' WHERE transacao_id = ?")->execute([$payment_id]);
                            log_check_status("Beehive: Status atualizado de '" . $venda_check['status_pagamento'] . "' para 'approved'");
                            process_approved_payment_dispatches($pdo, $payment_id, 'Beehive');
                        } else {
                            log_check_status("Beehive: Status já estava 'approved' - eventos não disparados (evita duplicação)");
                        }
                    }
                } catch (Exception $e) {
                    log_check_status("Erro ao atualizar status via polling (Beehive): " . $e->getMessage());
                }
            }
            
            log_check_status("Beehive: Retornando status final: " . $status);
            returnJsonSuccess(['status' => $status]);
        } else {
            log_check_status("Beehive: Não foi possível obter status do pagamento - retornando pending");
            returnJsonSuccess(['status' => 'pending', 'message' => 'Status não disponível']);
        }
    }
    elseif ($gateway === 'hypercash') {
        // --- Lógica Hypercash ---
        require_once __DIR__ . '/../gateways/hypercash.php';
        
        $secret_key = $tokens['hypercash_secret_key'] ?? '';
        if (!$secret_key) {
            returnJsonError('Credenciais Hypercash não configuradas.', 400);
        }
        
        log_check_status("Hypercash: Consultando status do pagamento - ID: " . substr($payment_id, 0, 20) . '...');
        
        $status_data = hypercash_get_payment_status($secret_key, $payment_id);
        
        if ($status_data === false) {
            log_check_status("Hypercash: ERRO - hypercash_get_payment_status retornou false.");
            returnJsonSuccess(['status' => 'pending', 'message' => 'Status não disponível']);
        }
        
        if ($status_data && isset($status_data['status'])) {
            $status = $status_data['status'];
            log_check_status("Hypercash: Status obtido da API: " . $status);
            
            // Se o status for aprovado, atualiza no banco e dispara webhook interno
            if ($status === 'approved') {
                try {
                    // Verifica se o status no banco já está aprovado
                    $stmt_check = $pdo->prepare("SELECT status_pagamento FROM vendas WHERE transacao_id = ? LIMIT 1");
                    $stmt_check->execute([$payment_id]);
                    $venda_check = $stmt_check->fetch(PDO::FETCH_ASSOC);
                    
                    if ($venda_check) {
                        // Só atualizar e disparar eventos quando status REALMENTE muda para approved
                        if ($venda_check['status_pagamento'] !== 'approved') {
                            $pdo->prepare("UPDATE vendas SET status_pagamento = 'approved' WHERE transacao_id = ?")->execute([$payment_id]);
                            log_check_status("Hypercash: Status atualizado de '" . $venda_check['status_pagamento'] . "' para 'approved'");
                            process_approved_payment_dispatches($pdo, $payment_id, 'Hypercash');
                            
                            // Disparar webhook interno para processar entrega (email, área de membros, PDF)
                            $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
                            $host = $_SERVER['HTTP_HOST'];
                            $script_path = dirname(dirname($_SERVER['PHP_SELF']));
                            $webhook_url = $protocol . '://' . $host . rtrim($script_path, '/') . '/notification.php';
                            $webhook_payload = ['id' => $payment_id, 'status' => 'paid', 'event' => 'payment.paid', 'force_process' => true];
                            $ch_webhook = curl_init($webhook_url);
                            curl_setopt($ch_webhook, CURLOPT_POST, true);
                            curl_setopt($ch_webhook, CURLOPT_POSTFIELDS, json_encode($webhook_payload));
                            curl_setopt($ch_webhook, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                            curl_setopt($ch_webhook, CURLOPT_RETURNTRANSFER, false);
                            curl_setopt($ch_webhook, CURLOPT_TIMEOUT, 2);
                            curl_setopt($ch_webhook, CURLOPT_CONNECTTIMEOUT, 1);
                            curl_setopt($ch_webhook, CURLOPT_NOSIGNAL, 1);
                            @curl_exec($ch_webhook);
                            curl_close($ch_webhook);
                        } else {
                            log_check_status("Hypercash: Status já estava 'approved' - eventos não disparados (evita duplicação)");
                        }
                    }
                } catch (Exception $e) {
                    log_check_status("Hypercash: ERRO ao processar webhook interno: " . $e->getMessage());
                }
            }
            
            log_check_status("Hypercash: Retornando status final: " . $status);
            returnJsonSuccess(['status' => $status]);
        } else {
            log_check_status("Hypercash: Não foi possível obter status do pagamento - retornando pending");
            returnJsonSuccess(['status' => 'pending', 'message' => 'Status não disponível']);
        }
    }
    elseif ($gateway === 'efi') {
        // --- Lógica Efí ---
        require_once __DIR__ . '/../gateways/efi.php';
        
        $client_id = $tokens['efi_client_id'] ?? '';
        $client_secret = $tokens['efi_client_secret'] ?? '';
        $certificate_path = $tokens['efi_certificate_path'] ?? '';
        
        if (empty($client_id) || empty($client_secret) || empty($certificate_path)) {
            returnJsonError('Credenciais Efí não configuradas para este vendedor.', 400);
        }
        
        $full_cert_path = dirname(__DIR__) . '/' . $certificate_path;
        $full_cert_path = str_replace('\\', '/', $full_cert_path);
        if (!file_exists($full_cert_path)) {
            returnJsonError('Certificado Efí não encontrado.', 400);
        }
        
        // Obter access token
        $token_data = efi_get_access_token($client_id, $client_secret, $full_cert_path);
        if (!$token_data) {
            returnJsonError('Erro ao obter token de acesso Efí.', 500);
        }
        
        // Consultar status do pagamento (passar certificado para mutual TLS)
        log_check_status("Efí: Consultando status do pagamento - txid: " . substr($payment_id, 0, 20) . '...');
        log_check_status("Efí: Client ID presente: " . (!empty($client_id) ? 'sim (tamanho: ' . strlen($client_id) . ')' : 'não'));
        log_check_status("Efí: Client Secret presente: " . (!empty($client_secret) ? 'sim (tamanho: ' . strlen($client_secret) . ')' : 'não'));
        log_check_status("Efí: Certificado path: " . $certificate_path);
        log_check_status("Efí: Certificado path completo: " . $full_cert_path);
        log_check_status("Efí: Certificado existe: " . (file_exists($full_cert_path) ? 'sim' : 'não'));
        log_check_status("Efí: Access token obtido: " . (!empty($token_data['access_token']) ? 'sim (tamanho: ' . strlen($token_data['access_token']) . ')' : 'não'));
        
        $status_data = efi_get_payment_status($token_data['access_token'], $payment_id, $full_cert_path);
        
        log_check_status("Efí: Resposta da função efi_get_payment_status: " . json_encode($status_data));
        
        if ($status_data === false) {
            log_check_status("Efí: ERRO - efi_get_payment_status retornou false. Verifique os logs de error_log para detalhes.");
            log_check_status("Efí: Isso pode indicar erro de conexão, endpoint incorreto, ou credenciais inválidas.");
        }
        
        if ($status_data && isset($status_data['status'])) {
            $status = ($status_data['status'] === 'approved' || $status_data['status'] === 'paid') ? 'approved' : $status_data['status'];
            log_check_status("Efí: Status obtido da API: " . $status . " (status_data completo: " . json_encode($status_data) . ")");
            
            // Se o status for aprovado, atualiza no banco e dispara webhook interno
            if ($status === 'approved') {
                try {
                    // Verifica se o status no banco já está aprovado
                    $stmt_check = $pdo->prepare("SELECT status_pagamento FROM vendas WHERE transacao_id = ? LIMIT 1");
                    $stmt_check->execute([$payment_id]);
                    $venda_check = $stmt_check->fetch(PDO::FETCH_ASSOC);
                    
                    if ($venda_check) {
                        if ($venda_check['status_pagamento'] !== 'approved') {
                            $pdo->prepare("UPDATE vendas SET status_pagamento = 'approved' WHERE transacao_id = ?")->execute([$payment_id]);
                            log_check_status("Efí: Status atualizado de '" . $venda_check['status_pagamento'] . "' para 'approved'");
                            process_approved_payment_dispatches($pdo, $payment_id, 'Efí Pix');
                        } else {
                            log_check_status("Efí: Status já estava 'approved' - eventos não disparados (evita duplicação)");
                        }
                    }
                } catch (Exception $e) {
                    log_check_status("Erro ao atualizar status via polling (Efí): " . $e->getMessage());
                }
            }
            
            log_check_status("Efí: Retornando status final: " . $status);
            returnJsonSuccess(['status' => $status]);
        } else {
            // Se não conseguiu obter status, retorna pending para continuar verificando
            log_check_status("Efí: Não foi possível obter status do pagamento - status_data: " . json_encode($status_data) . " - retornando pending");
            returnJsonSuccess(['status' => 'pending', 'message' => 'Status não disponível']);
        }
    }
    elseif ($gateway === 'efi_card') {
        // --- Lógica Efí Cartão ---
        require_once __DIR__ . '/../gateways/efi.php';
        
        $client_id = $tokens['efi_client_id'] ?? '';
        $client_secret = $tokens['efi_client_secret'] ?? '';
        $certificate_path = $tokens['efi_certificate_path'] ?? '';
        
        if (empty($client_id) || empty($client_secret) || empty($certificate_path)) {
            returnJsonError('Credenciais Efí não configuradas para este vendedor.', 400);
        }
        
        $full_cert_path = dirname(__DIR__) . '/' . $certificate_path;
        $full_cert_path = str_replace('\\', '/', $full_cert_path);
        if (!file_exists($full_cert_path)) {
            returnJsonError('Certificado Efí não encontrado.', 400);
        }
        
        // Obter access token da API de Cobranças (endpoint diferente)
        log_check_status("Efí Cartão: Obtendo access token da API de Cobranças...");
        log_check_status("Efí Cartão: Client ID presente: " . (!empty($client_id) ? 'sim (tamanho: ' . strlen($client_id) . ')' : 'não'));
        log_check_status("Efí Cartão: Client Secret presente: " . (!empty($client_secret) ? 'sim (tamanho: ' . strlen($client_secret) . ')' : 'não'));
        log_check_status("Efí Cartão: Certificado path: " . $certificate_path);
        log_check_status("Efí Cartão: Certificado path completo: " . $full_cert_path);
        log_check_status("Efí Cartão: Certificado existe: " . (file_exists($full_cert_path) ? 'sim' : 'não'));
        
        $token_data = efi_get_charges_access_token($client_id, $client_secret, $full_cert_path);
        if (!$token_data) {
            log_check_status("Efí Cartão: ERRO ao obter access token da API de Cobranças");
            returnJsonError('Erro ao obter token de acesso Efí (API de Cobranças).', 500);
        }
        
        log_check_status("Efí Cartão: Access token obtido com sucesso (primeiros 20 chars): " . substr($token_data['access_token'], 0, 20) . '...');
        
        // Consultar status da cobrança de cartão
        log_check_status("Efí Cartão: Consultando status da cobrança - charge_id: " . substr($payment_id, 0, 20) . '...');
        log_check_status("Efí Cartão: Certificado será usado: " . ($full_cert_path ? 'sim (' . $full_cert_path . ')' : 'não'));
        $status_data = efi_get_card_charge_status($token_data['access_token'], $payment_id, $full_cert_path);
        
        log_check_status("Efí Cartão: Resposta da função efi_get_card_charge_status: " . json_encode($status_data));
        
        if ($status_data === false) {
            log_check_status("Efí Cartão: ERRO - efi_get_card_charge_status retornou false.");
            log_check_status("Efí Cartão: Verificando status no banco de dados como fallback...");
            
            // Fallback: verificar status no banco de dados
            try {
                $stmt_fallback = $pdo->prepare("SELECT status_pagamento FROM vendas WHERE transacao_id = ? LIMIT 1");
                $stmt_fallback->execute([$payment_id]);
                $venda_fallback = $stmt_fallback->fetch(PDO::FETCH_ASSOC);
                
                if ($venda_fallback) {
                    $status_fallback = $venda_fallback['status_pagamento'];
                    log_check_status("Efí Cartão: Status no banco (fallback): " . $status_fallback);
                    
                    // Se o status no banco é rejected, retornar rejected
                    if ($status_fallback === 'rejected') {
                        returnJsonSuccess(['status' => 'rejected', 'message' => 'Status obtido do banco de dados', 'reason' => 'Pagamento recusado']);
                    } else {
                        returnJsonSuccess(['status' => $status_fallback ?: 'pending', 'message' => 'Status obtido do banco de dados']);
                    }
                } else {
                    returnJsonSuccess(['status' => 'pending', 'message' => 'Status não disponível']);
                }
            } catch (Exception $e) {
                log_check_status("Efí Cartão: Erro ao buscar status no banco (fallback): " . $e->getMessage());
                returnJsonSuccess(['status' => 'pending', 'message' => 'Status não disponível']);
            }
            return; // Não continuar se retornou false
        }
        
        // Se status_data tem erro mas não é false, ainda tentar processar
        if (isset($status_data['error']) && $status_data['error']) {
            log_check_status("Efí Cartão: API retornou erro mas continuando processamento - status_data: " . json_encode($status_data));
            
            // Se tem erro mas tem status, usar o status
            if (isset($status_data['status'])) {
                $status = $status_data['status'];
            } else {
                // Se tem erro e não tem status, usar fallback do banco
                try {
                    $stmt_fallback = $pdo->prepare("SELECT status_pagamento FROM vendas WHERE transacao_id = ? LIMIT 1");
                    $stmt_fallback->execute([$payment_id]);
                    $venda_fallback = $stmt_fallback->fetch(PDO::FETCH_ASSOC);
                    
                    if ($venda_fallback && $venda_fallback['status_pagamento'] === 'rejected') {
                        log_check_status("Efí Cartão: Status no banco (fallback após erro): rejected");
                        returnJsonSuccess(['status' => 'rejected', 'message' => 'Status obtido do banco de dados', 'reason' => 'Pagamento recusado']);
                    }
                } catch (Exception $e) {
                    log_check_status("Efí Cartão: Erro ao buscar status no banco (fallback após erro): " . $e->getMessage());
                }
                
                returnJsonSuccess(['status' => 'pending', 'message' => 'Erro ao consultar API, status não disponível']);
            }
        }
        
        if ($status_data && isset($status_data['status'])) {
            $status = $status_data['status'];
            log_check_status("Efí Cartão: Status obtido da API: " . $status);
            
            // Se o status for aprovado, atualiza no banco e dispara webhook interno
            if ($status === 'approved') {
                try {
                    // Verifica se o status no banco já está aprovado
                    $stmt_check = $pdo->prepare("SELECT status_pagamento FROM vendas WHERE transacao_id = ? LIMIT 1");
                    $stmt_check->execute([$payment_id]);
                    $venda_check = $stmt_check->fetch(PDO::FETCH_ASSOC);
                    
                    if ($venda_check) {
                        if ($venda_check['status_pagamento'] !== 'approved') {
                            $pdo->prepare("UPDATE vendas SET status_pagamento = 'approved' WHERE transacao_id = ?")->execute([$payment_id]);
                            log_check_status("Efí Cartão: Status atualizado de '" . $venda_check['status_pagamento'] . "' para 'approved'");
                            process_approved_payment_dispatches($pdo, $payment_id, 'Efí Cartão');
                        } else {
                            log_check_status("Efí Cartão: Status já estava 'approved' - eventos não disparados (evita duplicação)");
                        }
                    }
                } catch (Exception $e) {
                    log_check_status("Erro ao atualizar status via polling (Efí Cartão): " . $e->getMessage());
                }
            }
            // Se o status for rejeitado, atualiza no banco e dispara eventos
            elseif ($status === 'rejected') {
                try {
                    // Verifica se o status no banco já está rejeitado
                    $stmt_check = $pdo->prepare("SELECT status_pagamento FROM vendas WHERE transacao_id = ? LIMIT 1");
                    $stmt_check->execute([$payment_id]);
                    $venda_check = $stmt_check->fetch(PDO::FETCH_ASSOC);
                    
                    if ($venda_check) {
                        // Atualiza status no banco apenas se ainda não estiver rejeitado
                        if ($venda_check['status_pagamento'] !== 'rejected') {
                            $pdo->prepare("UPDATE vendas SET status_pagamento = 'rejected' WHERE transacao_id = ?")->execute([$payment_id]);
                            log_check_status("Efí Cartão: Status atualizado de '" . $venda_check['status_pagamento'] . "' para 'rejected'");
                        }
                        
                        // Buscar dados da venda para processar eventos
                        $stmt_venda = $pdo->prepare("SELECT v.*, p.usuario_id, p.nome as produto_nome, p.checkout_hash FROM vendas v JOIN produtos p ON v.produto_id = p.id WHERE v.transacao_id = ? LIMIT 1");
                        $stmt_venda->execute([$payment_id]);
                        $venda_data = $stmt_venda->fetch(PDO::FETCH_ASSOC);
                        
                        if ($venda_data) {
                            // Disparar UTMfy para status rejeitado
                            if (function_exists('trigger_utmfy_integrations')) {
                                $valor_venda = (float)($venda_data['valor'] ?? 0);
                                $utmfy_payload = [
                                    'transacao_id' => $payment_id,
                                    'valor_total_compra' => $valor_venda,
                                    'comprador' => [
                                        'nome' => $venda_data['comprador_nome'] ?? '',
                                        'email' => $venda_data['comprador_email'] ?? '',
                                        'telefone' => $venda_data['comprador_telefone'] ?? '',
                                        'cpf' => $venda_data['comprador_cpf'] ?? ''
                                    ],
                                    'metodo_pagamento' => $venda_data['metodo_pagamento'] ?? 'Cartão de crédito',
                                    'produtos_comprados' => [[
                                        'produto_id' => $venda_data['produto_id'],
                                        'nome' => $venda_data['produto_nome'] ?? '',
                                        'valor' => $valor_venda
                                    ]],
                                    'data_venda' => $venda_data['data_venda'] ?? date('Y-m-d H:i:s')
                                ];
                                trigger_utmfy_integrations($venda_data['usuario_id'], $utmfy_payload, 'rejected', $venda_data['produto_id']);
                                log_check_status("Efí Cartão: UTMfy disparado para status 'rejected'");
                            }
                            
                            // Disparar tracking events (Facebook Ads, etc) para rejeição
                            $stmt_config = $pdo->prepare("SELECT checkout_config FROM produtos WHERE id = ? LIMIT 1");
                            $stmt_config->execute([$venda_data['produto_id']]);
                            $produto_config = $stmt_config->fetch(PDO::FETCH_ASSOC);
                            $checkout_config = json_decode($produto_config['checkout_config'] ?? '{}', true);
                            
                            if (function_exists('handle_tracking_events')) {
                                $valor_venda = (float)($venda_data['valor'] ?? 0);
                                $sale_details = [
                                    'transacao_id' => $payment_id,
                                    'valor_total_compra' => $valor_venda,
                                    'comprador' => [
                                        'nome' => $venda_data['comprador_nome'] ?? '',
                                        'email' => $venda_data['comprador_email'] ?? '',
                                        'telefone' => $venda_data['comprador_telefone'] ?? '',
                                        'cpf' => $venda_data['comprador_cpf'] ?? ''
                                    ],
                                    'metodo_pagamento' => $venda_data['metodo_pagamento'] ?? 'Cartão de crédito',
                                    'produtos_comprados' => [[
                                        'produto_id' => $venda_data['produto_id'],
                                        'nome' => $venda_data['produto_nome'] ?? '',
                                        'valor' => $valor_venda
                                    ]]
                                ];
                                handle_tracking_events('rejected', $sale_details, $checkout_config);
                                log_check_status("Efí Cartão: Tracking events disparados para status 'rejected'");
                            }
                            
                            // Disparar webhooks customizados para rejeição
                            if (function_exists('trigger_webhooks')) {
                                $event_data = [
                                    'transacao_id' => $payment_id,
                                    'valor_total_compra' => $valor_venda,
                                    'comprador' => [
                                        'nome' => $venda_data['comprador_nome'] ?? '',
                                        'email' => $venda_data['comprador_email'] ?? '',
                                        'telefone' => $venda_data['comprador_telefone'] ?? '',
                                        'cpf' => $venda_data['comprador_cpf'] ?? ''
                                    ],
                                    'metodo_pagamento' => $venda_data['metodo_pagamento'] ?? 'Cartão de crédito',
                                    'produtos_comprados' => [[
                                        'produto_id' => $venda_data['produto_id'],
                                        'nome' => $venda_data['produto_nome'] ?? '',
                                        'valor' => $valor_venda
                                    ]],
                                    'data_venda' => $venda_data['data_venda'] ?? date('Y-m-d H:i:s')
                                ];
                                trigger_webhooks($venda_data['usuario_id'], $event_data, 'rejected', $venda_data['produto_id']);
                                log_check_status("Efí Cartão: Webhooks disparados para status 'rejected'");
                            }
                        }
                    }
                } catch (Exception $e) {
                    log_check_status("Erro ao atualizar status rejeitado via polling (Efí Cartão): " . $e->getMessage());
                }
            }
            
            log_check_status("Efí Cartão: Retornando status final: " . $status);
            
            // Incluir mensagem e reason se disponíveis para ajudar no frontend
            $response_data = ['status' => $status];
            
            // Incluir reason e message quando disponíveis
            if (isset($status_data['reason']) && !empty($status_data['reason'])) {
                $response_data['reason'] = $status_data['reason'];
            }
            if (isset($status_data['message']) && !empty($status_data['message'])) {
                $response_data['message'] = $status_data['message'];
            }
            
            returnJsonSuccess($response_data);
        } else {
            returnJsonSuccess(['status' => 'pending', 'message' => 'Status não disponível']);
        }
    }
    elseif ($gateway === 'asaas') {
        // --- Lógica Asaas ---
        require_once __DIR__ . '/../gateways/asaas.php';
        
        $api_key = $tokens['asaas_api_key'] ?? '';
        $environment = $tokens['asaas_environment'] ?? 'sandbox';
        
        if (empty($api_key)) {
            returnJsonError('Credenciais Asaas não configuradas para este vendedor.', 400);
        }
        
        log_check_status("Asaas: Consultando status do pagamento - payment_id: " . substr($payment_id, 0, 20) . '...');
        log_check_status("Asaas: Ambiente: $environment");
        
        $status_data = asaas_get_payment_status($api_key, $payment_id, $environment);
        
        log_check_status("Asaas: Resposta da função asaas_get_payment_status: " . json_encode($status_data));
        
        if ($status_data === false) {
            log_check_status("Asaas: ERRO - asaas_get_payment_status retornou false. Verifique os logs para detalhes.");
            returnJsonSuccess(['status' => 'pending', 'message' => 'Status não disponível']);
        }
        
        if ($status_data && isset($status_data['status'])) {
            $status = $status_data['status'];
            log_check_status("Asaas: Status obtido da API: " . $status);
            
            // Se o status for aprovado, atualiza no banco e dispara webhook interno
            if ($status === 'approved') {
                try {
                    // Verifica se o status no banco já está aprovado
                    $stmt_check = $pdo->prepare("SELECT status_pagamento FROM vendas WHERE transacao_id = ? LIMIT 1");
                    $stmt_check->execute([$payment_id]);
                    $venda_check = $stmt_check->fetch(PDO::FETCH_ASSOC);
                    
                    if ($venda_check) {
                        if ($venda_check['status_pagamento'] !== 'approved') {
                            $pdo->prepare("UPDATE vendas SET status_pagamento = 'approved' WHERE transacao_id = ?")->execute([$payment_id]);
                            log_check_status("Asaas: Status atualizado de '" . $venda_check['status_pagamento'] . "' para 'approved'");
                            process_approved_payment_dispatches($pdo, $payment_id, 'Asaas Pix');
                        } else {
                            log_check_status("Asaas: Status já estava 'approved' - eventos não disparados (evita duplicação)");
                        }
                    }
                } catch (Exception $e) {
                    log_check_status("Erro ao atualizar status via polling (Asaas): " . $e->getMessage());
                }
            }
            
            log_check_status("Asaas: Retornando status final: " . $status);
            returnJsonSuccess(['status' => $status]);
        } else {
            // Se não conseguiu obter status, retorna pending para continuar verificando
            log_check_status("Asaas: Não foi possível obter status do pagamento - retornando pending");
            returnJsonSuccess(['status' => 'pending', 'message' => 'Status não disponível']);
        }
    }
    elseif ($gateway === 'pushinpay') {
        // --- Lógica PushinPay ---
        $token = $tokens['pushinpay_token'] ?? '';
        if (!$token) {
            returnJsonError('Token PushinPay não configurado para este vendedor.', 400);
        }

        // Tenta diferentes endpoints possíveis
        $endpoints = [
            'https://api.pushinpay.com.br/api/transactions/' . $payment_id,
            'https://api.pushinpay.com.br/api/pix/transactions/' . $payment_id,
            'https://api.pushinpay.com.br/api/pix/' . $payment_id
        ];
        
        $response = null;
        $http_code = 0;
        $curl_error = null;
        $data = null;
        
        foreach ($endpoints as $endpoint) {
            $ch = curl_init($endpoint);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $token,
                'Accept: application/json'
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            
            $response = curl_exec($ch);
            $curl_error = curl_error($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            // Se não houver erro e o código for 200-299, tenta processar
            if (!$curl_error && $http_code >= 200 && $http_code < 300) {
                $data = json_decode($response, true);
                if ($data && isset($data['status'])) {
                    break; // Endpoint correto encontrado
                }
            }
        }
        
        if ($curl_error) {
            returnJsonError('Erro de conexão com PushinPay: ' . $curl_error, 500);
        }
        
        if ($data && isset($data['status'])) {
            // Normaliza o status para 'approved' se for 'paid'
            $status = ($data['status'] === 'paid' || $data['status'] === 'approved' || $data['status'] === 'completed') ? 'approved' : $data['status'];
            
            // Se o status for aprovado, atualiza no banco e dispara webhook interno para processar entrega
            if ($status === 'approved') {
                try {
                    // Verifica se o status no banco já está aprovado
                    $stmt_check = $pdo->prepare("SELECT status_pagamento, checkout_session_uuid FROM vendas WHERE transacao_id = ? LIMIT 1");
                    $stmt_check->execute([$payment_id]);
                    $venda_check = $stmt_check->fetch(PDO::FETCH_ASSOC);
                    
                    if ($venda_check) {
                        if ($venda_check['status_pagamento'] !== 'approved') {
                            if (!empty($venda_check['checkout_session_uuid'])) {
                                $stmt_update_all = $pdo->prepare("UPDATE vendas SET status_pagamento = 'approved' WHERE checkout_session_uuid = ?");
                                $stmt_update_all->execute([$venda_check['checkout_session_uuid']]);
                            } else {
                                $pdo->prepare("UPDATE vendas SET status_pagamento = 'approved' WHERE transacao_id = ?")->execute([$payment_id]);
                            }
                            log_check_status("PushinPay: Status atualizado de '" . $venda_check['status_pagamento'] . "' para 'approved'");
                            process_approved_payment_dispatches($pdo, $payment_id, 'PushinPay');
                        } else {
                            log_check_status("PushinPay: Status já estava 'approved' - eventos não disparados (evita duplicação)");
                        }
                    }
                } catch (Exception $e) {
                    error_log("Erro ao atualizar status via polling: " . $e->getMessage());
                }
            }
            
            returnJsonSuccess(['status' => $status]);
        } else {
            // Se não conseguiu obter status, retorna pending para continuar verificando
            // Isso evita que o sistema pare de verificar se houver um problema temporário
            $error_msg = 'Status não disponível';
            if (isset($data['message'])) {
                $error_msg = $data['message'];
            } elseif ($response) {
                $error_msg = 'Resposta inesperada: ' . substr($response, 0, 100);
            }
            // Retorna pending ao invés de error para continuar verificando
            returnJsonSuccess(['status' => 'pending', 'message' => $error_msg]);
        }
    }
    elseif ($gateway === 'applyfy') {
        // --- Lógica Applyfy ---
        require_once __DIR__ . '/../gateways/applyfy.php';
        
        $public_key = $tokens['applyfy_public_key'] ?? '';
        $secret_key = $tokens['applyfy_secret_key'] ?? '';
        
        if (empty($public_key) || empty($secret_key)) {
            returnJsonError('Credenciais Applyfy não configuradas para este vendedor.', 400);
        }
        
        log_check_status("Applyfy: Consultando status do pagamento - transaction_id: " . substr($payment_id, 0, 20) . '...');
        
        $status_data = applyfy_get_transaction_status($public_key, $secret_key, $payment_id);
        
        if ($status_data === false) {
            log_check_status("Applyfy: ERRO - applyfy_get_transaction_status retornou false.");
            returnJsonSuccess(['status' => 'pending', 'message' => 'Status não disponível']);
        }
        
        if ($status_data && isset($status_data['status'])) {
            $status = $status_data['status'];
            log_check_status("Applyfy: Status obtido da API: " . $status);
            
            // Se o status for aprovado, atualiza no banco e dispara webhook interno
            if ($status === 'approved') {
                try {
                    // Verifica se o status no banco já está aprovado
                    $stmt_check = $pdo->prepare("SELECT status_pagamento FROM vendas WHERE transacao_id = ? LIMIT 1");
                    $stmt_check->execute([$payment_id]);
                    $venda_check = $stmt_check->fetch(PDO::FETCH_ASSOC);
                    
                    if ($venda_check) {
                        if ($venda_check['status_pagamento'] !== 'approved') {
                            $pdo->prepare("UPDATE vendas SET status_pagamento = 'approved' WHERE transacao_id = ?")->execute([$payment_id]);
                            log_check_status("Applyfy: Status atualizado de '" . $venda_check['status_pagamento'] . "' para 'approved'");
                            process_approved_payment_dispatches($pdo, $payment_id, 'Applyfy');
                        } else {
                            log_check_status("Applyfy: Status já estava 'approved' - eventos não disparados (evita duplicação)");
                        }
                    }
                } catch (Exception $e) {
                    log_check_status("Erro ao atualizar status via polling (Applyfy): " . $e->getMessage());
                }
            }
            
            log_check_status("Applyfy: Retornando status final: " . $status);
            returnJsonSuccess(['status' => $status]);
        } else {
            log_check_status("Applyfy: Não foi possível obter status do pagamento - retornando pending");
            returnJsonSuccess(['status' => 'pending', 'message' => 'Status não disponível']);
        }
    }
    elseif ($gateway === 'spacepag') {
        // --- Lógica SpacePag ---
        require_once __DIR__ . '/../gateways/spacepag.php';
        
        $public_key = $tokens['spacepag_public_key'] ?? '';
        $secret_key = $tokens['spacepag_secret_key'] ?? '';
        
        if (empty($public_key) || empty($secret_key)) {
            returnJsonError('Credenciais SpacePag não configuradas para este vendedor.', 400);
        }
        
        log_check_status("SpacePag: Consultando status do pagamento - transaction_id: " . substr($payment_id, 0, 20) . '...');
        
        $token_data = spacepag_get_access_token($public_key, $secret_key);
        if (!$token_data || empty($token_data['access_token'])) {
            log_check_status("SpacePag: ERRO ao obter access token.");
            returnJsonSuccess(['status' => 'pending', 'message' => 'Status não disponível']);
        }
        
        $status_data = spacepag_get_payment_status($token_data['access_token'], $payment_id);
        
        if ($status_data === false) {
            log_check_status("SpacePag: ERRO - spacepag_get_payment_status retornou false.");
            returnJsonSuccess(['status' => 'pending', 'message' => 'Status não disponível']);
        }
        
        if ($status_data && isset($status_data['status'])) {
            $status = $status_data['status'];
            log_check_status("SpacePag: Status obtido da API: " . $status);
            
            if ($status === 'approved') {
                try {
                    $stmt_check = $pdo->prepare("SELECT status_pagamento FROM vendas WHERE transacao_id = ? LIMIT 1");
                    $stmt_check->execute([$payment_id]);
                    $venda_check = $stmt_check->fetch(PDO::FETCH_ASSOC);
                    
                    if ($venda_check) {
                        if ($venda_check['status_pagamento'] !== 'approved') {
                            $pdo->prepare("UPDATE vendas SET status_pagamento = 'approved' WHERE transacao_id = ?")->execute([$payment_id]);
                            log_check_status("SpacePag: Status atualizado de '" . $venda_check['status_pagamento'] . "' para 'approved'");
                            process_approved_payment_dispatches($pdo, $payment_id, 'SpacePag');
                        } else {
                            log_check_status("SpacePag: Status já estava 'approved' - eventos não disparados (evita duplicação)");
                        }
                    }
                } catch (Exception $e) {
                    log_check_status("Erro ao atualizar status via polling (SpacePag): " . $e->getMessage());
                }
            }
            
            log_check_status("SpacePag: Retornando status final: " . $status);
            returnJsonSuccess(['status' => $status]);
        } else {
            log_check_status("SpacePag: Não foi possível obter status - retornando pending");
            returnJsonSuccess(['status' => 'pending', 'message' => 'Status não disponível']);
        }
    } else {
        // --- Lógica Mercado Pago ---
        $token = $tokens['mp_access_token'] ?? '';
        if (!$token) {
            returnJsonError('Token Mercado Pago não configurado para este vendedor.', 400);
        }

        $ch = curl_init('https://api.mercadopago.com/v1/payments/' . $payment_id);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        
        $response = curl_exec($ch);
        $curl_error = curl_error($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($curl_error) {
            returnJsonError('Erro de conexão com Mercado Pago: ' . $curl_error, 500);
        }
        
        $data = json_decode($response, true);

        if ($http_code == 200 && isset($data['status'])) {
            // Normaliza status do Mercado Pago: 'approved' ou 'paid' vira 'approved'
            $status = strtolower($data['status']);
            $normalized_status = ($status === 'approved' || $status === 'paid' || $status === 'completed') ? 'approved' : $status;
            
            log_check_status("Status Mercado Pago: " . $data['status'] . " -> Normalizado: " . $normalized_status);
            
            // Se o status for aprovado, atualiza no banco e dispara webhook interno
            if ($normalized_status === 'approved') {
                try {
                    // Verifica se o status no banco já está aprovado
                    $stmt_check = $pdo->prepare("SELECT status_pagamento, checkout_session_uuid FROM vendas WHERE transacao_id = ? LIMIT 1");
                    $stmt_check->execute([$payment_id]);
                    $venda_check = $stmt_check->fetch(PDO::FETCH_ASSOC);
                    
                    if ($venda_check) {
                        if ($venda_check['status_pagamento'] !== 'approved') {
                            if (!empty($venda_check['checkout_session_uuid'])) {
                                $stmt_update_all = $pdo->prepare("UPDATE vendas SET status_pagamento = 'approved' WHERE checkout_session_uuid = ?");
                                $stmt_update_all->execute([$venda_check['checkout_session_uuid']]);
                            } else {
                                $pdo->prepare("UPDATE vendas SET status_pagamento = 'approved' WHERE transacao_id = ?")->execute([$payment_id]);
                            }
                            log_check_status("Mercado Pago: Status atualizado de '" . $venda_check['status_pagamento'] . "' para 'approved'");
                            process_approved_payment_dispatches($pdo, $payment_id, 'Mercado Pago');
                        } else {
                            log_check_status("Mercado Pago: Status já estava 'approved' - eventos não disparados (evita duplicação)");
                        }
                    }
                } catch (Exception $e) {
                    log_check_status("Erro ao atualizar status no BD ou disparar webhook interno (Mercado Pago): " . $e->getMessage());
                }
            }
            
            returnJsonSuccess(['status' => $normalized_status]);
        } else {
            $error_msg = isset($data['message']) ? $data['message'] : 'Erro ao consultar status no Mercado Pago';
            returnJsonError($error_msg, $http_code ?: 500);
        }
    }

} catch (PDOException $e) {
    require_once __DIR__ . '/../helpers/security_helper.php';
    $log_file = __DIR__ . '/../check_status_log.txt';
    secure_log($log_file, "PDOException em check_status: " . $e->getMessage());
    returnJsonError('Erro ao processar requisição.', 500);
} catch (Exception $e) {
    require_once __DIR__ . '/../helpers/security_helper.php';
    $log_file = __DIR__ . '/../check_status_log.txt';
    secure_log($log_file, "Exception em check_status: " . $e->getMessage());
    returnJsonError('Erro ao processar requisição.', 500);
} catch (Error $e) {
    require_once __DIR__ . '/../helpers/security_helper.php';
    $log_file = __DIR__ . '/../check_status_log.txt';
    secure_log($log_file, "Error fatal em check_status: " . $e->getMessage());
    returnJsonError('Erro interno do servidor.', 500);
} catch (Throwable $e) {
    require_once __DIR__ . '/../helpers/security_helper.php';
    $log_file = __DIR__ . '/../check_status_log.txt';
    secure_log($log_file, "Throwable em check_status: " . $e->getMessage());
    returnJsonError('Erro ao processar requisição.', 500);
}
?>