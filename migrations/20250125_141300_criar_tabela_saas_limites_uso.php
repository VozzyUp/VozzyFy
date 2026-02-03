<?php
/**
 * Migration: Criar tabela saas_limites_uso
 * Versão mínima: 1.0.0
 * Cria a tabela de limites de uso SaaS (depende de usuarios)
 */

class Migration_20250125_141300_criar_tabela_saas_limites_uso {
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
        $stmt = $pdo->query("SHOW TABLES LIKE 'saas_limites_uso'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("
                CREATE TABLE `saas_limites_uso` (
                    `id` INT(11) NOT NULL AUTO_INCREMENT,
                    `usuario_id` INT(11) NOT NULL,
                    `mes_ano` VARCHAR(7) NOT NULL COMMENT 'Formato: YYYY-MM',
                    `produtos_criados` INT(11) DEFAULT 0,
                    `pedidos_realizados` INT(11) DEFAULT 0,
                    `resetado_em` DATETIME DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `unique_usuario_mes` (`usuario_id`,`mes_ano`),
                    KEY `idx_usuario_mes` (`usuario_id`,`mes_ano`),
                    CONSTRAINT `saas_limites_uso_ibfk_1` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
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
            $pdo->exec("ALTER TABLE `saas_limites_uso` DROP FOREIGN KEY `saas_limites_uso_ibfk_1`");
        } catch (PDOException $e) {
            // Ignorar se não existir
        }
        $pdo->exec("DROP TABLE IF EXISTS `saas_limites_uso`");
    }
}

