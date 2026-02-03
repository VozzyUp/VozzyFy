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

// Busca todos os produtos do infoprodutor para o dropdown de associação de webhook
try {
    $stmt_products = $pdo->prepare("SELECT id, nome FROM produtos WHERE usuario_id = :usuario_id ORDER BY nome ASC");
    $stmt_products->bindParam(':usuario_id', $usuario_id_logado, PDO::PARAM_INT);
    $stmt_products->execute();
    $infoprodutor_products = $stmt_products->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    $mensagem = "<div class='bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded relative mb-4' role='alert'>Erro ao buscar produtos para Webhooks: " . htmlspecialchars($e->getMessage()) . "</div>";
    $infoprodutor_products = [];
}
?>

<div class="container mx-auto">
    <div class="flex items-center mb-6">
        <a href="/index?pagina=integracoes" style="color: var(--accent-primary);" onmouseover="this.style.color='var(--accent-primary-hover)'" onmouseout="this.style.color='var(--accent-primary)'" class="mr-4">
            <i data-lucide="arrow-left-circle" class="w-8 h-8"></i>
        </a>
        <div>
            <h1 class="text-3xl font-bold text-white">Gerenciar Webhooks</h1>
            <p class="text-gray-400">Conecte-se a outras plataformas.</p>
        </div>
    </div>

    <?php echo $mensagem; ?>

    <!-- Formulário para Adicionar/Editar Webhook -->
    <div class="bg-dark-card p-8 rounded-lg shadow-md mb-8" style="border-color: var(--accent-primary);">
        <h2 id="form-title" class="text-2xl font-semibold text-white mb-6">Adicionar Novo Webhook</h2>
        <form id="webhook-form" class="space-y-6">
            <input type="hidden" name="webhook_id" id="webhook_id">
            
            <div>
                <label for="webhook_url" class="block text-gray-300 text-sm font-semibold mb-2">URL do Webhook</label>
                <input type="url" id="webhook_url" name="webhook_url" required
                       class="w-full px-4 py-3 bg-dark-elevated border border-dark-border rounded-lg focus:outline-none focus:ring-2 text-white" style="--tw-ring-color: var(--accent-primary);"
                       placeholder="Ex: https://webhook.site/abcdef-123456">
                <p class="text-xs text-gray-400 mt-1">Esta é a URL para onde os dados serão enviados.</p>
            </div>

            <div>
                <label for="produto_id" class="block text-gray-300 text-sm font-semibold mb-2">Produto Associado (Opcional)</label>
                <select id="produto_id" name="produto_id"
                        class="w-full px-4 py-3 bg-dark-elevated border border-dark-border rounded-lg focus:outline-none focus:ring-2 text-white" style="--tw-ring-color: var(--accent-primary);">
                    <option value="">Todos os produtos (Webhook Global)</option>
                    <?php foreach ($infoprodutor_products as $product): ?>
                        <option value="<?php echo $product['id']; ?>"><?php echo htmlspecialchars($product['nome']); ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="text-xs text-gray-400 mt-1">Selecione um produto para que este webhook seja acionado apenas para vendas dele. Deixe vazio para acionar para todos os produtos.</p>
            </div>

            <div>
                <h3 class="text-lg font-semibold text-white mb-3">Eventos que Disparam o Webhook</h3>
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
                </div>
            </div>

            <div class="flex items-center justify-end space-x-4 mt-6">
                <button type="button" id="cancel-edit-btn" class="hidden bg-dark-elevated text-gray-300 font-bold py-2 px-5 rounded-lg hover:bg-dark-card transition duration-300 border border-dark-border">
                    Cancelar Edição
                </button>
                <button type="submit" id="save-webhook-btn" class="text-white font-bold py-2 px-5 rounded-lg transition duration-300 flex items-center space-x-2" style="background-color: var(--accent-primary);" onmouseover="this.style.backgroundColor='var(--accent-primary-hover)'" onmouseout="this.style.backgroundColor='var(--accent-primary)'">
                    <i data-lucide="save" class="w-5 h-5"></i>
                    <span>Salvar Webhook</span>
                </button>
            </div>
        </form>
    </div>

    <!-- Lista de Webhooks Cadastrados -->
    <div class="bg-dark-card p-8 rounded-lg shadow-md" style="border-color: var(--accent-primary);">
        <h2 class="text-2xl font-semibold text-white mb-6">Webhooks Cadastrados</h2>
        
        <div id="loading-state" class="text-center py-12 text-gray-400" style="display: none;">
            <svg class="animate-spin h-8 w-8 mx-auto" style="color: var(--accent-primary);" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.96l2-2.669z"></path>
            </svg>
            <p class="mt-4 font-medium">Carregando webhooks...</p>
        </div>

        <div id="empty-state" class="text-center py-12 text-gray-400" style="display: none;">
            <i data-lucide="webhook" class="mx-auto w-16 h-16 text-gray-500 mb-2"></i>
            <p class="mt-4">Nenhum webhook cadastrado ainda.</p>
            <p class="text-sm">Use o formulário acima para adicionar um novo webhook.</p>
        </div>

        <div id="webhooks-list" class="space-y-4">
            <!-- Webhooks serão carregados aqui via JavaScript -->
        </div>
    </div>
</div>

<script>
// Token CSRF para requisições AJAX
window.csrfToken = '<?php echo htmlspecialchars($csrf_token_js); ?>';

document.addEventListener('DOMContentLoaded', function() {
    lucide.createIcons();

    const webhookForm = document.getElementById('webhook-form');
    const webhookIdInput = document.getElementById('webhook_id');
    const formTitle = document.getElementById('form-title');
    const webhookUrlInput = document.getElementById('webhook_url');
    const productIdSelect = document.getElementById('produto_id');
    const eventCheckboxes = document.querySelectorAll('#webhook-form input[type="checkbox"]');
    const saveWebhookBtn = document.getElementById('save-webhook-btn');
    const cancelEditBtn = document.getElementById('cancel-edit-btn');
    const webhooksList = document.getElementById('webhooks-list');
    const loadingState = document.getElementById('loading-state');
    const emptyState = document.getElementById('empty-state');

    // --- Funções Auxiliares de UI ---
    function showLoading() {
        webhooksList.style.display = 'none';
        emptyState.style.display = 'none';
        loadingState.style.display = 'block';
    }

    function showEmptyState() {
        webhooksList.style.display = 'none';
        loadingState.style.display = 'none';
        emptyState.style.display = 'block';
    }

    function showWebhooksList() {
        loadingState.style.display = 'none';
        emptyState.style.display = 'none';
        webhooksList.style.display = 'block';
    }

    function resetForm() {
        webhookForm.reset();
        webhookIdInput.value = '';
        formTitle.textContent = 'Adicionar Novo Webhook';
        saveWebhookBtn.innerHTML = '<i data-lucide="save" class="w-5 h-5"></i> <span>Salvar Webhook</span>';
        cancelEditBtn.classList.add('hidden');
        lucide.createIcons();
    }

    function populateFormForEdit(webhook) {
        formTitle.textContent = 'Editar Webhook';
        webhookIdInput.value = webhook.id;
        webhookUrlInput.value = webhook.url;
        productIdSelect.value = webhook.produto_id || ''; // Se for null, seleciona a opção "Todos os produtos"
        
        eventCheckboxes.forEach(checkbox => {
            const eventName = checkbox.name.replace('event_', '');
            checkbox.checked = webhook[`event_${eventName}`] === 1;
        });

        saveWebhookBtn.innerHTML = '<i data-lucide="save" class="w-5 h-5"></i> <span>Atualizar Webhook</span>';
        cancelEditBtn.classList.remove('hidden');
        lucide.createIcons();
        window.scrollTo({ top: 0, behavior: 'smooth' }); // Rola para o topo do formulário
    }

    function getEventStatus(webhook) {
        const activeEvents = [];
        if (webhook.event_approved) activeEvents.push('Aprovada');
        if (webhook.event_pending) activeEvents.push('Pendente');
        if (webhook.event_rejected) activeEvents.push('Recusada');
        if (webhook.event_refunded) activeEvents.push('Reembolso');
        if (webhook.event_charged_back) activeEvents.push('Chargeback');
        return activeEvents.length > 0 ? activeEvents.join(', ') : 'Nenhum';
    }

    // --- Lógica de Comunicação com a API ---
    async function fetchWebhooks() {
        showLoading();
        try {
            const response = await fetch('/api/api.php?action=get_webhooks');
            const result = await response.json();

            if (result.success) {
                webhooksList.innerHTML = ''; // Limpa a lista antes de preencher
                if (result.webhooks.length === 0) {
                    showEmptyState();
                } else {
                    result.webhooks.forEach(webhook => {
                        const webhookItem = document.createElement('div');
                        webhookItem.className = 'bg-dark-elevated p-4 rounded-lg border border-dark-border flex flex-col sm:flex-row justify-between items-start sm:items-center';
                        
                        const produtoNome = webhook.produto_nome ? htmlspecialchars(webhook.produto_nome) : '<span class="text-gray-400">(Todos os produtos)</span>';
                        const activeEvents = getEventStatus(webhook);

                        webhookItem.innerHTML = `
                            <div class="flex-1 mb-2 sm:mb-0">
                                <p class="font-semibold text-white text-lg break-all" title="${htmlspecialchars(webhook.url)}">${htmlspecialchars(webhook.url)}</p>
                                <p class="text-sm text-gray-400 mt-1">Produto: ${produtoNome}</p>
                                <p class="text-sm text-gray-400">Eventos: ${activeEvents}</p>
                            </div>
                            <div class="flex space-x-2 mt-2 sm:mt-0 flex-shrink-0">
                                <button type="button" class="test-webhook-btn bg-blue-900/30 text-blue-300 font-semibold py-2 px-4 rounded-lg hover:bg-blue-900/50 transition text-sm flex items-center space-x-1 border border-blue-500/30" data-url="${htmlspecialchars(webhook.url)}">
                                    <i data-lucide="send" class="w-4 h-4"></i>
                                    <span>Testar</span>
                                </button>
                                <button type="button" class="edit-webhook-btn bg-yellow-900/30 text-yellow-300 font-semibold py-2 px-4 rounded-lg hover:bg-yellow-900/50 transition text-sm flex items-center space-x-1 border border-yellow-500/30" data-webhook-id="${webhook.id}">
                                    <i data-lucide="edit" class="w-4 h-4"></i>
                                    <span>Editar</span>
                                </button>
                                <button type="button" class="delete-webhook-btn bg-red-900/30 text-red-300 font-semibold py-2 px-4 rounded-lg hover:bg-red-900/50 transition text-sm flex items-center space-x-1 border border-red-500/30" data-webhook-id="${webhook.id}">
                                    <i data-lucide="trash-2" class="w-4 h-4"></i>
                                    <span>Excluir</span>
                                </button>
                            </div>
                        `;
                        webhooksList.appendChild(webhookItem);
                    });
                    lucide.createIcons();
                    showWebhooksList();
                }
            } else {
                alert('Erro ao carregar webhooks: ' + (result.error || 'Erro desconhecido.'));
                showEmptyState();
            }
        } catch (error) {
            console.error('Erro ao buscar webhooks:', error);
            alert('Erro de comunicação com o servidor ao carregar webhooks.');
            showEmptyState();
        }
    }

    async function saveWebhook(action, webhookData) {
        try {
            // Adicionar token CSRF aos dados
            webhookData.csrf_token = window.csrfToken || '';
            
            const response = await fetch(`/api/api.php?action=${action}`, {
                method: 'POST',
                headers: { 
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': window.csrfToken || ''
                },
                body: JSON.stringify(webhookData),
            });
            const result = await response.json();

            if (result.success) {
                alert(result.message);
                resetForm();
                fetchWebhooks();
            } else {
                alert('Erro: ' + (result.error || 'Não foi possível salvar o webhook.'));
            }
        } catch (error) {
            console.error('Erro ao salvar webhook:', error);
            alert('Erro de comunicação com o servidor ao salvar o webhook.');
        }
    }

    async function deleteWebhook(webhookId) {
        if (!confirm('Tem certeza que deseja excluir este webhook? Esta ação não pode ser desfeita.')) {
            return;
        }
        try {
            const response = await fetch('/api/api.php?action=delete_webhook', {
                method: 'POST',
                headers: { 
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': window.csrfToken || ''
                },
                body: JSON.stringify({ 
                    id: webhookId,
                    csrf_token: window.csrfToken || ''
                }),
            });
            const result = await response.json();

            if (result.success) {
                alert(result.message);
                fetchWebhooks();
            } else {
                alert('Erro: ' + (result.error || 'Não foi possível excluir o webhook.'));
            }
        } catch (error) {
            console.error('Erro ao excluir webhook:', error);
            alert('Erro de comunicação com o servidor ao excluir o webhook.');
        }
    }

    async function testWebhook(url) {
        alert('Enviando evento de teste para: ' + url + '. Verifique o log da sua ferramenta de webhook.');
        try {
            const response = await fetch('/api/api.php?action=test_webhook', {
                method: 'POST',
                headers: { 
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': window.csrfToken || ''
                },
                body: JSON.stringify({ 
                    url: url,
                    csrf_token: window.csrfToken || ''
                }),
            });
            const result = await response.json();

            if (result.success) {
                alert('Teste de webhook enviado com sucesso! Resposta: ' + result.message);
            } else {
                alert('Falha no teste de webhook: ' + (result.error || 'Erro desconhecido.'));
            }
        } catch (error) {
            console.error('Erro ao testar webhook:', error);
            alert('Erro de comunicação com o servidor ao testar o webhook.');
        }
    }

    // --- Event Listeners ---
    webhookForm.addEventListener('submit', function(e) {
        e.preventDefault();

        const webhookData = {
            url: webhookUrlInput.value,
            produto_id: productIdSelect.value === '' ? null : parseInt(productIdSelect.value),
            events: {}
        };

        eventCheckboxes.forEach(checkbox => {
            const eventName = checkbox.name.replace('event_', '');
            webhookData.events[eventName] = checkbox.checked ? 1 : 0;
        });

        if (webhookIdInput.value) { // Edição
            webhookData.id = parseInt(webhookIdInput.value);
            saveWebhook('update_webhook', webhookData);
        } else { // Criação
            saveWebhook('create_webhook', webhookData);
        }
    });

    cancelEditBtn.addEventListener('click', resetForm);

    webhooksList.addEventListener('click', function(e) {
        const editBtn = e.target.closest('.edit-webhook-btn');
        const deleteBtn = e.target.closest('.delete-webhook-btn');
        const testBtn = e.target.closest('.test-webhook-btn');

        if (editBtn) {
            const webhookId = parseInt(editBtn.dataset.webhookId);
            const webhookToEdit = Array.from(webhooksList.children).find(item => 
                item.querySelector('.edit-webhook-btn')?.dataset.webhookId == webhookId
            );
            
            if (webhookToEdit) {
                const url = webhookToEdit.querySelector('.font-semibold.text-lg').textContent;
                const produtoElement = webhookToEdit.querySelector('p:nth-child(2)');
                const produtoIdMatch = produtoElement.textContent.match(/Produto: (.*)/);
                
                const productId = produtoIdMatch && produtoIdMatch[1] !== '(Todos os produtos)' 
                    ? productIdSelect.options[Array.from(productIdSelect.options).findIndex(option => option.textContent === produtoIdMatch[1])]?.value
                    : '';

                const eventsText = webhookToEdit.querySelector('p:nth-child(3)').textContent;
                const events = {
                    approved: eventsText.includes('Aprovada') ? 1 : 0,
                    pending: eventsText.includes('Pendente') ? 1 : 0,
                    rejected: eventsText.includes('Recusada') ? 1 : 0,
                    refunded: eventsText.includes('Reembolso') ? 1 : 0,
                    charged_back: eventsText.includes('Chargeback') ? 1 : 0
                };
                
                populateFormForEdit({ id: webhookId, url: url, produto_id: productId, ...events });
            }
        } else if (deleteBtn) {
            deleteWebhook(parseInt(deleteBtn.dataset.webhookId));
        } else if (testBtn) {
            testWebhook(testBtn.dataset.url);
        }
    });

    // Helper para escapar HTML
    function htmlspecialchars(str) {
        const div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    // Carga inicial dos webhooks
    fetchWebhooks();
});
</script>