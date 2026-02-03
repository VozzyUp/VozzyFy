<?php
/**
 * Sistema de Rotas para Plugins
 * 
 * Permite que plugins registrem rotas customizadas sem modificar o código fonte principal
 */

if (!function_exists('register_plugin_route')) {
    /**
     * Registra uma nova rota para um plugin
     * 
     * @param string $path Caminho da rota (ex: '/meu-plugin/pagina')
     * @param callable|string $callback Função callback ou string 'Classe::metodo'
     * @param string $access_level Nível de acesso: 'public', 'user', 'admin' (padrão: 'user')
     * @param string $plugin_name Nome do plugin (usado para identificar a rota)
     * @return bool Sucesso ou falha
     */
    function register_plugin_route($path, $callback, $access_level = 'user', $plugin_name = '') {
        global $plugin_routes;
        
        if (!isset($plugin_routes)) {
            $plugin_routes = [];
        }
        
        // Normalizar path (garantir que comece com /)
        $path = '/' . ltrim($path, '/');
        
        $plugin_routes[$path] = [
            'callback' => $callback,
            'access_level' => $access_level,
            'plugin_name' => $plugin_name,
            'registered_at' => time()
        ];
        
        return true;
    }
}

if (!function_exists('get_plugin_routes')) {
    /**
     * Obtém todas as rotas registradas por plugins
     * 
     * @return array Array de rotas registradas
     */
    function get_plugin_routes() {
        global $plugin_routes;
        return isset($plugin_routes) ? $plugin_routes : [];
    }
}

if (!function_exists('get_plugin_route')) {
    /**
     * Obtém uma rota específica por path
     * 
     * @param string $path Caminho da rota
     * @return array|null Dados da rota ou null se não existir
     */
    function get_plugin_route($path) {
        global $plugin_routes;
        
        if (!isset($plugin_routes) || !isset($plugin_routes[$path])) {
            return null;
        }
        
        return $plugin_routes[$path];
    }
}

if (!function_exists('handle_plugin_route')) {
    /**
     * Processa uma rota de plugin se ela existir
     * 
     * @param string $request_path Caminho da requisição atual
     * @return bool true se a rota foi processada, false caso contrário
     */
    function handle_plugin_route($request_path) {
        global $plugin_routes;
        
        if (!isset($plugin_routes)) {
            return false;
        }
        
        // Normalizar path
        $request_path = '/' . ltrim($request_path, '/');
        
        // Tentar match exato primeiro
        if (isset($plugin_routes[$request_path])) {
            $route = $plugin_routes[$request_path];
            return execute_plugin_route($route, $request_path);
        }
        
        // Tentar match com parâmetros (pattern simples)
        foreach ($plugin_routes as $route_path => $route) {
            $pattern = str_replace('/', '\/', $route_path);
            $pattern = preg_replace('/\{(\w+)\}/', '([^\/]+)', $pattern);
            $pattern = '/^' . $pattern . '$/';
            
            if (preg_match($pattern, $request_path, $matches)) {
                // Extrair parâmetros nomeados
                $params = [];
                if (preg_match_all('/\{(\w+)\}/', $route_path, $param_names)) {
                    for ($i = 0; $i < count($param_names[1]); $i++) {
                        $params[$param_names[1][$i]] = $matches[$i + 1] ?? null;
                    }
                }
                
                $_GET['route_params'] = $params;
                return execute_plugin_route($route, $request_path, $params);
            }
        }
        
        return false;
    }
}

if (!function_exists('execute_plugin_route')) {
    /**
     * Executa uma rota de plugin
     * 
     * @param array $route Dados da rota
     * @param string $path Caminho da requisição
     * @param array $params Parâmetros extraídos do path (opcional)
     * @return bool true se executada com sucesso
     */
    function execute_plugin_route($route, $path, $params = []) {
        // Verificar acesso
        $access_level = $route['access_level'] ?? 'user';
        
        if ($access_level === 'admin') {
            if (!isset($_SESSION['tipo']) || $_SESSION['tipo'] !== 'admin') {
                http_response_code(403);
                echo json_encode(['error' => 'Acesso negado. Apenas administradores podem acessar esta rota.']);
                return false;
            }
        } elseif ($access_level === 'user') {
            if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
                http_response_code(401);
                echo json_encode(['error' => 'Acesso negado. Você precisa estar logado.']);
                return false;
            }
        }
        // 'public' não requer verificação
        
        // Executar callback
        $callback = $route['callback'];
        
        if (is_callable($callback)) {
            try {
                call_user_func($callback, $path, $params);
                return true;
            } catch (Exception $e) {
                error_log("Erro ao executar rota de plugin '{$path}': " . $e->getMessage());
                http_response_code(500);
                echo json_encode(['error' => 'Erro ao processar requisição']);
                return false;
            }
        } elseif (is_string($callback) && strpos($callback, '::') !== false) {
            // Callback estático estilo 'Classe::metodo'
            list($class, $method) = explode('::', $callback);
            
            if (class_exists($class) && method_exists($class, $method)) {
                try {
                    call_user_func([$class, $method], $path, $params);
                    return true;
                } catch (Exception $e) {
                    error_log("Erro ao executar rota de plugin '{$path}': " . $e->getMessage());
                    http_response_code(500);
                    echo json_encode(['error' => 'Erro ao processar requisição']);
                    return false;
                }
            }
        }
        
        error_log("Callback inválido para rota de plugin '{$path}'");
        return false;
    }
}

// Carregar helpers necessários
if (file_exists(__DIR__ . '/plugin_db_helper.php')) {
    require_once __DIR__ . '/plugin_db_helper.php';
}
?>

