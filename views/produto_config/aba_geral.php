<?php
// Aba Geral - Configurações básicas do produto
?>

<div class="space-y-4 md:space-y-6">
    <div>
        <h2 class="text-lg md:text-xl font-semibold mb-3 md:mb-4 text-white flex items-center gap-2">
            <i data-lucide="package" class="w-5 h-5" style="color: var(--accent-primary);"></i>
            Informações Básicas
        </h2>
        <div class="bg-dark-elevated p-4 md:p-6 rounded-lg border border-dark-border space-y-4">
            <div>
                <label for="nome" class="block text-gray-300 text-sm font-semibold mb-2">Nome do Produto</label>
                <input type="text" id="nome" name="nome" class="form-input" value="<?php echo htmlspecialchars($produto['nome']); ?>" required>
            </div>
            <div>
                <label for="descricao" class="block text-gray-300 text-sm font-semibold mb-2">Descrição</label>
                <textarea id="descricao" name="descricao" rows="4" class="form-input" placeholder="Descreva os benefícios do seu produto..."><?php echo htmlspecialchars($produto['descricao'] ?? ''); ?></textarea>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label for="preco" class="block text-gray-300 text-sm font-semibold mb-2">Preço (R$)</label>
                    <div class="relative">
                        <span class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-gray-400 font-bold z-10">R$</span>
                        <input type="number" step="0.01" id="preco" name="preco" class="form-input pl-10 w-full" value="<?php echo htmlspecialchars($produto['preco']); ?>" required style="padding-left: 2.75rem;">
                    </div>
                </div>
                <div>
                    <label for="preco_anterior" class="block text-gray-300 text-sm font-semibold mb-2">Preço Anterior (De)</label>
                    <input type="text" id="preco_anterior" name="preco_anterior" class="form-input w-full" placeholder="Ex: 99,90" value="<?php echo !empty($produto['preco_anterior']) ? htmlspecialchars(number_format($produto['preco_anterior'], 2, ',', '.')) : ''; ?>">
                    <p class="text-xs text-gray-400 mt-1">Deixe em branco para não exibir o preço cortado.</p>
                </div>
            </div>
        </div>
    </div>

    <div>
        <h2 class="text-lg md:text-xl font-semibold mb-3 md:mb-4 text-white flex items-center gap-2">
            <i data-lucide="image" class="w-5 h-5" style="color: var(--accent-primary);"></i>
            Capa do Produto
        </h2>
        <div class="bg-dark-elevated p-4 md:p-6 rounded-lg border border-dark-border">
            <div class="relative group">
                <div class="w-full h-48 md:h-64 bg-dark-card rounded-xl overflow-hidden border-2 border-dark-border border-dashed flex items-center justify-center relative">
                    <?php if (!empty($produto['foto'])): ?>
                        <img src="<?php echo $upload_dir . htmlspecialchars($produto['foto']); ?>" id="preview-img" class="absolute inset-0 w-full h-full object-cover">
                    <?php else: ?>
                        <img id="preview-img" class="absolute inset-0 w-full h-full object-cover hidden">
                        <div id="placeholder-img" class="text-center p-4">
                            <i data-lucide="image" class="w-12 h-12 text-gray-500 mx-auto mb-2"></i>
                            <p class="text-sm text-gray-400">Nenhuma imagem selecionada</p>
                        </div>
                    <?php endif; ?>
                    
                    <label for="foto" class="absolute inset-0 bg-black bg-opacity-0 group-hover:bg-opacity-40 transition-all duration-300 flex items-center justify-center cursor-pointer">
                        <span class="bg-dark-card text-white px-4 py-2 rounded-full shadow-lg font-medium text-sm transform scale-90 opacity-0 group-hover:scale-100 group-hover:opacity-100 transition-all">
                            <i data-lucide="camera" class="w-4 h-4 inline mr-1"></i> Alterar Capa
                        </span>
                    </label>
                </div>
                <input type="file" id="foto" name="foto" class="hidden" accept="image/png, image/jpeg, image/webp" onchange="previewImage(this)">
            </div>
            <p class="text-xs text-gray-400 mt-2 text-center">Recomendado: 800x800px (JPG/PNG/WebP)</p>
        </div>
    </div>

    <div>
        <h2 class="text-lg md:text-xl font-semibold mb-3 md:mb-4 text-white flex items-center gap-2">
            <i data-lucide="truck" class="w-5 h-5" style="color: var(--accent-primary);"></i>
            Configuração de Entrega
        </h2>
        <div class="bg-dark-elevated p-4 md:p-6 rounded-lg border border-dark-border space-y-4">
            <div>
                <label class="block text-gray-300 text-sm font-medium mb-3">Como o cliente receberá o produto?</label>
                <input type="hidden" id="tipo_entrega" name="tipo_entrega" value="<?php echo htmlspecialchars($produto['tipo_entrega'] ?? 'link'); ?>">
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                    <div class="entrega-option-card cursor-pointer p-4 rounded-lg border-2 transition-all duration-200 bg-dark-card hover:bg-dark-elevated <?php echo (($produto['tipo_entrega'] ?? 'link') == 'link') ? 'border-[var(--accent-primary)] bg-dark-elevated' : 'border-dark-border'; ?>" data-value="link" onclick="selectEntregaOption('link')">
                        <div class="flex flex-col items-center text-center space-y-2">
                            <div class="w-12 h-12 rounded-lg flex items-center justify-center mb-2" style="background-color: color-mix(in srgb, var(--accent-primary) 20%, transparent);">
                                <i data-lucide="link" class="w-6 h-6" style="color: var(--accent-primary);"></i>
                            </div>
                            <h3 class="font-semibold text-white text-sm">Link Externo</h3>
                            <p class="text-xs text-gray-400">Google Drive, Notion, etc</p>
                        </div>
                    </div>
                    
                    <div class="entrega-option-card cursor-pointer p-4 rounded-lg border-2 transition-all duration-200 bg-dark-card hover:bg-dark-elevated <?php echo (($produto['tipo_entrega'] ?? '') == 'email_pdf') ? 'border-[var(--accent-primary)] bg-dark-elevated' : 'border-dark-border'; ?>" data-value="email_pdf" onclick="selectEntregaOption('email_pdf')">
                        <div class="flex flex-col items-center text-center space-y-2">
                            <div class="w-12 h-12 rounded-lg flex items-center justify-center mb-2" style="background-color: color-mix(in srgb, var(--accent-primary) 20%, transparent);">
                                <i data-lucide="file-text" class="w-6 h-6" style="color: var(--accent-primary);"></i>
                            </div>
                            <h3 class="font-semibold text-white text-sm">Arquivo PDF</h3>
                            <p class="text-xs text-gray-400">Anexo no e-mail</p>
                        </div>
                    </div>
                    
                    <div class="entrega-option-card cursor-pointer p-4 rounded-lg border-2 transition-all duration-200 bg-dark-card hover:bg-dark-elevated <?php echo (($produto['tipo_entrega'] ?? '') == 'area_membros') ? 'border-[var(--accent-primary)] bg-dark-elevated' : 'border-dark-border'; ?>" data-value="area_membros" onclick="selectEntregaOption('area_membros')">
                        <div class="flex flex-col items-center text-center space-y-2">
                            <div class="w-12 h-12 rounded-lg flex items-center justify-center mb-2" style="background-color: color-mix(in srgb, var(--accent-primary) 20%, transparent);">
                                <i data-lucide="lock" class="w-6 h-6" style="color: var(--accent-primary);"></i>
                            </div>
                            <h3 class="font-semibold text-white text-sm">Área de Membros</h3>
                            <p class="text-xs text-gray-400">Acesso interno</p>
                        </div>
                    </div>
                    
                    <div class="entrega-option-card cursor-pointer p-4 rounded-lg border-2 transition-all duration-200 bg-dark-card hover:bg-dark-elevated <?php echo (($produto['tipo_entrega'] ?? '') == 'produto_fisico') ? 'border-[var(--accent-primary)] bg-dark-elevated' : 'border-dark-border'; ?>" data-value="produto_fisico" onclick="selectEntregaOption('produto_fisico')">
                        <div class="flex flex-col items-center text-center space-y-2">
                            <div class="w-12 h-12 rounded-lg flex items-center justify-center mb-2" style="background-color: color-mix(in srgb, var(--accent-primary) 20%, transparent);">
                                <i data-lucide="package" class="w-6 h-6" style="color: var(--accent-primary);"></i>
                            </div>
                            <h3 class="font-semibold text-white text-sm">Produto Físico</h3>
                            <p class="text-xs text-gray-400">Envio por correio</p>
                        </div>
                    </div>
                    
                    <div class="entrega-option-card cursor-pointer p-4 rounded-lg border-2 transition-all duration-200 bg-dark-card hover:bg-dark-elevated <?php echo (($produto['tipo_entrega'] ?? '') == 'somente_pagamento') ? 'border-[var(--accent-primary)] bg-dark-elevated' : 'border-dark-border'; ?>" data-value="somente_pagamento" onclick="selectEntregaOption('somente_pagamento')">
                        <div class="flex flex-col items-center text-center space-y-2">
                            <div class="w-12 h-12 rounded-lg flex items-center justify-center mb-2" style="background-color: color-mix(in srgb, var(--accent-primary) 20%, transparent);">
                                <i data-lucide="credit-card" class="w-6 h-6" style="color: var(--accent-primary);"></i>
                            </div>
                            <h3 class="font-semibold text-white text-sm">Somente link de pagamento</h3>
                            <p class="text-xs text-gray-400">Sem entrega automática</p>
                        </div>
                    </div>
                </div>
            </div>

            <div id="entrega-fields-container">
                <div id="entrega-link-container" class="animate-fade-in-down" style="display: <?php echo (($produto['tipo_entrega'] ?? '') === 'link') ? 'block' : 'none'; ?>;">
                    <label for="conteudo_entrega_link" class="block text-gray-300 text-sm font-medium mb-2">URL de Acesso</label>
                    <input type="url" id="conteudo_entrega_link" name="conteudo_entrega_link" class="form-input" placeholder="https://" value="<?php echo ($produto['tipo_entrega'] ?? '') === 'link' ? htmlspecialchars($produto['conteudo_entrega'] ?? '') : ''; ?>">
                </div>

                <div id="entrega-pdf-container" class="animate-fade-in-down" style="display: <?php echo (($produto['tipo_entrega'] ?? '') === 'email_pdf') ? 'block' : 'none'; ?>;">
                    <label class="block text-gray-300 text-sm font-medium mb-2">Upload do Arquivo PDF</label>
                    <?php if (($produto['tipo_entrega'] ?? '') == 'email_pdf' && !empty($produto['conteudo_entrega'])): ?>
                        <div class="flex items-center space-x-3 mb-3 p-3 bg-dark-card border border-dark-border rounded-lg shadow-sm">
                            <div class="bg-red-900/30 p-2 rounded-lg flex-shrink-0"><i data-lucide="file-text" class="w-5 h-5 text-red-400"></i></div>
                            <div class="flex-1 min-w-0">
                                <p class="text-xs text-gray-400">Arquivo Atual:</p>
                                <p class="text-sm font-medium text-white truncate"><?php echo htmlspecialchars($produto['conteudo_entrega']); ?></p>
                            </div>
                        </div>
                    <?php endif; ?>
                    <label class="flex flex-col items-center justify-center w-full h-28 md:h-32 border-2 border-dark-border border-dashed rounded-lg cursor-pointer bg-dark-card hover:bg-dark-elevated transition-colors">
                        <div class="flex flex-col items-center justify-center pt-4 md:pt-5 pb-4 md:pb-6 px-2">
                            <i data-lucide="upload-cloud" class="w-6 h-6 md:w-8 md:h-8 text-gray-400 mb-2"></i>
                            <p class="text-xs md:text-sm text-gray-400 text-center"><span class="font-semibold">Clique para enviar</span> ou arraste</p>
                            <p class="text-xs text-gray-400">PDF (MAX. 10MB)</p>
                        </div>
                        <input type="file" id="conteudo_entrega_pdf" name="conteudo_entrega_pdf" class="hidden" accept="application/pdf">
                    </label>
                    <div id="pdf-file-name" class="mt-2 text-sm text-gray-400 font-medium text-center hidden"></div>
                </div>

                <div id="entrega-membros-container" class="animate-fade-in-down" style="display: <?php echo (($produto['tipo_entrega'] ?? '') === 'area_membros') ? 'block' : 'none'; ?>;">
                    <div class="flex items-start p-3 md:p-4 bg-blue-900/20 border border-blue-500/30 rounded-lg">
                        <i data-lucide="info" class="w-5 h-5 text-blue-400 mt-0.5 mr-2 md:mr-3 flex-shrink-0"></i>
                        <div class="flex-1 min-w-0">
                            <h4 class="font-bold text-blue-300 text-sm">Integração Automática</h4>
                            <p class="text-xs md:text-sm text-blue-200 mt-1">O acesso será liberado automaticamente na área "Meus Cursos" do aluno após a confirmação do pagamento.</p>
                        </div>
                    </div>
                </div>

                <div id="entrega-fisico-container" class="animate-fade-in-down" style="display: <?php echo (($produto['tipo_entrega'] ?? '') === 'produto_fisico') ? 'block' : 'none'; ?>;">
                    <div class="flex items-start p-3 md:p-4 bg-green-900/20 border border-green-500/30 rounded-lg">
                        <i data-lucide="info" class="w-5 h-5 text-green-400 mt-0.5 mr-2 md:mr-3 flex-shrink-0"></i>
                        <div class="flex-1 min-w-0">
                            <h4 class="font-bold text-green-300 text-sm">Produto Físico</h4>
                            <p class="text-xs md:text-sm text-green-200 mt-1">O cliente precisará informar o endereço de entrega no checkout. Os dados serão salvos junto com a venda.</p>
                        </div>
                    </div>
                </div>

                <div id="entrega-pagamento-container" class="animate-fade-in-down" style="display: <?php echo (($produto['tipo_entrega'] ?? '') === 'somente_pagamento') ? 'block' : 'none'; ?>;">
                    <div class="flex items-start p-3 md:p-4 bg-yellow-900/20 border border-yellow-500/30 rounded-lg">
                        <i data-lucide="info" class="w-5 h-5 text-yellow-400 mt-0.5 mr-2 md:mr-3 flex-shrink-0"></i>
                        <div class="flex-1 min-w-0">
                            <h4 class="font-bold text-yellow-300 text-sm">Somente link de pagamento</h4>
                            <p class="text-xs md:text-sm text-yellow-200 mt-1">Nenhum email será enviado ao cliente após o pagamento ser aprovado. Use esta opção para produtos que são apenas links de checkout sem necessidade de entrega automática.</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Seção de Ofertas do Produto -->
    <div>
        <h2 class="text-lg md:text-xl font-semibold mb-3 md:mb-4 text-white flex items-center gap-2">
            <i data-lucide="tag" class="w-5 h-5" style="color: var(--accent-primary);"></i>
            Ofertas do Produto
        </h2>
        <div class="bg-dark-elevated p-4 md:p-6 rounded-lg border border-dark-border space-y-4">
            <div class="flex items-center justify-between mb-4">
                <p class="text-sm text-gray-400">Crie ofertas com preços diferentes para este produto. Cada oferta terá seu próprio link de checkout.</p>
                <button type="button" id="btn-adicionar-oferta" class="bg-[#32e768] hover:bg-[#28d15e] text-white font-semibold py-2 px-4 rounded-lg transition-all duration-300 flex items-center gap-2">
                    <i data-lucide="plus" class="w-4 h-4"></i>
                    Adicionar Oferta
                </button>
            </div>

            <!-- Lista de Ofertas Existentes -->
            <div id="ofertas-lista" class="space-y-3">
                <?php
                // Buscar ofertas do produto
                try {
                    $stmt_ofertas = $pdo->prepare("SELECT * FROM produto_ofertas WHERE produto_id = ? ORDER BY created_at DESC");
                    $stmt_ofertas->execute([$id_produto]);
                    $ofertas = $stmt_ofertas->fetchAll(PDO::FETCH_ASSOC);
                } catch (PDOException $e) {
                    // Se a tabela não existir, mostrar mensagem
                    $ofertas = [];
                    if (strpos($e->getMessage(), "doesn't exist") !== false || strpos($e->getMessage(), "não existe") !== false) {
                        echo "<div class='bg-yellow-900/20 border border-yellow-500 text-yellow-300 px-4 py-3 rounded relative mb-4' role='alert'>⚠️ A tabela produto_ofertas não existe. Execute o arquivo SQL de migração primeiro.</div>";
                    }
                }
                
                if (empty($ofertas)):
                ?>
                    <div class="text-center py-8 text-gray-400">
                        <i data-lucide="tag" class="w-12 h-12 mx-auto mb-2 opacity-50"></i>
                        <p class="text-sm">Nenhuma oferta criada ainda.</p>
                        <p class="text-xs mt-1">Clique em "Adicionar Oferta" para criar uma nova oferta.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($ofertas as $oferta): 
                        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
                        $domainName = $_SERVER['HTTP_HOST'];
                        $oferta_link = $protocol . $domainName . '/checkout?p=' . $oferta['checkout_hash'];
                    ?>
                        <div class="oferta-item bg-dark-card p-4 rounded-lg border border-dark-border" data-oferta-id="<?php echo $oferta['id']; ?>">
                            <div class="flex items-start justify-between gap-4">
                                <div class="flex-1">
                                    <div class="flex items-center gap-3 mb-2">
                                        <h3 class="font-semibold text-white"><?php echo htmlspecialchars($oferta['nome']); ?></h3>
                                        <?php if ($oferta['is_active']): ?>
                                            <span class="bg-green-900/30 text-green-400 text-xs font-bold px-2 py-0.5 rounded border border-green-500/50">Ativa</span>
                                        <?php else: ?>
                                            <span class="bg-gray-900/30 text-gray-400 text-xs font-bold px-2 py-0.5 rounded border border-gray-500/50">Inativa</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="flex items-center gap-4 text-sm">
                                        <div>
                                            <span class="text-gray-400">Preço:</span>
                                            <span class="text-white font-bold ml-1">R$ <?php echo number_format($oferta['preco'], 2, ',', '.'); ?></span>
                                        </div>
                                        <div class="flex items-center gap-2">
                                            <input type="text" readonly value="<?php echo htmlspecialchars($oferta_link); ?>" class="form-input text-xs flex-1 max-w-xs bg-dark-elevated" id="oferta-link-<?php echo $oferta['id']; ?>">
                                            <button type="button" class="copy-oferta-link text-[#32e768] hover:text-[#28d15e] transition-colors" data-link-id="oferta-link-<?php echo $oferta['id']; ?>">
                                                <i data-lucide="copy" class="w-4 h-4"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <div class="flex items-center gap-2">
                                    <button type="button" class="editar-oferta text-blue-400 hover:text-blue-300 transition-colors" data-oferta-id="<?php echo $oferta['id']; ?>" data-oferta-nome="<?php echo htmlspecialchars($oferta['nome']); ?>" data-oferta-preco="<?php echo $oferta['preco']; ?>">
                                        <i data-lucide="edit" class="w-4 h-4"></i>
                                    </button>
                                    <button type="button" class="toggle-oferta text-yellow-400 hover:text-yellow-300 transition-colors" data-oferta-id="<?php echo $oferta['id']; ?>" data-oferta-active="<?php echo $oferta['is_active']; ?>">
                                        <i data-lucide="<?php echo $oferta['is_active'] ? 'eye-off' : 'eye'; ?>" class="w-4 h-4"></i>
                                    </button>
                                    <button type="button" class="excluir-oferta text-red-400 hover:text-red-300 transition-colors" data-oferta-id="<?php echo $oferta['id']; ?>" data-oferta-nome="<?php echo htmlspecialchars($oferta['nome']); ?>">
                                        <i data-lucide="trash-2" class="w-4 h-4"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

</div>

<script>
function previewImage(input) {
    if (input.files && input.files[0]) {
        var reader = new FileReader();
        reader.onload = function(e) {
            const preview = document.getElementById('preview-img');
            const placeholder = document.getElementById('placeholder-img');
            preview.src = e.target.result;
            preview.classList.remove('hidden');
            if (placeholder) placeholder.classList.add('hidden');
        }
        reader.readAsDataURL(input.files[0]);
    }
}

function selectEntregaOption(value) {
    const hiddenInput = document.getElementById('tipo_entrega');
    const cards = document.querySelectorAll('.entrega-option-card');
    const linkContainer = document.getElementById('entrega-link-container');
    const pdfContainer = document.getElementById('entrega-pdf-container');
    const membrosContainer = document.getElementById('entrega-membros-container');
    const fisicoContainer = document.getElementById('entrega-fisico-container');
    const pagamentoContainer = document.getElementById('entrega-pagamento-container');
    
    // Atualiza o valor do input hidden
    hiddenInput.value = value;
    
    // Remove seleção de todos os cards
    cards.forEach(card => {
        card.classList.remove('border-[var(--accent-primary)]', 'bg-dark-elevated');
        card.classList.add('border-dark-border', 'bg-dark-card');
    });
    
    // Adiciona seleção no card clicado
    const selectedCard = document.querySelector(`.entrega-option-card[data-value="${value}"]`);
    if (selectedCard) {
        selectedCard.classList.remove('border-dark-border', 'bg-dark-card');
        selectedCard.classList.add('border-[var(--accent-primary)]', 'bg-dark-elevated');
    }
    
    // Esconde todos os containers
    linkContainer.style.display = 'none';
    pdfContainer.style.display = 'none';
    membrosContainer.style.display = 'none';
    fisicoContainer.style.display = 'none';
    if (pagamentoContainer) pagamentoContainer.style.display = 'none';
    
    // Mostra o container correspondente
    if (value === 'link') {
        linkContainer.style.display = 'block';
    } else if (value === 'email_pdf') {
        pdfContainer.style.display = 'block';
    } else if (value === 'area_membros') {
        membrosContainer.style.display = 'block';
    } else if (value === 'produto_fisico') {
        fisicoContainer.style.display = 'block';
    } else if (value === 'somente_pagamento') {
        if (pagamentoContainer) pagamentoContainer.style.display = 'block';
    }
}

function toggleEntregaFields() {
    const hiddenInput = document.getElementById('tipo_entrega');
    selectEntregaOption(hiddenInput.value);
}

document.getElementById('conteudo_entrega_pdf')?.addEventListener('change', function(e) {
    const fileName = e.target.files[0] ? e.target.files[0].name : '';
    const display = document.getElementById('pdf-file-name');
    if (fileName && display) {
        display.textContent = 'Arquivo selecionado: ' + fileName;
        display.classList.remove('hidden');
    } else if (display) {
        display.classList.add('hidden');
    }
});

// Gerenciamento de Ofertas
document.addEventListener('DOMContentLoaded', () => {
    console.log('Inicializando gerenciamento de ofertas...');
    const modal = document.getElementById('modal-oferta');
    const btnAdicionar = document.getElementById('btn-adicionar-oferta');
    const btnFechar = document.getElementById('fechar-modal-oferta');
    const btnCancelar = document.getElementById('cancelar-oferta');
    // Tentar encontrar o formulário usando getElementById e querySelector como fallback
    const formOferta = document.getElementById('form-oferta') || document.querySelector('#form-oferta');
    const tituloModal = document.getElementById('modal-oferta-titulo');
    const inputOfertaId = document.getElementById('oferta-id');
    const inputOfertaNome = document.getElementById('oferta-nome');
    const inputOfertaPreco = document.getElementById('oferta-preco');
    
    console.log('Elementos encontrados:', {
        modal: !!modal,
        btnAdicionar: !!btnAdicionar,
        formOferta: !!formOferta,
        inputOfertaNome: !!inputOfertaNome,
        inputOfertaPreco: !!inputOfertaPreco
    });

    // Abrir modal para criar nova oferta
    btnAdicionar?.addEventListener('click', () => {
        inputOfertaId.value = '';
        inputOfertaNome.value = '';
        inputOfertaPreco.value = '';
        tituloModal.textContent = 'Adicionar Oferta';
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    });

    // Fechar modal
    const fecharModal = () => {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
        formOferta.reset();
        inputOfertaId.value = '';
    };

    btnFechar?.addEventListener('click', fecharModal);
    btnCancelar?.addEventListener('click', fecharModal);

    // Editar oferta
    document.querySelectorAll('.editar-oferta').forEach(btn => {
        btn.addEventListener('click', function() {
            const ofertaId = this.getAttribute('data-oferta-id');
            const ofertaNome = this.getAttribute('data-oferta-nome');
            const ofertaPreco = this.getAttribute('data-oferta-preco');
            
            inputOfertaId.value = ofertaId;
            inputOfertaNome.value = ofertaNome;
            inputOfertaPreco.value = ofertaPreco;
            tituloModal.textContent = 'Editar Oferta';
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        });
    });

    // Toggle ativar/desativar oferta
    document.querySelectorAll('.toggle-oferta').forEach(btn => {
        btn.addEventListener('click', function() {
            const ofertaId = this.getAttribute('data-oferta-id');
            const isActive = this.getAttribute('data-oferta-active') === '1';
            
            if (confirm(`Deseja ${isActive ? 'desativar' : 'ativar'} esta oferta?`)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.style.display = 'none';
                
                const csrfToken = document.querySelector('input[name="csrf_token"]');
                if (csrfToken) {
                    const csrfInput = document.createElement('input');
                    csrfInput.type = 'hidden';
                    csrfInput.name = 'csrf_token';
                    csrfInput.value = csrfToken.value;
                    form.appendChild(csrfInput);
                }
                
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'toggle_oferta';
                actionInput.value = ofertaId;
                form.appendChild(actionInput);
                
                document.body.appendChild(form);
                form.submit();
            }
        });
    });

    // Excluir oferta
    document.querySelectorAll('.excluir-oferta').forEach(btn => {
        btn.addEventListener('click', function() {
            const ofertaId = this.getAttribute('data-oferta-id');
            const ofertaNome = this.getAttribute('data-oferta-nome');
            
            if (confirm(`Tem certeza que deseja excluir a oferta "${ofertaNome}"? Esta ação não pode ser desfeita.`)) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.style.display = 'none';
                
                const csrfToken = document.querySelector('input[name="csrf_token"]');
                if (csrfToken) {
                    const csrfInput = document.createElement('input');
                    csrfInput.type = 'hidden';
                    csrfInput.name = 'csrf_token';
                    csrfInput.value = csrfToken.value;
                    form.appendChild(csrfInput);
                }
                
                const actionInput = document.createElement('input');
                actionInput.type = 'hidden';
                actionInput.name = 'excluir_oferta';
                actionInput.value = ofertaId;
                form.appendChild(actionInput);
                
                document.body.appendChild(form);
                form.submit();
            }
        });
    });

    // Copiar link da oferta
    document.querySelectorAll('.copy-oferta-link').forEach(btn => {
        btn.addEventListener('click', function() {
            const linkId = this.getAttribute('data-link-id');
            const linkInput = document.getElementById(linkId);
            if (linkInput) {
                linkInput.select();
                document.execCommand('copy');
                
                const icon = this.querySelector('i');
                const originalClass = icon.getAttribute('data-lucide');
                icon.setAttribute('data-lucide', 'check');
                lucide.createIcons();
                
                setTimeout(() => {
                    icon.setAttribute('data-lucide', originalClass);
                    lucide.createIcons();
                }, 2000);
            }
        });
    });

    // Submeter formulário de oferta
    if (formOferta) {
        console.log('Registrando evento submit no formulário de oferta');
        formOferta.addEventListener('submit', function(e) {
            console.log('Evento submit capturado!');
            e.preventDefault();
            e.stopPropagation();
            
            const ofertaId = inputOfertaId.value;
            const ofertaNome = inputOfertaNome.value.trim();
            const ofertaPreco = parseFloat(inputOfertaPreco.value);
            
            console.log('Tentando salvar oferta:', { ofertaId, ofertaNome, ofertaPreco });
        
        // Validações
        if (ofertaNome.length < 3) {
            alert('O nome da oferta deve ter no mínimo 3 caracteres.');
            return;
        }
        
        if (!ofertaPreco || ofertaPreco <= 0 || isNaN(ofertaPreco)) {
            alert('O preço deve ser maior que zero.');
            return;
        }
        
        // Criar formulário para envio
        const form = document.createElement('form');
        form.method = 'POST';
        // Usar a URL correta do index com os parâmetros
        const urlParams = new URLSearchParams(window.location.search);
        const produtoId = urlParams.get('id') || '';
        const aba = urlParams.get('aba') || 'geral';
        form.action = '/index?pagina=produto_config&id=' + produtoId + '&aba=' + aba;
        form.style.display = 'none';
        
        console.log('URL do formulário:', form.action);
        
        const csrfToken = document.querySelector('input[name="csrf_token"]');
        if (csrfToken) {
            const csrfInput = document.createElement('input');
            csrfInput.type = 'hidden';
            csrfInput.name = 'csrf_token';
            csrfInput.value = csrfToken.value;
            form.appendChild(csrfInput);
            console.log('CSRF token adicionado');
        } else {
            console.error('CSRF token não encontrado!');
            alert('Erro: Token de segurança não encontrado. Recarregue a página e tente novamente.');
            return;
        }
        
        if (ofertaId) {
            // Editar oferta existente
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'editar_oferta';
            actionInput.value = ofertaId;
            form.appendChild(actionInput);
            console.log('Editando oferta:', ofertaId);
        } else {
            // Criar nova oferta
            const actionInput = document.createElement('input');
            actionInput.type = 'hidden';
            actionInput.name = 'criar_oferta';
            actionInput.value = '1';
            form.appendChild(actionInput);
            console.log('Criando nova oferta');
        }
        
        const nomeInput = document.createElement('input');
        nomeInput.type = 'hidden';
        nomeInput.name = 'oferta_nome';
        nomeInput.value = ofertaNome;
        form.appendChild(nomeInput);
        
        const precoInput = document.createElement('input');
        precoInput.type = 'hidden';
        precoInput.name = 'oferta_preco';
        precoInput.value = ofertaPreco;
        form.appendChild(precoInput);
        
        console.log('Formulário criado, campos:', {
            criar_oferta: !ofertaId ? '1' : undefined,
            editar_oferta: ofertaId || undefined,
            oferta_nome: ofertaNome,
            oferta_preco: ofertaPreco
        });
        
        document.body.appendChild(form);
        console.log('Submetendo formulário...');
        form.submit();
        });
    } else {
        console.error('Formulário de oferta não encontrado!');
    }
});
</script>

