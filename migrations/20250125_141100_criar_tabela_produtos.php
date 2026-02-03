<?php
/**
 * Migration: Criar tabela produtos
 * Versão mínima: 1.0.0
 * Cria a tabela de produtos (depende de usuarios)
 */

class Migration_20250125_141100_criar_tabela_produtos {
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
        $stmt = $pdo->query("SHOW TABLES LIKE 'produtos'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("
                CREATE TABLE `produtos` (
                    `id` INT(11) NOT NULL AUTO_INCREMENT,
                    `nome` VARCHAR(255) NOT NULL,
                    `descricao` TEXT DEFAULT NULL,
                    `preco` DECIMAL(10,2) NOT NULL,
                    `foto` VARCHAR(255) DEFAULT NULL,
                    `checkout_hash` VARCHAR(255) NOT NULL,
                    `checkout_config` TEXT DEFAULT NULL,
                    `usuario_id` INT(11) NOT NULL,
                    `data_criacao` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `preco_anterior` DECIMAL(10,2) DEFAULT NULL,
                    `tipo_entrega` VARCHAR(50) NOT NULL DEFAULT 'link',
                    `conteudo_entrega` VARCHAR(255) DEFAULT NULL,
                    `gateway` VARCHAR(50) DEFAULT 'mercadopago',
                    PRIMARY KEY (`id`),
                    KEY `idx_usuario_id` (`usuario_id`),
                    CONSTRAINT `fk_produtos_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
            ");
        }
    }
    
    /**
     * Reverte a migration (rollback)
     * @param PDO $pdo
     */
    public function down($pdo) {
        // Remover foreign key primeiro
        try {
            $pdo->exec("ALTER TABLE `produtos` DROP FOREIGN KEY `fk_produtos_usuario`");
        } catch (PDOException $e) {
            // Ignorar se não existir
        }
        $pdo->exec("DROP TABLE IF EXISTS `produtos`");
    }
}

