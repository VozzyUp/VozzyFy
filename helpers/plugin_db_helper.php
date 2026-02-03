<?php
/**
 * Helper de Banco de Dados para Plugins
 * 
 * Funções seguras para plugins acessarem o banco de dados
 * usando prepared statements obrigatórios
 */

if (!function_exists('plugin_db_query')) {
    /**
     * Executa uma query SQL segura usando prepared statements
     * 
     * @param string $query SQL query com placeholders (?, :nome)
     * @param array $params Parâmetros para bind (opcional)
     * @return PDOStatement|false Statement preparado ou false em caso de erro
     */
    function plugin_db_query($query, $params = []) {
        global $pdo;
        
        if (!$pdo) {
            error_log("plugin_db_query: PDO não está disponível");
            return false;
        }
        
        try {
            $stmt = $pdo->prepare($query);
            if ($stmt === false) {
                error_log("plugin_db_query: Erro ao preparar query: " . implode(', ', $pdo->errorInfo()));
                return false;
            }
            
            if (!empty($params)) {
                $result = $stmt->execute($params);
                if (!$result) {
                    error_log("plugin_db_query: Erro ao executar query: " . implode(', ', $stmt->errorInfo()));
                    return false;
                }
            }
            
            return $stmt;
        } catch (PDOException $e) {
            error_log("plugin_db_query: Exceção PDO: " . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('plugin_get_option')) {
    /**
     * Obtém uma opção/configuração de um plugin
     * 
     * @param string $plugin_name Nome do plugin (pasta)
     * @param string $option_key Chave da opção
     * @param mixed $default Valor padrão se não existir
     * @return mixed Valor da opção ou $default
     */
    function plugin_get_option($plugin_name, $option_key, $default = null) {
        global $pdo;
        
        if (!$pdo) {
            return $default;
        }
        
        try {
            // Verificar se a tabela existe
            $stmt_check = $pdo->query("SHOW TABLES LIKE 'plugin_options'");
            if ($stmt_check->rowCount() === 0) {
                // Tabela não existe, criar se possível
                $create_table_sql = "
                    CREATE TABLE IF NOT EXISTS plugin_options (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        plugin_name VARCHAR(255) NOT NULL,
                        option_key VARCHAR(255) NOT NULL,
                        option_value TEXT,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        UNIQUE KEY unique_plugin_option (plugin_name, option_key),
                        INDEX idx_plugin_name (plugin_name)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                ";
                try {
                    $pdo->exec($create_table_sql);
                } catch (PDOException $e) {
                    error_log("plugin_get_option: Erro ao criar tabela plugin_options: " . $e->getMessage());
                    return $default;
                }
            }
            
            $stmt = $pdo->prepare("SELECT option_value FROM plugin_options WHERE plugin_name = ? AND option_key = ?");
            $stmt->execute([$plugin_name, $option_key]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result && isset($result['option_value'])) {
                // Tentar deserializar JSON, se não for JSON válido, retorna como string
                $value = json_decode($result['option_value'], true);
                return json_last_error() === JSON_ERROR_NONE ? $value : $result['option_value'];
            }
            
            return $default;
        } catch (PDOException $e) {
            error_log("plugin_get_option: Erro ao buscar opção: " . $e->getMessage());
            return $default;
        }
    }
}

if (!function_exists('plugin_set_option')) {
    /**
     * Salva uma opção/configuração de um plugin
     * 
     * @param string $plugin_name Nome do plugin (pasta)
     * @param string $option_key Chave da opção
     * @param mixed $option_value Valor da opção (será serializado como JSON se não for string)
     * @return bool Sucesso ou falha
     */
    function plugin_set_option($plugin_name, $option_key, $option_value) {
        global $pdo;
        
        if (!$pdo) {
            error_log("plugin_set_option: PDO não está disponível");
            return false;
        }
        
        try {
            // Verificar se a tabela existe
            $stmt_check = $pdo->query("SHOW TABLES LIKE 'plugin_options'");
            if ($stmt_check->rowCount() === 0) {
                // Tabela não existe, criar
                $create_table_sql = "
                    CREATE TABLE IF NOT EXISTS plugin_options (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        plugin_name VARCHAR(255) NOT NULL,
                        option_key VARCHAR(255) NOT NULL,
                        option_value TEXT,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        UNIQUE KEY unique_plugin_option (plugin_name, option_key),
                        INDEX idx_plugin_name (plugin_name)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
                ";
                try {
                    $pdo->exec($create_table_sql);
                } catch (PDOException $e) {
                    error_log("plugin_set_option: Erro ao criar tabela plugin_options: " . $e->getMessage());
                    return false;
                }
            }
            
            // Serializar valor como JSON se não for string
            $serialized_value = is_string($option_value) ? $option_value : json_encode($option_value);
            
            $stmt = $pdo->prepare("
                INSERT INTO plugin_options (plugin_name, option_key, option_value) 
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE option_value = VALUES(option_value), updated_at = CURRENT_TIMESTAMP
            ");
            
            $result = $stmt->execute([$plugin_name, $option_key, $serialized_value]);
            
            return $result;
        } catch (PDOException $e) {
            error_log("plugin_set_option: Erro ao salvar opção: " . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('plugin_delete_option')) {
    /**
     * Deleta uma opção/configuração de um plugin
     * 
     * @param string $plugin_name Nome do plugin (pasta)
     * @param string $option_key Chave da opção
     * @return bool Sucesso ou falha
     */
    function plugin_delete_option($plugin_name, $option_key) {
        global $pdo;
        
        if (!$pdo) {
            return false;
        }
        
        try {
            $stmt = $pdo->prepare("DELETE FROM plugin_options WHERE plugin_name = ? AND option_key = ?");
            return $stmt->execute([$plugin_name, $option_key]);
        } catch (PDOException $e) {
            error_log("plugin_delete_option: Erro ao deletar opção: " . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('plugin_delete_all_options')) {
    /**
     * Deleta todas as opções de um plugin
     * 
     * @param string $plugin_name Nome do plugin (pasta)
     * @return bool Sucesso ou falha
     */
    function plugin_delete_all_options($plugin_name) {
        global $pdo;
        
        if (!$pdo) {
            return false;
        }
        
        try {
            $stmt = $pdo->prepare("DELETE FROM plugin_options WHERE plugin_name = ?");
            return $stmt->execute([$plugin_name]);
        } catch (PDOException $e) {
            error_log("plugin_delete_all_options: Erro ao deletar opções: " . $e->getMessage());
            return false;
        }
    }
}
?>

