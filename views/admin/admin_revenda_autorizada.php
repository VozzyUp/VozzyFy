<?php
// Este arquivo é incluído dentro de admin.php
?>

<div class="flex items-center justify-between mb-6">
    <div>
        <h1 class="text-3xl font-bold text-white">Revenda Autorizada</h1>
        <p class="text-gray-400 mt-1">Transforme sua paixão em um negócio escalável e lucrativo.</p>
    </div>
    <a href="/admin?pagina=admin_dashboard" class="bg-dark-elevated text-gray-300 font-bold py-2 px-4 rounded-lg hover:bg-dark-card transition duration-300 flex items-center space-x-2 border border-dark-border">
        <i data-lucide="arrow-left" class="w-5 h-5"></i>
        <span>Voltar ao Dashboard</span>
    </a>
</div>

<!-- Hero Section -->
<div class="bg-gradient-to-br p-8 rounded-lg shadow-lg mb-8 border" style="background: linear-gradient(to bottom right, color-mix(in srgb, var(--accent-primary) 20%, transparent), color-mix(in srgb, var(--accent-primary-hover) 10%, transparent), transparent); border-color: color-mix(in srgb, var(--accent-primary) 30%, transparent);">
    <div class="flex items-center gap-4 mb-4">
        <div class="w-16 h-16 rounded-full flex items-center justify-center" style="background-color: color-mix(in srgb, var(--accent-primary) 20%, transparent);">
            <i data-lucide="trending-up" class="w-8 h-8" style="color: var(--accent-primary);"></i>
        </div>
        <div>
            <h2 class="text-4xl font-bold text-white mb-2">Escale Seu Negócio de Forma Inteligente</h2>
            <p class="text-xl text-gray-300">Revenda a plataforma completa e comece a faturar hoje mesmo</p>
        </div>
    </div>
    <p class="text-lg text-gray-400 leading-relaxed">
        Você não precisa criar do zero. Com nosso programa de Revenda Autorizada, você recebe o código fonte completo da plataforma, 
        com direito de revenda e todo o suporte necessário para construir um negócio digital escalável e lucrativo.
    </p>
</div>

<!-- Grid de Benefícios -->
<div class="grid md:grid-cols-2 lg:grid-cols-3 gap-6 mb-8">
    <!-- Benefício 1: Preço Livre -->
    <div class="bg-dark-card p-6 rounded-lg shadow-md border border-dark-border transition-all duration-300" onmouseover="this.style.borderColor='color-mix(in srgb, var(--accent-primary) 50%, transparent)'" onmouseout="this.style.borderColor=''">
        <div class="w-12 h-12 rounded-lg flex items-center justify-center mb-4" style="background-color: color-mix(in srgb, var(--accent-primary) 20%, transparent);">
            <i data-lucide="dollar-sign" class="w-6 h-6" style="color: var(--accent-primary);"></i>
        </div>
        <h3 class="text-xl font-bold text-white mb-3">Revenda pelo Preço que Quiser</h3>
        <p class="text-gray-400 leading-relaxed">
            Você tem total liberdade para definir seus próprios preços. Maximize sua margem de lucro e crie estratégias de precificação 
            que funcionem para o seu mercado e audiência.
        </p>
    </div>

    <!-- Benefício 2: Código Fonte Completo -->
    <div class="bg-dark-card p-6 rounded-lg shadow-md border border-dark-border transition-all duration-300" onmouseover="this.style.borderColor='color-mix(in srgb, var(--accent-primary) 50%, transparent)'" onmouseout="this.style.borderColor=''">
        <div class="w-12 h-12 rounded-lg flex items-center justify-center mb-4" style="background-color: color-mix(in srgb, var(--accent-primary) 20%, transparent);">
            <i data-lucide="code" class="w-6 h-6" style="color: var(--accent-primary);"></i>
        </div>
        <h3 class="text-xl font-bold text-white mb-3">Código Fonte Completo</h3>
        <p class="text-gray-400 leading-relaxed">
            Receba o código fonte completo da plataforma de checkout, com todas as funcionalidades prontas. Você terá direito de revender 
            a plataforma como seu próprio produto, com total liberdade para personalizar e adaptar conforme sua necessidade.
        </p>
    </div>

    <!-- Benefício 3: Suporte Completo -->
    <div class="bg-dark-card p-6 rounded-lg shadow-md border border-dark-border transition-all duration-300" onmouseover="this.style.borderColor='color-mix(in srgb, var(--accent-primary) 50%, transparent)'" onmouseout="this.style.borderColor=''">
        <div class="w-12 h-12 rounded-lg flex items-center justify-center mb-4" style="background-color: color-mix(in srgb, var(--accent-primary) 20%, transparent);">
            <i data-lucide="headphones" class="w-6 h-6" style="color: var(--accent-primary);"></i>
        </div>
        <h3 class="text-xl font-bold text-white mb-3">Suporte e Materiais de Marketing</h3>
        <p class="text-gray-400 leading-relaxed">
            Acesso a materiais de marketing prontos, artes para redes sociais, copy de vendas e suporte dedicado para ajudar 
            você a escalar suas vendas rapidamente.
        </p>
    </div>

    <!-- Benefício 4: Escalabilidade -->
    <div class="bg-dark-card p-6 rounded-lg shadow-md border border-dark-border transition-all duration-300" onmouseover="this.style.borderColor='color-mix(in srgb, var(--accent-primary) 50%, transparent)'" onmouseout="this.style.borderColor=''">
        <div class="w-12 h-12 rounded-lg flex items-center justify-center mb-4" style="background-color: color-mix(in srgb, var(--accent-primary) 20%, transparent);">
            <i data-lucide="rocket" class="w-6 h-6" style="color: var(--accent-primary);"></i>
        </div>
        <h3 class="text-xl font-bold text-white mb-3">Negócio Escalável</h3>
        <p class="text-gray-400 leading-relaxed">
            Construa um negócio que cresce sem limites. Revendendo a plataforma, você pode vender infinitamente sem custos adicionais 
            de produção ou estoque. Cada venda é lucro líquido.
        </p>
    </div>

    <!-- Benefício 5: Liberdade -->
    <div class="bg-dark-card p-6 rounded-lg shadow-md border border-dark-border transition-all duration-300" onmouseover="this.style.borderColor='color-mix(in srgb, var(--accent-primary) 50%, transparent)'" onmouseout="this.style.borderColor=''">
        <div class="w-12 h-12 rounded-lg flex items-center justify-center mb-4" style="background-color: color-mix(in srgb, var(--accent-primary) 20%, transparent);">
            <i data-lucide="zap" class="w-6 h-6" style="color: var(--accent-primary);"></i>
        </div>
        <h3 class="text-xl font-bold text-white mb-3">Comece Hoje Mesmo</h3>
        <p class="text-gray-400 leading-relaxed">
            Não precisa esperar. Com acesso imediato ao código fonte da plataforma e materiais de marketing, você pode começar 
            a revender e gerar receita desde o primeiro dia.
        </p>
    </div>

    <!-- Benefício 6: Alta Margem -->
    <div class="bg-dark-card p-6 rounded-lg shadow-md border border-dark-border transition-all duration-300" onmouseover="this.style.borderColor='color-mix(in srgb, var(--accent-primary) 50%, transparent)'" onmouseout="this.style.borderColor=''">
        <div class="w-12 h-12 rounded-lg flex items-center justify-center mb-4" style="background-color: color-mix(in srgb, var(--accent-primary) 20%, transparent);">
            <i data-lucide="trending-up" class="w-6 h-6" style="color: var(--accent-primary);"></i>
        </div>
        <h3 class="text-xl font-bold text-white mb-3">Alta Margem de Lucro</h3>
        <p class="text-gray-400 leading-relaxed">
            Revender a plataforma oferece margens de lucro muito superiores a produtos físicos. Sem custos de produção, 
            estoque ou logística, você fica com a maior parte do valor de cada venda.
        </p>
    </div>
</div>

<!-- Seção de Detalhes -->
<div class="bg-dark-card p-8 rounded-lg shadow-md mb-8 border border-dark-border">
    <h2 class="text-2xl font-semibold mb-6 text-white flex items-center gap-2">
        <i data-lucide="info" class="w-6 h-6" style="color: var(--accent-primary);"></i>
        <span>Como Funciona o Programa</span>
    </h2>
    
    <div class="space-y-6">
        <div class="flex gap-4">
            <div class="flex-shrink-0 w-8 h-8 rounded-full flex items-center justify-center" style="background-color: color-mix(in srgb, var(--accent-primary) 20%, transparent);">
                <span class="font-bold" style="color: var(--accent-primary);">1</span>
            </div>
            <div>
                <h3 class="text-lg font-semibold text-white mb-2">Acesso ao Código Fonte</h3>
                <p class="text-gray-400">Ao se tornar um revendedor autorizado, você recebe acesso imediato ao código fonte completo da plataforma e materiais de marketing.</p>
            </div>
        </div>
        
        <div class="flex gap-4">
            <div class="flex-shrink-0 w-8 h-8 rounded-full flex items-center justify-center" style="background-color: color-mix(in srgb, var(--accent-primary) 20%, transparent);">
                <span class="font-bold" style="color: var(--accent-primary);">2</span>
            </div>
            <div>
                <h3 class="text-lg font-semibold text-white mb-2">Direito de Revenda</h3>
                <p class="text-gray-400">Você tem o direito legal de revender a plataforma como seu próprio produto. Personalize com sua marca e adapte conforme sua necessidade.</p>
            </div>
        </div>
        
        <div class="flex gap-4">
            <div class="flex-shrink-0 w-8 h-8 rounded-full flex items-center justify-center" style="background-color: color-mix(in srgb, var(--accent-primary) 20%, transparent);">
                <span class="font-bold" style="color: var(--accent-primary);">3</span>
            </div>
            <div>
                <h3 class="text-lg font-semibold text-white mb-2">Liberdade de Preços</h3>
                <p class="text-gray-400">Você define seus próprios preços. Não há preços mínimos ou máximos. Crie estratégias de precificação que maximizem seus lucros.</p>
            </div>
        </div>
        
        <div class="flex gap-4">
            <div class="flex-shrink-0 w-8 h-8 rounded-full flex items-center justify-center" style="background-color: color-mix(in srgb, var(--accent-primary) 20%, transparent);">
                <span class="font-bold" style="color: var(--accent-primary);">4</span>
            </div>
            <div>
                <h3 class="text-lg font-semibold text-white mb-2">Suporte Contínuo</h3>
                <p class="text-gray-400">Receba suporte dedicado, materiais de marketing atualizados e acesso a atualizações da plataforma conforme são lançadas.</p>
            </div>
        </div>
    </div>
</div>

<!-- Seção de Promessa -->
<div class="p-8 rounded-lg shadow-md mb-8 border-l-4" style="background: linear-gradient(to right, color-mix(in srgb, var(--accent-primary) 10%, transparent), transparent); border-color: var(--accent-primary);">
    <div class="flex items-start gap-4">
        <i data-lucide="target" class="w-8 h-8 flex-shrink-0 mt-1" style="color: var(--accent-primary);"></i>
        <div>
            <h2 class="text-2xl font-bold text-white mb-4">Escalar e Ganhar Dinheiro</h2>
            <p class="text-lg text-gray-300 leading-relaxed mb-4">
                Este não é apenas um programa de revenda. É uma oportunidade real de construir um negócio digital escalável e lucrativo.
            </p>
            <ul class="space-y-3 text-gray-300">
                <li class="flex items-start gap-2">
                    <i data-lucide="check-circle" class="w-5 h-5 flex-shrink-0 mt-0.5" style="color: var(--accent-primary);"></i>
                    <span><strong class="text-white">Ganhe dinheiro desde o primeiro dia</strong> - Comece a revender a plataforma imediatamente com código fonte completo</span>
                </li>
                <li class="flex items-start gap-2">
                    <i data-lucide="check-circle" class="w-5 h-5 flex-shrink-0 mt-0.5" style="color: var(--accent-primary);"></i>
                    <span><strong class="text-white">Escale sem limites</strong> - Revender a plataforma permite crescimento infinito sem custos adicionais</span>
                </li>
                <li class="flex items-start gap-2">
                    <i data-lucide="check-circle" class="w-5 h-5 flex-shrink-0 mt-0.5" style="color: var(--accent-primary);"></i>
                    <span><strong class="text-white">Máxima margem de lucro</strong> - Fique com a maior parte do valor de cada venda da plataforma</span>
                </li>
                <li class="flex items-start gap-2">
                    <i data-lucide="check-circle" class="w-5 h-5 flex-shrink-0 mt-0.5" style="color: var(--accent-primary);"></i>
                    <span><strong class="text-white">Liberdade total</strong> - Defina seus preços, sua estratégia e construa seu próprio negócio revendendo a plataforma</span>
                </li>
                <li class="flex items-start gap-2">
                    <i data-lucide="check-circle" class="w-5 h-5 flex-shrink-0 mt-0.5" style="color: var(--accent-primary);"></i>
                    <span><strong class="text-white">Suporte completo</strong> - Materiais de marketing prontos, copy de vendas e suporte para você escalar</span>
                </li>
            </ul>
        </div>
    </div>
</div>

<!-- CTA Final -->
<div class="bg-dark-card p-8 rounded-lg shadow-lg border-2 text-center" style="border-color: var(--accent-primary);">
    <div class="max-w-2xl mx-auto">
        <div class="w-20 h-20 rounded-full flex items-center justify-center mx-auto mb-6" style="background-color: color-mix(in srgb, var(--accent-primary) 20%, transparent);">
            <i data-lucide="sparkles" class="w-10 h-10" style="color: var(--accent-primary);"></i>
        </div>
        <h2 class="text-3xl font-bold text-white mb-4">Pronto para Começar a Escalar?</h2>
        <p class="text-lg text-gray-300 mb-8 leading-relaxed">
            Junte-se ao programa de Revenda Autorizada e transforme sua paixão em um negócio digital lucrativo. 
            Acesso imediato ao código fonte completo da plataforma e suporte completo para você começar a revender e faturar hoje mesmo.
        </p>
        <a href="https://meulink.lat/revendagetfy" target="_blank" rel="noopener noreferrer" 
           class="inline-flex items-center justify-center gap-3 text-white font-bold py-4 px-8 rounded-lg shadow-lg hover:shadow-xl transition-all duration-300 text-lg transform hover:scale-105" style="background-color: var(--accent-primary); box-shadow: 0 10px 15px -3px color-mix(in srgb, var(--accent-primary) 20%, transparent);" onmouseover="this.style.backgroundColor='var(--accent-primary-hover)'" onmouseout="this.style.backgroundColor='var(--accent-primary)'">
            <i data-lucide="arrow-right" class="w-6 h-6"></i>
            <span>Quero Ser Revendedor Autorizado</span>
        </a>
        <p class="text-sm text-gray-400 mt-4">
            <i data-lucide="lock" class="w-4 h-4 inline-block mr-1"></i>
            Processo seguro e garantido
        </p>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    lucide.createIcons();
});
</script>

