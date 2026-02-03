<?php
/**
 * Migration: Criar tabela configuracoes
 * Versão mínima: 1.0.0
 * Cria a tabela de configurações gerais do sistema
 */

class Migration_20250125_140100_criar_tabela_configuracoes {
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
        $stmt = $pdo->query("SHOW TABLES LIKE 'configuracoes'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("
                CREATE TABLE `configuracoes` (
                    `chave` VARCHAR(255) NOT NULL,
                    `valor` TEXT NOT NULL,
                    PRIMARY KEY (`chave`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
            ");
        }
    }
    
    /**
     * Reverte a migration (rollback)
     * @param PDO $pdo
     */
    public function down($pdo) {
        $pdo->exec("DROP TABLE IF EXISTS `configuracoes`");
    }
}

