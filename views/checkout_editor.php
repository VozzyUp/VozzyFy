<?php
// Redirecionar para nova página unificada de configuração
if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    header("Location: /index?pagina=produto_config&id=" . intval($_GET['id']) . "&aba=checkout");
    exit;
}

// Fallback se não houver ID
header("Location: /index?pagina=produtos");
exit;

// Código abaixo não será executado devido ao redirecionamento acima
// Mantido apenas para referência
/*
<?php
// A página é carregada via index.php, então o config.php já está incluído
$mensagem = '';
$produto = null;
$checkout_config = [];
$order_bumps = [];

// 1. Validar e buscar o produto
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: /index?pagina=produtos");
    exit;
}

$id_produto = $_GET['id'];
*/
$usuario_id_logado = $_SESSION['id']; // ID do usuário logado

try {
    // 2. Buscar o produto e verificar se ele pertence ao usuário logado
    $stmt = $pdo->prepare("SELECT * FROM produtos WHERE id = ? AND usuario_id = ?");
    $stmt->execute([$id_produto, $usuario_id_logado]);
    $produto = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$produto) {
         $_SESSION['flash_message'] = "<div class='bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded relative mb-4' role='alert'>Produto não encontrado ou você não tem permissão para acessá-lo.</div>";
         header("Location: /index?pagina=produtos");
         exit;
    }

    // Identifica o gateway do produto atual
    $current_gateway = $produto['gateway'] ?? 'mercadopago';

    // --- ALTERAÇÃO AQUI: Filtra produtos de Order Bump pelo mesmo Gateway ---
    $stmt_todos_produtos = $pdo->prepare("SELECT id, nome FROM produtos WHERE id != ? AND usuario_id = ? AND gateway = ?");
    $stmt_todos_produtos->execute([$id_produto, $usuario_id_logado, $current_gateway]);
    $lista_produtos_orderbump = $stmt_todos_produtos->fetchAll(PDO::FETCH_ASSOC);

    // Busca os order bumps existentes para este produto
    $stmt_ob = $pdo->prepare("SELECT * FROM order_bumps WHERE main_product_id = ? ORDER BY ordem ASC");
    $stmt_ob->execute([$id_produto]);
    $order_bumps = $stmt_ob->fetchAll(PDO::FETCH_ASSOC);


} catch(PDOException $e) {
    die("Erro ao buscar dados: " . $e->getMessage());
}

// Garante que o diretório de uploads exista
if (!is_dir('uploads')) {
    mkdir('uploads', 0755, true);
}

// -- NOVA FUNÇÃO DE UPLOAD MÚLTIPLO (segura) --
function handle_multiple_uploads($file_key, $prefix, $product_id) {
    require_once __DIR__ . '/../helpers/security_helper.php';
    
    $uploaded_paths = [];
    
    // Debug: verificar se o campo existe
    error_log("Checkout Editor: Verificando upload para campo: $file_key");
    error_log("Checkout Editor: \$_FILES[$file_key] existe: " . (isset($_FILES[$file_key]) ? 'SIM' : 'NÃO'));
    
    if (isset($_FILES[$file_key])) {
        // Debug: verificar estrutura do $_FILES
        error_log("Checkout Editor: Estrutura \$_FILES[$file_key]: " . json_encode(array_keys($_FILES[$file_key])));
        
        if (is_array($_FILES[$file_key]['name'])) {
            $file_count = count($_FILES[$file_key]['name']);
            error_log("Checkout Editor: Número de arquivos para $file_key: $file_count");
            
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
                    
                    error_log("Checkout Editor: Processando arquivo $i: " . $file_array['name']);
                    
                    // Apenas JPEG ou PNG para banners do checkout
                    $upload_result = validate_image_upload($file_array, 'uploads/', $prefix . '_' . $product_id, 5, true);
                    if ($upload_result['success']) {
                        // Garantir que o caminho tenha / no início para exibição correta
                        $file_path = $upload_result['file_path'];
                        if (!empty($file_path) && strpos($file_path, '/') !== 0) {
                            $file_path = '/' . ltrim($file_path, '/');
                        }
                        $uploaded_paths[] = $file_path;
                        error_log("Checkout Editor: Upload bem-sucedido: $file_path");
                    } else {
                        // Log de erro para debug
                        error_log("Checkout Editor: Erro ao fazer upload de banner: " . ($upload_result['error'] ?? 'Erro desconhecido'));
                    }
                } else {
                    error_log("Checkout Editor: Erro no upload do arquivo $i: " . $_FILES[$file_key]['error'][$i]);
                }
            }
        } else {
            // Pode ser um único arquivo (não array)
            error_log("Checkout Editor: Campo $file_key não é array, tentando como arquivo único");
            if ($_FILES[$file_key]['error'] == UPLOAD_ERR_OK) {
                $file_array = [
                    'name' => $_FILES[$file_key]['name'],
                    'type' => $_FILES[$file_key]['type'],
                    'tmp_name' => $_FILES[$file_key]['tmp_name'],
                    'error' => $_FILES[$file_key]['error'],
                    'size' => $_FILES[$file_key]['size']
                ];
                
                $upload_result = validate_image_upload($file_array, 'uploads/', $prefix . '_' . $product_id, 5, true);
                if ($upload_result['success']) {
                    $file_path = $upload_result['file_path'];
                    if (!empty($file_path) && strpos($file_path, '/') !== 0) {
                        $file_path = '/' . ltrim($file_path, '/');
                    }
                    $uploaded_paths[] = $file_path;
                    error_log("Checkout Editor: Upload único bem-sucedido: $file_path");
                }
            }
        }
    } else {
        error_log("Checkout Editor: Campo $file_key não encontrado em \$_FILES");
    }
    
    error_log("Checkout Editor: Total de arquivos enviados para $file_key: " . count($uploaded_paths));
    return $uploaded_paths;
}

// 2. Processar o formulário de salvamento
if (isset($_POST['salvar_checkout'])) {
    $pdo->beginTransaction();
    try {
        // Busca a configuração existente para não sobrescrever o que não foi alterado
        $stmt_get_config = $pdo->prepare("SELECT checkout_config FROM produtos WHERE id = ? AND usuario_id = ?");
        $stmt_get_config->execute([$id_produto, $usuario_id_logado]);
        $current_config_json = $stmt_get_config->fetchColumn();
        $config_array = json_decode($current_config_json ?: '{}', true);

        // -- LÓGICA DE UPLOAD ATUALIZADA PARA MÚLTIPLOS BANNERS --
        // (Lógica de banners mantida idêntica)
        $current_banners = $config_array['banners'] ?? [];
        if (empty($current_banners) && !empty($config_array['bannerUrl'])) {
            $current_banners = [$config_array['bannerUrl']];
        }
        
        // Normalizar caminhos existentes (garantir / no início)
        $current_banners = array_map(function($banner) {
            if (!empty($banner) && strpos($banner, '/') !== 0 && strpos($banner, 'http') !== 0) {
                return '/' . ltrim($banner, '/');
            }
            return $banner;
        }, $current_banners);
        
        $banners_to_remove = $_POST['remove_banners'] ?? [];
        $final_banners_list = [];
        foreach ($current_banners as $banner_path) {
            if (!in_array($banner_path, $banners_to_remove)) {
                $final_banners_list[] = $banner_path;
            } else {
                // Normalizar caminho antes de deletar
                $file_to_delete = $banner_path;
                if (strpos($file_to_delete, '/') !== 0) {
                    $file_to_delete = '/' . ltrim($file_to_delete, '/');
                }
                if (file_exists($file_to_delete) || file_exists(ltrim($file_to_delete, '/'))) {
                    @unlink($file_to_delete);
                    @unlink(ltrim($file_to_delete, '/'));
                }
            }
        }
        $newly_uploaded_banners = handle_multiple_uploads('add_banner_files', 'banner', $id_produto);
        // Debug: log dos banners enviados
        error_log("Checkout Editor: Banners enviados: " . json_encode($newly_uploaded_banners));
        $config_array['banners'] = array_merge($final_banners_list, $newly_uploaded_banners);
        // Debug: log dos banners finais
        error_log("Checkout Editor: Banners finais salvos: " . json_encode($config_array['banners']));

        $current_side_banners = $config_array['sideBanners'] ?? [];
        if (empty($current_side_banners) && !empty($config_array['sideBannerUrl'])) {
            $current_side_banners = [$config_array['sideBannerUrl']];
        }
        
        // Normalizar caminhos existentes (garantir / no início)
        $current_side_banners = array_map(function($banner) {
            if (!empty($banner) && strpos($banner, '/') !== 0 && strpos($banner, 'http') !== 0) {
                return '/' . ltrim($banner, '/');
            }
            return $banner;
        }, $current_side_banners);
        
        $side_banners_to_remove = $_POST['remove_side_banners'] ?? [];
        $final_side_banners_list = [];
        foreach ($current_side_banners as $banner_path) {
            if (!in_array($banner_path, $side_banners_to_remove)) {
                $final_side_banners_list[] = $banner_path;
            } else {
                // Normalizar caminho antes de deletar
                $file_to_delete = $banner_path;
                if (strpos($file_to_delete, '/') !== 0) {
                    $file_to_delete = '/' . ltrim($file_to_delete, '/');
                }
                if (file_exists($file_to_delete) || file_exists(ltrim($file_to_delete, '/'))) {
                    @unlink($file_to_delete);
                    @unlink(ltrim($file_to_delete, '/'));
                }
            }
        }
        // Debug: verificar $_FILES antes de processar
        error_log("Checkout Editor: \$_FILES completo: " . json_encode(array_keys($_FILES)));
        if (isset($_FILES['add_side_banner_files'])) {
            error_log("Checkout Editor: add_side_banner_files existe. Estrutura: " . json_encode([
                'name' => is_array($_FILES['add_side_banner_files']['name']) ? count($_FILES['add_side_banner_files']['name']) . ' arquivos' : 'não é array',
                'error' => is_array($_FILES['add_side_banner_files']['error']) ? $_FILES['add_side_banner_files']['error'] : ($_FILES['add_side_banner_files']['error'] ?? 'não definido')
            ]));
        } else {
            error_log("Checkout Editor: add_side_banner_files NÃO existe em \$_FILES");
        }
        
        $newly_uploaded_side_banners = handle_multiple_uploads('add_side_banner_files', 'sidebanner', $id_produto);
        // Debug: log dos banners laterais enviados
        error_log("Checkout Editor: Banners laterais enviados: " . json_encode($newly_uploaded_side_banners));
        $config_array['sideBanners'] = array_merge($final_side_banners_list, $newly_uploaded_side_banners);
        // Debug: log dos banners laterais finais
        error_log("Checkout Editor: Banners laterais finais salvos: " . json_encode($config_array['sideBanners']));
        
        // Verificar se sideBanners está vazio após merge
        if (empty($config_array['sideBanners'])) {
            error_log("Checkout Editor: AVISO - sideBanners está vazio após processamento!");
            error_log("Checkout Editor: final_side_banners_list: " . json_encode($final_side_banners_list));
            error_log("Checkout Editor: newly_uploaded_side_banners: " . json_encode($newly_uploaded_side_banners));
        }

        unset($config_array['bannerUrl']);
        unset($config_array['sideBannerUrl']);

        // -- FIM DA LÓGICA DE UPLOAD --

        $preco_anterior = !empty($_POST['preco_anterior']) ? floatval(str_replace(',', '.', $_POST['preco_anterior'])) : null;
        $stmt_update_produto = $pdo->prepare("UPDATE produtos SET preco_anterior = ? WHERE id = ? AND usuario_id = ?");
        $stmt_update_produto->execute([$preco_anterior, $id_produto, $usuario_id_logado]);

        $elementOrder = json_decode($_POST['elementOrder'] ?? '[]', true);

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

        // Tracking - sempre preserva a configuração existente, só atualiza se houver campos com valores no POST
        // Como o formulário do editor de checkout sempre envia os campos de tracking (mesmo vazios),
        // precisamos verificar se algum campo foi realmente alterado antes de atualizar
        $existing_tracking = $config_array['tracking'] ?? [];
        
        // Verifica se algum campo de tracking foi realmente alterado (tem valor não vazio ou checkbox alterado)
        $has_tracking_changes = (isset($_POST['facebookPixelId']) && $_POST['facebookPixelId'] !== '' && $_POST['facebookPixelId'] !== ($existing_tracking['facebookPixelId'] ?? '')) || 
                               (isset($_POST['facebookApiToken']) && $_POST['facebookApiToken'] !== '' && $_POST['facebookApiToken'] !== ($existing_tracking['facebookApiToken'] ?? '')) || 
                               (isset($_POST['googleAnalyticsId']) && $_POST['googleAnalyticsId'] !== '' && $_POST['googleAnalyticsId'] !== ($existing_tracking['googleAnalyticsId'] ?? '')) || 
                               (isset($_POST['googleAdsId']) && $_POST['googleAdsId'] !== '' && $_POST['googleAdsId'] !== ($existing_tracking['googleAdsId'] ?? '')) ||
                               isset($_POST['fb_event_purchase']) || isset($_POST['fb_event_pending']) || 
                               isset($_POST['fb_event_refund']) || isset($_POST['fb_event_chargeback']) ||
                               isset($_POST['fb_event_rejected']) || isset($_POST['fb_event_initiate_checkout']) ||
                               isset($_POST['gg_event_purchase']) || isset($_POST['gg_event_pending']) ||
                               isset($_POST['gg_event_refund']) || isset($_POST['gg_event_chargeback']) ||
                               isset($_POST['gg_event_rejected']) || isset($_POST['gg_event_initiate_checkout']);
        
        // Se houver mudanças ou se já existe configuração de tracking, preserva/atualiza
        if ($has_tracking_changes || !empty($existing_tracking)) {
            // Só atualiza campos de texto se tiverem valores não vazios, caso contrário preserva o existente
            $config_array['tracking'] = [
                'facebookPixelId' => (isset($_POST['facebookPixelId']) && $_POST['facebookPixelId'] !== '') ? $_POST['facebookPixelId'] : ($existing_tracking['facebookPixelId'] ?? ''),
                'facebookApiToken' => (isset($_POST['facebookApiToken']) && $_POST['facebookApiToken'] !== '') ? $_POST['facebookApiToken'] : ($existing_tracking['facebookApiToken'] ?? ''),
                'googleAnalyticsId' => (isset($_POST['googleAnalyticsId']) && $_POST['googleAnalyticsId'] !== '') ? $_POST['googleAnalyticsId'] : ($existing_tracking['googleAnalyticsId'] ?? ''),
                'googleAdsId' => (isset($_POST['googleAdsId']) && $_POST['googleAdsId'] !== '') ? $_POST['googleAdsId'] : ($existing_tracking['googleAdsId'] ?? ''),
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
                'minutes' => isset($_POST['timer_minutes']) ? (int)$_POST['timer_minutes'] : ($existing_timer['minutes'] ?? 15),
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
                'tempo_exibicao' => isset($_POST['sales_notification_tempo_exibicao']) ? (int)$_POST['sales_notification_tempo_exibicao'] : ($existing_sales_notification['tempo_exibicao'] ?? 5),
                'intervalo_notificacao' => isset($_POST['sales_notification_intervalo_notificacao']) ? (int)$_POST['sales_notification_intervalo_notificacao'] : ($existing_sales_notification['intervalo_notificacao'] ?? 10)
            ];
        }
        
        // --- ALTERAÇÃO AQUI: Força métodos de pagamento se for PushinPay ---
        if ($current_gateway === 'pushinpay') {
             $config_array['paymentMethods'] = [ 
                'credit_card' => false, 
                'pix' => true, // Sempre true para PP
                'ticket' => false 
            ];
        } else {
            $config_array['paymentMethods'] = [ 
                'credit_card' => isset($_POST['payment_credit_card_enabled']), 
                'pix' => isset($_POST['payment_pix_enabled']), 
                'ticket' => isset($_POST['payment_ticket_enabled']) 
            ];
        }

        $config_array['backRedirect'] = [ 'enabled' => isset($_POST['back_redirect_enabled']), 'url' => $_POST['back_redirect_url'] ?? '' ];
        $config_array['elementOrder'] = $elementOrder;

        $config_array['customer_fields'] = [
            'enable_cpf' => true, // CPF sempre obrigatório
            'enable_phone' => true, // Telefone sempre obrigatório
        ];

        $config_json = json_encode($config_array, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $stmt = $pdo->prepare("UPDATE produtos SET checkout_config = ? WHERE id = ? AND usuario_id = ?");
        $stmt->execute([$config_json, $id_produto, $usuario_id_logado]);
        
        // --- LÓGICA DO ORDER BUMP (MÚLTIPLOS) ---
        // Só processa order bumps se houver campos de order bumps no POST
        // Isso evita que order bumps sejam deletados quando o usuário salva outras configurações
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
                if (empty($ob_product_id)) continue;

                // Valida se o produto de order bump também pertence ao usuário E se tem o MESMO gateway
                $stmt_check_owner = $pdo->prepare("SELECT id FROM produtos WHERE id = ? AND usuario_id = ? AND gateway = ?");
                $stmt_check_owner->execute([$ob_product_id, $usuario_id_logado, $current_gateway]);
                
                if($stmt_check_owner->rowCount() > 0) {
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
        
        // Redirect para recarregar a página e mostrar os novos banners
        header("Location: /index?pagina=checkout_editor&id=" . intval($id_produto) . "&success=1");
        exit;

    } catch (Exception $e) {
        $pdo->rollBack();
        $mensagem = "<div class='bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded relative mb-4' role='alert'>Erro ao salvar: " . $e->getMessage() . "</div>";
        error_log("Erro ao salvar checkout: " . $e->getMessage());
    }
}

// Mostra mensagem de sucesso se veio do redirect
if (isset($_GET['success']) && $_GET['success'] == '1') {
    $mensagem = "<div class='bg-green-900/20 border border-green-500 text-green-300 px-4 py-3 rounded relative mb-4' role='alert'>Configurações salvas com sucesso!</div>";
}

// Busca novamente os dados atualizados para preencher o formulário
$stmt = $pdo->prepare("SELECT * FROM produtos WHERE id = ? AND usuario_id = ?");
$stmt->execute([$id_produto, $usuario_id_logado]);
$produto = $stmt->fetch(PDO::FETCH_ASSOC);
$checkout_config = json_decode($produto['checkout_config'] ?? '{}', true);
$current_gateway = $produto['gateway'] ?? 'mercadopago';

// Recarrega order bumps
$stmt_ob = $pdo->prepare("SELECT * FROM order_bumps WHERE main_product_id = ? ORDER BY ordem ASC");
$stmt_ob->execute([$id_produto]);
$order_bumps = $stmt_ob->fetchAll(PDO::FETCH_ASSOC);

// Recarrega a lista de produtos para o select (caso tenha adicionado um produto que mudou de gateway)
$stmt_todos_produtos = $pdo->prepare("SELECT id, nome FROM produtos WHERE id != ? AND usuario_id = ? AND gateway = ?");
$stmt_todos_produtos->execute([$id_produto, $usuario_id_logado, $current_gateway]);
$lista_produtos_orderbump = $stmt_todos_produtos->fetchAll(PDO::FETCH_ASSOC);

$tracking_config = $checkout_config['tracking'] ?? [];
if (empty($tracking_config['facebookPixelId']) && !empty($checkout_config['facebookPixelId'])) {
    $tracking_config['facebookPixelId'] = $checkout_config['facebookPixelId'];
}
$tracking_events = $tracking_config['events'] ?? [];
$fb_events = $tracking_events['facebook'] ?? [];
$gg_events = $tracking_events['google'] ?? [];
$default_order = ['header', 'banner', 'youtube_video', 'summary', 'customer_info', 'order_bump', 'final_summary', 'payment', 'guarantee', 'security_info'];
$element_order = $checkout_config['elementOrder'] ?? $default_order;
if(empty($element_order) || !is_array($element_order)) $element_order = $default_order;
$payment_methods_config = $checkout_config['paymentMethods'] ?? [ 'credit_card' => false, 'pix' => false, 'ticket' => false ];

$customer_fields_config = $checkout_config['customer_fields'] ?? ['enable_cpf' => true, 'enable_phone' => true];
?>

<div class="flex h-screen bg-dark-base font-sans">
    <div class="w-1/3 h-full bg-dark-card shadow-lg overflow-y-auto border-r" style="border-color: var(--accent-primary);">
        <form action="/index?pagina=checkout_editor&id=<?php echo $id_produto; ?>" method="post" enctype="multipart/form-data" class="p-6">
            <div class="flex items-center mb-6 border-b border-dark-border pb-4">
                <a href="/index?pagina=produtos" class="mr-4 transition-colors" style="color: var(--accent-primary);" onmouseover="this.style.color='var(--accent-primary-hover)'" onmouseout="this.style.color='var(--accent-primary)'">
                     <i data-lucide="arrow-left-circle" class="w-7 h-7"></i>
                </a>
                <div>
                    <h1 class="text-2xl font-bold text-white">Editor de Checkout</h1>
                    <p class="text-sm text-gray-400 flex items-center gap-2">
                        Produto: <?php echo htmlspecialchars($produto['nome']); ?>
                        <?php if($current_gateway == 'pushinpay'): ?>
                            <span class="bg-green-900/30 text-green-400 text-xs font-bold px-2 py-0.5 rounded border border-green-500/50">PushinPay</span>
                        <?php else: ?>
                            <span class="bg-blue-900/30 text-blue-400 text-xs font-bold px-2 py-0.5 rounded border border-blue-500/50">Mercado Pago</span>
                        <?php endif; ?>
                    </p>
                </div>
            </div>

            <?php echo $mensagem; ?>
            
             <div class="mb-4 bg-blue-900/20 border border-blue-500/30 text-blue-300 p-3 rounded-lg text-sm">
                <p><strong class="text-blue-200">Dica:</strong> Arraste e solte os blocos na pré-visualização à direita para reordenar a página de checkout.</p>
            </div>


            <div class="space-y-8">
                <!-- Seção Resumo da Compra -->
                <div>
                    <h2 class="text-xl font-semibold mb-4 text-white flex items-center gap-2">
                        <i data-lucide="shopping-bag" class="w-5 h-5" style="color: var(--accent-primary);"></i>
                        Resumo da Compra
                    </h2>
                    <div class="space-y-4 p-4 border border-dark-border rounded-lg bg-dark-elevated">
                        <div>
                            <label for="summary_product_name" class="block text-gray-300 text-sm font-semibold mb-2">Nome do Produto no Checkout</label>
                            <input type="text" id="summary_product_name" name="summary_product_name" class="form-input-sm" value="<?php echo htmlspecialchars($checkout_config['summary']['product_name'] ?? $produto['nome']); ?>">
                             <p class="text-xs text-gray-400 mt-1">Por padrão, usa o nome original do produto.</p>
                        </div>
                        <div>
                            <label for="preco_anterior" class="block text-gray-300 text-sm font-semibold mb-2">Preço Original (De)</label>
                            <input type="text" id="preco_anterior" name="preco_anterior" class="form-input-sm" placeholder="Ex: 99,90" value="<?php echo !empty($produto['preco_anterior']) ? htmlspecialchars(number_format($produto['preco_anterior'], 2, ',', '.')) : ''; ?>">
                            <p class="text-xs text-gray-400 mt-1">Deixe em branco para não exibir o preço cortado.</p>
                        </div>
                        <div>
                            <label for="summary_discount_text" class="block text-gray-300 text-sm font-semibold mb-2">Texto de Desconto (Opcional)</label>
                            <input type="text" id="summary_discount_text" name="summary_discount_text" class="form-input-sm" placeholder="Ex: 30% OFF" value="<?php echo htmlspecialchars($checkout_config['summary']['discount_text'] ?? ''); ?>">
                            <p class="text-xs text-gray-400 mt-1">Exibido como um selo de destaque no produto.</p>
                        </div>
                    </div>
                </div>

                <!-- Configurações Visuais -->
                <div>
                    <h2 class="text-xl font-semibold mb-4 text-white flex items-center gap-2">
                        <i data-lucide="palette" class="w-5 h-5" style="color: var(--accent-primary);"></i>
                        Aparência
                    </h2>
                    <div class="space-y-4 p-4 border border-dark-border rounded-lg bg-dark-elevated">
                        <div>
                            <label for="backgroundColor" class="block text-gray-300 text-sm font-semibold mb-2">Cor de Fundo da Página</label>
                            <div class="flex items-center space-x-2">
                               <input type="color" id="backgroundColorPicker" value="<?php echo htmlspecialchars($checkout_config['backgroundColor'] ?? '#f3f4f6'); ?>" class="p-1 h-10 w-14 block bg-dark-card border border-dark-border cursor-pointer rounded-lg">
                               <input type="text" id="backgroundColor" name="backgroundColor" class="w-full px-4 py-2 bg-dark-card border border-dark-border rounded-lg text-sm text-white" value="<?php echo htmlspecialchars($checkout_config['backgroundColor'] ?? '#f3f4f6'); ?>">
                            </div>
                        </div>
                        <div>
                            <label for="accentColor" class="block text-gray-300 text-sm font-semibold mb-2">Cor de Destaque (Cabeçalho/Botões)</label>
                            <div class="flex items-center space-x-2">
                               <input type="color" id="accentColorPicker" value="<?php echo htmlspecialchars($checkout_config['accentColor'] ?? '#00A3FF'); ?>" class="p-1 h-10 w-14 block bg-dark-card border border-dark-border cursor-pointer rounded-lg">
                               <input type="text" id="accentColor" name="accentColor" class="w-full px-4 py-2 bg-dark-card border border-dark-border rounded-lg text-sm text-white" value="<?php echo htmlspecialchars($checkout_config['accentColor'] ?? '#00A3FF'); ?>">
                            </div>
                        </div>
                        
                        <!-- INÍCIO: BANNERS MÚLTIPLOS (PRINCIPAL) -->
                        <div>
                            <label class="block text-gray-300 text-sm font-semibold mb-2">Banners Principais</label>
                            <div class="space-y-2 p-3 bg-dark-card rounded-lg border border-dark-border">
                                <?php 
                                $current_banners = $checkout_config['banners'] ?? [];
                                if (empty($current_banners) && !empty($checkout_config['bannerUrl'])) {
                                    $current_banners = [$checkout_config['bannerUrl']];
                                }
                                if (empty($current_banners)): ?>
                                    <p class="text-xs text-gray-400">Nenhum banner principal salvo.</p>
                                <?php else: ?>
                                    <?php foreach ($current_banners as $banner): ?>
                                       <?php 
                                       // Garantir que o caminho tenha / no início se não tiver
                                       $banner_path = $banner;
                                       if (!empty($banner_path) && strpos($banner_path, '/') !== 0 && strpos($banner_path, 'http') !== 0) {
                                           $banner_path = '/' . ltrim($banner_path, '/');
                                       }
                                       ?>
                                       <div class="flex items-center space-x-2">
                                           <img src="<?php echo htmlspecialchars($banner_path); ?>?t=<?php echo time(); ?>" class="h-10 w-auto rounded" onerror="this.src='data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'100\' height=\'100\'%3E%3Crect fill=\'%23ccc\' width=\'100\' height=\'100\'/%3E%3Ctext x=\'50%25\' y=\'50%25\' text-anchor=\'middle\' dy=\'.3em\' fill=\'%23999\'%3EErro%3C/text%3E%3C/svg%3E';">
                                           <span class="text-xs text-gray-300 truncate flex-1"><?php echo htmlspecialchars(basename($banner)); ?></span>
                                           <label class="text-sm flex items-center text-red-400 cursor-pointer">
                                               <input type="checkbox" name="remove_banners[]" value="<?php echo htmlspecialchars($banner); ?>" class="h-4 w-4 mr-1 text-red-400 focus:ring-red-500 border-dark-border rounded bg-dark-card">Remover
                                           </label>
                                       </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                            <label class="block text-gray-300 text-xs font-semibold mb-1 mt-3">Adicionar novos banners principais:</label>
                            <input type="file" name="add_banner_files[]" multiple accept="image/*" class="w-full text-sm text-gray-400 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:text-white"/>
                        </div>
                        
                        <!-- INÍCIO: BANNERS MÚLTIPLOS (LATERAL) -->
                         <div>
                            <label class="block text-gray-300 text-sm font-semibold mb-2">Banners Laterais</label>
                            <p class="text-xs text-gray-400 mt-1 mb-2">Visível na lateral em telas grandes.</p>
                            <div class="space-y-2 p-3 bg-dark-card rounded-lg border border-dark-border">
                                <?php 
                                $current_side_banners = $checkout_config['sideBanners'] ?? [];
                                if (empty($current_side_banners) && !empty($checkout_config['sideBannerUrl'])) {
                                    $current_side_banners = [$checkout_config['sideBannerUrl']];
                                }
                                if (empty($current_side_banners)): ?>
                                    <p class="text-xs text-gray-400">Nenhum banner lateral salvo.</p>
                                <?php else: ?>
                                    <?php foreach ($current_side_banners as $banner): ?>
                                       <?php 
                                       // Garantir que o caminho tenha / no início se não tiver
                                       $banner_path = $banner;
                                       if (!empty($banner_path) && strpos($banner_path, '/') !== 0 && strpos($banner_path, 'http') !== 0) {
                                           $banner_path = '/' . ltrim($banner_path, '/');
                                       }
                                       ?>
                                       <div class="flex items-center space-x-2">
                                           <img src="<?php echo htmlspecialchars($banner_path); ?>?t=<?php echo time(); ?>" class="h-10 w-auto rounded" onerror="this.src='data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'100\' height=\'100\'%3E%3Crect fill=\'%23ccc\' width=\'100\' height=\'100\'/%3E%3Ctext x=\'50%25\' y=\'50%25\' text-anchor=\'middle\' dy=\'.3em\' fill=\'%23999\'%3EErro%3C/text%3E%3C/svg%3E';">
                                           <span class="text-xs text-gray-300 truncate flex-1"><?php echo htmlspecialchars(basename($banner)); ?></span>
                                           <label class="text-sm flex items-center text-red-400 cursor-pointer">
                                               <input type="checkbox" name="remove_side_banners[]" value="<?php echo htmlspecialchars($banner); ?>" class="h-4 w-4 mr-1 text-red-400 focus:ring-red-500 border-dark-border rounded bg-dark-card">Remover
                                           </label>
                                       </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                            <label class="block text-gray-300 text-xs font-semibold mb-1 mt-3">Adicionar novos banners laterais:</label>
                            <input type="file" name="add_side_banner_files[]" multiple accept="image/*" class="w-full text-sm text-gray-400 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-sm file:font-semibold file:text-white"/>
                        </div>
                        
                    </div>
                </div>

                <!-- NOVA SEÇÃO: Order Bumps (Múltiplos) -->
                <div>
                    <h2 class="text-xl font-semibold mb-4 text-white flex items-center gap-2">
                        <i data-lucide="gift" class="w-5 h-5" style="color: var(--accent-primary);"></i>
                        Order Bumps
                    </h2>
                    <div class="p-4 border border-dark-border rounded-lg bg-dark-elevated space-y-4">
                        
                        <?php if($current_gateway == 'pushinpay'): ?>
                            <div class="bg-blue-900/20 border-l-4 border-blue-500 p-4 mb-4">
                                <p class="text-sm text-blue-300">Listando apenas produtos configurados com <strong class="text-blue-200">PushinPay</strong>.</p>
                            </div>
                        <?php endif; ?>

                        <div id="order-bumps-container">
                            <?php foreach ($order_bumps as $index => $bump): ?>
                                <div class="order-bump-item p-4 border border-dark-border rounded-lg bg-dark-card mb-3" data-index="<?php echo $index; ?>">
                                    <div class="flex justify-between items-center mb-3 cursor-grab">
                                        <h3 class="font-bold text-white flex items-center gap-2"><i data-lucide="grip-vertical" class="w-5 h-5 text-gray-400"></i> Oferta #<?php echo $index + 1; ?></h3>
                                        <button type="button" class="remove-order-bump text-red-400 hover:text-red-300 transition-colors">
                                            <i data-lucide="trash-2" class="w-5 h-5"></i>
                                        </button>
                                    </div>
                                    <div class="space-y-3">
                                        <div>
                                            <label class="block text-gray-300 text-sm font-semibold mb-2">Produto da Oferta</label>
                                            <select name="orderbump_product_id[]" class="form-input-sm">
                                                <option value="">-- Selecione um produto --</option>
                                                <?php foreach ($lista_produtos_orderbump as $prod_ob): ?>
                                                    <option value="<?php echo $prod_ob['id']; ?>" <?php echo ($bump['offer_product_id'] == $prod_ob['id']) ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($prod_ob['nome']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="block text-gray-300 text-sm font-semibold mb-2">Título da Oferta</label>
                                            <input type="text" name="orderbump_headline[]" value="<?php echo htmlspecialchars($bump['headline']); ?>" class="form-input-sm">
                                        </div>
                                        <div>
                                            <label class="block text-gray-300 text-sm font-semibold mb-2">Descrição da Oferta</label>
                                            <textarea name="orderbump_description[]" rows="3" class="form-input-sm"><?php echo htmlspecialchars($bump['description']); ?></textarea>
                                        </div>
                                        <div class="flex items-center">
                                            <input type="checkbox" name="orderbump_is_active[<?php echo $index; ?>]" value="1" class="form-checkbox" <?php echo ($bump['is_active'] ?? true) ? 'checked' : ''; ?>>
                                            <label class="ml-2 text-sm text-gray-300">Ativar esta oferta</label>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" id="add-order-bump" class="w-full bg-dark-card text-gray-300 font-semibold py-2 px-4 rounded-lg hover:text-white transition duration-300 flex items-center justify-center gap-2 border border-dark-border" onmouseover="this.style.backgroundColor='var(--accent-primary)'" onmouseout="this.style.backgroundColor=''">
                            <i data-lucide="plus-circle" class="w-5 h-5"></i>
                            Adicionar Oferta
                        </button>
                    </div>
                </div>

                <!-- Cabeçalho Principal -->
                <div>
                    <h2 class="text-xl font-semibold mb-4 text-white flex items-center gap-2">
                        <i data-lucide="heading" class="w-5 h-5" style="color: var(--accent-primary);"></i>
                        Cabeçalho Principal
                    </h2>
                    <div class="p-4 border border-dark-border rounded-lg bg-dark-elevated">
                        <div class="flex items-start space-x-4">
                            <div class="flex items-center h-5"><input id="header_enabled" name="header_enabled" type="checkbox" <?php echo ($checkout_config['header']['enabled'] ?? true) ? 'checked' : ''; ?> class="form-checkbox"></div>
                            <div class="text-sm">
                                <label for="header_enabled" class="font-bold text-white text-base">Ativar seção de título</label>
                                <p class="text-gray-400">Exibe o título principal e o subtítulo do checkout.</p>
                            </div>
                        </div>
                         <div class="mt-4 space-y-3">
                             <div>
                                 <label for="header_title" class="block text-gray-300 text-sm font-semibold mb-2">Título Principal</label>
                                 <input type="text" name="header_title" id="header_title" value="<?php echo htmlspecialchars($checkout_config['header']['title'] ?? 'Finalize sua Compra'); ?>" class="form-input-sm">
                             </div>
                             <div>
                                 <label for="header_subtitle" class="block text-gray-300 text-sm font-semibold mb-2">Subtítulo</label>
                                 <input type="text" name="header_subtitle" id="header_subtitle" value="<?php echo htmlspecialchars($checkout_config['header']['subtitle'] ?? 'Ambiente 100% seguro'); ?>" class="form-input-sm">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Campos do Cliente -->
                <div>
                    <h2 class="text-xl font-semibold mb-4 text-white flex items-center gap-2">
                        <i data-lucide="user" class="w-5 h-5" style="color: var(--accent-primary);"></i>
                        Campos do Cliente
                    </h2>
                        <div class="p-4 border border-dark-border rounded-lg bg-dark-elevated space-y-4">
                        <div class="flex items-start space-x-4">
                            <div class="text-sm">
                                <label class="font-bold text-white text-base">Campo CPF</label>
                                <p class="text-gray-400">O campo CPF é sempre obrigatório no checkout.</p>
                            </div>
                        </div>
                        <div class="flex items-start space-x-4">
                            <div class="text-sm">
                                <label class="font-bold text-white text-base">Campo Telefone</label>
                                <p class="text-gray-400">O campo Telefone é sempre obrigatório no checkout.</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Métodos de Pagamento -->
                <div>
                    <h2 class="text-xl font-semibold mb-4 text-white flex items-center gap-2">
                        <i data-lucide="credit-card" class="w-5 h-5" style="color: var(--accent-primary);"></i>
                        Métodos de Pagamento
                    </h2>
                     <div class="p-4 border border-dark-border rounded-lg bg-dark-elevated space-y-4">
                        
                        <!-- ALTERAÇÃO AQUI: Lógica para travar opções se for PushinPay -->
                        <?php if ($current_gateway === 'pushinpay'): ?>
                            <div class="bg-orange-900/20 border-l-4 border-orange-500 p-4 mb-4">
                                <div class="flex">
                                    <div class="flex-shrink-0">
                                        <i data-lucide="alert-circle" class="text-orange-400 h-5 w-5"></i>
                                    </div>
                                    <div class="ml-3">
                                        <p class="text-sm text-orange-300">
                                            Este produto usa o gateway <strong class="text-orange-200">PushinPay</strong>. Apenas o método <strong class="text-orange-200">Pix</strong> está disponível e habilitado automaticamente.
                                        </p>
                                    </div>
                                </div>
                                <input type="hidden" name="payment_pix_enabled" value="on">
                            </div>
                            
                            <!-- Exibição Visual Apenas (Desabilitado) -->
                            <div class="opacity-50 pointer-events-none">
                                <div class="flex items-start space-x-4 mb-2">
                                    <div class="flex items-center h-5"><input type="checkbox" checked disabled class="form-checkbox"></div>
                                    <div class="text-sm"><label class="font-bold text-white text-base">Pix</label></div>
                                </div>
                                <div class="flex items-start space-x-4 mb-2">
                                    <div class="flex items-center h-5"><input type="checkbox" disabled class="form-checkbox"></div>
                                    <div class="text-sm"><label class="font-bold text-white text-base">Cartão de Crédito</label></div>
                                </div>
                                <div class="flex items-start space-x-4">
                                    <div class="flex items-center h-5"><input type="checkbox" disabled class="form-checkbox"></div>
                                    <div class="text-sm"><label class="font-bold text-white text-base">Boleto</label></div>
                                </div>
                            </div>

                        <?php else: ?>
                            <!-- Opções Normais para Mercado Pago -->
                            <div class="flex items-start space-x-4">
                                <div class="flex items-center h-5"><input id="payment_credit_card_enabled" name="payment_credit_card_enabled" type="checkbox" <?php echo ($payment_methods_config['credit_card'] ?? true) ? 'checked' : ''; ?> class="form-checkbox"></div>
                                <div class="text-sm">
                                    <label for="payment_credit_card_enabled" class="font-bold text-white text-base">Cartão de Crédito</label>
                                    <p class="text-gray-400">Permitir pagamentos via cartão de crédito.</p>
                                </div>
                            </div>
                             <div class="flex items-start space-x-4">
                                <div class="flex items-center h-5"><input id="payment_pix_enabled" name="payment_pix_enabled" type="checkbox" <?php echo ($payment_methods_config['pix'] ?? true) ? 'checked' : ''; ?> class="form-checkbox"></div>
                                <div class="text-sm">
                                    <label for="payment_pix_enabled" class="font-bold text-white text-base">Pix</label>
                                     <p class="text-gray-400">Permitir pagamentos via Pix.</p>
                                </div>
                            </div>
                            <div class="flex items-start space-x-4">
                                <div class="flex items-center h-5"><input id="payment_ticket_enabled" name="payment_ticket_enabled" type="checkbox" <?php echo ($payment_methods_config['ticket'] ?? true) ? 'checked' : ''; ?> class="form-checkbox"></div>
                                <div class="text-sm">
                                    <label for="payment_ticket_enabled" class="font-bold text-white text-base">Boleto</label>
                                    <p class="text-gray-400">Permitir pagamentos via boleto bancário.</p>
                                </div>
                            </div>
                        <?php endif; ?>

                    </div>
                </div>

                <!-- Cronômetro -->
                <div>
                    <h2 class="text-xl font-semibold mb-4 text-white flex items-center gap-2">
                        <i data-lucide="clock" class="w-5 h-5" style="color: var(--accent-primary);"></i>
                        Cronômetro de Escassez
                    </h2>
                    <div class="p-4 border border-dark-border rounded-lg bg-dark-elevated">
                        <div class="flex items-start space-x-4">
                            <div class="flex items-center h-5"><input id="timer_enabled" name="timer_enabled" type="checkbox" <?php echo ($checkout_config['timer']['enabled'] ?? false) ? 'checked' : ''; ?> class="form-checkbox"></div>
                            <div class="text-sm">
                                <label for="timer_enabled" class="font-bold text-white text-base">Ativar cronômetro</label>
                                <p class="text-gray-400">Mostra um contador regressivo para criar urgência.</p>
                            </div>
                        </div>
                         <div class="mt-4 space-y-3">
                             <div>
                                 <label for="timer_text" class="block text-gray-300 text-sm font-semibold mb-2">Texto Persuasivo</label>
                                 <input type="text" name="timer_text" id="timer_text" value="<?php echo htmlspecialchars($checkout_config['timer']['text'] ?? 'Esta oferta expira em:'); ?>" class="form-input-sm">
                             </div>
                             <div>
                                 <label for="timer_minutes" class="block text-gray-300 text-sm font-semibold mb-2">Duração (minutos)</label>
                                 <input type="number" name="timer_minutes" id="timer_minutes" value="<?php echo htmlspecialchars($checkout_config['timer']['minutes'] ?? 15); ?>" class="form-input-sm w-32">
                            </div>
                            <div>
                                <label for="timer_bgcolor" class="block text-gray-300 text-sm font-semibold mb-2">Cor de Fundo do Cronômetro</label>
                                <div class="flex items-center space-x-2">
                                   <input type="color" id="timerBgColorPicker" value="<?php echo htmlspecialchars($checkout_config['timer']['bgcolor'] ?? '#000000'); ?>" class="p-1 h-10 w-14 block bg-dark-card border border-dark-border cursor-pointer rounded-lg">
                                   <input type="text" id="timer_bgcolor" name="timer_bgcolor" class="w-full px-4 py-2 bg-dark-card border border-dark-border rounded-lg text-sm text-white" value="<?php echo htmlspecialchars($checkout_config['timer']['bgcolor'] ?? '#000000'); ?>">
                                </div>
                            </div>
                            <div>
                                <label for="timer_textcolor" class="block text-gray-300 text-sm font-semibold mb-2">Cor do Texto do Cronômetro</label>
                                <div class="flex items-center space-x-2">
                                   <input type="color" id="timerTextColorPicker" value="<?php echo htmlspecialchars($checkout_config['timer']['textcolor'] ?? '#FFFFFF'); ?>" class="p-1 h-10 w-14 block bg-dark-card border border-dark-border cursor-pointer rounded-lg">
                                   <input type="text" id="timer_textcolor" name="timer_textcolor" class="w-full px-4 py-2 bg-dark-card border border-dark-border rounded-lg text-sm text-white" value="<?php echo htmlspecialchars($checkout_config['timer']['textcolor'] ?? '#FFFFFF'); ?>">
                                </div>
                            </div>
                            <div class="flex items-center space-x-3 pt-2">
                                <input type="checkbox" id="timer_sticky" name="timer_sticky" class="form-checkbox" <?php echo ($checkout_config['timer']['sticky'] ?? true) ? 'checked' : ''; ?>>
                                <label for="timer_sticky" class="text-sm font-medium text-gray-300">Fixar cronômetro no topo ao rolar</label>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Notificação de Vendas -->
                <div>
                    <h2 class="text-xl font-semibold mb-4 text-white flex items-center gap-2">
                        <i data-lucide="bell" class="w-5 h-5" style="color: var(--accent-primary);"></i>
                        Notificações de Venda
                    </h2>
                    <div class="p-4 border border-dark-border rounded-lg bg-dark-elevated">
                         <div class="flex items-start space-x-4">
                            <div class="flex items-center h-5"><input id="sales_notification_enabled" name="sales_notification_enabled" type="checkbox" <?php echo ($checkout_config['salesNotification']['enabled'] ?? false) ? 'checked' : ''; ?> class="form-checkbox"></div>
                            <div class="text-sm">
                                <label for="sales_notification_enabled" class="font-bold text-white text-base">Ativar notificações</label>
                                <p class="text-gray-400">Mostra pop-ups de pessoas comprando o produto.</p>
                            </div>
                        </div>
                        <div class="mt-4 space-y-3">
                             <div>
                                 <label for="sales_notification_product" class="block text-gray-300 text-sm font-semibold mb-2">Nome do Produto na Notificação</label>
                                 <input type="text" name="sales_notification_product" id="sales_notification_product" value="<?php echo htmlspecialchars($checkout_config['salesNotification']['product'] ?? $produto['nome']); ?>" class="form-input-sm">
                             </div>
                             <div>
                                 <label for="sales_notification_names" class="block text-gray-300 text-sm font-semibold mb-2">Nomes dos Compradores (um por linha)</label>
                                 <textarea name="sales_notification_names" id="sales_notification_names" rows="5" class="form-input-sm" placeholder="João S.&#10;Maria C.&#10;Carlos A."><?php echo htmlspecialchars($checkout_config['salesNotification']['names'] ?? ''); ?></textarea>
                            </div>
                            <div>
                                <label for="sales_notification_tempo_exibicao" class="block text-gray-300 text-sm font-semibold mb-2">Tempo de Exibição da Notificação (segundos)</label>
                                <input type="number" name="sales_notification_tempo_exibicao" id="sales_notification_tempo_exibicao" value="<?php echo htmlspecialchars($checkout_config['salesNotification']['tempo_exibicao'] ?? 5); ?>" class="form-input-sm w-32" min="1">
                                <p class="text-xs text-gray-400 mt-1">Duração que cada pop-up ficará visível.</p>
                            </div>
                            <div>
                                <label for="sales_notification_intervalo_notificacao" class="block text-gray-300 text-sm font-semibold mb-2">Intervalo entre Notificações (segundos)</label>
                                <input type="number" name="sales_notification_intervalo_notificacao" id="sales_notification_intervalo_notificacao" value="<?php echo htmlspecialchars($checkout_config['salesNotification']['intervalo_notificacao'] ?? 10); ?>" class="form-input-sm w-32" min="1">
                                <p class="text-xs text-gray-400 mt-1">Tempo de espera entre um pop-up e outro.</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Rastreamento & Pixels -->
                <div>
                    <h2 class="text-xl font-semibold mb-4 text-white flex items-center gap-2">
                        <i data-lucide="activity" class="w-5 h-5" style="color: var(--accent-primary);"></i>
                        Rastreamento & Pixels
                    </h2>
                    <div class="space-y-4 p-4 border border-dark-border rounded-lg bg-dark-elevated">
                        <div>
                            <label for="facebookPixelId" class="block text-gray-300 text-sm font-semibold mb-2">ID do Pixel do Facebook</label>
                            <input type="text" id="facebookPixelId" name="facebookPixelId" class="form-input-sm" placeholder="Apenas os números" value="<?php echo htmlspecialchars($tracking_config['facebookPixelId'] ?? ''); ?>">
                        </div>
                        <div>
                            <label for="facebookApiToken" class="block text-gray-300 text-sm font-semibold mb-2">Token da API de Conversões (Facebook)</label>
                            <input type="text" id="facebookApiToken" name="facebookApiToken" class="form-input-sm" placeholder="Cole seu token de acesso aqui" value="<?php echo htmlspecialchars($tracking_config['facebookApiToken'] ?? ''); ?>">
                        </div>
                        <div>
                            <label for="googleAnalyticsId" class="block text-gray-300 text-sm font-semibold mb-2">ID do Google Analytics (GA4)</label>
                            <input type="text" id="googleAnalyticsId" name="googleAnalyticsId" class="form-input-sm" placeholder="Ex: G-XXXXXXXXXX" value="<?php echo htmlspecialchars($tracking_config['googleAnalyticsId'] ?? ''); ?>">
                        </div>
                        <div>
                            <label for="googleAdsId" class="block text-gray-300 text-sm font-semibold mb-2">ID de Conversão do Google Ads</label>
                            <input type="text" id="googleAdsId" name="googleAdsId" class="form-input-sm" placeholder="Ex: AW-XXXXXXXXX" value="<?php echo htmlspecialchars($tracking_config['googleAdsId'] ?? ''); ?>">
                        </div>
                        
                        <div class="pt-4 mt-4 border-t border-dark-border">
                            <h3 class="text-lg font-semibold mb-3 text-white">Eventos do Facebook</h3>
                            <div class="space-y-3">
                                <?php
                                function render_event_toggle($platform, $event_key, $label, $events_array) {
                                    $name = htmlspecialchars($platform . '_event_' . $event_key);
                                    $is_checked_by_default = in_array($event_key, ['purchase', 'initiate_checkout']);
                                    $checked = isset($events_array[$event_key]) ? $events_array[$event_key] : $is_checked_by_default;
                                    $checked_attr = $checked ? 'checked' : '';
                                    echo "<div class='flex items-center justify-between p-2 bg-dark-card rounded-md border border-dark-border'>
                                            <label for='{$name}' class='text-sm font-medium text-gray-300'>{$label}</label>
                                            <label for='{$name}' class='relative inline-flex items-center cursor-pointer'>
                                                <input type='checkbox' id='{$name}' name='{$name}' class='sr-only peer' {$checked_attr}>
                                                <div class='w-11 h-6 bg-dark-elevated rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[\"\"] after:absolute after:top-0.5 after:left-[2px] after:bg-white after:border-dark-border after:border after:rounded-full after:h-5 after:w-5 after:transition-all' style='peer-checked:background-color: var(--accent-primary);'></div>
                                            </label>
                                          </div>";
                                }
                                render_event_toggle('fb', 'purchase', 'Compra Aprovada', $fb_events);
                                render_event_toggle('fb', 'initiate_checkout', 'Carrinho Abandonado (InitiateCheckout)', $fb_events);
                                render_event_toggle('fb', 'pending', 'Pagamento Pendente', $fb_events);
                                render_event_toggle('fb', 'rejected', 'Cartão Recusado', $fb_events);
                                render_event_toggle('fb', 'refund', 'Pagamento Estornado (Reembolso)', $fb_events);
                                render_event_toggle('fb', 'chargeback', 'Chargeback', $fb_events);
                                ?>
                            </div>
                        </div>

                        <div class="pt-4 mt-4 border-t border-dark-border">
                            <h3 class="text-lg font-semibold mb-3 text-white">Eventos do Google</h3>
                            <div class="space-y-3">
                                 <?php 
                                 render_event_toggle('gg', 'purchase', 'Compra Aprovada', $gg_events);
                                 render_event_toggle('gg', 'initiate_checkout', 'Início de Checkout', $gg_events);
                                 render_event_toggle('gg', 'pending', 'Pagamento Pendente', $gg_events);
                                 render_event_toggle('gg', 'rejected', 'Cartão Recusado', $gg_events);
                                 render_event_toggle('gg', 'refund', 'Pagamento Estornado (Reembolso)', $gg_events);
                                 render_event_toggle('gg', 'chargeback', 'Chargeback', $gg_events);
                                 ?>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Recursos Adicionais -->
                <div>
                     <h2 class="text-xl font-semibold mb-4 text-white flex items-center gap-2">
                        <i data-lucide="settings" class="w-5 h-5" style="color: var(--accent-primary);"></i>
                        Recursos Adicionais
                     </h2>
                     <div class="space-y-4 p-4 border border-dark-border rounded-lg bg-dark-elevated">
                        <div>
                            <label for="youtubeUrl" class="block text-gray-300 text-sm font-semibold mb-2">URL do Vídeo do YouTube (Opcional)</label>
                            <input type="url" id="youtubeUrl" name="youtubeUrl" class="form-input-sm" placeholder="https://www.youtube.com/watch?v=..." value="<?php echo htmlspecialchars($checkout_config['youtubeUrl'] ?? ''); ?>">
                        </div>
                        <div>
                            <label for="redirectUrl" class="block text-gray-300 text-sm font-semibold mb-2">Redirecionar após Compra (Opcional)</label>
                            <input type="url" id="redirectUrl" name="redirectUrl" class="form-input-sm" placeholder="https://suapagina.com/obrigado" value="<?php echo htmlspecialchars($checkout_config['redirectUrl'] ?? ''); ?>">
                             <p class="text-xs text-gray-400 mt-1">Por padrão, o cliente é enviado para uma página de obrigado genérica.</p>
                        </div>
                        <div>
                            <label class="block text-gray-300 text-sm font-semibold mb-2">Redirecionamento de Saída (Back Redirect)</label>
                            <div class="p-3 border border-dark-border rounded-lg bg-dark-card">
                                <div class="flex items-start space-x-3">
                                    <div class="flex items-center h-5"><input id="back_redirect_enabled" name="back_redirect_enabled" type="checkbox" <?php echo ($checkout_config['backRedirect']['enabled'] ?? false) ? 'checked' : ''; ?> class="form-checkbox"></div>
                                    <div class="text-sm">
                                        <label for="back_redirect_enabled" class="font-medium text-white">Ativar redirecionamento de saída</label>
                                        <p class="text-gray-400">Redireciona o usuário para uma URL específica se ele tentar sair da página.</p>
                                    </div>
                                </div>
                                <div class="mt-3">
                                     <label for="back_redirect_url" class="block text-gray-300 text-xs font-semibold mb-1">URL para redirecionamento</label>
                                    <input type="url" id="back_redirect_url" name="back_redirect_url" class="form-input-sm" placeholder="https://suapagina.com/oferta" value="<?php echo htmlspecialchars($checkout_config['backRedirect']['url'] ?? ''); ?>">
                                </div>
                            </div>
                        </div>
                     </div>
                </div>
            </div>
            
            <input type="hidden" name="elementOrder" id="elementOrderInput" value='<?php echo json_encode($element_order); ?>'>

            <div class="mt-8 border-t border-dark-border pt-6">
                <button type="submit" name="salvar_checkout" class="w-full text-white font-bold py-3 px-8 rounded-lg transition duration-300 flex items-center justify-center gap-2 shadow-lg" style="background-color: var(--accent-primary); box-shadow: 0 10px 15px -3px color-mix(in srgb, var(--accent-primary) 20%, transparent);" onmouseover="this.style.backgroundColor='var(--accent-primary-hover)'" onmouseout="this.style.backgroundColor='var(--accent-primary)'">
                    <i data-lucide="save" class="w-5 h-5"></i>
                    Salvar Alterações
                </button>
            </div>
        </form>
    </div>

    <div class="w-2/3 h-full p-8 bg-dark-elevated flex items-center justify-center">
        <div class="w-full max-w-2xl h-[90%] bg-dark-card rounded-2xl shadow-2xl overflow-hidden border" style="border-color: var(--accent-primary);">
            <div class="h-8 bg-dark-elevated flex items-center px-4 border-b border-dark-border">
                <div class="flex space-x-2">
                    <div class="w-3 h-3 bg-red-500 rounded-full"></div>
                    <div class="w-3 h-3 bg-yellow-400 rounded-full"></div>
                    <div class="w-3 h-3 bg-green-500 rounded-full"></div>
                </div>
            </div>
             <iframe id="checkout-preview" src="/checkout?p=<?php echo $produto['checkout_hash']; ?>&preview=true&rand=<?php echo time(); ?>" class="w-full h-full border-0"></iframe>
        </div>
    </div>
</div>
<style>
    .form-checkbox { @apply h-6 w-6 border-dark-border rounded bg-dark-card; accent-color: var(--accent-primary); }
    .form-input-sm { 
        @apply w-full px-4 py-2 bg-dark-card border border-dark-border rounded-lg text-sm text-white placeholder-gray-500; 
        background-color: #1a1f24 !important;
        color: #ffffff !important;
    }
    .form-input-sm select {
        background-color: #1a1f24 !important;
        color: #ffffff !important;
    }
    .form-input-sm select option {
        background-color: #1a1f24 !important;
        color: #ffffff !important;
    }
    .form-input-sm::placeholder {
        color: #6b7280 !important;
    }
    .sortable-ghost { opacity: 0.4; background: color-mix(in srgb, var(--accent-primary) 20%, transparent); }
    
    /* Garantir que todos os inputs fiquem dark */
    input[type="text"],
    input[type="number"],
    input[type="url"],
    input[type="email"],
    textarea,
    select {
        background-color: #1a1f24 !important;
        color: #ffffff !important;
        border-color: rgba(255, 255, 255, 0.1) !important;
    }
    
    input[type="text"]:focus,
    input[type="number"]:focus,
    input[type="url"]:focus,
    input[type="email"]:focus,
    textarea:focus,
    select:focus {
        border-color: var(--accent-primary) !important;
        outline: none !important;
        box-shadow: 0 0 0 3px color-mix(in srgb, var(--accent-primary) 10%, transparent) !important;
    }
    
    input[type="text"]::placeholder,
    input[type="number"]::placeholder,
    input[type="url"]::placeholder,
    input[type="email"]::placeholder,
    textarea::placeholder {
        color: #6b7280 !important;
    }
    
    select option {
        background-color: #1a1f24 !important;
        color: #ffffff !important;
    }
</style>

<div id="order-bump-template" style="display: none;">
    <div class="order-bump-item p-4 border border-dark-border rounded-lg bg-dark-card mb-3" data-index="NEW_INDEX">
        <div class="flex justify-between items-center mb-3 cursor-grab">
            <h3 class="font-bold text-white flex items-center gap-2"><i data-lucide="grip-vertical" class="w-5 h-5 text-gray-400"></i> Nova Oferta</h3>
            <button type="button" class="remove-order-bump text-red-400 hover:text-red-300 transition-colors">
                <i data-lucide="trash-2" class="w-5 h-5"></i>
            </button>
        </div>
        <div class="space-y-3">
            <div>
                <label class="block text-gray-300 text-sm font-semibold mb-2">Produto da Oferta</label>
                <select name="orderbump_product_id[]" class="form-input-sm">
                    <option value="">-- Selecione um produto --</option>
                    <?php foreach ($lista_produtos_orderbump as $prod_ob): ?>
                        <option value="<?php echo $prod_ob['id']; ?>"><?php echo htmlspecialchars($prod_ob['nome']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-gray-300 text-sm font-semibold mb-2">Título da Oferta</label>
                <input type="text" name="orderbump_headline[]" value="Sim, eu quero aproveitar essa oferta!" class="form-input-sm">
            </div>
            <div>
                <label class="block text-gray-300 text-sm font-semibold mb-2">Descrição da Oferta</label>
                <textarea name="orderbump_description[]" rows="3" class="form-input-sm"></textarea>
            </div>
            <div class="flex items-center">
                <input type="checkbox" name="orderbump_is_active[NEW_INDEX]" value="1" class="form-checkbox" checked>
                <label class="ml-2 text-sm text-gray-300">Ativar esta oferta</label>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    lucide.createIcons();
    const iframe = document.getElementById('checkout-preview');
    const form = document.querySelector('form');

    const syncColorPickers = (pickerId, inputId) => {
        const picker = document.getElementById(pickerId);
        const input = document.getElementById(inputId);
        if(picker && input) {
            picker.addEventListener('input', (e) => { input.value = e.target.value; });
            input.addEventListener('input', (e) => { picker.value = e.target.value; });
        }
    };

    syncColorPickers('backgroundColorPicker', 'backgroundColor');
    syncColorPickers('accentColorPicker', 'accentColor');
    syncColorPickers('timerBgColorPicker', 'timer_bgcolor');
    syncColorPickers('timerTextColorPicker', 'timer_textcolor');
    
    form.addEventListener('submit', () => {
        setTimeout(() => {
            // Adiciona um parâmetro aleatório para forçar o recarregamento do iframe
            iframe.src = iframe.src.split('&rand=')[0] + '&rand=' + new Date().getTime();
        }, 1000); // Aumenta o tempo para dar chance ao salvamento
    });

    const container = document.getElementById('order-bumps-container');
    const addButton = document.getElementById('add-order-bump');
    const template = document.getElementById('order-bump-template');

    const updateBumpIndices = () => {
        container.querySelectorAll('.order-bump-item').forEach((item, index) => {
             item.querySelector('h3').innerHTML = `<i data-lucide="grip-vertical" class="w-5 h-5 text-gray-400"></i> Oferta #${index + 1}`;
             const checkbox = item.querySelector('input[type="checkbox"]');
             if(checkbox) {
                // Garante que o atributo 'name' seja atualizado para o índice correto
                checkbox.name = `orderbump_is_active[${index}]`;
             }
        });
        lucide.createIcons();
    };

    addButton.addEventListener('click', () => {
        const newIndex = container.querySelectorAll('.order-bump-item').length;
        const tempDiv = document.createElement('div');
        // Substitui o placeholder para garantir que o índice esteja correto
        tempDiv.innerHTML = template.innerHTML.replace(/NEW_INDEX/g, newIndex);
        
        const clone = tempDiv.firstElementChild;
        container.appendChild(clone);
        updateBumpIndices();
    });

    container.addEventListener('click', (e) => {
        const removeButton = e.target.closest('.remove-order-bump');
        if (removeButton) {
            removeButton.closest('.order-bump-item').remove();
            updateBumpIndices();
        }
    });

    new Sortable(container, {
        animation: 150,
        handle: '.cursor-grab',
        ghostClass: 'sortable-ghost',
        onEnd: () => {
            updateBumpIndices();
        }
    });
    
    const initializeSortablePreview = () => {
        const iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
        // ATENÇÃO: O seletor foi mudado do 'main > .checkout-content' original (que não existe)
        // para o wrapper real dos elementos: 'div.lg\\:w-2\\/3 > div.bg-white'
        const sortableContainer = iframeDoc.querySelector('div.lg\\:w-2\\/3 > div.bg-white');
        const elementOrderInput = document.getElementById('elementOrderInput');
        
        if (sortableContainer && typeof Sortable !== 'undefined') {
             new Sortable(sortableContainer, {
                animation: 150,
                ghostClass: 'sortable-ghost',
                handle: '.drag-handle',
                // Filtra elementos que não devem ser arrastáveis (ex: <hr>)
                filter: 'hr', 
                onMove: function (evt) {
                    // Impede mover 'hr'
                    return evt.related.tagName !== 'HR';
                },
                onEnd: () => {
                    const order = Array.from(sortableContainer.children)
                                     .map(child => child.dataset.id)
                                     .filter(id => id); // Filtra IDs indefinidos (como 'hr')
                    elementOrderInput.value = JSON.stringify(order);
                }
            });

            // Adiciona alças de arrastar ('drag handles')
            const elements = sortableContainer.querySelectorAll(':scope > section[data-id]');
            elements.forEach(el => {
                 if(el.querySelector('.drag-handle')) return; // Já existe
                const handle = iframeDoc.createElement('div');
                handle.className = 'drag-handle';
                handle.innerHTML = `<i data-lucide="grip-vertical" style="color: black; background: white; padding: 4px; border-radius: 4px; box-shadow: 0 1px 3px rgba(0,0,0,0.2);"></i>`;
                Object.assign(handle.style, { position: 'absolute', top: '12px', right: '12px', cursor: 'grab', zIndex: '10', opacity: '0', transition: 'opacity 0.2s' });
                el.style.position = 'relative'; // Necessário para o 'absolute'
                el.insertBefore(handle, el.firstChild);
                
                // Mostra/esconde a alça
                el.addEventListener('mouseenter', () => handle.style.opacity = '1');
                el.addEventListener('mouseleave', () => handle.style.opacity = '0');
            });
             if(iframe.contentWindow.lucide) {
                iframe.contentWindow.lucide.createIcons();
            }
        } else {
            console.warn("Contêiner arrastável ('.lg\\:w-2\\/3 > .bg-white') não encontrado no iframe.");
        }
    };
    
    iframe.onload = () => {
        // A lógica de arrastar foi desativada temporariamente pois o seletor
        // `main > .checkout-content` não existia no checkout.php.
        // A lógica foi corrigida acima para `div.lg\\:w-2\\/3 > div.bg-white` e reativada.
        initializeSortablePreview();

        const iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
        const salesNotificationDebugger = iframeDoc.getElementById('sales-notification-debugger');
        if (salesNotificationDebugger) {
            salesNotificationDebugger.style.display = 'none';
        }
    };
});
</script>