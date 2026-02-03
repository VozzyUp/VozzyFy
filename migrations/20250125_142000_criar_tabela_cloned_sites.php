<?php
/**
 * Migration: Criar tabela cloned_sites
 * Versão mínima: 1.0.0
 * Cria a tabela de sites clonados (depende de usuarios)
 */

class Migration_20250125_142000_criar_tabela_cloned_sites {
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
        $stmt = $pdo->query("SHOW TABLES LIKE 'cloned_sites'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("
                CREATE TABLE `cloned_sites` (
                    `id` INT(11) NOT NULL AUTO_INCREMENT,
                    `usuario_id` INT(11) NOT NULL COMMENT 'ID do infoprodutor dono do site clonado',
                    `original_url` VARCHAR(2048) NOT NULL COMMENT 'URL do site original que foi clonado',
                    `title` VARCHAR(255) DEFAULT NULL COMMENT 'Título da página clonada',
                    `original_html` LONGTEXT NOT NULL COMMENT 'Conteúdo HTML original da página clonada',
                    `edited_html` LONGTEXT DEFAULT NULL COMMENT 'Conteúdo HTML da página após edição do usuário',
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    `slug` VARCHAR(255) DEFAULT NULL,
                    `status` VARCHAR(20) DEFAULT 'draft',
                    PRIMARY KEY (`id`),
                    KEY `fk_cloned_sites_usuario` (`usuario_id`),
                    CONSTRAINT `fk_cloned_sites_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
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
            $pdo->exec("ALTER TABLE `cloned_sites` DROP FOREIGN KEY `fk_cloned_sites_usuario`");
        } catch (PDOException $e) {
            // Ignorar se não existir
        }
        $pdo->exec("DROP TABLE IF EXISTS `cloned_sites`");
    }
}

