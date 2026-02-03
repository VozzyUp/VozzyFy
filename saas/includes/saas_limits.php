<?php
/**
 * Sistema de Limites SaaS
 * 
 * Funções para verificar e controlar limites de produtos e pedidos
 */

require_once __DIR__ . '/saas_functions.php';

if (!function_exists('saas_check_product_limit')) {
    /**
     * Verifica se o usuário pode criar um novo produto
     * @param int $usuario_id
     * @return array ['allowed' => bool, 'message' => string]
     */
    function saas_check_product_limit($usuario_id) {
        global $pdo;
        
        if (!saas_enabled()) {
            return ['allowed' => true, 'message' => ''];
        }
        
        $plano = saas_get_user_plan($usuario_id);
        
        if (!$plano) {
            return [
                'allowed' => false,
                'message' => 'Você precisa ter um plano ativo para criar produtos.'
            ];
        }
        
        $max_produtos = $plano['max_produtos'];
        
        // Se não há limite, permite
        if ($max_produtos === null) {
            return ['allowed' => true, 'message' => ''];
        }
        
        // Conta produtos criados
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM produtos WHERE usuario_id = ?");
            $stmt->execute([$usuario_id]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            $produtos_criados = (int)$result['total'];
            
            if ($produtos_criados >= $max_produtos) {
                return [
                    'allowed' => false,
                    'message' => "Você atingiu o limite de produtos do seu plano ({$max_produtos} produtos).",
                    'upgrade_url' => '/index?pagina=saas_planos'
                ];
            }
            
            return ['allowed' => true, 'message' => ''];
        } catch (PDOException $e) {
            error_log("Erro ao verificar limite de produtos: " . $e->getMessage());
            return ['allowed' => true, 'message' => '']; // Em caso de erro, permite
        }
    }
}

if (!function_exists('saas_check_order_limit')) {
    /**
     * Verifica se o usuário pode criar um novo pedido neste mês
     * @param int $usuario_id
     * @return array ['allowed' => bool, 'message' => string]
     */
    function saas_check_order_limit($usuario_id) {
        global $pdo;
        
        if (!saas_enabled()) {
            return ['allowed' => true, 'message' => ''];
        }
        
        $plano = saas_get_user_plan($usuario_id);
        
        // SEMPRE permite pedidos, mesmo sem plano ativo
        if (!$plano) {
            return ['allowed' => true, 'message' => ''];
        }
        
        $max_pedidos_mes = $plano['max_pedidos_mes'];
        
        // Se não há limite, permite
        if ($max_pedidos_mes === null) {
            return ['allowed' => true, 'message' => ''];
        }
        
        // Busca contador do mês atual
        $mes_ano = date('Y-m');
        
        try {
            $stmt = $pdo->prepare("
                SELECT pedidos_realizados 
                FROM saas_contadores_mensais 
                WHERE usuario_id = ? AND mes_ano = ?
            ");
            $stmt->execute([$usuario_id, $mes_ano]);
            $contador = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $pedidos_realizados = $contador ? (int)$contador['pedidos_realizados'] : 0;
            
            if ($pedidos_realizados >= $max_pedidos_mes) {
                return [
                    'allowed' => false,
                    'message' => "Você atingiu o limite de pedidos do seu plano neste mês ({$max_pedidos_mes} pedidos).",
                    'upgrade_url' => '/index?pagina=saas_planos'
                ];
            }
            
            return ['allowed' => true, 'message' => ''];
        } catch (PDOException $e) {
            error_log("Erro ao verificar limite de pedidos: " . $e->getMessage());
            return ['allowed' => true, 'message' => '']; // Em caso de erro, permite
        }
    }
}

if (!function_exists('saas_increment_order_count')) {
    /**
     * Incrementa o contador de pedidos do usuário no mês atual
     * @param int $usuario_id
     * @return bool
     */
    function saas_increment_order_count($usuario_id) {
        global $pdo;
        
        if (!saas_enabled()) {
            return true;
        }
        
        $mes_ano = date('Y-m');
        
        try {
            // Verifica se já existe contador para este mês
            $stmt = $pdo->prepare("
                SELECT id, pedidos_realizados 
                FROM saas_contadores_mensais 
                WHERE usuario_id = ? AND mes_ano = ?
            ");
            $stmt->execute([$usuario_id, $mes_ano]);
            $contador = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($contador) {
                // Atualiza contador existente
                $stmt = $pdo->prepare("
                    UPDATE saas_contadores_mensais 
                    SET pedidos_realizados = pedidos_realizados + 1,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                $stmt->execute([$contador['id']]);
            } else {
                // Cria novo contador
                $stmt = $pdo->prepare("
                    INSERT INTO saas_contadores_mensais 
                    (usuario_id, mes_ano, pedidos_realizados, produtos_criados) 
                    VALUES (?, ?, 1, 0)
                ");
                $stmt->execute([$usuario_id, $mes_ano]);
            }
            
            return true;
        } catch (PDOException $e) {
            error_log("Erro ao incrementar contador de pedidos: " . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('saas_increment_product_count')) {
    /**
     * Incrementa o contador de produtos do usuário
     * @param int $usuario_id
     * @return bool
     */
    function saas_increment_product_count($usuario_id) {
        global $pdo;
        
        if (!saas_enabled()) {
            return true;
        }
        
        $mes_ano = date('Y-m');
        
        try {
            // Verifica se já existe contador para este mês
            $stmt = $pdo->prepare("
                SELECT id, produtos_criados 
                FROM saas_contadores_mensais 
                WHERE usuario_id = ? AND mes_ano = ?
            ");
            $stmt->execute([$usuario_id, $mes_ano]);
            $contador = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($contador) {
                // Atualiza contador existente
                $stmt = $pdo->prepare("
                    UPDATE saas_contadores_mensais 
                    SET produtos_criados = produtos_criados + 1,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                $stmt->execute([$contador['id']]);
            } else {
                // Cria novo contador
                $stmt = $pdo->prepare("
                    INSERT INTO saas_contadores_mensais 
                    (usuario_id, mes_ano, pedidos_realizados, produtos_criados) 
                    VALUES (?, ?, 0, 1)
                ");
                $stmt->execute([$usuario_id, $mes_ano]);
            }
            
            return true;
        } catch (PDOException $e) {
            error_log("Erro ao incrementar contador de produtos: " . $e->getMessage());
            return false;
        }
    }
}


