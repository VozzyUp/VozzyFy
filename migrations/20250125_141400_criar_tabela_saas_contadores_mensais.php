<?php
/**
 * Migration: Criar tabela saas_contadores_mensais
 * Versão mínima: 1.0.0
 * Cria a tabela de contadores mensais SaaS (depende de usuarios)
 */

class Migration_20250125_141400_criar_tabela_saas_contadores_mensais {
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
        $stmt = $pdo->query("SHOW TABLES LIKE 'saas_contadores_mensais'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("
                CREATE TABLE `saas_contadores_mensais` (
                    `id` INT(11) NOT NULL AUTO_INCREMENT,
                    `usuario_id` INT(11) NOT NULL,
                    `mes_ano` VARCHAR(7) NOT NULL COMMENT 'Formato: YYYY-MM',
                    `produtos_criados` INT(11) DEFAULT 0,
                    `pedidos_realizados` INT(11) DEFAULT 0,
                    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `unique_user_month` (`usuario_id`,`mes_ano`),
                    CONSTRAINT `saas_contadores_mensais_ibfk_1` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
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
            $pdo->exec("ALTER TABLE `saas_contadores_mensais` DROP FOREIGN KEY `saas_contadores_mensais_ibfk_1`");
        } catch (PDOException $e) {
            // Ignorar se não existir
        }
        $pdo->exec("DROP TABLE IF EXISTS `saas_contadores_mensais`");
    }
}

