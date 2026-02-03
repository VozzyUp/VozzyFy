<?php
/**
 * Gateway Asaas - Integração com API Asaas
 * 
 * Este arquivo contém todas as funções necessárias para comunicação com a API Asaas
 * seguindo padrões de segurança e modularidade
 * 
 * Documentação: https://docs.asaas.com/docs/visao-geral
 */

/**
 * Obtém a URL base da API conforme o ambientes
 * 
 * @param string $environment Ambiente: 'production' ou 'sandbox'
 * @return string URL base da API
 */
function asaas_get_api_url($environment = 'sandbox') {
    $environment = strtolower(trim($environment));
    if ($environment === 'production') {
        return 'https://api.asaas.com/v3';
    }
    return 'https://api-sandbox.asaas.com/v3';
}

/**
 * Cria uma cobrança Pix via Asaas
 * 
 * @param string $api_key Chave de API do Asaas
 * @param float $amount Valor da transação em reais
 * @param array $customer_data Dados do cliente ['name' => string, 'email' => string, 'cpfCnpj' => string, 'phone' => string, 'postalCode' => string, 'addressNumber' => string, 'addressComplement' => string|null]
 * @param string $description Descrição da transação
 * @param string $due_date Data de vencimento (formato: Y-m-d)
 * @param string $webhook_url URL do webhook para notificações
 * @param string $environment Ambiente: 'production' ou 'sandbox'
 * @return array ['payment_id' => string, 'qr_code' => string, 'qr_code_base64' => string] ou false em caso de erro
 */
function asaas_create_pix_payment($api_key, $amount, $customer_data, $description = '', $due_date = '', $webhook_url = '', $environment = 'sandbox') {
    // Remover espaços em branco das credenciais
    $api_key = trim($api_key);
    
    // Log inicial com informações de debug (sem expor credenciais completas)
    $api_key_preview = !empty($api_key) ? substr($api_key, 0, 10) . '...' . substr($api_key, -4) : 'VAZIO';
    error_log("[Asaas] Iniciando criação de pagamento Pix - API Key: $api_key_preview, Ambiente: $environment");
    
    if (empty($api_key) || $amount <= 0) {
        error_log("[Asaas] Parâmetros inválidos - api_key: " . (!empty($api_key) ? 'presente' : 'vazio') . ", amount: $amount");
        return false;
    }
    
    // Validar valor mínimo do Asaas (R$ 5,00 para Pix)
    if ($amount < 5.00) {
        error_log("[Asaas] Valor mínimo não atingido - Valor: R$ $amount, Mínimo: R$ 5,00");
        return ['error' => true, 'message' => 'O valor mínimo para pagamento Pix via Asaas é de R$ 5,00.'];
    }
    
    // Validar CPF/CNPJ (remover formatação)
    $cpf_cnpj = preg_replace('/[^0-9]/', '', $customer_data['cpfCnpj'] ?? '');
    if (empty($cpf_cnpj) || (strlen($cpf_cnpj) !== 11 && strlen($cpf_cnpj) !== 14)) {
        error_log("[Asaas] CPF/CNPJ inválido - CPF/CNPJ fornecido: " . ($customer_data['cpfCnpj'] ?? 'vazio') . ", CPF/CNPJ limpo: $cpf_cnpj, Tamanho: " . strlen($cpf_cnpj));
        return false;
    }
    
    // Validar email
    $email = filter_var($customer_data['email'] ?? '', FILTER_VALIDATE_EMAIL);
    if (!$email) {
        error_log("[Asaas] Email inválido: " . ($customer_data['email'] ?? 'vazio'));
        return false;
    }
    
    // Data de vencimento padrão: 1 dia a partir de hoje
    if (empty($due_date)) {
        $due_date = date('Y-m-d', strtotime('+1 day'));
    }
    
    $base_url = asaas_get_api_url($environment);
    $url = $base_url . '/payments';
    
    // Formatar valor (Asaas espera valor em reais com 2 casas decimais)
    $amount_formatted = number_format((float)$amount, 2, '.', '');
    
    // Preparar payload conforme documentação do Asaas
    // O Asaas requer que o customer seja um objeto com os dados completos
    $payload = [
        'billingType' => 'PIX',
        'value' => (float)$amount_formatted,
        'dueDate' => $due_date,
        'description' => !empty($description) ? $description : 'Pagamento via checkout',
        'customer' => [
            'name' => $customer_data['name'] ?? 'Cliente',
            'email' => $email,
            'cpfCnpj' => $cpf_cnpj,
            'phone' => preg_replace('/[^0-9]/', '', $customer_data['phone'] ?? ''),
        ]
    ];
    
    // Adicionar endereço se fornecido
    if (!empty($customer_data['postalCode'])) {
        $payload['customer']['postalCode'] = preg_replace('/[^0-9]/', '', $customer_data['postalCode']);
    }
    if (!empty($customer_data['addressNumber'])) {
        $payload['customer']['addressNumber'] = $customer_data['addressNumber'];
    }
    if (!empty($customer_data['addressComplement'])) {
        $payload['customer']['addressComplement'] = $customer_data['addressComplement'];
    }
    
    // Adicionar webhook se fornecido
    if (!empty($webhook_url)) {
        $payload['notificationDisabled'] = false;
        // O Asaas usa o campo 'notificationUrl' para webhooks, não 'callback'
        // Mas primeiro precisamos configurar na conta Asaas, então não enviamos aqui
        // O webhook será configurado manualmente na conta Asaas apontando para /notification.php
    }
    
    error_log("[Asaas] Criando cobrança Pix - Valor: R$ $amount_formatted, Vencimento: $due_date");
    error_log("[Asaas] Payload completo: " . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'access_token: ' . $api_key,
        'Content-Type: application/json',
        'User-Agent: Checkout-Platform/1.0'
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    
    $response = curl_exec($ch);
    $curl_error = curl_error($ch);
    $curl_errno = curl_errno($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($curl_error || $curl_errno) {
        error_log("[Asaas] Create Pix Payment cURL Error (errno: $curl_errno): " . $curl_error);
        error_log("[Asaas] URL da requisição: " . $url);
        return false;
    }
    
    if (empty($response)) {
        error_log("[Asaas] Create Pix Payment: Resposta vazia do servidor (HTTP $http_code)");
        return false;
    }
    
    if ($http_code < 200 || $http_code >= 300) {
        $error_data = json_decode($response, true);
        $error_message = isset($error_data['errors']) ? json_encode($error_data['errors']) : $response;
        error_log("[Asaas] Create Pix Payment HTTP Error ($http_code): " . substr($error_message, 0, 500));
        error_log("[Asaas] Resposta completa: " . substr($response, 0, 1000));
        return false;
    }
    
    $data = json_decode($response, true);
    if (!$data || json_last_error() !== JSON_ERROR_NONE) {
        error_log("[Asaas] Create Pix Payment: Erro ao decodificar JSON - " . json_last_error_msg());
        error_log("[Asaas] Resposta recebida: " . substr($response, 0, 500));
        return false;
    }
    
    if (!isset($data['id'])) {
        error_log("[Asaas] Create Pix Payment: Resposta não contém 'id' - " . substr($response, 0, 500));
        return false;
    }
    
    $payment_id = $data['id'];
    error_log("[Asaas] Pagamento Pix criado com sucesso - ID: $payment_id");
    
    // Buscar QR Code
    $qr_data = asaas_get_pix_qr_code($api_key, $payment_id, $environment);
    
    return [
        'payment_id' => $payment_id,
        'qr_code' => $qr_data['qr_code'] ?? '',
        'qr_code_base64' => $qr_data['qr_code_base64'] ?? null
    ];
}

/**
 * Obtém o QR Code Pix de uma cobrança
 * 
 * @param string $api_key Chave de API do Asaas
 * @param string $payment_id ID do pagamento
 * @param string $environment Ambiente: 'production' ou 'sandbox'
 * @return array ['qr_code' => string, 'qr_code_base64' => string] ou false em caso de erro
 */
function asaas_get_pix_qr_code($api_key, $payment_id, $environment = 'sandbox') {
    $api_key = trim($api_key);
    $payment_id = trim($payment_id);
    
    if (empty($api_key) || empty($payment_id)) {
        error_log("[Asaas] Parâmetros inválidos para obter QR Code");
        return false;
    }
    
    $base_url = asaas_get_api_url($environment);
    $url = $base_url . '/payments/' . urlencode($payment_id) . '/pixQrCode';
    
    error_log("[Asaas] Buscando QR Code Pix - Payment ID: $payment_id");
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'access_token: ' . $api_key,
        'Content-Type: application/json',
        'User-Agent: Checkout-Platform/1.0'
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
        error_log("[Asaas] Get QR Code cURL Error (errno: $curl_errno): " . $curl_error);
        return false;
    }
    
    if ($http_code !== 200) {
        error_log("[Asaas] Get QR Code HTTP Error ($http_code): " . substr($response, 0, 500));
        return false;
    }
    
    $data = json_decode($response, true);
    if (!$data || json_last_error() !== JSON_ERROR_NONE) {
        error_log("[Asaas] Get QR Code: Erro ao decodificar JSON");
        return false;
    }
    
    return [
        'qr_code' => $data['payload'] ?? '',
        'qr_code_base64' => $data['encodedImage'] ?? null
    ];
}

/**
 * Consulta o status de um pagamento
 * 
 * @param string $api_key Chave de API do Asaas
 * @param string $payment_id ID do pagamento
 * @param string $environment Ambiente: 'production' ou 'sandbox'
 * @return array ['status' => string] ou false em caso de erro
 */
function asaas_get_payment_status($api_key, $payment_id, $environment = 'sandbox') {
    $api_key = trim($api_key);
    $payment_id = trim($payment_id);
    
    if (empty($api_key) || empty($payment_id)) {
        error_log("[Asaas] Parâmetros inválidos para consultar status");
        return false;
    }
    
    $base_url = asaas_get_api_url($environment);
    $url = $base_url . '/payments/' . urlencode($payment_id);
    
    error_log("[Asaas] Consultando status do pagamento - Payment ID: $payment_id");
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'access_token: ' . $api_key,
        'Content-Type: application/json',
        'User-Agent: Checkout-Platform/1.0'
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
        error_log("[Asaas] Get Status cURL Error (errno: $curl_errno): " . $curl_error);
        return false;
    }
    
    if ($http_code === 404) {
        error_log("[Asaas] Pagamento não encontrado (404) - Payment ID: $payment_id");
        return ['status' => 'pending'];
    }
    
    if ($http_code !== 200) {
        error_log("[Asaas] Get Status HTTP Error ($http_code): " . substr($response, 0, 500));
        return false;
    }
    
    $data = json_decode($response, true);
    if (!$data || json_last_error() !== JSON_ERROR_NONE) {
        error_log("[Asaas] Get Status: Erro ao decodificar JSON");
        return false;
    }
    
    // Normalizar status do Asaas para padrão interno
    $status = strtoupper($data['status'] ?? 'PENDING');
    $normalized_status = $status;
    
    // Mapear status do Asaas para padrão interno
    if (in_array($status, ['CONFIRMED', 'RECEIVED'])) {
        $normalized_status = 'approved';
    } elseif (in_array($status, ['OVERDUE', 'REFUNDED'])) {
        $normalized_status = strtolower($status);
    } else {
        $normalized_status = 'pending';
    }
    
    error_log("[Asaas] Status do pagamento - Original: $status, Normalizado: $normalized_status");
    
    return [
        'status' => $normalized_status,
        'original_status' => $status
    ];
}

/**
 * Cria uma cobrança com cartão de crédito via Asaas
 * 
 * @param string $api_key Chave de API do Asaas
 * @param float $amount Valor da transação em reais
 * @param array $card_data Dados do cartão ['number' => string, 'holderName' => string, 'expiryMonth' => string, 'expiryYear' => string, 'ccv' => string] ou null se usar token
 * @param array $customer_data Dados do cliente ['name' => string, 'email' => string, 'cpfCnpj' => string, 'phone' => string, 'postalCode' => string, 'addressNumber' => string, 'addressComplement' => string|null]
 * @param string $description Descrição da transação
 * @param string $due_date Data de vencimento (formato: Y-m-d)
 * @param string $webhook_url URL do webhook para notificações
 * @param int $installments Número de parcelas (padrão: 1)
 * @param string $credit_card_token Token do cartão (opcional, se fornecido, não precisa de card_data)
 * @param string $environment Ambiente: 'production' ou 'sandbox'
 * @return array ['payment_id' => string, 'status' => string] ou false em caso de erro
 */
function asaas_create_card_payment($api_key, $amount, $card_data, $customer_data, $description = '', $due_date = '', $webhook_url = '', $installments = 1, $credit_card_token = null, $environment = 'sandbox') {
    $api_key = trim($api_key);
    
    $api_key_preview = !empty($api_key) ? substr($api_key, 0, 10) . '...' . substr($api_key, -4) : 'VAZIO';
    error_log("[Asaas] Iniciando criação de pagamento com cartão - API Key: $api_key_preview, Ambiente: $environment");
    
    if (empty($api_key) || $amount <= 0) {
        error_log("[Asaas] Parâmetros inválidos para criar pagamento com cartão");
        return false;
    }
    
    // Validar CPF/CNPJ
    $cpf_cnpj = preg_replace('/[^0-9]/', '', $customer_data['cpfCnpj'] ?? '');
    if (empty($cpf_cnpj) || (strlen($cpf_cnpj) !== 11 && strlen($cpf_cnpj) !== 14)) {
        error_log("[Asaas] CPF/CNPJ inválido para pagamento com cartão");
        return false;
    }
    
    // Validar email
    $email = filter_var($customer_data['email'] ?? '', FILTER_VALIDATE_EMAIL);
    if (!$email) {
        error_log("[Asaas] Email inválido para pagamento com cartão");
        return false;
    }
    
    // Data de vencimento padrão: hoje
    if (empty($due_date)) {
        $due_date = date('Y-m-d');
    }
    
    $base_url = asaas_get_api_url($environment);
    $url = $base_url . '/payments';
    
    $amount_formatted = number_format((float)$amount, 2, '.', '');
    $installment_count = max(1, (int)$installments);
    $installment_value = (float)$amount_formatted / $installment_count;

    // Preparar payload
    $payload = [
        'billingType' => 'CREDIT_CARD',
        'value' => (float)$amount_formatted,
        'dueDate' => $due_date,
        'description' => !empty($description) ? $description : 'Pagamento via checkout',
        'installmentCount' => $installment_count,
        'installmentValue' => number_format($installment_value, 2, '.', ''),
        'customer' => [
            'name' => $customer_data['name'] ?? 'Cliente',
            'email' => $email,
            'cpfCnpj' => $cpf_cnpj,
            'phone' => preg_replace('/[^0-9]/', '', $customer_data['phone'] ?? ''),
        ]
    ];
    
    // Adicionar endereço se fornecido
    if (!empty($customer_data['postalCode'])) {
        $payload['customer']['postalCode'] = preg_replace('/[^0-9]/', '', $customer_data['postalCode']);
    }
    if (!empty($customer_data['addressNumber'])) {
        $payload['customer']['addressNumber'] = $customer_data['addressNumber'];
    }
    if (!empty($customer_data['addressComplement'])) {
        $payload['customer']['addressComplement'] = $customer_data['addressComplement'];
    }
    
    // Adicionar dados do cartão ou token
    if (!empty($credit_card_token)) {
        $payload['creditCardToken'] = $credit_card_token;
    } elseif (!empty($card_data)) {
        $payload['creditCard'] = [
            'holderName' => $card_data['holderName'] ?? '',
            'number' => preg_replace('/[^0-9]/', '', $card_data['number'] ?? ''),
            'expiryMonth' => str_pad($card_data['expiryMonth'] ?? '', 2, '0', STR_PAD_LEFT),
            'expiryYear' => $card_data['expiryYear'] ?? '',
            'ccv' => $card_data['ccv'] ?? ''
        ];
        
        // CEP é obrigatório para pagamentos com cartão no Asaas
        $postal_code = preg_replace('/[^0-9]/', '', $customer_data['postalCode'] ?? '');
        if (empty($postal_code) && isset($customer_data['cep'])) {
            $postal_code = preg_replace('/[^0-9]/', '', $customer_data['cep']);
        }
        if (empty($postal_code) && isset($customer_data['address']['cep'])) {
            $postal_code = preg_replace('/[^0-9]/', '', $customer_data['address']['cep']);
        }
        
        if (empty($postal_code)) {
            error_log("[Asaas] CEP não fornecido para pagamento com cartão");
            return ['error' => true, 'message' => 'CEP é obrigatório para pagamento com cartão.'];
        }
        
        $payload['creditCardHolderInfo'] = [
            'name' => $customer_data['name'] ?? '',
            'email' => $email,
            'cpfCnpj' => $cpf_cnpj,
            'postalCode' => $postal_code,
            'addressNumber' => $customer_data['addressNumber'] ?? ($customer_data['address']['numero'] ?? ''),
            'addressComplement' => $customer_data['addressComplement'] ?? ($customer_data['address']['complemento'] ?? null),
            'phone' => preg_replace('/[^0-9]/', '', $customer_data['phone'] ?? ''),
            'mobilePhone' => preg_replace('/[^0-9]/', '', $customer_data['phone'] ?? '')
        ];
    } else {
        error_log("[Asaas] Dados do cartão ou token não fornecidos");
        return false;
    }
    
    // Adicionar IP do cliente se disponível
    $client_ip = null;
    if (function_exists('get_client_ip')) {
        $client_ip = get_client_ip();
    } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
        $client_ip = $_SERVER['REMOTE_ADDR'];
    }
    if ($client_ip) {
        $payload['remoteIp'] = $client_ip;
    }
    
    // Log do payload (sem dados sensíveis)
    $payload_for_log = $payload;
    if (isset($payload_for_log['creditCard']['number'])) {
        $card_number = $payload_for_log['creditCard']['number'];
        $payload_for_log['creditCard']['number'] = substr($card_number, 0, 4) . '****' . substr($card_number, -4);
    }
    if (isset($payload_for_log['creditCard']['ccv'])) {
        $payload_for_log['creditCard']['ccv'] = '***';
    }
    if (isset($payload_for_log['creditCardToken'])) {
        $payload_for_log['creditCardToken'] = '[OCULTO]';
    }
    error_log("[Asaas] Payload completo: " . json_encode($payload_for_log, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'access_token: ' . $api_key,
        'Content-Type: application/json',
        'User-Agent: Checkout-Platform/1.0'
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    
    $response = curl_exec($ch);
    $curl_error = curl_error($ch);
    $curl_errno = curl_errno($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($curl_error || $curl_errno) {
        error_log("[Asaas] Create Card Payment cURL Error (errno: $curl_errno): " . $curl_error);
        return false;
    }
    
    if (empty($response)) {
        error_log("[Asaas] Create Card Payment: Resposta vazia do servidor (HTTP $http_code)");
        return false;
    }
    
    if ($http_code < 200 || $http_code >= 300) {
        $error_data = json_decode($response, true);
        $error_message = isset($error_data['errors']) ? json_encode($error_data['errors']) : $response;
        error_log("[Asaas] Create Card Payment HTTP Error ($http_code): " . substr($error_message, 0, 500));
        return false;
    }
    
    $data = json_decode($response, true);
    if (!$data || json_last_error() !== JSON_ERROR_NONE) {
        error_log("[Asaas] Create Card Payment: Erro ao decodificar JSON");
        return false;
    }
    
    if (!isset($data['id'])) {
        error_log("[Asaas] Create Card Payment: Resposta não contém 'id'");
        return false;
    }
    
    $payment_id = $data['id'];
    $status = strtoupper($data['status'] ?? 'PENDING');
    
    // Normalizar status
    if (in_array($status, ['CONFIRMED', 'RECEIVED'])) {
        $status = 'approved';
    } elseif (in_array($status, ['OVERDUE', 'REFUNDED'])) {
        $status = strtolower($status);
    } else {
        $status = 'pending';
    }
    
    error_log("[Asaas] Pagamento com cartão criado - ID: $payment_id, Status: $status");
    
    return [
        'payment_id' => $payment_id,
        'status' => $status
    ];
}

/**
 * Tokeniza um cartão de crédito
 * 
 * @param string $api_key Chave de API do Asaas
 * @param array $card_data Dados do cartão ['number' => string, 'holderName' => string, 'expiryMonth' => string, 'expiryYear' => string, 'ccv' => string]
 * @param array $customer_data Dados do cliente ['name' => string, 'email' => string, 'cpfCnpj' => string, 'phone' => string, 'postalCode' => string, 'addressNumber' => string]
 * @param string $environment Ambiente: 'production' ou 'sandbox'
 * @return array ['creditCardToken' => string] ou false em caso de erro
 */
function asaas_tokenize_card($api_key, $card_data, $customer_data, $environment = 'sandbox') {
    $api_key = trim($api_key);
    
    if (empty($api_key) || empty($card_data)) {
        error_log("[Asaas] Parâmetros inválidos para tokenizar cartão");
        return false;
    }
    
    $base_url = asaas_get_api_url($environment);
    $url = $base_url . '/creditCard/tokenize';
    
    $cpf_cnpj = preg_replace('/[^0-9]/', '', $customer_data['cpfCnpj'] ?? '');
    
    $payload = [
        'creditCard' => [
            'holderName' => $card_data['holderName'] ?? '',
            'number' => preg_replace('/[^0-9]/', '', $card_data['number'] ?? ''),
            'expiryMonth' => str_pad($card_data['expiryMonth'] ?? '', 2, '0', STR_PAD_LEFT),
            'expiryYear' => $card_data['expiryYear'] ?? '',
            'ccv' => $card_data['ccv'] ?? ''
        ],
        'creditCardHolderInfo' => [
            'name' => $customer_data['name'] ?? '',
            'email' => $customer_data['email'] ?? '',
            'cpfCnpj' => $cpf_cnpj,
            'postalCode' => preg_replace('/[^0-9]/', '', $customer_data['postalCode'] ?? ''),
            'addressNumber' => $customer_data['addressNumber'] ?? '',
            'phone' => preg_replace('/[^0-9]/', '', $customer_data['phone'] ?? ''),
            'mobilePhone' => preg_replace('/[^0-9]/', '', $customer_data['phone'] ?? '')
        ]
    ];
    
    // Adicionar IP do cliente se disponível
    $client_ip = null;
    if (function_exists('get_client_ip')) {
        $client_ip = get_client_ip();
    } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
        $client_ip = $_SERVER['REMOTE_ADDR'];
    }
    if ($client_ip) {
        $payload['remoteIp'] = $client_ip;
    }
    
    // Log do payload (sem dados sensíveis)
    $payload_for_log = $payload;
    if (isset($payload_for_log['creditCard']['number'])) {
        $card_number = $payload_for_log['creditCard']['number'];
        $payload_for_log['creditCard']['number'] = substr($card_number, 0, 4) . '****' . substr($card_number, -4);
    }
    if (isset($payload_for_log['creditCard']['ccv'])) {
        $payload_for_log['creditCard']['ccv'] = '***';
    }
    error_log("[Asaas] Tokenizando cartão - Payload: " . json_encode($payload_for_log, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'access_token: ' . $api_key,
        'Content-Type: application/json',
        'User-Agent: Checkout-Platform/1.0'
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
        error_log("[Asaas] Tokenize Card cURL Error (errno: $curl_errno): " . $curl_error);
        return false;
    }
    
    if ($http_code !== 200) {
        error_log("[Asaas] Tokenize Card HTTP Error ($http_code): " . substr($response, 0, 500));
        return false;
    }
    
    $data = json_decode($response, true);
    if (!$data || json_last_error() !== JSON_ERROR_NONE) {
        error_log("[Asaas] Tokenize Card: Erro ao decodificar JSON");
        return false;
    }
    
    if (!isset($data['creditCardToken'])) {
        error_log("[Asaas] Tokenize Card: Resposta não contém 'creditCardToken'");
        return false;
    }
    
    error_log("[Asaas] Cartão tokenizado com sucesso");
    
    return [
        'creditCardToken' => $data['creditCardToken'],
        'creditCardNumber' => $data['creditCardNumber'] ?? null,
        'creditCardBrand' => $data['creditCardBrand'] ?? null
    ];
}

