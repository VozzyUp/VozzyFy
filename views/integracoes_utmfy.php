<?php
// Este arquivo é incluído a partir do index.php,
// então a verificação de login e a conexão com o banco ($pdo) já existem.

// Gerar token CSRF
require_once __DIR__ . '/../helpers/security_helper.php';
$csrf_token_js = generate_csrf_token();

// Obter o ID do usuário logado
$usuario_id_logado = $_SESSION['id'] ?? 0;

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

// Busca todos os produtos do infoprodutor para o dropdown de associação de integração UTMfy
try {
    $stmt_products = $pdo->prepare("SELECT id, nome FROM produtos WHERE usuario_id = :usuario_id ORDER BY nome ASC");
    $stmt_products->bindParam(':usuario_id', $usuario_id_logado, PDO::PARAM_INT);
    $stmt_products->execute();
    $infoprodutor_products = $stmt_products->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $mensagem = "<div class='bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded relative mb-4' role='alert'>Erro ao buscar produtos para UTMfy: " . htmlspecialchars($e->getMessage()) . "</div>";
    $infoprodutor_products = [];
}
?>

<div class="container mx-auto">
    <div class="flex items-center mb-6">
        <a href="/index?pagina=integracoes" style="color: var(--accent-primary);" onmouseover="this.style.color='var(--accent-primary-hover)'" onmouseout="this.style.color='var(--accent-primary)'" class="mr-4">
            <i data-lucide="arrow-left-circle" class="w-8 h-8"></i>
        </a>
        <div>
            <h1 class="text-3xl font-bold text-white">Integrar com UTMfy</h1>
            <p class="text-gray-400">Conecte-se à UTMfy para enviar notificações de vendas e rastrear links.</p>
        </div>
    </div>

    <?php echo $mensagem; ?>

    <!-- Formulário para Adicionar/Editar Integração UTMfy -->
    <div class="bg-dark-card p-8 rounded-lg shadow-md mb-8" style="border-color: var(--accent-primary);">
        <h2 id="form-title" class="text-2xl font-semibold text-white mb-6">Adicionar Nova Integração UTMfy</h2>
        <form id="utmfy-form" class="space-y-6">
            <input type="hidden" name="utmfy_integration_id" id="utmfy_integration_id">
            
            <div>
                <label for="integration_name" class="block text-gray-300 text-sm font-semibold mb-2">Nome da Integração</label>
                <input type="text" id="integration_name" name="integration_name" required
                       class="w-full px-4 py-3 bg-dark-elevated border border-dark-border rounded-lg focus:outline-none focus:ring-2 text-white" style="--tw-ring-color: var(--accent-primary);"
                       placeholder="Ex: Campanha de Lançamento - Curso X">
                <p class="text-xs text-gray-400 mt-1">Um nome amigável para identificar esta integração.</p>
            </div>

            <div>
                <label for="api_token" class="block text-gray-300 text-sm font-semibold mb-2">API Token da UTMfy</label>
                <input type="text" id="api_token" name="api_token" required
                       class="w-full px-4 py-3 bg-dark-elevated border border-dark-border rounded-lg focus:outline-none focus:ring-2 text-white" style="--tw-ring-color: var(--accent-primary);"
                       placeholder="Cole seu API Token da UTMfy aqui">
                <p class="text-xs text-gray-400 mt-1">Este token é fornecido pela plataforma UTMfy. Para obtê-lo, acesse o painel da UTMfy em Integrações > Webhooks > Credenciais de API.</p>
            </div>

            <div>
                <label for="product_id" class="block text-gray-300 text-sm font-semibold mb-2">Produto Associado (Opcional)</label>
                <select id="product_id" name="product_id"
                        class="w-full px-4 py-3 bg-dark-elevated border border-dark-border rounded-lg focus:outline-none focus:ring-2 text-white" style="--tw-ring-color: var(--accent-primary);">
                    <option value="">Todos os produtos (Integração Global)</option>
                    <?php foreach ($infoprodutor_products as $product): ?>
                        <option value="<?php echo $product['id']; ?>"><?php echo htmlspecialchars($product['nome']); ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="text-xs text-gray-400 mt-1">Selecione um produto para que a UTMfy seja notificada apenas para vendas dele. Deixe vazio para notificar para todos os produtos.</p>
            </div>

            <div>
                <h3 class="text-lg font-semibold text-white mb-3">Eventos que Disparam a Notificação para UTMfy</h3>
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
                    <label class="inline-flex items-center cursor-pointer">
                        <input type="checkbox" name="event_approved" class="form-checkbox h-5 w-5 rounded" style="--tw-ring-color: var(--accent-primary); color: var(--accent-primary);">
                        <span class="ml-2 text-gray-300">Compra Aprovada</span>
                    </label>
                    <label class="inline-flex items-center cursor-pointer">
                        <input type="checkbox" name="event_pending" class="form-checkbox h-5 w-5 text-yellow-400 rounded" style="--tw-ring-color: var(--accent-primary);">
                        <span class="ml-2 text-gray-300">Pagamento Pendente</span>
                    </label>
                    <label class="inline-flex items-center cursor-pointer">
                        <input type="checkbox" name="event_rejected" class="form-checkbox h-5 w-5 text-red-400 rounded" style="--tw-ring-color: var(--accent-primary);">
                        <span class="ml-2 text-gray-300">Pagamento Recusado</span>
                    </label>
                    <label class="inline-flex items-center cursor-pointer">
                        <input type="checkbox" name="event_refunded" class="form-checkbox h-5 w-5 text-purple-400 rounded" style="--tw-ring-color: var(--accent-primary);">
                        <span class="ml-2 text-gray-300">Reembolso</span>
                    </label>
                    <label class="inline-flex items-center cursor-pointer">
                        <input type="checkbox" name="event_charged_back" class="form-checkbox h-5 w-5 text-pink-400 rounded" style="--tw-ring-color: var(--accent-primary);">
                        <span class="ml-2 text-gray-300">Chargeback</span>
                    </label>
                    <label class="inline-flex items-center cursor-pointer">
                        <input type="checkbox" name="event_initiate_checkout" class="form-checkbox h-5 w-5 text-indigo-400 rounded" style="--tw-ring-color: var(--accent-primary);">
                        <span class="ml-2 text-gray-300">Carrinho Abandonado (InitiateCheckout)</span>
                    </label>
                </div>
            </div>

            <div class="flex items-center justify-end space-x-4 mt-6">
                <button type="button" id="cancel-edit-btn" class="hidden bg-dark-elevated text-gray-300 font-bold py-2 px-5 rounded-lg hover:bg-dark-card transition duration-300 border border-dark-border">
                    Cancelar Edição
                </button>
                <button type="submit" id="save-utmfy-btn" class="text-white font-bold py-2 px-5 rounded-lg transition duration-300 flex items-center space-x-2" style="background-color: var(--accent-primary);" onmouseover="this.style.backgroundColor='var(--accent-primary-hover)'" onmouseout="this.style.backgroundColor='var(--accent-primary)'">
                    <i data-lucide="save" class="w-5 h-5"></i>
                    <span>Salvar Integração</span>
                </button>
            </div>
        </form>
    </div>

    <!-- Lista de Integrações UTMfy Cadastradas -->
    <div class="bg-dark-card p-8 rounded-lg shadow-md" style="border-color: var(--accent-primary);">
        <h2 class="text-2xl font-semibold text-white mb-6">Integrações UTMfy Cadastradas</h2>
        
        <div id="loading-state" class="text-center py-12 text-gray-400" style="display: none;">
            <svg class="animate-spin h-8 w-8 mx-auto" style="color: var(--accent-primary);" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.96l2-2.669z"></path>
            </svg>
            <p class="mt-4 font-medium">Carregando integrações...</p>
        </div>

        <div id="empty-state" class="text-center py-12 text-gray-400" style="display: none;">
            <i data-lucide="link" class="mx-auto w-16 h-16 text-gray-500 mb-2"></i>
            <p class="mt-4">Nenhuma integração UTMfy cadastrada ainda.</p>
            <p class="text-sm">Use o formulário acima para adicionar uma nova integração.</p>
        </div>

        <div id="utmfy-list" class="space-y-4">
            <!-- Integrações serão carregadas aqui via JavaScript -->
        </div>
    </div>
</div>

<script>
// Token CSRF para requisições AJAX
window.csrfToken = '<?php echo htmlspecialchars($csrf_token_js); ?>';

document.addEventListener('DOMContentLoaded', function() {
    lucide.createIcons();

    const utmfyForm = document.getElementById('utmfy-form');
    const utmfyIntegrationIdInput = document.getElementById('utmfy_integration_id');
    const formTitle = document.getElementById('form-title');
    const integrationNameInput = document.getElementById('integration_name');
    const apiTokenInput = document.getElementById('api_token');
    const productIdSelect = document.getElementById('product_id');
    const eventCheckboxes = document.querySelectorAll('#utmfy-form input[type="checkbox"]');
    const saveUtmfyBtn = document.getElementById('save-utmfy-btn');
    const cancelEditBtn = document.getElementById('cancel-edit-btn');
    const utmfyList = document.getElementById('utmfy-list');
    const loadingState = document.getElementById('loading-state');
    const emptyState = document.getElementById('empty-state');

    let cachedIntegrations = []; // Cache to store fetched integrations for editing

    // --- Funções Auxiliares de UI ---
    function showLoading() {
        utmfyList.style.display = 'none';
        emptyState.style.display = 'none';
        loadingState.style.display = 'block';
    }

    function showEmptyState() {
        utmfyList.style.display = 'none';
        loadingState.style.display = 'none';
        emptyState.style.display = 'block';
    }

    function showUtmfyList() {
        loadingState.style.display = 'none';
        emptyState.style.display = 'none';
        utmfyList.style.display = 'block';
    }

    function resetForm() {
        utmfyForm.reset();
        utmfyIntegrationIdInput.value = '';
        formTitle.textContent = 'Adicionar Nova Integração UTMfy';
        saveUtmfyBtn.innerHTML = '<i data-lucide="save" class="w-5 h-5"></i> <span>Salvar Integração</span>';
        cancelEditBtn.classList.add('hidden');
        lucide.createIcons();
    }

    function populateFormForEdit(integration) {
        formTitle.textContent = 'Editar Integração UTMfy';
        utmfyIntegrationIdInput.value = integration.id;
        integrationNameInput.value = integration.name;
        apiTokenInput.value = integration.api_token;
        productIdSelect.value = integration.product_id || ''; // Se for null, seleciona a opção "Todos os produtos"
        
        eventCheckboxes.forEach(checkbox => {
            const eventName = checkbox.name.replace('event_', '');
            checkbox.checked = integration[`event_${eventName}`] === 1;
        });

        saveUtmfyBtn.innerHTML = '<i data-lucide="save" class="w-5 h-5"></i> <span>Atualizar Integração</span>';
        cancelEditBtn.classList.remove('hidden');
        lucide.createIcons();
        window.scrollTo({ top: 0, behavior: 'smooth' }); // Rola para o topo do formulário
    }

    function getEventStatusText(integration) {
        const activeEvents = [];
        if (integration.event_approved) activeEvents.push('Aprovada');
        if (integration.event_pending) activeEvents.push('Pendente');
        if (integration.event_rejected) activeEvents.push('Recusada');
        if (integration.event_refunded) activeEvents.push('Reembolso');
        if (integration.event_charged_back) activeEvents.push('Chargeback');
        if (integration.event_initiate_checkout) activeEvents.push('Carrinho Abandonado'); // Adicionado o novo evento
        return activeEvents.length > 0 ? activeEvents.join(', ') : 'Nenhum';
    }

    // --- Lógica de Comunicação com a API ---
    async function fetchUtmfyIntegrations() {
        showLoading();
        try {
            const response = await fetch('/api/api.php?action=get_utmfy_integrations');
            const result = await response.json();

            if (result.success) {
                cachedIntegrations = result.integrations; // Cache the fetched data
                utmfyList.innerHTML = ''; // Limpa a lista antes de preencher
                if (cachedIntegrations.length === 0) {
                    showEmptyState();
                } else {
                    cachedIntegrations.forEach(integration => {
                        const integrationItem = document.createElement('div');
                        integrationItem.className = 'bg-dark-elevated p-4 rounded-lg border border-dark-border flex flex-col sm:flex-row justify-between items-start sm:items-center';
                        
                        const productName = integration.product_name ? htmlspecialchars(integration.product_name) : '<span class="text-gray-400">(Todos os produtos)</span>';
                        const activeEvents = getEventStatusText(integration);

                        integrationItem.innerHTML = `
                            <div class="flex-1 mb-2 sm:mb-0">
                                <p class="font-semibold text-white text-lg">${htmlspecialchars(integration.name)}</p>
                                <p class="text-sm text-gray-400 mt-1">API Token: <span class="font-mono text-gray-300">${htmlspecialchars(integration.api_token).substring(0, 10)}...</span></p>
                                <p class="text-sm text-gray-400">Produto: ${productName}</p>
                                <p class="text-sm text-gray-400">Eventos: ${activeEvents}</p>
                            </div>
                            <div class="flex space-x-2 mt-2 sm:mt-0 flex-shrink-0">
                                <button type="button" class="edit-utmfy-btn bg-yellow-900/30 text-yellow-300 font-semibold py-2 px-4 rounded-lg hover:bg-yellow-900/50 transition text-sm flex items-center space-x-1 border border-yellow-500/30" data-utmfy-id="${integration.id}">
                                    <i data-lucide="edit" class="w-4 h-4"></i>
                                    <span>Editar</span>
                                </button>
                                <button type="button" class="delete-utmfy-btn bg-red-900/30 text-red-300 font-semibold py-2 px-4 rounded-lg hover:bg-red-900/50 transition text-sm flex items-center space-x-1 border border-red-500/30" data-utmfy-id="${integration.id}">
                                    <i data-lucide="trash-2" class="w-4 h-4"></i>
                                    <span>Excluir</span>
                                </button>
                            </div>
                        `;
                        utmfyList.appendChild(integrationItem);
                    });
                    lucide.createIcons();
                    showUtmfyList();
                }
            } else {
                alert('Erro ao carregar integrações UTMfy: ' + (result.error || 'Erro desconhecido.'));
                showEmptyState();
            }
        } catch (error) {
            console.error('Erro ao buscar integrações UTMfy:', error);
            alert('Erro de comunicação com o servidor ao carregar integrações UTMfy.');
            showEmptyState();
        }
    }

    async function saveUtmfyIntegration(action, integrationData) {
        try {
            // Verificar se token CSRF está disponível
            if (!window.csrfToken || window.csrfToken === '') {
                console.error('Token CSRF não disponível');
                alert('Erro: Token de segurança não encontrado. Por favor, recarregue a página e tente novamente.');
                return;
            }
            
            // Adicionar token CSRF aos dados
            integrationData.csrf_token = window.csrfToken;
            
            const response = await fetch(`/api/api.php?action=${action}`, {
                method: 'POST',
                headers: { 
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': window.csrfToken
                },
                body: JSON.stringify(integrationData),
            });
            const result = await response.json();

            if (result.success) {
                alert(result.message);
                resetForm();
                fetchUtmfyIntegrations();
            } else {
                alert('Erro: ' + (result.error || 'Não foi possível salvar a integração.'));
            }
        } catch (error) {
            console.error('Erro ao salvar integração UTMfy:', error);
            alert('Erro de comunicação com o servidor ao salvar a integração.');
        }
    }

    async function deleteUtmfyIntegration(integrationId) {
        if (!confirm('Tem certeza que deseja excluir esta integração UTMfy? Esta ação não pode ser desfeita.')) {
            return;
        }
        
        // Verificar se token CSRF está disponível
        if (!window.csrfToken || window.csrfToken === '') {
            console.error('Token CSRF não disponível');
            alert('Erro: Token de segurança não encontrado. Por favor, recarregue a página e tente novamente.');
            return;
        }
        
        try {
            const response = await fetch('/api/api.php?action=delete_utmfy_integration', {
                method: 'POST',
                headers: { 
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': window.csrfToken
                },
                body: JSON.stringify({ 
                    id: integrationId,
                    csrf_token: window.csrfToken
                }),
            });
            const result = await response.json();

            if (result.success) {
                alert(result.message);
                fetchUtmfyIntegrations();
            } else {
                alert('Erro: ' + (result.error || 'Não foi possível excluir a integração.'));
            }
        } catch (error) {
            console.error('Erro ao excluir integração UTMfy:', error);
            alert('Erro de comunicação com o servidor ao excluir a integração.');
        }
    }

    // --- Event Listeners ---
    utmfyForm.addEventListener('submit', function(e) {
        e.preventDefault();

        const integrationData = {
            name: integrationNameInput.value,
            api_token: apiTokenInput.value,
            product_id: productIdSelect.value === '' ? null : parseInt(productIdSelect.value),
            events: {}
        };

        eventCheckboxes.forEach(checkbox => {
            const eventName = checkbox.name.replace('event_', '');
            integrationData.events[eventName] = checkbox.checked ? 1 : 0;
        });

        if (utmfyIntegrationIdInput.value) { // Edição
            integrationData.id = parseInt(utmfyIntegrationIdInput.value); 
            saveUtmfyIntegration('update_utmfy_integration', integrationData);
        } else { // Criação
            saveUtmfyIntegration('create_utmfy_integration', integrationData);
        }
    });

    cancelEditBtn.addEventListener('click', resetForm);

    utmfyList.addEventListener('click', async function(e) {
        const editBtn = e.target.closest('.edit-utmfy-btn');
        const deleteBtn = e.target.closest('.delete-utmfy-btn');

        if (editBtn) {
            const integrationId = parseInt(editBtn.dataset.utmfyId);
            const integrationToEdit = cachedIntegrations.find(integration => integration.id === integrationId);
            
            if (integrationToEdit) {
                populateFormForEdit(integrationToEdit);
            } else {
                alert('Integração não encontrada para edição.');
            }
        } else if (deleteBtn) {
            deleteUtmfyIntegration(parseInt(deleteBtn.dataset.utmfyId));
        }
    });

    // Helper para escapar HTML
    function htmlspecialchars(str) {
        const div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    // Carga inicial das integrações
    fetchUtmfyIntegrations();
});
</script>