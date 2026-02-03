<?php
require_once __DIR__ . '/../../config/config.php';

// Incluir helper de segurança para funções CSRF
require_once __DIR__ . '/../../helpers/security_helper.php';

// Proteção da página: usuários logados podem acessar (exceto admin).
// Administradores são redirecionados para o painel de admin.
// Usuários não logados são redirecionados para a tela de login da área de membros.
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: /member_login");
    exit;
}

// Se for um administrador logado, redireciona para o painel de admin, pois não deve acessar a área de membros.
if (isset($_SESSION["tipo"]) && $_SESSION["tipo"] === 'admin') {
    header("location: /admin");
    exit;
}

$cliente_email = $_SESSION['usuario']; 
$cliente_nome = $_SESSION['nome'] ?? $cliente_email;
$usuario_id = $_SESSION['id'] ?? 0;
$usuario_tipo = $_SESSION['tipo'] ?? ''; 

$mensagem_erro = '';
$curso = null;
$modulos_com_aulas = [];
$total_aulas_desbloqueadas = 0; // Total de aulas DESBLOQUEADAS para cálculo de progresso
$aulas_concluidas_desbloqueadas = 0; // Aulas concluídas que estão DESBLOQUEADAS
$progresso_percentual = 0;
$upload_dir = 'uploads/';
$aula_files_dir_public = 'uploads/aula_files/'; // Caminho público para download

// Valida o ID do produto
if (!isset($_GET['produto_id']) || !is_numeric($_GET['produto_id'])) {
    $mensagem_erro = "ID do curso inválido. Por favor, volte ao painel.";
} else {
    $produto_id = (int)$_GET['produto_id'];

    try {
        // 1. Verifica se o usuário tem acesso a este produto/curso
        // Acesso pode ser via alunos_acessos (comprou) OU se é infoprodutor e criou o produto
        $acesso_info = null;
        $data_concessao = null;
        
        // Primeiro verifica se comprou (está em alunos_acessos)
        $stmt_acesso = $pdo->prepare("
            SELECT data_concessao FROM alunos_acessos 
            WHERE aluno_email = ? AND produto_id = ?
        ");
        $stmt_acesso->execute([$cliente_email, $produto_id]);
        $acesso_info = $stmt_acesso->fetch(PDO::FETCH_ASSOC);
        
        // Se não encontrou em alunos_acessos, verifica se é infoprodutor e criou o produto
        if (!$acesso_info && $usuario_tipo === 'infoprodutor') {
            $stmt_produto = $pdo->prepare("
                SELECT data_criacao, usuario_id FROM produtos 
                WHERE id = ? AND usuario_id = ?
            ");
            $stmt_produto->execute([$produto_id, $usuario_id]);
            $produto_info = $stmt_produto->fetch(PDO::FETCH_ASSOC);
            
            if ($produto_info) {
                // Infoprodutor criou o produto, usar data_criacao como data_concessao
                $acesso_info = ['data_concessao' => $produto_info['data_criacao']];
            }
        }

        if (!$acesso_info) {
            $mensagem_erro = "Você não tem acesso a este curso. Se acredita que é um erro, entre em contato com o suporte.";
        } else {
            $data_concessao = new DateTime($acesso_info['data_concessao']);
            $current_date = new DateTime();

            // 2. Busca os detalhes do curso e o produto associado
            $stmt_curso = $pdo->prepare("
                SELECT c.*, p.nome as produto_nome, p.descricao as produto_descricao, p.foto as produto_foto 
                FROM cursos c
                JOIN produtos p ON c.produto_id = p.id
                WHERE p.id = ? AND p.tipo_entrega = 'area_membros'
            ");
            $stmt_curso->execute([$produto_id]);
            $curso = $stmt_curso->fetch(PDO::FETCH_ASSOC);

            if (!$curso) {
                $mensagem_erro = "Curso não encontrado ou não está configurado como 'Área de Membros'.";
            } else {
                if (!isset($curso['allow_comments'])) $curso['allow_comments'] = 0;
                if (!isset($curso['community_enabled'])) $curso['community_enabled'] = 0;
                // 3. Verificar se existe coluna secao_id e tabela secoes
                $has_secao_col = false;
                $secoes = [];
                $stmt_t = $pdo->query("SHOW TABLES LIKE 'secoes'");
                if ($stmt_t->rowCount() > 0) {
                    $chk = $pdo->query("SHOW COLUMNS FROM modulos LIKE 'secao_id'");
                    $has_secao_col = $chk->rowCount() > 0;
                    // Verificar se coluna tipo_capa existe
                    $has_tipo_capa_col = false;
                    try {
                        $chk_tipo_capa = $pdo->query("SHOW COLUMNS FROM secoes LIKE 'tipo_capa'");
                        $has_tipo_capa_col = $chk_tipo_capa->rowCount() > 0;
                    } catch (PDOException $e) {}
                    
                    if ($has_tipo_capa_col) {
                        $stmt_secoes = $pdo->prepare("SELECT id, curso_id, titulo, tipo_secao, ordem, conteudo_extra, tipo_capa FROM secoes WHERE curso_id = ? ORDER BY ordem ASC, id ASC");
                    } else {
                        $stmt_secoes = $pdo->prepare("SELECT id, curso_id, titulo, tipo_secao, ordem, conteudo_extra FROM secoes WHERE curso_id = ? ORDER BY ordem ASC, id ASC");
                    }
                    $stmt_secoes->execute([$curso['id']]);
                    $secoes = $stmt_secoes->fetchAll(PDO::FETCH_ASSOC);
                    // Garantir que tipo_capa existe no array (padrão 'vertical' se não existir)
                    foreach ($secoes as &$s) {
                        if (!isset($s['tipo_capa'])) {
                            $s['tipo_capa'] = 'vertical';
                        }
                    }
                    unset($s);
                }

                // 4. Busca os módulos do curso (incluindo secao_id se existir)
                $stmt_modulos = $pdo->prepare("SELECT id, curso_id, titulo, imagem_capa_url, ordem, release_days, is_paid_module, linked_product_id" . ($has_secao_col ? ", secao_id" : "") . " FROM modulos WHERE curso_id = ? ORDER BY ordem ASC, id ASC");
                $stmt_modulos->execute([$curso['id']]);
                $modulos = $stmt_modulos->fetchAll(PDO::FETCH_ASSOC);

                // 5. Para cada módulo, busca as aulas e o progresso do aluno
                foreach ($modulos as $modulo) {
                    if (!isset($modulo['secao_id'])) $modulo['secao_id'] = null;
                    // NEW: Verificar acesso a módulos pagos
                    $modulo['is_paid_module'] = (bool)($modulo['is_paid_module'] ?? 0);
                    $modulo['linked_product_id'] = $modulo['linked_product_id'] ?? null;
                    $modulo['produto_atrelado'] = null;
                    $has_access_to_linked_product = false;
                    
                    if ($modulo['is_paid_module'] && $modulo['linked_product_id']) {
                        // Buscar informações do produto atrelado (incluindo checkout_hash e checkout_config)
                        $stmt_prod = $pdo->prepare("SELECT id, nome, preco, checkout_hash, checkout_config FROM produtos WHERE id = ?");
                        $stmt_prod->execute([$modulo['linked_product_id']]);
                        $produto_data = $stmt_prod->fetch(PDO::FETCH_ASSOC);
                        
                        // Se não encontrou checkout_hash, tentar buscar de produto_ofertas
                        if ($produto_data && empty($produto_data['checkout_hash'])) {
                            $stmt_oferta = $pdo->prepare("SELECT checkout_hash FROM produto_ofertas WHERE produto_id = ? AND is_active = 1 LIMIT 1");
                            $stmt_oferta->execute([$modulo['linked_product_id']]);
                            $oferta = $stmt_oferta->fetch(PDO::FETCH_ASSOC);
                            if ($oferta) {
                                $produto_data['checkout_hash'] = $oferta['checkout_hash'];
                            }
                        }
                        
                        $modulo['produto_atrelado'] = $produto_data;
                        
                        // Verificar se o aluno tem acesso ao produto atrelado
                        if ($modulo['produto_atrelado']) {
                            $stmt_check_access = $pdo->prepare("SELECT COUNT(*) FROM alunos_acessos WHERE aluno_email = ? AND produto_id = ?");
                            $stmt_check_access->execute([$cliente_email, $modulo['linked_product_id']]);
                            $has_access_to_linked_product = $stmt_check_access->fetchColumn() > 0;
                        }
                    }
                    
                    // Calcula a data de liberação do módulo (só se não for módulo pago sem acesso)
                    if ($modulo['is_paid_module'] && !$has_access_to_linked_product) {
                        // Módulo pago sem acesso - sempre bloqueado
                        $modulo['is_locked'] = true;
                        $modulo['available_at'] = null;
                        $modulo['lock_reason'] = 'paid_module_no_access';
                    } else {
                        // Módulo normal ou módulo pago com acesso - verificar release_days
                        $module_release_date = clone $data_concessao;
                        $module_release_date->modify("+{$modulo['release_days']} days");
                        $modulo['is_locked'] = ($current_date < $module_release_date);
                        $modulo['available_at'] = $module_release_date->format('d/m/Y H:i');
                        $modulo['lock_reason'] = $modulo['is_locked'] ? 'release_days' : null;
                    }

                    // MODIFICADO: Incluir 'tipo_conteudo', 'download_protegido', 'download_link', 'termos_consentimento' na consulta das aulas
                    $stmt_aulas = $pdo->prepare("SELECT id, modulo_id, titulo, url_video, descricao, ordem, release_days, tipo_conteudo, download_protegido, download_link, termos_consentimento FROM aulas WHERE modulo_id = ? ORDER BY ordem ASC, id ASC");
                    $stmt_aulas->execute([$modulo['id']]);
                    $aulas = $stmt_aulas->fetchAll(PDO::FETCH_ASSOC);
                    
                    $aulas_com_progresso = [];
                    foreach ($aulas as $aula) {
                        // Calcula a data de liberação da aula
                        $lesson_release_date = clone $data_concessao;
                        $lesson_release_date->modify("+{$aula['release_days']} days");
                        $aula['is_locked'] = ($current_date < $lesson_release_date);
                        $aula['available_at'] = $lesson_release_date->format('d/m/Y H:i');

                        // Soma apenas as aulas que estão DESBLOQUEADAS para o cálculo do progresso geral
                        if (!$aula['is_locked']) {
                            $total_aulas_desbloqueadas++;
                        }

                        $stmt_progresso = $pdo->prepare("SELECT COUNT(*) FROM aluno_progresso WHERE aluno_email = ? AND aula_id = ?");
                        $stmt_progresso->execute([$cliente_email, $aula['id']]);
                        $aula['concluida'] = $stmt_progresso->fetchColumn() > 0;
                        if ($aula['concluida'] && !$aula['is_locked']) { // Só conta como concluída se estiver desbloqueada
                            $aulas_concluidas_desbloqueadas++;
                        }

                        // NOVO: Busca arquivos da aula
                        $stmt_files = $pdo->prepare("SELECT id, nome_original, nome_salvo FROM aula_arquivos WHERE aula_id = ? ORDER BY ordem ASC, id ASC");
                        $stmt_files->execute([$aula['id']]);
                        $aula['files'] = $stmt_files->fetchAll(PDO::FETCH_ASSOC);

                        $aulas_com_progresso[] = $aula;
                    }

                    $modulos_com_aulas[] = [
                        'modulo' => $modulo,
                        'aulas' => $aulas_com_progresso
                    ];
                }

                // 6. Agrupar por seção (se existir tabela secoes e secao_id)
                $secoes_com_conteudo = [];
                $modulos_sem_secao = [];
                $lista_modulos_para_js = [];
                $tem_secoes = $has_secao_col && !empty($secoes);

                if ($tem_secoes) {
                    $secao_ids = array_column($secoes, 'id');
                    foreach ($secoes as $secao) {
                        $bloco = ['secao' => $secao, 'modulos_com_aulas' => [], 'produtos' => []];
                        if ($secao['tipo_secao'] === 'curso') {
                            $bloco['modulos_com_aulas'] = array_filter($modulos_com_aulas, function ($item) use ($secao) {
                                return isset($item['modulo']['secao_id']) && (int)$item['modulo']['secao_id'] === (int)$secao['id'];
                            });
                            $bloco['modulos_com_aulas'] = array_values($bloco['modulos_com_aulas']);
                            foreach ($bloco['modulos_com_aulas'] as $item) {
                                $lista_modulos_para_js[] = $item;
                            }
                        } elseif ($secao['tipo_secao'] === 'outros_produtos') {
                            // Verificar se colunas existem
                            $has_imagem_col = false;
                            $has_link_col = false;
                            try {
                                $chk_col = $pdo->query("SHOW COLUMNS FROM secao_produtos LIKE 'imagem_capa_url'");
                                $has_imagem_col = $chk_col->rowCount() > 0;
                                $chk_col_link = $pdo->query("SHOW COLUMNS FROM secao_produtos LIKE 'link_personalizado'");
                                $has_link_col = $chk_col_link->rowCount() > 0;
                            } catch (PDOException $e) {}
                            
                            if ($has_imagem_col && $has_link_col) {
                                $stmt_sp = $pdo->prepare("SELECT sp.produto_id, sp.ordem, sp.imagem_capa_url, sp.link_personalizado FROM secao_produtos sp WHERE sp.secao_id = ? ORDER BY sp.ordem ASC, sp.id ASC");
                            } elseif ($has_imagem_col) {
                                $stmt_sp = $pdo->prepare("SELECT sp.produto_id, sp.ordem, sp.imagem_capa_url FROM secao_produtos sp WHERE sp.secao_id = ? ORDER BY sp.ordem ASC, sp.id ASC");
                            } else {
                                $stmt_sp = $pdo->prepare("SELECT sp.produto_id, sp.ordem FROM secao_produtos sp WHERE sp.secao_id = ? ORDER BY sp.ordem ASC, sp.id ASC");
                            }
                            $stmt_sp->execute([$secao['id']]);
                            $sp_rows = $stmt_sp->fetchAll(PDO::FETCH_ASSOC);
                            foreach ($sp_rows as $sp) {
                                $stmt_p = $pdo->prepare("SELECT p.id, p.nome, p.preco, p.checkout_hash, p.checkout_config, p.foto FROM produtos p WHERE p.id = ?");
                                $stmt_p->execute([$sp['produto_id']]);
                                $prod = $stmt_p->fetch(PDO::FETCH_ASSOC);
                                if ($prod) {
                                    // Adicionar imagem_capa_url da seção se existir
                                    if ($has_imagem_col && isset($sp['imagem_capa_url'])) {
                                        $prod['imagem_capa_url'] = $sp['imagem_capa_url'];
                                    }
                                    // Adicionar link_personalizado da seção se existir
                                    if ($has_link_col && isset($sp['link_personalizado']) && !empty($sp['link_personalizado'])) {
                                        $prod['link_personalizado'] = $sp['link_personalizado'];
                                    }
                                    if (empty($prod['checkout_hash'])) {
                                        $stmt_o = $pdo->prepare("SELECT checkout_hash FROM produto_ofertas WHERE produto_id = ? AND is_active = 1 LIMIT 1");
                                        $stmt_o->execute([$prod['id']]);
                                        $oferta = $stmt_o->fetch(PDO::FETCH_ASSOC);
                                        if ($oferta) $prod['checkout_hash'] = $oferta['checkout_hash'];
                                    }
                                    $stmt_acc = $pdo->prepare("SELECT COUNT(*) FROM alunos_acessos WHERE aluno_email = ? AND produto_id = ?");
                                    $stmt_acc->execute([$cliente_email, $prod['id']]);
                                    $prod['tem_acesso'] = $stmt_acc->fetchColumn() > 0;
                                    $bloco['produtos'][] = $prod;
                                }
                            }
                        }
                        $secoes_com_conteudo[] = $bloco;
                    }
                    $modulos_sem_secao = array_values(array_filter($modulos_com_aulas, function ($item) {
                        return empty($item['modulo']['secao_id']);
                    }));
                    foreach ($modulos_sem_secao as $item) {
                        $lista_modulos_para_js[] = $item;
                    }
                } else {
                    $lista_modulos_para_js = $modulos_com_aulas;
                }

                if ($total_aulas_desbloqueadas > 0) {
                    $progresso_percentual = round(($aulas_concluidas_desbloqueadas / $total_aulas_desbloqueadas) * 100);
                }
            }
        }
    } catch (PDOException $e) {
        $mensagem_erro = "Erro de banco de dados: " . htmlspecialchars($e->getMessage());
    }
}

// Gerar token CSRF ANTES de qualquer output HTML (para evitar erro de headers already sent)
$csrf_token_js = generate_csrf_token();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($curso['produto_nome'] ?? $curso['titulo'] ?? 'Curso'); ?> - Área de Membros</title>
    <?php
    // Carregar configurações do sistema (inclui cor primária)
    include __DIR__ . '/../../config/load_settings.php';
    
    // Adiciona favicon se configurado
    $favicon_url_raw = getSystemSetting('favicon_url', '');
    if (!empty($favicon_url_raw)) {
        $favicon_url = ltrim($favicon_url_raw, '/');
        if (strpos($favicon_url, 'http') !== 0) {
            if (strpos($favicon_url, 'uploads/') === 0) {
                $favicon_url = '/' . $favicon_url;
            } else {
                $favicon_url = '/' . $favicon_url;
            }
        }
        $favicon_ext = strtolower(pathinfo($favicon_url, PATHINFO_EXTENSION));
        $favicon_type = 'image/x-icon';
        if ($favicon_ext === 'png') {
            $favicon_type = 'image/png';
        } elseif ($favicon_ext === 'svg') {
            $favicon_type = 'image/svg+xml';
        }
        echo '<link rel="icon" type="' . htmlspecialchars($favicon_type) . '" href="' . htmlspecialchars($favicon_url) . '">' . "\n";
    }
    ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .prose { /* TailwindCSS Typography plugin classes can be added here or in global CSS */
            --tw-prose-body: #d1d5db; 
            --tw-prose-headings: #f9fafb; 
            --tw-prose-links: #fb923c; 
        }
        .module-card.active { box-shadow: 0 0 20px var(--accent-primary); transform: scale(1.02); }
        .module-card:hover { box-shadow: 0 4px 20px rgba(0,0,0,0.4); }
        /* Player (dentro da aula): fundo neutro sem azulado */
        #player-wrapper .player-card { background-color: #141414; border: 1px solid #262626; }
        #player-wrapper .player-card .border-gray-700 { border-color: #262626 !important; }
        #player-wrapper .player-aside { background-color: #141414; border: 1px solid #262626; }
        #player-wrapper .lesson-item:hover { background-color: #1a1a1a !important; }
        #player-wrapper .progress-track { background-color: #262626 !important; }
        #player-wrapper input.bg-gray-700, #player-wrapper textarea.bg-gray-700 { background-color: #1a1a1a !important; border-color: #333 !important; }
        /* Carrossel: esconder scrollbar */
        .modules-carousel-track { scrollbar-width: none; -ms-overflow-style: none; }
        .modules-carousel-track::-webkit-scrollbar { display: none; }
        .lesson-item.active { background-color: #7c2d12; color: #ffedd5; font-weight: 600; }
        .lesson-item.active .lucide-play-circle { color: #fdba74; }
        .aspect-video { aspect-ratio: 16 / 9; }
        .header-bg {
            background: linear-gradient(to right, var(--accent-primary), var(--accent-primary-hover));
        }
        .lesson-item.locked { 
            cursor: not-allowed; 
            opacity: 0.6; 
            background-color: #2d3748; /* Mais escuro para indicar bloqueio */
        }
        .lesson-item.locked:hover {
            background-color: #2d3748; /* Não muda ao hover */
        }
        .lesson-item.locked .lucide-play-circle, .lesson-item.locked .lucide-lock, .lesson-item.locked .lucide-file-text {
            color: #718096; /* Cinza para ícones bloqueados */
        }
        
        /* ===== INÍCIO: PLAYER YOUTUBE CUSTOMIZADO (CSS DO YMin) ===== */
        .ymin{
         --aspect:16/9; --crop:2000px; --accent:var(--accent-primary); --bar-color:var(--accent); --track-color:#202532; /* <-- COR PRIMÁRIA DO SISTEMA */
         position:relative; width:100%; aspect-ratio:var(--aspect); background:#000; overflow:hidden;
         /* Adicionado para se encaixar no layout */
         border-radius: 0.75rem; /* 12px */
         box-shadow: 0 20px 25px -5px rgb(0 0 0 / 0.1), 0 8px 10px -6px rgb(0 0 0 / 0.1);
        }
        .ymin .frame{position:relative;width:100%;height:100%;background:#000;overflow:hidden}
        .ymin iframe{position:absolute;inset:0;width:100%;height:calc(100% + var(--crop));top:calc(var(--crop)*-0.5);border:0;display:block;opacity:0;transition:opacity .18s ease}
        .ymin.ready iframe{opacity:1}
        .ymin .veil{position:absolute;inset:0;background:#000;z-index:8;opacity:1;transition:opacity .18s ease}
        .ymin.ready .veil{opacity:0;pointer-events:none}
        .ymin .clickzone{position:absolute;inset:0;z-index:9}

        /* Capas (com ícone) */
        .ymin .overlay{position:absolute;inset:0;z-index:10;display:grid;place-items:center;background:rgba(0,0,0,.5);pointer-events:none}
        .ymin .overlay[hidden]{display:none}
.ymin .cover{display:grid;place-items:center;text-align:center}
.ymin .icon{width:110px;max-width:26vw;height:auto;filter:drop-shadow(0 10px 28px rgba(0,0,0,.6));animation:pulse 1.6s ease-in-out infinite;
         filter: brightness(0) invert(1); /* <-- FORÇA O ÍCONE GRANDE DE PLAY A SER BRANCO */
        }
@keyframes pulse{0%{transform:scale(1)}50%{transform:scale(1.06)}100%{transform:scale(1)}}

        /* HUD + barra (interativa) */
        .ymin .hud.ui{position:absolute;left:0;right:0;bottom:0;z-index:12;height:10px;pointer-events:auto}
        .ymin .progress{position:absolute;left:0;right:0;bottom:0;height:10px;background:var(--track-color);border:0;overflow:hidden;cursor:pointer}
        .ymin .progress .bar{position:absolute;left:0;top:0;bottom:0;width:0;background:var(--bar-color);transition:width .08s linear}

        .ymin .timecode.ui{
         position:absolute; left:12px; bottom:14px; z-index:13;
         padding:4px 8px; border-radius:8px; background:rgba(0,0,0,.55);
         color:#fff; font:600 12px/1.2 system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,"Helvetica Neue",Arial,"Noto Sans","Apple Color Emoji","Segoe UI Emoji"; /* <-- COR DO TEXTO (BRANCO) */
        }

        .ymin .ctrls-right.ui{
         position:absolute; right:10px; bottom:12px; z-index:13; display:flex; gap:8px;
        }
        .ymin .btn{
         width:40px; height:40px; border:0; border-radius:10px; background:var(--accent); color:#fff; /* <-- COR DO BOTÃO (LARANJA) E ÍCONE (BRANCO) */
         display:grid; place-items:center; cursor:pointer; box-shadow:0 6px 18px rgba(0,0,0,.35);
         transition:transform .12s ease, filter .12s ease;
        }
        .ymin .btn:hover{transform:translateY(-1px);filter:brightness(.9)}
.ymin .btn img{width:22px;height:22px;display:block;pointer-events:none;
         filter: brightness(0) invert(1); /* <-- FORÇA OS ÍCONES DOS BOTÕES A SEREM BRANCOS */
        }

:fullscreen .ymin .frame{aspect-ratio:auto;height:100vh}
        :-webkit-full-screen .ymin .frame{aspect-ratio:auto;height:100vh}

        .ymin .ui{opacity:1;transition:opacity .18s ease, transform .18s ease}
        .ymin.controls-hidden .ui{opacity:0; transform:translateY(12px); pointer-events:none}

        /* ===== Vertical (Shorts) ===== */
        .ymin.vertical{
         --aspect:9/16;
         width:min(520px, 100%);
         max-height:84vh;
         margin:0 auto;
         border-radius:14px;
        }
        .ymin.vertical iframe{
         width:calc(100% + var(--crop));
         height:100%;
         left:calc(var(--crop)*-0.5);
         top:0;
        }
        /* ===== FIM: PLAYER YOUTUBE CUSTOMIZADO (CSS DO YMin) ===== */
        
        /* ===== MELHORIAS PROFISSIONAIS DO PLAYER ===== */
        /* Controles de Velocidade e Qualidade */
        .ymin .speed-menu, .ymin .quality-menu {
            position: absolute;
            bottom: 60px;
            right: 10px;
            background: rgba(0, 0, 0, 0.85);
            border-radius: 8px;
            padding: 8px 0;
            min-width: 120px;
            z-index: 15;
            display: none;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.5);
        }
        .ymin .speed-menu.show, .ymin .quality-menu.show {
            display: block;
        }
        .ymin .speed-menu button, .ymin .quality-menu button {
            width: 100%;
            padding: 8px 16px;
            background: transparent;
            border: 0;
            color: #fff;
            text-align: left;
            cursor: pointer;
            font-size: 14px;
            transition: background 0.2s;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .ymin .speed-menu button:hover, .ymin .quality-menu button:hover {
            background: rgba(255, 255, 255, 0.1);
        }
        .ymin .speed-menu button.active, .ymin .quality-menu button.active {
            background: var(--accent);
            color: #fff;
        }
        .ymin .speed-menu button.active::after, .ymin .quality-menu button.active::after {
            content: '✓';
            margin-left: 8px;
        }
        
        /* Tooltips melhorados */
        .ymin .btn {
            position: relative;
        }
        .ymin .btn[title]:hover::after {
            content: attr(title);
            position: absolute;
            bottom: 100%;
            right: 0;
            margin-bottom: 8px;
            padding: 6px 10px;
            background: rgba(0, 0, 0, 0.9);
            color: #fff;
            font-size: 12px;
            white-space: nowrap;
            border-radius: 4px;
            pointer-events: none;
            z-index: 20;
        }
        
        /* Loading Spinner */
        .ymin .loading-spinner {
            position: absolute;
            inset: 0;
            z-index: 11;
            display: grid;
            place-items: center;
            background: rgba(0, 0, 0, 0.7);
            opacity: 0;
            transition: opacity 0.3s;
            pointer-events: none;
        }
        .ymin.loading .loading-spinner {
            opacity: 1;
        }
        .ymin .loading-spinner::after {
            content: '';
            width: 48px;
            height: 48px;
            border: 4px solid rgba(255, 255, 255, 0.2);
            border-top-color: var(--accent);
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* Proteção contra gravação de tela - Torna vídeo preto na gravação */
        .ymin .screen-capture-blocker {
            position: absolute;
            inset: 0;
            z-index: 9999;
            background: #000;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.1s;
        }
        .ymin.screen-capturing .screen-capture-blocker {
            opacity: 1;
            pointer-events: auto;
        }
        /* Garantir que iframe fique preto durante captura */
        .ymin.screen-capturing iframe {
            filter: brightness(0) !important;
            opacity: 0 !important;
        }
        .ymin.screen-capturing .frame::before {
            content: '';
            position: absolute;
            inset: 0;
            background: #000;
            z-index: 99998;
            pointer-events: none;
        }
        
        /* Melhorias de Responsividade Mobile */
        @media (max-width: 768px) {
            .ymin .ctrls-right.ui {
                gap: 6px;
                right: 8px;
                bottom: 10px;
            }
            .ymin .btn {
                width: 36px;
                height: 36px;
            }
            .ymin .btn img {
                width: 18px;
                height: 18px;
            }
            .ymin .speed-menu, .ymin .quality-menu {
                right: 8px;
                bottom: 54px;
                min-width: 100px;
            }
        }
        
        /* Animações melhoradas */
        .ymin .ui {
            transition: opacity 0.2s ease, transform 0.2s ease;
        }
        .ymin .btn {
            transition: transform 0.15s ease, filter 0.15s ease, background 0.2s ease;
        }
        /* ===== FIM: MELHORIAS PROFISSIONAIS DO PLAYER ===== */
    </style>
</head>
<body class="text-gray-200 antialiased" style="background-color: #0d0d0d;">

    <?php if ($mensagem_erro): ?>
        <div class="flex min-h-screen items-center justify-center p-8">
            <div class="bg-red-900 border border-red-700 text-red-200 px-6 py-4 rounded-lg text-center max-w-lg">
                <p class="font-bold text-lg">Ocorreu um Erro</p>
                <p><?php echo $mensagem_erro; ?></p>
                 <a href="/member_area_dashboard" class="mt-4 inline-block bg-red-600 text-white font-bold py-2 px-4 rounded-lg hover:bg-red-700 transition">Voltar aos Meus Cursos</a>
            </div>
        </div>
    <?php elseif (!$curso): ?>
        <div class="flex min-h-screen items-center justify-center p-8">
             <div class="bg-gray-800 border border-gray-700 text-gray-300 px-6 py-4 rounded-lg text-center">
                <p>Carregando...</p>
            </div>
        </div>
    <?php else: 
    $aba = isset($_GET['aba']) ? trim($_GET['aba']) : '';
    $aba_comunidade = ($aba === 'comunidade');
    $community_enabled = !empty($curso['community_enabled']);
    $comunidade_categorias = [];
    if ($community_enabled && $aba_comunidade) {
        $stmt_cc = $pdo->query("SHOW TABLES LIKE 'comunidade_categorias'");
        if ($stmt_cc->rowCount() > 0) {
            $stmt_cat = $pdo->prepare("SELECT id, nome, is_public_posting, ordem FROM comunidade_categorias WHERE curso_id = ? ORDER BY ordem ASC, id ASC");
            $stmt_cat->execute([$curso['id']]);
            $comunidade_categorias = $stmt_cat->fetchAll(PDO::FETCH_ASSOC);
        }
    }
    // Token CSRF já foi gerado no topo do arquivo (antes de qualquer output)
    ?>
    <meta name="csrf-token" content="<?php echo htmlspecialchars($csrf_token_js); ?>">
    <script>
        // Variável global para token CSRF
        window.csrfToken = '<?php echo htmlspecialchars($csrf_token_js); ?>';
    </script>
    <div id="course-container" class="min-h-screen flex flex-col">
        <?php if ($aba_comunidade): ?>
        <header class="w-full flex-shrink-0 text-white p-4 md:p-6 border-b border-gray-700/50" style="background-color: #0d0d0d;">
            <div class="max-w-screen-2xl mx-auto flex justify-between items-center flex-wrap gap-3">
                <div class="flex items-center space-x-4">
                    <a href="/member_area_dashboard" class="text-white/90 hover:text-white transition-colors" title="Meus Cursos">
                        <i data-lucide="arrow-left-circle" class="w-7 h-7"></i>
                    </a>
                    <h1 class="text-xl md:text-2xl font-bold"><?php echo htmlspecialchars($curso['produto_nome'] ?? $curso['titulo'] ?? 'Comunidade'); ?></h1>
                </div>
                <div class="flex items-center gap-3 md:gap-4">
                    <?php if ($community_enabled): ?>
                    <a href="/member_course_view?produto_id=<?php echo (int)$produto_id; ?>" class="px-3 py-2 rounded-lg text-gray-400 hover:text-white hover:bg-white/10 transition text-sm font-medium">Member</a>
                    <a href="/member_course_view?produto_id=<?php echo (int)$produto_id; ?>&aba=comunidade" class="px-3 py-2 rounded-lg bg-white/10 text-white text-sm font-medium">Comunidade</a>
                    <?php endif; ?>
                    <span class="font-medium hidden md:block text-gray-300">Olá, <?php echo htmlspecialchars($cliente_nome); ?>!</span>
                    <a href="/member_logout" class="flex items-center space-x-2 text-white/90 hover:text-white transition-colors px-3 py-2 rounded-lg hover:bg-white/10">
                        <i data-lucide="log-out" class="w-5 h-5"></i>
                        <span class="hidden sm:block">Sair</span>
                    </a>
                </div>
            </div>
        </header>
        <?php endif; ?>

        <div class="flex-1 flex flex-col min-w-0">
        <?php if (!$aba_comunidade):
            $show_edit_banner = false;
            $show_member_nav = true;
            $member_nav_left = '<a href="/member_area_dashboard" class="flex items-center justify-center w-10 h-10 rounded-full text-white/90 hover:text-white hover:bg-white/10 transition-colors" title="Voltar aos Meus Cursos"><i data-lucide="arrow-left-circle" class="w-7 h-7"></i></a>';
            $member_nav_right = '<span class="font-medium text-white/90 hidden sm:block">Olá, ' . htmlspecialchars($cliente_nome) . '!</span><a href="/member_logout" class="flex items-center gap-2 text-white/90 hover:text-white transition-colors px-3 py-2 rounded-lg hover:bg-white/10"><i data-lucide="log-out" class="w-5 h-5"></i><span class="hidden sm:block">Sair</span></a>';
            $member_nav_tabs = '';
            if ($community_enabled) {
                $member_nav_tabs = '<a href="/member_course_view?produto_id=' . (int)$produto_id . '" class="px-3 py-2 rounded-lg bg-white/10 text-white text-sm font-medium">Member</a>';
                $member_nav_tabs .= '<a href="/member_course_view?produto_id=' . (int)$produto_id . '&aba=comunidade" class="px-3 py-2 rounded-lg text-white/80 hover:text-white hover:bg-white/10 transition text-sm font-medium">Comunidade</a>';
            }
            include __DIR__ . '/../partials/curso_banner.php';
        ?>
        <main class="max-w-screen-2xl mx-auto p-4 md:p-8 w-full flex-1">
            <?php
            $tem_conteudo_curso = !empty($modulos_com_aulas) && $total_aulas_desbloqueadas > 0;
            $tem_conteudo_secoes = $tem_secoes && (
                !empty($modulos_sem_secao) ||
                array_reduce($secoes_com_conteudo, function ($acc, $b) {
                    return $acc || !empty($b['modulos_com_aulas']) || !empty($b['produtos']);
                }, false)
            );
            $mostrar_conteudo = $tem_conteudo_curso || $tem_conteudo_secoes;
            ?>
            <?php if (!$mostrar_conteudo): ?>
                <div class="bg-gray-800 border border-gray-700 p-8 rounded-lg text-center text-gray-400">
                    <i data-lucide="video-off" class="mx-auto w-16 h-16 text-gray-600"></i>
                    <p class="mt-4 font-semibold text-lg text-gray-200">Este curso ainda não tem conteúdo disponível.</p>
                    <p>Entre em contato com o suporte se isso for um erro ou verifique as datas de liberação.</p>
                </div>
            <?php else: ?>

                <!-- Player e Aulas (Oculto por padrão, visível após selecionar um módulo) -->
                <div id="player-wrapper" class="hidden">
                    <!-- Barra de Progresso -->
                    <div class="mb-8">
                        <div class="flex justify-between items-center mb-2">
                            <span class="text-sm font-semibold" style="color: var(--accent-primary);">SEU PROGRESSO</span>
                            <span class="text-sm font-bold text-white"><?php echo $progresso_percentual; ?>% Completo</span>
                        </div>
                        <div class="w-full bg-gray-700 rounded-full h-2.5">
                            <div class="h-2.5 rounded-full" style="width: <?php echo $progresso_percentual; ?>%; background-color: var(--accent-primary);"></div>
                        </div>
                    </div>

                    <!-- Player e Lista de Aulas -->
                    <div id="player-section" class="flex flex-col lg:flex-row gap-8 mb-12">
                        <!-- Coluna Esquerda: Player e Detalhes -->
                        <div class="lg:w-2/3 w-full">
                            
                            <!-- [INÍCIO DA MUDANÇA] Container do Player YMin -->
                            <!-- Este div será o "host" para o player YMin ou para o placeholder. -->
                            <!-- Removido 'aspect-video' daqui, pois o YMin ou o placeholder interno controlarão o aspecto. -->
                            <div id="player-host" class="bg-black rounded-xl shadow-2xl mb-6">
                                <!-- Placeholder inicial que será substituído -->
                                <div class="w-full aspect-video bg-black flex flex-col items-center justify-center text-gray-500 rounded-xl">
                                    <i data-lucide="play-circle" class="w-16 h-16 text-gray-600 mb-4"></i>
                                    <p class="text-lg font-semibold">Selecione um módulo e uma aula para começar.</p>
                                </div>
                            </div>
                            <!-- [FIM DA MUDANÇA] Container do Player YMin -->

                            <div class="player-card bg-gray-800 p-6 rounded-xl shadow-lg border border-gray-700">
                                <h2 id="lesson-title" class="text-2xl font-bold text-white mb-4">Selecione um módulo para começar</h2>
                                <div id="lesson-description" class="prose max-w-none">
                                    <p>A descrição e materiais da aula aparecerão aqui.</p>
                                </div>
                                <div class="mt-6 pt-4 border-t border-gray-700 flex justify-end">
                                    <!-- O botão agora será atualizado dinamicamente -->
                                    <button id="mark-as-complete-btn" class="text-white font-bold py-2.5 px-5 rounded-lg transition duration-300 flex items-center space-x-2 disabled:opacity-50 disabled:cursor-not-allowed hidden">
                                        <i data-lucide="check-square" class="w-5 h-5"></i>
                                        <span>Marcar como Concluída</span>
                                    </button>
                                </div>
                                <?php if (!empty($curso['allow_comments'])): ?>
                                <div id="comments-section" class="mt-8 pt-6 border-t border-gray-700 hidden">
                                    <h3 class="text-xl font-bold text-white mb-4">Comentários</h3>
                                    <form id="comment-form" class="mb-6">
                                        <input type="hidden" name="aula_id" id="comment-aula-id" value="">
                                        <input type="hidden" id="comment-author" name="autor_nome" value="<?php echo htmlspecialchars($cliente_nome ?? $cliente_email ?? ''); ?>">
                                        <div class="mb-3">
                                            <label for="comment-text" class="block text-gray-300 text-sm font-medium mb-1">Comentário</label>
                                            <textarea id="comment-text" name="texto" rows="3" class="w-full px-4 py-2 bg-gray-700 border border-gray-600 rounded-lg text-white focus:ring-2 focus:ring-offset-0 resize-none" style="--tw-ring-color: var(--accent-primary);" maxlength="5000" required placeholder="Escreva seu comentário..."></textarea>
                                        </div>
                                        <button type="submit" id="comment-submit" class="text-white font-bold py-2 px-5 rounded-lg transition" style="background-color: var(--accent-primary);">Enviar comentário</button>
                                    </form>
                                    <div id="comments-list" class="space-y-4">
                                        <p class="text-gray-400 text-sm">Carregando comentários...</p>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <!-- Coluna Direita: Lista de Aulas do Módulo Ativo -->
                        <aside class="player-aside lg:w-1/3 w-full bg-gray-800 rounded-xl shadow-lg p-4 flex-shrink-0 h-fit lg:sticky top-20 border border-gray-700">
                            <h3 id="module-title-aside" class="font-bold text-xl text-white mb-4 px-2">Aulas do Módulo</h3>
                            <div id="lesson-list-container" class="space-y-2 max-h-[70vh] overflow-y-auto pr-2">
                               <p class="text-gray-400 px-2">Selecione um módulo abaixo para ver as aulas.</p>
                            </div>
                        </aside>
                    </div>
                </div>

                <!-- Seção de Módulos / Conteúdo por Seção (Sempre visível) -->
                <div id="modules-section">
                    <?php if ($tem_secoes): ?>
                        <?php $global_module_index = 0; ?>
                        <?php foreach ($secoes_com_conteudo as $bloco): ?>
                            <?php $secao = $bloco['secao']; ?>
                            <?php if ($secao['tipo_secao'] === 'curso' && !empty($bloco['modulos_com_aulas'])): ?>
                                <div class="modules-carousel-section mb-10">
                                    <div class="flex justify-between items-center mb-4">
                                        <h2 class="text-2xl font-bold text-white"><?php echo htmlspecialchars($secao['titulo']); ?></h2>
                                        <div class="flex gap-2">
                                            <button type="button" class="carousel-prev w-10 h-10 md:w-10 md:h-10 rounded-lg bg-gray-800 hover:bg-gray-700 border border-gray-600 text-white flex items-center justify-center transition-colors" aria-label="Anterior"><i data-lucide="chevron-left" class="w-5 h-5"></i></button>
                                            <button type="button" class="carousel-next w-10 h-10 md:w-10 md:h-10 rounded-lg bg-gray-800 hover:bg-gray-700 border border-gray-600 text-white flex items-center justify-center transition-colors" aria-label="Próximo"><i data-lucide="chevron-right" class="w-5 h-5"></i></button>
                                        </div>
                                    </div>
                                    <div class="modules-carousel-wrapper">
                                        <div class="modules-carousel-track flex gap-6 overflow-x-auto overflow-y-visible scroll-smooth pb-2" style="-webkit-overflow-scrolling: touch;">
                                            <?php 
                                            $tipo_capa = $secao['tipo_capa'] ?? 'vertical';
                                            $card_width_class = ($tipo_capa === 'horizontal') ? 'w-[280px] md:w-[500px]' : 'w-[280px] md:w-[320px]';
                                            foreach ($bloco['modulos_com_aulas'] as $item): ?>
                                                <?php
                                                $module = $item['modulo'];
                                                $idx = $global_module_index++;
                                                $is_module_locked = $module['is_locked'];
                                                $module_button_classes = "module-card group relative rounded-lg overflow-hidden transition-all duration-300 text-left w-full";
                                                $module_button_classes .= $is_module_locked ? ' opacity-50 cursor-not-allowed' : '';
                                                ?>
                                                <div class="flex-shrink-0 <?php echo $card_width_class; ?>">
                                                    <?php 
                                                    // Garantir que $tipo_capa está disponível no partial
                                                    $tipo_capa = $secao['tipo_capa'] ?? 'vertical';
                                                    include __DIR__ . '/member_course_view_module_card.php'; 
                                                    ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php elseif ($secao['tipo_secao'] === 'outros_produtos' && !empty($bloco['produtos'])): ?>
                                <div class="modules-carousel-section mb-10">
                                    <div class="flex justify-between items-center mb-4">
                                        <h2 class="text-2xl font-bold text-white"><?php echo htmlspecialchars($secao['titulo']); ?></h2>
                                        <div class="flex gap-2">
                                            <button type="button" class="carousel-prev w-10 h-10 md:w-10 md:h-10 rounded-lg bg-gray-800 hover:bg-gray-700 border border-gray-600 text-white flex items-center justify-center transition-colors" aria-label="Anterior"><i data-lucide="chevron-left" class="w-5 h-5"></i></button>
                                            <button type="button" class="carousel-next w-10 h-10 md:w-10 md:h-10 rounded-lg bg-gray-800 hover:bg-gray-700 border border-gray-600 text-white flex items-center justify-center transition-colors" aria-label="Próximo"><i data-lucide="chevron-right" class="w-5 h-5"></i></button>
                                        </div>
                                    </div>
                                    <div class="modules-carousel-wrapper">
                                        <div class="modules-carousel-track flex gap-6 overflow-x-auto overflow-y-visible scroll-smooth pb-2" style="-webkit-overflow-scrolling: touch;">
                                            <?php 
                                            $tipo_capa = $secao['tipo_capa'] ?? 'vertical';
                                            $card_width_class = ($tipo_capa === 'horizontal') ? 'w-[280px] md:w-[500px]' : 'w-[280px] md:w-[320px]';
                                            foreach ($bloco['produtos'] as $prod): ?>
                                                <div class="flex-shrink-0 <?php echo $card_width_class; ?>">
                                                    <?php 
                                                    // Garantir que $tipo_capa está disponível no partial
                                                    $tipo_capa = $secao['tipo_capa'] ?? 'vertical';
                                                    include __DIR__ . '/member_course_view_product_card.php'; 
                                                    ?>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <?php if (!empty($modulos_sem_secao)): ?>
                            <div id="modules-grid" class="modules-carousel-section mb-10">
                                <div class="flex justify-between items-center mb-4">
                                    <h2 class="text-2xl font-bold text-white">Outros</h2>
                                    <div class="flex gap-2">
                                        <button type="button" class="carousel-prev w-10 h-10 rounded-lg bg-gray-800 hover:bg-gray-700 border border-gray-600 text-white flex items-center justify-center transition-colors" aria-label="Anterior"><i data-lucide="chevron-left" class="w-5 h-5"></i></button>
                                        <button type="button" class="carousel-next w-10 h-10 rounded-lg bg-gray-800 hover:bg-gray-700 border border-gray-600 text-white flex items-center justify-center transition-colors" aria-label="Próximo"><i data-lucide="chevron-right" class="w-5 h-5"></i></button>
                                    </div>
                                </div>
                                <div class="modules-carousel-wrapper">
                                    <div class="modules-carousel-track flex gap-6 overflow-x-auto overflow-y-visible scroll-smooth pb-2" style="-webkit-overflow-scrolling: touch;">
                                        <?php foreach ($modulos_sem_secao as $item): ?>
                                            <?php
                                            $module = $item['modulo'];
                                            $idx = $global_module_index++;
                                            $is_module_locked = $module['is_locked'];
                                            $module_button_classes = "module-card group relative rounded-lg overflow-hidden transition-all duration-300 text-left w-full";
                                            $module_button_classes .= $is_module_locked ? ' opacity-50 cursor-not-allowed' : '';
                                            ?>
                                            <div class="flex-shrink-0 w-[280px] md:w-[320px]">
                                                <?php include __DIR__ . '/member_course_view_module_card.php'; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div id="modules-grid" class="modules-carousel-section mb-10">
                            <div class="flex justify-between items-center mb-4">
                                <h2 class="text-3xl font-bold text-white">Módulos do Curso</h2>
                                <div class="flex gap-2">
                                    <button type="button" class="carousel-prev w-10 h-10 rounded-lg bg-gray-800 hover:bg-gray-700 border border-gray-600 text-white flex items-center justify-center transition-colors" aria-label="Anterior"><i data-lucide="chevron-left" class="w-5 h-5"></i></button>
                                    <button type="button" class="carousel-next w-10 h-10 rounded-lg bg-gray-800 hover:bg-gray-700 border border-gray-600 text-white flex items-center justify-center transition-colors" aria-label="Próximo"><i data-lucide="chevron-right" class="w-5 h-5"></i></button>
                                </div>
                            </div>
                            <div class="modules-carousel-wrapper">
                                <div class="modules-carousel-track flex gap-6 overflow-x-auto overflow-y-visible scroll-smooth pb-2" style="-webkit-overflow-scrolling: touch;">
                                    <?php foreach ($modulos_com_aulas as $index => $item): ?>
                                        <?php
                                        $module = $item['modulo'];
                                        $idx = $index;
                                        $is_module_locked = $module['is_locked'];
                                        $module_button_classes = "module-card group relative rounded-lg overflow-hidden transition-all duration-300 text-left w-full";
                                        $module_button_classes .= $is_module_locked ? ' opacity-50 cursor-not-allowed' : '';
                                        ?>
                                        <div class="flex-shrink-0 w-[280px] md:w-[320px]">
                                            <?php include __DIR__ . '/member_course_view_module_card.php'; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </main>
        <?php else: ?>
        <!-- Banner da Comunidade -->
        <?php
        $comunidade_banner = $curso['comunidade_banner_url'] ?? null;
        if ($comunidade_banner && file_exists($comunidade_banner)):
        ?>
        <div class="w-full mb-6">
            <img src="<?php echo htmlspecialchars($comunidade_banner); ?>" alt="Banner Comunidade" class="w-full h-auto md:h-[600px] object-cover">
        </div>
        <?php endif; ?>
        
        <main class="max-w-screen-2xl mx-auto p-3 md:p-5 w-full flex-1 flex gap-6" id="community-main">
            <?php if (empty($comunidade_categorias)): ?>
                <div class="w-full p-8 rounded-lg text-center text-gray-400" style="background-color: #141414; border: 1px solid #262626;">
                    <p>Nenhuma categoria do feed configurada ainda.</p>
                </div>
            <?php else: ?>
                <!-- Sidebar de Categorias -->
                <aside class="w-64 flex-shrink-0 hidden md:block">
                    <div class="rounded-lg border p-4 sticky top-20" style="background-color: #141414; border-color: #262626;">
                        <h3 class="text-lg font-bold text-white mb-4">Categorias</h3>
                        <nav class="space-y-2">
                            <?php foreach ($comunidade_categorias as $idx => $cat): ?>
                                <button type="button" class="community-tab w-full text-left px-3 py-2 rounded-lg border transition text-gray-400 hover:text-white <?php echo $idx === 0 ? 'text-white' : ''; ?>" style="<?php echo $idx === 0 ? 'background-color: #1a1a1a; border-color: #333;' : 'border-color: #262626; background-color: transparent;'; ?>" onmouseover="<?php echo $idx === 0 ? '' : 'this.style.backgroundColor=\'#1a1a1a\'; this.style.borderColor=\'#333\';'; ?>" onmouseout="<?php echo $idx === 0 ? '' : 'this.style.backgroundColor=\'transparent\'; this.style.borderColor=\'#262626\';'; ?>" data-categoria-id="<?php echo (int)$cat['id']; ?>" data-public-posting="<?php echo (int)$cat['is_public_posting']; ?>">
                                    <?php echo htmlspecialchars($cat['nome']); ?>
                                </button>
                            <?php endforeach; ?>
                        </nav>
                    </div>
                </aside>
                
                <!-- Área Principal -->
                <div class="flex-1 min-w-0">
                    <!-- Tabs Mobile -->
                    <div class="md:hidden mb-4 overflow-x-auto">
                        <div class="flex gap-2 pb-2">
                            <?php foreach ($comunidade_categorias as $idx => $cat): ?>
                                <button type="button" class="community-tab flex-shrink-0 px-4 py-2 rounded-lg border transition text-gray-400 hover:text-white <?php echo $idx === 0 ? 'text-white' : ''; ?>" style="<?php echo $idx === 0 ? 'background-color: #1a1a1a; border-color: #333;' : 'border-color: #262626; background-color: transparent;'; ?>" onmouseover="<?php echo $idx === 0 ? '' : 'this.style.backgroundColor=\'#1a1a1a\'; this.style.borderColor=\'#333\';'; ?>" onmouseout="<?php echo $idx === 0 ? '' : 'this.style.backgroundColor=\'transparent\'; this.style.borderColor=\'#262626\';'; ?>" data-categoria-id="<?php echo (int)$cat['id']; ?>" data-public-posting="<?php echo (int)$cat['is_public_posting']; ?>">
                                    <?php echo htmlspecialchars($cat['nome']); ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <div id="community-post-form" class="mb-6 hidden">
                        <div class="rounded-lg border p-4" style="background-color: #141414; border-color: #262626;">
                            <form id="community-post-form-el" class="space-y-4">
                                <input type="hidden" name="categoria_id" id="community-categoria-id" value="">
                                <div>
                                    <label for="community-post-content" class="block text-gray-300 text-sm font-medium mb-2">Novo post</label>
                                    <textarea id="community-post-content" name="conteudo" rows="4" class="w-full px-4 py-2 bg-gray-700 border border-gray-600 rounded-lg text-white resize-none focus:ring-2 focus:ring-offset-0" style="--tw-ring-color: var(--accent-primary);" maxlength="10000" required placeholder="Escreva sua mensagem..."></textarea>
                                </div>
                                <div>
                                    <label for="community-post-image" class="block text-gray-300 text-sm font-medium mb-2">Imagem (opcional)</label>
                                    <input type="file" id="community-post-image" name="imagem" accept="image/*" class="w-full text-sm text-gray-400 file:mr-2 file:py-2 file:px-3 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:cursor-pointer file:bg-gray-700 file:text-white">
                                    <div id="community-post-image-preview" class="mt-2 hidden">
                                        <img id="community-post-image-preview-img" src="" alt="Preview" class="max-w-full max-h-64 rounded-lg border border-gray-600">
                                        <button type="button" id="community-post-image-remove" class="mt-2 text-sm text-red-400 hover:text-red-300">Remover imagem</button>
                                    </div>
                                </div>
                                <button type="submit" class="text-white font-bold py-2 px-5 rounded-lg transition" style="background-color: var(--accent-primary);">Publicar</button>
                            </form>
                        </div>
                    </div>
                    
                    <div id="community-posts-container" class="space-y-4">
                        <p class="text-gray-400">Carregando...</p>
                    </div>
                </div>
            <?php endif; ?>
        </main>
        <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <script>
        /* =================================================================== */
        /* ====== INÍCIO: TECNOLOGIA DO PLAYER (COPIADO DO YMin) ====== */
        /* =================================================================== */

        /* ÍCONES personalizados (PNG) */
        const ICONS = {
         back5: "https://iili.io/KCUAyMJ.png",
         fwd5: "https://iili.io/KCU5QhF.png",
         play: "https://iili.io/KCUYGS4.png",
         fs: "https://iili.io/KCUaDBe.png"
        };

        /* Tempo para auto-ocultar controles (ms) */
        const HIDE_DELAY_MS = 2200;

        /* ===================== YOUTUBE PLAYER API (Carregador) ===================== */
        (function(){
         if (!window._ytApi) {
         window._ytApi = {};
         window._ytApi.promise = new Promise((resolve) => {
           window._ytApi._resolve = resolve;
           const s = document.createElement('script');
           s.src = 'https://www.youtube.com/iframe_api';
           document.head.appendChild(s);
           const prev = window.onYouTubeIframeAPIReady;
           window.onYouTubeIframeAPIReady = function(){
           if (typeof prev === 'function') try { prev(); } catch {}
           window._ytApi._resolve();
           };
         });
         }})();
        const ytApiReady = window._ytApi.promise;

        let yminPlayer=null, yminRaf=0, yminRoot=null, yminPlaying=false, yminFirst=false, idleTimer=0, scrubbing=false;

        /* Barra "fake" pra UX */
        const REACH_AT = 0.90, PEAK_AT = 0.70, ACCEL_SHAPE = 0.6;
        function fakeFromReal(p){
         p=Math.max(0,Math.min(1,p));
         if(p<=REACH_AT){ const t=p/REACH_AT; return PEAK_AT*Math.pow(t,ACCEL_SHAPE); }
         const t=(p-REACH_AT)/(1-REACH_AT); return PEAK_AT+(1-PEAK_AT)*(1-Math.pow(1-t,3));
        }
        function formatTime(s){
         s = Math.max(0, Math.floor(s||0));
         const h = Math.floor(s/3600), m = Math.floor((s%3600)/60), sec = s%60;
         if (h>0) return `${h}:${String(m).padStart(2,'0')}:${String(sec).padStart(2,'0')}`;
         return `${m}:${String(sec).padStart(2,'0')}`;}

        /* ====== MOUNT COM IMAGENS (ícones customizados) ====== */
        function mountYMinHTML(root){
         const mountId='yt-mount-'+Math.random().toString(36).slice(2,8);
         root.innerHTML=`
         <div class="frame">
           <div class="clickzone" aria-hidden="true"></div>
           <div id="${mountId}"></div>
           <div class="veil" aria-hidden="true"></div>
           
           <div class="loading-spinner" aria-hidden="true"></div>
           <div class="screen-capture-blocker" aria-hidden="true"></div>

           <div class="overlay start"><div class="cover">
           <img class="icon" src="${ICONS.play}" alt="Play">
           </div></div>

           <div class="overlay paused" hidden><div class="cover">
           <img class="icon" src="${ICONS.play}" alt="Play">
           </div></div>
           

           <div class="hud ui"><div class="progress"><div class="bar"></div></div></div>
           <div class="timecode ui"><span class="cur">0:00</span> / <span class="dur">0:00</span></div>

           <div class="ctrls-right ui">
           <button class="btn back5" type="button" aria-label="Voltar 5 segundos" title="Voltar 5s (←)">
             <img src="${ICONS.back5}" alt="Voltar 5s">
           </button>
           <button class="btn fwd5" type="button" aria-label="Avançar 5 segundos" title="Avançar 5s (→)">
             <img src="${ICONS.fwd5}" alt="Avançar 5s">
           </button>
           <button class="btn speed-btn" type="button" aria-label="Velocidade de reprodução" title="Velocidade de reprodução (S)">
             <span style="font-size: 14px; font-weight: 600;">1x</span>
           </button>
           <button class="btn quality-btn" type="button" aria-label="Qualidade do vídeo" title="Qualidade do vídeo (Q)">
             <span style="font-size: 12px; font-weight: 600;">Auto</span>
           </button>
           <button class="btn fsbtn" type="button" aria-label="Tela cheia" title="Tela cheia (F)">
             <img src="${ICONS.fs}" alt="Tela cheia">
           </button>
           </div>
           
           <div class="speed-menu">
             <button data-speed="0.5" type="button">0.5x</button>
             <button data-speed="0.75" type="button">0.75x</button>
             <button data-speed="1" type="button" class="active">1x</button>
             <button data-speed="1.25" type="button">1.25x</button>
             <button data-speed="1.5" type="button">1.5x</button>
             <button data-speed="1.75" type="button">1.75x</button>
             <button data-speed="2" type="button">2x</button>
           </div>
           
           <div class="quality-menu">
             <button data-quality="auto" type="button" class="active">Auto</button>
             <button data-quality="small" type="button">240p</button>
             <button data-quality="medium" type="button">360p</button>
             <button data-quality="large" type="button">480p</button>
             <button data-quality="hd720" type="button">720p HD</button>
             <button data-quality="hd1080" type="button">1080p Full HD</button>
             <button data-quality="highres" type="button">Alta Resolução</button>
           </div>
         </div>
         `;
         return mountId;
        }
        function destroyYMin(){
         cancelAnimationFrame(yminRaf); yminRaf=0;
         try{ yminPlayer && yminPlayer.destroy && yminPlayer.destroy(); }catch{}
         yminPlayer=null; yminRoot=null; yminPlaying=false; yminFirst=false; scrubbing=false;
         clearTimeout(idleTimer);
        }
        function showControls(root){
         root.classList.remove('controls-hidden');
         clearTimeout(idleTimer);
         idleTimer = setTimeout(()=>{ if (!scrubbing) root.classList.add('controls-hidden'); }, HIDE_DELAY_MS);
        }
        function clamp01(x){ return Math.max(0, Math.min(1, x)); }

        async function createYMin(root, videoId){
         destroyYMin(); yminRoot=root;
         const mountId = mountYMinHTML(root);

         const isVertical = root.classList.contains('vertical') || root.dataset.vertical === '1';
         if (isVertical) { root.style.setProperty('--aspect','9/16'); }

         const frame   = root.querySelector('.frame');
         const clickzone = root.querySelector('.clickzone');
         const startOv  = root.querySelector('.overlay.start');
         const pausedOv = root.querySelector('.overlay.paused');
         const barEl   = root.querySelector('.progress .bar');
         const progress = root.querySelector('.progress');
         const curEl   = root.querySelector('.timecode .cur');
         const durEl   = root.querySelector('.timecode .dur');
         const fsBtn   = root.querySelector('.fsbtn');
         const back5Btn = root.querySelector('.back5');
         const fwd5Btn  = root.querySelector('.fwd5');

         setTimeout(() => { try { root.classList.add('ready'); } catch {} }, 1500);
         showControls(root);

         await ytApiReady;
         
         // Mostrar loading spinner
         root.classList.add('loading');
         
         // Qualidade preferida do localStorage
         const preferredQuality = localStorage.getItem('ymin-quality') || 'auto';

         yminPlayer = new YT.Player(mountId,{
         videoId, host:'https://www.youtube-nocookie.com',
         playerVars:{
           autoplay:1,
           mute:1,
           controls:0,
           disablekb:1,
           fs:0,
           modestbranding:1,
           rel:0,
           iv_load_policy:3,
           playsinline:1
           // vq removido - vamos aplicar via API após player estar pronto
         },
         events:{
           onReady(){
           try {
             // Remover loading spinner
             root.classList.remove('loading');
             
             // Aplicar velocidade salva
             const savedSpeed = parseFloat(localStorage.getItem('ymin-speed') || '1');
             if (yminPlayer && typeof yminPlayer.setPlaybackRate === 'function') {
               yminPlayer.setPlaybackRate(savedSpeed);
               if (speedBtn) {
                 speedBtn.querySelector('span').textContent = savedSpeed + 'x';
                 speedMenu.querySelectorAll('button').forEach(btn => {
                   btn.classList.toggle('active', parseFloat(btn.dataset.speed) === savedSpeed);
                 });
               }
             }
             
             // Aplicar qualidade preferida - múltiplas tentativas em momentos diferentes
             // Tentativa 1: Imediatamente após onReady (para casos rápidos)
             setTimeout(() => {
               try {
                 if (yminPlayer && qualityBtn && typeof applyQuality === 'function') {
                   applyQuality(preferredQuality, true);
                 }
               } catch(qErr) {
               }
             }, 1500); // Aguardar 1.5 segundos
             
             // Tentativa 2: Após o vídeo começar a carregar/buffer
             setTimeout(() => {
               try {
                 if (yminPlayer && typeof applyQuality === 'function') {
                   applyQuality(preferredQuality, true);
                 }
               } catch(qErr) {
               }
             }, 3000); // Aguardar 3 segundos
             
             // Tentativa 3: Após mais tempo (caso o vídeo esteja carregando)
             setTimeout(() => {
               try {
                 if (yminPlayer && typeof applyQuality === 'function') {
                   applyQuality(preferredQuality, true);
                 }
                 
                 // Detectar qualidades disponíveis após aplicar qualidade
                 if (typeof detectAvailableQualities === 'function') {
                   detectAvailableQualities();
                 }
               } catch(qErr) {
               }
             }, 5000); // Aguardar 5 segundos
             
             yminPlayer.mute();
             yminPlayer.playVideo();
           } catch(err) {
             root.classList.remove('loading');
           }
           requestAnimationFrame(()=>root.classList.add('ready'));
           setTimeout(()=>{ try { root.classList.add('ready'); } catch {} }, 400);
           loop();
           },
           onStateChange(e){
           try {
             if(e.data===YT.PlayerState.PLAYING){
               yminPlaying=true; 
               if(yminFirst){ 
                 startOv.hidden=true; 
                 pausedOv.hidden=true; 
               }
               
               // Aplicar qualidade quando o vídeo começar a tocar (momento crítico)
               if (typeof applyQuality === 'function' && currentQuality) {
                 setTimeout(() => {
                   try {
                     applyQuality(currentQuality, true);
                   } catch(err) {
                   }
                 }, 500);
               }
             }else if(e.data===YT.PlayerState.PAUSED){
               yminPlaying=false; 
               if(yminFirst){ 
                 pausedOv.hidden=false; 
               }
             }else if(e.data===YT.PlayerState.ENDED){
               yminPlaying=false; 
               try{
                 if(yminPlayer && typeof yminPlayer.seekTo === 'function' && typeof yminPlayer.pauseVideo === 'function'){
                   yminPlayer.seekTo(0,true);
                   yminPlayer.pauseVideo();
                 }
               }catch{}
               pausedOv.hidden=false;
             }
           } catch(err) {
             console.warn('Erro no onStateChange:', err);
           }
           },
           onError(e){
             console.error('Erro no player do YouTube:', e);
             root.classList.remove('loading');
             try {
               const ytWatchUrl = 'https://www.youtube.com/watch?v=' + videoId;
               const ytEmbedUrl = 'https://www.youtube.com/embed/' + videoId + '?autoplay=1';
               const isVertical = root.classList.contains('vertical');
               startOv.style.pointerEvents = 'auto';
               startOv.innerHTML = '<div class="cover" style="pointer-events:auto;display:flex;flex-direction:column;align-items:center;gap:16px;padding:24px;">' +
                 '<p style="color:#fff;font-size:18px;margin:0;">Erro ao carregar vídeo</p>' +
                 '<p style="color:#999;font-size:14px;margin:0;text-align:center;">O YouTube pode estar solicitando verificação. Tente uma das opções abaixo:</p>' +
                 '<div style="display:flex;flex-wrap:wrap;gap:12px;justify-content:center;">' +
                 '<a href="' + ytWatchUrl + '" target="_blank" rel="noopener" class="btn" style="padding:12px 24px;text-decoration:none;display:inline-flex;align-items:center;gap:8px;">' +
                 '<img src="' + ICONS.play + '" alt="" style="width:20px;height:20px;filter:brightness(0) invert(1);">Assistir no YouTube</a>' +
                 '<button type="button" class="btn" id="btn-try-simple-player" style="padding:12px 24px;border:none;cursor:pointer;display:inline-flex;align-items:center;gap:8px;">' +
                 '<img src="' + ICONS.play + '" alt="" style="width:20px;height:20px;filter:brightness(0) invert(1);">Tentar player embutido simples</button>' +
                 '</div></div>';
               startOv.hidden = false;
               document.getElementById('btn-try-simple-player').addEventListener('click', function() {
                 destroyYMin();
                 const playerHost = root.parentElement;
                 if (playerHost) {
                   const aspectStyle = isVertical ? 'aspect-ratio:9/16;max-width:min(520px,100%);margin:0 auto;' : 'aspect-ratio:16/9;';
                   playerHost.innerHTML = '<div class="ymin" style="width:100%;border-radius:14px;overflow:hidden;background:#000;"><div style="position:relative;width:100%;' + aspectStyle + '"><iframe src="' + ytEmbedUrl + '" style="position:absolute;inset:0;width:100%;height:100%;border:0;" allow="accelerometer;autoplay;clipboard-write;encrypted-media;gyroscope;picture-in-picture" allowfullscreen></iframe></div></div>';
                 }
               });
             } catch(err) {
               console.warn('Erro ao mostrar mensagem de erro:', err);
             }
           },
           onPlaybackQualityChange(e){
             // Atualizar qualidade quando o YouTube mudar automaticamente
             try {
               if (qualityBtn && qualityMenu && e && e.data) {
                 const ytQuality = e.data;
                 const reverseMap = {
                   'default': 'auto',
                   'small': 'small',
                   'medium': 'medium',
                   'large': 'large',
                   'hd720': 'hd720',
                   'hd1080': 'hd1080',
                   'highres': 'highres'
                 };
                 
                 const mappedQuality = reverseMap[ytQuality] || 'auto';
                 
                 // Atualizar visual apenas se mudou
                 if (mappedQuality !== currentQuality) {
                   const qualityLabels = {
                     'auto': 'Auto',
                     'small': '240p',
                     'medium': '360p',
                     'large': '480p',
                     'hd720': '720p',
                     'hd1080': '1080p',
                     'highres': 'HD+'
                   };
                   
                   qualityBtn.querySelector('span').textContent = qualityLabels[mappedQuality] || 'Auto';
                   qualityMenu.querySelectorAll('button').forEach(btn => {
                     btn.classList.toggle('active', btn.dataset.quality === mappedQuality);
                   });
                   
                   currentQuality = mappedQuality;
                 }
               }
             } catch(err) {
             }
           }
         }
         });

         function firstPlay(){ 
           yminFirst=true; 
           startOv.hidden=true; 
           try{
             if(yminPlayer && typeof yminPlayer.seekTo === 'function') yminPlayer.seekTo(0,true);
             if(yminPlayer && typeof yminPlayer.unMute === 'function') yminPlayer.unMute();
             
           }catch(err){
             console.warn('Erro no firstPlay:', err);
           }
           play(); 
         }
         function play(){ 
           try{
             if(yminPlayer && typeof yminPlayer.playVideo === 'function') {
               yminPlayer.playVideo();
             }
           }catch(err){
             console.warn('Erro ao reproduzir:', err);
           }
         }
         function pause(){ 
           try{
             if(yminPlayer && typeof yminPlayer.pauseVideo === 'function') {
               yminPlayer.pauseVideo();
             }
           }catch(err){
             console.warn('Erro ao pausar:', err);
           }
         }
         function toggle(){ 
           showControls(root); 
           if (yminPlayer) {
             yminPlaying ? pause() : (yminFirst ? play() : firstPlay()); 
           }
         }

         clickzone.addEventListener('click', toggle);
         root.addEventListener('mousemove', ()=>showControls(root), {passive:true});
         root.addEventListener('touchstart', ()=>showControls(root), {passive:true});
         root.addEventListener('touchmove', ()=>showControls(root), {passive:true});

         function enterFs(el){ (el.requestFullscreen||el.webkitRequestFullscreen||el.msRequestFullscreen||el.mozRequestFullScreen)?.call(el); }
         function exitFs(){ (document.exitFullscreen||document.webkitExitFullscreen||document.msExitFullscreen||document.mozCancelFullScreen)?.call(document); }
         function isFs(){ return document.fullscreenElement||document.webkitFullscreenElement||document.msFullscreenElement||document.mozFullScreenElement; }
         fsBtn.addEventListener('click', e=>{ e.stopPropagation(); showControls(root); isFs()?exitFs():enterFs(frame); });
         
         // Controles de Velocidade de Reprodução
         const speedBtn = root.querySelector('.speed-btn');
         const speedMenu = root.querySelector('.speed-menu');
         let currentSpeed = parseFloat(localStorage.getItem('ymin-speed') || '1');
         
         // Aplicar velocidade salva
         function applySpeed(speed) {
           try {
             if (yminPlayer && typeof yminPlayer.setPlaybackRate === 'function') {
               yminPlayer.setPlaybackRate(speed);
               currentSpeed = speed;
               speedBtn.querySelector('span').textContent = speed + 'x';
               localStorage.setItem('ymin-speed', speed.toString());
               
               // Atualizar menu
               speedMenu.querySelectorAll('button').forEach(btn => {
                 btn.classList.toggle('active', parseFloat(btn.dataset.speed) === speed);
               });
             }
           } catch(err) {
             console.warn('Erro ao aplicar velocidade:', err);
           }
         }
         
         // Toggle menu de velocidade
         speedBtn.addEventListener('click', (e) => {
           e.stopPropagation();
           showControls(root);
           const isShowing = speedMenu.classList.contains('show');
           root.querySelectorAll('.speed-menu, .quality-menu').forEach(m => m.classList.remove('show'));
           if (!isShowing) speedMenu.classList.add('show');
         });
         
         // Event listeners para opções de velocidade
         speedMenu.querySelectorAll('button').forEach(btn => {
           btn.addEventListener('click', (e) => {
             e.stopPropagation();
             const speed = parseFloat(btn.dataset.speed);
             applySpeed(speed);
             speedMenu.classList.remove('show');
           });
         });
         
         // Controles de Qualidade de Vídeo
         const qualityBtn = root.querySelector('.quality-btn');
         const qualityMenu = root.querySelector('.quality-menu');
         let currentQuality = localStorage.getItem('ymin-quality') || 'auto';
         
         // Aplicar qualidade - método robusto com múltiplas tentativas
         function applyQuality(quality, force = false) {
           try {
             if (!yminPlayer) {
               return false;
             }
             
             // Converter qualidade para formato esperado pela API do YouTube
             const qualityMap = {
               'auto': 'default',
               'small': 'small',
               'medium': 'medium', 
               'large': 'large',
               'hd720': 'hd720',
               'hd1080': 'hd1080',
               'highres': 'highres'
             };
             
             const ytQuality = qualityMap[quality] || 'default';
             
             // Verificar se já está na qualidade desejada
             if (!force && typeof yminPlayer.getPlaybackQuality === 'function') {
               try {
                 const currentQ = yminPlayer.getPlaybackQuality();
                 if (currentQ === ytQuality || (quality === 'auto' && currentQ === 'default')) {
                   // Já está na qualidade correta
                   return true;
                 }
               } catch(e) {
                 // Ignorar erro e continuar
               }
             }
             
             let applied = false;
             
             // Método 1: setPlaybackQuality (recomendado)
             if (typeof yminPlayer.setPlaybackQuality === 'function') {
               try {
                 yminPlayer.setPlaybackQuality(ytQuality);
                 applied = true;
               } catch(e) {
                 console.warn('Erro ao usar setPlaybackQuality:', e);
               }
             }
             
             // Se aplicou, verificar e garantir que ficou
             if (applied) {
               // Aguardar um pouco e verificar se realmente mudou
               setTimeout(() => {
                 try {
                   if (yminPlayer && typeof yminPlayer.getPlaybackQuality === 'function') {
                     const currentQ = yminPlayer.getPlaybackQuality();
                     
                     // Se não mudou e não é 'auto', tentar forçar novamente
                     if (currentQ !== ytQuality && quality !== 'auto') {
                       if (typeof yminPlayer.setPlaybackQuality === 'function') {
                         yminPlayer.setPlaybackQuality(ytQuality);
                       }
                       
                       // Última tentativa após mais tempo
                       setTimeout(() => {
                         try {
                           if (yminPlayer && typeof yminPlayer.setPlaybackQuality === 'function') {
                             const finalQ = yminPlayer.getPlaybackQuality();
                             if (finalQ !== ytQuality && quality !== 'auto') {
                               // Tentar usar setPlaybackQualityRange como fallback (se disponível)
                               if (typeof yminPlayer.setPlaybackQualityRange === 'function') {
                                 yminPlayer.setPlaybackQualityRange(ytQuality);
                               }
                             }
                           }
                         } catch(e) {
                         }
                       }, 2000);
                     }
                   }
                 } catch(e) {
                 }
               }, 1000);
             }
             
             // Atualizar estado local
             currentQuality = quality;
             
             // Atualizar texto do botão
             const qualityLabels = {
               'auto': 'Auto',
               'small': '240p',
               'medium': '360p',
               'large': '480p',
               'hd720': '720p',
               'hd1080': '1080p',
               'highres': 'HD+'
             };
             
             if (qualityBtn && qualityBtn.querySelector('span')) {
               qualityBtn.querySelector('span').textContent = qualityLabels[quality] || 'Auto';
             }
             
             localStorage.setItem('ymin-quality', quality);
             
             // Atualizar menu
             if (qualityMenu) {
               qualityMenu.querySelectorAll('button').forEach(btn => {
                 btn.classList.toggle('active', btn.dataset.quality === quality);
               });
             }
             
             return applied;
           } catch(err) {
             return false;
           }
         }
         
         // Detectar qualidade disponível após carregamento
         function detectAvailableQualities() {
           try {
             if (!yminPlayer || !qualityMenu) return;
             
             if (typeof yminPlayer.getAvailableQualityLevels === 'function') {
               const available = yminPlayer.getAvailableQualityLevels();
               const qualityOptions = qualityMenu.querySelectorAll('button');
               
               // Mapear qualidades do YouTube para nossos valores
               const qualityMapping = {
                 'AUTO': 'auto',
                 'SMALL': 'small',
                 'MEDIUM': 'medium',
                 'LARGE': 'large',
                 'HD720': 'hd720',
                 'HD1080': 'hd1080',
                 'HIGH_RES': 'highres'
               };
               
               qualityOptions.forEach(btn => {
                 const quality = btn.dataset.quality;
                 // Sempre mostrar 'auto'
                 if (quality === 'auto') {
                   btn.style.display = '';
                   return;
                 }
                 
                 // Verificar se está disponível
                 const upperQuality = quality.toUpperCase();
                 const ytQualityKey = Object.keys(qualityMapping).find(k => qualityMapping[k] === quality);
                 const isAvailable = available.some(q => {
                   const qUpper = q.toUpperCase();
                   return qUpper === upperQuality || 
                          (ytQualityKey && qUpper.includes(ytQualityKey.replace('_', '')));
                 }) || available.includes(upperQuality);
                 
                 btn.style.display = isAvailable ? '' : 'none';
               });
               
               // Verificar qualidade atual
               if (typeof yminPlayer.getPlaybackQuality === 'function') {
                 try {
                   const currentQ = yminPlayer.getPlaybackQuality();
                 } catch(e) {
                   // Ignorar erro
                 }
               }
             }
           } catch(err) {
           }
         }
         
         // Toggle menu de qualidade
         qualityBtn.addEventListener('click', (e) => {
           e.stopPropagation();
           showControls(root);
           const isShowing = qualityMenu.classList.contains('show');
           root.querySelectorAll('.speed-menu, .quality-menu').forEach(m => m.classList.remove('show'));
           if (!isShowing) {
             qualityMenu.classList.add('show');
             detectAvailableQualities();
           }
         });
         
         // Event listeners para opções de qualidade
         qualityMenu.querySelectorAll('button').forEach(btn => {
           btn.addEventListener('click', (e) => {
             e.stopPropagation();
             const quality = btn.dataset.quality;
             applyQuality(quality);
             qualityMenu.classList.remove('show');
           });
         });
         
         // Fechar menus ao clicar fora
         document.addEventListener('click', (e) => {
           if (!root.contains(e.target)) {
             root.querySelectorAll('.speed-menu, .quality-menu').forEach(m => m.classList.remove('show'));
           }
         });
         
         // Atalhos de Teclado
         let keyboardHandler = null;
         function setupKeyboardShortcuts() {
           if (keyboardHandler) return; // Evitar múltiplos listeners
           
           keyboardHandler = (e) => {
             // Só processar se o player estiver focado ou visível
             if (!root.classList.contains('ready') || !yminPlayer) return;
             
             // Verificar se está digitando em um input
             if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
             
             switch(e.key) {
               case ' ': // Espaço - Play/Pause
                 e.preventDefault();
                 showControls(root);
                 toggle();
                 break;
               case 'ArrowLeft': // ← - Voltar 5s
                 e.preventDefault();
                 showControls(root);
                 seekBy(-5);
                 break;
               case 'ArrowRight': // → - Avançar 5s
                 e.preventDefault();
                 showControls(root);
                 seekBy(5);
                 break;
               case 'm':
               case 'M': // M - Mute/Unmute
                 e.preventDefault();
                 try {
                   if (yminPlayer && typeof yminPlayer.isMuted === 'function') {
                     const isMuted = yminPlayer.isMuted();
                     if (isMuted) {
                       yminPlayer.unMute();
                     } else {
                       yminPlayer.mute();
                     }
                     showControls(root);
                   }
                 } catch(err) {
                   console.warn('Erro ao mutar:', err);
                 }
                 break;
               case 'f':
               case 'F': // F - Fullscreen
                 e.preventDefault();
                 showControls(root);
                 isFs() ? exitFs() : enterFs(frame);
                 break;
               case 's':
               case 'S': // S - Menu de Velocidade
                 e.preventDefault();
                 showControls(root);
                 const speedShowing = speedMenu.classList.contains('show');
                 root.querySelectorAll('.speed-menu, .quality-menu').forEach(m => m.classList.remove('show'));
                 if (!speedShowing) speedMenu.classList.add('show');
                 break;
               case 'q':
               case 'Q': // Q - Menu de Qualidade
                 e.preventDefault();
                 showControls(root);
                 const qualityShowing = qualityMenu.classList.contains('show');
                 root.querySelectorAll('.speed-menu, .quality-menu').forEach(m => m.classList.remove('show'));
                 if (!qualityShowing) {
                   qualityMenu.classList.add('show');
                   detectAvailableQualities();
                 }
                 break;
               case 'Escape': // ESC - Fechar menus
                 root.querySelectorAll('.speed-menu, .quality-menu').forEach(m => m.classList.remove('show'));
                 break;
             }
           };
           
           root.addEventListener('keydown', keyboardHandler);
         }
         setupKeyboardShortcuts();
         
         // Tornar o player focado para atalhos de teclado
         root.setAttribute('tabindex', '0');
         
         // Proteção contra Screen Capture - Torna vídeo preto na gravação
         function setupScreenCaptureProtection() {
           try {
             const blocker = root.querySelector('.screen-capture-blocker');
             if (!blocker) return;
             
             let isCapturing = false;
             
             // Função para ativar proteção
             function activateProtection() {
               if (!isCapturing) {
                 isCapturing = true;
                 root.classList.add('screen-capturing');
                 
                 // Criar canvas preto e sobrepor ao iframe (método adicional)
                 const iframe = root.querySelector('iframe');
                 if (iframe && iframe.parentElement) {
                   try {
                     let protectionCanvas = iframe.parentElement.querySelector('.protection-canvas');
                     if (!protectionCanvas) {
                       protectionCanvas = document.createElement('canvas');
                       protectionCanvas.className = 'protection-canvas';
                       protectionCanvas.style.cssText = 'position:absolute;inset:0;z-index:99999;background:#000;pointer-events:none;';
                       protectionCanvas.width = iframe.offsetWidth || 640;
                       protectionCanvas.height = iframe.offsetHeight || 360;
                       iframe.parentElement.style.position = 'relative';
                       iframe.parentElement.appendChild(protectionCanvas);
                     }
                     protectionCanvas.style.display = 'block';
                   } catch(err) {
                     console.warn('Erro ao aplicar canvas de proteção:', err);
                   }
                 }
               }
             }
             
             // Função para desativar proteção
             function deactivateProtection() {
               if (isCapturing) {
                 isCapturing = false;
                 root.classList.remove('screen-capturing');
                 
                 // Remover canvas de proteção
                 const iframe = root.querySelector('iframe');
                 if (iframe && iframe.parentElement) {
                   const canvas = iframe.parentElement.querySelector('.protection-canvas');
                   if (canvas) {
                     canvas.style.display = 'none';
                   }
                 }
               }
             }
             
             // Detectar getDisplayMedia (Screen Capture API)
             if (navigator.mediaDevices && navigator.mediaDevices.getDisplayMedia) {
               const originalGetDisplayMedia = navigator.mediaDevices.getDisplayMedia;
               navigator.mediaDevices.getDisplayMedia = function(...args) {
                 activateProtection();
                 
                 const promise = originalGetDisplayMedia.apply(this, args);
                 
                 promise.then((stream) => {
                   if (stream) {
                     const videoTracks = stream.getVideoTracks();
                     if (videoTracks.length > 0) {
                       videoTracks.forEach(track => {
                         track.addEventListener('ended', () => {
                           setTimeout(deactivateProtection, 100);
                         });
                       });
                     }
                     
                     // Monitorar stream ativo
                     const monitorInterval = setInterval(() => {
                       if (!stream.active) {
                         deactivateProtection();
                         clearInterval(monitorInterval);
                       }
                     }, 500);
                   }
                 }).catch(() => {
                   deactivateProtection();
                 });
                 
                 return promise;
               };
             }
             
             // Detectar getUserMedia com screen capture
             if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
               const originalGetUserMedia = navigator.mediaDevices.getUserMedia;
               navigator.mediaDevices.getUserMedia = function(...args) {
                 const constraints = args[0] || {};
                 if (constraints.video && (constraints.video.screen || constraints.video.displaySurface)) {
                   activateProtection();
                   
                   const promise = originalGetUserMedia.apply(this, args);
                   promise.then((stream) => {
                     if (stream) {
                       const videoTracks = stream.getVideoTracks();
                       if (videoTracks.length > 0) {
                         videoTracks.forEach(track => {
                           track.addEventListener('ended', () => {
                             setTimeout(deactivateProtection, 100);
                           });
                         });
                       }
                     }
                   }).catch(() => {
                     deactivateProtection();
                   });
                   
                   return promise;
                 }
                 return originalGetUserMedia.apply(this, args);
               };
             }
             
             // Monitoramento adicional (verificação periódica conservadora)
             if (navigator.permissions && navigator.permissions.query) {
               try {
                 navigator.permissions.query({ name: 'display-capture' }).then((result) => {
                   if (result.state === 'granted') {
                     // Se já tem permissão, pode estar gravando - ativar proteção preventiva
                     activateProtection();
                   }
                 
                   result.addEventListener('change', () => {
                     if (result.state === 'granted') {
                       activateProtection();
                     } else {
                       deactivateProtection();
                     }
                   });
                 }).catch(() => {
                   // Permissions API não suportado, ignorar
                 });
               } catch(err) {
                 // Permissions API pode não estar disponível
               }
             }
             
             // Proteção usando Page Visibility API (detecta se está em picture-in-picture ou compartilhando)
             // Comentado para evitar falsos positivos - deixar apenas detecção via getDisplayMedia
             // document.addEventListener('visibilitychange', () => {
             //   if (document.hidden) {
             //     setTimeout(() => {
             //       if (document.hidden) {
             //         activateProtection();
             //       }
             //     }, 1000);
             //   } else {
             //     deactivateProtection();
             //   }
             // });
             
           } catch(err) {
             console.warn('Erro ao configurar proteção de screen capture:', err);
           }
         }
         setupScreenCaptureProtection();

         // Correção de bugs de seek com validações melhoradas
         function seekBy(delta){
         try{
           if (!yminPlayer || typeof yminPlayer.getCurrentTime !== 'function' || typeof yminPlayer.getDuration !== 'function') return;
           const cur = yminPlayer.getCurrentTime() || 0;
           const dur = yminPlayer.getDuration() || 0;
           if (dur > 0 && !isNaN(cur) && !isNaN(dur)){
             let t = Math.max(0, Math.min(dur - 0.1, cur + delta));
             if (t >= 0 && t <= dur) {
               yminPlayer.seekTo(t, true);
               showControls(root);
             }
           }
         }catch(err){
           console.warn('Erro ao fazer seek:', err);
         }
         }
         back5Btn.addEventListener('click', (e)=>{ e.stopPropagation(); showControls(root); seekBy(-5); });
         fwd5Btn.addEventListener('click', (e)=>{ e.stopPropagation(); showControls(root); seekBy(+5); });

         function pctFromEvent(ev){
         try {
           const r = progress.getBoundingClientRect();
           if (!r || r.width === 0) return 0;
           const x = (ev.touches ? ev.touches[0]?.clientX : ev.clientX) - r.left;
           return clamp01(x / r.width);
         } catch(err) {
           return 0;
         }
         }
         function preview(p){ 
           try {
             if (!barEl || isNaN(p)) return;
             barEl.style.width = (fakeFromReal(clamp01(p))*100).toFixed(2)+'%'; 
           } catch(err) {
             console.warn('Erro ao preview:', err);
           }
         }
         function seekToPct(p){
         try {
           if (!yminPlayer || typeof yminPlayer.getDuration !== 'function' || typeof yminPlayer.seekTo !== 'function') return;
           const dur = yminPlayer.getDuration() || 0;
           if (dur > 0 && !isNaN(dur) && !isNaN(p)) {
             const clampedP = clamp01(p);
             const targetTime = dur * clampedP;
             if (targetTime >= 0 && targetTime <= dur) {
               yminPlayer.seekTo(targetTime, true);
             }
           }
         } catch(err) {
           console.warn('Erro ao seekToPct:', err);
         }
         }
         
         // Throttle para moveScrub para evitar múltiplas chamadas
         let lastMoveTime = 0;
         const MOVE_THROTTLE_MS = 50;
         
         function startScrub(ev){
         try {
           ev.preventDefault(); 
           if (!yminPlayer) return;
           scrubbing = true; 
           showControls(root);
           const p = pctFromEvent(ev); 
           if (!isNaN(p)) {
             preview(p); 
             seekToPct(p);
           }
           lastMoveTime = 0;
           window.addEventListener('mousemove', moveScrub);
           window.addEventListener('touchmove', moveScrub, {passive:false});
           window.addEventListener('mouseup', endScrub);
           window.addEventListener('touchend', endScrub);
           window.addEventListener('mouseleave', endScrub); // Adicionado para garantir reset
         } catch(err) {
           console.warn('Erro ao startScrub:', err);
           endScrub(ev); // Garantir cleanup em caso de erro
         }
         }
         function moveScrub(ev){
         try {
           ev.preventDefault();
           if(!scrubbing || !yminPlayer) return;
           
           // Throttle para melhorar performance
           const now = Date.now();
           if (now - lastMoveTime < MOVE_THROTTLE_MS) return;
           lastMoveTime = now;
           
           const p = pctFromEvent(ev); 
           if (!isNaN(p)) {
             preview(p); 
             // Não fazer seek durante move - apenas preview
           }
         } catch(err) {
           console.warn('Erro ao moveScrub:', err);
         }
         }
         function endScrub(ev){
         try {
           if(!scrubbing) return;
           
           const p = pctFromEvent(ev); 
           if (!isNaN(p)) {
             preview(p); 
             seekToPct(p);
           }
           
           // Garantir reset completo
           scrubbing = false;
           lastMoveTime = 0;
           
           window.removeEventListener('mousemove', moveScrub);
           window.removeEventListener('touchmove', moveScrub);
           window.removeEventListener('mouseup', endScrub);
           window.removeEventListener('touchend', endScrub);
           window.removeEventListener('mouseleave', endScrub);
           
           showControls(root);
         } catch(err) {
           console.warn('Erro ao endScrub:', err);
           // Reset de qualquer forma
           scrubbing = false;
           lastMoveTime = 0;
         }
         }
         progress.addEventListener('mousedown', startScrub);
         progress.addEventListener('touchstart', startScrub, {passive:true});

         function loop(){
         cancelAnimationFrame(yminRaf);
         const tick=()=>{
           try{
             if (!yminPlayer || typeof yminPlayer.getCurrentTime !== 'function' || typeof yminPlayer.getDuration !== 'function') {
               yminRaf=requestAnimationFrame(tick);
               return;
             }
             
             const cur = yminPlayer.getCurrentTime() || 0;
             const dur = yminPlayer.getDuration() || 0;
             
             if(dur > 0 && !isNaN(cur) && !isNaN(dur)){
               if(curEl) curEl.textContent = formatTime(cur);
               if(durEl) durEl.textContent = formatTime(dur);
               
               if(!scrubbing && barEl){
                 const pReal = Math.max(0, Math.min(1, cur / dur));
                 barEl.style.width = (fakeFromReal(pReal)*100).toFixed(2)+'%';
               }
               
             }
           }catch(err){
             console.warn('Erro no loop:', err);
           }
           yminRaf=requestAnimationFrame(tick);
         };
         yminRaf=requestAnimationFrame(tick);
         }
        }
        /* =================================================================== */
        /* ====== FIM: TECNOLOGIA DO PLAYER (COPIADO DO YMin) ======== */
        /* =================================================================== */


        document.addEventListener('DOMContentLoaded', function() {
            lucide.createIcons();

            // Carrossel de módulos: botões Anterior / Próximo (track dentro de .modules-carousel-section)
            document.querySelectorAll('.carousel-prev').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var section = this.closest('.modules-carousel-section');
                    var track = section && section.querySelector('.modules-carousel-track');
                    if (track) track.scrollBy({ left: -280, behavior: 'smooth' });
                });
            });
            document.querySelectorAll('.carousel-next').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var section = this.closest('.modules-carousel-section');
                    var track = section && section.querySelector('.modules-carousel-track');
                    if (track) track.scrollBy({ left: 280, behavior: 'smooth' });
                });
            });

            // Função para esconder botões do carrossel quando não há scroll necessário
            function toggleCarouselButtons() {
                document.querySelectorAll('.modules-carousel-section').forEach(function(section) {
                    const track = section.querySelector('.modules-carousel-track');
                    const buttons = section.querySelectorAll('.carousel-prev, .carousel-next');
                    if (track && buttons.length > 0) {
                        const hasScroll = track.scrollWidth > track.clientWidth;
                        buttons.forEach(function(btn) {
                            btn.style.display = hasScroll ? '' : 'none';
                        });
                    }
                });
            }

            // Executar ao carregar a página
            toggleCarouselButtons();

            // Executar ao redimensionar a janela
            let resizeTimeout;
            window.addEventListener('resize', function() {
                clearTimeout(resizeTimeout);
                resizeTimeout = setTimeout(toggleCarouselButtons, 250);
            });

            const allModulesData = <?php echo json_encode(isset($lista_modulos_para_js) ? $lista_modulos_para_js : $modulos_com_aulas); ?>;
            const clienteEmail = "<?php echo htmlspecialchars($cliente_email); ?>";
            const currentProductId = "<?php echo htmlspecialchars($produto_id); ?>";
            const aulaFilesDirPublic = "<?php echo htmlspecialchars($aula_files_dir_public); ?>";
            const allowComments = <?php echo !empty($curso['allow_comments']) ? 'true' : 'false'; ?>;
            const abaComunidade = <?php echo $aba_comunidade ? 'true' : 'false'; ?>;
            const comunidadeCategorias = <?php echo json_encode($comunidade_categorias ?? []); ?>;
            const usuarioTipo = "<?php echo htmlspecialchars($usuario_tipo); ?>";
            const isInfoprodutor = usuarioTipo === 'infoprodutor' || usuarioTipo === 'admin';

            if (abaComunidade && comunidadeCategorias.length > 0) {
                (function initCommunity() {
                    const container = document.getElementById('community-posts-container');
                    const formWrap = document.getElementById('community-post-form');
                    const formEl = document.getElementById('community-post-form-el');
                    const catIdInput = document.getElementById('community-categoria-id');
                    const tabs = document.querySelectorAll('.community-tab');
                    const imageInput = document.getElementById('community-post-image');
                    const imagePreview = document.getElementById('community-post-image-preview');
                    const imagePreviewImg = document.getElementById('community-post-image-preview-img');
                    const imageRemoveBtn = document.getElementById('community-post-image-remove');
                    let selectedImage = null;
                    
                    // Preview de imagem
                    if (imageInput) {
                        imageInput.addEventListener('change', function(e) {
                            const file = e.target.files[0];
                            if (file && file.type.startsWith('image/')) {
                                const reader = new FileReader();
                                reader.onload = function(e) {
                                    selectedImage = file;
                                    imagePreviewImg.src = e.target.result;
                                    imagePreview.classList.remove('hidden');
                                };
                                reader.readAsDataURL(file);
                            }
                        });
                    }
                    
                    if (imageRemoveBtn) {
                        imageRemoveBtn.addEventListener('click', function() {
                            selectedImage = null;
                            if (imageInput) imageInput.value = '';
                            imagePreview.classList.add('hidden');
                        });
                    }
                    
                    function loadPosts(categoriaId) {
                        if (!container) return;
                        container.innerHTML = '<p class="text-gray-400">Carregando...</p>';
                        fetch('/api/comunidade_posts.php?action=list&categoria_id=' + encodeURIComponent(categoriaId), { method: 'GET', credentials: 'same-origin' })
                            .then(r => r.json())
                            .then(data => {
                                if (!data.success) { container.innerHTML = '<p class="text-red-400">Erro ao carregar posts.</p>'; return; }
                                const posts = data.posts || [];
                                if (posts.length === 0) { container.innerHTML = '<p class="text-gray-400">Nenhum post ainda.</p>'; return; }
                                container.innerHTML = posts.map(p => {
                                    const dataCriacao = p.data_criacao ? new Date(p.data_criacao).toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' }) : '';
                                    const conteudoEscaped = (p.conteudo || '').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\n/g, '<br>');
                                    const autorNome = (p.autor_nome || p.autor_email || 'Anônimo').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                                    const isInfoprodutor = p.autor_tipo === 'infoprodutor';
                                    const badgeClass = isInfoprodutor ? '' : 'bg-gray-500/20 text-gray-400';
                                    const badgeStyle = isInfoprodutor ? 'background-color: color-mix(in srgb, var(--accent-primary) 20%, transparent); color: var(--accent-primary);' : '';
                                    const badgeText = isInfoprodutor ? 'Administrador' : 'Aluno';
                                    const imageHtml = p.anexo_url ? `<div class="mt-3"><img src="${p.anexo_url.replace(/"/g, '&quot;')}" alt="Anexo" class="max-w-full rounded-lg border border-gray-600 cursor-pointer" onclick="window.open(this.src, '_blank')"></div>` : '';
                                    return `<div class="rounded-xl p-5 border transition" style="background-color: #141414; border-color: #262626;" onmouseover="this.style.borderColor='#333'" onmouseout="this.style.borderColor='#262626'">
                                        <div class="flex items-start justify-between mb-3">
                                            <div class="flex items-center gap-2">
                                                <div class="w-10 h-10 rounded-full flex items-center justify-center text-white font-semibold" style="background-color: #1a1a1a;">${autorNome.charAt(0).toUpperCase()}</div>
                                                <div>
                                                    <p class="font-semibold text-white">${autorNome}</p>
                                                    <div class="flex items-center gap-2 mt-0.5">
                                                        <span class="text-xs px-2 py-0.5 rounded ${badgeClass}" ${badgeStyle ? `style="${badgeStyle}"` : ''}>${badgeText}</span>
                                                        <span class="text-gray-500 text-xs">${dataCriacao}</span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="text-gray-300 whitespace-pre-wrap">${conteudoEscaped}</div>
                                        ${imageHtml}
                                    </div>`;
                                }).join('');
                                if (typeof lucide !== 'undefined' && lucide.createIcons) lucide.createIcons();
                            })
                            .catch(() => { container.innerHTML = '<p class="text-red-400">Erro ao carregar posts.</p>'; });
                    }
                    tabs.forEach(function(tab) {
                        tab.addEventListener('click', function() {
                            const catId = this.getAttribute('data-categoria-id');
                            const isPublic = parseInt(this.getAttribute('data-public-posting'), 10) === 1;
                            tabs.forEach(t => { 
                                t.style.backgroundColor = 'transparent';
                                t.style.borderColor = '#262626';
                                t.classList.remove('text-white');
                                t.classList.add('text-gray-400');
                            });
                            this.style.backgroundColor = '#1a1a1a';
                            this.style.borderColor = '#333';
                            this.classList.add('text-white'); 
                            this.classList.remove('text-gray-400');
                            if (catIdInput) catIdInput.value = catId;
                            if (formWrap) { formWrap.classList.toggle('hidden', !(isPublic || isInfoprodutor)); }
                            loadPosts(catId);
                        });
                    });
                    var firstTab = tabs[0];
                    if (firstTab) {
                        firstTab.click();
                    }
                    if (formEl) {
                        formEl.addEventListener('submit', function(e) {
                            e.preventDefault();
                            var catId = catIdInput ? catIdInput.value : '';
                            var content = document.getElementById('community-post-content');
                            if (!catId || !content || !content.value.trim()) return;
                            var formData = new FormData();
                            formData.append('action', 'add');
                            formData.append('csrf_token', window.csrfToken || '');
                            formData.append('categoria_id', catId);
                            formData.append('conteudo', content.value.trim());
                            if (selectedImage) {
                                formData.append('imagem', selectedImage);
                            }
                            const submitBtn = formEl.querySelector('button[type="submit"]');
                            const originalText = submitBtn ? submitBtn.textContent : '';
                            if (submitBtn) {
                                submitBtn.disabled = true;
                                submitBtn.textContent = 'Publicando...';
                            }
                            fetch('/api/comunidade_posts.php', { method: 'POST', credentials: 'same-origin', body: formData })
                                .then(r => r.json())
                                .then(data => {
                                    if (data.success) { 
                                        content.value = '';
                                        selectedImage = null;
                                        if (imageInput) imageInput.value = '';
                                        if (imagePreview) imagePreview.classList.add('hidden');
                                        loadPosts(catId);
                                    } else { 
                                        alert(data.error || 'Erro ao publicar.'); 
                                    }
                                    if (submitBtn) {
                                        submitBtn.disabled = false;
                                        submitBtn.textContent = originalText;
                                    }
                                })
                                .catch(() => {
                                    alert('Erro ao publicar.');
                                    if (submitBtn) {
                                        submitBtn.disabled = false;
                                        submitBtn.textContent = originalText;
                                    }
                                });
                        });
                    }
                })();
                return;
            }

            if (!allModulesData || allModulesData.length === 0) return;

            const playerWrapper = document.getElementById('player-wrapper');
            // [INÍCIO DA MUDANÇA] Referências do Player
            const playerHost = document.getElementById('player-host'); // Novo container do player
            const initialPlaceholderHTML = playerHost.innerHTML; // Salva o placeholder inicial
            // [FIM DA MUDANÇA]
            
            const lessonTitle = document.getElementById('lesson-title');
            const lessonDescription = document.getElementById('lesson-description');
            const lessonListContainer = document.getElementById('lesson-list-container');
            const moduleCards = document.querySelectorAll('.module-card');
            const moduleTitleAside = document.getElementById('module-title-aside');
            const markAsCompleteBtn = document.getElementById('mark-as-complete-btn');
            
            let currentModuleId = null;
            let currentLessonData = null; // Guarda os dados da aula atualmente carregada

            
            // [INÍCIO DA MUDANÇA] Função loadLesson atualizada para usar YMin
            function loadLesson(lesson) {
                // 1. Destrói qualquer player YMin anterior
                destroyYMin(); 

                if (!lesson) { // Reset player if no lesson
                    playerHost.innerHTML = initialPlaceholderHTML; // Restaura placeholder inicial
                    lucide.createIcons();
                    lessonTitle.textContent = 'Nenhuma aula selecionada';
                    lessonDescription.innerHTML = '<p>Selecione uma aula na lista ao lado.</p>';
                    markAsCompleteBtn.classList.add('hidden');
                    currentLessonData = null;
                    const commentsSection = document.getElementById('comments-section');
                    if (commentsSection) { commentsSection.classList.add('hidden'); commentsSection.querySelector('#comment-aula-id').value = ''; }
                    return;
                }
                
                // 2. Lida com aula bloqueada
                if (lesson.is_locked) {
                    playerHost.innerHTML = `<div class="w-full aspect-video bg-black flex flex-col items-center justify-center text-gray-500 rounded-xl">
                                                <i data-lucide="lock" class="w-16 h-16 text-gray-600 mb-4"></i>
                                                <p class="text-lg font-semibold">Aula Bloqueada</p>
                                                <p class="text-sm">Disponível em: ${lesson.available_at}</p>
                                            </div>`;
                    lucide.createIcons();
                    lessonTitle.textContent = 'Aula Bloqueada';
                    lessonDescription.innerHTML = `<p class="text-red-400 flex items-center"><i data-lucide="lock" class="w-5 h-5 mr-2"></i> Esta aula estará disponível em: ${lesson.available_at}.</p><p>Volte mais tarde para acessá-la!</p>`;
                    markAsCompleteBtn.classList.add('hidden');
                    currentLessonData = null;
                    lucide.createIcons(); // Render the lock icon in the description
                    const commentsSectionLocked = document.getElementById('comments-section');
                    if (commentsSectionLocked) { commentsSectionLocked.classList.add('hidden'); commentsSectionLocked.querySelector('#comment-aula-id').value = ''; }
                    return;
                }

                currentLessonData = lesson;
                const commentsSectionShow = document.getElementById('comments-section');
                if (allowComments && commentsSectionShow) {
                    commentsSectionShow.classList.remove('hidden');
                    commentsSectionShow.querySelector('#comment-aula-id').value = lesson.id;
                    loadComments(lesson.id);
                } else if (commentsSectionShow) {
                    commentsSectionShow.classList.add('hidden');
                }

                // 3. Lógica de exibição baseada no tipo de conteúdo
                let videoId = null;
                let isShort = false;
                
                // Se for tipo 'text', 'files' ou 'download_protegido', ocultar completamente a área do player
                if (lesson.tipo_conteudo === 'text' || lesson.tipo_conteudo === 'files' || lesson.tipo_conteudo === 'download_protegido') {
                    // Tipo 'text', 'files' ou 'download_protegido' - ocultar player completamente, mostrar apenas conteúdo
                    playerHost.style.setProperty('display', 'none', 'important');
                    playerHost.style.setProperty('margin-bottom', '0', 'important');
                    playerHost.innerHTML = '';
                } else {
                    // Mostrar player para outros tipos (video ou mixed)
                    playerHost.style.setProperty('display', 'block', 'important');
                    playerHost.style.removeProperty('margin-bottom');
                    
                    if ((lesson.tipo_conteudo === 'video' || lesson.tipo_conteudo === 'mixed') && lesson.url_video) {
                        // Regex do player YMin para extrair o ID
                        const match = lesson.url_video.match(/(?:youtube\.com\/(?:watch\?v=|shorts\/|embed\/|v\/)|youtu\.be\/)([A-Za-z0-9_-]{11})/i);
                        if (match && match[1]) {
                            videoId = match[1];
                            isShort = /youtube\.com\/shorts\//i.test(lesson.url_video);
                        }
                    }

                    if (videoId) {
                        // Encontrou um vídeo do YouTube -> Carrega o YMin
                        playerHost.innerHTML = ''; // Limpa o placeholder
                        const playerDiv = document.createElement('div');
                        // Adiciona a classe 'ymin' e 'controls-hidden' (e 'vertical' se for short)
                        playerDiv.className = `ymin controls-hidden ${isShort ? 'vertical' : ''}`;
                        playerHost.appendChild(playerDiv);
                        
                        // Chama a função principal do YMin
                        createYMin(playerDiv, videoId);
                    } else {
                        // Não é um vídeo do YouTube válido -> Mostra placeholder
                        playerHost.innerHTML = `<div class="w-full aspect-video bg-black flex flex-col items-center justify-center text-gray-500 rounded-xl">
                                                    <i data-lucide="video-off" class="w-16 h-16 text-gray-600 mb-4"></i>
                                                    <p class="text-lg font-semibold">Esta aula não contém vídeo.</p>
                                                    <p class="text-sm">Verifique os materiais de apoio abaixo.</p>
                                                </div>`;
                        lucide.createIcons();
                    }
                }


                // 5. Carrega Título, Descrição e Arquivos (lógica original mantida)
                lessonTitle.textContent = lesson.titulo;

                let descriptionHtml = (lesson.descricao || 'Esta aula não possui descrição.')
                    .replace(/</g, "&lt;").replace(/>/g, "&gt;") // Basic HTML escaping
                    .replace(/(https?:\/\/[^\s]+)/g, '<a href="$1" target="_blank" class="hover:underline" style="color: var(--accent-primary);">$1</a>') // Link detection
                    .replace(/\n/g, '<br>');
                
                // Adicionar arquivos de apoio como botões CTA (apenas se não for tipo 'text')
                if (lesson.tipo_conteudo !== 'text') {
                    // Download Protegido
                    if (lesson.tipo_conteudo === 'download_protegido') {
                        descriptionHtml += '<h4 class="text-lg font-bold text-white mt-6 mb-3">Material para Download</h4>';
                        descriptionHtml += `
                            <button id="btn-download-protegido-${lesson.id}" class="text-white font-bold py-3 px-6 rounded-lg transition duration-300 text-base flex items-center justify-center space-x-2 w-full" style="background-color: var(--accent-primary);" onmouseover="this.style.backgroundColor='var(--accent-primary-hover)'" onmouseout="this.style.backgroundColor='var(--accent-primary)'">
                                <i data-lucide="lock" class="w-5 h-5 flex-shrink-0"></i>
                                <span>Baixar Material</span>
                            </button>
                        `;
                    }
                    // Arquivos normais
                    else if ((lesson.tipo_conteudo === 'files' || lesson.tipo_conteudo === 'mixed') && lesson.files && lesson.files.length > 0) {
                        descriptionHtml += '<h4 class="text-lg font-bold text-white mt-6 mb-3">Materiais de Apoio</h4>';
                        descriptionHtml += '<div class="grid grid-cols-1 md:grid-cols-2 gap-4">'; // Responsive grid container
                        lesson.files.forEach(file => {
                            const filePath = `${aulaFilesDirPublic}${file.nome_salvo}`;
                            descriptionHtml += `
                                <a href="${filePath}" target="_blank" class="text-white font-bold py-3 px-6 rounded-lg transition duration-300 text-base flex items-center justify-center space-x-2" style="background-color: var(--accent-primary);" onmouseover="this.style.backgroundColor='var(--accent-primary-hover)'" onmouseout="this.style.backgroundColor='var(--accent-primary)'">
                                    <i data-lucide="download" class="w-5 h-5 flex-shrink-0"></i>
                                    <span>${file.nome_original}</span>
                                </a>
                            `;
                        });
                        descriptionHtml += '</div>'; // Close the grid div
                    } else if ((lesson.tipo_conteudo === 'files' || lesson.tipo_conteudo === 'mixed') && (!lesson.files || lesson.files.length === 0)) {
                        descriptionHtml += '<p class="text-gray-500 mt-4">Nenhum material de apoio disponível para esta aula.</p>';
                    }
                }


                lessonDescription.innerHTML = descriptionHtml;
                lucide.createIcons(); // Re-render icons if new ones were added in descriptionHtml

                // Adicionar event listener para botão de download protegido (se existir)
                if (lesson.tipo_conteudo === 'download_protegido') {
                    // Usar setTimeout para garantir que o DOM foi atualizado
                    setTimeout(() => {
                        const downloadBtn = document.getElementById(`btn-download-protegido-${lesson.id}`);
                        if (downloadBtn) {
                            // Remover event listeners anteriores se existirem
                            const newBtn = downloadBtn.cloneNode(true);
                            downloadBtn.parentNode.replaceChild(newBtn, downloadBtn);
                            
                            newBtn.addEventListener('click', function(e) {
                                e.preventDefault();
                                e.stopPropagation();
                                handleDownloadProtegido(lesson);
                            });
                        }
                    }, 100);
                }

                // 6. Highlight na aula ativa (lógica original mantida)
                document.querySelectorAll('.lesson-item').forEach(item => {
                    item.classList.toggle('active', item.dataset.lessonId == lesson.id);
                });

                // 7. Atualiza o botão "Marcar como Concluída" (lógica original mantida)
                // AQUI USAMOS O ESTADO ATUAL DA AULA (lesson.concluida)
                updateMarkAsCompleteButton(lesson.concluida);
            }
            // [FIM DA MUDANÇA] Função loadLesson

            // [INÍCIO DA MUDANÇA] Função de atualização do botão
            function updateMarkAsCompleteButton(isConcluida) {
                if (!markAsCompleteBtn || !currentLessonData || currentLessonData.is_locked) { 
                    markAsCompleteBtn.classList.add('hidden'); // Oculta se a aula estiver bloqueada ou nenhuma aula selecionada
                    return;
                }
                markAsCompleteBtn.classList.remove('hidden'); // Mostra o botão
                markAsCompleteBtn.disabled = false; // Garante que o botão está habilitado

                if (isConcluida) {
                    // Estado "Concluída" - Pronta para DESMARCAR
                    markAsCompleteBtn.innerHTML = '<i data-lucide="x-square" class="w-5 h-5"></i><span>Desmarcar Conclusão</span>';
                    markAsCompleteBtn.classList.remove('bg-gray-600', 'cursor-not-allowed', 'bg-yellow-600', 'hover:bg-yellow-700');
                    markAsCompleteBtn.classList.add('bg-yellow-600', 'hover:bg-yellow-700'); // Cor amarela para desmarcar
                } else {
                    // Estado "Não Concluída" - Pronta para MARCAR
                    markAsCompleteBtn.innerHTML = '<i data-lucide="check-square" class="w-5 h-5"></i><span>Marcar como Concluída</span>';
                    markAsCompleteBtn.classList.remove('bg-yellow-600', 'hover:bg-yellow-700', 'bg-gray-600', 'cursor-not-allowed');
                    markAsCompleteBtn.style.backgroundColor = 'var(--accent-primary)';
                    markAsCompleteBtn.onmouseover = function() { this.style.backgroundColor = 'var(--accent-primary-hover)'; };
                    markAsCompleteBtn.onmouseout = function() { this.style.backgroundColor = 'var(--accent-primary)'; };
                }
                lucide.createIcons(); // Renderiza os novos ícones (check-square ou x-square)
            }
            // [FIM DA MUDANÇA] Função de atualização do botão

            function loadComments(aulaId) {
                const listEl = document.getElementById('comments-list');
                if (!listEl) return;
                listEl.innerHTML = '<p class="text-gray-400 text-sm">Carregando comentários...</p>';
                fetch('/api/comentarios_aula.php?action=list&aula_id=' + encodeURIComponent(aulaId), { method: 'GET', credentials: 'same-origin' })
                    .then(r => r.json())
                    .then(data => {
                        if (!data.success) {
                            listEl.innerHTML = '<p class="text-red-400 text-sm">Erro ao carregar comentários.</p>';
                            return;
                        }
                        const comments = data.comments || [];
                        if (comments.length === 0) {
                            listEl.innerHTML = '<p class="text-gray-400 text-sm">Nenhum comentário ainda. Seja o primeiro!</p>';
                            return;
                        }
                        listEl.innerHTML = comments.map(c => {
                            const dataCriacao = c.data_criacao ? new Date(c.data_criacao).toLocaleString('pt-BR', { dateStyle: 'short', timeStyle: 'short' }) : '';
                            const textoEscaped = (c.texto || '').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/\n/g, '<br>');
                            return '<div class="bg-gray-700/50 rounded-lg p-4"><p class="font-semibold text-white">' + (c.autor_nome || '').replace(/</g, '&lt;') + '</p><p class="text-gray-300 text-sm mt-1">' + textoEscaped + '</p><p class="text-gray-500 text-xs mt-2">' + dataCriacao + '</p></div>';
                        }).join('');
                    })
                    .catch(() => { listEl.innerHTML = '<p class="text-red-400 text-sm">Erro ao carregar comentários.</p>'; });
            }

            const commentForm = document.getElementById('comment-form');
            if (allowComments && commentForm) {
                commentForm.addEventListener('submit', function(e) {
                    e.preventDefault();
                    const aulaId = document.getElementById('comment-aula-id').value;
                    const autorNome = document.getElementById('comment-author').value.trim() || undefined;
                    const texto = document.getElementById('comment-text').value.trim();
                    if (!aulaId || !texto) return;
                    const submitBtn = document.getElementById('comment-submit');
                    if (submitBtn) { submitBtn.disabled = true; submitBtn.textContent = 'Enviando...'; }
                    const formData = new FormData();
                    formData.append('action', 'add');
                    formData.append('csrf_token', window.csrfToken || '');
                    formData.append('aula_id', aulaId);
                    formData.append('autor_nome', autorNome || '');
                    formData.append('texto', texto);
                    fetch('/api/comentarios_aula.php', { method: 'POST', credentials: 'same-origin', body: formData })
                        .then(r => r.json())
                        .then(data => {
                            if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = 'Enviar comentário'; }
                            if (data.success) {
                                document.getElementById('comment-text').value = '';
                                loadComments(aulaId);
                            } else {
                                alert(data.error || 'Erro ao enviar comentário.');
                            }
                        })
                        .catch(() => {
                            if (submitBtn) { submitBtn.disabled = false; submitBtn.textContent = 'Enviar comentário'; }
                            alert('Erro ao enviar comentário.');
                        });
                });
            }

            function displayLessonsForModule(moduleIndex) {
                const moduleData = allModulesData[moduleIndex];
                if (!moduleData) return;

                currentModuleId = moduleData.modulo.id;

                // Highlight active module card
                moduleCards.forEach(card => {
                    card.classList.toggle('active', card.dataset.moduleId == currentModuleId);
                });
                
                moduleTitleAside.textContent = moduleData.modulo.titulo;
                lessonListContainer.innerHTML = ''; // Clear previous lessons

                if (moduleData.aulas.length === 0) {
                    lessonListContainer.innerHTML = '<p class="text-gray-400 px-2">Este módulo não possui aulas.</p>';
                    loadLesson(null); // Clear the player
                    return;
                }

                let firstAvailableLesson = null;

                moduleData.aulas.forEach(aula => {
                    const lessonButton = document.createElement('button');
                    let iconHtml = '';
                    let textClass = 'text-gray-300'; // Default class for unlocked, not completed lessons

                    if (aula.is_locked) {
                        lessonButton.className = 'lesson-item w-full text-left flex items-center space-x-3 p-3 rounded-lg locked';
                        iconHtml = `<i data-lucide="lock" class="w-5 h-5 flex-shrink-0 text-gray-500"></i>`;
                        textClass = 'text-gray-500'; // Make text dimmer for locked lessons
                    } else {
                        lessonButton.className = 'lesson-item w-full text-left flex items-center space-x-3 p-3 rounded-lg hover:bg-gray-700 transition';
                        
                        // Determine icon(s) based on content type
                        let videoIcon = '';
                        let fileIcon = '';
                        let textIcon = '';

                        if (aula.tipo_conteudo === 'text') {
                            textIcon = `<i data-lucide="align-left" class="w-5 h-5 flex-shrink-0 ${aula.concluida ? '' : 'text-gray-500'}" ${aula.concluida ? 'style="color: var(--accent-primary);"' : ''}></i>`;
                        } else if (aula.tipo_conteudo === 'download_protegido') {
                            textIcon = `<i data-lucide="lock" class="w-5 h-5 flex-shrink-0 ${aula.concluida ? '' : 'text-yellow-500'}" ${aula.concluida ? 'style="color: var(--accent-primary);"' : ''}></i>`;
                        } else {
                            if (aula.tipo_conteudo === 'video' || aula.tipo_conteudo === 'mixed') {
                                videoIcon = `<i data-lucide="play-circle" class="w-5 h-5 flex-shrink-0 ${aula.concluida ? '' : 'text-gray-500'}" ${aula.concluida ? 'style="color: var(--accent-primary);"' : ''}></i>`;
                            }
                            if (aula.tipo_conteudo === 'files' || aula.tipo_conteudo === 'mixed') {
                                fileIcon = `<i data-lucide="file-text" class="w-5 h-5 flex-shrink-0 ${aula.concluida ? '' : 'text-gray-500'}" ${aula.concluida ? 'style="color: var(--accent-primary);"' : ''}></i>`;
                            }
                        }
                        // Combine them, possibly with a small space
                        iconHtml = textIcon || (videoIcon + (videoIcon && fileIcon ? '<span class="w-1"></span>' : '') + fileIcon);


                        if (aula.concluida) {
                            textClass = 'text-gray-400 line-through'; // [MUDANÇA] Mantém o line-through para concluídas
                        } else {
                             textClass = 'text-gray-300';
                             if (!firstAvailableLesson) { // Keep track of the first unlocked lesson
                                 firstAvailableLesson = aula;
                             }
                        }
                    }

                    lessonButton.dataset.lessonId = aula.id;
                    lessonButton.innerHTML = `
                        <div class="flex items-center space-x-1">
                            ${iconHtml}
                        </div>
                        <span class="${textClass}">${aula.titulo}</span>
                        ${aula.concluida && !aula.is_locked ? '<i data-lucide="check" class="w-4 h-4 ml-auto flex-shrink-0" style="color: var(--accent-primary);"></i>' : ''}
                        ${aula.is_locked ? `<span class="ml-auto text-xs text-gray-500">Disp. ${aula.available_at}</span>` : ''}
                    `;
                    
                    // [MUDANÇA] A aula é carregada ao clicar, mesmo se bloqueada (a loadLesson tratará o bloqueio)
                    lessonButton.addEventListener('click', () => loadLesson(aula));
                    
                    lessonListContainer.appendChild(lessonButton);
                });
                lucide.createIcons();
                
                // Auto-load the first unlocked lesson of this module, or the very first one if none are unlocked.
                loadLesson(firstAvailableLesson || moduleData.aulas[0]);
            }
            
            // Event listeners for module cards
            moduleCards.forEach(card => {
                card.addEventListener('click', (e) => {
                    // Prevent click if clicking on "Comprar Produto" link
                    if (e.target.closest('a[href*="/checkout"]')) {
                        return; // Let the link work normally
                    }
                    
                    // Only allow click if module is not locked
                    if (card.disabled) return; 

                    playerWrapper.classList.remove('hidden'); // Make the player section visible
                    
                    const moduleIndex = parseInt(card.dataset.moduleIndex, 10);
                    displayLessonsForModule(moduleIndex);

                    // Scroll to player
                    playerWrapper.scrollIntoView({ behavior: 'smooth', block: 'start' });
                });
            });

            // [INÍCIO DA MUDANÇA] Lógica de clique do botão "Marcar/Desmarcar"
            markAsCompleteBtn.addEventListener('click', async () => {
                // Checa se há uma aula carregada, se o botão está desabilitado (ex: durante uma chamada de API) ou se a aula está bloqueada
                if (!currentLessonData || markAsCompleteBtn.disabled || currentLessonData.is_locked) return;

                // Desabilita o botão temporariamente para evitar cliques duplos
                markAsCompleteBtn.disabled = true;

                const isCompleted = currentLessonData.concluida;
                const action = isCompleted ? 'unmark_lesson_complete' : 'mark_lesson_complete';
                const newConcluidaState = !isCompleted;

                try {
                    const response = await fetch(`/api/member_api.php?action=${action}`, {
                        method: 'POST',
                        headers: { 
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': window.csrfToken || ''
                        },
                        body: JSON.stringify({
                            aluno_email: clienteEmail,
                            aula_id: currentLessonData.id,
                            csrf_token: window.csrfToken || ''
                        })
                    });
                    const result = await response.json();

                    if (result.success) {
                        // 1. Atualiza o estado da aula atual
                        currentLessonData.concluida = newConcluidaState; 
                        
                        // 2. Atualiza o estado global (em allModulesData)
                        allModulesData.forEach(moduleItem => {
                            moduleItem.aulas.forEach(aula => {
                                if (aula.id === currentLessonData.id) {
                                    aula.concluida = newConcluidaState;
                                }
                            });
                        });

                        // 3. Atualiza o visual do botão
                        updateMarkAsCompleteButton(newConcluidaState);
                        
                        // 4. Re-renderiza a lista de aulas para refletir a mudança (ex: ícone, line-through)
                        const activeModuleIndex = Array.from(moduleCards).findIndex(card => card.classList.contains('active'));
                        if (activeModuleIndex !== -1) {
                            displayLessonsForModule(activeModuleIndex);
                        }

                        // 5. Atualiza a barra de progresso geral
                        updateOverallProgress();

                    } else {
                        console.error(`Erro ao ${action}: ` + (result.error || 'Erro desconhecido.'));
                        // Se a ação de desmarcar falhar, avisa o usuário (pois pode ser problema de backend)
                        if (action === 'unmark_lesson_complete') {
                            console.warn('Atenção: A ação "unmark_lesson_complete" falhou. Verifique se ela foi implementada no seu "member_api.php".');
                            // Não reverta o estado aqui, apenas re-habilite o botão
                        }
                    }
                } catch (error) {
                    console.error(`Erro de rede/API ao ${action}:`, error);
                } finally {
                    // Re-habilita o botão após a conclusão da API (com sucesso ou falha)
                    // A função updateMarkAsCompleteButton já faz isso, mas podemos garantir
                    if (currentLessonData && !currentLessonData.is_locked) {
                         markAsCompleteBtn.disabled = false;
                         // Garante que o botão está no estado correto caso a API falhe e não atualize
                         updateMarkAsCompleteButton(currentLessonData.concluida);
                    }
                }
            });
            // [FIM DA MUDANÇA] Lógica de clique do botão "Marcar/Desmarcar"


            function updateOverallProgress() {
                let currentTotalAulas = 0;
                let currentAulasConcluidas = 0;

                allModulesData.forEach(moduleItem => {
                    moduleItem.aulas.forEach(aula => {
                        // Only count UNLOCKED lessons for overall progress
                        if (!aula.is_locked) { 
                            currentTotalAulas++;
                            if (aula.concluida) {
                                currentAulasConcluidas++;
                            }
                        }
                    });
                });

                const newProgressPercent = currentTotalAulas > 0 ? Math.round((currentAulasConcluidas / currentTotalAulas) * 100) : 0;
                
                document.querySelector('#player-wrapper .font-bold.text-white').textContent = `${newProgressPercent}% Completo`;
                const progressBar = document.querySelector('#player-wrapper .h-2\\.5');
                if (progressBar) {
                    progressBar.style.width = `${newProgressPercent}%`;
                    progressBar.style.backgroundColor = 'var(--accent-primary)';
                }
            }

            // Initial call to update progress bar on page load
            updateOverallProgress();
            
            // Render icons for paid module buttons (if any)
            lucide.createIcons();

            // Função para verificar e processar download protegido
            async function handleDownloadProtegido(lesson) {
                if (!lesson || lesson.tipo_conteudo !== 'download_protegido') {
                    return;
                }
                
                const csrfToken = window.csrfToken || document.querySelector('meta[name="csrf-token"]')?.content || '';
                
                if (!csrfToken) {
                    alert('Erro: Token de segurança não encontrado. Recarregue a página.');
                    return;
                }
                
                // Verificar se já existe consentimento
                try {
                    const requestData = {
                        aula_id: lesson.id,
                        email: clienteEmail,
                        csrf_token: csrfToken
                    };
                    
                    const checkResponse = await fetch('/api/check_download_consent.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': csrfToken
                        },
                        body: JSON.stringify(requestData)
                    });
                    
                    if (!checkResponse.ok) {
                        // Tentar obter resposta como JSON para verificar se há novo token
                        try {
                            const errorText = await checkResponse.text();
                            const errorJson = JSON.parse(errorText);
                            
                            // Se recebeu novo token CSRF, atualizar e tentar novamente
                            if (errorJson.new_csrf_token) {
                                window.csrfToken = errorJson.new_csrf_token;
                                const metaTag = document.querySelector('meta[name="csrf-token"]');
                                if (metaTag) {
                                    metaTag.setAttribute('content', errorJson.new_csrf_token);
                                }
                                
                                // Tentar novamente com novo token
                                const retryRequestData = {
                                    aula_id: lesson.id,
                                    email: clienteEmail,
                                    csrf_token: errorJson.new_csrf_token
                                };
                                
                                const retryResponse = await fetch('/api/check_download_consent.php', {
                                    method: 'POST',
                                    headers: {
                                        'Content-Type': 'application/json',
                                        'X-CSRF-Token': errorJson.new_csrf_token
                                    },
                                    body: JSON.stringify(retryRequestData)
                                });
                                
                                if (retryResponse.ok) {
                                    const retryResult = await retryResponse.json();
                                    
                                    // Atualizar token se foi renovado novamente
                                    if (retryResult.new_csrf_token) {
                                        window.csrfToken = retryResult.new_csrf_token;
                                        const metaTag = document.querySelector('meta[name="csrf-token"]');
                                        if (metaTag) {
                                            metaTag.setAttribute('content', retryResult.new_csrf_token);
                                        }
                                    }
                                    
                                    // Processar resultado do retry
                                    if (retryResult && retryResult.success === true && retryResult.has_consent === true && retryResult.download_url) {
                                        const isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
                                        
                                        if (isMobile) {
                                            try {
                                                const link = document.createElement('a');
                                                link.href = retryResult.download_url;
                                                link.target = '_blank';
                                                link.rel = 'noopener noreferrer';
                                                document.body.appendChild(link);
                                                link.click();
                                                document.body.removeChild(link);
                                            } catch (e) {
                                                window.location.href = retryResult.download_url;
                                            }
                                        } else {
                                            window.open(retryResult.download_url, '_blank');
                                        }
                                        return;
                                    } else {
                                        openDownloadConsentModal(lesson);
                                        return;
                                    }
                                }
                            }
                        } catch (e) {
                            // Erro ao processar resposta de erro
                        }
                        
                        // Se a resposta não for OK e não conseguiu retry, abrir modal
                        openDownloadConsentModal(lesson);
                        return;
                    }
                    
                    const checkResult = await checkResponse.json();
                    
                    // Atualizar token CSRF se foi renovado
                    if (checkResult.new_csrf_token) {
                        window.csrfToken = checkResult.new_csrf_token;
                        const metaTag = document.querySelector('meta[name="csrf-token"]');
                        if (metaTag) {
                            metaTag.setAttribute('content', checkResult.new_csrf_token);
                        }
                    }
                    
                    // Verificar se a resposta foi bem-sucedida e se tem consentimento
                    if (checkResult && checkResult.success === true && checkResult.has_consent === true && checkResult.download_url) {
                        // Já tem consentimento - fazer download direto
                        // Detectar mobile e usar método apropriado
                        const isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
                        
                        if (isMobile) {
                            // No mobile, usar location.href ou criar link com download
                            try {
                                const link = document.createElement('a');
                                link.href = checkResult.download_url;
                                link.target = '_blank';
                                link.rel = 'noopener noreferrer';
                                document.body.appendChild(link);
                                link.click();
                                document.body.removeChild(link);
                            } catch (e) {
                                // Fallback: usar location.href
                                window.location.href = checkResult.download_url;
                            }
                        } else {
                            // Desktop: usar window.open
                            window.open(checkResult.download_url, '_blank');
                        }
                        return;
                    }
                    
                    // Se chegou aqui, não tem consentimento - abrir modal
                    openDownloadConsentModal(lesson);
                    
                } catch (error) {
                    // Em caso de erro na verificação, abrir modal normalmente
                    openDownloadConsentModal(lesson);
                }
            }

            // Função para abrir modal de consentimento de download protegido
            function openDownloadConsentModal(lesson) {
                if (!lesson || lesson.tipo_conteudo !== 'download_protegido') return;
                
                // Preencher dados do modal
                document.getElementById('consent-modal-aula-id').value = lesson.id;
                document.getElementById('consent-modal-termos').innerHTML = (lesson.termos_consentimento || '').replace(/\n/g, '<br>');
                document.getElementById('consent-modal-email').value = clienteEmail;
                document.getElementById('consent-modal-cpf').value = '';
                document.getElementById('consent-modal-nome').value = '';
                document.getElementById('consent-modal-checkbox').checked = false;
                
                // Mostrar modal
                document.getElementById('download-consent-modal').classList.remove('hidden');
                lucide.createIcons(); // Re-renderizar ícones
            }

            // Função para fechar modal de consentimento
            function closeDownloadConsentModal() {
                document.getElementById('download-consent-modal').classList.add('hidden');
                // Limpar formulário
                document.getElementById('consent-form').reset();
                document.getElementById('consent-modal-email').value = clienteEmail; // Restaurar email
            }

            // Event listeners do modal
            const consentModalClose = document.getElementById('consent-modal-close');
            const consentModalCancel = document.getElementById('consent-modal-cancel');
            const consentModalOverlay = document.getElementById('download-consent-modal');
            
            if (consentModalClose) {
                consentModalClose.addEventListener('click', closeDownloadConsentModal);
            }
            if (consentModalCancel) {
                consentModalCancel.addEventListener('click', closeDownloadConsentModal);
            }
            if (consentModalOverlay) {
                consentModalOverlay.addEventListener('click', function(e) {
                    if (e.target === this) closeDownloadConsentModal();
                });
            }

            // Processar formulário de consentimento
            const consentForm = document.getElementById('consent-form');
            if (consentForm) {
                consentForm.addEventListener('submit', async function(e) {
                    e.preventDefault();
                    
                    const aulaId = document.getElementById('consent-modal-aula-id').value;
                    const email = document.getElementById('consent-modal-email').value;
                    const cpf = document.getElementById('consent-modal-cpf').value;
                    const nome = document.getElementById('consent-modal-nome').value;
                    const termosAceitos = document.getElementById('consent-modal-checkbox').checked;

                    // Validações
                    if (!termosAceitos) {
                        alert('Você deve concordar com os termos para continuar.');
                        return;
                    }

                    const cpfLimpo = cpf.replace(/\D/g, '');
                    if (!cpfLimpo || cpfLimpo.length !== 11) {
                        alert('Por favor, informe um CPF válido.');
                        return;
                    }

                    if (!nome || nome.trim().length < 3) {
                        alert('Por favor, informe seu nome completo.');
                        return;
                    }

                    // Desabilitar botão durante processamento
                    const submitBtn = document.getElementById('consent-modal-submit');
                    const originalText = submitBtn.textContent;
                    submitBtn.disabled = true;
                    submitBtn.textContent = 'Processando...';

                    try {
                        const csrfToken = window.csrfToken || document.querySelector('meta[name="csrf-token"]')?.content || '';
                        const response = await fetch('/api/process_download_consent.php', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-Token': csrfToken
                            },
                            body: JSON.stringify({
                                aula_id: aulaId,
                                email: email,
                                cpf: cpfLimpo,
                                nome: nome.trim(),
                                csrf_token: csrfToken
                            })
                        });

                        // Verificar se a resposta é OK antes de tentar parsear JSON
                        let result;
                        const responseText = await response.text();
                        
                        try {
                            result = JSON.parse(responseText);
                        } catch (e) {
                            // Se não for JSON válido, criar objeto de erro
                            result = {
                                success: false,
                                error: 'Resposta inválida do servidor. Tente novamente.'
                            };
                        }

                        // Atualizar token CSRF se foi renovado
                        if (result.new_csrf_token) {
                            window.csrfToken = result.new_csrf_token;
                            const metaTag = document.querySelector('meta[name="csrf-token"]');
                            if (metaTag) {
                                metaTag.setAttribute('content', result.new_csrf_token);
                            }
                        }
                        
                        if (result.success && result.download_url) {
                            // Fechar modal
                            closeDownloadConsentModal();
                            
                            // Detectar mobile e usar método apropriado
                            const isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
                            
                            if (isMobile) {
                                // No mobile, usar location.href ou criar link com download
                                try {
                                    const link = document.createElement('a');
                                    link.href = result.download_url;
                                    link.target = '_blank';
                                    link.rel = 'noopener noreferrer';
                                    document.body.appendChild(link);
                                    link.click();
                                    document.body.removeChild(link);
                                } catch (e) {
                                    // Fallback: usar location.href
                                    window.location.href = result.download_url;
                                }
                            } else {
                                // Desktop: usar window.open
                                window.open(result.download_url, '_blank');
                            }
                        } else {
                            // Mostrar mensagem de erro mais detalhada
                            let errorMsg = result.error || 'Erro ao processar consentimento. Tente novamente.';
                            
                            // Adicionar informações de debug se disponíveis
                            if (result.debug) {
                                // Criar mensagem mais detalhada baseada no debug
                                if (result.debug.aula_id === null || result.debug.aula_id === 0) {
                                    errorMsg = 'ID da aula não foi enviado corretamente. Recarregue a página e tente novamente.';
                                } else if (!result.debug.email) {
                                    errorMsg = 'Email não foi enviado corretamente. Verifique se o campo de email está preenchido.';
                                }
                            }
                            
                            // Se o status HTTP não for 200, adicionar informação
                            if (!response.ok) {
                                errorMsg += ' (Erro ' + response.status + ')';
                            }
                            
                            alert(errorMsg);
                            submitBtn.disabled = false;
                            submitBtn.textContent = originalText;
                        }
                    } catch (error) {
                        // Não usar console.error pois está bloqueado
                        // Tentar ler resposta como texto se JSON falhar
                        let errorMessage = 'Erro ao processar consentimento. Tente novamente.';
                        let shouldRetry = false;
                        let newToken = null;
                        
                        try {
                            // Tentar fazer a requisição novamente para capturar o erro
                            const currentToken = window.csrfToken || document.querySelector('meta[name="csrf-token"]')?.content || '';
                            const retryResponse = await fetch('/api/process_download_consent.php', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-CSRF-Token': currentToken
                                },
                                body: JSON.stringify({
                                    aula_id: aulaId,
                                    email: email,
                                    cpf: cpfLimpo,
                                    nome: nome.trim(),
                                    csrf_token: currentToken
                                })
                            });
                            
                            const text = await retryResponse.text();
                            try {
                                const jsonResult = JSON.parse(text);
                                errorMessage = jsonResult.error || errorMessage;
                                
                                // Se recebeu novo token CSRF, atualizar e tentar novamente
                                if (jsonResult.new_csrf_token) {
                                    newToken = jsonResult.new_csrf_token;
                                    window.csrfToken = newToken;
                                    const metaTag = document.querySelector('meta[name="csrf-token"]');
                                    if (metaTag) {
                                        metaTag.setAttribute('content', newToken);
                                    }
                                    
                                    // Se erro foi CSRF, tentar novamente automaticamente
                                    if (errorMessage.includes('CSRF') || errorMessage.includes('Token')) {
                                        shouldRetry = true;
                                    }
                                }
                            } catch (e) {
                                // Se não for JSON, usar a mensagem padrão
                                errorMessage = 'Erro ao processar consentimento. Verifique se todos os campos foram preenchidos corretamente.';
                            }
                            
                            // Se deve tentar novamente com novo token
                            if (shouldRetry && newToken) {
                                try {
                                    const finalResponse = await fetch('/api/process_download_consent.php', {
                                        method: 'POST',
                                        headers: {
                                            'Content-Type': 'application/json',
                                            'X-CSRF-Token': newToken
                                        },
                                        body: JSON.stringify({
                                            aula_id: aulaId,
                                            email: email,
                                            cpf: cpfLimpo,
                                            nome: nome.trim(),
                                            csrf_token: newToken
                                        })
                                    });
                                    
                                    const finalText = await finalResponse.text();
                                    const finalResult = JSON.parse(finalText);
                                    
                                    if (finalResult.success && finalResult.download_url) {
                                        // Sucesso no retry
                                        closeDownloadConsentModal();
                                        
                                        const isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
                                        
                                        if (isMobile) {
                                            try {
                                                const link = document.createElement('a');
                                                link.href = finalResult.download_url;
                                                link.target = '_blank';
                                                link.rel = 'noopener noreferrer';
                                                document.body.appendChild(link);
                                                link.click();
                                                document.body.removeChild(link);
                                            } catch (e) {
                                                window.location.href = finalResult.download_url;
                                            }
                                        } else {
                                            window.open(finalResult.download_url, '_blank');
                                        }
                                        
                                        submitBtn.disabled = false;
                                        submitBtn.textContent = originalText;
                                        return; // Sucesso no retry
                                    } else {
                                        errorMessage = finalResult.error || errorMessage;
                                    }
                                } catch (retryError) {
                                    // Falhou no retry também
                                }
                            }
                        } catch (e) {
                            errorMessage = 'Erro de rede ao processar consentimento. Verifique sua conexão e tente novamente.';
                        }
                        
                        alert(errorMessage);
                        submitBtn.disabled = false;
                        submitBtn.textContent = originalText;
                    }
                });
            }
        });
    </script>
    
    <!-- Proteções contra Inspeção e Clique Direito -->
    <script>
        (function() {
            'use strict';
            
            // Bloquear botão direito do mouse
            document.addEventListener('contextmenu', function(e) {
                e.preventDefault();
                return false;
            });
            
            // Bloquear atalhos de teclado comuns para DevTools
            document.addEventListener('keydown', function(e) {
                // F12
                if (e.keyCode === 123) {
                    e.preventDefault();
                    return false;
                }
                // Ctrl+Shift+I (Chrome/Edge)
                if (e.ctrlKey && e.shiftKey && e.keyCode === 73) {
                    e.preventDefault();
                    return false;
                }
                // Ctrl+Shift+J (Chrome/Edge Console)
                if (e.ctrlKey && e.shiftKey && e.keyCode === 74) {
                    e.preventDefault();
                    return false;
                }
                // Ctrl+Shift+C (Chrome/Edge Inspect)
                if (e.ctrlKey && e.shiftKey && e.keyCode === 67) {
                    e.preventDefault();
                    return false;
                }
                // Ctrl+U (View Source)
                if (e.ctrlKey && e.keyCode === 85) {
                    e.preventDefault();
                    return false;
                }
                // Ctrl+S (Save Page)
                if (e.ctrlKey && e.keyCode === 83) {
                    e.preventDefault();
                    return false;
                }
                // Ctrl+P (Print - pode ser usado para salvar como PDF)
                if (e.ctrlKey && e.keyCode === 80) {
                    e.preventDefault();
                    return false;
                }
                // Ctrl+A (Select All) - apenas em áreas sensíveis
                if (e.ctrlKey && e.keyCode === 65 && e.target.tagName !== 'INPUT' && e.target.tagName !== 'TEXTAREA') {
                    e.preventDefault();
                    return false;
                }
            });
            
            // Bloquear seleção de texto
            document.addEventListener('selectstart', function(e) {
                e.preventDefault();
                return false;
            });
            
            // Bloquear arrastar imagens
            document.addEventListener('dragstart', function(e) {
                if (e.target.tagName === 'IMG') {
                    e.preventDefault();
                    return false;
                }
            });
            
            // Bloquear arrastar qualquer elemento
            document.addEventListener('drag', function(e) {
                e.preventDefault();
                return false;
            });
            
            // Detectar quando DevTools é aberto (método de detecção por diferença de tamanho)
            let devtools = {
                open: false,
                orientation: null,
                warningShown: false
            };
            
            const threshold = 160;
            let checkCount = 0;
            
            setInterval(function() {
                const widthDiff = window.outerWidth - window.innerWidth;
                const heightDiff = window.outerHeight - window.innerHeight;
                
                // Verifica se DevTools está aberto
                if (widthDiff > threshold || heightDiff > threshold) {
                    if (!devtools.open) {
                        devtools.open = true;
                        checkCount++;
                        
                        // Mostrar aviso apenas após algumas verificações para evitar falsos positivos
                        if (checkCount >= 3 && !devtools.warningShown) {
                            devtools.warningShown = true;
                            const warningDiv = document.createElement('div');
                            warningDiv.id = 'devtools-warning';
                            warningDiv.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.95);z-index:999999;display:flex;align-items:center;justify-content:center;color:#fff;font-family:Arial;font-size:20px;text-align:center;padding:20px;';
                            warningDiv.innerHTML = '<div><h1 style="font-size:32px;margin-bottom:20px;">⚠️ Acesso Negado</h1><p style="margin-bottom:10px;">Ferramentas de desenvolvedor não são permitidas nesta página.</p><p style="font-size:14px;color:#999;margin-top:20px;">Por favor, feche as ferramentas de desenvolvedor e recarregue a página.</p></div>';
                            document.body.appendChild(warningDiv);
                            
                            // Bloquear interação com a página
                            document.body.style.pointerEvents = 'none';
                        }
                    }
                } else {
                    if (devtools.open) {
                        devtools.open = false;
                        checkCount = 0;
                        
                        // Remover aviso se DevTools foi fechado
                        const warningDiv = document.getElementById('devtools-warning');
                        if (warningDiv) {
                            warningDiv.remove();
                            document.body.style.pointerEvents = 'auto';
                            devtools.warningShown = false;
                        }
                    }
                }
            }, 500);
            
            // Bloquear console
            const noop = function() {};
            const methods = ['log', 'debug', 'info', 'warn', 'error', 'assert', 'dir', 'dirxml', 'group', 'groupEnd', 'time', 'timeEnd', 'count', 'trace', 'profile', 'profileEnd'];
            methods.forEach(function(method) {
                window.console[method] = noop;
            });
            
            // Bloquear acesso ao console via código
            Object.defineProperty(window, 'console', {
                get: function() {
                    return {};
                },
                set: function() {}
            });
            
        })();
    </script>
    
    <style>
        /* Bloquear seleção de texto via CSS */
        * {
            -webkit-user-select: none !important;
            -moz-user-select: none !important;
            -ms-user-select: none !important;
            user-select: none !important;
            -webkit-touch-callout: none !important;
            -webkit-tap-highlight-color: transparent !important;
        }
        
        /* Permitir seleção apenas em campos de input e textarea */
        input, textarea {
            -webkit-user-select: text !important;
            -moz-user-select: text !important;
            -ms-user-select: text !important;
            user-select: text !important;
        }
        
        /* Bloquear arrastar imagens */
        img {
            -webkit-user-drag: none !important;
            -khtml-user-drag: none !important;
            -moz-user-drag: none !important;
            -o-user-drag: none !important;
            user-drag: none !important;
        }
        
        /* Bloquear salvar imagens */
        img::selection {
            background: transparent !important;
        }
    </style>

    <!-- Modal de Consentimento para Download Protegido -->
    <div id="download-consent-modal" class="fixed inset-0 bg-black bg-opacity-60 z-50 flex items-center justify-center p-4 hidden">
        <div class="bg-gray-800 rounded-xl shadow-2xl w-full max-w-2xl max-h-[90vh] overflow-y-auto border border-gray-700">
            <div class="p-6 border-b border-gray-700">
                <div class="flex justify-between items-center">
                    <h2 class="text-2xl font-bold text-white">Termos de Consentimento</h2>
                    <button id="consent-modal-close" class="text-gray-400 hover:text-white">
                        <i data-lucide="x" class="w-6 h-6"></i>
                    </button>
                </div>
            </div>
            <form id="consent-form" class="p-6 space-y-4">
                <input type="hidden" id="consent-modal-aula-id" name="aula_id">
                
                <!-- Exibição dos Termos -->
                <div class="bg-gray-900 p-4 rounded-lg border border-gray-700 max-h-60 overflow-y-auto">
                    <div id="consent-modal-termos" class="text-gray-300 whitespace-pre-wrap"></div>
                </div>

                <!-- Checkbox de Concordância -->
                <div class="flex items-start space-x-3">
                    <input type="checkbox" id="consent-modal-checkbox" name="termos_aceitos" required class="mt-1 h-4 w-4 border-gray-600 rounded" style="color: var(--accent-primary); --tw-ring-color: var(--accent-primary);">
                    <label for="consent-modal-checkbox" class="text-gray-300 text-sm">
                        Concordo com os termos e condições acima
                    </label>
                </div>

                <!-- Campos do Cliente -->
                <div class="space-y-4 pt-4 border-t border-gray-700">
                    <div>
                        <label for="consent-modal-email" class="block text-gray-300 text-sm font-semibold mb-2">Email</label>
                        <input type="email" id="consent-modal-email" name="email" required readonly class="w-full px-4 py-3 bg-gray-900 border border-gray-700 rounded-lg text-white cursor-not-allowed" value="<?php echo htmlspecialchars($cliente_email); ?>">
                        <p class="mt-1 text-xs text-gray-400">Email preenchido automaticamente</p>
                    </div>

                    <div>
                        <label for="consent-modal-cpf" class="block text-gray-300 text-sm font-semibold mb-2">CPF</label>
                        <input type="text" id="consent-modal-cpf" name="cpf" required maxlength="14" placeholder="000.000.000-00" class="w-full px-4 py-3 bg-gray-900 border border-gray-700 rounded-lg text-white focus:outline-none focus:ring-2" style="--tw-ring-color: var(--accent-primary);" oninput="this.value = this.value.replace(/\D/g, '').replace(/(\d{3})(\d)/, '$1.$2').replace(/(\d{3})(\d)/, '$1.$2').replace(/(\d{3})(\d{1,2})$/, '$1-$2')">
                    </div>

                    <div>
                        <label for="consent-modal-nome" class="block text-gray-300 text-sm font-semibold mb-2">Nome Completo</label>
                        <input type="text" id="consent-modal-nome" name="nome" required minlength="3" placeholder="Seu nome completo" class="w-full px-4 py-3 bg-gray-900 border border-gray-700 rounded-lg text-white focus:outline-none focus:ring-2" style="--tw-ring-color: var(--accent-primary);">
                    </div>
                </div>

                <!-- Botões -->
                <div class="flex justify-end space-x-4 pt-4 border-t border-gray-700">
                    <button type="button" id="consent-modal-cancel" class="px-6 py-2 bg-gray-700 text-white rounded-lg hover:bg-gray-600 transition">
                        Cancelar
                    </button>
                    <button type="submit" id="consent-modal-submit" class="px-6 py-2 text-white rounded-lg transition" style="background-color: var(--accent-primary);" onmouseover="this.style.backgroundColor='var(--accent-primary-hover)'" onmouseout="this.style.backgroundColor='var(--accent-primary)'">
                        Confirmar e Baixar
                    </button>
                </div>
            </form>
        </div>
    </div>
    <div id="consent-modal-overlay" class="fixed inset-0 bg-black bg-opacity-60 z-40 hidden"></div>

    <script>
        // Sincronizar overlay com modal
        const consentModal = document.getElementById('download-consent-modal');
        const consentOverlay = document.getElementById('consent-modal-overlay');
        
        const observer = new MutationObserver(function(mutations) {
            mutations.forEach(function(mutation) {
                if (mutation.attributeName === 'class') {
                    if (consentModal.classList.contains('hidden')) {
                        consentOverlay.classList.add('hidden');
                    } else {
                        consentOverlay.classList.remove('hidden');
                    }
                }
            });
        });
        
        observer.observe(consentModal, {
            attributes: true,
            attributeFilter: ['class']
        });

    </script>
</body>
</html>