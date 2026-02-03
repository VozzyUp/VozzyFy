<?php
// Arquivo wrapper - apenas redireciona para o arquivo da API
// Não declara funções aqui para evitar conflito de redeclaração

// Inicia buffer de saída
ob_start();

// Desabilita exibição de erros
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Define header JSON
header('Content-Type: application/json');

// Verifica se o arquivo existe
if (!file_exists(__DIR__ . '/api/check_status.php')) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['status' => 'error', 'message' => 'Arquivo check_status não encontrado.']);
    exit;
}

// Limpa o buffer e inclui o arquivo da API
ob_end_clean();
require __DIR__ . '/api/check_status.php';
?>

