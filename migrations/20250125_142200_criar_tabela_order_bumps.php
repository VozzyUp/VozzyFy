<?php
/**
 * Migration: Criar tabela order_bumps
 * Versão mínima: 1.0.0
 * Cria a tabela de order bumps (depende de produtos)
 */

class Migration_20250125_142200_criar_tabela_order_bumps {
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
        $stmt = $pdo->query("SHOW TABLES LIKE 'order_bumps'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("
                CREATE TABLE `order_bumps` (
                    `id` INT(11) NOT NULL AUTO_INCREMENT,
                    `main_product_id` INT(11) NOT NULL COMMENT 'ID do produto principal (o do checkout)',
                    `offer_product_id` INT(11) NOT NULL COMMENT 'ID do produto que está sendo ofertado',
                    `headline` VARCHAR(255) DEFAULT 'Sim, eu quero aproveitar essa oferta!',
                    `description` TEXT DEFAULT NULL,
                    `ordem` INT(11) NOT NULL DEFAULT 0 COMMENT 'Ordem de exibição no checkout',
                    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                    PRIMARY KEY (`id`),
                    KEY `idx_main_product_id` (`main_product_id`),
                    KEY `fk_order_bumps_offer_product` (`offer_product_id`),
                    CONSTRAINT `fk_order_bumps_main_product` FOREIGN KEY (`main_product_id`) REFERENCES `produtos` (`id`) ON DELETE CASCADE,
                    CONSTRAINT `fk_order_bumps_offer_product` FOREIGN KEY (`offer_product_id`) REFERENCES `produtos` (`id`) ON DELETE CASCADE
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
            $pdo->exec("ALTER TABLE `order_bumps` DROP FOREIGN KEY `fk_order_bumps_main_product`");
            $pdo->exec("ALTER TABLE `order_bumps` DROP FOREIGN KEY `fk_order_bumps_offer_product`");
        } catch (PDOException $e) {
            // Ignorar se não existir
        }
        $pdo->exec("DROP TABLE IF EXISTS `order_bumps`");
    }
}

