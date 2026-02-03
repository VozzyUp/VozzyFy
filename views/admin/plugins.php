<?php
// Página de Gerenciamento de Plugins
require_once __DIR__ . '/../../helpers/plugin_loader.php';
?>
<div class="container mx-auto">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-3xl font-bold text-white">Gerenciar Plugins</h1>
            <p class="text-gray-400 mt-1">Instale e gerencie plugins extras da plataforma.</p>
        </div>
        <button id="btn-upload-plugin" class="bg-primary hover:bg-primary-hover text-white font-semibold py-2 px-4 rounded-lg transition-colors flex items-center gap-2">
            <i data-lucide="upload" class="w-5 h-5"></i>
            Enviar Plugin (ZIP)
        </button>
    </div>

    <!-- Plugins Disponíveis -->
    <div class="mb-8">
        <h2 class="text-2xl font-bold text-white mb-4">Plugins Disponíveis</h2>
        <div id="available-plugins-grid" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <div class="text-center text-gray-400 py-8">Carregando plugins disponíveis...</div>
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
                <div class="flex gap-3">
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

    <!-- Lista de Plugins Instalados -->
    <div id="plugins-instalados" class="mb-6">
        <h2 class="text-2xl font-bold text-white mb-4">Plugins Instalados</h2>
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

<script>
document.addEventListener('DOMContentLoaded', function() {
    const uploadModal = document.getElementById('upload-modal');
    const btnUpload = document.getElementById('btn-upload-plugin');
    const closeUpload = document.getElementById('close-upload-modal');
    const cancelUpload = document.getElementById('cancel-upload');
    const uploadForm = document.getElementById('upload-form');
    const pluginsList = document.getElementById('plugins-list');

    // Abrir/fechar modal
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
        
        const formData = new FormData(uploadForm);
        formData.append('action', 'upload_plugin');
        
        try {
            const response = await fetch('/api/plugins_api.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            });
            
            if (!response.ok) {
                const errorData = await response.json().catch(() => ({ error: 'Erro desconhecido' }));
                alert('Erro: ' + (errorData.error || 'Erro desconhecido'));
                return;
            }
            
            const data = await response.json();
            
            if (data.success) {
                alert(data.message);
                uploadModal.classList.add('hidden');
                uploadModal.classList.remove('flex');
                uploadForm.reset();
                loadPlugins();
                loadAvailablePlugins(); // Recarrega também os disponíveis para atualizar status
            } else {
                alert('Erro: ' + (data.error || 'Erro desconhecido'));
            }
        } catch (error) {
            alert('Erro ao enviar plugin: ' + error.message);
        }
    });

    // Carregar plugins disponíveis
    async function loadAvailablePlugins() {
        try {
            // Tenta primeiro via API, depois direto pelo JSON
            let response;
            try {
                response = await fetch('/api/plugins_available_api.php', {
                    credentials: 'same-origin'
                });
            } catch (e) {
                // Se falhar, tenta direto
                response = await fetch('/plugins/plugins_available.json', {
                    credentials: 'same-origin'
                });
            }
            
            if (!response.ok) {
                console.error('Erro ao carregar plugins disponíveis:', response.status);
                document.getElementById('available-plugins-grid').innerHTML = '<div class="col-span-full text-center text-gray-400 py-8">Erro ao carregar plugins disponíveis.</div>';
                return;
            }
            
            const availablePlugins = await response.json();
            
            // Se for um objeto com error, trata como erro
            if (availablePlugins.error) {
                throw new Error(availablePlugins.error);
            }
            
            const installedPlugins = await getInstalledPlugins();
            
            renderAvailablePlugins(availablePlugins, installedPlugins);
        } catch (error) {
            console.error('Erro ao carregar plugins disponíveis:', error);
            document.getElementById('available-plugins-grid').innerHTML = '<div class="col-span-full text-center text-gray-400 py-8">Erro ao carregar plugins disponíveis: ' + error.message + '</div>';
        }
    }
    
    // Obter lista de plugins instalados
    async function getInstalledPlugins() {
        try {
            const response = await fetch('/api/plugins_api.php?action=list_plugins', {
                credentials: 'same-origin',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });
            
            if (!response.ok) {
                return [];
            }
            
            const data = await response.json();
            return data.success ? data.plugins : [];
        } catch (error) {
            console.error('Erro ao obter plugins instalados:', error);
            return [];
        }
    }
    
    // Renderizar plugins disponíveis
    function renderAvailablePlugins(availablePlugins, installedPlugins) {
        const grid = document.getElementById('available-plugins-grid');
        
        if (availablePlugins.length === 0) {
            grid.innerHTML = '<div class="col-span-full text-center text-gray-400 py-8">Nenhum plugin disponível no momento.</div>';
            return;
        }
        
        const installedIds = installedPlugins.map(p => p.pasta);
        
        grid.innerHTML = availablePlugins.map(plugin => {
            const isInstalled = installedIds.includes(plugin.id);
            const installedPlugin = installedPlugins.find(p => p.pasta === plugin.id);
            
            return `
                <div class="bg-dark-card rounded-xl shadow-sm border border-dark-border overflow-hidden hover:border-primary transition-colors">
                    <div class="relative">
                        <img src="${plugin.thumbnail}" alt="${plugin.nome}" class="w-full h-48 object-cover">
                        ${isInstalled ? '<div class="absolute top-2 right-2 bg-green-500 text-white text-xs font-semibold px-2 py-1 rounded">Instalado</div>' : ''}
                    </div>
                    <div class="p-6">
                        <div class="flex items-start justify-between mb-2">
                            <h3 class="text-xl font-bold text-white">${plugin.nome}</h3>
                            <span class="text-sm text-gray-400">v${plugin.versao}</span>
                        </div>
                        <p class="text-gray-400 text-sm mb-4 line-clamp-2">${plugin.descricao}</p>
                        <div class="flex items-center justify-between mb-4">
                            <div>
                                <span class="text-primary font-bold text-lg">${plugin.preco}</span>
                            </div>
                            ${plugin.categoria ? `<span class="text-xs text-gray-500 bg-dark-elevated px-2 py-1 rounded">${plugin.categoria}</span>` : ''}
                        </div>
                        <div class="flex gap-2">
                            ${isInstalled ? `
                                <button onclick="window.location.href='#plugins-instalados'" class="flex-1 bg-gray-700 hover:bg-gray-600 text-white font-semibold py-2 px-4 rounded-lg transition-colors text-sm">
                                    Gerenciar
                                </button>
                            ` : `
                                <a href="${plugin.link_venda}" target="_blank" class="flex-1 bg-primary hover:bg-primary-hover text-white font-semibold py-2 px-4 rounded-lg transition-colors text-sm text-center flex items-center justify-center">
                                    <i data-lucide="external-link" class="w-4 h-4 mr-1"></i>
                                    Ver Detalhes
                                </a>
                            `}
                        </div>
                    </div>
                </div>
            `;
        }).join('');
        
        lucide.createIcons();
    }

    // Carregar plugins instalados
    async function loadPlugins() {
        try {
            // Tenta primeiro com o caminho completo, depois com caminho relativo
            let response;
            try {
                response = await fetch('/api/plugins_api.php?action=list_plugins', {
                    credentials: 'same-origin',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });
            } catch (e) {
                // Se falhar, tenta caminho relativo
                response = await fetch('api/plugins_api.php?action=list_plugins', {
                    credentials: 'same-origin',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });
            }
            
            if (!response.ok) {
                const errorText = await response.text();
                let errorData;
                try {
                    errorData = JSON.parse(errorText);
                } catch (e) {
                    errorData = { error: `Erro HTTP ${response.status}: ${errorText.substring(0, 100)}` };
                }
                console.error('Erro na resposta:', errorData, 'Status:', response.status);
                pluginsList.innerHTML = `<tr><td colspan="5" class="px-6 py-8 text-center text-red-400">Erro: ${errorData.error || 'Erro ao carregar plugins (Status: ' + response.status + ')'}</td></tr>`;
                return;
            }
            
            const data = await response.json();
            
            if (data.success) {
                renderPlugins(data.plugins);
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
            pluginsList.innerHTML = '<tr><td colspan="5" class="px-6 py-8 text-center text-gray-400">Nenhum plugin instalado</td></tr>';
            return;
        }

        pluginsList.innerHTML = plugins.map(plugin => {
            const statusBadge = plugin.ativo 
                ? '<span class="px-2 py-1 text-xs font-semibold rounded-full bg-green-900/30 text-green-400">Ativo</span>'
                : '<span class="px-2 py-1 text-xs font-semibold rounded-full bg-gray-900/30 text-gray-400">Inativo</span>';
            
            const fileStatus = plugin.arquivo_existe 
                ? '<span class="text-green-400 text-xs">✓ Arquivo OK</span>'
                : '<span class="text-red-400 text-xs">✗ Arquivo não encontrado</span>';
            
            const installDate = new Date(plugin.instalado_em).toLocaleDateString('pt-BR');
            
            return `
                <tr class="hover:bg-dark-elevated transition-colors">
                    <td class="px-6 py-4">
                        <div class="flex items-center gap-3">
                            <div class="w-10 h-10 rounded-lg bg-primary/20 flex items-center justify-center">
                                <i data-lucide="puzzle" class="w-5 h-5 text-primary"></i>
                            </div>
                            <div>
                                <div class="font-semibold text-white">${plugin.nome}</div>
                                <div class="text-xs text-gray-400">${plugin.pasta}</div>
                                ${fileStatus}
                            </div>
                        </div>
                    </td>
                    <td class="px-6 py-4 text-gray-300">${plugin.versao}</td>
                    <td class="px-6 py-4">${statusBadge}</td>
                    <td class="px-6 py-4 text-gray-400 text-sm">${installDate}</td>
                    <td class="px-6 py-4 text-right">
                        <div class="flex items-center justify-end gap-2">
                            <button onclick="togglePlugin(${plugin.id}, ${plugin.ativo ? 0 : 1})" 
                                    class="px-3 py-1 text-sm rounded-lg transition-colors ${plugin.ativo ? 'bg-yellow-900/30 text-yellow-400 hover:bg-yellow-900/50' : 'bg-primary/20 text-primary hover:bg-primary/30'}">
                                ${plugin.ativo ? 'Desativar' : 'Ativar'}
                            </button>
                            <button onclick="uninstallPlugin(${plugin.id})" 
                                    class="px-3 py-1 text-sm rounded-lg bg-red-900/30 text-red-400 hover:bg-red-900/50 transition-colors">
                                Desinstalar
                            </button>
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
        
        try {
            const formData = new FormData();
            formData.append('action', 'toggle_plugin');
            formData.append('id', id);
            formData.append('ativo', novoStatus);
            
            const response = await fetch('/api/plugins_api.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            });
            
            if (!response.ok) {
                const errorData = await response.json().catch(() => ({ error: 'Erro desconhecido' }));
                alert('Erro: ' + (errorData.error || 'Erro desconhecido'));
                return;
            }
            
            const data = await response.json();
            
            if (data.success) {
                alert('Status do plugin atualizado com sucesso!');
                loadPlugins();
                loadAvailablePlugins(); // Recarrega também os disponíveis para atualizar status
            } else {
                alert('Erro: ' + (data.error || 'Erro desconhecido'));
            }
        } catch (error) {
            alert('Erro ao atualizar plugin: ' + error.message);
        }
    };

    window.uninstallPlugin = async function(id) {
        if (!confirm('Tem certeza que deseja desinstalar este plugin? Esta ação não pode ser desfeita.')) {
            return;
        }
        
        try {
            const formData = new FormData();
            formData.append('action', 'uninstall_plugin');
            formData.append('id', id);
            
            const response = await fetch('/api/plugins_api.php', {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            });
            
            if (!response.ok) {
                const errorData = await response.json().catch(() => ({ error: 'Erro desconhecido' }));
                alert('Erro: ' + (errorData.error || 'Erro desconhecido'));
                return;
            }
            
            const data = await response.json();
            
            if (data.success) {
                alert('Plugin desinstalado com sucesso!');
                loadPlugins();
                loadAvailablePlugins(); // Recarrega também os disponíveis para atualizar status
            } else {
                alert('Erro: ' + (data.error || 'Erro desconhecido'));
            }
        } catch (error) {
            alert('Erro ao desinstalar plugin: ' + error.message);
        }
    };

    // Carregar plugins ao iniciar
    loadAvailablePlugins();
    loadPlugins();
    lucide.createIcons();
});
</script>

