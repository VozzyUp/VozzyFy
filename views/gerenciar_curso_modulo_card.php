<?php
// Partial: card de um módulo (usa $item com 'modulo' e 'aulas'). Requer $produto_id, $csrf_token no escopo.
if (empty($item) || !isset($item['modulo'], $item['aulas'])) return;
?>
<div class="bg-dark-card rounded-lg shadow-md overflow-hidden border border-dark-border">
    <div class="bg-dark-elevated p-4 flex justify-between items-center border-b border-dark-border">
        <div class="flex items-center gap-4">
            <?php if (!empty($item['modulo']['imagem_capa_url']) && file_exists($item['modulo']['imagem_capa_url'])): ?>
                <img src="<?php echo htmlspecialchars($item['modulo']['imagem_capa_url']); ?>" alt="Capa do módulo" class="w-24 h-16 object-cover rounded-md border border-dark-border">
            <?php else: ?>
                <div class="w-24 h-16 bg-dark-card rounded-md flex items-center justify-center border border-dark-border">
                    <i data-lucide="image-off" class="w-8 h-8 text-gray-500"></i>
                </div>
            <?php endif; ?>
            <div>
                <h3 class="text-xl font-bold text-white">
                    <?php echo htmlspecialchars($item['modulo']['titulo']); ?>
                    <?php if ($item['modulo']['release_days'] > 0): ?>
                        <span class="ml-2 text-sm font-medium" style="color: var(--accent-primary);">(Liberado em <?php echo $item['modulo']['release_days']; ?> dias)</span>
                    <?php endif; ?>
                </h3>
                <?php if ($item['modulo']['is_paid_module'] && $item['modulo']['produto_atrelado']): ?>
                    <div class="mt-1 flex items-center gap-2">
                        <span class="inline-flex items-center px-2 py-1 rounded text-xs font-semibold" style="background-color: var(--accent-primary); color: white;">
                            <i data-lucide="dollar-sign" class="w-3 h-3 mr-1"></i>
                            Módulo Pago
                        </span>
                        <span class="text-xs text-gray-400">
                            Produto: <?php echo htmlspecialchars($item['modulo']['produto_atrelado']['nome']); ?> - R$ <?php echo number_format($item['modulo']['produto_atrelado']['preco'], 2, ',', '.'); ?>
                        </span>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <div class="flex items-center space-x-2">
             <button class="add-lesson-btn text-sm text-white font-semibold py-2 px-4 rounded-lg transition flex items-center space-x-1" style="background-color: var(--accent-primary);" onmouseover="this.style.backgroundColor='var(--accent-primary-hover)'" onmouseout="this.style.backgroundColor='var(--accent-primary)'" data-modulo-id="<?php echo $item['modulo']['id']; ?>" data-modulo-titulo="<?php echo htmlspecialchars($item['modulo']['titulo']); ?>">
                <i data-lucide="plus-circle" class="w-4 h-4"></i>
                <span>Nova Aula</span>
            </button>
            <button class="edit-module-btn p-2 rounded-lg bg-yellow-500 text-white hover:bg-yellow-600 transition"
                data-modulo-id="<?php echo $item['modulo']['id']; ?>"
                data-modulo-titulo="<?php echo htmlspecialchars($item['modulo']['titulo']); ?>"
                data-imagem-url="<?php echo htmlspecialchars($item['modulo']['imagem_capa_url'] ?? ''); ?>"
                data-release-days="<?php echo htmlspecialchars($item['modulo']['release_days']); ?>"
                data-is-paid-module="<?php echo $item['modulo']['is_paid_module'] ? '1' : '0'; ?>"
                data-linked-product-id="<?php echo htmlspecialchars($item['modulo']['linked_product_id'] ?? ''); ?>"
                data-secao-id="<?php echo htmlspecialchars($item['modulo']['secao_id'] ?? ''); ?>">
                <i data-lucide="edit" class="w-5 h-5"></i>
            </button>
            <form action="/index?pagina=gerenciar_curso&produto_id=<?php echo (int)$produto_id; ?>" method="post" onsubmit="return confirm('Tem certeza que deseja deletar este módulo e todas as suas aulas?');">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="modulo_id" value="<?php echo $item['modulo']['id']; ?>">
                <button type="submit" name="deletar_modulo" class="text-white bg-red-500 p-2 rounded-lg hover:bg-red-600 transition">
                    <i data-lucide="trash-2" class="w-5 h-5"></i>
                </button>
            </form>
        </div>
    </div>
    <div class="p-4">
        <?php if (empty($item['aulas'])): ?>
            <p class="text-gray-400 text-center py-4">Nenhuma aula neste módulo.</p>
        <?php else: ?>
            <ul class="space-y-3 sortable-aulas" data-modulo-id="<?php echo $item['modulo']['id']; ?>">
                <?php foreach ($item['aulas'] as $aula): ?>
                    <li class="flex justify-between items-center p-3 bg-dark-elevated rounded-md border border-dark-border hover:bg-dark-card aula-item" data-aula-id="<?php echo $aula['id']; ?>">
                        <div class="flex items-center space-x-3 cursor-grab">
                            <i data-lucide="grip-vertical" class="w-5 h-5 text-gray-500 flex-shrink-0"></i>
                            <?php if ($aula['tipo_conteudo'] === 'video' || $aula['tipo_conteudo'] === 'mixed'): ?>
                                <i data-lucide="play-circle" class="w-5 h-5 text-gray-400 flex-shrink-0"></i>
                            <?php endif; ?>
                            <?php if ($aula['tipo_conteudo'] === 'files' || $aula['tipo_conteudo'] === 'mixed'): ?>
                                <i data-lucide="file-text" class="w-5 h-5 text-gray-400 flex-shrink-0"></i>
                            <?php endif; ?>
                            <?php if ($aula['tipo_conteudo'] === 'download_protegido'): ?>
                                <i data-lucide="lock" class="w-5 h-5 text-yellow-400 flex-shrink-0"></i>
                            <?php endif; ?>
                            <span class="font-medium text-gray-300">
                                <?php echo htmlspecialchars($aula['titulo']); ?>
                                <?php if ($aula['release_days'] > 0): ?>
                                    <span class="ml-2 text-sm font-medium" style="color: var(--accent-primary);">(Liberada em <?php echo $aula['release_days']; ?> dias)</span>
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="flex items-center space-x-2 flex-shrink-0">
                            <button class="edit-lesson-btn text-blue-400 hover:text-blue-300 p-1 rounded-full"
                                data-aula-id="<?php echo $aula['id']; ?>"
                                data-titulo="<?php echo htmlspecialchars($aula['titulo']); ?>"
                                data-url-video="<?php echo htmlspecialchars($aula['url_video'] ?? ''); ?>"
                                data-descricao="<?php echo htmlspecialchars($aula['descricao'] ?? ''); ?>"
                                data-release-days="<?php echo htmlspecialchars($aula['release_days']); ?>"
                                data-tipo-conteudo="<?php echo htmlspecialchars($aula['tipo_conteudo']); ?>"
                                data-download-link="<?php echo htmlspecialchars($aula['download_link'] ?? ''); ?>"
                                data-termos-consentimento="<?php echo htmlspecialchars($aula['termos_consentimento'] ?? ''); ?>"
                                data-files='<?php echo json_encode($aula['files']); ?>'>
                                <i data-lucide="edit" class="w-5 h-5"></i>
                            </button>
                            <form action="/index?pagina=gerenciar_curso&produto_id=<?php echo (int)$produto_id; ?>" method="post" onsubmit="return confirm('Tem certeza que deseja deletar esta aula?');" class="inline-block">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                <input type="hidden" name="aula_id" value="<?php echo $aula['id']; ?>">
                                <button type="submit" name="deletar_aula" class="text-red-400 hover:text-red-300 p-1 rounded-full">
                                    <i data-lucide="x-circle" class="w-5 h-5"></i>
                                </button>
                            </form>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>
