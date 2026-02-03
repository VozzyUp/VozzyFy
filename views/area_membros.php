<?php
// Este arquivo é incluído a partir do index.php,
// então a verificação de login e a conexão com o banco ($pdo) já existem.

// Obter o ID do usuário logado
$usuario_id_logado = $_SESSION['id'] ?? 0;

// Se por algum motivo o ID do usuário não estiver definido, redireciona para o login
if ($usuario_id_logado === 0) {
    header("location: /login");
    exit;
}

// Busca todos os produtos que são do tipo 'Área de Membros' E que pertencem ao usuário logado
try {
    $stmt = $pdo->prepare("
        SELECT id, nome, foto 
        FROM produtos 
        WHERE tipo_entrega = 'area_membros'
        AND usuario_id = ? 
        ORDER BY data_criacao DESC
    ");
    $stmt->execute([$usuario_id_logado]);
    $cursos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Em um cenário real, seria bom logar este erro.
    echo "<div class='bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded relative mb-4' role='alert'>Erro ao buscar cursos: " . htmlspecialchars($e->getMessage()) . "</div>";
    $cursos = []; // Garante que a variável exista para evitar erros no HTML
}

$upload_dir = 'uploads/'; // Pasta onde as imagens estão salvas

// Gerar token CSRF para uso em requisições JavaScript
require_once __DIR__ . '/../helpers/security_helper.php';
$csrf_token_js = generate_csrf_token();
?>

<meta name="csrf-token" content="<?php echo htmlspecialchars($csrf_token_js); ?>">
<script>
    // Variável global para token CSRF
    window.csrfToken = '<?php echo htmlspecialchars($csrf_token_js); ?>';
</script>

<div class="container mx-auto">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-3xl font-bold text-white">Área de Membros</h1>
    </div>

    <!-- Sistema de Abas -->
    <div class="bg-dark-card rounded-lg shadow-md mb-6" style="border-color: var(--accent-primary);">
        <div class="flex border-b border-dark-border">
            <button id="tab-gerenciar" class="tab-button active px-6 py-4 font-semibold text-white border-b-2" style="border-color: var(--accent-primary);">
                Gerenciar Cursos
            </button>
            <button id="tab-consentimentos" class="tab-button px-6 py-4 font-semibold text-gray-400 hover:text-white border-b-2 border-transparent">
                Downloads e Consentimentos
            </button>
        </div>
    </div>

    <!-- Aba: Gerenciar Cursos -->
    <div id="content-gerenciar" class="tab-content">
        <div class="bg-dark-card p-8 rounded-lg shadow-md" style="border-color: var(--accent-primary);">
            <h2 class="text-2xl font-semibold mb-6 text-white">Gerenciar Cursos</h2>
        
        <?php if (empty($cursos)): ?>
            <div class="text-center py-12 text-gray-400">
                <i data-lucide="video-off" class="mx-auto w-16 h-16 text-gray-500"></i>
                <p class="mt-4">Nenhum produto foi configurado para entrega via Área de Membros.</p>
                <p>Vá para a <a href="/index?pagina=produtos" style="color: var(--accent-primary);" onmouseover="this.style.color='var(--accent-primary-hover)'" onmouseout="this.style.color='var(--accent-primary)'" class="hover:underline font-semibold">página de produtos</a> para configurar um.</p>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-8">
                <?php foreach ($cursos as $curso): ?>
                    <div class="group bg-dark-elevated rounded-lg overflow-hidden border border-dark-border hover:shadow-xl transition-shadow duration-300 flex flex-col">
                        <div class="relative h-64 bg-dark-card">
                             <?php if ($curso['foto']): ?>
                                <img src="<?php echo $upload_dir . htmlspecialchars($curso['foto']); ?>" alt="<?php echo htmlspecialchars($curso['nome']); ?>" class="w-full h-full object-cover">
                            <?php else: ?>
                                <div class="w-full h-full flex items-center justify-center">
                                    <i data-lucide="image-off" class="text-gray-500 w-16 h-16"></i>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="p-4 flex-grow flex flex-col justify-between">
                            <div>
                                <h3 class="font-bold text-lg text-white mb-4 truncate" title="<?php echo htmlspecialchars($curso['nome']); ?>">
                                    <?php echo htmlspecialchars($curso['nome']); ?>
                                </h3>
                            </div>
                            <div class="mt-2 flex flex-col gap-2"> <!-- Alterado para flex-col e gap-2 para empilhar botões -->
                                <a href="/curso_preview?produto_id=<?php echo $curso['id']; ?>" target="_blank" class="flex-1 text-center bg-dark-card text-gray-300 font-bold py-2 px-3 rounded-lg hover:bg-dark-elevated hover:text-white transition duration-300 flex items-center justify-center space-x-2 text-sm border border-dark-border">
                                    <i data-lucide="eye" class="w-4 h-4"></i>
                                    <span>Pré-visualizar</span>
                                </a>
                                <a href="/index?pagina=gerenciar_curso&produto_id=<?php echo $curso['id']; ?>" class="flex-1 text-center text-white font-bold py-2 px-3 rounded-lg transition duration-300 flex items-center justify-center space-x-2 text-sm" style="background-color: var(--accent-primary);" onmouseover="this.style.backgroundColor='var(--accent-primary-hover)'" onmouseout="this.style.backgroundColor='var(--accent-primary)'">
                                    <i data-lucide="edit" class="w-4 h-4"></i>
                                    <span>Gerenciar</span>
                                </a>
                                <!-- NOVO BOTÃO 'OFERTAS' -->
                                <a href="/index?pagina=infoprodutor_member_offers&source_product_id=<?php echo $curso['id']; ?>" class="flex-1 text-center bg-purple-500 text-white font-bold py-2 px-3 rounded-lg hover:bg-purple-600 transition duration-300 flex items-center justify-center space-x-2 text-sm">
                                    <i data-lucide="tag" class="w-4 h-4"></i>
                                    <span>Ofertas</span>
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        </div>
    </div>

    <!-- Aba: Downloads e Consentimentos -->
    <div id="content-consentimentos" class="tab-content hidden">
        <div class="bg-dark-card p-8 rounded-lg shadow-md" style="border-color: var(--accent-primary);">
            <h2 class="text-2xl font-semibold mb-6 text-white">Downloads e Consentimentos</h2>
            
            <!-- Filtros -->
            <div class="mb-6 flex flex-col md:flex-row gap-4">
                <input type="text" id="filter-search" placeholder="Buscar por nome, email ou curso..." class="flex-1 px-4 py-2 bg-dark-elevated border border-dark-border rounded-lg text-white focus:outline-none focus:ring-2" style="--tw-ring-color: var(--accent-primary);">
                <button id="btn-filter" class="px-6 py-2 text-white rounded-lg transition" style="background-color: var(--accent-primary);" onmouseover="this.style.backgroundColor='var(--accent-primary-hover)'" onmouseout="this.style.backgroundColor='var(--accent-primary)'">
                    <i data-lucide="search" class="w-5 h-5 inline-block mr-2"></i>
                    Buscar
                </button>
            </div>

            <!-- Tabela de Consentimentos -->
            <div id="consentimentos-container" class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr class="border-b border-dark-border">
                            <th class="px-4 py-3 text-gray-300 font-semibold">Data</th>
                            <th class="px-4 py-3 text-gray-300 font-semibold">Aluno</th>
                            <th class="px-4 py-3 text-gray-300 font-semibold">Email</th>
                            <th class="px-4 py-3 text-gray-300 font-semibold">CPF</th>
                            <th class="px-4 py-3 text-gray-300 font-semibold">Curso</th>
                            <th class="px-4 py-3 text-gray-300 font-semibold">Aula</th>
                            <th class="px-4 py-3 text-gray-300 font-semibold text-right">Ações</th>
                        </tr>
                    </thead>
                    <tbody id="consentimentos-tbody">
                        <tr>
                            <td colspan="7" class="px-4 py-8 text-center text-gray-400">
                                <i data-lucide="loader" class="w-8 h-8 mx-auto mb-2 animate-spin"></i>
                                <p>Carregando consentimentos...</p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Modal para Visualizar Documento de Consentimento -->
<div id="view-consent-modal" class="fixed inset-0 bg-black bg-opacity-60 z-50 flex items-center justify-center p-4 hidden">
    <div class="bg-gray-800 rounded-xl shadow-2xl w-full max-w-4xl max-h-[90vh] overflow-hidden border border-gray-700 flex flex-col">
        <div class="p-6 border-b border-gray-700 flex justify-between items-center flex-shrink-0">
            <h2 class="text-2xl font-bold text-white">Documento de Consentimento</h2>
            <button id="view-consent-close" class="text-gray-400 hover:text-white">
                <i data-lucide="x" class="w-6 h-6"></i>
            </button>
        </div>
        <div class="p-6 overflow-y-auto flex-1 bg-white">
            <div id="view-consent-content"></div>
        </div>
        <div class="p-6 border-t border-gray-700 flex justify-end flex-shrink-0">
            <button id="view-consent-download" class="px-6 py-2 text-white rounded-lg transition" style="background-color: var(--accent-primary);" onmouseover="this.style.backgroundColor='var(--accent-primary-hover)'" onmouseout="this.style.backgroundColor='var(--accent-primary)'">
                <i data-lucide="download" class="w-5 h-5 inline-block mr-2"></i>
                Download Documento
            </button>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    lucide.createIcons();

    // Sistema de Abas
    const tabGerenciar = document.getElementById('tab-gerenciar');
    const tabConsentimentos = document.getElementById('tab-consentimentos');
    const contentGerenciar = document.getElementById('content-gerenciar');
    const contentConsentimentos = document.getElementById('content-consentimentos');

    function switchTab(tab) {
        // Remove active de todas as abas
        document.querySelectorAll('.tab-button').forEach(btn => {
            btn.classList.remove('active', 'text-white');
            btn.classList.add('text-gray-400');
            btn.style.borderColor = 'transparent';
        });
        
        // Esconde todos os conteúdos
        document.querySelectorAll('.tab-content').forEach(content => {
            content.classList.add('hidden');
        });

        if (tab === 'gerenciar') {
            tabGerenciar.classList.add('active', 'text-white');
            tabGerenciar.classList.remove('text-gray-400');
            tabGerenciar.style.borderColor = 'var(--accent-primary)';
            contentGerenciar.classList.remove('hidden');
        } else {
            tabConsentimentos.classList.add('active', 'text-white');
            tabConsentimentos.classList.remove('text-gray-400');
            tabConsentimentos.style.borderColor = 'var(--accent-primary)';
            contentConsentimentos.classList.remove('hidden');
            loadConsentimentos(); // Carregar consentimentos ao abrir a aba
        }
    }

    tabGerenciar.addEventListener('click', () => switchTab('gerenciar'));
    tabConsentimentos.addEventListener('click', () => switchTab('consentimentos'));

    // Carregar consentimentos
    function loadConsentimentos(search = '') {
        const tbody = document.getElementById('consentimentos-tbody');
        tbody.innerHTML = '<tr><td colspan="7" class="px-4 py-8 text-center text-gray-400"><i data-lucide="loader" class="w-8 h-8 mx-auto mb-2 animate-spin"></i><p>Carregando consentimentos...</p></td></tr>';
        lucide.createIcons();

        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || document.querySelector('input[name="csrf_token"]')?.value || '';
        
        fetch('/api/get_download_consentimentos.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify({
                search: search,
                csrf_token: csrfToken
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                renderConsentimentos(data.consentimentos);
            } else {
                tbody.innerHTML = '<tr><td colspan="7" class="px-4 py-8 text-center text-red-400">Erro ao carregar consentimentos: ' + (data.error || 'Erro desconhecido') + '</td></tr>';
            }
        })
        .catch(error => {
            console.error('Erro:', error);
            tbody.innerHTML = '<tr><td colspan="7" class="px-4 py-8 text-center text-red-400">Erro ao carregar consentimentos.</td></tr>';
        });
    }

    function renderConsentimentos(consentimentos) {
        const tbody = document.getElementById('consentimentos-tbody');
        
        if (!consentimentos || consentimentos.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" class="px-4 py-8 text-center text-gray-400">Nenhum consentimento encontrado.</td></tr>';
            return;
        }

        tbody.innerHTML = consentimentos.map(consent => {
            const dataFormatada = new Date(consent.data_consentimento).toLocaleString('pt-BR');
            return `
                <tr class="border-b border-dark-border hover:bg-dark-elevated">
                    <td class="px-4 py-3 text-gray-300">${dataFormatada}</td>
                    <td class="px-4 py-3 text-gray-300">${escapeHtml(consent.aluno_nome)}</td>
                    <td class="px-4 py-3 text-gray-300">${escapeHtml(consent.aluno_email)}</td>
                    <td class="px-4 py-3 text-gray-300">${formatCPF(consent.aluno_cpf)}</td>
                    <td class="px-4 py-3 text-gray-300">${escapeHtml(consent.produto_nome)}</td>
                    <td class="px-4 py-3 text-gray-300">${escapeHtml(consent.aula_titulo)}</td>
                    <td class="px-4 py-3 text-right">
                        <button class="view-consent-btn text-blue-400 hover:text-blue-300 mr-3" data-consent-id="${consent.id}">
                            <i data-lucide="eye" class="w-5 h-5"></i>
                        </button>
                        <button class="download-consent-btn" style="color: var(--accent-primary);" onmouseover="this.style.color='var(--accent-primary-hover)'" onmouseout="this.style.color='var(--accent-primary)'" data-consent-id="${consent.id}">
                            <i data-lucide="download" class="w-5 h-5" style="color: inherit;"></i>
                        </button>
                    </td>
                </tr>
            `;
        }).join('');

        lucide.createIcons();

        // Event listeners para botões
        document.querySelectorAll('.view-consent-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const consentId = this.dataset.consentId;
                viewConsentimento(consentId);
            });
        });

        document.querySelectorAll('.download-consent-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                const consentId = this.dataset.consentId;
                downloadConsentimento(consentId);
            });
        });
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function formatCPF(cpf) {
        const cpfLimpo = cpf.replace(/\D/g, '');
        return cpfLimpo.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, '$1.$2.$3-$4');
    }

    function viewConsentimento(consentId) {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || document.querySelector('input[name="csrf_token"]')?.value || '';
        
        fetch('/api/get_consentimento_documento.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify({
                consentimento_id: consentId,
                csrf_token: csrfToken
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.getElementById('view-consent-content').innerHTML = data.documento_html;
                document.getElementById('view-consent-modal').classList.remove('hidden');
                document.getElementById('view-consent-download').dataset.consentId = consentId;
            } else {
                alert('Erro ao carregar documento: ' + (data.error || 'Erro desconhecido'));
            }
        })
        .catch(error => {
            console.error('Erro:', error);
            alert('Erro ao carregar documento.');
        });
    }

    async function downloadConsentimento(consentId) {
        if (!consentId) {
            alert('Erro: ID do consentimento não fornecido.');
            return;
        }
        
        const csrfToken = window.csrfToken || document.querySelector('meta[name="csrf-token"]')?.content || document.querySelector('input[name="csrf_token"]')?.value || '';
        
        if (!csrfToken) {
            alert('Erro: Token de segurança não encontrado. Recarregue a página e tente novamente.');
            return;
        }
        
        // Criar formulário para enviar via POST
        const formData = new FormData();
        formData.append('consentimento_id', consentId);
        formData.append('csrf_token', csrfToken);
        formData.append('download', '1');
        
        try {
            const response = await fetch('/api/get_consentimento_documento.php', {
                method: 'POST',
                headers: {
                    'X-CSRF-Token': csrfToken
                },
                body: formData
            });
            
            if (!response.ok) {
                const errorText = await response.text();
                let errorMsg = 'Erro ao baixar documento.';
                let shouldRetry = false;
                
                try {
                    const errorJson = JSON.parse(errorText);
                    errorMsg = errorJson.error || errorMsg;
                    
                    // Se recebeu novo token CSRF, atualizar e tentar novamente
                    if (errorJson.new_csrf_token) {
                        window.csrfToken = errorJson.new_csrf_token;
                        const metaTag = document.querySelector('meta[name="csrf-token"]');
                        if (metaTag) {
                            metaTag.setAttribute('content', errorJson.new_csrf_token);
                        }
                        shouldRetry = true;
                    }
                } catch (e) {
                    // Se não for JSON, usar texto
                    if (errorText.includes('CSRF')) {
                        errorMsg = 'Token de segurança inválido. Recarregue a página e tente novamente.';
                    }
                }
                
                // Se recebeu novo token, tentar novamente automaticamente
                if (shouldRetry && window.csrfToken) {
                    // Tentar novamente com novo token
                    const retryFormData = new FormData();
                    retryFormData.append('consentimento_id', consentId);
                    retryFormData.append('csrf_token', window.csrfToken);
                    retryFormData.append('download', '1');
                    
                    try {
                        const retryResponse = await fetch('/api/get_consentimento_documento.php', {
                            method: 'POST',
                            headers: {
                                'X-CSRF-Token': window.csrfToken
                            },
                            body: retryFormData
                        });
                        
                        if (retryResponse.ok) {
                            // Sucesso no retry - processar download normalmente
                            const retryHtmlContent = await retryResponse.text();
                            const retryBlob = new Blob([retryHtmlContent], { type: 'text/html;charset=utf-8' });
                            const retryUrl = window.URL.createObjectURL(retryBlob);
                            const retryFilename = 'consentimento_' + consentId + '_' + new Date().toISOString().slice(0, 10).replace(/-/g, '') + '.html';
                            
                            const isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
                            
                            if (isMobile) {
                                try {
                                    const link = document.createElement('a');
                                    link.href = retryUrl;
                                    link.download = retryFilename;
                                    link.style.display = 'none';
                                    document.body.appendChild(link);
                                    const clickEvent = new MouseEvent('click', {
                                        view: window,
                                        bubbles: true,
                                        cancelable: true
                                    });
                                    link.dispatchEvent(clickEvent);
                                    setTimeout(() => {
                                        document.body.removeChild(link);
                                        window.URL.revokeObjectURL(retryUrl);
                                    }, 100);
                                } catch (e) {
                                    window.open(retryUrl, '_blank');
                                    setTimeout(() => {
                                        window.URL.revokeObjectURL(retryUrl);
                                    }, 1000);
                                }
                            } else {
                                const link = document.createElement('a');
                                link.href = retryUrl;
                                link.download = retryFilename;
                                document.body.appendChild(link);
                                link.click();
                                document.body.removeChild(link);
                                window.URL.revokeObjectURL(retryUrl);
                            }
                            return; // Sucesso no retry
                        }
                    } catch (retryError) {
                        // Falhou no retry também
                    }
                }
                
                alert(errorMsg);
                return;
            }
            
            // Obter o conteúdo HTML
            const htmlContent = await response.text();
            
            // Detectar mobile
            const isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
            
            // Criar blob e fazer download
            const blob = new Blob([htmlContent], { type: 'text/html;charset=utf-8' });
            const url = window.URL.createObjectURL(blob);
            const filename = 'consentimento_' + consentId + '_' + new Date().toISOString().slice(0, 10).replace(/-/g, '') + '.html';
            
            if (isMobile) {
                // No mobile, usar método mais compatível
                try {
                    const link = document.createElement('a');
                    link.href = url;
                    link.download = filename;
                    link.style.display = 'none';
                    document.body.appendChild(link);
                    
                    // Tentar trigger de download
                    const clickEvent = new MouseEvent('click', {
                        view: window,
                        bubbles: true,
                        cancelable: true
                    });
                    link.dispatchEvent(clickEvent);
                    
                    // Limpar após um tempo
                    setTimeout(() => {
                        document.body.removeChild(link);
                        window.URL.revokeObjectURL(url);
                    }, 100);
                } catch (e) {
                    // Fallback: abrir em nova aba
                    window.open(url, '_blank');
                    // Limpar após um tempo
                    setTimeout(() => {
                        window.URL.revokeObjectURL(url);
                    }, 1000);
                }
            } else {
                // Desktop: método padrão
                const link = document.createElement('a');
                link.href = url;
                link.download = filename;
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
                window.URL.revokeObjectURL(url);
            }
            
        } catch (error) {
            alert('Erro ao baixar documento: ' + (error.message || 'Erro desconhecido'));
        }
    }

    // Fechar modal
    document.getElementById('view-consent-close').addEventListener('click', function() {
        document.getElementById('view-consent-modal').classList.add('hidden');
    });

    document.getElementById('view-consent-modal').addEventListener('click', function(e) {
        if (e.target === this) {
            this.classList.add('hidden');
        }
    });

    // Botão de download no modal
    document.getElementById('view-consent-download').addEventListener('click', function() {
        const consentId = this.dataset.consentId;
        if (consentId) {
            downloadConsentimento(consentId);
        } else {
            alert('Erro: ID do consentimento não encontrado.');
        }
    });

    // Busca
    document.getElementById('btn-filter').addEventListener('click', function() {
        const search = document.getElementById('filter-search').value;
        loadConsentimentos(search);
    });

    document.getElementById('filter-search').addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            loadConsentimentos(this.value);
        }
    });
});
</script>