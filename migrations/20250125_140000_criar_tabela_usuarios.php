<?php
/**
 * Migration: Criar tabela usuarios
 * Versão mínima: 1.0.0
 * Cria a tabela base de usuários do sistema
 */

class Migration_20250125_140000_criar_tabela_usuarios {
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
        $stmt = $pdo->query("SHOW TABLES LIKE 'usuarios'");
        if ($stmt->rowCount() == 0) {
            $pdo->exec("
                CREATE TABLE `usuarios` (
                    `id` INT(11) NOT NULL AUTO_INCREMENT,
                    `usuario` VARCHAR(255) NOT NULL,
                    `nome` VARCHAR(255) DEFAULT NULL,
                    `test_field` VARCHAR(100) DEFAULT NULL,
                    `telefone` VARCHAR(20) DEFAULT NULL,
                    `senha` VARCHAR(255) NOT NULL,
                    `tipo` VARCHAR(20) NOT NULL DEFAULT 'infoprodutor' COMMENT 'Define o tipo de usuário (admin, infoprodutor, usuario[cliente])',
                    `mp_public_key` VARCHAR(255) DEFAULT NULL,
                    `mp_access_token` VARCHAR(255) DEFAULT NULL,
                    `foto_perfil` VARCHAR(255) DEFAULT NULL,
                    `ultima_visualizacao_notificacoes` TIMESTAMP NULL DEFAULT NULL COMMENT 'Timestamp da última vez que o usuário visualizou o painel de notificações',
                    `pushinpay_token` VARCHAR(255) DEFAULT NULL,
                    `efi_client_id` VARCHAR(255) DEFAULT NULL,
                    `efi_client_secret` VARCHAR(255) DEFAULT NULL,
                    `efi_certificate_path` VARCHAR(500) DEFAULT NULL,
                    `efi_pix_key` VARCHAR(255) DEFAULT NULL,
                    `remember_token` VARCHAR(255) DEFAULT NULL,
                    `beehive_secret_key` VARCHAR(255) DEFAULT NULL,
                    `beehive_public_key` VARCHAR(255) DEFAULT NULL,
                    `hypercash_secret_key` VARCHAR(255) DEFAULT NULL,
                    `hypercash_public_key` VARCHAR(255) DEFAULT NULL,
                    `efi_payee_code` VARCHAR(255) DEFAULT NULL,
                    `password_reset_token` VARCHAR(64) DEFAULT NULL COMMENT 'Token único para recuperação de senha',
                    `password_reset_expires` DATETIME DEFAULT NULL COMMENT 'Data e hora de expiração do token (1 hora após geração)',
                    `password_setup_token` VARCHAR(64) DEFAULT NULL COMMENT 'Token único para criação inicial de senha',
                    `password_setup_expires` DATETIME DEFAULT NULL COMMENT 'Data e hora de expiração do token (7 dias após geração)',
                    `saas_plano_free_atribuido` TINYINT(1) DEFAULT 0,
                    `applyfy_public_key` VARCHAR(255) DEFAULT NULL,
                    `applyfy_secret_key` VARCHAR(255) DEFAULT NULL,
                    `asaas_api_key` VARCHAR(255) DEFAULT NULL COMMENT 'Chave de API do Asaas',
                    `asaas_environment` ENUM('production','sandbox') DEFAULT 'sandbox' COMMENT 'Ambiente do Asaas (produção ou sandbox)',
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `usuario` (`usuario`),
                    KEY `idx_remember_token` (`remember_token`),
                    KEY `idx_password_reset_token` (`password_reset_token`),
                    KEY `idx_password_setup_token` (`password_setup_token`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
            ");
        }
    }
    
    /**
     * Reverte a migration (rollback)
     * @param PDO $pdo
     */
    public function down($pdo) {
        $pdo->exec("DROP TABLE IF EXISTS `usuarios`");
    }
}

