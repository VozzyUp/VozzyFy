<?php
/**
 * Security Headers Configuration
 * Aplica headers de segurança HTTP para proteger contra vários tipos de ataques
 */

if (!function_exists('is_https')) {
    /**
     * Verifica se a requisição está usando HTTPS
     * @return bool True se HTTPS, False caso contrário
     */
    function is_https() {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') 
            || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
            || (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on')
            || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
    }
}

if (!function_exists('generate_csp_nonce')) {
    /**
     * Gera um nonce único para CSP (Content Security Policy)
     * @return string Nonce de 32 caracteres
     */
    function generate_csp_nonce() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // Gera novo nonce se não existir ou se expirou (1 hora)
        $nonce_age = isset($_SESSION['csp_nonce_time']) ? (time() - $_SESSION['csp_nonce_time']) : 3601;
        if (empty($_SESSION['csp_nonce']) || $nonce_age > 3600) {
            $_SESSION['csp_nonce'] = base64_encode(random_bytes(24));
            $_SESSION['csp_nonce_time'] = time();
        }
        
        return $_SESSION['csp_nonce'];
    }
}

if (!function_exists('get_csp_nonce')) {
    /**
     * Obtém o nonce atual para uso em templates
     * @return string Nonce atual
     */
    function get_csp_nonce() {
        return generate_csp_nonce();
    }
}

if (!function_exists('apply_security_headers')) {
    /**
     * Aplica headers de segurança HTTP
     * Deve ser chamado antes de qualquer output
     * @param bool $strict_csp Se true, usa CSP mais restritivo com nonces
     * @return string Nonce gerado (para uso em templates)
     */
    function apply_security_headers($strict_csp = false) {
        // X-Frame-Options: Previne clickjacking
        // Permite iframe apenas para preview do checkout (quando preview=true)
        $is_preview = isset($_GET['preview']) && $_GET['preview'] === 'true';
        $is_checkout_preview = (basename($_SERVER['PHP_SELF']) === 'checkout.php' && $is_preview);
        
        if ($is_checkout_preview) {
            // Permite iframe apenas para preview do checkout (same-origin)
            header('X-Frame-Options: SAMEORIGIN');
        } else {
            // Bloqueia iframe para todas as outras páginas
            header('X-Frame-Options: DENY');
        }
        
        // X-Content-Type-Options: Previne MIME type sniffing
        header('X-Content-Type-Options: nosniff');
        
        // Referrer-Policy: Controla informações enviadas no Referer header
        header('Referrer-Policy: strict-origin-when-cross-origin');
        
        // Permissions-Policy: Controla recursos do navegador
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
        
        // X-XSS-Protection: Ativa proteção XSS do navegador (legado, mas ainda útil)
        header('X-XSS-Protection: 1; mode=block');
        
        // Strict-Transport-Security: Força HTTPS (apenas se estiver em HTTPS)
        if (is_https()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
        }
        
        // Content-Security-Policy: Previne XSS e outros ataques
        if ($strict_csp) {
            // CSP restritivo com nonces (remove unsafe-inline e unsafe-eval)
            // Requer que todos os scripts e estilos inline usem nonce
            $nonce = generate_csp_nonce();
            $csp = "default-src 'self'; " .
                   "script-src 'self' 'nonce-{$nonce}' https://cdn.tailwindcss.com https://unpkg.com https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://www.googletagmanager.com https://www.google-analytics.com https://connect.facebook.net https://js.fastsoftbrasil.com https://cdn.quilljs.com https://www.youtube.com https://sdk.mercadopago.com https://http2.mlstatic.com https://device.clearsale.com.br https://static.cloudflareinsights.com; " .
                   "style-src 'self' 'nonce-{$nonce}' https://fonts.googleapis.com https://cdn.tailwindcss.com https://cdn.quilljs.com https://cdn.jsdelivr.net; " .
                   "font-src 'self' data: https://fonts.gstatic.com; " .
                   "img-src 'self' data: https: http:; " .
                   "connect-src 'self' https://api.fastsoftbrasil.com https:; " .
                   "frame-src 'self' https://www.youtube.com https://www.youtube-nocookie.com; " .
                   "object-src 'none'; " .
                   "base-uri 'self'; " .
                   "form-action 'self';";
        } else {
            // CSP balanceado: permite recursos externos e unsafe-inline para compatibilidade
            // NÃO usa nonce quando não é strict, pois nonce + unsafe-inline = unsafe-inline é ignorado
            // Para preview do checkout, permite frame-ancestors 'self' para permitir iframe
            $frame_ancestors = $is_checkout_preview ? "'self'" : "'none'";
            
            $csp = "default-src 'self'; " .
                   "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.tailwindcss.com https://unpkg.com https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://www.googletagmanager.com https://www.google-analytics.com https://connect.facebook.net https://api.mercadopago.com https://sdk.mercadopago.com https://http2.mlstatic.com https://api.pushinpay.com.br https://js.fastsoftbrasil.com https://cdn.quilljs.com https://www.youtube.com https://device.clearsale.com.br https://static.cloudflareinsights.com; " .
                   "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdn.tailwindcss.com https://cdn.quilljs.com https://cdn.jsdelivr.net; " .
                   "font-src 'self' data: https://fonts.gstatic.com; " .
                   "img-src 'self' data: https: http:; " .
                   "connect-src 'self' https://api.fastsoftbrasil.com https: http:; " .
                   "frame-src 'self' https://www.youtube.com https://www.youtube-nocookie.com https: http:; " .
                   "frame-ancestors {$frame_ancestors}; " .
                   "object-src 'none'; " .
                   "base-uri 'self'; " .
                   "form-action 'self';";
            $nonce = null; // Não retorna nonce quando não é strict
        }
        
        header("Content-Security-Policy: $csp");
        
        // Remove informações do servidor (segurança por obscuridade)
        header_remove('X-Powered-By');
        if (function_exists('header_register_callback')) {
            // Tenta remover Server header (nem todos os servidores permitem)
            @header_remove('Server');
        }
        
        return $nonce ?? null;
    }
}

// Aplicar headers automaticamente se o arquivo for incluído diretamente
if (basename($_SERVER['PHP_SELF']) === basename(__FILE__)) {
    apply_security_headers();
}

