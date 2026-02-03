<?php
/**
 * API para Verificar Atualizações no GitHub
 * Compara versão local com versão no GitHub
 */

// Desabilitar TUDO que possa gerar output HTML
ini_set('display_errors', 0);
ini_set('html_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Limpar TODOS os buffers existentes
while (ob_get_level() > 0) {
    ob_end_clean();
}

// Iniciar novo buffer
ob_start();

// Error handler que suprime todos os erros (apenas loga)
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    error_log("PHP Error [{$errno}]: {$errstr} in {$errfile} on line {$errline}");
    return true; // Suprime o erro
}, E_ALL | E_STRICT);

// Shutdown function para capturar erros fatais
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== NULL && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        // Limpar TODOS os buffers
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        
        // Tentar enviar JSON de erro
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'success' => false,
                'error' => 'Erro fatal: ' . $error['message'],
                'file' => $error['file'] ?? 'unknown',
                'line' => $error['line'] ?? 0
            ]);
        } else {
            // Se headers já foram enviados, pelo menos logar o erro
            error_log("Erro fatal após headers enviados: " . $error['message'] . " em " . ($error['file'] ?? 'unknown') . " linha " . ($error['line'] ?? 0));
        }
    }
});

// Definir header JSON ANTES de qualquer include
if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}

// Função para retornar JSON de forma segura
function returnJson($success, $data = [], $error = null) {
    // Limpar TODOS os buffers
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    
    if (!headers_sent()) {
        http_response_code($success ? 200 : 500);
        header('Content-Type: application/json; charset=utf-8');
    }
    
    echo json_encode([
        'success' => $success,
        'data' => $data,
        'error' => $error
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // Carregar config.php
    $configPath = __DIR__ . '/../config/config.php';
    if (!file_exists($configPath)) {
        returnJson(false, [], 'Arquivo de configuração não encontrado');
    }
    
    // Limpar buffer antes de incluir
    ob_clean();
    
    // Capturar qualquer output durante o include
    $output_before = ob_get_contents();
    ob_clean();
    
    // Tentar incluir config.php com tratamento de erro
    try {
        require_once $configPath;
    } catch (Exception $e) {
        error_log("Erro ao incluir config.php: " . $e->getMessage());
        returnJson(false, [], 'Erro ao carregar configuração: ' . $e->getMessage());
    } catch (Error $e) {
        error_log("Erro fatal ao incluir config.php: " . $e->getMessage());
        returnJson(false, [], 'Erro fatal ao carregar configuração: ' . $e->getMessage());
    }
    
    // Capturar qualquer output gerado
    $output = ob_get_clean();
    if (!empty(trim($output))) {
        error_log("Output inesperado de config.php (" . strlen($output) . " bytes): " . substr($output, 0, 500));
        // Tentar remover BOM e espaços
        $output = preg_replace('/^\xEF\xBB\xBF/', '', $output); // Remove BOM UTF-8
        $output = ltrim($output); // Remove espaços à esquerda
        if (!empty(trim($output))) {
            error_log("Output ainda presente após limpeza: " . substr($output, 0, 200));
        }
    }
    ob_start();
    
    // Carregar security_helper.php
    $securityPath = __DIR__ . '/../helpers/security_helper.php';
    if (!file_exists($securityPath)) {
        returnJson(false, [], 'Helper de segurança não encontrado');
    }
    
    ob_clean();
    
    // Tentar incluir security_helper.php com tratamento de erro
    try {
        require_once $securityPath;
    } catch (Exception $e) {
        error_log("Erro ao incluir security_helper.php: " . $e->getMessage());
        returnJson(false, [], 'Erro ao carregar helper de segurança: ' . $e->getMessage());
    } catch (Error $e) {
        error_log("Erro fatal ao incluir security_helper.php: " . $e->getMessage());
        returnJson(false, [], 'Erro fatal ao carregar helper de segurança: ' . $e->getMessage());
    }
    
    $output = ob_get_clean();
    if (!empty(trim($output))) {
        error_log("Output inesperado de security_helper.php (" . strlen($output) . " bytes): " . substr($output, 0, 500));
        // Tentar remover BOM e espaços
        $output = preg_replace('/^\xEF\xBB\xBF/', '', $output);
        $output = ltrim($output);
        if (!empty(trim($output))) {
            error_log("Output ainda presente após limpeza: " . substr($output, 0, 200));
        }
    }
    ob_start();
    
    // Verificar se função existe
    if (!function_exists('require_admin_auth')) {
        returnJson(false, [], 'Função require_admin_auth não encontrada');
    }
    
    if (!function_exists('getSystemSetting')) {
        returnJson(false, [], 'Função getSystemSetting não encontrada');
    }
    
    // Autenticação - require_admin_auth faz exit se não autenticado
    ob_clean();
    
    try {
        require_admin_auth(true);
    } catch (Exception $e) {
        error_log("Erro em require_admin_auth: " . $e->getMessage());
        returnJson(false, [], 'Erro na autenticação: ' . $e->getMessage());
    } catch (Error $e) {
        error_log("Erro fatal em require_admin_auth: " . $e->getMessage());
        returnJson(false, [], 'Erro fatal na autenticação: ' . $e->getMessage());
    }
    
    // Se chegou aqui, está autenticado
    // Limpar qualquer output que possa ter sido gerado (não deveria ter)
    $output = ob_get_clean();
    if (!empty(trim($output))) {
        error_log("AVISO: Output inesperado de require_admin_auth: " . substr($output, 0, 500));
    }
    ob_start();
    
    // Verificar se $pdo está disponível
    if (!isset($pdo) || $pdo === null) {
        returnJson(false, [], 'Erro: Banco de dados não está disponível. Verifique a conexão.');
    }
    
    // Obter configurações do GitHub
    // Usar try/catch para capturar qualquer erro do getSystemSetting
    $githubRepo = '';
    $githubToken = '';
    $githubBranch = 'main';
    
    try {
        $githubRepo = @getSystemSetting('github_repo', 'LeonardoIsrael0516/getfy-update');
    } catch (Exception $e) {
        error_log("Erro ao buscar github_repo: " . $e->getMessage());
    } catch (Error $e) {
        error_log("Erro fatal ao buscar github_repo: " . $e->getMessage());
    }
    
    try {
        $githubToken = @getSystemSetting('github_token', '');
    } catch (Exception $e) {
        error_log("Erro ao buscar github_token: " . $e->getMessage());
    } catch (Error $e) {
        error_log("Erro fatal ao buscar github_token: " . $e->getMessage());
    }
    
    try {
        $githubBranch = @getSystemSetting('github_branch', 'main');
    } catch (Exception $e) {
        error_log("Erro ao buscar github_branch: " . $e->getMessage());
        $githubBranch = 'main'; // Fallback
    } catch (Error $e) {
        error_log("Erro fatal ao buscar github_branch: " . $e->getMessage());
        $githubBranch = 'main'; // Fallback
    }
    
    // Se não configurado, usar env
    if (empty($githubRepo)) {
        $githubRepo = getenv('GITHUB_REPO') ?: '';
    }
    if (empty($githubToken)) {
        $githubToken = getenv('GITHUB_TOKEN') ?: '';
    }
    
    // Ler versão local
    $versionFile = __DIR__ . '/../VERSION.txt';
    $localVersion = '1.0.0';
    if (file_exists($versionFile)) {
        $localVersion = trim(file_get_contents($versionFile));
        if (empty($localVersion)) {
            $localVersion = '1.0.0';
        }
    }
    
    // Se não configurado, retornar mensagem amigável
    if (empty($githubRepo) || $githubRepo === 'usuario/repositorio') {
        returnJson(true, [
            'local_version' => $localVersion,
            'remote_version' => null,
            'has_update' => false,
            'needs_config' => true,
            'message' => 'Repositório GitHub não configurado. Configure em Configurações do Sistema.'
        ]);
    }
    
    if (empty($githubToken)) {
        returnJson(true, [
            'local_version' => $localVersion,
            'remote_version' => null,
            'has_update' => false,
            'needs_config' => true,
            'message' => 'Token GitHub não configurado. Configure em Configurações do GitHub.'
        ]);
    }
    
    // Buscar versão no GitHub
    $url = "https://api.github.com/repos/{$githubRepo}/contents/VERSION.txt?ref={$githubBranch}";
    
    $headers = [
        'User-Agent: PHP-App-Updater/1.0',
        'Accept: application/vnd.github.v3.raw',
        'Authorization: token ' . $githubToken
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    if ($curlError) {
        returnJson(false, [], 'Erro ao conectar com GitHub: ' . $curlError);
    }
    
    if ($httpCode === 200) {
        $remoteVersion = trim($response);
        if (empty($remoteVersion)) {
            returnJson(false, [], 'Versão no GitHub está vazia');
        }
        
        // Comparar versões
        $comparison = version_compare($remoteVersion, $localVersion);
        $hasUpdate = $comparison > 0;
        
        // Buscar informações da release (opcional)
        $releaseInfo = null;
        if ($hasUpdate) {
            $releaseUrl = "https://api.github.com/repos/{$githubRepo}/releases/latest";
            $ch2 = curl_init($releaseUrl);
            curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch2, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch2, CURLOPT_HTTPHEADER, $headers);
            $releaseResponse = curl_exec($ch2);
            $releaseHttpCode = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
            curl_close($ch2);
            
            if ($releaseHttpCode === 200) {
                $releaseData = json_decode($releaseResponse, true);
                if ($releaseData) {
                    $releaseInfo = [
                        'tag' => $releaseData['tag_name'] ?? '',
                        'name' => $releaseData['name'] ?? '',
                        'body' => $releaseData['body'] ?? '',
                        'published_at' => $releaseData['published_at'] ?? '',
                        'html_url' => $releaseData['html_url'] ?? ''
                    ];
                }
            }
        }
        
        returnJson(true, [
            'local_version' => $localVersion,
            'remote_version' => $remoteVersion,
            'has_update' => $hasUpdate,
            'comparison' => $comparison,
            'release_info' => $releaseInfo
        ]);
    } elseif ($httpCode === 404) {
        // Tentar sem ref
        $url2 = "https://api.github.com/repos/{$githubRepo}/contents/VERSION.txt";
        $ch2 = curl_init($url2);
        curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch2, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch2, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch2, CURLOPT_FOLLOWLOCATION, true);
        
        $response2 = curl_exec($ch2);
        $httpCode2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
        curl_close($ch2);
        
        if ($httpCode2 === 200) {
            $remoteVersion = trim($response2);
            $comparison = version_compare($remoteVersion, $localVersion);
            $hasUpdate = $comparison > 0;
            
            returnJson(true, [
                'local_version' => $localVersion,
                'remote_version' => $remoteVersion,
                'has_update' => $hasUpdate,
                'comparison' => $comparison
            ]);
        }
        
        // Listar arquivos na raiz
        $contentsUrl = "https://api.github.com/repos/{$githubRepo}/contents/?ref={$githubBranch}";
        $ch3 = curl_init($contentsUrl);
        curl_setopt($ch3, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch3, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch3, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch3, CURLOPT_FOLLOWLOCATION, true);
        
        $contentsResponse = curl_exec($ch3);
        $contentsHttpCode = curl_getinfo($ch3, CURLINFO_HTTP_CODE);
        curl_close($ch3);
        
        $files = [];
        if ($contentsHttpCode === 200) {
            $contentsData = json_decode($contentsResponse, true);
            if (is_array($contentsData)) {
                foreach ($contentsData as $item) {
                    if (isset($item['type']) && $item['type'] === 'file') {
                        $files[] = $item['name'];
                    }
                }
            }
        }
        
        $fileList = count($files) > 0 ? implode(', ', array_slice($files, 0, 15)) : 'nenhum arquivo encontrado';
        $errorMsg = 'Arquivo VERSION.txt não encontrado no repositório.';
        if (count($files) > 0) {
            $errorMsg .= ' Arquivos encontrados na raiz: ' . $fileList . (count($files) > 15 ? '...' : '');
        } else {
            $errorMsg .= ' Nenhum arquivo encontrado na raiz. Verifique se o token tem permissão "repo" e se a branch está correta.';
        }
        
        returnJson(false, [], $errorMsg);
    } elseif ($httpCode === 403) {
        $errorData = json_decode($response, true);
        $errorMsg = isset($errorData['message']) ? $errorData['message'] : 'Acesso negado';
        returnJson(false, [], "Acesso negado ao repositório. Verifique se o token tem permissão 'repo'. Erro: {$errorMsg}");
    } elseif ($httpCode === 401) {
        returnJson(false, [], 'Token GitHub inválido ou expirado. Verifique suas credenciais.');
    } else {
        $errorData = json_decode($response, true);
        $errorMsg = isset($errorData['message']) ? $errorData['message'] : "HTTP {$httpCode}";
        returnJson(false, [], "Erro ao buscar versão no GitHub (HTTP {$httpCode}): {$errorMsg}");
    }
    
} catch (Exception $e) {
    error_log("Erro em update_check.php: " . $e->getMessage() . " | File: " . $e->getFile() . " | Line: " . $e->getLine());
    returnJson(false, [], 'Erro ao verificar atualizações: ' . $e->getMessage());
} catch (Error $e) {
    error_log("Erro fatal em update_check.php: " . $e->getMessage() . " | File: " . $e->getFile() . " | Line: " . $e->getLine());
    returnJson(false, [], 'Erro fatal ao verificar atualizações: ' . $e->getMessage());
} catch (Throwable $e) {
    error_log("Erro Throwable em update_check.php: " . $e->getMessage() . " | File: " . $e->getFile() . " | Line: " . $e->getLine());
    returnJson(false, [], 'Erro inesperado: ' . $e->getMessage());
}
