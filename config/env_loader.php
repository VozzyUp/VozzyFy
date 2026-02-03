<?php
/**
 * Carregador de Variáveis de Ambiente
 * Carrega variáveis do arquivo .env ou de $_ENV
 */

if (!function_exists('load_env_file')) {
    /**
     * Carrega variáveis de ambiente de um arquivo .env
     * @param string $env_file Caminho para o arquivo .env
     * @return bool True se carregado com sucesso, False caso contrário
     */
    function load_env_file($env_file) {
        if (!file_exists($env_file) || !is_readable($env_file)) {
            return false;
        }
        
        $lines = file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return false;
        }
        
        foreach ($lines as $line) {
            // Ignora comentários
            if (strpos(trim($line), '#') === 0) {
                continue;
            }
            
            // Separa chave e valor
            if (strpos($line, '=') !== false) {
                list($key, $value) = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);
                
                // Remove aspas se presentes
                if ((substr($value, 0, 1) === '"' && substr($value, -1) === '"') ||
                    (substr($value, 0, 1) === "'" && substr($value, -1) === "'")) {
                    $value = substr($value, 1, -1);
                }
                
                // Define variável de ambiente se não existir
                if (!isset($_ENV[$key]) && !getenv($key)) {
                    $_ENV[$key] = $value;
                    putenv("$key=$value");
                }
            }
        }
        
        return true;
    }
}

if (!function_exists('get_env_var')) {
    /**
     * Obtém uma variável de ambiente com fallback
     * @param string $key Chave da variável
     * @param string $default Valor padrão se não encontrado
     * @return string Valor da variável ou default
     */
    function get_env_var($key, $default = '') {
        // Tenta $_ENV primeiro
        if (isset($_ENV[$key]) && !empty($_ENV[$key])) {
            return $_ENV[$key];
        }
        
        // Tenta getenv()
        $value = getenv($key);
        if ($value !== false && !empty($value)) {
            return $value;
        }
        
        // Retorna default
        return $default;
    }
}

// Tenta carregar arquivo .env na raiz do projeto
$env_paths = [
    __DIR__ . '/../.env',
    dirname(__DIR__) . '/.env',
    __DIR__ . '/.env'
];

$env_loaded = false;
foreach ($env_paths as $env_path) {
    if (load_env_file($env_path)) {
        $env_loaded = true;
        break;
    }
}

// Se não encontrou .env, tenta usar variáveis de ambiente do sistema
// (útil para produção onde variáveis são definidas no servidor)

