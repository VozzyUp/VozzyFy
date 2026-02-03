<?php
/**
 * Sistema de Hooks/Actions para Plugins
 * Permite que plugins se integrem ao sistema existente
 */

if (!function_exists('register_hook')) {
    /**
     * Registra um hook/action
     * @param string $hook_name Nome do hook
     * @param callable $callback Função callback
     * @param int $priority Prioridade (menor = executa primeiro, padrão: 10)
     */
    function register_hook($hook_name, $callback, $priority = 10) {
        global $plugin_hooks;
        
        if (!isset($plugin_hooks)) {
            $plugin_hooks = [];
        }
        
        if (!isset($plugin_hooks[$hook_name])) {
            $plugin_hooks[$hook_name] = [];
        }
        
        $plugin_hooks[$hook_name][] = [
            'callback' => $callback,
            'priority' => $priority
        ];
        
        // Ordena por prioridade
        usort($plugin_hooks[$hook_name], function($a, $b) {
            return $a['priority'] - $b['priority'];
        });
    }
}

if (!function_exists('do_action')) {
    /**
     * Executa todos os hooks registrados para uma ação
     * @param string $hook_name Nome do hook
     * @param mixed ...$args Argumentos para passar aos callbacks
     * @return mixed Retorna o último valor retornado ou null
     */
    function do_action($hook_name, ...$args) {
        global $plugin_hooks;
        
        if (!isset($plugin_hooks) || !isset($plugin_hooks[$hook_name])) {
            return null;
        }
        
        $result = null;
        foreach ($plugin_hooks[$hook_name] as $hook) {
            if (is_callable($hook['callback'])) {
                try {
                    $result = call_user_func_array($hook['callback'], $args);
                } catch (Exception $e) {
                    error_log("Erro ao executar hook '{$hook_name}': " . $e->getMessage());
                    error_log("Stack trace: " . $e->getTraceAsString());
                    // Continua executando outros hooks mesmo se um falhar
                    continue;
                } catch (Error $e) {
                    error_log("Erro fatal ao executar hook '{$hook_name}': " . $e->getMessage());
                    error_log("Stack trace: " . $e->getTraceAsString());
                    // Continua executando outros hooks mesmo se um falhar
                    continue;
                }
            }
        }
        
        return $result;
    }
}

if (!function_exists('apply_filters')) {
    /**
     * Aplica filtros a um valor (hooks que modificam dados)
     * @param string $filter_name Nome do filtro
     * @param mixed $value Valor a ser filtrado
     * @param mixed ...$args Argumentos adicionais
     * @return mixed Valor filtrado
     */
    function apply_filters($filter_name, $value, ...$args) {
        global $plugin_hooks;
        
        if (!isset($plugin_hooks) || !isset($plugin_hooks[$filter_name])) {
            return $value;
        }
        
        foreach ($plugin_hooks[$filter_name] as $hook) {
            if (is_callable($hook['callback'])) {
                try {
                    $value = call_user_func_array($hook['callback'], array_merge([$value], $args));
                } catch (Exception $e) {
                    error_log("Erro ao executar filter '{$filter_name}': " . $e->getMessage());
                    error_log("Stack trace: " . $e->getTraceAsString());
                    // Retorna valor sem modificar se o filter falhar
                    break;
                } catch (Error $e) {
                    error_log("Erro fatal ao executar filter '{$filter_name}': " . $e->getMessage());
                    error_log("Stack trace: " . $e->getTraceAsString());
                    // Retorna valor sem modificar se o filter falhar
                    break;
                }
            }
        }
        
        return $value;
    }
}

if (!function_exists('remove_hook')) {
    /**
     * Remove um hook específico
     * @param string $hook_name Nome do hook
     * @param callable $callback Callback a remover
     */
    function remove_hook($hook_name, $callback) {
        global $plugin_hooks;
        
        if (!isset($plugin_hooks) || !isset($plugin_hooks[$hook_name])) {
            return;
        }
        
        $plugin_hooks[$hook_name] = array_filter($plugin_hooks[$hook_name], function($hook) use ($callback) {
            return $hook['callback'] !== $callback;
        });
    }
}

// Alias para compatibilidade (add_action = register_hook)
if (!function_exists('add_action')) {
    /**
     * Alias para register_hook (compatibilidade WordPress-style)
     * @param string $hook_name Nome do hook
     * @param callable $callback Função callback
     * @param int $priority Prioridade (menor = executa primeiro, padrão: 10)
     */
    function add_action($hook_name, $callback, $priority = 10) {
        return register_hook($hook_name, $callback, $priority);
    }
}

// Alias para compatibilidade (add_filter = register_hook para filters)
if (!function_exists('add_filter')) {
    /**
     * Alias para register_hook (compatibilidade WordPress-style para filters)
     * @param string $filter_name Nome do filtro
     * @param callable $callback Função callback
     * @param int $priority Prioridade (menor = executa primeiro, padrão: 10)
     */
    function add_filter($filter_name, $callback, $priority = 10) {
        return register_hook($filter_name, $callback, $priority);
    }
}

if (!function_exists('remove_filter')) {
    /**
     * Remove um filtro específico
     * @param string $filter_name Nome do filtro
     * @param callable $callback Callback a remover
     * @param int $priority Prioridade do hook (opcional)
     */
    function remove_filter($filter_name, $callback, $priority = null) {
        return remove_hook($filter_name, $callback);
    }
}

if (!function_exists('has_action')) {
    /**
     * Verifica se um action/hook existe e tem callbacks registrados
     * @param string $hook_name Nome do hook
     * @param callable|null $callback Callback específico para verificar (opcional)
     * @return bool|int Retorna true se existir, false se não existir, ou número de callbacks se callback específico
     */
    function has_action($hook_name, $callback = null) {
        global $plugin_hooks;
        
        if (!isset($plugin_hooks) || !isset($plugin_hooks[$hook_name])) {
            return false;
        }
        
        if ($callback === null) {
            return count($plugin_hooks[$hook_name]) > 0;
        }
        
        foreach ($plugin_hooks[$hook_name] as $hook) {
            if ($hook['callback'] === $callback) {
                return true;
            }
        }
        
        return false;
    }
}

if (!function_exists('has_filter')) {
    /**
     * Verifica se um filter existe e tem callbacks registrados
     * @param string $filter_name Nome do filtro
     * @param callable|null $callback Callback específico para verificar (opcional)
     * @return bool|int Retorna true se existir, false se não existir, ou número de callbacks se callback específico
     */
    function has_filter($filter_name, $callback = null) {
        return has_action($filter_name, $callback);
    }
}

