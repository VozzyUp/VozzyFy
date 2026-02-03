<?php
// Inicia o buffer de saída IMEDIATAMENTE.
ob_start();

// Verifica se o arquivo config existe
if (!file_exists('config.php')) {
    ob_clean();
    http_response_code(500);
    die(json_encode(['error' => 'Config não encontrado']));
}

require_once __DIR__ . '/../config/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Importa Helper da UTMfy
if (file_exists(__DIR__ . '/helpers/utmfy_helper.php')) require_once __DIR__ . '/helpers/utmfy_helper.php';

// Importa Helper de criação de senha
if (file_exists(__DIR__ . '/../helpers/password_setup_helper.php')) require_once __DIR__ . '/../helpers/password_setup_helper.php';

// Importa Helper de Webhooks
if (file_exists(__DIR__ . '/../helpers/webhook_helper.php')) require_once __DIR__ . '/../helpers/webhook_helper.php';

// PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

ini_set('display_errors', 0); 
ini_set('log_errors', 1); 
ini_set('error_log', __DIR__ . '/notification_api_errors.log'); 

function log_webhook($message) {
    file_put_contents(__DIR__ . '/webhook_log.txt', date('Y-m-d H:i:s') . " - " . $message . "\n", FILE_APPEND);
}

$phpmailer_path = __DIR__ . '/PHPMailer/src/';
if (file_exists($phpmailer_path . 'Exception.php')) { require_once $phpmailer_path . 'Exception.php'; require_once $phpmailer_path . 'PHPMailer.php'; require_once $phpmailer_path . 'SMTP.php'; }

// --- FUNÇÕES AUXILIARES ---

function sendFacebookConversionEvent($pixel_id, $api_token, $event_name, $sale_details, $event_source_url) {
    if (empty($pixel_id) || empty($api_token)) return;
    $url = "https://graph.facebook.com/v19.0/" . $pixel_id . "/events?access_token=" . $api_token;
    
    $user_data = [
        'em' => [hash('sha256', strtolower($sale_details['comprador_email']))],
        'ph' => [hash('sha256', preg_replace('/[^0-9]/', '', $sale_details['comprador_telefone']))],
    ];
    $name_parts = explode(' ', $sale_details['comprador_nome'], 2);
    $user_data['fn'] = [hash('sha256', strtolower($name_parts[0]))];
    if (isset($name_parts[1])) $user_data['ln'] = [hash('sha256', strtolower($name_parts[1]))];

    $payload = [
        'data' => [[
            'event_name' => $event_name,
            'event_time' => time(),
            'event_source_url' => $event_source_url,
            'user_data' => $user_data,
            'custom_data' => ['currency' => 'BRL', 'value' => (float)$sale_details['valor']],
            'action_source' => 'website',
        ]]
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_exec($ch);
    curl_close($ch);
}

function handle_tracking_events($status, $sale_details, $checkout_config) {
    $tracking_config = $checkout_config['tracking'] ?? [];
    if (empty($tracking_config)) return;
    
    $status = strtolower($status);

    $event_map = [
        'approved' => ['key' => 'purchase', 'fb_name' => 'Purchase'],
        'paid' => ['key' => 'purchase', 'fb_name' => 'Purchase'],
        'pix_created' => ['key' => 'pending', 'fb_name' => 'PaymentPending'], 
        'pending' => ['key' => 'pending', 'fb_name' => 'PaymentPending'],
        'rejected' => ['key' => 'rejected', 'fb_name' => 'PaymentRejected'],
        'refunded' => ['key' => 'refund', 'fb_name' => 'Refund'],
        'charged_back' => ['key' => 'chargeback', 'fb_name' => 'Chargeback']
    ];
    
    if (!isset($event_map[$status])) return;
    $event_info = $event_map[$status];
    
    if (!empty($tracking_config['events']['facebook'][$event_info['key']])) {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
        $checkout_url = $protocol . $_SERVER['HTTP_HOST'] . '/checkout?p=' . $sale_details['checkout_hash'];
        sendFacebookConversionEvent($tracking_config['facebookPixelId'] ?? '', $tracking_config['facebookApiToken'] ?? '', $event_info['fb_name'], $sale_details, $checkout_url);
    }
}

function trigger_webhooks($usuario_id, $event_data, $trigger_event, $produto_id = null) {
    global $pdo;
    $trigger_event = strtolower($trigger_event);
    $event_field = 'event_' . $trigger_event;
    
    if (in_array($trigger_event, ['approved', 'paid'])) $event_field = 'event_approved';
    if ($trigger_event == 'pix_created') $event_field = 'event_pending';

    $stmt = $pdo->prepare("SELECT url FROM webhooks WHERE usuario_id = :uid AND {$event_field} = 1 AND (produto_id IS NULL OR produto_id = :pid)");
    $stmt->execute([':uid' => $usuario_id, ':pid' => $produto_id]);
    $webhooks = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($webhooks)) return;
    $json_payload = json_encode(['event' => $trigger_event, 'timestamp' => date('Y-m-d H:i:s'), 'data' => $event_data]);

    foreach ($webhooks as $url) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'X-Starfy-Event: ' . $trigger_event]);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json_payload);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_exec($ch);
        curl_close($ch);
    }
}

function process_single_product_delivery($product_data, $customer_email) {
    global $pdo;
    $type = $product_data['tipo_entrega'];
    $content = $product_data['conteudo_entrega'];
    if ($type === 'link' && !empty($content)) return ['success' => true, 'product_name' => $product_data['produto_nome'], 'content_type' => 'link', 'content_value' => $content];
    if ($type === 'email_pdf' && !empty($content) && file_exists('uploads/' . $content)) return ['success' => true, 'product_name' => $product_data['produto_nome'], 'content_type' => 'pdf', 'content_value' => 'uploads/' . $content];
    if ($type === 'area_membros') {
        $pdo->prepare("INSERT IGNORE INTO alunos_acessos (aluno_email, produto_id) VALUES (?, ?)")->execute([$customer_email, $product_data['produto_id']]);
        return ['success' => true, 'product_name' => $product_data['produto_nome'], 'content_type' => 'area_membros', 'content_value' => null];
    }
    if ($type === 'somente_pagamento') {
        return ['success' => true, 'product_name' => $product_data['produto_nome'], 'content_type' => 'somente_pagamento', 'content_value' => null];
    }
    return ['success' => false, 'message' => 'Tipo desconhecido ou vazio'];
}

// --- FUNÇÃO DE ENVIO DE E-MAIL (ATUALIZADA PARA USAR O TEMPLATE DO BANCO) ---
function send_delivery_email_consolidated($to_email, $customer_name, $products, $pass, $login_url, $address_data = null, $setup_token = null) {
    global $pdo;
    $mail = new PHPMailer(true);
    try {
        // Busca configurações SMTP e TEMPLATE do banco
        $stmt = $pdo->query("SELECT chave, valor FROM configuracoes WHERE chave IN ('smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_encryption', 'smtp_from_email', 'smtp_from_name', 'email_template_delivery_subject', 'email_template_delivery_html')");
        $config = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        // Configuração de Remetente
        $default_from = 'noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $fromEmail = !empty($config['smtp_from_email']) ? $config['smtp_from_email'] : ($config['smtp_username'] ?? $default_from);
        if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) $fromEmail = $default_from;

        if (empty($config['smtp_host'])) {
            $mail->isMail();
        } else {
            $mail->isSMTP();
            $mail->Host = $config['smtp_host'];
            $mail->Port = $config['smtp_port'];
            $mail->SMTPAuth = true;
            $mail->Username = $config['smtp_username'];
            $mail->Password = $config['smtp_password'];
            $mail->SMTPSecure = $config['smtp_encryption'] == 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
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
        
        // Se o template estiver vazio, gera template padrão usando configurações da plataforma
        if (empty(trim($template))) {
            log_webhook("API Notification: Template vazio, gerando template padrão com configurações da plataforma");
            
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
                $template = '<p>Olá {CLIENT_NAME}, aqui estão seus produtos:</p><!-- LOOP_PRODUCTS_START --><p>{PRODUCT_NAME}</p><!-- LOOP_PRODUCTS_END -->';
            }
        }

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
        
        // Substitui variáveis, incluindo {CLIENT_EMAIL}, {LOGO_URL}, {DELIVERY_ADDRESS} e {SETUP_PASSWORD_URL}
        $body = str_replace(
            ['{CLIENT_NAME}', '{CLIENT_EMAIL}', '{MEMBER_AREA_PASSWORD}', '{MEMBER_AREA_LOGIN_URL}', '{LOGO_URL}', '{DELIVERY_ADDRESS}', '{SETUP_PASSWORD_URL}'], 
            [$customer_name, $to_email, $pass ?? 'N/A', $login_url ?? '#', $logo_url_final, $address_html, $setup_password_url], 
            $template
        );
        
        // Também substitui URLs de imagens quebradas ou genéricas pela logo configurada
        if (!empty($logo_url_final)) {
            $body = preg_replace('/src=["\']https?:\/\/[^"\']*imgbb\.com[^"\']*["\']/i', 'src="' . $logo_url_final . '"', $body);
            $body = preg_replace('/src=["\']https?:\/\/[^"\']*ibb\.co[^"\']*["\']/i', 'src="' . $logo_url_final . '"', $body);
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
                 
                 // Limpa tags condicionais (ex: <!-- IF_PRODUCT_TYPE_LINK -->)
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
        
        // Anexos
        foreach ($products as $p) {
            if ($p['content_type'] == 'pdf' && file_exists($p['content_value'])) {
                $mail->addAttachment($p['content_value'], basename($p['content_value']));
            }
        }

        $mail->send();
        return true;
    } catch (Exception $e) {
        log_webhook("Erro Email: " . $e->getMessage());
        return false;
    }
}

function create_notification($usuario_id, $tipo, $mensagem, $valor, $venda_id_fk = null, $metodo = null) {
    global $pdo;
    if (!$usuario_id) return;
    try {
        $link = $venda_id_fk ? "/index?pagina=vendas&id={$venda_id_fk}" : null;
        $pdo->prepare("INSERT INTO notificacoes (usuario_id, tipo, mensagem, valor, link_acao, venda_id_fk, metodo_pagamento) VALUES (?, ?, ?, ?, ?, ?, ?)")
            ->execute([$usuario_id, $tipo, $mensagem, $valor, $link, $venda_id_fk, $metodo]);
    } catch (Exception $e) {
        log_webhook("Erro notificacao: " . $e->getMessage());
    }
}

// -----------------------------------------------------------------------------
// EXECUÇÃO DA LÓGICA
// -----------------------------------------------------------------------------

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $is_json = stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false;
        $data = [];
        $action = $_GET['action'] ?? '';

        if ($is_json) {
            $input = file_get_contents('php://input');
            $data = json_decode($input, true) ?? [];
        } else {
            $data = $_POST;
            if (empty($data)) {
                $input = file_get_contents('php://input');
                parse_str($input, $data);
            }
        }

        // --- API Actions do Frontend ---
        if ($action === 'mark_all_as_read' || $action === 'mark_as_displayed_live') {
             if (!isset($_SESSION['id'])) { echo json_encode(['error' => 'Auth required']); exit; }
             if ($action === 'mark_all_as_read') {
                 $pdo->prepare("UPDATE notificacoes SET lida = 1 WHERE usuario_id = ?")->execute([$_SESSION['id']]);
             } else {
                 $notif_id = $data['notification_id'] ?? ($_POST['notification_id'] ?? null);
                 if ($notif_id) $pdo->prepare("UPDATE notificacoes SET displayed_live = 1 WHERE id = ? AND usuario_id = ?")->execute([$notif_id, $_SESSION['id']]);
             }
             echo json_encode(['success' => true]);
             exit;
        }

        // --- WEBHOOK (Gateway) ---
        header('Content-Type: application/json');
        ob_clean();
        echo json_encode(['status' => 'success']);
        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();

        $payment_id = $data['data']['id'] ?? ($data['id'] ?? null); 
        $resource = $data['resource'] ?? null;
        if (!$payment_id && $resource) $payment_id = preg_replace('/[^0-9]/', '', $resource);

        if ($payment_id) {
            $stmt = $pdo->prepare("SELECT v.*, p.usuario_id, u.mp_access_token FROM vendas v JOIN produtos p ON v.produto_id = p.id LEFT JOIN usuarios u ON p.usuario_id = u.id WHERE v.transacao_id = ? LIMIT 1");
            $stmt->execute([$payment_id]);
            $venda = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($venda) {
                $new_status = $data['status'] ?? null; 
                if (!$new_status && $venda['mp_access_token']) {
                     $ch = curl_init("https://api.mercadopago.com/v1/payments/" . $payment_id);
                     curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                     curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $venda['mp_access_token']]);
                     $mp_res = json_decode(curl_exec($ch), true);
                     curl_close($ch);
                     if (isset($mp_res['status'])) $new_status = $mp_res['status'];
                }
                
                if ($new_status) {
                    $new_status = strtolower($new_status); 
                    $db_status = ($new_status === 'paid' || $new_status === 'completed' || $new_status === 'approved') ? 'approved' : $new_status;
                    
                    // Verificar se status mudou de pending para approved
                    $status_anterior = $venda['status_pagamento'] ?? 'pending';
                    $status_mudou_para_approved = ($status_anterior !== 'approved' && $db_status === 'approved');

                    $pdo->prepare("UPDATE vendas SET status_pagamento = ? WHERE transacao_id = ?")->execute([$db_status, $payment_id]);
                    
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
                        $config = json_decode($main_sale['checkout_config'] ?? '{}', true);
                        
                        handle_tracking_events($db_status, $main_sale, $config);

                        $webhook_payload = [
                            'transacao_id' => $payment_id,
                            'status_pagamento' => $db_status,
                            'valor_total_compra' => array_sum(array_column($all_sales, 'valor')),
                            'comprador' => ['email' => $main_sale['comprador_email'], 'nome' => $main_sale['comprador_nome']],
                            'metodo_pagamento' => $main_sale['metodo_pagamento'],
                            'produtos_comprados' => $all_sales,
                            'utm_parameters' => [
                                'utm_source' => $main_sale['utm_source'], 'utm_campaign' => $main_sale['utm_campaign'],
                                'utm_medium' => $main_sale['utm_medium'], 'src' => $main_sale['src'], 'sck' => $main_sale['sck']
                            ]
                        ];
                        
                        // Disparar webhook com evento correto baseado no status
                        // Se status é 'approved', 'paid' ou 'completed', dispara evento 'approved'
                        $webhook_event = ($db_status === 'approved' || $db_status === 'paid' || $db_status === 'completed') ? 'approved' : $db_status;
                        
                        // IMPORTANTE: Sempre disparar webhook quando status for 'approved'
                        // Isso garante que o webhook seja enviado quando o PIX é pago
                        if ($webhook_event === 'approved') {
                            trigger_webhooks($main_sale['usuario_id'], $webhook_payload, $webhook_event, $main_sale['produto_id']);
                        }

                        if (function_exists('trigger_utmfy_integrations')) {
                            $webhook_payload['data_venda'] = $main_sale['data_venda'];
                            $webhook_payload['comprador']['cpf'] = $main_sale['comprador_cpf'];
                            $webhook_payload['comprador']['telefone'] = $main_sale['comprador_telefone'];
                            trigger_utmfy_integrations($main_sale['usuario_id'], $webhook_payload, $db_status, $main_sale['produto_id']);
                        }

                        $msg = "Venda atualizada: " . ucfirst($db_status);
                        if ($db_status == 'approved') $msg = "Venda Aprovada! R$ " . number_format($webhook_payload['valor_total_compra'], 2, ',', '.');
                        if ($db_status == 'pix_created' || ($db_status == 'pending' && stripos($main_sale['metodo_pagamento'], 'pix') !== false)) $msg = "Pix Gerado. Aguardando.";
                        
                        create_notification($main_sale['usuario_id'], ($db_status == 'approved' ? 'Compra Aprovada' : 'Atualização'), $msg, $webhook_payload['valor_total_compra'], $main_sale['id'], $main_sale['metodo_pagamento']);

                        if ($db_status === 'approved' && $main_sale['email_entrega_enviado'] == 0) {
                            // Processa produtos e gerencia criação de senha/token
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
                                        
                                        if ($existing_user) {
                                            // Cliente JÁ TEM conta
                                            // NÃO gerar senha, apenas garantir acesso (já feito por process_single_product_delivery)
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
                                            } catch (PDOException $e) {
                                                $new_user_id = null;
                                            }
                                            
                                            // Gerar token de criação de senha apenas se o usuário foi criado
                                            if ($new_user_id && function_exists('generate_setup_token')) {
                                                $setup_token = generate_setup_token($new_user_id);
                                            } else {
                                                $setup_token = null;
                                            }
                                            
                                            $pass = null; // Não passa senha no email
                                        }
                                    }
                                    $processed_prods[] = $res;
                                }
                            }
                            
                            if (!empty($processed_prods)) {
                                // Busca Link de Login
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

                                // Envia E-mail (Agora passando todas as variáveis corretas, incluindo setup_token)
                                send_delivery_email_consolidated($main_sale['comprador_email'], $main_sale['comprador_nome'], $processed_prods, $pass, $login_url, null, $setup_token);
                                
                                $pdo->prepare("UPDATE vendas SET email_entrega_enviado = 1 WHERE checkout_session_uuid = ?")->execute([$main_sale['checkout_session_uuid']]);
                            }
                        }
                    }
                }
            }
        }
        exit;
    }
    
    // ... GET Actions ...
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        header('Content-Type: application/json');
        ob_clean();
        if (!isset($_SESSION['id'])) { echo json_encode(['error' => 'Auth required']); exit; }
        $uid = $_SESSION['id'];
        $action = $_GET['action'] ?? '';

        if ($action === 'get_unread_count') {
            $c = $pdo->prepare("SELECT COUNT(*) FROM notificacoes WHERE usuario_id = ? AND lida = 0");
            $c->execute([$uid]);
            echo json_encode(['success' => true, 'count' => $c->fetchColumn()]);
            exit;
        }
        if ($action === 'get_recent_notifications') {
            $s = $pdo->prepare("SELECT id, tipo, mensagem, valor, DATE_FORMAT(data_notificacao, '%Y-%m-%dT%H:%i:%s') as data_notificacao, lida, link_acao FROM notificacoes WHERE usuario_id = ? ORDER BY data_notificacao DESC LIMIT 10");
            $s->execute([$uid]);
            echo json_encode(['success' => true, 'notifications' => $s->fetchAll(PDO::FETCH_ASSOC)]);
            exit;
        }
        if ($action === 'get_live_notifications') {
            $s = $pdo->prepare("SELECT n.id, n.tipo, n.mensagem, n.valor, n.metodo_pagamento, p.nome as produto_nome, p.foto as produto_foto FROM notificacoes n LEFT JOIN vendas v ON n.venda_id_fk = v.id LEFT JOIN produtos p ON v.produto_id = p.id WHERE n.usuario_id = ? AND n.displayed_live = 0 ORDER BY n.data_notificacao ASC LIMIT 5");
            $s->execute([$uid]);
            echo json_encode(['success' => true, 'live_notifications' => $s->fetchAll(PDO::FETCH_ASSOC)]);
            exit;
        }
    }

} catch (Throwable $e) {
    http_response_code(500);
    error_log("Erro Fatal Notification: " . $e->getMessage());
    ob_clean();
    echo json_encode(['error' => 'Erro interno']);
}
?>