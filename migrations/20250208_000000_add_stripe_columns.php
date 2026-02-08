<?php
/**
 * Migration: Adicionar colunas do Stripe
 * Versão mínima: 1.0.0
 * Adiciona campos de configuração do Stripe nas tabelas usuarios e saas_admin_gateways
 */

class Migration_20250208_000000_add_stripe_columns {
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
        // Adicionar colunas na tabela usuarios
        $columns = [
            'stripe_public_key' => 'VARCHAR(255) DEFAULT NULL',
            'stripe_secret_key' => 'VARCHAR(255) DEFAULT NULL',
            'stripe_webhook_secret' => 'VARCHAR(255) DEFAULT NULL'
        ];
        
        foreach ($columns as $column => $definition) {
            try {
                $stmt = $pdo->query("SHOW COLUMNS FROM usuarios LIKE '$column'");
                if ($stmt->rowCount() == 0) {
                    $pdo->exec("ALTER TABLE usuarios ADD COLUMN $column $definition");
                }
            } catch (PDOException $e) {
                // Ignorar erro se coluna já existir (redundância)
            }
        }
        
        // Adicionar colunas na tabela saas_admin_gateways
        // Primeiro verifica se a tabela existe
        $stmt_table = $pdo->query("SHOW TABLES LIKE 'saas_admin_gateways'");
        if ($stmt_table->rowCount() > 0) {
            $columns_saas = [
                'stripe_public_key' => 'VARCHAR(255) DEFAULT NULL',
                'stripe_secret_key' => 'VARCHAR(255) DEFAULT NULL',
                'stripe_webhook_secret' => 'VARCHAR(255) DEFAULT NULL'
            ];
            
            foreach ($columns_saas as $column => $definition) {
                try {
                    $stmt = $pdo->query("SHOW COLUMNS FROM saas_admin_gateways LIKE '$column'");
                    if ($stmt->rowCount() == 0) {
                        $pdo->exec("ALTER TABLE saas_admin_gateways ADD COLUMN $column $definition");
                    }
                } catch (PDOException $e) {
                    // Ignorar
                }
            }
        }
    }
    
    /**
     * Reverte a migration (rollback)
     * @param PDO $pdo
     */
    public function down($pdo) {
        // Remover colunas da tabela usuarios
        $columns = ['stripe_public_key', 'stripe_secret_key', 'stripe_webhook_secret'];
        foreach ($columns as $column) {
            try {
                $pdo->exec("ALTER TABLE usuarios DROP COLUMN $column");
            } catch (PDOException $e) {}
        }
        
        // Remover colunas da tabela saas_admin_gateways
        $stmt_table = $pdo->query("SHOW TABLES LIKE 'saas_admin_gateways'");
        if ($stmt_table->rowCount() > 0) {
            foreach ($columns as $column) {
                try {
                    $pdo->exec("ALTER TABLE saas_admin_gateways DROP COLUMN $column");
                } catch (PDOException $e) {}
            }
        }
    }
}
