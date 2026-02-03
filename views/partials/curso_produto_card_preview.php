<?php
// Partial: card de produto para preview na gestão. Visual igual ao card de módulo (capa, título) com overlay de edição.
// Variáveis: $sp (produto da seção com produto_id, imagem_capa_url, nome, preco, foto), $secao_id, $produto_id (curso), $csrf_token, $tipo_capa (opcional, padrão 'vertical').
if (empty($sp) || !isset($sp['produto_id'])) return;

$tipo_capa = $tipo_capa ?? 'vertical'; // Padrão vertical se não definido

// Determinar imagem de capa: prioridade para imagem_capa_url da seção, depois foto do produto, depois placeholder
$imagem_capa = '';
if (!empty($sp['imagem_capa_url'])) {
    $caminho_banco = $sp['imagem_capa_url'];
    $file_path_absoluto = __DIR__ . '/../../' . $caminho_banco;
    if (file_exists($file_path_absoluto)) {
        $imagem_capa = '/' . $caminho_banco;
    }
} elseif (!empty($sp['foto'])) {
    $caminho_foto = 'uploads/' . $sp['foto'];
    $file_path_absoluto = __DIR__ . '/../../' . $caminho_foto;
    if (file_exists($file_path_absoluto)) {
        $imagem_capa = '/' . $caminho_foto;
    }
}
$aspect_class = ($tipo_capa === 'horizontal') ? 'aspect-[842/327]' : 'aspect-[3/4]';
?>
<div class="product-card-preview group relative rounded-lg overflow-hidden border-2 border-gray-700 transition-all duration-300 text-left bg-gray-800" data-produto-secao-id="<?php echo (int)$sp['produto_id']; ?>" data-secao-id="<?php echo (int)$secao_id; ?>">
    <div class="<?php echo htmlspecialchars($aspect_class); ?>">
        <?php if (!empty($imagem_capa)): ?>
            <img src="<?php echo htmlspecialchars($imagem_capa); ?>" alt="Capa do <?php echo htmlspecialchars($sp['nome']); ?>" class="w-full h-full object-cover transition-transform duration-300 group-hover:scale-110">
        <?php else: ?>
            <div class="w-full h-full bg-gray-700 flex items-center justify-center">
                <i data-lucide="package" class="w-12 h-12 text-gray-500"></i>
            </div>
        <?php endif; ?>
    </div>
    <div class="absolute inset-0 bg-gradient-to-t from-black/90 via-black/50 to-transparent"></div>
    <div class="absolute bottom-0 left-0 right-0 p-4">
        <h4 class="font-bold text-lg text-white"><?php echo htmlspecialchars($sp['nome']); ?></h4>
    </div>
    <div class="absolute top-2 right-2 flex gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
        <button type="button" class="drawer-open-edit-produto-secao p-2 rounded-lg bg-white/90 text-gray-800 hover:bg-white shadow" title="Editar produto"
            data-produto-id="<?php echo (int)$sp['produto_id']; ?>"
            data-secao-id="<?php echo (int)$secao_id; ?>"
            data-produto-nome="<?php echo htmlspecialchars($sp['nome']); ?>"
            data-imagem-url="<?php echo htmlspecialchars($sp['imagem_capa_url'] ?? ''); ?>"
            data-link-personalizado="<?php echo htmlspecialchars($sp['link_personalizado'] ?? ''); ?>">
            <i data-lucide="edit" class="w-4 h-4"></i>
        </button>
        <form action="/index?pagina=gerenciar_curso&produto_id=<?php echo (int)$produto_id; ?>" method="post" onsubmit="return confirm('Remover este produto da seção?');" class="inline">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="secao_id" value="<?php echo (int)$secao_id; ?>">
            <input type="hidden" name="produto_id" value="<?php echo (int)$sp['produto_id']; ?>">
            <button type="submit" name="secao_remover_produto" class="p-2 rounded-lg bg-red-500/90 text-white hover:bg-red-600 shadow" title="Remover produto">
                <i data-lucide="trash-2" class="w-4 h-4"></i>
            </button>
        </form>
    </div>
</div>

