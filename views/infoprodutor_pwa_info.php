<?php
// Página de informações sobre o módulo PWA
// Exibida quando o módulo não está instalado
?>

<div class="container mx-auto">
    <div class="mb-6">
        <h1 class="text-3xl font-bold text-white mb-2">Progressive Web App (PWA)</h1>
        <p class="text-gray-400">Transforme sua plataforma em um aplicativo instalável para Android e iPhone</p>
    </div>

    <!-- Hero Section -->
    <div class="bg-gradient-to-br from-blue-900/30 via-purple-900/20 to-indigo-900/30 rounded-lg p-8 mb-6 border" style="border-color: var(--accent-primary);">
        <div class="flex flex-col md:flex-row items-center gap-6">
            <div class="flex-shrink-0">
                <div class="w-24 h-24 rounded-full bg-primary/20 flex items-center justify-center" style="background-color: color-mix(in srgb, var(--accent-primary) 20%, transparent);">
                    <i data-lucide="smartphone" class="w-12 h-12" style="color: var(--accent-primary);"></i>
                </div>
            </div>
            <div class="flex-1 text-center md:text-left">
                <h2 class="text-2xl font-bold text-white mb-2">Transforme sua plataforma em um App</h2>
                <p class="text-gray-300 mb-4">Com o módulo PWA, seus usuários podem instalar sua plataforma diretamente no celular, criando uma experiência nativa e profissional.</p>
                <a href="https://meulink.lat/getfypwa" target="_blank" class="inline-flex items-center gap-2 text-white font-bold py-3 px-6 rounded-lg transition-all duration-300 hover:scale-105" style="background-color: var(--accent-primary);" onmouseover="this.style.backgroundColor='var(--accent-primary-hover)'" onmouseout="this.style.backgroundColor='var(--accent-primary)'">
                    <i data-lucide="shopping-cart" class="w-5 h-5"></i>
                    <span>Adquirir Módulo PWA</span>
                </a>
            </div>
        </div>
    </div>

    <!-- Benefícios -->
    <div class="mb-6">
        <h2 class="text-2xl font-bold text-white mb-4">Benefícios do PWA</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <!-- Benefício 1: Instale no Celular -->
            <div class="bg-dark-card p-6 rounded-lg border border-dark-border hover:border-primary transition-all duration-300 transform hover:scale-105">
                <div class="flex items-start gap-4">
                    <div class="flex-shrink-0 w-12 h-12 rounded-lg flex items-center justify-center" style="background-color: color-mix(in srgb, var(--accent-primary) 20%, transparent);">
                        <i data-lucide="download" class="w-6 h-6" style="color: var(--accent-primary);"></i>
                    </div>
                    <div>
                        <h3 class="text-lg font-semibold text-white mb-2">Instale no Celular</h3>
                        <p class="text-gray-400 text-sm">Seus usuários podem instalar a plataforma diretamente no Android e iPhone, sem precisar passar pela loja de aplicativos.</p>
                    </div>
                </div>
            </div>

            <!-- Benefício 2: Funciona Offline -->
            <div class="bg-dark-card p-6 rounded-lg border border-dark-border hover:border-primary transition-all duration-300 transform hover:scale-105">
                <div class="flex items-start gap-4">
                    <div class="flex-shrink-0 w-12 h-12 rounded-lg flex items-center justify-center" style="background-color: color-mix(in srgb, var(--accent-primary) 20%, transparent);">
                        <i data-lucide="wifi-off" class="w-6 h-6" style="color: var(--accent-primary);"></i>
                    </div>
                    <div>
                        <h3 class="text-lg font-semibold text-white mb-2">Funciona Offline</h3>
                        <p class="text-gray-400 text-sm">O PWA armazena conteúdo em cache, permitindo que funcione mesmo sem conexão com a internet.</p>
                    </div>
                </div>
            </div>

            <!-- Benefício 3: Notificações Push -->
            <div class="bg-dark-card p-6 rounded-lg border border-dark-border hover:border-primary transition-all duration-300 transform hover:scale-105">
                <div class="flex items-start gap-4">
                    <div class="flex-shrink-0 w-12 h-12 rounded-lg flex items-center justify-center" style="background-color: color-mix(in srgb, var(--accent-primary) 20%, transparent);">
                        <i data-lucide="bell" class="w-6 h-6" style="color: var(--accent-primary);"></i>
                    </div>
                    <div>
                        <h3 class="text-lg font-semibold text-white mb-2">Notificações Push</h3>
                        <p class="text-gray-400 text-sm">Envie notificações push diretamente para os dispositivos dos usuários, mesmo quando o app não está aberto.</p>
                    </div>
                </div>
            </div>

            <!-- Benefício 4: Acesso Rápido -->
            <div class="bg-dark-card p-6 rounded-lg border border-dark-border hover:border-primary transition-all duration-300 transform hover:scale-105">
                <div class="flex items-start gap-4">
                    <div class="flex-shrink-0 w-12 h-12 rounded-lg flex items-center justify-center" style="background-color: color-mix(in srgb, var(--accent-primary) 20%, transparent);">
                        <i data-lucide="zap" class="w-6 h-6" style="color: var(--accent-primary);"></i>
                    </div>
                    <div>
                        <h3 class="text-lg font-semibold text-white mb-2">Acesso Rápido</h3>
                        <p class="text-gray-400 text-sm">Acesso instantâneo direto da tela inicial do celular, sem precisar abrir o navegador.</p>
                    </div>
                </div>
            </div>

            <!-- Benefício 5: Experiência Nativa -->
            <div class="bg-dark-card p-6 rounded-lg border border-dark-border hover:border-primary transition-all duration-300 transform hover:scale-105">
                <div class="flex items-start gap-4">
                    <div class="flex-shrink-0 w-12 h-12 rounded-lg flex items-center justify-center" style="background-color: color-mix(in srgb, var(--accent-primary) 20%, transparent);">
                        <i data-lucide="sparkles" class="w-6 h-6" style="color: var(--accent-primary);"></i>
                    </div>
                    <div>
                        <h3 class="text-lg font-semibold text-white mb-2">Experiência Nativa</h3>
                        <p class="text-gray-400 text-sm">Interface que se comporta como um app nativo, com tela cheia e sem barra de endereço do navegador.</p>
                    </div>
                </div>
            </div>

            <!-- Benefício 6: Melhor Performance -->
            <div class="bg-dark-card p-6 rounded-lg border border-dark-border hover:border-primary transition-all duration-300 transform hover:scale-105">
                <div class="flex items-start gap-4">
                    <div class="flex-shrink-0 w-12 h-12 rounded-lg flex items-center justify-center" style="background-color: color-mix(in srgb, var(--accent-primary) 20%, transparent);">
                        <i data-lucide="gauge" class="w-6 h-6" style="color: var(--accent-primary);"></i>
                    </div>
                    <div>
                        <h3 class="text-lg font-semibold text-white mb-2">Melhor Performance</h3>
                        <p class="text-gray-400 text-sm">Carregamento mais rápido e uso eficiente de recursos, proporcionando uma experiência fluida.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Como Funciona -->
    <div class="bg-dark-card p-8 rounded-lg border border-dark-border mb-6">
        <h2 class="text-2xl font-bold text-white mb-4">Como Funciona</h2>
        <div class="space-y-4">
            <div class="flex items-start gap-4">
                <div class="flex-shrink-0 w-8 h-8 rounded-full bg-primary/20 flex items-center justify-center font-bold text-white" style="background-color: color-mix(in srgb, var(--accent-primary) 20%, transparent); color: var(--accent-primary);">
                    1
                </div>
                <div>
                    <h3 class="text-lg font-semibold text-white mb-1">Adquira o Módulo</h3>
                    <p class="text-gray-400">Compre o módulo PWA através do link de aquisição e receba os arquivos para instalação.</p>
                </div>
            </div>
            <div class="flex items-start gap-4">
                <div class="flex-shrink-0 w-8 h-8 rounded-full bg-primary/20 flex items-center justify-center font-bold text-white" style="background-color: color-mix(in srgb, var(--accent-primary) 20%, transparent); color: var(--accent-primary);">
                    2
                </div>
                <div>
                    <h3 class="text-lg font-semibold text-white mb-1">Instale na Plataforma</h3>
                    <p class="text-gray-400">Extraia os arquivos na raiz do código fonte da plataforma e execute a migração SQL.</p>
                </div>
            </div>
            <div class="flex items-start gap-4">
                <div class="flex-shrink-0 w-8 h-8 rounded-full bg-primary/20 flex items-center justify-center font-bold text-white" style="background-color: color-mix(in srgb, var(--accent-primary) 20%, transparent); color: var(--accent-primary);">
                    3
                </div>
                <div>
                    <h3 class="text-lg font-semibold text-white mb-1">Configure no Admin</h3>
                    <p class="text-gray-400">Acesse Configurações > PWA no painel admin e personalize nome, ícone, cores e outras opções.</p>
                </div>
            </div>
            <div class="flex items-start gap-4">
                <div class="flex-shrink-0 w-8 h-8 rounded-full bg-primary/20 flex items-center justify-center font-bold text-white" style="background-color: color-mix(in srgb, var(--accent-primary) 20%, transparent); color: var(--accent-primary);">
                    4
                </div>
                <div>
                    <h3 class="text-lg font-semibold text-white mb-1">Pronto para Usar</h3>
                    <p class="text-gray-400">Seus usuários verão automaticamente o prompt de instalação ao acessar o painel no celular.</p>
                </div>
            </div>
        </div>
    </div>

    <!-- CTA Final -->
    <div class="bg-gradient-to-r from-primary/20 to-primary/10 rounded-lg p-8 border text-center" style="border-color: var(--accent-primary); background: linear-gradient(to right, color-mix(in srgb, var(--accent-primary) 20%, transparent), color-mix(in srgb, var(--accent-primary) 10%, transparent));">
        <h2 class="text-2xl font-bold text-white mb-3">Pronto para Transformar sua Plataforma?</h2>
        <p class="text-gray-300 mb-6">Adquira o módulo PWA agora e ofereça uma experiência de app nativo para seus usuários.</p>
        <a href="https://meulink.lat/getfypwa" target="_blank" class="inline-flex items-center gap-2 text-white font-bold py-4 px-8 rounded-lg transition-all duration-300 hover:scale-105 shadow-lg" style="background-color: var(--accent-primary);" onmouseover="this.style.backgroundColor='var(--accent-primary-hover)'" onmouseout="this.style.backgroundColor='var(--accent-primary)'">
            <i data-lucide="shopping-cart" class="w-5 h-5"></i>
            <span>Adquirir Módulo PWA Agora</span>
        </a>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    lucide.createIcons();
});
</script>

