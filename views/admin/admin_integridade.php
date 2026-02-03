<!-- Página de Verificação de Integridade do Banco de Dados -->
<div class="container mx-auto">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-3xl font-bold text-white">Verificação de Integridade</h1>
            <p class="text-gray-400 mt-1">Verifique e corrija a estrutura do banco de dados</p>
        </div>
    </div>

    <!-- Status Geral -->
    <div id="integrity-status" class="bg-dark-card p-6 rounded-xl shadow-sm mb-6 border border-dark-border">
        <h2 class="text-xl font-semibold text-white mb-4 flex items-center space-x-2">
            <i data-lucide="shield-check" class="w-5 h-5"></i>
            <span>Status da Integridade</span>
        </h2>
        <div id="status-content" class="text-center py-8">
            <div class="inline-flex items-center space-x-2 text-gray-400">
                <i data-lucide="loader" class="w-5 h-5 animate-spin"></i>
                <span>Carregando...</span>
            </div>
        </div>
    </div>

    <!-- Ações -->
    <div class="bg-dark-card p-6 rounded-xl shadow-sm mb-6 border border-dark-border">
        <h2 class="text-xl font-semibold text-white mb-4 flex items-center space-x-2">
            <i data-lucide="settings" class="w-5 h-5"></i>
            <span>Ações</span>
        </h2>
        <div class="flex items-center space-x-3">
            <button 
                id="btn-check-integrity" 
                class="bg-primary hover:bg-primary/80 text-white font-medium py-2 px-6 rounded-lg transition flex items-center space-x-2"
            >
                <i data-lucide="refresh-cw" class="w-4 h-4"></i>
                <span>Verificar Integridade</span>
            </button>
            <button 
                id="btn-fix-integrity" 
                class="bg-green-600 hover:bg-green-700 text-white font-medium py-2 px-6 rounded-lg transition flex items-center space-x-2 disabled:opacity-50 disabled:cursor-not-allowed"
                disabled
            >
                <i data-lucide="wrench" class="w-4 h-4"></i>
                <span>Corrigir Automaticamente</span>
            </button>
        </div>
    </div>

    <!-- Detalhes das Tabelas -->
    <div id="tables-details" class="bg-dark-card p-6 rounded-xl shadow-sm mb-6 border border-dark-border hidden">
        <h2 class="text-xl font-semibold text-white mb-4 flex items-center space-x-2">
            <i data-lucide="database" class="w-5 h-5"></i>
            <span>Detalhes das Tabelas</span>
        </h2>
        <div class="overflow-x-auto">
            <table class="w-full text-left">
                <thead>
                    <tr class="border-b border-dark-border">
                        <th class="pb-3 text-sm font-semibold text-gray-400">Tabela</th>
                        <th class="pb-3 text-sm font-semibold text-gray-400">Status</th>
                        <th class="pb-3 text-sm font-semibold text-gray-400">Colunas Faltando</th>
                        <th class="pb-3 text-sm font-semibold text-gray-400">Migration</th>
                    </tr>
                </thead>
                <tbody id="tables-list" class="text-gray-300">
                    <!-- Preenchido via JavaScript -->
                </tbody>
            </table>
        </div>
    </div>

    <!-- Resultado da Correção -->
    <div id="fix-result" class="hidden mb-6">
        <div class="bg-dark-card p-6 rounded-xl shadow-sm border border-dark-border">
            <h3 class="text-xl font-semibold text-white mb-4">Resultado da Correção</h3>
            <div id="fix-result-content"></div>
        </div>
    </div>

    <!-- Progresso da Correção -->
    <div id="fix-progress" class="hidden mb-6">
        <div class="bg-dark-card p-6 rounded-xl shadow-sm border border-primary">
            <h3 class="text-xl font-semibold text-white mb-4">Corrigindo Banco de Dados...</h3>
            <div class="space-y-4">
                <div>
                    <div class="flex justify-between text-sm text-gray-400 mb-1">
                        <span id="fix-progress-step">Iniciando...</span>
                        <span id="fix-progress-percent">0%</span>
                    </div>
                    <div class="w-full bg-dark-elevated rounded-full h-2">
                        <div id="fix-progress-bar" class="bg-primary h-2 rounded-full transition-all duration-300" style="width: 0%"></div>
                    </div>
                </div>
                <div id="fix-log" class="bg-dark-elevated rounded-lg p-4 max-h-64 overflow-y-auto space-y-2 text-sm font-mono">
                    <!-- Log será preenchido via JavaScript -->
                </div>
            </div>
        </div>
    </div>

</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const btnCheckIntegrity = document.getElementById('btn-check-integrity');
    const btnFixIntegrity = document.getElementById('btn-fix-integrity');
    const statusContent = document.getElementById('status-content');
    const tablesDetails = document.getElementById('tables-details');
    const tablesList = document.getElementById('tables-list');
    const fixResult = document.getElementById('fix-result');
    const fixResultContent = document.getElementById('fix-result-content');
    const fixProgress = document.getElementById('fix-progress');
    const fixLog = document.getElementById('fix-log');
    
    let integrityData = null;
    
    // Verificar integridade ao carregar a página
    checkIntegrity();
    
    // Botão verificar integridade
    btnCheckIntegrity.addEventListener('click', function() {
        checkIntegrity();
    });
    
    // Botão corrigir
    btnFixIntegrity.addEventListener('click', function() {
        if (!integrityData || integrityData.status === 'ok') {
            return;
        }
        
        const missingTables = integrityData.missing_tables ? integrityData.missing_tables.length : 0;
        const missingColumns = integrityData.total_missing_columns || 0;
        
        let confirmMessage = 'Deseja executar as migrations faltantes? Esta ação irá:\n';
        if (missingTables > 0) {
            confirmMessage += `- Criar ${missingTables} tabela(s) faltante(s)\n`;
        }
        if (missingColumns > 0) {
            confirmMessage += `- Adicionar ${missingColumns} coluna(s) faltante(s)\n`;
        }
        confirmMessage += '\nTem certeza que deseja continuar?';
        
        if (!confirm(confirmMessage)) {
            return;
        }
        
        fixIntegrity();
    });
    
    function checkIntegrity() {
        btnCheckIntegrity.disabled = true;
        btnCheckIntegrity.innerHTML = '<i data-lucide="loader" class="w-4 h-4 animate-spin"></i> <span>Verificando...</span>';
        statusContent.innerHTML = '<div class="inline-flex items-center space-x-2 text-gray-400"><i data-lucide="loader" class="w-5 h-5 animate-spin"></i><span>Verificando integridade...</span></div>';
        
        fetch('/api/admin_api.php?action=check_integrity')
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }
                return response.json();
            })
            .then(data => {
                if (data.success) {
                    integrityData = data.data;
                    displayIntegrityStatus(data.data);
                    displayTablesDetails(data.data);
                    
                    // Habilitar botão de correção se houver tabelas ou colunas faltantes
                    const hasMissingTables = data.data.missing_tables && data.data.missing_tables.length > 0;
                    const hasMissingColumns = data.data.total_missing_columns && data.data.total_missing_columns > 0;
                    
                    if (hasMissingTables || hasMissingColumns) {
                        btnFixIntegrity.disabled = false;
                    } else {
                        btnFixIntegrity.disabled = true;
                    }
                } else {
                    throw new Error(data.error || 'Erro desconhecido');
                }
            })
            .catch(error => {
                console.error('Erro ao verificar integridade:', error);
                statusContent.innerHTML = `
                    <div class="flex items-center space-x-2 text-red-400">
                        <i data-lucide="alert-circle" class="w-5 h-5"></i>
                        <span>Erro ao verificar integridade: ${error.message}</span>
                    </div>
                `;
            })
            .finally(() => {
                btnCheckIntegrity.disabled = false;
                btnCheckIntegrity.innerHTML = '<i data-lucide="refresh-cw" class="w-4 h-4"></i> <span>Verificar Integridade</span>';
                lucide.createIcons();
            });
    }
    
    function displayIntegrityStatus(data) {
        const status = data.status;
        const totalExpected = data.total_expected || 0;
        const totalExisting = data.total_existing || 0;
        const missingCount = data.missing_tables ? data.missing_tables.length : 0;
        const missingColumnsCount = data.total_missing_columns || 0;
        
        let statusHtml = '';
        let statusColor = '';
        let statusIcon = '';
        let statusText = '';
        
        if (status === 'ok') {
            statusColor = 'text-green-400';
            statusIcon = 'check-circle';
            statusText = 'Banco de dados íntegro';
        } else {
            statusColor = 'text-yellow-400';
            statusIcon = 'alert-triangle';
            statusText = 'Problemas detectados';
        }
        
        statusHtml = `
            <div class="flex items-center space-x-4">
                <div class="bg-${status === 'ok' ? 'green' : 'yellow'}-500/20 p-4 rounded-full">
                    <i data-lucide="${statusIcon}" class="w-8 h-8 ${statusColor}"></i>
                </div>
                <div class="flex-1">
                    <h3 class="text-2xl font-semibold text-white mb-2">${statusText}</h3>
                    <div class="space-y-1 text-gray-400">
                        <p>Tabelas esperadas: <span class="text-white font-medium">${totalExpected}</span></p>
                        <p>Tabelas existentes: <span class="text-white font-medium">${totalExisting}</span></p>
                        ${missingCount > 0 ? `<p class="text-yellow-400">Tabelas faltando: <span class="font-medium">${missingCount}</span></p>` : ''}
                        ${missingColumnsCount > 0 ? `<p class="text-yellow-400">Colunas faltando: <span class="font-medium">${missingColumnsCount}</span></p>` : ''}
                    </div>
                </div>
            </div>
        `;
        
        statusContent.innerHTML = statusHtml;
        lucide.createIcons();
    }
    
    function displayTablesDetails(data) {
        if (!data.details || Object.keys(data.details).length === 0) {
            tablesDetails.classList.add('hidden');
            return;
        }
        
        tablesDetails.classList.remove('hidden');
        let html = '';
        
        // Ordenar tabelas: faltando primeiro, depois existentes
        const sortedTables = Object.keys(data.details).sort((a, b) => {
            const aStatus = data.details[a].status;
            const bStatus = data.details[b].status;
            if (aStatus === 'missing' && bStatus !== 'missing') return -1;
            if (aStatus !== 'missing' && bStatus === 'missing') return 1;
            return a.localeCompare(b);
        });
        
        sortedTables.forEach(tableName => {
            const detail = data.details[tableName];
            const status = detail.status;
            let statusClass = 'text-green-400';
            let statusIcon = 'check-circle';
            let statusText = 'OK';
            
            if (status === 'missing') {
                statusClass = 'text-red-400';
                statusIcon = 'x-circle';
                statusText = 'Tabela faltando';
            } else if (status === 'columns_missing') {
                statusClass = 'text-yellow-400';
                statusIcon = 'alert-triangle';
                statusText = 'Colunas faltando';
            }
            
            const missingColumns = detail.missing_columns || [];
            const missingColumnsText = missingColumns.length > 0 
                ? `<span class="text-yellow-400">${missingColumns.length} coluna(s): ${missingColumns.slice(0, 3).join(', ')}${missingColumns.length > 3 ? '...' : ''}</span>`
                : '<span class="text-gray-500">Nenhuma</span>';
            
            html += `
                <tr class="border-b border-dark-border">
                    <td class="py-3 text-white font-medium">${tableName}</td>
                    <td class="py-3">
                        <span class="inline-flex items-center space-x-1 ${statusClass}">
                            <i data-lucide="${statusIcon}" class="w-4 h-4"></i>
                            <span>${statusText}</span>
                        </span>
                    </td>
                    <td class="py-3 text-sm">${missingColumnsText}</td>
                    <td class="py-3 text-gray-400 text-sm">${detail.migration || '-'}</td>
                </tr>
            `;
        });
        
        tablesList.innerHTML = html;
        lucide.createIcons();
    }
    
    function fixIntegrity() {
        btnFixIntegrity.disabled = true;
        fixProgress.classList.remove('hidden');
        fixResult.classList.add('hidden');
        fixLog.innerHTML = '';
        updateFixProgress(0, 'Iniciando correção...');
        
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        
        fetch('/api/admin_api.php?action=run_missing_migrations', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                csrf_token: csrfToken
            })
        })
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            return response.json();
        })
        .then(data => {
            updateFixProgress(100, 'Correção concluída!');
            
            let resultHtml = '';
            if (data.success) {
                resultHtml = `
                    <div class="flex items-start space-x-4">
                        <div class="bg-green-500/20 p-3 rounded-full">
                            <i data-lucide="check-circle" class="w-6 h-6 text-green-500"></i>
                        </div>
                        <div class="flex-1">
                            <h4 class="text-lg font-semibold text-white mb-2">Correção Concluída com Sucesso!</h4>
                            <p class="text-gray-400 mb-4">${data.message || 'Migrations executadas com sucesso.'}</p>
                            ${data.migrations ? `
                                <div class="space-y-2 text-sm mb-4">
                                    <p class="text-gray-300 font-semibold">Migrations:</p>
                                    <p class="text-gray-400">Executadas: <span class="text-white font-medium">${data.migrations.executed?.length || 0}</span></p>
                                    <p class="text-gray-400">Ignoradas: <span class="text-white font-medium">${data.migrations.skipped?.length || 0}</span></p>
                                    ${data.migrations.errors && data.migrations.errors.length > 0 ? `
                                        <p class="text-red-400">Erros: <span class="font-medium">${data.migrations.errors.length}</span></p>
                                        <div class="bg-red-500/10 border border-red-500/20 rounded-lg p-3 mt-2">
                                            <ul class="list-disc list-inside space-y-1">
                                                ${data.migrations.errors.map(err => `<li class="text-red-400">${err.migration || 'Sistema'}: ${err.error}</li>`).join('')}
                                            </ul>
                                        </div>
                                    ` : ''}
                                </div>
                            ` : ''}
                            ${data.columns_fixed ? `
                                <div class="space-y-2 text-sm">
                                    <p class="text-gray-300 font-semibold">Colunas Adicionadas:</p>
                                    <p class="text-gray-400">Adicionadas: <span class="text-white font-medium">${data.columns_fixed.added?.filter(c => c.status === 'added').length || 0}</span></p>
                                    ${data.columns_fixed.added && data.columns_fixed.added.length > 0 ? `
                                        <div class="bg-green-500/10 border border-green-500/20 rounded-lg p-3 mt-2">
                                            <ul class="list-disc list-inside space-y-1">
                                                ${data.columns_fixed.added.filter(c => c.status === 'added').map(col => `<li class="text-green-400">${col.table}.${col.column}</li>`).join('')}
                                            </ul>
                                        </div>
                                    ` : ''}
                                    ${data.columns_fixed.errors && data.columns_fixed.errors.length > 0 ? `
                                        <p class="text-red-400 mt-2">Erros: <span class="font-medium">${data.columns_fixed.errors.length}</span></p>
                                        <div class="bg-red-500/10 border border-red-500/20 rounded-lg p-3 mt-2">
                                            <ul class="list-disc list-inside space-y-1">
                                                ${data.columns_fixed.errors.map(err => {
                                                    let errorMsg = err.error || 'Erro desconhecido';
                                                    if (err.migration) errorMsg += ` (Migration: ${err.migration})`;
                                                    if (err.sql) errorMsg += ` | SQL: ${err.sql}`;
                                                    return `<li class="text-red-400">${err.table}.${err.column || ''}: ${errorMsg}</li>`;
                                                }).join('')}
                                            </ul>
                                        </div>
                                    ` : ''}
                                </div>
                            ` : ''}
                        </div>
                    </div>
                `;
            } else {
                resultHtml = `
                    <div class="flex items-start space-x-4">
                        <div class="bg-red-500/20 p-3 rounded-full">
                            <i data-lucide="alert-circle" class="w-6 h-6 text-red-500"></i>
                        </div>
                        <div class="flex-1">
                            <h4 class="text-lg font-semibold text-white mb-2">Erro na Correção</h4>
                            <p class="text-red-400">${data.error || data.message || 'Erro desconhecido ao executar migrations.'}</p>
                        </div>
                    </div>
                `;
            }
            
            fixResultContent.innerHTML = resultHtml;
            fixProgress.classList.add('hidden');
            fixResult.classList.remove('hidden');
            
            // Verificar integridade novamente após correção
            setTimeout(() => {
                checkIntegrity();
            }, 1000);
        })
        .catch(error => {
            console.error('Erro ao corrigir integridade:', error);
            updateFixProgress(0, 'Erro na correção');
            fixResultContent.innerHTML = `
                <div class="flex items-start space-x-4">
                    <div class="bg-red-500/20 p-3 rounded-full">
                        <i data-lucide="alert-circle" class="w-6 h-6 text-red-500"></i>
                    </div>
                    <div class="flex-1">
                        <h4 class="text-lg font-semibold text-white mb-2">Erro na Correção</h4>
                        <p class="text-red-400">${error.message}</p>
                    </div>
                </div>
            `;
            fixProgress.classList.add('hidden');
            fixResult.classList.remove('hidden');
        })
        .finally(() => {
            btnFixIntegrity.disabled = false;
            lucide.createIcons();
        });
    }
    
    function updateFixProgress(percent, step) {
        document.getElementById('fix-progress-percent').textContent = percent + '%';
        document.getElementById('fix-progress-step').textContent = step;
        document.getElementById('fix-progress-bar').style.width = percent + '%';
        
        if (step) {
            const logEntry = document.createElement('div');
            logEntry.className = 'text-gray-300';
            logEntry.textContent = `[${new Date().toLocaleTimeString()}] ${step}`;
            fixLog.appendChild(logEntry);
            fixLog.scrollTop = fixLog.scrollHeight;
        }
    }
    
    // Atualizar ícones do Lucide
    lucide.createIcons();
});
</script>

