<?php
/**
 * Helper para Gerenciamento de Alunos
 * Funções auxiliares para envio de emails de acesso e gerenciamento de alunos
 */

// Carrega PHPMailer se ainda não foi carregado
$phpmailer_path = __DIR__ . '/../PHPMailer/src/';
if (file_exists($phpmailer_path . 'Exception.php')) {
    require_once $phpmailer_path . 'Exception.php';
    require_once $phpmailer_path . 'PHPMailer.php';
    require_once $phpmailer_path . 'SMTP.php';
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

/**
 * Envia email de acesso para um aluno recém-criado
 * Reutiliza a lógica de send_delivery_email_consolidated do notification.php
 * 
 * @param string $email Email do aluno
 * @param string $nome Nome do aluno
 * @param int $produto_id ID do produto
 * @param string|null $setup_token Token de criação de senha (se novo usuário)
 * @return bool True se sucesso, False caso contrário
 */
function send_student_access_email($email, $nome, $produto_id, $setup_token = null) {
    global $pdo;
    
    if (!isset($pdo) || empty($email) || empty($nome) || empty($produto_id)) {
        error_log("ALUNOS_HELPER: Parâmetros inválidos para envio de email");
        return false;
    }
    
    try {
        // Busca dados do produto
        $stmt_produto = $pdo->prepare("SELECT id, nome, tipo_entrega, conteudo_entrega FROM produtos WHERE id = ?");
        $stmt_produto->execute([$produto_id]);
        $produto = $stmt_produto->fetch(PDO::FETCH_ASSOC);
        
        if (!$produto || $produto['tipo_entrega'] !== 'area_membros') {
            error_log("ALUNOS_HELPER: Produto não encontrado ou não é área de membros");
            return false;
        }
        
        // Prepara estrutura de produto para email (formato esperado por send_delivery_email_consolidated)
        $products = [[
            'product_name' => $produto['nome'],
            'content_type' => 'area_membros',
            'content_value' => null,
            'produto_id' => $produto['id']
        ]];
        
        // Busca URL de login da área de membros
        $login_url_stmt = $pdo->query("SELECT valor FROM configuracoes WHERE chave = 'member_area_login_url'");
        $login_url_config = $login_url_stmt->fetchColumn();
        
        if (empty($login_url_config) || $login_url_config === '#') {
            $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $login_url = $protocol . '://' . $host . '/member_login';
        } else {
            $login_url = $login_url_config;
        }
        
        // Usa nossa implementação direta (mais confiável e independente)
        $result = send_student_email_direct($email, $nome, $products, $login_url, $setup_token);
        
        if ($result) {
            error_log("ALUNOS_HELPER: Email de acesso enviado com sucesso para: $email");
        } else {
            error_log("ALUNOS_HELPER: Falha ao enviar email de acesso para: $email");
        }
        
        return $result;
        
    } catch (Exception $e) {
        error_log("ALUNOS_HELPER: Erro ao enviar email: " . $e->getMessage());
        return false;
    }
}

/**
 * Envia email diretamente (reimplementação da lógica de send_delivery_email_consolidated)
 * Usado quando a função não está disponível
 */
function send_student_email_direct($to_email, $customer_name, $products, $login_url, $setup_token = null) {
    global $pdo;
    
    $mail = new PHPMailer(true);
    try {
        // Busca configurações SMTP e TEMPLATE do banco
        $stmt = $pdo->query("SELECT chave, valor FROM configuracoes WHERE chave IN ('smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_encryption', 'smtp_from_email', 'smtp_from_name', 'email_template_delivery_subject', 'email_template_delivery_html')");
        $config = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        // Busca logo configurada
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
                $host = $_SERVER['HTTP_HOST'];
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
        
        $email_subject = trim($config['email_template_delivery_subject'] ?? '');
        $mail->Subject = !empty($email_subject) ? $email_subject : 'Seu acesso chegou!';
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';

        // Usa o template do banco
        $template = $config['email_template_delivery_html'] ?? '';
        
        // Se o template estiver vazio, gera template padrão
        if (empty(trim($template))) {
            if (file_exists(__DIR__ . '/email_template_helper.php')) {
                require_once __DIR__ . '/email_template_helper.php';
            }
            
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
            
            if (function_exists('generate_default_delivery_email_template')) {
                $template = generate_default_delivery_email_template($logo_checkout_url, $cor_primaria, $nome_plataforma);
            } else {
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
        
        // Substitui variáveis globais
        $body = str_replace(
            ['{CLIENT_NAME}', '{CLIENT_EMAIL}', '{MEMBER_AREA_PASSWORD}', '{LOGO_URL}'], 
            [$customer_name, $to_email, 'N/A', $logo_url_final], 
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
                         if (strpos($item, '<!-- IF_NEW_USER_SETUP -->') !== false) {
                             $item = str_replace(["<!-- IF_NEW_USER_SETUP -->", "<!-- END_IF_NEW_USER_SETUP -->"], '', $item);
                             $item = preg_replace("/<!-- IF_EXISTING_USER -->.*?<!-- END_IF_EXISTING_USER -->/s", "", $item);
                         }
                     } else {
                         if (strpos($item, '<!-- IF_EXISTING_USER -->') !== false) {
                             $item = str_replace(["<!-- IF_EXISTING_USER -->", "<!-- END_IF_EXISTING_USER -->"], '', $item);
                             $item = preg_replace("/<!-- IF_NEW_USER_SETUP -->.*?<!-- END_IF_NEW_USER_SETUP -->/s", "", $item);
                         }
                     }
                     
                     $item = str_replace('{SETUP_PASSWORD_URL}', htmlspecialchars($setup_password_url ?? ''), $item);
                     $item = str_replace('{MEMBER_AREA_LOGIN_URL}', htmlspecialchars($login_url ?? '#'), $item);
                 } else {
                     if (strpos($item, '<!-- IF_NEW_USER_SETUP -->') !== false) {
                         $item = preg_replace("/<!-- IF_NEW_USER_SETUP -->.*?<!-- END_IF_NEW_USER_SETUP -->/s", "", $item);
                     }
                     if (strpos($item, '<!-- IF_EXISTING_USER -->') !== false) {
                         $item = preg_replace("/<!-- IF_EXISTING_USER -->.*?<!-- END_IF_EXISTING_USER -->/s", "", $item);
                     }
                 }
                 
                 $html_prods .= $item;
             }
             $body = str_replace($loop_start . $part . $loop_end, $html_prods, $body);
        }
        
        // Verifica se o corpo do email não está vazio
        if (empty(trim(strip_tags($body)))) {
            $body = '<p>Olá ' . htmlspecialchars($customer_name) . ',</p>';
            $body .= '<p>Seu produto está disponível!</p>';
            if ($setup_token && !empty($setup_password_url)) {
                $body .= '<p><a href="' . htmlspecialchars($setup_password_url) . '">Clique aqui para criar sua senha e acessar</a></p>';
            } elseif (!empty($login_url) && $login_url !== '#') {
                $body .= '<p><a href="' . htmlspecialchars($login_url) . '">Clique aqui para acessar sua área de membros</a></p>';
            }
            foreach ($products as $p) {
                $body .= '<p><strong>' . htmlspecialchars($p['product_name']) . '</strong></p>';
            }
        }

        $mail->Body = $body;
        
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("ALUNOS_HELPER: Erro ao enviar email: " . $e->getMessage());
        return false;
    }
}

