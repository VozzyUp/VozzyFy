<?php
/**
 * Helper para Sistema de Conquistas
 * Funções para cálculo de faturamento, verificação e atribuição de conquistas
 */

/**
 * Calcula o faturamento lifetime (total histórico) de um infoprodutor
 * @param int $usuario_id ID do usuário/infoprodutor
 * @return float Faturamento total em reais
 */
function calcular_faturamento_lifetime($usuario_id) {
    global $pdo;
    
    if (!isset($pdo) || !$usuario_id) {
        return 0.00;
    }
    
    try {
        // Soma todas as vendas aprovadas dos produtos do infoprodutor
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(v.valor), 0) as faturamento_total
            FROM vendas v
            INNER JOIN produtos p ON v.produto_id = p.id
            WHERE p.usuario_id = ? 
            AND v.status_pagamento = 'approved'
        ");
        $stmt->execute([$usuario_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return (float)($result['faturamento_total'] ?? 0.00);
    } catch (PDOException $e) {
        error_log("Erro ao calcular faturamento lifetime: " . $e->getMessage());
        return 0.00;
    }
}

/**
 * Obtém a conquista atual (última conquista atingida) do usuário
 * @param int $usuario_id ID do usuário
 * @return array|false Array com dados da conquista ou false se não houver
 */
function obter_conquista_atual($usuario_id) {
    global $pdo;
    
    if (!isset($pdo) || !$usuario_id) {
        return false;
    }
    
    $faturamento_atual = calcular_faturamento_lifetime($usuario_id);
    
    try {
        // Busca a conquista com maior valor_maximo que o usuário já atingiu
        $stmt = $pdo->prepare("
            SELECT c.*, uc.data_conquista, uc.faturamento_atingido
            FROM conquistas c
            INNER JOIN usuario_conquistas uc ON c.id = uc.conquista_id
            WHERE uc.usuario_id = ?
            AND c.is_active = 1
            ORDER BY c.valor_maximo DESC, c.ordem DESC
        ");
        $stmt->execute([$usuario_id]);
        $conquistas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Valida cada conquista para garantir que o faturamento realmente atingiu o valor_maximo
        foreach ($conquistas as $conquista) {
            $valor_maximo = $conquista['valor_maximo'] ? (float)$conquista['valor_maximo'] : null;
            
            if ($valor_maximo !== null) {
                // Conquista com faixa: precisa ter atingido o valor_maximo
                if ($faturamento_atual >= $valor_maximo) {
                    // Faturamento atingiu o valor_maximo, retorna esta conquista
                    return $conquista;
                } else {
                    // Faturamento não atingiu, remove registro incorreto e continua procurando
                    try {
                        $stmt_remove = $pdo->prepare("DELETE FROM usuario_conquistas WHERE usuario_id = ? AND conquista_id = ?");
                        $stmt_remove->execute([$usuario_id, $conquista['id']]);
                        error_log("Conquista removida (atribuída incorretamente): Usuário #{$usuario_id} - Conquista #{$conquista['id']}");
                    } catch (PDOException $e) {
                        error_log("Erro ao remover conquista incorreta: " . $e->getMessage());
                    }
                }
            } else {
                // Última conquista (sem valor_maximo): precisa ter atingido o valor_minimo
                $valor_minimo = (float)$conquista['valor_minimo'];
                if ($faturamento_atual >= $valor_minimo) {
                    // Faturamento atingiu o valor_minimo, retorna esta conquista
                    return $conquista;
                } else {
                    // Faturamento não atingiu, remove registro incorreto e continua procurando
                    try {
                        $stmt_remove = $pdo->prepare("DELETE FROM usuario_conquistas WHERE usuario_id = ? AND conquista_id = ?");
                        $stmt_remove->execute([$usuario_id, $conquista['id']]);
                        error_log("Conquista removida (atribuída incorretamente): Usuário #{$usuario_id} - Conquista #{$conquista['id']}");
                    } catch (PDOException $e) {
                        error_log("Erro ao remover conquista incorreta: " . $e->getMessage());
                    }
                }
            }
        }
        
        return false;
    } catch (PDOException $e) {
        error_log("Erro ao obter conquista atual: " . $e->getMessage());
        return false;
    }
}

/**
 * Obtém a próxima conquista a ser atingida pelo usuário
 * Retorna a conquista que o usuário ainda não atingiu completamente
 * Pode ser uma conquista que ele está progredindo dentro da faixa OU a próxima que ainda não começou
 * @param int $usuario_id ID do usuário
 * @return array|false Array com dados da próxima conquista ou false se não houver
 */
function obter_proxima_conquista($usuario_id) {
    global $pdo;
    
    if (!isset($pdo) || !$usuario_id) {
        return false;
    }
    
    $faturamento_atual = calcular_faturamento_lifetime($usuario_id);
    
    try {
        // Primeiro, tenta encontrar uma conquista onde o faturamento está DENTRO da faixa mas ainda não completou
        // (valor_minimo <= faturamento < valor_maximo E não foi conquistada ainda)
        $stmt_em_progresso = $pdo->prepare("
            SELECT c.*
            FROM conquistas c
            WHERE c.is_active = 1
            AND c.valor_minimo <= ?
            AND (c.valor_maximo IS NULL OR ? < c.valor_maximo)
            AND NOT EXISTS (
                SELECT 1 FROM usuario_conquistas uc 
                WHERE uc.usuario_id = ? AND uc.conquista_id = c.id
            )
            ORDER BY c.valor_minimo DESC, c.ordem DESC
            LIMIT 1
        ");
        $stmt_em_progresso->execute([$faturamento_atual, $faturamento_atual, $usuario_id]);
        $conquista_em_progresso = $stmt_em_progresso->fetch(PDO::FETCH_ASSOC);
        
        if ($conquista_em_progresso) {
            return $conquista_em_progresso;
        }
        
        // Se não encontrou conquista em progresso, busca a primeira conquista que ainda não começou
        // (valor_minimo > faturamento E não foi conquistada)
        $stmt_proxima = $pdo->prepare("
            SELECT c.*
            FROM conquistas c
            WHERE c.is_active = 1
            AND c.valor_minimo > ?
            AND NOT EXISTS (
                SELECT 1 FROM usuario_conquistas uc 
                WHERE uc.usuario_id = ? AND uc.conquista_id = c.id
            )
            ORDER BY c.valor_minimo ASC, c.ordem ASC
            LIMIT 1
        ");
        $stmt_proxima->execute([$faturamento_atual, $usuario_id]);
        $conquista = $stmt_proxima->fetch(PDO::FETCH_ASSOC);
        
        return $conquista ? $conquista : false;
    } catch (PDOException $e) {
        error_log("Erro ao obter próxima conquista: " . $e->getMessage());
        return false;
    }
}

/**
 * Calcula o progresso percentual para uma conquista específica
 * @param int $usuario_id ID do usuário
 * @param int $conquista_id ID da conquista
 * @return array Array com 'progresso' (0-100) e 'faltante' (valor em reais)
 */
function calcular_progresso_conquista($usuario_id, $conquista_id) {
    global $pdo;
    
    if (!isset($pdo) || !$usuario_id || !$conquista_id) {
        return ['progresso' => 0, 'faltante' => 0];
    }
    
    $faturamento_atual = calcular_faturamento_lifetime($usuario_id);
    
    try {
        // Busca dados da conquista
        $stmt = $pdo->prepare("SELECT valor_minimo, valor_maximo FROM conquistas WHERE id = ?");
        $stmt->execute([$conquista_id]);
        $conquista = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$conquista) {
            return ['progresso' => 0, 'faltante' => 0];
        }
        
        $valor_minimo = (float)$conquista['valor_minimo'];
        $valor_maximo = $conquista['valor_maximo'] ? (float)$conquista['valor_maximo'] : null;
        
        // Buscar conquista anterior (ponto de partida para o progresso)
        // Primeiro tenta buscar a conquista atual do usuário (última conquista atingida)
        $stmt_atual = $pdo->prepare("
            SELECT c.valor_maximo 
            FROM conquistas c
            INNER JOIN usuario_conquistas uc ON c.id = uc.conquista_id
            WHERE uc.usuario_id = ?
            AND c.is_active = 1
            ORDER BY c.valor_maximo DESC, c.ordem DESC
            LIMIT 1
        ");
        $stmt_atual->execute([$usuario_id]);
        $atual = $stmt_atual->fetch(PDO::FETCH_ASSOC);
        
        // Se não tem conquista atual, busca a última conquista antes da próxima (por valor_maximo)
        if (!$atual || !$atual['valor_maximo']) {
            $stmt_anterior = $pdo->prepare("
                SELECT valor_maximo 
                FROM conquistas 
                WHERE is_active = 1 
                AND valor_maximo < ? 
                ORDER BY valor_maximo DESC 
                LIMIT 1
            ");
            $stmt_anterior->execute([$valor_minimo]);
            $anterior = $stmt_anterior->fetch(PDO::FETCH_ASSOC);
            $valor_base = $anterior && $anterior['valor_maximo'] ? (float)$anterior['valor_maximo'] : 0;
        } else {
            $valor_base = (float)$atual['valor_maximo'];
        }
        
        // Se não tem valor_maximo na próxima conquista, usar apenas valor_minimo como meta
        if ($valor_maximo === null) {
            // Última conquista - progresso baseado em valor_base até valor_minimo
            if ($faturamento_atual >= $valor_minimo) {
                return ['progresso' => 100, 'faltante' => 0];
            }
            $intervalo = $valor_minimo - $valor_base;
            if ($intervalo > 0) {
                $progresso = (($faturamento_atual - $valor_base) / $intervalo) * 100;
                return ['progresso' => min(100, max(0, $progresso)), 'faltante' => $valor_minimo - $faturamento_atual];
            }
            return ['progresso' => 0, 'faltante' => $valor_minimo - $faturamento_atual];
        }
        
        // Conquista com faixa definida (valor_minimo até valor_maximo)
        // Se já passou do valor_maximo, já atingiu (100%)
        if ($faturamento_atual >= $valor_maximo) {
            return ['progresso' => 100, 'faltante' => 0];
        }
        
        // Calcular progresso baseado na faixa COMPLETA da conquista: de valor_base até valor_maximo
        $intervalo_total = $valor_maximo - $valor_base;
        if ($intervalo_total > 0) {
            $progresso = (($faturamento_atual - $valor_base) / $intervalo_total) * 100;
            $progresso = min(100, max(0, $progresso)); // Garantir entre 0 e 100
            
            // Faltante é até o valor_maximo (meta final da conquista)
            $faltante = max(0, $valor_maximo - $faturamento_atual);
            
            return ['progresso' => $progresso, 'faltante' => $faltante];
        }
        
        // Se não há intervalo válido (valor_base >= valor_maximo), progresso é 0
        return ['progresso' => 0, 'faltante' => $valor_maximo - $faturamento_atual];
        
    } catch (PDOException $e) {
        error_log("Erro ao calcular progresso da conquista: " . $e->getMessage());
        return ['progresso' => 0, 'faltante' => 0];
    }
}

/**
 * Verifica e atribui novas conquistas automaticamente ao usuário
 * @param int $usuario_id ID do usuário
 * @return array Array com conquistas atribuídas ['novas' => [...], 'total' => count]
 */
function verificar_conquistas($usuario_id) {
    global $pdo;
    
    if (!isset($pdo) || !$usuario_id) {
        return ['novas' => [], 'total' => 0];
    }
    
    $faturamento_atual = calcular_faturamento_lifetime($usuario_id);
    $conquistas_atribuidas = [];
    
    try {
        // Busca todas as conquistas ativas que o usuário ainda não possui
        // Conquistas só são atribuídas quando o faturamento COMPLETA a faixa:
        // - Se tem valor_maximo: precisa atingir o valor_maximo
        // - Se não tem valor_maximo (última conquista): precisa atingir o valor_minimo
        $stmt = $pdo->prepare("
            SELECT c.*
            FROM conquistas c
            WHERE c.is_active = 1
            AND (
                (c.valor_maximo IS NOT NULL AND ? >= c.valor_maximo)
                OR 
                (c.valor_maximo IS NULL AND ? >= c.valor_minimo)
            )
            AND NOT EXISTS (
                SELECT 1 FROM usuario_conquistas uc 
                WHERE uc.usuario_id = ? AND uc.conquista_id = c.id
            )
            ORDER BY c.ordem ASC, c.valor_minimo ASC
        ");
        $stmt->execute([$faturamento_atual, $faturamento_atual, $usuario_id]);
        $conquistas_para_atribuir = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Atribui cada conquista
        foreach ($conquistas_para_atribuir as $conquista) {
            try {
                $stmt_insert = $pdo->prepare("
                    INSERT INTO usuario_conquistas (usuario_id, conquista_id, faturamento_atingido)
                    VALUES (?, ?, ?)
                ");
                $stmt_insert->execute([
                    $usuario_id,
                    $conquista['id'],
                    $faturamento_atual
                ]);
                
                $conquistas_atribuidas[] = $conquista;
                
                error_log("Conquista atribuída: Usuário #{$usuario_id} - Conquista #{$conquista['id']} ({$conquista['nome']})");
            } catch (PDOException $e) {
                // Ignora erro de duplicata (pode acontecer em concorrência)
                if ($e->getCode() != 23000) {
                    error_log("Erro ao atribuir conquista: " . $e->getMessage());
                }
            }
        }
        
        return [
            'novas' => $conquistas_atribuidas,
            'total' => count($conquistas_atribuidas)
        ];
        
    } catch (PDOException $e) {
        error_log("Erro ao verificar conquistas: " . $e->getMessage());
        return ['novas' => [], 'total' => 0];
    }
}

/**
 * Obtém todas as conquistas com status para um usuário
 * @param int $usuario_id ID do usuário
 * @return array Array de conquistas com informações de status
 */
function obter_todas_conquistas_com_status($usuario_id) {
    global $pdo;
    
    if (!isset($pdo) || !$usuario_id) {
        return [];
    }
    
    $faturamento_atual = calcular_faturamento_lifetime($usuario_id);
    
    try {
        // Busca todas as conquistas ativas ordenadas
        $stmt = $pdo->prepare("
            SELECT 
                c.*,
                CASE 
                    WHEN uc.id IS NOT NULL THEN 1 
                    ELSE 0 
                END as conquistada,
                uc.data_conquista,
                uc.faturamento_atingido
            FROM conquistas c
            LEFT JOIN usuario_conquistas uc ON c.id = uc.conquista_id AND uc.usuario_id = ?
            WHERE c.is_active = 1
            ORDER BY c.ordem ASC, c.valor_minimo ASC
        ");
        $stmt->execute([$usuario_id]);
        $conquistas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Identifica qual é a próxima conquista (a que está em progresso)
        $proxima_conquista = obter_proxima_conquista($usuario_id);
        $proxima_conquista_id = $proxima_conquista ? $proxima_conquista['id'] : null;
        
        // Adiciona informações de progresso para cada conquista
        foreach ($conquistas as &$conquista) {
            // Validação: só marca como conquistada se o faturamento realmente atingiu o valor_maximo
            // Isso corrige conquistas atribuídas incorretamente antes da correção
            $foi_marcada_como_conquistada = (bool)$conquista['conquistada'];
            $valor_maximo = $conquista['valor_maximo'] ? (float)$conquista['valor_maximo'] : null;
            
            if ($foi_marcada_como_conquistada) {
                // Verifica se realmente atingiu o valor necessário
                if ($valor_maximo !== null) {
                    // Conquista com faixa: precisa ter atingido o valor_maximo
                    if ($faturamento_atual < $valor_maximo) {
                        // Faturamento não atingiu o valor_maximo, marca como não conquistada
                        $conquista['conquistada'] = false;
                        // Remove o registro incorreto do banco de dados
                        try {
                            $stmt_remove = $pdo->prepare("DELETE FROM usuario_conquistas WHERE usuario_id = ? AND conquista_id = ?");
                            $stmt_remove->execute([$usuario_id, $conquista['id']]);
                            error_log("Conquista removida (atribuída incorretamente): Usuário #{$usuario_id} - Conquista #{$conquista['id']}");
                        } catch (PDOException $e) {
                            error_log("Erro ao remover conquista incorreta: " . $e->getMessage());
                        }
                    } else {
                        // Faturamento atingiu o valor_maximo, mantém como conquistada
                        $conquista['conquistada'] = true;
                    }
                } else {
                    // Última conquista (sem valor_maximo): precisa ter atingido o valor_minimo
                    $valor_minimo = (float)$conquista['valor_minimo'];
                    if ($faturamento_atual < $valor_minimo) {
                        // Faturamento não atingiu o valor_minimo, marca como não conquistada
                        $conquista['conquistada'] = false;
                        // Remove o registro incorreto do banco de dados
                        try {
                            $stmt_remove = $pdo->prepare("DELETE FROM usuario_conquistas WHERE usuario_id = ? AND conquista_id = ?");
                            $stmt_remove->execute([$usuario_id, $conquista['id']]);
                            error_log("Conquista removida (atribuída incorretamente): Usuário #{$usuario_id} - Conquista #{$conquista['id']}");
                        } catch (PDOException $e) {
                            error_log("Erro ao remover conquista incorreta: " . $e->getMessage());
                        }
                    } else {
                        // Faturamento atingiu o valor_minimo, mantém como conquistada
                        $conquista['conquistada'] = true;
                    }
                }
            } else {
                // Não foi marcada como conquistada, mantém false
                $conquista['conquistada'] = false;
            }
            
            // Só calcula progresso se for a próxima conquista (a que está em progresso)
            // Conquistas futuras aparecem como bloqueadas (sem progresso)
            if ($proxima_conquista_id && $conquista['id'] == $proxima_conquista_id) {
                // É a próxima conquista - calcula progresso
                $progresso_info = calcular_progresso_conquista($usuario_id, $conquista['id']);
                $conquista['progresso'] = $progresso_info['progresso'];
                $conquista['faltante'] = $progresso_info['faltante'];
                $conquista['pode_mostrar_progresso'] = true;
            } else {
                // Não é a próxima conquista - sem progresso (bloqueada)
                $conquista['progresso'] = 0;
                $conquista['faltante'] = 0;
                $conquista['pode_mostrar_progresso'] = false;
            }
        }
        
        return $conquistas;
        
    } catch (PDOException $e) {
        error_log("Erro ao obter todas as conquistas: " . $e->getMessage());
        return [];
    }
}

