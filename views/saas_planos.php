<?php
/**
 * Página de Planos SaaS - Infoprodutor
 * Lista planos disponíveis e permite assinar
 */

require_once __DIR__ . '/../saas/includes/saas_functions.php';

// Verificar se SaaS está habilitado
if (!saas_enabled()) {
    echo '<div class="bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded relative mb-4" role="alert">Sistema SaaS não está habilitado.</div>';
    return;
}

// Buscar planos ativos
$planos = [];
try {
    $stmt = $pdo->query("SELECT * FROM saas_planos WHERE ativo = 1 ORDER BY ordem ASC, preco ASC");
    $planos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Erro ao buscar planos: " . $e->getMessage());
}

// Separar planos por período e filtrar planos free (preço 0 ou is_free = 1)
$planos_mensais = array_filter($planos, function($p) { 
    return $p['periodo'] === 'mensal' && (float)$p['preco'] > 0 && (int)$p['is_free'] === 0; 
});
$planos_anuais = array_filter($planos, function($p) { 
    return $p['periodo'] === 'anual' && (float)$p['preco'] > 0 && (int)$p['is_free'] === 0; 
});

// Buscar plano atual do usuário
$plano_atual = null;
$assinatura_atual = null;
$plano_info = saas_get_user_plan($_SESSION['id']);
if ($plano_info) {
    $plano_atual = $plano_info['plano_id'];
    $assinatura_atual = $plano_info;
}

// Definir tab ativo: se não houver planos mensais, usar anual; caso contrário, usar mensal
$tab_ativo = $_GET['tab'] ?? (empty($planos_mensais) && !empty($planos_anuais) ? 'anual' : 'mensal');

// Definir tab ativo: se não houver planos mensais, usar anual; caso contrário, usar mensal
$tab_ativo = $_GET['tab'] ?? (empty($planos_mensais) && !empty($planos_anuais) ? 'anual' : 'mensal');
?>

<div class="container mx-auto">
    <div class="mb-8">
        <h1 class="text-3xl font-bold text-white mb-2">Escolha seu Plano</h1>
        <p class="text-gray-400">Selecione o plano ideal para suas necessidades</p>
    </div>

    <?php if ($plano_atual && $assinatura_atual): ?>
    <!-- Card do Plano Atual -->
    <div class="bg-gradient-to-br from-primary/20 via-primary/10 to-primary/5 border-2 border-primary/40 rounded-2xl p-8 mb-8 shadow-lg shadow-primary/10">
        <div class="flex items-start justify-between flex-wrap gap-6">
            <div class="flex-1">
                <div class="flex items-center gap-3 mb-4">
                    <div class="w-12 h-12 bg-primary/20 rounded-xl flex items-center justify-center">
                        <i data-lucide="crown" class="w-6 h-6 text-primary"></i>
                    </div>
                    <div>
                        <h2 class="text-2xl font-bold text-white mb-1">Plano Atual</h2>
                        <p class="text-primary font-semibold text-lg"><?php echo htmlspecialchars($assinatura_atual['plano_nome']); ?></p>
                    </div>
                </div>
                
                <!-- Limitações do Plano e Valor -->
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mt-6">
                    <div class="bg-dark-elevated/50 rounded-lg p-4 border border-dark-border">
                        <div class="flex items-center gap-3 mb-2">
                            <i data-lucide="package" class="w-5 h-5 text-primary"></i>
                            <span class="text-gray-400 text-sm">Produtos</span>
                        </div>
                        <p class="text-2xl font-bold text-white">
                            <?php echo $assinatura_atual['max_produtos'] ? number_format($assinatura_atual['max_produtos'], 0, ',', '.') : 'Ilimitado'; ?>
                        </p>
                    </div>
                    
                    <div class="bg-dark-elevated/50 rounded-lg p-4 border border-dark-border">
                        <div class="flex items-center gap-3 mb-2">
                            <i data-lucide="shopping-cart" class="w-5 h-5 text-primary"></i>
                            <span class="text-gray-400 text-sm">Pedidos/mês</span>
                        </div>
                        <p class="text-2xl font-bold text-white">
                            <?php echo $assinatura_atual['max_pedidos_mes'] ? number_format($assinatura_atual['max_pedidos_mes'], 0, ',', '.') : 'Ilimitado'; ?>
                        </p>
                    </div>
                    
                    <div class="bg-dark-elevated/50 rounded-lg p-4 border border-dark-border">
                        <div class="flex items-center gap-3 mb-2">
                            <i data-lucide="calendar" class="w-5 h-5 text-primary"></i>
                            <span class="text-gray-400 text-sm">Vence em</span>
                        </div>
                        <p class="text-lg font-bold text-white mb-3">
                            <?php echo date('d/m/Y', strtotime($assinatura_atual['data_vencimento'])); ?>
                        </p>
                        <div class="pt-3 border-t border-dark-border">
                            <p class="text-gray-400 text-xs mb-1">Valor</p>
                            <p class="text-2xl font-bold text-white">R$ <?php echo number_format($assinatura_atual['preco'], 2, ',', '.'); ?></p>
                            <p class="text-gray-400 text-xs mt-1">por <?php echo $assinatura_atual['periodo'] === 'mensal' ? 'mês' : 'ano'; ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Tabs Mensal/Anual (só aparece se houver ambos os tipos) -->
    <?php if (!empty($planos_mensais) && !empty($planos_anuais)): ?>
    <div class="mb-8">
        <div class="flex gap-2 bg-dark-elevated p-1 rounded-lg inline-flex">
            <button onclick="switchTab('mensal')" id="tab-mensal" class="px-6 py-2 rounded-md font-semibold transition-all <?php echo $tab_ativo === 'mensal' ? 'bg-primary text-white' : 'text-gray-400 hover:text-white'; ?>">
                Mensal
            </button>
            <button onclick="switchTab('anual')" id="tab-anual" class="px-6 py-2 rounded-md font-semibold transition-all <?php echo $tab_ativo === 'anual' ? 'bg-primary text-white' : 'text-gray-400 hover:text-white'; ?>">
                Anual
            </button>
        </div>
    </div>
    <?php endif; ?>

    <!-- Planos Mensais -->
    <div id="planos-mensais" class="<?php echo (empty($planos_anuais) || $tab_ativo === 'mensal') ? '' : 'hidden'; ?>">
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php if (empty($planos_mensais)): ?>
                <div class="col-span-full text-center py-12">
                    <p class="text-gray-400">Nenhum plano mensal disponível no momento.</p>
                </div>
            <?php else: ?>
                <?php foreach ($planos_mensais as $plano): ?>
                    <div class="bg-dark-card rounded-xl border border-dark-border p-6 hover:border-primary/50 transition-all <?php echo $plano_atual == $plano['id'] ? 'ring-2 ring-primary' : ''; ?>">
                        <?php if ($plano_atual == $plano['id']): ?>
                            <div class="mb-4">
                                <span class="inline-block bg-primary/20 text-primary px-3 py-1 rounded-full text-xs font-bold">Plano Atual</span>
                            </div>
                        <?php endif; ?>
                        
                        <h3 class="text-2xl font-bold text-white mb-2"><?php echo htmlspecialchars($plano['nome']); ?></h3>
                        <div class="mb-4">
                            <span class="text-4xl font-bold text-white">R$ <?php echo number_format($plano['preco'], 2, ',', '.'); ?></span>
                            <span class="text-gray-400">/mês</span>
                        </div>
                        
                        <?php if (!empty($plano['descricao'])): ?>
                            <p class="text-gray-300 mb-4"><?php echo nl2br(htmlspecialchars($plano['descricao'])); ?></p>
                        <?php endif; ?>
                        
                        <ul class="space-y-2 mb-6">
                            <li class="flex items-center text-gray-300">
                                <i data-lucide="check" class="w-5 h-5 text-primary mr-2"></i>
                                <span>Produtos: <?php echo $plano['max_produtos'] ?? 'Ilimitado'; ?></span>
                            </li>
                            <li class="flex items-center text-gray-300">
                                <i data-lucide="check" class="w-5 h-5 text-primary mr-2"></i>
                                <span>Pedidos/mês: <?php echo $plano['max_pedidos_mes'] ?? 'Ilimitado'; ?></span>
                            </li>
                        </ul>
                        
                        <?php if ($plano_atual != $plano['id']): ?>
                            <?php 
                            $checkout_url = '/saas/checkout/checkout_plano.php?plano_id=' . intval($plano['id']);
                            ?>
                            <a href="<?php echo htmlspecialchars($checkout_url); ?>" 
                               class="block w-full text-center px-6 py-3 bg-primary hover:bg-primary/80 text-white rounded-lg font-semibold transition-colors">
                                <?php echo $plano['is_free'] ? 'Ativar Plano Grátis' : 'Assinar Agora'; ?>
                            </a>
                        <?php else: ?>
                            <button disabled class="block w-full text-center px-6 py-3 bg-gray-600 text-gray-400 rounded-lg font-semibold cursor-not-allowed">
                                Plano Atual
                            </button>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Planos Anuais -->
    <div id="planos-anuais" class="<?php echo (!empty($planos_anuais) && $tab_ativo === 'anual') ? '' : 'hidden'; ?>">
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php if (empty($planos_anuais)): ?>
                <div class="col-span-full text-center py-12">
                    <p class="text-gray-400">Nenhum plano anual disponível no momento.</p>
                </div>
            <?php else: ?>
                <?php foreach ($planos_anuais as $plano): ?>
                    <div class="bg-dark-card rounded-xl border border-dark-border p-6 hover:border-primary/50 transition-all <?php echo $plano_atual == $plano['id'] ? 'ring-2 ring-primary' : ''; ?>">
                        <?php if ($plano_atual == $plano['id']): ?>
                            <div class="mb-4">
                                <span class="inline-block bg-primary/20 text-primary px-3 py-1 rounded-full text-xs font-bold">Plano Atual</span>
                            </div>
                        <?php endif; ?>
                        
                        <h3 class="text-2xl font-bold text-white mb-2"><?php echo htmlspecialchars($plano['nome']); ?></h3>
                        <div class="mb-4">
                            <span class="text-4xl font-bold text-white">R$ <?php echo number_format($plano['preco'], 2, ',', '.'); ?></span>
                            <span class="text-gray-400">/ano</span>
                        </div>
                        
                        <?php if (!empty($plano['descricao'])): ?>
                            <p class="text-gray-300 mb-4"><?php echo nl2br(htmlspecialchars($plano['descricao'])); ?></p>
                        <?php endif; ?>
                        
                        <ul class="space-y-2 mb-6">
                            <li class="flex items-center text-gray-300">
                                <i data-lucide="check" class="w-5 h-5 text-primary mr-2"></i>
                                <span>Produtos: <?php echo $plano['max_produtos'] ?? 'Ilimitado'; ?></span>
                            </li>
                            <li class="flex items-center text-gray-300">
                                <i data-lucide="check" class="w-5 h-5 text-primary mr-2"></i>
                                <span>Pedidos/mês: <?php echo $plano['max_pedidos_mes'] ?? 'Ilimitado'; ?></span>
                            </li>
                        </ul>
                        
                        <?php if ($plano_atual != $plano['id']): ?>
                            <?php 
                            $checkout_url = '/saas/checkout/checkout_plano.php?plano_id=' . intval($plano['id']);
                            ?>
                            <a href="<?php echo htmlspecialchars($checkout_url); ?>" 
                               class="block w-full text-center px-6 py-3 bg-primary hover:bg-primary/80 text-white rounded-lg font-semibold transition-colors">
                                Assinar Agora
                            </a>
                        <?php else: ?>
                            <button disabled class="block w-full text-center px-6 py-3 bg-gray-600 text-gray-400 rounded-lg font-semibold cursor-not-allowed">
                                Plano Atual
                            </button>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function switchTab(tab) {
    // Esconder todas as seções
    document.getElementById('planos-mensais').classList.add('hidden');
    document.getElementById('planos-anuais').classList.add('hidden');
    
    // Remover estilo ativo de todos os botões
    document.getElementById('tab-mensal').classList.remove('bg-primary', 'text-white');
    document.getElementById('tab-mensal').classList.add('text-gray-400');
    document.getElementById('tab-anual').classList.remove('bg-primary', 'text-white');
    document.getElementById('tab-anual').classList.add('text-gray-400');
    
    // Mostrar seção selecionada
    if (tab === 'mensal') {
        document.getElementById('planos-mensais').classList.remove('hidden');
        document.getElementById('tab-mensal').classList.add('bg-primary', 'text-white');
        document.getElementById('tab-mensal').classList.remove('text-gray-400');
    } else {
        document.getElementById('planos-anuais').classList.remove('hidden');
        document.getElementById('tab-anual').classList.add('bg-primary', 'text-white');
        document.getElementById('tab-anual').classList.remove('text-gray-400');
    }
}

lucide.createIcons();
</script>


