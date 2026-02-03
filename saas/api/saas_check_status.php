<?php
/**
 * Verificação de Status de Pagamento de Plano SaaS
 * Similar ao api/check_status.php mas para planos
 */

ob_start();
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

function returnJsonError($message, $code = 500) {
    ob_clean();
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['status' => 'error', 'message' => $message]);
    exit;
}

function returnJsonSuccess($data) {
    ob_clean();
    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

// Carregar config
$config_paths = [
    __DIR__ . '/../../config/config.php',
    __DIR__ . '/../../config.php'
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
            returnJsonError('Erro ao carregar configuração: ' . $e->getMessage(), 500);
        }
    }
}

if (!$config_loaded) {
    returnJsonError('Arquivo de configuração não encontrado.', 500);
}

ob_end_clean();

// Headers CORS e JSON
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Responder a requisições OPTIONS (preflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$payment_id = $_GET['id'] ?? null;
$gateway = $_GET['gateway'] ?? 'mercadopago';

if (!$payment_id) {
    returnJsonError('ID do pagamento não fornecido.', 400);
}

try {
    // Buscar assinatura
    $stmt = $pdo->prepare("SELECT * FROM saas_assinaturas WHERE transacao_id = ? LIMIT 1");
    $stmt->execute([$payment_id]);
    $assinatura = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$assinatura) {
        returnJsonError('Assinatura não encontrada.', 404);
    }
    
    // Buscar credenciais do gateway admin
    $stmt_gateway = $pdo->prepare("SELECT * FROM saas_admin_gateways WHERE gateway = ?");
    $stmt_gateway->execute([$gateway]);
    $gateway_config = $stmt_gateway->fetch(PDO::FETCH_ASSOC);
    
    if (!$gateway_config) {
        returnJsonError('Gateway não configurado.', 400);
    }
    
    // Verificar status conforme gateway
    if ($gateway === 'efi') {
        require_once __DIR__ . '/../../gateways/efi.php';
        
        $client_id = trim($gateway_config['efi_client_id'] ?? '');
        $client_secret = trim($gateway_config['efi_client_secret'] ?? '');
        $certificate_path = trim($gateway_config['efi_certificate_path'] ?? '');
        
        if (empty($client_id) || empty($client_secret) || empty($certificate_path)) {
            returnJsonError('Credenciais Efí não configuradas.', 400);
        }
        
        $full_cert_path = __DIR__ . '/../../' . str_replace('\\', '/', $certificate_path);
        if (!file_exists($full_cert_path)) {
            returnJsonError('Certificado Efí não encontrado.', 400);
        }
        
        $token_data = efi_get_access_token($client_id, $client_secret, $full_cert_path);
        if (!$token_data) {
            returnJsonError('Erro ao obter token de acesso Efí.', 500);
        }
        
        $status_data = efi_get_payment_status($token_data['access_token'], $payment_id, $full_cert_path);
        
        if ($status_data && isset($status_data['status'])) {
            $status = ($status_data['status'] === 'approved' || $status_data['status'] === 'paid') ? 'approved' : $status_data['status'];
            
            if ($status === 'approved' && $assinatura['status'] !== 'ativo') {
                // Buscar informações do plano para calcular vencimento
                $stmt_plano = $pdo->prepare("SELECT periodo FROM saas_planos WHERE id = ?");
                $stmt_plano->execute([$assinatura['plano_id']]);
                $plano = $stmt_plano->fetch(PDO::FETCH_ASSOC);
                
                $periodo_dias = ($plano && $plano['periodo'] === 'anual') ? 365 : 30;
                $novo_vencimento = date('Y-m-d', strtotime("+{$periodo_dias} days"));
                
                // IMPORTANTE: Desativar todas as outras assinaturas do usuário antes de ativar a nova
                $pdo->prepare("
                    UPDATE saas_assinaturas 
                    SET status = 'expirado' 
                    WHERE usuario_id = ? 
                    AND id != ? 
                    AND status = 'ativo'
                ")->execute([$assinatura['usuario_id'], $assinatura['id']]);
                
                // Atualizar assinatura para ativo e atualizar vencimento
                $pdo->prepare("
                    UPDATE saas_assinaturas 
                    SET status = 'ativo', 
                        data_vencimento = ?,
                        notificado_vencimento = 0,
                        notificado_expirado = 0
                    WHERE id = ?
                ")->execute([$novo_vencimento, $assinatura['id']]);
                
                error_log("SaaS (Efí): Assinatura #{$assinatura['id']} ativada para usuário #{$assinatura['usuario_id']}. Vencimento: {$novo_vencimento}");
            }
            
            returnJsonSuccess(['status' => $status]);
        } else {
            returnJsonSuccess(['status' => 'pending', 'message' => 'Status não disponível']);
        }
        
    } elseif ($gateway === 'pushinpay') {
        $token = $gateway_config['pushinpay_token'] ?? '';
        if (!$token) {
            returnJsonError('Token PushinPay não configurado.', 400);
        }
        
        $endpoints = [
            'https://api.pushinpay.com.br/api/transactions/' . $payment_id,
            'https://api.pushinpay.com.br/api/pix/transactions/' . $payment_id,
            'https://api.pushinpay.com.br/api/pix/' . $payment_id
        ];
        
        $response = null;
        $http_code = 0;
        $curl_error = null;
        $data = null;
        
        foreach ($endpoints as $endpoint) {
            $ch = curl_init($endpoint);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $token,
                'Accept: application/json'
            ]);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            
            $response = curl_exec($ch);
            $curl_error = curl_error($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if (!$curl_error && $http_code >= 200 && $http_code < 300) {
                $data = json_decode($response, true);
                if ($data && isset($data['status'])) {
                    break; // Endpoint correto encontrado
                }
            }
        }
        
        if ($curl_error) {
            returnJsonError('Erro de conexão com PushinPay: ' . $curl_error, 500);
        }
        
        if ($data && isset($data['status'])) {
            $status_raw = strtolower($data['status']);
            $status = ($status_raw === 'paid' || $status_raw === 'approved' || $status_raw === 'completed') ? 'approved' : $status_raw;
            
            if ($status === 'approved' && $assinatura['status'] !== 'ativo') {
                // Buscar informações do plano para calcular vencimento
                $stmt_plano = $pdo->prepare("SELECT periodo FROM saas_planos WHERE id = ?");
                $stmt_plano->execute([$assinatura['plano_id']]);
                $plano = $stmt_plano->fetch(PDO::FETCH_ASSOC);
                
                $periodo_dias = ($plano && $plano['periodo'] === 'anual') ? 365 : 30;
                $novo_vencimento = date('Y-m-d', strtotime("+{$periodo_dias} days"));
                
                // IMPORTANTE: Desativar todas as outras assinaturas do usuário antes de ativar a nova
                $pdo->prepare("
                    UPDATE saas_assinaturas 
                    SET status = 'expirado' 
                    WHERE usuario_id = ? 
                    AND id != ? 
                    AND status = 'ativo'
                ")->execute([$assinatura['usuario_id'], $assinatura['id']]);
                
                // Atualizar assinatura para ativo e atualizar vencimento
                $pdo->prepare("
                    UPDATE saas_assinaturas 
                    SET status = 'ativo', 
                        data_vencimento = ?,
                        notificado_vencimento = 0,
                        notificado_expirado = 0
                    WHERE id = ?
                ")->execute([$novo_vencimento, $assinatura['id']]);
                
                error_log("SaaS (PushinPay): Assinatura #{$assinatura['id']} ativada para usuário #{$assinatura['usuario_id']}. Vencimento: {$novo_vencimento}");
            }
            
            returnJsonSuccess(['status' => $status]);
        } else {
            returnJsonSuccess(['status' => 'pending', 'message' => 'Status não disponível']);
        }
        
    } elseif ($gateway === 'mercadopago') {
        $token = $gateway_config['mp_access_token'] ?? '';
        if (!$token) {
            returnJsonError('Token Mercado Pago não configurado.', 400);
        }
        
        $ch = curl_init('https://api.mercadopago.com/v1/payments/' . $payment_id);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code == 200) {
            $data = json_decode($response, true);
            
            if (isset($data['status'])) {
                $status = strtolower($data['status']);
                $normalized_status = ($status === 'approved' || $status === 'paid') ? 'approved' : $status;
                
                if ($normalized_status === 'approved' && $assinatura['status'] !== 'ativo') {
                    // Buscar informações do plano para calcular vencimento
                    $stmt_plano = $pdo->prepare("SELECT periodo FROM saas_planos WHERE id = ?");
                    $stmt_plano->execute([$assinatura['plano_id']]);
                    $plano = $stmt_plano->fetch(PDO::FETCH_ASSOC);
                    
                    $periodo_dias = ($plano && $plano['periodo'] === 'anual') ? 365 : 30;
                    $novo_vencimento = date('Y-m-d', strtotime("+{$periodo_dias} days"));
                    
                    // IMPORTANTE: Desativar todas as outras assinaturas do usuário antes de ativar a nova
                    $pdo->prepare("
                        UPDATE saas_assinaturas 
                        SET status = 'expirado' 
                        WHERE usuario_id = ? 
                        AND id != ? 
                        AND status = 'ativo'
                    ")->execute([$assinatura['usuario_id'], $assinatura['id']]);
                    
                    // Atualizar assinatura para ativo e atualizar vencimento
                    $pdo->prepare("
                        UPDATE saas_assinaturas 
                        SET status = 'ativo', 
                            data_vencimento = ?,
                            notificado_vencimento = 0,
                            notificado_expirado = 0
                        WHERE id = ?
                    ")->execute([$novo_vencimento, $assinatura['id']]);
                    
                    error_log("SaaS: Assinatura #{$assinatura['id']} ativada para usuário #{$assinatura['usuario_id']}. Vencimento: {$novo_vencimento}");
                }
                
                returnJsonSuccess(['status' => $normalized_status]);
            } else {
                returnJsonSuccess(['status' => 'pending', 'message' => 'Status não disponível']);
            }
        } else {
            returnJsonSuccess(['status' => 'pending', 'message' => 'Erro ao consultar status']);
        }
    } else {
        returnJsonError('Gateway não suportado.', 400);
    }
    
} catch (PDOException $e) {
    returnJsonError('Erro de banco de dados: ' . $e->getMessage(), 500);
} catch (Exception $e) {
    returnJsonError('Erro ao verificar status: ' . $e->getMessage(), 500);
}


