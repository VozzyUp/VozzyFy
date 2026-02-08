<?php
// Inicia buffer de saída para capturar qualquer output indesejado
ob_start();

// Desabilita exibição de erros antes de qualquer output
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/process_payment_log.txt');
error_reporting(E_ALL);

// Função para retornar erro JSON de forma segura
function returnJsonError($message, $code = 500)
{
    ob_clean(); // Limpa qualquer output anterior
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['error' => $message]);
    exit;
}

// Função para retornar sucesso JSON
function returnJsonSuccess($data)
{
    ob_clean(); // Limpa qualquer output anterior
    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// Tenta carregar config.php (pode estar na raiz ou em config/)
$config_paths = [
    __DIR__ . '/config.php',
    __DIR__ . '/config/config.php',
    dirname(__DIR__) . '/config/config.php'
];

$config_loaded = false;
foreach ($config_paths as $config_path) {
    if (file_exists($config_path)) {
        try {
            // Captura qualquer output do config.php
            ob_start();
            require $config_path;
            ob_end_clean();
            $config_loaded = true;
            break;
        } catch (Exception $e) {
            ob_end_clean();
            returnJsonError('Erro ao carregar configuração: ' . $e->getMessage(), 500);
        } catch (Error $e) {
            ob_end_clean();
            returnJsonError('Erro fatal ao carregar configuração: ' . $e->getMessage(), 500);
        }
    }
}

if (!$config_loaded) {
    returnJsonError('Arquivo de configuração não encontrado.', 500);
}

// Limpa o buffer inicial
ob_end_clean();

// Define header JSON
header('Content-Type: application/json');

// Inclui o helper da UTMfy
$utmfy_paths = [
    __DIR__ . '/helpers/utmfy_helper.php',
    dirname(__DIR__) . '/helpers/utmfy_helper.php',
    __DIR__ . '/utmfy_helper.php'
];

foreach ($utmfy_paths as $utmfy_path) {
    if (file_exists($utmfy_path)) {
        try {
            ob_start();
            require_once $utmfy_path;
            ob_end_clean();
            break;
        } catch (Exception $e) {
            ob_end_clean();
            error_log('Erro ao carregar utmfy_helper: ' . $e->getMessage());
        } catch (Error $e) {
            ob_end_clean();
            error_log('Erro fatal ao carregar utmfy_helper: ' . $e->getMessage());
        }
    }
}

// Inclui o helper de push para pedidos
$push_pedidos_paths = [
    __DIR__ . '/helpers/push_pedidos_helper.php',
    dirname(__DIR__) . '/helpers/push_pedidos_helper.php',
    __DIR__ . '/push_pedidos_helper.php'
];

foreach ($push_pedidos_paths as $push_path) {
    if (file_exists($push_path)) {
        try {
            ob_start();
            require_once $push_path;
            ob_end_clean();
            break;
        } catch (Exception $e) {
            ob_end_clean();
            error_log('Erro ao carregar push_pedidos_helper: ' . $e->getMessage());
        } catch (Error $e) {
            ob_end_clean();
            error_log('Erro fatal ao carregar push_pedidos_helper: ' . $e->getMessage());
        }
    }
}

// Carrega helpers de segurança e validação
require_once __DIR__ . '/helpers/security_helper.php';
require_once __DIR__ . '/helpers/validation_helper.php';
require_once __DIR__ . '/helpers/webhook_helper.php';

// Carrega helper de disparo centralizado de eventos
if (file_exists(__DIR__ . '/helpers/payment_events_dispatcher.php')) {
    require_once __DIR__ . '/helpers/payment_events_dispatcher.php';
}

// Carrega helper de vendas
if (file_exists(__DIR__ . '/helpers/sales_helper.php')) {
    require_once __DIR__ . '/helpers/sales_helper.php';
}

// Rate limiting para endpoint de pagamento
$client_ip = get_client_ip();
$rate_limit = check_rate_limit_db('payment_process', 10, 60, $client_ip); // 10 requisições por minuto
if (!$rate_limit['allowed']) {
    log_security_event('rate_limit_exceeded_payment', [
        'ip' => $client_ip,
        'reset_at' => $rate_limit['reset_at']
    ]);
    returnJsonError('Muitas requisições. Tente novamente mais tarde.', 429);
}

function log_process($msg)
{
    $log_file = __DIR__ . '/process_payment_log.txt';
    // Usar secure_log ao invés de file_put_contents direto
    if (function_exists('secure_log')) {
        secure_log($log_file, $msg, 'info');
    } else {
        @file_put_contents($log_file, date('Y-m-d H:i:s') . " - " . $msg . "\n", FILE_APPEND);
    }
}

log_process("INÍCIO DO PROCESSAMENTO");

$raw_post_data = file_get_contents('php://input');
log_process("Raw POST data recebido: " . substr($raw_post_data, 0, 200));

$data = json_decode($raw_post_data, true);

if (!$data) {
    log_process("ERRO: Dados inválidos - JSON decode falhou");
    returnJsonError('Dados inválidos.', 400);
}

log_process("Gateway solicitado: " . ($data['gateway'] ?? 'não informado'));

// Campos comuns
$required_fields = ['transaction_amount', 'email', 'name', 'phone', 'product_id'];
foreach ($required_fields as $field) {
    if (empty($data[$field])) {
        returnJsonError("Campo obrigatório ausente: $field", 400);
    }
}

// Validações de dados de pagamento
if (!validate_email($data['email'])) {
    returnJsonError('Email inválido.', 400);
}

// CPF é opcional - validar apenas se fornecido
if (!empty($data['cpf']) && !validate_cpf($data['cpf'])) {
    returnJsonError('CPF inválido.', 400);
}

if (!validate_phone_br($data['phone'])) {
    returnJsonError('Telefone inválido.', 400);
}

if (!validate_transaction_amount($data['transaction_amount'])) {
    returnJsonError('Valor da transação inválido. Deve estar entre R$ 0,01 e R$ 100.000,00.', 400);
}

// Sanitizar inputs
$data['name'] = sanitize_input($data['name'] ?? '');
$data['email'] = filter_var($data['email'], FILTER_SANITIZE_EMAIL);
// CPF é opcional - sanitizar apenas se fornecido
$data['cpf'] = !empty($data['cpf']) ? preg_replace('/[^0-9]/', '', $data['cpf']) : '';
$data['phone'] = preg_replace('/[^0-9]/', '', $data['phone']);

// 1. Descobrir Gateway e Credenciais
$main_product_id = $data['product_id'];
$gateway_choice = $data['gateway'] ?? 'mercadopago';

try {
    $stmt_prod = $pdo->prepare("SELECT usuario_id, nome FROM produtos WHERE id = ?");
    $stmt_prod->execute([$main_product_id]);
    $product_info = $stmt_prod->fetch(PDO::FETCH_ASSOC);
    if (!$product_info)
        throw new Exception("Produto não encontrado.");

    $usuario_id = $product_info['usuario_id'];
    $main_product_name = $product_info['nome'];

    $stmt_user = $pdo->prepare("SELECT mp_access_token, pushinpay_token, efi_client_id, efi_client_secret, efi_certificate_path, efi_pix_key, efi_payee_code, beehive_secret_key, beehive_public_key, hypercash_secret_key, hypercash_public_key, asaas_api_key, asaas_environment, applyfy_public_key, applyfy_secret_key, spacepag_public_key, spacepag_secret_key FROM usuarios WHERE id = ?");
    $stmt_user->execute([$usuario_id]);
    $credentials = $stmt_user->fetch(PDO::FETCH_ASSOC);

    // Log para debug - verificar se credenciais foram buscadas
    log_process("Efí: Usuario ID: $usuario_id");
    if ($credentials) {
        log_process("Efí: Credenciais encontradas no banco");
        log_process("Efí: efi_client_id presente: " . (!empty($credentials['efi_client_id']) ? 'sim (' . substr($credentials['efi_client_id'], 0, 8) . '...)' : 'não'));
        log_process("Efí: efi_client_secret presente: " . (!empty($credentials['efi_client_secret']) ? 'sim' : 'não'));
        log_process("Efí: efi_certificate_path: " . ($credentials['efi_certificate_path'] ?? 'vazio'));
        log_process("Efí: efi_pix_key presente: " . (!empty($credentials['efi_pix_key']) ? 'sim' : 'não'));
    } else {
        log_process("Efí: ERRO - Credenciais não encontradas no banco para usuario_id: $usuario_id");
    }

    // URL Webhook
    $domainName = $_SERVER['HTTP_HOST'];
    $scriptDir = dirname($_SERVER['PHP_SELF']);
    $path = rtrim(str_replace('\\', '/', $scriptDir), '/');

    // Detectar protocolo (HTTPS ou HTTP)
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
        (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) ||
        (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        ? 'https' : 'http';

    // Construir URL do webhook
    $webhook_url = $protocol . "://" . $domainName . $path . '/notification.php';

    // Validar URL do webhook (Mercado Pago exige HTTPS válido)
    if (!filter_var($webhook_url, FILTER_VALIDATE_URL)) {
        log_process("ERRO: URL do webhook inválida: " . $webhook_url);
        // Tentar forçar HTTPS se estiver em produção
        if ($protocol === 'http' && $domainName !== 'localhost' && strpos($domainName, '127.0.0.1') === false) {
            $webhook_url = "https://" . $domainName . $path . '/notification.php';
            log_process("Tentando forçar HTTPS: " . $webhook_url);
        }
    }

    // Validar se é localhost (Mercado Pago não aceita localhost)
    if ($domainName === 'localhost' || strpos($domainName, '127.0.0.1') !== false || strpos($domainName, '::1') !== false) {
        log_process("AVISO: Ambiente local detectado. Mercado Pago pode não aceitar webhook em localhost.");
        log_process("Webhook URL: " . $webhook_url);
        // Em ambiente local, pode ser necessário usar um serviço de túnel (ngrok, etc)
        // ou configurar uma URL válida nas configurações
    }

    // Log da URL final
    log_process("Webhook URL final: " . $webhook_url);

    // URL Obrigado
    $stmt_prod_conf = $pdo->prepare("SELECT checkout_config FROM produtos WHERE id = ?");
    $stmt_prod_conf->execute([$main_product_id]);
    $p_conf = $stmt_prod_conf->fetch(PDO::FETCH_ASSOC);
    $checkout_config = json_decode($p_conf['checkout_config'] ?? '{}', true);

    // Incluir helper de segurança para validação SSRF
    require_once __DIR__ . '/helpers/security_helper.php';

    // Função para validar e limpar URL, removendo caminhos absolutos e validando SSRF
    $clean_redirect_url = function ($url) use ($domainName, $path) {
        if (empty($url)) {
            return '/obrigado.php'; // Sempre usar caminho relativo
        }
        // Se contém caminho absoluto do sistema de arquivos, ignorar
        if (preg_match('/^[A-Z]:[\\\\\/]/i', $url) || strpos($url, 'C:/') !== false || strpos($url, 'C:\\') !== false || strpos($url, 'xampp') !== false || strpos($url, 'htdocs') !== false) {
            return '/obrigado.php'; // Sempre usar caminho relativo
        }
        // Se é uma URL HTTP/HTTPS válida, validar SSRF antes de usar
        if (preg_match('/^https?:\/\//', $url)) {
            $ssrf_validation = validate_url_for_ssrf($url);
            if (!$ssrf_validation['valid']) {
                log_security_event('ssrf_blocked_redirect_url', [
                    'url' => $url,
                    'ip' => get_client_ip(),
                    'error' => $ssrf_validation['error']
                ]);
                return '/obrigado.php'; // Bloquear e usar URL padrão
            }
            return $url;
        }
        // Se começa com /, é um caminho relativo válido
        if (strpos($url, '/') === 0) {
            return $url;
        }
        // Caso contrário, usar caminho relativo padrão
        return '/obrigado.php';
    };

    $redirect_url_raw = $checkout_config['redirectUrl'] ?? '';
    $redirect_url_after_approval = $clean_redirect_url($redirect_url_raw);

    log_process("Webhook URL gerada: " . $webhook_url);
    $checkout_session_uuid = uniqid('checkout_') . bin2hex(random_bytes(8));

    // UTMs
    $utm_parameters = $data['utm_parameters'] ?? [];

    // Determinar payment_method a partir de payment_method_id ou payment_method
    $payment_method_id = $data['payment_method_id'] ?? $data['payment_method'] ?? null;
    $payment_method = null;
    if ($payment_method_id === 'pix' || $payment_method_id === 'Pix') {
        $payment_method = 'Pix';
    } elseif ($payment_method_id === 'ticket' || $payment_method_id === 'Boleto') {
        $payment_method = 'Boleto';
    } elseif (isset($data['payment_method']) && !empty($data['payment_method'])) {
        $payment_method = $data['payment_method'];
    } elseif (isset($data['card_data']) || isset($data['card_token'])) {
        $payment_method = 'Cartão de crédito';
    }

    log_process("Gateway escolhido: $gateway_choice, Payment Method ID: " . ($payment_method_id ?? 'não fornecido') . ", Payment Method: " . ($payment_method ?? 'não determinado'));

    // ==========================================================
    // FLUXO EFÍ
    // ==========================================================
    if ($gateway_choice === 'efi') {
        // Incluir arquivo do gateway Efí
        require_once __DIR__ . '/gateways/efi.php';

        // Remover espaços em branco e caracteres invisíveis das credenciais
        $client_id = trim($credentials['efi_client_id'] ?? '');
        $client_secret = trim($credentials['efi_client_secret'] ?? '');
        $certificate_path = trim($credentials['efi_certificate_path'] ?? '');
        $pix_key = trim($credentials['efi_pix_key'] ?? '');

        // Log detalhado antes de processar
        error_log("Efí: Iniciando processamento de pagamento");
        error_log("Efí: Client ID presente: " . (!empty($client_id) ? 'sim (tamanho: ' . strlen($client_id) . ')' : 'não'));
        error_log("Efí: Client Secret presente: " . (!empty($client_secret) ? 'sim (tamanho: ' . strlen($client_secret) . ')' : 'não'));
        error_log("Efí: Caminho certificado (relativo): " . $certificate_path);
        error_log("Efí: Chave Pix presente: " . (!empty($pix_key) ? 'sim' : 'não'));

        if (empty($client_id) || empty($client_secret) || empty($certificate_path) || empty($pix_key)) {
            $missing = [];
            if (empty($client_id))
                $missing[] = 'Client ID';
            if (empty($client_secret))
                $missing[] = 'Client Secret';
            if (empty($certificate_path))
                $missing[] = 'Caminho do Certificado';
            if (empty($pix_key))
                $missing[] = 'Chave Pix';
            error_log("Efí: Credenciais faltando: " . implode(', ', $missing));
            throw new Exception("Credenciais Efí não configuradas completamente. Faltando: " . implode(', ', $missing));
        }

        // Validar se certificado existe
        // Normalizar caminho (Windows usa \, mas precisamos de / para cURL)
        $certificate_path_normalized = str_replace('\\', '/', $certificate_path);
        $full_cert_path = __DIR__ . '/' . $certificate_path_normalized;
        // Normalizar também o caminho completo para Windows
        $full_cert_path = str_replace('\\', '/', $full_cert_path);
        error_log("Efí: Caminho completo do certificado (normalizado): " . $full_cert_path);

        if (!file_exists($full_cert_path)) {
            error_log("Efí: Certificado não encontrado no caminho: " . $full_cert_path);
            error_log("Efí: Diretório atual: " . __DIR__);
            error_log("Efí: Caminho relativo do banco: " . $certificate_path);
            throw new Exception("Certificado Efí não encontrado em: " . $certificate_path);
        }

        error_log("Efí: Certificado encontrado, obtendo token de acesso...");

        // Obter access token
        $token_data = efi_get_access_token($client_id, $client_secret, $full_cert_path);
        if (!$token_data) {
            error_log("Efí: Falha ao obter token de acesso");
            throw new Exception("Erro ao obter token de acesso Efí (401 - Invalid credentials). Verifique: 1) Se o Client ID e Client Secret estão corretos na conta Efí, 2) Se o certificado P12 corresponde a essas credenciais, 3) Se as credenciais estão ativas. Consulte os logs para mais detalhes.");
        }

        error_log("Efí: Token obtido com sucesso");

        $access_token = $token_data['access_token'];

        // Criar cobrança Pix
        // Efí exige CPF ou CNPJ - validar CPF antes de criar cobrança
        $cpf_limpo = preg_replace('/[^0-9]/', '', $data['cpf'] ?? '');
        if (empty($cpf_limpo) || strlen($cpf_limpo) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf_limpo)) {
            throw new Exception("CPF é obrigatório para pagamento via Efí. Por favor, informe um CPF válido.");
        }

        $payer_data = [
            'name' => $data['name'],
            'cpf' => $cpf_limpo,
            'email' => $data['email']
        ];

        $pix_result = efi_create_pix_charge(
            $access_token,
            (float) $data['transaction_amount'],
            $pix_key,
            $payer_data,
            'Compra: ' . $main_product_name,
            60, // 60 minutos de expiração
            $full_cert_path // Passar certificado para mutual TLS
        );

        if (!$pix_result || !isset($pix_result['txid'])) {
            // Verificar se o erro foi relacionado a CPF inválido
            if (isset($pix_result['error']) && $pix_result['error']) {
                $error_msg = $pix_result['message'] ?? 'Erro ao criar cobrança Pix na Efí';
                // Mensagens específicas para CPF inválido
                if (stripos($error_msg, 'cpf') !== false || stripos($error_msg, 'documento') !== false) {
                    throw new Exception("CPF inválido. Por favor, verifique o CPF informado.");
                }
                throw new Exception($error_msg);
            }
            throw new Exception("Erro ao criar cobrança Pix na Efí. Verifique os logs para mais detalhes.");
        }

        $payment_id = $pix_result['txid'];
        $status = 'pending';

        // Salva Venda
        save_sales($pdo, $data, $main_product_id, $payment_id, $status, 'Pix', $checkout_session_uuid, $utm_parameters);

        // --- DISPARO IMEDIATO DE EVENTOS (Status: Pending) ---
        // Usando função centralizada para garantir consistência
        if (function_exists('dispatch_payment_events')) {
            $custom_event_data = [
                'transacao_id' => $payment_id,
                'usuario_id' => $usuario_id,
                'produto_id' => $main_product_id,
                'produto_nome' => $main_product_name,
                'valor_total_compra' => $data['transaction_amount'],
                'comprador_nome' => $data['name'],
                'comprador_email' => $data['email'],
                'comprador_telefone' => $data['phone'],
                'comprador_cpf' => !empty($data['cpf']) ? $data['cpf'] : null,
                'metodo_pagamento' => 'Pix',
                'data_venda' => date('Y-m-d H:i:s'),
                'utm_source' => $utm_parameters['utm_source'] ?? null,
                'utm_campaign' => $utm_parameters['utm_campaign'] ?? null,
                'utm_medium' => $utm_parameters['utm_medium'] ?? null,
                'utm_content' => $utm_parameters['utm_content'] ?? null,
                'utm_term' => $utm_parameters['utm_term'] ?? null,
                'src' => $utm_parameters['src'] ?? null,
                'sck' => $utm_parameters['sck'] ?? null
            ];
            dispatch_payment_events($pdo, $payment_id, 'pending', 'Efí Pix', $custom_event_data);
        }
        // -------------------------------------------------------------

        returnJsonSuccess([
            'status' => 'pix_created',
            'pix_data' => [
                'qr_code_base64' => $pix_result['qr_code_base64'] ?? null,
                'qr_code' => $pix_result['qr_code'] ?? '',
                'payment_id' => $payment_id
            ],
            'redirect_url_after_approval' => $redirect_url_after_approval . '?payment_id=' . $payment_id
        ]);
    }

    // ==========================================================
    // FLUXO PUSHINPAY
    // ==========================================================
    elseif ($gateway_choice === 'pushinpay') {

        $token = $credentials['pushinpay_token'] ?? '';
        if (empty($token))
            throw new Exception("Token PushinPay não configurado.");

        $amount_cents = (int) (round((float) $data['transaction_amount'], 2) * 100);
        $payer_data = [
            "name" => $data['name'],
            "email" => $data['email']
        ];

        // CPF é opcional para PushinPay - só adicionar se fornecido
        $cpf_limpo = preg_replace('/[^0-9]/', '', $data['cpf'] ?? '');
        if (!empty($cpf_limpo) && strlen($cpf_limpo) === 11) {
            $payer_data["document"] = $cpf_limpo;
        }

        $payload = [
            "value" => $amount_cents,
            "webhook_url" => $webhook_url,
            "payer" => $payer_data
        ];

        $ch = curl_init('https://api.pushinpay.com.br/api/pix/cashIn');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json'
        ]);

        $response = curl_exec($ch);
        $curl_error = curl_error($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        log_process("PushinPay Response HTTP Code: $http_code");
        log_process("PushinPay Response: " . substr($response, 0, 500));

        if ($curl_error) {
            log_process("PushinPay cURL Error: " . $curl_error);
            throw new Exception("Erro de conexão com PushinPay: " . $curl_error);
        }

        $res_data = json_decode($response, true);

        if ($http_code >= 200 && $http_code < 300 && isset($res_data['qr_code_base64'])) {
            $payment_id = $res_data['id'] ?? null;
            if (!$payment_id) {
                log_process("PushinPay: Resposta sem ID de pagamento");
                throw new Exception("Resposta inválida da API PushinPay: ID não encontrado");
            }

            $status = 'pending';

            // Salva Venda
            save_sales($pdo, $data, $main_product_id, $payment_id, $status, 'Pix', $checkout_session_uuid, $utm_parameters);

            // --- DISPARO IMEDIATO DE EVENTOS (Status: Pending) ---
            // Usando função centralizada para garantir consistência
            if (function_exists('dispatch_payment_events')) {
                $custom_event_data = [
                    'transacao_id' => $payment_id,
                    'usuario_id' => $usuario_id,
                    'produto_id' => $main_product_id,
                    'produto_nome' => $main_product_name,
                    'valor_total_compra' => $data['transaction_amount'],
                    'comprador_nome' => $data['name'],
                    'comprador_email' => $data['email'],
                    'comprador_telefone' => $data['phone'],
                    'comprador_cpf' => !empty($data['cpf']) ? $data['cpf'] : null,
                    'metodo_pagamento' => 'Pix',
                    'data_venda' => date('Y-m-d H:i:s'),
                    'utm_source' => $utm_parameters['utm_source'] ?? null,
                    'utm_campaign' => $utm_parameters['utm_campaign'] ?? null,
                    'utm_medium' => $utm_parameters['utm_medium'] ?? null,
                    'utm_content' => $utm_parameters['utm_content'] ?? null,
                    'utm_term' => $utm_parameters['utm_term'] ?? null,
                    'src' => $utm_parameters['src'] ?? null,
                    'sck' => $utm_parameters['sck'] ?? null
                ];
                dispatch_payment_events($pdo, $payment_id, 'pending', 'PushinPay Pix', $custom_event_data);
            }
            // -------------------------------------------------------------

            returnJsonSuccess([
                'status' => 'pix_created',
                'pix_data' => [
                    'qr_code_base64' => $res_data['qr_code_base64'],
                    'qr_code' => $res_data['qr_code'] ?? '',
                    'payment_id' => $payment_id
                ],
                'redirect_url_after_approval' => $redirect_url_after_approval . '?payment_id=' . $payment_id
            ]);

        } else {
            $error_msg = "Erro ao processar pagamento";
            if (isset($res_data['message'])) {
                $error_msg = $res_data['message'];
            } elseif (isset($res_data['error'])) {
                $error_msg = is_array($res_data['error']) ? implode(', ', $res_data['error']) : $res_data['error'];
            } elseif (!empty($response)) {
                $error_msg = "Resposta inesperada: " . substr($response, 0, 200);
            }

            log_process("PushinPay Error ($http_code): " . $error_msg);
            throw new Exception("PushinPay Error ($http_code): " . $error_msg);
        }
    }

    // ==========================================================
    // FLUXO ASAAS PIX
    // ==========================================================
    elseif ($gateway_choice === 'asaas' && $payment_method === 'Pix') {
        require_once __DIR__ . '/gateways/asaas.php';

        $api_key = trim($credentials['asaas_api_key'] ?? '');
        $environment = trim($credentials['asaas_environment'] ?? 'sandbox');

        // Validar credenciais
        if (empty($api_key)) {
            throw new Exception("Credenciais Asaas não configuradas.");
        }

        log_process("Asaas: Iniciando criação de pagamento Pix");
        log_process("Asaas: Ambiente: $environment");

        // Validar CPF (obrigatório para Asaas Pix)
        $cpf = preg_replace('/[^0-9]/', '', $data['cpf'] ?? '');
        if (empty($cpf) || strlen($cpf) !== 11) {
            throw new Exception("CPF inválido. Por favor, informe um CPF válido com 11 dígitos.");
        }

        // Criar pagamento Pix
        $pix_result = asaas_create_pix_payment(
            $api_key,
            (float) $data['transaction_amount'],
            [
                'name' => $data['name'],
                'email' => $data['email'],
                'cpfCnpj' => $cpf,
                'phone' => preg_replace('/[^0-9]/', '', $data['phone']),
                'postalCode' => preg_replace('/[^0-9]/', '', $data['cep'] ?? ''),
                'addressNumber' => $data['numero'] ?? '',
                'addressComplement' => $data['complemento'] ?? null
            ],
            'Compra: ' . $main_product_name,
            date('Y-m-d', strtotime('+1 day')),
            $webhook_url,
            $environment
        );

        if (!$pix_result || (isset($pix_result['error']) && $pix_result['error'])) {
            $error_message = $pix_result['message'] ?? 'Erro ao criar pagamento Pix no Asaas. Verifique os logs para mais detalhes.';
            log_process("Asaas: Erro ao criar pagamento Pix - " . $error_message);
            throw new Exception($error_message);
        }

        // Buscar QR Code se não veio na resposta
        if (empty($pix_result['qr_code_base64'])) {
            $qr_data = asaas_get_pix_qr_code($api_key, $pix_result['payment_id'], $environment);
            if ($qr_data) {
                $pix_result['qr_code_base64'] = $qr_data['qr_code_base64'] ?? null;
                $pix_result['qr_code'] = $qr_data['qr_code'] ?? '';
            }
        }

        $payment_id = $pix_result['payment_id'];
        $status = 'pending';

        // Salvar venda
        save_sales($pdo, $data, $main_product_id, $payment_id, $status, 'Pix', $checkout_session_uuid, $utm_parameters);

        // Disparar eventos
        if (function_exists('dispatch_payment_events')) {
            $custom_event_data = [
                'transacao_id' => $payment_id,
                'usuario_id' => $usuario_id,
                'produto_id' => $main_product_id,
                'produto_nome' => $main_product_name,
                'valor_total_compra' => $data['transaction_amount'],
                'comprador_nome' => $data['name'],
                'comprador_email' => $data['email'],
                'comprador_telefone' => $data['phone'],
                'comprador_cpf' => !empty($data['cpf']) ? $data['cpf'] : null,
                'metodo_pagamento' => 'Pix',
                'data_venda' => date('Y-m-d H:i:s'),
                'utm_source' => $utm_parameters['utm_source'] ?? null,
                'utm_campaign' => $utm_parameters['utm_campaign'] ?? null,
                'utm_medium' => $utm_parameters['utm_medium'] ?? null,
                'utm_content' => $utm_parameters['utm_content'] ?? null,
                'utm_term' => $utm_parameters['utm_term'] ?? null,
                'src' => $utm_parameters['src'] ?? null,
                'sck' => $utm_parameters['sck'] ?? null
            ];
            dispatch_payment_events($pdo, $payment_id, 'pending', 'Asaas Pix', $custom_event_data);
        }

        returnJsonSuccess([
            'status' => 'pix_created',
            'pix_data' => [
                'qr_code_base64' => $pix_result['qr_code_base64'] ?? null,
                'qr_code' => $pix_result['qr_code'] ?? '',
                'payment_id' => $payment_id
            ],
            'redirect_url_after_approval' => $redirect_url_after_approval . '?payment_id=' . $payment_id
        ]);
    }

    // ==========================================================
    // FLUXO BEEHIVE
    // ==========================================================
    elseif ($gateway_choice === 'beehive') {
        require_once __DIR__ . '/gateways/beehive.php';

        $secret_key = $credentials['beehive_secret_key'] ?? '';
        $public_key = $credentials['beehive_public_key'] ?? '';

        // Validações backend
        if (empty($secret_key) || empty($public_key)) {
            throw new Exception("Credenciais Beehive não configuradas.");
        }

        if (empty($data['card_token'])) {
            log_process("Beehive: Token do cartão não fornecido no POST");
            throw new Exception("Token do cartão não fornecido.");
        }

        log_process("Beehive: Token recebido (primeiros 20 chars): " . substr($data['card_token'], 0, 20) . "... (tamanho: " . strlen($data['card_token']) . ")");

        // Validar CPF
        $cpf = preg_replace('/[^0-9]/', '', $data['cpf'] ?? '');
        if (strlen($cpf) !== 11) {
            throw new Exception("CPF inválido.");
        }

        // Validar email
        if (!filter_var($data['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Email inválido.");
        }

        // Validar valor
        $amount = (float) ($data['transaction_amount'] ?? 0);
        if ($amount <= 0) {
            throw new Exception("Valor inválido.");
        }

        // Criar pagamento
        // get_client_ip() já está disponível via require_once do beehive.php acima (linha 417)
        $card_data = $data['card_data'] ?? null; // Dados do cartão do frontend
        $client_ip = get_client_ip(); // Usar função helper para capturar IP real
        $payment_result = beehive_create_payment(
            $secret_key,
            $public_key,
            $amount,
            $data['card_token'] ?? '',
            [
                'name' => $data['name'],
                'email' => $data['email'],
                'cpf' => $cpf,
                'phone' => preg_replace('/[^0-9]/', '', $data['phone'] ?? '')
            ],
            'Compra: ' . $main_product_name,
            $webhook_url,
            $card_data, // Dados do cartão
            $client_ip // IP do cliente
        );

        if (!$payment_result || (isset($payment_result['error']) && $payment_result['error'])) {
            $error_message = $payment_result['message'] ?? 'Erro ao processar pagamento Beehive.';
            log_process("Beehive Error: " . $error_message);
            throw new Exception($error_message);
        }

        $status = $payment_result['status']; // 'approved', 'pending', 'rejected'
        $payment_id = $payment_result['payment_id'];
        $metodo = 'Cartão de crédito';

        // Salvar venda
        save_sales($pdo, $data, $main_product_id, $payment_id, $status, $metodo, $checkout_session_uuid, $utm_parameters);

        // Disparar eventos usando função centralizada
        if (function_exists('dispatch_payment_events')) {
            $custom_event_data = [
                'transacao_id' => $payment_id,
                'usuario_id' => $usuario_id,
                'produto_id' => $main_product_id,
                'produto_nome' => $main_product_name,
                'valor_total_compra' => $amount,
                'comprador_nome' => $data['name'],
                'comprador_email' => $data['email'],
                'comprador_telefone' => $data['phone'],
                'comprador_cpf' => !empty($data['cpf']) ? $data['cpf'] : null,
                'metodo_pagamento' => $metodo,
                'data_venda' => date('Y-m-d H:i:s'),
                'utm_source' => $utm_parameters['utm_source'] ?? null,
                'utm_campaign' => $utm_parameters['utm_campaign'] ?? null,
                'utm_medium' => $utm_parameters['utm_medium'] ?? null,
                'utm_content' => $utm_parameters['utm_content'] ?? null,
                'utm_term' => $utm_parameters['utm_term'] ?? null,
                'src' => $utm_parameters['src'] ?? null,
                'sck' => $utm_parameters['sck'] ?? null
            ];
            dispatch_payment_events($pdo, $payment_id, $status, 'Beehive', $custom_event_data);
        }

        // Retornar resposta
        $response_data = [
            'status' => $status,
            'payment_id' => $payment_id
        ];

        if ($status === 'approved') {
            $response_data['redirect_url'] = $redirect_url_after_approval . '?payment_id=' . $payment_id;
        }

        returnJsonSuccess($response_data);
    }

    // ==========================================================
    // FLUXO HYPERCASH
    // ==========================================================
    elseif ($gateway_choice === 'hypercash') {
        require_once __DIR__ . '/gateways/hypercash.php';

        $secret_key = $credentials['hypercash_secret_key'] ?? '';
        $public_key = $credentials['hypercash_public_key'] ?? '';

        // Validações backend
        if (empty($secret_key) || empty($public_key)) {
            throw new Exception("Credenciais Hypercash não configuradas.");
        }

        if (empty($data['card_token'])) {
            log_process("Hypercash: Token do cartão não fornecido no POST");
            throw new Exception("Token do cartão não fornecido.");
        }

        log_process("Hypercash: Token recebido (primeiros 20 chars): " . substr($data['card_token'], 0, 20) . "... (tamanho: " . strlen($data['card_token']) . ")");

        // Validar CPF
        $cpf = preg_replace('/[^0-9]/', '', $data['cpf'] ?? '');
        if (strlen($cpf) !== 11) {
            throw new Exception("CPF inválido.");
        }

        // Validar email
        if (!filter_var($data['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Email inválido.");
        }

        // Validar valor
        $amount = (float) ($data['transaction_amount'] ?? 0);
        if ($amount <= 0) {
            throw new Exception("Valor inválido.");
        }

        // Criar pagamento
        // hypercash_get_client_ip() já está disponível via require_once do hypercash.php acima
        $card_data = $data['card_data'] ?? null; // Dados do cartão do frontend
        $client_ip = hypercash_get_client_ip(); // Usar função helper para capturar IP real
        $payment_result = hypercash_create_payment(
            $secret_key,
            $public_key,
            $amount,
            $data['card_token'] ?? '',
            [
                'name' => $data['name'],
                'email' => $data['email'],
                'cpf' => $cpf,
                'phone' => preg_replace('/[^0-9]/', '', $data['phone'] ?? '')
            ],
            'Compra: ' . $main_product_name,
            $webhook_url,
            $card_data, // Dados do cartão
            $client_ip // IP do cliente
        );

        if (!$payment_result || (isset($payment_result['error']) && $payment_result['error'])) {
            $error_message = $payment_result['message'] ?? 'Erro ao processar pagamento Hypercash.';
            log_process("Hypercash Error: " . $error_message);
            throw new Exception($error_message);
        }

        $status = $payment_result['status']; // 'approved', 'pending', 'rejected'
        $payment_id = $payment_result['payment_id'];
        $metodo = 'Cartão de crédito';

        // Salvar venda
        save_sales($pdo, $data, $main_product_id, $payment_id, $status, $metodo, $checkout_session_uuid, $utm_parameters);

        // Disparar eventos usando função centralizada
        if (function_exists('dispatch_payment_events')) {
            $custom_event_data = [
                'transacao_id' => $payment_id,
                'usuario_id' => $usuario_id,
                'produto_id' => $main_product_id,
                'produto_nome' => $main_product_name,
                'valor_total_compra' => $amount,
                'comprador_nome' => $data['name'],
                'comprador_email' => $data['email'],
                'comprador_telefone' => $data['phone'],
                'comprador_cpf' => !empty($data['cpf']) ? $data['cpf'] : null,
                'metodo_pagamento' => $metodo,
                'data_venda' => date('Y-m-d H:i:s'),
                'utm_source' => $utm_parameters['utm_source'] ?? null,
                'utm_campaign' => $utm_parameters['utm_campaign'] ?? null,
                'utm_medium' => $utm_parameters['utm_medium'] ?? null,
                'utm_content' => $utm_parameters['utm_content'] ?? null,
                'utm_term' => $utm_parameters['utm_term'] ?? null,
                'src' => $utm_parameters['src'] ?? null,
                'sck' => $utm_parameters['sck'] ?? null
            ];
            dispatch_payment_events($pdo, $payment_id, $status, 'Hypercash', $custom_event_data);
        }

        // Retornar resposta
        $response_data = [
            'status' => $status,
            'payment_id' => $payment_id
        ];

        if ($status === 'approved') {
            $response_data['redirect_url'] = $redirect_url_after_approval . '?payment_id=' . $payment_id;
        } elseif ($status === 'pending') {
            // Para pending, redirecionar para página de aguardando processamento
            $response_data['redirect_url'] = '/aguardando.php?payment_id=' . $payment_id;
        }

        returnJsonSuccess($response_data);
    }

    // ==========================================================
    // FLUXO EFÍ CARTÃO
    // ==========================================================
    elseif ($gateway_choice === 'efi_card') {
        log_process("Efí Cartão: Iniciando processamento");
        try {
            require_once __DIR__ . '/gateways/efi.php';
            log_process("Efí Cartão: Arquivo efi.php carregado com sucesso");
        } catch (Exception $e) {
            log_process("Efí Cartão: Erro ao carregar efi.php: " . $e->getMessage());
            throw new Exception("Erro ao carregar gateway Efí: " . $e->getMessage());
        }

        $client_id = trim($credentials['efi_client_id'] ?? '');
        $client_secret = trim($credentials['efi_client_secret'] ?? '');
        $certificate_path = trim($credentials['efi_certificate_path'] ?? '');

        // Validações backend
        if (empty($client_id) || empty($client_secret) || empty($certificate_path)) {
            throw new Exception("Credenciais Efí não configuradas completamente.");
        }

        if (empty($data['payment_token'])) {
            log_process("Efí Cartão: Payment token não fornecido no POST");
            throw new Exception("Payment token não fornecido.");
        }

        log_process("Efí Cartão: Payment token recebido (primeiros 30 chars): " . substr($data['payment_token'], 0, 30) . "... (tamanho: " . strlen($data['payment_token']) . ")");

        // Validar CPF
        $cpf = preg_replace('/[^0-9]/', '', $data['cpf'] ?? '');
        if (strlen($cpf) !== 11) {
            throw new Exception("CPF inválido.");
        }

        // Validar email
        if (!filter_var($data['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Email inválido.");
        }

        // Validar valor
        $amount = (float) ($data['transaction_amount'] ?? 0);
        if ($amount <= 0) {
            throw new Exception("Valor inválido.");
        }

        // Obter access token
        $full_cert_path = __DIR__ . '/' . str_replace('\\', '/', $certificate_path);
        log_process("Efí Cartão: Caminho completo do certificado: $full_cert_path");
        log_process("Efí Cartão: Certificado existe: " . (file_exists($full_cert_path) ? 'sim' : 'não'));

        if (!file_exists($full_cert_path)) {
            log_process("Efí Cartão: ERRO - Certificado não encontrado em: $full_cert_path");
            throw new Exception("Certificado Efí não encontrado. Verifique o caminho do certificado.");
        }

        log_process("Efí Cartão: Obtendo access token da API de Cobranças...");
        $token_data = efi_get_charges_access_token($client_id, $client_secret, $full_cert_path);

        if (!$token_data || !isset($token_data['access_token'])) {
            log_process("Efí Cartão: Erro ao obter access token");
            log_process("Efí Cartão: token_data: " . json_encode($token_data));
            throw new Exception("Erro ao autenticar com Efí. Verifique as credenciais.");
        }

        log_process("Efí Cartão: Access token obtido com sucesso");

        // Obter número de parcelas (padrão: 1)
        $installments = (int) ($data['installments'] ?? 1);
        if ($installments < 1 || $installments > 12) {
            $installments = 1;
        }

        log_process("Efí Cartão: Criando cobrança - Valor: $amount, Parcelas: $installments");

        // Criar cobrança
        $payment_result = efi_create_card_charge(
            $token_data['access_token'],
            $amount,
            $data['payment_token'],
            [
                'name' => $data['name'],
                'email' => $data['email'],
                'cpf' => $cpf,
                'phone' => $data['phone']
            ],
            'Compra: ' . $main_product_name,
            $webhook_url,
            $full_cert_path,
            $installments
        );

        if (!$payment_result || (isset($payment_result['error']) && $payment_result['error'])) {
            $error_message = $payment_result['message'] ?? 'Erro ao processar pagamento Efí.';
            log_process("Efí Cartão Error: " . $error_message);
            if (isset($payment_result['http_code'])) {
                log_process("Efí Cartão HTTP Code: " . $payment_result['http_code']);
            }
            if (isset($payment_result['error_data'])) {
                log_process("Efí Cartão Error Data: " . json_encode($payment_result['error_data']));
            }
            throw new Exception($error_message);
        }

        log_process("Efí Cartão: Payment result recebido - status: " . ($payment_result['status'] ?? 'não definido') . ", charge_id: " . ($payment_result['charge_id'] ?? 'não definido'));

        if (!isset($payment_result['status']) || !isset($payment_result['charge_id'])) {
            log_process("Efí Cartão: Resposta inválida - payment_result: " . json_encode($payment_result));
            throw new Exception("Resposta inválida da API Efí.");
        }

        $status = $payment_result['status']; // 'approved', 'pending', 'rejected'
        $payment_id = $payment_result['charge_id'];
        $metodo = 'Cartão de crédito';

        // CORREÇÃO: Se o status inicial for 'pending', fazer múltiplas verificações na API
        // A API EFI pode retornar 'unpaid' na criação mesmo quando aprovado na hora
        // Fazer 3 tentativas com delays progressivos (2s, 4s, 6s)
        if ($status === 'pending') {
            log_process("Efí Cartão: Status inicial é 'pending', fazendo verificações imediatas na API...");

            $max_attempts = 3;
            $delays = [2, 4, 6]; // Delays em segundos

            for ($attempt = 0; $attempt < $max_attempts; $attempt++) {
                if ($attempt > 0) {
                    sleep($delays[$attempt - 1]);
                }

                log_process("Efí Cartão: Tentativa " . ($attempt + 1) . "/$max_attempts de verificação imediata...");
                $status_check = efi_get_card_charge_status($token_data['access_token'], $payment_id, $full_cert_path);

                if ($status_check && isset($status_check['status'])) {
                    $status_checked = $status_check['status'];
                    log_process("Efí Cartão: Status verificado (tentativa " . ($attempt + 1) . "): " . $status_checked . " | status_raw: " . ($status_check['status_raw'] ?? 'N/A'));

                    if ($status_checked === 'approved' || $status_checked === 'rejected') {
                        $status = $status_checked;
                        log_process("Efí Cartão: Status atualizado de 'pending' para '" . $status . "' após verificação imediata (tentativa " . ($attempt + 1) . ")");
                        break; // Sair do loop se encontrou status definitivo
                    }
                } else {
                    log_process("Efí Cartão: ERRO na tentativa " . ($attempt + 1) . " - Não foi possível verificar status. status_check: " . json_encode($status_check));
                }
            }

            if ($status === 'pending') {
                log_process("Efí Cartão: Após $max_attempts tentativas, status ainda é 'pending'. Continuará verificando via polling.");
            }
        }

        log_process("Efí Cartão: Salvando venda - payment_id: $payment_id, status: $status");

        // Salvar venda
        try {
            save_sales($pdo, $data, $main_product_id, $payment_id, $status, $metodo, $checkout_session_uuid, $utm_parameters);
            log_process("Efí Cartão: Venda salva com sucesso");
        } catch (Exception $e) {
            log_process("Efí Cartão: Erro ao salvar venda: " . $e->getMessage());
            throw new Exception("Erro ao salvar venda: " . $e->getMessage());
        }

        // Disparar eventos usando função centralizada
        if (function_exists('dispatch_payment_events')) {
            try {
                log_process("Efí Cartão: Disparando eventos via função centralizada...");
                $custom_event_data = [
                    'transacao_id' => $payment_id,
                    'usuario_id' => $usuario_id,
                    'produto_id' => $main_product_id,
                    'produto_nome' => $main_product_name,
                    'valor_total_compra' => $amount,
                    'comprador_nome' => $data['name'],
                    'comprador_email' => $data['email'],
                    'comprador_telefone' => $data['phone'],
                    'comprador_cpf' => !empty($data['cpf']) ? $data['cpf'] : null,
                    'metodo_pagamento' => $metodo,
                    'data_venda' => date('Y-m-d H:i:s'),
                    'utm_source' => $utm_parameters['utm_source'] ?? null,
                    'utm_campaign' => $utm_parameters['utm_campaign'] ?? null,
                    'utm_medium' => $utm_parameters['utm_medium'] ?? null,
                    'utm_content' => $utm_parameters['utm_content'] ?? null,
                    'utm_term' => $utm_parameters['utm_term'] ?? null,
                    'src' => $utm_parameters['src'] ?? null,
                    'sck' => $utm_parameters['sck'] ?? null
                ];
                dispatch_payment_events($pdo, $payment_id, $status, 'Efí Cartão', $custom_event_data);
                log_process("Efí Cartão: Eventos disparados com sucesso via função centralizada");
            } catch (Exception $e) {
                log_process("Efí Cartão: Erro ao disparar eventos (não crítico): " . $e->getMessage());
                // Não lança exceção aqui, pois o pagamento já foi processado
            }
        }

        log_process("Efí Cartão: Preparando resposta JSON...");

        // Retornar resposta
        $response_data = [
            'status' => $status,
            'payment_id' => $payment_id
        ];

        if ($status === 'approved') {
            $response_data['redirect_url'] = $redirect_url_after_approval . '?payment_id=' . $payment_id;
        } elseif ($status === 'pending') {
            // Para pending, redirecionar para página de aguardando processamento
            $response_data['redirect_url'] = '/aguardando.php?payment_id=' . $payment_id;
        }

        log_process("Efí Cartão: Retornando resposta JSON - status: $status, payment_id: $payment_id");
        returnJsonSuccess($response_data);
    }

    // ==========================================================
    // FLUXO ASAAS CARTÃO
    // ==========================================================
    elseif ($gateway_choice === 'asaas' && $payment_method === 'Cartão de crédito') {
        require_once __DIR__ . '/gateways/asaas.php';

        $api_key = trim($credentials['asaas_api_key'] ?? '');
        $environment = trim($credentials['asaas_environment'] ?? 'sandbox');

        // Validar credenciais
        if (empty($api_key)) {
            throw new Exception("Credenciais Asaas não configuradas.");
        }

        log_process("Asaas Cartão: Iniciando processamento");
        log_process("Asaas Cartão: Ambiente: $environment");

        // Validar CPF
        $cpf = preg_replace('/[^0-9]/', '', $data['cpf'] ?? '');
        if (strlen($cpf) !== 11) {
            throw new Exception("CPF inválido.");
        }

        // Validar email
        if (!filter_var($data['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Email inválido.");
        }

        // Validar valor
        $amount = (float) ($data['transaction_amount'] ?? 0);
        if ($amount <= 0) {
            throw new Exception("Valor inválido.");
        }

        // Obter número de parcelas (padrão: 1)
        $installments = (int) ($data['installments'] ?? 1);
        if ($installments < 1 || $installments > 12) {
            $installments = 1;
        }

        // Preparar dados do cartão
        $card_data = null;
        $credit_card_token = null;

        if (!empty($data['card_token'])) {
            // Se houver token, usar tokenização
            $credit_card_token = $data['card_token'];
        } elseif (!empty($data['card_data'])) {
            // Se houver dados do cartão, usar diretamente
            $card_data = $data['card_data'];
        } else {
            throw new Exception("Dados do cartão ou token não fornecidos.");
        }

        // Criar pagamento com cartão
        // Asaas exige CEP - buscar de diferentes fontes possíveis
        $cep = preg_replace('/[^0-9]/', '', $data['cep'] ?? '');
        if (empty($cep) && isset($data['address']['cep'])) {
            $cep = preg_replace('/[^0-9]/', '', $data['address']['cep']);
        }

        $payment_result = asaas_create_card_payment(
            $api_key,
            $amount,
            $card_data,
            [
                'name' => $data['name'],
                'email' => $data['email'],
                'cpfCnpj' => $cpf,
                'phone' => preg_replace('/[^0-9]/', '', $data['phone'] ?? ''),
                'postalCode' => $cep,
                'cep' => $cep,
                'addressNumber' => $data['numero'] ?? ($data['address']['numero'] ?? ''),
                'addressComplement' => $data['complemento'] ?? ($data['address']['complemento'] ?? null),
                'address' => $data['address'] ?? []
            ],
            'Compra: ' . $main_product_name,
            date('Y-m-d'),
            $webhook_url,
            $installments,
            $credit_card_token,
            $environment
        );

        if (!$payment_result || (isset($payment_result['error']) && $payment_result['error'])) {
            $error_message = $payment_result['message'] ?? 'Erro ao processar pagamento Asaas.';
            log_process("Asaas Cartão Error: " . $error_message);
            throw new Exception($error_message);
        }

        $status = $payment_result['status'] ?? 'pending';
        $payment_id = $payment_result['payment_id'];
        $metodo = 'Cartão de crédito';

        log_process("Asaas Cartão: Salvando venda - payment_id: $payment_id, status: $status");

        // Salvar venda
        try {
            save_sales($pdo, $data, $main_product_id, $payment_id, $status, $metodo, $checkout_session_uuid, $utm_parameters);
            log_process("Asaas Cartão: Venda salva com sucesso");
        } catch (Exception $e) {
            log_process("Asaas Cartão: Erro ao salvar venda: " . $e->getMessage());
            throw new Exception("Erro ao salvar venda: " . $e->getMessage());
        }

        // Disparar eventos usando função centralizada
        if (function_exists('dispatch_payment_events')) {
            try {
                log_process("Asaas Cartão: Disparando eventos via função centralizada...");
                $custom_event_data = [
                    'transacao_id' => $payment_id,
                    'usuario_id' => $usuario_id,
                    'produto_id' => $main_product_id,
                    'produto_nome' => $main_product_name,
                    'valor_total_compra' => $amount,
                    'comprador_nome' => $data['name'],
                    'comprador_email' => $data['email'],
                    'comprador_telefone' => $data['phone'],
                    'comprador_cpf' => !empty($data['cpf']) ? $data['cpf'] : null,
                    'metodo_pagamento' => $metodo,
                    'data_venda' => date('Y-m-d H:i:s'),
                    'utm_source' => $utm_parameters['utm_source'] ?? null,
                    'utm_campaign' => $utm_parameters['utm_campaign'] ?? null,
                    'utm_medium' => $utm_parameters['utm_medium'] ?? null,
                    'utm_content' => $utm_parameters['utm_content'] ?? null,
                    'utm_term' => $utm_parameters['utm_term'] ?? null,
                    'src' => $utm_parameters['src'] ?? null,
                    'sck' => $utm_parameters['sck'] ?? null
                ];
                dispatch_payment_events($pdo, $payment_id, $status, 'Asaas Cartão', $custom_event_data);
                log_process("Asaas Cartão: Eventos disparados com sucesso via função centralizada");
            } catch (Exception $e) {
                log_process("Asaas Cartão: Erro ao disparar eventos (não crítico): " . $e->getMessage());
            }
        }

        log_process("Asaas Cartão: Preparando resposta JSON...");

        // Retornar resposta
        $response_data = [
            'status' => $status,
            'payment_id' => $payment_id
        ];

        if ($status === 'approved') {
            $response_data['redirect_url'] = $redirect_url_after_approval . '?payment_id=' . $payment_id;
        } elseif ($status === 'pending') {
            $response_data['redirect_url'] = '/aguardando.php?payment_id=' . $payment_id;
        }

        log_process("Asaas Cartão: Retornando resposta JSON - status: $status, payment_id: $payment_id");
        returnJsonSuccess($response_data);
    }

    // ==========================================================
    // FLUXO APPLYFY PIX
    // ==========================================================
    elseif ($gateway_choice === 'applyfy' && $payment_method === 'Pix') {
        require_once __DIR__ . '/gateways/applyfy.php';

        $public_key = trim($credentials['applyfy_public_key'] ?? '');
        $secret_key = trim($credentials['applyfy_secret_key'] ?? '');

        // Validar credenciais
        if (empty($public_key) || empty($secret_key)) {
            throw new Exception("Credenciais Applyfy não configuradas.");
        }

        log_process("Applyfy Pix: Iniciando criação de pagamento");

        // Validar CPF (obrigatório para Applyfy Pix)
        $cpf = preg_replace('/[^0-9]/', '', $data['cpf'] ?? '');
        if (empty($cpf) || strlen($cpf) !== 11) {
            throw new Exception("CPF inválido. Por favor, informe um CPF válido com 11 dígitos.");
        }

        // Gerar identifier único
        $identifier = uniqid('applyfy_', true) . '_' . bin2hex(random_bytes(8));

        // Preparar dados do cliente
        $client_data = [
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => preg_replace('/\D/', '', $data['phone'] ?? ''), // Apenas números para Applyfy
            'document' => $cpf
        ];

        // Preparar lista de produtos (opcional)
        $products = [];
        // Produto principal
        $products[] = [
            'id' => (string) $main_product_id,
            'name' => $main_product_name,
            'quantity' => 1,
            'price' => (float) $data['transaction_amount']
        ];

        // Order bumps (se houver)
        $order_bump_ids = $data['order_bump_product_ids'] ?? [];
        if (!empty($order_bump_ids)) {
            foreach ($order_bump_ids as $ob_id) {
                $stmt_ob = $pdo->prepare("SELECT nome, preco FROM produtos WHERE id = ?");
                $stmt_ob->execute([$ob_id]);
                $ob_data = $stmt_ob->fetch(PDO::FETCH_ASSOC);
                if ($ob_data) {
                    $products[] = [
                        'id' => (string) $ob_id,
                        'name' => $ob_data['nome'],
                        'quantity' => 1,
                        'price' => (float) $ob_data['preco']
                    ];
                }
            }
        }

        // Criar pagamento Pix
        $pix_result = applyfy_create_pix_payment(
            $public_key,
            $secret_key,
            (float) $data['transaction_amount'],
            $client_data,
            $identifier,
            $webhook_url,
            $products
        );

        if (!$pix_result || (isset($pix_result['error']) && $pix_result['error'])) {
            $error_message = $pix_result['message'] ?? 'Erro ao criar pagamento Pix no Applyfy. Verifique os logs para mais detalhes.';
            log_process("Applyfy Pix: Erro ao criar pagamento - " . $error_message);
            throw new Exception($error_message);
        }

        $payment_id = $pix_result['transaction_id'];
        $status = 'pending';

        // Salvar venda
        save_sales($pdo, $data, $main_product_id, $payment_id, $status, 'Pix', $checkout_session_uuid, $utm_parameters);

        // Disparar eventos
        if (function_exists('dispatch_payment_events')) {
            $custom_event_data = [
                'transacao_id' => $payment_id,
                'usuario_id' => $usuario_id,
                'produto_id' => $main_product_id,
                'produto_nome' => $main_product_name,
                'valor_total_compra' => $data['transaction_amount'],
                'comprador_nome' => $data['name'],
                'comprador_email' => $data['email'],
                'comprador_telefone' => $data['phone'],
                'comprador_cpf' => !empty($data['cpf']) ? $data['cpf'] : null,
                'metodo_pagamento' => 'Pix',
                'data_venda' => date('Y-m-d H:i:s'),
                'utm_source' => $utm_parameters['utm_source'] ?? null,
                'utm_campaign' => $utm_parameters['utm_campaign'] ?? null,
                'utm_medium' => $utm_parameters['utm_medium'] ?? null,
                'utm_content' => $utm_parameters['utm_content'] ?? null,
                'utm_term' => $utm_parameters['utm_term'] ?? null,
                'src' => $utm_parameters['src'] ?? null,
                'sck' => $utm_parameters['sck'] ?? null
            ];
            dispatch_payment_events($pdo, $payment_id, 'pending', 'Applyfy Pix', $custom_event_data);
        }

        returnJsonSuccess([
            'status' => 'pix_created',
            'pix_data' => [
                'qr_code_base64' => $pix_result['qr_code_base64'] ?? null,
                'qr_code' => $pix_result['qr_code'] ?? '',
                'payment_id' => $payment_id
            ],
            'redirect_url_after_approval' => $redirect_url_after_approval . '?payment_id=' . $payment_id
        ]);
    }

    // ==========================================================
    // FLUXO SPACEPAG PIX
    // ==========================================================
    elseif ($gateway_choice === 'spacepag') {
        require_once __DIR__ . '/gateways/spacepag.php';

        $public_key = trim($credentials['spacepag_public_key'] ?? '');
        $secret_key = trim($credentials['spacepag_secret_key'] ?? '');

        if (empty($public_key) || empty($secret_key)) {
            throw new Exception("Credenciais SpacePag não configuradas.");
        }

        log_process("SpacePag Pix: Iniciando criação de pagamento");

        // CPF é obrigatório para SpacePag
        $cpf = preg_replace('/[^0-9]/', '', $data['cpf'] ?? '');
        if (empty($cpf) || strlen($cpf) !== 11) {
            throw new Exception("CPF inválido. Por favor, informe um CPF válido com 11 dígitos.");
        }

        $token_data = spacepag_get_access_token($public_key, $secret_key);
        if (!$token_data || empty($token_data['access_token'])) {
            throw new Exception("Erro ao obter token SpacePag. Verifique as credenciais.");
        }

        $consumer = [
            'name' => $data['name'],
            'document' => $cpf,
            'email' => $data['email']
        ];
        // external_id limitado a 12 caracteres (conforme documentação SpacePag)
        $external_id = substr(preg_replace('/[^a-zA-Z0-9]/', '', $checkout_session_uuid), 0, 12);

        // Se webhook_url for localhost, enviar vazio (SpacePag não consegue chamar localhost)
        $spacepag_webhook = $webhook_url;
        if (strpos($webhook_url, 'localhost') !== false || strpos($webhook_url, '127.0.0.1') !== false) {
            $spacepag_webhook = ''; // Não enviar webhook para localhost
            log_process("SpacePag: Webhook localhost detectado, desabilitando postback");
        }

        $pix_result = spacepag_create_pix_charge(
            $token_data['access_token'],
            (float) $data['transaction_amount'],
            $consumer,
            $external_id,
            $spacepag_webhook
        );

        if (!$pix_result || (isset($pix_result['error']) && $pix_result['error'])) {
            $error_message = $pix_result['message'] ?? 'Erro ao criar pagamento Pix no SpacePag.';
            log_process("SpacePag Pix: Erro - " . $error_message);

            // Se for erro 500 da API, informar ao usuário de forma mais clara
            if (strpos($error_message, 'Internal server error') !== false) {
                throw new Exception("A API SpacePag está temporariamente indisponível. Por favor, tente outro método de pagamento ou aguarde alguns minutos. Se o problema persistir, entre em contato com o suporte.");
            }

            throw new Exception($error_message);
        }

        $payment_id = $pix_result['transaction_id'];
        $status = 'pending';

        save_sales($pdo, $data, $main_product_id, $payment_id, $status, 'Pix', $checkout_session_uuid, $utm_parameters);

        if (function_exists('dispatch_payment_events')) {
            $custom_event_data = [
                'transacao_id' => $payment_id,
                'usuario_id' => $usuario_id,
                'produto_id' => $main_product_id,
                'produto_nome' => $main_product_name,
                'valor_total_compra' => $data['transaction_amount'],
                'comprador_nome' => $data['name'],
                'comprador_email' => $data['email'],
                'comprador_telefone' => $data['phone'],
                'comprador_cpf' => !empty($data['cpf']) ? $data['cpf'] : null,
                'metodo_pagamento' => 'Pix',
                'data_venda' => date('Y-m-d H:i:s'),
                'utm_source' => $utm_parameters['utm_source'] ?? null,
                'utm_campaign' => $utm_parameters['utm_campaign'] ?? null,
                'utm_medium' => $utm_parameters['utm_medium'] ?? null,
                'utm_content' => $utm_parameters['utm_content'] ?? null,
                'utm_term' => $utm_parameters['utm_term'] ?? null,
                'src' => $utm_parameters['src'] ?? null,
                'sck' => $utm_parameters['sck'] ?? null
            ];
            dispatch_payment_events($pdo, $payment_id, 'pending', 'SpacePag Pix', $custom_event_data);
        }

        returnJsonSuccess([
            'status' => 'pix_created',
            'pix_data' => [
                'qr_code_base64' => $pix_result['qr_code_base64'] ?? null,
                'qr_code' => $pix_result['qr_code'] ?? '',
                'payment_id' => $payment_id
            ],
            'redirect_url_after_approval' => $redirect_url_after_approval . '?payment_id=' . $payment_id
        ]);
    }

    // ==========================================================
    // FLUXO APPLYFY CARTÃO
    // ==========================================================
    elseif ($gateway_choice === 'applyfy' && $payment_method === 'Cartão de crédito') {
        require_once __DIR__ . '/gateways/applyfy.php';

        $public_key = trim($credentials['applyfy_public_key'] ?? '');
        $secret_key = trim($credentials['applyfy_secret_key'] ?? '');

        // Validar credenciais
        if (empty($public_key) || empty($secret_key)) {
            throw new Exception("Credenciais Applyfy não configuradas.");
        }

        log_process("Applyfy Cartão: Iniciando processamento");

        // Validar CPF (obrigatório para Applyfy Cartão)
        $cpf = preg_replace('/[^0-9]/', '', $data['cpf'] ?? '');
        if (empty($cpf) || strlen($cpf) !== 11) {
            throw new Exception("CPF inválido. Por favor, informe um CPF válido com 11 dígitos.");
        }

        // Validar email
        if (!filter_var($data['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            throw new Exception("Email inválido.");
        }

        // Validar valor
        $amount = (float) ($data['transaction_amount'] ?? 0);
        if ($amount <= 0) {
            throw new Exception("Valor inválido.");
        }

        // Obter número de parcelas (padrão: 1)
        $installments = (int) ($data['installments'] ?? 1);
        if ($installments < 1 || $installments > 12) {
            $installments = 1;
        }

        // Validar dados do cartão
        if (empty($data['card_data'])) {
            throw new Exception("Dados do cartão não fornecidos.");
        }

        $card_data_raw = $data['card_data'];

        // Preparar dados do cartão no formato Applyfy
        // expiresAt deve estar no formato YYYY-MM
        $expires_at = '';
        if (isset($card_data_raw['expirationMonth']) && isset($card_data_raw['expirationYear'])) {
            $month = str_pad((string) $card_data_raw['expirationMonth'], 2, '0', STR_PAD_LEFT);
            $year = (string) $card_data_raw['expirationYear'];
            // Se ano tiver 4 dígitos, pegar apenas os 2 últimos
            if (strlen($year) === 4) {
                $year = substr($year, 2);
            }
            $expires_at = '20' . $year . '-' . $month;
        } else {
            throw new Exception("Dados de validade do cartão inválidos.");
        }

        $card_data = [
            'number' => preg_replace('/[^0-9]/', '', $card_data_raw['number'] ?? ''),
            'owner' => $card_data_raw['holderName'] ?? $card_data_raw['owner'] ?? '',
            'expiresAt' => $expires_at,
            'cvv' => preg_replace('/[^0-9]/', '', $card_data_raw['cvv'] ?? '')
        ];

        // Validar campos do cartão
        if (empty($card_data['number']) || strlen($card_data['number']) < 13) {
            throw new Exception("Número do cartão inválido.");
        }
        if (empty($card_data['owner']) || strlen($card_data['owner']) < 3) {
            throw new Exception("Nome no cartão inválido.");
        }
        if (empty($card_data['cvv']) || strlen($card_data['cvv']) < 3) {
            throw new Exception("CVV do cartão inválido.");
        }

        // Obter IP do cliente
        require_once __DIR__ . '/helpers/security_helper.php';
        $client_ip = get_client_ip();

        // Gerar identifier único
        $identifier = uniqid('applyfy_', true) . '_' . bin2hex(random_bytes(8));

        // Preparar dados do cliente com endereço
        $client_data = [
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => preg_replace('/\D/', '', $data['phone'] ?? ''), // Apenas números para Applyfy
            'document' => $cpf,
            'address' => [
                'country' => 'BR',
                'zipCode' => preg_replace('/[^0-9-]/', '', $data['cep'] ?? $data['address']['zipCode'] ?? ''),
                'state' => strtoupper(substr($data['estado'] ?? $data['address']['state'] ?? '', 0, 2)),
                'city' => $data['cidade'] ?? $data['address']['city'] ?? '',
                'street' => $data['logradouro'] ?? $data['address']['street'] ?? '',
                'neighborhood' => $data['bairro'] ?? $data['address']['neighborhood'] ?? '',
                'number' => $data['numero'] ?? $data['address']['number'] ?? '',
                'complement' => $data['complemento'] ?? $data['address']['complement'] ?? ''
            ]
        ];

        // Validar endereço obrigatório para cartão
        if (
            empty($client_data['address']['zipCode']) || empty($client_data['address']['street']) ||
            empty($client_data['address']['number']) || empty($client_data['address']['neighborhood']) ||
            empty($client_data['address']['city']) || empty($client_data['address']['state'])
        ) {
            throw new Exception("Endereço completo é obrigatório para pagamento com cartão.");
        }

        // Preparar lista de produtos (opcional)
        $products = [];
        // Produto principal
        $products[] = [
            'id' => (string) $main_product_id,
            'name' => $main_product_name,
            'quantity' => 1,
            'price' => (float) $data['transaction_amount']
        ];

        // Order bumps (se houver)
        $order_bump_ids = $data['order_bump_product_ids'] ?? [];
        if (!empty($order_bump_ids)) {
            foreach ($order_bump_ids as $ob_id) {
                $stmt_ob = $pdo->prepare("SELECT nome, preco FROM produtos WHERE id = ?");
                $stmt_ob->execute([$ob_id]);
                $ob_data = $stmt_ob->fetch(PDO::FETCH_ASSOC);
                if ($ob_data) {
                    $products[] = [
                        'id' => (string) $ob_id,
                        'name' => $ob_data['nome'],
                        'quantity' => 1,
                        'price' => (float) $ob_data['preco']
                    ];
                }
            }
        }

        // Criar pagamento com cartão
        $card_result = applyfy_create_card_payment(
            $public_key,
            $secret_key,
            $amount,
            $client_data,
            $card_data,
            $client_ip,
            $identifier,
            $webhook_url,
            $products,
            $installments
        );

        if (!$card_result || (isset($card_result['error']) && $card_result['error'])) {
            $error_message = $card_result['message'] ?? 'Erro ao processar pagamento com cartão no Applyfy.';
            $error_details = $card_result['error_details'] ?? null;

            // Melhorar mensagem de erro com detalhes específicos
            if ($error_details && is_array($error_details)) {
                foreach ($error_details as $detail) {
                    if (isset($detail['message']) && isset($detail['path'])) {
                        $field = implode(' -> ', $detail['path']);
                        if (stripos($detail['message'], 'zip code') !== false || stripos($field, 'zipCode') !== false) {
                            $error_message = 'CEP inválido. Por favor, verifique o CEP informado.';
                            break;
                        }
                    }
                }
            }

            log_process("Applyfy Cartão Error: " . $error_message);
            log_process("Applyfy Cartão Error Details: " . json_encode($error_details));
            throw new Exception($error_message);
        }

        $payment_id = $card_result['transaction_id'];
        $status = $card_result['status']; // 'approved', 'pending', 'rejected'
        $metodo = 'Cartão de crédito';

        // Salvar venda
        try {
            save_sales($pdo, $data, $main_product_id, $payment_id, $status, $metodo, $checkout_session_uuid, $utm_parameters);
            log_process("Applyfy Cartão: Venda salva com sucesso");
        } catch (Exception $e) {
            log_process("Applyfy Cartão: Erro ao salvar venda: " . $e->getMessage());
            throw new Exception("Erro ao salvar venda: " . $e->getMessage());
        }

        // Disparar eventos usando função centralizada
        if (function_exists('dispatch_payment_events')) {
            $custom_event_data = [
                'transacao_id' => $payment_id,
                'usuario_id' => $usuario_id,
                'produto_id' => $main_product_id,
                'produto_nome' => $main_product_name,
                'valor_total_compra' => $amount,
                'comprador_nome' => $data['name'],
                'comprador_email' => $data['email'],
                'comprador_telefone' => $data['phone'],
                'comprador_cpf' => !empty($data['cpf']) ? $data['cpf'] : null,
                'metodo_pagamento' => $metodo,
                'data_venda' => date('Y-m-d H:i:s'),
                'utm_source' => $utm_parameters['utm_source'] ?? null,
                'utm_campaign' => $utm_parameters['utm_campaign'] ?? null,
                'utm_medium' => $utm_parameters['utm_medium'] ?? null,
                'utm_content' => $utm_parameters['utm_content'] ?? null,
                'utm_term' => $utm_parameters['utm_term'] ?? null,
                'src' => $utm_parameters['src'] ?? null,
                'sck' => $utm_parameters['sck'] ?? null
            ];
            dispatch_payment_events($pdo, $payment_id, $status, 'Applyfy Cartão', $custom_event_data);
        }

        log_process("Applyfy Cartão: Preparando resposta JSON...");

        // Retornar resposta
        $response_data = [
            'status' => $status,
            'payment_id' => $payment_id
        ];

        if ($status === 'approved') {
            $response_data['redirect_url'] = $redirect_url_after_approval . '?payment_id=' . $payment_id;
        } elseif ($status === 'pending') {
            $response_data['redirect_url'] = '/aguardando.php?payment_id=' . $payment_id;
        }

        log_process("Applyfy Cartão: Retornando resposta JSON - status: $status, payment_id: $payment_id");
        returnJsonSuccess($response_data);
    }

    // ==========================================================
    // FLUXO STRIPE
    // ==========================================================
    elseif ($gateway_choice === 'stripe') {
        require_once __DIR__ . '/gateways/stripe.php';

        $public_key = trim($credentials['stripe_public_key'] ?? '');
        $secret_key = trim($credentials['stripe_secret_key'] ?? '');

        if (empty($public_key) || empty($secret_key)) {
            throw new Exception("Credenciais Stripe não configuradas.");
        }

        log_process("Stripe: Iniciando Checkout Session");

        // Items para Checkout
        $line_items = [];
        $line_items[] = [
            'price_data' => [
                'currency' => 'brl',
                'product_data' => [
                    'name' => $main_product_name,
                ],
                'unit_amount' => (int) (round((float) $data['transaction_amount'], 2) * 100),
            ],
            'quantity' => 1,
        ];

        // Adicionar Order Bumps se houver
        $order_bump_ids = $data['order_bump_product_ids'] ?? [];
        if (!empty($order_bump_ids)) {
            foreach ($order_bump_ids as $ob_id) {
                try {
                    $stmt_ob = $pdo->prepare("SELECT nome, preco FROM produtos WHERE id = ?");
                    $stmt_ob->execute([$ob_id]);
                    $ob_data = $stmt_ob->fetch(PDO::FETCH_ASSOC);
                    if ($ob_data) {
                        $line_items[] = [
                            'price_data' => [
                                'currency' => 'brl',
                                'product_data' => [
                                    'name' => 'Order Bump: ' . $ob_data['nome'],
                                ],
                                'unit_amount' => (int) (round((float) $ob_data['preco'], 2) * 100),
                            ],
                            'quantity' => 1,
                        ];
                    }
                } catch (Exception $e) {
                    log_process("Stripe: Erro ao adicionar order bump $ob_id: " . $e->getMessage());
                }
            }
        }

        // Metadata para identificar a venda no webhook
        $metadata = [
            'product_id' => $main_product_id,
            'checkout_uuid' => $checkout_session_uuid,
            'user_email' => $data['email'],
        ];

        // URLs de redirecionamento
        $success_url = $redirect_url_after_approval . '?session_id={CHECKOUT_SESSION_ID}';
        $cancel_url = $protocol . "://" . $domainName . $path . '/checkout_cancel.php?product_id=' . $main_product_id;

        try {
            $session = create_stripe_checkout_session(
                $line_items,
                $success_url,
                $cancel_url,
                $metadata,
                $secret_key
            );

            if (isset($session['id']) && isset($session['url'])) {
                // Salva Venda com status 'initiated'
                $payment_id = $session['id'];
                $status = 'pending'; // Stripe Checkout is pending until completed

                save_sales($pdo, $data, $main_product_id, $payment_id, $status, 'Cartão de crédito', $checkout_session_uuid, $utm_parameters);

                // Disparo de evento pending
                if (function_exists('dispatch_payment_events')) {
                    $custom_event_data = [
                        'transacao_id' => $payment_id,
                        'usuario_id' => $usuario_id,
                        'produto_id' => $main_product_id,
                        'produto_nome' => $main_product_name,
                        'valor_total_compra' => $data['transaction_amount'],
                        'comprador_nome' => $data['name'],
                        'comprador_email' => $data['email'],
                        'comprador_telefone' => $data['phone'],
                        'metodo_pagamento' => 'Stripe Checkout',
                        'data_venda' => date('Y-m-d H:i:s'),
                    ];
                    // dispatch_payment_events($pdo, $payment_id, 'pending', 'Stripe', $custom_event_data);
                }

                returnJsonSuccess([
                    'status' => 'redirect',
                    'redirect_url' => $session['url']
                ]);
            } else {
                throw new Exception("Erro ao criar sessão Stripe.");
            }
        } catch (Exception $e) {
            log_process("Stripe Error: " . $e->getMessage());
            throw $e;
        }
    }

    // ==========================================================
    // FLUXO MERCADO PAGO (fallback)
    // ==========================================================
    else {
        $token = $credentials['mp_access_token'] ?? '';
        if (empty($token)) {
            log_process("Mercado Pago: Token não configurado");
            throw new Exception("Token Mercado Pago não configurado.");
        }

        // Validar payment_method_id
        $payment_method_id = $data['payment_method_id'] ?? null;
        if (empty($payment_method_id)) {
            // Tentar inferir de outros campos
            if (isset($data['paymentTypeId'])) {
                $payment_method_id = $data['paymentTypeId'];
            } elseif (isset($data['payment_type_id'])) {
                $payment_method_id = $data['payment_type_id'];
            } else {
                log_process("Mercado Pago: payment_method_id não fornecido");
                throw new Exception("Método de pagamento não especificado. Por favor, tente novamente.");
            }
        }

        log_process("Mercado Pago: Iniciando processamento - payment_method_id: $payment_method_id");

        // Validar URL do webhook antes de enviar
        $webhook_url_for_mp = null;
        if (!filter_var($webhook_url, FILTER_VALIDATE_URL)) {
            log_process("Mercado Pago: ERRO - URL do webhook inválida: " . $webhook_url);
        } else {
            // Verificar se é localhost (Mercado Pago não aceita)
            $parsed_url = parse_url($webhook_url);
            $webhook_host = $parsed_url['host'] ?? '';
            if ($webhook_host === 'localhost' || strpos($webhook_host, '127.0.0.1') !== false || strpos($webhook_host, '::1') !== false) {
                log_process("Mercado Pago: AVISO - Webhook em localhost detectado. Omitindo notification_url.");
                // Em ambiente local, não enviar notification_url (Mercado Pago não aceita)
                $webhook_url_for_mp = null;
            } else {
                // Validar se é HTTPS (Mercado Pago exige HTTPS)
                if (strpos($webhook_url, 'https://') !== 0) {
                    log_process("Mercado Pago: AVISO - Webhook não é HTTPS. Forçando HTTPS...");
                    $webhook_url_for_mp = str_replace('http://', 'https://', $webhook_url);
                } else {
                    $webhook_url_for_mp = $webhook_url;
                }
                log_process("Mercado Pago: notification_url válida: " . $webhook_url_for_mp);
            }
        }

        // Extrair CPF de diferentes fontes possíveis (opcional para Pix)
        $cpf = '';
        if (!empty($data['cpf'])) {
            $cpf = preg_replace('/[^0-9]/', '', $data['cpf']);
        } elseif (!empty($data['payer']['identification']['number'])) {
            $cpf = preg_replace('/[^0-9]/', '', $data['payer']['identification']['number']);
        } elseif (!empty($data['formData']['payer']['identification']['number'])) {
            $cpf = preg_replace('/[^0-9]/', '', $data['formData']['payer']['identification']['number']);
        }

        // CPF é obrigatório apenas para Cartão e Boleto, não para Pix
        $is_pix = (strtolower($payment_method_id) === 'pix');
        if (!$is_pix) {
            // Validar CPF apenas para Cartão e Boleto
            if (empty($cpf) || strlen($cpf) !== 11) {
                throw new Exception("CPF inválido ou não fornecido.");
            }
        }

        $payment_data = [
            'transaction_amount' => (float) $data['transaction_amount'],
            'description' => 'Compra: ' . $main_product_name,
            'payment_method_id' => $payment_method_id,
            'payer' => [
                'email' => $data['email'],
                'first_name' => explode(' ', $data['name'])[0],
                'last_name' => substr(strstr($data['name'], ' '), 1) ?: '',
            ],
            'external_reference' => $checkout_session_uuid
        ];

        // Incluir CPF apenas se fornecido e válido (ou obrigatório para Cartão/Boleto)
        if (!empty($cpf) && strlen($cpf) === 11) {
            $payment_data['payer']['identification'] = ['type' => 'CPF', 'number' => $cpf];
        }

        // Adicionar notification_url apenas se for válida (não localhost)
        if ($webhook_url_for_mp !== null) {
            $payment_data['notification_url'] = $webhook_url_for_mp;
        }

        if (isset($data['token']))
            $payment_data['token'] = $data['token'];
        if (isset($data['installments']))
            $payment_data['installments'] = (int) $data['installments'];
        if (isset($data['issuer_id']))
            $payment_data['issuer_id'] = (int) $data['issuer_id'];

        $ch = curl_init('https://api.mercadopago.com/v1/payments');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
            'X-Idempotency-Key: ' . $checkout_session_uuid
        ]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payment_data));
        $response = curl_exec($ch);
        $curl_error = curl_error($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curl_error) {
            log_process("Mercado Pago: Erro cURL: " . $curl_error);
            throw new Exception("Erro de conexão com Mercado Pago. Tente novamente.");
        }

        $res_data = json_decode($response, true);

        if (!$res_data) {
            log_process("Mercado Pago: Resposta inválida (não é JSON) - HTTP Code: $http_code");
            log_process("Mercado Pago: Resposta: " . substr($response, 0, 500));
            throw new Exception("Resposta inválida do Mercado Pago. Tente novamente.");
        }

        if ($http_code >= 200 && $http_code < 300 && isset($res_data['status'])) {
            $status = $res_data['status'];
            $payment_id = $res_data['id'] ?? null;

            if (empty($payment_id)) {
                log_process("Mercado Pago: Payment ID não retornado na resposta");
                log_process("Mercado Pago: Resposta completa: " . json_encode($res_data));
                throw new Exception("Erro ao processar pagamento. Tente novamente.");
            }

            $metodo = ($payment_method_id === 'pix') ? 'Pix' : (($payment_method_id === 'ticket') ? 'Boleto' : 'Cartão de crédito');

            log_process("Mercado Pago: Pagamento criado - ID: $payment_id, Status: $status, Método: $metodo");

            save_sales($pdo, $data, $main_product_id, $payment_id, $status, $metodo, $checkout_session_uuid, $utm_parameters);

            // --- DISPARO IMEDIATO DE EVENTOS ---
            // Usando função centralizada para garantir consistência
            if (function_exists('dispatch_payment_events')) {
                $custom_event_data = [
                    'transacao_id' => $payment_id,
                    'usuario_id' => $usuario_id,
                    'produto_id' => $main_product_id,
                    'produto_nome' => $main_product_name,
                    'valor_total_compra' => $data['transaction_amount'],
                    'comprador_nome' => $data['name'],
                    'comprador_email' => $data['email'],
                    'comprador_telefone' => $data['phone'],
                    'comprador_cpf' => !empty($data['cpf']) ? $data['cpf'] : null,
                    'metodo_pagamento' => $metodo,
                    'data_venda' => date('Y-m-d H:i:s'),
                    'utm_source' => $utm_parameters['utm_source'] ?? null,
                    'utm_campaign' => $utm_parameters['utm_campaign'] ?? null,
                    'utm_medium' => $utm_parameters['utm_medium'] ?? null,
                    'utm_content' => $utm_parameters['utm_content'] ?? null,
                    'utm_term' => $utm_parameters['utm_term'] ?? null,
                    'src' => $utm_parameters['src'] ?? null,
                    'sck' => $utm_parameters['sck'] ?? null
                ];
                dispatch_payment_events($pdo, $payment_id, $status, 'Mercado Pago', $custom_event_data);
            }
            // -------------------------------------------------------------

            if ($status == 'pending' && $payment_method_id == 'pix') {
                // Validar se os dados do Pix existem antes de retornar
                $qr_code_base64 = null;
                $qr_code = null;

                if (isset($res_data['point_of_interaction']['transaction_data']['qr_code_base64'])) {
                    $qr_code_base64 = $res_data['point_of_interaction']['transaction_data']['qr_code_base64'];
                }

                if (isset($res_data['point_of_interaction']['transaction_data']['qr_code'])) {
                    $qr_code = $res_data['point_of_interaction']['transaction_data']['qr_code'];
                }

                if (empty($qr_code) && empty($qr_code_base64)) {
                    log_process("Mercado Pago Pix: Dados do QR Code não encontrados na resposta");
                    log_process("Mercado Pago Pix: Resposta: " . json_encode($res_data));
                    throw new Exception("Erro ao gerar QR Code do Pix. Tente novamente.");
                }

                log_process("Mercado Pago Pix: QR Code gerado com sucesso");

                returnJsonSuccess([
                    'status' => 'pix_created',
                    'pix_data' => [
                        'qr_code_base64' => $qr_code_base64,
                        'qr_code' => $qr_code,
                        'payment_id' => $payment_id
                    ],
                    'redirect_url_after_approval' => $redirect_url_after_approval . '?payment_id=' . $payment_id
                ]);
            }

            $response_front = ['status' => $status, 'message' => 'Processado.', 'payment_id' => $payment_id];
            if ($status == 'approved') {
                $response_front['redirect_url'] = $redirect_url_after_approval . '?payment_id=' . $payment_id;
            }
            returnJsonSuccess($response_front);

        } else {
            // Log detalhado do erro
            $error_message = "Erro ao processar pagamento no Mercado Pago";
            if (isset($res_data['message'])) {
                $error_message = $res_data['message'];
            } elseif (isset($res_data['error'])) {
                $error_message = is_string($res_data['error']) ? $res_data['error'] : ($res_data['error']['message'] ?? 'Erro desconhecido');
            }

            log_process("Mercado Pago: Erro - HTTP Code: $http_code");
            log_process("Mercado Pago: Erro - Mensagem: $error_message");
            log_process("Mercado Pago: Resposta completa: " . json_encode($res_data));

            // Mensagem amigável para o usuário
            $user_message = "Não foi possível processar o pagamento. ";
            if (isset($res_data['cause']) && is_array($res_data['cause'])) {
                $causes = array_column($res_data['cause'], 'description');
                if (!empty($causes)) {
                    $user_message .= implode('. ', $causes);
                } else {
                    $user_message .= "Tente novamente ou escolha outro método de pagamento.";
                }
            } else {
                $user_message .= "Tente novamente ou escolha outro método de pagamento.";
            }

            throw new Exception($user_message);
        }
    }

} catch (Exception $e) {
    log_process("Erro Exception: " . $e->getMessage());
    log_process("Stack trace: " . $e->getTraceAsString());

    // Verifica se é erro de limite atingido
    $error_message = $e->getMessage();
    if (strpos($error_message, 'LIMITE_ATINGIDO|') === 0) {
        $parts = explode('|', $error_message, 3);
        $message = $parts[1] ?? 'Limite atingido';
        $upgrade_url = $parts[2] ?? '/index?pagina=saas_planos';

        ob_clean();
        http_response_code(403); // Forbidden
        header('Content-Type: application/json');
        echo json_encode([
            'error' => $message,
            'limit_reached' => true,
            'upgrade_url' => $upgrade_url
        ]);
        exit;
    }

    returnJsonError($error_message, 500);
} catch (Error $e) {
    log_process("Erro Fatal: " . $e->getMessage());
    log_process("Stack trace: " . $e->getTraceAsString());
    returnJsonError('Erro interno do servidor', 500);
}


?>