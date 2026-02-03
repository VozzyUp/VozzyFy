<?php
/**
 * Funções Auxiliares do Sistema SaaS
 * 
 * Este arquivo contém todas as funções principais para gerenciamento do sistema SaaS
 */

if (!function_exists('saas_enabled')) {
    /**
     * Verifica se o sistema SaaS está habilitado
     * @return bool
     */
    function saas_enabled() {
        global $pdo;
        
        if (!isset($pdo)) {
            return false;
        }
        
        try {
            // Verifica se a tabela existe
            $stmt = $pdo->query("SHOW TABLES LIKE 'saas_config'");
            if ($stmt->rowCount() == 0) {
                return false;
            }
            
            $stmt = $pdo->prepare("SELECT enabled FROM saas_config LIMIT 1");
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return $result && (int)$result['enabled'] === 1;
        } catch (PDOException $e) {
            error_log("Erro ao verificar status SaaS: " . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('saas_enable')) {
    /**
     * Habilita o sistema SaaS
     * @return bool
     */
    function saas_enable() {
        global $pdo;
        
        if (!isset($pdo)) {
            return false;
        }
        
        try {
            // Verifica se já existe registro
            $stmt = $pdo->prepare("SELECT id FROM saas_config LIMIT 1");
            $stmt->execute();
            $exists = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($exists) {
                $stmt = $pdo->prepare("UPDATE saas_config SET enabled = 1");
            } else {
                $stmt = $pdo->prepare("INSERT INTO saas_config (enabled) VALUES (1)");
            }
            
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Erro ao habilitar SaaS: " . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('saas_disable')) {
    /**
     * Desabilita o sistema SaaS
     * @return bool
     */
    function saas_disable() {
        global $pdo;
        
        if (!isset($pdo)) {
            return false;
        }
        
        try {
            $stmt = $pdo->prepare("UPDATE saas_config SET enabled = 0");
            return $stmt->execute();
        } catch (PDOException $e) {
            error_log("Erro ao desabilitar SaaS: " . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('saas_get_user_plan')) {
    /**
     * Retorna o plano atual do usuário
     * @param int $usuario_id
     * @return array|null
     */
    function saas_get_user_plan($usuario_id) {
        global $pdo;
        
        if (!isset($pdo) || !$usuario_id) {
            return null;
        }
        
        try {
            $stmt = $pdo->prepare("
                SELECT sa.*, sp.id as plano_id, sp.nome as plano_nome, sp.preco, sp.periodo, sp.max_produtos, sp.max_pedidos_mes
                FROM saas_assinaturas sa
                JOIN saas_planos sp ON sa.plano_id = sp.id
                WHERE sa.usuario_id = ? 
                AND sa.status = 'ativo' 
                AND sa.data_vencimento >= CURDATE()
                ORDER BY sa.data_inicio DESC
                LIMIT 1
            ");
            $stmt->execute([$usuario_id]);
            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Erro ao buscar plano do usuário: " . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('saas_assign_free_plan')) {
    /**
     * Atribui plano free automaticamente ao usuário
     * @param int $usuario_id
     * @return bool
     */
    function saas_assign_free_plan($usuario_id) {
        global $pdo;
        
        if (!isset($pdo) || !$usuario_id) {
            return false;
        }
        
        try {
            // Verifica se já tem plano atribuído
            $plano_atual = saas_get_user_plan($usuario_id);
            if ($plano_atual) {
                return true; // Já tem plano
            }
            
            // Verifica se já foi atribuído anteriormente
            $stmt = $pdo->prepare("SELECT saas_plano_free_atribuido FROM usuarios WHERE id = ?");
            $stmt->execute([$usuario_id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user && (int)$user['saas_plano_free_atribuido'] === 1) {
                return true; // Já foi atribuído antes
            }
            
            // Busca plano free (is_free = 1 OU preco = 0)
            $stmt = $pdo->prepare("SELECT id FROM saas_planos WHERE (is_free = 1 OR preco = 0) AND ativo = 1 ORDER BY is_free DESC, preco ASC LIMIT 1");
            $stmt->execute();
            $plano_free = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$plano_free) {
                error_log("Nenhum plano free disponível para atribuir ao usuário ID: $usuario_id");
                return false; // Não há plano free disponível
            }
            
            // Calcula data de vencimento (30 dias para plano mensal)
            $data_inicio = date('Y-m-d');
            $data_vencimento = date('Y-m-d', strtotime('+30 days'));
            
            // Cria assinatura
            $stmt = $pdo->prepare("
                INSERT INTO saas_assinaturas 
                (usuario_id, plano_id, status, data_inicio, data_vencimento) 
                VALUES (?, ?, 'ativo', ?, ?)
            ");
            $stmt->execute([$usuario_id, $plano_free['id'], $data_inicio, $data_vencimento]);
            
            // Marca como atribuído
            $stmt = $pdo->prepare("UPDATE usuarios SET saas_plano_free_atribuido = 1 WHERE id = ?");
            $stmt->execute([$usuario_id]);
            
            return true;
        } catch (PDOException $e) {
            error_log("Erro ao atribuir plano free: " . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('saas_check_user_access')) {
    /**
     * Verifica se o usuário tem acesso (tem plano ativo)
     * @param int $usuario_id
     * @return bool
     */
    function saas_check_user_access($usuario_id) {
        global $pdo;
        
        if (!isset($pdo) || !$usuario_id) {
            return false;
        }
        
        // Se SaaS não está habilitado, todos têm acesso
        if (!saas_enabled()) {
            return true;
        }
        
        try {
            $plano = saas_get_user_plan($usuario_id);
            return $plano !== null;
        } catch (Exception $e) {
            error_log("Erro ao verificar acesso do usuário: " . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('saas_get_plan_limits')) {
    /**
     * Retorna os limites do plano
     * @param int $plano_id
     * @return array
     */
    function saas_get_plan_limits($plano_id) {
        global $pdo;
        
        if (!isset($pdo) || !$plano_id) {
            return ['max_produtos' => null, 'max_pedidos_mes' => null];
        }
        
        try {
            $stmt = $pdo->prepare("SELECT max_produtos, max_pedidos_mes FROM saas_planos WHERE id = ?");
            $stmt->execute([$plano_id]);
            $plano = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($plano) {
                return [
                    'max_produtos' => $plano['max_produtos'],
                    'max_pedidos_mes' => $plano['max_pedidos_mes']
                ];
            }
            
            return ['max_produtos' => null, 'max_pedidos_mes' => null];
        } catch (PDOException $e) {
            error_log("Erro ao buscar limites do plano: " . $e->getMessage());
            return ['max_produtos' => null, 'max_pedidos_mes' => null];
        }
    }
}

if (!function_exists('saas_get_user_limits')) {
    /**
     * Retorna os limites do plano atual do usuário
     * @param int $usuario_id
     * @return array
     */
    function saas_get_user_limits($usuario_id) {
        $plano = saas_get_user_plan($usuario_id);
        
        if (!$plano) {
            return ['max_produtos' => null, 'max_pedidos_mes' => null];
        }
        
        return saas_get_plan_limits($plano['plano_id']);
    }
}

