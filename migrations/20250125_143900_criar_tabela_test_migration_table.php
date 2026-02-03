<?php
/**
 * Migration: Criar tabela test_migration_table
 * Versão mínima: 1.0.0
 * Cria a tabela de teste para migrations
 * Nota: Esta é uma tabela de teste, pode ser removida em produção
 */

class Migration_20250125_143900_criar_tabela_test_migration_table {
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
        $stmt = $pdo->query("SHOW TABLES LIKE 'test_migration_table'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("
                CREATE TABLE `test_migration_table` (
                    `id` INT(11) NOT NULL AUTO_INCREMENT,
                    `nome` VARCHAR(255) NOT NULL,
                    `descricao` TEXT DEFAULT NULL,
                    `valor` DECIMAL(10,2) DEFAULT 0.00,
                    `ativo` TINYINT(1) DEFAULT 1,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_ativo` (`ativo`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
    }
    
    /**
     * Reverte a migration (rollback)
     * @param PDO $pdo
     */
    public function down($pdo) {
        $pdo->exec("DROP TABLE IF EXISTS `test_migration_table`");
    }
}

