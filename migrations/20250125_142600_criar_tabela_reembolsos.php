<?php
/**
 * Migration: Criar tabela reembolsos
 * Versão mínima: 1.0.0
 * Cria a tabela de reembolsos (depende de vendas, produtos e usuarios)
 */

class Migration_20250125_142600_criar_tabela_reembolsos {
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
        $stmt = $pdo->query("SHOW TABLES LIKE 'reembolsos'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("
                CREATE TABLE `reembolsos` (
                    `id` INT(11) NOT NULL AUTO_INCREMENT,
                    `venda_id` INT(11) NOT NULL,
                    `produto_id` INT(11) NOT NULL,
                    `comprador_email` VARCHAR(255) NOT NULL,
                    `comprador_nome` VARCHAR(255) NOT NULL,
                    `valor` DECIMAL(10,2) NOT NULL,
                    `motivo` TEXT DEFAULT NULL,
                    `status` ENUM('pending','approved','rejected') DEFAULT 'pending',
                    `mensagem_infoprodutor` TEXT DEFAULT NULL,
                    `data_solicitacao` DATETIME NOT NULL,
                    `data_resposta` DATETIME DEFAULT NULL,
                    `usuario_id` INT(11) NOT NULL COMMENT 'ID do infoprodutor',
                    `transacao_id` VARCHAR(255) NOT NULL,
                    `metodo_pagamento` VARCHAR(50) DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    KEY `idx_venda_id` (`venda_id`),
                    KEY `idx_produto_id` (`produto_id`),
                    KEY `idx_status` (`status`),
                    KEY `idx_usuario_id` (`usuario_id`),
                    KEY `idx_comprador_email` (`comprador_email`),
                    CONSTRAINT `reembolsos_ibfk_1` FOREIGN KEY (`venda_id`) REFERENCES `vendas` (`id`) ON DELETE CASCADE,
                    CONSTRAINT `reembolsos_ibfk_2` FOREIGN KEY (`produto_id`) REFERENCES `produtos` (`id`) ON DELETE CASCADE,
                    CONSTRAINT `reembolsos_ibfk_3` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
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
            $pdo->exec("ALTER TABLE `reembolsos` DROP FOREIGN KEY `reembolsos_ibfk_1`");
            $pdo->exec("ALTER TABLE `reembolsos` DROP FOREIGN KEY `reembolsos_ibfk_2`");
            $pdo->exec("ALTER TABLE `reembolsos` DROP FOREIGN KEY `reembolsos_ibfk_3`");
        } catch (PDOException $e) {
            // Ignorar se não existir
        }
        $pdo->exec("DROP TABLE IF EXISTS `reembolsos`");
    }
}

