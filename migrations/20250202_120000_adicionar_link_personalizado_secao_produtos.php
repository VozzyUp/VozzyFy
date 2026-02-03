<?php
/**
 * Migration: Adicionar campo link_personalizado na tabela secao_produtos
 * Versão mínima: 1.0.0
 * Permite que produtos na seção "outros_produtos" tenham link personalizado para página de vendas
 */

class Migration_20250202_120000_adicionar_link_personalizado_secao_produtos {
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
        // Verificar se a tabela secao_produtos existe
        $stmt = $pdo->query("SHOW TABLES LIKE 'secao_produtos'");
        if ($stmt->rowCount() == 0) {
            // Se a tabela não existir, não faz nada (será criada por outra migration)
            return;
        }

        // Verificar se a coluna já existe
        $stmt = $pdo->query("SHOW COLUMNS FROM `secao_produtos` LIKE 'link_personalizado'");
        if ($stmt->rowCount() == 0) {
            // Adicionar coluna link_personalizado
            $pdo->exec("ALTER TABLE `secao_produtos` ADD COLUMN `link_personalizado` VARCHAR(500) NULL AFTER `imagem_capa_url` COMMENT 'Link personalizado para página de vendas. Se NULL, usa checkout_hash padrão'");
        }
    }

    /**
     * Reverte a migration (rollback)
     * @param PDO $pdo
     */
    public function down($pdo) {
        // Verificar se a tabela existe
        $stmt = $pdo->query("SHOW TABLES LIKE 'secao_produtos'");
        if ($stmt->rowCount() == 0) {
            return;
        }

        // Verificar se a coluna existe antes de remover
        $stmt = $pdo->query("SHOW COLUMNS FROM secao_produtos LIKE 'link_personalizado'");
        if ($stmt->rowCount() > 0) {
            $pdo->exec("ALTER TABLE `secao_produtos` DROP COLUMN `link_personalizado`");
        }
    }
}

