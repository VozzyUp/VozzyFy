<?php
/**
 * Arquivo de roteamento para API
 * Permite que /api?action=... funcione corretamente
 * Redireciona para api/api.php mantendo todos os parâmetros
 */

// Garantir que a query string seja preservada
if (!empty($_SERVER['QUERY_STRING'])) {
    parse_str($_SERVER['QUERY_STRING'], $_GET);
}

// Incluir o arquivo da API principal
require_once __DIR__ . '/api/api.php';

