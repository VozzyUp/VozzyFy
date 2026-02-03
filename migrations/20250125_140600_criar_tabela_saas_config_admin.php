<?php
/**
 * Migration: Criar tabela saas_config_admin
 * Versão mínima: 1.0.0
 * Cria a tabela de configuração administrativa do sistema SaaS
 */

class Migration_20250125_140600_criar_tabela_saas_config_admin {
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
        $stmt = $pdo->query("SHOW TABLES LIKE 'saas_config_admin'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("
                CREATE TABLE `saas_config_admin` (
                    `id` INT(11) NOT NULL AUTO_INCREMENT,
                    `mp_access_token` TEXT DEFAULT NULL,
                    `mp_public_key` VARCHAR(255) DEFAULT NULL,
                    `pushinpay_token` TEXT DEFAULT NULL,
                    `ativo` TINYINT(1) DEFAULT 1,
                    `atualizado_em` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    `payment_methods` TEXT DEFAULT NULL COMMENT 'JSON com métodos de pagamento habilitados',
                    PRIMARY KEY (`id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
    }
    
    /**
     * Reverte a migration (rollback)
     * @param PDO $pdo
     */
    public function down($pdo) {
        $pdo->exec("DROP TABLE IF EXISTS `saas_config_admin`");
    }
}

