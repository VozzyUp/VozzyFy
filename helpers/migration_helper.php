<?php
/**
 * Sistema de Migrations
 * Gerencia atualizações do banco de dados via migrations PHP
 */

if (!function_exists('run_migrations')) {
    /**
     * Executa todas as migrations pendentes
     * @param PDO $pdo Instância do PDO
     * @param string $currentVersion Versão atual do sistema
     * @return array Resultado da execução
     */
    function run_migrations($pdo, $currentVersion = null) {
        $results = [
            'executed' => [],
            'skipped' => [],
            'errors' => []
        ];
        
        try {
            // Garantir que tabela schema_migrations existe
            ensure_migrations_table($pdo);
            
            // Buscar versão atual se não fornecida
            if ($currentVersion === null) {
                $versionFile = __DIR__ . '/../VERSION.txt';
                if (file_exists($versionFile)) {
                    $currentVersion = trim(file_get_contents($versionFile));
                }
                if (empty($currentVersion)) {
                    $currentVersion = '1.0.0';
                }
            }
            
            // Buscar migrations executadas
            $executedMigrations = get_executed_migrations($pdo);
            
            // Buscar arquivos de migration
            $migrationsDir = __DIR__ . '/../migrations';
            if (!is_dir($migrationsDir)) {
                return $results; // Nenhuma migration se pasta não existir
            }
            
            $migrationFiles = glob($migrationsDir . '/*.php');
            usort($migrationFiles, function($a, $b) {
                return basename($a) <=> basename($b);
            });
            
            foreach ($migrationFiles as $migrationFile) {
                $migrationName = basename($migrationFile);
                
                // Verificar se já foi executada
                $wasExecuted = in_array($migrationName, $executedMigrations);
                
                // Se foi executada, verificar se a tabela ainda existe e se tem todas as colunas
                // (para migrations de criação de tabelas)
                if ($wasExecuted && strpos($migrationName, 'criar_tabela_') !== false) {
                    // Extrair nome da tabela do nome do arquivo
                    if (preg_match('/criar_tabela_(.+)\.php$/', $migrationName, $tableMatches)) {
                        $tableName = $tableMatches[1];
                        try {
                            // Verificar se a tabela existe
                            $stmt = $pdo->query("SHOW TABLES LIKE '{$tableName}'");
                            if ($stmt->rowCount() == 0) {
                                // Tabela não existe, mas migration foi executada - forçar reexecução
                                error_log("INTEGRIDADE: Tabela {$tableName} não existe, mas migration {$migrationName} foi executada. Forçando reexecução.");
                                $wasExecuted = false; // Permitir execução novamente
                            } else {
                                // Tabela existe, verificar se tem todas as colunas esperadas
                                if (function_exists('get_expected_columns_from_migration')) {
                                    // Extrair nome da tabela do nome do arquivo
                                    $tableNameForCheck = null;
                                    if (preg_match('/criar_tabela_(.+)\.php$/', $migrationName, $tableMatches)) {
                                        $tableNameForCheck = $tableMatches[1];
                                    }
                                    $expectedColumns = get_expected_columns_from_migration($migrationName, $tableNameForCheck);
                                    if (!empty($expectedColumns)) {
                                        // Buscar colunas existentes no banco
                                        $stmt = $pdo->query("SHOW COLUMNS FROM `{$tableName}`");
                                        if ($stmt !== false) {
                                            $resultCols = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                            $existingColumns = [];
                                            foreach ($resultCols as $row) {
                                                $existingColumns[] = $row['Field'];
                                            }
                                            
                                            // Comparar (case-insensitive)
                                            $expectedColumnsLower = array_map('strtolower', $expectedColumns);
                                            $existingColumnsLower = array_map('strtolower', $existingColumns);
                                            $missingColumns = [];
                                            
                                            foreach ($expectedColumns as $expectedCol) {
                                                if (!in_array(strtolower($expectedCol), $existingColumnsLower)) {
                                                    $missingColumns[] = $expectedCol;
                                                }
                                            }
                                            
                                            if (!empty($missingColumns)) {
                                                // Faltam colunas - forçar reexecução
                                                error_log("INTEGRIDADE: Tabela {$tableName} existe mas faltam colunas: " . implode(', ', $missingColumns) . ". Forçando reexecução da migration {$migrationName}.");
                                                $wasExecuted = false; // Permitir execução novamente
                                            }
                                        }
                                    }
                                }
                            }
                        } catch (Exception $e) {
                            error_log("INTEGRIDADE: Erro ao verificar existência da tabela {$tableName}: " . $e->getMessage());
                        } catch (PDOException $e) {
                            error_log("INTEGRIDADE: Erro PDO ao verificar tabela {$tableName}: " . $e->getMessage());
                        }
                    }
                }
                
                if ($wasExecuted) {
                    $results['skipped'][] = $migrationName;
                    continue;
                }
                
                // Carregar e executar migration
                try {
                    // Capturar qualquer output durante o require
                    ob_start();
                    require_once $migrationFile;
                    $output = ob_get_clean();
                    
                    // Se houver output inesperado, logar mas continuar
                    if (!empty(trim($output))) {
                        error_log("AVISO: Output inesperado ao carregar migration {$migrationName}: " . substr($output, 0, 200));
                    }
                    
                    // Extrair nome da classe (assumindo formato: Migration_YYYYMMDD_HHMMSS_description)
                    $className = 'Migration_' . str_replace(['.php', '-'], ['', '_'], pathinfo($migrationName, PATHINFO_FILENAME));
                    
                    if (!class_exists($className)) {
                        throw new Exception("Classe {$className} não encontrada no arquivo {$migrationName}");
                    }
                    
                    $migration = new $className();
                    
                    // Verificar versão mínima requerida
                    if (method_exists($migration, 'getVersion')) {
                        $requiredVersion = $migration->getVersion();
                        if (version_compare($currentVersion, $requiredVersion, '<')) {
                            $results['skipped'][] = $migrationName . ' (versão mínima requerida: ' . $requiredVersion . ')';
                            continue;
                        }
                    }
                    
                    // Executar migration
                    if (method_exists($migration, 'up')) {
                        // Capturar output durante execução
                        ob_start();
                        
                        // Nota: Operações DDL (CREATE, ALTER, DROP) fazem commit automático no MySQL
                        // Por isso, não usamos transações para migrations que podem conter DDL
                        // Se a migration precisar de transação, ela deve gerenciar internamente
                        $transactionStarted = false;
                        try {
                            // Tentar iniciar transação (pode não ser necessário para DDL)
                            if ($pdo->inTransaction() === false) {
                                $pdo->beginTransaction();
                                $transactionStarted = true;
                            }
                            
                            $migration->up($pdo);
                            
                            // Verificar se houve erro após execução (para DDL que pode falhar silenciosamente)
                            $errorInfo = $pdo->errorInfo();
                            if (isset($errorInfo[0]) && $errorInfo[0] !== '00000' && $errorInfo[0] !== '') {
                                $errorMsg = isset($errorInfo[2]) ? $errorInfo[2] : 'Erro desconhecido do PDO';
                                error_log("INTEGRIDADE: Erro do PDO após executar migration {$migrationName}: {$errorMsg}");
                                throw new Exception("Erro ao executar migration: {$errorMsg}");
                            }
                            
                            // Só fazer commit se a transação ainda estiver ativa
                            // (DDL faz commit automático, então pode não haver transação)
                            if ($pdo->inTransaction() && $transactionStarted) {
                                $pdo->commit();
                            }
                            
                            $output = ob_get_clean();
                            if (!empty(trim($output))) {
                                error_log("AVISO: Output inesperado ao executar migration {$migrationName}: " . substr($output, 0, 200));
                            }
                            
                            // Verificar se a tabela foi realmente criada (para migrations de criação de tabelas)
                            if (strpos($migrationName, 'criar_tabela_') !== false) {
                                if (preg_match('/criar_tabela_(.+)\.php$/', $migrationName, $tableMatches)) {
                                    $tableName = $tableMatches[1];
                                    $stmt = $pdo->query("SHOW TABLES LIKE '{$tableName}'");
                                    if ($stmt->rowCount() == 0) {
                                        throw new Exception("Migration executada mas tabela {$tableName} não foi criada. Verifique se há dependências faltando (ex: foreign keys).");
                                    }
                                }
                            }
                            
                            // Registrar migration executada
                            register_migration($pdo, $migrationName, $currentVersion);
                            $results['executed'][] = $migrationName;
                        } catch (Exception $e) {
                            ob_end_clean(); // Limpar output em caso de erro
                            
                            // Só fazer rollback se houver transação ativa
                            if ($pdo->inTransaction()) {
                                try {
                                    $pdo->rollBack();
                                } catch (PDOException $rollbackError) {
                                    // Se rollback falhar (ex: já foi commitado por DDL), apenas logar
                                    error_log("Aviso: Não foi possível fazer rollback: " . $rollbackError->getMessage());
                                }
                            }
                            throw $e;
                        } catch (Throwable $e) {
                            ob_end_clean(); // Limpar output em caso de erro fatal
                            
                            // Só fazer rollback se houver transação ativa
                            if ($pdo->inTransaction()) {
                                try {
                                    $pdo->rollBack();
                                } catch (PDOException $rollbackError) {
                                    error_log("Aviso: Não foi possível fazer rollback: " . $rollbackError->getMessage());
                                }
                            }
                            throw $e;
                        }
                    } else {
                        throw new Exception("Método 'up' não encontrado na classe {$className}");
                    }
                    
                } catch (Exception $e) {
                    $results['errors'][] = [
                        'migration' => $migrationName,
                        'error' => $e->getMessage()
                    ];
                    error_log("Erro ao executar migration {$migrationName}: " . $e->getMessage());
                } catch (Throwable $e) {
                    $results['errors'][] = [
                        'migration' => $migrationName,
                        'error' => 'Erro fatal: ' . $e->getMessage()
                    ];
                    error_log("Erro fatal ao executar migration {$migrationName}: " . $e->getMessage());
                }
            }
            
        } catch (Exception $e) {
            $results['errors'][] = [
                'migration' => 'system',
                'error' => $e->getMessage()
            ];
        }
        
        return $results;
    }
}

if (!function_exists('ensure_migrations_table')) {
    /**
     * Garante que a tabela schema_migrations existe
     * @param PDO $pdo
     */
    function ensure_migrations_table($pdo) {
        try {
            // Verificar se a tabela já existe
            $stmt = $pdo->query("SHOW TABLES LIKE 'schema_migrations'");
            if ($stmt->rowCount() == 0) {
                // Criar tabela apenas se não existir
                $pdo->exec("
                    CREATE TABLE `schema_migrations` (
                        `id` INT(11) NOT NULL AUTO_INCREMENT,
                        `migration_file` VARCHAR(255) NOT NULL,
                        `version` VARCHAR(20) NOT NULL,
                        `executed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        PRIMARY KEY (`id`),
                        UNIQUE KEY `migration_file` (`migration_file`),
                        INDEX `idx_version` (`version`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");
            } else {
                // Se a tabela já existe, verificar se tem as colunas e índices necessários
                $stmt = $pdo->query("SHOW COLUMNS FROM `schema_migrations`");
                $columns = $stmt->fetchAll(PDO::FETCH_COLUMN);
                
                // Adicionar colunas que faltam (se necessário)
                if (!in_array('id', $columns)) {
                    $pdo->exec("ALTER TABLE `schema_migrations` ADD COLUMN `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST");
                }
                if (!in_array('migration_file', $columns)) {
                    $pdo->exec("ALTER TABLE `schema_migrations` ADD COLUMN `migration_file` VARCHAR(255) NOT NULL");
                }
                if (!in_array('version', $columns)) {
                    $pdo->exec("ALTER TABLE `schema_migrations` ADD COLUMN `version` VARCHAR(20) NOT NULL");
                }
                if (!in_array('executed_at', $columns)) {
                    $pdo->exec("ALTER TABLE `schema_migrations` ADD COLUMN `executed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP");
                }
                
                // Verificar se a chave única já existe antes de criar
                $stmt = $pdo->query("SHOW INDEX FROM `schema_migrations` WHERE Key_name = 'migration_file'");
                if ($stmt->rowCount() == 0) {
                    try {
                        $pdo->exec("ALTER TABLE `schema_migrations` ADD UNIQUE KEY `migration_file` (`migration_file`)");
                    } catch (PDOException $e) {
                        // Se falhar, pode ser que já exista de outra forma, apenas logar
                        error_log("Aviso: Não foi possível adicionar chave única migration_file: " . $e->getMessage());
                    }
                }
                
                // Verificar se o índice idx_version existe
                $stmt = $pdo->query("SHOW INDEX FROM `schema_migrations` WHERE Key_name = 'idx_version'");
                if ($stmt->rowCount() == 0) {
                    try {
                        $pdo->exec("ALTER TABLE `schema_migrations` ADD INDEX `idx_version` (`version`)");
                    } catch (PDOException $e) {
                        error_log("Aviso: Não foi possível adicionar índice idx_version: " . $e->getMessage());
                    }
                }
            }
        } catch (PDOException $e) {
            error_log("Erro ao garantir tabela schema_migrations: " . $e->getMessage());
            // Não lançar exceção para não interromper o processo
        }
    }
}

if (!function_exists('get_executed_migrations')) {
    /**
     * Retorna lista de migrations já executadas
     * @param PDO $pdo
     * @return array
     */
    function get_executed_migrations($pdo) {
        try {
            ensure_migrations_table($pdo);
            $stmt = $pdo->query("SELECT migration_file FROM schema_migrations ORDER BY executed_at ASC");
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            return [];
        }
    }
}

if (!function_exists('register_migration')) {
    /**
     * Registra uma migration como executada
     * @param PDO $pdo
     * @param string $migrationFile Nome do arquivo de migration
     * @param string $version Versão atual
     */
    function register_migration($pdo, $migrationFile, $version) {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO schema_migrations (migration_file, version) 
                VALUES (?, ?)
                ON DUPLICATE KEY UPDATE version = VALUES(version), executed_at = CURRENT_TIMESTAMP
            ");
            $stmt->execute([$migrationFile, $version]);
        } catch (PDOException $e) {
            error_log("Erro ao registrar migration: " . $e->getMessage());
        }
    }
}

if (!function_exists('rollback_migration')) {
    /**
     * Reverte uma migration (se tiver método down)
     * @param PDO $pdo
     * @param string $migrationFile Nome do arquivo de migration
     * @return bool
     */
    function rollback_migration($pdo, $migrationFile) {
        $migrationsDir = __DIR__ . '/../migrations';
        $filePath = $migrationsDir . '/' . $migrationFile;
        
        if (!file_exists($filePath)) {
            return false;
        }
        
        try {
            require_once $filePath;
            $className = 'Migration_' . str_replace(['.php', '-'], ['', '_'], pathinfo($migrationFile, PATHINFO_FILENAME));
            
            if (!class_exists($className)) {
                return false;
            }
            
            $migration = new $className();
            
            if (method_exists($migration, 'down')) {
                $transactionStarted = false;
                try {
                    // Tentar iniciar transação (pode não ser necessário para DDL)
                    if ($pdo->inTransaction() === false) {
                        $pdo->beginTransaction();
                        $transactionStarted = true;
                    }
                    
                    $migration->down($pdo);
                    
                    // Remover registro
                    $stmt = $pdo->prepare("DELETE FROM schema_migrations WHERE migration_file = ?");
                    $stmt->execute([$migrationFile]);
                    
                    // Só fazer commit se a transação ainda estiver ativa
                    if ($pdo->inTransaction() && $transactionStarted) {
                        $pdo->commit();
                    }
                    
                    return true;
                } catch (Exception $e) {
                    // Só fazer rollback se houver transação ativa
                    if ($pdo->inTransaction()) {
                        try {
                            $pdo->rollBack();
                        } catch (PDOException $rollbackError) {
                            error_log("Aviso: Não foi possível fazer rollback: " . $rollbackError->getMessage());
                        }
                    }
                    throw $e;
                } catch (Throwable $e) {
                    // Só fazer rollback se houver transação ativa
                    if ($pdo->inTransaction()) {
                        try {
                            $pdo->rollBack();
                        } catch (PDOException $rollbackError) {
                            error_log("Aviso: Não foi possível fazer rollback: " . $rollbackError->getMessage());
                        }
                    }
                    throw $e;
                }
            }
            
            return false;
        } catch (Exception $e) {
            error_log("Erro ao reverter migration: " . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('get_expected_tables')) {
    /**
     * Retorna lista de tabelas esperadas baseadas nas migrations de criação de tabelas
     * @return array Array associativo: ['nome_tabela' => 'nome_arquivo_migration']
     */
    function get_expected_tables() {
        $expectedTables = [];
        $migrationsDir = __DIR__ . '/../migrations';
        
        if (!is_dir($migrationsDir)) {
            return $expectedTables;
        }
        
        $migrationFiles = glob($migrationsDir . '/*.php');
        
        foreach ($migrationFiles as $migrationFile) {
            $migrationName = basename($migrationFile);
            
            // Verificar se é uma migration de criação de tabela
            if (strpos($migrationName, 'criar_tabela_') !== false) {
                // Extrair nome da tabela do nome do arquivo
                // Formato: YYYYMMDD_HHMMSS_criar_tabela_nome_tabela.php
                if (preg_match('/criar_tabela_(.+)\.php$/', $migrationName, $matches)) {
                    $tableName = $matches[1];
                    $expectedTables[$tableName] = $migrationName;
                }
            }
            
            // Verificar se é a migration de instalação do plugin de recorrência
            // Esta migration cria a tabela 'assinaturas' através do install.php do plugin
            if ($migrationName === '20250125_140400_instalar_plugin_recorrencia_padrao.php') {
                $expectedTables['assinaturas'] = $migrationName;
            }
            
            // Verificar se é a migration que cria secoes e secao_produtos
            // Esta migration cria múltiplas tabelas: secoes, secao_produtos
            if ($migrationName === '20250131_100000_criar_secoes_e_vincular_modulos.php') {
                $expectedTables['secoes'] = $migrationName;
                $expectedTables['secao_produtos'] = $migrationName;
            }
        }
        
        // IMPORTANTE: Sobrescrever tabela 'usuarios' para verificar colunas adicionadas por migrations
        // A tabela usuarios existe (criada por migration) mas recebe colunas de novos gateways via outras migrations
        $expectedTables['usuarios'] = 'usuarios_base_plus_migrations';
        
        return $expectedTables;
    }
}

if (!function_exists('get_all_expected_columns_for_usuarios')) {
    /**
     * Coleta todas as colunas esperadas da tabela usuarios
     * a partir de todas as migrations que adicionam colunas (ALTER TABLE)
     * @param string $migrationsDir Diretório das migrations
     * @return array Array com nomes das colunas esperadas
     */
    function get_all_expected_columns_for_usuarios($migrationsDir) {
        $columns = [];
        
        // Colunas base da tabela usuarios (sempre esperadas)
        // Usar nomes reais das colunas do banco
        $columns = array_merge($columns, [
            'id', 'usuario', 'nome', 'telefone', 'senha', 'tipo',
            'mp_public_key', 'mp_access_token', 'pushinpay_token',
            'foto_perfil', 'ultima_visualizacao_notificacoes',
            'remember_token', 'password_reset_token', 'password_reset_expires',
            'password_setup_token', 'password_setup_expires', 'saas_plano_free_atribuido',
            'test_field'
        ]);
        
        // Buscar migrations que adicionam colunas à tabela usuarios
        $migrationFiles = glob($migrationsDir . '/*.php');
        
        foreach ($migrationFiles as $migrationFile) {
            $migrationName = basename($migrationFile);
            $content = file_get_contents($migrationFile);
            
            // Verificar se é uma migration que adiciona colunas à tabela usuarios
            // Procurar por padrões: ALTER TABLE `usuarios` ADD COLUMN `coluna_nome`
            if (stripos($content, 'ALTER TABLE') !== false && 
                (stripos($content, '`usuarios`') !== false || stripos($content, 'usuarios') !== false)) {
                
                // Extrair nomes das colunas adicionadas
                preg_match_all('/ADD\s+COLUMN\s+[`"]?([a-zA-Z_][a-zA-Z0-9_]*)[`"]?\s+/i', $content, $matches);
                if (!empty($matches[1])) {
                    foreach ($matches[1] as $columnName) {
                        if (!in_array($columnName, $columns)) {
                            $columns[] = $columnName;
                            error_log("INTEGRIDADE: Detectada coluna '{$columnName}' da tabela usuarios na migration {$migrationName}");
                        }
                    }
                }
            }
        }
        
        error_log("INTEGRIDADE: Total de colunas esperadas para tabela usuarios: " . count($columns) . " - " . implode(', ', $columns));
        return $columns;
    }
}

if (!function_exists('get_expected_columns_from_migration')) {
    /**
     * Extrai as colunas esperadas de uma migration de criação de tabela
     * Usa abordagem linha por linha para maior precisão
     * @param string $migrationFile Nome do arquivo de migration
     * @param string $tableName Nome da tabela (opcional, para migrations que criam múltiplas tabelas)
     * @return array Array com nomes das colunas esperadas
     */
    function get_expected_columns_from_migration($migrationFile, $tableName = null) {
        $columns = [];
        
        try {
            $migrationsDir = __DIR__ . '/../migrations';
            
            // Caso especial: tabela usuarios (agregação de múltiplas migrations)
            if ($migrationFile === 'usuarios_base_plus_migrations') {
                // Coletar todas as colunas esperadas da tabela usuarios
                // a partir de todas as migrations que adicionam colunas
                $columns = get_all_expected_columns_for_usuarios($migrationsDir);
                return $columns;
            }
            
            $filePath = $migrationsDir . '/' . $migrationFile;
            
            if (!file_exists($filePath)) {
                error_log("INTEGRIDADE: Arquivo de migration não encontrado: {$filePath}");
                return $columns;
            }
            
            // Caso especial: migration de instalação do plugin de recorrência
            // Esta migration cria a tabela assinaturas através do install.php do plugin
            if ($migrationFile === '20250125_140400_instalar_plugin_recorrencia_padrao.php') {
                $pluginInstallFile = __DIR__ . '/../plugins/recorrencia/install.php';
                if (file_exists($pluginInstallFile)) {
                    $installContent = file_get_contents($pluginInstallFile);
                    $installLines = explode("\n", $installContent);
                    
                    $inCreateTable = false;
                    $inTableDefinition = false;
                    $parenDepth = 0;
                    $sqlKeywords = ['PRIMARY', 'UNIQUE', 'KEY', 'INDEX', 'FOREIGN', 'CONSTRAINT', 'FULLTEXT'];
                    
                    foreach ($installLines as $line) {
                        $line = trim($line);
                        
                        // Detectar início do CREATE TABLE para assinaturas
                        if (stripos($line, 'CREATE TABLE') !== false && stripos($line, 'assinaturas') !== false) {
                            $inCreateTable = true;
                            if (strpos($line, '(') !== false) {
                                $inTableDefinition = true;
                                $parenDepth = substr_count($line, '(') - substr_count($line, ')');
                            }
                            continue;
                        }
                        
                        if ($inCreateTable && !$inTableDefinition) {
                            if (strpos($line, '(') !== false) {
                                $inTableDefinition = true;
                                $parenDepth = substr_count($line, '(') - substr_count($line, ')');
                            } else {
                                continue;
                            }
                        }
                        
                        if ($inTableDefinition) {
                            $parenDepth += substr_count($line, '(') - substr_count($line, ')');
                            
                            if (stripos($line, 'ENGINE') !== false || ($parenDepth <= 0 && strpos($line, ')') !== false)) {
                                break;
                            }
                            
                            // Extrair nome da coluna
                            if (preg_match('/^[`"]?([a-zA-Z_][a-zA-Z0-9_]*)[`"]?\s+([A-Z][A-Z0-9_]*)/i', $line, $matches)) {
                                $columnName = $matches[1];
                                if (!in_array(strtoupper($columnName), $sqlKeywords)) {
                                    $columns[] = $columnName;
                                }
                            }
                        }
                    }
                    
                    if (!empty($columns)) {
                        error_log("INTEGRIDADE: Extraídas " . count($columns) . " colunas do install.php do plugin de recorrência: " . implode(', ', $columns));
                        return array_unique($columns);
                    }
                }
                
                // Se install.php não existe ou não conseguiu extrair, retornar colunas esperadas baseadas na estrutura conhecida
                return ['id', 'produto_id', 'comprador_email', 'comprador_nome', 'venda_inicial_id', 'proxima_cobranca', 'ultima_cobranca', 'status', 'created_at', 'updated_at'];
            }
            
            // Ler o conteúdo do arquivo
            $fileContent = file_get_contents($filePath);
            $lines = explode("\n", $fileContent);
            
            $inCreateTable = false;
            $inTableDefinition = false;
            $parenDepth = 0;
            $foundCreateTable = false;
            $currentTableName = null;
            
            // Palavras-chave SQL que não são colunas
            $sqlKeywords = ['PRIMARY', 'UNIQUE', 'KEY', 'INDEX', 'FOREIGN', 'CONSTRAINT', 'FULLTEXT'];
            
            // Caso especial: migration que cria múltiplas tabelas (secoes e secao_produtos)
            // Se tableName foi fornecido, procurar especificamente por essa tabela
            if ($migrationFile === '20250131_100000_criar_secoes_e_vincular_modulos.php' && $tableName !== null) {
                // Procurar especificamente pela tabela solicitada
                $targetTable = $tableName;
            } else {
                $targetTable = null; // Extrair da primeira tabela encontrada
            }
            
            foreach ($lines as $lineNum => $line) {
                $originalLine = $line;
                $line = trim($line);
                
                // Detectar início do CREATE TABLE
                if (stripos($line, 'CREATE TABLE') !== false) {
                    // Extrair nome da tabela do CREATE TABLE
                    if (preg_match('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`"]?(\w+)[`"]?/i', $line, $tableMatches)) {
                        $currentTableName = $tableMatches[1];
                        
                        // Se estamos procurando por uma tabela específica, verificar se é a correta
                        if ($targetTable !== null && strtolower($currentTableName) !== strtolower($targetTable)) {
                            // Não é a tabela que estamos procurando, pular
                            $inCreateTable = false;
                            $inTableDefinition = false;
                            $currentTableName = null;
                            continue;
                        }
                    }
                    
                    $inCreateTable = true;
                    $foundCreateTable = true;
                    // Verificar se há parêntese de abertura na mesma linha
                    if (strpos($line, '(') !== false) {
                        $inTableDefinition = true;
                        $parenDepth = substr_count($line, '(') - substr_count($line, ')');
                    }
                    continue;
                }
                
                // Se encontramos CREATE TABLE, procurar pelo parêntese de abertura
                if ($inCreateTable && !$inTableDefinition) {
                    if (strpos($line, '(') !== false) {
                        $inTableDefinition = true;
                        $parenDepth = substr_count($line, '(') - substr_count($line, ')');
                    } else {
                        continue;
                    }
                }
                
                // Se estamos dentro da definição da tabela
                if ($inTableDefinition) {
                    // Atualizar profundidade dos parênteses
                    $parenDepth += substr_count($line, '(') - substr_count($line, ')');
                    
                    // Verificar se encontramos o fechamento (ENGINE ou parêntese final)
                    if (stripos($line, 'ENGINE') !== false || ($parenDepth <= 0 && strpos($line, ')') !== false)) {
                        // Se estávamos procurando por uma tabela específica e encontramos o fechamento,
                        // resetar para procurar pela próxima tabela (se houver)
                        if ($targetTable !== null && $currentTableName !== null && strtolower($currentTableName) !== strtolower($targetTable)) {
                            // Não era a tabela que procurávamos, resetar e continuar
                            $inCreateTable = false;
                            $inTableDefinition = false;
                            $currentTableName = null;
                            $parenDepth = 0;
                            continue;
                        }
                        break;
                    }
                    
                    // Procurar por definições de coluna (formato: `nome_coluna` tipo ...)
                    // Padrão melhorado: backtick, nome da coluna, backtick, espaço, tipo (mais flexível)
                    // Aceita qualquer tipo SQL (INT, VARCHAR, TINYINT, TIMESTAMP, etc.)
                    if (preg_match('/^[`"]?([a-zA-Z_][a-zA-Z0-9_]*)[`"]?\s+([A-Z][A-Z0-9_]*)/i', $line, $matches)) {
                        $columnName = $matches[1];
                        
                        // Ignorar se for palavra-chave SQL
                        if (!in_array(strtoupper($columnName), $sqlKeywords)) {
                            $columns[] = $columnName;
                        }
                    }
                    // Também tentar padrão sem backtick no início (caso raro)
                    elseif (preg_match('/^\s*([a-zA-Z_][a-zA-Z0-9_]*)\s+([A-Z][A-Z0-9_]*)/i', $line, $matches)) {
                        $columnName = $matches[1];
                        if (!in_array(strtoupper($columnName), $sqlKeywords)) {
                            $columns[] = $columnName;
                        }
                    }
                }
            }
            
            // Se não encontrou colunas com método linha por linha, tentar regex como fallback
            if (empty($columns) && $foundCreateTable) {
                error_log("INTEGRIDADE: Método linha por linha não encontrou colunas, tentando regex para {$migrationFile}");
                
                // Método fallback: regex no conteúdo completo
                // Se estamos procurando por uma tabela específica, usar padrão que captura o nome da tabela
                if ($targetTable !== null) {
                    // Procurar especificamente pela tabela solicitada
                    $pattern = '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`"]?' . preg_quote($targetTable, '/') . '[`"]?\s*\((.*?)\)\s*ENGINE/is';
                } else {
                    // Procurar pela primeira tabela encontrada
                    $pattern = '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`"]?(\w+)[`"]?\s*\((.*?)\)\s*ENGINE/is';
                }
                
                if (preg_match($pattern, $fileContent, $matches)) {
                    $tableDefinition = $matches[1] ?? $matches[2]; // Pegar a definição (índice varia dependendo do padrão)
                    
                    // Normalizar
                    $tableDefinition = preg_replace('/\s+/', ' ', $tableDefinition);
                    $tableDefinition = preg_replace('/COMMENT\s+[\'"][^\'"]*[\'"]/i', '', $tableDefinition);
                    $tableDefinition = preg_replace('/\b(?:PRIMARY\s+KEY|UNIQUE\s+KEY|KEY|INDEX|FULLTEXT\s+KEY|FOREIGN\s+KEY|CONSTRAINT)\s*\([^)]+\)/i', '', $tableDefinition);
                    
                    // Procurar todas as colunas (padrão melhorado para capturar tipos completos)
                    if (preg_match_all('/[`"]?([a-zA-Z_][a-zA-Z0-9_]*)[`"]?\s+([A-Z][A-Z0-9_]*)/i', $tableDefinition, $colMatches)) {
                        foreach ($colMatches[1] as $idx => $colName) {
                            if (!in_array(strtoupper($colName), $sqlKeywords)) {
                                $columns[] = $colName;
                            }
                        }
                    }
                }
            }
            
            $columns = array_unique($columns);
            
            // SEMPRE procurar em outras migrations que podem adicionar colunas à mesma tabela
            // (para casos como secao_produtos que recebe colunas de migrations posteriores)
            // Determinar nome da tabela: do parâmetro $tableName ou do nome do arquivo
            $targetTableName = $tableName;
            if ($targetTableName === null && preg_match('/criar_tabela_(.+)\.php$/', $migrationFile, $tableMatches)) {
                $targetTableName = $tableMatches[1];
            }
            
            if ($targetTableName !== null) {
                $allMigrationFiles = glob($migrationsDir . '/*.php');
                foreach ($allMigrationFiles as $otherMigrationFile) {
                    $otherMigrationName = basename($otherMigrationFile);
                    // Ignorar a própria migration
                    if ($otherMigrationName === $migrationFile) {
                        continue;
                    }
                    // Procurar por ALTER TABLE que adiciona colunas à mesma tabela
                    $otherContent = file_get_contents($otherMigrationFile);
                    // Padrão mais flexível: captura ALTER TABLE com backticks ou sem, e ADD COLUMN
                    // Exemplos: ALTER TABLE `secao_produtos` ADD COLUMN `imagem_capa_url`
                    //          ALTER TABLE secao_produtos ADD COLUMN imagem_capa_url
                    //          $pdo->exec("ALTER TABLE `secao_produtos` ADD COLUMN `imagem_capa_url` ...");
                    //          $pdo->exec('ALTER TABLE `secao_produtos` ADD COLUMN `link_personalizado` ...');
                    $patterns = [
                        // Padrão 1: com backticks em ambos (mais comum) - dentro de strings PHP
                        '/ALTER\s+TABLE\s+[`"]' . preg_quote($targetTableName, '/') . '[`"]\s+ADD\s+COLUMN\s+[`"]([a-zA-Z_][a-zA-Z0-9_]*)[`"]/i',
                        // Padrão 2: backticks opcionais - dentro de strings PHP
                        '/ALTER\s+TABLE\s+[`"]?' . preg_quote($targetTableName, '/') . '[`"]?\s+ADD\s+COLUMN\s+[`"]?([a-zA-Z_][a-zA-Z0-9_]*)[`"]?/i',
                        // Padrão 3: captura mesmo quando há caracteres antes (como $pdo->exec) - mais flexível
                        '/\$pdo\s*->\s*(?:exec|query|prepare|execute)\s*\([`"\']\s*ALTER\s+TABLE\s+[`"]?' . preg_quote($targetTableName, '/') . '[`"]?\s+ADD\s+COLUMN\s+[`"]?([a-zA-Z_][a-zA-Z0-9_]*)[`"]?/is',
                        // Padrão 4: captura em qualquer contexto (fallback mais amplo)
                        '/ADD\s+COLUMN\s+[`"]?([a-zA-Z_][a-zA-Z0-9_]*)[`"]?\s+.*?ALTER\s+TABLE\s+[`"]?' . preg_quote($targetTableName, '/') . '[`"]?/is',
                    ];
                    
                    foreach ($patterns as $patternIdx => $pattern) {
                        if (preg_match_all($pattern, $otherContent, $otherAlterMatches)) {
                            foreach ($otherAlterMatches[1] as $otherColumnName) {
                                if (!in_array($otherColumnName, $columns)) {
                                    $columns[] = $otherColumnName;
                                    error_log("INTEGRIDADE: Detectada coluna '{$otherColumnName}' adicionada por migration {$otherMigrationName} à tabela {$targetTableName} (padrão " . ($patternIdx + 1) . ")");
                                }
                            }
                            // Não fazer break aqui - continuar tentando outros padrões para capturar todas as colunas
                        }
                    }
                }
            }
            
            $columns = array_unique($columns);
            error_log("INTEGRIDADE: Extraídas " . count($columns) . " colunas da migration {$migrationFile}: " . implode(', ', $columns));
            
        } catch (Exception $e) {
            error_log("INTEGRIDADE: Erro ao extrair colunas da migration {$migrationFile}: " . $e->getMessage());
            error_log("INTEGRIDADE: Stack trace: " . $e->getTraceAsString());
        } catch (Throwable $e) {
            error_log("INTEGRIDADE: Erro fatal ao extrair colunas da migration {$migrationFile}: " . $e->getMessage());
            error_log("INTEGRIDADE: Stack trace: " . $e->getTraceAsString());
        }
        
        return $columns;
    }
}

if (!function_exists('get_existing_columns')) {
    /**
     * Obtém as colunas existentes de uma tabela no banco de dados
     * @param PDO $pdo Instância do PDO
     * @param string $tableName Nome da tabela
     * @return array Array com nomes das colunas existentes
     */
    function get_existing_columns($pdo, $tableName) {
        $columns = [];
        
        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM `{$tableName}`");
            $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($result as $row) {
                $columns[] = $row['Field'];
            }
        } catch (PDOException $e) {
            error_log("Erro ao obter colunas da tabela {$tableName}: " . $e->getMessage());
        }
        
        return $columns;
    }
}

if (!function_exists('get_column_definition_from_migration')) {
    /**
     * Extrai a definição completa de uma coluna específica de uma migration
     * @param string $migrationFile Nome do arquivo de migration
     * @param string $columnName Nome da coluna
     * @return string|null Definição completa da coluna ou null se não encontrada
     */
    function get_column_definition_from_migration($migrationFile, $columnName, $tableName = null) {
        $migrationsDir = __DIR__ . '/../migrations';
        $filePath = $migrationsDir . '/' . $migrationFile;
        
        if (!file_exists($filePath)) {
            return null;
        }
        
        // Caso especial: migration de instalação do plugin de recorrência
        // Esta migration cria a tabela assinaturas através do install.php do plugin
        if ($migrationFile === '20250125_140400_instalar_plugin_recorrencia_padrao.php') {
            $pluginInstallFile = __DIR__ . '/../plugins/recorrencia/install.php';
            if (file_exists($pluginInstallFile)) {
                $installContent = file_get_contents($pluginInstallFile);
                // Usar a mesma lógica de extração, mas procurar no install.php
                $fileContent = $installContent;
            } else {
                return null;
            }
        } else {
            try {
                $fileContent = file_get_contents($filePath);
            } catch (Exception $e) {
                return null;
            }
        }
        
        try {
            
            // Abordagem 1: Procurar primeiro no CREATE TABLE (mais confiável, definição completa)
            // Só depois procurar em ALTER TABLE se não encontrar no CREATE TABLE
            // Isso evita problemas com definições cortadas em ALTER TABLE
            
            // Abordagem 2: Ler linha por linha e procurar pela definição no CREATE TABLE
            $lines = explode("\n", $fileContent);
            $inCreateTable = false;
            $foundColumn = false;
            $columnLine = '';
            $targetTableFound = false;
            $currentTableName = null;
            
            foreach ($lines as $line) {
                $originalLine = $line;
                $line = trim($line);
                
                // Detectar início do CREATE TABLE (pode estar em string multi-linha)
                if (stripos($line, 'CREATE TABLE') !== false) {
                    // Extrair nome da tabela do CREATE TABLE
                    if (preg_match('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`"]?(\w+)[`"]?/i', $line, $tableMatches)) {
                        $currentTableName = $tableMatches[1];
                        
                        // Se tableName foi fornecido, verificar se é a tabela correta
                        if ($tableName !== null && strtolower($currentTableName) !== strtolower($tableName)) {
                            // Não é a tabela que procuramos, continuar
                            $inCreateTable = false;
                            $currentTableName = null;
                            continue;
                        }
                    }
                    $inCreateTable = true;
                    $targetTableFound = true;
                    continue;
                }
                
                // Se estamos dentro do CREATE TABLE
                if ($inCreateTable) {
                    // Verificar se encontramos a coluna (procurar por backtick + nome + backtick + espaço + definição)
                    // Padrão melhorado: captura tudo até a vírgula ou fim da linha
                    if (preg_match('/^`' . preg_quote($columnName, '/') . '`\s+(.+)$/i', $line, $matches)) {
                        $columnLine = trim($matches[1]);
                        // Remover vírgula final se houver
                        $columnLine = rtrim($columnLine, ',');
                        $foundColumn = true;
                        // Se a linha termina com vírgula, já temos a definição completa
                        if (substr(rtrim($line), -1) === ',') {
                            break;
                        }
                        // Se a linha não termina com vírgula, pode continuar na próxima linha (caso raro)
                        if (substr(rtrim($line), -1) !== ',' && substr(rtrim($line), -1) !== ')') {
                            // Continuar na próxima linha se necessário
                            continue;
                        }
                        break;
                    }
                    
                    // Se encontramos o fechamento do CREATE TABLE, parar
                    if (strpos($line, ')') !== false && (stripos($line, 'ENGINE') !== false || stripos($line, 'PRIMARY KEY') !== false)) {
                        break;
                    }
                }
            }
            
            if ($foundColumn && !empty($columnLine)) {
                // Remover vírgula final se houver
                $columnLine = rtrim($columnLine, ',');
                
                // Remover comentários SQL (COMMENT 'texto' ou COMMENT "texto" - pode estar incompleto)
                // Remover tudo a partir de COMMENT, incluindo aspas não fechadas
                $columnLine = preg_replace('/\s+COMMENT\s+[\'"][^\'"]*[\'"]?/i', '', $columnLine);
                $columnLine = preg_replace('/\s+COMMENT\s+.*$/i', '', $columnLine);
                
                // Limpar espaços extras
                $columnLine = preg_replace('/\s+/', ' ', $columnLine);
                $columnLine = trim($columnLine);
                
                if (!empty($columnLine)) {
                    error_log("INTEGRIDADE: Definição extraída (linha por linha) para coluna {$columnName}: {$columnLine}");
                    return $columnLine;
                }
            }
            
            // Abordagem 3: Regex no conteúdo completo (fallback) - melhorado para strings multi-linha
            $patterns = [
                // Padrão 1: mais simples e direto - captura tipo com parênteses fechados + NULL (sem COMMENT)
                // Exemplo: `link_personalizado` VARCHAR(500) NULL COMMENT '...'
                // Este padrão deve ser testado PRIMEIRO porque é o mais específico
                '/`' . preg_quote($columnName, '/') . '`\s+([A-Z][A-Z0-9_]*\([^)]+\)\s+NULL)(?=\s*(?:COMMENT|,|\)|$))/is',
                // Padrão 2: `coluna` tipo(parênteses) ... até COMMENT ou vírgula (mais genérico)
                '/`' . preg_quote($columnName, '/') . '`\s+([A-Z][A-Z0-9_]*\([^)]+\)[^,]*?)(?=\s*(?:COMMENT|,))/is',
                // Padrão 3: fallback - captura tudo até vírgula (mais permissivo)
                '/`' . preg_quote($columnName, '/') . '`\s+([^,]+)/is',
            ];
            
            foreach ($patterns as $idx => $pattern) {
                if (preg_match($pattern, $fileContent, $matches)) {
                    $colDef = trim($matches[1]);
                    
                    // Remover comentários SQL (COMMENT 'texto' ou COMMENT "texto" - pode estar incompleto)
                    // Remover tudo a partir de COMMENT, incluindo aspas não fechadas
                    $colDef = preg_replace('/\s+COMMENT\s+[\'"][^\'"]*[\'"]?/i', '', $colDef);
                    $colDef = preg_replace('/\s+COMMENT\s+.*$/i', '', $colDef);
                    
                    // Limpar espaços extras e quebras de linha
                    $colDef = preg_replace('/[\r\n]+/', ' ', $colDef);
                    $colDef = preg_replace('/\s+/', ' ', $colDef);
                    $colDef = trim($colDef);
                    $colDef = rtrim($colDef, ',');
                    
                    // VALIDAÇÃO CRÍTICA: Verificar se a definição está completa
                    // Se contém parêntese de abertura, deve ter parêntese de fechamento
                    if (preg_match('/\(/', $colDef)) {
                        $openParens = substr_count($colDef, '(');
                        $closeParens = substr_count($colDef, ')');
                        if ($openParens !== $closeParens) {
                            error_log("INTEGRIDADE: Definição incompleta (parênteses não fechados) para coluna {$columnName}: {$colDef}. Continuando busca...");
                            continue; // Continuar para o próximo padrão
                        }
                    }
                    
                    if (!empty($colDef) && strlen($colDef) > 2) {
                        error_log("INTEGRIDADE: Definição extraída (regex " . ($idx + 1) . ") para coluna {$columnName}: {$colDef}");
                        return $colDef;
                    }
                }
            }
            
            // Se não encontrou com os padrões acima, tentar abordagem mais complexa
            $createTablePos = stripos($fileContent, 'CREATE TABLE');
            if ($createTablePos !== false) {
                // Encontrar a posição do primeiro parêntese de abertura
                $openParenPos = strpos($fileContent, '(', $createTablePos);
                if ($openParenPos !== false) {
                    // Encontrar o parêntese de fechamento correspondente
                    $depth = 0;
                    $closeParenPos = $openParenPos;
                    for ($i = $openParenPos; $i < strlen($fileContent); $i++) {
                        $char = $fileContent[$i];
                        if ($char === '(') {
                            $depth++;
                        } elseif ($char === ')') {
                            $depth--;
                            if ($depth === 0) {
                                $closeParenPos = $i;
                                break;
                            }
                        }
                    }
                    
                    if ($depth === 0) {
                        // Extrair a definição da tabela
                        $tableDefinition = substr($fileContent, $openParenPos + 1, $closeParenPos - $openParenPos - 1);
                        
                        // Procurar pela coluna na definição extraída
                        $pattern = '/`' . preg_quote($columnName, '/') . '`\s+([^,)]+?)(?=,|\))/is';
                        if (preg_match($pattern, $tableDefinition, $matches)) {
                            $colDef = trim($matches[1]);
                            $colDef = preg_replace('/\s+COMMENT\s+[\'"][^\'"]*[\'"]/i', '', $colDef);
                            $colDef = preg_replace('/\s+/', ' ', $colDef);
                            $colDef = trim($colDef);
                            $colDef = rtrim($colDef, ',');
                            
                            if (!empty($colDef)) {
                                error_log("INTEGRIDADE: Definição extraída (método 2) para coluna {$columnName}: {$colDef}");
                                return $colDef;
                            }
                        }
                    }
                }
            }
            
            error_log("INTEGRIDADE: Coluna {$columnName} não encontrada na migration {$migrationFile} (CREATE TABLE)");
        } catch (Exception $e) {
            error_log("Erro ao extrair definição da coluna {$columnName} da migration {$migrationFile}: " . $e->getMessage());
        }
        
        // Se não encontrou no CREATE TABLE, procurar no ALTER TABLE da própria migration primeiro
        if ($tableName !== null) {
            // Procurar no ALTER TABLE da própria migration
            // Padrão melhorado: captura ENUM, VARCHAR, etc. com NULL, NOT NULL, DEFAULT, etc.
            // Exemplo: ENUM('vertical', 'horizontal') NOT NULL DEFAULT 'vertical'
            $patterns = [
                // Padrão 1: Tipo com parênteses + NOT NULL + DEFAULT
                '/ALTER\s+TABLE\s+[`"]?' . preg_quote($tableName, '/') . '[`"]?\s+ADD\s+COLUMN\s+[`"]?' . preg_quote($columnName, '/') . '[`"]?\s+([A-Z][A-Z0-9_]*\([^)]+\)\s+NOT\s+NULL\s+DEFAULT\s+[^\s]+)(?=\s*(?:AFTER|COMMENT|,|$))/is',
                // Padrão 2: Tipo com parênteses + NOT NULL (sem DEFAULT)
                '/ALTER\s+TABLE\s+[`"]?' . preg_quote($tableName, '/') . '[`"]?\s+ADD\s+COLUMN\s+[`"]?' . preg_quote($columnName, '/') . '[`"]?\s+([A-Z][A-Z0-9_]*\([^)]+\)\s+NOT\s+NULL)(?=\s*(?:AFTER|COMMENT|,|$))/is',
                // Padrão 3: Tipo com parênteses + DEFAULT NULL (importante para migrations que usam DEFAULT NULL)
                '/ALTER\s+TABLE\s+[`"]?' . preg_quote($tableName, '/') . '[`"]?\s+ADD\s+COLUMN\s+[`"]?' . preg_quote($columnName, '/') . '[`"]?\s+([A-Z][A-Z0-9_]*\([^)]+\)\s+DEFAULT\s+NULL)(?=\s*(?:AFTER|COMMENT|,|$))/is',
                // Padrão 4: Tipo com parênteses + NULL
                '/ALTER\s+TABLE\s+[`"]?' . preg_quote($tableName, '/') . '[`"]?\s+ADD\s+COLUMN\s+[`"]?' . preg_quote($columnName, '/') . '[`"]?\s+([A-Z][A-Z0-9_]*\([^)]+\)\s+NULL)(?=\s*(?:AFTER|COMMENT|,|$))/is',
                // Padrão 5: Tipo com parênteses apenas (sem NULL/NOT NULL)
                '/ALTER\s+TABLE\s+[`"]?' . preg_quote($tableName, '/') . '[`"]?\s+ADD\s+COLUMN\s+[`"]?' . preg_quote($columnName, '/') . '[`"]?\s+([A-Z][A-Z0-9_]*\([^)]+\))(?=\s*(?:AFTER|COMMENT|,|$))/is',
            ];
            
            foreach ($patterns as $patternIdx => $pattern) {
                if (preg_match($pattern, $fileContent, $matches)) {
                    $colDef = trim($matches[1]);
                    // Validar que está completo (tem parêntese fechado se tiver parêntese de abertura)
                    if (preg_match('/\(/', $colDef)) {
                        $openParens = substr_count($colDef, '(');
                        $closeParens = substr_count($colDef, ')');
                        if ($openParens === $closeParens && !empty($colDef)) {
                            error_log("INTEGRIDADE: Definição encontrada para coluna {$columnName} na própria migration {$migrationFile} (ALTER TABLE, padrão " . ($patternIdx + 1) . "): {$colDef}");
                            return $colDef;
                        }
                    } elseif (!empty($colDef)) {
                        // Tipo sem parênteses (ex: INT, TEXT)
                        error_log("INTEGRIDADE: Definição encontrada para coluna {$columnName} na própria migration {$migrationFile} (ALTER TABLE, padrão " . ($patternIdx + 1) . "): {$colDef}");
                        return $colDef;
                    }
                }
            }
            
            // Se ainda não encontrou, procurar em outras migrations via ALTER TABLE
            $allMigrationFiles = glob($migrationsDir . '/*.php');
            foreach ($allMigrationFiles as $otherMigrationFile) {
                $otherMigrationName = basename($otherMigrationFile);
                // Ignorar a própria migration (já procuramos acima)
                if ($otherMigrationName === $migrationFile) {
                    continue;
                }
                // Procurar por ALTER TABLE que adiciona esta coluna específica à mesma tabela
                $otherContent = file_get_contents($otherMigrationFile);
                
                foreach ($patterns as $patternIdx => $pattern) {
                    if (preg_match($pattern, $otherContent, $matches)) {
                        $colDef = trim($matches[1]);
                        // Validar que está completo
                        if (preg_match('/\(/', $colDef)) {
                            $openParens = substr_count($colDef, '(');
                            $closeParens = substr_count($colDef, ')');
                            if ($openParens === $closeParens && !empty($colDef)) {
                                error_log("INTEGRIDADE: Definição encontrada para coluna {$columnName} na migration {$otherMigrationName} (ALTER TABLE, padrão " . ($patternIdx + 1) . "): {$colDef}");
                                return $colDef;
                            }
                        } elseif (!empty($colDef)) {
                            error_log("INTEGRIDADE: Definição encontrada para coluna {$columnName} na migration {$otherMigrationName} (ALTER TABLE, padrão " . ($patternIdx + 1) . "): {$colDef}");
                            return $colDef;
                        }
                    }
                }
            }
        }
        
        return null;
    }
}

if (!function_exists('fix_missing_columns')) {
    /**
     * Adiciona colunas faltantes em tabelas existentes
     * @param PDO $pdo Instância do PDO
     * @param array $missingColumns Array associativo: ['nome_tabela' => ['coluna1', 'coluna2', ...]]
     * @param array $migrationMap Array associativo: ['nome_tabela' => 'nome_arquivo_migration']
     * @return array Resultado da operação
     */
    function fix_missing_columns($pdo, $missingColumns, $migrationMap) {
        $results = [
            'added' => [],
            'errors' => []
        ];
        
        foreach ($missingColumns as $tableName => $columns) {
            if (!isset($migrationMap[$tableName])) {
                $results['errors'][] = [
                    'table' => $tableName,
                    'error' => 'Migration não encontrada para a tabela'
                ];
                continue;
            }
            
            $migrationFile = $migrationMap[$tableName];
            
            foreach ($columns as $columnName) {
                try {
                    // Verificar se a coluna já existe (busca fresca do banco)
                    $stmt = $pdo->query("SHOW COLUMNS FROM `{$tableName}` LIKE '{$columnName}'");
                    if ($stmt !== false && $stmt->rowCount() > 0) {
                        error_log("INTEGRIDADE: Coluna {$columnName} já existe na tabela {$tableName}");
                        $results['added'][] = [
                            'table' => $tableName,
                            'column' => $columnName,
                            'status' => 'already_exists'
                        ];
                        continue;
                    }
                    
                    // Obter definição completa da coluna (passar também o nome da tabela para procurar em outras migrations)
                    $columnDefinition = get_column_definition_from_migration($migrationFile, $columnName, $tableName);
                    
                    if (empty($columnDefinition)) {
                        error_log("INTEGRIDADE: Definição não encontrada para coluna {$columnName} na tabela {$tableName} (migration: {$migrationFile})");
                        $results['errors'][] = [
                            'table' => $tableName,
                            'column' => $columnName,
                            'error' => 'Definição da coluna não encontrada na migration',
                            'migration' => $migrationFile
                        ];
                        continue;
                    }
                    
                    // IMPORTANTE: Remover COMMENT da definição antes de usar (pode causar erros de sintaxe SQL)
                    // Garantir que o COMMENT seja completamente removido (múltiplas tentativas)
                    $columnDefinition = preg_replace('/\s+COMMENT\s+[\'"][^\'"]*[\'"]?/i', '', $columnDefinition);
                    $columnDefinition = preg_replace('/\s+COMMENT\s+[\'"][^\'"]*[\'"]?/i', '', $columnDefinition); // Duplicado para garantir
                    $columnDefinition = preg_replace('/\s+COMMENT\s+.*$/i', '', $columnDefinition);
                    $columnDefinition = preg_replace('/\s+COMMENT\s+.*$/i', '', $columnDefinition); // Duplicado para garantir
                    $columnDefinition = trim($columnDefinition);
                    
                    // Log da definição após limpeza
                    error_log("INTEGRIDADE: Definição após limpeza para coluna {$columnName}: {$columnDefinition}");
                    
                    // Validar se a definição parece válida (deve conter pelo menos um tipo)
                    if (!preg_match('/\b(INT|VARCHAR|TEXT|TINYINT|DATETIME|TIMESTAMP|DECIMAL|ENUM|DATE|TIME|BLOB|JSON)\b/i', $columnDefinition)) {
                        error_log("INTEGRIDADE: Definição da coluna parece inválida: {$columnDefinition}");
                        $results['errors'][] = [
                            'table' => $tableName,
                            'column' => $columnName,
                            'error' => 'Definição da coluna parece inválida: ' . $columnDefinition,
                            'migration' => $migrationFile,
                            'definition' => $columnDefinition
                        ];
                        continue;
                    }
                    
                    // Executar ALTER TABLE para adicionar a coluna
                    // Validar a definição antes de usar
                    if (empty(trim($columnDefinition))) {
                        error_log("INTEGRIDADE: ERRO - Definição da coluna está vazia para {$columnName}");
                        $results['errors'][] = [
                            'table' => $tableName,
                            'column' => $columnName,
                            'error' => 'Definição da coluna está vazia',
                            'migration' => $migrationFile
                        ];
                        continue;
                    }
                    
                    // Tentar adicionar no final primeiro (mais seguro)
                    // IMPORTANTE: Não incluir COMMENT no SQL para evitar problemas com aspas
                    $sql = "ALTER TABLE `{$tableName}` ADD COLUMN `{$columnName}` {$columnDefinition}";
                    
                    error_log("INTEGRIDADE: Executando SQL: {$sql}");
                    error_log("INTEGRIDADE: Definição completa extraída: {$columnDefinition}");
                    
                    // Verificar se há erro antes de executar
                    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    
                    try {
                        error_log("INTEGRIDADE: Tentando executar SQL: {$sql}");
                        
                        // Verificar se a coluna realmente não existe antes de executar
                        $stmtCheck = $pdo->query("SHOW COLUMNS FROM `{$tableName}` LIKE '{$columnName}'");
                        if ($stmtCheck !== false && $stmtCheck->rowCount() > 0) {
                            error_log("INTEGRIDADE: Coluna {$columnName} já existe antes de executar ALTER TABLE");
                            $results['added'][] = [
                                'table' => $tableName,
                                'column' => $columnName,
                                'status' => 'already_exists',
                                'sql' => $sql
                            ];
                            continue;
                        }
                        
                        // Executar o ALTER TABLE usando prepare/execute para melhor controle de erros
                        try {
                            $stmt = $pdo->prepare($sql);
                            if ($stmt === false) {
                                $errorInfo = $pdo->errorInfo();
                                $errorMsg = isset($errorInfo[2]) ? $errorInfo[2] : 'Erro ao preparar SQL';
                                error_log("INTEGRIDADE: ERRO ao preparar SQL: {$errorMsg}");
                                error_log("INTEGRIDADE: ErrorInfo: " . json_encode($errorInfo));
                                throw new PDOException($errorMsg, isset($errorInfo[1]) ? $errorInfo[1] : 0);
                            }
                            
                            $executed = $stmt->execute();
                            if ($executed === false) {
                                $errorInfo = $stmt->errorInfo();
                                $errorMsg = isset($errorInfo[2]) ? $errorInfo[2] : 'Erro ao executar SQL';
                                error_log("INTEGRIDADE: ERRO ao executar SQL: {$errorMsg}");
                                error_log("INTEGRIDADE: ErrorInfo: " . json_encode($errorInfo));
                                throw new PDOException($errorMsg, isset($errorInfo[1]) ? $errorInfo[1] : 0);
                            }
                            
                            $rowsAffected = $stmt->rowCount();
                            error_log("INTEGRIDADE: SQL executado com sucesso. Linhas afetadas: {$rowsAffected}");
                            
                            // Verificar erro imediatamente após execução
                            $errorInfo = $pdo->errorInfo();
                            if (isset($errorInfo[0]) && $errorInfo[0] !== '00000' && $errorInfo[0] !== '') {
                                $errorMsg = isset($errorInfo[2]) ? $errorInfo[2] : 'Erro desconhecido do PDO';
                                error_log("INTEGRIDADE: ERRO do PDO após execução: {$errorMsg}");
                                error_log("INTEGRIDADE: ErrorInfo completo: " . json_encode($errorInfo));
                                throw new PDOException($errorMsg, isset($errorInfo[1]) ? $errorInfo[1] : 0);
                            }
                            
                            error_log("INTEGRIDADE: PDO não reportou erros após execução. ErrorInfo: " . json_encode($errorInfo));
                        } catch (PDOException $e) {
                            // Re-throw para ser capturado pelo catch externo
                            throw $e;
                        }
                        
                        // Aguardar para garantir que o banco processou
                        usleep(500000); // 0.5 segundo
                        
                        // Verificar se a coluna foi realmente adicionada (múltiplas tentativas)
                        $maxRetries = 10;
                        $columnAdded = false;
                        $lastColumns = [];
                        
                        for ($retry = 0; $retry < $maxRetries; $retry++) {
                            // Buscar colunas frescas do banco (usar query direta para garantir)
                            try {
                                $stmt = $pdo->query("SHOW COLUMNS FROM `{$tableName}`");
                                if ($stmt !== false) {
                                    $resultCols = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                    $lastColumns = [];
                                    foreach ($resultCols as $row) {
                                        $lastColumns[] = $row['Field'];
                                    }
                                    
                                    error_log("INTEGRIDADE: Tentativa " . ($retry + 1) . "/{$maxRetries} - Colunas existentes: " . implode(', ', $lastColumns));
                                    
                                    // Comparação case-insensitive
                                    $lastColumnsLower = array_map('strtolower', $lastColumns);
                                    if (in_array(strtolower($columnName), $lastColumnsLower)) {
                                        $columnAdded = true;
                                        error_log("INTEGRIDADE: Coluna {$columnName} confirmada na tabela {$tableName} na tentativa " . ($retry + 1));
                                        break;
                                    }
                                }
                            } catch (Exception $e) {
                                error_log("INTEGRIDADE: Erro ao verificar coluna na tentativa " . ($retry + 1) . ": " . $e->getMessage());
                            }
                            
                            if ($retry < $maxRetries - 1) {
                                usleep(300000); // Aguardar 0.3 segundo
                            }
                        }
                        
                        if (!$columnAdded) {
                            $errorMsg = 'Coluna não foi adicionada após ' . $maxRetries . ' tentativas de verificação';
                            error_log("INTEGRIDADE: ERRO - Falha ao adicionar coluna {$columnName} na tabela {$tableName}. {$errorMsg}");
                            error_log("INTEGRIDADE: SQL executado foi: {$sql}");
                            error_log("INTEGRIDADE: Colunas encontradas: " . implode(', ', $lastColumns));
                            error_log("INTEGRIDADE: Procurando por: " . strtolower($columnName));
                            
                            $results['errors'][] = [
                                'table' => $tableName,
                                'column' => $columnName,
                                'error' => $errorMsg,
                                'sql' => $sql,
                                'existing_columns' => $lastColumns,
                                'definition' => $columnDefinition
                            ];
                            continue;
                        }
                        
                        error_log("INTEGRIDADE: SUCESSO - Coluna {$columnName} adicionada e confirmada na tabela {$tableName}");
                        
                        $results['added'][] = [
                            'table' => $tableName,
                            'column' => $columnName,
                            'status' => 'added',
                            'sql' => $sql,
                            'verified' => true
                        ];
                    } catch (PDOException $e) {
                        $errorMsg = $e->getMessage();
                        $errorCode = $e->getCode();
                        error_log("INTEGRIDADE: Exceção PDO ao executar SQL: {$errorMsg} (Código: {$errorCode})");
                        error_log("INTEGRIDADE: SQL que falhou: {$sql}");
                        $results['errors'][] = [
                            'table' => $tableName,
                            'column' => $columnName,
                            'error' => $errorMsg,
                            'error_code' => $errorCode,
                            'sql' => $sql
                        ];
                        continue;
                    }
                    
                } catch (PDOException $e) {
                    $errorInfo = $pdo->errorInfo();
                    $errorMsg = $e->getMessage();
                    if (isset($errorInfo[2])) {
                        $errorMsg .= ' | SQL Error: ' . $errorInfo[2];
                    }
                    $results['errors'][] = [
                        'table' => $tableName,
                        'column' => $columnName,
                        'error' => $errorMsg,
                        'sql_state' => isset($errorInfo[0]) ? $errorInfo[0] : '',
                        'sql_code' => isset($errorInfo[1]) ? $errorInfo[1] : ''
                    ];
                    error_log("INTEGRIDADE PDOException ao adicionar coluna {$columnName} na tabela {$tableName}: {$errorMsg}");
                } catch (Exception $e) {
                    $results['errors'][] = [
                        'table' => $tableName,
                        'column' => $columnName,
                        'error' => $e->getMessage()
                    ];
                    error_log("INTEGRIDADE Exception ao adicionar coluna {$columnName} na tabela {$tableName}: " . $e->getMessage());
                }
            }
        }
        
        return $results;
    }
}

if (!function_exists('check_database_integrity')) {
    /**
     * Verifica a integridade do banco de dados comparando tabelas esperadas com existentes
     * Também verifica colunas faltantes em cada tabela
     * @param PDO $pdo Instância do PDO
     * @return array Array com status da verificação
     */
    function check_database_integrity($pdo) {
        $result = [
            'status' => 'ok',
            'total_expected' => 0,
            'total_existing' => 0,
            'missing_tables' => [],
            'existing_tables' => [],
            'extra_tables' => [],
            'missing_columns' => [],
            'total_missing_columns' => 0,
            'details' => []
        ];
        
        try {
            // Forçar busca fresca do banco (sem cache)
            // Garantir que estamos usando a conexão correta
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
            
            // Obter tabelas esperadas das migrations
            if (!function_exists('get_expected_tables')) {
                throw new Exception('Função get_expected_tables não encontrada');
            }
            
            $expectedTables = get_expected_tables();
            if (!is_array($expectedTables)) {
                $expectedTables = [];
            }
            $result['total_expected'] = count($expectedTables);
            
            // Obter tabelas existentes no banco (sempre buscar do banco, nunca cache)
            $stmt = $pdo->query("SHOW TABLES");
            if ($stmt === false) {
                $errorInfo = $pdo->errorInfo();
                throw new Exception('Erro ao executar SHOW TABLES: ' . (isset($errorInfo[2]) ? $errorInfo[2] : 'Erro desconhecido'));
            }
            $existingTables = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if (!is_array($existingTables)) {
                $existingTables = [];
            }
            $result['total_existing'] = count($existingTables);
            
            // Converter para array associativo para facilitar busca
            $existingTablesMap = array_flip($existingTables);
            
            // Verificar cada tabela esperada
            foreach ($expectedTables as $tableName => $migrationFile) {
                try {
                    if (isset($existingTablesMap[$tableName])) {
                        // Tabela existe, verificar colunas
                        $expectedColumns = [];
                        try {
                            // Passar nome da tabela para migrations que criam múltiplas tabelas
                            $expectedColumns = get_expected_columns_from_migration($migrationFile, $tableName);
                            if (empty($expectedColumns)) {
                                error_log("INTEGRIDADE: AVISO - Nenhuma coluna extraída da migration {$migrationFile} para tabela {$tableName}");
                            } else {
                                error_log("INTEGRIDADE: Tabela {$tableName} - Colunas esperadas (" . count($expectedColumns) . "): " . implode(', ', $expectedColumns));
                            }
                        } catch (Exception $e) {
                            error_log("INTEGRIDADE: Erro ao obter colunas esperadas da migration {$migrationFile}: " . $e->getMessage());
                            error_log("INTEGRIDADE: Stack trace: " . $e->getTraceAsString());
                            // Continuar mesmo se falhar
                        }
                        
                        $existingColumns = [];
                        try {
                            // Sempre buscar colunas frescas do banco (sem cache)
                            $stmt = $pdo->query("SHOW COLUMNS FROM `{$tableName}`");
                            if ($stmt !== false) {
                                $resultCols = $stmt->fetchAll(PDO::FETCH_ASSOC);
                                foreach ($resultCols as $row) {
                                    $existingColumns[] = $row['Field'];
                                }
                                error_log("INTEGRIDADE: Tabela {$tableName} - Colunas existentes (" . count($existingColumns) . "): " . implode(', ', $existingColumns));
                            }
                        } catch (Exception $e) {
                            error_log("INTEGRIDADE: Erro ao obter colunas existentes da tabela {$tableName}: " . $e->getMessage());
                            // Continuar mesmo se falhar
                        } catch (PDOException $e) {
                            error_log("INTEGRIDADE: Erro PDO ao obter colunas da tabela {$tableName}: " . $e->getMessage());
                            // Continuar mesmo se falhar
                        }
                        
                        // Comparar arrays (case-insensitive para segurança)
                        $expectedColumnsLower = array_map('strtolower', $expectedColumns);
                        $existingColumnsLower = array_map('strtolower', $existingColumns);
                        $missingColumns = [];
                        
                        foreach ($expectedColumns as $idx => $expectedCol) {
                            if (!in_array(strtolower($expectedCol), $existingColumnsLower)) {
                                $missingColumns[] = $expectedCol;
                            }
                        }
                        
                        if (!empty($missingColumns)) {
                            error_log("INTEGRIDADE: Tabela {$tableName} - Colunas faltando (" . count($missingColumns) . "): " . implode(', ', $missingColumns));
                        }
                    
                    if (!empty($missingColumns)) {
                        $result['missing_columns'][$tableName] = array_values($missingColumns);
                        $result['total_missing_columns'] += count($missingColumns);
                        $result['status'] = 'error';
                        
                        $result['details'][$tableName] = [
                            'status' => 'columns_missing',
                            'migration' => $migrationFile,
                            'exists' => true,
                            'expected_columns' => $expectedColumns,
                            'existing_columns' => $existingColumns,
                            'missing_columns' => array_values($missingColumns)
                        ];
                    } else {
                        $result['existing_tables'][] = $tableName;
                        $result['details'][$tableName] = [
                            'status' => 'ok',
                            'migration' => $migrationFile,
                            'exists' => true,
                            'expected_columns' => $expectedColumns,
                            'existing_columns' => $existingColumns,
                            'missing_columns' => []
                        ];
                    }
                    } else {
                        // Tabela não existe
                        $result['missing_tables'][] = $tableName;
                        $expectedColumns = [];
                        try {
                            // Passar nome da tabela para migrations que criam múltiplas tabelas
                            $expectedColumns = get_expected_columns_from_migration($migrationFile, $tableName);
                        } catch (Exception $e) {
                            error_log("INTEGRIDADE: Erro ao obter colunas esperadas da migration {$migrationFile}: " . $e->getMessage());
                        }
                        
                        $result['details'][$tableName] = [
                            'status' => 'missing',
                            'migration' => $migrationFile,
                            'exists' => false,
                            'expected_columns' => $expectedColumns,
                            'existing_columns' => [],
                            'missing_columns' => $expectedColumns // Todas as colunas estão faltando se a tabela não existe
                        ];
                        $result['status'] = 'error';
                    }
                } catch (Exception $e) {
                    error_log("INTEGRIDADE: Erro ao verificar tabela {$tableName}: " . $e->getMessage());
                    // Adicionar como erro mas continuar
                    $result['details'][$tableName] = [
                        'status' => 'error',
                        'migration' => $migrationFile,
                        'exists' => false,
                        'error' => $e->getMessage()
                    ];
                    $result['status'] = 'error';
                }
            }
            
            // Verificar tabelas extras (existem no banco mas não têm migration)
            // Ignorar tabelas do sistema MySQL
            $systemTables = ['schema_migrations', 'test_migration_table'];
            foreach ($existingTables as $tableName) {
                if (!isset($expectedTables[$tableName]) && !in_array($tableName, $systemTables)) {
                    // Verificar se não é uma tabela de sistema do MySQL
                    if (strpos($tableName, 'mysql.') === false && 
                        strpos($tableName, 'information_schema.') === false &&
                        strpos($tableName, 'performance_schema.') === false &&
                        strpos($tableName, 'sys.') === false) {
                        $result['extra_tables'][] = $tableName;
                    }
                }
            }
            
            // Verificar plugin de recorrência (migration 20250125_140400_instalar_plugin_recorrencia_padrao.php)
            // Esta migration instala e ativa o plugin de recorrência por padrão
            // A tabela 'assinaturas' é criada pelo install.php do plugin
            // A tabela assinaturas já é verificada acima via get_expected_tables()
            // Aqui apenas adicionamos informação sobre o status do plugin se a tabela foi detectada
            try {
                // Verificar se tabela plugins existe
                $stmt = $pdo->query("SHOW TABLES LIKE 'plugins'");
                if ($stmt->rowCount() > 0 && isset($result['details']['assinaturas'])) {
                    // Verificar se plugin de recorrência está instalado e ativo
                    $stmt = $pdo->prepare("SELECT id, ativo FROM plugins WHERE pasta = ?");
                    $stmt->execute(['recorrencia']);
                    $plugin_recorrencia = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    // Adicionar informação sobre o plugin no detalhe da tabela assinaturas
                    if (!$plugin_recorrencia) {
                        $result['details']['assinaturas']['plugin_check'] = 'Plugin de recorrência não está instalado';
                        error_log("INTEGRIDADE: Plugin de recorrência não está instalado. Migration 20250125_140400_instalar_plugin_recorrencia_padrao.php deve ser executada.");
                    } elseif ($plugin_recorrencia['ativo'] == 0) {
                        $result['details']['assinaturas']['plugin_check'] = 'Plugin de recorrência está instalado mas inativo';
                        error_log("INTEGRIDADE: Plugin de recorrência está instalado mas inativo. Deve ser ativado.");
                    } else {
                        $result['details']['assinaturas']['plugin_check'] = 'Plugin de recorrência está instalado e ativo';
                        error_log("INTEGRIDADE: Plugin de recorrência está instalado e ativo.");
                    }
                }
            } catch (Exception $e) {
                error_log("INTEGRIDADE: Erro ao verificar plugin de recorrência: " . $e->getMessage());
            } catch (PDOException $e) {
                error_log("INTEGRIDADE: Erro PDO ao verificar plugin de recorrência: " . $e->getMessage());
            }
            
        } catch (PDOException $e) {
            $result['status'] = 'error';
            $result['error'] = $e->getMessage();
            error_log("Erro ao verificar integridade do banco: " . $e->getMessage());
        } catch (Exception $e) {
            $result['status'] = 'error';
            $result['error'] = $e->getMessage();
            error_log("Erro ao verificar integridade do banco: " . $e->getMessage());
        }
        
        return $result;
    }
}

if (!function_exists('extract_table_columns_from_sql')) {
    /**
     * Extrai apenas os nomes das colunas de uma tabela do SQL original
     * @param string $sqlContent Conteúdo do arquivo SQL
     * @param string $tableName Nome da tabela
     * @return array Array com nomes das colunas
     */
    function extract_table_columns_from_sql($sqlContent, $tableName) {
        $columns = [];
        
        // Procurar pelo CREATE TABLE da tabela específica
        $pattern = '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`"]?' . preg_quote($tableName, '/') . '[`"]?\s*\((.*?)\)\s*ENGINE/is';
        
        if (preg_match($pattern, $sqlContent, $matches)) {
            $tableDefinition = $matches[1];
            
            // Extrair colunas usando regex
            // Procurar por padrão: `nome_coluna` tipo ...
            if (preg_match_all('/`([a-zA-Z_][a-zA-Z0-9_]*)`\s+[A-Z]/i', $tableDefinition, $colMatches)) {
                $columns = $colMatches[1];
            }
            
            // Remover palavras-chave SQL que não são colunas
            $sqlKeywords = ['PRIMARY', 'UNIQUE', 'KEY', 'INDEX', 'FOREIGN', 'CONSTRAINT', 'FULLTEXT'];
            $columns = array_filter($columns, function($col) use ($sqlKeywords) {
                return !in_array(strtoupper($col), $sqlKeywords);
            });
            
            $columns = array_values($columns);
        }
        
        return $columns;
    }
}

if (!function_exists('validate_migration_completeness')) {
    /**
     * Valida se todas as migrations estão completas comparando com o SQL original
     * @param string|null $sqlFilePath Caminho para o arquivo SQL original (opcional)
     * @return array Relatório de validação detalhado
     */
    function validate_migration_completeness($sqlFilePath = null) {
        $report = [
            'status' => 'ok',
            'total_migrations' => 0,
            'valid_migrations' => 0,
            'invalid_migrations' => [],
            'missing_tables_in_sql' => [],
            'missing_tables_in_migrations' => [],
            'column_mismatches' => []
        ];
        
        try {
            // Se não fornecido, tentar encontrar o arquivo SQL padrão
            if ($sqlFilePath === null) {
                $sqlFilePath = __DIR__ . '/../banco-criar-migration.sql';
            }
            
            // Obter tabelas esperadas das migrations
            $expectedTables = get_expected_tables();
            $report['total_migrations'] = count($expectedTables);
            
            // Se o arquivo SQL existe, comparar
            if (file_exists($sqlFilePath)) {
                $sqlContent = file_get_contents($sqlFilePath);
                
                // Extrair nomes de tabelas do SQL
                $sqlTables = [];
                if (preg_match_all('/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`"]?(\w+)[`"]?/i', $sqlContent, $matches)) {
                    $sqlTables = array_unique($matches[1]);
                }
                
                // Comparar tabelas
                foreach ($expectedTables as $tableName => $migrationFile) {
                    if (!in_array($tableName, $sqlTables)) {
                        $report['missing_tables_in_sql'][] = [
                            'table' => $tableName,
                            'migration' => $migrationFile
                        ];
                        $report['status'] = 'error';
                    } else {
                        // Comparar colunas da migration com o SQL original
                        $sqlColumns = extract_table_columns_from_sql($sqlContent, $tableName);
                        $migrationColumns = get_expected_columns_from_migration($migrationFile, $tableName);
                        
                        if (!empty($sqlColumns)) {
                            // Normalizar para comparação case-insensitive
                            $sqlColumnsLower = array_map('strtolower', $sqlColumns);
                            $migrationColumnsLower = array_map('strtolower', $migrationColumns);
                            
                            $missingInMigration = [];
                            $extraInMigration = [];
                            
                            // Colunas no SQL que não estão na migration
                            foreach ($sqlColumns as $sqlCol) {
                                if (!in_array(strtolower($sqlCol), $migrationColumnsLower)) {
                                    $missingInMigration[] = $sqlCol;
                                }
                            }
                            
                            // Colunas na migration que não estão no SQL
                            foreach ($migrationColumns as $migCol) {
                                if (!in_array(strtolower($migCol), $sqlColumnsLower)) {
                                    $extraInMigration[] = $migCol;
                                }
                            }
                            
                            if (!empty($missingInMigration) || !empty($extraInMigration)) {
                                $report['column_mismatches'][$tableName] = [
                                    'migration' => $migrationFile,
                                    'missing_in_migration' => array_values($missingInMigration),
                                    'extra_in_migration' => array_values($extraInMigration),
                                    'sql_columns' => $sqlColumns,
                                    'migration_columns' => $migrationColumns,
                                    'sql_columns_count' => count($sqlColumns),
                                    'migration_columns_count' => count($migrationColumns)
                                ];
                                $report['status'] = 'error';
                            }
                        }
                    }
                }
                
                foreach ($sqlTables as $sqlTable) {
                    if (!isset($expectedTables[$sqlTable])) {
                        $report['missing_tables_in_migrations'][] = $sqlTable;
                        $report['status'] = 'error';
                    }
                }
            }
            
            // Validar cada migration
            foreach ($expectedTables as $tableName => $migrationFile) {
                $expectedColumns = get_expected_columns_from_migration($migrationFile, $tableName);
                
                if (empty($expectedColumns)) {
                    $report['invalid_migrations'][] = [
                        'table' => $tableName,
                        'migration' => $migrationFile,
                        'error' => 'Nenhuma coluna extraída da migration'
                    ];
                    $report['status'] = 'error';
                } else {
                    $report['valid_migrations']++;
                }
            }
            
        } catch (Exception $e) {
            $report['status'] = 'error';
            $report['error'] = $e->getMessage();
            error_log("Erro ao validar migrations: " . $e->getMessage());
        }
        
        return $report;
    }
}

