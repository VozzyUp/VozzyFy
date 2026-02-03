<?php
/**
 * Plugin de Recorrência
 * 
 * Adiciona funcionalidade de pagamento recorrente aos produtos.
 * 
 * @package Recorrencia
 * @version 1.0.0
 */

// Previne acesso direto
if (!defined('PLUGIN_LOADED')) {
    return;
}

// Debug: Verificar se plugin está sendo carregado
error_log("RECORRENCIA: Plugin de Recorrência sendo carregado...");

// ==================== HELPER FUNCTIONS ====================

/**
 * Cria uma assinatura para um produto recorrente
 */
function recorrencia_criar_assinatura($produto_id, $venda_id, $comprador_email, $comprador_nome) {
    global $pdo;
    
    try {
        // Buscar informações do produto
        $stmt_produto = $pdo->prepare("SELECT tipo_pagamento, intervalo_recorrencia FROM produtos WHERE id = ?");
        $stmt_produto->execute([$produto_id]);
        $produto = $stmt_produto->fetch(PDO::FETCH_ASSOC);
        
        if (!$produto || $produto['tipo_pagamento'] !== 'recorrente') {
            return false;
        }
        
        // Buscar informações da venda
        $stmt_venda = $pdo->prepare("SELECT data_venda FROM vendas WHERE id = ?");
        $stmt_venda->execute([$venda_id]);
        $venda = $stmt_venda->fetch(PDO::FETCH_ASSOC);
        
        if (!$venda) {
            return false;
        }
        
        // Calcular próxima cobrança (30 dias a partir da data da venda)
        $data_venda = new DateTime($venda['data_venda']);
        $data_venda->modify('+30 days');
        $proxima_cobranca = $data_venda->format('Y-m-d');
        
        // Criar assinatura
        $stmt_insert = $pdo->prepare("
            INSERT INTO assinaturas (produto_id, comprador_email, comprador_nome, venda_inicial_id, proxima_cobranca, status)
            VALUES (?, ?, ?, ?, ?, 'ativa')
        ");
        $stmt_insert->execute([$produto_id, $comprador_email, $comprador_nome, $venda_id, $proxima_cobranca]);
        $assinatura_id = $pdo->lastInsertId();
        
        // Atualizar venda com assinatura_id
        $stmt_update = $pdo->prepare("UPDATE vendas SET assinatura_id = ? WHERE id = ?");
        $stmt_update->execute([$assinatura_id, $venda_id]);
        
        // Se tipo entrega = area_membros, atualizar alunos_acessos
        $stmt_tipo = $pdo->prepare("SELECT tipo_entrega FROM produtos WHERE id = ?");
        $stmt_tipo->execute([$produto_id]);
        $tipo_entrega = $stmt_tipo->fetchColumn();
        
        if ($tipo_entrega === 'area_membros') {
            $data_expiracao = $proxima_cobranca;
            $stmt_acesso = $pdo->prepare("
                UPDATE alunos_acessos 
                SET data_expiracao = ?, assinatura_id = ?
                WHERE produto_id = ? AND aluno_email = ?
            ");
            $stmt_acesso->execute([$data_expiracao, $assinatura_id, $produto_id, $comprador_email]);
        }
        
        error_log("RECORRENCIA: Assinatura #{$assinatura_id} criada para produto #{$produto_id}, venda #{$venda_id}");
        return $assinatura_id;
        
    } catch (PDOException $e) {
        error_log("RECORRENCIA: Erro ao criar assinatura - " . $e->getMessage());
        return false;
    }
}

/**
 * Processa renovação de assinatura quando pagamento é aprovado
 */
function recorrencia_processar_renovacao($venda_id, $assinatura_id) {
    global $pdo;
    
    try {
        // Buscar assinatura
        $stmt_ass = $pdo->prepare("SELECT * FROM assinaturas WHERE id = ?");
        $stmt_ass->execute([$assinatura_id]);
        $assinatura = $stmt_ass->fetch(PDO::FETCH_ASSOC);
        
        if (!$assinatura || $assinatura['status'] !== 'ativa') {
            return false;
        }
        
        // Atualizar próxima cobrança (+30 dias)
        $hoje = new DateTime();
        $hoje->modify('+30 days');
        $nova_proxima_cobranca = $hoje->format('Y-m-d');
        
        $stmt_update = $pdo->prepare("
            UPDATE assinaturas 
            SET proxima_cobranca = ?, ultima_cobranca = ?, status = 'ativa'
            WHERE id = ?
        ");
        $stmt_update->execute([$nova_proxima_cobranca, date('Y-m-d'), $assinatura_id]);
        
        // Se tipo entrega = area_membros, atualizar alunos_acessos
        $stmt_produto = $pdo->prepare("SELECT tipo_entrega FROM produtos WHERE id = ?");
        $stmt_produto->execute([$assinatura['produto_id']]);
        $tipo_entrega = $stmt_produto->fetchColumn();
        
        if ($tipo_entrega === 'area_membros') {
            $stmt_acesso = $pdo->prepare("
                UPDATE alunos_acessos 
                SET data_expiracao = ?
                WHERE produto_id = ? AND aluno_email = ?
            ");
            $stmt_acesso->execute([$nova_proxima_cobranca, $assinatura['produto_id'], $assinatura['comprador_email']]);
        }
        
        error_log("RECORRENCIA: Renovação processada para assinatura #{$assinatura_id}");
        return true;
        
    } catch (PDOException $e) {
        error_log("RECORRENCIA: Erro ao processar renovação - " . $e->getMessage());
        return false;
    }
}

// ==================== HOOKS ====================

// Debug: Verificar se hooks estão sendo registrados
error_log("RECORRENCIA: Registrando hooks...");

/**
 * Adiciona campo de tipo de pagamento no formulário de produto via JavaScript
 * Funciona na página de produtos (criar/editar)
 */
add_action('product_form_after', function() {
    // Debug: Verificar se hook está sendo executado
    error_log("RECORRENCIA: Hook product_form_after executado! Pagina: " . ($_GET['pagina'] ?? 'nenhuma'));
    // Verificar se está na página de produtos (criar/editar)
    $is_produtos_page = isset($_GET['pagina']) && $_GET['pagina'] === 'produtos';
    
    if (!$is_produtos_page) {
        return;
    }
    
    global $pdo;
    $tipo_pagamento = 'unico'; // Default
    $checked_unico = $tipo_pagamento === 'unico' ? 'checked' : '';
    $checked_recorrente = $tipo_pagamento === 'recorrente' ? 'checked' : '';
    
    // Buscar tipo_pagamento se estiver editando
    if (isset($_GET['editar'])) {
        $produto_id = intval($_GET['editar']);
        try {
            $stmt = $pdo->prepare("SELECT tipo_pagamento FROM produtos WHERE id = ?");
            $stmt->execute([$produto_id]);
            $tipo_pagamento = $stmt->fetchColumn() ?: 'unico';
            $checked_unico = $tipo_pagamento === 'unico' ? 'checked' : '';
            $checked_recorrente = $tipo_pagamento === 'recorrente' ? 'checked' : '';
        } catch (PDOException $e) {
            $tipo_pagamento = 'unico';
        }
    }
    
    // Adicionar campo via JavaScript após o campo de preço
    ?>
    <script>
    (function() {
        let tentativas = 0;
        const maxTentativas = 50; // 5 segundos máximo (50 * 100ms)
        
        function adicionarCampoTipoPagamento() {
            tentativas++;
            
            // Verificar se o campo já foi adicionado
            if (document.querySelector('input[name="tipo_pagamento"]')) {
                console.log('RECORRENCIA: Campo tipo_pagamento já existe');
                return;
            }
            
            // Encontrar o campo de preço
            const precoField = document.getElementById('preco');
            if (!precoField) {
                if (tentativas < maxTentativas) {
                    setTimeout(adicionarCampoTipoPagamento, 100);
                } else {
                    console.error('RECORRENCIA: Campo de preço não encontrado após várias tentativas');
                }
                return;
            }
            
            // Encontrar o container do grid de preço
            const precoContainer = precoField.closest('.grid.grid-cols-1.md\\:grid-cols-2');
            if (!precoContainer) {
                if (tentativas < maxTentativas) {
                    setTimeout(adicionarCampoTipoPagamento, 100);
                } else {
                    console.error('RECORRENCIA: Container do grid não encontrado');
                }
                return;
            }
            
            console.log('RECORRENCIA: Adicionando campo tipo_pagamento...');
            
            // Criar campo de tipo de pagamento (mesmo padrão visual da plataforma)
            const tipoPagamentoDiv = document.createElement('div');
            tipoPagamentoDiv.className = 'mt-4';
            tipoPagamentoDiv.innerHTML = `
                <label class="block text-gray-300 text-sm font-semibold mb-2">Tipo de Pagamento</label>
                <div class="flex flex-wrap gap-4">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="radio" name="tipo_pagamento" value="unico" class="w-4 h-4 cursor-pointer" style="accent-color: var(--accent-primary);" <?php echo $checked_unico; ?>>
                        <span class="text-gray-300">Pagamento Único</span>
                    </label>
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="radio" name="tipo_pagamento" value="recorrente" class="w-4 h-4 cursor-pointer" style="accent-color: var(--accent-primary);" <?php echo $checked_recorrente; ?>>
                        <span class="text-gray-300">Recorrente (mensal)</span>
                    </label>
                </div>
                <p class="text-xs text-gray-400 mt-1">Selecione se o produto é pago uma única vez ou se é uma assinatura mensal.</p>
            `;
            
            // Adicionar após o grid de preço
            precoContainer.parentNode.insertBefore(tipoPagamentoDiv, precoContainer.nextSibling);
            
            console.log('RECORRENCIA: Campo tipo_pagamento adicionado com sucesso!');
        }
        
        // Executar quando DOM estiver pronto ou imediatamente
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function() {
                console.log('RECORRENCIA: DOM carregado, iniciando adição do campo...');
                adicionarCampoTipoPagamento();
            });
        } else {
            console.log('RECORRENCIA: DOM já carregado, iniciando adição do campo...');
            adicionarCampoTipoPagamento();
        }
    })();
    </script>
    <?php
});

// Hook para admin.php (painel admin)
add_action('admin_footer', function() {
    // Só adiciona se estiver na página de configuração de produto
    if (!isset($_GET['pagina']) || $_GET['pagina'] !== 'produto_config' || !isset($_GET['id'])) {
        return;
    }
    
    global $pdo;
    $produto_id = intval($_GET['id']);
    
    // Buscar tipo_pagamento do produto
    try {
        $stmt = $pdo->prepare("SELECT tipo_pagamento FROM produtos WHERE id = ?");
        $stmt->execute([$produto_id]);
        $tipo_pagamento = $stmt->fetchColumn() ?: 'unico';
    } catch (PDOException $e) {
        $tipo_pagamento = 'unico';
    }
    
    $checked_unico = $tipo_pagamento === 'unico' ? 'checked' : '';
    $checked_recorrente = $tipo_pagamento === 'recorrente' ? 'checked' : '';
    
    ?>
    <script>
    (function() {
        let tentativas = 0;
        const maxTentativas = 50;
        
        function adicionarCampoTipoPagamentoProdutoConfig() {
            tentativas++;
            
            // Verificar se o campo já foi adicionado
            if (document.querySelector('input[name="tipo_pagamento"]')) {
                console.log('RECORRENCIA (produto_config): Campo tipo_pagamento já existe');
                return;
            }
            
            // Verificar se estamos na aba geral
            const urlParams = new URLSearchParams(window.location.search);
            const aba = urlParams.get('aba') || 'geral';
            
            if (aba !== 'geral') {
                return;
            }
            
            // Encontrar o campo de preço
            const precoField = document.getElementById('preco');
            if (!precoField) {
                if (tentativas < maxTentativas) {
                    setTimeout(adicionarCampoTipoPagamentoProdutoConfig, 100);
                } else {
                    console.error('RECORRENCIA (produto_config): Campo de preço não encontrado');
                }
                return;
            }
            
            // Encontrar o container do grid de preço
            const precoContainer = precoField.closest('.grid.grid-cols-1.md\\:grid-cols-2');
            if (!precoContainer) {
                if (tentativas < maxTentativas) {
                    setTimeout(adicionarCampoTipoPagamentoProdutoConfig, 100);
                } else {
                    console.error('RECORRENCIA (produto_config): Container do grid não encontrado');
                }
                return;
            }
            
            console.log('RECORRENCIA (produto_config): Adicionando campo tipo_pagamento...');
            
            // Criar campo de tipo de pagamento (mesmo padrão visual da plataforma)
            const tipoPagamentoDiv = document.createElement('div');
            tipoPagamentoDiv.className = 'mt-4';
            tipoPagamentoDiv.innerHTML = `
                <label class="block text-gray-300 text-sm font-semibold mb-2">Tipo de Pagamento</label>
                <div class="flex flex-wrap gap-4">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="radio" name="tipo_pagamento" value="unico" class="w-4 h-4 cursor-pointer" style="accent-color: var(--accent-primary);" <?php echo $checked_unico; ?>>
                        <span class="text-gray-300">Pagamento Único</span>
                    </label>
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="radio" name="tipo_pagamento" value="recorrente" class="w-4 h-4 cursor-pointer" style="accent-color: var(--accent-primary);" <?php echo $checked_recorrente; ?>>
                        <span class="text-gray-300">Recorrente (mensal)</span>
                    </label>
                </div>
                <p class="text-xs text-gray-400 mt-1">Selecione se o produto é pago uma única vez ou se é uma assinatura mensal.</p>
            `;
            
            // Adicionar após o grid de preço
            precoContainer.parentNode.insertBefore(tipoPagamentoDiv, precoContainer.nextSibling);
            
            console.log('RECORRENCIA (produto_config): Campo tipo_pagamento adicionado com sucesso!');
        }
        
        // Executar quando DOM estiver pronto ou imediatamente
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function() {
                console.log('RECORRENCIA (produto_config): DOM carregado, iniciando adição do campo...');
                adicionarCampoTipoPagamentoProdutoConfig();
            });
        } else {
            console.log('RECORRENCIA (produto_config): DOM já carregado, iniciando adição do campo...');
            adicionarCampoTipoPagamentoProdutoConfig();
        }
    })();
    </script>
    <?php
});

// Hook para index.php (dashboard do usuário/infoprodutor) - página produto_config
// IMPORTANTE: produto_config é carregado pelo index.php, não pelo admin.php
add_action('dashboard_footer', function() {
    // Só adiciona se estiver na página de configuração de produto
    if (!isset($_GET['pagina']) || $_GET['pagina'] !== 'produto_config' || !isset($_GET['id'])) {
        return;
    }
    
    global $pdo;
    $produto_id = intval($_GET['id']);
    
    // Buscar tipo_pagamento do produto
    try {
        $stmt = $pdo->prepare("SELECT tipo_pagamento FROM produtos WHERE id = ?");
        $stmt->execute([$produto_id]);
        $tipo_pagamento = $stmt->fetchColumn() ?: 'unico';
    } catch (PDOException $e) {
        $tipo_pagamento = 'unico';
    }
    
    $checked_unico = $tipo_pagamento === 'unico' ? 'checked' : '';
    $checked_recorrente = $tipo_pagamento === 'recorrente' ? 'checked' : '';
    
    ?>
    <script>
    (function() {
        let tentativas = 0;
        const maxTentativas = 50;
        
        function adicionarCampoTipoPagamentoProdutoConfig() {
            tentativas++;
            
            // Verificar se o campo já foi adicionado
            if (document.querySelector('input[name="tipo_pagamento"]')) {
                console.log('RECORRENCIA (produto_config): Campo tipo_pagamento já existe');
                return;
            }
            
            // Verificar se estamos na aba geral
            const urlParams = new URLSearchParams(window.location.search);
            const aba = urlParams.get('aba') || 'geral';
            
            if (aba !== 'geral') {
                return;
            }
            
            // Encontrar o campo de preço
            const precoField = document.getElementById('preco');
            if (!precoField) {
                if (tentativas < maxTentativas) {
                    setTimeout(adicionarCampoTipoPagamentoProdutoConfig, 100);
                } else {
                    console.error('RECORRENCIA (produto_config): Campo de preço não encontrado');
                }
                return;
            }
            
            // Encontrar o container do grid de preço
            const precoContainer = precoField.closest('.grid.grid-cols-1.md\\:grid-cols-2');
            if (!precoContainer) {
                if (tentativas < maxTentativas) {
                    setTimeout(adicionarCampoTipoPagamentoProdutoConfig, 100);
                } else {
                    console.error('RECORRENCIA (produto_config): Container do grid não encontrado');
                }
                return;
            }
            
            console.log('RECORRENCIA (produto_config): Adicionando campo tipo_pagamento...');
            
            // Criar campo de tipo de pagamento (mesmo padrão visual da plataforma)
            const tipoPagamentoDiv = document.createElement('div');
            tipoPagamentoDiv.className = 'mt-4';
            tipoPagamentoDiv.innerHTML = `
                <label class="block text-gray-300 text-sm font-semibold mb-2">Tipo de Pagamento</label>
                <div class="flex flex-wrap gap-4">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="radio" name="tipo_pagamento" value="unico" class="w-4 h-4 cursor-pointer" style="accent-color: var(--accent-primary);" <?php echo $checked_unico; ?>>
                        <span class="text-gray-300">Pagamento Único</span>
                    </label>
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="radio" name="tipo_pagamento" value="recorrente" class="w-4 h-4 cursor-pointer" style="accent-color: var(--accent-primary);" <?php echo $checked_recorrente; ?>>
                        <span class="text-gray-300">Recorrente (mensal)</span>
                    </label>
                </div>
                <p class="text-xs text-gray-400 mt-1">Selecione se o produto é pago uma única vez ou se é uma assinatura mensal.</p>
            `;
            
            // Adicionar após o grid de preço
            precoContainer.parentNode.insertBefore(tipoPagamentoDiv, precoContainer.nextSibling);
            
            console.log('RECORRENCIA (produto_config): Campo tipo_pagamento adicionado com sucesso!');
        }
        
        // Executar quando DOM estiver pronto ou imediatamente
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', function() {
                console.log('RECORRENCIA (produto_config): DOM carregado, iniciando adição do campo...');
                adicionarCampoTipoPagamentoProdutoConfig();
            });
        } else {
            console.log('RECORRENCIA (produto_config): DOM já carregado, iniciando adição do campo...');
            adicionarCampoTipoPagamentoProdutoConfig();
        }
    })();
    </script>
    <?php
});

/**
 * Filtro para processar tipo_pagamento antes de salvar produto
 */
add_filter('product_data_before_save', function($product_data, $is_update = false) {
    // Se estiver salvando via produto_config, pegar do POST
    if (isset($_POST['salvar_produto_config']) && isset($_POST['tipo_pagamento'])) {
        $product_data['tipo_pagamento'] = $_POST['tipo_pagamento'];
        $product_data['intervalo_recorrencia'] = 'mensal'; // Default
    }
    return $product_data;
}, 10);

/**
 * Salva tipo_pagamento no banco ao atualizar produto
 */
add_action('before_update_product', function($produto_id, $product_data) {
    global $pdo;
    
    if (isset($product_data['tipo_pagamento'])) {
        try {
            $stmt = $pdo->prepare("UPDATE produtos SET tipo_pagamento = ?, intervalo_recorrencia = ? WHERE id = ?");
            $stmt->execute([
                $product_data['tipo_pagamento'],
                $product_data['intervalo_recorrencia'] ?? 'mensal',
                $produto_id
            ]);
        } catch (PDOException $e) {
            error_log("RECORRENCIA: Erro ao salvar tipo_pagamento - " . $e->getMessage());
        }
    }
}, 10);

/**
 * Modifica dados do produto no checkout para adicionar "/mês"
 */
add_filter('checkout_product_data', function($product_data) {
    global $pdo;
    
    if (isset($product_data['id'])) {
        $produto_id = $product_data['id'];
        try {
            $stmt = $pdo->prepare("SELECT tipo_pagamento FROM produtos WHERE id = ?");
            $stmt->execute([$produto_id]);
            $tipo_pagamento = $stmt->fetchColumn();
            
            if ($tipo_pagamento === 'recorrente') {
                $product_data['eh_recorrente'] = true;
            }
        } catch (PDOException $e) {
            error_log("RECORRENCIA: Erro ao buscar tipo_pagamento - " . $e->getMessage());
        }
    }
    
    return $product_data;
}, 10);

/**
 * Adiciona "/mês" no checkout quando produto é recorrente
 */
add_action('checkout_footer', function() {
    global $pdo;
    
    // Verificar se estamos no checkout
    if (!isset($_GET['p'])) {
        return;
    }
    
    $checkout_hash = $_GET['p'];
    
    try {
        // Verificar se é oferta ou produto normal
        $stmt_oferta = $pdo->prepare("SELECT produto_id FROM produto_ofertas WHERE checkout_hash = ? AND is_active = 1");
        $stmt_oferta->execute([$checkout_hash]);
        $oferta = $stmt_oferta->fetch(PDO::FETCH_ASSOC);
        
        if ($oferta) {
            $produto_id = $oferta['produto_id'];
        } else {
            $stmt_prod = $pdo->prepare("SELECT id FROM produtos WHERE checkout_hash = ?");
            $stmt_prod->execute([$checkout_hash]);
            $prod = $stmt_prod->fetch(PDO::FETCH_ASSOC);
            if (!$prod) return;
            $produto_id = $prod['id'];
        }
        
        // Verificar tipo de pagamento
        $stmt_tipo = $pdo->prepare("SELECT tipo_pagamento FROM produtos WHERE id = ?");
        $stmt_tipo->execute([$produto_id]);
        $tipo_pagamento = $stmt_tipo->fetchColumn();
        
        if ($tipo_pagamento === 'recorrente') {
            ?>
            <script>
            document.addEventListener('DOMContentLoaded', function() {
                // Adicionar "/mês" ao lado do preço no resumo
                const priceElements = document.querySelectorAll('span.text-2xl.font-bold, span.text-xl.font-bold');
                priceElements.forEach(function(el) {
                    if (el.textContent.includes('R$') && !el.textContent.includes('/mês')) {
                        const text = el.textContent.trim();
                        el.innerHTML = text + ' <span class="text-base font-normal">/mês</span>';
                    }
                });
                
                // Adicionar "/mês" no resumo lateral
                const summaryPrice = document.getElementById('final-total-price');
                if (summaryPrice && summaryPrice.textContent.includes('R$') && !summaryPrice.textContent.includes('/mês')) {
                    const text = summaryPrice.textContent.trim();
                    summaryPrice.innerHTML = text + ' <span class="text-sm font-normal">/mês</span>';
                }
                
                // Adicionar "/mês" no resumo mobile
                const mobileSummaryPrice = document.getElementById('final-total-price-mobile');
                if (mobileSummaryPrice && mobileSummaryPrice.textContent.includes('R$') && !mobileSummaryPrice.textContent.includes('/mês')) {
                    const text = mobileSummaryPrice.textContent.trim();
                    mobileSummaryPrice.innerHTML = text + ' <span class="text-sm font-normal">/mês</span>';
                }
            });
            </script>
            <?php
        }
    } catch (PDOException $e) {
        error_log("RECORRENCIA: Erro ao adicionar '/mês' no checkout - " . $e->getMessage());
    }
}, 10);

/**
 * Processa renovações - intercepta parâmetro renovacao no checkout e marca venda como renovação
 */
add_action('checkout_before_payment_form', function() {
    global $pdo;
    
    // Verificar se há parâmetro renovacao na URL
    if (!isset($_GET['renovacao']) || !is_numeric($_GET['renovacao'])) {
        return;
    }
    
    $assinatura_id = intval($_GET['renovacao']);
    
    // Buscar assinatura
    try {
        $stmt = $pdo->prepare("SELECT produto_id, comprador_email FROM assinaturas WHERE id = ? AND status IN ('ativa', 'expirada')");
        $stmt->execute([$assinatura_id]);
        $assinatura = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($assinatura) {
            // Salvar assinatura_id na sessão para usar em save_sales
            if (!isset($_SESSION)) {
                session_start();
            }
            $_SESSION['renovacao_assinatura_id'] = $assinatura_id;
            $_SESSION['renovacao_produto_id'] = $assinatura['produto_id'];
        }
    } catch (PDOException $e) {
        error_log("RECORRENCIA: Erro ao buscar assinatura para renovação - " . $e->getMessage());
    }
}, 10);

/**
 * Intercepta save_sales para marcar venda como renovação
 * Usa filtro para modificar dados antes de inserir
 */
add_filter('payment_data_before_process', function($payment_data) {
    // Verificar se há renovacao na sessão
    if (isset($_SESSION['renovacao_assinatura_id']) && isset($_SESSION['renovacao_produto_id'])) {
        $payment_data['renovacao_assinatura_id'] = $_SESSION['renovacao_assinatura_id'];
        $payment_data['renovacao_produto_id'] = $_SESSION['renovacao_produto_id'];
    }
    return $payment_data;
}, 10);

/**
 * Intercepta after_create_venda para vincular renovação à assinatura
 */
add_action('after_create_venda', function($produto_id) {
    global $pdo;
    
    // Verificar se há renovacao na sessão
    if (!isset($_SESSION['renovacao_assinatura_id']) || !isset($_SESSION['renovacao_produto_id'])) {
        return;
    }
    
    if ($produto_id != $_SESSION['renovacao_produto_id']) {
        return;
    }
    
    $assinatura_id = $_SESSION['renovacao_assinatura_id'];
    
    try {
        // Buscar última venda criada (criada nos últimos 5 minutos)
        $stmt_venda = $pdo->prepare("
            SELECT id 
            FROM vendas 
            WHERE produto_id = ? 
              AND assinatura_id IS NULL
              AND eh_renovacao = 0
              AND TIMESTAMPDIFF(MINUTE, data_venda, NOW()) <= 5
            ORDER BY data_venda DESC
            LIMIT 1
        ");
        $stmt_venda->execute([$produto_id]);
        $venda = $stmt_venda->fetch(PDO::FETCH_ASSOC);
        
        if ($venda) {
            // Atualizar venda com assinatura_id e eh_renovacao
            $stmt_update = $pdo->prepare("UPDATE vendas SET assinatura_id = ?, eh_renovacao = 1 WHERE id = ?");
            $stmt_update->execute([$assinatura_id, $venda['id']]);
            
            error_log("RECORRENCIA: Venda #{$venda['id']} marcada como renovação da assinatura #{$assinatura_id}");
        }
        
        // Limpar sessão após processar
        unset($_SESSION['renovacao_assinatura_id']);
        unset($_SESSION['renovacao_produto_id']);
        
    } catch (PDOException $e) {
        error_log("RECORRENCIA: Erro ao vincular renovação - " . $e->getMessage());
    }
}, 5); // Prioridade 5 para executar antes da criação de assinatura

/**
 * Cria assinatura após venda aprovada
 */
add_action('after_create_venda', function($produto_id) {
    global $pdo;
    
    // Buscar última venda aprovada deste produto e comprador
    // Isso é executado após save_sales, então a venda já foi criada
    try {
        // Buscar assinatura vinculada à última venda aprovada recente
        // Como não temos contexto direto da venda aqui, vamos verificar se é produto recorrente
        $stmt_produto = $pdo->prepare("SELECT tipo_pagamento FROM produtos WHERE id = ?");
        $stmt_produto->execute([$produto_id]);
        $tipo_pagamento = $stmt_produto->fetchColumn();
        
        if ($tipo_pagamento === 'recorrente') {
            // Buscar última venda aprovada (criada nos últimos 5 minutos para evitar duplicação)
            $stmt_venda = $pdo->prepare("
                SELECT id, comprador_email, comprador_nome 
                FROM vendas 
                WHERE produto_id = ? 
                  AND status_pagamento = 'approved'
                  AND assinatura_id IS NULL
                  AND eh_renovacao = 0
                  AND TIMESTAMPDIFF(MINUTE, data_venda, NOW()) <= 5
                ORDER BY data_venda DESC
                LIMIT 1
            ");
            $stmt_venda->execute([$produto_id]);
            $venda = $stmt_venda->fetch(PDO::FETCH_ASSOC);
            
            if ($venda) {
                // Verificar se já não existe assinatura ativa para este produto/email
                $stmt_check = $pdo->prepare("
                    SELECT id FROM assinaturas 
                    WHERE produto_id = ? 
                      AND comprador_email = ? 
                      AND status = 'ativa'
                ");
                $stmt_check->execute([$produto_id, $venda['comprador_email']]);
                
                if ($stmt_check->rowCount() === 0) {
                    // Criar nova assinatura
                    recorrencia_criar_assinatura(
                        $produto_id,
                        $venda['id'],
                        $venda['comprador_email'],
                        $venda['comprador_nome']
                    );
                }
            }
        }
    } catch (PDOException $e) {
        error_log("RECORRENCIA: Erro ao processar after_create_venda - " . $e->getMessage());
    }
}, 10);

/**
 * Processa renovação quando pagamento aprovado e eh_renovacao = 1
 */
add_action('after_create_venda', function($produto_id) {
    global $pdo;
    
    try {
        // Buscar última venda aprovada que é renovação
        $stmt_venda = $pdo->prepare("
            SELECT id, assinatura_id 
            FROM vendas 
            WHERE produto_id = ? 
              AND status_pagamento = 'approved'
              AND eh_renovacao = 1
              AND assinatura_id IS NOT NULL
              AND TIMESTAMPDIFF(MINUTE, data_venda, NOW()) <= 5
            ORDER BY data_venda DESC
            LIMIT 1
        ");
        $stmt_venda->execute([$produto_id]);
        $venda = $stmt_venda->fetch(PDO::FETCH_ASSOC);
        
        if ($venda && $venda['assinatura_id']) {
            recorrencia_processar_renovacao($venda['id'], $venda['assinatura_id']);
        }
    } catch (PDOException $e) {
        error_log("RECORRENCIA: Erro ao processar renovação - " . $e->getMessage());
    }
}, 20); // Prioridade 20 para executar depois da criação de assinatura

/**
 * Modifica área de membros para verificar status de assinatura e exibir botão de renovação
 */
add_action('dashboard_head', function() {
    // Só adiciona se estiver na área de membros
    if (!isset($_SERVER['REQUEST_URI']) || strpos($_SERVER['REQUEST_URI'], 'member_area_dashboard') === false) {
        return;
    }
    
    global $pdo;
    $cliente_email = $_SESSION['usuario'] ?? '';
    
    if (empty($cliente_email)) {
        return;
    }
    
    // Buscar assinaturas do cliente
    try {
        $stmt_ass = $pdo->prepare("
            SELECT 
                a.id as assinatura_id,
                a.produto_id,
                a.proxima_cobranca,
                a.status as assinatura_status,
                aa.data_expiracao,
                p.checkout_hash
            FROM assinaturas a
            JOIN produtos p ON a.produto_id = p.id
            LEFT JOIN alunos_acessos aa ON a.produto_id = aa.produto_id AND aa.aluno_email = a.comprador_email
            WHERE a.comprador_email = ?
        ");
        $stmt_ass->execute([$cliente_email]);
        $assinaturas = $stmt_ass->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($assinaturas)) {
            return;
        }
        
        // Criar array JavaScript com informações de assinaturas
        $assinaturas_js = [];
        foreach ($assinaturas as $ass) {
            $hoje = new DateTime();
            $data_expiracao = $ass['data_expiracao'] ? new DateTime($ass['data_expiracao']) : null;
            $proxima_cobranca = new DateTime($ass['proxima_cobranca']);
            
            $is_ativo = true;
            if ($ass['assinatura_status'] === 'expirada' || 
                ($data_expiracao && $data_expiracao < $hoje) ||
                ($proxima_cobranca < $hoje)) {
                $is_ativo = false;
            }
            
            $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'];
            $checkout_url = $protocol . '://' . $host . '/checkout?p=' . $ass['checkout_hash'] . '&renovacao=' . $ass['assinatura_id'];
            
            $assinaturas_js[] = [
                'produto_id' => $ass['produto_id'],
                'assinatura_id' => $ass['assinatura_id'],
                'is_ativo' => $is_ativo,
                'checkout_url' => $checkout_url,
                'status' => $ass['assinatura_status']
            ];
        }
        ?>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const assinaturas = <?php echo json_encode($assinaturas_js); ?>;
            
            assinaturas.forEach(function(ass) {
                // Encontrar card do curso pelo produto_id
                const card = document.querySelector(`[data-produto-id="${ass.produto_id}"]`);
                if (!card) {
                    // Tentar encontrar pelo atributo ou classe
                    const cards = document.querySelectorAll('.bg-gray-800.rounded-2xl');
                    cards.forEach(function(c) {
                        const link = c.querySelector('a[href*="produto_id=' + ass.produto_id + '"]');
                        if (link) {
                            const cardToUpdate = c;
                            
                            // Verificar status
                            if (!ass.is_ativo) {
                                // Adicionar badge "Inativo"
                                const badge = document.createElement('div');
                                badge.className = 'absolute top-2 left-2 bg-red-600 text-white px-2 py-1 rounded text-xs font-bold z-10';
                                badge.textContent = 'Inativo';
                                cardToUpdate.insertBefore(badge, cardToUpdate.firstChild);
                                
                                // Adicionar botão "Renovar" antes do botão de acesso
                                const actionsDiv = cardToUpdate.querySelector('.mt-auto, .flex.items-center.justify-between');
                                if (actionsDiv) {
                                    const renovarBtn = document.createElement('a');
                                    renovarBtn.href = ass.checkout_url;
                                    renovarBtn.className = 'bg-[var(--accent-primary)] hover:bg-[var(--accent-primary-hover)] text-white font-semibold py-2 px-4 rounded-lg transition-all duration-300 flex items-center gap-2';
                                    renovarBtn.innerHTML = '<i data-lucide="refresh-cw" class="w-4 h-4"></i> Renovar';
                                    
                                    // Inserir antes do botão existente
                                    if (actionsDiv.firstChild) {
                                        actionsDiv.insertBefore(renovarBtn, actionsDiv.firstChild);
                                    } else {
                                        actionsDiv.appendChild(renovarBtn);
                                    }
                                    
                                    // Re-inicializar ícones lucide
                                    if (typeof lucide !== 'undefined') {
                                        lucide.createIcons();
                                    }
                                }
                            } else {
                                // Adicionar badge "Ativo"
                                const badge = document.createElement('div');
                                badge.className = 'absolute top-2 left-2 bg-green-600 text-white px-2 py-1 rounded text-xs font-bold z-10';
                                badge.textContent = 'Ativo';
                                cardToUpdate.insertBefore(badge, cardToUpdate.firstChild);
                            }
                        }
                    });
                    return;
                }
                
                // Verificar status
                if (!ass.is_ativo) {
                    // Adicionar badge "Inativo"
                    const badge = document.createElement('div');
                    badge.className = 'absolute top-2 left-2 bg-red-600 text-white px-2 py-1 rounded text-xs font-bold z-10';
                    badge.textContent = 'Inativo';
                    card.insertBefore(badge, card.firstChild);
                    
                    // Adicionar botão "Renovar"
                    const actionsDiv = card.querySelector('.mt-auto, .flex.items-center.justify-between');
                    if (actionsDiv) {
                        const renovarBtn = document.createElement('a');
                        renovarBtn.href = ass.checkout_url;
                        renovarBtn.className = 'bg-[var(--accent-primary)] hover:bg-[var(--accent-primary-hover)] text-white font-semibold py-2 px-4 rounded-lg transition-all duration-300 flex items-center gap-2';
                        renovarBtn.innerHTML = '<i data-lucide="refresh-cw" class="w-4 h-4"></i> Renovar';
                        actionsDiv.insertBefore(renovarBtn, actionsDiv.firstChild);
                        
                        if (typeof lucide !== 'undefined') {
                            lucide.createIcons();
                        }
                    }
                } else {
                    // Adicionar badge "Ativo"
                    const badge = document.createElement('div');
                    badge.className = 'absolute top-2 left-2 bg-green-600 text-white px-2 py-1 rounded text-xs font-bold z-10';
                    badge.textContent = 'Ativo';
                    card.insertBefore(badge, card.firstChild);
                }
            });
        });
        </script>
        <?php
    } catch (PDOException $e) {
        error_log("RECORRENCIA: Erro ao verificar assinaturas na área de membros - " . $e->getMessage());
    }
}, 10);

/**
 * Hook no notification.php quando venda é aprovada
 * Isso garante que a assinatura seja criada também via webhook
 */
add_action('after_update_venda_status', function($venda_id, $novo_status, $status_anterior) {
    if ($novo_status !== 'approved' || $status_anterior === 'approved') {
        return;
    }
    
    global $pdo;
    
    try {
        // Buscar dados da venda
        $stmt_venda = $pdo->prepare("
            SELECT v.id, v.produto_id, v.comprador_email, v.comprador_nome, v.assinatura_id, v.eh_renovacao
            FROM vendas v
            WHERE v.id = ?
        ");
        $stmt_venda->execute([$venda_id]);
        $venda = $stmt_venda->fetch(PDO::FETCH_ASSOC);
        
        if (!$venda) {
            return;
        }
        
        // Se for renovação, processar renovação
        if ($venda['eh_renovacao'] == 1 && $venda['assinatura_id']) {
            recorrencia_processar_renovacao($venda_id, $venda['assinatura_id']);
            return;
        }
        
        // Verificar se produto é recorrente
        $stmt_produto = $pdo->prepare("SELECT tipo_pagamento FROM produtos WHERE id = ?");
        $stmt_produto->execute([$venda['produto_id']]);
        $tipo_pagamento = $stmt_produto->fetchColumn();
        
        if ($tipo_pagamento === 'recorrente' && !$venda['assinatura_id']) {
            // Verificar se já não existe assinatura ativa
            $stmt_check = $pdo->prepare("
                SELECT id FROM assinaturas 
                WHERE produto_id = ? 
                  AND comprador_email = ? 
                  AND status = 'ativa'
            ");
            $stmt_check->execute([$venda['produto_id'], $venda['comprador_email']]);
            
            if ($stmt_check->rowCount() === 0) {
                // Criar assinatura
                recorrencia_criar_assinatura(
                    $venda['produto_id'],
                    $venda['id'],
                    $venda['comprador_email'],
                    $venda['comprador_nome']
                );
            }
        }
    } catch (PDOException $e) {
        error_log("RECORRENCIA: Erro ao processar after_update_venda_status - " . $e->getMessage());
    }
}, 10);

