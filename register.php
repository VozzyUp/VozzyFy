<?php
/**
 * Página de Registro - Sistema SaaS
 * Permite que novos infoprodutores se cadastrem na plataforma
 */

require_once __DIR__ . '/config/config.php';

// Carregar funções SaaS
if (file_exists(__DIR__ . '/saas/includes/saas_functions.php')) {
    require_once __DIR__ . '/saas/includes/saas_functions.php';
}

// Verificar se SaaS está habilitado
if (!function_exists('saas_enabled') || !saas_enabled()) {
    // Se SaaS não estiver habilitado, redireciona para login
    header("location: /login");
    exit;
}

// Se o usuário já estiver logado, redireciona para o painel dele
if (isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true) {
    if (isset($_SESSION["tipo"]) && $_SESSION["tipo"] == 'admin') {
        header("location: /admin");
    } else {
        header("location: /");
    }
    exit;
}

$nome = $email = $senha = $confirm_senha = '';
$nome_err = $email_err = $senha_err = $confirm_senha_err = '';
$cadastro_sucesso = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    // Valida nome
    if (empty(trim($_POST["nome"]))) {
        $nome_err = "Por favor, digite seu nome completo.";
    } else {
        $nome = trim($_POST["nome"]);
    }

    // Valida e-mail
    $existing_user = null;
    if (empty(trim($_POST["email"]))) {
        $email_err = "Por favor, digite seu e-mail.";
    } elseif (!filter_var(trim($_POST["email"]), FILTER_VALIDATE_EMAIL)) {
        $email_err = "Formato de e-mail inválido.";
    } else {
        $sql = "SELECT id, tipo, senha FROM usuarios WHERE usuario = :email";
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(":email", $param_email, PDO::PARAM_STR);
        $param_email = trim($_POST["email"]);
        if ($stmt->execute()) {
            if ($stmt->rowCount() == 1) {
                $existing_user = $stmt->fetch(PDO::FETCH_ASSOC);
                // Se já existe como infoprodutor ou admin, mostrar erro
                if ($existing_user['tipo'] == 'infoprodutor' || $existing_user['tipo'] == 'admin') {
                    $email_err = "Este e-mail já está em uso.";
                }
                // Se existe como 'usuario' (cliente final), vamos verificar a senha depois
                // e atualizar o tipo para infoprodutor
            } else {
                $email = trim($_POST["email"]);
            }
        } else {
            $email_err = "Erro ao verificar e-mail. Tente novamente.";
        }
        unset($stmt);
    }

    // Valida senha
    if (empty(trim($_POST["senha"]))) {
        $senha_err = "Por favor, digite uma senha.";
    } elseif (strlen(trim($_POST["senha"])) < 6) {
        $senha_err = "A senha deve ter pelo menos 6 caracteres.";
    } else {
        $senha = trim($_POST["senha"]);
    }

    // Valida confirmação
    if (empty(trim($_POST["confirm_senha"]))) {
        $confirm_senha_err = "Por favor, confirme a senha.";
    } else {
        $confirm_senha = trim($_POST["confirm_senha"]);
        if (empty($senha_err) && ($senha != $confirm_senha)) {
            $confirm_senha_err = "As senhas não coincidem.";
        }
    }

    // Insere no banco ou atualiza tipo se já existe como cliente final
    if (empty($nome_err) && empty($email_err) && empty($senha_err) && empty($confirm_senha_err)) {
        // Se existe como cliente final (usuario), verificar senha e atualizar
        if ($existing_user && $existing_user['tipo'] == 'usuario') {
            // Verificar se a senha está correta
            if (password_verify($senha, $existing_user['senha'])) {
                // Atualizar tipo para infoprodutor
                $sql_update = "UPDATE usuarios SET tipo = 'infoprodutor', nome = :nome WHERE id = :id";
                $stmt_update = $pdo->prepare($sql_update);
                $stmt_update->bindParam(":nome", $param_nome, PDO::PARAM_STR);
                $stmt_update->bindParam(":id", $existing_user['id'], PDO::PARAM_INT);
                $param_nome = $nome;
                
                if ($stmt_update->execute()) {
                    $user_id = $existing_user['id'];
                    
                    // Atribuir plano free automaticamente (se SaaS estiver habilitado e plano existir)
                    if (function_exists('saas_enabled') && function_exists('saas_assign_free_plan')) {
                        if (saas_enabled()) {
                            saas_assign_free_plan($user_id);
                        }
                    }
                    
                    // Fazer login automático
                    $_SESSION["loggedin"] = true;
                    $_SESSION["id"] = $user_id;
                    $_SESSION["usuario"] = $email;
                    $_SESSION["nome"] = $nome;
                    $_SESSION["tipo"] = 'infoprodutor';
                    $_SESSION['is_infoprodutor'] = true;
                    $_SESSION['current_view_mode'] = 'infoprodutor';
                    
                    // Redirecionar para o painel do infoprodutor
                    header("location: /");
                    exit;
                } else {
                    $email_err = "Erro ao atualizar conta. Tente novamente.";
                }
                unset($stmt_update);
            } else {
                // Senha incorreta
                $senha_err = "Senha incorreta. Se você já tem uma conta como cliente, use a senha correta para atualizar para infoprodutor.";
            }
        } else {
            // Criar novo usuário
            $sql = "INSERT INTO usuarios (nome, usuario, senha, tipo) VALUES (:nome, :usuario, :senha, 'infoprodutor')";

            if ($stmt = $pdo->prepare($sql)) {
                $stmt->bindParam(":nome", $param_nome, PDO::PARAM_STR);
                $stmt->bindParam(":usuario", $param_usuario, PDO::PARAM_STR);
                $stmt->bindParam(":senha", $param_senha, PDO::PARAM_STR);

                $param_nome = $nome;
                $param_usuario = $email;
                $param_senha = password_hash($senha, PASSWORD_DEFAULT);

                if ($stmt->execute()) {
                    $user_id = $pdo->lastInsertId();
                    
                    // Atribuir plano free automaticamente (se SaaS estiver habilitado e plano existir)
                    if (function_exists('saas_enabled') && function_exists('saas_assign_free_plan')) {
                        if (saas_enabled()) {
                            saas_assign_free_plan($user_id);
                        }
                    }
                    
                    // Fazer login automático
                    $_SESSION["loggedin"] = true;
                    $_SESSION["id"] = $user_id;
                    $_SESSION["usuario"] = $email;
                    $_SESSION["nome"] = $nome;
                    $_SESSION["tipo"] = 'infoprodutor';
                    $_SESSION['is_infoprodutor'] = true;
                    $_SESSION['current_view_mode'] = 'infoprodutor';
                    
                    // Redirecionar para o painel do infoprodutor
                    header("location: /");
                    exit;
                } else {
                    $email_err = "Erro ao criar conta. Tente novamente.";
                }
                unset($stmt);
            }
        }
    }
    // Não remover $pdo aqui, pois load_settings.php precisa dele
}

// Carregar configurações do sistema (deve ser após config.php ter inicializado $pdo)
include __DIR__ . '/config/load_settings.php';
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Criar Conta - Comece Agora</title>
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
            box-shadow: 0 4px 20px -2px rgba(50, 231, 104, 0.15);
            transform: translateY(-1px);
        }
        
        .modern-input.error {
            border-color: #ef4444;
            background-color: #1a0f0f;
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
            
            <div class="relative z-20 mb-8 max-w-lg">
                <h1 class="text-5xl font-bold text-white mb-4 leading-tight">
                    Comece a vender <br>
                    <span class="text-transparent bg-clip-text" style="background-image: linear-gradient(to right, var(--accent-primary), rgba(50, 231, 104, 0.6));">sem limites.</span>
                </h1>
                <p class="text-gray-300 text-lg leading-relaxed">
                    Crie sua conta gratuitamente e descubra porque somos a escolha número 1 dos infoprodutores.
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
                    <h2 class="text-3xl font-bold text-white tracking-tight">Crie sua Conta</h2>
                    <p class="text-gray-400 mt-2">Preencha os dados abaixo para começar.</p>
                </div>
                
                <?php if(!empty($cadastro_sucesso)): ?>
                    <div class="bg-green-900/20 border border-green-500 text-green-300 px-4 py-3 rounded-xl flex items-center gap-3" role="alert">
                        <i data-lucide="check-circle" class="w-5 h-5 flex-shrink-0"></i>
                        <p class="text-sm font-medium"><?php echo htmlspecialchars($cadastro_sucesso); ?></p>
                    </div>
                <?php endif; ?>

                <?php if((!empty($nome_err) || !empty($email_err) || !empty($senha_err) || !empty($confirm_senha_err)) && empty($cadastro_sucesso)): ?>
                    <div class="bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded-xl flex items-center gap-3">
                        <i data-lucide="alert-circle" class="w-5 h-5 flex-shrink-0"></i>
                        <div class="text-sm font-medium">
                            Verifique os campos destacados abaixo.
                        </div>
                    </div>
                <?php endif; ?>

                <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" class="space-y-6">
                    
                    <div class="space-y-5">
                        <div class="modern-input-group">
                            <label for="nome" class="block text-gray-300 text-sm font-bold mb-2 ml-1">Nome Completo</label>
                            <div class="relative">
                                <i data-lucide="user" class="input-icon w-5 h-5"></i>
                                <input type="text" name="nome" id="nome" 
                                       class="modern-input <?php echo (!empty($nome_err)) ? 'error' : ''; ?>" 
                                       value="<?php echo htmlspecialchars($nome); ?>" 
                                       required 
                                       placeholder="Seu nome completo"
                                       autocomplete="name">
                            </div>
                            <?php if(!empty($nome_err)): ?>
                                <p class="text-red-400 text-xs mt-1 ml-1"><?php echo $nome_err; ?></p>
                            <?php endif; ?>
                        </div>

                        <div class="modern-input-group">
                            <label for="email" class="block text-gray-300 text-sm font-bold mb-2 ml-1">E-mail</label>
                            <div class="relative">
                                <i data-lucide="mail" class="input-icon w-5 h-5"></i>
                                <input type="email" name="email" id="email" 
                                       class="modern-input <?php echo (!empty($email_err)) ? 'error' : ''; ?>" 
                                       value="<?php echo htmlspecialchars($email); ?>" 
                                       required 
                                       placeholder="seuemail@exemplo.com"
                                       autocomplete="email">
                            </div>
                            <?php if(!empty($email_err)): ?>
                                <p class="text-red-400 text-xs mt-1 ml-1"><?php echo $email_err; ?></p>
                            <?php endif; ?>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div class="modern-input-group">
                                <label for="senha" class="block text-gray-300 text-sm font-bold mb-2 ml-1">Senha</label>
                                <div class="relative">
                                    <i data-lucide="lock" class="input-icon w-5 h-5"></i>
                                    <input type="password" name="senha" id="senha" 
                                           class="modern-input <?php echo (!empty($senha_err)) ? 'error' : ''; ?>" 
                                           required 
                                           placeholder="Mínimo 6 caracteres"
                                           autocomplete="new-password">
                                </div>
                                <?php if(!empty($senha_err)): ?>
                                    <p class="text-red-400 text-xs mt-1 ml-1"><?php echo $senha_err; ?></p>
                                <?php endif; ?>
                            </div>

                            <div class="modern-input-group">
                                <label for="confirm_senha" class="block text-gray-300 text-sm font-bold mb-2 ml-1">Confirmar</label>
                                <div class="relative">
                                    <i data-lucide="lock-keyhole" class="input-icon w-5 h-5"></i>
                                    <input type="password" name="confirm_senha" id="confirm_senha" 
                                           class="modern-input <?php echo (!empty($confirm_senha_err)) ? 'error' : ''; ?>" 
                                           required 
                                           placeholder="Repita a senha"
                                           autocomplete="new-password">
                                </div>
                                <?php if(!empty($confirm_senha_err)): ?>
                                    <p class="text-red-400 text-xs mt-1 ml-1"><?php echo $confirm_senha_err; ?></p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="btn-primary w-full text-white font-bold py-4 px-6 rounded-xl shadow-lg transform hover:-translate-y-0.5 transition-all duration-300 flex items-center justify-center gap-2 group">
                        <span>Criar Conta Grátis</span>
                        <i data-lucide="arrow-right" class="w-5 h-5 group-hover:translate-x-1 transition-transform"></i>
                    </button>
                </form>
                
                <div class="pt-6 border-t border-dark-border text-center">
                    <p class="text-gray-400 text-sm">
                        Já tem uma conta? 
                        <a href="/login" class="text-primary font-semibold hover:text-primary/80 transition-colors">Fazer Login</a>
                    </p>
                </div>
            </div>
        </div>

    </div>

    <script>
        lucide.createIcons();
    </script>
</body>
</html>


