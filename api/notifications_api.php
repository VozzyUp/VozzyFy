<?php

require_once __DIR__ . '/../config/config.php';

// PHPMailer - Incluir a biblioteca PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

// Configura o log de erros PHP para esta API.
ini_set('display_errors', 0); // DESABILITAR exibição de erros no navegador para APIs
ini_set('log_errors', 1); // Habilita o log de erros
ini_set('error_log', __DIR__ . '/notification_api_errors.log'); // Loga erros para um arquivo específico da API de notificações

/**
 * Função para registrar mensagens em um arquivo de log específico para webhooks.
 * @param string $message A mensagem a ser registrada.
 */
function log_webhook($message) {
    file_put_contents('webhook_log.txt', date('Y-m-d H:i:s') . " - " . $message . "\n", FILE_APPEND);
}

// Incluir os arquivos do PHPMailer
$phpmailer_path = __DIR__ . '/PHPMailer/src/';
if (file_exists($phpmailer_path . 'Exception.php')) {
    require_once $phpmailer_path . 'Exception.php';
} else {
    error_log("ERRO: PHPMailer Exception.php não encontrado em " . $phpmailer_path . 'Exception.php');
}
if (file_exists($phpmailer_path . 'PHPMailer.php')) {
    require_once $phpmailer_path . 'PHPMailer.php';
} else {
    error_log("ERRO: PHPMailer.php não encontrado em " . $phpmailer_path . 'PHPMailer.php');
}
if (file_exists($phpmailer_path . 'SMTP.php')) {
    require_once $phpmailer_path . 'SMTP.php';
} else {
    error_log("ERRO: PHPMailer SMTP.php não encontrado em " . $phpmailer_path . 'SMTP.php');
}


/**
 * Envia um evento de conversão para a API do Facebook (CAPI).
 *
 * @param string $pixel_id ID do Pixel do Facebook.
 * @param string $api_token Token de Acesso da API de Conversões.
 * @param string $event_name Nome do evento (ex: 'Purchase', 'Refund').
 * @param array $sale_details Detalhes da venda do banco de dados.
 * @param string $event_source_url URL da página onde o evento ocorreu (checkout).
 */
function sendFacebookConversionEvent($pixel_id, $api_token, $event_name, $sale_details, $event_source_url) {
    if (empty($pixel_id) || empty($api_token)) {
        log_webhook("TRACKING FB: Pixel ID ou API Token não configurados. Evento '$event_name' não enviado.");
        return;
    }

    $url = "https://graph.facebook.com/v19.0/" . $pixel_id . "/events?access_token=" . $api_token;

    $user_data = [
        'em' => [hash('sha256', strtolower($sale_details['comprador_email']))],
        'ph' => [hash('sha256', preg_replace('/[^0-9]/', '', $sale_details['comprador_telefone']))],
    ];
    
    $name_parts = explode(' ', $sale_details['comprador_nome'], 2);
    $user_data['fn'] = [hash('sha256', strtolower($name_parts[0]))];
    if (isset($name_parts[1])) {
        $user_data['ln'] = [hash('sha256', strtolower($name_parts[1]))];
    }

    $payload = [
        'data' => [
            [
                'event_name' => $event_name,
                'event_time' => time(),
                'event_source_url' => $event_source_url,
                'user_data' => $user_data,
                'custom_data' => [
                    'currency' => 'BRL',
                    'value' => (float)$sale_details['valor'],
                ],
                'action_source' => 'website',
            ]
        ],
        // 'test_event_code' => 'TEST_CODE' // Descomente para testar
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
        log_webhook("TRACKING FB: Sucesso! Evento '$event_name' enviado para o Pixel ID $pixel_id. Resposta: " . $response);
    } else {
        log_webhook("TRACKING FB: ERRO! Falha ao enviar evento '$event_name' para o Pixel ID $pixel_id. HTTP $http_code. Resposta: " . $response);
    }
}


/**
 * Dispara eventos de rastreamento com base no status do pagamento.
 *
 * @param string $status Status do pagamento (ex: 'approved').
 * @param array $sale_details Detalhes da venda.
 * @param array $checkout_config Configurações do checkout do produto.
 */
function handle_tracking_events($status, $sale_details, $checkout_config) {
    $tracking_config = $checkout_config['tracking'] ?? [];
    if (empty($tracking_config)) {
        log_webhook("TRACKING: Configuração de rastreamento vazia para o produto.");
        return;
    }

    $event_map = [
        'approved'     => ['key' => 'purchase',   'fb_name' => 'Purchase'],
        'pending'      => ['key' => 'pending',    'fb_name' => 'PaymentPending'],
        'rejected'     => ['key' => 'rejected',   'fb_name' => 'PaymentRejected'],
        'refunded'     => ['key' => 'refund',     'fb_name' => 'Refund'],
        'charged_back' => ['key' => 'chargeback', 'fb_name' => 'Chargeback']
    ];
    
    if (!isset($event_map[$status])) {
        log_webhook("TRACKING: Status '$status' não mapeado para evento de rastreamento.");
        return;
    }

    $event_info = $event_map[$status];
    $event_key = $event_info['key'];
    $fb_event_name = $event_info['fb_name'];

    // Lógica para Facebook CAPI
    $fb_events_enabled = $tracking_config['events']['facebook'] ?? [];
    if (!empty($fb_events_enabled[$event_key])) {
        $pixel_id = $tracking_config['facebookPixelId'] ?? '';
        $api_token = $tracking_config['facebookApiToken'] ?? '';
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
        $domainName = $_SERVER['HTTP_HOST'];
        $checkout_url = $protocol . $domainName . '/checkout?p=' . $sale_details['checkout_hash'];
        
        sendFacebookConversionEvent($pixel_id, $api_token, $fb_event_name, $sale_details, $checkout_url);
    } else {
        log_webhook("TRACKING FB: Evento '$fb_event_name' (chave: $event_key) está desativado nas configurações do produto.");
    }
}

/**
 * Dispara eventos de webhook para integrações externas.
 *
 * @param int $usuario_id ID do infoprodutor.
 * @param array $event_data Dados do evento a serem enviados.
 * @param string $trigger_event O evento que disparou (ex: 'approved', 'pending').
 * @param int|null $produto_id O ID do produto associado à venda, se houver.
 */
function trigger_webhooks($usuario_id, $event_data, $trigger_event, $produto_id = null) {
    global $pdo;
    log_webhook("WEBHOOKS: Verificando webhooks para o evento '$trigger_event' (usuario_id: $usuario_id, produto_id: " . ($produto_id ?? 'NULL') . ")");

    $event_field = 'event_' . $trigger_event;

    // Busca webhooks globais (produto_id IS NULL) ou específicos para o produto
    $stmt_webhooks = $pdo->prepare("
        SELECT url 
        FROM webhooks 
        WHERE usuario_id = :usuario_id 
        AND {$event_field} = 1 
        AND (produto_id IS NULL OR produto_id = :produto_id)
    ");
    $stmt_webhooks->bindParam(':usuario_id', $usuario_id, PDO::PARAM_INT);
    $stmt_webhooks->bindParam(':produto_id', $produto_id, PDO::PARAM_INT);
    $stmt_webhooks->execute();
    $webhooks = $stmt_webhooks->fetchAll(PDO::FETCH_COLUMN);

    if (empty($webhooks)) {
        log_webhook("WEBHOOKS: Nenhum webhook encontrado para o evento '$trigger_event'.");
        return;
    }

    $payload = [
        'event' => $trigger_event,
        'timestamp' => date('Y-m-d H:i:s'),
        'data' => $event_data
    ];

    $json_payload = json_encode($payload);

    foreach ($webhooks as $url) {
        log_webhook("WEBHOOKS: Enviando payload para URL: $url");
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'X-Starfy-Event: ' . $trigger_event, // Cabeçalho personalizado
            'User-Agent: StarfyWebhookAgent/1.0' // User-Agent personalizado
        ]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json_payload);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15); // Timeout de 15 segundos

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($curl_error) {
            log_webhook("WEBHOOKS: ERRO! Falha cURL para $url. Erro: $curl_error");
        } else if ($http_code >= 200 && $http_code < 300) {
            log_webhook("WEBHOOKS: Sucesso! Webhook enviado para $url. HTTP $http_code. Resposta: " . (strlen($response) > 200 ? substr($response, 0, 200) . '...' : $response));
        } else {
            log_webhook("WEBHOOKS: FALHA! Webhook para $url retornou HTTP $http_code. Resposta: " . (strlen($response) > 200 ? substr($response, 0, 200) . '...' : $response));
        }
    }
}

/**
 * Dispara eventos para integração com a UTMfy.
 *
 * @param int $usuario_id ID do infoprodutor.
 * @param array $event_data Dados do evento a serem enviados (venda).
 * @param string $trigger_event O evento que disparou (ex: 'approved', 'pending').
 * @param int|null $produto_id O ID do produto associado à venda, se houver.
 * @param array $mercado_pago_payment_data Dados completos do pagamento do Mercado Pago (para approvedDate, refundedAt).
 */
function trigger_utmfy_integrations($usuario_id, $event_data, $trigger_event, $produto_id = null, $mercado_pago_payment_data = []) {
    global $pdo;
    log_webhook("UTMFY: Verificando integrações para o evento '$trigger_event' (usuario_id: $usuario_id, produto_id: " . ($produto_id ?? 'NULL') . ")");

    $event_field = 'event_' . $trigger_event;

    $stmt_integrations = $pdo->prepare("
        SELECT api_token
        FROM utmfy_integrations
        WHERE usuario_id = :usuario_id
        AND {$event_field} = 1
        AND (product_id IS NULL OR product_id = :product_id)
    ");
    $stmt_integrations->bindParam(':usuario_id', $usuario_id, PDO::PARAM_INT);
    $stmt_integrations->bindParam(':product_id', $produto_id, PDO::PARAM_INT);
    $stmt_integrations->execute();
    $integrations = $stmt_integrations->fetchAll(PDO::FETCH_COLUMN);

    if (empty($integrations)) {
        log_webhook("UTMFY: Nenhuma integração encontrada para o evento '$trigger_event'.");
        return;
    }

    $utmify_endpoint = 'https://api.utmify.com.br/api-credentials/orders';

    // Mapeamento de status da Starfy para status da UTMfy
    $status_map = [
        'approved'     => 'paid',
        'pending'      => 'waiting_payment', // Para Pix/Boleto pendente ou cartão em análise
        'in_process'   => 'waiting_payment', // Em processo de análise
        'rejected'     => 'refused',
        'refunded'     => 'refunded',
        'charged_back' => 'chargedback',
        'cancelled'    => 'refused', // Cancelado pode ser considerado recusado pela UTMfy
    ];
    $utmfy_status = $status_map[$trigger_event] ?? 'waiting_payment';

    // Mapeamento de métodos de pagamento da Starfy para UTMfy
    $payment_method_map = [
        'Pix' => 'pix',
        'Boleto' => 'boleto',
        'Cartão de crédito' => 'credit_card',
        // Adicionar outros, se houver
    ];
    $utmfy_payment_method = $payment_method_map[$event_data['metodo_pagamento']] ?? 'free_price'; // Default para 'free_price' se desconhecido

    foreach ($integrations as $api_token) {
        $customer_phone = preg_replace('/[^0-9]/', '', $event_data['comprador']['telefone']);
        $customer_document = preg_replace('/[^0-9]/', '', $event_data['comprador']['cpf']);

        $products_payload = [];
        $total_price_in_cents = 0;
        foreach ($event_data['produtos_comprados'] as $product) {
            $product_price_in_cents = (int)(((float)$product['valor']) * 100);
            $products_payload[] = [
                'id' => (string)$product['produto_id'],
                'name' => (string)$product['nome'],
                'planId' => null, // Assumindo que Starfy não tem 'planId'
                'planName' => null, // Assumindo que Starfy não tem 'planName'
                'quantity' => 1, // Assumindo 1 unidade por item de venda no BD
                'priceInCents' => $product_price_in_cents
            ];
            $total_price_in_cents += $product_price_in_cents;
        }

        // Conversão de datas para UTC (formato 'YYYY-MM-DD HH:MM:SS')
        // Datas do DB são em 'America/Sao_Paulo' (-03:00)
        $convert_to_utc = function($date_string) {
            if (empty($date_string) || $date_string === 'null') return null;
            try {
                $datetime = new DateTime($date_string, new DateTimeZone('America/Sao_Paulo'));
                $datetime->setTimezone(new DateTimeZone('UTC'));
                return $datetime->format('Y-m-d H:i:s');
            } catch (Exception $e) {
                log_webhook("UTMFY: Erro ao converter data para UTC: $date_string. Erro: " . $e->getMessage());
                return null;
            }
        };

        $created_at_utc = $convert_to_utc($event_data['data_venda'] ?? null);
        $approved_date_utc = null;
        $refunded_at_utc = null;

        if ($trigger_event === 'approved' && isset($mercado_pago_payment_data['date_approved'])) {
            $approved_date_utc = $convert_to_utc($mercado_pago_payment_data['date_approved']);
        }
        if ($trigger_event === 'refunded' && isset($mercado_pago_payment_data['date_refunded'])) {
            $refunded_at_utc = $convert_to_utc($mercado_pago_payment_data['date_refunded']);
        }
        // Se a data de criação não puder ser determinada, use o tempo atual em UTC como fallback.
        if (empty($created_at_utc)) {
            $created_at_utc = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
            log_webhook("UTMFY: data_venda da Starfy vazia, usando tempo atual UTC para createdAt.");
        }


        $payload = [
            'orderId' => (string)$event_data['transacao_id'],
            'platform' => 'Getfy', // Nome da sua plataforma
            'paymentMethod' => $utmfy_payment_method,
            'status' => $utmfy_status,
            'createdAt' => $created_at_utc,
            'approvedDate' => $approved_date_utc,
            'refundedAt' => $refunded_at_utc,
            'customer' => [
                'name' => $event_data['comprador']['nome'],
                'email' => $event_data['comprador']['email'],
                'phone' => empty($customer_phone) ? null : $customer_phone,
                'document' => empty($customer_document) ? null : $customer_document,
                'country' => 'BR', // Assumindo Brasil
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null // IP do servidor ou do cliente original, se capturado
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
                'totalPriceInCents' => $total_price_in_cents,
                'gatewayFeeInCents' => 0, // Ajustar se a Starfy tiver uma taxa de gateway fixa/conhecida
                'userCommissionInCents' => $total_price_in_cents, // Assumindo que a comissão do usuário é o total se a taxa de gateway for 0
                'currency' => 'BRL'
            ],
            'isTest' => false
        ];
        
        $json_payload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (json_last_error() !== JSON_ERROR_NONE) {
            log_webhook("UTMFY: ERRO ao codificar payload JSON para UTMfy: " . json_last_error_msg());
            continue; // Pular para a próxima integração
        }

        log_webhook("UTMFY: Enviando payload para UTMfy (token parcial: " . substr($api_token, 0, 10) . "...). Evento: $trigger_event");
        log_webhook("UTMFY: URL da requisição: $utmify_endpoint");
        log_webhook("UTMFY: Payload JSON completo: " . $json_payload);
        
        $ch = curl_init($utmify_endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'x-api-token: ' . $api_token // Header de autenticação da UTMfy
        ]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json_payload);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($curl_error) {
            log_webhook("UTMFY: ERRO! Falha cURL ao enviar para UTMfy (token parcial: " . substr($api_token, 0, 10) . "...). Erro: $curl_error");
        } else if ($http_code >= 200 && $http_code < 300) {
            log_webhook("UTMFY: Sucesso! Evento '$trigger_event' enviado para UTMfy (token parcial: " . substr($api_token, 0, 10) . "...) HTTP $http_code. Resposta: " . (strlen($response) > 200 ? substr($response, 0, 200) . '...' : $response));
        } else {
            log_webhook("UTMFY: FALHA! Evento '$trigger_event' para UTMfy (token parcial: " . substr($api_token, 0, 10) . "...) retornou HTTP $http_code. Resposta: " . (strlen($response) > 200 ? substr($response, 0, 200) . '...' : $response));
        }
    }
}


/**
 * Processa a entrega de um único produto, registrando acessos ou coletando dados para e-mail.
 *
 * @param array $product_data Detalhes do produto e da venda (`vendas` JOIN `produtos`).
 * @param string $customer_email E-mail do comprador.
 * @return array Um array com 'success' (bool), 'product_name' (string), 'content_type' (string: 'link', 'pdf', 'area_membros'),
 * 'content_value' (string: URL, path_to_pdf, ou null para área de membros), e 'message' (string).
 */
function process_single_product_delivery($product_data, $customer_email) {
    global $pdo;

    $delivery_type = $product_data['tipo_entrega'];
    $delivery_content = $product_data['conteudo_entrega'];
    $product_name = $product_data['produto_nome'];
    $product_id_for_area_membros = $product_data['produto_id'];

    log_webhook("  Iniciando processamento de entrega para produto '$product_name'. Tipo: '$delivery_type'.");
    
    switch ($delivery_type) {
        case 'link':
            if (!empty($delivery_content)) {
                return ['success' => true, 'product_name' => $product_name, 'content_type' => 'link', 'content_value' => $delivery_content];
            } else {
                return ['success' => false, 'message' => "Conteúdo de entrega (link) vazio para o produto '$product_name'."];
            }
        case 'email_pdf':
            if (!empty($delivery_content)) {
                $pdf_path = 'uploads/' . $delivery_content;
                if (file_exists($pdf_path) && is_readable($pdf_path)) {
                    return ['success' => true, 'product_name' => $product_name, 'content_type' => 'pdf', 'content_value' => $pdf_path];
                } else {
                    return ['success' => false, 'message' => "Arquivo PDF não encontrado ou ilegível em: " . $pdf_path];
                }
            } else {
                return ['success' => false, 'message' => "Conteúdo de entrega (PDF) vazio para o produto '$product_name'."];
            }
        case 'area_membros':
            if (!empty($customer_email) && !empty($product_id_for_area_membros)) {
                $stmt_grant_access = $pdo->prepare("INSERT IGNORE INTO alunos_acessos (aluno_email, produto_id) VALUES (?, ?)");
                $stmt_grant_access->execute([$customer_email, $product_id_for_area_membros]);

                if ($stmt_grant_access->rowCount() > 0) {
                    log_webhook("    SUCESSO DE ENTREGA (Área de Membros): Acesso concedido para " . $customer_email . " ao produto ID " . $product_id_for_area_membros);
                    return ['success' => true, 'product_name' => $product_name, 'content_type' => 'area_membros', 'content_value' => null];
                } else {
                    log_webhook("    INFO DE ENTREGA (Área de Membros): Acesso para " . $customer_email . " ao produto ID " . $product_id_for_area_membros . " já existia ou falhou (IGNORADO).");
                    return ['success' => true, 'product_name' => $product_name, 'content_type' => 'area_membros', 'content_value' => null, 'message' => 'Acesso já concedido.'];
                }
            } else {
                return ['success' => false, 'message' => "E-mail do comprador ou ID do produto ausente para a área de membros do produto '$product_name'."];
            }
        case 'somente_pagamento':
            log_webhook("    INFO DE ENTREGA (Somente Pagamento): Produto '$product_name' - Nenhum email será enviado.");
            return ['success' => true, 'product_name' => $product_name, 'content_type' => 'somente_pagamento', 'content_value' => null];
        default:
            return ['success' => false, 'message' => "Tipo de entrega desconhecido ('$delivery_type') para o produto '$product_name'."];
    }
}


/**
 * Função para enviar e-mail de entrega consolidado do produto.
 * Utiliza as configurações SMTP do administrador e um template personalizável.
 *
 * @param string $to_email E-mail do destinatário.
 * @param string $customer_name Nome do cliente.
 * @param array $processed_products_for_email Array de produtos com detalhes de entrega formatados.
 * @param string|null $member_area_password Senha gerada para a área de membros, se houver.
 * @param string|null $member_area_login_url URL de login da área de membros.
 * @param string $email_subject Assunto do e-mail (do admin config).
 * @param string $email_html_template Template HTML do e-mail (do admin config).
 * @return bool True se o e-mail foi enviado com sucesso, false caso contrário.
 */
function send_delivery_email_consolidated($to_email, $customer_name, $processed_products_for_email, $member_area_password, $member_area_login_url, $email_subject, $email_html_template, $address_data = null, $setup_token = null) {
    global $pdo;

    $mail = new PHPMailer(true); // Habilita exceções

    try {
        // Obter configurações SMTP da tabela `configuracoes`
        $stmt_smtp_configs = $pdo->prepare("SELECT chave, valor FROM configuracoes WHERE chave IN ('smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_encryption', 'smtp_from_email', 'smtp_from_name')");
        $stmt_smtp_configs->execute();
        $smtp_configs_raw = $stmt_smtp_configs->fetchAll(PDO::FETCH_KEY_PAIR);
        
        $smtp_config = [
            'host' => $smtp_configs_raw['smtp_host'] ?? '',
            'port' => (int)($smtp_configs_raw['smtp_port'] ?? 587),
            'username' => $smtp_configs_raw['smtp_username'] ?? '',
            'password' => $smtp_configs_raw['smtp_password'] ?? '',
            'encryption' => $smtp_configs_raw['smtp_encryption'] ?? 'tls',
            'from_email' => $smtp_configs_raw['smtp_from_email'] ?? '',
            'from_name' => $smtp_configs_raw['smtp_from_name'] ?? 'Starfy'
        ];

        // Adiciona logging das configurações SMTP
        log_webhook("EMAIL_DELIVERY: Configurações SMTP obtidas para envio: " . print_r($smtp_config, true));

        // Configurar PHPMailer para usar SMTP ou PHP Mailer padrão
        if (empty($smtp_config['host']) || empty($smtp_config['username']) || empty($smtp_config['password'])) {
            log_webhook("SMTP: Credenciais não configuradas. Tentando usar a função mail() padrão.");
            $mail->isMail();
            $default_from_email = $smtp_config['from_email'] ?: ("nao-responda@" . parse_url($_SERVER['HTTP_HOST'], PHP_URL_HOST));
            $mail->setFrom($default_from_email, $smtp_config['from_name']);
        } else {
            $mail->isSMTP();
            $mail->Host = $smtp_config['host'];
            $mail->Port = $smtp_config['port'];
            $mail->SMTPAuth = true;
            $mail->Username = $smtp_config['username'];
            $mail->Password = $smtp_config['password'];
            
            // SMTPOptions para aceitar certificados autoassinados (cuidado em produção)
            $mail->SMTPOptions = array(
                'ssl' => array(
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                )
            );

            if ($smtp_config['encryption'] === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($smtp_config['encryption'] === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } else {
                $mail->SMTPSecure = false;
                $mail->SMTPAutoTLS = false;
            }
            // CORREÇÃO: Usar o username como 'From' address para evitar "Sender address rejected"
            $mail->setFrom($smtp_config['username'], $smtp_config['from_name']);
            log_webhook("SMTP: Usando configurações: Host=" . $smtp_config['host'] . ", User=" . $smtp_config['username'] . ", Port=" . $smtp_config['port'] . ", Enc=" . $smtp_config['encryption']);
        }

        $mail->CharSet = 'UTF-8';
        $mail->addAddress($to_email, $customer_name);
        // Garante que o assunto não esteja vazio
        $email_subject_clean = trim($email_subject ?? '');
        $mail->Subject = !empty($email_subject_clean) ? $email_subject_clean : 'Seus Acessos Foram Liberados!';
        $mail->isHTML(true);

        // Busca logo configurada
        $logo_url_final = '';
        if (function_exists('getSystemSetting')) {
            $logo_url_raw = getSystemSetting('logo_url', '');
            if (!empty($logo_url_raw)) {
                if (strpos($logo_url_raw, 'http') === 0) {
                    $logo_url_final = $logo_url_raw;
                } else {
                    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
                    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                    $logo_path = ltrim($logo_url_raw, '/');
                    $logo_url_final = $protocol . '://' . $host . '/' . $logo_path;
                }
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
        
        // Se o template estiver vazio, gera template padrão usando configurações da plataforma
        if (empty(trim($email_html_template))) {
            log_webhook("Notifications API: Template vazio, gerando template padrão com configurações da plataforma");
            
            // Carrega helper de template
            if (file_exists(__DIR__ . '/../helpers/email_template_helper.php')) {
                require_once __DIR__ . '/../helpers/email_template_helper.php';
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
                $email_html_template = generate_default_delivery_email_template($logo_checkout_url, $cor_primaria, $nome_plataforma);
            } else {
                // Fallback básico
                $email_html_template = '<p>Olá {CLIENT_NAME}, aqui estão seus produtos:</p><!-- LOOP_PRODUCTS_START --><p>{PRODUCT_NAME}</p><!-- LOOP_PRODUCTS_END -->';
            }
        }
        
        // Substituições de placeholders globais
        $html_body = str_replace(
            ['{CLIENT_NAME}', '{CLIENT_EMAIL}', '{MEMBER_AREA_PASSWORD}', '{MEMBER_AREA_LOGIN_URL}', '{LOGO_URL}', '{DELIVERY_ADDRESS}', '{SETUP_PASSWORD_URL}'],
            [
                htmlspecialchars($customer_name ?? ''),
                htmlspecialchars($to_email ?? ''),
                htmlspecialchars($member_area_password ?? 'Não aplicável'),
                htmlspecialchars($member_area_login_url ?? '#'),
                $logo_url_final,
                $address_html,
                $setup_password_url
            ],
            $email_html_template
        );

        // Processar blocos de produtos dentro do template
        $products_html_blocks = [];
        $product_loop_start_tag = '<!-- LOOP_PRODUCTS_START -->';
        $product_loop_end_tag = '<!-- LOOP_PRODUCTS_END -->';

        if (strpos($html_body, $product_loop_start_tag) !== false && strpos($html_body, $product_loop_end_tag) !== false) {
            $loop_template = substr(
                $html_body,
                strpos($html_body, $product_loop_start_tag) + strlen($product_loop_start_tag),
                strpos($html_body, $product_loop_end_tag) - (strpos($html_body, $product_loop_start_tag) + strlen($product_loop_start_tag))
            );

            foreach ($processed_products_for_email as $product) {
                $current_product_block = $loop_template;
                $current_product_block = str_replace('{PRODUCT_NAME}', htmlspecialchars($product['product_name']), $current_product_block);

                // Handle conditional content based on product type
                $product_type_markers = [
                    'link'        => ['<!-- IF_PRODUCT_TYPE_LINK -->', '<!-- END_IF_PRODUCT_TYPE_LINK -->'],
                    'pdf'         => ['<!-- IF_PRODUCT_TYPE_PDF -->', '<!-- END_IF_PRODUCT_TYPE_PDF -->'],
                    'area_membros' => ['<!-- IF_PRODUCT_TYPE_MEMBER_AREA -->', '<!-- END_IF_PRODUCT_TYPE_MEMBER_AREA -->']
                ];

                // Passo 1: Processar blocos condicionais (IFs)
                foreach ($product_type_markers as $type => $markers) {
                    $start = strpos($current_product_block, $markers[0]);
                    $end = strpos($current_product_block, $markers[1]);

                    if ($start !== false && $end !== false) {
                        $block_content = substr($current_product_block, $start + strlen($markers[0]), $end - ($start + strlen($markers[0])));
                        if ($product['content_type'] === $type) {
                            // MANTÉM O BLOCO

                            // Substituições específicas da área de membros (se estiverem dentro do bloco)
                            $block_content = str_replace('{MEMBER_AREA_PASSWORD}', htmlspecialchars($member_area_password ?? ''), $block_content);
                            $block_content = str_replace('{MEMBER_AREA_LOGIN_URL}', htmlspecialchars($member_area_login_url ?? ''), $block_content);
                            $block_content = str_replace('{SETUP_PASSWORD_URL}', htmlspecialchars($setup_password_url ?? ''), $block_content);
                            
                            // Processa tags condicionais para área de membros (novo usuário vs existente)
                            if ($product['content_type'] === 'area_membros') {
                                if ($setup_token) {
                                    // Cliente novo - mostra IF_NEW_USER_SETUP
                                    $block_content = str_replace(["<!-- IF_NEW_USER_SETUP -->", "<!-- END_IF_NEW_USER_SETUP -->"], '', $block_content);
                                    $block_content = preg_replace("/<!-- IF_EXISTING_USER -->.*?<!-- END_IF_EXISTING_USER -->/s", "", $block_content);
                                } else {
                                    // Cliente existente - mostra IF_EXISTING_USER
                                    $block_content = str_replace(["<!-- IF_EXISTING_USER -->", "<!-- END_IF_EXISTING_USER -->"], '', $block_content);
                                    $block_content = preg_replace("/<!-- IF_NEW_USER_SETUP -->.*?<!-- END_IF_NEW_USER_SETUP -->/s", "", $block_content);
                                }
                            } else {
                                // Remove ambas as tags se não for área de membros
                                $block_content = preg_replace("/<!-- IF_NEW_USER_SETUP -->.*?<!-- END_IF_NEW_USER_SETUP -->/s", "", $block_content);
                                $block_content = preg_replace("/<!-- IF_EXISTING_USER -->.*?<!-- END_IF_EXISTING_USER -->/s", "", $block_content);
                            }
                            
                            $current_product_block = str_replace($markers[0] . $block_content . $markers[1], $block_content, $current_product_block);
                        } else {
                            // Remove this block (tipo não corresponde)
                            $current_product_block = str_replace($markers[0] . $block_content . $markers[1], '', $current_product_block);
                        }
                    }
                }
                
                // Passo 2: Substituir placeholders de conteúdo (link, etc.) DEPOIS de processar os blocos IF
                // Isso permite que {PRODUCT_LINK} seja usado dentro ou fora dos blocos IF
                
                $product_link_value = ''; // Inicia vazio
                if ($product['content_type'] === 'link' && !empty($product['content_value'])) {
                    $product_link_value = $product['content_value'];
                }
                
                // CORREÇÃO: Não usar htmlspecialchars em URLs para o {PRODUCT_LINK}
                // $product_link_value é o link real apenas se o tipo for 'link'.
                $current_product_block = str_replace('{PRODUCT_LINK}', $product_link_value, $current_product_block);

                // Substituições de área de membros (caso estejam fora do bloco IF)
                $current_product_block = str_replace('{MEMBER_AREA_PASSWORD}', htmlspecialchars($member_area_password ?? 'Não aplicável'), $current_product_block);
                $current_product_block = str_replace('{MEMBER_AREA_LOGIN_URL}', htmlspecialchars($member_area_login_url ?? '#'), $current_product_block);
                $current_product_block = str_replace('{SETUP_PASSWORD_URL}', htmlspecialchars($setup_password_url ?? ''), $current_product_block);
                
                // Processa tags condicionais para área de membros fora do bloco IF também
                if ($product['content_type'] === 'area_membros') {
                    if ($setup_token) {
                        // Cliente novo - mostra IF_NEW_USER_SETUP
                        $current_product_block = str_replace(["<!-- IF_NEW_USER_SETUP -->", "<!-- END_IF_NEW_USER_SETUP -->"], '', $current_product_block);
                        $current_product_block = preg_replace("/<!-- IF_EXISTING_USER -->.*?<!-- END_IF_EXISTING_USER -->/s", "", $current_product_block);
                    } else {
                        // Cliente existente - mostra IF_EXISTING_USER
                        $current_product_block = str_replace(["<!-- IF_EXISTING_USER -->", "<!-- END_IF_EXISTING_USER -->"], '', $current_product_block);
                        $current_product_block = preg_replace("/<!-- IF_NEW_USER_SETUP -->.*?<!-- END_IF_NEW_USER_SETUP -->/s", "", $current_product_block);
                    }
                } else {
                    // Remove ambas as tags se não for área de membros
                    $current_product_block = preg_replace("/<!-- IF_NEW_USER_SETUP -->.*?<!-- END_IF_NEW_USER_SETUP -->/s", "", $current_product_block);
                    $current_product_block = preg_replace("/<!-- IF_EXISTING_USER -->.*?<!-- END_IF_EXISTING_USER -->/s", "", $current_product_block);
                }

                
                $products_html_blocks[] = $current_product_block;
            }
            $html_body = str_replace($product_loop_start_tag . $loop_template . $product_loop_end_tag, implode('', $products_html_blocks), $html_body);
        }

        $mail->Body = $html_body;
        $mail->AltBody = strip_tags($html_body); // Fallback para clientes de e-mail que não suportam HTML

        // Adicionar anexos PDF
        foreach ($processed_products_for_email as $product) {
            if ($product['content_type'] === 'pdf' && file_exists($product['content_value'])) {
                $mail->addAttachment($product['content_value'], basename($product['content_value']));
            }
        }
        
        $mail->send();
        log_webhook("SUCESSO DE ENTREGA: E-mail consolidado enviado para " . $to_email);
        return true;
    } catch (Exception $e) {
        log_webhook("FALHA DE ENTREGA (E-mail): O e-mail para " . $to_email . " não pôde ser enviado. Erro: " . $e->getMessage() . " File: " . $e->getFile() . " Line: " . $e->getLine());
        return false;
    }
}


/**
 * Cria uma notificação no banco de dados para o infoprodutor.
 *
 * @param int $usuario_id ID do infoprodutor.
 * @param string $tipo Tipo da notificação (ex: 'Compra Aprovada').
 * @param string $mensagem Mensagem da notificação.
 * @param float $valor Valor associado (ex: valor da venda).
 * @param int|null $venda_id_fk ID da venda associada (opcional).
 * @param string|null $metodo_pagamento Método de pagamento, para notificação live.
 */
function create_notification($usuario_id, $tipo, $mensagem, $valor, $venda_id_fk = null, $metodo_pagamento = null) {
    global $pdo;
    try {
        $link_acao = ($venda_id_fk) ? "/index?pagina=vendas&id={$venda_id_fk}" : null;
        $stmt = $pdo->prepare("INSERT INTO notificacoes (usuario_id, tipo, mensagem, valor, link_acao, venda_id_fk, metodo_pagamento) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$usuario_id, $tipo, $mensagem, $valor, $link_acao, $venda_id_fk, $metodo_pagamento]);
        error_log("NOTIFICACAO CRIADA: Para usuario_id=$usuario_id, Tipo: '$tipo', Mensagem: '$mensagem', Venda ID FK: $venda_id_fk, Método: $metodo_pagamento.");
    } catch (PDOException $e) {
        error_log("ERRO: Falha ao criar notificação para o infoprodutor ID $usuario_id: " . $e->getMessage());
    }
}

// Inicia o buffer de saída.
ob_start();

try {
    // --- Lógica para Requisições POST ---
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_GET['action'] ?? '';

        // --- Ação: Marcar Notificação como Lida (Frontend API) ---
        // This is the specific action for "quando eu abri o sininho a notificção tem que contar como lida"
        if ($action === 'mark_all_as_read') { 
            header('Content-Type: application/json');

            // Assegura que a sessão está iniciada e o usuário está logado
            if (session_status() == PHP_SESSION_NONE) {
                session_start();
            }
            if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || !isset($_SESSION['id'])) {
                http_response_code(403);
                ob_clean();
                echo json_encode(['success' => false, 'error' => 'Acesso não autorizado para marcar notificações como lidas.']);
                exit;
            }
            $usuario_id_logado = $_SESSION['id'];

            $stmt = $pdo->prepare("UPDATE notificacoes SET lida = 1 WHERE usuario_id = ? AND lida = 0");
            $stmt->execute([$usuario_id_logado]);
            ob_clean();
            echo json_encode(['success' => true, 'message' => 'Todas as notificações marcadas como lidas.']);
            exit;
        }

        // --- Ação: Marcar Notificação como Exibida ao Vivo (Frontend API) ---
        if ($action === 'mark_as_displayed_live') {
            header('Content-Type: application/json');

            // Assegura que a sessão está iniciada e o usuário está logado
            if (session_status() == PHP_SESSION_NONE) {
                session_start();
            }
            if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || !isset($_SESSION['id'])) {
                http_response_code(403);
                ob_clean();
                echo json_encode(['success' => false, 'error' => 'Acesso não autorizado para marcar notificação como exibida.']);
                exit;
            }
            $usuario_id_logado = $_SESSION['id'];

            $notification_id = $_POST['notification_id'] ?? null; // Correctly use $_POST for POST data

            if ($notification_id) {
                $stmt = $pdo->prepare("UPDATE notificacoes SET displayed_live = 1 WHERE id = ? AND usuario_id = ?");
                $stmt->execute([$notification_id, $usuario_id_logado]);
                ob_clean();
                echo json_encode(['success' => true, 'message' => 'Notificação marcada como exibida ao vivo.']);
            } else {
                http_response_code(400);
                ob_clean();
                echo json_encode(['success' => false, 'error' => 'ID da notificação ausente.']);
            }
            exit;
        }

        // --- Se não for uma ação de API específica com 'action' GET parameter, assume que é um Webhook ---
        // Responde imediatamente ao Mercado Pago para confirmar o recebimento.
        header('Content-Type: application/json');
        http_response_code(200);
        ob_clean(); // Limpa o buffer antes de enviar o JSON para o MP
        echo json_encode(['status' => 'success', 'message' => 'Webhook received for processing.']);
        ob_end_flush(); // Envia o 200 OK para o Mercado Pago e desliga o buffer.

        // O processamento pesado do webhook continua aqui, mas não pode gerar mais saída HTTP.
        $body = file_get_contents('php://input');
        if (empty($body)) { 
            log_webhook("Notificação Recebida (POST, sem action): Corpo da requisição vazio. Ignorando.");
            exit(); 
        }

        log_webhook("Notificação Recebida (POST, sem action): " . $body);
        $data = json_decode($body, true);

        if (isset($data['type']) && $data['type'] === 'payment' && isset($data['data']['id'])) {
            $payment_id = $data['data']['id'];
            log_webhook("Processando notificação de pagamento para ID: $payment_id.");

            try {
                // 1. Encontrar o produto_id e o usuario_id (infoprodutor) associados a ESTA transação
                $stmt_get_venda_base_info = $pdo->prepare("
                    SELECT v.id as venda_db_id, v.produto_id, p.usuario_id, v.checkout_session_uuid, v.comprador_email, v.comprador_nome, v.comprador_telefone, v.comprador_cpf, p.checkout_hash,
                           v.utm_source, v.utm_campaign, v.utm_medium, v.utm_content, v.utm_term, v.src, v.sck, v.data_venda
                    FROM vendas v
                    JOIN produtos p ON v.produto_id = p.id
                    WHERE v.transacao_id = ?
                    LIMIT 1 
                ");
                $stmt_get_venda_base_info->execute([$payment_id]);
                $venda_base_info = $stmt_get_venda_base_info->fetch(PDO::FETCH_ASSOC);

                if (!$venda_base_info) {
                    log_webhook("Info: Transação ID '$payment_id' não encontrada no BD ou sem produto/usuário associado. Ignorando.");
                    exit();
                }

                $usuario_id_do_produtor = $venda_base_info['usuario_id'];
                $checkout_session_uuid = $venda_base_info['checkout_session_uuid'];
                $primeira_venda_db_id = $venda_base_info['venda_db_id'];
                $customer_email = $venda_base_info['comprador_email'];
                $customer_name = $venda_base_info['comprador_nome'];

                // 2. Obter o access_token do Mercado Pago para o infoprodutor dono do produto
                $stmt_get_mp_token = $pdo->prepare("SELECT mp_access_token FROM usuarios WHERE id = ?");
                $stmt_get_mp_token->execute([$usuario_id_do_produtor]);
                $access_token = $stmt_get_mp_token->fetchColumn();

                if (empty($access_token)) {
                    log_webhook("ERRO CRÍTICO: Access Token do Mercado Pago não configurado para o infoprodutor ID: $usuario_id_do_produtor. Não é possível consultar a API do MP.");
                    exit();
                }

                // 3. Consultar a API do Mercado Pago com o access_token correto
                $url = "https://api.mercadopago.com/v1/payments/" . $payment_id;
                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $access_token]);
                log_webhook("Mercado Pago API: Consultando payment ID '$payment_id' para infoprodutor '$usuario_id_do_produtor'.");
                $response_body = curl_exec($ch);
                $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                log_webhook("Mercado Pago API: Resposta HTTP $http_code. Body: " . (strlen($response_body) > 500 ? substr($response_body, 0, 500) . '...' : $response_body));

                if ($http_code == 200) {
                    $payment_data = json_decode($response_body, true);
                    $new_status = $payment_data['status'];
                    $transaction_id = $payment_data['id'];

                    // 4. Atualizar o status de TODAS as vendas associadas à checkout_session_uuid e ao transacao_id
                    $stmt_update_multiple = $pdo->prepare("UPDATE vendas SET status_pagamento = ? WHERE transacao_id = ? AND checkout_session_uuid = ?");
                    $stmt_update_multiple->execute([$new_status, $transaction_id, $checkout_session_uuid]);
                    log_webhook("Sucesso: Todas as vendas da sessão '$checkout_session_uuid' (transação '$transaction_id') atualizadas para status '$new_status'.");
                    
                    // 5. Obter detalhes completos de TODAS as vendas da sessão para tracking e entrega
                    $stmt_details_all_sales = $pdo->prepare("
                        SELECT 
                            v.*,
                            p.nome as produto_nome, 
                            p.tipo_entrega, 
                            p.conteudo_entrega,
                            p.checkout_config,
                            p.checkout_hash
                        FROM vendas v
                        JOIN produtos p ON v.produto_id = p.id
                        WHERE v.transacao_id = ? AND v.checkout_session_uuid = ?
                    ");
                    $stmt_details_all_sales->execute([$transaction_id, $checkout_session_uuid]);
                    $all_sales_details = $stmt_details_all_sales->fetchAll(PDO::FETCH_ASSOC);

                    if ($all_sales_details) {
                        $main_sale_details = $all_sales_details[0]; 
                        $checkout_config_main_product = json_decode($main_sale_details['checkout_config'] ?? '{}', true);
                        
                        handle_tracking_events($new_status, $main_sale_details, $checkout_config_main_product);

                        // --- Lógica para Webhooks ---
                        $webhook_payload = [
                            'infoprodutor_id' => $usuario_id_do_produtor,
                            'transacao_id' => $transaction_id,
                            'status_pagamento' => $new_status,
                            'valor_total_compra' => array_sum(array_column($all_sales_details, 'valor')),
                            'comprador' => [
                                'nome' => $customer_name,
                                'email' => $customer_email,
                                'telefone' => $venda_base_info['comprador_telefone'],
                                'cpf' => $venda_base_info['comprador_cpf']
                            ],
                            'metodo_pagamento' => $main_sale_details['metodo_pagamento'],
                            'produtos_comprados' => [],
                            'utm_parameters' => [
                                'utm_source' => $venda_base_info['utm_source'],
                                'utm_campaign' => $venda_base_info['utm_campaign'],
                                'utm_medium' => $venda_base_info['utm_medium'],
                                'utm_content' => $venda_base_info['utm_content'],
                                'utm_term' => $venda_base_info['utm_term'],
                                'src' => $venda_base_info['src'],
                                'sck' => $venda_base_info['sck'],
                            ],
                            'data_venda' => $venda_base_info['data_venda'] // Para a UTMfy, se precisar de `createdAt`
                        ];

                        foreach ($all_sales_details as $sale_item) {
                            $webhook_payload['produtos_comprados'][] = [
                                'produto_id' => $sale_item['produto_id'],
                                'nome' => $sale_item['produto_nome'],
                                'valor' => (float)$sale_item['valor'],
                                'tipo_entrega' => $sale_item['tipo_entrega'],
                                'conteudo_entrega' => $sale_item['conteudo_entrega'],
                                'checkout_hash' => $sale_item['checkout_hash']
                            ];
                        }

                        trigger_webhooks($usuario_id_do_produtor, $webhook_payload, $new_status);
                        trigger_webhooks($usuario_id_do_produtor, $webhook_payload, $new_status, $main_sale_details['produto_id']);
                        foreach ($all_sales_details as $sale_item) {
                            if ($sale_item['produto_id'] !== $main_sale_details['produto_id']) {
                                trigger_webhooks($usuario_id_do_produtor, $webhook_payload, $new_status, $sale_item['produto_id']);
                            }
                        }

                        // --- NOVO: Lógica para UTMfy ---
                        // Passar os dados completos do Mercado Pago para a UTMfy
                        trigger_utmfy_integrations($usuario_id_do_produtor, $webhook_payload, $new_status, null, $payment_data); // Global
                        trigger_utmfy_integrations($usuario_id_do_produtor, $webhook_payload, $new_status, $main_sale_details['produto_id'], $payment_data); // Produto principal
                        foreach ($all_sales_details as $sale_item) {
                            if ($sale_item['produto_id'] !== $main_sale_details['produto_id']) {
                                trigger_utmfy_integrations($usuario_id_do_produtor, $webhook_payload, $new_status, $sale_item['produto_id'], $payment_data); // Order bumps
                            }
                        }

                        // --- Lógica para criar Notificação no banco de dados ---
                        $notification_type = '';
                        $notification_message = '';
                        $valor_total_compra = array_sum(array_column($all_sales_details, 'valor'));
                        $produtos_nomes = implode(', ', array_unique(array_column($all_sales_details, 'produto_nome')));
                        $metodo_pagamento_venda = htmlspecialchars($main_sale_details['metodo_pagamento']);

                        switch ($new_status) {
                            case 'approved':
                                $notification_type = 'Compra Aprovada';
                                $notification_message = "Parabéns! Nova venda aprovada de R$" . number_format($valor_total_compra, 2, ',', '.') . " para os produtos '{$produtos_nomes}' via {$metodo_pagamento_venda}.";
                                break;
                            case 'pending':
                                if ($main_sale_details['metodo_pagamento'] === 'Pix') {
                                    $notification_type = 'Pix Gerado';
                                    $notification_message = "Um pagamento Pix de R$" . number_format($valor_total_compra, 2, ',', '.') . " foi gerado para os produtos '{$produtos_nomes}'. Aguardando pagamento.";
                                } elseif ($main_sale_details['metodo_pagamento'] === 'Boleto') {
                                    $notification_type = 'Boleto Gerado';
                                    $notification_message = "Um boleto de R$" . number_format($valor_total_compra, 2, ',', '.') . " foi gerado para os produtos '{$produtos_nomes}'. Aguardando pagamento.";
                                } else {
                                    $notification_type = 'Pagamento Pendente';
                                    $notification_message = "Um pagamento de R$" . number_format($valor_total_compra, 2, ',', '.') . " para os produtos '{$produtos_nomes}' está pendente.";
                                }
                                break;
                            case 'in_process':
                                $notification_type = 'Pagamento em Processamento';
                                $notification_message = "O pagamento de R$" . number_format($valor_total_compra, 2, ',', '.') . " para os produtos '{$produtos_nomes}' está em processamento.";
                                break;
                            case 'rejected':
                                $notification_type = 'Pagamento Recusado';
                                $notification_message = "O pagamento de R$" . number_format($valor_total_compra, 2, ',', '.') . " para os produtos '{$produtos_nomes}' foi recusado. Motivo: " . htmlspecialchars($payment_data['status_detail'] ?? 'Não informado.');
                                break;
                            case 'cancelled':
                                $notification_type = 'Pagamento Cancelado';
                                $notification_message = "O pagamento de R$" . number_format($valor_total_compra, 2, ',', '.') . " para os produtos '{$produtos_nomes}' foi cancelado.";
                                break;
                            case 'refunded':
                                $notification_type = 'Reembolso';
                                $notification_message = "Um reembolso de R$" . number_format($valor_total_compra, 2, ',', '.') . " foi emitido para a venda dos produtos '{$produtos_nomes}'.";
                                break;
                            case 'charged_back':
                                $notification_type = 'Chargeback';
                                $notification_message = "ATENÇÃO: Foi detectado um chargeback de R$" . number_format($valor_total_compra, 2, ',', '.') . " para a venda dos produtos '{$produtos_nomes}'.";
                                break;
                            default:
                                $notification_type = 'Atualização de Pagamento';
                                $notification_message = "Status do pagamento de R$" . number_format($valor_total_compra, 2, ',', '.') . " para '{$produtos_nomes}' atualizado para: " . ucfirst(str_replace('_', ' ', $new_status)) . ".";
                                break;
                        }
                        
                        create_notification($usuario_id_do_produtor, $notification_type, $notification_message, $valor_total_compra, $primeira_venda_db_id, $metodo_pagamento_venda);

                        // --- Lógica de Entrega de Produtos (Somente para Aprovados e se o e-mail ainda não foi enviado) ---
                        if ($new_status === 'approved') {
                            $stmt_check_email_sent = $pdo->prepare("SELECT COUNT(*) FROM vendas WHERE checkout_session_uuid = ? AND email_entrega_enviado = 1");
                            $stmt_check_email_sent->execute([$checkout_session_uuid]);
                            $email_already_sent = $stmt_check_email_sent->fetchColumn() > 0;

                            if (!$email_already_sent) {
                                log_webhook("Entrega: E-mail de entrega consolidado ainda não enviado para a sessão '$checkout_session_uuid'. Processando entrega.");

                                $processed_products_for_email = [];
                                $member_area_password_for_delivery = null;
                                $member_area_login_url = null;
                                $setup_token = null; // Inicializa setup_token

                                $stmt_email_config = $pdo->query("SELECT chave, valor FROM configuracoes WHERE chave IN ('email_template_delivery_subject', 'email_template_delivery_html', 'member_area_login_url')");
                                $email_configs = $stmt_email_config->fetchAll(PDO::FETCH_KEY_PAIR);
                                $email_subject_raw = trim($email_configs['email_template_delivery_subject'] ?? '');
                                $email_subject = !empty($email_subject_raw) ? $email_subject_raw : 'Seus Acessos Foram Liberados!';
                                $email_html_template = $email_configs['email_template_delivery_html'] ?? '';
                                $member_area_login_url_config = $email_configs['member_area_login_url'] ?? '';

                                foreach ($all_sales_details as $sale) {
                                    $delivery_result = process_single_product_delivery($sale, $customer_email);

                                    if ($delivery_result['success']) {
                                        if ($delivery_result['content_type'] === 'area_membros') {
                                            // Importa Helper de criação de senha
                                            if (!function_exists('generate_setup_token') && file_exists(__DIR__ . '/../helpers/password_setup_helper.php')) {
                                                require_once __DIR__ . '/../helpers/password_setup_helper.php';
                                            }
                                            
                                            if ($member_area_password_for_delivery === null && !isset($setup_token)) {
                                                // Verifica se usuário já existe
                                                $stmt_check_user = $pdo->prepare("SELECT id, senha FROM usuarios WHERE usuario = ? AND tipo = 'usuario'");
                                                $stmt_check_user->execute([$customer_email]);
                                                $existing_user = $stmt_check_user->fetch(PDO::FETCH_ASSOC);
                                                
                                                if ($existing_user) {
                                                    // Cliente JÁ TEM conta
                                                    // NÃO gerar senha, apenas garantir acesso (já feito por process_single_product_delivery)
                                                    log_webhook("  Área de Membros: Cliente existente detectado: " . $customer_email . " - Não gerando senha");
                                                    $member_area_password_for_delivery = null; // Não passa senha no email
                                                } else {
                                                    // Cliente NOVO
                                                    // Criar usuário com senha temporária (será substituída quando criar senha via token)
                                                    $temp_password = bin2hex(random_bytes(32));
                                                    $hashed_temp = password_hash($temp_password, PASSWORD_DEFAULT);
                                                    
                                                    try {
                                                        $stmt_insert_user = $pdo->prepare("INSERT INTO usuarios (usuario, nome, senha, tipo) VALUES (?, ?, ?, 'usuario')");
                                                        $stmt_insert_user->execute([$customer_email, $customer_name, $hashed_temp]);
                                                        $new_user_id = $pdo->lastInsertId();
                                                        log_webhook("  Área de Membros: Novo usuário criado (com senha temporária): " . $customer_email . " - ID: " . $new_user_id);
                                                    } catch (PDOException $e) {
                                                        log_webhook("  Área de Membros: ERRO ao criar usuário: " . $e->getMessage());
                                                        $new_user_id = null;
                                                    }
                                                    
                                                    // Gerar token de criação de senha apenas se o usuário foi criado
                                                    if ($new_user_id && function_exists('generate_setup_token')) {
                                                        $setup_token = generate_setup_token($new_user_id);
                                                        log_webhook("  Área de Membros: Token de criação de senha gerado para novo usuário: " . $customer_email);
                                                    } else {
                                                        if (!$new_user_id) {
                                                            log_webhook("  Área de Membros: ERRO - Não foi possível criar usuário");
                                                        } else {
                                                            log_webhook("  Área de Membros: ERRO - Função generate_setup_token não encontrada!");
                                                        }
                                                        $setup_token = null;
                                                    }
                                                    
                                                    $member_area_password_for_delivery = null; // Não passa senha no email
                                                }
                                                
                                                $member_area_login_url = $member_area_login_url_config;
                                            }
                                            $processed_products_for_email[] = $delivery_result;

                                        } else {
                                            $processed_products_for_email[] = $delivery_result;
                                        }
                                    } else {
                                        log_webhook("Entrega falhou para o produto '{$sale['produto_nome']}': {$delivery_result['message']}");
                                    }
                                }

                                if (!empty($processed_products_for_email)) {
                                    log_webhook("EMAIL_DELIVERY: Tentando enviar e-mail consolidado para '$customer_email' com " . count($processed_products_for_email) . " produtos.");
                                    log_webhook("EMAIL_DELIVERY: Conteúdo dos produtos para e-mail: " . print_r($processed_products_for_email, true));
                                    log_webhook("EMAIL_DELIVERY: Senha de Área de Membros gerada (se aplicável): " . ($member_area_password_for_delivery ? 'SIM' : 'NÃO'));
                                    log_webhook("EMAIL_DELIVERY: URL de Login da Área de Membros: " . $member_area_login_url);
                                    log_webhook("EMAIL_DELIVERY: Assunto do e-mail: " . $email_subject);

                                    $email_sent = send_delivery_email_consolidated(
                                        $customer_email,
                                        $customer_name,
                                        $processed_products_for_email,
                                        $member_area_password_for_delivery,
                                        $member_area_login_url,
                                        $email_subject,
                                        $email_html_template,
                                        null, // address_data
                                        isset($setup_token) ? $setup_token : null // setup_token
                                    );
                                    if ($email_sent) {
                                        $stmt_mark_sent = $pdo->prepare("UPDATE vendas SET email_entrega_enviado = 1 WHERE checkout_session_uuid = ?");
                                        $stmt_mark_sent->execute([$checkout_session_uuid]);
                                        log_webhook("Entrega: Todas as vendas da sessão '$checkout_session_uuid' marcadas como e-mail de entrega enviado.");
                                    } else {
                                        log_webhook("Entrega: Falha no envio do e-mail consolidado para '$customer_email'. A venda na sessão '$checkout_session_uuid' NÃO foi marcada como e-mail enviado.");
                                    }
                                } else {
                                    log_webhook("Entrega: Nenhum conteúdo para e-mail consolidado para a sessão '$checkout_session_uuid'.");
                                }
                            } else {
                                log_webhook("Entrega: E-mail consolidado já enviado para a sessão '$checkout_session_uuid'. Ignorando reenvio.");
                            }
                        }
                    } else {
                        log_webhook("ERRO DE LÓGICA: Não foi possível encontrar detalhes do produto/venda para a transação '$transaction_id' e sessão '$checkout_session_uuid'.");
                    }

                } else {
                    log_webhook("ERRO: Falha ao consultar API para transacao_id '$payment_id'. HTTP $http_code. Resposta: $response_body");
                }
            } catch (Exception $e) {
                log_webhook("Exceção no processamento do webhook: " . $e->getMessage() . " File: " . $e->getFile() . " Line: " . $e->getLine());
            }
        } else {
            log_webhook("Notificação Recebida (POST, sem action): Dados inválidos ou tipo não é 'payment'. Ignorando. Data: " . print_r($data, true));
        }

        exit(); // Garante que o script pare após o processamento do webhook
        

    } elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
        // --- Lógica para Requisições GET (API de Notificações para o painel do usuário) ---
        header('Content-Type: application/json');
        
        // Assegura que a sessão está iniciada para acessar $_SESSION
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }

        // Verifica a autenticação do usuário
        if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || !isset($_SESSION['id'])) {
            http_response_code(403);
            ob_clean(); // Limpa o buffer antes de enviar o JSON
            echo json_encode(['error' => 'Acesso não autorizado para a API de notificações.']);
            exit;
        }

        $usuario_id_logado = $_SESSION['id'];
        $action = $_GET['action'] ?? '';

        try {
            if ($action === 'get_unread_count') {
                $stmt = $pdo->prepare("SELECT COUNT(id) FROM notificacoes WHERE usuario_id = ? AND lida = 0");
                $stmt->execute([$usuario_id_logado]);
                $count = $stmt->fetchColumn();
                ob_clean();
                echo json_encode(['success' => true, 'count' => (int)$count]);
                exit;
            }

            if ($action === 'get_recent_notifications') {
                // CORREÇÃO: Formatar a data para o padrão ISO 8601
                $stmt = $pdo->prepare("SELECT id, tipo, mensagem, valor, DATE_FORMAT(data_notificacao, '%Y-%m-%dT%H:%i:%s') AS data_notificacao, lida, link_acao FROM notificacoes WHERE usuario_id = ? ORDER BY data_notificacao DESC LIMIT 10");
                $stmt->execute([$usuario_id_logado]);
                $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
                ob_clean();
                echo json_encode(['success' => true, 'notifications' => $notifications]);
                exit;
            }

            // `mark_all_as_read` is now handled in the POST block when `action` is set.
            // This GET version would not be used.

            if ($action === 'get_live_notifications') {
                $stmt = $pdo->prepare("
                    SELECT 
                        n.id, n.tipo, n.mensagem, n.valor, n.metodo_pagamento, p.nome as produto_nome, p.foto as produto_foto 
                    FROM notificacoes n
                    LEFT JOIN vendas v ON n.venda_id_fk = v.id
                    LEFT JOIN produtos p ON v.produto_id = p.id
                    WHERE n.usuario_id = ? AND n.displayed_live = 0 AND n.tipo IN ('Compra Aprovada', 'Pix Gerado', 'Boleto Gerado')
                    ORDER BY n.data_notificacao ASC
                    LIMIT 5
                ");
                $stmt->execute([$usuario_id_logado]);
                $live_notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);

                // Fetch a *single* `metodo_pagamento` from the `vendas` table
                // For live notifications, if there are multiple products, the method would be the same
                foreach ($live_notifications as &$notification) {
                    if ($notification['venda_id_fk']) {
                        $stmt_method = $pdo->prepare("SELECT metodo_pagamento FROM vendas WHERE id = ? LIMIT 1");
                        $stmt_method->execute([$notification['venda_id_fk']]);
                        $notification['metodo_pagamento'] = $stmt_method->fetchColumn();
                    } else {
                        $notification['metodo_pagamento'] = 'Desconhecido';
                    }
                }
                unset($notification); // Clear reference

                ob_clean();
                echo json_encode(['success' => true, 'live_notifications' => $live_notifications]);
                exit;
            }

            http_response_code(400);
            ob_clean();
            echo json_encode(['error' => 'Ação de API inválida.']);

        } catch (Throwable $e) {
            http_response_code(500);
            error_log('API_NOTIFICATIONS: Erro Fatal na requisição GET: ' . $e->getMessage() . ' no arquivo ' . $e->getFile() . ' na linha ' . $e->getLine());
            ob_clean();
            echo json_encode(['error' => 'Ocorreu um erro interno no servidor da API de notificações. Verifique o arquivo de log.']);
        }
    } else {
        // --- Requisição com método HTTP não suportado ---
        header('Content-Type: application/json');
        http_response_code(405); // Method Not Allowed
        ob_clean();
        echo json_encode(['error' => 'Método HTTP não permitido.']);
        exit;
    }

} catch (Throwable $e) {
    // Captura exceções globais que possam ocorrer antes ou fora dos blocos try-catch específicos.
    header('Content-Type: application/json');
    http_response_code(500);
    error_log('NOTIFICATION_SCRIPT_GLOBAL_ERROR: Erro Fatal: ' . $e->getMessage() . ' no arquivo ' . $e->getFile() . ' na linha ' . $e->getLine());
    ob_clean();
    echo json_encode(['error' => 'Um erro crítico inesperado ocorreu. Verifique os logs do servidor.']);
    exit;
}

// O script já enviou a resposta HTTP apropriada e encerrou a execução explicitamente
// ou o `ob_end_flush()` foi chamado. Se chegarmos aqui, é um fallback seguro.
exit();