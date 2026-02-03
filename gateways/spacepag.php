<?php
/**
 * Gateway SpacePag - Integração com API Pix SpacePag
 *
 * Base URL: https://api.spacepag.com.br/v1
 * Somente Pix.
 */

define('SPACEPAG_BASE_URL', 'https://api.spacepag.com.br/v1');

/**
 * Obtém access token JWT via POST /auth
 *
 * @param string $public_key Public key (pk_live_... ou pk_test_...)
 * @param string $secret_key Secret key (sk_live_... ou sk_test_...)
 * @return array|false ['access_token' => string, 'expires_in' => int] ou false
 */
function spacepag_get_access_token($public_key, $secret_key) {
    $public_key = trim($public_key);
    $secret_key = trim($secret_key);

    if (empty($public_key) || empty($secret_key)) {
        error_log("SpacePag: Credenciais não fornecidas");
        return false;
    }

    $url = SPACEPAG_BASE_URL . '/auth';
    $payload = json_encode([
        'public_key' => $public_key,
        'secret_key' => $secret_key
    ]);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

    $response = curl_exec($ch);
    $curl_error = curl_error($ch);
    $curl_errno = curl_errno($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curl_error || $curl_errno) {
        error_log("SpacePag Auth cURL Error (errno: $curl_errno): " . $curl_error);
        return false;
    }

    if ($http_code !== 200 && $http_code !== 201) {
        $preview = substr($response, 0, 500);
        error_log("SpacePag Auth HTTP $http_code: " . $preview);
        $decoded = json_decode($response, true);
        if ($decoded && isset($decoded['message'])) {
            error_log("SpacePag Auth message: " . $decoded['message']);
        }
        return false;
    }

    $data = json_decode($response, true);
    if (!$data || empty($data['access_token'])) {
        error_log("SpacePag Auth: Resposta sem access_token");
        return false;
    }

    // API retorna "expireIn" (sem s final) - conforme documentação
    $expires_in = isset($data['expireIn']) ? (int) $data['expireIn'] : 1800;
    return [
        'access_token' => $data['access_token'],
        'expires_in' => $expires_in
    ];
}

/**
 * Cria cobrança Pix via POST /cob
 *
 * @param string $access_token JWT
 * @param float $amount Valor em reais
 * @param array $consumer ['name' => string, 'document' => string (CPF), 'email' => string]
 * @param string $external_id Identificador externo (ex: checkout_session_uuid)
 * @param string $postback_url URL de webhook
 * @return array|false ['transaction_id' => string, 'status' => 'pending', 'qr_code' => string, 'qr_code_base64' => string] ou false / ['error' => true, 'message' => string]
 */
function spacepag_create_pix_charge($access_token, $amount, $consumer, $external_id, $postback_url) {
    $access_token = trim($access_token);
    if (empty($access_token)) {
        error_log("SpacePag Cob: access_token vazio");
        return false;
    }
    // Validar name, email e document (obrigatórios conforme API)
    if (empty($consumer['name']) || empty($consumer['email']) || empty($consumer['document'])) {
        error_log("SpacePag Cob: consumer incompleto (name, email, document obrigatórios)");
        return ['error' => true, 'message' => 'Dados do cliente incompletos. Nome, email e CPF são obrigatórios.'];
    }

    $document = preg_replace('/[^0-9]/', '', $consumer['document']);
    if (strlen($document) !== 11) {
        error_log("SpacePag Cob: documento (CPF) inválido - length: " . strlen($document));
        return ['error' => true, 'message' => 'CPF inválido. Por favor, informe um CPF válido com 11 dígitos.'];
    }
    
    // Formatar CPF como XXX.XXX.XXX-XX (conforme documentação SpacePag)
    $document_formatted = substr($document, 0, 3) . '.' . 
                         substr($document, 3, 3) . '.' . 
                         substr($document, 6, 3) . '-' . 
                         substr($document, 9, 2);

    $amount_float = (float) $amount;
    if ($amount_float <= 0) {
        error_log("SpacePag Cob: amount inválido");
        return ['error' => true, 'message' => 'Valor inválido.'];
    }

    $url = SPACEPAG_BASE_URL . '/cob';
    $payload = [
        'amount' => $amount_float,
        'consumer' => [
            'name' => trim($consumer['name']),
            'document' => $document_formatted, // CPF formatado
            'email' => trim($consumer['email'])
        ],
        'external_id' => $external_id
    ];
    
    // Adicionar postback apenas se não for vazio
    if (!empty($postback_url)) {
        $payload['postback'] = $postback_url;
    }
    
    // Log do payload para debug
    error_log("SpacePag Cob: Payload = " . json_encode($payload));

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $access_token
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

    $response = curl_exec($ch);
    $curl_error = curl_error($ch);
    $curl_errno = curl_errno($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curl_error || $curl_errno) {
        error_log("SpacePag Cob cURL Error (errno: $curl_errno): " . $curl_error);
        return false;
    }

    if ($http_code !== 200 && $http_code !== 201) {
        $preview = substr($response, 0, 500);
        error_log("SpacePag Cob HTTP $http_code: " . $preview);
        $decoded = json_decode($response, true);
        if ($decoded && isset($decoded['message'])) {
            return ['error' => true, 'message' => $decoded['message']];
        }
        return false;
    }

    $data = json_decode($response, true);
    if (!$data || empty($data['transaction_id'])) {
        error_log("SpacePag Cob: Resposta sem transaction_id. Resposta: " . substr($response, 0, 300));
        return false;
    }

    $transaction_id = $data['transaction_id'];
    $status = isset($data['status']) ? strtolower($data['status']) : 'pending';
    $pix = $data['pix'] ?? [];
    $qr_code = $pix['copy_and_paste'] ?? '';
    $qr_code_base64 = $pix['qrcode'] ?? null;
    if ($qr_code_base64 && strpos($qr_code_base64, 'data:') !== 0) {
        $qr_code_base64 = 'data:image/png;base64,' . $qr_code_base64;
    }

    return [
        'transaction_id' => $transaction_id,
        'status' => $status,
        'qr_code' => $qr_code,
        'qr_code_base64' => $qr_code_base64
    ];
}

/**
 * Consulta status da transação Pix via GET /transactions/cob/:transaction_id
 *
 * @param string $access_token JWT
 * @param string $transaction_id ID da transação
 * @return array|false ['status' => 'approved'|'pending'|'rejected', ...] ou false
 */
function spacepag_get_payment_status($access_token, $transaction_id) {
    $access_token = trim($access_token);
    $transaction_id = trim($transaction_id);
    if (empty($access_token) || empty($transaction_id)) {
        error_log("SpacePag GetStatus: access_token ou transaction_id vazio");
        return false;
    }

    $url = SPACEPAG_BASE_URL . '/transactions/cob/' . $transaction_id;

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $access_token
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

    $response = curl_exec($ch);
    $curl_error = curl_error($ch);
    $curl_errno = curl_errno($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curl_error || $curl_errno) {
        error_log("SpacePag GetStatus cURL Error (errno: $curl_errno): " . $curl_error);
        return false;
    }

    if ($http_code === 404) {
        $decoded = json_decode($response, true);
        if ($decoded && isset($decoded['message'])) {
            error_log("SpacePag GetStatus 404: " . $decoded['message']);
        }
        return false;
    }

    if ($http_code !== 200) {
        error_log("SpacePag GetStatus HTTP $http_code: " . substr($response, 0, 300));
        return false;
    }

    $data = json_decode($response, true);
    if (!$data) {
        error_log("SpacePag GetStatus: Resposta JSON inválida");
        return false;
    }

    $api_status = isset($data['status']) ? strtolower(trim($data['status'])) : 'pending';
    $status = 'pending';
    if (in_array($api_status, ['paid', 'approved', 'completed'])) {
        $status = 'approved';
    } elseif (in_array($api_status, ['cancelled', 'rejected', 'failed'])) {
        $status = 'rejected';
    }

    return [
        'status' => $status,
        'raw_status' => $api_status
    ];
}
