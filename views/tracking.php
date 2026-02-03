<?php
// Este arquivo é incluído a partir do index.php,
// então a verificação de login e a conexão com o banco ($pdo) já existem.

// Incluir helper de segurança para funções CSRF
require_once __DIR__ . '/../helpers/security_helper.php';

// Carrega sistema de plugins com verificação de existência
$plugin_hooks_path = __DIR__ . '/../../helpers/plugin_hooks.php';
$plugin_loader_path = __DIR__ . '/../../helpers/plugin_loader.php';

if (file_exists($plugin_hooks_path)) {
    try {
        require_once $plugin_hooks_path;
    } catch (Exception $e) {
        error_log("Erro ao carregar plugin_hooks.php: " . $e->getMessage());
    }
}

if (file_exists($plugin_loader_path)) {
    try {
        require_once $plugin_loader_path;
    } catch (Exception $e) {
        error_log("Erro ao carregar plugin_loader.php: " . $e->getMessage());
    }
}

// Obter o ID do usuário logado
$usuario_id_logado = $_SESSION['id'] ?? 0;

// Verifica se tracking está habilitado no plano (SaaS)
$tracking_allowed = true;
if (function_exists('plugin_active') && plugin_active('saas')) {
    $limitations_path = __DIR__ . '/../../plugins/saas/includes/limitations.php';
    if (file_exists($limitations_path)) {
        try {
            require_once $limitations_path;
            if (function_exists('check_saas_limitation')) {
                $tracking_check = check_saas_limitation($usuario_id_logado, 'tracking');
                $tracking_allowed = $tracking_check['allowed'] ?? true;
            }
        } catch (Exception $e) {
            error_log("Erro ao carregar limitations.php: " . $e->getMessage());
            // Continua com tracking_allowed = true por padrão
        }
    }
}

// Se por algum motivo o ID do usuário não estiver definido, redireciona para o login
if ($usuario_id_logado === 0) {
    header("location: /login");
    exit;
}

$mensagem = '';

// Pega a mensagem da sessão, se houver, e depois limpa
if (isset($_SESSION['flash_message'])) {
    $mensagem = $_SESSION['flash_message'];
    unset($_SESSION['flash_message']);
}

// Verifica se a tabela starfy_tracking_products existe no banco
$table_exists = false;
try {
    if (isset($pdo) && $pdo instanceof PDO) {
        $stmt_check_table = $pdo->query("SHOW TABLES LIKE 'starfy_tracking_products'");
        $table_exists = $stmt_check_table->rowCount() > 0;
    }
} catch (PDOException $e) {
    error_log("Erro ao verificar existência da tabela starfy_tracking_products: " . $e->getMessage());
    $table_exists = false;
}

// Inicializa variáveis
$infoprodutor_products = [];
$tracked_products = [];

// Busca todos os produtos do infoprodutor para o dropdown
try {
    if (isset($pdo) && $pdo instanceof PDO) {
        $stmt_products = $pdo->prepare("SELECT id, nome FROM produtos WHERE usuario_id = :usuario_id ORDER BY nome ASC");
        $stmt_products->bindParam(':usuario_id', $usuario_id_logado, PDO::PARAM_INT);
        $stmt_products->execute();
        $infoprodutor_products = $stmt_products->fetchAll(PDO::FETCH_ASSOC);

        // Busca produtos já rastreados pelo infoprodutor (apenas se a tabela existir)
        if ($table_exists) {
            try {
                $stmt_tracked_products = $pdo->prepare("SELECT stp.id, stp.produto_id, stp.tracking_id, p.nome FROM starfy_tracking_products stp JOIN produtos p ON stp.produto_id = p.id WHERE stp.usuario_id = :usuario_id ORDER BY p.nome ASC");
                $stmt_tracked_products->bindParam(':usuario_id', $usuario_id_logado, PDO::PARAM_INT);
                $stmt_tracked_products->execute();
                $tracked_products = $stmt_tracked_products->fetchAll(PDO::FETCH_ASSOC);
            } catch (PDOException $e) {
                error_log("Erro ao buscar produtos rastreados: " . $e->getMessage());
                // Se a tabela não existir ou houver erro, tracked_products permanece vazio
                $tracked_products = [];
                if (strpos($e->getMessage(), "doesn't exist") !== false || strpos($e->getMessage(), "Table") !== false) {
                    $mensagem = "<div class='bg-yellow-900/20 border border-yellow-500 text-yellow-300 px-4 py-3 rounded relative mb-4' role='alert'><strong>Atenção:</strong> A tabela de rastreamento ainda não foi criada. Entre em contato com o suporte para ativar esta funcionalidade.</div>";
                }
            }
        } else {
            $mensagem = "<div class='bg-yellow-900/20 border border-yellow-500 text-yellow-300 px-4 py-3 rounded relative mb-4' role='alert'><strong>Atenção:</strong> A tabela de rastreamento ainda não foi criada. Entre em contato com o suporte para ativar esta funcionalidade.</div>";
        }
    } else {
        $mensagem = "<div class='bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded relative mb-4' role='alert'><strong>Erro:</strong> Não foi possível conectar ao banco de dados.</div>";
    }
} catch (PDOException $e) {
    error_log("Erro ao buscar produtos: " . $e->getMessage());
    $mensagem = "<div class='bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded relative mb-4' role='alert'><strong>Erro:</strong> Não foi possível carregar os produtos. Tente novamente mais tarde.</div>";
    $infoprodutor_products = [];
    $tracked_products = [];
} catch (Exception $e) {
    error_log("Erro geral em tracking.php: " . $e->getMessage());
    $mensagem = "<div class='bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded relative mb-4' role='alert'><strong>Erro:</strong> Ocorreu um erro inesperado. Tente novamente mais tarde.</div>";
    $infoprodutor_products = [];
    $tracked_products = [];
}

// Gerar token CSRF para uso em requisições JavaScript
$csrf_token_js = generate_csrf_token();
?>

<meta name="csrf-token" content="<?php echo htmlspecialchars($csrf_token_js); ?>">
<script>
    // Variável global para token CSRF
    window.csrfToken = '<?php echo htmlspecialchars($csrf_token_js); ?>';
</script>

<div class="container mx-auto">
    <h1 class="text-3xl font-bold text-white mb-6">Starfy Track</h1>
    <p class="text-gray-400 mb-8">Monitore o desempenho do seu funil de vendas em tempo real. Veja quantas pessoas visitam sua página, chegam ao checkout e compram seus produtos.</p>

    <?php echo $mensagem; ?>

    <?php if (!$tracking_allowed): ?>
        <div class="bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded relative mb-4" role="alert">
            <p class="font-bold">Tracking não disponível no seu plano</p>
            <p class="text-sm">Faça upgrade do seu plano para habilitar o sistema de tracking.</p>
            <a href="/plugins/saas/admin/checkout?plano=1" class="text-red-300 underline mt-2 inline-block">Ver planos disponíveis</a>
        </div>
    <?php endif; ?>

    <!-- Configuração de Rastreamento -->
    <div class="bg-dark-card p-8 rounded-lg shadow-md mb-8 <?php echo !$tracking_allowed ? 'opacity-50 pointer-events-none' : ''; ?>" style="border-color: var(--accent-primary);">
        <h2 class="text-2xl font-semibold text-white mb-6">Configurar Rastreamento de Produto</h2>
        <div class="space-y-6">
            <div>
                <label for="product_select" class="block text-gray-300 text-sm font-semibold mb-2">Selecione um Produto para Rastrear</label>
                <div class="flex flex-col sm:flex-row items-stretch sm:space-x-4 space-y-4 sm:space-y-0">
                    <select id="product_select" class="w-full sm:w-2/3 px-4 py-3 bg-dark-elevated border border-dark-border rounded-lg focus:outline-none focus:ring-2 text-white" style="--tw-ring-color: var(--accent-primary);">
                        <option value="">-- Selecione um produto --</option>
                        <?php foreach ($infoprodutor_products as $product): ?>
                            <option value="<?php echo $product['id']; ?>"><?php echo htmlspecialchars($product['nome']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button id="add_track_product_btn" class="w-full sm:w-1/3 text-white font-bold py-3 px-6 rounded-lg transition duration-300 flex items-center justify-center space-x-2 disabled:opacity-50 disabled:cursor-not-allowed" style="background-color: var(--accent-primary);" onmouseover="if(!this.disabled) this.style.backgroundColor='var(--accent-primary-hover)'" onmouseout="if(!this.disabled) this.style.backgroundColor='var(--accent-primary)'">
                        <i data-lucide="plus-circle" class="w-5 h-5"></i>
                        <span>Ativar Rastreamento</span>
                    </button>
                </div>
                <div id="product_select_error" class="text-red-400 text-sm mt-2 hidden">Por favor, selecione um produto.</div>
            </div>

            <!-- Produtos já rastreados -->
            <div class="mt-8 border-t border-dark-border pt-6">
                <h3 class="text-xl font-semibold text-white mb-4">Produtos Ativamente Rastreando</h3>
                <div id="tracked_products_list" class="space-y-4">
                    <?php if (empty($tracked_products)): ?>
                        <div class="text-center py-4 text-gray-400">
                            <i data-lucide="line-chart" class="mx-auto w-12 h-12 text-gray-500 mb-2"></i>
                            <p>Nenhum produto está sendo rastreado ainda.</p>
                            <p class="text-sm">Selecione um produto acima para começar.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($tracked_products as $tp): ?>
                            <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between p-4 bg-dark-elevated rounded-lg border border-dark-border">
                                <div class="flex-1 mb-2 sm:mb-0">
                                    <p class="font-semibold text-white"><?php echo htmlspecialchars($tp['nome']); ?></p>
                                    <p class="text-sm text-gray-400">ID de Rastreamento: <span class="font-mono text-gray-300"><?php echo htmlspecialchars($tp['tracking_id']); ?></span></p>
                                </div>
                                <div class="flex space-x-2 mt-2 sm:mt-0">
                                    <button class="generate-script-btn bg-blue-900/30 text-blue-300 font-semibold py-2 px-4 rounded-lg hover:bg-blue-900/50 transition text-sm flex items-center space-x-1 border border-blue-500/30" data-tracking-id="<?php echo htmlspecialchars($tp['tracking_id']); ?>" data-product-name="<?php echo htmlspecialchars($tp['nome']); ?>">
                                        <i data-lucide="code" class="w-4 h-4"></i>
                                        <span>Gerar Script</span>
                                    </button>
                                    <button class="view-data-btn bg-purple-900/30 text-purple-300 font-semibold py-2 px-4 rounded-lg hover:bg-purple-900/50 transition text-sm flex items-center space-x-1 border border-purple-500/30" data-tracking-product-db-id="<?php echo htmlspecialchars($tp['id']); ?>" data-product-name="<?php echo htmlspecialchars($tp['nome']); ?>">
                                        <i data-lucide="bar-chart" class="w-4 h-4"></i>
                                        <span>Ver Dados</span>
                                    </button>
                                    <button class="delete-funnel-btn bg-red-900/30 text-red-300 font-semibold py-2 px-4 rounded-lg hover:bg-red-900/50 transition text-sm flex items-center space-x-1 border border-red-500/30" data-tracking-product-db-id="<?php echo htmlspecialchars($tp['id']); ?>" data-product-name="<?php echo htmlspecialchars($tp['nome']); ?>">
                                        <i data-lucide="trash-2" class="w-4 h-4"></i>
                                        <span>Excluir</span>
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Seção de Dados de Rastreamento (Escondida por padrão) -->
    <div id="tracking_data_section" class="bg-dark-card p-8 rounded-lg shadow-md" style="display: none; border-color: var(--accent-primary);">
        <div class="flex items-center justify-between mb-6 border-b border-dark-border pb-4">
            <div>
                <h2 class="text-2xl font-semibold text-white">Análise de Funil para <span id="analyzed_product_name" style="color: var(--accent-primary);"></span></h2>
                <p class="text-gray-400 text-sm mt-1">Dados atualizados em tempo real.</p>
            </div>
            <div class="flex space-x-2">
                <button id="close_analysis_btn" class="bg-dark-elevated text-gray-300 font-bold py-2 px-4 rounded-lg hover:bg-dark-card transition duration-300 flex items-center space-x-2 border border-dark-border">
                    <i data-lucide="x-circle" class="w-5 h-5"></i>
                    <span>Fechar Análise</span>
                </button>
            </div>
        </div>

        <!-- Filtros de Período -->
        <div class="flex flex-wrap items-center justify-start gap-3 mb-6 bg-dark-elevated p-2 rounded-lg border border-dark-border">
            <span class="text-sm font-semibold text-gray-300 mr-2">Filtrar por:</span>
            <button data-period="today" class="period-filter-btn px-3 py-1 text-sm font-semibold rounded-md text-white shadow" style="background-color: var(--accent-primary);">Hoje</button>
            <button data-period="yesterday" class="period-filter-btn px-3 py-1 text-sm font-semibold rounded-md text-gray-400 hover:bg-dark-card">Ontem</button>
            <button data-period="7days" class="period-filter-btn px-3 py-1 text-sm font-semibold rounded-md text-gray-400 hover:bg-dark-card">7 dias</button>
            <button data-period="month" class="period-filter-btn px-3 py-1 text-sm font-semibold rounded-md text-gray-400 hover:bg-dark-card">Mês</button>
            <button data-period="year" class="period-filter-btn px-3 py-1 text-sm font-semibold rounded-md text-gray-400 hover:bg-dark-card">Ano</button>
            <button data-period="all" class="period-filter-btn px-3 py-1 text-sm font-semibold rounded-md text-gray-400 hover:bg-dark-card">Todo o Período</button>
        </div>
        
        <!-- Funil de Conversão (Gráfico) -->
        <div class="flex flex-col lg:flex-row gap-8 mb-8">
            <div class="lg:w-1/2 w-full bg-dark-elevated p-6 rounded-lg border border-dark-border shadow-sm">
                <h3 class="text-xl font-semibold text-white mb-4 flex items-center space-x-2"><i data-lucide="funnel" class="w-6 h-6" style="color: var(--accent-primary);"></i><span>Funil de Vendas</span></h3>
                <div class="h-80 relative">
                    <canvas id="funnelChart"></canvas>
                </div>
            </div>
            <div class="lg:w-1/2 w-full space-y-4">
                <!-- KPIs do Funil -->
                <div class="bg-blue-900/20 border-l-4 border-blue-500 p-4 rounded-lg shadow-sm">
                    <p class="text-gray-400 text-sm">Visitas à Página</p>
                    <p id="kpi_page_views" class="text-3xl font-bold text-blue-300">0</p>
                </div>
                <div class="bg-yellow-900/20 border-l-4 border-yellow-500 p-4 rounded-lg shadow-sm">
                    <p class="text-gray-400 text-sm">Visitas ao Checkout</p>
                    <p id="kpi_initiate_checkouts" class="text-3xl font-bold text-yellow-300">0</p>
                    <p id="conversion_page_to_checkout" class="text-sm text-gray-400 mt-1">0% de conversão (Página > Checkout)</p>
                </div>
                <div class="bg-green-900/20 border-l-4 border-green-500 p-4 rounded-lg shadow-sm">
                    <p class="text-gray-400 text-sm">Compras Aprovadas</p>
                    <p id="kpi_purchases" class="text-3xl font-bold text-green-300">0</p>
                    <p id="conversion_checkout_to_purchase" class="text-sm text-gray-400 mt-1">0% de conversão (Checkout > Compra)</p>
                </div>
                 <div class="p-4 rounded-lg shadow-sm" style="background-color: color-mix(in srgb, var(--accent-primary) 20%, transparent); border-left-color: var(--accent-primary); border-left-width: 4px;">
                    <p class="text-gray-400 text-sm">Taxa de Conversão Geral</p>
                    <p id="conversion_overall" class="text-3xl font-bold" style="color: var(--accent-primary);">0%</p>
                </div>
            </div>
        </div>

        <!-- Métricas de Desempenho Adicionais -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            <div class="bg-dark-card p-6 rounded-lg border border-dark-border shadow-sm">
                <h3 class="text-gray-400 text-sm font-medium">Cliques na Página p/ 1 Venda</h3>
                <p id="kpi_clicks_to_sale_page" class="text-2xl font-bold text-white">0</p>
            </div>
            <div class="bg-dark-card p-6 rounded-lg border border-dark-border shadow-sm">
                <h3 class="text-gray-400 text-sm font-medium">Cliques no Checkout p/ 1 Venda</h3>
                <p id="kpi_clicks_to_sale_checkout" class="text-2xl font-bold text-white">0</p>
            </div>
            <div class="bg-dark-card p-6 rounded-lg border border-dark-border shadow-sm">
                <h3 class="text-gray-400 text-sm font-medium">Vendas do Produto Principal</h3>
                <p id="kpi_main_product_sales_count" class="text-2xl font-bold text-white">0</p>
                <p id="kpi_main_product_sales_value" class="text-sm text-gray-400 mt-1">Valor Total: R$ 0,00</p>
            </div>
        </div>

        <!-- Vendas de Order Bumps -->
        <div class="bg-dark-card p-6 rounded-lg shadow-sm border border-dark-border">
            <h3 class="text-xl font-semibold text-white mb-4">Vendas de Order Bumps</h3>
            <div id="order_bump_sales_list" class="space-y-3">
                 <p class="text-gray-400">Nenhuma venda de order bump neste período.</p>
            </div>
        </div>
    </div>
</div>

<!-- Modal para exibir o script de rastreamento -->
<div id="script_modal" class="fixed inset-0 bg-black bg-opacity-60 z-50 flex items-center justify-center p-4 hidden">
    <div class="bg-dark-card rounded-xl shadow-2xl w-full max-w-2xl transform transition-all opacity-0 scale-95 border border-[#32e768]" id="script_modal_content">
        <div class="p-6 border-b border-dark-border flex justify-between items-center">
            <h2 class="text-2xl font-bold text-white">Script de Rastreamento para <span id="script_product_name" class="text-[#32e768]"></span></h2>
            <button class="close-modal-btn text-gray-400 hover:text-gray-300 p-1 rounded-full hover:bg-dark-elevated transition">
                <i data-lucide="x" class="w-6 h-6"></i>
            </button>
        </div>
        <div class="p-6 space-y-4">
            <p class="text-gray-300">Copie o script abaixo e cole-o na seção <code class="text-[#32e768]">&lt;head&gt;</code> do seu site de vendas, antes da tag <code class="text-[#32e768]">&lt;/head&gt;</code>.</p>
            <div class="relative">
                <textarea id="tracking_script_textarea" readonly rows="10" class="w-full p-4 bg-dark-base text-green-300 text-sm font-mono rounded-lg border border-dark-border focus:outline-none focus:ring-2 focus:ring-[#32e768]" wrap="off"></textarea>
                <button id="copy_script_btn" class="absolute top-4 right-4 bg-[#32e768] text-white font-semibold py-2 px-4 rounded-lg hover:bg-[#28d15e] transition duration-300 flex items-center space-x-2">
                    <i data-lucide="copy" class="w-5 h-5"></i>
                    <span>Copiar Script</span>
                </button>
            </div>
            <p class="text-sm text-gray-400">Este script irá rastrear visitas à página e cliques em botões de checkout para o produto selecionado.</p>
        </div>
    </div>
</div>


<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    lucide.createIcons();

    const productSelect = document.getElementById('product_select');
    const addTrackProductBtn = document.getElementById('add_track_product_btn');
    const productSelectError = document.getElementById('product_select_error');
    const trackedProductsList = document.getElementById('tracked_products_list');

    const trackingDataSection = document.getElementById('tracking_data_section');
    const analyzedProductName = document.getElementById('analyzed_product_name');
    const periodFilterButtons = document.querySelectorAll('.period-filter-btn');
    const closeAnalysisBtn = document.getElementById('close_analysis_btn');

    const kpiPageViews = document.getElementById('kpi_page_views');
    const kpiInitiateCheckouts = document.getElementById('kpi_initiate_checkouts');
    const kpiPurchases = document.getElementById('kpi_purchases');
    const conversionPageToCheckout = document.getElementById('conversion_page_to_checkout');
    const conversionCheckoutToPurchase = document.getElementById('conversion_checkout_to_purchase');
    const conversionOverall = document.getElementById('conversion_overall');
    const kpiClicksToSalePage = document.getElementById('kpi_clicks_to_sale_page');
    const kpiClicksToSaleCheckout = document.getElementById('kpi_clicks_to_sale_checkout');
    const kpiMainProductSalesCount = document.getElementById('kpi_main_product_sales_count');
    const kpiMainProductSalesValue = document.getElementById('kpi_main_product_sales_value');
    const orderBumpSalesList = document.getElementById('order_bump_sales_list');

    const scriptModal = document.getElementById('script_modal');
    const scriptProductName = document.getElementById('script_product_name');
    const trackingScriptTextarea = document.getElementById('tracking_script_textarea');
    const copyScriptBtn = document.getElementById('copy_script_btn');
    const closeModalBtns = document.querySelectorAll('.close-modal-btn');

    let currentTrackingProductDbId = null; // ID da tabela starfy_tracking_products
    let currentPeriodFilter = 'all';
    let funnelChartInstance = null;

    // --- Funções Auxiliares ---
    function showModal(modalElement) {
        modalElement.classList.remove('hidden');
        setTimeout(() => {
            const content = modalElement.querySelector('.transform');
            if (content) {
                content.classList.remove('opacity-0', 'scale-95');
            }
        }, 10);
    }

    function hideModal(modalElement) {
        const content = modalElement.querySelector('.transform');
        if (content) {
            content.classList.add('opacity-0', 'scale-95');
        }
        setTimeout(() => {
            modalElement.classList.add('hidden');
        }, 200);
    }

    function formatCurrency(value) {
        return parseFloat(value).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
    }

    // --- Lógica de Adição de Produto para Rastreamento ---
    productSelect.addEventListener('change', function() {
        if (this.value) {
            addTrackProductBtn.disabled = false;
            productSelectError.classList.add('hidden');
        } else {
            addTrackProductBtn.disabled = true;
        }
    });

    addTrackProductBtn.addEventListener('click', async function() {
        const productId = productSelect.value;
        if (!productId) {
            productSelectError.classList.remove('hidden');
            return;
        }

        try {
            const response = await fetch('/api/api.php?action=add_starfy_tracked_product', {
                method: 'POST',
                headers: { 
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': window.csrfToken || ''
                },
                body: JSON.stringify({ 
                    produto_id: productId,
                    csrf_token: window.csrfToken || ''
                })
            });
            const result = await response.json();

            if (result.success) {
                alert(result.message);
                await refreshTrackedProductsList(); // Atualiza a lista de produtos rastreados
                productSelect.value = ''; // Limpa o seletor
                addTrackProductBtn.disabled = true;
            } else {
                alert('Erro: ' + (result.error || 'Não foi possível ativar o rastreamento.'));
            }
        } catch (error) {
            console.error('Erro ao adicionar produto para rastreamento:', error);
            alert('Erro de comunicação com o servidor.');
        }
    });

    async function refreshTrackedProductsList() {
        try {
            const response = await fetch('/api/api.php?action=get_starfy_tracked_products');
            const result = await response.json();

            if (result.success) {
                trackedProductsList.innerHTML = ''; // Limpa a lista existente
                if (result.products.length === 0) {
                    trackedProductsList.innerHTML = `
                        <div class="text-center py-4 text-gray-400">
                            <i data-lucide="line-chart" class="mx-auto w-12 h-12 text-gray-500 mb-2"></i>
                            <p>Nenhum produto está sendo rastreado ainda.</p>
                            <p class="text-sm">Selecione um produto acima para começar.</p>
                        </div>
                    `;
                } else {
                    result.products.forEach(tp => {
                        const div = document.createElement('div');
                        div.className = 'flex flex-col sm:flex-row items-start sm:items-center justify-between p-4 bg-dark-elevated rounded-lg border border-dark-border';
                        div.innerHTML = `
                            <div class="flex-1 mb-2 sm:mb-0">
                                <p class="font-semibold text-white">${htmlspecialchars(tp.nome)}</p>
                                <p class="text-sm text-gray-400">ID de Rastreamento: <span class="font-mono text-gray-300">${htmlspecialchars(tp.tracking_id)}</span></p>
                            </div>
                            <div class="flex space-x-2 mt-2 sm:mt-0">
                                <button class="generate-script-btn bg-blue-900/30 text-blue-300 font-semibold py-2 px-4 rounded-lg hover:bg-blue-900/50 transition text-sm flex items-center space-x-1 border border-blue-500/30" data-tracking-id="${htmlspecialchars(tp.tracking_id)}" data-product-name="${htmlspecialchars(tp.nome)}">
                                    <i data-lucide="code" class="w-4 h-4"></i>
                                    <span>Gerar Script</span>
                                </button>
                                <button class="view-data-btn bg-purple-900/30 text-purple-300 font-semibold py-2 px-4 rounded-lg hover:bg-purple-900/50 transition text-sm flex items-center space-x-1 border border-purple-500/30" data-tracking-product-db-id="${htmlspecialchars(tp.id)}" data-product-name="${htmlspecialchars(tp.nome)}">
                                    <i data-lucide="bar-chart" class="w-4 h-4"></i>
                                    <span>Ver Dados</span>
                                </button>
                                <button class="delete-funnel-btn bg-red-900/30 text-red-300 font-semibold py-2 px-4 rounded-lg hover:bg-red-900/50 transition text-sm flex items-center space-x-1 border border-red-500/30" data-tracking-product-db-id="${htmlspecialchars(tp.id)}" data-product-name="${htmlspecialchars(tp.nome)}">
                                    <i data-lucide="trash-2" class="w-4 h-4"></i>
                                    <span>Excluir</span>
                                </button>
                            </div>
                        `;
                        trackedProductsList.appendChild(div);
                    });
                }
                lucide.createIcons(); // Re-render icons after adding new elements
            } else {
                alert('Erro ao carregar lista de produtos rastreados: ' + (result.error || 'Erro desconhecido.'));
            }
        } catch (error) {
            console.error('Erro ao recarregar produtos rastreados:', error);
            alert('Erro de comunicação ao recarregar a lista de produtos rastreados.');
        }
    }


    // --- Lógica do Modal de Script ---
    trackedProductsList.addEventListener('click', async function(e) {
        const generateBtn = e.target.closest('.generate-script-btn');
        if (generateBtn) {
            const trackingId = generateBtn.dataset.trackingId;
            const productName = generateBtn.dataset.productName;

            try {
                const response = await fetch(`/api/api.php?action=generate_tracking_script&tracking_id=${trackingId}`);
                const result = await response.json();

                if (result.success) {
                    scriptProductName.textContent = productName;
                    trackingScriptTextarea.value = result.script;
                    showModal(scriptModal);
                } else {
                    alert('Erro ao gerar script: ' + (result.error || 'Erro desconhecido.'));
                }
            } catch (error) {
                console.error('Erro ao gerar script de rastreamento:', error);
                alert('Erro de comunicação com o servidor ao gerar o script.');
            }
        }
    });

    copyScriptBtn.addEventListener('click', function() {
        trackingScriptTextarea.select();
        document.execCommand('copy');
        const originalText = this.innerHTML;
        this.innerHTML = '<i data-lucide="check" class="w-5 h-5"></i><span>Copiado!</span>';
        lucide.createIcons();
        setTimeout(() => {
            this.innerHTML = originalText;
            lucide.createIcons();
        }, 2000);
    });

    closeModalBtns.forEach(btn => {
        btn.addEventListener('click', () => hideModal(scriptModal));
    });

    scriptModal.addEventListener('click', (e) => {
        if (e.target === scriptModal) hideModal(scriptModal);
    });

    // --- Lógica da Seção de Dados de Rastreamento ---
    trackedProductsList.addEventListener('click', async function(e) {
        const viewDataBtn = e.target.closest('.view-data-btn');
        if (viewDataBtn) {
            currentTrackingProductDbId = viewDataBtn.dataset.trackingProductDbId;
            const productName = viewDataBtn.dataset.productName;
            analyzedProductName.textContent = productName;
            
            trackingDataSection.style.display = 'block';
            await fetchTrackingData(currentTrackingProductDbId, currentPeriodFilter);
            trackingDataSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    });

    closeAnalysisBtn.addEventListener('click', function() {
        trackingDataSection.style.display = 'none';
        currentTrackingProductDbId = null;
        if (funnelChartInstance) {
            funnelChartInstance.destroy();
            funnelChartInstance = null;
        }
    });

            periodFilterButtons.forEach(btn => {
                btn.addEventListener('click', function() {
                    periodFilterButtons.forEach(b => {
                        b.classList.remove('bg-[#32e768]', 'text-white', 'shadow');
                        b.classList.add('text-gray-400', 'hover:bg-dark-card');
                    });
                    this.classList.add('bg-[#32e768]', 'text-white', 'shadow');
                    this.classList.remove('text-gray-400', 'hover:bg-dark-card');

            currentPeriodFilter = this.dataset.period;
            if (currentTrackingProductDbId) {
                fetchTrackingData(currentTrackingProductDbId, currentPeriodFilter);
            }
        });
    });

    async function fetchTrackingData(trackingProductDbId, period) {
        try {
            const response = await fetch(`/api/api.php?action=get_starfy_tracking_data&tracking_product_id=${trackingProductDbId}&period=${period}`);
            const result = await response.json();

            if (result.success) {
                const data = result.data;

                // Update KPIs
                kpiPageViews.textContent = data.funnel.page_views;
                kpiInitiateCheckouts.textContent = data.funnel.initiate_checkouts;
                kpiPurchases.textContent = data.funnel.purchases;

                conversionPageToCheckout.textContent = `${data.conversions.page_to_checkout}% de conversão (Página > Checkout)`;
                conversionCheckoutToPurchase.textContent = `${data.conversions.checkout_to_purchase}% de conversão (Checkout > Compra)`;
                conversionOverall.textContent = `${data.conversions.overall}%`;
                
                kpiClicksToSalePage.textContent = data.kpis.clicks_to_sale_page;
                kpiClicksToSaleCheckout.textContent = data.kpis.clicks_to_sale_checkout;

                kpiMainProductSalesCount.textContent = data.sales_summary.main_product_sales_count;
                kpiMainProductSalesValue.textContent = `Valor Total: ${formatCurrency(data.sales_summary.main_product_sales_value)}`;
                
                // Order Bumps
                orderBumpSalesList.innerHTML = '';
                if (data.sales_summary.order_bump_sales.length > 0) {
                    data.sales_summary.order_bump_sales.forEach(ob => {
                        const div = document.createElement('div');
                        div.className = 'flex items-center justify-between p-3 bg-dark-elevated rounded-md border border-dark-border';
                        div.innerHTML = `
                            <p class="font-medium text-white">${htmlspecialchars(ob.product_name)}</p>
                            <p class="text-sm text-gray-400">${ob.total_count} vendas - ${formatCurrency(ob.total_value)}</p>
                        `;
                        orderBumpSalesList.appendChild(div);
                    });
                } else {
                    orderBumpSalesList.innerHTML = '<p class="text-gray-400">Nenhuma venda de order bump neste período.</p>';
                }

                // Render Funnel Chart
                renderFunnelChart(data.funnel);

            } else {
                alert('Erro ao carregar dados de rastreamento: ' + (result.error || 'Erro desconhecido.'));
            }
        } catch (error) {
            console.error('Erro ao buscar dados de rastreamento:', error);
            alert('Erro de comunicação com o servidor ao buscar dados de rastreamento.');
        }
    }

    function renderFunnelChart(funnelData) {
        const ctx = document.getElementById('funnelChart').getContext('2d');
        if (funnelChartInstance) {
            funnelChartInstance.destroy();
        }

        funnelChartInstance = new Chart(ctx, {
            type: 'bar', // Pode ser 'bar' ou 'horizontalBar'
            data: {
                labels: ['Visitas à Página', 'Visitas ao Checkout', 'Compras'],
                datasets: [{
                    label: 'Contagem',
                    data: [funnelData.page_views, funnelData.initiate_checkouts, funnelData.purchases],
                    backgroundColor: [
                        'rgba(66, 133, 244, 0.7)', // Azul (Google-like)
                        'rgba(251, 188, 5, 0.7)',  // Amarelo (Google-like)
                        'rgba(52, 168, 83, 0.7)'   // Verde (Google-like)
                    ],
                    borderColor: [
                        'rgba(66, 133, 244, 1)',
                        'rgba(251, 188, 5, 1)',
                        'rgba(52, 168, 83, 1)'
                    ],
                    borderWidth: 1
                }]
            },
            options: {
                indexAxis: 'y', // Para barras horizontais
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    x: {
                        beginAtZero: true,
                        ticks: {
                            callback: function(value) { return value.toLocaleString('pt-BR'); }
                        }
                    },
                    y: {
                        grid: {
                            display: false
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                return `${context.label}: ${context.parsed.x.toLocaleString('pt-BR')}`;
                            }
                        }
                    }
                }
            }
        });
    }

    // --- Lógica para Excluir Funil ---
    trackedProductsList.addEventListener('click', async function(e) {
        const deleteBtn = e.target.closest('.delete-funnel-btn');
        if (deleteBtn) {
            const trackingProductDbId = deleteBtn.dataset.trackingProductDbId;
            const productName = deleteBtn.dataset.productName;

            if (confirm(`Tem certeza que deseja excluir o funil de rastreamento para o produto "${productName}"? Esta ação não pode ser desfeita.`)) {
                try {
                    const response = await fetch('/api/api.php?action=delete_starfy_tracked_product', {
                        method: 'POST',
                        headers: { 
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': window.csrfToken || ''
                        },
                        body: JSON.stringify({ 
                            tracking_product_db_id: trackingProductDbId,
                            csrf_token: window.csrfToken || ''
                        })
                    });
                    const result = await response.json();

                    if (result.success) {
                        alert(result.message);
                        await refreshTrackedProductsList(); // Recarrega a lista
                        // Se o funil excluído era o que estava sendo analisado, fecha a seção de análise
                        if (currentTrackingProductDbId === trackingProductDbId) {
                            closeAnalysisBtn.click();
                        }
                    } else {
                        alert('Erro ao excluir funil: ' + (result.error || 'Erro desconhecido.'));
                    }
                } catch (error) {
                    console.error('Erro ao excluir funil de rastreamento:', error);
                    alert('Erro de comunicação com o servidor.');
                }
            }
        }
    });

    // Helper para escapar HTML (já que estamos inserindo conteúdo dinâmico)
    function htmlspecialchars(str) {
        const div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }
});
</script>

