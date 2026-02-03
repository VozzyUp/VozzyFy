<?php
/**
 * Migration: Adicionar campos de download protegido na tabela aulas
 * Versão mínima: 2.5.2
 * Adiciona suporte para downloads protegidos com termos de consentimento
 */

class Migration_20250129_120000_adicionar_download_protegido_aulas {
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
        // Verificar se a tabela aulas existe
        $stmt = $pdo->query("SHOW TABLES LIKE 'aulas'");
        if ($stmt->rowCount() == 0) {
            throw new Exception("Tabela 'aulas' não existe. Execute as migrations anteriores primeiro.");
        }
        
        // Verificar se as colunas já existem
        $stmt = $pdo->query("SHOW COLUMNS FROM aulas LIKE 'download_protegido'");
        if ($stmt->rowCount() == 0) {
            // Adicionar campo download_protegido
            $pdo->exec("ALTER TABLE `aulas` ADD COLUMN `download_protegido` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Indica se é um download protegido' AFTER `tipo_conteudo`");
        }
        
        // Verificar se download_link existe
        $stmt = $pdo->query("SHOW COLUMNS FROM aulas LIKE 'download_link'");
        if ($stmt->rowCount() == 0) {
            // Adicionar campo download_link
            $pdo->exec("ALTER TABLE `aulas` ADD COLUMN `download_link` VARCHAR(500) NULL COMMENT 'Link do Google Drive ou outro serviço de download' AFTER `download_protegido`");
        }
        
        // Verificar se termos_consentimento existe
        $stmt = $pdo->query("SHOW COLUMNS FROM aulas LIKE 'termos_consentimento'");
        if ($stmt->rowCount() == 0) {
            // Adicionar campo termos_consentimento
            $pdo->exec("ALTER TABLE `aulas` ADD COLUMN `termos_consentimento` TEXT NULL COMMENT 'Termos ou política de consentimento personalizados' AFTER `download_link`");
        }
        
        // Atualizar ENUM tipo_conteudo para incluir 'download_protegido'
        // Primeiro verificar o tipo atual da coluna
        $stmt = $pdo->query("SHOW COLUMNS FROM aulas WHERE Field = 'tipo_conteudo'");
        $column_info = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($column_info) {
            $current_type = $column_info['Type'];
            // Verificar se 'download_protegido' já está no ENUM
            if (stripos($current_type, 'download_protegido') === false) {
                // Modificar ENUM para incluir 'download_protegido'
                $pdo->exec("ALTER TABLE `aulas` MODIFY COLUMN `tipo_conteudo` ENUM('video','files','mixed','text','download_protegido') NOT NULL DEFAULT 'video' COMMENT 'Tipo de conteúdo da aula: video, files, mixed, text ou download_protegido'");
            }
        }
    }
    
    /**
     * Reverte a migration (rollback)
     * @param PDO $pdo
     */
    public function down($pdo) {
        // Remover colunas adicionadas
        try {
            $pdo->exec("ALTER TABLE `aulas` DROP COLUMN `termos_consentimento`");
        } catch (PDOException $e) {
            // Ignorar se não existir
        }
        
        try {
            $pdo->exec("ALTER TABLE `aulas` DROP COLUMN `download_link`");
        } catch (PDOException $e) {
            // Ignorar se não existir
        }
        
        try {
            $pdo->exec("ALTER TABLE `aulas` DROP COLUMN `download_protegido`");
        } catch (PDOException $e) {
            // Ignorar se não existir
        }
        
        // Reverter ENUM (remover 'download_protegido')
        try {
            $pdo->exec("ALTER TABLE `aulas` MODIFY COLUMN `tipo_conteudo` ENUM('video','files','mixed','text') NOT NULL DEFAULT 'video' COMMENT 'Tipo de conteúdo da aula: video, files, mixed ou text'");
        } catch (PDOException $e) {
            // Ignorar erro
        }
    }
}

