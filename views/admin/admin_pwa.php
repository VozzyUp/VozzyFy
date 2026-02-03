<?php
// Este arquivo é incluído dentro de admin.php
// Verifica se módulo PWA está instalado
$pwa_installed = file_exists(__DIR__ . '/../../pwa/pwa_config.php');

// Incluir helper de segurança para funções CSRF
require_once __DIR__ . '/../../helpers/security_helper.php';

// Gerar token CSRF para uso em requisições JavaScript
$csrf_token_js = generate_csrf_token();
?>

<meta name="csrf-token" content="<?php echo htmlspecialchars($csrf_token_js); ?>">
<script>
    // Variável global para token CSRF
    window.csrfToken = '<?php echo htmlspecialchars($csrf_token_js); ?>';
</script>

<div class="flex items-center justify-between mb-6">
    <div>
        <h1 class="text-3xl font-bold text-white">Configurações PWA</h1>
        <p class="text-gray-400 mt-1">Configure o Progressive Web App (PWA) da plataforma.</p>
    </div>
    <a href="/admin?pagina=admin_configuracoes" class="bg-dark-elevated text-gray-300 font-bold py-2 px-4 rounded-lg hover:bg-dark-card transition duration-300 flex items-center space-x-2 border border-dark-border">
        <i data-lucide="arrow-left" class="w-5 h-5"></i>
        <span>Voltar</span>
    </a>
</div>

<?php if (!$pwa_installed): ?>
<!-- Página de Vendas PWA -->
<style>
.pwa-sales-hero {
    background: linear-gradient(135deg, rgba(50, 231, 104, 0.1) 0%, rgba(50, 231, 104, 0.05) 100%);
    border: 1px solid rgba(50, 231, 104, 0.2);
    position: relative;
    overflow: hidden;
}

.pwa-sales-hero::before {
    content: '';
    position: absolute;
    top: -50%;
    right: -50%;
    width: 200%;
    height: 200%;
    background: radial-gradient(circle, rgba(50, 231, 104, 0.1) 0%, transparent 70%);
    animation: pulse 8s ease-in-out infinite;
}

@keyframes pulse {
    0%, 100% { opacity: 0.5; transform: scale(1); }
    50% { opacity: 0.8; transform: scale(1.1); }
}

.pwa-benefit-card {
    transition: all 0.3s ease;
    border: 1px solid rgba(50, 231, 104, 0.1);
}

.pwa-benefit-card:hover {
    transform: translateY(-5px);
    border-color: rgba(50, 231, 104, 0.3);
    box-shadow: 0 10px 30px rgba(50, 231, 104, 0.2);
}

.pwa-cta-button {
    background: linear-gradient(135deg, #32e768 0%, #28d158 100%);
    transition: all 0.3s ease;
    box-shadow: 0 4px 15px rgba(50, 231, 104, 0.3);
}

.pwa-cta-button:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(50, 231, 104, 0.4);
    background: linear-gradient(135deg, #28d158 0%, #32e768 100%);
}

.pwa-feature-icon {
    background: linear-gradient(135deg, rgba(50, 231, 104, 0.2) 0%, rgba(50, 231, 104, 0.1) 100%);
    border: 1px solid rgba(50, 231, 104, 0.3);
}
</style>

<!-- Hero Section -->
<div class="pwa-sales-hero rounded-2xl p-12 mb-8 text-center relative">
    <div class="relative z-10">
        <div class="inline-flex items-center justify-center w-20 h-20 rounded-full mb-6 pwa-feature-icon">
            <i data-lucide="smartphone" class="w-10 h-10" style="color: var(--accent-primary);"></i>
        </div>
        <h1 class="text-5xl font-bold text-white mb-4">
            Transforme sua Plataforma em um <span style="color: var(--accent-primary);">App Nativo</span>
        </h1>
        <p class="text-xl text-gray-300 mb-8 max-w-2xl mx-auto">
            Com o módulo PWA, seus usuários podem instalar sua plataforma diretamente no celular, 
            receber notificações push e ter uma experiência mobile de primeira classe.
        </p>
        <a href="https://meulink.lat/getfypwa" target="_blank" class="pwa-cta-button inline-flex items-center gap-3 px-8 py-4 rounded-xl text-white font-bold text-lg">
            <i data-lucide="shopping-cart" class="w-6 h-6"></i>
            <span>Comprar Módulo PWA</span>
            <i data-lucide="arrow-right" class="w-6 h-6"></i>
        </a>
    </div>
</div>

<!-- Benefícios -->
<div class="mb-8">
    <h2 class="text-3xl font-bold text-white mb-6 text-center">Por que escolher o PWA?</h2>
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        <!-- Benefício 1 -->
        <div class="pwa-benefit-card bg-dark-card p-6 rounded-xl">
            <div class="w-14 h-14 rounded-lg pwa-feature-icon flex items-center justify-center mb-4">
                <i data-lucide="download" class="w-7 h-7" style="color: var(--accent-primary);"></i>
            </div>
            <h3 class="text-xl font-bold text-white mb-2">Instalação como App</h3>
            <p class="text-gray-400">Seus usuários podem instalar sua plataforma diretamente na tela inicial do celular, sem precisar da loja de aplicativos.</p>
        </div>
        
        <!-- Benefício 2 -->
        <div class="pwa-benefit-card bg-dark-card p-6 rounded-xl">
            <div class="w-14 h-14 rounded-lg pwa-feature-icon flex items-center justify-center mb-4">
                <i data-lucide="bell" class="w-7 h-7" style="color: var(--accent-primary);"></i>
            </div>
            <h3 class="text-xl font-bold text-white mb-2">Notificações Push</h3>
            <p class="text-gray-400">Envie notificações push para seus usuários mesmo quando eles não estão usando o app, aumentando o engajamento.</p>
        </div>
        
        <!-- Benefício 3 -->
        <div class="pwa-benefit-card bg-dark-card p-6 rounded-xl">
            <div class="w-14 h-14 rounded-lg pwa-feature-icon flex items-center justify-center mb-4">
                <i data-lucide="wifi-off" class="w-7 h-7" style="color: var(--accent-primary);"></i>
            </div>
            <h3 class="text-xl font-bold text-white mb-2">Funciona Offline</h3>
            <p class="text-gray-400">Seus usuários podem acessar conteúdo mesmo sem conexão com a internet, melhorando a experiência.</p>
        </div>
        
        <!-- Benefício 4 -->
        <div class="pwa-benefit-card bg-dark-card p-6 rounded-xl">
            <div class="w-14 h-14 rounded-lg pwa-feature-icon flex items-center justify-center mb-4">
                <i data-lucide="zap" class="w-7 h-7" style="color: var(--accent-primary);"></i>
            </div>
            <h3 class="text-xl font-bold text-white mb-2">Melhor Performance</h3>
            <p class="text-gray-400">Carregamento mais rápido e experiência mais fluida, proporcionando uma navegação superior.</p>
        </div>
        
        <!-- Benefício 5 -->
        <div class="pwa-benefit-card bg-dark-card p-6 rounded-xl">
            <div class="w-14 h-14 rounded-lg pwa-feature-icon flex items-center justify-center mb-4">
                <i data-lucide="smartphone" class="w-7 h-7" style="color: var(--accent-primary);"></i>
            </div>
            <h3 class="text-xl font-bold text-white mb-2">Experiência Mobile</h3>
            <p class="text-gray-400">Interface otimizada para dispositivos móveis, com suporte completo para iOS e Android.</p>
        </div>
        
        <!-- Benefício 6 -->
        <div class="pwa-benefit-card bg-dark-card p-6 rounded-xl">
            <div class="w-14 h-14 rounded-lg pwa-feature-icon flex items-center justify-center mb-4">
                <i data-lucide="home" class="w-7 h-7" style="color: var(--accent-primary);"></i>
            </div>
            <h3 class="text-xl font-bold text-white mb-2">Acesso Rápido</h3>
            <p class="text-gray-400">Acesso direto da tela inicial, sem precisar abrir o navegador, como um app nativo.</p>
        </div>
    </div>
</div>

<!-- Features/Diferenciais -->
<div class="bg-dark-card p-8 rounded-xl mb-8 border border-dark-border">
    <h2 class="text-3xl font-bold text-white mb-6 text-center">O que está incluído?</h2>
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 max-w-4xl mx-auto">
        <div class="flex items-start gap-4">
            <div class="w-10 h-10 rounded-lg pwa-feature-icon flex items-center justify-center flex-shrink-0">
                <i data-lucide="check" class="w-5 h-5" style="color: var(--accent-primary);"></i>
            </div>
            <div>
                <h4 class="text-lg font-semibold text-white mb-1">Instalação PWA Completa</h4>
                <p class="text-gray-400 text-sm">Sistema completo de instalação com manifest e service worker configurados.</p>
            </div>
        </div>
        
        <div class="flex items-start gap-4">
            <div class="w-10 h-10 rounded-lg pwa-feature-icon flex items-center justify-center flex-shrink-0">
                <i data-lucide="check" class="w-5 h-5" style="color: var(--accent-primary);"></i>
            </div>
            <div>
                <h4 class="text-lg font-semibold text-white mb-1">Notificações Push</h4>
                <p class="text-gray-400 text-sm">Sistema completo de notificações push para iOS e Android.</p>
            </div>
        </div>
        
        <div class="flex items-start gap-4">
            <div class="w-10 h-10 rounded-lg pwa-feature-icon flex items-center justify-center flex-shrink-0">
                <i data-lucide="check" class="w-5 h-5" style="color: var(--accent-primary);"></i>
            </div>
            <div>
                <h4 class="text-lg font-semibold text-white mb-1">Painel de Configuração</h4>
                <p class="text-gray-400 text-sm">Interface administrativa completa para gerenciar todas as configurações.</p>
            </div>
        </div>
        
        <div class="flex items-start gap-4">
            <div class="w-10 h-10 rounded-lg pwa-feature-icon flex items-center justify-center flex-shrink-0">
                <i data-lucide="check" class="w-5 h-5" style="color: var(--accent-primary);"></i>
            </div>
            <div>
                <h4 class="text-lg font-semibold text-white mb-1">Suporte iOS e Android</h4>
                <p class="text-gray-400 text-sm">Funciona perfeitamente em dispositivos iOS e Android.</p>
            </div>
        </div>
        
        <div class="flex items-start gap-4">
            <div class="w-10 h-10 rounded-lg pwa-feature-icon flex items-center justify-center flex-shrink-0">
                <i data-lucide="check" class="w-5 h-5" style="color: var(--accent-primary);"></i>
            </div>
            <div>
                <h4 class="text-lg font-semibold text-white mb-1">Cache e Offline</h4>
                <p class="text-gray-400 text-sm">Sistema de cache inteligente para funcionamento offline.</p>
            </div>
        </div>
        
        <div class="flex items-start gap-4">
            <div class="w-10 h-10 rounded-lg pwa-feature-icon flex items-center justify-center flex-shrink-0">
                <i data-lucide="check" class="w-5 h-5" style="color: var(--accent-primary);"></i>
            </div>
            <div>
                <h4 class="text-lg font-semibold text-white mb-1">Atualizações Automáticas</h4>
                <p class="text-gray-400 text-sm">O app se atualiza automaticamente quando há novas versões.</p>
            </div>
        </div>
    </div>
</div>

<!-- CTA Final -->
<div class="bg-gradient-to-r from-green-600/20 to-green-500/20 border border-green-500/30 rounded-2xl p-12 text-center">
    <h2 class="text-4xl font-bold text-white mb-4">Pronto para transformar sua plataforma?</h2>
    <p class="text-xl text-gray-300 mb-8 max-w-2xl mx-auto">
        Adquira o módulo PWA agora e ofereça a melhor experiência mobile para seus usuários.
    </p>
    <a href="https://meulink.lat/getfypwa" target="_blank" class="pwa-cta-button inline-flex items-center gap-3 px-10 py-5 rounded-xl text-white font-bold text-xl">
        <i data-lucide="shopping-cart" class="w-7 h-7"></i>
        <span>Comprar Agora</span>
        <i data-lucide="arrow-right" class="w-7 h-7"></i>
    </a>
    <p class="text-sm text-gray-400 mt-6">
        <i data-lucide="shield-check" class="w-4 h-4 inline mr-1"></i>
        Compra segura • Suporte completo • Atualizações incluídas
    </p>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    lucide.createIcons();
});
</script>

<?php else: ?>

<div id="status-message" class="hidden px-4 py-3 rounded relative mb-4" role="alert"></div>

<!-- Sistema de Abas -->
<div class="mb-6">
    <!-- Header das Abas -->
    <div class="flex border-b border-dark-border mb-6">
        <button class="tab-button active px-6 py-3 text-sm font-semibold transition-all duration-300 border-b-2" 
                data-tab="general" 
                style="border-bottom-color: var(--accent-primary); color: var(--accent-primary);">
            <i data-lucide="settings" class="w-4 h-4 inline mr-2"></i>
            Configuração Geral
        </button>
        <button class="tab-button px-6 py-3 text-sm font-semibold transition-all duration-300 border-b-2 text-gray-400 hover:text-gray-300" 
                data-tab="push" 
                style="border-bottom-color: transparent;">
            <i data-lucide="bell" class="w-4 h-4 inline mr-2"></i>
            Notificações Push
        </button>
    </div>
    
    <!-- Conteúdo das Abas -->
    <div class="tabs-content">
        <!-- Aba: Configuração Geral -->
        <div id="tab-general" class="tab-panel active">
            
<!-- Seção: Informações Básicas -->
<div class="bg-dark-card p-8 rounded-lg shadow-md mb-6" style="border-color: var(--accent-primary);">
    <h2 class="text-2xl font-semibold mb-6 text-white flex items-center gap-2">
        <i data-lucide="info" class="w-6 h-6" style="color: var(--accent-primary);"></i>
        <span>Informações Básicas</span>
    </h2>
    
    <div class="space-y-4">
        <div>
            <label for="pwa_app_name" class="block text-gray-300 text-sm font-semibold mb-2">Nome do App</label>
            <input type="text" id="pwa_app_name" name="pwa_app_name" 
                   class="w-full px-4 py-3 bg-dark-elevated border border-dark-border rounded-lg text-white focus:outline-none focus:ring-2 transition duration-300"
                   placeholder="Ex: Minha Plataforma" maxlength="255">
            <p class="text-xs text-gray-400 mt-1">Nome completo que aparecerá na tela inicial do dispositivo.</p>
        </div>
        
        <div>
            <label for="pwa_short_name" class="block text-gray-300 text-sm font-semibold mb-2">Nome Curto</label>
            <input type="text" id="pwa_short_name" name="pwa_short_name" 
                   class="w-full px-4 py-3 bg-dark-elevated border border-dark-border rounded-lg text-white focus:outline-none focus:ring-2 transition duration-300"
                   placeholder="Ex: App" maxlength="50">
            <p class="text-xs text-gray-400 mt-1">Nome curto que aparecerá abaixo do ícone (máximo 12 caracteres recomendado).</p>
        </div>
        
        <div>
            <label for="pwa_description" class="block text-gray-300 text-sm font-semibold mb-2">Descrição</label>
            <textarea id="pwa_description" name="pwa_description" rows="3"
                      class="w-full px-4 py-3 bg-dark-elevated border border-dark-border rounded-lg text-white focus:outline-none focus:ring-2 transition duration-300"
                      placeholder="Descrição do aplicativo que aparecerá nas lojas de aplicativos"></textarea>
            <p class="text-xs text-gray-400 mt-1">Descrição do aplicativo (opcional).</p>
        </div>
    </div>
</div>

<!-- Seção: Ícone do App -->
<div class="bg-dark-card p-8 rounded-lg shadow-md mb-6 border border-dark-border">
    <h2 class="text-2xl font-semibold mb-6 text-white flex items-center gap-2">
        <i data-lucide="image" class="w-6 h-6" style="color: var(--accent-primary);"></i>
        <span>Ícone do App</span>
    </h2>
    <p class="text-gray-400 mb-6">Faça upload do ícone que aparecerá na tela inicial do dispositivo. Recomendado: 512x512px, formato PNG.</p>
    
    <div class="flex flex-col md:flex-row items-start md:items-center gap-6">
        <div class="flex-1">
            <label for="pwa_icon_file" class="block text-gray-300 text-sm font-semibold mb-2">Upload do Ícone</label>
            <div class="relative">
                <input type="file" id="pwa_icon_file" name="pwa_icon_file" accept="image/png,image/jpeg,image/jpg,image/webp" 
                       class="hidden">
                <label for="pwa_icon_file" class="cursor-pointer inline-flex items-center justify-center px-6 py-3 bg-dark-elevated border border-dark-border rounded-lg text-white hover:bg-dark-card transition">
                    <i data-lucide="upload" class="w-5 h-5 mr-2"></i>
                    <span>Selecionar Arquivo</span>
                </label>
                <span id="pwa_icon_filename" class="ml-4 text-gray-400 text-sm"></span>
            </div>
            <p class="text-xs text-gray-400 mt-2">Formatos aceitos: PNG, JPG, WEBP. Tamanho máximo: 2MB</p>
        </div>
        <div class="flex flex-col gap-2">
            <div class="w-32 h-32 rounded-lg border-2 border-dark-border bg-dark-elevated flex items-center justify-center overflow-hidden" id="pwa-icon-preview">
                <img id="pwa-icon-preview-img" src="" alt="Ícone Preview" class="max-w-full max-h-full object-contain hidden">
                <i data-lucide="image" class="w-12 h-12 text-gray-500" id="pwa-icon-placeholder"></i>
            </div>
            <button type="button" id="upload-pwa-icon-btn" class="text-white font-bold py-2 px-4 rounded-lg transition duration-300 disabled:opacity-50 disabled:cursor-not-allowed" style="background-color: var(--accent-primary);" onmouseover="if(!this.disabled) this.style.backgroundColor='var(--accent-primary-hover)'" onmouseout="if(!this.disabled) this.style.backgroundColor='var(--accent-primary)'" disabled>
                Enviar Ícone
            </button>
        </div>
    </div>
</div>

<!-- Seção: Cores e Aparência -->
<div class="bg-dark-card p-8 rounded-lg shadow-md mb-6" style="border-color: var(--accent-primary);">
    <h2 class="text-2xl font-semibold mb-6 text-white flex items-center gap-2">
        <i data-lucide="palette" class="w-6 h-6" style="color: var(--accent-primary);"></i>
        <span>Cores e Aparência</span>
    </h2>
    
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div>
            <label for="pwa_theme_color" class="block text-gray-300 text-sm font-semibold mb-2">Cor do Tema</label>
            <div class="flex items-center gap-4">
                <input type="color" id="pwa_theme_color_picker" 
                       class="w-20 h-20 rounded-lg cursor-pointer border-2 border-dark-border bg-transparent">
                <input type="text" id="pwa_theme_color" 
                       class="flex-1 px-4 py-3 bg-dark-elevated border border-dark-border rounded-lg text-white font-mono text-sm focus:outline-none focus:ring-2 transition duration-300"
                       placeholder="#32e768" maxlength="7">
            </div>
            <p class="text-xs text-gray-400 mt-2">Cor que será usada na barra de status e elementos do sistema. Por padrão, usa a cor primária da plataforma.</p>
        </div>
        
        <div>
            <label for="pwa_background_color" class="block text-gray-300 text-sm font-semibold mb-2">Cor de Fundo</label>
            <div class="flex items-center gap-4">
                <input type="color" id="pwa_background_color_picker" 
                       class="w-20 h-20 rounded-lg cursor-pointer border-2 border-dark-border bg-transparent">
                <input type="text" id="pwa_background_color" 
                       class="flex-1 px-4 py-3 bg-dark-elevated border border-dark-border rounded-lg text-white font-mono text-sm focus:outline-none focus:ring-2 transition duration-300"
                       placeholder="#ffffff" maxlength="7">
            </div>
            <p class="text-xs text-gray-400 mt-2">Cor de fundo da tela de splash (tela de carregamento).</p>
        </div>
    </div>
    
    <div class="mt-6">
        <label for="pwa_display_mode" class="block text-gray-300 text-sm font-semibold mb-2">Modo de Exibição</label>
        <select id="pwa_display_mode" 
                class="w-full px-4 py-3 bg-dark-elevated border border-dark-border rounded-lg text-white focus:outline-none focus:ring-2 transition duration-300">
            <option value="standalone">Standalone (Recomendado)</option>
            <option value="fullscreen">Fullscreen</option>
            <option value="minimal-ui">Minimal UI</option>
            <option value="browser">Browser</option>
        </select>
        <p class="text-xs text-gray-400 mt-2">Como o app será exibido quando instalado. Standalone remove a barra de endereço do navegador.</p>
    </div>
</div>

<!-- Seção: URLs -->
<div class="bg-dark-card p-8 rounded-lg shadow-md mb-6 border border-dark-border">
    <h2 class="text-2xl font-semibold mb-6 text-white flex items-center gap-2">
        <i data-lucide="link" class="w-6 h-6" style="color: var(--accent-primary);"></i>
        <span>URLs</span>
    </h2>
    
    <div class="space-y-4">
        <div>
            <label for="pwa_start_url" class="block text-gray-300 text-sm font-semibold mb-2">URL Inicial</label>
            <input type="text" id="pwa_start_url" name="pwa_start_url" 
                   class="w-full px-4 py-3 bg-dark-elevated border border-dark-border rounded-lg text-white focus:outline-none focus:ring-2 transition duration-300"
                   placeholder="/" value="/">
            <p class="text-xs text-gray-400 mt-1">Página que será aberta quando o app for iniciado.</p>
        </div>
        
        <div>
            <label for="pwa_scope" class="block text-gray-300 text-sm font-semibold mb-2">Escopo</label>
            <input type="text" id="pwa_scope" name="pwa_scope" 
                   class="w-full px-4 py-3 bg-dark-elevated border border-dark-border rounded-lg text-white focus:outline-none focus:ring-2 transition duration-300"
                   placeholder="/" value="/">
            <p class="text-xs text-gray-400 mt-1">Escopo de navegação do PWA (geralmente "/").</p>
        </div>
    </div>
</div>

        </div>
        
        <!-- Aba: Notificações Push -->
        <div id="tab-push" class="tab-panel hidden">
            
<!-- Seção: Notificações Push -->
<div class="bg-dark-card p-8 rounded-lg shadow-md mb-6 border border-dark-border">
    <h2 class="text-2xl font-semibold mb-6 text-white flex items-center gap-2">
        <i data-lucide="bell" class="w-6 h-6" style="color: var(--accent-primary);"></i>
        <span>Notificações Push</span>
    </h2>
    
    <div class="flex items-start gap-3 mb-6">
        <input type="checkbox" id="pwa_push_enabled" name="pwa_push_enabled" 
               class="mt-1 w-5 h-5 rounded border-dark-border bg-dark-elevated text-primary focus:ring-2 focus:ring-primary cursor-pointer">
        <div>
            <label for="pwa_push_enabled" class="text-white font-medium cursor-pointer">Ativar Notificações Push</label>
            <p class="text-sm text-gray-400 mt-1">Permite que o app envie notificações push para os usuários instalados.</p>
        </div>
    </div>
    
    <!-- Submenu de Notificações Push -->
    <div id="push-notifications-section" class="hidden">
        <!-- Status VAPID Keys -->
        <div class="mb-6 p-4 bg-dark-elevated rounded-lg border border-dark-border">
            <div class="flex items-center justify-between mb-2">
                <h3 class="text-lg font-semibold text-white">Chaves VAPID</h3>
                <div class="flex gap-2">
                    <button type="button" id="generate-vapid-keys-btn" class="text-sm px-3 py-1 bg-dark-card border border-dark-border rounded text-gray-300 hover:text-white transition">
                        Gerar Chaves
                    </button>
                    <a href="/pwa/generate_vapid_keys.php" target="_blank" class="text-sm px-3 py-1 bg-blue-600 hover:bg-blue-700 text-white rounded transition">
                        Script Alternativo
                    </a>
                </div>
            </div>
            <div id="vapid-status" class="text-sm text-gray-400">
                <span class="inline-flex items-center gap-2">
                    <i data-lucide="loader-2" class="w-4 h-4 animate-spin"></i>
                    Verificando...
                </span>
            </div>
        </div>
        
        <!-- Estatísticas -->
        <div class="mb-6">
            <div class="p-4 bg-dark-elevated rounded-lg border border-dark-border">
                <div class="text-sm text-gray-400 mb-1">Usuários Inscritos</div>
                <div id="subscriptions-count" class="text-2xl font-bold text-white">-</div>
            </div>
        </div>
        
        <!-- Formulário de Envio -->
        <div class="mb-6 p-4 bg-dark-elevated rounded-lg border border-dark-border">
            <h3 class="text-lg font-semibold text-white mb-4">Enviar Notificação Push</h3>
            <form id="send-push-form" class="space-y-4">
                <div>
                    <label for="push-title" class="block text-gray-300 text-sm font-semibold mb-2">Título *</label>
                    <input type="text" id="push-title" required
                           class="w-full px-4 py-3 bg-dark-card border border-dark-border rounded-lg text-white focus:outline-none focus:ring-2 transition duration-300"
                           placeholder="Ex: Nova atualização disponível">
                </div>
                <div>
                    <label for="push-message" class="block text-gray-300 text-sm font-semibold mb-2">Mensagem *</label>
                    <textarea id="push-message" required rows="3"
                              class="w-full px-4 py-3 bg-dark-card border border-dark-border rounded-lg text-white focus:outline-none focus:ring-2 transition duration-300"
                              placeholder="Digite a mensagem da notificação"></textarea>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label for="push-url" class="block text-gray-300 text-sm font-semibold mb-2">URL (opcional)</label>
                        <input type="url" id="push-url"
                               class="w-full px-4 py-3 bg-dark-card border border-dark-border rounded-lg text-white focus:outline-none focus:ring-2 transition duration-300"
                               placeholder="/index?pagina=dashboard">
                    </div>
                    <div>
                        <label for="push-icon" class="block text-gray-300 text-sm font-semibold mb-2">Ícone (opcional)</label>
                        <input type="url" id="push-icon"
                               class="w-full px-4 py-3 bg-dark-card border border-dark-border rounded-lg text-white focus:outline-none focus:ring-2 transition duration-300"
                               placeholder="/assets/pix.svg">
                    </div>
                </div>
                <button type="submit" id="send-push-btn" class="w-full text-white font-bold py-3 px-6 rounded-lg transition-all duration-300 flex items-center justify-center gap-2" style="background-color: var(--accent-primary);" onmouseover="this.style.backgroundColor='var(--accent-primary-hover)'" onmouseout="this.style.backgroundColor='var(--accent-primary)'">
                    <i data-lucide="send" class="w-5 h-5"></i>
                    <span>Enviar Notificação</span>
                </button>
            </form>
        </div>
    </div>
</div>
            
        </div>
    </div>
</div>

<!-- Botão Salvar (apenas na aba de Configuração Geral) -->
<div id="save-button-container" class="bg-dark-card p-8 rounded-lg shadow-md border border-dark-border">
    <button type="button" id="save-pwa-config-btn" class="w-full text-white font-bold py-4 px-6 rounded-lg transition-all duration-300 flex items-center justify-center gap-2" style="background-color: var(--accent-primary);" onmouseover="this.style.backgroundColor='var(--accent-primary-hover)'" onmouseout="this.style.backgroundColor='var(--accent-primary)'">
        <i data-lucide="save" class="w-5 h-5"></i>
        <span>Salvar Configurações PWA</span>
    </button>
</div>

<style>
/* Estilos para as Abas */
.tab-button {
    position: relative;
    background: transparent;
    border: none;
    cursor: pointer;
    outline: none;
}

.tab-button.active {
    color: var(--accent-primary);
    border-bottom-color: var(--accent-primary) !important;
}

.tab-button:not(.active) {
    color: #9ca3af;
    border-bottom-color: transparent;
}

.tab-button:not(.active):hover {
    color: #d1d5db;
}

.tab-panel {
    display: none;
}

.tab-panel.active {
    display: block;
    animation: fadeIn 0.3s ease-in;
}

@keyframes fadeIn {
    from {
        opacity: 0;
        transform: translateY(10px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    lucide.createIcons();
    
    // ==========================================================
    // SISTEMA DE ABAS
    // ==========================================================
    
    const tabButtons = document.querySelectorAll('.tab-button');
    const tabPanels = document.querySelectorAll('.tab-panel');
    const saveButtonContainer = document.getElementById('save-button-container');
    
    // Função para alternar abas
    function switchTab(tabName) {
        // Remove active de todos os botões e painéis
        tabButtons.forEach(btn => {
            btn.classList.remove('active');
            btn.style.borderBottomColor = 'transparent';
            btn.style.color = '#9ca3af';
        });
        
        tabPanels.forEach(panel => {
            panel.classList.remove('active');
            panel.classList.add('hidden');
        });
        
        // Adiciona active ao botão e painel selecionado
        const activeButton = document.querySelector(`[data-tab="${tabName}"]`);
        const activePanel = document.getElementById(`tab-${tabName}`);
        
        if (activeButton && activePanel) {
            activeButton.classList.add('active');
            activeButton.style.borderBottomColor = 'var(--accent-primary)';
            activeButton.style.color = 'var(--accent-primary)';
            
            activePanel.classList.add('active');
            activePanel.classList.remove('hidden');
            
            // Mostra/oculta botão salvar apenas na aba de configuração geral
            if (saveButtonContainer) {
                if (tabName === 'general') {
                    saveButtonContainer.style.display = 'block';
                } else {
                    saveButtonContainer.style.display = 'none';
                }
            }
            
            // Recria ícones após mudança de aba
            lucide.createIcons();
        }
        
        // Salva aba ativa no localStorage
        localStorage.setItem('pwa_admin_active_tab', tabName);
    }
    
    // Adiciona event listeners aos botões de aba
    tabButtons.forEach(button => {
        button.addEventListener('click', function() {
            const tabName = this.getAttribute('data-tab');
            switchTab(tabName);
        });
    });
    
    // Restaura aba ativa do localStorage (se existir)
    const savedTab = localStorage.getItem('pwa_admin_active_tab');
    if (savedTab && (savedTab === 'general' || savedTab === 'push')) {
        switchTab(savedTab);
    } else {
        // Por padrão, mostra a aba de configuração geral
        switchTab('general');
    }
    
    const statusMessage = document.getElementById('status-message');
    const saveBtn = document.getElementById('save-pwa-config-btn');
    const iconFile = document.getElementById('pwa_icon_file');
    const iconFilename = document.getElementById('pwa_icon_filename');
    const iconPreview = document.getElementById('pwa-icon-preview-img');
    const iconPlaceholder = document.getElementById('pwa-icon-placeholder');
    const uploadIconBtn = document.getElementById('upload-pwa-icon-btn');
    
    // Sincronizar color pickers
    const syncColorPickers = (pickerId, inputId) => {
        const picker = document.getElementById(pickerId);
        const input = document.getElementById(inputId);
        if(picker && input) {
            picker.addEventListener('input', (e) => { input.value = e.target.value; });
            input.addEventListener('input', (e) => {
                let hex = e.target.value.trim();
                if (hex && hex[0] !== '#') hex = '#' + hex;
                if (/^#[0-9A-Fa-f]{6}$/.test(hex)) {
                    picker.value = hex;
                }
            });
        }
    };
    
    syncColorPickers('pwa_theme_color_picker', 'pwa_theme_color');
    syncColorPickers('pwa_background_color_picker', 'pwa_background_color');
    
    function showMessage(message, type = 'success') {
        statusMessage.classList.remove('hidden');
        statusMessage.className = 'px-4 py-3 rounded relative mb-4';
        if (type === 'success') {
            statusMessage.classList.add('bg-green-900/20', 'border', 'border-green-500/30', 'text-green-300');
        } else {
            statusMessage.classList.add('bg-red-900/20', 'border', 'border-red-500/30', 'text-red-300');
        }
        statusMessage.textContent = message;
        statusMessage.scrollIntoView({ behavior: 'smooth', block: 'start' });
        setTimeout(() => {
            statusMessage.classList.add('hidden');
        }, 5000);
    }
    
    // Carregar configurações
    async function loadPWAConfig() {
        try {
            const response = await fetch('/api/admin_api.php?action=get_pwa_config');
            const result = await response.json();
            
            if (result.success && result.data) {
                const config = result.data;
                
                document.getElementById('pwa_app_name').value = config.app_name || 'Plataforma';
                document.getElementById('pwa_short_name').value = config.short_name || 'App';
                document.getElementById('pwa_description').value = config.description || '';
                document.getElementById('pwa_theme_color').value = config.theme_color || '#32e768';
                document.getElementById('pwa_theme_color_picker').value = config.theme_color || '#32e768';
                document.getElementById('pwa_background_color').value = config.background_color || '#ffffff';
                document.getElementById('pwa_background_color_picker').value = config.background_color || '#ffffff';
                document.getElementById('pwa_display_mode').value = config.display_mode || 'standalone';
                document.getElementById('pwa_start_url').value = config.start_url || '/';
                document.getElementById('pwa_scope').value = config.scope || '/';
                document.getElementById('pwa_push_enabled').checked = config.push_enabled == 1;
                
                // Preview do ícone
                if (config.icon_path) {
                    iconPreview.src = '/' + config.icon_path;
                    iconPreview.classList.remove('hidden');
                    iconPlaceholder.classList.add('hidden');
                }
                
                // Mostrar seção de push se estiver habilitado
                if (config.push_enabled == 1 && typeof togglePushSection === 'function') {
                    togglePushSection();
                }
            }
        } catch (error) {
            console.error('Erro ao carregar configurações PWA:', error);
        }
    }
    
    // Preview ícone
    iconFile.addEventListener('change', function() {
        if (this.files && this.files[0]) {
            iconFilename.textContent = this.files[0].name;
            uploadIconBtn.disabled = false;
            
            const reader = new FileReader();
            reader.onload = function(e) {
                iconPreview.src = e.target.result;
                iconPreview.classList.remove('hidden');
                iconPlaceholder.classList.add('hidden');
            };
            reader.readAsDataURL(this.files[0]);
        }
    });
    
    // Upload ícone
    uploadIconBtn.addEventListener('click', async function() {
        if (!iconFile.files || !iconFile.files[0]) {
            showMessage('Selecione um arquivo primeiro', 'error');
            return;
        }
        
        const formData = new FormData();
        formData.append('icon', iconFile.files[0]);
        formData.append('csrf_token', window.csrfToken || '');
        
        uploadIconBtn.disabled = true;
        uploadIconBtn.textContent = 'Enviando...';
        
        try {
            const response = await fetch('/api/admin_api.php?action=upload_pwa_icon', {
                method: 'POST',
                headers: {
                    'X-CSRF-Token': window.csrfToken || ''
                },
                body: formData
            });
            const result = await response.json();
            
            if (result.success) {
                showMessage('Ícone enviado com sucesso!', 'success');
                iconFile.value = '';
                iconFilename.textContent = '';
                uploadIconBtn.disabled = true;
                if (result.icon_url) {
                    iconPreview.src = result.icon_url;
                }
            } else {
                showMessage('Erro ao enviar ícone: ' + (result.error || 'Erro desconhecido'), 'error');
                uploadIconBtn.disabled = false;
            }
        } catch (error) {
            console.error('Erro:', error);
            showMessage('Erro de comunicação com o servidor', 'error');
            uploadIconBtn.disabled = false;
        } finally {
            uploadIconBtn.textContent = 'Enviar Ícone';
        }
    });
    
    // Salvar configurações
    saveBtn.addEventListener('click', async function() {
        const config = {
            app_name: document.getElementById('pwa_app_name').value || 'Plataforma',
            short_name: document.getElementById('pwa_short_name').value || 'App',
            description: document.getElementById('pwa_description').value,
            theme_color: document.getElementById('pwa_theme_color').value || '#32e768',
            background_color: document.getElementById('pwa_background_color').value || '#ffffff',
            display_mode: document.getElementById('pwa_display_mode').value || 'standalone',
            start_url: document.getElementById('pwa_start_url').value || '/',
            scope: document.getElementById('pwa_scope').value || '/',
            push_enabled: document.getElementById('pwa_push_enabled').checked ? 1 : 0
        };
        
        // Mantém icon_path se já existir (não sobrescreve)
        try {
            const response = await fetch('/api/admin_api.php?action=get_pwa_config');
            const result = await response.json();
            if (result.success && result.data && result.data.icon_path) {
                config.icon_path = result.data.icon_path;
            }
        } catch (e) {
            // Ignora erro
        }
        
        saveBtn.disabled = true;
        saveBtn.innerHTML = '<i data-lucide="loader-2" class="w-5 h-5 animate-spin"></i><span>Salvando...</span>';
        lucide.createIcons();
        
        try {
            // Adicionar token CSRF ao config
            config.csrf_token = window.csrfToken || '';
            
            const response = await fetch('/api/admin_api.php?action=save_pwa_config', {
                method: 'POST',
                headers: { 
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': window.csrfToken || ''
                },
                body: JSON.stringify(config)
            });
            const result = await response.json();
            
            if (result.success) {
                showMessage('Configurações PWA salvas com sucesso!', 'success');
                // Atualizar visibilidade da seção de push
                if (typeof togglePushSection === 'function') {
                    togglePushSection();
                }
            } else {
                showMessage('Erro ao salvar: ' + (result.error || 'Erro desconhecido'), 'error');
            }
        } catch (error) {
            console.error('Erro:', error);
            showMessage('Erro de comunicação com o servidor', 'error');
        } finally {
            saveBtn.disabled = false;
            saveBtn.innerHTML = '<i data-lucide="save" class="w-5 h-5"></i><span>Salvar Configurações PWA</span>';
            lucide.createIcons();
        }
    });
    
    // ==========================================================
    // NOTIFICAÇÕES PUSH
    // ==========================================================
    
    const pushSection = document.getElementById('push-notifications-section');
    const pushEnabledCheckbox = document.getElementById('pwa_push_enabled');
    const generateVapidBtn = document.getElementById('generate-vapid-keys-btn');
    const vapidStatus = document.getElementById('vapid-status');
    const subscriptionsCount = document.getElementById('subscriptions-count');
    const sendPushForm = document.getElementById('send-push-form');
    const sendPushBtn = document.getElementById('send-push-btn');
    
    // Mostrar/ocultar seção de push baseado no checkbox
    function togglePushSection() {
        if (pushEnabledCheckbox && pushSection) {
            if (pushEnabledCheckbox.checked) {
                pushSection.classList.remove('hidden');
                loadPushData();
            } else {
                pushSection.classList.add('hidden');
            }
        }
    }
    
    if (pushEnabledCheckbox) {
        pushEnabledCheckbox.addEventListener('change', togglePushSection);
    }
    
    // Função auxiliar para escapar HTML
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    // Função auxiliar para fazer requisições com tratamento de erros melhorado
    async function fetchJSON(url, options = {}) {
        try {
            const response = await fetch(url, options);
            
            // Tenta ler o texto primeiro
            const text = await response.text();
            
            // Se não houver texto, retorna erro
            if (!text || text.trim() === '') {
                throw new Error(`Resposta vazia do servidor (HTTP ${response.status})`);
            }
            
            // Tenta fazer parse do JSON
            let result;
            try {
                result = JSON.parse(text);
            } catch (parseError) {
                console.error('Erro ao fazer parse do JSON:', parseError);
                console.error('Resposta do servidor:', text.substring(0, 500));
                throw new Error(`Resposta inválida do servidor: ${text.substring(0, 100)}...`);
            }
            
            // Se a resposta não foi OK, mas temos JSON, retorna o JSON com erro
            if (!response.ok && result.error) {
                throw new Error(result.error);
            }
            
            // Se a resposta não foi OK e não temos JSON válido, lança erro genérico
            if (!response.ok) {
                throw new Error(`Erro HTTP ${response.status}: ${response.statusText}`);
            }
            
            return result;
        } catch (error) {
            console.error('Erro na requisição:', error);
            throw error;
        }
    }
    
    // Carregar configurações ao iniciar (depois de definir togglePushSection)
    loadPWAConfig();
    
    // Carregar dados de push
    async function loadPushData() {
        // Verificar status VAPID
        try {
            const result = await fetchJSON('/api/admin_api.php?action=get_vapid_keys');
            
            if (result.success && result.publicKey) {
                const publicKeyShort = result.publicKey.substring(0, 20) + '...';
                vapidStatus.innerHTML = `
                    <span class="text-green-400">
                        <i data-lucide="check-circle" class="w-4 h-4 inline"></i>
                        Chaves configuradas (${publicKeyShort})
                    </span>
                `;
                lucide.createIcons();
            } else {
                const errorMsg = result.error || 'Chaves não configuradas';
                vapidStatus.innerHTML = `
                    <span class="text-yellow-400">
                        <i data-lucide="alert-circle" class="w-4 h-4 inline"></i>
                        ${escapeHtml(errorMsg)}
                    </span>
                `;
                lucide.createIcons();
            }
        } catch (error) {
            console.error('Erro ao verificar chaves VAPID:', error);
            vapidStatus.innerHTML = `
                <span class="text-red-400">
                    <i data-lucide="x-circle" class="w-4 h-4 inline"></i>
                    Erro ao verificar chaves: ${escapeHtml(error.message)}
                </span>
            `;
            lucide.createIcons();
        }
        
        // Carregar contagem de subscriptions
        try {
            const result = await fetchJSON('/api/admin_api.php?action=get_push_subscriptions');
            
            if (result.success) {
                subscriptionsCount.textContent = result.count || 0;
            } else {
                subscriptionsCount.textContent = 'Erro';
                console.error('Erro ao carregar subscriptions:', result.error);
            }
        } catch (error) {
            console.error('Erro ao carregar subscriptions:', error);
            subscriptionsCount.textContent = 'Erro';
        }
    }
    
    // Gerar chaves VAPID
    if (generateVapidBtn) {
        generateVapidBtn.addEventListener('click', async function() {
            generateVapidBtn.disabled = true;
            generateVapidBtn.textContent = 'Gerando...';
            
            try {
                const result = await fetchJSON('/api/admin_api.php?action=get_vapid_keys');
                
                if (result.success) {
                    showMessage('Chaves VAPID geradas com sucesso!', 'success');
                    loadPushData();
                } else {
                    let errorMsg = result.error || 'Erro desconhecido';
                    // Se o erro for relacionado a OpenSSL/EC, adiciona instruções
                    if (errorMsg.includes('Unable to create') || errorMsg.includes('curvas elípticas') || errorMsg.includes('EC')) {
                        errorMsg += '<br><br><strong>Alternativas:</strong><br>';
                        errorMsg += '1. <a href="/pwa/generate_vapid_keys.php" target="_blank" class="text-blue-400 underline">Gerar via script PHP</a><br>';
                        errorMsg += '2. Execute: <code>node pwa/generate_vapid-keys.js</code> (requer Node.js)<br>';
                        errorMsg += '3. Use ferramenta online: <a href="https://web-push-codelab.glitch.me/" target="_blank" class="text-blue-400 underline">web-push-codelab.glitch.me</a>';
                    }
                    showMessage(errorMsg, 'error', 10000); // Mostra por 10 segundos
                }
            } catch (error) {
                console.error('Erro ao gerar chaves VAPID:', error);
                showMessage('Erro de comunicação: ' + escapeHtml(error.message), 'error');
            } finally {
                generateVapidBtn.disabled = false;
                generateVapidBtn.textContent = 'Gerar Chaves';
            }
        });
    }
    
    // Enviar notificação push
    if (sendPushForm) {
        sendPushForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const title = document.getElementById('push-title').value.trim();
            const message = document.getElementById('push-message').value.trim();
            const url = document.getElementById('push-url').value.trim();
            const icon = document.getElementById('push-icon').value.trim();
            
            if (!title || !message) {
                showMessage('Preencha título e mensagem', 'error');
                return;
            }
            
            sendPushBtn.disabled = true;
            sendPushBtn.innerHTML = '<i data-lucide="loader-2" class="w-5 h-5 animate-spin"></i><span>Enviando...</span>';
            lucide.createIcons();
            
            try {
                const result = await fetchJSON('/api/admin_api.php?action=send_push_notification', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ title, message, url, icon })
                });
                
                if (result.success) {
                    showMessage(result.message || 'Notificação enviada com sucesso!', 'success');
                    sendPushForm.reset();
                    loadPushData();
                } else {
                    showMessage('Erro ao enviar: ' + (result.error || 'Erro desconhecido'), 'error');
                }
            } catch (error) {
                console.error('Erro ao enviar notificação:', error);
                showMessage('Erro de comunicação: ' + escapeHtml(error.message), 'error');
            } finally {
                sendPushBtn.disabled = false;
                sendPushBtn.innerHTML = '<i data-lucide="send" class="w-5 h-5"></i><span>Enviar Notificação</span>';
                lucide.createIcons();
            }
        });
    }
});
</script>

<?php endif; ?>

