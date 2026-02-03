/**
 * CSRF Token Auto-Refresh
 * Renova automaticamente o token CSRF quando está próximo de expirar
 * para evitar erros quando a página fica aberta por muito tempo
 */

(function() {
    'use strict';
    
    // Configurações
    const CSRF_LIFETIME = 604800000; // 7 dias em milissegundos
    const CHECK_INTERVAL = 300000; // Verifica a cada 5 minutos
    const RENEWAL_THRESHOLD = 86400000; // Renova quando faltam menos de 1 dia (em milissegundos)
    
    let tokenStartTime = null;
    let refreshInterval = null;
    
    /**
     * Atualiza o token CSRF em todos os lugares da página
     */
    function updateCsrfToken(newToken) {
        // Atualizar variável global
        if (typeof window !== 'undefined') {
            window.csrfToken = newToken;
        }
        
        // Atualizar meta tag
        let metaTag = document.querySelector('meta[name="csrf-token"]');
        if (metaTag) {
            metaTag.setAttribute('content', newToken);
        } else {
            // Criar meta tag se não existir
            metaTag = document.createElement('meta');
            metaTag.name = 'csrf-token';
            metaTag.content = newToken;
            document.head.appendChild(metaTag);
        }
        
        // Atualizar todos os campos hidden com name="csrf_token"
        const hiddenInputs = document.querySelectorAll('input[type="hidden"][name="csrf_token"]');
        hiddenInputs.forEach(input => {
            input.value = newToken;
        });
        
        // Atualizar campos com id="csrf_token"
        const csrfById = document.getElementById('csrf_token');
        if (csrfById) {
            csrfById.value = newToken;
        }
        
        // Atualizar cookie se necessário (para double-submit pattern)
        if (typeof document !== 'undefined' && document.cookie !== undefined) {
            const cookieOptions = 'path=/; SameSite=Lax' + (window.location.protocol === 'https:' ? '; Secure' : '');
            document.cookie = 'csrf_token_cookie=' + newToken + '; ' + cookieOptions + '; max-age=' + CSRF_LIFETIME / 1000;
        }
        
        console.log('CSRF Token renovado automaticamente');
    }
    
    /**
     * Renova o token CSRF via AJAX
     */
    async function refreshCsrfToken() {
        try {
            const response = await fetch('/api/refresh_csrf.php', {
                method: 'GET',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json'
                }
            });
            
            if (!response.ok) {
                throw new Error('Erro ao renovar token CSRF: ' + response.status);
            }
            
            const data = await response.json();
            
            if (data.success && data.csrf_token) {
                updateCsrfToken(data.csrf_token);
                tokenStartTime = Date.now();
                return true;
            } else {
                throw new Error('Resposta inválida do servidor');
            }
        } catch (error) {
            console.error('Erro ao renovar token CSRF:', error);
            return false;
        }
    }
    
    /**
     * Verifica se o token precisa ser renovado
     */
    function checkAndRefreshIfNeeded() {
        // Se não temos tempo de início, tentar obter do token atual
        if (tokenStartTime === null) {
            // Estimar que o token foi gerado recentemente (assumir que a página acabou de carregar)
            tokenStartTime = Date.now();
        }
        
        const tokenAge = Date.now() - tokenStartTime;
        const timeUntilExpiry = CSRF_LIFETIME - tokenAge;
        
        // Se faltam menos de 1 dia para expirar, renovar
        if (timeUntilExpiry < RENEWAL_THRESHOLD) {
            console.log('Token CSRF próximo de expirar, renovando...');
            refreshCsrfToken();
        }
    }
    
    /**
     * Inicializa o sistema de renovação automática
     */
    function init() {
        // Verificar se há token CSRF na página
        const metaToken = document.querySelector('meta[name="csrf-token"]');
        const windowToken = typeof window !== 'undefined' ? window.csrfToken : null;
        
        if (!metaToken && !windowToken) {
            console.warn('CSRF Auto-Refresh: Token CSRF não encontrado na página');
            return;
        }
        
        // Inicializar tempo de início
        tokenStartTime = Date.now();
        
        // Verificar imediatamente
        checkAndRefreshIfNeeded();
        
        // Configurar verificação periódica
        refreshInterval = setInterval(checkAndRefreshIfNeeded, CHECK_INTERVAL);
        
        // Renovar token quando a página ganha foco (usuário volta para a aba)
        if (typeof window !== 'undefined') {
            window.addEventListener('focus', function() {
                checkAndRefreshIfNeeded();
            });
            
            // Renovar token antes de enviar formulários (preventivo)
            document.addEventListener('submit', function(e) {
                const form = e.target;
                if (form.tagName === 'FORM') {
                    const tokenAge = Date.now() - tokenStartTime;
                    if (tokenAge > CSRF_LIFETIME - RENEWAL_THRESHOLD) {
                        // Se token está próximo de expirar, renovar antes de enviar
                        e.preventDefault();
                        refreshCsrfToken().then(success => {
                            if (success) {
                                // Reenviar formulário após renovar token
                                form.submit();
                            }
                        });
                    }
                }
            }, true); // Usar capture phase para interceptar antes
        }
        
        console.log('CSRF Auto-Refresh inicializado');
    }
    
    // Inicializar quando o DOM estiver pronto
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
    
    // Exportar função para renovação manual se necessário
    if (typeof window !== 'undefined') {
        window.refreshCsrfToken = refreshCsrfToken;
    }
})();

