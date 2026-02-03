<?php
/**
 * Reconciliação de Pix Pendentes
 *
 * Verifica vendas Pix pendentes nas últimas 48h e chama check_status para cada uma.
 * O check_status consulta a API do gateway, atualiza o banco e dispara UTMfy/entrega.
 *
 * Configure: INSERT INTO configuracoes (chave, valor) VALUES ('reconcile_pix_key', 'seu_token_secreto');
 * Cron: * / 10 * * * * curl -s "https://seusite.com/api/reconcile_pending_pix.php?key=SEU_TOKEN"
 */

ob_start();
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../reconcile_pix_log.txt');
error_reporting(E_ALL);

header('Content-Type: application/json');

$config_paths = [__DIR__ . '/../config/config.php', __DIR__ . '/config.php'];
foreach ($config_paths as $path) {
    if (file_exists($path)) {
        require $path;
        break;
    }
}

if (!isset($pdo)) {
    http_response_code(500);
    echo json_encode(['error' => 'Banco não configurado']);
    exit;
}

$provided_key = $_GET['key'] ?? $_SERVER['HTTP_X_RECONCILE_KEY'] ?? '';
$expected_key = null;
try {
    $stmt = $pdo->query("SELECT valor FROM configuracoes WHERE chave = 'reconcile_pix_key' LIMIT 1");
    if ($stmt && $row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $expected_key = trim($row['valor']);
    }
} catch (Exception $e) {}
if (empty($expected_key)) {
    $expected_key = getenv('RECONCILE_PIX_KEY') ?: '';
}
if (empty($expected_key) || !hash_equals($expected_key, $provided_key)) {
    http_response_code(403);
    echo json_encode(['error' => 'Acesso negado. Configure reconcile_pix_key em configuracoes.']);
    exit;
}

function log_reconcile($msg) {
    $f = __DIR__ . '/../reconcile_pix_log.txt';
    if (file_exists(__DIR__ . '/../helpers/security_helper.php')) {
        require_once __DIR__ . '/../helpers/security_helper.php';
        if (function_exists('secure_log')) {
            secure_log($f, $msg, 'info');
            return;
        }
    }
    @file_put_contents($f, date('Y-m-d H:i:s') . " - $msg\n", FILE_APPEND);
}

log_reconcile("INÍCIO reconciliação Pix pendentes");

$stmt = $pdo->query("
    SELECT v.transacao_id, v.produto_id, p.usuario_id, p.checkout_config
    FROM vendas v
    JOIN produtos p ON v.produto_id = p.id
    WHERE v.status_pagamento = 'pending'
      AND (v.metodo_pagamento LIKE '%Pix%' OR v.metodo_pagamento LIKE '%pix%')
      AND v.data_venda >= DATE_SUB(NOW(), INTERVAL 48 HOUR)
    ORDER BY v.data_venda DESC
    LIMIT 50
");
$pending = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

$approved = 0;
$base_url = '';
if (!empty($_SERVER['HTTP_HOST'])) {
    $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $base_url = $proto . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname(dirname($_SERVER['PHP_SELF'] ?? '')), '/\\');
}
if (empty($base_url)) {
    try {
        $row = $pdo->query("SELECT valor FROM configuracoes WHERE chave = 'site_url' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($row && !empty($row['valor'])) {
            $base_url = rtrim($row['valor'], '/');
        }
    } catch (Exception $e) {}
}

foreach ($pending as $venda) {
    $payment_id = $venda['transacao_id'];
    $seller_id = (int)$venda['usuario_id'];
    $checkout_config = json_decode($venda['checkout_config'] ?? '{}', true);
    $gateway = $checkout_config['paymentMethods']['pix']['gateway'] ?? 'pushinpay';

    if (!in_array($gateway, ['efi', 'pushinpay', 'asaas', 'applyfy', 'mercadopago'])) {
        continue;
    }

    $url = $base_url . '/api/check_status.php?id=' . urlencode($payment_id) . '&seller_id=' . $seller_id . '&gateway=' . urlencode($gateway);
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $response = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http === 200) {
        $data = json_decode($response, true);
        if ($data && isset($data['status']) && ($data['status'] === 'approved' || $data['status'] === 'paid')) {
            $approved++;
            log_reconcile("Aprovado: $payment_id (gateway: $gateway)");

            $webhook_url = $base_url . '/notification.php';
            $ch2 = curl_init($webhook_url);
            curl_setopt($ch2, CURLOPT_POST, true);
            curl_setopt($ch2, CURLOPT_POSTFIELDS, json_encode(['payment_id' => $payment_id, 'status' => 'paid', 'force_process' => true]));
            curl_setopt($ch2, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch2, CURLOPT_RETURNTRANSFER, false);
            curl_setopt($ch2, CURLOPT_TIMEOUT, 5);
            @curl_exec($ch2);
            curl_close($ch2);
        }
    }
}

log_reconcile("FIM reconciliação. Processados: " . count($pending) . ", Aprovados: $approved");

ob_clean();
echo json_encode([
    'success' => true,
    'processed' => count($pending),
    'approved' => $approved
]);
