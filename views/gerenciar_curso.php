<?php
// Este arquivo é incluído a partir do index.php,
// então a verificação de login e a conexão com o banco ($pdo) já existem.

// Incluir helper de segurança para funções CSRF
require_once __DIR__ . '/../helpers/security_helper.php';

// Obter o ID do usuário logado
$usuario_id_logado = $_SESSION['id'] ?? 0;

// Se por algum motivo o ID do usuário não estiver definido, redireciona para o login
if ($usuario_id_logado === 0) {
    header("location: /login");
    exit;
}

$mensagem = '';
$produto = null;
$curso = null;
$modulos_com_aulas = [];
$upload_dir = 'uploads/';
$aula_files_dir = 'uploads/aula_files/';

// Garante que o diretório de arquivos de aula exista
if (!is_dir($aula_files_dir)) {
    mkdir($aula_files_dir, 0755, true);
}


// 1. Validar e buscar o produto_id
if (!isset($_GET['produto_id']) || !is_numeric($_GET['produto_id'])) {
    header("Location: /index?pagina=area_membros");
    exit;
}
$produto_id = (int)$_GET['produto_id'];

try {
    // 2. Buscar o produto e verificar se é do tipo 'area_membros' E pertence ao usuário logado
    $stmt_produto = $pdo->prepare("SELECT * FROM produtos WHERE id = ? AND tipo_entrega = 'area_membros' AND usuario_id = ?");
    $stmt_produto->execute([$produto_id, $usuario_id_logado]);
    $produto = $stmt_produto->fetch(PDO::FETCH_ASSOC);

    if (!$produto) {
        // Se o produto não for encontrado ou não pertencer ao usuário, redireciona
        $_SESSION['flash_message'] = "<div class='bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded relative mb-4' role='alert'>Produto não encontrado ou você não tem permissão para acessá-lo.</div>";
        header("Location: /index?pagina=area_membros");
        exit;
    }

    // 3. Sincronizar com a tabela 'cursos'
    $stmt_curso = $pdo->prepare("SELECT * FROM cursos WHERE produto_id = ?");
    $stmt_curso->execute([$produto_id]);
    $curso = $stmt_curso->fetch(PDO::FETCH_ASSOC);

    if (!$curso) {
        // Se o curso não existe, cria um novo
        $stmt_insert_curso = $pdo->prepare("INSERT INTO cursos (produto_id, titulo, descricao, imagem_url) VALUES (?, ?, ?, ?)");
        $stmt_insert_curso->execute([$produto_id, $produto['nome'], $produto['descricao'], $produto['foto'] ? 'uploads/' . $produto['foto'] : null]);
        
        // Busca o curso recém-criado
        $stmt_curso->execute([$produto_id]);
        $curso = $stmt_curso->fetch(PDO::FETCH_ASSOC);
    }
    $curso_id = $curso['id'];

    // 4. Lógica de manipulação de dados (POST requests)
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // Verifica CSRF (security_helper.php já foi incluído no início)
        if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
            $_SESSION['flash_message'] = "<div class='bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded relative mb-4' role='alert'>Token CSRF inválido ou ausente.</div>";
            header("Location: /index?pagina=gerenciar_curso&produto_id=" . $produto_id);
            exit;
        }
        
        $should_redirect = false; // Flag para controlar o redirecionamento

        // Função auxiliar para upload de arquivos (segura). $max_mb padrão 5 (banners hero aceitam 15).
        function handle_file_upload($file_key, $target_dir, $current_file_path = null, $max_mb = 5) {
            // security_helper.php já foi incluído no início
            
            if (isset($_FILES[$file_key]) && $_FILES[$file_key]['error'] === UPLOAD_ERR_OK) {
                error_log("handle_file_upload: Processando {$file_key}. Nome: " . ($_FILES[$file_key]['name'] ?? 'N/A') . ", Tamanho: " . ($_FILES[$file_key]['size'] ?? 0));
                
                // Deleta o arquivo antigo se existir (somente se o caminho for do diretório de uploads)
                if ($current_file_path && file_exists($current_file_path) && strpos($current_file_path, 'uploads/') === 0) {
                    @unlink($current_file_path);
                }
                
                // Valida e faz upload seguro - APENAS JPEG ou PNG para imagens de curso/módulo
                $upload_result = validate_image_upload($_FILES[$file_key], $target_dir, $file_key, $max_mb, true);
                if ($upload_result['success']) {
                    error_log("handle_file_upload: Upload bem-sucedido! Caminho: " . $upload_result['file_path']);
                    return $upload_result['file_path'];
                } else {
                    error_log("handle_file_upload: Upload falhou! Erro: " . ($upload_result['error'] ?? 'Erro desconhecido'));
                }
            } else {
                if (isset($_FILES[$file_key])) {
                    error_log("handle_file_upload: Erro no arquivo {$file_key}. Código de erro: " . ($_FILES[$file_key]['error'] ?? 'N/A'));
                } else {
                    error_log("handle_file_upload: Arquivo {$file_key} não encontrado em \$_FILES");
                }
            }
            return null; // Retorna null se não houver upload ou falhar
        }

        // Salvar Configurações do Curso (allow_comments)
        if (isset($_POST['salvar_config_curso'])) {
            $should_redirect = true;
            $allow_comments = isset($_POST['allow_comments']) ? 1 : 0;
            try {
                $stmt = $pdo->query("SHOW COLUMNS FROM cursos LIKE 'allow_comments'");
                if ($stmt->rowCount() > 0) {
                    $pdo->prepare("UPDATE cursos SET allow_comments = ? WHERE id = ?")->execute([$allow_comments, $curso_id]);
                }
                $curso['allow_comments'] = $allow_comments;
                $mensagem = "<div class='px-4 py-3 rounded' role='alert' style='background-color: color-mix(in srgb, var(--accent-primary) 20%, transparent); border: 1px solid var(--accent-primary); color: var(--accent-primary);'>Configurações salvas!</div>";
            } catch (PDOException $e) {
                $mensagem = "<div class='bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded' role='alert'>Erro ao salvar configurações.</div>";
            }
        }

        // Adicionar Seção
        if (isset($_POST['adicionar_secao'])) {
            $should_redirect = true;
            $titulo_secao = trim($_POST['titulo_secao'] ?? '');
            $tipo_secao = $_POST['tipo_secao'] ?? 'curso';
            if (!in_array($tipo_secao, ['curso', 'outros_produtos'])) $tipo_secao = 'curso';
            $tipo_capa = $_POST['tipo_capa'] ?? 'vertical';
            if (!in_array($tipo_capa, ['vertical', 'horizontal'])) $tipo_capa = 'vertical';
            try {
                $stmt = $pdo->query("SHOW TABLES LIKE 'secoes'");
                if ($stmt->rowCount() > 0 && $titulo_secao !== '') {
                    $max_ordem = $pdo->prepare("SELECT COALESCE(MAX(ordem), 0) + 1 FROM secoes WHERE curso_id = ?");
                    $max_ordem->execute([$curso_id]);
                    $ordem = (int)$max_ordem->fetchColumn();
                    // Verificar se coluna tipo_capa existe
                    $has_tipo_capa = false;
                    try {
                        $chk_col = $pdo->query("SHOW COLUMNS FROM secoes LIKE 'tipo_capa'");
                        $has_tipo_capa = $chk_col->rowCount() > 0;
                    } catch (PDOException $e) {}
                    
                    if ($has_tipo_capa) {
                        $pdo->prepare("INSERT INTO secoes (curso_id, titulo, tipo_secao, ordem, tipo_capa) VALUES (?, ?, ?, ?, ?)")->execute([$curso_id, $titulo_secao, $tipo_secao, $ordem, $tipo_capa]);
                    } else {
                        $pdo->prepare("INSERT INTO secoes (curso_id, titulo, tipo_secao, ordem) VALUES (?, ?, ?, ?)")->execute([$curso_id, $titulo_secao, $tipo_secao, $ordem]);
                    }
                    $mensagem = "<div class='px-4 py-3 rounded' role='alert' style='background-color: color-mix(in srgb, var(--accent-primary) 20%, transparent); border: 1px solid var(--accent-primary); color: var(--accent-primary);'>Seção adicionada!</div>";
                } elseif ($titulo_secao === '') {
                    $mensagem = "<div class='bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded' role='alert'>O título da seção é obrigatório.</div>";
                }
            } catch (PDOException $e) {
                $mensagem = "<div class='bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded' role='alert'>Erro ao adicionar seção.</div>";
            }
        }

        // Editar Seção
        if (isset($_POST['editar_secao'])) {
            $should_redirect = true;
            $secao_id_edit = (int)($_POST['secao_id'] ?? 0);
            $titulo_secao = trim($_POST['titulo_secao'] ?? '');
            $tipo_secao = $_POST['tipo_secao'] ?? 'curso';
            if (!in_array($tipo_secao, ['curso', 'outros_produtos'])) $tipo_secao = 'curso';
            $tipo_capa = $_POST['tipo_capa'] ?? 'vertical';
            if (!in_array($tipo_capa, ['vertical', 'horizontal'])) $tipo_capa = 'vertical';
            try {
                $stmt = $pdo->query("SHOW TABLES LIKE 'secoes'");
                if ($stmt->rowCount() > 0 && $secao_id_edit > 0 && $titulo_secao !== '') {
                    $check = $pdo->prepare("SELECT id FROM secoes WHERE id = ? AND curso_id = ?");
                    $check->execute([$secao_id_edit, $curso_id]);
                    if ($check->rowCount() > 0) {
                        // Verificar se coluna tipo_capa existe
                        $has_tipo_capa = false;
                        try {
                            $chk_col = $pdo->query("SHOW COLUMNS FROM secoes LIKE 'tipo_capa'");
                            $has_tipo_capa = $chk_col->rowCount() > 0;
                        } catch (PDOException $e) {}
                        
                        if ($has_tipo_capa) {
                            $pdo->prepare("UPDATE secoes SET titulo = ?, tipo_secao = ?, tipo_capa = ? WHERE id = ? AND curso_id = ?")->execute([$titulo_secao, $tipo_secao, $tipo_capa, $secao_id_edit, $curso_id]);
                        } else {
                            $pdo->prepare("UPDATE secoes SET titulo = ?, tipo_secao = ? WHERE id = ? AND curso_id = ?")->execute([$titulo_secao, $tipo_secao, $secao_id_edit, $curso_id]);
                        }
                        $mensagem = "<div class='px-4 py-3 rounded' role='alert' style='background-color: color-mix(in srgb, var(--accent-primary) 20%, transparent); border: 1px solid var(--accent-primary); color: var(--accent-primary);'>Seção atualizada!</div>";
                    }
                }
            } catch (PDOException $e) {
                $mensagem = "<div class='bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded' role='alert'>Erro ao atualizar seção.</div>";
            }
        }

        // Deletar Seção
        if (isset($_POST['deletar_secao'])) {
            $should_redirect = true;
            $secao_id_del = (int)($_POST['secao_id'] ?? 0);
            try {
                $stmt = $pdo->query("SHOW TABLES LIKE 'secoes'");
                if ($stmt->rowCount() > 0 && $secao_id_del > 0) {
                    $pdo->prepare("DELETE FROM secoes WHERE id = ? AND curso_id = ?")->execute([$secao_id_del, $curso_id]);
                    $mensagem = "<div class='px-4 py-3 rounded' role='alert' style='background-color: color-mix(in srgb, var(--accent-primary) 20%, transparent); border: 1px solid var(--accent-primary); color: var(--accent-primary);'>Seção removida.</div>";
                }
            } catch (PDOException $e) {
                $mensagem = "<div class='bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded' role='alert'>Erro ao remover seção.</div>";
            }
        }

        // Seção: adicionar produto (tipo outros_produtos)
        if (isset($_POST['secao_adicionar_produto'])) {
            $should_redirect = true;
            $secao_id = (int)($_POST['secao_id'] ?? 0);
            $produto_id_add = (int)($_POST['produto_id'] ?? 0);
            
            // DEBUG: Verificar se chegou aqui
            error_log("=== ADICIONAR_PRODUTO INICIADO ===");
            error_log("ADICIONAR_PRODUTO: secao_id={$secao_id}, produto_id={$produto_id_add}");
            error_log("ADICIONAR_PRODUTO: POST completo: " . print_r($_POST, true));
            error_log("ADICIONAR_PRODUTO: FILES completo: " . print_r($_FILES, true));
            
            try {
                $stmt = $pdo->query("SHOW TABLES LIKE 'secao_produtos'");
                if ($stmt->rowCount() > 0 && $secao_id > 0 && $produto_id_add > 0) {
                    $check_secao = $pdo->prepare("SELECT id FROM secoes WHERE id = ? AND curso_id = ?");
                    $check_secao->execute([$secao_id, $curso_id]);
                    if ($check_secao->rowCount() > 0) {
                        $check_prod = $pdo->prepare("SELECT id FROM produtos WHERE id = ? AND usuario_id = ?");
                        $check_prod->execute([$produto_id_add, $usuario_id_logado]);
                        if ($check_prod->rowCount() > 0) {
                            // Verificar se produto já existe na seção
                            $stmt_check_existing = $pdo->prepare("SELECT id, imagem_capa_url FROM secao_produtos WHERE secao_id = ? AND produto_id = ?");
                            $stmt_check_existing->execute([$secao_id, $produto_id_add]);
                            $existing = $stmt_check_existing->fetch(PDO::FETCH_ASSOC);
                            
                            // Verificar se colunas existem
                            $has_imagem_col = false;
                            $has_link_col = false;
                            try {
                                $chk_col = $pdo->query("SHOW COLUMNS FROM secao_produtos LIKE 'imagem_capa_url'");
                                $has_imagem_col = $chk_col->rowCount() > 0;
                                $chk_col_link = $pdo->query("SHOW COLUMNS FROM secao_produtos LIKE 'link_personalizado'");
                                $has_link_col = $chk_col_link->rowCount() > 0;
                            } catch (PDOException $e) {}
                            
                            // Processar link personalizado
                            $tipo_link = $_POST['tipo_link_produto'] ?? 'padrao';
                            $link_personalizado = null;
                            if ($tipo_link === 'personalizado' && !empty($_POST['link_personalizado'])) {
                                $link_personalizado = trim($_POST['link_personalizado']);
                                // Validar URL básica
                                if (!filter_var($link_personalizado, FILTER_VALIDATE_URL)) {
                                    $mensagem = "<div class='bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded' role='alert'>URL do link personalizado inválida.</div>";
                                    $link_personalizado = null;
                                }
                            }
                            
                            if ($existing) {
                                // Produto já existe - atualizar imagem e link se houver mudanças
                                $update_fields = [];
                                $update_values = [];
                                
                                if ($has_imagem_col) {
                                    $current_imagem = $existing['imagem_capa_url'] ?? null;
                                    $nova_imagem_path = handle_file_upload('imagem_capa_produto', $upload_dir, $current_imagem);
                                    if ($nova_imagem_path !== $current_imagem) {
                                        $update_fields[] = "imagem_capa_url = ?";
                                        $update_values[] = $nova_imagem_path;
                                    }
                                }
                                
                                if ($has_link_col) {
                                    $update_fields[] = "link_personalizado = ?";
                                    $update_values[] = $link_personalizado;
                                }
                                
                                if (!empty($update_fields)) {
                                    $update_values[] = $secao_id;
                                    $update_values[] = $produto_id_add;
                                    $stmt_update = $pdo->prepare("UPDATE secao_produtos SET " . implode(", ", $update_fields) . " WHERE secao_id = ? AND produto_id = ?");
                                    $stmt_update->execute($update_values);
                                    $mensagem = "<div class='px-4 py-3 rounded' role='alert' style='background-color: color-mix(in srgb, var(--accent-primary) 20%, transparent); border: 1px solid var(--accent-primary); color: var(--accent-primary);'>Produto atualizado na seção.</div>";
                                } else {
                                    $mensagem = "<div class='px-4 py-3 rounded' role='alert' style='background-color: color-mix(in srgb, var(--accent-primary) 20%, transparent); border: 1px solid var(--accent-primary); color: var(--accent-primary);'>Produto já está na seção.</div>";
                                }
                            } else {
                                // Produto não existe - inserir novo
                                $max = $pdo->prepare("SELECT COALESCE(MAX(ordem), 0) + 1 FROM secao_produtos WHERE secao_id = ?");
                                $max->execute([$secao_id]);
                                $ordem = (int)$max->fetchColumn();
                                
                                // Processar upload de imagem (se houver)
                                $imagem_capa_url = null;
                                if ($has_imagem_col) {
                                    $imagem_capa_url = handle_file_upload('imagem_capa_produto', $upload_dir, null);
                                }
                                
                                // Montar INSERT baseado nas colunas disponíveis
                                if ($has_imagem_col && $has_link_col) {
                                    $stmt_insert = $pdo->prepare("INSERT INTO secao_produtos (secao_id, produto_id, ordem, imagem_capa_url, link_personalizado) VALUES (?, ?, ?, ?, ?)");
                                    $stmt_insert->execute([$secao_id, $produto_id_add, $ordem, $imagem_capa_url, $link_personalizado]);
                                } elseif ($has_imagem_col) {
                                    $stmt_insert = $pdo->prepare("INSERT INTO secao_produtos (secao_id, produto_id, ordem, imagem_capa_url) VALUES (?, ?, ?, ?)");
                                    $stmt_insert->execute([$secao_id, $produto_id_add, $ordem, $imagem_capa_url]);
                                } elseif ($has_link_col) {
                                    $stmt_insert = $pdo->prepare("INSERT INTO secao_produtos (secao_id, produto_id, ordem, link_personalizado) VALUES (?, ?, ?, ?)");
                                    $stmt_insert->execute([$secao_id, $produto_id_add, $ordem, $link_personalizado]);
                                } else {
                                    $stmt_insert = $pdo->prepare("INSERT INTO secao_produtos (secao_id, produto_id, ordem) VALUES (?, ?, ?)");
                                    $stmt_insert->execute([$secao_id, $produto_id_add, $ordem]);
                                }
                                
                                $mensagem = "<div class='px-4 py-3 rounded' role='alert' style='background-color: color-mix(in srgb, var(--accent-primary) 20%, transparent); border: 1px solid var(--accent-primary); color: var(--accent-primary);'>Produto adicionado à seção" . ($imagem_capa_url ? " com imagem de capa" : "") . ($link_personalizado ? " e link personalizado" : "") . ".</div>";
                            }
                        } else {
                            error_log("ADICIONAR_PRODUTO: Produto não pertence ao usuário ou não existe");
                        }
                    } else {
                        error_log("ADICIONAR_PRODUTO: Seção não pertence ao curso ou não existe");
                    }
                } else {
                    error_log("ADICIONAR_PRODUTO: Tabela secao_produtos não existe ou parâmetros inválidos");
                }
            } catch (PDOException $e) {
                error_log("ADICIONAR_PRODUTO: Exceção PDO: " . $e->getMessage());
                $mensagem = "<div class='bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded' role='alert'>Erro ao adicionar produto à seção: " . htmlspecialchars($e->getMessage()) . "</div>";
            }
        }

        // Seção: editar produto (tipo outros_produtos)
        if (isset($_POST['secao_editar_produto'])) {
            $should_redirect = true;
            $secao_id = (int)($_POST['secao_id'] ?? 0);
            $produto_id_edit = (int)($_POST['produto_id'] ?? 0);
            
            // Validação: secao_id e produto_id são obrigatórios
            if ($secao_id <= 0 || $produto_id_edit <= 0) {
                $mensagem = "<div class='bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded' role='alert'>Erro: Dados inválidos. Seção ID: {$secao_id}, Produto ID: {$produto_id_edit}. O campo secao_id não está sendo preenchido corretamente. Verifique o console do navegador (F12) para mais detalhes.</div>";
            } else {
                try {
                    $stmt = $pdo->query("SHOW TABLES LIKE 'secao_produtos'");
                    if ($stmt->rowCount() > 0) {
                        $check_secao = $pdo->prepare("SELECT id FROM secoes WHERE id = ? AND curso_id = ?");
                        $check_secao->execute([$secao_id, $curso_id]);
                        if ($check_secao->rowCount() > 0) {
                            // Verificar se colunas existem
                            $has_imagem_col = false;
                            $has_link_col = false;
                            try {
                                $chk_col = $pdo->query("SHOW COLUMNS FROM secao_produtos LIKE 'imagem_capa_url'");
                                $has_imagem_col = $chk_col->rowCount() > 0;
                                $chk_col_link = $pdo->query("SHOW COLUMNS FROM secao_produtos LIKE 'link_personalizado'");
                                $has_link_col = $chk_col_link->rowCount() > 0;
                            } catch (PDOException $e) {}
                            
                            // Buscar dados atuais do produto na seção
                            $select_fields = [];
                            if ($has_imagem_col) $select_fields[] = "imagem_capa_url";
                            if ($has_link_col) $select_fields[] = "link_personalizado";
                            
                            if (!empty($select_fields)) {
                                $stmt_current = $pdo->prepare("SELECT " . implode(", ", $select_fields) . " FROM secao_produtos WHERE secao_id = ? AND produto_id = ?");
                                $stmt_current->execute([$secao_id, $produto_id_edit]);
                                $current = $stmt_current->fetch(PDO::FETCH_ASSOC);
                                
                                if ($current) {
                                    $update_fields = [];
                                    $update_values = [];
                                    
                                    // Processar imagem de capa
                                    if ($has_imagem_col) {
                                        $nova_imagem_path = handle_file_upload('imagem_capa_produto', $upload_dir, $current['imagem_capa_url'] ?? null);
                                        
                                        if ($nova_imagem_path && $nova_imagem_path !== ($current['imagem_capa_url'] ?? null)) {
                                            // Nova imagem foi enviada
                                            $update_fields[] = "imagem_capa_url = ?";
                                            $update_values[] = $nova_imagem_path;
                                        } else if (!empty($_POST['remove_imagem_capa_produto'])) {
                                            // Remover imagem
                                            if (($current['imagem_capa_url'] ?? null) && file_exists($current['imagem_capa_url']) && strpos($current['imagem_capa_url'], 'uploads/') === 0) {
                                                @unlink($current['imagem_capa_url']);
                                            }
                                            $update_fields[] = "imagem_capa_url = NULL";
                                        }
                                    }
                                    
                                    // Processar link personalizado
                                    if ($has_link_col) {
                                        $tipo_link = $_POST['tipo_link_produto_edit'] ?? 'padrao';
                                        $link_personalizado = null;
                                        if ($tipo_link === 'personalizado' && !empty($_POST['link_personalizado'])) {
                                            $link_personalizado = trim($_POST['link_personalizado']);
                                            // Validar URL básica
                                            if (!filter_var($link_personalizado, FILTER_VALIDATE_URL)) {
                                                $mensagem = "<div class='bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded' role='alert'>URL do link personalizado inválida.</div>";
                                                $link_personalizado = null;
                                            }
                                        }
                                        
                                        // Atualizar link apenas se mudou
                                        if ($link_personalizado !== ($current['link_personalizado'] ?? null)) {
                                            $update_fields[] = "link_personalizado = ?";
                                            $update_values[] = $link_personalizado;
                                        }
                                    }
                                    
                                    // Executar UPDATE se houver mudanças
                                    if (!empty($update_fields)) {
                                        // Separar campos com valores e campos NULL
                                        $fields_with_values = [];
                                        $fields_null = [];
                                        $values = [];
                                        
                                        foreach ($update_fields as $idx => $field) {
                                            if (strpos($field, '= NULL') !== false) {
                                                $fields_null[] = str_replace(' = NULL', '', $field) . ' = NULL';
                                            } else {
                                                $fields_with_values[] = $field;
                                                $values[] = $update_values[$idx];
                                            }
                                        }
                                        
                                        // Combinar todos os campos
                                        $all_fields = array_merge($fields_with_values, $fields_null);
                                        $values[] = $secao_id;
                                        $values[] = $produto_id_edit;
                                        
                                        $sql = "UPDATE secao_produtos SET " . implode(", ", $all_fields) . " WHERE secao_id = ? AND produto_id = ?";
                                        $stmt_update = $pdo->prepare($sql);
                                        $stmt_update->execute($values);
                                        
                                        $mensagem = "<div class='px-4 py-3 rounded' role='alert' style='background-color: color-mix(in srgb, var(--accent-primary) 20%, transparent); border: 1px solid var(--accent-primary); color: var(--accent-primary);'>Produto atualizado com sucesso.</div>";
                                    } else {
                                        $mensagem = "<div class='px-4 py-3 rounded' role='alert' style='background-color: color-mix(in srgb, var(--accent-primary) 20%, transparent); border: 1px solid var(--accent-primary); color: var(--accent-primary);'>Nenhuma alteração realizada.</div>";
                                    }
                                } else {
                                    $mensagem = "<div class='bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded' role='alert'>Produto não encontrado na seção.</div>";
                                }
                            } else {
                                $mensagem = "<div class='bg-yellow-900/20 border border-yellow-500 text-yellow-300 px-4 py-3 rounded' role='alert'>Funcionalidade ainda não está disponível. Execute as migrations.</div>";
                            }
                        } else {
                            $mensagem = "<div class='bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded' role='alert'>Seção não encontrada ou não pertence a este curso.</div>";
                        }
                    } else {
                        $mensagem = "<div class='bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded' role='alert'>Tabela secao_produtos não encontrada.</div>";
                    }
                } catch (PDOException $e) {
                    @file_put_contents(__DIR__ . '/../debug_editar_produto.log', "ERRO: " . $e->getMessage() . "\n", FILE_APPEND);
                    $mensagem = "<div class='bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded' role='alert'>Erro ao atualizar produto: " . htmlspecialchars($e->getMessage()) . "</div>";
                }
            }
        }

        // Seção: remover produto
        if (isset($_POST['secao_remover_produto'])) {
            $should_redirect = true;
            $secao_id = (int)($_POST['secao_id'] ?? 0);
            $produto_id_rem = (int)($_POST['produto_id'] ?? 0);
            try {
                $stmt = $pdo->query("SHOW TABLES LIKE 'secao_produtos'");
                if ($stmt->rowCount() > 0 && $secao_id > 0 && $produto_id_rem > 0) {
                    // Verificar se coluna imagem_capa_url existe e deletar arquivo se existir
                    $has_imagem_col = false;
                    try {
                        $chk_col = $pdo->query("SHOW COLUMNS FROM secao_produtos LIKE 'imagem_capa_url'");
                        $has_imagem_col = $chk_col->rowCount() > 0;
                    } catch (PDOException $e) {}
                    
                    if ($has_imagem_col) {
                        // Buscar imagem antes de deletar
                        $stmt_img = $pdo->prepare("SELECT imagem_capa_url FROM secao_produtos WHERE secao_id = ? AND produto_id = ?");
                        $stmt_img->execute([$secao_id, $produto_id_rem]);
                        $img_data = $stmt_img->fetch(PDO::FETCH_ASSOC);
                        if ($img_data && !empty($img_data['imagem_capa_url']) && file_exists($img_data['imagem_capa_url']) && strpos($img_data['imagem_capa_url'], 'uploads/') === 0) {
                            @unlink($img_data['imagem_capa_url']);
                        }
                    }
                    
                    $pdo->prepare("DELETE sp FROM secao_produtos sp INNER JOIN secoes s ON sp.secao_id = s.id WHERE sp.secao_id = ? AND sp.produto_id = ? AND s.curso_id = ?")->execute([$secao_id, $produto_id_rem, $curso_id]);
                    $mensagem = "<div class='px-4 py-3 rounded' role='alert' style='background-color: color-mix(in srgb, var(--accent-primary) 20%, transparent); border: 1px solid var(--accent-primary); color: var(--accent-primary);'>Produto removido da seção.</div>";
                }
            } catch (PDOException $e) {
                $mensagem = "<div class='bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded' role='alert'>Erro ao remover produto.</div>";
            }
        }

        // Comunidade: adicionar categoria
        if (isset($_POST['comunidade_adicionar_categoria'])) {
            $should_redirect = true;
            $cat_nome = trim($_POST['categoria_nome'] ?? '');
            $is_public = isset($_POST['categoria_public_posting']) ? 1 : 0;
            try {
                $stmt = $pdo->query("SHOW TABLES LIKE 'comunidade_categorias'");
                if ($stmt->rowCount() > 0 && $cat_nome !== '') {
                    $max_ordem = $pdo->prepare("SELECT COALESCE(MAX(ordem), 0) + 1 FROM comunidade_categorias WHERE curso_id = ?");
                    $max_ordem->execute([$curso_id]);
                    $ordem = (int)$max_ordem->fetchColumn();
                    $pdo->prepare("INSERT INTO comunidade_categorias (curso_id, nome, is_public_posting, ordem) VALUES (?, ?, ?, ?)")->execute([$curso_id, $cat_nome, $is_public, $ordem]);
                    $mensagem = "<div class='px-4 py-3 rounded' role='alert' style='background-color: color-mix(in srgb, var(--accent-primary) 20%, transparent); border: 1px solid var(--accent-primary); color: var(--accent-primary);'>Categoria do feed adicionada!</div>";
                }
            } catch (PDOException $e) {
                $mensagem = "<div class='bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded' role='alert'>Erro ao adicionar categoria.</div>";
            }
        }

        // Comunidade: editar categoria
        if (isset($_POST['comunidade_editar_categoria'])) {
            $should_redirect = true;
            $cat_id = (int)($_POST['categoria_id'] ?? 0);
            $cat_nome = trim($_POST['categoria_nome'] ?? '');
            $is_public = isset($_POST['categoria_public_posting']) ? 1 : 0;
            try {
                $stmt = $pdo->query("SHOW TABLES LIKE 'comunidade_categorias'");
                if ($stmt->rowCount() > 0 && $cat_id > 0 && $cat_nome !== '') {
                    $pdo->prepare("UPDATE comunidade_categorias SET nome = ?, is_public_posting = ? WHERE id = ? AND curso_id = ?")->execute([$cat_nome, $is_public, $cat_id, $curso_id]);
                    $mensagem = "<div class='px-4 py-3 rounded' role='alert' style='background-color: color-mix(in srgb, var(--accent-primary) 20%, transparent); border: 1px solid var(--accent-primary); color: var(--accent-primary);'>Categoria atualizada!</div>";
                }
            } catch (PDOException $e) {
                $mensagem = "<div class='bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded' role='alert'>Erro ao atualizar categoria.</div>";
            }
        }

        // Comunidade: deletar categoria
        if (isset($_POST['comunidade_deletar_categoria'])) {
            $should_redirect = true;
            $cat_id = (int)($_POST['categoria_id'] ?? 0);
            try {
                $stmt = $pdo->query("SHOW TABLES LIKE 'comunidade_categorias'");
                if ($stmt->rowCount() > 0 && $cat_id > 0) {
                    $pdo->prepare("DELETE FROM comunidade_categorias WHERE id = ? AND curso_id = ?")->execute([$cat_id, $curso_id]);
                    $mensagem = "<div class='px-4 py-3 rounded' role='alert' style='background-color: color-mix(in srgb, var(--accent-primary) 20%, transparent); border: 1px solid var(--accent-primary); color: var(--accent-primary);'>Categoria removida.</div>";
                }
            } catch (PDOException $e) {
                $mensagem = "<div class='bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded' role='alert'>Erro ao remover categoria.</div>";
            }
        }

        // Salvar Banner do Curso (desktop, mobile, logo — máx 15MB cada)
        if (isset($_POST['salvar_banner_curso'])) {
            $should_redirect = true;
            $updates = [];
            $params = [];
            $has_desktop_col = $pdo->query("SHOW COLUMNS FROM cursos LIKE 'banner_desktop_url'")->rowCount() > 0;
            $has_mobile_col  = $pdo->query("SHOW COLUMNS FROM cursos LIKE 'banner_mobile_url'")->rowCount() > 0;
            $has_logo_col    = $pdo->query("SHOW COLUMNS FROM cursos LIKE 'banner_logo_url'")->rowCount() > 0;

            // Desktop (2560x1280)
            if ($has_desktop_col) {
                $banner_desktop_url = $curso['banner_desktop_url'] ?? $curso['banner_url'] ?? null;
                if (!empty($_POST['remove_banner_desktop']) && $banner_desktop_url && file_exists($banner_desktop_url) && strpos($banner_desktop_url, 'uploads/') === 0) {
                    @unlink($banner_desktop_url);
                    $updates[] = 'banner_desktop_url = ?';
                    $params[] = null;
                } else {
                    $novo_desktop = handle_file_upload('banner_desktop', $upload_dir, $banner_desktop_url, 15);
                    if ($novo_desktop) {
                        $updates[] = 'banner_desktop_url = ?';
                        $params[] = $novo_desktop;
                    }
                }
            }

            // Mobile (1630x1920)
            if ($has_mobile_col) {
                $banner_mobile_url = $curso['banner_mobile_url'] ?? null;
                if (!empty($_POST['remove_banner_mobile']) && $banner_mobile_url && file_exists($banner_mobile_url) && strpos($banner_mobile_url, 'uploads/') === 0) {
                    @unlink($banner_mobile_url);
                    $updates[] = 'banner_mobile_url = ?';
                    $params[] = null;
                } else {
                    $novo_mobile = handle_file_upload('banner_mobile', $upload_dir, $banner_mobile_url, 15);
                    if ($novo_mobile) {
                        $updates[] = 'banner_mobile_url = ?';
                        $params[] = $novo_mobile;
                    }
                }
            }

            // Logo (canto esquerdo)
            if ($has_logo_col) {
                $banner_logo_url = $curso['banner_logo_url'] ?? null;
                if (!empty($_POST['remove_banner_logo']) && $banner_logo_url && file_exists($banner_logo_url) && strpos($banner_logo_url, 'uploads/') === 0) {
                    @unlink($banner_logo_url);
                    $updates[] = 'banner_logo_url = ?';
                    $params[] = null;
                } else {
                    $novo_logo = handle_file_upload('banner_logo', $upload_dir, $banner_logo_url, 15);
                    if ($novo_logo) {
                        $updates[] = 'banner_logo_url = ?';
                        $params[] = $novo_logo;
                    }
                }
            }

            // Legado: um único arquivo "banner_curso" (atualiza banner_url; se colunas novas existirem, também desktop)
            $novo_banner_path = handle_file_upload('banner_curso', $upload_dir, $curso['banner_url'] ?? null, 15);
            if ($novo_banner_path) {
                $updates[] = 'banner_url = ?';
                $params[] = $novo_banner_path;
                $desktop_uploaded = !empty($_FILES['banner_desktop']['tmp_name']) && $_FILES['banner_desktop']['error'] === UPLOAD_ERR_OK;
                if ($has_desktop_col && !$desktop_uploaded) {
                    $updates[] = 'banner_desktop_url = COALESCE(banner_desktop_url, ?)';
                    $params[] = $novo_banner_path;
                }
            } elseif (!empty($_POST['remove_banner']) && !empty($curso['banner_url']) && file_exists($curso['banner_url']) && strpos($curso['banner_url'], 'uploads/') === 0) {
                @unlink($curso['banner_url']);
                $updates[] = 'banner_url = ?';
                $params[] = null;
            }

            if (!empty($updates)) {
                $params[] = $curso_id;
                $stmt = $pdo->prepare("UPDATE cursos SET " . implode(', ', $updates) . " WHERE id = ?");
                $stmt->execute($params);
                $mensagem = "<div class='px-4 py-3 rounded' role='alert' style='background-color: color-mix(in srgb, var(--accent-primary) 20%, transparent); border: 1px solid var(--accent-primary); color: var(--accent-primary);'>Banner(s) atualizado(s)!</div>";
            } else {
                $mensagem = "<div class='bg-yellow-900/20 border border-yellow-500 text-yellow-300 px-4 py-3 rounded' role='alert'>Nenhuma alteração de imagem enviada.</div>";
            }
        }

        // Adicionar Módulo
        if (isset($_POST['adicionar_modulo'])) {
            $should_redirect = true;
            $titulo_modulo = trim($_POST['titulo_modulo']);
            $release_days_modulo = (int)($_POST['release_days_modulo'] ?? 0);
            $is_paid_module = isset($_POST['is_paid_module']) ? 1 : 0;
            $linked_product_id = null;
            
            if ($is_paid_module) {
                $linked_product_id = !empty($_POST['linked_product_id']) ? (int)$_POST['linked_product_id'] : null;
                
                // Validar que o produto pertence ao mesmo infoprodutor
                if ($linked_product_id) {
                    $stmt_check_prod = $pdo->prepare("SELECT id FROM produtos WHERE id = ? AND usuario_id = ?");
                    $stmt_check_prod->execute([$linked_product_id, $usuario_id_logado]);
                    if ($stmt_check_prod->rowCount() === 0) {
                        $linked_product_id = null;
                        $is_paid_module = 0;
                        $mensagem = "<div class='bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded' role='alert'>Produto selecionado inválido. Módulo criado como gratuito.</div>";
                    }
                } else {
                    $is_paid_module = 0;
                    $mensagem = "<div class='bg-yellow-900/20 border border-yellow-500 text-yellow-300 px-4 py-3 rounded' role='alert'>Módulo pago selecionado mas nenhum produto escolhido. Módulo criado como gratuito.</div>";
                }
            }
            
            if (!empty($titulo_modulo)) {
                $secao_id_mod = !empty($_POST['secao_id']) ? (int)$_POST['secao_id'] : null;
                $has_secao_col = false;
                try {
                    $chk = $pdo->query("SHOW COLUMNS FROM modulos LIKE 'secao_id'");
                    $has_secao_col = $chk->rowCount() > 0;
                } catch (PDOException $e) {}
                if ($has_secao_col) {
                    $stmt = $pdo->prepare("INSERT INTO modulos (curso_id, secao_id, titulo, release_days, is_paid_module, linked_product_id) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$curso_id, $secao_id_mod, $titulo_modulo, $release_days_modulo, $is_paid_module, $linked_product_id]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO modulos (curso_id, titulo, release_days, is_paid_module, linked_product_id) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$curso_id, $titulo_modulo, $release_days_modulo, $is_paid_module, $linked_product_id]);
                }
                if (empty($mensagem)) {
                    $mensagem = "<div class='px-4 py-3 rounded' role='alert' style='background-color: color-mix(in srgb, var(--accent-primary) 20%, transparent); border: 1px solid var(--accent-primary); color: var(--accent-primary);'>Módulo adicionado!</div>";
                }
            } else {
                $mensagem = "<div class='bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded' role='alert'>O título do módulo não pode estar vazio.</div>";
            }
        }
        
        // Editar Módulo (Título, Capa, Release Days e Módulo Pago)
        if (isset($_POST['editar_modulo'])) {
            $should_redirect = true;
            $modulo_id_edit = $_POST['modulo_id'];
            $titulo_modulo_edit = trim($_POST['titulo_modulo']);
            $release_days_edit = (int)($_POST['release_days_modulo'] ?? 0);
            $is_paid_module = isset($_POST['is_paid_module']) ? 1 : 0;
            $linked_product_id = null;
            
            if ($is_paid_module) {
                $linked_product_id = !empty($_POST['linked_product_id']) ? (int)$_POST['linked_product_id'] : null;
                
                // Validar que o produto pertence ao mesmo infoprodutor
                if ($linked_product_id) {
                    $stmt_check_prod = $pdo->prepare("SELECT id FROM produtos WHERE id = ? AND usuario_id = ?");
                    $stmt_check_prod->execute([$linked_product_id, $usuario_id_logado]);
                    if ($stmt_check_prod->rowCount() === 0) {
                        $linked_product_id = null;
                        $is_paid_module = 0;
                        $mensagem = "<div class='bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded' role='alert'>Produto selecionado inválido. Módulo atualizado como gratuito.</div>";
                    }
                } else {
                    $is_paid_module = 0;
                    $mensagem = "<div class='bg-yellow-900/20 border border-yellow-500 text-yellow-300 px-4 py-3 rounded' role='alert'>Módulo pago selecionado mas nenhum produto escolhido. Módulo atualizado como gratuito.</div>";
                }
            }

            // Busca dados atuais do módulo para pegar o caminho da imagem antiga
            $stmt_old_mod = $pdo->prepare("SELECT imagem_capa_url FROM modulos WHERE id = ? AND curso_id = ?");
            $stmt_old_mod->execute([$modulo_id_edit, $curso_id]);
            $old_module = $stmt_old_mod->fetch(PDO::FETCH_ASSOC);

            $secao_id_edit_mod = !empty($_POST['secao_id']) ? (int)$_POST['secao_id'] : null;
            $has_secao_col_edit = false;
            try {
                $chk = $pdo->query("SHOW COLUMNS FROM modulos LIKE 'secao_id'");
                $has_secao_col_edit = $chk->rowCount() > 0;
            } catch (PDOException $e) {}

            if ($old_module) {
                $nova_imagem_path = handle_file_upload('imagem_capa_modulo', $upload_dir, $old_module['imagem_capa_url']);
                error_log("EDITAR_MODULO: nova_imagem_path = " . ($nova_imagem_path ?? 'NULL') . ", old_module['imagem_capa_url'] = " . ($old_module['imagem_capa_url'] ?? 'NULL'));
                $sql_titulo_imagem = "UPDATE modulos SET titulo = ?, imagem_capa_url = ?, release_days = ?, is_paid_module = ?, linked_product_id = ?";
                $sql_titulo_only = "UPDATE modulos SET titulo = ?, release_days = ?, is_paid_module = ?, linked_product_id = ?";
                if ($has_secao_col_edit) {
                    $sql_titulo_imagem .= ", secao_id = ?";
                    $sql_titulo_only .= ", secao_id = ?";
                }
                $sql_titulo_imagem .= " WHERE id = ? AND curso_id = ?";
                $sql_titulo_only .= " WHERE id = ? AND curso_id = ?";

                if ($nova_imagem_path) { // Se uma nova imagem foi enviada
                    error_log("EDITAR_MODULO: Salvando nova imagem: " . $nova_imagem_path);
                    if ($has_secao_col_edit) {
                        $stmt = $pdo->prepare($sql_titulo_imagem);
                        $result = $stmt->execute([$titulo_modulo_edit, $nova_imagem_path, $release_days_edit, $is_paid_module, $linked_product_id, $secao_id_edit_mod, $modulo_id_edit, $curso_id]);
                        error_log("EDITAR_MODULO: UPDATE executado (com secao_id). Resultado: " . ($result ? 'SUCESSO' : 'FALHA') . ", Rows affected: " . $stmt->rowCount());
                    } else {
                        $stmt = $pdo->prepare($sql_titulo_imagem);
                        $result = $stmt->execute([$titulo_modulo_edit, $nova_imagem_path, $release_days_edit, $is_paid_module, $linked_product_id, $modulo_id_edit, $curso_id]);
                        error_log("EDITAR_MODULO: UPDATE executado (sem secao_id). Resultado: " . ($result ? 'SUCESSO' : 'FALHA') . ", Rows affected: " . $stmt->rowCount());
                    }
                    if (empty($mensagem)) {
                        $mensagem = "<div class='px-4 py-3 rounded' role='alert' style='background-color: color-mix(in srgb, var(--accent-primary) 20%, transparent); border: 1px solid var(--accent-primary); color: var(--accent-primary);'>Módulo atualizado!</div>";
                    }
                } else if (!empty($_POST['remove_imagem_capa_modulo'])) {
                    if ($old_module['imagem_capa_url'] && file_exists($old_module['imagem_capa_url']) && strpos($old_module['imagem_capa_url'], 'uploads/') === 0) {
                        unlink($old_module['imagem_capa_url']);
                    }
                    if ($has_secao_col_edit) {
                        $stmt = $pdo->prepare(str_replace('imagem_capa_url = ?,', 'imagem_capa_url = NULL,', $sql_titulo_imagem));
                        $stmt->execute([$titulo_modulo_edit, $release_days_edit, $is_paid_module, $linked_product_id, $secao_id_edit_mod, $modulo_id_edit, $curso_id]);
                    } else {
                        $stmt = $pdo->prepare("UPDATE modulos SET titulo = ?, imagem_capa_url = NULL, release_days = ?, is_paid_module = ?, linked_product_id = ? WHERE id = ? AND curso_id = ?");
                        $stmt->execute([$titulo_modulo_edit, $release_days_edit, $is_paid_module, $linked_product_id, $modulo_id_edit, $curso_id]);
                    }
                    if (empty($mensagem)) {
                        $mensagem = "<div class='px-4 py-3 rounded' role='alert' style='background-color: color-mix(in srgb, var(--accent-primary) 20%, transparent); border: 1px solid var(--accent-primary); color: var(--accent-primary);'>Módulo e imagem de capa atualizados!</div>";
                    }
                } else {
                    if ($has_secao_col_edit) {
                        $stmt = $pdo->prepare($sql_titulo_only);
                        $stmt->execute([$titulo_modulo_edit, $release_days_edit, $is_paid_module, $linked_product_id, $secao_id_edit_mod, $modulo_id_edit, $curso_id]);
                    } else {
                        $stmt = $pdo->prepare("UPDATE modulos SET titulo = ?, release_days = ?, is_paid_module = ?, linked_product_id = ? WHERE id = ? AND curso_id = ?");
                        $stmt->execute([$titulo_modulo_edit, $release_days_edit, $is_paid_module, $linked_product_id, $modulo_id_edit, $curso_id]);
                    }
                    if (empty($mensagem)) {
                        $mensagem = "<div class='px-4 py-3 rounded px-4 py-3 rounded' role='alert'>Módulo atualizado!</div>";
                    }
                }
            } else {
                 $mensagem = "<div class='bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded' role='alert'>Módulo não encontrado para edição.</div>";
            }
        }

        // Adicionar Aula
        if (isset($_POST['adicionar_aula'])) {
            $should_redirect = true;
            $modulo_id = $_POST['modulo_id'];
            $titulo_aula = trim($_POST['titulo_aula']);
            $url_video = trim($_POST['url_video']); // Pode ser vazio
            $descricao_aula = trim($_POST['descricao_aula']);
            $release_days_aula = (int)($_POST['release_days_aula'] ?? 0);
            $tipo_conteudo = $_POST['tipo_conteudo'] ?? 'video'; // 'video', 'files', 'mixed', 'text', 'download_protegido'
            $download_link = trim($_POST['download_link'] ?? '');
            $termos_consentimento = trim($_POST['termos_consentimento'] ?? '');
            $download_protegido = ($tipo_conteudo === 'download_protegido') ? 1 : 0;

            // Verifica se o módulo realmente pertence a este curso
            $stmt_check_modulo = $pdo->prepare("SELECT id FROM modulos WHERE id = ? AND curso_id = ?");
            $stmt_check_modulo->execute([$modulo_id, $curso_id]);
            if ($stmt_check_modulo->rowCount() === 0) {
                 $mensagem = "<div class='bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded' role='alert'>Módulo inválido para este curso.</div>";
            } elseif (empty($titulo_aula)) {
                $mensagem = "<div class='bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded' role='alert'>O título da aula é obrigatório.</div>";
            } else {
                // Validações de conteúdo baseadas no tipo
                // NOTE: 'aula_files' will have name[0] as empty if no file is selected.
                $has_new_files = isset($_FILES['aula_files']) && !empty($_FILES['aula_files']['name'][0]);

                if ($tipo_conteudo === 'video' && empty($url_video)) {
                    $mensagem = "<div class='bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded' role='alert'>Para aulas de vídeo, a URL do vídeo é obrigatória.</div>";
                } elseif ($tipo_conteudo === 'files' && !$has_new_files) {
                    $mensagem = "<div class='bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded' role='alert'>Para aulas de arquivos, pelo menos um arquivo é obrigatório.</div>";
                } elseif ($tipo_conteudo === 'mixed' && empty($url_video) && !$has_new_files) {
                    $mensagem = "<div class='bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded' role='alert'>Para aulas mistas, a URL do vídeo e pelo menos um arquivo são obrigatórios.</div>";
                } elseif ($tipo_conteudo === 'download_protegido' && (empty($download_link) || empty($termos_consentimento))) {
                    $mensagem = "<div class='bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded' role='alert'>Para downloads protegidos, o link de download e os termos de consentimento são obrigatórios.</div>";
                } else {
                    // Tipo 'text' ou outros tipos válidos - prosseguir com a inserção
                    $stmt = $pdo->prepare("INSERT INTO aulas (modulo_id, titulo, url_video, descricao, release_days, tipo_conteudo, download_protegido, download_link, termos_consentimento) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"); 
                    $stmt->execute([$modulo_id, $titulo_aula, $url_video, $descricao_aula, $release_days_aula, $tipo_conteudo, $download_protegido, $download_link, $termos_consentimento]); 
                    $nova_aula_id = $pdo->lastInsertId();

                    // Upload de múltiplos arquivos para a aula (seguro)
                    if ($has_new_files) {
                        // security_helper.php já foi incluído no início
                        
                        // Whitelist de tipos permitidos para arquivos de aula
                        $allowed_aula_types = [
                            'application/pdf',
                            'application/msword',
                            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                            'application/vnd.ms-excel',
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            'application/vnd.ms-powerpoint',
                            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                            'text/plain',
                            'application/zip',
                            'application/x-rar-compressed',
                            'image/jpeg',
                            'image/jpg',
                            'image/png'
                        ];
                        $allowed_aula_extensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'zip', 'rar', 'jpg', 'jpeg', 'png'];
                        
                        foreach ($_FILES['aula_files']['name'] as $key => $name) {
                            if ($_FILES['aula_files']['error'][$key] === UPLOAD_ERR_OK) {
                                $file_array = [
                                    'name' => $_FILES['aula_files']['name'][$key],
                                    'type' => $_FILES['aula_files']['type'][$key],
                                    'tmp_name' => $_FILES['aula_files']['tmp_name'][$key],
                                    'error' => $_FILES['aula_files']['error'][$key],
                                    'size' => $_FILES['aula_files']['size'][$key]
                                ];
                                
                                $upload_result = validate_uploaded_file($file_array, $allowed_aula_types, $allowed_aula_extensions, 50 * 1024 * 1024, $aula_files_dir, 'aula_file');
                                
                                if ($upload_result['success']) {
                                    $stmt_insert_file = $pdo->prepare("INSERT INTO aula_arquivos (aula_id, nome_original, nome_salvo, caminho_arquivo, tipo_mime, tamanho_bytes) VALUES (?, ?, ?, ?, ?, ?)");
                                    $stmt_insert_file->execute([
                                        $nova_aula_id,
                                        $name,
                                        basename($upload_result['file_path']),
                                        $upload_result['file_path'],
                                        $file_array['type'],
                                        $file_array['size']
                                    ]);
                                }
                            }
                        }
                    }
                    $mensagem = "<div class='px-4 py-3 rounded' role='alert' style='background-color: color-mix(in srgb, var(--accent-primary) 20%, transparent); border: 1px solid var(--accent-primary); color: var(--accent-primary);'>Aula adicionada!</div>";
                }
            }
        }
        
        // Editar Aula
        if (isset($_POST['editar_aula_form'])) {
            $should_redirect = true;
            $aula_id_edit = $_POST['aula_id'];
            $titulo_aula = trim($_POST['titulo_aula']);
            $url_video = trim($_POST['url_video']);
            $descricao_aula = trim($_POST['descricao_aula']);
            $release_days_aula = (int)($_POST['release_days_aula'] ?? 0);
            $tipo_conteudo = $_POST['tipo_conteudo'] ?? 'video';
            $download_link = trim($_POST['download_link'] ?? '');
            $termos_consentimento = trim($_POST['termos_consentimento'] ?? '');
            $download_protegido = ($tipo_conteudo === 'download_protegido') ? 1 : 0;

            // Valida se a aula pertence a um módulo deste curso
            $stmt_check_aula = $pdo->prepare("SELECT a.id FROM aulas a JOIN modulos m ON a.modulo_id = m.id WHERE a.id = ? AND m.curso_id = ?");
            $stmt_check_aula->execute([$aula_id_edit, $curso_id]);

            if ($stmt_check_aula->rowCount() === 0) {
                 $mensagem = "<div class='bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded' role='alert'>Aula não encontrada ou não pertence a este curso.</div>";
            } elseif (empty($titulo_aula)) {
                $mensagem = "<div class='bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded' role='alert'>O título da aula é obrigatório.</div>";
            } else {
                // Validações de conteúdo baseadas no tipo
                $has_new_files = isset($_FILES['aula_files']) && !empty($_FILES['aula_files']['name'][0]);
                $has_existing_files_to_keep = !empty($_POST['existing_files']);

                if ($tipo_conteudo === 'video' && empty($url_video)) {
                    $mensagem = "<div class='bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded' role='alert'>Para aulas de vídeo, a URL do vídeo é obrigatória.</div>";
                } elseif ($tipo_conteudo === 'files' && !$has_new_files && !$has_existing_files_to_keep) {
                    // Se o tipo é 'files' e não há arquivos novos e nem existentes marcados, erro.
                    $mensagem = "<div class='bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded' role='alert'>Para aulas de arquivos, pelo menos um arquivo é obrigatório.</div>";
                } elseif ($tipo_conteudo === 'mixed' && empty($url_video) && !$has_new_files && !$has_existing_files_to_keep) {
                    // Se o tipo é 'mixed' e não há vídeo, nem arquivos novos, nem existentes.
                    $mensagem = "<div class='bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded' role='alert'>Para aulas mistas, a URL do vídeo e pelo menos um arquivo são obrigatórios.</div>";
                } elseif ($tipo_conteudo === 'download_protegido' && (empty($download_link) || empty($termos_consentimento))) {
                    $mensagem = "<div class='bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded' role='alert'>Para downloads protegidos, o link de download e os termos de consentimento são obrigatórios.</div>";
                } else {
                    // Tipo 'text' ou outros tipos válidos - prosseguir com a atualização
                    $stmt = $pdo->prepare("UPDATE aulas SET titulo = ?, url_video = ?, descricao = ?, release_days = ?, tipo_conteudo = ?, download_protegido = ?, download_link = ?, termos_consentimento = ? WHERE id = ?");
                    $stmt->execute([$titulo_aula, $url_video, $descricao_aula, $release_days_aula, $tipo_conteudo, $download_protegido, $download_link, $termos_consentimento, $aula_id_edit]);
                    
                    // Se for tipo 'text', remover todos os arquivos existentes (já que não deve ter arquivos)
                    if ($tipo_conteudo === 'text') {
                        $stmt_all_files = $pdo->prepare("SELECT id, caminho_arquivo FROM aula_arquivos WHERE aula_id = ?");
                        $stmt_all_files->execute([$aula_id_edit]);
                        $all_files = $stmt_all_files->fetchAll(PDO::FETCH_ASSOC);
                        
                        foreach ($all_files as $file) {
                            // Deleta o arquivo do sistema de arquivos
                            if (file_exists($file['caminho_arquivo'])) {
                                unlink($file['caminho_arquivo']);
                            }
                            // Deleta o registro do banco de dados
                            $stmt_delete_file = $pdo->prepare("DELETE FROM aula_arquivos WHERE id = ?");
                            $stmt_delete_file->execute([$file['id']]);
                        }
                    } else {

                    // Gerenciar arquivos existentes (deletar)
                    $existing_files_to_keep = $_POST['existing_files'] ?? [];
                    // Busca todos os arquivos da aula
                    $stmt_all_files = $pdo->prepare("SELECT id, caminho_arquivo FROM aula_arquivos WHERE aula_id = ?");
                    $stmt_all_files->execute([$aula_id_edit]);
                    $all_files = $stmt_all_files->fetchAll(PDO::FETCH_ASSOC);

                    foreach ($all_files as $file) {
                        if (!in_array($file['id'], $existing_files_to_keep)) {
                            // Deleta o arquivo do sistema de arquivos
                            if (file_exists($file['caminho_arquivo'])) {
                                unlink($file['caminho_arquivo']);
                            }
                            // Deleta o registro do banco de dados
                            $stmt_delete_file = $pdo->prepare("DELETE FROM aula_arquivos WHERE id = ?");
                            $stmt_delete_file->execute([$file['id']]);
                        }
                    }

                    // Upload de novos arquivos (seguro)
                    if ($has_new_files) {
                        // security_helper.php já foi incluído no início
                        
                        // Whitelist de tipos permitidos para arquivos de aula
                        $allowed_aula_types = [
                            'application/pdf',
                            'application/msword',
                            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                            'application/vnd.ms-excel',
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            'application/vnd.ms-powerpoint',
                            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                            'text/plain',
                            'application/zip',
                            'application/x-rar-compressed'
                        ];
                        $allowed_aula_extensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'zip', 'rar'];
                        
                        foreach ($_FILES['aula_files']['name'] as $key => $name) {
                            if ($_FILES['aula_files']['error'][$key] === UPLOAD_ERR_OK) {
                                $file_array = [
                                    'name' => $_FILES['aula_files']['name'][$key],
                                    'type' => $_FILES['aula_files']['type'][$key],
                                    'tmp_name' => $_FILES['aula_files']['tmp_name'][$key],
                                    'error' => $_FILES['aula_files']['error'][$key],
                                    'size' => $_FILES['aula_files']['size'][$key]
                                ];
                                
                                $upload_result = validate_uploaded_file($file_array, $allowed_aula_types, $allowed_aula_extensions, 50 * 1024 * 1024, $aula_files_dir, 'aula_file');
                                
                                if ($upload_result['success']) {
                                    $stmt_insert_file = $pdo->prepare("INSERT INTO aula_arquivos (aula_id, nome_original, nome_salvo, caminho_arquivo, tipo_mime, tamanho_bytes) VALUES (?, ?, ?, ?, ?, ?)");
                                    $stmt_insert_file->execute([
                                        $aula_id_edit,
                                        $name,
                                        basename($upload_result['file_path']),
                                        $upload_result['file_path'],
                                        $file_array['type'],
                                        $file_array['size']
                                    ]);
                                }
                            }
                        }
                    }
                    }
                    $mensagem = "<div class='px-4 py-3 rounded' role='alert' style='background-color: color-mix(in srgb, var(--accent-primary) 20%, transparent); border: 1px solid var(--accent-primary); color: var(--accent-primary);'>Aula atualizada!</div>";
                }
            }
        }

        // Deletar Módulo
        if (isset($_POST['deletar_modulo'])) {
            $should_redirect = true;
            $modulo_id_del = $_POST['modulo_id'];

            // Primeiro, verifica se o módulo pertence a este curso antes de deletar
            $stmt_check_modulo = $pdo->prepare("SELECT imagem_capa_url FROM modulos WHERE id = ? AND curso_id = ?");
            $stmt_check_modulo->execute([$modulo_id_del, $curso_id]);
            $module_to_delete = $stmt_check_modulo->fetch(PDO::FETCH_ASSOC);

            if ($module_to_delete) {
                // Deleta a imagem de capa se existir
                if ($module_to_delete['imagem_capa_url'] && file_exists($module_to_delete['imagem_capa_url']) && strpos($module_to_delete['imagem_capa_url'], 'uploads/') === 0) {
                    unlink($module_to_delete['imagem_capa_url']);
                }
                
                // Antes de deletar o módulo, precisamos deletar os arquivos das aulas para evitar órfãos
                $stmt_get_aula_files = $pdo->prepare("
                    SELECT af.caminho_arquivo 
                    FROM aula_arquivos af
                    JOIN aulas a ON af.aula_id = a.id
                    WHERE a.modulo_id = ?
                ");
                $stmt_get_aula_files->execute([$modulo_id_del]);
                $files_to_delete = $stmt_get_aula_files->fetchAll(PDO::FETCH_COLUMN);

                foreach ($files_to_delete as $file_path) {
                    if (file_exists($file_path)) {
                        unlink($file_path);
                    }
                }

                // Deleta o módulo (e suas aulas em cascata devido à FOREIGN KEY ON DELETE CASCADE)
                $stmt = $pdo->prepare("DELETE FROM modulos WHERE id = ? AND curso_id = ?");
                $stmt->execute([$modulo_id_del, $curso_id]);
                $mensagem = "<div class='px-4 py-3 rounded' role='alert' style='background-color: color-mix(in srgb, var(--accent-primary) 20%, transparent); border: 1px solid var(--accent-primary); color: var(--accent-primary);'>Módulo e suas aulas foram deletados.</div>";
            } else {
                 $mensagem = "<div class='bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded' role='alert'>Módulo não encontrado ou não pertence a este curso.</div>";
            }
        }
        
        // Deletar Aula
        if (isset($_POST['deletar_aula'])) {
            $should_redirect = true;
            $aula_id_del = $_POST['aula_id'];

            // Verifica se a aula pertence a um módulo deste curso
            $stmt_check_aula = $pdo->prepare("
                SELECT a.id, af.caminho_arquivo 
                FROM aulas a 
                JOIN modulos m ON a.modulo_id = m.id 
                LEFT JOIN aula_arquivos af ON a.id = af.aula_id
                WHERE a.id = ? AND m.curso_id = ?
            ");
            $stmt_check_aula->execute([$aula_id_del, $curso_id]);
            $aula_files_to_delete = $stmt_check_aula->fetchAll(PDO::FETCH_ASSOC);

            if (!empty($aula_files_to_delete)) {
                foreach ($aula_files_to_delete as $file_info) {
                    if ($file_info['caminho_arquivo'] && file_exists($file_info['caminho_arquivo'])) {
                        unlink($file_info['caminho_arquivo']);
                    }
                }
                // Deleta a aula (e seus arquivos em cascata se a FK estiver configurada)
                $stmt = $pdo->prepare("DELETE FROM aulas WHERE id = ?");
                $stmt->execute([$aula_id_del]);
                $mensagem = "<div class='px-4 py-3 rounded' role='alert' style='background-color: color-mix(in srgb, var(--accent-primary) 20%, transparent); border: 1px solid var(--accent-primary); color: var(--accent-primary);'>Aula deletada.</div>";
            } else {
                $mensagem = "<div class='bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded' role='alert'>Aula não encontrada ou não pertence a este curso.</div>";
            }
        }
        
        // CORREÇÃO: Lógica de redirecionamento centralizada
        if ($should_redirect) {
            $_SESSION['flash_message'] = $mensagem;
            // AQUI ESTÁ A CORREÇÃO: Garantir que o redirecionamento inclua a página correta no index
            header("Location: /index?pagina=gerenciar_curso&produto_id=" . $produto_id);
            exit;
        }
    }
    
    // Pega a mensagem da sessão, se houver, e depois limpa
    if (isset($_SESSION['flash_message'])) {
        $mensagem = $_SESSION['flash_message'];
        unset($_SESSION['flash_message']);
    }

    // Recarregar curso para ter allow_comments e community_enabled (se colunas existirem)
    $stmt_curso->execute([$produto_id]);
    $curso = $stmt_curso->fetch(PDO::FETCH_ASSOC);
    if (!isset($curso['allow_comments'])) $curso['allow_comments'] = 0;
    if (!isset($curso['community_enabled'])) $curso['community_enabled'] = 0;

    // Buscar seções do curso (se tabela existir)
    $secoes = [];
    $secoes_produtos = [];
    try {
        $stmt_t = $pdo->query("SHOW TABLES LIKE 'secoes'");
        if ($stmt_t->rowCount() > 0) {
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
            $stmt_secoes->execute([$curso_id]);
            $secoes = $stmt_secoes->fetchAll(PDO::FETCH_ASSOC);
            foreach ($secoes as &$s) {
                // Garantir que tipo_capa existe no array (padrão 'vertical' se não existir)
                if (!isset($s['tipo_capa'])) {
                    $s['tipo_capa'] = 'vertical';
                }
                // Verificar se coluna imagem_capa_url existe
                $has_imagem_col = false;
                try {
                    $chk_col = $pdo->query("SHOW COLUMNS FROM secao_produtos LIKE 'imagem_capa_url'");
                    $has_imagem_col = $chk_col->rowCount() > 0;
                    $chk_col_link = $pdo->query("SHOW COLUMNS FROM secao_produtos LIKE 'link_personalizado'");
                    $has_link_col = $chk_col_link->rowCount() > 0;
                } catch (PDOException $e) {
                    $has_imagem_col = false;
                    $has_link_col = false;
                }
                
                if ($has_imagem_col && $has_link_col) {
                    $st = $pdo->prepare("SELECT sp.produto_id, sp.imagem_capa_url, sp.link_personalizado, p.nome, p.preco, p.foto FROM secao_produtos sp JOIN produtos p ON sp.produto_id = p.id WHERE sp.secao_id = ? ORDER BY sp.ordem ASC, sp.id ASC");
                } elseif ($has_imagem_col) {
                    $st = $pdo->prepare("SELECT sp.produto_id, sp.imagem_capa_url, p.nome, p.preco, p.foto FROM secao_produtos sp JOIN produtos p ON sp.produto_id = p.id WHERE sp.secao_id = ? ORDER BY sp.ordem ASC, sp.id ASC");
                } else {
                    $st = $pdo->prepare("SELECT sp.produto_id, p.nome, p.preco, p.foto FROM secao_produtos sp JOIN produtos p ON sp.produto_id = p.id WHERE sp.secao_id = ? ORDER BY sp.ordem ASC, sp.id ASC");
                }
                $st->execute([$s['id']]);
                $s['produtos'] = $st->fetchAll(PDO::FETCH_ASSOC);
            }
            unset($s);
        }
    } catch (PDOException $e) {
        $secoes = [];
    }

    // 5. Buscar todos os produtos do infoprodutor para módulos pagos (excluindo o produto atual)
    $stmt_produtos = $pdo->prepare("SELECT id, nome, preco FROM produtos WHERE usuario_id = ? AND id != ? ORDER BY nome ASC");
    $stmt_produtos->execute([$usuario_id_logado, $produto_id]);
    $produtos_disponiveis = $stmt_produtos->fetchAll(PDO::FETCH_ASSOC);

    // 6. Buscar todos os módulos e aulas para exibição (incluir secao_id se existir)
    $has_secao_col = false;
    try {
        $chk = $pdo->query("SHOW COLUMNS FROM modulos LIKE 'secao_id'");
        $has_secao_col = $chk->rowCount() > 0;
    } catch (PDOException $e) {}
    $stmt_modulos = $pdo->prepare("SELECT id, curso_id, titulo, imagem_capa_url, ordem, release_days, is_paid_module, linked_product_id" . ($has_secao_col ? ", secao_id" : "") . " FROM modulos WHERE curso_id = ? ORDER BY ordem ASC, id ASC");
    $stmt_modulos->execute([$curso_id]);
    $modulos = $stmt_modulos->fetchAll(PDO::FETCH_ASSOC);

    foreach ($modulos as $modulo) {
        if (!isset($modulo['secao_id'])) $modulo['secao_id'] = null;
        $produto_atrelado = null;
        if ($modulo['is_paid_module'] && $modulo['linked_product_id']) {
            $stmt_prod_atrelado = $pdo->prepare("SELECT id, nome, preco FROM produtos WHERE id = ? AND usuario_id = ?");
            $stmt_prod_atrelado->execute([$modulo['linked_product_id'], $usuario_id_logado]);
            $produto_atrelado = $stmt_prod_atrelado->fetch(PDO::FETCH_ASSOC);
        }
        $modulo['produto_atrelado'] = $produto_atrelado;
        
        $stmt_aulas = $pdo->prepare("SELECT id, modulo_id, titulo, url_video, descricao, ordem, release_days, tipo_conteudo, download_protegido, download_link, termos_consentimento FROM aulas WHERE modulo_id = ? ORDER BY ordem ASC, id ASC");
        $stmt_aulas->execute([$modulo['id']]);
        $aulas = $stmt_aulas->fetchAll(PDO::FETCH_ASSOC);

        foreach ($aulas as &$aula) {
            $stmt_files = $pdo->prepare("SELECT id, nome_original, caminho_arquivo FROM aula_arquivos WHERE aula_id = ? ORDER BY ordem ASC, id ASC");
            $stmt_files->execute([$aula['id']]);
            $aula['files'] = $stmt_files->fetchAll(PDO::FETCH_ASSOC);
        }
        unset($aula);

        $modulos_com_aulas[] = [
            'modulo' => $modulo,
            'aulas' => $aulas
        ];
    }

    // Agrupar módulos por seção para exibição
    $secoes_com_modulos = [];
    $modulos_sem_secao = [];
    if ($has_secao_col && !empty($secoes)) {
        $secao_ids = array_column($secoes, 'id');
        foreach ($secoes as $secao) {
            $secoes_com_modulos[] = [
                'secao' => $secao,
                'modulos_com_aulas' => array_filter($modulos_com_aulas, function($item) use ($secao) {
                    return isset($item['modulo']['secao_id']) && (int)$item['modulo']['secao_id'] === (int)$secao['id'];
                })
            ];
        }
        $modulos_sem_secao = array_filter($modulos_com_aulas, function($item) {
            return empty($item['modulo']['secao_id']);
        });
    } else {
        $secoes_com_modulos = [];
        $modulos_sem_secao = $modulos_com_aulas;
    }

    // Categorias da comunidade (se tabela existir)
    $comunidade_categorias = [];
    try {
        $stmt_t = $pdo->query("SHOW TABLES LIKE 'comunidade_categorias'");
        if ($stmt_t->rowCount() > 0) {
            $stmt_cat = $pdo->prepare("SELECT id, curso_id, nome, is_public_posting, ordem FROM comunidade_categorias WHERE curso_id = ? ORDER BY ordem ASC, id ASC");
            $stmt_cat->execute([$curso_id]);
            $comunidade_categorias = $stmt_cat->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        $comunidade_categorias = [];
    }

    $tem_secoes = (bool)count($secoes ?? []);

} catch (PDOException $e) {
    $mensagem = "<div class='bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded' role='alert'>Erro de banco de dados: " . htmlspecialchars($e->getMessage()) . "</div>";
    $secoes = [];
    $tem_secoes = false;
}

?>

<?php
// Gerar token CSRF para uso nos formulários
$csrf_token = generate_csrf_token();
?>

<div class="container mx-auto max-w-7xl">
    <div class="flex items-center justify-between mb-4 flex-wrap gap-4">
        <a href="/index?pagina=area_membros" style="color: var(--accent-primary);" onmouseover="this.style.color='var(--accent-primary-hover)'" onmouseout="this.style.color='var(--accent-primary)'" class="mr-4">
            <i data-lucide="arrow-left-circle" class="w-7 h-7"></i>
        </a>
        <div class="flex items-center gap-4 flex-1 flex-wrap">
            <h1 class="text-xl font-bold text-white">Editando: <?php echo htmlspecialchars($curso['titulo'] ?? 'Curso'); ?></h1>
            <a href="/index?pagina=gerenciar_comunidade&produto_id=<?php echo $produto_id; ?>" class="text-sm text-black hover:text-black transition px-3 py-1.5 rounded-lg border border-dark-border hover:border-[var(--accent-primary)] flex items-center gap-2 whitespace-nowrap" style="background-color: var(--accent-primary);" onmouseover="this.style.backgroundColor='var(--accent-primary-hover)'" onmouseout="this.style.backgroundColor='var(--accent-primary)'">
                <i data-lucide="users" class="w-4 h-4"></i> Configurações da Comunidade
            </a>
            <?php if (!empty($curso['allow_comments'])): ?>
            <a href="/index?pagina=gerenciar_comentarios&produto_id=<?php echo $produto_id; ?>" class="text-sm text-black hover:text-black transition px-3 py-1.5 rounded-lg border border-dark-border hover:border-[var(--accent-primary)] flex items-center gap-2 whitespace-nowrap" style="background-color: var(--accent-primary);" onmouseover="this.style.backgroundColor='var(--accent-primary-hover)'" onmouseout="this.style.backgroundColor='var(--accent-primary)'">
                <i data-lucide="message-square" class="w-4 h-4"></i> Moderar Comentários
            </a>
            <?php endif; ?>
        </div>
        <a href="/member_course_view?produto_id=<?php echo (int)$produto_id; ?>" target="_blank" class="text-sm text-gray-400 hover:text-white transition whitespace-nowrap">Ver como aluno <i data-lucide="external-link" class="w-4 h-4 inline"></i></a>
    </div>

    <?php if ($mensagem) echo "<div class='mb-6'>$mensagem</div>"; ?>

    <?php $show_edit_banner = true; include __DIR__ . '/partials/curso_banner.php'; ?>

    <!-- Preview da área de membros (estrutura visual como o aluno vê) -->
    <div id="preview-area" class="mb-8">
        <div class="flex justify-between items-center mb-6">
            <h2 class="text-xl font-bold text-white">Conteúdo do curso</h2>
            <button type="button" class="drawer-open-config px-4 py-2 rounded-lg text-white text-sm font-semibold transition" style="background-color: var(--accent-primary);" data-drawer-panel="config">
                <i data-lucide="settings" class="w-4 h-4 inline-block mr-1"></i> Configurações do curso
            </button>
        </div>
        <?php if ($tem_secoes || !empty($modulos_com_aulas)): ?>
            <div id="preview-secoes-sortable" class="space-y-10">
            <?php foreach ($secoes as $secao): ?>
            <div class="secao-preview rounded-xl border border-dark-border overflow-hidden bg-dark-card" data-secao-id="<?php echo (int)$secao['id']; ?>">
                <div class="secao-header flex flex-wrap justify-between items-center gap-2 p-4 bg-dark-elevated border-b border-dark-border">
                    <div class="flex items-center gap-2">
                        <span class="cursor-grab text-gray-500 hover:text-gray-400" title="Arrastar para reordenar"><i data-lucide="grip-vertical" class="w-5 h-5"></i></span>
                        <h3 class="text-xl font-bold text-white"><?php echo htmlspecialchars($secao['titulo']); ?></h3>
                        <span class="text-xs font-medium px-2 py-0.5 rounded" style="background-color: var(--accent-primary); color: white;">
                            <?php echo $secao['tipo_secao'] === 'curso' ? 'Conteúdo do curso' : 'Outros produtos'; ?>
                        </span>
                    </div>
                    <div class="flex items-center gap-2">
                        <button type="button" class="edit-secao-btn px-3 py-1.5 rounded-lg text-blue-400 hover:bg-blue-400/20 text-sm" data-secao-id="<?php echo (int)$secao['id']; ?>" data-titulo="<?php echo htmlspecialchars($secao['titulo']); ?>" data-tipo="<?php echo htmlspecialchars($secao['tipo_secao']); ?>" data-tipo-capa="<?php echo htmlspecialchars($secao['tipo_capa'] ?? 'vertical'); ?>"><i data-lucide="edit" class="w-4 h-4 inline mr-1"></i> Editar</button>
                        <form action="/index?pagina=gerenciar_curso&produto_id=<?php echo $produto_id; ?>" method="post" onsubmit="return confirm('Remover esta seção?');" class="inline">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                            <input type="hidden" name="secao_id" value="<?php echo (int)$secao['id']; ?>">
                            <button type="submit" name="deletar_secao" class="px-3 py-1.5 rounded-lg text-red-400 hover:bg-red-400/20 text-sm"><i data-lucide="trash-2" class="w-4 h-4 inline"></i></button>
                        </form>
                        <?php if ($secao['tipo_secao'] === 'curso'): ?>
                        <button type="button" class="drawer-open-add-module px-3 py-1.5 rounded-lg text-white text-sm font-semibold" style="background-color: var(--accent-primary);" data-secao-id="<?php echo (int)$secao['id']; ?>"><i data-lucide="plus" class="w-4 h-4 inline mr-1"></i> Adicionar módulo</button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="secao-content p-4">
                    <?php if ($secao['tipo_secao'] === 'curso'): ?>
                        <?php
                        $modulos_desta_secao = array_filter($modulos_com_aulas, function($m) use ($secao) { return isset($m['modulo']['secao_id']) && (int)$m['modulo']['secao_id'] === (int)$secao['id']; });
                        ?>
                        <?php if (!empty($modulos_desta_secao)): ?>
                        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-6 sortable-modulos" data-secao-id="<?php echo (int)$secao['id']; ?>">
                            <?php 
                            $tipo_capa = $secao['tipo_capa'] ?? 'vertical';
                            foreach ($modulos_desta_secao as $item): ?>
                                <?php include __DIR__ . '/partials/curso_module_card_preview.php'; ?>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <p class="text-gray-500 text-sm py-4">Nenhum módulo nesta seção. Clique em &quot;Adicionar módulo&quot; acima.</p>
                        <?php endif; ?>
                    <?php elseif ($secao['tipo_secao'] === 'outros_produtos'): ?>
                        <?php if (!empty($secao['produtos'])): ?>
                        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-6">
                            <?php 
                            $tipo_capa = $secao['tipo_capa'] ?? 'vertical';
                            foreach ($secao['produtos'] ?? [] as $sp): 
                                $secao_id = (int)$secao['id']; // Garantir que $secao_id está disponível no partial
                            ?>
                                <?php include __DIR__ . '/partials/curso_produto_card_preview.php'; ?>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                        <p class="text-gray-500 text-sm py-4">Nenhum produto nesta seção. Adicione um produto abaixo.</p>
                        <?php endif; ?>
                        <div class="mt-6">
                            <button type="button" class="drawer-open-add-produto-secao text-white text-sm font-semibold py-2.5 px-6 rounded-lg transition-all duration-300 flex items-center gap-2" style="background-color: var(--accent-primary);" onmouseover="this.style.backgroundColor='var(--accent-primary-hover)'" onmouseout="this.style.backgroundColor='var(--accent-primary)'" data-secao-id="<?php echo (int)$secao['id']; ?>">
                                <i data-lucide="plus" class="w-4 h-4"></i> Adicionar Produto
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
            <?php if (!empty($modulos_sem_secao)): ?>
            <div class="secao-preview mb-10 rounded-xl border border-dark-border overflow-hidden bg-dark-card">
                <div class="secao-header flex justify-between items-center p-4 bg-dark-elevated border-b border-dark-border">
                    <h3 class="text-xl font-bold text-white">Sem seção</h3>
                </div>
                <div class="secao-content p-4">
                    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5 gap-6">
                        <?php foreach ($modulos_sem_secao as $item): ?>
                            <?php include __DIR__ . '/partials/curso_module_card_preview.php'; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            <div class="mt-6">
                <button type="button" class="drawer-open-add-secao px-4 py-2 rounded-lg border-2 border-dashed border-gray-600 text-gray-400 hover:text-white hover:border-gray-500 transition text-sm font-medium">
                    <i data-lucide="plus" class="w-4 h-4 inline mr-1"></i> Adicionar seção
                </button>
            </div>
        <?php else: ?>
            <div class="rounded-xl border border-dashed border-gray-600 p-8 text-center text-gray-500">
                <p class="mb-4">Nenhum conteúdo ainda. Use &quot;Adicionar seção&quot; ou os formulários abaixo para começar.</p>
                <button type="button" class="drawer-open-add-secao px-4 py-2 rounded-lg text-white text-sm font-semibold" style="background-color: var(--accent-primary);"><i data-lucide="plus" class="w-4 h-4 inline mr-1"></i> Adicionar seção</button>
            </div>
        <?php endif; ?>
    </div>

    <!-- Drawer lateral: configurações, seção, módulo, aula -->
    <div id="gerenciar-drawer-backdrop" class="fixed inset-0 bg-black/60 z-40 hidden transition-opacity" aria-hidden="true"></div>
    <aside id="gerenciar-drawer" class="fixed top-0 right-0 h-full w-full max-w-md bg-dark-card border-l border-dark-border shadow-2xl z-50 transform translate-x-full transition-transform duration-300 flex flex-col" role="dialog" aria-label="Painel de edição">
        <div class="flex-shrink-0 flex justify-between items-center p-4 border-b border-dark-border">
            <h2 id="drawer-title" class="text-lg font-bold text-white">Configurações</h2>
            <button type="button" id="drawer-close" class="p-2 rounded-lg text-gray-400 hover:text-white hover:bg-dark-elevated transition" aria-label="Fechar">
                <i data-lucide="x" class="w-5 h-5"></i>
            </button>
        </div>
        <div class="flex-1 overflow-y-auto p-4">
            <!-- Painel: Alterar banner (desktop, mobile, logo) -->
            <div id="drawer-panel-banner" class="drawer-panel hidden">
                <form action="/index?pagina=gerenciar_curso&produto_id=<?php echo $produto_id; ?>" method="post" enctype="multipart/form-data" class="space-y-6">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

                    <!-- Imagem de fundo desktop -->
                    <section>
                        <h3 class="text-sm font-semibold text-gray-400 uppercase tracking-wider mb-2">Imagem de fundo desktop</h3>
                        <p class="text-xs text-gray-500 mb-2">2560×1280px / Máximo 15MB</p>
                        <?php
                        $banner_desktop = $curso['banner_desktop_url'] ?? $curso['banner_url'] ?? null;
                        if ($banner_desktop && file_exists($banner_desktop)):
                        ?>
                        <div class="mb-2">
                            <img src="<?php echo htmlspecialchars($banner_desktop); ?>" alt="Desktop" class="w-full h-28 object-cover rounded-lg border border-dark-border">
                            <label class="mt-2 flex items-center gap-2 text-sm text-gray-400 cursor-pointer">
                                <input type="checkbox" name="remove_banner_desktop" value="1" class="w-4 h-4 rounded border-dark-border bg-dark-elevated text-red-400 focus:ring-red-500 focus:ring-offset-0 focus:ring-2"> Remover
                            </label>
                        </div>
                        <?php endif; ?>
                        <input type="file" name="banner_desktop" accept="image/*" class="w-full text-sm text-gray-400 file:mr-2 file:py-2 file:px-3 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:cursor-pointer file:bg-dark-elevated file:text-white">
                    </section>

                    <!-- Imagem de fundo mobile -->
                    <section>
                        <h3 class="text-sm font-semibold text-gray-400 uppercase tracking-wider mb-2">Imagem de fundo mobile</h3>
                        <p class="text-xs text-gray-500 mb-2">1630×1920px / Máximo 15MB</p>
                        <?php
                        $banner_mobile = $curso['banner_mobile_url'] ?? null;
                        if ($banner_mobile && file_exists($banner_mobile)):
                        ?>
                        <div class="mb-2">
                            <img src="<?php echo htmlspecialchars($banner_mobile); ?>" alt="Mobile" class="w-full h-32 object-cover rounded-lg border border-dark-border">
                            <label class="mt-2 flex items-center gap-2 text-sm text-gray-400 cursor-pointer">
                                <input type="checkbox" name="remove_banner_mobile" value="1" class="w-4 h-4 rounded border-dark-border bg-dark-elevated text-red-400 focus:ring-red-500 focus:ring-offset-0 focus:ring-2"> Remover
                            </label>
                        </div>
                        <?php endif; ?>
                        <input type="file" name="banner_mobile" accept="image/*" class="w-full text-sm text-gray-400 file:mr-2 file:py-2 file:px-3 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:cursor-pointer file:bg-dark-elevated file:text-white">
                    </section>

                    <!-- Logo no canto esquerdo (header) -->
                    <section>
                        <h3 class="text-sm font-semibold text-gray-400 uppercase tracking-wider mb-2">Logo no header</h3>
                        <p class="text-xs text-gray-500 mb-2">Exibido no canto esquerdo do hero. Máximo 15MB</p>
                        <?php
                        $banner_logo = $curso['banner_logo_url'] ?? null;
                        if ($banner_logo && file_exists($banner_logo)):
                        ?>
                        <div class="mb-2">
                            <img src="<?php echo htmlspecialchars($banner_logo); ?>" alt="Logo" class="max-h-20 w-auto object-contain rounded border border-dark-border bg-dark-elevated p-1">
                            <label class="mt-2 flex items-center gap-2 text-sm text-gray-400 cursor-pointer">
                                <input type="checkbox" name="remove_banner_logo" value="1" class="w-4 h-4 rounded border-dark-border bg-dark-elevated text-red-400 focus:ring-red-500 focus:ring-offset-0 focus:ring-2"> Remover logo
                            </label>
                        </div>
                        <?php endif; ?>
                        <input type="file" name="banner_logo" accept="image/*" class="w-full text-sm text-gray-400 file:mr-2 file:py-2 file:px-3 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:cursor-pointer file:bg-dark-elevated file:text-white">
                    </section>

                    <!-- Legado: uma única imagem (fallback) -->
                    <?php if (!empty($curso['banner_url']) && file_exists($curso['banner_url']) && empty($curso['banner_desktop_url'] ?? null)): ?>
                    <section class="pt-4 border-t border-dark-border">
                        <p class="text-xs text-gray-500 mb-2">Banner atual (único)</p>
                        <div class="mb-2">
                            <img src="<?php echo htmlspecialchars($curso['banner_url']); ?>" alt="Banner" class="w-full h-24 object-cover rounded-lg border border-dark-border">
                            <label class="mt-2 flex items-center gap-2 text-sm text-gray-400 cursor-pointer">
                                <input type="checkbox" name="remove_banner" value="1" class="w-4 h-4 rounded border-dark-border bg-dark-elevated text-red-400 focus:ring-red-500 focus:ring-offset-0 focus:ring-2"> Remover
                            </label>
                        </div>
                        <input type="file" name="banner_curso" accept="image/*" class="w-full text-sm text-gray-400 file:mr-2 file:py-2 file:px-3 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:cursor-pointer file:bg-dark-elevated file:text-white">
                    </section>
                    <?php endif; ?>

                    <button type="submit" name="salvar_banner_curso" class="w-full text-white text-sm font-semibold py-2.5 px-4 rounded-lg transition" style="background-color: var(--accent-primary);">Salvar imagens</button>
                </form>
            </div>

            <!-- Painel: Configurações do curso (somente comentários, comunidade, categorias) -->
            <div id="drawer-panel-config" class="drawer-panel hidden space-y-6">
                <section>
                    <h3 class="text-sm font-semibold text-gray-400 uppercase tracking-wider mb-3">Configurações</h3>
                    <form action="/index?pagina=gerenciar_curso&produto_id=<?php echo $produto_id; ?>" method="post" class="space-y-3">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <div class="space-y-2">
                            <label class="flex items-center gap-3 p-3 rounded-lg border border-dark-border bg-dark-elevated/50 hover:border-gray-600 transition cursor-pointer">
                                <input type="checkbox" name="allow_comments" value="1" class="w-5 h-5 rounded border-dark-border bg-dark-card text-[var(--accent-primary)] focus:ring-[var(--accent-primary)] focus:ring-offset-0 focus:ring-2" <?php echo !empty($curso['allow_comments']) ? 'checked' : ''; ?>>
                                <span class="text-gray-200 text-sm font-medium">Comentários nas aulas</span>
                                <span class="ml-auto text-gray-500 text-xs">Alunos podem comentar</span>
                            </label>
                        </div>
                        <button type="submit" name="salvar_config_curso" class="w-full text-white text-sm font-semibold py-2.5 px-4 rounded-lg transition mt-4" style="background-color: var(--accent-primary);">Salvar configurações</button>
                    </form>
                </section>
            </div>

            <!-- Painel: Nova / Editar Seção -->
            <div id="drawer-panel-secao" class="drawer-panel hidden">
                <form action="/index?pagina=gerenciar_curso&produto_id=<?php echo $produto_id; ?>" method="post" id="drawer-form-secao">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="secao_id" id="drawer-secao-id" value="">
                    <div class="space-y-4">
                        <div>
                            <label for="drawer-titulo-secao" class="block text-gray-300 text-sm font-semibold mb-1">Título da seção</label>
                            <input type="text" id="drawer-titulo-secao" name="titulo_secao" required placeholder="Ex: Módulos do curso" class="form-input-style w-full px-4 py-2 text-sm">
                        </div>
                        <div>
                            <label for="drawer-tipo-secao" class="block text-gray-300 text-sm font-semibold mb-1">Tipo</label>
                            <select id="drawer-tipo-secao" name="tipo_secao" class="form-input-style w-full px-4 py-2 text-sm">
                                <option value="curso">Conteúdo do curso</option>
                                <option value="outros_produtos">Outros produtos</option>
                            </select>
                        </div>
                        <div>
                            <label for="drawer-tipo-capa" class="block text-gray-300 text-sm font-semibold mb-1">Tipo de Capa</label>
                            <select id="drawer-tipo-capa" name="tipo_capa" class="form-input-style w-full px-4 py-2 text-sm">
                                <option value="vertical">Capa Vertical (padrão)</option>
                                <option value="horizontal">Capa Horizontal (842x327)</option>
                            </select>
                        </div>
                        <div class="flex gap-2">
                            <button type="submit" name="adicionar_secao" id="drawer-secao-btn-add" class="text-white text-sm font-semibold py-2 px-4 rounded-lg" style="background-color: var(--accent-primary);">Adicionar seção</button>
                            <button type="submit" name="editar_secao" id="drawer-secao-btn-edit" class="hidden text-white text-sm font-semibold py-2 px-4 rounded-lg" style="background-color: var(--accent-primary);">Salvar</button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Painel: Novo / Editar Módulo -->
            <div id="drawer-panel-modulo" class="drawer-panel hidden">
                <div id="drawer-modulo-add-wrap">
                    <h3 class="text-sm font-semibold text-gray-400 uppercase tracking-wider mb-3">Novo módulo</h3>
                    <form action="/index?pagina=gerenciar_curso&produto_id=<?php echo $produto_id; ?>" method="post" enctype="multipart/form-data" class="space-y-4">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <?php if ($tem_secoes): ?>
                        <div>
                            <label class="block text-gray-300 text-sm font-semibold mb-1">Seção</label>
                            <select name="secao_id" class="form-input-style w-full px-4 py-2 text-sm" id="drawer-modulo-secao-select">
                                <option value="">Sem seção</option>
                                <?php foreach ($secoes as $s): if ($s['tipo_secao'] === 'curso'): ?>
                                <option value="<?php echo (int)$s['id']; ?>"><?php echo htmlspecialchars($s['titulo']); ?></option>
                                <?php endif; endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        <div>
                            <label class="block text-gray-300 text-sm font-semibold mb-1">Título do módulo</label>
                            <input type="text" name="titulo_modulo" required placeholder="Ex: Módulo 1" class="form-input-style w-full px-4 py-2 text-sm">
                        </div>
                        <div>
                            <label class="block text-gray-300 text-sm font-semibold mb-1">Liberar após (dias)</label>
                            <input type="number" name="release_days_modulo" value="0" min="0" class="form-input-style w-full px-4 py-2 text-sm">
                        </div>
                        <div>
                            <label class="block text-gray-300 text-sm font-semibold mb-1">Capa do módulo</label>
                            <input type="file" name="imagem_capa_modulo" accept="image/*" class="w-full text-sm text-gray-400 file:mr-2 file:py-2 file:px-3 file:rounded-lg file:border-0 file:text-sm">
                        </div>
                        <button type="submit" name="adicionar_modulo" class="text-white text-sm font-semibold py-2 px-4 rounded-lg" style="background-color: var(--accent-primary);">Adicionar módulo</button>
                    </form>
                </div>
                <div id="drawer-modulo-edit-wrap" class="hidden mt-6 pt-6 border-t border-dark-border">
                    <h3 class="text-sm font-semibold text-gray-400 uppercase tracking-wider mb-3">Editar módulo</h3>
                    <form action="/index?pagina=gerenciar_curso&produto_id=<?php echo $produto_id; ?>" method="post" enctype="multipart/form-data" id="drawer-form-edit-modulo" class="space-y-4">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <input type="hidden" name="modulo_id" id="drawer-modulo-id-edit" value="">
                        <?php if ($tem_secoes): ?>
                        <div>
                            <label class="block text-gray-300 text-sm font-semibold mb-1">Seção</label>
                            <select name="secao_id" id="drawer-edit-modulo-secao" class="form-input-style w-full px-4 py-2 text-sm">
                                <option value="">Sem seção</option>
                                <?php foreach ($secoes as $s): if ($s['tipo_secao'] === 'curso'): ?>
                                <option value="<?php echo (int)$s['id']; ?>"><?php echo htmlspecialchars($s['titulo']); ?></option>
                                <?php endif; endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                        <div>
                            <label class="block text-gray-300 text-sm font-semibold mb-1">Título</label>
                            <input type="text" name="titulo_modulo" id="drawer-edit-modulo-titulo" required class="form-input-style w-full px-4 py-2 text-sm">
                        </div>
                        <div>
                            <label class="block text-gray-300 text-sm font-semibold mb-1">Liberar após (dias)</label>
                            <input type="number" name="release_days_modulo" id="drawer-edit-modulo-release" value="0" min="0" class="form-input-style w-full px-4 py-2 text-sm">
                        </div>
                        <div>
                            <label class="block text-gray-300 text-sm font-semibold mb-1">Capa</label>
                            <input type="file" name="imagem_capa_modulo" accept="image/*" class="w-full text-sm text-gray-400 file:mr-2 file:py-2 file:rounded-lg">
                            <label class="mt-1 flex items-center gap-2 text-sm text-gray-400"><input type="checkbox" name="remove_imagem_capa_modulo" value="1"> Remover capa</label>
                        </div>
                        <button type="submit" name="editar_modulo" class="text-white text-sm font-semibold py-2 px-4 rounded-lg" style="background-color: var(--accent-primary);">Salvar</button>
                    </form>
                </div>
            </div>

            <!-- Painel: Adicionar Produto à Seção -->
            <div id="drawer-panel-add-produto-secao" class="drawer-panel hidden">
                <h3 class="text-sm font-semibold text-gray-400 uppercase tracking-wider mb-3">Adicionar produto à seção</h3>
                <form action="/index?pagina=gerenciar_curso&produto_id=<?php echo $produto_id; ?>" method="post" enctype="multipart/form-data" id="drawer-form-add-produto-secao" class="space-y-4">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="secao_id" id="drawer-add-produto-secao-id" value="">
                    <div>
                        <label class="block text-gray-300 text-sm font-semibold mb-1">Produto</label>
                        <select name="produto_id" id="drawer-add-produto-id" required class="form-input-style w-full px-4 py-2 text-sm">
                            <option value="">Selecione um produto...</option>
                            <?php foreach ($produtos_disponiveis as $p): ?>
                            <option value="<?php echo (int)$p['id']; ?>"><?php echo htmlspecialchars($p['nome']); ?> - R$ <?php echo number_format($p['preco'], 2, ',', '.'); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="block text-gray-300 text-sm font-semibold mb-1">Tipo de Link</label>
                        <div class="space-y-2">
                            <label class="flex items-center gap-2 text-sm text-gray-300 cursor-pointer">
                                <input type="radio" name="tipo_link_produto" value="padrao" id="drawer-add-link-padrao" checked class="w-4 h-4 rounded border-dark-border bg-dark-card text-accent-primary focus:ring-accent-primary focus:ring-offset-0 focus:ring-2">
                                <span>Usar link padrão do checkout</span>
                            </label>
                            <label class="flex items-center gap-2 text-sm text-gray-300 cursor-pointer">
                                <input type="radio" name="tipo_link_produto" value="personalizado" id="drawer-add-link-personalizado" class="w-4 h-4 rounded border-dark-border bg-dark-card text-accent-primary focus:ring-accent-primary focus:ring-offset-0 focus:ring-2">
                                <span>Link personalizado</span>
                            </label>
                        </div>
                    </div>
                    <div id="drawer-add-link-personalizado-wrap" class="hidden">
                        <label class="block text-gray-300 text-sm font-semibold mb-1">Link Personalizado (URL)</label>
                        <input type="url" name="link_personalizado" id="drawer-add-link-personalizado-input" placeholder="https://exemplo.com/pagina-vendas" class="form-input-style w-full px-4 py-2 text-sm">
                        <p class="text-xs text-gray-400 mt-1">Digite a URL completa da página de vendas</p>
                    </div>
                    <div>
                        <label class="block text-gray-300 text-sm font-semibold mb-1">Imagem de Capa (opcional)</label>
                        <input type="file" name="imagem_capa_produto" accept="image/jpeg,image/png" class="w-full text-sm text-gray-400 file:mr-2 file:py-2 file:px-3 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:cursor-pointer file:bg-dark-elevated file:text-white">
                        <p class="text-xs text-gray-400 mt-1">JPEG ou PNG, máximo 5MB</p>
                    </div>
                    <button type="submit" name="secao_adicionar_produto" class="w-full text-white text-sm font-semibold py-2.5 px-4 rounded-lg transition" style="background-color: var(--accent-primary);">Adicionar Produto</button>
                </form>
            </div>

            <!-- Painel: Editar Produto da Seção -->
            <div id="drawer-panel-produto-secao" class="drawer-panel hidden">
                <h3 class="text-sm font-semibold text-gray-400 uppercase tracking-wider mb-3">Editar produto da seção</h3>
                <form action="/index?pagina=gerenciar_curso&produto_id=<?php echo $produto_id; ?>" method="post" enctype="multipart/form-data" id="drawer-form-edit-produto-secao" class="space-y-4">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="secao_id" id="drawer-produto-secao-id" value="">
                    <input type="hidden" name="produto_id" id="drawer-produto-id" value="">
                    <div>
                        <label class="block text-gray-300 text-sm font-semibold mb-1">Produto</label>
                        <input type="text" id="drawer-produto-nome" readonly class="form-input-style w-full px-4 py-2 text-sm bg-dark-elevated text-gray-400">
                    </div>
                    <div>
                        <label class="block text-gray-300 text-sm font-semibold mb-1">Tipo de Link</label>
                        <div class="space-y-2">
                            <label class="flex items-center gap-2 text-sm text-gray-300 cursor-pointer">
                                <input type="radio" name="tipo_link_produto_edit" value="padrao" id="drawer-edit-link-padrao" class="w-4 h-4 rounded border-dark-border bg-dark-card text-accent-primary focus:ring-accent-primary focus:ring-offset-0 focus:ring-2">
                                <span>Usar link padrão do checkout</span>
                            </label>
                            <label class="flex items-center gap-2 text-sm text-gray-300 cursor-pointer">
                                <input type="radio" name="tipo_link_produto_edit" value="personalizado" id="drawer-edit-link-personalizado" class="w-4 h-4 rounded border-dark-border bg-dark-card text-accent-primary focus:ring-accent-primary focus:ring-offset-0 focus:ring-2">
                                <span>Link personalizado</span>
                            </label>
                        </div>
                    </div>
                    <div id="drawer-edit-link-personalizado-wrap" class="hidden">
                        <label class="block text-gray-300 text-sm font-semibold mb-1">Link Personalizado (URL)</label>
                        <input type="url" name="link_personalizado" id="drawer-edit-link-personalizado-input" placeholder="https://exemplo.com/pagina-vendas" class="form-input-style w-full px-4 py-2 text-sm">
                        <p class="text-xs text-gray-400 mt-1">Digite a URL completa da página de vendas</p>
                    </div>
                    <div>
                        <label class="block text-gray-300 text-sm font-semibold mb-1">Imagem de Capa</label>
                        <div id="drawer-produto-imagem-preview" class="mb-2"></div>
                        <input type="file" name="imagem_capa_produto" accept="image/jpeg,image/png" class="w-full text-sm text-gray-400 file:mr-2 file:py-2 file:px-3 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:cursor-pointer file:bg-dark-elevated file:text-white">
                        <p class="text-xs text-gray-400 mt-1">JPEG ou PNG, máximo 5MB</p>
                        <label class="mt-2 flex items-center gap-2 text-sm text-gray-400 cursor-pointer">
                            <input type="checkbox" name="remove_imagem_capa_produto" value="1" class="w-4 h-4 rounded border-dark-border bg-dark-card text-red-400 focus:ring-red-500 focus:ring-offset-0 focus:ring-2"> Remover imagem de capa
                        </label>
                    </div>
                    <button type="submit" name="secao_editar_produto" class="w-full text-white text-sm font-semibold py-2.5 px-4 rounded-lg transition" style="background-color: var(--accent-primary);">Salvar alterações</button>
                </form>
            </div>

            <!-- Painel: Nova / Editar Aula -->
            <div id="drawer-panel-aula" class="drawer-panel hidden space-y-6">
                <div id="drawer-aula-add-wrap">
                    <h3 class="text-sm font-semibold text-gray-400 uppercase tracking-wider mb-3">Nova aula em <span id="drawer-aula-modulo-titulo" class="text-white"></span></h3>
                    <form action="/index?pagina=gerenciar_curso&produto_id=<?php echo $produto_id; ?>" method="post" enctype="multipart/form-data" class="space-y-4" id="drawer-form-add-aula">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <input type="hidden" name="modulo_id" id="drawer-add-aula-modulo-id" value="">
                        <div><label class="block text-gray-300 text-sm font-semibold mb-1">Título da aula</label><input type="text" name="titulo_aula" required placeholder="Ex: Aula 1" class="form-input-style w-full px-4 py-2 text-sm"></div>
                        <div>
                            <label class="block text-gray-300 text-sm font-semibold mb-1">Tipo de conteúdo</label>
                            <select name="tipo_conteudo" id="drawer-add-tipo-conteudo" class="form-input-style w-full px-4 py-2 text-sm">
                                <option value="video">Vídeo</option>
                                <option value="files">Arquivos</option>
                                <option value="mixed">Vídeo e arquivos</option>
                                <option value="text">Apenas texto</option>
                                <option value="download_protegido">Download protegido</option>
                            </select>
                        </div>
                        <div id="drawer-add-video-wrap"><label class="block text-gray-300 text-sm font-semibold mb-1">URL do vídeo</label><input type="url" name="url_video" placeholder="https://youtube.com/..." class="form-input-style w-full px-4 py-2 text-sm"></div>
                        <div id="drawer-add-files-wrap" class="hidden"><label class="block text-gray-300 text-sm font-semibold mb-1">Arquivos</label><input type="file" name="aula_files[]" multiple class="w-full text-sm text-gray-400 file:mr-2 file:py-2 file:rounded-lg"></div>
                        <div id="drawer-add-download-wrap" class="hidden">
                            <label class="block text-gray-300 text-sm font-semibold mb-1">Link do download</label><input type="url" name="download_link" class="form-input-style w-full px-4 py-2 text-sm">
                            <label class="block text-gray-300 text-sm font-semibold mb-1 mt-2">Termos de consentimento</label><textarea name="termos_consentimento" rows="4" class="form-input-style w-full px-4 py-2 text-sm"></textarea>
                        </div>
                        <div><label class="block text-gray-300 text-sm font-semibold mb-1">Liberar após (dias)</label><input type="number" name="release_days_aula" value="0" min="0" class="form-input-style w-full px-4 py-2 text-sm"></div>
                        <div><label class="block text-gray-300 text-sm font-semibold mb-1">Descrição</label><textarea name="descricao_aula" rows="3" class="form-input-style w-full px-4 py-2 text-sm"></textarea></div>
                        <button type="submit" name="adicionar_aula" class="text-white text-sm font-semibold py-2 px-4 rounded-lg" style="background-color: var(--accent-primary);">Adicionar aula</button>
                    </form>
                </div>
                <div id="drawer-aula-edit-wrap" class="hidden">
                    <h3 class="text-sm font-semibold text-gray-400 uppercase tracking-wider mb-3">Editar aula</h3>
                    <form action="/index?pagina=gerenciar_curso&produto_id=<?php echo $produto_id; ?>" method="post" enctype="multipart/form-data" class="space-y-4" id="drawer-form-edit-aula">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <input type="hidden" name="aula_id" id="drawer-edit-aula-id" value="">
                        <div><label class="block text-gray-300 text-sm font-semibold mb-1">Título</label><input type="text" name="titulo_aula" id="drawer-edit-aula-titulo" required class="form-input-style w-full px-4 py-2 text-sm"></div>
                        <div>
                            <label class="block text-gray-300 text-sm font-semibold mb-1">Tipo de conteúdo</label>
                            <select name="tipo_conteudo" id="drawer-edit-tipo-conteudo" class="form-input-style w-full px-4 py-2 text-sm">
                                <option value="video">Vídeo</option>
                                <option value="files">Arquivos</option>
                                <option value="mixed">Vídeo e arquivos</option>
                                <option value="text">Apenas texto</option>
                                <option value="download_protegido">Download protegido</option>
                            </select>
                        </div>
                        <div id="drawer-edit-video-wrap"><label class="block text-gray-300 text-sm font-semibold mb-1">URL do vídeo</label><input type="url" name="url_video" id="drawer-edit-url-video" class="form-input-style w-full px-4 py-2 text-sm"></div>
                        <div id="drawer-edit-files-wrap" class="hidden"><label class="block text-gray-300 text-sm font-semibold mb-1">Novos arquivos</label><input type="file" name="aula_files[]" multiple class="w-full text-sm text-gray-400 file:mr-2 file:py-2 file:rounded-lg"></div>
                        <div id="drawer-edit-download-wrap" class="hidden">
                            <label class="block text-gray-300 text-sm font-semibold mb-1">Link do download</label><input type="url" name="download_link" id="drawer-edit-download-link" class="form-input-style w-full px-4 py-2 text-sm">
                            <label class="block text-gray-300 text-sm font-semibold mb-1 mt-2">Termos</label><textarea name="termos_consentimento" id="drawer-edit-termos" rows="4" class="form-input-style w-full px-4 py-2 text-sm"></textarea>
                        </div>
                        <div><label class="block text-gray-300 text-sm font-semibold mb-1">Liberar após (dias)</label><input type="number" name="release_days_aula" id="drawer-edit-release" value="0" min="0" class="form-input-style w-full px-4 py-2 text-sm"></div>
                        <div><label class="block text-gray-300 text-sm font-semibold mb-1">Descrição</label><textarea name="descricao_aula" id="drawer-edit-descricao" rows="3" class="form-input-style w-full px-4 py-2 text-sm"></textarea></div>
                        <button type="submit" name="editar_aula_form" class="text-white text-sm font-semibold py-2 px-4 rounded-lg" style="background-color: var(--accent-primary);">Salvar</button>
                    </form>
                </div>
            </div>

            <!-- Painel: Lista de Aulas do Módulo -->
            <div id="drawer-panel-aulas-list" class="drawer-panel hidden">
                <h3 class="text-sm font-semibold text-gray-400 uppercase tracking-wider mb-3">Aulas: <span id="drawer-aulas-list-modulo-titulo" class="text-white font-normal"></span></h3>
                <p class="text-gray-500 text-xs mb-2">Arraste para reordenar.</p>
                <div id="drawer-aulas-list-content" class="space-y-2 mb-4 max-h-64 overflow-y-auto">
                    <!-- Lista preenchida via JS -->
                </div>
                <button type="button" id="drawer-aulas-list-btn-nova" class="w-full py-2.5 px-4 rounded-lg text-white text-sm font-semibold transition" style="background-color: var(--accent-primary);">
                    <i data-lucide="plus-circle" class="w-4 h-4 inline mr-1"></i> Nova aula
                </button>
            </div>
        </div>
    </aside>

    <!-- Modal Editar Categoria Comunidade (usado dentro do drawer ou sozinho) -->
    <div id="edit-categoria-comunidade-modal" class="fixed inset-0 bg-black bg-opacity-60 z-[60] flex items-center justify-center p-4 hidden">
        <div class="bg-dark-card rounded-xl shadow-2xl w-full max-w-md transform transition-all opacity-0 scale-95" style="border-color: var(--accent-primary);">
            <form action="/index?pagina=gerenciar_curso&produto_id=<?php echo $produto_id; ?>" method="post">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="categoria_id" id="modal-cat-comunidade-id" value="">
                <div class="p-6 border-b border-dark-border"><h2 class="text-xl font-bold text-white">Editar Categoria</h2></div>
                <div class="p-6 space-y-4">
                    <div>
                        <label for="modal-cat-comunidade-nome" class="block text-gray-300 text-sm font-semibold mb-2">Nome</label>
                        <input type="text" id="modal-cat-comunidade-nome" name="categoria_nome" required class="form-input-style w-full px-4 py-3 bg-dark-elevated border border-dark-border rounded-lg text-white">
                    </div>
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" name="categoria_public_posting" value="1" id="modal-cat-comunidade-public" class="form-checkbox">
                        <span class="text-gray-300 text-sm">Postagem pública (alunos podem postar)</span>
                    </label>
                </div>
                <div class="p-6 border-t border-dark-border flex justify-end gap-2">
                    <button type="button" class="modal-cancel-btn bg-dark-card text-gray-300 font-bold py-2 px-5 rounded-lg border border-dark-border">Cancelar</button>
                    <button type="submit" name="comunidade_editar_categoria" class="text-white font-bold py-2 px-5 rounded-lg" style="background-color: var(--accent-primary);">Salvar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Formulários antigos (ocultos; edição via drawer) -->
    <div id="old-forms-placeholder" class="hidden">
    <!-- Personalizar Aparência do Curso -->
    <div class="bg-dark-card p-6 rounded-lg shadow-md mb-8" style="border-color: var(--accent-primary);">
        <h2 class="text-2xl font-semibold mb-4 text-white">Personalizar Aparência do Curso</h2>
        <form action="/index?pagina=gerenciar_curso&produto_id=<?php echo $produto_id; ?>" method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <div class="mb-4">
                <label for="banner_curso" class="block text-gray-300 text-sm font-semibold mb-2">Banner do Topo</label>
                <?php if (!empty($curso['banner_url']) && file_exists($curso['banner_url'])): ?>
                    <div class="mb-2">
                        <img src="<?php echo htmlspecialchars($curso['banner_url']); ?>" alt="Banner atual" class="w-full h-48 object-cover rounded-lg border border-dark-border">
                        <label class="mt-2 flex items-center text-sm text-gray-400">
                            <input type="checkbox" name="remove_banner" value="1" class="h-4 w-4 mr-1 text-red-400 focus:ring-red-500 rounded"> Remover banner existente
                        </label>
                    </div>
                <?php endif; ?>
                <input type="file" id="banner_curso" name="banner_curso" class="w-full text-sm text-gray-400 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold" style="--file-bg: color-mix(in srgb, var(--accent-primary) 20%, transparent); --file-text: var(--accent-primary);" onmouseover="this.style.setProperty('--file-bg', 'color-mix(in srgb, var(--accent-primary) 30%, transparent)')" onmouseout="this.style.setProperty('--file-bg', 'color-mix(in srgb, var(--accent-primary) 20%, transparent)')" accept="image/*">
                <p class="mt-1 text-xs text-gray-400">Recomendado: 1920x400px</p>
            </div>
            <button type="submit" name="salvar_banner_curso" class="text-white font-bold py-2 px-5 rounded-lg transition" style="background-color: var(--accent-primary);" onmouseover="this.style.backgroundColor='var(--accent-primary-hover)'" onmouseout="this.style.backgroundColor='var(--accent-primary)'">Salvar Banner</button>
        </form>
    </div>

    <!-- Configurações do Curso (comentários, comunidade) -->
    <div class="bg-dark-card p-6 rounded-lg shadow-md mb-8" style="border-color: var(--accent-primary);">
        <h2 class="text-2xl font-semibold mb-4 text-white">Configurações do Curso</h2>
        <form action="/index?pagina=gerenciar_curso&produto_id=<?php echo $produto_id; ?>" method="post">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <div class="space-y-4">
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" name="allow_comments" value="1" class="form-checkbox" <?php echo !empty($curso['allow_comments']) ? 'checked' : ''; ?>>
                    <span class="text-gray-300 font-medium">Permitir comentários nas aulas</span>
                </label>
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" name="community_enabled" value="1" class="form-checkbox" <?php echo !empty($curso['community_enabled']) ? 'checked' : ''; ?>>
                    <span class="text-gray-300 font-medium">Habilitar comunidade (feed) para este curso</span>
                </label>
            </div>
            <button type="submit" name="salvar_config_curso" class="mt-4 text-white font-bold py-2 px-5 rounded-lg transition" style="background-color: var(--accent-primary);" onmouseover="this.style.backgroundColor='var(--accent-primary-hover)'" onmouseout="this.style.backgroundColor='var(--accent-primary)'">Salvar Configurações</button>
        </form>
    </div>

    <!-- Comunidade: Categorias do feed (quando comunidade habilitada) -->
    <?php if (!empty($curso['community_enabled'])): ?>
    <div class="bg-dark-card p-6 rounded-lg shadow-md mb-8" style="border-color: var(--accent-primary);">
        <h2 class="text-2xl font-semibold mb-4 text-white">Categorias do Feed (Comunidade)</h2>
        <p class="text-gray-400 text-sm mb-4">Crie categorias (páginas) do feed. Em cada categoria você pode permitir que alunos postem (postagem pública) ou apenas você posta.</p>
        <form action="/index?pagina=gerenciar_curso&produto_id=<?php echo $produto_id; ?>" method="post" class="flex flex-wrap gap-4 items-end mb-6">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <div>
                <label for="categoria_nome_add" class="block text-gray-300 text-sm font-semibold mb-1">Nome da categoria</label>
                <input type="text" id="categoria_nome_add" name="categoria_nome" placeholder="Ex: Avisos" class="form-input-style px-4 py-2 bg-dark-elevated border border-dark-border rounded-lg text-white w-56" style="--tw-ring-color: var(--accent-primary);">
            </div>
            <label class="flex items-center gap-2 cursor-pointer">
                <input type="checkbox" name="categoria_public_posting" value="1" class="form-checkbox">
                <span class="text-gray-300 text-sm">Postagem pública (alunos podem postar)</span>
            </label>
            <button type="submit" name="comunidade_adicionar_categoria" class="text-white font-bold py-2 px-4 rounded-lg transition" style="background-color: var(--accent-primary);"><i data-lucide="plus" class="w-4 h-4 inline-block mr-1"></i> Adicionar Categoria</button>
        </form>
        <?php if (!empty($comunidade_categorias)): ?>
        <div class="space-y-3">
            <?php foreach ($comunidade_categorias as $cat): ?>
            <div class="border border-dark-border rounded-lg p-4 bg-dark-elevated flex justify-between items-center flex-wrap gap-2">
                <div>
                    <span class="font-bold text-white"><?php echo htmlspecialchars($cat['nome']); ?></span>
                    <span class="text-xs font-medium px-2 py-0.5 rounded ml-2 <?php echo !empty($cat['is_public_posting']) ? '' : 'bg-gray-700 text-gray-400'; ?>" <?php echo !empty($cat['is_public_posting']) ? 'style="background-color: color-mix(in srgb, var(--accent-primary) 40%, transparent); color: var(--accent-primary);"' : ''; ?>>
                        <?php echo !empty($cat['is_public_posting']) ? 'Postagem pública' : 'Só infoprodutor'; ?>
                    </span>
                </div>
                <div class="flex items-center gap-2">
                    <button type="button" class="edit-cat-comunidade-btn text-blue-400 hover:text-blue-300 p-1 rounded" data-cat-id="<?php echo (int)$cat['id']; ?>" data-nome="<?php echo htmlspecialchars($cat['nome']); ?>" data-public="<?php echo (int)$cat['is_public_posting']; ?>"><i data-lucide="edit" class="w-5 h-5"></i></button>
                    <form action="/index?pagina=gerenciar_curso&produto_id=<?php echo $produto_id; ?>" method="post" onsubmit="return confirm('Remover esta categoria?');" class="inline">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <input type="hidden" name="categoria_id" value="<?php echo (int)$cat['id']; ?>">
                        <button type="submit" name="comunidade_deletar_categoria" class="text-red-400 hover:text-red-300 p-1 rounded"><i data-lucide="trash-2" class="w-5 h-5"></i></button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <p class="text-gray-500 text-sm">Nenhuma categoria ainda. Adicione uma acima.</p>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Seções do Curso -->
    <?php
    $tem_secoes = (bool)count($secoes);
    if ($tem_secoes || true):
    ?>
    <div class="bg-dark-card p-6 rounded-lg shadow-md mb-8" style="border-color: var(--accent-primary);">
        <h2 class="text-2xl font-semibold mb-4 text-white">Seções</h2>
        <p class="text-gray-400 text-sm mb-4">Organize o conteúdo em seções. Cada seção pode ser: conteúdo do curso (módulos/aulas) ou outros produtos (aluno acessa ou compra).</p>
        <form action="/index?pagina=gerenciar_curso&produto_id=<?php echo $produto_id; ?>" method="post" class="flex flex-wrap gap-4 items-end mb-6">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <div>
                <label for="titulo_secao_add" class="block text-gray-300 text-sm font-semibold mb-1">Título da seção</label>
                <input type="text" id="titulo_secao_add" name="titulo_secao" placeholder="Ex: Módulos do curso" class="form-input-style px-4 py-2 bg-dark-elevated border border-dark-border rounded-lg text-white w-56" style="--tw-ring-color: var(--accent-primary);">
            </div>
            <div>
                <label for="tipo_secao_add" class="block text-gray-300 text-sm font-semibold mb-1">Tipo</label>
                <select id="tipo_secao_add" name="tipo_secao" class="form-input-style px-4 py-2 bg-dark-elevated border border-dark-border rounded-lg text-white" style="--tw-ring-color: var(--accent-primary);">
                    <option value="curso">Conteúdo do curso</option>
                    <option value="outros_produtos">Outros produtos</option>
                </select>
            </div>
            <div>
                <label for="tipo_capa_add" class="block text-gray-300 text-sm font-semibold mb-1">Tipo de Capa</label>
                <select id="tipo_capa_add" name="tipo_capa" class="form-input-style px-4 py-2 bg-dark-elevated border border-dark-border rounded-lg text-white" style="--tw-ring-color: var(--accent-primary);">
                    <option value="vertical">Capa Vertical (padrão)</option>
                    <option value="horizontal">Capa Horizontal (842x327)</option>
                </select>
            </div>
            <button type="submit" name="adicionar_secao" class="text-white font-bold py-2 px-4 rounded-lg transition" style="background-color: var(--accent-primary);" onmouseover="this.style.backgroundColor='var(--accent-primary-hover)'" onmouseout="this.style.backgroundColor='var(--accent-primary)'">
                <i data-lucide="plus" class="w-4 h-4 inline-block mr-1"></i> Adicionar Seção
            </button>
        </form>
        <?php foreach ($secoes as $secao): ?>
        <div class="border border-dark-border rounded-lg p-4 mb-4 bg-dark-elevated">
            <div class="flex justify-between items-start gap-4 flex-wrap">
                <div>
                    <h3 class="text-lg font-bold text-white"><?php echo htmlspecialchars($secao['titulo']); ?></h3>
                    <span class="text-xs font-medium px-2 py-0.5 rounded" style="background-color: var(--accent-primary); color: white;">
                        <?php
                        echo $secao['tipo_secao'] === 'curso' ? 'Conteúdo do curso' : 'Outros produtos';
                        ?>
                    </span>
                </div>
                <div class="flex items-center gap-2">
                    <button type="button" class="edit-secao-btn text-blue-400 hover:text-blue-300 p-1 rounded" data-secao-id="<?php echo (int)$secao['id']; ?>" data-titulo="<?php echo htmlspecialchars($secao['titulo']); ?>" data-tipo="<?php echo htmlspecialchars($secao['tipo_secao']); ?>" data-tipo-capa="<?php echo htmlspecialchars($secao['tipo_capa'] ?? 'vertical'); ?>">
                        <i data-lucide="edit" class="w-5 h-5"></i>
                    </button>
                    <form action="/index?pagina=gerenciar_curso&produto_id=<?php echo $produto_id; ?>" method="post" onsubmit="return confirm('Remover esta seção? Módulos ficarão sem seção.');" class="inline">
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                        <input type="hidden" name="secao_id" value="<?php echo (int)$secao['id']; ?>">
                        <button type="submit" name="deletar_secao" class="text-red-400 hover:text-red-300 p-1 rounded"><i data-lucide="trash-2" class="w-5 h-5"></i></button>
                    </form>
                </div>
            </div>
            <?php if ($secao['tipo_secao'] === 'outros_produtos'): ?>
            <div class="mt-3">
                <p class="text-gray-400 text-sm mb-2">Produtos nesta seção:</p>
                <ul class="list-disc list-inside text-gray-300 text-sm space-y-1">
                    <?php foreach ($secao['produtos'] ?? [] as $sp): ?>
                    <li class="flex items-center gap-2">
                        <?php echo htmlspecialchars($sp['nome']); ?> - R$ <?php echo number_format($sp['preco'], 2, ',', '.'); ?>
                        <form action="/index?pagina=gerenciar_curso&produto_id=<?php echo $produto_id; ?>" method="post" class="inline">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                            <input type="hidden" name="secao_id" value="<?php echo (int)$secao['id']; ?>">
                            <input type="hidden" name="produto_id" value="<?php echo (int)$sp['produto_id']; ?>">
                            <button type="submit" name="secao_remover_produto" class="text-red-400 hover:text-red-300 text-xs">Remover</button>
                        </form>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <form action="/index?pagina=gerenciar_curso&produto_id=<?php echo $produto_id; ?>" method="post" class="mt-2 flex gap-2 items-center">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="secao_id" value="<?php echo (int)$secao['id']; ?>">
                    <select name="produto_id" class="form-input-style px-3 py-1.5 bg-dark-card border border-dark-border rounded text-white text-sm" style="--tw-ring-color: var(--accent-primary);">
                        <option value="">Selecione um produto...</option>
                        <?php foreach ($produtos_disponiveis as $p): ?>
                        <option value="<?php echo (int)$p['id']; ?>"><?php echo htmlspecialchars($p['nome']); ?> - R$ <?php echo number_format($p['preco'], 2, ',', '.'); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" name="secao_adicionar_produto" class="text-white text-sm font-semibold py-1.5 px-3 rounded" style="background-color: var(--accent-primary);">Adicionar</button>
                </form>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Adicionar Novo Módulo -->
    <div class="bg-dark-card p-6 rounded-lg shadow-md mb-8" style="border-color: var(--accent-primary);">
        <h2 class="text-2xl font-semibold mb-4 text-white">Adicionar Novo Módulo</h2>
        <form action="/index?pagina=gerenciar_curso&produto_id=<?php echo $produto_id; ?>" method="post" class="space-y-4"> <!-- Changed to space-y-4 for vertical stacking -->
            <?php
            if (!isset($csrf_token)) {
                $csrf_token = generate_csrf_token();
            }
            ?>
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <?php if ($tem_secoes): ?>
            <div>
                <label for="secao_id_add" class="block text-gray-300 text-sm font-semibold mb-2">Seção</label>
                <select id="secao_id_add" name="secao_id" class="form-input-style w-full px-4 py-3 bg-dark-elevated border border-dark-border rounded-lg focus:outline-none focus:ring-2 text-white" style="--tw-ring-color: var(--accent-primary);" onfocus="this.style.borderColor='var(--accent-primary)'" onblur="this.style.borderColor='rgba(255,255,255,0.1)'">
                    <option value="">Sem seção</option>
                    <?php foreach ($secoes as $s): ?>
                    <?php if ($s['tipo_secao'] === 'curso'): ?>
                    <option value="<?php echo (int)$s['id']; ?>"><?php echo htmlspecialchars($s['titulo']); ?></option>
                    <?php endif; ?>
                    <?php endforeach; ?>
                </select>
                <p class="mt-1 text-xs text-gray-400">Módulos só podem ser vinculados a seções do tipo "Conteúdo do curso".</p>
            </div>
            <?php endif; ?>
            <div>
                <label for="titulo_modulo_add" class="block text-gray-300 text-sm font-semibold mb-2">Título do Módulo</label>
                <input type="text" id="titulo_modulo_add" name="titulo_modulo" placeholder="Ex: Módulo 1 - Introdução" required class="form-input-style w-full px-4 py-3 bg-dark-elevated border border-dark-border rounded-lg focus:outline-none focus:ring-2 text-white" style="--tw-ring-color: var(--accent-primary);" onfocus="this.style.borderColor='var(--accent-primary)'" onblur="this.style.borderColor='rgba(255,255,255,0.1)'">
            </div>
            <!-- NEW: Release Days for Add Module -->
            <div>
                <label for="release_days_modulo_add" class="block text-gray-300 text-sm font-semibold mb-2">Liberar após (dias)</label>
                <input type="number" id="release_days_modulo_add" name="release_days_modulo" value="0" min="0" class="form-input-style w-full px-4 py-3 bg-dark-elevated border border-dark-border rounded-lg focus:outline-none focus:ring-2 text-white" style="--tw-ring-color: var(--accent-primary);" onfocus="this.style.borderColor='var(--accent-primary)'" onblur="this.style.borderColor='rgba(255,255,255,0.1)'" placeholder="0 = Liberação imediata">
                <p class="mt-1 text-xs text-gray-400">Defina quantos dias após a compra do curso este módulo será liberado para o aluno.</p>
            </div>
            <div class="flex justify-end">
                <button type="submit" name="adicionar_modulo" class="text-white font-bold py-3 px-6 rounded-lg transition duration-300 flex items-center space-x-2" style="background-color: var(--accent-primary);" onmouseover="this.style.backgroundColor='var(--accent-primary-hover)'" onmouseout="this.style.backgroundColor='var(--accent-primary)'">
                    <i data-lucide="plus" class="w-5 h-5"></i>
                    <span>Adicionar Módulo</span>
                </button>
            </div>
        </form>
    </div>
    </div><!-- /old-forms-placeholder -->

</div>

<!-- Modal para Adicionar Aula -->
<div id="add-lesson-modal" class="fixed inset-0 bg-black bg-opacity-60 z-50 flex items-center justify-center p-4 hidden overflow-y-auto">
    <div class="bg-dark-card rounded-xl shadow-2xl w-full max-w-4xl h-[90vh] max-h-[90vh] transform transition-all opacity-0 scale-95 border flex flex-col my-4" style="border-color: var(--accent-primary);" id="add-lesson-modal-content">
        <form action="/index?pagina=gerenciar_curso&produto_id=<?php echo $produto_id; ?>" method="post" enctype="multipart/form-data" class="flex flex-col h-full min-h-0">
            <?php
            if (!isset($csrf_token)) {
                $csrf_token = generate_csrf_token();
            }
            ?>
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <div class="p-6 border-b border-dark-border flex-shrink-0"><h2 class="text-2xl font-bold text-white">Adicionar Nova Aula em <span id="modal-modulo-titulo-add" style="color: var(--accent-primary);"></span></h2></div>
            <div class="p-6 space-y-4 overflow-y-auto flex-1 min-h-0">
                <input type="hidden" name="modulo_id" id="modal-modulo-id-add">
                <div><label for="add_titulo_aula" class="block text-gray-300 text-sm font-semibold mb-2">Título da Aula</label><input type="text" id="add_titulo_aula" name="titulo_aula" required class="form-input-style w-full px-4 py-3 bg-dark-elevated border border-dark-border rounded-lg focus:outline-none focus:ring-2 text-white" style="--tw-ring-color: var(--accent-primary);" onfocus="this.style.borderColor='var(--accent-primary)'" onblur="this.style.borderColor='rgba(255,255,255,0.1)'" placeholder="Ex: Aula 1 - Bem-vindo ao curso"></div>
                
                <!-- Tipo de Conteúdo da Aula (Add) -->
                <div>
                    <label for="add_tipo_conteudo" class="block text-gray-300 text-sm font-semibold mb-2">Tipo de Conteúdo</label>
                    <select id="add_tipo_conteudo" name="tipo_conteudo" class="form-input-style w-full px-4 py-3 bg-dark-elevated border border-dark-border rounded-lg focus:outline-none focus:ring-2 text-white" style="--tw-ring-color: var(--accent-primary);" onfocus="this.style.borderColor='var(--accent-primary)'" onblur="this.style.borderColor='rgba(255,255,255,0.1)'">
                        <option value="video">Somente Vídeo</option>
                        <option value="files">Somente Arquivos</option>
                        <option value="mixed">Vídeo e Arquivos</option>
                        <option value="text">Apenas Texto</option>
                        <option value="download_protegido">Download Protegido</option>
                    </select>
                </div>

                <!-- URL do Vídeo (Add) -->
                <div id="add-video-url-container">
                    <label for="add_url_video" class="block text-gray-300 text-sm font-semibold mb-2">URL do Vídeo (YouTube)</label>
                    <input type="url" id="add_url_video" name="url_video" class="form-input-style w-full px-4 py-3 bg-dark-elevated border border-dark-border rounded-lg focus:outline-none focus:ring-2 text-white" style="--tw-ring-color: var(--accent-primary);" onfocus="this.style.borderColor='var(--accent-primary)'" onblur="this.style.borderColor='rgba(255,255,255,0.1)'" placeholder="https://www.youtube.com/watch?v=...">
                </div>

                <!-- Upload de Arquivos (Add) -->
                <div id="add-files-upload-container">
                    <label for="add_aula_files" class="block text-gray-300 text-sm font-semibold mb-2">Upload de Arquivos</label>
                    <input type="file" id="add_aula_files" name="aula_files[]" multiple class="w-full text-sm text-gray-400 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold" style="--file-bg: color-mix(in srgb, var(--accent-primary) 20%, transparent); --file-text: var(--accent-primary);" onmouseover="this.style.setProperty('--file-bg', 'color-mix(in srgb, var(--accent-primary) 30%, transparent)')" onmouseout="this.style.setProperty('--file-bg', 'color-mix(in srgb, var(--accent-primary) 20%, transparent)')">
                    <p class="mt-1 text-xs text-gray-400">Múltiplos arquivos (PDF, imagens, zip, etc.)</p>
                </div>

                <!-- Campos de Download Protegido (Add) -->
                <div id="add-download-protegido-container" style="display: none;">
                    <label for="add_download_link" class="block text-gray-300 text-sm font-semibold mb-2">Link do Download (Google Drive)</label>
                    <input type="url" id="add_download_link" name="download_link" class="form-input-style w-full px-4 py-3 bg-dark-elevated border border-dark-border rounded-lg focus:outline-none focus:ring-2 text-white" style="--tw-ring-color: var(--accent-primary);" onfocus="this.style.borderColor='var(--accent-primary)'" onblur="this.style.borderColor='rgba(255,255,255,0.1)'" placeholder="https://drive.google.com/...">
                    <p class="mt-1 text-xs text-gray-400">Cole aqui o link compartilhado do Google Drive ou outro serviço de download</p>
                    
                    <label for="add_termos_consentimento" class="block text-gray-300 text-sm font-semibold mb-2 mt-4">Termos ou Política de Consentimento</label>
                    <textarea id="add_termos_consentimento" name="termos_consentimento" rows="8" class="form-input-style w-full px-4 py-3 bg-dark-elevated border border-dark-border rounded-lg focus:outline-none focus:ring-2 text-white" style="--tw-ring-color: var(--accent-primary);" onfocus="this.style.borderColor='var(--accent-primary)'" onblur="this.style.borderColor='rgba(255,255,255,0.1)'" placeholder="Digite aqui os termos, política de uso ou condições que o cliente deve aceitar antes de baixar o material..."></textarea>
                    <p class="mt-1 text-xs text-gray-400">Estes termos serão exibidos ao cliente antes do download. Use para políticas de uso, direitos autorais, etc.</p>
                </div>

                <div><label for="add_descricao_aula" class="block text-gray-300 text-sm font-semibold mb-2">Descrição / Materiais</label><textarea id="add_descricao_aula" name="descricao_aula" rows="5" class="form-input-style w-full px-4 py-3 bg-dark-elevated border border-dark-border rounded-lg focus:outline-none focus:ring-2 text-white" style="--tw-ring-color: var(--accent-primary);" onfocus="this.style.borderColor='var(--accent-primary)'" onblur="this.style.borderColor='rgba(255,255,255,0.1)'" placeholder="Links, textos de apoio, etc."></textarea></div>
                <!-- Release Days for Add Lesson -->
                <div>
                    <label for="add_release_days_aula" class="block text-gray-300 text-sm font-semibold mb-2">Liberar após (dias)</label>
                    <input type="number" id="add_release_days_aula" name="release_days_aula" value="0" min="0" class="form-input-style w-full px-4 py-3 bg-dark-elevated border border-dark-border rounded-lg focus:outline-none focus:ring-2 text-white" style="--tw-ring-color: var(--accent-primary);" onfocus="this.style.borderColor='var(--accent-primary)'" onblur="this.style.borderColor='rgba(255,255,255,0.1)'" placeholder="0 = Liberação imediata">
                    <p class="mt-1 text-xs text-gray-400">Defina quantos dias após a compra do curso esta aula será liberada para o aluno.</p>
                </div>
            </div>
            <div class="px-6 py-4 bg-dark-elevated rounded-b-xl flex justify-end items-center space-x-4 border-t border-dark-border flex-shrink-0">
                <button type="button" class="modal-cancel-btn bg-dark-card text-gray-300 font-bold py-2 px-5 rounded-lg hover:bg-dark-elevated border border-dark-border">Cancelar</button>
                <button type="submit" name="adicionar_aula" class="text-white font-bold py-2 px-5 rounded-lg" style="background-color: var(--accent-primary);" onmouseover="this.style.backgroundColor='var(--accent-primary-hover)'" onmouseout="this.style.backgroundColor='var(--accent-primary)'">Salvar Aula</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal para Editar Aula -->
<div id="edit-lesson-modal" class="fixed inset-0 bg-black bg-opacity-60 z-50 flex items-center justify-center p-4 hidden overflow-y-auto">
    <div class="bg-dark-card rounded-xl shadow-2xl w-full max-w-4xl h-[90vh] max-h-[90vh] transform transition-all opacity-0 scale-95 border flex flex-col my-4" style="border-color: var(--accent-primary);" id="edit-lesson-modal-content">
        <form action="/index?pagina=gerenciar_curso&produto_id=<?php echo $produto_id; ?>" method="post" enctype="multipart/form-data" class="flex flex-col h-full min-h-0">
            <?php
            if (!isset($csrf_token)) {
                $csrf_token = generate_csrf_token();
            }
            ?>
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <div class="p-6 border-b border-dark-border flex-shrink-0"><h2 class="text-2xl font-bold text-white">Editar Aula</h2></div>
            <div class="p-6 space-y-4 overflow-y-auto flex-1 min-h-0">
                <input type="hidden" name="aula_id" id="edit_aula_id">
                <div><label for="edit_titulo_aula" class="block text-gray-300 text-sm font-semibold mb-2">Título da Aula</label><input type="text" id="edit_titulo_aula" name="titulo_aula" required class="form-input-style w-full px-4 py-3 bg-dark-elevated border border-dark-border rounded-lg focus:outline-none focus:ring-2 text-white" style="--tw-ring-color: var(--accent-primary);" onfocus="this.style.borderColor='var(--accent-primary)'" onblur="this.style.borderColor='rgba(255,255,255,0.1)'" placeholder="Ex: Aula 1 - Bem-vindo ao curso"></div>

                <!-- Tipo de Conteúdo da Aula (Edit) -->
                <div>
                    <label for="edit_tipo_conteudo" class="block text-gray-300 text-sm font-semibold mb-2">Tipo de Conteúdo</label>
                    <select id="edit_tipo_conteudo" name="tipo_conteudo" class="form-input-style w-full px-4 py-3 bg-dark-elevated border border-dark-border rounded-lg focus:outline-none focus:ring-2 text-white" style="--tw-ring-color: var(--accent-primary);" onfocus="this.style.borderColor='var(--accent-primary)'" onblur="this.style.borderColor='rgba(255,255,255,0.1)'">
                        <option value="video">Somente Vídeo</option>
                        <option value="files">Somente Arquivos</option>
                        <option value="mixed">Vídeo e Arquivos</option>
                        <option value="text">Apenas Texto</option>
                        <option value="download_protegido">Download Protegido</option>
                    </select>
                </div>

                <!-- URL do Vídeo (Edit) -->
                <div id="edit-video-url-container">
                    <label for="edit_url_video" class="block text-gray-300 text-sm font-semibold mb-2">URL do Vídeo (YouTube)</label>
                    <input type="url" id="edit_url_video" name="url_video" class="form-input-style w-full px-4 py-3 bg-dark-elevated border border-dark-border rounded-lg focus:outline-none focus:ring-2 text-white" style="--tw-ring-color: var(--accent-primary);" onfocus="this.style.borderColor='var(--accent-primary)'" onblur="this.style.borderColor='rgba(255,255,255,0.1)'" placeholder="https://www.youtube.com/watch?v=...">
                </div>

                <!-- Arquivos Existentes (Edit) -->
                <div id="edit-existing-files-container" class="space-y-2">
                    <p class="block text-gray-300 text-sm font-semibold mb-2">Arquivos Atuais:</p>
                    <div id="existing-files-list">
                        <!-- Arquivos serão carregados aqui via JS -->
                    </div>
                </div>

                <!-- Upload de Novos Arquivos (Edit) -->
                <div id="edit-new-files-upload-container">
                    <label for="edit_aula_files" class="block text-gray-300 text-sm font-semibold mb-2">Upload de Novos Arquivos</label>
                    <input type="file" id="edit_aula_files" name="aula_files[]" multiple class="w-full text-sm text-gray-400 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold" style="--file-bg: color-mix(in srgb, var(--accent-primary) 20%, transparent); --file-text: var(--accent-primary);" onmouseover="this.style.setProperty('--file-bg', 'color-mix(in srgb, var(--accent-primary) 30%, transparent)')" onmouseout="this.style.setProperty('--file-bg', 'color-mix(in srgb, var(--accent-primary) 20%, transparent)')">
                    <p class="mt-1 text-xs text-gray-400">Múltiplos arquivos (PDF, imagens, zip, etc.)</p>
                </div>

                <!-- Campos de Download Protegido (Edit) -->
                <div id="edit-download-protegido-container" style="display: none;">
                    <label for="edit_download_link" class="block text-gray-300 text-sm font-semibold mb-2">Link do Download (Google Drive)</label>
                    <input type="url" id="edit_download_link" name="download_link" class="form-input-style w-full px-4 py-3 bg-dark-elevated border border-dark-border rounded-lg focus:outline-none focus:ring-2 text-white" style="--tw-ring-color: var(--accent-primary);" onfocus="this.style.borderColor='var(--accent-primary)'" onblur="this.style.borderColor='rgba(255,255,255,0.1)'" placeholder="https://drive.google.com/...">
                    <p class="mt-1 text-xs text-gray-400">Cole aqui o link compartilhado do Google Drive ou outro serviço de download</p>
                    
                    <label for="edit_termos_consentimento" class="block text-gray-300 text-sm font-semibold mb-2 mt-4">Termos ou Política de Consentimento</label>
                    <textarea id="edit_termos_consentimento" name="termos_consentimento" rows="8" class="form-input-style w-full px-4 py-3 bg-dark-elevated border border-dark-border rounded-lg focus:outline-none focus:ring-2 text-white" style="--tw-ring-color: var(--accent-primary);" onfocus="this.style.borderColor='var(--accent-primary)'" onblur="this.style.borderColor='rgba(255,255,255,0.1)'" placeholder="Digite aqui os termos, política de uso ou condições que o cliente deve aceitar antes de baixar o material..."></textarea>
                    <p class="mt-1 text-xs text-gray-400">Estes termos serão exibidos ao cliente antes do download. Use para políticas de uso, direitos autorais, etc.</p>
                </div>

                <div><label for="edit_descricao_aula" class="block text-gray-300 text-sm font-semibold mb-2">Descrição / Materiais</label><textarea id="edit_descricao_aula" name="descricao_aula" rows="5" class="form-input-style w-full px-4 py-3 bg-dark-elevated border border-dark-border rounded-lg focus:outline-none focus:ring-2 text-white" style="--tw-ring-color: var(--accent-primary);" onfocus="this.style.borderColor='var(--accent-primary)'" onblur="this.style.borderColor='rgba(255,255,255,0.1)'" placeholder="Links, textos de apoio, etc."></textarea></div>
                <!-- Release Days for Edit Lesson -->
                <div>
                    <label for="edit_release_days_aula" class="block text-gray-300 text-sm font-semibold mb-2">Liberar após (dias)</label>
                    <input type="number" id="edit_release_days_aula" name="release_days_aula" value="0" min="0" class="form-input-style w-full px-4 py-3 bg-dark-elevated border border-dark-border rounded-lg focus:outline-none focus:ring-2 text-white" style="--tw-ring-color: var(--accent-primary);" onfocus="this.style.borderColor='var(--accent-primary)'" onblur="this.style.borderColor='rgba(255,255,255,0.1)'" placeholder="0 = Liberação imediata">
                    <p class="mt-1 text-xs text-gray-400">Defina quantos dias após a compra do curso esta aula será liberada para o aluno.</p>
                </div>
            </div>
            <div class="px-6 py-4 bg-dark-elevated rounded-b-xl flex justify-end items-center space-x-4 border-t border-dark-border flex-shrink-0">
                <button type="button" class="modal-cancel-btn bg-dark-card text-gray-300 font-bold py-2 px-5 rounded-lg hover:bg-dark-elevated border border-dark-border">Cancelar</button>
                <button type="submit" name="editar_aula_form" class="text-white font-bold py-2 px-5 rounded-lg" style="background-color: var(--accent-primary);" onmouseover="this.style.backgroundColor='var(--accent-primary-hover)'" onmouseout="this.style.backgroundColor='var(--accent-primary)'">Salvar Alterações</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal para Editar Módulo -->
<div id="edit-module-modal" class="fixed inset-0 bg-black bg-opacity-60 z-50 flex items-center justify-center p-4 hidden">
    <div class="bg-dark-card rounded-xl shadow-2xl w-full max-w-2xl transform transition-all opacity-0 scale-95" style="border-color: var(--accent-primary);" id="edit-module-modal-content">
        <form action="/index?pagina=gerenciar_curso&produto_id=<?php echo $produto_id; ?>" method="post" enctype="multipart/form-data">
            <?php
            if (!isset($csrf_token)) {
                $csrf_token = generate_csrf_token();
            }
            ?>
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <div class="p-6 border-b border-dark-border"><h2 class="text-2xl font-bold text-white">Editar Módulo</h2></div>
            <div class="p-6 space-y-4">
                <input type="hidden" name="modulo_id" id="modal-modulo-id-edit">
                <?php if ($tem_secoes): ?>
                <div>
                    <label for="modal-secao-id-edit" class="block text-gray-300 text-sm font-semibold mb-2">Seção</label>
                    <select id="modal-secao-id-edit" name="secao_id" class="form-input-style w-full px-4 py-3 bg-dark-elevated border border-dark-border rounded-lg text-white" style="--tw-ring-color: var(--accent-primary);">
                        <option value="">Sem seção</option>
                        <?php foreach ($secoes as $s): ?>
                        <?php if ($s['tipo_secao'] === 'curso'): ?>
                        <option value="<?php echo (int)$s['id']; ?>"><?php echo htmlspecialchars($s['titulo']); ?></option>
                        <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <div><label for="modal-titulo-modulo-edit" class="block text-gray-300 text-sm font-semibold mb-2">Título do Módulo</label><input type="text" id="modal-titulo-modulo-edit" name="titulo_modulo" required class="form-input-style"></div>
                <div>
                    <label for="imagem_capa_modulo" class="block text-gray-300 text-sm font-semibold mb-2">Imagem de Capa do Módulo</label>
                    <img id="modal-imagem-preview" src="" alt="Preview da imagem" class="w-48 h-auto object-cover rounded-lg border border-dark-border mb-2 hidden">
                    <input type="file" id="imagem_capa_modulo" name="imagem_capa_modulo" class="w-full text-sm text-gray-300 bg-dark-elevated border border-dark-border rounded-lg px-4 py-2 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:cursor-pointer cursor-pointer" style="--file-bg: color-mix(in srgb, var(--accent-primary) 20%, transparent); --file-text: var(--accent-primary);" onmouseover="this.style.setProperty('--file-bg', 'color-mix(in srgb, var(--accent-primary) 30%, transparent)')" onmouseout="this.style.setProperty('--file-bg', 'color-mix(in srgb, var(--accent-primary) 20%, transparent)')" accept="image/*">
                    <label class="mt-2 flex items-center text-sm text-gray-400">
                        <input type="checkbox" name="remove_imagem_capa_modulo" value="1" id="remove_imagem_capa_modulo" class="h-4 w-4 mr-1 bg-dark-elevated border-dark-border rounded cursor-pointer" style="color: var(--accent-primary); --tw-ring-color: var(--accent-primary);"> Remover imagem de capa existente
                    </label>
                </div>
                <!-- Release Days for Edit Module -->
                <div>
                    <label for="modal-release-days-modulo-edit" class="block text-gray-300 text-sm font-semibold mb-2">Liberar após (dias)</label>
                    <input type="number" id="modal-release-days-modulo-edit" name="release_days_modulo" value="0" min="0" class="form-input-style" placeholder="0 = Liberação imediata">
                    <p class="mt-1 text-xs text-gray-400">Defina quantos dias após a compra do curso este módulo será liberado para o aluno.</p>
                </div>
            </div>
            <div class="px-6 py-4 bg-dark-elevated rounded-b-xl flex justify-end items-center space-x-4 border-t border-dark-border">
                <button type="button" class="modal-cancel-btn bg-dark-card text-gray-300 font-bold py-2 px-5 rounded-lg hover:bg-dark-elevated border border-dark-border">Cancelar</button>
                <button type="submit" name="editar_modulo" class="text-white font-bold py-2 px-5 rounded-lg" style="background-color: var(--accent-primary);" onmouseover="this.style.backgroundColor='var(--accent-primary-hover)'" onmouseout="this.style.backgroundColor='var(--accent-primary)'">Salvar Alterações</button>
            </div>
        </form>
    </div>
</div>

<style>
/* --- Inputs profissionais (drawer + modais) --- */
.form-input-style,
#gerenciar-drawer input[type="text"],
#gerenciar-drawer input[type="url"],
#gerenciar-drawer input[type="number"],
#gerenciar-drawer input[type="email"],
#gerenciar-drawer textarea,
#gerenciar-drawer select,
#add-lesson-modal input[type="text"],
#add-lesson-modal input[type="url"],
#add-lesson-modal input[type="number"],
#add-lesson-modal textarea,
#add-lesson-modal select,
#edit-lesson-modal input[type="text"],
#edit-lesson-modal input[type="url"],
#edit-lesson-modal input[type="number"],
#edit-lesson-modal textarea,
#edit-lesson-modal select,
#edit-module-modal input[type="text"],
#edit-module-modal input[type="number"],
#edit-categoria-comunidade-modal input[type="text"] {
    width: 100%;
    padding: 0.625rem 1rem;
    font-size: 0.875rem;
    line-height: 1.4;
    color: rgba(255, 255, 255, 0.95) !important;
    background-color: #161b22 !important;
    border: 1px solid rgba(255, 255, 255, 0.12) !important;
    border-radius: 0.5rem;
    outline: none;
    transition: border-color 0.2s ease, box-shadow 0.2s ease;
}
.form-input-style::placeholder,
#gerenciar-drawer input::placeholder,
#gerenciar-drawer textarea::placeholder {
    color: rgba(255, 255, 255, 0.4) !important;
}
.form-input-style:focus,
#gerenciar-drawer input:focus,
#gerenciar-drawer textarea:focus,
#gerenciar-drawer select:focus,
#add-lesson-modal input:focus,
#add-lesson-modal textarea:focus,
#add-lesson-modal select:focus,
#edit-lesson-modal input:focus,
#edit-lesson-modal textarea:focus,
#edit-lesson-modal select:focus,
#edit-module-modal input:focus,
#edit-categoria-comunidade-modal input:focus {
    border-color: var(--accent-primary) !important;
    box-shadow: 0 0 0 2px rgba(50, 231, 104, 0.2) !important;
}
#gerenciar-drawer textarea,
#add-lesson-modal textarea,
#edit-lesson-modal textarea {
    min-height: 80px;
    resize: vertical;
}
#gerenciar-drawer select,
#add-lesson-modal select,
#edit-lesson-modal select,
#edit-module-modal select {
    cursor: pointer;
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 24 24' stroke='rgba(255,255,255,0.5)'%3E%3Cpath stroke-linecap='round' stroke-linejoin='round' stroke-width='2' d='M19 9l-7 7-7-7'/%3E%3C/svg%3E") !important;
    background-repeat: no-repeat !important;
    background-position: right 0.75rem center !important;
    background-size: 1.25rem !important;
    padding-right: 2.5rem !important;
}
#gerenciar-drawer select option,
#add-lesson-modal select option,
#edit-lesson-modal select option {
    background-color: #161b22 !important;
    color: #fff !important;
}
#gerenciar-drawer input[type="file"],
#add-lesson-modal input[type="file"],
#edit-lesson-modal input[type="file"],
#edit-module-modal input[type="file"] {
    padding: 0.5rem;
    font-size: 0.8125rem;
    color: rgba(255, 255, 255, 0.7) !important;
    background-color: #161b22 !important;
    border: 1px dashed rgba(255, 255, 255, 0.15) !important;
    border-radius: 0.5rem;
}
#gerenciar-drawer input[type="checkbox"],
#add-lesson-modal input[type="checkbox"],
#edit-lesson-modal input[type="checkbox"],
#edit-module-modal input[type="checkbox"],
#edit-categoria-comunidade-modal input[type="checkbox"] {
    width: 1rem;
    height: 1rem;
    accent-color: var(--accent-primary);
    cursor: pointer;
}
.form-checkbox {
    accent-color: var(--accent-primary);
}

.sortable-ghost {
    opacity: 0.4;
    background: color-mix(in srgb, var(--accent-primary) 20%, transparent);
}
#drawer-aulas-list-content .drawer-aula-row {
    transition: box-shadow 0.15s ease;
}
#drawer-aulas-list-content .drawer-aula-row.sortable-ghost {
    border-color: var(--accent-primary);
}
.secao-preview { transition: box-shadow 0.2s ease; }
.secao-preview:hover { box-shadow: 0 0 0 1px var(--accent-primary); }
.module-card-preview:hover { box-shadow: 0 0 0 2px var(--accent-primary); }
</style>

<script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    lucide.createIcons();

    const currentProductId = <?php echo $produto_id; ?>;

    // --- Drawer lateral ---
    const drawer = document.getElementById('gerenciar-drawer');
    const drawerBackdrop = document.getElementById('gerenciar-drawer-backdrop');
    const drawerTitle = document.getElementById('drawer-title');
    const drawerClose = document.getElementById('drawer-close');
    const panelTitles = { config: 'Configurações do curso', secao: 'Seção', modulo: 'Módulo', aula: 'Aula', 'produto-secao': 'Editar Produto', 'add-produto-secao': 'Adicionar Produto' };

    function openDrawer(panelName, opts) {
        opts = opts || {};
        document.querySelectorAll('.drawer-panel').forEach(p => { p.classList.add('hidden'); });
        const panel = document.getElementById('drawer-panel-' + panelName);
        if (panel) panel.classList.remove('hidden');
        drawerTitle.textContent = panelTitles[panelName] || 'Edição';
        drawerBackdrop.classList.remove('hidden');
        drawer.classList.remove('translate-x-full');
        document.body.style.overflow = 'hidden';

        if (panelName === 'config' || panelName === 'banner') return;
        if (panelName === 'secao') {
            const form = document.getElementById('drawer-form-secao');
            const idInput = document.getElementById('drawer-secao-id');
            const tituloInput = document.getElementById('drawer-titulo-secao');
            const tipoSelect = document.getElementById('drawer-tipo-secao');
            const tipoCapaSelect = document.getElementById('drawer-tipo-capa');
            const btnAdd = document.getElementById('drawer-secao-btn-add');
            const btnEdit = document.getElementById('drawer-secao-btn-edit');
            if (opts.mode === 'edit' && opts.data) {
                idInput.value = opts.data.secao_id || '';
                tituloInput.value = opts.data.titulo || '';
                tipoSelect.value = opts.data.tipo || 'curso';
                if (tipoCapaSelect) tipoCapaSelect.value = opts.data.tipo_capa || 'vertical';
                btnAdd.classList.add('hidden'); btnEdit.classList.remove('hidden');
            } else {
                idInput.value = ''; tituloInput.value = ''; tipoSelect.value = 'curso';
                if (tipoCapaSelect) tipoCapaSelect.value = 'vertical';
                btnAdd.classList.remove('hidden'); btnEdit.classList.add('hidden');
            }
            return;
        }
        if (panelName === 'modulo') {
            const addWrap = document.getElementById('drawer-modulo-add-wrap');
            const editWrap = document.getElementById('drawer-modulo-edit-wrap');
            const secaoSelect = document.getElementById('drawer-modulo-secao-select');
            if (opts.mode === 'add') {
                addWrap.classList.remove('hidden'); editWrap.classList.add('hidden');
                if (secaoSelect && opts.secao_id) secaoSelect.value = String(opts.secao_id);
            } else if (opts.mode === 'edit' && opts.data) {
                addWrap.classList.add('hidden'); editWrap.classList.remove('hidden');
                document.getElementById('drawer-modulo-id-edit').value = opts.data.modulo_id || '';
                document.getElementById('drawer-edit-modulo-titulo').value = opts.data.titulo || '';
                document.getElementById('drawer-edit-modulo-release').value = opts.data.release_days || 0;
                const secaoEdit = document.getElementById('drawer-edit-modulo-secao');
                if (secaoEdit && opts.data.secao_id !== undefined) secaoEdit.value = String(opts.data.secao_id);
            }
            return;
        }
        if (panelName === 'aula') {
            const addWrap = document.getElementById('drawer-aula-add-wrap');
            const editWrap = document.getElementById('drawer-aula-edit-wrap');
            if (opts.mode === 'add') {
                addWrap.classList.remove('hidden'); editWrap.classList.add('hidden');
                document.getElementById('drawer-add-aula-modulo-id').value = opts.modulo_id || '';
                document.getElementById('drawer-aula-modulo-titulo').textContent = opts.modulo_titulo || '';
                toggleDrawerAulaAddFields();
            } else if (opts.mode === 'edit' && opts.data) {
                addWrap.classList.add('hidden'); editWrap.classList.remove('hidden');
                document.getElementById('drawer-edit-aula-id').value = opts.data.aula_id || '';
                document.getElementById('drawer-edit-aula-titulo').value = opts.data.titulo || '';
                document.getElementById('drawer-edit-url-video').value = opts.data.url_video || '';
                document.getElementById('drawer-edit-release').value = opts.data.release_days || 0;
                document.getElementById('drawer-edit-descricao').value = opts.data.descricao || '';
                document.getElementById('drawer-edit-tipo-conteudo').value = opts.data.tipo_conteudo || 'video';
                document.getElementById('drawer-edit-download-link').value = opts.data.download_link || '';
                document.getElementById('drawer-edit-termos').value = opts.data.termos_consentimento || '';
                const existingFilesEl = document.getElementById('drawer-edit-existing-files');
                if (existingFilesEl) {
                    existingFilesEl.innerHTML = '';
                    const files = opts.data.files || [];
                    if (files.length) {
                        files.forEach(function(f) {
                            const div = document.createElement('div');
                            div.className = 'flex items-center gap-2 p-2 bg-dark-elevated rounded border border-dark-border text-sm';
                            div.innerHTML = '<input type="checkbox" name="existing_files[]" value="' + (f.id || '') + '" id="drawer-file-' + (f.id || '') + '" checked class="rounded"> <label for="drawer-file-' + (f.id || '') + '" class="text-gray-300">' + (f.nome_original || f.nome || '') + '</label>' + (f.caminho_arquivo ? ' <a href="' + f.caminho_arquivo + '" target="_blank" class="ml-auto text-blue-400"><i data-lucide="download" class="w-4 h-4"></i></a>' : '');
                            existingFilesEl.appendChild(div);
                        });
                        if (typeof lucide !== 'undefined' && lucide.createIcons) lucide.createIcons();
                    }
                }
                toggleDrawerAulaEditFields();
            }
            return;
        }
        if (panelName === 'add-produto-secao') {
            // Preencher secao_id quando adicionar produto
            const secaoIdInput = document.getElementById('drawer-add-produto-secao-id');
            if (secaoIdInput && opts.data && opts.data.secao_id) {
                secaoIdInput.value = opts.data.secao_id;
                console.log('Adicionar produto - secao_id definido como:', secaoIdInput.value);
            } else {
                console.error('Erro: secao_id não fornecido ou elemento não encontrado');
            }
            // Resetar formulário (mas manter secao_id)
            const form = document.getElementById('drawer-form-add-produto-secao');
            if (form) {
                const secaoIdValue = secaoIdInput ? secaoIdInput.value : '';
                form.reset();
                // Restaurar secao_id após reset
                if (secaoIdInput && secaoIdValue) {
                    secaoIdInput.value = secaoIdValue;
                }
                // Restaurar radio padrão
                const linkPadraoRadio = document.querySelector('#drawer-form-add-produto-secao input[name="tipo_link_produto"][value="padrao"]');
                if (linkPadraoRadio) linkPadraoRadio.checked = true;
                // Esconder campo de link personalizado
                const linkPersonalizadoWrap = document.getElementById('drawer-add-link-personalizado-wrap');
                if (linkPersonalizadoWrap) linkPersonalizadoWrap.classList.add('hidden');
                // Limpar campo de link personalizado
                const linkPersonalizadoInput = document.getElementById('drawer-add-link-personalizado-input');
                if (linkPersonalizadoInput) linkPersonalizadoInput.value = '';
            }
            // Configurar toggle de link personalizado (remover listeners antigos primeiro)
            const linkPersonalizadoRadio = document.querySelector('#drawer-form-add-produto-secao input[name="tipo_link_produto"][value="personalizado"]');
            const linkPadraoRadioAdd = document.querySelector('#drawer-form-add-produto-secao input[name="tipo_link_produto"][value="padrao"]');
            // Remover listeners antigos (clonar e substituir para remover)
            if (linkPersonalizadoRadio) {
                const newRadio = linkPersonalizadoRadio.cloneNode(true);
                linkPersonalizadoRadio.parentNode.replaceChild(newRadio, linkPersonalizadoRadio);
                newRadio.addEventListener('change', toggleLinkPersonalizadoAdd);
            }
            if (linkPadraoRadioAdd) {
                const newRadio = linkPadraoRadioAdd.cloneNode(true);
                linkPadraoRadioAdd.parentNode.replaceChild(newRadio, linkPadraoRadioAdd);
                newRadio.addEventListener('change', toggleLinkPersonalizadoAdd);
            }
            return;
        }
        if (panelName === 'produto-secao') {
            if (opts.mode === 'edit' && opts.data) {
                const form = document.getElementById('drawer-form-edit-produto-secao');
                if (form) {
                    // Não resetar o formulário completamente para não perder o campo de arquivo
                    // Apenas limpar o campo de arquivo manualmente
                    const fileInput = form.querySelector('input[type="file"]');
                    if (fileInput) {
                        fileInput.value = '';
                    }
                    // Resetar checkbox
                    const removeCheckbox = form.querySelector('input[name="remove_imagem_capa_produto"]');
                    if (removeCheckbox) {
                        removeCheckbox.checked = false;
                    }
                }
                // Debug: verificar valores
                console.log('Editar produto - secao_id:', opts.data.secao_id, 'produto_id:', opts.data.produto_id);
                const secaoIdEl = document.getElementById('drawer-produto-secao-id');
                const produtoIdEl = document.getElementById('drawer-produto-id');
                if (secaoIdEl) {
                    secaoIdEl.value = opts.data.secao_id || '';
                    console.log('secao_id definido como:', secaoIdEl.value);
                } else {
                    console.error('Elemento drawer-produto-secao-id não encontrado!');
                }
                if (produtoIdEl) {
                    produtoIdEl.value = opts.data.produto_id || '';
                    console.log('produto_id definido como:', produtoIdEl.value);
                } else {
                    console.error('Elemento drawer-produto-id não encontrado!');
                }
                document.getElementById('drawer-produto-nome').value = opts.data.produto_nome || '';
                // Configurar tipo de link
                const linkPersonalizado = opts.data.link_personalizado || '';
                const linkPadraoRadio = document.getElementById('drawer-edit-link-padrao');
                const linkPersonalizadoRadio = document.getElementById('drawer-edit-link-personalizado');
                const linkPersonalizadoInput = document.getElementById('drawer-edit-link-personalizado-input');
                const linkPersonalizadoWrap = document.getElementById('drawer-edit-link-personalizado-wrap');
                
                if (linkPersonalizado && linkPersonalizado.trim() !== '') {
                    // Tem link personalizado
                    if (linkPersonalizadoRadio) linkPersonalizadoRadio.checked = true;
                    if (linkPadraoRadio) linkPadraoRadio.checked = false;
                    if (linkPersonalizadoInput) linkPersonalizadoInput.value = linkPersonalizado;
                    if (linkPersonalizadoWrap) linkPersonalizadoWrap.classList.remove('hidden');
                } else {
                    // Usa link padrão
                    if (linkPadraoRadio) linkPadraoRadio.checked = true;
                    if (linkPersonalizadoRadio) linkPersonalizadoRadio.checked = false;
                    if (linkPersonalizadoInput) linkPersonalizadoInput.value = '';
                    if (linkPersonalizadoWrap) linkPersonalizadoWrap.classList.add('hidden');
                }
                const previewEl = document.getElementById('drawer-produto-imagem-preview');
                if (previewEl) {
                    if (opts.data.imagem_url) {
                        const imgPath = '/' + opts.data.imagem_url;
                        previewEl.innerHTML = '<img src="' + imgPath + '" alt="Preview" class="max-h-32 w-auto object-contain rounded border border-dark-border bg-dark-elevated p-1">';
                    } else {
                        previewEl.innerHTML = '<p class="text-gray-400 text-sm">Nenhuma imagem de capa definida</p>';
                    }
                }
            }
            return;
        }
        if (panelName === 'aulas-list') {
            drawerTitle.textContent = 'Aulas';
            const tituloEl = document.getElementById('drawer-aulas-list-modulo-titulo');
            const content = document.getElementById('drawer-aulas-list-content');
            const btnNova = document.getElementById('drawer-aulas-list-btn-nova');
            if (tituloEl) tituloEl.textContent = opts.modulo_titulo || '';
            if (btnNova) {
                btnNova.dataset.moduloId = opts.modulo_id || '';
                btnNova.dataset.moduloTitulo = opts.modulo_titulo || '';
            }
            if (content) {
                if (window.drawerAulasSortable) {
                    window.drawerAulasSortable.destroy();
                    window.drawerAulasSortable = null;
                }
                content.innerHTML = '';
                content.removeAttribute('data-modulo-id');
                const aulas = opts.aulas || [];
                const csrfToken = document.querySelector('input[name="csrf_token"]')?.value || '';
                const baseUrl = '/index?pagina=gerenciar_curso&produto_id=' + currentProductId;
                if (aulas.length === 0) {
                    content.innerHTML = '<p class="text-gray-500 text-sm py-2">Nenhuma aula neste módulo.</p>';
                } else {
                    content.setAttribute('data-modulo-id', String(opts.modulo_id || ''));
                    aulas.forEach(function(a) {
                        const row = document.createElement('div');
                        row.className = 'flex items-center gap-2 p-2 bg-dark-elevated rounded-lg border border-dark-border drawer-aula-row';
                        row.setAttribute('data-aula-id', String(a.id || ''));
                        const filesJson = JSON.stringify(a.files || []);
                        row.innerHTML = '<span class="drawer-aula-drag-handle cursor-grab active:cursor-grabbing text-gray-500 hover:text-gray-400 flex-shrink-0 p-1" title="Arrastar para reordenar"><i data-lucide="grip-vertical" class="w-4 h-4"></i></span>' +
                            '<span class="text-gray-200 text-sm font-medium truncate flex-1 min-w-0">' + (a.titulo || 'Aula') + '</span>' +
                            '<button type="button" class="edit-lesson-btn flex-shrink-0 p-1.5 rounded text-blue-400 hover:bg-blue-400/20" title="Editar aula"' +
                            ' data-aula-id="' + (a.id || '') + '" data-titulo="' + (a.titulo || '').replace(/"/g, '&quot;') + '" data-url-video="' + (a.url_video || '').replace(/"/g, '&quot;') + '"' +
                            ' data-descricao="' + (a.descricao || '').replace(/"/g, '&quot;') + '" data-release-days="' + (a.release_days || 0) + '" data-tipo-conteudo="' + (a.tipo_conteudo || 'video') + '"' +
                            ' data-download-link="' + (a.download_link || '').replace(/"/g, '&quot;') + '" data-termos-consentimento="' + (a.termos_consentimento || '').replace(/"/g, '&quot;') + '" data-files=\'' + filesJson.replace(/'/g, '&#39;') + '\'>' +
                            '<i data-lucide="edit" class="w-4 h-4"></i></button>' +
                            '<form action="' + baseUrl + '" method="post" onsubmit="return confirm(\'Excluir esta aula?\');" class="inline flex-shrink-0">' +
                            '<input type="hidden" name="csrf_token" value="' + csrfToken + '"><input type="hidden" name="aula_id" value="' + (a.id || '') + '">' +
                            '<button type="submit" name="deletar_aula" class="p-1.5 rounded text-red-400 hover:bg-red-400/20" title="Excluir"><i data-lucide="trash-2" class="w-4 h-4"></i></button></form>';
                        content.appendChild(row);
                    });
                    if (typeof lucide !== 'undefined' && lucide.createIcons) lucide.createIcons();
                    if (typeof Sortable !== 'undefined' && aulas.length > 0) {
                        window.drawerAulasSortable = new Sortable(content, {
                            animation: 150,
                            ghostClass: 'sortable-ghost',
                            handle: '.drawer-aula-drag-handle',
                            onEnd: function() {
                                const moduloId = content.getAttribute('data-modulo-id');
                                if (!moduloId) return;
                                const order = Array.from(content.querySelectorAll('.drawer-aula-row')).map(function(el) { return el.getAttribute('data-aula-id'); }).filter(Boolean);
                                if (order.length === 0) return;
                                const token = document.querySelector('input[name="csrf_token"]')?.value || '';
                                const payload = {
                                    modulo_id: parseInt(moduloId, 10),
                                    aulas_order: order.map(function(id) { return parseInt(id, 10) || id; }),
                                    produto_id: currentProductId,
                                    csrf_token: token
                                };
                                fetch('/api/api.php?action=reorder_aulas', {
                                    method: 'POST',
                                    credentials: 'same-origin',
                                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': token },
                                    body: JSON.stringify(payload)
                                }).then(function(r) { return r.json(); }).then(function(d) {
                                    if (!d.success) console.error('Reordenar aulas:', d.error);
                                }).catch(function(e) { console.error(e); });
                            }
                        });
                    }
                }
            }
            return;
        }
    }

    function closeDrawer() {
        drawerBackdrop.classList.add('hidden');
        drawer.classList.add('translate-x-full');
        document.body.style.overflow = '';
    }

    function toggleDrawerAulaAddFields() {
        const t = document.getElementById('drawer-add-tipo-conteudo').value;
        document.getElementById('drawer-add-video-wrap').classList.toggle('hidden', t !== 'video' && t !== 'mixed');
        document.getElementById('drawer-add-files-wrap').classList.toggle('hidden', t !== 'files' && t !== 'mixed');
        document.getElementById('drawer-add-download-wrap').classList.toggle('hidden', t !== 'download_protegido');
    }
    function toggleDrawerAulaEditFields() {
        const t = document.getElementById('drawer-edit-tipo-conteudo').value;
        document.getElementById('drawer-edit-video-wrap').classList.toggle('hidden', t !== 'video' && t !== 'mixed');
        document.getElementById('drawer-edit-files-wrap').classList.toggle('hidden', t !== 'files' && t !== 'mixed');
        document.getElementById('drawer-edit-download-wrap').classList.toggle('hidden', t !== 'download_protegido');
    }
    
    // Toggle campo de link personalizado (adicionar)
    function toggleLinkPersonalizadoAdd() {
        const linkPersonalizadoWrap = document.getElementById('drawer-add-link-personalizado-wrap');
        const radioPersonalizado = document.querySelector('#drawer-form-add-produto-secao input[name="tipo_link_produto"][value="personalizado"]');
        if (linkPersonalizadoWrap && radioPersonalizado) {
            if (radioPersonalizado.checked) {
                linkPersonalizadoWrap.classList.remove('hidden');
            } else {
                linkPersonalizadoWrap.classList.add('hidden');
                const input = document.getElementById('drawer-add-link-personalizado-input');
                if (input) input.value = '';
            }
        }
    }
    
    // Toggle campo de link personalizado (editar)
    function toggleLinkPersonalizadoEdit() {
        const linkPersonalizado = document.getElementById('drawer-edit-link-personalizado');
        const linkPersonalizadoWrap = document.getElementById('drawer-edit-link-personalizado-wrap');
        if (linkPersonalizado && linkPersonalizadoWrap) {
            if (linkPersonalizado.checked) {
                linkPersonalizadoWrap.classList.remove('hidden');
            } else {
                linkPersonalizadoWrap.classList.add('hidden');
                const input = document.getElementById('drawer-edit-link-personalizado-input');
                if (input) input.value = '';
            }
        }
    }
    
    // Event listeners para toggle de link personalizado
    const linkPersonalizadoAddRadio = document.getElementById('drawer-add-link-personalizado');
    const linkPersonalizadoPadraoAddRadio = document.getElementById('drawer-add-link-padrao');
    if (linkPersonalizadoAddRadio) {
        linkPersonalizadoAddRadio.addEventListener('change', toggleLinkPersonalizadoAdd);
    }
    if (linkPersonalizadoPadraoAddRadio) {
        linkPersonalizadoPadraoAddRadio.addEventListener('change', toggleLinkPersonalizadoAdd);
    }
    
    const linkPersonalizadoEditRadio = document.getElementById('drawer-edit-link-personalizado');
    const linkPersonalizadoPadraoEditRadio = document.getElementById('drawer-edit-link-padrao');
    if (linkPersonalizadoEditRadio) {
        linkPersonalizadoEditRadio.addEventListener('change', toggleLinkPersonalizadoEdit);
    }
    if (linkPersonalizadoPadraoEditRadio) {
        linkPersonalizadoPadraoEditRadio.addEventListener('change', toggleLinkPersonalizadoEdit);
    }

    if (drawerClose) drawerClose.addEventListener('click', closeDrawer);
    if (drawerBackdrop) drawerBackdrop.addEventListener('click', closeDrawer);
    document.querySelectorAll('.drawer-open-config, [data-drawer-panel]').forEach(btn => {
        btn.addEventListener('click', function() { openDrawer(this.getAttribute('data-drawer-panel') || 'config'); });
    });
    document.querySelectorAll('.drawer-open-add-secao').forEach(btn => {
        btn.addEventListener('click', () => openDrawer('secao', { mode: 'add' }));
    });
    document.querySelectorAll('.edit-secao-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            openDrawer('secao', { mode: 'edit', data: { secao_id: this.dataset.secaoId, titulo: this.dataset.titulo, tipo: this.dataset.tipo, tipo_capa: this.dataset.tipoCapa || 'vertical', conteudo: this.dataset.conteudo } });
        });
    });
    document.querySelectorAll('.drawer-open-add-module').forEach(btn => {
        btn.addEventListener('click', function() {
            openDrawer('modulo', { mode: 'add', secao_id: this.dataset.secaoId || '' });
        });
    });
    function openDrawerEditModule(btn) {
        openDrawer('modulo', { mode: 'edit', data: {
            modulo_id: btn.dataset.moduloId,
            titulo: btn.dataset.moduloTitulo,
            release_days: btn.dataset.releaseDays,
            is_paid_module: btn.dataset.isPaidModule,
            linked_product_id: btn.dataset.linkedProductId,
            secao_id: btn.dataset.secaoId
        } });
    }
    document.querySelectorAll('.edit-module-btn').forEach(btn => { btn.addEventListener('click', function() { openDrawerEditModule(this); }); });
    document.querySelectorAll('.drawer-open-edit-module').forEach(btn => { btn.addEventListener('click', function() { openDrawerEditModule(this); }); });
    function openDrawerEditProdutoSecao(btn) {
        openDrawer('produto-secao', { mode: 'edit', data: {
            produto_id: btn.dataset.produtoId,
            secao_id: btn.dataset.secaoId,
            produto_nome: btn.dataset.produtoNome,
            imagem_url: btn.dataset.imagemUrl || '',
            link_personalizado: btn.dataset.linkPersonalizado || ''
        } });
    }
    document.querySelectorAll('.drawer-open-edit-produto-secao').forEach(btn => { btn.addEventListener('click', function() { openDrawerEditProdutoSecao(this); }); });
    
    // Handler para botão de adicionar produto
    document.querySelectorAll('.drawer-open-add-produto-secao').forEach(btn => {
        btn.addEventListener('click', function() {
            openDrawer('add-produto-secao', { data: { secao_id: this.dataset.secaoId } });
        });
    });
    document.querySelectorAll('.add-lesson-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            openDrawer('aula', { mode: 'add', modulo_id: this.dataset.moduloId, modulo_titulo: this.dataset.moduloTitulo });
        });
    });
    function openDrawerEditAula(btn) {
        let files = [];
        try { if (btn.dataset.files) files = JSON.parse(btn.dataset.files); } catch (e) {}
        const data = { aula_id: btn.dataset.aulaId, titulo: btn.dataset.titulo, url_video: btn.dataset.urlVideo, release_days: btn.dataset.releaseDays, descricao: btn.dataset.descricao, tipo_conteudo: btn.dataset.tipoConteudo, download_link: btn.dataset.downloadLink || '', termos_consentimento: btn.dataset.termosConsentimento || '', files };
        openDrawer('aula', { mode: 'edit', data });
    }
    document.querySelectorAll('.edit-lesson-btn').forEach(btn => {
        btn.addEventListener('click', function() { openDrawerEditAula(this); });
    });
    drawer.addEventListener('click', function(e) {
        const btn = e.target.closest('.edit-lesson-btn');
        if (btn && document.getElementById('drawer-aulas-list-content') && document.getElementById('drawer-aulas-list-content').contains(btn)) {
            e.preventDefault();
            openDrawerEditAula(btn);
        }
    });
    document.querySelectorAll('.drawer-open-aulas-list').forEach(btn => {
        btn.addEventListener('click', function() {
            let aulas = [];
            try { if (this.dataset.aulas) aulas = JSON.parse(this.dataset.aulas); } catch (e) {}
            openDrawer('aulas-list', { modulo_id: this.dataset.moduloId, modulo_titulo: this.dataset.moduloTitulo || '', aulas });
        });
    });
    document.getElementById('drawer-aulas-list-btn-nova')?.addEventListener('click', function() {
        openDrawer('aula', { mode: 'add', modulo_id: this.dataset.moduloId, modulo_titulo: this.dataset.moduloTitulo || '' });
    });
    const drawerAddTipo = document.getElementById('drawer-add-tipo-conteudo');
    if (drawerAddTipo) drawerAddTipo.addEventListener('change', toggleDrawerAulaAddFields);
    const drawerEditTipo = document.getElementById('drawer-edit-tipo-conteudo');
    if (drawerEditTipo) drawerEditTipo.addEventListener('change', toggleDrawerAulaEditFields);

    // --- Lógica genérica para Modais ---
    function openModal(modal) {
        modal.classList.remove('hidden');
        setTimeout(() => {
            const content = modal.querySelector('.transform');
            if (content) content.classList.remove('opacity-0', 'scale-95');
        }, 10);
    }

    function closeModal(modal) {
        const content = modal.querySelector('.transform');
        if (content) content.classList.add('opacity-0', 'scale-95');
        setTimeout(() => {
            modal.classList.add('hidden');
            const form = modal.querySelector('form');
            if (form) form.reset();
        }, 200);
    }

    document.querySelectorAll('.modal-cancel-btn').forEach(btn => {
        btn.addEventListener('click', () => closeModal(btn.closest('.fixed')));
    });

    document.querySelectorAll('.fixed[id$="-modal"]').forEach(modal => {
        modal.addEventListener('click', (e) => {
            if (e.target === modal) closeModal(modal);
        });
    });

    // --- Lógica para Modal de Adicionar Aula ---
    const addLessonModal = document.getElementById('add-lesson-modal');
    const addTipoConteudoSelect = document.getElementById('add_tipo_conteudo');
    const addVideoUrlContainer = document.getElementById('add-video-url-container');
    const addAulaFilesContainer = document.getElementById('add-files-upload-container');
    const addDownloadProtegidoContainer = document.getElementById('add-download-protegido-container');
    const addUrlVideoInput = document.getElementById('add_url_video');
    const addAulaFilesInput = document.getElementById('add_aula_files');
    const addDownloadLinkInput = document.getElementById('add_download_link');
    const addTermosConsentimentoInput = document.getElementById('add_termos_consentimento');

    function toggleAddLessonFields() {
        const selectedType = addTipoConteudoSelect.value;
        addUrlVideoInput.required = false;
        addAulaFilesInput.required = false;
        addDownloadLinkInput.required = false;
        addTermosConsentimentoInput.required = false;

        addVideoUrlContainer.style.display = 'none';
        addAulaFilesContainer.style.display = 'none';
        addDownloadProtegidoContainer.style.display = 'none';

        if (selectedType === 'text') {
            // Tipo 'text' - ocultar vídeo e arquivos, não requer nenhum
            // Campos já estão ocultos e não obrigatórios
        } else if (selectedType === 'video' || selectedType === 'mixed') {
            addVideoUrlContainer.style.display = 'block';
            addUrlVideoInput.required = true;
        }
        if (selectedType === 'files' || selectedType === 'mixed') {
            addAulaFilesContainer.style.display = 'block';
            addAulaFilesInput.required = true; // Always require new files for 'add' if type is files/mixed
        }
        if (selectedType === 'download_protegido') {
            addDownloadProtegidoContainer.style.display = 'block';
            addDownloadLinkInput.required = true;
            addTermosConsentimentoInput.required = true;
        }
    }

    addTipoConteudoSelect.addEventListener('change', toggleAddLessonFields);


    // add-lesson-btn: abre o drawer (painel aula, modo add); ver listener acima na seção Drawer

    // --- Lógica para Modal de Editar Aula ---
    const editLessonModal = document.getElementById('edit-lesson-modal');
    const editTipoConteudoSelect = document.getElementById('edit_tipo_conteudo');
    const editVideoUrlContainer = document.getElementById('edit-video-url-container');
    const editExistingFilesContainer = document.getElementById('edit-existing-files-container');
    const editNewFilesUploadContainer = document.getElementById('edit-new-files-upload-container');
    const editDownloadProtegidoContainer = document.getElementById('edit-download-protegido-container');
    const editUrlVideoInput = document.getElementById('edit_url_video');
    const existingFilesList = document.getElementById('existing-files-list');
    const editAulaFilesInput = document.getElementById('edit_aula_files');
    const editDownloadLinkInput = document.getElementById('edit_download_link');
    const editTermosConsentimentoInput = document.getElementById('edit_termos_consentimento');


    function toggleEditLessonFields() {
        const selectedType = editTipoConteudoSelect.value;
        editUrlVideoInput.required = false;
        editAulaFilesInput.required = false; // Reset required for new uploads
        editDownloadLinkInput.required = false;
        editTermosConsentimentoInput.required = false;

        editVideoUrlContainer.style.display = 'none';
        editExistingFilesContainer.style.display = 'none';
        editNewFilesUploadContainer.style.display = 'none';
        editDownloadProtegidoContainer.style.display = 'none';

        if (selectedType === 'text') {
            // Tipo 'text' - ocultar vídeo e arquivos, não requer nenhum
            // Campos já estão ocultos e não obrigatórios
        } else if (selectedType === 'video' || selectedType === 'mixed') {
            editVideoUrlContainer.style.display = 'block';
            editUrlVideoInput.required = true;
        }
        if (selectedType === 'files' || selectedType === 'mixed') {
            editExistingFilesContainer.style.display = 'block';
            editNewFilesUploadContainer.style.display = 'block';
            
            // Check if any existing file checkbox is currently checked
            const anyExistingFileSelectedToKeep = existingFilesList.querySelectorAll('input[name="existing_files[]"]:checked').length > 0;

            if (!anyExistingFileSelectedToKeep) { // If no existing files are selected to be kept, then new uploads become required
                editAulaFilesInput.required = true;
            }
        }
        if (selectedType === 'download_protegido') {
            editDownloadProtegidoContainer.style.display = 'block';
            editDownloadLinkInput.required = true;
            editTermosConsentimentoInput.required = true;
        }
    }

    editTipoConteudoSelect.addEventListener('change', toggleEditLessonFields);
    // Also, re-evaluate required status when a checkbox for existing files is clicked
    existingFilesList.addEventListener('change', (e) => {
        if (e.target.type === 'checkbox' && (editTipoConteudoSelect.value === 'files' || editTipoConteudoSelect.value === 'mixed')) {
            toggleEditLessonFields();
        }
    });


    // edit-lesson-btn: abre o drawer (painel aula, modo edit); ver listener acima na seção Drawer

    // --- Lógica para Modal de Editar Módulo ---
    const editModuleModal = document.getElementById('edit-module-modal');
    const imgPreview = document.getElementById('modal-imagem-preview');
    const removeImageCheckbox = document.getElementById('remove_imagem_capa_modulo');
    // edit-module-btn: abre o drawer (painel modulo, modo edit); ver openDrawerEditModule na seção Drawer

    // --- Reordenação de Seções na preview ---
    const secaoSortable = document.getElementById('preview-secoes-sortable');
    if (secaoSortable && typeof Sortable !== 'undefined') {
        new Sortable(secaoSortable, {
            animation: 150,
            ghostClass: 'sortable-ghost',
            handle: '.secao-header .cursor-grab',
            onEnd: function() {
                const order = Array.from(secaoSortable.querySelectorAll('.secao-preview')).map(el => el.getAttribute('data-secao-id')).filter(Boolean);
                const secaoIds = order.map(id => parseInt(id, 10)).filter(n => n > 0);
                if (secaoIds.length === 0) return;
                const csrfToken = document.querySelector('input[name="csrf_token"]')?.value || '';
                fetch('/api/api.php?action=reorder_secoes', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify({ secoes_order: secaoIds, produto_id: currentProductId, csrf_token: csrfToken })
                }).then(r => r.json()).then(d => { if (!d.success) console.error(d.error); }).catch(e => console.error(e));
            }
        });
    }

    // --- Reordenação de Módulos na preview (por seção) ---
    document.querySelectorAll('.sortable-modulos').forEach(grid => {
        if (typeof Sortable === 'undefined') return;
        const secaoId = grid.getAttribute('data-secao-id');
        new Sortable(grid, {
            animation: 150,
            ghostClass: 'sortable-ghost',
            onEnd: function() {
                const order = Array.from(grid.querySelectorAll('[data-modulo-id]')).map(el => el.getAttribute('data-modulo-id'));
                if (order.length === 0) return;
                const csrfToken = document.querySelector('input[name="csrf_token"]')?.value || '';
                const body = { modulos_order: order, produto_id: currentProductId, csrf_token: csrfToken };
                if (secaoId && secaoId !== '0') body.secao_id = parseInt(secaoId, 10);
                else body.secao_id = null;
                fetch('/api/api.php?action=reorder_modulos', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                    body: JSON.stringify(body)
                }).then(r => r.json()).then(d => { if (!d.success) console.error(d.error); }).catch(e => console.error(e));
            }
        });
    });

    // --- Lógica de Reordenação (Drag-and-Drop) de Aulas ---
    document.querySelectorAll('.sortable-aulas').forEach(ul => {
        new Sortable(ul, {
            animation: 150,
            ghostClass: 'sortable-ghost',
            handle: '.cursor-grab', // A alça para arrastar será o ícone de 'grip-vertical'
            onEnd: function (evt) {
                const moduloId = evt.from.dataset.moduloId;
                const newOrder = Array.from(evt.from.children).map(item => item.dataset.aulaId);
                
                // Enviar a nova ordem para a API
                const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || document.querySelector('input[name="csrf_token"]')?.value || '';
                fetch('/api/api.php?action=reorder_aulas', {
                    method: 'POST',
                    headers: { 
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrfToken
                    },
                    body: JSON.stringify({
                        modulo_id: moduloId,
                        aulas_order: newOrder,
                        produto_id: currentProductId, // Passa o ID do produto para validação
                        csrf_token: csrfToken
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Opcional: Feedback visual de sucesso
                        // alert('Ordem das aulas atualizada!');
                        console.log('Ordem das aulas atualizada com sucesso!');
                    } else {
                        // alert('Erro ao reordenar aulas: ' + (data.error || 'Erro desconhecido.'));
                        console.error('Erro ao reordenar aulas:', data.error);
                        // Opcional: Recarregar a página para reverter a ordem visual para a do banco
                        // window.location.reload(); 
                    }
                })
                .catch(error => {
                    console.error('Erro de rede ao reordenar aulas:', error);
                    // alert('Erro de comunicação com o servidor ao reordenar aulas.');
                    // window.location.reload();
                });
            }
        });
    });

    // Chamada inicial para toggleAddLessonFields para garantir que o formulário "Adicionar Aula" esteja correto ao carregar
    toggleAddLessonFields();

    // --- Modal Editar Categoria Comunidade ---
    const editCatComunidadeModal = document.getElementById('edit-categoria-comunidade-modal');
    if (editCatComunidadeModal) {
        document.querySelectorAll('.edit-cat-comunidade-btn').forEach(btn => {
            btn.addEventListener('click', function() {
                document.getElementById('modal-cat-comunidade-id').value = this.dataset.catId || '';
                document.getElementById('modal-cat-comunidade-nome').value = this.dataset.nome || '';
                document.getElementById('modal-cat-comunidade-public').checked = parseInt(this.dataset.public, 10) === 1;
                openModal(editCatComunidadeModal);
            });
        });
    }
});
</script></script>
