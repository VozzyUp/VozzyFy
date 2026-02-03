<?php
// Este arquivo é incluído a partir do index.php,
// então a verificação de login e a conexão com o banco ($pdo) já existem.

// Incluir helper de segurança para funções CSRF
require_once __DIR__ . '/../helpers/security_helper.php';

// Obter o ID do usuário logado
$usuario_id_logado = $_SESSION['id'] ?? 0;

// Se por algum motivo o ID do usuário não estiver definido, redireciona para o login
if ($usuario_id_logado === 0) {
    header("location: /login");
    exit;
}

$mensagem = '';
$produto = null;
$curso = null;
$upload_dir = 'uploads/';

// 1. Validar e buscar o produto_id
if (!isset($_GET['produto_id']) || !is_numeric($_GET['produto_id'])) {
    header("Location: /index?pagina=area_membros");
    exit;
}
$produto_id = (int)$_GET['produto_id'];

try {
    // 2. Buscar o produto e verificar se é do tipo 'area_membros' E pertence ao usuário logado
    $stmt_produto = $pdo->prepare("SELECT * FROM produtos WHERE id = ? AND tipo_entrega = 'area_membros' AND usuario_id = ?");
    $stmt_produto->execute([$produto_id, $usuario_id_logado]);
    $produto = $stmt_produto->fetch(PDO::FETCH_ASSOC);

    if (!$produto) {
        // Se o produto não for encontrado ou não pertencer ao usuário, redireciona
        $_SESSION['flash_message'] = "<div class='bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded relative mb-4' role='alert'>Produto não encontrado ou você não tem permissão para acessá-lo.</div>";
        header("Location: /index?pagina=area_membros");
        exit;
    }

    // 3. Sincronizar com a tabela 'cursos'
    $stmt_curso = $pdo->prepare("SELECT * FROM cursos WHERE produto_id = ?");
    $stmt_curso->execute([$produto_id]);
    $curso = $stmt_curso->fetch(PDO::FETCH_ASSOC);

    if (!$curso) {
        // Se o curso não existe, cria um novo
        $stmt_insert_curso = $pdo->prepare("INSERT INTO cursos (produto_id, titulo, descricao, imagem_url) VALUES (?, ?, ?, ?)");
        $stmt_insert_curso->execute([$produto_id, $produto['nome'], $produto['descricao'], $produto['foto'] ? 'uploads/' . $produto['foto'] : null]);
        
        // Busca o curso recém-criado
        $stmt_curso->execute([$produto_id]);
        $curso = $stmt_curso->fetch(PDO::FETCH_ASSOC);
    }
    $curso_id = $curso['id'];

    // 4. Lógica de manipulação de dados (POST requests)
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Verifica CSRF
        if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
            $_SESSION['flash_message'] = "<div class='bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded relative mb-4' role='alert'>Token CSRF inválido ou ausente.</div>";
            header("Location: /index?pagina=gerenciar_comunidade&produto_id=" . $produto_id);
            exit;
        }
        
        $should_redirect = false; // Flag para controlar o redirecionamento

        // Função auxiliar para upload de arquivos (segura)
        function handle_file_upload($file_key, $target_dir, $current_file_path = null, $max_mb = 5) {
            require_once __DIR__ . '/../helpers/security_helper.php';
            
            if (isset($_FILES[$file_key]) && $_FILES[$file_key]['error'] === UPLOAD_ERR_OK) {
                // Deleta o arquivo antigo se existir
                if ($current_file_path && file_exists($current_file_path) && strpos($current_file_path, 'uploads/') === 0) {
                    @unlink($current_file_path);
                }
                
                // Valida e faz upload seguro
                $upload_result = validate_image_upload($_FILES[$file_key], $target_dir, $file_key, $max_mb, true);
                if ($upload_result['success']) {
                    return $upload_result['file_path'];
                }
            }
            return null;
        }

        // Salvar Configurações da Comunidade (community_enabled)
        if (isset($_POST['salvar_config_comunidade'])) {
            $should_redirect = true;
            $community_enabled = isset($_POST['community_enabled']) ? 1 : 0;
            try {
                $stmt = $pdo->query("SHOW COLUMNS FROM cursos LIKE 'community_enabled'");
                if ($stmt->rowCount() > 0) {
                    $pdo->prepare("UPDATE cursos SET community_enabled = ? WHERE id = ?")->execute([$community_enabled, $curso_id]);
                }
                $curso['community_enabled'] = $community_enabled;
                $mensagem = "<div class='px-4 py-3 rounded' role='alert' style='background-color: color-mix(in srgb, var(--accent-primary) 20%, transparent); border: 1px solid var(--accent-primary); color: var(--accent-primary);'>Configurações da comunidade salvas!</div>";
            } catch (PDOException $e) {
                $mensagem = "<div class='bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded' role='alert'>Erro ao salvar configurações da comunidade.</div>";
            }
        }

        // Salvar Banner da Comunidade
        if (isset($_POST['salvar_banner_comunidade'])) {
            $should_redirect = true;
            $has_comunidade_banner_col = false;
            try {
                $chk = $pdo->query("SHOW COLUMNS FROM cursos LIKE 'comunidade_banner_url'");
                $has_comunidade_banner_col = $chk->rowCount() > 0;
            } catch (PDOException $e) {}

            if ($has_comunidade_banner_col) {
                $comunidade_banner_url = $curso['comunidade_banner_url'] ?? null;
                
                // Remover banner se solicitado
                if (!empty($_POST['remove_banner_comunidade']) && $comunidade_banner_url && file_exists($comunidade_banner_url) && strpos($comunidade_banner_url, 'uploads/') === 0) {
                    @unlink($comunidade_banner_url);
                    $pdo->prepare("UPDATE cursos SET comunidade_banner_url = ? WHERE id = ?")->execute([null, $curso_id]);
                    $curso['comunidade_banner_url'] = null;
                    $mensagem = "<div class='px-4 py-3 rounded' role='alert' style='background-color: color-mix(in srgb, var(--accent-primary) 20%, transparent); border: 1px solid var(--accent-primary); color: var(--accent-primary);'>Banner da comunidade removido!</div>";
                } else {
                    // Upload de novo banner
                    $novo_banner = handle_file_upload('banner_comunidade', $upload_dir, $comunidade_banner_url, 15);
                    if ($novo_banner) {
                        $pdo->prepare("UPDATE cursos SET comunidade_banner_url = ? WHERE id = ?")->execute([$novo_banner, $curso_id]);
                        $curso['comunidade_banner_url'] = $novo_banner;
                        $mensagem = "<div class='px-4 py-3 rounded' role='alert' style='background-color: color-mix(in srgb, var(--accent-primary) 20%, transparent); border: 1px solid var(--accent-primary); color: var(--accent-primary);'>Banner da comunidade atualizado!</div>";
                    } else {
                        if (empty($mensagem)) {
                            $mensagem = "<div class='bg-yellow-900/20 border border-yellow-500 text-yellow-300 px-4 py-3 rounded' role='alert'>Nenhuma alteração de imagem enviada.</div>";
                        }
                    }
                }
            } else {
                $mensagem = "<div class='bg-yellow-900/20 border border-yellow-500 text-yellow-300 px-4 py-3 rounded' role='alert'>Coluna comunidade_banner_url não existe na tabela cursos.</div>";
            }
        }

        // Comunidade: adicionar categoria
        if (isset($_POST['comunidade_adicionar_categoria'])) {
            $should_redirect = true;
            $cat_nome = trim($_POST['categoria_nome'] ?? '');
            $is_public = isset($_POST['categoria_public_posting']) ? 1 : 0;
            try {
                $stmt = $pdo->query("SHOW TABLES LIKE 'comunidade_categorias'");
                if ($stmt->rowCount() > 0 && $cat_nome !== '') {
                    $max_ordem = $pdo->prepare("SELECT COALESCE(MAX(ordem), 0) + 1 FROM comunidade_categorias WHERE curso_id = ?");
                    $max_ordem->execute([$curso_id]);
                    $ordem = (int)$max_ordem->fetchColumn();
                    $pdo->prepare("INSERT INTO comunidade_categorias (curso_id, nome, is_public_posting, ordem) VALUES (?, ?, ?, ?)")->execute([$curso_id, $cat_nome, $is_public, $ordem]);
                    $mensagem = "<div class='px-4 py-3 rounded' role='alert' style='background-color: color-mix(in srgb, var(--accent-primary) 20%, transparent); border: 1px solid var(--accent-primary); color: var(--accent-primary);'>Categoria do feed adicionada!</div>";
                }
            } catch (PDOException $e) {
                $mensagem = "<div class='bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded' role='alert'>Erro ao adicionar categoria.</div>";
            }
        }

        // Comunidade: editar categoria
        if (isset($_POST['comunidade_editar_categoria'])) {
            $should_redirect = true;
            $cat_id = (int)($_POST['categoria_id'] ?? 0);
            $cat_nome = trim($_POST['categoria_nome'] ?? '');
            $is_public = isset($_POST['categoria_public_posting']) ? 1 : 0;
            try {
                $stmt = $pdo->query("SHOW TABLES LIKE 'comunidade_categorias'");
                if ($stmt->rowCount() > 0 && $cat_id > 0 && $cat_nome !== '') {
                    $pdo->prepare("UPDATE comunidade_categorias SET nome = ?, is_public_posting = ? WHERE id = ? AND curso_id = ?")->execute([$cat_nome, $is_public, $cat_id, $curso_id]);
                    $mensagem = "<div class='px-4 py-3 rounded' role='alert' style='background-color: color-mix(in srgb, var(--accent-primary) 20%, transparent); border: 1px solid var(--accent-primary); color: var(--accent-primary);'>Categoria atualizada!</div>";
                }
            } catch (PDOException $e) {
                $mensagem = "<div class='bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded' role='alert'>Erro ao atualizar categoria.</div>";
            }
        }

        // Comunidade: deletar categoria
        if (isset($_POST['comunidade_deletar_categoria'])) {
            $should_redirect = true;
            $cat_id = (int)($_POST['categoria_id'] ?? 0);
            try {
                $stmt = $pdo->query("SHOW TABLES LIKE 'comunidade_categorias'");
                if ($stmt->rowCount() > 0 && $cat_id > 0) {
                    $pdo->prepare("DELETE FROM comunidade_categorias WHERE id = ? AND curso_id = ?")->execute([$cat_id, $curso_id]);
                    $mensagem = "<div class='px-4 py-3 rounded' role='alert' style='background-color: color-mix(in srgb, var(--accent-primary) 20%, transparent); border: 1px solid var(--accent-primary); color: var(--accent-primary);'>Categoria removida.</div>";
                }
            } catch (PDOException $e) {
                $mensagem = "<div class='bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded' role='alert'>Erro ao remover categoria.</div>";
            }
        }

        // Redirecionamento após POST
        if ($should_redirect) {
            $_SESSION['flash_message'] = $mensagem;
            header("Location: /index?pagina=gerenciar_comunidade&produto_id=" . $produto_id);
            exit;
        }
    }
    
    // Pega a mensagem da sessão, se houver, e depois limpa
    if (isset($_SESSION['flash_message'])) {
        $mensagem = $_SESSION['flash_message'];
        unset($_SESSION['flash_message']);
    }

    // Recarregar curso para ter community_enabled (se coluna existir)
    $stmt_curso->execute([$produto_id]);
    $curso = $stmt_curso->fetch(PDO::FETCH_ASSOC);
    if (!isset($curso['community_enabled'])) $curso['community_enabled'] = 0;

    // Categorias da comunidade (se tabela existir)
    $comunidade_categorias = [];
    try {
        $stmt_t = $pdo->query("SHOW TABLES LIKE 'comunidade_categorias'");
        if ($stmt_t->rowCount() > 0) {
            $stmt_cat = $pdo->prepare("SELECT id, curso_id, nome, is_public_posting, ordem FROM comunidade_categorias WHERE curso_id = ? ORDER BY ordem ASC, id ASC");
            $stmt_cat->execute([$curso_id]);
            $comunidade_categorias = $stmt_cat->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        $comunidade_categorias = [];
    }

} catch (PDOException $e) {
    $mensagem = "<div class='bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded' role='alert'>Erro de banco de dados: " . htmlspecialchars($e->getMessage()) . "</div>";
}

?>

<?php
// Gerar token CSRF para uso nos formulários
$csrf_token = generate_csrf_token();
?>

<style>
/* Estilos para inputs de texto */
.form-input-style,
input[type="text"],
input[type="email"],
input[type="number"],
textarea {
    background-color: #1a1f24 !important;
    border: 1px solid rgba(255, 255, 255, 0.1) !important;
    color: #ffffff !important;
    border-radius: 0.5rem;
}

.form-input-style:focus,
input[type="text"]:focus,
input[type="email"]:focus,
input[type="number"]:focus,
textarea:focus {
    outline: none !important;
    border-color: var(--accent-primary) !important;
    box-shadow: 0 0 0 3px rgba(50, 231, 104, 0.1) !important;
}

.form-input-style::placeholder,
input[type="text"]::placeholder,
input[type="email"]::placeholder,
input[type="number"]::placeholder,
textarea::placeholder {
    color: #6b7280 !important;
}

/* Estilos para inputs de arquivo - botão com texto preto */
input[type="file"] {
    color: #9ca3af;
}

input[type="file"]::file-selector-button {
    background-color: #d1d5db !important;
    color: #000000 !important;
    border: none;
    padding: 0.5rem 0.75rem;
    margin-right: 0.5rem;
    border-radius: 0.5rem;
    font-weight: 600;
    font-size: 0.875rem;
    cursor: pointer;
    transition: background-color 0.2s;
}

input[type="file"]::file-selector-button:hover {
    background-color: #9ca3af !important;
}
</style>

<div class="container mx-auto max-w-7xl">
    <div class="flex items-center justify-between mb-4">
        <a href="/index?pagina=gerenciar_curso&produto_id=<?php echo $produto_id; ?>" style="color: var(--accent-primary);" onmouseover="this.style.color='var(--accent-primary-hover)'" onmouseout="this.style.color='var(--accent-primary)'" class="mr-4">
            <i data-lucide="arrow-left-circle" class="w-7 h-7"></i>
        </a>
        <h1 class="text-xl font-bold text-white">Configurações da Comunidade: <?php echo htmlspecialchars($curso['titulo'] ?? 'Curso'); ?></h1>
        <a href="/member_course_view?produto_id=<?php echo (int)$produto_id; ?>" target="_blank" class="text-sm text-gray-400 hover:text-white transition">Ver como aluno <i data-lucide="external-link" class="w-4 h-4 inline"></i></a>
    </div>

    <?php if ($mensagem) echo "<div class='mb-6'>$mensagem</div>"; ?>

    <!-- Habilitar Comunidade -->
    <section class="bg-dark-card rounded-xl border border-dark-border p-6 mb-6">
        <h2 class="text-lg font-bold text-white mb-4">Habilitar Comunidade</h2>
        <form action="/index?pagina=gerenciar_comunidade&produto_id=<?php echo $produto_id; ?>" method="post" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <label class="flex items-center gap-3 p-3 rounded-lg border border-dark-border bg-dark-elevated/50 hover:border-gray-600 transition cursor-pointer">
                <input type="checkbox" name="community_enabled" value="1" class="w-5 h-5 rounded border-dark-border bg-dark-card text-[var(--accent-primary)] focus:ring-[var(--accent-primary)] focus:ring-offset-0 focus:ring-2" <?php echo !empty($curso['community_enabled']) ? 'checked' : ''; ?>>
                <span class="text-gray-200 text-sm font-medium">Ativar comunidade (feed)</span>
                <span class="ml-auto text-gray-500 text-xs">Feed de publicações</span>
            </label>
            <button type="submit" name="salvar_config_comunidade" class="w-full text-white text-sm font-semibold py-2.5 px-4 rounded-lg transition" style="background-color: var(--accent-primary);">Salvar configuração</button>
        </form>
    </section>

    <?php if (!empty($curso['community_enabled'])): ?>
    <!-- Banner da Comunidade -->
    <section class="bg-dark-card rounded-xl border border-dark-border p-6 mb-6">
        <h2 class="text-lg font-bold text-white mb-4">Banner da Comunidade</h2>
        <form action="/index?pagina=gerenciar_comunidade&produto_id=<?php echo $produto_id; ?>" method="post" enctype="multipart/form-data" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <?php
            $comunidade_banner = $curso['comunidade_banner_url'] ?? null;
            if ($comunidade_banner && file_exists($comunidade_banner)):
            ?>
            <div class="mb-4">
                <img src="<?php echo htmlspecialchars($comunidade_banner); ?>" alt="Banner Comunidade" class="w-full max-h-64 object-cover rounded-lg border border-dark-border">
                <label class="mt-2 flex items-center gap-2 text-sm text-gray-400 cursor-pointer">
                    <input type="checkbox" name="remove_banner_comunidade" value="1" class="w-4 h-4 rounded border-dark-border bg-dark-elevated text-red-400 focus:ring-red-500 focus:ring-offset-0 focus:ring-2"> Remover banner
                </label>
            </div>
            <?php endif; ?>
            <input type="file" name="banner_comunidade" accept="image/*" class="w-full text-sm text-gray-400 file:mr-2 file:py-2 file:px-3 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:cursor-pointer file:bg-gray-300 file:text-black hover:file:bg-gray-400">
            <p class="text-xs text-gray-500">Máximo 15MB. Recomendado: 1920x600px</p>
            <button type="submit" name="salvar_banner_comunidade" class="w-full text-white text-sm font-semibold py-2.5 px-4 rounded-lg transition" style="background-color: var(--accent-primary);">Salvar banner</button>
        </form>
    </section>

    <!-- Gerenciar Categorias (Páginas) -->
    <section class="bg-dark-card rounded-xl border border-dark-border p-6 mb-6">
        <h2 class="text-lg font-bold text-white mb-4">Categorias (Páginas) da Comunidade</h2>
        <form action="/index?pagina=gerenciar_comunidade&produto_id=<?php echo $produto_id; ?>" method="post" class="flex flex-wrap gap-2 items-end mb-4">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="text" name="categoria_nome" placeholder="Nome da categoria" required class="px-3 py-2 text-sm flex-1 min-w-0 bg-dark-elevated border border-dark-border text-white rounded-lg focus:outline-none focus:ring-2 focus:ring-[var(--accent-primary)] focus:border-transparent">
            <label class="flex items-center gap-2 cursor-pointer text-sm text-gray-400"><input type="checkbox" name="categoria_public_posting" value="1" class="w-4 h-4 rounded border-dark-border bg-dark-card text-[var(--accent-primary)] focus:ring-[var(--accent-primary)] focus:ring-offset-0"> Postagem pública</label>
            <button type="submit" name="comunidade_adicionar_categoria" class="text-white text-sm font-semibold py-2 px-3 rounded-lg" style="background-color: var(--accent-primary);">Adicionar</button>
        </form>
        <?php if (!empty($comunidade_categorias)): ?>
        <ul class="space-y-2">
            <?php foreach ($comunidade_categorias as $cat): ?>
            <li class="flex justify-between items-center p-3 bg-dark-elevated rounded-lg border border-dark-border">
                <div class="flex-1">
                    <span class="text-white text-sm font-medium"><?php echo htmlspecialchars($cat['nome']); ?></span>
                    <span class="ml-2 text-xs text-gray-500"><?php echo $cat['is_public_posting'] ? '(Pública)' : '(Privada)'; ?></span>
                </div>
                <div class="flex items-center gap-1">
                    <button type="button" class="edit-cat-comunidade-btn p-1.5 rounded text-blue-400 hover:bg-blue-400/20" data-cat-id="<?php echo (int)$cat['id']; ?>" data-nome="<?php echo htmlspecialchars($cat['nome']); ?>" data-public="<?php echo (int)$cat['is_public_posting']; ?>"><i data-lucide="edit" class="w-4 h-4"></i></button>
                    <form action="/index?pagina=gerenciar_comunidade&produto_id=<?php echo $produto_id; ?>" method="post" onsubmit="return confirm('Remover esta categoria?');" class="inline">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <input type="hidden" name="categoria_id" value="<?php echo (int)$cat['id']; ?>">
                        <button type="submit" name="comunidade_deletar_categoria" class="p-1.5 rounded text-red-400 hover:bg-red-400/20"><i data-lucide="trash-2" class="w-4 h-4"></i></button>
                    </form>
                </div>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php else: ?>
        <p class="text-gray-500 text-sm">Nenhuma categoria. Adicione acima.</p>
        <?php endif; ?>
    </section>

    <!-- Moderar Posts -->
    <section class="bg-dark-card rounded-xl border border-dark-border p-6 mb-6">
        <h2 class="text-lg font-bold text-white mb-4">Moderar Posts</h2>
        <div id="moderacao-posts-container" class="space-y-4">
            <p class="text-gray-400 text-sm">Carregando posts...</p>
        </div>
    </section>
    <?php else: ?>
    <div class="bg-dark-card rounded-xl border border-dark-border p-6 text-center text-gray-400 mb-6">
        <p class="text-sm">Habilite a comunidade acima para configurar categorias e banner.</p>
    </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    lucide.createIcons();

    const currentProductId = <?php echo $produto_id; ?>;
    const cursoId = <?php echo $curso_id; ?>;

    // Carregar posts de moderação
    function loadModerationPosts() {
        const container = document.getElementById('moderacao-posts-container');
        if (!container) {
            console.error('Container de moderação não encontrado!');
            return;
        }
        
        container.innerHTML = '<p class="text-gray-400 text-sm">Carregando posts...</p>';
        
        fetch(`/api/comunidade_posts.php?action=list_all&curso_id=${cursoId}`)
            .then(response => response.json())
            .then(data => {
                if (data.success && data.posts && data.posts.length > 0) {
                    let html = '<div class="space-y-4">';
                    data.posts.forEach(post => {
                        const categoriaNome = post.categoria_nome || 'Sem categoria';
                        const autorTipo = post.autor_tipo === 'infoprodutor' ? 'Infoprodutor' : 'Aluno';
                        const dataPost = new Date(post.created_at).toLocaleString('pt-BR');
                        const hasImage = post.anexo_url ? `<img src="${post.anexo_url}" alt="Anexo" class="mt-2 max-w-full h-auto rounded-lg border border-dark-border">` : '';
                        
                        html += `
                            <div class="bg-dark-elevated rounded-lg border border-dark-border p-4">
                                <div class="flex justify-between items-start mb-2">
                                    <div class="flex-1">
                                        <div class="flex items-center gap-2 mb-1">
                                            <span class="text-white font-semibold text-sm">${post.autor_nome || post.autor_email}</span>
                                            <span class="text-xs px-2 py-0.5 rounded ${post.autor_tipo === 'infoprodutor' ? 'bg-blue-500/20 text-blue-400' : 'bg-gray-500/20 text-gray-400'}">${autorTipo}</span>
                                            <span class="text-xs text-gray-500">${categoriaNome}</span>
                                        </div>
                                        <p class="text-xs text-gray-500">${dataPost}</p>
                                    </div>
                                    <button onclick="deletePost(${post.id})" class="p-1.5 rounded text-red-400 hover:bg-red-400/20 transition" title="Deletar post">
                                        <i data-lucide="trash-2" class="w-4 h-4"></i>
                                    </button>
                                </div>
                                <div class="text-gray-300 text-sm whitespace-pre-wrap">${post.conteudo || ''}</div>
                                ${hasImage}
                            </div>
                        `;
                    });
                    html += '</div>';
                    container.innerHTML = html;
                    if (typeof lucide !== 'undefined' && lucide.createIcons) lucide.createIcons();
                } else {
                    container.innerHTML = '<p class="text-gray-500 text-sm">Nenhum post encontrado.</p>';
                }
            })
            .catch(error => {
                console.error('Erro ao carregar posts:', error);
                container.innerHTML = '<p class="text-red-400 text-sm">Erro ao carregar posts. Tente novamente.</p>';
            });
    }

    // Função global para deletar post
    window.deletePost = function(postId) {
        if (!confirm('Tem certeza que deseja deletar este post? Esta ação não pode ser desfeita.')) {
            return;
        }
        
        const csrfToken = document.querySelector('input[name="csrf_token"]')?.value || '';
        
        fetch('/api/comunidade_posts.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: new URLSearchParams({
                action: 'delete',
                post_id: postId,
                csrf_token: csrfToken
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                loadModerationPosts();
            } else {
                alert('Erro ao deletar post: ' + (data.error || 'Erro desconhecido'));
            }
        })
        .catch(error => {
            console.error('Erro:', error);
            alert('Erro ao deletar post. Tente novamente.');
        });
    };

    // Carregar posts ao carregar a página
    loadModerationPosts();

    // Modal para editar categoria
    const editCatBtns = document.querySelectorAll('.edit-cat-comunidade-btn');
    editCatBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            const catId = this.dataset.catId;
            const catNome = this.dataset.nome;
            const isPublic = this.dataset.public === '1';
            
            const novoNome = prompt('Nome da categoria:', catNome);
            if (novoNome === null || novoNome.trim() === '') return;
            
            const novoIsPublic = confirm('Postagem pública? (OK = Sim, Cancelar = Não)');
            
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = `/index?pagina=gerenciar_comunidade&produto_id=${currentProductId}`;
            
            const csrfInput = document.createElement('input');
            csrfInput.type = 'hidden';
            csrfInput.name = 'csrf_token';
            csrfInput.value = document.querySelector('input[name="csrf_token"]')?.value || '';
            form.appendChild(csrfInput);
            
            const catIdInput = document.createElement('input');
            catIdInput.type = 'hidden';
            catIdInput.name = 'categoria_id';
            catIdInput.value = catId;
            form.appendChild(catIdInput);
            
            const nomeInput = document.createElement('input');
            nomeInput.type = 'hidden';
            nomeInput.name = 'categoria_nome';
            nomeInput.value = novoNome.trim();
            form.appendChild(nomeInput);
            
            const publicInput = document.createElement('input');
            publicInput.type = 'hidden';
            publicInput.name = 'categoria_public_posting';
            if (novoIsPublic) {
                publicInput.value = '1';
                form.appendChild(publicInput);
            }
            
            const submitInput = document.createElement('input');
            submitInput.type = 'hidden';
            submitInput.name = 'comunidade_editar_categoria';
            submitInput.value = '1';
            form.appendChild(submitInput);
            
            document.body.appendChild(form);
            form.submit();
        });
    });
});
</script>

