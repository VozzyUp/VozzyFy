<?php
/**
 * Migration: Criar tabela cloned_site_settings
 * Versão mínima: 1.0.0
 * Cria a tabela de configurações de sites clonados (depende de cloned_sites)
 */

class Migration_20250125_143400_criar_tabela_cloned_site_settings {
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
        $stmt = $pdo->query("SHOW TABLES LIKE 'cloned_site_settings'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("
                CREATE TABLE `cloned_site_settings` (
                    `id` INT(11) NOT NULL AUTO_INCREMENT,
                    `cloned_site_id` INT(11) NOT NULL COMMENT 'ID do site clonado associado',
                    `facebook_pixel_id` VARCHAR(255) DEFAULT NULL COMMENT 'ID do Facebook Pixel',
                    `google_analytics_id` VARCHAR(255) DEFAULT NULL COMMENT 'ID do Google Analytics',
                    `custom_head_scripts` LONGTEXT DEFAULT NULL COMMENT 'Scripts personalizados a serem injetados no <head>',
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `idx_cloned_site_settings_unique` (`cloned_site_id`),
                    KEY `fk_cloned_site_settings_site` (`cloned_site_id`),
                    CONSTRAINT `fk_cloned_site_settings_site` FOREIGN KEY (`cloned_site_id`) REFERENCES `cloned_sites` (`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
            ");
        }
    }
    
    /**
     * Reverte a migration (rollback)
     * @param PDO $pdo
     */
    public function down($pdo) {
        try {
            $pdo->exec("ALTER TABLE `cloned_site_settings` DROP FOREIGN KEY `fk_cloned_site_settings_site`");
        } catch (PDOException $e) {
            // Ignorar se não existir
        }
        $pdo->exec("DROP TABLE IF EXISTS `cloned_site_settings`");
    }
}

