<?php
/**
 * Script de Recuperação de Carrinho
 * Processa vendas Pix pendentes há mais de 10 minutos e envia emails de recuperação
 * 
 * Executar via cron job a cada 5 minutos
 * Veja instruções no modal de configuração do admin
 */

// Configurações
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../cart_recovery_errors.log');

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

// Inclui helper de recuperação
if (file_exists(__DIR__ . '/../helpers/cart_recovery_helper.php')) {
    require_once __DIR__ . '/../helpers/cart_recovery_helper.php';
} else {
    error_log("CART_RECOVERY: Erro - arquivo cart_recovery_helper.php não encontrado");
    exit(1);
}

// Função de log
function log_recovery($message) {
    $log_file = __DIR__ . '/../cart_recovery_log.txt';
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($log_file, "[$timestamp] $message\n", FILE_APPEND);
    error_log("CART_RECOVERY: $message");
}

log_recovery("=== INÍCIO DO PROCESSAMENTO DE RECUPERAÇÃO DE CARRINHO ===");

try {
    // Busca vendas pendentes há mais de 10 minutos
    $query = "
        SELECT 
            v.id,
            v.comprador_nome,
            v.comprador_email,
            v.produto_id,
            v.valor,
            v.data_venda,
            p.checkout_hash,
            p.nome as produto_nome,
            p.preco as produto_preco
        FROM vendas v
        JOIN produtos p ON v.produto_id = p.id
        WHERE v.status_pagamento = 'pending'
          AND v.metodo_pagamento = 'Pix'
          AND v.email_recovery_sent = 0
          AND TIMESTAMPDIFF(MINUTE, v.data_venda, NOW()) >= 10
        ORDER BY v.data_venda ASC
    ";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $vendas_pendentes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $total_encontradas = count($vendas_pendentes);
    log_recovery("Vendas pendentes encontradas: $total_encontradas");
    
    if ($total_encontradas === 0) {
        log_recovery("Nenhuma venda pendente para processar. Finalizando.");
        exit(0);
    }
    
    $enviados = 0;
    $erros = 0;
    
    // Processa cada venda
    foreach ($vendas_pendentes as $venda) {
        $venda_id = $venda['id'];
        $customer_name = $venda['comprador_nome'] ?? 'Cliente';
        $customer_email = $venda['comprador_email'];
        $product_name = $venda['produto_nome'];
        $product_price = floatval($venda['produto_preco'] ?? $venda['valor']);
        $checkout_hash = $venda['checkout_hash'];
        
        // Valida dados essenciais
        if (empty($customer_email) || !filter_var($customer_email, FILTER_VALIDATE_EMAIL)) {
            log_recovery("Venda ID $venda_id: Email inválido ($customer_email). Pulando.");
            $erros++;
            continue;
        }
        
        if (empty($checkout_hash)) {
            log_recovery("Venda ID $venda_id: checkout_hash não encontrado. Pulando.");
            $erros++;
            continue;
        }
        
        // Constrói URL do checkout
        // Busca URL base das configurações ou constrói a partir do servidor
        $protocol = 'https';
        $host = 'localhost';
        $base_path = '';
        
        // Tenta obter do servidor se disponível (execução via web)
        if (isset($_SERVER['HTTP_HOST']) && !empty($_SERVER['HTTP_HOST'])) {
            $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'];
            $base_path = rtrim(dirname(dirname($_SERVER['PHP_SELF'])), '/\\');
        } else {
            // Execução via CLI - tenta buscar da configuração do sistema
            if (function_exists('getSystemSetting')) {
                $site_url = getSystemSetting('site_url', '');
                if (!empty($site_url)) {
                    $parsed = parse_url($site_url);
                    $protocol = $parsed['scheme'] ?? 'https';
                    $host = $parsed['host'] ?? 'localhost';
                    $base_path = isset($parsed['path']) ? rtrim($parsed['path'], '/') : '';
                } else {
                    // Fallback: tenta obter do banco diretamente
                    $stmt_url = $pdo->query("SELECT valor FROM configuracoes_sistema WHERE chave = 'site_url' LIMIT 1");
                    $url_result = $stmt_url->fetch(PDO::FETCH_ASSOC);
                    if ($url_result && !empty($url_result['valor'])) {
                        $parsed = parse_url($url_result['valor']);
                        $protocol = $parsed['scheme'] ?? 'https';
                        $host = $parsed['host'] ?? 'localhost';
                        $base_path = isset($parsed['path']) ? rtrim($parsed['path'], '/') : '';
                    }
                }
            }
        }
        
        $checkout_url = $protocol . '://' . $host . $base_path . '/checkout?p=' . urlencode($checkout_hash);
        
        log_recovery("Processando venda ID $venda_id - Cliente: $customer_email - Produto: $product_name");
        
        // Envia email de recuperação
        $email_enviado = send_cart_recovery_email(
            $customer_email,
            $customer_name,
            $product_name,
            $product_price,
            $checkout_url
        );
        
        if ($email_enviado) {
            // Atualiza flag email_recovery_sent
            try {
                $stmt_update = $pdo->prepare("UPDATE vendas SET email_recovery_sent = 1 WHERE id = ?");
                $stmt_update->execute([$venda_id]);
                
                log_recovery("Venda ID $venda_id: Email enviado com sucesso e flag atualizada.");
                $enviados++;
            } catch (PDOException $e) {
                log_recovery("Venda ID $venda_id: Erro ao atualizar flag email_recovery_sent: " . $e->getMessage());
                $erros++;
            }
        } else {
            log_recovery("Venda ID $venda_id: Falha ao enviar email.");
            $erros++;
        }
        
        // Pequeno delay para não sobrecarregar o servidor SMTP
        usleep(500000); // 0.5 segundos
    }
    
    log_recovery("=== RESUMO DO PROCESSAMENTO ===");
    log_recovery("Total encontradas: $total_encontradas");
    log_recovery("Emails enviados: $enviados");
    log_recovery("Erros: $erros");
    log_recovery("=== FIM DO PROCESSAMENTO ===");
    
} catch (PDOException $e) {
    log_recovery("ERRO CRÍTICO no banco de dados: " . $e->getMessage());
    exit(1);
} catch (Exception $e) {
    log_recovery("ERRO CRÍTICO: " . $e->getMessage());
    exit(1);
}

exit(0);

