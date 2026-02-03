<?php
require __DIR__ . '/config/config.php';
include __DIR__ . '/config/load_settings.php';

$payment_id = $_GET['payment_id'] ?? null;
if (!$payment_id) {
    die("Pagamento não encontrado.");
}

// Buscar dados da venda
$sale_details = null;
$accentColor = '#7427F1'; // Cor padrão
$checkout_url = '';
$gateway = 'mercadopago'; // Padrão
try {
    $stmt = $pdo->prepare("
        SELECT v.*, p.nome as produto_nome, p.checkout_config, p.usuario_id, p.checkout_hash
        FROM vendas v
        JOIN produtos p ON v.produto_id = p.id
        WHERE v.transacao_id = ? 
        ORDER BY v.id DESC 
        LIMIT 1
    ");
    $stmt->execute([$payment_id]);
    $sale_details = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($sale_details) {
        // Buscar cor de destaque do checkout_config
        if (!empty($sale_details['checkout_config'])) {
            $checkout_config = json_decode($sale_details['checkout_config'], true);
            if (is_array($checkout_config) && isset($checkout_config['accentColor'])) {
                $accentColor = $checkout_config['accentColor'];
            }
        }
        
        // Gerar URL do checkout
        if (!empty($sale_details['checkout_hash'])) {
            $checkout_url = '/checkout.php?p=' . urlencode($sale_details['checkout_hash']);
        }
        
        // Detectar gateway pelo checkout_config do produto (fonte confiável)
        $checkout_config = json_decode($sale_details['checkout_config'] ?? '{}', true);
        $payment_methods = $checkout_config['paymentMethods'] ?? [];
        $metodo = strtolower($sale_details['metodo_pagamento'] ?? '');
        
        $gateway_from_config = null;
        if (strpos($metodo, 'cartão') !== false || strpos($metodo, 'cartao') !== false || strpos($metodo, 'credito') !== false) {
            $gateway_from_config = $payment_methods['credit_card']['gateway'] ?? null;
        } elseif (strpos($metodo, 'pix') !== false) {
            $gateway_from_config = $payment_methods['pix']['gateway'] ?? null;
        }
        
        if (!empty($gateway_from_config) && in_array($gateway_from_config, ['efi', 'pushinpay', 'asaas', 'applyfy', 'mercadopago', 'beehive', 'hypercash'])) {
            $gateway = $gateway_from_config;
            // Para cartão Efí, usar efi_card
            if ($gateway === 'efi' && (strpos($metodo, 'cartão') !== false || strpos($metodo, 'cartao') !== false)) {
                $gateway = 'efi_card';
            }
        } else {
            // Fallback: lógica antiga por transacao_id
            if (strpos($metodo, 'cartão') !== false || strpos($metodo, 'cartao') !== false || strpos($metodo, 'credito') !== false) {
                if (strpos($payment_id, 'efi_') === 0 || strlen($payment_id) > 20) {
                    $gateway = 'efi_card';
                } elseif (strlen($payment_id) <= 20 && is_numeric($payment_id)) {
                    $gateway = 'hypercash';
                }
            } elseif (strpos($metodo, 'pix') !== false) {
                if (strpos($payment_id, 'efi_') === 0) {
                    $gateway = 'efi';
                } else {
                    $gateway = 'pushinpay';
                }
            }
        }
    }
} catch (PDOException $e) {
    error_log("Erro ao buscar venda: " . $e->getMessage());
}

// Se não encontrou a venda ou já está aprovado, redirecionar para obrigado
if (!$sale_details) {
    header('Location: /obrigado.php?payment_id=' . urlencode($payment_id));
    exit;
}

if ($sale_details['status_pagamento'] === 'approved') {
    header('Location: /obrigado.php?payment_id=' . urlencode($payment_id));
    exit;
}

// Se já está rejeitado, mostrar página de erro (não redirecionar)
$is_rejected = ($sale_details['status_pagamento'] === 'rejected');

$seller_id = $sale_details['usuario_id'] ?? 0;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Aguardando Confirmação do Pagamento</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    <?php do_action('public_page_head'); ?>
    <style>
        body { font-family: 'Inter', sans-serif; }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        .spinner {
            animation: spin 1s linear infinite;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        .pulse-dot {
            animation: pulse 1.5s ease-in-out infinite;
        }
    </style>
</head>
<body class="bg-gradient-to-br from-gray-50 to-gray-100">
    <?php do_action('aguardando_page_before'); ?>
    <div class="min-h-screen flex items-center justify-center px-4 py-12">
        <div class="max-w-md w-full bg-white rounded-2xl shadow-xl p-8 text-center">
            <!-- Logo (mesma do checkout) -->
            <?php if (!empty($logo_checkout_url)): ?>
            <div class="mb-8 flex justify-center">
                <img src="<?php echo htmlspecialchars($logo_checkout_url, ENT_QUOTES, 'UTF-8'); ?>" 
                     alt="Logo" 
                     class="h-10 w-auto object-contain mx-auto"
                     onerror="this.style.display='none'">
            </div>
            <?php endif; ?>
            
            <!-- Spinner -->
            <div class="mb-6 flex justify-center">
                <div class="relative">
                    <div class="spinner">
                        <i data-lucide="loader-2" class="w-16 h-16" style="color: <?php echo htmlspecialchars($accentColor, ENT_QUOTES, 'UTF-8'); ?>;"></i>
                    </div>
                    <div class="absolute inset-0 flex items-center justify-center">
                        <div class="w-8 h-8 rounded-full pulse-dot" style="background-color: <?php echo htmlspecialchars($accentColor, ENT_QUOTES, 'UTF-8'); ?>; opacity: 0.2;"></div>
                    </div>
                </div>
            </div>
            
            <!-- Título -->
            <h1 class="text-2xl font-bold text-gray-900 mb-3">
                Aguardando Confirmação
            </h1>
            
            <!-- Mensagem -->
            <p class="text-gray-600 mb-2 text-lg">
                Seu pagamento está sendo processado
            </p>
            
            <p class="text-sm text-gray-500 mb-4">
                Por favor, aguarde alguns instantes. Você será redirecionado automaticamente quando o pagamento for confirmado.
            </p>
            
            <!-- Aviso sobre email -->
            <div class="mb-6 p-3 bg-green-50 border border-green-100 rounded-lg">
                <p class="text-xs text-green-700 flex items-center justify-center gap-2">
                    <i data-lucide="mail" class="w-4 h-4"></i>
                    Você receberá um email com os detalhes quando o pagamento for aprovado
                </p>
            </div>
            
            <!-- Status -->
            <div id="status-message" class="text-sm font-medium mb-6" style="color: <?php echo htmlspecialchars($accentColor, ENT_QUOTES, 'UTF-8'); ?>;">
                Verificando status do pagamento...
            </div>
            
            <!-- Área de erro (oculta inicialmente) -->
            <div id="error-section" class="hidden">
                <div class="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg">
                    <div class="flex items-center justify-center gap-2 mb-2">
                        <i data-lucide="x-circle" class="w-5 h-5 text-red-600"></i>
                        <p class="text-sm font-semibold text-red-800">Pagamento não autorizado</p>
                    </div>
                    <p class="text-xs text-red-700 mb-4">
                        Seu pagamento foi recusado. Isso pode acontecer por diversos motivos, como dados incorretos do cartão, limite insuficiente ou problemas com a operadora.
                    </p>
                </div>
                
                <!-- Botões de ação -->
                <div class="flex flex-col gap-3 mb-6">
                    <a href="<?php echo htmlspecialchars($checkout_url ?: '/checkout.php', ENT_QUOTES, 'UTF-8'); ?>" 
                       class="w-full px-6 py-3 rounded-lg font-semibold text-white text-center transition-all hover:opacity-90"
                       style="background-color: <?php echo htmlspecialchars($accentColor, ENT_QUOTES, 'UTF-8'); ?>;">
                        Tentar novamente com outro cartão
                    </a>
                    <?php if ($checkout_url): ?>
                    <a href="<?php echo htmlspecialchars($checkout_url, ENT_QUOTES, 'UTF-8'); ?>" 
                       class="w-full px-6 py-3 rounded-lg font-semibold text-gray-700 text-center bg-gray-100 border border-gray-300 transition-all hover:bg-gray-200">
                        Voltar ao checkout
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Informação do produto (se disponível) -->
            <?php if($sale_details && $sale_details['produto_nome']): ?>
            <div class="mt-6 p-4 bg-gray-50 border border-gray-200 rounded-lg">
                <p class="text-xs text-gray-500 mb-1">Produto adquirido:</p>
                <p class="font-semibold text-gray-800 text-sm"><?php echo htmlspecialchars($sale_details['produto_nome']); ?></p>
            </div>
            <?php endif; ?>
            
            <!-- Aviso (oculto quando erro) -->
            <div id="info-warning" class="mt-8 p-3 bg-blue-50 border border-blue-100 rounded-lg">
                <p class="text-xs text-blue-700 flex items-center justify-center gap-2">
                    <i data-lucide="info" class="w-4 h-4"></i>
                    Não feche esta página
                </p>
            </div>
        </div>
    </div>

    <script>
        lucide.createIcons();
        
        const paymentId = '<?php echo htmlspecialchars($payment_id, ENT_QUOTES, 'UTF-8'); ?>';
        const sellerId = '<?php echo (int)$seller_id; ?>';
        const gateway = '<?php echo htmlspecialchars($gateway, ENT_QUOTES, 'UTF-8'); ?>';
        const checkoutUrl = '<?php echo htmlspecialchars($checkout_url ?: '/checkout.php', ENT_QUOTES, 'UTF-8'); ?>';
        let pollingInterval = null;
        const maxAttempts = 120; // 120 tentativas = ~10 minutos (com intervalo de 5 segundos)
        let attemptCount = 0;
        
        const statusMessage = document.getElementById('status-message');
        const errorSection = document.getElementById('error-section');
        const infoWarning = document.getElementById('info-warning');
        const spinnerContainer = document.querySelector('.mb-6.flex.justify-center');
        
        // Se já está rejeitado, mostrar erro imediatamente
        <?php if ($is_rejected): ?>
        showErrorState();
        <?php endif; ?>
        
        function checkPaymentStatus() {
            attemptCount++;
            
            if (attemptCount > maxAttempts) {
                clearInterval(pollingInterval);
                statusMessage.className = 'text-sm font-medium mb-6';
                statusMessage.innerHTML = '<span class="text-amber-700 block mb-4">O tempo de espera acabou. Se você já realizou o Pix, o pagamento pode ter sido aprovado. Verifique seu email ou tente uma das opções abaixo:</span>' +
                    '<div class="flex flex-col gap-3 mt-4">' +
                    '<button type="button" id="btn-retry-polling" class="w-full px-6 py-3 rounded-lg font-semibold text-white transition-all hover:opacity-90" style="background-color: <?php echo htmlspecialchars($accentColor, ENT_QUOTES, "UTF-8"); ?>;">Verificar novamente</button>' +
                    '<a href="/obrigado.php?payment_id=' + encodeURIComponent(paymentId) + '" class="w-full px-6 py-3 rounded-lg font-semibold text-gray-700 text-center bg-gray-100 border border-gray-300 transition-all hover:bg-gray-200">Ver página de confirmação</a>' +
                    '</div>';
                document.getElementById('btn-retry-polling')?.addEventListener('click', restartPolling);
                lucide.createIcons();
                return;
            }
            
            // Usar os parâmetros corretos: id, seller_id e gateway
            fetch(`/api/check_status.php?id=${encodeURIComponent(paymentId)}&seller_id=${sellerId}&gateway=${encodeURIComponent(gateway)}`)
                .then(response => {
                    if (!response.ok) {
                        // Se for erro 400 ou outro, tentar novamente na próxima iteração
                        return null;
                    }
                    return response.json();
                })
                .then(data => {
                    if (!data) {
                        // Erro na resposta, continuar tentando silenciosamente
                        console.log('Aguardando: Resposta vazia ou inválida, continuando polling...');
                        return;
                    }
                    
                    // Log para debug
                    console.log('Aguardando: Status recebido:', data.status, '| Dados completos:', data);
                    
                    if (data.status === 'approved' || data.status === 'paid') {
                        console.log('Aguardando: Pagamento APROVADO detectado! Redirecionando...');
                        clearInterval(pollingInterval);
                        statusMessage.textContent = 'Pagamento aprovado! Redirecionando...';
                        statusMessage.className = 'text-sm text-green-600 font-medium mb-6';
                        
                        // Redirecionar para obrigado.php após 1 segundo
                        setTimeout(() => {
                            window.location.href = `/obrigado.php?payment_id=${encodeURIComponent(paymentId)}`;
                        }, 1000);
                    } else if (data.status === 'rejected' || data.status === 'refused') {
                        console.log('Aguardando: Pagamento REJEITADO detectado! Recarregando página...');
                        clearInterval(pollingInterval);
                        // Recarregar a página para garantir que o status atualizado do banco seja exibido
                        setTimeout(() => {
                            window.location.reload();
                        }, 500);
                    } else if (data.status === 'unpaid' || data.status === 'pending') {
                        // Verificar se há reason ou message indicando recusa
                        const hasRejectionReason = (data.reason && (
                            data.reason.toLowerCase().includes('recusado') || 
                            data.reason.toLowerCase().includes('negado') || 
                            data.reason.toLowerCase().includes('não autorizado') ||
                            data.reason.toLowerCase().includes('refused') ||
                            data.reason.toLowerCase().includes('rejected') ||
                            data.reason.toLowerCase().includes('denied')
                        ));
                        
                        const hasRejectionMessage = (data.message && (
                            data.message.toLowerCase().includes('recusado') || 
                            data.message.toLowerCase().includes('não autorizado') ||
                            data.message.toLowerCase().includes('negado') ||
                            data.message.toLowerCase().includes('refused') ||
                            data.message.toLowerCase().includes('rejected') ||
                            data.message.toLowerCase().includes('denied') ||
                            data.message.toLowerCase().includes('transação não autorizada')
                        ));
                        
                        if (hasRejectionReason || hasRejectionMessage) {
                            // Se unpaid/pending mas com reason ou message de recusa, tratar como rejeitado
                            clearInterval(pollingInterval);
                            setTimeout(() => {
                                window.location.reload();
                            }, 500);
                        } else {
                            // Status ainda é pending, continuar verificando silenciosamente
                            statusMessage.textContent = 'Aguardando confirmação do pagamento...';
                            statusMessage.style.color = '<?php echo htmlspecialchars($accentColor, ENT_QUOTES, 'UTF-8'); ?>';
                        }
                    } else {
                        // Status ainda é pending, continuar verificando silenciosamente
                        statusMessage.textContent = 'Aguardando confirmação do pagamento...';
                        statusMessage.style.color = '<?php echo htmlspecialchars($accentColor, ENT_QUOTES, 'UTF-8'); ?>';
                    }
                })
                .catch(error => {
                    // Erro silencioso - continuar tentando
                });
        }
        
        function restartPolling() {
            if (pollingInterval) clearInterval(pollingInterval);
            attemptCount = 0;
            statusMessage.innerHTML = 'Verificando status do pagamento...';
            statusMessage.style.color = '<?php echo htmlspecialchars($accentColor, ENT_QUOTES, "UTF-8"); ?>';
            if (spinnerContainer) spinnerContainer.style.display = '';
            pollingInterval = setInterval(checkPaymentStatus, 5000);
            checkPaymentStatus();
        }
        
        // Iniciar polling a cada 5 segundos
        // Primeira verificação após 2 segundos
        setTimeout(() => {
            checkPaymentStatus();
            pollingInterval = setInterval(checkPaymentStatus, 5000);
        }, 2000);
        
        // Limpar intervalo quando a página for fechada
        window.addEventListener('beforeunload', () => {
            if (pollingInterval) {
                clearInterval(pollingInterval);
            }
        });
        
        function showErrorState() {
            clearInterval(pollingInterval);
            
            // Ocultar spinner e mensagens de aguardando
            if (spinnerContainer) {
                spinnerContainer.style.display = 'none';
            }
            statusMessage.style.display = 'none';
            if (infoWarning) {
                infoWarning.style.display = 'none';
            }
            
            // Mostrar seção de erro
            if (errorSection) {
                errorSection.classList.remove('hidden');
            }
            
            // Atualizar título e mensagem principal
            const title = document.querySelector('h1');
            if (title) {
                title.textContent = 'Pagamento não autorizado';
            }
            
            const subtitle = document.querySelector('.text-gray-600.mb-2');
            if (subtitle) {
                subtitle.textContent = 'Seu pagamento foi recusado';
            }
            
            const description = document.querySelector('.text-sm.text-gray-500.mb-4');
            if (description) {
                description.textContent = 'Por favor, tente novamente com outro cartão ou outra forma de pagamento.';
            }
            
            // Recriar ícones após mudanças no DOM
            lucide.createIcons();
        }
    </script>
</body>
</html>
