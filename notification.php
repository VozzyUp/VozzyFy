<?php
// Registra handler de erro fatal ANTES de qualquer coisa
register_shutdown_function(function () {
    $error = error_get_last();
    if ($error !== NULL && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        ob_clean();
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'error' => 'Erro fatal no servidor: ' . $error['message'] . ' em ' . $error['file'] . ':' . $error['line']
        ]);
        exit;
    }
});

// Inicia o buffer de saída IMEDIATAMENTE.
ob_start();

// Aplicar headers de segurança antes de qualquer output
require_once __DIR__ . '/config/security_headers.php';
if (function_exists('apply_security_headers')) {
    apply_security_headers(false); // CSP permissivo para webhooks
}

// Desabilita exibição de erros
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Verifica se o arquivo config existe
$config_paths = [
    __DIR__ . '/config/config.php',
    __DIR__ . '/config.php'
];

$config_loaded = false;
foreach ($config_paths as $config_path) {
    if (file_exists($config_path)) {
        require_once $config_path;
        $config_loaded = true;
        break;
    }
}

if (!$config_loaded) {
    ob_clean();
    http_response_code(500);
    header('Content-Type: application/json');
    die(json_encode(['error' => 'Config não encontrado']));
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Importa Helper da UTMfy
if (file_exists(__DIR__ . '/helpers/utmfy_helper.php'))
    require_once __DIR__ . '/helpers/utmfy_helper.php';

// Inclui o helper de push para pedidos
if (file_exists(__DIR__ . '/helpers/push_pedidos_helper.php'))
    require_once __DIR__ . '/helpers/push_pedidos_helper.php';

// Importa Helper de criação de senha
if (file_exists(__DIR__ . '/helpers/password_setup_helper.php'))
    require_once __DIR__ . '/helpers/password_setup_helper.php';

// Importa Helper de Webhooks
if (file_exists(__DIR__ . '/helpers/webhook_helper.php')) {
    require_once __DIR__ . '/helpers/webhook_helper.php';
} else {
    // Log será feito depois quando log_webhook estiver disponível
    error_log("ERRO: Helper webhook_helper.php NÃO encontrado em: " . __DIR__ . '/helpers/webhook_helper.php');
}

// Importa Helper de Disparo Centralizado de Eventos
if (file_exists(__DIR__ . '/helpers/payment_events_dispatcher.php')) {
    require_once __DIR__ . '/helpers/payment_events_dispatcher.php';
} else {
    error_log("ERRO: Helper payment_events_dispatcher.php NÃO encontrado em: " . __DIR__ . '/helpers/payment_events_dispatcher.php');
}

// PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/notification_api_errors.log');

// Carrega helpers de segurança
require_once __DIR__ . '/helpers/security_helper.php';

// Whitelist de IPs dos gateways de pagamento (adicionar IPs reais em produção)
$webhook_ip_whitelist = [
    // Mercado Pago IPs (exemplos - adicionar IPs reais)
    // '189.1.160.0/19',
    // '189.1.192.0/19',
    // Efí IPs (adicionar IPs reais)
    // PushinPay IPs (adicionar IPs reais)
    // Beehive IPs (adicionar IPs reais)
    // Hypercash IPs (adicionar IPs reais)
];

// Validação de IP do webhook (opcional - pode ser desabilitado se usar apenas assinatura)
function is_webhook_ip_allowed($ip)
{
    global $webhook_ip_whitelist;

    // Se whitelist vazia, permite (usa apenas validação de assinatura)
    if (empty($webhook_ip_whitelist)) {
        return true;
    }

    // Verifica se IP está na whitelist
    foreach ($webhook_ip_whitelist as $allowed_ip) {
        if (strpos($allowed_ip, '/') !== false) {
            // CIDR notation
            list($subnet, $mask) = explode('/', $allowed_ip);
            if ((ip2long($ip) & ~((1 << (32 - $mask)) - 1)) == ip2long($subnet)) {
                return true;
            }
        } else {
            // IP exato
            if ($ip === $allowed_ip) {
                return true;
            }
        }
    }

    return false;
}

// Rate limiting para webhooks
$client_ip = get_client_ip();
$rate_limit = check_rate_limit_db('webhook_receive', 100, 60, $client_ip); // 100 requisições por minuto
if (!$rate_limit['allowed']) {
    log_security_event('rate_limit_exceeded_webhook', [
        'ip' => $client_ip,
        'reset_at' => $rate_limit['reset_at']
    ]);
    ob_clean();
    http_response_code(429);
    header('Content-Type: application/json');
    die(json_encode(['error' => 'Rate limit exceeded']));
}

function log_webhook($message)
{
    $log_file = __DIR__ . '/webhook_log.txt';
    // Usar secure_log ao invés de file_put_contents direto
    if (function_exists('secure_log')) {
        secure_log($log_file, $message, 'info');
    } else {
        @file_put_contents($log_file, date('Y-m-d H:i:s') . " - " . $message . "\n", FILE_APPEND);
    }
}

$phpmailer_path = __DIR__ . '/PHPMailer/src/';
if (file_exists($phpmailer_path . 'Exception.php')) {
    require_once $phpmailer_path . 'Exception.php';
    require_once $phpmailer_path . 'PHPMailer.php';
    require_once $phpmailer_path . 'SMTP.php';
}

// --- FUNÇÕES AUXILIARES ---

function sendFacebookConversionEvent($pixel_id, $api_token, $event_name, $sale_details, $event_source_url)
{
    if (empty($pixel_id) || empty($api_token)) {
        log_webhook("TRACKING FB: Pixel ID ou API Token vazio. Pixel: " . ($pixel_id ?: 'vazio') . " | Token: " . ($api_token ? 'configurado' : 'vazio'));
        return;
    }

    $url = "https://graph.facebook.com/v19.0/" . $pixel_id . "/events?access_token=" . $api_token;

    $user_data = [
        'em' => [hash('sha256', strtolower($sale_details['comprador_email']))],
        'ph' => [hash('sha256', preg_replace('/[^0-9]/', '', $sale_details['comprador_telefone']))],
    ];
    $name_parts = explode(' ', $sale_details['comprador_nome'], 2);
    $user_data['fn'] = [hash('sha256', strtolower($name_parts[0]))];
    if (isset($name_parts[1]))
        $user_data['ln'] = [hash('sha256', strtolower($name_parts[1]))];

    // Priorizar valor_total_compra se disponível (inclui order bumps), senão usar valor
    $valor_evento = isset($sale_details['valor_total_compra']) ? (float) $sale_details['valor_total_compra'] : (float) ($sale_details['valor'] ?? 0);

    $payload = [
        'data' => [
            [
                'event_name' => $event_name,
                'event_time' => time(),
                'event_source_url' => $event_source_url,
                'user_data' => $user_data,
                'custom_data' => ['currency' => 'BRL', 'value' => $valor_evento],
                'action_source' => 'website',
            ]
        ]
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
        log_webhook("TRACKING FB: Sucesso! Evento '$event_name' enviado para Pixel ID $pixel_id. HTTP $http_code");
    } else {
        log_webhook("TRACKING FB: ERRO! Falha ao enviar evento '$event_name' para Pixel ID $pixel_id. HTTP $http_code. Resposta: " . substr($response, 0, 200));
    }
}

function handle_tracking_events($status, $sale_details, $checkout_config)
{
    $tracking_config = $checkout_config['tracking'] ?? [];
    if (empty($tracking_config)) {
        log_webhook("TRACKING: Configuração de rastreamento vazia para o produto.");
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
        log_webhook("TRACKING: Status '$status' não mapeado para evento de rastreamento.");
        return;
    }

    $event_info = $event_map[$status];
    $event_key = $event_info['key'];
    $fb_event_name = $event_info['fb_name'];

    // Verifica se o evento está habilitado no Facebook
    $fb_events_enabled = $tracking_config['events']['facebook'] ?? [];
    if (empty($fb_events_enabled[$event_key])) {
        log_webhook("TRACKING FB: Evento '$event_key' não está habilitado para Facebook. Eventos habilitados: " . json_encode($fb_events_enabled));
        return;
    }

    $pixel_id = $tracking_config['facebookPixelId'] ?? '';
    $api_token = $tracking_config['facebookApiToken'] ?? '';

    if (empty($pixel_id)) {
        log_webhook("TRACKING FB: Facebook Pixel ID não configurado.");
        return;
    }

    if (empty($api_token)) {
        log_webhook("TRACKING FB: Facebook API Token não configurado. Evento '$fb_event_name' não será enviado.");
        return;
    }

    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
    $checkout_url = $protocol . $_SERVER['HTTP_HOST'] . '/checkout?p=' . ($sale_details['checkout_hash'] ?? '');

    log_webhook("TRACKING FB: Disparando evento '$fb_event_name' para Pixel ID: $pixel_id | Status: $status | Event Key: $event_key");
    sendFacebookConversionEvent($pixel_id, $api_token, $fb_event_name, $sale_details, $checkout_url);
}

function trigger_webhooks($usuario_id, $event_data, $trigger_event, $produto_id = null)
{
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

    log_webhook("WEBHOOKS: Verificando webhooks para evento '$trigger_event' (usuario_id: $usuario_id, produto_id: " . ($produto_id ?? 'NULL') . ", event_field: $event_field)");

    try {
        $stmt = $pdo->prepare("SELECT url FROM webhooks WHERE usuario_id = :uid AND {$event_field} = 1 AND (produto_id IS NULL OR produto_id = :pid)");
        $stmt->execute([':uid' => $usuario_id, ':pid' => $produto_id]);
        $webhooks = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($webhooks)) {
            log_webhook("WEBHOOKS: Nenhum webhook encontrado para evento '$trigger_event' (usuario_id: $usuario_id, produto_id: " . ($produto_id ?? 'NULL') . ", event_field: $event_field)");
            return;
        }

        log_webhook("WEBHOOKS: Encontrados " . count($webhooks) . " webhook(s) para evento '$trigger_event'");

        $json_payload = json_encode(['event' => $trigger_event, 'timestamp' => date('Y-m-d H:i:s'), 'data' => $event_data]);

        // Validar URLs de webhook contra SSRF
        require_once __DIR__ . '/helpers/security_helper.php';

        foreach ($webhooks as $url) {
            // Validar URL contra SSRF antes de fazer requisição
            $ssrf_validation = validate_url_for_ssrf($url);
            if (!$ssrf_validation['valid']) {
                log_webhook("WEBHOOKS: Webhook bloqueado por SSRF: " . $url . " - " . ($ssrf_validation['error'] ?? 'Erro desconhecido'));
                log_security_event('ssrf_blocked_webhook', [
                    'url' => $url,
                    'ip' => get_client_ip(),
                    'error' => $ssrf_validation['error']
                ]);
                continue; // Pula este webhook
            }

            log_webhook("WEBHOOKS: Disparando webhook para evento '$trigger_event' - URL: $url");

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'X-Starfy-Event: ' . $trigger_event]);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json_payload);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false); // Não seguir redirecionamentos (prevenção SSRF)

            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error = curl_error($ch);
            curl_close($ch);

            if ($curl_error) {
                log_webhook("WEBHOOKS: Erro cURL ao disparar webhook para $url: " . $curl_error);
            } else {
                log_webhook("WEBHOOKS: Webhook disparado com sucesso para $url (HTTP $http_code)");
            }
        }
    } catch (Exception $e) {
        log_webhook("WEBHOOKS: Erro ao disparar webhooks: " . $e->getMessage());
        // Não lança exceção para não interromper o fluxo de pagamento
    }
}

/**
 * Converte usuário de tipo 'usuario' para 'infoprodutor' se tiver compras aprovadas
 * @param string $email Email do usuário
 * @return bool True se conversão foi feita, False caso contrário
 */
if (!function_exists('convert_user_to_infoprodutor_if_needed')) {
    function convert_user_to_infoprodutor_if_needed($email)
    {
        global $pdo;
        try {
            // Busca usuário
            $stmt = $pdo->prepare("SELECT id, tipo FROM usuarios WHERE usuario = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user || $user['tipo'] !== 'usuario') {
                return false; // Já é infoprodutor ou não existe
            }

            // Verifica se tem compras aprovadas
            $stmt_vendas = $pdo->prepare("
                SELECT COUNT(*) as total 
                FROM vendas 
                WHERE comprador_email = ? AND status_pagamento IN ('approved', 'paid', 'completed')
            ");
            $stmt_vendas->execute([$email]);
            $vendas = $stmt_vendas->fetch(PDO::FETCH_ASSOC);

            if ($vendas && $vendas['total'] > 0) {
                // Converte para infoprodutor
                $stmt_update = $pdo->prepare("UPDATE usuarios SET tipo = 'infoprodutor' WHERE id = ?");
                $stmt_update->execute([$user['id']]);

                // Atribuir plano free se SaaS estiver habilitado
                if (function_exists('saas_enabled') && function_exists('saas_assign_free_plan')) {
                    if (saas_enabled()) {
                        saas_assign_free_plan($user['id']);
                    }
                }

                log_webhook("Usuário convertido para infoprodutor: $email (ID: {$user['id']})");
                return true;
            }

            return false;
        } catch (PDOException $e) {
            error_log("Erro ao converter usuário para infoprodutor: " . $e->getMessage());
            log_webhook("ERRO ao converter usuário para infoprodutor: " . $e->getMessage());
            return false;
        }
    }
}

function process_single_product_delivery($product_data, $customer_email)
{
    global $pdo;
    $type = $product_data['tipo_entrega'];
    $content = $product_data['conteudo_entrega'];
    if ($type === 'link' && !empty($content))
        return ['success' => true, 'product_name' => $product_data['produto_nome'], 'content_type' => 'link', 'content_value' => $content];
    if ($type === 'email_pdf' && !empty($content) && file_exists('uploads/' . $content))
        return ['success' => true, 'product_name' => $product_data['produto_nome'], 'content_type' => 'pdf', 'content_value' => 'uploads/' . $content];
    if ($type === 'area_membros') {
        $pdo->prepare("INSERT IGNORE INTO alunos_acessos (aluno_email, produto_id) VALUES (?, ?)")->execute([$customer_email, $product_data['produto_id']]);

        // NÃO converte automaticamente - usuário deve clicar no botão "mudar para infoprodutor" se SaaS estiver ativado

        return ['success' => true, 'product_name' => $product_data['produto_nome'], 'content_type' => 'area_membros', 'content_value' => null];
    }
    if ($type === 'produto_fisico') {
        return ['success' => true, 'product_name' => $product_data['produto_nome'], 'content_type' => 'produto_fisico', 'content_value' => null];
    }
    if ($type === 'somente_pagamento') {
        log_webhook("    INFO DE ENTREGA (Somente Pagamento): Produto '{$product_data['produto_nome']}' - Nenhum email será enviado.");
        return ['success' => true, 'product_name' => $product_data['produto_nome'], 'content_type' => 'somente_pagamento', 'content_value' => null];
    }
    return ['success' => false, 'message' => 'Tipo desconhecido ou vazio'];
}

// --- FUNÇÃO DE ENVIO DE E-MAIL (ATUALIZADA PARA USAR O TEMPLATE DO BANCO) ---
function send_delivery_email_consolidated($to_email, $customer_name, $products, $pass, $login_url, $address_data = null, $setup_token = null)
{
    global $pdo;
    log_webhook("send_delivery_email_consolidated chamada para: " . $to_email);

    $mail = new PHPMailer(true);
    try {
        // Busca configurações SMTP e TEMPLATE do banco
        $stmt = $pdo->query("SELECT chave, valor FROM configuracoes WHERE chave IN ('smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_encryption', 'smtp_from_email', 'smtp_from_name', 'email_template_delivery_subject', 'email_template_delivery_html')");
        $config = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        log_webhook("Configuracoes SMTP carregadas. Host: " . ($config['smtp_host'] ?? 'NÃO CONFIGURADO'));

        // Busca logo configurada da tabela configuracoes_sistema
        $logo_url_raw = '';
        if (function_exists('getSystemSetting')) {
            $logo_url_raw = getSystemSetting('logo_url', '');
        } else {
            $stmt_logo = $pdo->query("SELECT valor FROM configuracoes_sistema WHERE chave = 'logo_url' LIMIT 1");
            $logo_result = $stmt_logo->fetch(PDO::FETCH_ASSOC);
            $logo_url_raw = $logo_result ? $logo_result['valor'] : '';
        }

        // Constrói URL absoluta da logo
        $logo_url_final = '';
        if (!empty($logo_url_raw)) {
            if (strpos($logo_url_raw, 'http') === 0) {
                // Já é uma URL absoluta
                $logo_url_final = $logo_url_raw;
            } else {
                // É um caminho relativo, constrói URL absoluta
                $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'];
                $logo_path = ltrim($logo_url_raw, '/');
                $logo_url_final = $protocol . '://' . $host . '/' . $logo_path;
            }
        }
        log_webhook("Logo URL final: " . ($logo_url_final ?: 'NÃO CONFIGURADA'));

        // Configuração de Remetente
        $default_from = 'noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $fromEmail = !empty($config['smtp_from_email']) ? $config['smtp_from_email'] : ($config['smtp_username'] ?? $default_from);
        if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL))
            $fromEmail = $default_from;

        if (empty($config['smtp_host'])) {
            $mail->isMail();
        } else {
            $mail->isSMTP();
            $mail->Host = $config['smtp_host'];
            $mail->Port = $config['smtp_port'];
            $mail->SMTPAuth = true;
            $mail->Username = $config['smtp_username'];
            $mail->Password = $config['smtp_password'];
            $mail->SMTPSecure = $config['smtp_encryption'] == 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        }

        $mail->setFrom($fromEmail, $config['smtp_from_name'] ?? 'Starfy');
        $mail->addAddress($to_email, $customer_name);

        // Usa o assunto do banco ou um padrão
        $email_subject = trim($config['email_template_delivery_subject'] ?? '');
        $mail->Subject = !empty($email_subject) ? $email_subject : 'Seu acesso chegou!';
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';

        // Usa o template do banco
        $template = $config['email_template_delivery_html'] ?? '';

        // Log para debug
        log_webhook("Template carregado: " . (empty($template) ? 'VAZIO' : 'OK (' . strlen($template) . ' caracteres)'));

        // Se o template estiver vazio, gera template padrão usando configurações da plataforma
        if (empty(trim($template))) {
            log_webhook("AVISO: Template vazio, gerando template padrão com configurações da plataforma");

            // Carrega helper de template
            if (file_exists(__DIR__ . '/helpers/email_template_helper.php')) {
                require_once __DIR__ . '/helpers/email_template_helper.php';
            }

            // Busca configurações da plataforma
            $logo_checkout_url_raw = '';
            if (function_exists('getSystemSetting')) {
                $logo_checkout_url_raw = getSystemSetting('logo_checkout_url', '');
                if (empty($logo_checkout_url_raw)) {
                    $logo_checkout_url_raw = getSystemSetting('logo_url', '');
                }
            } else {
                $stmt_logo = $pdo->query("SELECT valor FROM configuracoes_sistema WHERE chave IN ('logo_checkout_url', 'logo_url') ORDER BY FIELD(chave, 'logo_checkout_url', 'logo_url') LIMIT 1");
                $logo_result = $stmt_logo->fetch(PDO::FETCH_ASSOC);
                $logo_checkout_url_raw = $logo_result ? $logo_result['valor'] : '';
            }

            // Normaliza URL da logo
            $logo_checkout_url = '';
            if (!empty($logo_checkout_url_raw)) {
                if (strpos($logo_checkout_url_raw, 'http') === 0) {
                    $logo_checkout_url = $logo_checkout_url_raw;
                } else {
                    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
                    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                    $logo_path = ltrim($logo_checkout_url_raw, '/');
                    $logo_checkout_url = $protocol . '://' . $host . '/' . $logo_path;
                }
            }

            // Busca cor primária e nome da plataforma
            $cor_primaria = '#32e768';
            $nome_plataforma = 'Starfy';

            if (function_exists('getSystemSetting')) {
                $cor_primaria = getSystemSetting('cor_primaria', '#32e768');
                $nome_plataforma = getSystemSetting('nome_plataforma', 'Starfy');
            } else {
                $stmt_sistema = $pdo->query("SELECT chave, valor FROM configuracoes_sistema WHERE chave IN ('cor_primaria', 'nome_plataforma')");
                $sistema_configs = $stmt_sistema->fetchAll(PDO::FETCH_KEY_PAIR);
                $cor_primaria = $sistema_configs['cor_primaria'] ?? '#32e768';
                $nome_plataforma = $sistema_configs['nome_plataforma'] ?? 'Starfy';
            }

            // Gera template padrão
            if (function_exists('generate_default_delivery_email_template')) {
                $template = generate_default_delivery_email_template($logo_checkout_url, $cor_primaria, $nome_plataforma);
                log_webhook("Template padrão gerado com sucesso (logo: " . ($logo_checkout_url ? 'SIM' : 'NÃO') . ", cor: $cor_primaria, nome: $nome_plataforma)");
            } else {
                log_webhook("ERRO: Função generate_default_delivery_email_template não encontrada!");
                // Fallback básico
                $template = '<p>Olá {CLIENT_NAME}, aqui estão seus produtos:</p><!-- LOOP_PRODUCTS_START --><p>{PRODUCT_NAME}</p><!-- LOOP_PRODUCTS_END -->';
            }
        }

        // Prepara dados de endereço para substituição
        $address_html = '';
        if ($address_data && !empty($address_data['cep'])) {
            $address_html = '<div style="margin-top: 20px; padding: 15px; background-color: #f5f5f5; border-radius: 5px; border-left: 4px solid #4CAF50;">';
            $address_html .= '<h3 style="margin-top: 0; color: #333; font-size: 16px;">Endereço de Entrega</h3>';
            $address_html .= '<p style="margin: 5px 0; color: #666; font-size: 14px;"><strong>CEP:</strong> ' . htmlspecialchars($address_data['cep']) . '</p>';
            $address_html .= '<p style="margin: 5px 0; color: #666; font-size: 14px;"><strong>Endereço:</strong> ' . htmlspecialchars($address_data['logradouro']) . ', ' . htmlspecialchars($address_data['numero']);
            if (!empty($address_data['complemento'])) {
                $address_html .= ' - ' . htmlspecialchars($address_data['complemento']);
            }
            $address_html .= '</p>';
            $address_html .= '<p style="margin: 5px 0; color: #666; font-size: 14px;"><strong>Bairro:</strong> ' . htmlspecialchars($address_data['bairro']) . '</p>';
            $address_html .= '<p style="margin: 5px 0; color: #666; font-size: 14px;"><strong>Cidade/UF:</strong> ' . htmlspecialchars($address_data['cidade']) . ' - ' . htmlspecialchars($address_data['estado']) . '</p>';
            $address_html .= '</div>';
        }

        // Prepara URL de criação de senha se houver token
        $setup_password_url = '';
        if ($setup_token) {
            $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $setup_password_url = $protocol . '://' . $host . '/member_setup_password?token=' . urlencode($setup_token);
        }

        // Substitui variáveis globais (exceto as que serão substituídas dentro dos blocos de produtos)
        // Não substitui {SETUP_PASSWORD_URL} e {MEMBER_AREA_LOGIN_URL} aqui, pois serão substituídos dentro dos blocos condicionais
        $body = str_replace(
            ['{CLIENT_NAME}', '{CLIENT_EMAIL}', '{MEMBER_AREA_PASSWORD}', '{LOGO_URL}', '{DELIVERY_ADDRESS}'],
            [$customer_name, $to_email, $pass ?? 'N/A', $logo_url_final, $address_html],
            $template
        );

        // Substitui placeholders globais que não estão dentro de blocos condicionais (fallback)
        $body = str_replace('{SETUP_PASSWORD_URL}', htmlspecialchars($setup_password_url ?? ''), $body);
        $body = str_replace('{MEMBER_AREA_LOGIN_URL}', htmlspecialchars($login_url ?? '#'), $body);

        // Também substitui URLs de imagens quebradas ou genéricas pela logo configurada
        if (!empty($logo_url_final)) {
            // Substitui URLs comuns de imagens quebradas ou placeholders
            $body = preg_replace('/src=["\']https?:\/\/[^"\']*imgbb\.com[^"\']*["\']/i', 'src="' . $logo_url_final . '"', $body);
            $body = preg_replace('/src=["\']https?:\/\/[^"\']*ibb\.co[^"\']*["\']/i', 'src="' . $logo_url_final . '"', $body);
            // Substitui qualquer src vazio ou quebrado no início do template
            $body = preg_replace('/<img[^>]*src=["\'](?!https?:\/\/)[^"\']*["\'][^>]*>/i', '<img src="' . $logo_url_final . '" alt="Logo" style="max-width: 200px; height: auto;">', $body, 1);
        }

        // Processa o Loop de Produtos
        $loop_start = '<!-- LOOP_PRODUCTS_START -->';
        $loop_end = '<!-- LOOP_PRODUCTS_END -->';
        if (strpos($body, $loop_start) !== false) {
            $part = substr($body, strpos($body, $loop_start) + strlen($loop_start));
            $part = substr($part, 0, strpos($part, $loop_end));
            $html_prods = '';
            foreach ($products as $p) {
                $item = str_replace('{PRODUCT_NAME}', $p['product_name'], $part);
                $item = str_replace('{PRODUCT_LINK}', ($p['content_type'] == 'link' ? $p['content_value'] : ''), $item);

                // Limpa tags condicionais (ex: <!-- IF_PRODUCT_TYPE_LINK -->)
                $types = ['link', 'pdf', 'area_membros', 'produto_fisico'];
                foreach ($types as $t) {
                    $tag = 'PRODUCT_TYPE_' . strtoupper($t == 'area_membros' ? 'MEMBER_AREA' : ($t == 'produto_fisico' ? 'PHYSICAL_PRODUCT' : $t));
                    if ($p['content_type'] == $t)
                        $item = str_replace(["<!-- IF_$tag -->", "<!-- END_IF_$tag -->"], '', $item);
                    else
                        $item = preg_replace("/<!-- IF_$tag -->.*?<!-- END_IF_$tag -->/s", "", $item);
                }

                // Processa tags condicionais para área de membros (novo usuário vs existente)
                // IMPORTANTE: Se as tags não existirem no template, não remove nada
                if ($p['content_type'] == 'area_membros') {
                    if ($setup_token) {
                        // Cliente novo - mostra IF_NEW_USER_SETUP
                        if (strpos($item, '<!-- IF_NEW_USER_SETUP -->') !== false) {
                            $item = str_replace(["<!-- IF_NEW_USER_SETUP -->", "<!-- END_IF_NEW_USER_SETUP -->"], '', $item);
                            $item = preg_replace("/<!-- IF_EXISTING_USER -->.*?<!-- END_IF_EXISTING_USER -->/s", "", $item);
                        }
                    } else {
                        // Cliente existente - mostra IF_EXISTING_USER
                        if (strpos($item, '<!-- IF_EXISTING_USER -->') !== false) {
                            $item = str_replace(["<!-- IF_EXISTING_USER -->", "<!-- END_IF_EXISTING_USER -->"], '', $item);
                            $item = preg_replace("/<!-- IF_NEW_USER_SETUP -->.*?<!-- END_IF_NEW_USER_SETUP -->/s", "", $item);
                        }
                    }

                    // Substitui placeholders de área de membros DENTRO do item (após processar blocos condicionais)
                    $item = str_replace('{SETUP_PASSWORD_URL}', htmlspecialchars($setup_password_url ?? ''), $item);
                    $item = str_replace('{MEMBER_AREA_LOGIN_URL}', htmlspecialchars($login_url ?? '#'), $item);
                } else {
                    // Remove ambas as tags se não for área de membros (apenas se existirem)
                    if (strpos($item, '<!-- IF_NEW_USER_SETUP -->') !== false) {
                        $item = preg_replace("/<!-- IF_NEW_USER_SETUP -->.*?<!-- END_IF_NEW_USER_SETUP -->/s", "", $item);
                    }
                    if (strpos($item, '<!-- IF_EXISTING_USER -->') !== false) {
                        $item = preg_replace("/<!-- IF_EXISTING_USER -->.*?<!-- END_IF_EXISTING_USER -->/s", "", $item);
                    }
                }

                $html_prods .= $item;
            }
            $body = str_replace($loop_start . $part . $loop_end, $html_prods, $body);
        }

        // Log do corpo do email para debug (apenas primeiros 500 caracteres)
        log_webhook("Corpo do email (primeiros 500 chars): " . substr(strip_tags($body), 0, 500));

        // Verifica se o corpo do email não está vazio
        if (empty(trim(strip_tags($body)))) {
            log_webhook("ERRO: Corpo do email está vazio após processamento! Gerando conteúdo mínimo...");
            // Adiciona conteúdo mínimo para garantir que o email seja enviado
            $body = '<p>Olá ' . htmlspecialchars($customer_name) . ',</p>';
            $body .= '<p>Seu produto está disponível!</p>';
            if ($setup_token && !empty($setup_password_url)) {
                $body .= '<p><a href="' . htmlspecialchars($setup_password_url) . '">Clique aqui para criar sua senha e acessar</a></p>';
            } elseif (!empty($login_url) && $login_url !== '#') {
                $body .= '<p><a href="' . htmlspecialchars($login_url) . '">Clique aqui para acessar sua área de membros</a></p>';
            }
            foreach ($products as $p) {
                $body .= '<p><strong>' . htmlspecialchars($p['product_name']) . '</strong></p>';
            }
            log_webhook("Conteúdo mínimo gerado: " . strlen($body) . " caracteres");
        }

        $mail->Body = $body;

        // Anexos
        foreach ($products as $p) {
            if ($p['content_type'] == 'pdf' && file_exists($p['content_value'])) {
                $mail->addAttachment($p['content_value'], basename($p['content_value']));
            }
        }

        log_webhook("Tentando enviar email...");
        log_webhook("Setup token: " . ($setup_token ? 'SIM (' . substr($setup_token, 0, 10) . '...)' : 'NÃO'));
        log_webhook("Setup password URL: " . ($setup_password_url ?: 'NÃO DEFINIDA'));
        log_webhook("Total de produtos: " . count($products));
        log_webhook("Tamanho do corpo do email: " . strlen($body) . " caracteres");

        $mail->send();
        log_webhook("Email enviado com sucesso!");
        return true;
    } catch (Exception $e) {
        log_webhook("ERRO ao enviar email: " . $e->getMessage());
        log_webhook("Stack trace: " . $e->getTraceAsString());
        return false;
    }
}

function create_notification($usuario_id, $tipo, $mensagem, $valor, $venda_id_fk = null, $metodo = null)
{
    global $pdo;
    if (!$usuario_id)
        return;
    try {
        $link = $venda_id_fk ? "/index?pagina=vendas&id={$venda_id_fk}" : null;
        $pdo->prepare("INSERT INTO notificacoes (usuario_id, tipo, mensagem, valor, link_acao, venda_id_fk, metodo_pagamento) VALUES (?, ?, ?, ?, ?, ?, ?)")
            ->execute([$usuario_id, $tipo, $mensagem, $valor, $link, $venda_id_fk, $metodo]);
    } catch (Exception $e) {
        log_webhook("Erro notificacao: " . $e->getMessage());
    }
}

// -----------------------------------------------------------------------------
// EXECUÇÃO DA LÓGICA
// -----------------------------------------------------------------------------

try {
    // Verifica se $pdo está disponível antes de processar qualquer requisição
    if (!isset($pdo)) {
        ob_clean();
        http_response_code(500);
        header('Content-Type: application/json');
        error_log("Erro: \$pdo não está definido em notification.php");
        echo json_encode(['error' => 'Erro de configuração do servidor - banco de dados não conectado']);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $is_json = stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false;
        $data = [];
        $action = $_GET['action'] ?? '';

        if ($is_json) {
            $input = file_get_contents('php://input');
            $data = json_decode($input, true) ?? [];
        } else {
            $data = $_POST;
            if (empty($data)) {
                $input = file_get_contents('php://input');
                parse_str($input, $data);
            }
        }

        // --- API Actions do Frontend ---
        if ($action === 'mark_all_as_read' || $action === 'mark_as_displayed_live') {
            if (!isset($_SESSION['id'])) {
                echo json_encode(['error' => 'Auth required']);
                exit;
            }
            if ($action === 'mark_all_as_read') {
                $pdo->prepare("UPDATE notificacoes SET lida = 1 WHERE usuario_id = ?")->execute([$_SESSION['id']]);
            } else {
                $notif_id = $data['notification_id'] ?? ($_POST['notification_id'] ?? null);
                if ($notif_id)
                    $pdo->prepare("UPDATE notificacoes SET displayed_live = 1 WHERE id = ? AND usuario_id = ?")->execute([$notif_id, $_SESSION['id']]);
            }
            echo json_encode(['success' => true]);
            exit;
        }

        // --- WEBHOOK (Gateway) ---
        header('Content-Type: application/json');
        ob_clean();
        echo json_encode(['status' => 'success']);
        if (function_exists('fastcgi_finish_request'))
            fastcgi_finish_request();

        // Log do webhook recebido para debug (sanitizado)
        require_once __DIR__ . '/helpers/security_helper.php';
        $sanitized_data = sanitize_log_message(json_encode($data));
        log_webhook("Webhook recebido: " . $sanitized_data);

        // Coletar headers para validação de assinatura
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $header_name = str_replace('_', '-', substr($key, 5));
                $headers[$header_name] = $value;
                $headers['HTTP_' . strtoupper(str_replace('-', '_', $header_name))] = $value;
            }
        }

        // Tenta extrair payment_id de diferentes formatos (Mercado Pago, PushinPay e Efí)
        $payment_id = null;
        $gateway_detected = null;

        // Formato Efí Cartão - charge_id (prioridade para detectar gateway de cartão)
        if (isset($data['data']['charge_id'])) {
            $payment_id = $data['data']['charge_id'];
            $gateway_detected = 'efi_card';
            log_webhook("Payment ID extraído do formato Efí Cartão (data.charge_id): " . $payment_id);
        } elseif (isset($data['charge_id'])) {
            $payment_id = $data['charge_id'];
            $gateway_detected = 'efi_card';
            log_webhook("Payment ID extraído do formato Efí Cartão (charge_id): " . $payment_id);
        }

        // Formato Efí Pix - txid (prioridade para detectar gateway)
        if (!$payment_id && isset($data['txid'])) {
            $payment_id = $data['txid'];
            $gateway_detected = 'efi';
            log_webhook("Payment ID extraído do formato Efí (txid): " . $payment_id);
        } elseif (!$payment_id && isset($data['pix'][0]['txid'])) {
            // Formato alternativo Efí (array de pix)
            $payment_id = $data['pix'][0]['txid'];
            $gateway_detected = 'efi';
            log_webhook("Payment ID extraído do formato Efí (pix[0].txid): " . $payment_id);
        }

        // Formato Mercado Pago - webhook padrão
        if (!$payment_id) {
            if (isset($data['type']) && $data['type'] === 'payment' && isset($data['data']['id'])) {
                $payment_id = $data['data']['id'];
                $gateway_detected = 'mercadopago';
                log_webhook("Payment ID extraído do formato Mercado Pago (type=payment): " . $payment_id);
            } elseif (isset($data['data']['id'])) {
                $payment_id = $data['data']['id'];
                $gateway_detected = 'mercadopago';
                log_webhook("Payment ID extraído de data.id: " . $payment_id);
            } elseif (isset($data['id'])) {
                $payment_id = $data['id'];
                log_webhook("Payment ID extraído de id: " . $payment_id);
            }
        }

        // Formato PushinPay (pode vir em diferentes campos)
        if (!$payment_id) {
            if (isset($data['transaction_id'])) {
                $payment_id = $data['transaction_id'];
                $gateway_detected = 'pushinpay';
                log_webhook("Payment ID extraído de transaction_id: " . $payment_id);
            } elseif (isset($data['transaction']['id'])) {
                $payment_id = $data['transaction']['id'];
                $gateway_detected = 'pushinpay';
                log_webhook("Payment ID extraído de transaction.id: " . $payment_id);
            } elseif (isset($data['payment_id'])) {
                $payment_id = $data['payment_id'];
                log_webhook("Payment ID extraído de payment_id: " . $payment_id);
            }
        }

        // Formato Beehive (conforme documentação: type="transaction" ou type="checkout")
        if (!$payment_id || !$gateway_detected) {
            // Verificar se é webhook Beehive pelo campo 'type'
            if (isset($data['type']) && in_array($data['type'], ['transaction', 'checkout', 'transfer'])) {
                // Verificar se é Hypercash primeiro (pode ter estrutura similar)
                // Hypercash geralmente envia type="transaction" com data.id e data.status
                if (isset($data['data']['id']) && isset($data['data']['status'])) {
                    // Pode ser Hypercash ou Beehive - tentar detectar por estrutura específica
                    // Hypercash geralmente tem estrutura mais simples: {type: "transaction", data: {id, status}}
                    $gateway_detected = 'hypercash';
                    $payment_id = $data['data']['id'];
                    log_webhook("Webhook Hypercash detectado (type: " . $data['type'] . ", data.id: " . $payment_id . ")");
                } else {
                    $gateway_detected = 'beehive';
                    log_webhook("Webhook Beehive detectado (type: " . $data['type'] . ")");

                    // Para type="transaction": data.id
                    if ($data['type'] === 'transaction' && isset($data['data']['id'])) {
                        $payment_id = $data['data']['id'];
                        log_webhook("Payment ID extraído do formato Beehive transaction (data.id): " . $payment_id);
                    }
                    // Para type="checkout": data.transaction.id
                    elseif ($data['type'] === 'checkout' && isset($data['data']['transaction']['id'])) {
                        $payment_id = $data['data']['transaction']['id'];
                        log_webhook("Payment ID extraído do formato Beehive checkout (data.transaction.id): " . $payment_id);
                    }
                    // Fallback: tentar data.id ou data.transaction.id
                    elseif (isset($data['data']['id'])) {
                        $payment_id = $data['data']['id'];
                        log_webhook("Payment ID extraído do formato Beehive (data.id): " . $payment_id);
                    } elseif (isset($data['data']['transaction']['id'])) {
                        $payment_id = $data['data']['transaction']['id'];
                        log_webhook("Payment ID extraído do formato Beehive (data.transaction.id): " . $payment_id);
                    }
                }
            }
            // Fallback: tentar detectar por estrutura (sem type)
            elseif (isset($data['transaction']['id']) && !$gateway_detected) {
                $payment_id = $data['transaction']['id'];
                $gateway_detected = 'beehive';
                log_webhook("Payment ID extraído do formato Beehive (transaction.id): " . $payment_id);
            } elseif (isset($data['id']) && !$gateway_detected) {
                // Pode ser Hypercash se tiver estrutura simples {id, status}
                if (isset($data['status'])) {
                    $gateway_detected = 'hypercash';
                    log_webhook("Webhook Hypercash detectado (id + status): " . $data['id']);
                } else {
                    $gateway_detected = 'beehive';
                }
                $payment_id = $data['id'];
                log_webhook("Payment ID extraído (id): " . $payment_id);
            }
        }

        // Formato Asaas - event e payment.id ou data.id 
        if (!$payment_id || !$gateway_detected) {
            if (isset($data['event']) && isset($data['payment']['id'])) {
                $payment_id = $data['payment']['id'];
                $gateway_detected = 'asaas';
                log_webhook("Payment ID extraído do formato Asaas (payment.id): " . $payment_id);
            } elseif (isset($data['event']) && isset($data['data']['id'])) {
                $payment_id = $data['data']['id'];
                $gateway_detected = 'asaas';
                log_webhook("Payment ID extraído do formato Asaas (data.id): " . $payment_id);
            }
        }

        // Formato SpacePag - event order.* e transaction_id (antes de Applyfy para não confundir com transactionId)
        if (!$payment_id || !$gateway_detected) {
            if (isset($data['event']) && in_array($data['event'], ['order.created', 'order.paid', 'order.cancelled', 'order.med']) && isset($data['transaction_id'])) {
                $payment_id = $data['transaction_id'];
                $gateway_detected = 'spacepag';
                log_webhook("Payment ID extraído do formato SpacePag (transaction_id): " . $payment_id);
            }
        }

        // Formato Stripe
        if (!$payment_id || !$gateway_detected) {
            if (isset($data['type']) && isset($data['data']['object']['id'])) {
                $evt_type = $data['type'];
                // Verifica se é um evento do Stripe
                if (
                    strpos($evt_type, 'checkout.session') === 0 ||
                    strpos($evt_type, 'payment_intent') === 0 ||
                    strpos($evt_type, 'invoice') === 0 ||
                    strpos($evt_type, 'charge') === 0
                ) {

                    $payment_id = $data['data']['object']['id'];
                    $gateway_detected = 'stripe';
                    log_webhook("Payment ID extraído do formato Stripe ($evt_type): " . $payment_id);
                }
            }
        }

        // Formato Applyfy - transactionId
        if (!$payment_id || !$gateway_detected) {
            if (isset($data['transactionId'])) {
                $payment_id = $data['transactionId'];
                $gateway_detected = 'applyfy';
                log_webhook("Payment ID extraído do formato Applyfy (transactionId): " . $payment_id);
            } elseif (isset($data['transaction']['id'])) {
                $payment_id = $data['transaction']['id'];
                $gateway_detected = 'applyfy';
                log_webhook("Payment ID extraído do formato Applyfy (transaction.id): " . $payment_id);
            }
        }

        // Tenta extrair de resource (Mercado Pago - formato alternativo)
        $resource = $data['resource'] ?? null;
        if (!$payment_id && $resource) {
            // Resource pode ser uma URL como "/v1/payments/123456789" ou apenas o ID
            if (preg_match('/\/payments\/(\d+)/', $resource, $matches)) {
                $payment_id = $matches[1];
                log_webhook("Payment ID extraído de resource (URL): " . $payment_id);
            } else {
                $payment_id = preg_replace('/[^0-9]/', '', $resource); // Remove tudo exceto números
                if (!empty($payment_id)) {
                    log_webhook("Payment ID extraído de resource (limpo): " . $payment_id);
                }
            }
        }

        log_webhook("Payment ID final extraído: " . ($payment_id ?: 'NÃO ENCONTRADO'));

        if ($payment_id) {
            log_webhook("Buscando venda com transacao_id: " . $payment_id);

            // NOVO: Verificar se é pagamento de plano SaaS ANTES de buscar venda
            // Planos SaaS não criam vendas, apenas assinaturas
            if (file_exists(__DIR__ . '/saas/includes/saas_functions.php')) {
                require_once __DIR__ . '/saas/includes/saas_functions.php';

                if (function_exists('saas_enabled') && saas_enabled()) {
                    $stmt_plano = $pdo->prepare("
                        SELECT sa.*, sp.periodo, sp.nome as plano_nome
                        FROM saas_assinaturas sa
                        JOIN saas_planos sp ON sa.plano_id = sp.id
                        WHERE sa.transacao_id = ?
                        LIMIT 1
                    ");
                    $stmt_plano->execute([$payment_id]);
                    $assinatura_plano = $stmt_plano->fetch(PDO::FETCH_ASSOC);

                    if ($assinatura_plano) {
                        log_webhook("SaaS: Pagamento de plano detectado! Assinatura ID: {$assinatura_plano['id']}, Plano: {$assinatura_plano['plano_nome']}");

                        // Determinar status do pagamento
                        $status_aprovado = false;
                        if (isset($data['status'])) {
                            $status_raw = strtolower($data['status']);
                            $status_aprovado = in_array($status_raw, ['approved', 'paid', 'completed']);
                        } elseif (isset($data['data']['status'])) {
                            $status_raw = strtolower($data['data']['status']);
                            $status_aprovado = in_array($status_raw, ['approved', 'paid', 'completed']);
                        }

                        if ($status_aprovado && $assinatura_plano['status'] !== 'ativo') {
                            log_webhook("SaaS: Pagamento aprovado! Ativando assinatura...");

                            // IMPORTANTE: Desativar todas as outras assinaturas do usuário antes de ativar a nova
                            $stmt_desativar = $pdo->prepare("
                                UPDATE saas_assinaturas 
                                SET status = 'expirado' 
                                WHERE usuario_id = ? 
                                AND id != ? 
                                AND status = 'ativo'
                            ");
                            $stmt_desativar->execute([$assinatura_plano['usuario_id'], $assinatura_plano['id']]);

                            // Atualiza assinatura para ativo e renova vencimento
                            $periodo_dias = $assinatura_plano['periodo'] === 'anual' ? 365 : 30;
                            $novo_vencimento = date('Y-m-d', strtotime("+{$periodo_dias} days"));

                            $stmt_update = $pdo->prepare("
                                UPDATE saas_assinaturas 
                                SET status = 'ativo', 
                                    data_vencimento = ?,
                                    notificado_vencimento = 0,
                                    notificado_expirado = 0
                                WHERE transacao_id = ?
                            ");
                            $stmt_update->execute([$novo_vencimento, $payment_id]);

                            log_webhook("SaaS: Assinatura #{$assinatura_plano['id']} ativada para usuário #{$assinatura_plano['usuario_id']}. Vencimento: {$novo_vencimento}");

                            // Cria notificação
                            if (function_exists('create_notification')) {
                                create_notification(
                                    $assinatura_plano['usuario_id'],
                                    'Plano Ativado',
                                    "Seu plano '{$assinatura_plano['plano_nome']}' foi ativado com sucesso!",
                                    $assinatura_plano['plano_id'],
                                    null,
                                    null
                                );
                            }

                            // Retornar sucesso e sair (não processar como venda)
                            ob_clean();
                            http_response_code(200);
                            header('Content-Type: application/json');
                            echo json_encode(['status' => 'success', 'message' => 'Plano SaaS ativado']);
                            exit;
                        } else {
                            log_webhook("SaaS: Pagamento ainda não aprovado ou assinatura já está ativa. Status atual: {$assinatura_plano['status']}");
                        }
                    }
                }
            }

            $stmt = $pdo->prepare("SELECT v.*, p.usuario_id, u.mp_access_token, u.pushinpay_token, u.efi_client_id, u.efi_client_secret, u.efi_certificate_path, u.beehive_secret_key, u.hypercash_secret_key, u.applyfy_public_key, u.applyfy_secret_key, u.spacepag_public_key, u.spacepag_secret_key, u.stripe_webhook_secret FROM vendas v JOIN produtos p ON v.produto_id = p.id LEFT JOIN usuarios u ON p.usuario_id = u.id WHERE v.transacao_id = ? LIMIT 1");
            $stmt->execute([$payment_id]);
            $venda = $stmt->fetch(PDO::FETCH_ASSOC);

            // Validação de assinatura de webhook (se gateway suporta)
            if ($venda && $gateway_detected) {
                $secret_key = null;
                switch ($gateway_detected) {
                    case 'mercadopago':
                        $secret_key = $venda['mp_access_token'] ?? null;
                        break;
                    case 'pushinpay':
                        $secret_key = $venda['pushinpay_token'] ?? null;
                        break;
                    case 'beehive':
                        $secret_key = $venda['beehive_secret_key'] ?? null;
                        break;
                    case 'hypercash':
                        $secret_key = $venda['hypercash_secret_key'] ?? null;
                        break;
                    case 'efi':
                    case 'efi_card':
                        // Efí valida via IP/certificado, não via assinatura HMAC
                        break;
                    case 'applyfy':
                        // Applyfy pode validar via secret_key se necessário
                        $secret_key = $venda['applyfy_secret_key'] ?? null;
                        break;
                    case 'spacepag':
                        $secret_key = $venda['spacepag_secret_key'] ?? null;
                        break;
                    case 'stripe':
                        $secret_key = $venda['stripe_webhook_secret'] ?? null;
                        break;
                }

                // Validar assinatura se gateway suporta e chave está disponível
                if ($secret_key && function_exists('validate_webhook_signature')) {
                    $headers = [];
                    foreach ($_SERVER as $key => $value) {
                        if (strpos($key, 'HTTP_') === 0) {
                            $header_name = str_replace('_', '-', substr($key, 5));
                            $headers[$header_name] = $value;
                            $headers['HTTP_' . strtoupper(str_replace('-', '_', $header_name))] = $value;
                        }
                    }

                    $is_valid = validate_webhook_signature($gateway_detected, $headers, $data, $secret_key, $input);
                    if (!$is_valid) {
                        log_security_event('webhook_invalid_signature', [
                            'gateway' => $gateway_detected,
                            'payment_id' => $payment_id,
                            'ip' => $client_ip
                        ]);
                        log_webhook("ERRO: Assinatura de webhook inválida para gateway: " . $gateway_detected);
                        // Não processa webhook com assinatura inválida
                        return;
                    } else {
                        log_webhook("Assinatura de webhook validada com sucesso para gateway: " . $gateway_detected);
                    }
                }
            }

            if (!$venda) {
                log_webhook("ERRO: Venda não encontrada para transacao_id: " . $payment_id);
            } else {
                log_webhook("Venda encontrada. ID: " . $venda['id'] . ", Status atual: " . $venda['status_pagamento'] . ", Email enviado: " . ($venda['email_entrega_enviado'] ?? 0));
            }

            if ($venda) {
                log_webhook("Venda encontrada no BD. Transacao ID: " . $payment_id . ", Status atual: " . $venda['status_pagamento']);

                // Tenta extrair status do webhook (diferentes formatos)
                $new_status = null;

                // Formato Mercado Pago
                // O webhook do Mercado Pago NÃO envia o status, apenas o ID do pagamento
                // Precisamos buscar da API
                if (isset($data['type']) && $data['type'] === 'payment') {
                    // Webhook do Mercado Pago - não tem status, precisa buscar da API
                    log_webhook("Webhook do Mercado Pago detectado (type=payment). Status será buscado da API.");
                    $new_status = null; // Será buscado da API abaixo
                } elseif (isset($data['action']) && $data['action'] === 'payment.updated') {
                    // Formato alternativo do Mercado Pago
                    $new_status = $data['data']['status'] ?? null;
                } elseif (isset($data['status'])) {
                    $new_status = $data['status'];
                }

                // Formato PushinPay
                if (!$new_status) {
                    if (isset($data['transaction']['status'])) {
                        $new_status = $data['transaction']['status'];
                    } elseif (isset($data['status'])) {
                        $new_status = $data['status'];
                    } elseif (isset($data['event']) && $data['event'] === 'payment.paid') {
                        $new_status = 'paid';
                    }
                }

                // Formato Efí Cartão - webhook indica status da cobrança
                if (!$new_status && $gateway_detected === 'efi_card') {
                    // Efí Cartão envia status no campo data.status ou status
                    if (isset($data['data']['status'])) {
                        $new_status = $data['data']['status'];
                        log_webhook("Status extraído do webhook Efí Cartão (data.status): " . $new_status);
                    } elseif (isset($data['status'])) {
                        $new_status = $data['status'];
                        log_webhook("Status extraído do webhook Efí Cartão (status): " . $new_status);
                    }

                    // IMPORTANTE: A API Efí retorna recusa em data.refusal.reason
                    // Se status é unpaid/waiting e existe refusal.reason, tratar como rejected
                    $refusal_reason = '';
                    if (isset($data['data']['refusal']['reason'])) {
                        $refusal_reason = $data['data']['refusal']['reason'];
                        log_webhook("Efí Cartão: refusal.reason encontrado: " . $refusal_reason);
                    } elseif (isset($data['refusal']['reason'])) {
                        $refusal_reason = $data['refusal']['reason'];
                        log_webhook("Efí Cartão: refusal.reason encontrado (raiz): " . $refusal_reason);
                    }

                    // Se status é unpaid/waiting e existe refusal.reason, normalizar para rejected
                    if (($new_status === 'unpaid' || $new_status === 'waiting') && !empty($refusal_reason)) {
                        $new_status = 'rejected';
                        log_webhook("Efí Cartão: Status normalizado para 'rejected' devido a refusal.reason: " . $refusal_reason);
                    }
                }

                // Formato Efí Pix - webhook indica pagamento recebido
                // IMPORTANTE: Verificar tanto se gateway_detected é 'efi' quanto se tem txid (pode vir sem gateway detectado)
                if (!$new_status && ($gateway_detected === 'efi' || isset($data['txid']) || isset($data['pix']))) {
                    // Se tem txid no webhook, significa que o pagamento foi recebido
                    if (isset($data['txid']) || isset($data['pix'])) {
                        $new_status = 'paid'; // Efí envia webhook quando pagamento é recebido
                        log_webhook("Webhook Efí detectado - pagamento recebido (txid: " . ($data['txid'] ?? 'N/A') . ") | gateway_detected: " . ($gateway_detected ?? 'não detectado'));
                        // Se gateway não foi detectado antes, detecta agora
                        if (!$gateway_detected) {
                            $gateway_detected = 'efi';
                            log_webhook("Gateway Efí detectado retroativamente via txid");
                        }
                    }
                    // Também verifica se o status vem explicitamente no webhook
                    if (isset($data['status'])) {
                        $efi_status = strtolower($data['status']);
                        if (in_array($efi_status, ['paid', 'approved', 'completed', 'liquidado', 'liquidated'])) {
                            $new_status = 'paid';
                            log_webhook("Webhook Efí - status explícito: " . $data['status'] . " -> normalizado para 'paid'");
                        }

                    }
                    // Verifica também em data.status (formato alternativo)
                    if (!$new_status && isset($data['data']['status'])) {
                        $efi_status = strtolower($data['data']['status']);
                        if (in_array($efi_status, ['paid', 'approved', 'completed', 'liquidado', 'liquidated'])) {
                            $new_status = 'paid';
                            log_webhook("Webhook Efí - status em data.status: " . $data['data']['status'] . " -> normalizado para 'paid'");
                        }
                    }
                }

                // Formato Beehive - webhook indica status da transação
                // Conforme documentação: type="transaction" -> data.status, type="checkout" -> data.transaction.status
                if (!$new_status && $gateway_detected === 'beehive') {
                    // Para type="transaction": data.status
                    if (isset($data['type']) && $data['type'] === 'transaction' && isset($data['data']['status'])) {
                        $new_status = $data['data']['status'];
                        log_webhook("Status extraído do webhook Beehive transaction (data.status): " . $new_status);
                    }
                    // Para type="checkout": data.transaction.status
                    elseif (isset($data['type']) && $data['type'] === 'checkout' && isset($data['data']['transaction']['status'])) {
                        $new_status = $data['data']['transaction']['status'];
                        log_webhook("Status extraído do webhook Beehive checkout (data.transaction.status): " . $new_status);
                    }
                    // Fallback: tentar data.status ou data.transaction.status
                    elseif (isset($data['data']['status'])) {
                        $new_status = $data['data']['status'];
                        log_webhook("Status extraído do webhook Beehive (data.status): " . $new_status);
                    } elseif (isset($data['data']['transaction']['status'])) {
                        $new_status = $data['data']['transaction']['status'];
                        log_webhook("Status extraído do webhook Beehive (data.transaction.status): " . $new_status);
                    }
                    // Fallback: formato antigo (sem data wrapper)
                    elseif (isset($data['transaction']['status'])) {
                        $new_status = $data['transaction']['status'];
                        log_webhook("Status extraído do webhook Beehive (transaction.status): " . $new_status);
                    } elseif (isset($data['status'])) {
                        $new_status = $data['status'];
                        log_webhook("Status extraído do webhook Beehive (status): " . $new_status);
                    }

                    // Normalizar status da Beehive para o padrão interno
                    if ($new_status) {
                        $status_lower = strtolower($new_status);
                        if ($status_lower === 'paid') {
                            $new_status = 'approved';
                        } elseif (in_array($status_lower, ['refused', 'rejected', 'failed'])) {
                            $new_status = 'rejected';
                        } elseif (in_array($status_lower, ['pending', 'processing', 'authorized'])) {
                            $new_status = 'pending';
                        }
                        log_webhook("Status Beehive normalizado: " . $new_status);
                    }
                }

                // Formato Hypercash - webhook indica status da transação
                // Conforme documentação: type="transaction" -> data.status, ou simplesmente {id, status}
                if (!$new_status && $gateway_detected === 'hypercash') {
                    // Para type="transaction": data.status
                    if (isset($data['type']) && $data['type'] === 'transaction' && isset($data['data']['status'])) {
                        $new_status = $data['data']['status'];
                        log_webhook("Status extraído do webhook Hypercash transaction (data.status): " . $new_status);
                    }
                    // Fallback: tentar data.status
                    elseif (isset($data['data']['status'])) {
                        $new_status = $data['data']['status'];
                        log_webhook("Status extraído do webhook Hypercash (data.status): " . $new_status);
                    }
                    // Fallback: formato simples {status}
                    elseif (isset($data['status'])) {
                        $new_status = $data['status'];
                        log_webhook("Status extraído do webhook Hypercash (status): " . $new_status);
                    }

                    // Normalizar status do Hypercash para o padrão interno
                    if ($new_status) {
                        $status_upper = strtoupper($new_status);
                        if (in_array($status_upper, ['PAID', 'AUTHORIZED'])) {
                            $new_status = 'approved';
                        } elseif (in_array($status_upper, ['REFUSED', 'CANCELED'])) {
                            $new_status = 'rejected';
                        } elseif (in_array($status_upper, ['PROCESSING', 'WAITING_PAYMENT', 'IN_ANALYSIS'])) {
                            $new_status = 'pending';
                        }
                        log_webhook("Status Hypercash normalizado: " . $new_status);
                    }
                }

                // Formato Efí Cartão - normalizar status
                // NOTA: A normalização para 'rejected' já foi feita acima se existe refusal.reason
                if ($new_status && $gateway_detected === 'efi_card') {
                    $status_lower = strtolower($new_status);
                    if ($status_lower === 'paid') {
                        $new_status = 'approved';
                    } elseif ($status_lower === 'rejected') {
                        // Já está normalizado como rejected (devido a refusal.reason)
                        $new_status = 'rejected';
                    } elseif (in_array($status_lower, ['unpaid', 'waiting'])) {
                        // Se não foi normalizado para rejected acima, manter como pending
                        $new_status = 'pending';
                    } elseif (in_array($status_lower, ['canceled', 'expired'])) {
                        $new_status = 'rejected';
                    } elseif ($status_lower === 'refunded') {
                        $new_status = 'refunded';
                    }
                    log_webhook("Status Efí Cartão normalizado: " . $new_status);
                }

                // Formato Asaas - extrair e normalizar status
                if (!$new_status && $gateway_detected === 'asaas') {
                    if (isset($data['payment']['status'])) {
                        $new_status = $data['payment']['status'];
                        log_webhook("Status extraído do webhook Asaas (payment.status): " . $new_status);
                    } elseif (isset($data['data']['status'])) {
                        $new_status = $data['data']['status'];
                        log_webhook("Status extraído do webhook Asaas (data.status): " . $new_status);
                    } elseif (isset($data['status'])) {
                        $new_status = $data['status'];
                        log_webhook("Status extraído do webhook Asaas (status): " . $new_status);
                    }
                }

                // Formato Asaas - normalizar status
                if ($new_status && $gateway_detected === 'asaas') {
                    $status_lower = strtolower($new_status);
                    if (in_array($status_lower, ['confirmed', 'received'])) {
                        $new_status = 'approved';
                    } elseif ($status_lower === 'overdue') {
                        $new_status = 'rejected';
                    } elseif ($status_lower === 'refunded') {
                        $new_status = 'refunded';
                    } else {
                        $new_status = 'pending';
                    }
                    log_webhook("Status Asaas normalizado: " . $new_status);
                }

                // Formato SpacePag - extrair e normalizar status
                if (!$new_status && $gateway_detected === 'spacepag') {
                    if (isset($data['status'])) {
                        $new_status = $data['status'];
                        log_webhook("Status extraído do webhook SpacePag (status): " . $new_status);
                    }
                }
                if ($new_status && $gateway_detected === 'spacepag') {
                    $sp_status = strtolower(trim($new_status));
                    if (in_array($sp_status, ['paid', 'approved', 'completed'])) {
                        $new_status = 'approved';
                    } elseif ($sp_status === 'cancelled') {
                        $new_status = 'rejected';
                    } elseif ($sp_status === 'pending') {
                        $new_status = 'pending';
                    }
                    log_webhook("Status SpacePag normalizado: " . $new_status);
                }

                // Formato Stripe
                if (!$new_status && $gateway_detected === 'stripe') {
                    $evt_type = $data['type'] ?? '';
                    if (
                        $evt_type === 'checkout.session.completed' ||
                        $evt_type === 'payment_intent.succeeded' ||
                        $evt_type === 'invoice.payment_succeeded' ||
                        $evt_type === 'charge.succeeded'
                    ) {
                        $new_status = 'approved';
                    } elseif (
                        $evt_type === 'checkout.session.expired' ||
                        $evt_type === 'payment_intent.canceled' ||
                        $evt_type === 'charge.failed'
                    ) {
                        $new_status = 'rejected';
                    } elseif ($evt_type === 'charge.refunded') {
                        $new_status = 'refunded';
                    }

                    log_webhook("Status Stripe derivado do evento ($evt_type): " . ($new_status ?? 'não mapeado'));
                }

                // Formato Applyfy - extrair status
                if (!$new_status && $gateway_detected === 'applyfy') {
                    if (isset($data['status'])) {
                        $new_status = $data['status'];
                        log_webhook("Status extraído do webhook Applyfy (status): " . $new_status);
                    } elseif (isset($data['transaction']['status'])) {
                        $new_status = $data['transaction']['status'];
                        log_webhook("Status extraído do webhook Applyfy (transaction.status): " . $new_status);
                    }
                }

                // Formato Applyfy - normalizar status
                if ($new_status && $gateway_detected === 'applyfy') {
                    require_once __DIR__ . '/gateways/applyfy.php';
                    $status_upper = strtoupper(trim($new_status));
                    if ($status_upper === 'COMPLETED' || $status_upper === 'OK') {
                        $new_status = 'approved';
                    } elseif ($status_upper === 'PENDING') {
                        $new_status = 'pending';
                    } elseif ($status_upper === 'FAILED' || $status_upper === 'REJECTED') {
                        $new_status = 'rejected';
                    } elseif ($status_upper === 'REFUNDED') {
                        $new_status = 'refunded';
                    } elseif ($status_upper === 'CHARGED_BACK') {
                        $new_status = 'charged_back';
                    } else {
                        $new_status = 'pending';
                    }
                    log_webhook("Status Applyfy normalizado: " . $new_status . " (original: " . $status_upper . ")");
                }

                log_webhook("Status extraído do webhook: " . ($new_status ?: 'NÃO ENCONTRADO') . " | Gateway: " . ($gateway_detected ?: 'não detectado'));

                // Verifica se tem flag force_process ANTES de buscar da API
                // IMPORTANTE: Aceitar force_process como true, 1, 'true', '1' para garantir compatibilidade
                $force_process_flag = false;
                if (isset($data['force_process'])) {
                    $force_process_value = $data['force_process'];
                    $force_process_flag = ($force_process_value === true || $force_process_value === 1 || $force_process_value === 'true' || $force_process_value === '1');
                }
                if ($force_process_flag) {
                    log_webhook("Flag force_process detectada no webhook - forçando processamento completo");
                }

                // Se o webhook tem flag force_process ou status explicitamente 'approved', processa
                if ($force_process_flag) {
                    // Normalizar status para 'approved' quando force_process é true
                    // O webhook interno pode enviar 'paid', mas precisamos 'approved' para tracking
                    $status_from_webhook = $data['status'] ?? 'approved';
                    $new_status = ($status_from_webhook === 'paid' || $status_from_webhook === 'approved' || $status_from_webhook === 'completed') ? 'approved' : $status_from_webhook;
                    log_webhook("Webhook interno com force_process detectado. Status original: " . $status_from_webhook . " | Status normalizado: " . $new_status . " | Não buscará da API para evitar sobrescrever.");
                }

                // Se não veio status no webhook E não tem force_process, tenta buscar da API
                // IMPORTANTE: O webhook do Mercado Pago NÃO envia o status, sempre precisa buscar da API
                // MAS: Se tem force_process, não busca da API para não sobrescrever o status correto
                if ((!$new_status || (isset($data['type']) && $data['type'] === 'payment')) && !$force_process_flag) {
                    // Verifica se é Hypercash (se tem credenciais Hypercash e método é Cartão de crédito)
                    if (!empty($venda['hypercash_secret_key']) && stripos($venda['metodo_pagamento'], 'Cartão') !== false) {
                        log_webhook("Buscando status do Hypercash via API...");
                        require_once __DIR__ . '/gateways/hypercash.php';

                        $status_data = hypercash_get_payment_status($venda['hypercash_secret_key'], $payment_id);
                        if ($status_data && isset($status_data['status'])) {
                            $new_status = $status_data['status'];
                            log_webhook("Status obtido da API Hypercash: " . $new_status);
                        }
                    }
                    // Verifica se é Beehive (se tem credenciais Beehive e método é Cartão de crédito)
                    elseif (!empty($venda['beehive_secret_key']) && stripos($venda['metodo_pagamento'], 'Cartão') !== false) {
                        log_webhook("Buscando status do Beehive via API...");
                        require_once __DIR__ . '/gateways/beehive.php';

                        $status_data = beehive_get_payment_status($venda['beehive_secret_key'], $payment_id);
                        if ($status_data && isset($status_data['status'])) {
                            $new_status = $status_data['status'];
                            log_webhook("Status obtido da API Beehive: " . $new_status);
                        }
                    }
                    // Verifica se é Efí Cartão (se tem credenciais Efí e método é Cartão de crédito)
                    elseif (!empty($venda['efi_client_id']) && !empty($venda['efi_certificate_path']) && stripos($venda['metodo_pagamento'], 'Cartão') !== false && $gateway_detected === 'efi_card') {
                        log_webhook("Buscando status do Efí Cartão via API...");
                        require_once __DIR__ . '/../gateways/efi.php';

                        $full_cert_path = dirname(__DIR__) . '/' . $venda['efi_certificate_path'];
                        $full_cert_path = str_replace('\\', '/', $full_cert_path);
                        if (file_exists($full_cert_path)) {
                            // CORREÇÃO: Usar token da API de Cobranças, não da API Pix
                            $token_data = efi_get_charges_access_token($venda['efi_client_id'], $venda['efi_client_secret'], $full_cert_path);
                            if ($token_data) {
                                $status_data = efi_get_card_charge_status($token_data['access_token'], $payment_id, $full_cert_path);
                                if ($status_data && isset($status_data['status'])) {
                                    $new_status = $status_data['status'];
                                    log_webhook("Status obtido da API Efí Cartão: " . $new_status);
                                }
                            }
                        }
                    }
                    // Verifica se é Efí Pix (se tem credenciais Efí e método é Pix)
                    elseif (!empty($venda['efi_client_id']) && !empty($venda['efi_certificate_path']) && stripos($venda['metodo_pagamento'], 'pix') !== false) {
                        log_webhook("Buscando status do Efí via API...");
                        require_once __DIR__ . '/../gateways/efi.php';

                        $full_cert_path = dirname(__DIR__) . '/' . $venda['efi_certificate_path'];
                        $full_cert_path = str_replace('\\', '/', $full_cert_path);
                        if (file_exists($full_cert_path)) {
                            $token_data = efi_get_access_token($venda['efi_client_id'], $venda['efi_client_secret'], $full_cert_path);
                            if ($token_data) {
                                // Passar certificado para mutual TLS
                                $status_data = efi_get_payment_status($token_data['access_token'], $payment_id, $full_cert_path);
                                if ($status_data && isset($status_data['status'])) {
                                    // Se API retorna 'approved', manter como 'approved' (não converter para 'paid')
                                    // Se retorna 'paid', manter como 'paid' (será normalizado na linha 1040)
                                    $new_status = $status_data['status'];
                                    log_webhook("Efí: Status obtido da API: " . ($status_data['status'] ?? 'N/A') . " | Usado como: " . ($new_status ?? 'N/A'));
                                }
                            }
                        }
                    }
                    // Verifica se é PushinPay (se tem pushinpay_token e método é Pix)
                    elseif (!empty($venda['pushinpay_token']) && stripos($venda['metodo_pagamento'], 'pix') !== false) {
                        log_webhook("Buscando status do PushinPay via API...");
                        // Tenta diferentes endpoints
                        $endpoints = [
                            'https://api.pushinpay.com.br/api/transactions/' . $payment_id,
                            'https://api.pushinpay.com.br/api/pix/transactions/' . $payment_id,
                            'https://api.pushinpay.com.br/api/pix/' . $payment_id
                        ];

                        foreach ($endpoints as $endpoint) {
                            $ch = curl_init($endpoint);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                                'Authorization: Bearer ' . $venda['pushinpay_token'],
                                'Accept: application/json'
                            ]);
                            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                            $pp_res = json_decode(curl_exec($ch), true);
                            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                            curl_close($ch);

                            if ($http_code >= 200 && $http_code < 300 && isset($pp_res['status'])) {
                                $new_status = $pp_res['status'];
                                log_webhook("Status obtido da API PushinPay: " . $new_status);
                                break;
                            }
                        }
                    } elseif ($venda['mp_access_token']) {
                        log_webhook("Buscando status do Mercado Pago via API (webhook não envia status)...");
                        // Busca status do Mercado Pago - SEMPRE necessário pois webhook não envia status
                        $ch = curl_init("https://api.mercadopago.com/v1/payments/" . $payment_id);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $venda['mp_access_token']]);
                        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
                        $mp_res = json_decode(curl_exec($ch), true);
                        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        curl_close($ch);

                        if ($http_code == 200 && isset($mp_res['status'])) {
                            $new_status = $mp_res['status'];
                            log_webhook("Status obtido da API Mercado Pago: " . $new_status);
                        } else {
                            log_webhook("ERRO ao buscar status do Mercado Pago. HTTP Code: " . $http_code . ", Resposta: " . substr(json_encode($mp_res), 0, 200));
                        }
                    }
                }

                // Se não veio status no webhook mas a venda já está aprovada, força processamento de entrega
                $should_process_delivery = false;

                // Processa se houver novo status OU se precisar processar entrega OU se tiver force_process
                // IMPORTANTE: Também processa se o status atual é 'pending' e o novo é 'approved' (PIX aprovado)
                $status_mudou_para_approved = false;
                $webhook_veio_com_status_aprovado = false;

                // IMPORTANTE: NÃO forçar processamento quando status já está 'approved' no BD
                // Gateways PIX (SpacePag, PushinPay, etc) podem enviar webhook 2-3 vezes
                // Se processarmos toda vez, duplicamos notificações PWA e webhooks externos
                // Só processamos eventos (webhook, push, create_notification) quando status REALMENTE mudou

                // CORREÇÃO: Se o status no BD já está 'approved', definir $db_status imediatamente
                // Isso garante que mesmo se o webhook não trouxer status explícito, o processamento ocorra
                if ($venda['status_pagamento'] === 'approved') {
                    $db_status = 'approved';
                    log_webhook("Status no BD já está 'approved' - definindo db_status = 'approved' para garantir processamento");
                }

                if (!$new_status && $venda['status_pagamento'] === 'approved' && ($venda['email_entrega_enviado'] ?? 0) == 0) {
                    log_webhook("Status não veio no webhook, mas venda já está aprovada e email não foi enviado. Forçando processamento de entrega.");
                    $db_status = 'approved';
                    $new_status = 'paid'; // Força processamento
                    $should_process_delivery = true;
                }

                if ($new_status) {
                    $new_status_original = $new_status; // Guardar original para logs
                    $new_status = strtolower($new_status);
                    $db_status = ($new_status === 'paid' || $new_status === 'completed' || $new_status === 'approved') ? 'approved' : $new_status;

                    // Verifica se webhook veio com status aprovado (importante para Efí)
                    if ($db_status === 'approved') {
                        $webhook_veio_com_status_aprovado = true;
                        log_webhook("Webhook veio com status aprovado - new_status original: '{$new_status_original}' | normalizado para: '{$db_status}' | gateway: " . ($gateway_detected ?? 'não detectado'));
                    }

                    // Verifica se está mudando de pending para approved (PIX aprovado)
                    // IMPORTANTE: SÓ marcar como mudou quando REALMENTE há transição de não-approved para approved
                    // Se já estava approved, NÃO processar novamente (evita duplicação de webhooks/PWA)
                    if ($venda['status_pagamento'] !== 'approved' && $db_status === 'approved') {
                        $status_mudou_para_approved = true;
                        log_webhook("Status mudou de '" . $venda['status_pagamento'] . "' para 'approved' - Pagamento aprovado! Processando eventos.");
                    } elseif ($venda['status_pagamento'] === 'approved' && $db_status === 'approved') {
                        // Status já estava approved - webhook duplicado do gateway, NÃO reprocessar eventos
                        log_webhook("Status já estava 'approved' - webhook duplicado ignorado para evitar notificações repetidas | gateway: " . ($gateway_detected ?? 'não detectado'));
                    }
                } else {
                    // CORREÇÃO: Se não veio new_status mas o status no BD é 'approved', garantir que db_status seja 'approved'
                    // Também verifica se webhook chegou (indicando que o gateway notificou)
                    if ($venda['status_pagamento'] === 'approved') {
                        $db_status = 'approved';
                        // Se webhook chegou (tem dados), marcar que veio com status aprovado
                        $raw_input = @file_get_contents('php://input');
                        $webhook_arrived = !empty($_POST) || !empty($_GET) || !empty($raw_input);
                        if ($webhook_arrived) {
                            $webhook_veio_com_status_aprovado = true;
                            $status_mudou_para_approved = true; // Forçar processamento mesmo que status não tenha mudado
                            log_webhook("Webhook chegou mas não trouxe status explícito - status no BD é 'approved', forçando processamento de webhooks/UTMfy/Meta Ads");
                        }
                    } else {
                        $db_status = 'approved'; // Fallback padrão
                    }
                }

                // Se tiver force_process, força processamento mesmo que status já esteja atualizado
                if ($force_process_flag) {
                    // Garante que db_status seja 'approved' quando force_process é true
                    $db_status = 'approved';
                    // Se o status já estava 'approved', ainda assim força processamento (para UTMfy e entrega)
                    if ($venda['status_pagamento'] === 'approved') {
                        $status_mudou_para_approved = true;
                        log_webhook("Flag force_process detectada - status já estava 'approved', mas forçando processamento de entrega e UTMfy");
                    } else {
                        $status_mudou_para_approved = true;
                        log_webhook("Flag force_process detectada - forçando processamento de entrega e UTMfy | Status anterior: " . $venda['status_pagamento']);
                    }
                }

                // Só processar quando: status mudou, force_process, precisa processar entrega, ou tem novo status
                // Não forçar quando status já está approved (evita duplicação de webhooks/PWA)
                $should_process = $new_status || $should_process_delivery || $status_mudou_para_approved || $force_process_flag;

                log_webhook("Verificando se deve processar: should_process=" . ($should_process ? 'true' : 'false') . " | new_status=" . ($new_status ?: 'null') . " | should_process_delivery=" . ($should_process_delivery ? 'true' : 'false') . " | status_mudou_para_approved=" . ($status_mudou_para_approved ? 'true' : 'false') . " | force_process_flag=" . ($force_process_flag ? 'true' : 'false') . " | db_status=" . $db_status . " | webhook_veio_com_status_aprovado=" . ($webhook_veio_com_status_aprovado ? 'true' : 'false') . " | gateway: " . ($gateway_detected ?? 'não detectado'));

                // CORREÇÃO CRÍTICA: Adicionar log ANTES do bloco if ($should_process) para debug
                log_webhook("DEBUG CRÍTICO: ANTES do bloco if (\$should_process) - should_process: " . ($should_process ? 'true' : 'false') . " | db_status: " . $db_status . " | Entrando no bloco? " . ($should_process ? 'SIM' : 'NÃO') . " | payment_id: " . ($payment_id ?? 'NULL'));

                if ($should_process) {
                    log_webhook("DEBUG CRÍTICO: DENTRO do bloco if (\$should_process) - Processando webhook e UTMfy...");
                    log_webhook("Status normalizado para BD: " . $db_status . " | Condição satisfeita: new_status=" . ($new_status ?: 'null') . " | should_process=" . ($should_process_delivery ? 'true' : 'false') . " | mudou_approved=" . ($status_mudou_para_approved ? 'true' : 'false') . " | force_process=" . ($force_process_flag ? 'true' : 'false'));

                    // Atualiza status apenas se mudou
                    if ($venda['status_pagamento'] !== $db_status) {
                        // IMPORTANTE: Atualiza TODAS as vendas relacionadas pelo checkout_session_uuid
                        // Isso garante que order bumps e produtos relacionados também sejam atualizados
                        if (!empty($venda['checkout_session_uuid'])) {
                            $stmt_update_all = $pdo->prepare("UPDATE vendas SET status_pagamento = ? WHERE checkout_session_uuid = ?");
                            $stmt_update_all->execute([$db_status, $venda['checkout_session_uuid']]);
                            $affected_rows = $stmt_update_all->rowCount();
                            log_webhook("Status atualizado no BD de '" . $venda['status_pagamento'] . "' para '" . $db_status . "' em " . $affected_rows . " venda(s) relacionada(s) pelo checkout_session_uuid");
                        } else {
                            // Fallback: se não tiver checkout_session_uuid, atualiza apenas pela transacao_id
                            $pdo->prepare("UPDATE vendas SET status_pagamento = ? WHERE transacao_id = ?")->execute([$db_status, $payment_id]);
                            log_webhook("Status atualizado no BD de '" . $venda['status_pagamento'] . "' para '" . $db_status . "' (fallback: apenas transacao_id)");
                        }

                        // Buscar dados atualizados da venda após atualizar status
                        $stmt_updated = $pdo->prepare("SELECT email_entrega_enviado FROM vendas WHERE transacao_id = ? LIMIT 1");
                        $stmt_updated->execute([$payment_id]);
                        $venda_updated = $stmt_updated->fetch(PDO::FETCH_ASSOC);
                        if ($venda_updated) {
                            $venda['email_entrega_enviado'] = $venda_updated['email_entrega_enviado'];
                            log_webhook("Dados atualizados da venda - email_entrega_enviado: " . $venda['email_entrega_enviado']);
                        }
                    } else {
                        log_webhook("Status já está como '" . $db_status . "' no BD, não precisa atualizar");
                        // IMPORTANTE: Mesmo que o status já esteja atualizado, se force_process é true, deve processar UTMfy e tracking
                        if ($force_process_flag && $db_status === 'approved') {
                            log_webhook("Status já estava 'approved', mas force_process é true - processando UTMfy e tracking mesmo assim");
                        }
                    }

                    // Buscar todas as vendas relacionadas pelo checkout_session_uuid (inclui order bumps)
                    // IMPORTANTE: Sempre buscar vendas para garantir que tracking seja disparado mesmo quando status já está approved
                    if (!empty($venda['checkout_session_uuid'])) {
                        $stmt_all = $pdo->prepare("
                            SELECT v.*, p.usuario_id, p.nome as produto_nome, p.tipo_entrega, p.conteudo_entrega, p.checkout_config, p.checkout_hash 
                            FROM vendas v 
                            JOIN produtos p ON v.produto_id = p.id 
                            WHERE v.checkout_session_uuid = ?
                        ");
                        $stmt_all->execute([$venda['checkout_session_uuid']]);
                    } else {
                        // Fallback: buscar apenas pela transacao_id se não tiver checkout_session_uuid
                        $stmt_all = $pdo->prepare("
                            SELECT v.*, p.usuario_id, p.nome as produto_nome, p.tipo_entrega, p.conteudo_entrega, p.checkout_config, p.checkout_hash 
                            FROM vendas v 
                            JOIN produtos p ON v.produto_id = p.id 
                            WHERE v.transacao_id = ?
                        ");
                        $stmt_all->execute([$payment_id]);
                    }
                    $all_sales = $stmt_all->fetchAll(PDO::FETCH_ASSOC);

                    log_webhook("DEBUG: Total de vendas encontradas: " . count($all_sales) . " | Entrando no bloco de processamento de webhook e UTMfy");

                    if (!empty($all_sales)) {
                        log_webhook("DEBUG: Vendas encontradas, processando webhook e UTMfy...");
                        $main_sale = $all_sales[0];
                        $config = json_decode($main_sale['checkout_config'] ?? '{}', true);

                        // Calcular valor total incluindo order bumps
                        $valor_total_compra = array_sum(array_column($all_sales, 'valor'));

                        // Preparar sale_details com valor total correto para Meta Ads
                        $sale_details_for_tracking = $main_sale;
                        $sale_details_for_tracking['valor'] = $valor_total_compra;
                        $sale_details_for_tracking['valor_total_compra'] = $valor_total_compra;

                        // CORREÇÃO: Só disparar webhooks, PWA push e create_notification quando status REALMENTE mudou
                        // Evita duplicação quando: 1) Gateway envia webhook 2-3x (SpacePag, PushinPay)
                        // 2) check_status (polling) e webhook detectam ao mesmo tempo
                        // Quando force_process: check_status já disparou eventos, só processar entrega
                        $deve_disparar_eventos = $status_mudou_para_approved && !$force_process_flag;

                        if ($deve_disparar_eventos) {
                            // Meta Ads - só quando transição real para approved
                            $tracking_status = ($db_status === 'approved' || $db_status === 'paid' || $db_status === 'completed') ? 'approved' : $db_status;
                            if ($db_status === 'approved' || $db_status === 'paid' || $db_status === 'completed') {
                                log_webhook("Disparando handle_tracking_events (Meta Ads) - status mudou para approved");
                                try {
                                    handle_tracking_events($tracking_status, $sale_details_for_tracking, $config);
                                } catch (Exception $e) {
                                    log_webhook("ERRO em handle_tracking_events (Meta Ads): " . $e->getMessage());
                                }
                            }

                            // Função centralizada: webhooks, UTMfy, PWA push
                            $gateway_name_for_log = !empty($gateway_detected) ? ucfirst($gateway_detected) : 'Gateway';
                            if ($gateway_detected === 'efi')
                                $gateway_name_for_log = 'Efí Pix';
                            elseif ($gateway_detected === 'efi_card')
                                $gateway_name_for_log = 'Efí Cartão';

                            $status_for_dispatch = in_array($db_status, ['paid', 'completed']) ? 'approved' : $db_status;

                            if (function_exists('dispatch_payment_events')) {
                                try {
                                    $custom_event_data = $main_sale;
                                    $custom_event_data['produto_nome'] = $main_sale['produto_nome'] ?? 'Produto';
                                    $custom_event_data['valor_total_compra'] = array_sum(array_column($all_sales, 'valor'));
                                    dispatch_payment_events($pdo, $payment_id, $status_for_dispatch, $gateway_name_for_log, $custom_event_data);
                                    log_webhook("Eventos disparados (webhook, PWA, UTMfy) - status mudou para approved");
                                } catch (Exception $e) {
                                    log_webhook("ERRO dispatch_payment_events: " . $e->getMessage());
                                }
                            }

                            // Notificação no dashboard
                            $msg = ($db_status == 'approved') ? "Venda Aprovada! R$ " . number_format($valor_total_compra, 2, ',', '.') : "Venda atualizada: " . ucfirst($db_status);
                            if ($db_status == 'pix_created' || ($db_status == 'pending' && stripos($main_sale['metodo_pagamento'], 'pix') !== false))
                                $msg = "Pix Gerado. Aguardando.";
                            create_notification($main_sale['usuario_id'], ($db_status == 'approved' ? 'Compra Aprovada' : 'Atualização'), $msg, $valor_total_compra, $main_sale['id'], $main_sale['metodo_pagamento']);
                        } else {
                            log_webhook("Eventos NÃO disparados - status já estava approved ou force_process (evita duplicação)");
                        }

                        // Processa webhook de planos SaaS (se for pagamento de plano)
                        if (plugin_active('saas')) {
                            $stmt_plano = $pdo->prepare("
                                SELECT sa.*, sp.periodo 
                                FROM saas_assinaturas sa
                                JOIN saas_planos sp ON sa.plano_id = sp.id
                                WHERE sa.transacao_id = ?
                            ");
                            $stmt_plano->execute([$payment_id]);
                            $assinatura_plano = $stmt_plano->fetch(PDO::FETCH_ASSOC);

                            if ($assinatura_plano) {
                                if ($db_status === 'approved' || $db_status === 'paid') {
                                    // IMPORTANTE: Desativar todas as outras assinaturas do usuário antes de ativar a nova
                                    $stmt_desativar = $pdo->prepare("
                                        UPDATE saas_assinaturas 
                                        SET status = 'expirado' 
                                        WHERE usuario_id = ? 
                                        AND id != ? 
                                        AND status = 'ativo'
                                    ");
                                    $stmt_desativar->execute([$assinatura_plano['usuario_id'], $assinatura_plano['id']]);

                                    // Atualiza assinatura para ativo e renova vencimento
                                    $periodo_dias = $assinatura_plano['periodo'] === 'anual' ? 365 : 30;
                                    $novo_vencimento = date('Y-m-d H:i:s', strtotime("+{$periodo_dias} days"));

                                    $stmt_update = $pdo->prepare("
                                        UPDATE saas_assinaturas 
                                        SET status = 'ativo', data_vencimento = ?, notificado_vencimento = 0, notificado_expirado = 0
                                        WHERE transacao_id = ?
                                    ");
                                    $stmt_update->execute([$novo_vencimento, $payment_id]);

                                    // Cria notificação
                                    $stmt_plano_info = $pdo->prepare("SELECT nome FROM saas_planos WHERE id = ?");
                                    $stmt_plano_info->execute([$assinatura_plano['plano_id']]);
                                    $plano_info = $stmt_plano_info->fetch(PDO::FETCH_ASSOC);

                                    create_notification(
                                        $assinatura_plano['usuario_id'],
                                        'Plano Ativado',
                                        "Seu plano '{$plano_info['nome']}' foi ativado com sucesso!",
                                        $assinatura_plano['plano_id'],
                                        null,
                                        null
                                    );
                                }
                            }
                        }

                        if ($db_status === 'approved') {
                            // Buscar dados atualizados da venda para verificar email_entrega_enviado
                            $stmt_check_email = $pdo->prepare("SELECT email_entrega_enviado FROM vendas WHERE transacao_id = ? LIMIT 1");
                            $stmt_check_email->execute([$payment_id]);
                            $email_check = $stmt_check_email->fetch(PDO::FETCH_ASSOC);

                            if ($email_check && $email_check['email_entrega_enviado'] == 0) {
                                log_webhook("Iniciando processamento de entrega para transacao: " . $payment_id);
                                log_webhook("Email entrega enviado: " . $email_check['email_entrega_enviado']);

                                // Processa produtos e gerencia criação de senha/token
                                $processed_prods = [];
                                $pass = null;
                                $setup_token = null; // Inicializa explicitamente

                                foreach ($all_sales as $s) {
                                    log_webhook("Processando produto: " . $s['produto_nome'] . " - Tipo: " . $s['tipo_entrega']);
                                    $res = process_single_product_delivery($s, $s['comprador_email']);
                                    if ($res['success']) {
                                        log_webhook("Produto processado com sucesso: " . $res['product_name'] . " - Tipo: " . $res['content_type']);
                                        if ($res['content_type'] == 'area_membros') {
                                            // Verifica se usuário já existe
                                            $stmt_check = $pdo->prepare("SELECT id, senha FROM usuarios WHERE usuario = ? AND tipo = 'usuario'");
                                            $stmt_check->execute([$s['comprador_email']]);
                                            $existing_user = $stmt_check->fetch(PDO::FETCH_ASSOC);

                                            if ($existing_user) {
                                                // Cliente JÁ TEM conta
                                                // NÃO gerar senha, apenas garantir acesso (já feito por process_single_product_delivery)
                                                log_webhook("Cliente existente detectado: " . $s['comprador_email'] . " - Não gerando senha");
                                                $pass = null; // Não passa senha no email
                                                // Garante que setup_token seja null para cliente existente
                                                if (!isset($setup_token))
                                                    $setup_token = null;
                                            } else {
                                                // Cliente NOVO
                                                // Criar usuário com senha temporária (será substituída quando criar senha via token)
                                                // A coluna senha é NOT NULL, então precisamos de um valor temporário
                                                $temp_password = bin2hex(random_bytes(32)); // Senha temporária longa e aleatória
                                                $hashed_temp = password_hash($temp_password, PASSWORD_DEFAULT);

                                                try {
                                                    $stmt_insert = $pdo->prepare("INSERT INTO usuarios (usuario, nome, senha, tipo) VALUES (?, ?, ?, 'usuario')");
                                                    $stmt_insert->execute([$s['comprador_email'], $s['comprador_nome'], $hashed_temp]);
                                                    $new_user_id = $pdo->lastInsertId();
                                                    log_webhook("Novo usuário criado (com senha temporária): " . $s['comprador_email'] . " - ID: " . $new_user_id);
                                                } catch (PDOException $e) {
                                                    log_webhook("ERRO ao criar usuário: " . $e->getMessage() . " - Email: " . $s['comprador_email']);
                                                    // Continua o processo mesmo se falhar (pode ser usuário duplicado)
                                                    $new_user_id = null;
                                                }

                                                // Gerar token de criação de senha apenas se o usuário foi criado
                                                if ($new_user_id && function_exists('generate_setup_token')) {
                                                    $setup_token = generate_setup_token($new_user_id);
                                                    log_webhook("Token de criação de senha gerado para novo usuário: " . $s['comprador_email'] . " - Token: " . substr($setup_token, 0, 10) . "...");
                                                } else {
                                                    if (!$new_user_id) {
                                                        log_webhook("ERRO: Não foi possível criar usuário, token não será gerado para: " . $s['comprador_email']);
                                                    } else {
                                                        log_webhook("ERRO: Função generate_setup_token não encontrada!");
                                                    }
                                                    $setup_token = null;
                                                }

                                                $pass = null; // Não passa senha no email
                                            }
                                        }
                                        $processed_prods[] = $res;
                                    } else {
                                        log_webhook("Erro ao processar produto: " . ($res['message'] ?? 'Erro desconhecido'));
                                    }
                                }

                                log_webhook("Total de produtos processados: " . count($processed_prods));

                                if (!empty($processed_prods)) {
                                    // Verificar se todos os produtos são do tipo 'somente_pagamento'
                                    $all_somente_pagamento = true;
                                    $has_somente_pagamento = false;
                                    foreach ($processed_prods as $prod) {
                                        if ($prod['content_type'] === 'somente_pagamento') {
                                            $has_somente_pagamento = true;
                                        } else {
                                            $all_somente_pagamento = false;
                                        }
                                    }

                                    // Se todos os produtos forem 'somente_pagamento', não enviar email
                                    if ($all_somente_pagamento && $has_somente_pagamento) {
                                        log_webhook("Todos os produtos são 'somente_pagamento' - Nenhum email será enviado para: " . $main_sale['comprador_email']);
                                        // Marcar email_entrega_enviado = 1 para evitar reprocessamento
                                        $pdo->prepare("UPDATE vendas SET email_entrega_enviado = 1 WHERE checkout_session_uuid = ?")->execute([$main_sale['checkout_session_uuid']]);
                                        log_webhook("Flag email_entrega_enviado atualizada para 1 (sem envio de email)");
                                    } else {
                                        // Filtrar produtos que não são 'somente_pagamento' para envio de email
                                        $produtos_para_email = array_filter($processed_prods, function ($prod) {
                                            return $prod['content_type'] !== 'somente_pagamento';
                                        });

                                        // Se houver produtos para enviar por email
                                        if (!empty($produtos_para_email)) {
                                            // Busca Link de Login
                                            $login_url_stmt = $pdo->query("SELECT valor FROM configuracoes WHERE chave = 'member_area_login_url'");
                                            $login_url_config = $login_url_stmt->fetchColumn();

                                            // Se não estiver configurado, gera URL padrão
                                            if (empty($login_url_config) || $login_url_config === '#') {
                                                $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
                                                $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                                                $login_url = $protocol . '://' . $host . '/member_login';
                                            } else {
                                                $login_url = $login_url_config;
                                            }

                                            // Busca dados de endereço se houver produto físico
                                            $address_data = null;
                                            $has_physical_product = false;
                                            foreach ($produtos_para_email as $prod) {
                                                if ($prod['content_type'] === 'produto_fisico') {
                                                    $has_physical_product = true;
                                                    break;
                                                }
                                            }

                                            if ($has_physical_product && !empty($main_sale['comprador_cep'])) {
                                                $address_data = [
                                                    'cep' => $main_sale['comprador_cep'] ?? '',
                                                    'logradouro' => $main_sale['comprador_logradouro'] ?? '',
                                                    'numero' => $main_sale['comprador_numero'] ?? '',
                                                    'complemento' => $main_sale['comprador_complemento'] ?? '',
                                                    'bairro' => $main_sale['comprador_bairro'] ?? '',
                                                    'cidade' => $main_sale['comprador_cidade'] ?? '',
                                                    'estado' => $main_sale['comprador_estado'] ?? ''
                                                ];
                                            }

                                            log_webhook("Preparando envio de email para: " . $main_sale['comprador_email']);
                                            log_webhook("Login URL: " . $login_url);
                                            log_webhook("Senha gerada: " . ($pass ? 'SIM' : 'NÃO'));
                                            log_webhook("Token de setup: " . ($setup_token ? 'SIM' : 'NÃO'));
                                            log_webhook("Produto físico: " . ($has_physical_product ? 'SIM' : 'NÃO'));
                                            log_webhook("Produtos para email: " . count($produtos_para_email) . " (excluindo somente_pagamento)");

                                            // Envia E-mail apenas com produtos que não são 'somente_pagamento'
                                            $email_sent = send_delivery_email_consolidated($main_sale['comprador_email'], $main_sale['comprador_nome'], array_values($produtos_para_email), $pass, $login_url, $address_data, $setup_token);

                                            if ($email_sent) {
                                                log_webhook("Email enviado com sucesso para: " . $main_sale['comprador_email']);
                                                $pdo->prepare("UPDATE vendas SET email_entrega_enviado = 1 WHERE checkout_session_uuid = ?")->execute([$main_sale['checkout_session_uuid']]);
                                                log_webhook("Flag email_entrega_enviado atualizada para 1");
                                            } else {
                                                log_webhook("ERRO: Falha ao enviar email para: " . $main_sale['comprador_email']);
                                            }
                                        } else {
                                            log_webhook("AVISO: Nenhum produto para enviar por email (todos são somente_pagamento ou nenhum foi processado)");
                                        }
                                    }
                                } else {
                                    if ($email_check && $email_check['email_entrega_enviado'] != 0) {
                                        log_webhook("Email já foi enviado anteriormente (email_entrega_enviado = " . $email_check['email_entrega_enviado'] . ")");
                                    }
                                }
                            } else {
                                if ($db_status !== 'approved') {
                                    log_webhook("Status não é approved: " . $db_status);
                                }
                            }
                        }
                    } else {
                        // should_process é false - não disparar eventos para evitar duplicação
                        log_webhook("should_process é false - eventos não disparados. db_status: " . $db_status);
                    }
                }
            }
            exit;
        }

        // ... GET Actions ...
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            ob_clean();
            header('Content-Type: application/json');

            // Verifica se $pdo está disponível
            if (!isset($pdo)) {
                http_response_code(500);
                error_log("Erro: \$pdo não está definido em notification.php");
                echo json_encode(['error' => 'Erro de configuração do servidor']);
                exit;
            }

            if (!isset($_SESSION['id'])) {
                http_response_code(401);
                echo json_encode(['error' => 'Auth required']);
                exit;
            }

            $uid = $_SESSION['id'];
            $action = $_GET['action'] ?? '';

            if ($action === 'get_unread_count') {
                try {
                    $c = $pdo->prepare("SELECT COUNT(*) FROM notificacoes WHERE usuario_id = ? AND lida = 0");
                    $c->execute([$uid]);
                    ob_clean();
                    echo json_encode(['success' => true, 'count' => (int) $c->fetchColumn()]);
                } catch (PDOException $e) {
                    ob_clean();
                    http_response_code(500);
                    error_log("Erro get_unread_count (PDO): " . $e->getMessage());
                    echo json_encode(['error' => 'Erro ao buscar contagem']);
                } catch (Exception $e) {
                    ob_clean();
                    http_response_code(500);
                    error_log("Erro get_unread_count: " . $e->getMessage());
                    echo json_encode(['error' => 'Erro ao buscar contagem']);
                }
                exit;
            }

            if ($action === 'get_recent_notifications') {
                try {
                    $s = $pdo->prepare("SELECT id, tipo, mensagem, valor, DATE_FORMAT(data_notificacao, '%Y-%m-%dT%H:%i:%s') as data_notificacao, lida, link_acao FROM notificacoes WHERE usuario_id = ? ORDER BY data_notificacao DESC LIMIT 10");
                    $s->execute([$uid]);
                    ob_clean();
                    echo json_encode(['success' => true, 'notifications' => $s->fetchAll(PDO::FETCH_ASSOC)]);
                } catch (PDOException $e) {
                    ob_clean();
                    http_response_code(500);
                    error_log("Erro get_recent_notifications (PDO): " . $e->getMessage());
                    echo json_encode(['error' => 'Erro ao buscar notificações']);
                } catch (Exception $e) {
                    ob_clean();
                    http_response_code(500);
                    error_log("Erro get_recent_notifications: " . $e->getMessage());
                    echo json_encode(['error' => 'Erro ao buscar notificações']);
                }
                exit;
            }

            if ($action === 'get_live_notifications') {
                try {
                    $s = $pdo->prepare("SELECT n.id, n.tipo, n.mensagem, n.valor, n.metodo_pagamento, p.nome as produto_nome, p.foto as produto_foto FROM notificacoes n LEFT JOIN vendas v ON n.venda_id_fk = v.id LEFT JOIN produtos p ON v.produto_id = p.id WHERE n.usuario_id = ? AND n.displayed_live = 0 ORDER BY n.data_notificacao ASC LIMIT 5");
                    $s->execute([$uid]);
                    ob_clean();
                    echo json_encode(['success' => true, 'live_notifications' => $s->fetchAll(PDO::FETCH_ASSOC)]);
                } catch (PDOException $e) {
                    ob_clean();
                    http_response_code(500);
                    error_log("Erro get_live_notifications (PDO): " . $e->getMessage());
                    echo json_encode(['error' => 'Erro ao buscar notificações ao vivo']);
                } catch (Exception $e) {
                    ob_clean();
                    http_response_code(500);
                    error_log("Erro get_live_notifications: " . $e->getMessage());
                    echo json_encode(['error' => 'Erro ao buscar notificações ao vivo']);
                }
                exit;
            }

            // Se não for nenhuma ação conhecida, retorna erro
            ob_clean();
            http_response_code(400);
            echo json_encode(['error' => 'Ação não reconhecida']);
            exit;
        }
    }

} catch (Throwable $e) {
    http_response_code(500);
    error_log("Erro Fatal Notification: " . $e->getMessage());
    ob_clean();
    echo json_encode(['error' => 'Erro interno']);
}
?>