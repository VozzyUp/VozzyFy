<?php
/**
 * Migration: Criar tabela starfy_tracking_products
 * Versão mínima: 1.0.0
 * Cria a tabela de produtos rastreados do Starfy (depende de usuarios e produtos)
 */

class Migration_20250125_141700_criar_tabela_starfy_tracking_products {
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
        $stmt = $pdo->query("SHOW TABLES LIKE 'starfy_tracking_products'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("
                CREATE TABLE `starfy_tracking_products` (
                    `id` INT(11) NOT NULL AUTO_INCREMENT,
                    `usuario_id` INT(11) NOT NULL COMMENT 'ID do infoprodutor dono do produto',
                    `produto_id` INT(11) NOT NULL COMMENT 'ID do produto real sendo rastreado',
                    `tracking_id` VARCHAR(64) NOT NULL COMMENT 'ID único para o script de rastreamento',
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `idx_unique_tracking_id` (`tracking_id`),
                    UNIQUE KEY `idx_unique_usuario_produto_rastreado` (`usuario_id`,`produto_id`),
                    KEY `fk_tracking_products_usuario` (`usuario_id`),
                    KEY `fk_tracking_products_produto` (`produto_id`),
                    CONSTRAINT `fk_tracking_products_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE,
                    CONSTRAINT `fk_tracking_products_produto` FOREIGN KEY (`produto_id`) REFERENCES `produtos` (`id`) ON DELETE CASCADE
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
            $pdo->exec("ALTER TABLE `starfy_tracking_products` DROP FOREIGN KEY `fk_tracking_products_usuario`");
            $pdo->exec("ALTER TABLE `starfy_tracking_products` DROP FOREIGN KEY `fk_tracking_products_produto`");
        } catch (PDOException $e) {
            // Ignorar se não existir
        }
        $pdo->exec("DROP TABLE IF EXISTS `starfy_tracking_products`");
    }
}

