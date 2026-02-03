<?php
/**
 * Página de Planos SaaS para Infoprodutores
 */
require_once __DIR__ . '/../plugins/saas/includes/user_dashboard_info.php';

// Buscar planos ativos
try {
    $stmt = $pdo->query("SELECT * FROM saas_planos WHERE ativo = 1 ORDER BY preco ASC");
    $planos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $planos = [];
}

// Separar planos por período
$planos_mensais = array_filter($planos, function($p) { return $p['periodo'] === 'mensal'; });
$planos_anuais = array_filter($planos, function($p) { return $p['periodo'] === 'anual'; });

// Buscar plano atual do usuário
$plano_atual = null;
$assinatura_atual = null;
if (plugin_active('saas')) {
    $plano_info = get_user_plan_dashboard_info($_SESSION['id']);
    if ($plano_info) {
        try {
            $stmt = $pdo->prepare("
                SELECT sa.*, sp.* 
                FROM saas_assinaturas sa
                JOIN saas_planos sp ON sa.plano_id = sp.id
                WHERE sa.usuario_id = ? AND sa.status = 'ativo' AND sa.data_vencimento > NOW()
                ORDER BY sa.data_inicio DESC
                LIMIT 1
            ");
            $stmt->execute([$_SESSION['id']]);
            $assinatura_atual = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($assinatura_atual) {
                $plano_atual = $assinatura_atual['plano_id'];
            }
        } catch (PDOException $e) {
            error_log("Erro ao buscar assinatura atual: " . $e->getMessage());
        }
    }
}

$tab_ativo = $_GET['tab'] ?? 'mensal';
?>

<div class="container mx-auto">
    <div class="mb-8">
        <h1 class="text-3xl font-bold text-white mb-2">Escolha seu Plano</h1>
        <p class="text-gray-400">Selecione o plano ideal para suas necessidades</p>
    </div>

    <?php if ($plano_atual && $assinatura_atual): ?>
    <div class="bg-gradient-to-r from-primary/20 to-primary/10 border border-primary/30 rounded-xl p-6 mb-8">
        <div class="flex items-center justify-between flex-wrap gap-4">
            <div>
                <h2 class="text-xl font-bold text-white mb-1">Plano Atual: <?php echo htmlspecialchars($assinatura_atual['nome']); ?></h2>
                <p class="text-gray-300 text-sm">
                    Vence em: <?php echo date('d/m/Y', strtotime($assinatura_atual['data_vencimento'])); ?>
                </p>
            </div>
            <div class="flex items-center gap-4">
                <div class="text-right">
                    <p class="text-2xl font-bold text-white">R$ <?php echo number_format($assinatura_atual['preco'], 2, ',', '.'); ?></p>
                    <p class="text-gray-400 text-sm">por <?php echo $assinatura_atual['periodo'] === 'mensal' ? 'mês' : 'ano'; ?></p>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Tabs Mensal/Anual -->
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

    <!-- Planos Mensais -->
    <div id="planos-mensais" class="<?php echo $tab_ativo === 'mensal' ? '' : 'hidden'; ?>">
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
                        <p class="text-gray-400 text-sm mb-4"><?php echo htmlspecialchars($plano['descricao']); ?></p>
                        
                        <div class="mb-6">
                            <div class="flex items-baseline gap-2">
                                <span class="text-4xl font-bold text-white">R$ <?php echo number_format($plano['preco'], 2, ',', '.'); ?></span>
                                <span class="text-gray-400">/mês</span>
                            </div>
                        </div>
                        
                        <div class="space-y-3 mb-6">
                            <div class="flex items-center gap-2">
                                <i data-lucide="check" class="w-5 h-5 text-primary"></i>
                                <span class="text-gray-300 text-sm">
                                    Produtos: <?php echo $plano['max_produtos'] ?? 'Ilimitado'; ?>
                                </span>
                            </div>
                            <div class="flex items-center gap-2">
                                <i data-lucide="check" class="w-5 h-5 text-primary"></i>
                                <span class="text-gray-300 text-sm">
                                    Pedidos/mês: <?php echo $plano['max_pedidos_mes'] ?? 'Ilimitado'; ?>
                                </span>
                            </div>
                            <div class="flex items-center gap-2">
                                <i data-lucide="<?php echo $plano['tracking_enabled'] ? 'check' : 'x'; ?>" class="w-5 h-5 <?php echo $plano['tracking_enabled'] ? 'text-primary' : 'text-gray-500'; ?>"></i>
                                <span class="text-gray-300 text-sm">
                                    Sistema de Tracking: <?php echo $plano['tracking_enabled'] ? 'Habilitado' : 'Desabilitado'; ?>
                                </span>
                            </div>
                        </div>
                        
                        <?php if ($plano_atual == $plano['id']): ?>
                            <button disabled class="w-full bg-gray-700 text-gray-400 font-semibold py-3 px-6 rounded-lg cursor-not-allowed">
                                Plano Atual
                            </button>
                        <?php elseif ($plano['preco'] == 0): ?>
                            <a href="/plugins/saas/admin/checkout?plano=<?php echo $plano['id']; ?>" class="block w-full bg-primary hover:bg-primary-hover text-white font-semibold py-3 px-6 rounded-lg transition-colors text-center">
                                Selecionar Grátis
                            </a>
                        <?php else: ?>
                            <a href="/plugins/saas/admin/checkout?plano=<?php echo $plano['id']; ?>" class="block w-full bg-primary hover:bg-primary-hover text-white font-semibold py-3 px-6 rounded-lg transition-colors text-center">
                                Assinar Agora
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Planos Anuais -->
    <div id="planos-anuais" class="<?php echo $tab_ativo === 'anual' ? '' : 'hidden'; ?>">
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
                        <p class="text-gray-400 text-sm mb-4"><?php echo htmlspecialchars($plano['descricao']); ?></p>
                        
                        <div class="mb-6">
                            <div class="flex items-baseline gap-2">
                                <span class="text-4xl font-bold text-white">R$ <?php echo number_format($plano['preco'], 2, ',', '.'); ?></span>
                                <span class="text-gray-400">/ano</span>
                            </div>
                            <p class="text-gray-500 text-xs mt-1">
                                R$ <?php echo number_format($plano['preco'] / 12, 2, ',', '.'); ?> por mês
                            </p>
                        </div>
                        
                        <div class="space-y-3 mb-6">
                            <div class="flex items-center gap-2">
                                <i data-lucide="check" class="w-5 h-5 text-primary"></i>
                                <span class="text-gray-300 text-sm">
                                    Produtos: <?php echo $plano['max_produtos'] ?? 'Ilimitado'; ?>
                                </span>
                            </div>
                            <div class="flex items-center gap-2">
                                <i data-lucide="check" class="w-5 h-5 text-primary"></i>
                                <span class="text-gray-300 text-sm">
                                    Pedidos/mês: <?php echo $plano['max_pedidos_mes'] ?? 'Ilimitado'; ?>
                                </span>
                            </div>
                            <div class="flex items-center gap-2">
                                <i data-lucide="<?php echo $plano['tracking_enabled'] ? 'check' : 'x'; ?>" class="w-5 h-5 <?php echo $plano['tracking_enabled'] ? 'text-primary' : 'text-gray-500'; ?>"></i>
                                <span class="text-gray-300 text-sm">
                                    Sistema de Tracking: <?php echo $plano['tracking_enabled'] ? 'Habilitado' : 'Desabilitado'; ?>
                                </span>
                            </div>
                        </div>
                        
                        <?php if ($plano_atual == $plano['id']): ?>
                            <button disabled class="w-full bg-gray-700 text-gray-400 font-semibold py-3 px-6 rounded-lg cursor-not-allowed">
                                Plano Atual
                            </button>
                        <?php elseif ($plano['preco'] == 0): ?>
                            <a href="/plugins/saas/admin/checkout?plano=<?php echo $plano['id']; ?>" class="block w-full bg-primary hover:bg-primary-hover text-white font-semibold py-3 px-6 rounded-lg transition-colors text-center">
                                Selecionar Grátis
                            </a>
                        <?php else: ?>
                            <a href="/plugins/saas/admin/checkout?plano=<?php echo $plano['id']; ?>" class="block w-full bg-primary hover:bg-primary-hover text-white font-semibold py-3 px-6 rounded-lg transition-colors text-center">
                                Assinar Agora
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
    function switchTab(tab) {
        // Atualiza tabs
        document.getElementById('tab-mensal').classList.toggle('bg-primary', tab === 'mensal');
        document.getElementById('tab-mensal').classList.toggle('text-white', tab === 'mensal');
        document.getElementById('tab-mensal').classList.toggle('text-gray-400', tab !== 'mensal');
        
        document.getElementById('tab-anual').classList.toggle('bg-primary', tab === 'anual');
        document.getElementById('tab-anual').classList.toggle('text-white', tab === 'anual');
        document.getElementById('tab-anual').classList.toggle('text-gray-400', tab !== 'anual');
        
        // Mostra/esconde planos
        document.getElementById('planos-mensais').classList.toggle('hidden', tab !== 'mensal');
        document.getElementById('planos-anuais').classList.toggle('hidden', tab !== 'anual');
        
        // Atualiza URL sem recarregar
        const url = new URL(window.location);
        url.searchParams.set('tab', tab);
        window.history.pushState({}, '', url);
    }
    
    // Inicializa ícones
    if (typeof lucide !== 'undefined') {
        lucide.createIcons();
    }
</script>

