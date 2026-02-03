<?php
/**
 * API para alternar entre painéis (Infoprodutor e Área de Membros)
 */

require_once __DIR__ . '/../config/config.php';

// Carregar funções SaaS se necessário
if (file_exists(__DIR__ . '/../saas/includes/saas_functions.php')) {
    require_once __DIR__ . '/../saas/includes/saas_functions.php';
}

/**
 * Converte usuário de tipo 'usuario' para 'infoprodutor' quando solicitado pelo usuário
 * Só funciona se SaaS estiver ativado e usuário tiver compras aprovadas
 * @param string $email Email do usuário
 * @return array ['success' => bool, 'message' => string]
 */
function convert_user_to_infoprodutor_on_demand($email) {
    global $pdo;
    
    // Verificar se SaaS está ativado
    if (!function_exists('saas_enabled') || !saas_enabled()) {
        return ['success' => false, 'message' => 'Sistema SaaS não está ativado'];
    }
    
    try {
        // Busca usuário
        $stmt = $pdo->prepare("SELECT id, tipo FROM usuarios WHERE usuario = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            return ['success' => false, 'message' => 'Usuário não encontrado'];
        }
        
        if ($user['tipo'] !== 'usuario') {
            return ['success' => false, 'message' => 'Usuário já é infoprodutor ou admin'];
        }
        
        // Verifica se tem compras aprovadas
        $stmt_vendas = $pdo->prepare("
            SELECT COUNT(*) as total 
            FROM vendas 
            WHERE comprador_email = ? AND status_pagamento IN ('approved', 'paid', 'completed')
        ");
        $stmt_vendas->execute([$email]);
        $vendas = $stmt_vendas->fetch(PDO::FETCH_ASSOC);
        
        if (!$vendas || $vendas['total'] == 0) {
            return ['success' => false, 'message' => 'Você precisa ter pelo menos uma compra aprovada para se tornar infoprodutor'];
        }
        
        // Converte para infoprodutor
        $stmt_update = $pdo->prepare("UPDATE usuarios SET tipo = 'infoprodutor' WHERE id = ?");
        $stmt_update->execute([$user['id']]);
        
        // Atribuir plano free se SaaS estiver habilitado
        if (function_exists('saas_assign_free_plan')) {
            saas_assign_free_plan($user['id']);
        }
        
        error_log("Usuário convertido para infoprodutor via botão: $email (ID: {$user['id']})");
        return ['success' => true, 'message' => 'Usuário convertido para infoprodutor com sucesso'];
        
    } catch (PDOException $e) {
        error_log("Erro ao converter usuário para infoprodutor: " . $e->getMessage());
        return ['success' => false, 'message' => 'Erro ao converter usuário: ' . $e->getMessage()];
    }
}

header('Content-Type: application/json');

// Verifica se está logado
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Não autenticado']);
    exit;
}

// Verifica se é admin (não pode alternar)
if (isset($_SESSION["tipo"]) && $_SESSION["tipo"] === 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Administradores não podem alternar painéis']);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método não permitido']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$view_mode = $input['view_mode'] ?? $_POST['view_mode'] ?? null;

if (!in_array($view_mode, ['infoprodutor', 'member'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Modo de visualização inválido']);
    exit;
}

$usuario_id = $_SESSION['id'] ?? 0;
$usuario_tipo = $_SESSION['tipo'] ?? '';
$usuario_email = $_SESSION['usuario'] ?? '';

// Validação de acesso
$has_access = false;

if ($view_mode === 'infoprodutor') {
    // Se não é infoprodutor, tenta converter se SaaS estiver ativado e usuário tiver compras
    if ($usuario_tipo !== 'infoprodutor') {
        // Verifica se SaaS está ativado antes de tentar converter
        if (function_exists('saas_enabled') && saas_enabled()) {
            // Tenta converter quando usuário clica no botão
            $conversion_result = convert_user_to_infoprodutor_on_demand($usuario_email);
            if ($conversion_result['success']) {
                // Atualiza sessão
                $_SESSION['tipo'] = 'infoprodutor';
                $_SESSION['is_infoprodutor'] = true;
                $usuario_tipo = 'infoprodutor';
                $has_access = true;
            } else {
                $has_access = false;
                log_security_event('unauthorized_panel_switch_attempt', [
                    'user_id' => $usuario_id,
                    'user_type' => $usuario_tipo,
                    'attempted_view_mode' => $view_mode,
                    'reason' => $conversion_result['message'],
                    'ip' => get_client_ip()
                ]);
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => $conversion_result['message']]);
                exit;
            }
        } else {
            // SaaS não está ativado - não pode converter
            $has_access = false;
            log_security_event('unauthorized_panel_switch_attempt', [
                'user_id' => $usuario_id,
                'user_type' => $usuario_tipo,
                'attempted_view_mode' => $view_mode,
                'reason' => 'SaaS não está ativado',
                'ip' => get_client_ip()
            ]);
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Sistema SaaS não está ativado. Apenas infoprodutores podem acessar este painel.']);
            exit;
        }
    } else {
        $has_access = true;
    }
    
    if (!$has_access) {
        log_security_event('unauthorized_panel_switch_attempt', [
            'user_id' => $usuario_id,
            'user_type' => $usuario_tipo,
            'attempted_view_mode' => $view_mode,
            'ip' => get_client_ip()
        ]);
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Acesso negado. Apenas infoprodutores podem acessar este painel.']);
        exit;
    }
} elseif ($view_mode === 'member') {
    // Pode acessar área de membros se:
    // 1. É infoprodutor (sempre pode acessar), OU
    // 2. Tem registros em alunos_acessos (comprou cursos), OU
    // 3. Tem compras aprovadas de produtos area_membros
    try {
        // Infoprodutores sempre podem acessar
        if ($usuario_tipo === 'infoprodutor') {
            $has_access = true;
        } else {
            // Verifica se tem cursos comprados (acessos criados)
            $stmt_acessos = $pdo->prepare("
                SELECT COUNT(*) as total 
                FROM alunos_acessos aa
                JOIN produtos p ON aa.produto_id = p.id
                WHERE aa.aluno_email = ? AND p.tipo_entrega = 'area_membros'
            ");
            $stmt_acessos->execute([$usuario_email]);
            $acessos = $stmt_acessos->fetch(PDO::FETCH_ASSOC);
            
            if ($acessos && $acessos['total'] > 0) {
                $has_access = true;
            } else {
                // Verifica se tem compras aprovadas de produtos area_membros
                $stmt_vendas = $pdo->prepare("
                    SELECT COUNT(*) as total 
                    FROM vendas v
                    JOIN produtos p ON v.produto_id = p.id
                    WHERE v.comprador_email = ? 
                    AND v.status_pagamento IN ('approved', 'paid', 'completed')
                    AND p.tipo_entrega = 'area_membros'
                ");
                $stmt_vendas->execute([$usuario_email]);
                $vendas = $stmt_vendas->fetch(PDO::FETCH_ASSOC);
                
                if ($vendas && $vendas['total'] > 0) {
                    $has_access = true;
                }
            }
        }
    } catch (PDOException $e) {
        error_log("Erro ao verificar acesso à área de membros: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erro ao verificar acesso']);
        exit;
    }
}

if (!$has_access) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Você não tem acesso a este painel']);
    exit;
}

// Atualiza o modo de visualização na sessão
$_SESSION['current_view_mode'] = $view_mode;

// Define URL de redirecionamento
$redirect_url = ($view_mode === 'infoprodutor') ? '/' : '/member_area_dashboard';

echo json_encode([
    'success' => true,
    'view_mode' => $view_mode,
    'redirect_url' => $redirect_url
]);

