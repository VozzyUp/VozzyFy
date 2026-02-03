<?php
/**
 * API para Sistema de Conquistas
 * Retorna dados de progresso e conquistas do infoprodutor
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/conquistas_helper.php';
require_once __DIR__ . '/../helpers/security_helper.php';

// Verificar autenticação
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    http_response_code(401);
    echo json_encode(['error' => 'Não autenticado']);
    exit;
}

$action = $_GET['action'] ?? '';

try {
    $usuario_id = $_SESSION['id'] ?? null;
    
    if (!$usuario_id) {
        throw new Exception('ID do usuário não encontrado');
    }
    
    switch ($action) {
        case 'get_progress':
            // Retorna progresso atual e conquista atual
            $faturamento = calcular_faturamento_lifetime($usuario_id);
            $conquista_atual = obter_conquista_atual($usuario_id);
            $proxima_conquista = obter_proxima_conquista($usuario_id);
            
            $progresso_info = null;
            if ($proxima_conquista) {
                $progresso_info = calcular_progresso_conquista($usuario_id, $proxima_conquista['id']);
            }
            
            echo json_encode([
                'success' => true,
                'faturamento_lifetime' => $faturamento,
                'faturamento_formatado' => 'R$ ' . number_format($faturamento, 2, ',', '.'),
                'conquista_atual' => $conquista_atual,
                'proxima_conquista' => $proxima_conquista,
                'progresso' => $progresso_info
            ]);
            break;
            
        case 'get_all':
            // Retorna todas as conquistas com status
            $conquistas = obter_todas_conquistas_com_status($usuario_id);
            $faturamento = calcular_faturamento_lifetime($usuario_id);
            
            echo json_encode([
                'success' => true,
                'faturamento_lifetime' => $faturamento,
                'faturamento_formatado' => 'R$ ' . number_format($faturamento, 2, ',', '.'),
                'conquistas' => $conquistas
            ]);
            break;
            
        default:
            throw new Exception('Ação inválida');
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

