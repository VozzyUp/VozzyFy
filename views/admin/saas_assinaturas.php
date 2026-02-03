<?php
/**
 * Página de Assinaturas - Admin
 */

$filtro_status = $_GET['status'] ?? 'all';
$pesquisa = $_GET['pesquisa'] ?? '';
$assinaturas = [];

try {
    $where_conditions = [];
    $params = [];
    
    // Filtro de status
    if ($filtro_status !== 'all') {
        $status_db = ($filtro_status === 'pending') ? 'pendente' : $filtro_status;
        $where_conditions[] = "sa.status = ?";
        $params[] = $status_db;
    }
    
    // Filtro de pesquisa (nome ou email)
    if (!empty($pesquisa)) {
        $where_conditions[] = "(u.nome LIKE ? OR u.usuario LIKE ?)";
        $pesquisa_like = '%' . $pesquisa . '%';
        $params[] = $pesquisa_like;
        $params[] = $pesquisa_like;
    }
    
    $where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';
    
    $sql = "
        SELECT sa.*, sp.nome as plano_nome, sp.preco as plano_preco, u.nome as usuario_nome, u.usuario as usuario_email
        FROM saas_assinaturas sa
        LEFT JOIN saas_planos sp ON sa.plano_id = sp.id
        LEFT JOIN usuarios u ON sa.usuario_id = u.id
        $where_clause
        ORDER BY sa.data_inicio DESC, sa.id DESC
    ";
    
    if (!empty($params)) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    } else {
        $stmt = $pdo->query($sql);
    }
    
    $assinaturas = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Erro ao buscar assinaturas: " . $e->getMessage());
    $assinaturas = [];
}

// Processar cancelamento
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancelar_assinatura'])) {
    $id = intval($_POST['assinatura_id'] ?? 0);
    try {
        $stmt = $pdo->prepare("UPDATE saas_assinaturas SET status = 'cancelado' WHERE id = ?");
        $stmt->execute([$id]);
        // Recarregar assinaturas via JavaScript para evitar problema de headers
        echo '<script>window.location.href = "/admin?pagina=saas_assinaturas&status=' . urlencode($filtro_status) . '&pesquisa=' . urlencode($pesquisa) . '";</script>';
        exit;
    } catch (PDOException $e) {
        $mensagem = '<div class="bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded relative mb-4" role="alert">Erro ao cancelar assinatura: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
}

// Processar alteração de plano
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['alterar_plano'])) {
    $assinatura_id = intval($_POST['assinatura_id'] ?? 0);
    $novo_plano_id = intval($_POST['novo_plano_id'] ?? 0);
    
    if ($assinatura_id && $novo_plano_id) {
        try {
            $pdo->beginTransaction();
            
            // Buscar dados da assinatura atual
            $stmt = $pdo->prepare("SELECT * FROM saas_assinaturas WHERE id = ?");
            $stmt->execute([$assinatura_id]);
            $assinatura_atual = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$assinatura_atual) {
                throw new Exception("Assinatura não encontrada.");
            }
            
            // Buscar dados do novo plano
            $stmt = $pdo->prepare("SELECT * FROM saas_planos WHERE id = ? AND ativo = 1");
            $stmt->execute([$novo_plano_id]);
            $novo_plano = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$novo_plano) {
                throw new Exception("Plano não encontrado ou inativo.");
            }
            
            // Desativar assinatura atual
            $stmt = $pdo->prepare("UPDATE saas_assinaturas SET status = 'expirado' WHERE id = ?");
            $stmt->execute([$assinatura_id]);
            
            // Calcular nova data de vencimento baseada no período do novo plano
            $periodo_dias = $novo_plano['periodo'] === 'anual' ? 365 : 30;
            $nova_data_vencimento = date('Y-m-d', strtotime("+{$periodo_dias} days"));
            
            // Criar nova assinatura
            $stmt = $pdo->prepare("
                INSERT INTO saas_assinaturas 
                (usuario_id, plano_id, status, data_inicio, data_vencimento, metodo_pagamento) 
                VALUES (?, ?, 'ativo', CURDATE(), ?, 'Alterado pelo Admin')
            ");
            $stmt->execute([
                $assinatura_atual['usuario_id'],
                $novo_plano_id,
                $nova_data_vencimento
            ]);
            
            $nova_assinatura_id = $pdo->lastInsertId();
            
            // Desativar outras assinaturas ativas do mesmo usuário
            $stmt = $pdo->prepare("
                UPDATE saas_assinaturas 
                SET status = 'expirado' 
                WHERE usuario_id = ? 
                AND id != ? 
                AND status = 'ativo'
            ");
            $stmt->execute([$assinatura_atual['usuario_id'], $nova_assinatura_id]);
            
            $pdo->commit();
            
            // Recarregar assinaturas via JavaScript para evitar problema de headers
            echo '<script>window.location.href = "/admin?pagina=saas_assinaturas&status=' . urlencode($filtro_status) . '&pesquisa=' . urlencode($pesquisa) . '&msg=plano_alterado";</script>';
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $mensagem = '<div class="bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded relative mb-4" role="alert">Erro ao alterar plano: ' . htmlspecialchars($e->getMessage()) . '</div>';
        }
    } else {
        $mensagem = '<div class="bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded relative mb-4" role="alert">Dados inválidos para alteração de plano.</div>';
    }
}

// Buscar planos disponíveis para o modal de alteração
$planos_disponiveis = [];
try {
    $stmt = $pdo->query("SELECT id, nome, preco, periodo FROM saas_planos WHERE ativo = 1 ORDER BY ordem ASC, preco ASC");
    $planos_disponiveis = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Erro ao buscar planos: " . $e->getMessage());
}
?>

<div class="container mx-auto">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-3xl font-bold text-white">Assinaturas</h1>
            <p class="text-gray-400 mt-1">Visualize e gerencie todas as assinaturas</p>
        </div>
    </div>

    <?php 
    // Mostrar mensagens de sucesso via GET
    if (isset($_GET['msg'])) {
        if ($_GET['msg'] === 'plano_alterado') {
            echo '<div class="bg-green-900/20 border border-green-500 text-green-300 px-4 py-3 rounded relative mb-4" role="alert">Plano alterado com sucesso!</div>';
        }
    }
    if (isset($mensagem)): ?>
        <?php echo $mensagem; ?>
    <?php endif; ?>

    <!-- Filtros e Pesquisa -->
    <div class="bg-dark-card p-4 rounded-lg shadow-md border mb-6" style="border-color: var(--accent-primary);">
        <!-- Campo de Pesquisa -->
        <div class="mb-4">
            <form method="GET" action="/admin" class="flex gap-2">
                <input type="hidden" name="pagina" value="saas_assinaturas">
                <input type="hidden" name="status" value="<?php echo htmlspecialchars($filtro_status); ?>">
                <div class="flex-1 relative">
                    <i data-lucide="search" class="absolute left-3 top-1/2 transform -translate-y-1/2 w-5 h-5 text-gray-400"></i>
                    <input type="text" 
                           name="pesquisa" 
                           value="<?php echo htmlspecialchars($pesquisa); ?>" 
                           placeholder="Pesquisar por nome ou email do usuário..."
                           class="w-full pl-10 pr-4 py-2 bg-dark-elevated border border-dark-border rounded-lg text-white placeholder-gray-500 focus:outline-none focus:border-primary">
                </div>
                <button type="submit" class="px-6 py-2 bg-primary hover:bg-primary/80 text-white rounded-lg font-semibold transition-colors">
                    Pesquisar
                </button>
                <?php if (!empty($pesquisa)): ?>
                    <a href="/admin?pagina=saas_assinaturas&status=<?php echo urlencode($filtro_status); ?>" 
                       class="px-4 py-2 bg-gray-600 hover:bg-gray-500 text-white rounded-lg font-semibold transition-colors">
                        Limpar
                    </a>
                <?php endif; ?>
            </form>
        </div>
        
        <!-- Filtros de Status -->
        <div class="flex flex-wrap gap-2">
            <a href="/admin?pagina=saas_assinaturas&status=all<?php echo !empty($pesquisa) ? '&pesquisa=' . urlencode($pesquisa) : ''; ?>" 
               class="px-4 py-2 rounded-lg font-semibold <?php echo $filtro_status === 'all' ? 'bg-primary text-white' : 'bg-dark-elevated text-gray-400 hover:text-white'; ?>">
                Todas
            </a>
            <a href="/admin?pagina=saas_assinaturas&status=ativo<?php echo !empty($pesquisa) ? '&pesquisa=' . urlencode($pesquisa) : ''; ?>" 
               class="px-4 py-2 rounded-lg font-semibold <?php echo $filtro_status === 'ativo' ? 'bg-primary text-white' : 'bg-dark-elevated text-gray-400 hover:text-white'; ?>">
                Ativas
            </a>
            <a href="/admin?pagina=saas_assinaturas&status=pending<?php echo !empty($pesquisa) ? '&pesquisa=' . urlencode($pesquisa) : ''; ?>" 
               class="px-4 py-2 rounded-lg font-semibold <?php echo $filtro_status === 'pending' ? 'bg-primary text-white' : 'bg-dark-elevated text-gray-400 hover:text-white'; ?>">
                Pendentes
            </a>
            <a href="/admin?pagina=saas_assinaturas&status=cancelado<?php echo !empty($pesquisa) ? '&pesquisa=' . urlencode($pesquisa) : ''; ?>" 
               class="px-4 py-2 rounded-lg font-semibold <?php echo $filtro_status === 'cancelado' ? 'bg-primary text-white' : 'bg-dark-elevated text-gray-400 hover:text-white'; ?>">
                Canceladas
            </a>
            <a href="/admin?pagina=saas_assinaturas&status=expirado<?php echo !empty($pesquisa) ? '&pesquisa=' . urlencode($pesquisa) : ''; ?>" 
               class="px-4 py-2 rounded-lg font-semibold <?php echo $filtro_status === 'expirado' ? 'bg-primary text-white' : 'bg-dark-elevated text-gray-400 hover:text-white'; ?>">
                Expiradas
            </a>
        </div>
    </div>

    <!-- Lista de Assinaturas -->
    <div class="bg-dark-card p-6 rounded-lg shadow-md border" style="border-color: var(--accent-primary);">
        <?php if (empty($assinaturas)): ?>
            <p class="text-gray-400 text-center py-8">Nenhuma assinatura encontrada.</p>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="border-b border-dark-border">
                            <th class="text-left py-3 px-4 text-gray-300">Usuário</th>
                            <th class="text-left py-3 px-4 text-gray-300">Plano</th>
                            <th class="text-left py-3 px-4 text-gray-300">Valor</th>
                            <th class="text-left py-3 px-4 text-gray-300">Status</th>
                            <th class="text-left py-3 px-4 text-gray-300">Início</th>
                            <th class="text-left py-3 px-4 text-gray-300">Vencimento</th>
                            <th class="text-left py-3 px-4 text-gray-300">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($assinaturas as $assinatura): ?>
                            <tr class="border-b border-dark-border hover:bg-dark-elevated">
                                <td class="py-3 px-4 text-white">
                                    <div>
                                        <div class="font-semibold"><?php echo htmlspecialchars($assinatura['usuario_nome'] ?? 'Usuário não encontrado'); ?></div>
                                        <div class="text-sm text-gray-400"><?php echo htmlspecialchars($assinatura['usuario_email'] ?? 'N/A'); ?></div>
                                    </div>
                                </td>
                                <td class="py-3 px-4 text-white">
                                    <?php echo htmlspecialchars($assinatura['plano_nome'] ?? 'Plano não encontrado'); ?>
                                </td>
                                <td class="py-3 px-4 text-white">
                                    R$ <?php echo number_format($assinatura['plano_preco'] ?? 0, 2, ',', '.'); ?>
                                </td>
                                <td class="py-3 px-4">
                                    <?php
                                    $status_colors = [
                                        'ativo' => 'text-green-400',
                                        'pending' => 'text-yellow-400',
                                        'pendente' => 'text-yellow-400',
                                        'cancelado' => 'text-red-400',
                                        'expirado' => 'text-gray-400'
                                    ];
                                    $status_display = $assinatura['status'];
                                    // Normalizar exibição do status
                                    if ($status_display === 'pendente') {
                                        $status_display = 'pending';
                                    }
                                    $color = $status_colors[$assinatura['status']] ?? 'text-gray-400';
                                    ?>
                                    <span class="<?php echo $color; ?> font-semibold">
                                        <?php echo ucfirst($status_display); ?>
                                    </span>
                                </td>
                                <td class="py-3 px-4 text-gray-300">
                                    <?php echo date('d/m/Y', strtotime($assinatura['data_inicio'])); ?>
                                </td>
                                <td class="py-3 px-4 text-gray-300">
                                    <?php echo date('d/m/Y', strtotime($assinatura['data_vencimento'])); ?>
                                </td>
                                <td class="py-3 px-4">
                                    <div class="flex items-center gap-2">
                                        <button onclick="abrirModalAlterarPlano(<?php echo $assinatura['id']; ?>, '<?php echo htmlspecialchars($assinatura['usuario_nome'] ?? 'Usuário'); ?>', <?php echo $assinatura['plano_id']; ?>)" 
                                                class="text-primary hover:text-primary/80" 
                                                title="Alterar Plano">
                                            <i data-lucide="edit" class="w-4 h-4"></i>
                                        </button>
                                        <?php if ($assinatura['status'] === 'ativo'): ?>
                                            <form method="POST" class="inline" onsubmit="return confirm('Tem certeza que deseja cancelar esta assinatura?');">
                                                <input type="hidden" name="assinatura_id" value="<?php echo $assinatura['id']; ?>">
                                                <input type="hidden" name="cancelar_assinatura" value="1">
                                                <button type="submit" class="text-red-400 hover:text-red-300" title="Cancelar Assinatura">
                                                    <i data-lucide="x-circle" class="w-4 h-4"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Modal Alterar Plano -->
    <div id="modal-alterar-plano" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
        <div class="bg-dark-card rounded-xl border border-dark-border p-6 max-w-md w-full">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-xl font-bold text-white">Alterar Plano</h3>
                <button onclick="fecharModalAlterarPlano()" class="text-gray-400 hover:text-white">
                    <i data-lucide="x" class="w-6 h-6"></i>
                </button>
            </div>
            
            <form method="POST" id="form-alterar-plano">
                <input type="hidden" name="alterar_plano" value="1">
                <input type="hidden" name="assinatura_id" id="modal-assinatura-id">
                
                <div class="mb-4">
                    <p class="text-gray-300 mb-2">Usuário: <span id="modal-usuario-nome" class="font-semibold text-white"></span></p>
                </div>
                
                <div class="mb-4">
                    <label class="block text-gray-300 mb-2">Novo Plano *</label>
                    <select name="novo_plano_id" id="modal-novo-plano" required
                            class="w-full bg-dark-elevated border border-dark-border rounded-lg px-4 py-2 text-white focus:outline-none focus:border-primary">
                        <option value="">Selecione um plano...</option>
                        <?php foreach ($planos_disponiveis as $plano): ?>
                            <option value="<?php echo $plano['id']; ?>">
                                <?php echo htmlspecialchars($plano['nome']); ?> - 
                                R$ <?php echo number_format($plano['preco'], 2, ',', '.'); ?> 
                                (<?php echo $plano['periodo'] === 'mensal' ? 'Mensal' : 'Anual'; ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="flex gap-3">
                    <button type="submit" 
                            class="flex-1 px-4 py-2 bg-primary hover:bg-primary/80 text-white rounded-lg font-semibold transition-colors">
                        Alterar Plano
                    </button>
                    <button type="button" 
                            onclick="fecharModalAlterarPlano()"
                            class="px-4 py-2 bg-gray-600 hover:bg-gray-500 text-white rounded-lg font-semibold transition-colors">
                        Cancelar
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    lucide.createIcons();
    
    function abrirModalAlterarPlano(assinaturaId, usuarioNome, planoAtualId) {
        document.getElementById('modal-assinatura-id').value = assinaturaId;
        document.getElementById('modal-usuario-nome').textContent = usuarioNome;
        document.getElementById('modal-novo-plano').value = '';
        document.getElementById('modal-alterar-plano').classList.remove('hidden');
        lucide.createIcons();
    }
    
    function fecharModalAlterarPlano() {
        document.getElementById('modal-alterar-plano').classList.add('hidden');
    }
    
    // Fechar modal ao clicar fora
    document.getElementById('modal-alterar-plano')?.addEventListener('click', function(e) {
        if (e.target === this) {
            fecharModalAlterarPlano();
        }
    });
    
    // Confirmar antes de alterar
    document.getElementById('form-alterar-plano')?.addEventListener('submit', function(e) {
        const novoPlano = document.getElementById('modal-novo-plano').value;
        if (!novoPlano) {
            e.preventDefault();
            alert('Por favor, selecione um plano.');
            return false;
        }
        
        const planoSelecionado = document.getElementById('modal-novo-plano').options[document.getElementById('modal-novo-plano').selectedIndex].text;
        if (!confirm('Tem certeza que deseja alterar o plano para: ' + planoSelecionado + '?')) {
            e.preventDefault();
            return false;
        }
    });
</script>


