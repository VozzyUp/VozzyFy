<?php
/**
 * Migration: Criar tabela configuracoes_sistema
 * Versão mínima: 1.0.0
 * Cria a tabela de configurações do sistema
 */

class Migration_20250125_140200_criar_tabela_configuracoes_sistema {
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
        $stmt = $pdo->query("SHOW TABLES LIKE 'configuracoes_sistema'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("
                CREATE TABLE `configuracoes_sistema` (
                    `id` INT(11) NOT NULL AUTO_INCREMENT,
                    `chave` VARCHAR(100) NOT NULL,
                    `valor` TEXT DEFAULT NULL,
                    `tipo` VARCHAR(50) DEFAULT 'text',
                    `descricao` TEXT DEFAULT NULL,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `chave` (`chave`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
    }
    
    /**
     * Reverte a migration (rollback)
     * @param PDO $pdo
     */
    public function down($pdo) {
        $pdo->exec("DROP TABLE IF EXISTS `configuracoes_sistema`");
    }
}

