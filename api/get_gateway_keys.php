<?php
/**
 * Endpoint protegido para obter chaves públicas de gateways
 * Protege contra exposição de credenciais no JavaScript
 */
header('Content-Type: application/json');
ob_start();

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/security_helper.php';

// Verificar se é requisição POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    ob_clean();
    echo json_encode(['success' => false, 'error' => 'Método não permitido']);
    exit;
}

// Verificar CSRF
$input = json_decode(file_get_contents('php://input'), true);
if (empty($input['csrf_token']) || !verify_csrf_token($input['csrf_token'])) {
    http_response_code(403);
    ob_clean();
    echo json_encode(['success' => false, 'error' => 'Token CSRF inválido']);
    exit;
}

// Verificar se checkout_hash foi fornecido
if (empty($input['checkout_hash'])) {
    http_response_code(400);
    ob_clean();
    echo json_encode(['success' => false, 'error' => 'Hash do checkout não fornecido']);
    exit;
}

$checkout_hash = trim($input['checkout_hash']);

try {
    // Buscar produto pelo checkout_hash
    $stmt = $pdo->prepare("SELECT id, usuario_id, gateway FROM produtos WHERE checkout_hash = ? LIMIT 1");
    $stmt->execute([$checkout_hash]);
    $produto = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$produto) {
        http_response_code(404);
        ob_clean();
        echo json_encode(['success' => false, 'error' => 'Produto não encontrado']);
        exit;
    }
    
    // Buscar chaves públicas do vendedor
    $stmt_vendedor = $pdo->prepare("SELECT mp_public_key, beehive_public_key, hypercash_public_key, efi_payee_code FROM usuarios WHERE id = ? LIMIT 1");
    $stmt_vendedor->execute([$produto['usuario_id']]);
    $vendedor_data = $stmt_vendedor->fetch(PDO::FETCH_ASSOC);
    
    if (!$vendedor_data) {
        http_response_code(404);
        ob_clean();
        echo json_encode(['success' => false, 'error' => 'Vendedor não encontrado']);
        exit;
    }
    
    // Retornar apenas as chaves públicas necessárias
    $response = [
        'success' => true,
        'keys' => [
            'beehive_public_key' => $vendedor_data['beehive_public_key'] ?? '',
            'hypercash_public_key' => $vendedor_data['hypercash_public_key'] ?? '',
            'efi_payee_code' => $vendedor_data['efi_payee_code'] ?? ''
        ]
    ];
    
    ob_clean();
    echo json_encode($response);
    exit;
    
} catch (PDOException $e) {
    error_log("Erro ao buscar chaves de gateway: " . $e->getMessage());
    http_response_code(500);
    ob_clean();
    echo json_encode(['success' => false, 'error' => 'Erro interno do servidor']);
    exit;
}

