<?php
/**
 * Migration: Criar tabela email_queue
 * Versão mínima: 1.0.0
 * Cria a tabela de fila de emails
 */

class Migration_20250125_141000_criar_tabela_email_queue {
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
        $stmt = $pdo->query("SHOW TABLES LIKE 'email_queue'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("
                CREATE TABLE `email_queue` (
                    `id` INT(11) NOT NULL AUTO_INCREMENT,
                    `recipient_email` VARCHAR(255) NOT NULL,
                    `recipient_name` VARCHAR(255) DEFAULT NULL,
                    `subject` VARCHAR(500) NOT NULL,
                    `body` TEXT NOT NULL,
                    `status` ENUM('pending','processing','sent','failed') DEFAULT 'pending',
                    `attempts` INT(11) DEFAULT 0,
                    `error_message` TEXT DEFAULT NULL,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `sent_at` TIMESTAMP NULL DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    KEY `idx_status` (`status`),
                    KEY `idx_created_at` (`created_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
            ");
        }
    }
    
    /**
     * Reverte a migration (rollback)
     * @param PDO $pdo
     */
    public function down($pdo) {
        $pdo->exec("DROP TABLE IF EXISTS `email_queue`");
    }
}

