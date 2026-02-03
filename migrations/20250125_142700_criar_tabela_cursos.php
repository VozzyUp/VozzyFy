<?php
/**
 * Migration: Criar tabela cursos
 * Versão mínima: 1.0.0
 * Cria a tabela de cursos (depende de produtos)
 */

class Migration_20250125_142700_criar_tabela_cursos {
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
        $stmt = $pdo->query("SHOW TABLES LIKE 'cursos'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("
                CREATE TABLE `cursos` (
                    `id` INT(11) NOT NULL AUTO_INCREMENT,
                    `produto_id` INT(11) NOT NULL,
                    `titulo` VARCHAR(255) NOT NULL,
                    `descricao` TEXT DEFAULT NULL,
                    `imagem_url` VARCHAR(255) DEFAULT NULL,
                    `banner_url` VARCHAR(255) DEFAULT NULL,
                    `data_criacao` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_produto_id_cursos` (`produto_id`),
                    CONSTRAINT `fk_cursos_produto` FOREIGN KEY (`produto_id`) REFERENCES `produtos` (`id`) ON DELETE CASCADE
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
            $pdo->exec("ALTER TABLE `cursos` DROP FOREIGN KEY `fk_cursos_produto`");
        } catch (PDOException $e) {
            // Ignorar se não existir
        }
        $pdo->exec("DROP TABLE IF EXISTS `cursos`");
    }
}

