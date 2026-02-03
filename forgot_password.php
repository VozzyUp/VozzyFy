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
    } else {
        header("location: /");
    }
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
            // Busca usuário (admin ou infoprodutor)
            $stmt = $pdo->prepare("SELECT id, usuario, nome, tipo FROM usuarios WHERE usuario = ? AND tipo IN ('admin', 'infoprodutor')");
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
        error_log("Erro na recuperação de senha: " . $e->getMessage());
        $mensagem = "Erro ao processar solicitação. Por favor, tente novamente mais tarde.";
        $tipo_mensagem = 'error';
    } catch (Exception $e) {
        error_log("Erro geral na recuperação de senha: " . $e->getMessage());
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
    <title>Recuperação de Senha - <?php echo htmlspecialchars($nome_plataforma ?? 'Starfy'); ?></title>
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
    </style>
</head>
<body class="min-h-screen" style="background-color: #07090d;">

    <div class="min-h-screen flex items-center justify-center p-8">
        <div class="w-full max-w-[420px] space-y-8">
            
            <div class="text-center">
                <div class="inline-flex justify-center mb-6 p-4 rounded-3xl mb-6">
                    <img src="<?php echo htmlspecialchars($logo_url); ?>" alt="Logo" class="w-auto h-16 object-contain">
                </div>
                <h2 class="text-3xl font-bold text-white tracking-tight">Recuperar Senha</h2>
                <p class="text-gray-400 mt-2">Informe seu e-mail para receber instruções de recuperação.</p>
            </div>
            
            <?php if(!empty($mensagem)): ?>
                <div class="<?php echo $tipo_mensagem === 'success' ? 'bg-green-50 border border-green-100 text-green-600' : 'bg-red-50 border border-red-100 text-red-600'; ?> px-4 py-3 rounded-xl flex items-center gap-3 animate-pulse" role="alert">
                    <i data-lucide="<?php echo $tipo_mensagem === 'success' ? 'check-circle' : 'alert-circle'; ?>" class="w-5 h-5 flex-shrink-0"></i>
                    <p class="text-sm font-medium"><?php echo htmlspecialchars($mensagem); ?></p>
                </div>
            <?php endif; ?>

            <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" class="space-y-6">
                
                <div class="space-y-5">
                    <div class="modern-input-group">
                        <label for="email" class="block text-gray-300 text-sm font-bold mb-2 ml-1">E-mail</label>
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

                <button type="submit" class="btn-primary w-full text-white font-bold py-4 px-6 rounded-xl shadow-lg transform hover:-translate-y-0.5 transition-all duration-300 flex items-center justify-center gap-2 group" style="box-shadow: 0 10px 15px -3px color-mix(in srgb, var(--accent-primary) 30%, transparent), 0 4px 6px -2px color-mix(in srgb, var(--accent-primary) 20%, transparent);" onmouseover="this.style.boxShadow='0 10px 15px -3px color-mix(in srgb, var(--accent-primary) 40%, transparent), 0 4px 6px -2px color-mix(in srgb, var(--accent-primary) 30%, transparent)'" onmouseout="this.style.boxShadow='0 10px 15px -3px color-mix(in srgb, var(--accent-primary) 30%, transparent), 0 4px 6px -2px color-mix(in srgb, var(--accent-primary) 20%, transparent)'">
                    <span>Enviar Instruções</span>
                    <i data-lucide="send" class="w-5 h-5 group-hover:translate-x-1 transition-transform"></i>
                </button>
            </form>
            
            <div class="pt-6 border-t border-dark-border text-center">
                <p class="text-gray-400 text-sm mb-3">
                    Lembrou sua senha?
                </p>
                <a href="/login" class="inline-block w-full px-6 py-3 bg-dark-elevated hover:bg-dark-card border border-dark-border hover:border-primary text-white font-semibold rounded-xl transition-all duration-300 flex items-center justify-center gap-2 group">
                    <i data-lucide="arrow-left" class="w-5 h-5"></i>
                    <span>Voltar ao Login</span>
                </a>
            </div>
        </div>
    </div>

    <script>
        lucide.createIcons();
    </script>
</body>
</html>

