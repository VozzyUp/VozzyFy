<!-- Página de Atualizações do Sistema -->
<div class="container mx-auto">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-3xl font-bold text-white">Atualizações do Sistema</h1>
            <p class="text-gray-400 mt-1">Gerencie atualizações e versões da plataforma</p>
        </div>
    </div>

    <!-- Configurações do GitHub -->
    <div class="bg-dark-card p-6 rounded-xl shadow-sm mb-6 border border-dark-border">
        <h2 class="text-xl font-semibold text-white mb-4 flex items-center space-x-2">
            <i data-lucide="settings" class="w-5 h-5"></i>
            <span>Configurações do GitHub</span>
        </h2>
        <p class="text-gray-400 mb-4 text-sm">Configure o token de acesso para verificar e baixar atualizações</p>
        
        <form id="github-config-form" class="space-y-4">
            <div>
                <label for="github_token" class="block text-sm font-medium text-gray-300 mb-2">
                    Token de Acesso<span class="text-red-400">*</span>
                </label>
                <input 
                    type="password" 
                    id="github_token" 
                    name="github_token" 
                    placeholder="ghp_xxxxxxxxxxxxxxxxxxxx"
                    class="w-full bg-dark-elevated border border-dark-border rounded-lg px-4 py-2 text-white focus:outline-none focus:ring-2 focus:ring-primary"
                    required
                >
            </div>
            
            <div class="flex items-center space-x-3">
                <button 
                    type="submit" 
                    id="btn-save-github-config"
                    class="bg-primary hover:bg-primary/80 text-white font-medium py-2 px-6 rounded-lg transition flex items-center space-x-2"
                >
                    <i data-lucide="save" class="w-4 h-4"></i>
                    <span>Salvar Token</span>
                </button>
                <button 
                    type="button" 
                    id="btn-test-github-config"
                    class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-lg transition flex items-center space-x-2"
                >
                    <i data-lucide="check-circle" class="w-4 h-4"></i>
                    <span>Testar Conexão</span>
                </button>
            </div>
            
            <div id="github-config-message" class="hidden mt-4 p-3 rounded-lg"></div>
        </form>
    </div>

    <!-- Informações da Versão Atual -->
    <div class="bg-dark-card p-6 rounded-xl shadow-sm mb-6 border border-primary">
        <h2 class="text-xl font-semibold text-white mb-4">Versão Atual</h2>
        <div class="flex items-center space-x-4">
            <div class="bg-primary/20 p-3 rounded-full">
                <i data-lucide="package" class="w-6 h-6 text-primary"></i>
            </div>
            <div>
                <p class="text-gray-400 text-sm">Versão Instalada</p>
                <p id="current-version" class="text-2xl font-bold text-white">Carregando...</p>
            </div>
        </div>
    </div>

    <!-- Verificação de Atualizações -->
    <div class="bg-dark-card p-6 rounded-xl shadow-sm mb-6 border border-dark-border">
        <h2 class="text-xl font-semibold text-white mb-4">Verificar Atualizações</h2>
        <p class="text-gray-400 mb-4">Clique no botão abaixo para verificar se há atualizações disponíveis no GitHub</p>
        <button 
            id="btn-check-updates" 
            class="bg-primary hover:bg-primary/80 text-white font-medium py-2 px-6 rounded-lg transition flex items-center space-x-2"
        >
            <i data-lucide="refresh-cw" class="w-4 h-4"></i>
            <span>Verificar Atualizações</span>
        </button>
    </div>

    <!-- Resultado da Verificação -->
    <div id="update-check-result" class="hidden mb-6">
        <div class="bg-dark-card p-6 rounded-xl shadow-sm border border-dark-border">
            <div id="update-available" class="hidden">
                <div class="flex items-start space-x-4 mb-4">
                    <div class="bg-green-500/20 p-3 rounded-full">
                        <i data-lucide="download" class="w-6 h-6 text-green-500"></i>
                    </div>
                    <div class="flex-1">
                        <h3 class="text-xl font-semibold text-white mb-2">Atualização Disponível!</h3>
                        <p class="text-gray-400 mb-2">
                            Versão atual: <span id="local-version-display" class="text-white font-medium"></span>
                        </p>
                        <p class="text-gray-400 mb-4">
                            Nova versão: <span id="remote-version-display" class="text-green-400 font-bold"></span>
                        </p>
                        <div id="release-info" class="mb-4"></div>
                        <button 
                            id="btn-start-update" 
                            class="bg-green-600 hover:bg-green-700 text-white font-medium py-2 px-6 rounded-lg transition"
                        >
                            Atualizar Agora
                        </button>
                    </div>
                </div>
            </div>
            
            <div id="no-update" class="hidden">
                <div class="flex items-center space-x-4">
                    <div class="bg-blue-500/20 p-3 rounded-full">
                        <i data-lucide="check-circle" class="w-6 h-6 text-blue-500"></i>
                    </div>
                    <div>
                        <h3 class="text-xl font-semibold text-white mb-2">Sistema Atualizado</h3>
                        <p class="text-gray-400">Você está usando a versão mais recente disponível.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Progresso da Atualização -->
    <div id="update-progress" class="hidden mb-6">
        <div class="bg-dark-card p-6 rounded-xl shadow-sm border border-primary">
            <h3 class="text-xl font-semibold text-white mb-4">Atualizando Sistema...</h3>
            <div class="space-y-4">
                <div>
                    <div class="flex justify-between text-sm text-gray-400 mb-1">
                        <span id="progress-step">Iniciando...</span>
                        <span id="progress-percent">0%</span>
                    </div>
                    <div class="w-full bg-dark-elevated rounded-full h-2">
                        <div id="progress-bar" class="bg-primary h-2 rounded-full transition-all duration-300" style="width: 0%"></div>
                    </div>
                </div>
                <div id="update-log" class="bg-dark-elevated rounded-lg p-4 max-h-64 overflow-y-auto space-y-2 text-sm font-mono">
                    <!-- Log será preenchido via JavaScript -->
                </div>
            </div>
        </div>
    </div>

</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const btnCheckUpdates = document.getElementById('btn-check-updates');
    const btnStartUpdate = document.getElementById('btn-start-update');
    const updateCheckResult = document.getElementById('update-check-result');
    const updateAvailable = document.getElementById('update-available');
    const noUpdate = document.getElementById('no-update');
    const updateProgress = document.getElementById('update-progress');
    const currentVersionEl = document.getElementById('current-version');
    
    // ========== CONFIGURAÇÕES DO GITHUB ==========
    
    // Valores padrão
    const DEFAULT_REPO = 'LeonardoIsrael0516/getfy-update';
    const DEFAULT_BRANCH = 'main';
    
    // Carregar configurações do GitHub
    function loadGitHubConfig() {
        fetch('/api/admin_api.php?action=get_github_config')
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }
                return response.json();
            })
            .then(data => {
                if (data.success && data.config) {
                    document.getElementById('github_token').value = data.config.github_token || '';
                }
            })
            .catch(error => {
                console.error('Erro ao carregar configurações do GitHub:', error);
            });
    }
    
    // Salvar configurações do GitHub
    const githubForm = document.getElementById('github-config-form');
    if (githubForm) {
        githubForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const btnSave = document.getElementById('btn-save-github-config');
            const messageDiv = document.getElementById('github-config-message');
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
            
            btnSave.disabled = true;
            btnSave.innerHTML = '<i data-lucide="loader" class="w-4 h-4 animate-spin"></i> <span>Salvando...</span>';
            messageDiv.classList.add('hidden');
            
            const formData = {
                github_repo: DEFAULT_REPO,
                github_token: document.getElementById('github_token').value.trim(),
                github_branch: DEFAULT_BRANCH
            };
            
            try {
                const response = await fetch('/api/admin_api.php?action=save_github_config', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrfToken
                    },
                    body: JSON.stringify(formData)
                });
                
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }
                
                const data = await response.json();
                
                if (data.success) {
                    messageDiv.className = 'mt-4 p-3 rounded-lg bg-green-500/20 border border-green-500 text-green-400';
                    messageDiv.textContent = 'Configurações salvas com sucesso!';
                    messageDiv.classList.remove('hidden');
                } else {
                    throw new Error(data.error || 'Erro ao salvar configurações');
                }
            } catch (error) {
                console.error('Erro ao salvar configurações:', error);
                messageDiv.className = 'mt-4 p-3 rounded-lg bg-red-500/20 border border-red-500 text-red-400';
                messageDiv.textContent = 'Erro ao salvar configurações: ' + error.message;
                messageDiv.classList.remove('hidden');
            } finally {
                btnSave.disabled = false;
                btnSave.innerHTML = '<i data-lucide="save" class="w-4 h-4"></i> <span>Salvar Configurações</span>';
            }
        });
        
        // Testar conexão com GitHub
        const btnTest = document.getElementById('btn-test-github-config');
        if (btnTest) {
            btnTest.addEventListener('click', async function() {
                const messageDiv = document.getElementById('github-config-message');
                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
                
                btnTest.disabled = true;
                btnTest.innerHTML = '<i data-lucide="loader" class="w-4 h-4 animate-spin"></i> <span>Testando...</span>';
                messageDiv.classList.add('hidden');
                
                const formData = {
                    github_repo: DEFAULT_REPO,
                    github_token: document.getElementById('github_token').value.trim(),
                    github_branch: DEFAULT_BRANCH
                };
                
                if (!formData.github_token) {
                    messageDiv.className = 'mt-4 p-3 rounded-lg bg-yellow-500/20 border border-yellow-500 text-yellow-400';
                    messageDiv.textContent = 'Por favor, preencha o token antes de testar.';
                    messageDiv.classList.remove('hidden');
                    btnTest.disabled = false;
                    btnTest.innerHTML = '<i data-lucide="check-circle" class="w-4 h-4"></i> <span>Testar Conexão</span>';
                    return;
                }
                
                try {
                    const response = await fetch('/api/admin_api.php?action=test_github_config', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': csrfToken
                        },
                        body: JSON.stringify(formData)
                    });
                    
                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                    }
                    
                    const data = await response.json();
                    
                    if (data.success) {
                        messageDiv.className = 'mt-4 p-3 rounded-lg bg-green-500/20 border border-green-500 text-green-400';
                        messageDiv.textContent = 'Conexão com repositório bem-sucedida! ' + (data.message || '');
                        messageDiv.classList.remove('hidden');
                    } else {
                        throw new Error(data.error || 'Erro ao testar conexão');
                    }
                } catch (error) {
                    console.error('Erro ao testar conexão:', error);
                    messageDiv.className = 'mt-4 p-3 rounded-lg bg-red-500/20 border border-red-500 text-red-400';
                    messageDiv.textContent = 'Erro ao testar conexão: ' + error.message;
                    messageDiv.classList.remove('hidden');
                } finally {
                    btnTest.disabled = false;
                    btnTest.innerHTML = '<i data-lucide="check-circle" class="w-4 h-4"></i> <span>Testar Conexão</span>';
                }
            });
        }
        
        // Carregar configurações ao iniciar
        loadGitHubConfig();
    }
    
    // ========== FIM CONFIGURAÇÕES DO GITHUB ==========
    
    // Carregar versão atual
    fetch('/api/update_check.php')
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            return response.json();
        })
        .then(data => {
            if (data.success && data.data && data.data.local_version) {
                currentVersionEl.textContent = data.data.local_version;
            } else {
                currentVersionEl.textContent = 'Desconhecida';
            }
        })
        .catch(error => {
            console.error('Erro ao carregar versão:', error);
            currentVersionEl.textContent = 'Erro ao carregar';
        });
    
    // Verificar atualizações
    btnCheckUpdates.addEventListener('click', function() {
        btnCheckUpdates.disabled = true;
        btnCheckUpdates.innerHTML = '<i data-lucide="loader" class="w-4 h-4 animate-spin"></i> <span>Verificando...</span>';
        
        fetch('/api/update_check.php')
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }
                return response.json();
            })
            .then(data => {
                btnCheckUpdates.disabled = false;
                btnCheckUpdates.innerHTML = '<i data-lucide="refresh-cw" class="w-4 h-4"></i> <span>Verificar Atualizações</span>';
                
                if (!data.success) {
                    alert('Erro: ' + (data.error || 'Não foi possível verificar atualizações'));
                    return;
                }
                
                // Verificar se precisa configurar GitHub
                if (data.data && data.data.needs_config) {
                    updateCheckResult.classList.remove('hidden');
                    updateAvailable.classList.add('hidden');
                    noUpdate.classList.remove('hidden');
                    const noUpdateP = noUpdate.querySelector('p');
                    if (noUpdateP) {
                        noUpdateP.textContent = data.data.message || 'Repositório GitHub não configurado. Configure em Configurações do Sistema.';
                    }
                    return;
                }
                
                updateCheckResult.classList.remove('hidden');
                
                if (data.data && data.data.has_update) {
                    updateAvailable.classList.remove('hidden');
                    noUpdate.classList.add('hidden');
                    document.getElementById('local-version-display').textContent = data.data.local_version;
                    document.getElementById('remote-version-display').textContent = data.data.remote_version;
                    
                    // Mostrar informações da release se disponível
                    if (data.data.release_info) {
                        const releaseInfo = data.data.release_info;
                        const releaseDiv = document.getElementById('release-info');
                        releaseDiv.innerHTML = `
                            <div class="bg-dark-elevated p-4 rounded-lg">
                                <h4 class="text-white font-medium mb-2">${releaseInfo.name || releaseInfo.tag}</h4>
                                <p class="text-gray-400 text-sm">${releaseInfo.body ? releaseInfo.body.substring(0, 200) + '...' : ''}</p>
                                ${releaseInfo.html_url ? `<a href="${releaseInfo.html_url}" target="_blank" class="text-primary hover:underline text-sm mt-2 inline-block">Ver no GitHub →</a>` : ''}
                            </div>
                        `;
                    }
                } else {
                    updateAvailable.classList.add('hidden');
                    noUpdate.classList.remove('hidden');
                }
            })
            .catch(error => {
                btnCheckUpdates.disabled = false;
                btnCheckUpdates.innerHTML = '<i data-lucide="refresh-cw" class="w-4 h-4"></i> <span>Verificar Atualizações</span>';
                console.error('Erro ao verificar atualizações:', error);
                alert('Erro ao verificar atualizações: ' + error.message + '\n\nVerifique o console do navegador para mais detalhes.');
            });
    });
    
    // Iniciar atualização
    btnStartUpdate.addEventListener('click', function() {
        if (!confirm('Tem certeza que deseja atualizar o sistema? Um backup será criado automaticamente.')) {
            return;
        }
        
        updateCheckResult.classList.add('hidden');
        updateProgress.classList.remove('hidden');
        
        startUpdate();
    });
    
    function startUpdate() {
        const progressBar = document.getElementById('progress-bar');
        const progressStep = document.getElementById('progress-step');
        const progressPercent = document.getElementById('progress-percent');
        const updateLog = document.getElementById('update-log');
        
        function addLog(message, type = 'info') {
            const logEntry = document.createElement('div');
            logEntry.className = type === 'error' ? 'text-red-400' : (type === 'success' ? 'text-green-400' : 'text-gray-300');
            logEntry.textContent = '[' + new Date().toLocaleTimeString() + '] ' + message;
            updateLog.appendChild(logEntry);
            updateLog.scrollTop = updateLog.scrollHeight;
        }
        
        function updateProgress(percent, step) {
            progressBar.style.width = percent + '%';
            progressPercent.textContent = percent + '%';
            progressStep.textContent = step;
        }
        
        addLog('Iniciando atualização...');
        updateProgress(10, 'Baixando arquivos...');
        
        // 1. Baixar arquivos
        fetch('/api/update_download.php')
            .then(response => response.json())
            .then(data => {
                if (!data.success) {
                    throw new Error(data.error || 'Erro ao baixar arquivos');
                }
                
                addLog(`Arquivos baixados: ${data.data.total_files} arquivos`, 'success');
                updateProgress(40, 'Criando backup...');
                
                // 2. Processar atualização (faz backup, atualiza arquivos, executa migrations)
                return fetch('/api/update_process.php?action=process');
            })
            .then(response => response.json())
            .then(data => {
                if (!data.success) {
                    throw new Error(data.error || 'Erro ao processar atualização');
                }
                
                updateProgress(60, 'Atualizando arquivos...');
                addLog(`Backup criado: ${data.data.backup.folder}`, 'success');
                addLog(`Arquivos atualizados: ${data.data.files_updated.length}`, 'success');
                
                if (data.data.migrations) {
                    updateProgress(80, 'Executando migrations...');
                    if (data.data.migrations.executed.length > 0) {
                        addLog(`Migrations executadas: ${data.data.migrations.executed.join(', ')}`, 'success');
                    }
                    if (data.data.migrations.errors.length > 0) {
                        data.data.migrations.errors.forEach(err => {
                            addLog(`Erro na migration ${err.migration}: ${err.error}`, 'error');
                        });
                    }
                }
                
                updateProgress(100, 'Concluído!');
                addLog('Atualização concluída com sucesso!', 'success');
                
                // Recarregar página após 3 segundos
                setTimeout(() => {
                    window.location.reload();
                }, 3000);
            })
            .catch(error => {
                addLog('Erro: ' + error.message, 'error');
                updateProgress(0, 'Erro na atualização');
            });
    }
    
});
</script>

