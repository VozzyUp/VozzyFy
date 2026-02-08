<?php
// stripe_webhook.php

// Incluir conexão com banco de dados
require_once 'includes/db.php';
require_once 'helpers/sales_helper.php';

// Definir cabeçalho
header('Content-Type: application/json');

$payload = @file_get_contents('php://input');
$sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

// Obter credenciais do usuário (assumindo que o infoprodutor_id vem no metadata ou buscamos o primeiro usuário admin como padrão se não tivermos contexto)
// Como o webhook é global para a conta conectada, precisamos saber qual chave secreta usar.
// Se usarmos uma conta Stripe para múltiplos infoprodutores, precisamos de uma lógica para identificar qual chave usar.
// Pela estrutura atual, parece que há apenas um admin/infoprodutor principal configurado no banco.

try {
    // 1. Obter configuração do Stripe do banco de dados
    $stmt = $pdo->query("SELECT stripe_secret_key, stripe_webhook_secret FROM usuarios WHERE stripe_public_key IS NOT NULL LIMIT 1");
    $config = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$config) {
        throw new Exception("Configuração Stripe não encontrada");
    }

    $stripe_secret_key = $config['stripe_secret_key'];
    $endpoint_secret = $config['stripe_webhook_secret'];

    $event = null;

    // Verify webhook signature manually since Stripe library is not installed
    if (empty($sig_header) || empty($endpoint_secret)) {
        http_response_code(400);
        exit();
    }

    $signed_payload = $payload;
    $sig_header_parts = explode(',', $sig_header);
    $timestamp = '';
    $signature = '';

    foreach ($sig_header_parts as $part) {
        $part_parts = explode('=', $part, 2);
        if (count($part_parts) == 2) {
            $key = trim($part_parts[0]);
            $value = trim($part_parts[1]);
            if ($key == 't') {
                $timestamp = $value;
            } elseif ($key == 'v1') {
                $signature = $value;
            }
        }
    }

    if (empty($timestamp) || empty($signature)) {
        http_response_code(400);
        exit();
    }

    // Check timestamp to prevent replay attacks (tolerance of 5 minutes)
    if (abs(time() - $timestamp) > 300) {
        http_response_code(400);
        exit();
    }

    $signed_payload = $timestamp . '.' . $payload;
    $expected_signature = hash_hmac('sha256', $signed_payload, $endpoint_secret);

    if (!hash_equals($expected_signature, $signature)) {
        http_response_code(400);
        exit();
    }

    $event = json_decode($payload);

    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        exit();
    }

    // Handle the event
    switch ($event->type) {
        case 'payment_intent.succeeded':
            $paymentIntent = $event->data->object;
            handlePaymentIntentSucceeded($pdo, $paymentIntent);
            break;
        case 'payment_intent.payment_failed':
            $paymentIntent = $event->data->object;
            handlePaymentIntentFailed($pdo, $paymentIntent);
            break;
        default:
            // Unexpected event type
            http_response_code(200);
            exit();
    }

    http_response_code(200);

} catch (Exception $e) {
    error_log("Stripe Webhook Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

function handlePaymentIntentSucceeded($pdo, $paymentIntent)
{
    $payment_id = $paymentIntent->id;

    // Atualizar status da venda para 'approved'
    $stmt = $pdo->prepare("UPDATE vendas SET status_pagamento = 'approved' WHERE transacao_id = ?");
    $stmt->execute([$payment_id]);

    // Verificar se o update afetou alguma linha
    if ($stmt->rowCount() > 0) {
        error_log("Pedido Stripe aprovado: " . $payment_id);
    } else {
        error_log("Pedido Stripe aprovado mas não encontrado no banco: " . $payment_id);
    }
}

function handlePaymentIntentFailed($pdo, $paymentIntent)
{
    $payment_id = $paymentIntent->id;

    // Atualizar status da venda para 'rejected'
    $stmt = $pdo->prepare("UPDATE vendas SET status_pagamento = 'rejected' WHERE transacao_id = ?");
    $stmt->execute([$payment_id]);

    error_log("Pedido Stripe falhou: " . $payment_id);
}
?>