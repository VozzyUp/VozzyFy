<?php
/**
 * Teste simples para verificar se o problema é com o código ou com includes
 */

// Desabilitar TUDO
ini_set('display_errors', 0);
ini_set('html_errors', 0);
error_reporting(0);

// Limpar buffers
while (ob_get_level() > 0) {
    ob_end_clean();
}
ob_start();

header('Content-Type: application/json; charset=utf-8');

try {
    // Testar se consegue carregar config
    if (!file_exists(__DIR__ . '/../config/config.php')) {
        throw new Exception('config.php não encontrado');
    }
    
    ob_clean();
    require_once __DIR__ . '/../config/config.php';
    $output = ob_get_clean();
    if (!empty(trim($output))) {
        error_log("Output de config.php: " . substr($output, 0, 200));
    }
    ob_start();
    
    // Testar se consegue carregar security_helper
    if (!file_exists(__DIR__ . '/../helpers/security_helper.php')) {
        throw new Exception('security_helper.php não encontrado');
    }
    
    ob_clean();
    require_once __DIR__ . '/../helpers/security_helper.php';
    $output = ob_get_clean();
    if (!empty(trim($output))) {
        error_log("Output de security_helper.php: " . substr($output, 0, 200));
    }
    ob_start();
    
    // Testar se função existe
    if (!function_exists('require_admin_auth')) {
        throw new Exception('require_admin_auth não existe');
    }
    
    // Testar autenticação
    ob_clean();
    require_admin_auth(true);
    $output = ob_get_clean();
    if (!empty(trim($output))) {
        error_log("Output de require_admin_auth: " . substr($output, 0, 200));
    }
    
    // Se chegou aqui, tudo OK
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Todos os testes passaram!'
    ]);
    
} catch (Exception $e) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
} catch (Error $e) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error' => 'Erro fatal: ' . $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
}

