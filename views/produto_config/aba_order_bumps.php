<?php
// Aba Order Bumps - Gerenciamento de ofertas adicionais
?>

<div class="space-y-6">
    <div>
        <h2 class="text-xl font-semibold mb-4 text-white flex items-center gap-2">
            <i data-lucide="gift" class="w-5 h-5" style="color: var(--accent-primary);"></i>
            Order Bumps
        </h2>
        <div class="bg-dark-elevated p-6 rounded-lg border border-dark-border space-y-4">
            
            <?php if($current_gateway == 'pushinpay'): ?>
                <div class="bg-blue-900/20 border-l-4 border-blue-500 p-4 mb-4 rounded">
                    <p class="text-sm text-blue-300">Listando apenas produtos configurados com <strong class="text-blue-200">PushinPay</strong>.</p>
                </div>
            <?php elseif($current_gateway == 'efi'): ?>
                <div class="bg-purple-900/20 border-l-4 border-purple-500 p-4 mb-4 rounded">
                    <p class="text-sm text-purple-300">Listando apenas produtos configurados com <strong class="text-purple-200">Efí</strong>.</p>
                </div>
            <?php else: ?>
                <div class="bg-blue-900/20 border-l-4 border-blue-500 p-4 mb-4 rounded">
                    <p class="text-sm text-blue-300">Listando apenas produtos configurados com <strong class="text-blue-200">Mercado Pago</strong>.</p>
                </div>
            <?php endif; ?>

            <div id="order-bumps-container" class="space-y-4">
                <?php foreach ($order_bumps as $index => $bump): ?>
                    <div class="order-bump-item p-4 border border-dark-border rounded-lg bg-dark-card" data-index="<?php echo $index; ?>">
                        <div class="flex justify-between items-center mb-3 cursor-grab">
                            <h3 class="font-bold text-white flex items-center gap-2">
                                <i data-lucide="grip-vertical" class="w-5 h-5 text-gray-400"></i>
                                Oferta #<?php echo $index + 1; ?>
                            </h3>
                            <button type="button" class="remove-order-bump text-red-400 hover:text-red-300 transition-colors">
                                <i data-lucide="trash-2" class="w-5 h-5"></i>
                            </button>
                        </div>
                        <div class="space-y-3">
                            <div>
                                <label class="block text-gray-300 text-sm font-semibold mb-2">Produto da Oferta</label>
                                <select name="orderbump_product_id[]" class="form-input">
                                    <option value="">-- Selecione um produto --</option>
                                    <?php foreach ($lista_produtos_orderbump as $prod_ob): ?>
                                        <option value="<?php echo $prod_ob['id']; ?>" <?php echo ($bump['offer_product_id'] == $prod_ob['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($prod_ob['nome']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label class="block text-gray-300 text-sm font-semibold mb-2">Título da Oferta</label>
                                <input type="text" name="orderbump_headline[]" value="<?php echo htmlspecialchars($bump['headline']); ?>" class="form-input" placeholder="Ex: Sim, eu quero aproveitar essa oferta!">
                            </div>
                            <div>
                                <label class="block text-gray-300 text-sm font-semibold mb-2">Descrição da Oferta</label>
                                <textarea name="orderbump_description[]" rows="3" class="form-input" placeholder="Descreva os benefícios desta oferta..."><?php echo htmlspecialchars($bump['description']); ?></textarea>
                            </div>
                            <div class="flex items-center">
                                <input type="checkbox" name="orderbump_is_active[<?php echo $index; ?>]" value="1" class="form-checkbox" <?php echo ($bump['is_active'] ?? true) ? 'checked' : ''; ?>>
                                <label class="ml-2 text-sm text-gray-300">Ativar esta oferta</label>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <button type="button" id="add-order-bump" class="w-full bg-dark-card text-gray-300 font-semibold py-3 px-4 rounded-lg hover:text-white transition duration-300 flex items-center justify-center gap-2 border border-dark-border" onmouseover="this.style.backgroundColor='var(--accent-primary)'" onmouseout="this.style.backgroundColor=''">
                <i data-lucide="plus-circle" class="w-5 h-5"></i>
                Adicionar Oferta
            </button>
        </div>
    </div>
</div>

<div id="order-bump-template" style="display: none;">
    <div class="order-bump-item p-4 border border-dark-border rounded-lg bg-dark-card" data-index="NEW_INDEX">
        <div class="flex justify-between items-center mb-3 cursor-grab">
            <h3 class="font-bold text-white flex items-center gap-2">
                <i data-lucide="grip-vertical" class="w-5 h-5 text-gray-400"></i>
                Nova Oferta
            </h3>
            <button type="button" class="remove-order-bump text-red-400 hover:text-red-300 transition-colors">
                <i data-lucide="trash-2" class="w-5 h-5"></i>
            </button>
        </div>
        <div class="space-y-3">
            <div>
                <label class="block text-gray-300 text-sm font-semibold mb-2">Produto da Oferta</label>
                <select name="orderbump_product_id[]" class="form-input">
                    <option value="">-- Selecione um produto --</option>
                    <?php foreach ($lista_produtos_orderbump as $prod_ob): ?>
                        <option value="<?php echo $prod_ob['id']; ?>"><?php echo htmlspecialchars($prod_ob['nome']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-gray-300 text-sm font-semibold mb-2">Título da Oferta</label>
                <input type="text" name="orderbump_headline[]" value="Sim, eu quero aproveitar essa oferta!" class="form-input">
            </div>
            <div>
                <label class="block text-gray-300 text-sm font-semibold mb-2">Descrição da Oferta</label>
                <textarea name="orderbump_description[]" rows="3" class="form-input"></textarea>
            </div>
            <div class="flex items-center">
                <input type="checkbox" name="orderbump_is_active[NEW_INDEX]" value="1" class="form-checkbox" checked>
                <label class="ml-2 text-sm text-gray-300">Ativar esta oferta</label>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const container = document.getElementById('order-bumps-container');
    const addButton = document.getElementById('add-order-bump');
    const template = document.getElementById('order-bump-template');

    const updateBumpIndices = () => {
        container.querySelectorAll('.order-bump-item').forEach((item, index) => {
            item.querySelector('h3').innerHTML = `<i data-lucide="grip-vertical" class="w-5 h-5 text-gray-400"></i> Oferta #${index + 1}`;
            const checkbox = item.querySelector('input[type="checkbox"]');
            if(checkbox) {
                checkbox.name = `orderbump_is_active[${index}]`;
            }
        });
        lucide.createIcons();
    };

    addButton.addEventListener('click', () => {
        const newIndex = container.querySelectorAll('.order-bump-item').length;
        const tempDiv = document.createElement('div');
        tempDiv.innerHTML = template.innerHTML.replace(/NEW_INDEX/g, newIndex);
        
        const clone = tempDiv.firstElementChild;
        container.appendChild(clone);
        updateBumpIndices();
        lucide.createIcons();
    });

    container.addEventListener('click', (e) => {
        const removeButton = e.target.closest('.remove-order-bump');
        if (removeButton) {
            removeButton.closest('.order-bump-item').remove();
            updateBumpIndices();
        }
    });

    new Sortable(container, {
        animation: 150,
        handle: '.cursor-grab',
        ghostClass: 'sortable-ghost',
        onEnd: () => {
            updateBumpIndices();
        }
    });
});
</script>

<style>
.sortable-ghost { opacity: 0.4; background: color-mix(in srgb, var(--accent-primary) 20%, transparent); }
</style>

