<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../config/config.php';

// Proteção de página: verifica se o usuário está logado E se é um administrador
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || !isset($_SESSION["tipo"]) || $_SESSION["tipo"] !== 'admin') {
    header("location: /login");
    exit;
}

// Buscar lista de produtos e vendedores para os filtros
$stmt_produtos = $pdo->query("SELECT id, nome FROM produtos ORDER BY nome ASC");
$produtos = $stmt_produtos->fetchAll(PDO::FETCH_ASSOC);

$stmt_vendedores = $pdo->query("SELECT id, nome, usuario FROM usuarios WHERE tipo = 'infoprodutor' ORDER BY nome ASC");
$vendedores = $stmt_vendedores->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Relatórios Detalhados - Painel Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        body { font-family: 'Inter', sans-serif; }
        .form-input-style { 
            width: 100%;
            padding: 0.75rem 1rem;
            background-color: #0f1419;
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 0.5rem;
            color: white;
        }
        .form-input-style:focus {
            outline: none;
            ring: 2px;
            ring-color: #32e768;
            border-color: #32e768;
        }
        .form-input-style::placeholder {
            color: #6b7280;
        }
        .form-input-style option {
            background-color: #0f1419;
            color: white;
        }
        input[type="date"].form-input-style,
        input[type="email"].form-input-style,
        input[type="text"].form-input-style,
        input[type="number"].form-input-style,
        input[type="password"].form-input-style,
        input[type="url"].form-input-style,
        select.form-input-style {
            color-scheme: dark;
        }
        .metric-card {
            background: linear-gradient(135deg, rgba(50, 231, 104, 0.1) 0%, rgba(50, 231, 104, 0.05) 100%);
            border: 1px solid rgba(50, 231, 104, 0.3);
        }
    </style>
</head>
<body class="bg-dark-base font-sans">
    <div class="container mx-auto">
        <div class="flex items-center justify-between mb-6">
            <div>
                <h1 class="text-3xl font-bold text-white">Relatórios Detalhados</h1>
                <p class="text-gray-400 mt-1">Gere e visualize relatórios completos da plataforma.</p>
            </div>
            <a href="/admin?pagina=admin_dashboard" class="bg-dark-elevated text-gray-300 font-bold py-2 px-4 rounded-lg hover:bg-dark-card transition duration-300 flex items-center space-x-2 border border-dark-border">
                <i data-lucide="arrow-left" class="w-5 h-5"></i>
                <span>Voltar ao Dashboard</span>
            </a>
        </div>

        <!-- Filtros -->
        <div class="bg-dark-card p-6 rounded-lg shadow-md border border-dark-border mb-6">
            <h2 class="text-xl font-semibold mb-4 text-white">Filtros de Relatório</h2>
            
            <form id="relatorios-form">
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 mb-4">
                        <div>
                        <label for="tipo_relatorio" class="block text-sm font-medium text-gray-300 mb-2">Tipo de Relatório</label>
                        <select id="tipo_relatorio" name="tipo_relatorio" class="form-input-style" required>
                                <option value="vendas_periodo">Vendas por Período</option>
                                <option value="produtos_vendidos">Produtos Vendidos</option>
                                <option value="atividade_usuarios">Atividade de Usuários</option>
                                <option value="faturamento_vendedores">Faturamento por Vendedor</option>
                            <option value="metodos_pagamento">Métodos de Pagamento</option>
                            <option value="status_vendas">Status de Vendas</option>
                            <option value="carrinhos_abandonados">Carrinhos Abandonados</option>
                            </select>
                        </div>
                        <div>
                        <label for="data_inicio" class="block text-sm font-medium text-gray-300 mb-2">Data de Início</label>
                            <input type="date" id="data_inicio" name="data_inicio" class="form-input-style">
                        </div>
                        <div>
                        <label for="data_fim" class="block text-sm font-medium text-gray-300 mb-2">Data de Fim</label>
                            <input type="date" id="data_fim" name="data_fim" class="form-input-style">
                        </div>
                    </div>

                <!-- Filtros Adicionais (dinâmicos baseados no tipo de relatório) -->
                <div id="filtros-adicionais" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 mb-4">
                    <!-- Filtros serão adicionados dinamicamente via JavaScript -->
                </div>

                <div class="flex justify-end gap-2">
                    <button type="button" id="btn-export-csv" class="bg-dark-elevated text-gray-300 font-bold py-2 px-5 rounded-lg hover:bg-dark-card transition duration-300 flex items-center space-x-2 border border-dark-border" style="display: none;">
                        <i data-lucide="file-text" class="w-5 h-5"></i>
                        <span>Exportar CSV</span>
                    </button>
                    <button type="submit" class="bg-[#32e768] text-white font-bold py-2 px-5 rounded-lg hover:bg-[#28d15e] transition duration-300 flex items-center justify-center space-x-2">
                        <i data-lucide="search" class="w-5 h-5"></i>
                            <span>Gerar Relatório</span>
                        </button>
                </div>
            </form>
        </div>

        <!-- Resultados -->
            <div id="relatorio-output">
                <div class="text-center py-12 text-gray-400">
                    <i data-lucide="bar-chart-2" class="mx-auto w-16 h-16 text-gray-500"></i>
                    <p class="mt-4 font-medium">Os relatórios gerados aparecerão aqui.</p>
                <p class="mt-1 text-sm">Use os filtros acima para especificar o tipo de relatório e o período desejado.</p>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            lucide.createIcons();

            const relatoriosForm = document.getElementById('relatorios-form');
            const relatorioOutput = document.getElementById('relatorio-output');
            const tipoRelatorioSelect = document.getElementById('tipo_relatorio');
            const filtrosAdicionais = document.getElementById('filtros-adicionais');
            const btnExportCsv = document.getElementById('btn-export-csv');
            
            let currentReportData = null;
            let chartInstance = null;

            // Produtos e vendedores para filtros
            const produtos = <?php echo json_encode($produtos); ?>;
            const vendedores = <?php echo json_encode($vendedores); ?>;

            // Atualizar filtros adicionais baseado no tipo de relatório
            function updateAdditionalFilters() {
                const tipo = tipoRelatorioSelect.value;
                let html = '';

                if (tipo === 'produtos_vendidos') {
                    html = `
                        <div>
                            <label for="produto_filter" class="block text-sm font-medium text-gray-300 mb-2">Filtrar por Produto</label>
                            <select id="produto_filter" name="produto_filter" class="form-input-style">
                                <option value="">Todos os Produtos</option>
                                ${produtos.map(p => `<option value="${p.id}">${p.nome}</option>`).join('')}
                            </select>
                        </div>
                    `;
                } else if (tipo === 'faturamento_vendedores') {
                    html = `
                        <div>
                            <label for="vendedor_filter" class="block text-sm font-medium text-gray-300 mb-2">Filtrar por Vendedor</label>
                            <select id="vendedor_filter" name="vendedor_filter" class="form-input-style">
                                <option value="">Todos os Vendedores</option>
                                ${vendedores.map(v => `<option value="${v.id}">${v.nome || v.usuario}</option>`).join('')}
                            </select>
                        </div>
                    `;
                } else if (tipo === 'metodos_pagamento') {
                    html = `
                        <div>
                            <label for="metodo_filter" class="block text-sm font-medium text-gray-300 mb-2">Filtrar por Método</label>
                            <select id="metodo_filter" name="metodo_filter" class="form-input-style">
                                <option value="">Todos os Métodos</option>
                                <option value="Pix">Pix</option>
                                <option value="Cartão de Crédito">Cartão de Crédito</option>
                                <option value="Cartão de Débito">Cartão de Débito</option>
                                <option value="Boleto">Boleto</option>
                            </select>
                        </div>
                    `;
                } else if (tipo === 'status_vendas') {
                    html = `
                        <div>
                            <label for="status_filter" class="block text-sm font-medium text-gray-300 mb-2">Filtrar por Status</label>
                            <select id="status_filter" name="status_filter" class="form-input-style">
                                <option value="">Todos os Status</option>
                                <option value="approved">Aprovadas</option>
                                <option value="pending">Pendentes</option>
                                <option value="in_process">Em Processamento</option>
                                <option value="refunded">Reembolsadas</option>
                                <option value="charged_back">Chargeback</option>
                                <option value="cancelled">Canceladas</option>
                                <option value="info_filled">Info Preenchida</option>
                            </select>
                        </div>
                    `;
                }

                filtrosAdicionais.innerHTML = html;
                lucide.createIcons();
            }

            tipoRelatorioSelect.addEventListener('change', updateAdditionalFilters);
            updateAdditionalFilters();

            // Formatar moeda
            function formatCurrency(value) {
                return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value);
            }

            // Formatar número
            function formatNumber(value) {
                return new Intl.NumberFormat('pt-BR').format(value);
            }

            // Renderizar gráfico
            function renderChart(chartData, type = 'line') {
                if (chartInstance) {
                    chartInstance.destroy();
                }

                const ctx = document.getElementById('report-chart');
                if (!ctx || !chartData || !chartData.labels) return;

                const isDark = true;
                Chart.defaults.color = '#9ca3af';
                Chart.defaults.borderColor = 'rgba(255, 255, 255, 0.1)';

                chartInstance = new Chart(ctx, {
                    type: type,
                    data: {
                        labels: chartData.labels,
                        datasets: Object.keys(chartData).filter(k => k !== 'labels').map((key, index) => {
                            const colors = [
                                'rgba(50, 231, 104, 0.8)',
                                'rgba(59, 130, 246, 0.8)',
                                'rgba(168, 85, 247, 0.8)',
                                'rgba(236, 72, 153, 0.8)'
                            ];
                            return {
                                label: key.charAt(0).toUpperCase() + key.slice(1).replace(/_/g, ' '),
                                data: chartData[key],
                                backgroundColor: colors[index % colors.length],
                                borderColor: colors[index % colors.length].replace('0.8', '1'),
                                borderWidth: 2,
                                fill: type === 'line' ? true : false
                            };
                        })
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                labels: {
                                    color: '#9ca3af'
                                }
                            }
                        },
                        scales: type !== 'pie' ? {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    color: '#9ca3af'
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
                        } : {}
                    }
                });
            }

            // Renderizar resultados
            function renderResults(data) {
                currentReportData = data;
                btnExportCsv.style.display = 'inline-flex';

                let html = '';

                // Métricas (cards)
                if (data.metrics && Object.keys(data.metrics).length > 0) {
                    html += '<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">';
                    for (const [key, value] of Object.entries(data.metrics)) {
                        const labels = {
                            'total_vendas': 'Total de Vendas',
                            'total_faturamento': 'Faturamento Total',
                            'total_aprovadas': 'Vendas Aprovadas',
                            'total_usuarios': 'Total de Usuários',
                            'total_abandonados': 'Carrinhos Abandonados',
                            'valor_total_abandonado': 'Valor Abandonado'
                        };
                        const icon = key.includes('faturamento') || key.includes('valor') ? 'dollar-sign' : 
                                    key.includes('usuarios') ? 'users' : 'shopping-cart';
                        html += `
                            <div class="metric-card p-5 rounded-lg">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <p class="text-gray-400 text-sm">${labels[key] || key}</p>
                                        <p class="text-2xl font-bold text-white mt-2">
                                            ${key.includes('faturamento') || key.includes('valor') ? formatCurrency(value) : formatNumber(value)}
                                        </p>
                                    </div>
                                    <i data-lucide="${icon}" class="w-8 h-8 text-[#32e768]"></i>
                                </div>
                            </div>
                        `;
                    }
                    html += '</div>';
                }

                // Gráfico
                if (data.chart_data && data.chart_data.labels && data.chart_data.labels.length > 0) {
                    html += `
                        <div class="bg-dark-card p-6 rounded-lg shadow-md border border-dark-border mb-6">
                            <h3 class="text-xl font-semibold text-white mb-4">Visualização Gráfica</h3>
                            <div style="height: 400px;">
                                <canvas id="report-chart"></canvas>
                            </div>
                        </div>
                    `;
                }

                // Tabela de dados
                if (data.data && data.data.length > 0) {
                    html += `
                        <div class="bg-dark-card p-6 rounded-lg shadow-md border border-dark-border">
                            <h3 class="text-xl font-semibold text-white mb-4">Dados Detalhados</h3>
                            <div class="overflow-x-auto">
                                <table class="w-full text-left">
                                    <thead>
                                        <tr class="border-b border-dark-border">
                    `;
                    
                    // Cabeçalhos da tabela baseado no tipo de relatório
                    const headers = getTableHeaders(data.report_type);
                    headers.forEach(header => {
                        html += `<th class="px-4 py-3 text-gray-300 font-semibold">${header}</th>`;
                    });
                    
                    html += `
                                        </tr>
                                    </thead>
                                    <tbody>
                    `;

                    data.data.forEach((row, index) => {
                        html += `<tr class="border-b border-dark-border ${index % 2 === 0 ? 'bg-dark-elevated' : ''}">`;
                        html += renderTableRow(row, data.report_type);
                        html += '</tr>';
                    });

                    html += `
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    `;
                } else {
                    html += `
                        <div class="bg-dark-card p-6 rounded-lg shadow-md border border-dark-border text-center">
                            <i data-lucide="inbox" class="mx-auto w-16 h-16 text-gray-500"></i>
                            <p class="mt-4 text-gray-400 font-medium">Nenhum dado encontrado para os filtros selecionados.</p>
                        </div>
                    `;
                }

                relatorioOutput.innerHTML = html;
                lucide.createIcons();

                // Renderizar gráfico após inserir o canvas
                if (data.chart_data && data.chart_data.labels && data.chart_data.labels.length > 0) {
                    setTimeout(() => {
                        const chartType = data.report_type === 'status_vendas' ? 'bar' : 'line';
                        renderChart(data.chart_data, chartType);
                    }, 100);
                }
            }

            function getTableHeaders(reportType) {
                const headersMap = {
                    'vendas_periodo': ['Data', 'Quantidade', 'Faturamento', 'Vendas Aprovadas'],
                    'produtos_vendidos': ['Produto', 'Quantidade Vendida', 'Faturamento Total', 'Vendas Aprovadas'],
                    'atividade_usuarios': ['Data', 'Novos Cadastros', 'Conversões'],
                    'faturamento_vendedores': ['Vendedor', 'Total de Vendas', 'Faturamento Total', 'Vendas Aprovadas'],
                    'metodos_pagamento': ['Método', 'Quantidade', 'Faturamento', 'Aprovadas'],
                    'status_vendas': ['Status', 'Quantidade', 'Valor Total'],
                    'carrinhos_abandonados': ['Data', 'Quantidade Abandonados', 'Valor Abandonado', 'Com Info Preenchida']
                };
                return headersMap[reportType] || [];
            }

            function renderTableRow(row, reportType) {
                let html = '';
                
                if (reportType === 'vendas_periodo') {
                    html += `<td class="px-4 py-3 text-gray-300">${new Date(row.dia).toLocaleDateString('pt-BR')}</td>`;
                    html += `<td class="px-4 py-3 text-gray-300">${formatNumber(row.quantidade)}</td>`;
                    html += `<td class="px-4 py-3 text-gray-300">${formatCurrency(row.faturamento)}</td>`;
                    html += `<td class="px-4 py-3 text-gray-300">${formatNumber(row.vendas_aprovadas)}</td>`;
                } else if (reportType === 'produtos_vendidos') {
                    html += `<td class="px-4 py-3 text-gray-300">${row.nome}</td>`;
                    html += `<td class="px-4 py-3 text-gray-300">${formatNumber(row.quantidade_vendida)}</td>`;
                    html += `<td class="px-4 py-3 text-gray-300">${formatCurrency(row.faturamento_total)}</td>`;
                    html += `<td class="px-4 py-3 text-gray-300">${formatNumber(row.vendas_aprovadas)}</td>`;
                } else if (reportType === 'atividade_usuarios') {
                    html += `<td class="px-4 py-3 text-gray-300">${new Date(row.dia).toLocaleDateString('pt-BR')}</td>`;
                    html += `<td class="px-4 py-3 text-gray-300">${formatNumber(row.novos_cadastros)}</td>`;
                    html += `<td class="px-4 py-3 text-gray-300">${formatNumber(row.conversoes)}</td>`;
                } else if (reportType === 'faturamento_vendedores') {
                    html += `<td class="px-4 py-3 text-gray-300">${row.nome || row.usuario}</td>`;
                    html += `<td class="px-4 py-3 text-gray-300">${formatNumber(row.total_vendas)}</td>`;
                    html += `<td class="px-4 py-3 text-gray-300">${formatCurrency(row.faturamento_total)}</td>`;
                    html += `<td class="px-4 py-3 text-gray-300">${formatNumber(row.vendas_aprovadas)}</td>`;
                } else if (reportType === 'metodos_pagamento') {
                    html += `<td class="px-4 py-3 text-gray-300">${row.metodo_pagamento || 'Não informado'}</td>`;
                    html += `<td class="px-4 py-3 text-gray-300">${formatNumber(row.quantidade)}</td>`;
                    html += `<td class="px-4 py-3 text-gray-300">${formatCurrency(row.faturamento)}</td>`;
                    html += `<td class="px-4 py-3 text-gray-300">${formatNumber(row.aprovadas)}</td>`;
                } else if (reportType === 'status_vendas') {
                    const statusMap = {
                        'approved': 'Aprovadas',
                        'pending': 'Pendentes',
                        'in_process': 'Em Processamento',
                        'refunded': 'Reembolsadas',
                        'charged_back': 'Chargeback',
                        'cancelled': 'Canceladas',
                        'info_filled': 'Info Preenchida'
                    };
                    html += `<td class="px-4 py-3 text-gray-300">${statusMap[row.status_pagamento] || row.status_pagamento}</td>`;
                    html += `<td class="px-4 py-3 text-gray-300">${formatNumber(row.quantidade)}</td>`;
                    html += `<td class="px-4 py-3 text-gray-300">${formatCurrency(row.valor_total)}</td>`;
                } else if (reportType === 'carrinhos_abandonados') {
                    html += `<td class="px-4 py-3 text-gray-300">${new Date(row.dia).toLocaleDateString('pt-BR')}</td>`;
                    html += `<td class="px-4 py-3 text-gray-300">${formatNumber(row.quantidade_abandonados)}</td>`;
                    html += `<td class="px-4 py-3 text-gray-300">${formatCurrency(row.valor_abandonado)}</td>`;
                    html += `<td class="px-4 py-3 text-gray-300">${formatNumber(row.com_info_preenchida)}</td>`;
                }
                
                return html;
            }

            // Exportar CSV
            btnExportCsv.addEventListener('click', function() {
                if (!currentReportData || !currentReportData.data) {
                    alert('Nenhum dado para exportar');
                    return;
                }

                const headers = getTableHeaders(currentReportData.report_type);
                let csv = headers.join(',') + '\n';

                currentReportData.data.forEach(row => {
                    const values = [];
                    if (currentReportData.report_type === 'vendas_periodo') {
                        values.push(new Date(row.dia).toLocaleDateString('pt-BR'), row.quantidade, row.faturamento, row.vendas_aprovadas);
                    } else if (currentReportData.report_type === 'produtos_vendidos') {
                        values.push(`"${row.nome}"`, row.quantidade_vendida, row.faturamento_total, row.vendas_aprovadas);
                    } else if (currentReportData.report_type === 'atividade_usuarios') {
                        values.push(new Date(row.dia).toLocaleDateString('pt-BR'), row.novos_cadastros, row.conversoes);
                    } else if (currentReportData.report_type === 'faturamento_vendedores') {
                        values.push(`"${row.nome || row.usuario}"`, row.total_vendas, row.faturamento_total, row.vendas_aprovadas);
                    } else if (currentReportData.report_type === 'metodos_pagamento') {
                        values.push(row.metodo_pagamento || 'Não informado', row.quantidade, row.faturamento, row.aprovadas);
                    } else if (currentReportData.report_type === 'status_vendas') {
                        const statusMap = {
                            'approved': 'Aprovadas',
                            'pending': 'Pendentes',
                            'in_process': 'Em Processamento',
                            'refunded': 'Reembolsadas',
                            'charged_back': 'Chargeback',
                            'cancelled': 'Canceladas',
                            'info_filled': 'Info Preenchida'
                        };
                        values.push(statusMap[row.status_pagamento] || row.status_pagamento, row.quantidade, row.valor_total);
                    } else if (currentReportData.report_type === 'carrinhos_abandonados') {
                        values.push(new Date(row.dia).toLocaleDateString('pt-BR'), row.quantidade_abandonados, row.valor_abandonado, row.com_info_preenchida);
                    }
                    csv += values.join(',') + '\n';
                });

                const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
                const link = document.createElement('a');
                const url = URL.createObjectURL(blob);
                link.setAttribute('href', url);
                link.setAttribute('download', `relatorio_${currentReportData.report_type}_${new Date().toISOString().split('T')[0]}.csv`);
                link.style.visibility = 'hidden';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            });

            // Submeter formulário
            relatoriosForm.addEventListener('submit', function(e) {
                e.preventDefault();

                const formData = {
                    report_type: tipoRelatorioSelect.value,
                    date_start: document.getElementById('data_inicio').value || null,
                    date_end: document.getElementById('data_fim').value || null,
                    status_filter: document.getElementById('status_filter')?.value || null,
                    vendedor_filter: document.getElementById('vendedor_filter')?.value || null,
                    produto_filter: document.getElementById('produto_filter')?.value || null,
                    metodo_filter: document.getElementById('metodo_filter')?.value || null
                };

                relatorioOutput.innerHTML = `
                    <div class="text-center py-12 text-gray-400">
                        <svg class="animate-spin h-8 w-8 text-[#32e768] mx-auto" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.96l2-2.669z"></path>
                        </svg>
                        <p class="mt-4 font-medium">Gerando relatório...</p>
                    </div>
                `;

                fetch('/api/admin_api.php?action=generate_report', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify(formData)
                })
                .then(async response => {
                    if (!response.ok) {
                        const text = await response.text();
                        console.error('Erro HTTP:', response.status, text);
                        throw new Error(`Erro HTTP ${response.status}: ${text.substring(0, 200)}`);
                    }
                    const contentType = response.headers.get('content-type');
                    if (!contentType || !contentType.includes('application/json')) {
                        const text = await response.text();
                        console.error('Resposta não é JSON:', text.substring(0, 500));
                        throw new Error('Resposta do servidor não é JSON. Verifique se a API está funcionando.');
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.success) {
                        renderResults(data);
                    } else {
                        relatorioOutput.innerHTML = `
                            <div class="bg-red-900/20 border border-red-500/50 p-6 rounded-lg text-center">
                                <i data-lucide="alert-circle" class="mx-auto w-16 h-16 text-red-500"></i>
                                <p class="mt-4 text-red-400 font-medium">Erro ao gerar relatório</p>
                                <p class="mt-1 text-sm text-red-300">${data.error || 'Erro desconhecido'}</p>
                            </div>
                        `;
                        lucide.createIcons();
                    }
                })
                .catch(error => {
                    console.error('Erro:', error);
                    relatorioOutput.innerHTML = `
                        <div class="bg-red-900/20 border border-red-500/50 p-6 rounded-lg text-center">
                            <i data-lucide="alert-circle" class="mx-auto w-16 h-16 text-red-500"></i>
                            <p class="mt-4 text-red-400 font-medium">Erro ao gerar relatório</p>
                            <p class="mt-1 text-sm text-red-300">${error.message}</p>
                            <p class="mt-2 text-xs text-gray-400">Verifique o console do navegador para mais detalhes.</p>
                        </div>
                    `;
                    lucide.createIcons();
                });
            });
        });
    </script>
</body>
</html>
