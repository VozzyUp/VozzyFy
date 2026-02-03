<?php
/**
 * Migration: Adicionar suporte ao gateway Asaas
 * Versão mínima: 1.0.0
 * Adiciona colunas na tabela usuarios para armazenar credenciais do Asaas
 */

class Migration_20250116_120000_asaas_migration {
    /**
     * Versão mínima do sistema requerida para executar esta migration
     * @return string
     */
    public function getVersion() {
        return '1.0.0';
    }
    
    /**
     * Executa a migration (aplicar mudanças)
     * @param PDO $pdo
     */
    public function up($pdo) {
        try {
            // Verificar e adicionar asaas_api_key se não existir
            $stmt = $pdo->query("SHOW COLUMNS FROM `usuarios` LIKE 'asaas_api_key'");
            if ($stmt->rowCount() == 0) {
                $pdo->exec("ALTER TABLE `usuarios` ADD COLUMN `asaas_api_key` VARCHAR(255) NULL COMMENT 'Chave de API do Asaas'");
            }
            
            // Verificar e adicionar asaas_environment se não existir
            $stmt = $pdo->query("SHOW COLUMNS FROM `usuarios` LIKE 'asaas_environment'");
            if ($stmt->rowCount() == 0) {
                $pdo->exec("ALTER TABLE `usuarios` ADD COLUMN `asaas_environment` ENUM('production', 'sandbox') DEFAULT 'sandbox' COMMENT 'Ambiente do Asaas (produção ou sandbox)'");
            }
        } catch (PDOException $e) {
            error_log("Erro ao adicionar colunas Asaas: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Reverte a migration (rollback)
     * @param PDO $pdo
     */
    public function down($pdo) {
        try {
            // Remover colunas se existirem
            $stmt = $pdo->query("SHOW COLUMNS FROM `usuarios` LIKE 'asaas_api_key'");
            if ($stmt->rowCount() > 0) {
                $pdo->exec("ALTER TABLE `usuarios` DROP COLUMN `asaas_api_key`");
            }
            
            $stmt = $pdo->query("SHOW COLUMNS FROM `usuarios` LIKE 'asaas_environment'");
            if ($stmt->rowCount() > 0) {
                $pdo->exec("ALTER TABLE `usuarios` DROP COLUMN `asaas_environment`");
            }
        } catch (PDOException $e) {
            error_log("Erro ao reverter migration Asaas: " . $e->getMessage());
            throw $e;
        }
    }
}

