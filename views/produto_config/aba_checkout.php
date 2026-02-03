<?php
// Aba Checkout - Configurações visuais e funcionais do checkout
?>

<div class="space-y-6">
    <!-- Botão para Abrir Editor de Checkout -->
    <div class="bg-blue-900/20 border-l-4 border-blue-500 p-4 rounded-lg mb-6">
        <div class="flex items-center justify-between">
            <div class="flex-1">
                <h3 class="text-lg font-semibold text-white mb-1 flex items-center gap-2">
                    <i data-lucide="external-link" class="w-5 h-5 text-blue-400"></i>
                    Editor de Checkout
                </h3>
                <p class="text-sm text-blue-300">Abra o editor completo para configurar todas as opções do checkout de forma visual.</p>
            </div>
            <a href="/checkout_editor?id=<?php echo $id_produto; ?>" target="_blank" rel="noopener noreferrer" class="ml-4 text-white font-bold py-3 px-6 rounded-lg shadow-lg hover:shadow-xl transition-all duration-300 flex items-center gap-2 whitespace-nowrap" style="background-color: var(--accent-primary); box-shadow: 0 10px 15px -3px color-mix(in srgb, var(--accent-primary) 20%, transparent);" onmouseover="this.style.backgroundColor='var(--accent-primary-hover)'" onmouseout="this.style.backgroundColor='var(--accent-primary)'">
                <i data-lucide="maximize-2" class="w-5 h-5"></i>
                Abrir Editor
            </a>
        </div>
    </div>
</div>

