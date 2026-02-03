<?php
// Buscar logo do checkout para usar no template
$logo_checkout_url = '';

// Função auxiliar para construir URL absoluta
function buildAbsoluteLogoUrl($logo_path_raw) {
    if (empty($logo_path_raw)) {
        return '';
    }
    
    // Se já é URL absoluta, retornar como está
    if (strpos($logo_path_raw, 'http://') === 0 || strpos($logo_path_raw, 'https://') === 0) {
        return $logo_path_raw;
    }
    
    // Construir URL absoluta
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    
    // Remover barra inicial se houver e garantir que não está vazio
    $logo_path = ltrim($logo_path_raw, '/');
    if (empty($logo_path)) {
        return '';
    }
    
    // Construir URL completa
    $full_url = $protocol . $host . '/' . $logo_path;
    
    // Verificar se o arquivo existe (se for caminho local)
    if (strpos($logo_path, 'uploads/') === 0 || strpos($logo_path, 'http') !== 0) {
        $local_path = __DIR__ . '/../' . $logo_path;
        if (!file_exists($local_path)) {
            error_log("Logo não encontrada em: " . $local_path);
            // Mesmo assim retorna a URL, pode ser que esteja em outro servidor
        }
    }
    
    return $full_url;
}

if (function_exists('getSystemSetting')) {
    $logo_checkout_url_raw = getSystemSetting('logo_checkout_url', '');
    if (empty($logo_checkout_url_raw)) {
        $logo_checkout_url_raw = getSystemSetting('logo_url', '');
    }
    $logo_checkout_url = buildAbsoluteLogoUrl($logo_checkout_url_raw);
} else {
    // Fallback se getSystemSetting não existir
    try {
        $stmt_logo = $pdo->query("SELECT valor FROM configuracoes_sistema WHERE chave = 'logo_checkout_url' LIMIT 1");
        $logo_result = $stmt_logo->fetch(PDO::FETCH_ASSOC);
        $logo_checkout_url_raw = $logo_result ? $logo_result['valor'] : '';
        
        if (empty($logo_checkout_url_raw)) {
            $stmt_logo = $pdo->query("SELECT valor FROM configuracoes_sistema WHERE chave = 'logo_url' LIMIT 1");
            $logo_result = $stmt_logo->fetch(PDO::FETCH_ASSOC);
            $logo_checkout_url_raw = $logo_result ? $logo_result['valor'] : '';
        }
        
        $logo_checkout_url = buildAbsoluteLogoUrl($logo_checkout_url_raw);
    } catch (Exception $e) {
        error_log("Erro ao buscar logo: " . $e->getMessage());
    }
}

$nome_plataforma = function_exists('getSystemSetting') ? getSystemSetting('nome_plataforma', 'Starfy') : 'Starfy';

// Debug: log da URL da logo (apenas em desenvolvimento)
if (empty($logo_checkout_url)) {
    error_log("AVISO: Logo do checkout não configurada ou não encontrada");
}
?>

<div class="flex items-center justify-between mb-6">
    <div>
        <h1 class="text-3xl font-bold text-white">Email Marketing / Broadcast</h1>
        <p class="text-gray-400 mt-1">Envie emails em massa para infoprodutores, clientes finais ou ambos.</p>
    </div>
    <a href="/admin?pagina=admin_dashboard" class="bg-dark-elevated text-gray-300 font-bold py-2 px-4 rounded-lg hover:bg-dark-card transition duration-300 flex items-center space-x-2 border border-dark-border">
        <i data-lucide="arrow-left" class="w-5 h-5"></i>
        <span>Voltar ao Dashboard</span>
    </a>
</div>

<!-- Card Informativo sobre Job -->
<div class="bg-blue-900/20 border border-blue-500/30 rounded-lg p-6 mb-6">
    <div class="flex items-start gap-4">
        <div class="flex-shrink-0">
            <i data-lucide="info" class="w-6 h-6 text-blue-400"></i>
        </div>
        <div class="flex-1">
            <h3 class="text-lg font-semibold text-blue-300 mb-2">Configuração do Job Necessária</h3>
            <p class="text-blue-200 mb-3">
                Para que os emails sejam enviados automaticamente, você precisa configurar um job no 
                <a href="https://console.cron-job.org/login" target="_blank" class="underline hover:text-blue-100">cron-job.org</a>.
            </p>
            <button type="button" id="btn-show-job-instructions" class="bg-blue-600 hover:bg-blue-700 text-white font-semibold py-2 px-4 rounded-lg transition duration-300 flex items-center gap-2">
                <i data-lucide="help-circle" class="w-4 h-4"></i>
                Ver Instruções de Configuração
            </button>
        </div>
    </div>
</div>

<div id="status-message" class="hidden bg-green-900/20 border border-green-500/30 text-green-300 px-4 py-3 rounded relative mb-4" role="alert"></div>
<div id="error-message" class="hidden bg-red-900/20 border border-red-500/30 text-red-300 px-4 py-3 rounded relative mb-4" role="alert"></div>

<div class="bg-dark-card rounded-lg shadow-md p-6 border border-dark-border">
    <form id="broadcast-form">
        <!-- Seleção de Destinatários -->
        <div class="mb-6">
            <label class="block text-gray-300 text-sm font-semibold mb-2">Enviar para:</label>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <label class="flex items-center p-4 bg-dark-elevated rounded-lg border border-dark-border cursor-pointer transition-colors" onmouseover="this.style.borderColor='var(--accent-primary)'" onmouseout="this.style.borderColor='rgba(255,255,255,0.1)'">
                    <input type="radio" name="recipient_type" value="infoprodutor" class="mr-3" checked>
                    <div>
                        <div class="font-semibold text-white">Infoprodutores</div>
                        <div class="text-xs text-gray-400">Apenas infoprodutores</div>
                    </div>
                </label>
                <label class="flex items-center p-4 bg-dark-elevated rounded-lg border border-dark-border cursor-pointer transition-colors" onmouseover="this.style.borderColor='var(--accent-primary)'" onmouseout="this.style.borderColor='rgba(255,255,255,0.1)'">
                    <input type="radio" name="recipient_type" value="client" class="mr-3">
                    <div>
                        <div class="font-semibold text-white">Clientes Finais</div>
                        <div class="text-xs text-gray-400">Apenas clientes finais</div>
                    </div>
                </label>
                <label class="flex items-center p-4 bg-dark-elevated rounded-lg border border-dark-border cursor-pointer transition-colors" onmouseover="this.style.borderColor='var(--accent-primary)'" onmouseout="this.style.borderColor='rgba(255,255,255,0.1)'">
                    <input type="radio" name="recipient_type" value="both" class="mr-3">
                    <div>
                        <div class="font-semibold text-white">Ambos</div>
                        <div class="text-xs text-gray-400">Infoprodutores + Clientes</div>
                    </div>
                </label>
            </div>
            <div id="recipient-count" class="mt-3 text-sm text-gray-400">
                <i data-lucide="users" class="w-4 h-4 inline-block mr-1"></i>
                <span id="count-text">Carregando...</span>
            </div>
        </div>

        <!-- Assunto -->
        <div class="mb-6">
            <label for="email-subject" class="block text-gray-300 text-sm font-semibold mb-2">Assunto do Email *</label>
            <input type="text" id="email-subject" name="subject" required
                   class="w-full px-4 py-3 bg-dark-elevated border border-dark-border rounded-lg text-white focus:outline-none focus:ring-2 focus:border-transparent" style="--tw-ring-color: var(--accent-primary);"
                   placeholder="Ex: Novidades e Atualizações">
        </div>

        <!-- Editor WYSIWYG -->
        <div class="mb-6">
            <label class="block text-gray-300 text-sm font-semibold mb-2">Conteúdo do Email *</label>
            <div class="bg-dark-elevated border border-dark-border rounded-lg overflow-hidden">
                <textarea id="email-content" name="content" required></textarea>
            </div>
            <p class="text-xs text-gray-400 mt-2">
                <i data-lucide="info" class="w-3 h-3 inline-block mr-1"></i>
                O conteúdo será inserido automaticamente em um template HTML profissional com logo.
            </p>
        </div>

        <!-- Preview -->
        <div class="mb-6">
            <label class="block text-gray-300 text-sm font-semibold mb-2">Preview do Email</label>
            <div class="bg-white rounded-lg border border-dark-border p-4 max-h-96 overflow-y-auto">
                <div id="email-preview">
                    <p class="text-gray-500 text-center py-8">O preview aparecerá aqui conforme você digita...</p>
                </div>
            </div>
        </div>

        <!-- Botão Enviar -->
        <div class="flex justify-end gap-4">
            <button type="button" id="btn-preview" class="bg-gray-600 hover:bg-gray-700 text-white font-semibold py-3 px-6 rounded-lg transition duration-300 flex items-center gap-2">
                <i data-lucide="eye" class="w-5 h-5"></i>
                Atualizar Preview
            </button>
            <button type="submit" id="btn-submit" class="text-white font-semibold py-3 px-6 rounded-lg transition duration-300 flex items-center gap-2" style="background-color: var(--accent-primary);" onmouseover="this.style.backgroundColor='var(--accent-primary-hover)'" onmouseout="this.style.backgroundColor='var(--accent-primary)'">
                <i data-lucide="send" class="w-5 h-5"></i>
                Enviar para Fila
            </button>
        </div>
    </form>
</div>

<!-- Modal de Instruções do Job -->
<div id="job-instructions-modal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
    <div class="bg-dark-card rounded-lg shadow-xl max-w-3xl w-full max-h-[90vh] overflow-y-auto border border-dark-border">
        <div class="sticky top-0 bg-dark-card border-b border-dark-border p-6 flex items-center justify-between">
            <h2 class="text-2xl font-bold text-white">Como Configurar o Job no cron-job.org</h2>
            <button type="button" id="btn-close-modal" class="text-gray-400 hover:text-white transition-colors">
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
                        echo $protocol . $_SERVER['HTTP_HOST'] . '/api/process_email_queue.php';
                        ?>
                    </code>
                </div>
            </div>
            
            <div>
                <h3 class="text-lg font-semibold text-white mb-2">4. Configure a Frequência</h3>
                <p class="mb-2">Para enviar 30 emails por minuto, configure:</p>
                <ul class="list-disc list-inside ml-4 space-y-1">
                    <li><strong>Minutos:</strong> */2 (a cada 2 minutos)</li>
                    <li><strong>Horas:</strong> * (todas as horas)</li>
                    <li><strong>Dias:</strong> * (todos os dias)</li>
                    <li><strong>Meses:</strong> * (todos os meses)</li>
                    <li><strong>Dia da Semana:</strong> * (todos os dias da semana)</li>
                </ul>
                <p class="mt-2 text-sm text-gray-400">
                    <i data-lucide="info" class="w-4 h-4 inline-block mr-1"></i>
                    Isso fará o job executar a cada 2 minutos, processando até 30 emails por execução (15 emails/minuto).
                    Para 30 emails/minuto, configure para executar a cada 1 minuto (*/1).
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
                    <strong>Importante:</strong> O job precisa estar ativo para que os emails sejam enviados. 
                    Certifique-se de que o job está habilitado após criá-lo.
                </p>
            </div>
        </div>
        <div class="sticky bottom-0 bg-dark-card border-t border-dark-border p-6 flex justify-end">
            <button type="button" id="btn-close-modal-bottom" class="text-white font-semibold py-2 px-6 rounded-lg transition duration-300" style="background-color: var(--accent-primary);" onmouseover="this.style.backgroundColor='var(--accent-primary-hover)'" onmouseout="this.style.backgroundColor='var(--accent-primary)'">
                Entendi
            </button>
        </div>
    </div>
</div>

<!-- Quill.js - Editor WYSIWYG gratuito e sem necessidade de API key -->
<link href="https://cdn.quilljs.com/1.3.6/quill.snow.css" rel="stylesheet">
<script src="https://cdn.quilljs.com/1.3.6/quill.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    lucide.createIcons();
    
    // Inicializar Quill.js
    const textareaElement = document.getElementById('email-content');
    if (textareaElement && typeof Quill !== 'undefined') {
        // Criar container para o editor
        const editorContainer = document.createElement('div');
        editorContainer.id = 'quill-editor-container';
        editorContainer.style.width = '100%';
        editorContainer.style.minHeight = '400px';
        editorContainer.style.height = '400px';
        editorContainer.style.backgroundColor = '#ffffff';
        textareaElement.parentNode.insertBefore(editorContainer, textareaElement);
        textareaElement.style.display = 'none';
        
        // Garantir que o container pai tenha largura total
        const parentContainer = textareaElement.parentNode;
        if (parentContainer) {
            parentContainer.style.width = '100%';
            parentContainer.style.display = 'block';
        }
        
        // Inicializar Quill
        const quill = new Quill('#quill-editor-container', {
            theme: 'snow',
            modules: {
                toolbar: [
                    [{ 'header': [1, 2, 3, false] }],
                    ['bold', 'italic', 'underline', 'strike'],
                    [{ 'color': [] }, { 'background': [] }],
                    [{ 'list': 'ordered'}, { 'list': 'bullet' }],
                    [{ 'align': [] }],
                    ['link'],
                    ['clean']
                ]
            },
            placeholder: 'Digite o conteúdo do email aqui...'
        });
        
        // Sincronizar conteúdo com textarea oculto
        quill.on('text-change', function() {
            const html = quill.root.innerHTML;
            textareaElement.value = html;
            updatePreview();
        });
        
        // Função para obter conteúdo do editor
        window.getEmailContent = function() {
            return quill.root.innerHTML;
        };
        
        // Função para limpar editor
        window.clearEmailEditor = function() {
            quill.setContents([]);
            textareaElement.value = '';
        };
    } else {
        console.error('Quill.js não carregou. Usando textarea simples.');
        // Fallback: adicionar listener no textarea
        if (textareaElement) {
            textareaElement.addEventListener('input', updatePreview);
        }
        window.getEmailContent = function() {
            const textarea = document.getElementById('email-content');
            if (textarea) {
                return textarea.value.replace(/\n/g, '<br>');
            }
            return '';
        };
        window.clearEmailEditor = function() {
            const textarea = document.getElementById('email-content');
            if (textarea) {
                textarea.value = '';
            }
        };
    }
    
    // Variáveis globais
    const logoCheckoutUrl = '<?php echo htmlspecialchars($logo_checkout_url, ENT_QUOTES); ?>';
    const nomePlataforma = '<?php echo htmlspecialchars($nome_plataforma, ENT_QUOTES); ?>';
    const protocol = '<?php echo (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off" || $_SERVER["SERVER_PORT"] == 443) ? "https://" : "http://"; ?>';
    const host = '<?php echo $_SERVER["HTTP_HOST"]; ?>';
    
    // Debug: log da URL da logo
    console.log('Logo URL recebida do PHP:', logoCheckoutUrl);
    console.log('Protocol:', protocol);
    console.log('Host:', host);
    
    // Função para gerar template HTML
    function generateEmailTemplate(content) {
        // Garantir que a URL da logo seja absoluta e válida
        let finalLogoUrl = logoCheckoutUrl || '';
        
        // Se a URL não começar com http/https, construir URL absoluta
        if (finalLogoUrl && !finalLogoUrl.match(/^https?:\/\//i)) {
            // Remover barra inicial se houver
            finalLogoUrl = finalLogoUrl.replace(/^\//, '');
            finalLogoUrl = protocol + host + '/' + finalLogoUrl;
        }
        
        // Validar URL antes de usar
        if (finalLogoUrl && !finalLogoUrl.match(/^https?:\/\/.+/i)) {
            console.warn('URL da logo inválida:', finalLogoUrl);
            finalLogoUrl = '';
        }
        
        console.log('URL final da logo:', finalLogoUrl);
        
        const logoHtml = finalLogoUrl ? 
            `<img src="${finalLogoUrl}" alt="${nomePlataforma}" style="max-width: 200px; height: auto; display: block; margin: 0 auto 30px; border: 0;" />` : 
            '';
        
        return `
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>Email Marketing</title>
    <style>
        @media screen and (max-width: 600px) {
            .container { width: 100% !important; padding: 10px !important; }
            .content { padding: 25px 20px !important; }
        }
    </style>
</head>
<body style="margin: 0; padding: 0; background-color: #f1f5f9; font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
    <table align="center" border="0" cellpadding="0" cellspacing="0" width="100%" style="border-collapse: collapse;">
        <tr>
            <td align="center" style="padding: 20px 0;">
                <table class="container" align="center" border="0" cellpadding="0" cellspacing="0" width="600" style="border-collapse: collapse; background-color: #ffffff; border-radius: 16px; box-shadow: 0 10px 25px rgba(0,0,0,0.05); overflow: hidden; border: 1px solid #e2e8f0;">
                    <!-- Cabeçalho com Logo -->
                    <tr>
                        <td align="center" style="padding: 30px 20px; background-color: #ffffff;">
                            ${logoHtml}
                        </td>
                    </tr>
                    <!-- Corpo Principal -->
                    <tr>
                        <td class="content" style="padding: 40px 35px;">
                            ${content}
                        </td>
                    </tr>
                    <!-- Rodapé -->
                    <tr>
                        <td align="center" style="padding: 25px 30px; background-color: #f8fafc; border-top: 1px solid #e2e8f0;">
                            <p style="margin: 0; font-size: 13px; color: #64748b;">
                                
                            </p>
                            <p style="margin: 10px 0 0 0; font-size: 13px; color: #94a3b8;">
                                ${nomePlataforma} &copy; ${new Date().getFullYear()}. Todos os direitos reservados.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
        `;
    }
    
    // Atualizar preview
    function updatePreview() {
        let content = '';
        if (typeof window.getEmailContent === 'function') {
            content = window.getEmailContent();
        } else {
            const textarea = document.getElementById('email-content');
            if (textarea) {
                // Converter quebras de linha em <br> e texto simples em HTML básico
                content = textarea.value
                    .replace(/\n/g, '<br>')
                    .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
                    .replace(/\*(.*?)\*/g, '<em>$1</em>');
            }
        }
        const fullHtml = generateEmailTemplate(content);
        document.getElementById('email-preview').innerHTML = fullHtml;
    }
    
    // Botão de preview
    document.getElementById('btn-preview')?.addEventListener('click', updatePreview);
    
    // Atualizar contador de destinatários
    function updateRecipientCount() {
        const recipientType = document.querySelector('input[name="recipient_type"]:checked')?.value || 'infoprodutor';
        
        fetch('/api/admin_api.php?action=get_recipient_count&type=' + recipientType)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('count-text').textContent = 
                        `${data.count} destinatário(s) receberão este email`;
                } else {
                    document.getElementById('count-text').textContent = 'Erro ao carregar contagem';
                }
            })
            .catch(error => {
                console.error('Erro:', error);
                document.getElementById('count-text').textContent = 'Erro ao carregar contagem';
            });
    }
    
    // Atualizar contador quando mudar tipo
    document.querySelectorAll('input[name="recipient_type"]').forEach(radio => {
        radio.addEventListener('change', updateRecipientCount);
    });
    
    // Carregar contador inicial
    updateRecipientCount();
    
    // Modal de instruções
    const modal = document.getElementById('job-instructions-modal');
    const btnShow = document.getElementById('btn-show-job-instructions');
    const btnClose = document.getElementById('btn-close-modal');
    const btnCloseBottom = document.getElementById('btn-close-modal-bottom');
    
    btnShow?.addEventListener('click', () => {
        modal.classList.remove('hidden');
    });
    
    const closeModal = () => modal.classList.add('hidden');
    btnClose?.addEventListener('click', closeModal);
    btnCloseBottom?.addEventListener('click', closeModal);
    modal?.addEventListener('click', (e) => {
        if (e.target === modal) closeModal();
    });
    
    // Submeter formulário
    document.getElementById('broadcast-form')?.addEventListener('submit', async (e) => {
        e.preventDefault();
        
        const subject = document.getElementById('email-subject').value.trim();
        let content = '';
        if (typeof window.getEmailContent === 'function') {
            content = window.getEmailContent();
        } else {
            const textarea = document.getElementById('email-content');
            if (textarea) {
                content = textarea.value.replace(/\n/g, '<br>');
            }
        }
        const recipientType = document.querySelector('input[name="recipient_type"]:checked')?.value;
        
        if (!subject || !content) {
            showError('Por favor, preencha o assunto e o conteúdo do email.');
            return;
        }
        
        // Gerar HTML completo com template
        const fullHtml = generateEmailTemplate(content);
        
        const btnSubmit = document.getElementById('btn-submit');
        btnSubmit.disabled = true;
        btnSubmit.innerHTML = '<i data-lucide="loader-2" class="w-5 h-5 animate-spin"></i> Enviando...';
        
        try {
            const response = await fetch('/api/admin_api.php?action=create_broadcast_queue', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    recipient_type: recipientType,
                    subject: subject,
                    body: fullHtml
                })
            });
            
            const data = await response.json();
            
            if (data.success) {
                showSuccess(`Email adicionado à fila com sucesso! ${data.total} email(s) serão enviados.`);
                document.getElementById('broadcast-form').reset();
                if (typeof window.clearEmailEditor === 'function') {
                    window.clearEmailEditor();
                } else {
                    const textarea = document.getElementById('email-content');
                    if (textarea) {
                        textarea.value = '';
                    }
                }
                updatePreview();
                updateRecipientCount();
            } else {
                showError(data.error || 'Erro ao adicionar email à fila.');
            }
        } catch (error) {
            console.error('Erro:', error);
            showError('Erro ao comunicar com o servidor.');
        } finally {
            btnSubmit.disabled = false;
            btnSubmit.innerHTML = '<i data-lucide="send" class="w-5 h-5"></i> Enviar para Fila';
        }
    });
    
    function showSuccess(message) {
        const el = document.getElementById('status-message');
        el.textContent = message;
        el.classList.remove('hidden');
        document.getElementById('error-message').classList.add('hidden');
        setTimeout(() => el.classList.add('hidden'), 5000);
    }
    
    function showError(message) {
        const el = document.getElementById('error-message');
        el.textContent = message;
        el.classList.remove('hidden');
        document.getElementById('status-message').classList.add('hidden');
    }
    
    // Atualizar ícones após mudanças dinâmicas
    setInterval(() => lucide.createIcons(), 1000);
});
</script>

