<?php
/**
 * Página de Configuração de Gateways - Admin (para checkout de planos)
 */

$mensagem = '';
$msg_type = '';

// Buscar configurações atuais
$gateways_config = [];
try {
    $stmt = $pdo->query("SELECT * FROM saas_admin_gateways");
    $gateways_config = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Tabela pode não existir ainda
    error_log("Erro ao buscar gateways admin: " . $e->getMessage());
}

// Buscar preferências de gateway por método de pagamento
$payment_methods_config = [];
try {
    $stmt = $pdo->query("SELECT * FROM saas_payment_methods");
    $payment_methods_config = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Erro ao buscar preferências de métodos: " . $e->getMessage());
}

// Organizar por gateway
$gateways_by_name = [];
foreach ($gateways_config as $gw) {
    $gateways_by_name[$gw['gateway']] = $gw;
}

// Organizar preferências por método
$preferences = [];
foreach ($payment_methods_config as $pm) {
    $preferences[$pm['payment_method']] = $pm['gateway'];
}

// Salvar preferências de gateway por método
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['salvar_preferencias'])) {
    try {
        $pix_gateway = $_POST['pix_gateway'] ?? '';
        $credit_card_gateway = $_POST['credit_card_gateway'] ?? '';

        // Salvar preferência Pix
        if (!empty($pix_gateway)) {
            $stmt = $pdo->prepare("
                INSERT INTO saas_payment_methods (payment_method, gateway) 
                VALUES ('pix', ?)
                ON DUPLICATE KEY UPDATE gateway = ?, updated_at = CURRENT_TIMESTAMP
            ");
            $stmt->execute([$pix_gateway, $pix_gateway]);
        }

        // Salvar preferência Cartão
        if (!empty($credit_card_gateway)) {
            $stmt = $pdo->prepare("
                INSERT INTO saas_payment_methods (payment_method, gateway) 
                VALUES ('credit_card', ?)
                ON DUPLICATE KEY UPDATE gateway = ?, updated_at = CURRENT_TIMESTAMP
            ");
            $stmt->execute([$credit_card_gateway, $credit_card_gateway]);
        }

        $mensagem = '<div class="bg-green-900/20 border border-green-500 text-green-300 px-4 py-3 rounded relative mb-4" role="alert">Preferências salvas com sucesso!</div>';

        // Recarregar preferências
        $stmt = $pdo->query("SELECT * FROM saas_payment_methods");
        $payment_methods_config = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $preferences = [];
        foreach ($payment_methods_config as $pm) {
            $preferences[$pm['payment_method']] = $pm['gateway'];
        }
    } catch (PDOException $e) {
        $mensagem = '<div class="bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded relative mb-4" role="alert">Erro ao salvar preferências: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
}

// Salvar configurações
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['salvar_gateway'])) {
    $gateway_name = $_POST['gateway'] ?? '';

    if (empty($gateway_name)) {
        $mensagem = '<div class="bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded relative mb-4" role="alert">Gateway não especificado.</div>';
    } else {
        try {
            // Preparar dados baseado no gateway
            $data = ['gateway' => $gateway_name];

            if ($gateway_name === 'mercadopago') {
                $data['mp_access_token'] = $_POST['mp_access_token'] ?? '';
                $data['mp_public_key'] = $_POST['mp_public_key'] ?? '';
            } elseif ($gateway_name === 'efi') {
                $data['efi_client_id'] = $_POST['efi_client_id'] ?? '';
                $data['efi_client_secret'] = $_POST['efi_client_secret'] ?? '';
                $data['efi_pix_key'] = $_POST['efi_pix_key'] ?? '';
                $data['efi_payee_code'] = $_POST['efi_payee_code'] ?? '';

                // Processar upload de certificado
                if (isset($_FILES['efi_certificate']) && $_FILES['efi_certificate']['error'] === UPLOAD_ERR_OK) {
                    $cert_file = $_FILES['efi_certificate'];
                    $cert_ext = strtolower(pathinfo($cert_file['name'], PATHINFO_EXTENSION));

                    if ($cert_ext === 'p12') {
                        $cert_dir = __DIR__ . '/../../uploads/certificados/';
                        if (!is_dir($cert_dir)) {
                            mkdir($cert_dir, 0755, true);
                        }

                        $cert_filename = 'saas_admin_efi_' . time() . '.p12';
                        $cert_path = $cert_dir . $cert_filename;

                        if (move_uploaded_file($cert_file['tmp_name'], $cert_path)) {
                            $data['efi_certificate_path'] = 'uploads/certificados/' . $cert_filename;
                        }
                    }
                } else {
                    // Manter certificado existente se não houver novo upload
                    if (isset($gateways_by_name['efi']['efi_certificate_path'])) {
                        $data['efi_certificate_path'] = $gateways_by_name['efi']['efi_certificate_path'];
                    }
                }
            } elseif ($gateway_name === 'pushinpay') {
                $data['pushinpay_token'] = $_POST['pushinpay_token'] ?? '';
            } elseif ($gateway_name === 'beehive') {
                $data['beehive_secret_key'] = $_POST['beehive_secret_key'] ?? '';
                $data['beehive_public_key'] = $_POST['beehive_public_key'] ?? '';
            } elseif ($gateway_name === 'hypercash') {
                $data['hypercash_secret_key'] = $_POST['hypercash_secret_key'] ?? '';
                $data['hypercash_public_key'] = $_POST['hypercash_public_key'] ?? '';
            } elseif ($gateway_name === 'stripe') {
                $data['stripe_public_key'] = $_POST['stripe_public_key'] ?? '';
                $data['stripe_secret_key'] = $_POST['stripe_secret_key'] ?? '';
                $data['stripe_webhook_secret'] = $_POST['stripe_webhook_secret'] ?? '';
            }

            // Verificar se já existe
            $stmt = $pdo->prepare("SELECT id FROM saas_admin_gateways WHERE gateway = ?");
            $stmt->execute([$gateway_name]);
            $exists = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($exists) {
                // Update
                $fields = [];
                $values = [];
                foreach ($data as $key => $value) {
                    if ($key !== 'gateway') {
                        $fields[] = "$key = ?";
                        $values[] = $value;
                    }
                }
                $values[] = $gateway_name;

                $sql = "UPDATE saas_admin_gateways SET " . implode(', ', $fields) . " WHERE gateway = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($values);
            } else {
                // Insert
                $fields = array_keys($data);
                $placeholders = array_fill(0, count($fields), '?');
                $sql = "INSERT INTO saas_admin_gateways (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $placeholders) . ")";
                $stmt = $pdo->prepare($sql);
                $stmt->execute(array_values($data));
            }

            $mensagem = '<div class="bg-green-900/20 border border-green-500 text-green-300 px-4 py-3 rounded relative mb-4" role="alert">Configurações salvas com sucesso!</div>';

            // Recarregar configurações
            $stmt = $pdo->query("SELECT * FROM saas_admin_gateways");
            $gateways_config = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $gateways_by_name = [];
            foreach ($gateways_config as $gw) {
                $gateways_by_name[$gw['gateway']] = $gw;
            }
        } catch (PDOException $e) {
            $mensagem = '<div class="bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded relative mb-4" role="alert">Erro ao salvar: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }
    }
}

// Verificar quais gateways estão configurados
$gateways_disponiveis = [];
if (!empty($gateways_by_name['efi']['efi_client_id']) && !empty($gateways_by_name['efi']['efi_client_secret']) && !empty($gateways_by_name['efi']['efi_certificate_path']) && !empty($gateways_by_name['efi']['efi_pix_key'])) {
    $gateways_disponiveis['pix'][] = ['value' => 'efi', 'label' => 'Efí'];
}
if (!empty($gateways_by_name['pushinpay']['pushinpay_token'])) {
    $gateways_disponiveis['pix'][] = ['value' => 'pushinpay', 'label' => 'PushinPay'];
}
if (!empty($gateways_by_name['mercadopago']['mp_access_token'])) {
    $gateways_disponiveis['pix'][] = ['value' => 'mercadopago', 'label' => 'Mercado Pago'];
}

if (!empty($gateways_by_name['hypercash']['hypercash_secret_key']) && !empty($gateways_by_name['hypercash']['hypercash_public_key'])) {
    $gateways_disponiveis['credit_card'][] = ['value' => 'hypercash', 'label' => 'Hypercash'];
}
if (!empty($gateways_by_name['beehive']['beehive_secret_key']) && !empty($gateways_by_name['beehive']['beehive_public_key'])) {
    $gateways_disponiveis['credit_card'][] = ['value' => 'beehive', 'label' => 'Beehive'];
}
if (!empty($gateways_by_name['efi']['efi_client_id']) && !empty($gateways_by_name['efi']['efi_client_secret']) && !empty($gateways_by_name['efi']['efi_certificate_path'])) {
    $gateways_disponiveis['credit_card'][] = ['value' => 'efi', 'label' => 'Efí'];
}
if (!empty($gateways_by_name['mercadopago']['mp_access_token']) && !empty($gateways_by_name['mercadopago']['mp_public_key'])) {
    $gateways_disponiveis['credit_card'][] = ['value' => 'mercadopago', 'label' => 'Mercado Pago'];
}
if (!empty($gateways_by_name['stripe']['stripe_secret_key']) && !empty($gateways_by_name['stripe']['stripe_public_key'])) {
    $gateways_disponiveis['credit_card'][] = ['value' => 'stripe', 'label' => 'Stripe'];
}
?>

<div class="container mx-auto">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-3xl font-bold text-white">Gateways de Pagamento - Admin</h1>
            <p class="text-gray-400 mt-1">Configure os gateways de pagamento para checkout de planos SaaS</p>
        </div>
    </div>

    <?php if ($mensagem): ?>
        <?php echo $mensagem; ?>
    <?php endif; ?>

    <div class="bg-dark-card p-6 rounded-lg shadow-md border mb-6" style="border-color: var(--accent-primary);">
        <p class="text-gray-300 mb-4">
            Configure os gateways de pagamento que serão usados no checkout de planos SaaS.
            Estas configurações são separadas das configurações dos infoprodutores.
        </p>
    </div>

    <!-- Seção de Preferências de Gateway por Método -->
    <div class="bg-dark-card p-6 rounded-lg shadow-md border mb-6" style="border-color: var(--accent-primary);">
        <h2 class="text-xl font-semibold text-white mb-4">Escolher Gateway por Método de Pagamento</h2>
        <p class="text-gray-400 mb-4 text-sm">Selecione qual gateway será usado para cada método de pagamento no
            checkout de planos.</p>

        <form method="POST">
            <input type="hidden" name="salvar_preferencias" value="1">

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Pix -->
                <div>
                    <label class="block text-gray-300 mb-2 font-semibold">Gateway para Pix</label>
                    <select name="pix_gateway"
                        class="w-full bg-dark-elevated border border-dark-border rounded-lg px-4 py-2 text-white">
                        <option value="">-- Selecione --</option>
                        <?php if (isset($gateways_disponiveis['pix'])): ?>
                            <?php foreach ($gateways_disponiveis['pix'] as $gw): ?>
                                <option value="<?php echo $gw['value']; ?>" <?php echo ($preferences['pix'] ?? '') === $gw['value'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($gw['label']); ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                    <?php if (empty($gateways_disponiveis['pix'])): ?>
                        <p class="text-yellow-400 text-xs mt-1">Configure pelo menos um gateway que suporte Pix abaixo.</p>
                    <?php endif; ?>
                </div>

                <!-- Cartão de Crédito -->
                <div>
                    <label class="block text-gray-300 mb-2 font-semibold">Gateway para Cartão de Crédito</label>
                    <select name="credit_card_gateway"
                        class="w-full bg-dark-elevated border border-dark-border rounded-lg px-4 py-2 text-white">
                        <option value="">-- Selecione --</option>
                        <?php if (isset($gateways_disponiveis['credit_card'])): ?>
                            <?php foreach ($gateways_disponiveis['credit_card'] as $gw): ?>
                                <option value="<?php echo $gw['value']; ?>" <?php echo ($preferences['credit_card'] ?? '') === $gw['value'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($gw['label']); ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                    <?php if (empty($gateways_disponiveis['credit_card'])): ?>
                        <p class="text-yellow-400 text-xs mt-1">Configure pelo menos um gateway que suporte Cartão abaixo.
                        </p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="mt-6">
                <button type="submit"
                    class="px-6 py-2 bg-primary hover:bg-primary/80 text-white rounded-lg font-semibold">
                    Salvar Preferências
                </button>
            </div>
        </form>
    </div>

    <!-- Tabs para cada gateway -->
    <div class="bg-dark-card p-6 rounded-lg shadow-md border" style="border-color: var(--accent-primary);">
        <h2 class="text-xl font-semibold text-white mb-4">Configurar Credenciais dos Gateways</h2>

        <div class="flex flex-wrap gap-2 mb-6 border-b border-dark-border pb-4">
            <button onclick="showGatewayTab('mercadopago')" id="tab-mercadopago"
                class="px-4 py-2 rounded-lg font-semibold bg-primary text-white">
                Mercado Pago
            </button>
            <button onclick="showGatewayTab('efi')" id="tab-efi"
                class="px-4 py-2 rounded-lg font-semibold text-gray-400 hover:text-white">
                Efí (Pix)
            </button>
            <button onclick="showGatewayTab('pushinpay')" id="tab-pushinpay"
                class="px-4 py-2 rounded-lg font-semibold text-gray-400 hover:text-white">
                PushinPay
            </button>
            <button onclick="showGatewayTab('beehive')" id="tab-beehive"
                class="px-4 py-2 rounded-lg font-semibold text-gray-400 hover:text-white">
                Beehive
            </button>
            <button onclick="showGatewayTab('hypercash')" id="tab-hypercash"
                class="px-4 py-2 rounded-lg font-semibold text-gray-400 hover:text-white">
                Hypercash
            </button>
            <button onclick="showGatewayTab('stripe')" id="tab-stripe"
                class="px-4 py-2 rounded-lg font-semibold text-gray-400 hover:text-white">
                Stripe
            </button>
        </div>

        <!-- Mercado Pago -->
        <div id="gateway-mercadopago" class="gateway-tab">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="gateway" value="mercadopago">
                <input type="hidden" name="salvar_gateway" value="1">

                <div class="space-y-4">
                    <div>
                        <label class="block text-gray-300 mb-2">Access Token *</label>
                        <input type="text" name="mp_access_token"
                            value="<?php echo htmlspecialchars($gateways_by_name['mercadopago']['mp_access_token'] ?? ''); ?>"
                            class="w-full bg-dark-elevated border border-dark-border rounded-lg px-4 py-2 text-white"
                            placeholder="APP_USR-...">
                        <p class="text-gray-400 text-xs mt-1">Suporta: Pix, Cartão de Crédito</p>
                    </div>
                    <div>
                        <label class="block text-gray-300 mb-2">Public Key *</label>
                        <input type="text" name="mp_public_key"
                            value="<?php echo htmlspecialchars($gateways_by_name['mercadopago']['mp_public_key'] ?? ''); ?>"
                            class="w-full bg-dark-elevated border border-dark-border rounded-lg px-4 py-2 text-white"
                            placeholder="APP_USR-...">
                        <p class="text-gray-400 text-xs mt-1">Necessário para pagamentos com cartão</p>
                    </div>
                    <button type="submit"
                        class="px-6 py-2 bg-primary hover:bg-primary/80 text-white rounded-lg font-semibold">
                        Salvar Configurações
                    </button>
                </div>
            </form>
        </div>

        <!-- Efí -->
        <div id="gateway-efi" class="gateway-tab hidden">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="gateway" value="efi">
                <input type="hidden" name="salvar_gateway" value="1">

                <div class="space-y-4">
                    <div>
                        <label class="block text-gray-300 mb-2">Client ID *</label>
                        <input type="text" name="efi_client_id"
                            value="<?php echo htmlspecialchars($gateways_by_name['efi']['efi_client_id'] ?? ''); ?>"
                            class="w-full bg-dark-elevated border border-dark-border rounded-lg px-4 py-2 text-white"
                            placeholder="Client ID da aplicação Efí">
                        <p class="text-gray-400 text-xs mt-1">Suporta: Pix, Cartão de Crédito</p>
                    </div>
                    <div>
                        <label class="block text-gray-300 mb-2">Client Secret *</label>
                        <input type="text" name="efi_client_secret"
                            value="<?php echo htmlspecialchars($gateways_by_name['efi']['efi_client_secret'] ?? ''); ?>"
                            class="w-full bg-dark-elevated border border-dark-border rounded-lg px-4 py-2 text-white"
                            placeholder="Client Secret da aplicação Efí">
                    </div>
                    <div>
                        <label class="block text-gray-300 mb-2">Chave Pix *</label>
                        <input type="text" name="efi_pix_key"
                            value="<?php echo htmlspecialchars($gateways_by_name['efi']['efi_pix_key'] ?? ''); ?>"
                            class="w-full bg-dark-elevated border border-dark-border rounded-lg px-4 py-2 text-white"
                            placeholder="Chave Pix (CPF, CNPJ, E-mail, Telefone ou Chave Aleatória)">
                    </div>
                    <div>
                        <label class="block text-gray-300 mb-2">ID da Conta (Payee Code) *</label>
                        <input type="text" name="efi_payee_code"
                            value="<?php echo htmlspecialchars($gateways_by_name['efi']['efi_payee_code'] ?? ''); ?>"
                            class="w-full bg-dark-elevated border border-dark-border rounded-lg px-4 py-2 text-white"
                            placeholder="Seu Identificador de conta (payee_code) da Efí">
                        <p class="text-gray-400 text-xs mt-1">Identificador único da sua conta Efí</p>
                    </div>
                    <div>
                        <label class="block text-gray-300 mb-2">Certificado P12 *</label>
                        <input type="file" name="efi_certificate" accept=".p12"
                            class="w-full bg-dark-elevated border border-dark-border rounded-lg px-4 py-2 text-white">
                        <?php if (!empty($gateways_by_name['efi']['efi_certificate_path'])): ?>
                            <p class="text-sm text-gray-400 mt-1">Certificado atual:
                                <?php echo htmlspecialchars($gateways_by_name['efi']['efi_certificate_path']); ?></p>
                        <?php endif; ?>
                        <p class="text-gray-400 text-xs mt-1">Certificado P12 fornecido pela Efí</p>
                    </div>
                    <button type="submit"
                        class="px-6 py-2 bg-primary hover:bg-primary/80 text-white rounded-lg font-semibold">
                        Salvar Configurações
                    </button>
                </div>
            </form>
        </div>

        <!-- PushinPay -->
        <div id="gateway-pushinpay" class="gateway-tab hidden">
            <form method="POST">
                <input type="hidden" name="gateway" value="pushinpay">
                <input type="hidden" name="salvar_gateway" value="1">

                <div class="space-y-4">
                    <div>
                        <label class="block text-gray-300 mb-2">Token *</label>
                        <input type="text" name="pushinpay_token"
                            value="<?php echo htmlspecialchars($gateways_by_name['pushinpay']['pushinpay_token'] ?? ''); ?>"
                            class="w-full bg-dark-elevated border border-dark-border rounded-lg px-4 py-2 text-white"
                            placeholder="Token de autenticação PushinPay">
                        <p class="text-gray-400 text-xs mt-1">Suporta: Pix</p>
                    </div>
                    <button type="submit"
                        class="px-6 py-2 bg-primary hover:bg-primary/80 text-white rounded-lg font-semibold">
                        Salvar Configurações
                    </button>
                </div>
            </form>
        </div>

        <!-- Beehive -->
        <div id="gateway-beehive" class="gateway-tab hidden">
            <form method="POST">
                <input type="hidden" name="gateway" value="beehive">
                <input type="hidden" name="salvar_gateway" value="1">

                <div class="space-y-4">
                    <div>
                        <label class="block text-gray-300 mb-2">Secret Key *</label>
                        <input type="text" name="beehive_secret_key"
                            value="<?php echo htmlspecialchars($gateways_by_name['beehive']['beehive_secret_key'] ?? ''); ?>"
                            class="w-full bg-dark-elevated border border-dark-border rounded-lg px-4 py-2 text-white"
                            placeholder="sk_live_... ou sk_test_...">
                        <p class="text-gray-400 text-xs mt-1">Suporta: Cartão de Crédito</p>
                    </div>
                    <div>
                        <label class="block text-gray-300 mb-2">Public Key *</label>
                        <input type="text" name="beehive_public_key"
                            value="<?php echo htmlspecialchars($gateways_by_name['beehive']['beehive_public_key'] ?? ''); ?>"
                            class="w-full bg-dark-elevated border border-dark-border rounded-lg px-4 py-2 text-white"
                            placeholder="pk_live_... ou pk_test_...">
                    </div>
                    <button type="submit"
                        class="px-6 py-2 bg-primary hover:bg-primary/80 text-white rounded-lg font-semibold">
                        Salvar Configurações
                    </button>
                </div>
            </form>
        </div>

        <!-- Hypercash -->
        <div id="gateway-hypercash" class="gateway-tab hidden">
            <form method="POST">
                <input type="hidden" name="gateway" value="hypercash">
                <input type="hidden" name="salvar_gateway" value="1">

                <div class="space-y-4">
                    <div>
                        <label class="block text-gray-300 mb-2">Secret Key *</label>
                        <input type="text" name="hypercash_secret_key"
                            value="<?php echo htmlspecialchars($gateways_by_name['hypercash']['hypercash_secret_key'] ?? ''); ?>"
                            class="w-full bg-dark-elevated border border-dark-border rounded-lg px-4 py-2 text-white"
                            placeholder="Secret Key da Hypercash">
                        <p class="text-gray-400 text-xs mt-1">Suporta: Cartão de Crédito</p>
                    </div>
                    <div>
                        <label class="block text-gray-300 mb-2">Public Key *</label>
                        <input type="text" name="hypercash_public_key"
                            value="<?php echo htmlspecialchars($gateways_by_name['hypercash']['hypercash_public_key'] ?? ''); ?>"
                            class="w-full bg-dark-elevated border border-dark-border rounded-lg px-4 py-2 text-white"
                            placeholder="Public Key da Hypercash">
                    </div>
                    <button type="submit"
                        class="px-6 py-2 bg-primary hover:bg-primary/80 text-white rounded-lg font-semibold">
                        Salvar Configurações
                    </button>
                </div>
            </form>
        </div>

        <!-- Stripe -->
        <div id="gateway-stripe" class="gateway-tab hidden">
            <form method="POST">
                <input type="hidden" name="gateway" value="stripe">
                <input type="hidden" name="salvar_gateway" value="1">

                <div class="space-y-4">
                    <div>
                        <label class="block text-gray-300 mb-2">Public Key *</label>
                        <input type="text" name="stripe_public_key"
                            value="<?php echo htmlspecialchars($gateways_by_name['stripe']['stripe_public_key'] ?? ''); ?>"
                            class="w-full bg-dark-elevated border border-dark-border rounded-lg px-4 py-2 text-white"
                            placeholder="pk_live_... ou pk_test_...">
                        <p class="text-gray-400 text-xs mt-1">Chave pública da Stripe</p>
                    </div>
                    <div>
                        <label class="block text-gray-300 mb-2">Secret Key *</label>
                        <input type="text" name="stripe_secret_key"
                            value="<?php echo htmlspecialchars($gateways_by_name['stripe']['stripe_secret_key'] ?? ''); ?>"
                            class="w-full bg-dark-elevated border border-dark-border rounded-lg px-4 py-2 text-white"
                            placeholder="sk_live_... ou sk_test_...">
                        <p class="text-gray-400 text-xs mt-1">Chave secreta da Stripe</p>
                    </div>
                    <div>
                        <label class="block text-gray-300 mb-2">Webhook Secret *</label>
                        <input type="text" name="stripe_webhook_secret"
                            value="<?php echo htmlspecialchars($gateways_by_name['stripe']['stripe_webhook_secret'] ?? ''); ?>"
                            class="w-full bg-dark-elevated border border-dark-border rounded-lg px-4 py-2 text-white"
                            placeholder="whsec_...">
                        <p class="text-gray-400 text-xs mt-1">Segredo do Webhook para validar notificações</p>
                    </div>
                    <button type="submit"
                        class="px-6 py-2 bg-primary hover:bg-primary/80 text-white rounded-lg font-semibold">
                        Salvar Configurações
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    function showGatewayTab(gateway) {
        // Esconder todas as tabs
        document.querySelectorAll('.gateway-tab').forEach(tab => {
            tab.classList.add('hidden');
        });

        // Remover estilo ativo de todos os botões
        document.querySelectorAll('[id^="tab-"]').forEach(btn => {
            btn.classList.remove('bg-primary', 'text-white');
            btn.classList.add('text-gray-400');
        });

        // Mostrar tab selecionada
        document.getElementById('gateway-' + gateway).classList.remove('hidden');

        // Ativar botão
        const btn = document.getElementById('tab-' + gateway);
        btn.classList.add('bg-primary', 'text-white');
        btn.classList.remove('text-gray-400');
    }

    // Mostrar primeira tab por padrão
    showGatewayTab('mercadopago');
</script>