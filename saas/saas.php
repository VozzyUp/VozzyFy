<?php
/**
 * Módulo Principal SaaS
 * 
 * Este arquivo carrega todas as funções e integrações do sistema SaaS
 */

// Carrega funções auxiliares
require_once __DIR__ . '/includes/saas_functions.php';
require_once __DIR__ . '/includes/saas_limits.php';

// Carrega hooks se o sistema de hooks estiver disponível
if (function_exists('add_action')) {
    // Hook para verificar limite antes de criar produto
    add_action('before_create_product', function($usuario_id) {
        if (!saas_enabled()) {
            return ['allowed' => true];
        }
        
        if (!$usuario_id) {
            return ['allowed' => false, 'message' => 'Usuário não identificado'];
        }
        
        return saas_check_product_limit($usuario_id);
    });
    
    // Hook para incrementar contador após criar produto
    add_action('after_create_product', function($produto_id, $usuario_id) {
        if (saas_enabled() && $usuario_id) {
            saas_increment_product_count($usuario_id);
        }
    });
    
    // Hook para verificar limite antes de criar venda
    add_action('before_create_venda', function($produto_id) {
        if (!saas_enabled()) {
            return ['allowed' => true];
        }
        
        // CORREÇÃO: No checkout público, SEMPRE buscar usuario_id do produto
        // O checkout é público, então não devemos usar $_SESSION['id'] do comprador
        // O limite deve ser verificado para o DONO DO PRODUTO (infoprodutor), não para o comprador
        $usuario_id = 0;
        
        // Sempre buscar usuario_id do produto (ignorando sessão do comprador)
        if ($produto_id) {
            // Tenta acessar $pdo via global ou $GLOBALS
            $pdo = null;
            if (isset($GLOBALS['pdo']) && $GLOBALS['pdo']) {
                $pdo = $GLOBALS['pdo'];
            } elseif (function_exists('get_db_connection')) {
                // Se houver função helper para conexão
                try {
                    $pdo = get_db_connection();
                } catch (Exception $e) {
                    error_log("Erro ao obter conexão DB no hook before_create_venda: " . $e->getMessage());
                }
            }
            
            if ($pdo) {
                try {
                    $stmt = $pdo->prepare("SELECT usuario_id FROM produtos WHERE id = ?");
                    $stmt->execute([$produto_id]);
                    $produto = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($produto && !empty($produto['usuario_id'])) {
                        $usuario_id = $produto['usuario_id'];
                    }
                } catch (Exception $e) {
                    error_log("Erro ao buscar usuario_id do produto no hook before_create_venda: " . $e->getMessage());
                }
            } else {
                // Se não conseguir acessar $pdo, não bloquear o checkout
                // Retorna allowed=true para não bloquear o checkout público
                error_log("AVISO: Não foi possível acessar \$pdo no hook before_create_venda. Permitindo checkout público.");
                return ['allowed' => true];
            }
        }
        
        if (!$usuario_id) {
            // CORREÇÃO: Não bloquear checkout público se não conseguir identificar usuário
            // Retorna allowed=true para permitir checkout público funcionar
            error_log("AVISO: Usuário não identificado no hook before_create_venda, mas permitindo checkout público.");
            return ['allowed' => true];
        }
        
        // Verifica limite para o DONO DO PRODUTO (infoprodutor), não para o comprador
        return saas_check_order_limit($usuario_id);
    });
    
    // Hook para incrementar contador após criar venda
    add_action('after_create_venda', function($produto_id) {
        if (saas_enabled()) {
            $usuario_id = $_SESSION['id'] ?? 0;
            if ($usuario_id) {
                saas_increment_order_count($usuario_id);
            }
        }
    });
}

