<?php
/**
 * Debug script para testar update_check.php
 */

ini_set('display_errors', 1);
ini_set('html_errors', 0);
error_reporting(E_ALL);

header('Content-Type: text/plain');

echo "=== DEBUG UPDATE_CHECK ===\n\n";

// 1. Verificar se config.php existe
echo "1. Verificando config.php...\n";
$configPath = __DIR__ . '/../config/config.php';
if (file_exists($configPath)) {
    echo "   ✓ config.php encontrado\n";
    require_once $configPath;
    echo "   ✓ config.php carregado\n";
} else {
    echo "   ✗ config.php NÃO encontrado em: {$configPath}\n";
    exit;
}

// 2. Verificar se getSystemSetting existe
echo "\n2. Verificando função getSystemSetting...\n";
if (function_exists('getSystemSetting')) {
    echo "   ✓ getSystemSetting existe\n";
} else {
    echo "   ✗ getSystemSetting NÃO existe\n";
    exit;
}

// 3. Verificar se security_helper existe
echo "\n3. Verificando security_helper.php...\n";
$securityPath = __DIR__ . '/../helpers/security_helper.php';
if (file_exists($securityPath)) {
    echo "   ✓ security_helper.php encontrado\n";
    require_once $securityPath;
    echo "   ✓ security_helper.php carregado\n";
} else {
    echo "   ✗ security_helper.php NÃO encontrado em: {$securityPath}\n";
    exit;
}

// 4. Verificar se require_admin_auth existe
echo "\n4. Verificando função require_admin_auth...\n";
if (function_exists('require_admin_auth')) {
    echo "   ✓ require_admin_auth existe\n";
} else {
    echo "   ✗ require_admin_auth NÃO existe\n";
    exit;
}

// 5. Testar getSystemSetting
echo "\n5. Testando getSystemSetting...\n";
try {
    $repo = getSystemSetting('github_repo', 'LeonardoIsrael0516/getfy-update');
    $token = getSystemSetting('github_token', '');
    $branch = getSystemSetting('github_branch', 'main');
    echo "   ✓ github_repo: " . (empty($repo) ? 'vazio' : substr($repo, 0, 20)) . "\n";
    echo "   ✓ github_token: " . (empty($token) ? 'vazio' : 'definido') . "\n";
    echo "   ✓ github_branch: {$branch}\n";
} catch (Exception $e) {
    echo "   ✗ Erro ao buscar configurações: " . $e->getMessage() . "\n";
    exit;
} catch (Error $e) {
    echo "   ✗ Erro fatal ao buscar configurações: " . $e->getMessage() . "\n";
    exit;
}

// 6. Verificar VERSION.txt local
echo "\n6. Verificando VERSION.txt local...\n";
$versionFile = __DIR__ . '/../VERSION.txt';
if (file_exists($versionFile)) {
    $localVersion = trim(file_get_contents($versionFile));
    echo "   ✓ VERSION.txt encontrado: {$localVersion}\n";
} else {
    echo "   ⚠ VERSION.txt NÃO encontrado (será usado 1.0.0 como padrão)\n";
}

// 7. Testar sessão
echo "\n7. Verificando sessão...\n";
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
echo "   ✓ Sessão iniciada\n";
echo "   - loggedin: " . (isset($_SESSION['loggedin']) ? ($_SESSION['loggedin'] ? 'true' : 'false') : 'não definido') . "\n";
echo "   - tipo: " . (isset($_SESSION['tipo']) ? $_SESSION['tipo'] : 'não definido') . "\n";

echo "\n=== FIM DO DEBUG ===\n";

