<?php
/**
 * Teste simples para verificar se update_process.php está funcionando
 */

// Desabilitar exibição de erros
ini_set('display_errors', 0);
ini_set('html_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Limpar buffers
while (ob_get_level() > 0) {
    ob_end_clean();
}
ob_start();

// Shutdown function
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== NULL && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        if (!headers_sent()) {
            @http_response_code(500);
            @header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'error' => 'Erro fatal: ' . $error['message'],
                'file' => $error['file'] ?? 'unknown',
                'line' => $error['line'] ?? 0
            ]);
        }
    }
});

// Definir header JSON
if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}

try {
    // Testar includes
    require_once __DIR__ . '/../config/config.php';
    $output = ob_get_clean();
    if (!empty(trim($output))) {
        throw new Exception('Output detectado de config.php: ' . substr($output, 0, 200));
    }
    ob_start();
    
    require_once __DIR__ . '/../helpers/security_helper.php';
    $output = ob_get_clean();
    if (!empty(trim($output))) {
        throw new Exception('Output detectado de security_helper.php: ' . substr($output, 0, 200));
    }
    ob_start();
    
    require_once __DIR__ . '/../helpers/update_helper.php';
    $output = ob_get_clean();
    if (!empty(trim($output))) {
        throw new Exception('Output detectado de update_helper.php: ' . substr($output, 0, 200));
    }
    ob_start();
    
    require_once __DIR__ . '/../helpers/migration_helper.php';
    $output = ob_get_clean();
    if (!empty(trim($output))) {
        throw new Exception('Output detectado de migration_helper.php: ' . substr($output, 0, 200));
    }
    ob_start();
    
    // Testar autenticação
    require_admin_auth(true);
    $output = ob_get_clean();
    if (!empty(trim($output))) {
        throw new Exception('Output detectado de require_admin_auth: ' . substr($output, 0, 200));
    }
    ob_start();
    
    // Testar funções
    $functions = ['validate_update_files', 'create_backup', 'run_migrations'];
    $missing = [];
    foreach ($functions as $func) {
        if (!function_exists($func)) {
            $missing[] = $func;
        }
    }
    
    if (!empty($missing)) {
        throw new Exception('Funções não encontradas: ' . implode(', ', $missing));
    }
    
    // Testar diretório temp
    $tempDir = __DIR__ . '/../temp/update';
    $tempExists = is_dir($tempDir);
    $tempWritable = $tempExists && is_writable($tempDir);
    
    // Limpar buffers
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Todos os testes passaram!',
        'temp_dir' => [
            'exists' => $tempExists,
            'writable' => $tempWritable,
            'path' => $tempDir
        ],
        'functions' => [
            'validate_update_files' => function_exists('validate_update_files'),
            'create_backup' => function_exists('create_backup'),
            'run_migrations' => function_exists('run_migrations')
        ]
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

