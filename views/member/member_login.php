<?php
// Inicia buffer de saída para evitar qualquer output antes do HTML
ob_start();

// Inclui o arquivo de configuração que inicia a sessão com session_start() e a conexão PDO.
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../helpers/security_helper.php';

// Se o usuário já estiver logado, redireciona para o painel apropriado.
if (isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true) {
    if (isset($_SESSION["tipo"]) && $_SESSION["tipo"] == 'admin') {
        header("location: /admin"); 
    } else { 
        header("location: /member_area_dashboard"); 
    }
    exit;
}

$erro = '';
$email_input = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    try {
        if (empty(trim($_POST["email"] ?? '')) || empty(trim($_POST["senha"] ?? ''))) {
            $erro = "Por favor, preencha o e-mail e a senha.";
        } else {
            $email_input = trim($_POST["email"]);
            $senha_input = trim($_POST["senha"]);
            
            // Verifica rate limiting ANTES de processar login
            $client_ip = get_client_ip();
            $rate_check = check_login_attempts($client_ip, $email_input);
            
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

            // Seleciona o usuário no banco de dados (permite infoprodutor e usuario, mas não admin)
            $sql = "SELECT id, usuario, nome, senha, tipo FROM usuarios WHERE usuario = :email AND tipo != 'admin'";
            
            $stmt = $pdo->prepare($sql);
            if ($stmt) {
                $stmt->bindParam(":email", $email_input, PDO::PARAM_STR);
                
                if ($stmt->execute()) {
                    if ($stmt->rowCount() == 1) {
                        $row = $stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($row && isset($row["senha"]) && password_verify($senha_input, $row["senha"])) {
                            // Limpa tentativas de login após login bem-sucedido
                            clear_login_attempts($client_ip, $email_input);
                            
                            $_SESSION["loggedin"] = true;
                            $_SESSION["id"] = $row["id"];
                            $_SESSION["usuario"] = $row["usuario"]; 
                            $_SESSION["nome"] = $row["nome"]; 
                            $_SESSION["tipo"] = $row["tipo"];
                            $_SESSION['is_infoprodutor'] = ($row["tipo"] == 'infoprodutor');
                            $_SESSION['current_view_mode'] = 'member';
                            
                            // Limpa o buffer antes do redirecionamento
                            ob_end_clean();
                            header("location: /member_area_dashboard");
                            exit();
                        } else {
                            // Registra tentativa falha
                            record_failed_login($client_ip, $email_input);
                            $erro = "E-mail ou senha inválidos.";
                        }
                    } else {
                        // Registra tentativa falha (email não existe ou é admin)
                        record_failed_login($client_ip, $email_input);
                        $erro = "E-mail ou senha inválidos.";
                    }
                } else {
                    $erro = "Oops! Algo deu errado. Por favor, tente novamente mais tarde.";
                }
                unset($stmt);
            } else {
                $erro = "Erro ao preparar consulta. Por favor, tente novamente.";
            }
            } // Fecha else do rate check
        }
    } catch (PDOException $e) {
        // Log do erro para debug (não exibe detalhes ao usuário por segurança)
        error_log("Erro no login de membros: " . $e->getMessage());
        $erro = "Erro ao processar login. Por favor, tente novamente mais tarde.";
    } catch (Exception $e) {
        // Log do erro para debug
        error_log("Erro geral no login de membros: " . $e->getMessage());
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
    <title>Acesso à Área de Membros</title>
    <?php include __DIR__ . '/../../config/load_settings.php'; ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    
    <style> 
        body { 
            font-family: 'Plus Jakarta Sans', sans-serif; 
            background-color: #f8fafc; /* Slate 50 background global */
        } 
        
        /* Estilos dos Inputs Modernos (Mesma pegada do login principal) */
        .modern-input-group {
            position: relative;
            transition: all 0.3s ease;
        }
        
        .modern-input {
            width: 100%;
            padding: 1rem 1rem 1rem 3rem; /* Padding maior para conforto */
            background-color: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 1rem; /* Arredondado */
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

        /* Botão Gradiente */
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
            <div class="inline-flex justify-center p-4 rounded-3xl mb-6">
                 <img src="<?php echo htmlspecialchars($logo_checkout_url); ?>" alt="Logotipo da Starfy" class="w-auto h-12 object-contain">
            </div>
            <h2 class="text-2xl font-bold text-slate-900">Área de Membros</h2>
            <p class="text-slate-500 mt-2 text-sm">Acesse seus cursos e conteúdos exclusivos.</p>
        </div>
        
        <?php if(!empty($erro)): ?>
            <div class="bg-red-50 border-2 border-red-200 text-red-700 px-4 py-3 rounded-xl flex items-center gap-3 mb-6 shadow-sm" role="alert">
                <i data-lucide="alert-circle" class="w-5 h-5 flex-shrink-0 text-red-600"></i>
                <p class="text-sm font-semibold"><?php echo htmlspecialchars($erro); ?></p>
            </div>
        <?php endif; ?>

        <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" class="space-y-6">
            
            <div class="space-y-5">
                <!-- Campo E-mail -->
                <div class="modern-input-group">
                    <label for="email" class="block text-slate-700 text-sm font-bold mb-2 ml-1">Seu E-mail</label>
                    <div class="relative">
                        <i data-lucide="mail" class="input-icon w-5 h-5"></i>
                        <input type="email" name="email" id="email" 
                               class="modern-input" 
                               value="<?php echo htmlspecialchars($email_input); ?>" 
                               required 
                               placeholder="seuemail@exemplo.com">
                    </div>
                </div>

                <!-- Campo Senha -->
                <div class="modern-input-group">
                    <label for="senha" class="block text-slate-700 text-sm font-bold mb-2 ml-1">Sua Senha</label>
                    <div class="relative">
                        <i data-lucide="lock" class="input-icon w-5 h-5"></i>
                        <input type="password" name="senha" id="senha" 
                               class="modern-input" 
                               required 
                               placeholder="••••••••">
                    </div>
                </div>
            </div>

            <button type="submit" class="btn-primary w-full text-white font-bold py-4 px-6 rounded-xl shadow-lg transform hover:-translate-y-0.5 transition-all duration-300 flex items-center justify-center gap-2 group mt-4" style="box-shadow: 0 10px 15px -3px color-mix(in srgb, var(--accent-primary) 30%, transparent), 0 4px 6px -2px color-mix(in srgb, var(--accent-primary) 20%, transparent);" onmouseover="this.style.boxShadow='0 10px 15px -3px color-mix(in srgb, var(--accent-primary) 40%, transparent), 0 4px 6px -2px color-mix(in srgb, var(--accent-primary) 30%, transparent)'" onmouseout="this.style.boxShadow='0 10px 15px -3px color-mix(in srgb, var(--accent-primary) 30%, transparent), 0 4px 6px -2px color-mix(in srgb, var(--accent-primary) 20%, transparent)'">
                 <i data-lucide="log-in" class="w-5 h-5"></i>
                <span>Acessar Área de Membros</span>
            </button>
        </form>
        
        <!-- Link "Esqueci minha senha" -->
        <div class="text-center mt-4">
            <a href="/member_forgot_password" class="text-sm text-slate-500 hover:text-primary transition-colors duration-200 inline-flex items-center gap-1">
                <i data-lucide="key" class="w-4 h-4"></i>
                <span>Esqueci minha senha</span>
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