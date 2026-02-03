<?php
// Este arquivo é incluído dentro de admin.php

require_once __DIR__ . '/../../helpers/conquistas_helper.php';
require_once __DIR__ . '/../../helpers/security_helper.php';

// Processar ações
$mensagem = '';
$mensagem_tipo = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';
    
    if ($acao === 'criar' || $acao === 'editar') {
        $id = $acao === 'editar' ? (int)($_POST['id'] ?? 0) : 0;
        $nome = trim($_POST['nome'] ?? '');
        $descricao = trim($_POST['descricao'] ?? '');
        $valor_minimo = (float)($_POST['valor_minimo'] ?? 0);
        $valor_maximo = !empty($_POST['valor_maximo']) ? (float)$_POST['valor_maximo'] : null;
        $ordem = (int)($_POST['ordem'] ?? 0);
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        // Validações
        if (empty($nome)) {
            $mensagem = 'Nome da conquista é obrigatório.';
            $mensagem_tipo = 'error';
        } elseif ($valor_maximo !== null && $valor_maximo <= $valor_minimo) {
            $mensagem = 'Valor máximo deve ser maior que o valor mínimo.';
            $mensagem_tipo = 'error';
        } else {
            try {
                // Upload de imagem
                $imagem_badge = null;
                if (isset($_FILES['imagem_badge']) && $_FILES['imagem_badge']['error'] === UPLOAD_ERR_OK) {
                    $file = $_FILES['imagem_badge'];
                    $allowed_types = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
                    $max_size = 2 * 1024 * 1024; // 2MB
                    
                    if (!in_array($file['type'], $allowed_types)) {
                        throw new Exception('Tipo de arquivo não permitido. Use JPG, PNG, WEBP ou GIF.');
                    }
                    
                    if ($file['size'] > $max_size) {
                        throw new Exception('Arquivo muito grande. Tamanho máximo: 2MB.');
                    }
                    
                    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
                    $filename = 'badge_' . time() . '_' . uniqid() . '.' . $ext;
                    $upload_path = __DIR__ . '/../../uploads/badges/' . $filename;
                    
                    if (!move_uploaded_file($file['tmp_name'], $upload_path)) {
                        throw new Exception('Erro ao fazer upload da imagem.');
                    }
                    
                    $imagem_badge = 'uploads/badges/' . $filename;
                    
                    // Se estiver editando e tiver imagem antiga, deletar
                    if ($id > 0) {
                        $stmt_old = $pdo->prepare("SELECT imagem_badge FROM conquistas WHERE id = ?");
                        $stmt_old->execute([$id]);
                        $old = $stmt_old->fetch(PDO::FETCH_ASSOC);
                        if ($old && $old['imagem_badge'] && file_exists(__DIR__ . '/../../' . $old['imagem_badge'])) {
                            @unlink(__DIR__ . '/../../' . $old['imagem_badge']);
                        }
                    }
                } elseif ($acao === 'editar' && $id > 0) {
                    // Manter imagem existente se não houver upload
                    $stmt_old = $pdo->prepare("SELECT imagem_badge FROM conquistas WHERE id = ?");
                    $stmt_old->execute([$id]);
                    $old = $stmt_old->fetch(PDO::FETCH_ASSOC);
                    $imagem_badge = $old['imagem_badge'] ?? null;
                }
                
                if ($acao === 'criar') {
                    $stmt = $pdo->prepare("
                        INSERT INTO conquistas (nome, descricao, valor_minimo, valor_maximo, imagem_badge, ordem, is_active)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([$nome, $descricao, $valor_minimo, $valor_maximo, $imagem_badge, $ordem, $is_active]);
                    $mensagem = 'Conquista criada com sucesso!';
                    $mensagem_tipo = 'success';
                } else {
                    if ($imagem_badge) {
                        $stmt = $pdo->prepare("
                            UPDATE conquistas 
                            SET nome = ?, descricao = ?, valor_minimo = ?, valor_maximo = ?, imagem_badge = ?, ordem = ?, is_active = ?
                            WHERE id = ?
                        ");
                        $stmt->execute([$nome, $descricao, $valor_minimo, $valor_maximo, $imagem_badge, $ordem, $is_active, $id]);
                    } else {
                        $stmt = $pdo->prepare("
                            UPDATE conquistas 
                            SET nome = ?, descricao = ?, valor_minimo = ?, valor_maximo = ?, ordem = ?, is_active = ?
                            WHERE id = ?
                        ");
                        $stmt->execute([$nome, $descricao, $valor_minimo, $valor_maximo, $ordem, $is_active, $id]);
                    }
                    $mensagem = 'Conquista atualizada com sucesso!';
                    $mensagem_tipo = 'success';
                }
            } catch (Exception $e) {
                $mensagem = 'Erro: ' . $e->getMessage();
                $mensagem_tipo = 'error';
            }
        }
    } elseif ($acao === 'deletar') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            try {
                // Deletar imagem
                $stmt_img = $pdo->prepare("SELECT imagem_badge FROM conquistas WHERE id = ?");
                $stmt_img->execute([$id]);
                $img = $stmt_img->fetch(PDO::FETCH_ASSOC);
                if ($img && $img['imagem_badge'] && file_exists(__DIR__ . '/../../' . $img['imagem_badge'])) {
                    @unlink(__DIR__ . '/../../' . $img['imagem_badge']);
                }
                
                $stmt = $pdo->prepare("DELETE FROM conquistas WHERE id = ?");
                $stmt->execute([$id]);
                $mensagem = 'Conquista deletada com sucesso!';
                $mensagem_tipo = 'success';
            } catch (Exception $e) {
                $mensagem = 'Erro ao deletar: ' . $e->getMessage();
                $mensagem_tipo = 'error';
            }
        }
    }
}

// Buscar todas as conquistas
try {
    $stmt = $pdo->query("SELECT * FROM conquistas ORDER BY ordem ASC, valor_minimo ASC");
    $conquistas = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $conquistas = [];
    $mensagem = 'Erro ao buscar conquistas: ' . $e->getMessage();
    $mensagem_tipo = 'error';
}

// Conquista para edição (se houver)
$conquista_edit = null;
if (isset($_GET['editar'])) {
    $id_edit = (int)$_GET['editar'];
    try {
        $stmt = $pdo->prepare("SELECT * FROM conquistas WHERE id = ?");
        $stmt->execute([$id_edit]);
        $conquista_edit = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $mensagem = 'Erro ao buscar conquista: ' . $e->getMessage();
        $mensagem_tipo = 'error';
    }
}

$csrf_token_js = generate_csrf_token();
?>

<meta name="csrf-token" content="<?php echo htmlspecialchars($csrf_token_js); ?>">
<script>
    window.csrfToken = '<?php echo htmlspecialchars($csrf_token_js); ?>';
</script>
<style>
    .form-input-style { 
        width: 100%;
        padding: 0.75rem 1rem;
        background-color: #0f1419 !important;
        border: 1px solid rgba(255, 255, 255, 0.1) !important;
        border-radius: 0.5rem;
        color: white !important;
    }
    .form-input-style:focus {
        outline: none !important;
        border-color: var(--accent-primary) !important;
        box-shadow: 0 0 0 3px rgba(50, 231, 104, 0.1) !important;
    }
    .form-input-style::placeholder {
        color: #6b7280 !important;
    }
    .form-input-style option {
        background-color: #0f1419 !important;
        color: white !important;
    }
    input[type="date"].form-input-style,
    input[type="email"].form-input-style,
    input[type="text"].form-input-style,
    input[type="number"].form-input-style,
    input[type="password"].form-input-style,
    input[type="tel"].form-input-style,
    select.form-input-style,
    textarea.form-input-style {
        color-scheme: dark;
    }
</style>

<div class="flex items-center justify-between mb-6">
    <div>
        <h1 class="text-3xl font-bold text-white">Gerenciar Conquistas</h1>
        <p class="text-gray-400 mt-1">Configure as conquistas baseadas em faturamento para gamificar a plataforma.</p>
    </div>
    <a href="/admin?pagina=admin_dashboard" class="bg-dark-elevated text-gray-300 font-bold py-2 px-4 rounded-lg hover:bg-dark-card transition duration-300 flex items-center space-x-2 border border-dark-border">
        <i data-lucide="arrow-left" class="w-5 h-5"></i>
        <span>Voltar ao Dashboard</span>
    </a>
</div>

<?php if ($mensagem): ?>
<div class="mb-4 px-4 py-3 rounded relative <?php echo $mensagem_tipo === 'success' ? 'bg-green-900/30 border border-green-500 text-green-300' : 'bg-red-900/30 border border-red-500 text-red-300'; ?>">
    <?php echo htmlspecialchars($mensagem); ?>
</div>
<?php endif; ?>

<!-- Formulário de Criar/Editar -->
<div class="bg-dark-card p-8 rounded-lg shadow-md mb-6 border border-dark-border">
    <h2 class="text-2xl font-semibold mb-6 text-white flex items-center gap-2">
        <i data-lucide="<?php echo $conquista_edit ? 'edit' : 'plus'; ?>" class="w-6 h-6" style="color: var(--accent-primary);"></i>
        <span><?php echo $conquista_edit ? 'Editar Conquista' : 'Nova Conquista'; ?></span>
    </h2>
    
    <form method="POST" enctype="multipart/form-data" class="space-y-6">
        <input type="hidden" name="acao" value="<?php echo $conquista_edit ? 'editar' : 'criar'; ?>">
        <?php if ($conquista_edit): ?>
        <input type="hidden" name="id" value="<?php echo $conquista_edit['id']; ?>">
        <?php endif; ?>
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
                <label class="block text-gray-300 text-sm font-semibold mb-2">Nome da Conquista *</label>
                <input type="text" name="nome" value="<?php echo htmlspecialchars($conquista_edit['nome'] ?? ''); ?>" required
                       class="form-input-style" placeholder="Ex: Primeiro Milhão">
            </div>
            
            <div>
                <label class="block text-gray-300 text-sm font-semibold mb-2">Ordem de Exibição</label>
                <input type="number" name="ordem" value="<?php echo $conquista_edit['ordem'] ?? 0; ?>" min="0"
                       class="form-input-style" placeholder="0">
                <p class="text-xs text-gray-400 mt-1">Menor número aparece primeiro</p>
            </div>
        </div>
        
        <div>
            <label class="block text-gray-300 text-sm font-semibold mb-2">Descrição</label>
            <textarea name="descricao" rows="3" class="form-input-style" placeholder="Descreva a conquista..."><?php echo htmlspecialchars($conquista_edit['descricao'] ?? ''); ?></textarea>
        </div>
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
                <label class="block text-gray-300 text-sm font-semibold mb-2">Valor Mínimo (R$) *</label>
                <input type="number" name="valor_minimo" value="<?php echo $conquista_edit['valor_minimo'] ?? 0; ?>" step="0.01" min="0" required
                       class="form-input-style" placeholder="0.00">
            </div>
            
            <div>
                <label class="block text-gray-300 text-sm font-semibold mb-2">Valor Máximo (R$)</label>
                <input type="number" name="valor_maximo" value="<?php echo $conquista_edit['valor_maximo'] ?? ''; ?>" step="0.01" min="0"
                       class="form-input-style" placeholder="Deixe vazio para última conquista">
                <p class="text-xs text-gray-400 mt-1">Deixe vazio se for a última conquista</p>
            </div>
        </div>
        
        <div>
            <label class="block text-gray-300 text-sm font-semibold mb-2">Imagem do Badge</label>
            <input type="file" name="imagem_badge" accept="image/jpeg,image/png,image/webp,image/gif"
                   class="form-input-style">
            <p class="text-xs text-gray-400 mt-1">Formatos: JPG, PNG, WEBP, GIF. Máximo: 2MB</p>
            <?php if ($conquista_edit && $conquista_edit['imagem_badge']): ?>
            <div class="mt-2">
                <p class="text-xs text-gray-400 mb-1">Imagem atual:</p>
                <img src="/<?php echo htmlspecialchars($conquista_edit['imagem_badge']); ?>" alt="Badge" class="w-24 h-24 object-cover rounded-lg border border-dark-border">
            </div>
            <?php endif; ?>
        </div>
        
        <div class="flex items-center">
            <input type="checkbox" name="is_active" id="is_active" <?php echo (!$conquista_edit || $conquista_edit['is_active']) ? 'checked' : ''; ?>
                   class="w-4 h-4 rounded border-dark-border bg-dark-elevated text-primary focus:ring-primary">
            <label for="is_active" class="ml-2 text-gray-300 text-sm">Conquista ativa</label>
        </div>
        
        <div class="flex gap-4">
            <button type="submit" class="text-white font-bold py-3 px-6 rounded-lg transition duration-300" style="background-color: var(--accent-primary);" onmouseover="this.style.backgroundColor='var(--accent-primary-hover)'" onmouseout="this.style.backgroundColor='var(--accent-primary)'">
                <i data-lucide="<?php echo $conquista_edit ? 'save' : 'plus'; ?>" class="w-5 h-5 inline-block mr-2"></i>
                <?php echo $conquista_edit ? 'Salvar Alterações' : 'Criar Conquista'; ?>
            </button>
            <?php if ($conquista_edit): ?>
            <a href="/admin?pagina=admin_conquistas" class="bg-dark-elevated text-gray-300 font-bold py-3 px-6 rounded-lg hover:bg-dark-card transition duration-300 border border-dark-border">
                Cancelar
            </a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Listagem de Conquistas -->
<div class="bg-dark-card p-8 rounded-lg shadow-md border border-dark-border">
    <h2 class="text-2xl font-semibold mb-6 text-white flex items-center gap-2">
        <i data-lucide="trophy" class="w-6 h-6" style="color: var(--accent-primary);"></i>
        <span>Conquistas Cadastradas</span>
    </h2>
    
    <?php if (empty($conquistas)): ?>
    <p class="text-gray-400 text-center py-8">Nenhuma conquista cadastrada ainda.</p>
    <?php else: ?>
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead>
                <tr class="border-b border-dark-border">
                    <th class="text-left py-3 px-4 text-gray-300 font-semibold">Ordem</th>
                    <th class="text-left py-3 px-4 text-gray-300 font-semibold">Badge</th>
                    <th class="text-left py-3 px-4 text-gray-300 font-semibold">Nome</th>
                    <th class="text-left py-3 px-4 text-gray-300 font-semibold">Faixa de Faturamento</th>
                    <th class="text-left py-3 px-4 text-gray-300 font-semibold">Status</th>
                    <th class="text-left py-3 px-4 text-gray-300 font-semibold">Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($conquistas as $conq): ?>
                <tr class="border-b border-dark-border hover:bg-dark-elevated transition">
                    <td class="py-3 px-4 text-gray-300"><?php echo $conq['ordem']; ?></td>
                    <td class="py-3 px-4">
                        <?php if ($conq['imagem_badge']): ?>
                        <img src="/<?php echo htmlspecialchars($conq['imagem_badge']); ?>" alt="Badge" class="w-12 h-12 object-cover rounded border border-dark-border">
                        <?php else: ?>
                        <div class="w-12 h-12 bg-dark-elevated rounded border border-dark-border flex items-center justify-center">
                            <i data-lucide="image" class="w-6 h-6 text-gray-500"></i>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td class="py-3 px-4">
                        <div class="text-white font-semibold"><?php echo htmlspecialchars($conq['nome']); ?></div>
                        <?php if ($conq['descricao']): ?>
                        <div class="text-xs text-gray-400 mt-1"><?php echo htmlspecialchars(substr($conq['descricao'], 0, 50)); ?><?php echo strlen($conq['descricao']) > 50 ? '...' : ''; ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="py-3 px-4 text-gray-300">
                        R$ <?php echo number_format($conq['valor_minimo'], 2, ',', '.'); ?>
                        <?php if ($conq['valor_maximo']): ?>
                        - R$ <?php echo number_format($conq['valor_maximo'], 2, ',', '.'); ?>
                        <?php else: ?>
                        <span class="text-gray-500">+</span>
                        <?php endif; ?>
                    </td>
                    <td class="py-3 px-4">
                        <?php if ($conq['is_active']): ?>
                        <span class="px-2 py-1 bg-green-900/30 text-green-300 rounded text-xs font-semibold">Ativa</span>
                        <?php else: ?>
                        <span class="px-2 py-1 bg-gray-900/30 text-gray-400 rounded text-xs font-semibold">Inativa</span>
                        <?php endif; ?>
                    </td>
                    <td class="py-3 px-4">
                        <div class="flex gap-2">
                            <a href="/admin?pagina=admin_conquistas&editar=<?php echo $conq['id']; ?>" 
                               class="p-2 bg-blue-900/30 text-blue-300 rounded hover:bg-blue-900/50 transition" title="Editar">
                                <i data-lucide="edit" class="w-4 h-4"></i>
                            </a>
                            <form method="POST" class="inline" onsubmit="return confirm('Tem certeza que deseja deletar esta conquista?');">
                                <input type="hidden" name="acao" value="deletar">
                                <input type="hidden" name="id" value="<?php echo $conq['id']; ?>">
                                <button type="submit" class="p-2 bg-red-900/30 text-red-300 rounded hover:bg-red-900/50 transition" title="Deletar">
                                    <i data-lucide="trash-2" class="w-4 h-4"></i>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    lucide.createIcons();
});
</script>

