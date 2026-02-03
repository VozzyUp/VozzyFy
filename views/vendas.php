<?php
// Este arquivo é incluído a partir do index.php

// Incluir helper de segurança para funções CSRF
require_once __DIR__ . '/../helpers/security_helper.php';

// Gerar token CSRF para uso em requisições JavaScript
$csrf_token_js = generate_csrf_token();
?>

<meta name="csrf-token" content="<?php echo htmlspecialchars($csrf_token_js); ?>">
<script>
    // Variável global para token CSRF
    window.csrfToken = '<?php echo htmlspecialchars($csrf_token_js); ?>';
</script>

<?php do_action('vendas_list_before'); ?>
<div class="container mx-auto relative">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-3xl font-bold text-white">Relatório de Vendas</h1>
        <button onclick="exportToCSV()" class="bg-dark-elevated text-gray-300 font-bold py-2 px-4 rounded-lg hover:bg-dark-card transition duration-300 flex items-center space-x-2 border border-dark-border" title="Exportar vendas filtradas para CSV">
            <i data-lucide="download" class="w-5 h-5"></i>
            <span>Exportar CSV</span>
        </button>
    </div>

    <!-- Cards de Métricas -->
    <div class="grid grid-cols-2 md:grid-cols-6 gap-4 mb-6">
        <div data-status="all" class="metric-card p-4 bg-dark-card rounded-lg shadow-md cursor-pointer border-2" style="border-color: var(--accent-primary);">
            <h3 class="text-gray-400 text-sm font-medium">Total de Vendas</h3>
            <p id="metric-all" class="text-2xl font-bold text-white">0</p>
        </div>
        <div data-status="approved" class="metric-card p-4 bg-dark-card rounded-lg shadow-md cursor-pointer border-2 border-transparent" onmouseover="this.style.borderColor='var(--accent-primary)'" onmouseout="this.style.borderColor='transparent'">
            <h3 class="text-gray-400 text-sm font-medium">Aprovadas</h3>
            <p id="metric-approved" class="text-2xl font-bold" style="color: var(--accent-primary);">0</p>
        </div>
        <div data-status="abandoned_all" class="metric-card p-4 bg-dark-card rounded-lg shadow-md cursor-pointer border-2 border-transparent" onmouseover="this.style.borderColor='var(--accent-primary)'" onmouseout="this.style.borderColor='transparent'">
            <h3 class="text-gray-400 text-sm font-medium">Abandonadas</h3>
            <p id="metric-abandoned_all" class="text-2xl font-bold text-red-400">0</p>
        </div>
        <div data-status="info_filled" class="metric-card p-4 bg-dark-card rounded-lg shadow-md cursor-pointer border-2 border-transparent" onmouseover="this.style.borderColor='var(--accent-primary)'" onmouseout="this.style.borderColor='transparent'">
            <h3 class="text-gray-400 text-sm font-medium">Checkout Aband.</h3>
            <p id="metric-info_filled" class="text-2xl font-bold text-red-300">0</p>
        </div>
        <div data-status="refunded" class="metric-card p-4 bg-dark-card rounded-lg shadow-md cursor-pointer border-2 border-transparent" onmouseover="this.style.borderColor='var(--accent-primary)'" onmouseout="this.style.borderColor='transparent'">
            <h3 class="text-gray-400 text-sm font-medium">Reembolsadas</h3>
            <p id="metric-refunded" class="text-2xl font-bold text-purple-400">0</p>
        </div>
        <div data-status="charged_back" class="metric-card p-4 bg-dark-card rounded-lg shadow-md cursor-pointer border-2 border-transparent" onmouseover="this.style.borderColor='var(--accent-primary)'" onmouseout="this.style.borderColor='transparent'">
            <h3 class="text-gray-400 text-sm font-medium">Chargeback</h3>
            <p id="metric-charged_back" class="text-2xl font-bold text-pink-400">0</p>
        </div>
    </div>

    <!-- Tabela -->
    <div class="bg-dark-card p-6 rounded-lg shadow-md border" style="border-color: var(--accent-primary);">
        <div class="mb-4">
            <div class="relative">
                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                    <i data-lucide="search" class="w-5 h-5 text-gray-400"></i>
                </div>
                <input type="text" id="search-input" class="block w-full pl-10 pr-3 py-2 border border-dark-border rounded-md leading-5 bg-dark-elevated placeholder-gray-500 text-white focus:outline-none focus:placeholder-gray-400 focus:ring-1 sm:text-sm" style="--tw-ring-color: var(--accent-primary);" onfocus="this.style.borderColor='var(--accent-primary)'; this.style.boxShadow='0 0 0 1px var(--accent-primary)'" onblur="this.style.borderColor='rgba(255,255,255,0.1)'; this.style.boxShadow='none'" placeholder="Pesquisar por nome, e-mail ou ID...">
            </div>
        </div>

        <!-- Tabela Desktop (oculta no mobile) -->
        <div class="hidden md:block overflow-x-auto">
            <table class="min-w-full divide-y divide-dark-border">
                <thead class="bg-dark-elevated">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Cliente</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Produto</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Valor</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Método</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Data</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Status</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Ações</th>
                    </tr>
                </thead>
                <tbody id="vendas-tbody" class="bg-dark-card divide-y divide-dark-border"></tbody>
            </table>
        </div>
        
        <!-- Cards Mobile (visível apenas no mobile) -->
        <div id="vendas-cards-mobile" class="md:hidden space-y-4"></div>

        <div id="loading-state" class="text-center py-12 text-gray-400" style="display: none;">
            <svg class="animate-spin h-8 w-8 mx-auto" style="color: var(--accent-primary);" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.96l2-2.669z"></path></svg>
            <p class="mt-4 font-medium">Carregando...</p>
        </div>
        <div id="empty-state" class="text-center py-12 text-gray-400" style="display: none;">
            <i data-lucide="inbox" class="mx-auto w-16 h-16 text-gray-500"></i>
            <p class="mt-4">Nenhuma venda encontrada.</p>
        </div>

        <div id="pagination-controls" class="hidden mt-4 flex items-center justify-between">
            <button id="prev-page" class="relative inline-flex items-center px-4 py-2 border border-dark-border text-sm font-medium rounded-md text-gray-300 bg-dark-elevated hover:bg-dark-card">Anterior</button>
            <span id="page-info" class="text-sm text-gray-300">Página 1 de 1</span>
            <button id="next-page" class="ml-3 relative inline-flex items-center px-4 py-2 border border-dark-border text-sm font-medium rounded-md text-gray-300 bg-dark-elevated hover:bg-dark-card">Próximo</button>
        </div>
    </div>
</div>
<?php do_action('vendas_list_after'); ?>

<!-- MODAL DE REENVIAR ACESSO -->
<div id="resend-modal" class="fixed inset-0 z-50 hidden overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
    <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
        <div class="fixed inset-0 bg-gray-900 bg-opacity-75 transition-opacity" aria-hidden="true"></div>
        <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
        <div class="inline-block align-bottom bg-dark-card rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full border" style="border-color: var(--accent-primary);">
            <div class="bg-dark-card px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                <div class="sm:flex sm:items-start">
                    <div class="mx-auto flex-shrink-0 flex items-center justify-center h-12 w-12 rounded-full sm:mx-0 sm:h-10 sm:w-10" style="background-color: color-mix(in srgb, var(--accent-primary) 20%, transparent);">
                        <i data-lucide="mail" class="h-6 w-6" style="color: var(--accent-primary);"></i>
                    </div>
                    <div class="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left w-full">
                        <h3 class="text-lg leading-6 font-medium text-white" id="modal-title">Reenviar Acesso</h3>
                        <div class="mt-2">
                            <p class="text-sm text-gray-400 mb-4">O cliente cadastrou o e-mail errado? Corrija abaixo para reenviar o acesso.</p>
                            <label for="modal-email-input" class="block text-sm font-medium text-gray-300">E-mail de Destino</label>
                            <input type="email" id="modal-email-input" class="mt-1 focus:ring-primary focus:border-primary block w-full shadow-sm sm:text-sm border-dark-border rounded-md border p-2 bg-dark-elevated text-white placeholder-gray-500" placeholder="email@cliente.com">
                            <input type="hidden" id="modal-venda-id">
                        </div>
                    </div>
                </div>
            </div>
            <div class="bg-dark-elevated px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
                <button type="button" id="confirm-resend-btn" class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 text-base font-medium text-white focus:outline-none focus:ring-2 focus:ring-offset-2 sm:ml-3 sm:w-auto sm:text-sm" style="background-color: var(--accent-primary);" onmouseover="this.style.backgroundColor='var(--accent-primary-hover)'" onmouseout="this.style.backgroundColor='var(--accent-primary)'" onfocus="this.style.boxShadow='0 0 0 2px var(--accent-primary)'" onblur="this.style.boxShadow='none'">
                    Enviar Agora
                </button>
                <button type="button" id="cancel-resend-btn" class="mt-3 w-full inline-flex justify-center rounded-md border border-dark-border shadow-sm px-4 py-2 bg-dark-card text-base font-medium text-gray-300 hover:bg-dark-elevated focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm">
                    Cancelar
                </button>
            </div>
        </div>
    </div>
</div>

<!-- SIDEBAR DETALHES DA VENDA -->
<div id="venda-details-overlay" class="fixed inset-0 bg-black bg-opacity-50 z-40 hidden" onclick="closeVendaDetails()"></div>
<div id="venda-details-sidebar" class="fixed right-0 top-0 h-full w-full max-w-md bg-dark-card border-l shadow-xl z-50 transform translate-x-full transition-transform duration-300 overflow-y-auto" style="border-color: var(--accent-primary);">
    <div class="sticky top-0 bg-dark-card border-b border-dark-border px-6 py-4 flex justify-between items-center z-10">
        <h3 class="text-lg font-semibold text-white">Detalhes da Venda</h3>
        <button type="button" onclick="closeVendaDetails()" class="p-2 rounded-lg text-gray-400 hover:text-white hover:bg-dark-elevated transition">
            <i data-lucide="x" class="w-5 h-5"></i>
        </button>
    </div>
    <div id="venda-details-loading" class="p-6 text-center text-gray-400">
        <i data-lucide="loader-2" class="w-8 h-8 animate-spin mx-auto"></i>
        <p class="mt-2">Carregando...</p>
    </div>
    <div id="venda-details-content" class="p-6 hidden">
        <input type="hidden" id="venda-details-id" value="">
        <div class="space-y-6">
            <section>
                <h4 class="text-sm font-semibold text-gray-400 uppercase mb-2">Cliente</h4>
                <div class="space-y-1 text-sm">
                    <p class="text-white"><span class="text-gray-500">Nome:</span> <span id="detail-nome"></span></p>
                    <p class="text-white"><span class="text-gray-500">E-mail:</span> <span id="detail-email"></span></p>
                    <p class="text-white"><span class="text-gray-500">Telefone:</span> <span id="detail-telefone"></span></p>
                    <p class="text-white"><span class="text-gray-500">CPF:</span> <span id="detail-cpf"></span></p>
                </div>
            </section>
            <section>
                <h4 class="text-sm font-semibold text-gray-400 uppercase mb-2">Venda</h4>
                <div class="space-y-1 text-sm">
                    <p class="text-white"><span class="text-gray-500">Produto:</span> <span id="detail-produto"></span></p>
                    <p class="text-white"><span class="text-gray-500">Valor:</span> <span id="detail-valor" style="color: var(--accent-primary);"></span></p>
                    <p class="text-white"><span class="text-gray-500">Data:</span> <span id="detail-data"></span></p>
                    <p class="text-white"><span class="text-gray-500">Status:</span> <span id="detail-status"></span></p>
                    <p class="text-white"><span class="text-gray-500">Método:</span> <span id="detail-metodo"></span></p>
                    <p class="text-white break-all"><span class="text-gray-500">Transação ID:</span> <span id="detail-transacao" class="text-xs"></span></p>
                </div>
            </section>
            <section>
                <h4 class="text-sm font-semibold text-gray-400 uppercase mb-2">Parâmetros UTM</h4>
                <div id="detail-utms" class="space-y-1 text-sm text-gray-300"></div>
            </section>
            <section id="detail-utmfy-section" class="hidden">
                <button type="button" id="btn-dispatch-utmfy" class="w-full py-3 px-4 rounded-lg font-semibold text-white transition flex items-center justify-center gap-2" style="background-color: var(--accent-primary);" onmouseover="this.style.opacity='0.9'" onmouseout="this.style.opacity='1'">
                    <i data-lucide="send" class="w-5 h-5"></i>
                    <span>Disparar para UTMfy</span>
                </button>
                <p class="mt-2 text-xs text-gray-500">Use somente se a venda não tiver contabilizado na UTMfy.</p>
            </section>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const tbody = document.getElementById('vendas-tbody');
    const loadingState = document.getElementById('loading-state');
    const emptyState = document.getElementById('empty-state');
    const searchInput = document.getElementById('search-input');
    const metricCardsContainer = document.querySelector('.grid.grid-cols-2.md\\:grid-cols-6');
    
    // Paginação
    const paginationControls = document.getElementById('pagination-controls');
    const pageInfo = document.getElementById('page-info');
    const prevPageBtn = document.getElementById('prev-page');
    const nextPageBtn = document.getElementById('next-page');

    // Modal Elements
    const resendModal = document.getElementById('resend-modal');
    const modalEmailInput = document.getElementById('modal-email-input');
    const modalVendaId = document.getElementById('modal-venda-id');
    const confirmResendBtn = document.getElementById('confirm-resend-btn');
    const cancelResendBtn = document.getElementById('cancel-resend-btn');

    let state = { status: 'all', search: '', page: 1 };
    let debounceTimer;

    const statusBadges = {
        'approved': '', 
        'paid': '', // ADICIONADO: Tratamento para 'paid' igual a approved
        'completed': '', // ADICIONADO: Tratamento para 'completed' igual a approved
        'pending': 'bg-yellow-100 text-yellow-800',
        'in_process': 'bg-blue-100 text-blue-800', 
        'rejected': 'bg-red-100 text-red-800',
        'cancelled': 'bg-gray-100 text-gray-800', 
        'refunded': 'bg-purple-100 text-purple-800',
        'charged_back': 'bg-pink-100 text-pink-800', 
        'info_filled': 'bg-indigo-100 text-indigo-800',
        'abandoned_all': 'bg-red-100 text-red-800'
    };
    
    const paymentMethodIcons = {
        'Pix': 'https://img.icons8.com/color/48/pix.png',
        'Cartão de crédito': 'https://img.icons8.com/color/48/bank-cards.png',
        'Boleto': 'https://img.icons8.com/color/48/barcode.png',
        'Não informado': 'https://img.icons8.com/material-outlined/48/help.png'
    };

    const formatStatusText = (status) => {
        const map = { 
            'approved': 'Aprovada', 
            'paid': 'Aprovada', // ADICIONADO: Traduz 'paid' para Aprovada
            'completed': 'Aprovada', // ADICIONADO
            'pending': 'Pendente', 
            'in_process': 'Em Processamento', 
            'rejected': 'Rejeitada', 
            'cancelled': 'Cancelada', 
            'refunded': 'Reembolsada', 
            'charged_back': 'Chargeback', 
            'info_filled': 'Info. Preenchidas' 
        };
        return map[status] || status;
    };

    const fetchVendas = async () => {
        loadingState.style.display = 'block';
        emptyState.style.display = 'none';
        paginationControls.classList.add('hidden');
        tbody.innerHTML = '';
        
        try {
            const url = `/api/api.php?action=get_vendas&status=${state.status}&search=${encodeURIComponent(state.search)}&page=${state.page}`;
            const response = await fetch(url);
            const data = await response.json();

            document.getElementById('metric-all').textContent = data.metrics.all || 0;
            document.getElementById('metric-approved').textContent = data.metrics.approved || 0;
            document.getElementById('metric-refunded').textContent = data.metrics.refunded || 0;
            document.getElementById('metric-charged_back').textContent = data.metrics.charged_back || 0;
            document.getElementById('metric-abandoned_all').textContent = data.metrics.abandoned_all || 0;
            document.getElementById('metric-info_filled').textContent = data.metrics.info_filled || 0;

            if (data.vendas.length === 0) {
                emptyState.style.display = 'block';
            } else {
                const vendasCardsMobile = document.getElementById('vendas-cards-mobile');
                if (vendasCardsMobile) vendasCardsMobile.innerHTML = ''; // Limpar cards mobile
                
                data.vendas.forEach(venda => {
                    const valorFormatado = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(venda.valor);
                    const dataFormatada = new Date(venda.data_venda).toLocaleString('pt-BR');
                    let badgeClass = statusBadges[venda.status_pagamento] || 'bg-gray-100 text-gray-800';
                    let badgeStyle = '';
                    if (venda.status_pagamento === 'approved' || venda.status_pagamento === 'paid' || venda.status_pagamento === 'completed') {
                        badgeClass = '';
                        badgeStyle = 'background-color: color-mix(in srgb, var(--accent-primary) 20%, transparent); color: var(--accent-primary);';
                    }
                    const metodo = venda.metodo_pagamento || 'Não informado';
                    const iconUrl = paymentMethodIcons[metodo] || paymentMethodIcons['Não informado'];

                    let whatsappLink = '#';
                    let whatsappClass = 'opacity-50 cursor-not-allowed';
                    if (venda.comprador_telefone) {
                        const cleanPhone = venda.comprador_telefone.replace(/\D/g, '');
                        if (cleanPhone.length >= 10) {
                           whatsappLink = `https://api.whatsapp.com/send?phone=55${cleanPhone}&text=Ol%C3%A1%2C%20${encodeURIComponent(venda.comprador_nome)}%21%20Referente%20%C3%A0%20sua%20compra%3A`;
                           whatsappClass = '';
                           whatsappStyle = 'color: var(--accent-primary);';
                        }
                    }
                    
                    // Lógica dos botões
                    let actionsHtml = '';
                    
                    // Botão Ver Detalhes (todas as vendas)
                    actionsHtml += `
                        <button class="details-btn p-1 text-gray-400 hover:text-white transition" title="Ver detalhes" onclick="openVendaDetails(${venda.id})">
                            <i data-lucide="info" class="w-5 h-5"></i>
                        </button>
                    `;
                    
                    // Botão Reenviar Acesso (Para approved OU paid)
                    if (venda.status_pagamento === 'approved' || venda.status_pagamento === 'paid' || venda.status_pagamento === 'completed') {
                        actionsHtml += `
                            <button class="resend-btn p-1" style="color: var(--accent-primary);" onmouseover="this.style.color='var(--accent-primary-hover)'" onmouseout="this.style.color='var(--accent-primary)'" title="Reenviar Acesso" onclick="openResendModal(${venda.id}, '${venda.comprador_email}')">
                                <i data-lucide="mail" class="w-5 h-5"></i>
                            </button>
                        `;
                    }

                    // Botão Aprovar (Só se NÃO for aprovada/paga)
                    if (venda.status_pagamento !== 'approved' && venda.status_pagamento !== 'paid' && venda.status_pagamento !== 'completed') {
                        actionsHtml += `
                            <button class="approve-btn text-green-600 hover:text-green-800 p-1" title="Aprovar Manualmente" data-venda-id="${venda.id}" onclick="approveSale(${venda.id})">
                                <i data-lucide="check-circle" class="w-5 h-5"></i>
                            </button>`;
                    }

                    // Linha da tabela (Desktop)
                    const tr = document.createElement('tr');
                    tr.innerHTML = `
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="text-sm font-medium text-white">${venda.comprador_nome || 'Não informado'}</div>
                            <div class="text-sm text-gray-400">${venda.comprador_email || ''}</div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap"><div class="text-sm text-gray-300">${venda.produto_nome || 'Produto não encontrado'}</div></td>
                        <td class="px-6 py-4 whitespace-nowrap"><div class="text-sm font-semibold text-white">${valorFormatado}</div></td>
                        <td class="px-6 py-4 whitespace-nowrap"><div class="text-sm text-gray-300 flex items-center"><img src="${iconUrl}" class="w-5 h-5 mr-2">${metodo}</div></td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-400">${dataFormatada}</td>
                        <td class="px-6 py-4 whitespace-nowrap"><span class="px-3 py-1 inline-flex text-xs leading-5 font-semibold rounded-full ${badgeClass}" ${badgeStyle ? `style="${badgeStyle}"` : ''}>${formatStatusText(venda.status_pagamento)}</span></td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium flex items-center space-x-3">
                            <a href="${whatsappLink}" target="_blank" class="${whatsappClass}" ${whatsappStyle ? `style="${whatsappStyle}"` : ''} title="WhatsApp"><i data-lucide="message-circle" class="w-5 h-5"></i></a>
                            ${actionsHtml}
                        </td>
                    `;
                    tbody.appendChild(tr);
                    
                    // Card Mobile
                    if (vendasCardsMobile) {
                        const card = document.createElement('div');
                        card.className = 'bg-dark-elevated rounded-lg p-4 border border-dark-border';
                        card.innerHTML = `
                            <div class="flex justify-between items-start mb-3">
                                <div class="flex-1">
                                    <h3 class="text-white font-semibold text-base mb-1">${venda.comprador_nome || 'Não informado'}</h3>
                                    <p class="text-gray-400 text-sm">${venda.comprador_email || ''}</p>
                                </div>
                                <span class="px-3 py-1 inline-flex text-xs leading-5 font-semibold rounded-full ${badgeClass} whitespace-nowrap ml-2" ${badgeStyle ? `style="${badgeStyle}"` : ''}>${formatStatusText(venda.status_pagamento)}</span>
                            </div>
                            
                            <div class="space-y-2 mb-4">
                                <div class="flex items-center justify-between">
                                    <span class="text-gray-400 text-sm">Produto:</span>
                                    <span class="text-white text-sm font-medium text-right">${venda.produto_nome || 'Produto não encontrado'}</span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-gray-400 text-sm">Valor:</span>
                                    <span class="text-white text-sm font-bold" style="color: var(--accent-primary);">${valorFormatado}</span>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-gray-400 text-sm">Método:</span>
                                    <div class="flex items-center">
                                        <img src="${iconUrl}" class="w-4 h-4 mr-1">
                                        <span class="text-white text-sm">${metodo}</span>
                                    </div>
                                </div>
                                <div class="flex items-center justify-between">
                                    <span class="text-gray-400 text-sm">Data:</span>
                                    <span class="text-gray-300 text-sm">${dataFormatada}</span>
                                </div>
                            </div>
                            
                            <div class="flex items-center justify-end space-x-2 pt-3 border-t border-dark-border">
                                <a href="${whatsappLink}" target="_blank" class="${whatsappClass} p-2 rounded-lg bg-dark-card hover:bg-dark-elevated transition" ${whatsappStyle ? `style="${whatsappStyle}"` : ''} title="WhatsApp">
                                    <i data-lucide="message-circle" class="w-5 h-5"></i>
                                </a>
                                ${actionsHtml.replace(/p-1/g, 'p-2 rounded-lg bg-dark-card hover:bg-dark-elevated transition')}
                            </div>
                        `;
                        vendasCardsMobile.appendChild(card);
                    }
                });
                updatePagination(data.pagination);
                lucide.createIcons();
            }
        } catch (error) {
            console.error('Erro:', error);
        } finally {
            loadingState.style.display = 'none';
        }
    };

    function updatePagination(data) {
        if (!data || data.totalPages <= 1) { paginationControls.classList.add('hidden'); return; }
        paginationControls.classList.remove('hidden');
        pageInfo.textContent = `Página ${data.currentPage} de ${data.totalPages}`;
        prevPageBtn.disabled = data.currentPage <= 1;
        nextPageBtn.disabled = data.currentPage >= data.totalPages;
    }

    // --- FUNÇÕES DE AÇÃO ---
    
    window.approveSale = async (vendaId) => {
        if (!confirm('Tem certeza que deseja APROVAR esta venda manualmente? O cliente receberá o acesso.')) return;
        
        try {
            const res = await fetch('/api/vendas_actions.php', {
                method: 'POST',
                headers: { 
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-CSRF-Token': window.csrfToken || ''
                },
                body: `action=approve&venda_id=${vendaId}&csrf_token=${encodeURIComponent(window.csrfToken || '')}`
            });
            const result = await res.json();
            if (result.success) {
                alert('Venda aprovada com sucesso!');
                fetchVendas(); // Recarrega a tabela
            } else {
                alert('Erro: ' + result.message);
            }
        } catch (e) { alert('Erro de conexão.'); }
    };

    window.openResendModal = (vendaId, currentEmail) => {
        modalVendaId.value = vendaId;
        modalEmailInput.value = currentEmail;
        resendModal.classList.remove('hidden');
    };

    confirmResendBtn.addEventListener('click', async () => {
        const vendaId = modalVendaId.value;
        const newEmail = modalEmailInput.value;
        
        if(!newEmail) { alert('Digite um e-mail válido.'); return; }
        
        confirmResendBtn.disabled = true;
        confirmResendBtn.textContent = "Enviando...";
        
        try {
            const res = await fetch('/api/vendas_actions.php', {
                method: 'POST',
                headers: { 
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-CSRF-Token': window.csrfToken || ''
                },
                body: `action=resend&venda_id=${vendaId}&email=${encodeURIComponent(newEmail)}&csrf_token=${encodeURIComponent(window.csrfToken || '')}`
            });
            const result = await res.json();
            if (result.success) {
                alert('Acesso reenviado com sucesso para: ' + newEmail);
                resendModal.classList.add('hidden');
                fetchVendas(); // Atualiza caso o email tenha mudado na visualização
            } else {
                alert('Erro: ' + result.message);
            }
        } catch (e) { alert('Erro de conexão.'); }
        finally {
            confirmResendBtn.disabled = false;
            confirmResendBtn.textContent = "Enviar Agora";
        }
    });

    cancelResendBtn.addEventListener('click', () => {
        resendModal.classList.add('hidden');
    });

    window.openVendaDetails = async (vendaId) => {
        const overlay = document.getElementById('venda-details-overlay');
        const sidebar = document.getElementById('venda-details-sidebar');
        const loading = document.getElementById('venda-details-loading');
        const content = document.getElementById('venda-details-content');
        overlay.classList.remove('hidden');
        sidebar.classList.remove('translate-x-full');
        loading.classList.remove('hidden');
        content.classList.add('hidden');
        try {
            const res = await fetch('/api/api.php?action=get_venda_details&venda_id=' + vendaId);
            const v = await res.json();
            if (v.error) {
                alert(v.error);
                closeVendaDetails();
                return;
            }
            document.getElementById('venda-details-id').value = v.id;
            document.getElementById('detail-nome').textContent = v.comprador_nome || '-';
            document.getElementById('detail-email').textContent = v.comprador_email || '-';
            document.getElementById('detail-telefone').textContent = v.comprador_telefone || '-';
            document.getElementById('detail-cpf').textContent = v.comprador_cpf || '-';
            document.getElementById('detail-produto').textContent = v.produto_nome || '-';
            document.getElementById('detail-valor').textContent = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(v.valor || 0);
            document.getElementById('detail-data').textContent = v.data_venda ? new Date(v.data_venda).toLocaleString('pt-BR') : '-';
            document.getElementById('detail-status').textContent = v.status_pagamento || '-';
            document.getElementById('detail-metodo').textContent = v.metodo_pagamento || '-';
            document.getElementById('detail-transacao').textContent = v.transacao_id || '-';
            const utmFields = [
                ['utm_source', 'utm_source'],
                ['utm_medium', 'utm_medium'],
                ['utm_campaign', 'utm_campaign'],
                ['utm_content', 'utm_content'],
                ['utm_term', 'utm_term'],
                ['src', 'src'],
                ['sck', 'sck']
            ];
            const utmDiv = document.getElementById('detail-utms');
            const hasAny = utmFields.some(([key]) => v[key]);
            if (hasAny) {
                utmDiv.innerHTML = utmFields.map(([key, label]) => {
                    const val = v[key] || '';
                    return val ? `<p class="text-white"><span class="text-gray-500">${label}:</span> ${val}</p>` : '';
                }).filter(Boolean).join('') || '<p class="text-gray-500">Nenhum</p>';
            } else {
                utmDiv.innerHTML = '<p class="text-gray-500">Nenhum</p>';
            }
            const utmfySection = document.getElementById('detail-utmfy-section');
            const isApproved = ['approved','paid','completed'].includes((v.status_pagamento || '').toLowerCase());
            const hasTransacao = v.transacao_id && v.transacao_id.trim() !== '';
            if (isApproved && hasTransacao) {
                utmfySection.classList.remove('hidden');
                const btn = document.getElementById('btn-dispatch-utmfy');
                btn.onclick = () => dispatchUtmfy(v.id);
            } else {
                utmfySection.classList.add('hidden');
            }
            loading.classList.add('hidden');
            content.classList.remove('hidden');
            lucide.createIcons();
        } catch (e) {
            console.error(e);
            alert('Erro ao carregar detalhes.');
            closeVendaDetails();
        }
    };

    window.closeVendaDetails = () => {
        document.getElementById('venda-details-overlay').classList.add('hidden');
        document.getElementById('venda-details-sidebar').classList.add('translate-x-full');
    };

    window.dispatchUtmfy = async (vendaId) => {
        const btn = document.getElementById('btn-dispatch-utmfy');
        if (!btn) return;
        const origText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i data-lucide="loader-2" class="w-5 h-5 animate-spin"></i><span>Enviando...</span>';
        lucide.createIcons();
        try {
            const res = await fetch('/api/vendas_actions.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': window.csrfToken || '' },
                body: `action=dispatch_utmfy&venda_id=${vendaId}&csrf_token=${encodeURIComponent(window.csrfToken || '')}`
            });
            const result = await res.json();
            if (result.success) {
                alert(result.message || 'UTMfy disparado com sucesso.');
            } else {
                alert('Erro: ' + (result.message || 'Falha ao disparar.'));
            }
        } catch (e) {
            alert('Erro de conexão.');
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<i data-lucide="send" class="w-5 h-5"></i><span>Disparar para UTMfy</span>';
            lucide.createIcons();
        }
    };

    document.getElementById('venda-details-overlay')?.addEventListener('click', window.closeVendaDetails);

    // Listeners de Filtro
    metricCardsContainer.addEventListener('click', (e) => {
        const card = e.target.closest('.metric-card');
        if (card) {
            document.querySelectorAll('.metric-card').forEach(c => { 
                c.style.borderColor = 'transparent';
                c.onmouseover = function() { this.style.borderColor = 'var(--accent-primary)'; };
                c.onmouseout = function() { if (!c.classList.contains('active')) this.style.borderColor = 'transparent'; };
            });
            card.style.borderColor = 'var(--accent-primary)';
            card.classList.add('active');
            state.status = card.dataset.status; state.page = 1; fetchVendas();
        }
    });
    searchInput.addEventListener('keyup', () => { clearTimeout(debounceTimer); debounceTimer = setTimeout(() => { state.search = searchInput.value; state.page = 1; fetchVendas(); }, 500); });
    prevPageBtn.addEventListener('click', () => { if (state.page > 1) { state.page--; fetchVendas(); } });
    nextPageBtn.addEventListener('click', () => { if (!nextPageBtn.disabled) { state.page++; fetchVendas(); } });

    // Função de exportação CSV
    window.exportToCSV = async function() {
        try {
            const status = state.status || 'all';
            const search = state.search || '';
            const url = `/api/api.php?action=export_vendas_csv&status=${encodeURIComponent(status)}&search=${encodeURIComponent(search)}`;
            
            // Criar link temporário para download
            const link = document.createElement('a');
            link.href = url;
            link.download = 'vendas_' + new Date().toISOString().slice(0, 19).replace(/:/g, '-') + '.csv';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        } catch (error) {
            console.error('Erro ao exportar CSV:', error);
            alert('Erro ao exportar CSV. Tente novamente.');
        }
    };

    fetchVendas();
});
</script>