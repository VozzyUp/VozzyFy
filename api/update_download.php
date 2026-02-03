<?php
/**
 * API para Baixar Arquivos Atualizados do GitHub
 * Baixa arquivos do repositório e salva em pasta temporária
 */

// Desabilitar exibição de erros
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
            @ob_end_clean();
        }
        
        // Tentar enviar JSON de erro
        if (!headers_sent()) {
            @http_response_code(500);
            @header('Content-Type: application/json; charset=utf-8');
            try {
                echo json_encode([
                    'success' => false,
                    'error' => 'Erro fatal: ' . $error['message'],
                    'file' => $error['file'] ?? 'unknown',
                    'line' => $error['line'] ?? 0
                ]);
            } catch (Exception $e) {
                echo '{"success":false,"error":"Erro fatal: ' . addslashes($error['message']) . '"}';
            }
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

// Função para retornar resposta JSON
function jsonResponse($success, $data = [], $error = null) {
    // Limpar TODOS os buffers
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    
    // Tentar definir headers se ainda não foram enviados
    if (!headers_sent()) {
        @http_response_code($success ? 200 : 500);
        @header('Content-Type: application/json; charset=utf-8');
    }
    
    // Tentar retornar JSON
    try {
        $output = json_encode([
            'success' => $success,
            'data' => $data,
            'error' => $error
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        
        if ($output === false) {
            // Se json_encode falhar, retornar erro simples
            echo '{"success":false,"error":"Erro ao gerar resposta JSON"}';
        } else {
            echo $output;
        }
    } catch (Exception $e) {
        echo '{"success":false,"error":"Erro ao gerar resposta: ' . addslashes($e->getMessage()) . '"}';
    } catch (Error $e) {
        echo '{"success":false,"error":"Erro fatal ao gerar resposta: ' . addslashes($e->getMessage()) . '"}';
    }
    
    // Garantir que o script pare aqui
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    }
    exit(0);
    die(); // Backup caso exit não funcione
}

// Limpar buffer antes de incluir
ob_clean();

// Tentar incluir config.php com tratamento de erro
try {
    require_once __DIR__ . '/../config/config.php';
} catch (Exception $e) {
    error_log("Erro ao incluir config.php: " . $e->getMessage());
    jsonResponse(false, [], 'Erro ao carregar configuração: ' . $e->getMessage());
} catch (Error $e) {
    error_log("Erro fatal ao incluir config.php: " . $e->getMessage());
    jsonResponse(false, [], 'Erro fatal ao carregar configuração: ' . $e->getMessage());
}

// Capturar qualquer output gerado
$output = ob_get_clean();
if (!empty(trim($output))) {
    error_log("Output inesperado de config.php (" . strlen($output) . " bytes): " . substr($output, 0, 500));
}
ob_start();

// Tentar incluir security_helper.php com tratamento de erro
try {
    require_once __DIR__ . '/../helpers/security_helper.php';
} catch (Exception $e) {
    error_log("Erro ao incluir security_helper.php: " . $e->getMessage());
    jsonResponse(false, [], 'Erro ao carregar helper de segurança: ' . $e->getMessage());
} catch (Error $e) {
    error_log("Erro fatal ao incluir security_helper.php: " . $e->getMessage());
    jsonResponse(false, [], 'Erro fatal ao carregar helper de segurança: ' . $e->getMessage());
}

// Capturar qualquer output gerado
$output = ob_get_clean();
if (!empty(trim($output))) {
    error_log("Output inesperado de security_helper.php (" . strlen($output) . " bytes): " . substr($output, 0, 500));
}
ob_start();

// Autenticação - require_admin_auth faz exit se não autenticado
try {
    require_admin_auth(true);
} catch (Exception $e) {
    error_log("Erro em require_admin_auth: " . $e->getMessage());
    jsonResponse(false, [], 'Erro na autenticação: ' . $e->getMessage());
} catch (Error $e) {
    error_log("Erro fatal em require_admin_auth: " . $e->getMessage());
    jsonResponse(false, [], 'Erro fatal na autenticação: ' . $e->getMessage());
}

// Se chegou aqui, está autenticado
// Limpar qualquer output que possa ter sido gerado (não deveria ter)
$output = ob_get_clean();
if (!empty(trim($output))) {
    error_log("AVISO: Output inesperado de require_admin_auth: " . substr($output, 0, 500));
    // Se houver output, limpar e continuar
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
}
ob_start();

// Garantir que não há output antes de continuar
if (ob_get_length() > 0) {
    ob_clean();
}

// Verificar se há algum output inesperado antes de continuar
$checkOutput = ob_get_contents();
if (!empty(trim($checkOutput))) {
    error_log("AVISO: Output detectado antes do processamento principal: " . substr($checkOutput, 0, 500));
    ob_clean();
}

// Função auxiliar para deletar diretórios recursivamente
function delete_directory_recursive($dir) {
    if (!is_dir($dir)) {
        return false;
    }
    $files = array_diff(scandir($dir), ['.', '..']);
    foreach ($files as $file) {
        $path = $dir . '/' . $file;
        if (is_dir($path)) {
            delete_directory_recursive($path);
        } else {
            unlink($path);
        }
    }
    return rmdir($dir);
}

try {
    // Verificar se extensão ZIP está disponível (necessária para extrair arquivos)
    if (!extension_loaded('zip') || !class_exists('ZipArchive')) {
        jsonResponse(false, [], 'Extensão ZIP do PHP não está habilitada. Por favor, habilite a extensão php_zip no arquivo php.ini e reinicie o servidor Apache/XAMPP. Procure por "extension=zip" no php.ini e remova o ponto e vírgula (;) se estiver comentado.');
    }
    
    // Obter configurações do GitHub
    $githubRepo = getSystemSetting('github_repo', 'LeonardoIsrael0516/getfy-update');
    $githubToken = getSystemSetting('github_token', '');
    $githubBranch = getSystemSetting('github_branch', 'main');
    
    // Valores padrão se não configurado
    if (empty($githubRepo)) {
        $githubRepo = getenv('GITHUB_REPO') ?: '';
    }
    if (empty($githubToken)) {
        $githubToken = getenv('GITHUB_TOKEN') ?: '';
    }
    
    if (empty($githubRepo) || $githubRepo === 'usuario/repositorio') {
        jsonResponse(false, [], 'Repositório GitHub não configurado');
    }
    
    // Verificar se token está configurado
    if (empty($githubToken)) {
        jsonResponse(false, [], 'Token GitHub não configurado. Configure o token nas Configurações do GitHub.');
    }
    
    // Criar diretório temporário
    $tempDir = __DIR__ . '/../temp/update';
    if (!is_dir($tempDir)) {
        mkdir($tempDir, 0755, true);
    }
    
    // Limpar atualizações anteriores
    $files = glob($tempDir . '/*');
    foreach ($files as $file) {
        if (is_file($file)) {
            unlink($file);
        } elseif (is_dir($file)) {
            delete_directory_recursive($file);
        }
    }
    
    // Usar zipball para baixar todo o repositório de uma vez (mais eficiente e confiável)
    $zipballUrl = "https://api.github.com/repos/{$githubRepo}/zipball/{$githubBranch}";
    $zipFilePath = $tempDir . '/update.zip';
    
    $headers = [
        'User-Agent: PHP-App-Updater/1.0',
        'Authorization: token ' . $githubToken,
        'Accept: application/vnd.github.v3+json'
    ];
    
    // Baixar repositório completo via zipball
    $ch = curl_init($zipballUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120); // 2 minutos para download
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    
    // Configurações SSL/TLS - desabilitar verificação para evitar erros de decriptação
    // Nota: Em produção, considere configurar certificados SSL adequados
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    
    // Buffer para download de arquivos grandes
    curl_setopt($ch, CURLOPT_BUFFERSIZE, 16384);
    
    $zipContent = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    $curlErrno = curl_errno($ch);
    curl_close($ch);
    
    if ($curlError) {
        // Se for erro SSL, tentar com configurações diferentes
        if (strpos($curlError, 'SSL') !== false || strpos($curlError, 'decryption') !== false || $curlErrno === CURLE_SSL_CONNECT_ERROR) {
            error_log("Erro SSL detectado, tentando com configurações alternativas: " . $curlError);
            
            // Tentar novamente com configurações SSL mais permissivas
            $ch = curl_init($zipballUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_TIMEOUT, 120);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            
            // Tentar diferentes versões de TLS
            if (defined('CURL_SSLVERSION_TLSv1_2')) {
                curl_setopt($ch, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1_2);
            }
            
            $zipContent = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
        }
        
        if ($curlError) {
            jsonResponse(false, [], 'Erro ao baixar repositório: ' . $curlError . ' (Código: ' . $curlErrno . ')');
        }
    }
    
    // Verificar se o conteúdo foi baixado
    if (empty($zipContent)) {
        jsonResponse(false, [], 'Nenhum conteúdo foi baixado do repositório. Verifique sua conexão com a internet.');
    }
    
    if ($httpCode !== 200) {
        $errorData = json_decode($zipContent, true);
        $errorMsg = isset($errorData['message']) ? $errorData['message'] : "HTTP {$httpCode}";
        
        if ($httpCode === 403) {
            jsonResponse(false, [], "Acesso negado ao repositório. Verifique se o token tem permissão 'repo'. Erro: {$errorMsg}");
        } elseif ($httpCode === 404) {
            jsonResponse(false, [], "Repositório ou branch não encontrado. Verifique se '{$githubRepo}' e branch '{$githubBranch}' estão corretos.");
        } else {
            jsonResponse(false, [], "Erro ao baixar repositório (HTTP {$httpCode}): {$errorMsg}");
        }
    }
    
    // Salvar ZIP
    if (file_put_contents($zipFilePath, $zipContent) === false) {
        jsonResponse(false, [], 'Erro ao salvar arquivo ZIP baixado.');
    }
    
    // Extrair ZIP
    $zip = new ZipArchive;
    if ($zip->open($zipFilePath) !== TRUE) {
        jsonResponse(false, [], 'Erro ao abrir arquivo ZIP baixado.');
    }
    
    // Extrair para diretório temporário
    $extractPath = $tempDir . '/extracted';
    if (!is_dir($extractPath)) {
        mkdir($extractPath, 0755, true);
    }
    
    if ($zip->extractTo($extractPath) !== TRUE) {
        $zip->close();
        jsonResponse(false, [], 'Erro ao extrair arquivos do ZIP.');
    }
    $zip->close();
    
    // Encontrar o diretório raiz extraído (geralmente tem formato: repo-branch-hash)
    $extractedDirs = glob($extractPath . '/*', GLOB_ONLYDIR);
    if (empty($extractedDirs)) {
        jsonResponse(false, [], 'Nenhum diretório encontrado no ZIP extraído.');
    }
    
    $sourceDir = $extractedDirs[0]; // Primeiro diretório é o conteúdo do repositório
    
    // Mover arquivos do diretório extraído para o tempDir (exceto config, uploads, etc)
    $excludeDirs = ['config', 'uploads', 'backups', 'temp', 'install', '.git'];
    $downloadedFiles = [];
    $errors = [];
    
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $relativePath = str_replace($sourceDir . DIRECTORY_SEPARATOR, '', $file->getPathname());
            
            // Verificar se deve excluir
            $shouldExclude = false;
            foreach ($excludeDirs as $exclude) {
                if (strpos($relativePath, $exclude . DIRECTORY_SEPARATOR) === 0 || 
                    strpos($relativePath, DIRECTORY_SEPARATOR . $exclude . DIRECTORY_SEPARATOR) !== false) {
                    $shouldExclude = true;
                    break;
                }
            }
            
            if ($shouldExclude) {
                continue;
            }
            
            $destPath = $tempDir . DIRECTORY_SEPARATOR . $relativePath;
            $destDir = dirname($destPath);
            
            if (!is_dir($destDir)) {
                mkdir($destDir, 0755, true);
            }
            
            if (copy($file->getPathname(), $destPath)) {
                $downloadedFiles[] = $relativePath;
            } else {
                $errors[] = "Não foi possível copiar: {$relativePath}";
            }
        }
    }
    
    // Limpar diretório extraído e ZIP (função já declarada acima)
    delete_directory_recursive($extractPath);
    @unlink($zipFilePath);
    
    // Se não baixou nenhum arquivo, retornar erro
    if (empty($downloadedFiles)) {
        $errorMsg = 'Nenhum arquivo foi baixado.';
        if (!empty($errors)) {
            $errorMsg .= ' Erros: ' . implode(', ', array_slice($errors, 0, 5));
        } else {
            $errorMsg .= ' Verifique se o token tem permissão "repo" e se o repositório está acessível.';
        }
        jsonResponse(false, [], $errorMsg);
    }
    
    jsonResponse(true, [
        'downloaded_files' => $downloadedFiles,
        'total_files' => count($downloadedFiles),
        'errors' => $errors,
        'temp_directory' => $tempDir
    ]);
    
} catch (Exception $e) {
    // Limpar qualquer output antes de retornar erro
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    jsonResponse(false, [], 'Erro ao baixar atualização: ' . $e->getMessage());
} catch (Error $e) {
    // Limpar qualquer output antes de retornar erro fatal
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
    jsonResponse(false, [], 'Erro fatal ao baixar atualização: ' . $e->getMessage());
}

