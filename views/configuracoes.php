<?php
// Inclui o arquivo de configuração que inicia a sessão e a conexão PDO
require_once __DIR__ . '/../config/config.php';

// Proteção de página: verifica se o usuário está logado
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: /login");
    exit;
}

$mensagem = '';
$usuario_id_logado = $_SESSION['id']; // ID do usuário logado
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configurações</title>
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Lucide Icons -->
    <script src="https://unpkg.com/lucide@latest"></script>
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <style>
        body { 
            font-family: 'Plus Jakarta Sans', sans-serif; 
            background-color: #07090d; /* Dark base */
        }
        
        /* Animações e Efeitos */
        .card-hover { transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); }
        .card-hover:hover { transform: translateY(-4px); box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04); }
        
        .selected-ring { 
            ring-width: 2px; 
            ring-color: var(--accent-primary);
            background-color: color-mix(in srgb, var(--accent-primary) 10%, transparent);
            border-color: var(--accent-primary);
        }

        .animate-fade-in { animation: fadeIn 0.4s ease-out forwards; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        
        /* Input Styles */
        .custom-input:focus-within { box-shadow: 0 0 0 4px color-mix(in srgb, var(--accent-primary) 10%, transparent); border-color: var(--accent-primary); }
    </style>
</head>
<body class="min-h-screen text-white pb-20">

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
        
        <!-- Header -->
        <div class="flex flex-col md:flex-row justify-between items-start md:items-center mb-12 gap-4">
            <div>
                <h1 class="text-3xl md:text-4xl font-extrabold tracking-tight text-white">
                    Configurações
                </h1>
                <p class="mt-2 text-gray-400 text-lg">Gerencie as configurações da sua plataforma.</p>
            </div>
        </div>

        <!-- Mensagem informativa -->
        <div class="bg-blue-900/20 border border-blue-500/50 rounded-2xl p-6 mb-8">
            <div class="flex items-start gap-4">
                <div class="h-12 w-12 rounded-xl bg-blue-900/30 flex items-center justify-center text-blue-400 border border-blue-500/30 flex-shrink-0">
                    <i data-lucide="info" class="w-6 h-6"></i>
                </div>
                <div>
                    <h3 class="text-xl font-bold text-white mb-2">Configurações de Gateway Movidas</h3>
                    <p class="text-gray-300 leading-relaxed">
                        As configurações de <strong>Mercado Pago</strong> e <strong>PushinPay</strong> foram movidas para a seção de <strong>Integrações</strong>.
                        <a href="/index?pagina=integracoes" class="font-semibold underline ml-1" style="color: var(--accent-primary);" onmouseover="this.style.color='var(--accent-primary-hover)'" onmouseout="this.style.color='var(--accent-primary)'">Clique aqui para acessar</a>.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            if (typeof lucide !== 'undefined') lucide.createIcons();
        });
    </script>
</body>
</html>