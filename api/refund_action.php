<?php
/**
 * API de Ação de Reembolso (Aprovar/Recusar)
 * Permite que infoprodutores aprovem ou recusem solicitações de reembolso
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
$rate_limit = check_rate_limit_db('refund_action', 10, 60, $client_ip); // 10 requisições por minuto
if (!$rate_limit['allowed']) {
    log_security_event('rate_limit_exceeded_refund_action', [
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
$refund_id = isset($data['refund_id']) ? (int)$data['refund_id'] : 0;
$action = isset($data['action']) ? strtolower(trim($data['action'])) : '';
$message = isset($data['message']) ? sanitize_input($data['message'], true) : null;

if ($refund_id <= 0) {
    returnJsonError('ID do reembolso inválido.', 400);
}

if (!in_array($action, ['approve', 'reject'])) {
    returnJsonError('Ação inválida. Use "approve" ou "reject".', 400);
}

// Obter ID do usuário logado
$usuario_id_logado = $_SESSION['id'] ?? 0;
if ($usuario_id_logado <= 0) {
    returnJsonError('ID do usuário não encontrado na sessão.', 400);
}

try {
    // Buscar reembolso e validar ownership
    $stmt = $pdo->prepare("
        SELECT r.*, p.nome as produto_nome, v.data_venda
        FROM reembolsos r
        JOIN produtos p ON r.produto_id = p.id
        JOIN vendas v ON r.venda_id = v.id
        WHERE r.id = ? AND r.usuario_id = ?
    ");
    $stmt->execute([$refund_id, $usuario_id_logado]);
    $reembolso = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$reembolso) {
        returnJsonError('Reembolso não encontrado ou você não tem permissão para processá-lo.', 404);
    }
    
    // Validar se status é 'pending'
    if ($reembolso['status'] !== 'pending') {
        returnJsonError('Este reembolso já foi processado anteriormente.', 400);
    }
    
    // Determinar novo status
    $new_status = ($action === 'approve') ? 'approved' : 'rejected';
    
    // Atualizar reembolso
    $stmt_update = $pdo->prepare("
        UPDATE reembolsos 
        SET status = ?, 
            mensagem_infoprodutor = ?, 
            data_resposta = NOW()
        WHERE id = ?
    ");
    $stmt_update->execute([$new_status, $message, $refund_id]);
    
    // Preparar dados para email
    $refund_data = [
        'id' => $reembolso['id'],
        'comprador_nome' => $reembolso['comprador_nome'],
        'comprador_email' => $reembolso['comprador_email'],
        'produto_nome' => $reembolso['produto_nome'],
        'valor' => $reembolso['valor'],
        'data_venda' => $reembolso['data_venda'],
        'data_solicitacao' => $reembolso['data_solicitacao'],
        'data_resposta' => date('Y-m-d H:i:s'),
        'mensagem_infoprodutor' => $message
    ];
    
    // Enviar email ao cliente
    $email_sent = send_refund_response_email($reembolso['comprador_email'], $refund_data, $new_status);
    
    if (!$email_sent) {
        error_log("REFUND ACTION: Erro ao enviar email, mas reembolso foi atualizado (ID: $refund_id)");
    }
    
    $action_text = ($action === 'approve') ? 'aprovado' : 'recusado';
    returnJsonSuccess([
        'message' => "Reembolso {$action_text} com sucesso. O cliente receberá um email com a decisão.",
        'refund_id' => $refund_id,
        'status' => $new_status
    ]);
    
} catch (PDOException $e) {
    error_log("REFUND ACTION: Erro PDO: " . $e->getMessage());
    returnJsonError('Erro ao processar ação de reembolso.', 500);
} catch (Exception $e) {
    error_log("REFUND ACTION: Erro: " . $e->getMessage());
    returnJsonError('Erro ao processar ação de reembolso: ' . $e->getMessage(), 500);
}

