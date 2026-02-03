<?php
// Página de Gerenciamento de Plugins com Loja
require_once __DIR__ . '/../../helpers/plugin_loader.php';
require_once __DIR__ . '/../../helpers/security_helper.php';

$csrf_token = generate_csrf_token();
?>
<div class="container mx-auto">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-3xl font-bold text-white">Plugins</h1>
            <p class="text-gray-400 mt-1">Instale e gerencie plugins extras da plataforma.</p>
        </div>
        <button id="btn-upload-plugin" class="bg-primary hover:bg-primary-hover text-white font-semibold py-2 px-4 rounded-lg transition-colors flex items-center gap-2">
            <i data-lucide="upload" class="w-5 h-5"></i>
            Enviar Plugin (ZIP)
        </button>
    </div>

    <!-- Abas -->
    <div class="mb-6 border-b border-dark-border">
        <nav class="flex space-x-1" aria-label="Tabs">
            <button id="tab-installed" class="tab-button active px-4 py-2 text-sm font-medium rounded-t-lg transition-colors text-white bg-dark-elevated border-b-2 border-primary" data-tab="installed">
                <i data-lucide="package" class="w-4 h-4 inline mr-2"></i>
                Plugins Instalados
            </button>
            <button id="tab-marketplace" class="tab-button px-4 py-2 text-sm font-medium rounded-t-lg transition-colors text-gray-400 hover:text-white" data-tab="marketplace">
                <i data-lucide="store" class="w-4 h-4 inline mr-2"></i>
                Loja de Plugins
            </button>
        </nav>
    </div>

    <!-- Conteúdo da Aba: Plugins Instalados -->
    <div id="content-installed" class="tab-content">
        <!-- Filtros e Busca -->
        <div class="mb-4 flex flex-col md:flex-row gap-4">
            <div class="flex-1">
                <input type="text" id="search-plugins" placeholder="Buscar plugins..." class="w-full bg-dark-elevated border border-dark-border text-white rounded-lg px-4 py-2 focus:outline-none focus:border-primary">
            </div>
            <select id="filter-status" class="bg-dark-elevated border border-dark-border text-white rounded-lg px-4 py-2 focus:outline-none focus:border-primary">
                <option value="all">Todos</option>
                <option value="active">Ativos</option>
                <option value="inactive">Inativos</option>
            </select>
        </div>

        <!-- Lista de Plugins Instalados -->
        <div class="bg-dark-card rounded-xl shadow-sm border border-dark-border overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-dark-elevated">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Plugin</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Versão</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Status</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Instalado em</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-gray-400 uppercase tracking-wider">Ações</th>
                        </tr>
                    </thead>
                    <tbody id="plugins-list" class="divide-y divide-dark-border">
                        <tr>
                            <td colspan="5" class="px-6 py-8 text-center text-gray-400">Carregando plugins...</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Conteúdo da Aba: Loja de Plugins -->
    <div id="content-marketplace" class="tab-content hidden">
        <!-- Filtros e Busca -->
        <div class="mb-4 flex flex-col md:flex-row gap-4">
            <div class="flex-1">
                <input type="text" id="search-marketplace" placeholder="Buscar na loja..." class="w-full bg-dark-elevated border border-dark-border text-white rounded-lg px-4 py-2 focus:outline-none focus:border-primary">
            </div>
            <select id="filter-category" class="bg-dark-elevated border border-dark-border text-white rounded-lg px-4 py-2 focus:outline-none focus:border-primary">
                <option value="all">Todas categorias</option>
                <option value="gateway">Gateway</option>
                <option value="integração">Integração</option>
                <option value="marketing">Marketing</option>
                <option value="utilitário">Utilitário</option>
            </select>
        </div>

        <!-- Card de Envio de Plugin -->
        <div class="mb-6">
            <a href="https://docs.google.com/forms/d/e/1FAIpQLScm6JH7T-zHDG0ztsafrbOlZXcc-H5gcy3VKvIw9J-mZdLRpg/viewform?usp=publish-editor" target="_blank" class="block bg-gradient-to-r from-primary/20 to-primary/10 rounded-xl shadow-sm border-2 border-primary/50 hover:border-primary transition-all hover:shadow-lg">
                <div class="p-6 flex items-center justify-between">
                    <div class="flex items-center gap-4">
                        <div class="w-16 h-16 rounded-lg bg-primary/30 flex items-center justify-center flex-shrink-0">
                            <i data-lucide="upload-cloud" class="w-8 h-8 text-primary"></i>
                        </div>
                        <div>
                            <h3 class="text-xl font-bold text-white mb-1">Envie seu próprio plugin para a loja</h3>
                            <p class="text-gray-400 text-sm">Compartilhe seu plugin com a comunidade. Preencha o formulário e seu plugin pode aparecer na loja!</p>
                        </div>
                    </div>
                    <div class="flex-shrink-0">
                        <button class="bg-primary hover:bg-primary-hover text-white font-semibold py-3 px-6 rounded-lg transition-colors flex items-center gap-2">
                            <i data-lucide="external-link" class="w-5 h-5"></i>
                            Enviar Plugin
                        </button>
                    </div>
                </div>
            </a>
        </div>

        <!-- Grid de Plugins da Loja -->
        <div id="marketplace-grid" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <div class="col-span-full text-center text-gray-400 py-8">Carregando plugins da loja...</div>
        </div>
    </div>
</div>

<!-- Modal de Upload -->
<div id="upload-modal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden items-center justify-center">
    <div class="bg-dark-card p-6 rounded-xl shadow-xl max-w-md w-full mx-4 border border-dark-border">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-xl font-bold text-white">Enviar Plugin</h2>
            <button id="close-upload-modal" class="text-gray-400 hover:text-white">
                <i data-lucide="x" class="w-5 h-5"></i>
            </button>
        </div>
        <form id="upload-form" enctype="multipart/form-data" class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-gray-300 mb-2">Arquivo ZIP do Plugin</label>
                <input type="file" name="plugin_zip" id="plugin_zip" accept=".zip" required class="w-full text-sm text-gray-400 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-primary file:text-white hover:file:bg-primary-hover">
                <p class="text-xs text-gray-500 mt-2">O ZIP deve conter uma pasta com o nome do plugin e o arquivo principal {nome}.php</p>
            </div>
            
            <!-- Indicador de Progresso -->
            <div id="upload-progress" class="hidden">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-sm font-medium text-gray-300">Instalando plugin...</span>
                    <span id="upload-progress-text" class="text-sm text-gray-400">0%</span>
                </div>
                <div class="w-full bg-dark-elevated rounded-full h-2.5 border border-dark-border overflow-hidden">
                    <div id="upload-progress-bar" class="bg-primary h-2.5 transition-all duration-300" style="width: 0%"></div>
                </div>
                <p id="upload-status-text" class="text-xs text-gray-400 mt-2">Enviando arquivo...</p>
            </div>
            
            <div id="upload-buttons" class="flex gap-3">
                <button type="submit" class="flex-1 bg-primary hover:bg-primary-hover text-white font-semibold py-2 px-4 rounded-lg transition-colors">
                    Enviar
                </button>
                <button type="button" id="cancel-upload" class="flex-1 bg-dark-elevated hover:bg-dark-border text-white font-semibold py-2 px-4 rounded-lg transition-colors">
                    Cancelar
                </button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Debug: Verificar se CSRF token está disponível
    const csrfTokenCheck = window.csrfToken || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    if (!csrfTokenCheck) {
        console.error('AVISO: Token CSRF não encontrado! window.csrfToken:', window.csrfToken, '| meta tag:', document.querySelector('meta[name="csrf-token"]')?.getAttribute('content'));
    } else {
        console.log('CSRF Token encontrado:', csrfTokenCheck.substring(0, 10) + '...');
    }
    
    const uploadModal = document.getElementById('upload-modal');
    const btnUpload = document.getElementById('btn-upload-plugin');
    const closeUpload = document.getElementById('close-upload-modal');
    const cancelUpload = document.getElementById('cancel-upload');
    const uploadForm = document.getElementById('upload-form');
    const pluginsList = document.getElementById('plugins-list');
    const marketplaceGrid = document.getElementById('marketplace-grid');
    
    // Sistema de Abas
    const tabs = document.querySelectorAll('.tab-button');
    const tabContents = document.querySelectorAll('.tab-content');
    
    tabs.forEach(tab => {
        tab.addEventListener('click', () => {
            const targetTab = tab.dataset.tab;
            
            // Atualiza abas
            tabs.forEach(t => {
                t.classList.remove('active', 'text-white', 'bg-dark-elevated', 'border-primary');
                t.classList.add('text-gray-400');
            });
            tab.classList.add('active', 'text-white', 'bg-dark-elevated', 'border-b-2', 'border-primary');
            tab.classList.remove('text-gray-400');
            
            // Mostra conteúdo correspondente
            tabContents.forEach(content => {
                content.classList.add('hidden');
            });
            document.getElementById(`content-${targetTab}`).classList.remove('hidden');
            
            // Recarrega conteúdo se necessário
            if (targetTab === 'marketplace') {
                loadMarketplace();
            }
        });
    });

    // Abrir/fechar modal de upload
    btnUpload.addEventListener('click', () => {
        uploadModal.classList.remove('hidden');
        uploadModal.classList.add('flex');
    });

    [closeUpload, cancelUpload].forEach(btn => {
        btn.addEventListener('click', () => {
            uploadModal.classList.add('hidden');
            uploadModal.classList.remove('flex');
            uploadForm.reset();
        });
    });


    // Upload de plugin
    uploadForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        
        // Verificar se CSRF token está disponível
        const csrfToken = window.csrfToken || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        if (!csrfToken) {
            alert('Erro: Token CSRF não encontrado. Por favor, recarregue a página.');
            console.error('CSRF Token não encontrado!');
            return;
        }
        
        const formData = new FormData(uploadForm);
        formData.append('action', 'upload_plugin');
        formData.append('csrf_token', csrfToken);
        
        // Elementos de UI
        const submitBtn = uploadForm.querySelector('button[type="submit"]');
        const uploadButtons = document.getElementById('upload-buttons');
        const uploadProgress = document.getElementById('upload-progress');
        const uploadProgressBar = document.getElementById('upload-progress-bar');
        const uploadProgressText = document.getElementById('upload-progress-text');
        const uploadStatusText = document.getElementById('upload-status-text');
        const originalText = submitBtn.textContent;
        
        // Esconder botões e mostrar progresso
        uploadButtons.classList.add('hidden');
        uploadProgress.classList.remove('hidden');
        submitBtn.disabled = true;
        
        // Função para atualizar progresso
        function updateProgress(percent, status) {
            uploadProgressBar.style.width = percent + '%';
            uploadProgressText.textContent = Math.round(percent) + '%';
            uploadStatusText.textContent = status;
        }
        
        try {
            updateProgress(10, 'Enviando arquivo...');
            
            // Criar XMLHttpRequest para tracking de upload
            const xhr = new XMLHttpRequest();
            
            // Promise para o upload
            const uploadPromise = new Promise((resolve, reject) => {
                xhr.upload.addEventListener('progress', (e) => {
                    if (e.lengthComputable) {
                        // Upload do arquivo: 10% a 50%
                        const uploadPercent = 10 + (e.loaded / e.total) * 40;
                        updateProgress(uploadPercent, 'Enviando arquivo... (' + Math.round((e.loaded / e.total) * 100) + '%)');
                    }
                });
                
                xhr.addEventListener('load', () => {
                    if (xhr.status >= 200 && xhr.status < 300) {
                        updateProgress(60, 'Arquivo recebido, extraindo...');
                        resolve(xhr.responseText);
                    } else {
                        reject(new Error('Erro HTTP: ' + xhr.status));
                    }
                });
                
                xhr.addEventListener('error', () => {
                    reject(new Error('Erro de conexão'));
                });
                
                xhr.addEventListener('abort', () => {
                    reject(new Error('Upload cancelado'));
                });
                
                xhr.open('POST', '/api/plugins_api.php');
                xhr.send(formData);
            });
            
            // Aguardar resposta
            const responseText = await uploadPromise;
            updateProgress(70, 'Validando estrutura...');
            
            // Fazer parse da resposta
            let data;
            try {
                data = JSON.parse(responseText);
            } catch (parseError) {
                console.error('Erro ao fazer parse do JSON:', responseText);
                throw new Error('Resposta inválida do servidor');
            }
            
            updateProgress(90, 'Finalizando instalação...');
            
            if (data.success) {
                updateProgress(100, 'Plugin extraído com sucesso!');
                
                // Aguardar um pouco para garantir que o arquivo foi extraído
                await new Promise(resolve => setTimeout(resolve, 1000));
                
                // Forçar list_plugins que tem auto-instalação - isso detecta e registra plugins na pasta
                updateProgress(100, 'Detectando plugin...');
                try {
                    const listResponse = await fetch('/api/plugins_api.php?action=list_plugins', {
                        credentials: 'same-origin',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    });
                    if (listResponse.ok) {
                        const listData = await listResponse.json();
                        console.log('Lista de plugins atualizada:', listData);
                        if (listData.success && listData.plugins) {
                            updateProgress(100, 'Plugin detectado e registrado!');
                        }
                    }
                } catch (err) {
                    console.error('Erro ao atualizar lista de plugins:', err);
                }
                
                // Aguardar mais um pouco para garantir que tudo foi processado
                await new Promise(resolve => setTimeout(resolve, 1500));
                
                // Fechar modal
                uploadModal.classList.add('hidden');
                uploadModal.classList.remove('flex');
                uploadForm.reset();
                
                // Recarregar a página para garantir que tudo está atualizado
                // Usando reload(true) para forçar recarregar do servidor (sem cache)
                window.location.reload(true);
            } else {
                throw new Error(data.error || 'Erro desconhecido');
            }
        } catch (error) {
            // Mostrar erro
            uploadProgress.classList.add('hidden');
            uploadButtons.classList.remove('hidden');
            submitBtn.disabled = false;
            submitBtn.textContent = originalText;
            
            alert('Erro ao enviar plugin: ' + error.message);
            console.error('Erro no upload:', error);
        }
    });


    // Carregar marketplace
    async function loadMarketplace() {
        try {
            const response = await fetch('/api/plugins_marketplace_api.php?action=list_available', {
                credentials: 'same-origin'
            });
            
            if (!response.ok) {
                throw new Error('Erro ao carregar marketplace');
            }
            
            const data = await response.json();
            
            if (data.success) {
                renderMarketplace(data.plugins || []);
            } else {
                marketplaceGrid.innerHTML = '<div class="col-span-full text-center text-red-400 py-8">Erro ao carregar marketplace: ' + (data.error || 'Erro desconhecido') + '</div>';
            }
        } catch (error) {
            console.error('Erro ao carregar marketplace:', error);
            marketplaceGrid.innerHTML = '<div class="col-span-full text-center text-red-400 py-8">Erro ao carregar marketplace: ' + error.message + '</div>';
        }
    }
    
    // Renderizar marketplace
    function renderMarketplace(plugins) {
        if (plugins.length === 0) {
            marketplaceGrid.innerHTML = '<div class="col-span-full text-center text-gray-400 py-8">Nenhum plugin disponível no momento.</div>';
            return;
        }
        
        marketplaceGrid.innerHTML = plugins.map(plugin => {
            const isInstalled = plugin.instalado || false;
            const hasDownloadUrl = plugin.download_url && plugin.download_url.trim() !== '';
            const hasExternalUrl = plugin.external_url && plugin.external_url.trim() !== '';
            const isPaid = plugin.preco && plugin.preco > 0;
            const categoria = plugin.categoria || '';
            
            return `
                <div class="bg-dark-card rounded-xl shadow-sm border border-dark-border overflow-hidden hover:border-primary transition-colors">
                    <div class="p-6">
                        <div class="flex items-start justify-between mb-3">
                            <div class="w-12 h-12 rounded-lg bg-primary/20 flex items-center justify-center flex-shrink-0">
                                <i data-lucide="puzzle" class="w-6 h-6 text-primary"></i>
                            </div>
                            <div class="flex flex-col items-end gap-1">
                                ${isInstalled ? '<span class="px-2 py-1 text-xs font-semibold rounded-full bg-green-900/30 text-green-400">Instalado</span>' : ''}
                                <span class="text-xs text-gray-400">v${plugin.versao || '1.0.0'}</span>
                            </div>
                        </div>
                        <h3 class="text-xl font-bold text-white mb-2">${escapeHtml(plugin.nome || 'Plugin')}</h3>
                        <p class="text-gray-400 text-sm mb-4 line-clamp-3 h-16">${escapeHtml(plugin.descricao || 'Sem descrição')}</p>
                        <div class="flex items-center justify-between mb-4">
                            <div>
                                ${plugin.autor ? `<span class="text-xs text-gray-500">por ${escapeHtml(plugin.autor)}</span>` : ''}
                            </div>
                            ${categoria ? `<span class="text-xs text-gray-500 bg-dark-elevated px-2 py-1 rounded">${escapeHtml(categoria)}</span>` : ''}
                        </div>
                        ${isPaid ? `<div class="mb-4"><span class="text-primary font-bold text-lg">R$ ${parseFloat(plugin.preco).toFixed(2).replace('.', ',')}</span></div>` : ''}
                        <div class="flex gap-2">
                            ${isInstalled ? `
                                <button onclick="window.location.href='#content-installed'; document.getElementById('tab-installed').click();" class="flex-1 bg-gray-700 hover:bg-gray-600 text-white font-semibold py-2 px-4 rounded-lg transition-colors text-sm">
                                    Gerenciar
                                </button>
                            ` : (hasDownloadUrl ? `
                                <button onclick="installFromMarketplace('${escapeHtml(plugin.slug || '')}')" class="flex-1 bg-primary hover:bg-primary-hover text-white font-semibold py-2 px-4 rounded-lg transition-colors text-sm">
                                    Instalar
                                </button>
                            ` : (hasExternalUrl ? `
                                <a href="${escapeHtml(plugin.external_url)}" target="_blank" class="flex-1 bg-primary hover:bg-primary-hover text-white font-semibold py-2 px-4 rounded-lg transition-colors text-sm text-center flex items-center justify-center">
                                    <i data-lucide="download" class="w-4 h-4 mr-1"></i>
                                    Baixar
                                </a>
                            ` : ''))}
                        </div>
                    </div>
                </div>
            `;
        }).join('');
        
        lucide.createIcons();
    }

    // Instalar do marketplace
    window.installFromMarketplace = async function(slug) {
        if (!confirm('Deseja instalar este plugin?')) {
            return;
        }
        
        const formData = new FormData();
        formData.append('action', 'install_from_marketplace');
        formData.append('slug', slug);
        formData.append('csrf_token', '<?php echo htmlspecialchars($csrf_token); ?>');
        
        try {
            const response = await fetch('/api/plugins_marketplace_api.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            });
            
            const data = await response.json();
            
            if (data.success) {
                alert(data.message || 'Plugin instalado com sucesso!');
                loadPlugins();
                loadMarketplace();
                // Muda para aba de instalados
                document.getElementById('tab-installed').click();
            } else {
                alert('Erro: ' + (data.error || 'Erro desconhecido'));
            }
        } catch (error) {
            alert('Erro ao instalar plugin: ' + error.message);
        }
    };

    // Função para escapar HTML
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Busca e filtros
    const searchPlugins = document.getElementById('search-plugins');
    const filterStatus = document.getElementById('filter-status');
    let allPlugins = [];
    
    searchPlugins?.addEventListener('input', () => {
        filterAndRenderPlugins();
    });
    
    filterStatus?.addEventListener('change', () => {
        filterAndRenderPlugins();
    });
    
    function filterAndRenderPlugins() {
        const search = (searchPlugins?.value || '').toLowerCase();
        const status = filterStatus?.value || 'all';
        
        let filtered = allPlugins.filter(plugin => {
            const matchSearch = !search || 
                (plugin.nome || '').toLowerCase().includes(search) ||
                (plugin.pasta || '').toLowerCase().includes(search);
            
            const matchStatus = status === 'all' || 
                (status === 'active' && plugin.ativo) ||
                (status === 'inactive' && !plugin.ativo);
            
            return matchSearch && matchStatus;
        });
        
        renderPlugins(filtered);
    }

    // Carregar plugins instalados
    async function loadPlugins() {
        try {
            const response = await fetch('/api/plugins_api.php?action=list_plugins', {
                credentials: 'same-origin',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
            
            if (!response.ok) {
                throw new Error('Erro HTTP ' + response.status);
            }
            
            const data = await response.json();
            
            if (data.success) {
                allPlugins = data.plugins || [];
                filterAndRenderPlugins();
            } else {
                pluginsList.innerHTML = `<tr><td colspan="5" class="px-6 py-8 text-center text-red-400">${data.error || 'Erro ao carregar plugins'}</td></tr>`;
            }
        } catch (error) {
            console.error('Erro ao carregar plugins:', error);
            pluginsList.innerHTML = '<tr><td colspan="5" class="px-6 py-8 text-center text-red-400">Erro ao carregar plugins: ' + error.message + '</td></tr>';
        }
    }

    function renderPlugins(plugins) {
        if (plugins.length === 0) {
            pluginsList.innerHTML = '<tr><td colspan="5" class="px-6 py-8 text-center text-gray-400">Nenhum plugin encontrado</td></tr>';
            return;
        }

        pluginsList.innerHTML = plugins.map(plugin => {
            const statusBadge = plugin.ativo 
                ? '<span class="px-2 py-1 text-xs font-semibold rounded-full bg-green-900/30 text-green-400">Ativo</span>'
                : '<span class="px-2 py-1 text-xs font-semibold rounded-full bg-gray-900/30 text-gray-400">Inativo</span>';
            
            const fileStatus = plugin.arquivo_existe 
                ? '<span class="text-green-400 text-xs">✓ Arquivo OK</span>'
                : '<span class="text-red-400 text-xs">✗ Arquivo não encontrado</span>';
            
            const installDate = new Date(plugin.instalado_em || Date.now()).toLocaleDateString('pt-BR');
            const isOrphan = !plugin.arquivo_existe;
            
            return `
                <tr class="hover:bg-dark-elevated transition-colors ${isOrphan ? 'bg-red-900/5' : ''}">
                    <td class="px-6 py-4">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-lg bg-primary/20 flex items-center justify-center">
                                <i data-lucide="puzzle" class="w-5 h-5 text-primary"></i>
                            </div>
                            <div>
                                <div class="font-semibold text-white">${escapeHtml(plugin.nome || 'Plugin')}</div>
                                <div class="text-xs text-gray-400">${escapeHtml(plugin.pasta || '')}</div>
                                ${fileStatus}
                            </div>
                        </div>
                    </td>
                    <td class="px-6 py-4 text-gray-300">${escapeHtml(plugin.versao || '1.0.0')}</td>
                    <td class="px-6 py-4">${statusBadge}</td>
                    <td class="px-6 py-4 text-gray-400 text-sm">${installDate}</td>
                    <td class="px-6 py-4 text-right">
                        <div class="flex items-center justify-end gap-2">
                            ${isOrphan ? `
                                <button onclick="removeOrphanPlugin(${plugin.id})" 
                                        class="px-3 py-1 text-sm rounded-lg bg-red-600 hover:bg-red-700 text-white transition-colors" title="Remover do banco de dados">
                                    Remover
                                </button>
                            ` : `
                                <button onclick="togglePlugin(${plugin.id}, ${plugin.ativo ? 0 : 1})" 
                                        class="px-3 py-1 text-sm rounded-lg transition-colors ${plugin.ativo ? 'bg-yellow-900/30 text-yellow-400 hover:bg-yellow-900/50' : 'bg-primary/20 text-primary hover:bg-primary/30'}">
                                    ${plugin.ativo ? 'Desativar' : 'Ativar'}
                                </button>
                                <button onclick="uninstallPlugin(${plugin.id})" 
                                        class="px-3 py-1 text-sm rounded-lg bg-red-900/30 text-red-400 hover:bg-red-900/50 transition-colors">
                                    Desinstalar
                                </button>
                            `}
                        </div>
                    </td>
                </tr>
            `;
        }).join('');
        
        lucide.createIcons();
    }

    // Funções globais para ações
    window.togglePlugin = async function(id, novoStatus) {
        if (!confirm(`Tem certeza que deseja ${novoStatus ? 'ativar' : 'desativar'} este plugin?`)) {
            return;
        }
        
        // Verificar se CSRF token está disponível
        const csrfToken = window.csrfToken || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        if (!csrfToken) {
            alert('Erro: Token CSRF não encontrado. Por favor, recarregue a página.');
            console.error('CSRF Token não encontrado!');
            return;
        }
        
        try {
            const formData = new FormData();
            formData.append('action', 'toggle_plugin');
            formData.append('id', id);
            formData.append('ativo', novoStatus);
            formData.append('csrf_token', csrfToken);
            
            console.log('Toggle Plugin - Enviando:', { id, novoStatus, csrfToken: csrfToken.substring(0, 10) + '...' });
            
            const response = await fetch('/api/plugins_api.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            });
            
            console.log('Toggle Plugin - Resposta recebida:', { status: response.status, statusText: response.statusText });
            
            // Ler resposta como texto primeiro
            const responseText = await response.text();
            console.log('Toggle Plugin - Resposta bruta:', responseText.substring(0, 500));
            
            // Tentar fazer parse do JSON
            let data;
            try {
                data = JSON.parse(responseText);
                console.log('Toggle Plugin - Resposta parseada:', data);
            } catch (parseError) {
                console.error('Erro ao fazer parse do JSON:', parseError);
                console.error('Resposta completa:', responseText);
                alert('Erro: Resposta inválida do servidor. Status: ' + response.status + '. Verifique o console para detalhes.');
                return;
            }
            
            if (!response.ok) {
                // Erro HTTP (400, 403, 500, etc)
                const errorMsg = data.error || data.message || 'Erro ao atualizar plugin. Status: ' + response.status;
                console.error('Erro HTTP:', response.status, data);
                alert('Erro: ' + errorMsg);
                return;
            }
            
            if (data.success) {
                alert('Status do plugin atualizado com sucesso!');
                loadPlugins();
                loadMarketplace();
            } else {
                alert('Erro: ' + (data.error || data.message || 'Erro desconhecido'));
            }
        } catch (error) {
            console.error('Erro ao atualizar plugin:', error);
            alert('Erro ao atualizar plugin: ' + error.message);
        }
    };

    window.uninstallPlugin = async function(id) {
        if (!confirm('Tem certeza que deseja desinstalar este plugin? Esta ação não pode ser desfeita.')) {
            return;
        }
        
        // Verificar se CSRF token está disponível
        const csrfToken = window.csrfToken || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        if (!csrfToken) {
            alert('Erro: Token CSRF não encontrado. Por favor, recarregue a página.');
            console.error('CSRF Token não encontrado! Verifique se window.csrfToken está definido.');
            return;
        }
        
        try {
            const formData = new FormData();
            formData.append('action', 'uninstall_plugin');
            formData.append('id', id);
            formData.append('csrf_token', csrfToken);
            
            const response = await fetch('/api/plugins_api.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            });
            
            // Ler resposta como texto primeiro (para verificar se é JSON válido)
            const responseText = await response.text();
            
            // Tentar fazer parse do JSON
            let data;
            try {
                data = JSON.parse(responseText);
            } catch (parseError) {
                console.error('Erro ao fazer parse do JSON:', parseError);
                console.error('Resposta do servidor:', responseText);
                alert('Erro: Resposta inválida do servidor. Status: ' + response.status + '. Verifique o console para detalhes.');
                return;
            }
            
            if (!response.ok) {
                // Erro HTTP (400, 403, 500, etc)
                const errorMsg = data.error || data.message || 'Erro ao desinstalar plugin. Status: ' + response.status;
                console.error('Erro HTTP:', response.status, data);
                alert('Erro: ' + errorMsg);
                return;
            }
            
            if (data.success) {
                alert('Plugin desinstalado com sucesso!');
                loadPlugins();
                loadMarketplace();
            } else {
                alert('Erro: ' + (data.error || data.message || 'Erro desconhecido'));
            }
        } catch (error) {
            console.error('Erro ao desinstalar plugin:', error);
            alert('Erro ao desinstalar plugin: ' + error.message);
        }
    };

    // Remover plugin órfão (sem arquivo) do banco
    window.removeOrphanPlugin = async function(id) {
        if (!confirm('Este plugin não tem arquivo físico. Deseja remover o registro do banco de dados?')) {
            return;
        }
        
        // Verificar se CSRF token está disponível
        const csrfToken = window.csrfToken || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        if (!csrfToken) {
            alert('Erro: Token CSRF não encontrado. Por favor, recarregue a página.');
            console.error('CSRF Token não encontrado!');
            return;
        }
        
        try {
            const formData = new FormData();
            formData.append('action', 'remove_orphan_plugin');
            formData.append('id', id);
            formData.append('csrf_token', csrfToken);
            
            const response = await fetch('/api/plugins_api.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            });
            
            // Verificar se a resposta é JSON válido
            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                const text = await response.text();
                console.error('Resposta não é JSON:', text);
                alert('Erro ao processar resposta do servidor. Status: ' + response.status);
                return;
            }
            
            const data = await response.json();
            
            if (!response.ok) {
                // Erro HTTP (400, 403, 500, etc)
                const errorMsg = data.error || data.message || 'Erro ao remover plugin. Status: ' + response.status;
                console.error('Erro HTTP:', response.status, data);
                alert('Erro: ' + errorMsg);
                return;
            }
            
            if (data.success) {
                alert('Plugin removido do banco de dados!');
                loadPlugins();
                loadMarketplace();
            } else {
                alert('Erro: ' + (data.error || data.message || 'Erro desconhecido'));
            }
        } catch (error) {
            console.error('Erro ao remover plugin:', error);
            alert('Erro ao remover plugin: ' + error.message);
        }
    };

    // Carregar dados ao iniciar
    loadPlugins();
    lucide.createIcons();
});
</script>

<style>
.tab-button {
    border-bottom: 2px solid transparent;
}

.tab-button.active {
    border-bottom-color: var(--accent-primary);
}
</style>

