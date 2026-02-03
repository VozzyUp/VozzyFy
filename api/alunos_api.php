<?php
require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');

// Função auxiliar para enviar resposta JSON
function sendJsonResponse($success, $data = [], $httpCode = 200) {
    http_response_code($httpCode);
    echo json_encode(['success' => $success] + $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// Verifica se o usuário está logado e é infoprodutor
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || (isset($_SESSION["tipo"]) && $_SESSION["tipo"] !== 'infoprodutor')) {
    sendJsonResponse(false, ['error' => 'Acesso não autorizado. Você precisa estar logado como infoprodutor.'], 401);
}

$usuario_id = $_SESSION['id'];
$action = $_GET['action'] ?? '';

// Obtém os dados do corpo da requisição POST
$input = json_decode(file_get_contents('php://input'), true);

switch ($action) {
    case 'list':
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            sendJsonResponse(false, ['error' => 'Método não permitido. Use GET.'], 405);
        }

        $produto_id = $_GET['produto_id'] ?? null;

        if (!$produto_id || !is_numeric($produto_id)) {
            sendJsonResponse(false, ['error' => 'ID do produto é obrigatório e deve ser numérico.'], 400);
        }

        try {
            // Valida que o produto pertence ao infoprodutor
            $stmt_produto = $pdo->prepare("SELECT id, nome FROM produtos WHERE id = ? AND usuario_id = ? AND tipo_entrega = 'area_membros'");
            $stmt_produto->execute([$produto_id, $usuario_id]);
            $produto = $stmt_produto->fetch(PDO::FETCH_ASSOC);

            if (!$produto) {
                sendJsonResponse(false, ['error' => 'Produto não encontrado ou não pertence a você.'], 404);
            }

            // Busca alunos do produto
            $stmt = $pdo->prepare("
                SELECT 
                    aa.aluno_email,
                    aa.data_concessao,
                    u.nome,
                    p.nome as produto_nome,
                    p.id as produto_id
                FROM alunos_acessos aa
                JOIN produtos p ON aa.produto_id = p.id
                LEFT JOIN usuarios u ON u.usuario = aa.aluno_email AND u.tipo = 'usuario'
                WHERE p.id = ? AND p.usuario_id = ?
                ORDER BY aa.data_concessao DESC
            ");
            $stmt->execute([$produto_id, $usuario_id]);
            $alunos = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Calcula progresso para cada aluno
            foreach ($alunos as &$aluno) {
                // Inicializa valores padrão
                $total_aulas = 0;
                $aulas_concluidas = 0;
                $progresso_percentual = 0;
                
                // Verifica se existe curso para o produto
                $stmt_curso = $pdo->prepare("SELECT id FROM cursos WHERE produto_id = ? LIMIT 1");
                $stmt_curso->execute([$produto_id]);
                $curso = $stmt_curso->fetch(PDO::FETCH_ASSOC);
                
                if ($curso) {
                    // Busca data de concessão do aluno
                    $stmt_data = $pdo->prepare("SELECT data_concessao FROM alunos_acessos WHERE aluno_email = ? AND produto_id = ?");
                    $stmt_data->execute([$aluno['aluno_email'], $produto_id]);
                    $acesso = $stmt_data->fetch(PDO::FETCH_ASSOC);
                    
                    $data_concessao = $acesso ? new DateTime($acesso['data_concessao']) : new DateTime();
                    $current_date = new DateTime();
                    
                    // Busca todas as aulas do curso com seus release_days
                    $stmt_aulas = $pdo->prepare("
                        SELECT a.id, COALESCE(a.release_days, 0) as release_days, m.release_days as modulo_release_days
                        FROM aulas a
                        JOIN modulos m ON a.modulo_id = m.id
                        JOIN cursos c ON m.curso_id = c.id
                        WHERE c.produto_id = ?
                        ORDER BY m.ordem ASC, a.ordem ASC
                    ");
                    $stmt_aulas->execute([$produto_id]);
                    $todas_aulas = $stmt_aulas->fetchAll(PDO::FETCH_ASSOC);
                    
                    // Conta apenas aulas desbloqueadas
                    foreach ($todas_aulas as $aula) {
                        $release_days = (int)($aula['release_days'] ?? 0);
                        $modulo_release_days = (int)($aula['modulo_release_days'] ?? 0);
                        $total_release_days = max($release_days, $modulo_release_days);
                        
                        $release_date = clone $data_concessao;
                        $release_date->modify("+{$total_release_days} days");
                        
                        // Só conta se a aula estiver desbloqueada
                        if ($current_date >= $release_date) {
                            $total_aulas++;
                            
                            // Verifica se esta aula foi concluída
                            $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM aluno_progresso WHERE aluno_email = ? AND aula_id = ?");
                            $stmt_check->execute([$aluno['aluno_email'], $aula['id']]);
                            if ($stmt_check->fetchColumn() > 0) {
                                $aulas_concluidas++;
                            }
                        }
                    }
                    
                    // Calcula percentual
                    $progresso_percentual = $total_aulas > 0 ? round(($aulas_concluidas / $total_aulas) * 100) : 0;
                }

                // Garante que os valores sejam sempre números inteiros
                $aluno['progresso_percentual'] = (int)$progresso_percentual;
                $aluno['total_aulas'] = (int)$total_aulas;
                $aluno['aulas_concluidas'] = (int)$aulas_concluidas;
                $aluno['nome'] = $aluno['nome'] ?? $aluno['aluno_email']; // Fallback para email se não tiver nome
            }

            sendJsonResponse(true, ['alunos' => $alunos, 'produto' => $produto]);
        } catch (PDOException $e) {
            error_log("Erro de DB ao listar alunos: " . $e->getMessage());
            sendJsonResponse(false, ['error' => 'Erro interno do servidor ao listar alunos.'], 500);
        }
        break;

    case 'create':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            sendJsonResponse(false, ['error' => 'Método não permitido. Use POST.'], 405);
        }

        $email = trim($input['email'] ?? '');
        $nome = trim($input['nome'] ?? '');
        $produto_id = $input['produto_id'] ?? null;
        $enviar_email = isset($input['enviar_email']) && $input['enviar_email'] === true;

        // Validações
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            sendJsonResponse(false, ['error' => 'Email inválido.'], 400);
        }

        if (empty($nome)) {
            sendJsonResponse(false, ['error' => 'Nome é obrigatório.'], 400);
        }

        if (!$produto_id || !is_numeric($produto_id)) {
            sendJsonResponse(false, ['error' => 'ID do produto é obrigatório e deve ser numérico.'], 400);
        }

        try {
            // Valida que o produto pertence ao infoprodutor
            $stmt_produto = $pdo->prepare("SELECT id, nome FROM produtos WHERE id = ? AND usuario_id = ? AND tipo_entrega = 'area_membros'");
            $stmt_produto->execute([$produto_id, $usuario_id]);
            $produto = $stmt_produto->fetch(PDO::FETCH_ASSOC);

            if (!$produto) {
                sendJsonResponse(false, ['error' => 'Produto não encontrado ou não pertence a você.'], 404);
            }

            // Verifica se aluno já tem acesso ao produto
            $stmt_check = $pdo->prepare("SELECT id FROM alunos_acessos WHERE aluno_email = ? AND produto_id = ?");
            $stmt_check->execute([$email, $produto_id]);
            if ($stmt_check->fetch()) {
                sendJsonResponse(false, ['error' => 'Este aluno já possui acesso a este produto.'], 400);
            }

            // Inicia transação
            $pdo->beginTransaction();

            // Insere acesso do aluno
            $stmt_insert = $pdo->prepare("INSERT INTO alunos_acessos (aluno_email, produto_id) VALUES (?, ?)");
            $stmt_insert->execute([$email, $produto_id]);

            // Verifica se usuário existe (independente do tipo)
            $stmt_user = $pdo->prepare("SELECT id, senha, tipo, nome FROM usuarios WHERE usuario = ?");
            $stmt_user->execute([$email]);
            $existing_user = $stmt_user->fetch(PDO::FETCH_ASSOC);

            $setup_token = null;
            $is_new_user = false;

            if (!$existing_user) {
                // Cliente NOVO - cria usuário com senha temporária
                $temp_password = bin2hex(random_bytes(32));
                $hashed_temp = password_hash($temp_password, PASSWORD_DEFAULT);

                $stmt_create_user = $pdo->prepare("INSERT INTO usuarios (usuario, nome, senha, tipo) VALUES (?, ?, ?, 'usuario')");
                $stmt_create_user->execute([$email, $nome, $hashed_temp]);
                $new_user_id = $pdo->lastInsertId();
                $is_new_user = true;

                // Gera token de criação de senha
                if (file_exists(__DIR__ . '/../helpers/password_setup_helper.php')) {
                    require_once __DIR__ . '/../helpers/password_setup_helper.php';
                    if (function_exists('generate_setup_token')) {
                        $setup_token = generate_setup_token($new_user_id);
                    }
                }
            } else {
                // Cliente existente - atualiza nome se necessário (independente do tipo)
                if (empty($existing_user['nome']) || $existing_user['nome'] !== $nome) {
                    $stmt_update_nome = $pdo->prepare("UPDATE usuarios SET nome = ? WHERE id = ?");
                    $stmt_update_nome->execute([$nome, $existing_user['id']]);
                }
                // Se for infoprodutor, não precisa gerar token (já tem conta ativa)
                // Se for usuario, pode gerar token se necessário (mas não é obrigatório aqui)
            }

            $pdo->commit();

            // Envia email se solicitado
            $email_enviado = false;
            if ($enviar_email) {
                if (file_exists(__DIR__ . '/../helpers/alunos_helper.php')) {
                    require_once __DIR__ . '/../helpers/alunos_helper.php';
                    if (function_exists('send_student_access_email')) {
                        $email_enviado = send_student_access_email($email, $nome, $produto_id, $setup_token);
                    } else {
                        error_log("ALUNOS_API: Função send_student_access_email não encontrada no helper");
                    }
                } else {
                    error_log("ALUNOS_API: Arquivo helpers/alunos_helper.php não encontrado");
                }
            }

            sendJsonResponse(true, [
                'message' => 'Aluno criado com sucesso.',
                'email_enviado' => $email_enviado,
                'is_new_user' => $is_new_user
            ]);

        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Erro de DB ao criar aluno: " . $e->getMessage());
            sendJsonResponse(false, ['error' => 'Erro interno do servidor ao criar aluno.'], 500);
        }
        break;

    case 'progress':
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            sendJsonResponse(false, ['error' => 'Método não permitido. Use GET.'], 405);
        }

        $email = $_GET['email'] ?? '';
        $produto_id = $_GET['produto_id'] ?? null;

        if (empty($email)) {
            sendJsonResponse(false, ['error' => 'Email do aluno é obrigatório.'], 400);
        }

        if (!$produto_id || !is_numeric($produto_id)) {
            sendJsonResponse(false, ['error' => 'ID do produto é obrigatório e deve ser numérico.'], 400);
        }

        try {
            // Valida que o produto pertence ao infoprodutor
            $stmt_produto = $pdo->prepare("SELECT id, nome FROM produtos WHERE id = ? AND usuario_id = ? AND tipo_entrega = 'area_membros'");
            $stmt_produto->execute([$produto_id, $usuario_id]);
            $produto = $stmt_produto->fetch(PDO::FETCH_ASSOC);

            if (!$produto) {
                sendJsonResponse(false, ['error' => 'Produto não encontrado ou não pertence a você.'], 404);
            }

            // Verifica se aluno tem acesso
            $stmt_acesso = $pdo->prepare("SELECT data_concessao FROM alunos_acessos WHERE aluno_email = ? AND produto_id = ?");
            $stmt_acesso->execute([$email, $produto_id]);
            $acesso = $stmt_acesso->fetch(PDO::FETCH_ASSOC);

            if (!$acesso) {
                sendJsonResponse(false, ['error' => 'Aluno não possui acesso a este produto.'], 404);
            }

            $data_concessao = new DateTime($acesso['data_concessao']);
            $current_date = new DateTime();

            // Busca curso do produto
            $stmt_curso = $pdo->prepare("SELECT id FROM cursos WHERE produto_id = ?");
            $stmt_curso->execute([$produto_id]);
            $curso = $stmt_curso->fetch(PDO::FETCH_ASSOC);

            if (!$curso) {
                sendJsonResponse(true, [
                    'progresso_geral' => 0,
                    'total_aulas' => 0,
                    'aulas_concluidas' => 0,
                    'modulos' => []
                ]);
            }

            // Busca módulos do curso
            $stmt_modulos = $pdo->prepare("SELECT id, titulo, ordem, release_days FROM modulos WHERE curso_id = ? ORDER BY ordem ASC, id ASC");
            $stmt_modulos->execute([$curso['id']]);
            $modulos = $stmt_modulos->fetchAll(PDO::FETCH_ASSOC);

            $total_aulas = 0;
            $aulas_concluidas = 0;
            $modulos_com_progresso = [];

            foreach ($modulos as $modulo) {
                // Calcula data de liberação do módulo
                $module_release_date = clone $data_concessao;
                $module_release_date->modify("+{$modulo['release_days']} days");
                $is_locked = ($current_date < $module_release_date);

                // Busca aulas do módulo
                $stmt_aulas = $pdo->prepare("SELECT id, titulo, ordem, release_days FROM aulas WHERE modulo_id = ? ORDER BY ordem ASC, id ASC");
                $stmt_aulas->execute([$modulo['id']]);
                $aulas = $stmt_aulas->fetchAll(PDO::FETCH_ASSOC);

                $modulo_total_aulas = 0;
                $modulo_aulas_concluidas = 0;
                $aulas_com_status = [];

                foreach ($aulas as $aula) {
                    // Calcula data de liberação da aula
                    $lesson_release_date = clone $data_concessao;
                    $lesson_release_date->modify("+{$aula['release_days']} days");
                    $aula_is_locked = ($current_date < $lesson_release_date);

                    // Só conta aulas desbloqueadas
                    if (!$aula_is_locked) {
                        $modulo_total_aulas++;
                        $total_aulas++;
                    }

                    // Verifica se aula foi concluída
                    $stmt_progresso = $pdo->prepare("SELECT data_conclusao FROM aluno_progresso WHERE aluno_email = ? AND aula_id = ?");
                    $stmt_progresso->execute([$email, $aula['id']]);
                    $progresso = $stmt_progresso->fetch(PDO::FETCH_ASSOC);

                    $concluida = !empty($progresso);
                    if ($concluida && !$aula_is_locked) {
                        $modulo_aulas_concluidas++;
                        $aulas_concluidas++;
                    }

                    $aulas_com_status[] = [
                        'aula_id' => $aula['id'],
                        'titulo' => $aula['titulo'],
                        'concluida' => $concluida,
                        'is_locked' => $aula_is_locked,
                        'data_conclusao' => $progresso ? $progresso['data_conclusao'] : null
                    ];
                }

                $modulo_progresso = $modulo_total_aulas > 0 ? round(($modulo_aulas_concluidas / $modulo_total_aulas) * 100) : 0;

                $modulos_com_progresso[] = [
                    'modulo_id' => $modulo['id'],
                    'titulo' => $modulo['titulo'],
                    'total_aulas' => $modulo_total_aulas,
                    'aulas_concluidas' => $modulo_aulas_concluidas,
                    'progresso_percentual' => $modulo_progresso,
                    'is_locked' => $is_locked,
                    'aulas' => $aulas_com_status
                ];
            }

            $progresso_geral = $total_aulas > 0 ? round(($aulas_concluidas / $total_aulas) * 100) : 0;

            sendJsonResponse(true, [
                'progresso_geral' => $progresso_geral,
                'total_aulas' => $total_aulas,
                'aulas_concluidas' => $aulas_concluidas,
                'modulos' => $modulos_com_progresso
            ]);

        } catch (PDOException $e) {
            error_log("Erro de DB ao buscar progresso: " . $e->getMessage());
            sendJsonResponse(false, ['error' => 'Erro interno do servidor ao buscar progresso.'], 500);
        }
        break;

    case 'remove':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            sendJsonResponse(false, ['error' => 'Método não permitido. Use POST.'], 405);
        }

        $email = trim($input['email'] ?? '');
        $produto_id = $input['produto_id'] ?? null;

        if (empty($email)) {
            sendJsonResponse(false, ['error' => 'Email do aluno é obrigatório.'], 400);
        }

        if (!$produto_id || !is_numeric($produto_id)) {
            sendJsonResponse(false, ['error' => 'ID do produto é obrigatório e deve ser numérico.'], 400);
        }

        try {
            // Valida que o produto pertence ao infoprodutor
            $stmt_produto = $pdo->prepare("SELECT id FROM produtos WHERE id = ? AND usuario_id = ?");
            $stmt_produto->execute([$produto_id, $usuario_id]);
            $produto = $stmt_produto->fetch(PDO::FETCH_ASSOC);

            if (!$produto) {
                sendJsonResponse(false, ['error' => 'Produto não encontrado ou não pertence a você.'], 404);
            }

            // Remove acesso
            $stmt_remove = $pdo->prepare("DELETE FROM alunos_acessos WHERE aluno_email = ? AND produto_id = ?");
            $stmt_remove->execute([$email, $produto_id]);

            if ($stmt_remove->rowCount() > 0) {
                sendJsonResponse(true, ['message' => 'Acesso do aluno removido com sucesso.']);
            } else {
                sendJsonResponse(false, ['error' => 'Aluno não possui acesso a este produto.'], 404);
            }

        } catch (PDOException $e) {
            error_log("Erro de DB ao remover aluno: " . $e->getMessage());
            sendJsonResponse(false, ['error' => 'Erro interno do servidor ao remover acesso.'], 500);
        }
        break;

    default:
        sendJsonResponse(false, ['error' => 'Ação não reconhecida.'], 400);
        break;
}

