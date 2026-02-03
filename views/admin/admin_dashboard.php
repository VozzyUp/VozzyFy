<!-- Este arquivo é incluído dentro de admin.php -->
<div class="container mx-auto">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-3xl font-bold text-white">Dashboard do Administrador</h1>
            <p class="text-gray-400 mt-1">Visão geral completa da sua plataforma.</p>
        </div>
    </div>

    <!-- Indicadores (KPIs) -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
        <div class="bg-dark-card p-5 rounded-xl shadow-sm flex items-center space-x-4 border border-primary">
            <div class="bg-primary/20 p-3 rounded-full">
                <i data-lucide="users" class="w-6 h-6 text-primary"></i>
            </div>
            <div>
                <p class="text-gray-400 text-sm">Usuários Ativos</p>
                <p id="kpi-total-usuarios" class="text-2xl font-bold text-white">...</p>
            </div>
        </div>
        <div class="bg-dark-card p-5 rounded-xl shadow-sm flex items-center space-x-4 border border-primary">
            <div class="bg-primary/20 p-3 rounded-full">
                <i data-lucide="dollar-sign" class="w-6 h-6 text-primary"></i>
            </div>
            <div>
                <p class="text-gray-400 text-sm">Faturamento Total</p>
                <p id="kpi-faturamento-total" class="text-2xl font-bold text-white">...</p>
            </div>
        </div>
        <div class="bg-dark-card p-5 rounded-xl shadow-sm flex items-center space-x-4 border border-primary">
            <div class="bg-primary/20 p-3 rounded-full">
                <i data-lucide="shopping-cart" class="w-6 h-6 text-primary"></i>
            </div>
            <div>
                <p class="text-gray-400 text-sm">Vendas Aprovadas</p>
                <p id="kpi-vendas-aprovadas" class="text-2xl font-bold text-white">...</p>
            </div>
        </div>
         <div class="bg-dark-card p-5 rounded-xl shadow-sm flex items-center space-x-4 border border-primary">
            <div class="bg-primary/20 p-3 rounded-full">
                <i data-lucide="package" class="w-6 h-6 text-primary"></i>
            </div>
            <div>
                <p class="text-gray-400 text-sm">Produtos Ativos</p>
                <p id="kpi-produtos-ativos" class="text-2xl font-bold text-white">...</p>
            </div>
        </div>
    </div>

    <!-- Gráfico de Vendas -->
    <div class="bg-dark-card p-6 rounded-xl shadow-sm mb-6 border border-primary">
        <h2 class="text-xl font-semibold text-white mb-4">Picos de Vendas (Últimos 30 dias)</h2>
        <div style="height: 350px;">
            <canvas id="salesChartAdmin"></canvas>
        </div>
    </div>

    <!-- Seção de Produtos, Vendedores e Usuários -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Produtos Mais Vendidos -->
        <div class="bg-dark-card p-6 rounded-xl shadow-sm border border-dark-border">
            <h2 class="text-xl font-semibold text-white mb-4">Produtos Mais Vendidos</h2>
            <div id="top-products-list" class="space-y-4">
                 <p class="text-gray-400 text-center py-4">Carregando produtos...</p>
            </div>
        </div>
        
        <!-- NOVO: Ranking de Vendedores -->
        <div class="bg-dark-card p-6 rounded-xl shadow-sm border border-dark-border">
            <h2 class="text-xl font-semibold text-white mb-4">Ranking de Vendedores</h2>
            <div id="top-sellers-list" class="space-y-4">
                <p class="text-gray-400 text-center py-4">Carregando ranking...</p>
            </div>
        </div>


        <!-- Últimos Usuários Cadastrados -->
        <div class="bg-dark-card p-6 rounded-xl shadow-sm border border-dark-border">
            <h2 class="text-xl font-semibold text-white mb-4">Últimos Usuários Cadastrados</h2>
            <div id="recent-users-list" class="space-y-3">
                <p class="text-gray-400 text-center py-4">Carregando usuários...</p>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    let salesChartInstance = null;

    function formatCurrency(value) {
        return parseFloat(value).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
    }
    
    // Função para criar o avatar com a inicial do nome
    function createAvatar(name) {
        const initial = name ? name.charAt(0).toUpperCase() : '?';
        return `
            <div class="w-10 h-10 rounded-full bg-dark-elevated flex items-center justify-center text-gray-300 font-bold text-lg flex-shrink-0">
                ${initial}
            </div>
        `;
    }

    function fetchAdminData() {
        fetch('/api/admin_api.php?action=get_admin_dashboard_data')
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json();
            })
            .then(data => {
                // KPIs
                document.getElementById('kpi-total-usuarios').textContent = data.kpis.total_usuarios;
                document.getElementById('kpi-faturamento-total').textContent = formatCurrency(data.kpis.faturamento_total);
                document.getElementById('kpi-vendas-aprovadas').textContent = data.kpis.vendas_aprovadas;
                document.getElementById('kpi-produtos-ativos').textContent = data.kpis.produtos_ativos;

                // Gráfico
                renderSalesChart(data.chart);

                // Produtos Mais Vendidos
                const topProductsContainer = document.getElementById('top-products-list');
                topProductsContainer.innerHTML = '';
                if(data.top_products && data.top_products.length > 0) {
                    data.top_products.forEach(product => {
                        const productHtml = `
                            <div class="flex items-center space-x-4">
                                <img src="${product.foto ? 'uploads/' + product.foto : 'https://placehold.co/64x64/f97316/FFF?text=P'}" alt="${product.nome}" class="w-16 h-16 rounded-lg object-cover flex-shrink-0">
                                <div class="flex-grow">
                                    <p class="font-semibold text-white truncate" title="${product.nome}">${product.nome}</p>
                                    <p class="text-sm text-gray-400">${formatCurrency(product.faturamento_total)}</p>
                                </div>
                                <div class="text-right flex-shrink-0">
                                    <p class="font-bold text-lg text-primary">${product.total_vendas}</p>
                                    <p class="text-xs text-gray-400">vendas</p>
                                </div>
                            </div>
                        `;
                        topProductsContainer.innerHTML += productHtml;
                    });
                } else {
                     topProductsContainer.innerHTML = '<p class="text-gray-400 text-center py-4">Nenhum produto vendido ainda.</p>';
                }

                // NOVO: Ranking de Vendedores
                const topSellersContainer = document.getElementById('top-sellers-list');
                topSellersContainer.innerHTML = '';
                if (data.top_sellers && data.top_sellers.length > 0) {
                    data.top_sellers.forEach(seller => {
                        const sellerName = seller.nome || seller.usuario;
                        const sellerAvatar = seller.foto_perfil 
                            ? `<img src="uploads/${seller.foto_perfil}" alt="${sellerName}" class="w-10 h-10 rounded-full object-cover flex-shrink-0">`
                            : createAvatar(sellerName);
                        
                        const sellerHtml = `
                            <div class="flex items-center space-x-3">
                                ${sellerAvatar}
                                <div class="flex-grow">
                                    <p class="font-semibold text-white truncate" title="${sellerName}">${sellerName}</p>
                                    <p class="text-sm text-gray-400">${formatCurrency(seller.faturamento_total)}</p>
                                </div>
                                <div class="text-right flex-shrink-0">
                                    <p class="font-bold text-lg text-primary">${seller.total_vendas}</p>
                                    <p class="text-xs text-gray-400">vendas</p>
                                </div>
                            </div>
                        `;
                        topSellersContainer.innerHTML += sellerHtml;
                    });
                } else {
                    topSellersContainer.innerHTML = '<p class="text-gray-400 text-center py-4">Nenhum vendedor com vendas aprovadas.</p>';
                }


                // Usuários Recentes
                const recentUsersContainer = document.getElementById('recent-users-list');
                recentUsersContainer.innerHTML = '';
                 if(data.recent_users && data.recent_users.length > 0) {
                    data.recent_users.forEach(user => {
                        const userName = user.nome || user.usuario;
                        const userAvatar = user.foto_perfil 
                            ? `<img src="uploads/${user.foto_perfil}" alt="${userName}" class="w-10 h-10 rounded-full object-cover">`
                            : createAvatar(userName);

                        const userHtml = `
                            <div class="flex items-center justify-between p-2 hover:bg-dark-elevated rounded-lg">
                                <div class="flex items-center space-x-3">
                                    ${userAvatar}
                                    <p class="font-medium text-white truncate" title="${userName}">${userName}</p>
                                </div>
                                <span class="text-xs font-semibold px-2 py-1 rounded-full ${user.tipo === 'admin' ? 'bg-primary/20 text-primary' : 'bg-blue-900/30 text-blue-300'}">
                                    ${user.tipo}
                                </span>
                            </div>
                        `;
                        recentUsersContainer.innerHTML += userHtml;
                    });
                } else {
                     recentUsersContainer.innerHTML = '<p class="text-gray-400 text-center py-4">Nenhum usuário encontrado.</p>';
                }
            })
            .catch(error => {
                console.error('Erro ao buscar dados para o dashboard do admin:', error);
                document.getElementById('top-products-list').innerHTML = '<p class="text-red-500 text-center py-4">Erro ao carregar dados.</p>';
                document.getElementById('top-sellers-list').innerHTML = '<p class="text-red-500 text-center py-4">Erro ao carregar dados.</p>';
                document.getElementById('recent-users-list').innerHTML = '<p class="text-red-500 text-center py-4">Erro ao carregar dados.</p>';
            });
    }

    function renderSalesChart(chartData) {
        const ctx = document.getElementById('salesChartAdmin').getContext('2d');
        if (salesChartInstance) {
            salesChartInstance.destroy();
        }

        const gradient = ctx.createLinearGradient(0, 0, 0, 350);
        gradient.addColorStop(0, 'rgba(50, 231, 104, 0.4)');
        gradient.addColorStop(1, 'rgba(50, 231, 104, 0)');

        salesChartInstance = new Chart(ctx, {
            type: 'line',
            data: {
                labels: chartData.labels,
                datasets: [{
                    label: 'Faturamento',
                    data: chartData.data,
                    backgroundColor: gradient,
                    borderColor: getComputedStyle(document.documentElement).getPropertyValue('--accent-primary').trim() || '#32e768',
                    borderWidth: 3,
                    pointBackgroundColor: '#1a1f24',
                    pointBorderColor: getComputedStyle(document.documentElement).getPropertyValue('--accent-primary').trim() || '#32e768',
                    pointHoverRadius: 7,
                    pointRadius: 5,
                    tension: 0.4,
                    fill: true,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            color: '#9ca3af',
                            callback: function(value) { return 'R$ ' + value.toLocaleString('pt-BR'); }
                        },
                        grid: {
                            color: 'rgba(255, 255, 255, 0.1)'
                        }
                    },
                    x: {
                        ticks: {
                            color: '#9ca3af'
                        },
                        grid: {
                            color: 'rgba(255, 255, 255, 0.1)'
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
                                let label = context.dataset.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                if (context.parsed.y !== null) {
                                    label += formatCurrency(context.parsed.y);
                                }
                                return label;
                            }
                        }
                    }
                }
            }
        });
    }

    fetchAdminData();
    lucide.createIcons();
});
</script>