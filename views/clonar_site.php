<?php
// Inclui o arquivo de configuração que inicia a sessão
require_once __DIR__ . '/../config/config.php';

// Verifica se o usuário está logado, se não, redireciona para a página de login
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: /login");
    exit;
}

$usuario_id_logado = $_SESSION['id'];

// Gerar token CSRF para uso em requisições JavaScript
require_once __DIR__ . '/../helpers/security_helper.php';
$csrf_token_js = generate_csrf_token();
?>

<meta name="csrf-token" content="<?php echo htmlspecialchars($csrf_token_js); ?>">
<script>
    // Variável global para token CSRF
    window.csrfToken = '<?php echo htmlspecialchars($csrf_token_js); ?>';
</script>

<style>
    /* CSS para a animação de clique nos botões */
    @keyframes bounce-once {
        0%, 100% { transform: scale(1); }
        50% { transform: scale(1.05); }
    }
    .animate-bounce-once {
        animation: bounce-once 0.3s ease-in-out;
    }
    
    /* Smooth transitions */
    .transition-all-300 {
        transition: all 0.3s ease-in-out;
    }

    /* Animação de Progresso da Publicação */
    @keyframes progress-loading {
        0% { width: 0%; }
        100% { width: 100%; }
    }
    
    .animate-progress {
        animation: progress-loading 2.5s ease-in-out forwards;
    }

    /* Confete simples CSS (opcional) */
    @keyframes confetti-fall {
        0% { transform: translateY(-100vh) rotate(0deg); opacity: 1; }
        100% { transform: translateY(100vh) rotate(720deg); opacity: 0; }
    }
    .confetti {
        position: fixed;
        width: 10px;
        height: 10px;
        background-color: var(--accent-primary);
        animation: confetti-fall 3s linear forwards;
        z-index: 60;
    }

    /* Scrollbar personalizada para o editor */
    .custom-scrollbar::-webkit-scrollbar {
        width: 8px;
    }
    .custom-scrollbar::-webkit-scrollbar-track {
        background: #0f1419;
    }
    .custom-scrollbar::-webkit-scrollbar-thumb {
        background: var(--accent-primary); 
        border-radius: 4px;
    }
    .custom-scrollbar::-webkit-scrollbar-thumb:hover {
        background: var(--accent-primary-hover); 
    }
</style>

<div class="container mx-auto p-4 lg:p-8 max-w-7xl">
    
    <!-- Header Simples e Moderno -->
    <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-8">
        <div>
            <h1 class="text-3xl font-extrabold text-white tracking-tight">Clonador de Sites</h1>
            <p class="text-gray-400 mt-1">Copie, edite e lance páginas de vendas em segundos.</p>
        </div>
        <div class="mt-4 md:mt-0">
             <!-- Espaço para ações globais futuras -->
        </div>
    </div>

    <!-- Seção de Clonagem de URL (Card Moderno Laranja) -->
    <div class="bg-dark-card rounded-xl shadow-lg overflow-hidden mb-8 transition-all hover:shadow-xl" style="border-color: var(--accent-primary);">
        <div class="p-6 md:p-8">
            <h2 class="text-xl font-bold text-white mb-4 flex items-center">
                <div class="p-2 rounded-lg mr-3" style="background-color: color-mix(in srgb, var(--accent-primary) 20%, transparent);">
                    <i data-lucide="globe" class="w-5 h-5" style="color: var(--accent-primary);"></i>
                </div>
                Nova Clonagem
            </h2>
            <form id="cloneForm" class="relative">
                <div class="flex flex-col md:flex-row gap-4 items-end">
                    <div class="flex-grow w-full">
                        <label for="url_to_clone" class="block text-sm font-semibold text-gray-300 mb-1">URL do Site Original</label>
                        <input type="text" id="url_to_clone" name="url_to_clone" class="block w-full sm:text-sm border-dark-border rounded-lg py-3 px-4 bg-dark-elevated text-white" style="--tw-ring-color: var(--accent-primary);" onfocus="this.style.borderColor='var(--accent-primary)'" onblur="this.style.borderColor='rgba(255,255,255,0.1)'" placeholder="www.exemplo.com/pagina" required>
                    </div>
                    <button type="submit" class="w-full md:w-auto flex items-center justify-center py-3 px-6 border border-transparent text-sm font-medium rounded-lg text-white focus:outline-none focus:ring-2 focus:ring-offset-2 transition-colors shadow-md hover:shadow-lg" style="background-color: var(--accent-primary); box-shadow: 0 10px 15px -3px color-mix(in srgb, var(--accent-primary) 20%, transparent); --tw-ring-color: var(--accent-primary);" onmouseover="this.style.backgroundColor='var(--accent-primary-hover)'" onmouseout="this.style.backgroundColor='var(--accent-primary)'">
                        <i data-lucide="copy" class="w-5 h-5 mr-2"></i>
                        Clonar Agora
                    </button>
                </div>
                <p class="mt-2 text-xs text-gray-400 flex items-center">
                    <i data-lucide="shield-check" class="w-3 h-3 mr-1"></i> Scripts de rastreamento externos são removidos automaticamente para segurança.
                </p>
                
                <!-- Aviso sobre compatibilidade -->
                <div class="mt-4 p-4 rounded-lg border" style="background-color: color-mix(in srgb, var(--accent-primary) 8%, transparent); border-color: color-mix(in srgb, var(--accent-primary) 30%, transparent);">
                    <div class="flex items-start gap-3">
                        <div class="flex-shrink-0 mt-0.5">
                            <i data-lucide="info" class="w-5 h-5" style="color: var(--accent-primary);"></i>
                        </div>
                        <div class="flex-1">
                            <p class="text-sm font-semibold text-white mb-1">Compatibilidade de Clonagem</p>
                            <p class="text-xs text-gray-300 leading-relaxed">
                                A clonagem funciona apenas em <strong class="text-white">sites HTML estáticos</strong> e <strong class="text-white">WordPress</strong>. 
                                <span class="block mt-1">Não funciona em aplicações <strong class="text-white">React</strong>, <strong class="text-white">Vite</strong>, <strong class="text-white">Next.js</strong> ou outras <strong class="text-white">SPAs (Single Page Applications)</strong>.</span>
                            </p>
                        </div>
                    </div>
                </div>
                
                <div id="cloneMessage" class="mt-3 text-sm font-medium"></div>
            </form>
        </div>
    </div>

    <!-- Seção do Editor de Páginas -->
    <div id="editorSection" class="hidden bg-dark-card rounded-xl shadow-lg overflow-hidden mb-8" style="border-color: var(--accent-primary);">
        <div class="border-b border-dark-border p-6" style="background-color: color-mix(in srgb, var(--accent-primary) 10%, transparent);">
            <div class="flex flex-col lg:flex-row justify-between lg:items-center gap-6">
                
                <!-- Título e Slug -->
                <div class="flex-grow space-y-4 w-full lg:w-2/3">
                    <div>
                        <label for="clonedSiteTitle" class="block text-xs font-bold text-gray-400 uppercase tracking-wider mb-1">Nome do Projeto</label>
                        <input type="text" id="clonedSiteTitle" class="block w-full border-dark-border bg-dark-elevated rounded-md shadow-sm sm:text-lg font-medium p-2 text-white" style="--tw-ring-color: var(--accent-primary);" onfocus="this.style.borderColor='var(--accent-primary)'" onblur="this.style.borderColor='rgba(255,255,255,0.1)'" placeholder="Ex: Página de Vendas Black Friday">
                    </div>

                    <!-- Slug Pequenininho -->
                    <div class="flex items-center">
                        <label for="clonedSiteSlug" class="block text-xs font-bold text-gray-400 uppercase tracking-wider mr-3 whitespace-nowrap">Link:</label>
                        <div class="flex rounded-md shadow-sm w-full max-w-sm">
                            <span class="inline-flex items-center px-3 rounded-l-md border border-r-0 border-dark-border bg-dark-elevated text-gray-400 text-xs">
                                /s/
                            </span>
                            <input type="text" id="clonedSiteSlug" class="focus:ring-[#32e768] focus:border-[#32e768] flex-1 block w-full rounded-none rounded-r-md text-xs border-dark-border py-1.5 bg-dark-elevated text-white" placeholder="minha-pagina">
                        </div>
                    </div>
                </div>

                <!-- Botões de Status (Modernizados e com Animação) -->
                <div class="flex flex-col items-end gap-3 min-w-[220px]">
                    <span class="text-xs font-bold text-gray-400 uppercase tracking-wider">Publicação</span>
                    
                    <!-- Container dos Botões -->
                    <div class="flex flex-col gap-2 w-full">
                        <!-- Botão Publicar (Destaque quando rascunho) -->
                        <button type="button" id="btnStatusPublished" class="group relative w-full flex items-center justify-center px-4 py-2 border border-transparent text-sm font-bold rounded-lg text-white bg-gradient-to-r from-[#32e768] to-[#28d15e] hover:from-[#28d15e] hover:to-[#32e768] focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#32e768] shadow-md transition-all transform hover:-translate-y-0.5">
                            <i data-lucide="rocket" class="w-4 h-4 mr-2 animate-pulse"></i>
                            <span id="txtBtnPublished">Publicar Site</span>
                        </button>

                        <!-- Botão Despublicar (Discreto) -->
                        <button type="button" id="btnStatusDraft" class="hidden w-full flex items-center justify-center px-4 py-2 border border-dark-border text-sm font-medium rounded-lg text-gray-300 bg-dark-card hover:bg-dark-elevated focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-dark-border transition-all">
                            <i data-lucide="eye-off" class="w-4 h-4 mr-2"></i>
                            Despublicar (Rascunho)
                        </button>
                    </div>

                    <!-- Indicador de Status Atual -->
                    <div id="statusIndicator" class="flex items-center text-xs font-medium text-gray-400 bg-dark-elevated px-2 py-1 rounded-full border border-dark-border">
                        <span class="w-2 h-2 rounded-full bg-gray-500 mr-2"></span>
                        Status: Rascunho
                    </div>
                    
                    <!-- Hidden input to store value -->
                    <input type="hidden" id="clonedSiteStatus" value="draft">
                </div>
            </div>
        </div>

        <div class="p-6">
            <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-4 gap-4">
                <div>
                    <h3 class="text-lg font-semibold text-white flex items-center">
                        <i data-lucide="edit-3" class="w-5 h-5 mr-2 text-[#32e768]"></i> Editor Visual
                    </h3>
                    <p class="text-xs text-gray-400 mt-1">Clique nos elementos abaixo para editar texto ou links.</p>
                </div>
            </div>
            
            <div id="editorStatus" class="text-sm text-gray-400 italic mb-2"></div>

            <!-- Barra de ferramentas do editor visual -->
            <div id="editorToolbar" class="flex flex-wrap gap-2 p-3 bg-dark-elevated border border-dark-border rounded-t-xl sticky top-0 z-10">
                <div class="flex gap-1 border-r border-dark-border pr-2 mr-1">
                    <button type="button" class="p-2 hover:bg-dark-card rounded hover:shadow-sm text-gray-300 transition-all" data-command="bold" title="Negrito"><i data-lucide="bold" class="w-4 h-4"></i></button>
                    <button type="button" class="p-2 hover:bg-dark-card rounded hover:shadow-sm text-gray-300 transition-all" data-command="italic" title="Itálico"><i data-lucide="italic" class="w-4 h-4"></i></button>
                    <button type="button" class="p-2 hover:bg-dark-card rounded hover:shadow-sm text-gray-300 transition-all" data-command="underline" title="Sublinhado"><i data-lucide="underline" class="w-4 h-4"></i></button>
                </div>
                
                <div class="flex gap-1 border-r border-dark-border pr-2 mr-1">
                    <button type="button" class="p-2 hover:bg-dark-card rounded hover:shadow-sm text-gray-300 transition-all" data-command="createLink" title="Inserir Link"><i data-lucide="link" class="w-4 h-4"></i></button>
                    <button type="button" class="p-2 hover:bg-dark-card rounded hover:shadow-sm text-gray-300 transition-all" data-command="unlink" title="Remover Link"><i data-lucide="link-2-off" class="w-4 h-4"></i></button>
                    <button type="button" class="p-2 hover:bg-dark-card rounded hover:shadow-sm text-gray-300 transition-all" data-command="insertImage" title="Imagem"><i data-lucide="image" class="w-4 h-4"></i></button>
                </div>

                <div class="flex gap-1 items-center border-r border-dark-border pr-2 mr-1">
                    <label class="flex items-center justify-center p-2 hover:bg-dark-card rounded hover:shadow-sm cursor-pointer text-gray-300 transition-all" title="Cor do Texto">
                        <div class="w-4 h-4 rounded-full bg-gradient-to-br from-[#32e768] to-[#28d15e] border border-dark-border mr-1"></div>
                        <input type="color" id="foreColorPicker" class="opacity-0 absolute w-0 h-0" value="#000000">
                        <i data-lucide="chevron-down" class="w-3 h-3 text-gray-400"></i>
                    </label>
                </div>

                <select class="px-3 py-1.5 bg-dark-card border border-dark-border rounded text-gray-300 text-sm focus:outline-none focus:ring-1 focus:ring-[#32e768] text-white" data-command="formatBlock">
                    <option value="p">Normal</option>
                    <option value="h1">Título 1</option>
                    <option value="h2">Título 2</option>
                    <option value="h3">Título 3</option>
                </select>

                <div class="flex-grow"></div>

                <div class="flex gap-1">
                    <button type="button" class="p-2 hover:bg-dark-card rounded hover:shadow-sm text-gray-400" data-command="undo"><i data-lucide="undo" class="w-4 h-4"></i></button>
                    <button type="button" class="p-2 hover:bg-dark-card rounded hover:shadow-sm text-gray-400" data-command="redo"><i data-lucide="redo" class="w-4 h-4"></i></button>
                    <button type="button" id="deleteElementBtn" class="p-2 bg-red-900/30 hover:bg-red-900/50 rounded text-red-300 border border-red-500/30 ml-2" title="Deletar Seleção"><i data-lucide="trash-2" class="w-4 h-4"></i></button>
                </div>
            </div>

            <!-- Iframe para edição visual -->
            <div class="relative group border border-dark-border rounded-b-xl overflow-hidden bg-dark-card">
                <iframe id="visualEditorFrame" src="about:blank" class="w-full h-[650px] bg-dark-card"></iframe>
                
                <!-- Loading Overlay -->
                <div id="editorLoadingOverlay" class="absolute inset-0 bg-dark-card/80 z-20 flex items-center justify-center hidden">
                    <div class="text-center">
                        <div class="animate-spin rounded-full h-10 w-10 border-b-2 border-[#32e768] mx-auto mb-2"></div>
                        <p class="text-sm text-gray-300">Preparando editor...</p>
                    </div>
                </div>
            </div>

            <!-- Footer Actions -->
            <div class="mt-6 flex flex-col md:flex-row justify-between items-center gap-4 pt-6 border-t border-dark-border">
                 <div id="saveMessage" class="text-sm order-2 md:order-1 w-full md:w-auto"></div>

                 <div class="flex gap-3 order-1 md:order-2 w-full md:w-auto">
                    <button id="copyHtmlFromEditorBtn" class="flex-1 md:flex-none relative py-2.5 px-5 border border-dark-border shadow-sm text-sm font-medium rounded-lg text-gray-300 bg-dark-card hover:bg-dark-elevated focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#32e768] transition-all overflow-hidden">
                        <span class="button-content flex items-center justify-center">
                            <i data-lucide="code" class="w-4 h-4 mr-2 text-gray-400"></i> Copiar Código
                        </span>
                        <span class="feedback-overlay absolute inset-0 flex items-center justify-center bg-dark-elevated text-white opacity-0 transition-opacity duration-300 pointer-events-none"></span>
                    </button>

                    <button id="saveVisualEditorBtn" class="flex-1 md:flex-none relative py-2.5 px-8 border border-transparent shadow-lg shadow-[#32e768]/20 text-sm font-bold rounded-lg text-white bg-[#32e768] hover:bg-[#28d15e] focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-[#32e768] transition-all overflow-hidden">
                        <span class="button-content flex items-center justify-center">
                            <i data-lucide="save" class="w-5 h-5 mr-2"></i> Salvar Tudo
                        </span>
                        <span class="feedback-overlay absolute inset-0 flex items-center justify-center bg-green-500 text-white opacity-0 transition-opacity duration-300 pointer-events-none"></span>
                    </button>
                </div>
            </div>
        </div>

        <!-- Scripts & Configs (Accordion Style) -->
        <div class="border-t border-dark-border bg-dark-elevated p-6">
            <details class="group">
                <summary class="flex justify-between items-center font-medium cursor-pointer list-none text-gray-300 hover:text-[#32e768] transition-colors">
                    <span class="flex items-center">
                        <i data-lucide="settings" class="w-5 h-5 mr-2 text-gray-400 group-hover:text-[#32e768]"></i>
                        Configurações Avançadas (Pixels e Scripts)
                    </span>
                    <span class="transition group-open:rotate-180">
                        <i data-lucide="chevron-down" class="w-5 h-5"></i>
                    </span>
                </summary>
                <div class="text-gray-400 mt-4 animate-fadeIn">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-4">
                        <div>
                            <label for="facebookPixelId" class="block text-xs font-semibold text-gray-300 uppercase mb-1">Facebook Pixel ID</label>
                            <input type="text" id="facebookPixelId" class="block w-full border-dark-border bg-dark-card rounded-md shadow-sm focus:ring-[#32e768] focus:border-[#32e768] sm:text-sm p-2 text-white" placeholder="Ex: 1234567890">
                        </div>
                        <div>
                            <label for="googleAnalyticsId" class="block text-xs font-semibold text-gray-300 uppercase mb-1">Google Analytics (G- / UA-)</label>
                            <input type="text" id="googleAnalyticsId" class="block w-full border-dark-border bg-dark-card rounded-md shadow-sm focus:ring-[#32e768] focus:border-[#32e768] sm:text-sm p-2 text-white" placeholder="Ex: G-XXXXXXXXXX">
                        </div>
                    </div>
                    
                    <div>
                        <label for="customHeadScripts" class="block text-xs font-semibold text-gray-300 uppercase mb-1">Scripts Personalizados (Head)</label>
                        <textarea id="customHeadScripts" class="block w-full border-dark-border bg-dark-card rounded-md shadow-sm focus:ring-[#32e768] focus:border-[#32e768] sm:text-sm p-2 font-mono h-24 text-white" placeholder="Cole aqui seus scripts do Taboola, Outbrain, Chat, etc..."></textarea>
                    </div>
                </div>
            </details>
        </div>
    </div>

    <!-- Lista de Sites Clonados -->
    <div class="bg-dark-card rounded-xl shadow-lg border border-[#32e768] overflow-hidden">
        <div class="p-6 md:p-8 border-b border-dark-border">
            <h2 class="text-xl font-bold text-white flex items-center">
                <i data-lucide="layers" class="w-5 h-5 mr-3 text-[#32e768]"></i> 
                Minha Biblioteca
            </h2>
        </div>
        <div id="clonedSitesList" class="divide-y divide-dark-border">
            <!-- Sites serão injetados aqui -->
            <div class="p-8 text-center text-gray-400">
                <i data-lucide="ghost" class="w-12 h-12 mx-auto mb-3 opacity-20"></i>
                <p>Nenhum site clonado ainda.</p>
            </div>
        </div>
    </div>
</div>

<!-- Floating Editor Box (Modernizado) -->
<div id="floatingEditorBox" class="fixed hidden flex-col p-4 bg-dark-card border border-dark-border rounded-xl shadow-2xl z-50 w-80 backdrop-blur-sm bg-dark-card/95 transition-opacity duration-200" style="top:0; left:0;">
    <div class="flex justify-between items-center mb-3 border-b border-dark-border pb-2">
        <span class="text-xs font-bold text-gray-300 uppercase flex items-center">
            <i data-lucide="edit-2" class="w-3 h-3 mr-1 text-[#32e768]"></i> Editar Elemento
        </span>
        <button id="closeFloatingEditor" class="text-gray-400 hover:text-gray-300 transition-colors p-1 rounded hover:bg-dark-elevated"><i data-lucide="x" class="w-4 h-4"></i></button>
    </div>
    
    <div class="space-y-3">
        <div id="textEditGroup">
            <label class="block text-xs font-medium text-gray-300 mb-1">Conteúdo de Texto</label>
            <textarea id="floatingEditorText" class="block w-full border-dark-border bg-dark-elevated rounded-lg shadow-sm text-sm focus:ring-[#32e768] focus:border-[#32e768] resize-none h-24 p-2 custom-scrollbar text-white"></textarea>
        </div>

        <div id="urlEditGroup">
            <label class="block text-xs font-medium text-gray-300 mb-1">Link de Destino (URL)</label>
            <input type="text" id="floatingEditorUrl" class="block w-full border-dark-border bg-dark-elevated rounded-lg shadow-sm text-sm focus:ring-[#32e768] focus:border-[#32e768] p-2 text-white" placeholder="https://...">
        </div>
        
        <div id="imageEditGroup" class="hidden">
            <label class="block text-xs font-medium text-gray-300 mb-1">URL da Imagem</label>
            <input type="text" id="floatingEditorImgSrc" class="block w-full border-dark-border bg-dark-elevated rounded-lg shadow-sm text-sm focus:ring-[#32e768] focus:border-[#32e768] p-2 text-white" placeholder="https://...">
        </div>
    </div>

    <div class="flex justify-end gap-2 mt-4 pt-3 border-t border-dark-border">
        <button id="deleteFloatingElementBtn" class="p-2 text-red-400 hover:bg-red-900/30 rounded-md transition-colors" title="Excluir Elemento">
            <i data-lucide="trash-2" class="w-4 h-4"></i>
        </button>
        <button id="saveFloatingEditorBtn" class="flex-1 inline-flex justify-center items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-[#32e768] hover:bg-[#28d15e] shadow-sm transition-all">
            Salvar Alteração
        </button>
    </div>
</div>

<!-- MODAL DE PUBLICAÇÃO SIMULADA -->
<div id="publishModal" class="fixed inset-0 z-50 hidden flex items-center justify-center bg-gray-900 bg-opacity-75 backdrop-blur-sm transition-opacity opacity-0 pointer-events-none">
    <div class="bg-dark-card rounded-2xl shadow-2xl p-8 max-w-md w-full transform scale-95 transition-transform relative border border-[#32e768]" id="publishModalContent">
        
        <!-- Botão Fechar (X) -->
        <button id="closePublishModalX" class="absolute top-4 right-4 text-gray-400 hover:text-gray-300 transition-colors focus:outline-none">
            <i data-lucide="x" class="w-6 h-6"></i>
        </button>

        <!-- Estado 1: Carregando -->
        <div id="publishStateLoading" class="text-center mt-4">
            <div class="w-20 h-20 bg-[#32e768]/20 rounded-full flex items-center justify-center mx-auto mb-6 animate-pulse">
                <i data-lucide="rocket" class="w-10 h-10 text-[#32e768]"></i>
            </div>
            <h3 class="text-2xl font-bold text-white mb-2">Lançando seu Site</h3>
            <p id="publishStepText" class="text-gray-400 mb-6 h-6">Iniciando compilação...</p>
            
            <!-- Barra de Progresso -->
            <div class="w-full bg-dark-elevated rounded-full h-3 mb-2 overflow-hidden">
                <div id="publishProgressBar" class="bg-gradient-to-r from-[#32e768] to-[#28d15e] h-3 rounded-full w-0 transition-all duration-300"></div>
            </div>
            <p class="text-xs text-gray-400 text-right"><span id="publishPercent">0</span>%</p>
        </div>

        <!-- Estado 2: Sucesso -->
        <div id="publishStateSuccess" class="hidden text-center mt-4">
            <div class="w-20 h-20 bg-green-900/30 rounded-full flex items-center justify-center mx-auto mb-6">
                <i data-lucide="check" class="w-10 h-10 text-green-400"></i>
            </div>
            <h3 class="text-2xl font-bold text-white mb-2">Sucesso!</h3>
            <p class="text-gray-400 mb-6">Sua página está publicada e online.</p>
            <button id="viewPublishedSiteBtn" class="w-full py-3 bg-[#32e768] text-white rounded-lg font-bold shadow-lg hover:bg-[#28d15e] transition-all flex items-center justify-center">
                Ver Site Online <i data-lucide="external-link" class="w-4 h-4 ml-2"></i>
            </button>
        </div>
    </div>
</div>

<script>
    lucide.createIcons();

    // Referências do DOM
    const cloneForm = document.getElementById('cloneForm');
    const urlToCloneInput = document.getElementById('url_to_clone');
    const cloneMessage = document.getElementById('cloneMessage');

    const editorSection = document.getElementById('editorSection');
    const clonedSiteTitleInput = document.getElementById('clonedSiteTitle');
    const clonedSiteSlugInput = document.getElementById('clonedSiteSlug');
    
    // NOVOS BOTÕES DE STATUS
    const btnStatusDraft = document.getElementById('btnStatusDraft');
    const btnStatusPublished = document.getElementById('btnStatusPublished');
    const txtBtnPublished = document.getElementById('txtBtnPublished');
    const clonedSiteStatusInput = document.getElementById('clonedSiteStatus');
    const statusIndicator = document.getElementById('statusIndicator');

    const visualEditorFrame = document.getElementById('visualEditorFrame');
    const editorToolbar = document.getElementById('editorToolbar');
    const saveVisualEditorBtn = document.getElementById('saveVisualEditorBtn');
    const copyHtmlFromEditorBtn = document.getElementById('copyHtmlFromEditorBtn');
    const editorStatus = document.getElementById('editorStatus');
    const saveMessage = document.getElementById('saveMessage');
    const editorLoadingOverlay = document.getElementById('editorLoadingOverlay');

    const facebookPixelIdInput = document.getElementById('facebookPixelId');
    const googleAnalyticsIdInput = document.getElementById('googleAnalyticsId');
    const customHeadScriptsInput = document.getElementById('customHeadScripts');

    const clonedSitesList = document.getElementById('clonedSitesList');

    // Floating Editor
    const floatingEditorBox = document.getElementById('floatingEditorBox');
    const floatingEditorText = document.getElementById('floatingEditorText');
    const floatingEditorUrl = document.getElementById('floatingEditorUrl');
    const floatingEditorImgSrc = document.getElementById('floatingEditorImgSrc');
    const textEditGroup = document.getElementById('textEditGroup');
    const urlEditGroup = document.getElementById('urlEditGroup');
    const imageEditGroup = document.getElementById('imageEditGroup');
    const saveFloatingEditorBtn = document.getElementById('saveFloatingEditorBtn');
    const deleteFloatingElementBtn = document.getElementById('deleteFloatingElementBtn');
    const closeFloatingEditorBtn = document.getElementById('closeFloatingEditor');
    const deleteElementBtn = document.getElementById('deleteElementBtn');
    const foreColorPicker = document.getElementById('foreColorPicker');
    
    // Safe Mode (Removido, mantemos variável HTML original para consistência)
    let originalHtmlContent = ""; 

    // Modal Elements
    const publishModal = document.getElementById('publishModal');
    const publishModalContent = document.getElementById('publishModalContent');
    const publishStateLoading = document.getElementById('publishStateLoading');
    const publishStateSuccess = document.getElementById('publishStateSuccess');
    const publishProgressBar = document.getElementById('publishProgressBar');
    const publishPercent = document.getElementById('publishPercent');
    const publishStepText = document.getElementById('publishStepText');
    
    // Updated References for Modal Buttons
    const closePublishModalX = document.getElementById('closePublishModalX');
    const viewPublishedSiteBtn = document.getElementById('viewPublishedSiteBtn');


    let currentClonedSiteId = null;
    let editorDoc = null;
    let currentSelectedElement = null;
    let previousSelectedElement = null;


    // --- LÓGICA DOS BOTÕES DE STATUS ---
    function updateStatusUI(status, skipAnimation = false) {
        clonedSiteStatusInput.value = status;
        
        if (status === 'published') {
            // UI quando PUBLICADO
            btnStatusPublished.classList.add('hidden'); // Esconde o botão publicar
            btnStatusDraft.classList.remove('hidden'); // Mostra o botão despublicar
            
            // Atualiza o indicador
            statusIndicator.innerHTML = '<span class="w-2 h-2 rounded-full bg-green-500 mr-2 animate-pulse"></span> Status: <span class="text-green-600 font-bold ml-1">ONLINE</span>';
            statusIndicator.classList.remove('bg-gray-100', 'text-gray-500');
            statusIndicator.classList.add('bg-green-50', 'text-green-700', 'border', 'border-green-200');

        } else {
            // UI quando RASCUNHO
            btnStatusPublished.classList.remove('hidden'); // Mostra o botão publicar
            btnStatusDraft.classList.add('hidden'); // Esconde o botão despublicar

            // Atualiza o indicador
            statusIndicator.innerHTML = '<span class="w-2 h-2 rounded-full bg-gray-500 mr-2"></span> Status: Rascunho';
            statusIndicator.classList.remove('bg-[#32e768]/20', 'text-[#32e768]', 'border', 'border-[#32e768]/30');
            statusIndicator.classList.add('bg-dark-elevated', 'text-gray-400');
        }
    }

    // Click no botão "Despublicar" (Simples)
    btnStatusDraft.addEventListener('click', () => {
        if(confirm("Tem certeza que deseja retirar o site do ar? Ele voltará a ser um rascunho.")) {
            updateStatusUI('draft');
            showMessage(saveMessage, 'Site revertido para rascunho.', 'info');
        }
    });

    // Click no botão "Publicar" (Com Animação)
    btnStatusPublished.addEventListener('click', () => {
        runPublishAnimation();
    });

    // Função de Animação de Publicação
    function runPublishAnimation() {
        // Reset Modal State
        publishStateLoading.classList.remove('hidden');
        publishStateSuccess.classList.add('hidden');
        publishProgressBar.style.width = '0%';
        publishPercent.innerText = '0';
        
        // Show Modal
        publishModal.classList.remove('hidden', 'opacity-0', 'pointer-events-none');
        publishModalContent.classList.remove('scale-95');
        publishModalContent.classList.add('scale-100');

        // Steps sequence
        const steps = [
            { pct: 20, text: "Otimizando imagens..." },
            { pct: 45, text: "Gerando HTML estático..." },
            { pct: 70, text: "Configurando scripts de rastreamento..." },
            { pct: 90, text: "Enviando para o servidor..." },
            { pct: 100, text: "Finalizando..." }
        ];

        let currentStep = 0;

        const interval = setInterval(() => {
            if (currentStep >= steps.length) {
                clearInterval(interval);
                finishPublishing();
                return;
            }

            const step = steps[currentStep];
            publishProgressBar.style.width = step.pct + '%';
            publishPercent.innerText = step.pct;
            publishStepText.innerText = step.text;
            
            currentStep++;
        }, 500); // 500ms per step = ~2.5s total animation
    }

    function finishPublishing() {
        // Change to success state
        setTimeout(() => {
            publishStateLoading.classList.add('hidden');
            publishStateSuccess.classList.remove('hidden');
            
            // Create Confetti
            createConfetti();
            
            // Update actual logic status
            updateStatusUI('published');
            
            // Trigger auto-save to persist the 'published' status
            saveVisualEditorBtn.click(); 

        }, 500);
    }

    function createConfetti() {
        for (let i = 0; i < 50; i++) {
            const confetti = document.createElement('div');
            confetti.classList.add('confetti');
            confetti.style.left = Math.random() * 100 + 'vw';
            confetti.style.backgroundColor = ['#32e768', '#28d15e', '#22c55e', '#16a34a'][Math.floor(Math.random() * 4)];
            confetti.style.animationDuration = (Math.random() * 3 + 2) + 's';
            document.body.appendChild(confetti);
            
            setTimeout(() => confetti.remove(), 5000);
        }
    }

    // Fecha o modal ao clicar no X
    closePublishModalX.addEventListener('click', () => {
        closeModalAnimation();
    });

    // Abre o site e fecha o modal
    viewPublishedSiteBtn.addEventListener('click', () => {
        const slug = clonedSiteSlugInput.value;
        if(slug) {
            // Opens in a new tab
            const url = `cloned_site_viewer.php?slug=${slug}`;
            window.open(url, '_blank');
            closeModalAnimation();
        } else {
            alert("Erro: O link do site não foi gerado corretamente.");
        }
    });

    function closeModalAnimation() {
        publishModal.classList.add('opacity-0', 'pointer-events-none');
        publishModalContent.classList.remove('scale-100');
        publishModalContent.classList.add('scale-95');
        setTimeout(() => publishModal.classList.add('hidden'), 300);
    }


    // --- FUNÇÕES UTILITÁRIAS ---
    function applyBounceAnimation(buttonElement) {
        buttonElement.classList.add('animate-bounce-once');
        buttonElement.addEventListener('animationend', () => {
            buttonElement.classList.remove('animate-bounce-once');
        }, { once: true });
    }

    function showButtonFeedback(buttonElement, message, type = 'success') {
        const buttonContent = buttonElement.querySelector('.button-content');
        const feedbackOverlay = buttonElement.querySelector('.feedback-overlay');
        
        let icon = type === 'success' ? '<i data-lucide="check-circle" class="w-5 h-5 mr-2"></i>' : '<i data-lucide="x-circle" class="w-5 h-5 mr-2"></i>';
        let bgColorClass = type === 'success' ? 'bg-green-600' : 'bg-red-500';

        if (buttonElement.id === 'copyHtmlFromEditorBtn') bgColorClass = type === 'success' ? 'bg-gray-800' : 'bg-red-500';

        feedbackOverlay.className = `feedback-overlay absolute inset-0 flex items-center justify-center text-white opacity-0 transition-opacity duration-300 pointer-events-none ${bgColorClass}`;
        feedbackOverlay.innerHTML = `${icon}<span>${message}</span>`;
        lucide.createIcons({ container: feedbackOverlay });

        buttonContent.classList.remove('opacity-100');
        buttonContent.classList.add('opacity-0');
        feedbackOverlay.classList.remove('opacity-0');
        feedbackOverlay.classList.add('opacity-100');

        applyBounceAnimation(buttonElement);

        setTimeout(() => {
            feedbackOverlay.classList.remove('opacity-100');
            feedbackOverlay.classList.add('opacity-0');
            buttonContent.classList.remove('opacity-0');
            buttonContent.classList.add('opacity-100');
        }, 1500);
    }

    function showMessage(element, message, type = 'info') {
        if (!element) return;
        element.innerHTML = message;
        // Tailwind styling for messages
        const baseClasses = "mt-3 p-3 rounded-lg text-sm font-medium flex items-center";
        
        if (type === 'success') {
            element.className = `${baseClasses} bg-green-900/20 text-green-300 border border-green-500/30`;
            element.innerHTML = `<i data-lucide="check" class="w-4 h-4 mr-2"></i> ${message}`;
        } else if (type === 'error') {
            element.className = `${baseClasses} bg-red-900/20 text-red-300 border border-red-500/30`;
            element.innerHTML = `<i data-lucide="alert-triangle" class="w-4 h-4 mr-2"></i> ${message}`;
        } else {
            element.className = `${baseClasses} bg-blue-900/20 text-blue-300 border border-blue-500/30 animate-pulse`;
            element.innerHTML = `<i data-lucide="loader" class="w-4 h-4 mr-2 animate-spin"></i> ${message}`;
        }
        lucide.createIcons({ container: element });
        
        element.classList.remove('hidden');
        setTimeout(() => { if (element) element.classList.add('hidden'); }, 5000);
    }

    // --- EDITOR VISUAL ---
    function initializeVisualEditor(htmlContent) {
        editorLoadingOverlay.classList.remove('hidden');
        
        // Carrega o HTML original sem remover scripts
        let processedHtml = htmlContent;

        setTimeout(() => {
            editorDoc = visualEditorFrame.contentDocument || visualEditorFrame.contentWindow.document;
            editorDoc.open();
            editorDoc.write(processedHtml);
            editorDoc.close();
            
            const editorStyles = `
                ::selection { background: rgba(50, 231, 104, 0.3); color: #ffffff; }
                img, video, audio, iframe { cursor: pointer; transition: transform 0.2s ease; }
                img:hover, video:hover, audio:hover, iframe:hover, a:hover { outline: 2px dashed #32e768; outline-offset: 2px; }
                *[contenteditable="true"]:focus { outline: none; }
                body { padding-bottom: 200px !important; } /* Space for scrolling */
            `;
            
            const styleEl = editorDoc.createElement('style');
            styleEl.id = 'editor-ui-styles'; // ID para fácil remoção
            styleEl.textContent = editorStyles;
            
            if (editorDoc.head) editorDoc.head.appendChild(styleEl);
            else {
                const head = editorDoc.createElement('head');
                editorDoc.documentElement.insertBefore(head, editorDoc.body);
                head.appendChild(styleEl);
            }
            
            // Garantir que o body existe antes de manipular
            if (!editorDoc.body) {
                editorDoc.write("<body>" + processedHtml + "</body>");
            }

            if (editorDoc.body) {
                editorDoc.body.setAttribute('contenteditable', 'true');
                editorDoc.designMode = 'on';

                editorDoc.body.addEventListener('input', () => {
                    editorStatus.textContent = '● Alterações pendentes...';
                    editorStatus.classList.add('text-[#32e768]', 'animate-pulse');
                });

                // USE CAPTURE: TRUE para forçar nosso evento a disparar antes dos scripts do site
                editorDoc.addEventListener('click', handleElementClickInEditor, true);
                
                // Previne navegação normal de links
                const links = editorDoc.querySelectorAll('a');
                links.forEach(link => {
                    link.addEventListener('click', (e) => {
                        e.preventDefault(); // Stop navigation
                        e.stopPropagation();
                    });
                });
            }

            editorStatus.textContent = 'Pronto para editar';
            editorStatus.classList.remove('text-[#32e768]', 'animate-pulse');
            editorLoadingOverlay.classList.add('hidden');
        }, 100);
    }

    function handleElementClickInEditor(e) {
        // Stop Everything: Propagation, Immediate Propagation, Default Action
        e.preventDefault(); 
        e.stopPropagation();
        e.stopImmediatePropagation();

        if (previousSelectedElement) {
            previousSelectedElement.style.outline = '';
            previousSelectedElement.style.boxShadow = '';
        }

        let target = e.target;
        
        // Find the most meaningful parent if clicked on a small generic tag inside a link
        // Example: clicking <span> inside <a href> -> select <a>
        if (target.tagName !== 'A' && target.closest('a')) {
            target = target.closest('a');
        }

        currentSelectedElement = target;

        // Efeito de seleção Verde
        target.style.outline = '3px solid #32e768'; 
        target.style.boxShadow = '0 0 0 4px rgba(50, 231, 104, 0.2)';
        previousSelectedElement = target;

        let editableText = false;
        let editableUrl = false;
        let editableImg = false;
        
        let urlValue = '';
        let textValue = '';
        let imgSrcValue = '';

        if (target.nodeType === Node.ELEMENT_NODE && target.tagName !== 'HTML' && target.tagName !== 'BODY') {
            textValue = target.innerText.trim();
            editableText = true;

            if (target.tagName === 'A') {
                urlValue = target.getAttribute('href') || '';
                editableUrl = true;
            } else if (['IMG', 'VIDEO', 'IFRAME'].includes(target.tagName)) {
                imgSrcValue = target.getAttribute('src') || '';
                editableImg = true;
                if(target.tagName === 'IMG') editableText = false;
            }
        }

        floatingEditorText.value = textValue;
        floatingEditorUrl.value = urlValue;
        floatingEditorImgSrc.value = imgSrcValue;

        textEditGroup.classList.toggle('hidden', !editableText);
        urlEditGroup.classList.toggle('hidden', !editableUrl);
        imageEditGroup.classList.toggle('hidden', !editableImg);

        positionFloatingEditor(target);
        floatingEditorBox.classList.remove('hidden');
    }

    function positionFloatingEditor(targetElement) {
        const rect = targetElement.getBoundingClientRect();
        const iframeRect = visualEditorFrame.getBoundingClientRect();
        const editorBoxWidth = floatingEditorBox.offsetWidth;
        const editorBoxHeight = floatingEditorBox.offsetHeight;

        const targetAbsoluteTop = iframeRect.top + rect.top;
        const targetAbsoluteLeft = iframeRect.left + rect.left;
        const targetAbsoluteBottom = iframeRect.top + rect.bottom;

        // Posição Padrão: Embaixo do elemento
        let finalTop = targetAbsoluteBottom + 10; 
        let finalLeft = targetAbsoluteLeft;

        // Correção de borda direita: Se sair da tela, alinha à direita
        if (finalLeft + editorBoxWidth > window.innerWidth - 20) {
            finalLeft = window.innerWidth - editorBoxWidth - 20;
        }
        if (finalLeft < 20) finalLeft = 20;
        
        // Correção de borda inferior: Se sair da tela, joga pra cima do elemento
        if (finalTop + editorBoxHeight > window.innerHeight - 20) {
            finalTop = targetAbsoluteTop - editorBoxHeight - 10;
        }
        
        // Correção de borda superior (caso jogue pra cima e saia)
        if (finalTop < iframeRect.top) {
            finalTop = iframeRect.top + 10;
        }
        
        floatingEditorBox.style.top = `${finalTop}px`;
        floatingEditorBox.style.left = `${finalLeft}px`;
        
        // Adiciona um pequeno fade-in
        floatingEditorBox.classList.remove('opacity-0');
        floatingEditorBox.classList.add('opacity-100');
    }

    // --- HANDLERS DO EDITOR FLUTUANTE ---
    saveFloatingEditorBtn.addEventListener('click', () => {
        if (currentSelectedElement) {
            // Salvar Texto
            if (!textEditGroup.classList.contains('hidden')) {
                currentSelectedElement.innerText = floatingEditorText.value;
            }
            
            // Salvar URL (Links)
            if (!urlEditGroup.classList.contains('hidden')) {
                const url = floatingEditorUrl.value;
                if (currentSelectedElement.tagName === 'A') {
                    currentSelectedElement.setAttribute('href', url);
                    currentSelectedElement.setAttribute('target', '_self'); // Force self to avoid popups
                }
            }
            
            // Salvar Fonte (Imagem/Iframe)
            if (!imageEditGroup.classList.contains('hidden')) {
                const src = floatingEditorImgSrc.value;
                if (['IMG', 'VIDEO', 'IFRAME'].includes(currentSelectedElement.tagName)) {
                    currentSelectedElement.setAttribute('src', src);
                }
            }
            
            editorStatus.textContent = '● Elemento atualizado!';
        }
        floatingEditorBox.classList.add('hidden');
        if (previousSelectedElement) {
            previousSelectedElement.style.outline = '';
            previousSelectedElement.style.boxShadow = '';
        }
        currentSelectedElement = null;
    });

    deleteFloatingElementBtn.addEventListener('click', () => {
        if (currentSelectedElement && confirm('Excluir este elemento?')) {
            currentSelectedElement.remove();
            editorStatus.textContent = '● Elemento removido';
        }
        floatingEditorBox.classList.add('hidden');
        currentSelectedElement = null;
    });

    closeFloatingEditorBtn.addEventListener('click', () => {
        floatingEditorBox.classList.add('hidden');
        if (previousSelectedElement) {
            previousSelectedElement.style.outline = '';
            previousSelectedElement.style.boxShadow = '';
        }
        currentSelectedElement = null;
    });

    // --- BARRA DE FERRAMENTAS ---
    deleteElementBtn.addEventListener('click', () => {
        if (editorDoc) {
            const selection = editorDoc.getSelection();
            if (selection.rangeCount > 0 && !selection.isCollapsed) {
                executeCommand('delete');
            } else if (currentSelectedElement) {
                currentSelectedElement.remove();
                floatingEditorBox.classList.add('hidden');
            } else {
                alert('Selecione algo para deletar.');
            }
        }
    });

    function executeCommand(command, value = null) {
        if (editorDoc) {
            editorDoc.execCommand(command, false, value);
            editorStatus.textContent = '● Editando...';
        }
    }

    editorToolbar.addEventListener('click', (e) => {
        const button = e.target.closest('button');
        const select = e.target.closest('select');

        if (button && button.id !== 'deleteElementBtn') {
            const command = button.dataset.command;
            if (command === 'createLink') {
                const url = prompt('URL do link:', 'http://');
                if (url) executeCommand(command, url);
            } else if (command === 'insertImage') {
                const imageUrl = prompt('URL da imagem:');
                if (imageUrl) executeCommand(command, imageUrl);
            } else {
                executeCommand(command);
            }
            // Feedback visual no botão
            button.classList.add('bg-[#32e768]/20', 'text-[#32e768]');
            setTimeout(() => button.classList.remove('bg-[#32e768]/20', 'text-[#32e768]'), 200);
        } else if (select) {
            const command = select.dataset.command;
            executeCommand(command, select.value);
        }
    });

    foreColorPicker.addEventListener('change', (e) => executeCommand('foreColor', e.target.value));

    // --- AÇÕES PRINCIPAIS (CLONAR/SALVAR) ---
    cloneForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        let url = urlToCloneInput.value.trim();
        
        if (!url) {
            showMessage(cloneMessage, 'Digite uma URL válida.', 'error');
            return;
        }

        // Remove https:// ou http:// se já estiver presente
        url = url.replace(/^https?:\/\//i, '');
        
        // Adiciona https:// se não tiver protocolo
        if (!url.match(/^https?:\/\//i)) {
            url = 'https://' + url;
        }

        showMessage(cloneMessage, 'Iniciando clonagem...', 'loading');

        try {
            const response = await fetch('/api.php?action=clone_url', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ url: url })
            });
            
            // Verificar se a resposta é JSON
            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                const text = await response.text();
                console.error('Resposta não é JSON:', text.substring(0, 200));
                showMessage(cloneMessage, 'Erro: A API retornou HTML. Verifique se está logado.', 'error');
                return;
            }
            
            if (!response.ok) {
                // Tentar ler a resposta como JSON para obter a mensagem de erro
                let errorMessage = `Erro HTTP: ${response.status}`;
                try {
                    const errorData = await response.clone().json();
                    if (errorData.error) {
                        errorMessage = errorData.error;
                    }
                } catch (e) {
                    // Se não for JSON, usar o status
                }
                throw new Error(errorMessage);
            }
            
            const data = await response.json();

            if (data.success) {
                showMessage(cloneMessage, 'Sucesso! Site clonado.', 'success');
                currentClonedSiteId = data.cloned_site_id;
                
                // Populate fields
                clonedSiteTitleInput.value = data.title;
                clonedSiteSlugInput.value = '';
                updateStatusUI('draft');
                
                // Show Editor
                editorSection.classList.remove('hidden');
                editorSection.scrollIntoView({ behavior: 'smooth' });
                
                // Clear tracking
                facebookPixelIdInput.value = '';
                googleAnalyticsIdInput.value = '';
                customHeadScriptsInput.value = '';
                
                // Store original HTML
                originalHtmlContent = data.html_content;
                
                initializeVisualEditor(originalHtmlContent);
                loadClonedSites();
            } else {
                showMessage(cloneMessage, `Erro: ${data.error}`, 'error');
            }
        } catch (error) {
            console.error('Erro ao clonar URL:', error);
            showMessage(cloneMessage, error.message || 'Erro ao clonar URL. Verifique se a URL está correta.', 'error');
        }
    });

    saveVisualEditorBtn.addEventListener('click', async () => {
        if (!currentClonedSiteId) return;

        // Se o botão foi clicado manualmente e NÃO está no contexto da animação de publicação,
        // apenas mostre a mensagem de salvamento normal.
        if (publishModal.classList.contains('hidden')) {
            showMessage(saveMessage, 'Salvando alterações...', 'loading');
        }

        // --- NOVA LIMPEZA ROBUSTA: PARSING E REMOÇÃO TOTAL ---
        
        // 1. Capturar HTML bruto, incluindo Doctype
        let rawHtml = "";
        if (editorDoc.doctype) {
             rawHtml += "<!DOCTYPE " + editorDoc.doctype.name + (editorDoc.doctype.publicId ? ' PUBLIC "' + editorDoc.doctype.publicId + '"' : '') + (!editorDoc.doctype.publicId && editorDoc.doctype.systemId ? ' SYSTEM' : '') + (editorDoc.doctype.systemId ? ' "' + editorDoc.doctype.systemId + '"' : '') + ">\n";
        } else {
             rawHtml += "<!DOCTYPE html>\n";
        }
        
        rawHtml += editorDoc.documentElement.outerHTML;

        // 2. Criar um documento virtual para limpeza
        const parser = new DOMParser();
        const doc = parser.parseFromString(rawHtml, 'text/html');

        // 3. Remover estilo do editor
        const styleEl = doc.getElementById('editor-ui-styles');
        if (styleEl) styleEl.remove();

        // 4. LIMPEZA PROFUNDA: Remover contenteditable de TODOS os elementos
        const editableElements = doc.querySelectorAll('[contenteditable]');
        editableElements.forEach(el => el.removeAttribute('contenteditable'));
        doc.body.removeAttribute('contenteditable'); // Garante que o body está limpo

        // 5. Remover artefatos visuais de seleção (bordas laranjas)
        const allElements = doc.querySelectorAll('*');
        allElements.forEach(el => {
            // Remove estilos inline que contenham 'outline' ou 'box-shadow' usados pelo editor
            if (el.style.length > 0) {
                // Checagem simples para ver se é o estilo do editor
                if (el.style.outline.includes('#32e768') || el.style.outline.includes('rgb(50, 231, 104)') || el.style.boxShadow.includes('50, 231, 104')) {
                    el.style.outline = '';
                    el.style.boxShadow = '';
                    // Se o atributo style ficar vazio, remove ele totalmente
                    if (el.getAttribute('style') === '') {
                        el.removeAttribute('style');
                    }
                }
            }
        });

        // 6. Serializar de volta para string limpa
        let editedHtml = "";
        // Re-adiciona o doctype original (parser às vezes perde doctypes complexos)
        if (editorDoc.doctype) {
             editedHtml += "<!DOCTYPE " + editorDoc.doctype.name + (editorDoc.doctype.publicId ? ' PUBLIC "' + editorDoc.doctype.publicId + '"' : '') + (!editorDoc.doctype.publicId && editorDoc.doctype.systemId ? ' SYSTEM' : '') + (editorDoc.doctype.systemId ? ' "' + editorDoc.doctype.systemId + '"' : '') + ">\n";
        } else {
             editedHtml += "<!DOCTYPE html>\n";
        }
        editedHtml += doc.documentElement.outerHTML;
        
        // Atualiza a "originalHtmlContent" para o estado atual salvo, caso troque de toggle
        originalHtmlContent = editedHtml;
        // ----------------------------------------------------------------

        // Use the hidden input value which is updated by the buttons
        const newStatus = clonedSiteStatusInput.value;

        try {
            const response = await fetch('/api.php?action=save_cloned_site', {
                method: 'POST',
                headers: { 
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': window.csrfToken || ''
                },
                body: JSON.stringify({
                    cloned_site_id: currentClonedSiteId,
                    edited_html_content: editedHtml,
                    title: clonedSiteTitleInput.value,
                    slug: clonedSiteSlugInput.value,
                    status: newStatus,
                    facebook_pixel_id: facebookPixelIdInput.value,
                    google_analytics_id: googleAnalyticsIdInput.value,
                    custom_head_scripts: customHeadScriptsInput.value,
                    csrf_token: window.csrfToken || ''
                })
            });
            
            // Verificar se a resposta é JSON
            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                const text = await response.text();
                console.error('Resposta não é JSON:', text.substring(0, 200));
                showMessage(saveMessage, 'Erro: A API retornou HTML. Verifique se está logado.', 'error');
                return;
            }
            
            if (!response.ok) {
                throw new Error(`Erro HTTP: ${response.status}`);
            }
            
            const data = await response.json();

            if (data.success) {
                // Só mostra msg se NÃO estiver no modal de publicação
                if (publishModal.classList.contains('hidden')) {
                    showMessage(saveMessage, 'Salvo com sucesso!', 'success');
                    editorStatus.textContent = 'Todas as alterações salvas.';
                    showButtonFeedback(saveVisualEditorBtn, 'Salvo!', 'success');
                }
                loadClonedSites();
            } else {
                showMessage(saveMessage, `Erro: ${data.error}`, 'error');
                showButtonFeedback(saveVisualEditorBtn, 'Erro!', 'error');
            }
        } catch (error) {
            showMessage(saveMessage, 'Erro de conexão.', 'error');
            showButtonFeedback(saveVisualEditorBtn, 'Erro!', 'error');
        }
    });

    copyHtmlFromEditorBtn.addEventListener('click', async () => {
        if (!currentClonedSiteId) return;
        
        showMessage(saveMessage, 'Gerando código...', 'loading');

        try {
            const response = await fetch(`/cloned_site_viewer?id=${currentClonedSiteId}`);
            if (!response.ok) throw new Error();
            const fullHtml = await response.text();

            await navigator.clipboard.writeText(fullHtml);
            showMessage(saveMessage, 'HTML copiado!', 'success');
            showButtonFeedback(copyHtmlFromEditorBtn, 'Copiado!', 'success');
        } catch (err) {
            showMessage(saveMessage, 'Erro ao copiar.', 'error');
            showButtonFeedback(copyHtmlFromEditorBtn, 'Erro', 'error');
        }
    });

    async function loadClonedSites() {
        try {
            const response = await fetch('/api.php?action=get_cloned_sites');
            
            // Verificar se a resposta é JSON
            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                const text = await response.text();
                console.error('Resposta não é JSON. Status:', response.status);
                console.error('Content-Type:', contentType);
                console.error('Resposta (primeiros 500 chars):', text.substring(0, 500));
                
                // Se for erro 403, pode ser problema de autenticação
                if (response.status === 403) {
                    throw new Error('Acesso não autorizado. Faça login novamente.');
                }
                
                throw new Error('A API retornou HTML em vez de JSON. Verifique se está logado.');
            }
            
            if (!response.ok) {
                // Tentar ler a resposta como JSON mesmo em caso de erro
                try {
                    const errorData = await response.json();
                    if (errorData.error) {
                        throw new Error(errorData.error);
                    }
                } catch (e) {
                    // Se não for JSON, usar o status
                }
                throw new Error(`Erro HTTP: ${response.status}`);
            }
            
            const data = await response.json();

            clonedSitesList.innerHTML = '';

            if (data.success && data.cloned_sites.length > 0) {
                data.cloned_sites.forEach(site => {
                    const isPublished = site.status === 'published';
                    const statusClass = isPublished ? 'bg-green-900/30 text-green-300 border-green-500/30' : 'bg-dark-elevated text-gray-300 border-dark-border';
                    const statusText = isPublished ? 'No Ar' : 'Rascunho';
                    
                    const publicLink = (isPublished && site.slug) 
                        ? `<a href="/cloned_site_viewer?slug=${site.slug}" target="_blank" class="flex items-center mt-2 text-sm text-[#32e768] hover:text-[#28d15e] font-medium transition-colors">
                             <i data-lucide="external-link" class="w-4 h-4 mr-1"></i> Acessar Página
                           </a>` 
                        : '';

                    const card = document.createElement('div');
                    card.className = 'p-6 hover:bg-dark-elevated transition-colors flex flex-col md:flex-row justify-between items-start md:items-center group';
                    
                    card.innerHTML = `
                        <div class="flex-1 min-w-0 pr-4">
                            <div class="flex items-center gap-3 mb-1">
                                <h3 class="text-lg font-bold text-white truncate">${site.title || 'Sem Título'}</h3>
                                <span class="px-2.5 py-0.5 rounded-full text-xs font-bold border ${statusClass}">${statusText}</span>
                            </div>
                            <p class="text-xs text-gray-400 truncate font-mono">${site.original_url}</p>
                            ${publicLink}
                            <p class="text-xs text-gray-400 mt-2">Criado em: ${new Date(site.created_at).toLocaleDateString()}</p>
                        </div>
                        <div class="flex items-center gap-2 mt-4 md:mt-0 opacity-100 md:opacity-0 group-hover:opacity-100 transition-opacity">
                            <button onclick="editClonedSite(${site.id})" class="p-2 text-gray-400 hover:text-[#32e768] hover:bg-[#32e768]/20 rounded-lg transition-colors" title="Editar">
                                <i data-lucide="edit-3" class="w-5 h-5"></i>
                            </button>
                            <button onclick="deleteClonedSite(${site.id})" class="p-2 text-gray-400 hover:text-red-400 hover:bg-red-900/30 rounded-lg transition-colors" title="Excluir">
                                <i data-lucide="trash-2" class="w-5 h-5"></i>
                            </button>
                        </div>
                    `;
                    clonedSitesList.appendChild(card);
                });
                lucide.createIcons();
            } else {
                clonedSitesList.innerHTML = `<div class="p-8 text-center text-gray-400"><i data-lucide="ghost" class="w-12 h-12 mx-auto mb-3 opacity-20"></i><p>Sua lista está vazia.</p></div>`;
                lucide.createIcons();
            }
        } catch (error) {
            console.error('Erro ao carregar sites clonados:', error);
            clonedSitesList.innerHTML = `<div class="p-8 text-center text-red-400"><i data-lucide="alert-circle" class="w-12 h-12 mx-auto mb-3 opacity-20"></i><p>Erro ao carregar sites. Verifique se está logado.</p></div>`;
            lucide.createIcons();
        }
    }

    async function editClonedSite(id) {
        showMessage(cloneMessage, 'Carregando...', 'loading'); // Reutiliza área de msg superior
        try {
            const response = await fetch(`/api.php?action=get_cloned_site_details&cloned_site_id=${id}`);
            
            // Verificar se a resposta é JSON
            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                const text = await response.text();
                console.error('Resposta não é JSON:', text.substring(0, 200));
                showMessage(cloneMessage, 'Erro: A API retornou HTML. Verifique se está logado.', 'error');
                return;
            }
            
            if (!response.ok) {
                throw new Error(`Erro HTTP: ${response.status}`);
            }
            
            const data = await response.json();

            if (data.success) {
                currentClonedSiteId = data.details.id;
                clonedSiteTitleInput.value = data.details.title;
                clonedSiteSlugInput.value = data.details.slug || '';
                
                updateStatusUI(data.details.status || 'draft', true); // Skip animation on load

                facebookPixelIdInput.value = data.details.facebook_pixel_id || '';
                googleAnalyticsIdInput.value = data.details.google_analytics_id || '';
                customHeadScriptsInput.value = data.details.custom_head_scripts || '';

                editorSection.classList.remove('hidden');
                editorSection.scrollIntoView({ behavior: 'smooth' });
                
                // Store original content
                originalHtmlContent = data.details.edited_html;
                
                // Load editor
                initializeVisualEditor(originalHtmlContent);
                
                // Clear previous selection
                floatingEditorBox.classList.add('hidden');
                if (previousSelectedElement) previousSelectedElement.style.outline = '';
                currentSelectedElement = null;
                
                showMessage(cloneMessage, '', ''); // Limpa msg
            }
        } catch (error) {
            console.error('Erro ao carregar detalhes:', error);
            showMessage(cloneMessage, 'Erro ao carregar detalhes do site. Verifique se está logado.', 'error');
        }
    }

    async function deleteClonedSite(id) {
        if (!confirm('Tem certeza? Essa ação não pode ser desfeita.')) return;

        try {
            const response = await fetch('/api.php?action=delete_cloned_site', {
                method: 'POST',
                headers: { 
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': window.csrfToken || ''
                },
                body: JSON.stringify({ 
                    cloned_site_id: id,
                    csrf_token: window.csrfToken || ''
                })
            });
            
            // Verificar se a resposta é JSON
            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                const text = await response.text();
                console.error('Resposta não é JSON:', text.substring(0, 200));
                alert('Erro: A API retornou HTML. Verifique se está logado.');
                return;
            }
            
            if (!response.ok) {
                throw new Error(`Erro HTTP: ${response.status}`);
            }
            
            const data = await response.json();

            if (data.success) {
                loadClonedSites();
                if (currentClonedSiteId === id) {
                    currentClonedSiteId = null;
                    editorSection.classList.add('hidden');
                }
            }
        } catch (error) {
            alert('Erro ao excluir');
        }
    }

    document.addEventListener('DOMContentLoaded', loadClonedSites);
</script>