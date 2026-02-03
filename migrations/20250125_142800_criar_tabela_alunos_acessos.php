<?php
/**
 * Migration: Criar tabela alunos_acessos
 * Versão mínima: 1.0.0
 * Cria a tabela de acessos de alunos (depende de produtos)
 */

class Migration_20250125_142800_criar_tabela_alunos_acessos {
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
        $stmt = $pdo->query("SHOW TABLES LIKE 'alunos_acessos'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("
                CREATE TABLE `alunos_acessos` (
                    `id` INT(11) NOT NULL AUTO_INCREMENT,
                    `aluno_email` VARCHAR(255) NOT NULL,
                    `produto_id` INT(11) NOT NULL,
                    `data_concessao` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `aluno_produto_unico` (`aluno_email`,`produto_id`),
                    KEY `idx_produto_id` (`produto_id`),
                    KEY `idx_aluno_email` (`aluno_email`),
                    CONSTRAINT `fk_alunos_acessos_produto` FOREIGN KEY (`produto_id`) REFERENCES `produtos` (`id`) ON DELETE CASCADE
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
            $pdo->exec("ALTER TABLE `alunos_acessos` DROP FOREIGN KEY `fk_alunos_acessos_produto`");
        } catch (PDOException $e) {
            // Ignorar se não existir
        }
        $pdo->exec("DROP TABLE IF EXISTS `alunos_acessos`");
    }
}

