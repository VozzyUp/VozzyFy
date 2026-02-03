<?php
require __DIR__ . '/../config/config.php';

// PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

$phpmailer_path = __DIR__ . '/../PHPMailer/src/';
if (file_exists($phpmailer_path . 'Exception.php')) { 
    require_once $phpmailer_path . 'Exception.php'; 
    require_once $phpmailer_path . 'PHPMailer.php'; 
    require_once $phpmailer_path . 'SMTP.php'; 
}

/**
 * Converte usuário de tipo 'usuario' para 'infoprodutor' se tiver compras aprovadas
 * @param string $email Email do usuário
 * @return bool True se conversão foi feita, False caso contrário
 */
if (!function_exists('convert_user_to_infoprodutor_if_needed')) {
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
                
                error_log("Usuário convertido para infoprodutor: $email (ID: {$user['id']})");
                return true;
            }
            
            return false;
        } catch (PDOException $e) {
            error_log("Erro ao converter usuário para infoprodutor: " . $e->getMessage());
            return false;
        }
    }
}

// Importa funções necessárias
if (!function_exists('process_single_product_delivery')) {
    function process_single_product_delivery($product_data, $customer_email) {
        global $pdo;
        $type = $product_data['tipo_entrega'];
        $content = $product_data['conteudo_entrega'];
        if ($type === 'link' && !empty($content)) return ['success' => true, 'product_name' => $product_data['produto_nome'], 'content_type' => 'link', 'content_value' => $content];
        if ($type === 'email_pdf' && !empty($content)) {
            $pdf_path = __DIR__ . '/../uploads/' . $content;
            if (file_exists($pdf_path)) {
                return ['success' => true, 'product_name' => $product_data['produto_nome'], 'content_type' => 'pdf', 'content_value' => $pdf_path];
            }
        }
        if ($type === 'area_membros') {
            $pdo->prepare("INSERT IGNORE INTO alunos_acessos (aluno_email, produto_id) VALUES (?, ?)")->execute([$customer_email, $product_data['produto_id']]);
            
            // NÃO converte automaticamente - usuário deve clicar no botão "mudar para infoprodutor" se SaaS estiver ativado
            
            return ['success' => true, 'product_name' => $product_data['produto_nome'], 'content_type' => 'area_membros', 'content_value' => null];
        }
        return ['success' => false, 'message' => 'Tipo desconhecido ou vazio'];
    }
}

// Define a função send_delivery_email_consolidated diretamente aqui para evitar problemas de inclusão
// Esta é uma versão simplificada baseada em obrigado.php
if (!function_exists('send_delivery_email_consolidated')) {
    function send_delivery_email_consolidated($to_email, $customer_name, $products, $pass, $login_url, $address_data = null, $setup_token = null) {
        global $pdo;
        error_log("send_delivery_email_consolidated chamada para: " . $to_email);
        
        $mail = new PHPMailer(true);
        try {
            // Busca configurações SMTP e TEMPLATE do banco
            $stmt = $pdo->query("SELECT chave, valor FROM configuracoes WHERE chave IN ('smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_encryption', 'smtp_from_email', 'smtp_from_name', 'email_template_delivery_subject', 'email_template_delivery_html')");
            $config = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            
            error_log("Configuracoes SMTP carregadas. Host: " . ($config['smtp_host'] ?? 'NÃO CONFIGURADO'));
            
            // Busca logo configurada da tabela configuracoes_sistema
            $logo_url_raw = '';
            if (function_exists('getSystemSetting')) {
                $logo_url_raw = getSystemSetting('logo_url', '');
            } else {
                $stmt_logo = $pdo->query("SELECT valor FROM configuracoes_sistema WHERE chave = 'logo_url' LIMIT 1");
                $logo_result = $stmt_logo->fetch(PDO::FETCH_ASSOC);
                $logo_url_raw = $logo_result ? $logo_result['valor'] : '';
            }
            
            // Constrói URL absoluta da logo
            $logo_url_final = '';
            if (!empty($logo_url_raw)) {
                if (strpos($logo_url_raw, 'http') === 0) {
                    $logo_url_final = $logo_url_raw;
                } else {
                    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
                    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                    $logo_path = ltrim($logo_url_raw, '/');
                    $logo_url_final = $protocol . '://' . $host . '/' . $logo_path;
                }
            }
            
            // Configuração de Remetente
            $default_from = 'noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
            $fromEmail = !empty($config['smtp_from_email']) ? $config['smtp_from_email'] : ($config['smtp_username'] ?? $default_from);
            if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) $fromEmail = $default_from;

            if (empty($config['smtp_host'])) {
                $mail->isMail();
                error_log("Usando mail() nativo do PHP");
            } else {
                $mail->isSMTP();
                $mail->Host = $config['smtp_host'];
                $mail->Port = $config['smtp_port'];
                $mail->SMTPAuth = true;
                $mail->Username = $config['smtp_username'];
                $mail->Password = $config['smtp_password'];
                $mail->SMTPSecure = $config['smtp_encryption'] == 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
                error_log("Usando SMTP: " . $config['smtp_host'] . ":" . $config['smtp_port']);
            }
            
            $mail->setFrom($fromEmail, $config['smtp_from_name'] ?? 'Starfy');
            $mail->addAddress($to_email, $customer_name);
            
            // Usa o assunto do banco ou um padrão
            $email_subject = trim($config['email_template_delivery_subject'] ?? '');
            $mail->Subject = !empty($email_subject) ? $email_subject : 'Seu acesso chegou!';
            $mail->isHTML(true);
            $mail->CharSet = 'UTF-8';

            // Usa o template do banco
            $template = $config['email_template_delivery_html'] ?? '';
            
            // Se o template estiver vazio, gera template padrão
            if (empty(trim($template))) {
                error_log("Template vazio, gerando template padrão");
                $template = '<p>Olá {CLIENT_NAME}, aqui estão seus produtos:</p><!-- LOOP_PRODUCTS_START --><p>{PRODUCT_NAME}</p><!-- LOOP_PRODUCTS_END -->';
            }

            // Prepara URL de criação de senha se houver token
            $setup_password_url = '';
            if ($setup_token) {
                $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $setup_password_url = $protocol . '://' . $host . '/member_setup_password?token=' . urlencode($setup_token);
            }
            
            // Substitui variáveis globais
            $body = str_replace(
                ['{CLIENT_NAME}', '{CLIENT_EMAIL}', '{MEMBER_AREA_PASSWORD}', '{LOGO_URL}'], 
                [$customer_name, $to_email, $pass ?? 'N/A', $logo_url_final], 
                $template
            );
            
            $body = str_replace('{SETUP_PASSWORD_URL}', htmlspecialchars($setup_password_url ?? ''), $body);
            $body = str_replace('{MEMBER_AREA_LOGIN_URL}', htmlspecialchars($login_url ?? '#'), $body);
            
            // Processa o Loop de Produtos
            $loop_start = '<!-- LOOP_PRODUCTS_START -->'; 
            $loop_end = '<!-- LOOP_PRODUCTS_END -->';
            if (strpos($body, $loop_start) !== false) {
                 $part = substr($body, strpos($body, $loop_start) + strlen($loop_start));
                 $part = substr($part, 0, strpos($part, $loop_end));
                 $html_prods = '';
                 foreach ($products as $p) {
                     $item = str_replace('{PRODUCT_NAME}', $p['product_name'], $part);
                     $item = str_replace('{PRODUCT_LINK}', ($p['content_type']=='link' ? $p['content_value'] : ''), $item);
                     
                     // Limpa tags condicionais
                     $types = ['link', 'pdf', 'area_membros'];
                     foreach($types as $t) {
                         $tag = 'PRODUCT_TYPE_'.strtoupper($t == 'area_membros' ? 'MEMBER_AREA' : $t);
                         if ($p['content_type'] == $t) $item = str_replace(["<!-- IF_$tag -->", "<!-- END_IF_$tag -->"], '', $item);
                         else $item = preg_replace("/<!-- IF_$tag -->.*?<!-- END_IF_$tag -->/s", "", $item);
                     }
                     
                     // Processa tags condicionais para área de membros
                     if ($p['content_type'] == 'area_membros') {
                         if ($setup_token) {
                             $item = str_replace(["<!-- IF_NEW_USER_SETUP -->", "<!-- END_IF_NEW_USER_SETUP -->"], '', $item);
                             $item = preg_replace("/<!-- IF_EXISTING_USER -->.*?<!-- END_IF_EXISTING_USER -->/s", "", $item);
                         } else {
                             $item = str_replace(["<!-- IF_EXISTING_USER -->", "<!-- END_IF_EXISTING_USER -->"], '', $item);
                             $item = preg_replace("/<!-- IF_NEW_USER_SETUP -->.*?<!-- END_IF_NEW_USER_SETUP -->/s", "", $item);
                         }
                         
                         $item = str_replace('{SETUP_PASSWORD_URL}', htmlspecialchars($setup_password_url ?? ''), $item);
                         $item = str_replace('{MEMBER_AREA_LOGIN_URL}', htmlspecialchars($login_url ?? '#'), $item);
                     } else {
                         $item = preg_replace("/<!-- IF_NEW_USER_SETUP -->.*?<!-- END_IF_NEW_USER_SETUP -->/s", "", $item);
                         $item = preg_replace("/<!-- IF_EXISTING_USER -->.*?<!-- END_IF_EXISTING_USER -->/s", "", $item);
                     }
                     
                     $html_prods .= $item;
                 }
                 $body = str_replace($loop_start . $part . $loop_end, $html_prods, $body);
            }

            $mail->Body = $body;
            
            // Anexos
            foreach ($products as $p) {
                if ($p['content_type'] == 'pdf' && file_exists($p['content_value'])) {
                    $mail->addAttachment($p['content_value'], basename($p['content_value']));
                }
            }

            error_log("Tentando enviar email...");
            $mail->send();
            error_log("Email enviado com sucesso!");
            return true;
        } catch (Exception $e) {
            error_log("ERRO ao enviar email: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            return false;
        }
    }
}

// Importa Helper de criação de senha
if (file_exists(__DIR__ . '/../helpers/password_setup_helper.php')) {
    require_once __DIR__ . '/../helpers/password_setup_helper.php';
}

// Inicia output buffering para capturar qualquer saída indesejada
ob_start();

// Aplicar headers de segurança antes de qualquer output
require_once __DIR__ . '/../config/security_headers.php';
if (function_exists('apply_security_headers')) {
    apply_security_headers(false); // CSP permissivo para APIs
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ob_end_clean();
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método não permitido']);
    exit;
}

// Rate limiting para prevenir abuso
require_once __DIR__ . '/../helpers/security_helper.php';
$client_ip = get_client_ip();
$rate_check = check_rate_limit_db('resend_access', 5, 300, $client_ip); // 5 requisições por 5 minutos

if (!$rate_check['allowed']) {
    ob_end_clean();
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Muitas tentativas. Aguarde alguns minutos antes de tentar novamente.']);
    exit;
}

// Limitar tamanho da requisição
$max_request_size = 10240; // 10KB
$content_length = $_SERVER['CONTENT_LENGTH'] ?? 0;
if ($content_length > $max_request_size) {
    ob_end_clean();
    http_response_code(413);
    echo json_encode(['success' => false, 'error' => 'Requisição muito grande.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$payment_id = $input['payment_id'] ?? null;

// Validação rigorosa do payment_id
if (!$payment_id) {
    ob_end_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'ID da transação é obrigatório']);
    exit;
}

// Whitelist de caracteres permitidos e limite de tamanho
if (!preg_match('/^[a-zA-Z0-9_-]+$/', $payment_id) || strlen($payment_id) > 255) {
    log_security_event('invalid_payment_id_resend', [
        'ip' => $client_ip,
        'payment_id_length' => strlen($payment_id ?? ''),
        'payment_id_preview' => substr($payment_id ?? '', 0, 20)
    ]);
    ob_end_clean();
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'ID da transação inválido']);
    exit;
}

// Limpa qualquer saída capturada antes de processar
ob_clean();

try {
    // Busca a venda principal usando transacao_id
    $stmt = $pdo->prepare("
        SELECT 
            v.*,
            p.id as produto_id,
            p.nome as produto_nome,
            p.tipo_entrega,
            p.conteudo_entrega,
            p.checkout_config
        FROM vendas v
        JOIN produtos p ON v.produto_id = p.id
        WHERE v.transacao_id = ?
        ORDER BY v.id ASC
        LIMIT 1
    ");
    $stmt->execute([$payment_id]);
    $main_sale = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$main_sale) {
        ob_end_clean();
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Transação não encontrada']);
        exit;
    }
    
    // Verifica se o pagamento foi aprovado
    if ($main_sale['status_pagamento'] !== 'approved') {
        ob_end_clean();
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'O pagamento ainda não foi aprovado']);
        exit;
    }
    
    // Verifica se já foi reenviado manualmente (usando checkout_session_uuid para agrupar todas as vendas)
    $checkout_session_uuid = $main_sale['checkout_session_uuid'];
    $ja_reenviado = false;
    
    // Verifica se a coluna existe antes de usar
    try {
        if ($checkout_session_uuid) {
            // Tenta verificar se a coluna existe
            $stmt_check_col = $pdo->query("SHOW COLUMNS FROM vendas LIKE 'email_reenviado_manual'");
            $col_exists = $stmt_check_col->rowCount() > 0;
            
            if ($col_exists) {
                $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM vendas WHERE checkout_session_uuid = ? AND email_reenviado_manual = 1");
                $stmt_check->execute([$checkout_session_uuid]);
                $ja_reenviado = $stmt_check->fetchColumn() > 0;
            }
        }
    } catch (Exception $e) {
        // Se a coluna não existir, ignora o erro e continua (permite reenvio)
        error_log("Coluna email_reenviado_manual pode não existir: " . $e->getMessage());
    }
    
    if ($ja_reenviado) {
        ob_end_clean();
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'O e-mail já foi reenviado anteriormente. Verifique sua caixa de entrada e spam.']);
        exit;
    }
    
    // Busca todas as vendas desta sessão de checkout
    $stmt_all = $pdo->prepare("
        SELECT v.*, p.usuario_id, p.nome as produto_nome, p.tipo_entrega, p.conteudo_entrega, p.checkout_config, p.checkout_hash 
        FROM vendas v 
        JOIN produtos p ON v.produto_id = p.id 
        WHERE v.checkout_session_uuid = ?
    ");
    $stmt_all->execute([$checkout_session_uuid ?: $payment_id]);
    $all_sales = $stmt_all->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($all_sales)) {
        ob_end_clean();
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Nenhuma venda encontrada para esta transação']);
        exit;
    }
    
    $main_sale = $all_sales[0];
    
    // Processa produtos
    $processed_prods = [];
    $pass = null;
    $setup_token = null;
    
    foreach ($all_sales as $s) {
        $res = process_single_product_delivery($s, $s['comprador_email']);
        
        if ($res['success']) {
            if ($res['content_type'] == 'area_membros') {
                // Verifica se usuário já existe
                $stmt_check = $pdo->prepare("SELECT id, senha FROM usuarios WHERE usuario = ? AND tipo = 'usuario'");
                $stmt_check->execute([$s['comprador_email']]);
                $existing_user = $stmt_check->fetch(PDO::FETCH_ASSOC);
                
                if (!$existing_user) {
                    // Cliente NOVO - criar usuário e gerar token
                    $temp_password = bin2hex(random_bytes(32));
                    $hashed_temp = password_hash($temp_password, PASSWORD_DEFAULT);
                    
                    try {
                        $stmt_insert = $pdo->prepare("INSERT INTO usuarios (usuario, nome, senha, tipo) VALUES (?, ?, ?, 'usuario')");
                        $stmt_insert->execute([$s['comprador_email'], $s['comprador_nome'], $hashed_temp]);
                        $new_user_id = $pdo->lastInsertId();
                        
                        // Gerar token de criação de senha
                        if ($new_user_id && function_exists('generate_setup_token')) {
                            $setup_token = generate_setup_token($new_user_id);
                        }
                    } catch (PDOException $e) {
                        error_log("Erro ao criar usuário para reenvio: " . $e->getMessage());
                    }
                }
            }
            $processed_prods[] = $res;
        }
    }
    
    // Envia email se houver produtos processados
    if (!empty($processed_prods)) {
        $login_url_stmt = $pdo->query("SELECT valor FROM configuracoes WHERE chave = 'member_area_login_url'");
        $login_url_config = $login_url_stmt->fetchColumn();
        
        if (empty($login_url_config) || $login_url_config === '#') {
            $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $login_url = $protocol . '://' . $host . '/member_login';
        } else {
            $login_url = $login_url_config;
        }
        
        // Verifica se a função existe antes de chamar
        if (!function_exists('send_delivery_email_consolidated')) {
            ob_end_clean();
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Função de envio de e-mail não encontrada. Verifique a configuração do sistema.']);
            exit;
        }
        
        $email_sent = send_delivery_email_consolidated($main_sale['comprador_email'], $main_sale['comprador_nome'], $processed_prods, $pass, $login_url, null, $setup_token);
        
        if ($email_sent) {
            // Marca como reenviado manualmente para todas as vendas desta sessão
            // Verifica se a coluna existe antes de atualizar
            try {
                $stmt_check_col = $pdo->query("SHOW COLUMNS FROM vendas LIKE 'email_reenviado_manual'");
                $col_exists = $stmt_check_col->rowCount() > 0;
                
                if ($col_exists) {
                    if ($checkout_session_uuid) {
                        $pdo->prepare("UPDATE vendas SET email_reenviado_manual = 1 WHERE checkout_session_uuid = ?")->execute([$checkout_session_uuid]);
                    } else {
                        $pdo->prepare("UPDATE vendas SET email_reenviado_manual = 1 WHERE transacao_id = ?")->execute([$payment_id]);
                    }
                }
            } catch (Exception $e) {
                // Se a coluna não existir, apenas loga o erro mas não falha
                error_log("Erro ao atualizar email_reenviado_manual (coluna pode não existir): " . $e->getMessage());
            }
            
            ob_end_clean();
            echo json_encode(['success' => true, 'message' => 'E-mail reenviado com sucesso!']);
        } else {
            ob_end_clean();
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Falha ao reenviar e-mail. Verifique as configurações SMTP.']);
        }
    } else {
        ob_end_clean();
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Nenhum produto encontrado para reenviar']);
    }
    
} catch (Exception $e) {
    ob_end_clean();
    http_response_code(500);
    error_log("Erro ao reenviar e-mail de acesso: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    error_log("File: " . $e->getFile() . " Line: " . $e->getLine());
    echo json_encode(['success' => false, 'error' => 'Erro interno ao reenviar acesso: ' . $e->getMessage()]);
} catch (Error $e) {
    ob_end_clean();
    http_response_code(500);
    error_log("Erro fatal ao reenviar e-mail de acesso: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
    error_log("File: " . $e->getFile() . " Line: " . $e->getLine());
    echo json_encode(['success' => false, 'error' => 'Erro fatal ao reenviar acesso: ' . $e->getMessage()]);
}

