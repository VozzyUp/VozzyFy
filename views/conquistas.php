<?php
// Este arquivo é incluído dentro de index.php

require_once __DIR__ . '/../helpers/conquistas_helper.php';

$usuario_id = $_SESSION['id'] ?? null;
if (!$usuario_id) {
    echo '<div class="text-center p-10"><p class="text-gray-400">Erro ao carregar conquistas.</p></div>';
    return;
}

$faturamento = calcular_faturamento_lifetime($usuario_id);
$conquistas = obter_todas_conquistas_com_status($usuario_id);
?>

<div class="container mx-auto">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-3xl font-bold text-white">Conquistas</h1>
            <p class="text-gray-400 mt-1">Acompanhe seu progresso e desbloqueie novas conquistas baseadas no seu faturamento.</p>
        </div>
        <a href="/index?pagina=dashboard" class="bg-dark-elevated text-gray-300 font-bold py-2 px-4 rounded-lg hover:bg-dark-card transition duration-300 flex items-center space-x-2 border border-dark-border">
            <i data-lucide="arrow-left" class="w-5 h-5"></i>
            <span>Voltar</span>
        </a>
    </div>

    <!-- Card de Resumo -->
    <div class="bg-dark-card p-6 rounded-lg shadow-md mb-6 border border-dark-border">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="text-xl font-semibold text-white mb-2">Faturamento</h2>
                <p class="text-3xl font-bold" style="color: var(--accent-primary);">R$ <?php echo number_format($faturamento, 2, ',', '.'); ?></p>
            </div>
            <div class="text-right">
                <p class="text-sm text-gray-400 mb-1">Conquistas Desbloqueadas</p>
                <p class="text-2xl font-bold text-white">
                    <?php 
                    $conquistas_desbloqueadas = count(array_filter($conquistas, function($c) { return $c['conquistada']; }));
                    echo $conquistas_desbloqueadas . ' / ' . count($conquistas);
                    ?>
                </p>
            </div>
        </div>
    </div>

    <!-- Grid de Conquistas -->
    <?php if (empty($conquistas)): ?>
    <div class="bg-dark-card p-10 rounded-lg shadow-md border border-dark-border text-center">
        <i data-lucide="trophy" class="w-16 h-16 text-gray-500 mx-auto mb-4"></i>
        <p class="text-gray-400 text-lg">Nenhuma conquista cadastrada ainda.</p>
    </div>
    <?php else: ?>
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        <?php foreach ($conquistas as $conquista): ?>
        <div class="bg-dark-card rounded-lg shadow-md border border-dark-border overflow-hidden transition-all duration-300 hover:shadow-lg <?php echo $conquista['conquistada'] ? 'ring-2' : 'opacity-75'; ?>" 
             style="<?php echo $conquista['conquistada'] ? 'ring-color: var(--accent-primary);' : ''; ?>">
            
            <!-- Header do Card -->
            <div class="p-6 text-center border-b border-dark-border">
                <?php if ($conquista['imagem_badge']): ?>
                <img src="/<?php echo htmlspecialchars($conquista['imagem_badge']); ?>" 
                     alt="<?php echo htmlspecialchars($conquista['nome']); ?>" 
                     class="mx-auto mb-4 object-contain <?php echo $conquista['conquistada'] ? '' : 'opacity-50'; ?>"
                     style="width: 216px; height: 270px; aspect-ratio: 1080/1350;">
                <?php else: ?>
                <div class="mx-auto bg-dark-elevated flex items-center justify-center mb-4 <?php echo $conquista['conquistada'] ? '' : 'opacity-50'; ?>"
                     style="width: 216px; height: 270px; aspect-ratio: 1080/1350;">
                    <i data-lucide="trophy" class="w-16 h-16 <?php echo $conquista['conquistada'] ? 'text-primary' : 'text-gray-500'; ?>"></i>
                </div>
                <?php endif; ?>
                
                <h3 class="text-xl font-bold text-white mb-1"><?php echo htmlspecialchars($conquista['nome']); ?></h3>
                <?php if ($conquista['descricao']): ?>
                <p class="text-sm text-gray-400"><?php echo htmlspecialchars($conquista['descricao']); ?></p>
                <?php endif; ?>
            </div>
            
            <!-- Conteúdo do Card -->
            <div class="p-6">
                <!-- Faixa de Faturamento -->
                <div class="mb-4">
                    <p class="text-xs text-gray-400 mb-1">Faixa de Faturamento</p>
                    <p class="text-sm font-semibold text-white">
                        R$ <?php echo number_format($conquista['valor_minimo'], 2, ',', '.'); ?>
                        <?php if ($conquista['valor_maximo']): ?>
                        - R$ <?php echo number_format($conquista['valor_maximo'], 2, ',', '.'); ?>
                        <?php else: ?>
                        <span class="text-gray-500">+</span>
                        <?php endif; ?>
                    </p>
                </div>
                
                <!-- Status -->
                <div class="mb-4">
                    <?php if ($conquista['conquistada']): ?>
                    <div class="flex items-center gap-2" style="color: var(--accent-primary);">
                        <i data-lucide="check-circle" class="w-5 h-5" style="color: var(--accent-primary);"></i>
                        <span class="text-sm font-semibold">Conquistada</span>
                        <?php if ($conquista['data_conquista']): ?>
                        <span class="text-xs text-gray-400 ml-auto">
                            <?php echo date('d/m/Y', strtotime($conquista['data_conquista'])); ?>
                        </span>
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <div class="flex items-center gap-2 text-gray-500">
                        <i data-lucide="lock" class="w-5 h-5"></i>
                        <span class="text-sm font-semibold">Bloqueada</span>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Barra de Progresso -->
                <?php if (!$conquista['conquistada'] && ($conquista['pode_mostrar_progresso'] ?? false)): ?>
                <div class="mb-2">
                    <div class="flex items-center justify-between text-xs text-gray-400 mb-1">
                        <span>Progresso</span>
                        <span><?php echo number_format($conquista['progresso'], 0); ?>%</span>
                    </div>
                    <div class="w-full bg-dark-elevated rounded-full h-2 overflow-hidden">
                        <div class="h-full rounded-full transition-all duration-300" 
                             style="width: <?php echo min(100, max(0, $conquista['progresso'])); ?>%; background-color: var(--accent-primary);"></div>
                    </div>
                    <?php if ($conquista['faltante'] > 0): ?>
                    <p class="text-xs text-gray-500 mt-1">Faltam R$ <?php echo number_format($conquista['faltante'], 2, ',', '.'); ?></p>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    lucide.createIcons();
    
    // Animação de reveal ao scroll
    const observerOptions = {
        threshold: 0.1,
        rootMargin: '0px 0px -50px 0px'
    };
    
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.style.opacity = '1';
                entry.target.style.transform = 'translateY(0)';
            }
        });
    }, observerOptions);
    
    document.querySelectorAll('.bg-dark-card.rounded-lg').forEach(card => {
        card.style.opacity = '0';
        card.style.transform = 'translateY(20px)';
        card.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
        observer.observe(card);
    });
});
</script>

