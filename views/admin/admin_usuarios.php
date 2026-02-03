<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../config/config.php';

// Carregar funções SaaS se necessário
if (file_exists(__DIR__ . '/../../saas/includes/saas_functions.php')) {
    require_once __DIR__ . '/../../saas/includes/saas_functions.php';
}

// Proteção de página: verifica se o usuário está logado E se é um administrador
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true || !isset($_SESSION["tipo"]) || $_SESSION["tipo"] !== 'admin') {
    header("location: /login");
    exit;
}

// Buscar planos SaaS disponíveis (se SaaS estiver habilitado)
$planos_disponiveis = [];
if (function_exists('saas_enabled') && saas_enabled()) {
    try {
        $stmt = $pdo->query("SELECT id, nome, preco, periodo FROM saas_planos WHERE ativo = 1 ORDER BY ordem ASC, preco ASC");
        $planos_disponiveis = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Erro ao buscar planos: " . $e->getMessage());
    }
}

// O filtro de role já é definido em admin.php e passado para cá através da inclusão
// $role_filter = isset($_GET['role']) ? $_GET['role'] : 'all'; // Já vem de admin.php
$active_class_filter_btn = 'text-white shadow';
$inactive_class_filter_btn = 'text-gray-400 hover:bg-dark-card bg-dark-elevated';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerenciar Usuários - Painel Admin</title>
    <?php
    require_once __DIR__ . '/../../helpers/security_helper.php';
    $csrf_token_js = generate_csrf_token();
    ?>
    <meta name="csrf-token" content="<?php echo htmlspecialchars($csrf_token_js); ?>">
    <script>
        // Variável global para token CSRF
        window.csrfToken = '<?php echo htmlspecialchars($csrf_token_js); ?>';
    </script>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    <style>
        body { font-family: 'Inter', sans-serif; }
        .form-input-style { 
            width: 100%;
            padding: 0.75rem 1rem;
            background-color: #0f1419 !important;
            border: 1px solid rgba(255, 255, 255, 0.1) !important;
            border-radius: 0.5rem;
            color: white !important;
        }
        .form-input-style:focus {
            outline: none !important;
            border-color: var(--accent-primary) !important;
            box-shadow: 0 0 0 3px rgba(50, 231, 104, 0.1) !important;
        }
        .form-input-style::placeholder {
            color: #6b7280 !important;
        }
        .form-input-style option {
            background-color: #0f1419 !important;
            color: white !important;
        }
        input[type="date"].form-input-style,
        input[type="email"].form-input-style,
        input[type="text"].form-input-style,
        input[type="number"].form-input-style,
        input[type="password"].form-input-style,
        input[type="tel"].form-input-style,
        select.form-input-style {
            color-scheme: dark;
        }
        .modal-overlay {
            position: fixed;
            inset: 0;
            background-color: rgba(0, 0, 0, 0.6);
            z-index: 999;
            display: flex;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.3s ease-in-out, visibility 0.3s ease-in-out;
        }
        .modal-overlay.open {
            opacity: 1;
            visibility: visible;
        }
        .modal-content {
            background-color: #1a1f24;
            border-radius: 12px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
            border: 1px solid var(--accent-primary);
            width: 95%;
            max-width: 600px;
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, calc(-50% - 20px)) scale(0.95);
            opacity: 0;
            transition: transform 0.3s ease-in-out, opacity 0.3s ease-in-out;
        }
        .modal-overlay.open .modal-content {
            transform: translate(-50%, -50%) scale(1);
            opacity: 1;
        }
    </style>
</head>
<body class="bg-dark-base font-sans">
    <div class="container mx-auto">
        <div class="flex items-center justify-between mb-6">
            <div>
                <h1 class="text-3xl font-bold text-white">Gerenciar Usuários</h1>
                <p class="text-gray-400 mt-1">Visualize, edite e adicione usuários da plataforma.</p>
            </div>
            <a href="/admin?pagina=admin_dashboard" class="bg-dark-elevated text-gray-300 font-bold py-2 px-4 rounded-lg hover:bg-dark-card transition duration-300 flex items-center space-x-2 border border-dark-border">
                <i data-lucide="arrow-left" class="w-5 h-5"></i>
                <span>Voltar ao Dashboard</span>
            </a>
        </div>

        <div class="bg-dark-card p-8 rounded-lg shadow-md border" style="border-color: var(--accent-primary);">
            <h2 class="text-2xl font-semibold mb-6 text-white">Listagem de Usuários</h2>
            
            <div class="flex flex-col sm:flex-row justify-between items-center mb-6 space-y-4 sm:space-y-0 sm:space-x-4">
                <div class="relative w-full sm:w-1/2">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <i data-lucide="search" class="w-5 h-5 text-gray-400"></i>
                    </div>
                    <input type="text" id="search-input" class="block w-full pl-10 pr-3 py-2 border border-dark-border rounded-md leading-5 bg-dark-elevated text-white placeholder-gray-500 focus:outline-none focus:placeholder-gray-400 focus:ring-1 sm:text-sm" style="--tw-ring-color: var(--accent-primary);" onfocus="this.style.borderColor='var(--accent-primary)'; this.style.boxShadow='0 0 0 1px var(--accent-primary)'" onblur="this.style.borderColor='rgba(255,255,255,0.1)'; this.style.boxShadow='none'" placeholder="Pesquisar por nome ou e-mail...">
                </div>
                <button id="add-user-btn" class="w-full sm:w-auto text-white font-bold py-2 px-4 rounded-lg transition duration-300 flex items-center justify-center space-x-2" style="background-color: var(--accent-primary);" onmouseover="this.style.backgroundColor='var(--accent-primary-hover)'" onmouseout="this.style.backgroundColor='var(--accent-primary)'">
                    <i data-lucide="user-plus" class="w-5 h-5"></i>
                    <span>Adicionar Usuário</span>
                </button>
            </div>

            <!-- Botões de Filtro por Função -->
            <div class="flex flex-wrap gap-2 mb-6">
                <a href="/admin?pagina=admin_usuarios&role=all" class="filter-role-btn px-4 py-2 text-sm font-semibold rounded-md <?php echo ($role_filter == 'all') ? $active_class_filter_btn : $inactive_class_filter_btn; ?>">
                    Todos os Usuários
                </a>
                <a href="/admin?pagina=admin_usuarios&role=infoproducer" class="filter-role-btn px-4 py-2 text-sm font-semibold rounded-md <?php echo ($role_filter == 'infoproducer') ? $active_class_filter_btn : $inactive_class_filter_btn; ?>" <?php if ($role_filter == 'infoproducer'): ?>style="background-color: var(--accent-primary);"<?php endif; ?>>
                    Infoprodutores
                </a>
                <a href="/admin?pagina=admin_usuarios&role=client" class="filter-role-btn px-4 py-2 text-sm font-semibold rounded-md <?php echo ($role_filter == 'client') ? $active_class_filter_btn : $inactive_class_filter_btn; ?>" <?php if ($role_filter == 'client'): ?>style="background-color: var(--accent-primary);"<?php endif; ?>>
                    Clientes Finais
                </a>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-dark-border">
                    <thead class="bg-dark-elevated">
                        <tr>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Nome</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Email</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Telefone</th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-400 uppercase tracking-wider">Tipo</th>
                            <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-400 uppercase tracking-wider">Ações</th>
                        </tr>
                    </thead>
                    <tbody id="users-tbody" class="bg-dark-card divide-y divide-dark-border">
                        <!-- User rows will be loaded here by JavaScript -->
                    </tbody>
                </table>
            </div>
            
            <!-- Loading State -->
            <div id="loading-state" class="text-center py-12 text-gray-400" style="display: none;">
                <svg class="animate-spin h-8 w-8 mx-auto" style="color: var(--accent-primary);" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.96l2-2.669z"></path>
                </svg>
                <p class="mt-4 font-medium">Carregando usuários...</p>
            </div>

            <!-- Empty State -->
            <div id="empty-state" class="text-center py-12 text-gray-400" style="display: none;">
                <i data-lucide="users-round" class="mx-auto w-16 h-16 text-gray-500"></i>
                <p class="mt-4 font-medium">Nenhum usuário encontrado.</p>
                <p class="mt-1 text-sm">Tente ajustar sua pesquisa ou adicione um novo usuário.</p>
            </div>

            <!-- Pagination Controls -->
            <div id="pagination-controls" class="hidden mt-4 flex items-center justify-between">
                <button id="prev-page" class="relative inline-flex items-center px-4 py-2 border border-dark-border text-sm font-medium rounded-md text-gray-300 bg-dark-elevated hover:bg-dark-card disabled:opacity-50 disabled:cursor-not-allowed">
                    Anterior
                </button>
                <span id="page-info" class="text-sm text-gray-300">Página 1 de 1</span>
                <button id="next-page" class="ml-3 relative inline-flex items-center px-4 py-2 border border-dark-border text-sm font-medium rounded-md text-gray-300 bg-dark-elevated hover:bg-dark-card disabled:opacity-50 disabled:cursor-not-allowed">
                    Próximo
                </button>
            </div>

        </div>
    </div>

    <!-- Add/Edit User Modal -->
    <div id="user-modal" class="modal-overlay hidden">
        <div class="modal-content">
            <div class="flex justify-between items-center p-6 border-b border-dark-border">
                <h2 id="modal-title" class="text-2xl font-bold text-white">Adicionar Usuário</h2>
                <button class="modal-close-btn text-gray-400 hover:text-gray-300 p-1 rounded-full hover:bg-dark-elevated transition">
                    <i data-lucide="x" class="w-6 h-6"></i>
                </button>
            </div>
            <form id="user-form" action="" method="post" class="p-6 space-y-5">
                <input type="hidden" name="csrf_token" id="csrf-token" value="<?php echo htmlspecialchars($csrf_token_js); ?>">
                <input type="hidden" name="user_id" id="user-id">
                <div>
                    <label for="nome" class="block text-gray-300 text-sm font-semibold mb-2">Nome Completo</label>
                    <input type="text" id="nome" name="nome" required class="form-input-style" placeholder="Nome completo do usuário">
                </div>
                <div>
                    <label for="email" class="block text-gray-300 text-sm font-semibold mb-2">E-mail (Usuário)</label>
                    <input type="email" id="email" name="email" required class="form-input-style" placeholder="email@exemplo.com">
                </div>
                <div>
                    <label for="telefone" class="block text-gray-300 text-sm font-semibold mb-2">Telefone</label>
                    <input type="tel" id="telefone" name="telefone" class="form-input-style" placeholder="(XX) XXXXX-XXXX">
                </div>
                <div>
                    <label for="senha" class="block text-gray-300 text-sm font-semibold mb-2">Senha</label>
                    <input type="password" id="senha" name="senha" class="form-input-style" placeholder="Deixe em branco para não alterar a senha">
                    <p class="text-xs text-gray-400 mt-1">Ao adicionar, uma senha padrão será gerada se deixado em branco.</p>
                </div>
                <div>
                    <label for="tipo" class="block text-gray-300 text-sm font-semibold mb-2">Tipo de Usuário</label>
                    <!-- ALTERAÇÃO: Atualizado o select para incluir Infoprodutor e renomear Usuário para Cliente Final -->
                    <select id="tipo" name="tipo" required class="form-input-style">
                        <option value="infoprodutor">Infoprodutor</option>
                        <option value="usuario">Cliente Final</option>
                        <option value="admin">Administrador</option>
                    </select>
                </div>
                <!-- Campo hidden sempre presente para garantir que plano_id seja enviado -->
                <input type="hidden" name="plano_id" id="plano_id_hidden" value="">
                <?php if (function_exists('saas_enabled') && saas_enabled() && !empty($planos_disponiveis)): ?>
                <div id="plano-container" class="hidden">
                    <label for="plano_id_select" class="block text-gray-300 text-sm font-semibold mb-2">Plano SaaS</label>
                    <select id="plano_id_select" class="form-input-style">
                        <option value="">Nenhum plano</option>
                        <?php foreach ($planos_disponiveis as $plano): ?>
                            <?php 
                            $preco_formatado = number_format($plano['preco'], 2, ',', '.');
                            $periodo_texto = $plano['periodo'] === 'mensal' ? 'mês' : 'ano';
                            ?>
                            <option value="<?php echo $plano['id']; ?>">
                                <?php echo htmlspecialchars($plano['nome']); ?> - R$ <?php echo $preco_formatado; ?>/<?php echo $periodo_texto; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="text-xs text-gray-400 mt-1">Selecione um plano para atribuir ao infoprodutor. Deixe em branco para não atribuir plano.</p>
                </div>
                <?php endif; ?>
                <div class="flex justify-end items-center space-x-4">
                    <button type="button" class="modal-cancel-btn bg-dark-elevated text-gray-300 font-bold py-2 px-5 rounded-lg hover:bg-dark-card transition border border-dark-border">Cancelar</button>
                    <button type="submit" class="bg-primary text-white font-bold py-2 px-5 rounded-lg transition" style="background-color: var(--accent-primary);" onmouseover="this.style.backgroundColor='var(--accent-primary-hover)'" onmouseout="this.style.backgroundColor='var(--accent-primary)'">Salvar Usuário</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Delete User Confirmation Modal -->
    <div id="delete-modal" class="modal-overlay hidden">
        <div class="modal-content max-w-sm text-center p-8">
            <div class="mx-auto flex items-center justify-center h-16 w-16 rounded-full bg-red-900/30 mb-4 border border-red-500/30">
                <i data-lucide="alert-triangle" class="h-8 w-8 text-red-400"></i>
            </div>
            <h3 class="text-xl font-bold text-white mb-2">Tem certeza?</h3>
            <p class="text-gray-400">Você realmente deseja deletar o usuário <strong id="delete-user-name" class="text-white"></strong>? Esta ação não pode ser desfeita.</p>
            <div class="mt-6 flex justify-center space-x-4">
                <button type="button" class="modal-close-btn bg-dark-elevated text-gray-300 font-bold py-2 px-5 rounded-lg hover:bg-dark-card transition border border-dark-border">Cancelar</button>
                <button type="button" id="confirm-delete-btn" class="bg-red-600 text-white font-bold py-2 px-5 rounded-lg hover:bg-red-700 transition">Deletar</button>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            lucide.createIcons();

            const usersTbody = document.getElementById('users-tbody');
            const loadingState = document.getElementById('loading-state');
            const emptyState = document.getElementById('empty-state');
            const searchInput = document.getElementById('search-input');
            const addUserBtn = document.getElementById('add-user-btn');
            const userModal = document.getElementById('user-modal');
            const modalTitle = document.getElementById('modal-title');
            const userForm = document.getElementById('user-form');
            const userIdInput = document.getElementById('user-id');
            const nomeInput = document.getElementById('nome');
            const emailInput = document.getElementById('email');
            const telefoneInput = document.getElementById('telefone');
            const senhaInput = document.getElementById('senha');
            const tipoSelect = document.getElementById('tipo');
            const planoContainer = document.getElementById('plano-container');
            const planoSelect = document.getElementById('plano_id_select');
            const planoHidden = document.getElementById('plano_id_hidden');
            
            // Mostrar/ocultar campo de plano baseado no tipo
            function togglePlanoField() {
                if (planoContainer && planoHidden) {
                    if (tipoSelect.value === 'infoprodutor') {
                        planoContainer.classList.remove('hidden');
                        // Sincronizar valor do select visível com o hidden
                        if (planoSelect) {
                            planoHidden.value = planoSelect.value;
                        }
                    } else {
                        planoContainer.classList.add('hidden');
                        if (planoSelect) planoSelect.value = '';
                        planoHidden.value = ''; // Limpar campo hidden
                    }
                }
            }
            
            // Sincronizar valor do select com o hidden quando mudar
            if (planoSelect && planoHidden) {
                planoSelect.addEventListener('change', function() {
                    planoHidden.value = this.value;
                });
            }
            
            // Event listener para mudança de tipo
            if (tipoSelect) {
                tipoSelect.addEventListener('change', togglePlanoField);
                // Verificar estado inicial
                togglePlanoField();
            }
            const deleteModal = document.getElementById('delete-modal');
            const deleteUserName = document.getElementById('delete-user-name');
            const confirmDeleteBtn = document.getElementById('confirm-delete-btn');
            const paginationControls = document.getElementById('pagination-controls');
            const pageInfo = document.getElementById('page-info');
            const prevPageBtn = document.getElementById('prev-page');
            const nextPageBtn = document.getElementById('next-page');

            let state = {
                search: '',
                page: 1,
                role: new URLSearchParams(window.location.search).get('role') || 'all' // Get role from URL
            };
            let debounceTimer;
            let userToDeleteId = null;

            // --- Modal Functions ---
            function openModal(modal) {
                modal.classList.add('open');
            }

            function closeModal(modal) {
                modal.classList.remove('open');
                // Clear form after closing edit/add modal
                if (modal.id === 'user-modal') {
                    userForm.reset();
                    userIdInput.value = '';
                    // Re-enable email field for new user if it was disabled for editing
                    emailInput.disabled = false;
                    // Adjust placeholder for password
                    senhaInput.placeholder = 'Deixe em branco para não alterar a senha';
                    document.querySelector('#user-form p.text-xs').textContent = 'Ao adicionar, uma senha padrão será gerada se deixado em branco.';
                }
            }

            document.querySelectorAll('.modal-close-btn').forEach(btn => {
                btn.addEventListener('click', (e) => {
                    closeModal(e.target.closest('.modal-overlay'));
                });
            });

            // Close modal when clicking outside
            userModal.addEventListener('click', (e) => {
                if (e.target === userModal) closeModal(userModal);
            });
            deleteModal.addEventListener('click', (e) => {
                if (e.target === deleteModal) closeModal(deleteModal);
            });

            // --- Fetch Users ---
            const fetchUsers = async () => {
                loadingState.style.display = 'block';
                emptyState.style.display = 'none';
                paginationControls.classList.add('hidden');
                usersTbody.innerHTML = '';
                
                try {
                    // Include the role filter in the API call
                    const url = `/api/admin_api.php?action=get_users&search=${encodeURIComponent(state.search)}&page=${state.page}&role=${state.role}`;
                    const response = await fetch(url);

                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    const data = await response.json();

                    if (data.users.length === 0) {
                        emptyState.style.display = 'block';
                    } else {
                        data.users.forEach(user => {
                            const tr = document.createElement('tr');
                            
                            // ALTERAÇÃO: Lógica para classes e texto do tipo de usuário
                            let userTypeText = user.tipo;
                            let userTypeClass = 'bg-blue-900/30 text-blue-300'; // Padrão para 'usuario' (Cliente)

                            if (user.tipo === 'admin') {
                                userTypeClass = 'bg-primary/20'; // Admin
                                userTypeText = 'Admin';
                            } else if (user.tipo === 'infoprodutor') {
                                userTypeClass = 'bg-primary/20'; // Infoprodutor
                                userTypeText = 'Infoprodutor';
                            } else if (user.tipo === 'usuario') {
                                userTypeText = 'Cliente Final';
                            }
                            // FIM DA ALTERAÇÃO

                            tr.innerHTML = `
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm font-medium text-white">${user.nome || 'N/A'}</div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-400">${user.usuario}</div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <div class="text-sm text-gray-400">${user.telefone || 'N/A'}</div>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full ${userTypeClass}">${userTypeText}</span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                                    <button data-id="${user.id}" class="edit-btn mr-2" style="color: var(--accent-primary);" onmouseover="this.style.color='var(--accent-primary-hover)'" onmouseout="this.style.color='var(--accent-primary)'">
                                        <i data-lucide="edit" class="w-5 h-5 inline-block"></i>
                                    </button>
                                    <button data-id="${user.id}" data-name="${user.nome || user.usuario}" class="delete-btn text-red-400 hover:text-red-300">
                                        <i data-lucide="trash-2" class="w-5 h-5 inline-block"></i>
                                    </button>
                                </td>
                            `;
                            usersTbody.appendChild(tr);
                        });
                        lucide.createIcons(); // Re-render Lucide icons for new content
                        updatePagination(data.pagination);
                    }
                } catch (error) {
                    console.error('Erro ao buscar usuários:', error);
                    usersTbody.innerHTML = '<tr><td colspan="5" class="text-center py-10 text-red-400">Falha ao carregar os dados.</td></tr>';
                } finally {
                    loadingState.style.display = 'none';
                }
            };

            // --- Pagination Update ---
            function updatePagination(paginationData) {
                if (!paginationData || paginationData.totalPages <= 1) {
                    paginationControls.classList.add('hidden');
                    return;
                }
                paginationControls.classList.remove('hidden');
                pageInfo.textContent = `Página ${paginationData.currentPage} de ${paginationData.totalPages}`;
                prevPageBtn.disabled = paginationData.currentPage <= 1;
                nextPageBtn.disabled = paginationData.currentPage >= paginationData.totalPages;
            }

            // --- Add/Edit User Logic ---
            addUserBtn.addEventListener('click', () => {
                modalTitle.textContent = 'Adicionar Usuário';
                userForm.action = '/api/admin_api.php?action=create_user';
                senhaInput.placeholder = 'Digite uma senha (será hashificada)';
                document.querySelector('#user-form p.text-xs').textContent = 'Ao adicionar, uma senha padrão será gerada se deixado em branco.';
                emailInput.disabled = false; // Enable email for new user
                if (planoSelect) planoSelect.value = ''; // Limpar plano ao adicionar novo usuário
                if (planoHidden) planoHidden.value = ''; // Limpar campo hidden também
                togglePlanoField(); // Atualizar visibilidade do campo de plano
                openModal(userModal);
            });

            usersTbody.addEventListener('click', async (e) => {
                const editButton = e.target.closest('.edit-btn');
                if (editButton) {
                    const userId = editButton.dataset.id;
                    try {
                        const response = await fetch(`/api/admin_api.php?action=get_user_details&id=${userId}`);
                        if (!response.ok) throw new Error('Failed to fetch user details');
                        const user = await response.json();
                        
                        modalTitle.textContent = 'Editar Usuário';
                        userForm.action = '/api/admin_api.php?action=update_user';
                        userIdInput.value = user.id;
                        nomeInput.value = user.nome;
                        emailInput.value = user.usuario; // 'usuario' field holds email
                        
                        // ### ALTERAÇÃO PRINCIPAL AQUI ###
                        // Mudei de 'true' para 'false' para permitir a edição do e-mail
                        emailInput.disabled = false; 
                        
                        telefoneInput.value = user.telefone || '';
                        senhaInput.value = ''; // Clear password field on edit
                        senhaInput.placeholder = 'Deixe em branco para não alterar a senha';
                        document.querySelector('#user-form p.text-xs').textContent = 'Deixe em branco para manter a senha atual.';
                        tipoSelect.value = user.tipo;
                        togglePlanoField(); // Atualizar visibilidade do campo de plano
                        
                        // Preencher plano atual se existir
                        if (planoSelect && planoHidden && user.plano_id) {
                            planoSelect.value = user.plano_id;
                            planoHidden.value = user.plano_id;
                        } else if (planoSelect && planoHidden) {
                            planoSelect.value = '';
                            planoHidden.value = '';
                        }
                        
                        openModal(userModal);
                    } catch (error) {
                        console.error('Erro ao carregar detalhes do usuário:', error);
                        alert('Erro ao carregar detalhes do usuário.');
                    }
                }

                const deleteButton = e.target.closest('.delete-btn');
                if (deleteButton) {
                    userToDeleteId = deleteButton.dataset.id;
                    deleteUserName.textContent = deleteButton.dataset.name;
                    openModal(deleteModal);
                }
            });

            userForm.addEventListener('submit', async (e) => {
                e.preventDefault();
                const formData = new FormData(userForm);
                
                // Esta verificação não é mais estritamente necessária se o campo está sempre habilitado,
                // mas não causa mal. Se o campo estivesse desabilitado, isso garantiria que o valor é enviado.
                // Como agora está habilitado, o FormData() já o captura.
                if (emailInput.disabled) { 
                    formData.set('email', emailInput.value); 
                }

                const data = {};
                formData.forEach((value, key) => {
                    data[key] = value;
                });
                
                // Garantir que plano_id seja sempre enviado (mesmo se campo estiver oculto)
                if (!data.hasOwnProperty('plano_id')) {
                    data['plano_id'] = '';
                }
                
                // Se o tipo não for infoprodutor, garantir que plano_id seja vazio
                if (data['tipo'] !== 'infoprodutor') {
                    data['plano_id'] = '';
                }
                
                // Adicionar token CSRF - verificar múltiplas fontes
                const csrfToken = window.csrfToken || document.getElementById('csrf-token')?.value || '';
                if (!csrfToken) {
                    console.error('ERRO: Token CSRF não encontrado!');
                    alert('Erro: Token CSRF não encontrado. Recarregue a página.');
                    return;
                }
                data['csrf_token'] = csrfToken;

                try {
                    const response = await fetch(userForm.action, {
                        method: 'POST',
                        headers: { 
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': csrfToken || window.csrfToken || document.getElementById('csrf-token')?.value || ''
                        },
                        body: JSON.stringify(data),
                    });

                    if (!response.ok) {
                        const errorData = await response.json();
                        throw new Error(errorData.error || 'Erro ao salvar usuário.');
                    }

                    const result = await response.json();
                    alert(result.message);
                    closeModal(userModal);
                    state.page = 1; // Go back to first page after add/edit
                    fetchUsers();
                } catch (error) {
                    console.error('Erro:', error);
                    alert(error.message);
                }
            });

            confirmDeleteBtn.addEventListener('click', async () => {
                try {
                    const deleteCsrfToken = window.csrfToken || document.getElementById('csrf-token')?.value || '';
                    if (!deleteCsrfToken) {
                        console.error('ERRO: Token CSRF não encontrado para deletar!');
                        alert('Erro: Token CSRF não encontrado. Recarregue a página.');
                        return;
                    }
                    const response = await fetch(`/api/admin_api.php?action=delete_user`, {
                        method: 'POST',
                        headers: { 
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': deleteCsrfToken
                        },
                        body: JSON.stringify({ 
                            user_id: userToDeleteId,
                            csrf_token: deleteCsrfToken
                        }),
                    });

                    if (!response.ok) {
                        const errorData = await response.json();
                        throw new Error(errorData.error || 'Erro ao deletar usuário.');
                    }

                    const result = await response.json();
                    alert(result.message);
                    closeModal(deleteModal);
                    state.page = 1; // Go back to first page after delete
                    fetchUsers();
                } catch (error) {
                    console.error('Erro:', error);
                    alert(error.message);
                }
            });

            // --- Search and Pagination Event Listeners ---
            searchInput.addEventListener('keyup', () => {
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(() => {
                    state.search = searchInput.value;
                    state.page = 1;
                    fetchUsers();
                }, 500); // 500ms debounce
            });

            prevPageBtn.addEventListener('click', () => {
                if (state.page > 1) {
                    state.page--;
                    fetchUsers();
                }
            });

            nextPageBtn.addEventListener('click', () => {
                if (!nextPageBtn.disabled) { // Check if not already on the last page
                    state.page++;
                    fetchUsers();
                }
            });

            // Initial fetch
            fetchUsers();
        });
    </script>
</body>
</html>