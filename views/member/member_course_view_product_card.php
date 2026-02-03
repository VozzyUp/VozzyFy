<?php
// Partial: card de produto para visualização do aluno. Visual igual ao card de módulo (capa, título) com link para checkout ou acesso.
// Variáveis: $prod (produto com id, nome, preco, imagem_capa_url, foto, tem_acesso, checkout_hash), $upload_dir, $tipo_capa (opcional, padrão 'vertical')
if (empty($prod) || !isset($prod['id'])) return;

$tipo_capa = $tipo_capa ?? 'vertical'; // Padrão vertical se não definido

// Determinar imagem de capa: prioridade para imagem_capa_url da seção, depois foto do produto, depois placeholder
$imagem_capa = '';
if (!empty($prod['imagem_capa_url'])) {
    $caminho_banco = $prod['imagem_capa_url'];
    $file_path_absoluto = __DIR__ . '/../../' . $caminho_banco;
    if (file_exists($file_path_absoluto)) {
        $imagem_capa = '/' . $caminho_banco;
    }
} elseif (!empty($prod['foto'])) {
    $caminho_foto = $upload_dir . $prod['foto'];
    $file_path_absoluto = __DIR__ . '/../../' . $caminho_foto;
    if (file_exists($file_path_absoluto)) {
        $imagem_capa = '/' . $caminho_foto;
    }
}
$aspect_class = ($tipo_capa === 'horizontal') ? 'aspect-[842/327]' : 'aspect-[3/4]';
$min_height_class = ($tipo_capa === 'horizontal') ? '' : 'min-h-[200px] md:min-h-[260px]';

// Determinar link e texto do botão
$produto_link = '#';
$botao_texto = 'Ver Produto';
$botao_estilo = 'bg-gray-700 hover:bg-gray-600';

if (!empty($prod['tem_acesso'])) {
    $produto_link = '/member_course_view?produto_id=' . (int)$prod['id'];
    $botao_texto = 'Acessar';
    $botao_estilo = 'text-white';
    $botao_estilo_inline = 'background-color: var(--accent-primary);';
} elseif (!empty($prod['link_personalizado'])) {
    // Usar link personalizado se existir
    $produto_link = htmlspecialchars($prod['link_personalizado']);
    $botao_texto = 'Comprar';
    $botao_estilo = 'text-white';
    $botao_estilo_inline = 'background-color: var(--accent-primary);';
} elseif (!empty($prod['checkout_hash'])) {
    // Usar checkout_hash padrão se não houver link personalizado
    $produto_link = '/checkout.php?p=' . htmlspecialchars($prod['checkout_hash']);
    $botao_texto = 'Comprar';
    $botao_estilo = 'text-white';
    $botao_estilo_inline = 'background-color: var(--accent-primary);';
}
?>
<a href="<?php echo htmlspecialchars($produto_link); ?>" target="_blank" rel="noopener noreferrer" class="module-card group relative rounded-lg overflow-hidden transition-all duration-300 text-left w-full block">
    <div class="<?php echo htmlspecialchars($aspect_class); ?> <?php echo htmlspecialchars($min_height_class); ?> overflow-hidden">
        <?php if (!empty($imagem_capa)): ?>
            <img src="<?php echo htmlspecialchars($imagem_capa); ?>" alt="Capa do <?php echo htmlspecialchars($prod['nome']); ?>" class="w-full h-full object-cover">
        <?php else: ?>
            <div class="w-full h-full bg-gray-700 flex items-center justify-center <?php echo htmlspecialchars($min_height_class); ?>">
                <i data-lucide="package" class="w-14 h-14 md:w-16 md:h-16 text-gray-500"></i>
            </div>
        <?php endif; ?>
    </div>
    <div class="absolute inset-0 bg-gradient-to-t from-black/90 via-black/50 to-transparent"></div>
    <div class="absolute bottom-0 left-0 right-0 p-4">
        <h4 class="font-bold text-lg text-white"><?php echo htmlspecialchars($prod['nome']); ?></h4>
    </div>
    <?php if (empty($prod['tem_acesso'])): ?>
    <div class="absolute top-3 right-3">
        <div class="w-10 h-10 bg-white rounded-full flex items-center justify-center shadow-lg">
            <i data-lucide="lock" class="w-5 h-5 text-gray-700"></i>
        </div>
    </div>
    <?php endif; ?>
</a>

