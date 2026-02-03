<?php
/**
 * Migration: Criar tabela comunidade_categorias (categorias do feed da comunidade)
 * Versão mínima: 1.0.0
 */

class Migration_20250131_100004_criar_tabela_comunidade_categorias {
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
        $stmt = $pdo->query("SHOW TABLES LIKE 'comunidade_categorias'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("
                CREATE TABLE `comunidade_categorias` (
                    `id` INT(11) NOT NULL AUTO_INCREMENT,
                    `curso_id` INT(11) NOT NULL,
                    `nome` VARCHAR(255) NOT NULL,
                    `is_public_posting` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0=só infoprodutor posta, 1=todos podem postar',
                    `ordem` INT(11) NOT NULL DEFAULT 0,
                    PRIMARY KEY (`id`),
                    KEY `idx_comunidade_categorias_curso_id` (`curso_id`),
                    CONSTRAINT `fk_comunidade_categorias_curso` FOREIGN KEY (`curso_id`) REFERENCES `cursos` (`id`) ON DELETE CASCADE
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
            $pdo->exec("ALTER TABLE `comunidade_categorias` DROP FOREIGN KEY `fk_comunidade_categorias_curso`");
        } catch (PDOException $e) {
            // Ignorar
        }
        $pdo->exec("DROP TABLE IF EXISTS `comunidade_categorias`");
    }
}
