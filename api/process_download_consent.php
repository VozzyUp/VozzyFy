<?php
/**
 * API: Processar Consentimento de Download Protegido
 * Processa o consentimento do cliente e retorna URL de download
 */

// Inicia sessão se necessário
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/security_helper.php';
require_once __DIR__ . '/../helpers/validation_helper.php';

header('Content-Type: application/json');

// Verificação CSRF
$csrf_token = null;
$raw_input = file_get_contents('php://input');
$data = json_decode($raw_input, true);

// Verificar se houve erro no parsing JSON
if (json_last_error() !== JSON_ERROR_NONE) {
    error_log("process_download_consent: Erro ao decodificar JSON: " . json_last_error_msg() . " | Raw input: " . substr($raw_input, 0, 500));
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Erro ao processar dados. Formato JSON inválido.',
        'debug' => ['json_error' => json_last_error_msg()]
    ]);
    exit;
}

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
        'endpoint' => '/api/process_download_consent.php',
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

// Rate limiting
$client_ip = get_client_ip();
$rate_check = check_rate_limit_db('download_consent', 10, 60, $client_ip); // 10 requisições por minuto

if (!$rate_check['allowed']) {
    log_security_event('rate_limit_exceeded', [
        'ip' => $client_ip,
        'endpoint' => '/api/process_download_consent.php',
        'reset_at' => $rate_check['reset_at'] ?? null
    ]);
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Muitas requisições. Aguarde alguns instantes.']);
    exit;
}

try {
    // Verificar se os dados foram recebidos
    if (empty($data) || !is_array($data)) {
        error_log("process_download_consent: Dados não recebidos ou formato inválido. Raw input: " . substr($raw_input, 0, 500));
        throw new Exception('Dados não recebidos corretamente. Tente novamente.');
    }
    
    // Validar dados recebidos
    $aula_id = (int)($data['aula_id'] ?? 0);
    $email = trim($data['email'] ?? '');
    $cpf = trim($data['cpf'] ?? '');
    $nome = trim($data['nome'] ?? '');

    if (empty($aula_id) || $aula_id <= 0) {
        error_log("process_download_consent: Aula ID inválido. Recebido: " . ($data['aula_id'] ?? 'não definido'));
        throw new Exception('ID da aula inválido.');
    }

    if (empty($email)) {
        error_log("process_download_consent: Email vazio. Dados recebidos: " . json_encode($data));
        throw new Exception('Email é obrigatório.');
    }
    
    if (!validate_email($email)) {
        error_log("process_download_consent: Email inválido: " . $email);
        throw new Exception('Email inválido.');
    }

    $cpf_limpo = preg_replace('/[^0-9]/', '', $cpf);
    if (empty($cpf_limpo)) {
        error_log("process_download_consent: CPF vazio. Recebido: " . $cpf);
        throw new Exception('CPF é obrigatório.');
    }
    
    if (!validate_cpf($cpf_limpo)) {
        error_log("process_download_consent: CPF inválido: " . $cpf_limpo);
        throw new Exception('CPF inválido.');
    }

    if (empty($nome) || strlen($nome) < 3) {
        error_log("process_download_consent: Nome inválido. Recebido: " . $nome);
        throw new Exception('Nome completo deve ter pelo menos 3 caracteres.');
    }

    // Buscar dados da aula
    $stmt_aula = $pdo->prepare("
        SELECT a.*, m.curso_id, c.produto_id, p.usuario_id, p.nome as produto_nome
        FROM aulas a
        JOIN modulos m ON a.modulo_id = m.id
        JOIN cursos c ON m.curso_id = c.id
        JOIN produtos p ON c.produto_id = p.id
        WHERE a.id = ? AND a.tipo_conteudo = 'download_protegido'
    ");
    $stmt_aula->execute([$aula_id]);
    $aula = $stmt_aula->fetch(PDO::FETCH_ASSOC);

    if (!$aula) {
        throw new Exception('Aula não encontrada ou não é um download protegido.');
    }

    // Verificar se o aluno tem acesso à aula
    // Verifica acesso via alunos_acessos (comprou) OU se é infoprodutor e criou o produto
    $tem_acesso = false;
    
    // Normalizar email (lowercase, trim)
    $email_normalizado = strtolower(trim($email));
    
    // Primeiro verifica se comprou (está em alunos_acessos) - comparação case-insensitive
    $stmt_acesso = $pdo->prepare("
        SELECT COUNT(*) 
        FROM alunos_acessos 
        WHERE LOWER(TRIM(aluno_email)) = ? AND produto_id = ?
    ");
    $stmt_acesso->execute([$email_normalizado, $aula['produto_id']]);
    $tem_acesso = $stmt_acesso->fetchColumn() > 0;
    
    // Se não encontrou em alunos_acessos, verificar se tem venda aprovada
    if (!$tem_acesso) {
        $stmt_venda = $pdo->prepare("
            SELECT COUNT(*) 
            FROM vendas 
            WHERE LOWER(TRIM(comprador_email)) = ? 
            AND produto_id = ? 
            AND status_pagamento IN ('approved', 'paid', 'completed')
        ");
        $stmt_venda->execute([$email_normalizado, $aula['produto_id']]);
        $tem_acesso = $stmt_venda->fetchColumn() > 0;
    }
    
    // Se ainda não tem acesso, verifica se é infoprodutor e criou o produto
    if (!$tem_acesso) {
        // Verificar se há sessão ativa e se é infoprodutor
        if (!empty($_SESSION['id']) && !empty($_SESSION['tipo']) && $_SESSION['tipo'] === 'infoprodutor') {
            $usuario_id = $_SESSION['id'];
            $stmt_produto = $pdo->prepare("
                SELECT id FROM produtos 
                WHERE id = ? AND usuario_id = ?
            ");
            $stmt_produto->execute([$aula['produto_id'], $usuario_id]);
            $produto_info = $stmt_produto->fetch(PDO::FETCH_ASSOC);
            
            if ($produto_info) {
                // Verificar se o usuario (email) do infoprodutor corresponde ao email fornecido
                $stmt_usuario = $pdo->prepare("SELECT usuario FROM usuarios WHERE id = ?");
                $stmt_usuario->execute([$usuario_id]);
                $usuario_info = $stmt_usuario->fetch(PDO::FETCH_ASSOC);
                
                if ($usuario_info && strtolower(trim($usuario_info['usuario'])) === $email_normalizado) {
                    $tem_acesso = true;
                }
            }
        }
        
        // Se ainda não tem acesso, verificar se o email fornecido corresponde a algum infoprodutor dono do produto
        if (!$tem_acesso) {
            $stmt_produto_email = $pdo->prepare("
                SELECT p.usuario_id, u.usuario
                FROM produtos p
                JOIN usuarios u ON p.usuario_id = u.id
                WHERE p.id = ? AND LOWER(TRIM(u.usuario)) = ?
            ");
            $stmt_produto_email->execute([$aula['produto_id'], $email_normalizado]);
            $produto_email_info = $stmt_produto_email->fetch(PDO::FETCH_ASSOC);
            
            if ($produto_email_info) {
                $tem_acesso = true;
            }
        }
    }

    if (!$tem_acesso) {
        // Log para debug (remover em produção se necessário)
        error_log("Acesso negado - Email: {$email}, Produto ID: {$aula['produto_id']}, Aula ID: {$aula_id}");
        throw new Exception('Você não tem acesso a esta aula. Verifique se você comprou este curso ou se é o criador do produto.');
    }

    // Validar que download_link e termos_consentimento existem
    if (empty($aula['download_link'])) {
        throw new Exception('Link de download não configurado para esta aula.');
    }

    if (empty($aula['termos_consentimento'])) {
        throw new Exception('Termos de consentimento não configurados para esta aula.');
    }

    // Gerar documento HTML de consentimento
    $data_consentimento = date('d/m/Y H:i:s');
    $ip_address = get_client_ip();
    
    // Se o IP for localhost (::1 ou 127.0.0.1), tentar obter IP real de headers
    if (in_array($ip_address, ['::1', '127.0.0.1', '0.0.0.0'])) {
        // Tentar obter IP real de headers de proxy/load balancer
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $forwarded_ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $ip_address = trim($forwarded_ips[0]);
        } elseif (!empty($_SERVER['HTTP_X_REAL_IP'])) {
            $ip_address = $_SERVER['HTTP_X_REAL_IP'];
        } elseif (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $ip_address = $_SERVER['HTTP_CF_CONNECTING_IP'];
        } else {
            // Se ainda for localhost, usar uma descrição mais clara
            $ip_address = 'Localhost (' . ($_SERVER['REMOTE_ADDR'] ?? 'N/A') . ')';
        }
    }
    
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'Não informado';
    
    $documento_html = generate_consentimento_html([
        'aluno_nome' => $nome,
        'aluno_email' => $email,
        'aluno_cpf' => $cpf_limpo,
        'produto_nome' => $aula['produto_nome'],
        'aula_titulo' => $aula['titulo'],
        'termos_aceitos' => $aula['termos_consentimento'],
        'data_consentimento' => $data_consentimento,
        'ip_address' => $ip_address,
        'user_agent' => $user_agent
    ]);

    // Salvar consentimento no banco
    $stmt_insert = $pdo->prepare("
        INSERT INTO download_consentimentos 
        (aula_id, produto_id, aluno_email, aluno_nome, aluno_cpf, termos_aceitos, documento_consentimento_html, ip_address, user_agent)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt_insert->execute([
        $aula_id,
        $aula['produto_id'],
        $email,
        $nome,
        $cpf_limpo,
        $aula['termos_consentimento'],
        $documento_html,
        $ip_address,
        $user_agent
    ]);

    // Retornar sucesso com URL de download
    $response = [
        'success' => true,
        'download_url' => $aula['download_link'],
        'message' => 'Consentimento registrado com sucesso.'
    ];
    
    // Incluir novo token se foi renovado
    if (isset($new_token) && $new_token) {
        $response['new_csrf_token'] = $new_token;
    }
    
    echo json_encode($response);

} catch (Exception $e) {
    error_log("Erro ao processar consentimento de download: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    error_log("Dados recebidos: " . json_encode($data ?? []));
    http_response_code(400);
    
    // Retornar mensagem de erro mais amigável
    $errorMessage = $e->getMessage();
    
    // Não expor detalhes técnicos em produção, mas manter informações úteis
    echo json_encode([
        'success' => false,
        'error' => $errorMessage,
        'debug' => [
            'aula_id' => $aula_id ?? null,
            'email' => isset($email) ? substr($email, 0, 10) . '...' : null,
            'has_data' => !empty($data),
            'data_keys' => !empty($data) ? array_keys($data) : []
        ]
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
} catch (Throwable $e) {
    error_log("Erro fatal ao processar consentimento de download: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Erro interno do servidor. Tente novamente mais tarde.'
    ]);
    exit;
}

/**
 * Gera documento HTML de consentimento
 */
function generate_consentimento_html($data) {
    $html = '<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Documento de Consentimento - Download Protegido</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
            line-height: 1.6;
            color: #333;
        }
        .header {
            text-align: center;
            border-bottom: 2px solid #333;
            padding-bottom: 20px;
            margin-bottom: 30px;
        }
        .section {
            margin-bottom: 25px;
        }
        .section-title {
            font-weight: bold;
            font-size: 18px;
            margin-bottom: 10px;
            color: #000;
        }
        .field {
            margin-bottom: 8px;
        }
        .field-label {
            font-weight: bold;
            display: inline-block;
            min-width: 150px;
        }
        .termos-box {
            background: #f5f5f5;
            border: 1px solid #ddd;
            padding: 15px;
            border-radius: 5px;
            margin-top: 10px;
            white-space: pre-wrap;
        }
        .footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #ddd;
            font-size: 12px;
            color: #666;
        }
        .signature {
            margin-top: 30px;
            padding-top: 20px;
            border-top: 2px solid #333;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>DOCUMENTO DE CONSENTIMENTO</h1>
        <h2>Download Protegido - Material Digital</h2>
    </div>

    <div class="section">
        <div class="section-title">DADOS DO CLIENTE</div>
        <div class="field">
            <span class="field-label">Nome Completo:</span>
            <span>' . htmlspecialchars($data['aluno_nome']) . '</span>
        </div>
        <div class="field">
            <span class="field-label">Email:</span>
            <span>' . htmlspecialchars($data['aluno_email']) . '</span>
        </div>
        <div class="field">
            <span class="field-label">CPF:</span>
            <span>' . htmlspecialchars($data['aluno_cpf']) . '</span>
        </div>
    </div>

    <div class="section">
        <div class="section-title">INFORMAÇÕES DO PRODUTO</div>
        <div class="field">
            <span class="field-label">Produto:</span>
            <span>' . htmlspecialchars($data['produto_nome']) . '</span>
        </div>
        <div class="field">
            <span class="field-label">Aula/Material:</span>
            <span>' . htmlspecialchars($data['aula_titulo']) . '</span>
        </div>
    </div>

    <div class="section">
        <div class="section-title">TERMOS E CONDIÇÕES ACEITOS</div>
        <div class="termos-box">' . nl2br(htmlspecialchars($data['termos_aceitos'])) . '</div>
    </div>

    <div class="section">
        <div class="section-title">DECLARAÇÃO DE CONSENTIMENTO</div>
        <p>
            Eu, <strong>' . htmlspecialchars($data['aluno_nome']) . '</strong>, CPF <strong>' . htmlspecialchars($data['aluno_cpf']) . '</strong>,
            declaro que li, compreendi e aceito integralmente os termos e condições acima descritos.
        </p>
        <p>
            Autorizo expressamente o download e uso do material digital conforme os termos estabelecidos.
        </p>
    </div>

    <div class="signature">
        <div class="field">
            <span class="field-label">Data e Hora:</span>
            <span>' . htmlspecialchars($data['data_consentimento']) . '</span>
        </div>
        <div class="field">
            <span class="field-label">IP de Origem:</span>
            <span>' . htmlspecialchars($data['ip_address']) . '</span>
        </div>
    </div>

    <div class="footer">
        <p><strong>Este documento foi gerado automaticamente pelo sistema.</strong></p>
        <p>Este documento serve como comprovante de entrega do produto digital e consentimento do cliente.</p>
        <p>Data de geração: ' . date('d/m/Y H:i:s') . '</p>
    </div>
</body>
</html>';

    return $html;
}

