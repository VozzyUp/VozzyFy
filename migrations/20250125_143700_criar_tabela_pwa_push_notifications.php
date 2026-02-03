<?php
/**
 * Migration: Criar tabela pwa_push_notifications
 * Versão mínima: 1.0.0
 * Cria a tabela de notificações push do PWA (depende de usuarios)
 */

class Migration_20250125_143700_criar_tabela_pwa_push_notifications {
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
        $stmt = $pdo->query("SHOW TABLES LIKE 'pwa_push_notifications'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("
                CREATE TABLE `pwa_push_notifications` (
                    `id` INT(11) NOT NULL AUTO_INCREMENT,
                    `title` VARCHAR(255) NOT NULL,
                    `message` TEXT NOT NULL,
                    `url` VARCHAR(500) DEFAULT NULL,
                    `icon` VARCHAR(500) DEFAULT NULL,
                    `sent_count` INT(11) DEFAULT 0,
                    `failed_count` INT(11) DEFAULT 0,
                    `created_by` INT(11) DEFAULT NULL,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_created_at` (`created_at`),
                    KEY `created_by` (`created_by`),
                    CONSTRAINT `pwa_push_notifications_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `usuarios` (`id`) ON DELETE SET NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
    }
    
    /**
     * Reverte a migration (rollback)
     * @param PDO $pdo
     */
    public function down($pdo) {
        try {
            $pdo->exec("ALTER TABLE `pwa_push_notifications` DROP FOREIGN KEY `pwa_push_notifications_ibfk_1`");
        } catch (PDOException $e) {
            // Ignorar se não existir
        }
        $pdo->exec("DROP TABLE IF EXISTS `pwa_push_notifications`");
    }
}

