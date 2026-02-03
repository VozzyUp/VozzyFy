<?php
/**
 * Migration: Adicionar campo tipo_capa na tabela secoes
 * Versão mínima: 1.0.0
 * Permite escolher entre capa vertical (padrão) ou horizontal (842x327) para seções
 */

class Migration_20250203_100000_adicionar_tipo_capa_secoes {
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
        // Verificar se a tabela secoes existe
        $stmt = $pdo->query("SHOW TABLES LIKE 'secoes'");
        if ($stmt->rowCount() == 0) {
            // Se a tabela não existir, não faz nada (será criada por outra migration)
            return;
        }

        // Verificar se a coluna já existe
        $stmt = $pdo->query("SHOW COLUMNS FROM `secoes` LIKE 'tipo_capa'");
        if ($stmt->rowCount() == 0) {
            // Adicionar coluna tipo_capa após ordem
            // IMPORTANTE: Formato específico para detecção pelo sistema de integridade
            // O padrão de regex procura por: ALTER TABLE `secoes` ADD COLUMN `tipo_capa` ENUM(...)
            $pdo->exec("ALTER TABLE `secoes` ADD COLUMN `tipo_capa` ENUM('vertical', 'horizontal') NOT NULL DEFAULT 'vertical' AFTER `ordem` COMMENT 'Tipo de capa: vertical (3/4) ou horizontal (842x327)'");
        }
    }

    /**
     * Reverte a migration (rollback)
     * @param PDO $pdo
     */
    public function down($pdo) {
        // Verificar se a tabela existe
        $stmt = $pdo->query("SHOW TABLES LIKE 'secoes'");
        if ($stmt->rowCount() == 0) {
            return;
        }

        // Verificar se a coluna existe antes de remover
        $stmt = $pdo->query("SHOW COLUMNS FROM secoes LIKE 'tipo_capa'");
        if ($stmt->rowCount() > 0) {
            // Remover coluna
            $pdo->exec("ALTER TABLE `secoes` DROP COLUMN `tipo_capa`");
        }
    }
}

