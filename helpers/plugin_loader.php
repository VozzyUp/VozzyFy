<?php
/**
 * Carregador de Plugins
 * Carrega e inicializa plugins ativos
 */

if (!function_exists('load_active_plugins')) {
    /**
     * Carrega todos os plugins ativos
     */
    function load_active_plugins() {
        global $pdo;
        
        if (!isset($pdo)) {
            return;
        }
        
        try {
            $stmt = $pdo->prepare("SELECT pasta, nome FROM plugins WHERE ativo = 1");
            $stmt->execute();
            $plugins = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($plugins as $plugin) {
                $plugin_file = __DIR__ . '/../plugins/' . $plugin['pasta'] . '/' . $plugin['pasta'] . '.php';
                
                if (file_exists($plugin_file)) {
                    try {
                        if (!defined('PLUGIN_LOADED')) {
                            define('PLUGIN_LOADED', true);
                        }
                        error_log("Plugin Loader: Carregando plugin '{$plugin['nome']}' ({$plugin['pasta']})");
                        require_once $plugin_file;
                        error_log("Plugin Loader: Plugin '{$plugin['nome']}' carregado com sucesso");
                    } catch (Exception $e) {
                        error_log("Erro ao carregar plugin '{$plugin['nome']}' ({$plugin['pasta']}): " . $e->getMessage());
                        error_log("Stack trace: " . $e->getTraceAsString());
                        // Continua carregando outros plugins mesmo se um falhar
                        continue;
                    } catch (Error $e) {
                        error_log("Erro fatal ao carregar plugin '{$plugin['nome']}' ({$plugin['pasta']}): " . $e->getMessage());
                        error_log("Stack trace: " . $e->getTraceAsString());
                        // Continua carregando outros plugins mesmo se um falhar
                        continue;
                    }
                }
            }
            
            // Executa hook após todos os plugins serem carregados
            if (function_exists('do_action')) {
                do_action('plugins_loaded', $plugins);
            }
        } catch (PDOException $e) {
            error_log("Erro ao carregar plugins: " . $e->getMessage());
        }
    }
}

if (!function_exists('plugin_active')) {
    /**
     * Verifica se um plugin está ativo
     * @param string $plugin_pasta Nome da pasta do plugin
     * @return bool
     */
    function plugin_active($plugin_pasta) {
        global $pdo;
        
        if (!isset($pdo)) {
            return false;
        }
        
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM plugins WHERE pasta = ? AND ativo = 1");
            $stmt->execute([$plugin_pasta]);
            return $stmt->fetchColumn() > 0;
        } catch (PDOException $e) {
            error_log("Erro ao verificar plugin: " . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('get_plugin_info')) {
    /**
     * Obtém informações de um plugin
     * @param string $plugin_pasta Nome da pasta do plugin
     * @return array|null
     */
    function get_plugin_info($plugin_pasta) {
        global $pdo;
        
        if (!isset($pdo)) {
            return null;
        }
        
        try {
            $stmt = $pdo->prepare("SELECT * FROM plugins WHERE pasta = ?");
            $stmt->execute([$plugin_pasta]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Erro ao obter info do plugin: " . $e->getMessage());
            return null;
        }
    }
}

// Carrega plugins ativos automaticamente quando este arquivo é incluído
// Mas só se o PDO estiver disponível (após config.php)
if (isset($pdo)) {
    load_active_plugins();
}

