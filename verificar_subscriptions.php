<?php
/**
 * Script para verificar subscriptions de push por usuário
 */

require_once __DIR__ . '/config/config.php';

// Verifica se é admin
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || !isset($_SESSION['tipo']) || $_SESSION['tipo'] !== 'admin') {
    die("Acesso negado. Apenas administradores podem verificar subscriptions.");
}

require_once __DIR__ . '/pwa/api/web_push_helper.php';

echo "<h2>Subscriptions de Push Notifications por Usuário</h2>";
echo "<style>
    table { border-collapse: collapse; width: 100%; margin: 20px 0; }
    th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
    th { background-color: #4CAF50; color: white; }
    tr:nth-child(even) { background-color: #f2f2f2; }
    .no-subs { color: red; font-weight: bold; }
    .has-subs { color: green; font-weight: bold; }
</style>";

// Busca todos os usuários
$stmt = $pdo->query("SELECT id, nome, email FROM usuarios ORDER BY id");
$usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<table>";
echo "<tr><th>ID</th><th>Nome</th><th>Email</th><th>Subscriptions</th><th>Detalhes</th></tr>";

foreach ($usuarios as $usuario) {
    $user_id = $usuario['id'];
    $subscriptions = pwa_get_subscriptions($user_id);
    $count = count($subscriptions);
    
    $status_class = $count > 0 ? 'has-subs' : 'no-subs';
    $status_text = $count > 0 ? "✓ {$count} subscription(s)" : "✗ Nenhuma";
    
    echo "<tr>";
    echo "<td>{$user_id}</td>";
    echo "<td>{$usuario['nome']}</td>";
    echo "<td>{$usuario['email']}</td>";
    echo "<td class='{$status_class}'>{$status_text}</td>";
    echo "<td>";
    
    if ($count > 0) {
        echo "<details><summary>Ver detalhes</summary><ul>";
        foreach ($subscriptions as $sub) {
            $endpoint_short = substr($sub['endpoint'], 0, 60) . '...';
            $created = $sub['created_at'] ?? 'N/A';
            echo "<li>Endpoint: {$endpoint_short}<br>";
            echo "Criado em: {$created}</li>";
        }
        echo "</ul></details>";
    } else {
        echo "Usuário precisa permitir notificações no navegador";
    }
    
    echo "</td>";
    echo "</tr>";
}

echo "</table>";

// Estatísticas gerais
$all_subs = pwa_get_subscriptions();
$total_subs = count($all_subs);
$users_with_subs = 0;

foreach ($usuarios as $usuario) {
    $subs = pwa_get_subscriptions($usuario['id']);
    if (count($subs) > 0) {
        $users_with_subs++;
    }
}

echo "<h3>Estatísticas</h3>";
echo "<ul>";
echo "<li>Total de subscriptions: <strong>{$total_subs}</strong></li>";
echo "<li>Usuários com subscriptions: <strong>{$users_with_subs}</strong> de " . count($usuarios) . "</li>";
echo "</ul>";

echo "<hr>";
echo "<h3>Como registrar uma subscription:</h3>";
echo "<ol>";
echo "<li>Faça login como o vendedor (usuário que receberá as notificações)</li>";
echo "<li>Abra o navegador e permita notificações quando solicitado</li>";
echo "<li>Ou vá para a página de configurações do PWA e registre manualmente</li>";
echo "</ol>";

echo "<p><strong>Nota:</strong> O vendedor ID 34 tem 1 subscription registrada (conforme o log). 
Se você não está recebendo notificações, verifique se está logado como o vendedor correto e se o navegador tem permissão para exibir notificações.</p>";
?>

