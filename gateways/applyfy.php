<?php
/**
 * Gateway Applyfy - Integração com API Applyfy
 * 
 * Este arquivo contém todas as funções necessárias para comunicação com a API Applyfy
 * seguindo padrões de segurança e modularidade.
 */

/**
 * Normaliza status do Applyfy para status padrão do sistema
 * 
 * @param string $applyfy_status Status retornado pela API Applyfy
 * @return string Status normalizado
 */
function applyfy_normalize_status($applyfy_status) {
    $status_upper = strtoupper(trim($applyfy_status));
    
    // Mapeamento de status
    if ($status_upper === 'COMPLETED' || $status_upper === 'OK') {
        return 'approved';
    } elseif ($status_upper === 'PENDING') {
        return 'pending';
    } elseif ($status_upper === 'FAILED' || $status_upper === 'REJECTED') {
        return 'rejected';
    } elseif ($status_upper === 'REFUNDED') {
        return 'refunded';
    } elseif ($status_upper === 'CHARGED_BACK') {
        return 'charged_back';
    }
    
    // Status desconhecido - manter como pending por segurança
    error_log("Applyfy: Status não mapeado: '$applyfy_status' - mantendo como pending");
    return 'pending';
}

/**
 * Cria pagamento Pix via Applyfy
 * 
 * @param string $public_key Chave pública do Applyfy
 * @param string $secret_key Chave secreta do Applyfy
 * @param float $amount Valor da transação
 * @param array $client_data Dados do cliente (name, email, phone, document)
 * @param string $identifier Identificador único da transação
 * @param string $callback_url URL de callback para webhook
 * @param array $products Lista de produtos (opcional)
 * @return array|false Dados do pagamento ou false em caso de erro
 */
function applyfy_create_pix_payment($public_key, $secret_key, $amount, $client_data, $identifier, $callback_url = null, $products = []) {
    $public_key = trim($public_key);
    $secret_key = trim($secret_key);
    
    if (empty($public_key) || empty($secret_key)) {
        error_log("Applyfy Pix: Credenciais não fornecidas");
        return false;
    }
    
    $url = 'https://app.applyfy.com.br/api/v1/gateway/pix/receive';
    
    // Validar campos obrigatórios
    if (empty($client_data['name']) || empty($client_data['email']) || empty($client_data['document'])) {
        error_log("Applyfy Pix: Campos obrigatórios faltando - name: " . (!empty($client_data['name']) ? 'ok' : 'vazio') . ", email: " . (!empty($client_data['email']) ? 'ok' : 'vazio') . ", document: " . (!empty($client_data['document']) ? 'ok' : 'vazio'));
        return ['error' => true, 'message' => 'Dados do cliente incompletos. Nome, email e documento são obrigatórios.'];
    }
    
    // Preparar payload
    $phone_cleaned = preg_replace('/\D/', '', $client_data['phone'] ?? '');
    $document_cleaned = preg_replace('/\D/', '', $client_data['document'] ?? '');
    
    $payload = [
        'identifier' => $identifier,
        'amount' => (float)$amount,
        'client' => [
            'name' => trim($client_data['name']),
            'email' => trim($client_data['email']),
            'phone' => $phone_cleaned,
            'document' => $document_cleaned
        ]
    ];
    
    // Adicionar produtos se fornecidos
    if (!empty($products)) {
        $payload['products'] = $products;
    }
    
    // Adicionar callback URL se fornecida
    if (!empty($callback_url)) {
        $payload['callbackUrl'] = $callback_url;
    }
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'x-public-key: ' . $public_key,
        'x-secret-key: ' . $secret_key,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    
    error_log("Applyfy Pix: Enviando requisição para: $url");
    error_log("Applyfy Pix: Identifier: $identifier, Amount: $amount, Client: " . substr($client_data['name'], 0, 20) . '...');
    
    $response = curl_exec($ch);
    $curl_error = curl_error($ch);
    $curl_errno = curl_errno($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($curl_error || $curl_errno) {
        error_log("Applyfy Pix cURL Error (errno: $curl_errno): " . $curl_error);
        return false;
    }
    
    // Applyfy retorna 200 (OK) ou 201 (Created) em caso de sucesso
    if ($http_code !== 200 && $http_code !== 201) {
        $response_preview = substr($response, 0, 1000);
        error_log("Applyfy Pix HTTP Error ($http_code): " . $response_preview);
        
        $error_data = json_decode($response, true);
        if ($error_data) {
            $error_msg = $error_data['message'] ?? 'Erro desconhecido';
            $error_code = $error_data['errorCode'] ?? 'UNKNOWN';
            error_log("Applyfy Pix Error Code: $error_code, Message: $error_msg");
            return [
                'error' => true,
                'message' => $error_msg,
                'error_code' => $error_code
            ];
        }
        
        return false;
    }
    
    $data = json_decode($response, true);
    
    if (!$data || !isset($data['transactionId'])) {
        error_log("Applyfy Pix: Resposta inválida ou transactionId não encontrado");
        error_log("Applyfy Pix: Resposta: " . substr($response, 0, 500));
        return false;
    }
    
    $transaction_id = $data['transactionId'];
    $status = applyfy_normalize_status($data['status'] ?? 'PENDING');
    
    error_log("Applyfy Pix: Transação criada - ID: $transaction_id, Status: $status");
    
    // Extrair dados do Pix
    $pix_data = $data['pix'] ?? [];
    $qr_code = $pix_data['code'] ?? '';
    $qr_code_base64 = $pix_data['base64'] ?? null;
    
    // Se base64 não vier com prefixo data:, adicionar
    if ($qr_code_base64 && strpos($qr_code_base64, 'data:') !== 0) {
        $qr_code_base64 = 'data:image/png;base64,' . $qr_code_base64;
    }
    
    return [
        'transaction_id' => $transaction_id,
        'status' => $status,
        'qr_code' => $qr_code,
        'qr_code_base64' => $qr_code_base64,
        'pix_image_url' => $pix_data['image'] ?? null
    ];
}

/**
 * Cria pagamento com Cartão de Crédito via Applyfy
 * 
 * @param string $public_key Chave pública do Applyfy
 * @param string $secret_key Chave secreta do Applyfy
 * @param float $amount Valor da transação
 * @param array $client_data Dados do cliente (name, email, phone, document, address)
 * @param array $card_data Dados do cartão (number, owner, expiresAt, cvv)
 * @param string $client_ip IP do cliente
 * @param string $identifier Identificador único da transação
 * @param string $callback_url URL de callback para webhook
 * @param array $products Lista de produtos (opcional)
 * @param int $installments Número de parcelas (padrão: 1)
 * @return array|false Dados do pagamento ou false em caso de erro
 */
function applyfy_create_card_payment($public_key, $secret_key, $amount, $client_data, $card_data, $client_ip, $identifier, $callback_url = null, $products = [], $installments = 1) {
    $public_key = trim($public_key);
    $secret_key = trim($secret_key);
    
    if (empty($public_key) || empty($secret_key)) {
        error_log("Applyfy Cartão: Credenciais não fornecidas");
        return false;
    }
    
    $url = 'https://app.applyfy.com.br/api/v1/gateway/card/receive';
    
    // Validar campos obrigatórios
    if (empty($client_data['name']) || empty($client_data['email']) || empty($client_data['document'])) {
        error_log("Applyfy Cartão: Campos obrigatórios faltando - name: " . (!empty($client_data['name']) ? 'ok' : 'vazio') . ", email: " . (!empty($client_data['email']) ? 'ok' : 'vazio') . ", document: " . (!empty($client_data['document']) ? 'ok' : 'vazio'));
        return ['error' => true, 'message' => 'Dados do cliente incompletos. Nome, email e documento são obrigatórios.'];
    }
    
    // Preparar payload
    $phone_cleaned = preg_replace('/\D/', '', $client_data['phone'] ?? '');
    $document_cleaned = preg_replace('/\D/', '', $client_data['document'] ?? '');
    
    // Validar e preparar endereço
    $zip_code_raw = $client_data['address']['zipCode'] ?? '';
    $zip_code = preg_replace('/[^0-9]/', '', $zip_code_raw); // Remover hífen e qualquer caractere não numérico
    $state = strtoupper(trim(substr($client_data['address']['state'] ?? '', 0, 2)));
    $city = trim($client_data['address']['city'] ?? '');
    $street = trim($client_data['address']['street'] ?? '');
    $neighborhood = trim($client_data['address']['neighborhood'] ?? '');
    $number = trim($client_data['address']['number'] ?? '');
    $complement = trim($client_data['address']['complement'] ?? '');
    
    // Validar campos obrigatórios do endereço
    if (empty($zip_code) || strlen($zip_code) !== 8) {
        error_log("Applyfy Cartão: CEP inválido ou vazio");
        return ['error' => true, 'message' => 'CEP inválido. Por favor, informe um CEP válido com 8 dígitos.'];
    }
    if (empty($state) || strlen($state) !== 2) {
        error_log("Applyfy Cartão: Estado inválido ou vazio");
        return ['error' => true, 'message' => 'Estado inválido. Por favor, informe a sigla do estado (ex: SP, RJ).'];
    }
    if (empty($city)) {
        error_log("Applyfy Cartão: Cidade vazia");
        return ['error' => true, 'message' => 'Cidade é obrigatória.'];
    }
    if (empty($street)) {
        error_log("Applyfy Cartão: Logradouro vazio");
        return ['error' => true, 'message' => 'Logradouro é obrigatório.'];
    }
    if (empty($neighborhood)) {
        error_log("Applyfy Cartão: Bairro vazio");
        return ['error' => true, 'message' => 'Bairro é obrigatório.'];
    }
    if (empty($number)) {
        error_log("Applyfy Cartão: Número do endereço vazio");
        return ['error' => true, 'message' => 'Número do endereço é obrigatório.'];
    }
    
    $payload = [
        'identifier' => $identifier,
        'amount' => (float)$amount,
        'client' => [
            'name' => trim($client_data['name']),
            'email' => trim($client_data['email']),
            'phone' => $phone_cleaned,
            'document' => $document_cleaned,
            'address' => [
                'country' => 'BR',
                'zipCode' => strlen($zip_code) === 8 ? substr($zip_code, 0, 5) . '-' . substr($zip_code, 5) : $zip_code, // Formato brasileiro: 67113-230
                'state' => $state,
                'city' => $city,
                'street' => $street,
                'neighborhood' => $neighborhood,
                'number' => $number
            ]
        ],
        'clientIp' => $client_ip,
        'card' => [
            'number' => preg_replace('/[^0-9]/', '', $card_data['number'] ?? ''),
            'owner' => trim($card_data['owner'] ?? $card_data['holderName'] ?? ''),
            'expiresAt' => $card_data['expiresAt'] ?? '', // Formato: YYYY-MM
            'cvv' => preg_replace('/[^0-9]/', '', $card_data['cvv'] ?? '')
        ]
    ];
    
    // Validar campos do cartão
    if (empty($payload['card']['number']) || strlen($payload['card']['number']) < 13) {
        error_log("Applyfy Cartão: Número do cartão inválido");
        return ['error' => true, 'message' => 'Número do cartão inválido.'];
    }
    if (empty($payload['card']['owner']) || strlen($payload['card']['owner']) < 3) {
        error_log("Applyfy Cartão: Nome no cartão inválido");
        return ['error' => true, 'message' => 'Nome no cartão inválido.'];
    }
    if (empty($payload['card']['expiresAt']) || !preg_match('/^\d{4}-\d{2}$/', $payload['card']['expiresAt'])) {
        error_log("Applyfy Cartão: Data de validade inválida: " . ($payload['card']['expiresAt'] ?? 'vazio'));
        return ['error' => true, 'message' => 'Data de validade inválida. Formato esperado: YYYY-MM.'];
    }
    if (empty($payload['card']['cvv']) || strlen($payload['card']['cvv']) < 3) {
        error_log("Applyfy Cartão: CVV inválido");
        return ['error' => true, 'message' => 'CVV inválido.'];
    }
    
    // Adicionar complemento apenas se não estiver vazio
    if (!empty($complement)) {
        $payload['client']['address']['complement'] = $complement;
    }
    
    // Adicionar parcelas se maior que 1
    if ($installments > 1) {
        $payload['installments'] = (int)$installments;
    }
    
    // Adicionar produtos se fornecidos
    if (!empty($products)) {
        $payload['products'] = $products;
    }
    
    // Adicionar callback URL se fornecida
    if (!empty($callback_url)) {
        $payload['callbackUrl'] = $callback_url;
    }
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'x-public-key: ' . $public_key,
        'x-secret-key: ' . $secret_key,
        'Content-Type: application/json'
    ]);
    
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    
    error_log("Applyfy Cartão: Enviando requisição para: $url");
    error_log("Applyfy Cartão: Identifier: $identifier, Amount: $amount");
    error_log("Applyfy Cartão: Endereço - CEP (raw): $zip_code_raw, CEP (limpo): $zip_code, CEP no payload: " . $payload['client']['address']['zipCode'] . ", Estado: $state, Cidade: $city, Logradouro: $street, Bairro: $neighborhood, Número: $number");
    error_log("Applyfy Cartão: Cartão - Owner: " . substr($payload['card']['owner'], 0, 10) . '..., ExpiresAt: ' . $payload['card']['expiresAt']);
    
    $response = curl_exec($ch);
    $curl_error = curl_error($ch);
    $curl_errno = curl_errno($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($curl_error || $curl_errno) {
        error_log("Applyfy Cartão cURL Error (errno: $curl_errno): " . $curl_error);
        return false;
    }
    
    // Applyfy retorna 200 (OK) ou 201 (Created) em caso de sucesso
    if ($http_code !== 200 && $http_code !== 201) {
        $response_preview = substr($response, 0, 1000);
        error_log("Applyfy Cartão HTTP Error ($http_code): " . $response_preview);
        
        $error_data = json_decode($response, true);
        if ($error_data) {
            $error_msg = $error_data['message'] ?? 'Erro desconhecido';
            $error_code = $error_data['errorCode'] ?? 'UNKNOWN';
            $error_details = $error_data['details'] ?? null;
            error_log("Applyfy Cartão Error Code: $error_code, Message: $error_msg");
            if ($error_details) {
                error_log("Applyfy Cartão Error Details: " . json_encode($error_details));
            }
            return [
                'error' => true,
                'message' => $error_msg,
                'error_code' => $error_code,
                'error_details' => $error_details
            ];
        }
        
        return false;
    }
    
    $data = json_decode($response, true);
    
    if (!$data || !isset($data['transactionId'])) {
        error_log("Applyfy Cartão: Resposta inválida ou transactionId não encontrado");
        error_log("Applyfy Cartão: Resposta: " . substr($response, 0, 500));
        return false;
    }
    
    $transaction_id = $data['transactionId'];
    $status_raw = $data['status'] ?? 'PENDING';
    $status = applyfy_normalize_status($status_raw);
    
    error_log("Applyfy Cartão: Transação criada - ID: $transaction_id, Status raw: $status_raw, Status normalizado: $status");
    
    return [
        'transaction_id' => $transaction_id,
        'status' => $status,
        'status_raw' => $status_raw,
        'order' => $data['order'] ?? null,
        'details' => $data['details'] ?? null,
        'error_description' => $data['errorDescription'] ?? null
    ];
}

/**
 * Busca status de uma transação via Applyfy
 * 
 * @param string $public_key Chave pública do Applyfy
 * @param string $secret_key Chave secreta do Applyfy
 * @param string $transaction_id ID da transação
 * @return array|false Dados da transação ou false em caso de erro
 */
function applyfy_get_transaction_status($public_key, $secret_key, $transaction_id) {
    $public_key = trim($public_key);
    $secret_key = trim($secret_key);
    
    if (empty($public_key) || empty($secret_key) || empty($transaction_id)) {
        error_log("Applyfy Get Status: Credenciais ou transaction_id não fornecidos");
        return false;
    }
    
    $url = 'https://app.applyfy.com.br/api/v1/gateway/transactions?id=' . urlencode($transaction_id);
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'x-public-key: ' . $public_key,
        'x-secret-key: ' . $secret_key,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    
    $response = curl_exec($ch);
    $curl_error = curl_error($ch);
    $curl_errno = curl_errno($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($curl_error || $curl_errno) {
        error_log("Applyfy Get Status cURL Error (errno: $curl_errno): " . $curl_error);
        return false;
    }
    
    if ($http_code === 404) {
        error_log("Applyfy Get Status: Transação não encontrada (404) - Transaction ID: $transaction_id");
        return ['status' => 'pending']; // Retornar pending se não encontrada
    }
    
    if ($http_code !== 200) {
        $response_preview = substr($response, 0, 500);
        error_log("Applyfy Get Status HTTP Error ($http_code): " . $response_preview);
        return false;
    }
    
    $data = json_decode($response, true);
    
    if (!$data) {
        error_log("Applyfy Get Status: Erro ao decodificar JSON");
        return false;
    }
    
    $status_raw = $data['status'] ?? 'PENDING';
    $status = applyfy_normalize_status($status_raw);
    
    error_log("Applyfy Get Status: Transaction ID: $transaction_id, Status raw: $status_raw, Status normalizado: $status");
    
    return [
        'status' => $status,
        'status_raw' => $status_raw,
        'status_description' => $data['statusDescription'] ?? null,
        'amount' => $data['amount'] ?? null,
        'charge_amount' => $data['chargeAmount'] ?? null,
        'payment_method' => $data['paymentMethod'] ?? null,
        'error_description' => $data['errorDescription'] ?? null,
        'payed_at' => $data['payedAt'] ?? null
    ];
}

