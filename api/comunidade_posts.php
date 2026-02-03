<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../helpers/security_helper.php';

header('Content-Type: application/json');

function sendJson($success, $data = [], $code = 200) {
    http_response_code($code);
    echo json_encode(array_merge(['success' => $success], $data), JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    sendJson(false, ['error' => 'Não autorizado.'], 401);
}

$aluno_email = $_SESSION['usuario'] ?? '';
$aluno_nome = $_SESSION['nome'] ?? $aluno_email;
$usuario_id = (int)($_SESSION['id'] ?? 0);
$usuario_tipo = $_SESSION['tipo'] ?? '';

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Verificar acesso ao curso (produto) da categoria
function get_curso_id_from_categoria($pdo, $categoria_id) {
    $stmt = $pdo->prepare("SELECT curso_id FROM comunidade_categorias WHERE id = ?");
    $stmt->execute([$categoria_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? (int)$row['curso_id'] : 0;
}

function user_has_access_to_curso($pdo, $curso_id, $aluno_email, $usuario_id, $usuario_tipo) {
    $stmt = $pdo->prepare("SELECT produto_id FROM cursos WHERE id = ?");
    $stmt->execute([$curso_id]);
    $c = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$c) return false;
    $stmt_acc = $pdo->prepare("SELECT 1 FROM alunos_acessos WHERE aluno_email = ? AND produto_id = ?");
    $stmt_acc->execute([$aluno_email, $c['produto_id']]);
    if ($stmt_acc->rowCount() > 0) return true;
    if ($usuario_tipo === 'infoprodutor') {
        $stmt_p = $pdo->prepare("SELECT 1 FROM produtos WHERE id = ? AND usuario_id = ?");
        $stmt_p->execute([$c['produto_id'], $usuario_id]);
        return $stmt_p->rowCount() > 0;
    }
    return false;
}

if ($action === 'list') {
    $categoria_id = isset($_GET['categoria_id']) ? (int)$_GET['categoria_id'] : 0;
    if ($categoria_id <= 0) {
        sendJson(false, ['error' => 'categoria_id inválido.'], 400);
    }
    $curso_id = get_curso_id_from_categoria($pdo, $categoria_id);
    if ($curso_id <= 0) {
        sendJson(false, ['error' => 'Categoria não encontrada.'], 404);
    }
    if (!user_has_access_to_curso($pdo, $curso_id, $aluno_email, $usuario_id, $usuario_tipo)) {
        sendJson(false, ['error' => 'Sem acesso ao curso.'], 403);
    }
    try {
        $stmt_t = $pdo->query("SHOW TABLES LIKE 'comunidade_posts'");
        if ($stmt_t->rowCount() === 0) {
            sendJson(true, ['posts' => []]);
        }
        // Verificar se coluna anexo_url existe
        $has_anexo = false;
        try {
            $chk = $pdo->query("SHOW COLUMNS FROM comunidade_posts LIKE 'anexo_url'");
            $has_anexo = $chk->rowCount() > 0;
        } catch (PDOException $e) {}
        
        $select_fields = "id, categoria_id, autor_tipo, autor_nome, conteudo, data_criacao";
        if ($has_anexo) {
            $select_fields .= ", anexo_url";
        }
        
        $stmt = $pdo->prepare("
            SELECT {$select_fields}
            FROM comunidade_posts
            WHERE categoria_id = ?
            ORDER BY data_criacao DESC
            LIMIT 100
        ");
        $stmt->execute([$categoria_id]);
        $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        sendJson(true, ['posts' => $posts]);
    } catch (PDOException $e) {
        error_log('comunidade_posts list: ' . $e->getMessage());
        sendJson(false, ['error' => 'Erro ao listar posts.'], 500);
    }
}

if ($action === 'add') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendJson(false, ['error' => 'Use POST.'], 405);
    }
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) $input = [];
    $csrf = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $input['csrf_token'] ?? null;
    if (empty($csrf) || !verify_csrf_token($csrf)) {
        sendJson(false, ['error' => 'Token CSRF inválido.'], 403);
    }
    $categoria_id = (int)($_POST['categoria_id'] ?? $input['categoria_id'] ?? 0);
    $conteudo = trim((string)($_POST['conteudo'] ?? $input['conteudo'] ?? ''));
    if ($categoria_id <= 0) {
        sendJson(false, ['error' => 'Categoria inválida.'], 400);
    }
    if ($conteudo === '') {
        sendJson(false, ['error' => 'Conteúdo não pode ser vazio.'], 400);
    }
    if (strlen($conteudo) > 10000) {
        sendJson(false, ['error' => 'Conteúdo muito longo.'], 400);
    }
    $curso_id = get_curso_id_from_categoria($pdo, $categoria_id);
    if ($curso_id <= 0) {
        sendJson(false, ['error' => 'Categoria não encontrada.'], 404);
    }
    if (!user_has_access_to_curso($pdo, $curso_id, $aluno_email, $usuario_id, $usuario_tipo)) {
        sendJson(false, ['error' => 'Sem acesso ao curso.'], 403);
    }
    try {
        $stmt_cat = $pdo->prepare("SELECT id, is_public_posting FROM comunidade_categorias WHERE id = ? AND curso_id = ?");
        $stmt_cat->execute([$categoria_id, $curso_id]);
        $cat = $stmt_cat->fetch(PDO::FETCH_ASSOC);
        if (!$cat) {
            sendJson(false, ['error' => 'Categoria não encontrada.'], 404);
        }
        $autor_tipo = ($usuario_tipo === 'admin' || $usuario_tipo === 'infoprodutor') ? 'infoprodutor' : 'aluno';
        if ($autor_tipo === 'aluno' && empty($cat['is_public_posting'])) {
            sendJson(false, ['error' => 'Esta categoria não permite postagem por alunos.'], 403);
        }
        $stmt_t = $pdo->query("SHOW TABLES LIKE 'comunidade_posts'");
        if ($stmt_t->rowCount() === 0) {
            sendJson(false, ['error' => 'Recurso indisponível.'], 503);
        }
        // Processar upload de imagem se houver
        $anexo_url = null;
        if (isset($_FILES['imagem']) && $_FILES['imagem']['error'] === UPLOAD_ERR_OK) {
            $upload_dir = __DIR__ . '/../uploads/comunidade_posts/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            $file = $_FILES['imagem'];
            $allowed_types = ['image/jpeg', 'image/png', 'image/webp'];
            $max_size = 5 * 1024 * 1024; // 5MB
            
            if (!in_array($file['type'], $allowed_types)) {
                sendJson(false, ['error' => 'Tipo de arquivo não permitido. Use JPEG, PNG ou WebP.'], 400);
            }
            if ($file['size'] > $max_size) {
                sendJson(false, ['error' => 'Arquivo muito grande. Máximo 5MB.'], 400);
            }
            
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = uniqid('post_', true) . '.' . $ext;
            $filepath = $upload_dir . $filename;
            
            if (move_uploaded_file($file['tmp_name'], $filepath)) {
                $anexo_url = 'uploads/comunidade_posts/' . $filename;
            } else {
                sendJson(false, ['error' => 'Erro ao fazer upload da imagem.'], 500);
            }
        }
        
        $autor_id = ($autor_tipo === 'infoprodutor' && $usuario_id) ? $usuario_id : null;
        
        // Verificar se coluna anexo_url existe
        $has_anexo = false;
        try {
            $chk = $pdo->query("SHOW COLUMNS FROM comunidade_posts LIKE 'anexo_url'");
            $has_anexo = $chk->rowCount() > 0;
        } catch (PDOException $e) {}
        
        if ($has_anexo) {
            $stmt_ins = $pdo->prepare("INSERT INTO comunidade_posts (categoria_id, autor_tipo, autor_id, autor_email, autor_nome, conteudo, anexo_url) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt_ins->execute([$categoria_id, $autor_tipo, $autor_id, $aluno_email, $aluno_nome, $conteudo, $anexo_url]);
        } else {
            $stmt_ins = $pdo->prepare("INSERT INTO comunidade_posts (categoria_id, autor_tipo, autor_id, autor_email, autor_nome, conteudo) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt_ins->execute([$categoria_id, $autor_tipo, $autor_id, $aluno_email, $aluno_nome, $conteudo]);
        }
        sendJson(true, ['message' => 'Post publicado.', 'id' => (int)$pdo->lastInsertId()]);
    } catch (PDOException $e) {
        error_log('comunidade_posts add: ' . $e->getMessage());
        sendJson(false, ['error' => 'Erro ao publicar.'], 500);
    }
}

// Listar categorias do curso (para o cliente)
if ($action === 'categorias') {
    $produto_id = isset($_GET['produto_id']) ? (int)$_GET['produto_id'] : 0;
    if ($produto_id <= 0) {
        sendJson(false, ['error' => 'produto_id inválido.'], 400);
    }
    $stmt_curso = $pdo->prepare("SELECT id FROM cursos WHERE produto_id = ?");
    $stmt_curso->execute([$produto_id]);
    $curso = $stmt_curso->fetch(PDO::FETCH_ASSOC);
    if (!$curso) {
        sendJson(false, ['error' => 'Curso não encontrado.'], 404);
    }
    if (!user_has_access_to_curso($pdo, $curso['id'], $aluno_email, $usuario_id, $usuario_tipo)) {
        sendJson(false, ['error' => 'Sem acesso ao curso.'], 403);
    }
    $stmt_chk = $pdo->query("SHOW COLUMNS FROM cursos LIKE 'community_enabled'");
    if ($stmt_chk->rowCount() === 0) {
        sendJson(true, ['categorias' => []]);
    }
    $stmt_ce = $pdo->prepare("SELECT community_enabled FROM cursos WHERE id = ?");
    $stmt_ce->execute([$curso['id']]);
    $ce = $stmt_ce->fetch(PDO::FETCH_ASSOC);
    if (empty($ce['community_enabled'])) {
        sendJson(true, ['categorias' => []]);
    }
    $stmt_t = $pdo->query("SHOW TABLES LIKE 'comunidade_categorias'");
    if ($stmt_t->rowCount() === 0) {
        sendJson(true, ['categorias' => []]);
    }
    $stmt_cat = $pdo->prepare("SELECT id, nome, is_public_posting, ordem FROM comunidade_categorias WHERE curso_id = ? ORDER BY ordem ASC, id ASC");
    $stmt_cat->execute([$curso['id']]);
    $categorias = $stmt_cat->fetchAll(PDO::FETCH_ASSOC);
    sendJson(true, ['categorias' => $categorias]);
}

// Listar todos os posts para moderação (apenas infoprodutor do curso)
if ($action === 'list_all') {
    $curso_id = isset($_GET['curso_id']) ? (int)$_GET['curso_id'] : 0;
    if ($curso_id <= 0) {
        sendJson(false, ['error' => 'curso_id inválido.'], 400);
    }
    
    // Verificar se é infoprodutor do curso
    $stmt_curso = $pdo->prepare("SELECT produto_id FROM cursos WHERE id = ?");
    $stmt_curso->execute([$curso_id]);
    $curso = $stmt_curso->fetch(PDO::FETCH_ASSOC);
    if (!$curso) {
        sendJson(false, ['error' => 'Curso não encontrado.'], 404);
    }
    
    if ($usuario_tipo !== 'infoprodutor') {
        sendJson(false, ['error' => 'Acesso negado. Apenas infoprodutores podem moderar.'], 403);
    }
    
    $stmt_p = $pdo->prepare("SELECT 1 FROM produtos WHERE id = ? AND usuario_id = ?");
    $stmt_p->execute([$curso['produto_id'], $usuario_id]);
    if ($stmt_p->rowCount() === 0) {
        sendJson(false, ['error' => 'Acesso negado. Você não é o infoprodutor deste curso.'], 403);
    }
    
    try {
        $stmt_t = $pdo->query("SHOW TABLES LIKE 'comunidade_posts'");
        if ($stmt_t->rowCount() === 0) {
            sendJson(true, ['posts' => []]);
        }
        
        // Buscar categorias do curso
        $stmt_cat = $pdo->prepare("SELECT id FROM comunidade_categorias WHERE curso_id = ?");
        $stmt_cat->execute([$curso_id]);
        $categoria_ids = $stmt_cat->fetchAll(PDO::FETCH_COLUMN);
        
        if (empty($categoria_ids)) {
            sendJson(true, ['posts' => []]);
        }
        
        $placeholders = implode(',', array_fill(0, count($categoria_ids), '?'));
        
        // Verificar se coluna anexo_url existe
        $has_anexo = false;
        try {
            $chk = $pdo->query("SHOW COLUMNS FROM comunidade_posts LIKE 'anexo_url'");
            $has_anexo = $chk->rowCount() > 0;
        } catch (PDOException $e) {}
        
        $select_fields = "cp.id, cp.categoria_id, cp.autor_tipo, cp.autor_nome, cp.conteudo, cp.data_criacao, cc.nome as categoria_nome";
        if ($has_anexo) {
            $select_fields .= ", cp.anexo_url";
        }
        
        $stmt = $pdo->prepare("
            SELECT {$select_fields}
            FROM comunidade_posts cp
            INNER JOIN comunidade_categorias cc ON cp.categoria_id = cc.id
            WHERE cp.categoria_id IN ({$placeholders})
            ORDER BY cp.data_criacao DESC
            LIMIT 200
        ");
        $stmt->execute($categoria_ids);
        $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Formatar data
        foreach ($posts as &$post) {
            if (isset($post['data_criacao'])) {
                $post['created_at'] = $post['data_criacao'];
            }
        }
        
        sendJson(true, ['posts' => $posts]);
    } catch (PDOException $e) {
        error_log('comunidade_posts list_all: ' . $e->getMessage());
        sendJson(false, ['error' => 'Erro ao listar posts.'], 500);
    }
}

// Deletar post (apenas infoprodutor do curso)
if ($action === 'delete') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendJson(false, ['error' => 'Use POST.'], 405);
    }
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) $input = [];
    $csrf = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $input['csrf_token'] ?? null;
    if (empty($csrf) || !verify_csrf_token($csrf)) {
        sendJson(false, ['error' => 'Token CSRF inválido.'], 403);
    }
    
    $post_id = (int)($_POST['post_id'] ?? $input['post_id'] ?? 0);
    if ($post_id <= 0) {
        sendJson(false, ['error' => 'post_id inválido.'], 400);
    }
    
    try {
        $stmt_t = $pdo->query("SHOW TABLES LIKE 'comunidade_posts'");
        if ($stmt_t->rowCount() === 0) {
            sendJson(false, ['error' => 'Recurso indisponível.'], 503);
        }
        
        // Buscar post e categoria
        $stmt_post = $pdo->prepare("
            SELECT cp.id, cp.categoria_id, cp.anexo_url, cc.curso_id
            FROM comunidade_posts cp
            INNER JOIN comunidade_categorias cc ON cp.categoria_id = cc.id
            WHERE cp.id = ?
        ");
        $stmt_post->execute([$post_id]);
        $post = $stmt_post->fetch(PDO::FETCH_ASSOC);
        
        if (!$post) {
            sendJson(false, ['error' => 'Post não encontrado.'], 404);
        }
        
        // Verificar se é infoprodutor do curso
        $stmt_curso = $pdo->prepare("SELECT produto_id FROM cursos WHERE id = ?");
        $stmt_curso->execute([$post['curso_id']]);
        $curso = $stmt_curso->fetch(PDO::FETCH_ASSOC);
        if (!$curso) {
            sendJson(false, ['error' => 'Curso não encontrado.'], 404);
        }
        
        if ($usuario_tipo !== 'infoprodutor') {
            sendJson(false, ['error' => 'Acesso negado. Apenas infoprodutores podem deletar posts.'], 403);
        }
        
        $stmt_p = $pdo->prepare("SELECT 1 FROM produtos WHERE id = ? AND usuario_id = ?");
        $stmt_p->execute([$curso['produto_id'], $usuario_id]);
        if ($stmt_p->rowCount() === 0) {
            sendJson(false, ['error' => 'Acesso negado. Você não é o infoprodutor deste curso.'], 403);
        }
        
        // Deletar imagem se existir
        if (!empty($post['anexo_url']) && file_exists(__DIR__ . '/../' . $post['anexo_url'])) {
            @unlink(__DIR__ . '/../' . $post['anexo_url']);
        }
        
        // Deletar post
        $stmt_del = $pdo->prepare("DELETE FROM comunidade_posts WHERE id = ?");
        $stmt_del->execute([$post_id]);
        
        sendJson(true, ['message' => 'Post deletado com sucesso.']);
    } catch (PDOException $e) {
        error_log('comunidade_posts delete: ' . $e->getMessage());
        sendJson(false, ['error' => 'Erro ao deletar post.'], 500);
    }
}

sendJson(false, ['error' => 'Ação inválida.'], 400);
