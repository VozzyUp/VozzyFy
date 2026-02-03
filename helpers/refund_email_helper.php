<?php
/**
 * Helper de Emails para Reembolsos
 * Funções para envio de emails relacionados a reembolsos
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

if (!function_exists('send_refund_request_notification')) {
    /**
     * Envia email de notificação ao infoprodutor sobre nova solicitação de reembolso
     * @param string $infoprodutor_email Email do infoprodutor
     * @param array $refund_data Dados do reembolso
     * @return bool True se enviado com sucesso, False caso contrário
     */
    function send_refund_request_notification($infoprodutor_email, $refund_data) {
        global $pdo;
        
        if (empty($infoprodutor_email) || !filter_var($infoprodutor_email, FILTER_VALIDATE_EMAIL)) {
            error_log("REFUND EMAIL: Email do infoprodutor inválido: " . $infoprodutor_email);
            return false;
        }
        
        $phpmailer_path = __DIR__ . '/../PHPMailer/src/';
        if (!file_exists($phpmailer_path . 'PHPMailer.php')) {
            error_log("REFUND EMAIL: PHPMailer não encontrado");
            return false;
        }
        
        require_once $phpmailer_path . 'Exception.php';
        require_once $phpmailer_path . 'PHPMailer.php';
        require_once $phpmailer_path . 'SMTP.php';
        
        $mail = new PHPMailer(true);
        
        try {
            // Buscar configurações SMTP
            $stmt = $pdo->query("SELECT chave, valor FROM configuracoes WHERE chave IN ('smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_encryption', 'smtp_from_email', 'smtp_from_name')");
            $config = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            
            // Configurar remetente
            $default_from = 'noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
            $fromEmail = !empty($config['smtp_from_email']) ? $config['smtp_from_email'] : ($config['smtp_username'] ?? $default_from);
            if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
                $fromEmail = $default_from;
            }
            
            // Configurar SMTP ou mail() nativo
            if (empty($config['smtp_host'])) {
                $mail->isMail();
            } else {
                $mail->isSMTP();
                $mail->Host = $config['smtp_host'];
                $mail->Port = (int)($config['smtp_port'] ?? 587);
                $mail->SMTPAuth = true;
                $mail->Username = $config['smtp_username'];
                $mail->Password = $config['smtp_password'];
                $mail->SMTPSecure = ($config['smtp_encryption'] == 'ssl') ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
            }
            
            $mail->setFrom($fromEmail, $config['smtp_from_name'] ?? 'Starfy');
            $mail->addAddress($infoprodutor_email);
            
            // Buscar logo configurada
            $logo_url = '';
            if (function_exists('getSystemSetting')) {
                $logo_url_raw = getSystemSetting('logo_url', '');
                if (!empty($logo_url_raw)) {
                    if (strpos($logo_url_raw, 'http') === 0) {
                        $logo_url = $logo_url_raw;
                    } else {
                        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
                        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                        $logo_url = $protocol . '://' . $host . '/' . ltrim($logo_url_raw, '/');
                    }
                }
            }
            
            // Assunto
            $mail->Subject = 'Nova Solicitação de Reembolso - ' . htmlspecialchars($refund_data['produto_nome'] ?? 'Produto');
            $mail->isHTML(true);
            $mail->CharSet = 'UTF-8';
            
            // Calcular dias desde a compra
            $data_venda = new DateTime($refund_data['data_venda']);
            $data_atual = new DateTime();
            $dias_desde_compra = $data_atual->diff($data_venda)->days;
            $dentro_prazo = $dias_desde_compra <= 7;
            
            // Template HTML do email
            $valor_formatado = 'R$ ' . number_format((float)$refund_data['valor'], 2, ',', '.');
            $data_solicitacao_formatada = date('d/m/Y H:i', strtotime($refund_data['data_solicitacao']));
            $data_venda_formatada = date('d/m/Y H:i', strtotime($refund_data['data_venda']));
            
            $body = '
            <!DOCTYPE html>
            <html lang="pt-BR">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
            </head>
            <body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
                ' . (!empty($logo_url) ? '<div style="text-align: center; margin-bottom: 30px;"><img src="' . htmlspecialchars($logo_url) . '" alt="Logo" style="max-width: 200px; height: auto;"></div>' : '') . '
                
                <h2 style="color: #7427F1; border-bottom: 2px solid #7427F1; padding-bottom: 10px;">Nova Solicitação de Reembolso</h2>
                
                <p>Olá,</p>
                
                <p>Você recebeu uma nova solicitação de reembolso:</p>
                
                <div style="background-color: #f5f5f5; padding: 20px; border-radius: 8px; margin: 20px 0;">
                    <p style="margin: 5px 0;"><strong>Cliente:</strong> ' . htmlspecialchars($refund_data['comprador_nome']) . '</p>
                    <p style="margin: 5px 0;"><strong>Email:</strong> ' . htmlspecialchars($refund_data['comprador_email']) . '</p>
                    <p style="margin: 5px 0;"><strong>Produto:</strong> ' . htmlspecialchars($refund_data['produto_nome'] ?? 'N/A') . '</p>
                    <p style="margin: 5px 0;"><strong>Valor:</strong> ' . $valor_formatado . '</p>
                    <p style="margin: 5px 0;"><strong>Data da Compra:</strong> ' . $data_venda_formatada . '</p>
                    <p style="margin: 5px 0;"><strong>Data da Solicitação:</strong> ' . $data_solicitacao_formatada . '</p>
                    <p style="margin: 5px 0;"><strong>Dias desde a compra:</strong> ' . $dias_desde_compra . ' dias</p>
                    <p style="margin: 5px 0; color: ' . ($dentro_prazo ? '#28a745' : '#dc3545') . ';"><strong>Status do Prazo:</strong> ' . ($dentro_prazo ? '✓ Dentro do prazo de 7 dias' : '⚠ Fora do prazo de 7 dias') . '</p>
                </div>
                
                ' . (!empty($refund_data['motivo']) ? '<div style="background-color: #fff3cd; padding: 15px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #ffc107;"><p style="margin: 0;"><strong>Motivo informado pelo cliente:</strong></p><p style="margin: 10px 0 0 0;">' . nl2br(htmlspecialchars($refund_data['motivo'])) . '</p></div>' : '') . '
                
                <div style="background-color: #d1ecf1; padding: 15px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #0c5460;">
                    <p style="margin: 0;"><strong>⚠️ Importante:</strong></p>
                    <p style="margin: 10px 0 0 0;">Por lei, o cliente tem direito a reembolso dentro de 7 dias corridos a partir da data da compra (CDC - Código de Defesa do Consumidor, Art. 49).</p>
                </div>
                
                <div style="text-align: center; margin: 30px 0;">
                    <a href="' . (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/index?pagina=reembolsos" style="background-color: #7427F1; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; display: inline-block; font-weight: bold;">Acessar Painel de Reembolsos</a>
                </div>
                
                <p style="color: #666; font-size: 12px; margin-top: 30px; border-top: 1px solid #ddd; padding-top: 20px;">
                    Este é um email automático. Por favor, não responda diretamente a este email.
                </p>
            </body>
            </html>';
            
            $mail->Body = $body;
            
            $mail->send();
            error_log("REFUND EMAIL: Email de notificação enviado com sucesso para: " . $infoprodutor_email);
            return true;
            
        } catch (Exception $e) {
            error_log("REFUND EMAIL: Erro ao enviar email de notificação: " . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('send_refund_response_email')) {
    /**
     * Envia email ao cliente informando sobre aprovação/recusa do reembolso
     * @param string $cliente_email Email do cliente
     * @param array $refund_data Dados do reembolso
     * @param string $status Status: 'approved' ou 'rejected'
     * @return bool True se enviado com sucesso, False caso contrário
     */
    function send_refund_response_email($cliente_email, $refund_data, $status) {
        global $pdo;
        
        if (empty($cliente_email) || !filter_var($cliente_email, FILTER_VALIDATE_EMAIL)) {
            error_log("REFUND EMAIL: Email do cliente inválido: " . $cliente_email);
            return false;
        }
        
        if (!in_array($status, ['approved', 'rejected'])) {
            error_log("REFUND EMAIL: Status inválido: " . $status);
            return false;
        }
        
        $phpmailer_path = __DIR__ . '/../PHPMailer/src/';
        if (!file_exists($phpmailer_path . 'PHPMailer.php')) {
            error_log("REFUND EMAIL: PHPMailer não encontrado");
            return false;
        }
        
        require_once $phpmailer_path . 'Exception.php';
        require_once $phpmailer_path . 'PHPMailer.php';
        require_once $phpmailer_path . 'SMTP.php';
        
        $mail = new PHPMailer(true);
        
        try {
            // Buscar configurações SMTP
            $stmt = $pdo->query("SELECT chave, valor FROM configuracoes WHERE chave IN ('smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_encryption', 'smtp_from_email', 'smtp_from_name')");
            $config = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            
            // Configurar remetente
            $default_from = 'noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
            $fromEmail = !empty($config['smtp_from_email']) ? $config['smtp_from_email'] : ($config['smtp_username'] ?? $default_from);
            if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
                $fromEmail = $default_from;
            }
            
            // Configurar SMTP ou mail() nativo
            if (empty($config['smtp_host'])) {
                $mail->isMail();
            } else {
                $mail->isSMTP();
                $mail->Host = $config['smtp_host'];
                $mail->Port = (int)($config['smtp_port'] ?? 587);
                $mail->SMTPAuth = true;
                $mail->Username = $config['smtp_username'];
                $mail->Password = $config['smtp_password'];
                $mail->SMTPSecure = ($config['smtp_encryption'] == 'ssl') ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
            }
            
            $mail->setFrom($fromEmail, $config['smtp_from_name'] ?? 'Starfy');
            $mail->addAddress($cliente_email, $refund_data['comprador_nome'] ?? '');
            
            // Buscar logo configurada
            $logo_url = '';
            if (function_exists('getSystemSetting')) {
                $logo_url_raw = getSystemSetting('logo_url', '');
                if (!empty($logo_url_raw)) {
                    if (strpos($logo_url_raw, 'http') === 0) {
                        $logo_url = $logo_url_raw;
                    } else {
                        $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
                        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                        $logo_url = $protocol . '://' . $host . '/' . ltrim($logo_url_raw, '/');
                    }
                }
            }
            
            // Assunto
            $status_text = ($status === 'approved') ? 'Aprovado' : 'Recusado';
            $mail->Subject = 'Solicitação de Reembolso ' . $status_text . ' - ' . htmlspecialchars($refund_data['produto_nome'] ?? 'Produto');
            $mail->isHTML(true);
            $mail->CharSet = 'UTF-8';
            
            // Template HTML do email
            $valor_formatado = 'R$ ' . number_format((float)$refund_data['valor'], 2, ',', '.');
            $data_resposta_formatada = date('d/m/Y H:i', strtotime($refund_data['data_resposta'] ?? 'now'));
            
            $cor_status = ($status === 'approved') ? '#28a745' : '#dc3545';
            $icone_status = ($status === 'approved') ? '✓' : '✗';
            $titulo_status = ($status === 'approved') ? 'Reembolso Aprovado' : 'Reembolso Recusado';
            $mensagem_status = ($status === 'approved') 
                ? 'Sua solicitação de reembolso foi <strong>aprovada</strong>. O valor será reembolsado conforme o método de pagamento utilizado na compra original.'
                : 'Sua solicitação de reembolso foi <strong>recusada</strong>.';
            
            $body = '
            <!DOCTYPE html>
            <html lang="pt-BR">
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
            </head>
            <body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
                ' . (!empty($logo_url) ? '<div style="text-align: center; margin-bottom: 30px;"><img src="' . htmlspecialchars($logo_url) . '" alt="Logo" style="max-width: 200px; height: auto;"></div>' : '') . '
                
                <h2 style="color: ' . $cor_status . '; border-bottom: 2px solid ' . $cor_status . '; padding-bottom: 10px;">
                    ' . $icone_status . ' ' . $titulo_status . '
                </h2>
                
                <p>Olá, <strong>' . htmlspecialchars($refund_data['comprador_nome'] ?? 'Cliente') . '</strong>,</p>
                
                <p>' . $mensagem_status . '</p>
                
                <div style="background-color: #f5f5f5; padding: 20px; border-radius: 8px; margin: 20px 0;">
                    <p style="margin: 5px 0;"><strong>Produto:</strong> ' . htmlspecialchars($refund_data['produto_nome'] ?? 'N/A') . '</p>
                    <p style="margin: 5px 0;"><strong>Valor:</strong> ' . $valor_formatado . '</p>
                    <p style="margin: 5px 0;"><strong>Data da Resposta:</strong> ' . $data_resposta_formatada . '</p>
                </div>
                
                ' . (!empty($refund_data['mensagem_infoprodutor']) ? '<div style="background-color: #e7f3ff; padding: 15px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #0066cc;"><p style="margin: 0;"><strong>Mensagem do vendedor:</strong></p><p style="margin: 10px 0 0 0;">' . nl2br(htmlspecialchars($refund_data['mensagem_infoprodutor'])) . '</p></div>' : '') . '
                
                ' . ($status === 'approved' ? '<div style="background-color: #d4edda; padding: 15px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #28a745;"><p style="margin: 0;"><strong>Próximos passos:</strong></p><p style="margin: 10px 0 0 0;">O reembolso será processado e o valor será creditado na mesma forma de pagamento utilizada na compra original. O prazo para o crédito pode variar de acordo com o método de pagamento.</p></div>' : '') . '
                
                <p style="color: #666; font-size: 12px; margin-top: 30px; border-top: 1px solid #ddd; padding-top: 20px;">
                    Este é um email automático. Se você tiver dúvidas, entre em contato com o suporte.
                </p>
            </body>
            </html>';
            
            $mail->Body = $body;
            
            $mail->send();
            error_log("REFUND EMAIL: Email de resposta enviado com sucesso para: " . $cliente_email);
            return true;
            
        } catch (Exception $e) {
            error_log("REFUND EMAIL: Erro ao enviar email de resposta: " . $e->getMessage());
            return false;
        }
    }
}

