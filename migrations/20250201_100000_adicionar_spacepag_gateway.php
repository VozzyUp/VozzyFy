<?php
/**
 * Migration: Adicionar suporte ao gateway SpacePag
 * Versão mínima: 1.0.0
 * Adiciona colunas para credenciais do gateway SpacePag na tabela usuarios
 */

class Migration_20250201_100000_adicionar_spacepag_gateway {
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
            $stmt = $pdo->query("SHOW COLUMNS FROM `usuarios` LIKE 'spacepag_public_key'");
            if ($stmt->rowCount() == 0) {
                $pdo->exec("ALTER TABLE `usuarios` ADD COLUMN `spacepag_public_key` VARCHAR(255) NULL");
            }
            
            $stmt = $pdo->query("SHOW COLUMNS FROM `usuarios` LIKE 'spacepag_secret_key'");
            if ($stmt->rowCount() == 0) {
                $pdo->exec("ALTER TABLE `usuarios` ADD COLUMN `spacepag_secret_key` VARCHAR(255) NULL");
            }
        } catch (PDOException $e) {
            error_log("Erro ao adicionar colunas SpacePag: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Reverte a migration (rollback)
     * @param PDO $pdo
     */
    public function down($pdo) {
        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM `usuarios` LIKE 'spacepag_public_key'");
            if ($stmt->rowCount() > 0) {
                $pdo->exec("ALTER TABLE `usuarios` DROP COLUMN `spacepag_public_key`");
            }
            
            $stmt = $pdo->query("SHOW COLUMNS FROM `usuarios` LIKE 'spacepag_secret_key'");
            if ($stmt->rowCount() > 0) {
                $pdo->exec("ALTER TABLE `usuarios` DROP COLUMN `spacepag_secret_key`");
            }
        } catch (PDOException $e) {
            error_log("Erro ao reverter migration SpacePag: " . $e->getMessage());
            throw $e;
        }
    }
}
