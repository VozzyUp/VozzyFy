<?php
/**
 * Carrega configurações do sistema e aplica dinamicamente
 * Este arquivo deve ser incluído no <head> de todas as páginas principais
 */

/**
 * Ajusta o brilho de uma cor hexadecimal
 * @param string $hex Cor em hexadecimal (#RRGGBB)
 * @param int $steps Passos de ajuste (negativo escurece, positivo clareia)
 * @return string Cor ajustada em hexadecimal
 */
if (!function_exists('adjustBrightness')) {
    function adjustBrightness($hex, $steps) {
        // Remove # se presente
        $hex = str_replace('#', '', $hex);
        
        // Converte para RGB
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        
        // Ajusta o brilho
        $r = max(0, min(255, $r + $steps));
        $g = max(0, min(255, $g + $steps));
        $b = max(0, min(255, $b + $steps));
        
        // Converte de volta para hex
        return '#' . str_pad(dechex($r), 2, '0', STR_PAD_LEFT) . 
                     str_pad(dechex($g), 2, '0', STR_PAD_LEFT) . 
                     str_pad(dechex($b), 2, '0', STR_PAD_LEFT);
    }
}

/**
 * Converte cor hexadecimal para RGB
 * @param string $hex Cor em hexadecimal (#RRGGBB)
 * @return array Array com r, g, b
 */
if (!function_exists('hexToRgb')) {
    function hexToRgb($hex) {
        $hex = str_replace('#', '', $hex);
        return [
            'r' => hexdec(substr($hex, 0, 2)),
            'g' => hexdec(substr($hex, 2, 2)),
            'b' => hexdec(substr($hex, 4, 2))
        ];
    }
}

// Garante que config.php já foi incluído
if (!function_exists('getSystemSetting')) {
    require_once __DIR__ . '/config.php';
}

// Busca configurações
$cor_primaria = getSystemSetting('cor_primaria', '#32e768');
$logo_url_raw = getSystemSetting('logo_url', 'https://i.ibb.co/2YRWNQw7/1757909548831-Photoroom.png');
$login_image_url_raw = getSystemSetting('login_image_url', '');
$nome_plataforma = getSystemSetting('nome_plataforma', 'Starfy');
$logo_checkout_url_raw = getSystemSetting('logo_checkout_url', '');
$favicon_url_raw = getSystemSetting('favicon_url', '');

// Normaliza URLs: igual às imagens dos módulos
// Remove barra inicial se houver (valores antigos podem ter)
$logo_url = ltrim($logo_url_raw, '/');
if (empty($logo_url)) {
    $logo_url = 'https://i.ibb.co/2YRWNQw7/1757909548831-Photoroom.png';
} elseif (strpos($logo_url, 'http') === 0) {
    // URL completa, mantém como está
} elseif (strpos($logo_url, 'uploads/') === 0) {
    // Adiciona barra inicial (igual às imagens dos módulos)
    $logo_url = '/' . $logo_url;
} else {
    // Outros casos, adiciona barra se necessário
    $logo_url = '/' . $logo_url;
}

$login_image_url = ltrim($login_image_url_raw, '/');
if (!empty($login_image_url) && strpos($login_image_url, 'http') !== 0) {
    if (strpos($login_image_url, 'uploads/') === 0) {
        // Adiciona barra inicial (igual às imagens dos módulos)
        $login_image_url = '/' . $login_image_url;
    } elseif (!empty($login_image_url)) {
        $login_image_url = '/' . $login_image_url;
    }
}

// Logo do checkout: se não configurada, usa a logo padrão
$logo_checkout_url = ltrim($logo_checkout_url_raw, '/');
if (empty($logo_checkout_url)) {
    $logo_checkout_url = $logo_url;
} elseif (strpos($logo_checkout_url, 'http') === 0) {
    // URL completa, mantém como está
} elseif (strpos($logo_checkout_url, 'uploads/') === 0) {
    // Adiciona barra inicial (igual às imagens dos módulos)
    $logo_checkout_url = '/' . $logo_checkout_url;
} else {
    $logo_checkout_url = '/' . $logo_checkout_url;
}

// Normaliza URL do favicon
$favicon_url = ltrim($favicon_url_raw, '/');
if (!empty($favicon_url) && strpos($favicon_url, 'http') !== 0) {
    if (strpos($favicon_url, 'uploads/') === 0) {
        // Adiciona barra inicial (igual às imagens dos módulos)
        $favicon_url = '/' . $favicon_url;
    } else {
        $favicon_url = '/' . $favicon_url;
    }
}

// Calcula cor hover
$cor_primaria_hover = adjustBrightness($cor_primaria, -10);

// Gera CSS dinâmico para a cor primária
?>
<style>
:root {
    --accent-primary: <?php echo htmlspecialchars($cor_primaria); ?>;
    --accent-primary-hover: <?php echo htmlspecialchars($cor_primaria_hover); ?>;
}

/* Classe utilitária para cor primária */
.bg-primary {
    background-color: var(--accent-primary) !important;
}

.bg-primary-hover:hover {
    background-color: var(--accent-primary-hover) !important;
}

.text-primary {
    color: var(--accent-primary) !important;
}

.border-primary {
    border-color: var(--accent-primary) !important;
}

.ring-primary:focus {
    ring-color: var(--accent-primary) !important;
}

/* Background dinâmico para sidebar-item-active */
.sidebar-item-active {
    background: <?php 
        $rgb = hexToRgb($cor_primaria);
        echo "rgba({$rgb['r']}, {$rgb['g']}, {$rgb['b']}, 0.1)";
    ?> !important;
    color: <?php echo htmlspecialchars($cor_primaria); ?> !important;
}

.sidebar-item-active i {
    color: <?php echo htmlspecialchars($cor_primaria); ?> !important;
    filter: drop-shadow(0 0 4px <?php 
        $rgb = hexToRgb($cor_primaria);
        echo "rgba({$rgb['r']}, {$rgb['g']}, {$rgb['b']}, 0.4)";
    ?>) !important;
}

.sidebar-item-active span {
    color: <?php echo htmlspecialchars($cor_primaria); ?> !important;
}

/* Sidebar glass border dinâmico */
.sidebar-glass {
    box-shadow: 
        0 8px 32px rgba(0, 0, 0, 0.4),
        inset 0 0 0 1px <?php 
            $rgb = hexToRgb($cor_primaria);
            echo "rgba({$rgb['r']}, {$rgb['g']}, {$rgb['b']}, 0.1)";
        ?>,
        0 0 40px <?php 
            $rgb = hexToRgb($cor_primaria);
            echo "rgba({$rgb['r']}, {$rgb['g']}, {$rgb['b']}, 0.05)";
        ?> !important;
}

/* Cards do Dashboard - bordas dinâmicas */
div.bg-dark-card.border,
.bg-dark-card.border {
    border-color: <?php echo htmlspecialchars($cor_primaria); ?> !important;
    border-width: 1px !important;
}

/* Força a cor nos cards que têm style inline com var(--accent-primary) */
div[style*="border-color: var(--accent-primary)"] {
    border-color: <?php echo htmlspecialchars($cor_primaria); ?> !important;
}
</style>
<?php
// Gera tag <link rel="icon"> se favicon estiver configurado
if (!empty($favicon_url)) {
    // Determina o tipo MIME baseado na extensão
    $favicon_ext = strtolower(pathinfo($favicon_url, PATHINFO_EXTENSION));
    $favicon_type = 'image/x-icon'; // padrão
    if ($favicon_ext === 'png') {
        $favicon_type = 'image/png';
    } elseif ($favicon_ext === 'svg') {
        $favicon_type = 'image/svg+xml';
    }
    echo '<link rel="icon" type="' . htmlspecialchars($favicon_type) . '" href="' . htmlspecialchars($favicon_url) . '">' . "\n";
}

// Integração PWA - Adiciona tags meta e links se módulo estiver instalado
// IMPORTANTE: No iOS, essas meta tags são CRÍTICAS para modo standalone
if (file_exists(__DIR__ . '/../pwa/pwa_config.php')) {
    require_once __DIR__ . '/../pwa/pwa_config.php';
    
    if (pwa_module_installed()) {
        $pwa_config = pwa_get_config();
        // Sempre inclui meta tags se módulo está instalado (não depende de configuração)
        // Força inclusão mesmo sem config para garantir que iOS funcione
        if (!$pwa_config) {
            // Usa valores padrão se não houver configuração
            $pwa_theme_color = function_exists('getSystemSetting') ? getSystemSetting('cor_primaria', '#32e768') : '#32e768';
            $pwa_app_name = function_exists('getSystemSetting') ? getSystemSetting('nome_plataforma', 'Plataforma') : 'Plataforma';
            $pwa_icon_path = '';
            $pwa_icon_url = '';
        } else {
            $pwa_theme_color = $pwa_config['theme_color'] ?? $cor_primaria;
            $pwa_app_name = $pwa_config['app_name'] ?? $nome_plataforma;
            $pwa_icon_path = $pwa_config['icon_path'] ?? $favicon_url;
            
            // Normaliza URL do ícone
            $pwa_icon_url = '';
            if (!empty($pwa_icon_path)) {
                $pwa_icon_url = ltrim($pwa_icon_path, '/');
                if (strpos($pwa_icon_url, 'http') !== 0) {
                    $pwa_icon_url = '/' . $pwa_icon_url;
                } else {
                    $pwa_icon_url = $pwa_icon_path;
                }
            } elseif (!empty($favicon_url)) {
                $pwa_icon_url = $favicon_url;
            }
            
        }
        
        // Meta tags PWA (CRÍTICO para iOS standalone)
        // Baseado em pesquisas: iOS depende PRINCIPALMENTE dessas meta tags HTML
        // IMPORTANTE: Estas tags DEVEM estar presentes na página onde o usuário instala
        echo '<!-- PWA Meta Tags -->' . "\n";
        echo '<meta name="theme-color" content="' . htmlspecialchars($pwa_theme_color) . '">' . "\n";
        // Esta é a tag MAIS IMPORTANTE - deve ser "yes" para modo standalone
        echo '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n";
        // Status bar style: default, black, ou black-translucent
        echo '<meta name="apple-mobile-web-app-status-bar-style" content="default">' . "\n";
        // Título do app quando instalado
        echo '<meta name="apple-mobile-web-app-title" content="' . htmlspecialchars($pwa_app_name) . '">' . "\n";
        echo '<meta name="mobile-web-app-capable" content="yes">' . "\n";
        // Meta tags adicionais
        echo '<meta name="format-detection" content="telephone=no">' . "\n";
        
        // Link para manifest
        // IMPORTANTE: Usa caminho relativo simples para evitar problemas com caminhos de sistema de arquivos
        // O navegador resolve automaticamente o caminho relativo baseado na URL atual
        echo '<link rel="manifest" href="/pwa/manifest.php">' . "\n";
        
        // Apple touch icons (múltiplos tamanhos para melhor compatibilidade iOS)
        if (!empty($pwa_icon_url)) {
            // iOS precisa de ícones específicos
            // Baseado em pesquisas: iOS precisa de apple-touch-icon (fallback geral primeiro)
            echo '<link rel="apple-touch-icon" href="' . htmlspecialchars($pwa_icon_url) . '">' . "\n";
            // Tamanho específico para iOS 11+ (180x180 é o padrão)
            echo '<link rel="apple-touch-icon" sizes="180x180" href="' . htmlspecialchars($pwa_icon_url) . '">' . "\n";
        }
    }
}
?>

