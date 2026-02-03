<?php
// Partial: card de módulo para member_course_view. Variáveis: $module, $item, $idx, $is_module_locked, $module_button_classes, $tipo_capa (opcional, padrão 'vertical')
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
$min_height_class = ($tipo_capa === 'horizontal') ? '' : 'min-h-[200px] md:min-h-[260px]';
?>
<button class="<?php echo $module_button_classes; ?>"
        data-module-id="<?php echo (int)$module['id']; ?>"
        data-module-index="<?php echo (int)$idx; ?>"
        <?php echo $is_module_locked ? 'disabled' : ''; ?>
>
    <div class="<?php echo htmlspecialchars($aspect_class); ?> <?php echo htmlspecialchars($min_height_class); ?>">
        <?php if (!empty($imagem_capa)): ?>
            <img src="<?php echo htmlspecialchars($imagem_capa); ?>" alt="Capa do <?php echo htmlspecialchars($module['titulo']); ?>" class="w-full h-full object-cover transition-transform duration-300 group-hover:scale-110">
        <?php else: ?>
            <div class="w-full h-full bg-gray-700 flex items-center justify-center <?php echo htmlspecialchars($min_height_class); ?>">
                <i data-lucide="image" class="w-14 h-14 md:w-16 md:h-16 text-gray-500"></i>
            </div>
        <?php endif; ?>
    </div>
    <?php if ($tipo_capa !== 'horizontal'): ?>
    <div class="absolute inset-0 bg-gradient-to-t from-black/90 via-black/50 to-transparent"></div>
    <div class="absolute bottom-0 left-0 right-0 p-4">
        <h4 class="font-bold text-lg text-white"><?php echo htmlspecialchars($module['titulo']); ?></h4>
        <?php if ($is_module_locked): ?>
            <?php if ($module['lock_reason'] === 'paid_module_no_access' && !empty($module['produto_atrelado']['checkout_hash'])): ?>
                <?php
                $product_link = '/checkout?p=' . htmlspecialchars($module['produto_atrelado']['checkout_hash']);
                if (!empty($module['produto_atrelado']['checkout_config'])) {
                    $prod_checkout_config = json_decode($module['produto_atrelado']['checkout_config'], true);
                    if (isset($prod_checkout_config['blocked_product_link_type']) && $prod_checkout_config['blocked_product_link_type'] === 'sales_page' && !empty($prod_checkout_config['blocked_product_sales_page_url'])) {
                        $product_link = $prod_checkout_config['blocked_product_sales_page_url'];
                    }
                }
                ?>
                <div class="mt-2 space-y-2">
                    <span class="text-xs text-yellow-400 flex items-center"><i data-lucide="dollar-sign" class="w-4 h-4 mr-1"></i> Módulo Pago</span>
                    <div class="text-xs text-white">
                        <p class="font-semibold"><?php echo htmlspecialchars($module['produto_atrelado']['nome']); ?></p>
                        <p class="text-yellow-400">R$ <?php echo number_format($module['produto_atrelado']['preco'], 2, ',', '.'); ?></p>
                    </div>
                    <a href="<?php echo htmlspecialchars($product_link); ?>" class="block w-full mt-2 text-white font-bold py-2 px-4 rounded-lg transition text-center text-sm" style="background-color: var(--accent-primary);" onclick="event.stopPropagation();"><i data-lucide="shopping-cart" class="w-4 h-4 inline-block mr-1"></i> Comprar Produto</a>
                </div>
            <?php elseif ($module['lock_reason'] === 'paid_module_no_access' && !empty($module['produto_atrelado'])): ?>
                <div class="mt-2 space-y-2">
                    <span class="text-xs text-yellow-400 flex items-center"><i data-lucide="dollar-sign" class="w-4 h-4 mr-1"></i> Módulo Pago</span>
                    <div class="text-xs text-white">
                        <p class="font-semibold"><?php echo htmlspecialchars($module['produto_atrelado']['nome']); ?></p>
                        <p class="text-red-400">Checkout não configurado para este produto.</p>
                    </div>
                </div>
            <?php else: ?>
                <span class="text-xs text-red-400 flex items-center mt-1"><i data-lucide="lock" class="w-4 h-4 mr-1"></i> Disponível em: <?php echo htmlspecialchars($module['available_at']); ?></span>
            <?php endif; ?>
        <?php else: ?>
            <span class="text-xs" style="color: var(--accent-primary);"><?php echo count($item['aulas']); ?> aulas</span>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</button>
