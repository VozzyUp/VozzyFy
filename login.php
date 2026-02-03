<?php
// Inicia buffer de saída para evitar qualquer output antes do HTML
ob_start();

// Assumimos que o seu arquivo 'config.php' já inicia a sessão com session_start().
// Se não for o caso, a linha session_start() deve ser a primeira linha deste arquivo.
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/helpers/security_helper.php';

// Verifica acesso SaaS ao fazer login
if (isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true && plugin_active('saas')) {
    require_once __DIR__ . '/plugins/saas/includes/notifications.php';
    require_once __DIR__ . '/plugins/saas/saas.php';
    
    // Garante que usuário tenha plano free se disponível
    if ($_SESSION['tipo'] !== 'admin') {
        saas_ensure_free_plan($_SESSION['id']);
    }
    
    if (!saas_check_user_access($_SESSION['id']) && $_SESSION['tipo'] !== 'admin') {
        // Redireciona para página de planos se não tiver acesso
        header("location: /index?pagina=planos");
        exit;
    }
}

// Se o usuário já estiver logado, redireciona para o painel apropriado
if (isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true) {
    // Redirecionamentos baseados no tipo
    if (isset($_SESSION["tipo"]) && $_SESSION["tipo"] == 'admin') {
        header("location: /admin"); exit;
    } 
    if (isset($_SESSION["tipo"]) && $_SESSION["tipo"] == 'infoprodutor') {
        header("location: /"); exit;
    }
    if (isset($_SESSION["tipo"]) && $_SESSION["tipo"] == 'usuario') {
        header("location: /member_area_dashboard"); exit;
    }
    header("location: /login"); exit;
}

$erro = '';
$usuario_input = '';

// Verifica se existe cookie de "Lembrar usuário" para pré-preencher
if (isset($_COOKIE['remember_user'])) {
    $usuario_input = $_COOKIE['remember_user'];
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    try {
        if (empty(trim($_POST["usuario"] ?? '')) || empty(trim($_POST["senha"] ?? ''))) {
            $erro = "Por favor, preencha o usuário e a senha.";
        } else {
            $usuario_input = trim($_POST["usuario"]);
            $senha_input = trim($_POST["senha"]);
            
            // Verifica rate limiting ANTES de processar login
            $client_ip = get_client_ip();
            $rate_check = check_login_attempts($client_ip, $usuario_input);
            
            if (!$rate_check['allowed']) {
                // Calcula tempo restante
                $blocked_until = strtotime($rate_check['blocked_until']);
                $remaining_seconds = $blocked_until - time();
                $remaining_minutes = ceil($remaining_seconds / 60);
                
                if ($remaining_minutes <= 0) {
                    $erro = "Muitas tentativas falhas. Tente novamente em alguns instantes.";
                } else {
                    $erro = "Muitas tentativas falhas. Tente novamente em " . $remaining_minutes . " minuto(s).";
                }
            } else {

            $sql = "SELECT id, usuario, nome, senha, tipo FROM usuarios WHERE usuario = :usuario";
            
            $stmt = $pdo->prepare($sql);
            if ($stmt) {
                $stmt->bindParam(":usuario", $usuario_input, PDO::PARAM_STR);
                
                if ($stmt->execute()) {
                    if ($stmt->rowCount() == 1) {
                        $row = $stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($row && isset($row["senha"]) && password_verify($senha_input, $row["senha"])) {
                            // Limpa tentativas de login após login bem-sucedido
                            clear_login_attempts($client_ip, $usuario_input);
                            
                            // Regenera ID de sessão imediatamente após login (prevenção de session fixation)
                            session_regenerate_id(true);
                            
                            $_SESSION["loggedin"] = true;
                            $_SESSION["id"] = $row["id"];
                            $_SESSION["usuario"] = $row["usuario"];
                            $_SESSION["nome"] = $row["nome"];
                            $_SESSION["tipo"] = $row["tipo"];
                            $_SESSION["last_activity"] = time(); // Atualiza última atividade

                            // Lógica do "Lembrar-me" (Cookies)
                            if (isset($_POST['remember'])) {
                                // Gera um token seguro para "lembrar-me"
                                $remember_token = bin2hex(random_bytes(32)); // Token de 64 caracteres
                                $token_expires = date('Y-m-d H:i:s', time() + (86400 * 30)); // 30 dias
                                
                                // Salva o token no banco de dados com expiração
                                try {
                                    // Verificar se coluna remember_token_expires existe
                                    $stmt_check_col = $pdo->query("SHOW COLUMNS FROM usuarios LIKE 'remember_token_expires'");
                                    $col_exists = $stmt_check_col->rowCount() > 0;
                                    
                                    if ($col_exists) {
                                        $stmt_token = $pdo->prepare("UPDATE usuarios SET remember_token = ?, remember_token_expires = ? WHERE id = ?");
                                        $stmt_token->execute([$remember_token, $token_expires, $row["id"]]);
                                    } else {
                                        // Se coluna não existe, criar
                                        try {
                                            $pdo->exec("ALTER TABLE usuarios ADD COLUMN remember_token_expires DATETIME NULL AFTER remember_token");
                                            $stmt_token = $pdo->prepare("UPDATE usuarios SET remember_token = ?, remember_token_expires = ? WHERE id = ?");
                                            $stmt_token->execute([$remember_token, $token_expires, $row["id"]]);
                                        } catch (PDOException $e) {
                                            // Se falhar ao criar coluna, salvar sem expiração (compatibilidade)
                                            $stmt_token = $pdo->prepare("UPDATE usuarios SET remember_token = ? WHERE id = ?");
                                            $stmt_token->execute([$remember_token, $row["id"]]);
                                        }
                                    }
                                    
                                    // Cria cookies seguros
                                    $is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || 
                                               $_SERVER['SERVER_PORT'] == 443 ||
                                               (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
                                    
                                    $cookie_options = [
                                        'expires' => time() + (86400 * 30),
                                        'path' => '/',
                                        'domain' => '',
                                        'secure' => $is_https,
                                        'httponly' => true,
                                        'samesite' => 'Lax'
                                    ];
                                    
                                    if (PHP_VERSION_ID >= 70300) {
                                        setcookie('remember_token', $remember_token, $cookie_options);
                                        setcookie('remember_user', $usuario_input, array_merge($cookie_options, ['httponly' => false])); // remember_user precisa ser acessível via JS
                                    } else {
                                        setcookie('remember_token', $remember_token, $cookie_options['expires'], $cookie_options['path'], $cookie_options['domain'], $cookie_options['secure'], $cookie_options['httponly']);
                                        setcookie('remember_user', $usuario_input, $cookie_options['expires'], $cookie_options['path'], $cookie_options['domain'], $cookie_options['secure'], false);
                                    }
                                } catch (PDOException $e) {
                                    error_log("Erro ao salvar remember_token: " . $e->getMessage());
                                }
                            } else {
                                // Se desmarcado, remove o token do banco e os cookies
                                try {
                                    $stmt_remove = $pdo->prepare("UPDATE usuarios SET remember_token = NULL WHERE id = ?");
                                    $stmt_remove->execute([$row["id"]]);
                                } catch (PDOException $e) {
                                    error_log("Erro ao remover remember_token: " . $e->getMessage());
                                }
                                
                                if(isset($_COOKIE['remember_token'])) {
                                    setcookie('remember_token', "", time() - 3600, "/");
                                }
                                if(isset($_COOKIE['remember_user'])) {
                                    setcookie('remember_user', "", time() - 3600, "/");
                                }
                            }

                            // Limpa o buffer antes do redirecionamento
                            ob_end_clean();
                            
                            // Define modo de visualização padrão se não estiver definido
                            if (!isset($_SESSION['current_view_mode'])) {
                                if ($row["tipo"] == 'infoprodutor') {
                                    $_SESSION['current_view_mode'] = 'infoprodutor';
                                } elseif ($row["tipo"] == 'usuario') {
                                    $_SESSION['current_view_mode'] = 'member';
                                }
                            }
                            
                            // Redirecionamento baseado no tipo e modo de visualização
                            if ($row["tipo"] == 'admin') {
                                $_SESSION['is_infoprodutor'] = false;
                                header("location: /admin"); 
                            } elseif ($row["tipo"] == 'infoprodutor') {
                                $_SESSION['is_infoprodutor'] = true;
                                // Se current_view_mode for 'member', redireciona para área de membros
                                if (isset($_SESSION['current_view_mode']) && $_SESSION['current_view_mode'] === 'member') {
                                    header("location: /member_area_dashboard");
                                } else {
                                    header("location: /");
                                }
                            } else { 
                                // SEGURANÇA: Usuários tipo 'usuario' só podem acessar área de membros
                                // Não permite acesso ao painel de infoprodutor mesmo se current_view_mode estiver definido
                                $_SESSION['is_infoprodutor'] = false; 
                                $_SESSION['current_view_mode'] = 'member'; // Força modo member para usuários tipo 'usuario'
                                header("location: /member_area_dashboard");
                            }
                            exit();
                            
                        } else {
                            // Registra tentativa falha
                            record_failed_login($client_ip, $usuario_input);
                            $erro = "Credenciais incorretas.";
                        }
                    } else {
                        // Registra tentativa falha (email não existe)
                        record_failed_login($client_ip, $usuario_input);
                        $erro = "Credenciais incorretas.";
                    }
                } else {
                    $erro = "Erro no sistema. Tente novamente.";
                }
                unset($stmt);
            } else {
                $erro = "Erro ao preparar consulta. Por favor, tente novamente.";
            }
            } // Fecha else do rate check
        }
    } catch (PDOException $e) {
        // Log do erro para debug (não exibe detalhes ao usuário por segurança)
        error_log("Erro no login: " . $e->getMessage());
        $erro = "Erro ao processar login. Por favor, tente novamente mais tarde.";
    } catch (Exception $e) {
        // Log do erro para debug
        error_log("Erro geral no login: " . $e->getMessage());
        $erro = "Erro ao processar login. Por favor, tente novamente mais tarde.";
    }
}

// Limpa qualquer output indesejado antes de renderizar o HTML
ob_end_clean();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Acesso à Plataforma</title>
    <?php include __DIR__ . '/config/load_settings.php'; ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    
    <style> 
        body { 
            font-family: 'Plus Jakarta Sans', sans-serif; 
        } 
        
        .modern-input-group {
            position: relative;
            transition: all 0.3s ease;
        }
        
        .modern-input {
            width: 100%;
            padding: 1rem 1rem 1rem 3rem;
            background-color: #0f1419;
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 1rem;
            color: white;
            font-size: 0.95rem;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .modern-input:focus {
            outline: none;
            background-color: #1a1f24;
            border-color: var(--accent-primary);
            box-shadow: 0 4px 20px -2px color-mix(in srgb, var(--accent-primary) 15%, transparent);
            transform: translateY(-1px);
        }
        
        .modern-input::placeholder {
            color: #6b7280;
        }

        .input-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            transition: color 0.3s ease;
        }

        .modern-input:focus + .input-icon,
        .modern-input:focus ~ .input-icon {
            color: var(--accent-primary);
        }

        .btn-primary {
            background: var(--accent-primary);
            position: relative;
            overflow: hidden;
            z-index: 1;
        }
        
        .btn-primary::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: var(--accent-primary-hover);
            z-index: -1;
            transition: opacity 0.3s ease;
            opacity: 0;
        }

        .btn-primary:hover::before {
            opacity: 1;
        }

        @keyframes float-up {
            0% { opacity: 0; transform: translateY(40px) scale(0.9); }
            10% { opacity: 1; transform: translateY(0) scale(1); }
            90% { opacity: 1; transform: translateY(0) scale(1); }
            100% { opacity: 0; transform: translateY(-40px) scale(0.9); }
        }

        .notification-card {
            animation: float-up 4s ease-in-out forwards;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        .notification-card:hover {
            transform: translateY(-2px) scale(1.02);
            box-shadow: 
                0 12px 40px rgba(0, 0, 0, 0.4),
                inset 0 1px 0 rgba(255, 255, 255, 0.15),
                0 0 0 1px rgba(255, 255, 255, 0.1),
                0 0 20px color-mix(in srgb, var(--accent-primary) 20%, transparent);
        }
        
        .glass-effect {
            background: rgba(15, 20, 25, 0.7);
            backdrop-filter: blur(20px) saturate(180%);
            -webkit-backdrop-filter: blur(20px) saturate(180%);
            border: 1px solid rgba(255, 255, 255, 0.15);
            box-shadow: 
                0 8px 32px rgba(0, 0, 0, 0.3),
                inset 0 1px 0 rgba(255, 255, 255, 0.1),
                0 0 0 1px rgba(255, 255, 255, 0.05);
        }

        /* Checkbox customizado */
        .custom-checkbox input:checked + div {
            background-color: var(--accent-primary) !important;
            border-color: var(--accent-primary) !important;
        }
        .custom-checkbox input:checked + div svg {
            display: block;
        }
    </style>
</head>
<body class="min-h-screen" style="background-color: #07090d;">

    <div class="min-h-screen grid lg:grid-cols-2">
            
        <!-- Coluna da Esquerda -->
        <div class="hidden lg:flex relative flex-col justify-end p-12 overflow-hidden bg-slate-900" <?php echo !empty($login_image_url) ? 'style="background-image: url(' . htmlspecialchars($login_image_url) . '); background-size: cover; background-position: center;"' : ''; ?>>
            <?php if (!empty($login_image_url)): ?>
            <div class="absolute inset-0 bg-gradient-to-t from-black via-black/40 to-transparent"></div>
            <?php else: ?>
            <div class="absolute inset-0 z-0">
                <img src="https://img.freepik.com/fotos-premium/cabelo-encaracolado-de-jovem-feliz-sorrindo-e-rindo-ela-esta-feliz-em-estudio-isolado-com-solido-brilhante_39704-6416.jpg" 
                     class="w-full h-full object-cover opacity-90" 
                     alt="Background">
                <div class="absolute inset-0 bg-gradient-to-t from-black via-black/40 to-transparent"></div>
            </div>
            <?php endif; ?>
            
            <div id="notifications-wrapper" class="absolute inset-0 pointer-events-none z-20 p-8 flex flex-col justify-start items-start gap-4" style="padding-top: 8rem;"></div>

            <div class="relative z-20 mb-8 max-w-lg">
                <h1 class="text-5xl font-bold text-white mb-4 leading-tight">
                    Escale suas vendas <br>
                    <span class="text-transparent bg-clip-text" style="background-image: linear-gradient(to right, var(--accent-primary), color-mix(in srgb, var(--accent-primary) 60%, transparent));">sem limites.</span>
                </h1>
                <p class="text-gray-300 text-lg leading-relaxed">
                    Junte-se a milhares de empreendedores que faturam todos os dias com nossa tecnologia de alta performance.
                </p>
            </div>
        </div>

        <!-- Coluna da Direita -->
        <div class="flex items-center justify-center p-8" style="background-color: #07090d;">
            <div class="w-full max-w-[420px] space-y-8">
                
                <div class="text-center">
                    <div class="inline-flex justify-center mb-6 p-4 rounded-3xl mb-6">
                        <img src="<?php echo htmlspecialchars($logo_url); ?>" alt="Logo" class="w-auto h-16 object-contain">
                    </div>
                    <h2 class="text-3xl font-bold text-white tracking-tight">Bem-vindo de volta!</h2>
                    <p class="text-gray-400 mt-2">Acesse sua conta para gerenciar seu império.</p>
                </div>
                
                <?php if(!empty($erro)): ?>
                    <div class="bg-red-50 border border-red-100 text-red-600 px-4 py-3 rounded-xl flex items-center gap-3 animate-pulse" role="alert">
                        <i data-lucide="alert-circle" class="w-5 h-5 flex-shrink-0"></i>
                        <p class="text-sm font-medium"><?php echo htmlspecialchars($erro); ?></p>
                    </div>
                <?php endif; ?>

                <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" class="space-y-6">
                    
                    <div class="space-y-5">
                        <div class="modern-input-group">
                            <label for="usuario" class="block text-gray-300 text-sm font-bold mb-2 ml-1">Usuário</label>
                            <div class="relative">
                                <i data-lucide="user" class="input-icon w-5 h-5"></i>
                                <input type="text" name="usuario" id="usuario" 
                                       class="modern-input" 
                                       value="<?php echo htmlspecialchars($usuario_input); ?>" 
                                       required 
                                       placeholder="exemplo@email.com"
                                       autocomplete="username">
                            </div>
                        </div>

                        <div class="modern-input-group">
                            <label for="senha" class="block text-gray-300 text-sm font-bold mb-2 ml-1">Senha</label>
                            <div class="relative">
                                <i data-lucide="lock" class="input-icon w-5 h-5"></i>
                                <input type="password" name="senha" id="senha" 
                                       class="modern-input pr-12" 
                                       required 
                                       placeholder="••••••••"
                                       autocomplete="current-password">
                                <button type="button" id="toggle-password" 
                                        class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-300 transition-colors focus:outline-none"
                                        aria-label="Mostrar senha">
                                    <i data-lucide="eye" id="eye-icon" class="w-5 h-5"></i>
                                </button>
                            </div>
                        </div>

                        <!-- Checkbox "Lembrar-me" -->
                        <label class="custom-checkbox flex items-center gap-3 cursor-pointer group">
                            <div class="relative">
                                <input type="checkbox" name="remember" class="peer sr-only">
                                <div class="w-5 h-5 border-2 rounded-md transition-all duration-200 flex items-center justify-center" style="border-color: rgba(255, 255, 255, 0.1); background-color: #0f1419;" onmouseover="this.style.borderColor='var(--accent-primary)'" onmouseout="this.style.borderColor='rgba(255, 255, 255, 0.1)'">
                                    <svg class="w-3.5 h-3.5 text-white hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"></path></svg>
                                </div>
                            </div>
                            <span class="text-sm font-medium text-gray-400 group-hover:text-gray-300 select-none">Lembrar meu acesso</span>
                        </label>
                        
                        <!-- Link "Esqueci minha senha" -->
                        <div class="text-right">
                            <a href="/forgot_password" class="text-sm text-gray-400 hover:text-primary transition-colors duration-200 inline-flex items-center gap-1">
                                <i data-lucide="key" class="w-4 h-4"></i>
                                <span>Esqueci minha senha</span>
                            </a>
                        </div>
                    </div>

                    <button type="submit" class="btn-primary w-full text-white font-bold py-4 px-6 rounded-xl shadow-lg transform hover:-translate-y-0.5 transition-all duration-300 flex items-center justify-center gap-2 group" style="box-shadow: 0 10px 15px -3px color-mix(in srgb, var(--accent-primary) 30%, transparent), 0 4px 6px -2px color-mix(in srgb, var(--accent-primary) 20%, transparent);" onmouseover="this.style.boxShadow='0 10px 15px -3px color-mix(in srgb, var(--accent-primary) 40%, transparent), 0 4px 6px -2px color-mix(in srgb, var(--accent-primary) 30%, transparent)'" onmouseout="this.style.boxShadow='0 10px 15px -3px color-mix(in srgb, var(--accent-primary) 30%, transparent), 0 4px 6px -2px color-mix(in srgb, var(--accent-primary) 20%, transparent)'">
                        <span>Entrar</span>
                        <i data-lucide="arrow-right" class="w-5 h-5 group-hover:translate-x-1 transition-transform"></i>
                    </button>
                </form>
                
                <?php
                // Verificar se SaaS está habilitado para mostrar botão de cadastro
                if (file_exists(__DIR__ . '/saas/includes/saas_functions.php')) {
                    require_once __DIR__ . '/saas/includes/saas_functions.php';
                    if (function_exists('saas_enabled') && saas_enabled()):
                ?>
                    <div class="pt-6 border-t border-dark-border text-center">
                        <p class="text-gray-400 text-sm mb-3">
                            Ainda não tem uma conta?
                        </p>
                        <a href="/register" class="inline-block w-full px-6 py-3 bg-dark-elevated hover:bg-dark-card border border-dark-border hover:border-primary text-white font-semibold rounded-xl transition-all duration-300 flex items-center justify-center gap-2 group">
                            <i data-lucide="user-plus" class="w-5 h-5"></i>
                            <span>Criar Conta Grátis</span>
                        </a>
                    </div>
                <?php
                    endif;
                }
                ?>
            </div>
        </div>

    </div>

    <script>
        lucide.createIcons();
        
        // Toggle mostrar/ocultar senha
        const togglePassword = document.getElementById('toggle-password');
        const passwordInput = document.getElementById('senha');
        const eyeIcon = document.getElementById('eye-icon');
        
        if (togglePassword && passwordInput && eyeIcon) {
            togglePassword.addEventListener('click', function() {
                const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                passwordInput.setAttribute('type', type);
                
                // Alterna o ícone
                eyeIcon.setAttribute('data-lucide', type === 'password' ? 'eye' : 'eye-off');
                lucide.createIcons();
            });
        }

        const wrapper = document.getElementById('notifications-wrapper');
        const names = ['Gabriel S.', 'Amanda M.', 'Lucas R.', 'Beatriz C.', 'João P.', 'Fernanda L.'];
        const logoUrl = '<?php echo htmlspecialchars($logo_url); ?>';
        const actions = [
            { type: 'Venda Aprovada', icon: 'check-circle', color: 'text-green-500', valueRange: [47, 297] },
            { type: 'PIX Gerado', icon: 'qr-code', color: 'text-blue-500', valueRange: [97, 197] },
            { type: 'Venda Cartão', icon: 'credit-card', color: 'text-orange-500', valueRange: [147, 497] }
        ];

        function formatCurrency(value) {
            return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(value);
        }

        function createNotification() {
            if (wrapper.children.length > 3) wrapper.removeChild(wrapper.firstChild);

            const randomName = names[Math.floor(Math.random() * names.length)];
            const randomAction = actions[Math.floor(Math.random() * actions.length)];
            const randomValue = Math.floor(Math.random() * (randomAction.valueRange[1] - randomAction.valueRange[0]) + randomAction.valueRange[0]) + 0.90;

            // Obtém a cor primária para o gradiente
            const primaryColor = getComputedStyle(document.documentElement).getPropertyValue('--accent-primary').trim();
            const rgbMatch = primaryColor.match(/^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i);
            let rgbValues = '50, 231, 104'; // fallback
            if (rgbMatch) {
                rgbValues = `${parseInt(rgbMatch[1], 16)}, ${parseInt(rgbMatch[2], 16)}, ${parseInt(rgbMatch[3], 16)}`;
            }

            const notif = document.createElement('div');
            notif.className = 'notification-card glass-effect rounded-2xl p-4 flex items-center gap-4 w-72 transform transition-all shadow-xl';
            notif.style.borderLeft = '4px solid var(--accent-primary)';
            
            notif.innerHTML = `
                <div class="p-2 rounded-full flex-shrink-0" style="background: linear-gradient(135deg, rgba(${rgbValues}, 0.2), rgba(${rgbValues}, 0.1)); border: 1px solid rgba(${rgbValues}, 0.3);">
                    <img src="${logoUrl}" alt="Logo" class="w-8 h-8 object-contain">
                </div>
                <div class="flex-1 min-w-0">
                    <div class="flex justify-between items-start mb-1">
                        <p class="text-xs font-bold text-white truncate" style="color: var(--accent-primary);">${randomAction.type}</p>
                        <span class="text-[10px] text-gray-400 flex-shrink-0 ml-2">Agora</span>
                    </div>
                    <p class="text-sm font-extrabold text-white mt-0.5">${formatCurrency(randomValue)}</p>
                    <p class="text-[10px] text-gray-400 truncate">${randomName} acabou de comprar</p>
                </div>
            `;

            wrapper.appendChild(notif);

            setTimeout(() => {
                if(notif.parentNode === wrapper) wrapper.removeChild(notif);
            }, 4000);
        }

        function startNotificationLoop() {
            createNotification();
            const nextTime = Math.random() * 2000 + 1500;
            setTimeout(startNotificationLoop, nextTime);
        }

        if (window.innerWidth >= 1024) {
            setTimeout(startNotificationLoop, 1000);
        }
    </script>
</body>
</html>