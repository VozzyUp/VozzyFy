<?php
/**
 * API: Verificar se já existe consentimento de download
 * Retorna se o aluno já preencheu consentimento para uma aula
 */

// Inicia sessão se necessário
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/security_helper.php';

header('Content-Type: application/json');

// Verificação CSRF
$csrf_token = null;
$raw_input = file_get_contents('php://input');
$data = json_decode($raw_input, true);

if (isset($data['csrf_token'])) {
    $csrf_token = $data['csrf_token'];
} elseif (isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
    $csrf_token = $_SERVER['HTTP_X_CSRF_TOKEN'];
} elseif (isset($_POST['csrf_token'])) {
    $csrf_token = $_POST['csrf_token'];
}

// Verificar token CSRF com renovação automática se expirou
$csrf_valid = false;
$new_token = null;

if (!empty($csrf_token)) {
    $csrf_valid = verify_csrf_token($csrf_token);
    
    // Se token inválido mas sessão é válida, tentar renovar
    if (!$csrf_valid && isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true) {
        // Verificar se token expirou (não é inválido por outro motivo)
        if (isset($_SESSION['csrf_token_time']) && (time() - $_SESSION['csrf_token_time']) > 604800) {
            // Token expirou mas sessão é válida - gerar novo token
            $new_token = generate_csrf_token();
            $csrf_valid = true; // Aceitar após renovação
        }
    }
}

if (!$csrf_valid) {
    log_security_event('invalid_csrf_token', [
        'endpoint' => '/api/check_download_consent.php',
        'ip' => get_client_ip(),
        'has_session' => isset($_SESSION['loggedin']),
        'token_provided' => !empty($csrf_token)
    ]);
    http_response_code(403);
    $response = ['success' => false, 'error' => 'Token CSRF inválido. Recarregue a página e tente novamente.'];
    if ($new_token) {
        $response['new_csrf_token'] = $new_token;
    }
    echo json_encode($response);
    exit;
}

try {
    $aula_id = (int)($data['aula_id'] ?? 0);
    $email = trim($data['email'] ?? '');

    if (empty($aula_id) || $aula_id <= 0) {
        throw new Exception('ID da aula inválido.');
    }

    if (empty($email)) {
        throw new Exception('Email não fornecido.');
    }

    // Normalizar email
    $email_normalizado = strtolower(trim($email));

    // Verificar se já existe consentimento para este aluno e esta aula
    $stmt = $pdo->prepare("
        SELECT dc.id, a.download_link
        FROM download_consentimentos dc
        JOIN aulas a ON dc.aula_id = a.id
        WHERE dc.aula_id = ? AND LOWER(TRIM(dc.aluno_email)) = ?
        ORDER BY dc.data_consentimento DESC
        LIMIT 1
    ");
    $stmt->execute([$aula_id, $email_normalizado]);
    $consentimento = $stmt->fetch(PDO::FETCH_ASSOC);

    // Debug log
    error_log("check_download_consent: Aula ID: {$aula_id}, Email: {$email_normalizado}, Consentimento encontrado: " . ($consentimento ? 'SIM' : 'NÃO'));

    if ($consentimento && !empty($consentimento['download_link'])) {
        // Já existe consentimento - retornar link de download da aula
        error_log("check_download_consent: Retornando download direto. URL: " . $consentimento['download_link']);
        $response = [
            'success' => true,
            'has_consent' => true,
            'download_url' => $consentimento['download_link'],
            'consentimento_id' => $consentimento['id']
        ];
        
        // Incluir novo token se foi renovado
        if (isset($new_token) && $new_token) {
            $response['new_csrf_token'] = $new_token;
        }
        
        echo json_encode($response);
    } else {
        // Não existe consentimento - precisa preencher
        error_log("check_download_consent: Nenhum consentimento encontrado. Abrindo modal.");
        $response = [
            'success' => true,
            'has_consent' => false
        ];
        
        // Incluir novo token se foi renovado
        if (isset($new_token) && $new_token) {
            $response['new_csrf_token'] = $new_token;
        }
        
        echo json_encode($response);
    }

} catch (Exception $e) {
    error_log("Erro ao verificar consentimento: " . $e->getMessage());
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

