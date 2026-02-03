<?php
/**
 * Página de Configuração SaaS - Admin
 */

require_once __DIR__ . '/../../saas/includes/saas_functions.php';

$saas_enabled = saas_enabled();
$mensagem = '';

// Processar habilitar/desabilitar
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'enable') {
        if (saas_enable()) {
            $mensagem = '<div class="bg-green-900/20 border border-green-500 text-green-300 px-4 py-3 rounded relative mb-4" role="alert">Sistema SaaS habilitado com sucesso!</div>';
            $saas_enabled = true;
        } else {
            $mensagem = '<div class="bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded relative mb-4" role="alert">Erro ao habilitar sistema SaaS.</div>';
        }
    } elseif ($_POST['action'] === 'disable') {
        if (saas_disable()) {
            $mensagem = '<div class="bg-yellow-900/20 border border-yellow-500 text-yellow-300 px-4 py-3 rounded relative mb-4" role="alert">Sistema SaaS desabilitado com sucesso!</div>';
            $saas_enabled = false;
        } else {
            $mensagem = '<div class="bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded relative mb-4" role="alert">Erro ao desabilitar sistema SaaS.</div>';
        }
    }
}

// Buscar estatísticas
$total_planos = 0;
$total_assinaturas = 0;
$total_assinaturas_ativas = 0;

try {
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM saas_planos");
    $total_planos = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM saas_assinaturas");
    $total_assinaturas = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM saas_assinaturas WHERE status = 'ativo' AND data_vencimento >= CURDATE()");
    $total_assinaturas_ativas = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
} catch (PDOException $e) {
    error_log("Erro ao buscar estatísticas SaaS: " . $e->getMessage());
}
?>

<div class="container mx-auto">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-3xl font-bold text-white">Modo SaaS</h1>
            <p class="text-gray-400 mt-1">Gerencie o sistema de planos e assinaturas</p>
        </div>
    </div>

    <?php if ($mensagem): ?>
        <?php echo $mensagem; ?>
    <?php endif; ?>

    <!-- Status do Sistema -->
    <div class="bg-dark-card p-6 rounded-lg shadow-md border mb-6" style="border-color: var(--accent-primary);">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="text-xl font-semibold text-white mb-2">Status do Sistema</h2>
                <p class="text-gray-400">
                    O sistema SaaS está atualmente: 
                    <span class="font-bold <?php echo $saas_enabled ? 'text-green-400' : 'text-red-400'; ?>">
                        <?php echo $saas_enabled ? 'HABILITADO' : 'DESABILITADO'; ?>
                    </span>
                </p>
            </div>
            <form method="POST" class="inline">
                <?php if ($saas_enabled): ?>
                    <input type="hidden" name="action" value="disable">
                    <button type="submit" class="px-6 py-2 bg-red-600 hover:bg-red-700 text-white rounded-lg font-semibold transition-colors">
                        Desabilitar SaaS
                    </button>
                <?php else: ?>
                    <input type="hidden" name="action" value="enable">
                    <button type="submit" class="px-6 py-2 bg-green-600 hover:bg-green-700 text-white rounded-lg font-semibold transition-colors">
                        Habilitar SaaS
                    </button>
                <?php endif; ?>
            </form>
        </div>
    </div>

    <!-- Explicação do Sistema -->
    <div class="bg-dark-card p-6 rounded-lg shadow-md border mb-6" style="border-color: var(--accent-primary);">
        <h2 class="text-xl font-semibold text-white mb-4">Sobre o Modo SaaS</h2>
        <div class="text-gray-300 space-y-3">
            <p>O Modo SaaS permite que você transforme sua plataforma em um sistema de assinaturas, onde:</p>
            <ul class="list-disc list-inside space-y-2 ml-4">
                <li>Você pode criar planos gratuitos e pagos para infoprodutores</li>
                <li>Infoprodutores podem se cadastrar e adquirir planos</li>
                <li>Você pode definir limitações por plano (quantidade de produtos, pedidos por mês)</li>
                <li>O sistema controla automaticamente os limites de cada usuário</li>
                <li>Infoprodutores sem plano ativo não podem criar produtos ou realizar pedidos</li>
            </ul>
            <p class="mt-4"><strong class="text-white">Importante:</strong> Ao habilitar o Modo SaaS, todos os infoprodutores precisarão ter um plano ativo para usar a plataforma. Certifique-se de criar pelo menos um plano gratuito antes de habilitar.</p>
        </div>
    </div>

    <!-- Estatísticas -->
    <?php if ($saas_enabled): ?>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
        <div class="bg-dark-card p-6 rounded-lg shadow-md border" style="border-color: var(--accent-primary);">
            <div class="flex items-center space-x-4">
                <div class="bg-primary/20 p-3 rounded-full">
                    <i data-lucide="package" class="w-6 h-6 text-primary"></i>
                </div>
                <div>
                    <p class="text-gray-400 text-sm">Total de Planos</p>
                    <p class="text-2xl font-bold text-white"><?php echo $total_planos; ?></p>
                </div>
            </div>
        </div>
        <div class="bg-dark-card p-6 rounded-lg shadow-md border" style="border-color: var(--accent-primary);">
            <div class="flex items-center space-x-4">
                <div class="bg-primary/20 p-3 rounded-full">
                    <i data-lucide="users" class="w-6 h-6 text-primary"></i>
                </div>
                <div>
                    <p class="text-gray-400 text-sm">Total de Assinaturas</p>
                    <p class="text-2xl font-bold text-white"><?php echo $total_assinaturas; ?></p>
                </div>
            </div>
        </div>
        <div class="bg-dark-card p-6 rounded-lg shadow-md border" style="border-color: var(--accent-primary);">
            <div class="flex items-center space-x-4">
                <div class="bg-primary/20 p-3 rounded-full">
                    <i data-lucide="check-circle" class="w-6 h-6 text-primary"></i>
                </div>
                <div>
                    <p class="text-gray-400 text-sm">Assinaturas Ativas</p>
                    <p class="text-2xl font-bold text-white"><?php echo $total_assinaturas_ativas; ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Links Rápidos -->
    <div class="bg-dark-card p-6 rounded-lg shadow-md border" style="border-color: var(--accent-primary);">
        <h2 class="text-xl font-semibold text-white mb-4">Gerenciamento</h2>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <a href="/admin?pagina=saas_planos" class="bg-dark-elevated hover:bg-dark-card p-4 rounded-lg border border-dark-border hover:border-primary transition-colors">
                <div class="flex items-center space-x-3">
                    <i data-lucide="package" class="w-6 h-6 text-primary"></i>
                    <div>
                        <p class="font-semibold text-white">Gerenciar Planos</p>
                        <p class="text-sm text-gray-400">Criar e editar planos</p>
                    </div>
                </div>
            </a>
            <a href="/admin?pagina=saas_gateways" class="bg-dark-elevated hover:bg-dark-card p-4 rounded-lg border border-dark-border hover:border-primary transition-colors">
                <div class="flex items-center space-x-3">
                    <i data-lucide="credit-card" class="w-6 h-6 text-primary"></i>
                    <div>
                        <p class="font-semibold text-white">Gateways de Pagamento</p>
                        <p class="text-sm text-gray-400">Configurar métodos de pagamento</p>
                    </div>
                </div>
            </a>
            <a href="/admin?pagina=saas_assinaturas" class="bg-dark-elevated hover:bg-dark-card p-4 rounded-lg border border-dark-border hover:border-primary transition-colors">
                <div class="flex items-center space-x-3">
                    <i data-lucide="users" class="w-6 h-6 text-primary"></i>
                    <div>
                        <p class="font-semibold text-white">Assinaturas</p>
                        <p class="text-sm text-gray-400">Ver todas as assinaturas</p>
                    </div>
                </div>
            </a>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
    lucide.createIcons();
</script>


