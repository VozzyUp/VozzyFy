<?php
/**
 * Migration: Criar tabela pwa_push_subscriptions
 * Versão mínima: 1.0.0
 * Cria a tabela de assinaturas push do PWA (depende de usuarios)
 */

class Migration_20250125_141600_criar_tabela_pwa_push_subscriptions {
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
        $stmt = $pdo->query("SHOW TABLES LIKE 'pwa_push_subscriptions'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("
                CREATE TABLE `pwa_push_subscriptions` (
                    `id` INT(11) NOT NULL AUTO_INCREMENT,
                    `usuario_id` INT(11) NOT NULL,
                    `endpoint` TEXT NOT NULL,
                    `p256dh` TEXT NOT NULL,
                    `auth` TEXT NOT NULL,
                    `user_agent` VARCHAR(500) DEFAULT NULL,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_usuario_id` (`usuario_id`),
                    KEY `idx_endpoint` (`endpoint`(255)),
                    CONSTRAINT `pwa_push_subscriptions_ibfk_1` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
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
            $pdo->exec("ALTER TABLE `pwa_push_subscriptions` DROP FOREIGN KEY `pwa_push_subscriptions_ibfk_1`");
        } catch (PDOException $e) {
            // Ignorar se não existir
        }
        $pdo->exec("DROP TABLE IF EXISTS `pwa_push_subscriptions`");
    }
}

