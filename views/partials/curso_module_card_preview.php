<?php
// Partial: card de módulo para preview na gestão. Mesmo visual do aluno (capa, título, X aulas) com overlay de edição.
// Variáveis: $item (modulo + aulas), $produto_id, $csrf_token, $tipo_capa (opcional, padrão 'vertical').
if (empty($item) || !isset($item['modulo'], $item['aulas'])) return;
$module = $item['modulo'];
$tipo_capa = $tipo_capa ?? 'vertical'; // Padrão vertical se não definido
$imagem_capa = '';
if (!empty($module['imagem_capa_url'])) {
    $caminho_banco = $module['imagem_capa_url'];
    $file_path_absoluto = __DIR__ . '/../../' . $caminho_banco;
    if (file_exists($file_path_absoluto)) {
        $imagem_capa = '/' . $caminho_banco;
    }
}
$aspect_class = ($tipo_capa === 'horizontal') ? 'aspect-[842/327]' : 'aspect-[3/4]';
?>
<div class="module-card-preview group relative rounded-lg overflow-hidden border-2 border-gray-700 transition-all duration-300 text-left bg-gray-800" data-modulo-id="<?php echo (int)$module['id']; ?>">
    <div class="<?php echo htmlspecialchars($aspect_class); ?>">
        <?php if (!empty($imagem_capa)): ?>
            <img src="<?php echo htmlspecialchars($imagem_capa); ?>" alt="Capa do <?php echo htmlspecialchars($module['titulo']); ?>" class="w-full h-full object-cover transition-transform duration-300 group-hover:scale-110">
        <?php else: ?>
            <div class="w-full h-full bg-gray-700 flex items-center justify-center">
                <i data-lucide="image" class="w-12 h-12 text-gray-500"></i>
            </div>
        <?php endif; ?>
    </div>
    <div class="absolute inset-0 bg-gradient-to-t from-black/90 via-black/50 to-transparent"></div>
    <div class="absolute bottom-0 left-0 right-0 p-4">
        <h4 class="font-bold text-lg text-white"><?php echo htmlspecialchars($module['titulo']); ?></h4>
        <span class="text-xs" style="color: var(--accent-primary);"><?php echo count($item['aulas']); ?> aulas</span>
        <?php if (!empty($module['release_days'])): ?>
            <span class="block text-xs text-gray-400 mt-0.5">Liberado em <?php echo (int)$module['release_days']; ?> dias</span>
        <?php endif; ?>
    </div>
    <div class="absolute top-2 right-2 flex gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
        <button type="button" class="drawer-open-edit-module p-2 rounded-lg bg-white/90 text-gray-800 hover:bg-white shadow" title="Editar módulo"
            data-modulo-id="<?php echo (int)$module['id']; ?>"
            data-modulo-titulo="<?php echo htmlspecialchars($module['titulo']); ?>"
            data-imagem-url="<?php echo htmlspecialchars($module['imagem_capa_url'] ?? ''); ?>"
            data-release-days="<?php echo (int)($module['release_days'] ?? 0); ?>"
            data-is-paid-module="<?php echo !empty($module['is_paid_module']) ? '1' : '0'; ?>"
            data-linked-product-id="<?php echo htmlspecialchars($module['linked_product_id'] ?? ''); ?>"
            data-secao-id="<?php echo htmlspecialchars($module['secao_id'] ?? ''); ?>">
            <i data-lucide="edit" class="w-4 h-4"></i>
        </button>
        <button type="button" class="drawer-open-aulas-list px-2 py-1.5 rounded-lg text-white text-sm font-semibold shadow" style="background-color: var(--accent-primary);" title="Ver e gerenciar aulas"
            data-modulo-id="<?php echo (int)$module['id']; ?>"
            data-modulo-titulo="<?php echo htmlspecialchars($module['titulo']); ?>"
            data-aulas="<?php echo htmlspecialchars(json_encode(array_map(function($a) {
                return [
                    'id' => (int)$a['id'],
                    'titulo' => $a['titulo'] ?? '',
                    'url_video' => $a['url_video'] ?? '',
                    'descricao' => $a['descricao'] ?? '',
                    'release_days' => (int)($a['release_days'] ?? 0),
                    'tipo_conteudo' => $a['tipo_conteudo'] ?? 'video',
                    'download_link' => $a['download_link'] ?? '',
                    'termos_consentimento' => $a['termos_consentimento'] ?? '',
                    'files' => $a['files'] ?? []
                ];
            }, $item['aulas']))); ?>">
            <i data-lucide="list-video" class="w-4 h-4 inline mr-1"></i> Aulas
        </button>
        <form action="/index?pagina=gerenciar_curso&produto_id=<?php echo (int)$produto_id; ?>" method="post" onsubmit="return confirm('Deletar este módulo e todas as aulas?');" class="inline">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="modulo_id" value="<?php echo (int)$module['id']; ?>">
            <button type="submit" name="deletar_modulo" class="p-2 rounded-lg bg-red-500/90 text-white hover:bg-red-600 shadow" title="Excluir módulo">
                <i data-lucide="trash-2" class="w-4 h-4"></i>
            </button>
        </form>
    </div>
</div>
