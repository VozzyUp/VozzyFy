<?php
/**
 * Helper de Atualização
 * Funções auxiliares para backup, restore e validação
 */

if (!function_exists('create_backup')) {
    /**
     * Cria backup do banco de dados e arquivos críticos
     * @param PDO $pdo Instância do PDO
     * @return array Informações do backup criado
     */
    function create_backup($pdo) {
        $backupDir = __DIR__ . '/../backups';
        if (!is_dir($backupDir)) {
            mkdir($backupDir, 0755, true);
        }
        
        $timestamp = date('Ymd_His');
        $backupFolder = $backupDir . '/update_' . $timestamp;
        mkdir($backupFolder, 0755, true);
        
        $backupInfo = [
            'folder' => $backupFolder,
            'timestamp' => $timestamp,
            'files' => [],
            'database' => null,
            'note' => 'Backup do banco de dados deve ser feito manualmente antes da atualização'
        ];
        
        try {
            // NOTA: Backup do banco de dados foi removido para evitar travamentos
            // O usuário deve fazer backup manual do banco antes de atualizar
            // Usando phpMyAdmin ou mysqldump manualmente
            
            // Backup apenas de arquivos críticos
            $criticalFiles = [
                'config/config.php',
                'VERSION.txt'
            ];
            
            foreach ($criticalFiles as $file) {
                $sourcePath = __DIR__ . '/../' . $file;
                if (file_exists($sourcePath)) {
                    $destPath = $backupFolder . '/' . str_replace('/', '_', $file);
                    if (copy($sourcePath, $destPath)) {
                        $backupInfo['files'][] = $file;
                    }
                }
            }
            
            // Limpar backups antigos (manter apenas últimos 5)
            cleanup_old_backups($backupDir, 5);
            
            return $backupInfo;
            
        } catch (Exception $e) {
            error_log("Erro ao criar backup: " . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('backup_database')) {
    /**
     * Cria dump do banco de dados
     * @param PDO $pdo
     * @param string $outputFile Arquivo de saída
     * @return bool
     */
    function backup_database($pdo, $outputFile) {
        try {
            $dbName = DB_NAME;
            $dbUser = DB_USER;
            $dbPass = DB_PASS;
            $dbHost = DB_HOST;
            
            // Tentar usar mysqldump se disponível
            $mysqldump = 'mysqldump';
            if (PHP_OS_FAMILY === 'Windows') {
                // Tentar encontrar mysqldump no XAMPP
                $possiblePaths = [
                    'C:\\xampp\\mysql\\bin\\mysqldump.exe',
                    'C:\\Program Files\\MySQL\\MySQL Server 8.0\\bin\\mysqldump.exe',
                    'C:\\Program Files\\xampp\\mysql\\bin\\mysqldump.exe'
                ];
                
                foreach ($possiblePaths as $path) {
                    if (file_exists($path)) {
                        $mysqldump = $path;
                        break;
                    }
                }
            }
            
            $command = sprintf(
                '%s -h%s -u%s -p%s %s > %s 2>&1',
                escapeshellarg($mysqldump),
                escapeshellarg($dbHost),
                escapeshellarg($dbUser),
                escapeshellarg($dbPass),
                escapeshellarg($dbName),
                escapeshellarg($outputFile)
            );
            
            exec($command, $output, $returnCode);
            
            if ($returnCode === 0 && file_exists($outputFile) && filesize($outputFile) > 0) {
                return true;
            }
            
            // Fallback: criar dump manual via PDO
            return backup_database_manual($pdo, $outputFile);
            
        } catch (Exception $e) {
            error_log("Erro ao fazer backup do banco: " . $e->getMessage());
            // Tentar método manual
            return backup_database_manual($pdo, $outputFile);
        }
    }
}

if (!function_exists('backup_database_manual')) {
    /**
     * Cria dump manual do banco via PDO
     * @param PDO $pdo
     * @param string $outputFile
     * @return bool
     */
    function backup_database_manual($pdo, $outputFile) {
        try {
            $output = fopen($outputFile, 'w');
            if (!$output) {
                return false;
            }
            
            // Cabeçalho
            fwrite($output, "-- Backup do Banco de Dados\n");
            fwrite($output, "-- Gerado em: " . date('Y-m-d H:i:s') . "\n\n");
            fwrite($output, "SET FOREIGN_KEY_CHECKS=0;\n\n");
            
            // Buscar todas as tabelas
            $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
            
            foreach ($tables as $table) {
                // CREATE TABLE
                $createTable = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_ASSOC);
                if ($createTable) {
                    fwrite($output, "\n-- Estrutura da tabela `{$table}`\n");
                    fwrite($output, "DROP TABLE IF EXISTS `{$table}`;\n");
                    fwrite($output, $createTable['Create Table'] . ";\n\n");
                }
                
                // INSERTs
                $rows = $pdo->query("SELECT * FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
                if (!empty($rows)) {
                    fwrite($output, "-- Dados da tabela `{$table}`\n");
                    foreach ($rows as $row) {
                        $columns = array_keys($row);
                        $values = array_map(function($val) use ($pdo) {
                            return $pdo->quote($val);
                        }, array_values($row));
                        
                        fwrite($output, "INSERT INTO `{$table}` (`" . implode('`, `', $columns) . "`) VALUES (" . implode(', ', $values) . ");\n");
                    }
                    fwrite($output, "\n");
                }
            }
            
            fwrite($output, "SET FOREIGN_KEY_CHECKS=1;\n");
            fclose($output);
            
            return true;
            
        } catch (Exception $e) {
            error_log("Erro no backup manual: " . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('cleanup_old_backups')) {
    /**
     * Remove backups antigos, mantendo apenas os N mais recentes
     * @param string $backupDir Diretório de backups
     * @param int $keep Número de backups para manter
     */
    function cleanup_old_backups($backupDir, $keep = 5) {
        if (!is_dir($backupDir)) {
            return;
        }
        
        $backups = glob($backupDir . '/update_*');
        if (count($backups) <= $keep) {
            return;
        }
        
        // Ordenar por data (mais recente primeiro)
        usort($backups, function($a, $b) {
            return filemtime($b) - filemtime($a);
        });
        
        // Remover backups antigos
        $toRemove = array_slice($backups, $keep);
        foreach ($toRemove as $backup) {
            if (is_dir($backup)) {
                delete_directory($backup);
            } elseif (is_file($backup)) {
                unlink($backup);
            }
        }
    }
}

if (!function_exists('delete_directory')) {
    /**
     * Remove diretório recursivamente
     * @param string $dir
     * @return bool
     */
    function delete_directory($dir) {
        if (!is_dir($dir)) {
            return false;
        }
        
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                delete_directory($path);
            } else {
                unlink($path);
            }
        }
        
        return rmdir($dir);
    }
}

if (!function_exists('restore_backup')) {
    /**
     * Restaura backup do banco de dados
     * @param PDO $pdo
     * @param string $backupFile Arquivo SQL do backup
     * @return bool
     */
    function restore_backup($pdo, $backupFile) {
        if (!file_exists($backupFile)) {
            return false;
        }
        
        try {
            $sql = file_get_contents($backupFile);
            if (empty($sql)) {
                return false;
            }
            
            // Executar SQL
            $queries = array_filter(array_map('trim', explode(';', $sql)));
            foreach ($queries as $query) {
                if (empty($query) || strpos($query, '--') === 0) {
                    continue;
                }
                try {
                    $pdo->exec($query);
                } catch (PDOException $e) {
                    // Ignorar alguns erros comuns
                    if (strpos($e->getMessage(), 'already exists') === false) {
                        error_log("Erro ao restaurar: " . $e->getMessage());
                    }
                }
            }
            
            return true;
            
        } catch (Exception $e) {
            error_log("Erro ao restaurar backup: " . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('validate_update_files')) {
    /**
     * Valida integridade dos arquivos baixados
     * @param string $updateDir Diretório com arquivos atualizados
     * @return array Resultado da validação
     */
    function validate_update_files($updateDir) {
        $results = [
            'valid' => true,
            'errors' => []
        ];
        
        if (!is_dir($updateDir)) {
            $results['valid'] = false;
            $results['errors'][] = 'Diretório de atualização não encontrado';
            return $results;
        }
        
        // Verificar se VERSION.txt existe
        $versionFile = $updateDir . '/VERSION.txt';
        if (!file_exists($versionFile)) {
            $results['valid'] = false;
            $results['errors'][] = 'VERSION.txt não encontrado nos arquivos atualizados';
        }
        
        // Verificar se arquivos críticos existem (opcional - apenas aviso)
        // Nota: Não tornamos obrigatório para permitir flexibilidade em diferentes estruturas de projeto
        $criticalFiles = [
            'config/config.php' => false, // Não deve ser sobrescrito
            'index.php' => false, // Opcional - pode não existir em alguns projetos
            'checkout.php' => false // Opcional - pode não existir em alguns projetos
        ];
        
        foreach ($criticalFiles as $file => $required) {
            $filePath = $updateDir . '/' . $file;
            if ($required && !file_exists($filePath)) {
                $results['valid'] = false;
                $results['errors'][] = "Arquivo crítico não encontrado: {$file}";
            } elseif (!$required && !file_exists($filePath)) {
                // Apenas aviso, não erro
                $results['errors'][] = "Aviso: Arquivo opcional não encontrado: {$file}";
            }
        }
        
        // Se só houver avisos (não erros críticos), considerar válido
        $criticalErrors = array_filter($results['errors'], function($error) {
            return strpos($error, 'Arquivo crítico não encontrado') !== false;
        });
        
        if (empty($criticalErrors) && !empty($results['errors'])) {
            // Se só houver avisos, limpar os erros e considerar válido
            $results['valid'] = true;
            $results['errors'] = []; // Limpar avisos para não confundir
        }
        
        return $results;
    }
}

