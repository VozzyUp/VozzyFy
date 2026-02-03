<?php
require __DIR__ . '/config/config.php';

// PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

$phpmailer_path = __DIR__ . '/PHPMailer/src/';
if (file_exists($phpmailer_path . 'Exception.php')) { 
    require_once $phpmailer_path . 'Exception.php'; 
    require_once $phpmailer_path . 'PHPMailer.php'; 
    require_once $phpmailer_path . 'SMTP.php'; 
}

// Importa Helper de criação de senha
if (file_exists(__DIR__ . '/helpers/password_setup_helper.php')) {
    require_once __DIR__ . '/helpers/password_setup_helper.php';
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

// Inclui apenas as funções necessárias (sem executar o código principal)
if (!function_exists('process_single_product_delivery')) {
    function process_single_product_delivery($product_data, $customer_email) {
        global $pdo;
        $type = $product_data['tipo_entrega'];
        $content = $product_data['conteudo_entrega'];
        if ($type === 'link' && !empty($content)) return ['success' => true, 'product_name' => $product_data['produto_nome'], 'content_type' => 'link', 'content_value' => $content];
        if ($type === 'email_pdf' && !empty($content) && file_exists('uploads/' . $content)) return ['success' => true, 'product_name' => $product_data['produto_nome'], 'content_type' => 'pdf', 'content_value' => 'uploads/' . $content];
        if ($type === 'area_membros') {
            $pdo->prepare("INSERT IGNORE INTO alunos_acessos (aluno_email, produto_id) VALUES (?, ?)")->execute([$customer_email, $product_data['produto_id']]);
            
            // NÃO converte automaticamente - usuário deve clicar no botão "mudar para infoprodutor" se SaaS estiver ativado
            
            return ['success' => true, 'product_name' => $product_data['produto_nome'], 'content_type' => 'area_membros', 'content_value' => null];
        }
        if ($type === 'somente_pagamento') {
            return ['success' => true, 'product_name' => $product_data['produto_nome'], 'content_type' => 'somente_pagamento', 'content_value' => null];
        }
        return ['success' => false, 'message' => 'Tipo desconhecido ou vazio'];
    }
}

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
                    // Já é uma URL absoluta
                    $logo_url_final = $logo_url_raw;
                } else {
                    // É um caminho relativo, constrói URL absoluta
                    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
                    $host = $_SERVER['HTTP_HOST'];
                    $logo_path = ltrim($logo_url_raw, '/');
                    $logo_url_final = $protocol . '://' . $host . '/' . $logo_path;
                }
            }
            error_log("Logo URL final: " . ($logo_url_final ?: 'NÃO CONFIGURADA'));
            
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
            
            // Aplicar filtro no destinatário antes de adicionar
            $final_recipient = $to_email;
            if (function_exists('apply_filters')) {
                $final_recipient = apply_filters('email_recipient', $to_email, 'delivery');
            }
            
            $mail->addAddress($final_recipient, $customer_name);
            
            // Usa o assunto do banco ou um padrão
            $email_subject = trim($config['email_template_delivery_subject'] ?? '');
            $email_subject = !empty($email_subject) ? $email_subject : 'Seu acesso chegou!';
            
            // Aplicar filtro no assunto
            if (function_exists('apply_filters')) {
                $context = [
                    'customer_name' => $customer_name,
                    'products' => $products,
                    'to_email' => $final_recipient
                ];
                $email_subject = apply_filters('email_subject', $email_subject, 'delivery', $context);
            }
            
            $mail->Subject = $email_subject;
            $mail->isHTML(true);
            $mail->CharSet = 'UTF-8';

            // Usa o template do banco
            $template = $config['email_template_delivery_html'] ?? '';
            
            // Aplicar filtro no template antes de processar
            if (function_exists('apply_filters')) {
                $template = apply_filters('email_template', $template, 'delivery');
            }
            
            // Se o template estiver vazio, gera template padrão usando configurações da plataforma
            if (empty(trim($template))) {
                error_log("Obrigado.php: Template vazio, gerando template padrão com configurações da plataforma");
                
                // Carrega helper de template
                if (file_exists(__DIR__ . '/helpers/email_template_helper.php')) {
                    require_once __DIR__ . '/helpers/email_template_helper.php';
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
                    $template = '<p>Olá {CLIENT_NAME}, aqui estão seus produtos:</p><!-- LOOP_PRODUCTS_START --><p>{PRODUCT_NAME}</p><!-- LOOP_PRODUCTS_END -->';
                }
            }

            // Prepara URL de criação de senha se houver token
            $setup_password_url = '';
            if ($setup_token) {
                $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $setup_password_url = $protocol . '://' . $host . '/member_setup_password?token=' . urlencode($setup_token);
            }
            
            // Substitui variáveis globais (exceto as que serão substituídas dentro dos blocos de produtos)
            $body = str_replace(
                ['{CLIENT_NAME}', '{CLIENT_EMAIL}', '{MEMBER_AREA_PASSWORD}', '{LOGO_URL}'], 
                [$customer_name, $to_email, $pass ?? 'N/A', $logo_url_final], 
                $template
            );
            
            // Substitui placeholders globais que não estão dentro de blocos condicionais (fallback)
            $body = str_replace('{SETUP_PASSWORD_URL}', htmlspecialchars($setup_password_url ?? ''), $body);
            $body = str_replace('{MEMBER_AREA_LOGIN_URL}', htmlspecialchars($login_url ?? '#'), $body);
            
            // Também substitui URLs de imagens quebradas ou genéricas pela logo configurada
            if (!empty($logo_url_final)) {
                // Substitui URLs comuns de imagens quebradas ou placeholders
                $body = preg_replace('/src=["\']https?:\/\/[^"\']*imgbb\.com[^"\']*["\']/i', 'src="' . $logo_url_final . '"', $body);
                $body = preg_replace('/src=["\']https?:\/\/[^"\']*ibb\.co[^"\']*["\']/i', 'src="' . $logo_url_final . '"', $body);
                // Substitui qualquer src vazio ou quebrado no início do template
                $body = preg_replace('/<img[^>]*src=["\'](?!https?:\/\/)[^"\']*["\'][^>]*>/i', '<img src="' . $logo_url_final . '" alt="Logo" style="max-width: 200px; height: auto;">', $body, 1);
            }
            
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
                         
                         // Substitui placeholders de área de membros DENTRO do item (após processar blocos condicionais)
                         $item = str_replace('{SETUP_PASSWORD_URL}', htmlspecialchars($setup_password_url ?? ''), $item);
                         $item = str_replace('{MEMBER_AREA_LOGIN_URL}', htmlspecialchars($login_url ?? '#'), $item);
                     } else {
                         // Remove ambas as tags se não for área de membros
                         $item = preg_replace("/<!-- IF_NEW_USER_SETUP -->.*?<!-- END_IF_NEW_USER_SETUP -->/s", "", $item);
                         $item = preg_replace("/<!-- IF_EXISTING_USER -->.*?<!-- END_IF_EXISTING_USER -->/s", "", $item);
                     }
                     
                     $html_prods .= $item;
                 }
                 $body = str_replace($loop_start . $part . $loop_end, $html_prods, $body);
            }
            
            // Aplicar filtro no corpo do email antes de enviar
            if (function_exists('apply_filters')) {
                $context = [
                    'customer_name' => $customer_name,
                    'products' => $products,
                    'to_email' => $final_recipient,
                    'login_url' => $login_url,
                    'address_data' => $address_data
                ];
                $body = apply_filters('email_body', $body, 'delivery', $context);
            }

            $mail->Body = $body;
            
            // Anexos
            foreach ($products as $p) {
                if ($p['content_type'] == 'pdf' && file_exists($p['content_value'])) {
                    $mail->addAttachment($p['content_value'], basename($p['content_value']));
                }
            }
            
            // Obter headers do PHPMailer para filtro
            $headers_array = [];
            foreach ($mail->getCustomHeaders() as $header) {
                $headers_array[] = $header[0] . ': ' . $header[1];
            }
            
            // Aplicar filtro nos headers
            if (function_exists('apply_filters')) {
                $headers_array = apply_filters('email_headers', $headers_array, 'delivery');
            }
            
            // Executar hook antes de enviar email
            if (function_exists('do_action')) {
                do_action('before_send_email', $final_recipient, $email_subject, $body, 'delivery');
            }

            error_log("Tentando enviar email...");
            $send_result = $mail->send();
            
            // Executar hook depois de enviar email
            if (function_exists('do_action')) {
                do_action('after_send_email', $final_recipient, $email_subject, $send_result, 'delivery');
                
                if ($send_result) {
                    do_action('email_sent', $final_recipient, $email_subject, 'delivery');
                } else {
                    do_action('email_failed', $final_recipient, $email_subject, $mail->ErrorInfo, 'delivery');
                }
            }
            
            error_log("Email enviado com sucesso!");
            return $send_result;
        } catch (Exception $e) {
            error_log("ERRO ao enviar email: " . $e->getMessage());
            error_log("Stack trace: " . $e->getTraceAsString());
            return false;
        }
    }
}

$payment_id = $_GET['payment_id'] ?? null;
$sale_details = null;
$tracking_config = [];
$fb_events_enabled = [];
$gg_events_enabled = [];

// NEW: Variables for Starfy Track
$starfy_track_endpoint = null;
$starfy_tracking_id_hash = null;
$starfy_checkout_session_uuid = null; // To get from DB, if it exists

if ($payment_id) {
    try {
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
            LIMIT 1
        ");
        $stmt->execute([$payment_id]);
        $sale_details = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Se a venda existe mas o email ainda não foi enviado, verifica status e processa entrega
        if ($sale_details && $sale_details['email_entrega_enviado'] == 0) {
            // Se o status no BD não está aprovado, verifica na API do gateway
            $status_aprovado = false;
            if ($sale_details['status_pagamento'] === 'approved') {
                $status_aprovado = true;
            } else {
                // Verifica status real na API (pode estar desatualizado no BD)
                error_log("Obrigado.php: Status no BD é '" . $sale_details['status_pagamento'] . "'. Verificando na API...");
                
                // Busca token do gateway
                $stmt_gateway = $pdo->prepare("SELECT u.mp_access_token, u.pushinpay_token, p.gateway FROM produtos p LEFT JOIN usuarios u ON p.usuario_id = u.id WHERE p.id = ?");
                $stmt_gateway->execute([$sale_details['produto_id']]);
                $gateway_info = $stmt_gateway->fetch(PDO::FETCH_ASSOC);
                
                if ($gateway_info) {
                    $payment_id = $sale_details['transacao_id'];
                    
                    // Verifica Mercado Pago
                    if (!empty($gateway_info['mp_access_token']) && ($gateway_info['gateway'] === 'mercadopago' || empty($gateway_info['gateway']))) {
                        $ch = curl_init("https://api.mercadopago.com/v1/payments/" . $payment_id);
                        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $gateway_info['mp_access_token']]);
                        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                        $mp_res = json_decode(curl_exec($ch), true);
                        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                        curl_close($ch);
                        
                        if ($http_code == 200 && isset($mp_res['status'])) {
                            $status_real = strtolower($mp_res['status']);
                            if ($status_real === 'approved' || $status_real === 'paid' || $status_real === 'completed') {
                                $status_aprovado = true;
                                // Atualiza status no BD
                                $pdo->prepare("UPDATE vendas SET status_pagamento = 'approved' WHERE transacao_id = ?")->execute([$payment_id]);
                                error_log("Obrigado.php: Status atualizado para 'approved' no BD após verificação na API Mercado Pago");
                            }
                        }
                    }
                    // Verifica PushinPay
                    elseif (!empty($gateway_info['pushinpay_token']) && $gateway_info['gateway'] === 'pushinpay') {
                        $endpoints = [
                            'https://api.pushinpay.com.br/api/transactions/' . $payment_id,
                            'https://api.pushinpay.com.br/api/pix/transactions/' . $payment_id
                        ];
                        
                        foreach ($endpoints as $endpoint) {
                            $ch = curl_init($endpoint);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                                'Authorization: Bearer ' . $gateway_info['pushinpay_token'],
                                'Accept: application/json'
                            ]);
                            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                            $pp_res = json_decode(curl_exec($ch), true);
                            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                            curl_close($ch);
                            
                            if ($http_code >= 200 && $http_code < 300 && isset($pp_res['status'])) {
                                $status_real = strtolower($pp_res['status']);
                                if ($status_real === 'approved' || $status_real === 'paid' || $status_real === 'completed') {
                                    $status_aprovado = true;
                                    // Atualiza status no BD
                                    $pdo->prepare("UPDATE vendas SET status_pagamento = 'approved' WHERE transacao_id = ?")->execute([$payment_id]);
                                    error_log("Obrigado.php: Status atualizado para 'approved' no BD após verificação na API PushinPay");
                                    break;
                                }
                            }
                        }
                    }
                }
            }
            
            // Processa entrega apenas se estiver aprovado
            if ($status_aprovado) {
                error_log("Obrigado.php: Processando entrega diretamente para transacao: " . $payment_id);
            
            // Busca todas as vendas desta transação
            $stmt_all = $pdo->prepare("
                SELECT v.*, p.usuario_id, p.nome as produto_nome, p.tipo_entrega, p.conteudo_entrega, p.checkout_config, p.checkout_hash 
                FROM vendas v 
                JOIN produtos p ON v.produto_id = p.id 
                WHERE v.transacao_id = ?
            ");
            $stmt_all->execute([$payment_id]);
            $all_sales = $stmt_all->fetchAll(PDO::FETCH_ASSOC);
            
            if (!empty($all_sales)) {
                $main_sale = $all_sales[0];
                
                // Processa produtos
                $processed_prods = [];
                $pass = null;
                error_log("Obrigado.php: Total de vendas para processar: " . count($all_sales));
                
                foreach ($all_sales as $s) {
                    error_log("Obrigado.php: Processando produto - Nome: " . $s['produto_nome'] . ", Tipo entrega: " . ($s['tipo_entrega'] ?? 'NÃO CONFIGURADO') . ", Conteudo: " . ($s['conteudo_entrega'] ?? 'VAZIO'));
                    $res = process_single_product_delivery($s, $s['comprador_email']);
                    error_log("Obrigado.php: Resultado processamento - Success: " . ($res['success'] ? 'SIM' : 'NÃO') . ", Message: " . ($res['message'] ?? 'OK'));
                    
                    if ($res['success']) {
                        if ($res['content_type'] == 'area_membros') {
                            // Verifica se usuário já existe
                            $stmt_check = $pdo->prepare("SELECT id, senha FROM usuarios WHERE usuario = ? AND tipo = 'usuario'");
                            $stmt_check->execute([$s['comprador_email']]);
                            $existing_user = $stmt_check->fetch(PDO::FETCH_ASSOC);
                            
                            if ($existing_user) {
                                // Cliente JÁ TEM conta
                                // NÃO gerar senha, apenas garantir acesso (já feito por process_single_product_delivery)
                                error_log("Obrigado.php: Cliente existente detectado: " . $s['comprador_email'] . " - Não gerando senha");
                                $pass = null; // Não passa senha no email
                            } else {
                                // Cliente NOVO
                                // Criar usuário com senha temporária (será substituída quando criar senha via token)
                                $temp_password = bin2hex(random_bytes(32));
                                $hashed_temp = password_hash($temp_password, PASSWORD_DEFAULT);
                                
                                try {
                                    $stmt_insert = $pdo->prepare("INSERT INTO usuarios (usuario, nome, senha, tipo) VALUES (?, ?, ?, 'usuario')");
                                    $stmt_insert->execute([$s['comprador_email'], $s['comprador_nome'], $hashed_temp]);
                                    $new_user_id = $pdo->lastInsertId();
                                    error_log("Obrigado.php: Novo usuário criado (com senha temporária): " . $s['comprador_email'] . " - ID: " . $new_user_id);
                                } catch (PDOException $e) {
                                    error_log("Obrigado.php: ERRO ao criar usuário: " . $e->getMessage());
                                    $new_user_id = null;
                                }
                                
                                // Gerar token de criação de senha apenas se o usuário foi criado
                                if ($new_user_id && function_exists('generate_setup_token')) {
                                    $setup_token = generate_setup_token($new_user_id);
                                    error_log("Obrigado.php: Token de criação de senha gerado para novo usuário: " . $s['comprador_email']);
                                } else {
                                    if (!$new_user_id) {
                                        error_log("Obrigado.php: ERRO - Não foi possível criar usuário");
                                    } else {
                                        error_log("Obrigado.php: ERRO - Função generate_setup_token não encontrada!");
                                    }
                                    $setup_token = null;
                                }
                                
                                $pass = null; // Não passa senha no email
                            }
                        }
                        $processed_prods[] = $res;
                        error_log("Obrigado.php: Produto adicionado à lista de processados");
                    } else {
                        error_log("Obrigado.php: Produto NÃO foi processado - " . ($res['message'] ?? 'Erro desconhecido'));
                    }
                }
                
                error_log("Obrigado.php: Total de produtos processados com sucesso: " . count($processed_prods));
                
                // Envia email se houver produtos processados
                if (!empty($processed_prods)) {
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
                    
                    error_log("Obrigado.php: Preparando envio de email para: " . $main_sale['comprador_email']);
                    error_log("Obrigado.php: Login URL: " . $login_url);
                    error_log("Obrigado.php: Senha gerada: " . ($pass ? 'SIM' : 'NÃO'));
                    error_log("Obrigado.php: Token de setup: " . (isset($setup_token) && $setup_token ? 'SIM' : 'NÃO'));
                    
                    $email_sent = send_delivery_email_consolidated($main_sale['comprador_email'], $main_sale['comprador_nome'], $processed_prods, $pass, $login_url, null, isset($setup_token) ? $setup_token : null);
                    
                    if ($email_sent) {
                        $pdo->prepare("UPDATE vendas SET email_entrega_enviado = 1 WHERE checkout_session_uuid = ?")->execute([$main_sale['checkout_session_uuid']]);
                        error_log("Obrigado.php: Email enviado com sucesso! Flag atualizada.");
                    } else {
                        error_log("Obrigado.php: ERRO ao enviar email! Verifique os logs de erro do PHP.");
                    }
                } else {
                    error_log("Obrigado.php: AVISO - Nenhum produto foi processado. Verifique se o produto tem tipo_entrega configurado.");
                }
            } else {
                error_log("Obrigado.php: Pagamento ainda não foi aprovado. Status atual: " . ($sale_details['status_pagamento'] ?? 'N/A'));
            }
            }
        }

        if ($sale_details) {
            $checkout_config = json_decode($sale_details['checkout_config'] ?? '{}', true);
            $tracking_config = $checkout_config['tracking'] ?? [];
            
            // Retrocompatibilidade para o pixel antigo
            if (empty($tracking_config['facebookPixelId']) && !empty($checkout_config['facebookPixelId'])) {
                $tracking_config['facebookPixelId'] = $checkout_config['facebookPixelId'];
            }
            
            $tracking_events = $tracking_config['events'] ?? [];
            $fb_events_enabled = $tracking_events['facebook'] ?? [];
            $gg_events_enabled = $tracking_events['google'] ?? [];

            // NEW: Fetch Starfy Track info
            $starfy_checkout_session_uuid = $sale_details['checkout_session_uuid'];
            $stmt_get_starfy_tracking = $pdo->prepare("SELECT stp.tracking_id FROM starfy_tracking_products stp JOIN produtos p ON stp.produto_id = p.id WHERE p.id = ?");
            $stmt_get_starfy_tracking->execute([$sale_details['produto_id']]);
            $starfy_tracking_id_hash = $stmt_get_starfy_tracking->fetchColumn();

            if ($starfy_tracking_id_hash) {
                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
                $domainName = $_SERVER['HTTP_HOST'];
                $basePath = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
                $starfy_track_endpoint = $protocol . $domainName . $basePath . '/track.php';
            }
        }
    } catch (PDOException $e) {
        // Em um ambiente de produção, é melhor logar este erro do que exibi-lo.
        error_log("Erro ao buscar detalhes da venda para rastreamento: " . $e->getMessage());
    }
}

$fbPixelId = $tracking_config['facebookPixelId'] ?? '';
$gaId = $tracking_config['googleAnalyticsId'] ?? '';
$gAdsId = $tracking_config['googleAdsId'] ?? '';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Obrigado pela sua compra!</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        body { font-family: 'Inter', sans-serif; }
    </style>

    <!-- Scripts de Rastreamento de Compra Aprovada -->
    <?php if ($sale_details): ?>

        <?php // Facebook Pixel Purchase Event ?>
        <?php if (!empty($fbPixelId) && !empty($fb_events_enabled['purchase'])): ?>
        <script>
        !function(f,b,e,v,n,t,s)
        {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
        n.callMethod.apply(n,arguments):n.queue.push(arguments)};
        if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
        n.queue=[];t=b.createElement(e);t.async=!0;
        t.src=v;s=b.getElementsByTagName(e)[0];
        s.parentNode.insertBefore(t,s)}(window, document,'script',
        'https://connect.facebook.net/en_US/fbevents.js');
        fbq('init', '<?php echo htmlspecialchars($fbPixelId); ?>');
        fbq('track', 'PageView');
        fbq('track', 'Purchase', {
            value: <?php echo (float)$sale_details['valor']; ?>,
            currency: 'BRL'
        });
        </script>
        <noscript><img height="1" width="1" style="display:none"
        src="https://www.facebook.com/tr?id=<?php echo htmlspecialchars($fbPixelId); ?>&ev=Purchase&cd[value]=<?php echo (float)$sale_details['valor']; ?>&cd[currency]=BRL"
        /></noscript>
        <?php endif; ?>

        <?php // Google Analytics & Ads Purchase Event ?>
        <?php if ((!empty($gaId) || !empty($gAdsId)) && !empty($gg_events_enabled['purchase'])): 
            $google_primary_id = !empty($gAdsId) ? $gAdsId : $gaId;
        ?>
        <script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo htmlspecialchars($google_primary_id); ?>"></script>
        <script>
          window.dataLayer = window.dataLayer || [];
          function gtag(){dataLayer.push(arguments);}
          gtag('js', new Date());

          <?php if (!empty($gAdsId)): ?>
          gtag('config', '<?php echo htmlspecialchars($gAdsId); ?>');
          <?php endif; ?>
          <?php if (!empty($gaId)): ?>
          gtag('config', '<?php echo htmlspecialchars($gaId); ?>');
          <?php endif; ?>

          gtag('event', 'purchase', {
            "transaction_id": "<?php echo htmlspecialchars($sale_details['transacao_id']); ?>",
            "value": <?php echo (float)$sale_details['valor']; ?>,
            "currency": "BRL",
            "items": [{
              "item_id": "<?php echo htmlspecialchars($sale_details['produto_id']); ?>",
              "item_name": "<?php echo htmlspecialchars($sale_details['produto_nome']); ?>",
              "price": <?php echo (float)$sale_details['valor']; ?>,
              "quantity": 1
            }]
          });
        </script>
        <?php endif; ?>

        <!-- NEW: STARFY TRACK - Purchase Event -->
        <?php if ($starfy_track_endpoint && $starfy_tracking_id_hash && $starfy_checkout_session_uuid): ?>
        <script>
            (function() {
                const STARFY_TRACK_ID_HASH = '<?php echo htmlspecialchars($starfy_tracking_id_hash); ?>';
                const TRACK_ENDPOINT = '<?php echo $starfy_track_endpoint; ?>';
                const CHECKOUT_SESSION_UUID = '<?php echo htmlspecialchars($starfy_checkout_session_uuid); ?>';
                const PRODUCT_ID = <?php echo (int)$sale_details['produto_id']; ?>;
                const PURCHASE_VALUE = <?php echo (float)$sale_details['valor']; ?>;

                // Send event to tracking endpoint
                function sendStarfyTrackEvent(eventType, eventData = {}) {
                    const payload = {
                        tracking_id: STARFY_TRACK_ID_HASH,
                        session_id: CHECKOUT_SESSION_UUID, // Use the checkout session UUID as the session_id for this event
                        event_type: eventType,
                        event_data: {
                            ...eventData,
                            url: window.location.href,
                            referrer: document.referrer,
                            transaction_id: '<?php echo htmlspecialchars($sale_details['transacao_id']); ?>',
                            product_id: PRODUCT_ID,
                            value: PURCHASE_VALUE,
                            currency: 'BRL'
                        }
                    };

                    fetch(TRACK_ENDPOINT, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(payload)
                    }).then(response => {
                        if (!response.ok) {
                            // Erro ao enviar evento
                        }
                    }).catch(error => {
                        // Erro de rede ao enviar evento
                    });
                }

                // Track Purchase on page load
                sendStarfyTrackEvent('purchase');
            })();
        </script>
        <?php endif; ?>
        <!-- Fim STARFY TRACK -->
        
        <!-- Script Manual de Rastreamento -->
        <?php 
        require_once __DIR__ . '/helpers/security_helper.php';
        $custom_script = $tracking_config['customScript'] ?? '';
        if (!empty($custom_script)): 
            $sanitized_script = sanitize_custom_script($custom_script);
            if (!empty($sanitized_script)) {
                echo $sanitized_script;
            }
        endif; 
        ?>
        <!-- Fim Script Manual -->
        
    <?php endif; ?>
    <!-- Fim dos Scripts de Rastreamento -->

    <?php do_action('public_page_head'); ?>
</head>
<body class="bg-gray-100 flex items-center justify-center min-h-screen p-4">
    <?php do_action('obrigado_page_before'); ?>
    <div class="w-full max-w-lg p-6 sm:p-8 bg-white rounded-2xl shadow-lg text-center">
        <div class="mx-auto flex items-center justify-center h-20 w-20 rounded-full bg-green-100 mb-6">
            <i data-lucide="check-circle-2" class="h-12 w-12 text-green-600"></i>
        </div>
        <h1 class="text-3xl font-bold text-gray-800">Pagamento Recebido!</h1>
        <p class="text-gray-600 mt-4 text-lg">
            Obrigado pela sua compra! Em breve você receberá um e-mail com todos os detalhes e o acesso ao seu produto.
        </p>
        <p class="text-gray-500 mt-3 text-sm">
            <strong>Importante:</strong> Caso não receba o e-mail em alguns minutos, verifique também sua <strong>caixa de spam</strong> ou lixo eletrônico.
        </p>
        
        <?php 
        // Verifica se já foi reenviado manualmente
        // NOTA: Coluna email_reenviado_manual pode não existir em todas as instalações
        $ja_reenviado = false;
        if ($sale_details && $sale_details['checkout_session_uuid']) {
            try {
                // Verifica se a coluna existe antes de usar
                $stmt_check_col = $pdo->query("SHOW COLUMNS FROM vendas LIKE 'email_reenviado_manual'");
                if ($stmt_check_col->rowCount() > 0) {
                    $stmt_check_reenvio = $pdo->prepare("SELECT COUNT(*) FROM vendas WHERE checkout_session_uuid = ? AND email_reenviado_manual = 1");
                    $stmt_check_reenvio->execute([$sale_details['checkout_session_uuid']]);
                    $ja_reenviado = $stmt_check_reenvio->fetchColumn() > 0;
                }
            } catch (Exception $e) {
                // Se a coluna não existir, considera como não reenviado
                $ja_reenviado = false;
            }
        }
        ?>
        
        <?php if($sale_details && $sale_details['status_pagamento'] === 'approved' && !$ja_reenviado && $payment_id): ?>
            <div class="mt-4 text-center">
                <p class="text-sm text-gray-600 mb-2">Não recebeu o e-mail?</p>
                <button id="btn-resend-email" class="text-orange-600 font-semibold hover:underline text-sm">
                    Reenviar acesso
                </button>
                <p id="resend-message" class="text-sm mt-2 hidden"></p>
            </div>
        <?php endif; ?>
        
        <?php if($sale_details && $sale_details['produto_nome']): ?>
            <div class="mt-8 p-4 bg-gray-50 border border-gray-200 rounded-lg">
                <p class="text-sm text-gray-500">Produto adquirido:</p>
                <p class="font-semibold text-gray-800"><?php echo htmlspecialchars($sale_details['produto_nome']); ?></p>
            </div>
        <?php endif; ?>

        <div class="mt-8">
            <a href="/" class="text-orange-600 font-semibold hover:underline">
                Voltar para a página inicial
            </a>
        </div>
        <?php if($payment_id): ?>
            <p class="text-xs text-gray-400 mt-10">ID da transação: <?php echo htmlspecialchars($payment_id); ?></p>
        <?php endif; ?>
    </div>
    <script>
        lucide.createIcons();
        
        // Reenviar e-mail de acesso
        <?php if($sale_details && $sale_details['status_pagamento'] === 'approved' && !$ja_reenviado && $payment_id): ?>
        document.getElementById('btn-resend-email')?.addEventListener('click', function() {
            const btn = this;
            const messageEl = document.getElementById('resend-message');
            const originalText = btn.textContent;
            
            // Desabilita o botão e mostra loading
            btn.disabled = true;
            btn.textContent = 'Enviando...';
            btn.classList.add('opacity-50', 'cursor-not-allowed');
            messageEl.classList.add('hidden');
            
            // Faz a requisição
            fetch('/api/resend_access.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    payment_id: '<?php echo htmlspecialchars($payment_id); ?>'
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    messageEl.textContent = '✓ E-mail reenviado com sucesso! Verifique sua caixa de entrada.';
                    messageEl.classList.remove('hidden');
                    messageEl.classList.add('text-green-600');
                    messageEl.classList.remove('text-red-600');
                    
                    // Esconde o botão após sucesso
                    btn.style.display = 'none';
                    document.querySelector('.mt-4.text-center p.text-sm.text-gray-600.mb-2').style.display = 'none';
                } else {
                    messageEl.textContent = data.error || 'Erro ao reenviar e-mail. Tente novamente mais tarde.';
                    messageEl.classList.remove('hidden');
                    messageEl.classList.add('text-red-600');
                    messageEl.classList.remove('text-green-600');
                    
                    // Reabilita o botão em caso de erro
                    btn.disabled = false;
                    btn.textContent = originalText;
                    btn.classList.remove('opacity-50', 'cursor-not-allowed');
                }
            })
            .catch(error => {
                messageEl.textContent = 'Erro ao reenviar e-mail. Tente novamente mais tarde.';
                messageEl.classList.remove('hidden');
                messageEl.classList.add('text-red-600');
                messageEl.classList.remove('text-green-600');
                
                // Reabilita o botão em caso de erro
                btn.disabled = false;
                btn.textContent = originalText;
                btn.classList.remove('opacity-50', 'cursor-not-allowed');
            });
        });
        <?php endif; ?>
    </script>
    <?php do_action('public_page_footer'); ?>
    <?php do_action('obrigado_page_after'); ?>
</body>
</html>