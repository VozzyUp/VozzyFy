<?php
/**
 * Migration: Adicionar allow_comments em cursos e criar tabela aula_comentarios
 * Versão mínima: 1.0.0
 */

class Migration_20250131_100002_cursos_comentarios_e_aula_comentarios {
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
        // 1. Adicionar allow_comments em cursos
        $stmt = $pdo->query("SHOW COLUMNS FROM cursos LIKE 'allow_comments'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("ALTER TABLE `cursos` ADD COLUMN `allow_comments` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0=desabilitado, 1=permitir comentários nas aulas' AFTER `banner_url`");
        }

        // 2. Criar tabela aula_comentarios
        $stmt = $pdo->query("SHOW TABLES LIKE 'aula_comentarios'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("
                CREATE TABLE `aula_comentarios` (
                    `id` INT(11) NOT NULL AUTO_INCREMENT,
                    `aula_id` INT(11) NOT NULL,
                    `aluno_email` VARCHAR(255) NOT NULL,
                    `autor_nome` VARCHAR(255) NOT NULL,
                    `texto` TEXT NOT NULL,
                    `data_criacao` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `aprovado` TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0=pendente moderação, 1=aprovado',
                    PRIMARY KEY (`id`),
                    KEY `idx_aula_comentarios_aula_id` (`aula_id`),
                    CONSTRAINT `fk_aula_comentarios_aula` FOREIGN KEY (`aula_id`) REFERENCES `aulas` (`id`) ON DELETE CASCADE
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
            $pdo->exec("ALTER TABLE `aula_comentarios` DROP FOREIGN KEY `fk_aula_comentarios_aula`");
        } catch (PDOException $e) {
            // Ignorar
        }
        $pdo->exec("DROP TABLE IF EXISTS `aula_comentarios`");
        try {
            $pdo->exec("ALTER TABLE `cursos` DROP COLUMN `allow_comments`");
        } catch (PDOException $e) {
            // Ignorar
        }
    }
}
