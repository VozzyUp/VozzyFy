<?php
// Este arquivo é incluído a partir do index.php

// Incluir helper de segurança para funções CSRF
require_once __DIR__ . '/../helpers/security_helper.php';

// Gerar token CSRF para uso em requisições JavaScript
$csrf_token_js = generate_csrf_token();

// Buscar reembolsos do infoprodutor logado
$usuario_id_logado = $_SESSION['id'] ?? 0;
$reembolsos = [];
$reembolsos_stats = [
    'pending' => 0,
    'approved' => 0,
    'rejected' => 0,
    'total' => 0
];

try {
    // Buscar estatísticas
    $stmt_stats = $pdo->prepare("
        SELECT 
            status,
            COUNT(*) as total
        FROM reembolsos
        WHERE usuario_id = ?
        GROUP BY status
    ");
    $stmt_stats->execute([$usuario_id_logado]);
    $stats_data = $stmt_stats->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($stats_data as $stat) {
        $reembolsos_stats[$stat['status']] = (int)$stat['total'];
        $reembolsos_stats['total'] += (int)$stat['total'];
    }
    
    // Buscar reembolsos pendentes primeiro, depois os outros
    $stmt = $pdo->prepare("
        SELECT 
            r.*,
            p.nome as produto_nome,
            v.data_venda,
            DATEDIFF(NOW(), v.data_venda) as dias_desde_compra
        FROM reembolsos r
        JOIN produtos p ON r.produto_id = p.id
        JOIN vendas v ON r.venda_id = v.id
        WHERE r.usuario_id = ?
        ORDER BY 
            CASE WHEN r.status = 'pending' THEN 0 ELSE 1 END,
            r.data_solicitacao DESC
        LIMIT 100
    ");
    $stmt->execute([$usuario_id_logado]);
    $reembolsos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Erro ao buscar reembolsos: " . $e->getMessage());
    $reembolsos = [];
}
?>

<meta name="csrf-token" content="<?php echo htmlspecialchars($csrf_token_js); ?>">
<script>
    // Variável global para token CSRF
    window.csrfToken = '<?php echo htmlspecialchars($csrf_token_js); ?>';
</script>

<div class="container mx-auto relative">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-3xl font-bold text-white">Gerenciar Reembolsos</h1>
    </div>

    <!-- Cards de Estatísticas -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <div class="metric-card p-4 bg-dark-card rounded-lg shadow-md border-2" style="border-color: var(--accent-primary);">
            <h3 class="text-gray-400 text-sm font-medium">Total</h3>
            <p class="text-2xl font-bold text-white"><?php echo $reembolsos_stats['total']; ?></p>
        </div>
        <div class="metric-card p-4 bg-dark-card rounded-lg shadow-md border-2 border-yellow-500/50">
            <h3 class="text-gray-400 text-sm font-medium">Pendentes</h3>
            <p class="text-2xl font-bold text-yellow-400"><?php echo $reembolsos_stats['pending']; ?></p>
        </div>
        <div class="metric-card p-4 bg-dark-card rounded-lg shadow-md border-2 border-green-500/50">
            <h3 class="text-gray-400 text-sm font-medium">Aprovados</h3>
            <p class="text-2xl font-bold text-green-400"><?php echo $reembolsos_stats['approved']; ?></p>
        </div>
        <div class="metric-card p-4 bg-dark-card rounded-lg shadow-md border-2 border-red-500/50">
            <h3 class="text-gray-400 text-sm font-medium">Recusados</h3>
            <p class="text-2xl font-bold text-red-400"><?php echo $reembolsos_stats['rejected']; ?></p>
        </div>
    </div>

    <!-- Tabela de Reembolsos -->
    <div class="bg-dark-card p-6 rounded-lg shadow-md border" style="border-color: var(--accent-primary);">
        <?php if (empty($reembolsos)): ?>
            <div class="text-center py-12 text-gray-400">
                <i data-lucide="inbox" class="mx-auto w-16 h-16 text-gray-500"></i>
                <p class="mt-4">Nenhum reembolso encontrado.</p>
            </div>
        <?php else: ?>
            <!-- Tabela Desktop -->
            <div class="hidden md:block overflow-x-auto">
                <table class="min-w-full divide-y divide-dark-border">
                    <thead class="bg-dark-elevated">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Cliente</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Produto</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Valor</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Data Compra</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Data Solicitação</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Dias</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Status</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Ações</th>
                        </tr>
                    </thead>
                    <tbody class="bg-dark-card divide-y divide-dark-border">
                        <?php foreach ($reembolsos as $reembolso): 
                            $dias_desde_compra = (int)($reembolso['dias_desde_compra'] ?? 0);
                            $dentro_prazo = $dias_desde_compra <= 7;
                            $valor_formatado = 'R$ ' . number_format((float)$reembolso['valor'], 2, ',', '.');
                            $data_venda_formatada = date('d/m/Y H:i', strtotime($reembolso['data_venda']));
                            $data_solicitacao_formatada = date('d/m/Y H:i', strtotime($reembolso['data_solicitacao']));
                            
                            $status_class = '';
                            $status_text = '';
                            $status_icon = '';
                            switch ($reembolso['status']) {
                                case 'pending':
                                    $status_class = 'bg-yellow-500/20 text-yellow-400 border-yellow-500/50';
                                    $status_text = 'Pendente';
                                    $status_icon = 'clock';
                                    break;
                                case 'approved':
                                    $status_class = 'bg-green-500/20 text-green-400 border-green-500/50';
                                    $status_text = 'Aprovado';
                                    $status_icon = 'check-circle';
                                    break;
                                case 'rejected':
                                    $status_class = 'bg-red-500/20 text-red-400 border-red-500/50';
                                    $status_text = 'Recusado';
                                    $status_icon = 'x-circle';
                                    break;
                            }
                        ?>
                            <tr class="hover:bg-dark-elevated transition-colors">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-white"><?php echo htmlspecialchars($reembolso['comprador_nome']); ?></div>
                                    <div class="text-sm text-gray-400"><?php echo htmlspecialchars($reembolso['comprador_email']); ?></div>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="text-sm text-white"><?php echo htmlspecialchars($reembolso['produto_nome']); ?></div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-semibold text-white"><?php echo $valor_formatado; ?></div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-300"><?php echo $data_venda_formatada; ?></div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-300"><?php echo $data_solicitacao_formatada; ?></div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="px-2 py-1 text-xs font-semibold rounded-full <?php echo $dentro_prazo ? 'bg-green-500/20 text-green-400' : 'bg-red-500/20 text-red-400'; ?>">
                                        <?php echo $dias_desde_compra; ?> dias
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="px-2 py-1 text-xs font-semibold rounded-full border <?php echo $status_class; ?>">
                                        <i data-lucide="<?php echo $status_icon; ?>" class="w-3 h-3 inline mr-1"></i>
                                        <?php echo $status_text; ?>
                                    </span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                    <?php if ($reembolso['status'] === 'pending'): ?>
                                        <button 
                                            onclick="openRefundActionModal(<?php echo $reembolso['id']; ?>, 'approve', '<?php echo htmlspecialchars($reembolso['comprador_nome'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($reembolso['produto_nome'], ENT_QUOTES); ?>', <?php echo $dias_desde_compra; ?>)"
                                            class="mr-2 px-3 py-1 bg-green-600 text-white rounded hover:bg-green-700 transition-colors text-xs font-semibold">
                                            Aprovar
                                        </button>
                                        <button 
                                            onclick="openRefundActionModal(<?php echo $reembolso['id']; ?>, 'reject', '<?php echo htmlspecialchars($reembolso['comprador_nome'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($reembolso['produto_nome'], ENT_QUOTES); ?>', <?php echo $dias_desde_compra; ?>)"
                                            class="px-3 py-1 bg-red-600 text-white rounded hover:bg-red-700 transition-colors text-xs font-semibold">
                                            Recusar
                                        </button>
                                    <?php else: ?>
                                        <span class="text-gray-500 text-xs">Processado</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Cards Mobile -->
            <div class="md:hidden space-y-4">
                <?php foreach ($reembolsos as $reembolso): 
                    $dias_desde_compra = (int)($reembolso['dias_desde_compra'] ?? 0);
                    $dentro_prazo = $dias_desde_compra <= 7;
                    $valor_formatado = 'R$ ' . number_format((float)$reembolso['valor'], 2, ',', '.');
                    $data_venda_formatada = date('d/m/Y H:i', strtotime($reembolso['data_venda']));
                    $data_solicitacao_formatada = date('d/m/Y H:i', strtotime($reembolso['data_solicitacao']));
                    
                    $status_class = '';
                    $status_text = '';
                    switch ($reembolso['status']) {
                        case 'pending':
                            $status_class = 'bg-yellow-500/20 text-yellow-400 border-yellow-500/50';
                            $status_text = 'Pendente';
                            break;
                        case 'approved':
                            $status_class = 'bg-green-500/20 text-green-400 border-green-500/50';
                            $status_text = 'Aprovado';
                            break;
                        case 'rejected':
                            $status_class = 'bg-red-500/20 text-red-400 border-red-500/50';
                            $status_text = 'Recusado';
                            break;
                    }
                ?>
                    <div class="bg-dark-elevated p-4 rounded-lg border border-dark-border">
                        <div class="flex justify-between items-start mb-3">
                            <div>
                                <h3 class="text-white font-semibold"><?php echo htmlspecialchars($reembolso['comprador_nome']); ?></h3>
                                <p class="text-gray-400 text-sm"><?php echo htmlspecialchars($reembolso['comprador_email']); ?></p>
                            </div>
                            <span class="px-2 py-1 text-xs font-semibold rounded-full border <?php echo $status_class; ?>">
                                <?php echo $status_text; ?>
                            </span>
                        </div>
                        <div class="space-y-2 text-sm">
                            <p class="text-gray-300"><strong>Produto:</strong> <?php echo htmlspecialchars($reembolso['produto_nome']); ?></p>
                            <p class="text-white"><strong>Valor:</strong> <?php echo $valor_formatado; ?></p>
                            <p class="text-gray-300"><strong>Data Compra:</strong> <?php echo $data_venda_formatada; ?></p>
                            <p class="text-gray-300"><strong>Data Solicitação:</strong> <?php echo $data_solicitacao_formatada; ?></p>
                            <p class="text-gray-300">
                                <strong>Dias desde compra:</strong> 
                                <span class="px-2 py-1 text-xs rounded-full <?php echo $dentro_prazo ? 'bg-green-500/20 text-green-400' : 'bg-red-500/20 text-red-400'; ?>">
                                    <?php echo $dias_desde_compra; ?> dias
                                </span>
                            </p>
                            <?php if (!empty($reembolso['motivo'])): ?>
                                <div class="mt-2 p-2 bg-gray-800 rounded text-xs text-gray-300">
                                    <strong>Motivo:</strong> <?php echo nl2br(htmlspecialchars($reembolso['motivo'])); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php if ($reembolso['status'] === 'pending'): ?>
                            <div class="mt-4 flex space-x-2">
                                <button 
                                    onclick="openRefundActionModal(<?php echo $reembolso['id']; ?>, 'approve', '<?php echo htmlspecialchars($reembolso['comprador_nome'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($reembolso['produto_nome'], ENT_QUOTES); ?>', <?php echo $dias_desde_compra; ?>)"
                                    class="flex-1 px-3 py-2 bg-green-600 text-white rounded hover:bg-green-700 transition-colors text-sm font-semibold">
                                    Aprovar
                                </button>
                                <button 
                                    onclick="openRefundActionModal(<?php echo $reembolso['id']; ?>, 'reject', '<?php echo htmlspecialchars($reembolso['comprador_nome'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($reembolso['produto_nome'], ENT_QUOTES); ?>', <?php echo $dias_desde_compra; ?>)"
                                    class="flex-1 px-3 py-2 bg-red-600 text-white rounded hover:bg-red-700 transition-colors text-sm font-semibold">
                                    Recusar
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal de Aprovar/Recusar Reembolso -->
<div id="refund-action-modal" class="fixed inset-0 z-50 hidden overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
    <div class="flex items-end justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
        <div class="fixed inset-0 bg-gray-900 bg-opacity-75 transition-opacity" aria-hidden="true" onclick="closeRefundActionModal()"></div>
        <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
        <div class="inline-block align-bottom bg-dark-card rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full border" style="border-color: var(--accent-primary);">
            <div class="bg-dark-card px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                <div class="sm:flex sm:items-start">
                    <div class="mx-auto flex-shrink-0 flex items-center justify-center h-12 w-12 rounded-full sm:mx-0 sm:h-10 sm:w-10" id="refund-action-icon-container" style="background-color: color-mix(in srgb, var(--accent-primary) 20%, transparent);">
                        <i data-lucide="info" class="h-6 w-6" id="refund-action-icon" style="color: var(--accent-primary);"></i>
                    </div>
                    <div class="mt-3 text-center sm:mt-0 sm:ml-4 sm:text-left w-full">
                        <h3 class="text-lg leading-6 font-medium text-white" id="refund-action-title">Aprovar Reembolso</h3>
                        <div class="mt-2">
                            <div class="mb-4 p-3 bg-blue-900/20 border border-blue-500/50 rounded-lg">
                                <p class="text-sm text-blue-300">
                                    <i data-lucide="info" class="w-4 h-4 inline mr-2"></i>
                                    <strong>Importante:</strong> Por lei (CDC - Código de Defesa do Consumidor, Art. 49), o cliente tem direito a reembolso dentro de 7 dias corridos a partir da data da compra.
                                </p>
                            </div>
                            
                            <div class="mb-4">
                                <p class="text-sm text-gray-300 mb-2">
                                    <strong>Cliente:</strong> <span id="refund-action-cliente-name"></span>
                                </p>
                                <p class="text-sm text-gray-300 mb-2">
                                    <strong>Produto:</strong> <span id="refund-action-produto-name"></span>
                                </p>
                                <p class="text-sm text-gray-300 mb-2">
                                    <strong>Dias desde a compra:</strong> <span id="refund-action-dias" class="font-semibold"></span> dias
                                </p>
                            </div>
                            
                            <label for="refund-action-message" class="block text-sm font-medium text-gray-300 mb-2">
                                Mensagem para o Cliente <span class="text-gray-500">(opcional)</span>
                            </label>
                            <textarea 
                                id="refund-action-message" 
                                rows="4"
                                class="w-full px-4 py-2 bg-dark-elevated border border-dark-border rounded-lg text-white placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-purple-500"
                                placeholder="Deixe uma mensagem para o cliente..."></textarea>
                        </div>
                    </div>
                </div>
            </div>
            <div class="bg-dark-elevated px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
                <button 
                    type="button" 
                    id="confirm-refund-action-btn"
                    class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 text-base font-medium text-white focus:outline-none focus:ring-2 focus:ring-offset-2 sm:ml-3 sm:w-auto sm:text-sm"
                    style="background-color: var(--accent-primary);"
                    onmouseover="this.style.backgroundColor='var(--accent-primary-hover)'"
                    onmouseout="this.style.backgroundColor='var(--accent-primary)'">
                    Confirmar
                </button>
                <button 
                    type="button" 
                    id="cancel-refund-action-btn"
                    onclick="closeRefundActionModal()"
                    class="mt-3 w-full inline-flex justify-center rounded-md border border-dark-border shadow-sm px-4 py-2 bg-dark-card text-base font-medium text-gray-300 hover:bg-dark-elevated focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm">
                    Cancelar
                </button>
            </div>
        </div>
    </div>
</div>

<script>
let currentRefundId = null;
let currentAction = null; // 'approve' ou 'reject'

function openRefundActionModal(refundId, action, clienteNome, produtoNome, diasDesdeCompra) {
    currentRefundId = refundId;
    currentAction = action;
    
    const modal = document.getElementById('refund-action-modal');
    const title = document.getElementById('refund-action-title');
    const iconContainer = document.getElementById('refund-action-icon-container');
    const icon = document.getElementById('refund-action-icon');
    const clienteNameSpan = document.getElementById('refund-action-cliente-name');
    const produtoNameSpan = document.getElementById('refund-action-produto-name');
    const diasSpan = document.getElementById('refund-action-dias');
    const messageTextarea = document.getElementById('refund-action-message');
    const confirmBtn = document.getElementById('confirm-refund-action-btn');
    
    clienteNameSpan.textContent = clienteNome;
    produtoNameSpan.textContent = produtoNome;
    diasSpan.textContent = diasDesdeCompra;
    diasSpan.className = 'font-semibold ' + (diasDesdeCompra <= 7 ? 'text-green-400' : 'text-red-400');
    messageTextarea.value = '';
    
    if (action === 'approve') {
        title.textContent = 'Aprovar Reembolso';
        confirmBtn.textContent = 'Aprovar Reembolso';
        confirmBtn.style.backgroundColor = 'var(--accent-primary)';
        iconContainer.style.backgroundColor = 'color-mix(in srgb, #10b981 20%, transparent)';
        icon.setAttribute('data-lucide', 'check-circle');
        icon.style.color = '#10b981';
    } else {
        title.textContent = 'Recusar Reembolso';
        confirmBtn.textContent = 'Recusar Reembolso';
        confirmBtn.style.backgroundColor = '#dc2626';
        iconContainer.style.backgroundColor = 'color-mix(in srgb, #dc2626 20%, transparent)';
        icon.setAttribute('data-lucide', 'x-circle');
        icon.style.color = '#dc2626';
    }
    
    lucide.createIcons();
    modal.classList.remove('hidden');
}

function closeRefundActionModal() {
    document.getElementById('refund-action-modal').classList.add('hidden');
    currentRefundId = null;
    currentAction = null;
}

async function submitRefundAction() {
    if (!currentRefundId || !currentAction) {
        return;
    }
    
    const message = document.getElementById('refund-action-message').value.trim();
    const confirmBtn = document.getElementById('confirm-refund-action-btn');
    const originalText = confirmBtn.textContent;
    
    // Desabilitar botão
    confirmBtn.disabled = true;
    confirmBtn.textContent = 'Processando...';
    confirmBtn.classList.add('opacity-50', 'cursor-not-allowed');
    
    try {
        const response = await fetch('/api/refund_action.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': window.csrfToken || ''
            },
            body: JSON.stringify({
                refund_id: currentRefundId,
                action: currentAction,
                message: message || null,
                csrf_token: window.csrfToken || ''
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            alert(data.message || 'Ação processada com sucesso!');
            closeRefundActionModal();
            // Recarregar página para atualizar lista
            window.location.reload();
        } else {
            alert(data.error || 'Erro ao processar ação. Tente novamente.');
            confirmBtn.disabled = false;
            confirmBtn.textContent = originalText;
            confirmBtn.classList.remove('opacity-50', 'cursor-not-allowed');
        }
    } catch (error) {
        console.error('Erro ao processar ação:', error);
        alert('Erro ao processar ação. Tente novamente.');
        confirmBtn.disabled = false;
        confirmBtn.textContent = originalText;
        confirmBtn.classList.remove('opacity-50', 'cursor-not-allowed');
    }
}

// Event listeners
document.getElementById('confirm-refund-action-btn').addEventListener('click', submitRefundAction);
document.getElementById('cancel-refund-action-btn').addEventListener('click', closeRefundActionModal);

// Fechar modal ao pressionar ESC
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeRefundActionModal();
    }
});

// Inicializar ícones
document.addEventListener('DOMContentLoaded', function() {
    lucide.createIcons();
});
</script>

