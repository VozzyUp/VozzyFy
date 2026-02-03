<?php
/**
 * Gateway Beehive - Integração com API Beehive para Cartão de Crédito
 *
 * Este arquivo contém todas as funções necessárias para comunicação com a API Beehive
 * seguindo padrões de segurança e modularidade.
 */

/**
 * Captura o IP real do cliente, considerando proxies, ngrok e outros intermediários
 *
 * @return string|null IP do cliente ou null se não encontrar um IP válido
 */
function get_client_ip() {
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
 * @param string $secret_key Secret Key da aplicação Beehive
 * @param string $public_key Public Key da aplicação Beehive (para referência, não usada na API)
 * @param float $amount Valor da transação em reais
 * @param string $card_token Token do cartão gerado via BeehivePay.encrypt
 * @param array $customer_data Dados do cliente ['name' => string, 'email' => string, 'cpf' => string, 'phone' => string]
 * @param string $description Descrição da transação
 * @param string $webhook_url URL do webhook para notificações
 * @return array ['payment_id' => string, 'status' => 'approved'|'pending'|'rejected', 'message' => string] ou false em caso de erro
 */
function beehive_create_payment($secret_key, $public_key, $amount, $card_token, $customer_data, $description = '', $webhook_url = '', $card_data = null, $client_ip = null) {
    // Remover espaços em branco das credenciais
    $secret_key = trim($secret_key);
    $public_key = trim($public_key);
    $card_token = trim($card_token);
    
    // Log inicial com informações de debug (sem expor credenciais completas)
    $secret_key_preview = !empty($secret_key) ? substr($secret_key, 0, 8) . '...' . substr($secret_key, -4) : 'VAZIO';
    $public_key_preview = !empty($public_key) ? substr($public_key, 0, 8) . '...' . substr($public_key, -4) : 'VAZIO';
    error_log("Beehive: Iniciando criação de pagamento - Secret Key: $secret_key_preview, Public Key: $public_key_preview");
    
    // Verificar se Public Key e Secret Key são do mesmo ambiente
    $secret_is_test = (strpos($secret_key, 'sk_test_') === 0);
    $secret_is_live = (strpos($secret_key, 'sk_live_') === 0);
    $public_is_test = (strpos($public_key, 'pk_test_') === 0);
    $public_is_live = (strpos($public_key, 'pk_live_') === 0);
    
    if ($secret_is_test && !$public_is_test) {
        error_log("Beehive: AVISO - Secret Key é de TESTE mas Public Key não é de teste!");
    }
    if ($secret_is_live && !$public_is_live) {
        error_log("Beehive: AVISO - Secret Key é de PRODUÇÃO mas Public Key não é de produção!");
    }
    
    if (empty($secret_key) || $amount <= 0) {
        error_log("Beehive: Parâmetros inválidos - secret_key: " . (!empty($secret_key) ? 'presente' : 'vazio') . ", amount: $amount");
        return false;
    }
    
    // Validar token (obrigatório conforme documentação da Beehive)
    if (empty($card_token)) {
        error_log("Beehive: Token do cartão não fornecido");
        return false;
    }
    
    // Validar formato do token (deve ter pelo menos 20 caracteres)
    if (strlen($card_token) < 20) {
        error_log("Beehive: Token do cartão muito curto (tamanho: " . strlen($card_token) . ")");
        return [
            'error' => true,
            'message' => 'Token do cartão inválido. Por favor, tente novamente.',
            'http_code' => 400
        ];
    }
    
    // Validar CPF (remover formatação)
    $cpf = preg_replace('/[^0-9]/', '', $customer_data['cpf'] ?? '');
    if (strlen($cpf) !== 11) {
        error_log("Beehive: CPF inválido - CPF fornecido: " . ($customer_data['cpf'] ?? 'vazio') . ", CPF limpo: $cpf, Tamanho: " . strlen($cpf));
        return false;
    }
    
    // Validar se CPF não é uma sequência de zeros ou números repetidos
    if (preg_match('/^(\d)\1{10}$/', $cpf)) {
        error_log("Beehive: CPF inválido - CPF é uma sequência repetida: $cpf");
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
        error_log("Beehive: CPF inválido - CPF não passa na validação de dígitos verificadores: $cpf");
        return false;
    }
    
    // Validar email
    $email = filter_var($customer_data['email'] ?? '', FILTER_VALIDATE_EMAIL);
    if (!$email) {
        error_log("Beehive: Email inválido: " . ($customer_data['email'] ?? 'vazio'));
        return false;
    }
    
    // Verificar se é modo de teste (Secret Key começa com sk_test_)
    $is_test_mode = (strpos($secret_key, 'sk_test_') === 0);
    $url = 'https://api.conta.paybeehive.com.br/v1/transactions';
    
    // Formatar valor (Beehive espera valor em centavos)
    $amount_cents = (int)round((float)$amount * 100);
    
    error_log("Beehive: Modo de teste detectado: " . ($is_test_mode ? 'SIM' : 'NÃO') . " (Secret Key começa com: " . substr($secret_key, 0, 8) . ")");
    
    // Preparar payload conforme documentação da Beehive
    $payload = [
        'amount' => $amount_cents,
        'paymentMethod' => 'credit_card', // Obrigatório: credit_card, boleto ou pix
        'installments' => 1, // Obrigatório para credit_card - padrão: 1 (à vista)
        'customer' => [
            'name' => $customer_data['name'] ?? 'Cliente',
            'email' => $email,
            'document' => [
                'type' => 'cpf', // cpf ou cnpj
                'number' => $cpf // String com apenas números (11 para CPF, 14 para CNPJ)
            ],
            'phone' => beehive_format_phone($customer_data['phone'] ?? '00000000000')
        ],
        'items' => [ // Obrigatório: array de itens (máximo 5)
            [
                'title' => !empty($description) ? substr($description, 0, 500) : 'Produto', // title é obrigatório, maxLength: 500
                'quantity' => 1,
                'unitPrice' => $amount_cents, // unitPrice é obrigatório e deve ser integer
                'tangible' => false // tangible é obrigatório e deve ser boolean
            ]
        ],
        'description' => !empty($description) ? $description : 'Pagamento via checkout'
    ];
    
    // Adicionar IP do cliente se disponível (pode ser necessário para 3DS)
    // Se não foi fornecido, tentar capturar automaticamente
    if (empty($client_ip)) {
        $client_ip = get_client_ip();
    }
    
    if (!empty($client_ip)) {
        $payload['ip'] = $client_ip;
        error_log("Beehive: IP do cliente adicionado: " . $client_ip);
    } else {
        error_log("Beehive: IP do cliente não encontrado ou inválido - campo 'ip' será omitido do payload");
    }
    
    // Conforme documentação da Beehive:
    // - card.hash: Hash do cartão (token gerado via BeehivePay.encrypt) - válido por 5 minutos
    // - card.id: ID do cartão salvo
    // - card.number, card.holderName, etc.: Dados diretos do cartão
    // Priorizar dados diretos do cartão se disponíveis (evita problemas com 3DS)
    // Usar hash apenas se não tiver dados diretos
    if ($card_data && is_array($card_data)) {
        // Validar e formatar dados do cartão
        $card_number = preg_replace('/[^0-9]/', '', $card_data['number'] ?? '');
        $card_holder_name = trim($card_data['holderName'] ?? '');
        $card_exp_month = (int)($card_data['expirationMonth'] ?? 0);
        $card_exp_year = (int)($card_data['expirationYear'] ?? 0);
        $card_cvv = preg_replace('/[^0-9]/', '', $card_data['cvv'] ?? '');
        
        // Validações conforme erros da API
        if (empty($card_number) || strlen($card_number) > 20) {
            error_log("Beehive: Número do cartão inválido (tamanho: " . strlen($card_number) . ", max: 20)");
            return [
                'error' => true,
                'message' => 'Número do cartão inválido. Deve ter no máximo 20 caracteres e apenas números.',
                'http_code' => 400
            ];
        }
        if (empty($card_holder_name) || strlen($card_holder_name) > 100) {
            error_log("Beehive: Nome no cartão inválido (tamanho: " . strlen($card_holder_name) . ", max: 100)");
            return [
                'error' => true,
                'message' => 'Nome no cartão inválido. Deve ter no máximo 100 caracteres.',
                'http_code' => 400
            ];
        }
        if ($card_exp_month < 1 || $card_exp_month > 12) {
            error_log("Beehive: Mês de expiração inválido: $card_exp_month (deve ser entre 1 e 12)");
            return [
                'error' => true,
                'message' => 'Mês de expiração inválido. Deve ser entre 1 e 12.',
                'http_code' => 400
            ];
        }
        if ($card_exp_year < 2025 || $card_exp_year > 2065) {
            error_log("Beehive: Ano de expiração inválido: $card_exp_year (deve ser entre 2025 e 2065)");
            return [
                'error' => true,
                'message' => 'Ano de expiração inválido. Deve ser entre 2025 e 2065.',
                'http_code' => 400
            ];
        }
        if (empty($card_cvv) || strlen($card_cvv) > 4) {
            error_log("Beehive: CVV inválido (tamanho: " . strlen($card_cvv) . ", max: 4)");
            return [
                'error' => true,
                'message' => 'CVV inválido. Deve ter no máximo 4 caracteres.',
                'http_code' => 400
            ];
        }
        
        // Adicionar dados do cartão ao payload
        $payload['card'] = [
            'number' => $card_number,
            'holderName' => $card_holder_name,
            'expirationMonth' => $card_exp_month,
            'expirationYear' => $card_exp_year,
            'cvv' => $card_cvv
        ];
        
        error_log("Beehive: Usando dados do cartão diretamente no payload (priorizado para evitar problemas com 3DS)");
    } elseif (!empty($card_token)) {
        // Usar hash (token) apenas se não tiver dados diretos
        $payload['card'] = [
            'hash' => $card_token
        ];
        error_log("Beehive: Usando hash do cartão (token) - dados diretos não disponíveis");
    } else {
        error_log("Beehive: ERRO - Nem token (hash) nem dados do cartão fornecidos");
        return [
            'error' => true,
            'message' => 'Dados do cartão não fornecidos. É necessário fornecer o hash (token) ou os dados do cartão.',
            'http_code' => 400
        ];
    }
    
    // Adicionar postback URL se fornecido (conforme documentação: postbackUrl)
    if (!empty($webhook_url)) {
        $payload['postbackUrl'] = $webhook_url; // Conforme documentação da Beehive
    }
    
    error_log("Beehive: Criando transação - Valor: R$ " . number_format($amount, 2, ',', '.') . " ($amount_cents centavos)");
    if (!empty($card_token)) {
        error_log("Beehive: Card Token (primeiros 30 chars): " . substr($card_token, 0, 30) . "... (tamanho: " . strlen($card_token) . ")");
        error_log("Beehive: Card Token (últimos 30 chars): ..." . substr($card_token, -30));
    }
    
    // Log do payload completo (sem dados sensíveis por segurança)
    $payload_for_log = $payload;
    if (isset($payload_for_log['card']['hash'])) {
        $payload_for_log['card']['hash'] = '[OCULTO - Tamanho: ' . strlen($card_token) . ']';
    }
    if (isset($payload_for_log['card']['number'])) {
        $payload_for_log['card']['number'] = substr($payload['card']['number'], 0, 4) . '****';
    }
    if (isset($payload_for_log['card']['cvv'])) {
        $payload_for_log['card']['cvv'] = '***';
    }
    error_log("Beehive: Payload completo: " . json_encode($payload_for_log, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    
    // Basic Auth conforme documentação: {SECRET_KEY}:x
    // Documentação: https://paybeehive.readme.io/reference/introducao
    // Formato: Basic base64({SECRET_KEY}:x)
    $auth = base64_encode($secret_key . ':x');
    error_log("Beehive: Secret Key (primeiros 10 chars): " . substr($secret_key, 0, 10) . "... (tamanho: " . strlen($secret_key) . ")");
    error_log("Beehive: Authorization header: Basic " . substr($auth, 0, 20) . "...");
    
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
        error_log("Beehive Create Payment cURL Error (errno: $curl_errno): " . $curl_error);
        error_log("Beehive: URL da requisição: " . $url);
        return false;
    }
    
    if (empty($response)) {
        error_log("Beehive Create Payment: Resposta vazia do servidor (HTTP $http_code)");
        return false;
    }
    
    if ($http_code < 200 || $http_code >= 300) {
        error_log("Beehive Create Payment HTTP Error ($http_code)");
        error_log("Beehive: Resposta completa da API: " . $response);
        $error_data = json_decode($response, true);
        $error_message = 'Erro desconhecido';
        if ($error_data) {
            error_log("Beehive Create Payment Error Details: " . json_encode($error_data, JSON_UNESCAPED_UNICODE));
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
                    
                    // Melhorar mensagem para erro RL-2 (Token inválido)
                    if (is_string($error_message) && (strpos($error_message, 'RL-2') !== false || strpos($error_message, 'Token inválido') !== false)) {
                        $error_message = 'Token do cartão inválido (RL-2). Possíveis causas: 1) Public Key e Secret Key não são do mesmo par/ambiente, 2) Token expirado (use imediatamente após gerar), 3) Public Key ou Secret Key incorretas. Verifique se ambas as chaves são da mesma conta Beehive e do mesmo ambiente (produção ou teste).';
                    }
                    
                    // Melhorar mensagem para erro 3DS
                    if (is_string($error_message) && (stripos($error_message, '3DS') !== false || stripos($error_message, '3d secure') !== false || stripos($error_message, 'Dados 3DS') !== false)) {
                        $error_message = 'Erro na autenticação 3D Secure. Possíveis causas: 1) Cartão não suporta 3DS, 2) IP do cliente inválido ou não capturado corretamente, 3) Configurações da conta Beehive. Por favor, tente novamente com outro cartão ou entre em contato com o suporte da Beehive.';
                        error_log("Beehive: Erro 3DS detectado - IP enviado: " . ($client_ip ?? 'não fornecido'));
                    }
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
        error_log("Beehive: Resposta inválida (não é JSON)");
        error_log("Beehive: Resposta completa: " . substr($response, 0, 1000));
        return false;
    }
    
    // Extrair payment_id e status da resposta
    // A estrutura pode variar, então tentamos diferentes campos possíveis
    $payment_id = $data['id'] ?? $data['transactionId'] ?? $data['payment_id'] ?? null;
    $status_raw = $data['status'] ?? $data['paymentStatus'] ?? null;
    
    if (!$payment_id) {
        error_log("Beehive: payment_id não encontrado na resposta");
        error_log("Beehive: Resposta completa: " . json_encode($data));
        return false;
    }
    
    // Normalizar status
    $status = 'pending';
    if ($status_raw) {
        $status_lower = strtolower($status_raw);
        if (in_array($status_lower, ['approved', 'paid', 'completed', 'success'])) {
            $status = 'approved';
        } elseif (in_array($status_lower, ['rejected', 'refused', 'declined', 'failed'])) {
            $status = 'rejected';
        } else {
            $status = 'pending';
        }
    }
    
    error_log("Beehive: Transação criada com sucesso - ID: $payment_id, Status: $status");
    
    return [
        'payment_id' => (string)$payment_id,
        'status' => $status,
        'message' => $data['message'] ?? ($status === 'approved' ? 'Pagamento aprovado' : 'Pagamento processado')
    ];
}

/**
 * Consulta o status de uma transação
 * 
 * @param string $secret_key Secret Key da aplicação Beehive
 * @param string $payment_id ID da transação
 * @return array|false ['status' => string, 'valor' => float] ou false em caso de erro
 */
function beehive_get_payment_status($secret_key, $payment_id) {
    $secret_key = trim($secret_key);
    $payment_id = trim($payment_id);
    
    if (empty($secret_key) || empty($payment_id)) {
        error_log("Beehive: Parâmetros inválidos para consultar status - secret_key: " . (!empty($secret_key) ? 'presente' : 'vazio') . ", payment_id: " . (!empty($payment_id) ? 'presente' : 'vazio'));
        return false;
    }
    
    $url = 'https://api.conta.paybeehive.com.br/v1/transactions/' . urlencode($payment_id);
    
    error_log("Beehive: Consultando status do pagamento - ID: " . substr($payment_id, 0, 20) . '...');
    
    // Basic Auth: Secret Key como username, senha vazia
    $auth = base64_encode($secret_key . ':');
    
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
        error_log("Beehive Get Status cURL Error (errno: $curl_errno): " . $curl_error);
        return false;
    }
    
    if ($http_code === 404) {
        error_log("Beehive: Transação não encontrada (404) - ID: " . $payment_id);
        return ['status' => 'pending'];
    }
    
    if ($http_code !== 200) {
        error_log("Beehive Get Status HTTP Error ($http_code): " . substr($response, 0, 500));
        return false;
    }
    
    $data = json_decode($response, true);
    
    if (!$data) {
        error_log("Beehive: Resposta inválida ao consultar status");
        return false;
    }
    
    // Extrair status e valor
    $status_raw = $data['status'] ?? $data['paymentStatus'] ?? null;
    $amount_cents = $data['amount'] ?? $data['value'] ?? null;
    
    // Normalizar status
    $status = 'pending';
    if ($status_raw) {
        $status_lower = strtolower($status_raw);
        if (in_array($status_lower, ['approved', 'paid', 'completed', 'success'])) {
            $status = 'approved';
        } elseif (in_array($status_lower, ['rejected', 'refused', 'declined', 'failed'])) {
            $status = 'rejected';
        } else {
            $status = 'pending';
        }
    }
    
    // Converter valor de centavos para reais
    $valor = null;
    if ($amount_cents !== null) {
        $valor = (float)($amount_cents / 100);
    }
    
    error_log("Beehive: Status obtido - Status: $status, Valor: " . ($valor !== null ? 'R$ ' . number_format($valor, 2, ',', '.') : 'não informado'));
    
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
function beehive_validate_webhook_signature($payload, $signature, $secret_key) {
    // Se a Beehive não usar assinatura de webhook, retornar true
    // Caso contrário, implementar validação conforme documentação
    if (empty($signature)) {
        // Se não há assinatura, assumir que é válido (ou retornar false se for obrigatório)
        error_log("Beehive: Webhook sem assinatura - validando como verdadeiro (ajustar conforme documentação)");
        return true;
    }
    
    // Implementar validação de assinatura conforme documentação da Beehive
    // Por enquanto, retornar true (ajustar quando tiver documentação específica)
    error_log("Beehive: Validação de assinatura de webhook não implementada - retornando true");
    return true;
}

/**
 * Formata o telefone para o formato esperado pela API Beehive
 * A API espera telefone no formato: apenas números, mínimo 10 dígitos
 * 
 * @param string $phone Telefone do cliente (pode estar formatado)
 * @return string Telefone formatado (apenas números, mínimo 10 dígitos)
 */
function beehive_format_phone($phone) {
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

