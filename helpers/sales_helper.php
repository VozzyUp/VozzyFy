<?php
/**
 * Helper de Vendas
 * Funções relacionadas a salvar e gerenciar vendas
 */

if (!function_exists('save_sales')) {
    /**
     * Salva uma venda no banco de dados
     * 
     * @param PDO $pdo Conexão com o banco
     * @param array $data Dados da transação (POST)
     * @param int $main_id ID do produto principal
     * @param string $payment_id ID da transação no gateway
     * @param string $status Status do pagamento
     * @param string $metodo Método de pagamento
     * @param string $uuid UUID da sessão de checkout
     * @param array $utm_params Parâmetros UTM
     * @return void
     * @throws Exception Se houver erro
     */
    function save_sales($pdo, $data, $main_id, $payment_id, $status, $metodo, $uuid, $utm_params)
    {
        // Verifica limitações via hooks (SaaS) - antes de criar venda
        $hooks_paths = [
            __DIR__ . '/plugin_hooks.php',
            dirname(__DIR__) . '/helpers/plugin_hooks.php',
            dirname(__DIR__) . '/plugin_hooks.php'
        ];

        foreach ($hooks_paths as $hooks_path) {
            if (file_exists($hooks_path)) {
                try {
                    // Evitar output
                    ob_start();
                    require_once $hooks_path;
                    ob_end_clean();
                    break;
                } catch (Exception $e) {
                    ob_end_clean();
                    error_log("Erro ao carregar plugin_hooks: " . $e->getMessage());
                }
            }
        }

        // CORREÇÃO: Buscar usuario_id do produto ANTES de verificar limites
        $usuario_id_from_product = null;
        if (!empty($main_id)) {
            try {
                $stmt_prod_check = $pdo->prepare("SELECT usuario_id FROM produtos WHERE id = ?");
                $stmt_prod_check->execute([$main_id]);
                $prod_check = $stmt_prod_check->fetch(PDO::FETCH_ASSOC);
                if ($prod_check && !empty($prod_check['usuario_id'])) {
                    $usuario_id_from_product = $prod_check['usuario_id'];
                }
            } catch (Exception $e) {
                // Log opcional
            }
        }

        if (function_exists('do_action')) {
            $limit_check = do_action('before_create_venda', $main_id ?? 0);
            if ($limit_check && isset($limit_check['allowed']) && !$limit_check['allowed']) {
                $error_message = $limit_check['message'] ?? 'Limite de pedidos atingido';
                $upgrade_url = $limit_check['upgrade_url'] ?? '/index?pagina=saas_planos';
                throw new Exception("LIMITE_ATINGIDO|" . $error_message . "|" . $upgrade_url);
            }
        }

        // Extrai UTMs
        $utm_source = $utm_params['utm_source'] ?? null;
        $utm_campaign = $utm_params['utm_campaign'] ?? null;
        $utm_medium = $utm_params['utm_medium'] ?? null;
        $utm_content = $utm_params['utm_content'] ?? null;
        $utm_term = $utm_params['utm_term'] ?? null;
        $src = $utm_params['src'] ?? null;
        $sck = $utm_params['sck'] ?? null;

        $pdo->beginTransaction();
        try {
            // Validar IDs de produtos
            $products = [$main_id];
            if (isset($data['order_bump_product_ids']) && is_array($data['order_bump_product_ids'])) {
                $products = array_merge($products, $data['order_bump_product_ids']);
            }

            // Validar e sanitizar IDs
            // Requer validation_helper.php estar incluído
            if (function_exists('validate_product_ids')) {
                $products = validate_product_ids($products, 10);
            }

            $placeholders = implode(',', array_fill(0, count($products), '?'));
            $stmt_info = $pdo->prepare("SELECT id, preco FROM produtos WHERE id IN ($placeholders)");
            $stmt_info->execute($products);
            $prod_map = $stmt_info->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);

            // Verificar se existe oferta ativa
            $oferta_preco = null;
            if ($main_id && isset($data['transaction_amount'])) {
                $transaction_total = floatval($data['transaction_amount']);
                $order_bumps_total = 0.0;

                if (isset($data['order_bump_product_ids']) && is_array($data['order_bump_product_ids'])) {
                    foreach ($data['order_bump_product_ids'] as $ob_pid) {
                        if (isset($prod_map[$ob_pid])) {
                            $order_bumps_total += floatval($prod_map[$ob_pid]['preco']);
                        }
                    }
                }

                $main_product_value = $transaction_total - $order_bumps_total;
                $produto_preco = isset($prod_map[$main_id]) ? floatval($prod_map[$main_id]['preco']) : 0;

                if (abs($main_product_value - $produto_preco) > 0.01) {
                    $stmt_oferta = $pdo->prepare("
                        SELECT preco FROM produto_ofertas 
                        WHERE produto_id = ? AND is_active = 1 
                        AND ABS(preco - ?) < 0.01
                        ORDER BY created_at DESC 
                        LIMIT 1
                    ");
                    $stmt_oferta->execute([$main_id, $main_product_value]);
                    $oferta = $stmt_oferta->fetch(PDO::FETCH_ASSOC);
                    if ($oferta) {
                        $oferta_preco = floatval($oferta['preco']);
                    } else {
                        $oferta_preco = $main_product_value;
                    }
                }
            }

            // Extrair dados de endereço
            $address = $data['address'] ?? null;
            $comprador_cep = $address['cep'] ?? null;
            $comprador_logradouro = $address['logradouro'] ?? null;
            $comprador_numero = $address['numero'] ?? null;
            $comprador_complemento = $address['complemento'] ?? null;
            $comprador_bairro = $address['bairro'] ?? null;
            $comprador_cidade = $address['cidade'] ?? null;
            $comprador_estado = $address['estado'] ?? null;

            $stmt_insert = $pdo->prepare("INSERT INTO vendas (produto_id, comprador_nome, comprador_email, comprador_cpf, comprador_telefone, comprador_cep, comprador_logradouro, comprador_numero, comprador_complemento, comprador_bairro, comprador_cidade, comprador_estado, valor, status_pagamento, transacao_id, metodo_pagamento, checkout_session_uuid, email_entrega_enviado, utm_source, utm_campaign, utm_medium, utm_content, utm_term, src, sck) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?)");

            foreach ($products as $pid) {
                if (isset($prod_map[$pid])) {
                    if ($pid === $main_id && $oferta_preco !== null) {
                        $val = $oferta_preco;
                    } else {
                        $val = $prod_map[$pid]['preco'];
                    }

                    $comprador_cpf = !empty($data['cpf']) ? preg_replace('/[^0-9]/', '', $data['cpf']) : null;

                    $stmt_insert->execute([
                        $pid,
                        $data['name'],
                        $data['email'],
                        $comprador_cpf,
                        preg_replace('/[^0-9]/', '', $data['phone']),
                        $comprador_cep,
                        $comprador_logradouro,
                        $comprador_numero,
                        $comprador_complemento,
                        $comprador_bairro,
                        $comprador_cidade,
                        $comprador_estado,
                        $val,
                        $status,
                        $payment_id,
                        $metodo,
                        $uuid,
                        $utm_source,
                        $utm_campaign,
                        $utm_medium,
                        $utm_content,
                        $utm_term,
                        $src,
                        $sck
                    ]);
                }
            }
            $pdo->commit();

            // Verificar conquistas
            if ($status === 'approved' && $usuario_id_from_product) {
                $conquistas_helper_path = __DIR__ . '/conquistas_helper.php';
                if (!file_exists($conquistas_helper_path)) {
                    $conquistas_helper_path = dirname(__DIR__) . '/helpers/conquistas_helper.php';
                }

                if (file_exists($conquistas_helper_path)) {
                    try {
                        require_once $conquistas_helper_path;
                        if (function_exists('verificar_conquistas')) {
                            verificar_conquistas($usuario_id_from_product);
                        }
                    } catch (Exception $e) {
                        error_log("Erro ao verificar conquistas: " . $e->getMessage());
                    }
                }
            }

            // Hook após venda
            if (function_exists('do_action')) {
                do_action('after_create_venda', $main_id);
            }

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Erro ao salvar vendas: " . $e->getMessage());
            throw $e;
        }
    }
}
?>