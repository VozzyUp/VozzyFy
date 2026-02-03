<?php
// Este é um endpoint público 'beacon' para receber eventos de rastreamento de script JS via imagem 1x1.
// Não requer autenticação de sessão, mas valida a entrada para segurança e registra o evento.

// Configura os cabeçalhos para enviar uma imagem GIF transparente 1x1 pixel.
header('Content-Type: image/gif');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Saída de um GIF transparente 1x1 pixel (dados brutos codificados)
// Isso é crucial para que o navegador exiba uma imagem válida, mesmo que invisível.
echo base64_decode('R0lGODlhAQABAJAAAP8AAAAAACH5BAUQAAAALAAAAAABAAEAAAICBAEAOw==');

// Inicia o buffer de saída *após* a saída da imagem.
// Isso garante que a imagem seja enviada imediatamente e qualquer saída PHP subsequente
// (como erros ou logs internos que não são direcionados para o arquivo de log) seja capturada e descartada,
// evitando corrupção do fluxo da imagem.
ob_start();

// Ativar log de erros detalhado (APENAS PARA DEPURAÇÃO - REMOVA EM PRODUÇÃO!)
error_reporting(E_ALL);
ini_set('display_errors', 0); // DESABILITAR exibição de erros no navegador para APIs
ini_set('log_errors', 1); // Habilita o log de erros
ini_set('error_log', __DIR__ . '/track_beacon_errors.log'); // Loga erros para um arquivo específico

// Funções para registro de log (pode ser útil ter uma separada para o beacon)
function log_beacon($message) {
    error_log(date('Y-m-d H:i:s') . " - " . $message, 0); // 0 = default PHP error_log, use 3 for custom file path
}

log_beacon("STARFY_TRACK_BEACON: Script iniciado.");

try {
    require_once __DIR__ . '/../config/config.php';
    log_beacon("STARFY_TRACK_BEACON: config.php carregado com sucesso.");

    // 1. Receber e validar os parâmetros GET
    $tracking_id = $_GET['tracking_id'] ?? null;
    $session_id = $_GET['session_id'] ?? null;
    $event_type = $_GET['event_type'] ?? 'page_view'; // Pode ser sobrescrito pelo JS, mas 'page_view' é o default
    $raw_event_data = $_GET['event_data'] ?? null; // NOVO: Captura o event_data como string JSON

    if (empty($tracking_id) || empty($session_id) || empty($event_type)) {
        log_beacon("STARFY_TRACK_BEACON: Erro: tracking_id, session_id ou event_type ausente. tracking_id: " . ($tracking_id ?? 'N/A') . ", session_id: " . ($session_id ?? 'N/A') . ", event_type: " . ($event_type ?? 'N/A'));
        ob_end_clean(); // Descarta qualquer saída do buffer
        exit; // A imagem já foi enviada, então apenas encerramos o script silenciosamente.
    }

    // 2. Verificar a validade do tracking_id e obter o tracking_product_id interno
    $stmt_tracking_product = $pdo->prepare("SELECT id FROM starfy_tracking_products WHERE tracking_id = :tracking_id");
    $stmt_tracking_product->bindParam(':tracking_id', $tracking_id, PDO::PARAM_STR);
    $stmt_tracking_product->execute();
    $tracking_product_id_db = $stmt_tracking_product->fetchColumn();

    if (!$tracking_product_id_db) {
        log_beacon("STARFY_TRACK_BEACON: Tracking ID inválido ou não encontrado: $tracking_id.");
        ob_end_clean(); // Descarta qualquer saída do buffer
        exit;
    }

    // 3. Preparar dados adicionais do evento
    $event_data = [
        'url' => $_SERVER['HTTP_REFERER'] ?? ($_GET['url'] ?? 'direct_access'), // Prioriza o referrer do browser ou da URL do JS, fallback para direct_access
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
    ];

    // NOVO: Mesclar event_data recebido via GET (se for JSON válido)
    if ($raw_event_data) {
        $decoded_raw_event_data = json_decode($raw_event_data, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded_raw_event_data)) {
            $event_data = array_merge($event_data, $decoded_raw_event_data);
            log_beacon("STARFY_TRACK_BEACON: event_data mesclado. Dados finais: " . json_encode($event_data));
        } else {
            log_beacon("STARFY_TRACK_BEACON: raw_event_data inválido ou não JSON: '$raw_event_data'. Ignorando mesclagem.");
        }
    }


    // 4. Inserir o evento na tabela starfy_tracking_events
    // Usamos um SELECT COUNT para verificar se o evento já existe para esta sessão e produto
    // para evitar duplicações em refreshes da página.
    $check_existing_stmt = $pdo->prepare("SELECT COUNT(*) FROM starfy_tracking_events WHERE tracking_product_id = :tracking_product_id AND session_id = :session_id AND event_type = :event_type");
    $check_existing_stmt->bindParam(':tracking_product_id', $tracking_product_id_db, PDO::PARAM_INT);
    $check_existing_stmt->bindParam(':session_id', $session_id, PDO::PARAM_STR);
    $check_existing_stmt->bindParam(':event_type', $event_type, PDO::PARAM_STR);
    $check_existing_stmt->execute();

    if ($check_existing_stmt->fetchColumn() > 0) {
        log_beacon("STARFY_TRACK_BEACON: Evento '$event_type' já registrado para a sessão '$session_id' e tracking_product_id '$tracking_product_id_db'. Ignorando.");
        ob_end_clean(); // Descarta qualquer saída do buffer
        exit;
    }

    $stmt_insert = $pdo->prepare("INSERT INTO starfy_tracking_events (tracking_product_id, session_id, event_type, event_data) VALUES (:tracking_product_id, :session_id, :event_type, :event_data)");
    $stmt_insert->bindParam(':tracking_product_id', $tracking_product_id_db, PDO::PARAM_INT);
    $stmt_insert->bindParam(':session_id', $session_id, PDO::PARAM_STR);
    $stmt_insert->bindParam(':event_type', $event_type, PDO::PARAM_STR);
    $stmt_insert->bindParam(':event_data', json_encode($event_data), PDO::PARAM_STR);

    if ($stmt_insert->execute()) {
        log_beacon("STARFY_TRACK_BEACON: Evento '$event_type' registrado com sucesso. Session ID: '$session_id', Tracking Product DB ID: '$tracking_product_id_db'.");
    } else {
        log_beacon("STARFY_TRACK_BEACON: Erro ao registrar evento no banco de dados. SQLSTATE: " . $stmt_insert->errorCode() . ", Detalhes: " . print_r($stmt_insert->errorInfo(), true));
    }

} catch (Throwable $e) { // Captura Exception e Error
    log_beacon('STARFY_TRACK_BEACON: Erro Fatal: ' . $e->getMessage() . ' no arquivo ' . $e->getFile() . ' na linha ' . $e->getLine());
}

// O script termina aqui. Nenhuma outra saída é necessária, pois a imagem já foi enviada.
ob_end_clean(); // Descarta qualquer output restante do buffer e o desliga.
?>