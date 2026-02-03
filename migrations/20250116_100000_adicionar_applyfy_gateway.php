<?php
/**
 * Migration: Adicionar suporte ao gateway Applyfy
 * Versão mínima: 1.0.0
 * Adiciona colunas para credenciais do gateway Applyfy na tabela usuarios
 */

class Migration_20250116_100000_adicionar_applyfy_gateway {
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
        // Verificar se as colunas já existem antes de adicionar
        try {
            // Verificar applyfy_public_key
            $stmt = $pdo->query("SHOW COLUMNS FROM `usuarios` LIKE 'applyfy_public_key'");
            if ($stmt->rowCount() == 0) {
                $pdo->exec("ALTER TABLE `usuarios` ADD COLUMN `applyfy_public_key` VARCHAR(255) NULL");
            }
            
            // Verificar applyfy_secret_key
            $stmt = $pdo->query("SHOW COLUMNS FROM `usuarios` LIKE 'applyfy_secret_key'");
            if ($stmt->rowCount() == 0) {
                $pdo->exec("ALTER TABLE `usuarios` ADD COLUMN `applyfy_secret_key` VARCHAR(255) NULL");
            }
        } catch (PDOException $e) {
            error_log("Erro ao adicionar colunas Applyfy: " . $e->getMessage());
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
            $stmt = $pdo->query("SHOW COLUMNS FROM `usuarios` LIKE 'applyfy_public_key'");
            if ($stmt->rowCount() > 0) {
                $pdo->exec("ALTER TABLE `usuarios` DROP COLUMN `applyfy_public_key`");
            }
            
            $stmt = $pdo->query("SHOW COLUMNS FROM `usuarios` LIKE 'applyfy_secret_key'");
            if ($stmt->rowCount() > 0) {
                $pdo->exec("ALTER TABLE `usuarios` DROP COLUMN `applyfy_secret_key`");
            }
        } catch (PDOException $e) {
            error_log("Erro ao reverter migration Applyfy: " . $e->getMessage());
            throw $e;
        }
    }
}

