<?php
/**
 * Migration: Criar tabela saas_planos
 * Versão mínima: 1.0.0
 * Cria a tabela de planos do sistema SaaS
 */

class Migration_20250125_140700_criar_tabela_saas_planos {
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
        $stmt = $pdo->query("SHOW TABLES LIKE 'saas_planos'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("
                CREATE TABLE `saas_planos` (
                    `id` INT(11) NOT NULL AUTO_INCREMENT,
                    `nome` VARCHAR(100) NOT NULL,
                    `descricao` TEXT DEFAULT NULL,
                    `preco` DECIMAL(10,2) DEFAULT 0.00,
                    `periodo` ENUM('mensal','anual') DEFAULT 'mensal',
                    `max_produtos` INT(11) DEFAULT NULL COMMENT 'NULL = ilimitado',
                    `max_pedidos_mes` INT(11) DEFAULT NULL COMMENT 'NULL = ilimitado',
                    `is_free` TINYINT(1) DEFAULT 0,
                    `tracking_enabled` TINYINT(1) DEFAULT 0,
                    `ativo` TINYINT(1) DEFAULT 1,
                    `ordem` INT(11) DEFAULT 0,
                    `criado_em` DATETIME DEFAULT CURRENT_TIMESTAMP,
                    `atualizado_em` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_ativo` (`ativo`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
    }
    
    /**
     * Reverte a migration (rollback)
     * @param PDO $pdo
     */
    public function down($pdo) {
        $pdo->exec("DROP TABLE IF EXISTS `saas_planos`");
    }
}

