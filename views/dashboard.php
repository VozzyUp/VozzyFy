<div class="container mx-auto">
    <?php
    // Carregar cor primária para uso direto nos cards
    if (!function_exists('getSystemSetting')) {
        require_once __DIR__ . '/../config/config.php';
    }
    // Incluir funções auxiliares se não existirem
    if (!function_exists('hexToRgb')) {
        require_once __DIR__ . '/../config/load_settings.php';
    }
    $cor_primaria_dashboard = getSystemSetting('cor_primaria', '#32e768');
    $cor_primaria_hover_dashboard = adjustBrightness($cor_primaria_dashboard, -10);
    
    // Banner do Dashboard
    $banner_config_json = getSystemSetting('dashboard_banner_config', '{}');
    $banner_config = json_decode($banner_config_json, true);
    
    if ($banner_config && isset($banner_config['enabled']) && $banner_config['enabled'] === true && !empty($banner_config['banners'])) {
        $banner_type = $banner_config['type'] ?? 'single';
        $banners = $banner_config['banners'] ?? [];
        
        // Normalizar URLs dos banners
        $banners = array_map(function($banner) {
            if (strpos($banner, 'http') === 0) {
                return $banner;
            }
            return '/' . ltrim($banner, '/');
        }, $banners);
        
        if ($banner_type === 'single' && count($banners) > 0) {
            // Banner único
            ?>
            <div class="mb-6 rounded-lg overflow-hidden">
                <img src="<?php echo htmlspecialchars($banners[0]); ?>" alt="Banner" class="w-full h-auto object-cover" style="max-height: 200px;">
            </div>
            <?php
        } elseif ($banner_type === 'carousel' && count($banners) > 0) {
            // Carrossel de banners
            $autoplay = isset($banner_config['autoplay']) ? $banner_config['autoplay'] : true;
            // Interval está em segundos, converter para milissegundos
            $interval_seconds = isset($banner_config['interval']) ? (int)$banner_config['interval'] : 5;
            $interval_ms = $interval_seconds * 1000; // Converter segundos para milissegundos
            ?>
            <div class="mb-6 rounded-lg overflow-hidden relative" id="dashboard-banner-carousel" style="max-height: 200px; height: 200px;">
                <div class="carousel-container relative w-full h-full" style="position: relative; width: 100%; height: 100%;">
                    <?php foreach ($banners as $index => $banner): ?>
                        <div class="carousel-slide" data-index="<?php echo $index; ?>" style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; opacity: <?php echo $index === 0 ? '1' : '0'; ?>; transition: opacity 0.6s ease-in-out; z-index: <?php echo $index === 0 ? '2' : '1'; ?>;">
                            <img src="<?php echo htmlspecialchars($banner); ?>" alt="Banner <?php echo $index + 1; ?>" class="w-full h-full object-cover" style="width: 100%; height: 100%; object-fit: cover;">
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if (count($banners) > 1): ?>
                    <!-- Navegação do carrossel -->
                    <div class="absolute bottom-4 left-1/2 transform -translate-x-1/2 flex gap-2 z-20">
                        <?php foreach ($banners as $index => $banner): ?>
                            <button class="carousel-dot w-2 h-2 rounded-full transition-colors cursor-pointer <?php echo $index === 0 ? 'bg-white' : 'bg-gray-400'; ?>" data-index="<?php echo $index; ?>" aria-label="Ir para banner <?php echo $index + 1; ?>"></button>
                        <?php endforeach; ?>
                    </div>
                    <!-- Setas de navegação -->
                    <button class="carousel-prev absolute left-4 top-1/2 transform -translate-y-1/2 bg-black/50 hover:bg-black/70 text-white p-2 rounded-full transition-colors z-20 cursor-pointer" aria-label="Banner anterior" style="z-index: 20;">
                        <i data-lucide="chevron-left" class="w-6 h-6"></i>
                    </button>
                    <button class="carousel-next absolute right-4 top-1/2 transform -translate-y-1/2 bg-black/50 hover:bg-black/70 text-white p-2 rounded-full transition-colors z-20 cursor-pointer" aria-label="Próximo banner" style="z-index: 20;">
                        <i data-lucide="chevron-right" class="w-6 h-6"></i>
                    </button>
                <?php endif; ?>
            </div>
            <script>
            (function() {
                'use strict';
                
                // Aguardar DOM estar pronto
                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', initCarousel);
                } else {
                    initCarousel();
                }
                
                function initCarousel() {
                    // Tentar encontrar o carrossel
                    let carousel = document.querySelector('[id^="dashboard-banner-carousel"]');
                    if (!carousel) return;
                    
                    const slides = carousel.querySelectorAll('.carousel-slide');
                    const dots = carousel.querySelectorAll('.carousel-dot');
                    const prevBtn = carousel.querySelector('.carousel-prev');
                    const nextBtn = carousel.querySelector('.carousel-next');
                    
                    if (slides.length === 0 || slides.length === 1) return;
                    
                    let currentIndex = 0;
                    let autoplayInterval = null;
                    let isTransitioning = false;
                    const autoplayEnabled = <?php echo $autoplay ? 'true' : 'false'; ?>;
                    const autoplayDelay = <?php echo $interval_ms; ?>; // Já está em milissegundos
                    
                    function showSlide(index) {
                        if (isTransitioning) return;
                        if (index < 0 || index >= slides.length) return;
                        
                        isTransitioning = true;
                        
                        // Atualizar slides
                        slides.forEach((slide, i) => {
                            if (i === index) {
                                slide.style.opacity = '1';
                                slide.style.zIndex = '3';
                            } else {
                                slide.style.opacity = '0';
                                if (i < index) {
                                    slide.style.zIndex = '1';
                                } else {
                                    slide.style.zIndex = '2';
                                }
                            }
                        });
                        
                        // Atualizar dots
                        dots.forEach((dot, i) => {
                            if (i === index) {
                                dot.classList.remove('bg-gray-400');
                                dot.classList.add('bg-white');
                            } else {
                                dot.classList.remove('bg-white');
                                dot.classList.add('bg-gray-400');
                            }
                        });
                        
                        currentIndex = index;
                        
                        // Liberar transição após animação
                        setTimeout(() => {
                            isTransitioning = false;
                        }, 600);
                    }
                    
                    function nextSlide() {
                        const next = (currentIndex + 1) % slides.length;
                        showSlide(next);
                    }
                    
                    function prevSlide() {
                        const prev = (currentIndex - 1 + slides.length) % slides.length;
                        showSlide(prev);
                    }
                    
                    function startAutoplay() {
                        stopAutoplay();
                        if (autoplayEnabled && slides.length > 1) {
                            autoplayInterval = setInterval(() => {
                                if (!isTransitioning) {
                                    nextSlide();
                                }
                            }, autoplayDelay);
                        }
                    }
                    
                    function stopAutoplay() {
                        if (autoplayInterval) {
                            clearInterval(autoplayInterval);
                            autoplayInterval = null;
                        }
                    }
                    
                    // Event listeners
                    if (nextBtn) {
                        nextBtn.addEventListener('click', function(e) {
                            e.preventDefault();
                            e.stopPropagation();
                            if (!isTransitioning) {
                                stopAutoplay();
                                nextSlide();
                                startAutoplay();
                            }
                        });
                    }
                    
                    if (prevBtn) {
                        prevBtn.addEventListener('click', function(e) {
                            e.preventDefault();
                            e.stopPropagation();
                            if (!isTransitioning) {
                                stopAutoplay();
                                prevSlide();
                                startAutoplay();
                            }
                        });
                    }
                    
                    dots.forEach((dot, index) => {
                        dot.addEventListener('click', function(e) {
                            e.preventDefault();
                            e.stopPropagation();
                            if (!isTransitioning && index !== currentIndex) {
                                stopAutoplay();
                                showSlide(index);
                                startAutoplay();
                            }
                        });
                    });
                    
                    // Pausar ao passar mouse
                    carousel.addEventListener('mouseenter', stopAutoplay);
                    carousel.addEventListener('mouseleave', startAutoplay);
                    
                    // Inicializar
                    showSlide(0);
                    
                    // Iniciar autoplay após um pequeno delay
                    setTimeout(() => {
                        startAutoplay();
                    }, 1000);
                    
                    // Inicializar ícones Lucide
                    if (typeof lucide !== 'undefined') {
                        setTimeout(() => {
                            lucide.createIcons();
                        }, 200);
                    }
                }
            })();
            </script>
            <?php
        }
    }
    ?>
    
    <h1 class="text-3xl font-bold text-white mb-6">Dashboard</h1>
    
    <!-- Cards de Métricas Principais -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-6">
        <div class="group relative bg-dark-card p-6 rounded-lg shadow-md border transition-all duration-300 ease-in-out transform hover:scale-105" style="border-color: <?php echo htmlspecialchars($cor_primaria_dashboard); ?>;">
            <!-- Subtle Neon Overlay -->
            <div class="absolute inset-0 rounded-lg opacity-0 group-hover:opacity-30 pointer-events-none z-0 bg-[size:200%_100%] bg-[position:0%_0%] group-hover:bg-[position:100%_0%] transition-all duration-500 ease-in-out" style="background: linear-gradient(to left, <?php echo htmlspecialchars($cor_primaria_dashboard); ?>, <?php echo htmlspecialchars($cor_primaria_hover_dashboard); ?>);"></div>
            
            <!-- Card Content -->
            <div class="relative z-10">
                <h3 class="flex items-center text-gray-400 text-sm font-medium mb-2">
                    <i data-lucide="line-chart" class="w-5 h-5 mr-2" style="color: <?php echo htmlspecialchars($cor_primaria_dashboard); ?>;"></i>
                    Vendas Totais
                </h3>
                <p id="vendas-totais" class="text-3xl font-bold text-white">R$ 0,00</p>
            </div>
        </div>
        <div class="group relative bg-dark-card p-6 rounded-lg shadow-md border transition-all duration-300 ease-in-out transform hover:scale-105" style="border-color: <?php echo htmlspecialchars($cor_primaria_dashboard); ?>;">
            <!-- Subtle Neon Overlay -->
            <div class="absolute inset-0 rounded-lg opacity-0 group-hover:opacity-30 pointer-events-none z-0 bg-[size:200%_100%] bg-[position:0%_0%] group-hover:bg-[position:100%_0%] transition-all duration-500 ease-in-out" style="background: linear-gradient(to left, <?php echo htmlspecialchars($cor_primaria_dashboard); ?>, <?php echo htmlspecialchars($cor_primaria_hover_dashboard); ?>);"></div>
            
            <!-- Card Content -->
            <div class="relative z-10">
                <h3 class="flex items-center text-gray-400 text-sm font-medium mb-2">
                    <i data-lucide="bar-chart-2" class="w-5 h-5 mr-2" style="color: <?php echo htmlspecialchars($cor_primaria_dashboard); ?>;"></i>
                    Quantidade de Vendas
                </h3>
                <p id="quantidade-vendas" class="text-3xl font-bold text-white">0</p>
            </div>
        </div>
        <div class="group relative bg-dark-card p-6 rounded-lg shadow-md border transition-all duration-300 ease-in-out transform hover:scale-105" style="border-color: <?php echo htmlspecialchars($cor_primaria_dashboard); ?>;">
            <!-- Subtle Neon Overlay -->
            <div class="absolute inset-0 rounded-lg opacity-0 group-hover:opacity-30 pointer-events-none z-0 bg-[size:200%_100%] bg-[position:0%_0%] group-hover:bg-[position:100%_0%] transition-all duration-500 ease-in-out" style="background: linear-gradient(to left, <?php echo htmlspecialchars($cor_primaria_dashboard); ?>, <?php echo htmlspecialchars($cor_primaria_hover_dashboard); ?>);"></div>
            
            <!-- Card Content -->
            <div class="relative z-10">
                <h3 class="flex items-center text-gray-400 text-sm font-medium mb-2">
                    <i data-lucide="wallet" class="w-5 h-5 mr-2" style="color: <?php echo htmlspecialchars($cor_primaria_dashboard); ?>;"></i>
                    Ticket Médio
                </h3>
                <p id="ticket-medio" class="text-3xl font-bold text-white">R$ 0,00</p>
            </div>
        </div>
         <div class="group relative bg-dark-card p-6 rounded-lg shadow-md border transition-all duration-300 ease-in-out transform hover:scale-105" style="border-color: <?php echo htmlspecialchars($cor_primaria_dashboard); ?>;">
            <!-- Subtle Neon Overlay -->
            <div class="absolute inset-0 rounded-lg opacity-0 group-hover:opacity-30 pointer-events-none z-0 bg-[size:200%_100%] bg-[position:0%_0%] group-hover:bg-[position:100%_0%] transition-all duration-500 ease-in-out" style="background: linear-gradient(to left, <?php echo htmlspecialchars($cor_primaria_dashboard); ?>, <?php echo htmlspecialchars($cor_primaria_hover_dashboard); ?>);"></div>
            
            <!-- Card Content -->
            <div class="relative z-10">
                <h3 class="flex items-center text-gray-400 text-sm font-medium mb-2">
                    <i data-lucide="package" class="w-5 h-5 mr-2" style="color: <?php echo htmlspecialchars($cor_primaria_dashboard); ?>;"></i>
                    Total de Produtos
                </h3>
                <p id="total-produtos" class="text-3xl font-bold text-white">0</p>
            </div>
        </div>
    </div>

    <!-- Cards de Métricas Adicionais -->
    <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6"> <!-- Alterado para md:grid-cols-4 para adicionar o novo card -->
        <div class="group relative bg-dark-card p-6 rounded-lg shadow-md border transition-all duration-300 ease-in-out transform hover:scale-105" style="border-color: <?php echo htmlspecialchars($cor_primaria_dashboard); ?>;">
            <!-- Subtle Neon Overlay -->
            <div class="absolute inset-0 rounded-lg opacity-0 group-hover:opacity-30 pointer-events-none z-0 bg-[size:200%_100%] bg-[position:0%_0%] group-hover:bg-[position:100%_0%] transition-all duration-500 ease-in-out" style="background: linear-gradient(to left, <?php echo htmlspecialchars($cor_primaria_dashboard); ?>, <?php echo htmlspecialchars($cor_primaria_hover_dashboard); ?>);"></div>
            
            <!-- Card Content -->
            <div class="relative z-10">
                <h3 class="flex items-center text-gray-400 text-sm font-medium mb-2">
                    <i data-lucide="hourglass" class="w-5 h-5 mr-2" style="color: <?php echo htmlspecialchars($cor_primaria_dashboard); ?>;"></i> <!-- Ícone de ampulheta para pendente -->
                    Vendas Pendentes
                </h3>
                <p id="vendas-pendentes-valor" class="text-2xl font-bold text-white">R$ 0,00</p>
                <p class="text-sm text-gray-400">Total: <span id="vendas-pendentes-quantidade">0</span> vendas</p>
            </div>
        </div>
        <div class="group relative bg-dark-card p-6 rounded-lg shadow-md border transition-all duration-300 ease-in-out transform hover:scale-105" style="border-color: <?php echo htmlspecialchars($cor_primaria_dashboard); ?>;">
            <!-- Subtle Neon Overlay -->
            <div class="absolute inset-0 rounded-lg opacity-0 group-hover:opacity-30 pointer-events-none z-0 bg-[size:200%_100%] bg-[position:0%_0%] group-hover:bg-[position:100%_0%] transition-all duration-500 ease-in-out" style="background: linear-gradient(to left, <?php echo htmlspecialchars($cor_primaria_dashboard); ?>, <?php echo htmlspecialchars($cor_primaria_hover_dashboard); ?>);"></div>
            
            <!-- Card Content -->
            <div class="relative z-10">
                <h3 class="flex items-center text-gray-400 text-sm font-medium mb-2">
                    <i data-lucide="shopping-cart" class="w-5 h-5 mr-2" style="color: <?php echo htmlspecialchars($cor_primaria_dashboard); ?>;"></i>
                    Abandono Carrinho
                </h3>
                <p id="abandono-carrinho" class="text-3xl font-bold text-white">0</p>
            </div>
        </div>
        <div class="group relative bg-dark-card p-6 rounded-lg shadow-md border transition-all duration-300 ease-in-out transform hover:scale-105" style="border-color: <?php echo htmlspecialchars($cor_primaria_dashboard); ?>;">
            <!-- Subtle Neon Overlay -->
            <div class="absolute inset-0 rounded-lg opacity-0 group-hover:opacity-30 pointer-events-none z-0 bg-[size:200%_100%] bg-[position:0%_0%] group-hover:bg-[position:100%_0%] transition-all duration-500 ease-in-out" style="background: linear-gradient(to left, <?php echo htmlspecialchars($cor_primaria_dashboard); ?>, <?php echo htmlspecialchars($cor_primaria_hover_dashboard); ?>);"></div>
            
            <!-- Card Content -->
            <div class="relative z-10">
                <h3 class="flex items-center text-gray-400 text-sm font-medium mb-2">
                    <i data-lucide="redo" class="w-5 h-5 mr-2" style="color: <?php echo htmlspecialchars($cor_primaria_dashboard); ?>;"></i>
                    Reembolso
                </h3>
                <p id="reembolsos" class="text-3xl font-bold text-white">R$ 0,00</p>
            </div>
        </div>
        <div class="group relative bg-dark-card p-6 rounded-lg shadow-md border transition-all duration-300 ease-in-out transform hover:scale-105" style="border-color: <?php echo htmlspecialchars($cor_primaria_dashboard); ?>;">
            <!-- Subtle Neon Overlay -->
            <div class="absolute inset-0 rounded-lg opacity-0 group-hover:opacity-30 pointer-events-none z-0 bg-[size:200%_100%] bg-[position:0%_0%] group-hover:bg-[position:100%_0%] transition-all duration-500 ease-in-out" style="background: linear-gradient(to left, <?php echo htmlspecialchars($cor_primaria_dashboard); ?>, <?php echo htmlspecialchars($cor_primaria_hover_dashboard); ?>);"></div>
            
            <!-- Card Content -->
            <div class="relative z-10">
                <h3 class="flex items-center text-gray-400 text-sm font-medium mb-2">
                    <i data-lucide="badge-x" class="w-5 h-5 mr-2" style="color: <?php echo htmlspecialchars($cor_primaria_dashboard); ?>;"></i>
                    Charge Back
                </h3>
                <p id="chargebacks" class="text-3xl font-bold text-white">R$ 0,00</p>
            </div>
        </div>
    </div>

    <!-- NOVA SEÇÃO: Jornada Starfy -->
    <!-- COMENTADO - Não será usado por enquanto
    <div class="bg-gradient-to-br from-purple-800 via-indigo-900 to-black p-6 rounded-lg shadow-md mb-6 text-white relative overflow-hidden">
        <div class="absolute inset-0 opacity-30" style="background-image: url('https://static.vecteezy.com/ti/fotos-gratis/p1/2097706-3d-realistic-nebula-space-background-gratis-foto.jpg'); background-size: cover; background-position: center;"></div>
        
        <div class="relative z-10">
            <h2 class="text-3xl font-extrabold text-white mb-4">Sua Jornada Starfy</h2>
            <div class="flex items-center space-x-4 mb-2">
                <i data-lucide="star" class="w-8 h-8 text-yellow-400 flex-shrink-0"></i>
                <div>
                    <p class="text-lg font-bold" id="journey-stage-name">Carregando Etapa...</p>
                    <p class="text-sm text-gray-300" id="journey-stage-description">...</p>
                </div>
            </div>

            <div class="mt-6">
                <div class="w-full bg-dark-elevated rounded-full h-3.5 mb-2 relative">
                    <div id="journey-progress-bar-fill" class="bg-orange-500 h-3.5 rounded-full transition-all duration-700 ease-out" style="width: 0%;"></div>
                    <span id="journey-progress-text" class="absolute inset-0 flex items-center justify-center text-xs font-semibold text-white opacity-90">0%</span>
                </div>
                <div class="flex justify-between text-sm mt-1">
                    <span id="journey-current-amount" class="font-medium text-gray-200">R$ 0,00</span>
                    <span id="journey-next-amount" class="font-bold text-white">R$ 0,00</span>
                </div>
            </div>
            <p id="journey-next-stage-message" class="text-xs text-gray-400 mt-2 text-right">Faltam X para a próxima etapa!</p>
        </div>
    </div>
    -->


    <!-- Gráfico de Vendas -->
    <div class="bg-dark-card p-6 rounded-lg shadow-md border" style="border-color: <?php echo htmlspecialchars($cor_primaria_dashboard); ?>;">
        <div class="flex flex-wrap items-center justify-between gap-4 mb-4">
            <h3 id="chart-title" class="text-xl font-semibold text-white">Vendas</h3>
            <div id="chart-filters" class="flex items-center space-x-1 sm:space-x-2 bg-dark-elevated p-1 rounded-lg">
                <button data-period="today" class="filter-btn px-3 py-1 text-sm font-semibold rounded-md text-white shadow" style="background-color: <?php echo htmlspecialchars($cor_primaria_dashboard); ?>;">Hoje</button>
                <button data-period="yesterday" class="filter-btn px-3 py-1 text-sm font-semibold rounded-md text-gray-400 hover:bg-dark-card">Ontem</button>
                <button data-period="7days" class="filter-btn px-3 py-1 text-sm font-semibold rounded-md text-gray-400 hover:bg-dark-card">7 dias</button>
                <button data-period="month" class="filter-btn px-3 py-1 text-sm font-semibold rounded-md text-gray-400 hover:bg-dark-card">Mês</button>
                <button data-period="year" class="filter-btn px-3 py-1 text-sm font-semibold rounded-md text-gray-400 hover:bg-dark-card">Ano</button>
                <button data-period="all" class="filter-btn px-3 py-1 text-sm font-semibold rounded-md text-gray-400 hover:bg-dark-card">Total</button>
            </div>
        </div>
        <div class="h-80">
            <canvas id="salesChart"></canvas>
        </div>
    </div>


    <!-- Nova seção para Taxa de Conversão e Vendas por Método de Pagamento -->
    <div class="bg-dark-card p-6 rounded-lg shadow-md border mt-6" style="border-color: <?php echo htmlspecialchars($cor_primaria_dashboard); ?>;">
        <h2 class="text-xl font-semibold text-white mb-4">Métricas de Pagamento</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
            <div class="group relative bg-dark-elevated p-4 rounded-lg shadow-md transition-all duration-300 ease-in-out transform hover:scale-105">
                <!-- Subtle Neon Overlay -->
                <div class="absolute inset-0 rounded-lg opacity-0 group-hover:opacity-30 pointer-events-none z-0 bg-[size:200%_100%] bg-[position:0%_0%] group-hover:bg-[position:100%_0%] transition-all duration-500 ease-in-out" style="background: linear-gradient(to left, <?php echo htmlspecialchars($cor_primaria_dashboard); ?>, <?php echo htmlspecialchars($cor_primaria_hover_dashboard); ?>);"></div>
                
                <!-- Card Content -->
                <div class="relative z-10">
                    <h3 class="flex items-center text-gray-400 text-sm font-medium mb-2">
                        <i data-lucide="percent" class="w-5 h-5 mr-2" style="color: <?php echo htmlspecialchars($cor_primaria_dashboard); ?>;"></i>
                        Taxa de Conversão Geral
                    </h3>
                    <p id="taxa-conversao-geral" class="text-2xl font-bold text-white">0%</p>
                </div>
            </div>
            <div class="group relative bg-dark-elevated p-4 rounded-lg shadow-md transition-all duration-300 ease-in-out transform hover:scale-105">
                <!-- Subtle Neon Overlay -->
                <div class="absolute inset-0 rounded-lg opacity-0 group-hover:opacity-30 pointer-events-none z-0 bg-[size:200%_100%] bg-[position:0%_0%] group-hover:bg-[position:100%_0%] transition-all duration-500 ease-in-out" style="background: linear-gradient(to left, <?php echo htmlspecialchars($cor_primaria_dashboard); ?>, <?php echo htmlspecialchars($cor_primaria_hover_dashboard); ?>);"></div>
                
                <!-- Card Content -->
                <div class="relative z-10">
                    <h3 class="flex items-center text-gray-400 text-sm font-medium mb-2">
                        <i data-lucide="qr-code" class="w-5 h-5 mr-2" style="color: <?php echo htmlspecialchars($cor_primaria_dashboard); ?>;"></i>
                        Vendas Pix
                    </h3>
                    <p class="text-xl font-bold text-white"><span id="pix-vendas-valor">R$ 0,00</span></p>
                    <p class="text-sm text-gray-400">Conversão: <span id="pix-vendas-percentual">0%</span></p>
                </div>
            </div>
            <div class="group relative bg-dark-elevated p-4 rounded-lg shadow-md transition-all duration-300 ease-in-out transform hover:scale-105">
                <!-- Subtle Neon Overlay -->
                <div class="absolute inset-0 rounded-lg opacity-0 group-hover:opacity-30 pointer-events-none z-0 bg-[size:200%_100%] bg-[position:0%_0%] group-hover:bg-[position:100%_0%] transition-all duration-500 ease-in-out" style="background: linear-gradient(to left, <?php echo htmlspecialchars($cor_primaria_dashboard); ?>, <?php echo htmlspecialchars($cor_primaria_hover_dashboard); ?>);"></div>
                
                <!-- Card Content -->
                <div class="relative z-10">
                    <h3 class="flex items-center text-gray-400 text-sm font-medium mb-2">
                        <i data-lucide="receipt" class="w-5 h-5 mr-2" style="color: <?php echo htmlspecialchars($cor_primaria_dashboard); ?>;"></i>
                        Vendas Boleto
                    </h3>
                    <p class="text-xl font-bold text-white"><span id="boleto-vendas-valor">R$ 0,00</span></p>
                    <p class="text-sm text-gray-400">Conversão: <span id="boleto-vendas-percentual">0%</span></p>
                </div>
            </div>
            <div class="group relative bg-dark-elevated p-4 rounded-lg shadow-md transition-all duration-300 ease-in-out transform hover:scale-105">
                <!-- Subtle Neon Overlay -->
                <div class="absolute inset-0 rounded-lg opacity-0 group-hover:opacity-30 pointer-events-none z-0 bg-[size:200%_100%] bg-[position:0%_0%] group-hover:bg-[position:100%_0%] transition-all duration-500 ease-in-out" style="background: linear-gradient(to left, <?php echo htmlspecialchars($cor_primaria_dashboard); ?>, <?php echo htmlspecialchars($cor_primaria_hover_dashboard); ?>);"></div>
                
                <!-- Card Content -->
                <div class="relative z-10">
                    <h3 class="flex items-center text-gray-400 text-sm font-medium mb-2">
                        <i data-lucide="credit-card" class="w-5 h-5 mr-2" style="color: <?php echo htmlspecialchars($cor_primaria_dashboard); ?>;"></i>
                        Vendas Cartão de Crédito
                    </h3>
                    <p class="text-xl font-bold text-white"><span id="cartao-vendas-valor">R$ 0,00</span></p>
                    <p class="text-sm text-gray-400">Conversão: <span id="cartao-vendas-percentual">0%</span></p>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    let salesChartInstance = null;
    const ctx = document.getElementById('salesChart').getContext('2d');
    const chartTitle = document.getElementById('chart-title');
    let currentPeriod = 'today'; // Store the current selected period
    let lastKnownWidth = window.innerWidth; // To detect breakpoint crossing

    // Mapeamento das etapas da jornada Starfy
    // COMENTADO - Não será usado por enquanto
    /*
    const STARFY_STAGES = [
        { name: 'ESTRELA CADENTE', min: 0, max: 10000, description: 'Toda grande jornada começa com um vislumbre. Você acendeu a sua luz no universo Starfy. É o ponto de partida de uma trajetória que promete ser épica.', color: 'from-purple-800 via-indigo-900 to-black' },
        { name: 'NEBULOSA ASCENDENTE', min: 10000, max: 100000, description: 'Uma formação se inicia, cores vibrantes e um brilho crescente. Você está moldando seu espaço na galáxia Starfy, com potencial para se expandir e impressionar.', color: 'from-indigo-900 via-blue-950 to-gray-900' },
        { name: 'SISTEMA SOLAR', min: 100000, max: 250000, description: 'Você estabeleceu um centro de gravidade, atraindo e energizando. Seu sistema está em equilíbrio, mostrando que a sua órbita é consistente e promissora.', color: 'from-blue-800 via-sky-950 to-slate-900' },
        { name: 'AGLOMERADO ESTELAR', min: 250000, max: 500000, description: 'Seu brilho não está sozinho; você se une a outros astros, formando uma força coesa. Sua presença se torna notável, um grupo de estrelas que ecoam no universo.', color: 'from-cyan-700 via-teal-900 to-zinc-950' },
        { name: 'SUPERNOVA DOURADA', min: 500000, max: 1000000, description: 'Um evento cósmico de proporções grandiosas! Sua energia explode, iluminando vastas extensões. Você é uma força que redefine o seu entorno, uma referência inconfundível.', color: 'from-yellow-400 via-amber-600 to-orange-700' },
        { name: 'GALÁXIA SUPREMA', min: 1000000, max: 5000000, description: 'Você se tornou um universo em si, com trilhões de estrelas e uma complexidade que inspira. Uma galáxia construída com visão, resiliência e paixão, reconhecida por sua vastidão.', color: 'from-orange-400 via-red-600 to-rose-700' },
        { name: 'CONSTELAÇÃO ETERNA', min: 5000000, max: 10000000, description: 'Sua luz transcende o tempo, formando um padrão que será lembrado por gerações. Um legado gravado no firmamento, um marco de excelência e inspiração.', color: 'from-pink-400 via-fuchsia-600 to-purple-700' },
        { name: 'BURACO NEGRO DE SUCESSO', min: 10000000, max: Infinity, description: 'O ápice, onde a gravidade do seu sucesso é irresistível! Você não apenas atravessou o universo, você se tornou a força dominante que atrai e molda tudo ao seu redor. Esta é a mais alta honra da Starfy, reservada aos que redefiniram os limites.', color: 'from-gray-700 via-gray-800 to-black' }
    ];

    function formatCurrency(value) {
        return parseFloat(value).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
    }

    function renderStarfyJourney(totalFaturamentoLifetime) {
        let currentStage = null;
        let nextStage = null;
        let progressPercent = 0;
        let currentAmountInStage = 0;
        let amountNeededForStage = 0; 

        for (let i = 0; i < STARFY_STAGES.length; i++) {
            if (totalFaturamentoLifetime >= STARFY_STAGES[i].min && totalFaturamentoLifetime < STARFY_STAGES[i].max) {
                currentStage = STARFY_STAGES[i];
                nextStage = STARFY_STAGES[i + 1] || null; 
                break;
            }
        }
        
        // Handle cases where lifetime revenue is below the first stage or at the last stage
        if (!currentStage && totalFaturamentoLifetime >= STARFY_STAGES[STARFY_STAGES.length - 1].min) {
            currentStage = STARFY_STAGES[STARFY_STAGES.length - 1]; // Last stage
            nextStage = null; 
        } else if (!currentStage) { 
             currentStage = STARFY_STAGES[0]; // Default to first stage if below its min
             nextStage = STARFY_STAGES[1];
        }


        if (currentStage) {
            document.getElementById('journey-stage-name').textContent = currentStage.name;
            document.getElementById('journey-stage-description').textContent = currentStage.description;
            
            const journeyContainer = document.querySelector('.bg-gradient-to-br');
            // Remove as classes de cor existentes e adiciona a nova do estágio atual
            // Filtra as classes para não remover 'bg-gradient-to-br' e as cores do background (opacity-10)
            const currentClasses = Array.from(journeyContainer.classList);
            const classesToRemove = currentClasses.filter(cls => cls.startsWith('from-') || cls.startsWith('via-') || cls.startsWith('to-'));
            journeyContainer.classList.remove(...classesToRemove);
            journeyContainer.classList.add(...currentStage.color.split(' '));


            if (currentStage.max === Infinity) { 
                document.getElementById('journey-current-amount').textContent = formatCurrency(totalFaturamentoLifetime);
                document.getElementById('journey-next-amount').textContent = 'Conquista Máxima!';
                document.getElementById('journey-progress-bar-fill').style.width = '100%';
                document.getElementById('journey-progress-text').textContent = '100%';
                document.getElementById('journey-next-stage-message').textContent = 'Você alcançou o ápice da Jornada Starfy!';
            } else {
                currentAmountInStage = totalFaturamentoLifetime - currentStage.min;
                amountNeededForStage = currentStage.max - currentStage.min;
                
                if (amountNeededForStage > 0) {
                    progressPercent = Math.min(100, (currentAmountInStage / amountNeededForStage) * 100);
                } else {
                    progressPercent = 100; 
                }
                
                document.getElementById('journey-current-amount').textContent = formatCurrency(totalFaturamentoLifetime);
                document.getElementById('journey-next-amount').textContent = formatCurrency(currentStage.max);
                document.getElementById('journey-progress-bar-fill').style.width = `${progressPercent.toFixed(2)}%`;
                document.getElementById('journey-progress-text').textContent = `${progressPercent.toFixed(0)}%`;
                
                const remaining = currentStage.max - totalFaturamentoLifetime;
                document.getElementById('journey-next-stage-message').textContent = `Faltam ${formatCurrency(remaining)} para a próxima etapa!`;
            }
        }
    }
    */


    function updateKpiAndChart(period = 'today') {
        currentPeriod = period; // Update currentPeriod when function is called
        fetch(`/api/api.php?action=get_dashboard_data&period=${period}`)
            .then(response => {
                if (!response.ok) {
                    throw new Error('A resposta da rede não foi OK');
                }
                return response.json();
            })
            .then(data => {
                // Atualiza os KPIs
                document.getElementById('vendas-totais').innerText = `R$ ${data.kpis.vendas_totais}`;
                document.getElementById('quantidade-vendas').innerText = data.kpis.quantidade_vendas;
                document.getElementById('ticket-medio').innerText = `R$ ${data.kpis.ticket_medio}`;
                document.getElementById('total-produtos').innerText = data.kpis.total_produtos;
                
                // Atualiza os novos KPIs
                document.getElementById('vendas-pendentes-valor').innerText = `R$ ${data.kpis.vendas_pendentes_valor}`; // NOVO
                document.getElementById('vendas-pendentes-quantidade').innerText = data.kpis.vendas_pendentes_quantidade; // NOVO
                document.getElementById('abandono-carrinho').innerText = data.kpis.abandono_carrinho;
                document.getElementById('reembolsos').innerText = `R$ ${data.kpis.reembolsos}`;
                document.getElementById('chargebacks').innerText = `R$ ${data.kpis.chargebacks}`;

                // Atualiza os novos KPIs de pagamento e conversão
                document.getElementById('taxa-conversao-geral').innerText = data.kpis.taxa_conversao_geral;
                document.getElementById('pix-vendas-valor').innerText = `R$ ${data.kpis.pix_vendas_valor}`;
                document.getElementById('pix-vendas-percentual').innerText = data.kpis.pix_vendas_percentual;
                document.getElementById('boleto-vendas-valor').innerText = `R$ ${data.kpis.boleto_vendas_valor}`;
                document.getElementById('boleto-vendas-percentual').innerText = data.kpis.boleto_vendas_percentual;
                document.getElementById('cartao-vendas-valor').innerText = `R$ ${data.kpis.cartao_vendas_valor}`;
                document.getElementById('cartao-vendas-percentual').innerText = data.kpis.cartao_vendas_percentual;

                // Renderiza a Jornada Starfy com o faturamento lifetime
                // COMENTADO - Não será usado por enquanto
                // renderStarfyJourney(data.kpis.total_faturamento_lifetime);


                // Atualiza o título do gráfico
                const activeButton = document.querySelector(`#chart-filters button[data-period='${period}']`);
                let chartGranularity = '';
                // REMOVIDO: A linha abaixo foi removida conforme solicitado
                // if (period === 'today' || period === 'yesterday') { chartGranularity = ' (por Hora)'; }
                chartTitle.textContent = `Vendas (${activeButton.textContent})${chartGranularity}`;

                const isHourlyPeriod = (period === 'today' || period === 'yesterday');
                const isMobile = window.innerWidth < 768; // Tailwind's 'md' breakpoint is 768px

                const chartConfig = {
                    type: 'line',
                    data: {
                        labels: data.chart.labels,
                        datasets: [{
                            label: 'Vendas',
                            backgroundColor: 'rgba(var(--accent-primary-rgb, 50, 231, 104), 0.2)',
                            borderColor: getComputedStyle(document.documentElement).getPropertyValue('--accent-primary').trim() || '#32e768',
                            borderWidth: 2,
                            tension: 0.4,
                            fill: true,
                            pointBackgroundColor: '#f97316',
                            pointBorderColor: '#fff',
                            pointHoverBackgroundColor: '#fff',
                            pointHoverBorderColor: '#f97316',
                            data: data.chart.data,
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            x: {
                                ticks: {
                                    autoSkip: isHourlyPeriod ? isMobile : true, // false for desktop hourly, true otherwise
                                    maxTicksLimit: isHourlyPeriod ? (isMobile ? 8 : undefined) : 12, // 8 for mobile hourly, 12 for others, undefined for desktop hourly
                                    maxRotation: 0, // Mantém os rótulos horizontais
                                    minRotation: 0, // Mantém os rótulos horizontais
                                    callback: function(value, index, values) {
                                        return this.getLabelForValue(value); 
                                    }
                                }
                            },
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    callback: function(value) {
                                        return 'R$ ' + value.toLocaleString('pt-BR');
                                    }
                                }
                            }
                        },
                        plugins: {
                            legend: {
                                display: false
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        let label = context.dataset.label || '';
                                        if (label) { label += ': '; }
                                        if (context.parsed.y !== null) {
                                            label += new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(context.parsed.y);
                                        }
                                        return label;
                                    }
                                }
                            }
                        }
                    }
                };

                if (salesChartInstance) {
                    salesChartInstance.data.labels = data.chart.labels;
                    salesChartInstance.data.datasets[0].data = data.chart.data;
                    
                    // Update tick options directly on existing instance
                    salesChartInstance.options.scales.x.ticks.autoSkip = isHourlyPeriod ? isMobile : true;
                    salesChartInstance.options.scales.x.ticks.maxTicksLimit = isHourlyPeriod ? (isMobile ? 8 : undefined) : 12;
                    salesChartInstance.options.scales.x.ticks.maxRotation = 0;
                    salesChartInstance.options.scales.x.ticks.minRotation = 0;
                    
                    salesChartInstance.update();
                } else {
                    salesChartInstance = new Chart(ctx, chartConfig);
                }
            })
            .catch(error => console.error(`Erro ao buscar dados para o período "${period}":`, error));
    }

    // Event listener para os botões de filtro usando delegação de evento
    const filterButtonsContainer = document.getElementById('chart-filters');
    filterButtonsContainer.addEventListener('click', function(e) {
        if (e.target.tagName === 'BUTTON') {
            // Remove o estilo ativo de todos os botões
            filterButtonsContainer.querySelectorAll('button').forEach(btn => {
                btn.classList.remove('text-white', 'shadow');
                btn.style.backgroundColor = '';
                btn.classList.add('text-gray-400', 'hover:bg-dark-card');
            });
            // Adiciona o estilo ativo ao botão clicado
            e.target.style.backgroundColor = getComputedStyle(document.documentElement).getPropertyValue('--accent-primary').trim() || '#32e768';
            e.target.classList.add('text-white', 'shadow');
            e.target.classList.remove('text-gray-600', 'hover:bg-gray-200');

            const period = e.target.dataset.period;
            updateKpiAndChart(period);
        }
    });

    // Resize listener to re-render chart on breakpoint crossing
    window.addEventListener('resize', () => {
        const newWidth = window.innerWidth;
        const breakpoint = 768; // Corresponds to Tailwind's 'md' breakpoint

        // Check if we crossed the breakpoint
        if ((lastKnownWidth < breakpoint && newWidth >= breakpoint) || (lastKnownWidth >= breakpoint && newWidth < breakpoint)) {
            console.log("Breakpoint crossed, updating chart for period:", currentPeriod);
            updateKpiAndChart(currentPeriod);
        }
        lastKnownWidth = newWidth;
    });

    // Carga inicial com o período 'hoje'
    updateKpiAndChart('today');
});
</script>