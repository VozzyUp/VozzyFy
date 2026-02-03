<?php
/**
 * Processador de Fila de Emails de Broadcast
 * Processa até 30 emails por execução
 * Deve ser chamado via cron job externo (ex: cron-job.org)
 */

// Inicia buffer de saída
ob_start();

// Desabilita exibição de erros
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../process_email_queue_log.txt');
error_reporting(E_ALL);

// Define header JSON
header('Content-Type: application/json');

// Carregar configurações
$config_paths = [
    __DIR__ . '/../config/config.php',
    __DIR__ . '/config.php'
];

$config_loaded = false;
foreach ($config_paths as $config_path) {
    if (file_exists($config_path)) {
        try {
            ob_start();
            require $config_path;
            ob_end_clean();
            $config_loaded = true;
            break;
        } catch (Exception $e) {
            ob_end_clean();
            error_log("Erro ao carregar config: " . $e->getMessage());
        }
    }
}

if (!$config_loaded) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['error' => 'Configuração não encontrada']);
    exit;
}

// Verificar se $pdo está disponível
if (!isset($pdo)) {
    ob_clean();
    http_response_code(500);
    echo json_encode(['error' => 'Banco de dados não conectado']);
    exit;
}

// PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

$phpmailer_path = __DIR__ . '/../PHPMailer/src/';
if (file_exists($phpmailer_path . 'Exception.php')) {
    require_once $phpmailer_path . 'Exception.php';
    require_once $phpmailer_path . 'PHPMailer.php';
    require_once $phpmailer_path . 'SMTP.php';
} else {
    ob_clean();
    http_response_code(500);
    echo json_encode(['error' => 'PHPMailer não encontrado']);
    exit;
}

function log_queue($message) {
    $log_file = __DIR__ . '/../process_email_queue_log.txt';
    @file_put_contents($log_file, date('Y-m-d H:i:s') . " - " . $message . "\n", FILE_APPEND);
}

log_queue("INÍCIO DO PROCESSAMENTO DA FILA");

try {
    // Buscar configurações SMTP
    $stmt = $pdo->query("SELECT chave, valor FROM configuracoes WHERE chave IN ('smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_encryption', 'smtp_from_email', 'smtp_from_name')");
    $smtp_config = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    if (empty($smtp_config['smtp_host'])) {
        log_queue("AVISO: SMTP não configurado. Usando mail() nativo.");
    }
    
    // Buscar até 30 emails pendentes
    $stmt = $pdo->prepare("
        SELECT id, recipient_email, recipient_name, subject, body, attempts
        FROM email_queue 
        WHERE status = 'pending' 
        ORDER BY created_at ASC 
        LIMIT 30
    ");
    $stmt->execute();
    $emails = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($emails)) {
        ob_clean();
        echo json_encode([
            'success' => true,
            'processed' => 0,
            'sent' => 0,
            'failed' => 0,
            'message' => 'Nenhum email pendente na fila'
        ]);
        exit;
    }
    
    log_queue("Encontrados " . count($emails) . " emails para processar");
    
    $sent = 0;
    $failed = 0;
    $errors = [];
    
    foreach ($emails as $email_data) {
        $email_id = $email_data['id'];
        $recipient_email = $email_data['recipient_email'];
        $recipient_name = $email_data['recipient_name'] ?? $recipient_email;
        $subject = $email_data['subject'];
        $body = $email_data['body'];
        
        // Atualizar status para processing
        $pdo->prepare("UPDATE email_queue SET status = 'processing' WHERE id = ?")->execute([$email_id]);
        
        try {
            $mail = new PHPMailer(true);
            
            // Configurar SMTP ou mail() nativo
            if (empty($smtp_config['smtp_host'])) {
                $mail->isMail();
            } else {
                $mail->isSMTP();
                $mail->Host = $smtp_config['smtp_host'];
                $mail->Port = (int)$smtp_config['smtp_port'];
                $mail->SMTPAuth = true;
                $mail->Username = $smtp_config['smtp_username'];
                $mail->Password = $smtp_config['smtp_password'];
                $mail->SMTPSecure = ($smtp_config['smtp_encryption'] == 'ssl') ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
            }
            
            // Configurar remetente
            $default_from = 'noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
            $fromEmail = !empty($smtp_config['smtp_from_email']) ? $smtp_config['smtp_from_email'] : ($smtp_config['smtp_username'] ?? $default_from);
            if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
                $fromEmail = $default_from;
            }
            
            $mail->setFrom($fromEmail, $smtp_config['smtp_from_name'] ?? 'Starfy');
            $mail->addAddress($recipient_email, $recipient_name);
            $mail->Subject = $subject;
            $mail->isHTML(true);
            $mail->CharSet = 'UTF-8';
            $mail->Body = $body;
            
            // Enviar email
            $mail->send();
            
            // Atualizar status para sent
            $pdo->prepare("UPDATE email_queue SET status = 'sent', sent_at = NOW() WHERE id = ?")->execute([$email_id]);
            $sent++;
            log_queue("Email enviado com sucesso para: {$recipient_email}");
            
        } catch (Exception $e) {
            $error_message = $e->getMessage();
            $attempts = isset($email_data['attempts']) ? (int)$email_data['attempts'] : 0;
            $new_attempts = $attempts + 1;
            
            // Se já tentou 3 vezes, marcar como failed
            if ($new_attempts >= 3) {
                $pdo->prepare("UPDATE email_queue SET status = 'failed', attempts = ?, error_message = ? WHERE id = ?")
                    ->execute([$new_attempts, $error_message, $email_id]);
                $failed++;
                log_queue("Email falhou após 3 tentativas: {$recipient_email} - {$error_message}");
            } else {
                // Voltar para pending para tentar novamente
                $pdo->prepare("UPDATE email_queue SET status = 'pending', attempts = ?, error_message = ? WHERE id = ?")
                    ->execute([$new_attempts, $error_message, $email_id]);
                log_queue("Email falhou (tentativa {$new_attempts}/3): {$recipient_email} - {$error_message}");
            }
            
            $errors[] = "{$recipient_email}: {$error_message}";
        }
    }
    
    log_queue("Processamento concluído: {$sent} enviados, {$failed} falharam");
    
    ob_clean();
    echo json_encode([
        'success' => true,
        'processed' => count($emails),
        'sent' => $sent,
        'failed' => $failed,
        'message' => "Processados: {$sent} enviados, {$failed} falharam"
    ]);
    
} catch (Exception $e) {
    log_queue("ERRO FATAL: " . $e->getMessage());
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Erro ao processar fila: ' . $e->getMessage()
    ]);
} catch (Error $e) {
    log_queue("ERRO FATAL: " . $e->getMessage());
    ob_clean();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Erro fatal ao processar fila'
    ]);
}

