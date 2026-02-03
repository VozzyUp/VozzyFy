<?php
/**
 * Teste direto do update_process.php para verificar se retorna JSON corretamente
 */

// Simular sessão admin
session_start();
$_SESSION['loggedin'] = true;
$_SESSION['tipo'] = 'admin';
$_SESSION['id'] = 1;

// Simular ação
$_GET['action'] = 'process';

// Capturar output
ob_start();

try {
    require_once __DIR__ . '/update_process.php';
    $output = ob_get_clean();
    
    echo "=== OUTPUT CAPTURADO ===\n";
    echo $output;
    echo "\n=== FIM DO OUTPUT ===\n";
    
    // Verificar se é JSON válido
    $json = json_decode($output, true);
    if ($json !== null) {
        echo "\n✓ JSON válido!\n";
        echo "Success: " . ($json['success'] ? 'true' : 'false') . "\n";
        if (isset($json['error'])) {
            echo "Error: " . $json['error'] . "\n";
        }
    } else {
        echo "\n✗ JSON inválido ou não é JSON\n";
        echo "Primeiros 200 caracteres: " . substr($output, 0, 200) . "\n";
    }
    
} catch (Exception $e) {
    $output = ob_get_clean();
    echo "EXCEÇÃO: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . "\n";
    echo "Line: " . $e->getLine() . "\n";
    echo "Output: " . substr($output, 0, 500) . "\n";
} catch (Error $e) {
    $output = ob_get_clean();
    echo "ERRO FATAL: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . "\n";
    echo "Line: " . $e->getLine() . "\n";
    echo "Output: " . substr($output, 0, 500) . "\n";
}

