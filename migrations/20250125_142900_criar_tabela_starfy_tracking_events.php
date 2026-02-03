<?php
/**
 * Migration: Criar tabela starfy_tracking_events
 * Versão mínima: 1.0.0
 * Cria a tabela de eventos de rastreamento do Starfy (depende de starfy_tracking_products)
 */

class Migration_20250125_142900_criar_tabela_starfy_tracking_events {
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
        $stmt = $pdo->query("SHOW TABLES LIKE 'starfy_tracking_events'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("
                CREATE TABLE `starfy_tracking_events` (
                    `id` INT(11) NOT NULL AUTO_INCREMENT,
                    `tracking_product_id` INT(11) NOT NULL COMMENT 'ID do produto rastreado em starfy_tracking_products',
                    `session_id` VARCHAR(255) NOT NULL COMMENT 'ID único da sessão do usuário',
                    `event_type` VARCHAR(50) NOT NULL COMMENT 'Tipo do evento (page_view, initiate_checkout, purchase)',
                    `event_data` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'Dados adicionais do evento (ex: url, referrer)' CHECK (json_valid(`event_data`)),
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `fk_tracking_events_product` (`tracking_product_id`),
                    KEY `idx_session_id` (`session_id`),
                    KEY `idx_event_type` (`event_type`),
                    KEY `idx_created_at` (`created_at`),
                    CONSTRAINT `fk_tracking_events_product` FOREIGN KEY (`tracking_product_id`) REFERENCES `starfy_tracking_products` (`id`) ON DELETE CASCADE
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
            $pdo->exec("ALTER TABLE `starfy_tracking_events` DROP FOREIGN KEY `fk_tracking_events_product`");
        } catch (PDOException $e) {
            // Ignorar se não existir
        }
        $pdo->exec("DROP TABLE IF EXISTS `starfy_tracking_events`");
    }
}

