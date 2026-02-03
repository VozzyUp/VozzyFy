<?php
// Inicia buffer de saída ANTES de qualquer include para evitar "headers already sent"
ob_start();

// Inclui o arquivo de configuração que inicia a sessão
require_once __DIR__ . '/config/config.php';

// Verifica se o usuário está logado, se não, redireciona para a página de login
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    ob_end_clean(); // Limpa buffer antes de redirecionar
    header("location: /login");
    exit;
}

// Define a página antes da verificação de acesso
$pagina = isset($_GET['pagina']) ? $_GET['pagina'] : 'dashboard';

// Verifica acesso SaaS (se plugin estiver ativo)
if (plugin_active('saas')) {
    require_once __DIR__ . '/plugins/saas/includes/notifications.php';
    require_once __DIR__ . '/plugins/saas/saas.php';
    
    // Garante que usuário tenha plano free se disponível
    if ($_SESSION['tipo'] !== 'admin') {
        saas_ensure_free_plan($_SESSION['id']);
    }
    
    // Se não tiver acesso e não estiver na página de planos, redireciona
    if (!saas_check_user_access($_SESSION['id']) && $_SESSION['tipo'] !== 'admin' && $pagina !== 'planos') {
        $_SESSION['flash_message'] = "<div class='bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded relative mb-4' role='alert'>Você precisa adquirir um plano para continuar usando a plataforma.</div>";
        ob_end_clean(); // Limpa buffer antes de redirecionar
        header("location: /index?pagina=planos");
        exit;
    }
}

// Se o usuário logado for um administrador, redireciona para o painel de administração.
// Isso garante que admins não acessem o painel de usuário/infoprodutor.
if (isset($_SESSION["tipo"]) && $_SESSION["tipo"] === 'admin') {
    ob_end_clean(); // Limpa buffer antes de redirecionar
    header("location: /admin");
    exit;
}

// A página index.php é agora o dashboard unificado para infoprodutores,
// sem redirecionamento condicional para mobile_dashboard_charts.php
// A distinção entre desktop e PWA será apenas na experiência do navegador/aplicativo instalado,
// mas a base do conteúdo será a mesma.
// A remoção de $_SESSION['is_pwa_session'] e sua lógica relacionada é feita.


// Fetch user data for display in the header
$user_id_display = $_SESSION['id'];
$user_name_display = htmlspecialchars($_SESSION['usuario']); // Fallback to session username/email
$foto_perfil = null;
$has_member_access = false;
$current_view_mode = $_SESSION['current_view_mode'] ?? 'infoprodutor';

try {
    $stmt = $pdo->prepare("SELECT nome, foto_perfil FROM usuarios WHERE id = ?");
    $stmt->execute([$user_id_display]);
    $user_data = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user_data) {
        // Prefer the 'nome' from DB if available, otherwise use session 'usuario'
        $user_name_display = htmlspecialchars($user_data['nome'] ?? $_SESSION['usuario']);
        $foto_perfil = htmlspecialchars($user_data['foto_perfil'] ?? '');
    }
    
    // Verifica se tem acesso à área de membros
    $usuario_email = $_SESSION['usuario'] ?? '';
    $usuario_tipo = $_SESSION['tipo'] ?? '';
    
    // Verifica se tem cursos comprados (acessos criados)
    $stmt_acessos = $pdo->prepare("
        SELECT COUNT(*) as total 
        FROM alunos_acessos aa
        JOIN produtos p ON aa.produto_id = p.id
        WHERE aa.aluno_email = ? AND p.tipo_entrega = 'area_membros'
    ");
    $stmt_acessos->execute([$usuario_email]);
    $acessos = $stmt_acessos->fetch(PDO::FETCH_ASSOC);
    
    if ($acessos && $acessos['total'] > 0) {
        $has_member_access = true;
    } else {
        // Se não tem acessos criados, verifica se tem compras aprovadas (pode estar aguardando entrega)
        $stmt_vendas = $pdo->prepare("
            SELECT COUNT(*) as total 
            FROM vendas v
            JOIN produtos p ON v.produto_id = p.id
            WHERE v.comprador_email = ? 
            AND v.status_pagamento IN ('approved', 'paid', 'completed')
            AND p.tipo_entrega = 'area_membros'
        ");
        $stmt_vendas->execute([$usuario_email]);
        $vendas = $stmt_vendas->fetch(PDO::FETCH_ASSOC);
        
        if ($vendas && $vendas['total'] > 0) {
            $has_member_access = true;
        } else {
            // Verifica se é infoprodutor e criou produtos area_membros
            if ($usuario_tipo === 'infoprodutor') {
                $stmt_produtos = $pdo->prepare("
                    SELECT COUNT(*) as total 
                    FROM produtos 
                    WHERE usuario_id = ? AND tipo_entrega = 'area_membros'
                ");
                $stmt_produtos->execute([$user_id_display]);
                $produtos = $stmt_produtos->fetch(PDO::FETCH_ASSOC);
                
                if ($produtos && $produtos['total'] > 0) {
                    $has_member_access = true;
                }
            }
        }
    }
} catch (PDOException $e) {
    // Log the error, but don't stop the page from loading
    error_log("Error fetching user data for index.php: " . $e->getMessage());
}


// Lista de páginas permitidas para segurança
$paginas_permitidas = ['dashboard', 'produtos', 'configuracoes', 'checkout_editor', 'produto_config', 'vendas', 'area_membros', 'gerenciar_curso', 'gerenciar_comunidade', 'gerenciar_comentarios', 'profile', 'infoprodutor_member_offers', 'tracking', 'integracoes', 'integracoes_webhooks', 'integracoes_utmfy', 'clonar_site', 'planos', 'saas_planos', 'pwa_info', 'alunos', 'reembolsos', 'conquistas'];

// Verifica se módulo PWA está instalado
$pwa_module_installed = file_exists(__DIR__ . '/pwa/pwa_config.php');

// Lógica para link ativo do menu - Modern Glassmorphism Design
$active_class = 'sidebar-item sidebar-item-active';
$inactive_class = 'sidebar-item sidebar-item-inactive';

// Buffer já foi iniciado no início do arquivo
// Limpa qualquer output anterior que possa ter sido gerado
ob_clean();

// Exibe a mensagem flash (se existir) dentro do buffer
if (isset($_SESSION['flash_message']) && !empty($_SESSION['flash_message'])) {
    echo '<div class="mb-6">';
    echo $_SESSION['flash_message'];
    echo '</div>';
    unset($_SESSION['flash_message']); // Limpa a mensagem após exibir
}

// Exibe mensagens de feedback do perfil (se existir)
if (isset($_SESSION['profile_feedback_for_js']) && !empty($_SESSION['profile_feedback_for_js'])) {
    $profile_messages_html = '';
    foreach ($_SESSION['profile_feedback_for_js'] as $msg) {
        $profile_messages_html .= '<p>' . htmlspecialchars($msg) . '</p>';
    }
    echo '<div class="mb-6 px-4 py-3 rounded relative" role="alert" style="background-color: color-mix(in srgb, var(--accent-primary) 20%, transparent); border: 1px solid var(--accent-primary); color: var(--accent-primary);">';
    echo $profile_messages_html;
    echo '</div>';
    unset($_SESSION['profile_feedback_for_js']);
}

// Inclui a página solicitada (como 'gerenciar_curso.php') dentro do buffer
if (in_array($pagina, $paginas_permitidas) && file_exists(__DIR__ . '/views/' . $pagina . '.php')) {
    // Verificar se é página SaaS
    if ($pagina === 'saas_planos' && file_exists(__DIR__ . '/views/saas_planos.php')) {
        include __DIR__ . '/views/saas_planos.php';
    } elseif (file_exists(__DIR__ . '/views/' . $pagina . '.php')) {
        include __DIR__ . '/views/' . $pagina . '.php';
    } else {
        echo "<div class='text-center p-10 bg-dark-card rounded-lg shadow border border-dark-border'><h1 class='text-4xl font-bold text-white'>Erro 404</h1><p class='mt-2 text-gray-400'>Página não encontrada.</p></div>";
    }
} else {
    // Se a página não for encontrada, mostra um erro 404
    echo "<div class='text-center p-10 bg-dark-card rounded-lg shadow border border-dark-border'><h1 class='text-4xl font-bold text-white'>Erro 404</h1><p class='mt-2 text-gray-400'>Página não encontrada.</p></div>";
}

// Captura todo o conteúdo do buffer para a variável $page_content
$page_content = ob_get_clean();

// Carrega security_helper ANTES de qualquer output HTML para evitar "headers already sent"
require_once __DIR__ . '/helpers/security_helper.php';

// Gera token CSRF ANTES de qualquer output HTML (setcookie precisa de headers antes do output)
$csrf_token_js = generate_csrf_token();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <title>Painel do Usuário</title>
    <?php 
    // IMPORTANTE: load_settings.php deve ser incluído ANTES de qualquer script
    // para garantir que as meta tags PWA estejam no início do head
    include __DIR__ . '/config/load_settings.php'; 
    ?>
    
    <?php if ($pwa_module_installed): ?>
    <!-- Script CRÍTICO para verificação e forçar modo standalone no iOS -->
    <script>
    (function() {
        // Detecta se está no iOS
        const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
        
        if (isIOS) {
            // Verifica se as meta tags PWA estão presentes no DOM
            const metaTag = document.querySelector('meta[name="apple-mobile-web-app-capable"]');
            const manifestLink = document.querySelector('link[rel="manifest"]');
            
            // Debug: verifica se meta tags estão presentes
            // Verifica se está em modo standalone (instalado como PWA)
            const isStandalone = window.navigator.standalone === true || 
                                 window.matchMedia('(display-mode: standalone)').matches;
            
            // Se NÃO está em standalone mas deveria estar
            if (!isStandalone) {
                // Verifica se as meta tags estão presentes
                if (metaTag && metaTag.content === 'yes') {
                    // Meta tag está presente, mas iOS não está em standalone
                    // Isso pode indicar que precisa reinstalar ou há cache
                    
                    // Verifica se o manifest está acessível e válido
                    if (manifestLink) {
                        fetch(manifestLink.href)
                            .then(response => {
                                if (!response.ok) {
                                    // Manifest retornou erro
                                }
                                return response.json();
                            })
                            .then(manifest => {
                                if (manifest && manifest.display === 'standalone') {
                                    // Manifest válido e configurado para standalone
                                } else {
                                    // Manifest não está configurado para standalone
                                }
                            })
                            .catch(error => {
                                // Erro ao verificar manifest
                            });
                    }
                    
                    // Tenta forçar recarregamento das meta tags (fallback)
                    if (metaTag.content !== 'yes') {
                        metaTag.setAttribute('content', 'yes');
                    }
                } else {
                    // Meta tag não está presente - cria dinamicamente (fallback de emergência)
                    const newMetaTag = document.createElement('meta');
                    newMetaTag.name = 'apple-mobile-web-app-capable';
                    newMetaTag.content = 'yes';
                    document.head.insertBefore(newMetaTag, document.head.firstChild);
                }
            } else {
                // Está em modo standalone - configura comportamento de app
                document.documentElement.style.setProperty('--safe-area-inset-top', 'env(safe-area-inset-top)');
                document.documentElement.style.setProperty('--safe-area-inset-bottom', 'env(safe-area-inset-bottom)');
                
                // Previne que links abram no Safari
                document.addEventListener('click', function(e) {
                    const target = e.target.closest('a');
                    if (target && target.href && !target.target) {
                        // Links internos devem abrir no mesmo contexto
                        if (target.href.indexOf(window.location.origin) === 0) {
                            e.preventDefault();
                            window.location.href = target.href;
                        }
                    }
                }, true);
            }
        }
    })();
    </script>
    <?php endif; ?>

    <script src="https://cdn.tailwindcss.com"></script>
    <script>
    tailwind.config = {
      theme: {
        extend: {
          colors: {
            orange: {
              50: '#fff7ed',
              100: '#ffedd5',
              200: '#fed7aa',
              300: '#fdba74',
              400: '#fb923c',
              500: '#f97316',
              600: '#ea580c',
              700: '#c2410c',
              800: '#9a3412',
              900: '#7c2d12',
            },
          }
        }
      }
    }
    </script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="style.css">
    <!-- CSRF Token para JavaScript -->
    <!-- Token CSRF já foi gerado antes do HTML começar (linha ~161) -->
    <meta name="csrf-token" content="<?php echo htmlspecialchars($csrf_token_js); ?>">
    <script>
        // Variável global para token CSRF
        window.csrfToken = '<?php echo htmlspecialchars($csrf_token_js); ?>';
    </script>
    <script src="/assets/js/csrf-auto-refresh.js"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        /* Estilos para o sino de notificações */
        .notification-bell-container {
            position: relative;
            cursor: pointer;
            padding: 8px;
            border-radius: 9999px; /* Full rounded */
            transition: background-color 0.2s;
        }
        .notification-bell-container:hover {
            background-color: #f3f4f6; /* Gray-100 */
        }
        .notification-badge {
            position: absolute;
            top: 0;
            right: 0;
            background-color: #f97316; /* Orange-500 */
            color: white;
            font-size: 0.75rem; /* text-xs */
            font-weight: 700; /* font-bold */
            border-radius: 9999px; /* Full rounded */
            padding: 0.15rem 0.4rem;
            min-width: 1.25rem; /* w-5 h-5 */
            height: 1.25rem;
            display: flex;
            align-items: center;
            justify-content: center;
            line-height: 1;
            transform: translate(25%, -25%);
            box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            transition: background-color 0.2s;
        }
        .notification-popup {
            position: fixed;
            top: 0;
            right: 0;
            width: 320px;
            height: 100vh;
            background-color: #0f1419;
            border-left: 1px solid rgba(255, 255, 255, 0.1);
            box-shadow: -4px 0 15px rgba(0,0,0,0.3);
            z-index: 1000;
            transform: translateX(100%);
            transition: transform 0.3s ease-in-out;
            display: flex;
            flex-direction: column;
        }
        .notification-popup.open {
            transform: translateX(0);
        }
        .notification-header {
            padding: 1rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .notification-list {
            flex-grow: 1;
            overflow-y: auto;
        }
        .notification-item {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid rgba(255, 255, 255, 0.05);
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
            transition: background-color 0.2s;
            color: rgba(255, 255, 255, 0.9);
        }
        .notification-item:hover {
            background-color: rgba(255, 255, 255, 0.05);
        }
        .notification-item.unread {
            background-color: rgba(50, 231, 104, 0.1);
            font-weight: 500;
        }
        .notification-icon {
            flex-shrink: 0;
            width: 1.25rem;
            height: 1.25rem;
            color: var(--accent-primary);
            margin-top: 2px;
        }
        .notification-item-message {
            flex-grow: 1;
            font-size: 0.875rem;
            line-height: 1.4;
            color: rgba(255, 255, 255, 0.9);
        }
        .notification-item-time {
            flex-shrink: 0;
            font-size: 0.75rem;
            color: rgba(255, 255, 255, 0.5);
            white-space: nowrap;
        }
        .empty-notifications {
            padding: 1.5rem;
            text-align: center;
            color: rgba(255, 255, 255, 0.5);
        }

        /* Live Floating Notification */
        .live-notification-container {
            position: fixed;
            bottom: 20px;
            right: 20px;
            width: 320px;
            background-color: #0f1419;
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.3), 0 4px 6px -2px rgba(0, 0, 0, 0.2);
            padding: 1rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            transform: translateY(120%); /* Start off-screen */
            opacity: 0;
            transition: transform 0.5s cubic-bezier(0.25, 0.46, 0.45, 0.94), opacity 0.5s ease-out;
            z-index: 1000;
        }

        .live-notification-container.show {
            transform: translateY(0);
            opacity: 1;
        }

        .live-notification-product-image {
            width: 50px;
            height: 50px;
            border-radius: 8px;
            object-fit: cover;
            flex-shrink: 0;
            border: 1px solid #e5e7eb;
        }
        .cash-register-sound {
            display: none; /* Hide audio element */
        }

        /* Responsividade para o menu lateral */
        #sidebar {
            width: 100%;
            max-width: 280px; /* Ajuste para um tamanho mais comum em mobile */
            transform: translateX(-100%); /* Escondido por padrão */
        }
        #sidebar.open {
            transform: translateX(0); /* Visível quando aberto */
        }
        #sidebar-overlay {
            display: none; /* Escondido por padrão */
        }
        #sidebar-overlay.open {
            display: block; /* Visível quando o menu está aberto */
        }
        /* Ajuste do conteúdo principal para telas menores */
        main {
            margin-left: 0; /* Remove a margem fixa em mobile */
        }
        /* Oculta o botão de toggle em telas maiores */
        #sidebar-toggle {
            display: flex; /* Exibe por padrão em mobile */
        }

        /* Media query para telas maiores (desktop) */
        @media (min-width: 768px) { /* md breakpoint */
            #sidebar {
                transform: translateX(0); /* Sempre visível em desktop */
                width: 256px; /* md:w-64 */
            }
            #sidebar-toggle {
                display: none; /* Oculta em desktop */
            }
            main {
                margin-left: 256px; /* md:ml-64 */
            }
            #sidebar-overlay {
                display: none; /* Nunca visível em desktop */
            }
        }

    </style>
</head>
<body class="font-sans flex flex-col min-h-screen" style="background-color: #07090d;">
    <!-- Header Fixo Invisível (Topo) -->
    <header class="fixed top-0 left-0 right-0 z-40 bg-dark-base/80 backdrop-blur-sm h-[60px] flex items-center justify-between px-4 md:px-6">
        <!-- Botão de Toggle Mobile -->
        <button id="sidebar-toggle" class="md:hidden p-2 rounded-lg bg-dark-elevated border border-dark-border text-white hover:bg-dark-card transition-colors">
            <i data-lucide="menu" class="w-6 h-6"></i>
        </button>
        <div class="hidden md:block"></div> <!-- Espaçador para desktop -->
        
        <!-- Controles do Header (Notificação, Perfil, Logout) -->
        <div class="flex items-center space-x-3">
            <!-- Sininho de Notificações -->
            <div id="notification-bell" class="notification-bell-container flex items-center justify-center relative cursor-pointer p-2 rounded-lg hover:bg-dark-elevated transition-colors">
                <i data-lucide="bell" id="bell-icon" class="w-6 h-6 text-gray-400 hover:text-white transition-colors"></i>
                <span id="notification-badge" class="notification-badge hidden">0</span>
            </div>

            <!-- Dropdown do Perfil -->
            <div class="relative" id="profile-dropdown-container">
                <button id="profile-dropdown-btn" class="flex items-center space-x-3 group hover:bg-dark-elevated p-2 rounded-lg transition-colors" title="Meu Perfil">
                    <?php if (!empty($foto_perfil)): ?>
                        <img src="uploads/<?php echo $foto_perfil; ?>" alt="Foto de Perfil" class="w-10 h-10 rounded-full object-cover shadow-sm" style="border: 2px solid var(--accent-primary);">
                    <?php else: ?>
                        <div class="w-10 h-10 rounded-full flex items-center justify-center text-white text-lg font-bold shadow-lg transition-colors" style="background-color: var(--accent-primary);" onmouseover="this.style.backgroundColor='var(--accent-primary-hover)'" onmouseout="this.style.backgroundColor='var(--accent-primary)'">
                            <?php echo strtoupper(substr($user_name_display, 0, 1)); ?>
                        </div>
                    <?php endif; ?>
                    <span class="text-sm font-semibold text-white hidden sm:block"><?php echo $user_name_display; ?></span>
                    <i data-lucide="chevron-down" class="w-4 h-4 text-gray-400 hidden sm:block"></i>
                </button>
                
                <!-- Dropdown Menu -->
                <div id="profile-dropdown-menu" class="hidden absolute right-0 mt-2 w-56 bg-dark-card border border-dark-border rounded-lg shadow-xl z-50 overflow-hidden">
                    <a href="/index?pagina=profile" class="flex items-center space-x-3 px-4 py-3 text-white hover:bg-dark-elevated transition-colors">
                        <i data-lucide="user" class="w-5 h-5"></i>
                        <span>Meu Perfil</span>
                    </a>
                    <?php if ($_SESSION['tipo'] === 'infoprodutor'): ?>
                        <button id="switch-to-member" class="w-full flex items-center space-x-3 px-4 py-3 text-white hover:bg-dark-elevated transition-colors text-left">
                            <i data-lucide="graduation-cap" class="w-5 h-5"></i>
                            <span>Mudar para Painel de Aluno</span>
                        </button>
                    <?php endif; ?>
                    <div class="border-t border-dark-border"></div>
                    <a href="/logout" class="flex items-center space-x-3 px-4 py-3 text-red-400 hover:bg-dark-elevated transition-colors">
                        <i data-lucide="log-out" class="w-5 h-5"></i>
                        <span>Sair</span>
                    </a>
                </div>
            </div>
        </div>
    </header>

    <!-- Popup de Notificações Lateral -->
    <div id="notification-popup" class="notification-popup">
        <div class="notification-header">
            <h3 class="text-lg font-bold text-white">Notificações</h3>
            <button id="close-notification-popup" class="text-gray-400 hover:text-white p-1 rounded-full hover:bg-dark-elevated transition-colors">
                <i data-lucide="x" class="w-5 h-5"></i>
            </button>
        </div>
        
        <!-- Botão para Ativar Notificações Push (se não tiver permissão) -->
        <div id="push-notification-activate-section" class="px-4 py-3 border-b border-dark-border" style="display: none;">
            <div class="flex items-center gap-3 p-3 bg-dark-elevated rounded-lg">
                <div class="flex-1">
                    <p class="text-sm font-semibold text-white mb-1">Notificações Push</p>
                    <p class="text-xs text-gray-400">Receba notificações em tempo real</p>
                </div>
                <button id="activate-push-notifications-btn" class="px-4 py-2 rounded-lg text-sm font-semibold transition-colors flex items-center gap-2" style="background: var(--accent-primary); color: white;">
                    <i data-lucide="bell" class="w-4 h-4"></i>
                    Ativar
                </button>
            </div>
        </div>
        
        <div id="notification-list" class="notification-list">
            <div class="empty-notifications" id="empty-notifications-state">
                <i data-lucide="bell-off" class="mx-auto w-12 h-12 text-gray-500 mb-2"></i>
                <p class="text-sm text-gray-400">Nenhuma notificação recente.</p>
            </div>
            <!-- Notifications will be loaded here by JavaScript -->
        </div>
    </div>


    <!-- Menu Lateral (Sidebar) -->
    <?php do_action('dashboard_sidebar_before'); ?>
    <aside id="sidebar" class="sidebar-glass fixed top-0 left-0 bottom-0 z-50 transform -translate-x-full transition-transform duration-300 w-full max-w-xs md:translate-x-0 md:w-64 flex flex-col overflow-y-auto">
        <!-- Sidebar Header (Logo) -->
        <div class="sidebar-header">
            <img src="<?php echo htmlspecialchars($logo_url); ?>" alt="Logotipo" class="h-10 w-auto">
        </div>
        
        <?php
        do_action('dashboard_widgets_before');
        // Widget de Conquistas (apenas para infoprodutores)
        if (isset($_SESSION['tipo']) && $_SESSION['tipo'] === 'infoprodutor' && isset($_SESSION['id'])) {
            require_once __DIR__ . '/helpers/conquistas_helper.php';
            $usuario_id = $_SESSION['id'];
            $faturamento = calcular_faturamento_lifetime($usuario_id);
            $proxima_conquista = obter_proxima_conquista($usuario_id);
            $progresso_info = $proxima_conquista ? calcular_progresso_conquista($usuario_id, $proxima_conquista['id']) : ['progresso' => 0, 'faltante' => 0];
            
            // Formatar valores para exibição (K para milhares, M para milhões)
            function formatar_valor_curto($valor) {
                $valor = (float)$valor;
                if ($valor >= 1000000) {
                    // Valores em milhões: 1M, 2M, 3M (sem decimais)
                    $milhoes = $valor / 1000000;
                    // Se for número inteiro, mostrar sem decimais, senão mostrar 1 casa decimal
                    if ($milhoes == floor($milhoes)) {
                        return 'R$ ' . number_format($milhoes, 0, ',', '.') . 'M';
                    } else {
                        return 'R$ ' . number_format($milhoes, 1, ',', '.') . 'M';
                    }
                } elseif ($valor >= 1000) {
                    // Valores em milhares: 10K, 20K, 100K (sem decimais)
                    $milhares = $valor / 1000;
                    // Se for número inteiro, mostrar sem decimais, senão mostrar 1 casa decimal se necessário
                    if ($milhares == floor($milhares)) {
                        return 'R$ ' . number_format($milhares, 0, ',', '.') . 'K';
                    } else {
                        // Mostrar 1 ou 2 casas decimais dependendo do valor
                        $decimais = ($milhares * 10) == floor($milhares * 10) ? 1 : 2;
                        return 'R$ ' . number_format($milhares, $decimais, ',', '.') . 'K';
                    }
                }
                return 'R$ ' . number_format($valor, 2, ',', '.');
            }
            
            $faturamento_formatado = formatar_valor_curto($faturamento);
            // Mostrar valor_maximo da próxima conquista como meta (se houver), senão valor_minimo
            $proxima_meta_valor = $proxima_conquista ? ($proxima_conquista['valor_maximo'] ? $proxima_conquista['valor_maximo'] : $proxima_conquista['valor_minimo']) : 0;
            $proxima_meta = $proxima_conquista ? formatar_valor_curto($proxima_meta_valor) : '';
            // Pegar progresso real da função calcular_progresso_conquista
            $progresso_percent = $proxima_conquista ? min(100, max(0, $progresso_info['progresso'])) : 100;
            
            // Debug: log do progresso calculado (pode remover depois)
            // error_log("Faturamento: $faturamento | Próxima: " . ($proxima_conquista['nome'] ?? 'N/A') . " | Progresso: " . $progresso_percent . "%");
        ?>
        <!-- Widget de Faturamento - Clean -->
        <div class="px-2 mb-4">
            <a href="/index?pagina=conquistas" class="block border rounded-lg p-3 hover:opacity-90 transition-all duration-300" style="border-color: var(--accent-primary);">
                <div class="text-white text-xs font-semibold mb-2.5">Faturamento</div>
                <?php if ($proxima_conquista): 
                    // Recalcular progresso para garantir que está correto (usando valor_maximo como meta se houver)
                    $progresso_percent_final = min(100, max(0, $progresso_info['progresso']));
                    
                    // Debug: descomentar para ver valores no log
                    // error_log("Sidebar Progresso - Faturamento: $faturamento | Conquista: {$proxima_conquista['nome']} ({$proxima_conquista['valor_minimo']}-{$proxima_conquista['valor_maximo']}) | Progresso: {$progresso_percent_final}%");
                ?>
                <div class="flex items-baseline gap-1.5 mb-2.5">
                    <span class="text-white text-sm"><?php echo $faturamento_formatado; ?></span>
                    <span class="text-gray-400 text-xs">/</span>
                    <span class="text-gray-400 text-sm"><?php echo $proxima_meta; ?></span>
                    <span class="text-xs font-bold ml-auto" style="color: var(--accent-primary);"><?php echo number_format($progresso_percent_final, 0); ?>%</span>
                </div>
                <div class="relative w-full rounded-full h-1.5 overflow-hidden" style="background-color: rgba(255, 255, 255, 0.08);">
                    <div class="h-full rounded-full transition-all duration-500 ease-out" style="width: <?php echo $progresso_percent_final; ?>%; background-color: var(--accent-primary);"></div>
                </div>
                <?php else: ?>
                <div class="flex items-baseline gap-1.5 mb-2.5">
                    <span class="text-white text-sm"><?php echo $faturamento_formatado; ?></span>
                    <span class="text-xs font-bold ml-auto" style="color: var(--accent-primary);">100%</span>
                </div>
                <div class="relative w-full rounded-full h-1.5 overflow-hidden" style="background-color: rgba(255, 255, 255, 0.08);">
                    <div class="h-full rounded-full" style="width: 100%; background-color: var(--accent-primary);"></div>
                </div>
                <?php endif; ?>
            </a>
        </div>
        <?php } ?>
        
        <?php do_action('dashboard_widgets_after'); ?>
        
        <nav class="mt-4 flex-grow px-2">
            <?php do_action('dashboard_menu_before'); ?>
            <a href="/index?pagina=dashboard" class="<?php echo $pagina == 'dashboard' ? $active_class : $inactive_class; ?>">
                <i data-lucide="layout-dashboard" class="w-5 h-5"></i>
                <span>Dashboard</span>
            </a>
            <a href="/index?pagina=vendas" class="<?php echo $pagina == 'vendas' ? $active_class : $inactive_class; ?>">
                <i data-lucide="shopping-cart" class="w-5 h-5"></i>
                <span>Vendas</span>
            </a>
            <a href="/index?pagina=produtos" class="<?php echo ($pagina == 'produtos' || $pagina == 'checkout_editor' || $pagina == 'produto_config') ? $active_class : $inactive_class; ?>">
                <i data-lucide="package" class="w-5 h-5"></i>
                <span>Produtos</span>
            </a>
            <a href="/index?pagina=reembolsos" class="<?php echo $pagina == 'reembolsos' ? $active_class : $inactive_class; ?>">
                <i data-lucide="refresh-ccw" class="w-5 h-5"></i>
                <span>Reembolsos</span>
            </a>
            <a href="/index?pagina=area_membros" class="<?php echo ($pagina == 'area_membros' || $pagina == 'gerenciar_curso' || $pagina == 'infoprodutor_member_offers') ? $active_class : $inactive_class; ?>">
                <i data-lucide="play-square" class="w-5 h-5"></i>
                <span>Área de Membros</span>
            </a>
            <a href="/index?pagina=alunos" class="<?php echo $pagina == 'alunos' ? $active_class : $inactive_class; ?>">
                <i data-lucide="graduation-cap" class="w-5 h-5"></i>
                <span>Alunos</span>
            </a>
            <a href="/index?pagina=conquistas" class="<?php echo $pagina == 'conquistas' ? $active_class : $inactive_class; ?>">
                <i data-lucide="trophy" class="w-5 h-5"></i>
                <span>Conquistas</span>
            </a>
            <a href="/index?pagina=tracking" class="<?php echo $pagina == 'tracking' ? $active_class : $inactive_class; ?>">
                <i data-lucide="line-chart" class="w-5 h-5"></i>
                <span>Tracking</span>
            </a>
            <a href="/index?pagina=clonar_site" class="<?php echo $pagina == 'clonar_site' ? $active_class : $inactive_class; ?>">
                <i data-lucide="copy-check" class="w-5 h-5"></i>
                <span>Clonar Site</span>
            </a>
            <a href="/index?pagina=integracoes" class="<?php echo (in_array($pagina, ['integracoes', 'integracoes_webhooks', 'integracoes_utmfy'])) ? $active_class : $inactive_class; ?>">
                <i data-lucide="plug-zap" class="w-5 h-5"></i>
                <span>Integrações</span>
            </a>
            <?php
            // Menu de Planos SaaS (se habilitado)
            if (file_exists(__DIR__ . '/saas/includes/saas_functions.php')) {
                require_once __DIR__ . '/saas/includes/saas_functions.php';
                if (function_exists('saas_enabled') && saas_enabled()):
            ?>
            <a href="/index?pagina=saas_planos" class="<?php echo $pagina == 'saas_planos' ? $active_class : $inactive_class; ?>">
                <i data-lucide="credit-card" class="w-5 h-5"></i>
                <span>Planos</span>
            </a>
            <?php
                endif;
            }
            
            // Itens de menu dinâmicos de plugins (SaaS - Planos)
            if (function_exists('do_action')) {
                global $plugin_hooks;
                $all_menu_items = [];
                
                // Coleta todos os arrays retornados pelos hooks
                if (isset($plugin_hooks['infoprodutor_menu_items'])) {
                    foreach ($plugin_hooks['infoprodutor_menu_items'] as $hook) {
                        if (is_callable($hook['callback'])) {
                            $items = call_user_func($hook['callback']);
                            if (is_array($items)) {
                                $all_menu_items = array_merge($all_menu_items, $items);
                            }
                        }
                    }
                }
                
                foreach ($all_menu_items as $item) {
                    if (isset($item['title']) && isset($item['url'])) {
                        $icon = $item['icon'] ?? 'settings';
                        $item_pagina = parse_str(parse_url($item['url'], PHP_URL_QUERY), $params);
                        $item_pagina = $params['pagina'] ?? '';
                        $is_active = ($pagina === $item_pagina || strpos($_SERVER['REQUEST_URI'], $item['url']) !== false);
                        echo '<a href="' . htmlspecialchars($item['url']) . '" class="' . ($is_active ? $active_class : $inactive_class) . '">';
                        echo '<i data-lucide="' . htmlspecialchars($icon) . '" class="w-5 h-5"></i>';
                        echo '<span>' . htmlspecialchars($item['title']) . '</span>';
                        echo '</a>';
                    }
                }
            }
            ?>
            <?php // O link para o Painel Admin foi removido do painel de usuário, pois admins serão redirecionados diretamente. ?>
            
            <?php
            // Itens de menu dinâmicos usando filter
            if (function_exists('apply_filters')) {
                $plugin_menu_items = apply_filters('dashboard_menu_items', []);
                if (!empty($plugin_menu_items) && is_array($plugin_menu_items)) {
                    foreach ($plugin_menu_items as $item) {
                        if (isset($item['title']) && isset($item['url'])) {
                            $icon = $item['icon'] ?? 'settings';
                            $item_pagina = '';
                            parse_str(parse_url($item['url'], PHP_URL_QUERY), $params);
                            $item_pagina = $params['pagina'] ?? '';
                            $is_active = ($pagina === $item_pagina || strpos($_SERVER['REQUEST_URI'], $item['url']) !== false);
                            echo '<a href="' . htmlspecialchars($item['url']) . '" class="' . ($is_active ? $active_class : $inactive_class) . '">';
                            echo '<i data-lucide="' . htmlspecialchars($icon) . '" class="w-5 h-5"></i>';
                            echo '<span>' . htmlspecialchars($item['title']) . '</span>';
                            echo '</a>';
                        }
                    }
                }
                
                // Itens de sidebar dinâmicos usando filter
                $plugin_sidebar_items = apply_filters('dashboard_sidebar_items', []);
                if (!empty($plugin_sidebar_items) && is_array($plugin_sidebar_items)) {
                    foreach ($plugin_sidebar_items as $item) {
                        if (isset($item['title']) && isset($item['url'])) {
                            $icon = $item['icon'] ?? 'settings';
                            $item_pagina = '';
                            parse_str(parse_url($item['url'], PHP_URL_QUERY), $params);
                            $item_pagina = $params['pagina'] ?? '';
                            $is_active = ($pagina === $item_pagina || strpos($_SERVER['REQUEST_URI'], $item['url']) !== false);
                            echo '<a href="' . htmlspecialchars($item['url']) . '" class="' . ($is_active ? $active_class : $inactive_class) . '">';
                            echo '<i data-lucide="' . htmlspecialchars($icon) . '" class="w-5 h-5"></i>';
                            echo '<span>' . htmlspecialchars($item['title']) . '</span>';
                            echo '</a>';
                        }
                    }
                }
            }
            ?>
            
            <?php do_action('dashboard_menu_after'); ?>
        </nav>
        
        <!-- Card do Plano SaaS (parte inferior do sidebar) -->
        <?php
        if (plugin_active('saas') && isset($_SESSION['tipo']) && $_SESSION['tipo'] !== 'admin') {
            // Carrega funções SaaS
            require_once __DIR__ . '/saas/includes/saas_functions.php';
            require_once __DIR__ . '/saas/includes/saas_limits.php';
            
            // Função para obter informações do plano do usuário
            if (!function_exists('get_user_plan_dashboard_info')) {
                function get_user_plan_dashboard_info($usuario_id) {
                    global $pdo;
                    
                    $plano = saas_get_user_plan($usuario_id);
                    if (!$plano) {
                        return null;
                    }
                    
                    // Busca produtos criados
                    $stmt_produtos = $pdo->prepare("SELECT COUNT(*) FROM produtos WHERE usuario_id = ?");
                    $stmt_produtos->execute([$usuario_id]);
                    $produtos_criados = (int)$stmt_produtos->fetchColumn();
                    
                    // Busca pedidos realizados no mês atual
                    $mes_ano = date('Y-m');
                    $stmt_pedidos = $pdo->prepare("
                        SELECT pedidos_realizados 
                        FROM saas_contadores_mensais 
                        WHERE usuario_id = ? AND mes_ano = ?
                    ");
                    $stmt_pedidos->execute([$usuario_id, $mes_ano]);
                    $contador = $stmt_pedidos->fetch(PDO::FETCH_ASSOC);
                    $pedidos_realizados = $contador ? (int)$contador['pedidos_realizados'] : 0;
                    
                    return [
                        'plano_nome' => $plano['plano_nome'] ?? 'Plano',
                        'data_vencimento' => $plano['data_vencimento'] ?? date('Y-m-d'),
                        'produtos_criados' => $produtos_criados,
                        'max_produtos' => $plano['max_produtos'],
                        'pedidos_realizados' => $pedidos_realizados,
                        'max_pedidos_mes' => $plano['max_pedidos_mes']
                    ];
                }
            }
            
            $plan_info = get_user_plan_dashboard_info($_SESSION['id']);
            if ($plan_info):
                // Calcula percentual de pedidos utilizados
                $pedidos_realizados = (int)($plan_info['pedidos_realizados'] ?? 0);
                $max_pedidos = (int)($plan_info['max_pedidos_mes'] ?? 0);
                $percentual_pedidos = 0;
                $mostrar_aviso_pedidos = false;
                
                if ($max_pedidos > 0) {
                    $percentual_pedidos = ($pedidos_realizados / $max_pedidos) * 100;
                    // Mostra aviso se estiver em 80% ou mais do limite
                    $mostrar_aviso_pedidos = $percentual_pedidos >= 80;
                }
        ?>
        <div class="mt-auto px-2 pb-4 pt-4">
            <?php if ($mostrar_aviso_pedidos): ?>
            <!-- Aviso de Limite de Pedidos Próximo -->
            <div id="order-limit-warning" class="mb-3 bg-gradient-to-r from-orange-900/30 via-yellow-900/20 to-orange-900/30 rounded-lg p-3 border border-orange-500/50 animate-pulse">
                <div class="flex items-start gap-3">
                    <div class="flex-shrink-0">
                        <i data-lucide="alert-triangle" class="w-5 h-5 text-orange-400"></i>
                    </div>
                    <div class="flex-1 min-w-0">
                        <p class="text-xs font-semibold text-orange-200 mb-1">Atenção: Limite de Pedidos Próximo</p>
                        <p class="text-xs text-orange-300/80 mb-2">
                            Você já utilizou <strong><?php echo $pedidos_realizados; ?></strong> de <strong><?php echo $max_pedidos; ?></strong> pedidos deste mês (<?php echo number_format($percentual_pedidos, 1); ?>%).
                        </p>
                        <a href="/index?pagina=planos" class="inline-block text-xs font-semibold text-orange-200 hover:text-orange-100 underline">
                            Fazer Upgrade do Plano →
                        </a>
                    </div>
                    <button onclick="document.getElementById('order-limit-warning').classList.add('hidden')" class="flex-shrink-0 text-orange-400 hover:text-orange-300">
                        <i data-lucide="x" class="w-4 h-4"></i>
                    </button>
                </div>
            </div>
            <?php endif; ?>
            
            <div class="bg-gradient-to-br from-blue-900/20 via-purple-900/20 to-indigo-900/20 rounded-lg p-3 border border-primary/30">
                <div class="flex items-center justify-between mb-2">
                    <div class="flex-1 min-w-0">
                        <p class="text-xs font-semibold text-white truncate"><?php echo htmlspecialchars($plan_info['plano_nome']); ?></p>
                        <p class="text-xs text-gray-400 mt-0.5">Vence: <?php echo date('d/m/Y', strtotime($plan_info['data_vencimento'])); ?></p>
                    </div>
                </div>
                <div class="flex items-center gap-2 text-xs">
                    <div class="flex-1 bg-dark-elevated/50 rounded px-2 py-1">
                        <span class="text-gray-400">Produtos:</span>
                        <span class="text-white font-semibold">
                            <?php echo $plan_info['produtos_criados']; ?>/<?php echo $plan_info['max_produtos'] ?? '∞'; ?>
                        </span>
                    </div>
                    <div class="flex-1 bg-dark-elevated/50 rounded px-2 py-1 <?php echo $mostrar_aviso_pedidos ? 'bg-orange-900/30 border border-orange-500/50' : ''; ?>">
                        <span class="text-gray-400">Pedidos:</span>
                        <span class="text-white font-semibold <?php echo $mostrar_aviso_pedidos ? 'text-orange-300' : ''; ?>">
                            <?php echo $plan_info['pedidos_realizados']; ?>/<?php echo $plan_info['max_pedidos_mes'] ?? '∞'; ?>
                        </span>
                    </div>
                </div>
                <a href="/index?pagina=planos" class="block mt-2 w-full bg-primary/20 hover:bg-primary/30 text-primary text-xs font-semibold py-1.5 px-2 rounded text-center transition-colors">
                    Ver Planos
                </a>
            </div>
        </div>
        <?php
            endif;
        }
        ?>
        <?php do_action('dashboard_sidebar_after'); ?>
    </aside>

    <!-- Overlay para o menu mobile -->
    <div id="sidebar-overlay" class="fixed inset-0 bg-black bg-opacity-50 z-30 hidden"></div>

    <!-- Conteúdo Principal -->
    <main class="flex-1 md:ml-64 mt-[60px] p-6 lg:p-8 overflow-y-auto">
        <?php
        // Hooks específicos por página
        do_action("{$pagina}_page_before");
        
        if ($pagina == 'dashboard') {
            do_action('dashboard_widgets_before');
        }
        
        // Agora, simplesmente exibe o conteúdo que foi capturado no buffer
        echo $page_content;
        
        if ($pagina == 'dashboard') {
            do_action('dashboard_widgets_after');
        }
        
        // Hooks específicos por página
        do_action("{$pagina}_page_after");
        ?>
    </main>

    <!-- Floating Live Notification -->
    <div id="live-notification-container" class="live-notification-container">
        <!-- Usando favicon do sistema -->
        <img id="live-notification-product-image" src="<?php echo !empty($favicon_url) ? htmlspecialchars($favicon_url, ENT_QUOTES, 'UTF-8') : 'https://i.ibb.co/gbNBTgDD/1757909548831.jpg'; ?>" alt="Notificação" class="live-notification-product-image">
        <div>
            <p class="text-sm font-semibold text-white" id="live-notification-message"></p>
            <p class="text-xs text-gray-400 mt-1" id="live-notification-details"></p>
        </div>
        <audio id="cash-register-sound" class="cash-register-sound" src="assets/cash_register.mp3" preload="auto"></audio>
    </div>

    <script>
        // Move lucide.createIcons() to the very end of the body to ensure all elements are parsed.
        lucide.createIcons();

        // --- Lógica de Responsividade do Menu Lateral ---
        const sidebarToggle = document.getElementById('sidebar-toggle');
        const sidebar = document.getElementById('sidebar');
        const sidebarOverlay = document.getElementById('sidebar-overlay');
        const body = document.body;

        function toggleSidebar() {
            sidebar.classList.toggle('-translate-x-full');
            sidebar.classList.toggle('open'); // Adiciona a classe open para controle de visibilidade
            sidebarOverlay.classList.toggle('hidden');
            sidebarOverlay.classList.toggle('open'); // Adiciona a classe open ao overlay
            body.classList.toggle('overflow-hidden'); // Previne o scroll do body quando o sidebar está aberto
        }

        sidebarToggle.addEventListener('click', toggleSidebar);
        sidebarOverlay.addEventListener('click', toggleSidebar); // Fechar o sidebar ao clicar no overlay

        // Close sidebar if window resized to desktop
        window.addEventListener('resize', () => {
            if (window.innerWidth >= 768) { // Tailwind's 'md' breakpoint
                sidebar.classList.remove('-translate-x-full', 'open');
                sidebarOverlay.classList.add('hidden', 'open');
                body.classList.remove('overflow-hidden');
            }
        });

        // --- Lógica de Notificações ---
        const notificationBell = document.getElementById('notification-bell');
        const bellIcon = document.getElementById('bell-icon');
        const notificationBadge = document.getElementById('notification-badge');
        const notificationPopup = document.getElementById('notification-popup');
        const closePopupBtn = document.getElementById('close-notification-popup');
        const notificationList = document.getElementById('notification-list');
        const emptyNotificationsState = document.getElementById('empty-notifications-state');
        // Floating Live Notification elements
        const liveNotificationContainer = document.getElementById('live-notification-container');
        const liveNotificationMessage = document.getElementById('live-notification-message');
        const liveNotificationDetails = document.getElementById('live-notification-details');
        const liveNotificationProductImage = document.getElementById('live-notification-product-image');
        const cashRegisterSound = document.getElementById('cash-register-sound');

        // Flag to prevent repeated attempts to resume audio context
        let audioContextResumed = false;
        // Queue for live notifications
        let notificationQueue = [];
        let isDisplayingNotification = false;

        // Function to attempt to resume audio context (unlock audio playback)
        function tryResumeAudioContext() {
            if (!audioContextResumed && cashRegisterSound) {
                // Store original volume
                const originalVolume = cashRegisterSound.volume;
                // Set volume to 0 for silent unlock attempt
                cashRegisterSound.volume = 0;

                // Ensure the audio element has a valid source and is loaded
                if (!cashRegisterSound.src || cashRegisterSound.readyState < 2) {
                    cashRegisterSound.load();
                    // Wait for it to load, then try to play (or rely on next interaction)
                    cashRegisterSound.oncanplaythrough = () => {
                         cashRegisterSound.play().then(() => {
                            audioContextResumed = true;
                            cashRegisterSound.pause();
                            cashRegisterSound.currentTime = 0;
                            cashRegisterSound.volume = originalVolume; // Restore original volume
                        }).catch(e => {
                            console.warn("Autoplay was prevented after load, waiting for user interaction.", e);
                            cashRegisterSound.volume = originalVolume; // Restore original volume on error
                        });
                        cashRegisterSound.oncanplaythrough = null; // Remove handler
                    };
                    return; // Exit, will try again on next interaction/poll
                }

                // If audio is ready, try to play
                cashRegisterSound.play().then(() => {
                    audioContextResumed = true;
                    // Pause it immediately if it's just for unlocking
                    cashRegisterSound.pause();
                    cashRegisterSound.currentTime = 0;
                    cashRegisterSound.volume = originalVolume; // Restore original volume
                }).catch(e => {
                    console.warn("Autoplay was prevented, waiting for user interaction.", e);
                    cashRegisterSound.volume = originalVolume; // Restore original volume on error
                    // This error is expected if no user interaction yet.
                    // We don't mark audioContextResumed as true here.
                });
            }
        }

        // Attach audio context resume attempt to first user interaction
        // Using { once: true } ensures it runs only once per event type
        document.addEventListener('click', tryResumeAudioContext, { once: true });
        document.addEventListener('keydown', tryResumeAudioContext, { once: true });


        // CORREÇÃO: Função formatTimeAgo com mais granularidade e correção de fuso horário
        function formatTimeAgo(timestamp) {
            const now = new Date();
            // A API em 'notification.php' agora formata a data como 'YYYY-MM-DDTHH:MM:SS'.
            // Ao criar um objeto Date com esta string sem um fuso horário explícito ('Z' ou offset),
            // o navegador a interpreta no fuso horário LOCAL do usuário, conforme solicitado.
            const date = new Date(timestamp);
            const seconds = Math.floor((now - date) / 1000);

            if (seconds < 5) return "Agora mesmo";
            if (seconds < 60) return `Há ${seconds} segundo(s) atrás`;

            const minutes = Math.floor(seconds / 60);
            if (minutes < 60) return `Há ${minutes} minuto(s) atrás`;

            const hours = Math.floor(minutes / 60);
            if (hours < 24) return `Há ${hours} hora(s) atrás`;

            const days = Math.floor(hours / 24);
            if (days < 30) return `Há ${days} dia(s) atrás`;

            const months = Math.floor(days / 30);
            if (months < 12) return `Há ${months} mês(es) atrás`;

            const years = Math.floor(days / 365);
            return `Há ${years} ano(s) atrás`;
        }


        async function fetchNotificationsCount() {
            try {
                const response = await fetch('/notification?action=get_unread_count'); // Use notification.php
                if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
                const data = await response.json();
                
                if (data.count > 0) {
                    notificationBadge.textContent = data.count;
                    notificationBadge.classList.remove('hidden');
                    bellIcon.classList.remove('text-gray-400');
                    bellIcon.classList.add('text-orange-500'); // Cor laranja para notificações
                } else {
                    notificationBadge.classList.add('hidden');
                    bellIcon.classList.remove('text-orange-500');
                    bellIcon.classList.add('text-gray-400'); // Cinza quando não há notificações
                }
            } catch (error) {
                console.error('Error fetching notification count:', error);
            }
        }

        async function fetchRecentNotifications() {
            try {
                const response = await fetch('/notification?action=get_recent_notifications'); // Use notification.php
                if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
                const data = await response.json();

                notificationList.innerHTML = ''; // Clear previous notifications
                if (data.notifications && data.notifications.length > 0) {
                    emptyNotificationsState.style.display = 'none';
                    data.notifications.forEach(notification => {
                        const item = document.createElement('a');
                        item.href = notification.link_acao || '#'; // If link_acao exists, make it clickable
                        item.target = notification.link_acao ? '_blank' : '_self'; // Open in new tab if there's a link
                        item.classList.add('notification-item');
                        if (notification.lida === 0) {
                            item.classList.add('unread');
                        }

                        // Determine icon based on type (example mapping)
                        let iconName = 'bell'; // Default icon
                        switch (notification.tipo) {
                            case 'Compra Aprovada': iconName = 'check-circle'; break;
                            case 'Pix Gerado': iconName = 'smartphone'; break;
                            case 'Boleto Gerado': iconName = 'file-text'; break;
                            case 'Pagamento Pendente': iconName = 'clock'; break;
                            case 'Pagamento Recusado': iconName = 'x-circle'; break;
                            case 'Reembolso': iconName = 'rotate-ccw'; break;
                            case 'Chargeback': iconName = 'shield-alert'; break;
                            default: iconName = 'info'; break;
                        }

                        item.innerHTML = `
                            <i data-lucide="${iconName}" class="notification-icon"></i>
                            <div class="notification-item-message">
                                <span class="font-semibold">${notification.tipo}:</span> ${notification.mensagem}
                            </div>
                            <span class="notification-item-time">${formatTimeAgo(notification.data_notificacao)}</span>
                        `;
                        notificationList.appendChild(item);
                    });
                    lucide.createIcons(); // Re-render Lucide icons for new content
                } else {
                    emptyNotificationsState.style.display = 'block';
                }
            } catch (error) {
                console.error('Error fetching recent notifications:', error);
                notificationList.innerHTML = `<div class="empty-notifications"><p class="text-red-500">Erro ao carregar notificações.</p></div>`;
            }
        }

        async function markNotificationsAsRead() {
            try {
                const response = await fetch('/notification?action=mark_all_as_read', { method: 'POST' }); // Use notification.php
                if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
                // No need to process response, just update count locally
                notificationBadge.classList.add('hidden');
                bellIcon.classList.remove('text-orange-500');
                bellIcon.classList.add('text-gray-400');
            } catch (error) {
                console.error('Error marking notifications as read:', error);
            }
        }

        // --- Lógica para Notificações Flutuantes (Live Notifications) ---
        async function fetchLiveNotifications() {
            try {
                const response = await fetch('/notification?action=get_live_notifications'); // Use notification.php
                if (!response.ok) {
                    throw new Error('Failed to fetch live notifications');
                }
                const data = await response.json();

                if (data.live_notifications && data.live_notifications.length > 0) {
                    for (const notification of data.live_notifications) {
                        notificationQueue.push(notification); 
                        // Mark as displayed_live on the server immediately upon *receiving* it
                        // This prevents it from being fetched again in subsequent polls
                        await fetch('/notification?action=mark_as_displayed_live', { // Use notification.php
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: `notification_id=${notification.id}`
                        });
                    }
                    // Once all fetched notifications are in the queue, process them
                    processNotificationQueue();
                    // Refresh main notification count after potentially 'consuming' new notifications
                    fetchNotificationsCount();
                }
            } catch (error) {
                console.error('Error fetching live notifications:', error);
            }
        }

        // Processes the notification queue
        function processNotificationQueue() {
            if (!isDisplayingNotification && notificationQueue.length > 0) {
                isDisplayingNotification = true;
                const notification = notificationQueue.shift(); // Get the next notification
                _actualDisplayLiveNotification(notification); // Call the internal displayer
            }
        }

        // Actual function to display a single live notification
        function _actualDisplayLiveNotification(notification) {
            const allowedTypes = ['Compra Aprovada', 'Pix Gerado', 'Boleto Gerado'];
            if (!allowedTypes.includes(notification.tipo)) {
                isDisplayingNotification = false; // Important: reset flag even if not displayed
                processNotificationQueue(); // Try next in queue
                return;
            }

            let messageText = '';
            let detailsText = '';
            const value = parseFloat(notification.valor).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
            const productName = notification.produto_nome || 'Um produto';

            switch (notification.tipo) {
                case 'Compra Aprovada':
                    messageText = `Nova Compra Aprovada!`;
                    detailsText = `${productName} por ${value} (${notification.metodo_pagamento})`;
                    break;
                case 'Pix Gerado':
                    messageText = `Pix Gerado!`;
                    detailsText = `${productName} por ${value}`;
                    break;
                case 'Boleto Gerado':
                    messageText = `Boleto Gerado!`;
                    detailsText = `${productName} por ${value}`;
                    break;
                default:
                    isDisplayingNotification = false; // Reset flag
                    processNotificationQueue(); // Try next in queue
                    return;
            }

            liveNotificationMessage.textContent = messageText;
            liveNotificationDetails.textContent = detailsText;
            
            // Set product image - usando favicon do sistema
            const faviconUrl = '<?php echo !empty($favicon_url) ? htmlspecialchars($favicon_url, ENT_QUOTES, 'UTF-8') : "https://i.ibb.co/gbNBTgDD/1757909548831.jpg"; ?>';
            liveNotificationProductImage.src = faviconUrl;
            
            // Play sound
            if (cashRegisterSound && audioContextResumed) { // Only play if context is resumed
                cashRegisterSound.load(); // Ensure the audio is ready to play
                cashRegisterSound.currentTime = 0; // Reset sound to start
                cashRegisterSound.volume = 1; // Ensure volume is audible for real notifications
                cashRegisterSound.play().catch(e => console.error("Error playing sound, autoplay might be blocked:", e));
            }

            liveNotificationContainer.classList.add('show');
            setTimeout(() => {
                liveNotificationContainer.classList.remove('show');
                isDisplayingNotification = false; // Reset flag
                processNotificationQueue(); // Process the next one in queue
            }, 8000); // Display for 8 seconds
        }

        notificationBell.addEventListener('click', () => {
            notificationPopup.classList.toggle('open');
            if (notificationPopup.classList.contains('open')) {
                fetchRecentNotifications();
                markNotificationsAsRead();
                // Verifica e mostra botão de ativar notificações push
                checkPushNotificationStatus();
            }
            // Attempt to resume audio context on bell click as well
            tryResumeAudioContext();
        });
        
        // Função para verificar status das notificações push e mostrar botão
        // Torna global para garantir acesso
        window.checkPushNotificationStatus = async function checkPushNotificationStatus() {
            const pushSection = document.getElementById('push-notification-activate-section');
            const activateBtn = document.getElementById('activate-push-notifications-btn');
            
            if (!pushSection) {
                return;
            }
            
            if (!activateBtn) {
                return;
            }
            
            try {
                // Verifica se push está habilitado
                const configResponse = await fetch('/api/admin_api.php?action=get_pwa_config');
                const configResult = await configResponse.json();
                
                if (!configResult.success || !configResult.data || !configResult.data.push_enabled) {
                    pushSection.style.display = 'none';
                    return;
                }
                
                // Mostra o botão apenas se a permissão não tiver sido concedida
                if (Notification.permission === 'default') {
                    pushSection.style.display = 'block';
                } else if (Notification.permission === 'granted') {
                    pushSection.style.display = 'none';
                    // Tenta registrar subscription se ainda não estiver registrada
                    if ('serviceWorker' in navigator) {
                        navigator.serviceWorker.ready.then(async (registration) => {
                            try {
                                await continuePushRegistration(registration);
                            } catch (error) {
                                // Erro ao registrar subscription
                            }
                        });
                    }
                } else {
                    // Permissão negada - mostra botão com texto diferente
                    pushSection.style.display = 'block';
                    // Atualiza texto do botão
                    activateBtn.innerHTML = '<i data-lucide="refresh-cw" class="w-4 h-4"></i> Verificar Novamente';
                    activateBtn.onclick = async () => {
                        await checkPermissionAndRegister();
                    };
                }
            } catch (error) {
                pushSection.style.display = 'none';
            }
        }
        
        // Função auxiliar para registrar subscription diretamente
        async function registerPushSubscriptionDirect(registration) {
            try {
                // Obtém chave VAPID pública
                const vapidResponse = await fetch('/api/admin_api.php?action=get_vapid_keys');
                const vapidResult = await vapidResponse.json();
                
                if (!vapidResult.success || !vapidResult.publicKey) {
                    throw new Error('Erro ao obter chave VAPID');
                }
                
                // Converte chave VAPID (Base64Url) para formato Uint8Array
                const urlBase64ToUint8Array = (base64String) => {
                    base64String = base64String.replace(/-/g, '+').replace(/_/g, '/');
                    const padding = '='.repeat((4 - base64String.length % 4) % 4);
                    const base64 = base64String + padding;
                    
                    const rawData = window.atob(base64);
                    const outputArray = new Uint8Array(rawData.length);
                    for (let i = 0; i < rawData.length; ++i) {
                        outputArray[i] = rawData.charCodeAt(i);
                    }
                    return outputArray;
                };
                
                const vapidPublicKey = urlBase64ToUint8Array(vapidResult.publicKey);
                
                // Obtém subscription
                const subscription = await registration.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: vapidPublicKey
                });
                
                // Envia subscription para o servidor
                const subscriptionData = {
                    endpoint: subscription.endpoint,
                    keys: {
                        p256dh: btoa(String.fromCharCode(...new Uint8Array(subscription.getKey('p256dh')))),
                        auth: btoa(String.fromCharCode(...new Uint8Array(subscription.getKey('auth'))))
                    }
                };
                
                const registerResponse = await fetch('/api/admin_api.php?action=register_push_subscription', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ subscription: subscriptionData })
                });
                
                if (!registerResponse.ok) {
                    const errorText = await registerResponse.text();
                    throw new Error('Erro HTTP ' + registerResponse.status + ': ' + errorText);
                }
                
                const registerResult = await registerResponse.json();
                
                if (!registerResult.success) {
                    throw new Error(registerResult.error || 'Erro ao registrar subscription');
                }
            } catch (error) {
                throw error;
            }
        }
        
        // Botão para ativar notificações push
        const activatePushBtn = document.getElementById('activate-push-notifications-btn');
        if (activatePushBtn) {
            activatePushBtn.addEventListener('click', async () => {
                activatePushBtn.disabled = true;
                activatePushBtn.innerHTML = '<i data-lucide="loader-2" class="w-4 h-4 animate-spin"></i> Ativando...';
                activatePushBtn.style.background = 'var(--accent-primary)';
                activatePushBtn.style.color = 'white';
                lucide.createIcons();
                
                try {
                    // Solicita permissão
                    const permission = await Notification.requestPermission();
                    
                    if (permission === 'granted') {
                        // Tenta registrar subscription se Service Worker estiver pronto
                        if ('serviceWorker' in navigator) {
                            try {
                                const registration = await navigator.serviceWorker.ready;
                                
                                // Registra subscription diretamente
                                await registerPushSubscriptionDirect(registration);
                                
                                activatePushBtn.innerHTML = '<i data-lucide="check" class="w-4 h-4"></i> Ativado!';
                                activatePushBtn.style.background = '#16a34a';
                                activatePushBtn.style.color = 'white';
                                
                                // Esconde o botão após 2 segundos
                                setTimeout(() => {
                                    const pushSection = document.getElementById('push-notification-activate-section');
                                    if (pushSection) {
                                        pushSection.style.display = 'none';
                                    }
                                }, 2000);
                            } catch (error) {
                                activatePushBtn.innerHTML = '<i data-lucide="bell" class="w-4 h-4"></i> Ativar';
                                activatePushBtn.disabled = false;
                            }
                        } else {
                            activatePushBtn.innerHTML = '<i data-lucide="check" class="w-4 h-4"></i> Ativado!';
                                activatePushBtn.style.background = '#16a34a';
                                activatePushBtn.style.color = 'white';
                            
                            setTimeout(() => {
                                const pushSection = document.getElementById('push-notification-activate-section');
                                if (pushSection) {
                                    pushSection.style.display = 'none';
                                }
                            }, 2000);
                        }
                    } else if (permission === 'denied') {
                        showPermissionDeniedMessage();
                        startPermissionMonitoring();
                        activatePushBtn.innerHTML = '<i data-lucide="refresh-cw" class="w-4 h-4"></i> Verificar Novamente';
                        activatePushBtn.disabled = false;
                        activatePushBtn.style.background = 'var(--accent-primary)';
                        activatePushBtn.style.color = 'white';
                        lucide.createIcons();
                    } else {
                        activatePushBtn.innerHTML = '<i data-lucide="bell" class="w-4 h-4"></i> Ativar';
                        activatePushBtn.disabled = false;
                        activatePushBtn.style.background = 'var(--accent-primary)';
                        activatePushBtn.style.color = 'white';
                    }
                } catch (error) {
                    activatePushBtn.innerHTML = '<i data-lucide="bell" class="w-4 h-4"></i> Ativar';
                    activatePushBtn.disabled = false;
                    activatePushBtn.style.background = 'var(--accent-primary)';
                    activatePushBtn.style.color = 'white';
                    lucide.createIcons();
                    activatePushBtn.style.background = 'var(--accent-primary)';
                    activatePushBtn.style.color = 'white';
                }
                
                lucide.createIcons();
            });
        }

        closePopupBtn.addEventListener('click', () => {
            notificationPopup.classList.remove('open');
        });

        // Close popup when clicking outside
        document.addEventListener('click', (event) => {
            if (!notificationPopup.contains(event.target) && !notificationBell.contains(event.target) && notificationPopup.classList.contains('open')) {
                notificationPopup.classList.remove('open');
            }
        });
        
        // Initial fetch and polling for count
        fetchNotificationsCount();
        setInterval(fetchNotificationsCount, 15000); // Poll every 15 seconds

        // Polling for live notifications (more frequent)
        fetchLiveNotifications();
        setInterval(fetchLiveNotifications, 10000);
        
        // --- Lógica do Dropdown do Perfil ---
        const profileDropdownBtn = document.getElementById('profile-dropdown-btn');
        const profileDropdownMenu = document.getElementById('profile-dropdown-menu');
        const switchToMemberBtn = document.getElementById('switch-to-member');
        
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
        
        // Alternar para painel de aluno
        if (switchToMemberBtn) {
            switchToMemberBtn.addEventListener('click', async () => {
                try {
                    const response = await fetch('/api/switch_panel.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({ view_mode: 'member' })
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
    <script>
        // Registra o Service Worker (apenas se módulo PWA instalado)
        <?php if ($pwa_module_installed): ?>
       // Detecta iOS e modo standalone
       const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
       const isStandalone = window.navigator.standalone === true || 
                            window.matchMedia('(display-mode: standalone)').matches;
       
       if ('serviceWorker' in navigator) {
           window.addEventListener('load', () => {
               navigator.serviceWorker.register('/pwa/sw.js').then(registration => {
                   // Registrar subscription de push após Service Worker estar pronto
                   registerPushSubscription(registration);
               }, err => {
                   // Fallback para sw.js na raiz se existir
                   navigator.serviceWorker.register('/sw.js').catch(() => {
                       // ServiceWorker não encontrado
                   });
               });
           });
           
           // Verifica e mostra banner de notificações após carregar a página
           // Não depende do Service Worker estar pronto
           setTimeout(() => {
               checkAndShowNotificationBanner();
           }, 2000); // Aguarda 2 segundos após carregar a página
           
           // Verifica periodicamente (a cada 5 minutos) se ainda não tiver permissão
           setInterval(() => {
               if (Notification.permission === 'default') {
                   checkAndShowNotificationBanner();
               }
           }, 5 * 60 * 1000); // 5 minutos
       } else {
           // Mesmo sem Service Worker, pode mostrar o banner se push estiver habilitado
           setTimeout(() => {
               checkAndShowNotificationBanner();
           }, 2000);
           
           // Verifica periodicamente (a cada 5 minutos) se ainda não tiver permissão
           setInterval(() => {
               if (Notification.permission === 'default') {
                   checkAndShowNotificationBanner();
               }
           }, 5 * 60 * 1000); // 5 minutos
       }
       
       // Verifica status do botão no sidebar quando a página carrega
       // (mesmo que o sidebar não esteja aberto)
       setTimeout(() => {
           checkPushNotificationStatus();
       }, 3000);
       
       // Verifica permissão imediatamente e inicia processos
       // Se permissão já está "granted" ao carregar, tenta registrar
       if (Notification.permission === 'granted') {
           setTimeout(() => {
               if (typeof window.checkPermissionAndRegister === 'function') {
                   window.checkPermissionAndRegister();
               }
           }, 3000);
       }
       
       // Se permissão está "denied", inicia monitoramento
       if (Notification.permission === 'denied') {
           setTimeout(() => {
               if (typeof window.startPermissionMonitoring === 'function') {
                   window.startPermissionMonitoring();
               }
           }, 3000);
       }
       
       // Adiciona listener para eventos do usuário para verificar permissão
       // (útil quando usuário permite manualmente e interage com a página)
       let userInteractionCheck = false;
       ['click', 'scroll', 'keydown'].forEach(eventType => {
           document.addEventListener(eventType, () => {
               if (!userInteractionCheck && Notification.permission === 'granted') {
                   userInteractionCheck = true;
                   setTimeout(() => {
                       checkPermissionAndRegister();
                   }, 1000);
               }
           }, { once: true, passive: true });
       });
        
        // Função para verificar permissão e registrar subscription automaticamente
        let permissionMonitorInterval = null;
        
        // Torna função global para garantir acesso
        window.checkPermissionAndRegister = async function checkPermissionAndRegister() {
            if (Notification.permission === 'granted') {
                // Para o monitoramento
                if (permissionMonitorInterval) {
                    clearInterval(permissionMonitorInterval);
                    permissionMonitorInterval = null;
                }
                
                // Tenta registrar subscription
                if ('serviceWorker' in navigator) {
                    try {
                        const registration = await navigator.serviceWorker.ready;
                        await continuePushRegistration(registration);
                    } catch (error) {
                        // Erro ao registrar subscription
                    }
                }
            }
        };
        
        // Inicia monitoramento periódico da permissão
        // Torna função global para garantir acesso
        window.startPermissionMonitoring = function startPermissionMonitoring() {
            // Para monitoramento anterior se existir
            if (permissionMonitorInterval) {
                clearInterval(permissionMonitorInterval);
            }
            
            // Verifica imediatamente
            checkPermissionAndRegister();
            
            // Verifica a cada 5 segundos
            permissionMonitorInterval = setInterval(() => {
                checkPermissionAndRegister();
            }, 5000);
            
            // Para após 5 minutos para não ficar verificando indefinidamente
            setTimeout(() => {
                if (permissionMonitorInterval) {
                    clearInterval(permissionMonitorInterval);
                    permissionMonitorInterval = null;
                }
            }, 5 * 60 * 1000);
        }
        
        // Função para mostrar mensagem quando permissão é negada
        // Torna função global para garantir acesso
        window.showPermissionDeniedMessage = function showPermissionDeniedMessage() {
            // Remove mensagem anterior se existir
            const existingMsg = document.getElementById('push-permission-denied-message');
            if (existingMsg) {
                existingMsg.remove();
            }
            
            const message = document.createElement('div');
            message.id = 'push-permission-denied-message';
            message.style.cssText = `
                position: fixed;
                bottom: 20px;
                right: 20px;
                max-width: 400px;
                background: #f59e0b;
                color: white;
                padding: 20px;
                border-radius: 12px;
                box-shadow: 0 10px 25px rgba(0,0,0,0.3);
                z-index: 10001;
                animation: slideUp 0.3s ease-out;
            `;
            
            message.innerHTML = `
                <div class="flex items-start gap-3">
                    <div class="flex-1">
                        <h3 class="font-bold text-lg mb-2">Permissão Negada</h3>
                        <p class="text-sm opacity-90 mb-3">Para receber notificações, permita nas configurações do navegador:</p>
                        <ol class="text-sm opacity-90 mb-3 list-decimal list-inside space-y-1">
                            <li>Clique no ícone de cadeado/carregando na barra de endereço</li>
                            <li>Encontre "Notificações"</li>
                            <li>Selecione "Permitir"</li>
                            <li>Recarregue a página</li>
                        </ol>
                        <div class="flex gap-2">
                            <button id="retry-permission-btn" class="px-4 py-2 rounded-lg font-semibold hover:opacity-90 transition" style="background: white; color: #f59e0b;">
                                Verificar Novamente
                            </button>
                            <button id="close-denied-message" class="px-4 py-2 bg-transparent border border-white rounded-lg font-semibold hover:bg-white/20 transition">
                                Fechar
                            </button>
                        </div>
                    </div>
                    <button id="close-denied-x" class="text-white opacity-70 hover:opacity-100">✕</button>
                </div>
            `;
            
            document.body.appendChild(message);
            
            // Botão verificar novamente
            document.getElementById('retry-permission-btn').addEventListener('click', async () => {
                await checkPermissionAndRegister();
                message.remove();
            });
            
            // Botões de fechar
            document.getElementById('close-denied-message').addEventListener('click', () => {
                message.remove();
            });
            
            document.getElementById('close-denied-x').addEventListener('click', () => {
                message.remove();
            });
            
            // Remove após 30 segundos
            setTimeout(() => {
                if (document.body.contains(message)) {
                    message.remove();
                }
            }, 30000);
        };
        
        // Função para mostrar banner informando que precisa instalar PWA no iOS
        function showIOSInstallRequiredBanner() {
            const existingBanner = document.getElementById('ios-pwa-install-required-banner');
            if (existingBanner) {
                existingBanner.remove();
            }
            
            const banner = document.createElement('div');
            banner.id = 'ios-pwa-install-required-banner';
            banner.style.cssText = `
                position: fixed;
                bottom: 20px;
                right: 20px;
                max-width: 400px;
                background: linear-gradient(135deg, #007AFF 0%, #0051D5 100%);
                color: white;
                padding: 20px;
                border-radius: 12px;
                box-shadow: 0 10px 25px rgba(0,0,0,0.3);
                z-index: 10002;
                animation: slideUp 0.3s ease-out;
            `;
            
            banner.innerHTML = `
                <div class="flex items-start gap-3">
                    <div class="flex-1">
                        <h3 class="font-bold text-lg mb-2">📱 Instale o App</h3>
                        <p class="text-sm opacity-90 mb-3">Para receber notificações no iPhone, você precisa instalar o app primeiro.</p>
                        <div class="flex gap-2">
                            <button id="ios-install-instructions-btn" class="px-4 py-2 rounded-lg font-semibold hover:opacity-90 transition" style="background: white; color: #007AFF;">
                                Como Instalar
                            </button>
                            <button id="close-ios-install-banner" class="px-4 py-2 bg-transparent border border-white rounded-lg font-semibold hover:bg-white/20 transition">
                                Depois
                            </button>
                        </div>
                    </div>
                    <button id="close-ios-install-x" class="text-white opacity-70 hover:opacity-100">✕</button>
                </div>
            `;
            
            document.body.appendChild(banner);
            
            // Botão de instruções
            document.getElementById('ios-install-instructions-btn').addEventListener('click', () => {
                showIOSInstallInstructions();
                banner.remove();
            });
            
            // Botões de fechar
            document.getElementById('close-ios-install-banner').addEventListener('click', () => {
                const hideUntil = Date.now() + (24 * 60 * 60 * 1000); // Esconde por 24 horas
                localStorage.setItem('ios_install_banner_hide_until', hideUntil.toString());
                banner.remove();
            });
            
            document.getElementById('close-ios-install-x').addEventListener('click', () => {
                const hideUntil = Date.now() + (24 * 60 * 60 * 1000); // Esconde por 24 horas
                localStorage.setItem('ios_install_banner_hide_until', hideUntil.toString());
                banner.remove();
            });
        }
        
        // Função para mostrar instruções de instalação iOS
        function showIOSInstallInstructions() {
            const existingModal = document.getElementById('ios-install-instructions-modal');
            if (existingModal) {
                existingModal.remove();
            }
            
            const modal = document.createElement('div');
            modal.id = 'ios-install-instructions-modal';
            modal.style.cssText = `
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(0,0,0,0.7);
                z-index: 10003;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
            `;
            
            modal.innerHTML = `
                <div style="background: white; border-radius: 16px; padding: 30px; max-width: 500px; width: 100%; max-height: 90vh; overflow-y: auto;">
                    <div class="flex justify-between items-center mb-4">
                        <h2 class="text-2xl font-bold text-gray-800">Como Instalar no iPhone</h2>
                        <button id="ios-instructions-close" class="text-gray-500 hover:text-gray-700 text-2xl">&times;</button>
                    </div>
                    <div class="space-y-4 text-gray-700">
                        <div class="flex gap-3">
                            <div class="flex-shrink-0 w-8 h-8 bg-blue-500 text-white rounded-full flex items-center justify-center font-bold">1</div>
                            <div>
                                <p class="font-semibold">Abra o Safari</p>
                                <p class="text-sm text-gray-600">Certifique-se de estar usando o Safari (não Chrome ou outros navegadores)</p>
                            </div>
                        </div>
                        <div class="flex gap-3">
                            <div class="flex-shrink-0 w-8 h-8 bg-blue-500 text-white rounded-full flex items-center justify-center font-bold">2</div>
                            <div>
                                <p class="font-semibold">Toque no botão Compartilhar</p>
                                <p class="text-sm text-gray-600">Localize o ícone de compartilhar na barra inferior do Safari (quadrado com seta para cima)</p>
                            </div>
                        </div>
                        <div class="flex gap-3">
                            <div class="flex-shrink-0 w-8 h-8 bg-blue-500 text-white rounded-full flex items-center justify-center font-bold">3</div>
                            <div>
                                <p class="font-semibold">Selecione "Adicionar à Tela de Início"</p>
                                <p class="text-sm text-gray-600">Role para baixo e encontre a opção "Adicionar à Tela de Início"</p>
                            </div>
                        </div>
                        <div class="flex gap-3">
                            <div class="flex-shrink-0 w-8 h-8 bg-blue-500 text-white rounded-full flex items-center justify-center font-bold">4</div>
                            <div>
                                <p class="font-semibold">Confirme a instalação</p>
                                <p class="text-sm text-gray-600">Toque em "Adicionar" no canto superior direito</p>
                            </div>
                        </div>
                        <div class="flex gap-3">
                            <div class="flex-shrink-0 w-8 h-8 text-white rounded-full flex items-center justify-center font-bold" style="background-color: var(--accent-primary);">✓</div>
                            <div>
                                <p class="font-semibold">Abra o app instalado</p>
                                <p class="text-sm text-gray-600">Depois de instalar, abra o app pela tela de início. As notificações estarão disponíveis!</p>
                            </div>
                        </div>
                    </div>
                    <div class="mt-6 flex justify-end">
                        <button id="ios-instructions-ok" class="px-6 py-2 bg-blue-500 text-white rounded-lg font-semibold hover:bg-blue-600 transition">
                            Entendi
                        </button>
                    </div>
                </div>
            `;
            
            document.body.appendChild(modal);
            
            document.getElementById('ios-instructions-close').addEventListener('click', () => {
                modal.remove();
            });
            
            document.getElementById('ios-instructions-ok').addEventListener('click', () => {
                modal.remove();
            });
            
            modal.addEventListener('click', (e) => {
                if (e.target === modal) {
                    modal.remove();
                }
            });
        }
        
        // Função para verificar e mostrar banner de notificações
        async function checkAndShowNotificationBanner() {
            try {
                // Detecta iOS
                const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
                const isStandalone = window.navigator.standalone === true || 
                                     window.matchMedia('(display-mode: standalone)').matches;
                
                // Verifica se push está habilitado
                const configResponse = await fetch('/api/admin_api.php?action=get_pwa_config');
                const configResult = await configResponse.json();
                
                if (!configResult.success || !configResult.data || !configResult.data.push_enabled) {
                    return; // Push não habilitado
                }
                
                // No iOS, notificações push só funcionam se a PWA estiver instalada
                if (isIOS && !isStandalone) {
                    // Mostra banner informando que precisa instalar primeiro
                    showIOSInstallRequiredBanner();
                    return;
                }
                
                // Verifica se já tem permissão ou foi negada
                if (Notification.permission === 'granted') {
                    // Se já tem permissão, tenta registrar subscription
                    if ('serviceWorker' in navigator) {
                        navigator.serviceWorker.ready.then(async (registration) => {
                            try {
                                await continuePushRegistration(registration);
                            } catch (error) {
                                // Erro ao registrar subscription
                            }
                        });
                    }
                    return;
                }
                
                if (Notification.permission === 'denied') {
                    // Inicia verificação periódica para detectar quando permissão mudar
                    startPermissionMonitoring();
                    return;
                }
                
                // Verifica se está escondido temporariamente (30 minutos)
                const hideUntil = localStorage.getItem('push_permission_banner_hide_until');
                if (hideUntil && parseInt(hideUntil) > Date.now()) {
                    // Ainda está no período de esconder
                    // Agenda para verificar novamente quando o período expirar
                    const timeLeft = parseInt(hideUntil) - Date.now();
                    setTimeout(() => {
                        if (Notification.permission === 'default') {
                            checkAndShowNotificationBanner();
                        }
                    }, timeLeft + 1000);
                    return;
                }
                
                // Mostra o banner
                showNotificationPermissionBanner(async () => {
                    const permission = await Notification.requestPermission();
                    
                    if (permission === 'granted') {
                        // Tenta registrar subscription se Service Worker estiver pronto
                        if ('serviceWorker' in navigator) {
                            try {
                                const registration = await navigator.serviceWorker.ready;
                                await continuePushRegistration(registration);
                            } catch (error) {
                                // Erro ao registrar subscription
                            }
                        }
                    } else if (permission === 'denied') {
                        showPermissionDeniedMessage();
                        startPermissionMonitoring();
                    }
                });
            } catch (error) {
                // Erro ao verificar banner de notificações
            }
        }
        
        // Função para registrar subscription de push
        async function registerPushSubscription(registration) {
            try {
                // Verifica se push está habilitado
                const configResponse = await fetch('/api/admin_api.php?action=get_pwa_config');
                const configResult = await configResponse.json();
                
                if (!configResult.success || !configResult.data || !configResult.data.push_enabled) {
                    return;
                }
                
                // Verifica se já tem permissão
                if (Notification.permission === 'denied') {
                    return;
                }
                
                // Se não tem permissão, não faz nada (o banner já foi mostrado)
                if (Notification.permission === 'default') {
                    return;
                }
                
                // Verifica se já tem subscription registrada
                const existingSubscription = await registration.pushManager.getSubscription();
                if (existingSubscription) {
                    // SEMPRE atualiza a subscription para garantir que está com o usuario_id correto
                    // Isso resolve o problema quando o usuário troca de conta
                    try {
                        const subscriptionData = {
                            endpoint: existingSubscription.endpoint,
                            keys: {
                                p256dh: btoa(String.fromCharCode(...new Uint8Array(existingSubscription.getKey('p256dh')))),
                                auth: btoa(String.fromCharCode(...new Uint8Array(existingSubscription.getKey('auth'))))
                            }
                        };
                        
                        const checkResponse = await fetch('/api/admin_api.php?action=register_push_subscription', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ subscription: subscriptionData })
                        });
                        
                        if (checkResponse.ok) {
                            const result = await checkResponse.json();
                            if (result.success) {
                                // Subscription atualizada para o usuário atual
                            }
                        }
                    } catch (e) {
                        // Erro ao atualizar subscription - continua para registrar novamente
                    }
                }
                
                // Se já tem permissão, continua
                await continuePushRegistration(registration);
            } catch (error) {
                // Erro ao registrar subscription de push
            }
        }
        
        // Função para mostrar banner de permissão
        function showNotificationPermissionBanner(onAccept) {
            // Remove banner anterior se existir
            const existingBanner = document.getElementById('push-permission-banner');
            if (existingBanner) {
                existingBanner.remove();
            }
            
            // Se já tem permissão, tenta registrar subscription
            if (Notification.permission === 'granted') {
                if ('serviceWorker' in navigator) {
                    navigator.serviceWorker.ready.then(async (registration) => {
                        try {
                            await continuePushRegistration(registration);
                        } catch (error) {
                            // Erro ao registrar subscription
                        }
                    });
                }
                return;
            }
            
            // Se foi negada, inicia monitoramento
            if (Notification.permission === 'denied') {
                startPermissionMonitoring();
                return;
            }
            
            const banner = document.createElement('div');
            banner.id = 'push-permission-banner';
            banner.style.cssText = `
                position: fixed;
                bottom: 20px;
                right: 20px;
                max-width: 400px;
                background: linear-gradient(135deg, var(--accent-primary) 0%, var(--accent-primary-hover) 100%);
                color: white;
                padding: 20px;
                border-radius: 12px;
                box-shadow: 0 10px 25px rgba(0,0,0,0.3);
                z-index: 10000;
                animation: slideUp 0.3s ease-out;
            `;
            
            banner.innerHTML = `
                <div class="flex items-start gap-3">
                    <div class="flex-1">
                        <h3 class="font-bold text-lg mb-2">Receba Notificações</h3>
                        <p class="text-sm opacity-90 mb-3">Permita notificações para receber atualizações importantes em tempo real.</p>
                        <div class="flex gap-2">
                            <button id="accept-push-btn" class="px-4 py-2 rounded-lg font-semibold hover:opacity-90 transition" style="background: white; color: var(--accent-primary);">
                                Permitir
                            </button>
                            <button id="dismiss-push-btn" class="px-4 py-2 bg-transparent border border-white rounded-lg font-semibold hover:bg-white/20 transition" style="color: white;">
                                Agora não
                            </button>
                        </div>
                    </div>
                    <button id="close-push-banner" class="text-white opacity-70 hover:opacity-100">✕</button>
                </div>
            `;
            
            document.body.appendChild(banner);
            
            // Event listeners
            document.getElementById('accept-push-btn').addEventListener('click', async () => {
                banner.remove();
                if (onAccept) {
                    await onAccept();
                } else {
                    // Se não tiver callback, solicita permissão diretamente
                    const permission = await Notification.requestPermission();
                    
                    if (permission === 'granted') {
                        // Tenta registrar subscription se Service Worker estiver pronto
                        if ('serviceWorker' in navigator) {
                            try {
                                const registration = await navigator.serviceWorker.ready;
                                await registerPushSubscription(registration);
                            } catch (error) {
                                // Erro ao registrar subscription
                            }
                        }
                    }
                }
            });
            
            document.getElementById('dismiss-push-btn').addEventListener('click', () => {
                // Esconde por 30 minutos ao clicar em "Agora não"
                const hideUntil = Date.now() + (30 * 60 * 1000);
                localStorage.setItem('push_permission_banner_hide_until', hideUntil.toString());
                banner.remove();
                // Verifica novamente após o período se ainda não tiver permissão
                setTimeout(() => {
                    if (Notification.permission === 'default') {
                        checkAndShowNotificationBanner();
                    }
                }, 30 * 60 * 1000);
            });
            
            document.getElementById('close-push-banner').addEventListener('click', () => {
                // Esconde por 30 minutos ao fechar
                const hideUntil = Date.now() + (30 * 60 * 1000);
                localStorage.setItem('push_permission_banner_hide_until', hideUntil.toString());
                banner.remove();
                // Verifica novamente após o período se ainda não tiver permissão
                setTimeout(() => {
                    if (Notification.permission === 'default') {
                        checkAndShowNotificationBanner();
                    }
                }, 30 * 60 * 1000);
            });
            
            // Adiciona animação CSS
            if (!document.getElementById('push-banner-style')) {
                const style = document.createElement('style');
                style.id = 'push-banner-style';
                style.textContent = `
                    @keyframes slideUp {
                        from {
                            transform: translateY(100%);
                            opacity: 0;
                        }
                        to {
                            transform: translateY(0);
                            opacity: 1;
                        }
                    }
                `;
                document.head.appendChild(style);
            }
        }
        
        // Função para continuar o registro após permissão concedida
        async function continuePushRegistration(registration) {
            try {
                
                // Obtém chave VAPID pública
                const vapidResponse = await fetch('/api/admin_api.php?action=get_vapid_keys');
                const vapidResult = await vapidResponse.json();
                
                if (!vapidResult.success || !vapidResult.publicKey) {
                    return;
                }
                
                // Converte chave VAPID (Base64Url) para formato Uint8Array
                const urlBase64ToUint8Array = (base64String) => {
                    // Remove padding se existir
                    base64String = base64String.replace(/-/g, '+').replace(/_/g, '/');
                    const padding = '='.repeat((4 - base64String.length % 4) % 4);
                    const base64 = base64String + padding;
                    
                    try {
                        const rawData = window.atob(base64);
                        const outputArray = new Uint8Array(rawData.length);
                        
                        for (let i = 0; i < rawData.length; ++i) {
                            outputArray[i] = rawData.charCodeAt(i);
                        }
                        return outputArray;
                    } catch (e) {
                        throw e;
                    }
                };
                
                const vapidPublicKey = urlBase64ToUint8Array(vapidResult.publicKey);
                
                // Obtém subscription
                const subscription = await registration.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: vapidPublicKey
                });
                
                // Envia subscription para o servidor
                const subscriptionData = {
                    endpoint: subscription.endpoint,
                    keys: {
                        p256dh: btoa(String.fromCharCode(...new Uint8Array(subscription.getKey('p256dh')))),
                        auth: btoa(String.fromCharCode(...new Uint8Array(subscription.getKey('auth'))))
                    }
                };
                
                const registerResponse = await fetch('/api/admin_api.php?action=register_push_subscription', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ subscription: subscriptionData })
                });
                
                if (!registerResponse.ok) {
                    const errorText = await registerResponse.text();
                    throw new Error('Erro HTTP ' + registerResponse.status + ': ' + errorText);
                }
                
                const registerResult = await registerResponse.json();
                
                if (!registerResult.success) {
                    throw new Error(registerResult.error || 'Erro ao registrar subscription');
                }
            } catch (error) {
                // Erro ao continuar registro de push
            }
        }
        <?php else: ?>
        // Service Worker padrão (se existir)
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('/sw.js').then(registration => {
                    // ServiceWorker registrado
                }, err => {
                    // Falha no registro do ServiceWorker
                });
            });
        }
        <?php endif; ?>
        
        // ==========================================================
        // PWA INSTALL PROMPT (Android e iOS)
        // ==========================================================
        <?php if ($pwa_module_installed && isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true): ?>
        (function() {
            const PWA_INSTALLED_KEY = 'pwa_installed';
            const PWA_DISMISSED_KEY = 'pwa_dismissed';
            const PWA_DISMISSED_TIME_KEY = 'pwa_dismissed_time';
            const DISMISS_COOLDOWN = 7 * 24 * 60 * 60 * 1000; // 7 dias em milissegundos
            
            // Opção de debug: força exibição do banner
            const FORCE_SHOW_DEBUG = localStorage.getItem('pwa_force_show') === 'true';
            
            // Melhorar detecção de iOS com múltiplas verificações
            const userAgent = navigator.userAgent || '';
            const platform = navigator.platform || '';
            const isIPad = /iPad/.test(userAgent) || (platform === 'MacIntel' && navigator.maxTouchPoints > 1);
            const isIPhone = /iPhone/.test(userAgent);
            const isIPod = /iPod/.test(userAgent);
            const isIOSDevice = (isIPad || isIPhone || isIPod) && !window.MSStream;
            
            // Valores do localStorage
            const storedInstalled = localStorage.getItem(PWA_INSTALLED_KEY);
            const storedDismissed = localStorage.getItem(PWA_DISMISSED_KEY);
            const storedDismissedTime = localStorage.getItem(PWA_DISMISSED_TIME_KEY);
            
            // Limpa localStorage antigo se necessário (para permitir mostrar novamente)
            // Se o usuário desinstalou o app, limpa a flag de instalado
            if (isIOSDevice) {
                const wasInstalled = storedInstalled === 'true';
                const isStandaloneNow = window.navigator.standalone === true || 
                                      window.matchMedia('(display-mode: standalone)').matches;
                
                // Se estava marcado como instalado mas não está mais em standalone, limpa
                if (wasInstalled && !isStandaloneNow) {
                    localStorage.removeItem(PWA_INSTALLED_KEY);
                }
            }
            
            // Verifica se está em modo standalone (já instalado)
            // iOS: window.navigator.standalone === true quando instalado
            // Android: window.matchMedia('(display-mode: standalone)').matches
            const isStandalone = window.matchMedia('(display-mode: standalone)').matches || 
                                 (window.navigator.standalone === true) ||
                                 document.referrer.includes('android-app://') ||
                                 // Verifica se está em modo fullscreen (também indica PWA)
                                 window.matchMedia('(display-mode: fullscreen)').matches;
            
            // Se está em standalone, marca como instalado mas NÃO retorna ainda (permite debug)
            if (isStandalone && !FORCE_SHOW_DEBUG) {
                localStorage.setItem(PWA_INSTALLED_KEY, 'true');
                return; // Já está instalado
            }
            
            // Verifica se já instalou ou recusou recentemente (apenas se não estiver em standalone)
            const isInstalled = localStorage.getItem(PWA_INSTALLED_KEY) === 'true';
            const dismissedTime = localStorage.getItem(PWA_DISMISSED_TIME_KEY);
            const isDismissed = dismissedTime && (Date.now() - parseInt(dismissedTime)) < DISMISS_COOLDOWN;
            
            // No iOS, permite mostrar novamente após 1 dia (mais permissivo)
            const iOS_DISMISS_COOLDOWN = 24 * 60 * 60 * 1000; // 1 dia para iOS
            const isDismissedIOS = isIOSDevice && dismissedTime && (Date.now() - parseInt(dismissedTime)) < iOS_DISMISS_COOLDOWN;
            
            if (isInstalled && !isStandalone && !FORCE_SHOW_DEBUG) {
                // Se marcou como instalado mas não está em standalone, limpa (pode ter desinstalado)
                localStorage.removeItem(PWA_INSTALLED_KEY);
            }
            
            // Para iOS, usa cooldown menor (1 dia), para outros usa 7 dias
            // Ignora cooldown se modo debug estiver ativado
            if (!FORCE_SHOW_DEBUG) {
                if (isIOSDevice && isDismissedIOS) {
                    return; // Não mostra prompt
                } else if (!isIOSDevice && isDismissed) {
                    return; // Não mostra prompt
                }
            }
            
            let deferredPrompt = null;
            // isIOSDevice já foi definido acima
            const isAndroid = /Android/.test(navigator.userAgent);
            const themeColor = getComputedStyle(document.documentElement).getPropertyValue('--accent-primary').trim() || '#32e768';
            
            // Cria banner de instalação
            function createInstallBanner() {
                const banner = document.createElement('div');
                banner.id = 'pwa-install-banner';
                banner.style.cssText = `
                    position: fixed;
                    bottom: 20px;
                    left: 50%;
                    transform: translateX(-50%);
                    width: 90%;
                    max-width: 400px;
                    background: linear-gradient(135deg, ${themeColor} 0%, ${themeColor}dd 100%);
                    color: white;
                    padding: 16px 20px;
                    border-radius: 12px;
                    box-shadow: 0 10px 25px rgba(0,0,0,0.3);
                    z-index: 10000;
                    display: flex;
                    align-items: center;
                    gap: 12px;
                    animation: slideUp 0.3s ease-out;
                    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                `;
                
                const icon = document.createElement('div');
                icon.innerHTML = '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2v20M2 12h20"/></svg>';
                icon.style.cssText = 'flex-shrink: 0;';
                
                const content = document.createElement('div');
                content.style.cssText = 'flex: 1;';
                content.innerHTML = `
                    <div style="font-weight: 600; font-size: 15px; margin-bottom: 4px;">Instalar App</div>
                    <div style="font-size: 13px; opacity: 0.9;">Adicione à tela inicial para acesso rápido</div>
                `;
                
                const installBtn = document.createElement('button');
                installBtn.textContent = 'Instalar';
                installBtn.style.cssText = `
                    background: rgba(255,255,255,0.2);
                    border: 1px solid rgba(255,255,255,0.3);
                    color: white;
                    padding: 8px 16px;
                    border-radius: 6px;
                    font-weight: 600;
                    font-size: 14px;
                    cursor: pointer;
                    transition: all 0.2s;
                    flex-shrink: 0;
                `;
                installBtn.onmouseover = function() { this.style.background = 'rgba(255,255,255,0.3)'; };
                installBtn.onmouseout = function() { this.style.background = 'rgba(255,255,255,0.2)'; };
                
                const closeBtn = document.createElement('button');
                closeBtn.innerHTML = '✕';
                closeBtn.style.cssText = `
                    background: transparent;
                    border: none;
                    color: white;
                    font-size: 18px;
                    width: 24px;
                    height: 24px;
                    cursor: pointer;
                    opacity: 0.8;
                    flex-shrink: 0;
                `;
                closeBtn.onmouseover = function() { this.style.opacity = '1'; };
                closeBtn.onmouseout = function() { this.style.opacity = '0.8'; };
                
                // Ações dos botões
                installBtn.addEventListener('click', function() {
                    if (deferredPrompt) {
                        // Android
                        deferredPrompt.prompt();
                        deferredPrompt.userChoice.then((choiceResult) => {
                            if (choiceResult.outcome === 'accepted') {
                                localStorage.setItem(PWA_INSTALLED_KEY, 'true');
                            } else {
                                localStorage.setItem(PWA_DISMISSED_KEY, 'true');
                                localStorage.setItem(PWA_DISMISSED_TIME_KEY, Date.now().toString());
                            }
                            deferredPrompt = null;
                            banner.remove();
                        });
                    } else if (isIOSDevice) {
                        // iOS - mostra instruções
                        showIOSInstructions();
                        banner.remove();
                    }
                });
                
                closeBtn.addEventListener('click', function() {
                    localStorage.setItem(PWA_DISMISSED_KEY, 'true');
                    localStorage.setItem(PWA_DISMISSED_TIME_KEY, Date.now().toString());
                    banner.remove();
                });
                
                banner.appendChild(icon);
                banner.appendChild(content);
                banner.appendChild(installBtn);
                banner.appendChild(closeBtn);
                
                // Adiciona animação CSS
                const style = document.createElement('style');
                style.textContent = `
                    @keyframes slideUp {
                        from {
                            transform: translateX(-50%) translateY(100%);
                            opacity: 0;
                        }
                        to {
                            transform: translateX(-50%) translateY(0);
                            opacity: 1;
                        }
                    }
                `;
                document.head.appendChild(style);
                
                document.body.appendChild(banner);
                
                // Remove após 30 segundos se não interagir
                setTimeout(() => {
                    if (document.body.contains(banner)) {
                        banner.style.animation = 'slideUp 0.3s ease-out reverse';
                        setTimeout(() => banner.remove(), 300);
                    }
                }, 30000);
            }
            
            // Instruções para iOS
            function showIOSInstructions() {
                const modal = document.createElement('div');
                modal.style.cssText = `
                    position: fixed;
                    top: 0;
                    left: 0;
                    right: 0;
                    bottom: 0;
                    background: rgba(0,0,0,0.7);
                    z-index: 10001;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    padding: 20px;
                `;
                
                const content = document.createElement('div');
                content.style.cssText = `
                    background: #1e293b;
                    border-radius: 16px;
                    padding: 24px;
                    max-width: 400px;
                    width: 100%;
                    color: white;
                `;
                
                content.innerHTML = `
                    <h3 style="font-size: 20px; font-weight: 700; margin-bottom: 16px; color: ${themeColor};">Instalar no iPhone</h3>
                    <ol style="list-style: decimal; padding-left: 20px; line-height: 1.8;">
                        <li>Toque no botão <strong>Compartilhar</strong> <span style="color: ${themeColor};">□↑</span> na parte inferior</li>
                        <li>Role para baixo e toque em <strong>"Adicionar à Tela de Início"</strong></li>
                        <li>Toque em <strong>"Adicionar"</strong> no canto superior direito</li>
                    </ol>
                    <button id="ios-install-close" style="margin-top: 20px; width: 100%; padding: 12px; background: ${themeColor}; color: white; border: none; border-radius: 8px; font-weight: 600; cursor: pointer;">Entendi</button>
                `;
                
                modal.appendChild(content);
                document.body.appendChild(modal);
                
                document.getElementById('ios-install-close').addEventListener('click', function() {
                    modal.remove();
                    localStorage.setItem(PWA_DISMISSED_KEY, 'true');
                    localStorage.setItem(PWA_DISMISSED_TIME_KEY, Date.now().toString());
                });
                
                modal.addEventListener('click', function(e) {
                    if (e.target === modal) {
                        modal.remove();
                        localStorage.setItem(PWA_DISMISSED_KEY, 'true');
                        localStorage.setItem(PWA_DISMISSED_TIME_KEY, Date.now().toString());
                    }
                });
            }
            
            // Android: captura evento beforeinstallprompt
            window.addEventListener('beforeinstallprompt', (e) => {
                e.preventDefault();
                deferredPrompt = e;
                createInstallBanner();
            });
            
            // Função auxiliar para verificar se deve mostrar banner
            function shouldShowBanner() {
                // Modo debug sempre mostra
                if (FORCE_SHOW_DEBUG) {
                    return true;
                }
                
                // Não mostra se estiver em standalone
                const stillStandalone = window.navigator.standalone === true || 
                                      window.matchMedia('(display-mode: standalone)').matches;
                if (stillStandalone) {
                    return false;
                }
                
                // Não mostra se foi instalado
                const stillInstalled = localStorage.getItem(PWA_INSTALLED_KEY) === 'true';
                if (stillInstalled) {
                    return false;
                }
                
                // Verifica cooldown
                const dismissedTime = localStorage.getItem(PWA_DISMISSED_TIME_KEY);
                const iOS_DISMISS_COOLDOWN = 24 * 60 * 60 * 1000; // 1 dia para iOS
                const stillDismissed = dismissedTime && (Date.now() - parseInt(dismissedTime)) < iOS_DISMISS_COOLDOWN;
                if (stillDismissed && isIOSDevice) {
                    return false;
                }
                
                return true;
            }
            
            // iOS: mostra banner após delay (apenas se não estiver em standalone)
            if (isIOSDevice) {
                // Verifica se as meta tags necessárias estão presentes
                const metaTag = document.querySelector('meta[name="apple-mobile-web-app-capable"]');
                const manifestLink = document.querySelector('link[rel="manifest"]');
                const appleTouchIcon = document.querySelector('link[rel="apple-touch-icon"]');
                
                // Verifica se deve mostrar (considera todas as condições)
                if (shouldShowBanner()) {
                    // Reduz delay para 3 segundos no iOS para melhor UX
                    setTimeout(() => {
                        // Verifica novamente antes de mostrar (pode ter mudado)
                        if (shouldShowBanner()) {
                            createInstallBanner();
                        }
                    }, 3000);
                }
            } else if (isIOSDevice && window.navigator.standalone) {
                localStorage.setItem(PWA_INSTALLED_KEY, 'true');
            }
            
            // FALLBACK: Se chegou até aqui e é iOS mas não mostrou banner, tenta mostrar após delay maior
            // Isso garante que mesmo com problemas menores, o banner apareça
            // Só executa se não estiver em modo debug (modo debug já força exibição)
            if (isIOSDevice && !window.navigator.standalone) {
                setTimeout(() => {
                    // Verifica se o banner já foi criado
                    const existingBanner = document.getElementById('pwa-install-banner');
                    if (existingBanner) {
                        return;
                    }
                    
                    // Verifica condições novamente (modo debug já foi tratado antes)
                    if (shouldShowBanner()) {
                        createInstallBanner();
                    }
                }, 8000); // Fallback após 8 segundos
            }
            
            // Detecta se foi instalado
            window.addEventListener('appinstalled', () => {
                localStorage.setItem(PWA_INSTALLED_KEY, 'true');
                const banner = document.getElementById('pwa-install-banner');
                if (banner) banner.remove();
            });
        })();
        <?php endif; ?>
    </script>
    <?php do_action('dashboard_footer'); ?>
</body>
</html>