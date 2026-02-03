<?php
/**
 * Checkout de Planos SaaS
 * Página dedicada para checkout de planos (separada do checkout de produtos)
 */

require_once __DIR__ . '/../../config/config.php';
include __DIR__ . '/../../config/load_settings.php';

// Verificar se SaaS está habilitado
if (file_exists(__DIR__ . '/../includes/saas_functions.php')) {
    require_once __DIR__ . '/../includes/saas_functions.php';
    if (!saas_enabled()) {
        die("Sistema SaaS não está habilitado.");
    }
} else {
    die("Sistema SaaS não configurado.");
}

// Verificar se usuário está logado
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: /login");
    exit;
}

// Verificar se é infoprodutor
if ($_SESSION["tipo"] !== 'infoprodutor') {
    header("location: /");
    exit;
}

$plano_id = $_GET['plano_id'] ?? null;
if (!$plano_id || !is_numeric($plano_id)) {
    header("location: /index?pagina=saas_planos");
    exit;
}

try {
    // Buscar plano
    $stmt = $pdo->prepare("SELECT * FROM saas_planos WHERE id = ? AND ativo = 1");
    $stmt->execute([$plano_id]);
    $plano = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$plano) {
        die("Plano não encontrado ou inativo.");
    }
    
    // Buscar gateways configurados no admin
    $stmt = $pdo->query("SELECT * FROM saas_admin_gateways");
    $gateways_config = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Organizar por gateway
    $gateways_by_name = [];
    foreach ($gateways_config as $gw) {
        $gateways_by_name[$gw['gateway']] = $gw;
    }
    
    // Buscar preferências de gateway por método de pagamento
    try {
        $stmt = $pdo->query("SELECT * FROM saas_payment_methods");
        $payment_methods_config = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Tabela pode não existir ainda
        error_log("Erro ao buscar preferências de métodos: " . $e->getMessage());
        $payment_methods_config = [];
    }
    
    $preferences = [];
    foreach ($payment_methods_config as $pm) {
        $preferences[$pm['payment_method']] = $pm['gateway'];
    }
    
    // Determinar gateway preferido para Pix
    $pix_gateway_preferred = $preferences['pix'] ?? null;
    $pix_efi_enabled = false;
    $pix_pushinpay_enabled = false;
    $pix_mercadopago_enabled = false;
    
    if ($pix_gateway_preferred === 'efi') {
        $pix_efi_enabled = !empty($gateways_by_name['efi']['efi_client_id']) && 
                          !empty($gateways_by_name['efi']['efi_client_secret']) && 
                          !empty($gateways_by_name['efi']['efi_pix_key']) &&
                          !empty($gateways_by_name['efi']['efi_certificate_path']);
    } elseif ($pix_gateway_preferred === 'pushinpay') {
        $pix_pushinpay_enabled = !empty($gateways_by_name['pushinpay']['pushinpay_token']);
    } elseif ($pix_gateway_preferred === 'mercadopago') {
        $pix_mercadopago_enabled = !empty($gateways_by_name['mercadopago']['mp_access_token']);
    }
    
    // Determinar gateway preferido para Cartão
    $credit_card_gateway_preferred = $preferences['credit_card'] ?? null;
    $credit_card_beehive_enabled = false;
    $credit_card_hypercash_enabled = false;
    $credit_card_efi_enabled = false;
    $credit_card_mercadopago_enabled = false;
    
    if ($credit_card_gateway_preferred === 'hypercash') {
        $credit_card_hypercash_enabled = !empty($gateways_by_name['hypercash']['hypercash_secret_key']) && 
                                        !empty($gateways_by_name['hypercash']['hypercash_public_key']);
    } elseif ($credit_card_gateway_preferred === 'beehive') {
        $credit_card_beehive_enabled = !empty($gateways_by_name['beehive']['beehive_secret_key']) && 
                                       !empty($gateways_by_name['beehive']['beehive_public_key']);
    } elseif ($credit_card_gateway_preferred === 'efi') {
        $credit_card_efi_enabled = !empty($gateways_by_name['efi']['efi_client_id']) && 
                                  !empty($gateways_by_name['efi']['efi_client_secret']) &&
                                  !empty($gateways_by_name['efi']['efi_certificate_path']);
    } elseif ($credit_card_gateway_preferred === 'mercadopago') {
        $credit_card_mercadopago_enabled = !empty($gateways_by_name['mercadopago']['mp_access_token']) && 
                                          !empty($gateways_by_name['mercadopago']['mp_public_key']);
    }
    
    // Se plano é free, processar diretamente
    if ($plano['is_free'] == 1) {
        // Atribuir plano free
        if (function_exists('saas_assign_free_plan')) {
            saas_assign_free_plan($_SESSION['id']);
        }
        header("location: /index?pagina=saas_planos&success=1");
        exit;
    }
    
    // Calcular valor
    $valor = floatval($plano['preco']);
    $periodo = $plano['periodo'];
    
} catch (PDOException $e) {
    die("Erro ao buscar dados: " . $e->getMessage());
}

// Buscar dados do usuário logado
$usuario_nome = $_SESSION['nome'] ?? '';
$usuario_email = $_SESSION['usuario'] ?? '';
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout - <?php echo htmlspecialchars($plano['nome']); ?></title>
    <?php include __DIR__ . '/../../config/load_settings.php'; ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    <link rel="stylesheet" href="/style.css">
    
    <?php if ($credit_card_mercadopago_enabled): ?>
    <!-- Mercado Pago SDK -->
    <script src="https://sdk.mercadopago.com/js/v2"></script>
    <?php endif; ?>
    
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background-color: #07090d;
        }
        
        .payment-method-card {
            transition: all 0.3s ease;
        }
        
        .payment-method-card.selected {
            border-color: var(--accent-primary);
            background-color: rgba(var(--accent-primary-rgb, 50, 231, 104), 0.1);
        }
        
        .modern-input {
            width: 100%;
            padding: 0.75rem 1rem;
            background-color: #0f1419;
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 0.5rem;
            color: white;
            transition: all 0.3s ease;
        }
        
        .modern-input:focus {
            outline: none;
            border-color: var(--accent-primary);
            box-shadow: 0 0 0 3px rgba(var(--accent-primary-rgb, 50, 231, 104), 0.1);
        }
    </style>
</head>
<body>
    <div class="min-h-screen py-8 px-4">
        <div class="max-w-6xl mx-auto">
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
                
                <!-- Coluna Principal - Formulário -->
                <div class="lg:col-span-2">
                    <div class="bg-dark-card rounded-xl border border-dark-border p-6 mb-6">
                        <h1 class="text-2xl font-bold text-white mb-2">Finalizar Assinatura</h1>
                        <p class="text-gray-400">Complete seus dados para finalizar a assinatura do plano</p>
                    </div>
                    
                    <form id="checkout-form" class="space-y-6">
                        <!-- Dados Pessoais -->
                        <div class="bg-dark-card rounded-xl border border-dark-border p-6">
                            <h2 class="text-lg font-semibold text-white mb-4">Dados para Pagamento</h2>
                            
                            <!-- Informações do usuário (somente leitura) -->
                            <div class="bg-dark-elevated rounded-lg p-4 mb-4">
                                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    <div>
                                        <p class="text-gray-400 text-sm mb-1">Nome</p>
                                        <p class="text-white font-semibold"><?php echo htmlspecialchars($usuario_nome); ?></p>
                                    </div>
                                    <div>
                                        <p class="text-gray-400 text-sm mb-1">E-mail</p>
                                        <p class="text-white font-semibold"><?php echo htmlspecialchars($usuario_email); ?></p>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="space-y-4">
                                <div>
                                    <label class="block text-gray-300 mb-2">CPF *</label>
                                    <input type="text" name="cpf" id="cpf" required
                                           placeholder="000.000.000-00"
                                           class="modern-input" maxlength="14">
                                </div>
                                
                                <div>
                                    <label class="block text-gray-300 mb-2">Telefone *</label>
                                    <input type="text" name="phone" id="phone" required
                                           placeholder="(00) 00000-0000"
                                           class="modern-input" maxlength="15">
                                </div>
                            </div>
                        </div>
                        
                        <!-- Método de Pagamento -->
                        <div class="bg-dark-card rounded-xl border border-dark-border p-6">
                            <h2 class="text-lg font-semibold text-white mb-4">Método de Pagamento</h2>
                            
                            <?php 
                            $has_pix = $pix_efi_enabled || $pix_pushinpay_enabled || $pix_mercadopago_enabled;
                            $has_card = $credit_card_beehive_enabled || $credit_card_hypercash_enabled || $credit_card_efi_enabled || $credit_card_mercadopago_enabled;
                            
                            if (!$has_pix && !$has_card): ?>
                                <div class="bg-yellow-900/20 border border-yellow-500 rounded-lg p-4 mb-4">
                                    <p class="text-yellow-300 text-sm">
                                        <i data-lucide="alert-triangle" class="w-5 h-5 inline mr-2"></i>
                                        Nenhum gateway de pagamento configurado. Por favor, configure os gateways no painel admin antes de continuar.
                                    </p>
                                    <a href="/admin?pagina=saas_gateways" class="text-yellow-400 hover:text-yellow-300 text-sm underline mt-2 inline-block">
                                        Configurar Gateways
                                    </a>
                                </div>
                            <?php else: ?>
                                <div class="grid grid-cols-2 gap-4 mb-6" id="payment-methods-selector">
                                    <?php if ($has_pix): ?>
                                        <div class="payment-method-card bg-dark-elevated border-2 border-dark-border rounded-lg p-4 cursor-pointer text-center hover:border-primary transition-colors" 
                                             data-payment-method="pix">
                                            <i data-lucide="qr-code" class="w-8 h-8 mx-auto mb-2 text-primary"></i>
                                            <span class="text-white font-semibold">Pix</span>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if ($has_card): ?>
                                        <div class="payment-method-card bg-dark-elevated border-2 border-dark-border rounded-lg p-4 cursor-pointer text-center hover:border-primary transition-colors" 
                                             data-payment-method="credit_card">
                                            <i data-lucide="credit-card" class="w-8 h-8 mx-auto mb-2 text-primary"></i>
                                            <span class="text-white font-semibold">Cartão de Crédito</span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            
                            <?php if ($has_pix || $has_card): ?>
                                <!-- Container Pix -->
                                <div id="pix-container" class="hidden">
                                    <div class="bg-green-900/20 border border-green-500 rounded-lg p-4 mb-4">
                                        <div class="flex items-center gap-3 mb-2">
                                            <i data-lucide="check-circle" class="w-5 h-5 text-green-400"></i>
                                            <span class="text-green-300 font-semibold">Pagamento via Pix</span>
                                        </div>
                                        <p class="text-sm text-gray-300">• Liberação imediata após pagamento</p>
                                        <p class="text-sm text-gray-300">• 100% Seguro</p>
                                    </div>
                                    <button type="button" id="btn-pagar-pix" 
                                            class="w-full bg-green-600 hover:bg-green-700 text-white font-bold py-4 rounded-lg transition-colors">
                                        GERAR PIX
                                    </button>
                                </div>
                                
                                <!-- Container Cartão -->
                                <div id="card-container" class="hidden">
                                    <div id="card-form-container">
                                        <!-- Formulário de cartão será inserido aqui via JavaScript -->
                                    </div>
                                    <button type="button" id="btn-pagar-cartao" 
                                            class="w-full bg-primary hover:bg-primary/80 text-white font-bold py-4 rounded-lg transition-colors mt-4">
                                        FINALIZAR PAGAMENTO
                                    </button>
                                </div>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
                
                <!-- Coluna Lateral - Resumo -->
                <div class="lg:col-span-1">
                    <div class="bg-dark-card rounded-xl border border-dark-border p-6 sticky top-4">
                        <h2 class="text-lg font-semibold text-white mb-4">Resumo da Assinatura</h2>
                        
                        <div class="space-y-4 mb-6">
                            <div>
                                <p class="text-gray-400 text-sm">Plano</p>
                                <p class="text-white font-semibold"><?php echo htmlspecialchars($plano['nome']); ?></p>
                            </div>
                            
                            <div>
                                <p class="text-gray-400 text-sm">Período</p>
                                <p class="text-white font-semibold"><?php echo ucfirst($periodo); ?></p>
                            </div>
                            
                            <div>
                                <p class="text-gray-400 text-sm">Valor</p>
                                <p class="text-2xl font-bold text-white">R$ <?php echo number_format($valor, 2, ',', '.'); ?></p>
                                <p class="text-gray-400 text-xs"><?php echo $periodo === 'mensal' ? 'por mês' : 'por ano'; ?></p>
                            </div>
                        </div>
                        
                        <div class="border-t border-dark-border pt-4">
                            <div class="flex justify-between items-center mb-2">
                                <span class="text-gray-400">Total</span>
                                <span class="text-2xl font-bold text-white">R$ <?php echo number_format($valor, 2, ',', '.'); ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal Pix QR Code -->
    <div id="pix-modal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
        <div class="bg-dark-card rounded-xl border border-dark-border p-6 max-w-md w-full">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-xl font-bold text-white">Pix Gerado</h3>
                <button onclick="closePixModal()" class="text-gray-400 hover:text-white">
                    <i data-lucide="x" class="w-6 h-6"></i>
                </button>
            </div>
            
            <!-- Estado: Aguardando Pagamento -->
            <div id="pix-waiting-state">
                <div id="pix-qr-container" class="text-center">
                    <!-- QR Code será inserido aqui -->
                </div>
                <div id="pix-copy-container" class="mt-4">
                    <p class="text-gray-300 text-sm mb-2">Ou copie o código Pix:</p>
                    <div class="flex gap-2">
                        <input type="text" id="pix-code" readonly 
                               class="flex-1 bg-dark-elevated border border-dark-border rounded-lg px-4 py-2 text-white text-sm">
                        <button onclick="copyPixCode()" class="px-4 py-2 bg-primary hover:bg-primary/80 text-white rounded-lg">
                            <i data-lucide="copy" class="w-4 h-4"></i>
                        </button>
                    </div>
                </div>
                <p class="text-gray-400 text-xs mt-4 text-center">Aguardando pagamento...</p>
            </div>
            
            <!-- Estado: Pagamento Aprovado -->
            <div id="pix-approved-state" class="hidden text-center">
                <div class="mb-4">
                    <i data-lucide="check-circle" class="w-16 h-16 text-green-500 mx-auto"></i>
                </div>
                <h3 class="text-xl font-bold text-white mb-2">Pagamento Aprovado!</h3>
                <p class="text-gray-300 text-sm mb-4">Seu plano foi ativado com sucesso. Redirecionando...</p>
            </div>
        </div>
    </div>
    
    <script>
        lucide.createIcons();
        
        // Máscaras de input
        document.getElementById('cpf').addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            if (value.length <= 11) {
                value = value.replace(/(\d{3})(\d)/, '$1.$2');
                value = value.replace(/(\d{3})(\d)/, '$1.$2');
                value = value.replace(/(\d{3})(\d{1,2})$/, '$1-$2');
                e.target.value = value;
            }
        });
        
        document.getElementById('phone').addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            if (value.length <= 11) {
                if (value.length <= 10) {
                    value = value.replace(/(\d{2})(\d)/, '($1) $2');
                    value = value.replace(/(\d{4})(\d)/, '$1-$2');
                } else {
                    value = value.replace(/(\d{2})(\d)/, '($1) $2');
                    value = value.replace(/(\d{5})(\d)/, '$1-$2');
                }
                e.target.value = value;
            }
        });
        
        // Seleção de método de pagamento
        let selectedPaymentMethod = null;
        let selectedGateway = null;
        
        document.querySelectorAll('.payment-method-card').forEach(card => {
            card.addEventListener('click', function() {
                document.querySelectorAll('.payment-method-card').forEach(c => c.classList.remove('selected'));
                this.classList.add('selected');
                
                selectedPaymentMethod = this.dataset.paymentMethod;
                
                // Esconder containers
                const pixContainer = document.getElementById('pix-container');
                const cardContainer = document.getElementById('card-container');
                
                if (pixContainer) pixContainer.classList.add('hidden');
                if (cardContainer) cardContainer.classList.add('hidden');
                
                // Mostrar container selecionado
                if (selectedPaymentMethod === 'pix' && pixContainer) {
                    pixContainer.classList.remove('hidden');
                    // Usar gateway preferido configurado
                    selectedGateway = '<?php echo $pix_gateway_preferred ?? ''; ?>';
                    
                    if (!selectedGateway) {
                        alert('Gateway Pix não configurado. Por favor, configure no painel admin.');
                        return;
                    }
                } else if (selectedPaymentMethod === 'credit_card' && cardContainer) {
                    cardContainer.classList.remove('hidden');
                    // Usar gateway preferido configurado
                    selectedGateway = '<?php echo $credit_card_gateway_preferred ?? ''; ?>';
                    
                    if (!selectedGateway) {
                        alert('Gateway de Cartão não configurado. Por favor, configure no painel admin.');
                        return;
                    }
                    
                    // Inicializar formulário de cartão (se necessário)
                    initCardForm();
                }
            });
        });
        
        let mpInstance = null;
        let cardNumberElement = null;
        let expirationDateElement = null;
        let securityCodeElement = null;
        
        function initCardForm() {
            const container = document.getElementById('card-form-container');
            
            // Verificar qual gateway está sendo usado
            if (selectedGateway === 'mercadopago') {
                // Inicializar Mercado Pago SDK
                const mpPublicKey = '<?php echo $gateways_by_name['mercadopago']['mp_public_key'] ?? ''; ?>';
                
                if (!mpPublicKey) {
                    alert('Chave pública do Mercado Pago não configurada');
                    return;
                }
                
                container.innerHTML = `
                    <div class="space-y-4">
                        <div>
                            <label class="block text-gray-300 mb-2">Número do Cartão</label>
                            <div id="cardNumberContainer" class="modern-input" style="padding: 0.75rem 1rem;"></div>
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-gray-300 mb-2">Validade</label>
                                <div id="expirationDateContainer" class="modern-input" style="padding: 0.75rem 1rem;"></div>
                            </div>
                            <div>
                                <label class="block text-gray-300 mb-2">CVV</label>
                                <div id="securityCodeContainer" class="modern-input" style="padding: 0.75rem 1rem;"></div>
                            </div>
                        </div>
                        <div>
                            <label class="block text-gray-300 mb-2">Nome no Cartão</label>
                            <input type="text" id="card-name" placeholder="NOME COMO NO CARTÃO" 
                                   class="modern-input" style="text-transform: uppercase;">
                        </div>
                    </div>
                `;
                
                // Aguardar o DOM ser atualizado
                setTimeout(() => {
                    try {
                        // Inicializar Mercado Pago
                        mpInstance = new MercadoPago(mpPublicKey, {
                            locale: 'pt-BR'
                        });
                        
                        // Criar e montar campos seguros
                        cardNumberElement = mpInstance.fields.create('cardNumber', {
                            placeholder: '0000 0000 0000 0000'
                        }).mount('cardNumberContainer');
                        
                        expirationDateElement = mpInstance.fields.create('expirationDate', {
                            placeholder: 'MM/AA'
                        }).mount('expirationDateContainer');
                        
                        securityCodeElement = mpInstance.fields.create('securityCode', {
                            placeholder: 'CVV'
                        }).mount('securityCodeContainer');
                        
                        console.log('Mercado Pago SDK inicializado com sucesso');
                    } catch (error) {
                        console.error('Erro ao inicializar Mercado Pago SDK:', error);
                        alert('Erro ao inicializar formulário de pagamento. Por favor, recarregue a página.');
                    }
                }, 100);
            } else {
                // Outros gateways - formulário básico
                container.innerHTML = `
                    <div class="space-y-4">
                        <div>
                            <label class="block text-gray-300 mb-2">Número do Cartão</label>
                            <input type="text" id="card-number" placeholder="0000 0000 0000 0000" 
                                   class="modern-input" maxlength="19">
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-gray-300 mb-2">Validade</label>
                                <input type="text" id="card-expiry" placeholder="MM/AA" 
                                       class="modern-input" maxlength="5">
                            </div>
                            <div>
                                <label class="block text-gray-300 mb-2">CVV</label>
                                <input type="text" id="card-cvv" placeholder="000" 
                                       class="modern-input" maxlength="4">
                            </div>
                        </div>
                        <div>
                            <label class="block text-gray-300 mb-2">Nome no Cartão</label>
                            <input type="text" id="card-name" placeholder="NOME COMO NO CARTÃO" 
                                   class="modern-input">
                        </div>
                    </div>
                `;
            }
        }
        
        // Dados do usuário da sessão (PHP)
        const userData = {
            name: <?php echo json_encode($usuario_nome); ?>,
            email: <?php echo json_encode($usuario_email); ?>
        };
        
        // Processar pagamento Pix
        document.getElementById('btn-pagar-pix')?.addEventListener('click', async function() {
            if (!selectedPaymentMethod || !selectedGateway) {
                alert('Selecione um método de pagamento');
                return;
            }
            
            const cpf = document.getElementById('cpf').value.replace(/\D/g, '');
            const phone = document.getElementById('phone').value.replace(/\D/g, '');
            
            if (!cpf || cpf.length !== 11) {
                alert('Por favor, informe um CPF válido');
                return;
            }
            
            if (!phone || phone.length < 10) {
                alert('Por favor, informe um telefone válido');
                return;
            }
            
            const formData = {
                plano_id: <?php echo $plano_id; ?>,
                gateway: selectedGateway,
                payment_method: 'pix',
                transaction_amount: <?php echo $valor; ?>,
                name: userData.name,
                email: userData.email,
                cpf: cpf,
                phone: phone
            };
            
            try {
                const response = await fetch('/saas/checkout/process_plano_payment.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(formData)
                });
                
                // Verificar se a resposta é JSON válido
                const contentType = response.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    const text = await response.text();
                    console.error('Resposta não é JSON:', text);
                    alert('Erro ao processar pagamento. Resposta inválida do servidor.');
                    return;
                }
                
                const data = await response.json();
                
                if (!response.ok) {
                    // Erro HTTP (400, 403, 500, etc)
                    alert(data.error || 'Erro ao processar pagamento. Status: ' + response.status);
                    return;
                }
                
                if (data.status === 'pix_created' && data.pix_data) {
                    // Mostrar QR Code
                    showPixModal(data.pix_data);
                    // Iniciar polling (passar gateway também)
                    startPaymentCheck(data.pix_data.payment_id, selectedGateway);
                } else if (data.error) {
                    alert(data.error);
                } else {
                    alert('Erro ao processar pagamento. Resposta inesperada.');
                }
            } catch (error) {
                console.error('Erro ao processar pagamento:', error);
                alert('Erro ao processar pagamento. Verifique sua conexão e tente novamente.');
            }
        });
        
        // Processar pagamento Cartão
        document.getElementById('btn-pagar-cartao')?.addEventListener('click', async function() {
            if (!selectedPaymentMethod || !selectedGateway) {
                alert('Selecione um método de pagamento');
                return;
            }
            
            const cpf = document.getElementById('cpf').value.replace(/\D/g, '');
            const phone = document.getElementById('phone').value.replace(/\D/g, '');
            const cardName = document.getElementById('card-name')?.value;
            
            if (!cpf || cpf.length !== 11) {
                alert('Por favor, informe um CPF válido');
                return;
            }
            
            if (!phone || phone.length < 10) {
                alert('Por favor, informe um telefone válido');
                return;
            }
            
            if (!cardName || cardName.trim() === '') {
                alert('Por favor, informe o nome no cartão');
                return;
            }
            
            // Processar conforme gateway
            if (selectedGateway === 'mercadopago') {
                await processarCartaoMercadoPago(cpf, phone, cardName);
            } else {
                alert('Gateway de cartão não suportado: ' + selectedGateway);
            }
        });
        
        async function processarCartaoMercadoPago(cpf, phone, cardName) {
            try {
                if (!mpInstance) {
                    alert('SDK do Mercado Pago não inicializado. Por favor, recarregue a página.');
                    return;
                }
                
                // Desabilitar botão durante processamento
                const btn = document.getElementById('btn-pagar-cartao');
                const originalText = btn.textContent;
                btn.disabled = true;
                btn.textContent = 'PROCESSANDO...';
                
                // Criar token do cartão
                const cardData = {
                    cardholderName: cardName.toUpperCase(),
                    identificationType: 'CPF',
                    identificationNumber: cpf
                };
                
                console.log('Criando token do cartão...', cardData);
                const tokenResponse = await mpInstance.createCardToken(cardData);
                
                if (!tokenResponse || !tokenResponse.id) {
                    throw new Error('Falha ao criar token do cartão');
                }
                
                console.log('Token criado com sucesso:', tokenResponse.id);
                
                // Enviar para o backend
                const formData = {
                    plano_id: <?php echo $plano_id; ?>,
                    gateway: 'mercadopago',
                    payment_method: 'credit_card',
                    transaction_amount: <?php echo $valor; ?>,
                    name: userData.name,
                    email: userData.email,
                    cpf: cpf,
                    phone: phone,
                    token: tokenResponse.id,
                    installments: 1,
                    payment_method_id: tokenResponse.payment_method_id || 'visa'
                };
                
                console.log('Enviando pagamento para o backend...');
                const response = await fetch('/saas/checkout/process_plano_payment.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(formData)
                });
                
                const contentType = response.headers.get('content-type');
                if (!contentType || !contentType.includes('application/json')) {
                    const text = await response.text();
                    console.error('Resposta não é JSON:', text);
                    throw new Error('Resposta inválida do servidor');
                }
                
                const data = await response.json();
                
                if (!response.ok) {
                    throw new Error(data.error || 'Erro ao processar pagamento');
                }
                
                if (data.status === 'approved' || data.status === 'paid') {
                    alert('Pagamento aprovado! Redirecionando...');
                    window.location.href = '/index?pagina=saas_planos&success=1&payment_id=' + data.payment_id;
                } else if (data.status === 'pending') {
                    alert('Pagamento em análise. Você receberá uma notificação quando for aprovado.');
                    window.location.href = '/index?pagina=saas_planos&pending=1';
                } else {
                    throw new Error(data.error || 'Pagamento não aprovado');
                }
            } catch (error) {
                console.error('Erro ao criar token ou processar pagamento:', error);
                alert('Erro ao processar pagamento: ' + (error.message || 'Erro desconhecido'));
                
                // Reabilitar botão
                const btn = document.getElementById('btn-pagar-cartao');
                if (btn) {
                    btn.disabled = false;
                    btn.textContent = 'FINALIZAR PAGAMENTO';
                }
            }
        }
        
        function showPixModal(pixData) {
            // Mostrar estado de aguardando pagamento
            const waitingState = document.getElementById('pix-waiting-state');
            const approvedState = document.getElementById('pix-approved-state');
            if (waitingState) waitingState.classList.remove('hidden');
            if (approvedState) approvedState.classList.add('hidden');
            
            document.getElementById('pix-modal').classList.remove('hidden');
            const container = document.getElementById('pix-qr-container');
            
            if (pixData.qr_code_base64) {
                // Verificar se já contém o prefixo data:image
                let qrCodeSrc = pixData.qr_code_base64;
                if (!qrCodeSrc.startsWith('data:image')) {
                    // Se não tiver prefixo, adicionar
                    qrCodeSrc = 'data:image/png;base64,' + qrCodeSrc;
                }
                container.innerHTML = `<img src="${qrCodeSrc}" alt="QR Code Pix" class="mx-auto max-w-full">`;
            } else {
                container.innerHTML = `<p class="text-gray-300">QR Code não disponível</p>`;
            }
            
            if (pixData.qr_code) {
                document.getElementById('pix-code').value = pixData.qr_code;
            }
            
            // Recriar ícones do Lucide
            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }
        }
        
        function closePixModal() {
            document.getElementById('pix-modal').classList.add('hidden');
        }
        
        function copyPixCode() {
            const code = document.getElementById('pix-code');
            code.select();
            document.execCommand('copy');
            alert('Código Pix copiado!');
        }
        
        let paymentCheckInterval = null;
        
        function startPaymentCheck(paymentId, gateway) {
            if (paymentCheckInterval) {
                clearInterval(paymentCheckInterval);
            }
            
            if (!gateway) {
                console.error('Gateway não fornecido para verificação de status');
                return;
            }
            
            let attempts = 0;
            paymentCheckInterval = setInterval(async () => {
                attempts++;
                if (attempts > 120) {
                    clearInterval(paymentCheckInterval);
                    paymentCheckInterval = null;
                    alert('Tempo expirou. Verifique o status do pagamento manualmente.');
                    return;
                }
                
                try {
                    // Usar wrapper na raiz, mesmo padrão do checkout do infoprodutor
                    const url = `/saas_check_status?id=${encodeURIComponent(paymentId)}&gateway=${encodeURIComponent(gateway)}`;
                    const response = await fetch(url);
                    
                    // Se a resposta não for OK, tenta ler o texto para debug
                    if (!response.ok) {
                        const text = await response.text();
                        if (text) {
                            try {
                                const errorResult = JSON.parse(text);
                                console.warn('Erro ao verificar status:', errorResult.message || 'Erro desconhecido');
                            } catch (e) {
                                console.error('Resposta de erro não é JSON válido:', text.substring(0, 200));
                            }
                        } else {
                            console.error('Resposta vazia do servidor (HTTP ' + response.status + ')');
                        }
                        // Continua tentando
                        return;
                    }
                    
                    // Verifica se a resposta é JSON válido
                    const contentType = response.headers.get('content-type');
                    if (!contentType || !contentType.includes('application/json')) {
                        const text = await response.text();
                        console.error('Resposta não é JSON:', text.substring(0, 200));
                        // Não para o intervalo, tenta novamente na próxima iteração
                        return;
                    }
                    
                    const text = await response.text();
                    if (!text || text.trim() === '') {
                        console.error('Resposta vazia do servidor');
                        return;
                    }
                    
                    let result;
                    try {
                        result = JSON.parse(text);
                    } catch (e) {
                        console.error('Erro ao fazer parse do JSON:', e, 'Resposta:', text.substring(0, 200));
                        return;
                    }
                    
                    if (result.status === 'approved' || result.status === 'paid') {
                        clearInterval(paymentCheckInterval);
                        paymentCheckInterval = null;
                        // Mostrar estado de aprovado no modal se existir
                        const waitingState = document.getElementById('pix-waiting-state');
                        const approvedState = document.getElementById('pix-approved-state');
                        if (waitingState) waitingState.classList.add('hidden');
                        if (approvedState) approvedState.classList.remove('hidden');
                        
                        // Redirecionar após 2 segundos
                        setTimeout(() => {
                            window.location.href = '/index?pagina=saas_planos&success=1&payment_id=' + paymentId;
                        }, 2000);
                    } else if (result.status === 'error') {
                        // Se houver erro, loga mas continua tentando
                        console.warn('Erro ao verificar status:', result.message || 'Erro desconhecido');
                    } else if (result.status === 'pending') {
                        // Status ainda pendente, continua verificando
                        // Não faz nada, apenas continua o loop
                    } else if (result.status === 'rejected' || result.status === 'cancelled') {
                        clearInterval(paymentCheckInterval);
                        paymentCheckInterval = null;
                        alert('Pagamento não aprovado. Por favor, tente novamente.');
                    }
                } catch (error) {
                    console.error('Erro ao verificar status:', error);
                }
            }, 5000);
        }
    </script>
</body>
</html>


