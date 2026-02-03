<?php
require __DIR__ . '/config/config.php';
include __DIR__ . '/config/load_settings.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$EXPIRY_MINUTES = 15;
$expiry_seconds = $EXPIRY_MINUTES * 60;

// POST: receber dados do checkout, salvar na session e redirecionar para GET
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payment_id = trim($_POST['payment_id'] ?? '');
    $qr_code_base64 = $_POST['qr_code_base64'] ?? '';
    $pix_code = $_POST['pix_code'] ?? '';
    $seller_id = trim($_POST['seller_id'] ?? '');
    $gateway = trim($_POST['gateway'] ?? '');
    $amount = trim($_POST['amount'] ?? '');
    $redirect_url_after_approval = trim($_POST['redirect_url_after_approval'] ?? '');
    $accent_color = trim($_POST['accent_color'] ?? '');
    $customer_name = trim($_POST['customer_name'] ?? '');
    $customer_email = trim($_POST['customer_email'] ?? '');
    $customer_phone = trim($_POST['customer_phone'] ?? '');

    if (empty($payment_id)) {
        header('Location: /checkout.php');
        exit;
    }

    if (!isset($_SESSION['pix_display'])) {
        $_SESSION['pix_display'] = [];
    }
    $_SESSION['pix_display'][$payment_id] = [
        'qr_code_base64' => $qr_code_base64,
        'pix_code' => $pix_code,
        'payment_id' => $payment_id,
        'seller_id' => $seller_id,
        'gateway' => $gateway,
        'amount' => $amount,
        'redirect_url_after_approval' => $redirect_url_after_approval,
        'accent_color' => $accent_color ?: '#7427F1',
        'customer_name' => $customer_name,
        'customer_email' => $customer_email,
        'customer_phone' => $customer_phone,
        'created_at' => time(),
    ];

    header('Location: /pagamento_pix.php?payment_id=' . urlencode($payment_id));
    exit;
}

// GET: exibir página ou mensagem de expirado
$payment_id = trim($_GET['payment_id'] ?? '');
if (empty($payment_id)) {
    header('Location: /checkout.php');
    exit;
}

$pix_data = null;
if (!empty($_SESSION['pix_display'][$payment_id])) {
    $stored = $_SESSION['pix_display'][$payment_id];
    if ((time() - ($stored['created_at'] ?? 0)) < $expiry_seconds) {
        $pix_data = $stored;
    }
}

$amount_formatted = 'R$ 0,00';
if (!empty($pix_data['amount'])) {
    $amount_val = is_numeric($pix_data['amount']) ? (float)$pix_data['amount'] : 0;
    $amount_formatted = 'R$ ' . number_format($amount_val, 2, ',', '.');
}
$created_at_ts = $pix_data['created_at'] ?? time();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pagamento Pix</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.9.2/dist/confetti.browser.min.js"></script>
    <?php if (function_exists('do_action')) { do_action('public_page_head'); } ?>
    <style>
        body { font-family: 'Inter', sans-serif; }
        .timer-card { border: 2px dashed #d1d5db; }
        .btn-copy:hover { opacity: 0.9; }
    </style>
</head>
<body class="bg-gray-100 min-h-screen">
<?php if (function_exists('do_action')) { do_action('pagamento_pix_page_before'); } ?>

<?php if (!$pix_data): ?>
    <div class="min-h-screen flex items-center justify-center px-4 py-12">
        <div class="max-w-md w-full bg-white rounded-2xl shadow-lg p-8 text-center">
            <div class="w-16 h-16 rounded-full bg-amber-100 flex items-center justify-center mx-auto mb-6">
                <i data-lucide="clock" class="w-8 h-8 text-amber-600"></i>
            </div>
            <h1 class="text-lg font-bold text-gray-900 mb-2">Código expirado</h1>
            <p class="text-sm text-gray-600 mb-6">Este código Pix expirou. Volte ao checkout e gere um novo Pix para pagar.</p>
            <a href="/checkout.php" class="inline-block px-6 py-3 rounded-xl font-semibold text-white bg-gray-900 transition-opacity hover:opacity-90">Voltar ao checkout</a>
        </div>
    </div>
    <script>document.addEventListener('DOMContentLoaded', function() { if (typeof lucide !== 'undefined') lucide.createIcons(); });</script>
</body>
</html>
<?php exit; endif; ?>

    <div class="min-h-screen flex flex-col items-center px-4 py-6 sm:py-8 pb-12">
        <div class="w-full max-w-md">
            <div class="bg-white rounded-2xl shadow-lg border border-gray-200 overflow-hidden">
                <?php
                $img_src = $pix_data['qr_code_base64'] ?? '';
                if ($img_src && !preg_match('/^data:/', $img_src)) {
                    $img_src = 'data:image/png;base64,' . $img_src;
                }
                if ($img_src):
                ?>
                <div class="flex justify-center pt-6 pb-2">
                    <div class="w-40 h-40 sm:w-44 sm:h-44 p-2.5 bg-white rounded-2xl border-2 border-dashed border-gray-300 shadow-sm">
                        <img id="pix-qr-img" src="<?php echo htmlspecialchars($img_src); ?>" alt="QR Code Pix" class="w-full h-full object-contain" style="image-rendering: pixelated;">
                    </div>
                </div>
                <?php endif; ?>

                <div class="px-5 sm:px-6 pb-6">
                    <h1 class="text-lg sm:text-xl font-bold text-center text-gray-900 mb-1">Pague <?php echo htmlspecialchars($amount_formatted); ?> via Pix</h1>
                    <p class="text-center text-gray-500 text-xs sm:text-sm mb-5">Copie o código ou use a câmera para ler o QR Code e realize o pagamento no app do seu banco.</p>

                    <p class="text-xs text-gray-500 mb-2">Pix Copia e Cola</p>
                    <input type="text" id="pix-code-input" readonly value="<?php echo htmlspecialchars($pix_data['pix_code'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" class="w-full bg-gray-50 border border-gray-200 rounded-xl py-2.5 px-3 text-xs text-gray-800 focus:outline-none mb-3">
                    <button type="button" id="btn-copy-pix" class="btn-copy w-full inline-flex items-center justify-center gap-1.5 px-4 py-3 rounded-xl bg-gray-900 text-white text-sm font-semibold transition-opacity hover:opacity-90 mb-4">
                        <i data-lucide="copy" class="w-4 h-4"></i> Copiar
                    </button>

                    <div id="confirmar-pagamento-wrap" class="space-y-3 mb-4">
                        <button type="button" id="btn-confirmar-pagamento" class="w-full inline-flex items-center justify-center gap-2 py-3 rounded-xl font-semibold text-gray-900 bg-white border-2 border-gray-200 transition-colors hover:bg-gray-50">
                            <i data-lucide="check" class="w-4 h-4 text-green-600"></i> Confirmar pagamento
                        </button>
                        <p id="confirmar-feedback" class="text-center text-xs text-gray-500 hidden"></p>
                    </div>

                    <div class="timer-card bg-gray-50 rounded-xl p-4 mb-4">
                        <div class="flex items-center justify-center gap-2">
                            <i data-lucide="clock" class="w-4 h-4 text-gray-600 shrink-0"></i>
                            <span class="text-sm font-medium text-gray-700">Código expira em</span>
                            <span id="timer-display" class="ml-auto text-lg font-bold font-mono text-gray-900 tabular-nums">15:00</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Instruções em 3 passos (como na imagem) -->
            <div class="w-full max-w-md mt-4 bg-white rounded-2xl shadow-lg border border-gray-200 overflow-hidden">
                <div class="p-5 sm:p-6 space-y-4">
                    <div class="flex gap-3 items-start">
                        <div class="w-10 h-10 rounded-full bg-gray-100 flex items-center justify-center shrink-0 border border-gray-200">
                            <i data-lucide="building-2" class="w-5 h-5 text-gray-700"></i>
                        </div>
                        <div>
                            <h3 class="text-sm font-bold text-gray-900 mb-0.5">Acesse seu banco</h3>
                            <p class="text-xs text-gray-600">Abra o app do seu banco, é rapidinho.</p>
                        </div>
                    </div>
                    <div class="border-t border-dashed border-gray-200"></div>
                    <div class="flex gap-3 items-start">
                        <div class="w-10 h-10 rounded-full bg-gray-100 flex items-center justify-center shrink-0 border border-gray-200">
                            <i data-lucide="qr-code" class="w-5 h-5 text-gray-700"></i>
                        </div>
                        <div>
                            <h3 class="text-sm font-bold text-gray-900 mb-0.5">Escolha a opção Pix</h3>
                            <p class="text-xs text-gray-600">Selecione "Pix Copia e Cola" ou "Ler QR code".</p>
                        </div>
                    </div>
                    <div class="border-t border-dashed border-gray-200"></div>
                    <div class="flex gap-3 items-start">
                        <div class="w-10 h-10 rounded-full bg-gray-100 flex items-center justify-center shrink-0 border border-gray-200">
                            <i data-lucide="circle-dollar-sign" class="w-5 h-5 text-gray-700"></i>
                        </div>
                        <div>
                            <h3 class="text-sm font-bold text-gray-900 mb-0.5">Conclua o pagamento</h3>
                            <p class="text-xs text-gray-600">Cole o código ou leia o QR code, confirme os dados e pronto!</p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Resumo da compra -->
            <div class="w-full max-w-md mt-4 bg-white rounded-2xl shadow-lg border border-gray-200 overflow-hidden">
                <div class="p-5 sm:p-6">
                    <h3 class="text-sm font-bold text-gray-900 mb-4">Resumo da compra</h3>
                    <div class="space-y-2 mb-3">
                        <div class="flex justify-between items-center text-xs">
                            <span class="text-gray-700">Pagamento Pix</span>
                            <span class="text-gray-900 font-medium"><?php echo htmlspecialchars($amount_formatted); ?></span>
                        </div>
                    </div>
                    <div class="border-t border-dashed border-gray-200 pt-3 mt-3">
                        <div class="flex justify-between items-center text-sm">
                            <span class="font-bold text-gray-900">Total</span>
                            <span class="font-bold text-gray-900"><?php echo htmlspecialchars($amount_formatted); ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <?php
            $customer_name = $pix_data['customer_name'] ?? '';
            $customer_email = $pix_data['customer_email'] ?? '';
            $customer_phone = $pix_data['customer_phone'] ?? '';
            $has_customer_info = $customer_name !== '' || $customer_email !== '' || $customer_phone !== '';
            ?>
            <!-- Pix + Informações do cliente (um único card como na imagem) -->
            <div class="w-full max-w-md mt-4 bg-white rounded-2xl shadow-lg border border-gray-200 overflow-hidden">
                <div class="p-5 sm:p-6">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-full bg-gray-100 flex items-center justify-center shrink-0 border border-gray-200">
                                <i data-lucide="qr-code" class="w-5 h-5 text-gray-700"></i>
                            </div>
                            <span class="text-sm font-medium text-gray-900">Pix</span>
                        </div>
                        <span id="status-badge" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold bg-amber-100 text-amber-800 border border-amber-200">
                            <i data-lucide="clock" class="w-3.5 h-3.5"></i> Pendente
                        </span>
                    </div>
                    <?php if ($has_customer_info): ?>
                    <div class="border-t border-dashed border-gray-300 mt-5 pt-5">
                        <h3 class="text-sm font-bold text-gray-900 mb-4">Informações</h3>
                        <div class="space-y-3 text-sm">
                            <?php if ($customer_name !== ''): ?>
                            <div class="flex justify-between items-center">
                                <span class="text-gray-600">Cliente</span>
                                <span class="text-gray-900 font-medium text-right break-all ml-2"><?php echo htmlspecialchars($customer_name); ?></span>
                            </div>
                            <?php endif; ?>
                            <?php if ($customer_email !== ''): ?>
                            <div class="flex justify-between items-center">
                                <span class="text-gray-600">E-mail</span>
                                <span class="text-gray-900 font-medium text-right break-all ml-2"><?php echo htmlspecialchars($customer_email); ?></span>
                            </div>
                            <?php endif; ?>
                            <?php if ($customer_phone !== ''): ?>
                            <div class="flex justify-between items-center">
                                <span class="text-gray-600">Telefone</span>
                                <span class="text-gray-900 font-medium text-right"><?php echo htmlspecialchars($customer_phone); ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
    (function() {
        var paymentId = <?php echo json_encode($payment_id); ?>;
        var sellerId = <?php echo json_encode($pix_data['seller_id'] ?? ''); ?>;
        var gateway = <?php echo json_encode($pix_data['gateway'] ?? ''); ?>;
        var redirectUrl = <?php echo json_encode($pix_data['redirect_url_after_approval'] ?? ''); ?>;
        var createdAt = <?php echo (int)$created_at_ts; ?>;

        var EXPIRY_SEC = <?php echo (int)$expiry_seconds; ?>;
        var endTime = (createdAt + EXPIRY_SEC) * 1000;

        function redirectToObrigado() {
            var url = redirectUrl && redirectUrl.indexOf('http') !== 0 && redirectUrl.indexOf('/') === 0
                ? redirectUrl + (redirectUrl.indexOf('?') >= 0 ? '&' : '?') + 'payment_id=' + encodeURIComponent(paymentId)
                : '/obrigado.php?payment_id=' + encodeURIComponent(paymentId);
            window.location.href = url;
        }

        function updateTimer() {
            var now = Date.now();
            var left = Math.max(0, Math.floor((endTime - now) / 1000));
            if (left <= 0) {
                document.getElementById('timer-display').textContent = '00:00';
                document.getElementById('timer-display').classList.add('text-red-600');
                if (window.timerInterval) clearInterval(window.timerInterval);
                return;
            }
            var m = Math.floor(left / 60);
            var s = left % 60;
            document.getElementById('timer-display').textContent = (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
        }

        updateTimer();
        window.timerInterval = setInterval(updateTimer, 1000);

        document.getElementById('btn-copy-pix').addEventListener('click', function() {
            var input = document.getElementById('pix-code-input');
            var btn = this;
            input.select();
            input.setSelectionRange(0, 99999);
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(input.value).then(function() {
                    btn.innerHTML = '<i data-lucide="check" class="w-4 h-4"></i> Copiado!';
                    if (typeof lucide !== 'undefined') lucide.createIcons();
                    setTimeout(function() { btn.innerHTML = '<i data-lucide="copy" class="w-4 h-4"></i> Copiar'; if (typeof lucide !== 'undefined') lucide.createIcons(); }, 2000);
                });
            } else {
                try {
                    document.execCommand('copy');
                    btn.innerHTML = '<i data-lucide="check" class="w-4 h-4"></i> Copiado!';
                    if (typeof lucide !== 'undefined') lucide.createIcons();
                    setTimeout(function() { btn.innerHTML = '<i data-lucide="copy" class="w-4 h-4"></i> Copiar'; if (typeof lucide !== 'undefined') lucide.createIcons(); }, 2000);
                } catch (e) {}
            }
        });

        document.getElementById('btn-confirmar-pagamento').addEventListener('click', function() {
            var btn = this;
            var feedback = document.getElementById('confirmar-feedback');
            btn.disabled = true;
            btn.textContent = 'Verificando...';
            feedback.classList.remove('hidden');
            feedback.textContent = 'Aguardando...';
            feedback.classList.remove('text-green-600', 'text-red-600');

            fetch('/check_status?id=' + encodeURIComponent(paymentId) + '&seller_id=' + encodeURIComponent(sellerId) + '&gateway=' + encodeURIComponent(gateway))
                .then(function(r) { return r.ok ? r.json() : null; })
                .then(function(data) {
                    if (data && (data.status === 'approved' || data.status === 'paid')) {
                        feedback.textContent = 'Aprovado! Redirecionando...';
                        feedback.classList.add('text-green-600');
                        var badge = document.getElementById('status-badge');
                        if (badge) {
                            badge.className = 'inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold bg-green-100 text-green-800 border border-green-200';
                            badge.innerHTML = '<i data-lucide="check" class="w-3.5 h-3.5"></i> Aprovado';
                            if (typeof lucide !== 'undefined') lucide.createIcons();
                        }
                        setTimeout(redirectToObrigado, 800);
                    } else {
                        feedback.textContent = 'Ainda aguardando. O pagamento é confirmado automaticamente.';
                        feedback.classList.add('text-gray-500');
                        btn.disabled = false;
                        btn.textContent = 'Confirmar pagamento';
                    }
                })
                .catch(function() {
                    feedback.textContent = 'Não foi possível verificar. Tente novamente.';
                    feedback.classList.add('text-red-600');
                    btn.disabled = false;
                    btn.textContent = 'Confirmar pagamento';
                });
        });

        var paymentCheckInterval = setInterval(function() {
            fetch('/check_status?id=' + encodeURIComponent(paymentId) + '&seller_id=' + encodeURIComponent(sellerId) + '&gateway=' + encodeURIComponent(gateway))
                .then(function(r) { return r.ok ? r.json() : null; })
                .then(function(data) {
                    if (data && (data.status === 'approved' || data.status === 'paid')) {
                        clearInterval(paymentCheckInterval);
                        var wrap = document.getElementById('confirmar-pagamento-wrap');
                        if (wrap) {
                            var btn = wrap.querySelector('button');
                            if (btn) btn.style.display = 'none';
                        }
                        var feedback = document.getElementById('confirmar-feedback');
                        if (feedback) {
                            feedback.classList.remove('hidden');
                            feedback.textContent = 'Pagamento aprovado! Redirecionando...';
                            feedback.classList.add('text-green-600');
                        }
                        var badge = document.getElementById('status-badge');
                        if (badge) {
                            badge.className = 'inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-semibold bg-green-100 text-green-800 border border-green-200';
                            badge.innerHTML = '<i data-lucide="check" class="w-3.5 h-3.5"></i> Aprovado';
                            if (typeof lucide !== 'undefined') lucide.createIcons();
                        }
                        setTimeout(redirectToObrigado, 2000);
                    }
                });
        }, 5000);

        document.addEventListener('DOMContentLoaded', function() {
            if (typeof lucide !== 'undefined') lucide.createIcons();
            if (typeof confetti === 'function') {
                confetti({ particleCount: 120, spread: 70, origin: { y: 0.6 } });
            }
        });
    })();
    </script>
</body>
</html>
