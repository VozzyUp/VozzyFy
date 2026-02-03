<?php
// Lógica para processar o formulário de adição/edição de produto
require_once __DIR__ . '/../helpers/security_helper.php';

$mensagem = '';
$produto_edit = null;
$upload_dir = 'uploads/'; // Pasta para salvar as imagens e PDFs

// Garante que o diretório de uploads exista
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

// Obter o ID do usuário logado
$usuario_id = $_SESSION['id'] ?? 0;
if ($usuario_id === 0) {
    header("location: /login");
    exit;
}

// Deletar produto
if (isset($_POST['deletar_produto'])) {
    // Verifica CSRF
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $mensagem = "<div class='bg-red-900/20 border-l-4 border-red-500 text-red-300 p-4 rounded-md shadow-sm mb-6' role='alert'>Token CSRF inválido ou ausente.</div>";
    } else {
        try {
        $produto_id_to_delete = $_POST['id_produto'];
        
        // Executar hook antes de deletar
        if (function_exists('do_action')) {
            $block_delete = do_action('before_delete_product', $produto_id_to_delete);
            if ($block_delete === false) {
                $mensagem = "<div class='bg-red-900/20 border-l-4 border-red-500 text-red-300 p-4 rounded-md shadow-sm mb-6' role='alert'>Deleção bloqueada por plugin.</div>";
                // NÃO continua com a deleção
            } else {
                $stmt_find = $pdo->prepare("SELECT foto, tipo_entrega, conteudo_entrega FROM produtos WHERE id = ? AND usuario_id = ?");
                $stmt_find->execute([$produto_id_to_delete, $usuario_id]);
                $produto_files = $stmt_find->fetch(PDO::FETCH_ASSOC);

                if ($produto_files) {
                    if ($produto_files['foto'] && file_exists($upload_dir . $produto_files['foto'])) {
                        unlink($upload_dir . $produto_files['foto']);
                    }
                    if ($produto_files['tipo_entrega'] === 'email_pdf' && $produto_files['conteudo_entrega'] && file_exists($upload_dir . $produto_files['conteudo_entrega'])) {
                        unlink($upload_dir . $produto_files['conteudo_entrega']);
                    }

                    $stmt = $pdo->prepare("DELETE FROM produtos WHERE id = ? AND usuario_id = ?");
                    $stmt->execute([$produto_id_to_delete, $usuario_id]);
                    
                    // Executar hook depois de deletar
                    if (function_exists('do_action')) {
                        do_action('after_delete_product', $produto_id_to_delete);
                    }
                    
                    $mensagem = "<div class='animate-fade-in-down bg-green-900/20 border-l-4 border-green-500 text-green-300 p-4 rounded-md shadow-sm mb-6' role='alert'><div class='flex'><div class='py-1'><i data-lucide='check-circle' class='w-6 h-6 mr-3 text-green-400'></i></div><div><p class='font-bold text-white'>Sucesso</p><p class='text-sm text-green-200'>Produto deletado com sucesso!</p></div></div></div>";
                } else {
                    $mensagem = "<div class='animate-fade-in-down bg-red-900/20 border-l-4 border-red-500 text-red-300 p-4 rounded-md shadow-sm mb-6' role='alert'><div class='flex'><div class='py-1'><i data-lucide='alert-circle' class='w-6 h-6 mr-3 text-red-400'></i></div><div><p class='font-bold text-white'>Erro</p><p class='text-sm text-red-200'>Produto não encontrado ou permissão negada.</p></div></div></div>";
                }
            }
        } else {
            // Se hooks não existem, executar normalmente
            $stmt_find = $pdo->prepare("SELECT foto, tipo_entrega, conteudo_entrega FROM produtos WHERE id = ? AND usuario_id = ?");
            $stmt_find->execute([$produto_id_to_delete, $usuario_id]);
            $produto_files = $stmt_find->fetch(PDO::FETCH_ASSOC);

            if ($produto_files) {
                if ($produto_files['foto'] && file_exists($upload_dir . $produto_files['foto'])) {
                    unlink($upload_dir . $produto_files['foto']);
                }
                if ($produto_files['tipo_entrega'] === 'email_pdf' && $produto_files['conteudo_entrega'] && file_exists($upload_dir . $produto_files['conteudo_entrega'])) {
                    unlink($upload_dir . $produto_files['conteudo_entrega']);
                }

                $stmt = $pdo->prepare("DELETE FROM produtos WHERE id = ? AND usuario_id = ?");
                $stmt->execute([$produto_id_to_delete, $usuario_id]);
                $mensagem = "<div class='animate-fade-in-down bg-green-900/20 border-l-4 border-green-500 text-green-300 p-4 rounded-md shadow-sm mb-6' role='alert'><div class='flex'><div class='py-1'><i data-lucide='check-circle' class='w-6 h-6 mr-3 text-green-400'></i></div><div><p class='font-bold text-white'>Sucesso</p><p class='text-sm text-green-200'>Produto deletado com sucesso!</p></div></div></div>";
            } else {
                $mensagem = "<div class='animate-fade-in-down bg-red-900/20 border-l-4 border-red-500 text-red-300 p-4 rounded-md shadow-sm mb-6' role='alert'><div class='flex'><div class='py-1'><i data-lucide='alert-circle' class='w-6 h-6 mr-3 text-red-400'></i></div><div><p class='font-bold text-white'>Erro</p><p class='text-sm text-red-200'>Produto não encontrado ou permissão negada.</p></div></div></div>";
            }
        }
        } catch (PDOException $e) {
            $mensagem = "<div class='bg-red-900/20 border-l-4 border-red-500 text-red-300 p-4 rounded-md shadow-sm mb-6' role='alert'>Erro ao deletar: " . $e->getMessage() . "</div>";
        }
    }
}

// Salvar (Adicionar ou Editar) produto
if (isset($_POST['salvar_produto'])) {
    // Verifica CSRF
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $mensagem = "<div class='bg-red-900/20 border-l-4 border-red-500 text-red-300 p-4 rounded-md shadow-sm mb-6' role='alert'>Token CSRF inválido ou ausente.</div>";
    } else {
        $nome = $_POST['nome'];
    $descricao = $_POST['descricao'];
    $preco = $_POST['preco'];
    $id_produto = $_POST['id_produto'];
    // Gateway padrão para novos produtos, mantém o existente ao editar
    $gateway = !empty($id_produto) ? ($_POST['gateway'] ?? 'mercadopago') : 'mercadopago';
    
    // --- Lógica de Upload de Imagem de Capa ---
    $foto_atual = $_POST['foto_atual'] ?? null;
    $nome_foto = $foto_atual;
    if (isset($_FILES['foto']) && $_FILES['foto']['error'] == 0) {
        // Apenas JPEG ou PNG para imagens de produtos
        $upload_result = validate_image_upload($_FILES['foto'], $upload_dir, 'produto_img', 5, true);
        if ($upload_result['success']) {
            // Remove foto antiga se existir
            if ($foto_atual && file_exists($upload_dir . $foto_atual)) {
                @unlink($upload_dir . $foto_atual);
            }
            // Extrai apenas o nome do arquivo do caminho relativo
            $nome_foto = basename($upload_result['file_path']);
        } else {
            $mensagem .= "<div class='bg-red-900/20 text-red-300 p-3 rounded mb-4'>" . htmlspecialchars($upload_result['error']) . "</div>";
            $nome_foto = $foto_atual;
        }
    }

    // --- Lógica de Entrega do Produto ---
    $tipo_entrega = $_POST['tipo_entrega'];
    $conteudo_entrega_atual = $_POST['conteudo_entrega_atual'] ?? null;
    $conteudo_entrega = $conteudo_entrega_atual;

    if ($tipo_entrega === 'link') {
        $conteudo_entrega = $_POST['conteudo_entrega_link'] ?? null;
    } elseif ($tipo_entrega === 'area_membros') {
        $conteudo_entrega = null; 
    } elseif ($tipo_entrega === 'produto_fisico') {
        $conteudo_entrega = null;
    } elseif ($tipo_entrega === 'email_pdf') {
        if (isset($_FILES['conteudo_entrega_pdf']) && $_FILES['conteudo_entrega_pdf']['error'] === UPLOAD_ERR_OK) {
            $upload_result = validate_pdf_upload($_FILES['conteudo_entrega_pdf'], $upload_dir, 'produto_pdf', 10);
            if ($upload_result['success']) {
                // Remove PDF antigo se existir
                if ($conteudo_entrega_atual && file_exists($upload_dir . $conteudo_entrega_atual)) {
                    @unlink($upload_dir . $conteudo_entrega_atual);
                }
                // Extrai apenas o nome do arquivo do caminho relativo
                $conteudo_entrega = basename($upload_result['file_path']);
            } else {
                $mensagem .= "<div class='bg-red-900/20 text-red-300 p-3 rounded mb-4'>" . htmlspecialchars($upload_result['error']) . "</div>";
                $conteudo_entrega = $conteudo_entrega_atual;
            }
        }
    }

    try {
        if (empty($id_produto)) {
            // Garante que hooks SaaS estejam carregados
            if (function_exists('saas_enabled') && saas_enabled()) {
                if (file_exists(__DIR__ . '/../saas/saas.php')) {
                    require_once __DIR__ . '/../saas/saas.php';
                }
            }
            
            // Verifica limitações via hooks (SaaS)
            $limit_check = do_action('before_create_product', $usuario_id);
            
            // Se não retornou nada ou retornou null, verifica diretamente
            if ($limit_check === null && function_exists('saas_check_product_limit')) {
                $limit_check = saas_check_product_limit($usuario_id);
            }
            
            // Se o limite foi atingido, bloqueia a criação
            if ($limit_check && isset($limit_check['allowed']) && $limit_check['allowed'] === false) {
                $upgrade_url = $limit_check['upgrade_url'] ?? '/index?pagina=saas_planos';
                $mensagem = "<div class='animate-fade-in-down bg-red-900/20 border-l-4 border-red-500 text-red-300 p-4 rounded-md shadow-sm mb-6' role='alert'>
                    <div class='flex'>
                        <div class='py-1'><i data-lucide='alert-circle' class='w-6 h-6 mr-3 text-red-400'></i></div>
                        <div class='flex-1'>
                            <p class='font-bold text-white mb-2'>Limite de Produtos Atingido</p>
                            <p class='text-sm text-red-200 mb-3'>" . htmlspecialchars($limit_check['message'] ?? 'Limite atingido') . " Faça upgrade do seu plano para criar mais produtos.</p>
                            <a href='" . htmlspecialchars($upgrade_url) . "' class='inline-flex items-center px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-md transition-colors'>
                                <i data-lucide='arrow-up-circle' class='w-4 h-4 mr-2'></i>
                                Fazer Upgrade do Plano
                            </a>
                        </div>
                    </div>
                </div>";
                // NÃO cria o produto - para aqui
            } elseif ($limit_check && isset($limit_check['allowed']) && $limit_check['allowed'] === true) {
                // Permite criar produto apenas se explicitamente permitido
                // Adicionar novo produto
                $checkout_hash = bin2hex(random_bytes(16));
                
                // Verificar se as colunas tipo_pagamento e intervalo_recorrencia existem
                try {
                    $stmt_check = $pdo->query("SHOW COLUMNS FROM produtos LIKE 'tipo_pagamento'");
                    $has_tipo_pagamento = $stmt_check && $stmt_check->rowCount() > 0;
                } catch (PDOException $e) {
                    $has_tipo_pagamento = false;
                }
                
                if ($has_tipo_pagamento) {
                    // Buscar tipo_pagamento do POST (com default 'unico')
                    $tipo_pagamento = $_POST['tipo_pagamento'] ?? 'unico';
                    $intervalo_recorrencia = 'mensal'; // Default
                    $stmt = $pdo->prepare("INSERT INTO produtos (nome, descricao, preco, foto, checkout_hash, tipo_entrega, conteudo_entrega, usuario_id, gateway, tipo_pagamento, intervalo_recorrencia) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$nome, $descricao, $preco, $nome_foto, $checkout_hash, $tipo_entrega, $conteudo_entrega, $usuario_id, $gateway, $tipo_pagamento, $intervalo_recorrencia]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO produtos (nome, descricao, preco, foto, checkout_hash, tipo_entrega, conteudo_entrega, usuario_id, gateway) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$nome, $descricao, $preco, $nome_foto, $checkout_hash, $tipo_entrega, $conteudo_entrega, $usuario_id, $gateway]);
                }
                $novo_produto_id = $pdo->lastInsertId();
                // Executa hook após criação
                do_action('after_create_product', $novo_produto_id, $usuario_id);
                // Redireciona para página de edição do produto recém-criado
                header("Location: /index?pagina=produto_config&id=" . $novo_produto_id . "&aba=geral");
                exit;
            } else {
                // Se não há verificação de limite (SaaS desabilitado ou sem retorno), permite criar
                // Adicionar novo produto
                $checkout_hash = bin2hex(random_bytes(16));
                
                // Verificar se as colunas tipo_pagamento e intervalo_recorrencia existem
                try {
                    $stmt_check = $pdo->query("SHOW COLUMNS FROM produtos LIKE 'tipo_pagamento'");
                    $has_tipo_pagamento = $stmt_check && $stmt_check->rowCount() > 0;
                } catch (PDOException $e) {
                    $has_tipo_pagamento = false;
                }
                
                if ($has_tipo_pagamento) {
                    // Buscar tipo_pagamento do POST (com default 'unico')
                    $tipo_pagamento = $_POST['tipo_pagamento'] ?? 'unico';
                    $intervalo_recorrencia = 'mensal'; // Default
                    $stmt = $pdo->prepare("INSERT INTO produtos (nome, descricao, preco, foto, checkout_hash, tipo_entrega, conteudo_entrega, usuario_id, gateway, tipo_pagamento, intervalo_recorrencia) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$nome, $descricao, $preco, $nome_foto, $checkout_hash, $tipo_entrega, $conteudo_entrega, $usuario_id, $gateway, $tipo_pagamento, $intervalo_recorrencia]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO produtos (nome, descricao, preco, foto, checkout_hash, tipo_entrega, conteudo_entrega, usuario_id, gateway) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$nome, $descricao, $preco, $nome_foto, $checkout_hash, $tipo_entrega, $conteudo_entrega, $usuario_id, $gateway]);
                }
                $novo_produto_id = $pdo->lastInsertId();
                // Executa hook após criação
                do_action('after_create_product', $novo_produto_id, $usuario_id);
                // Redireciona para página de edição do produto recém-criado
                header("Location: /index?pagina=produto_config&id=" . $novo_produto_id . "&aba=geral");
                exit;
            }
        } else {
            // Atualizar produto
            $product_data = [
                'nome' => $nome,
                'descricao' => $descricao,
                'preco' => $preco,
                'foto' => $nome_foto,
                'tipo_entrega' => $tipo_entrega,
                'conteudo_entrega' => $conteudo_entrega,
                'gateway' => $gateway
            ];
            
            // Aplicar filtro nos dados antes de salvar
            if (function_exists('apply_filters')) {
                $product_data = apply_filters('product_data_before_save', $product_data, true); // true = is_update
            }
            
            // Executar hook antes de atualizar
            if (function_exists('do_action')) {
                do_action('before_update_product', $id_produto, $product_data);
            }
            
            // Verificar se as colunas tipo_pagamento e intervalo_recorrencia existem
            try {
                $stmt_check = $pdo->query("SHOW COLUMNS FROM produtos LIKE 'tipo_pagamento'");
                $has_tipo_pagamento = $stmt_check && $stmt_check->rowCount() > 0;
            } catch (PDOException $e) {
                $has_tipo_pagamento = false;
            }
            
            if ($has_tipo_pagamento) {
                // Buscar tipo_pagamento do POST (com default 'unico' se não existir)
                $tipo_pagamento = $_POST['tipo_pagamento'] ?? 'unico';
                $intervalo_recorrencia = 'mensal'; // Default
                $stmt = $pdo->prepare("UPDATE produtos SET nome = ?, descricao = ?, preco = ?, foto = ?, tipo_entrega = ?, conteudo_entrega = ?, gateway = ?, tipo_pagamento = ?, intervalo_recorrencia = ? WHERE id = ? AND usuario_id = ?");
                $stmt->execute([$product_data['nome'], $product_data['descricao'], $product_data['preco'], $product_data['foto'], $product_data['tipo_entrega'], $product_data['conteudo_entrega'], $product_data['gateway'], $tipo_pagamento, $intervalo_recorrencia, $id_produto, $usuario_id]);
            } else {
                $stmt = $pdo->prepare("UPDATE produtos SET nome = ?, descricao = ?, preco = ?, foto = ?, tipo_entrega = ?, conteudo_entrega = ?, gateway = ? WHERE id = ? AND usuario_id = ?");
                $stmt->execute([$product_data['nome'], $product_data['descricao'], $product_data['preco'], $product_data['foto'], $product_data['tipo_entrega'], $product_data['conteudo_entrega'], $product_data['gateway'], $id_produto, $usuario_id]);
            }
            if ($stmt->rowCount() > 0) {
                 // Executar hook depois de atualizar
                 if (function_exists('do_action')) {
                     do_action('after_update_product', $id_produto, $product_data);
                 }
                 
                 $mensagem = "<div class='animate-fade-in-down bg-green-900/20 border-l-4 border-green-500 text-green-300 p-4 rounded-md shadow-sm mb-6' role='alert'><div class='flex'><div class='py-1'><i data-lucide='check-circle' class='w-6 h-6 mr-3 text-green-400'></i></div><div><p class='font-bold text-white'>Sucesso</p><p class='text-sm text-green-200'>Produto atualizado com sucesso!</p></div></div></div>";
                 
                 // Se o produto for do tipo 'area_membros', atualizar também o título do curso associado
                 if ($tipo_entrega === 'area_membros') {
                     $stmt_update_curso = $pdo->prepare("UPDATE cursos SET titulo = ?, descricao = ? WHERE produto_id = ?");
                     $stmt_update_curso->execute([$nome, $descricao, $id_produto]);
                 }
            } else {
                 $mensagem = "<div class='bg-blue-900/20 border-l-4 border-blue-500 text-blue-300 p-4 rounded-md shadow-sm mb-6' role='alert'>Nenhuma alteração realizada ou produto não encontrado.</div>";
            }
        }
        } catch (PDOException $e) {
            $mensagem = "<div class='bg-red-900/20 border-l-4 border-red-500 text-red-300 p-4 rounded-md shadow-sm mb-6' role='alert'>Erro ao salvar: " . $e->getMessage() . "</div>";
        }
    }
}

// Redirecionar para nova página de configuração se editar for usado
if (isset($_GET['editar'])) {
    header("Location: /index?pagina=produto_config&id=" . intval($_GET['editar']) . "&aba=geral");
    exit;
}

// Buscar produto para edição (mantido para compatibilidade com formulário antigo)
$produto_edit = null;

// Aplicar filtro nos argumentos de query se disponível
$query_args = ['usuario_id' => $usuario_id, 'order_by' => 'data_criacao', 'order' => 'DESC'];
if (function_exists('apply_filters')) {
    $query_args = apply_filters('vendas_query_args', $query_args); // Reutilizando mesmo filtro para produtos
}

// Busca todos os produtos
$stmt_produtos_list = $pdo->prepare("SELECT * FROM produtos WHERE usuario_id = ? ORDER BY data_criacao DESC");
$stmt_produtos_list->execute([$usuario_id]);
$produtos = $stmt_produtos_list->fetchAll(PDO::FETCH_ASSOC);

// Aplicar filtro nos dados dos produtos após carregar
if (function_exists('apply_filters')) {
    foreach ($produtos as $key => $produto) {
        $produtos[$key] = apply_filters('product_data_after_load', $produto);
    }
}

do_action('produtos_list_before');
?>

<style>
    /* Custom Animations */
    @keyframes fadeInDown {
        from { opacity: 0; transform: translateY(-10px); }
        to { opacity: 1; transform: translateY(0); }
    }
    @keyframes fadeInUp {
        from { opacity: 0; transform: translateY(10px); }
        to { opacity: 1; transform: translateY(0); }
    }
    .animate-fade-in-down { animation: fadeInDown 0.4s ease-out forwards; }
    .animate-fade-in-up { animation: fadeInUp 0.5s ease-out forwards; }
    
    /* Scrollbar personalizada suave */
    .custom-scrollbar::-webkit-scrollbar { width: 6px; }
    .custom-scrollbar::-webkit-scrollbar-track { background: #0f1419; }
    .custom-scrollbar::-webkit-scrollbar-thumb { background: #1a1f24; border-radius: 3px; }
    .custom-scrollbar::-webkit-scrollbar-thumb:hover { background: var(--accent-primary); }
    
    /* Garantir que os cards de entrega sejam clicáveis */
    .entrega-option-card {
        position: relative;
        z-index: 2;
        pointer-events: auto !important;
        user-select: none;
    }
    .entrega-option-card * {
        pointer-events: none;
    }
</style>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    
    <!-- Header Section -->
    <div class="flex flex-col md:flex-row justify-between items-center mb-10 gap-4">
        <div>
            <h1 class="text-3xl font-extrabold text-white tracking-tight">Meus Produtos</h1>
            <p class="text-gray-400 mt-1 text-sm">Gerencie seu catálogo, preços e formas de entrega.</p>
        </div>
        <button id="novo-produto-btn" class="group text-white font-medium py-2.5 px-6 rounded-xl shadow-lg transition-all duration-300 transform hover:-translate-y-0.5 flex items-center space-x-2" style="background-color: var(--accent-primary); box-shadow: 0 10px 15px -3px color-mix(in srgb, var(--accent-primary) 20%, transparent);" onmouseover="this.style.backgroundColor='var(--accent-primary-hover)'" onmouseout="this.style.backgroundColor='var(--accent-primary)'">
            <i data-lucide="plus" class="w-5 h-5 transition-transform group-hover:rotate-90"></i>
            <span>Novo Produto</span>
        </button>
    </div>

    <!-- Area de Mensagens -->
    <?php echo $mensagem; ?>

    <!-- Formulário (Slide Down) -->
    <div id="form-container" class="bg-dark-card rounded-2xl shadow-xl border border-dark-border overflow-hidden mb-10 animate-fade-in-down" style="display: none;">
        <div class="bg-dark-elevated px-8 py-4 border-b border-dark-border flex justify-between items-center">
            <h2 class="text-lg font-bold text-white flex items-center">
                <i data-lucide="<?php echo $produto_edit ? 'edit-3' : 'package-plus'; ?>" class="w-5 h-5 mr-2" style="color: var(--accent-primary);"></i>
                <?php echo $produto_edit ? 'Editar Produto' : 'Cadastrar Novo Produto'; ?>
            </h2>
            <button id="fechar-form-btn" class="text-gray-400 hover:text-gray-300 transition-colors p-1 rounded-full hover:bg-dark-elevated">
                <i data-lucide="x" class="w-5 h-5"></i>
            </button>
        </div>
        
        <?php do_action('product_form_before'); ?>
        <form action="/index?pagina=produtos" method="post" enctype="multipart/form-data" class="p-8">
            <?php
            require_once __DIR__ . '/../helpers/security_helper.php';
            $csrf_token = generate_csrf_token();
            ?>
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="id_produto" value="<?php echo $produto_edit['id'] ?? ''; ?>">
            <input type="hidden" name="foto_atual" value="<?php echo $produto_edit['foto'] ?? ''; ?>">
            <input type="hidden" name="conteudo_entrega_atual" value="<?php echo htmlspecialchars($produto_edit['conteudo_entrega'] ?? ''); ?>">

            <div class="grid grid-cols-1 md:grid-cols-12 gap-8">
                
                <!-- Coluna Esquerda: Informações Básicas -->
                <div class="md:col-span-8 space-y-6">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label for="nome" class="block text-gray-300 text-sm font-semibold mb-2">Nome do Produto</label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-gray-400">
                                    <i data-lucide="tag" class="w-4 h-4"></i>
                                </span>
                                <input type="text" id="nome" name="nome" class="pl-10 w-full px-4 py-3 bg-dark-elevated border border-dark-border rounded-lg focus:outline-none focus:ring-2 transition-all text-white placeholder-gray-500" style="--tw-ring-color: color-mix(in srgb, var(--accent-primary) 20%, transparent);" onfocus="this.style.borderColor='var(--accent-primary)'" onblur="this.style.borderColor='rgba(255,255,255,0.1)'" placeholder="Ex: E-book Premium" value="<?php echo htmlspecialchars($produto_edit['nome'] ?? ''); ?>" required>
                            </div>
                        </div>
                        <div>
                            <label for="preco" class="block text-gray-300 text-sm font-semibold mb-2">Preço (R$)</label>
                            <div class="relative">
                                <span class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-gray-400 font-bold">R$</span>
                                <input type="number" step="0.01" id="preco" name="preco" class="pl-10 w-full px-4 py-3 bg-dark-elevated border border-dark-border rounded-lg focus:outline-none focus:ring-2 transition-all text-white" style="--tw-ring-color: color-mix(in srgb, var(--accent-primary) 20%, transparent);" onfocus="this.style.borderColor='var(--accent-primary)'" onblur="this.style.borderColor='rgba(255,255,255,0.1)'" placeholder="0.00" value="<?php echo htmlspecialchars($produto_edit['preco'] ?? ''); ?>" required>
                            </div>
                        </div>
                    </div>

                    <div>
                        <label for="descricao" class="block text-gray-300 text-sm font-semibold mb-2">Descrição</label>
                        <textarea id="descricao" name="descricao" rows="4" class="w-full px-4 py-3 bg-dark-elevated border border-dark-border rounded-lg focus:outline-none focus:ring-2 transition-all text-white placeholder-gray-500" style="--tw-ring-color: color-mix(in srgb, var(--accent-primary) 20%, transparent);" onfocus="this.style.borderColor='var(--accent-primary)'" onblur="this.style.borderColor='rgba(255,255,255,0.1)'" placeholder="Descreva os benefícios do seu produto..."><?php echo htmlspecialchars($produto_edit['descricao'] ?? ''); ?></textarea>
                    </div>

                    <!-- Configuração de Entrega -->
                    <div class="bg-dark-elevated p-6 rounded-xl border border-dark-border">
                        <h3 class="text-sm font-bold text-white uppercase tracking-wide mb-4 flex items-center">
                            <i data-lucide="truck" class="w-4 h-4 mr-2"></i> Configuração de Entrega
                        </h3>
                        
                        <div class="mb-4">
                            <label class="block text-gray-300 text-sm font-medium mb-3">Como o cliente receberá o produto?</label>
                            <input type="hidden" id="tipo_entrega" name="tipo_entrega" value="<?php echo htmlspecialchars($produto_edit['tipo_entrega'] ?? 'link'); ?>">
                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4" style="position: relative; z-index: 1;">
                                <div class="entrega-option-card cursor-pointer p-4 rounded-lg border-2 transition-all duration-200 bg-dark-card hover:bg-dark-elevated <?php echo (($produto_edit['tipo_entrega'] ?? 'link') == 'link') ? 'border-[var(--accent-primary)] bg-dark-elevated' : 'border-dark-border'; ?>" data-value="link" onclick="if(typeof window.selectEntregaOption === 'function') { window.selectEntregaOption('link'); } else { console.error('selectEntregaOption não está definida'); }" style="position: relative; z-index: 2; pointer-events: auto;">
                                    <div class="flex flex-col items-center text-center space-y-2">
                                        <div class="w-12 h-12 rounded-lg flex items-center justify-center mb-2" style="background-color: color-mix(in srgb, var(--accent-primary) 20%, transparent);">
                                            <i data-lucide="link" class="w-6 h-6" style="color: var(--accent-primary);"></i>
                                        </div>
                                        <h3 class="font-semibold text-white text-sm">Link Externo</h3>
                                        <p class="text-xs text-gray-400">Google Drive, Notion, etc</p>
                                    </div>
                                </div>
                                
                                <div class="entrega-option-card cursor-pointer p-4 rounded-lg border-2 transition-all duration-200 bg-dark-card hover:bg-dark-elevated <?php echo (($produto_edit['tipo_entrega'] ?? '') == 'email_pdf') ? 'border-[var(--accent-primary)] bg-dark-elevated' : 'border-dark-border'; ?>" data-value="email_pdf" onclick="if(typeof window.selectEntregaOption === 'function') { window.selectEntregaOption('email_pdf'); } else { console.error('selectEntregaOption não está definida'); }" style="position: relative; z-index: 2; pointer-events: auto;">
                                    <div class="flex flex-col items-center text-center space-y-2">
                                        <div class="w-12 h-12 rounded-lg flex items-center justify-center mb-2" style="background-color: color-mix(in srgb, var(--accent-primary) 20%, transparent);">
                                            <i data-lucide="file-text" class="w-6 h-6" style="color: var(--accent-primary);"></i>
                                        </div>
                                        <h3 class="font-semibold text-white text-sm">Arquivo PDF</h3>
                                        <p class="text-xs text-gray-400">Anexo no e-mail</p>
                                    </div>
                                </div>
                                
                                <div class="entrega-option-card cursor-pointer p-4 rounded-lg border-2 transition-all duration-200 bg-dark-card hover:bg-dark-elevated <?php echo (($produto_edit['tipo_entrega'] ?? '') == 'area_membros') ? 'border-[var(--accent-primary)] bg-dark-elevated' : 'border-dark-border'; ?>" data-value="area_membros" onclick="if(typeof window.selectEntregaOption === 'function') { window.selectEntregaOption('area_membros'); } else { console.error('selectEntregaOption não está definida'); }" style="position: relative; z-index: 2; pointer-events: auto;">
                                    <div class="flex flex-col items-center text-center space-y-2">
                                        <div class="w-12 h-12 rounded-lg flex items-center justify-center mb-2" style="background-color: color-mix(in srgb, var(--accent-primary) 20%, transparent);">
                                            <i data-lucide="lock" class="w-6 h-6" style="color: var(--accent-primary);"></i>
                                        </div>
                                        <h3 class="font-semibold text-white text-sm">Área de Membros</h3>
                                        <p class="text-xs text-gray-400">Acesso interno</p>
                                    </div>
                                </div>
                                
                                <div class="entrega-option-card cursor-pointer p-4 rounded-lg border-2 transition-all duration-200 bg-dark-card hover:bg-dark-elevated <?php echo (($produto_edit['tipo_entrega'] ?? '') == 'produto_fisico') ? 'border-[var(--accent-primary)] bg-dark-elevated' : 'border-dark-border'; ?>" data-value="produto_fisico" onclick="if(typeof window.selectEntregaOption === 'function') { window.selectEntregaOption('produto_fisico'); } else { console.error('selectEntregaOption não está definida'); }" style="position: relative; z-index: 2; pointer-events: auto;">
                                    <div class="flex flex-col items-center text-center space-y-2">
                                        <div class="w-12 h-12 rounded-lg flex items-center justify-center mb-2" style="background-color: color-mix(in srgb, var(--accent-primary) 20%, transparent);">
                                            <i data-lucide="package" class="w-6 h-6" style="color: var(--accent-primary);"></i>
                                        </div>
                                        <h3 class="font-semibold text-white text-sm">Produto Físico</h3>
                                        <p class="text-xs text-gray-400">Envio por correio</p>
                                    </div>
                                </div>
                                
                                <div class="entrega-option-card cursor-pointer p-4 rounded-lg border-2 transition-all duration-200 bg-dark-card hover:bg-dark-elevated <?php echo (($produto_edit['tipo_entrega'] ?? '') == 'somente_pagamento') ? 'border-[var(--accent-primary)] bg-dark-elevated' : 'border-dark-border'; ?>" data-value="somente_pagamento" onclick="if(typeof window.selectEntregaOption === 'function') { window.selectEntregaOption('somente_pagamento'); } else { console.error('selectEntregaOption não está definida'); }" style="position: relative; z-index: 2; pointer-events: auto;">
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

                        <!-- Campos Dinâmicos de Entrega -->
                        <div id="entrega-fields-container">
                            <div id="entrega-link-container" class="animate-fade-in-down" style="display: <?php echo (($produto_edit['tipo_entrega'] ?? '') === 'link') ? 'block' : 'none'; ?>;">
                                <label for="conteudo_entrega_link" class="block text-gray-300 text-sm font-medium mb-2">URL de Acesso</label>
                                <input type="url" id="conteudo_entrega_link" name="conteudo_entrega_link" class="w-full px-4 py-3 bg-dark-elevated border border-dark-border rounded-lg focus:ring-2 transition-all text-white placeholder-gray-500" style="--tw-ring-color: color-mix(in srgb, var(--accent-primary) 20%, transparent);" onfocus="this.style.borderColor='var(--accent-primary)'" onblur="this.style.borderColor='rgba(255,255,255,0.1)'" placeholder="https://" value="<?php echo ($produto_edit['tipo_entrega'] ?? '') === 'link' ? htmlspecialchars($produto_edit['conteudo_entrega'] ?? '') : ''; ?>">
                            </div>

                            <div id="entrega-pdf-container" class="animate-fade-in-down" style="display: <?php echo (($produto_edit['tipo_entrega'] ?? '') === 'email_pdf') ? 'block' : 'none'; ?>;">
                                <label class="block text-gray-300 text-sm font-medium mb-2">Upload do Arquivo PDF</label>
                                <?php if (($produto_edit['tipo_entrega'] ?? '') == 'email_pdf' && !empty($produto_edit['conteudo_entrega'])): ?>
                                    <div class="flex items-center space-x-3 mb-3 p-3 bg-dark-card border border-dark-border rounded-lg shadow-sm">
                                        <div class="bg-red-900/30 p-2 rounded-lg"><i data-lucide="file-text" class="w-5 h-5 text-red-400"></i></div>
                                        <div class="flex-1 truncate">
                                            <p class="text-xs text-gray-400">Arquivo Atual:</p>
                                            <p class="text-sm font-medium text-white truncate"><?php echo htmlspecialchars($produto_edit['conteudo_entrega']); ?></p>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                <label class="flex flex-col items-center justify-center w-full h-32 border-2 border-dark-border border-dashed rounded-lg cursor-pointer bg-dark-elevated hover:bg-dark-card transition-colors">
                                    <div class="flex flex-col items-center justify-center pt-5 pb-6">
                                        <i data-lucide="upload-cloud" class="w-8 h-8 text-gray-400 mb-2"></i>
                                        <p class="text-sm text-gray-400"><span class="font-semibold">Clique para enviar</span> ou arraste</p>
                                        <p class="text-xs text-gray-400">PDF (MAX. 10MB)</p>
                                    </div>
                                    <input type="file" id="conteudo_entrega_pdf" name="conteudo_entrega_pdf" class="hidden" accept="application/pdf">
                                </label>
                                <div id="pdf-file-name" class="mt-2 text-sm text-gray-400 font-medium text-center hidden"></div>
                            </div>

                            <div id="entrega-membros-container" class="animate-fade-in-down" style="display: <?php echo (($produto_edit['tipo_entrega'] ?? '') === 'area_membros') ? 'block' : 'none'; ?>;">
                                <div class="flex items-start p-4 bg-blue-900/20 border border-blue-500/30 rounded-lg">
                                    <i data-lucide="info" class="w-5 h-5 text-blue-400 mt-0.5 mr-3 flex-shrink-0"></i>
                                    <div>
                                        <h4 class="font-bold text-blue-300 text-sm">Integração Automática</h4>
                                        <p class="text-sm text-blue-200 mt-1">O acesso será liberado automaticamente na área "Meus Cursos" do aluno após a confirmação do pagamento.</p>
                                    </div>
                                </div>
                            </div>

                            <div id="entrega-fisico-container" class="animate-fade-in-down" style="display: <?php echo (($produto_edit['tipo_entrega'] ?? '') === 'produto_fisico') ? 'block' : 'none'; ?>;">
                                <div class="flex items-start p-4 bg-green-900/20 border border-green-500/30 rounded-lg">
                                    <i data-lucide="info" class="w-5 h-5 text-green-400 mt-0.5 mr-3 flex-shrink-0"></i>
                                    <div>
                                        <h4 class="font-bold text-green-300 text-sm">Produto Físico</h4>
                                        <p class="text-sm text-green-200 mt-1">O cliente precisará informar o endereço de entrega no checkout. Os dados serão salvos junto com a venda.</p>
                                    </div>
                                </div>
                            </div>

                            <div id="entrega-pagamento-container" class="animate-fade-in-down" style="display: <?php echo (($produto_edit['tipo_entrega'] ?? '') === 'somente_pagamento') ? 'block' : 'none'; ?>;">
                                <div class="flex items-start p-4 bg-yellow-900/20 border border-yellow-500/30 rounded-lg">
                                    <i data-lucide="info" class="w-5 h-5 text-yellow-400 mt-0.5 mr-3 flex-shrink-0"></i>
                                    <div>
                                        <h4 class="font-bold text-yellow-300 text-sm">Somente link de pagamento</h4>
                                        <p class="text-sm text-yellow-200 mt-1">Nenhum email será enviado ao cliente após o pagamento ser aprovado. Use esta opção para produtos que são apenas links de checkout sem necessidade de entrega automática.</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Coluna Direita: Imagem e Gateway -->
                <div class="md:col-span-4 space-y-6">
                    <!-- Upload de Imagem -->
                    <div>
                        <label class="block text-gray-300 text-sm font-semibold mb-2">Capa do Produto</label>
                        <div class="relative group">
                            <div class="w-full h-64 bg-dark-elevated rounded-xl overflow-hidden border-2 border-dark-border border-dashed flex items-center justify-center relative">
                                <?php if ($produto_edit && !empty($produto_edit['foto'])): ?>
                                    <img src="<?php echo $upload_dir . htmlspecialchars($produto_edit['foto']); ?>" id="preview-img" class="absolute inset-0 w-full h-full object-cover">
                                <?php else: ?>
                                    <img id="preview-img" class="absolute inset-0 w-full h-full object-cover hidden">
                                    <div id="placeholder-img" class="text-center p-4">
                                        <i data-lucide="image" class="w-12 h-12 text-gray-500 mx-auto mb-2"></i>
                                        <p class="text-sm text-gray-400">Nenhuma imagem selecionada</p>
                                    </div>
                                <?php endif; ?>
                                
                                <!-- Overlay para troca -->
                                <label for="foto" class="absolute inset-0 bg-black bg-opacity-0 group-hover:bg-opacity-40 transition-all duration-300 flex items-center justify-center cursor-pointer">
                                    <span class="bg-dark-card text-white px-4 py-2 rounded-full shadow-lg font-medium text-sm transform scale-90 opacity-0 group-hover:scale-100 group-hover:opacity-100 transition-all">
                                        <i data-lucide="camera" class="w-4 h-4 inline mr-1"></i> Alterar Capa
                                    </span>
                                </label>
                            </div>
                            <input type="file" id="foto" name="foto" class="hidden" accept="image/png, image/jpeg, image/webp" onchange="previewImage(this)">
                        </div>
                        <p class="text-xs text-gray-400 mt-2 text-center">Recomendado: 800x800px (JPG/PNG)</p>
                    </div>
                </div>
            </div>

            <!-- Footer do Form -->
            <div class="flex items-center justify-end space-x-4 mt-8 pt-6 border-t border-dark-border">
                <button type="button" id="cancelar-btn" class="px-6 py-2.5 rounded-lg text-gray-300 hover:bg-dark-elevated hover:text-white font-medium transition-colors">Cancelar</button>
                <button type="submit" name="salvar_produto" class="text-white font-bold py-2.5 px-8 rounded-lg shadow-md hover:shadow-lg hover:-translate-y-0.5 transition-all duration-300 flex items-center" style="background-color: var(--accent-primary);" onmouseover="this.style.backgroundColor='var(--accent-primary-hover)'" onmouseout="this.style.backgroundColor='var(--accent-primary)'">
                    <i data-lucide="save" class="w-4 h-4 mr-2"></i>
                    <?php echo $produto_edit ? 'Salvar Alterações' : 'Cadastrar Produto'; ?>
                </button>
            </div>
        </form>
        <?php do_action('product_form_after'); ?>
    </div>

    <!-- Grid de Produtos -->
    <div class="animate-fade-in-up">
        <?php if (empty($produtos)): ?>
            <div class="bg-dark-card rounded-2xl shadow-sm p-12 text-center" style="border-color: var(--accent-primary);">
                <div class="inline-flex items-center justify-center w-20 h-20 bg-dark-elevated rounded-full mb-6">
                    <i data-lucide="package-open" class="w-10 h-10 text-gray-500"></i>
                </div>
                <h3 class="text-xl font-bold text-white mb-2">Nenhum produto encontrado</h3>
                <p class="text-gray-400 mb-8 max-w-md mx-auto">Seu catálogo está vazio. Comece adicionando seu primeiro produto digital agora mesmo.</p>
                <button onclick="document.getElementById('novo-produto-btn').click()" style="color: var(--accent-primary);" onmouseover="this.style.color='var(--accent-primary-hover)'" onmouseout="this.style.color='var(--accent-primary)'" class="font-bold hover:underline">
                    Criar meu primeiro produto &rarr;
                </button>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-8">
                <?php foreach ($produtos as $produto): ?>
                    <div class="bg-dark-card rounded-2xl shadow-sm hover:shadow-xl transition-all duration-300 border border-dark-border flex flex-col overflow-hidden group">
                        
                        <!-- Capa do Card -->
                        <div class="relative h-56 overflow-hidden bg-dark-elevated">
                            <?php if ($produto['foto']): ?>
                                <img src="<?php echo $upload_dir . htmlspecialchars($produto['foto']); ?>" alt="<?php echo htmlspecialchars($produto['nome']); ?>" class="w-full h-full object-cover transition-transform duration-500 group-hover:scale-110">
                            <?php else: ?>
                                <div class="w-full h-full flex flex-col items-center justify-center text-gray-500">
                                    <i data-lucide="image" class="w-12 h-12 mb-2"></i>
                                    <span class="text-xs font-medium">Sem imagem</span>
                                </div>
                            <?php endif; ?>
                            
                            <!-- Botões de Ação (Canto Superior Direito) -->
                            <div class="absolute top-3 right-3 flex flex-col gap-2">
                                <!-- Botão de Editar -->
                                <a href="/index?pagina=produto_config&id=<?php echo $produto['id']; ?>&aba=geral" class="bg-white/90 hover:bg-white text-gray-800 p-2 rounded-lg shadow-md transition-all duration-200 hover:shadow-lg backdrop-blur-sm" title="Editar Produto">
                                    <i data-lucide="pencil" class="w-4 h-4"></i>
                                </a>
                                
                                <!-- Botão de Excluir -->
                                <form method="post" action="/index?pagina=produtos" onsubmit="return confirm('Tem certeza que deseja excluir este produto? Esta ação não pode ser desfeita.');">
                                    <?php
                                    if (!isset($csrf_token)) {
                                        $csrf_token = generate_csrf_token();
                                    }
                                    ?>
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                    <input type="hidden" name="id_produto" value="<?php echo $produto['id']; ?>">
                                    <button type="submit" name="deletar_produto" class="bg-white/90 hover:bg-white text-red-600 p-2 rounded-lg shadow-md transition-all duration-200 hover:shadow-lg backdrop-blur-sm w-full" title="Excluir Produto">
                                        <i data-lucide="trash-2" class="w-4 h-4"></i>
                                    </button>
                                </form>
                            </div>
                        </div>

                        <!-- Info do Card -->
                        <div class="p-5 flex-grow flex flex-col">
                            <h3 class="font-bold text-white text-lg leading-snug mb-2 line-clamp-2 min-h-[3.5rem]" title="<?php echo htmlspecialchars($produto['nome']); ?>">
                                <?php echo htmlspecialchars($produto['nome']); ?>
                            </h3>
                            
                            <div class="mt-auto flex items-end justify-between border-t border-dark-border pt-4">
                                <div>
                                    <p class="text-xs text-gray-400 uppercase font-semibold">Preço</p>
                                    <p class="font-bold text-xl" style="color: var(--accent-primary);">R$ <?php echo number_format($produto['preco'], 2, ',', '.'); ?></p>
                                </div>
                                <div class="text-gray-500" title="Tipo de Entrega">
                                    <?php if($produto['tipo_entrega'] == 'link'): ?>
                                        <i data-lucide="link" class="w-5 h-5"></i>
                                    <?php elseif($produto['tipo_entrega'] == 'email_pdf'): ?>
                                        <i data-lucide="file-text" class="w-5 h-5"></i>
                                    <?php else: ?>
                                        <i data-lucide="lock" class="w-5 h-5"></i>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
    // Inicializa ícones Lucide
    lucide.createIcons();

    // Preview de Imagem
    function previewImage(input) {
        if (input.files && input.files[0]) {
            var reader = new FileReader();
            reader.onload = function(e) {
                const preview = document.getElementById('preview-img');
                const placeholder = document.getElementById('placeholder-img');
                preview.src = e.target.result;
                preview.classList.remove('hidden');
                placeholder.classList.add('hidden');
            }
            reader.readAsDataURL(input.files[0]);
        }
    }

    // Input file PDF feedback
    document.getElementById('conteudo_entrega_pdf').addEventListener('change', function(e) {
        const fileName = e.target.files[0] ? e.target.files[0].name : '';
        const display = document.getElementById('pdf-file-name');
        if (fileName) {
            display.textContent = 'Arquivo selecionado: ' + fileName;
            display.classList.remove('hidden');
        } else {
            display.classList.add('hidden');
        }
    });

    // Função Copiar Link com Feedback Visual Melhorado
    function copiarLink(link, btn) {
        navigator.clipboard.writeText(link).then(() => {
            const icon = btn.querySelector('svg'); // Pega o SVG gerado pelo Lucide
            const originalIconHtml = btn.innerHTML; // Salva o HTML original (pode ser o SVG)
            
            // Troca o ícone/classe
            btn.innerHTML = '<i data-lucide="check" class="w-5 h-5"></i>'; // Adiciona o check
            btn.style.backgroundColor = getComputedStyle(document.documentElement).getPropertyValue('--accent-primary').trim() || '#32e768';
            btn.style.color = 'white';
            btn.classList.remove('bg-dark-card', 'text-white');
            
            lucide.createIcons(); // Renderiza o check

            setTimeout(() => {
                btn.innerHTML = originalIconHtml; // Restaura o original (seja SVG ou <i>)
                
                // Se o original era <i>, precisa renderizar novamente
                if (originalIconHtml.includes('data-lucide')) {
                    lucide.createIcons();
                }

                btn.style.backgroundColor = '';
                btn.style.color = '';
                btn.classList.add('bg-dark-card', 'text-white');
            }, 2000);
        });
    }

    document.addEventListener('DOMContentLoaded', function() {
        const formContainer = document.getElementById('form-container');
        const novoProdutoBtn = document.getElementById('novo-produto-btn');
        const cancelarBtn = document.getElementById('cancelar-btn');
        const fecharFormBtn = document.getElementById('fechar-form-btn');

        function toggleForm(show) {
            if (show) {
                formContainer.style.display = 'block';
                // Scroll suave até o formulário
                formContainer.scrollIntoView({ behavior: 'smooth', block: 'start' });
                novoProdutoBtn.classList.add('opacity-50', 'cursor-not-allowed');
                novoProdutoBtn.disabled = true;
            } else {
                formContainer.style.display = 'none';
                novoProdutoBtn.classList.remove('opacity-50', 'cursor-not-allowed');
                novoProdutoBtn.disabled = false;
                
                // Limpa parâmetro URL
                const url = new URL(window.location);
                url.searchParams.delete('editar');
                window.history.replaceState({}, document.title, url);
            }
        }

        novoProdutoBtn.addEventListener('click', () => toggleForm(true));
        fecharFormBtn.addEventListener('click', () => toggleForm(false));
        cancelarBtn.addEventListener('click', () => {
            // Se estiver editando, volta para o padrão (pode recarregar ou só fechar)
            window.location.href = '/index?pagina=produtos';
        });

        const urlParams = new URLSearchParams(window.location.search);
        // Abre o form apenas se estiver editando ou se tiver mensagem de erro/sucesso (mas não de exclusão)
        const alertElement = document.querySelector('[role="alert"]');
        const isDeleteMessage = alertElement && (alertElement.textContent.includes('deletado') || alertElement.textContent.includes('excluir'));
        
        if (urlParams.has('editar') || (alertElement && !isDeleteMessage)) { 
            toggleForm(true);
        } else {
            toggleForm(false);
        }

        // Lógica de Entrega (Grid) - Função global para onclick
        window.selectEntregaOption = function(value) {
            const tipoEntregaHidden = document.getElementById('tipo_entrega');
            const linkContainer = document.getElementById('entrega-link-container');
            const pdfContainer = document.getElementById('entrega-pdf-container');
            const membrosContainer = document.getElementById('entrega-membros-container');
            const fisicoContainer = document.getElementById('entrega-fisico-container');
            const pagamentoContainer = document.getElementById('entrega-pagamento-container');
            const linkInput = document.getElementById('conteudo_entrega_link');
            const cards = document.querySelectorAll('.entrega-option-card');
            
            // Atualiza o valor do input hidden
            if (tipoEntregaHidden) tipoEntregaHidden.value = value;
            
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
            if (linkContainer) linkContainer.style.display = 'none';
            if (pdfContainer) pdfContainer.style.display = 'none';
            if (membrosContainer) membrosContainer.style.display = 'none';
            if (fisicoContainer) fisicoContainer.style.display = 'none';
            if (pagamentoContainer) pagamentoContainer.style.display = 'none';
            
            // Reset required
            if (linkInput) linkInput.required = false;
            
            // Mostra o container correspondente
            if (value === 'link') {
                if (linkContainer) linkContainer.style.display = 'block';
                if (linkInput) linkInput.required = true;
            } else if (value === 'email_pdf') {
                if (pdfContainer) pdfContainer.style.display = 'block';
            } else if (value === 'area_membros') {
                if (membrosContainer) membrosContainer.style.display = 'block';
            } else if (value === 'produto_fisico') {
                if (fisicoContainer) fisicoContainer.style.display = 'block';
            } else if (value === 'somente_pagamento') {
                if (pagamentoContainer) pagamentoContainer.style.display = 'block';
            }
        };

        // Inicializar com o valor atual após o DOM estar pronto
        const tipoEntregaHidden = document.getElementById('tipo_entrega');
        if (tipoEntregaHidden && tipoEntregaHidden.value) {
            window.selectEntregaOption(tipoEntregaHidden.value);
        }
    });
</script>
<?php do_action('produtos_list_after'); ?>