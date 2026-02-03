<?php
// Este é um endpoint público para receber eventos de rastreamento do script JS.
// Não requer autenticação de sessão, mas valida a entrada para segurança.

header('Content-Type: application/json');
ob_start(); // Inicia o buffer de saída para evitar problemas com headers já enviados

// Ativar log de erros detalhado (APENAS PARA DEPURAÇÃO - REMOVA EM PRODUÇÃO!)
error_reporting(E_ALL);
ini_set('display_errors', 0); // DESABILITAR exibição de erros no navegador para APIs
ini_set('log_errors', 1); // Habilita o log de erros
ini_set('error_log', __DIR__ . '/track_api_errors.log'); // Loga erros para um arquivo específico

error_log("STARFY_TRACK_ENDPOINT: Script iniciado.");

try {
    require_once __DIR__ . '/../config/config.php';

    // 1. Receber e decodificar o payload JSON
    $raw_post_data = file_get_contents('php://input');
    error_log("STARFY_TRACK_ENDPOINT: Dados brutos recebidos: " . $raw_post_data);
    $data = json_decode($raw_post_data, true);

    // Melhoria: Verifica se a decodificação JSON falhou OU se os dados resultantes não são um array (indicando input vazio/inválido)
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($data)) {
        http_response_code(400);
        error_log("STARFY_TRACK_ENDPOINT: Erro ao decodificar JSON ou dados vazios/inválidos: " . json_last_error_msg() . ". Dados brutos: " . $raw_post_data);
        echo json_encode(['success' => false, 'error' => 'Dados JSON inválidos ou vazios.']);
        exit;
    }

    // 2. Validar campos essenciais
    $required_fields = ['tracking_id', 'session_id', 'event_type'];
    foreach ($required_fields as $field) {
        if (empty($data[$field])) {
            http_response_code(400);
            error_log("STARFY_TRACK_ENDPOINT: Campo obrigatório ausente: '$field'. Dados recebidos: " . print_r($data, true));
            echo json_encode(['success' => false, 'error' => "Campo obrigatório ausente: $field"]);
            exit;
        }
    }

    $tracking_id = $data['tracking_id'];
    $session_id = $data['session_id'];
    $event_type = $data['event_type'];
    $event_data = $data['event_data'] ?? [];

    // Validar tipo de evento (permitir apenas tipos conhecidos)
    $allowed_event_types = ['page_view', 'initiate_checkout', 'purchase'];
    if (!in_array($event_type, $allowed_event_types)) {
        http_response_code(400);
        error_log("STARFY_TRACK_ENDPOINT: Tipo de evento inválido: '$event_type'. Dados recebidos: " . print_r($data, true));
        echo json_encode(['success' => false, 'error' => "Tipo de evento inválido: $event_type"]);
        exit;
    }

    // 3. Verificar a validade do tracking_id e obter o tracking_product_id interno
    $stmt_tracking_product = $pdo->prepare("SELECT id FROM starfy_tracking_products WHERE tracking_id = :tracking_id");
    $stmt_tracking_product->bindParam(':tracking_id', $tracking_id, PDO::PARAM_STR);
    $stmt_tracking_product->execute();
    $tracking_product_id_db = $stmt_tracking_product->fetchColumn();

    if (!$tracking_product_id_db) {
        http_response_code(404);
        error_log("STARFY_TRACK_ENDPOINT: Tracking ID inválido ou não encontrado: '$tracking_id'. Dados recebidos: " . print_r($data, true));
        echo json_encode(['success' => false, 'error' => 'Tracking ID inválido.']);
        exit;
    }

    // 4. Inserir o evento na tabela starfy_tracking_events
    // A lógica de verificar e inserir (`SELECT COUNT` e `INSERT`) é aplicada para 'page_view'
    // e 'initiate_checkout' para evitar a duplicação de eventos em recarregamentos de página dentro da mesma sessão.
    // Para 'purchase', não há essa verificação prévia, pois múltiplas compras podem ocorrer em uma sessão.

    $insert_event = true;
    if ($event_type === 'page_view' || $event_type === 'initiate_checkout') {
        error_log("STARFY_TRACK_ENDPOINT: Verificando evento existente para '$event_type' na sessão '$session_id' para tracking_product_id '$tracking_product_id_db'.");
        $check_existing_stmt = $pdo->prepare("SELECT COUNT(*) FROM starfy_tracking_events WHERE tracking_product_id = :tracking_product_id AND session_id = :session_id AND event_type = :event_type");
        $check_existing_stmt->bindParam(':tracking_product_id', $tracking_product_id_db, PDO::PARAM_INT);
        $check_existing_stmt->bindParam(':session_id', $session_id, PDO::PARAM_STR);
        $check_existing_stmt->bindParam(':event_type', $event_type, PDO::PARAM_STR);
        $check_existing_stmt->execute();

        if ($check_existing_stmt->fetchColumn() > 0) {
            error_log("STARFY_TRACK_ENDPOINT: Evento '$event_type' já registrado para a sessão '$session_id' e tracking_product_id '$tracking_product_id_db'. Ignorando inserção.");
            $insert_event = false; // Não insere se já existe
        }
    }

    if ($insert_event) {
        $stmt_insert = $pdo->prepare("INSERT INTO starfy_tracking_events (tracking_product_id, session_id, event_type, event_data) VALUES (:tracking_product_id, :session_id, :event_type, :event_data)");
        $stmt_insert->bindParam(':tracking_product_id', $tracking_product_id_db, PDO::PARAM_INT);
        $stmt_insert->bindParam(':session_id', $session_id, PDO::PARAM_STR);
        $stmt_insert->bindParam(':event_type', $event_type, PDO::PARAM_STR);
        $stmt_insert->bindParam(':event_data', json_encode($event_data), PDO::PARAM_STR);

        if ($stmt_insert->execute()) {
            error_log("STARFY_TRACK_ENDPOINT: Evento '$event_type' registrado com sucesso. Session ID: '$session_id', Tracking Product DB ID: '$tracking_product_id_db'.");
            ob_clean(); // Limpa o buffer antes de enviar o JSON
            echo json_encode(['success' => true, 'message' => 'Evento registrado com sucesso.']);
        } else {
            http_response_code(500);
            error_log("STARFY_TRACK_ENDPOINT: Erro ao registrar evento no banco de dados. SQLSTATE: " . $stmt_insert->errorCode() . ", Detalhes: " . print_r($stmt_insert->errorInfo(), true) . ". Dados recebidos: " . print_r($data, true));
            echo json_encode(['success' => false, 'error' => 'Erro ao registrar evento no banco de dados.']);
        }
    }

} catch (Throwable $e) { // Captura Exception e Error
    http_response_code(500);
    error_log('STARFY_TRACK_ENDPOINT: Erro Fatal: ' . $e->getMessage() . ' no arquivo ' . $e->getFile() . ' na linha ' . $e->getLine());
    ob_clean(); // Limpa o buffer antes de enviar o JSON
    echo json_encode(['success' => false, 'error' => 'Ocorreu um erro interno no servidor.']);
}