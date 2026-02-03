<?php
// Partial: banner do curso. Usado em member_course_view (só leitura) e gerenciar_curso (com botão Alterar banner).
// Variáveis: $curso (array com produto_nome, titulo, produto_descricao, descricao, banner_url, banner_desktop_url, banner_mobile_url, banner_logo_url, produto_foto), $upload_dir (string).
// Opcional: $show_edit_banner (bool) — quando true, exibe overlay "Alterar banner" que abre o drawer de configurações.
$banner_desktop = $curso['banner_desktop_url'] ?? $curso['banner_url'] ?? ($upload_dir . ($curso['produto_foto'] ?? ''));
$banner_mobile  = $curso['banner_mobile_url'] ?? $banner_desktop;
$banner_logo    = $curso['banner_logo_url'] ?? null;
$banner_title   = $curso['produto_nome'] ?? $curso['titulo'] ?? 'Curso';
$banner_desc    = $curso['produto_descricao'] ?? $curso['descricao'] ?? '';
$edit_mode      = !empty($show_edit_banner);
$has_desktop_bg  = !empty($banner_desktop) && (strpos($banner_desktop, 'http') === 0 || file_exists($banner_desktop));
$has_mobile_bg  = !empty($banner_mobile) && (strpos($banner_mobile, 'http') === 0 || file_exists($banner_mobile));
$has_logo       = !empty($banner_logo) && (strpos($banner_logo, 'http') === 0 || file_exists($banner_logo));
?>
<header class="curso-hero relative min-h-[440px] md:min-h-[520px] bg-gray-800 bg-cover bg-center flex-shrink-0 overflow-hidden" role="banner"
    style="--banner-desktop: <?php echo $has_desktop_bg ? "url('" . htmlspecialchars($banner_desktop) . "')" : 'none'; ?>; --banner-mobile: <?php echo $has_mobile_bg ? "url('" . htmlspecialchars($banner_mobile) . "')" : 'none'; ?>; <?php if ($has_desktop_bg || $has_mobile_bg): ?>background-image: var(--banner-mobile);<?php endif; ?>">
    <style>
    @media (min-width: 768px) {
        .curso-hero { background-image: var(--banner-desktop); }
    }
    @media (max-width: 767px) {
        .curso-hero { background-image: var(--banner-mobile); }
    }
    </style>
    <div class="absolute inset-0 bg-gradient-to-t from-black via-black/70 to-transparent"></div>

    <?php
    $show_member_nav = !empty($show_member_nav);
    if ($show_member_nav): ?>
    <div class="absolute top-0 left-0 right-0 z-10 flex justify-between items-center px-5 py-4 md:px-8 md:py-5">
        <div class="flex items-center gap-4 md:gap-6">
            <?php echo $member_nav_left ?? ''; ?>
            <?php if ($has_logo): ?>
            <div class="pl-2 md:pl-4 border-l border-white/20">
                <img src="<?php echo htmlspecialchars($banner_logo); ?>" alt="Logo" class="max-h-11 md:max-h-14 w-auto object-contain drop-shadow-lg" style="max-width: 180px;">
            </div>
            <?php endif; ?>
        </div>
        <?php if (!empty($member_nav_tabs)): ?>
        <div class="flex items-center gap-1 md:gap-2">
            <?php echo $member_nav_tabs; ?>
        </div>
        <?php endif; ?>
        <div class="flex items-center gap-3 md:gap-4">
            <?php echo $member_nav_right ?? ''; ?>
        </div>
    </div>
    <?php elseif ($has_logo): ?>
    <div class="absolute top-5 left-5 md:top-6 md:left-8 z-10 flex items-center p-4 md:p-5">
        <img src="<?php echo htmlspecialchars($banner_logo); ?>" alt="Logo" class="max-h-12 md:max-h-14 w-auto object-contain drop-shadow-lg" style="max-width: 180px;">
    </div>
    <?php endif; ?>

    <?php if ($edit_mode): ?>
    <div class="absolute top-4 right-4 z-10">
        <button type="button" class="drawer-open-config curso-banner-edit-btn px-4 py-2 rounded-lg text-white text-sm font-semibold shadow-lg transition opacity-90 hover:opacity-100" style="background-color: var(--accent-primary);" data-drawer-panel="banner" title="Alterar banner">
            <i data-lucide="image" class="w-4 h-4 inline-block mr-1 align-middle"></i> Alterar banner
        </button>
    </div>
    <?php endif; ?>

    <div class="absolute bottom-0 left-0 right-0 p-6 md:p-10 max-w-screen-2xl mx-auto w-full z-[1]">
        <h1 class="text-3xl md:text-5xl font-extrabold text-white drop-shadow-lg"><?php echo htmlspecialchars($banner_title); ?></h1>
        <p class="mt-2 text-lg text-gray-300 max-w-2xl drop-shadow-md"><?php echo htmlspecialchars($banner_desc); ?></p>
    </div>
</header>
