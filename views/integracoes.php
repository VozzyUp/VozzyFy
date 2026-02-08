<?php
// Este arquivo é incluído a partir do index.php,
// então a verificação de login e a conexão com o banco ($pdo) já existem.

// Obter o ID do usuário logado
$usuario_id_logado = $_SESSION['id'] ?? 0;

// Se por algum motivo o ID do usuário não estiver definido, redireciona para o login
if ($usuario_id_logado === 0) {
    header("location: /login");
    exit;
}

$mensagem = '';
$msg_type = '';

// Pega a mensagem da sessão, se houver, e depois limpa
if (isset($_SESSION['flash_message'])) {
    $mensagem = $_SESSION['flash_message'];
    unset($_SESSION['flash_message']);
}

// Fetch current user data para gateways
// Verificar se as colunas do Efí, Hypercash e Stripe existem antes de tentar buscar
try {
    $stmt_user_data = $pdo->prepare("SELECT mp_public_key, mp_access_token, pushinpay_token, efi_client_id, efi_client_secret, efi_certificate_path, efi_pix_key, efi_payee_code, hypercash_secret_key, hypercash_public_key, stripe_public_key, stripe_secret_key, stripe_webhook_secret, asaas_api_key, asaas_environment, applyfy_public_key, applyfy_secret_key, spacepag_public_key, spacepag_secret_key FROM usuarios WHERE id = ?");
    $stmt_user_data->execute([$usuario_id_logado]);
    $user_data_fetched = $stmt_user_data->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Se as colunas não existem, buscar sem elas e criar valores padrão
    if (strpos($e->getMessage(), 'efi_client_id') !== false || strpos($e->getMessage(), 'hypercash_secret_key') !== false || strpos($e->getMessage(), 'stripe_public_key') !== false || strpos($e->getMessage(), 'asaas_api_key') !== false || strpos($e->getMessage(), 'applyfy_public_key') !== false || strpos($e->getMessage(), 'spacepag_public_key') !== false || strpos($e->getMessage(), 'Column not found') !== false) {
        error_log("Colunas de gateways não encontradas. Executando migrations...");
        // Tentar executar as migrations
        try {
            // Migration Efí
            if (file_exists(__DIR__ . '/../gateways/efi_migration.sql')) {
                $migration_sql = file_get_contents(__DIR__ . '/../gateways/efi_migration.sql');
                $migration_sql = preg_replace('/--.*$/m', '', $migration_sql);
                $migration_sql = preg_replace('/\/\*.*?\*\//s', '', $migration_sql);
                $statements = array_filter(array_map('trim', explode(';', $migration_sql)));
                foreach ($statements as $statement) {
                    if (!empty($statement)) {
                        try {
                            $pdo->exec($statement);
                        } catch (PDOException $e) {
                        }
                    }
                }
            }
            // Migration Hypercash
            if (file_exists(__DIR__ . '/../gateways/hypercash_migration.sql')) {
                $migration_sql = file_get_contents(__DIR__ . '/../gateways/hypercash_migration.sql');
                $migration_sql = preg_replace('/--.*$/m', '', $migration_sql);
                $migration_sql = preg_replace('/\/\*.*?\*\//s', '', $migration_sql);
                $statements = array_filter(array_map('trim', explode(';', $migration_sql)));
                foreach ($statements as $statement) {
                    if (!empty($statement)) {
                        try {
                            $pdo->exec($statement);
                        } catch (PDOException $e) {
                        }
                    }
                }
            }
            // Migration Stripe
            if (file_exists(__DIR__ . '/../gateways/stripe_migration.sql')) {
                $migration_sql = file_get_contents(__DIR__ . '/../gateways/stripe_migration.sql');
                $migration_sql = preg_replace('/--.*$/m', '', $migration_sql);
                $migration_sql = preg_replace('/\/\*.*?\*\//s', '', $migration_sql);
                $statements = array_filter(array_map('trim', explode(';', $migration_sql)));
                foreach ($statements as $statement) {
                    if (!empty($statement)) {
                        try {
                            $pdo->exec($statement);
                        } catch (PDOException $e) {
                        }
                    }
                }
            }
            // Migration Asaas
            if (file_exists(__DIR__ . '/../gateways/asaas_migration.sql')) {
                $migration_sql = file_get_contents(__DIR__ . '/../gateways/asaas_migration.sql');
                $migration_sql = preg_replace('/--.*$/m', '', $migration_sql);
                $migration_sql = preg_replace('/\/\*.*?\*\//s', '', $migration_sql);
                $statements = array_filter(array_map('trim', explode(';', $migration_sql)));
                foreach ($statements as $statement) {
                    if (!empty($statement)) {
                        try {
                            $pdo->exec($statement);
                        } catch (PDOException $e) {
                        }
                    }
                }
            }
            // Migration Applyfy
            if (file_exists(__DIR__ . '/../SQL Atualizações/adicionar_applyfy_gateway.sql')) {
                $migration_sql = file_get_contents(__DIR__ . '/../SQL Atualizações/adicionar_applyfy_gateway.sql');
                $migration_sql = preg_replace('/--.*$/m', '', $migration_sql);
                $migration_sql = preg_replace('/\/\*.*?\*\//s', '', $migration_sql);
                $statements = array_filter(array_map('trim', explode(';', $migration_sql)));
                foreach ($statements as $statement) {
                    if (!empty($statement)) {
                        try {
                            $pdo->exec($statement);
                        } catch (PDOException $e) {
                        }
                    }
                }
            }
            // Tentar novamente
            $stmt_user_data = $pdo->prepare("SELECT mp_public_key, mp_access_token, pushinpay_token, efi_client_id, efi_client_secret, efi_certificate_path, efi_pix_key, efi_payee_code, hypercash_secret_key, hypercash_public_key, stripe_public_key, stripe_secret_key, stripe_webhook_secret, asaas_api_key, asaas_environment, applyfy_public_key, applyfy_secret_key, spacepag_public_key, spacepag_secret_key FROM usuarios WHERE id = ?");
            $stmt_user_data->execute([$usuario_id_logado]);
            $user_data_fetched = $stmt_user_data->fetch(PDO::FETCH_ASSOC);
        } catch (Exception $e2) {
            // Se ainda falhar, buscar sem as colunas dos gateways
            error_log("Erro ao executar migrations: " . $e2->getMessage());
            $stmt_user_data = $pdo->prepare("SELECT mp_public_key, mp_access_token, pushinpay_token FROM usuarios WHERE id = ?");
            $stmt_user_data->execute([$usuario_id_logado]);
            $user_data_fetched = $stmt_user_data->fetch(PDO::FETCH_ASSOC);
            // Adicionar valores padrão
            $user_data_fetched['efi_client_id'] = null;
            $user_data_fetched['efi_client_secret'] = null;
            $user_data_fetched['efi_certificate_path'] = null;
            $user_data_fetched['efi_pix_key'] = null;
            $user_data_fetched['efi_payee_code'] = null;
            $user_data_fetched['hypercash_secret_key'] = null;
            $user_data_fetched['hypercash_public_key'] = null;
            $user_data_fetched['stripe_public_key'] = null;
            $user_data_fetched['stripe_secret_key'] = null;
            $user_data_fetched['stripe_webhook_secret'] = null;
            $user_data_fetched['asaas_api_key'] = null;
            $user_data_fetched['asaas_environment'] = 'sandbox';
            $user_data_fetched['applyfy_public_key'] = null;
            $user_data_fetched['applyfy_secret_key'] = null;
            $user_data_fetched['spacepag_public_key'] = null;
            $user_data_fetched['spacepag_secret_key'] = null;
        }
    } else {
        throw $e; // Re-lançar se for outro erro
    }
}

$mercado_pago_public_key = $user_data_fetched['mp_public_key'] ?? '';
$mercado_pago_access_token = $user_data_fetched['mp_access_token'] ?? '';
$pushinpay_token = $user_data_fetched['pushinpay_token'] ?? '';
$efi_client_id = $user_data_fetched['efi_client_id'] ?? '';
$efi_client_secret = $user_data_fetched['efi_client_secret'] ?? '';
$efi_certificate_path = $user_data_fetched['efi_certificate_path'] ?? '';
$efi_pix_key = $user_data_fetched['efi_pix_key'] ?? '';
$efi_payee_code = $user_data_fetched['efi_payee_code'] ?? '';
$hypercash_secret_key = $user_data_fetched['hypercash_secret_key'] ?? '';
$hypercash_public_key = $user_data_fetched['hypercash_public_key'] ?? '';
$stripe_public_key = $user_data_fetched['stripe_public_key'] ?? '';
$stripe_secret_key = $user_data_fetched['stripe_secret_key'] ?? '';
$stripe_webhook_secret = $user_data_fetched['stripe_webhook_secret'] ?? '';
$asaas_api_key = $user_data_fetched['asaas_api_key'] ?? '';
$asaas_environment = $user_data_fetched['asaas_environment'] ?? 'sandbox';
$applyfy_public_key = $user_data_fetched['applyfy_public_key'] ?? '';
$applyfy_secret_key = $user_data_fetched['applyfy_secret_key'] ?? '';

$mp_configured = !empty($mercado_pago_access_token);
$pp_configured = !empty($pushinpay_token);
$efi_configured = !empty($efi_client_id) && !empty($efi_client_secret) && !empty($efi_certificate_path) && !empty($efi_pix_key);
$hypercash_configured = !empty($hypercash_secret_key) && !empty($hypercash_public_key);
$stripe_configured = !empty($stripe_public_key) && !empty($stripe_secret_key);
$asaas_configured = !empty($asaas_api_key);
$applyfy_configured = !empty($applyfy_public_key) && !empty($applyfy_secret_key);
$spacepag_public_key = $user_data_fetched['spacepag_public_key'] ?? '';
$spacepag_secret_key = $user_data_fetched['spacepag_secret_key'] ?? '';
$spacepag_configured = !empty($spacepag_public_key) && !empty($spacepag_secret_key);

// --- URL DE WEBHOOK ---
$domainName = $_SERVER['HTTP_HOST'];
$scriptDir = dirname($_SERVER['PHP_SELF']);
$path = rtrim(str_replace('\\', '/', $scriptDir), '/');
$webhook_url = "https://" . $domainName . $path . '/notification.php';

// Salvar configurações de gateway
if (isset($_POST['salvar_gateways'])) {
    $public_key = $_POST['mercado_pago_public_key'] ?? '';
    $access_token = $_POST['mercado_pago_access_token'] ?? '';
    $pp_token = $_POST['pushinpay_token'] ?? '';
    $efi_client_id = $_POST['efi_client_id'] ?? '';
    $efi_client_secret = $_POST['efi_client_secret'] ?? '';
    $efi_pix_key = $_POST['efi_pix_key'] ?? '';
    $efi_payee_code = $_POST['efi_payee_code'] ?? '';
    $hypercash_secret_key = $_POST['hypercash_secret_key'] ?? '';
    $hypercash_public_key = $_POST['hypercash_public_key'] ?? '';
    $stripe_pk = $_POST['stripe_public_key'] ?? '';
    $stripe_sk = $_POST['stripe_secret_key'] ?? '';
    $stripe_wh = $_POST['stripe_webhook_secret'] ?? '';
    $asaas_api_key = $_POST['asaas_api_key'] ?? '';
    $asaas_environment = $_POST['asaas_environment'] ?? 'sandbox';
    $applyfy_public_key = $_POST['applyfy_public_key'] ?? '';
    $applyfy_secret_key = $_POST['applyfy_secret_key'] ?? '';
    $spacepag_public_key = $_POST['spacepag_public_key'] ?? '';
    $spacepag_secret_key = $_POST['spacepag_secret_key'] ?? '';

    // Verifica CSRF
    require_once __DIR__ . '/../helpers/security_helper.php';
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $mensagem = "Token CSRF inválido ou ausente.";
        $msg_type = 'error';
    } else {
        // Processar upload de certificado P12
        $certificate_path = $efi_certificate_path; // Manter o existente se não houver novo upload

        if (isset($_FILES['efi_certificate']) && $_FILES['efi_certificate']['error'] === UPLOAD_ERR_OK) {
            $cert_file = $_FILES['efi_certificate'];
            $cert_ext = strtolower(pathinfo($cert_file['name'], PATHINFO_EXTENSION));

            // Validação segura de certificado P12
            require_once __DIR__ . '/../helpers/security_helper.php';

            // Remover certificado antigo se existir
            if (!empty($efi_certificate_path)) {
                $old_cert_full_path = __DIR__ . '/../' . $efi_certificate_path;
                if (file_exists($old_cert_full_path)) {
                    @unlink($old_cert_full_path);
                }
            }

            $allowed_cert_types = ['application/x-pkcs12', 'application/pkcs12', 'application/octet-stream'];
            $allowed_cert_extensions = ['p12', 'pfx'];
            $max_size = 5 * 1024 * 1024; // 5MB

            $upload_result = validate_uploaded_file($cert_file, $allowed_cert_types, $allowed_cert_extensions, $max_size, 'uploads/certificados/', 'cert_efi');

            if ($upload_result['success']) {
                $certificate_path = $upload_result['file_path'];
                // Mensagem de sucesso será exibida após salvar no banco
            } else {
                $mensagem = htmlspecialchars($upload_result['error']);
                $msg_type = 'error';
            }
        }

        if (empty($mensagem)) { // Só salva se não houve erro de upload
            try {
                $stmt = $pdo->prepare("UPDATE usuarios SET mp_public_key = ?, mp_access_token = ?, pushinpay_token = ?, efi_client_id = ?, efi_client_secret = ?, efi_certificate_path = ?, efi_pix_key = ?, efi_payee_code = ?, hypercash_secret_key = ?, hypercash_public_key = ?, stripe_public_key = ?, stripe_secret_key = ?, stripe_webhook_secret = ?, asaas_api_key = ?, asaas_environment = ?, applyfy_public_key = ?, applyfy_secret_key = ?, spacepag_public_key = ?, spacepag_secret_key = ? WHERE id = ?");
                $stmt->execute([$public_key, $access_token, $pp_token, $efi_client_id, $efi_client_secret, $certificate_path, $efi_pix_key, $efi_payee_code, $hypercash_secret_key, $hypercash_public_key, $stripe_pk, $stripe_sk, $stripe_wh, $asaas_api_key, $asaas_environment, $applyfy_public_key, $applyfy_secret_key, $spacepag_public_key, $spacepag_secret_key, $usuario_id_logado]);

                // Mensagem de sucesso personalizada se certificado foi enviado
                if (isset($_FILES['efi_certificate']) && $_FILES['efi_certificate']['error'] === UPLOAD_ERR_OK && !empty($certificate_path) && $certificate_path !== $efi_certificate_path) {
                    $mensagem = "Configurações de gateway salvas com sucesso! Certificado P12 enviado e salvo.";
                } else {
                    $mensagem = "Configurações de gateway salvas com sucesso.";
                }
                $msg_type = 'success';

                // Recarrega os dados
                $stmt_user_data->execute([$usuario_id_logado]);
                $user_data_fetched = $stmt_user_data->fetch(PDO::FETCH_ASSOC);

                $mercado_pago_public_key = $user_data_fetched['mp_public_key'] ?? '';
                $mercado_pago_access_token = $user_data_fetched['mp_access_token'] ?? '';
                $pushinpay_token = $user_data_fetched['pushinpay_token'] ?? '';
                $efi_client_id = $user_data_fetched['efi_client_id'] ?? '';
                $efi_client_secret = $user_data_fetched['efi_client_secret'] ?? '';
                $efi_certificate_path = $user_data_fetched['efi_certificate_path'] ?? '';
                $efi_pix_key = $user_data_fetched['efi_pix_key'] ?? '';
                $efi_payee_code = $user_data_fetched['efi_payee_code'] ?? '';
                $hypercash_secret_key = $user_data_fetched['hypercash_secret_key'] ?? '';
                $hypercash_public_key = $user_data_fetched['hypercash_public_key'] ?? '';
                $stripe_public_key = $user_data_fetched['stripe_public_key'] ?? '';
                $stripe_secret_key = $user_data_fetched['stripe_secret_key'] ?? '';
                $stripe_webhook_secret = $user_data_fetched['stripe_webhook_secret'] ?? '';
                $asaas_api_key = $user_data_fetched['asaas_api_key'] ?? '';
                $asaas_environment = $user_data_fetched['asaas_environment'] ?? 'sandbox';
                $applyfy_public_key = $user_data_fetched['applyfy_public_key'] ?? '';
                $applyfy_secret_key = $user_data_fetched['applyfy_secret_key'] ?? '';
                $spacepag_public_key = $user_data_fetched['spacepag_public_key'] ?? '';
                $spacepag_secret_key = $user_data_fetched['spacepag_secret_key'] ?? '';

                $mp_configured = !empty($mercado_pago_access_token);
                $pp_configured = !empty($pushinpay_token);
                $efi_configured = !empty($efi_client_id) && !empty($efi_client_secret) && !empty($efi_certificate_path) && !empty($efi_pix_key);
                $hypercash_configured = !empty($hypercash_secret_key) && !empty($hypercash_public_key);
                $stripe_configured = !empty($stripe_public_key) && !empty($stripe_secret_key);
                $asaas_configured = !empty($asaas_api_key);
                $applyfy_configured = !empty($applyfy_public_key) && !empty($applyfy_secret_key);
                $spacepag_configured = !empty($spacepag_public_key) && !empty($spacepag_secret_key);

            } catch (PDOException $e) {
                $mensagem = "Erro ao salvar: " . $e->getMessage();
                $msg_type = 'error';
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="pt-br">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Integrações</title>
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap"
        rel="stylesheet">

    <style>
        body {
            font-family: 'Plus Jakarta Sans', sans-serif;
            background-color: #07090d;
            /* Dark base */
        }

        /* Animações e Efeitos */
        .card-hover {
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .card-hover:hover {
            transform: translateY(-4px);
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }

        .animate-fade-in {
            animation: fadeIn 0.4s ease-out forwards;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .selected-ring {
            ring-width: 2px;
            ring-color: var(--accent-primary);
            background-color: color-mix(in srgb, var(--accent-primary) 10%, transparent);
            border-color: var(--accent-primary);
        }

        /* Input Styles */
        .custom-input:focus-within {
            box-shadow: 0 0 0 4px color-mix(in srgb, var(--accent-primary) 10%, transparent);
            border-color: var(--accent-primary);
        }
    </style>
</head>

<body class="min-h-screen text-white pb-20">

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">

        <!-- Header -->
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-12 gap-4">
            <div>
                <h1 class="text-3xl md:text-4xl font-extrabold tracking-tight text-white">
                    Central de Integrações
                </h1>
                <p class="mt-2 text-gray-400 text-lg">Conecte sua plataforma a ferramentas externas e automatize seus
                    processos.</p>
            </div>
        </div>

        <!-- Mensagens Flutuantes -->
        <?php if (!empty($mensagem)): ?>
            <div id='toast-msg'
                class='fixed top-5 right-5 z-50 animate-fade-in flex items-center w-full max-w-xs p-4 text-gray-300 bg-dark-card rounded-lg shadow-xl border border-dark-border'
                role='alert'>
                <div class='inline-flex items-center justify-center flex-shrink-0 w-8 h-8 rounded-lg <?php echo ($msg_type == "success" ? "" : ($msg_type == "error" ? "text-red-400 bg-red-900/30" : "text-blue-400 bg-blue-900/30")); ?>'
                    <?php echo ($msg_type == "success" ? 'style="color: var(--accent-primary); background-color: color-mix(in srgb, var(--accent-primary) 30%, transparent);"' : ''); ?>>
                    <i data-lucide='<?php echo ($msg_type == "success" ? "check" : ($msg_type == "error" ? "alert-circle" : "info")); ?>'
                        class='w-5 h-5'></i>
                </div>
                <div class='ml-3 text-sm font-medium'><?php echo $mensagem; ?></div>
                <button type='button'
                    class='ml-auto -mx-1.5 -my-1.5 bg-dark-card text-gray-400 hover:text-gray-300 rounded-lg focus:ring-2 focus:ring-dark-border p-1.5 hover:bg-dark-elevated inline-flex h-8 w-8'
                    onclick='this.parentElement.remove()'>
                    <i data-lucide='x' class='w-4 h-4'></i>
                </button>
            </div>
        <?php endif; ?>

        <!-- Grid de Cards -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">

            <!-- Card Webhooks -->
            <a href="/index?pagina=integracoes_webhooks"
                class="card-hover group relative bg-dark-card border border-dark-border rounded-2xl p-8 flex flex-col justify-between h-full overflow-hidden cursor-pointer"
                onmouseover="this.style.borderColor='var(--accent-primary)'"
                onmouseout="this.style.borderColor='rgba(255,255,255,0.1)'">
                <!-- Background Decoration -->
                <div class="absolute top-0 right-0 -mt-8 -mr-8 w-32 h-32 rounded-full opacity-50 group-hover:scale-150 transition-transform duration-500"
                    style="background-color: color-mix(in srgb, var(--accent-primary) 10%, transparent);"></div>

                <div class="relative z-10">
                    <div class="flex items-center gap-5 mb-6">
                        <div class="h-16 w-16 rounded-2xl flex items-center justify-center shadow-sm transition-colors"
                            style="background-color: color-mix(in srgb, var(--accent-primary) 20%, transparent); border-color: color-mix(in srgb, var(--accent-primary) 30%, transparent);"
                            onmouseover="this.style.backgroundColor='color-mix(in srgb, var(--accent-primary) 30%, transparent)'"
                            onmouseout="this.style.backgroundColor='color-mix(in srgb, var(--accent-primary) 20%, transparent)'">
                            <img src="https://res.cloudinary.com/hevo/image/upload/v1636351137/hevo-learn/webhooks.png"
                                alt="Webhook" class="h-10 w-10 object-contain">
                        </div>
                        <div>
                            <h2 class="text-2xl font-bold text-white transition-colors"
                                onmouseover="this.style.color='var(--accent-primary)'"
                                onmouseout="this.style.color='white'">Webhooks</h2>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium mt-1"
                                style="background-color: color-mix(in srgb, var(--accent-primary) 20%, transparent); color: var(--accent-primary);">
                                Automação
                            </span>
                        </div>
                    </div>

                    <p class="text-gray-400 text-base leading-relaxed mb-8">
                        Envie dados de vendas em tempo real para outras plataformas como Zapier, Make.com ou seu próprio
                        sistema. Notifique eventos instantaneamente.
                    </p>
                </div>

                <div class="relative z-10 mt-auto pt-6 border-t border-dark-border">
                    <span class="flex items-center text-sm font-bold transition-colors"
                        style="color: var(--accent-primary);"
                        onmouseover="this.style.color='var(--accent-primary-hover)'"
                        onmouseout="this.style.color='var(--accent-primary)'">
                        Configurar Webhooks <i data-lucide="arrow-right"
                            class="w-4 h-4 ml-2 group-hover:translate-x-1 transition-transform"></i>
                    </span>
                </div>
            </a>

            <!-- Card UTMfy -->
            <a href="/index?pagina=integracoes_utmfy"
                class="card-hover group relative bg-dark-card border border-dark-border rounded-2xl p-8 flex flex-col justify-between h-full overflow-hidden cursor-pointer"
                onmouseover="this.style.borderColor='var(--accent-primary)'"
                onmouseout="this.style.borderColor='rgba(255,255,255,0.1)'">
                <!-- Background Decoration -->
                <div class="absolute top-0 right-0 -mt-8 -mr-8 w-32 h-32 rounded-full opacity-50 group-hover:scale-150 transition-transform duration-500"
                    style="background-color: color-mix(in srgb, var(--accent-primary) 10%, transparent);"></div>

                <div class="relative z-10">
                    <div class="flex items-center gap-5 mb-6">
                        <div class="h-16 w-16 rounded-2xl flex items-center justify-center shadow-sm transition-colors"
                            style="background-color: color-mix(in srgb, var(--accent-primary) 20%, transparent); border-color: color-mix(in srgb, var(--accent-primary) 30%, transparent);"
                            onmouseover="this.style.backgroundColor='color-mix(in srgb, var(--accent-primary) 30%, transparent)'"
                            onmouseout="this.style.backgroundColor='color-mix(in srgb, var(--accent-primary) 20%, transparent)'">
                            <img src="https://is1-ssl.mzstatic.com/image/thumb/Purple221/v4/a5/ca/21/a5ca2115-6efd-59cd-6724-475031a69400/AppIcon-1x_U007emarketing-0-8-0-85-220-0.png/434x0w.webp"
                                alt="UTMfy" class="h-10 w-10 object-contain rounded-md">
                        </div>
                        <div>
                            <h2 class="text-2xl font-bold text-white transition-colors"
                                onmouseover="this.style.color='var(--accent-primary)'"
                                onmouseout="this.style.color='white'">UTMfy</h2>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium mt-1"
                                style="background-color: color-mix(in srgb, var(--accent-primary) 20%, transparent); color: var(--accent-primary);">
                                Rastreamento
                            </span>
                        </div>
                    </div>

                    <p class="text-gray-400 text-base leading-relaxed mb-8">
                        Integre com a UTMfy para rastrear suas campanhas de marketing (Facebook Ads, Google Ads) e
                        descobrir a origem exata de cada venda.
                    </p>
                </div>

                <div class="relative z-10 mt-auto pt-6 border-t border-dark-border">
                    <span class="flex items-center text-sm font-bold transition-colors"
                        style="color: var(--accent-primary);"
                        onmouseover="this.style.color='var(--accent-primary-hover)'"
                        onmouseout="this.style.color='var(--accent-primary)'">
                        Configurar UTMfy <i data-lucide="arrow-right"
                            class="w-4 h-4 ml-2 group-hover:translate-x-1 transition-transform"></i>
                    </span>
                </div>
            </a>

            <!-- Card Gateways de Pagamento -->
            <div id="card-gateways" onclick="showGatewayConfig()"
                class="card-hover group relative bg-dark-card border border-dark-border rounded-2xl p-8 flex flex-col justify-between h-full overflow-hidden cursor-pointer"
                onmouseover="this.style.borderColor='var(--accent-primary)'"
                onmouseout="this.style.borderColor='rgba(255,255,255,0.1)'">
                <!-- Background Decoration -->
                <div class="absolute top-0 right-0 -mt-8 -mr-8 w-32 h-32 rounded-full opacity-50 group-hover:scale-150 transition-transform duration-500"
                    style="background-color: color-mix(in srgb, var(--accent-primary) 10%, transparent);"></div>

                <div class="relative z-10">
                    <div class="flex items-center gap-5 mb-6">
                        <div class="h-16 w-16 rounded-2xl flex items-center justify-center shadow-sm transition-colors"
                            style="background-color: color-mix(in srgb, var(--accent-primary) 20%, transparent); border-color: color-mix(in srgb, var(--accent-primary) 30%, transparent);"
                            onmouseover="this.style.backgroundColor='color-mix(in srgb, var(--accent-primary) 30%, transparent)'"
                            onmouseout="this.style.backgroundColor='color-mix(in srgb, var(--accent-primary) 20%, transparent)'">
                            <i data-lucide="credit-card" class="w-8 h-8" style="color: var(--accent-primary);"></i>
                        </div>
                        <div>
                            <h2 class="text-2xl font-bold text-white transition-colors"
                                onmouseover="this.style.color='var(--accent-primary)'"
                                onmouseout="this.style.color='white'">Gateways de Pagamento</h2>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium mt-1"
                                style="background-color: color-mix(in srgb, var(--accent-primary) 20%, transparent); color: var(--accent-primary);">
                                Pagamentos
                            </span>
                        </div>
                    </div>

                    <p class="text-gray-400 text-base leading-relaxed mb-8">
                        Configure suas credenciais de Mercado Pago, PushinPay, Efí, Hypercash, Asaas e Applyfy para
                        processar pagamentos. Gerencie suas chaves de API e métodos de recebimento.
                    </p>
                </div>

                <div class="relative z-10 mt-auto pt-6 border-t border-dark-border">
                    <span class="flex items-center text-sm font-bold transition-colors"
                        style="color: var(--accent-primary);"
                        onmouseover="this.style.color='var(--accent-primary-hover)'"
                        onmouseout="this.style.color='var(--accent-primary)'">
                        Configurar Gateways <i data-lucide="arrow-right"
                            class="w-4 h-4 ml-2 group-hover:translate-x-1 transition-transform"></i>
                    </span>
                </div>
            </div>

        </div>

        <!-- Formulário de Configuração de Gateways -->
        <form action="/index?pagina=integracoes" method="post" enctype="multipart/form-data" class="space-y-8 mt-8">
            <?php
            require_once __DIR__ . '/../helpers/security_helper.php';
            $csrf_token = generate_csrf_token();
            ?>
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

            <!-- Card SpacePag em Destaque -->
            <div id="spacepag-featured" class="hidden mb-8">
                <div id="card-spacepag" onclick="showGateway('spacepag')"
                    class="card-hover group relative bg-gradient-to-r from-indigo-950 via-purple-950 to-indigo-950 border-2 border-indigo-500/50 rounded-3xl p-8 cursor-pointer overflow-hidden shadow-2xl shadow-indigo-500/20 hover:shadow-indigo-500/40 transition-all duration-300">

                    <!-- Background Glow Effect -->
                    <div
                        class="absolute inset-0 bg-gradient-to-r from-indigo-600/10 via-purple-600/10 to-indigo-600/10 opacity-0 group-hover:opacity-100 transition-opacity duration-500">
                    </div>
                    <div
                        class="absolute top-0 left-1/4 w-96 h-96 bg-indigo-500/20 rounded-full blur-3xl -translate-y-1/2">
                    </div>
                    <div
                        class="absolute bottom-0 right-1/4 w-96 h-96 bg-purple-500/20 rounded-full blur-3xl translate-y-1/2">
                    </div>

                    <!-- Status Check (canto superior direito) -->
                    <div class="absolute top-6 right-6 z-10">
                        <?php if ($spacepag_configured): ?>
                            <div class="p-2 rounded-full border"
                                style="background-color: color-mix(in srgb, var(--accent-primary) 30%, transparent); color: var(--accent-primary); border-color: color-mix(in srgb, var(--accent-primary) 50%, transparent);">
                                <i data-lucide="check" class="w-5 h-5 stroke-[3]" style="color: var(--accent-primary);"></i>
                            </div>
                        <?php else: ?>
                            <div
                                class="bg-indigo-500/20 text-indigo-300 p-2 rounded-full border border-indigo-500/30 group-hover:bg-indigo-500/30 transition-colors">
                                <i data-lucide="circle" class="w-5 h-5"></i>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="relative z-10 flex flex-col md:flex-row items-center md:items-start gap-6 md:gap-10">

                        <!-- Logo SpacePag -->
                        <div class="flex-shrink-0">
                            <div
                                class="h-24 w-24 md:h-28 md:w-28 rounded-2xl bg-white/10 backdrop-blur-sm flex items-center justify-center border border-white/20 shadow-xl group-hover:scale-105 transition-transform duration-300">
                                <img src="/assets/gateways/spacepag.png" alt="SpacePag"
                                    class="h-16 md:h-20 w-auto object-contain">
                            </div>
                        </div>

                        <!-- Conteúdo -->
                        <div class="flex-1 text-center md:text-left">

                            <!-- Badges -->
                            <div class="flex flex-wrap justify-center md:justify-start gap-2 mb-4">
                                <span
                                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-bold border"
                                    style="background-color: color-mix(in srgb, var(--accent-primary) 20%, transparent); color: var(--accent-primary); border-color: color-mix(in srgb, var(--accent-primary) 30%, transparent);">
                                    <i data-lucide="star" class="w-3.5 h-3.5" style="color: var(--accent-primary);"></i>
                                    Recomendado
                                </span>
                                <span
                                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-bold bg-emerald-500/20 text-emerald-300 border border-emerald-500/30">
                                    <i data-lucide="qr-code" class="w-3.5 h-3.5"></i>
                                    Pix
                                </span>
                                <span
                                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-bold bg-amber-500/20 text-amber-300 border border-amber-500/30">
                                    <i data-lucide="file-text" class="w-3.5 h-3.5"></i>
                                    CPF ou CNPJ
                                </span>
                                <span
                                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-bold bg-blue-500/20 text-blue-300 border border-blue-500/30">
                                    <i data-lucide="shield-check" class="w-3.5 h-3.5"></i>
                                    Sem bloqueios
                                </span>
                                <span
                                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-bold bg-purple-500/20 text-purple-300 border border-purple-500/30">
                                    <i data-lucide="zap" class="w-3.5 h-3.5"></i>
                                    Saque instantâneo
                                </span>
                            </div>

                            <!-- Descrição -->
                            <p class="text-gray-300 text-base md:text-lg leading-relaxed mb-6 max-w-2xl">
                                Gateway de pagamento Pix rápido, seguro e sem burocracia. Receba suas vendas
                                instantaneamente sem risco de bloqueios.
                            </p>

                            <!-- Botões -->
                            <div class="flex flex-col sm:flex-row items-center gap-4">
                                <button type="button" onclick="event.stopPropagation(); showGateway('spacepag');"
                                    class="inline-flex items-center gap-2 px-6 py-3 bg-gradient-to-r from-indigo-600 to-purple-600 hover:from-indigo-500 hover:to-purple-500 text-white font-bold rounded-xl shadow-lg hover:shadow-indigo-500/30 transition-all duration-300 transform hover:scale-105">
                                    <i data-lucide="settings" class="w-5 h-5"></i>
                                    Configurar SpacePag
                                </button>

                                <a href="https://hub.spacepag.com.br/auth/jwt/sign-up?ref=4a5d0212320748719ee818cffdb93248"
                                    id="spacepag-signup-link" target="_blank" rel="noopener noreferrer"
                                    onclick="event.stopPropagation();"
                                    class="inline-flex items-center gap-2 px-6 py-3 bg-white/10 hover:bg-white/20 text-white font-semibold rounded-xl border border-white/20 hover:border-white/40 transition-all duration-300">
                                    <i data-lucide="user-plus" class="w-5 h-5"></i>
                                    Criar conta
                                    <i data-lucide="external-link" class="w-4 h-4 opacity-70"></i>
                                </a>
                            </div>

                        </div>

                    </div>

                </div>
            </div>

            <!-- Seleção de Gateway (Cards) -->
            <div id="gateway-selection-cards" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8 hidden">

                <!-- Card Mercado Pago -->
                <div id="card-mp" onclick="showGateway('mp')"
                    class="card-hover group relative bg-dark-card border border-dark-border rounded-2xl p-8 cursor-pointer overflow-hidden h-full flex flex-col justify-between">

                    <div class="absolute top-0 right-0 p-6">
                        <?php if ($mp_configured): ?>
                            <div class="p-1.5 rounded-full"
                                style="background-color: color-mix(in srgb, var(--accent-primary) 30%, transparent); color: var(--accent-primary);">
                                <i data-lucide="check" class="w-4 h-4 stroke-[3]" style="color: var(--accent-primary);"></i>
                            </div>
                        <?php else: ?>
                            <div
                                class="bg-dark-elevated text-gray-500 p-1.5 rounded-full group-hover:bg-dark-card transition-colors">
                                <i data-lucide="circle" class="w-4 h-4"></i>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="flex items-center gap-5 mb-4">
                        <div
                            class="h-16 w-16 rounded-2xl bg-blue-900/20 flex items-center justify-center border border-blue-500/30 shadow-sm group-hover:bg-blue-900/30 transition-colors">
                            <img src="/assets/gateways/mercadopago.png" alt="Mercado Pago"
                                class="h-10 w-10 object-contain">
                        </div>
                        <div>
                            <h3 class="text-xl font-bold text-white transition-colors"
                                onmouseover="this.style.color='var(--accent-primary)'"
                                onmouseout="this.style.color='white'">Mercado Pago</h3>
                            <p class="text-gray-400 text-sm">Cartão, Boleto e Pix</p>
                        </div>
                    </div>

                    <div class="mt-4 pt-4 border-t border-dark-border">
                        <span class="text-sm font-semibold flex items-center transition-transform"
                            style="color: var(--accent-primary);"
                            onmouseover="this.style.transform='translateX(0.25rem)'"
                            onmouseout="this.style.transform='translateX(0)'">
                            Configurar <i data-lucide="chevron-right" class="w-4 h-4 ml-1"></i>
                        </span>
                    </div>
                </div>

                <!-- Card PushinPay -->
                <div id="card-pp" onclick="showGateway('pp')"
                    class="card-hover group relative bg-dark-card border border-dark-border rounded-2xl p-8 cursor-pointer overflow-hidden h-full flex flex-col justify-between">

                    <div class="absolute top-0 right-0 p-6">
                        <?php if ($pp_configured): ?>
                            <div class="p-1.5 rounded-full"
                                style="background-color: color-mix(in srgb, var(--accent-primary) 30%, transparent); color: var(--accent-primary);">
                                <i data-lucide="check" class="w-4 h-4 stroke-[3]" style="color: var(--accent-primary);"></i>
                            </div>
                        <?php else: ?>
                            <div
                                class="bg-dark-elevated text-gray-500 p-1.5 rounded-full group-hover:bg-dark-card transition-colors">
                                <i data-lucide="circle" class="w-4 h-4"></i>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="flex items-center gap-5 mb-4">
                        <div class="h-16 w-16 rounded-2xl flex items-center justify-center shadow-sm transition-colors"
                            style="background-color: color-mix(in srgb, var(--accent-primary) 20%, transparent); border-color: color-mix(in srgb, var(--accent-primary) 30%, transparent);"
                            onmouseover="this.style.backgroundColor='color-mix(in srgb, var(--accent-primary) 30%, transparent)'"
                            onmouseout="this.style.backgroundColor='color-mix(in srgb, var(--accent-primary) 20%, transparent)'">
                            <img src="/assets/gateways/pushinpay.png" alt="PushinPay" class="h-10 w-10 object-contain">
                        </div>
                        <div>
                            <h3 class="text-xl font-bold text-white transition-colors"
                                style="--hover-color: var(--accent-primary);"
                                onmouseover="this.style.color='var(--accent-primary)'"
                                onmouseout="this.style.color='white'">PushinPay</h3>
                            <p class="text-gray-400 text-sm">Pix</p>
                        </div>
                    </div>

                    <div class="mt-4 pt-4 border-t border-dark-border">
                        <span class="text-sm font-semibold flex items-center transition-transform"
                            style="color: var(--accent-primary);"
                            onmouseover="this.style.transform='translateX(0.25rem)'"
                            onmouseout="this.style.transform='translateX(0)'">
                            Configurar <i data-lucide="chevron-right" class="w-4 h-4 ml-1"></i>
                        </span>
                    </div>
                </div>

                <!-- Card Efí -->
                <div id="card-efi" onclick="showGateway('efi')"
                    class="card-hover group relative bg-dark-card border border-dark-border rounded-2xl p-8 cursor-pointer overflow-hidden h-full flex flex-col justify-between">

                    <div class="absolute top-0 right-0 p-6">
                        <?php if ($efi_configured): ?>
                            <div class="p-1.5 rounded-full"
                                style="background-color: color-mix(in srgb, var(--accent-primary) 30%, transparent); color: var(--accent-primary);">
                                <i data-lucide="check" class="w-4 h-4 stroke-[3]" style="color: var(--accent-primary);"></i>
                            </div>
                        <?php else: ?>
                            <div
                                class="bg-dark-elevated text-gray-500 p-1.5 rounded-full group-hover:bg-dark-card transition-colors">
                                <i data-lucide="circle" class="w-4 h-4"></i>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="flex items-center gap-5 mb-4">
                        <div
                            class="h-16 w-16 rounded-2xl bg-purple-900/20 flex items-center justify-center border border-purple-500/30 shadow-sm group-hover:bg-purple-900/30 transition-colors">
                            <img src="/assets/gateways/efi.png" alt="Efí" class="h-10 w-10 object-contain">
                        </div>
                        <div>
                            <h3 class="text-xl font-bold text-white transition-colors"
                                onmouseover="this.style.color='var(--accent-primary)'"
                                onmouseout="this.style.color='white'">Efí</h3>
                            <p class="text-gray-400 text-sm">Pix</p>
                        </div>
                    </div>

                    <div class="mt-4 pt-4 border-t border-dark-border">
                        <span class="text-sm font-semibold flex items-center transition-transform"
                            style="color: var(--accent-primary);"
                            onmouseover="this.style.transform='translateX(0.25rem)'"
                            onmouseout="this.style.transform='translateX(0)'">
                            Configurar <i data-lucide="chevron-right" class="w-4 h-4 ml-1"></i>
                        </span>
                    </div>
                </div>

                <!-- Card Asaas -->
                <div id="card-asaas" onclick="showGateway('asaas')"
                    class="card-hover group relative bg-dark-card border border-dark-border rounded-2xl p-8 cursor-pointer overflow-hidden h-full flex flex-col justify-between">

                    <div class="absolute top-0 right-0 p-6">
                        <?php if ($asaas_configured): ?>
                            <div class="p-1.5 rounded-full"
                                style="background-color: color-mix(in srgb, var(--accent-primary) 30%, transparent); color: var(--accent-primary);">
                                <i data-lucide="check" class="w-4 h-4 stroke-[3]"></i>
                            </div>
                        <?php else: ?>
                            <div
                                class="bg-dark-elevated text-gray-500 p-1.5 rounded-full group-hover:bg-dark-card transition-colors">
                                <i data-lucide="circle" class="w-4 h-4"></i>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="flex items-center gap-5 mb-4">
                        <div
                            class="h-16 w-16 rounded-2xl bg-teal-900/20 flex items-center justify-center border border-teal-500/30 shadow-sm group-hover:bg-teal-900/30 transition-colors">
                            <img src="/assets/gateways/asaas.png" alt="Asaas" class="h-10 w-10 object-contain">
                        </div>
                        <div>
                            <h3 class="text-xl font-bold text-white transition-colors"
                                onmouseover="this.style.color='var(--accent-primary)'"
                                onmouseout="this.style.color='white'">Asaas</h3>
                            <p class="text-gray-400 text-sm">Pix e Cartão de Crédito</p>
                        </div>
                    </div>

                    <div class="mt-4 pt-4 border-t border-dark-border">
                        <span class="text-sm font-semibold flex items-center transition-transform"
                            style="color: var(--accent-primary);"
                            onmouseover="this.style.transform='translateX(0.25rem)'"
                            onmouseout="this.style.transform='translateX(0)'">
                            Configurar <i data-lucide="chevron-right" class="w-4 h-4 ml-1"></i>
                        </span>
                    </div>
                </div>

                <!-- Card Hypercash -->
                <div id="card-hypercash" onclick="showGateway('hypercash')"
                    class="card-hover group relative bg-dark-card border border-dark-border rounded-2xl p-8 cursor-pointer overflow-hidden h-full flex flex-col justify-between">

                    <div class="absolute top-0 right-0 p-6">
                        <?php if ($hypercash_configured): ?>
                            <div class="p-1.5 rounded-full"
                                style="background-color: color-mix(in srgb, var(--accent-primary) 30%, transparent); color: var(--accent-primary);">
                                <i data-lucide="check" class="w-4 h-4 stroke-[3]"></i>
                            </div>
                        <?php else: ?>
                            <div
                                class="bg-dark-elevated text-gray-500 p-1.5 rounded-full group-hover:bg-dark-card transition-colors">
                                <i data-lucide="circle" class="w-4 h-4"></i>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="flex items-center gap-5 mb-4">
                        <div
                            class="h-16 w-16 rounded-2xl bg-indigo-900/20 flex items-center justify-center border border-indigo-500/30 shadow-sm group-hover:bg-indigo-900/30 transition-colors">
                            <img src="/assets/gateways/hypercash.png" alt="Hypercash" class="h-10 w-10 object-contain">
                        </div>
                        <div>
                            <h3 class="text-xl font-bold text-white transition-colors"
                                onmouseover="this.style.color='var(--accent-primary)'"
                                onmouseout="this.style.color='white'">Hypercash</h3>
                            <p class="text-gray-400 text-sm">Cartão de Crédito</p>
                        </div>
                    </div>

                    <div class="mt-4 pt-4 border-t border-dark-border">
                        <span class="text-sm font-semibold flex items-center transition-transform"
                            style="color: var(--accent-primary);"
                            onmouseover="this.style.transform='translateX(0.25rem)'"
                            onmouseout="this.style.transform='translateX(0)'">
                            Configurar <i data-lucide="chevron-right" class="w-4 h-4 ml-1"></i>
                        </span>
                    </div>
                </div>

                <!-- Card Applyfy -->
                <div id="card-applyfy" onclick="showGateway('applyfy')"
                    class="card-hover group relative bg-dark-card border border-dark-border rounded-2xl p-8 cursor-pointer overflow-hidden h-full flex flex-col justify-between">

                    <div class="absolute top-0 right-0 p-6">
                        <?php if ($applyfy_configured): ?>
                            <div class="p-1.5 rounded-full"
                                style="background-color: color-mix(in srgb, var(--accent-primary) 30%, transparent); color: var(--accent-primary);">
                                <i data-lucide="check" class="w-4 h-4 stroke-[3]"></i>
                            </div>
                        <?php else: ?>
                            <div
                                class="bg-dark-elevated text-gray-500 p-1.5 rounded-full group-hover:bg-dark-card transition-colors">
                                <i data-lucide="circle" class="w-4 h-4"></i>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="flex items-center gap-5 mb-4">
                        <div
                            class="h-16 w-16 rounded-2xl bg-blue-900/20 flex items-center justify-center border border-blue-500/30 shadow-sm group-hover:bg-blue-900/30 transition-colors">
                            <img src="/assets/gateways/applyfy.png" alt="Applyfy" class="h-10 w-10 object-contain">
                        </div>
                        <div>
                            <h3 class="text-xl font-bold text-white transition-colors"
                                onmouseover="this.style.color='var(--accent-primary)'"
                                onmouseout="this.style.color='white'">Applyfy</h3>
                            <p class="text-gray-400 text-sm">Pix e Cartão de Crédito</p>
                        </div>
                    </div>

                    <div class="mt-4 pt-4 border-t border-dark-border">
                        <span class="text-sm font-semibold flex items-center transition-transform"
                            style="color: var(--accent-primary);"
                            onmouseover="this.style.transform='translateX(0.25rem)'"
                            onmouseout="this.style.transform='translateX(0)'">
                            Configurar <i data-lucide="chevron-right" class="w-4 h-4 ml-1"></i>
                        </span>
                    </div>
                </div>

                <!-- Card Stripe -->
                <div id="card-stripe" onclick="showGateway('stripe')"
                    class="card-hover group relative bg-dark-card border border-dark-border rounded-2xl p-8 cursor-pointer overflow-hidden h-full flex flex-col justify-between">

                    <div class="absolute top-0 right-0 p-6">
                        <?php if ($stripe_configured): ?>
                            <div class="p-1.5 rounded-full"
                                style="background-color: color-mix(in srgb, var(--accent-primary) 30%, transparent); color: var(--accent-primary);">
                                <i data-lucide="check" class="w-4 h-4 stroke-[3]" style="color: var(--accent-primary);"></i>
                            </div>
                        <?php else: ?>
                            <div
                                class="bg-dark-elevated text-gray-500 p-1.5 rounded-full group-hover:bg-dark-card transition-colors">
                                <i data-lucide="circle" class="w-4 h-4"></i>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="flex items-center gap-5 mb-4">
                        <div
                            class="h-16 w-16 rounded-2xl bg-indigo-900/20 flex items-center justify-center border border-indigo-500/30 shadow-sm group-hover:bg-indigo-900/30 transition-colors">
                            <img src="/assets/gateways/stripe.png" alt="Stripe" class="h-10 w-10 object-contain">
                        </div>
                        <div>
                            <h3 class="text-xl font-bold text-white transition-colors"
                                onmouseover="this.style.color='var(--accent-primary)'"
                                onmouseout="this.style.color='white'">Stripe</h3>
                            <p class="text-gray-400 text-sm">Cartão de Crédito</p>
                        </div>
                    </div>

                    <div class="mt-4 pt-4 border-t border-dark-border">
                        <span class="text-sm font-semibold flex items-center transition-transform"
                            style="color: var(--accent-primary);"
                            onmouseover="this.style.transform='translateX(0.25rem)'"
                            onmouseout="this.style.transform='translateX(0)'">
                            Configurar <i data-lucide="chevron-right" class="w-4 h-4 ml-1"></i>
                        </span>
                    </div>
                </div>

                <!-- Card Pagarme -->
                <div id="card-pagarme"
                    class="group relative bg-dark-card border border-dark-border rounded-2xl p-8 overflow-hidden h-full flex flex-col justify-between opacity-75">

                    <div class="absolute top-0 right-0 p-6">
                        <span
                            class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-bold bg-amber-500/20 text-amber-300 border border-amber-500/30">
                            Em breve
                        </span>
                    </div>

                    <div class="flex items-center gap-5 mb-4">
                        <div
                            class="h-16 w-16 rounded-2xl bg-green-900/20 flex items-center justify-center border border-green-500/30 shadow-sm">
                            <img src="/assets/gateways/pagarme.png" alt="Pagarme" class="h-10 w-10 object-contain">
                        </div>
                        <div>
                            <h3 class="text-xl font-bold text-white">Pagarme</h3>
                            <p class="text-gray-400 text-sm">Pix e Cartão de Crédito</p>
                        </div>
                    </div>

                    <div class="mt-4 pt-4 border-t border-dark-border">
                        <span class="text-sm font-semibold text-gray-500 flex items-center">
                            Em breve <i data-lucide="clock" class="w-4 h-4 ml-1"></i>
                        </span>
                    </div>
                </div>

            </div>

            <!-- Área de Configuração (Painel Expansível) -->
            <div id="gateway-forms-container" class="hidden animate-fade-in">
                <div class="bg-dark-card rounded-2xl shadow-xl border border-dark-border overflow-hidden">

                    <div class="p-8 md:p-10">

                        <!-- Formulário MP -->
                        <div id="fields-mp" class="hidden gateway-section">
                            <div class="flex items-center gap-4 mb-8 border-b border-dark-border pb-6">
                                <div
                                    class="h-12 w-12 rounded-xl bg-blue-900/20 flex items-center justify-center border border-blue-500/30">
                                    <img src="/assets/gateways/mercadopago.png" alt="Mercado Pago"
                                        class="h-8 w-8 object-contain">
                                </div>
                                <div>
                                    <h3 class="text-xl font-bold text-white">Credenciais Mercado Pago</h3>
                                    <p class="text-gray-400">Insira suas chaves de produção (Live Keys).</p>
                                </div>
                            </div>

                            <div class="grid gap-6">
                                <div class="group">
                                    <label class="block text-sm font-semibold text-gray-300 mb-2">Public Key</label>
                                    <div
                                        class="custom-input flex items-center border border-dark-border rounded-lg px-4 py-3 bg-dark-elevated transition-all">
                                        <i data-lucide="unlock" class="text-gray-400 w-5 h-5 mr-3"></i>
                                        <input type="text" name="mercado_pago_public_key"
                                            value="<?php echo htmlspecialchars($mercado_pago_public_key); ?>"
                                            class="w-full bg-transparent border-none focus:ring-0 text-white placeholder-gray-500 font-medium sm:text-sm"
                                            placeholder="APP_USR-xxxxxxxx...">
                                    </div>
                                </div>

                                <div class="group">
                                    <label class="block text-sm font-semibold text-gray-300 mb-2">Access Token</label>
                                    <div
                                        class="custom-input flex items-center border border-dark-border rounded-lg px-4 py-3 bg-dark-elevated transition-all">
                                        <i data-lucide="lock" class="text-gray-400 w-5 h-5 mr-3"></i>
                                        <input type="text" name="mercado_pago_access_token"
                                            value="<?php echo htmlspecialchars($mercado_pago_access_token); ?>"
                                            class="w-full bg-transparent border-none focus:ring-0 text-white placeholder-gray-500 font-medium sm:text-sm"
                                            placeholder="APP_USR-xxxxxxxx...">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Formulário PP -->
                        <div id="fields-pp" class="hidden gateway-section">
                            <div class="flex items-center gap-4 mb-8 border-b border-dark-border pb-6">
                                <div class="h-12 w-12 rounded-xl flex items-center justify-center"
                                    style="background-color: color-mix(in srgb, var(--accent-primary) 20%, transparent); border-color: color-mix(in srgb, var(--accent-primary) 30%, transparent);">
                                    <img src="/assets/gateways/pushinpay.png" alt="PushinPay"
                                        class="h-8 w-8 object-contain">
                                </div>
                                <div>
                                    <h3 class="text-xl font-bold text-white">Credenciais PushinPay</h3>
                                    <p class="text-gray-400">Token de acesso para API Pix.</p>
                                </div>
                            </div>

                            <div class="group mb-6">
                                <label class="block text-sm font-semibold text-gray-300 mb-2">API Token (Bearer)</label>
                                <div
                                    class="custom-input flex items-center border border-dark-border rounded-lg px-4 py-3 bg-dark-elevated transition-all">
                                    <i data-lucide="shield-check" class="text-gray-400 w-5 h-5 mr-3"></i>
                                    <input type="text" name="pushinpay_token"
                                        value="<?php echo htmlspecialchars($pushinpay_token); ?>"
                                        class="w-full bg-transparent border-none focus:ring-0 text-white placeholder-gray-500 font-medium sm:text-sm"
                                        placeholder="Cole seu token aqui...">
                                </div>
                            </div>

                            <div
                                class="rounded-xl bg-orange-900/20 border border-orange-500/30 p-4 flex gap-4 items-start">
                                <i data-lucide="info" class="text-orange-400 w-5 h-5 flex-shrink-0 mt-0.5"></i>
                                <div>
                                    <h4 class="text-sm font-bold text-orange-300">Nota Importante</h4>
                                    <p class="text-sm text-orange-200 mt-1">Este gateway processa exclusivamente
                                        pagamentos via <strong>Pix</strong>. O checkout será adaptado automaticamente.
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- Formulário Efí -->
                        <div id="fields-efi" class="hidden gateway-section">
                            <div class="flex items-center gap-4 mb-8 border-b border-dark-border pb-6">
                                <div
                                    class="h-12 w-12 rounded-xl bg-purple-900/20 flex items-center justify-center border border-purple-500/30">
                                    <img src="/assets/gateways/efi.png" alt="Efí" class="h-8 w-8 object-contain">
                                </div>
                                <div>
                                    <h3 class="text-xl font-bold text-white">Credenciais Efí</h3>
                                    <p class="text-gray-400">Configure sua integração com a API Pix Efí.</p>
                                </div>
                            </div>

                            <div class="grid gap-6">
                                <div class="group">
                                    <label class="block text-sm font-semibold text-gray-300 mb-2">Client ID</label>
                                    <div
                                        class="custom-input flex items-center border border-dark-border rounded-lg px-4 py-3 bg-dark-elevated transition-all">
                                        <i data-lucide="key" class="text-gray-400 w-5 h-5 mr-3"></i>
                                        <input type="text" name="efi_client_id"
                                            value="<?php echo htmlspecialchars($efi_client_id); ?>"
                                            class="w-full bg-transparent border-none focus:ring-0 text-white placeholder-gray-500 font-medium sm:text-sm"
                                            placeholder="Seu Client ID da aplicação Efí">
                                    </div>
                                </div>

                                <div class="group">
                                    <label class="block text-sm font-semibold text-gray-300 mb-2">Client Secret</label>
                                    <div
                                        class="custom-input flex items-center border border-dark-border rounded-lg px-4 py-3 bg-dark-elevated transition-all">
                                        <i data-lucide="lock" class="text-gray-400 w-5 h-5 mr-3"></i>
                                        <input type="password" name="efi_client_secret"
                                            value="<?php echo htmlspecialchars($efi_client_secret); ?>"
                                            class="w-full bg-transparent border-none focus:ring-0 text-white placeholder-gray-500 font-medium sm:text-sm"
                                            placeholder="Seu Client Secret da aplicação Efí">
                                    </div>
                                </div>

                                <div class="group">
                                    <label class="block text-sm font-semibold text-gray-300 mb-2">Certificado
                                        P12</label>
                                    <div
                                        class="custom-input flex items-center border border-dark-border rounded-lg px-4 py-3 bg-dark-elevated transition-all">
                                        <i data-lucide="file" class="text-gray-400 w-5 h-5 mr-3"></i>
                                        <input type="file" name="efi_certificate" accept=".p12"
                                            class="w-full bg-transparent border-none focus:ring-0 text-white placeholder-gray-500 font-medium sm:text-sm text-sm file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-purple-600 file:text-white hover:file:bg-purple-700 file:cursor-pointer">
                                    </div>
                                    <?php if (!empty($efi_certificate_path)): ?>
                                        <div class="flex items-center gap-2 mt-2">
                                            <i data-lucide="check-circle" class="w-4 h-4"
                                                style="color: var(--accent-primary);"></i>
                                            <p class="text-xs font-medium" style="color: var(--accent-primary);">Certificado
                                                carregado: <span
                                                    class="text-gray-300"><?php echo htmlspecialchars(basename($efi_certificate_path)); ?></span>
                                            </p>
                                        </div>
                                        <p class="text-xs text-gray-400 mt-1">Envie um novo arquivo para substituir o
                                            certificado atual.</p>
                                    <?php else: ?>
                                        <p class="text-xs text-gray-400 mt-1">Faça upload do certificado P12 gerado na sua
                                            conta Efí (máximo 5MB).</p>
                                    <?php endif; ?>
                                </div>

                                <div class="group">
                                    <label class="block text-sm font-semibold text-gray-300 mb-2">Chave Pix</label>
                                    <div
                                        class="custom-input flex items-center border border-dark-border rounded-lg px-4 py-3 bg-dark-elevated transition-all">
                                        <i data-lucide="qr-code" class="text-gray-400 w-5 h-5 mr-3"></i>
                                        <input type="text" name="efi_pix_key"
                                            value="<?php echo htmlspecialchars($efi_pix_key); ?>"
                                            class="w-full bg-transparent border-none focus:ring-0 text-white placeholder-gray-500 font-medium sm:text-sm"
                                            placeholder="Cole sua chave Pix (E-mail, CPF, CNPJ ou chave aleatória)">
                                    </div>
                                    <p class="text-xs text-gray-400 mt-1">A chave Pix cadastrada na sua conta Efí.</p>
                                </div>

                                <div class="group">
                                    <label class="block text-sm font-semibold text-gray-300 mb-2">Identificador de Conta
                                        (Payee Code)</label>
                                    <div
                                        class="custom-input flex items-center border border-dark-border rounded-lg px-4 py-3 bg-dark-elevated transition-all">
                                        <i data-lucide="hash" class="text-gray-400 w-5 h-5 mr-3"></i>
                                        <input type="text" name="efi_payee_code"
                                            value="<?php echo htmlspecialchars($efi_payee_code); ?>"
                                            class="w-full bg-transparent border-none focus:ring-0 text-white placeholder-gray-500 font-medium sm:text-sm"
                                            placeholder="Seu Identificador de conta (payee_code) da Efí">
                                    </div>
                                    <p class="text-xs text-gray-400 mt-1">Necessário para gerar payment_token no
                                        frontend para pagamentos via cartão de crédito. Encontre em: API > Introdução >
                                        Identificador de conta.</p>
                                </div>
                            </div>

                            <div
                                class="rounded-xl bg-purple-900/20 border border-purple-500/30 p-4 flex gap-4 items-start mt-6">
                                <i data-lucide="info" class="text-purple-400 w-5 h-5 flex-shrink-0 mt-0.5"></i>
                                <div>
                                    <h4 class="text-sm font-bold text-purple-300">Nota Importante</h4>
                                    <p class="text-sm text-purple-200 mt-1">Este gateway processa pagamentos via
                                        <strong>Pix</strong> e <strong>Cartão de Crédito</strong>. Você precisa ter uma
                                        conta Efí e gerar o certificado P12 na seção de API da sua conta. O
                                        Identificador de Conta (Payee Code) é necessário apenas para pagamentos via
                                        cartão.
                                    </p>
                                </div>
                            </div>

                            <?php if (!empty($efi_client_id) && !empty($efi_client_secret) && !empty($efi_certificate_path)): ?>
                                <div
                                    class="rounded-xl bg-blue-900/20 border border-blue-500/30 p-4 flex gap-4 items-start mt-4">
                                    <i data-lucide="link" class="text-blue-400 w-5 h-5 flex-shrink-0 mt-0.5"></i>
                                    <div>
                                        <h4 class="text-sm font-bold text-blue-300">URL do Webhook</h4>
                                        <p class="text-sm text-blue-200 mt-1">Configure esta URL na sua conta Efí para
                                            receber notificações de pagamento:</p>
                                        <div class="mt-2 flex items-center gap-2">
                                            <input type="text" readonly
                                                value="<?php echo htmlspecialchars((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/notification.php'); ?>"
                                                class="flex-1 px-3 py-2 bg-dark-elevated border border-dark-border rounded text-sm text-white font-mono">
                                            <button type="button"
                                                onclick="copyToClipboard(this.previousElementSibling.value)"
                                                class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded text-sm font-semibold transition-colors">
                                                Copiar
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Formulário Hypercash -->
                        <div id="fields-hypercash" class="hidden gateway-section">
                            <div class="flex items-center gap-4 mb-8 border-b border-dark-border pb-6">
                                <div
                                    class="h-12 w-12 rounded-xl bg-indigo-900/20 flex items-center justify-center border border-indigo-500/30">
                                    <img src="/assets/gateways/hypercash.png" alt="Hypercash"
                                        class="h-8 w-8 object-contain">
                                </div>
                                <div>
                                    <h3 class="text-xl font-bold text-white">Credenciais Hypercash</h3>
                                    <p class="text-gray-400">Configure sua integração com a API Hypercash para
                                        pagamentos via Cartão de Crédito.</p>
                                </div>
                            </div>

                            <div class="grid gap-6">
                                <div class="group">
                                    <label class="block text-sm font-semibold text-gray-300 mb-2">Credencial
                                        Secreta</label>
                                    <div
                                        class="custom-input flex items-center border border-dark-border rounded-lg px-4 py-3 bg-dark-elevated transition-all">
                                        <i data-lucide="lock" class="text-gray-400 w-5 h-5 mr-3"></i>
                                        <input type="password" name="hypercash_secret_key"
                                            value="<?php echo htmlspecialchars($hypercash_secret_key); ?>"
                                            class="w-full bg-transparent border-none focus:ring-0 text-white placeholder-gray-500 font-medium sm:text-sm"
                                            placeholder="Sua Credencial Secreta da Hypercash">
                                    </div>
                                    <p class="text-xs text-gray-400 mt-1">Chave secreta para autenticação na API
                                        Hypercash (formato: sk_...).</p>
                                </div>

                                <div class="group">
                                    <label class="block text-sm font-semibold text-gray-300 mb-2">Credencial
                                        Pública</label>
                                    <div
                                        class="custom-input flex items-center border border-dark-border rounded-lg px-4 py-3 bg-dark-elevated transition-all">
                                        <i data-lucide="unlock" class="text-gray-400 w-5 h-5 mr-3"></i>
                                        <input type="text" name="hypercash_public_key"
                                            value="<?php echo htmlspecialchars($hypercash_public_key); ?>"
                                            class="w-full bg-transparent border-none focus:ring-0 text-white placeholder-gray-500 font-medium sm:text-sm"
                                            placeholder="Sua Credencial Pública da Hypercash">
                                    </div>
                                    <p class="text-xs text-gray-400 mt-1">Chave pública usada para tokenização no
                                        frontend (formato: pk_...).</p>
                                </div>
                            </div>

                            <div
                                class="rounded-xl bg-indigo-900/20 border border-indigo-500/30 p-4 flex gap-4 items-start mt-6">
                                <i data-lucide="info" class="text-indigo-400 w-5 h-5 flex-shrink-0 mt-0.5"></i>
                                <div>
                                    <h4 class="text-sm font-bold text-indigo-300">Nota Importante</h4>
                                    <p class="text-sm text-indigo-200 mt-1">Este gateway processa exclusivamente
                                        pagamentos via <strong>Cartão de Crédito</strong>. Você precisa ter uma conta
                                        Hypercash e obter suas credenciais na seção de API da sua conta.</p>
                                </div>
                            </div>
                        </div>

                        <!-- Formulário Asaas -->
                        <div id="fields-asaas" class="hidden gateway-section">
                            <div class="flex items-center gap-4 mb-8 border-b border-dark-border pb-6">
                                <div
                                    class="h-12 w-12 rounded-xl bg-teal-900/20 flex items-center justify-center border border-teal-500/30">
                                    <img src="/assets/gateways/asaas.png" alt="Asaas" class="h-8 w-8 object-contain">
                                </div>
                                <div>
                                    <h3 class="text-xl font-bold text-white">Credenciais Asaas</h3>
                                    <p class="text-gray-400">Configure sua integração com a API Asaas para pagamentos
                                        via Pix e Cartão de Crédito.</p>
                                </div>
                            </div>

                            <div class="grid gap-6">
                                <div class="group">
                                    <label class="block text-sm font-semibold text-gray-300 mb-2">Chave de API</label>
                                    <div
                                        class="custom-input flex items-center border border-dark-border rounded-lg px-4 py-3 bg-dark-elevated transition-all">
                                        <i data-lucide="lock" class="text-gray-400 w-5 h-5 mr-3"></i>
                                        <input type="password" name="asaas_api_key"
                                            value="<?php echo htmlspecialchars($asaas_api_key); ?>"
                                            class="w-full bg-transparent border-none focus:ring-0 text-white placeholder-gray-500 font-medium sm:text-sm"
                                            placeholder="Sua Chave de API do Asaas">
                                    </div>
                                    <p class="text-xs text-gray-400 mt-1">Chave de API do Asaas (formato: $aact_prod_...
                                        ou $aact_hmlg_...).</p>
                                </div>

                                <div class="group">
                                    <label class="block text-sm font-semibold text-gray-300 mb-2">Ambiente</label>
                                    <div
                                        class="custom-input flex items-center border border-dark-border rounded-lg px-4 py-3 bg-dark-elevated transition-all">
                                        <i data-lucide="globe" class="text-gray-400 w-5 h-5 mr-3"></i>
                                        <select name="asaas_environment"
                                            class="w-full bg-transparent border-none focus:ring-0 text-white font-medium sm:text-sm">
                                            <option value="sandbox" <?php echo $asaas_environment === 'sandbox' ? 'selected' : ''; ?>>Sandbox (Testes)</option>
                                            <option value="production" <?php echo $asaas_environment === 'production' ? 'selected' : ''; ?>>Produção</option>
                                        </select>
                                    </div>
                                    <p class="text-xs text-gray-400 mt-1">Selecione o ambiente: Sandbox para testes ou
                                        Produção para pagamentos reais.</p>
                                </div>
                            </div>

                            <div
                                class="rounded-xl bg-teal-900/20 border border-teal-500/30 p-4 flex gap-4 items-start mt-6">
                                <i data-lucide="info" class="text-teal-400 w-5 h-5 flex-shrink-0 mt-0.5"></i>
                                <div>
                                    <h4 class="text-sm font-bold text-teal-300">Nota Importante</h4>
                                    <p class="text-sm text-teal-200 mt-1">Este gateway processa pagamentos via
                                        <strong>Pix</strong> e <strong>Cartão de Crédito</strong>. Você precisa ter uma
                                        conta Asaas e obter sua chave de API na seção de Integrações da sua conta. Use
                                        Sandbox para testes antes de ir para produção.
                                    </p>
                                </div>
                            </div>

                            <?php if (!empty($asaas_api_key)): ?>
                                <div
                                    class="rounded-xl bg-blue-900/20 border border-blue-500/30 p-4 flex gap-4 items-start mt-4">
                                    <i data-lucide="link" class="text-blue-400 w-5 h-5 flex-shrink-0 mt-0.5"></i>
                                    <div>
                                        <h4 class="text-sm font-bold text-blue-300">URL do Webhook</h4>
                                        <p class="text-sm text-blue-200 mt-1">Configure esta URL na sua conta Asaas para
                                            receber notificações de pagamento:</p>
                                        <div class="mt-2 flex items-center gap-2">
                                            <input type="text" readonly
                                                value="<?php echo htmlspecialchars((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/notification.php'); ?>"
                                                class="flex-1 px-3 py-2 bg-dark-elevated border border-dark-border rounded text-sm text-white font-mono">
                                            <button type="button"
                                                onclick="copyToClipboard(this.previousElementSibling.value)"
                                                class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded text-sm font-semibold transition-colors">
                                                Copiar
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Formulário Applyfy -->
                        <div id="fields-applyfy" class="hidden gateway-section">
                            <div class="flex items-center gap-4 mb-8 border-b border-dark-border pb-6">
                                <div
                                    class="h-12 w-12 rounded-xl bg-blue-900/20 flex items-center justify-center border border-blue-500/30">
                                    <img src="/assets/gateways/applyfy.png" alt="Applyfy"
                                        class="h-8 w-8 object-contain">
                                </div>
                                <div>
                                    <h3 class="text-xl font-bold text-white">Credenciais Applyfy</h3>
                                    <p class="text-gray-400">Configure sua integração com a API Applyfy para pagamentos
                                        via Pix e Cartão de Crédito.</p>
                                </div>
                            </div>

                            <div class="grid gap-6">
                                <div class="group">
                                    <label class="block text-sm font-semibold text-gray-300 mb-2">Chave Pública</label>
                                    <div
                                        class="custom-input flex items-center border border-dark-border rounded-lg px-4 py-3 bg-dark-elevated transition-all">
                                        <i data-lucide="unlock" class="text-gray-400 w-5 h-5 mr-3"></i>
                                        <input type="text" name="applyfy_public_key"
                                            value="<?php echo htmlspecialchars($applyfy_public_key); ?>"
                                            class="w-full bg-transparent border-none focus:ring-0 text-white placeholder-gray-500 font-medium sm:text-sm"
                                            placeholder="Sua Chave Pública do Applyfy">
                                    </div>
                                    <p class="text-xs text-gray-400 mt-1">Chave pública do Applyfy (x-public-key).</p>
                                </div>

                                <div class="group">
                                    <label class="block text-sm font-semibold text-gray-300 mb-2">Chave Secreta</label>
                                    <div
                                        class="custom-input flex items-center border border-dark-border rounded-lg px-4 py-3 bg-dark-elevated transition-all">
                                        <i data-lucide="lock" class="text-gray-400 w-5 h-5 mr-3"></i>
                                        <input type="password" name="applyfy_secret_key"
                                            value="<?php echo htmlspecialchars($applyfy_secret_key); ?>"
                                            class="w-full bg-transparent border-none focus:ring-0 text-white placeholder-gray-500 font-medium sm:text-sm"
                                            placeholder="Sua Chave Secreta do Applyfy">
                                    </div>
                                    <p class="text-xs text-gray-400 mt-1">Chave secreta do Applyfy (x-secret-key).</p>
                                </div>
                            </div>

                            <div
                                class="rounded-xl bg-blue-900/20 border border-blue-500/30 p-4 flex gap-4 items-start mt-6">
                                <i data-lucide="info" class="text-blue-400 w-5 h-5 flex-shrink-0 mt-0.5"></i>
                                <div>
                                    <h4 class="text-sm font-bold text-blue-300">Nota Importante</h4>
                                    <p class="text-sm text-blue-200 mt-1">Este gateway processa pagamentos via
                                        <strong>Pix</strong> e <strong>Cartão de Crédito</strong>. Você precisa ter uma
                                        conta Applyfy e obter suas credenciais na seção de API da sua conta.
                                    </p>
                                </div>
                            </div>

                            <?php if (!empty($applyfy_public_key) && !empty($applyfy_secret_key)): ?>
                                <div
                                    class="rounded-xl bg-blue-900/20 border border-blue-500/30 p-4 flex gap-4 items-start mt-4">
                                    <i data-lucide="link" class="text-blue-400 w-5 h-5 flex-shrink-0 mt-0.5"></i>
                                    <div>
                                        <h4 class="text-sm font-bold text-blue-300">URL do Webhook</h4>
                                        <p class="text-sm text-blue-200 mt-1">Configure esta URL na sua conta Applyfy para
                                            receber notificações de pagamento:</p>
                                        <div class="mt-2 flex items-center gap-2">
                                            <input type="text" readonly
                                                value="<?php echo htmlspecialchars((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/notification.php'); ?>"
                                                class="flex-1 px-3 py-2 bg-dark-elevated border border-dark-border rounded text-sm text-white font-mono">
                                            <button type="button"
                                                onclick="copyToClipboard(this.previousElementSibling.value)"
                                                class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded text-sm font-semibold transition-colors">
                                                Copiar
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Formulário Stripe -->
                        <div id="fields-stripe" class="hidden gateway-section">
                            <div class="flex items-center gap-4 mb-8 border-b border-dark-border pb-6">
                                <div
                                    class="h-12 w-12 rounded-xl bg-indigo-900/20 flex items-center justify-center border border-indigo-500/30">
                                    <img src="/assets/gateways/stripe.png" alt="Stripe" class="h-8 w-8 object-contain">
                                </div>
                                <div>
                                    <h3 class="text-xl font-bold text-white">Credenciais Stripe</h3>
                                    <p class="text-gray-400">Configure sua integração com a Stripe para pagamentos
                                        globais.</p>
                                </div>
                            </div>

                            <div class="grid gap-6">
                                <div class="group">
                                    <label class="block text-sm font-semibold text-gray-300 mb-2">Chave Pública (Public
                                        Key)</label>
                                    <div
                                        class="custom-input flex items-center border border-dark-border rounded-lg px-4 py-3 bg-dark-elevated transition-all">
                                        <i data-lucide="unlock" class="text-gray-400 w-5 h-5 mr-3"></i>
                                        <input type="text" name="stripe_public_key"
                                            value="<?php echo htmlspecialchars($stripe_public_key); ?>"
                                            class="w-full bg-transparent border-none focus:ring-0 text-white placeholder-gray-500 font-medium sm:text-sm"
                                            placeholder="pk_test_... ou pk_live_...">
                                    </div>
                                </div>

                                <div class="group">
                                    <label class="block text-sm font-semibold text-gray-300 mb-2">Chave Secreta (Secret
                                        Key)</label>
                                    <div
                                        class="custom-input flex items-center border border-dark-border rounded-lg px-4 py-3 bg-dark-elevated transition-all">
                                        <i data-lucide="lock" class="text-gray-400 w-5 h-5 mr-3"></i>
                                        <input type="password" name="stripe_secret_key"
                                            value="<?php echo htmlspecialchars($stripe_secret_key); ?>"
                                            class="w-full bg-transparent border-none focus:ring-0 text-white placeholder-gray-500 font-medium sm:text-sm"
                                            placeholder="sk_test_... ou sk_live_...">
                                    </div>
                                </div>

                                <div class="group">
                                    <label class="block text-sm font-semibold text-gray-300 mb-2">Chave Secreta de
                                        Webhook (Webhook Secret)</label>
                                    <div
                                        class="custom-input flex items-center border border-dark-border rounded-lg px-4 py-3 bg-dark-elevated transition-all">
                                        <i data-lucide="key" class="text-gray-400 w-5 h-5 mr-3"></i>
                                        <input type="text" name="stripe_webhook_secret"
                                            value="<?php echo htmlspecialchars($stripe_webhook_secret); ?>"
                                            class="w-full bg-transparent border-none focus:ring-0 text-white placeholder-gray-500 font-medium sm:text-sm"
                                            placeholder="whsec_...">
                                    </div>
                                    <p class="text-xs text-gray-400 mt-1">Obtido no painel da Stripe em Developers >
                                        Webhooks (após criar o endpoint).</p>
                                </div>
                            </div>

                            <div
                                class="rounded-xl bg-blue-900/20 border border-blue-500/30 p-4 flex gap-4 items-start mt-6">
                                <i data-lucide="link" class="text-blue-400 w-5 h-5 flex-shrink-0 mt-0.5"></i>
                                <div>
                                    <h4 class="text-sm font-bold text-blue-300">URL do Endpoint de Webhook</h4>
                                    <p class="text-sm text-blue-200 mt-1">Configure esta URL na sua conta Stripe para
                                        receber notificações de pagamento:</p>
                                    <div class="mt-2 flex items-center gap-2">
                                        <input type="text" readonly
                                            value="<?php echo htmlspecialchars((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . '/notification.php'); ?>"
                                            class="flex-1 px-3 py-2 bg-dark-elevated border border-dark-border rounded text-sm text-white font-mono">
                                        <button type="button"
                                            onclick="copyToClipboard(this.previousElementSibling.value)"
                                            class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded text-sm font-semibold transition-colors">
                                            Copiar
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Formulário SpacePag -->
                        <div id="fields-spacepag" class="hidden gateway-section">
                            <div class="flex items-center gap-4 mb-8 border-b border-dark-border pb-6">
                                <div
                                    class="h-12 w-12 rounded-xl bg-indigo-900/20 flex items-center justify-center border border-indigo-500/30">
                                    <img src="/assets/gateways/spacepag.png" alt="SpacePag"
                                        class="h-8 w-8 object-contain">
                                </div>
                                <div>
                                    <h3 class="text-xl font-bold text-white">Credenciais SpacePag</h3>
                                    <p class="text-gray-400">Configure sua integração com a API SpacePag para pagamentos
                                        via Pix.</p>
                                </div>
                            </div>

                            <div class="grid gap-6">
                                <div class="group">
                                    <label class="block text-sm font-semibold text-gray-300 mb-2">Chave Pública</label>
                                    <div
                                        class="custom-input flex items-center border border-dark-border rounded-lg px-4 py-3 bg-dark-elevated transition-all">
                                        <i data-lucide="unlock" class="text-gray-400 w-5 h-5 mr-3"></i>
                                        <input type="text" name="spacepag_public_key"
                                            value="<?php echo htmlspecialchars($spacepag_public_key); ?>"
                                            class="w-full bg-transparent border-none focus:ring-0 text-white placeholder-gray-500 font-medium sm:text-sm"
                                            placeholder="pk_live_... ou pk_test_...">
                                    </div>
                                    <p class="text-xs text-gray-400 mt-1">Chave pública do SpacePag.</p>
                                </div>

                                <div class="group">
                                    <label class="block text-sm font-semibold text-gray-300 mb-2">Chave Secreta</label>
                                    <div
                                        class="custom-input flex items-center border border-dark-border rounded-lg px-4 py-3 bg-dark-elevated transition-all">
                                        <i data-lucide="lock" class="text-gray-400 w-5 h-5 mr-3"></i>
                                        <input type="password" name="spacepag_secret_key"
                                            value="<?php echo htmlspecialchars($spacepag_secret_key); ?>"
                                            class="w-full bg-transparent border-none focus:ring-0 text-white placeholder-gray-500 font-medium sm:text-sm"
                                            placeholder="sk_live_... ou sk_test_...">
                                    </div>
                                    <p class="text-xs text-gray-400 mt-1">Chave secreta do SpacePag.</p>
                                </div>
                            </div>

                            <div
                                class="rounded-xl bg-blue-900/20 border border-blue-500/30 p-4 flex gap-4 items-start mt-6">
                                <i data-lucide="info" class="text-blue-400 w-5 h-5 flex-shrink-0 mt-0.5"></i>
                                <div>
                                    <h4 class="text-sm font-bold text-blue-300">Nota Importante</h4>
                                    <p class="text-sm text-blue-200 mt-1">Este gateway processa pagamentos via
                                        <strong>Pix</strong>. Cadastre-se na SpacePag e utilize suas credenciais de API
                                        (public_key e secret_key).
                                    </p>
                                </div>
                            </div>

                            <?php if ($spacepag_configured): ?>
                                <div
                                    class="rounded-xl bg-blue-900/20 border border-blue-500/30 p-4 flex gap-4 items-start mt-4">
                                    <i data-lucide="link" class="text-blue-400 w-5 h-5 flex-shrink-0 mt-0.5"></i>
                                    <div>
                                        <h4 class="text-sm font-bold text-blue-300">URL do Webhook (postback)</h4>
                                        <p class="text-sm text-blue-200 mt-1">Configure esta URL na sua conta SpacePag para
                                            receber notificações de pagamento:</p>
                                        <div class="mt-2 flex items-center gap-2">
                                            <input type="text" readonly
                                                value="<?php echo htmlspecialchars((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $path . '/notification.php'); ?>"
                                                class="flex-1 px-3 py-2 bg-dark-elevated border border-dark-border rounded text-sm text-white font-mono">
                                            <button type="button"
                                                onclick="copyToClipboard(this.previousElementSibling.value)"
                                                class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded text-sm font-semibold transition-colors">
                                                Copiar
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Webhook Section (Estilo Terminal/Code) - Apenas para PushinPay, Hypercash, Asaas, Applyfy e SpacePag -->
                        <div id="webhook-section" class="mt-10 pt-8 border-t border-dark-border hidden">
                            <label
                                class="block text-sm font-semibold text-gray-300 mb-3 flex justify-between items-center">
                                <span>Webhook URL <span class="font-normal text-gray-400 ml-2 text-xs">Para notificações
                                        automáticas</span></span>
                                <span
                                    class="text-xs font-mono bg-dark-elevated text-gray-400 px-2 py-1 rounded border border-dark-border">POST</span>
                            </label>

                            <div class="relative group">
                                <div
                                    class="relative bg-dark-base rounded-xl p-1 flex items-center shadow-lg overflow-hidden border border-dark-border">
                                    <div class="pl-4 pr-2 py-3 flex-1 font-mono text-sm overflow-x-auto whitespace-nowrap"
                                        style="color: var(--accent-primary);">
                                        <span
                                            class="text-gray-500 select-none mr-2">$</span><?php echo htmlspecialchars($webhook_url); ?>
                                        <input type="hidden" id="webhook_url"
                                            value="<?php echo htmlspecialchars($webhook_url); ?>">
                                    </div>
                                    <button type="button" onclick="copyWebhookUrl()" id="copy-webhook-btn"
                                        class="bg-dark-elevated hover:bg-dark-card text-gray-300 hover:text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors mr-1 flex items-center gap-2 border border-dark-border">
                                        <i data-lucide="copy" class="w-4 h-4"></i> <span
                                            class="hidden sm:inline">Copiar</span>
                                    </button>
                                </div>
                            </div>
                        </div>

                    </div>

                    <!-- Footer Actions -->
                    <div
                        class="bg-dark-elevated px-8 py-6 border-t border-dark-border flex flex-col sm:flex-row items-center justify-end gap-4">
                        <button type="submit" name="salvar_gateways"
                            class="w-full sm:w-auto text-white font-bold py-3.5 px-8 rounded-xl shadow-lg hover:shadow-xl transition-all transform hover:-translate-y-0.5 flex items-center justify-center gap-2"
                            style="background-color: var(--accent-primary);"
                            onmouseover="this.style.backgroundColor='var(--accent-primary-hover)'"
                            onmouseout="this.style.backgroundColor='var(--accent-primary)'">
                            <i data-lucide="save" class="w-5 h-5"></i>
                            Salvar Alterações
                        </button>
                    </div>

                </div>
            </div>

        </form>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            lucide.createIcons();

            // Remove toast automaticamente após 4 segundos
            const toast = document.getElementById('toast-msg');
            if (toast) {
                setTimeout(() => {
                    toast.style.opacity = '0';
                    toast.style.transition = 'opacity 0.5s ease';
                    setTimeout(() => toast.remove(), 500);
                }, 4000);
            }
        });

        function showGatewayConfig() {
            const selectionCards = document.getElementById('gateway-selection-cards');
            const formsContainer = document.getElementById('gateway-forms-container');
            const spacepagFeatured = document.getElementById('spacepag-featured');

            // Mostrar cards de gateway e o SpacePag em destaque
            if (spacepagFeatured) {
                spacepagFeatured.classList.remove('hidden');
            }
            selectionCards.classList.remove('hidden');
            formsContainer.classList.remove('hidden');

            // Scroll suave
            setTimeout(() => {
                if (spacepagFeatured) {
                    spacepagFeatured.scrollIntoView({ behavior: 'smooth', block: 'start' });
                } else {
                    selectionCards.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            }, 100);

            lucide.createIcons();

            // Auto-selecionar gateway se já estiver configurado
            const hasMp = "<?php echo $mercado_pago_access_token; ?>";
            const hasPp = "<?php echo $pushinpay_token; ?>";
            const hasEfi = "<?php echo $efi_configured ? '1' : ''; ?>";
            const hasHypercash = "<?php echo $hypercash_configured ? '1' : ''; ?>";
            const hasAsaas = "<?php echo $asaas_configured ? '1' : ''; ?>";
            const hasApplyfy = "<?php echo $applyfy_configured ? '1' : ''; ?>";
            const hasSpacepag = "<?php echo $spacepag_configured ? '1' : ''; ?>";
            const hasStripe = "<?php echo $stripe_configured ? '1' : ''; ?>";

            if (hasSpacepag) {
                setTimeout(() => showGateway('spacepag'), 300);
            } else if (hasApplyfy) {
                setTimeout(() => showGateway('applyfy'), 300);
            } else if (hasAsaas) {
                setTimeout(() => showGateway('asaas'), 300);
            } else if (hasHypercash) {
                setTimeout(() => showGateway('hypercash'), 300);
            } else if (hasEfi) {
                setTimeout(() => showGateway('efi'), 300);
            } else if (hasPp && !hasMp) {
                setTimeout(() => showGateway('pp'), 300);
            } else if (hasMp) {
                setTimeout(() => showGateway('mp'), 300);
            } else if (hasStripe) {
                setTimeout(() => showGateway('stripe'), 300);
            }
        }

        function showGateway(gateway) {
            // Gateways não habilitados ainda
            if (gateway === 'pagarme') {
                return; // Não fazer nada para gateways em breve
            }

            // Visual reset para todos os cards na grid
            const allGridCards = document.querySelectorAll('#card-mp, #card-pp, #card-efi, #card-hypercash, #card-asaas, #card-applyfy');
            allGridCards.forEach(card => {
                card.classList.remove('selected-ring');
                card.style.borderColor = '';
                card.style.backgroundColor = '';
                card.classList.add('border-dark-border', 'bg-dark-card');
            });

            // Reset visual do card SpacePag em destaque
            const spacepagCard = document.getElementById('card-spacepag');
            if (spacepagCard) {
                spacepagCard.classList.remove('ring-4');
                spacepagCard.style.removeProperty('--tw-ring-color');
            }

            // Active visual
            if (gateway === 'spacepag') {
                // Para SpacePag, adicionar ring com cor dinâmica no card de destaque
                if (spacepagCard) {
                    spacepagCard.classList.add('ring-4');
                    spacepagCard.style.setProperty('--tw-ring-color', 'var(--accent-primary)');
                    spacepagCard.style.setProperty('--tw-ring-opacity', '0.5');
                }
            } else {
                const selectedCard = document.getElementById('card-' + gateway);
                if (selectedCard) {
                    selectedCard.classList.remove('border-dark-border', 'bg-dark-card');
                    selectedCard.classList.add('selected-ring');
                }
            }

            // Show container and specific form
            const container = document.getElementById('gateway-forms-container');
            container.classList.remove('hidden');

            document.querySelectorAll('.gateway-section').forEach(el => el.classList.add('hidden'));
            const formElement = document.getElementById('fields-' + gateway);
            if (formElement) {
                formElement.classList.remove('hidden');
            }

            // Mostrar Webhook URL para PushinPay, Hypercash, Asaas, Applyfy e SpacePag
            const webhookSection = document.getElementById('webhook-section');
            if (webhookSection) {
                if (gateway === 'pp' || gateway === 'hypercash' || gateway === 'asaas' || gateway === 'applyfy' || gateway === 'spacepag') {
                    webhookSection.classList.remove('hidden');
                } else {
                    webhookSection.classList.add('hidden');
                }
            }

            lucide.createIcons();

            // Scroll suave para o formulário de configuração
            setTimeout(() => {
                container.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }, 100);
        }

        function copyToClipboard(text) {
            navigator.clipboard.writeText(text).then(function () {
                alert('URL copiada para a área de transferência!');
            }, function (err) {
                // Fallback para navegadores mais antigos
                const textarea = document.createElement('textarea');
                textarea.value = text;
                textarea.style.position = 'fixed';
                textarea.style.opacity = '0';
                document.body.appendChild(textarea);
                textarea.select();
                document.execCommand('copy');
                document.body.removeChild(textarea);
                alert('URL copiada para a área de transferência!');
            });
        }

        function copyWebhookUrl() {
            const webhookInput = document.getElementById('webhook_url');
            const copyBtn = document.getElementById('copy-webhook-btn');

            navigator.clipboard.writeText(webhookInput.value).then(() => {
                const originalContent = copyBtn.innerHTML;
                const accentColor = getComputedStyle(document.documentElement).getPropertyValue('--accent-primary').trim();
                copyBtn.innerHTML = `<i data-lucide="check" class="w-4 h-4" style="color: ${accentColor};"></i> <span style="color: ${accentColor};">Copiado!</span>`;
                copyBtn.classList.remove('border-dark-border');
                copyBtn.style.borderColor = accentColor;

                lucide.createIcons();

                setTimeout(() => {
                    copyBtn.innerHTML = originalContent;
                    copyBtn.classList.add('border-dark-border');
                    copyBtn.style.borderColor = '';
                    lucide.createIcons();
                }, 2000);
            }).catch(err => {
                alert('Erro ao copiar. Tente manualmente.');
            });
        }
    </script>
</body>

</html>