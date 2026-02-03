<?php
/**
 * API para Processar Atualização
 * Executa backup, substitui arquivos e roda migrations
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
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        } else {
            error_log("Erro fatal após headers enviados: " . $error['message'] . " em " . ($error['file'] ?? 'unknown') . " linha " . ($error['line'] ?? 0));
        }
    }
});

// Definir header JSON ANTES de qualquer include
if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
}

// Função para retornar resposta JSON de forma segura
function jsonResponse($success, $data = [], $error = null) {
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

// Carregar arquivos com tratamento de erro
try {
    $configPath = __DIR__ . '/../config/config.php';
    if (!file_exists($configPath)) {
        jsonResponse(false, [], 'Arquivo de configuração não encontrado');
    }
    
    ob_clean();
    require_once $configPath;
    ob_clean();
    
} catch (Exception $e) {
    error_log("Erro ao incluir config.php: " . $e->getMessage());
    jsonResponse(false, [], 'Erro ao carregar configuração: ' . $e->getMessage());
} catch (Error $e) {
    error_log("Erro fatal ao incluir config.php: " . $e->getMessage());
    jsonResponse(false, [], 'Erro fatal ao carregar configuração: ' . $e->getMessage());
} catch (Throwable $e) {
    error_log("Erro ao incluir config.php: " . $e->getMessage());
    jsonResponse(false, [], 'Erro ao carregar configuração: ' . $e->getMessage());
}

try {
    ob_clean();
    require_once __DIR__ . '/../helpers/security_helper.php';
    ob_clean();
} catch (Exception $e) {
    error_log("Erro ao incluir security_helper.php: " . $e->getMessage());
    jsonResponse(false, [], 'Erro ao carregar helper de segurança: ' . $e->getMessage());
} catch (Throwable $e) {
    error_log("Erro ao incluir security_helper.php: " . $e->getMessage());
    jsonResponse(false, [], 'Erro ao carregar helper de segurança: ' . $e->getMessage());
}

try {
    ob_clean();
    require_once __DIR__ . '/../helpers/update_helper.php';
    ob_clean();
} catch (Exception $e) {
    error_log("Erro ao incluir update_helper.php: " . $e->getMessage());
    jsonResponse(false, [], 'Erro ao carregar helper de atualização: ' . $e->getMessage());
} catch (Throwable $e) {
    error_log("Erro ao incluir update_helper.php: " . $e->getMessage());
    jsonResponse(false, [], 'Erro ao carregar helper de atualização: ' . $e->getMessage());
}

try {
    ob_clean();
    require_once __DIR__ . '/../helpers/migration_helper.php';
    ob_clean();
} catch (Exception $e) {
    error_log("Erro ao incluir migration_helper.php: " . $e->getMessage());
    jsonResponse(false, [], 'Erro ao carregar helper de migrations: ' . $e->getMessage());
} catch (Throwable $e) {
    error_log("Erro ao incluir migration_helper.php: " . $e->getMessage());
    jsonResponse(false, [], 'Erro ao carregar helper de migrations: ' . $e->getMessage());
}

// Verificar autenticação admin
try {
    ob_clean();
    require_admin_auth(true);
    ob_clean();
} catch (Exception $e) {
    error_log("Erro em require_admin_auth: " . $e->getMessage());
    jsonResponse(false, [], 'Erro na autenticação: ' . $e->getMessage());
} catch (Throwable $e) {
    error_log("Erro fatal em require_admin_auth: " . $e->getMessage());
    jsonResponse(false, [], 'Erro fatal na autenticação: ' . $e->getMessage());
}

try {
    $action = $_GET['action'] ?? 'process';
    
    if ($action === 'process') {
        $tempDir = __DIR__ . '/../temp/update';
        
        if (!is_dir($tempDir)) {
            jsonResponse(false, [], 'Diretório de atualização não encontrado. Execute o download primeiro.');
        }
        
        // 1. Validar arquivos
        $validation = validate_update_files($tempDir);
        if (!$validation['valid']) {
            jsonResponse(false, [], 'Validação falhou: ' . implode(', ', $validation['errors']));
        }
        
        // 2. Criar backup de arquivos críticos (backup do banco deve ser feito manualmente)
        $backupInfo = create_backup($pdo);
        if (!$backupInfo) {
            jsonResponse(false, [], 'Erro ao criar backup de arquivos. Atualização cancelada por segurança.');
        }
        
        $results = [
            'backup' => $backupInfo,
            'files_updated' => [],
            'files_preserved' => [],
            'migrations' => null,
            'version_updated' => false
        ];
        
        // 3. Atualizar arquivos
        $basePath = dirname(__DIR__);
        $filesToUpdate = [];
        
        // Buscar todos os arquivos no diretório temporário
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($tempDir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $relativePath = str_replace($tempDir . DIRECTORY_SEPARATOR, '', $file->getPathname());
                $filesToUpdate[] = $relativePath;
            }
        }
        
        // Arquivos que NÃO devem ser sobrescritos
        $preservedFiles = [
            'config/config.php',
            '.htaccess'
        ];
        
        foreach ($filesToUpdate as $relativePath) {
            $sourcePath = $tempDir . '/' . $relativePath;
            $destPath = $basePath . '/' . $relativePath;
            
            // Verificar se deve preservar
            $shouldPreserve = false;
            foreach ($preservedFiles as $preserved) {
                if (strpos($relativePath, $preserved) !== false) {
                    $shouldPreserve = true;
                    break;
                }
            }
            
            if ($shouldPreserve) {
                $results['files_preserved'][] = $relativePath;
                continue;
            }
            
            // Criar diretório de destino se necessário
            $destDir = dirname($destPath);
            if (!is_dir($destDir)) {
                mkdir($destDir, 0755, true);
            }
            
            // Copiar arquivo
            if (copy($sourcePath, $destPath)) {
                $results['files_updated'][] = $relativePath;
            }
        }
        
        // 4. Atualizar VERSION.txt
        $versionFile = $tempDir . '/VERSION.txt';
        if (file_exists($versionFile)) {
            $newVersion = trim(file_get_contents($versionFile));
            $currentVersionFile = $basePath . '/VERSION.txt';
            if (file_put_contents($currentVersionFile, $newVersion)) {
                $results['version_updated'] = true;
                $results['new_version'] = $newVersion;
            }
        }
        
        // 5. Executar migrations (com tratamento robusto de erros)
        ob_clean();
        try {
            $migrationResults = run_migrations($pdo, $results['new_version'] ?? null);
            $results['migrations'] = $migrationResults;
            
            // Verificar se houve erros nas migrations
            if (!empty($migrationResults['errors'])) {
                $errorMessages = array_map(function($err) {
                    return $err['migration'] . ': ' . $err['error'];
                }, $migrationResults['errors']);
                
                // Logar erros mas não falhar a atualização completamente
                error_log("Erros em migrations: " . implode('; ', $errorMessages));
                $results['migration_warnings'] = $errorMessages;
            }
            
            ob_clean();
        } catch (Exception $e) {
            ob_clean();
            error_log("Erro ao executar migrations: " . $e->getMessage());
            $results['migrations'] = [
                'executed' => [],
                'skipped' => [],
                'errors' => [['migration' => 'system', 'error' => $e->getMessage()]]
            ];
            $results['migration_warnings'] = ['Erro ao executar migrations: ' . $e->getMessage()];
        } catch (Throwable $e) {
            ob_clean();
            error_log("Erro fatal ao executar migrations: " . $e->getMessage());
            $results['migrations'] = [
                'executed' => [],
                'skipped' => [],
                'errors' => [['migration' => 'system', 'error' => $e->getMessage()]]
            ];
            $results['migration_warnings'] = ['Erro fatal ao executar migrations: ' . $e->getMessage()];
        }
        
        // 6. Limpar diretório temporário
        $files = glob($tempDir . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            } elseif (is_dir($file)) {
                delete_directory($file);
            }
        }
        
        jsonResponse(true, $results);
        
    } elseif ($action === 'restore') {
        // Restaurar backup
        $backupFolder = $_POST['backup_folder'] ?? '';
        
        if (empty($backupFolder) || !is_dir($backupFolder)) {
            jsonResponse(false, [], 'Pasta de backup inválida');
        }
        
        // Restaurar banco de dados
        $dbBackupFile = $backupFolder . '/database.sql';
        if (file_exists($dbBackupFile)) {
            if (!restore_backup($pdo, $dbBackupFile)) {
                jsonResponse(false, [], 'Erro ao restaurar backup do banco de dados');
            }
        }
        
        // Restaurar arquivos
        $backupFiles = glob($backupFolder . '/config_*.php');
        foreach ($backupFiles as $backupFile) {
            $originalName = str_replace(['config_', '_'], ['config/', '/'], basename($backupFile));
            $destPath = dirname(__DIR__) . '/' . $originalName;
            if (file_exists($backupFile)) {
                copy($backupFile, $destPath);
            }
        }
        
        jsonResponse(true, ['message' => 'Backup restaurado com sucesso']);
    }
    
    jsonResponse(false, [], 'Ação inválida');
    
} catch (Exception $e) {
    jsonResponse(false, [], 'Erro ao processar atualização: ' . $e->getMessage());
}

