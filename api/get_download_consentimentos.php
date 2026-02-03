<?php
/**
 * API: Buscar Consentimentos de Download
 * Retorna lista de consentimentos do infoprodutor logado
 */

// Inicia sessão se necessário
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/security_helper.php';

header('Content-Type: application/json');

// Verificar se está logado
if (empty($_SESSION['id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit;
}

$usuario_id = $_SESSION['id'];

// Verificação CSRF
$csrf_token = null;
$raw_input = file_get_contents('php://input');
$data = json_decode($raw_input, true);

if (isset($data['csrf_token'])) {
    $csrf_token = $data['csrf_token'];
} elseif (isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
    $csrf_token = $_SERVER['HTTP_X_CSRF_TOKEN'];
} elseif (isset($_POST['csrf_token'])) {
    $csrf_token = $_POST['csrf_token'];
}

if (empty($csrf_token) || !verify_csrf_token($csrf_token)) {
    log_security_event('invalid_csrf_token', [
        'endpoint' => '/api/get_download_consentimentos.php',
        'ip' => get_client_ip()
    ]);
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Token CSRF inválido']);
    exit;
}

try {
    $search = trim($data['search'] ?? $_POST['search'] ?? '');
    
    // Query base
    $sql = "
        SELECT 
            dc.id,
            dc.aluno_nome,
            dc.aluno_email,
            dc.aluno_cpf,
            dc.data_consentimento,
            p.nome as produto_nome,
            a.titulo as aula_titulo
        FROM download_consentimentos dc
        JOIN produtos p ON dc.produto_id = p.id
        JOIN aulas a ON dc.aula_id = a.id
        WHERE p.usuario_id = ?
    ";
    
    $params = [$usuario_id];
    
    // Adicionar filtro de busca se fornecido
    if (!empty($search)) {
        $sql .= " AND (
            dc.aluno_nome LIKE ? OR 
            dc.aluno_email LIKE ? OR 
            p.nome LIKE ?
        )";
        $searchParam = '%' . $search . '%';
        $params[] = $searchParam;
        $params[] = $searchParam;
        $params[] = $searchParam;
    }
    
    $sql .= " ORDER BY dc.data_consentimento DESC LIMIT 500";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $consentimentos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'consentimentos' => $consentimentos
    ]);
    
} catch (Exception $e) {
    error_log("Erro ao buscar consentimentos: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Erro ao buscar consentimentos.'
    ]);
}

