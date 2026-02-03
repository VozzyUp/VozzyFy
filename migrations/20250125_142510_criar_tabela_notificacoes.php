<?php
/**
 * Migration: Criar tabela notificacoes
 * Versão mínima: 1.0.0
 * Cria a tabela de notificações (depende de usuarios e vendas)
 */

class Migration_20250125_142510_criar_tabela_notificacoes {
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
        $stmt = $pdo->query("SHOW TABLES LIKE 'notificacoes'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("
                CREATE TABLE `notificacoes` (
                    `id` INT(11) NOT NULL AUTO_INCREMENT,
                    `usuario_id` INT(11) NOT NULL COMMENT 'ID do infoprodutor que deve receber a notificação',
                    `tipo` VARCHAR(50) NOT NULL COMMENT 'Tipo de evento (ex: Compra Aprovada, Pix Gerado, Boleto Pago)',
                    `mensagem` TEXT NOT NULL COMMENT 'Mensagem completa da notificação',
                    `valor` DECIMAL(10,2) DEFAULT NULL COMMENT 'Valor associado à notificação (ex: valor da venda)',
                    `data_notificacao` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `lida` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0 para não lida, 1 para lida',
                    `link_acao` VARCHAR(255) DEFAULT NULL COMMENT 'Link opcional para detalhes da venda',
                    `displayed_live` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0 para não exibida ao vivo, 1 para já exibida ao vivo',
                    `venda_id_fk` INT(11) DEFAULT NULL COMMENT 'Chave estrangeira para a tabela de vendas',
                    `metodo_pagamento` VARCHAR(50) DEFAULT NULL COMMENT 'Método de pagamento da venda associada, para notificação live',
                    PRIMARY KEY (`id`),
                    KEY `idx_usuario_id_notificacoes` (`usuario_id`),
                    KEY `idx_lida_data_notificacao` (`lida`,`data_notificacao`),
                    KEY `fk_notificacoes_venda` (`venda_id_fk`),
                    CONSTRAINT `fk_notificacoes_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE,
                    CONSTRAINT `fk_notificacoes_venda` FOREIGN KEY (`venda_id_fk`) REFERENCES `vendas` (`id`) ON DELETE SET NULL
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
            $pdo->exec("ALTER TABLE `notificacoes` DROP FOREIGN KEY `fk_notificacoes_usuario`");
            $pdo->exec("ALTER TABLE `notificacoes` DROP FOREIGN KEY `fk_notificacoes_venda`");
        } catch (PDOException $e) {
            // Ignorar se não existir
        }
        $pdo->exec("DROP TABLE IF EXISTS `notificacoes`");
    }
}

