<?php
// Inicia a sessão antes de incluir config.php (caso não esteja iniciada)
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/config.php';

// Configura os cabeçalhos para JSON
header('Content-Type: application/json');

// Permite requisições de origens diferentes (CORS) se necessário.
// Para uma API no mesmo domínio da aplicação, pode não ser estritamente necessário,
// mas é uma boa prática para APIs. Descomente e ajuste se a API for acessada de outro domínio.
/*
header("Access-Control-Allow-Origin: *"); // Altere '*' para o domínio específico do frontend em produção
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization");
*/

// Função auxiliar para enviar resposta JSON
function sendJsonResponse($success, $data = [], $httpCode = 200) {
    http_response_code($httpCode);
    // [MUDANÇA] Adicionado JSON_UNESCAPED_SLASHES para URLs legíveis, se houver
    echo json_encode(['success' => $success] + $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Função helper para verificar CSRF em ações que modificam dados
 */
function require_csrf_for_modifying_actions() {
    require_once __DIR__ . '/../helpers/security_helper.php';
    
    if ($_SERVER['REQUEST_METHOD'] === 'GET' || $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        return true; // GET e OPTIONS não precisam de CSRF
    }
    
    $csrf_token = null;
    
    // Tenta obter token de diferentes fontes
    if (isset($_POST['csrf_token'])) {
        $csrf_token = $_POST['csrf_token'];
    } elseif (isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
        $csrf_token = $_SERVER['HTTP_X_CSRF_TOKEN'];
    } else {
        $input = json_decode(file_get_contents('php://input'), true);
        if (isset($input['csrf_token'])) {
            $csrf_token = $input['csrf_token'];
        }
    }
    
    if (empty($csrf_token) || !verify_csrf_token($csrf_token)) {
        log_security_event('invalid_csrf_token', [
            'endpoint' => '/api/member_api.php',
            'ip' => get_client_ip(),
            'method' => $_SERVER['REQUEST_METHOD']
        ]);
        sendJsonResponse(false, ['error' => 'Token CSRF inválido ou ausente'], 403);
    }
    
    return true;
}

// Verifica se o usuário está logado (não pode ser admin, mas pode ser 'usuario' ou não ter tipo definido)
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    sendJsonResponse(false, ['error' => 'Acesso não autorizado. Você precisa estar logado como cliente.'], 401);
}

// Se for admin, não pode acessar a área de membros
if (isset($_SESSION["tipo"]) && $_SESSION["tipo"] === 'admin') {
    sendJsonResponse(false, ['error' => 'Acesso não autorizado. Administradores não podem acessar a área de membros.'], 401);
}

$aluno_email_logado = $_SESSION['usuario']; // E-mail do cliente logado

// Obtém a ação da requisição
$action = $_GET['action'] ?? '';

// [MUDANÇA] Obtém os dados do corpo da requisição POST
$input = json_decode(file_get_contents('php://input'), true);

switch ($action) {
    case 'mark_lesson_complete':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            sendJsonResponse(false, ['error' => 'Método não permitido. Use POST.'], 405);
        }
        require_csrf_for_modifying_actions();

        $aula_id = $input['aula_id'] ?? null;
        $aluno_email_request = $input['aluno_email'] ?? null; // E-mail enviado na requisição

        if (!$aula_id || !is_numeric($aula_id)) {
            sendJsonResponse(false, ['error' => 'ID da aula inválido.'], 400);
        }

        // Validação de segurança: o e-mail na requisição deve ser o mesmo do usuário logado
        if ($aluno_email_request !== $aluno_email_logado) {
            sendJsonResponse(false, ['error' => 'Erro de segurança: e-mail de aluno não corresponde ao logado.'], 403);
        }

        try {
            // Usa INSERT IGNORE para evitar duplicatas se a aula já foi marcada
            $stmt = $pdo->prepare("INSERT IGNORE INTO aluno_progresso (aluno_email, aula_id, data_conclusao) VALUES (?, ?, NOW())");
            $stmt->execute([$aluno_email_logado, $aula_id]);

            // Se rowCount() > 0, um novo registro foi inserido. Se for 0, o registro já existia.
            if ($stmt->rowCount() > 0) {
                sendJsonResponse(true, ['message' => 'Aula marcada como concluída.']);
            } else {
                sendJsonResponse(true, ['message' => 'Aula já estava marcada como concluída.']);
            }

        } catch (PDOException $e) {
            // Em ambiente de produção, logar o erro e retornar uma mensagem genérica
            error_log("Erro de DB ao marcar aula: " . $e->getMessage());
            sendJsonResponse(false, ['error' => 'Erro interno do servidor ao marcar aula.'], 500);
        }
        break;

    // [NOVO CASE ADICIONADO]
    case 'unmark_lesson_complete':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            sendJsonResponse(false, ['error' => 'Método não permitido. Use POST.'], 405);
        }
        require_csrf_for_modifying_actions();

        $aula_id = $input['aula_id'] ?? null;
        $aluno_email_request = $input['aluno_email'] ?? null; // E-mail enviado na requisição

        if (!$aula_id || !is_numeric($aula_id)) {
            sendJsonResponse(false, ['error' => 'ID da aula inválido.'], 400);
        }

        // Validação de segurança: o e-mail na requisição deve ser o mesmo do usuário logado
        if ($aluno_email_request !== $aluno_email_logado) {
            sendJsonResponse(false, ['error' => 'Erro de segurança: e-mail de aluno não corresponde ao logado.'], 403);
        }

        try {
            // Deleta o registro de progresso para este aluno e esta aula
            $stmt = $pdo->prepare("DELETE FROM aluno_progresso WHERE aluno_email = ? AND aula_id = ?");
            $stmt->execute([$aluno_email_logado, $aula_id]);

            if ($stmt->rowCount() > 0) {
                sendJsonResponse(true, ['message' => 'Aula desmarcada como concluída.']);
            } else {
                // Isso pode acontecer se o usuário clicar rápido ou se o registro já não existia
                sendJsonResponse(true, ['message' => 'Aula já não estava marcada como concluída.']);
            }

        } catch (PDOException $e) {
            // Em ambiente de produção, logar o erro e retornar uma mensagem genérica
            error_log("Erro de DB ao desmarcar aula: " . $e->getMessage());
            sendJsonResponse(false, ['error' => 'Erro interno do servidor ao desmarcar aula.'], 500);
        }
        break;


    // Adicione outras ações da API de membros aqui, se necessário
    // case 'get_lesson_progress':
    //     // ... lógica para obter progresso de uma aula ...
    //     break;

    default:
        sendJsonResponse(false, ['error' => 'Ação desconhecida ou não especificada.'], 400);
        break;
}

?>