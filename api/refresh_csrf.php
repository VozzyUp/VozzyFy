<?php
/**
 * Endpoint para renovar token CSRF
 * Permite que páginas abertas por muito tempo renovem o token automaticamente
 */

// Aplicar headers de segurança
require_once __DIR__ . '/../config/security_headers.php';
if (function_exists('apply_security_headers')) {
    apply_security_headers(false);
}

header('Content-Type: application/json');
ob_start();

// Iniciar sessão se necessário
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Carregar helpers necessários
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/security_helper.php';

// Verificar se usuário está autenticado
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    http_response_code(401);
    ob_clean();
    echo json_encode([
        'success' => false,
        'error' => 'Usuário não autenticado'
    ]);
    exit;
}

// Apenas aceita requisições GET (não precisa de CSRF para renovar o token)
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    ob_clean();
    echo json_encode([
        'success' => false,
        'error' => 'Método não permitido. Use GET.'
    ]);
    exit;
}

try {
    // Gerar novo token CSRF
    $new_token = generate_csrf_token();
    
    // Retornar sucesso com novo token
    ob_clean();
    echo json_encode([
        'success' => true,
        'csrf_token' => $new_token,
        'expires_in' => 604800, // 7 dias em segundos
        'message' => 'Token CSRF renovado com sucesso'
    ]);
    exit;
    
} catch (Exception $e) {
    http_response_code(500);
    ob_clean();
    echo json_encode([
        'success' => false,
        'error' => 'Erro ao renovar token CSRF: ' . $e->getMessage()
    ]);
    exit;
}

