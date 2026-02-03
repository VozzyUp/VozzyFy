<?php
/**
 * Migration: Criar tabela schema_migrations
 * Versão mínima: 1.0.0
 * Cria a tabela de controle de migrations do sistema
 * Nota: Esta tabela já é criada pelo helper, mas esta migration garante que exista
 */

class Migration_20250125_143800_criar_tabela_schema_migrations {
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
        $stmt = $pdo->query("SHOW TABLES LIKE 'schema_migrations'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("
                CREATE TABLE `schema_migrations` (
                    `id` INT(11) NOT NULL AUTO_INCREMENT,
                    `migration_file` VARCHAR(255) NOT NULL,
                    `version` VARCHAR(20) NOT NULL,
                    `executed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `migration_file` (`migration_file`),
                    KEY `idx_version` (`version`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
    }
    
    /**
     * Reverte a migration (rollback)
     * @param PDO $pdo
     */
    public function down($pdo) {
        $pdo->exec("DROP TABLE IF EXISTS `schema_migrations`");
    }
}

