<?php
/**
 * Migration: Criar tabela comunidade_posts (posts do feed da comunidade)
 * Versão mínima: 1.0.0
 */

class Migration_20250131_100005_criar_tabela_comunidade_posts {
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
        $stmt = $pdo->query("SHOW TABLES LIKE 'comunidade_posts'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("
                CREATE TABLE `comunidade_posts` (
                    `id` INT(11) NOT NULL AUTO_INCREMENT,
                    `categoria_id` INT(11) NOT NULL,
                    `autor_tipo` ENUM('infoprodutor','aluno') NOT NULL,
                    `autor_id` INT(11) NULL COMMENT 'usuarios.id se infoprodutor',
                    `autor_email` VARCHAR(255) NOT NULL,
                    `autor_nome` VARCHAR(255) NOT NULL,
                    `conteudo` TEXT NOT NULL,
                    `data_criacao` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_comunidade_posts_categoria_id` (`categoria_id`),
                    KEY `idx_comunidade_posts_autor_id` (`autor_id`),
                    CONSTRAINT `fk_comunidade_posts_categoria` FOREIGN KEY (`categoria_id`) REFERENCES `comunidade_categorias` (`id`) ON DELETE CASCADE
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
            $pdo->exec("ALTER TABLE `comunidade_posts` DROP FOREIGN KEY `fk_comunidade_posts_categoria`");
        } catch (PDOException $e) {
            // Ignorar
        }
        $pdo->exec("DROP TABLE IF EXISTS `comunidade_posts`");
    }
}
