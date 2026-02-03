<?php
// Este arquivo é incluído dentro de admin.php

// Incluir helper de segurança para funções CSRF
require_once __DIR__ . '/../../helpers/security_helper.php';

// Gerar token CSRF para uso em requisições JavaScript
$csrf_token_js = generate_csrf_token();
?>

<meta name="csrf-token" content="<?php echo htmlspecialchars($csrf_token_js); ?>">
<script>
    // Variável global para token CSRF
    window.csrfToken = '<?php echo htmlspecialchars($csrf_token_js); ?>';
</script>

<div class="flex items-center justify-between mb-6">
    <div>
        <h1 class="text-3xl font-bold text-white">Banner do Dashboard</h1>
        <p class="text-gray-400 mt-1">Configure o banner exibido abaixo do header no dashboard dos infoprodutores.</p>
    </div>
    <a href="/admin?pagina=admin_dashboard" class="bg-dark-elevated text-gray-300 font-bold py-2 px-4 rounded-lg hover:bg-dark-card transition duration-300 flex items-center space-x-2 border border-dark-border">
        <i data-lucide="arrow-left" class="w-5 h-5"></i>
        <span>Voltar ao Dashboard</span>
    </a>
</div>

<div id="status-message" class="hidden px-4 py-3 rounded relative mb-4" role="alert"></div>

<!-- Seção: Tipo de Banner -->
<div class="bg-dark-card p-8 rounded-lg shadow-md mb-6 border" style="border-color: var(--accent-primary);">
    <h2 class="text-2xl font-semibold mb-6 text-white flex items-center gap-2">
        <i data-lucide="image" class="w-6 h-6" style="color: var(--accent-primary);"></i>
        <span>Tipo de Banner</span>
    </h2>
    <p class="text-gray-400 mb-6">Escolha se deseja exibir um banner único ou um carrossel com múltiplos banners.</p>
    
    <div class="flex flex-col md:flex-row gap-4">
        <label class="flex items-center p-4 bg-dark-elevated border-2 border-dark-border rounded-lg cursor-pointer transition-colors flex-1" onmouseover="this.style.borderColor='var(--accent-primary)'" onmouseout="this.style.borderColor=''">
            <input type="radio" name="banner_type" value="single" id="banner_type_single" class="mr-3 w-5 h-5" style="color: var(--accent-primary);" onfocus="this.style.outline='2px solid var(--accent-primary)'; this.style.outlineOffset='2px';" onblur="this.style.outline='';">
            <div>
                <div class="font-semibold text-white">Banner Único</div>
                <div class="text-sm text-gray-400">Exibe apenas um banner fixo</div>
            </div>
        </label>
        <label class="flex items-center p-4 bg-dark-elevated border-2 border-dark-border rounded-lg cursor-pointer transition-colors flex-1" onmouseover="this.style.borderColor='var(--accent-primary)'" onmouseout="this.style.borderColor=''">
            <input type="radio" name="banner_type" value="carousel" id="banner_type_carousel" class="mr-3 w-5 h-5" style="color: var(--accent-primary);" onfocus="this.style.outline='2px solid var(--accent-primary)'; this.style.outlineOffset='2px';" onblur="this.style.outline='';">
            <div>
                <div class="font-semibold text-white">Carrossel</div>
                <div class="text-sm text-gray-400">Exibe múltiplos banners em sequência</div>
            </div>
        </label>
    </div>
</div>

<!-- Seção: Informações de Tamanho -->
<div class="bg-blue-900/20 border border-blue-500/30 p-4 rounded-lg mb-6">
    <div class="flex items-start gap-3">
        <i data-lucide="info" class="w-5 h-5 text-blue-400 flex-shrink-0 mt-0.5"></i>
        <div>
            <h3 class="font-semibold text-blue-300 mb-1">Tamanho Recomendado</h3>
            <p class="text-sm text-blue-200">Para melhor visualização, recomendamos banners com dimensões de <strong>1200x200px</strong> (proporção 6:1).</p>
            <p class="text-xs text-blue-300 mt-2">Formatos aceitos: JPG, PNG, WEBP. Tamanho máximo: 2MB por imagem.</p>
        </div>
    </div>
</div>

<!-- Seção: Banner Único -->
<div id="single-banner-section" class="bg-dark-card p-8 rounded-lg shadow-md mb-6 border border-dark-border hidden">
    <h2 class="text-2xl font-semibold mb-6 text-white flex items-center gap-2">
        <i data-lucide="image" class="w-6 h-6" style="color: var(--accent-primary);"></i>
        <span>Banner Único</span>
    </h2>
    
    <div class="flex flex-col md:flex-row items-start md:items-center gap-6">
        <div class="flex-1">
            <label for="single_banner_file" class="block text-gray-300 text-sm font-semibold mb-2">Upload do Banner</label>
            <div class="relative">
                <input type="file" id="single_banner_file" name="single_banner_file" accept="image/jpeg,image/png,image/webp" 
                       class="hidden">
                <label for="single_banner_file" class="cursor-pointer inline-flex items-center justify-center px-6 py-3 bg-dark-elevated border border-dark-border rounded-lg text-white hover:bg-dark-card transition">
                    <i data-lucide="upload" class="w-5 h-5 mr-2"></i>
                    <span>Selecionar Arquivo</span>
                </label>
                <span id="single_banner_filename" class="ml-4 text-gray-400 text-sm"></span>
            </div>
        </div>
        <div class="flex flex-col gap-2">
            <div class="w-full max-w-md rounded-lg border-2 border-dark-border bg-dark-elevated flex items-center justify-center overflow-hidden" id="single-banner-preview" style="min-height: 200px;">
                <img id="single-banner-preview-img" src="" alt="Banner Preview" class="max-w-full max-h-full object-contain hidden">
                <i data-lucide="image" class="w-12 h-12 text-gray-500" id="single-banner-placeholder"></i>
            </div>
            <button type="button" id="upload-single-banner-btn" class="text-white font-bold py-2 px-4 rounded-lg transition duration-300 disabled:opacity-50 disabled:cursor-not-allowed" style="background-color: var(--accent-primary);" onmouseover="if(!this.disabled) this.style.backgroundColor='var(--accent-primary-hover)'" onmouseout="if(!this.disabled) this.style.backgroundColor='var(--accent-primary)'" disabled>
                Enviar Banner
            </button>
            <button type="button" id="remove-single-banner-btn" class="text-red-400 font-bold py-2 px-4 rounded-lg transition duration-300 hover:bg-red-900/20 border border-red-500/30 hidden">
                Remover Banner
            </button>
        </div>
    </div>
</div>

<!-- Seção: Carrossel -->
<div id="carousel-banner-section" class="bg-dark-card p-8 rounded-lg shadow-md mb-6 border border-dark-border hidden">
    <h2 class="text-2xl font-semibold mb-6 text-white flex items-center gap-2">
        <i data-lucide="images" class="w-6 h-6" style="color: var(--accent-primary);"></i>
        <span>Banners do Carrossel</span>
    </h2>
    
    <div class="mb-6">
        <label for="carousel_banners_file" class="block text-gray-300 text-sm font-semibold mb-2">Upload de Múltiplos Banners</label>
        <div class="relative">
            <input type="file" id="carousel_banners_file" name="carousel_banners_file" accept="image/jpeg,image/png,image/webp" 
                   multiple class="hidden">
            <label for="carousel_banners_file" class="cursor-pointer inline-flex items-center justify-center px-6 py-3 bg-dark-elevated border border-dark-border rounded-lg text-white hover:bg-dark-card transition">
                <i data-lucide="upload" class="w-5 h-5 mr-2"></i>
                <span>Selecionar Múltiplos Arquivos</span>
            </label>
        </div>
        <p class="text-xs text-gray-400 mt-2">Você pode selecionar vários arquivos de uma vez (Ctrl+Click ou Cmd+Click)</p>
    </div>
    
    <!-- Lista de Banners do Carrossel -->
    <div id="carousel-banners-list" class="space-y-4">
        <p class="text-gray-400 text-center py-8" id="carousel-empty-message">Nenhum banner adicionado ainda. Faça upload de banners acima.</p>
    </div>
</div>

<!-- Seção: Configurações do Carrossel -->
<div id="carousel-settings-section" class="bg-dark-card p-8 rounded-lg shadow-md mb-6 border border-dark-border hidden">
    <h2 class="text-2xl font-semibold mb-6 text-white flex items-center gap-2">
        <i data-lucide="settings" class="w-6 h-6" style="color: var(--accent-primary);"></i>
        <span>Configurações do Carrossel</span>
    </h2>
    
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <div>
            <label for="carousel_autoplay" class="flex items-center gap-2 cursor-pointer">
                <input type="checkbox" id="carousel_autoplay" class="w-5 h-5 rounded" style="accent-color: var(--accent-primary);" onfocus="this.style.outline='2px solid var(--accent-primary)'; this.style.outlineOffset='2px';" onblur="this.style.outline='';">
                <span class="text-white font-medium">Reprodução Automática</span>
            </label>
            <p class="text-xs text-gray-400 mt-1 ml-7">O carrossel muda de slide automaticamente</p>
        </div>
        <div>
            <label for="carousel_interval" class="block text-gray-300 text-sm font-semibold mb-2">Intervalo entre Slides (segundos)</label>
            <input type="number" id="carousel_interval" min="2" max="30" value="5" 
                   class="w-full px-4 py-3 bg-dark-elevated border border-dark-border rounded-lg text-white focus:outline-none" style="focus:ring-2px solid var(--accent-primary);" onfocus="this.style.borderColor='var(--accent-primary)'; this.style.boxShadow='0 0 0 2px var(--accent-primary)';" onblur="this.style.borderColor=''; this.style.boxShadow='';">
        </div>
    </div>
</div>

<!-- Seção: Ativar/Desativar -->
<div class="bg-dark-card p-8 rounded-lg shadow-md mb-6 border border-dark-border">
    <h2 class="text-2xl font-semibold mb-6 text-white flex items-center gap-2">
        <i data-lucide="power" class="w-6 h-6" style="color: var(--accent-primary);"></i>
        <span>Status do Banner</span>
    </h2>
    
    <div class="flex items-center gap-3">
        <input type="checkbox" id="banner_enabled" class="w-6 h-6 rounded" style="accent-color: var(--accent-primary);" onfocus="this.style.outline='2px solid var(--accent-primary)'; this.style.outlineOffset='2px';" onblur="this.style.outline='';">
        <label for="banner_enabled" class="text-white font-medium cursor-pointer">Ativar banner no dashboard</label>
    </div>
    <p class="text-xs text-gray-400 mt-2 ml-9">Quando desativado, o banner não será exibido no dashboard dos infoprodutores.</p>
</div>

<!-- Botão Salvar -->
<div class="flex justify-end">
    <button type="button" id="save-banner-config-btn" class="text-white font-bold py-3 px-8 rounded-lg transition duration-300" style="background-color: var(--accent-primary);" onmouseover="this.style.backgroundColor='var(--accent-primary-hover)'" onmouseout="this.style.backgroundColor='var(--accent-primary)'">
        <i data-lucide="save" class="w-5 h-5 inline-block mr-2"></i>
        Salvar Configurações
    </button>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    lucide.createIcons();
    
    const statusMessage = document.getElementById('status-message');
    const bannerTypeSingle = document.getElementById('banner_type_single');
    const bannerTypeCarousel = document.getElementById('banner_type_carousel');
    const singleBannerSection = document.getElementById('single-banner-section');
    const carouselBannerSection = document.getElementById('carousel-banner-section');
    const carouselSettingsSection = document.getElementById('carousel-settings-section');
    const singleBannerFile = document.getElementById('single_banner_file');
    const singleBannerFilename = document.getElementById('single_banner_filename');
    const singleBannerPreview = document.getElementById('single-banner-preview-img');
    const singleBannerPlaceholder = document.getElementById('single-banner-placeholder');
    const uploadSingleBannerBtn = document.getElementById('upload-single-banner-btn');
    const removeSingleBannerBtn = document.getElementById('remove-single-banner-btn');
    const carouselBannersFile = document.getElementById('carousel_banners_file');
    const carouselBannersList = document.getElementById('carousel-banners-list');
    const carouselEmptyMessage = document.getElementById('carousel-empty-message');
    const carouselAutoplay = document.getElementById('carousel_autoplay');
    const carouselInterval = document.getElementById('carousel_interval');
    const bannerEnabled = document.getElementById('banner_enabled');
    const saveBannerConfigBtn = document.getElementById('save-banner-config-btn');
    
    let currentBanners = [];
    let currentType = 'single';
    let uploadedBanners = [];
    
    function showMessage(message, type = 'success') {
        statusMessage.classList.remove('hidden');
        statusMessage.className = 'px-4 py-3 rounded relative mb-4';
        if (type === 'success') {
            statusMessage.classList.add('bg-green-900/20', 'border', 'border-green-500/30', 'text-green-300');
        } else {
            statusMessage.classList.add('bg-red-900/20', 'border', 'border-red-500/30', 'text-red-300');
        }
        statusMessage.textContent = message;
        statusMessage.scrollIntoView({ behavior: 'smooth', block: 'start' });
        setTimeout(() => {
            statusMessage.classList.add('hidden');
        }, 5000);
    }
    
    // Carregar configurações atuais
    async function loadBannerConfig() {
        try {
            const response = await fetch('/api/admin_api.php?action=get_dashboard_banners');
            const result = await response.json();
            console.log('Configurações carregadas:', result);
            
            if (result.success && result.data) {
                const config = result.data;
                currentType = config.type || 'single';
                currentBanners = config.banners || [];
                
                // Normalizar URLs dos banners (remover barra inicial para armazenamento interno)
                uploadedBanners = currentBanners.map(banner => {
                    return banner.startsWith('/') ? banner.substring(1) : banner;
                });
                
                console.log('Banners carregados:', uploadedBanners);
                
                // Definir tipo de banner
                if (currentType === 'single') {
                    bannerTypeSingle.checked = true;
                    showSingleSection();
                } else {
                    bannerTypeCarousel.checked = true;
                    showCarouselSection();
                }
                
                // Carregar banners
                if (currentType === 'single' && uploadedBanners.length > 0) {
                    displaySingleBanner('/' + uploadedBanners[0]);
                } else if (currentType === 'carousel') {
                    displayCarouselBanners(uploadedBanners);
                }
                
                // Configurações do carrossel
                if (config.autoplay !== undefined) {
                    carouselAutoplay.checked = config.autoplay;
                }
                if (config.interval) {
                    carouselInterval.value = config.interval;
                }
                
                // Status
                bannerEnabled.checked = config.enabled !== false;
            } else {
                console.log('Nenhuma configuração encontrada ou erro na resposta');
            }
        } catch (error) {
            console.error('Erro ao carregar configurações:', error);
        }
    }
    
    function showSingleSection() {
        singleBannerSection.classList.remove('hidden');
        carouselBannerSection.classList.add('hidden');
        carouselSettingsSection.classList.add('hidden');
    }
    
    function showCarouselSection() {
        singleBannerSection.classList.add('hidden');
        carouselBannerSection.classList.remove('hidden');
        carouselSettingsSection.classList.remove('hidden');
    }
    
    function displaySingleBanner(bannerUrl) {
        if (bannerUrl) {
            singleBannerPreview.src = bannerUrl.startsWith('/') ? bannerUrl : '/' + bannerUrl;
            singleBannerPreview.classList.remove('hidden');
            singleBannerPlaceholder.classList.add('hidden');
            removeSingleBannerBtn.classList.remove('hidden');
        } else {
            singleBannerPreview.classList.add('hidden');
            singleBannerPlaceholder.classList.remove('hidden');
            removeSingleBannerBtn.classList.add('hidden');
        }
    }
    
    function displayCarouselBanners(banners) {
        if (banners.length === 0) {
            carouselEmptyMessage.classList.remove('hidden');
            return;
        }
        
        carouselEmptyMessage.classList.add('hidden');
        carouselBannersList.innerHTML = '';
        
        banners.forEach((banner, index) => {
            const bannerUrl = banner.startsWith('/') ? banner : '/' + banner;
            const bannerItem = document.createElement('div');
            bannerItem.className = 'flex items-center gap-4 p-4 bg-dark-elevated rounded-lg border border-dark-border';
            bannerItem.dataset.index = index;
            bannerItem.dataset.banner = banner;
            
            bannerItem.innerHTML = `
                <div class="flex-shrink-0">
                    <img src="${bannerUrl}?t=${Date.now()}" alt="Banner ${index + 1}" class="w-32 h-20 object-cover rounded border border-dark-border">
                </div>
                <div class="flex-1">
                    <div class="text-white font-medium">Banner ${index + 1}</div>
                    <div class="text-xs text-gray-400">${banner.split('/').pop()}</div>
                </div>
                <div class="flex items-center gap-2">
                    <button type="button" class="move-banner-up p-2 text-gray-400 hover:text-white transition-colors ${index === 0 ? 'opacity-50 cursor-not-allowed' : ''}" ${index === 0 ? 'disabled' : ''} title="Mover para cima">
                        <i data-lucide="arrow-up" class="w-4 h-4"></i>
                    </button>
                    <button type="button" class="move-banner-down p-2 text-gray-400 hover:text-white transition-colors ${index === banners.length - 1 ? 'opacity-50 cursor-not-allowed' : ''}" ${index === banners.length - 1 ? 'disabled' : ''} title="Mover para baixo">
                        <i data-lucide="arrow-down" class="w-4 h-4"></i>
                    </button>
                    <button type="button" class="remove-carousel-banner p-2 text-red-400 hover:text-red-300 transition-colors" title="Remover">
                        <i data-lucide="trash-2" class="w-4 h-4"></i>
                    </button>
                </div>
            `;
            
            carouselBannersList.appendChild(bannerItem);
        });
        
        lucide.createIcons();
        
        // Event listeners para mover e remover
        carouselBannersList.querySelectorAll('.move-banner-up').forEach(btn => {
            btn.addEventListener('click', function() {
                const item = this.closest('[data-index]');
                const index = parseInt(item.dataset.index);
                if (index > 0) {
                    [uploadedBanners[index], uploadedBanners[index - 1]] = [uploadedBanners[index - 1], uploadedBanners[index]];
                    displayCarouselBanners(uploadedBanners);
                }
            });
        });
        
        carouselBannersList.querySelectorAll('.move-banner-down').forEach(btn => {
            btn.addEventListener('click', function() {
                const item = this.closest('[data-index]');
                const index = parseInt(item.dataset.index);
                if (index < uploadedBanners.length - 1) {
                    [uploadedBanners[index], uploadedBanners[index + 1]] = [uploadedBanners[index + 1], uploadedBanners[index]];
                    displayCarouselBanners(uploadedBanners);
                }
            });
        });
        
        carouselBannersList.querySelectorAll('.remove-carousel-banner').forEach(btn => {
            btn.addEventListener('click', function() {
                const item = this.closest('[data-index]');
                const index = parseInt(item.dataset.index);
                uploadedBanners.splice(index, 1);
                displayCarouselBanners(uploadedBanners);
            });
        });
    }
    
    // Mudança de tipo de banner
    bannerTypeSingle.addEventListener('change', function() {
        if (this.checked) {
            currentType = 'single';
            showSingleSection();
            if (uploadedBanners.length > 0) {
                displaySingleBanner(uploadedBanners[0]);
            }
        }
    });
    
    bannerTypeCarousel.addEventListener('change', function() {
        if (this.checked) {
            currentType = 'carousel';
            showCarouselSection();
            displayCarouselBanners(uploadedBanners);
        }
    });
    
    // Preview banner único
    singleBannerFile.addEventListener('change', function() {
        if (this.files && this.files[0]) {
            singleBannerFilename.textContent = this.files[0].name;
            uploadSingleBannerBtn.disabled = false;
            
            const reader = new FileReader();
            reader.onload = function(e) {
                singleBannerPreview.src = e.target.result;
                singleBannerPreview.classList.remove('hidden');
                singleBannerPlaceholder.classList.add('hidden');
            };
            reader.readAsDataURL(this.files[0]);
        }
    });
    
    // Upload banner único
    uploadSingleBannerBtn.addEventListener('click', async function() {
        if (!singleBannerFile.files || !singleBannerFile.files[0]) {
            showMessage('Selecione um arquivo primeiro', 'error');
            return;
        }
        
        const formData = new FormData();
        formData.append('banner', singleBannerFile.files[0]);
        formData.append('type', 'single');
        formData.append('csrf_token', window.csrfToken || '');
        
        uploadSingleBannerBtn.disabled = true;
        uploadSingleBannerBtn.innerHTML = '<i data-lucide="loader-2" class="w-5 h-5 inline-block mr-2 animate-spin"></i> Enviando...';
        lucide.createIcons();
        
        try {
            console.log('Enviando banner único...', singleBannerFile.files[0].name);
            const response = await fetch('/api/admin_api.php?action=upload_dashboard_banner', {
                method: 'POST',
                headers: {
                    'X-CSRF-Token': window.csrfToken || ''
                },
                body: formData
            });
            const result = await response.json();
            console.log('Resposta do servidor:', result);
            
            if (result.success) {
                // Garantir que a URL não tenha barra inicial duplicada
                let bannerUrl = result.url.startsWith('/') ? result.url.substring(1) : result.url;
                uploadedBanners = [bannerUrl];
                displaySingleBanner('/' + bannerUrl);
                showMessage('Banner enviado com sucesso!', 'success');
                singleBannerFile.value = '';
                singleBannerFilename.textContent = '';
                console.log('Banner adicionado. Lista atual:', uploadedBanners);
            } else {
                console.error('Erro no upload:', result);
                showMessage('Erro ao enviar banner: ' + (result.error || 'Erro desconhecido'), 'error');
            }
        } catch (error) {
            console.error('Erro:', error);
            showMessage('Erro de comunicação com o servidor: ' + error.message, 'error');
        } finally {
            uploadSingleBannerBtn.disabled = false;
            uploadSingleBannerBtn.innerHTML = '<i data-lucide="upload" class="w-5 h-5 inline-block mr-2"></i> Enviar Banner';
            lucide.createIcons();
        }
    });
    
    // Remover banner único
    removeSingleBannerBtn.addEventListener('click', async function() {
        if (uploadedBanners.length === 0) return;
        
        if (!confirm('Tem certeza que deseja remover este banner?')) return;
        
        try {
            const response = await fetch('/api/admin_api.php?action=delete_dashboard_banner', {
                method: 'POST',
                headers: { 
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': window.csrfToken || ''
                },
                body: JSON.stringify({ 
                    banner_url: uploadedBanners[0],
                    csrf_token: window.csrfToken || ''
                })
            });
            const result = await response.json();
            
            if (result.success) {
                uploadedBanners = [];
                displaySingleBanner(null);
                showMessage('Banner removido com sucesso!', 'success');
            } else {
                showMessage('Erro ao remover banner: ' + (result.error || 'Erro desconhecido'), 'error');
            }
        } catch (error) {
            console.error('Erro:', error);
            showMessage('Erro de comunicação com o servidor', 'error');
        }
    });
    
    // Upload múltiplos banners (carrossel)
    carouselBannersFile.addEventListener('change', async function() {
        if (!this.files || this.files.length === 0) return;
        
        const files = Array.from(this.files);
        const maxSize = 2 * 1024 * 1024; // 2MB
        const allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
        
        // Validar arquivos
        for (let file of files) {
            if (!allowedTypes.includes(file.type)) {
                showMessage(`Arquivo "${file.name}" não é um formato válido. Use JPG, PNG ou WEBP.`, 'error');
                return;
            }
            if (file.size > maxSize) {
                showMessage(`Arquivo "${file.name}" é muito grande. Máximo 2MB.`, 'error');
                return;
            }
        }
        
        // Upload de cada arquivo
        const uploadPromises = files.map(file => {
            const formData = new FormData();
            formData.append('banner', file);
            formData.append('type', 'carousel');
            formData.append('csrf_token', window.csrfToken || '');
            
            return fetch('/api/admin_api.php?action=upload_dashboard_banner', {
                method: 'POST',
                headers: {
                    'X-CSRF-Token': window.csrfToken || ''
                },
                body: formData
            }).then(res => res.json());
        });
        
        try {
            const results = await Promise.all(uploadPromises);
            const successful = results.filter(r => r.success);
            
            if (successful.length > 0) {
                // Garantir que as URLs não tenham barra inicial duplicada
                const newUrls = successful.map(r => {
                    return r.url.startsWith('/') ? r.url.substring(1) : r.url;
                });
                uploadedBanners = [...uploadedBanners, ...newUrls];
                displayCarouselBanners(uploadedBanners);
                showMessage(`${successful.length} banner(s) enviado(s) com sucesso!`, 'success');
                console.log('Banners adicionados. Lista atual:', uploadedBanners);
            }
            
            if (results.some(r => !r.success)) {
                const failed = results.filter(r => !r.success);
                showMessage(`${failed.length} banner(s) falharam ao enviar.`, 'error');
            }
            
            carouselBannersFile.value = '';
        } catch (error) {
            console.error('Erro:', error);
            showMessage('Erro ao enviar banners', 'error');
        }
    });
    
    // Salvar configuração
    saveBannerConfigBtn.addEventListener('click', async function() {
        const config = {
            type: currentType,
            banners: uploadedBanners,
            enabled: bannerEnabled.checked
        };
        
        if (currentType === 'carousel') {
            config.autoplay = carouselAutoplay.checked;
            config.interval = parseInt(carouselInterval.value) || 5;
        }
        
        if (uploadedBanners.length === 0) {
            showMessage('Adicione pelo menos um banner antes de salvar', 'error');
            return;
        }
        
        console.log('Salvando configuração:', config);
        console.log('Banners a serem salvos:', uploadedBanners);
        
        saveBannerConfigBtn.disabled = true;
        saveBannerConfigBtn.innerHTML = '<i data-lucide="loader-2" class="w-5 h-5 inline-block mr-2 animate-spin"></i> Salvando...';
        lucide.createIcons();
        
        try {
            console.log('Enviando requisição para salvar...');
            // Adicionar token CSRF ao config
            config.csrf_token = window.csrfToken || '';
            
            const response = await fetch('/api/admin_api.php?action=save_dashboard_banners', {
                method: 'POST',
                headers: { 
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': window.csrfToken || ''
                },
                body: JSON.stringify(config)
            });
            
            console.log('Status da resposta:', response.status, response.statusText);
            
            if (!response.ok) {
                const text = await response.text();
                console.error('Resposta não OK:', text);
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            
            const text = await response.text();
            console.log('Resposta bruta:', text);
            
            let result;
            try {
                result = JSON.parse(text);
            } catch (parseError) {
                console.error('Erro ao fazer parse do JSON:', parseError);
                console.error('Texto recebido:', text);
                throw new Error('Resposta inválida do servidor');
            }
            
            console.log('Resposta do servidor ao salvar:', result);
            
            if (result.success) {
                showMessage('Configurações salvas com sucesso!', 'success');
                // Recarregar configurações após salvar para garantir sincronização
                setTimeout(() => {
                    loadBannerConfig();
                }, 500);
            } else {
                console.error('Erro ao salvar:', result);
                showMessage('Erro ao salvar: ' + (result.error || 'Erro desconhecido'), 'error');
            }
        } catch (error) {
            console.error('Erro completo:', error);
            showMessage('Erro de comunicação com o servidor: ' + error.message, 'error');
        } finally {
            saveBannerConfigBtn.disabled = false;
            saveBannerConfigBtn.innerHTML = '<i data-lucide="save" class="w-5 h-5 inline-block mr-2"></i> Salvar Configurações';
            lucide.createIcons();
        }
    });
    
    // Carregar configurações ao iniciar
    loadBannerConfig();
});
</script>

