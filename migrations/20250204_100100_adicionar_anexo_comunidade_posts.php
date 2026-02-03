<?php
/**
 * Migration: Adicionar anexo (imagem) em posts da comunidade
 * Versão mínima: 1.0.0
 * Adiciona coluna anexo_url na tabela comunidade_posts
 */

class Migration_20250204_100100_adicionar_anexo_comunidade_posts {
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
        $stmt = $pdo->query("SHOW TABLES LIKE 'comunidade_posts'");
        if ($stmt->rowCount() > 0) {
            $stmt_col = $pdo->query("SHOW COLUMNS FROM `comunidade_posts` LIKE 'anexo_url'");
            if ($stmt_col->rowCount() === 0) {
                $pdo->exec("ALTER TABLE `comunidade_posts` ADD COLUMN `anexo_url` VARCHAR(255) DEFAULT NULL COMMENT 'URL da imagem anexada ao post' AFTER `conteudo`");
            }
        }
    }

    /**
     * Reverte a migration (rollback)
     * @param PDO $pdo
     */
    public function down($pdo) {
        try {
            $stmt = $pdo->query("SHOW TABLES LIKE 'comunidade_posts'");
            if ($stmt->rowCount() > 0) {
                $pdo->exec("ALTER TABLE `comunidade_posts` DROP COLUMN `anexo_url`");
            }
        } catch (PDOException $e) {
            // Ignorar se coluna não existir
        }
    }
}


