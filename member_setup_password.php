<?php
// Inicia buffer de saída
ob_start();

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/helpers/password_setup_helper.php';
include __DIR__ . '/config/load_settings.php';

// Se o usuário já estiver logado, redireciona
if (isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true) {
    if (isset($_SESSION["tipo"]) && $_SESSION["tipo"] == 'usuario') {
        header("location: /member_area_dashboard");
    } else {
        header("location: /");
    }
    exit;
}

$token = $_GET['token'] ?? '';
$mensagem = '';
$tipo_mensagem = ''; // 'success' ou 'error'
$token_valido = false;
$user_data = null;

// Valida token
if (!empty($token)) {
    $user_data = validate_setup_token($token);
    if ($user_data) {
        $token_valido = true;
    } else {
        $mensagem = "Token inválido ou expirado. Por favor, entre em contato com o suporte.";
        $tipo_mensagem = 'error';
    }
} else {
    $mensagem = "Token não fornecido.";
    $tipo_mensagem = 'error';
}

// Processa criação de senha
if ($_SERVER["REQUEST_METHOD"] == "POST" && $token_valido) {
    try {
        $new_password = trim($_POST["senha"] ?? '');
        $confirm_password = trim($_POST["confirmar_senha"] ?? '');
        
        if (empty($new_password) || empty($confirm_password)) {
            $mensagem = "Por favor, preencha todos os campos.";
            $tipo_mensagem = 'error';
        } elseif (strlen($new_password) < 8) {
            $mensagem = "A senha deve ter no mínimo 8 caracteres.";
            $tipo_mensagem = 'error';
        } elseif ($new_password !== $confirm_password) {
            $mensagem = "As senhas não coincidem.";
            $tipo_mensagem = 'error';
        } else {
            // Cria senha
            $sucesso = setup_password($token, $new_password);
            
            if ($sucesso && $user_data) {
                // Login automático após criar senha
                $_SESSION["loggedin"] = true;
                $_SESSION["id"] = $user_data['id'];
                $_SESSION["usuario"] = $user_data['usuario'];
                $_SESSION["nome"] = $user_data['nome'];
                $_SESSION["tipo"] = $user_data['tipo'];
                $_SESSION['is_infoprodutor'] = false;
                $_SESSION['current_view_mode'] = 'member';
                
                // Redireciona imediatamente para área de membros
                ob_end_clean();
                header("location: /member_area_dashboard");
                exit;
            } else {
                $mensagem = "Erro ao criar senha. Por favor, tente novamente.";
                $tipo_mensagem = 'error';
            }
        }
    } catch (Exception $e) {
        error_log("Erro ao criar senha: " . $e->getMessage());
        $mensagem = "Erro ao processar solicitação. Por favor, tente novamente.";
        $tipo_mensagem = 'error';
    }
}

ob_end_clean();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Criar Senha - Área de Membros</title>
    <?php include __DIR__ . '/config/load_settings.php'; ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    
    <style> 
        body { 
            font-family: 'Plus Jakarta Sans', sans-serif; 
            background-color: #f8fafc;
        } 
        
        .modern-input-group {
            position: relative;
            transition: all 0.3s ease;
        }
        
        .modern-input {
            width: 100%;
            padding: 1rem 1rem 1rem 3rem;
            background-color: #f8fafc;
            border: 1px solid #e2e8f0;
            color: #1e293b;
            border-radius: 1rem;
            font-size: 0.95rem;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .modern-input:focus {
            outline: none;
            background-color: #ffffff;
            border-color: var(--accent-primary);
            box-shadow: 0 4px 20px -2px color-mix(in srgb, var(--accent-primary) 15%, transparent);
            transform: translateY(-1px);
        }
        
        .modern-input::placeholder {
            color: #94a3b8;
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
<body class="min-h-screen flex items-center justify-center p-8">
    <!-- Card para Membros -->
    <div class="w-full max-w-[450px] bg-white p-8 md:p-12 rounded-3xl shadow-2xl shadow-slate-200/50 border border-slate-100">
        
        <div class="text-center">
            <div class="inline-flex justify-center mb-6 p-4 rounded-3xl mb-6" style="background-color: color-mix(in srgb, var(--accent-primary) 10%, transparent);">
                <img src="<?php echo htmlspecialchars($logo_checkout_url ?? $logo_url); ?>" alt="Logo" class="w-auto h-12 object-contain">
            </div>
            <h2 class="text-2xl font-bold text-slate-900 tracking-tight">Criar Sua Senha</h2>
            <p class="text-slate-500 mt-2"><?php echo $token_valido ? 'Defina uma senha para acessar sua área de membros.' : 'Token inválido ou expirado.'; ?></p>
        </div>
        
        <?php if(!empty($mensagem)): ?>
            <div class="<?php echo $tipo_mensagem === 'success' ? 'bg-green-50 border-2 border-green-200 text-green-700' : 'bg-red-50 border-2 border-red-200 text-red-700'; ?> px-4 py-3 rounded-xl flex items-center gap-3 mt-6" role="alert">
                <i data-lucide="<?php echo $tipo_mensagem === 'success' ? 'check-circle' : 'alert-circle'; ?>" class="w-5 h-5 flex-shrink-0"></i>
                <p class="text-sm font-medium"><?php echo htmlspecialchars($mensagem); ?></p>
            </div>
        <?php endif; ?>

        <?php if ($token_valido && $tipo_mensagem !== 'success'): ?>
        <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>?token=<?php echo htmlspecialchars($token); ?>" method="post" class="space-y-6 mt-6">
            
            <div class="space-y-5">
                <div class="modern-input-group">
                    <label for="senha" class="block text-slate-700 text-sm font-bold mb-2 ml-1">Nova Senha</label>
                    <div class="relative">
                        <i data-lucide="lock" class="input-icon w-5 h-5"></i>
                        <input type="password" name="senha" id="senha" 
                               class="modern-input" 
                               required 
                               placeholder="Mínimo 8 caracteres"
                               autocomplete="new-password"
                               minlength="8">
                    </div>
                    <p class="text-xs text-slate-500 mt-1 ml-1">A senha deve ter no mínimo 8 caracteres.</p>
                </div>

                <div class="modern-input-group">
                    <label for="confirmar_senha" class="block text-slate-700 text-sm font-bold mb-2 ml-1">Confirmar Senha</label>
                    <div class="relative">
                        <i data-lucide="lock" class="input-icon w-5 h-5"></i>
                        <input type="password" name="confirmar_senha" id="confirmar_senha" 
                               class="modern-input" 
                               required 
                               placeholder="Digite a senha novamente"
                               autocomplete="new-password"
                               minlength="8">
                    </div>
                </div>
            </div>

            <button type="submit" class="btn-primary w-full text-white font-bold py-4 px-6 rounded-xl shadow-lg transform hover:-translate-y-0.5 transition-all duration-300 flex items-center justify-center gap-2 group mt-4" style="box-shadow: 0 10px 15px -3px color-mix(in srgb, var(--accent-primary) 30%, transparent), 0 4px 6px -2px color-mix(in srgb, var(--accent-primary) 20%, transparent);">
                <span>Criar Senha e Acessar</span>
                <i data-lucide="check" class="w-5 h-5"></i>
            </button>
        </form>
        <?php elseif (!$token_valido): ?>
        <div class="pt-6 border-t border-slate-200 text-center mt-6">
            <p class="text-slate-500 text-sm mb-3">
                Seu link de criação de senha está inválido ou expirado.
            </p>
            <a href="/member_login" class="inline-block w-full px-6 py-3 bg-slate-100 hover:bg-slate-200 border border-slate-200 hover:border-primary text-slate-700 font-semibold rounded-xl transition-all duration-300 flex items-center justify-center gap-2 group">
                <i data-lucide="arrow-left" class="w-5 h-5"></i>
                <span>Voltar ao Login</span>
            </a>
        </div>
        <?php endif; ?>
        
        <!-- Rodapé Minimalista -->
        <p class="text-center text-xs text-slate-400 mt-10">
            &copy; <?php echo date("Y"); ?> <?php echo htmlspecialchars($nome_plataforma ?? 'Starfy'); ?>. Todos os direitos reservados.
        </p>
    </div>

    <script>
        lucide.createIcons();
    </script>
</body>
</html>

