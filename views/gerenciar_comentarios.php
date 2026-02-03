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
$curso_id = 0;

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

    // 3. Buscar o curso
    $stmt_curso = $pdo->prepare("SELECT * FROM cursos WHERE produto_id = ?");
    $stmt_curso->execute([$produto_id]);
    $curso = $stmt_curso->fetch(PDO::FETCH_ASSOC);

    if (!$curso) {
        $_SESSION['flash_message'] = "<div class='bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded relative mb-4' role='alert'>Curso não encontrado.</div>";
        header("Location: /index?pagina=area_membros");
        exit;
    }
    $curso_id = $curso['id'];

    // Verificar se comentários estão habilitados
    if (empty($curso['allow_comments'])) {
        $_SESSION['flash_message'] = "<div class='bg-yellow-900/20 border border-yellow-500 text-yellow-300 px-4 py-3 rounded relative mb-4' role='alert'>Comentários não estão habilitados para este curso. Habilite nas configurações do curso.</div>";
        header("Location: /index?pagina=gerenciar_curso&produto_id=" . $produto_id);
        exit;
    }

    // Buscar todas as aulas do curso para o filtro
    $aulas = [];
    try {
        $stmt_aulas = $pdo->prepare("
            SELECT a.id, a.titulo, m.titulo as modulo_titulo
            FROM aulas a
            JOIN modulos m ON a.modulo_id = m.id
            WHERE m.curso_id = ?
            ORDER BY m.ordem ASC, a.ordem ASC
        ");
        $stmt_aulas->execute([$curso_id]);
        $aulas = $stmt_aulas->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $aulas = [];
    }

} catch (PDOException $e) {
    $mensagem = "<div class='bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded' role='alert'>Erro de banco de dados: " . htmlspecialchars($e->getMessage()) . "</div>";
}

// Gerar token CSRF para uso nos formulários
$csrf_token = generate_csrf_token();
$csrf_token_js = generate_csrf_token();
?>

<meta name="csrf-token" content="<?php echo htmlspecialchars($csrf_token_js); ?>">
<script>
    window.csrfToken = '<?php echo htmlspecialchars($csrf_token_js); ?>';
</script>

<div class="container mx-auto max-w-7xl">
    <div class="flex items-center justify-between mb-4 flex-wrap gap-4">
        <a href="/index?pagina=gerenciar_curso&produto_id=<?php echo $produto_id; ?>" style="color: var(--accent-primary);" onmouseover="this.style.color='var(--accent-primary-hover)'" onmouseout="this.style.color='var(--accent-primary)'" class="mr-4">
            <i data-lucide="arrow-left-circle" class="w-7 h-7"></i>
        </a>
        <h1 class="text-xl font-bold text-white">Moderação de Comentários: <?php echo htmlspecialchars($curso['titulo'] ?? 'Curso'); ?></h1>
        <a href="/member_course_view?produto_id=<?php echo (int)$produto_id; ?>" target="_blank" class="text-sm text-gray-400 hover:text-white transition whitespace-nowrap">Ver como aluno <i data-lucide="external-link" class="w-4 h-4 inline"></i></a>
    </div>

    <?php if ($mensagem) echo "<div class='mb-6'>$mensagem</div>"; ?>

    <!-- Filtros -->
    <div class="bg-dark-card p-6 rounded-lg shadow-md mb-6" style="border-color: var(--accent-primary);">
        <div class="flex flex-col md:flex-row gap-4">
            <div class="flex-1">
                <label class="block text-gray-300 text-sm font-semibold mb-2">Filtrar por Status</label>
                <select id="filter-status" class="w-full px-4 py-2 bg-dark-elevated border border-dark-border rounded-lg text-white focus:outline-none focus:ring-2" style="--tw-ring-color: var(--accent-primary);">
                    <option value="all">Todos</option>
                    <option value="1">Aprovados</option>
                    <option value="0">Pendentes</option>
                </select>
            </div>
            <div class="flex-1">
                <label class="block text-gray-300 text-sm font-semibold mb-2">Filtrar por Aula</label>
                <select id="filter-aula" class="w-full px-4 py-2 bg-dark-elevated border border-dark-border rounded-lg text-white focus:outline-none focus:ring-2" style="--tw-ring-color: var(--accent-primary);">
                    <option value="all">Todas as aulas</option>
                    <?php foreach ($aulas as $aula): ?>
                        <option value="<?php echo (int)$aula['id']; ?>"><?php echo htmlspecialchars($aula['modulo_titulo'] . ' - ' . $aula['titulo']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="flex items-end">
                <button id="btn-filter" class="px-6 py-2 text-white rounded-lg transition" style="background-color: var(--accent-primary);" onmouseover="this.style.backgroundColor='var(--accent-primary-hover)'" onmouseout="this.style.backgroundColor='var(--accent-primary)'">
                    <i data-lucide="search" class="w-5 h-5 inline-block mr-2"></i>
                    Filtrar
                </button>
            </div>
        </div>
    </div>

    <!-- Lista de Comentários -->
    <div id="comments-container" class="space-y-4">
        <div class="bg-dark-card p-8 rounded-lg shadow-md text-center" style="border-color: var(--accent-primary);">
            <i data-lucide="loader" class="w-8 h-8 mx-auto mb-2 animate-spin" style="color: var(--accent-primary);"></i>
            <p class="text-gray-400">Carregando comentários...</p>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    lucide.createIcons();
    
    const cursoId = <?php echo $curso_id; ?>;
    const csrfToken = window.csrfToken || document.querySelector('meta[name="csrf-token"]')?.content || '';
    
    let currentStatus = 'all';
    let currentAula = 'all';
    
    function loadComments() {
        const container = document.getElementById('comments-container');
        container.innerHTML = '<div class="bg-dark-card p-8 rounded-lg shadow-md text-center" style="border-color: var(--accent-primary);"><i data-lucide="loader" class="w-8 h-8 mx-auto mb-2 animate-spin" style="color: var(--accent-primary);"></i><p class="text-gray-400">Carregando comentários...</p></div>';
        lucide.createIcons();
        
        let url = `/api/comentarios_aula.php?action=list_all&curso_id=${cursoId}`;
        if (currentStatus !== 'all') {
            url += `&status=${currentStatus}`;
        }
        if (currentAula !== 'all') {
            url += `&aula_id=${currentAula}`;
        }
        
        fetch(url, {
            method: 'GET',
            headers: {
                'X-CSRF-Token': csrfToken
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success && data.comments && data.comments.length > 0) {
                renderComments(data.comments);
            } else {
                container.innerHTML = '<div class="bg-dark-card p-8 rounded-lg shadow-md text-center" style="border-color: var(--accent-primary);"><i data-lucide="message-square" class="w-16 h-16 mx-auto mb-4 text-gray-600"></i><p class="text-gray-400">Nenhum comentário encontrado.</p></div>';
                lucide.createIcons();
            }
        })
        .catch(error => {
            console.error('Erro ao carregar comentários:', error);
            container.innerHTML = '<div class="bg-dark-card p-8 rounded-lg shadow-md text-center" style="border-color: var(--accent-primary);"><p class="text-red-400">Erro ao carregar comentários. Tente novamente.</p></div>';
        });
    }
    
    function renderComments(comments) {
        const container = document.getElementById('comments-container');
        
        if (comments.length === 0) {
            container.innerHTML = '<div class="bg-dark-card p-8 rounded-lg shadow-md text-center" style="border-color: var(--accent-primary);"><i data-lucide="message-square" class="w-16 h-16 mx-auto mb-4 text-gray-600"></i><p class="text-gray-400">Nenhum comentário encontrado com os filtros selecionados.</p></div>';
            lucide.createIcons();
            return;
        }
        
        let html = '<div class="space-y-4">';
        comments.forEach(comment => {
            const dataCriacao = new Date(comment.data_criacao).toLocaleString('pt-BR');
            const statusBadge = comment.aprovado == 1 
                ? '<span class="text-xs px-2 py-0.5 rounded" style="background-color: color-mix(in srgb, var(--accent-primary) 20%, transparent); color: var(--accent-primary);">Aprovado</span>'
                : '<span class="text-xs px-2 py-0.5 rounded bg-yellow-500/20 text-yellow-400">Pendente</span>';
            const textoEscaped = (comment.texto || '').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\n/g, '<br>');
            
            html += `
                <div class="bg-dark-elevated rounded-lg border border-dark-border p-4">
                    <div class="flex justify-between items-start mb-2">
                        <div class="flex-1">
                            <div class="flex items-center gap-2 mb-1">
                                <span class="text-white font-semibold text-sm">${escapeHtml(comment.autor_nome || comment.aluno_email || 'Anônimo')}</span>
                                ${statusBadge}
                                <span class="text-xs text-gray-500">${escapeHtml(comment.modulo_titulo || 'Módulo')} - ${escapeHtml(comment.aula_titulo || 'Aula')}</span>
                            </div>
                            <p class="text-xs text-gray-500">${dataCriacao}</p>
                        </div>
                        <div class="flex gap-2">
                            ${comment.aprovado == 0 ? `
                                <button onclick="approveComment(${comment.id})" class="p-1.5 rounded transition" style="color: var(--accent-primary);" onmouseover="this.style.backgroundColor='color-mix(in srgb, var(--accent-primary) 20%, transparent)'" onmouseout="this.style.backgroundColor='transparent'" title="Aprovar comentário">
                                    <i data-lucide="check" class="w-4 h-4"></i>
                                </button>
                            ` : `
                                <button onclick="rejectComment(${comment.id})" class="p-1.5 rounded text-yellow-400 hover:bg-yellow-400/20 transition" title="Reprovar comentário">
                                    <i data-lucide="x" class="w-4 h-4"></i>
                                </button>
                            `}
                            <button onclick="deleteComment(${comment.id})" class="p-1.5 rounded text-red-400 hover:bg-red-400/20 transition" title="Deletar comentário">
                                <i data-lucide="trash-2" class="w-4 h-4"></i>
                            </button>
                        </div>
                    </div>
                    <div class="text-gray-300 text-sm mt-2 whitespace-pre-wrap">${textoEscaped}</div>
                </div>
            `;
        });
        html += '</div>';
        container.innerHTML = html;
        lucide.createIcons();
    }
    
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    window.approveComment = function(commentId) {
        if (!confirm('Tem certeza que deseja aprovar este comentário?')) {
            return;
        }
        fetch('/api/comentarios_aula.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-Token': csrfToken
            },
            body: new URLSearchParams({
                action: 'approve',
                comment_id: commentId,
                csrf_token: csrfToken
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                loadComments();
            } else {
                alert('Erro ao aprovar comentário: ' + (data.error || 'Erro desconhecido'));
            }
        })
        .catch(error => {
            console.error('Erro ao aprovar comentário:', error);
            alert('Erro de comunicação ao aprovar comentário.');
        });
    };
    
    window.rejectComment = function(commentId) {
        if (!confirm('Tem certeza que deseja reprovar este comentário?')) {
            return;
        }
        fetch('/api/comentarios_aula.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-Token': csrfToken
            },
            body: new URLSearchParams({
                action: 'reject',
                comment_id: commentId,
                csrf_token: csrfToken
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                loadComments();
            } else {
                alert('Erro ao reprovar comentário: ' + (data.error || 'Erro desconhecido'));
            }
        })
        .catch(error => {
            console.error('Erro ao reprovar comentário:', error);
            alert('Erro de comunicação ao reprovar comentário.');
        });
    };
    
    window.deleteComment = function(commentId) {
        if (!confirm('Tem certeza que deseja deletar este comentário? Esta ação não pode ser desfeita.')) {
            return;
        }
        fetch('/api/comentarios_aula.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-CSRF-Token': csrfToken
            },
            body: new URLSearchParams({
                action: 'delete',
                comment_id: commentId,
                csrf_token: csrfToken
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                loadComments();
            } else {
                alert('Erro ao deletar comentário: ' + (data.error || 'Erro desconhecido'));
            }
        })
        .catch(error => {
            console.error('Erro ao deletar comentário:', error);
            alert('Erro de comunicação ao deletar comentário.');
        });
    };
    
    // Event listeners para filtros
    document.getElementById('btn-filter').addEventListener('click', function() {
        currentStatus = document.getElementById('filter-status').value;
        currentAula = document.getElementById('filter-aula').value;
        loadComments();
    });
    
    // Carregar comentários inicialmente
    loadComments();
});
</script>

