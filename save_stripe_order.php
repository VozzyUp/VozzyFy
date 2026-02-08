<?php
require __DIR__ . '/config/config.php';
require __DIR__ . '/helpers/validation_helper.php';
require __DIR__ . '/helpers/sales_helper.php';

header('Content-Type: application/json');

// Obter dados do POST
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    echo json_encode(['error' => 'Dados inválidos.']);
    exit;
}

// Campos obrigatórios para salvar a venda
$required_fields = ['transaction_amount', 'email', 'name', 'phone', 'product_id', 'payment_intent_id'];
foreach ($required_fields as $field) {
    if (empty($input[$field])) {
        echo json_encode(['error' => "Campo obrigatório ausente: $field"]);
        exit;
    }
}

// Validações básicas (similar ao process_payment.php)
if (!validate_email($input['email'])) {
    echo json_encode(['error' => 'Email inválido.']);
    exit;
}

if (!empty($input['cpf']) && !validate_cpf($input['cpf'])) {
    echo json_encode(['error' => 'CPF inválido.']);
    exit;
}

if (!validate_phone_br($input['phone'])) {
    echo json_encode(['error' => 'Telefone inválido.']);
    exit;
}

// Preparar dados para save_sales
$data = $input;
$main_id = $input['product_id'];
$payment_id = $input['payment_intent_id'];
$status = 'pending'; // Status inicial
$metodo = 'Stripe Cartão'; // Stripe é cartão
$uuid = $input['checkout_session_uuid'] ?? '';
$utm_params = $input['utm_parameters'] ?? [];

try {
    // Salvar venda
    save_sales($pdo, $data, $main_id, $payment_id, $status, $metodo, $uuid, $utm_params);

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    // Verificar se é erro de limite do SaaS (formato: LIMITE_ATINGIDO|msg|url)
    $msg = $e->getMessage();
    if (strpos($msg, 'LIMITE_ATINGIDO|') === 0) {
        $parts = explode('|', $msg);
        echo json_encode([
            'error' => $parts[1] ?? 'Limite atingido',
            'upgrade_url' => $parts[2] ?? null,
            'is_limit_error' => true
        ]);
    } else {
        echo json_encode(['error' => 'Erro ao salvar pedido: ' . $msg]);
    }
}
