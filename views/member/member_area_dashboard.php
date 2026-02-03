<?php
require_once __DIR__ . '/../../config/config.php';

// Verificar se o modo SaaS está ativado
$saas_enabled = false;
if (file_exists(__DIR__ . '/../../saas/includes/saas_functions.php')) {
    require_once __DIR__ . '/../../saas/includes/saas_functions.php';
    if (function_exists('saas_enabled')) {
        $saas_enabled = saas_enabled();
    }
}

// ... (código de sessão PHP inalterado) ...
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: /member_login");
    exit;
}
if (isset($_SESSION["tipo"]) && $_SESSION["tipo"] === 'admin') {
    header("location: /admin");
    exit;
}
$cliente_email = $_SESSION['usuario'];
$cliente_nome = $_SESSION['nome'] ?? $cliente_email;
$usuario_id = $_SESSION['id'] ?? 0;
$usuario_tipo = $_SESSION['tipo'] ?? '';
$is_infoprodutor = ($usuario_tipo === 'infoprodutor');
$cursos_adquiridos = [];
$upload_dir = 'uploads/'; 

try {
    // Query que combina cursos comprados (alunos_acessos) com cursos criados (produtos)
    // Se for infoprodutor, inclui cursos que ele criou
    // Agora também busca informações da venda para reembolso
    $sql = "
        SELECT DISTINCT
            p.id AS produto_id,
            p.nome AS produto_nome,
            p.foto AS produto_foto,
            c.titulo AS curso_titulo,
            c.descricao AS curso_descricao,
            c.imagem_url AS curso_imagem_url,
            c.banner_url AS curso_banner_url,
            combined.data_concessao,
            v.id AS venda_id,
            v.data_venda,
            v.status_pagamento,
            COALESCE((SELECT COUNT(*) FROM reembolsos r WHERE r.venda_id = v.id AND r.status = 'pending'), 0) AS tem_reembolso_pending,
            COALESCE((SELECT COUNT(*) FROM reembolsos r WHERE r.venda_id = v.id AND r.status = 'approved'), 0) AS tem_reembolso_approved
        FROM (
            -- Cursos comprados (via alunos_acessos)
            SELECT aa.produto_id, aa.data_concessao
            FROM alunos_acessos aa
            JOIN produtos p ON aa.produto_id = p.id
            WHERE aa.aluno_email = ? AND p.tipo_entrega = 'area_membros'
            
            UNION
            
            -- Cursos criados pelo infoprodutor (se for infoprodutor)
            SELECT p.id AS produto_id, p.data_criacao AS data_concessao
            FROM produtos p
            WHERE p.usuario_id = ? AND p.tipo_entrega = 'area_membros'
        ) AS combined
        JOIN produtos p ON combined.produto_id = p.id
        LEFT JOIN cursos c ON p.id = c.produto_id
        LEFT JOIN (
            SELECT v1.*
            FROM vendas v1
            INNER JOIN (
                SELECT produto_id, comprador_email, MAX(data_venda) as max_data_venda
                FROM vendas
                WHERE status_pagamento = 'approved'
                GROUP BY produto_id, comprador_email
            ) v2 ON v1.produto_id = v2.produto_id 
                AND v1.comprador_email = v2.comprador_email
                AND v1.data_venda = v2.max_data_venda
                AND v1.status_pagamento = 'approved'
        ) v ON v.produto_id = p.id AND v.comprador_email = ?
        ORDER BY combined.data_concessao DESC
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$cliente_email, $usuario_id, $cliente_email]);
    $cursos_adquiridos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Debug: log para verificar se venda_id está sendo retornado
    error_log("Member Area Dashboard: Total de cursos encontrados: " . count($cursos_adquiridos));
    foreach ($cursos_adquiridos as $idx => $curso) {
        if (!empty($curso['venda_id'])) {
            error_log("Member Area Dashboard: Curso {$curso['produto_id']} tem venda_id: {$curso['venda_id']}");
        }
    }

} catch (PDOException $e) {
    $mensagem_erro = "<div class='bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4' role='alert'>Erro ao buscar seus cursos: " . htmlspecialchars($e->getMessage()) . "</div>";
    $cursos_adquiridos = [];
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale-1.0">
    <title>Meus Cursos - Área de Membros Starfy</title>
    <?php
    // Carregar configurações do sistema (inclui cor primária)
    include __DIR__ . '/../../config/load_settings.php';
    
    // Adiciona favicon se configurado
    $favicon_url_raw = getSystemSetting('favicon_url', '');
    if (!empty($favicon_url_raw)) {
        $favicon_url = ltrim($favicon_url_raw, '/');
        if (strpos($favicon_url, 'http') !== 0) {
            if (strpos($favicon_url, 'uploads/') === 0) {
                $favicon_url = '/' . $favicon_url;
            } else {
                $favicon_url = '/' . $favicon_url;
            }
        }
        $favicon_ext = strtolower(pathinfo($favicon_url, PATHINFO_EXTENSION));
        $favicon_type = 'image/x-icon';
        if ($favicon_ext === 'png') {
            $favicon_type = 'image/png';
        } elseif ($favicon_ext === 'svg') {
            $favicon_type = 'image/svg+xml';
        }
        echo '<link rel="icon" type="' . htmlspecialchars($favicon_type) . '" href="' . htmlspecialchars($favicon_url) . '">' . "\n";
    }
    ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    <!-- Swiper CSS (Apenas para as Ofertas) -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.css" />
    <style>
        body { font-family: 'Inter', sans-serif; }
        
        /* * Estilos do Swiper para "Ofertas Exclusivas"
         * ATUALIZAÇÃO: Aumentamos o tamanho dos cards
         */
        
        /* O slide de oferta (maior) */
        .offer-swiper-slide {
            width: 280px; /* ANTES: 220px */
            height: auto; 
            flex-shrink: 0;
        }

        /* O card de oferta */
        .offer-card-style {
            display: flex;
            flex-direction: column;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            background-color: #141414;
            border: 2px solid #262626;
            height: 100%;
            /* ATUALIZAÇÃO: Nova transição */
            transition: all 0.3s ease-in-out;
        }
        /* ATUALIZAÇÃO: Efeito "chamativo" no hover */
        .offer-card-style:hover {
            transform: translateY(-5px); /* ANTES: scale(1.03) foi REMOVIDO */
            box-shadow: 0 10px 25px rgba(0,0,0,0.3); /* Sombra mais forte */
            border-color: var(--accent-primary); /* Borda com cor primária */
        }
        
        /* Container da imagem de oferta (maior) */
        .offer-image-container {
            width: 100%;
            height: 160px; /* ANTES: 130px */
            background-color: #1a1a1a;
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            border-radius: 10px 10px 0 0;
        }
        .offer-image-container img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 10px 10px 0 0;
            /* ATUALIZAÇÃO: Adicionada transição para o zoom suave */
            transition: transform 0.3s ease-in-out;
        }

        /* ATUALIZAÇÃO: Nova regra para o zoom da IMAGEM, imitando os cards principais */
        .offer-card-style:hover .offer-image-container img {
            transform: scale(1.05);
        }

        /* Controles de Navegação do Swiper (Setas) */
        .swiper-button-next, .swiper-button-prev {
            color: var(--accent-primary); /* Cor primária */
            width: 40px;
            height: 40px;
            background-color: rgba(255, 255, 255, 0.9);
            border-radius: 50%;
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
            transition: all 0.3s;
            transform: translateY(-50%) scale(0.9);
            opacity: 0;
            position: absolute;
            top: 50%;
            z-index: 10;
        }
        .swiper-button-next:hover, .swiper-button-prev:hover {
            background-color: var(--accent-primary);
            color: white;
            transform: translateY(-50%) scale(1);
        }
        .swiper-button-next::after, .swiper-button-prev::after {
            font-size: 1.5rem;
        }
        .swiper-button-prev { left: 10px; }
        .swiper-button-next { right: 10px; }

        .swiper-container:hover .swiper-button-next,
        .swiper-container:hover .swiper-button-prev {
            opacity: 1;
            transform: translateY(-50%) scale(1);
        }

        /* Paginação do Swiper (Pontos) */
        .swiper-pagination-bullet {
            background-color: #6b7280; /* gray-500 */
            width: 10px;
            height: 10px;
        }
        .swiper-pagination-bullet-active {
            background-color: var(--accent-primary); /* Cor primária */
        }

        /* Estilo do Swiper de Ofertas */
        .exclusiveOffersSwiper {
            width: 100%;
            height: 100%;
            padding-bottom: 40px; /* Espaço para paginação */
        }

        /* Styles for padlock icon on exclusive offers */
        .offer-card-style .lock-icon {
            position: absolute;
            top: 8px;
            right: 8px;
            background-color: rgba(0, 0, 0, 0.6);
            border-radius: 50%;
            padding: 6px;
            color: var(--accent-primary); /* Cor primária */
            z-index: 5;
            box-shadow: 0 2px 4px rgba(0,0,0,0.2);
        }
    </style>
</head>
<body class="text-gray-200 antialiased" style="background-color: #0d0d0d;">

    <!-- Cabeçalho Premium Fixo -->
    <header class="sticky top-0 z-50 w-full backdrop-blur-sm" style="background-color: rgba(13, 13, 13, 0.85); border-bottom: 1px solid #262626;">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center h-20">
                <div class="flex items-center space-x-4">
                    <a href="/member_area_dashboard">
                        <?php
                        $logo_url_raw = getSystemSetting('logo_url', 'https://i.ibb.co/0RGhGvMt/Gemini-Generated-Image-hdcuf5hdcuf5hdcu-Photoroom.png');
                        $logo_url = ltrim($logo_url_raw, '/');
                        if (!empty($logo_url) && strpos($logo_url, 'http') !== 0) {
                            if (strpos($logo_url, 'uploads/') === 0) {
                                $logo_url = '/' . $logo_url;
                            } else {
                                $logo_url = '/' . $logo_url;
                            }
                        }
                        ?>
                        <img src="<?php echo htmlspecialchars($logo_url); ?>" alt="<?php echo htmlspecialchars(getSystemSetting('nome_plataforma', 'Starfy')); ?> Logo" class="h-10">
                    </a>
                </div>
                <div class="flex items-center space-x-5">
                    <span class="font-medium hidden md:block text-gray-300">Olá,</span>
                    
                    <!-- Dropdown do Perfil (sempre visível) -->
                    <div class="relative" id="profile-dropdown-container">
                        <button id="profile-dropdown-btn" class="flex items-center space-x-2 text-gray-300 hover:text-white transition-colors group">
                            <i data-lucide="user" class="w-5 h-5"></i>
                            <span class="hidden sm:block font-medium"><?php echo htmlspecialchars($cliente_nome); ?></span>
                            <i data-lucide="chevron-down" class="w-4 h-4 text-gray-400"></i>
                        </button>
                        
                        <!-- Dropdown Menu -->
                        <div id="profile-dropdown-menu" class="hidden absolute right-0 mt-2 w-56 rounded-lg shadow-xl z-50 overflow-hidden" style="background-color: #141414; border: 1px solid #262626;">
                            <?php if ($saas_enabled): ?>
                                <button id="switch-to-infoprodutor" class="w-full flex items-center space-x-3 px-4 py-3 text-white transition-colors text-left" style="background-color: transparent;" onmouseover="this.style.backgroundColor='#1a1a1a'" onmouseout="this.style.backgroundColor='transparent'">
                                    <i data-lucide="layout-dashboard" class="w-5 h-5"></i>
                                    <span>Mudar para Painel do Produtor</span>
                                </button>
                                <div style="border-top: 1px solid #262626;"></div>
                            <?php endif; ?>
                            <a href="/member_logout" class="flex items-center space-x-3 px-4 py-3 text-red-400 transition-colors" style="background-color: transparent;" onmouseover="this.style.backgroundColor='#1a1a1a'" onmouseout="this.style.backgroundColor='transparent'">
                                <i data-lucide="log-out" class="w-5 h-5"></i>
                                <span>Sair</span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </header>

    <main class="max-w-7xl mx-auto py-12 px-4 sm:px-6 lg:px-8">
        <?php if (isset($mensagem_erro)) echo $mensagem_erro; ?>

        <!-- Novo Título Premium -->
        <div class_id="intro-header" class="mb-10">
            <h1 class="text-4xl font-extrabold mb-2" style="background: linear-gradient(to right, var(--accent-primary), rgba(255, 255, 255, 0.6)); -webkit-background-clip: text; background-clip: text; -webkit-text-fill-color: transparent;">
                Sua Biblioteca de Cursos
            </h1>
            <p class="text-xl text-gray-400">
                Todo seu conhecimento adquirido em um só lugar. Pronto para começar?
            </p>
        </div>


        <?php if (empty($cursos_adquiridos)): ?>
            <!-- Tela de Boas-Vindas / Vazio -->
            <div class="p-8 rounded-lg shadow-md text-center text-gray-400" style="background-color: #141414; border: 1px solid #262626;">
                <i data-lucide="inbox" class="mx-auto w-16 h-16 text-gray-600 mb-4"></i>
                <p class="text-lg font-semibold text-white">Você ainda não possui cursos</p>
                <p class="mt-2 text-sm">Parece que você ainda não adquiriu nenhum produto. Explore nossa loja ou, se você acredita que isso é um erro, por favor, entre em contato com o suporte.</p>
            </div>
        <?php else: ?>
            
            <!-- 
                NOVO LAYOUT: GRID DE CURSOS 
            -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8 mb-16">
                
                <?php foreach ($cursos_adquiridos as $curso): 
                    // Verificar se pode solicitar reembolso (apenas para cursos comprados, não criados)
                    $pode_solicitar_reembolso = false;
                    $dias_desde_compra = null;
                    if (!empty($curso['venda_id']) && !empty($curso['data_venda']) && $curso['status_pagamento'] === 'approved') {
                        $data_venda = new DateTime($curso['data_venda']);
                        $data_atual = new DateTime();
                        $dias_desde_compra = $data_atual->diff($data_venda)->days;
                        $pode_solicitar_reembolso = ($dias_desde_compra <= 7) && 
                                                     empty($curso['tem_reembolso_pending']) && 
                                                     empty($curso['tem_reembolso_approved']);
                    }
                ?>
                    <!-- O Card do Curso (agora em grid, não swiper) -->
                    <div class="group rounded-2xl shadow-lg overflow-hidden transition-all duration-300 hover:shadow-2xl hover:scale-[1.02] flex flex-col relative" style="background-color: #141414; border: 1px solid #262626;" onmouseover="this.style.borderColor='#333'" onmouseout="this.style.borderColor='#262626'">
                        
                        <!-- Botão de 3 pontinhos (menu dropdown) -->
                        <!-- Sempre mostrar o botão, mas só habilitar se houver venda_id -->
                        <div class="absolute top-2 right-2 z-50" style="z-index: 50;">
                            <button 
                                type="button"
                                class="refund-menu-btn p-2 rounded-full transition-colors text-gray-300 hover:text-white"
                                style="background-color: rgba(26, 26, 26, 0.8);" onmouseover="this.style.backgroundColor='#1a1a1a'" onmouseout="this.style.backgroundColor='rgba(26, 26, 26, 0.8)'"
                                data-produto-id="<?php echo $curso['produto_id']; ?>"
                                data-venda-id="<?php echo $curso['venda_id'] ?? ''; ?>"
                                data-pode-reembolso="<?php echo $pode_solicitar_reembolso ? '1' : '0'; ?>"
                                data-dias-compra="<?php echo $dias_desde_compra ?? ''; ?>"
                                onclick="event.stopPropagation(); event.preventDefault(); toggleRefundMenu(this);"
                                title="<?php echo !empty($curso['venda_id']) ? 'Opções do curso' : 'Curso sem compra registrada'; ?>">
                                <i data-lucide="more-vertical" class="w-5 h-5"></i>
                            </button>
                            
                            <!-- Dropdown Menu -->
                            <div class="refund-menu-dropdown hidden absolute right-0 mt-2 w-48 rounded-lg shadow-xl overflow-hidden" style="z-index: 100; background-color: #141414; border: 1px solid #262626;">
                                <?php if (empty($curso['venda_id'])): ?>
                                <div class="px-4 py-3 text-gray-500 text-sm">
                                    <i data-lucide="info" class="w-4 h-4 inline mr-2"></i>
                                    Sem compra registrada
                                </div>
                                <?php elseif ($pode_solicitar_reembolso): ?>
                                <button 
                                    type="button"
                                    class="w-full text-left px-4 py-3 text-white transition-colors flex items-center space-x-2"
                                    style="background-color: transparent;" onmouseover="this.style.backgroundColor='#1a1a1a'" onmouseout="this.style.backgroundColor='transparent'"
                                    onclick="event.stopPropagation(); openRefundModal(<?php echo $curso['produto_id']; ?>, <?php echo $curso['venda_id']; ?>);">
                                    <i data-lucide="refresh-ccw" class="w-4 h-4"></i>
                                    <span>Solicitar Reembolso</span>
                                </button>
                                <?php elseif (!empty($curso['tem_reembolso_pending']) && $curso['tem_reembolso_pending'] > 0): ?>
                                <div class="px-4 py-3 text-gray-400 text-sm">
                                    <i data-lucide="clock" class="w-4 h-4 inline mr-2"></i>
                                    Reembolso pendente
                                </div>
                                <?php elseif (!empty($curso['tem_reembolso_approved']) && $curso['tem_reembolso_approved'] > 0): ?>
                                <div class="px-4 py-3 text-sm" style="color: var(--accent-primary);">
                                    <i data-lucide="check-circle" class="w-4 h-4 inline mr-2"></i>
                                    Reembolso aprovado
                                </div>
                                <?php elseif ($dias_desde_compra !== null && $dias_desde_compra > 7): ?>
                                <div class="px-4 py-3 text-gray-500 text-sm">
                                    <i data-lucide="x-circle" class="w-4 h-4 inline mr-2"></i>
                                    Prazo expirado
                                </div>
                                <?php else: ?>
                                <div class="px-4 py-3 text-gray-400 text-sm">
                                    <i data-lucide="info" class="w-4 h-4 inline mr-2"></i>
                                    Sem opções disponíveis
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Link para o curso (cobre todo o card exceto o botão) -->
                        <a href="/member_course_view?produto_id=<?php echo $curso['produto_id']; ?>" 
                           class="flex flex-col flex-grow">
                        
                        <!-- A "Capa" Robusta -->
                        <div class="relative aspect-video overflow-hidden">
                            <?php 
                            // ***********************************************
                            // LÓGICA DE IMAGEM CORRIGIDA E PRIORIZADA
                            // ***********************************************
                            $image_path = null;
                            $placeholder_url = 'https://placehold.co/600x400/1f2937/9ca3af?text=Curso+Sem+Imagem';

                            // 1. Priorizar a foto do PRODUTO (capa principal)
                            if (!empty($curso['produto_foto'])) {
                                $image_path = $upload_dir . $curso['produto_foto'];
                            } 
                            // 2. Se não houver, tentar a imagem do CURSO (módulo)
                            elseif (!empty($curso['curso_imagem_url'])) {
                                // Verificar se é uma URL completa ou um nome de arquivo
                                if (filter_var($curso['curso_imagem_url'], FILTER_VALIDATE_URL)) {
                                    $image_path = $curso['curso_imagem_url']; // É uma URL completa
                                } else {
                                    $image_path = $upload_dir . $curso['curso_imagem_url']; // Assumir que é um arquivo local
                                }
                            }

                            // 3. Se ainda assim for nulo, definir o placeholder diretamente
                            if (empty($image_path)) {
                                $image_path = $placeholder_url; 
                            }
                            ?>
                            <img src="<?php echo htmlspecialchars($image_path); ?>" 
                                 alt="<?php echo htmlspecialchars($curso['curso_titulo'] ?? $curso['produto_nome']); ?>"
                                 class="w-full h-full object-cover transition-transform duration-300 group-hover:scale-105"
                                 onerror="this.onerror=null; this.src='<?php echo $placeholder_url; ?>';">
                            
                            <!-- Overlay de "Play" que aparece no hover -->
                            <div class="absolute inset-0 bg-black/50 opacity-0 group-hover:opacity-100 transition-opacity duration-300 flex items-center justify-center">
                                <i data-lucide="play-circle" class="w-16 h-16 text-white/80"></i>
                            </div>
                        </div>

                        <!-- Informações do Card -->
                        <div class="p-6 flex flex-col flex-grow">
                            <h3 class="text-2xl font-bold text-white mb-3 line-clamp-2">
                                <?php echo htmlspecialchars($curso['curso_titulo'] ?? $curso['produto_nome']); ?>
                            </h3>
                            <p class="text-gray-400 text-sm mb-4 line-clamp-3 flex-grow">
                                <?php echo htmlspecialchars($curso['curso_descricao'] ?? 'Acesse para ver mais detalhes.'); ?>
                            </p>
                            
                            <!-- Barra de Progresso (Exemplo) -->
                            <div class="mt-4">
                                <span class="text-xs font-semibold text-gray-400">Progresso</span>
                                <div class="w-full rounded-full h-2.5 mt-1" style="background-color: #262626;">
                                    <!-- 
                                        NOTA: Isso é um exemplo estático. 
                                        Para funcionar, você precisaria buscar o progresso real do aluno no banco.
                                    -->
                                    <div class="h-2.5 rounded-full" style="width: 45%; background-color: var(--accent-primary);"></div>
                                </div>
                            </div>
                        </div>
                        </a>
                    </div>
                <?php endforeach; ?>

            </div> <!-- Fim do Grid de Cursos -->
        <?php endif; ?>

        <!-- 
            SEÇÃO DE OFERTAS EXCLUSIVAS (COM CARDS MAIORES E MAIS CHAMATIVOS)
        -->
        <h2 class="text-3xl font-extrabold text-gray-100 mb-8 mt-12">Ofertas Exclusivas para Você</h2>
        
        <div id="exclusive-offers-loading" class="p-8 rounded-lg shadow-md text-center text-gray-400" style="display: block; background-color: #141414; border: 1px solid #262626;">
            <svg class="animate-spin h-8 w-8 mx-auto mb-4" style="color: var(--accent-primary);" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.96l2-2.669z"></path>
            </svg>
            <p class="text-lg font-semibold">Carregando ofertas...</p>
        </div>

        <div id="exclusive-offers-empty" class="p-8 rounded-lg shadow-md text-center text-gray-400" style="display: none; background-color: #141414; border: 1px solid #262626;">
            <i data-lucide="tag-off" class="mx-auto w-16 h-16 text-gray-600 mb-4"></i>
            <p class="text-lg font-semibold text-white">Nenhuma oferta exclusiva disponível.</p>
            <p class="mt-2 text-sm">Fique atento para futuras oportunidades!</p>
        </div>

        <!-- Swiper para Ofertas Exclusivas -->
        <div class="swiper exclusiveOffersSwiper swiper-container relative" style="display: none;">
            <div class="swiper-wrapper" id="exclusive-offers-list">
                <!-- Offers will be loaded here by JavaScript -->
            </div>
            <!-- Add Pagination -->
            <div class="swiper-pagination"></div>
            <!-- Add Navigation -->
            <div class="swiper-button-next"></div>
            <div class="swiper-button-prev"></div>
        </div>

    </main>

    <!-- Modal de Solicitação de Reembolso -->
    <div id="refund-modal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
        <div class="rounded-2xl shadow-2xl max-w-md w-full" style="background-color: #141414; border: 1px solid #262626;">
            <div class="p-6">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-2xl font-bold text-white">Solicitar Reembolso</h3>
                    <button 
                        type="button"
                        onclick="closeRefundModal()"
                        class="text-gray-400 hover:text-white transition-colors">
                        <i data-lucide="x" class="w-6 h-6"></i>
                    </button>
                </div>
                
                <div class="mb-4 p-4 rounded-lg" style="background-color: rgba(50, 231, 104, 0.1); border: 1px solid rgba(50, 231, 104, 0.3);">
                    <p class="text-sm" style="color: rgba(50, 231, 104, 0.9);">
                        <i data-lucide="info" class="w-4 h-4 inline mr-2"></i>
                        Você tem direito a reembolso dentro de 7 dias corridos a partir da data da compra, conforme o Código de Defesa do Consumidor (CDC - Art. 49).
                    </p>
                </div>
                
                <form id="refund-form" onsubmit="submitRefundRequest(event); return false;">
                    <input type="hidden" id="refund-produto-id" name="produto_id">
                    <input type="hidden" id="refund-venda-id" name="venda_id">
                    
                    <div class="mb-4">
                        <label for="refund-motivo" class="block text-sm font-medium text-gray-300 mb-2">
                            Motivo do Reembolso <span class="text-gray-500">(opcional)</span>
                        </label>
                        <textarea 
                            id="refund-motivo" 
                            name="motivo"
                            rows="4"
                            class="w-full px-4 py-2 rounded-lg text-white placeholder-gray-400 focus:outline-none focus:ring-2"
                            style="background-color: #1a1a1a; border: 1px solid #333; --tw-ring-color: var(--accent-primary);"
                            placeholder="Descreva o motivo do reembolso (opcional)..."></textarea>
                    </div>
                    
                    <div class="flex space-x-3">
                        <button 
                            type="button"
                            onclick="closeRefundModal()"
                            class="flex-1 px-4 py-2 text-white rounded-lg transition-colors"
                            style="background-color: #1a1a1a;" onmouseover="this.style.backgroundColor='#262626'" onmouseout="this.style.backgroundColor='#1a1a1a'">
                            Cancelar
                        </button>
                        <button 
                            type="submit"
                            id="refund-submit-btn"
                            class="flex-1 px-4 py-2 text-white rounded-lg transition-colors font-semibold"
                            style="background-color: var(--accent-primary);" onmouseover="this.style.backgroundColor='var(--accent-primary-hover)'" onmouseout="this.style.backgroundColor='var(--accent-primary)'">
                            Solicitar Reembolso
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Inicializar ícones do Lucide
        if (typeof lucide !== 'undefined') {
            lucide.createIcons();
        } else {
            console.error('Lucide não carregado');
        }
        
        // Reinicializar ícones quando o DOM estiver pronto
        document.addEventListener('DOMContentLoaded', function() {
            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }
        });
        
        // Função para toggle do menu dropdown
        function toggleRefundMenu(btn) {
            // Fechar todos os outros menus
            document.querySelectorAll('.refund-menu-dropdown').forEach(menu => {
                if (menu !== btn.nextElementSibling) {
                    menu.classList.add('hidden');
                }
            });
            
            // Toggle do menu atual
            const menu = btn.nextElementSibling;
            if (menu) {
                menu.classList.toggle('hidden');
            }
        }
        
        // Fechar menus ao clicar fora
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.refund-menu-btn') && !e.target.closest('.refund-menu-dropdown')) {
                document.querySelectorAll('.refund-menu-dropdown').forEach(menu => {
                    menu.classList.add('hidden');
                });
            }
        });
        
        // Função para abrir modal de reembolso
        function openRefundModal(produtoId, vendaId) {
            document.getElementById('refund-produto-id').value = produtoId;
            document.getElementById('refund-venda-id').value = vendaId;
            document.getElementById('refund-modal').classList.remove('hidden');
            document.getElementById('refund-motivo').value = '';
            lucide.createIcons();
        }
        
        // Função para fechar modal
        function closeRefundModal() {
            document.getElementById('refund-modal').classList.add('hidden');
            document.getElementById('refund-form').reset();
        }
        
        // Função para submeter solicitação de reembolso
        async function submitRefundRequest(event) {
            event.preventDefault();
            
            const produtoId = document.getElementById('refund-produto-id').value;
            const vendaId = document.getElementById('refund-venda-id').value;
            const motivo = document.getElementById('refund-motivo').value.trim();
            const submitBtn = document.getElementById('refund-submit-btn');
            const originalText = submitBtn.textContent;
            
            // Desabilitar botão
            submitBtn.disabled = true;
            submitBtn.textContent = 'Enviando...';
            submitBtn.classList.add('opacity-50', 'cursor-not-allowed');
            
            try {
                const response = await fetch('/api/refund_request.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        produto_id: parseInt(produtoId),
                        motivo: motivo || null
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    // Sucesso
                    alert('Solicitação de reembolso enviada com sucesso! Você receberá um email quando o infoprodutor responder.');
                    closeRefundModal();
                    // Recarregar página para atualizar status
                    window.location.reload();
                } else {
                    // Erro
                    alert(data.error || 'Erro ao solicitar reembolso. Tente novamente.');
                    submitBtn.disabled = false;
                    submitBtn.textContent = originalText;
                    submitBtn.classList.remove('opacity-50', 'cursor-not-allowed');
                }
            } catch (error) {
                console.error('Erro ao solicitar reembolso:', error);
                alert('Erro ao solicitar reembolso. Tente novamente.');
                submitBtn.disabled = false;
                submitBtn.textContent = originalText;
                submitBtn.classList.remove('opacity-50', 'cursor-not-allowed');
            }
        }
        
        // --- Lógica do Dropdown do Perfil (Área de Membros) ---
        const profileDropdownBtn = document.getElementById('profile-dropdown-btn');
        const profileDropdownMenu = document.getElementById('profile-dropdown-menu');
        const switchToInfoprodutorBtn = document.getElementById('switch-to-infoprodutor');
        
        // Toggle dropdown
        if (profileDropdownBtn && profileDropdownMenu) {
            profileDropdownBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                profileDropdownMenu.classList.toggle('hidden');
            });
            
            // Fechar dropdown ao clicar fora
            document.addEventListener('click', (e) => {
                if (!profileDropdownBtn.contains(e.target) && !profileDropdownMenu.contains(e.target)) {
                    profileDropdownMenu.classList.add('hidden');
                }
            });
        }
        
        // Alternar para painel do produtor
        if (switchToInfoprodutorBtn) {
            switchToInfoprodutorBtn.addEventListener('click', async () => {
                try {
                    const response = await fetch('/api/switch_panel.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({ view_mode: 'infoprodutor' })
                    });
                    
                    const data = await response.json();
                    
                    if (data.success) {
                        window.location.href = data.redirect_url;
                    } else {
                        alert('Erro ao alternar painel: ' + (data.error || 'Erro desconhecido'));
                    }
                } catch (error) {
                    console.error('Erro ao alternar painel:', error);
                    alert('Erro ao alternar painel. Tente novamente.');
                }
            });
        }
    </script>
    <!-- Swiper JS -->
    <script src="https://cdn.jsdelivr.net/npm/swiper@11/swiper-bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const uploadDir = '<?php echo $upload_dir; ?>';
            
            var exclusiveOffersSwiper; // Declare here so it can be initialized later

            const exclusiveOffersLoading = document.getElementById('exclusive-offers-loading');
            const exclusiveOffersEmpty = document.getElementById('exclusive-offers-empty');
            const exclusiveOffersSwiperContainer = document.querySelector('.exclusiveOffersSwiper');
            const exclusiveOffersList = document.getElementById('exclusive-offers-list');

            function formatCurrency(value) {
                return parseFloat(value).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
            }

            async function fetchExclusiveOffers() {
                exclusiveOffersLoading.style.display = 'block';
                exclusiveOffersEmpty.style.display = 'none';
                exclusiveOffersSwiperContainer.style.display = 'none';
                exclusiveOffersList.innerHTML = ''; // Clear previous offers

                try {
                    const response = await fetch('/api/api.php?action=get_member_exclusive_offers');
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    const data = await response.json();

                    if (data.offers && data.offers.length > 0) {
                        data.offers.forEach(offer => {
                            const slide = document.createElement('div');
                            // ATUALIZAÇÃO: Usando a nova classe de slide de oferta (com 'width: 280px' no CSS)
                            slide.classList.add('offer-swiper-slide');
                            
                            const productPhoto = offer.product_photo ? uploadDir + offer.product_photo : 'https://placehold.co/280x160/1f2937/d1d5db?text=Produto'; // Placeholder maior
                            const productPrice = formatCurrency(offer.product_price);
                            const checkoutLink = `/checkout?p=${offer.checkout_hash}`;

                            // ATUALIZAÇÃO: Usando as novas classes de CSS e adicionando o BADGE
                            slide.innerHTML = `
                                <a href="${checkoutLink}" class="offer-card-style offer-card relative block">
                                    <div class="lock-icon">
                                        <i data-lucide="lock" class="w-5 h-5"></i>
                                    </div>
                                    <div class="offer-image-container">
                                        <img src="${productPhoto}" alt="${offer.product_name}" onerror="this.onerror=null;this.src='https://placehold.co/280x160/1f2937/d1d5db?text=Produto';">
                                    </div>
                                    <div class="p-5 flex-grow flex flex-col justify-between">
                                        
                                        <!-- ATUALIZAÇÃO: BADGE ADICIONADO -->
                                        <span class="inline-block text-white text-xs font-bold px-3 py-1 rounded-full uppercase mb-3 self-start" style="background-color: var(--accent-primary);">
                                            Oferta Exclusiva
                                        </span>

                                        <div>
                                            <h3 class="text-xl font-bold text-white mb-2 line-clamp-2">${offer.product_name}</h3>
                                            <p class="text-gray-400 text-sm mb-4 line-clamp-3">
                                                Oferta exclusiva do seu infoprodutor.
                                            </p>
                                        </div>
                                        <span class="mt-4 inline-flex items-center justify-center text-white font-bold py-2.5 px-5 rounded-lg transition duration-300 text-base" style="background-color: var(--accent-primary);" onmouseover="this.style.backgroundColor='var(--accent-primary-hover)'" onmouseout="this.style.backgroundColor='var(--accent-primary)'">
                                            Comprar por ${productPrice}
                                        </span>
                                    </div>
                                </a>
                            `;
                            exclusiveOffersList.appendChild(slide);
                        });
                        
                        exclusiveOffersLoading.style.display = 'none';
                        exclusiveOffersSwiperContainer.style.display = 'block';
                        
                        // Initialize Swiper after content is loaded
                        exclusiveOffersSwiper = new Swiper(".exclusiveOffersSwiper", {
                            slidesPerView: "auto", // Permite que o CSS (.offer-swiper-slide) defina a largura
                            spaceBetween: 20,
                            freeMode: true,
                            pagination: {
                                el: ".swiper-pagination",
                                clickable: true,
                            },
                            navigation: {
                                nextEl: ".swiper-button-next",
                                prevEl: ".swiper-button-prev",
                            },
                            breakpoints: {
                                640: { spaceBetween: 20 },
                                768: { spaceBetween: 25 },
                                1024: { spaceBetween: 30 },
                                1280: { spaceBetween: 30 }
                            },
                        });
                        lucide.createIcons(); // Re-render icons for newly added elements
                    } else {
                        exclusiveOffersLoading.style.display = 'none';
                        exclusiveOffersEmpty.style.display = 'block';
                    }
                } catch (error) {
                    console.error('Error fetching exclusive offers:', error);
                    exclusiveOffersLoading.style.display = 'none';
                    exclusiveOffersEmpty.style.display = 'block'; // Show empty state on error too
                    exclusiveOffersEmpty.innerHTML = `<i data-lucide="cloud-off" class="mx-auto w-16 h-16 text-gray-600 mb-4"></i><p class="text-lg font-semibold text-red-500">Erro ao carregar ofertas!</p><p class="mt-2 text-sm text-gray-400">Tente novamente mais tarde ou entre em contato com o suporte.</p>`;
                    lucide.createIcons(); // Re-render icons for newly added elements
                }
            }

            fetchExclusiveOffers(); // Call to load exclusive offers
        });
    </script>
</body>
</html>