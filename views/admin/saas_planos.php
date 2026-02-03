<?php
/**
 * Página de Gerenciamento de Planos - Admin
 */

$mensagem = '';
$editing_plano = null;

// Buscar planos
$planos = [];
try {
    $stmt = $pdo->query("SELECT * FROM saas_planos ORDER BY ordem ASC, id ASC");
    $planos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Erro ao buscar planos: " . $e->getMessage());
}

// Verificar se está editando (GET request)
if (isset($_GET['editar'])) {
    $id = intval($_GET['editar']);
    foreach ($planos as $plano) {
        if ($plano['id'] == $id) {
            $editing_plano = $plano;
            break;
        }
    }
}

// Processar ações (POST requests)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['criar_plano'])) {
        $nome = $_POST['nome'] ?? '';
        $descricao = $_POST['descricao'] ?? '';
        $preco = floatval($_POST['preco'] ?? 0);
        $periodo = $_POST['periodo'] ?? 'mensal';
        $max_produtos = !empty($_POST['max_produtos']) ? intval($_POST['max_produtos']) : null;
        $max_pedidos_mes = !empty($_POST['max_pedidos_mes']) ? intval($_POST['max_pedidos_mes']) : null;
        $is_free = isset($_POST['is_free']) ? 1 : 0;
        $ativo = isset($_POST['ativo']) ? 1 : 0;
        $ordem = intval($_POST['ordem'] ?? 0);
        
        try {
            $stmt = $pdo->prepare("
                INSERT INTO saas_planos 
                (nome, descricao, preco, periodo, max_produtos, max_pedidos_mes, is_free, ativo, ordem) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$nome, $descricao, $preco, $periodo, $max_produtos, $max_pedidos_mes, $is_free, $ativo, $ordem]);
            $mensagem = '<div class="bg-green-900/20 border border-green-500 text-green-300 px-4 py-3 rounded relative mb-4" role="alert">Plano criado com sucesso!</div>';
            
            // Recarregar planos
            $stmt = $pdo->query("SELECT * FROM saas_planos ORDER BY ordem ASC, id ASC");
            $planos = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $editing_plano = null;
        } catch (PDOException $e) {
            $mensagem = '<div class="bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded relative mb-4" role="alert">Erro ao criar plano: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }
    } elseif (isset($_POST['editar_plano'])) {
        $id = intval($_POST['plano_id'] ?? 0);
        $nome = $_POST['nome'] ?? '';
        $descricao = $_POST['descricao'] ?? '';
        $preco = floatval($_POST['preco'] ?? 0);
        $periodo = $_POST['periodo'] ?? 'mensal';
        $max_produtos = !empty($_POST['max_produtos']) ? intval($_POST['max_produtos']) : null;
        $max_pedidos_mes = !empty($_POST['max_pedidos_mes']) ? intval($_POST['max_pedidos_mes']) : null;
        $is_free = isset($_POST['is_free']) ? 1 : 0;
        $ativo = isset($_POST['ativo']) ? 1 : 0;
        $ordem = intval($_POST['ordem'] ?? 0);
        
        try {
            $stmt = $pdo->prepare("
                UPDATE saas_planos 
                SET nome = ?, descricao = ?, preco = ?, periodo = ?, max_produtos = ?, max_pedidos_mes = ?, is_free = ?, ativo = ?, ordem = ?
                WHERE id = ?
            ");
            $stmt->execute([$nome, $descricao, $preco, $periodo, $max_produtos, $max_pedidos_mes, $is_free, $ativo, $ordem, $id]);
            $mensagem = '<div class="bg-green-900/20 border border-green-500 text-green-300 px-4 py-3 rounded relative mb-4" role="alert">Plano atualizado com sucesso!</div>';
            
            // Recarregar planos
            $stmt = $pdo->query("SELECT * FROM saas_planos ORDER BY ordem ASC, id ASC");
            $planos = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $editing_plano = null;
        } catch (PDOException $e) {
            $mensagem = '<div class="bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded relative mb-4" role="alert">Erro ao atualizar plano: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }
    } elseif (isset($_POST['excluir_plano'])) {
        $id = intval($_POST['plano_id'] ?? 0);
        try {
            $stmt = $pdo->prepare("DELETE FROM saas_planos WHERE id = ?");
            $stmt->execute([$id]);
            $mensagem = '<div class="bg-green-900/20 border border-green-500 text-green-300 px-4 py-3 rounded relative mb-4" role="alert">Plano excluído com sucesso!</div>';
            
            // Recarregar planos
            $stmt = $pdo->query("SELECT * FROM saas_planos ORDER BY ordem ASC, id ASC");
            $planos = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $editing_plano = null;
        } catch (PDOException $e) {
            $mensagem = '<div class="bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded relative mb-4" role="alert">Erro ao excluir plano: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }
    }
}
?>

<div class="container mx-auto">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-3xl font-bold text-white">Gerenciar Planos</h1>
            <p class="text-gray-400 mt-1">Crie e gerencie os planos disponíveis para infoprodutores</p>
        </div>
        <button onclick="toggleForm()" 
                class="px-6 py-2 bg-primary hover:bg-primary/80 text-white rounded-lg font-semibold">
            <i data-lucide="plus" class="w-5 h-5 inline mr-2"></i>
            <?php echo $editing_plano ? 'Cancelar Edição' : 'Novo Plano'; ?>
        </button>
    </div>

    <?php if ($mensagem): ?>
        <?php echo $mensagem; ?>
    <?php endif; ?>

    <!-- Formulário de Criar/Editar -->
    <div id="form-criar-plano" class="<?php echo $editing_plano ? '' : 'hidden'; ?> bg-dark-card p-6 rounded-lg shadow-md border mb-6" style="border-color: var(--accent-primary);">
        <h2 class="text-xl font-semibold text-white mb-4">
            <?php echo $editing_plano ? 'Editar Plano' : 'Criar Novo Plano'; ?>
        </h2>
        
        <form method="POST">
            <?php if ($editing_plano): ?>
                <input type="hidden" name="plano_id" value="<?php echo $editing_plano['id']; ?>">
                <input type="hidden" name="editar_plano" value="1">
            <?php else: ?>
                <input type="hidden" name="criar_plano" value="1">
            <?php endif; ?>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-gray-300 mb-2">Nome do Plano *</label>
                    <input type="text" name="nome" required
                           value="<?php echo htmlspecialchars($editing_plano['nome'] ?? ''); ?>"
                           class="w-full bg-dark-elevated border border-dark-border rounded-lg px-4 py-2 text-white">
                </div>
                
                <div>
                    <label class="block text-gray-300 mb-2">Preço (R$) *</label>
                    <input type="number" name="preco" step="0.01" min="0" required
                           value="<?php echo $editing_plano['preco'] ?? '0.00'; ?>"
                           class="w-full bg-dark-elevated border border-dark-border rounded-lg px-4 py-2 text-white">
                </div>
                
                <div>
                    <label class="block text-gray-300 mb-2">Período *</label>
                    <select name="periodo" required
                            class="w-full bg-dark-elevated border border-dark-border rounded-lg px-4 py-2 text-white">
                        <option value="mensal" <?php echo ($editing_plano['periodo'] ?? 'mensal') === 'mensal' ? 'selected' : ''; ?>>Mensal</option>
                        <option value="anual" <?php echo ($editing_plano['periodo'] ?? '') === 'anual' ? 'selected' : ''; ?>>Anual</option>
                    </select>
                </div>
                
                <div>
                    <label class="block text-gray-300 mb-2">Ordem de Exibição</label>
                    <input type="number" name="ordem" min="0"
                           value="<?php echo $editing_plano['ordem'] ?? '0'; ?>"
                           class="w-full bg-dark-elevated border border-dark-border rounded-lg px-4 py-2 text-white">
                </div>
                
                <div>
                    <label class="block text-gray-300 mb-2">Máximo de Produtos</label>
                    <input type="number" name="max_produtos" min="0"
                           value="<?php echo $editing_plano['max_produtos'] ?? ''; ?>"
                           placeholder="Deixe vazio para ilimitado"
                           class="w-full bg-dark-elevated border border-dark-border rounded-lg px-4 py-2 text-white">
                </div>
                
                <div>
                    <label class="block text-gray-300 mb-2">Máximo de Pedidos por Mês</label>
                    <input type="number" name="max_pedidos_mes" min="0"
                           value="<?php echo $editing_plano['max_pedidos_mes'] ?? ''; ?>"
                           placeholder="Deixe vazio para ilimitado"
                           class="w-full bg-dark-elevated border border-dark-border rounded-lg px-4 py-2 text-white">
                </div>
            </div>
            
            <div class="mt-4">
                <label class="block text-gray-300 mb-2">Descrição</label>
                <textarea name="descricao" rows="3"
                          class="w-full bg-dark-elevated border border-dark-border rounded-lg px-4 py-2 text-white"><?php echo htmlspecialchars($editing_plano['descricao'] ?? ''); ?></textarea>
            </div>
            
            <div class="mt-4 flex items-center space-x-4">
                <label class="flex items-center">
                    <input type="checkbox" name="is_free" value="1"
                           <?php echo ($editing_plano['is_free'] ?? 0) ? 'checked' : ''; ?>
                           class="mr-2">
                    <span class="text-gray-300">Plano Gratuito</span>
                </label>
                
                <label class="flex items-center">
                    <input type="checkbox" name="ativo" value="1"
                           <?php echo ($editing_plano['ativo'] ?? 1) ? 'checked' : ''; ?>
                           class="mr-2">
                    <span class="text-gray-300">Ativo</span>
                </label>
            </div>
            
            <div class="mt-6 flex space-x-4">
                <button type="submit" class="px-6 py-2 bg-primary hover:bg-primary/80 text-white rounded-lg font-semibold">
                    <?php echo $editing_plano ? 'Atualizar' : 'Criar'; ?> Plano
                </button>
                <?php if ($editing_plano): ?>
                    <a href="/admin?pagina=saas_planos" class="px-6 py-2 bg-gray-600 hover:bg-gray-700 text-white rounded-lg font-semibold">
                        Cancelar
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Lista de Planos -->
    <div class="bg-dark-card p-6 rounded-lg shadow-md border" style="border-color: var(--accent-primary);">
        <h2 class="text-xl font-semibold text-white mb-4">Planos Cadastrados</h2>
        
        <?php if (empty($planos)): ?>
            <p class="text-gray-400 text-center py-8">Nenhum plano cadastrado ainda.</p>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-dark-border">
                            <th class="text-left py-3 px-4 text-gray-300">Nome</th>
                            <th class="text-left py-3 px-4 text-gray-300">Preço</th>
                            <th class="text-left py-3 px-4 text-gray-300">Período</th>
                            <th class="text-left py-3 px-4 text-gray-300">Limites</th>
                            <th class="text-left py-3 px-4 text-gray-300">Status</th>
                            <th class="text-left py-3 px-4 text-gray-300">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($planos as $plano): ?>
                            <tr class="border-b border-dark-border hover:bg-dark-elevated">
                                <td class="py-3 px-4 text-white">
                                    <?php echo htmlspecialchars($plano['nome']); ?>
                                    <?php if ($plano['is_free']): ?>
                                        <span class="ml-2 text-xs bg-green-500/20 text-green-400 px-2 py-1 rounded">GRATUITO</span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3 px-4 text-white">
                                    R$ <?php echo number_format($plano['preco'], 2, ',', '.'); ?>
                                </td>
                                <td class="py-3 px-4 text-gray-300">
                                    <?php echo ucfirst($plano['periodo']); ?>
                                </td>
                                <td class="py-3 px-4 text-gray-300 text-sm">
                                    Produtos: <?php echo $plano['max_produtos'] ?? '∞'; ?><br>
                                    Pedidos/mês: <?php echo $plano['max_pedidos_mes'] ?? '∞'; ?>
                                </td>
                                <td class="py-3 px-4">
                                    <?php if ($plano['ativo']): ?>
                                        <span class="text-green-400">Ativo</span>
                                    <?php else: ?>
                                        <span class="text-red-400">Inativo</span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3 px-4">
                                    <div class="flex space-x-2">
                                        <a href="/admin?pagina=saas_planos&editar=<?php echo $plano['id']; ?>" 
                                           class="text-blue-400 hover:text-blue-300">
                                            <i data-lucide="edit" class="w-4 h-4"></i>
                                        </a>
                                        <form method="POST" class="inline" onsubmit="return confirm('Tem certeza que deseja excluir este plano?');">
                                            <input type="hidden" name="plano_id" value="<?php echo $plano['id']; ?>">
                                            <input type="hidden" name="excluir_plano" value="1">
                                            <button type="submit" class="text-red-400 hover:text-red-300">
                                                <i data-lucide="trash" class="w-4 h-4"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
    lucide.createIcons();
    
    function toggleForm() {
        const form = document.getElementById('form-criar-plano');
        if (form) {
            form.classList.toggle('hidden');
            // Se estiver editando, redirecionar para limpar o parâmetro GET
            <?php if ($editing_plano): ?>
                window.location.href = '/admin?pagina=saas_planos';
            <?php endif; ?>
        }
    }
    
    // Mostrar formulário automaticamente se estiver editando
    <?php if ($editing_plano): ?>
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('form-criar-plano');
            if (form) {
                form.classList.remove('hidden');
                // Scroll suave até o formulário
                form.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    <?php endif; ?>
</script>


