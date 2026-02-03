<?php
/**
 * Migration: Criar tabela rate_limits
 * Versão mínima: 1.0.0
 * Cria a tabela de controle de rate limiting
 */

class Migration_20250125_140900_criar_tabela_rate_limits {
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
        $stmt = $pdo->query("SHOW TABLES LIKE 'rate_limits'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("
                CREATE TABLE `rate_limits` (
                    `id` INT(11) NOT NULL AUTO_INCREMENT,
                    `rate_key` VARCHAR(255) NOT NULL,
                    `identifier` VARCHAR(255) DEFAULT NULL,
                    `attempts` INT(11) DEFAULT 1,
                    `first_attempt` DATETIME DEFAULT CURRENT_TIMESTAMP,
                    `last_attempt` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    `blocked_until` DATETIME DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    KEY `idx_key_identifier` (`rate_key`,`identifier`),
                    KEY `idx_last_attempt` (`last_attempt`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
            ");
        }
    }
    
    /**
     * Reverte a migration (rollback)
     * @param PDO $pdo
     */
    public function down($pdo) {
        $pdo->exec("DROP TABLE IF EXISTS `rate_limits`");
    }
}

