<?php
/**
 * ✅ ARQUIVO PRINCIPAL - ESTE É O ARQUIVO USADO PELO SISTEMA ✅
 * 
 * Este é o arquivo que realmente processa a configuração de produtos.
 * É carregado pelo index.php quando $pagina = 'produto_config'
 * 
 * Caminho: views/produto_config.php
 * 
 * ⚠️ NÃO confundir com produto_config.php na raiz (que não é usado)
 */

// Página unificada de configuração de produtos
$mensagem = '';
$produto = null;
$checkout_config = [];
$order_bumps = [];
$upload_dir = 'uploads/';

// Garante que o diretório de uploads exista
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

// Obter o ID do usuário logado
$usuario_id = $_SESSION['id'] ?? 0;
if ($usuario_id === 0) {
    header("location: /login");
    exit;
}

// Validar e buscar o produto
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: /index?pagina=produtos");
    exit;
}

$id_produto = $_GET['id'];

try {
    // Buscar o produto e verificar se ele pertence ao usuário logado
    $stmt = $pdo->prepare("SELECT * FROM produtos WHERE id = ? AND usuario_id = ?");
    $stmt->execute([$id_produto, $usuario_id]);
    $produto = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$produto) {
        $_SESSION['flash_message'] = "<div class='bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded relative mb-4' role='alert'>Produto não encontrado ou você não tem permissão para acessá-lo.</div>";
        header("Location: /index?pagina=produtos");
        exit;
    }

    $current_gateway = $produto['gateway'] ?? 'mercadopago';
    $checkout_config = json_decode($produto['checkout_config'] ?? '{}', true);

    // Busca os order bumps existentes
    $stmt_ob = $pdo->prepare("SELECT * FROM order_bumps WHERE main_product_id = ? ORDER BY ordem ASC");
    $stmt_ob->execute([$id_produto]);
    $order_bumps = $stmt_ob->fetchAll(PDO::FETCH_ASSOC);

    // Lista de produtos para order bumps (mesmo gateway)
    $stmt_todos_produtos = $pdo->prepare("SELECT id, nome FROM produtos WHERE id != ? AND usuario_id = ? AND gateway = ?");
    $stmt_todos_produtos->execute([$id_produto, $usuario_id, $current_gateway]);
    $lista_produtos_orderbump = $stmt_todos_produtos->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    die("Erro ao buscar dados: " . $e->getMessage());
}

// Função de upload múltiplo (segura)
function handle_multiple_uploads($file_key, $prefix, $product_id)
{
    require_once __DIR__ . '/../helpers/security_helper.php';

    $uploaded_paths = [];
    if (isset($_FILES[$file_key]) && is_array($_FILES[$file_key]['name'])) {
        $file_count = count($_FILES[$file_key]['name']);
        for ($i = 0; $i < $file_count; $i++) {
            if ($_FILES[$file_key]['error'][$i] == UPLOAD_ERR_OK) {
                // Cria array de arquivo compatível com validate_image_upload
                $file_array = [
                    'name' => $_FILES[$file_key]['name'][$i],
                    'type' => $_FILES[$file_key]['type'][$i],
                    'tmp_name' => $_FILES[$file_key]['tmp_name'][$i],
                    'error' => $_FILES[$file_key]['error'][$i],
                    'size' => $_FILES[$file_key]['size'][$i]
                ];

                // Apenas JPEG ou PNG para banners de produtos
                $upload_result = validate_image_upload($file_array, 'uploads/', $prefix . '_' . $product_id, 5, true);
                if ($upload_result['success']) {
                    $uploaded_paths[] = $upload_result['file_path'];
                }
            }
        }
    }
    return $uploaded_paths;
}

// Processar ações de ofertas (antes do salvamento principal)
require_once __DIR__ . '/../helpers/security_helper.php';

// Debug: verificar se está recebendo os dados
if (isset($_POST['criar_oferta']) || isset($_POST['editar_oferta']) || isset($_POST['excluir_oferta']) || isset($_POST['toggle_oferta'])) {
    error_log("DEBUG: Processando ação de oferta. POST: " . print_r($_POST, true));
    // Verifica CSRF
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $mensagem = "<div class='bg-red-900/20 border-l-4 border-red-500 text-red-300 p-4 rounded-md shadow-sm mb-6' role='alert'>Token CSRF inválido ou ausente.</div>";
    } else {
        $pdo->beginTransaction();
        try {
            // Criar nova oferta
            if (isset($_POST['criar_oferta'])) {
                $oferta_nome = trim($_POST['oferta_nome'] ?? '');
                $oferta_preco = floatval(str_replace(',', '.', $_POST['oferta_preco'] ?? 0));

                // Validações
                if (strlen($oferta_nome) < 3) {
                    throw new Exception("O nome da oferta deve ter no mínimo 3 caracteres.");
                }
                if ($oferta_preco <= 0) {
                    throw new Exception("O preço da oferta deve ser maior que zero.");
                }

                // Verificar se a tabela existe
                try {
                    $stmt_test = $pdo->query("SELECT 1 FROM produto_ofertas LIMIT 1");
                } catch (PDOException $e) {
                    if (strpos($e->getMessage(), "doesn't exist") !== false || strpos($e->getMessage(), "não existe") !== false) {
                        throw new Exception("A tabela produto_ofertas não existe. Execute o arquivo SQL de migração: SQL Atualizações/Nova pasta/criar_tabela_produto_ofertas.sql");
                    }
                    throw $e;
                }

                // Gerar checkout_hash único
                do {
                    $checkout_hash = bin2hex(random_bytes(16));
                    $stmt_check = $pdo->prepare("SELECT id FROM produto_ofertas WHERE checkout_hash = ?");
                    $stmt_check->execute([$checkout_hash]);
                } while ($stmt_check->rowCount() > 0);

                // Verificar também na tabela produtos
                do {
                    $stmt_check_prod = $pdo->prepare("SELECT id FROM produtos WHERE checkout_hash = ?");
                    $stmt_check_prod->execute([$checkout_hash]);
                    if ($stmt_check_prod->rowCount() > 0) {
                        $checkout_hash = bin2hex(random_bytes(16));
                    }
                } while ($stmt_check_prod->rowCount() > 0);

                // Inserir oferta
                $stmt_insert = $pdo->prepare("INSERT INTO produto_ofertas (produto_id, nome, preco, checkout_hash, is_active) VALUES (?, ?, ?, ?, 1)");
                if (!$stmt_insert->execute([$id_produto, $oferta_nome, $oferta_preco, $checkout_hash])) {
                    $errorInfo = $stmt_insert->errorInfo();
                    throw new Exception("Erro ao inserir oferta: " . ($errorInfo[2] ?? "Erro desconhecido"));
                }

                $mensagem = "<div class='px-4 py-3 rounded relative mb-4' role='alert' style='background-color: color-mix(in srgb, var(--accent-primary) 20%, transparent); border: 1px solid var(--accent-primary); color: var(--accent-primary);'>Oferta criada com sucesso!</div>";
            }

            // Editar oferta existente
            if (isset($_POST['editar_oferta'])) {
                $oferta_id = intval($_POST['editar_oferta']);
                $oferta_nome = trim($_POST['oferta_nome'] ?? '');
                $oferta_preco = floatval(str_replace(',', '.', $_POST['oferta_preco'] ?? 0));

                // Validações
                if (strlen($oferta_nome) < 3) {
                    throw new Exception("O nome da oferta deve ter no mínimo 3 caracteres.");
                }
                if ($oferta_preco <= 0) {
                    throw new Exception("O preço da oferta deve ser maior que zero.");
                }

                // Verificar se a oferta pertence ao produto e usuário
                $stmt_check = $pdo->prepare("SELECT po.id FROM produto_ofertas po JOIN produtos p ON po.produto_id = p.id WHERE po.id = ? AND po.produto_id = ? AND p.usuario_id = ?");
                $stmt_check->execute([$oferta_id, $id_produto, $usuario_id]);
                if ($stmt_check->rowCount() === 0) {
                    throw new Exception("Oferta não encontrada ou você não tem permissão para editá-la.");
                }

                // Atualizar oferta
                $stmt_update = $pdo->prepare("UPDATE produto_ofertas SET nome = ?, preco = ? WHERE id = ? AND produto_id = ?");
                $stmt_update->execute([$oferta_nome, $oferta_preco, $oferta_id, $id_produto]);

                $mensagem = "<div class='px-4 py-3 rounded relative mb-4' role='alert' style='background-color: color-mix(in srgb, var(--accent-primary) 20%, transparent); border: 1px solid var(--accent-primary); color: var(--accent-primary);'>Oferta atualizada com sucesso!</div>";
            }

            // Excluir oferta
            if (isset($_POST['excluir_oferta'])) {
                $oferta_id = intval($_POST['excluir_oferta']);

                // Verificar se a oferta pertence ao produto e usuário
                $stmt_check = $pdo->prepare("SELECT po.id FROM produto_ofertas po JOIN produtos p ON po.produto_id = p.id WHERE po.id = ? AND po.produto_id = ? AND p.usuario_id = ?");
                $stmt_check->execute([$oferta_id, $id_produto, $usuario_id]);
                if ($stmt_check->rowCount() === 0) {
                    throw new Exception("Oferta não encontrada ou você não tem permissão para excluí-la.");
                }

                // Excluir oferta
                $stmt_delete = $pdo->prepare("DELETE FROM produto_ofertas WHERE id = ? AND produto_id = ?");
                $stmt_delete->execute([$oferta_id, $id_produto]);

                $mensagem = "<div class='px-4 py-3 rounded relative mb-4' role='alert' style='background-color: color-mix(in srgb, var(--accent-primary) 20%, transparent); border: 1px solid var(--accent-primary); color: var(--accent-primary);'>Oferta excluída com sucesso!</div>";
            }

            // Toggle ativar/desativar oferta
            if (isset($_POST['toggle_oferta'])) {
                $oferta_id = intval($_POST['toggle_oferta']);

                // Verificar se a oferta pertence ao produto e usuário
                $stmt_check = $pdo->prepare("SELECT po.id, po.is_active FROM produto_ofertas po JOIN produtos p ON po.produto_id = p.id WHERE po.id = ? AND po.produto_id = ? AND p.usuario_id = ?");
                $stmt_check->execute([$oferta_id, $id_produto, $usuario_id]);
                $oferta_data = $stmt_check->fetch(PDO::FETCH_ASSOC);

                if (!$oferta_data) {
                    throw new Exception("Oferta não encontrada ou você não tem permissão para alterá-la.");
                }

                // Alternar status
                $novo_status = $oferta_data['is_active'] ? 0 : 1;
                $stmt_toggle = $pdo->prepare("UPDATE produto_ofertas SET is_active = ? WHERE id = ? AND produto_id = ?");
                $stmt_toggle->execute([$novo_status, $oferta_id, $id_produto]);

                $mensagem = "<div class='px-4 py-3 rounded relative mb-4' role='alert' style='background-color: color-mix(in srgb, var(--accent-primary) 20%, transparent); border: 1px solid var(--accent-primary); color: var(--accent-primary);'>Oferta " . ($novo_status ? 'ativada' : 'desativada') . " com sucesso!</div>";
            }

            $pdo->commit();

            // Redirecionar para evitar reenvio do formulário
            $redirect_url = "/index?pagina=produto_config&id=" . $id_produto . "&aba=geral";
            if (!empty($mensagem)) {
                $_SESSION['flash_message'] = $mensagem;
            }
            header("Location: " . $redirect_url);
            exit;
        } catch (Exception $e) {
            $pdo->rollBack();
            $mensagem = "<div class='bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded relative mb-4' role='alert'>Erro: " . htmlspecialchars($e->getMessage()) . "</div>";
            // Log do erro para debug
            error_log("Erro ao processar oferta: " . $e->getMessage());
        }
    }
}

// Processar formulário de salvamento
if (isset($_POST['salvar_produto_config'])) {
    // Verifica CSRF
    if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
        $mensagem = "<div class='bg-red-900/20 border-l-4 border-red-500 text-red-300 p-4 rounded-md shadow-sm mb-6' role='alert'>Token CSRF inválido ou ausente.</div>";
    } else {
        $pdo->beginTransaction();
        try {
            // ========== ABA GERAL ==========
            $nome = $_POST['nome'] ?? $produto['nome'];
            $descricao = $_POST['descricao'] ?? $produto['descricao'];
            $preco = $_POST['preco'] ?? $produto['preco'];
            $preco_anterior = !empty($_POST['preco_anterior']) ? floatval(str_replace(',', '.', $_POST['preco_anterior'])) : null;
            $gateway = $_POST['gateway'] ?? $current_gateway;
            $tipo_entrega = $_POST['tipo_entrega'] ?? $produto['tipo_entrega'];

            // Upload de foto (seguro)
            $foto_atual = $_POST['foto_atual'] ?? $produto['foto'];
            $nome_foto = $foto_atual;
            if (isset($_FILES['foto']) && $_FILES['foto']['error'] == 0) {
                require_once __DIR__ . '/../helpers/security_helper.php';
                // Apenas JPEG ou PNG para imagens de produtos
                $upload_result = validate_image_upload($_FILES['foto'], $upload_dir, 'produto_img', 5, true);
                if ($upload_result['success']) {
                    // Remove foto antiga se existir
                    if ($foto_atual && file_exists($upload_dir . $foto_atual)) {
                        @unlink($upload_dir . $foto_atual);
                    }
                    // Extrai apenas o nome do arquivo do caminho relativo
                    $nome_foto = basename($upload_result['file_path']);
                } else {
                    // Em caso de erro, mantém a foto atual
                    $nome_foto = $foto_atual;
                }
            }

            // Lógica de entrega
            $conteudo_entrega_atual = $_POST['conteudo_entrega_atual'] ?? $produto['conteudo_entrega'];
            $conteudo_entrega = $conteudo_entrega_atual;
            if ($tipo_entrega === 'link') {
                $conteudo_entrega = $_POST['conteudo_entrega_link'] ?? null;
            } elseif ($tipo_entrega === 'area_membros') {
                $conteudo_entrega = null;
            } elseif ($tipo_entrega === 'produto_fisico') {
                $conteudo_entrega = null;
            } elseif ($tipo_entrega === 'email_pdf') {
                if (isset($_FILES['conteudo_entrega_pdf']) && $_FILES['conteudo_entrega_pdf']['error'] === UPLOAD_ERR_OK) {
                    require_once __DIR__ . '/../helpers/security_helper.php';
                    $upload_result = validate_pdf_upload($_FILES['conteudo_entrega_pdf'], $upload_dir, 'produto_pdf', 10);
                    if ($upload_result['success']) {
                        // Remove PDF antigo se existir
                        if ($conteudo_entrega_atual && file_exists($upload_dir . $conteudo_entrega_atual)) {
                            @unlink($upload_dir . $conteudo_entrega_atual);
                        }
                        // Extrai apenas o nome do arquivo do caminho relativo
                        $conteudo_entrega = basename($upload_result['file_path']);
                    } else {
                        // Em caso de erro, mantém o PDF atual
                        $conteudo_entrega = $conteudo_entrega_atual;
                    }
                }
            }

            // Atualizar dados básicos do produto
            $tipo_pagamento = $_POST['tipo_pagamento'] ?? $produto['tipo_pagamento'] ?? 'unico';
            $intervalo_recorrencia = $_POST['intervalo_recorrencia'] ?? $produto['intervalo_recorrencia'] ?? 'mensal';

            // Verificar se as colunas existem antes de tentar atualizar
            $stmt_check = $pdo->query("SHOW COLUMNS FROM produtos LIKE 'tipo_pagamento'");
            $has_tipo_pagamento = $stmt_check->rowCount() > 0;

            if ($has_tipo_pagamento) {
                $stmt_update = $pdo->prepare("UPDATE produtos SET nome = ?, descricao = ?, preco = ?, foto = ?, tipo_entrega = ?, conteudo_entrega = ?, gateway = ?, preco_anterior = ?, tipo_pagamento = ?, intervalo_recorrencia = ? WHERE id = ? AND usuario_id = ?");
                $stmt_update->execute([$nome, $descricao, $preco, $nome_foto, $tipo_entrega, $conteudo_entrega, $gateway, $preco_anterior, $tipo_pagamento, $intervalo_recorrencia, $id_produto, $usuario_id]);
            } else {
                $stmt_update = $pdo->prepare("UPDATE produtos SET nome = ?, descricao = ?, preco = ?, foto = ?, tipo_entrega = ?, conteudo_entrega = ?, gateway = ?, preco_anterior = ? WHERE id = ? AND usuario_id = ?");
                $stmt_update->execute([$nome, $descricao, $preco, $nome_foto, $tipo_entrega, $conteudo_entrega, $gateway, $preco_anterior, $id_produto, $usuario_id]);
            }

            // Se o gateway mudou, atualizar current_gateway
            if ($gateway !== $current_gateway) {
                $current_gateway = $gateway;
            }

            // Se o produto for do tipo 'area_membros', atualizar também o título do curso associado
            if ($tipo_entrega === 'area_membros') {
                $stmt_update_curso = $pdo->prepare("UPDATE cursos SET titulo = ?, descricao = ? WHERE produto_id = ?");
                $stmt_update_curso->execute([$nome, $descricao, $id_produto]);
            }

            // ========== CHECKOUT CONFIG ==========
            // Busca configuração existente
            $stmt_get_config = $pdo->prepare("SELECT checkout_config FROM produtos WHERE id = ? AND usuario_id = ?");
            $stmt_get_config->execute([$id_produto, $usuario_id]);
            $current_config_json = $stmt_get_config->fetchColumn();
            $config_array = json_decode($current_config_json ?: '{}', true);

            // Upload de banners
            $current_banners = $config_array['banners'] ?? [];
            if (empty($current_banners) && !empty($config_array['bannerUrl'])) {
                $current_banners = [$config_array['bannerUrl']];
            }
            $banners_to_remove = $_POST['remove_banners'] ?? [];
            $final_banners_list = [];
            foreach ($current_banners as $banner_path) {
                if (!in_array($banner_path, $banners_to_remove)) {
                    $final_banners_list[] = $banner_path;
                } else {
                    if (file_exists($banner_path))
                        unlink($banner_path);
                }
            }
            $newly_uploaded_banners = handle_multiple_uploads('add_banner_files', 'banner', $id_produto);
            $config_array['banners'] = array_merge($final_banners_list, $newly_uploaded_banners);

            $current_side_banners = $config_array['sideBanners'] ?? [];
            if (empty($current_side_banners) && !empty($config_array['sideBannerUrl'])) {
                $current_side_banners = [$config_array['sideBannerUrl']];
            }
            $side_banners_to_remove = $_POST['remove_side_banners'] ?? [];
            $final_side_banners_list = [];
            foreach ($current_side_banners as $banner_path) {
                if (!in_array($banner_path, $side_banners_to_remove)) {
                    $final_side_banners_list[] = $banner_path;
                } else {
                    if (file_exists($banner_path))
                        unlink($banner_path);
                }
            }
            $newly_uploaded_side_banners = handle_multiple_uploads('add_side_banner_files', 'sidebanner', $id_produto);
            $config_array['sideBanners'] = array_merge($final_side_banners_list, $newly_uploaded_side_banners);

            unset($config_array['bannerUrl']);
            unset($config_array['sideBannerUrl']);

            // Configurações gerais - só atualiza se os campos estiverem presentes no POST
            if (isset($_POST['backgroundColor'])) {
                $config_array['backgroundColor'] = $_POST['backgroundColor'];
            }
            if (isset($_POST['accentColor'])) {
                $config_array['accentColor'] = $_POST['accentColor'];
            }
            if (isset($_POST['redirectUrl'])) {
                $config_array['redirectUrl'] = $_POST['redirectUrl'];
            }
            if (isset($_POST['youtubeUrl'])) {
                $config_array['youtubeUrl'] = $_POST['youtubeUrl'];
            }
            unset($config_array['facebookPixelId']);

            // Tracking - só atualiza se houver campos de tracking no POST (verifica se algum campo foi realmente enviado)
            // Verifica se algum campo de tracking foi enviado no POST (não apenas se existe, mas se foi realmente enviado)
            $has_tracking_fields = (isset($_POST['facebookPixelId']) && $_POST['facebookPixelId'] !== '') ||
                (isset($_POST['facebookApiToken']) && $_POST['facebookApiToken'] !== '') ||
                (isset($_POST['googleAnalyticsId']) && $_POST['googleAnalyticsId'] !== '') ||
                (isset($_POST['googleAdsId']) && $_POST['googleAdsId'] !== '') ||
                (isset($_POST['customScript']) && $_POST['customScript'] !== '') ||
                isset($_POST['fb_event_purchase']) || isset($_POST['fb_event_pending']) ||
                isset($_POST['fb_event_refund']) || isset($_POST['fb_event_chargeback']) ||
                isset($_POST['fb_event_rejected']) || isset($_POST['fb_event_initiate_checkout']) ||
                isset($_POST['gg_event_purchase']) || isset($_POST['gg_event_pending']) ||
                isset($_POST['gg_event_refund']) || isset($_POST['gg_event_chargeback']) ||
                isset($_POST['gg_event_rejected']) || isset($_POST['gg_event_initiate_checkout']);

            if ($has_tracking_fields) {
                $existing_tracking = $config_array['tracking'] ?? [];
                // Só atualiza campos de texto se tiverem valores não vazios, caso contrário preserva o existente
                $config_array['tracking'] = [
                    'facebookPixelId' => (isset($_POST['facebookPixelId']) && $_POST['facebookPixelId'] !== '') ? $_POST['facebookPixelId'] : ($existing_tracking['facebookPixelId'] ?? ''),
                    'facebookApiToken' => (isset($_POST['facebookApiToken']) && $_POST['facebookApiToken'] !== '') ? $_POST['facebookApiToken'] : ($existing_tracking['facebookApiToken'] ?? ''),
                    'googleAnalyticsId' => (isset($_POST['googleAnalyticsId']) && $_POST['googleAnalyticsId'] !== '') ? $_POST['googleAnalyticsId'] : ($existing_tracking['googleAnalyticsId'] ?? ''),
                    'googleAdsId' => (isset($_POST['googleAdsId']) && $_POST['googleAdsId'] !== '') ? $_POST['googleAdsId'] : ($existing_tracking['googleAdsId'] ?? ''),
                    'customScript' => (isset($_POST['customScript']) && $_POST['customScript'] !== '') ? $_POST['customScript'] : ($existing_tracking['customScript'] ?? ''),
                    'events' => [
                        'facebook' => [
                            'purchase' => isset($_POST['fb_event_purchase']) ? isset($_POST['fb_event_purchase']) : ($existing_tracking['events']['facebook']['purchase'] ?? false),
                            'pending' => isset($_POST['fb_event_pending']) ? isset($_POST['fb_event_pending']) : ($existing_tracking['events']['facebook']['pending'] ?? false),
                            'refund' => isset($_POST['fb_event_refund']) ? isset($_POST['fb_event_refund']) : ($existing_tracking['events']['facebook']['refund'] ?? false),
                            'chargeback' => isset($_POST['fb_event_chargeback']) ? isset($_POST['fb_event_chargeback']) : ($existing_tracking['events']['facebook']['chargeback'] ?? false),
                            'rejected' => isset($_POST['fb_event_rejected']) ? isset($_POST['fb_event_rejected']) : ($existing_tracking['events']['facebook']['rejected'] ?? false),
                            'initiate_checkout' => isset($_POST['fb_event_initiate_checkout']) ? isset($_POST['fb_event_initiate_checkout']) : ($existing_tracking['events']['facebook']['initiate_checkout'] ?? false),
                        ],
                        'google' => [
                            'purchase' => isset($_POST['gg_event_purchase']) ? isset($_POST['gg_event_purchase']) : ($existing_tracking['events']['google']['purchase'] ?? false),
                            'pending' => isset($_POST['gg_event_pending']) ? isset($_POST['gg_event_pending']) : ($existing_tracking['events']['google']['pending'] ?? false),
                            'refund' => isset($_POST['gg_event_refund']) ? isset($_POST['gg_event_refund']) : ($existing_tracking['events']['google']['refund'] ?? false),
                            'chargeback' => isset($_POST['gg_event_chargeback']) ? isset($_POST['gg_event_chargeback']) : ($existing_tracking['events']['google']['chargeback'] ?? false),
                            'rejected' => isset($_POST['gg_event_rejected']) ? isset($_POST['gg_event_rejected']) : ($existing_tracking['events']['google']['rejected'] ?? false),
                            'initiate_checkout' => isset($_POST['gg_event_initiate_checkout']) ? isset($_POST['gg_event_initiate_checkout']) : ($existing_tracking['events']['google']['initiate_checkout'] ?? false),
                        ]
                    ]
                ];
            }

            // Summary - só atualiza se os campos estiverem presentes no POST
            if (isset($_POST['summary_product_name']) || isset($_POST['summary_discount_text'])) {
                $existing_summary = $config_array['summary'] ?? [];
                $config_array['summary'] = [
                    'product_name' => $_POST['summary_product_name'] ?? ($existing_summary['product_name'] ?? ''),
                    'discount_text' => $_POST['summary_discount_text'] ?? ($existing_summary['discount_text'] ?? '')
                ];
            }

            // Header - só atualiza se os campos estiverem presentes no POST
            if (isset($_POST['header_enabled']) || isset($_POST['header_title']) || isset($_POST['header_subtitle'])) {
                $existing_header = $config_array['header'] ?? [];
                $config_array['header'] = [
                    'enabled' => isset($_POST['header_enabled']) ? isset($_POST['header_enabled']) : ($existing_header['enabled'] ?? true),
                    'title' => $_POST['header_title'] ?? ($existing_header['title'] ?? 'Finalize sua Compra'),
                    'subtitle' => $_POST['header_subtitle'] ?? ($existing_header['subtitle'] ?? 'Ambiente 100% seguro')
                ];
            }

            // Timer - só atualiza se os campos estiverem presentes no POST
            if (isset($_POST['timer_enabled']) || isset($_POST['timer_minutes']) || isset($_POST['timer_text']) || isset($_POST['timer_bgcolor']) || isset($_POST['timer_textcolor']) || isset($_POST['timer_sticky'])) {
                $existing_timer = $config_array['timer'] ?? [];
                $config_array['timer'] = [
                    'enabled' => isset($_POST['timer_enabled']) ? isset($_POST['timer_enabled']) : ($existing_timer['enabled'] ?? false),
                    'minutes' => isset($_POST['timer_minutes']) ? (int) $_POST['timer_minutes'] : ($existing_timer['minutes'] ?? 15),
                    'text' => $_POST['timer_text'] ?? ($existing_timer['text'] ?? 'Esta oferta expira em:'),
                    'bgcolor' => $_POST['timer_bgcolor'] ?? ($existing_timer['bgcolor'] ?? '#000000'),
                    'textcolor' => $_POST['timer_textcolor'] ?? ($existing_timer['textcolor'] ?? '#FFFFFF'),
                    'sticky' => isset($_POST['timer_sticky']) ? isset($_POST['timer_sticky']) : ($existing_timer['sticky'] ?? true)
                ];
            }

            // Sales Notification - só atualiza se os campos estiverem presentes no POST
            if (isset($_POST['sales_notification_enabled']) || isset($_POST['sales_notification_names']) || isset($_POST['sales_notification_product']) || isset($_POST['sales_notification_tempo_exibicao']) || isset($_POST['sales_notification_intervalo_notificacao'])) {
                $existing_sales_notification = $config_array['salesNotification'] ?? [];
                $config_array['salesNotification'] = [
                    'enabled' => isset($_POST['sales_notification_enabled']) ? isset($_POST['sales_notification_enabled']) : ($existing_sales_notification['enabled'] ?? false),
                    'names' => $_POST['sales_notification_names'] ?? ($existing_sales_notification['names'] ?? ''),
                    'product' => $_POST['sales_notification_product'] ?? ($existing_sales_notification['product'] ?? ''),
                    'tempo_exibicao' => isset($_POST['sales_notification_tempo_exibicao']) ? (int) $_POST['sales_notification_tempo_exibicao'] : ($existing_sales_notification['tempo_exibicao'] ?? 5),
                    'intervalo_notificacao' => isset($_POST['sales_notification_intervalo_notificacao']) ? (int) $_POST['sales_notification_intervalo_notificacao'] : ($existing_sales_notification['intervalo_notificacao'] ?? 10)
                ];
            }

            // Payment Methods - Lógica simplificada que lê diretamente dos campos hidden
            $existing_payment_methods = $config_array['paymentMethods'] ?? [];
            $has_payment_methods_in_post = isset($_POST['payment_pix_pushinpay']) || isset($_POST['payment_pix_efi']) || isset($_POST['payment_pix_enabled']) || isset($_POST['payment_pix_asaas']) || isset($_POST['payment_pix_applyfy']) || isset($_POST['payment_pix_spacepag']) ||
                isset($_POST['payment_credit_card_mercadopago']) || isset($_POST['payment_credit_card_hypercash']) || isset($_POST['payment_credit_card_efi']) || isset($_POST['payment_credit_card_asaas']) || isset($_POST['payment_credit_card_applyfy']) || isset($_POST['payment_credit_card_stripe']) ||
                isset($_POST['payment_ticket_enabled']);

            // Só atualiza paymentMethods se houver campos no POST (usuário está na aba de métodos de pagamento)
            if ($has_payment_methods_in_post) {
                // Ler valores diretos dos campos hidden (sempre presentes, refletem estado real dos checkboxes)
                $pix_pushinpay = isset($_POST['payment_pix_pushinpay']) && $_POST['payment_pix_pushinpay'] == '1';
                $pix_efi = isset($_POST['payment_pix_efi']) && $_POST['payment_pix_efi'] == '1';
                $pix_asaas = isset($_POST['payment_pix_asaas']) && $_POST['payment_pix_asaas'] == '1';
                $pix_applyfy = isset($_POST['payment_pix_applyfy']) && $_POST['payment_pix_applyfy'] == '1';
                $pix_spacepag = isset($_POST['payment_pix_spacepag']) && $_POST['payment_pix_spacepag'] == '1';
                $pix_mercadopago = isset($_POST['payment_pix_enabled']) && $_POST['payment_pix_enabled'] == '1';

                $credit_card_hypercash = isset($_POST['payment_credit_card_hypercash']) && $_POST['payment_credit_card_hypercash'] == '1';
                $credit_card_efi = isset($_POST['payment_credit_card_efi']) && $_POST['payment_credit_card_efi'] == '1';
                $credit_card_asaas = isset($_POST['payment_credit_card_asaas']) && $_POST['payment_credit_card_asaas'] == '1';
                $credit_card_applyfy = isset($_POST['payment_credit_card_applyfy']) && $_POST['payment_credit_card_applyfy'] == '1';
                $credit_card_mercadopago = isset($_POST['payment_credit_card_mercadopago']) && $_POST['payment_credit_card_mercadopago'] == '1';
                $credit_card_stripe = isset($_POST['payment_credit_card_stripe']) && $_POST['payment_credit_card_stripe'] == '1';

                // Determinar gateway do Pix (prioridade: PushinPay > Efí > Asaas > Applyfy > Mercado Pago)
                $pix_gateway = 'mercadopago';
                $pix_enabled = false;
                if ($pix_pushinpay) {
                    $pix_gateway = 'pushinpay';
                    $pix_enabled = true;
                } elseif ($pix_efi) {
                    $pix_gateway = 'efi';
                    $pix_enabled = true;
                } elseif ($pix_asaas) {
                    $pix_gateway = 'asaas';
                    $pix_enabled = true;
                } elseif ($pix_applyfy) {
                    $pix_gateway = 'applyfy';
                    $pix_enabled = true;
                } elseif ($pix_spacepag) {
                    $pix_gateway = 'spacepag';
                    $pix_enabled = true;
                } elseif ($pix_mercadopago) {
                    $pix_gateway = 'mercadopago';
                    $pix_enabled = true;
                }

                // Determinar gateway do Cartão (prioridade: Stripe > Hypercash > Efí > Asaas > Applyfy > Mercado Pago)
                // IMPORTANTE: Só habilita se o gateway específico estiver marcado explicitamente
                $credit_card_gateway = null;
                $credit_card_enabled = false;

                if ($credit_card_stripe) {
                    $credit_card_gateway = 'stripe';
                    $credit_card_enabled = true;
                } elseif ($credit_card_hypercash) {
                    $credit_card_gateway = 'hypercash';
                    $credit_card_enabled = true;
                } elseif ($credit_card_efi) {
                    $credit_card_gateway = 'efi';
                    $credit_card_enabled = true;
                } elseif ($credit_card_asaas) {
                    $credit_card_gateway = 'asaas';
                    $credit_card_enabled = true;
                } elseif ($credit_card_applyfy) {
                    $credit_card_gateway = 'applyfy';
                    $credit_card_enabled = true;
                } elseif ($credit_card_mercadopago) {
                    $credit_card_gateway = 'mercadopago';
                    $credit_card_enabled = true;
                }
                // Removida lógica de retrocompatibilidade que forçava Mercado Pago
                // Agora só habilita se o checkbox específico do gateway estiver marcado

                // Construir configuração final - sempre salva o estado atual dos checkboxes
                $config_array['paymentMethods'] = [
                    'pix' => [
                        'gateway' => $pix_gateway,
                        'enabled' => $pix_enabled
                    ],
                    'credit_card' => [
                        'gateway' => $credit_card_gateway,
                        'enabled' => $credit_card_enabled
                    ],
                    'ticket' => [
                        'gateway' => 'mercadopago',
                        'enabled' => isset($_POST['payment_ticket_enabled']) && $_POST['payment_ticket_enabled'] == '1'
                    ]
                ];
            } else {
                // Não há campos de métodos no POST - preserva configuração existente
                if (!empty($existing_payment_methods)) {
                    $config_array['paymentMethods'] = $existing_payment_methods;
                }
            }

            // Back Redirect - só atualiza se os campos estiverem presentes no POST
            if (isset($_POST['back_redirect_enabled']) || isset($_POST['back_redirect_url'])) {
                $existing_back_redirect = $config_array['backRedirect'] ?? [];
                $config_array['backRedirect'] = [
                    'enabled' => isset($_POST['back_redirect_enabled']) ? isset($_POST['back_redirect_enabled']) : ($existing_back_redirect['enabled'] ?? false),
                    'url' => $_POST['back_redirect_url'] ?? ($existing_back_redirect['url'] ?? '')
                ];
            }

            // Customer Fields - só atualiza se os campos estiverem presentes no POST
            if (isset($_POST['enable_phone'])) {
                $existing_customer_fields = $config_array['customer_fields'] ?? [];
                $config_array['customer_fields'] = [
                    'enable_cpf' => true, // CPF sempre obrigatório
                    'enable_phone' => isset($_POST['enable_phone']) ? isset($_POST['enable_phone']) : ($existing_customer_fields['enable_phone'] ?? true),
                ];
            } else {
                // Garantir que enable_cpf sempre seja true
                $existing_customer_fields = $config_array['customer_fields'] ?? [];
                $config_array['customer_fields'] = [
                    'enable_cpf' => true, // CPF sempre obrigatório
                    'enable_phone' => $existing_customer_fields['enable_phone'] ?? true,
                ];
            }

            // Element Order - só atualiza se o campo estiver presente no POST
            if (isset($_POST['elementOrder']) && !empty($_POST['elementOrder'])) {
                $elementOrder = json_decode($_POST['elementOrder'], true);
                if (is_array($elementOrder)) {
                    $config_array['elementOrder'] = $elementOrder;
                }
            }

            // Link para Produtos Bloqueados - só atualiza se os campos estiverem presentes no POST (aba links)
            if (isset($_POST['blocked_product_link_type'])) {
                $config_array['blocked_product_link_type'] = $_POST['blocked_product_link_type'];
                if (isset($_POST['blocked_product_sales_page_url']) && !empty(trim($_POST['blocked_product_sales_page_url']))) {
                    $config_array['blocked_product_sales_page_url'] = filter_var(trim($_POST['blocked_product_sales_page_url']), FILTER_SANITIZE_URL);
                } else {
                    unset($config_array['blocked_product_sales_page_url']);
                }
            }

            // Salvar checkout_config
            $config_json = json_encode($config_array, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            $stmt = $pdo->prepare("UPDATE produtos SET checkout_config = ? WHERE id = ? AND usuario_id = ?");
            $stmt->execute([$config_json, $id_produto, $usuario_id]);

            // ========== ORDER BUMPS ==========
            // Só processa order bumps se houver campos de order bumps no POST (usuário está na aba de order bumps)
            // Isso evita que order bumps sejam deletados quando o usuário salva outras abas
            if (isset($_POST['orderbump_product_id']) && is_array($_POST['orderbump_product_id'])) {
                // Deleta apenas se houver campos no POST (usuário está editando order bumps)
                $stmt_delete_ob = $pdo->prepare("DELETE FROM order_bumps WHERE main_product_id = ?");
                $stmt_delete_ob->execute([$id_produto]);

                $stmt_insert_ob = $pdo->prepare(
                    "INSERT INTO order_bumps (main_product_id, offer_product_id, headline, description, ordem, is_active) 
                 VALUES (?, ?, ?, ?, ?, ?)"
                );

                $ordem = 0;
                foreach ($_POST['orderbump_product_id'] as $index => $ob_product_id) {
                    if (empty($ob_product_id))
                        continue;

                    // Valida se o produto de order bump também pertence ao usuário E se tem o MESMO gateway
                    $stmt_check_owner = $pdo->prepare("SELECT id FROM produtos WHERE id = ? AND usuario_id = ? AND gateway = ?");
                    $stmt_check_owner->execute([$ob_product_id, $usuario_id, $current_gateway]);

                    if ($stmt_check_owner->rowCount() > 0) {
                        $headline = $_POST['orderbump_headline'][$index] ?? 'Sim, eu quero aproveitar essa oferta!';
                        $description = $_POST['orderbump_description'][$index] ?? '';
                        $is_active = isset($_POST['orderbump_is_active']) && isset($_POST['orderbump_is_active'][$index]);

                        $stmt_insert_ob->execute([$id_produto, $ob_product_id, $headline, $description, $ordem, $is_active]);
                        $ordem++;
                    }
                }
            }
            // Se não houver campos de order bumps no POST, NÃO faz nada - preserva os order bumps existentes

            $pdo->commit();
            $mensagem = "<div class='px-4 py-3 rounded relative mb-4' role='alert' style='background-color: color-mix(in srgb, var(--accent-primary) 20%, transparent); border: 1px solid var(--accent-primary); color: var(--accent-primary);'>Configurações salvas com sucesso!</div>";

            // Recarrega dados após salvamento
            $stmt = $pdo->prepare("SELECT * FROM produtos WHERE id = ? AND usuario_id = ?");
            $stmt->execute([$id_produto, $usuario_id]);
            $produto = $stmt->fetch(PDO::FETCH_ASSOC);
            $checkout_config = json_decode($produto['checkout_config'] ?? '{}', true);
            $current_gateway = $produto['gateway'] ?? 'mercadopago';

            $stmt_ob = $pdo->prepare("SELECT * FROM order_bumps WHERE main_product_id = ? ORDER BY ordem ASC");
            $stmt_ob->execute([$id_produto]);
            $order_bumps = $stmt_ob->fetchAll(PDO::FETCH_ASSOC);

            $stmt_todos_produtos = $pdo->prepare("SELECT id, nome FROM produtos WHERE id != ? AND usuario_id = ? AND gateway = ?");
            $stmt_todos_produtos->execute([$id_produto, $usuario_id, $current_gateway]);
            $lista_produtos_orderbump = $stmt_todos_produtos->fetchAll(PDO::FETCH_ASSOC);

        } catch (Exception $e) {
            $pdo->rollBack();
            $mensagem = "<div class='bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded relative mb-4' role='alert'>Erro ao salvar: " . $e->getMessage() . "</div>";
        }
    }
}

// Preparar variáveis para os componentes
$tracking_config = $checkout_config['tracking'] ?? [];
if (empty($tracking_config['facebookPixelId']) && !empty($checkout_config['facebookPixelId'])) {
    $tracking_config['facebookPixelId'] = $checkout_config['facebookPixelId'];
}
$tracking_events = $tracking_config['events'] ?? [];
$fb_events = $tracking_events['facebook'] ?? [];
$gg_events = $tracking_events['google'] ?? [];
$default_order = ['header', 'banner', 'youtube_video', 'summary', 'customer_info', 'order_bump', 'final_summary', 'payment', 'guarantee', 'security_info'];
$element_order = $checkout_config['elementOrder'] ?? $default_order;
if (empty($element_order) || !is_array($element_order))
    $element_order = $default_order;

// Ler paymentMethods com retrocompatibilidade
$payment_methods_config = $checkout_config['paymentMethods'] ?? [];
if (empty($payment_methods_config) || !isset($payment_methods_config['pix']['gateway'])) {
    // Estrutura antiga - migrar
    $old_payment_methods = $checkout_config['paymentMethods'] ?? ['credit_card' => false, 'pix' => false, 'ticket' => false];
    // Determinar gateway do Pix baseado no gateway do produto
    $pix_gateway = 'mercadopago';
    if ($current_gateway === 'pushinpay') {
        $pix_gateway = 'pushinpay';
    } elseif ($current_gateway === 'efi') {
        $pix_gateway = 'efi';
    }
    $payment_methods_config = [
        'pix' => [
            'gateway' => $pix_gateway,
            'enabled' => $old_payment_methods['pix'] ?? false
        ],
        'credit_card' => [
            'gateway' => 'mercadopago',
            'enabled' => $old_payment_methods['credit_card'] ?? false
        ],
        'ticket' => [
            'gateway' => 'mercadopago',
            'enabled' => $old_payment_methods['ticket'] ?? false
        ]
    ];
}

$customer_fields_config = $checkout_config['customer_fields'] ?? ['enable_cpf' => true, 'enable_phone' => true];

// Determinar aba ativa
$aba_ativa = $_GET['aba'] ?? 'geral';
$abas_permitidas = ['geral', 'order_bumps', 'metodos_pagamento', 'rastreamento', 'checkout', 'links'];
if (!in_array($aba_ativa, $abas_permitidas)) {
    $aba_ativa = 'geral';
}
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    <!-- Header -->
    <div class="mb-8">
        <div class="flex items-center justify-between mb-4">
            <div class="flex items-center gap-4">
                <a href="/index?pagina=produtos" class="transition-colors p-2 hover:bg-dark-elevated rounded-lg"
                    style="color: var(--accent-primary);" onmouseover="this.style.color='var(--accent-primary-hover)'"
                    onmouseout="this.style.color='var(--accent-primary)'">
                    <i data-lucide="arrow-left" class="w-6 h-6"></i>
                </a>
                <div>
                    <h1 class="text-3xl font-extrabold text-white mb-1">Configuração do Produto</h1>
                    <p class="text-gray-400 text-sm flex items-center gap-2">
                        <i data-lucide="package" class="w-4 h-4"></i>
                        <?php echo htmlspecialchars($produto['nome']); ?>
                    </p>
                </div>
            </div>
        </div>
        <?php
        // Exibir mensagem flash se existir
        if (isset($_SESSION['flash_message'])) {
            echo "<div class='mb-6'>" . $_SESSION['flash_message'] . "</div>";
            unset($_SESSION['flash_message']);
        } elseif (!empty($mensagem)) {
            echo "<div class='mb-6'>" . $mensagem . "</div>";
        }
        ?>
    </div>

    <!-- Sistema de Abas -->
    <div class="bg-dark-card rounded-xl shadow-xl border border-dark-border overflow-hidden mb-8">
        <div class="border-b border-dark-border bg-dark-elevated">
            <nav class="flex overflow-x-auto scrollbar-hide pb-1 md:pb-0" role="tablist"
                style="scrollbar-width: none; -ms-overflow-style: none;">
                <a href="/index?pagina=produto_config&id=<?php echo $id_produto; ?>&aba=geral"
                    class="flex items-center gap-2 px-4 md:px-6 py-3 md:py-4 text-sm font-semibold border-b-2 transition-all duration-200 whitespace-nowrap <?php echo $aba_ativa === 'geral' ? 'bg-dark-card' : 'text-gray-400 border-transparent hover:text-white hover:border-gray-600'; ?>"
                    <?php echo $aba_ativa === 'geral' ? 'style="color: var(--accent-primary); border-color: var(--accent-primary);"' : ''; ?>>
                    <i data-lucide="settings" class="w-4 h-4 md:w-5 md:h-5"></i>
                    <span>Geral</span>
                </a>
                <a href="/index?pagina=produto_config&id=<?php echo $id_produto; ?>&aba=order_bumps"
                    class="flex items-center gap-2 px-4 md:px-6 py-3 md:py-4 text-sm font-semibold border-b-2 transition-all duration-200 whitespace-nowrap <?php echo $aba_ativa === 'order_bumps' ? 'bg-dark-card' : 'text-gray-400 border-transparent hover:text-white hover:border-gray-600'; ?>"
                    <?php echo $aba_ativa === 'order_bumps' ? 'style="color: var(--accent-primary); border-color: var(--accent-primary);"' : ''; ?>>
                    <i data-lucide="gift" class="w-4 h-4 md:w-5 md:h-5"></i>
                    <span>Order Bumps</span>
                </a>
                <a href="/index?pagina=produto_config&id=<?php echo $id_produto; ?>&aba=metodos_pagamento"
                    class="flex items-center gap-2 px-4 md:px-6 py-3 md:py-4 text-sm font-semibold border-b-2 transition-all duration-200 whitespace-nowrap <?php echo $aba_ativa === 'metodos_pagamento' ? 'bg-dark-card' : 'text-gray-400 border-transparent hover:text-white hover:border-gray-600'; ?>"
                    <?php echo $aba_ativa === 'metodos_pagamento' ? 'style="color: var(--accent-primary); border-color: var(--accent-primary);"' : ''; ?>>
                    <i data-lucide="credit-card" class="w-4 h-4 md:w-5 md:h-5"></i>
                    <span>Métodos de Pagamento</span>
                </a>
                <a href="/index?pagina=produto_config&id=<?php echo $id_produto; ?>&aba=rastreamento"
                    class="flex items-center gap-2 px-4 md:px-6 py-3 md:py-4 text-sm font-semibold border-b-2 transition-all duration-200 whitespace-nowrap <?php echo $aba_ativa === 'rastreamento' ? 'bg-dark-card' : 'text-gray-400 border-transparent hover:text-white hover:border-gray-600'; ?>"
                    <?php echo $aba_ativa === 'rastreamento' ? 'style="color: var(--accent-primary); border-color: var(--accent-primary);"' : ''; ?>>
                    <i data-lucide="activity" class="w-4 h-4 md:w-5 md:h-5"></i>
                    <span>Rastreamento & Pixels</span>
                </a>
                <a href="/index?pagina=produto_config&id=<?php echo $id_produto; ?>&aba=checkout"
                    class="flex items-center gap-2 px-4 md:px-6 py-3 md:py-4 text-sm font-semibold border-b-2 transition-all duration-200 whitespace-nowrap <?php echo $aba_ativa === 'checkout' ? 'bg-dark-card' : 'text-gray-400 border-transparent hover:text-white hover:border-gray-600'; ?>"
                    <?php echo $aba_ativa === 'checkout' ? 'style="color: var(--accent-primary); border-color: var(--accent-primary);"' : ''; ?>>
                    <i data-lucide="palette" class="w-4 h-4 md:w-5 md:h-5"></i>
                    <span>Checkout</span>
                </a>
                <a href="/index?pagina=produto_config&id=<?php echo $id_produto; ?>&aba=links"
                    class="flex items-center gap-2 px-4 md:px-6 py-3 md:py-4 text-sm font-semibold border-b-2 transition-all duration-200 whitespace-nowrap <?php echo $aba_ativa === 'links' ? 'bg-dark-card' : 'text-gray-400 border-transparent hover:text-white hover:border-gray-600'; ?>"
                    <?php echo $aba_ativa === 'links' ? 'style="color: var(--accent-primary); border-color: var(--accent-primary);"' : ''; ?>>
                    <i data-lucide="link" class="w-4 h-4 md:w-5 md:h-5"></i>
                    <span>Links</span>
                </a>
            </nav>
        </div>

        <!-- Conteúdo das Abas -->
        <form action="/index?pagina=produto_config&id=<?php echo $id_produto; ?>&aba=<?php echo $aba_ativa; ?>"
            method="post" enctype="multipart/form-data" class="p-4 md:p-8 bg-dark-card"
            onsubmit="if(typeof window.forceUpdateEfiHidden === 'function') { window.forceUpdateEfiHidden(); } if(typeof window.forceUpdateAllHiddenFields === 'function') { window.forceUpdateAllHiddenFields(); } return true;">
            <?php
            require_once __DIR__ . '/../helpers/security_helper.php';
            $csrf_token = generate_csrf_token();
            ?>
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="id_produto" value="<?php echo $id_produto; ?>">
            <input type="hidden" name="foto_atual" value="<?php echo htmlspecialchars($produto['foto'] ?? ''); ?>">
            <input type="hidden" name="conteudo_entrega_atual"
                value="<?php echo htmlspecialchars($produto['conteudo_entrega'] ?? ''); ?>">
            <input type="hidden" name="elementOrder"
                value="<?php echo htmlspecialchars(json_encode($element_order)); ?>">

            <?php
            // Incluir componente da aba ativa
            $aba_file = __DIR__ . '/produto_config/aba_' . $aba_ativa . '.php';
            if (file_exists($aba_file)) {
                include $aba_file;
            } else {
                // Fallback para aba geral
                include __DIR__ . '/produto_config/aba_geral.php';
            }
            ?>

            <!-- Botão Salvar -->
            <?php if ($aba_ativa !== 'checkout'): ?>
                <div
                    class="mt-6 md:mt-10 pt-4 md:pt-6 border-t border-dark-border flex justify-center md:justify-end bg-dark-elevated -mx-4 md:-mx-8 -mb-4 md:-mb-8 px-4 md:px-8 py-4 md:py-6 rounded-b-xl sticky bottom-0 z-10">
                    <button type="submit" name="salvar_produto_config"
                        onclick="console.log('=== BOTÃO CLICADO (onclick) ==='); if(typeof window.forceUpdateEfiHidden === 'function') { window.forceUpdateEfiHidden(); } if(typeof window.forceUpdateAllHiddenFields === 'function') { window.forceUpdateAllHiddenFields(); } return true;"
                        class="w-full md:w-auto text-white font-bold py-3 md:py-3 px-6 md:px-8 rounded-lg shadow-lg hover:shadow-xl transition-all duration-300 flex items-center justify-center gap-2 transform hover:scale-105 active:scale-95"
                        style="background-color: var(--accent-primary);"
                        onmouseover="this.style.backgroundColor='var(--accent-primary-hover)'"
                        onmouseout="this.style.backgroundColor='var(--accent-primary)'">
                        <i data-lucide="save" class="w-5 h-5"></i>
                        Salvar Alterações
                    </button>
                </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- Modal para Criar/Editar Oferta (fora do formulário principal) -->
<div id="modal-oferta" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden items-center justify-center p-4">
    <div class="bg-dark-card rounded-xl shadow-2xl border border-dark-border max-w-md w-full p-6">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-xl font-bold text-white" id="modal-oferta-titulo">Adicionar Oferta</h3>
            <button type="button" id="fechar-modal-oferta" class="text-gray-400 hover:text-white transition-colors">
                <i data-lucide="x" class="w-5 h-5"></i>
            </button>
        </div>
        <form id="form-oferta" class="space-y-4">
            <input type="hidden" id="oferta-id" name="oferta_id" value="">
            <div>
                <label for="oferta-nome" class="block text-gray-300 text-sm font-semibold mb-2">Nome da Oferta</label>
                <input type="text" id="oferta-nome" name="oferta_nome" class="form-input"
                    placeholder="Ex: Oferta Black Friday" required minlength="3">
                <p class="text-xs text-gray-400 mt-1">Mínimo de 3 caracteres</p>
            </div>
            <div>
                <label for="oferta-preco" class="block text-gray-300 text-sm font-semibold mb-2">Preço (R$)</label>
                <div class="relative">
                    <span
                        class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none text-gray-400 font-bold z-10">R$</span>
                    <input type="number" step="0.01" id="oferta-preco" name="oferta_preco"
                        class="form-input pl-10 w-full" placeholder="0.00" required min="0.01"
                        style="padding-left: 2.75rem;">
                </div>
                <p class="text-xs text-gray-400 mt-1">Preço deve ser maior que zero</p>
            </div>
            <div class="flex gap-3 pt-4">
                <button type="button" id="cancelar-oferta"
                    class="flex-1 bg-dark-elevated hover:bg-dark-border text-white font-semibold py-2 px-4 rounded-lg transition-all duration-300">
                    Cancelar
                </button>
                <button type="submit"
                    class="flex-1 text-white font-semibold py-2 px-4 rounded-lg transition-all duration-300"
                    style="background-color: var(--accent-primary);"
                    onmouseover="this.style.backgroundColor='var(--accent-primary-hover)'"
                    onmouseout="this.style.backgroundColor='var(--accent-primary)'">
                    Salvar Oferta
                </button>
            </div>
        </form>
    </div>
</div>

<style>
    /* Esconder scrollbar nas abas */
    .scrollbar-hide::-webkit-scrollbar {
        display: none;
    }

    /* Estilos para inputs e formulários */
    .form-input {
        width: 100%;
        padding: 0.75rem 1rem;
        background-color: rgba(26, 31, 36, 0.8);
        border: 1px solid rgba(255, 255, 255, 0.1);
        border-radius: 0.5rem;
        font-size: 16px;
        /* Previne zoom no iOS */
        font-size: 0.875rem;
        color: white;
        transition: all 0.2s ease;
        font-family: inherit;
    }

    .form-input::placeholder {
        color: rgba(156, 163, 175, 0.6);
    }

    .form-input:focus {
        outline: none;
        border-color: var(--accent-primary);
        box-shadow: 0 0 0 3px rgba(50, 231, 104, 0.1);
    }

    textarea.form-input {
        resize: vertical;
        min-height: 100px;
    }

    .form-checkbox {
        width: 1.25rem;
        height: 1.25rem;
        color: var(--accent-primary);
        background-color: rgba(26, 31, 36, 0.8);
        border: 1px solid rgba(255, 255, 255, 0.1);
        border-radius: 0.25rem;
        cursor: pointer;
        transition: all 0.2s ease;
        appearance: none;
        -webkit-appearance: none;
        position: relative;
    }

    .form-checkbox:focus {
        outline: none;
        box-shadow: 0 0 0 3px rgba(50, 231, 104, 0.2);
    }

    .form-checkbox:checked {
        background-color: var(--accent-primary);
        border-color: var(--accent-primary);
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20' fill='white'%3E%3Cpath fill-rule='evenodd' d='M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z' clip-rule='evenodd'/%3E%3C/svg%3E");
        background-size: 100% 100%;
        background-position: center;
        background-repeat: no-repeat;
    }

    select.form-input {
        cursor: pointer;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3E%3Cpath stroke='%23ffffff' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3E%3C/svg%3E");
        background-position: right 0.5rem center;
        background-repeat: no-repeat;
        background-size: 1.5em 1.5em;
        padding-right: 2.5rem;
    }
</style>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        lucide.createIcons();
    });
</script>