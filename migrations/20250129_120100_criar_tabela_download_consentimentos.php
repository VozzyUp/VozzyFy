<?php
/**
 * Migration: Criar tabela download_consentimentos
 * Versão mínima: 2.5.2
 * Cria a tabela para armazenar consentimentos de downloads protegidos
 */

class Migration_20250129_120100_criar_tabela_download_consentimentos {
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
        $stmt = $pdo->query("SHOW TABLES LIKE 'download_consentimentos'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("
                CREATE TABLE `download_consentimentos` (
                    `id` INT(11) NOT NULL AUTO_INCREMENT,
                    `aula_id` INT(11) NOT NULL,
                    `produto_id` INT(11) NOT NULL,
                    `aluno_email` VARCHAR(255) NOT NULL,
                    `aluno_nome` VARCHAR(255) NOT NULL,
                    `aluno_cpf` VARCHAR(14) NOT NULL,
                    `termos_aceitos` TEXT NOT NULL,
                    `documento_consentimento_html` TEXT NOT NULL,
                    `ip_address` VARCHAR(45) NULL,
                    `user_agent` VARCHAR(500) NULL,
                    `data_consentimento` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `idx_aula_id` (`aula_id`),
                    KEY `idx_produto_id` (`produto_id`),
                    KEY `idx_aluno_email` (`aluno_email`),
                    KEY `idx_data_consentimento` (`data_consentimento`),
                    CONSTRAINT `fk_download_consentimentos_aula` FOREIGN KEY (`aula_id`) REFERENCES `aulas` (`id`) ON DELETE CASCADE,
                    CONSTRAINT `fk_download_consentimentos_produto` FOREIGN KEY (`produto_id`) REFERENCES `produtos` (`id`) ON DELETE CASCADE
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
            $pdo->exec("ALTER TABLE `download_consentimentos` DROP FOREIGN KEY `fk_download_consentimentos_aula`");
        } catch (PDOException $e) {
            // Ignorar se não existir
        }
        
        try {
            $pdo->exec("ALTER TABLE `download_consentimentos` DROP FOREIGN KEY `fk_download_consentimentos_produto`");
        } catch (PDOException $e) {
            // Ignorar se não existir
        }
        
        $pdo->exec("DROP TABLE IF EXISTS `download_consentimentos`");
    }
}

