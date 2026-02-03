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
$usuario_tipo = $_SESSION['tipo'] ?? '';
$usuario_id = $_SESSION['id'] ?? 0;
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Verificar se é ação de moderação (requer infoprodutor ou admin)
$moderation_actions = ['list_all', 'approve', 'reject', 'delete'];
$is_moderation_action = in_array($action, $moderation_actions);

// Para ações de moderação, verificar se é infoprodutor ou admin
if ($is_moderation_action && $usuario_tipo !== 'infoprodutor' && $usuario_tipo !== 'admin') {
    sendJson(false, ['error' => 'Acesso negado. Apenas infoprodutores e administradores podem moderar comentários.'], 403);
}

// Para ações normais (list, add), bloquear admin
if (!$is_moderation_action && $usuario_tipo === 'admin') {
    sendJson(false, ['error' => 'Acesso negado.'], 401);
}

if ($action === 'list') {
    $aula_id = isset($_GET['aula_id']) ? (int)$_GET['aula_id'] : 0;
    if ($aula_id <= 0) {
        sendJson(false, ['error' => 'aula_id inválido.'], 400);
    }
    try {
        // Verificar se o aluno tem acesso ao curso desta aula
        $stmt = $pdo->prepare("
            SELECT c.id as curso_id, c.allow_comments
            FROM aulas a
            JOIN modulos m ON a.modulo_id = m.id
            JOIN cursos c ON m.curso_id = c.id
            JOIN produtos p ON c.produto_id = p.id
            WHERE a.id = ? AND p.tipo_entrega = 'area_membros'
        ");
        $stmt->execute([$aula_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            sendJson(false, ['error' => 'Aula não encontrada.'], 404);
        }
        $stmt_acc = $pdo->prepare("SELECT 1 FROM alunos_acessos WHERE aluno_email = ? AND produto_id = (SELECT produto_id FROM cursos WHERE id = ?)");
        $stmt_acc->execute([$aluno_email, $row['curso_id']]);
        if ($stmt_acc->rowCount() === 0) {
            sendJson(false, ['error' => 'Sem acesso ao curso.'], 403);
        }
        $stmt_t = $pdo->query("SHOW TABLES LIKE 'aula_comentarios'");
        if ($stmt_t->rowCount() === 0) {
            sendJson(true, ['comments' => []]);
        }
        $stmt_com = $pdo->prepare("SELECT id, autor_nome, texto, data_criacao FROM aula_comentarios WHERE aula_id = ? AND aprovado = 1 ORDER BY data_criacao ASC");
        $stmt_com->execute([$aula_id]);
        $comments = $stmt_com->fetchAll(PDO::FETCH_ASSOC);
        sendJson(true, ['comments' => $comments]);
    } catch (PDOException $e) {
        error_log('comentarios_aula list: ' . $e->getMessage());
        sendJson(false, ['error' => 'Erro ao listar comentários.'], 500);
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
    $aula_id = (int)($_POST['aula_id'] ?? $input['aula_id'] ?? 0);
    $autor_nome = trim((string)($_POST['autor_nome'] ?? $input['autor_nome'] ?? ''));
    $texto = trim((string)($_POST['texto'] ?? $input['texto'] ?? ''));
    if ($autor_nome === '') $autor_nome = $aluno_email;
    if (strlen($autor_nome) > 255) $autor_nome = substr($autor_nome, 0, 255);
    if ($texto === '') {
        sendJson(false, ['error' => 'O comentário não pode ser vazio.'], 400);
    }
    if (strlen($texto) > 5000) {
        sendJson(false, ['error' => 'Comentário muito longo.'], 400);
    }
    try {
        $stmt = $pdo->prepare("
            SELECT c.id as curso_id, c.allow_comments
            FROM aulas a
            JOIN modulos m ON a.modulo_id = m.id
            JOIN cursos c ON m.curso_id = c.id
            JOIN produtos p ON c.produto_id = p.id
            WHERE a.id = ? AND p.tipo_entrega = 'area_membros'
        ");
        $stmt->execute([$aula_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            sendJson(false, ['error' => 'Aula não encontrada.'], 404);
        }
        if (empty($row['allow_comments'])) {
            sendJson(false, ['error' => 'Comentários não permitidos nesta aula.'], 403);
        }
        $stmt_acc = $pdo->prepare("SELECT 1 FROM alunos_acessos WHERE aluno_email = ? AND produto_id = (SELECT produto_id FROM cursos WHERE id = ?)");
        $stmt_acc->execute([$aluno_email, $row['curso_id']]);
        if ($stmt_acc->rowCount() === 0) {
            sendJson(false, ['error' => 'Sem acesso ao curso.'], 403);
        }
        $stmt_t = $pdo->query("SHOW TABLES LIKE 'aula_comentarios'");
        if ($stmt_t->rowCount() === 0) {
            sendJson(false, ['error' => 'Recurso indisponível.'], 503);
        }
        $stmt_ins = $pdo->prepare("INSERT INTO aula_comentarios (aula_id, aluno_email, autor_nome, texto, aprovado) VALUES (?, ?, ?, ?, 0)");
        $stmt_ins->execute([$aula_id, $aluno_email, $autor_nome, $texto]);
        sendJson(true, ['message' => 'Comentário enviado e aguardando moderação.', 'id' => (int)$pdo->lastInsertId()]);
    } catch (PDOException $e) {
        error_log('comentarios_aula add: ' . $e->getMessage());
        sendJson(false, ['error' => 'Erro ao enviar comentário.'], 500);
    }
}

// Ação: list_all - Listar todos os comentários de um curso (para moderação)
if ($action === 'list_all') {
    $curso_id = isset($_GET['curso_id']) ? (int)$_GET['curso_id'] : 0;
    if ($curso_id <= 0) {
        sendJson(false, ['error' => 'curso_id inválido.'], 400);
    }
    
    try {
        // Verificar se o usuário tem acesso ao curso (infoprodutor do curso ou admin)
        $stmt_curso = $pdo->prepare("
            SELECT c.id, c.produto_id, p.usuario_id 
            FROM cursos c 
            JOIN produtos p ON c.produto_id = p.id 
            WHERE c.id = ? AND p.tipo_entrega = 'area_membros'
        ");
        $stmt_curso->execute([$curso_id]);
        $curso_data = $stmt_curso->fetch(PDO::FETCH_ASSOC);
        
        if (!$curso_data) {
            sendJson(false, ['error' => 'Curso não encontrado.'], 404);
        }
        
        // Verificar permissão: admin pode acessar qualquer curso, infoprodutor apenas seus próprios
        if ($usuario_tipo === 'admin' || ($usuario_tipo === 'infoprodutor' && (int)$curso_data['usuario_id'] === (int)$usuario_id)) {
            $stmt_t = $pdo->query("SHOW TABLES LIKE 'aula_comentarios'");
            if ($stmt_t->rowCount() === 0) {
                sendJson(true, ['comments' => []]);
            }
            
            // Construir query com filtros opcionais
            $where_conditions = ["m.curso_id = ?"];
            $params = [$curso_id];
            
            $status = isset($_GET['status']) ? $_GET['status'] : null;
            if ($status !== null && $status !== 'all') {
                $where_conditions[] = "ac.aprovado = ?";
                $params[] = (int)$status;
            }
            
            $aula_id = isset($_GET['aula_id']) ? (int)$_GET['aula_id'] : 0;
            if ($aula_id > 0) {
                $where_conditions[] = "ac.aula_id = ?";
                $params[] = $aula_id;
            }
            
            $where_clause = implode(' AND ', $where_conditions);
            
            $stmt_com = $pdo->prepare("
                SELECT ac.id, ac.aula_id, ac.aluno_email, ac.autor_nome, ac.texto, ac.data_criacao, ac.aprovado,
                       a.titulo as aula_titulo, m.titulo as modulo_titulo
                FROM aula_comentarios ac
                JOIN aulas a ON ac.aula_id = a.id
                JOIN modulos m ON a.modulo_id = m.id
                WHERE {$where_clause}
                ORDER BY ac.data_criacao DESC
                LIMIT 200
            ");
            $stmt_com->execute($params);
            $comments = $stmt_com->fetchAll(PDO::FETCH_ASSOC);
            sendJson(true, ['comments' => $comments]);
        } else {
            sendJson(false, ['error' => 'Acesso negado. Você não tem permissão para moderar comentários deste curso.'], 403);
        }
    } catch (PDOException $e) {
        error_log('comentarios_aula list_all: ' . $e->getMessage());
        sendJson(false, ['error' => 'Erro ao listar comentários.'], 500);
    }
}

// Ação: approve - Aprovar um comentário
if ($action === 'approve') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendJson(false, ['error' => 'Use POST.'], 405);
    }
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) $input = [];
    $csrf = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $input['csrf_token'] ?? null;
    if (empty($csrf) || !verify_csrf_token($csrf)) {
        sendJson(false, ['error' => 'Token CSRF inválido.'], 403);
    }
    $comment_id = (int)($_POST['comment_id'] ?? $input['comment_id'] ?? 0);
    if ($comment_id <= 0) {
        sendJson(false, ['error' => 'comment_id inválido.'], 400);
    }
    
    try {
        // Verificar se o comentário existe e obter curso_id
        $stmt = $pdo->prepare("
            SELECT m.curso_id, p.usuario_id
            FROM aula_comentarios ac
            JOIN aulas a ON ac.aula_id = a.id
            JOIN modulos m ON a.modulo_id = m.id
            JOIN cursos c ON m.curso_id = c.id
            JOIN produtos p ON c.produto_id = p.id
            WHERE ac.id = ?
        ");
        $stmt->execute([$comment_id]);
        $comment_data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$comment_data) {
            sendJson(false, ['error' => 'Comentário não encontrado.'], 404);
        }
        
        // Verificar permissão
        if ($usuario_tipo === 'admin' || ($usuario_tipo === 'infoprodutor' && (int)$comment_data['usuario_id'] === (int)$usuario_id)) {
            $stmt_upd = $pdo->prepare("UPDATE aula_comentarios SET aprovado = 1 WHERE id = ?");
            $stmt_upd->execute([$comment_id]);
            sendJson(true, ['message' => 'Comentário aprovado.']);
        } else {
            sendJson(false, ['error' => 'Acesso negado.'], 403);
        }
    } catch (PDOException $e) {
        error_log('comentarios_aula approve: ' . $e->getMessage());
        sendJson(false, ['error' => 'Erro ao aprovar comentário.'], 500);
    }
}

// Ação: reject - Reprovar um comentário
if ($action === 'reject') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendJson(false, ['error' => 'Use POST.'], 405);
    }
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) $input = [];
    $csrf = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $input['csrf_token'] ?? null;
    if (empty($csrf) || !verify_csrf_token($csrf)) {
        sendJson(false, ['error' => 'Token CSRF inválido.'], 403);
    }
    $comment_id = (int)($_POST['comment_id'] ?? $input['comment_id'] ?? 0);
    if ($comment_id <= 0) {
        sendJson(false, ['error' => 'comment_id inválido.'], 400);
    }
    
    try {
        // Verificar se o comentário existe e obter curso_id
        $stmt = $pdo->prepare("
            SELECT m.curso_id, p.usuario_id
            FROM aula_comentarios ac
            JOIN aulas a ON ac.aula_id = a.id
            JOIN modulos m ON a.modulo_id = m.id
            JOIN cursos c ON m.curso_id = c.id
            JOIN produtos p ON c.produto_id = p.id
            WHERE ac.id = ?
        ");
        $stmt->execute([$comment_id]);
        $comment_data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$comment_data) {
            sendJson(false, ['error' => 'Comentário não encontrado.'], 404);
        }
        
        // Verificar permissão
        if ($usuario_tipo === 'admin' || ($usuario_tipo === 'infoprodutor' && (int)$comment_data['usuario_id'] === (int)$usuario_id)) {
            $stmt_upd = $pdo->prepare("UPDATE aula_comentarios SET aprovado = 0 WHERE id = ?");
            $stmt_upd->execute([$comment_id]);
            sendJson(true, ['message' => 'Comentário reprovado.']);
        } else {
            sendJson(false, ['error' => 'Acesso negado.'], 403);
        }
    } catch (PDOException $e) {
        error_log('comentarios_aula reject: ' . $e->getMessage());
        sendJson(false, ['error' => 'Erro ao reprovar comentário.'], 500);
    }
}

// Ação: delete - Deletar um comentário
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
    $comment_id = (int)($_POST['comment_id'] ?? $input['comment_id'] ?? 0);
    if ($comment_id <= 0) {
        sendJson(false, ['error' => 'comment_id inválido.'], 400);
    }
    
    try {
        // Verificar se o comentário existe e obter curso_id
        $stmt = $pdo->prepare("
            SELECT m.curso_id, p.usuario_id
            FROM aula_comentarios ac
            JOIN aulas a ON ac.aula_id = a.id
            JOIN modulos m ON a.modulo_id = m.id
            JOIN cursos c ON m.curso_id = c.id
            JOIN produtos p ON c.produto_id = p.id
            WHERE ac.id = ?
        ");
        $stmt->execute([$comment_id]);
        $comment_data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$comment_data) {
            sendJson(false, ['error' => 'Comentário não encontrado.'], 404);
        }
        
        // Verificar permissão
        if ($usuario_tipo === 'admin' || ($usuario_tipo === 'infoprodutor' && (int)$comment_data['usuario_id'] === (int)$usuario_id)) {
            $stmt_del = $pdo->prepare("DELETE FROM aula_comentarios WHERE id = ?");
            $stmt_del->execute([$comment_id]);
            sendJson(true, ['message' => 'Comentário deletado.']);
        } else {
            sendJson(false, ['error' => 'Acesso negado.'], 403);
        }
    } catch (PDOException $e) {
        error_log('comentarios_aula delete: ' . $e->getMessage());
        sendJson(false, ['error' => 'Erro ao deletar comentário.'], 500);
    }
}

sendJson(false, ['error' => 'Ação inválida.'], 400);
