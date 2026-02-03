<?php
// Inicia buffer de saída
ob_start();

require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/helpers/password_reset_helper.php';
include __DIR__ . '/config/load_settings.php';

// Se o usuário já estiver logado, redireciona
if (isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true) {
    header("location: /member_area_dashboard");
    exit;
}

$mensagem = '';
$tipo_mensagem = ''; // 'success' ou 'error'
$email_input = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    try {
        $email_input = trim($_POST["email"] ?? '');
        
        if (empty($email_input)) {
            $mensagem = "Por favor, informe seu e-mail.";
            $tipo_mensagem = 'error';
        } elseif (!filter_var($email_input, FILTER_VALIDATE_EMAIL)) {
            $mensagem = "E-mail inválido.";
            $tipo_mensagem = 'error';
        } else {
            // Busca usuário (tipo 'usuario')
            $stmt = $pdo->prepare("SELECT id, usuario, nome, tipo FROM usuarios WHERE usuario = ? AND tipo = 'usuario'");
            $stmt->execute([$email_input]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Sempre mostra mensagem de sucesso (por segurança, não revela se email existe)
            if ($user) {
                // Gera token
                $token = generate_reset_token($user['id']);
                
                if ($token) {
                    // Envia email
                    $email_enviado = send_reset_email($user['usuario'], $token, $user['tipo']);
                    
                    if ($email_enviado) {
                        $mensagem = "Instruções para recuperação de senha foram enviadas para seu e-mail.";
                        $tipo_mensagem = 'success';
                        $email_input = ''; // Limpa campo após sucesso
                    } else {
                        $mensagem = "Erro ao enviar e-mail. Por favor, tente novamente mais tarde.";
                        $tipo_mensagem = 'error';
                    }
                } else {
                    $mensagem = "Erro ao gerar token de recuperação. Por favor, tente novamente.";
                    $tipo_mensagem = 'error';
                }
            } else {
                // Mesmo se não encontrar, mostra mensagem de sucesso (segurança)
                $mensagem = "Se o e-mail estiver cadastrado, você receberá instruções para recuperação de senha.";
                $tipo_mensagem = 'success';
                $email_input = '';
            }
        }
    } catch (PDOException $e) {
        error_log("Erro na recuperação de senha (membros): " . $e->getMessage());
        $mensagem = "Erro ao processar solicitação. Por favor, tente novamente mais tarde.";
        $tipo_mensagem = 'error';
    } catch (Exception $e) {
        error_log("Erro geral na recuperação de senha (membros): " . $e->getMessage());
        $mensagem = "Erro ao processar solicitação. Por favor, tente novamente mais tarde.";
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
    <title>Recuperação de Senha - Área de Membros</title>
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
            border-radius: 1rem;
            color: #1e293b;
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
<body class="min-h-screen flex items-center justify-center p-4">

    <!-- Card Centralizado -->
    <div class="w-full max-w-[450px] bg-white p-8 md:p-12 rounded-3xl shadow-2xl shadow-slate-200/50 border border-slate-100">
        
        <div class="text-center mb-10">
            <div class="inline-flex justify-center p-4 rounded-3xl mb-6" style="background-color: color-mix(in srgb, var(--accent-primary) 10%, transparent);">
                 <img src="<?php echo htmlspecialchars($logo_url); ?>" alt="Logotipo" class="w-auto h-12 object-contain">
            </div>
            <h2 class="text-2xl font-bold text-slate-900">Recuperar Senha</h2>
            <p class="text-slate-500 mt-2 text-sm">Informe seu e-mail para receber instruções de recuperação.</p>
        </div>
        
        <?php if(!empty($mensagem)): ?>
            <div class="<?php echo $tipo_mensagem === 'success' ? 'bg-green-50 border-2 border-green-200 text-green-700' : 'bg-red-50 border-2 border-red-200 text-red-700'; ?> px-4 py-3 rounded-xl flex items-center gap-3 mb-6 shadow-sm" role="alert">
                <i data-lucide="<?php echo $tipo_mensagem === 'success' ? 'check-circle' : 'alert-circle'; ?>" class="w-5 h-5 flex-shrink-0 <?php echo $tipo_mensagem === 'success' ? 'text-green-600' : 'text-red-600'; ?>"></i>
                <p class="text-sm font-semibold"><?php echo htmlspecialchars($mensagem); ?></p>
            </div>
        <?php endif; ?>

        <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" class="space-y-6">
            
            <div class="space-y-5">
                <div class="modern-input-group">
                    <label for="email" class="block text-slate-700 text-sm font-bold mb-2 ml-1">Seu E-mail</label>
                    <div class="relative">
                        <i data-lucide="mail" class="input-icon w-5 h-5"></i>
                        <input type="email" name="email" id="email" 
                               class="modern-input" 
                               value="<?php echo htmlspecialchars($email_input); ?>" 
                               required 
                               placeholder="seuemail@exemplo.com"
                               autocomplete="email">
                    </div>
                </div>
            </div>

            <button type="submit" class="btn-primary w-full text-white font-bold py-4 px-6 rounded-xl shadow-lg transform hover:-translate-y-0.5 transition-all duration-300 flex items-center justify-center gap-2 group mt-4" style="box-shadow: 0 10px 15px -3px color-mix(in srgb, var(--accent-primary) 30%, transparent), 0 4px 6px -2px color-mix(in srgb, var(--accent-primary) 20%, transparent);" onmouseover="this.style.boxShadow='0 10px 15px -3px color-mix(in srgb, var(--accent-primary) 40%, transparent), 0 4px 6px -2px color-mix(in srgb, var(--accent-primary) 30%, transparent)'" onmouseout="this.style.boxShadow='0 10px 15px -3px color-mix(in srgb, var(--accent-primary) 30%, transparent), 0 4px 6px -2px color-mix(in srgb, var(--accent-primary) 20%, transparent)'">
                <i data-lucide="send" class="w-5 h-5"></i>
                <span>Enviar Instruções</span>
            </button>
        </form>
        
        <div class="pt-6 border-t border-slate-200 text-center">
            <p class="text-slate-500 text-sm mb-3">
                Lembrou sua senha?
            </p>
            <a href="/member_login" class="inline-block w-full px-6 py-3 bg-slate-100 hover:bg-slate-200 border border-slate-200 hover:border-primary text-slate-700 font-semibold rounded-xl transition-all duration-300 flex items-center justify-center gap-2 group">
                <i data-lucide="arrow-left" class="w-5 h-5"></i>
                <span>Voltar ao Login</span>
            </a>
        </div>
        
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

