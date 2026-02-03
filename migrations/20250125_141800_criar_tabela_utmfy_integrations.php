<?php
/**
 * Migration: Criar tabela utmfy_integrations
 * Versão mínima: 1.0.0
 * Cria a tabela de integrações UTMfy (depende de usuarios e produtos)
 */

class Migration_20250125_141800_criar_tabela_utmfy_integrations {
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
        $stmt = $pdo->query("SHOW TABLES LIKE 'utmfy_integrations'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("
                CREATE TABLE `utmfy_integrations` (
                    `id` INT(11) NOT NULL AUTO_INCREMENT,
                    `usuario_id` INT(11) NOT NULL COMMENT 'ID do infoprodutor dono da integração',
                    `name` VARCHAR(255) NOT NULL COMMENT 'Nome amigável da integração (ex: Campanha de Lançamento X)',
                    `api_token` VARCHAR(255) NOT NULL COMMENT 'API Token fornecido pela UTMfy',
                    `product_id` INT(11) DEFAULT NULL COMMENT 'ID do produto específico que dispara a notificação (NULL para todos os produtos do infoprodutor)',
                    `event_approved` TINYINT(1) NOT NULL DEFAULT 0,
                    `event_pending` TINYINT(1) NOT NULL DEFAULT 0,
                    `event_rejected` TINYINT(1) NOT NULL DEFAULT 0,
                    `event_refunded` TINYINT(1) NOT NULL DEFAULT 0,
                    `event_charged_back` TINYINT(1) NOT NULL DEFAULT 0,
                    `event_initiate_checkout` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Disparar evento ao iniciar checkout',
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `fk_utmfy_integrations_usuario` (`usuario_id`),
                    KEY `fk_utmfy_integrations_produto` (`product_id`),
                    CONSTRAINT `fk_utmfy_integrations_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE,
                    CONSTRAINT `fk_utmfy_integrations_produto` FOREIGN KEY (`product_id`) REFERENCES `produtos` (`id`) ON DELETE CASCADE
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
            $pdo->exec("ALTER TABLE `utmfy_integrations` DROP FOREIGN KEY `fk_utmfy_integrations_usuario`");
            $pdo->exec("ALTER TABLE `utmfy_integrations` DROP FOREIGN KEY `fk_utmfy_integrations_produto`");
        } catch (PDOException $e) {
            // Ignorar se não existir
        }
        $pdo->exec("DROP TABLE IF EXISTS `utmfy_integrations`");
    }
}

