<?php
/**
 * API: Obter Documento de Consentimento
 * Retorna HTML do documento ou faz download
 */

// Inicia sessão se necessário
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/security_helper.php';

// Verificar se está logado
if (empty($_SESSION['id'])) {
    http_response_code(401);
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['download'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    } else {
        die('Não autenticado');
    }
    exit;
}

$usuario_id = $_SESSION['id'];

// Verificação CSRF
$csrf_token = null;
$consentimento_id = null;
$download = false;

// Verificar se é download (POST com download=1) ou requisição JSON
$is_download_request = isset($_POST['download']) || isset($_GET['download']);
$content_type = $_SERVER['CONTENT_TYPE'] ?? '';
$is_json_request = strpos($content_type, 'application/json') !== false;
$is_form_data = strpos($content_type, 'multipart/form-data') !== false || strpos($content_type, 'application/x-www-form-urlencoded') !== false;

if ($is_json_request && !$is_download_request) {
    // Requisição JSON (visualização)
    header('Content-Type: application/json');
    
    $raw_input = file_get_contents('php://input');
    $data = json_decode($raw_input, true);
    
    if (isset($data['csrf_token'])) {
        $csrf_token = $data['csrf_token'];
    } elseif (isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
        $csrf_token = $_SERVER['HTTP_X_CSRF_TOKEN'];
    }
    
    $consentimento_id = (int)($data['consentimento_id'] ?? 0);
} else {
    // GET ou POST via formulário/FormData - para download direto
    $consentimento_id = (int)($_GET['consentimento_id'] ?? $_POST['consentimento_id'] ?? 0);
    $download = $is_download_request;
    
    // Tentar obter CSRF token de várias fontes (prioridade: POST > Header > GET)
    $csrf_token = '';
    
    // 1. POST direto (formulário HTML ou FormData) - mais comum para downloads
    if (!empty($_POST['csrf_token'])) {
        $csrf_token = $_POST['csrf_token'];
    }
    // 2. Header (pode vir de fetch com X-CSRF-Token)
    elseif (!empty($_SERVER['HTTP_X_CSRF_TOKEN'])) {
        $csrf_token = $_SERVER['HTTP_X_CSRF_TOKEN'];
    }
    // 3. GET (fallback)
    elseif (!empty($_GET['csrf_token'])) {
        $csrf_token = $_GET['csrf_token'];
    }
    // 4. Body do POST (application/x-www-form-urlencoded) - fallback
    elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST)) {
        $raw_input = file_get_contents('php://input');
        if (!empty($raw_input)) {
            // Tentar parsear como form-urlencoded
            parse_str($raw_input, $form_data);
            if (!empty($form_data['csrf_token'])) {
                $csrf_token = $form_data['csrf_token'];
            }
        }
    }
}

// Debug: verificar se token foi encontrado
if (empty($csrf_token)) {
    error_log("get_consentimento_documento: CSRF token vazio. Method: " . $_SERVER['REQUEST_METHOD'] . 
              ", POST keys: " . implode(', ', array_keys($_POST ?? [])) . 
              ", GET keys: " . implode(', ', array_keys($_GET ?? [])) .
              ", Session token exists: " . (isset($_SESSION['csrf_token']) ? 'yes' : 'no'));
}

// Verificar token CSRF com renovação automática se expirou
$csrf_valid = false;
$new_token = null;

if (empty($csrf_token)) {
    // Se não há token, verificar se é realmente necessário (pode ser que a sessão tenha expirado)
    if (!isset($_SESSION['csrf_token'])) {
        error_log("get_consentimento_documento: Sessão não tem token CSRF. Pode ter expirado.");
    }
    
    log_security_event('missing_csrf_token', [
        'endpoint' => '/api/get_consentimento_documento.php',
        'ip' => get_client_ip(),
        'method' => $_SERVER['REQUEST_METHOD'],
        'has_post' => !empty($_POST),
        'has_get' => !empty($_GET),
        'has_session_token' => isset($_SESSION['csrf_token'])
    ]);
    
    // Tentar gerar novo token se sessão é válida
    if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true) {
        $new_token = generate_csrf_token();
        $csrf_valid = true; // Aceitar após gerar novo token
    }
    
    if (!$csrf_valid) {
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$download) {
            http_response_code(403);
            header('Content-Type: application/json');
            $response = ['success' => false, 'error' => 'Token CSRF ausente. Recarregue a página e tente novamente.'];
            if ($new_token) {
                $response['new_csrf_token'] = $new_token;
            }
            echo json_encode($response);
        } else {
            http_response_code(403);
            die('Token CSRF ausente. Recarregue a página e tente novamente.');
        }
        exit;
    }
} else {
    $csrf_valid = verify_csrf_token($csrf_token);
    
    // Se token inválido mas sessão é válida, tentar renovar
    if (!$csrf_valid && isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true) {
        // Verificar se token expirou (não é inválido por outro motivo)
        if (isset($_SESSION['csrf_token_time']) && (time() - $_SESSION['csrf_token_time']) > 604800) {
            // Token expirou mas sessão é válida - gerar novo token
            $new_token = generate_csrf_token();
            $csrf_valid = true; // Aceitar após renovação
        }
    }
}

if (!$csrf_valid) {
    log_security_event('invalid_csrf_token', [
        'endpoint' => '/api/get_consentimento_documento.php',
        'ip' => get_client_ip(),
        'method' => $_SERVER['REQUEST_METHOD'],
        'has_post' => !empty($_POST),
        'has_get' => !empty($_GET)
    ]);
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$download) {
        http_response_code(403);
        header('Content-Type: application/json');
        $response = ['success' => false, 'error' => 'Token CSRF inválido. Recarregue a página e tente novamente.'];
        if ($new_token) {
            $response['new_csrf_token'] = $new_token;
        }
        echo json_encode($response);
    } else {
        http_response_code(403);
        die('Token CSRF inválido. Recarregue a página e tente novamente.');
    }
    exit;
}

try {
    if (empty($consentimento_id) || $consentimento_id <= 0) {
        throw new Exception('ID do consentimento inválido.');
    }
    
    // Buscar consentimento e verificar permissão
    $stmt = $pdo->prepare("
        SELECT dc.*, p.usuario_id
        FROM download_consentimentos dc
        JOIN produtos p ON dc.produto_id = p.id
        WHERE dc.id = ?
    ");
    $stmt->execute([$consentimento_id]);
    $consentimento = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$consentimento) {
        throw new Exception('Consentimento não encontrado.');
    }
    
    // Verificar se o infoprodutor é dono do produto
    if ($consentimento['usuario_id'] != $usuario_id) {
        throw new Exception('Você não tem permissão para acessar este consentimento.');
    }
    
    // Se for download, retornar como arquivo HTML
    if ($download) {
        $nome_arquivo = 'consentimento_' . $consentimento_id . '_' . date('Y-m-d_His') . '.html';
        
        // Limpar qualquer output anterior
        if (ob_get_level()) {
            ob_clean();
        }
        
        header('Content-Type: text/html; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $nome_arquivo . '"');
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: no-cache');
        
        echo $consentimento['documento_consentimento_html'];
        exit;
    }
    
    // Se for POST (visualização), retornar JSON
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $response = [
            'success' => true,
            'documento_html' => $consentimento['documento_consentimento_html']
        ];
        
        // Incluir novo token se foi renovado
        if (isset($new_token) && $new_token) {
            $response['new_csrf_token'] = $new_token;
        }
        
        echo json_encode($response);
    } else {
        // GET sem download - mostrar HTML
        echo $consentimento['documento_consentimento_html'];
    }
    
} catch (Exception $e) {
    error_log("Erro ao obter documento de consentimento: " . $e->getMessage());
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    } else {
        http_response_code(400);
        die('Erro: ' . htmlspecialchars($e->getMessage()));
    }
}

