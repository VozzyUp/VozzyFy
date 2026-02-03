<?php
/**
 * Migration: Criar tabela conquistas
 * Versão mínima: 1.0.0
 * Cria a tabela de conquistas do sistema de gamificação
 * Nota: Esta migration pode já existir, mas será atualizada se necessário
 */

class Migration_20250125_140400_criar_tabela_conquistas {
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
        $stmt = $pdo->query("SHOW TABLES LIKE 'conquistas'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("
                CREATE TABLE `conquistas` (
                    `id` INT(11) NOT NULL AUTO_INCREMENT,
                    `nome` VARCHAR(255) NOT NULL,
                    `descricao` TEXT DEFAULT NULL,
                    `valor_minimo` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                    `valor_maximo` DECIMAL(10,2) DEFAULT NULL,
                    `imagem_badge` VARCHAR(500) DEFAULT NULL,
                    `ordem` INT(11) NOT NULL DEFAULT 0,
                    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_ordem` (`ordem`),
                    KEY `idx_valor_minimo` (`valor_minimo`),
                    KEY `idx_is_active` (`is_active`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
    }
    
    /**
     * Reverte a migration (rollback)
     * @param PDO $pdo
     */
    public function down($pdo) {
        $pdo->exec("DROP TABLE IF EXISTS `conquistas`");
    }
}

