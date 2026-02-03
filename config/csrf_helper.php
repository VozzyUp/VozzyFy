<?php
/**
 * CSRF Protection Helper
 * Gera e valida tokens CSRF para proteção contra ataques Cross-Site Request Forgery
 */

/**
 * Gera um token CSRF único e o armazena na sessão
 * @return string Token CSRF
 */
function generate_csrf_token() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Se já existe um token válido, retorna ele
    if (isset($_SESSION['csrf_token']) && isset($_SESSION['csrf_token_time'])) {
        // Tokens expiram em 7 dias
        if (time() - $_SESSION['csrf_token_time'] < 604800) {
            return $_SESSION['csrf_token'];
        }
    }
    
    // Gera novo token
    $token = bin2hex(random_bytes(32));
    $_SESSION['csrf_token'] = $token;
    $_SESSION['csrf_token_time'] = time();
    
    return $token;
}

/**
 * Valida um token CSRF
 * @param string $token Token a ser validado
 * @return bool True se válido, False se inválido
 */
function validate_csrf_token($token) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    // Verifica se o token existe na sessão
    if (!isset($_SESSION['csrf_token']) || !isset($_SESSION['csrf_token_time'])) {
        return false;
    }
    
    // Verifica se o token expirou (7 dias)
    if (time() - $_SESSION['csrf_token_time'] > 604800) {
        unset($_SESSION['csrf_token'], $_SESSION['csrf_token_time']);
        return false;
    }
    
    // Compara os tokens usando comparação segura
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Obtém o campo hidden do token CSRF para formulários
 * @return string HTML do input hidden
 */
function csrf_field() {
    $token = generate_csrf_token();
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * Valida token CSRF de uma requisição POST/PUT/DELETE
 * Retorna erro JSON se inválido
 * @param array|null $json_input Array JSON já decodificado (opcional, para evitar ler php://input duas vezes)
 */
function validate_csrf_request($json_input = null) {
    // Permite GET e OPTIONS sem token
    if ($_SERVER['REQUEST_METHOD'] === 'GET' || $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        return true;
    }
    
    $token = null;
    
    // Para requisições JSON
    if (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false) {
        if ($json_input !== null) {
            $token = $json_input['csrf_token'] ?? null;
        } else {
            $input = json_decode(file_get_contents('php://input'), true);
            $token = $input['csrf_token'] ?? null;
        }
    } else {
        // Para formulários normais
        $token = $_POST['csrf_token'] ?? null;
    }
    
    if (!$token || !validate_csrf_token($token)) {
        http_response_code(403);
        header('Content-Type: application/json');
        ob_clean();
        echo json_encode(['error' => 'Token CSRF inválido ou expirado']);
        exit;
    }
    
    return true;
}

