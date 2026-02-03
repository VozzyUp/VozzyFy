<?php
/**
 * Migration: Criar tabela vendas
 * Versão mínima: 1.0.0
 * Cria a tabela de vendas (depende de produtos)
 */

class Migration_20250125_142500_criar_tabela_vendas {
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
        $stmt = $pdo->query("SHOW TABLES LIKE 'vendas'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("
                CREATE TABLE `vendas` (
                    `id` INT(11) NOT NULL AUTO_INCREMENT,
                    `produto_id` INT(11) NOT NULL,
                    `valor` DECIMAL(10,2) NOT NULL,
                    `status_pagamento` VARCHAR(50) NOT NULL,
                    `data_venda` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `comprador_email` VARCHAR(255) DEFAULT NULL,
                    `comprador_nome` VARCHAR(255) DEFAULT NULL,
                    `comprador_cpf` VARCHAR(20) DEFAULT NULL,
                    `comprador_telefone` VARCHAR(20) DEFAULT NULL,
                    `comprador_cep` VARCHAR(10) DEFAULT NULL,
                    `comprador_logradouro` VARCHAR(255) DEFAULT NULL,
                    `comprador_numero` VARCHAR(20) DEFAULT NULL,
                    `comprador_complemento` VARCHAR(100) DEFAULT NULL,
                    `comprador_bairro` VARCHAR(100) DEFAULT NULL,
                    `comprador_cidade` VARCHAR(100) DEFAULT NULL,
                    `comprador_estado` VARCHAR(2) DEFAULT NULL,
                    `transacao_id` VARCHAR(255) DEFAULT NULL,
                    `metodo_pagamento` VARCHAR(50) DEFAULT NULL,
                    `checkout_session_uuid` VARCHAR(255) DEFAULT NULL COMMENT 'UUID para agrupar vendas de um mesmo checkout (principal + order bumps)',
                    `email_entrega_enviado` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0 = Não enviado, 1 = Enviado',
                    `email_recovery_sent` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0 = Email de recuperação não enviado, 1 = Email de recuperação enviado',
                    `email_reenviado_manual` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0 = Não reenviado manualmente, 1 = Reenviado manualmente pelo cliente',
                    `utm_source` VARCHAR(255) DEFAULT NULL,
                    `utm_campaign` VARCHAR(255) DEFAULT NULL,
                    `utm_medium` VARCHAR(255) DEFAULT NULL,
                    `utm_content` VARCHAR(255) DEFAULT NULL,
                    `utm_term` VARCHAR(255) DEFAULT NULL,
                    `src` VARCHAR(255) DEFAULT NULL,
                    `sck` VARCHAR(255) DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    KEY `idx_produto_id_vendas` (`produto_id`),
                    KEY `idx_checkout_session_uuid` (`checkout_session_uuid`),
                    CONSTRAINT `fk_vendas_produto` FOREIGN KEY (`produto_id`) REFERENCES `produtos` (`id`) ON DELETE CASCADE
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
            $pdo->exec("ALTER TABLE `vendas` DROP FOREIGN KEY `fk_vendas_produto`");
        } catch (PDOException $e) {
            // Ignorar se não existir
        }
        $pdo->exec("DROP TABLE IF EXISTS `vendas`");
    }
}

