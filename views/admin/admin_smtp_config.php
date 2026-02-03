<?php
// admin.php já inclui config.php e lida com a sessão e verificação de admin.
// Este arquivo agora se torna uma interface cliente-lado para a admin_api.php

// Não há mais lógica de POST ou fetch direto do DB neste arquivo PHP.
// Toda interação com o backend será via AJAX.
// Apenas preparamos o HTML.

// Incluir helper de segurança para funções CSRF
require_once __DIR__ . '/../../helpers/security_helper.php';

// Gerar token CSRF para uso em requisições JavaScript
$csrf_token_js = generate_csrf_token();
?>

<meta name="csrf-token" content="<?php echo htmlspecialchars($csrf_token_js); ?>">
<script>
    // Variável global para token CSRF
    window.csrfToken = '<?php echo htmlspecialchars($csrf_token_js); ?>';
</script>

<div class="flex items-center justify-between mb-6">
    <div>
        <h1 class="text-3xl font-bold text-white">Configurações de E-mail</h1>
        <p class="text-gray-400 mt-1">Configure o serviço de e-mail, modelos de entrega e recuperação de carrinho.</p>
    </div>
    <a href="/admin?pagina=admin_dashboard" class="bg-dark-elevated text-gray-300 font-bold py-2 px-4 rounded-lg hover:bg-dark-card transition duration-300 flex items-center space-x-2 border border-dark-border">
        <i data-lucide="arrow-left" class="w-5 h-5"></i>
        <span>Voltar ao Dashboard</span>
    </a>
</div>

<div id="status-message" class="hidden bg-blue-900/20 border border-blue-500/30 text-blue-300 px-4 py-3 rounded relative mb-4" role="alert"></div>

<!-- Sistema de Abas -->
<div class="bg-dark-card rounded-lg shadow-md border border-dark-border mb-6">
    <div class="flex border-b border-dark-border">
        <button type="button" id="tab-smtp" class="tab-button active px-6 py-4 text-sm font-semibold text-white border-b-2 border-transparent hover:border-gray-400 transition-colors" data-tab="smtp">
            <i data-lucide="server" class="w-4 h-4 inline-block mr-2"></i>
            Servidor SMTP
        </button>
        <button type="button" id="tab-delivery" class="tab-button px-6 py-4 text-sm font-semibold text-gray-400 border-b-2 border-transparent hover:border-gray-400 hover:text-white transition-colors" data-tab="delivery">
            <i data-lucide="mail-check" class="w-4 h-4 inline-block mr-2"></i>
            E-mail de Entrega
        </button>
        <button type="button" id="tab-recovery" class="tab-button px-6 py-4 text-sm font-semibold text-gray-400 border-b-2 border-transparent hover:border-gray-400 hover:text-white transition-colors" data-tab="recovery">
            <i data-lucide="shopping-cart" class="w-4 h-4 inline-block mr-2"></i>
            Recuperação de Carrinho
        </button>
    </div>
</div>

<!-- Aba: Servidor SMTP -->
<div id="content-smtp" class="tab-content bg-dark-card rounded-lg shadow-md p-6" style="border-color: var(--accent-primary);">
    <h2 class="text-2xl font-semibold mb-6 text-white">Detalhes do Servidor SMTP</h2>
    
    <form id="email-settings-form">
        <!-- SMTP Settings -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
            <div>
                <label for="smtp_host" class="block text-gray-300 text-sm font-semibold mb-2">Host SMTP</label>
                <input type="text" id="smtp_host" name="smtp_host" required
                       class="form-input-style" placeholder="Ex: smtp.seudominio.com">
            </div>
            <div>
                <label for="smtp_port" class="block text-gray-300 text-sm font-semibold mb-2">Porta SMTP</label>
                <input type="number" id="smtp_port" name="smtp_port" required
                       class="form-input-style" placeholder="Ex: 587 ou 465">
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
            <div>
                <label for="smtp_username" class="block text-gray-300 text-sm font-semibold mb-2">Usuário SMTP (E-mail)</label>
                <input type="email" id="smtp_username" name="smtp_username" required
                       class="form-input-style" placeholder="Ex: seuemail@seudominio.com">
            </div>
            <div>
                <label for="smtp_password" class="block text-gray-300 text-sm font-semibold mb-2">Senha SMTP</label>
                <input type="password" id="smtp_password" name="smtp_password"
                       class="form-input-style" placeholder="••••••••">
                <p class="text-xs text-gray-400 mt-1">Deixe em branco para manter a senha atual.</p>
            </div>
        </div>

        <div class="mb-6">
            <label for="smtp_encryption" class="block text-gray-300 text-sm font-semibold mb-2">Criptografia</label>
            <select id="smtp_encryption" name="smtp_encryption" class="form-input-style" required>
                <option value="tls">TLS (Recomendado)</option>
                <option value="ssl">SSL</option>
                <option value="none">Nenhuma</option>
            </select>
        </div>

        <h2 class="text-2xl font-semibold mb-6 text-white border-t border-dark-border pt-6">Detalhes do Remetente</h2>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
            <div>
                <label for="smtp_from_email" class="block text-gray-300 text-sm font-semibold mb-2">E-mail do Remetente</label>
                <input type="email" id="smtp_from_email" name="smtp_from_email" required
                       class="form-input-style" placeholder="Ex: noreply@seudominio.com">
                <p class="text-xs text-gray-400 mt-1 text-blue-300">
                    <i data-lucide="info" class="w-3 h-3 inline-block mr-1"></i>
                    Para evitar erros, este e-mail deve ser o mesmo que o "Usuário SMTP" na maioria dos provedores.
                </p>
            </div>
            <div>
                <label for="smtp_from_name" class="block text-gray-300 text-sm font-semibold mb-2">Nome do Remetente</label>
                <input type="text" id="smtp_from_name" name="smtp_from_name" required
                       class="form-input-style" placeholder="Ex: Starfy Notificações">
            </div>
        </div>

        <div class="mt-8 pt-6 border-t border-dark-border flex flex-col sm:flex-row justify-end space-y-4 sm:space-y-0 sm:space-x-4">
            <button type="button" id="test-connection-btn" class="bg-blue-600 text-white font-bold py-3 px-6 rounded-lg hover:bg-blue-700 transition duration-300 flex items-center justify-center space-x-2">
                <i data-lucide="plug-zap" class="w-5 h-5"></i>
                <span>Testar Conexão</span>
            </button>
            <button type="button" id="send-test-email-btn" class="bg-purple-600 text-white font-bold py-3 px-6 rounded-lg hover:bg-purple-700 transition duration-300 flex items-center justify-center space-x-2">
                <i data-lucide="send" class="w-5 h-5"></i>
                <span>Enviar E-mail de Teste</span>
            </button>
            <button type="submit" id="save-settings-btn" class="text-white font-bold py-3 px-6 rounded-lg transition duration-300 flex items-center justify-center space-x-2" style="background-color: var(--accent-primary);" onmouseover="this.style.backgroundColor='var(--accent-primary-hover)'" onmouseout="this.style.backgroundColor='var(--accent-primary)'">
                <i data-lucide="save" class="w-5 h-5"></i>
                <span>Salvar Configurações</span>
            </button>
        </div>
    </form>

    <div id="response-message" class="mt-8 text-center py-4 rounded-lg hidden"></div>
</div>

<!-- Aba: E-mail de Entrega -->
<div id="content-delivery" class="tab-content hidden bg-dark-card rounded-lg shadow-md p-6" style="border-color: var(--accent-primary);">
    <h2 class="text-2xl font-semibold mb-6 text-white">E-mail de Entrega do Produto</h2>
    <p class="text-sm text-gray-400 mb-4">O sistema de entrega de emails é configurado automaticamente usando as configurações da plataforma.</p>
    
    <div class="bg-dark-elevated rounded-lg p-6 border border-dark-border">
        <div class="flex items-start">
            <i data-lucide="info" class="w-6 h-6 text-blue-400 mr-3 flex-shrink-0 mt-1"></i>
            <div>
                <h3 class="text-lg font-semibold text-white mb-2">Configuração Automática</h3>
                <p class="text-gray-300 mb-4">
                    Os emails de entrega são gerados automaticamente usando:
                </p>
                <ul class="list-disc list-inside ml-4 space-y-2 text-gray-300">
                    <li>Logo configurada nas configurações do sistema</li>
                    <li>Cor primária da plataforma</li>
                    <li>Nome da plataforma</li>
                    <li>URLs de acesso geradas automaticamente</li>
                </ul>
                <p class="text-gray-400 mt-4 text-sm">
                    <i data-lucide="check-circle" class="w-4 h-4 inline-block mr-1 text-green-400"></i>
                    Não é necessária nenhuma configuração manual. O sistema gerencia tudo automaticamente.
                </p>
            </div>
        </div>
    </div>
</div>

    <div id="response-message" class="mt-8 text-center py-4 rounded-lg hidden"></div>
</div>

<!-- Aba: Recuperação de Carrinho -->
<div id="content-recovery" class="tab-content hidden bg-dark-card rounded-lg shadow-md p-6" style="border-color: var(--accent-primary);">
    <div class="flex items-center justify-between mb-6">
        <div>
            <h2 class="text-2xl font-semibold text-white">Recuperação de Carrinho</h2>
            <p class="text-sm text-gray-400 mt-1">Configure o sistema automático de recuperação de carrinho para clientes que geraram Pix mas não pagaram.</p>
        </div>
    </div>
    
    <div class="bg-dark-elevated rounded-lg p-6 border border-dark-border mb-6">
        <div class="flex items-start justify-between">
            <div class="flex-1">
                <h3 class="text-lg font-semibold text-white mb-2 flex items-center">
                    <i data-lucide="clock" class="w-5 h-5 mr-2" style="color: var(--accent-primary);"></i>
                    Como Funciona
                </h3>
                <p class="text-gray-300 mb-4">
                    O sistema identifica automaticamente clientes que geraram um Pix mas não pagaram após 10 minutos. 
                    Um email de recuperação é enviado automaticamente com um link para retornar ao checkout e finalizar o pagamento.
                </p>
                <div class="space-y-2 text-sm text-gray-400">
                    <div class="flex items-center">
                        <i data-lucide="check-circle" class="w-4 h-4 mr-2 text-green-400"></i>
                        <span>Verifica vendas Pix pendentes há mais de 10 minutos</span>
                    </div>
                    <div class="flex items-center">
                        <i data-lucide="check-circle" class="w-4 h-4 mr-2 text-green-400"></i>
                        <span>Envia email automático com link para retornar ao checkout</span>
                    </div>
                    <div class="flex items-center">
                        <i data-lucide="check-circle" class="w-4 h-4 mr-2 text-green-400"></i>
                        <span>Usa o mesmo SMTP configurado acima</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="bg-yellow-900/20 border border-yellow-500/30 rounded-lg p-6 mb-6">
        <div class="flex items-start">
            <i data-lucide="alert-triangle" class="w-6 h-6 text-yellow-400 mr-3 flex-shrink-0 mt-1"></i>
            <div>
                <h3 class="text-lg font-semibold text-yellow-300 mb-2">Configuração Necessária</h3>
                <p class="text-yellow-200 mb-4">
                    Para que o sistema funcione, é necessário configurar um <strong>cron job</strong> que execute o script de recuperação 
                    a cada 5 minutos. Clique no botão abaixo para ver as instruções detalhadas.
                </p>
                <button type="button" id="btn-show-cron-instructions" class="text-white font-semibold py-2 px-6 rounded-lg transition duration-300 flex items-center space-x-2" style="background-color: var(--accent-primary);" onmouseover="this.style.backgroundColor='var(--accent-primary-hover)'" onmouseout="this.style.backgroundColor='var(--accent-primary)'">
                    <i data-lucide="help-circle" class="w-5 h-5"></i>
                    <span>Ver Instruções de Configuração</span>
                </button>
            </div>
        </div>
    </div>
    
    <div class="bg-dark-elevated rounded-lg p-6 border border-dark-border">
        <h3 class="text-lg font-semibold text-white mb-4">URL do Script</h3>
        <p class="text-sm text-gray-400 mb-3">Copie esta URL para configurar no cron-job.org:</p>
        <div class="bg-dark-card p-4 rounded-lg border border-dark-border">
            <code class="break-all text-sm" style="color: var(--accent-primary);">
                <?php 
                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
                echo $protocol . $_SERVER['HTTP_HOST'] . '/api/cart_recovery.php';
                ?>
            </code>
            <button type="button" id="copy-cron-url" class="mt-3 bg-dark-elevated text-gray-300 font-semibold py-2 px-4 rounded-lg hover:bg-dark-card transition border border-dark-border flex items-center space-x-2">
                <i data-lucide="copy" class="w-4 h-4"></i>
                <span>Copiar URL</span>
            </button>
        </div>
    </div>
</div>

<!-- Modal de Instruções do Cron Job -->
<div id="cron-instructions-modal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
    <div class="bg-dark-card rounded-lg shadow-xl max-w-3xl w-full max-h-[90vh] overflow-y-auto border border-dark-border">
        <div class="sticky top-0 bg-dark-card border-b border-dark-border p-6 flex items-center justify-between">
            <h2 class="text-2xl font-bold text-white">Como Configurar o Cron Job no cron-job.org</h2>
            <button type="button" id="btn-close-cron-modal" class="text-gray-400 hover:text-white transition-colors">
                <i data-lucide="x" class="w-6 h-6"></i>
            </button>
        </div>
        <div class="p-6 space-y-4 text-gray-300">
            <div>
                <h3 class="text-lg font-semibold text-white mb-2">1. Acesse o cron-job.org</h3>
                <p class="mb-2">Acesse <a href="https://console.cron-job.org/login" target="_blank" style="color: var(--accent-primary);" onmouseover="this.style.color='var(--accent-primary-hover)'" onmouseout="this.style.color='var(--accent-primary)'" class="hover:underline">https://console.cron-job.org/login</a> e faça login na sua conta.</p>
            </div>
            
            <div>
                <h3 class="text-lg font-semibold text-white mb-2">2. Crie um Novo Job</h3>
                <p class="mb-2">Clique em "Create cronjob" ou "Criar Job".</p>
            </div>
            
            <div>
                <h3 class="text-lg font-semibold text-white mb-2">3. Configure a URL</h3>
                <p class="mb-2">No campo "URL", insira:</p>
                <div class="bg-dark-elevated p-4 rounded-lg border border-dark-border">
                    <code class="break-all" style="color: var(--accent-primary);">
                        <?php 
                        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
                        echo $protocol . $_SERVER['HTTP_HOST'] . '/api/cart_recovery.php';
                        ?>
                    </code>
                </div>
            </div>
            
            <div>
                <h3 class="text-lg font-semibold text-white mb-2">4. Configure a Frequência</h3>
                <p class="mb-2">Para verificar a cada 5 minutos, configure:</p>
                <ul class="list-disc list-inside ml-4 space-y-1">
                    <li><strong>Minutos:</strong> */5 (a cada 5 minutos)</li>
                    <li><strong>Horas:</strong> * (todas as horas)</li>
                    <li><strong>Dias:</strong> * (todos os dias)</li>
                    <li><strong>Meses:</strong> * (todos os meses)</li>
                    <li><strong>Dia da Semana:</strong> * (todos os dias da semana)</li>
                </ul>
                <p class="mt-2 text-sm text-gray-400">
                    <i data-lucide="info" class="w-4 h-4 inline-block mr-1"></i>
                    Isso fará o job executar a cada 5 minutos, verificando vendas Pix pendentes há mais de 10 minutos e enviando emails de recuperação.
                </p>
            </div>
            
            <div>
                <h3 class="text-lg font-semibold text-white mb-2">5. Método HTTP</h3>
                <p class="mb-2">Selecione <strong>GET</strong> como método HTTP.</p>
            </div>
            
            <div>
                <h3 class="text-lg font-semibold text-white mb-2">6. Salve o Job</h3>
                <p class="mb-2">Clique em "Create" ou "Salvar" para criar o job.</p>
            </div>
            
            <div class="bg-yellow-900/20 border border-yellow-500/30 rounded-lg p-4 mt-4">
                <p class="text-yellow-300">
                    <i data-lucide="alert-triangle" class="w-5 h-5 inline-block mr-2"></i>
                    <strong>Importante:</strong> O job precisa estar ativo para que os emails de recuperação sejam enviados. 
                    Certifique-se de que o job está habilitado após criá-lo.
                </p>
            </div>
        </div>
        <div class="sticky bottom-0 bg-dark-card border-t border-dark-border p-6 flex justify-end">
            <button type="button" id="btn-close-cron-modal-bottom" class="text-white font-semibold py-2 px-6 rounded-lg transition duration-300" style="background-color: var(--accent-primary);" onmouseover="this.style.backgroundColor='var(--accent-primary-hover)'" onmouseout="this.style.backgroundColor='var(--accent-primary)'">
                Entendi
            </button>
        </div>
    </div>
</div>

<style>
    .form-input-style { 
        width: 100%;
        padding: 0.75rem 1rem;
        background-color: #0f1419;
        border: 1px solid rgba(255, 255, 255, 0.1);
        border-radius: 0.5rem;
        color: white;
    }
    .form-input-style:focus {
        outline: none;
        ring: 2px;
        ring-color: var(--accent-primary);
        border-color: var(--accent-primary);
    }
    .form-input-style::placeholder {
        color: #6b7280;
    }
    .form-input-style option {
        background-color: #0f1419;
        color: white;
    }
    input[type="date"].form-input-style,
    input[type="email"].form-input-style,
    input[type="text"].form-input-style,
    input[type="number"].form-input-style,
    input[type="password"].form-input-style,
    input[type="url"].form-input-style,
    select.form-input-style {
        color-scheme: dark;
    }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    lucide.createIcons();

    const form = document.getElementById('email-settings-form');
    const statusMessageDiv = document.getElementById('status-message');
    const testConnectionBtn = document.getElementById('test-connection-btn');
    const sendTestEmailBtn = document.getElementById('send-test-email-btn');
    const saveSettingsBtn = document.getElementById('save-settings-btn');

    function showStatusMessage(message, type = 'info') {
        statusMessageDiv.classList.remove('hidden', 'bg-green-900/20', 'text-green-300', 'bg-red-900/20', 'text-red-300', 'bg-blue-900/20', 'text-blue-300', 'bg-yellow-900/20', 'text-yellow-300', 'border-green-500/30', 'border-red-500/30', 'border-blue-500/30', 'border-yellow-500/30');
        statusMessageDiv.innerHTML = message;
        if (type === 'success') {
            statusMessageDiv.classList.add('bg-green-900/20', 'text-green-300', 'border-green-500/30');
        } else if (type === 'error') {
            statusMessageDiv.classList.add('bg-red-900/20', 'text-red-300', 'border-red-500/30');
        } else if (type === 'warning') {
            statusMessageDiv.classList.add('bg-yellow-900/20', 'text-yellow-300', 'border-yellow-500/30');
        } else {
            statusMessageDiv.classList.add('bg-blue-900/20', 'text-blue-300', 'border-blue-500/30');
        }
        statusMessageDiv.scrollIntoView({ behavior: 'smooth', block: 'start' });
        lucide.createIcons(); // Ensure icons within messages are rendered
    }

    async function fetchEmailSettings() {
        showStatusMessage('<i data-lucide="loader" class="animate-spin w-5 h-5 inline-block mr-2"></i> Carregando configurações...', 'info');
        try {
            const response = await fetch('/api/admin_api.php?action=get_email_settings');
            const result = await response.json();

            if (response.ok && result.success) {
                const data = result.data;
                document.getElementById('smtp_host').value = data.smtp_host || '';
                document.getElementById('smtp_port').value = data.smtp_port || '587';
                document.getElementById('smtp_username').value = data.smtp_username || '';
                document.getElementById('smtp_encryption').value = data.smtp_encryption || 'tls';
                document.getElementById('smtp_from_email').value = data.smtp_from_email || '';
                document.getElementById('smtp_from_name').value = data.smtp_from_name || 'Starfy';
                
                // Campos de entrega removidos - configurados automaticamente

                showStatusMessage('<i data-lucide="check-circle" class="w-5 h-5 inline-block mr-2"></i> Configurações carregadas com sucesso!', 'success');
            } else {
                showStatusMessage(`<i data-lucide="x-circle" class="w-5 h-5 inline-block mr-2"></i> Erro ao carregar configurações: ${result.error || 'Erro desconhecido.'}`, 'error');
            }
        } catch (error) {
            console.error('Erro na requisição AJAX para carregar configurações:', error);
            showStatusMessage(`<i data-lucide="alert-triangle" class="w-5 h-5 inline-block mr-2"></i> Erro de rede ou servidor ao carregar configurações.`, 'error');
        } finally {
            lucide.createIcons();
        }
    }

    async function sendSmtpRequest(action, extraData = {}, showSuccessMessage = true) {
        showStatusMessage('<i data-lucide="loader" class="animate-spin w-5 h-5 inline-block mr-2"></i> Aguarde...', 'info');
        lucide.createIcons();
        
        const formData = new FormData(form);
        const data = {};
        formData.forEach((value, key) => {
            // Se o campo de senha estiver vazio, não o envia na requisição AJAX
            // para que o backend use a senha já salva.
            if (key === 'smtp_password' && value === '') {
                // Não adiciona 'smtp_password' ao objeto de dados
            } else {
                data[key] = value;
            }
        });
        
        // Assegura que o 'action' da função seja o que prevalece
        data.action = action;
        Object.assign(data, extraData);
        
        try {
            // Adicionar token CSRF aos dados
            data.csrf_token = window.csrfToken || '';
            
            const response = await fetch(`/api/admin_api.php?action=${action}`, {
                method: 'POST',
                headers: { 
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': window.csrfToken || ''
                },
                body: JSON.stringify(data),
            });
            const result = await response.json();

            if (response.ok && result.success) {
                if (showSuccessMessage) {
                    showStatusMessage(`<i data-lucide="check-circle" class="w-5 h-5 inline-block mr-2"></i> ${result.message}`, 'success');
                }
                return { success: true, data: result.data }; // Return data if action returns it
            } else {
                showStatusMessage(`<i data-lucide="x-circle" class="w-5 h-5 inline-block mr-2"></i> ${result.error || 'Erro desconhecido.'}`, 'error');
                return { success: false, error: result.error };
            }
        } catch (error) {
            console.error('Erro na requisição AJAX:', error);
            let errorMessage = "Erro de rede ou servidor.";
            if (error instanceof TypeError && error.message.includes("Failed to fetch")) {
                errorMessage = `Erro de rede: Não foi possível conectar ao servidor. Verifique sua conexão ou a URL do backend.`;
            } else if (error instanceof SyntaxError && error.message.includes("JSON")) {
                errorMessage = `Erro no servidor: Resposta inválida. O servidor pode ter encontrado um erro PHP antes de retornar JSON. Verifique os logs de erro do PHP.`;
            }
            showStatusMessage(`<i data-lucide="alert-triangle" class="w-5 h-5 inline-block mr-2"></i> ${errorMessage}`, 'error');
            return { success: false, error: errorMessage };
        } finally {
            lucide.createIcons();
        }
    }

    testConnectionBtn.addEventListener('click', () => {
        sendSmtpRequest('test_smtp_connection');
    });

    sendTestEmailBtn.addEventListener('click', () => {
        const testEmail = prompt("Para qual e-mail você gostaria de enviar o e-mail de teste?", document.getElementById('smtp_username').value);
        if (testEmail) {
            sendSmtpRequest('send_test_email', { test_email: testEmail });
        }
    });

    saveSettingsBtn.addEventListener('click', async (e) => {
        e.preventDefault(); // Prevent default form submission
        const result = await sendSmtpRequest('save_email_settings');
        if(result.success) {
            // Re-fetch to update original values in case user cancels HTML editing later
            await fetchEmailSettings(); 
        }
    });
    
    
    // Funcionalidades de template removidas - configurado automaticamente


    // Initial fetch of settings when page loads
    fetchEmailSettings();
    
    // Sistema de Abas
    const tabs = document.querySelectorAll('.tab-button');
    const contents = document.querySelectorAll('.tab-content');
    
    tabs.forEach(tab => {
        tab.addEventListener('click', () => {
            const targetTab = tab.getAttribute('data-tab');
            
            // Remove active de todas as abas
            tabs.forEach(t => {
                t.classList.remove('active', 'text-white', 'border-b-2');
                t.classList.add('text-gray-400');
                t.style.borderBottomColor = 'transparent';
            });
            
            // Adiciona active na aba clicada
            tab.classList.add('active', 'text-white');
            tab.classList.remove('text-gray-400');
            tab.style.borderBottomColor = 'var(--accent-primary)';
            
            // Esconde todos os conteúdos
            contents.forEach(c => c.classList.add('hidden'));
            
            // Mostra o conteúdo correspondente
            const targetContent = document.getElementById(`content-${targetTab}`);
            if (targetContent) {
                targetContent.classList.remove('hidden');
            }
        });
    });
    
    // Modal de Instruções do Cron Job
    const cronModal = document.getElementById('cron-instructions-modal');
    const btnShowCron = document.getElementById('btn-show-cron-instructions');
    const btnCloseCron = document.getElementById('btn-close-cron-modal');
    const btnCloseCronBottom = document.getElementById('btn-close-cron-modal-bottom');
    
    btnShowCron?.addEventListener('click', () => {
        cronModal.classList.remove('hidden');
        lucide.createIcons();
    });
    
    const closeCronModal = () => {
        cronModal.classList.add('hidden');
    };
    
    btnCloseCron?.addEventListener('click', closeCronModal);
    btnCloseCronBottom?.addEventListener('click', closeCronModal);
    cronModal?.addEventListener('click', (e) => {
        if (e.target === cronModal) closeCronModal();
    });
    
    // Copiar URL do Cron
    const copyCronUrlBtn = document.getElementById('copy-cron-url');
    copyCronUrlBtn?.addEventListener('click', () => {
        const url = document.querySelector('#content-recovery code').textContent.trim();
        navigator.clipboard.writeText(url).then(() => {
            const originalText = copyCronUrlBtn.innerHTML;
            copyCronUrlBtn.innerHTML = '<i data-lucide="check" class="w-4 h-4"></i> <span>Copiado!</span>';
            lucide.createIcons();
            setTimeout(() => {
                copyCronUrlBtn.innerHTML = originalText;
                lucide.createIcons();
            }, 2000);
        }).catch(err => {
            console.error('Erro ao copiar:', err);
            alert('Erro ao copiar URL. Tente copiar manualmente.');
        });
    });
});
</script>