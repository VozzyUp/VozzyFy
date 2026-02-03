<?php
/**
 * Migration: Criar tabela pwa_config
 * Versão mínima: 1.0.0
 * Cria a tabela de configuração do PWA (Progressive Web App)
 */

class Migration_20250125_140800_criar_tabela_pwa_config {
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
        $stmt = $pdo->query("SHOW TABLES LIKE 'pwa_config'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("
                CREATE TABLE `pwa_config` (
                    `id` INT(11) NOT NULL AUTO_INCREMENT,
                    `app_name` VARCHAR(255) DEFAULT 'Plataforma',
                    `short_name` VARCHAR(50) DEFAULT 'App',
                    `description` TEXT DEFAULT NULL,
                    `icon_path` VARCHAR(255) DEFAULT NULL,
                    `theme_color` VARCHAR(7) DEFAULT '#32e768',
                    `background_color` VARCHAR(7) DEFAULT '#ffffff',
                    `display_mode` VARCHAR(20) DEFAULT 'standalone',
                    `start_url` VARCHAR(255) DEFAULT '/',
                    `scope` VARCHAR(255) DEFAULT '/',
                    `push_enabled` TINYINT(1) DEFAULT 0,
                    `vapid_public_key` TEXT DEFAULT NULL,
                    `vapid_private_key` TEXT DEFAULT NULL,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
    }
    
    /**
     * Reverte a migration (rollback)
     * @param PDO $pdo
     */
    public function down($pdo) {
        $pdo->exec("DROP TABLE IF EXISTS `pwa_config`");
    }
}

