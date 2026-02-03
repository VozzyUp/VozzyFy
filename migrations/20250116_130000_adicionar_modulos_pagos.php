<?php
/**
 * Migration: Adicionar suporte a módulos pagos
 * Versão mínima: 1.0.0
 * Adiciona campos para módulos pagos na tabela modulos
 */

class Migration_20250116_130000_adicionar_modulos_pagos {
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
        try {
            // Verificar se a tabela modulos existe
            $stmt = $pdo->query("SHOW TABLES LIKE 'modulos'");
            if ($stmt->rowCount() == 0) {
                error_log("Aviso: Tabela 'modulos' não existe. Migration de módulos pagos será ignorada.");
                return; // Não falha a migration se a tabela não existir
            }
            
            // Verificar e adicionar is_paid_module se não existir
            $stmt = $pdo->query("SHOW COLUMNS FROM `modulos` LIKE 'is_paid_module'");
            if ($stmt->rowCount() == 0) {
                $pdo->exec("ALTER TABLE `modulos` ADD COLUMN `is_paid_module` TINYINT(1) DEFAULT 0 NOT NULL");
            }
            
            // Verificar e adicionar linked_product_id se não existir
            $stmt = $pdo->query("SHOW COLUMNS FROM `modulos` LIKE 'linked_product_id'");
            if ($stmt->rowCount() == 0) {
                $pdo->exec("ALTER TABLE `modulos` ADD COLUMN `linked_product_id` INT NULL");
            }
            
            // Verificar se a foreign key já existe antes de adicionar
            $stmt = $pdo->query("
                SELECT CONSTRAINT_NAME 
                FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = 'modulos' 
                AND CONSTRAINT_NAME = 'fk_modulos_linked_product'
            ");
            
            if ($stmt->rowCount() == 0) {
                // Verificar se a tabela produtos existe antes de adicionar foreign key
                $stmt = $pdo->query("SHOW TABLES LIKE 'produtos'");
                if ($stmt->rowCount() > 0) {
                    $pdo->exec("
                        ALTER TABLE `modulos` 
                        ADD CONSTRAINT `fk_modulos_linked_product` 
                        FOREIGN KEY (`linked_product_id`) REFERENCES `produtos`(`id`) ON DELETE SET NULL
                    ");
                } else {
                    error_log("Aviso: Tabela 'produtos' não existe. Foreign key não será adicionada.");
                }
            }
        } catch (PDOException $e) {
            error_log("Erro ao adicionar campos de módulos pagos: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Reverte a migration (rollback)
     * @param PDO $pdo
     */
    public function down($pdo) {
        try {
            // Verificar se a tabela modulos existe
            $stmt = $pdo->query("SHOW TABLES LIKE 'modulos'");
            if ($stmt->rowCount() == 0) {
                return; // Tabela não existe, nada para reverter
            }
            
            // Remover foreign key se existir
            $stmt = $pdo->query("
                SELECT CONSTRAINT_NAME 
                FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE 
                WHERE TABLE_SCHEMA = DATABASE() 
                AND TABLE_NAME = 'modulos' 
                AND CONSTRAINT_NAME = 'fk_modulos_linked_product'
            ");
            
            if ($stmt->rowCount() > 0) {
                $pdo->exec("ALTER TABLE `modulos` DROP FOREIGN KEY `fk_modulos_linked_product`");
            }
            
            // Remover colunas se existirem
            $stmt = $pdo->query("SHOW COLUMNS FROM `modulos` LIKE 'linked_product_id'");
            if ($stmt->rowCount() > 0) {
                $pdo->exec("ALTER TABLE `modulos` DROP COLUMN `linked_product_id`");
            }
            
            $stmt = $pdo->query("SHOW COLUMNS FROM `modulos` LIKE 'is_paid_module'");
            if ($stmt->rowCount() > 0) {
                $pdo->exec("ALTER TABLE `modulos` DROP COLUMN `is_paid_module`");
            }
        } catch (PDOException $e) {
            error_log("Erro ao reverter migration de módulos pagos: " . $e->getMessage());
            throw $e;
        }
    }
}

