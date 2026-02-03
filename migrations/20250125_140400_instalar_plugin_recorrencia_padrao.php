<?php
/**
 * Migration: Instalar e ativar plugin de recorrência por padrão
 * Versão mínima: 1.0.0
 * 
 * Esta migration instala e ativa automaticamente o plugin de recorrência
 * durante a instalação inicial ou atualização da plataforma.
 */

class Migration_20250125_140400_instalar_plugin_recorrencia_padrao {
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
        // 1. Verificar se tabela plugins existe
        $stmt = $pdo->query("SHOW TABLES LIKE 'plugins'");
        if ($stmt->rowCount() == 0) {
            error_log("MIGRATION RECORRENCIA: Tabela 'plugins' não existe. Migration não pode ser executada.");
            throw new Exception("Tabela 'plugins' não existe. Execute a migration que cria a tabela plugins primeiro.");
        }
        
        // 2. Verificar se plugin já está instalado
        $stmt = $pdo->prepare("SELECT id, ativo FROM plugins WHERE pasta = ?");
        $stmt->execute(['recorrencia']);
        $plugin_existente = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($plugin_existente) {
            // Plugin já está instalado
            if ($plugin_existente['ativo'] == 0) {
                // Ativar se estiver inativo
                $stmt_update = $pdo->prepare("UPDATE plugins SET ativo = 1 WHERE id = ?");
                $stmt_update->execute([$plugin_existente['id']]);
                error_log("MIGRATION RECORRENCIA: Plugin 'recorrencia' já estava instalado e foi ativado.");
            } else {
                error_log("MIGRATION RECORRENCIA: Plugin 'recorrencia' já está instalado e ativo.");
            }
            return; // Não precisa fazer mais nada
        }
        
        // 3. Verificar se arquivos do plugin existem
        $plugin_dir = __DIR__ . '/../plugins/recorrencia';
        $plugin_file = $plugin_dir . '/recorrencia.php';
        $plugin_json = $plugin_dir . '/plugin.json';
        $install_file = $plugin_dir . '/install.php';
        
        if (!is_dir($plugin_dir)) {
            error_log("MIGRATION RECORRENCIA: AVISO - Diretório do plugin não encontrado: {$plugin_dir}");
            error_log("MIGRATION RECORRENCIA: Plugin será instalado quando os arquivos estiverem disponíveis.");
            return; // Não falha a migration, apenas registra aviso
        }
        
        if (!file_exists($plugin_file)) {
            error_log("MIGRATION RECORRENCIA: AVISO - Arquivo principal do plugin não encontrado: {$plugin_file}");
            error_log("MIGRATION RECORRENCIA: Plugin será instalado quando os arquivos estiverem disponíveis.");
            return; // Não falha a migration, apenas registra aviso
        }
        
        if (!file_exists($plugin_json)) {
            error_log("MIGRATION RECORRENCIA: AVISO - Arquivo plugin.json não encontrado: {$plugin_json}");
            error_log("MIGRATION RECORRENCIA: Usando valores padrão para instalação.");
            $plugin_nome = 'Recorrência';
            $plugin_versao = '1.0.0';
        } else {
            // 4. Ler plugin.json para obter nome e versão
            $plugin_data = json_decode(file_get_contents($plugin_json), true);
            if ($plugin_data === null || !isset($plugin_data['nome'])) {
                error_log("MIGRATION RECORRENCIA: AVISO - plugin.json inválido ou sem nome. Usando valores padrão.");
                $plugin_nome = 'Recorrência';
                $plugin_versao = $plugin_data['versao'] ?? '1.0.0';
            } else {
                $plugin_nome = $plugin_data['nome'];
                $plugin_versao = $plugin_data['versao'] ?? '1.0.0';
            }
        }
        
        // 5. Inserir registro na tabela plugins com ativo = 1
        try {
            $stmt_insert = $pdo->prepare("INSERT INTO plugins (nome, pasta, versao, ativo) VALUES (?, ?, ?, 1)");
            $stmt_insert->execute([$plugin_nome, 'recorrencia', $plugin_versao]);
            $plugin_id = $pdo->lastInsertId();
            error_log("MIGRATION RECORRENCIA: Plugin 'recorrencia' inserido no banco de dados (ID: {$plugin_id}).");
        } catch (PDOException $e) {
            // Se falhar por duplicação (race condition), verificar novamente
            if ($e->getCode() == 23000 || strpos($e->getMessage(), 'Duplicate') !== false) {
                error_log("MIGRATION RECORRENCIA: Plugin já foi inserido (possível race condition). Verificando status...");
                $stmt = $pdo->prepare("SELECT id, ativo FROM plugins WHERE pasta = ?");
                $stmt->execute(['recorrencia']);
                $plugin_existente = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($plugin_existente) {
                    if ($plugin_existente['ativo'] == 0) {
                        $stmt_update = $pdo->prepare("UPDATE plugins SET ativo = 1 WHERE id = ?");
                        $stmt_update->execute([$plugin_existente['id']]);
                        error_log("MIGRATION RECORRENCIA: Plugin ativado após race condition.");
                    }
                    return;
                }
            }
            throw $e;
        }
        
        // 6. Executar install.php se existir
        if (file_exists($install_file)) {
            try {
                // Define constante para contexto de instalação
                if (!defined('PLUGIN_INSTALL')) {
                    define('PLUGIN_INSTALL', true);
                }
                
                // Captura qualquer output do install.php
                ob_start();
                try {
                    require_once $install_file;
                    $output = ob_get_clean();
                    if (!empty($output)) {
                        error_log("MIGRATION RECORRENCIA: Output do install.php: " . $output);
                    }
                    error_log("MIGRATION RECORRENCIA: install.php executado com sucesso.");
                } catch (Exception $e) {
                    ob_end_clean();
                    // Se install.php falhar, remover registro do banco
                    $stmt_delete = $pdo->prepare("DELETE FROM plugins WHERE id = ?");
                    $stmt_delete->execute([$plugin_id]);
                    error_log("MIGRATION RECORRENCIA: ERRO ao executar install.php - " . $e->getMessage());
                    error_log("MIGRATION RECORRENCIA: Registro do plugin removido do banco de dados.");
                    throw new Exception("Erro ao executar install.php do plugin de recorrência: " . $e->getMessage());
                } catch (Error $e) {
                    ob_end_clean();
                    // Se install.php falhar, remover registro do banco
                    $stmt_delete = $pdo->prepare("DELETE FROM plugins WHERE id = ?");
                    $stmt_delete->execute([$plugin_id]);
                    error_log("MIGRATION RECORRENCIA: ERRO FATAL ao executar install.php - " . $e->getMessage());
                    error_log("MIGRATION RECORRENCIA: Registro do plugin removido do banco de dados.");
                    throw new Exception("Erro fatal ao executar install.php do plugin de recorrência: " . $e->getMessage());
                }
            } catch (PDOException $e) {
                // Se install.php falhar, remover registro do banco
                $stmt_delete = $pdo->prepare("DELETE FROM plugins WHERE id = ?");
                $stmt_delete->execute([$plugin_id]);
                error_log("MIGRATION RECORRENCIA: ERRO PDO ao executar install.php - " . $e->getMessage());
                error_log("MIGRATION RECORRENCIA: Registro do plugin removido do banco de dados.");
                throw new Exception("Erro PDO ao executar install.php do plugin de recorrência: " . $e->getMessage());
            }
        } else {
            error_log("MIGRATION RECORRENCIA: AVISO - install.php não encontrado. Plugin instalado sem executar script de instalação.");
        }
        
        error_log("MIGRATION RECORRENCIA: Plugin 'recorrencia' instalado e ativado com sucesso.");
    }
    
    /**
     * Reverte a migration (rollback)
     * @param PDO $pdo
     */
    public function down($pdo) {
        // Desativar plugin (não remover)
        $stmt = $pdo->prepare("UPDATE plugins SET ativo = 0 WHERE pasta = ?");
        $stmt->execute(['recorrencia']);
        
        if ($stmt->rowCount() > 0) {
            error_log("MIGRATION RECORRENCIA: Plugin 'recorrencia' desativado (rollback).");
        } else {
            error_log("MIGRATION RECORRENCIA: Plugin 'recorrencia' não encontrado para desativar (rollback).");
        }
    }
}

