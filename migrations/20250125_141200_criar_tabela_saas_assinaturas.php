<?php
/**
 * Migration: Criar tabela saas_assinaturas
 * Versão mínima: 1.0.0
 * Cria a tabela de assinaturas SaaS (depende de usuarios e saas_planos)
 */

class Migration_20250125_141200_criar_tabela_saas_assinaturas {
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
        $stmt = $pdo->query("SHOW TABLES LIKE 'saas_assinaturas'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("
                CREATE TABLE `saas_assinaturas` (
                    `id` INT(11) NOT NULL AUTO_INCREMENT,
                    `usuario_id` INT(11) NOT NULL,
                    `plano_id` INT(11) NOT NULL,
                    `status` ENUM('ativo','expirado','cancelado','pendente') DEFAULT 'pendente',
                    `data_inicio` DATE NOT NULL,
                    `data_vencimento` DATE NOT NULL,
                    `transacao_id` VARCHAR(255) DEFAULT NULL,
                    `metodo_pagamento` VARCHAR(50) DEFAULT NULL,
                    `gateway` VARCHAR(50) DEFAULT NULL,
                    `renovacao_automatica` TINYINT(1) DEFAULT 1,
                    `notificado_vencimento` TINYINT(1) DEFAULT 0,
                    `notificado_expirado` TINYINT(1) DEFAULT 0,
                    `criado_em` DATETIME DEFAULT CURRENT_TIMESTAMP,
                    `atualizado_em` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `plano_id` (`plano_id`),
                    KEY `idx_usuario_id` (`usuario_id`),
                    KEY `idx_status` (`status`),
                    KEY `idx_data_vencimento` (`data_vencimento`),
                    CONSTRAINT `saas_assinaturas_ibfk_1` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE,
                    CONSTRAINT `saas_assinaturas_ibfk_2` FOREIGN KEY (`plano_id`) REFERENCES `saas_planos` (`id`)
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
            $pdo->exec("ALTER TABLE `saas_assinaturas` DROP FOREIGN KEY `saas_assinaturas_ibfk_1`");
            $pdo->exec("ALTER TABLE `saas_assinaturas` DROP FOREIGN KEY `saas_assinaturas_ibfk_2`");
        } catch (PDOException $e) {
            // Ignorar se não existir
        }
        $pdo->exec("DROP TABLE IF EXISTS `saas_assinaturas`");
    }
}

