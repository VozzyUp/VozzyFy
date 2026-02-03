<?php
/**
 * Gateway Hypercash - Integração com API Hypercash para Cartão de Crédito
 *
 * Este arquivo contém todas as funções necessárias para comunicação com a API Hypercash
 * seguindo padrões de segurança e modularidade.
 */

/**
 * Captura o IP real do cliente, considerando proxies, ngrok e outros intermediários
 *
 * @return string|null IP do cliente ou null se não encontrar um IP válido
 */
function hypercash_get_client_ip() {
    $ip_keys = [
        'HTTP_CF_CONNECTING_IP', // Cloudflare
        'HTTP_X_FORWARDED_FOR',  // Proxy/NGrok
        'HTTP_X_REAL_IP',        // Nginx
        'REMOTE_ADDR'            // Padrão
    ];
    
    foreach ($ip_keys as $key) {
        if (!empty($_SERVER[$key])) {
            // Se for X-Forwarded-For, pegar o primeiro IP (cliente original)
            $ip = trim(explode(',', $_SERVER[$key])[0]);
            
            // Validar que é um IP válido e não é localhost
            if (filter_var($ip, FILTER_VALIDATE_IP) && 
                !in_array($ip, ['127.0.0.1', '::1', 'localhost'])) {
                return $ip;
            }
        }
    }
    
    return null; // Não enviar IP se não encontrar válido
}

/**
 * Cria uma transação de pagamento com cartão de crédito
 * 
 * @param string $secret_key Secret Key da aplicação Hypercash
 * @param string $public_key Public Key da aplicação Hypercash (para referência, não usada na API)
 * @param float $amount Valor da transação em reais
 * @param string $card_token Token do cartão gerado via FastSoft.encrypt
 * @param array $customer_data Dados do cliente ['name' => string, 'email' => string, 'cpf' => string, 'phone' => string]
 * @param string $description Descrição da transação
 * @param string $webhook_url URL do webhook para notificações
 * @param array $card_data Dados diretos do cartão (opcional, para evitar problemas com 3DS)
 * @param string $client_ip IP do cliente (opcional)
 * @return array ['payment_id' => string, 'status' => 'approved'|'pending'|'rejected', 'message' => string] ou false em caso de erro
 */
function hypercash_create_payment($secret_key, $public_key, $amount, $card_token, $customer_data, $description = '', $webhook_url = '', $card_data = null, $client_ip = null) {
    // Remover espaços em branco das credenciais
    $secret_key = trim($secret_key);
    $public_key = trim($public_key);
    $card_token = trim($card_token);
    
    // Log inicial com informações de debug (sem expor credenciais completas)
    $secret_key_preview = !empty($secret_key) ? substr($secret_key, 0, 8) . '...' . substr($secret_key, -4) : 'VAZIO';
    $public_key_preview = !empty($public_key) ? substr($public_key, 0, 8) . '...' . substr($public_key, -4) : 'VAZIO';
    error_log("Hypercash: Iniciando criação de pagamento - Secret Key: $secret_key_preview, Public Key: $public_key_preview");
    
    if (empty($secret_key) || $amount <= 0) {
        error_log("Hypercash: Parâmetros inválidos - secret_key: " . (!empty($secret_key) ? 'presente' : 'vazio') . ", amount: $amount");
        return false;
    }
    
    // Validar token (obrigatório)
    if (empty($card_token)) {
        error_log("Hypercash: Token do cartão não fornecido");
        return false;
    }
    
    // Validar formato do token (deve ter pelo menos 20 caracteres)
    if (strlen($card_token) < 20) {
        error_log("Hypercash: Token do cartão muito curto (tamanho: " . strlen($card_token) . ")");
        return [
            'error' => true,
            'message' => 'Token do cartão inválido. Por favor, tente novamente.',
            'http_code' => 400
        ];
    }
    
    // Validar CPF (remover formatação)
    $cpf = preg_replace('/[^0-9]/', '', $customer_data['cpf'] ?? '');
    if (strlen($cpf) !== 11) {
        error_log("Hypercash: CPF inválido - CPF fornecido: " . ($customer_data['cpf'] ?? 'vazio') . ", CPF limpo: $cpf, Tamanho: " . strlen($cpf));
        return false;
    }
    
    // Validar se CPF não é uma sequência de zeros ou números repetidos
    if (preg_match('/^(\d)\1{10}$/', $cpf)) {
        error_log("Hypercash: CPF inválido - CPF é uma sequência repetida: $cpf");
        return false;
    }
    
    // Validar dígitos verificadores do CPF
    if (!function_exists('validarCPF')) {
        function validarCPF($cpf) {
            $cpf = preg_replace('/[^0-9]/', '', $cpf);
            if (strlen($cpf) != 11) return false;
            if (preg_match('/(\d)\1{10}/', $cpf)) return false;
            for ($t = 9; $t < 11; $t++) {
                for ($d = 0, $c = 0; $c < $t; $c++) {
                    $d += $cpf[$c] * (($t + 1) - $c);
                }
                $d = ((10 * $d) % 11) % 10;
                if ($cpf[$c] != $d) return false;
            }
            return true;
        }
    }
    
    if (!validarCPF($cpf)) {
        error_log("Hypercash: CPF inválido - CPF não passa na validação de dígitos verificadores: $cpf");
        return false;
    }
    
    // Validar email
    $email = filter_var($customer_data['email'] ?? '', FILTER_VALIDATE_EMAIL);
    if (!$email) {
        error_log("Hypercash: Email inválido: " . ($customer_data['email'] ?? 'vazio'));
        return false;
    }
    
    // URL da API Hypercash
    // Baseado na documentação: https://docs.hypercash.com.br
    // Exemplo da doc mostra GET: https://api.hypercashbrasil.com.br/api/user/transactions
    // Para criar (POST), tentando o mesmo endpoint
    $url = 'https://api.hypercashbrasil.com.br/api/user/transactions';
    
    // Formatar valor (Hypercash espera valor em centavos)
    $amount_cents = (int)round((float)$amount * 100);
    
    error_log("Hypercash: Criando transação - Valor: R$ " . number_format($amount, 2, ',', '.') . " ($amount_cents centavos)");
    
    // Preparar payload conforme documentação da Hypercash
    $payload = [
        'amount' => $amount_cents,
        'paymentMethod' => 'credit_card',
        'installments' => 1, // Padrão: 1 parcela (à vista)
        'customer' => [
            'name' => $customer_data['name'] ?? 'Cliente',
            'email' => $email,
            'document' => [
                'type' => 'CPF',
                'number' => $cpf
            ],
            'phone' => hypercash_format_phone($customer_data['phone'] ?? '00000000000')
        ],
        'items' => [
            [
                'title' => !empty($description) ? substr($description, 0, 500) : 'Produto',
                'quantity' => 1,
                'unitPrice' => $amount_cents,
                'tangible' => false
            ]
        ]
    ];
    
    // Adicionar IP do cliente se disponível (pode ser necessário para 3DS/antifraud)
    if (empty($client_ip)) {
        $client_ip = hypercash_get_client_ip();
    }
    
    if (!empty($client_ip)) {
        $payload['ip'] = $client_ip;
        error_log("Hypercash: IP do cliente adicionado: " . $client_ip);
    } else {
        error_log("Hypercash: IP do cliente não encontrado ou inválido - campo 'ip' será omitido do payload");
    }
    
    // Adicionar dados do cartão
    // Priorizar dados diretos do cartão se disponíveis (evita problemas com 3DS)
    if ($card_data && is_array($card_data)) {
        // Validar e formatar dados do cartão
        $card_number = preg_replace('/[^0-9]/', '', $card_data['number'] ?? '');
        $card_holder_name = trim($card_data['holderName'] ?? $card_data['holder_name'] ?? '');
        $card_exp_month = (int)($card_data['expirationMonth'] ?? $card_data['exp_month'] ?? 0);
        $card_exp_year = (int)($card_data['expirationYear'] ?? $card_data['exp_year'] ?? 0);
        $card_cvv = preg_replace('/[^0-9]/', '', $card_data['cvv'] ?? '');
        
        // Validações
        if (empty($card_number) || strlen($card_number) < 13 || strlen($card_number) > 19) {
            error_log("Hypercash: Número do cartão inválido (tamanho: " . strlen($card_number) . ")");
            return [
                'error' => true,
                'message' => 'Número do cartão inválido.',
                'http_code' => 400
            ];
        }
        if (empty($card_holder_name) || strlen($card_holder_name) > 100) {
            error_log("Hypercash: Nome no cartão inválido (tamanho: " . strlen($card_holder_name) . ", max: 100)");
            return [
                'error' => true,
                'message' => 'Nome no cartão inválido. Deve ter no máximo 100 caracteres.',
                'http_code' => 400
            ];
        }
        if ($card_exp_month < 1 || $card_exp_month > 12) {
            error_log("Hypercash: Mês de expiração inválido: $card_exp_month (deve ser entre 1 e 12)");
            return [
                'error' => true,
                'message' => 'Mês de expiração inválido. Deve ser entre 1 e 12.',
                'http_code' => 400
            ];
        }
        if ($card_exp_year < date('Y') || $card_exp_year > 2065) {
            error_log("Hypercash: Ano de expiração inválido: $card_exp_year");
            return [
                'error' => true,
                'message' => 'Ano de expiração inválido.',
                'http_code' => 400
            ];
        }
        if (empty($card_cvv) || strlen($card_cvv) < 3 || strlen($card_cvv) > 4) {
            error_log("Hypercash: CVV inválido (tamanho: " . strlen($card_cvv) . ")");
            return [
                'error' => true,
                'message' => 'CVV inválido. Deve ter entre 3 e 4 caracteres.',
                'http_code' => 400
            ];
        }
        
        // Adicionar dados do cartão ao payload
        // A API Hypercash não aceita token, apenas dados diretos do cartão
        $payload['card'] = [
            'number' => $card_number,
            'holderName' => $card_holder_name,
            'expirationMonth' => $card_exp_month,
            'expirationYear' => $card_exp_year,
            'cvv' => $card_cvv
        ];
        
        error_log("Hypercash: Usando dados do cartão diretamente no payload (sem token - API não aceita)");
    } else {
        // Se não tiver dados diretos do cartão, retornar erro
        // A API Hypercash requer dados diretos, não aceita apenas token
        error_log("Hypercash: ERRO - Dados diretos do cartão são obrigatórios (API não aceita apenas token)");
        return [
            'error' => true,
            'message' => 'Dados do cartão são obrigatórios. A API requer os dados completos do cartão.',
            'http_code' => 400
        ];
    }
    
    // Adicionar webhook URL se fornecido
    if (!empty($webhook_url)) {
        $payload['postbackUrl'] = $webhook_url;
    }
    
    // Log do payload completo (sem dados sensíveis por segurança)
    $payload_for_log = $payload;
    if (isset($payload_for_log['card']['number'])) {
        $payload_for_log['card']['number'] = substr($payload['card']['number'], 0, 4) . '****';
    }
    if (isset($payload_for_log['card']['cvv'])) {
        $payload_for_log['card']['cvv'] = '***';
    }
    error_log("Hypercash: Payload completo: " . json_encode($payload_for_log, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    
    // Basic Auth conforme documentação: x:SECRET_KEY em base64
    $auth = base64_encode('x:' . $secret_key);
    error_log("Hypercash: Secret Key (primeiros 10 chars): " . substr($secret_key, 0, 10) . "... (tamanho: " . strlen($secret_key) . ")");
    error_log("Hypercash: Authorization header: Basic " . substr($auth, 0, 20) . "...");
    error_log("Hypercash: URL completa da requisição: " . $url);
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Basic ' . $auth,
        'Content-Type: application/json',
        'Accept: application/json'
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    
    $response = curl_exec($ch);
    $curl_error = curl_error($ch);
    $curl_errno = curl_errno($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($curl_error || $curl_errno) {
        error_log("Hypercash Create Payment cURL Error (errno: $curl_errno): " . $curl_error);
        error_log("Hypercash: URL da requisição: " . $url);
        return false;
    }
    
    if (empty($response)) {
        error_log("Hypercash Create Payment: Resposta vazia do servidor (HTTP $http_code)");
        return false;
    }
    
    if ($http_code < 200 || $http_code >= 300) {
        error_log("Hypercash Create Payment HTTP Error ($http_code)");
        error_log("Hypercash: Resposta completa da API: " . $response);
        $error_data = json_decode($response, true);
        $error_message = 'Erro desconhecido';
        if ($error_data) {
            error_log("Hypercash Create Payment Error Details: " . json_encode($error_data, JSON_UNESCAPED_UNICODE));
            if (isset($error_data['message'])) {
                $raw_message = $error_data['message'];
                
                // Tentar decodificar se for JSON string (resposta aninhada)
                if (is_string($raw_message) && (strpos($raw_message, '{') === 0 || strpos($raw_message, '[') === 0)) {
                    $nested_data = json_decode($raw_message, true);
                    if ($nested_data && isset($nested_data['message'])) {
                        $raw_message = $nested_data['message'];
                    }
                }
                
                // A mensagem pode ser string ou array
                if (is_array($raw_message)) {
                    $error_message = implode('. ', $raw_message);
                } else {
                    $error_message = $raw_message;
                }
            } elseif (isset($error_data['error'])) {
                $error_message = is_string($error_data['error']) ? $error_data['error'] : json_encode($error_data['error'], JSON_UNESCAPED_UNICODE);
            }
        }
        return [
            'error' => true,
            'message' => $error_message,
            'http_code' => $http_code,
            'error_data' => $error_data
        ];
    }
    
    $data = json_decode($response, true);
    
    if (!$data) {
        error_log("Hypercash: Resposta inválida (não é JSON)");
        error_log("Hypercash: Resposta completa: " . substr($response, 0, 1000));
        return false;
    }
    
    // Extrair payment_id e status da resposta
    $payment_id = $data['id'] ?? $data['data']['id'] ?? $data['transactionId'] ?? $data['payment_id'] ?? null;
    $status_raw = $data['status'] ?? $data['data']['status'] ?? $data['paymentStatus'] ?? $data['data']['paymentStatus'] ?? null;
    
    // Log detalhado da resposta para debug
    error_log("Hypercash: Resposta completa da API: " . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    error_log("Hypercash: Status raw extraído: " . ($status_raw ?? 'NULL'));
    
    if (!$payment_id) {
        error_log("Hypercash: payment_id não encontrado na resposta");
        error_log("Hypercash: Resposta completa: " . json_encode($data));
        return false;
    }
    
    // Normalizar status conforme mapeamento Hypercash
    $status = 'pending';
    if ($status_raw) {
        $status_upper = strtoupper($status_raw);
        error_log("Hypercash: Status raw (uppercase): " . $status_upper);
        
        if (in_array($status_upper, ['PAID', 'AUTHORIZED', 'APPROVED', 'SUCCESS'])) {
            $status = 'approved';
        } elseif (in_array($status_upper, ['REFUSED', 'CANCELED', 'CANCELLED', 'REJECTED', 'FAILED'])) {
            $status = 'rejected';
        } elseif (in_array($status_upper, ['PROCESSING', 'WAITING_PAYMENT', 'IN_ANALYSIS', 'PENDING'])) {
            $status = 'pending';
        } else {
            // Se o status não está mapeado, logar e manter como pending
            error_log("Hypercash: Status não mapeado: '$status_upper' - mantendo como pending");
            $status = 'pending';
        }
    } else {
        error_log("Hypercash: Status não encontrado na resposta - mantendo como pending");
    }
    
    error_log("Hypercash: Transação criada com sucesso - ID: $payment_id, Status raw: " . ($status_raw ?? 'NULL') . ", Status normalizado: $status");
    
    // Se o status veio como pending ou não veio, consultar imediatamente da API
    // Cartões de crédito geralmente são aprovados/recusados instantaneamente
    if ($status === 'pending' || !$status_raw) {
        error_log("Hypercash: Status inicial é pending ou não encontrado. Consultando status imediatamente da API...");
        $status_data = hypercash_get_payment_status($secret_key, $payment_id);
        if ($status_data && isset($status_data['status'])) {
            $status = $status_data['status'];
            error_log("Hypercash: Status atualizado após consulta imediata: $status");
        }
    }
    
    return [
        'payment_id' => (string)$payment_id,
        'status' => $status,
        'message' => $data['message'] ?? ($status === 'approved' ? 'Pagamento aprovado' : ($status === 'rejected' ? 'Pagamento recusado' : 'Pagamento processado'))
    ];
}

/**
 * Consulta o status de uma transação
 * 
 * @param string $secret_key Secret Key da aplicação Hypercash
 * @param string $payment_id ID da transação
 * @return array|false ['status' => string, 'valor' => float] ou false em caso de erro
 */
function hypercash_get_payment_status($secret_key, $payment_id) {
    $secret_key = trim($secret_key);
    $payment_id = trim($payment_id);
    
    if (empty($secret_key) || empty($payment_id)) {
        error_log("Hypercash: Parâmetros inválidos para consultar status - secret_key: " . (!empty($secret_key) ? 'presente' : 'vazio') . ", payment_id: " . (!empty($payment_id) ? 'presente' : 'vazio'));
        return false;
    }
    
    $url = 'https://api.hypercashbrasil.com.br/api/user/transactions/' . urlencode($payment_id);
    
    error_log("Hypercash: Consultando status do pagamento - ID: " . substr($payment_id, 0, 20) . '...');
    
    // Basic Auth: x:SECRET_KEY em base64
    $auth = base64_encode('x:' . $secret_key);
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Basic ' . $auth,
        'Accept: application/json'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    
    $response = curl_exec($ch);
    $curl_error = curl_error($ch);
    $curl_errno = curl_errno($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($curl_error || $curl_errno) {
        error_log("Hypercash Get Status cURL Error (errno: $curl_errno): " . $curl_error);
        return false;
    }
    
    if ($http_code === 404) {
        error_log("Hypercash: Transação não encontrada (404) - ID: " . $payment_id);
        return ['status' => 'pending'];
    }
    
    if ($http_code !== 200) {
        error_log("Hypercash Get Status HTTP Error ($http_code): " . substr($response, 0, 500));
        return false;
    }
    
    $data = json_decode($response, true);
    
    if (!$data) {
        error_log("Hypercash: Resposta inválida ao consultar status");
        return false;
    }
    
    // Extrair status e valor (pode estar em data.status ou status direto)
    $status_raw = $data['data']['status'] ?? $data['status'] ?? null;
    $amount_cents = $data['data']['amount'] ?? $data['amount'] ?? null;
    
    // Normalizar status conforme mapeamento Hypercash
    $status = 'pending';
    if ($status_raw) {
        $status_upper = strtoupper($status_raw);
        if (in_array($status_upper, ['PAID', 'AUTHORIZED'])) {
            $status = 'approved';
        } elseif (in_array($status_upper, ['REFUSED', 'CANCELED'])) {
            $status = 'rejected';
        } elseif (in_array($status_upper, ['PROCESSING', 'WAITING_PAYMENT', 'IN_ANALYSIS'])) {
            $status = 'pending';
        } else {
            $status = 'pending';
        }
    }
    
    // Converter valor de centavos para reais
    $valor = null;
    if ($amount_cents !== null) {
        $valor = (float)($amount_cents / 100);
    }
    
    error_log("Hypercash: Status obtido - Status: $status, Valor: " . ($valor !== null ? 'R$ ' . number_format($valor, 2, ',', '.') : 'não informado'));
    
    return [
        'status' => $status,
        'valor' => $valor
    ];
}

/**
 * Valida assinatura do webhook (se aplicável)
 * 
 * @param string $payload Payload do webhook (JSON string ou array)
 * @param string $signature Assinatura recebida no header
 * @param string $secret_key Secret Key para validação
 * @return bool true se válido, false caso contrário
 */
function hypercash_validate_webhook_signature($payload, $signature, $secret_key) {
    // Se a Hypercash não usar assinatura de webhook, retornar true
    // Caso contrário, implementar validação conforme documentação
    if (empty($signature)) {
        error_log("Hypercash: Webhook sem assinatura - validando como verdadeiro (ajustar conforme documentação)");
        return true;
    }
    
    // Implementar validação de assinatura conforme documentação da Hypercash
    // Por enquanto, retornar true (ajustar quando tiver documentação específica)
    error_log("Hypercash: Validação de assinatura de webhook não implementada - retornando true");
    return true;
}

/**
 * Formata o telefone para o formato esperado pela API Hypercash
 * A API espera telefone no formato: apenas números, mínimo 10 dígitos
 * 
 * @param string $phone Telefone do cliente (pode estar formatado)
 * @return string Telefone formatado (apenas números, mínimo 10 dígitos)
 */
function hypercash_format_phone($phone) {
    // Remove todos os caracteres não numéricos
    $phone_clean = preg_replace('/[^0-9]/', '', $phone);
    
    // Se o telefone estiver vazio ou muito curto, retorna um telefone padrão válido
    if (empty($phone_clean) || strlen($phone_clean) < 10) {
        // Retorna um telefone padrão válido (11 dígitos: DDD + 9 dígitos)
        return '11999999999';
    }
    
    // Garante que tenha pelo menos 10 dígitos (DDD + 8 dígitos) ou 11 (DDD + 9 dígitos)
    if (strlen($phone_clean) < 10) {
        // Se tiver menos de 10 dígitos, adiciona zeros à esquerda
        $phone_clean = str_pad($phone_clean, 10, '0', STR_PAD_LEFT);
    }
    
    // Limita a 11 dígitos (máximo para celular brasileiro)
    if (strlen($phone_clean) > 11) {
        $phone_clean = substr($phone_clean, 0, 11);
    }
    
    return $phone_clean;
}

