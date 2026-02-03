<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/helpers/security_helper.php';

// Este painel (admin.php) é exclusivo para administradores do sistema.
// Infoprodutores acessam o painel principal (index.php) e clientes finais acessam a área de membros (member_area_dashboard.php).
// Proteção de página: verifica se o usuário está logado E se é um administrador
require_admin_auth();

// Fetch admin user data for display (no longer displayed, but session is still valid)
$admin_user_id = $_SESSION['id'];
$admin_user_name_display = htmlspecialchars($_SESSION['usuario']); 

// Sistema de roteamento simples para o painel de admin
$pagina_admin = isset($_GET['pagina']) ? $_GET['pagina'] : 'admin_dashboard';
$role_filter = isset($_GET['role']) ? $_GET['role'] : 'all'; // Nova variável para o filtro de função
$paginas_permitidas_admin = ['admin_dashboard', 'admin_usuarios', 'admin_relatorios', 'admin_smtp_config', 'admin_configuracoes', 'admin_banner', 'admin_pwa', 'admin_revenda_autorizada', 'admin_broadcast', 'admin_conquistas', 'admin_update', 'admin_integridade', 'admin_plugins', 'saas_config', 'saas_planos', 'saas_gateways', 'saas_assinaturas'];

// Classes para o menu ativo - Modern Glassmorphism Design
$active_class = 'sidebar-item sidebar-item-active';
$inactive_class = 'sidebar-item sidebar-item-inactive';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Painel do Administrador</title>
    <?php include __DIR__ . '/config/load_settings.php'; ?>
    
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
    tailwind.config = {
      theme: {
        extend: {
          colors: {
            orange: { 50: '#fff7ed', 100: '#ffedd5', 200: '#fed7aa', 300: '#fdba74', 400: '#fb923c', 500: '#f97316', 600: '#ea580c', 700: '#c2410c', 800: '#9a3412', 900: '#7c2d12' }
          }
        }
      }
    }
    </script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link rel="stylesheet" href="style.css">
    <!-- CSRF Token para JavaScript -->
    <?php
    require_once __DIR__ . '/helpers/security_helper.php';
    $csrf_token_js = generate_csrf_token();
    ?>
    <meta name="csrf-token" content="<?php echo htmlspecialchars($csrf_token_js); ?>">
    <script>
        // Variável global para token CSRF
        window.csrfToken = '<?php echo htmlspecialchars($csrf_token_js); ?>';
    </script>
    <script src="/assets/js/csrf-auto-refresh.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    <?php do_action('admin_head'); ?>
    <style>
        /* Live Floating Notification */
        .live-notification-container {
            position: fixed;
            bottom: 20px;
            right: 20px;
            width: 320px;
            background-color: white;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
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
            border: 1px solid #e5e7eb; /* Adiciona uma borda sutil ao ícone */
        }
        .cash-register-sound {
            display: none; /* Hide audio element */
        }


    </style>
</head>
<body class="font-sans flex flex-col min-h-screen" style="background-color: #07090d;">
    <!-- Header Fixo Invisível (Topo) -->
    <header class="fixed top-0 left-0 right-0 z-40 bg-dark-base/80 backdrop-blur-sm h-[60px] flex items-center justify-between px-4 md:px-6">
        <!-- Botão de Toggle Mobile -->
        <button id="admin-sidebar-toggle" class="md:hidden p-2 rounded-lg bg-dark-elevated border border-dark-border text-white hover:bg-dark-card transition-colors">
            <i data-lucide="menu" class="w-6 h-6"></i>
        </button>
        <div class="hidden md:block"></div> <!-- Espaçador para desktop -->
        
        <!-- Controles do Header (Revenda Autorizada e Logout) -->
        <div class="flex items-center space-x-3">
            <a href="/admin?pagina=admin_revenda_autorizada" class="<?php echo $pagina_admin == 'admin_revenda_autorizada' ? 'text-primary bg-dark-elevated' : 'text-gray-400 hover:text-primary'; ?> transition-colors duration-200 p-2 rounded-lg hover:bg-dark-elevated flex items-center space-x-2" title="Revenda Autorizada">
                <i data-lucide="store" class="w-5 h-5"></i>
                <span class="hidden md:inline">Revenda Autorizada</span>
            </a>
            <a href="/logout" class="text-gray-400 hover:text-red-500 transition-colors duration-200 p-2 rounded-lg hover:bg-dark-elevated" title="Sair">
                <i data-lucide="log-out" class="w-5 h-5"></i>
            </a>
        </div>
    </header>

    <!-- Menu Lateral do Admin -->
    <?php do_action('admin_sidebar_before'); ?>
    <aside id="admin-sidebar" class="sidebar-glass fixed top-0 left-0 bottom-0 z-50 transform -translate-x-full transition-transform duration-300 w-full max-w-xs md:translate-x-0 md:w-64 flex flex-col overflow-y-auto">
        <!-- Sidebar Header (Logo) -->
        <div class="sidebar-header flex flex-col items-center">
            <img src="<?php echo htmlspecialchars($logo_url); ?>" alt="Logotipo" class="h-10 w-auto mb-2">
            <span class="text-sm font-semibold text-white px-2 py-1 rounded-full">PAINEL ADMIN</span>
        </div>
        
        <nav class="mt-4 flex-grow px-2">
            <?php do_action('admin_menu_before'); ?>
            <a href="/admin?pagina=admin_dashboard" class="<?php echo $pagina_admin == 'admin_dashboard' ? $active_class : $inactive_class; ?>">
                <i data-lucide="bar-chart-3" class="w-5 h-5"></i>
                <span>Dashboard Admin</span>
            </a>
            
            <!-- Gerenciamento de Usuários (Links Separados) -->
            <div>
                <a href="/admin?pagina=admin_usuarios&role=all" class="<?php echo ($pagina_admin == 'admin_usuarios' && $role_filter == 'all') ? $active_class : $inactive_class; ?>">
                    <i data-lucide="users" class="w-5 h-5"></i>
                    <span>Todos os Usuários</span>
                </a>
                <a href="/admin?pagina=admin_usuarios&role=infoproducer" class="<?php echo ($pagina_admin == 'admin_usuarios' && $role_filter == 'infoproducer') ? $active_class : $inactive_class; ?>">
                    <i data-lucide="award" class="w-5 h-5"></i>
                    <span>Gerenciar Infoprodutores</span>
                </a>
                <a href="/admin?pagina=admin_usuarios&role=client" class="<?php echo ($pagina_admin == 'admin_usuarios' && $role_filter == 'client') ? $active_class : $inactive_class; ?>">
                    <i data-lucide="handshake" class="w-5 h-5"></i>
                    <span>Gerenciar Clientes Finais</span>
                </a>
            </div>

            <a href="/admin?pagina=admin_relatorios" class="<?php echo $pagina_admin == 'admin_relatorios' ? $active_class : $inactive_class; ?>">
                <i data-lucide="file-text" class="w-5 h-5"></i>
                <span>Relatórios Detalhados</span>
            </a>
            <!-- NOVO: Link para Configurações SMTP -->
            <a href="/admin?pagina=admin_smtp_config" class="<?php echo $pagina_admin == 'admin_smtp_config' ? $active_class : $inactive_class; ?>">
                <i data-lucide="mail" class="w-5 h-5"></i>
                <span>Configurações SMTP</span>
            </a>
            <!-- NOVO: Link para Email Marketing / Broadcast -->
            <a href="/admin?pagina=admin_broadcast" class="<?php echo $pagina_admin == 'admin_broadcast' ? $active_class : $inactive_class; ?>">
                <i data-lucide="send" class="w-5 h-5"></i>
                <span>Email Marketing</span>
            </a>
            <!-- NOVO: Link para Configurações do Sistema -->
            <a href="/admin?pagina=admin_configuracoes" class="<?php echo $pagina_admin == 'admin_configuracoes' ? $active_class : $inactive_class; ?>">
                <i data-lucide="settings" class="w-5 h-5"></i>
                <span>Configurações</span>
            </a>
            <a href="/admin?pagina=admin_plugins" class="<?php echo $pagina_admin == 'admin_plugins' ? $active_class : $inactive_class; ?>">
                <i data-lucide="puzzle" class="w-5 h-5"></i>
                <span>Plugins</span>
            </a>
            <a href="/admin?pagina=admin_conquistas" class="<?php echo $pagina_admin == 'admin_conquistas' ? $active_class : $inactive_class; ?>">
                <i data-lucide="trophy" class="w-5 h-5"></i>
                <span>Conquistas</span>
            </a>
            <div class="ml-4 mt-1 space-y-1">
                <a href="/admin?pagina=admin_banner" class="<?php echo $pagina_admin == 'admin_banner' ? $active_class : $inactive_class; ?>">
                    <i data-lucide="image" class="w-4 h-4"></i>
                    <span>Banner</span>
                </a>
                <a href="/admin?pagina=admin_pwa" class="<?php echo $pagina_admin == 'admin_pwa' ? $active_class : $inactive_class; ?>">
                    <i data-lucide="smartphone" class="w-4 h-4"></i>
                    <span>PWA</span>
                </a>
            </div>
            
            <?php
            // Carregar funções SaaS se disponível
            if (file_exists(__DIR__ . '/saas/includes/saas_functions.php')) {
                require_once __DIR__ . '/saas/includes/saas_functions.php';
            }
            
            // Menu SaaS (sempre visível, mas submenu só quando habilitado)
            $saas_enabled = function_exists('saas_enabled') ? saas_enabled() : false;
            ?>
            
            <!-- Modo SaaS -->
            <a href="/admin?pagina=saas_config" class="<?php echo $pagina_admin == 'saas_config' ? $active_class : $inactive_class; ?>">
                <i data-lucide="layers" class="w-5 h-5"></i>
                <span>Modo SaaS</span>
            </a>
            
            <?php if ($saas_enabled): ?>
            <div class="ml-4 mt-1 space-y-1">
                <a href="/admin?pagina=saas_planos" class="<?php echo $pagina_admin == 'saas_planos' ? $active_class : $inactive_class; ?>">
                    <i data-lucide="package" class="w-4 h-4"></i>
                    <span>Planos</span>
                </a>
                <a href="/admin?pagina=saas_gateways" class="<?php echo $pagina_admin == 'saas_gateways' ? $active_class : $inactive_class; ?>">
                    <i data-lucide="credit-card" class="w-4 h-4"></i>
                    <span>Gateways</span>
                </a>
                <a href="/admin?pagina=saas_assinaturas" class="<?php echo $pagina_admin == 'saas_assinaturas' ? $active_class : $inactive_class; ?>">
                    <i data-lucide="users" class="w-4 h-4"></i>
                    <span>Assinaturas</span>
                </a>
            </div>
            <?php endif; ?>
            
            <?php
            // Itens de menu dinâmicos de plugins usando filter
            if (function_exists('apply_filters')) {
                $plugin_menu_items = apply_filters('admin_menu_items', []);
                if (!empty($plugin_menu_items) && is_array($plugin_menu_items)) {
                    foreach ($plugin_menu_items as $item) {
                        if (isset($item['title']) && isset($item['url'])) {
                            $icon = $item['icon'] ?? 'settings';
                            $is_active = (strpos($_SERVER['REQUEST_URI'], $item['url']) !== false);
                            echo '<a href="' . htmlspecialchars($item['url']) . '" class="' . ($is_active ? $active_class : $inactive_class) . '">';
                            echo '<i data-lucide="' . htmlspecialchars($icon) . '" class="w-5 h-5"></i>';
                            echo '<span>' . htmlspecialchars($item['title']) . '</span>';
                            echo '</a>';
                        }
                    }
                }
            }
            
            // Itens de sidebar dinâmicos usando filter
            if (function_exists('apply_filters')) {
                $plugin_sidebar_items = apply_filters('admin_sidebar_items', []);
                if (!empty($plugin_sidebar_items) && is_array($plugin_sidebar_items)) {
                    foreach ($plugin_sidebar_items as $item) {
                        if (isset($item['title']) && isset($item['url'])) {
                            $icon = $item['icon'] ?? 'settings';
                            $is_active = (strpos($_SERVER['REQUEST_URI'], $item['url']) !== false);
                            echo '<a href="' . htmlspecialchars($item['url']) . '" class="' . ($is_active ? $active_class : $inactive_class) . '">';
                            echo '<i data-lucide="' . htmlspecialchars($icon) . '" class="w-5 h-5"></i>';
                            echo '<span>' . htmlspecialchars($item['title']) . '</span>';
                            echo '</a>';
                        }
                    }
                }
            }
            ?>
            <?php do_action('admin_menu_after'); ?>
            
            <!-- Atualizações (movido para o final) -->
            <a href="/admin?pagina=admin_update" class="<?php echo $pagina_admin == 'admin_update' ? $active_class : $inactive_class; ?>">
                <i data-lucide="download" class="w-5 h-5"></i>
                <span>Atualizações</span>
            </a>
            <!-- Integridade -->
            <a href="/admin?pagina=admin_integridade" class="<?php echo $pagina_admin == 'admin_integridade' ? $active_class : $inactive_class; ?>">
                <i data-lucide="shield-check" class="w-5 h-5"></i>
                <span>Integridade</span>
            </a>
        </nav>
        
        <!-- Footer do Sidebar com Versão -->
        <div class="mt-auto px-2 pb-3 pt-2">
            <div class="text-left">
                <p class="text-xs text-gray-400 mb-1">Versão da Plataforma</p>
                <div class="px-2 py-1 rounded text-xs font-semibold inline-flex items-center gap-1.5" style="background-color: rgba(50, 231, 104, 0.1); border: 1px solid rgba(50, 231, 104, 0.3);">
                    <i data-lucide="tag" class="w-3 h-3" style="color: var(--accent-primary);"></i>
                    <span style="color: var(--accent-primary);">v<?php echo htmlspecialchars(get_platform_version()); ?></span>
                </div>
            </div>
        </div>
        <?php do_action('admin_sidebar_after'); ?>
    </aside>

    <!-- Overlay para o menu mobile -->
    <div id="admin-sidebar-overlay" class="fixed inset-0 bg-black bg-opacity-50 z-30 hidden"></div>

    <!-- Conteúdo Principal -->
    <main class="flex-1 md:ml-64 mt-[60px] p-6 lg:p-8 overflow-y-auto">
        <?php 
        $page_title = apply_filters('admin_page_title', 'Painel do Administrador');
        if ($pagina_admin == 'admin_dashboard') {
            do_action('admin_dashboard_before');
        }
        
        // Hooks específicos por página
        do_action("admin_{$pagina_admin}_before");
        
        if (in_array($pagina_admin, $paginas_permitidas_admin) && file_exists(__DIR__ . '/views/admin/' . $pagina_admin . '.php')) {
            include __DIR__ . '/views/admin/' . $pagina_admin . '.php';
        } else {
            echo "<div class='text-center p-10 bg-dark-card rounded-lg shadow border border-dark-border'><h1 class='text-4xl font-bold text-white'>Erro 404</h1><p class='mt-2 text-gray-400'>Página não encontrada no painel administrativo.</p></div>";
        }
        
        // Hooks específicos por página
        do_action("admin_{$pagina_admin}_after");
        
        if ($pagina_admin == 'admin_dashboard') {
            do_action('admin_dashboard_after');
        }
        ?>
    </main>

    <!-- Floating Live Notification (Mantido para o admin ver, se quiser, mas não ligado ao sininho) -->
    <div id="live-notification-container" class="live-notification-container">
        <img id="live-notification-product-image" src="https://i.ibb.co/gbNBTgDD/1757909548831.jpg" alt="Produto" class="live-notification-product-image">
        <div>
            <p class="text-sm font-semibold text-gray-900" id="live-notification-message"></p>
            <p class="text-xs text-gray-500 mt-1" id="live-notification-details"></p>
        </div>
        <audio id="cash-register-sound" class="cash-register-sound" src="assets/cash_register.mp3" preload="auto"></audio>
    </div>

    <script>
        // --- Lógica de Responsividade do Menu Lateral ---
        const adminSidebarToggle = document.getElementById('admin-sidebar-toggle');
        const adminSidebar = document.getElementById('admin-sidebar');
        const adminSidebarOverlay = document.getElementById('admin-sidebar-overlay');
        const body = document.body;

        function toggleAdminSidebar() {
            adminSidebar.classList.toggle('-translate-x-full');
            adminSidebar.classList.toggle('open');
            adminSidebarOverlay.classList.toggle('hidden');
            adminSidebarOverlay.classList.toggle('open');
            body.classList.toggle('overflow-hidden');
        }

        adminSidebarToggle.addEventListener('click', toggleAdminSidebar);
        adminSidebarOverlay.addEventListener('click', toggleAdminSidebar);

        window.addEventListener('resize', () => {
            if (window.innerWidth >= 768) { // Desktop breakpoint
                adminSidebar.classList.remove('-translate-x-full', 'open');
                adminSidebarOverlay.classList.add('hidden'); // Ensure overlay is hidden
                adminSidebarOverlay.classList.remove('open');
                body.classList.remove('overflow-hidden');
            } else { // Mobile breakpoint
                // Ensure desktop classes are not present if resized back to mobile
                // and sidebar should be hidden by default unless opened manually
                if (!adminSidebar.classList.contains('open')) {
                    adminSidebar.classList.add('-translate-x-full');
                }
            }
        });


        // --- Lógica de Notificações Flutuantes (Live Notifications) ---
        const liveNotificationContainer = document.getElementById('live-notification-container');
        const liveNotificationMessage = document.getElementById('live-notification-message');
        const liveNotificationDetails = document.getElementById('live-notification-details');
        const liveNotificationProductImage = document.getElementById('live-notification-product-image');
        const cashRegisterSound = document.getElementById('cash-register-sound');

        let audioContextResumed = false;
        let notificationQueue = [];
        let isDisplayingNotification = false;

        function tryResumeAudioContext() {
            if (!audioContextResumed && cashRegisterSound) {
                const originalVolume = cashRegisterSound.volume; // Store original volume
                cashRegisterSound.volume = 0; // Set volume to 0 for silent unlock

                if (!cashRegisterSound.src || cashRegisterSound.readyState < 2) {
                    cashRegisterSound.load();
                    cashRegisterSound.oncanplaythrough = () => {
                         cashRegisterSound.play().then(() => {
                            audioContextResumed = true;
                            cashRegisterSound.pause();
                            cashRegisterSound.currentTime = 0;
                            cashRegisterSound.volume = originalVolume; // Restore original volume
                        }).catch(e => {
                            console.warn("Autoplay prevented after load, waiting for user interaction.", e);
                            cashRegisterSound.volume = originalVolume; // Restore original volume on error
                        });
                        cashRegisterSound.oncanplaythrough = null;
                    };
                    return;
                }
                cashRegisterSound.play().then(() => {
                    audioContextResumed = true;
                    cashRegisterSound.pause();
                    cashRegisterSound.currentTime = 0;
                    cashRegisterSound.volume = originalVolume; // Restore original volume
                }).catch(e => {
                    console.warn("Autoplay was prevented, waiting for user interaction.", e);
                    cashRegisterSound.volume = originalVolume; // Restore original volume on error
                });
            }
        }
        document.addEventListener('click', tryResumeAudioContext, { once: true });
        document.addEventListener('keydown', tryResumeAudioContext, { once: true });

        async function fetchLiveNotifications() {
            try {
                const response = await fetch('/api/notifications_api.php?action=get_live_notifications');
                if (!response.ok) throw new Error(`HTTP error! status: ${response.status}`);
                const data = await response.json();

                if (data.live_notifications && data.live_notifications.length > 0) {
                    for (const notification of data.live_notifications) {
                        notificationQueue.push(notification); 
                        await fetch('/api/notifications_api.php?action=mark_as_displayed_live', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                            body: `notification_id=${notification.id}`
                        });
                    }
                    processNotificationQueue();
                }
            } catch (error) {
                console.error('Error fetching live notifications:', error);
            }
        }

        function processNotificationQueue() {
            if (!isDisplayingNotification && notificationQueue.length > 0) {
                isDisplayingNotification = true;
                const notification = notificationQueue.shift();
                _actualDisplayLiveNotification(notification);
            }
        }

        function _actualDisplayLiveNotification(notification) {
            const allowedTypes = ['Compra Aprovada', 'Pix Gerado', 'Boleto Gerado'];
            if (!allowedTypes.includes(notification.tipo)) {
                isDisplayingNotification = false;
                processNotificationQueue();
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
                    isDisplayingNotification = false;
                    processNotificationQueue();
                    return;
            }

            liveNotificationMessage.textContent = messageText;
            liveNotificationDetails.textContent = detailsText;
            // Modificação: Usa a foto do produto se disponível, caso contrário, usa a imagem padrão
            liveNotificationProductImage.src = notification.produto_foto ? 'uploads/' + notification.produto_foto : 'https://i.ibb.co/gbNBTgDD/1757909548831.jpg';
            
            if (cashRegisterSound && audioContextResumed) {
                cashRegisterSound.load();
                cashRegisterSound.currentTime = 0;
                cashRegisterSound.volume = 1; // Ensure volume is audible for real notifications
                cashRegisterSound.play().catch(e => console.error("Error playing sound:", e));
            }

            liveNotificationContainer.classList.add('show');
            setTimeout(() => {
                liveNotificationContainer.classList.remove('show');
                isDisplayingNotification = false;
                processNotificationQueue();
            }, 8000);
        }
        
        // Polling for live notifications (more frequent)
        fetchLiveNotifications();
        setInterval(fetchLiveNotifications, 10000);

    </script>
    <?php do_action('admin_footer'); ?>
    <script>
        // Move lucide.createIcons() to the very end of the body to ensure all elements are parsed.
        lucide.createIcons();
    </script>
    <script>
        // Registra o Service Worker
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => {
                navigator.serviceWorker.register('/sw.js').then(registration => {
                    console.log('ServiceWorker registrado com sucesso: ', registration.scope);
                }, err => {
                    console.log('Falha no registro do ServiceWorker: ', err);
                });
            });
        }
    </script>
</body>
</html>