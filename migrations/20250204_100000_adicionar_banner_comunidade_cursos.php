<?php
/**
 * Migration: Adicionar banner da comunidade em cursos
 * Versão mínima: 1.0.0
 * Adiciona coluna comunidade_banner_url na tabela cursos
 */

class Migration_20250204_100000_adicionar_banner_comunidade_cursos {
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
        $stmt = $pdo->query("SHOW COLUMNS FROM `cursos` LIKE 'comunidade_banner_url'");
        if ($stmt->rowCount() === 0) {
            // Verificar se banner_logo_url existe para posicionar após ela
            $stmt_logo = $pdo->query("SHOW COLUMNS FROM `cursos` LIKE 'banner_logo_url'");
            $after = ($stmt_logo->rowCount() > 0) ? ' AFTER `banner_logo_url`' : '';
            
            // Usar formato que o sistema de integridade detecta facilmente
            $pdo->exec("ALTER TABLE `cursos` ADD COLUMN `comunidade_banner_url` VARCHAR(255) DEFAULT NULL COMMENT 'Banner da comunidade (imagem única)'" . $after);
        }
    }

    /**
     * Reverte a migration (rollback)
     * @param PDO $pdo
     */
    public function down($pdo) {
        try {
            $pdo->exec("ALTER TABLE `cursos` DROP COLUMN `comunidade_banner_url`");
        } catch (PDOException $e) {
            // Ignorar se coluna não existir
        }
    }
}

