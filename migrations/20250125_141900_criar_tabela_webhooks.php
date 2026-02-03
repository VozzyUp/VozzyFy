<?php
/**
 * Migration: Criar tabela webhooks
 * Versão mínima: 1.0.0
 * Cria a tabela de webhooks personalizados (depende de usuarios e produtos)
 */

class Migration_20250125_141900_criar_tabela_webhooks {
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
        $stmt = $pdo->query("SHOW TABLES LIKE 'webhooks'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("
                CREATE TABLE `webhooks` (
                    `id` INT(11) NOT NULL AUTO_INCREMENT,
                    `usuario_id` INT(11) NOT NULL COMMENT 'ID do infoprodutor dono do webhook',
                    `produto_id` INT(11) DEFAULT NULL COMMENT 'ID do produto específico que dispara o webhook (NULL para todos os produtos do infoprodutor)',
                    `url` VARCHAR(2048) NOT NULL COMMENT 'URL para onde o webhook será enviado',
                    `event_approved` TINYINT(1) NOT NULL DEFAULT 0,
                    `event_pending` TINYINT(1) NOT NULL DEFAULT 0,
                    `event_rejected` TINYINT(1) NOT NULL DEFAULT 0,
                    `event_refunded` TINYINT(1) NOT NULL DEFAULT 0,
                    `event_charged_back` TINYINT(1) NOT NULL DEFAULT 0,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `fk_webhooks_usuario` (`usuario_id`),
                    KEY `fk_webhooks_produto` (`produto_id`),
                    CONSTRAINT `fk_webhooks_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE,
                    CONSTRAINT `fk_webhooks_produto` FOREIGN KEY (`produto_id`) REFERENCES `produtos` (`id`) ON DELETE CASCADE
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
            $pdo->exec("ALTER TABLE `webhooks` DROP FOREIGN KEY `fk_webhooks_usuario`");
            $pdo->exec("ALTER TABLE `webhooks` DROP FOREIGN KEY `fk_webhooks_produto`");
        } catch (PDOException $e) {
            // Ignorar se não existir
        }
        $pdo->exec("DROP TABLE IF EXISTS `webhooks`");
    }
}

