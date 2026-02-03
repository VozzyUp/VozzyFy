<?php
// Inicia buffer de saída
ob_start();

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/helpers/password_reset_helper.php';
include __DIR__ . '/config/load_settings.php';

// Se o usuário já estiver logado, redireciona
if (isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true) {
    if (isset($_SESSION["tipo"]) && $_SESSION["tipo"] == 'admin') {
        header("location: /admin");
    } elseif (isset($_SESSION["tipo"]) && $_SESSION["tipo"] == 'usuario') {
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
    $user_data = validate_reset_token($token);
    if ($user_data) {
        $token_valido = true;
    } else {
        $mensagem = "Token inválido ou expirado. Por favor, solicite uma nova recuperação de senha.";
        $tipo_mensagem = 'error';
    }
} else {
    $mensagem = "Token não fornecido.";
    $tipo_mensagem = 'error';
}

// Processa redefinição de senha
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
            // Redefine senha
            $sucesso = reset_password($token, $new_password);
            
            if ($sucesso) {
                $mensagem = "Senha redefinida com sucesso! Redirecionando para o login...";
                $tipo_mensagem = 'success';
                
                // Redireciona após 2 segundos
                $redirect_url = ($user_data['tipo'] === 'usuario') ? '/member_login' : '/login';
                header("refresh:2;url=" . $redirect_url);
            } else {
                $mensagem = "Erro ao redefinir senha. Por favor, tente novamente.";
                $tipo_mensagem = 'error';
            }
        }
    } catch (Exception $e) {
        error_log("Erro ao redefinir senha: " . $e->getMessage());
        $mensagem = "Erro ao processar solicitação. Por favor, tente novamente.";
        $tipo_mensagem = 'error';
    }
}

ob_end_clean();

// Determina estilo baseado no tipo de usuário
$is_member = ($user_data && $user_data['tipo'] === 'usuario');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Redefinir Senha - <?php echo htmlspecialchars($nome_plataforma ?? 'Starfy'); ?></title>
    <?php include __DIR__ . '/config/load_settings.php'; ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    
    <style> 
        body { 
            font-family: 'Plus Jakarta Sans', sans-serif; 
            <?php if ($is_member): ?>
            background-color: #f8fafc;
            <?php else: ?>
            background-color: #07090d;
            <?php endif; ?>
        } 
        
        .modern-input-group {
            position: relative;
            transition: all 0.3s ease;
        }
        
        .modern-input {
            width: 100%;
            padding: 1rem 1rem 1rem 3rem;
            <?php if ($is_member): ?>
            background-color: #f8fafc;
            border: 1px solid #e2e8f0;
            color: #1e293b;
            <?php else: ?>
            background-color: #0f1419;
            border: 1px solid rgba(255, 255, 255, 0.1);
            color: white;
            <?php endif; ?>
            border-radius: 1rem;
            font-size: 0.95rem;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .modern-input:focus {
            outline: none;
            <?php if ($is_member): ?>
            background-color: #ffffff;
            <?php else: ?>
            background-color: #1a1f24;
            <?php endif; ?>
            border-color: var(--accent-primary);
            box-shadow: 0 4px 20px -2px rgba(50, 231, 104, 0.15);
            transform: translateY(-1px);
        }
        
        .modern-input::placeholder {
            <?php if ($is_member): ?>
            color: #94a3b8;
            <?php else: ?>
            color: #6b7280;
            <?php endif; ?>
        }

        .input-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            <?php if ($is_member): ?>
            color: #94a3b8;
            <?php else: ?>
            color: #94a3b8;
            <?php endif; ?>
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

    <?php if ($is_member): ?>
    <!-- Card para Membros -->
    <div class="w-full max-w-[450px] bg-white p-8 md:p-12 rounded-3xl shadow-2xl shadow-slate-200/50 border border-slate-100">
    <?php else: ?>
    <!-- Card para Admin/Infoprodutor -->
    <div class="w-full max-w-[420px] space-y-8">
    <?php endif; ?>
        
        <div class="text-center">
            <div class="inline-flex justify-center mb-6 p-4 rounded-3xl mb-6 <?php echo $is_member ? 'style="background-color: rgba(50, 231, 104, 0.1);"' : ''; ?>">
                <img src="<?php echo htmlspecialchars($logo_url); ?>" alt="Logo" class="w-auto <?php echo $is_member ? 'h-12' : 'h-16'; ?> object-contain">
            </div>
            <h2 class="text-<?php echo $is_member ? '2xl' : '3xl'; ?> font-bold <?php echo $is_member ? 'text-slate-900' : 'text-white'; ?> tracking-tight">Redefinir Senha</h2>
            <p class="<?php echo $is_member ? 'text-slate-500' : 'text-gray-400'; ?> mt-2"><?php echo $token_valido ? 'Digite sua nova senha abaixo.' : 'Token inválido ou expirado.'; ?></p>
        </div>
        
        <?php if(!empty($mensagem)): ?>
            <div class="<?php echo $tipo_mensagem === 'success' ? ($is_member ? 'bg-green-50 border-2 border-green-200 text-green-700' : 'bg-green-50 border border-green-100 text-green-600') : ($is_member ? 'bg-red-50 border-2 border-red-200 text-red-700' : 'bg-red-50 border border-red-100 text-red-600'); ?> px-4 py-3 rounded-xl flex items-center gap-3" role="alert">
                <i data-lucide="<?php echo $tipo_mensagem === 'success' ? 'check-circle' : 'alert-circle'; ?>" class="w-5 h-5 flex-shrink-0"></i>
                <p class="text-sm font-medium"><?php echo htmlspecialchars($mensagem); ?></p>
            </div>
        <?php endif; ?>

        <?php if ($token_valido && $tipo_mensagem !== 'success'): ?>
        <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>?token=<?php echo htmlspecialchars($token); ?>" method="post" class="space-y-6">
            
            <div class="space-y-5">
                <div class="modern-input-group">
                    <label for="senha" class="block <?php echo $is_member ? 'text-slate-700' : 'text-gray-300'; ?> text-sm font-bold mb-2 ml-1">Nova Senha</label>
                    <div class="relative">
                        <i data-lucide="lock" class="input-icon w-5 h-5"></i>
                        <input type="password" name="senha" id="senha" 
                               class="modern-input" 
                               required 
                               placeholder="Mínimo 8 caracteres"
                               autocomplete="new-password"
                               minlength="8">
                    </div>
                    <p class="text-xs <?php echo $is_member ? 'text-slate-500' : 'text-gray-500'; ?> mt-1 ml-1">A senha deve ter no mínimo 8 caracteres.</p>
                </div>

                <div class="modern-input-group">
                    <label for="confirmar_senha" class="block <?php echo $is_member ? 'text-slate-700' : 'text-gray-300'; ?> text-sm font-bold mb-2 ml-1">Confirmar Nova Senha</label>
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

            <button type="submit" class="btn-primary w-full text-white font-bold py-4 px-6 rounded-xl shadow-lg transform hover:-translate-y-0.5 transition-all duration-300 flex items-center justify-center gap-2 group" style="box-shadow: 0 10px 15px -3px rgba(50, 231, 104, 0.3), 0 4px 6px -2px rgba(50, 231, 104, 0.2);">
                <span>Redefinir Senha</span>
                <i data-lucide="check" class="w-5 h-5"></i>
            </button>
        </form>
        <?php elseif (!$token_valido): ?>
        <div class="pt-6 border-t <?php echo $is_member ? 'border-slate-200' : 'border-dark-border'; ?> text-center">
            <p class="<?php echo $is_member ? 'text-slate-500' : 'text-gray-400'; ?> text-sm mb-3">
                Solicite uma nova recuperação de senha.
            </p>
            <a href="<?php echo $is_member ? '/member_forgot_password' : '/forgot_password'; ?>" class="inline-block w-full px-6 py-3 <?php echo $is_member ? 'bg-slate-100 hover:bg-slate-200 border border-slate-200 text-slate-700' : 'bg-dark-elevated hover:bg-dark-card border border-dark-border text-white'; ?> font-semibold rounded-xl transition-all duration-300 flex items-center justify-center gap-2 group">
                <i data-lucide="arrow-left" class="w-5 h-5"></i>
                <span>Voltar</span>
            </a>
        </div>
        <?php endif; ?>
        
        <?php if ($is_member): ?>
        <!-- Rodapé Minimalista -->
        <p class="text-center text-xs text-slate-400 mt-10">
            &copy; <?php echo date("Y"); ?> <?php echo htmlspecialchars($nome_plataforma ?? 'Starfy'); ?>. Todos os direitos reservados.
        </p>
        <?php endif; ?>
    </div>

    <script>
        lucide.createIcons();
    </script>
</body>
</html>

