<?php
/**
 * API para retornar plugins disponíveis
 * Alternativa caso o .htaccess bloqueie o JSON
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$json_file = __DIR__ . '/../plugins/plugins_available.json';

if (file_exists($json_file)) {
    $content = file_get_contents($json_file);
    echo $content;
} else {
    http_response_code(404);
    echo json_encode(['error' => 'Arquivo não encontrado']);
}

