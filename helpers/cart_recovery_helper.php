<?php
/**
 * Helper para Recuperação de Carrinho
 * Envia emails automáticos para clientes que geraram Pix mas não pagaram
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Gera template de email de recuperação de carrinho
 * @param string $logo_url URL completa da logo
 * @param string $cor_primaria Cor primária da plataforma (hex)
 * @param string $nome_plataforma Nome da plataforma
 * @return string HTML do template
 */
function generate_cart_recovery_email_template($logo_url, $cor_primaria, $nome_plataforma) {
    $cor_primaria_escaped = htmlspecialchars($cor_primaria);
    
    $template = '<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>Finalize sua Compra - ' . htmlspecialchars($nome_plataforma) . '</title>
    <style>
        @import url(\'https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap\');
        @media screen and (max-width: 600px) {
            .container { width: 100% !important; padding: 10px !important; }
            .content { padding: 25px 20px !important; }
            .header-img { width: 150px !important; }
            h1 { font-size: 24px !important; }
        }
    </style>
</head>
<body style="margin: 0; padding: 0; background-color: #f1f5f9; font-family: \'Inter\', -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Helvetica, Arial, sans-serif;">
    <!-- Preheader -->
    <div style="display: none; max-height: 0; overflow: hidden;">Você esqueceu de finalizar sua compra? Complete seu pagamento agora!</div>
    <table align="center" border="0" cellpadding="0" cellspacing="0" width="100%" style="border-collapse: collapse;">
        <tr>
            <td align="center" style="padding: 20px 0;">
                <table class="container" align="center" border="0" cellpadding="0" cellspacing="0" width="600" style="border-collapse: collapse; background-color: #ffffff; border-radius: 16px; box-shadow: 0 10px 25px rgba(0,0,0,0.05); overflow: hidden; border: 1px solid #e2e8f0;">
                    <!-- Cabeçalho com Logo -->
                    <tr>
                        <td align="center" bgcolor="#1e1e2f" style="padding: 30px 20px; background-color: #1e1e2f;">
                            <div>
                                <img class="header-img" src="{LOGO_URL}" alt="Logo ' . htmlspecialchars($nome_plataforma) . '" width="200" style="display: block; border: 0; max-width: 200px; height: auto;" />
                            </div>
                        </td>
                    </tr>
                    <!-- Corpo Principal -->
                    <tr>
                        <td class="content" style="padding: 40px 35px;">
                            <h1 style="font-size: 28px; font-weight: 700; color: #0f172a; margin: 0 0 15px 0;">Olá, {CLIENT_NAME}!</h1>
                            <p style="margin: 0 0 25px 0; font-size: 16px; line-height: 1.6; color: #475569;">
                                Notamos que você iniciou uma compra mas ainda não finalizou o pagamento. Não perca essa oportunidade!
                            </p>
                            
                            <!-- Alerta de Urgência -->
                            <div style="background-color: #fef3c7; border-left: 4px solid #f59e0b; padding: 15px; margin: 20px 0; border-radius: 4px;">
                                <p style="margin: 0; font-size: 14px; color: #92400e; font-weight: 600;">⏰ Atenção!</p>
                                <p style="margin: 10px 0 0 0; font-size: 13px; color: #92400e;">Seu Pix pode expirar em breve. Complete seu pagamento agora para garantir seu produto.</p>
                            </div>
                            
                            <!-- Informações do Produto -->
                            <div style="background-color: #f8fafc; border: 1px solid #e2e8f0; border-radius: 12px; padding: 25px; margin: 25px 0; box-shadow: 0 4px 10px rgba(0,0,0,0.03);">
                                <h2 style="font-size: 20px; font-weight: 600; color: #1e293b; margin: 0 0 10px 0;">{PRODUCT_NAME}</h2>
                                <p style="margin: 0; font-size: 24px; font-weight: 700; color: ' . $cor_primaria_escaped . ';">{PRODUCT_PRICE}</p>
                            </div>
                            
                            <!-- Botão de Ação -->
                            <table border="0" cellpadding="0" cellspacing="0" style="border-collapse: collapse; margin: 30px 0; width: 100%;">
                                <tr>
                                    <td align="center" style="background-color: ' . $cor_primaria_escaped . '; border-radius: 8px;">
                                        <a href="{CHECKOUT_URL}" target="_blank" style="color: #ffffff; text-decoration: none; font-size: 16px; font-weight: 600; padding: 16px 32px; border: 19px solid ' . $cor_primaria_escaped . '; display: inline-block; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
                                            ✅ Finalizar Pagamento Agora
                                        </a>
                                    </td>
                                </tr>
                            </table>
                            
                            <p style="word-break: break-all; font-size: 12px; color: #64748b; margin: 15px 0 0 0; text-align: center;">
                                Se o botão não funcionar, copie e cole o link abaixo no seu navegador:<br>
                                <a href="{CHECKOUT_URL}" style="color: ' . $cor_primaria_escaped . '; text-decoration: underline;">{CHECKOUT_URL}</a>
                            </p>
                            
                            <p style="margin: 30px 0 0 0; font-size: 16px; line-height: 1.6; color: #475569;">
                                Caso tenha alguma dúvida ou precise de suporte, entre em contato conosco.
                            </p>
                        </td>
                    </tr>
                    <!-- Rodapé -->
                    <tr>
                        <td align="center" style="padding: 25px 30px; background-color: #f8fafc; border-top: 1px solid #e2e8f0;">
                            <p style="margin: 0; font-size: 13px; color: #64748b;">
                                Este é um e-mail automático, por favor, não responda.
                            </p>
                            <p style="margin: 10px 0 0 0; font-size: 13px; color: #94a3b8;">
                                ' . htmlspecialchars($nome_plataforma) . ' &copy; ' . date('Y') . '. Todos os direitos reservados.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>';
    
    return $template;
}

/**
 * Envia email de recuperação de carrinho
 * @param string $to_email Email do cliente
 * @param string $customer_name Nome do cliente
 * @param string $product_name Nome do produto
 * @param float $product_price Preço do produto
 * @param string $checkout_url URL completa do checkout
 * @return bool True se enviado com sucesso, False em caso de erro
 */
function send_cart_recovery_email($to_email, $customer_name, $product_name, $product_price, $checkout_url) {
    global $pdo;
    
    if (empty($to_email) || !filter_var($to_email, FILTER_VALIDATE_EMAIL)) {
        error_log("CART_RECOVERY: Email inválido: " . $to_email);
        return false;
    }
    
    $mail = new PHPMailer(true);
    
    try {
        // Busca configurações SMTP da tabela configuracoes
        $stmt = $pdo->query("SELECT chave, valor FROM configuracoes WHERE chave IN ('smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_encryption', 'smtp_from_email', 'smtp_from_name')");
        $config = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        // Busca logo configurada da tabela configuracoes_sistema
        $logo_url_raw = '';
        if (function_exists('getSystemSetting')) {
            $logo_url_raw = getSystemSetting('logo_checkout_url', '');
            if (empty($logo_url_raw)) {
                $logo_url_raw = getSystemSetting('logo_url', '');
            }
        } else {
            $stmt_logo = $pdo->query("SELECT valor FROM configuracoes_sistema WHERE chave IN ('logo_checkout_url', 'logo_url') ORDER BY FIELD(chave, 'logo_checkout_url', 'logo_url') LIMIT 1");
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
        
        // Busca configurações da plataforma
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
        
        // Configuração de Remetente
        $default_from = 'noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $fromEmail = !empty($config['smtp_from_email']) ? $config['smtp_from_email'] : ($config['smtp_username'] ?? $default_from);
        if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            $fromEmail = $default_from;
        }
        
        // Configura SMTP ou mail() nativo
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
        
        $mail->setFrom($fromEmail, $config['smtp_from_name'] ?? $nome_plataforma);
        $mail->addAddress($to_email, $customer_name);
        $mail->Subject = 'Finalize sua compra - ' . $nome_plataforma;
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        
        // Gera template
        $template = generate_cart_recovery_email_template($logo_url_final, $cor_primaria, $nome_plataforma);
        
        // Formata preço
        $product_price_formatted = 'R$ ' . number_format($product_price, 2, ',', '.');
        
        // Substitui variáveis
        $body = str_replace(
            ['{CLIENT_NAME}', '{PRODUCT_NAME}', '{PRODUCT_PRICE}', '{CHECKOUT_URL}', '{LOGO_URL}'],
            [htmlspecialchars($customer_name), htmlspecialchars($product_name), $product_price_formatted, htmlspecialchars($checkout_url), $logo_url_final],
            $template
        );
        
        $mail->Body = $body;
        
        // Envia email
        $mail->send();
        error_log("CART_RECOVERY: Email enviado com sucesso para: " . $to_email);
        return true;
        
    } catch (Exception $e) {
        error_log("CART_RECOVERY: Erro ao enviar email para " . $to_email . ": " . $e->getMessage());
        return false;
    }
}

