<?php
/**
 * API de Marketplace de Plugins
 * Gerencia plugins disponíveis na loja e instalação via URL/download
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Inicia o buffer de saída
ob_start();

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');
require_once __DIR__ . '/../config/config.php';

// Verificar se é admin
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    ob_clean();
    http_response_code(403);
    echo json_encode(['error' => 'Não autenticado']);
    ob_end_flush();
    exit;
}

if (!isset($_SESSION["tipo"]) || $_SESSION["tipo"] !== 'admin') {
    ob_clean();
    http_response_code(403);
    echo json_encode(['error' => 'Acesso negado. Apenas administradores podem acessar o marketplace.']);
    ob_end_flush();
    exit;
}

// Carrega helpers necessários
require_once __DIR__ . '/../helpers/plugin_installer.php';
require_once __DIR__ . '/../helpers/security_helper.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$marketplace_file = __DIR__ . '/../config/plugins_marketplace.json';
$marketplace_cache_file = sys_get_temp_dir() . '/plugins_marketplace_cache.json';
$marketplace_cache_duration = 3600; // 1 hora em segundos

// Função auxiliar para carregar marketplace (URL externa ou arquivo local)
function load_marketplace($cache_file, $cache_duration, $fallback_file) {
    // URL externa hardcoded (escondida)
    //Não mexer nesta URL
    $external_url = 'https://media.meulink.lat/plugins_marketplace.json';
    
    // Se tem URL externa configurada, tenta usar (com cache)
    if (!empty($external_url) && filter_var($external_url, FILTER_VALIDATE_URL)) {
        // Verifica cache primeiro
        $cache_valid = false;
        $cached_data = null;
        
        if (file_exists($cache_file)) {
            $cache_time = filemtime($cache_file);
            if ((time() - $cache_time) < $cache_duration) {
                $cache_content = @file_get_contents($cache_file);
                if ($cache_content !== false) {
                    $cached_data = json_decode($cache_content, true);
                    if ($cached_data !== null && json_last_error() === JSON_ERROR_NONE) {
                        $cache_valid = true;
                    }
                }
            }
        }
        
        if ($cache_valid && $cached_data !== null) {
            // Usa cache
            return is_array($cached_data) ? $cached_data : [];
        }
        
        // Cache expirado ou inválido - busca da URL externa
        // Tenta primeiro cURL, depois file_get_contents como fallback
        $response_data = null;
        $last_error = null;
        
        // Método 1: Tenta cURL (primeiro com SSL, depois sem)
        $ssl_verify_options = [true, false];
        
        foreach ($ssl_verify_options as $ssl_verify) {
            try {
                $ch = curl_init($external_url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 30); // 30 segundos
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15); // 15 segundos para conectar
                curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $ssl_verify);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $ssl_verify ? 2 : 0);
                curl_setopt($ch, CURLOPT_USERAGENT, 'Getfy Plugin Marketplace/1.0');
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Accept: application/json',
                    'Accept-Charset: UTF-8'
                ]);
                // Configurar DNS alternativo se o DNS padrão falhar
                curl_setopt($ch, CURLOPT_DNS_USE_GLOBAL_CACHE, false);
                
                $response = curl_exec($ch);
                $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curl_error = curl_error($ch);
                $curl_errno = curl_errno($ch);
                curl_close($ch);
                
                // Log detalhado para debug em produção
                error_log("Marketplace: Tentativa cURL - SSL Verify: " . ($ssl_verify ? 'true' : 'false') . " | HTTP Code: $http_code | cURL Error: $curl_error (Code: $curl_errno)");
                
                // Se for erro de DNS (código 6), tenta usar file_get_contents ou IP direto
                if ($curl_errno === 6) {
                    $last_error = "DNS não resolveu: $curl_error";
                    error_log("Marketplace: $last_error - Tentando file_get_contents como fallback...");
                    break; // Para loop cURL e tenta file_get_contents
                }
                
                if ($curl_errno === 0 && $http_code >= 200 && $http_code < 300 && $response !== false && !empty($response)) {
                    $json = json_decode($response, true);
                    
                    if ($json !== null && json_last_error() === JSON_ERROR_NONE && is_array($json)) {
                        // Verifica se o diretório de cache existe e é gravável
                        $cache_dir = dirname($cache_file);
                        if (!is_dir($cache_dir)) {
                            @mkdir($cache_dir, 0755, true);
                        }
                        
                        // Tenta salvar no cache (não crítico se falhar)
                        if (is_writable($cache_dir) || (file_exists($cache_file) && is_writable($cache_file))) {
                            @file_put_contents($cache_file, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                            error_log("Marketplace: Dados salvos no cache com sucesso");
                        } else {
                            error_log("Marketplace: Aviso - Cache não é gravável (diretório: $cache_dir)");
                        }
                        
                        error_log("Marketplace: Dados carregados com sucesso da URL externa (SSL Verify: " . ($ssl_verify ? 'true' : 'false') . ")");
                        return $json;
                    } else {
                        $last_error = "JSON inválido ou não é array. Erro JSON: " . json_last_error_msg();
                        error_log("Marketplace: $last_error | Resposta (primeiros 500 chars): " . substr($response, 0, 500));
                    }
                } else {
                    $last_error = "HTTP Code: $http_code | cURL Error: $curl_error (Code: $curl_errno)";
                    // Se for erro de SSL e ainda não tentou sem SSL, continua para próxima tentativa
                    if ($ssl_verify && ($curl_errno === 60 || strpos($curl_error, 'SSL') !== false || strpos($curl_error, 'certificate') !== false)) {
                        error_log("Marketplace: Erro SSL detectado, tentando sem verificação SSL...");
                        continue;
                    }
                    // Se não for erro SSL ou já tentou sem SSL, para loop cURL
                    break;
                }
            } catch (Exception $e) {
                $last_error = "Exceção cURL: " . $e->getMessage();
                error_log("Marketplace: $last_error");
                if (!$ssl_verify) {
                    break; // Se já tentou sem SSL, para loop
                }
            }
        }
        
        // Método 2: Se cURL falhou (especialmente DNS), tenta file_get_contents como fallback
        if ($response_data === null && ($curl_errno === 6 || strpos($last_error, 'DNS') !== false || strpos($last_error, 'resolve') !== false)) {
            error_log("Marketplace: Tentando file_get_contents como fallback (cURL falhou com DNS)...");
            try {
                // Cria contexto para file_get_contents com SSL desabilitado (mais permissivo)
                $context = stream_context_create([
                    'http' => [
                        'method' => 'GET',
                        'timeout' => 30,
                        'header' => [
                            'Accept: application/json',
                            'User-Agent: Getfy Plugin Marketplace/1.0'
                        ],
                        'ignore_errors' => true
                    ],
                    'ssl' => [
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                        'allow_self_signed' => true
                    ]
                ]);
                
                $response = @file_get_contents($external_url, false, $context);
                
                if ($response !== false && !empty($response)) {
                    $json = json_decode($response, true);
                    
                    if ($json !== null && json_last_error() === JSON_ERROR_NONE && is_array($json)) {
                        error_log("Marketplace: Dados carregados com sucesso usando file_get_contents (fallback)");
                        // Salva no cache
                        $cache_dir = dirname($cache_file);
                        if (!is_dir($cache_dir)) {
                            @mkdir($cache_dir, 0755, true);
                        }
                        if (is_writable($cache_dir) || (file_exists($cache_file) && is_writable($cache_file))) {
                            @file_put_contents($cache_file, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
                        }
                        return $json;
                    }
                }
                
                $last_error .= " | file_get_contents também falhou";
            } catch (Exception $e) {
                $last_error .= " | Exceção file_get_contents: " . $e->getMessage();
                error_log("Marketplace: $last_error");
            }
        }
        
        // Se falhou todas as tentativas, loga erro final
        if ($response_data === null) {
            error_log("Marketplace: ERRO - Não foi possível carregar da URL externa após todas as tentativas (cURL e file_get_contents). Último erro: $last_error | URL: $external_url");
        }
        
        // Se falhou mas tem cache antigo, usa cache mesmo expirado (melhor que nada)
        if ($cached_data !== null && is_array($cached_data)) {
            return $cached_data;
        }
    }
    
    // Fallback: usa arquivo local
    return load_marketplace_from_json($fallback_file);
}

// Função auxiliar para carregar marketplace do JSON local
function load_marketplace_from_json($file) {
    if (!file_exists($file)) {
        return [];
    }
    
    $content = @file_get_contents($file);
    if ($content === false) {
        return [];
    }
    
    $json = json_decode($content, true);
    if (!$json || json_last_error() !== JSON_ERROR_NONE) {
        return [];
    }
    
    return is_array($json) ? $json : [];
}

// Função auxiliar para salvar marketplace no JSON
function save_marketplace_to_json($file, $data) {
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    
    // Cria diretório se não existir
    $dir = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    
    return @file_put_contents($file, $json) !== false;
}

try {
    switch ($action) {
        case 'test_connection':
            // Endpoint de teste para verificar conexão com o marketplace externo
            $external_url = 'https://media.meulink.lat/plugins_marketplace.json';
            $test_result = ['success' => false, 'url' => $external_url, 'details' => []];
            
            try {
                $ch = curl_init($external_url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_USERAGENT, 'Getfy Plugin Marketplace Test/1.0');
                
                $response = curl_exec($ch);
                $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curl_error = curl_error($ch);
                $curl_errno = curl_errno($ch);
                $content_type = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
                curl_close($ch);
                
                $test_result['details'] = [
                    'http_code' => $http_code,
                    'curl_error' => $curl_error,
                    'curl_errno' => $curl_errno,
                    'content_type' => $content_type,
                    'response_length' => $response ? strlen($response) : 0,
                    'response_preview' => $response ? substr($response, 0, 200) : null,
                    'is_json' => $response ? (json_decode($response, true) !== null) : false
                ];
                
                if ($curl_errno === 0 && $http_code >= 200 && $http_code < 300 && $response) {
                    $json = json_decode($response, true);
                    $test_result['success'] = ($json !== null && is_array($json));
                    $test_result['plugin_count'] = is_array($json) ? count($json) : 0;
                }
            } catch (Exception $e) {
                $test_result['details']['exception'] = $e->getMessage();
            }
            
            ob_clean();
            echo json_encode($test_result);
            ob_end_flush();
            exit;
            
        case 'list_available':
            // Lista plugins disponíveis no marketplace (URL externa ou arquivo local)
            $marketplace = load_marketplace($marketplace_cache_file, $marketplace_cache_duration, $marketplace_file);
            
            // Busca plugins instalados para filtrar da loja
            $installed_plugins = [];
            try {
                $stmt = $pdo->prepare("SELECT pasta, nome, versao, ativo FROM plugins");
                $stmt->execute();
                $installed_plugins = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                // Ignora erro se tabela não existir
            }
            
            $installed_slugs = array_column($installed_plugins, 'pasta');
            
            // Filtra plugins já instalados - remove da lista do marketplace
            $marketplace = array_filter($marketplace, function($plugin) use ($installed_slugs) {
                $slug = $plugin['slug'] ?? '';
                // Remove se o slug estiver na lista de instalados
                return !in_array($slug, $installed_slugs);
            });
            
            // Reindexa array após filtro
            $marketplace = array_values($marketplace);
            
            ob_clean();
            echo json_encode(['success' => true, 'plugins' => $marketplace]);
            ob_end_flush();
            exit;
            
        case 'install_from_url':
            // Instala plugin a partir de URL de download
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('Método não permitido');
            }
            
            // Verifica CSRF
            if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
                throw new Exception('Token CSRF inválido');
            }
            
            $download_url = $_POST['download_url'] ?? '';
            $slug = $_POST['slug'] ?? '';
            
            if (empty($download_url)) {
                throw new Exception('URL de download não fornecida');
            }
            
            // Valida URL
            if (!filter_var($download_url, FILTER_VALIDATE_URL)) {
                throw new Exception('URL inválida');
            }
            
            // Instala plugin usando helper
            $result = install_plugin_from_url($download_url);
            
            if (!$result['success']) {
                throw new Exception($result['message'] ?? 'Erro ao instalar plugin');
            }
            
            $plugin_info = $result['plugin_info'] ?? [];
            
            // Registra no banco de dados se não estiver instalado
            if (!empty($plugin_info['pasta'])) {
                try {
                    $stmt = $pdo->prepare("SELECT id FROM plugins WHERE pasta = ?");
                    $stmt->execute([$plugin_info['pasta']]);
                    
                    if (!$stmt->fetch()) {
                        // Não está instalado, registra
                        $stmt = $pdo->prepare("INSERT INTO plugins (nome, pasta, versao, ativo) VALUES (?, ?, ?, 0)");
                        $stmt->execute([
                            $plugin_info['nome'] ?? $plugin_info['pasta'],
                            $plugin_info['pasta'],
                            $plugin_info['versao'] ?? '1.0.0'
                        ]);
                    }
                } catch (PDOException $e) {
                    // Ignora erro se tabela não existir
                }
            }
            
            ob_clean();
            echo json_encode([
                'success' => true,
                'message' => 'Plugin instalado com sucesso',
                'plugin' => $plugin_info
            ]);
            ob_end_flush();
            exit;
            
        case 'install_from_marketplace':
            // Instala plugin do marketplace (busca download_url do JSON)
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('Método não permitido');
            }
            
            // Verifica CSRF
            if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
                throw new Exception('Token CSRF inválido');
            }
            
            $slug = $_POST['slug'] ?? '';
            
            if (empty($slug)) {
                throw new Exception('Slug do plugin não fornecido');
            }
            
            // Busca plugin no marketplace
            $marketplace = load_marketplace_from_json($marketplace_file);
            $plugin = null;
            
            foreach ($marketplace as $p) {
                if (($p['slug'] ?? '') === $slug) {
                    $plugin = $p;
                    break;
                }
            }
            
            if (!$plugin) {
                throw new Exception('Plugin não encontrado no marketplace');
            }
            
            // Verifica se tem download_url
            $download_url = $plugin['download_url'] ?? '';
            if (empty($download_url)) {
                throw new Exception('Plugin não possui URL de download configurada. Use a opção de instalar via URL personalizada.');
            }
            
            // Instala usando URL
            $result = install_plugin_from_url($download_url);
            
            if (!$result['success']) {
                throw new Exception($result['message'] ?? 'Erro ao instalar plugin');
            }
            
            $plugin_info = $result['plugin_info'] ?? [];
            
            // Registra no banco de dados se não estiver instalado
            if (!empty($plugin_info['pasta'])) {
                try {
                    $stmt = $pdo->prepare("SELECT id FROM plugins WHERE pasta = ?");
                    $stmt->execute([$plugin_info['pasta']]);
                    
                    if (!$stmt->fetch()) {
                        // Não está instalado, registra
                        $stmt = $pdo->prepare("INSERT INTO plugins (nome, pasta, versao, ativo) VALUES (?, ?, ?, 0)");
                        $stmt->execute([
                            $plugin_info['nome'] ?? $plugin_info['pasta'],
                            $plugin_info['pasta'],
                            $plugin_info['versao'] ?? '1.0.0'
                        ]);
                    }
                } catch (PDOException $e) {
                    // Ignora erro se tabela não existir
                }
            }
            
            ob_clean();
            echo json_encode([
                'success' => true,
                'message' => 'Plugin instalado com sucesso',
                'plugin' => $plugin_info
            ]);
            ob_end_flush();
            exit;
            
        case 'add_to_marketplace':
            // Adiciona plugin ao marketplace (admin)
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('Método não permitido');
            }
            
            // Verifica CSRF
            if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
                throw new Exception('Token CSRF inválido');
            }
            
            $slug = $_POST['slug'] ?? '';
            $nome = $_POST['nome'] ?? '';
            $versao = $_POST['versao'] ?? '1.0.0';
            $descricao = $_POST['descricao'] ?? '';
            $autor = $_POST['autor'] ?? '';
            $preco = isset($_POST['preco']) && $_POST['preco'] !== '' ? floatval($_POST['preco']) : null;
            $download_url = $_POST['download_url'] ?? null;
            $external_url = $_POST['external_url'] ?? null;
            $categoria = $_POST['categoria'] ?? '';
            
            if (empty($slug) || empty($nome)) {
                throw new Exception('Slug e nome do plugin são obrigatórios');
            }
            
            // Sanitiza slug
            $slug = preg_replace('/[^a-z0-9_-]/i', '', $slug);
            
            // Carrega marketplace atual
            $marketplace = load_marketplace_from_json($marketplace_file);
            
            // Verifica se já existe
            $exists = false;
            foreach ($marketplace as &$p) {
                if (($p['slug'] ?? '') === $slug) {
                    // Atualiza existente
                    $p['nome'] = $nome;
                    $p['versao'] = $versao;
                    $p['descricao'] = $descricao;
                    $p['autor'] = $autor;
                    $p['preco'] = $preco;
                    $p['download_url'] = $download_url;
                    $p['external_url'] = $external_url;
                    $p['categoria'] = $categoria;
                    $exists = true;
                    break;
                }
            }
            
            if (!$exists) {
                // Adiciona novo
                $marketplace[] = [
                    'slug' => $slug,
                    'nome' => $nome,
                    'versao' => $versao,
                    'descricao' => $descricao,
                    'autor' => $autor,
                    'preco' => $preco,
                    'download_url' => $download_url,
                    'external_url' => $external_url,
                    'categoria' => $categoria
                ];
            }
            
            // Salva no JSON
            if (!save_marketplace_to_json($marketplace_file, $marketplace)) {
                throw new Exception('Erro ao salvar marketplace');
            }
            
            ob_clean();
            echo json_encode([
                'success' => true,
                'message' => $exists ? 'Plugin atualizado no marketplace' : 'Plugin adicionado ao marketplace'
            ]);
            ob_end_flush();
            exit;
            
        case 'remove_from_marketplace':
            // Remove plugin do marketplace (admin)
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('Método não permitido');
            }
            
            // Verifica CSRF
            if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
                throw new Exception('Token CSRF inválido');
            }
            
            $slug = $_POST['slug'] ?? '';
            
            if (empty($slug)) {
                throw new Exception('Slug do plugin não fornecido');
            }
            
            // Carrega marketplace atual
            $marketplace = load_marketplace_from_json($marketplace_file);
            
            // Remove plugin
            $marketplace = array_filter($marketplace, function($p) use ($slug) {
                return ($p['slug'] ?? '') !== $slug;
            });
            
            // Reindexa array
            $marketplace = array_values($marketplace);
            
            // Salva no JSON
            if (!save_marketplace_to_json($marketplace_file, $marketplace)) {
                throw new Exception('Erro ao salvar marketplace');
            }
            
            ob_clean();
            echo json_encode([
                'success' => true,
                'message' => 'Plugin removido do marketplace'
            ]);
            ob_end_flush();
            exit;
            
        case 'clear_cache':
            // Limpa o cache do marketplace (força buscar novamente da URL externa)
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                throw new Exception('Método não permitido');
            }
            
            $cache_file = sys_get_temp_dir() . '/plugins_marketplace_cache.json';
            
            if (file_exists($cache_file)) {
                @unlink($cache_file);
            }
            
            ob_clean();
            echo json_encode(['success' => true, 'message' => 'Cache limpo com sucesso']);
            ob_end_flush();
            exit;
            
        default:
            ob_clean();
            http_response_code(400);
            echo json_encode(['error' => 'Ação não reconhecida']);
            ob_end_flush();
            exit;
    }
} catch (Exception $e) {
    ob_clean();
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => $e->getMessage()]);
    ob_end_flush();
    exit;
} catch (Error $e) {
    ob_clean();
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Erro interno: ' . $e->getMessage()]);
    ob_end_flush();
    exit;
}

