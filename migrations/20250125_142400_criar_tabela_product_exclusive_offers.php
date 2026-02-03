<?php
/**
 * Migration: Criar tabela product_exclusive_offers
 * Versão mínima: 1.0.0
 * Cria a tabela de ofertas exclusivas de produtos (depende de produtos)
 */

class Migration_20250125_142400_criar_tabela_product_exclusive_offers {
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
        $stmt = $pdo->query("SHOW TABLES LIKE 'product_exclusive_offers'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("
                CREATE TABLE `product_exclusive_offers` (
                    `id` INT(11) NOT NULL AUTO_INCREMENT,
                    `source_product_id` INT(11) NOT NULL COMMENT 'ID do produto que o cliente já possui e que gera a oferta',
                    `offer_product_id` INT(11) NOT NULL COMMENT 'ID do produto (tipo area_membros) ofertado',
                    `is_active` TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Status da oferta: 1=ativo, 0=inativo',
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `idx_unique_product_offer` (`source_product_id`,`offer_product_id`),
                    KEY `fk_offer_source_product` (`source_product_id`),
                    KEY `fk_offer_target_product` (`offer_product_id`),
                    CONSTRAINT `fk_offer_source_product` FOREIGN KEY (`source_product_id`) REFERENCES `produtos` (`id`) ON DELETE CASCADE,
                    CONSTRAINT `fk_offer_target_product` FOREIGN KEY (`offer_product_id`) REFERENCES `produtos` (`id`) ON DELETE CASCADE
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
            $pdo->exec("ALTER TABLE `product_exclusive_offers` DROP FOREIGN KEY `fk_offer_source_product`");
            $pdo->exec("ALTER TABLE `product_exclusive_offers` DROP FOREIGN KEY `fk_offer_target_product`");
        } catch (PDOException $e) {
            // Ignorar se não existir
        }
        $pdo->exec("DROP TABLE IF EXISTS `product_exclusive_offers`");
    }
}

