<?php
// Desabilitar exibição de erros imediatamente
ini_set('display_errors', 0);
ini_set('html_errors', 0);
error_reporting(E_ALL);

// Inicia o buffer de saída no início do script para capturar qualquer saída indesejada,
// como espaços em branco antes da tag <?php ou de arquivos incluídos.
ob_start();

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Aplicar headers de segurança antes de qualquer output
require_once __DIR__ . '/../config/security_headers.php';
if (function_exists('apply_security_headers')) {
    apply_security_headers(false); // CSP permissivo para APIs
}

// Definir header JSON imediatamente
header('Content-Type: application/json; charset=utf-8');

// Variável global para cache do input (evita ler php://input múltiplas vezes)
$GLOBALS['_admin_api_input_cache'] = null;

// Inicializar cache global se não existir
if (!isset($GLOBALS['_admin_api_input_cache'])) {
    $GLOBALS['_admin_api_input_cache'] = null;
}

// Função helper para verificar CSRF em ações que modificam dados
function require_csrf_for_modifying_actions() {
    require_once __DIR__ . '/../helpers/security_helper.php';
    
    if ($_SERVER['REQUEST_METHOD'] === 'GET' || $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        return true; // GET e OPTIONS não precisam de CSRF
    }
    
    $csrf_token = null;
    
    // Prioridade: JSON (mais comum em requisições AJAX) > Header > POST
    // Ler JSON primeiro (mais comum em requisições AJAX)
    if (!isset($GLOBALS['_admin_api_input_cache']) || $GLOBALS['_admin_api_input_cache'] === null) {
        $GLOBALS['_admin_api_input_cache'] = file_get_contents('php://input');
    }
    $input = json_decode($GLOBALS['_admin_api_input_cache'], true);
    if (isset($input['csrf_token']) && !empty($input['csrf_token'])) {
        $csrf_token = $input['csrf_token'];
    } elseif (isset($_SERVER['HTTP_X_CSRF_TOKEN']) && !empty($_SERVER['HTTP_X_CSRF_TOKEN'])) {
        $csrf_token = $_SERVER['HTTP_X_CSRF_TOKEN'];
    } elseif (isset($_POST['csrf_token']) && !empty($_POST['csrf_token'])) {
        $csrf_token = $_POST['csrf_token'];
    }
    
    if (empty($csrf_token)) {
        error_log("ADMIN_API CSRF: Token ausente ou vazio");
        http_response_code(403);
        ob_clean();
        echo json_encode(['success' => false, 'error' => 'Token CSRF inválido ou ausente']);
        exit;
    }
    
    if (!verify_csrf_token($csrf_token)) {
        error_log("ADMIN_API CSRF: Token inválido");
        http_response_code(403);
        ob_clean();
        echo json_encode(['success' => false, 'error' => 'Token CSRF inválido ou ausente']);
        exit;
    }
    
    return true;
}

// Incluir PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

// Ativar log de erros detalhado (APENAS PARA DEPURAÇÃO - REMOVA EM PRODUÇÃO!)
error_reporting(E_ALL); // Exibe todos os erros no log
ini_set('display_errors', 0); // DESABILITAR exibição de erros no navegador para APIs
ini_set('log_errors', 1); // Habilita o log de erros
ini_set('error_log', __DIR__ . '/../admin_api_errors.log'); // Opcional: log personalizado para esta API, ou use o padrão do PHP

// Garantir que nenhum output é enviado antes do JSON
if (ob_get_level() > 0) {
    ob_clean();
}

// CORREÇÃO: Ajustar os caminhos para o PHPMailer com base na informação do usuário.
// O usuário informou que a pasta 'PHPMailer' está diretamente em 'starfy 10000/'.
// __DIR__ garante um caminho absoluto.
$phpmailer_path = __DIR__ . '/../PHPMailer/src/';

if (file_exists($phpmailer_path . 'Exception.php')) {
    require_once $phpmailer_path . 'Exception.php';
} else {
    error_log("ADMIN_API: ERRO: Exception.php não encontrado em " . $phpmailer_path . 'Exception.php');
}

if (file_exists($phpmailer_path . 'PHPMailer.php')) {
    require_once $phpmailer_path . 'PHPMailer.php';
} else {
    error_log("ADMIN_API: ERRO: PHPMailer.php não encontrado em " . $phpmailer_path . 'PHPMailer.php');
}

if (file_exists($phpmailer_path . 'SMTP.php')) {
    require_once $phpmailer_path . 'SMTP.php';
} else {
    error_log("ADMIN_API: ERRO: SMTP.php não encontrado em " . $phpmailer_path . 'SMTP.php');
}


try {
    require_once __DIR__ . '/../config/config.php';
    require_once __DIR__ . '/../helpers/security_helper.php';
    require_once __DIR__ . '/../helpers/migration_helper.php';
    
    // Se não houver token na sessão, gerar um (pode acontecer se a sessão foi criada em outro contexto)
    if (empty($_SESSION['csrf_token'])) {
        generate_csrf_token();
    }

    $action = $_GET['action'] ?? '';
    
    // Lista de ações que podem ser acessadas por usuários logados (não apenas admins)
    $public_actions = ['get_pwa_config', 'register_push_subscription', 'get_vapid_keys'];
    
    // Verificação de segurança: Apenas admins logados podem acessar esta API
    // EXCETO para ações públicas listadas acima
    if (!in_array($action, $public_actions)) {
        // Usa função centralizada de autenticação admin
        require_admin_auth(true); // true = retorna JSON ao invés de redirecionar
    } else {
        // Para ações públicas, apenas verifica se está logado
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
            http_response_code(403);
            ob_clean();
            echo json_encode(['error' => 'Acesso não autorizado. Faça login primeiro.']);
            exit;
        }
    }

    // Função auxiliar para obter configurações SMTP, incluindo a senha do BD se não fornecida
    function getSmtpConfigFromRequest($pdo, $input_data) {
        $smtp_config = [
            'host' => $input_data['smtp_host'] ?? '',
            'port' => (int)($input_data['smtp_port'] ?? 587),
            'username' => $input_data['smtp_username'] ?? '',
            'encryption' => $input_data['smtp_encryption'] ?? 'tls',
            'from_email' => $input_data['smtp_from_email'] ?? '',
            'from_name' => $input_data['smtp_from_name'] ?? 'Starfy',
        ];


        // Se a senha não foi fornecida no POST (frontend deixou em branco), busca a do BD
        if (empty($input_data['smtp_password'])) {
            $stmt = $pdo->query("SELECT valor FROM configuracoes WHERE chave = 'smtp_password'");
            $db_password = $stmt->fetchColumn() ?? '';
            $smtp_config['password'] = $db_password;
            if (empty($db_password)) {
            }
        } else {
            $smtp_config['password'] = $input_data['smtp_password'];
        }
        return $smtp_config;
    }


    if ($action == 'get_admin_dashboard_data') {
        $response = [
            'kpis' => [],
            'chart' => [],
            'top_products' => [],
            'recent_users' => [],
            'top_sellers' => [] // Novo array para o ranking de vendedores
        ];

        // --- KPIs ---
        // ALTERAÇÃO: Contar usuários do tipo 'infoprodutor'
        $response['kpis']['total_usuarios'] = $pdo->query("SELECT COUNT(id) FROM usuarios WHERE tipo = 'infoprodutor'")->fetchColumn();
        $response['kpis']['produtos_ativos'] = $pdo->query("SELECT COUNT(id) FROM produtos")->fetchColumn();
        
        $stmt_vendas = $pdo->query("SELECT COUNT(id) as total, COALESCE(SUM(valor), 0) as faturamento FROM vendas WHERE status_pagamento = 'approved'");
        $vendas_data = $stmt_vendas->fetch(PDO::FETCH_ASSOC);
        $response['kpis']['vendas_aprovadas'] = $vendas_data['total'];
        $response['kpis']['faturamento_total'] = $vendas_data['faturamento'];

        // --- Dados do Gráfico (Últimos 30 dias) ---
        $chart_labels = [];
        $chart_data_template = [];
        for ($i = 29; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i days"));
            $chart_labels[] = date('d/m', strtotime($date));
            $chart_data_template[$date] = 0;
        }

        $sql_chart = "SELECT CAST(data_venda AS DATE) as dia, SUM(valor) as total_dia 
                      FROM vendas 
                      WHERE status_pagamento = 'approved' AND data_venda >= CURDATE() - INTERVAL 29 DAY 
                      GROUP BY dia ORDER BY dia ASC";
        $stmt_chart = $pdo->query($sql_chart);
        $vendas_chart_data = $stmt_chart->fetchAll(PDO::FETCH_KEY_PAIR);

        foreach ($vendas_chart_data as $dia => $total) {
            if (array_key_exists($dia, $chart_data_template)) {
                $chart_data_template[$dia] = (float)$total;
            }
        }
        $response['chart']['labels'] = $chart_labels;
        $response['chart']['data'] = array_values($chart_data_template);

        // --- Produtos Mais Vendidos ---
        $sql_top_products = "SELECT p.nome, p.foto, COUNT(v.id) as total_vendas, SUM(v.valor) as faturamento_total
                             FROM vendas v
                             JOIN produtos p ON v.produto_id = p.id
                             WHERE v.status_pagamento = 'approved'
                             GROUP BY v.produto_id, p.nome, p.foto
                             ORDER BY total_vendas DESC, faturamento_total DESC
                             LIMIT 5";
        $response['top_products'] = $pdo->query($sql_top_products)->fetchAll(PDO::FETCH_ASSOC);

        // --- Usuários Recentes ---
        $sql_recent_users = "SELECT id, usuario, nome, telefone, tipo FROM usuarios ORDER BY id DESC LIMIT 5";
        $response['recent_users'] = $pdo->query($sql_recent_users)->fetchAll(PDO::FETCH_ASSOC);
        
        // --- NOVO: Ranking de Vendedores ---
        $sql_top_sellers = "SELECT 
                                u.id, 
                                u.usuario, 
                                u.nome, 
                                u.foto_perfil, 
                                COUNT(v.id) as total_vendas, 
                                SUM(v.valor) as faturamento_total
                            FROM vendas v
                            JOIN produtos p ON v.produto_id = p.id
                            JOIN usuarios u ON p.usuario_id = u.id
                            WHERE v.status_pagamento = 'approved'
                            GROUP BY u.id, u.usuario, u.nome, u.foto_perfil
                            ORDER BY faturamento_total DESC
                            LIMIT 5";
        $response['top_sellers'] = $pdo->query($sql_top_sellers)->fetchAll(PDO::FETCH_ASSOC);

        // Limpa o buffer antes de enviar o JSON
        ob_clean();
        echo json_encode($response);
        exit;
    } 
    
    // NOVO: GET_USERS
    elseif ($action == 'get_users') {
        $search = $_GET['search'] ?? '';
        $page = isset($_GET['page']) && is_numeric($_GET['page']) ? (int)$_GET['page'] : 1;
        $limit = 10;
        $offset = ($page - 1) * $limit;
        $role = $_GET['role'] ?? 'all'; // Capture the role filter

        $where_conditions = [];
        $params = [];

        // Apply search filter
        // Alias 'usuarios' as 'u' for consistency
        if (!empty($search)) {
            $where_conditions[] = "(u.nome LIKE :search OR u.usuario LIKE :search)";
            $params[':search'] = "%" . $search . "%";
        }

        // ALTERAÇÃO: Lógica de filtro de função atualizada
        // Apply role filter
        switch ($role) {
            case 'infoproducer':
                // Um infoprodutor agora é 'infoprodutor'
                $where_conditions[] = "u.tipo = 'infoprodutor'";
                break;
            case 'client':
                // Um cliente agora é 'usuario'
                $where_conditions[] = "u.tipo = 'usuario'";
                break;
            case 'all':
                // 'all' inclui admin, infoprodutor, e usuario (cliente)
                // Nenhuma condição extra necessária.
                break;
            default:
                // Se um papel inválido for passado, o padrão é 'all' (sem condições extras)
                break;
        }
        // FIM DA ALTERAÇÃO

        $where_clause = empty($where_conditions) ? '' : 'WHERE ' . implode(' AND ', $where_conditions);

        // Contar total de registros
        // Use alias 'u' for 'usuarios' table
        $stmt_count = $pdo->prepare("SELECT COUNT(u.id) FROM usuarios u {$where_clause}");
        $stmt_count->execute($params);
        $total_records = $stmt_count->fetchColumn();
        $total_pages = $total_records > 0 ? ceil($total_records / $limit) : 1;

        // Buscar usuários
        // Use alias 'u' for 'usuarios' table
        $sql = "SELECT u.id, u.usuario, u.nome, u.telefone, u.tipo FROM usuarios u {$where_clause} ORDER BY u.id DESC LIMIT :limit OFFSET :offset";
        $stmt = $pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Limpa o buffer antes de enviar o JSON
        ob_clean();
        // Garantir que o header JSON está definido
        header('Content-Type: application/json');
        echo json_encode([
            'users' => $users,
            'pagination' => [
                'currentPage' => $page,
                'totalPages' => $total_pages,
                'totalRecords' => $total_records
            ]
        ]);
        exit;
    }

    // NOVO: GET_USER_DETAILS
    elseif ($action == 'get_user_details') {
        $user_id = $_GET['id'] ?? null;
        if (!$user_id) {
            http_response_code(400);
            error_log("ADMIN_API: Erro (get_user_details): ID do usuário ausente.");
            // Limpa o buffer antes de enviar o JSON
            ob_clean();
            echo json_encode(['error' => 'ID do usuário ausente.']);
            exit;
        }
        $stmt = $pdo->prepare("SELECT id, usuario, nome, telefone, tipo FROM usuarios WHERE id = :id");
        $stmt->bindParam(':id', $user_id, PDO::PARAM_INT);
        $stmt->execute();
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        // Buscar plano atual do usuário (se for infoprodutor e SaaS estiver habilitado)
        if ($user && $user['tipo'] === 'infoprodutor') {
            if (file_exists(__DIR__ . '/../saas/includes/saas_functions.php')) {
                require_once __DIR__ . '/../saas/includes/saas_functions.php';
                if (function_exists('saas_get_user_plan')) {
                    $plano_info = saas_get_user_plan($user_id);
                    if ($plano_info) {
                        $user['plano_id'] = $plano_info['plano_id'];
                    } else {
                        $user['plano_id'] = null;
                    }
                }
            }
        }

        // Limpa o buffer antes de enviar o JSON
        ob_clean();
        if ($user) {
            echo json_encode($user);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Usuário não encontrado.']);
        }
        exit;
    }

    // NOVO: CREATE_USER
    elseif ($action == 'create_user' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        // Verifica CSRF primeiro
        require_csrf_for_modifying_actions();
        
        $input = json_decode(file_get_contents('php://input'), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("ADMIN_API: Erro ao decodificar JSON para 'create_user': " . json_last_error_msg());
            http_response_code(400);
            // Limpa o buffer antes de enviar o JSON
            ob_clean();
            echo json_encode(['error' => 'Dados JSON inválidos.']);
            exit;
        }

        $nome = trim($input['nome'] ?? '');
        $email = trim($input['email'] ?? '');
        $telefone = trim($input['telefone'] ?? '');
        $senha = trim($input['senha'] ?? '');
        // ALTERAÇÃO: O padrão agora é 'infoprodutor' para corresponder ao formulário do admin
        $tipo = trim($input['tipo'] ?? 'infoprodutor');
        $plano_id = !empty($input['plano_id']) ? (int)$input['plano_id'] : null; // Plano SaaS (opcional)

        // SEGURANÇA: Validar tipo de usuário permitido (whitelist)
        $tipos_permitidos = ['infoprodutor', 'usuario', 'admin'];
        if (!in_array($tipo, $tipos_permitidos)) {
            http_response_code(400);
            error_log("ADMIN_API: Erro (create_user): Tipo de usuário inválido: " . $tipo);
            ob_clean();
            echo json_encode(['error' => 'Tipo de usuário inválido.']);
            exit;
        }

        if (empty($nome) || empty($email) || empty($tipo)) {
            http_response_code(400);
            error_log("ADMIN_API: Erro (create_user): Nome, e-mail e tipo são obrigatórios.");
            // Limpa o buffer antes de enviar o JSON
            ob_clean();
            echo json_encode(['error' => 'Nome, e-mail e tipo são obrigatórios.']);
            exit;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            error_log("ADMIN_API: Erro (create_user): Formato de e-mail inválido.");
            // Limpa o buffer antes de enviar o JSON
            ob_clean();
            echo json_encode(['error' => 'Formato de e-mail inválido.']);
            exit;
        }

        // Verifica se o e-mail já existe
        $stmt_check = $pdo->prepare("SELECT id FROM usuarios WHERE usuario = :email");
        $stmt_check->bindParam(':email', $email);
        $stmt_check->execute();
        if ($stmt_check->rowCount() > 0) {
            http_response_code(409); // Conflict
            error_log("ADMIN_API: Erro (create_user): E-mail já cadastrado.");
            // Limpa o buffer antes de enviar o JSON
            ob_clean();
            echo json_encode(['error' => 'Este e-mail já está cadastrado.']);
            exit;
        }

        // Gera uma senha padrão se não for fornecida
        if (empty($senha)) {
            $senha = bin2hex(random_bytes(8)); // Senha aleatória
            error_log("ADMIN_API: (create_user): Senha padrão gerada para novo usuário.");
        }
        $hashed_password = password_hash($senha, PASSWORD_DEFAULT);

        $stmt = $pdo->prepare("INSERT INTO usuarios (nome, usuario, telefone, senha, tipo) VALUES (:nome, :email, :telefone, :senha, :tipo)");
        $stmt->bindParam(':nome', $nome);
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':telefone', $telefone);
        $stmt->bindParam(':senha', $hashed_password);
        $stmt->bindParam(':tipo', $tipo);

        error_log("ADMIN_API: Preparing JSON response for action: create_user");
        // Limpa o buffer antes de enviar o JSON
        ob_clean();
        if ($stmt->execute()) {
            $new_user_id = $pdo->lastInsertId();
            
            // Atribuir plano SaaS se fornecido e se for infoprodutor
            if ($tipo === 'infoprodutor' && $plano_id !== null) {
                if (file_exists(__DIR__ . '/../saas/includes/saas_functions.php')) {
                    require_once __DIR__ . '/../saas/includes/saas_functions.php';
                    if (function_exists('saas_enabled') && saas_enabled()) {
                        try {
                            // Verificar se o plano existe e está ativo
                            $stmt_plano = $pdo->prepare("SELECT id, periodo FROM saas_planos WHERE id = ? AND ativo = 1");
                            $stmt_plano->execute([$plano_id]);
                            $plano = $stmt_plano->fetch(PDO::FETCH_ASSOC);
                            
                            if ($plano) {
                                // Calcular data de vencimento baseado no período
                                $data_inicio = date('Y-m-d');
                                if ($plano['periodo'] === 'anual') {
                                    $data_vencimento = date('Y-m-d', strtotime('+1 year'));
                                } else {
                                    $data_vencimento = date('Y-m-d', strtotime('+30 days'));
                                }
                                
                                // Criar nova assinatura
                                $stmt_assinatura = $pdo->prepare("
                                    INSERT INTO saas_assinaturas 
                                    (usuario_id, plano_id, status, data_inicio, data_vencimento) 
                                    VALUES (?, ?, 'ativo', ?, ?)
                                ");
                                $stmt_assinatura->execute([$new_user_id, $plano_id, $data_inicio, $data_vencimento]);
                                error_log("ADMIN_API: Plano ID $plano_id atribuído ao novo usuário ID $new_user_id");
                            }
                        } catch (PDOException $e) {
                            error_log("ADMIN_API: Erro ao atribuir plano ao criar usuário: " . $e->getMessage());
                            // Não falha a criação do usuário se houver erro no plano
                        }
                    }
                }
            }
            
            echo json_encode(['success' => true, 'message' => 'Usuário criado com sucesso!', 'id' => $new_user_id]);
        } else {
            http_response_code(500);
            error_log("ADMIN_API: Erro (create_user): Erro ao criar usuário no banco de dados.");
            echo json_encode(['error' => 'Erro ao criar usuário no banco de dados.']);
        }
        exit;
    }

    // NOVO: UPDATE_USER
    elseif ($action == 'update_user' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        // Verifica CSRF primeiro (usa header preferencialmente)
        require_csrf_for_modifying_actions();
        
        // Usa cache global se disponível, senão lê o input
        if ($GLOBALS['_admin_api_input_cache'] !== null) {
            $input = json_decode($GLOBALS['_admin_api_input_cache'], true);
        } else {
            $input = json_decode(file_get_contents('php://input'), true);
        }
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("ADMIN_API: Erro ao decodificar JSON para 'update_user': " . json_last_error_msg());
            http_response_code(400);
            // Limpa o buffer antes de enviar o JSON
            ob_clean();
            echo json_encode(['error' => 'Dados JSON inválidos.']);
            exit;
        }

        $user_id = $input['user_id'] ?? null;
        $nome = trim($input['nome'] ?? '');
        $email = trim($input['email'] ?? ''); // Email é importante para saber qual usuário atualizar
        $telefone = trim($input['telefone'] ?? '');
        $senha = trim($input['senha'] ?? '');
        $tipo = trim($input['tipo'] ?? 'usuario'); // O padrão 'usuario' (cliente) é seguro aqui
        
        // SEGURANÇA: Validar tipo de usuário permitido (whitelist)
        $tipos_permitidos = ['infoprodutor', 'usuario', 'admin'];
        if (!in_array($tipo, $tipos_permitidos)) {
            http_response_code(400);
            error_log("ADMIN_API: Erro (update_user): Tipo de usuário inválido: " . $tipo);
            ob_clean();
            echo json_encode(['error' => 'Tipo de usuário inválido.']);
            exit;
        }
        
        // Plano SaaS (opcional) - tratar string vazia como null
        $plano_id_raw = $input['plano_id'] ?? '';
        $plano_id = (!empty($plano_id_raw) && $plano_id_raw !== '' && $plano_id_raw !== '0') ? (int)$plano_id_raw : null;
        error_log("ADMIN_API: (update_user) plano_id recebido: " . var_export($plano_id_raw, true) . " -> processado: " . var_export($plano_id, true));

        if (empty($user_id) || empty($nome) || empty($email) || empty($tipo)) {
            http_response_code(400);
            error_log("ADMIN_API: Erro (update_user): ID do usuário, nome, e-mail e tipo são obrigatórios.");
            // Limpa o buffer antes de enviar o JSON
            ob_clean();
            echo json_encode(['error' => 'ID do usuário, nome, e-mail e tipo são obrigatórios.']);
            exit;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            error_log("ADMIN_API: Erro (update_user): Formato de e-mail inválido.");
            // Limpa o buffer antes de enviar o JSON
            ob_clean();
            echo json_encode(['error' => 'Formato de e-mail inválido.']);
            exit;
        }

        // Verifica se o usuário a ser editado existe
        $stmt_check = $pdo->prepare("SELECT id, tipo FROM usuarios WHERE id = :id");
        $stmt_check->bindParam(':id', $user_id, PDO::PARAM_INT);
        $stmt_check->execute();
        $existing_user = $stmt_check->fetch(PDO::FETCH_ASSOC);

        if (!$existing_user) {
            http_response_code(404);
            error_log("ADMIN_API: Erro (update_user): Usuário não encontrado para atualização (ID: $user_id).");
            // Limpa o buffer antes de enviar o JSON
            ob_clean();
            echo json_encode(['error' => 'Usuário não encontrado para atualização.']);
            exit;
        }
        
        // Impede que um admin edite a si mesmo para um tipo não-admin
        if ($user_id == $_SESSION['id'] && $existing_user['tipo'] === 'admin' && $tipo !== 'admin') {
             http_response_code(403);
             error_log("ADMIN_API: Erro (update_user): Tentativa de alterar o tipo do próprio admin (ID: $user_id).");
             ob_clean();
             echo json_encode(['error' => 'Não é permitido alterar o tipo do próprio usuário administrador.']);
             exit;
        }


        $update_fields = ['nome = :nome', 'usuario = :email', 'telefone = :telefone', 'tipo = :tipo']; // Adicionado 'usuario = :email'
        $params = [
            ':nome' => $nome,
            ':email' => $email, // Adicionado :email
            ':telefone' => $telefone,
            ':tipo' => $tipo,
            ':id' => $user_id
        ];

        if (!empty($senha)) {
            // Se uma nova senha for fornecida, hash e adicione ao update
            $hashed_password = password_hash($senha, PASSWORD_DEFAULT);
            $update_fields[] = 'senha = :senha';
            $params[':senha'] = $hashed_password;
            error_log("ADMIN_API: (update_user): Senha atualizada para usuário ID: $user_id.");
        }

        $sql = "UPDATE usuarios SET " . implode(', ', $update_fields) . " WHERE id = :id";
        $stmt = $pdo->prepare($sql);

        error_log("ADMIN_API: Preparing JSON response for action: update_user");
        // Limpa o buffer antes de enviar o JSON
        ob_clean();
        if ($stmt->execute($params)) {
            // Se o admin logado atualizou o próprio e-mail, atualiza a sessão
            if ($user_id == $_SESSION['id'] && $existing_user['tipo'] === 'admin') {
                $_SESSION['usuario'] = $email;
                error_log("ADMIN_API: E-mail do admin logado atualizado na sessão para: " . $email);
            }
            
            // Atribuir/atualizar plano SaaS se fornecido e se for infoprodutor
            if ($tipo === 'infoprodutor' && $plano_id !== null && $plano_id > 0) {
                if (file_exists(__DIR__ . '/../saas/includes/saas_functions.php')) {
                    require_once __DIR__ . '/../saas/includes/saas_functions.php';
                    if (function_exists('saas_enabled') && saas_enabled()) {
                        try {
                            // Verificar se o plano existe e está ativo
                            $stmt_plano = $pdo->prepare("SELECT id, periodo FROM saas_planos WHERE id = ? AND ativo = 1");
                            $stmt_plano->execute([$plano_id]);
                            $plano = $stmt_plano->fetch(PDO::FETCH_ASSOC);
                            
                            if ($plano) {
                                // Cancelar assinaturas ativas anteriores
                                $stmt_cancel = $pdo->prepare("UPDATE saas_assinaturas SET status = 'cancelado' WHERE usuario_id = ? AND status = 'ativo'");
                                $stmt_cancel->execute([$user_id]);
                                
                                // Calcular data de vencimento baseado no período
                                $data_inicio = date('Y-m-d');
                                if ($plano['periodo'] === 'anual') {
                                    $data_vencimento = date('Y-m-d', strtotime('+1 year'));
                                } else {
                                    $data_vencimento = date('Y-m-d', strtotime('+30 days'));
                                }
                                
                                // Criar nova assinatura
                                $stmt_assinatura = $pdo->prepare("
                                    INSERT INTO saas_assinaturas 
                                    (usuario_id, plano_id, status, data_inicio, data_vencimento) 
                                    VALUES (?, ?, 'ativo', ?, ?)
                                ");
                                $stmt_assinatura->execute([$user_id, $plano_id, $data_inicio, $data_vencimento]);
                                error_log("ADMIN_API: Plano ID $plano_id atribuído ao usuário ID $user_id");
                            }
                        } catch (PDOException $e) {
                            error_log("ADMIN_API: Erro ao atribuir plano: " . $e->getMessage());
                            // Não falha a atualização do usuário se houver erro no plano
                        }
                    }
                }
            } elseif ($tipo === 'infoprodutor' && $plano_id === null) {
                // Se plano_id for null/vazio, cancelar assinaturas ativas (remover plano)
                if (file_exists(__DIR__ . '/../saas/includes/saas_functions.php')) {
                    require_once __DIR__ . '/../saas/includes/saas_functions.php';
                    if (function_exists('saas_enabled') && saas_enabled()) {
                        try {
                            $stmt_cancel = $pdo->prepare("UPDATE saas_assinaturas SET status = 'cancelado' WHERE usuario_id = ? AND status = 'ativo'");
                            $stmt_cancel->execute([$user_id]);
                            error_log("ADMIN_API: Planos cancelados para usuário ID $user_id");
                        } catch (PDOException $e) {
                            error_log("ADMIN_API: Erro ao cancelar planos: " . $e->getMessage());
                        }
                    }
                }
            }
            
            echo json_encode(['success' => true, 'message' => 'Usuário atualizado com sucesso!']);
        } else {
            http_response_code(500);
            error_log("ADMIN_API: Erro (update_user): Erro ao atualizar usuário no banco de dados (ID: $user_id).");
            echo json_encode(['error' => 'Erro ao atualizar usuário no banco de dados.']);
        }
        exit;
    }

    // NOVO: DELETE_USER
    elseif ($action == 'delete_user' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf_for_modifying_actions();
        $input = json_decode(file_get_contents('php://input'), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("ADMIN_API: Erro ao decodificar JSON para 'delete_user': " . json_last_error_msg());
            http_response_code(400);
            // Limpa o buffer antes de enviar o JSON
            ob_clean();
            echo json_encode(['error' => 'Dados JSON inválidos.']);
            exit;
        }

        $user_id = $input['user_id'] ?? null;

        if (empty($user_id)) {
            http_response_code(400);
            error_log("ADMIN_API: Erro (delete_user): ID do usuário ausente.");
            // Limpa o buffer antes de enviar o JSON
            ob_clean();
            echo json_encode(['error' => 'ID do usuário ausente.']);
            exit;
        }

        // Impede que o próprio admin logado seja deletado
        if ($user_id == $_SESSION['id']) {
            http_response_code(403);
            error_log("ADMIN_API: Erro (delete_user): Tentativa de deletar o próprio usuário administrador (ID: $user_id).");
            // Limpa o buffer antes de enviar o JSON
            ob_clean();
            echo json_encode(['error' => 'Não é permitido deletar o próprio usuário administrador.']);
            exit;
        }

        $stmt = $pdo->prepare("DELETE FROM usuarios WHERE id = :id");
        $stmt->bindParam(':id', $user_id, PDO::PARAM_INT);

        error_log("ADMIN_API: Preparing JSON response for action: delete_user");
        // Limpa o buffer antes de enviar o JSON
        ob_clean();
        if ($stmt->execute()) {
            if ($stmt->rowCount() > 0) {
                echo json_encode(['success' => true, 'message' => 'Usuário deletado com sucesso!']);
            } else {
                http_response_code(404);
                error_log("ADMIN_API: Erro (delete_user): Usuário não encontrado para deletar (ID: $user_id).");
                echo json_encode(['error' => 'Usuário não encontrado.']);
            }
        } else {
            http_response_code(500);
            error_log("ADMIN_API: Erro (delete_user): Erro ao deletar usuário no banco de dados (ID: $user_id).");
            echo json_encode(['error' => 'Erro ao deletar usuário no banco de dados.']);
        }
        exit;
    }

    // NOVO: Ação para obter configurações de e-mail e entrega
    elseif ($action === 'get_email_settings') {
        error_log("ADMIN_API: Recebida ação get_email_settings.");
        $configs = [];
        $stmt = $pdo->query("SELECT chave, valor FROM configuracoes WHERE chave IN (
            'smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_encryption', 'smtp_from_email', 'smtp_from_name',
            'email_template_delivery_subject', 'email_template_delivery_html', 'member_area_login_url'
        )");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $configs[$row['chave']] = $row['valor'];
        }

        // Definir valores padrão para o template e o assunto, se não existirem no DB
        $nome_plataforma_default = getSystemSetting('nome_plataforma', 'Starfy');
        $default_subject = "Acesso ao seu Produto " . $nome_plataforma_default . "!";
        
        // Obtém a URL da logo do checkout (ou logo padrão se não houver)
        $logo_checkout_url_raw = getSystemSetting('logo_checkout_url', '');
        $logo_url_raw = getSystemSetting('logo_url', 'https://i.ibb.co/2YRWNQw7/175799548831-Photoroom.png');
        
        // Normaliza a URL da logo do checkout
        $logo_checkout_final = '';
        if (empty($logo_checkout_url_raw)) {
            // Se não tem logo checkout, usa a logo padrão
            $logo_checkout_final = $logo_url_raw;
        } else {
            $logo_checkout_final = $logo_checkout_url_raw;
        }
        
        // Remove barra inicial se houver
        $logo_checkout_final = ltrim($logo_checkout_final, '/');
        
        // Se não começa com http, adiciona o domínio base
        if (!empty($logo_checkout_final) && strpos($logo_checkout_final, 'http') !== 0) {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
            $domainName = $_SERVER['HTTP_HOST'];
            $logo_checkout_final = $protocol . $domainName . '/' . $logo_checkout_final;
        }
        
        $default_html_template = '
            <html>
            <head>
                <style>
                    body { font-family: \'Helvetica Neue\', Helvetica, Arial, sans-serif; background-color: #f7f7f7; color: #333; margin: 0; padding: 20px; }
                    .container { max-width: 600px; margin: 20px auto; background-color: #fff; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.08); overflow: hidden; }
                    .header { background-color: #f97316; padding: 30px; text-align: center; color: #fff; }
                    .header img { max-width: 200px; height: auto; margin-bottom: 20px; }
                    .header h1 { margin: 0; font-size: 28px; }
                    .content { padding: 30px; line-height: 1.6; font-size: 16px; }
                    .content p { margin-bottom: 15px; }
                    .product-section { background-color: #f0fdf4; border-left: 5px solid #22c55e; padding: 15px; border-radius: 5px; margin-bottom: 20px; }
                    .product-section h3 { margin-top: 0; color: #16a34a; }
                    .button { display: inline-block; background-color: #16a34a; color: #fff; padding: 12px 25px; border-radius: 5px; text-decoration: none; font-weight: bold; margin-top: 10px; }
                    .footer { background-color: #f0f0f0; padding: 20px; text-align: center; font-size: 12px; color: #777; border-top: 1px solid #eee; }
                </style>
            </head>
            <body>
                <div class="container">
                    <div class="header">
                        ' . (!empty($logo_checkout_final) ? '<img src="' . htmlspecialchars($logo_checkout_final) . '" alt="' . htmlspecialchars($nome_plataforma_default) . '" />' : '') . '
                        <h1>Parabéns, {CLIENT_NAME}!</h1>
                    </div>
                    <div class="content">
                        <p>Seus produtos adquiridos na ' . htmlspecialchars($nome_plataforma_default) . ' foram liberados com sucesso!</p>
                        <p>Abaixo estão os detalhes de acesso para cada um deles:</p>
                        
                        <!-- LOOP_PRODUCTS_START -->
                        <div class="product-section">
                            <h3>{PRODUCT_NAME}</h3>
                            <!-- IF_PRODUCT_TYPE_LINK -->
                            <p><strong>Link de Acesso:</strong></p>
                            <p style="text-align: center;"><a href="{PRODUCT_LINK}" class="button">Acessar {PRODUCT_NAME}</a></p>
                            <p style="word-break: break-all; font-size: 14px;">Se o botão não funcionar, copie e cole o link: <a href="{PRODUCT_LINK}">{PRODUCT_LINK}</a></p>
                            <!-- END_IF_PRODUCT_TYPE_LINK -->

                            <!-- IF_PRODUCT_TYPE_PDF -->
                            <p>Seu PDF está anexado a este e-mail. Faça o download para começar a aproveitar!</p>
                            <!-- END_IF_PRODUCT_TYPE_PDF -->

                            <!-- IF_PRODUCT_TYPE_MEMBER_AREA -->
                            <p>Este produto está disponível em sua área de membros.</p>
                            <p>Seu login é seu e-mail: <strong>{CLIENT_EMAIL}</strong></p>
                            <p>Sua senha: <strong>{MEMBER_AREA_PASSWORD}</strong> (foi gerada automaticamente)</p>
                            <p style="text-align: center;"><a href="{MEMBER_AREA_LOGIN_URL}" class="button">Acessar sua Área de Membros</a></p>
                            <!-- END_IF_PRODUCT_TYPE_MEMBER_AREA -->
                        </div>
                        <!-- LOOP_PRODUCTS_END -->

                        <p>Caso tenha alguma dúvida ou precise de suporte, entre em contato conosco.</p>
                        <p>Obrigado e aproveite seus novos produtos!</p>
                    </div>
                    <div class="footer">
                        <p>Este é um e-mail automático, por favor, não responda.</p>
                        <p>&copy; ' . date("Y") . ' ' . getSystemSetting('nome_plataforma', 'Starfy') . '. Todos os direitos reservados.</p>
                    </div>
                </div>
            </body>
            </html>
        ';

        // Obtém a URL base para a URL de login da área de membros padrão
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
        $domainName = $_SERVER['HTTP_HOST'];
        $default_member_area_login_url = $protocol . $domainName . '/member_login';


        // Substitui "Starfy" pelo nome dinâmico da plataforma no template HTML (se existir)
        $email_template_html = $configs['email_template_delivery_html'] ?? $default_html_template;
        $nome_plataforma_atual = getSystemSetting('nome_plataforma', 'Starfy');
        
        // Obtém a URL da logo do checkout para substituir imagens quebradas
        $logo_checkout_url_raw_db = getSystemSetting('logo_checkout_url', '');
        $logo_url_raw_db = getSystemSetting('logo_url', 'https://i.ibb.co/2YRWNQw7/175790954831-Photoroom.png');
        $logo_checkout_final_db = empty($logo_checkout_url_raw_db) ? $logo_url_raw_db : $logo_checkout_url_raw_db;
        $logo_checkout_final_db = ltrim($logo_checkout_final_db, '/');
        if (!empty($logo_checkout_final_db) && strpos($logo_checkout_final_db, 'http') !== 0) {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
            $domainName = $_SERVER['HTTP_HOST'];
            $logo_checkout_final_db = $protocol . $domainName . '/' . $logo_checkout_final_db;
        }
        
        // Substitui imagens quebradas ou não encontradas pela logo do checkout
        if (!empty($logo_checkout_final_db)) {
            // Substitui imagens com src contendo "not found", "broken", etc.
            $email_template_html = preg_replace(
                '/<img([^>]*?)src=["\']?[^"\']*(?:not[_-]?found|broken|404|error|missing)[^"\']*["\']?([^>]*?)>/i',
                '<img$1src="' . htmlspecialchars($logo_checkout_final_db) . '" alt="' . htmlspecialchars($nome_plataforma_atual) . '" style="max-width: 200px; height: auto; margin-bottom: 20px;"$2>',
                $email_template_html
            );
            // Se não houver imagem no header, adiciona uma
            if (strpos($email_template_html, '<div class="header">') !== false) {
                $header_pos = strpos($email_template_html, '<div class="header">');
                $h1_pos = strpos($email_template_html, '<h1>', $header_pos);
                if ($h1_pos !== false) {
                    $header_content = substr($email_template_html, $header_pos, $h1_pos - $header_pos);
                    if (stripos($header_content, '<img') === false) {
                        // Não há imagem no header, adiciona
                        $email_template_html = substr_replace(
                            $email_template_html,
                            '<img src="' . htmlspecialchars($logo_checkout_final_db) . '" alt="' . htmlspecialchars($nome_plataforma_atual) . '" style="max-width: 200px; height: auto; margin-bottom: 20px;" />',
                            $h1_pos,
                            0
                        );
                    }
                }
            }
        }
        
        // Substitui ocorrências de "Starfy" no copyright do template pelo nome dinâmico
        // Procura por padrões como "© YYYY Starfy" ou "Starfy. Todos os direitos"
        // Formato 1: &copy; YYYY Starfy. Todos os direitos reservados.
        $email_template_html = preg_replace(
            '/(&copy;|©)\s*(\d{4})\s+Starfy\s*\.\s*Todos os direitos reservados\./i',
            '$1 $2 ' . htmlspecialchars($nome_plataforma_atual) . '. Todos os direitos reservados.',
            $email_template_html
        );
        // Formato 2: &copy; YYYY Starfy.
        $email_template_html = preg_replace(
            '/(&copy;|©)\s*(\d{4})\s+Starfy\s*\./i',
            '$1 $2 ' . htmlspecialchars($nome_plataforma_atual) . '.',
            $email_template_html
        );
        // Formato 3: Starfy © YYYY
        $email_template_html = preg_replace(
            '/Starfy\s*(&copy;|©)\s*(\d{4})/i',
            htmlspecialchars($nome_plataforma_atual) . ' $1 $2',
            $email_template_html
        );
        
        $response_configs = [
            'smtp_host' => $configs['smtp_host'] ?? '',
            'smtp_port' => $configs['smtp_port'] ?? '587',
            'smtp_username' => $configs['smtp_username'] ?? '',
            'smtp_encryption' => $configs['smtp_encryption'] ?? 'tls',
            'smtp_from_email' => $configs['smtp_from_email'] ?? '',
            'smtp_from_name' => $configs['smtp_from_name'] ?? $nome_plataforma_atual,
            'email_template_delivery_subject' => $configs['email_template_delivery_subject'] ?? $default_subject,
            'email_template_delivery_html' => $email_template_html,
            'member_area_login_url' => $configs['member_area_login_url'] ?? $default_member_area_login_url
        ];
        // NÃO retornar a senha por segurança
        // $response_configs['smtp_password'] = '********'; 

        ob_clean();
        echo json_encode(['success' => true, 'data' => $response_configs]);
        exit;
    }

    // NOVO: Ação para salvar configurações de e-mail e entrega
    elseif ($action === 'save_email_settings' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf_for_modifying_actions();
        error_log("ADMIN_API: Recebida ação save_email_settings.");
        $input = json_decode(file_get_contents('php://input'), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("ADMIN_API: Erro ao decodificar JSON para 'save_email_settings': " . json_last_error_msg());
            http_response_code(400);
            ob_clean();
            echo json_encode(['success' => false, 'error' => 'Dados JSON inválidos.']);
            exit;
        }
        error_log("ADMIN_API: Dados de input para save_email_settings: " . print_r($input, true));

        $pdo->beginTransaction();
        try {
            $fields_to_save = [
                'smtp_host', 'smtp_port', 'smtp_username', 'smtp_encryption', 'smtp_from_email', 'smtp_from_name',
                'email_template_delivery_subject', 'email_template_delivery_html', 'member_area_login_url'
            ];

            foreach ($fields_to_save as $chave) {
                $valor = $input[$chave] ?? '';
                $stmt = $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)");
                $stmt->execute([$chave, $valor]);
            }

            // A senha só é atualizada se for explicitamente fornecida
            if (isset($input['smtp_password']) && !empty($input['smtp_password'])) {
                $stmt = $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES ('smtp_password', ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)");
                $stmt->execute([$input['smtp_password']]);
                error_log("ADMIN_API: Senha SMTP atualizada.");
            } else {
                error_log("ADMIN_API: Senha SMTP não fornecida ou vazia, mantendo a senha existente.");
            }

            $pdo->commit();
            ob_clean();
            echo json_encode(['success' => true, 'message' => 'Configurações de e-mail e entrega salvas com sucesso!']);
        } catch (PDOException $e) {
            $pdo->rollBack();
            http_response_code(500);
            error_log("ADMIN_API: Erro ao salvar configurações de e-mail/entrega: " . $e->getMessage());
            ob_clean();
            echo json_encode(['success' => false, 'error' => 'Erro ao salvar configurações no banco de dados: ' . $e->getMessage()]);
        }
        exit;
    }
    
    // Ação para limpar template de email e usar template padrão
    elseif ($action === 'clear_email_template') {
        error_log("ADMIN_API: Recebida ação clear_email_template.");
        try {
            $stmt = $pdo->prepare("UPDATE configuracoes SET valor = '' WHERE chave = 'email_template_delivery_html'");
            $stmt->execute();
            
            if ($stmt->rowCount() > 0) {
                ob_clean();
                echo json_encode(['success' => true, 'message' => 'Template limpo com sucesso! O sistema usará o template padrão da plataforma.']);
            } else {
                // Se não encontrou registro, cria um vazio
                $stmt = $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES ('email_template_delivery_html', '') ON DUPLICATE KEY UPDATE valor = ''");
                $stmt->execute();
                ob_clean();
                echo json_encode(['success' => true, 'message' => 'Template limpo com sucesso! O sistema usará o template padrão da plataforma.']);
            }
        } catch (PDOException $e) {
            error_log("ADMIN_API: Erro ao limpar template: " . $e->getMessage());
            http_response_code(500);
            ob_clean();
            echo json_encode(['success' => false, 'error' => 'Erro ao limpar template: ' . $e->getMessage()]);
        }
        exit;
    }


    // NOVO: Ação para testar conexão SMTP
    elseif ($action === 'test_smtp_connection' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        error_log("ADMIN_API: Recebida ação test_smtp_connection.");
        $input = json_decode(file_get_contents('php://input'), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("ADMIN_API: Erro ao decodificar JSON para 'test_smtp_connection': " . json_last_error_msg());
            http_response_code(400);
            // Limpa o buffer antes de enviar o JSON
            ob_clean();
            echo json_encode(['success' => false, 'error' => 'Dados JSON inválidos.']);
            exit;
        }
        error_log("ADMIN_API: Dados de input para test_smtp_connection: " . print_r($input, true));

        $smtp_config = getSmtpConfigFromRequest($pdo, $input);
        error_log("ADMIN_API: Configuração SMTP após processamento para test_smtp_connection (comprimento da senha: " . strlen($smtp_config['password']) . "): " . print_r($smtp_config, true));

        $mail = new PHPMailer(true);
        try {
            error_log("ADMIN_API: Instanciando PHPMailer para teste de conexão.");
            // Configurar SMTP
            $mail->isSMTP();
            $mail->SMTPDebug = SMTP::DEBUG_SERVER; // Ativa saída de depuração verbosa (para error_log do PHP)
            $mail->Debugoutput = 'error_log'; // EXPLICITAMENTE direciona o debug para o log de erros
            $mail->Host = $smtp_config['host'];
            $mail->Port = $smtp_config['port'];
            $mail->SMTPAuth = true;
            $mail->Username = $smtp_config['username'];
            $mail->Password = $smtp_config['password'];
            
            // SMTPOptions para aceitar certificados autoassinados (cuidado em produção)
            $mail->SMTPOptions = array(
                'ssl' => array(
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                )
            );

            if ($smtp_config['encryption'] === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($smtp_config['encryption'] === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } else {
                $mail->SMTPSecure = false;
                $mail->SMTPAutoTLS = false;
            }

            error_log("ADMIN_API: Tentando conectar ao SMTP: Host=" . $mail->Host . ", Port=" . $mail->Port . ", User=" . $mail->Username);
            // Apenas tenta conectar, sem enviar e-mail
            $mail->smtpConnect();
            $mail->smtpClose(); // Fecha a conexão imediatamente após o teste

            error_log("ADMIN_API: Teste de conexão SMTP bem-sucedido. Preparando resposta JSON.");
            // Limpa o buffer antes de enviar o JSON
            ob_clean();
            echo json_encode(['success' => true, 'message' => 'Conexão SMTP testada com sucesso!']);
        } catch (Exception $e) {
            http_response_code(500);
            error_log("ADMIN_API: Teste de conexão SMTP falhou: " . $e->getMessage() . " File: " . $e->getFile() . " Line: " . $e->getLine());
            error_log("ADMIN_API: Preparando resposta JSON de erro para teste de conexão.");
            // Limpa o buffer antes de enviar o JSON
            ob_clean();
            echo json_encode(['success' => false, 'error' => 'Falha na conexão SMTP: ' . $e->getMessage()]);
        }
        exit;
    }

    // NOVO: Ação para enviar e-mail de teste
    elseif ($action === 'send_test_email' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        error_log("ADMIN_API: Recebida ação send_test_email.");
        $input = json_decode(file_get_contents('php://input'), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("ADMIN_API: Erro ao decodificar JSON para 'send_test_email': " . json_last_error_msg());
            http_response_code(400);
            // Limpa o buffer antes de enviar o JSON
            ob_clean();
            echo json_encode(['success' => false, 'error' => 'Dados JSON inválidos.']);
            exit;
        }
        error_log("ADMIN_API: Dados de input para send_test_email: " . print_r($input, true));

        $smtp_config = getSmtpConfigFromRequest($pdo, $input);
        error_log("ADMIN_API: Configuração SMTP após processamento para send_test_email (comprimento da senha: " . strlen($smtp_config['password']) . "): " . print_r($smtp_config, true));
        $test_email = $input['test_email'] ?? '';

        if (empty($test_email) || !filter_var($test_email, FILTER_VALIDATE_EMAIL)) {
            http_response_code(400);
            error_log("ADMIN_API: Erro (send_test_email): E-mail de teste inválido ou ausente.");
            // Limpa o buffer antes de enviar o JSON
            ob_clean();
            echo json_encode(['success' => false, 'error' => 'E-mail de teste inválido ou ausente.']);
            exit;
        }

        $mail = new PHPMailer(true);
        try {
            error_log("ADMIN_API: Instanciando PHPMailer para envio de e-mail de teste.");
            // Configurar SMTP
            $mail->isSMTP();
            $mail->SMTPDebug = SMTP::DEBUG_SERVER; // Ativa saída de depuração verbosa (para error_log do PHP)
            $mail->Debugoutput = 'error_log'; // EXPLICITAMENTE direciona o debug para o log de erros
            $mail->Host = $smtp_config['host'];
            $mail->Port = $smtp_config['port'];
            $mail->SMTPAuth = true;
            $mail->Username = $smtp_config['username'];
            $mail->Password = $smtp_config['password'];
            
            // SMTPOptions para aceitar certificados autoassinados (cuidado em produção)
            $mail->SMTPOptions = array(
                'ssl' => array(
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                )
            );

            if ($smtp_config['encryption'] === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($smtp_config['encryption'] === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } else {
                $mail->SMTPSecure = false;
                $mail->SMTPAutoTLS = false;
            }

            // Configurar e-mail
            $mail->CharSet = 'UTF-8';
            // CORREÇÃO: Usar o username como 'From' address para evitar "Sender address rejected"
            $mail->setFrom($smtp_config['username'], $smtp_config['from_name']);
            $mail->addAddress($test_email);
            $mail->Subject = 'Email de Teste SMTP';
            $mail->isHTML(true);
            $mail->Body = 'Olá! Este é um e-mail de teste enviado da sua configuração SMTP. Se você recebeu esta mensagem, suas configurações estão funcionando corretamente.';
            $mail->AltBody = 'Olá! Este é um e-mail de teste enviado da sua configuração SMTP. Se você recebeu esta mensagem, suas configurações estão funcionando corretamente.';

            error_log("ADMIN_API: Tentando enviar e-mail de teste para " . $test_email . " usando SMTP: Host=" . $mail->Host . ", Port=" . $mail->Port);
            $mail->send();
            error_log("ADMIN_API: E-mail de teste enviado com sucesso para " . $test_email . ". Preparando resposta JSON.");
            // Limpa o buffer antes de enviar o JSON
            ob_clean();
            echo json_encode(['success' => true, 'message' => 'E-mail de teste enviado com sucesso para ' . $test_email . '!']);
        } catch (Exception $e) {
            http_response_code(500);
            error_log("ADMIN_API: Falha ao enviar e-mail de teste para " . $test_email . ": " . $e->getMessage() . " File: " . $e->getFile() . " Line: " . $e->getLine());
            error_log("ADMIN_API: Preparando resposta JSON de erro para envio de e-mail de teste.");
            // Limpa o buffer antes de enviar o JSON
            ob_clean();
            echo json_encode(['success' => false, 'error' => 'Falha ao enviar e-mail de teste: ' . $e->getMessage()]);
        }
        exit;
    }

    // ========== CONFIGURAÇÕES DO SISTEMA ==========
    elseif ($action === 'get_system_settings') {
        require_once __DIR__ . '/../config/config.php';
        if (!function_exists('getSystemSetting')) {
            ob_clean();
            echo json_encode(['success' => false, 'error' => 'Função getSystemSetting não encontrada']);
            exit;
        }
        
        // Busca valores brutos do banco
        $logo_url_raw = getSystemSetting('logo_url', 'https://i.ibb.co/2YRWNQw7/175709548831-Photoroom.png');
        $login_image_url_raw = getSystemSetting('login_image_url', '');
        $logo_checkout_url_raw = getSystemSetting('logo_checkout_url', '');
        $favicon_url_raw = getSystemSetting('favicon_url', '');
        
        // Normaliza URLs para retornar (igual ao load_settings.php)
        // Remove barra inicial se houver
        $logo_url_normalized = ltrim($logo_url_raw, '/');
        if (empty($logo_url_normalized)) {
            $logo_url_normalized = 'https://i.ibb.co/2YRWNQw7/175790954831-Photoroom.png';
        } elseif (strpos($logo_url_normalized, 'http') === 0) {
            // URL completa, mantém como está
        } elseif (strpos($logo_url_normalized, 'uploads/') === 0) {
            // Adiciona barra inicial (igual às imagens dos módulos)
            $logo_url_normalized = '/' . $logo_url_normalized;
        } else {
            $logo_url_normalized = '/' . $logo_url_normalized;
        }
        
        $login_image_url_normalized = ltrim($login_image_url_raw, '/');
        if (!empty($login_image_url_normalized) && strpos($login_image_url_normalized, 'http') !== 0) {
            if (strpos($login_image_url_normalized, 'uploads/') === 0) {
                $login_image_url_normalized = '/' . $login_image_url_normalized;
            } else {
                $login_image_url_normalized = '/' . $login_image_url_normalized;
            }
        }
        
        $logo_checkout_url_normalized = ltrim($logo_checkout_url_raw, '/');
        if (empty($logo_checkout_url_normalized)) {
            $logo_checkout_url_normalized = $logo_url_normalized;
        } elseif (strpos($logo_checkout_url_normalized, 'http') === 0) {
            // URL completa, mantém como está
        } elseif (strpos($logo_checkout_url_normalized, 'uploads/') === 0) {
            $logo_checkout_url_normalized = '/' . $logo_checkout_url_normalized;
        } else {
            $logo_checkout_url_normalized = '/' . $logo_checkout_url_normalized;
        }
        
        $favicon_url_normalized = ltrim($favicon_url_raw, '/');
        if (!empty($favicon_url_normalized) && strpos($favicon_url_normalized, 'http') !== 0) {
            if (strpos($favicon_url_normalized, 'uploads/') === 0) {
                $favicon_url_normalized = '/' . $favicon_url_normalized;
            } else {
                $favicon_url_normalized = '/' . $favicon_url_normalized;
            }
        }
        
        $settings = [
            'cor_primaria' => getSystemSetting('cor_primaria', '#32e768'),
            'logo_url' => $logo_url_normalized,
            'login_image_url' => $login_image_url_normalized,
            'nome_plataforma' => getSystemSetting('nome_plataforma', 'Starfy'),
            'logo_checkout_url' => $logo_checkout_url_normalized,
            'favicon_url' => $favicon_url_normalized,
            'plugins_marketplace_url' => $plugins_marketplace_url_raw
        ];
        
        ob_clean();
        echo json_encode(['success' => true, 'data' => $settings]);
        exit;
    }
    elseif ($action === 'save_system_settings' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf_for_modifying_actions();
        require_once __DIR__ . '/../config/config.php';
        
        // Verifica se PDO está disponível
        if (!isset($pdo) || !$pdo) {
            ob_clean();
            error_log("ADMIN_API: PDO não está disponível!");
            echo json_encode(['success' => false, 'error' => 'Erro de conexão com banco de dados']);
            exit;
        }
        
        if (!function_exists('setSystemSetting')) {
            ob_clean();
            error_log("ADMIN_API: Função setSystemSetting não encontrada!");
            echo json_encode(['success' => false, 'error' => 'Função setSystemSetting não encontrada']);
            exit;
        }
        
        $raw_input = file_get_contents('php://input');
        error_log("ADMIN_API save_system_settings: Raw input: " . $raw_input);
        
        $data = json_decode($raw_input, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            ob_clean();
            error_log("ADMIN_API: Erro ao decodificar JSON: " . json_last_error_msg());
            echo json_encode(['success' => false, 'error' => 'Erro ao processar dados: ' . json_last_error_msg()]);
            exit;
        }
        
        // Debug: log dos dados recebidos
        error_log("ADMIN_API save_system_settings: Dados recebidos: " . json_encode($data));
        
        if (!isset($data['cor_primaria']) && !isset($data['logo_url']) && !isset($data['login_image_url']) && !isset($data['nome_plataforma']) && !isset($data['logo_checkout_url'])) {
            ob_clean();
            echo json_encode(['success' => false, 'error' => 'Nenhuma configuração fornecida']);
            exit;
        }
        
        $updated = [];
        if (isset($data['cor_primaria'])) {
            $cor = trim($data['cor_primaria']);
            // Valida formato hexadecimal (#RRGGBB ou RRGGBB)
            if (preg_match('/^#?[0-9A-Fa-f]{6}$/', $cor)) {
                // Garante que tem o #
                if (strpos($cor, '#') !== 0) {
                    $cor = '#' . $cor;
                }
                if (setSystemSetting('cor_primaria', $cor)) {
                    $updated[] = 'cor_primaria';
                } else {
                    error_log("ADMIN_API: Erro ao salvar cor_primaria: " . $cor);
                }
            } else {
                error_log("ADMIN_API: Formato de cor inválido: " . $cor);
                ob_clean();
                echo json_encode(['success' => false, 'error' => 'Formato de cor inválido. Use o formato hexadecimal (#RRGGBB)']);
                exit;
            }
        }
        if (isset($data['logo_url'])) {
            $logo_url_val = filter_var($data['logo_url'], FILTER_SANITIZE_URL);
            if (setSystemSetting('logo_url', $logo_url_val)) {
                $updated[] = 'logo_url';
            } else {
                error_log("ADMIN_API: Erro ao salvar logo_url");
            }
        }
        if (isset($data['login_image_url'])) {
            $login_img_val = filter_var($data['login_image_url'], FILTER_SANITIZE_URL);
            if (setSystemSetting('login_image_url', $login_img_val)) {
                $updated[] = 'login_image_url';
            } else {
                error_log("ADMIN_API: Erro ao salvar login_image_url");
            }
        }
        if (isset($data['nome_plataforma'])) {
            $nome = trim($data['nome_plataforma']);
            if (!empty($nome)) {
                $nome_sanitizado = htmlspecialchars($nome, ENT_QUOTES, 'UTF-8');
                error_log("ADMIN_API: Tentando salvar nome_plataforma: " . $nome_sanitizado);
                if (setSystemSetting('nome_plataforma', $nome_sanitizado)) {
                    $updated[] = 'nome_plataforma';
                    error_log("ADMIN_API: nome_plataforma salvo com sucesso");
                } else {
                    error_log("ADMIN_API: Erro ao salvar nome_plataforma: " . $nome_sanitizado);
                }
            } else {
                error_log("ADMIN_API: nome_plataforma está vazio após trim");
            }
        } else {
            error_log("ADMIN_API: nome_plataforma não está definido no data");
        }
        if (isset($data['plugins_marketplace_url'])) {
            $url = trim($data['plugins_marketplace_url']);
            // Permite URL vazia (para usar arquivo local)
            $url_sanitizada = filter_var($url, FILTER_SANITIZE_URL);
            error_log("ADMIN_API: Tentando salvar plugins_marketplace_url: " . $url_sanitizada);
            if (setSystemSetting('plugins_marketplace_url', $url_sanitizada)) {
                $updated[] = 'plugins_marketplace_url';
                error_log("ADMIN_API: plugins_marketplace_url salvo com sucesso");
            } else {
                error_log("ADMIN_API: Erro ao salvar plugins_marketplace_url: " . $url_sanitizada);
            }
        }
        if (isset($data['logo_checkout_url'])) {
            $logo_checkout_val = filter_var($data['logo_checkout_url'], FILTER_SANITIZE_URL);
            if (setSystemSetting('logo_checkout_url', $logo_checkout_val)) {
                $updated[] = 'logo_checkout_url';
            } else {
                error_log("ADMIN_API: Erro ao salvar logo_checkout_url");
            }
        }
        
        ob_clean();
        echo json_encode(['success' => true, 'message' => 'Configurações salvas com sucesso', 'updated' => $updated]);
        exit;
    }
    elseif ($action === 'upload_logo' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf_for_modifying_actions();
        require_once __DIR__ . '/../config/config.php';
        require_once __DIR__ . '/../helpers/security_helper.php';
        
        $upload_dir = 'uploads/config/';
        
        if (!isset($_FILES['logo'])) {
            ob_clean();
            echo json_encode(['success' => false, 'error' => 'Nenhum arquivo enviado']);
            exit;
        }
        
        // Validação especial para logo (permite SVG também)
        $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/svg+xml'];
        $allowed_extensions = ['jpg', 'jpeg', 'png', 'webp', 'svg'];
        $max_size = 2 * 1024 * 1024; // 2MB
        
        $upload_result = validate_uploaded_file($_FILES['logo'], $allowed_types, $allowed_extensions, $max_size, $upload_dir, 'logo');
        
        if ($upload_result['success']) {
            // Deleta logo antiga se existir
            $old_logo = getSystemSetting('logo_url', '');
            if (!empty($old_logo) && strpos($old_logo, 'http') !== 0) {
                $old_path = ltrim($old_logo, '/');
                $old_path_absoluto = __DIR__ . '/../' . $old_path;
                if (file_exists($old_path_absoluto)) {
                    @unlink($old_path_absoluto);
                }
            }
            
            $logo_url = $upload_result['file_path'];
            if (setSystemSetting('logo_url', $logo_url)) {
                ob_clean();
                echo json_encode(['success' => true, 'message' => 'Logo enviada com sucesso', 'url' => '/' . $logo_url]);
            } else {
                ob_clean();
                echo json_encode(['success' => false, 'error' => 'Erro ao salvar configuração no banco de dados']);
            }
        } else {
            ob_clean();
            echo json_encode(['success' => false, 'error' => $upload_result['error']]);
        }
        exit;
    }
    elseif ($action === 'upload_login_image' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf_for_modifying_actions();
        require_once __DIR__ . '/../config/config.php';
        require_once __DIR__ . '/../helpers/security_helper.php';
        
        $upload_dir = 'uploads/config/';
        
        if (!isset($_FILES['login_image'])) {
            ob_clean();
            echo json_encode(['success' => false, 'error' => 'Nenhum arquivo enviado']);
            exit;
        }
        
        // Apenas JPEG ou PNG para imagens de login
        $upload_result = validate_image_upload($_FILES['login_image'], $upload_dir, 'login_bg', 5, true);
        
        if ($upload_result['success']) {
            // Deleta imagem antiga se existir
            $old_image = getSystemSetting('login_image_url', '');
            if (!empty($old_image) && strpos($old_image, 'http') !== 0) {
                $old_path = ltrim($old_image, '/');
                $old_path_absoluto = __DIR__ . '/../' . $old_path;
                if (file_exists($old_path_absoluto)) {
                    @unlink($old_path_absoluto);
                }
            }
            
            $image_url = $upload_result['file_path'];
            if (setSystemSetting('login_image_url', $image_url)) {
                ob_clean();
                echo json_encode(['success' => true, 'message' => 'Imagem de login enviada com sucesso', 'url' => '/' . $image_url]);
            } else {
                ob_clean();
                echo json_encode(['success' => false, 'error' => 'Erro ao salvar configuração no banco de dados']);
            }
        } else {
            ob_clean();
            echo json_encode(['success' => false, 'error' => $upload_result['error']]);
        }
        exit;
    }
    elseif ($action === 'upload_logo_checkout' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf_for_modifying_actions();
        require_once __DIR__ . '/../config/config.php';
        require_once __DIR__ . '/../helpers/security_helper.php';
        
        $upload_dir = 'uploads/config/';
        
        if (!isset($_FILES['logo_checkout'])) {
            ob_clean();
            echo json_encode(['success' => false, 'error' => 'Nenhum arquivo enviado']);
            exit;
        }
        
        // Validação especial para logo checkout (permite SVG também)
        $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/svg+xml'];
        $allowed_extensions = ['jpg', 'jpeg', 'png', 'webp', 'svg'];
        $max_size = 2 * 1024 * 1024; // 2MB
        
        $upload_result = validate_uploaded_file($_FILES['logo_checkout'], $allowed_types, $allowed_extensions, $max_size, $upload_dir, 'logo_checkout');
        
        if ($upload_result['success']) {
            // Deleta logo antiga se existir
            $old_logo = getSystemSetting('logo_checkout_url', '');
            if (!empty($old_logo) && strpos($old_logo, 'http') !== 0) {
                $old_path = ltrim($old_logo, '/');
                $old_path_absoluto = __DIR__ . '/../' . $old_path;
                if (file_exists($old_path_absoluto)) {
                    @unlink($old_path_absoluto);
                }
            }
            
            $logo_url = $upload_result['file_path'];
            if (setSystemSetting('logo_checkout_url', $logo_url)) {
                ob_clean();
                echo json_encode(['success' => true, 'message' => 'Logo do checkout enviada com sucesso', 'url' => '/' . $logo_url]);
            } else {
                ob_clean();
                echo json_encode(['success' => false, 'error' => 'Erro ao salvar configuração no banco de dados']);
            }
        } else {
            ob_clean();
            echo json_encode(['success' => false, 'error' => $upload_result['error']]);
        }
        exit;
    }
    elseif ($action === 'upload_favicon' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf_for_modifying_actions();
        require_once __DIR__ . '/../config/config.php';
        require_once __DIR__ . '/../helpers/security_helper.php';
        
        $upload_dir = 'uploads/config/';
        
        if (!isset($_FILES['favicon'])) {
            ob_clean();
            echo json_encode(['success' => false, 'error' => 'Nenhum arquivo enviado']);
            exit;
        }
        
        // Validação especial para favicon (permite ICO, PNG, SVG)
        $allowed_types = ['image/x-icon', 'image/vnd.microsoft.icon', 'image/png', 'image/svg+xml'];
        $allowed_extensions = ['ico', 'png', 'svg'];
        $max_size = 2 * 1024 * 1024; // 2MB
        
        $upload_result = validate_uploaded_file($_FILES['favicon'], $allowed_types, $allowed_extensions, $max_size, $upload_dir, 'favicon');
        
        if ($upload_result['success']) {
            // Deleta favicon antigo se existir
            $old_favicon = getSystemSetting('favicon_url', '');
            if (!empty($old_favicon) && strpos($old_favicon, 'http') !== 0) {
                $old_path = ltrim($old_favicon, '/');
                $old_path_absoluto = __DIR__ . '/../' . $old_path;
                if (file_exists($old_path_absoluto)) {
                    @unlink($old_path_absoluto);
                }
            }
            
            $favicon_url = $upload_result['file_path'];
            if (setSystemSetting('favicon_url', $favicon_url)) {
                ob_clean();
                echo json_encode(['success' => true, 'message' => 'Favicon enviado com sucesso', 'url' => '/' . $favicon_url]);
            } else {
                ob_clean();
                echo json_encode(['success' => false, 'error' => 'Erro ao salvar configuração no banco de dados']);
            }
        } else {
            ob_clean();
            echo json_encode(['success' => false, 'error' => $upload_result['error']]);
        }
        exit;
    }

    // ========== BANNER DO DASHBOARD ==========
    elseif ($action === 'get_dashboard_banners') {
        require_once __DIR__ . '/../config/config.php';
        
        $config_json = getSystemSetting('dashboard_banner_config', '{}');
        
        // Se não encontrou em configuracoes_sistema, tentar em configuracoes
        if ($config_json === '{}' || empty($config_json)) {
            try {
                $stmt = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = ?");
                $stmt->execute(['dashboard_banner_config']);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($result && !empty($result['valor'])) {
                    $config_json = $result['valor'];
                    error_log("ADMIN_API get_dashboard_banners: Configuração encontrada na tabela configuracoes");
                }
            } catch (PDOException $e) {
                error_log("ADMIN_API get_dashboard_banners: Erro ao buscar na tabela configuracoes: " . $e->getMessage());
            }
        }
        
        $config = json_decode($config_json, true);
        
        if (!$config || json_last_error() !== JSON_ERROR_NONE) {
            error_log("ADMIN_API get_dashboard_banners: JSON inválido ou vazio, usando padrão");
            $config = [
                'type' => 'single',
                'banners' => [],
                'enabled' => false,
                'autoplay' => true,
                'interval' => 5 // Em segundos
            ];
        }
        
        // Normalizar URLs dos banners
        if (isset($config['banners']) && is_array($config['banners'])) {
            $config['banners'] = array_map(function($banner) {
                if (strpos($banner, 'http') === 0) {
                    return $banner;
                }
                return '/' . ltrim($banner, '/');
            }, $config['banners']);
        }
        
        ob_clean();
        echo json_encode(['success' => true, 'data' => $config]);
        exit;
    }
    elseif ($action === 'save_dashboard_banners' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf_for_modifying_actions();
        require_once __DIR__ . '/../config/config.php';
        
        $raw_input = file_get_contents('php://input');
        error_log("ADMIN_API save_dashboard_banners: Raw input: " . $raw_input);
        
        $input = json_decode($raw_input, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("ADMIN_API save_dashboard_banners: Erro ao decodificar JSON: " . json_last_error_msg());
            ob_clean();
            echo json_encode(['success' => false, 'error' => 'Dados JSON inválidos: ' . json_last_error_msg()]);
            exit;
        }
        
        error_log("ADMIN_API save_dashboard_banners: Dados recebidos: " . print_r($input, true));
        
        $config = [
            'type' => $input['type'] ?? 'single',
            'banners' => $input['banners'] ?? [],
            'enabled' => isset($input['enabled']) ? (bool)$input['enabled'] : false
        ];
        
        if ($config['type'] === 'carousel') {
            $config['autoplay'] = isset($input['autoplay']) ? (bool)$input['autoplay'] : true;
            // Interval está em segundos (padrão 5 segundos)
            $config['interval'] = isset($input['interval']) ? (int)$input['interval'] : 5;
        }
        
        // Remover barras iniciais dos caminhos dos banners para salvar no banco
        if (isset($config['banners']) && is_array($config['banners'])) {
            $config['banners'] = array_map(function($banner) {
                return ltrim($banner, '/');
            }, $config['banners']);
        }
        
        $config_json = json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        error_log("ADMIN_API save_dashboard_banners: JSON a ser salvo: " . $config_json);
        
        // Salvar diretamente usando ON DUPLICATE KEY UPDATE (mais confiável)
        $saved = false;
        try {
            // Primeiro tentar na tabela configuracoes_sistema
            $stmt = $pdo->prepare("INSERT INTO configuracoes_sistema (chave, valor, tipo) VALUES (?, ?, 'text') ON DUPLICATE KEY UPDATE valor = ?, updated_at = CURRENT_TIMESTAMP");
            $result = $stmt->execute(['dashboard_banner_config', $config_json, $config_json]);
            $rows_affected = $stmt->rowCount();
            error_log("ADMIN_API save_dashboard_banners: Execute retornou: " . ($result ? 'true' : 'false') . ", rows affected: $rows_affected");
            
            // Verificar se realmente foi salvo consultando o banco
            $stmt_check = $pdo->prepare("SELECT valor FROM configuracoes_sistema WHERE chave = ?");
            $stmt_check->execute(['dashboard_banner_config']);
            $check_result = $stmt_check->fetch(PDO::FETCH_ASSOC);
            
            if ($check_result && $check_result['valor'] === $config_json) {
                $saved = true;
                error_log("ADMIN_API save_dashboard_banners: Salvo na tabela configuracoes_sistema com sucesso! Verificado no banco.");
            } else {
                error_log("ADMIN_API save_dashboard_banners: Execute OK mas valor não confere no banco.");
            }
        } catch (PDOException $e) {
            error_log("ADMIN_API save_dashboard_banners: Erro ao salvar em configuracoes_sistema: " . $e->getMessage());
            // Se falhar, tentar na tabela configuracoes
            try {
                $stmt = $pdo->prepare("INSERT INTO configuracoes (chave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = ?");
                $result = $stmt->execute(['dashboard_banner_config', $config_json, $config_json]);
                $rows_affected = $stmt->rowCount();
                error_log("ADMIN_API save_dashboard_banners: Execute em configuracoes retornou: " . ($result ? 'true' : 'false') . ", rows affected: $rows_affected");
                
                // Verificar se realmente foi salvo
                $stmt_check = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = ?");
                $stmt_check->execute(['dashboard_banner_config']);
                $check_result = $stmt_check->fetch(PDO::FETCH_ASSOC);
                
                if ($check_result && $check_result['valor'] === $config_json) {
                    $saved = true;
                    error_log("ADMIN_API save_dashboard_banners: Salvo na tabela configuracoes com sucesso! Verificado no banco.");
                }
            } catch (PDOException $e2) {
                error_log("ADMIN_API save_dashboard_banners: Erro ao salvar em configuracoes: " . $e2->getMessage());
            }
        }
        
        if ($saved) {
            // Verificar se realmente foi salvo
            $verification = getSystemSetting('dashboard_banner_config', '');
            if (empty($verification)) {
                // Tentar buscar da tabela configuracoes também
                try {
                    $stmt = $pdo->prepare("SELECT valor FROM configuracoes WHERE chave = ?");
                    $stmt->execute(['dashboard_banner_config']);
                    $result = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($result) {
                        $verification = $result['valor'];
                    }
                } catch (PDOException $e) {
                    error_log("ADMIN_API save_dashboard_banners: Erro ao verificar na tabela configuracoes: " . $e->getMessage());
                }
            }
            error_log("ADMIN_API save_dashboard_banners: Verificação - valor salvo: " . $verification);
            
            ob_clean();
            echo json_encode(['success' => true, 'message' => 'Configurações do banner salvas com sucesso!']);
        } else {
            error_log("ADMIN_API save_dashboard_banners: Falha ao salvar em todas as tentativas");
            ob_clean();
            echo json_encode(['success' => false, 'error' => 'Erro ao salvar configurações no banco de dados. Verifique os logs.']);
        }
        exit;
    }
    elseif ($action === 'upload_dashboard_banner' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf_for_modifying_actions();
        require_once __DIR__ . '/../config/config.php';
        require_once __DIR__ . '/../helpers/security_helper.php';
        
        $upload_dir = 'uploads/banners/';
        
        if (!isset($_FILES['banner'])) {
            ob_clean();
            echo json_encode(['success' => false, 'error' => 'Nenhum arquivo enviado']);
            exit;
        }
        
        // Apenas JPEG ou PNG para banners do dashboard
        $upload_result = validate_image_upload($_FILES['banner'], $upload_dir, 'banner_dashboard', 2, true);
        
        if ($upload_result['success']) {
            ob_clean();
            echo json_encode(['success' => true, 'message' => 'Banner enviado com sucesso', 'url' => $upload_result['file_path']]);
        } else {
            ob_clean();
            echo json_encode(['success' => false, 'error' => $upload_result['error']]);
        }
        exit;
    }
    elseif ($action === 'delete_dashboard_banner' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf_for_modifying_actions();
        require_once __DIR__ . '/../config/config.php';
        
        $input = json_decode(file_get_contents('php://input'), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            ob_clean();
            echo json_encode(['success' => false, 'error' => 'Dados JSON inválidos.']);
            exit;
        }
        
        $banner_url = $input['banner_url'] ?? '';
        if (empty($banner_url)) {
            ob_clean();
            echo json_encode(['success' => false, 'error' => 'URL do banner não fornecida.']);
            exit;
        }
        
        // Remover barra inicial se houver
        $banner_path = ltrim($banner_url, '/');
        $banner_path_absoluto = __DIR__ . '/../' . $banner_path;
        
        // Deletar arquivo físico
        if (file_exists($banner_path_absoluto)) {
            @unlink($banner_path_absoluto);
        }
        
        // Remover do banco de dados
        $config_json = getSystemSetting('dashboard_banner_config', '{}');
        $config = json_decode($config_json, true);
        
        if ($config && isset($config['banners']) && is_array($config['banners'])) {
            $config['banners'] = array_filter($config['banners'], function($banner) use ($banner_url) {
                $banner_normalized = ltrim($banner, '/');
                $banner_url_normalized = ltrim($banner_url, '/');
                return $banner_normalized !== $banner_url_normalized;
            });
            $config['banners'] = array_values($config['banners']); // Reindexar array
            
            $config_json = json_encode($config);
            setSystemSetting('dashboard_banner_config', $config_json);
        }
        
        ob_clean();
        echo json_encode(['success' => true, 'message' => 'Banner removido com sucesso']);
        exit;
    }

    // ========== BROADCAST EMAIL MARKETING ==========
    elseif ($action === 'get_recipient_count') {
        require_once __DIR__ . '/../config/config.php';
        
        $type = $_GET['type'] ?? 'infoprodutor';
        
        try {
            $where_conditions = [];
            
            switch ($type) {
                case 'infoprodutor':
                    $where_conditions[] = "tipo = 'infoprodutor'";
                    break;
                case 'client':
                    $where_conditions[] = "tipo = 'usuario'";
                    break;
                case 'both':
                    $where_conditions[] = "tipo IN ('infoprodutor', 'usuario')";
                    break;
                default:
                    $where_conditions[] = "tipo = 'infoprodutor'";
            }
            
            $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
            
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM usuarios {$where_clause} AND usuario IS NOT NULL AND usuario != ''");
            $stmt->execute();
            $count = $stmt->fetchColumn();
            
            ob_clean();
            echo json_encode(['success' => true, 'count' => (int)$count]);
            exit;
        } catch (PDOException $e) {
            error_log("ADMIN_API: Erro ao contar destinatários: " . $e->getMessage());
            ob_clean();
            echo json_encode(['success' => false, 'error' => 'Erro ao contar destinatários']);
            exit;
        }
    }

    elseif ($action === 'create_broadcast_queue' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        require_once __DIR__ . '/../config/config.php';
        
        $input = json_decode(file_get_contents('php://input'), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            ob_clean();
            echo json_encode(['success' => false, 'error' => 'Dados JSON inválidos.']);
            exit;
        }
        
        $recipient_type = $input['recipient_type'] ?? 'infoprodutor';
        $subject = trim($input['subject'] ?? '');
        $body = $input['body'] ?? '';
        
        if (empty($subject) || empty($body)) {
            ob_clean();
            echo json_encode(['success' => false, 'error' => 'Assunto e conteúdo são obrigatórios.']);
            exit;
        }
        
        try {
            // Buscar logo do checkout para normalizar URLs no HTML
            $logo_checkout_url_raw = getSystemSetting('logo_checkout_url', '');
            if (empty($logo_checkout_url_raw)) {
                $logo_checkout_url_raw = getSystemSetting('logo_url', '');
            }
            
            // Construir URL absoluta da logo
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $logo_checkout_final = '';
            
            if (!empty($logo_checkout_url_raw)) {
                if (strpos($logo_checkout_url_raw, 'http://') === 0 || strpos($logo_checkout_url_raw, 'https://') === 0) {
                    $logo_checkout_final = $logo_checkout_url_raw;
                } else {
                    $logo_path = ltrim($logo_checkout_url_raw, '/');
                    $logo_checkout_final = $protocol . $host . '/' . $logo_path;
                }
            }
            
            // Normalizar URLs de imagens no HTML do body
            // Substituir URLs relativas da logo por URL absoluta
            if (!empty($logo_checkout_final) && !empty($logo_checkout_url_raw)) {
                // Substituir src relativos da logo por URL absoluta
                $body = preg_replace_callback(
                    '/<img([^>]*?)src=["\']([^"\']*?)["\']([^>]*?)>/i',
                    function($matches) use ($logo_checkout_final, $logo_checkout_url_raw, $protocol, $host) {
                        $before_src = $matches[1];
                        $src_url = $matches[2];
                        $after_src = $matches[3];
                        
                        // Se a URL contém o caminho da logo (relativo ou absoluto)
                        if (strpos($src_url, $logo_checkout_url_raw) !== false || 
                            strpos($src_url, basename($logo_checkout_url_raw)) !== false) {
                            // Usar URL absoluta
                            return '<img' . $before_src . 'src="' . htmlspecialchars($logo_checkout_final, ENT_QUOTES) . '"' . $after_src . '>';
                        }
                        
                        // Se é URL relativa (não começa com http), converter para absoluta
                        if (!preg_match('/^https?:\/\//i', $src_url)) {
                            $src_path = ltrim($src_url, '/');
                            $src_absolute = $protocol . $host . '/' . $src_path;
                            return '<img' . $before_src . 'src="' . htmlspecialchars($src_absolute, ENT_QUOTES) . '"' . $after_src . '>';
                        }
                        
                        // Já é URL absoluta, manter como está
                        return $matches[0];
                    },
                    $body
                );
            }
            
            // Buscar destinatários
            $where_conditions = [];
            
            switch ($recipient_type) {
                case 'infoprodutor':
                    $where_conditions[] = "tipo = 'infoprodutor'";
                    break;
                case 'client':
                    $where_conditions[] = "tipo = 'usuario'";
                    break;
                case 'both':
                    $where_conditions[] = "tipo IN ('infoprodutor', 'usuario')";
                    break;
                default:
                    $where_conditions[] = "tipo = 'infoprodutor'";
            }
            
            $where_clause = 'WHERE ' . implode(' AND ', $where_conditions) . " AND usuario IS NOT NULL AND usuario != ''";
            
            $stmt = $pdo->prepare("SELECT usuario, nome FROM usuarios {$where_clause}");
            $stmt->execute();
            $recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($recipients)) {
                ob_clean();
                echo json_encode(['success' => false, 'error' => 'Nenhum destinatário encontrado.']);
                exit;
            }
            
            // Inserir na fila
            $stmt_insert = $pdo->prepare("INSERT INTO email_queue (recipient_email, recipient_name, subject, body, status) VALUES (?, ?, ?, ?, 'pending')");
            
            $pdo->beginTransaction();
            $inserted = 0;
            
            foreach ($recipients as $recipient) {
                $email = trim($recipient['usuario']);
                if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $stmt_insert->execute([
                        $email,
                        $recipient['nome'] ?? $email,
                        $subject,
                        $body
                    ]);
                    $inserted++;
                }
            }
            
            $pdo->commit();
            
            ob_clean();
            echo json_encode(['success' => true, 'total' => $inserted, 'message' => "{$inserted} email(s) adicionado(s) à fila com sucesso."]);
            exit;
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("ADMIN_API: Erro ao criar fila de broadcast: " . $e->getMessage());
            ob_clean();
            echo json_encode(['success' => false, 'error' => 'Erro ao criar fila de emails. Verifique se a tabela email_queue existe.']);
            exit;
        }
    }

    // ==========================================================
    // PWA MODULE ACTIONS
    // ==========================================================
    elseif ($action === 'get_pwa_config') {
        // Verifica se módulo PWA está instalado
        if (!file_exists(__DIR__ . '/../pwa/pwa_config.php')) {
            ob_clean();
            echo json_encode(['success' => false, 'error' => 'Módulo PWA não instalado']);
            exit;
        }
        
        require_once __DIR__ . '/../pwa/pwa_config.php';
        
        if (!function_exists('pwa_get_config')) {
            ob_clean();
            echo json_encode(['success' => false, 'error' => 'Funções PWA não disponíveis']);
            exit;
        }
        
        $config = pwa_get_config();
        
        ob_clean();
        echo json_encode(['success' => true, 'data' => $config]);
        exit;
    }
    
    elseif ($action === 'save_pwa_config' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf_for_modifying_actions();
        // Verifica se módulo PWA está instalado
        if (!file_exists(__DIR__ . '/../pwa/pwa_config.php')) {
            ob_clean();
            echo json_encode(['success' => false, 'error' => 'Módulo PWA não instalado']);
            exit;
        }
        
        require_once __DIR__ . '/../pwa/pwa_config.php';
        
        if (!function_exists('pwa_save_config')) {
            ob_clean();
            echo json_encode(['success' => false, 'error' => 'Funções PWA não disponíveis']);
            exit;
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            ob_clean();
            echo json_encode(['success' => false, 'error' => 'Dados JSON inválidos']);
            exit;
        }
        
        $result = pwa_save_config($input);
        
        ob_clean();
        if ($result) {
            echo json_encode(['success' => true, 'message' => 'Configurações PWA salvas com sucesso']);
        } else {
            echo json_encode(['success' => false, 'error' => 'Erro ao salvar configurações PWA']);
        }
        exit;
    }
    
    elseif ($action === 'upload_pwa_icon' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf_for_modifying_actions();
        // Verifica se módulo PWA está instalado
        if (!file_exists(__DIR__ . '/../pwa/pwa_config.php')) {
            ob_clean();
            echo json_encode(['success' => false, 'error' => 'Módulo PWA não instalado']);
            exit;
        }
        
        require_once __DIR__ . '/../pwa/pwa_config.php';
        require_once __DIR__ . '/../helpers/security_helper.php';
        
        if (!isset($_FILES['icon'])) {
            ob_clean();
            echo json_encode(['success' => false, 'error' => 'Nenhum arquivo enviado']);
            exit;
        }
        
        $upload_dir = 'uploads/';
        // Apenas JPEG ou PNG para ícones PWA
        $upload_result = validate_image_upload($_FILES['icon'], $upload_dir, 'pwa_icon', 2, true);
        
        if ($upload_result['success']) {
            $icon_path = $upload_result['file_path'];
            
            // Atualiza configuração PWA com o caminho do ícone
            $config = pwa_get_config();
            if ($config) {
                $config['icon_path'] = $icon_path;
                pwa_save_config($config);
            }
            
            ob_clean();
            echo json_encode([
                'success' => true,
                'icon_path' => $icon_path,
                'icon_url' => '/' . $icon_path,
                'message' => 'Ícone enviado com sucesso'
            ]);
        } else {
            ob_clean();
            echo json_encode(['success' => false, 'error' => $upload_result['error']]);
        }
        exit;
    }
    
    // ==========================================================
    // ENDPOINTS DE NOTIFICAÇÕES PUSH PWA
    // ==========================================================
    
    elseif ($action === 'register_push_subscription' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        // Verifica se módulo PWA está instalado
        if (!file_exists(__DIR__ . '/../pwa/api/web_push_helper.php')) {
            ob_clean();
            echo json_encode(['success' => false, 'error' => 'Módulo PWA Push não instalado']);
            exit;
        }
        
        // Verifica se já foi incluído antes de incluir
        if (!function_exists('pwa_register_subscription')) {
            require_once __DIR__ . '/../pwa/api/web_push_helper.php';
        }
        
        // Verifica se usuário está logado
        if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || !isset($_SESSION['id'])) {
            ob_clean();
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Não autorizado']);
            exit;
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        if (json_last_error() !== JSON_ERROR_NONE || empty($input['subscription'])) {
            ob_clean();
            echo json_encode(['success' => false, 'error' => 'Dados de subscription inválidos']);
            exit;
        }
        
        $usuario_id = $_SESSION['id'];
        $subscription = $input['subscription'];
        
        error_log("PWA Push: Tentando registrar subscription para usuário ID: " . $usuario_id);
        error_log("PWA Push: Endpoint: " . (isset($subscription['endpoint']) ? substr($subscription['endpoint'], 0, 50) . '...' : 'não fornecido'));
        
        $result = pwa_register_subscription($usuario_id, $subscription);
        
        ob_clean();
        if ($result) {
            error_log("PWA Push: Subscription registrada com sucesso para usuário ID: " . $usuario_id);
            echo json_encode(['success' => true, 'message' => 'Subscription registrada com sucesso']);
        } else {
            error_log("PWA Push: Falha ao registrar subscription para usuário ID: " . $usuario_id);
            echo json_encode(['success' => false, 'error' => 'Erro ao registrar subscription. Verifique os logs do servidor.']);
        }
        exit;
    }
    
    elseif ($action === 'get_vapid_keys' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        // Captura qualquer output anterior
        if (ob_get_level() > 0) {
            ob_clean();
        }
        
        // Registra handler de erros fatais
        register_shutdown_function(function() {
            $error = error_get_last();
            if ($error !== NULL && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
                if (ob_get_level() > 0) {
                    ob_clean();
                }
                http_response_code(500);
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false, 
                    'error' => 'Erro fatal: ' . $error['message'] . ' em ' . $error['file'] . ' linha ' . $error['line']
                ]);
                exit;
            }
        });
        
        try {
            // Verifica versão do PHP
            if (version_compare(PHP_VERSION, '8.2.0', '<')) {
                echo json_encode(['success' => false, 'error' => 'PHP 8.2+ necessário. Versão atual: ' . PHP_VERSION]);
                exit;
            }
            
            // Verifica se módulo PWA está instalado
            if (!file_exists(__DIR__ . '/../pwa/api/web_push_helper.php')) {
                echo json_encode(['success' => false, 'error' => 'Módulo PWA Push não instalado']);
                exit;
            }
            
            // Verifica se vendor/autoload existe
            if (!file_exists(__DIR__ . '/../vendor/autoload.php')) {
                echo json_encode(['success' => false, 'error' => 'vendor/autoload.php não encontrado. Execute: composer install']);
                exit;
            }
            
            // Verifica se a biblioteca web-push está instalada
            if (!file_exists(__DIR__ . '/../vendor/minishlink/web-push')) {
                echo json_encode(['success' => false, 'error' => 'Biblioteca minishlink/web-push não encontrada. Execute: composer require minishlink/web-push']);
                exit;
            }
            
            // Garante que config.php foi carregado
            if (!isset($pdo) && !isset($GLOBALS['pdo'])) {
                if (file_exists(__DIR__ . '/../config/config.php')) {
                    require_once __DIR__ . '/../config/config.php';
                }
            }
            
            // Verifica se PDO está disponível após carregar config
            if (!isset($pdo) && !isset($GLOBALS['pdo'])) {
                echo json_encode(['success' => false, 'error' => 'PDO não disponível. Verifique a configuração do banco de dados.']);
                exit;
            }
            
            // Verifica se já foi incluído antes de incluir
            if (!function_exists('pwa_get_or_generate_vapid_keys')) {
                require_once __DIR__ . '/../pwa/api/web_push_helper.php';
            }
            
            if (!function_exists('pwa_get_or_generate_vapid_keys')) {
                echo json_encode(['success' => false, 'error' => 'Função pwa_get_or_generate_vapid_keys não encontrada']);
                exit;
            }
            
            $vapidKeys = pwa_get_or_generate_vapid_keys();
            
            if ($vapidKeys && !empty($vapidKeys['publicKey'])) {
                echo json_encode([
                    'success' => true,
                    'publicKey' => $vapidKeys['publicKey']
                ]);
            } else {
                $errorMsg = 'Erro ao obter ou gerar chaves VAPID. Verifique os logs do PHP.';
                // Verifica se o erro é relacionado a OpenSSL/EC
                $lastError = error_get_last();
                if ($lastError && (strpos($lastError['message'], 'Unable to create the key') !== false || 
                    strpos($lastError['message'], 'EC') !== false)) {
                    $errorMsg = 'OpenSSL não tem suporte a curvas elípticas. Use: node pwa/generate_vapid-keys.js ou acesse /pwa/generate_vapid_keys.php';
                }
                echo json_encode(['success' => false, 'error' => $errorMsg]);
            }
        } catch (\Throwable $e) {
            error_log("Erro em get_vapid_keys: " . $e->getMessage() . " | File: " . $e->getFile() . " | Line: " . $e->getLine() . " | Trace: " . $e->getTraceAsString());
            if (ob_get_level() > 0) {
                ob_clean();
            }
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Erro interno: ' . $e->getMessage() . ' (Linha ' . $e->getLine() . ')']);
        }
        exit;
    }
    
    elseif ($action === 'get_push_subscriptions' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        // Captura qualquer output anterior
        if (ob_get_level() > 0) {
            ob_clean();
        }
        
        // Registra handler de erros fatais
        register_shutdown_function(function() {
            $error = error_get_last();
            if ($error !== NULL && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
                if (ob_get_level() > 0) {
                    ob_clean();
                }
                http_response_code(500);
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false, 
                    'error' => 'Erro fatal: ' . $error['message'] . ' em ' . $error['file'] . ' linha ' . $error['line']
                ]);
                exit;
            }
        });
        
        try {
            // Verifica se é admin
            if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || !isset($_SESSION['tipo']) || $_SESSION['tipo'] !== 'admin') {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Acesso negado']);
                exit;
            }
            
            // Verifica se módulo PWA está instalado
            if (!file_exists(__DIR__ . '/../pwa/api/web_push_helper.php')) {
                echo json_encode(['success' => false, 'error' => 'Módulo PWA Push não instalado']);
                exit;
            }
            
            // Garante que config.php foi carregado
            if (!isset($pdo) && !isset($GLOBALS['pdo'])) {
                if (file_exists(__DIR__ . '/../config/config.php')) {
                    require_once __DIR__ . '/../config/config.php';
                }
            }
            
            // Verifica se PDO está disponível após carregar config
            if (!isset($pdo) && !isset($GLOBALS['pdo'])) {
                echo json_encode(['success' => false, 'error' => 'PDO não disponível. Verifique a configuração do banco de dados.']);
                exit;
            }
            
            // Verifica se já foi incluído antes de incluir
            if (!function_exists('pwa_get_subscriptions')) {
                require_once __DIR__ . '/../pwa/api/web_push_helper.php';
            }
            
            if (!function_exists('pwa_get_subscriptions') || !function_exists('pwa_count_subscriptions')) {
                echo json_encode(['success' => false, 'error' => 'Funções PWA Push não encontradas']);
                exit;
            }
            
            $subscriptions = pwa_get_subscriptions();
            $count = pwa_count_subscriptions();
            
            echo json_encode([
                'success' => true,
                'subscriptions' => $subscriptions ?: [],
                'count' => $count ?: 0
            ]);
        } catch (\Throwable $e) {
            error_log("Erro em get_push_subscriptions: " . $e->getMessage() . " | File: " . $e->getFile() . " | Line: " . $e->getLine() . " | Trace: " . $e->getTraceAsString());
            if (ob_get_level() > 0) {
                ob_clean();
            }
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Erro interno: ' . $e->getMessage() . ' (Linha ' . $e->getLine() . ')']);
        }
        exit;
    }
    
    elseif ($action === 'send_push_notification' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        ob_clean();
        try {
            // Verifica se é admin
            if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || !isset($_SESSION['tipo']) || $_SESSION['tipo'] !== 'admin') {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Acesso negado']);
                exit;
            }
            
            // Verifica se módulo PWA está instalado
            if (!file_exists(__DIR__ . '/../pwa/api/web_push_helper.php')) {
                echo json_encode(['success' => false, 'error' => 'Módulo PWA Push não instalado']);
                exit;
            }
            
            // Garante que config.php foi carregado
            if (!isset($pdo) && !isset($GLOBALS['pdo'])) {
                if (file_exists(__DIR__ . '/../config/config.php')) {
                    require_once __DIR__ . '/../config/config.php';
                }
            }
            
            // Verifica se já foi incluído antes de incluir
            if (!function_exists('pwa_send_push_notification')) {
                require_once __DIR__ . '/../pwa/api/web_push_helper.php';
            }
            
            if (!function_exists('pwa_send_push_notification')) {
                echo json_encode(['success' => false, 'error' => 'Função pwa_send_push_notification não encontrada']);
                exit;
            }
            
            $input = json_decode(file_get_contents('php://input'), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                echo json_encode(['success' => false, 'error' => 'Dados JSON inválidos: ' . json_last_error_msg()]);
                exit;
            }
            
            $title = trim($input['title'] ?? '');
            $message = trim($input['message'] ?? '');
            $url = trim($input['url'] ?? '');
            $icon = trim($input['icon'] ?? '');
            
            if (empty($title) || empty($message)) {
                echo json_encode(['success' => false, 'error' => 'Título e mensagem são obrigatórios']);
                exit;
            }
            
            $created_by = $_SESSION['id'] ?? null;
            $result = pwa_send_push_notification($title, $message, $url ?: null, $icon ?: null, $created_by);
            
            ob_clean();
            if (isset($result['error'])) {
                echo json_encode(['success' => false, 'error' => $result['error']]);
            } else {
                echo json_encode([
                    'success' => true,
                    'sent' => $result['sent'] ?? 0,
                    'failed' => $result['failed'] ?? 0,
                    'total' => $result['total'] ?? 0,
                    'message' => "Notificação enviada para " . ($result['sent'] ?? 0) . " de " . ($result['total'] ?? 0) . " dispositivos"
                ]);
            }
        } catch (\Throwable $e) {
            error_log("Erro em send_push_notification: " . $e->getMessage() . " | File: " . $e->getFile() . " | Line: " . $e->getLine() . " | Trace: " . $e->getTraceAsString());
            ob_clean();
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Erro interno: ' . $e->getMessage()]);
        }
        exit;
    }
    
    elseif ($action === 'get_push_notification_history' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        ob_clean();
        try {
            // Verifica se é admin
            if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || !isset($_SESSION['tipo']) || $_SESSION['tipo'] !== 'admin') {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Acesso negado']);
                exit;
            }
            
            // Verifica se módulo PWA está instalado
            if (!file_exists(__DIR__ . '/../pwa/api/web_push_helper.php')) {
                echo json_encode(['success' => false, 'error' => 'Módulo PWA Push não instalado']);
                exit;
            }
            
            // Garante que config.php foi carregado
            if (!isset($pdo) && !isset($GLOBALS['pdo'])) {
                if (file_exists(__DIR__ . '/../config/config.php')) {
                    require_once __DIR__ . '/../config/config.php';
                }
            }
            
            // Verifica se já foi incluído antes de incluir
            if (!function_exists('pwa_get_notification_history')) {
                require_once __DIR__ . '/../pwa/api/web_push_helper.php';
            }
            
            if (!function_exists('pwa_get_notification_history')) {
                echo json_encode(['success' => false, 'error' => 'Função pwa_get_notification_history não encontrada']);
                exit;
            }
            
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
            $history = pwa_get_notification_history($limit);
            
            ob_clean();
            echo json_encode([
                'success' => true,
                'history' => $history ?: []
            ]);
        } catch (\Throwable $e) {
            error_log("Erro em get_push_notification_history: " . $e->getMessage() . " | File: " . $e->getFile() . " | Line: " . $e->getLine() . " | Trace: " . $e->getTraceAsString());
            ob_clean();
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Erro interno: ' . $e->getMessage()]);
        }
        exit;
    }

    // NOVO: Ação para gerar relatórios
    elseif ($action === 'generate_report') {
        try {
            error_log("ADMIN_API: Recebida ação generate_report. Method: " . $_SERVER['REQUEST_METHOD']);
            $input = json_decode(file_get_contents('php://input'), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $input = $_GET; // Fallback para GET
                error_log("ADMIN_API: Usando dados de GET como fallback");
            } else {
                error_log("ADMIN_API: Dados recebidos via JSON: " . print_r($input, true));
            }
            
            $report_type = $input['report_type'] ?? 'vendas_periodo';
            $date_start = $input['date_start'] ?? null;
            $date_end = $input['date_end'] ?? null;
            $status_filter = $input['status_filter'] ?? null;
            $vendedor_filter = $input['vendedor_filter'] ?? null;
            $produto_filter = $input['produto_filter'] ?? null;
            $metodo_filter = $input['metodo_filter'] ?? null;
            
            // Construir filtros de data
            $date_where = "";
            $date_params = [];
            if ($date_start && $date_end) {
                $date_where = "AND DATE(v.data_venda) BETWEEN :date_start AND :date_end";
                $date_params[':date_start'] = $date_start;
                $date_params[':date_end'] = $date_end;
            } elseif ($date_start) {
                $date_where = "AND DATE(v.data_venda) >= :date_start";
                $date_params[':date_start'] = $date_start;
            } elseif ($date_end) {
                $date_where = "AND DATE(v.data_venda) <= :date_end";
                $date_params[':date_end'] = $date_end;
            }
            
            $response = [
                'success' => true,
                'report_type' => $report_type,
                'metrics' => [],
                'data' => [],
                'chart_data' => []
            ];
            
            switch ($report_type) {
                case 'vendas_periodo':
                    // Vendas por Período
                    $sql = "
                        SELECT 
                            DATE(v.data_venda) as dia,
                            COUNT(v.id) as quantidade,
                            SUM(CASE WHEN v.status_pagamento = 'approved' THEN v.valor ELSE 0 END) as faturamento,
                            COUNT(CASE WHEN v.status_pagamento = 'approved' THEN v.id ELSE NULL END) as vendas_aprovadas
                        FROM vendas v
                        WHERE 1=1 {$date_where}
                        GROUP BY DATE(v.data_venda)
                        ORDER BY dia ASC
                    ";
                    $stmt = $pdo->prepare($sql);
                    foreach ($date_params as $key => $val) {
                        $stmt->bindValue($key, $val);
                    }
                    $stmt->execute();
                    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    // Calcular totais
                    $total_vendas = 0;
                    $total_faturamento = 0;
                    $total_aprovadas = 0;
                    foreach ($data as $row) {
                        $total_vendas += $row['quantidade'];
                        $total_faturamento += $row['faturamento'];
                        $total_aprovadas += $row['vendas_aprovadas'];
                    }
                    
                    $response['metrics'] = [
                        'total_vendas' => $total_vendas,
                        'total_faturamento' => $total_faturamento,
                        'total_aprovadas' => $total_aprovadas
                    ];
                    $response['data'] = $data;
                    $response['chart_data'] = [
                        'labels' => array_map(function($row) { return date('d/m', strtotime($row['dia'])); }, $data),
                        'faturamento' => array_map(function($row) { return (float)$row['faturamento']; }, $data),
                        'quantidade' => array_map(function($row) { return (int)$row['quantidade']; }, $data)
                    ];
                    break;
                    
                case 'produtos_vendidos':
                    // Produtos Vendidos
                    $produto_where = "";
                    $produto_params = $date_params;
                    if ($produto_filter) {
                        $produto_where = "AND p.id = :produto_id";
                        $produto_params[':produto_id'] = $produto_filter;
                    }
                    
                    $sql = "
                        SELECT 
                            p.id,
                            p.nome,
                            p.foto,
                            COUNT(v.id) as quantidade_vendida,
                            SUM(CASE WHEN v.status_pagamento = 'approved' THEN v.valor ELSE 0 END) as faturamento_total,
                            COUNT(CASE WHEN v.status_pagamento = 'approved' THEN v.id ELSE NULL END) as vendas_aprovadas
                        FROM produtos p
                        LEFT JOIN vendas v ON p.id = v.produto_id
                        WHERE 1=1 {$produto_where}
                        " . ($date_where ? "AND 1=1 {$date_where}" : "") . "
                        GROUP BY p.id, p.nome, p.foto
                        HAVING quantidade_vendida > 0
                        ORDER BY faturamento_total DESC, quantidade_vendida DESC
                    ";
                    $stmt = $pdo->prepare($sql);
                    foreach ($produto_params as $key => $val) {
                        $stmt->bindValue($key, $val);
                    }
                    $stmt->execute();
                    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    $response['data'] = $data;
                    $response['chart_data'] = [
                        'labels' => array_map(function($row) { return $row['nome']; }, array_slice($data, 0, 10)),
                        'faturamento' => array_map(function($row) { return (float)$row['faturamento_total']; }, array_slice($data, 0, 10)),
                        'quantidade' => array_map(function($row) { return (int)$row['quantidade_vendida']; }, array_slice($data, 0, 10))
                    ];
                    break;
                    
                case 'atividade_usuarios':
                    // Atividade de Usuários
                    // Como a tabela usuarios não tem coluna de data de cadastro,
                    // vamos mostrar atividade baseada em vendas aprovadas (conversões)
                    
                    $venda_date_where = "";
                    if ($date_start && $date_end) {
                        $venda_date_where = "AND DATE(v.data_venda) BETWEEN :date_start AND :date_end";
                    } elseif ($date_start) {
                        $venda_date_where = "AND DATE(v.data_venda) >= :date_start";
                    } elseif ($date_end) {
                        $venda_date_where = "AND DATE(v.data_venda) <= :date_end";
                    }
                    
                    // Contar conversões (vendas aprovadas) por dia e novos compradores únicos
                    $sql = "
                        SELECT 
                            DATE(v.data_venda) as dia,
                            COUNT(DISTINCT v.comprador_email) as novos_cadastros,
                            COUNT(DISTINCT v.comprador_email) as conversoes
                        FROM vendas v
                        WHERE v.status_pagamento = 'approved'
                        {$venda_date_where}
                        GROUP BY DATE(v.data_venda)
                        ORDER BY dia DESC
                        LIMIT 30
                    ";
                    $stmt = $pdo->prepare($sql);
                    foreach ($date_params as $key => $val) {
                        $stmt->bindValue($key, $val);
                    }
                    $stmt->execute();
                    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    // Total de usuários
                    $sql_total = "SELECT COUNT(id) as total FROM usuarios";
                    $total_usuarios = $pdo->query($sql_total)->fetch(PDO::FETCH_ASSOC)['total'];
                    
                    $response['metrics'] = [
                        'total_usuarios' => $total_usuarios
                    ];
                    $response['data'] = $data;
                    $response['chart_data'] = [
                        'labels' => array_map(function($row) { return date('d/m', strtotime($row['dia'])); }, $data),
                        'cadastros' => array_map(function($row) { return (int)$row['novos_cadastros']; }, $data),
                        'conversoes' => array_map(function($row) { return (int)$row['conversoes']; }, $data)
                    ];
                    break;
                    
                case 'faturamento_vendedores':
                    // Faturamento por Vendedor
                    $vendedor_where = "";
                    $vendedor_params = $date_params;
                    if ($vendedor_filter) {
                        $vendedor_where = "AND u.id = :vendedor_id";
                        $vendedor_params[':vendedor_id'] = $vendedor_filter;
                    }
                    
                    $sql = "
                        SELECT 
                            u.id,
                            u.usuario,
                            u.nome,
                            u.foto_perfil,
                            COUNT(v.id) as total_vendas,
                            SUM(CASE WHEN v.status_pagamento = 'approved' THEN v.valor ELSE 0 END) as faturamento_total,
                            COUNT(CASE WHEN v.status_pagamento = 'approved' THEN v.id ELSE NULL END) as vendas_aprovadas
                        FROM usuarios u
                        INNER JOIN produtos p ON u.id = p.usuario_id
                        LEFT JOIN vendas v ON p.id = v.produto_id
                        WHERE u.tipo = 'infoprodutor' {$vendedor_where}
                        " . ($date_where ? "AND 1=1 {$date_where}" : "") . "
                        GROUP BY u.id, u.usuario, u.nome, u.foto_perfil
                        HAVING total_vendas > 0
                        ORDER BY faturamento_total DESC
                    ";
                    $stmt = $pdo->prepare($sql);
                    foreach ($vendedor_params as $key => $val) {
                        $stmt->bindValue($key, $val);
                    }
                    $stmt->execute();
                    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    $response['data'] = $data;
                    $response['chart_data'] = [
                        'labels' => array_map(function($row) { return $row['nome'] ?: $row['usuario']; }, array_slice($data, 0, 10)),
                        'faturamento' => array_map(function($row) { return (float)$row['faturamento_total']; }, array_slice($data, 0, 10))
                    ];
                    break;
                    
                case 'metodos_pagamento':
                    // Métodos de Pagamento
                    $metodo_where = "";
                    $metodo_params = $date_params;
                    if ($metodo_filter) {
                        $metodo_where = "AND v.metodo_pagamento = :metodo";
                        $metodo_params[':metodo'] = $metodo_filter;
                    }
                    
                    $sql = "
                        SELECT 
                            v.metodo_pagamento,
                            COUNT(v.id) as quantidade,
                            SUM(CASE WHEN v.status_pagamento = 'approved' THEN v.valor ELSE 0 END) as faturamento,
                            COUNT(CASE WHEN v.status_pagamento = 'approved' THEN v.id ELSE NULL END) as aprovadas
                        FROM vendas v
                        WHERE 1=1 {$metodo_where} {$date_where}
                        GROUP BY v.metodo_pagamento
                        ORDER BY faturamento DESC
                    ";
                    $stmt = $pdo->prepare($sql);
                    foreach ($metodo_params as $key => $val) {
                        $stmt->bindValue($key, $val);
                    }
                    $stmt->execute();
                    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    $total_faturamento = array_sum(array_map(function($row) { return $row['faturamento']; }, $data));
                    
                    $response['metrics'] = [
                        'total_faturamento' => $total_faturamento
                    ];
                    $response['data'] = $data;
                    $response['chart_data'] = [
                        'labels' => array_map(function($row) { return $row['metodo_pagamento'] ?: 'Não informado'; }, $data),
                        'faturamento' => array_map(function($row) { return (float)$row['faturamento']; }, $data),
                        'quantidade' => array_map(function($row) { return (int)$row['quantidade']; }, $data)
                    ];
                    break;
                    
                case 'status_vendas':
                    // Status de Vendas
                    $status_where = "";
                    $status_params = $date_params;
                    if ($status_filter) {
                        $status_where = "AND v.status_pagamento = :status";
                        $status_params[':status'] = $status_filter;
                    }
                    
                    $sql = "
                        SELECT 
                            v.status_pagamento,
                            COUNT(v.id) as quantidade,
                            SUM(v.valor) as valor_total
                        FROM vendas v
                        WHERE 1=1 {$status_where} {$date_where}
                        GROUP BY v.status_pagamento
                        ORDER BY quantidade DESC
                    ";
                    $stmt = $pdo->prepare($sql);
                    foreach ($status_params as $key => $val) {
                        $stmt->bindValue($key, $val);
                    }
                    $stmt->execute();
                    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    $response['data'] = $data;
                    $response['chart_data'] = [
                        'labels' => array_map(function($row) {
                            $status_map = [
                                'approved' => 'Aprovadas',
                                'pending' => 'Pendentes',
                                'in_process' => 'Em Processamento',
                                'refunded' => 'Reembolsadas',
                                'charged_back' => 'Chargeback',
                                'cancelled' => 'Canceladas',
                                'info_filled' => 'Info Preenchida'
                            ];
                            return $status_map[$row['status_pagamento']] ?? $row['status_pagamento'];
                        }, $data),
                        'quantidade' => array_map(function($row) { return (int)$row['quantidade']; }, $data),
                        'valor' => array_map(function($row) { return (float)$row['valor_total']; }, $data)
                    ];
                    break;
                    
                case 'carrinhos_abandonados':
                    // Carrinhos Abandonados
                    $sql = "
                        SELECT 
                            DATE(v.data_venda) as dia,
                            COUNT(v.id) as quantidade_abandonados,
                            SUM(v.valor) as valor_abandonado,
                            COUNT(CASE WHEN v.status_pagamento = 'info_filled' THEN v.id ELSE NULL END) as com_info_preenchida
                        FROM vendas v
                        WHERE v.status_pagamento IN ('pending', 'in_process', 'cancelled', 'info_filled')
                        {$date_where}
                        GROUP BY DATE(v.data_venda)
                        ORDER BY dia DESC
                    ";
                    $stmt = $pdo->prepare($sql);
                    foreach ($date_params as $key => $val) {
                        $stmt->bindValue($key, $val);
                    }
                    $stmt->execute();
                    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    // Total de abandonados
                    $sql_total = "
                        SELECT 
                            COUNT(v.id) as total_abandonados,
                            SUM(v.valor) as valor_total_abandonado
                        FROM vendas v
                        WHERE v.status_pagamento IN ('pending', 'in_process', 'cancelled', 'info_filled')
                        {$date_where}
                    ";
                    $stmt_total = $pdo->prepare($sql_total);
                    foreach ($date_params as $key => $val) {
                        $stmt_total->bindValue($key, $val);
                    }
                    $stmt_total->execute();
                    $total_data = $stmt_total->fetch(PDO::FETCH_ASSOC);
                    
                    $response['metrics'] = [
                        'total_abandonados' => (int)$total_data['total_abandonados'],
                        'valor_total_abandonado' => (float)$total_data['valor_total_abandonado']
                    ];
                    $response['data'] = $data;
                    $response['chart_data'] = [
                        'labels' => array_map(function($row) { return date('d/m', strtotime($row['dia'])); }, $data),
                        'quantidade' => array_map(function($row) { return (int)$row['quantidade_abandonados']; }, $data),
                        'valor' => array_map(function($row) { return (float)$row['valor_abandonado']; }, $data)
                    ];
                    break;
                    
                default:
                    throw new Exception("Tipo de relatório inválido: " . $report_type);
            }
            
            ob_clean();
            echo json_encode($response);
            
        } catch (Exception $e) {
            error_log("ADMIN_API: Erro ao gerar relatório: " . $e->getMessage());
            http_response_code(500);
            ob_clean();
            echo json_encode(['success' => false, 'error' => 'Erro ao gerar relatório: ' . $e->getMessage()]);
        }
        exit;
    }
    elseif ($action === 'get_backups') {
        try {
            // Listar backups disponíveis
            $backupsDir = __DIR__ . '/../backups';
            $backups = [];
            
            if (is_dir($backupsDir)) {
                $backupFolders = glob($backupsDir . '/update_*');
                if ($backupFolders === false) {
                    $backupFolders = [];
                }
                
                usort($backupFolders, function($a, $b) {
                    return filemtime($b) - filemtime($a);
                });
                
                foreach ($backupFolders as $folder) {
                    if (is_dir($folder)) {
                        try {
                            $size = 0;
                            $iterator = new RecursiveIteratorIterator(
                                new RecursiveDirectoryIterator($folder, RecursiveDirectoryIterator::SKIP_DOTS),
                                RecursiveIteratorIterator::LEAVES_ONLY
                            );
                            
                            foreach ($iterator as $file) {
                                if ($file->isFile()) {
                                    $size += $file->getSize();
                                }
                            }
                            
                            // Formatar tamanho
                            $units = array('B', 'KB', 'MB', 'GB', 'TB');
                            $bytes = max($size, 0);
                            $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
                            $pow = min($pow, count($units) - 1);
                            $bytes /= pow(1024, $pow);
                            $sizeFormatted = round($bytes, 2) . ' ' . $units[$pow];
                            
                            $backups[] = [
                                'folder' => $folder,
                                'name' => basename($folder),
                                'date' => date('d/m/Y H:i:s', filemtime($folder)),
                                'size' => $size,
                                'size_formatted' => $sizeFormatted
                            ];
                        } catch (Exception $e) {
                            error_log("Erro ao processar backup folder {$folder}: " . $e->getMessage());
                            // Continuar com próximo backup
                        }
                    }
                }
            }
            
            ob_clean();
            echo json_encode(['success' => true, 'backups' => $backups]);
            exit;
        } catch (Exception $e) {
            error_log("Erro em get_backups: " . $e->getMessage());
            ob_clean();
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Erro ao listar backups: ' . $e->getMessage()]);
            exit;
        }
    }
    elseif ($action === 'get_github_config' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        try {
            require_once __DIR__ . '/../config/config.php';
            
            $config = [
                'github_repo' => getSystemSetting('github_repo', 'LeonardoIsrael0516/getfy-update'),
                'github_token' => getSystemSetting('github_token', ''),
                'github_branch' => getSystemSetting('github_branch', 'main')
            ];
            
            ob_clean();
            echo json_encode(['success' => true, 'config' => $config]);
            exit;
        } catch (Exception $e) {
            error_log("Erro em get_github_config: " . $e->getMessage());
            ob_clean();
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Erro ao carregar configurações: ' . $e->getMessage()]);
            exit;
        }
    }
    elseif ($action === 'save_github_config' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf_for_modifying_actions();
        try {
            require_once __DIR__ . '/../config/config.php';
            
            $raw_input = file_get_contents('php://input');
            $data = json_decode($raw_input, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                ob_clean();
                echo json_encode(['success' => false, 'error' => 'Erro ao processar dados: ' . json_last_error_msg()]);
                exit;
            }
            
            // Valores padrão
            $default_repo = 'LeonardoIsrael0516/getfy-update';
            $default_branch = 'main';
            
            $github_repo = trim($data['github_repo'] ?? $default_repo);
            $github_token = trim($data['github_token'] ?? '');
            $github_branch = trim($data['github_branch'] ?? $default_branch);
            
            // Se não fornecido, usar padrão
            if (empty($github_repo)) {
                $github_repo = $default_repo;
            }
            
            if (empty($github_branch)) {
                $github_branch = $default_branch;
            }
            
            if (empty($github_token)) {
                ob_clean();
                echo json_encode(['success' => false, 'error' => 'Token GitHub é obrigatório']);
                exit;
            }
            
            // Validar formato do repositório (usuario/repositorio)
            if (!preg_match('/^[a-zA-Z0-9._-]+\/[a-zA-Z0-9._-]+$/', $github_repo)) {
                ob_clean();
                echo json_encode(['success' => false, 'error' => 'Formato do repositório inválido. Use: usuario/repositorio']);
                exit;
            }
            
            $saved = [];
            if (setSystemSetting('github_repo', $github_repo)) {
                $saved[] = 'github_repo';
            }
            if (setSystemSetting('github_token', $github_token)) {
                $saved[] = 'github_token';
            }
            if (setSystemSetting('github_branch', $github_branch)) {
                $saved[] = 'github_branch';
            }
            
            ob_clean();
            echo json_encode(['success' => true, 'message' => 'Configurações salvas com sucesso', 'saved' => $saved]);
            exit;
        } catch (Exception $e) {
            error_log("Erro em save_github_config: " . $e->getMessage());
            ob_clean();
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Erro ao salvar configurações: ' . $e->getMessage()]);
            exit;
        }
    }
    elseif ($action === 'test_github_config' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf_for_modifying_actions();
        try {
            $raw_input = file_get_contents('php://input');
            $data = json_decode($raw_input, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                ob_clean();
                echo json_encode(['success' => false, 'error' => 'Erro ao processar dados: ' . json_last_error_msg()]);
                exit;
            }
            
            // Valores padrão
            $default_repo = 'LeonardoIsrael0516/getfy-update';
            $default_branch = 'main';
            
            $github_repo = trim($data['github_repo'] ?? $default_repo);
            $github_token = trim($data['github_token'] ?? '');
            $github_branch = trim($data['github_branch'] ?? $default_branch);
            
            // Se não fornecido, usar padrão
            if (empty($github_repo)) {
                $github_repo = $default_repo;
            }
            
            if (empty($github_branch)) {
                $github_branch = $default_branch;
            }
            
            if (empty($github_token)) {
                ob_clean();
                echo json_encode(['success' => false, 'error' => 'Token é obrigatório']);
                exit;
            }
            
            // Testar conexão com GitHub
            $url = "https://api.github.com/repos/{$github_repo}/contents/VERSION.txt?ref={$github_branch}";
            
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'User-Agent: PHP-App-Updater',
                'Authorization: token ' . $github_token,
                'Accept: application/vnd.github.v3.raw'
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            
            if ($curlError) {
                ob_clean();
                echo json_encode(['success' => false, 'error' => 'Erro ao conectar com GitHub: ' . $curlError]);
                exit;
            }
            
            if ($httpCode === 200) {
                $version = trim($response);
                ob_clean();
                echo json_encode([
                    'success' => true, 
                    'message' => "Conexão bem-sucedida! Versão encontrada: {$version}"
                ]);
            } elseif ($httpCode === 404) {
                ob_clean();
                echo json_encode(['success' => false, 'error' => 'Arquivo VERSION.txt não encontrado no repositório']);
            } elseif ($httpCode === 403) {
                ob_clean();
                echo json_encode(['success' => false, 'error' => 'Acesso negado. Verifique se o token tem permissão "repo"']);
            } elseif ($httpCode === 401) {
                ob_clean();
                echo json_encode(['success' => false, 'error' => 'Token inválido ou expirado']);
            } else {
                ob_clean();
                echo json_encode(['success' => false, 'error' => "Erro ao acessar GitHub (HTTP {$httpCode})"]);
            }
            exit;
        } catch (Exception $e) {
            error_log("Erro em test_github_config: " . $e->getMessage());
            ob_clean();
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Erro ao testar conexão: ' . $e->getMessage()]);
            exit;
        }
    }
    // Verificação de Integridade do Banco de Dados
    elseif ($action === 'check_integrity' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        try {
            if (!isset($pdo)) {
                throw new Exception('Conexão com banco de dados não disponível');
            }
            
            // Verificar se as funções necessárias existem
            if (!function_exists('check_database_integrity')) {
                throw new Exception('Função check_database_integrity não encontrada');
            }
            
            $integrity = check_database_integrity($pdo);
            
            ob_clean();
            echo json_encode([
                'success' => true,
                'data' => $integrity
            ]);
            exit;
        } catch (Throwable $e) {
            $errorMsg = $e->getMessage();
            $errorFile = $e->getFile();
            $errorLine = $e->getLine();
            $errorTrace = $e->getTraceAsString();
            
            error_log("Erro em check_integrity: {$errorMsg} em {$errorFile}:{$errorLine}");
            error_log("Stack trace: {$errorTrace}");
            
            ob_clean();
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Erro ao verificar integridade: ' . $errorMsg,
                'file' => basename($errorFile),
                'line' => $errorLine
            ]);
            exit;
        }
    }
    // Executar Migrations Faltantes
    elseif ($action === 'run_missing_migrations' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        require_csrf_for_modifying_actions();
        try {
            if (!isset($pdo)) {
                throw new Exception('Conexão com banco de dados não disponível');
            }
            
            // Verificar integridade primeiro
            $integrity = check_database_integrity($pdo);
            
            // Se não houver tabelas nem colunas faltantes, retornar sucesso
            $hasMissingTables = !empty($integrity['missing_tables']);
            $hasMissingColumns = !empty($integrity['missing_columns']) && $integrity['total_missing_columns'] > 0;
            
            if (!$hasMissingTables && !$hasMissingColumns) {
                ob_clean();
                echo json_encode([
                    'success' => true,
                    'message' => 'Nenhuma tabela ou coluna faltando. Banco de dados está íntegro.',
                    'migrations' => [
                        'executed' => [],
                        'skipped' => [],
                        'errors' => []
                    ],
                    'columns_fixed' => [
                        'added' => [],
                        'errors' => []
                    ]
                ]);
                exit;
            }
            
            $migrationResults = [
                'executed' => [],
                'skipped' => [],
                'errors' => []
            ];
            $columnsFixed = [
                'added' => [],
                'errors' => []
            ];
            
            // 1. Executar migrations para criar tabelas faltantes
            if ($hasMissingTables) {
                $versionFile = __DIR__ . '/../VERSION.txt';
                $currentVersion = '1.0.0';
                if (file_exists($versionFile)) {
                    $currentVersion = trim(file_get_contents($versionFile));
                    if (empty($currentVersion)) {
                        $currentVersion = '1.0.0';
                    }
                }
                
                $migrationResults = run_migrations($pdo, $currentVersion);
            }
            
            // 2. Adicionar colunas faltantes em tabelas existentes
            if ($hasMissingColumns && !empty($integrity['missing_columns'])) {
                // Obter mapa de migrations para cada tabela
                $expectedTables = get_expected_tables();
                $columnsFixed = fix_missing_columns($pdo, $integrity['missing_columns'], $expectedTables);
            }
            
            // Aguardar um pouco antes de verificar novamente para garantir que o banco processou
            sleep(2); // 2 segundos para garantir que o banco processou todas as alterações
            
            // Verificar novamente após execução (forçar busca fresca)
            // Limpar qualquer cache do PDO
            $integrityAfter = check_database_integrity($pdo);
            
            // Verificar se ainda há colunas faltantes após a correção
            $stillMissingColumns = !empty($integrityAfter['missing_columns']) && $integrityAfter['total_missing_columns'] > 0;
            $stillMissingTables = !empty($integrityAfter['missing_tables']);
            
            // Se ainda há problemas, considerar como erro
            if ($stillMissingColumns || $stillMissingTables) {
                error_log("INTEGRIDADE: Ainda há problemas após correção:");
                if ($stillMissingTables) {
                    error_log("INTEGRIDADE: Tabelas ainda faltando: " . implode(', ', $integrityAfter['missing_tables']));
                }
                if ($stillMissingColumns) {
                    foreach ($integrityAfter['missing_columns'] as $table => $cols) {
                        error_log("INTEGRIDADE: Tabela {$table} ainda tem colunas faltando: " . implode(', ', $cols));
                    }
                }
            }
            
            $hasErrors = !empty($migrationResults['errors']) || !empty($columnsFixed['errors']) || $stillMissingColumns || $stillMissingTables;
            $successMessage = 'Correção concluída!';
            if ($hasErrors) {
                $successMessage = 'Correção concluída com alguns erros. Verifique os detalhes.';
                if ($stillMissingColumns || $stillMissingTables) {
                    $successMessage .= ' Algumas colunas ou tabelas ainda estão faltando após a correção.';
                }
            }
            
            ob_clean();
            echo json_encode([
                'success' => !$hasErrors,
                'message' => $successMessage,
                'migrations' => $migrationResults,
                'columns_fixed' => $columnsFixed,
                'integrity_after' => $integrityAfter
            ]);
            exit;
        } catch (Exception $e) {
            error_log("Erro em run_missing_migrations: " . $e->getMessage());
            ob_clean();
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'error' => 'Erro ao executar migrations: ' . $e->getMessage()
            ]);
            exit;
        }
    }
    
    error_log("ADMIN_API: Ação inválida recebida: " . $action);
    // Limpa o buffer antes de enviar o JSON
    ob_clean();
    echo json_encode(['error' => 'Ação inválida']);

} catch (Throwable $e) { // Captura Exception e Error
    http_response_code(500);
    error_log('ADMIN_API: Erro Fatal na API de Admin: ' . $e->getMessage() . ' no arquivo ' . $e->getFile() . ' na linha ' . $e->getLine());
    error_log('ADMIN_API: Stack trace: ' . $e->getTraceAsString());
    // Limpa o buffer antes de enviar o JSON
    ob_clean();
    // Garantir que o header JSON está definido
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Ocorreu um erro interno no servidor. Verifique os logs de erro do PHP em ' . __DIR__ . '/admin_api_errors.log para mais detalhes.', 'message' => $e->getMessage()]);
    exit;
}