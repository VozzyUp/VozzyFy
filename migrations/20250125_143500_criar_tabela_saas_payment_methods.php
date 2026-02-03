<?php
/**
 * Migration: Criar tabela saas_payment_methods
 * Versão mínima: 1.0.0
 * Cria a tabela de métodos de pagamento do SaaS
 */

class Migration_20250125_143500_criar_tabela_saas_payment_methods {
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
        $stmt = $pdo->query("SHOW TABLES LIKE 'saas_payment_methods'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("
                CREATE TABLE `saas_payment_methods` (
                    `id` INT(11) NOT NULL AUTO_INCREMENT,
                    `payment_method` ENUM('pix','credit_card') NOT NULL,
                    `gateway` VARCHAR(50) NOT NULL,
                    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `unique_payment_method` (`payment_method`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
    }
    
    /**
     * Reverte a migration (rollback)
     * @param PDO $pdo
     */
    public function down($pdo) {
        $pdo->exec("DROP TABLE IF EXISTS `saas_payment_methods`");
    }
}

