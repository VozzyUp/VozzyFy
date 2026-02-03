<?php
// Teste muito simples para verificar se o problema é com includes ou com o código
ini_set('display_errors', 0);
ini_set('html_errors', 0);
error_reporting(0);

while (ob_get_level() > 0) {
    ob_end_clean();
}
ob_start();

header('Content-Type: application/json; charset=utf-8');

try {
    require_once __DIR__ . '/../config/config.php';
    $output = ob_get_clean();
    
    if (!empty(trim($output))) {
        echo json_encode([
            'success' => false,
            'error' => 'Output detectado de config.php: ' . substr($output, 0, 200),
            'output_length' => strlen($output)
        ]);
        exit;
    }
    
    ob_start();
    require_once __DIR__ . '/../helpers/security_helper.php';
    $output = ob_get_clean();
    
    if (!empty(trim($output))) {
        echo json_encode([
            'success' => false,
            'error' => 'Output detectado de security_helper.php: ' . substr($output, 0, 200),
            'output_length' => strlen($output)
        ]);
        exit;
    }
    
    ob_start();
    require_admin_auth(true);
    $output = ob_get_clean();
    
    if (!empty(trim($output))) {
        echo json_encode([
            'success' => false,
            'error' => 'Output detectado de require_admin_auth: ' . substr($output, 0, 200),
            'output_length' => strlen($output)
        ]);
        exit;
    }
    
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Nenhum output detectado!'
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

