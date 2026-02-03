<?php
/**
 * Helper para Recuperação de Senha
 * Funções auxiliares para gerenciar tokens de recuperação e envio de emails
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
 * Gera token único de recuperação e salva no banco
 * @param int $usuario_id ID do usuário
 * @return string|false Token gerado ou false em caso de erro
 */
function generate_reset_token($usuario_id) {
    global $pdo;
    
    if (!isset($pdo) || !$usuario_id) {
        error_log("PASSWORD_RESET: PDO não disponível ou usuario_id inválido");
        return false;
    }
    
    try {
        // Gera token único de 64 caracteres
        $token = bin2hex(random_bytes(32));
        
        // Define expiração de 1 hora
        $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
        
        // Salva token no banco
        $stmt = $pdo->prepare("UPDATE usuarios SET password_reset_token = ?, password_reset_expires = ? WHERE id = ?");
        $stmt->execute([$token, $expires, $usuario_id]);
        
        error_log("PASSWORD_RESET: Token gerado para usuario_id: $usuario_id");
        return $token;
    } catch (PDOException $e) {
        error_log("PASSWORD_RESET: Erro ao gerar token: " . $e->getMessage());
        return false;
    }
}

/**
 * Envia email de recuperação de senha via SMTP
 * @param string $email Email do destinatário
 * @param string $token Token de recuperação
 * @param string $tipo_usuario Tipo do usuário ('admin', 'infoprodutor', 'usuario')
 * @return bool True se enviado com sucesso, False caso contrário
 */
function send_reset_email($email, $token, $tipo_usuario = 'infoprodutor') {
    global $pdo;
    
    if (!isset($pdo)) {
        error_log("PASSWORD_RESET: PDO não disponível");
        return false;
    }
    
    // Verifica se PHPMailer foi carregado
    if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        error_log("PASSWORD_RESET: PHPMailer não encontrado");
        return false;
    }
    
    try {
        // Busca configurações SMTP
        $stmt_smtp = $pdo->prepare("SELECT chave, valor FROM configuracoes WHERE chave IN ('smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_encryption', 'smtp_from_email', 'smtp_from_name')");
        $stmt_smtp->execute();
        $smtp_configs_raw = $stmt_smtp->fetchAll(PDO::FETCH_KEY_PAIR);
        
        $smtp_config = [
            'host' => $smtp_configs_raw['smtp_host'] ?? '',
            'port' => (int)($smtp_configs_raw['smtp_port'] ?? 587),
            'username' => $smtp_configs_raw['smtp_username'] ?? '',
            'password' => $smtp_configs_raw['smtp_password'] ?? '',
            'encryption' => $smtp_configs_raw['smtp_encryption'] ?? 'tls',
            'from_email' => $smtp_configs_raw['smtp_from_email'] ?? '',
            'from_name' => $smtp_configs_raw['smtp_from_name'] ?? 'Starfy'
        ];
        
        // Busca nome da plataforma e logo
        require_once __DIR__ . '/../config/config.php';
        $nome_plataforma = getSystemSetting('nome_plataforma', 'Starfy');
        $logo_url = getSystemSetting('logo_url', 'https://i.ibb.co/2YRWNQw7/175790954831-Photoroom.png');
        
        // Determina URL base primeiro (necessário para construir URL completa do logo)
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || 
                    (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') ||
                    (!empty($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)
                    ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $base_url = $protocol . '://' . $host;
        
        // Normaliza logo URL - constrói URL completa se não for URL externa
        if (!empty($logo_url)) {
            $logo_url = ltrim($logo_url, '/');
            // Se não começar com http/https, construir URL completa
            if (strpos($logo_url, 'http://') !== 0 && strpos($logo_url, 'https://') !== 0) {
                // Garantir que comece com / se for caminho relativo
                if (strpos($logo_url, '/') !== 0) {
                    $logo_url = '/' . $logo_url;
                }
                // Construir URL completa
                $logo_url = $base_url . $logo_url;
            }
        }
        
        // URL de reset
        $reset_url = $base_url . '/reset_password.php?token=' . urlencode($token);
        
        // Instancia PHPMailer
        $mail = new PHPMailer(true);
        
        // Configura SMTP
        if (empty($smtp_config['host']) || empty($smtp_config['username']) || empty($smtp_config['password'])) {
            error_log("PASSWORD_RESET: Credenciais SMTP não configuradas");
            return false;
        }
        
        $mail->isSMTP();
        $mail->Host = $smtp_config['host'];
        $mail->Port = $smtp_config['port'];
        $mail->SMTPAuth = true;
        $mail->Username = $smtp_config['username'];
        $mail->Password = $smtp_config['password'];
        
        $mail->SMTPOptions = array(
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            )
        );
        
        if ($smtp_config['encryption'] === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($smtp_config['encryption'] === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure = false;
            $mail->SMTPAutoTLS = false;
        }
        
        $mail->setFrom($smtp_config['username'], $smtp_config['from_name']);
        $mail->CharSet = 'UTF-8';
        $mail->addAddress($email);
        $mail->Subject = 'Recuperação de Senha - ' . $nome_plataforma;
        $mail->isHTML(true);
        
        // Template HTML do email
        $html_body = '
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recuperação de Senha</title>
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
    <table align="center" border="0" cellpadding="0" cellspacing="0" width="100%" style="border-collapse: collapse;">
        <tr>
            <td align="center" style="padding: 20px 0;">
                <table class="container" align="center" border="0" cellpadding="0" cellspacing="0" width="600" style="border-collapse: collapse; background-color: #ffffff; border-radius: 16px; box-shadow: 0 10px 25px rgba(0,0,0,0.05); overflow: hidden; border: 1px solid #e2e8f0;">
                    <tr>
                        <td align="center" bgcolor="#1e1e2f" style="padding: 30px 20px; background-color: #1e1e2f;">
                            <div>
                                <img class="header-img" src="' . htmlspecialchars($logo_url) . '" alt="Logo ' . htmlspecialchars($nome_plataforma) . '" width="200" style="display: block; border: 0;" />
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td class="content" style="padding: 40px 35px;">
                            <h1 style="font-size: 28px; font-weight: 700; color: #0f172a; margin: 0 0 15px 0;">Recuperação de Senha</h1>
                            <p style="margin: 0 0 25px 0; font-size: 16px; line-height: 1.6; color: #475569;">
                                Recebemos uma solicitação para redefinir a senha da sua conta. Clique no botão abaixo para criar uma nova senha:
                            </p>
                            <table border="0" cellpadding="0" cellspacing="0" style="border-collapse: collapse; margin: 30px 0;">
                                <tr>
                                    <td align="center" style="background-color: #f97316; border-radius: 8px;">
                                        <a href="' . htmlspecialchars($reset_url) . '" target="_blank" style="color: #ffffff; text-decoration: none; font-size: 15px; font-weight: 600; padding: 14px 28px; border: 19px solid #f97316; display: inline-block; border-radius: 8px;">Redefinir Minha Senha</a>
                                    </td>
                                </tr>
                            </table>
                            <p style="word-break: break-all; font-size: 12px; color: #64748b; margin: 20px 0 0 0;">
                                Se o botão não funcionar, copie e cole o link abaixo no seu navegador:<br>
                                <a href="' . htmlspecialchars($reset_url) . '" style="color: #f97316;">' . htmlspecialchars($reset_url) . '</a>
                            </p>
                            <div style="background-color: #fef3c7; border-left: 4px solid #f59e0b; padding: 15px; margin: 25px 0; border-radius: 4px;">
                                <p style="margin: 0; font-size: 14px; color: #92400e; font-weight: 600;">⚠️ Importante</p>
                                <ul style="margin: 10px 0 0 0; padding-left: 20px; color: #92400e; font-size: 13px;">
                                    <li>Este link expira em <strong>1 hora</strong></li>
                                    <li>Se você não solicitou esta recuperação, ignore este email</li>
                                    <li>Não compartilhe este link com ninguém</li>
                                </ul>
                            </div>
                            <p style="margin: 30px 0 0 0; font-size: 16px; line-height: 1.6; color: #475569;">
                                Caso tenha alguma dúvida ou precise de suporte, entre em contato conosco.
                            </p>
                        </td>
                    </tr>
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
        
        $mail->Body = $html_body;
        $mail->AltBody = "Recuperação de Senha\n\nRecebemos uma solicitação para redefinir a senha da sua conta. Acesse o link abaixo para criar uma nova senha:\n\n" . $reset_url . "\n\nEste link expira em 1 hora. Se você não solicitou esta recuperação, ignore este email.";
        
        $mail->send();
        error_log("PASSWORD_RESET: Email enviado com sucesso para: $email");
        return true;
        
    } catch (Exception $e) {
        error_log("PASSWORD_RESET: Erro ao enviar email: " . $e->getMessage());
        return false;
    }
}

/**
 * Valida token de recuperação e retorna dados do usuário
 * @param string $token Token de recuperação
 * @return array|false Array com dados do usuário ou false se inválido/expirado
 */
function validate_reset_token($token) {
    global $pdo;
    
    if (!isset($pdo) || empty($token)) {
        return false;
    }
    
    try {
        $stmt = $pdo->prepare("SELECT id, usuario, nome, tipo, password_reset_expires FROM usuarios WHERE password_reset_token = ? AND password_reset_expires > NOW()");
        $stmt->execute([$token]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            return $user;
        }
        
        return false;
    } catch (PDOException $e) {
        error_log("PASSWORD_RESET: Erro ao validar token: " . $e->getMessage());
        return false;
    }
}

/**
 * Redefine senha do usuário e invalida token
 * @param string $token Token de recuperação
 * @param string $new_password Nova senha (será hasheada)
 * @return bool True se sucesso, False caso contrário
 */
function reset_password($token, $new_password) {
    global $pdo;
    
    if (!isset($pdo) || empty($token) || empty($new_password)) {
        return false;
    }
    
    // Valida força da senha (mínimo 8 caracteres)
    if (strlen($new_password) < 8) {
        error_log("PASSWORD_RESET: Senha muito curta (mínimo 8 caracteres)");
        return false;
    }
    
    // Valida token
    $user = validate_reset_token($token);
    if (!$user) {
        error_log("PASSWORD_RESET: Token inválido ou expirado");
        return false;
    }
    
    try {
        // Hash da nova senha
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        
        // Atualiza senha e invalida token
        $stmt = $pdo->prepare("UPDATE usuarios SET senha = ?, password_reset_token = NULL, password_reset_expires = NULL WHERE id = ?");
        $stmt->execute([$hashed_password, $user['id']]);
        
        error_log("PASSWORD_RESET: Senha redefinida com sucesso para usuario_id: " . $user['id']);
        return true;
    } catch (PDOException $e) {
        error_log("PASSWORD_RESET: Erro ao redefinir senha: " . $e->getMessage());
        return false;
    }
}

