<?php
/**
 * Script de Processamento de Cobrança Recorrente
 * Processa assinaturas vencidas e envia emails de renovação
 * 
 * Executar via cron job diariamente (00:00)
 * Veja instruções no modal de configuração do admin
 */

// Configurações
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../process_recurring_billing_errors.log');

// Inclui configurações
require_once __DIR__ . '/../config/config.php';

// Inclui PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$phpmailer_path = __DIR__ . '/../PHPMailer/src/';
if (file_exists($phpmailer_path . 'Exception.php')) {
    require_once $phpmailer_path . 'Exception.php';
    require_once $phpmailer_path . 'PHPMailer.php';
    require_once $phpmailer_path . 'SMTP.php';
}

// Função de log
function log_recurring($message) {
    $log_file = __DIR__ . '/../process_recurring_billing_log.txt';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($log_file, "[$timestamp] $message\n", FILE_APPEND);
    error_log("RECURRING_BILLING: $message");
}

log_recurring("=== INÍCIO DO PROCESSAMENTO DE COBRANÇA RECORRENTE ===");

try {
    // Buscar assinaturas ativas com próxima cobrança <= hoje (processa até 100 por execução)
    $query = "
        SELECT 
            a.id,
            a.produto_id,
            a.comprador_email,
            a.comprador_nome,
            a.proxima_cobranca,
            a.ultima_cobranca,
            p.nome as produto_nome,
            p.preco as produto_preco,
            p.checkout_hash,
            p.tipo_entrega
        FROM assinaturas a
        JOIN produtos p ON a.produto_id = p.id
        WHERE a.status = 'ativa'
          AND a.proxima_cobranca <= CURDATE()
        ORDER BY a.proxima_cobranca ASC
        LIMIT 100
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $assinaturas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $total_encontradas = count($assinaturas);
    log_recurring("Assinaturas vencidas encontradas: $total_encontradas");
    
    if ($total_encontradas === 0) {
        log_recurring("Nenhuma assinatura vencida para processar. Finalizando.");
        exit(0);
    }
    
    // Buscar configurações SMTP
    $stmt_config = $pdo->query("SELECT chave, valor FROM configuracoes WHERE chave IN ('smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_encryption', 'smtp_from_email', 'smtp_from_name')");
    $smtp_config = $stmt_config->fetchAll(PDO::FETCH_KEY_PAIR);
    
    $enviados = 0;
    $erros = 0;
    
    // Processa cada assinatura
    foreach ($assinaturas as $assinatura) {
        $assinatura_id = $assinatura['id'];
        $customer_email = $assinatura['comprador_email'];
        $customer_name = $assinatura['comprador_nome'];
        $produto_nome = $assinatura['produto_nome'];
        $produto_preco = floatval($assinatura['produto_preco']);
        $checkout_hash = $assinatura['checkout_hash'];
        
        // Valida dados essenciais
        if (empty($customer_email) || !filter_var($customer_email, FILTER_VALIDATE_EMAIL)) {
            log_recurring("Assinatura ID $assinatura_id: Email inválido ($customer_email). Pulando.");
            $erros++;
            continue;
        }
        
        if (empty($checkout_hash)) {
            log_recurring("Assinatura ID $assinatura_id: checkout_hash não encontrado. Pulando.");
            $erros++;
            continue;
        }
        
        // Constrói URL do checkout
        $protocol = 'https';
        $host = 'localhost';
        $base_path = '';
        
        if (isset($_SERVER['HTTP_HOST']) && !empty($_SERVER['HTTP_HOST'])) {
            $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'];
            $base_path = rtrim(dirname(dirname($_SERVER['PHP_SELF'])), '/\\');
        } else {
            if (function_exists('getSystemSetting')) {
                $site_url = getSystemSetting('site_url', '');
                if (!empty($site_url)) {
                    $parsed = parse_url($site_url);
                    $protocol = $parsed['scheme'] ?? 'https';
                    $host = $parsed['host'] ?? 'localhost';
                    $base_path = isset($parsed['path']) ? rtrim($parsed['path'], '/') : '';
                }
            }
        }
        
        $checkout_url = $protocol . '://' . $host . $base_path . '/checkout?p=' . urlencode($checkout_hash) . '&renovacao=' . $assinatura_id;
        
        log_recurring("Processando assinatura ID $assinatura_id - Cliente: $customer_email - Produto: $produto_nome");
        
        // Envia email de renovação
        $email_enviado = false;
        try {
            $mail = new PHPMailer(true);
            
            // Configuração SMTP
            if (empty($smtp_config['smtp_host'])) {
                $mail->isMail();
            } else {
                $mail->isSMTP();
                $mail->Host = $smtp_config['smtp_host'];
                $mail->Port = $smtp_config['smtp_port'] ?? 587;
                $mail->SMTPAuth = true;
                $mail->Username = $smtp_config['smtp_username'];
                $mail->Password = $smtp_config['smtp_password'];
                $mail->SMTPSecure = ($smtp_config['smtp_encryption'] ?? 'tls') == 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
            }
            
            $default_from = 'noreply@' . ($host ?? 'localhost');
            $fromEmail = !empty($smtp_config['smtp_from_email']) ? $smtp_config['smtp_from_email'] : ($smtp_config['smtp_username'] ?? $default_from);
            if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) $fromEmail = $default_from;
            
            $mail->setFrom($fromEmail, $smtp_config['smtp_from_name'] ?? 'Starfy');
            $mail->addAddress($customer_email, $customer_name);
            $mail->CharSet = 'UTF-8';
            $mail->isHTML(true);
            $mail->Subject = 'Renovação da sua assinatura - ' . $produto_nome;
            
            // Template HTML do email
            $email_body = '
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset="UTF-8">
                <meta name="viewport" content="width=device-width, initial-scale=1.0">
            </head>
            <body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
                <div style="background-color: #f4f4f4; padding: 20px; border-radius: 8px;">
                    <h2 style="color: #32e768;">É hora de renovar sua assinatura!</h2>
                    <p>Olá, ' . htmlspecialchars($customer_name) . '!</p>
                    <p>O período da sua assinatura de <strong>' . htmlspecialchars($produto_nome) . '</strong> está próximo de expirar.</p>
                    <p><strong>Valor da renovação: R$ ' . number_format($produto_preco, 2, ',', '.') . '/mês</strong></p>
                    <p style="margin: 30px 0;">
                        <a href="' . htmlspecialchars($checkout_url) . '" style="background-color: #32e768; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; display: inline-block; font-weight: bold;">
                            Renovar Assinatura
                        </a>
                    </p>
                    <p style="color: #666; font-size: 14px;">Este link é válido para renovar sua assinatura e continuar tendo acesso ao produto.</p>
                    <p style="color: #666; font-size: 14px;">Se você não deseja renovar, basta ignorar este e-mail.</p>
                </div>
            </body>
            </html>
            ';
            
            $mail->Body = $email_body;
            $mail->AltBody = "Olá, $customer_name!\n\nO período da sua assinatura de $produto_nome está próximo de expirar.\n\nValor da renovação: R$ " . number_format($produto_preco, 2, ',', '.') . "/mês\n\nRenove sua assinatura acessando: $checkout_url";
            
            $mail->send();
            $email_enviado = true;
            
        } catch (Exception $e) {
            log_recurring("Assinatura ID $assinatura_id: Erro ao enviar email - " . $e->getMessage());
            $email_enviado = false;
        }
        
        if ($email_enviado) {
            // Atualizar próxima cobrança (+30 dias) e última cobrança
            try {
                $hoje = new DateTime();
                $hoje->modify('+30 days');
                $nova_proxima_cobranca = $hoje->format('Y-m-d');
                
                $stmt_update = $pdo->prepare("
                    UPDATE assinaturas 
                    SET proxima_cobranca = ?, ultima_cobranca = CURDATE()
                    WHERE id = ?
                ");
                $stmt_update->execute([$nova_proxima_cobranca, $assinatura_id]);
                
                log_recurring("Assinatura ID $assinatura_id: Email enviado com sucesso. Próxima cobrança atualizada para $nova_proxima_cobranca.");
                $enviados++;
            } catch (PDOException $e) {
                log_recurring("Assinatura ID $assinatura_id: Erro ao atualizar próxima cobrança: " . $e->getMessage());
                $erros++;
            }
        } else {
            log_recurring("Assinatura ID $assinatura_id: Falha ao enviar email.");
            $erros++;
        }
        
        // Verificar se assinatura está expirada (mais de 7 dias após vencimento) e desativar acesso
        $proxima_cobranca = new DateTime($assinatura['proxima_cobranca']);
        $hoje_obj = new DateTime();
        $dias_expirada = $hoje_obj->diff($proxima_cobranca)->days;
        
        if ($dias_expirada > 7 && $assinatura['tipo_entrega'] === 'area_membros') {
            // Marcar assinatura como expirada
            try {
                $stmt_exp = $pdo->prepare("UPDATE assinaturas SET status = 'expirada' WHERE id = ?");
                $stmt_exp->execute([$assinatura_id]);
                
                log_recurring("Assinatura ID $assinatura_id: Marcada como expirada (mais de 7 dias sem renovação).");
            } catch (PDOException $e) {
                log_recurring("Assinatura ID $assinatura_id: Erro ao marcar como expirada: " . $e->getMessage());
            }
        }
        
        // Pequeno delay para não sobrecarregar o servidor SMTP
        usleep(500000); // 0.5 segundos
    }
    
    log_recurring("=== RESUMO DO PROCESSAMENTO ===");
    log_recurring("Total encontradas: $total_encontradas");
    log_recurring("Emails enviados: $enviados");
    log_recurring("Erros: $erros");
    log_recurring("=== FIM DO PROCESSAMENTO ===");
    
} catch (PDOException $e) {
    log_recurring("ERRO CRÍTICO no banco de dados: " . $e->getMessage());
    exit(1);
} catch (Exception $e) {
    log_recurring("ERRO CRÍTICO: " . $e->getMessage());
    exit(1);
}

exit(0);

