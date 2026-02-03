<?php
/**
 * Migration: Inserir dados iniciais essenciais do sistema
 * Versão mínima: 1.0.0
 * Insere apenas dados essenciais:
 * - Configurações do sistema (cores, logos, favicon, imagem de login, nome da plataforma)
 * 
 * Nota: O usuário admin é criado pelo instalador, não por esta migration
 */

class Migration_20250125_144000_inserir_dados_iniciais {
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
        // Inserir configurações do sistema (apenas se não existirem)
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM configuracoes_sistema");
        $count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
        
        if ($count == 0) {
            $systemConfigs = [
                ['cor_primaria', '#32e768', 'color', 'Cor primária do sistema'],
                ['logo_url', 'uploads/config/logo_1766928123.png', 'image', 'URL da logo do sistema'],
                ['login_image_url', 'uploads/config/login_bg_1766856615.jpg', 'image', 'URL da imagem de fundo da tela de login'],
                ['nome_plataforma', 'getfy', 'text', NULL],
                ['logo_checkout_url', 'uploads/config/logo_checkout_1766928133.png', 'text', NULL],
                ['favicon_url', 'uploads/config/favicon_1766928139.png', 'text', NULL],
                ['github_repo', '', 'text', NULL],
                ['github_token', '', 'text', NULL],
                ['github_branch', 'main', 'text', NULL]
            ];
            
            $stmt = $pdo->prepare("INSERT INTO configuracoes_sistema (chave, valor, tipo, descricao) VALUES (?, ?, ?, ?)");
            foreach ($systemConfigs as $config) {
                try {
                    $stmt->execute($config);
                } catch (PDOException $e) {
                    // Ignorar se já existe
                    if (strpos($e->getMessage(), 'Duplicate') === false) {
                        error_log("Erro ao inserir configuração do sistema {$config[0]}: " . $e->getMessage());
                    }
                }
            }
        } else {
            // Verificar e inserir configurações que podem estar faltando
            $systemConfigs = [
                ['cor_primaria', '#32e768', 'color', 'Cor primária do sistema'],
                ['logo_url', 'uploads/config/logo_1766928123.png', 'image', 'URL da logo do sistema'],
                ['login_image_url', 'uploads/config/login_bg_1766856615.jpg', 'image', 'URL da imagem de fundo da tela de login'],
                ['nome_plataforma', 'getfy', 'text', NULL],
                ['logo_checkout_url', 'uploads/config/logo_checkout_1766928133.png', 'text', NULL],
                ['favicon_url', 'uploads/config/favicon_1766928139.png', 'text', NULL],
                ['github_repo', '', 'text', NULL],
                ['github_token', '', 'text', NULL],
                ['github_branch', 'main', 'text', NULL]
            ];
            
            $stmt = $pdo->prepare("INSERT INTO configuracoes_sistema (chave, valor, tipo, descricao) VALUES (?, ?, ?, ?)");
            foreach ($systemConfigs as $config) {
                try {
                    // Verificar se já existe
                    $checkStmt = $pdo->prepare("SELECT COUNT(*) as count FROM configuracoes_sistema WHERE chave = ?");
                    $checkStmt->execute([$config[0]]);
                    $exists = $checkStmt->fetch(PDO::FETCH_ASSOC)['count'] > 0;
                    
                    if (!$exists) {
                        $stmt->execute($config);
                    }
                } catch (PDOException $e) {
                    // Ignorar se já existe
                    if (strpos($e->getMessage(), 'Duplicate') === false) {
                        error_log("Erro ao inserir configuração do sistema {$config[0]}: " . $e->getMessage());
                    }
                }
            }
        }
        
    }
    
    /**
     * Reverte a migration (rollback)
     * @param PDO $pdo
     */
    public function down($pdo) {
        // Não remover dados iniciais no rollback, pois podem ser necessários
        // Se necessário, pode-se adicionar lógica específica aqui
    }
}

