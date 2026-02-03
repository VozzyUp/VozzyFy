<?php
/**
 * Migration: Sistema de Gamificação - Conquistas
 * Versão mínima: 1.0.0
 * Cria tabelas para sistema de conquistas baseado em faturamento
 */

class Migration_20250116_110000_criar_tabela_conquistas {
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
        // Criar tabela de Conquistas
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `conquistas` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `nome` VARCHAR(255) NOT NULL,
                `descricao` TEXT,
                `valor_minimo` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                `valor_maximo` DECIMAL(10,2) NULL DEFAULT NULL,
                `imagem_badge` VARCHAR(500) NULL DEFAULT NULL,
                `ordem` INT(11) NOT NULL DEFAULT 0,
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                INDEX `idx_ordem` (`ordem`),
                INDEX `idx_valor_minimo` (`valor_minimo`),
                INDEX `idx_is_active` (`is_active`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        
        // Criar tabela de Conquistas dos Usuários
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `usuario_conquistas` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `usuario_id` INT(11) NOT NULL,
                `conquista_id` INT(11) NOT NULL,
                `data_conquista` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `faturamento_atingido` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                PRIMARY KEY (`id`),
                UNIQUE KEY `usuario_conquista` (`usuario_id`, `conquista_id`),
                INDEX `idx_usuario_id` (`usuario_id`),
                INDEX `idx_conquista_id` (`conquista_id`),
                INDEX `idx_data_conquista` (`data_conquista`),
                CONSTRAINT `fk_usuario_conquistas_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE,
                CONSTRAINT `fk_usuario_conquistas_conquista` FOREIGN KEY (`conquista_id`) REFERENCES `conquistas` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
    
    /**
     * Reverte a migration (rollback)
     * @param PDO $pdo
     */
    public function down($pdo) {
        // Remover tabelas na ordem inversa (primeiro dependentes, depois principais)
        try {
            $pdo->exec("DROP TABLE IF EXISTS `usuario_conquistas`");
            $pdo->exec("DROP TABLE IF EXISTS `conquistas`");
        } catch (PDOException $e) {
            error_log("Erro ao reverter migration de conquistas: " . $e->getMessage());
            throw $e;
        }
    }
}

