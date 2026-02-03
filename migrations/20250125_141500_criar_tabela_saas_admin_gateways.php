<?php
/**
 * Migration: Criar tabela saas_admin_gateways
 * Versão mínima: 1.0.0
 * Cria a tabela de gateways administrativos do SaaS
 */

class Migration_20250125_141500_criar_tabela_saas_admin_gateways {
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
        $stmt = $pdo->query("SHOW TABLES LIKE 'saas_admin_gateways'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("
                CREATE TABLE `saas_admin_gateways` (
                    `id` INT(11) NOT NULL AUTO_INCREMENT,
                    `gateway` VARCHAR(50) NOT NULL,
                    `mp_access_token` VARCHAR(255) DEFAULT NULL,
                    `mp_public_key` VARCHAR(255) DEFAULT NULL,
                    `efi_client_id` VARCHAR(255) DEFAULT NULL,
                    `efi_client_secret` VARCHAR(255) DEFAULT NULL,
                    `efi_certificate_path` VARCHAR(255) DEFAULT NULL,
                    `efi_pix_key` VARCHAR(255) DEFAULT NULL,
                    `efi_payee_code` VARCHAR(255) DEFAULT NULL,
                    `pushinpay_token` VARCHAR(255) DEFAULT NULL,
                    `beehive_secret_key` VARCHAR(255) DEFAULT NULL,
                    `beehive_public_key` VARCHAR(255) DEFAULT NULL,
                    `hypercash_secret_key` VARCHAR(255) DEFAULT NULL,
                    `hypercash_public_key` VARCHAR(255) DEFAULT NULL,
                    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `unique_gateway` (`gateway`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
    }
    
    /**
     * Reverte a migration (rollback)
     * @param PDO $pdo
     */
    public function down($pdo) {
        $pdo->exec("DROP TABLE IF EXISTS `saas_admin_gateways`");
    }
}

