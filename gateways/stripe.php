<?php

/**
 * Helper function to make requests to Stripe API
 */
function stripe_request($endpoint, $data, $secret_key, $method = 'POST')
{
    $url = 'https://api.stripe.com/v1/' . $endpoint;

    $ch = curl_init();

    // Configurações básicas do cURL
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERPWD, $secret_key . ':');
    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if (!empty($data)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        }
    } else if ($method === 'GET') {
        if (!empty($data)) {
            $url .= '?' . http_build_query($data);
            curl_setopt($ch, CURLOPT_URL, $url);
        }
    }

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);

    curl_close($ch);

    if ($error) {
        throw new Exception("Stripe Connection Error: " . $error);
    }

    $decoded = json_decode($response, true);

    if ($http_code >= 400) {
        $error_msg = $decoded['error']['message'] ?? 'Unknown Stripe Error';
        throw new Exception("Stripe API Error ($http_code): " . $error_msg);
    }

    return $decoded;
}

/**
 * Cria um PaymentIntent para pagamentos únicos (Produtos/Serviços)
 */
function create_stripe_payment_intent($amount_cents, $currency = 'brl', $description, $metadata, $secret_key)
{
    $data = [
        'amount' => $amount_cents,
        'currency' => $currency,
        'description' => $description,
        'metadata' => $metadata,
        'automatic_payment_methods' => [
            'enabled' => 'true',
        ],
    ];

    return stripe_request('payment_intents', $data, $secret_key);
}

/**
 * Cria uma Checkout Session para Pagamento Único (Alternativa ao PaymentIntent para checkout hospedado)
 */
function create_stripe_checkout_session($line_items, $success_url, $cancel_url, $metadata, $secret_key, $is_subscription = false)
{
    $data = [
        'line_items' => $line_items,
        'mode' => $is_subscription ? 'subscription' : 'payment',
        'success_url' => $success_url,
        'cancel_url' => $cancel_url,
        'metadata' => $metadata,
    ];

    // Para assinaturas, metadata deve ser anexado à subscription também
    if ($is_subscription) {
        $data['subscription_data'] = [
            'metadata' => $metadata
        ];
    } else {
        $data['payment_intent_data'] = [
            'metadata' => $metadata
        ];
    }

    return stripe_request('checkout/sessions', $data, $secret_key);
}

/**
 * Cria um Produto e Preço na Stripe (para Assinaturas dinâmicas se necessário)
 */
function create_stripe_price($product_name, $amount_cents, $currency = 'brl', $interval = 'month', $secret_key)
{
    // 1. Criar Produto
    $product_data = ['name' => $product_name];
    $product = stripe_request('products', $product_data, $secret_key);

    // 2. Criar Preço
    $price_data = [
        'unit_amount' => $amount_cents,
        'currency' => $currency,
        'recurring' => ['interval' => $interval],
        'product' => $product['id'],
    ];

    return stripe_request('prices', $price_data, $secret_key);
}

/**
 * Recupera um Customer ou cria se não existir (baseado no email)
 */
function get_or_create_stripe_customer($email, $name, $secret_key)
{
    // Buscar cliente por email
    $search = stripe_request('customers', ['email' => $email, 'limit' => 1], $secret_key, 'GET');

    if (!empty($search['data'])) {
        return $search['data'][0];
    }

    // Criar novo
    return stripe_request('customers', ['email' => $email, 'name' => $name], $secret_key);
}

/**
 * Validar Evento de Webhook
 * Nota: A validação de assinatura manual é complexa sem SDK. 
 * Esta função foca em parsear o payload e verificar o segredo se possível, 
 * mas a validação completa da assinatura HMAC-SHA256 é recomendada.
 */
function verify_stripe_webhook($payload, $sig_header, $endpoint_secret)
{
    // Se não tivermos o segredo, apenas retornamos o payload parseado (menos seguro)
    // Implementação manual de verificação de assinatura

    $timestamp = null;
    $signature = null;

    $items = explode(',', $sig_header);
    foreach ($items as $item) {
        $parts = explode('=', $item, 2);
        if ($parts[0] === 't')
            $timestamp = $parts[1];
        if ($parts[0] === 'v1')
            $signature = $parts[1];
    }

    if (!$timestamp || !$signature) {
        throw new Exception("Invalid signature header");
    }

    // Verificar timestamp para evitar replay attacks (tolerância de 5 min)
    if (abs(time() - $timestamp) > 300) {
        throw new Exception("Timestamp out of tolerance");
    }

    $signed_payload = $timestamp . '.' . $payload;
    $expected_signature = hash_hmac('sha256', $signed_payload, $endpoint_secret);

    if (!hash_equals($expected_signature, $signature)) {
        throw new Exception("Invalid signature");
    }

    return json_decode($payload, true);
}
?>