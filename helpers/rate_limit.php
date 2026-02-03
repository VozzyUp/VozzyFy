<?php
/**
 * Rate Limiting Helper
 * Implementa rate limiting usando sessão PHP
 */

/**
 * Verifica se a requisição excede o limite de taxa
 * @param string $key Chave única para identificar o limite (ex: 'login', 'check_status')
 * @param int $max_attempts Número máximo de tentativas
 * @param int $time_window Período de tempo em segundos (padrão: 60 segundos)
 * @return bool True se dentro do limite, False se excedeu
 */
function check_rate_limit($key, $max_attempts = 5, $time_window = 60) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    $rate_key = 'rate_limit_' . $key;
    $current_time = time();
    
    // Inicializa o array se não existir
    if (!isset($_SESSION[$rate_key])) {
        $_SESSION[$rate_key] = [
            'attempts' => [],
            'blocked_until' => 0
        ];
    }
    
    $rate_data = &$_SESSION[$rate_key];
    
    // Verifica se está bloqueado
    if ($rate_data['blocked_until'] > $current_time) {
        return false;
    }
    
    // Remove tentativas antigas (fora da janela de tempo)
    $rate_data['attempts'] = array_filter(
        $rate_data['attempts'],
        function($timestamp) use ($current_time, $time_window) {
            return ($current_time - $timestamp) < $time_window;
        }
    );
    
    // Verifica se excedeu o limite
    if (count($rate_data['attempts']) >= $max_attempts) {
        // Bloqueia por 15 minutos
        $rate_data['blocked_until'] = $current_time + 900;
        return false;
    }
    
    // Adiciona a tentativa atual
    $rate_data['attempts'][] = $current_time;
    
    return true;
}

/**
 * Obtém o tempo restante até a próxima tentativa permitida
 * @param string $key Chave do rate limit
 * @return int Tempo em segundos, 0 se não estiver bloqueado
 */
function get_rate_limit_remaining($key) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    $rate_key = 'rate_limit_' . $key;
    
    if (!isset($_SESSION[$rate_key])) {
        return 0;
    }
    
    $rate_data = $_SESSION[$rate_key];
    $current_time = time();
    
    if ($rate_data['blocked_until'] > $current_time) {
        return $rate_data['blocked_until'] - $current_time;
    }
    
    return 0;
}

/**
 * Limpa o rate limit para uma chave específica
 * @param string $key Chave do rate limit
 */
function clear_rate_limit($key) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    $rate_key = 'rate_limit_' . $key;
    unset($_SESSION[$rate_key]);
}

