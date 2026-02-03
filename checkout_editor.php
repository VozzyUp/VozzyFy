<?php
// Editor de Checkout Standalone - Página completa sem layout da plataforma
require_once __DIR__ . '/config/config.php';
include __DIR__ . '/config/load_settings.php';

// Verificar autenticação
if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: /login");
    exit;
}

// Editor de Checkout com Preview - Layout Split-Screen
$mensagem = '';
$produto = null;
$checkout_config = [];
$order_bumps = [];

// Validar e buscar o produto
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: /index?pagina=produtos");
    exit;
}

$id_produto = $_GET['id'];
$usuario_id_logado = $_SESSION['id'] ?? 0;

if ($usuario_id_logado === 0) {
    header("location: /login");
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT * FROM produtos WHERE id = ? AND usuario_id = ?");
    $stmt->execute([$id_produto, $usuario_id_logado]);
    $produto = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$produto) {
        $_SESSION['flash_message'] = "<div class='bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded relative mb-4' role='alert'>Produto não encontrado ou você não tem permissão para acessá-lo.</div>";
        header("Location: /index?pagina=produtos");
        exit;
    }

    $current_gateway = $produto['gateway'] ?? 'mercadopago';
    $checkout_config = json_decode($produto['checkout_config'] ?? '{}', true);

    $stmt_ob = $pdo->prepare("SELECT * FROM order_bumps WHERE main_product_id = ? ORDER BY ordem ASC");
    $stmt_ob->execute([$id_produto]);
    $order_bumps = $stmt_ob->fetchAll(PDO::FETCH_ASSOC);

    $stmt_todos_produtos = $pdo->prepare("SELECT id, nome FROM produtos WHERE id != ? AND usuario_id = ? AND gateway = ?");
    $stmt_todos_produtos->execute([$id_produto, $usuario_id_logado, $current_gateway]);
    $lista_produtos_orderbump = $stmt_todos_produtos->fetchAll(PDO::FETCH_ASSOC);

} catch(PDOException $e) {
    die("Erro ao buscar dados: " . $e->getMessage());
}

// Função de upload múltiplo (segura)
function handle_multiple_uploads($file_key, $prefix, $product_id) {
    require_once __DIR__ . '/helpers/security_helper.php';
    
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
                
                // Apenas JPEG ou PNG para banners do checkout
                $upload_result = validate_image_upload($file_array, 'uploads/', $prefix . '_' . $product_id, 5, true);
                if ($upload_result['success']) {
                    $uploaded_paths[] = $upload_result['file_path'];
                }
            }
        }
    }
    return $uploaded_paths;
}

// Endpoint para renovar token CSRF via AJAX
if (isset($_GET['refresh_csrf']) && $_GET['refresh_csrf'] == '1') {
    require_once __DIR__ . '/helpers/security_helper.php';
    header('Content-Type: application/json');
    $new_token = generate_csrf_token();
    echo json_encode(['csrf_token' => $new_token]);
    exit;
}

// Processar formulário de salvamento
if (isset($_POST['salvar_checkout'])) {
        // Verifica CSRF
        require_once __DIR__ . '/helpers/security_helper.php';
        if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
            // Tenta regenerar token caso tenha expirado (última tentativa antes de mostrar erro)
            $new_token = generate_csrf_token();
            $mensagem = "<div class='bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded relative mb-4' role='alert'>
                <strong>Token CSRF expirado.</strong> Por favor, <a href='#' onclick='window.location.reload(); return false;' class='underline hover:text-white'>recarregue a página</a> e tente novamente. Isso pode acontecer se você deixou o editor aberto por mais de 1 hora.
            </div>";
        } else {
        $pdo->beginTransaction();
        try {
        $stmt_get_config = $pdo->prepare("SELECT checkout_config FROM produtos WHERE id = ? AND usuario_id = ?");
        $stmt_get_config->execute([$id_produto, $usuario_id_logado]);
        $current_config_json = $stmt_get_config->fetchColumn();
        $config_array = json_decode($current_config_json ?: '{}', true);

        // Upload de banners
        $current_banners = $config_array['banners'] ?? [];
        if (empty($current_banners) && !empty($config_array['bannerUrl'])) {
            $current_banners = [$config_array['bannerUrl']];
        }
        
        // Normalizar caminhos existentes (garantir formato consistente)
        $current_banners = array_map(function($banner) {
            if (empty($banner)) return $banner;
            // Se já é URL completa, manter como está
            if (strpos($banner, 'http') === 0) return $banner;
            // Normalizar para começar com /
            $normalized = '/' . ltrim($banner, '/');
            return $normalized;
        }, $current_banners);
        
        // Normalizar também os banners a remover
        $banners_to_remove = $_POST['remove_banners'] ?? [];
        $banners_to_remove = array_map(function($banner) {
            if (empty($banner)) return $banner;
            if (strpos($banner, 'http') === 0) return $banner;
            return '/' . ltrim($banner, '/');
        }, $banners_to_remove);
        
        $final_banners_list = [];
        foreach ($current_banners as $banner_path) {
            // Comparar normalizando ambos os lados
            $normalized_current = $banner_path;
            if (strpos($normalized_current, 'http') !== 0 && strpos($normalized_current, '/') !== 0) {
                $normalized_current = '/' . ltrim($normalized_current, '/');
            }
            
            $should_remove = false;
            foreach ($banners_to_remove as $remove_path) {
                $normalized_remove = $remove_path;
                if (strpos($normalized_remove, 'http') !== 0 && strpos($normalized_remove, '/') !== 0) {
                    $normalized_remove = '/' . ltrim($normalized_remove, '/');
                }
                // Comparar tanto o caminho normalizado quanto sem normalização
                if ($normalized_current === $normalized_remove || 
                    $banner_path === $remove_path ||
                    ltrim($normalized_current, '/') === ltrim($normalized_remove, '/')) {
                    $should_remove = true;
                    break;
                }
            }
            
            if (!$should_remove) {
                $final_banners_list[] = $banner_path;
            } else {
                // Tentar deletar o arquivo em diferentes formatos de caminho
                $paths_to_try = [
                    $banner_path,
                    '/' . ltrim($banner_path, '/'),
                    ltrim($banner_path, '/'),
                    __DIR__ . '/' . ltrim($banner_path, '/'),
                    __DIR__ . $banner_path
                ];
                foreach ($paths_to_try as $path) {
                    if (file_exists($path) && is_file($path)) {
                        @unlink($path);
                        error_log("Checkout Editor: Banner removido: " . $path);
                    }
                }
            }
        }
        
        $newly_uploaded_banners = handle_multiple_uploads('add_banner_files', 'banner', $id_produto);
        $config_array['banners'] = array_merge($final_banners_list, $newly_uploaded_banners);
        
        // Remover campo antigo bannerUrl se existir
        unset($config_array['bannerUrl']);

        $current_side_banners = $config_array['sideBanners'] ?? [];
        if (empty($current_side_banners) && !empty($config_array['sideBannerUrl'])) {
            $current_side_banners = [$config_array['sideBannerUrl']];
        }
        
        // Normalizar caminhos existentes (garantir formato consistente)
        $current_side_banners = array_map(function($banner) {
            if (empty($banner)) return $banner;
            if (strpos($banner, 'http') === 0) return $banner;
            return '/' . ltrim($banner, '/');
        }, $current_side_banners);
        
        // Normalizar também os banners laterais a remover
        $side_banners_to_remove = $_POST['remove_side_banners'] ?? [];
        $side_banners_to_remove = array_map(function($banner) {
            if (empty($banner)) return $banner;
            if (strpos($banner, 'http') === 0) return $banner;
            return '/' . ltrim($banner, '/');
        }, $side_banners_to_remove);
        
        $final_side_banners_list = [];
        foreach ($current_side_banners as $banner_path) {
            // Comparar normalizando ambos os lados
            $normalized_current = $banner_path;
            if (strpos($normalized_current, 'http') !== 0 && strpos($normalized_current, '/') !== 0) {
                $normalized_current = '/' . ltrim($normalized_current, '/');
            }
            
            $should_remove = false;
            foreach ($side_banners_to_remove as $remove_path) {
                $normalized_remove = $remove_path;
                if (strpos($normalized_remove, 'http') !== 0 && strpos($normalized_remove, '/') !== 0) {
                    $normalized_remove = '/' . ltrim($normalized_remove, '/');
                }
                // Comparar tanto o caminho normalizado quanto sem normalização
                if ($normalized_current === $normalized_remove || 
                    $banner_path === $remove_path ||
                    ltrim($normalized_current, '/') === ltrim($normalized_remove, '/')) {
                    $should_remove = true;
                    break;
                }
            }
            
            if (!$should_remove) {
                $final_side_banners_list[] = $banner_path;
            } else {
                // Tentar deletar o arquivo em diferentes formatos de caminho
                $paths_to_try = [
                    $banner_path,
                    '/' . ltrim($banner_path, '/'),
                    ltrim($banner_path, '/'),
                    __DIR__ . '/' . ltrim($banner_path, '/'),
                    __DIR__ . $banner_path
                ];
                foreach ($paths_to_try as $path) {
                    if (file_exists($path) && is_file($path)) {
                        @unlink($path);
                        error_log("Checkout Editor: Banner lateral removido: " . $path);
                    }
                }
            }
        }
        
        $newly_uploaded_side_banners = handle_multiple_uploads('add_side_banner_files', 'sidebanner', $id_produto);
        $config_array['sideBanners'] = array_merge($final_side_banners_list, $newly_uploaded_side_banners);
        
        // Remover campo antigo sideBannerUrl se existir
        unset($config_array['sideBannerUrl']);

        $preco_anterior = !empty($_POST['preco_anterior']) ? floatval(str_replace(',', '.', $_POST['preco_anterior'])) : null;
        $stmt_update_produto = $pdo->prepare("UPDATE produtos SET preco_anterior = ? WHERE id = ? AND usuario_id = ?");
        $stmt_update_produto->execute([$preco_anterior, $id_produto, $usuario_id_logado]);

        $elementOrder = json_decode($_POST['elementOrder'] ?? '[]', true);

        $config_array['backgroundColor'] = $_POST['backgroundColor'] ?? '#f3f4f6';
        $config_array['accentColor'] = $_POST['accentColor'] ?? '#00A3FF';
        $config_array['redirectUrl'] = $_POST['redirectUrl'] ?? '';
        $config_array['youtubeUrl'] = $_POST['youtubeUrl'] ?? '';
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
        // Se não houver campos de tracking no POST, NÃO faz nada - preserva a configuração existente
        
        $config_array['summary'] = ['product_name' => $_POST['summary_product_name'] ?? '', 'discount_text' => $_POST['summary_discount_text'] ?? ''];
        
        $config_array['timer'] = [
            'enabled' => isset($_POST['timer_enabled']),
            'minutes' => (int)($_POST['timer_minutes'] ?? 15),
            'text' => $_POST['timer_text'] ?? 'Esta oferta expira em:',
            'bgcolor' => $_POST['timer_bgcolor'] ?? '#000000',
            'textcolor' => $_POST['timer_textcolor'] ?? '#FFFFFF',
            'sticky' => isset($_POST['timer_sticky'])
        ];

        $config_array['salesNotification'] = [
            'enabled' => isset($_POST['sales_notification_enabled']),
            'names' => $_POST['sales_notification_names'] ?? '',
            'product' => $_POST['sales_notification_product'] ?? '',
            'tempo_exibicao' => (int)($_POST['sales_notification_tempo_exibicao'] ?? 5),
            'intervalo_notificacao' => (int)($_POST['sales_notification_intervalo_notificacao'] ?? 10)
        ];
        
        // Payment Methods - usar estrutura existente do produto
        $payment_methods_config = $checkout_config['paymentMethods'] ?? [];
        if (empty($payment_methods_config) || !isset($payment_methods_config['pix']['gateway'])) {
            // Estrutura antiga
            if ($current_gateway === 'pushinpay') {
                $config_array['paymentMethods'] = ['credit_card' => false, 'pix' => true, 'ticket' => false];
            } else {
                $config_array['paymentMethods'] = [
                    'credit_card' => isset($_POST['payment_credit_card_enabled']),
                    'pix' => isset($_POST['payment_pix_enabled']),
                    'ticket' => isset($_POST['payment_ticket_enabled'])
                ];
            }
        } else {
            // Manter estrutura híbrida existente
            $config_array['paymentMethods'] = $payment_methods_config;
        }

        $config_array['backRedirect'] = ['enabled' => isset($_POST['back_redirect_enabled']), 'url' => $_POST['back_redirect_url'] ?? ''];
        $config_array['elementOrder'] = $elementOrder;

        $config_array['customer_fields'] = [
            'enable_cpf' => true, // CPF sempre obrigatório
            'enable_phone' => true, // Telefone sempre obrigatório
        ];

        // Garantir que arrays vazios sejam salvos como arrays vazios, não null
        if (empty($config_array['banners'])) {
            $config_array['banners'] = [];
        }
        if (empty($config_array['sideBanners'])) {
            $config_array['sideBanners'] = [];
        }
        
        // Log para debug
        error_log("Checkout Editor: Salvando configuração. Banners: " . json_encode($config_array['banners']));
        error_log("Checkout Editor: Salvando configuração. SideBanners: " . json_encode($config_array['sideBanners']));
        
        $config_json = json_encode($config_array, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $stmt = $pdo->prepare("UPDATE produtos SET checkout_config = ? WHERE id = ? AND usuario_id = ?");
        $result = $stmt->execute([$config_json, $id_produto, $usuario_id_logado]);
        
        if (!$result) {
            error_log("Checkout Editor: ERRO ao salvar checkout_config. Erro: " . print_r($stmt->errorInfo(), true));
            throw new Exception("Erro ao salvar configuração do checkout");
        }
        
        error_log("Checkout Editor: Configuração salva com sucesso. Produto ID: $id_produto");
        
        // Order Bumps - só processa se houver campos de order bumps no POST (usuário está na aba de order bumps)
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
        $mensagem = "<div class='bg-green-900/20 border border-green-500 text-green-300 px-4 py-3 rounded relative mb-4' role='alert'>Configurações salvas com sucesso!</div>";

        // Recarrega dados
        $stmt = $pdo->prepare("SELECT * FROM produtos WHERE id = ? AND usuario_id = ?");
        $stmt->execute([$id_produto, $usuario_id_logado]);
        $produto = $stmt->fetch(PDO::FETCH_ASSOC);
        $checkout_config = json_decode($produto['checkout_config'] ?? '{}', true);

        $stmt_ob = $pdo->prepare("SELECT * FROM order_bumps WHERE main_product_id = ? ORDER BY ordem ASC");
        $stmt_ob->execute([$id_produto]);
        $order_bumps = $stmt_ob->fetchAll(PDO::FETCH_ASSOC);

            $stmt_todos_produtos = $pdo->prepare("SELECT id, nome FROM produtos WHERE id != ? AND usuario_id = ? AND gateway = ?");
            $stmt_todos_produtos->execute([$id_produto, $usuario_id_logado, $current_gateway]);
            $lista_produtos_orderbump = $stmt_todos_produtos->fetchAll(PDO::FETCH_ASSOC);

        } catch (Exception $e) {
            $pdo->rollBack();
            $mensagem = "<div class='bg-red-900/20 border border-red-500 text-red-300 px-4 py-3 rounded relative mb-4' role='alert'>Erro ao salvar: " . $e->getMessage() . "</div>";
        }
    }
}

// Preparar variáveis
$tracking_config = $checkout_config['tracking'] ?? [];
if (empty($tracking_config['facebookPixelId']) && !empty($checkout_config['facebookPixelId'])) {
    $tracking_config['facebookPixelId'] = $checkout_config['facebookPixelId'];
}
$tracking_events = $tracking_config['events'] ?? [];
$fb_events = $tracking_events['facebook'] ?? [];
$gg_events = $tracking_events['google'] ?? [];
$default_order = ['banner', 'youtube_video', 'summary', 'order_bump', 'final_summary', 'payment', 'guarantee', 'security_info'];
$element_order = $checkout_config['elementOrder'] ?? $default_order;
if(empty($element_order) || !is_array($element_order)) $element_order = $default_order;
$payment_methods_config = $checkout_config['paymentMethods'] ?? ['credit_card' => false, 'pix' => false, 'ticket' => false];
$customer_fields_config = $checkout_config['customer_fields'] ?? ['enable_cpf' => true, 'enable_phone' => true];
?>

<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Editor de Checkout - <?php echo htmlspecialchars($produto['nome']); ?></title>
    <?php
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
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@latest/Sortable.min.js"></script>
    <style>
        body { margin: 0; padding: 0; overflow: hidden; background-color: #0f172a; }
        .form-input-sm {
            width: 100%;
            padding: 0.5rem 1rem;
            background-color: rgba(26, 31, 36, 0.8);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 0.5rem;
            font-size: 0.875rem;
            color: white;
            transition: all 0.2s ease;
        }
        .form-input-sm:focus {
            outline: none;
            border-color: var(--accent-primary);
            box-shadow: 0 0 0 3px color-mix(in srgb, var(--accent-primary) 10%, transparent);
        }
        .form-checkbox {
            width: 1.25rem;
            height: 1.25rem;
            color: var(--accent-primary);
            background-color: rgba(26, 31, 36, 0.8);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 0.25rem;
            cursor: pointer;
            appearance: none;
            -webkit-appearance: none;
        }
        .form-checkbox:checked {
            background-color: var(--accent-primary);
            border-color: var(--accent-primary);
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 20 20' fill='white'%3E%3Cpath fill-rule='evenodd' d='M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z' clip-rule='evenodd'/%3E%3C/svg%3E");
            background-size: 100% 100%;
            background-position: center;
            background-repeat: no-repeat;
        }
        .sortable-ghost { opacity: 0.4; background: color-mix(in srgb, var(--accent-primary) 20%, transparent); }
        .bg-dark-base { background-color: #0f172a; }
        .bg-dark-card { background-color: #1e293b; }
        .bg-dark-elevated { background-color: #1a1f24; }
        .bg-dark-border { border-color: rgba(255, 255, 255, 0.1); }
        .text-white { color: #ffffff; }
        .text-gray-300 { color: #d1d5db; }
        .text-gray-400 { color: #9ca3af; }
        #resize-handle {
            position: relative;
            z-index: 10;
        }
        #resize-handle:hover {
            background-color: var(--accent-primary) !important;
        }
        #resize-handle::before {
            content: '';
            position: absolute;
            left: 50%;
            top: 50%;
            transform: translate(-50%, -50%);
            width: 2px;
            height: 40px;
            background-color: rgba(255, 255, 255, 0.3);
            border-radius: 2px;
            transition: all 0.2s;
        }
        #resize-handle:hover::before {
            background-color: rgba(255, 255, 255, 0.6);
            height: 60px;
        }
        /* Estilo customizado para inputs de arquivo com cor primária dinâmica */
        input[type="file"]::file-selector-button {
            background-color: var(--accent-primary) !important;
            color: white !important;
            transition: background-color 0.3s ease;
        }
        input[type="file"]::file-selector-button:hover {
            background-color: var(--accent-primary-hover) !important;
        }
    </style>
</head>
<body class="bg-dark-base">
    <div class="flex h-screen overflow-hidden">
        <!-- Sidebar Esquerda (40%) -->
        <div id="sidebar-editor" class="h-full bg-dark-card shadow-lg overflow-y-auto border-r border-dark-border" style="width: 40%; min-width: 300px; max-width: 70%;">
            <div class="sticky top-0 bg-dark-card border-b border-dark-border z-10 p-4">
                <div class="flex items-center justify-between mb-2">
                    <div class="flex items-center gap-3">
                        <a href="/index?pagina=produto_config&id=<?php echo $id_produto; ?>&aba=checkout" style="color: var(--accent-primary);" onmouseover="this.style.color='var(--accent-primary-hover)'" onmouseout="this.style.color='var(--accent-primary)'" class="transition-colors">
                            <i data-lucide="x" class="w-6 h-6"></i>
                        </a>
                        <div>
                            <h1 class="text-xl font-bold text-white">Editor de Checkout</h1>
                            <p class="text-xs text-gray-400"><?php echo htmlspecialchars($produto['nome']); ?></p>
                        </div>
                    </div>
                </div>
                <?php echo $mensagem; ?>
            </div>

            <form action="/checkout_editor.php?id=<?php echo $id_produto; ?>" method="post" enctype="multipart/form-data" class="p-6">
                <?php
                require_once __DIR__ . '/helpers/security_helper.php';
                $csrf_token = generate_csrf_token();
                ?>
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <input type="hidden" name="elementOrder" id="elementOrderInput" value='<?php echo json_encode($element_order); ?>'>

                <div class="space-y-6">
                    <!-- Resumo da Compra -->
                    <div>
                        <h2 class="text-lg font-semibold mb-3 text-white flex items-center gap-2">
                            <i data-lucide="shopping-bag" class="w-5 h-5" style="color: var(--accent-primary);"></i>
                            Resumo da Compra
                        </h2>
                        <div class="bg-dark-elevated p-4 rounded-lg border border-dark-border space-y-3">
                            <div>
                                <label for="summary_product_name" class="block text-gray-300 text-xs font-semibold mb-1">Nome do Produto</label>
                                <input type="text" id="summary_product_name" name="summary_product_name" class="form-input-sm" value="<?php echo htmlspecialchars($checkout_config['summary']['product_name'] ?? $produto['nome']); ?>">
                            </div>
                            <div>
                                <label for="preco_anterior" class="block text-gray-300 text-xs font-semibold mb-1">Preço Anterior (De)</label>
                                <input type="text" id="preco_anterior" name="preco_anterior" class="form-input-sm" placeholder="Ex: 99,90" value="<?php echo !empty($produto['preco_anterior']) ? htmlspecialchars(number_format($produto['preco_anterior'], 2, ',', '.')) : ''; ?>">
                            </div>
                            <div>
                                <label for="summary_discount_text" class="block text-gray-300 text-xs font-semibold mb-1">Texto de Desconto</label>
                                <input type="text" id="summary_discount_text" name="summary_discount_text" class="form-input-sm" placeholder="Ex: 30% OFF" value="<?php echo htmlspecialchars($checkout_config['summary']['discount_text'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>

                    <!-- Aparência -->
                    <div>
                        <h2 class="text-lg font-semibold mb-3 text-white flex items-center gap-2">
                            <i data-lucide="palette" class="w-5 h-5" style="color: var(--accent-primary);"></i>
                            Aparência
                        </h2>
                        <div class="bg-dark-elevated p-4 rounded-lg border border-dark-border space-y-3">
                            <div>
                                <label for="backgroundColor" class="block text-gray-300 text-xs font-semibold mb-1">Cor de Fundo</label>
                                <div class="flex items-center gap-2">
                                    <input type="color" id="backgroundColorPicker" value="<?php echo htmlspecialchars($checkout_config['backgroundColor'] ?? '#f3f4f6'); ?>" class="h-8 w-12 rounded border border-dark-border cursor-pointer">
                                    <input type="text" id="backgroundColor" name="backgroundColor" class="form-input-sm flex-1" value="<?php echo htmlspecialchars($checkout_config['backgroundColor'] ?? '#f3f4f6'); ?>">
                                </div>
                            </div>
                            <div>
                                <label for="accentColor" class="block text-gray-300 text-xs font-semibold mb-1">Cor de Destaque</label>
                                <div class="flex items-center gap-2">
                                    <input type="color" id="accentColorPicker" value="<?php echo htmlspecialchars($checkout_config['accentColor'] ?? '#00A3FF'); ?>" class="h-8 w-12 rounded border border-dark-border cursor-pointer">
                                    <input type="text" id="accentColor" name="accentColor" class="form-input-sm flex-1" value="<?php echo htmlspecialchars($checkout_config['accentColor'] ?? '#00A3FF'); ?>">
                                </div>
                            </div>
                            
                            <!-- Banners Principais -->
                            <div>
                                <label class="block text-gray-300 text-xs font-semibold mb-1">Banners Principais</label>
                                <div class="space-y-2 p-2 bg-dark-card rounded border border-dark-border mb-2 max-h-32 overflow-y-auto">
                                    <?php 
                                    $current_banners = $checkout_config['banners'] ?? [];
                                    if (empty($current_banners) && !empty($checkout_config['bannerUrl'])) {
                                        $current_banners = [$checkout_config['bannerUrl']];
                                    }
                                    if (empty($current_banners)): ?>
                                        <p class="text-xs text-gray-400">Nenhum banner</p>
                                    <?php else: ?>
                                        <?php foreach ($current_banners as $banner): ?>
                                           <div class="flex items-center gap-2 text-xs">
                                               <img src="<?php echo htmlspecialchars($banner); ?>?t=<?php echo time(); ?>" class="h-8 w-auto rounded">
                                               <span class="text-gray-300 truncate flex-1"><?php echo htmlspecialchars(basename($banner)); ?></span>
                                               <label class="text-red-400 cursor-pointer">
                                                   <input type="checkbox" name="remove_banners[]" value="<?php echo htmlspecialchars($banner); ?>" class="w-3 h-3"> Remover
                                               </label>
                                           </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                                <input type="file" name="add_banner_files[]" multiple accept="image/*" class="w-full text-xs text-gray-400 file:mr-2 file:py-1 file:px-3 file:rounded file:border-0 file:text-xs file:font-semibold file:text-white">
                            </div>
                            
                            <!-- Banners Laterais -->
                            <div>
                                <label class="block text-gray-300 text-xs font-semibold mb-1">Banners Laterais</label>
                                <div class="space-y-2 p-2 bg-dark-card rounded border border-dark-border mb-2 max-h-32 overflow-y-auto">
                                    <?php 
                                    $current_side_banners = $checkout_config['sideBanners'] ?? [];
                                    if (empty($current_side_banners) && !empty($checkout_config['sideBannerUrl'])) {
                                        $current_side_banners = [$checkout_config['sideBannerUrl']];
                                    }
                                    if (empty($current_side_banners)): ?>
                                        <p class="text-xs text-gray-400">Nenhum banner</p>
                                    <?php else: ?>
                                        <?php foreach ($current_side_banners as $banner): ?>
                                           <div class="flex items-center gap-2 text-xs">
                                               <img src="<?php echo htmlspecialchars($banner); ?>?t=<?php echo time(); ?>" class="h-8 w-auto rounded">
                                               <span class="text-gray-300 truncate flex-1"><?php echo htmlspecialchars(basename($banner)); ?></span>
                                               <label class="text-red-400 cursor-pointer">
                                                   <input type="checkbox" name="remove_side_banners[]" value="<?php echo htmlspecialchars($banner); ?>" class="w-3 h-3"> Remover
                                               </label>
                                           </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                                <input type="file" name="add_side_banner_files[]" multiple accept="image/*" class="w-full text-xs text-gray-400 file:mr-2 file:py-1 file:px-3 file:rounded file:border-0 file:text-xs file:font-semibold file:text-white">
                            </div>
                        </div>
                    </div>

                    <!-- Cronômetro -->
                    <div>
                        <h2 class="text-lg font-semibold mb-3 text-white flex items-center gap-2">
                            <i data-lucide="clock" class="w-5 h-5" style="color: var(--accent-primary);"></i>
                            Cronômetro
                        </h2>
                        <div class="bg-dark-elevated p-4 rounded-lg border border-dark-border">
                            <div class="flex items-center gap-2 mb-3">
                                <input id="timer_enabled" name="timer_enabled" type="checkbox" <?php echo ($checkout_config['timer']['enabled'] ?? false) ? 'checked' : ''; ?> class="form-checkbox">
                                <label for="timer_enabled" class="text-sm font-medium text-white">Ativar cronômetro</label>
                            </div>
                            <div class="space-y-2">
                                <input type="text" name="timer_text" id="timer_text" value="<?php echo htmlspecialchars($checkout_config['timer']['text'] ?? 'Esta oferta expira em:'); ?>" class="form-input-sm" placeholder="Texto">
                                <div class="grid grid-cols-2 gap-2">
                                    <input type="number" name="timer_minutes" id="timer_minutes" value="<?php echo htmlspecialchars($checkout_config['timer']['minutes'] ?? 15); ?>" class="form-input-sm" placeholder="Minutos">
                                    <div class="flex items-center gap-2">
                                        <input type="checkbox" id="timer_sticky" name="timer_sticky" class="form-checkbox" <?php echo ($checkout_config['timer']['sticky'] ?? true) ? 'checked' : ''; ?>>
                                        <label for="timer_sticky" class="text-xs text-gray-300">Fixar</label>
                                    </div>
                                </div>
                                <div class="grid grid-cols-2 gap-2">
                                    <div>
                                        <label class="block text-xs text-gray-400 mb-1">Fundo</label>
                                        <div class="flex gap-1">
                                            <input type="color" id="timerBgColorPicker" value="<?php echo htmlspecialchars($checkout_config['timer']['bgcolor'] ?? '#000000'); ?>" class="h-6 w-8 rounded border border-dark-border cursor-pointer">
                                            <input type="text" id="timer_bgcolor" name="timer_bgcolor" class="form-input-sm flex-1" value="<?php echo htmlspecialchars($checkout_config['timer']['bgcolor'] ?? '#000000'); ?>">
                                        </div>
                                    </div>
                                    <div>
                                        <label class="block text-xs text-gray-400 mb-1">Texto</label>
                                        <div class="flex gap-1">
                                            <input type="color" id="timerTextColorPicker" value="<?php echo htmlspecialchars($checkout_config['timer']['textcolor'] ?? '#FFFFFF'); ?>" class="h-6 w-8 rounded border border-dark-border cursor-pointer">
                                            <input type="text" id="timer_textcolor" name="timer_textcolor" class="form-input-sm flex-1" value="<?php echo htmlspecialchars($checkout_config['timer']['textcolor'] ?? '#FFFFFF'); ?>">
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Notificações de Venda -->
                    <div>
                        <h2 class="text-lg font-semibold mb-3 text-white flex items-center gap-2">
                            <i data-lucide="bell" class="w-5 h-5" style="color: var(--accent-primary);"></i>
                            Notificações
                        </h2>
                        <div class="bg-dark-elevated p-4 rounded-lg border border-dark-border">
                            <div class="flex items-center gap-2 mb-3">
                                <input id="sales_notification_enabled" name="sales_notification_enabled" type="checkbox" <?php echo ($checkout_config['salesNotification']['enabled'] ?? false) ? 'checked' : ''; ?> class="form-checkbox">
                                <label for="sales_notification_enabled" class="text-sm font-medium text-white">Ativar notificações</label>
                            </div>
                            <div class="space-y-2">
                                <input type="text" name="sales_notification_product" id="sales_notification_product" value="<?php echo htmlspecialchars($checkout_config['salesNotification']['product'] ?? $produto['nome']); ?>" class="form-input-sm" placeholder="Nome do produto">
                                <textarea name="sales_notification_names" id="sales_notification_names" rows="3" class="form-input-sm" placeholder="Nomes (um por linha)"><?php echo htmlspecialchars($checkout_config['salesNotification']['names'] ?? ''); ?></textarea>
                                <div class="grid grid-cols-2 gap-2">
                                    <input type="number" name="sales_notification_tempo_exibicao" id="sales_notification_tempo_exibicao" value="<?php echo htmlspecialchars($checkout_config['salesNotification']['tempo_exibicao'] ?? 5); ?>" class="form-input-sm" placeholder="Tempo (s)">
                                    <input type="number" name="sales_notification_intervalo_notificacao" id="sales_notification_intervalo_notificacao" value="<?php echo htmlspecialchars($checkout_config['salesNotification']['intervalo_notificacao'] ?? 10); ?>" class="form-input-sm" placeholder="Intervalo (s)">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Recursos Adicionais -->
                    <div>
                        <h2 class="text-lg font-semibold mb-3 text-white flex items-center gap-2">
                            <i data-lucide="settings" class="w-5 h-5" style="color: var(--accent-primary);"></i>
                            Recursos
                        </h2>
                        <div class="bg-dark-elevated p-4 rounded-lg border border-dark-border space-y-3">
                            <div>
                                <label for="youtubeUrl" class="block text-gray-300 text-xs font-semibold mb-1">YouTube URL</label>
                                <input type="url" id="youtubeUrl" name="youtubeUrl" class="form-input-sm" placeholder="https://youtube.com/..." value="<?php echo htmlspecialchars($checkout_config['youtubeUrl'] ?? ''); ?>">
                            </div>
                            <div>
                                <label for="redirectUrl" class="block text-gray-300 text-xs font-semibold mb-1">Redirecionar após Compra</label>
                                <input type="url" id="redirectUrl" name="redirectUrl" class="form-input-sm" placeholder="https://..." value="<?php echo htmlspecialchars($checkout_config['redirectUrl'] ?? ''); ?>">
                            </div>
                            <div>
                                <label class="block text-gray-300 text-xs font-semibold mb-1">Back Redirect</label>
                                <div class="p-2 bg-dark-card rounded border border-dark-border">
                                    <div class="flex items-center gap-2 mb-2">
                                        <input id="back_redirect_enabled" name="back_redirect_enabled" type="checkbox" <?php echo ($checkout_config['backRedirect']['enabled'] ?? false) ? 'checked' : ''; ?> class="form-checkbox">
                                        <label for="back_redirect_enabled" class="text-xs text-white">Ativar</label>
                                    </div>
                                    <input type="url" id="back_redirect_url" name="back_redirect_url" class="form-input-sm" placeholder="URL" value="<?php echo htmlspecialchars($checkout_config['backRedirect']['url'] ?? ''); ?>">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-6 pt-4 border-t border-dark-border">
                    <button type="submit" name="salvar_checkout" class="w-full text-white font-bold py-3 px-6 rounded-lg transition-all duration-300 flex items-center justify-center gap-2" style="background-color: var(--accent-primary);" onmouseover="this.style.backgroundColor='var(--accent-primary-hover)'" onmouseout="this.style.backgroundColor='var(--accent-primary)'">
                        <i data-lucide="save" class="w-5 h-5"></i>
                        Salvar e Atualizar Preview
                    </button>
                </div>
            </form>
        </div>

        <!-- Resize Handle -->
        <div id="resize-handle" class="bg-dark-border cursor-col-resize transition-colors flex-shrink-0 relative group" style="width: 4px; min-width: 4px;" onmouseover="this.style.backgroundColor='var(--accent-primary)'" onmouseout="this.style.backgroundColor=''">
            <!-- Área clicável expandida (invisível mas funcional) -->
            <div class="absolute inset-y-0 left-1/2 transform -translate-x-1/2 w-12 -ml-6" style="cursor: col-resize;"></div>
        </div>

        <!-- Preview Direita (60%) -->
        <div id="preview-editor" class="h-full bg-dark-elevated border-l border-dark-border flex items-center justify-center p-4 flex-1" style="min-width: 30%;">
            <div class="w-full h-full bg-dark-card rounded-xl shadow-2xl overflow-hidden" style="border-color: var(--accent-primary);">
                <div class="h-8 bg-dark-elevated flex items-center px-4 border-b border-dark-border">
                    <div class="flex space-x-2">
                        <div class="w-3 h-3 bg-red-500 rounded-full"></div>
                        <div class="w-3 h-3 bg-yellow-400 rounded-full"></div>
                        <div class="w-3 h-3 bg-green-500 rounded-full"></div>
                    </div>
                    <span class="ml-4 text-xs text-gray-400">Preview do Checkout</span>
                    <button type="button" id="refresh-preview" class="ml-auto bg-dark-card text-white p-1.5 rounded transition-colors" style="background-color: var(--dark-card);" onmouseover="this.style.backgroundColor='var(--accent-primary)'" onmouseout="this.style.backgroundColor='var(--dark-card)'" title="Atualizar Preview">
                        <i data-lucide="refresh-cw" class="w-4 h-4"></i>
                    </button>
                </div>
                <iframe id="checkout-preview" src="/checkout?p=<?php echo $produto['checkout_hash']; ?>&preview=true&rand=<?php echo time(); ?>" class="w-full h-[calc(100%-2rem)] border-0"></iframe>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', () => {
        lucide.createIcons();
        const iframe = document.getElementById('checkout-preview');
        const form = document.querySelector('form');
        const refreshBtn = document.getElementById('refresh-preview');
        const csrfInput = document.querySelector('input[name="csrf_token"]');
        
        // Renovar token CSRF periodicamente (a cada 45 minutos) para evitar expiração
        // Isso garante que o token seja renovado antes de expirar (válido por 1 hora)
        if (csrfInput) {
            setInterval(() => {
                fetch('/checkout_editor.php?id=<?php echo $id_produto; ?>&refresh_csrf=1', {
                    method: 'GET',
                    credentials: 'same-origin'
                })
                .then(response => response.json())
                .then(data => {
                    if (data.csrf_token && csrfInput) {
                        csrfInput.value = data.csrf_token;
                        console.log('Token CSRF renovado automaticamente');
                    }
                })
                .catch(error => {
                    console.error('Erro ao renovar token CSRF:', error);
                });
            }, 45 * 60 * 1000); // 45 minutos
        }

        // Sincronizar color pickers
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

        // Atualizar preview ao salvar
        form.addEventListener('submit', () => {
            setTimeout(() => {
                iframe.src = iframe.src.split('&rand=')[0] + '&rand=' + new Date().getTime();
            }, 1000);
        });

        // Botão refresh manual
        refreshBtn.addEventListener('click', () => {
            iframe.src = iframe.src.split('&rand=')[0] + '&rand=' + new Date().getTime();
        });

        // Sortable para reordenar elementos no preview
        const initializeSortablePreview = () => {
            try {
                const iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
                const sortableContainer = iframeDoc.querySelector('div.lg\\:w-2\\/3 > div.bg-white');
                const elementOrderInput = document.getElementById('elementOrderInput');
                
                if (sortableContainer && typeof Sortable !== 'undefined') {
                    new Sortable(sortableContainer, {
                        animation: 150,
                        ghostClass: 'sortable-ghost',
                        handle: '.drag-handle',
                        filter: 'hr',
                        onMove: function (evt) {
                            return evt.related.tagName !== 'HR';
                        },
                        onEnd: () => {
                            const order = Array.from(sortableContainer.children)
                                             .map(child => child.dataset.id)
                                             .filter(id => id);
                            elementOrderInput.value = JSON.stringify(order);
                        }
                    });

                    // Adiciona drag handles
                    const elements = sortableContainer.querySelectorAll(':scope > section[data-id]');
                    elements.forEach(el => {
                        if(el.querySelector('.drag-handle')) return;
                        const handle = iframeDoc.createElement('div');
                        handle.className = 'drag-handle';
                        handle.innerHTML = `<i data-lucide="grip-vertical" style="color: black; background: white; padding: 4px; border-radius: 4px; box-shadow: 0 1px 3px rgba(0,0,0,0.2);"></i>`;
                        Object.assign(handle.style, { position: 'absolute', top: '12px', right: '12px', cursor: 'grab', zIndex: '10', opacity: '0', transition: 'opacity 0.2s' });
                        el.style.position = 'relative';
                        el.insertBefore(handle, el.firstChild);
                        
                        el.addEventListener('mouseenter', () => handle.style.opacity = '1');
                        el.addEventListener('mouseleave', () => handle.style.opacity = '0');
                    });
                    
                    if(iframe.contentWindow.lucide) {
                        iframe.contentWindow.lucide.createIcons();
                    }
                }
            } catch(e) {
                console.warn('Erro ao inicializar sortable no preview:', e);
            }
        };

        iframe.onload = () => {
            initializeSortablePreview();
        };

        // Resize Handler para ajustar largura do sidebar
        const sidebar = document.getElementById('sidebar-editor');
        const preview = document.getElementById('preview-editor');
        const resizeHandle = document.getElementById('resize-handle');
        
        // Carregar largura salva do localStorage
        const savedWidth = localStorage.getItem('checkout_editor_sidebar_width');
        if (savedWidth) {
            sidebar.style.width = savedWidth;
        }

        let isResizing = false;
        let startX = 0;
        let startWidth = 0;

        // Função para iniciar o resize
        const startResize = (e) => {
            isResizing = true;
            startX = e.clientX;
            startWidth = sidebar.offsetWidth;
            document.body.style.cursor = 'col-resize';
            document.body.style.userSelect = 'none';
            resizeHandle.style.backgroundColor = getComputedStyle(document.documentElement).getPropertyValue('--accent-primary').trim() || '<?php echo htmlspecialchars($cor_primaria ?? '#32e768'); ?>';
            e.preventDefault();
        };

        // Função para fazer o resize durante o movimento do mouse
        const doResize = (e) => {
            if (!isResizing) return;
            
            const diff = e.clientX - startX;
            const newWidth = startWidth + diff;
            const containerWidth = sidebar.parentElement.offsetWidth;
            const minWidth = 300;
            const maxWidth = containerWidth * 0.7;
            
            // Limitar entre min e max
            const finalWidth = Math.max(minWidth, Math.min(maxWidth, newWidth));
            sidebar.style.width = finalWidth + 'px';
        };

        // Função para parar o resize
        const stopResize = () => {
            if (isResizing) {
                isResizing = false;
                document.body.style.cursor = '';
                document.body.style.userSelect = '';
                resizeHandle.style.backgroundColor = '';
                
                // Salvar largura no localStorage
                localStorage.setItem('checkout_editor_sidebar_width', sidebar.style.width);
            }
        };

        // Event listeners no resize handle e na área expandida
        resizeHandle.addEventListener('mousedown', startResize);
        
        // Também permitir clicar na área expandida invisível
        const expandedArea = resizeHandle.querySelector('div');
        if (expandedArea) {
            expandedArea.addEventListener('mousedown', startResize);
        }
        
        // Event listeners globais para mousemove e mouseup
        document.addEventListener('mousemove', doResize);
        document.addEventListener('mouseup', stopResize);
        
        // Prevenir que o resize pare se o mouse sair da janela
        window.addEventListener('mouseleave', stopResize);
    });
    </script>
</body>
</html>

