<?php
/**
 * Migration: Criar tabela produto_ofertas
 * Versão mínima: 1.0.0
 * Cria a tabela de ofertas de produtos (depende de produtos)
 */

class Migration_20250125_142300_criar_tabela_produto_ofertas {
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
        $stmt = $pdo->query("SHOW TABLES LIKE 'produto_ofertas'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("
                CREATE TABLE `produto_ofertas` (
                    `id` INT(11) NOT NULL AUTO_INCREMENT,
                    `produto_id` INT(11) NOT NULL COMMENT 'ID do produto original',
                    `nome` VARCHAR(255) NOT NULL COMMENT 'Nome da oferta',
                    `preco` DECIMAL(10,2) NOT NULL COMMENT 'Preço específico da oferta',
                    `checkout_hash` VARCHAR(255) NOT NULL COMMENT 'Hash único para o checkout desta oferta',
                    `is_active` TINYINT(1) DEFAULT 1 COMMENT 'Status: 1=ativo, 0=inativo',
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `unique_checkout_hash` (`checkout_hash`),
                    KEY `idx_produto_id` (`produto_id`),
                    CONSTRAINT `fk_produto_ofertas_produto` FOREIGN KEY (`produto_id`) REFERENCES `produtos` (`id`) ON DELETE CASCADE
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
            $pdo->exec("ALTER TABLE `produto_ofertas` DROP FOREIGN KEY `fk_produto_ofertas_produto`");
        } catch (PDOException $e) {
            // Ignorar se não existir
        }
        $pdo->exec("DROP TABLE IF EXISTS `produto_ofertas`");
    }
}

