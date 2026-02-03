<?php
/**
 * Migration de Teste
 * Versão mínima: 1.0.0
 * Esta é uma migration de exemplo para demonstrar como criar migrations
 */

class Migration_20250120_120000_test_migration {
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
        // Exemplo: Criar uma tabela de teste
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `test_migration_table` (
                `id` INT(11) NOT NULL AUTO_INCREMENT,
                `nome` VARCHAR(255) NOT NULL,
                `descricao` TEXT NULL,
                `valor` DECIMAL(10,2) DEFAULT 0.00,
                `ativo` TINYINT(1) DEFAULT 1,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                INDEX `idx_ativo` (`ativo`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        
        // Exemplo: Inserir dados de teste (opcional)
        $stmt = $pdo->prepare("
            INSERT INTO `test_migration_table` (`nome`, `descricao`, `valor`) 
            VALUES (?, ?, ?)
        ");
        $stmt->execute(['Teste 1', 'Descrição do teste 1', 10.50]);
        $stmt->execute(['Teste 2', 'Descrição do teste 2', 20.75]);
        
        // Exemplo: Adicionar coluna em tabela existente (se a tabela usuarios existir)
        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM `usuarios` LIKE 'test_field'");
            if ($stmt->rowCount() == 0) {
                $pdo->exec("ALTER TABLE `usuarios` ADD COLUMN `test_field` VARCHAR(100) NULL AFTER `nome`");
            }
        } catch (PDOException $e) {
            // Se a tabela usuarios não existir, apenas loga o erro mas não falha a migration
            error_log("Aviso: Não foi possível adicionar coluna test_field (tabela usuarios pode não existir): " . $e->getMessage());
        }
    }
    
    /**
     * Reverte a migration (rollback)
     * @param PDO $pdo
     */
    public function down($pdo) {
        // Remover coluna adicionada
        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM `usuarios` LIKE 'test_field'");
            if ($stmt->rowCount() > 0) {
                $pdo->exec("ALTER TABLE `usuarios` DROP COLUMN `test_field`");
            }
        } catch (PDOException $e) {
            error_log("Aviso ao reverter: " . $e->getMessage());
        }
        
        // Remover tabela de teste
        $pdo->exec("DROP TABLE IF EXISTS `test_migration_table`");
    }
}

