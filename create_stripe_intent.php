<?php
require __DIR__ . '/config/config.php';

header('Content-Type: application/json');

// Obter dados do POST
$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['amount']) || !isset($input['infoprodutor_id'])) {
    echo json_encode(['error' => 'Dados incompletos.']);
    exit;
}

$amount = $input['amount'];
$infoprodutor_id = $input['infoprodutor_id'];
$customer_name = $input['customer_name'] ?? '';
$customer_email = $input['customer_email'] ?? '';

try {
    // Buscar Stripe Secret Key do vendedor
    $stmt = $pdo->prepare("SELECT stripe_secret_key FROM usuarios WHERE id = ?");
    $stmt->execute([$infoprodutor_id]);
    $credenciais = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$credenciais || empty($credenciais['stripe_secret_key'])) {
        echo json_encode(['error' => 'Stripe não configurado para este vendedor.']);
        exit;
    }

    $stripe_secret_key = $credenciais['stripe_secret_key'];

    // Converter valor para centavos (Stripe usa inteiros)
    $amount_cents = (int) (round($amount * 100));

    // Dados para criar PaymentIntent
    $data = [
        'amount' => $amount_cents,
        'currency' => 'brl',
        'automatic_payment_methods' => ['enabled' => 'true'],
        'description' => 'Venda VozzyFy', // Pode personalizar
        'metadata' => [
            'infoprodutor_id' => $infoprodutor_id,
            'customer_name' => $customer_name,
            'customer_email' => $customer_email
        ]
    ];

    // Preparar query string
    $postFields = http_build_query($data);

    // Inicializar cURL
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://api.stripe.com/v1/payment_intents');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $stripe_secret_key,
        'Content-Type: application/x-www-form-urlencoded'
    ]);

    $response = curl_exec($ch);
    $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);

    curl_close($ch);

    if ($curl_error) {
        echo json_encode(['error' => 'Erro na comunicação com Stripe: ' . $curl_error]);
        exit;
    }

    $result = json_decode($response, true);

    if ($http_status !== 200 || isset($result['error'])) {
        $error_msg = $result['error']['message'] ?? 'Erro desconhecido ao criar PaymentIntent.';
        echo json_encode(['error' => $error_msg, 'debug' => $result]);
        exit;
    }

    // Retornar client_secret e id
    echo json_encode([
        'clientSecret' => $result['client_secret'],
        'paymentIntentId' => $result['id']
    ]);

} catch (Exception $e) {
    echo json_encode(['error' => 'Erro interno: ' . $e->getMessage()]);
}
