<?php
/**
 * Migration: Adicionar campo imagem_capa_url na tabela secao_produtos
 * Versão mínima: 1.0.0
 * Permite que produtos na seção "outros_produtos" tenham capa de módulo igual aos módulos normais
 */

class Migration_20250202_100000_adicionar_imagem_capa_secao_produtos {
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
        $stmt = $pdo->query("SHOW COLUMNS FROM `secao_produtos` LIKE 'imagem_capa_url'");
        if ($stmt->rowCount() == 0) {
            // Adicionar coluna imagem_capa_url
            $pdo->exec("ALTER TABLE `secao_produtos` ADD COLUMN `imagem_capa_url` VARCHAR(255) NULL AFTER `ordem` COMMENT 'Caminho da imagem de capa do produto na seção'");
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
        $stmt = $pdo->query("SHOW COLUMNS FROM secao_produtos LIKE 'imagem_capa_url'");
        if ($stmt->rowCount() > 0) {
            // Deletar arquivos de imagem antes de remover a coluna
            try {
                $stmt_files = $pdo->query("SELECT imagem_capa_url FROM secao_produtos WHERE imagem_capa_url IS NOT NULL");
                $files = $stmt_files->fetchAll(PDO::FETCH_COLUMN);
                foreach ($files as $file_path) {
                    if (!empty($file_path) && file_exists($file_path) && strpos($file_path, 'uploads/') === 0) {
                        @unlink($file_path);
                    }
                }
            } catch (PDOException $e) {
                // Ignorar erros ao deletar arquivos
            }

            // Remover coluna
            $pdo->exec("ALTER TABLE `secao_produtos` DROP COLUMN `imagem_capa_url`");
        }
    }
}

