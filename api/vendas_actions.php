<?php
// Inicia buffer de saída IMEDIATAMENTE
ob_start();

// Desabilita exibição de erros
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../vendas_actions_error.log');
error_reporting(E_ALL);

// Define header JSON imediatamente
header('Content-Type: application/json');

// Registra handler de erro fatal
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== NULL && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        ob_clean();
        http_response_code(500);
        echo json_encode([
            'success' => false, 
            'message' => 'Erro fatal no servidor: ' . $error['message'] . ' em ' . $error['file'] . ':' . $error['line']
        ]);
        exit;
    }
});

require_once __DIR__ . '/../config/config.php';

// Carrega PHPMailer ANTES dos use statements
$phpmailer_path = __DIR__ . '/../PHPMailer/src/';
if (file_exists($phpmailer_path . 'Exception.php')) { 
    require_once $phpmailer_path . 'Exception.php'; 
    require_once $phpmailer_path . 'PHPMailer.php'; 
    require_once $phpmailer_path . 'SMTP.php'; 
}

// PHPMailer imports - DEVE estar DEPOIS dos requires
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

function log_action($msg) {
    file_put_contents(__DIR__ . '/../vendas_actions_log.txt', date('Y-m-d H:i:s') . " - " . $msg . "\n", FILE_APPEND);
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
            'endpoint' => '/api/vendas_actions.php',
            'ip' => get_client_ip(),
            'method' => $_SERVER['REQUEST_METHOD']
        ]);
        http_response_code(403);
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Token CSRF inválido ou ausente']);
        exit;
    }
    
    return true;
}

// Verifica autenticação
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    ob_clean();
    http_response_code(401);
    log_action("Erro: Não autorizado.");
    echo json_encode(['success' => false, 'message' => 'Não autorizado.']);
    exit;
}

// --- CARREGA O HELPER PARA ENVIAR O EVENTO ---
if (file_exists(__DIR__ . '/../helpers/utmfy_helper.php')) {
    require_once __DIR__ . '/../helpers/utmfy_helper.php';
} else {
    log_action("AVISO: utmfy_helper.php não encontrado (opcional).");
}

/**
 * Converte usuário de tipo 'usuario' para 'infoprodutor' se tiver compras aprovadas
 * @param string $email Email do usuário
 * @return bool True se conversão foi feita, False caso contrário
 */
function convert_user_to_infoprodutor_if_needed($email) {
    global $pdo;
    try {
        // Busca usuário
        $stmt = $pdo->prepare("SELECT id, tipo FROM usuarios WHERE usuario = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user || $user['tipo'] !== 'usuario') {
            return false; // Já é infoprodutor ou não existe
        }
        
        // Verifica se tem compras aprovadas
        $stmt_vendas = $pdo->prepare("
            SELECT COUNT(*) as total 
            FROM vendas 
            WHERE comprador_email = ? AND status_pagamento IN ('approved', 'paid', 'completed')
        ");
        $stmt_vendas->execute([$email]);
        $vendas = $stmt_vendas->fetch(PDO::FETCH_ASSOC);
        
        if ($vendas && $vendas['total'] > 0) {
            // Converte para infoprodutor
            $stmt_update = $pdo->prepare("UPDATE usuarios SET tipo = 'infoprodutor' WHERE id = ?");
            $stmt_update->execute([$user['id']]);
            
            // Atribuir plano free se SaaS estiver habilitado
            if (function_exists('saas_enabled') && function_exists('saas_assign_free_plan')) {
                if (saas_enabled()) {
                    saas_assign_free_plan($user['id']);
                }
            }
            
            log_action("Usuário convertido para infoprodutor: $email (ID: {$user['id']})");
            return true;
        }
        
        return false;
    } catch (PDOException $e) {
        error_log("Erro ao converter usuário para infoprodutor: " . $e->getMessage());
        log_action("ERRO ao converter usuário para infoprodutor: " . $e->getMessage());
        return false;
    }
}

// --- FUNÇÃO PROCESSAR ENTREGA ---
function process_single_product_delivery($product_data, $customer_email) {
    global $pdo;
    $type = $product_data['tipo_entrega']; 
    $content = $product_data['conteudo_entrega'];
    
    if ($type === 'link') {
        return ['success' => !empty($content), 'product_name' => $product_data['produto_nome'], 'content_type' => 'link', 'content_value' => $content];
    }
    if ($type === 'email_pdf') {
        return ['success' => file_exists('uploads/'.$content), 'product_name' => $product_data['produto_nome'], 'content_type' => 'pdf', 'content_value' => 'uploads/'.$content];
    }
    if ($type === 'area_membros') {
        try {
            // Tenta INSERT IGNORE primeiro
            $pdo->prepare("INSERT IGNORE INTO alunos_acessos (aluno_email, produto_id) VALUES (?, ?)")->execute([$customer_email, $product_data['produto_id']]);
        } catch (PDOException $e) {
            // Se INSERT IGNORE falhar (feature disabled), tenta verificar antes de inserir
            $stmt_check = $pdo->prepare("SELECT id FROM alunos_acessos WHERE aluno_email = ? AND produto_id = ?");
            $stmt_check->execute([$customer_email, $product_data['produto_id']]);
            if (!$stmt_check->fetch()) {
                $pdo->prepare("INSERT INTO alunos_acessos (aluno_email, produto_id) VALUES (?, ?)")->execute([$customer_email, $product_data['produto_id']]);
            }
        }
        
        // NÃO converte automaticamente - usuário deve clicar no botão "mudar para infoprodutor" se SaaS estiver ativado
        
        return ['success' => true, 'product_name' => $product_data['produto_nome'], 'content_type' => 'area_membros', 'content_value' => null];
    }
    if ($type === 'somente_pagamento') {
        return ['success' => true, 'product_name' => $product_data['produto_nome'], 'content_type' => 'somente_pagamento', 'content_value' => null];
    }
    return ['success' => false, 'message' => 'Tipo desconhecido'];
}

// --- FUNÇÃO DE ENVIO DE E-MAIL ---
function manual_send_email($to_email, $customer_name, $products, $password, $login_url, $address_data = null, $setup_token = null) {
    global $pdo;
    $mail = new PHPMailer(true);
    try {
        $stmt = $pdo->query("SELECT chave, valor FROM configuracoes WHERE chave IN ('smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_encryption', 'smtp_from_email', 'smtp_from_name', 'email_template_delivery_subject', 'email_template_delivery_html')");
        $conf = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        // Busca logo configurada
        $logo_url_raw = '';
        if (function_exists('getSystemSetting')) {
            $logo_url_raw = getSystemSetting('logo_url', '');
        } else {
            try {
                $stmt_logo = $pdo->query("SELECT valor FROM configuracoes_sistema WHERE chave = 'logo_url' LIMIT 1");
                $logo_result = $stmt_logo->fetch(PDO::FETCH_ASSOC);
                $logo_url_raw = $logo_result ? $logo_result['valor'] : '';
            } catch (PDOException $e) {
                // Se a tabela não existir ou houver erro, usa valor vazio
                log_action("AVISO: Erro ao buscar logo_url: " . $e->getMessage());
                $logo_url_raw = '';
            }
        }
        
        // Constrói URL absoluta da logo
        $logo_url_final = '';
        if (!empty($logo_url_raw)) {
            if (strpos($logo_url_raw, 'http') === 0) {
                $logo_url_final = $logo_url_raw;
            } else {
                $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'];
                $logo_path = ltrim($logo_url_raw, '/');
                $logo_url_final = $protocol . '://' . $host . '/' . $logo_path;
            }
        }
        
        $default_from = 'noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        
        $fromEmail = !empty($conf['smtp_from_email']) ? $conf['smtp_from_email'] : ($conf['smtp_username'] ?? $default_from);
        if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) $fromEmail = $default_from;

        if (empty($conf['smtp_host'])) {
            $mail->isMail();
        } else {
            $mail->isSMTP();
            $mail->Host = $conf['smtp_host'];
            $mail->Port = $conf['smtp_port'];
            $mail->SMTPAuth = true;
            $mail->Username = $conf['smtp_username'];
            $mail->Password = $conf['smtp_password'];
            $mail->SMTPSecure = ($conf['smtp_encryption'] == 'ssl') ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        }
        
        $mail->setFrom($fromEmail, $conf['smtp_from_name'] ?? 'Starfy');
        $mail->CharSet = 'UTF-8';
        $mail->addAddress($to_email, $customer_name); 
        $email_subject = trim($conf['email_template_delivery_subject'] ?? '');
        $mail->Subject = !empty($email_subject) ? $email_subject : 'Seu acesso chegou!';
        $mail->isHTML(true);

        $template = $conf['email_template_delivery_html'] ?? '';
        
        // Se o template estiver vazio, gera template padrão usando configurações da plataforma
        if (empty(trim($template))) {
            log_action("API Vendas Actions: Template vazio, gerando template padrão com configurações da plataforma");
            
            // Carrega helper de template
            if (file_exists(__DIR__ . '/../helpers/email_template_helper.php')) {
                require_once __DIR__ . '/../helpers/email_template_helper.php';
            }
            
            // Busca configurações da plataforma
            $logo_checkout_url_raw = '';
            if (function_exists('getSystemSetting')) {
                $logo_checkout_url_raw = getSystemSetting('logo_checkout_url', '');
                if (empty($logo_checkout_url_raw)) {
                    $logo_checkout_url_raw = getSystemSetting('logo_url', '');
                }
            } else {
                $stmt_logo = $pdo->query("SELECT valor FROM configuracoes_sistema WHERE chave IN ('logo_checkout_url', 'logo_url') ORDER BY FIELD(chave, 'logo_checkout_url', 'logo_url') LIMIT 1");
                $logo_result = $stmt_logo->fetch(PDO::FETCH_ASSOC);
                $logo_checkout_url_raw = $logo_result ? $logo_result['valor'] : '';
            }
            
            // Normaliza URL da logo
            $logo_checkout_url = '';
            if (!empty($logo_checkout_url_raw)) {
                if (strpos($logo_checkout_url_raw, 'http') === 0) {
                    $logo_checkout_url = $logo_checkout_url_raw;
                } else {
                    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
                    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                    $logo_path = ltrim($logo_checkout_url_raw, '/');
                    $logo_checkout_url = $protocol . '://' . $host . '/' . $logo_path;
                }
            }
            
            // Busca cor primária e nome da plataforma
            $cor_primaria = '#32e768';
            $nome_plataforma = 'Starfy';
            
            if (function_exists('getSystemSetting')) {
                $cor_primaria = getSystemSetting('cor_primaria', '#32e768');
                $nome_plataforma = getSystemSetting('nome_plataforma', 'Starfy');
            } else {
                $stmt_sistema = $pdo->query("SELECT chave, valor FROM configuracoes_sistema WHERE chave IN ('cor_primaria', 'nome_plataforma')");
                $sistema_configs = $stmt_sistema->fetchAll(PDO::FETCH_KEY_PAIR);
                $cor_primaria = $sistema_configs['cor_primaria'] ?? '#32e768';
                $nome_plataforma = $sistema_configs['nome_plataforma'] ?? 'Starfy';
            }
            
            // Gera template padrão
            if (function_exists('generate_default_delivery_email_template')) {
                $template = generate_default_delivery_email_template($logo_checkout_url, $cor_primaria, $nome_plataforma);
            } else {
                // Fallback básico
                $template = '<p>Olá {CLIENT_NAME}, seus produtos:</p><!-- LOOP_PRODUCTS_START --><p>{PRODUCT_NAME}: <a href="{PRODUCT_LINK}">{PRODUCT_LINK}</a></p><!-- LOOP_PRODUCTS_END -->';
            }
        }
        
        // Prepara dados de endereço para substituição
        $address_html = '';
        if ($address_data && !empty($address_data['cep'])) {
            $address_html = '<div style="margin-top: 20px; padding: 15px; background-color: #f5f5f5; border-radius: 5px; border-left: 4px solid #4CAF50;">';
            $address_html .= '<h3 style="margin-top: 0; color: #333; font-size: 16px;">Endereço de Entrega</h3>';
            $address_html .= '<p style="margin: 5px 0; color: #666; font-size: 14px;"><strong>CEP:</strong> ' . htmlspecialchars($address_data['cep']) . '</p>';
            $address_html .= '<p style="margin: 5px 0; color: #666; font-size: 14px;"><strong>Endereço:</strong> ' . htmlspecialchars($address_data['logradouro']) . ', ' . htmlspecialchars($address_data['numero']);
            if (!empty($address_data['complemento'])) {
                $address_html .= ' - ' . htmlspecialchars($address_data['complemento']);
            }
            $address_html .= '</p>';
            $address_html .= '<p style="margin: 5px 0; color: #666; font-size: 14px;"><strong>Bairro:</strong> ' . htmlspecialchars($address_data['bairro']) . '</p>';
            $address_html .= '<p style="margin: 5px 0; color: #666; font-size: 14px;"><strong>Cidade/UF:</strong> ' . htmlspecialchars($address_data['cidade']) . ' - ' . htmlspecialchars($address_data['estado']) . '</p>';
            $address_html .= '</div>';
        }
        
        // Prepara URL de criação de senha se houver token
        $setup_password_url = '';
        if ($setup_token) {
            $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $setup_password_url = $protocol . '://' . $host . '/member_setup_password?token=' . urlencode($setup_token);
        }
        
        // Substitui variáveis, incluindo {LOGO_URL}, {DELIVERY_ADDRESS} e {SETUP_PASSWORD_URL}
        $body = str_replace(['{CLIENT_NAME}', '{CLIENT_EMAIL}', '{MEMBER_AREA_PASSWORD}', '{MEMBER_AREA_LOGIN_URL}', '{LOGO_URL}', '{DELIVERY_ADDRESS}', '{SETUP_PASSWORD_URL}'], [$customer_name, $to_email, $password ?? 'N/A', $login_url, $logo_url_final, $address_html, $setup_password_url], $template);
        
        // Também substitui URLs de imagens quebradas ou genéricas pela logo configurada
        if (!empty($logo_url_final)) {
            $body = preg_replace('/src=["\']https?:\/\/[^"\']*imgbb\.com[^"\']*["\']/i', 'src="' . $logo_url_final . '"', $body);
            $body = preg_replace('/src=["\']https?:\/\/[^"\']*ibb\.co[^"\']*["\']/i', 'src="' . $logo_url_final . '"', $body);
            $body = preg_replace('/<img[^>]*src=["\'](?!https?:\/\/)[^"\']*["\'][^>]*>/i', '<img src="' . $logo_url_final . '" alt="Logo" style="max-width: 200px; height: auto;">', $body, 1);
        }
        
        $loop_start = '<!-- LOOP_PRODUCTS_START -->'; $loop_end = '<!-- LOOP_PRODUCTS_END -->';
        if (strpos($body, $loop_start) !== false) {
            $part = substr($body, strpos($body, $loop_start) + strlen($loop_start));
            $part = substr($part, 0, strpos($part, $loop_end));
            $html_prods = '';
            foreach ($products as $p) {
                $item = str_replace('{PRODUCT_NAME}', $p['product_name'], $part);
                $item = str_replace('{PRODUCT_LINK}', ($p['content_type']=='link' ? $p['content_value'] : ''), $item);
                $types = ['link', 'pdf', 'area_membros', 'produto_fisico'];
                foreach($types as $t) {
                    $tag = 'PRODUCT_TYPE_'.strtoupper($t == 'area_membros' ? 'MEMBER_AREA' : ($t == 'produto_fisico' ? 'PHYSICAL_PRODUCT' : $t));
                    if ($p['content_type'] == $t) $item = str_replace(["<!-- IF_$tag -->", "<!-- END_IF_$tag -->"], '', $item);
                    else $item = preg_replace("/<!-- IF_$tag -->.*?<!-- END_IF_$tag -->/s", "", $item);
                }
                
                // Processa tags condicionais para área de membros (novo usuário vs existente)
                if ($p['content_type'] == 'area_membros') {
                    if ($setup_token) {
                        // Cliente novo - mostra IF_NEW_USER_SETUP
                        $item = str_replace(["<!-- IF_NEW_USER_SETUP -->", "<!-- END_IF_NEW_USER_SETUP -->"], '', $item);
                        $item = preg_replace("/<!-- IF_EXISTING_USER -->.*?<!-- END_IF_EXISTING_USER -->/s", "", $item);
                    } else {
                        // Cliente existente - mostra IF_EXISTING_USER
                        $item = str_replace(["<!-- IF_EXISTING_USER -->", "<!-- END_IF_EXISTING_USER -->"], '', $item);
                        $item = preg_replace("/<!-- IF_NEW_USER_SETUP -->.*?<!-- END_IF_NEW_USER_SETUP -->/s", "", $item);
                    }
                } else {
                    // Remove ambas as tags se não for área de membros
                    $item = preg_replace("/<!-- IF_NEW_USER_SETUP -->.*?<!-- END_IF_NEW_USER_SETUP -->/s", "", $item);
                    $item = preg_replace("/<!-- IF_EXISTING_USER -->.*?<!-- END_IF_EXISTING_USER -->/s", "", $item);
                }
                
                $html_prods .= $item;
            }
            $body = str_replace($loop_start . $part . $loop_end, $html_prods, $body);
        }
        $mail->Body = $body;
        
        foreach ($products as $p) {
            if ($p['content_type'] == 'pdf' && file_exists($p['content_value'])) {
                $mail->addAttachment($p['content_value'], basename($p['content_value']));
            }
        }

        $mail->send();
        log_action("E-mail enviado com sucesso para: $to_email");
        return ['success' => true];
    } catch (Exception $e) { 
        $errorMsg = "Erro PHPMailer: " . $e->getMessage();
        log_action($errorMsg);
        return ['success' => false, 'error' => $errorMsg]; 
    }
}

// --- PROCESSAMENTO ---
try {
    ob_clean(); // Limpa qualquer output anterior
    
    // Verifica CSRF antes de processar ações
    require_csrf_for_modifying_actions();
    
    $action = $_POST['action'] ?? '';
    $venda_id = $_POST['venda_id'] ?? 0;
    
    // Verifica autenticação
    if (!isset($_SESSION['id'])) {
        ob_clean();
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'Não autorizado.']);
        exit;
    }
    
    $usuario_id_logado = $_SESSION['id'];

    if (!$venda_id) { 
        ob_clean();
        http_response_code(400);
        echo json_encode(['success'=>false, 'message'=>'ID inválido.']); 
        exit; 
    }

// Valida se a venda pertence ao usuário logado
// IMPORTANTE: Buscamos p.nome as produto_nome para usar no email e no UTMfy
$stmt = $pdo->prepare("SELECT v.*, p.nome as produto_nome, p.tipo_entrega, p.conteudo_entrega, p.usuario_id FROM vendas v JOIN produtos p ON v.produto_id = p.id WHERE v.id = ?");
$stmt->execute([$venda_id]);
$venda_data = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$venda_data || $venda_data['usuario_id'] != $usuario_id_logado) {
    ob_clean();
    http_response_code(404);
    echo json_encode(['success'=>false, 'message'=>'Venda não encontrada.']);
    exit;
}

// Recupera todas as vendas da mesma sessão
$stmt_session = $pdo->prepare("SELECT v.*, p.nome as produto_nome, p.tipo_entrega, p.conteudo_entrega, p.id as produto_id FROM vendas v JOIN produtos p ON v.produto_id = p.id WHERE v.checkout_session_uuid = ?");
$stmt_session->execute([$venda_data['checkout_session_uuid']]);
$todas_vendas = $stmt_session->fetchAll(PDO::FETCH_ASSOC);

$email_destino = $venda_data['comprador_email'];
$nome_destino = $venda_data['comprador_nome'];

// --- AÇÃO DISPARAR UTMFY ---
if ($action === 'dispatch_utmfy') {
    $status = strtolower(trim($venda_data['status_pagamento'] ?? ''));
    $transacao_id = trim($venda_data['transacao_id'] ?? '');
    if (!in_array($status, ['approved', 'paid', 'completed'])) {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Só é possível disparar UTMfy para vendas aprovadas.']);
        exit;
    }
    if (empty($transacao_id)) {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Esta venda não possui ID de transação.']);
        exit;
    }
    if (file_exists(__DIR__ . '/../helpers/utmfy_helper.php')) {
        require_once __DIR__ . '/../helpers/utmfy_helper.php';
    }
    if (file_exists(__DIR__ . '/../helpers/payment_events_dispatcher.php')) {
        require_once __DIR__ . '/../helpers/payment_events_dispatcher.php';
    }
    if (!function_exists('dispatch_payment_events')) {
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Função de dispatch não disponível.']);
        exit;
    }
    try {
        $ok = dispatch_payment_events($pdo, $transacao_id, 'approved', 'Manual (Detalhes)');
        ob_clean();
        echo json_encode(['success' => (bool)$ok, 'message' => $ok ? 'UTMfy disparado com sucesso.' : 'Erro ao disparar UTMfy.']);
    } catch (Exception $e) {
        log_action("ERRO dispatch_utmfy: " . $e->getMessage());
        ob_clean();
        echo json_encode(['success' => false, 'message' => 'Erro: ' . $e->getMessage()]);
    }
    exit;
}

if ($action === 'approve' || $action === 'resend') {
    
    if ($action === 'resend' && !empty($_POST['email'])) {
        $new_email = $_POST['email'];
        if (filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
            $email_destino = $new_email;
            $pdo->prepare("UPDATE vendas SET comprador_email = ? WHERE checkout_session_uuid = ?")->execute([$new_email, $venda_data['checkout_session_uuid']]);
            log_action("E-mail atualizado para: $new_email");
        }
    }

    // --- AÇÃO DE APROVAR ---
    if ($action === 'approve') {
        $status_anterior = $venda_data['status_pagamento'];
        $novo_status = 'approved';
        
        // Executar hook antes de atualizar status
        if (function_exists('do_action')) {
            do_action('before_update_venda_status', $venda_id, $novo_status, $status_anterior);
        }
        
        $pdo->prepare("UPDATE vendas SET status_pagamento = 'approved' WHERE checkout_session_uuid = ?")->execute([$venda_data['checkout_session_uuid']]);
        
        // Executar hook depois de atualizar status
        if (function_exists('do_action')) {
            do_action('after_update_venda_status', $venda_id, $novo_status, $status_anterior);
        }
        
        $pdo->prepare("INSERT INTO notificacoes (usuario_id, tipo, mensagem, valor, venda_id_fk, metodo_pagamento) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$usuario_id_logado, 'Compra Aprovada (Manual)', 'Venda aprovada manualmente.', $venda_data['valor'], $venda_id, 'Manual']);
        
        // --- DISPARO UTMFY MANUAL ---
        if (function_exists('trigger_utmfy_integrations')) {
            log_action("Disparando UTMfy Manual para Venda ID: $venda_id");
            
            // Prepara dados para o Helper
            $event_data = [
                'transacao_id' => $venda_data['transacao_id'],
                'valor_total_compra' => $venda_data['valor'],
                'data_venda' => $venda_data['data_venda'],
                'comprador' => [
                    'nome' => $venda_data['comprador_nome'],
                    'email' => $venda_data['comprador_email'],
                    'cpf' => $venda_data['comprador_cpf'],
                    'telefone' => $venda_data['comprador_telefone']
                ],
                'metodo_pagamento' => 'Manual/Aprovado',
                'utm_parameters' => [
                    'src' => $venda_data['src'] ?? null,
                    'sck' => $venda_data['sck'] ?? null,
                    'utm_source' => $venda_data['utm_source'] ?? null,
                    'utm_campaign' => $venda_data['utm_campaign'] ?? null,
                    'utm_medium' => $venda_data['utm_medium'] ?? null,
                    'utm_content' => $venda_data['utm_content'] ?? null,
                    'utm_term' => $venda_data['utm_term'] ?? null
                ],
                // Monta o produto explicitamente com 'nome'
                'produtos_comprados' => [
                    [
                        'produto_id' => $venda_data['produto_id'],
                        'nome' => $venda_data['produto_nome'], // Garante que o nome seja passado
                        'valor' => $venda_data['valor']
                    ]
                ]
            ];

            // Chama o helper passando 'approved' (que vira paid)
            trigger_utmfy_integrations($usuario_id_logado, $event_data, 'approved', $venda_data['produto_id']);
        }
        
        log_action("Venda aprovada manualmente: $venda_id");
    }

    // Processa entrega
    $delivered_prods = [];
    $member_pass = null;
    $setup_token = null; // Inicializa setup_token
    
    foreach ($todas_vendas as $v) {
        $res = process_single_product_delivery($v, $email_destino);

        if ($res['success']) {
            if ($res['content_type'] === 'area_membros' && !$member_pass) {
                // Importa Helper de criação de senha
                if (!function_exists('generate_setup_token') && file_exists(__DIR__ . '/../helpers/password_setup_helper.php')) {
                    require_once __DIR__ . '/../helpers/password_setup_helper.php';
                }
                
                // Verifica se usuário já existe (independente do tipo: 'usuario' ou 'infoprodutor')
                $stmt_check = $pdo->prepare("SELECT id, senha, tipo FROM usuarios WHERE usuario = ?");
                $stmt_check->execute([$email_destino]);
                $existing_user = $stmt_check->fetch(PDO::FETCH_ASSOC);
                
                if ($existing_user) {
                    // Cliente JÁ TEM conta (pode ser 'usuario' ou 'infoprodutor')
                    // NÃO converte automaticamente - usuário deve clicar no botão "mudar para infoprodutor" se SaaS estiver ativado
                    // NÃO gerar senha, apenas garantir acesso
                    $member_pass = null; // Não passa senha no email
                    log_action("Usuário já existe: " . $email_destino . " (tipo: " . $existing_user['tipo'] . ") - Acesso garantido");
                } else {
                    // Cliente NOVO
                    // Criar usuário com senha temporária (será substituída quando criar senha via token)
                    // A coluna senha é NOT NULL, então precisamos de um valor temporário
                    $temp_password = bin2hex(random_bytes(32)); // Senha temporária longa e aleatória
                    $hashed_temp = password_hash($temp_password, PASSWORD_DEFAULT);
                    
                    try {
                        $stmt_insert = $pdo->prepare("INSERT INTO usuarios (usuario, nome, senha, tipo) VALUES (?, ?, ?, 'usuario')");
                        $stmt_insert->execute([$email_destino, $nome_destino, $hashed_temp]);
                        $new_user_id = $pdo->lastInsertId();
                        log_action("Novo usuário criado (com senha temporária): " . $email_destino . " - ID: " . $new_user_id);
                    } catch (PDOException $e) {
                        // Se falhar, pode ser que o usuário já existe mas não foi encontrado na query anterior (race condition)
                        // Verifica novamente antes de lançar erro
                        $stmt_check_again = $pdo->prepare("SELECT id, tipo FROM usuarios WHERE usuario = ?");
                        $stmt_check_again->execute([$email_destino]);
                        $user_again = $stmt_check_again->fetch(PDO::FETCH_ASSOC);
                        
                        if ($user_again) {
                            // Usuário existe agora - apenas loga e continua
                            log_action("Usuário já existe (verificado após tentativa de inserção): " . $email_destino . " (tipo: " . $user_again['tipo'] . ")");
                            $member_pass = null;
                            // Não precisa gerar token, pois usuário já existe
                        } else {
                            // Realmente não existe - lança erro
                            log_action("ERRO ao criar usuário: " . $e->getMessage());
                            throw $e; // Re-lança a exceção para ser capturada pelo catch externo
                        }
                    }
                    
                    // Gerar token de criação de senha apenas se o usuário foi realmente criado
                    if (function_exists('generate_setup_token') && isset($new_user_id)) {
                        $setup_token = generate_setup_token($new_user_id);
                        log_action("Token de criação de senha gerado para: " . $email_destino);
                    } else if (!isset($new_user_id)) {
                        log_action("Token não gerado - usuário já existia: " . $email_destino);
                    } else {
                        log_action("ERRO: Função generate_setup_token não encontrada para: " . $email_destino);
                    }
                    
                    $member_pass = null; // Não passa senha no email
                }
            }
            $delivered_prods[] = $res;
        }
    }

    $login_url_stmt = $pdo->query("SELECT valor FROM configuracoes WHERE chave = 'member_area_login_url'");
    $login_url_config = $login_url_stmt->fetchColumn();
    
    // Se não estiver configurado, gera URL padrão
    if (empty($login_url_config) || $login_url_config === '#') {
        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $login_url = $protocol . '://' . $host . '/member_login';
    } else {
        $login_url = $login_url_config;
    }

    $send_result = manual_send_email($email_destino, $nome_destino, $delivered_prods, $member_pass, $login_url, null, isset($setup_token) ? $setup_token : null);

    if ($send_result['success']) {
        $pdo->prepare("UPDATE vendas SET email_entrega_enviado = 1 WHERE checkout_session_uuid = ?")->execute([$venda_data['checkout_session_uuid']]);
        ob_clean();
        echo json_encode(['success' => true, 'message' => 'Ação realizada com sucesso.']);
    } else {
        ob_clean();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Ação processada, mas falha no envio do e-mail: ' . $send_result['error']]);
    }

} else {
    ob_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Ação desconhecida.']);
}

} catch (PDOException $e) {
    ob_clean();
    http_response_code(500);
    $error_msg = "Erro PDO: " . $e->getMessage() . " | SQL State: " . $e->getCode() . " | File: " . $e->getFile() . ":" . $e->getLine();
    log_action($error_msg);
    error_log("VENDAS_ACTIONS PDO Error: " . $error_msg);
    // Em desenvolvimento, mostra mais detalhes. Em produção, apenas mensagem genérica
    $debug_mode = (isset($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], 'localhost') !== false);
    echo json_encode([
        'success' => false, 
        'message' => $debug_mode ? 'Erro de banco de dados: ' . $e->getMessage() : 'Erro de banco de dados.'
    ]);
} catch (Exception $e) {
    ob_clean();
    http_response_code(500);
    $error_msg = "Erro Exception: " . $e->getMessage() . " | File: " . $e->getFile() . ":" . $e->getLine();
    log_action($error_msg);
    error_log("VENDAS_ACTIONS Exception: " . $error_msg);
    $debug_mode = (isset($_SERVER['HTTP_HOST']) && strpos($_SERVER['HTTP_HOST'], 'localhost') !== false);
    echo json_encode([
        'success' => false, 
        'message' => $debug_mode ? 'Erro ao processar ação: ' . $e->getMessage() : 'Erro ao processar ação.'
    ]);
} catch (Throwable $e) {
    ob_clean();
    http_response_code(500);
    $error_msg = "Erro Fatal: " . $e->getMessage() . " | File: " . $e->getFile() . ":" . $e->getLine();
    log_action($error_msg);
    error_log("VENDAS_ACTIONS Fatal: " . $error_msg);
    echo json_encode(['success' => false, 'message' => 'Erro fatal no servidor.']);
}
?>