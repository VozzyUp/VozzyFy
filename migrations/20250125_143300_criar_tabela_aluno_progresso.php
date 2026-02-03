<?php
/**
 * Migration: Criar tabela aluno_progresso
 * Versão mínima: 1.0.0
 * Cria a tabela de progresso de alunos (depende de aulas)
 */

class Migration_20250125_143300_criar_tabela_aluno_progresso {
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
        $stmt = $pdo->query("SHOW TABLES LIKE 'aluno_progresso'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("
                CREATE TABLE `aluno_progresso` (
                    `id` INT(11) NOT NULL AUTO_INCREMENT,
                    `aluno_email` VARCHAR(255) NOT NULL,
                    `aula_id` INT(11) NOT NULL,
                    `data_conclusao` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `aluno_aula_unico` (`aluno_email`,`aula_id`),
                    KEY `idx_aula_id` (`aula_id`),
                    CONSTRAINT `fk_aluno_progresso_aula` FOREIGN KEY (`aula_id`) REFERENCES `aulas` (`id`) ON DELETE CASCADE
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
            $pdo->exec("ALTER TABLE `aluno_progresso` DROP FOREIGN KEY `fk_aluno_progresso_aula`");
        } catch (PDOException $e) {
            // Ignorar se não existir
        }
        $pdo->exec("DROP TABLE IF EXISTS `aluno_progresso`");
    }
}

