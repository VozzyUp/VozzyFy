<?php
/**
 * API de Solicitação de Reembolso
 * Permite que clientes solicitem reembolso de produtos comprados
 */

// Inicia buffer de saída
ob_start();

// Aplicar headers de segurança
require_once __DIR__ . '/../config/security_headers.php';
if (function_exists('apply_security_headers')) {
    apply_security_headers(false);
}

// Desabilita exibição de erros
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Define header JSON
header('Content-Type: application/json');

// Função para retornar erro JSON
function returnJsonError($message, $code = 500) {
    ob_clean();
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

// Função para retornar sucesso JSON
function returnJsonSuccess($data = []) {
    ob_clean();
    http_response_code(200);
    echo json_encode(array_merge(['success' => true], $data));
    exit;
}

// Carregar configuração
$config_paths = [
    __DIR__ . '/../config/config.php',
    __DIR__ . '/../config.php'
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

// Verificar sessão
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Verificar autenticação
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    returnJsonError('Usuário não autenticado.', 401);
}

// Carregar helpers
require_once __DIR__ . '/../helpers/security_helper.php';
require_once __DIR__ . '/../helpers/validation_helper.php';
require_once __DIR__ . '/../helpers/refund_email_helper.php';

// Rate limiting
$client_ip = get_client_ip();
$rate_limit = check_rate_limit_db('refund_request', 5, 60, $client_ip); // 5 requisições por minuto
if (!$rate_limit['allowed']) {
    log_security_event('rate_limit_exceeded_refund', [
        'ip' => $client_ip,
        'reset_at' => $rate_limit['reset_at']
    ]);
    returnJsonError('Muitas requisições. Tente novamente mais tarde.', 429);
}

// Verificar método HTTP
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    returnJsonError('Método não permitido.', 405);
}

// Obter dados do POST
$raw_post_data = file_get_contents('php://input');
$data = json_decode($raw_post_data, true);

if (!$data) {
    returnJsonError('Dados inválidos.', 400);
}

// Validar campos obrigatórios
$produto_id = isset($data['produto_id']) ? (int)$data['produto_id'] : 0;
$motivo = isset($data['motivo']) ? sanitize_input($data['motivo'], true) : null;

if ($produto_id <= 0) {
    returnJsonError('ID do produto inválido.', 400);
}

// Obter email do cliente da sessão
$cliente_email = $_SESSION['usuario'] ?? '';
if (empty($cliente_email)) {
    returnJsonError('Email do cliente não encontrado na sessão.', 400);
}

try {
    // Buscar venda relacionada ao produto e cliente
    // Priorizar vendas aprovadas e mais recentes
    $stmt_venda = $pdo->prepare("
        SELECT v.id, v.valor, v.status_pagamento, v.data_venda, v.transacao_id, 
               v.metodo_pagamento, v.comprador_nome, v.comprador_email,
               p.id as produto_id, p.nome as produto_nome, p.usuario_id
        FROM vendas v
        JOIN produtos p ON v.produto_id = p.id
        WHERE v.produto_id = ? 
        AND v.comprador_email = ?
        AND v.status_pagamento = 'approved'
        ORDER BY v.data_venda DESC
        LIMIT 1
    ");
    $stmt_venda->execute([$produto_id, $cliente_email]);
    $venda = $stmt_venda->fetch(PDO::FETCH_ASSOC);
    
    if (!$venda) {
        returnJsonError('Venda não encontrada ou não aprovada para este produto.', 404);
    }
    
    // Validar se passou menos de 7 dias desde a compra
    $data_venda = new DateTime($venda['data_venda']);
    $data_atual = new DateTime();
    $dias_desde_compra = $data_atual->diff($data_venda)->days;
    
    if ($dias_desde_compra > 7) {
        returnJsonError('O prazo de 7 dias para solicitar reembolso já expirou. Você não pode mais solicitar reembolso para este produto.', 400);
    }
    
    // Verificar se já existe reembolso pendente para esta venda
    $stmt_existing = $pdo->prepare("
        SELECT id, status 
        FROM reembolsos 
        WHERE venda_id = ? AND status = 'pending'
        LIMIT 1
    ");
    $stmt_existing->execute([$venda['id']]);
    $existing_refund = $stmt_existing->fetch(PDO::FETCH_ASSOC);
    
    if ($existing_refund) {
        returnJsonError('Já existe uma solicitação de reembolso pendente para esta compra.', 400);
    }
    
    // Verificar se já existe reembolso aprovado para esta venda
    $stmt_approved = $pdo->prepare("
        SELECT id 
        FROM reembolsos 
        WHERE venda_id = ? AND status = 'approved'
        LIMIT 1
    ");
    $stmt_approved->execute([$venda['id']]);
    $approved_refund = $stmt_approved->fetch(PDO::FETCH_ASSOC);
    
    if ($approved_refund) {
        returnJsonError('Este produto já teve reembolso aprovado anteriormente.', 400);
    }
    
    // Buscar email do infoprodutor
    $stmt_infoprodutor = $pdo->prepare("SELECT usuario as email, nome FROM usuarios WHERE id = ?");
    $stmt_infoprodutor->execute([$venda['usuario_id']]);
    $infoprodutor = $stmt_infoprodutor->fetch(PDO::FETCH_ASSOC);
    
    if (!$infoprodutor || empty($infoprodutor['email'])) {
        error_log("REFUND REQUEST: Infoprodutor não encontrado para usuario_id: " . $venda['usuario_id']);
        returnJsonError('Infoprodutor não encontrado.', 500);
    }
    
    // Inserir reembolso no banco
    $stmt_insert = $pdo->prepare("
        INSERT INTO reembolsos (
            venda_id, produto_id, comprador_email, comprador_nome, valor,
            motivo, status, data_solicitacao, usuario_id, transacao_id, metodo_pagamento
        ) VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW(), ?, ?, ?)
    ");
    
    $stmt_insert->execute([
        $venda['id'],
        $venda['produto_id'],
        $venda['comprador_email'],
        $venda['comprador_nome'],
        $venda['valor'],
        $motivo,
        $venda['usuario_id'],
        $venda['transacao_id'],
        $venda['metodo_pagamento']
    ]);
    
    $refund_id = $pdo->lastInsertId();
    
    // Preparar dados para email
    $refund_data = [
        'id' => $refund_id,
        'comprador_nome' => $venda['comprador_nome'],
        'comprador_email' => $venda['comprador_email'],
        'produto_nome' => $venda['produto_nome'],
        'valor' => $venda['valor'],
        'data_venda' => $venda['data_venda'],
        'data_solicitacao' => date('Y-m-d H:i:s'),
        'motivo' => $motivo
    ];
    
    // Enviar email de notificação ao infoprodutor
    $email_sent = send_refund_request_notification($infoprodutor['email'], $refund_data);
    
    if (!$email_sent) {
        error_log("REFUND REQUEST: Erro ao enviar email, mas reembolso foi criado (ID: $refund_id)");
    }
    
    returnJsonSuccess([
        'message' => 'Solicitação de reembolso criada com sucesso.',
        'refund_id' => $refund_id,
        'dias_desde_compra' => $dias_desde_compra
    ]);
    
} catch (PDOException $e) {
    error_log("REFUND REQUEST: Erro PDO: " . $e->getMessage());
    error_log("REFUND REQUEST: SQL State: " . $e->getCode());
    error_log("REFUND REQUEST: File: " . $e->getFile() . " Line: " . $e->getLine());
    error_log("REFUND REQUEST: Stack trace: " . $e->getTraceAsString());
    returnJsonError('Erro ao processar solicitação de reembolso.', 500);
} catch (Exception $e) {
    error_log("REFUND REQUEST: Erro: " . $e->getMessage());
    error_log("REFUND REQUEST: File: " . $e->getFile() . " Line: " . $e->getLine());
    error_log("REFUND REQUEST: Stack trace: " . $e->getTraceAsString());
    returnJsonError('Erro ao processar solicitação de reembolso: ' . $e->getMessage(), 500);
}

