<?php

define('DB_HOST', 'localhost');
define('DB_USER', 'u874095710_vozfy');
define('DB_PASS', 'Fe.050421$');
define('DB_NAME', 'u874095710_vozfy');

// Define o fuso horário padrão para o PHP para 'America/Sao_Paulo' (Horário de Brasília)
date_default_timezone_set('America/Sao_Paulo');

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // Define o fuso horário da sessão do MySQL para UTC-03:00 (Horário de Brasília)
    $pdo->exec("SET time_zone = '-03:00';");
} catch (PDOException $e) {
    die("ERRO: Não foi possível conectar ao banco de dados. " . $e->getMessage());
}

// Inicia a sessão para todas as páginas do painel
if (session_status() == PHP_SESSION_NONE) {
    $session_lifetime = 604800; // 7 dias em segundos
    ini_set('session.cookie_lifetime', $session_lifetime);
    ini_set('session.gc_maxlifetime', $session_lifetime);
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_strict_mode', 1);
    ini_set('session.cookie_samesite', 'Lax');
    
    if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || 
        $_SERVER['SERVER_PORT'] == 443 ||
        (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')) {
        ini_set('session.cookie_secure', 1);
    }
    
    session_start();
    
    if (isset($_SESSION['last_activity'])) {
        $inactivity_timeout = $session_lifetime;
        if ((time() - $_SESSION['last_activity']) > $inactivity_timeout) {
            session_unset();
            session_destroy();
            session_start();
        }
    }
    $_SESSION['last_activity'] = time();
    
    if (!isset($_SESSION['last_regeneration'])) {
        $_SESSION['last_regeneration'] = time();
    } elseif ((time() - $_SESSION['last_regeneration']) > 86400) {
        session_regenerate_id(true);
        $_SESSION['last_regeneration'] = time();
    }
}

/**
 * Busca uma configuração do sistema
 */
function getSystemSetting($chave, $default = '') {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT valor FROM configuracoes_sistema WHERE chave = ?");
        $stmt->execute([$chave]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['valor'] : $default;
    } catch (PDOException $e) {
        error_log("Erro ao buscar configuração: " . $e->getMessage());
        return $default;
    }
}

/**
 * Salva ou atualiza uma configuração do sistema
 */
function setSystemSetting($chave, $valor) {
    global $pdo;
    if (!$pdo) {
        error_log("setSystemSetting: PDO não está disponível");
        return false;
    }
    try {
        $stmt_check = $pdo->prepare("SELECT id FROM configuracoes_sistema WHERE chave = ?");
        $stmt_check->execute([$chave]);
        $exists = $stmt_check->fetch(PDO::FETCH_ASSOC);
        
        if ($exists) {
            try {
                $stmt = $pdo->prepare("UPDATE configuracoes_sistema SET valor = ?, updated_at = CURRENT_TIMESTAMP WHERE chave = ?");
                $stmt->execute([$valor, $chave]);
            } catch (PDOException $e) {
                $stmt = $pdo->prepare("UPDATE configuracoes_sistema SET valor = ? WHERE chave = ?");
                $stmt->execute([$valor, $chave]);
            }
        } else {
            $stmt = $pdo->prepare("INSERT INTO configuracoes_sistema (chave, valor) VALUES (?, ?)");
            $stmt->execute([$chave, $valor]);
        }
        return true;
    } catch (PDOException $e) {
        error_log("setSystemSetting: " . $e->getMessage());
        return false;
    }
}

/**
 * Busca todas as configurações do sistema
 */
function getAllSystemSettings() {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT chave, valor FROM configuracoes_sistema");
        $stmt->execute();
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $settings = [];
        foreach ($results as $row) {
            $settings[$row['chave']] = $row['valor'];
        }
        return $settings;
    } catch (PDOException $e) {
        error_log("Erro ao buscar todas as configurações: " . $e->getMessage());
        return [];
    }
}

/**
 * Obtém a versão atual da plataforma
 */
function get_platform_version() {
    $version_file = __DIR__ . '/../VERSION.txt';
    if (file_exists($version_file)) {
        $version = trim(file_get_contents($version_file));
        return !empty($version) ? $version : '1.0.0';
    }
    return '1.0.0';
}

// Carrega sistema de plugins
if (file_exists(__DIR__ . '/../helpers/plugin_hooks.php')) {
    require_once __DIR__ . '/../helpers/plugin_hooks.php';
}
if (file_exists(__DIR__ . '/../helpers/plugin_loader.php')) {
    require_once __DIR__ . '/../helpers/plugin_loader.php';
}

// Carregar migration helper para executar migrations durante instalação
if (file_exists(__DIR__ . '/../helpers/migration_helper.php')) {
    require_once __DIR__ . '/../helpers/migration_helper.php';
}

// Carregar sistema SaaS se habilitado
if (file_exists(__DIR__ . '/../saas/includes/saas_functions.php')) {
    require_once __DIR__ . '/../saas/includes/saas_functions.php';
    if (function_exists('saas_enabled') && saas_enabled()) {
        if (file_exists(__DIR__ . '/../saas/includes/saas_limits.php')) {
            require_once __DIR__ . '/../saas/includes/saas_limits.php';
        }
        if (file_exists(__DIR__ . '/../saas/saas.php')) {
            require_once __DIR__ . '/../saas/saas.php';
        }
    }
}

// Aplicar headers de segurança
if (!headers_sent()) {
    if (file_exists(__DIR__ . '/security_headers.php')) {
        require_once __DIR__ . '/security_headers.php';
        if (function_exists('apply_security_headers')) {
            apply_security_headers(false);
        }
    }
}
?>