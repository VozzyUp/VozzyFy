<?php
/**
 * Plugin de Recorrência - Script de Instalação
 * 
 * Este script cria as tabelas e colunas necessárias para o funcionamento do plugin.
 */

// Verificar se estamos sendo executados via contexto de instalação
if (!defined('PLUGIN_INSTALL')) {
    // Tenta carregar o contexto necessário
    if (!isset($pdo)) {
        // Tenta encontrar o arquivo de configuração
        $config_paths = [
            __DIR__ . '/../../config/config.php',
            __DIR__ . '/../config/config.php'
        ];
        
        foreach ($config_paths as $config_path) {
            if (file_exists($config_path)) {
                require_once $config_path;
                break;
            }
        }
    }
}

// Verificar se $pdo está disponível
if (!isset($pdo)) {
    error_log("RECORRENCIA INSTALL: Erro - PDO não disponível");
    exit(1);
}

try {
    $pdo->beginTransaction();
    
    // 1. Criar tabela assinaturas
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `assinaturas` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `produto_id` INT(11) NOT NULL,
            `comprador_email` VARCHAR(255) NOT NULL,
            `comprador_nome` VARCHAR(255) NOT NULL,
            `venda_inicial_id` INT(11) NOT NULL COMMENT 'Primeira compra',
            `proxima_cobranca` DATE NOT NULL COMMENT 'Data da próxima cobrança',
            `ultima_cobranca` DATE DEFAULT NULL COMMENT 'Última cobrança realizada',
            `status` ENUM('ativa', 'expirada', 'cancelada') NOT NULL DEFAULT 'ativa',
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_produto_id` (`produto_id`),
            KEY `idx_comprador_email` (`comprador_email`),
            KEY `idx_proxima_cobranca` (`proxima_cobranca`),
            KEY `idx_status` (`status`),
            KEY `idx_venda_inicial_id` (`venda_inicial_id`),
            CONSTRAINT `fk_assinaturas_produto` FOREIGN KEY (`produto_id`) REFERENCES `produtos` (`id`) ON DELETE CASCADE,
            CONSTRAINT `fk_assinaturas_venda` FOREIGN KEY (`venda_inicial_id`) REFERENCES `vendas` (`id`) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    
    // 2. Adicionar colunas na tabela produtos
    $stmt_check = $pdo->query("SHOW COLUMNS FROM `produtos` LIKE 'tipo_pagamento'");
    if ($stmt_check->rowCount() == 0) {
        $pdo->exec("ALTER TABLE `produtos` ADD COLUMN `tipo_pagamento` ENUM('unico', 'recorrente') NOT NULL DEFAULT 'unico' AFTER `gateway`");
    }
    
    $stmt_check = $pdo->query("SHOW COLUMNS FROM `produtos` LIKE 'intervalo_recorrencia'");
    if ($stmt_check->rowCount() == 0) {
        $pdo->exec("ALTER TABLE `produtos` ADD COLUMN `intervalo_recorrencia` VARCHAR(50) NOT NULL DEFAULT 'mensal' AFTER `tipo_pagamento`");
    }
    
    // 3. Adicionar colunas na tabela vendas
    $stmt_check = $pdo->query("SHOW COLUMNS FROM `vendas` LIKE 'assinatura_id'");
    if ($stmt_check->rowCount() == 0) {
        $pdo->exec("ALTER TABLE `vendas` ADD COLUMN `assinatura_id` INT(11) DEFAULT NULL AFTER `checkout_session_uuid`");
        $pdo->exec("ALTER TABLE `vendas` ADD KEY `idx_assinatura_id` (`assinatura_id`)");
        $pdo->exec("ALTER TABLE `vendas` ADD CONSTRAINT `fk_vendas_assinatura` FOREIGN KEY (`assinatura_id`) REFERENCES `assinaturas` (`id`) ON DELETE SET NULL");
    }
    
    $stmt_check = $pdo->query("SHOW COLUMNS FROM `vendas` LIKE 'eh_renovacao'");
    if ($stmt_check->rowCount() == 0) {
        $pdo->exec("ALTER TABLE `vendas` ADD COLUMN `eh_renovacao` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Flag para identificar renovações' AFTER `assinatura_id`");
    }
    
    // 4. Adicionar colunas na tabela alunos_acessos
    $stmt_check = $pdo->query("SHOW COLUMNS FROM `alunos_acessos` LIKE 'data_expiracao'");
    if ($stmt_check->rowCount() == 0) {
        $pdo->exec("ALTER TABLE `alunos_acessos` ADD COLUMN `data_expiracao` DATE DEFAULT NULL COMMENT 'Data de expiração do acesso' AFTER `data_concessao`");
    }
    
    $stmt_check = $pdo->query("SHOW COLUMNS FROM `alunos_acessos` LIKE 'assinatura_id'");
    if ($stmt_check->rowCount() == 0) {
        $pdo->exec("ALTER TABLE `alunos_acessos` ADD COLUMN `assinatura_id` INT(11) DEFAULT NULL AFTER `data_expiracao`");
        $pdo->exec("ALTER TABLE `alunos_acessos` ADD KEY `idx_assinatura_id` (`assinatura_id`)");
        $pdo->exec("ALTER TABLE `alunos_acessos` ADD CONSTRAINT `fk_alunos_acessos_assinatura` FOREIGN KEY (`assinatura_id`) REFERENCES `assinaturas` (`id`) ON DELETE SET NULL");
    }
    
    $pdo->commit();
    error_log("RECORRENCIA INSTALL: Tabelas e colunas criadas com sucesso");
    
} catch (PDOException $e) {
    $pdo->rollBack();
    error_log("RECORRENCIA INSTALL: Erro ao criar tabelas - " . $e->getMessage());
    throw $e;
}

