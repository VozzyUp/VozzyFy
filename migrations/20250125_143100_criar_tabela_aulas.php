<?php
/**
 * Migration: Criar tabela aulas
 * Versão mínima: 1.0.0
 * Cria a tabela de aulas (depende de modulos)
 */

class Migration_20250125_143100_criar_tabela_aulas {
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
        $stmt = $pdo->query("SHOW TABLES LIKE 'aulas'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("
                CREATE TABLE `aulas` (
                    `id` INT(11) NOT NULL AUTO_INCREMENT,
                    `modulo_id` INT(11) NOT NULL,
                    `titulo` VARCHAR(255) NOT NULL,
                    `url_video` VARCHAR(255) DEFAULT NULL COMMENT 'URL do vídeo (YouTube, Vimeo, etc.), pode ser NULL',
                    `descricao` TEXT DEFAULT NULL,
                    `ordem` INT(11) NOT NULL DEFAULT 0,
                    `release_days` INT(11) NOT NULL DEFAULT 0 COMMENT 'Número de dias após a compra para a aula ser liberada',
                    `tipo_conteudo` ENUM('video','files','mixed') NOT NULL DEFAULT 'video' COMMENT 'Tipo de conteúdo da aula: video, files ou mixed',
                    PRIMARY KEY (`id`),
                    KEY `idx_modulo_id` (`modulo_id`),
                    CONSTRAINT `fk_aulas_modulo` FOREIGN KEY (`modulo_id`) REFERENCES `modulos` (`id`) ON DELETE CASCADE
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
            $pdo->exec("ALTER TABLE `aulas` DROP FOREIGN KEY `fk_aulas_modulo`");
        } catch (PDOException $e) {
            // Ignorar se não existir
        }
        $pdo->exec("DROP TABLE IF EXISTS `aulas`");
    }
}

