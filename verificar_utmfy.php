<?php
/**
 * Script de Verificação da Integração UTMfy
 * 
 * Este script verifica:
 * 1. Se a função trigger_utmfy_integrations existe
 * 2. Lista todas as integrações configuradas
 * 3. Mostra os últimos eventos processados (via logs)
 * 4. Permite testar o disparo de um evento
 */

require_once __DIR__ . '/config/config.php';

// Verifica autenticação (apenas admin)
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || $_SESSION["tipo"] !== 'admin') {
    die("Acesso negado. Apenas administradores podem acessar este script.");
}

// Carrega o helper UTMfy
if (file_exists(__DIR__ . '/helpers/utmfy_helper.php')) {
    require_once __DIR__ . '/helpers/utmfy_helper.php';
} else {
    die("Arquivo utmfy_helper.php não encontrado!");
}

$mensagem = '';
$erro = '';

// Ação de teste
if (isset($_POST['testar_integracao'])) {
    $usuario_id_teste = $_POST['usuario_id'] ?? null;
    $produto_id_teste = $_POST['produto_id'] ?? null;
    
    if ($usuario_id_teste && $produto_id_teste) {
        // Busca dados de teste
        $stmt = $pdo->prepare("SELECT id, nome, preco FROM produtos WHERE id = ? AND usuario_id = ?");
        $stmt->execute([$produto_id_teste, $usuario_id_teste]);
        $produto_teste = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($produto_teste) {
            $evento_teste = [
                'transacao_id' => 'TEST_' . time(),
                'valor_total_compra' => $produto_teste['preco'],
                'comprador' => [
                    'nome' => 'Cliente Teste',
                    'email' => 'teste@exemplo.com',
                    'telefone' => '11999999999',
                    'cpf' => '12345678901'
                ],
                'metodo_pagamento' => 'Pix',
                'produtos_comprados' => [[
                    'produto_id' => $produto_teste['id'],
                    'nome' => $produto_teste['nome'],
                    'valor' => $produto_teste['preco']
                ]],
                'data_venda' => date('Y-m-d H:i:s'),
                'utm_parameters' => [
                    'utm_source' => 'teste',
                    'utm_campaign' => 'verificacao',
                    'utm_medium' => 'manual'
                ]
            ];
            
            try {
                trigger_utmfy_integrations($usuario_id_teste, $evento_teste, 'approved', $produto_teste['id']);
                $mensagem = "Evento de teste disparado com sucesso! Verifique o log utmfy_debug.log para mais detalhes.";
            } catch (Exception $e) {
                $erro = "Erro ao disparar evento de teste: " . $e->getMessage();
            }
        } else {
            $erro = "Produto não encontrado ou não pertence ao usuário selecionado.";
        }
    } else {
        $erro = "Selecione um usuário e produto para teste.";
    }
}

// Busca todas as integrações
$stmt_integrations = $pdo->query("
    SELECT ui.*, u.nome as usuario_nome 
    FROM utmfy_integrations ui 
    LEFT JOIN usuarios u ON ui.usuario_id = u.id 
    ORDER BY ui.usuario_id, ui.name
");
$integrations = $stmt_integrations->fetchAll(PDO::FETCH_ASSOC);

// Busca últimos logs (últimas 50 linhas)
$log_file = __DIR__ . '/helpers/utmfy_debug.log';
$ultimos_logs = '';
if (file_exists($log_file)) {
    $logs = file($log_file);
    $ultimos_logs = implode('', array_slice($logs, -50));
} else {
    $ultimos_logs = "Arquivo de log não encontrado ou ainda não foi criado.";
}

// Busca usuários para teste
$stmt_usuarios = $pdo->query("SELECT id, nome, usuario FROM usuarios WHERE tipo = 'infoprodutor' ORDER BY nome");
$usuarios = $stmt_usuarios->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verificação UTMfy</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        body { font-family: system-ui, -apple-system, sans-serif; background: #0f172a; }
        .code-block { background: #1e293b; padding: 1rem; border-radius: 0.5rem; overflow-x: auto; font-family: 'Courier New', monospace; font-size: 0.875rem; }
    </style>
</head>
<body class="min-h-screen p-8 text-white">
    <div class="max-w-6xl mx-auto">
        <div class="mb-8">
            <h1 class="text-3xl font-bold mb-2">Verificação da Integração UTMfy</h1>
            <p class="text-gray-400">Verifique o status da integração e teste eventos</p>
        </div>

        <?php if ($mensagem): ?>
            <div class="bg-green-900/30 border border-green-500 text-green-300 px-4 py-3 rounded-lg mb-6">
                <?php echo htmlspecialchars($mensagem); ?>
            </div>
        <?php endif; ?>

        <?php if ($erro): ?>
            <div class="bg-red-900/30 border border-red-500 text-red-300 px-4 py-3 rounded-lg mb-6">
                <?php echo htmlspecialchars($erro); ?>
            </div>
        <?php endif; ?>

        <!-- Status da Função -->
        <div class="bg-slate-800 rounded-lg p-6 mb-6 border border-slate-700">
            <h2 class="text-xl font-bold mb-4 flex items-center gap-2">
                <i data-lucide="check-circle" class="w-6 h-6 text-green-500"></i>
                Status da Função
            </h2>
            <div class="space-y-2">
                <p><strong>Função trigger_utmfy_integrations:</strong> 
                    <?php if (function_exists('trigger_utmfy_integrations')): ?>
                        <span class="text-green-400">✓ Disponível</span>
                    <?php else: ?>
                        <span class="text-red-400">✗ Não encontrada</span>
                    <?php endif; ?>
                </p>
                <p><strong>Arquivo helper:</strong> 
                    <?php echo file_exists(__DIR__ . '/helpers/utmfy_helper.php') ? '<span class="text-green-400">✓ Existe</span>' : '<span class="text-red-400">✗ Não encontrado</span>'; ?>
                </p>
                <p><strong>Arquivo de log:</strong> 
                    <?php 
                    $log_path = __DIR__ . '/helpers/utmfy_debug.log';
                    if (file_exists($log_path)) {
                        $log_size = filesize($log_path);
                        echo '<span class="text-green-400">✓ Existe (' . number_format($log_size / 1024, 2) . ' KB)</span>';
                    } else {
                        echo '<span class="text-yellow-400">⚠ Não existe ainda (será criado no primeiro uso)</span>';
                    }
                    ?>
                </p>
            </div>
        </div>

        <!-- Integrações Configuradas -->
        <div class="bg-slate-800 rounded-lg p-6 mb-6 border border-slate-700">
            <h2 class="text-xl font-bold mb-4 flex items-center gap-2">
                <i data-lucide="link" class="w-6 h-6 text-blue-500"></i>
                Integrações Configuradas (<?php echo count($integrations); ?>)
            </h2>
            
            <?php if (empty($integrations)): ?>
                <p class="text-gray-400">Nenhuma integração configurada.</p>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-slate-700">
                                <th class="text-left p-2">Usuário</th>
                                <th class="text-left p-2">Nome</th>
                                <th class="text-left p-2">Produto ID</th>
                                <th class="text-left p-2">Eventos Ativos</th>
                                <th class="text-left p-2">Token (primeiros chars)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($integrations as $int): ?>
                                <tr class="border-b border-slate-700/50">
                                    <td class="p-2"><?php echo htmlspecialchars($int['usuario_nome'] ?? 'N/A'); ?></td>
                                    <td class="p-2"><?php echo htmlspecialchars($int['name']); ?></td>
                                    <td class="p-2"><?php echo $int['product_id'] ? htmlspecialchars($int['product_id']) : '<span class="text-gray-400">Todos</span>'; ?></td>
                                    <td class="p-2">
                                        <?php 
                                        $eventos = [];
                                        if ($int['event_approved']) $eventos[] = 'approved';
                                        if ($int['event_pending']) $eventos[] = 'pending';
                                        if ($int['event_refund']) $eventos[] = 'refund';
                                        echo implode(', ', $eventos) ?: '<span class="text-gray-400">Nenhum</span>';
                                        ?>
                                    </td>
                                    <td class="p-2 font-mono text-xs">
                                        <?php echo htmlspecialchars(substr($int['api_token'], 0, 20)) . '...'; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <!-- Teste de Integração -->
        <div class="bg-slate-800 rounded-lg p-6 mb-6 border border-slate-700">
            <h2 class="text-xl font-bold mb-4 flex items-center gap-2">
                <i data-lucide="test-tube" class="w-6 h-6 text-yellow-500"></i>
                Testar Integração
            </h2>
            <form method="POST" class="space-y-4">
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium mb-2">Usuário</label>
                        <select name="usuario_id" required class="w-full bg-slate-700 border border-slate-600 rounded px-3 py-2">
                            <option value="">Selecione...</option>
                            <?php foreach ($usuarios as $u): ?>
                                <option value="<?php echo $u['id']; ?>">
                                    <?php echo htmlspecialchars($u['nome'] . ' (' . $u['usuario'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-2">Produto (ID)</label>
                        <input type="number" name="produto_id" required 
                               class="w-full bg-slate-700 border border-slate-600 rounded px-3 py-2"
                               placeholder="ID do produto">
                    </div>
                </div>
                <button type="submit" name="testar_integracao" 
                        class="bg-blue-600 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded">
                    Disparar Evento de Teste (Status: approved)
                </button>
            </form>
        </div>

        <!-- Últimos Logs -->
        <div class="bg-slate-800 rounded-lg p-6 border border-slate-700">
            <h2 class="text-xl font-bold mb-4 flex items-center gap-2">
                <i data-lucide="file-text" class="w-6 h-6 text-purple-500"></i>
                Últimos Logs (50 últimas linhas)
            </h2>
            <div class="code-block text-green-400 whitespace-pre-wrap max-h-96 overflow-y-auto">
                <?php echo htmlspecialchars($ultimos_logs); ?>
            </div>
        </div>

        <div class="mt-6 text-center text-gray-400 text-sm">
            <a href="/index?pagina=dashboard" class="text-blue-400 hover:underline">← Voltar ao Dashboard</a>
        </div>
    </div>

    <script>
        lucide.createIcons();
    </script>
</body>
</html>

