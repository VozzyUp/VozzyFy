<?php
// Aba Métodos de Pagamento - Grid visual para configurar métodos por gateway

// Buscar credenciais do usuário para verificar se estão configuradas
$usuario_id = $_SESSION['id'] ?? 0;
try {
    $stmt_credenciais = $pdo->prepare("SELECT mp_access_token, pushinpay_token, efi_client_id, efi_client_secret, efi_certificate_path, efi_pix_key, efi_payee_code, hypercash_secret_key, hypercash_public_key, asaas_api_key, asaas_environment, applyfy_public_key, applyfy_secret_key, spacepag_public_key, spacepag_secret_key FROM usuarios WHERE id = ?");
    $stmt_credenciais->execute([$usuario_id]);
    $credenciais = $stmt_credenciais->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Fallback se colunas não existirem
    try {
        $stmt_credenciais = $pdo->prepare("SELECT mp_access_token, pushinpay_token FROM usuarios WHERE id = ?");
        $stmt_credenciais->execute([$usuario_id]);
        $credenciais = $stmt_credenciais->fetch(PDO::FETCH_ASSOC);
        $credenciais['efi_client_id'] = null;
        $credenciais['efi_client_secret'] = null;
        $credenciais['efi_certificate_path'] = null;
        $credenciais['efi_pix_key'] = null;
        $credenciais['efi_payee_code'] = null;
        $credenciais['hypercash_secret_key'] = null;
        $credenciais['hypercash_public_key'] = null;
        $credenciais['asaas_api_key'] = null;
        $credenciais['asaas_environment'] = 'sandbox';
        $credenciais['applyfy_public_key'] = null;
        $credenciais['applyfy_secret_key'] = null;
        $credenciais['spacepag_public_key'] = null;
        $credenciais['spacepag_secret_key'] = null;
    } catch (PDOException $e2) {
        $credenciais = [
            'mp_access_token' => null,
            'pushinpay_token' => null,
            'efi_client_id' => null,
            'efi_client_secret' => null,
            'efi_certificate_path' => null,
            'efi_pix_key' => null,
            'efi_payee_code' => null,
            'hypercash_secret_key' => null,
            'hypercash_public_key' => null,
            'asaas_api_key' => null,
            'asaas_environment' => 'sandbox',
            'applyfy_public_key' => null,
            'applyfy_secret_key' => null,
            'spacepag_public_key' => null,
            'spacepag_secret_key' => null
        ];
    }
}

$spacepag_configured = !empty($credenciais['spacepag_public_key'] ?? '') && !empty($credenciais['spacepag_secret_key'] ?? '');

// Verificar quais gateways estão configurados
$mp_configured = !empty($credenciais['mp_access_token'] ?? '');
$pp_configured = !empty($credenciais['pushinpay_token'] ?? '');

// Para Efí, verificar se todas as credenciais estão preenchidas E se o certificado existe
$efi_configured = false;
if (!empty($credenciais['efi_client_id'] ?? '') && 
    !empty($credenciais['efi_client_secret'] ?? '') && 
    !empty($credenciais['efi_certificate_path'] ?? '') && 
    !empty($credenciais['efi_pix_key'] ?? '')) {
    // Verificar se o arquivo do certificado existe (caminho relativo a partir da raiz)
    $cert_path = $credenciais['efi_certificate_path'];
    // Tentar caminho relativo a partir da raiz do projeto
    $root_path = dirname(__DIR__, 2); // Volta 2 níveis: views/produto_config -> views -> raiz
    $full_cert_path = $root_path . '/' . $cert_path;
    if (file_exists($full_cert_path) || file_exists($cert_path)) {
        $efi_configured = true;
    }
}

// Para Efí Cartão, verificar se todas as credenciais estão preenchidas (incluindo payee_code)
$efi_card_configured = false;
if (!empty($credenciais['efi_client_id'] ?? '') && 
    !empty($credenciais['efi_client_secret'] ?? '') && 
    !empty($credenciais['efi_certificate_path'] ?? '') && 
    !empty($credenciais['efi_payee_code'] ?? '')) {
    // Verificar se o arquivo do certificado existe
    $cert_path = $credenciais['efi_certificate_path'];
    $root_path = dirname(__DIR__, 2);
    $full_cert_path = $root_path . '/' . $cert_path;
    if (file_exists($full_cert_path) || file_exists($cert_path)) {
        $efi_card_configured = true;
    }
}

// Para Hypercash, verificar se ambas as credenciais estão preenchidas
$hypercash_configured = !empty($credenciais['hypercash_secret_key'] ?? '') && !empty($credenciais['hypercash_public_key'] ?? '');

// Para Asaas, verificar se a chave de API está preenchida
$asaas_configured = !empty($credenciais['asaas_api_key'] ?? '');

// Para Applyfy, verificar se ambas as credenciais estão preenchidas
$applyfy_configured = !empty($credenciais['applyfy_public_key'] ?? '') && !empty($credenciais['applyfy_secret_key'] ?? '');

// Para SpacePag, verificar se ambas as credenciais estão preenchidas
$spacepag_configured = !empty($credenciais['spacepag_public_key'] ?? '') && !empty($credenciais['spacepag_secret_key'] ?? '');

// Variáveis removidas - não são mais necessárias sem a seção de gateways
?>

<div class="space-y-6">
    <div>
        <h2 class="text-xl font-semibold mb-4 text-white flex items-center gap-2">
            <i data-lucide="credit-card" class="w-5 h-5" style="color: var(--accent-primary);"></i>
            Métodos de Pagamento
        </h2>
        
        <div class="bg-blue-900/20 border-l-4 border-blue-500 p-4 mb-6 rounded">
            <p class="text-sm text-blue-300">
                <strong class="text-blue-200">Dica:</strong> Selecione os métodos de pagamento diretamente nos cards abaixo. Apenas métodos com credenciais configuradas estarão disponíveis.
            </p>
        </div>

        <!-- Grid de Métodos de Pagamento -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
            <!-- PushinPay Card -->
            <div class="bg-dark-elevated p-6 rounded-lg border border-dark-border <?php echo !$pp_configured ? 'opacity-50 pointer-events-none' : ''; ?>">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold text-white flex items-center gap-2">
                        <i data-lucide="zap" class="w-5 h-5" style="color: var(--accent-primary);"></i>
                        PushinPay
                    </h3>
                    <?php if (!$pp_configured): ?>
                        <div class="flex items-center gap-2">
                            <span class="bg-orange-900/30 text-orange-400 text-xs font-bold px-2 py-1 rounded border border-orange-500/50">Não Configurado</span>
                            <a href="/index?pagina=integracoes" class="text-orange-400 hover:text-orange-300 transition-colors" title="Configurar PushinPay">
                                <i data-lucide="external-link" class="w-4 h-4"></i>
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="space-y-3">
                    <label class="flex items-start p-3 bg-dark-card border border-dark-border rounded-lg cursor-pointer transition-all" <?php echo !$pp_configured ? 'style="pointer-events: none;"' : 'onmouseover="this.style.borderColor=\'var(--accent-primary)\'" onmouseout="this.style.borderColor=\'rgba(255, 255, 255, 0.1)\'"'; ?>>
                        <input type="checkbox" name="payment_pix_pushinpay" value="1" class="form-checkbox mt-1" 
                               <?php echo (isset($payment_methods_config['pix']['gateway']) && $payment_methods_config['pix']['gateway'] === 'pushinpay' && ($payment_methods_config['pix']['enabled'] ?? false)) ? 'checked' : ''; ?>
                               <?php echo !$pp_configured ? 'disabled' : ''; ?>>
                        <div class="ml-3 flex-1">
                            <div class="flex items-center gap-2">
                                <span class="text-sm font-medium text-white">Pix</span>
                                <span class="text-xs font-bold px-2 py-0.5 rounded" style="background-color: color-mix(in srgb, var(--accent-primary) 30%, transparent); color: var(--accent-primary);">Aprovação Imediata</span>
                            </div>
                            <p class="text-xs text-gray-400 mt-1">Pagamento instantâneo via Pix</p>
                        </div>
                    </label>
                </div>
            </div>

            <!-- Efí Card -->
            <div class="bg-dark-elevated p-6 rounded-lg border border-dark-border <?php echo (!$efi_configured && !$efi_card_configured) ? 'opacity-50' : ''; ?>" id="efi-payment-card">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold text-white flex items-center gap-2">
                        <i data-lucide="wallet" class="w-5 h-5 text-purple-400"></i>
                        Efí
                    </h3>
                    <?php if (!$efi_configured && !$efi_card_configured): ?>
                        <div class="flex items-center gap-2">
                            <span class="bg-orange-900/30 text-orange-400 text-xs font-bold px-2 py-1 rounded border border-orange-500/50">Não Configurado</span>
                            <a href="/index?pagina=integracoes" class="text-orange-400 hover:text-orange-300 transition-colors" title="Configurar Efí">
                                <i data-lucide="external-link" class="w-4 h-4"></i>
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="space-y-3">
                    <label class="flex items-start p-3 bg-dark-card border border-dark-border rounded-lg cursor-pointer transition-all hover:border-purple-500" <?php echo !$efi_configured ? 'style="pointer-events: none;"' : ''; ?>>
                        <input type="checkbox" name="payment_pix_efi" value="1" class="form-checkbox mt-1" 
                               <?php echo (isset($payment_methods_config['pix']['gateway']) && $payment_methods_config['pix']['gateway'] === 'efi' && ($payment_methods_config['pix']['enabled'] ?? false)) ? 'checked' : ''; ?>
                               <?php echo !$efi_configured ? 'disabled' : ''; ?>>
                        <div class="ml-3 flex-1">
                            <div class="flex items-center gap-2">
                                <span class="text-sm font-medium text-white">Pix</span>
                                <span class="bg-purple-900/30 text-purple-400 text-xs font-bold px-2 py-0.5 rounded">Aprovação Imediata</span>
                            </div>
                            <p class="text-xs text-gray-400 mt-1">Pagamento instantâneo via Pix</p>
                        </div>
                    </label>
                    <label class="flex items-start p-3 bg-dark-card border border-dark-border rounded-lg cursor-pointer transition-all hover:border-purple-500" <?php echo !$efi_card_configured ? 'style="pointer-events: none;"' : ''; ?>>
                        <input type="checkbox" name="payment_credit_card_efi" value="1" class="form-checkbox mt-1" 
                               <?php echo (isset($payment_methods_config['credit_card']['gateway']) && $payment_methods_config['credit_card']['gateway'] === 'efi' && ($payment_methods_config['credit_card']['enabled'] ?? false)) ? 'checked' : ''; ?>
                               <?php echo !$efi_card_configured ? 'disabled' : ''; ?>>
                        <div class="ml-3 flex-1">
                            <div class="flex items-center gap-2">
                                <span class="text-sm font-medium text-white">Cartão de Crédito</span>
                                <span class="bg-purple-900/30 text-purple-400 text-xs font-bold px-2 py-0.5 rounded">Aprovação Imediata</span>
                            </div>
                            <p class="text-xs text-gray-400 mt-1">Visa, Mastercard, Elo, Amex</p>
                        </div>
                    </label>
                </div>
            </div>

            <!-- Mercado Pago Card -->
            <div class="bg-dark-elevated p-6 rounded-lg border border-dark-border <?php echo !$mp_configured ? 'opacity-50 pointer-events-none' : ''; ?>">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold text-white flex items-center gap-2">
                        <i data-lucide="credit-card" class="w-5 h-5 text-blue-400"></i>
                        Mercado Pago
                    </h3>
                    <?php if (!$mp_configured): ?>
                        <div class="flex items-center gap-2">
                            <span class="bg-orange-900/30 text-orange-400 text-xs font-bold px-2 py-1 rounded border border-orange-500/50">Não Configurado</span>
                            <a href="/index?pagina=integracoes" class="text-orange-400 hover:text-orange-300 transition-colors" title="Configurar Mercado Pago">
                                <i data-lucide="external-link" class="w-4 h-4"></i>
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="space-y-3">
                    <label class="flex items-start p-3 bg-dark-card border border-dark-border rounded-lg cursor-pointer transition-all hover:border-blue-500" <?php echo !$mp_configured ? 'style="pointer-events: none;"' : ''; ?>>
                        <input type="checkbox" name="payment_pix_enabled" value="1" class="form-checkbox mt-1" 
                               <?php echo (isset($payment_methods_config['pix']['gateway']) && $payment_methods_config['pix']['gateway'] === 'mercadopago' && ($payment_methods_config['pix']['enabled'] ?? false)) ? 'checked' : ''; ?>
                               <?php echo !$mp_configured ? 'disabled' : ''; ?>>
                        <div class="ml-3 flex-1">
                            <span class="text-sm font-medium text-white">Pix</span>
                            <p class="text-xs text-gray-400 mt-1">Pagamento via Pix do Mercado Pago</p>
                        </div>
                    </label>
                    
                    <label class="flex items-start p-3 bg-dark-card border border-dark-border rounded-lg cursor-pointer transition-all hover:border-blue-500" <?php echo !$mp_configured ? 'style="pointer-events: none;"' : ''; ?>>
                        <input type="checkbox" name="payment_credit_card_mercadopago" value="1" class="form-checkbox mt-1" 
                               <?php echo (isset($payment_methods_config['credit_card']['gateway']) && $payment_methods_config['credit_card']['gateway'] === 'mercadopago' && ($payment_methods_config['credit_card']['enabled'] ?? false)) ? 'checked' : ''; ?>
                               <?php echo !$mp_configured ? 'disabled' : ''; ?>>
                        <div class="ml-3 flex-1">
                            <span class="text-sm font-medium text-white">Cartão de Crédito</span>
                            <p class="text-xs text-gray-400 mt-1">Visa, Mastercard, Elo, etc.</p>
                        </div>
                    </label>
                    
                    <label class="flex items-start p-3 bg-dark-card border border-dark-border rounded-lg cursor-pointer transition-all hover:border-blue-500" <?php echo !$mp_configured ? 'style="pointer-events: none;"' : ''; ?>>
                        <input type="checkbox" name="payment_ticket_enabled" value="1" class="form-checkbox mt-1" 
                               <?php echo ($payment_methods_config['ticket']['enabled'] ?? false) ? 'checked' : ''; ?>
                               <?php echo !$mp_configured ? 'disabled' : ''; ?>>
                        <div class="ml-3 flex-1">
                            <span class="text-sm font-medium text-white">Boleto</span>
                            <p class="text-xs text-gray-400 mt-1">Boleto bancário</p>
                        </div>
                    </label>
                </div>
            </div>

            <!-- Hypercash Card -->
            <div class="bg-dark-elevated p-6 rounded-lg border border-dark-border <?php echo !$hypercash_configured ? 'opacity-50 pointer-events-none' : ''; ?>">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold text-white flex items-center gap-2">
                        <i data-lucide="credit-card" class="w-5 h-5 text-indigo-400"></i>
                        Hypercash
                    </h3>
                    <?php if (!$hypercash_configured): ?>
                        <div class="flex items-center gap-2">
                            <span class="bg-orange-900/30 text-orange-400 text-xs font-bold px-2 py-1 rounded border border-orange-500/50">Não Configurado</span>
                            <a href="/index?pagina=integracoes" class="text-orange-400 hover:text-orange-300 transition-colors" title="Configurar Hypercash">
                                <i data-lucide="external-link" class="w-4 h-4"></i>
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="space-y-3">
                    <label class="flex items-start p-3 bg-dark-card border border-dark-border rounded-lg cursor-pointer transition-all hover:border-indigo-500" <?php echo !$hypercash_configured ? 'style="pointer-events: none;"' : ''; ?>>
                        <input type="checkbox" name="payment_credit_card_hypercash" value="1" class="form-checkbox mt-1" 
                               <?php echo (isset($payment_methods_config['credit_card']['gateway']) && $payment_methods_config['credit_card']['gateway'] === 'hypercash' && ($payment_methods_config['credit_card']['enabled'] ?? false)) ? 'checked' : ''; ?>
                               <?php echo !$hypercash_configured ? 'disabled' : ''; ?>>
                        <div class="ml-3 flex-1">
                            <div class="flex items-center gap-2">
                                <span class="text-sm font-medium text-white">Cartão de Crédito</span>
                                <span class="bg-indigo-900/30 text-indigo-400 text-xs font-bold px-2 py-0.5 rounded">Aprovação Imediata</span>
                            </div>
                            <p class="text-xs text-gray-400 mt-1">Visa, Mastercard, Elo, etc.</p>
                        </div>
                    </label>
                </div>
            </div>

            <!-- Asaas Card -->
            <div class="bg-dark-elevated p-6 rounded-lg border border-dark-border <?php echo !$asaas_configured ? 'opacity-50 pointer-events-none' : ''; ?>">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold text-white flex items-center gap-2">
                        <i data-lucide="wallet" class="w-5 h-5 text-teal-400"></i>
                        Asaas
                    </h3>
                    <?php if (!$asaas_configured): ?>
                        <div class="flex items-center gap-2">
                            <span class="bg-orange-900/30 text-orange-400 text-xs font-bold px-2 py-1 rounded border border-orange-500/50">Não Configurado</span>
                            <a href="/index?pagina=integracoes" class="text-orange-400 hover:text-orange-300 transition-colors" title="Configurar Asaas">
                                <i data-lucide="external-link" class="w-4 h-4"></i>
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="space-y-3">
                    <label class="flex items-start p-3 bg-dark-card border border-dark-border rounded-lg cursor-pointer transition-all hover:border-teal-500" <?php echo !$asaas_configured ? 'style="pointer-events: none;"' : ''; ?>>
                        <input type="checkbox" name="payment_pix_asaas" value="1" class="form-checkbox mt-1" 
                               <?php echo (isset($payment_methods_config['pix']['gateway']) && $payment_methods_config['pix']['gateway'] === 'asaas' && ($payment_methods_config['pix']['enabled'] ?? false)) ? 'checked' : ''; ?>
                               <?php echo !$asaas_configured ? 'disabled' : ''; ?>>
                        <div class="ml-3 flex-1">
                            <div class="flex items-center gap-2">
                                <span class="text-sm font-medium text-white">Pix</span>
                                <span class="bg-teal-900/30 text-teal-400 text-xs font-bold px-2 py-0.5 rounded">Aprovação Imediata</span>
                            </div>
                            <p class="text-xs text-gray-400 mt-1">Pagamento instantâneo via Pix</p>
                        </div>
                    </label>
                    <label class="flex items-start p-3 bg-dark-card border border-dark-border rounded-lg cursor-pointer transition-all hover:border-teal-500" <?php echo !$asaas_configured ? 'style="pointer-events: none;"' : ''; ?>>
                        <input type="checkbox" name="payment_credit_card_asaas" value="1" class="form-checkbox mt-1" 
                               <?php echo (isset($payment_methods_config['credit_card']['gateway']) && $payment_methods_config['credit_card']['gateway'] === 'asaas' && ($payment_methods_config['credit_card']['enabled'] ?? false)) ? 'checked' : ''; ?>
                               <?php echo !$asaas_configured ? 'disabled' : ''; ?>>
                        <div class="ml-3 flex-1">
                            <div class="flex items-center gap-2">
                                <span class="text-sm font-medium text-white">Cartão de Crédito</span>
                                <span class="bg-teal-900/30 text-teal-400 text-xs font-bold px-2 py-0.5 rounded">Aprovação Imediata</span>
                            </div>
                            <p class="text-xs text-gray-400 mt-1">Visa, Mastercard, Elo, etc.</p>
                        </div>
                    </label>
                </div>
            </div>

            <!-- Applyfy Card -->
            <div class="bg-dark-elevated p-6 rounded-lg border border-dark-border <?php echo !$applyfy_configured ? 'opacity-50 pointer-events-none' : ''; ?>">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold text-white flex items-center gap-2">
                        <i data-lucide="wallet" class="w-5 h-5 text-blue-400"></i>
                        Applyfy
                    </h3>
                    <?php if (!$applyfy_configured): ?>
                        <div class="flex items-center gap-2">
                            <span class="bg-orange-900/30 text-orange-400 text-xs font-bold px-2 py-1 rounded border border-orange-500/50">Não Configurado</span>
                            <a href="/index?pagina=integracoes" class="text-orange-400 hover:text-orange-300 transition-colors" title="Configurar Applyfy">
                                <i data-lucide="external-link" class="w-4 h-4"></i>
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="space-y-3">
                    <label class="flex items-start p-3 bg-dark-card border border-dark-border rounded-lg cursor-pointer transition-all hover:border-blue-500" <?php echo !$applyfy_configured ? 'style="pointer-events: none;"' : ''; ?>>
                        <input type="checkbox" name="payment_pix_applyfy" value="1" class="form-checkbox mt-1" 
                               <?php echo (isset($payment_methods_config['pix']['gateway']) && $payment_methods_config['pix']['gateway'] === 'applyfy' && ($payment_methods_config['pix']['enabled'] ?? false)) ? 'checked' : ''; ?>
                               <?php echo !$applyfy_configured ? 'disabled' : ''; ?>>
                        <div class="ml-3 flex-1">
                            <div class="flex items-center gap-2">
                                <span class="text-sm font-medium text-white">Pix</span>
                                <span class="bg-blue-900/30 text-blue-400 text-xs font-bold px-2 py-0.5 rounded">Aprovação Imediata</span>
                            </div>
                            <p class="text-xs text-gray-400 mt-1">Pagamento instantâneo via Pix</p>
                        </div>
                    </label>
                    <label class="flex items-start p-3 bg-dark-card border border-dark-border rounded-lg cursor-pointer transition-all hover:border-blue-500" <?php echo !$applyfy_configured ? 'style="pointer-events: none;"' : ''; ?>>
                        <input type="checkbox" name="payment_credit_card_applyfy" value="1" class="form-checkbox mt-1" 
                               <?php echo (isset($payment_methods_config['credit_card']['gateway']) && $payment_methods_config['credit_card']['gateway'] === 'applyfy' && ($payment_methods_config['credit_card']['enabled'] ?? false)) ? 'checked' : ''; ?>
                               <?php echo !$applyfy_configured ? 'disabled' : ''; ?>>
                        <div class="ml-3 flex-1">
                            <div class="flex items-center gap-2">
                                <span class="text-sm font-medium text-white">Cartão de Crédito</span>
                                <span class="bg-blue-900/30 text-blue-400 text-xs font-bold px-2 py-0.5 rounded">Aprovação Imediata</span>
                            </div>
                            <p class="text-xs text-gray-400 mt-1">Visa, Mastercard, Elo, etc.</p>
                        </div>
                    </label>
                </div>
            </div>

            <!-- SpacePag Card (somente Pix) -->
            <div class="bg-dark-elevated p-6 rounded-lg border border-dark-border <?php echo !$spacepag_configured ? 'opacity-50 pointer-events-none' : ''; ?>">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold text-white flex items-center gap-2">
                        <i data-lucide="zap" class="w-5 h-5" style="color: #6366f1;"></i>
                        SpacePag
                    </h3>
                    <?php if (!$spacepag_configured): ?>
                        <div class="flex items-center gap-2">
                            <span class="bg-orange-900/30 text-orange-400 text-xs font-bold px-2 py-1 rounded border border-orange-500/50">Não Configurado</span>
                            <a href="/index?pagina=integracoes" class="text-orange-400 hover:text-orange-300 transition-colors" title="Configurar SpacePag">
                                <i data-lucide="external-link" class="w-4 h-4"></i>
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="space-y-3">
                    <label class="flex items-start p-3 bg-dark-card border border-dark-border rounded-lg cursor-pointer transition-all hover:border-indigo-500" <?php echo !$spacepag_configured ? 'style="pointer-events: none;"' : ''; ?>>
                        <input type="checkbox" name="payment_pix_spacepag" value="1" class="form-checkbox mt-1" 
                               <?php echo (isset($payment_methods_config['pix']['gateway']) && $payment_methods_config['pix']['gateway'] === 'spacepag' && ($payment_methods_config['pix']['enabled'] ?? false)) ? 'checked' : ''; ?>
                               <?php echo !$spacepag_configured ? 'disabled' : ''; ?>>
                        <div class="ml-3 flex-1">
                            <div class="flex items-center gap-2">
                                <span class="text-sm font-medium text-white">Pix</span>
                                <span class="text-xs font-bold px-2 py-0.5 rounded" style="background-color: color-mix(in srgb, var(--accent-primary) 30%, transparent); color: var(--accent-primary);">Aprovação Imediata</span>
                            </div>
                            <p class="text-xs text-gray-400 mt-1">Pagamento instantâneo via Pix</p>
                        </div>
                    </label>
                </div>
            </div>
        </div>

        <!-- Resumo Visual -->
        <div class="bg-dark-elevated p-6 rounded-lg border border-dark-border mt-6">
            <h3 class="text-lg font-semibold text-white mb-4">Resumo da Configuração</h3>
            <div id="payment-summary" class="space-y-2 text-sm text-gray-300">
                <p>Carregando...</p>
            </div>
        </div>
        
        <!-- Campos hidden para garantir que valores sejam enviados mesmo quando desabilitados -->
        <!-- IMPORTANTE: Estes campos são atualizados dinamicamente pelo JavaScript antes do submit -->
        <input type="hidden" name="payment_pix_pushinpay" value="<?php echo (isset($payment_methods_config['pix']['gateway']) && $payment_methods_config['pix']['gateway'] === 'pushinpay' && ($payment_methods_config['pix']['enabled'] ?? false)) ? '1' : '0'; ?>">
        <input type="hidden" name="payment_pix_efi" value="<?php echo (isset($payment_methods_config['pix']['gateway']) && $payment_methods_config['pix']['gateway'] === 'efi' && ($payment_methods_config['pix']['enabled'] ?? false)) ? '1' : '0'; ?>">
        <input type="hidden" name="payment_pix_enabled" value="<?php echo (isset($payment_methods_config['pix']['gateway']) && $payment_methods_config['pix']['gateway'] === 'mercadopago' && ($payment_methods_config['pix']['enabled'] ?? false)) ? '1' : '0'; ?>">
        <!-- Removido payment_credit_card_enabled - não é mais necessário, apenas os campos específicos de gateway são usados -->
        <input type="hidden" name="payment_credit_card_mercadopago" value="<?php echo (isset($payment_methods_config['credit_card']['gateway']) && $payment_methods_config['credit_card']['gateway'] === 'mercadopago' && ($payment_methods_config['credit_card']['enabled'] ?? false)) ? '1' : '0'; ?>">
        <input type="hidden" name="payment_credit_card_hypercash" value="<?php echo (isset($payment_methods_config['credit_card']['gateway']) && $payment_methods_config['credit_card']['gateway'] === 'hypercash' && ($payment_methods_config['credit_card']['enabled'] ?? false)) ? '1' : '0'; ?>">
        <input type="hidden" name="payment_credit_card_efi" value="<?php echo (isset($payment_methods_config['credit_card']['gateway']) && $payment_methods_config['credit_card']['gateway'] === 'efi' && ($payment_methods_config['credit_card']['enabled'] ?? false)) ? '1' : '0'; ?>">
        <input type="hidden" name="payment_pix_asaas" value="<?php echo (isset($payment_methods_config['pix']['gateway']) && $payment_methods_config['pix']['gateway'] === 'asaas' && ($payment_methods_config['pix']['enabled'] ?? false)) ? '1' : '0'; ?>">
        <input type="hidden" name="payment_credit_card_asaas" value="<?php echo (isset($payment_methods_config['credit_card']['gateway']) && $payment_methods_config['credit_card']['gateway'] === 'asaas' && ($payment_methods_config['credit_card']['enabled'] ?? false)) ? '1' : '0'; ?>">
        <input type="hidden" name="payment_pix_applyfy" value="<?php echo (isset($payment_methods_config['pix']['gateway']) && $payment_methods_config['pix']['gateway'] === 'applyfy' && ($payment_methods_config['pix']['enabled'] ?? false)) ? '1' : '0'; ?>">
        <input type="hidden" name="payment_credit_card_applyfy" value="<?php echo (isset($payment_methods_config['credit_card']['gateway']) && $payment_methods_config['credit_card']['gateway'] === 'applyfy' && ($payment_methods_config['credit_card']['enabled'] ?? false)) ? '1' : '0'; ?>">
        <input type="hidden" name="payment_pix_spacepag" value="<?php echo (isset($payment_methods_config['pix']['gateway']) && $payment_methods_config['pix']['gateway'] === 'spacepag' && ($payment_methods_config['pix']['enabled'] ?? false)) ? '1' : '0'; ?>">
        <input type="hidden" name="payment_ticket_enabled" value="<?php echo ($payment_methods_config['ticket']['enabled'] ?? false) ? '1' : '0'; ?>">
    </div>
</div>

<script>
// Variáveis globais com estado de configuração dos gateways
const gatewayConfig = {
    mp: <?php echo $mp_configured ? 'true' : 'false'; ?>,
    pp: <?php echo $pp_configured ? 'true' : 'false'; ?>,
    efi: <?php echo $efi_configured ? 'true' : 'false'; ?>,
    efi_card: <?php echo $efi_card_configured ? 'true' : 'false'; ?>,
    hypercash: <?php echo $hypercash_configured ? 'true' : 'false'; ?>,
    asaas: <?php echo $asaas_configured ? 'true' : 'false'; ?>,
    applyfy: <?php echo $applyfy_configured ? 'true' : 'false'; ?>
};

// Função para verificar se pode interagir com gateway
function canInteractWithGateway(gateway) {
    if (gateway === 'pushinpay') return gatewayConfig.pp;
    if (gateway === 'efi') return gatewayConfig.efi;
    if (gateway === 'mercadopago') return gatewayConfig.mp;
    if (gateway === 'hypercash') return gatewayConfig.hypercash;
    if (gateway === 'asaas') return gatewayConfig.asaas;
    if (gateway === 'applyfy') return gatewayConfig.applyfy;
    if (gateway === 'spacepag') return gatewayConfig.spacepag;
    return false;
}

// Função global para atualizar todos os campos hidden antes do submit
window.forceUpdateAllHiddenFields = function() {
    // Atualizar métodos de pagamento (apenas checkboxes visíveis, não os hidden)
    document.querySelectorAll('input[type="checkbox"][name^="payment_"]:not([type="hidden"])').forEach(checkbox => {
        const hiddenInput = document.querySelector(`input[name="${checkbox.name}"][type="hidden"]`);
        if (hiddenInput) {
            hiddenInput.value = checkbox.checked ? '1' : '0';
        }
    });
    
    // Garantir exclusão mútua do Pix e Cartão
    if (typeof enforceSinglePix === 'function') {
        enforceSinglePix();
    }
    if (typeof enforceSingleCreditCard === 'function') {
        enforceSingleCreditCard();
    }
};

// Função removida - não é mais necessária sem a seção de gateways

function enforceSingleCreditCard() {
    const creditCardMP = document.querySelector('input[name="payment_credit_card_mercadopago"]:not([type="hidden"])');
    const creditCardHypercash = document.querySelector('input[name="payment_credit_card_hypercash"]:not([type="hidden"])');
    const creditCardEfi = document.querySelector('input[name="payment_credit_card_efi"]:not([type="hidden"])');
    const creditCardAsaas = document.querySelector('input[name="payment_credit_card_asaas"]:not([type="hidden"])');
    const creditCardApplyfy = document.querySelector('input[name="payment_credit_card_applyfy"]:not([type="hidden"])');

    // Prioridade: Hypercash > Efí > Asaas > Applyfy > Mercado Pago
    // Se Hypercash está selecionado, desmarcar todos os outros
    if (creditCardHypercash && creditCardHypercash.checked && !creditCardHypercash.disabled) {
        if (creditCardMP) {
            creditCardMP.checked = false;
            const hiddenMP = document.querySelector('input[name="payment_credit_card_mercadopago"][type="hidden"]');
            if (hiddenMP) hiddenMP.value = '0';
        }
        if (creditCardEfi) {
            creditCardEfi.checked = false;
            const hiddenEfi = document.querySelector('input[name="payment_credit_card_efi"][type="hidden"]');
            if (hiddenEfi) hiddenEfi.value = '0';
        }
        if (creditCardAsaas) {
            creditCardAsaas.checked = false;
            const hiddenAsaas = document.querySelector('input[name="payment_credit_card_asaas"][type="hidden"]');
            if (hiddenAsaas) hiddenAsaas.value = '0';
        }
        if (creditCardApplyfy) {
            creditCardApplyfy.checked = false;
            const hiddenApplyfy = document.querySelector('input[name="payment_credit_card_applyfy"][type="hidden"]');
            if (hiddenApplyfy) hiddenApplyfy.value = '0';
        }
    }
    // Se Efí está selecionado, desmarcar Mercado Pago, Hypercash, Asaas e Applyfy
    else if (creditCardEfi && creditCardEfi.checked && !creditCardEfi.disabled) {
        if (creditCardMP) {
            creditCardMP.checked = false;
            const hiddenMP = document.querySelector('input[name="payment_credit_card_mercadopago"][type="hidden"]');
            if (hiddenMP) hiddenMP.value = '0';
        }
        if (creditCardHypercash) {
            creditCardHypercash.checked = false;
            const hiddenHypercash = document.querySelector('input[name="payment_credit_card_hypercash"][type="hidden"]');
            if (hiddenHypercash) hiddenHypercash.value = '0';
        }
        if (creditCardAsaas) {
            creditCardAsaas.checked = false;
            const hiddenAsaas = document.querySelector('input[name="payment_credit_card_asaas"][type="hidden"]');
            if (hiddenAsaas) hiddenAsaas.value = '0';
        }
        if (creditCardApplyfy) {
            creditCardApplyfy.checked = false;
            const hiddenApplyfy = document.querySelector('input[name="payment_credit_card_applyfy"][type="hidden"]');
            if (hiddenApplyfy) hiddenApplyfy.value = '0';
        }
    }
    // Se Asaas está selecionado, desmarcar Mercado Pago, Hypercash, Efí e Applyfy
    else if (creditCardAsaas && creditCardAsaas.checked && !creditCardAsaas.disabled) {
        if (creditCardMP) {
            creditCardMP.checked = false;
            const hiddenMP = document.querySelector('input[name="payment_credit_card_mercadopago"][type="hidden"]');
            if (hiddenMP) hiddenMP.value = '0';
        }
        if (creditCardHypercash) {
            creditCardHypercash.checked = false;
            const hiddenHypercash = document.querySelector('input[name="payment_credit_card_hypercash"][type="hidden"]');
            if (hiddenHypercash) hiddenHypercash.value = '0';
        }
        if (creditCardEfi) {
            creditCardEfi.checked = false;
            const hiddenEfi = document.querySelector('input[name="payment_credit_card_efi"][type="hidden"]');
            if (hiddenEfi) hiddenEfi.value = '0';
        }
        if (creditCardApplyfy) {
            creditCardApplyfy.checked = false;
            const hiddenApplyfy = document.querySelector('input[name="payment_credit_card_applyfy"][type="hidden"]');
            if (hiddenApplyfy) hiddenApplyfy.value = '0';
        }
    }
    // Se Applyfy está selecionado, desmarcar Mercado Pago, Hypercash, Efí e Asaas
    else if (creditCardApplyfy && creditCardApplyfy.checked && !creditCardApplyfy.disabled) {
        if (creditCardMP) {
            creditCardMP.checked = false;
            const hiddenMP = document.querySelector('input[name="payment_credit_card_mercadopago"][type="hidden"]');
            if (hiddenMP) hiddenMP.value = '0';
        }
        if (creditCardHypercash) {
            creditCardHypercash.checked = false;
            const hiddenHypercash = document.querySelector('input[name="payment_credit_card_hypercash"][type="hidden"]');
            if (hiddenHypercash) hiddenHypercash.value = '0';
        }
        if (creditCardEfi) {
            creditCardEfi.checked = false;
            const hiddenEfi = document.querySelector('input[name="payment_credit_card_efi"][type="hidden"]');
            if (hiddenEfi) hiddenEfi.value = '0';
        }
        if (creditCardAsaas) {
            creditCardAsaas.checked = false;
            const hiddenAsaas = document.querySelector('input[name="payment_credit_card_asaas"][type="hidden"]');
            if (hiddenAsaas) hiddenAsaas.value = '0';
        }
    }
    // Se Mercado Pago está selecionado, desmarcar Hypercash, Efí, Asaas e Applyfy
    else if (creditCardMP && creditCardMP.checked && !creditCardMP.disabled) {
        if (creditCardHypercash) {
            creditCardHypercash.checked = false;
            const hiddenHypercash = document.querySelector('input[name="payment_credit_card_hypercash"][type="hidden"]');
            if (hiddenHypercash) hiddenHypercash.value = '0';
        }
        if (creditCardEfi) {
            creditCardEfi.checked = false;
            const hiddenEfi = document.querySelector('input[name="payment_credit_card_efi"][type="hidden"]');
            if (hiddenEfi) hiddenEfi.value = '0';
        }
        if (creditCardAsaas) {
            creditCardAsaas.checked = false;
            const hiddenAsaas = document.querySelector('input[name="payment_credit_card_asaas"][type="hidden"]');
            if (hiddenAsaas) hiddenAsaas.value = '0';
        }
        if (creditCardApplyfy) {
            creditCardApplyfy.checked = false;
            const hiddenApplyfy = document.querySelector('input[name="payment_credit_card_applyfy"][type="hidden"]');
            if (hiddenApplyfy) hiddenApplyfy.value = '0';
        }
    }
}

function enforceSinglePix() {
    const pixPP = document.querySelector('input[name="payment_pix_pushinpay"]:not([type="hidden"])');
    const pixEfi = document.querySelector('input[name="payment_pix_efi"]:not([type="hidden"])');
    const pixMP = document.querySelector('input[name="payment_pix_enabled"]:not([type="hidden"])');
    const pixAsaas = document.querySelector('input[name="payment_pix_asaas"]:not([type="hidden"])');
    const pixApplyfy = document.querySelector('input[name="payment_pix_applyfy"]:not([type="hidden"])');
    const pixSpacepag = document.querySelector('input[name="payment_pix_spacepag"]:not([type="hidden"])');
    
    // Prioridade: PushinPay > Efí > Asaas > Applyfy > SpacePag > Mercado Pago
    // Se PushinPay Pix está marcado, desmarcar Efí, Asaas, Applyfy e Mercado Pago Pix
    if (pixPP && pixPP.checked && !pixPP.disabled) {
        if (pixEfi) {
            pixEfi.checked = false;
            const hiddenEfi = document.querySelector('input[name="payment_pix_efi"][type="hidden"]');
            if (hiddenEfi) hiddenEfi.value = '0';
        }
        if (pixAsaas) {
            pixAsaas.checked = false;
            const hiddenAsaas = document.querySelector('input[name="payment_pix_asaas"][type="hidden"]');
            if (hiddenAsaas) hiddenAsaas.value = '0';
        }
        if (pixApplyfy) {
            pixApplyfy.checked = false;
            const hiddenApplyfy = document.querySelector('input[name="payment_pix_applyfy"][type="hidden"]');
            if (hiddenApplyfy) hiddenApplyfy.value = '0';
        }
        if (pixSpacepag) {
            pixSpacepag.checked = false;
            const hiddenSpacepag = document.querySelector('input[name="payment_pix_spacepag"][type="hidden"]');
            if (hiddenSpacepag) hiddenSpacepag.value = '0';
        }
        if (pixMP) {
            pixMP.checked = false;
            const hiddenMP = document.querySelector('input[name="payment_pix_enabled"][type="hidden"]');
            if (hiddenMP) hiddenMP.value = '0';
        }
    }
    
    // Se Efí Pix está marcado, desmarcar Asaas, Applyfy, SpacePag e Mercado Pago Pix (mas não PushinPay se estiver marcado)
    else if (pixEfi && pixEfi.checked && !pixEfi.disabled) {
        if (pixAsaas) {
            pixAsaas.checked = false;
            const hiddenAsaas = document.querySelector('input[name="payment_pix_asaas"][type="hidden"]');
            if (hiddenAsaas) hiddenAsaas.value = '0';
        }
        if (pixApplyfy) {
            pixApplyfy.checked = false;
            const hiddenApplyfy = document.querySelector('input[name="payment_pix_applyfy"][type="hidden"]');
            if (hiddenApplyfy) hiddenApplyfy.value = '0';
        }
        if (pixMP) {
            pixMP.checked = false;
            const hiddenMP = document.querySelector('input[name="payment_pix_enabled"][type="hidden"]');
            if (hiddenMP) hiddenMP.value = '0';
        }
        // Se PushinPay também estiver marcado, desmarcar Efí (PushinPay tem prioridade)
        if (pixPP && pixPP.checked && !pixPP.disabled) {
            pixEfi.checked = false;
            const hiddenEfi = document.querySelector('input[name="payment_pix_efi"][type="hidden"]');
            if (hiddenEfi) hiddenEfi.value = '0';
        }
    }
    
    // Se Asaas Pix está marcado, desmarcar Applyfy e Mercado Pago Pix (mas não PushinPay ou Efí se estiverem marcados)
    else if (pixAsaas && pixAsaas.checked && !pixAsaas.disabled) {
        if (pixApplyfy) {
            pixApplyfy.checked = false;
            const hiddenApplyfy = document.querySelector('input[name="payment_pix_applyfy"][type="hidden"]');
            if (hiddenApplyfy) hiddenApplyfy.value = '0';
        }
        if (pixSpacepag) {
            pixSpacepag.checked = false;
            const hiddenSpacepag = document.querySelector('input[name="payment_pix_spacepag"][type="hidden"]');
            if (hiddenSpacepag) hiddenSpacepag.value = '0';
        }
        if (pixMP) {
            pixMP.checked = false;
            const hiddenMP = document.querySelector('input[name="payment_pix_enabled"][type="hidden"]');
            if (hiddenMP) hiddenMP.value = '0';
        }
        // Se PushinPay ou Efí também estiverem marcados, desmarcar Asaas (têm prioridade)
        if (pixPP && pixPP.checked && !pixPP.disabled) {
            pixAsaas.checked = false;
            const hiddenAsaas = document.querySelector('input[name="payment_pix_asaas"][type="hidden"]');
            if (hiddenAsaas) hiddenAsaas.value = '0';
        }
        if (pixEfi && pixEfi.checked && !pixEfi.disabled) {
            pixAsaas.checked = false;
            const hiddenAsaas = document.querySelector('input[name="payment_pix_asaas"][type="hidden"]');
            if (hiddenAsaas) hiddenAsaas.value = '0';
        }
    }
    
    // Se Applyfy Pix está marcado, desmarcar SpacePag e Mercado Pago Pix (mas não PushinPay, Efí ou Asaas se estiverem marcados)
    else if (pixApplyfy && pixApplyfy.checked && !pixApplyfy.disabled) {
        if (pixSpacepag) {
            pixSpacepag.checked = false;
            const hiddenSpacepag = document.querySelector('input[name="payment_pix_spacepag"][type="hidden"]');
            if (hiddenSpacepag) hiddenSpacepag.value = '0';
        }
        if (pixMP) {
            pixMP.checked = false;
            const hiddenMP = document.querySelector('input[name="payment_pix_enabled"][type="hidden"]');
            if (hiddenMP) hiddenMP.value = '0';
        }
        // Se PushinPay, Efí ou Asaas também estiverem marcados, desmarcar Applyfy (têm prioridade)
        if (pixPP && pixPP.checked && !pixPP.disabled) {
            pixApplyfy.checked = false;
            const hiddenApplyfy = document.querySelector('input[name="payment_pix_applyfy"][type="hidden"]');
            if (hiddenApplyfy) hiddenApplyfy.value = '0';
        }
        if (pixEfi && pixEfi.checked && !pixEfi.disabled) {
            pixApplyfy.checked = false;
            const hiddenApplyfy = document.querySelector('input[name="payment_pix_applyfy"][type="hidden"]');
            if (hiddenApplyfy) hiddenApplyfy.value = '0';
        }
        if (pixAsaas && pixAsaas.checked && !pixAsaas.disabled) {
            pixApplyfy.checked = false;
            const hiddenApplyfy = document.querySelector('input[name="payment_pix_applyfy"][type="hidden"]');
            if (hiddenApplyfy) hiddenApplyfy.value = '0';
        }
    }
    
    // Se SpacePag Pix está marcado, desmarcar Mercado Pago Pix (mas não PushinPay, Efí, Asaas ou Applyfy se estiverem marcados)
    else if (pixSpacepag && pixSpacepag.checked && !pixSpacepag.disabled) {
        if (pixMP) {
            pixMP.checked = false;
            const hiddenMP = document.querySelector('input[name="payment_pix_enabled"][type="hidden"]');
            if (hiddenMP) hiddenMP.value = '0';
        }
        if (pixPP && pixPP.checked && !pixPP.disabled) {
            pixSpacepag.checked = false;
            const hiddenSpacepag = document.querySelector('input[name="payment_pix_spacepag"][type="hidden"]');
            if (hiddenSpacepag) hiddenSpacepag.value = '0';
        }
        if (pixEfi && pixEfi.checked && !pixEfi.disabled) {
            pixSpacepag.checked = false;
            const hiddenSpacepag = document.querySelector('input[name="payment_pix_spacepag"][type="hidden"]');
            if (hiddenSpacepag) hiddenSpacepag.value = '0';
        }
        if (pixAsaas && pixAsaas.checked && !pixAsaas.disabled) {
            pixSpacepag.checked = false;
            const hiddenSpacepag = document.querySelector('input[name="payment_pix_spacepag"][type="hidden"]');
            if (hiddenSpacepag) hiddenSpacepag.value = '0';
        }
        if (pixApplyfy && pixApplyfy.checked && !pixApplyfy.disabled) {
            pixSpacepag.checked = false;
            const hiddenSpacepag = document.querySelector('input[name="payment_pix_spacepag"][type="hidden"]');
            if (hiddenSpacepag) hiddenSpacepag.value = '0';
        }
    }
    
    // Se Mercado Pago Pix está marcado, desmarcar PushinPay, Efí, Asaas, Applyfy e SpacePag Pix
    else if (pixMP && pixMP.checked && !pixMP.disabled) {
        if (pixPP) {
            pixPP.checked = false;
            const hiddenPP = document.querySelector('input[name="payment_pix_pushinpay"][type="hidden"]');
            if (hiddenPP) hiddenPP.value = '0';
        }
        if (pixEfi) {
            pixEfi.checked = false;
            const hiddenEfi = document.querySelector('input[name="payment_pix_efi"][type="hidden"]');
            if (hiddenEfi) hiddenEfi.value = '0';
        }
        if (pixAsaas) {
            pixAsaas.checked = false;
            const hiddenAsaas = document.querySelector('input[name="payment_pix_asaas"][type="hidden"]');
            if (hiddenAsaas) hiddenAsaas.value = '0';
        }
        if (pixApplyfy) {
            pixApplyfy.checked = false;
            const hiddenApplyfy = document.querySelector('input[name="payment_pix_applyfy"][type="hidden"]');
            if (hiddenApplyfy) hiddenApplyfy.value = '0';
        }
    }
}

function updateSummary() {
    const pixPP = document.querySelector('input[name="payment_pix_pushinpay"]:not([type="hidden"])')?.checked || false;
    const pixEfi = document.querySelector('input[name="payment_pix_efi"]:not([type="hidden"])')?.checked || false;
    const pixMP = document.querySelector('input[name="payment_pix_enabled"]:not([type="hidden"])')?.checked || false;
    const pixAsaas = document.querySelector('input[name="payment_pix_asaas"]:not([type="hidden"])')?.checked || false;
    const pixApplyfy = document.querySelector('input[name="payment_pix_applyfy"]:not([type="hidden"])')?.checked || false;
    const pixSpacepag = document.querySelector('input[name="payment_pix_spacepag"]:not([type="hidden"])')?.checked || false;
    const creditCardMP = document.querySelector('input[name="payment_credit_card_mercadopago"]:not([type="hidden"])')?.checked || false;
    const creditCardHypercash = document.querySelector('input[name="payment_credit_card_hypercash"]:not([type="hidden"])')?.checked || false;
    const creditCardEfi = document.querySelector('input[name="payment_credit_card_efi"]:not([type="hidden"])')?.checked || false;
    const creditCardAsaas = document.querySelector('input[name="payment_credit_card_asaas"]:not([type="hidden"])')?.checked || false;
    const creditCardApplyfy = document.querySelector('input[name="payment_credit_card_applyfy"]:not([type="hidden"])')?.checked || false;
    const ticket = document.querySelector('input[name="payment_ticket_enabled"]:not([type="hidden"])')?.checked || false;
    
    const summary = document.getElementById('payment-summary');
    let html = '';
    
    if (pixPP) {
        html += '<p style="color: var(--accent-primary);">✓ Pix via <strong>PushinPay</strong> (Aprovação Imediata)</p>';
    }
    
    if (pixEfi) {
        html += '<p class="text-purple-400">✓ Pix via <strong>Efí</strong> (Aprovação Imediata)</p>';
    }
    
    if (pixAsaas) {
        html += '<p class="text-teal-400">✓ Pix via <strong>Asaas</strong> (Aprovação Imediata)</p>';
    }
    
    if (pixApplyfy) {
        html += '<p class="text-blue-400">✓ Pix via <strong>Applyfy</strong> (Aprovação Imediata)</p>';
    }
    
    if (pixSpacepag) {
        html += '<p class="text-indigo-400">✓ Pix via <strong>SpacePag</strong> (Aprovação Imediata)</p>';
    }
    
    if (pixMP) {
        html += '<p class="text-blue-400">✓ Pix via <strong>Mercado Pago</strong></p>';
    }
    
    if (creditCardMP) {
        html += '<p class="text-blue-400">✓ Cartão de Crédito via <strong>Mercado Pago</strong></p>';
    }
    
    if (creditCardHypercash) {
        html += '<p class="text-indigo-400">✓ Cartão de Crédito via <strong>Hypercash</strong> (Aprovação Imediata)</p>';
    }
    
    if (creditCardEfi) {
        html += '<p class="text-purple-400">✓ Cartão de Crédito via <strong>Efí</strong> (Aprovação Imediata)</p>';
    }
    
    if (creditCardAsaas) {
        html += '<p class="text-teal-400">✓ Cartão de Crédito via <strong>Asaas</strong> (Aprovação Imediata)</p>';
    }
    
    if (creditCardApplyfy) {
        html += '<p class="text-blue-400">✓ Cartão de Crédito via <strong>Applyfy</strong> (Aprovação Imediata)</p>';
    }
    
    if (ticket) {
        html += '<p class="text-blue-400">✓ Boleto via <strong>Mercado Pago</strong></p>';
    }
    
    if (!html) {
        html = '<p class="text-gray-400">Nenhum método de pagamento habilitado</p>';
    }
    
    summary.innerHTML = html;
}

// Atualizar summary ao carregar e ao mudar checkboxes
document.addEventListener('DOMContentLoaded', () => {
    updateSummary();
    
    // Adicionar listeners para garantir apenas 1 Pix
    // Usar :not([type="hidden"]) para pegar apenas os checkboxes visíveis
    const pixPP = document.querySelector('input[name="payment_pix_pushinpay"]:not([type="hidden"])');
    const pixEfi = document.querySelector('input[name="payment_pix_efi"]:not([type="hidden"])');
    const pixMP = document.querySelector('input[name="payment_pix_enabled"]:not([type="hidden"])');
    const pixAsaas = document.querySelector('input[name="payment_pix_asaas"]:not([type="hidden"])');
    
    if (pixPP) {
        pixPP.addEventListener('change', function() {
            const hiddenInput = document.querySelector('input[name="payment_pix_pushinpay"][type="hidden"]');
            if (hiddenInput) {
                hiddenInput.value = this.checked ? '1' : '0';
            }
            enforceSinglePix();
            updateSummary();
        });
    }
    
    if (pixEfi) {
        pixEfi.addEventListener('change', function() {
            const hiddenInput = document.querySelector('input[name="payment_pix_efi"][type="hidden"]');
            if (hiddenInput) {
                hiddenInput.value = this.checked ? '1' : '0';
            }
            enforceSinglePix();
            updateSummary();
        });
    }
    
    if (pixMP) {
        pixMP.addEventListener('change', function() {
            const hiddenInput = document.querySelector('input[name="payment_pix_enabled"][type="hidden"]');
            if (hiddenInput) {
                hiddenInput.value = this.checked ? '1' : '0';
            }
            enforceSinglePix();
            updateSummary();
        });
    }
    
    if (pixAsaas) {
        pixAsaas.addEventListener('change', function() {
            const hiddenInput = document.querySelector('input[name="payment_pix_asaas"][type="hidden"]');
            if (hiddenInput) {
                hiddenInput.value = this.checked ? '1' : '0';
            }
            enforceSinglePix();
            updateSummary();
        });
    }
    
    if (pixApplyfy) {
        pixApplyfy.addEventListener('change', function() {
            const hiddenInput = document.querySelector('input[name="payment_pix_applyfy"][type="hidden"]');
            if (hiddenInput) {
                hiddenInput.value = this.checked ? '1' : '0';
            }
            enforceSinglePix();
            updateSummary();
        });
    }
    
    const pixSpacepag = document.querySelector('input[name="payment_pix_spacepag"]:not([type="hidden"])');
    if (pixSpacepag) {
        pixSpacepag.addEventListener('change', function() {
            const hiddenInput = document.querySelector('input[name="payment_pix_spacepag"][type="hidden"]');
            if (hiddenInput) {
                hiddenInput.value = this.checked ? '1' : '0';
            }
            enforceSinglePix();
            updateSummary();
        });
    }
    
    // Função para adicionar listeners aos checkboxes de cartão de crédito
    function setupCreditCardListeners() {
        // Listeners para checkboxes de cartão de crédito (exclusão mútua)
        const creditCardMP = document.querySelector('input[name="payment_credit_card_mercadopago"]:not([type="hidden"])');
        const creditCardHypercash = document.querySelector('input[name="payment_credit_card_hypercash"]:not([type="hidden"])');
        const creditCardEfi = document.querySelector('input[name="payment_credit_card_efi"]:not([type="hidden"])');
        const creditCardAsaas = document.querySelector('input[name="payment_credit_card_asaas"]:not([type="hidden"])');
        const creditCardApplyfy = document.querySelector('input[name="payment_credit_card_applyfy"]:not([type="hidden"])');
        
        if (creditCardMP) {
            // Remover listener antigo se existir
            creditCardMP.replaceWith(creditCardMP.cloneNode(true));
            const newCreditCardMP = document.querySelector('input[name="payment_credit_card_mercadopago"]:not([type="hidden"])');
            newCreditCardMP.addEventListener('change', function() {
                const hiddenInput = document.querySelector('input[name="payment_credit_card_mercadopago"][type="hidden"]');
                if (hiddenInput) {
                    hiddenInput.value = this.checked ? '1' : '0';
                }
                enforceSingleCreditCard();
                updateSummary();
            });
        }
        
        if (creditCardHypercash) {
            // Remover listener antigo se existir
            creditCardHypercash.replaceWith(creditCardHypercash.cloneNode(true));
            const newCreditCardHypercash = document.querySelector('input[name="payment_credit_card_hypercash"]:not([type="hidden"])');
            newCreditCardHypercash.addEventListener('change', function() {
                const hiddenInput = document.querySelector('input[name="payment_credit_card_hypercash"][type="hidden"]');
                if (hiddenInput) {
                    hiddenInput.value = this.checked ? '1' : '0';
                }
                enforceSingleCreditCard();
                updateSummary();
            });
        }
        
        if (creditCardEfi) {
            console.log('Checkbox Efí Cartão encontrado, adicionando listener');
            console.log('Checkbox disabled?', creditCardEfi.disabled);
            console.log('gatewayConfig.efi_card:', gatewayConfig.efi_card);
            
            // Remover listener antigo se existir
            creditCardEfi.replaceWith(creditCardEfi.cloneNode(true));
            const newCreditCardEfi = document.querySelector('input[name="payment_credit_card_efi"]:not([type="hidden"])');
            
            // Garantir que está habilitado se as credenciais estão configuradas
            if (gatewayConfig.efi_card && newCreditCardEfi) {
                newCreditCardEfi.disabled = false;
                newCreditCardEfi.style.pointerEvents = 'auto';
                newCreditCardEfi.style.cursor = 'pointer';
            }
            
            newCreditCardEfi.addEventListener('click', function(e) {
                console.log('Checkbox Efí Cartão clicado!', e);
                e.stopPropagation();
            }, true);
            
            newCreditCardEfi.addEventListener('change', function(e) {
                const hiddenInput = document.querySelector('input[name="payment_credit_card_efi"][type="hidden"]');
                if (hiddenInput) {
                    hiddenInput.value = this.checked ? '1' : '0';
                }
                enforceSingleCreditCard();
                updateSummary();
            });
            
            // Adicionar listener no label também para garantir que funcione
            const efiCardLabel = newCreditCardEfi.closest('.efi-card-method-label');
            if (efiCardLabel && gatewayConfig.efi_card) {
                efiCardLabel.addEventListener('click', function(e) {
                    // Se clicar no label (mas não no checkbox), clicar no checkbox
                    if (e.target !== newCreditCardEfi && !newCreditCardEfi.contains(e.target)) {
                        e.preventDefault();
                        e.stopPropagation();
                        if (!newCreditCardEfi.disabled) {
                            newCreditCardEfi.checked = !newCreditCardEfi.checked;
                            newCreditCardEfi.dispatchEvent(new Event('change', { bubbles: true }));
                        }
                    }
                });
            }
        }
        
        if (creditCardAsaas) {
            // Remover listener antigo se existir
            creditCardAsaas.replaceWith(creditCardAsaas.cloneNode(true));
            const newCreditCardAsaas = document.querySelector('input[name="payment_credit_card_asaas"]:not([type="hidden"])');
            
            // Garantir que está habilitado se as credenciais estão configuradas
            if (gatewayConfig.asaas && newCreditCardAsaas) {
                newCreditCardAsaas.disabled = false;
                newCreditCardAsaas.style.pointerEvents = 'auto';
                newCreditCardAsaas.style.cursor = 'pointer';
            }
            
            newCreditCardAsaas.addEventListener('change', function() {
                const hiddenInput = document.querySelector('input[name="payment_credit_card_asaas"][type="hidden"]');
                if (hiddenInput) {
                    hiddenInput.value = this.checked ? '1' : '0';
                }
                enforceSingleCreditCard();
                updateSummary();
            });
        }
        
        if (creditCardApplyfy) {
            // Remover listener antigo se existir
            creditCardApplyfy.replaceWith(creditCardApplyfy.cloneNode(true));
            const newCreditCardApplyfy = document.querySelector('input[name="payment_credit_card_applyfy"]:not([type="hidden"])');
            
            // Garantir que está habilitado se as credenciais estão configuradas
            if (gatewayConfig.applyfy && newCreditCardApplyfy) {
                newCreditCardApplyfy.disabled = false;
                newCreditCardApplyfy.style.pointerEvents = 'auto';
                newCreditCardApplyfy.style.cursor = 'pointer';
            }
            
            newCreditCardApplyfy.addEventListener('change', function() {
                const hiddenInput = document.querySelector('input[name="payment_credit_card_applyfy"][type="hidden"]');
                if (hiddenInput) {
                    hiddenInput.value = this.checked ? '1' : '0';
                }
                enforceSingleCreditCard();
                updateSummary();
            });
        }
    }
    
    // Chamar função para adicionar listeners
    setupCreditCardListeners();
    
    // Listeners para outros checkboxes
    document.querySelectorAll('input[type="checkbox"]').forEach(checkbox => {
        if (            checkbox.name !== 'payment_pix_pushinpay' && checkbox.name !== 'payment_pix_efi' && checkbox.name !== 'payment_pix_enabled' && 
            checkbox.name !== 'payment_credit_card_mercadopago' && checkbox.name !== 'payment_credit_card_hypercash' && checkbox.name !== 'payment_credit_card_efi') {
            checkbox.addEventListener('change', () => {
                // Atualizar campo hidden correspondente
                const hiddenInput = document.querySelector(`input[name="${checkbox.name}"][type="hidden"]`);
                if (hiddenInput) {
                    hiddenInput.value = checkbox.checked ? '1' : '0';
                }
                updateSummary();
            });
        }
    });
    
    // Atualizar campos hidden dos métodos de pagamento (apenas checkboxes visíveis, não os hidden)
    document.querySelectorAll('input[type="checkbox"][name^="payment_"]:not([type="hidden"])').forEach(checkbox => {
        const hiddenInput = document.querySelector(`input[name="${checkbox.name}"][type="hidden"]`);
        if (hiddenInput) {
            hiddenInput.value = checkbox.checked ? '1' : '0';
        }
    });
    
    enforceSinglePix();
    enforceSingleCreditCard();
    setupCreditCardListeners();
    
    // Adicionar listener no formulário para garantir atualização antes do submit
    const form = document.querySelector('form[method="post"]');
    if (form) {
        form.addEventListener('submit', function(e) {
            // Forçar atualização de todos os campos hidden antes do submit
            if (typeof window.forceUpdateAllHiddenFields === 'function') {
                window.forceUpdateAllHiddenFields();
            }
            
            // Garantir que os campos hidden dos checkboxes de Pix estejam atualizados
            const pixPP = document.querySelector('input[name="payment_pix_pushinpay"]:not([type="hidden"])');
            const pixEfi = document.querySelector('input[name="payment_pix_efi"]:not([type="hidden"])');
            const pixMP = document.querySelector('input[name="payment_pix_enabled"]:not([type="hidden"])');
            const pixAsaas = document.querySelector('input[name="payment_pix_asaas"]:not([type="hidden"])');
            const pixApplyfy = document.querySelector('input[name="payment_pix_applyfy"]:not([type="hidden"])');
            
            if (pixPP) {
                const hiddenPP = document.querySelector('input[name="payment_pix_pushinpay"][type="hidden"]');
                if (hiddenPP) {
                    hiddenPP.value = pixPP.checked ? '1' : '0';
                }
            }
            
            if (pixEfi) {
                const hiddenEfi = document.querySelector('input[name="payment_pix_efi"][type="hidden"]');
                if (hiddenEfi) {
                    hiddenEfi.value = pixEfi.checked ? '1' : '0';
                }
            }
            
            if (pixMP) {
                const hiddenMP = document.querySelector('input[name="payment_pix_enabled"][type="hidden"]');
                if (hiddenMP) {
                    hiddenMP.value = pixMP.checked ? '1' : '0';
                }
            }
            
            if (pixAsaas) {
                const hiddenAsaas = document.querySelector('input[name="payment_pix_asaas"][type="hidden"]');
                if (hiddenAsaas) {
                    hiddenAsaas.value = pixAsaas.checked ? '1' : '0';
                }
            }
            
            if (pixApplyfy) {
                const hiddenApplyfy = document.querySelector('input[name="payment_pix_applyfy"][type="hidden"]');
                if (hiddenApplyfy) {
                    hiddenApplyfy.value = pixApplyfy.checked ? '1' : '0';
                }
            }
            
        });
    }
});
</script>

