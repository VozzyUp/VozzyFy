<?php
// Busca produtos do infoprodutor que são área de membros
$usuario_id = $_SESSION['id'] ?? 0;
$produtos_area_membros = [];

try {
    $stmt_produtos = $pdo->prepare("
        SELECT id, nome 
        FROM produtos 
        WHERE usuario_id = ? AND tipo_entrega = 'area_membros'
        ORDER BY nome ASC
    ");
    $stmt_produtos->execute([$usuario_id]);
    $produtos_area_membros = $stmt_produtos->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Erro ao buscar produtos: " . $e->getMessage());
}
?>

<div class="container mx-auto relative">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-3xl font-bold text-white">Gerenciar Alunos</h1>
        <button id="btn-criar-aluno" class="hidden px-4 py-2 rounded-lg font-semibold text-white transition-all duration-300 flex items-center gap-2" style="background-color: var(--accent-primary);" onmouseover="this.style.backgroundColor='var(--accent-primary-hover)'" onmouseout="this.style.backgroundColor='var(--accent-primary)'">
            <i data-lucide="user-plus" class="w-5 h-5"></i>
            Criar Aluno
        </button>
    </div>

    <!-- Seletor de Produto -->
    <div class="bg-dark-card p-6 rounded-lg shadow-md border mb-6" style="border-color: var(--accent-primary);">
        <label for="produto-select" class="block text-sm font-medium text-gray-300 mb-2">
            <i data-lucide="package" class="w-4 h-4 inline mr-2"></i>
            Selecione o Produto
        </label>
        <select id="produto-select" class="block w-full px-4 py-3 border border-dark-border rounded-lg bg-dark-elevated text-white focus:outline-none focus:ring-2 focus:ring-opacity-50" style="focus:ring-color: var(--accent-primary);">
            <option value="">-- Selecione um produto --</option>
            <?php foreach ($produtos_area_membros as $produto): ?>
                <option value="<?php echo $produto['id']; ?>"><?php echo htmlspecialchars($produto['nome']); ?></option>
            <?php endforeach; ?>
        </select>
        <?php if (empty($produtos_area_membros)): ?>
            <p class="mt-4 text-sm text-gray-400">
                <i data-lucide="info" class="w-4 h-4 inline mr-2"></i>
                Você ainda não possui produtos configurados como "Área de Membros". 
                <a href="/index?pagina=produtos" class="text-blue-400 hover:underline">Criar produto</a>
            </p>
        <?php endif; ?>
    </div>

    <!-- Listagem de Alunos -->
    <div id="alunos-container" class="hidden">
        <div class="bg-dark-card p-6 rounded-lg shadow-md border mb-4" style="border-color: var(--accent-primary);">
            <div class="mb-4">
                <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <i data-lucide="search" class="w-5 h-5 text-gray-400"></i>
                    </div>
                    <input type="text" id="search-input" class="block w-full pl-10 pr-3 py-2 border border-dark-border rounded-md leading-5 bg-dark-elevated placeholder-gray-500 text-white focus:outline-none focus:placeholder-gray-400 focus:ring-1 sm:text-sm" style="--tw-ring-color: var(--accent-primary);" onfocus="this.style.borderColor='var(--accent-primary)'; this.style.boxShadow='0 0 0 1px var(--accent-primary)'" onblur="this.style.borderColor='rgba(255,255,255,0.1)'; this.style.boxShadow='none'" placeholder="Pesquisar por nome ou email...">
                </div>
            </div>

            <!-- Tabela Desktop -->
            <div class="hidden md:block overflow-x-auto">
                <table class="min-w-full divide-y divide-dark-border">
                    <thead class="bg-dark-elevated">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Aluno</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Data de Acesso</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Progresso</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Ações</th>
                        </tr>
                    </thead>
                    <tbody id="alunos-tbody" class="bg-dark-card divide-y divide-dark-border"></tbody>
                </table>
            </div>

            <!-- Cards Mobile -->
            <div id="alunos-cards-mobile" class="md:hidden space-y-4"></div>

            <div id="loading-state" class="text-center py-12 text-gray-400" style="display: none;">
                <svg class="animate-spin h-8 w-8 mx-auto" style="color: var(--accent-primary);" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.96l2-2.669z"></path>
                </svg>
                <p class="mt-4 font-medium">Carregando...</p>
            </div>

            <div id="empty-state" class="text-center py-12 text-gray-400" style="display: none;">
                <i data-lucide="users" class="mx-auto w-16 h-16 text-gray-500"></i>
                <p class="mt-4">Nenhum aluno encontrado para este produto.</p>
            </div>
        </div>
    </div>

    <!-- Mensagem quando nenhum produto selecionado -->
    <div id="no-product-selected" class="bg-dark-card p-8 rounded-lg shadow-md border text-center" style="border-color: var(--accent-primary); display: <?php echo empty($produtos_area_membros) ? 'none' : 'block'; ?>;">
        <i data-lucide="package-search" class="mx-auto w-16 h-16 text-gray-500 mb-4"></i>
        <p class="text-gray-400 text-lg">Selecione um produto acima para visualizar os alunos</p>
    </div>
</div>

<!-- MODAL DE CRIAR ALUNO -->
<div id="create-modal" class="fixed inset-0 z-50 hidden overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
    <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
        <div class="fixed inset-0 bg-gray-900 bg-opacity-75 transition-opacity" aria-hidden="true"></div>
        <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
        <div class="inline-block align-bottom bg-dark-card rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full border" style="border-color: var(--accent-primary);">
            <div class="bg-dark-card px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                <div class="sm:flex sm:items-start">
                    <div class="mx-auto flex-shrink-0 flex items-center justify-center h-12 w-12 rounded-full sm:mx-0 sm:h-10 sm:w-10" style="background-color: color-mix(in srgb, var(--accent-primary) 20%, transparent);">
                        <i data-lucide="user-plus" class="h-6 w-6" style="color: var(--accent-primary);"></i>
                    </div>
                    <div class="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left w-full">
                        <h3 class="text-lg leading-6 font-medium text-white" id="modal-title">Criar Novo Aluno</h3>
                        <div class="mt-4 space-y-4">
                            <div>
                                <label for="create-email" class="block text-sm font-medium text-gray-300">E-mail *</label>
                                <input type="email" id="create-email" class="mt-1 focus:ring-primary focus:border-primary block w-full shadow-sm sm:text-sm border-dark-border rounded-md border p-2 bg-dark-elevated text-white placeholder-gray-500" placeholder="aluno@email.com" required>
                            </div>
                            <div>
                                <label for="create-nome" class="block text-sm font-medium text-gray-300">Nome *</label>
                                <input type="text" id="create-nome" class="mt-1 focus:ring-primary focus:border-primary block w-full shadow-sm sm:text-sm border-dark-border rounded-md border p-2 bg-dark-elevated text-white placeholder-gray-500" placeholder="Nome completo" required>
                            </div>
                            <div>
                                <label for="create-produto" class="block text-sm font-medium text-gray-300">Produto *</label>
                                <select id="create-produto" class="mt-1 focus:ring-primary focus:border-primary block w-full shadow-sm sm:text-sm border-dark-border rounded-md border p-2 bg-dark-elevated text-white" required>
                                    <option value="">-- Selecione --</option>
                                    <?php foreach ($produtos_area_membros as $produto): ?>
                                        <option value="<?php echo $produto['id']; ?>"><?php echo htmlspecialchars($produto['nome']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="flex items-center">
                                <input type="checkbox" id="create-enviar-email" class="w-4 h-4 rounded border-dark-border bg-dark-elevated text-primary focus:ring-primary" checked>
                                <label for="create-enviar-email" class="ml-2 text-sm text-gray-300">Enviar email de acesso automaticamente</label>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="bg-dark-elevated px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
                <button type="button" id="confirm-create-btn" class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 text-base font-medium text-white focus:outline-none focus:ring-2 focus:ring-offset-2 sm:ml-3 sm:w-auto sm:text-sm" style="background-color: var(--accent-primary);" onmouseover="this.style.backgroundColor='var(--accent-primary-hover)'" onmouseout="this.style.backgroundColor='var(--accent-primary)'">
                    Criar e Enviar Acesso
                </button>
                <button type="button" id="cancel-create-btn" class="mt-3 w-full inline-flex justify-center rounded-md border border-dark-border shadow-sm px-4 py-2 bg-dark-card text-base font-medium text-gray-300 hover:bg-dark-elevated focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm">
                    Cancelar
                </button>
            </div>
        </div>
    </div>
</div>

<!-- MODAL DE PROGRESSO -->
<div id="progress-modal" class="fixed inset-0 z-50 hidden overflow-y-auto" aria-labelledby="progress-modal-title" role="dialog" aria-modal="true">
    <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
        <div class="fixed inset-0 bg-gray-900 bg-opacity-75 transition-opacity" aria-hidden="true"></div>
        <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
        <div class="inline-block align-bottom bg-dark-card rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-3xl sm:w-full border" style="border-color: var(--accent-primary);">
            <div class="bg-dark-card px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg leading-6 font-medium text-white" id="progress-modal-title">Progresso do Aluno</h3>
                    <button type="button" id="close-progress-modal" class="text-gray-400 hover:text-white">
                        <i data-lucide="x" class="w-6 h-6"></i>
                    </button>
                </div>
                <div id="progress-content" class="mt-4">
                    <div class="text-center py-8">
                        <svg class="animate-spin h-8 w-8 mx-auto" style="color: var(--accent-primary);" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.96l2-2.669z"></path>
                        </svg>
                        <p class="mt-4 text-gray-400">Carregando progresso...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    lucide.createIcons();
    
    const produtoSelect = document.getElementById('produto-select');
    const alunosContainer = document.getElementById('alunos-container');
    const noProductSelected = document.getElementById('no-product-selected');
    const btnCriarAluno = document.getElementById('btn-criar-aluno');
    const searchInput = document.getElementById('search-input');
    const alunosTbody = document.getElementById('alunos-tbody');
    const alunosCardsMobile = document.getElementById('alunos-cards-mobile');
    const loadingState = document.getElementById('loading-state');
    const emptyState = document.getElementById('empty-state');
    
    // Modais
    const createModal = document.getElementById('create-modal');
    const progressModal = document.getElementById('progress-modal');
    const confirmCreateBtn = document.getElementById('confirm-create-btn');
    const cancelCreateBtn = document.getElementById('cancel-create-btn');
    const closeProgressModal = document.getElementById('close-progress-modal');
    
    let currentProdutoId = null;
    let allAlunos = [];
    let searchTerm = '';

    // Quando seleciona produto
    produtoSelect.addEventListener('change', function() {
        currentProdutoId = this.value;
        
        if (currentProdutoId) {
            alunosContainer.classList.remove('hidden');
            noProductSelected.style.display = 'none';
            btnCriarAluno.classList.remove('hidden');
            fetchAlunos();
        } else {
            alunosContainer.classList.add('hidden');
            noProductSelected.style.display = 'block';
            btnCriarAluno.classList.add('hidden');
        }
    });

    // Busca alunos
    async function fetchAlunos() {
        if (!currentProdutoId) return;

        loadingState.style.display = 'block';
        emptyState.style.display = 'none';
        alunosTbody.innerHTML = '';
        if (alunosCardsMobile) alunosCardsMobile.innerHTML = '';

        try {
            const response = await fetch(`/api/alunos_api.php?action=list&produto_id=${currentProdutoId}`);
            const data = await response.json();

            if (data.success) {
                allAlunos = data.alunos || [];
                // Debug: verificar se progresso está sendo retornado
                if (allAlunos.length > 0) {
                    console.log('Alunos carregados:', allAlunos.length);
                    console.log('Primeiro aluno (exemplo):', {
                        email: allAlunos[0].aluno_email,
                        progresso: allAlunos[0].progresso_percentual,
                        total_aulas: allAlunos[0].total_aulas,
                        aulas_concluidas: allAlunos[0].aulas_concluidas
                    });
                }
                filterAndDisplayAlunos();
            } else {
                alert('Erro: ' + (data.error || 'Erro desconhecido'));
            }
        } catch (error) {
            console.error('Erro:', error);
            alert('Erro ao carregar alunos');
        } finally {
            loadingState.style.display = 'none';
        }
    }

    // Filtra e exibe alunos
    function filterAndDisplayAlunos() {
        const filtered = allAlunos.filter(aluno => {
            if (!searchTerm) return true;
            const term = searchTerm.toLowerCase();
            return aluno.aluno_email.toLowerCase().includes(term) || 
                   (aluno.nome && aluno.nome.toLowerCase().includes(term));
        });

        if (filtered.length === 0) {
            emptyState.style.display = 'block';
            return;
        }

        emptyState.style.display = 'none';

        // Desktop
        filtered.forEach(aluno => {
            const row = document.createElement('tr');
            const dataFormatada = new Date(aluno.data_concessao).toLocaleDateString('pt-BR');
            
            // Garante valores válidos para progresso
            const progresso = parseInt(aluno.progresso_percentual) || 0;
            const totalAulas = parseInt(aluno.total_aulas) || 0;
            const aulasConcluidas = parseInt(aluno.aulas_concluidas) || 0;
            
            row.innerHTML = `
                <td class="px-6 py-4 whitespace-nowrap">
                    <div class="text-sm font-medium text-white">${escapeHtml(aluno.nome || aluno.aluno_email)}</div>
                    <div class="text-sm text-gray-400">${escapeHtml(aluno.aluno_email)}</div>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-300">${dataFormatada}</td>
                <td class="px-6 py-4 whitespace-nowrap">
                    <div class="flex items-center">
                        <div class="w-full bg-gray-700 rounded-full h-2.5 mr-2" style="max-width: 100px;">
                            <div class="h-2.5 rounded-full" style="width: ${progresso}%; background-color: var(--accent-primary);"></div>
                        </div>
                        <span class="text-sm text-white font-semibold">${progresso}%</span>
                    </div>
                    <div class="text-xs text-gray-400 mt-1">${aulasConcluidas}/${totalAulas} aulas</div>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                    <button onclick="viewProgress('${escapeHtml(aluno.aluno_email)}', ${currentProdutoId})" class="text-primary hover:text-primary-hover mr-3" style="color: var(--accent-primary);" onmouseover="this.style.color='var(--accent-primary-hover)'" onmouseout="this.style.color='var(--accent-primary)'" title="Ver Progresso">
                        <i data-lucide="bar-chart-2" class="w-5 h-5"></i>
                    </button>
                    <button onclick="removeAluno('${escapeHtml(aluno.aluno_email)}', ${currentProdutoId})" class="text-red-400 hover:text-red-600" title="Remover Acesso">
                        <i data-lucide="trash-2" class="w-5 h-5"></i>
                    </button>
                </td>
            `;
            alunosTbody.appendChild(row);
        });

        // Mobile
        filtered.forEach(aluno => {
            const card = document.createElement('div');
            card.className = 'bg-dark-elevated p-4 rounded-lg border border-dark-border';
            const dataFormatada = new Date(aluno.data_concessao).toLocaleDateString('pt-BR');
            
            // Garante valores válidos para progresso
            const progresso = parseInt(aluno.progresso_percentual) || 0;
            const totalAulas = parseInt(aluno.total_aulas) || 0;
            const aulasConcluidas = parseInt(aluno.aulas_concluidas) || 0;
            
            card.innerHTML = `
                <div class="flex justify-between items-start mb-3">
                    <div>
                        <div class="text-sm font-medium text-white">${escapeHtml(aluno.nome || aluno.aluno_email)}</div>
                        <div class="text-xs text-gray-400">${escapeHtml(aluno.aluno_email)}</div>
                    </div>
                </div>
                <div class="mb-3">
                    <div class="text-xs text-gray-400 mb-1">Data de Acesso: ${dataFormatada}</div>
                    <div class="flex items-center mb-1">
                        <div class="w-full bg-gray-700 rounded-full h-2 mr-2" style="max-width: 80px;">
                            <div class="h-2 rounded-full" style="width: ${progresso}%; background-color: var(--accent-primary);"></div>
                        </div>
                        <span class="text-xs text-white font-semibold">${progresso}%</span>
                    </div>
                    <div class="text-xs text-gray-400">${aulasConcluidas}/${totalAulas} aulas concluídas</div>
                </div>
                <div class="flex gap-2">
                    <button onclick="viewProgress('${escapeHtml(aluno.aluno_email)}', ${currentProdutoId})" class="flex-1 px-3 py-2 rounded-lg text-sm font-medium text-white transition-colors" style="background-color: var(--accent-primary);" onmouseover="this.style.backgroundColor='var(--accent-primary-hover)'" onmouseout="this.style.backgroundColor='var(--accent-primary)'">
                        <i data-lucide="bar-chart-2" class="w-4 h-4 inline mr-1"></i>
                        Progresso
                    </button>
                    <button onclick="removeAluno('${escapeHtml(aluno.aluno_email)}', ${currentProdutoId})" class="px-3 py-2 rounded-lg text-sm font-medium text-red-400 border border-red-400 hover:bg-red-400 hover:text-white transition-colors">
                        <i data-lucide="trash-2" class="w-4 h-4"></i>
                    </button>
                </div>
            `;
            alunosCardsMobile.appendChild(card);
        });

        lucide.createIcons();
    }

    // Busca em tempo real
    let searchDebounce;
    searchInput.addEventListener('input', function() {
        searchTerm = this.value;
        clearTimeout(searchDebounce);
        searchDebounce = setTimeout(() => {
            filterAndDisplayAlunos();
        }, 300);
    });

    // Abrir modal de criar
    btnCriarAluno.addEventListener('click', function() {
        document.getElementById('create-produto').value = currentProdutoId;
        createModal.classList.remove('hidden');
    });

    // Fechar modal de criar
    cancelCreateBtn.addEventListener('click', function() {
        createModal.classList.add('hidden');
        document.getElementById('create-email').value = '';
        document.getElementById('create-nome').value = '';
        document.getElementById('create-enviar-email').checked = true;
    });

    // Criar aluno
    confirmCreateBtn.addEventListener('click', async function() {
        const email = document.getElementById('create-email').value.trim();
        const nome = document.getElementById('create-nome').value.trim();
        const produtoId = document.getElementById('create-produto').value;
        const enviarEmail = document.getElementById('create-enviar-email').checked;

        if (!email || !nome || !produtoId) {
            alert('Preencha todos os campos obrigatórios');
            return;
        }

        if (!email.match(/^[^\s@]+@[^\s@]+\.[^\s@]+$/)) {
            alert('Email inválido');
            return;
        }

        confirmCreateBtn.disabled = true;
        confirmCreateBtn.textContent = 'Criando...';

        try {
            const response = await fetch('/api/alunos_api.php?action=create', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    email: email,
                    nome: nome,
                    produto_id: produtoId,
                    enviar_email: enviarEmail
                })
            });

            const data = await response.json();

            if (data.success) {
                alert('Aluno criado com sucesso!' + (data.email_enviado ? ' Email de acesso enviado.' : ''));
                createModal.classList.add('hidden');
                document.getElementById('create-email').value = '';
                document.getElementById('create-nome').value = '';
                fetchAlunos();
            } else {
                alert('Erro: ' + (data.error || 'Erro desconhecido'));
            }
        } catch (error) {
            console.error('Erro:', error);
            alert('Erro ao criar aluno');
        } finally {
            confirmCreateBtn.disabled = false;
            confirmCreateBtn.textContent = 'Criar e Enviar Acesso';
        }
    });

    // Fechar modal de progresso
    closeProgressModal.addEventListener('click', function() {
        progressModal.classList.add('hidden');
    });

    // Função para visualizar progresso
    window.viewProgress = async function(email, produtoId) {
        progressModal.classList.remove('hidden');
        const progressContent = document.getElementById('progress-content');
        progressContent.innerHTML = `
            <div class="text-center py-8">
                <svg class="animate-spin h-8 w-8 mx-auto" style="color: var(--accent-primary);" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.96l2-2.669z"></path>
                </svg>
                <p class="mt-4 text-gray-400">Carregando progresso...</p>
            </div>
        `;

        try {
            const response = await fetch(`/api/alunos_api.php?action=progress&email=${encodeURIComponent(email)}&produto_id=${produtoId}`);
            const data = await response.json();

            if (data.success) {
                let html = `
                    <div class="mb-6">
                        <div class="flex justify-between items-center mb-2">
                            <span class="text-sm font-semibold" style="color: var(--accent-primary);">PROGRESSO GERAL</span>
                            <span class="text-sm font-bold text-white">${data.progresso_geral}% Completo</span>
                        </div>
                        <div class="w-full bg-gray-700 rounded-full h-3">
                            <div class="h-3 rounded-full" style="width: ${data.progresso_geral}%; background-color: var(--accent-primary);"></div>
                        </div>
                        <div class="text-xs text-gray-400 mt-2">${data.aulas_concluidas} de ${data.total_aulas} aulas concluídas</div>
                    </div>
                `;

                if (data.modulos && data.modulos.length > 0) {
                    html += '<div class="space-y-4">';
                    data.modulos.forEach(modulo => {
                        html += `
                            <div class="bg-dark-elevated p-4 rounded-lg border border-dark-border">
                                <div class="flex justify-between items-center mb-2">
                                    <h4 class="font-semibold text-white">${escapeHtml(modulo.titulo)}</h4>
                                    <span class="text-sm text-gray-400">${modulo.aulas_concluidas}/${modulo.total_aulas} aulas</span>
                                </div>
                                <div class="w-full bg-gray-700 rounded-full h-2 mb-3">
                                    <div class="h-2 rounded-full" style="width: ${modulo.progresso_percentual}%; background-color: var(--accent-primary);"></div>
                                </div>
                                <div class="space-y-2">
                        `;
                        
                        if (modulo.aulas && modulo.aulas.length > 0) {
                            modulo.aulas.forEach(aula => {
                                const statusIcon = aula.concluida ? 'check-circle-2' : 'circle';
                                const statusColor = aula.concluida ? 'text-green-400' : 'text-gray-500';
                                const statusText = aula.concluida ? 'Concluída' : 'Pendente';
                                const lockIcon = aula.is_locked ? '<i data-lucide="lock" class="w-4 h-4 text-yellow-400"></i>' : '';
                                
                                html += `
                                    <div class="flex items-center justify-between text-sm ${aula.is_locked ? 'opacity-50' : ''}">
                                        <div class="flex items-center gap-2">
                                            <i data-lucide="${statusIcon}" class="w-4 h-4 ${statusColor}"></i>
                                            ${lockIcon}
                                            <span class="text-gray-300">${escapeHtml(aula.titulo)}</span>
                                        </div>
                                        ${aula.concluida && aula.data_conclusao ? `<span class="text-xs text-gray-400">${new Date(aula.data_conclusao).toLocaleDateString('pt-BR')}</span>` : ''}
                                    </div>
                                `;
                            });
                        }
                        
                        html += `
                                </div>
                            </div>
                        `;
                    });
                    html += '</div>';
                } else {
                    html += '<p class="text-gray-400 text-center py-4">Nenhum módulo encontrado para este curso.</p>';
                }

                progressContent.innerHTML = html;
                lucide.createIcons();
            } else {
                progressContent.innerHTML = `<p class="text-red-400 text-center py-4">Erro: ${data.error || 'Erro desconhecido'}</p>`;
            }
        } catch (error) {
            console.error('Erro:', error);
            progressContent.innerHTML = '<p class="text-red-400 text-center py-4">Erro ao carregar progresso</p>';
        }
    };

    // Função para remover aluno
    window.removeAluno = async function(email, produtoId) {
        if (!confirm(`Tem certeza que deseja remover o acesso de ${email} a este produto?`)) {
            return;
        }

        try {
            const response = await fetch('/api/alunos_api.php?action=remove', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    email: email,
                    produto_id: produtoId
                })
            });

            const data = await response.json();

            if (data.success) {
                alert('Acesso removido com sucesso!');
                fetchAlunos();
            } else {
                alert('Erro: ' + (data.error || 'Erro desconhecido'));
            }
        } catch (error) {
            console.error('Erro:', error);
            alert('Erro ao remover acesso');
        }
    };

    // Função auxiliar para escape HTML
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
});
</script>

