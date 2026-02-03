<?php
/**
 * Processamento de Pagamento de Planos SaaS
 * Similar ao process_payment.php mas para planos
 */

// Registrar handler de erros fatais
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== NULL && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        ob_clean();
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode([
            'error' => 'Erro fatal: ' . $error['message'] . ' em ' . $error['file'] . ' linha ' . $error['line']
        ]);
        exit;
    }
});

ob_start();
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

function returnJsonError($message, $code = 500) {
    ob_clean();
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['error' => $message]);
    exit;
}

function returnJsonSuccess($data) {
    ob_clean();
    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// Carregar config
$config_paths = [
    __DIR__ . '/../../config/config.php',
    __DIR__ . '/../../config.php'
];

$config_loaded = false;
foreach ($config_paths as $config_path) {
    if (file_exists($config_path)) {
        try {
            ob_start();
            require $config_path;
            ob_end_clean();
            $config_loaded = true;
            break;
        } catch (Exception $e) {
            ob_end_clean();
            returnJsonError('Erro ao carregar configuração: ' . $e->getMessage(), 500);
        }
    }
}

if (!$config_loaded) {
    returnJsonError('Arquivo de configuração não encontrado.', 500);
}

// Garantir que a sessão está iniciada
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

ob_end_clean();
header('Content-Type: application/json');

// Log inicial para debug
error_log("SaaS Payment: Iniciando processamento - REQUEST_METHOD: " . ($_SERVER['REQUEST_METHOD'] ?? 'N/A'));

// Verificar se SaaS está habilitado
if (file_exists(__DIR__ . '/../includes/saas_functions.php')) {
    require_once __DIR__ . '/../includes/saas_functions.php';
    if (!function_exists('saas_enabled')) {
        error_log("SaaS: Função saas_enabled não encontrada após incluir saas_functions.php");
        returnJsonError('Sistema SaaS não configurado corretamente. Função saas_enabled não encontrada.', 500);
    }
    
    // Verificar se PDO está disponível
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        error_log("SaaS: PDO não disponível em process_plano_payment.php");
        returnJsonError('Erro de configuração do banco de dados.', 500);
    }
    
    // Verificar se tabela existe
    try {
        $stmt_check = $pdo->query("SHOW TABLES LIKE 'saas_config'");
        if ($stmt_check->rowCount() == 0) {
            error_log("SaaS: Tabela saas_config não existe");
            returnJsonError('Sistema SaaS não configurado. Tabela saas_config não encontrada. Execute a migração SQL necessária.', 500);
        }
    } catch (PDOException $e) {
        error_log("SaaS: Erro ao verificar tabela saas_config: " . $e->getMessage());
        returnJsonError('Erro ao verificar configuração do sistema SaaS.', 500);
    }
    
    // Verificar se está habilitado
    error_log("SaaS Payment: Verificando se SaaS está habilitado...");
    
    // Verificar diretamente no banco para ter certeza
    try {
        $stmt_saas = $pdo->prepare("SELECT enabled FROM saas_config LIMIT 1");
        $stmt_saas->execute();
        $saas_config = $stmt_saas->fetch(PDO::FETCH_ASSOC);
        
        if (!$saas_config) {
            error_log("SaaS: Nenhum registro encontrado na tabela saas_config");
            returnJsonError('Sistema SaaS não configurado. Nenhum registro encontrado na tabela saas_config. Por favor, habilite no painel administrativo (Configurações > Modo SaaS).', 500);
        }
        
        $enabled_value = (int)($saas_config['enabled'] ?? 0);
        error_log("SaaS Payment: Valor de enabled no banco: " . $enabled_value);
        
        if ($enabled_value !== 1) {
            error_log("SaaS: Sistema SaaS está desabilitado (enabled = {$enabled_value})");
            returnJsonError('Sistema SaaS não está habilitado. Por favor, habilite no painel administrativo (Configurações > Modo SaaS).', 403);
        }
        
        error_log("SaaS Payment: SaaS está habilitado (enabled = 1), continuando...");
    } catch (PDOException $e) {
        error_log("SaaS: Erro ao verificar enabled: " . $e->getMessage());
        returnJsonError('Erro ao verificar status do sistema SaaS: ' . $e->getMessage(), 500);
    }
} else {
    error_log("SaaS: Arquivo saas_functions.php não encontrado em " . __DIR__ . '/../includes/saas_functions.php');
    returnJsonError('Sistema SaaS não configurado. Arquivo saas_functions.php não encontrado.', 500);
}

// Verificar se usuário está logado
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    returnJsonError('Usuário não autenticado.', 401);
}

// Verificar se é infoprodutor
if ($_SESSION["tipo"] !== 'infoprodutor') {
    returnJsonError('Acesso negado.', 403);
}

$raw_post_data = file_get_contents('php://input');
$data = json_decode($raw_post_data, true);

if (!$data) {
    returnJsonError('Dados inválidos.', 400);
}

// Obter nome e email da sessão se não vierem no POST
$name = $data['name'] ?? $_SESSION['nome'] ?? '';
$email = $data['email'] ?? $_SESSION['usuario'] ?? '';

// Campos obrigatórios (nome e email podem vir da sessão)
$required_fields = ['transaction_amount', 'cpf', 'phone', 'plano_id', 'gateway'];
foreach ($required_fields as $field) {
    if (empty($data[$field])) {
        returnJsonError("Campo obrigatório ausente: $field", 400);
    }
}

// Validar nome e email (devem existir na sessão ou no POST)
if (empty($name)) {
    returnJsonError("Nome não encontrado. Por favor, faça login novamente.", 400);
}

if (empty($email)) {
    returnJsonError("E-mail não encontrado. Por favor, faça login novamente.", 400);
}

$plano_id = intval($data['plano_id']);
$gateway_choice = $data['gateway'] ?? 'mercadopago';

try {
    // Buscar plano
    $stmt_plano = $pdo->prepare("SELECT * FROM saas_planos WHERE id = ? AND ativo = 1");
    $stmt_plano->execute([$plano_id]);
    $plano = $stmt_plano->fetch(PDO::FETCH_ASSOC);
    
    if (!$plano) {
        throw new Exception("Plano não encontrado ou inativo.");
    }
    
    // Buscar credenciais do gateway admin
    $stmt_gateway = $pdo->prepare("SELECT * FROM saas_admin_gateways WHERE gateway = ?");
    $stmt_gateway->execute([$gateway_choice]);
    $gateway_config = $stmt_gateway->fetch(PDO::FETCH_ASSOC);
    
    if (!$gateway_config) {
        throw new Exception("Gateway não configurado no painel admin.");
    }
    
    $usuario_id = $_SESSION['id'];
    
    // Construir URL do webhook corretamente (seguindo padrão do process_payment.php)
    $domainName = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptDir = dirname($_SERVER['PHP_SELF']);
    $path = rtrim(str_replace('\\', '/', $scriptDir), '/');
    
    // Detectar protocolo (HTTPS ou HTTP)
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || 
                (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) ||
                (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
                ? 'https' : 'http';
    
    // Construir URL do webhook
    $webhook_url = $protocol . "://" . $domainName . $path . '/../../notification.php';
    
    // Validar URL do webhook
    if (!filter_var($webhook_url, FILTER_VALIDATE_URL)) {
        error_log("SaaS Payment: URL do webhook inválida: " . $webhook_url);
        // Tentar forçar HTTPS se estiver em produção
        if ($protocol === 'http' && $domainName !== 'localhost' && strpos($domainName, '127.0.0.1') === false) {
            $webhook_url = "https://" . $domainName . $path . '/../../notification.php';
            error_log("SaaS Payment: Tentando forçar HTTPS: " . $webhook_url);
        }
    }
    
    // Validar se é localhost (Mercado Pago não aceita localhost)
    $webhook_url_for_mp = null;
    if ($domainName === 'localhost' || strpos($domainName, '127.0.0.1') !== false || strpos($domainName, '::1') !== false) {
        error_log("SaaS Payment: AVISO - Ambiente local detectado. Mercado Pago não aceita webhook em localhost.");
        error_log("SaaS Payment: Webhook URL: " . $webhook_url);
        // Em ambiente local, não enviar notification_url (Mercado Pago não aceita)
        $webhook_url_for_mp = null;
    } else {
        // Validar se é HTTPS (Mercado Pago exige HTTPS)
        if (strpos($webhook_url, 'https://') !== 0) {
            error_log("SaaS Payment: AVISO - Webhook não é HTTPS. Forçando HTTPS...");
            $webhook_url_for_mp = str_replace('http://', 'https://', $webhook_url);
        } else {
            $webhook_url_for_mp = $webhook_url;
        }
        error_log("SaaS Payment: notification_url válida: " . $webhook_url_for_mp);
    }
    
    // Processar conforme gateway
    if ($gateway_choice === 'efi' && $data['payment_method'] === 'pix') {
        require_once __DIR__ . '/../../gateways/efi.php';
        
        $client_id = trim($gateway_config['efi_client_id'] ?? '');
        $client_secret = trim($gateway_config['efi_client_secret'] ?? '');
        $certificate_path = trim($gateway_config['efi_certificate_path'] ?? '');
        $pix_key = trim($gateway_config['efi_pix_key'] ?? '');
        
        if (empty($client_id) || empty($client_secret) || empty($certificate_path) || empty($pix_key)) {
            throw new Exception("Credenciais Efí não configuradas completamente.");
        }
        
        $full_cert_path = __DIR__ . '/../../' . str_replace('\\', '/', $certificate_path);
        if (!file_exists($full_cert_path)) {
            throw new Exception("Certificado Efí não encontrado.");
        }
        
        $token_data = efi_get_access_token($client_id, $client_secret, $full_cert_path);
        if (!$token_data) {
            throw new Exception("Erro ao obter token de acesso Efí.");
        }
        
        $pix_result = efi_create_pix_charge(
            $token_data['access_token'],
            (float)$data['transaction_amount'],
            $pix_key,
            [
                'name' => $name,
                'cpf' => $data['cpf'],
                'email' => $email
            ],
            'Assinatura: ' . $plano['nome'],
            60,
            $full_cert_path
        );
        
        if (!$pix_result || !isset($pix_result['txid'])) {
            throw new Exception("Erro ao criar cobrança Pix na Efí.");
        }
        
        $payment_id = $pix_result['txid'];
        $status = 'pending';
        
        // Criar assinatura
        $data_inicio = date('Y-m-d');
        $dias_periodo = $plano['periodo'] === 'anual' ? 365 : 30;
        $data_vencimento = date('Y-m-d', strtotime("+{$dias_periodo} days"));
        
        $stmt = $pdo->prepare("
            INSERT INTO saas_assinaturas 
            (usuario_id, plano_id, status, data_inicio, data_vencimento, transacao_id, metodo_pagamento) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$usuario_id, $plano_id, $status, $data_inicio, $data_vencimento, $payment_id, 'Pix']);
        
        returnJsonSuccess([
            'status' => 'pix_created',
            'pix_data' => [
                'qr_code_base64' => $pix_result['qr_code_base64'] ?? null,
                'qr_code' => $pix_result['qr_code'] ?? '',
                'payment_id' => $payment_id
            ],
            'redirect_url' => '/index?pagina=saas_planos?payment_id=' . $payment_id
        ]);
        
    } elseif ($gateway_choice === 'pushinpay' && $data['payment_method'] === 'pix') {
        $token = $gateway_config['pushinpay_token'] ?? '';
        if (empty($token)) {
            throw new Exception("Token PushinPay não configurado.");
        }
        
        $amount_cents = (int)(round((float)$data['transaction_amount'], 2) * 100);
            $payload = [
                "value" => $amount_cents,
                "webhook_url" => $webhook_url,
                "payer" => [
                    "name" => $name,
                    "document" => preg_replace('/[^0-9]/', '', $data['cpf']),
                    "email" => $email
                ]
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
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $res_data = json_decode($response, true);
        
        if ($http_code >= 200 && $http_code < 300 && isset($res_data['qr_code_base64'])) {
            $payment_id = $res_data['id'] ?? null;
            if (!$payment_id) {
                throw new Exception("Resposta inválida da API PushinPay: ID não encontrado");
            }
            
            $status = 'pending';
            
            // Criar assinatura
            $data_inicio = date('Y-m-d');
            $dias_periodo = $plano['periodo'] === 'anual' ? 365 : 30;
            $data_vencimento = date('Y-m-d', strtotime("+{$dias_periodo} days"));
            
            $stmt = $pdo->prepare("
                INSERT INTO saas_assinaturas 
                (usuario_id, plano_id, status, data_inicio, data_vencimento, transacao_id, metodo_pagamento) 
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$usuario_id, $plano_id, $status, $data_inicio, $data_vencimento, $payment_id, 'Pix']);
            
            returnJsonSuccess([
                'status' => 'pix_created',
                'pix_data' => [
                    'qr_code_base64' => $res_data['qr_code_base64'],
                    'qr_code' => $res_data['qr_code'] ?? '',
                    'payment_id' => $payment_id
                ],
                'redirect_url' => '/index?pagina=saas_planos?payment_id=' . $payment_id
            ]);
        } else {
            $error_msg = $res_data['message'] ?? 'Erro ao processar pagamento';
            throw new Exception("PushinPay Error ($http_code): " . $error_msg);
        }
        
    } elseif ($gateway_choice === 'mercadopago' && $data['payment_method'] === 'pix') {
        $token = trim($gateway_config['mp_access_token'] ?? '');
        if (empty($token)) {
            throw new Exception("Token Mercado Pago não configurado.");
        }
        
        // Validar formato básico do token (deve começar com APP_USR- ou TEST-)
        if (!preg_match('/^(APP_USR-|TEST-)/', $token)) {
            error_log("SaaS Payment (Mercado Pago): Token com formato inválido: " . substr($token, 0, 20) . "...");
            throw new Exception("Token do Mercado Pago com formato inválido. O token deve começar com 'APP_USR-' (produção) ou 'TEST-' (sandbox).");
        }
        
        error_log("SaaS Payment (Mercado Pago): Token detectado - Tipo: " . (strpos($token, 'TEST-') === 0 ? 'SANDBOX' : 'PRODUÇÃO'));
        
        // Validar CPF antes de enviar
        $cpf_limpo = preg_replace('/[^0-9]/', '', $data['cpf']);
        if (strlen($cpf_limpo) !== 11) {
            throw new Exception("CPF inválido. Deve conter 11 dígitos.");
        }
        
        // Dividir nome corretamente
        $name_parts = explode(' ', trim($name), 2);
        $first_name = $name_parts[0] ?? '';
        $last_name = $name_parts[1] ?? '';
        
        if (empty($first_name)) {
            throw new Exception("Nome inválido. Por favor, informe um nome completo.");
        }
        
        $payment_data = [
            'transaction_amount' => (float)$data['transaction_amount'],
            'description' => 'Assinatura: ' . $plano['nome'],
            'payment_method_id' => 'pix',
            'payer' => [
                'email' => $email,
                'first_name' => $first_name,
                'last_name' => $last_name,
                'identification' => [
                    'type' => 'CPF',
                    'number' => $cpf_limpo
                ],
            ]
        ];
        
        // Adicionar notification_url apenas se for válida (não localhost)
        if ($webhook_url_for_mp !== null) {
            $payment_data['notification_url'] = $webhook_url_for_mp;
            error_log("SaaS Payment (Mercado Pago): Adicionando notification_url: " . $webhook_url_for_mp);
        } else {
            error_log("SaaS Payment (Mercado Pago): Omitindo notification_url (ambiente local)");
        }
        
        error_log("SaaS Payment (Mercado Pago): Enviando requisição - Valor: " . $payment_data['transaction_amount']);
        error_log("SaaS Payment (Mercado Pago): Payload: " . json_encode($payment_data));
        
        // Gerar chave de idempotência única para evitar pagamentos duplicados
        $idempotency_key = 'saas_plano_' . $plano_id . '_' . $usuario_id . '_' . time() . '_' . bin2hex(random_bytes(8));
        
        $ch = curl_init('https://api.mercadopago.com/v1/payments');
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $token,
            'X-Idempotency-Key: ' . $idempotency_key
        ]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payment_data));
        
        error_log("SaaS Payment (Mercado Pago): X-Idempotency-Key: " . $idempotency_key);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);
        
        // Log da resposta para debug
        error_log("SaaS Payment (Mercado Pago): HTTP Code: " . $http_code);
        error_log("SaaS Payment (Mercado Pago): Response: " . substr($response, 0, 500));
        
        if ($curl_error) {
            error_log("SaaS Payment (Mercado Pago): cURL Error: " . $curl_error);
            throw new Exception("Erro de conexão com Mercado Pago: " . $curl_error);
        }
        
        $res_data = json_decode($response, true);
        
        if ($http_code >= 200 && $http_code < 300 && isset($res_data['status'])) {
            $status = $res_data['status'];
            $payment_id = $res_data['id'];
            
            // Criar assinatura
            $data_inicio = date('Y-m-d');
            $dias_periodo = $plano['periodo'] === 'anual' ? 365 : 30;
            $data_vencimento = date('Y-m-d', strtotime("+{$dias_periodo} days"));
            
            $stmt = $pdo->prepare("
                INSERT INTO saas_assinaturas 
                (usuario_id, plano_id, status, data_inicio, data_vencimento, transacao_id, metodo_pagamento) 
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$usuario_id, $plano_id, $status, $data_inicio, $data_vencimento, $payment_id, 'Pix']);
            
            if ($status == 'pending') {
                returnJsonSuccess([
                    'status' => 'pix_created',
                    'pix_data' => [
                        'qr_code_base64' => $res_data['point_of_interaction']['transaction_data']['qr_code_base64'] ?? null,
                        'qr_code' => $res_data['point_of_interaction']['transaction_data']['qr_code'] ?? '',
                        'payment_id' => $payment_id
                    ],
                    'redirect_url' => '/index?pagina=saas_planos?payment_id=' . $payment_id
                ]);
            } else {
                returnJsonSuccess([
                    'status' => $status,
                    'payment_id' => $payment_id,
                    'redirect_url' => '/index?pagina=saas_planos?payment_id=' . $payment_id
                ]);
            }
        } else {
            // Extrair mensagem de erro detalhada do Mercado Pago
            $error_message = "Erro ao processar pagamento no Mercado Pago";
            $user_friendly_message = "Não foi possível processar o pagamento.";
            
            if ($res_data) {
                if (isset($res_data['message'])) {
                    $error_message = $res_data['message'];
                } elseif (isset($res_data['error'])) {
                    if (is_string($res_data['error'])) {
                        $error_message = $res_data['error'];
                    } elseif (is_array($res_data['error']) && isset($res_data['error']['message'])) {
                        $error_message = $res_data['error']['message'];
                    }
                }
                
                // Adicionar causas se disponíveis
                if (isset($res_data['cause']) && is_array($res_data['cause'])) {
                    $causes = [];
                    foreach ($res_data['cause'] as $cause) {
                        if (isset($cause['description'])) {
                            $causes[] = $cause['description'];
                        } elseif (is_string($cause)) {
                            $causes[] = $cause;
                        }
                    }
                    if (!empty($causes)) {
                        $error_message .= " - " . implode(". ", $causes);
                    }
                }
                
                // Mensagens amigáveis para erros comuns
                if ($http_code === 401 || $http_code === 403) {
                    if (stripos($error_message, 'UNAUTHORIZED') !== false || 
                        stripos($error_message, 'unauthorized') !== false ||
                        stripos($error_message, 'forbidden') !== false) {
                        $user_friendly_message = "Token do Mercado Pago inválido ou sem permissão. Por favor, verifique as credenciais no painel administrativo.";
                    } else {
                        $user_friendly_message = "Erro de autorização no Mercado Pago. Verifique se o token está correto e tem permissão para criar pagamentos PIX.";
                    }
                } elseif ($http_code === 400) {
                    $user_friendly_message = "Dados inválidos enviados ao Mercado Pago. Verifique os dados informados.";
                } elseif ($http_code === 404) {
                    $user_friendly_message = "Recurso não encontrado no Mercado Pago. Verifique a configuração do gateway.";
                } elseif ($http_code >= 500) {
                    $user_friendly_message = "Erro no servidor do Mercado Pago. Tente novamente em alguns instantes.";
                }
            }
            
            error_log("SaaS Payment (Mercado Pago): Erro - HTTP $http_code - $error_message");
            error_log("SaaS Payment (Mercado Pago): Resposta completa: " . json_encode($res_data));
            
            // Para erros 401/403, retornar mensagem amigável ao usuário
            if ($http_code === 401 || $http_code === 403) {
                throw new Exception($user_friendly_message);
            } else {
                throw new Exception("Mercado Pago Error (HTTP $http_code): " . $error_message);
            }
        }
        
    } else {
        throw new Exception("Gateway ou método de pagamento não suportado.");
    }
    
} catch (Exception $e) {
    error_log("SaaS Payment Error (Exception): " . $e->getMessage());
    error_log("SaaS Payment Error File: " . $e->getFile() . " Line: " . $e->getLine());
    error_log("SaaS Payment Error Stack: " . $e->getTraceAsString());
    returnJsonError('Erro ao processar pagamento: ' . $e->getMessage(), 500);
} catch (Error $e) {
    error_log("SaaS Payment Error (Fatal): " . $e->getMessage());
    error_log("SaaS Payment Error File: " . $e->getFile() . " Line: " . $e->getLine());
    error_log("SaaS Payment Error Stack: " . $e->getTraceAsString());
    returnJsonError('Erro interno do servidor: ' . $e->getMessage(), 500);
} catch (Throwable $e) {
    error_log("SaaS Payment Error (Throwable): " . $e->getMessage());
    error_log("SaaS Payment Error File: " . $e->getFile() . " Line: " . $e->getLine());
    error_log("SaaS Payment Error Stack: " . $e->getTraceAsString());
    returnJsonError('Erro ao processar pagamento: ' . $e->getMessage(), 500);
}


