<?php
/**
 * Migration: Adicionar community_enabled em cursos
 * Versão mínima: 1.0.0
 */

class Migration_20250131_100003_cursos_community_enabled {
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
        $stmt = $pdo->query("SHOW COLUMNS FROM cursos LIKE 'community_enabled'");
        if ($stmt->rowCount() == 0) {
            $stmt = $pdo->query("SHOW COLUMNS FROM cursos LIKE 'allow_comments'");
            $after = ($stmt->rowCount() > 0) ? ' AFTER `allow_comments`' : '';
            $pdo->exec("ALTER TABLE `cursos` ADD COLUMN `community_enabled` TINYINT(1) NOT NULL DEFAULT 0 COMMENT '0=desabilitado, 1=comunidade ativa para o curso'" . $after);
        }
    }

    /**
     * Reverte a migration (rollback)
     * @param PDO $pdo
     */
    public function down($pdo) {
        try {
            $pdo->exec("ALTER TABLE `cursos` DROP COLUMN `community_enabled`");
        } catch (PDOException $e) {
            // Ignorar
        }
    }
}
