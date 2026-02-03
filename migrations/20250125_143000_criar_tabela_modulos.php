<?php
/**
 * Migration: Criar tabela modulos
 * Versão mínima: 1.0.0
 * Cria a tabela de módulos (depende de cursos e produtos)
 */

class Migration_20250125_143000_criar_tabela_modulos {
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
        $stmt = $pdo->query("SHOW TABLES LIKE 'modulos'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("
                CREATE TABLE `modulos` (
                    `id` INT(11) NOT NULL AUTO_INCREMENT,
                    `curso_id` INT(11) NOT NULL,
                    `titulo` VARCHAR(255) NOT NULL,
                    `imagem_capa_url` VARCHAR(255) DEFAULT NULL,
                    `ordem` INT(11) NOT NULL DEFAULT 0,
                    `release_days` INT(11) NOT NULL DEFAULT 0 COMMENT 'Número de dias após a compra para o módulo ser liberado',
                    `is_paid_module` TINYINT(1) NOT NULL DEFAULT 0,
                    `linked_product_id` INT(11) DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    KEY `idx_curso_id` (`curso_id`),
                    KEY `fk_modulos_linked_product` (`linked_product_id`),
                    CONSTRAINT `fk_modulos_curso` FOREIGN KEY (`curso_id`) REFERENCES `cursos` (`id`) ON DELETE CASCADE,
                    CONSTRAINT `fk_modulos_linked_product` FOREIGN KEY (`linked_product_id`) REFERENCES `produtos` (`id`) ON DELETE SET NULL
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
            $pdo->exec("ALTER TABLE `modulos` DROP FOREIGN KEY `fk_modulos_curso`");
            $pdo->exec("ALTER TABLE `modulos` DROP FOREIGN KEY `fk_modulos_linked_product`");
        } catch (PDOException $e) {
            // Ignorar se não existir
        }
        $pdo->exec("DROP TABLE IF EXISTS `modulos`");
    }
}

