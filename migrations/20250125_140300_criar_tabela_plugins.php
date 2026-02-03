<?php
/**
 * Migration: Criar tabela plugins
 * Versão mínima: 1.0.0
 * Cria a tabela de plugins do sistema
 */

class Migration_20250125_140300_criar_tabela_plugins {
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
        $stmt = $pdo->query("SHOW TABLES LIKE 'plugins'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("
                CREATE TABLE `plugins` (
                    `id` INT(11) NOT NULL AUTO_INCREMENT,
                    `nome` VARCHAR(100) NOT NULL,
                    `pasta` VARCHAR(100) NOT NULL,
                    `versao` VARCHAR(20) DEFAULT '1.0.0',
                    `ativo` TINYINT(1) DEFAULT 0,
                    `instalado_em` DATETIME DEFAULT CURRENT_TIMESTAMP,
                    `atualizado_em` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `nome` (`nome`),
                    UNIQUE KEY `pasta` (`pasta`),
                    KEY `idx_ativo` (`ativo`),
                    KEY `idx_pasta` (`pasta`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
    }
    
    /**
     * Reverte a migration (rollback)
     * @param PDO $pdo
     */
    public function down($pdo) {
        $pdo->exec("DROP TABLE IF EXISTS `plugins`");
    }
}

