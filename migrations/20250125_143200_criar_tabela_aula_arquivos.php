<?php
/**
 * Migration: Criar tabela aula_arquivos
 * Versão mínima: 1.0.0
 * Cria a tabela de arquivos de aulas (depende de aulas)
 */

class Migration_20250125_143200_criar_tabela_aula_arquivos {
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
        $stmt = $pdo->query("SHOW TABLES LIKE 'aula_arquivos'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("
                CREATE TABLE `aula_arquivos` (
                    `id` INT(11) NOT NULL AUTO_INCREMENT,
                    `aula_id` INT(11) NOT NULL,
                    `nome_original` VARCHAR(255) NOT NULL COMMENT 'Nome original do arquivo',
                    `nome_salvo` VARCHAR(255) NOT NULL COMMENT 'Nome do arquivo salvo no servidor',
                    `caminho_arquivo` VARCHAR(255) NOT NULL COMMENT 'Caminho completo do arquivo no servidor (ex: uploads/aula_files/arquivo.pdf)',
                    `tipo_mime` VARCHAR(100) DEFAULT NULL COMMENT 'Tipo MIME do arquivo (ex: application/pdf, image/png)',
                    `tamanho_bytes` INT(11) DEFAULT NULL,
                    `ordem` INT(11) NOT NULL DEFAULT 0 COMMENT 'Ordem de exibição do arquivo dentro da aula',
                    `data_upload` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    KEY `fk_aula_arquivos_aula` (`aula_id`),
                    CONSTRAINT `fk_aula_arquivos_aula` FOREIGN KEY (`aula_id`) REFERENCES `aulas` (`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
            ");
        }
    }
    
    /**
     * Reverte a migration (rollback)
     * @param PDO $pdo
     */
    public function down($pdo) {
        try {
            $pdo->exec("ALTER TABLE `aula_arquivos` DROP FOREIGN KEY `fk_aula_arquivos_aula`");
        } catch (PDOException $e) {
            // Ignorar se não existir
        }
        $pdo->exec("DROP TABLE IF EXISTS `aula_arquivos`");
    }
}

