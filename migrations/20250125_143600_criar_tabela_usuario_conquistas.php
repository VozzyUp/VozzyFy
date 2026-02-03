<?php
/**
 * Migration: Criar tabela usuario_conquistas
 * Versão mínima: 1.0.0
 * Cria a tabela de conquistas dos usuários (depende de usuarios e conquistas)
 * Nota: Esta migration pode já existir junto com conquistas, mas será criada separadamente
 */

class Migration_20250125_143600_criar_tabela_usuario_conquistas {
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
        $stmt = $pdo->query("SHOW TABLES LIKE 'usuario_conquistas'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("
                CREATE TABLE `usuario_conquistas` (
                    `id` INT(11) NOT NULL AUTO_INCREMENT,
                    `usuario_id` INT(11) NOT NULL,
                    `conquista_id` INT(11) NOT NULL,
                    `data_conquista` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `faturamento_atingido` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `usuario_conquista` (`usuario_id`,`conquista_id`),
                    KEY `idx_usuario_id` (`usuario_id`),
                    KEY `idx_conquista_id` (`conquista_id`),
                    KEY `idx_data_conquista` (`data_conquista`),
                    CONSTRAINT `fk_usuario_conquistas_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE,
                    CONSTRAINT `fk_usuario_conquistas_conquista` FOREIGN KEY (`conquista_id`) REFERENCES `conquistas` (`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
    }
    
    /**
     * Reverte a migration (rollback)
     * @param PDO $pdo
     */
    public function down($pdo) {
        try {
            $pdo->exec("ALTER TABLE `usuario_conquistas` DROP FOREIGN KEY `fk_usuario_conquistas_usuario`");
            $pdo->exec("ALTER TABLE `usuario_conquistas` DROP FOREIGN KEY `fk_usuario_conquistas_conquista`");
        } catch (PDOException $e) {
            // Ignorar se não existir
        }
        $pdo->exec("DROP TABLE IF EXISTS `usuario_conquistas`");
    }
}

