<?php
/**
 * Gateway Efí - Integração com API Pix Efí
 * 
 * Este arquivo contém todas as funções necessárias para comunicação com a API Efí
 * seguindo padrões de segurança e modularidade.
 */

/**
 * Obtém access token via OAuth2 com certificado P12
 * 
 * @param string $client_id Client ID da aplicação Efí
 * @param string $client_secret Client Secret da aplicação Efí
 * @param string $certificate_path Caminho completo do certificado P12
 * @return array ['access_token' => string, 'expires_in' => int] ou false em caso de erro
 */
function efi_get_access_token($client_id, $client_secret, $certificate_path) {
    // Remover espaços em branco das credenciais
    $client_id = trim($client_id);
    $client_secret = trim($client_secret);
    $certificate_path = trim($certificate_path);
    
    // Normalizar caminho do certificado (Windows usa \, mas cURL precisa de /)
    $certificate_path = str_replace('\\', '/', $certificate_path);
    
    // Log inicial com informações de debug (sem expor credenciais completas)
    $client_id_preview = !empty($client_id) ? substr($client_id, 0, 8) . '...' . substr($client_id, -4) : 'VAZIO';
    error_log("Efí: Iniciando obtenção de token - Client ID: $client_id_preview");
    error_log("Efí: Caminho do certificado (normalizado): " . $certificate_path);
    
    if (empty($client_id) || empty($client_secret) || empty($certificate_path)) {
        error_log("Efí: Credenciais ou certificado não fornecidos - Client ID: " . (!empty($client_id) ? 'presente' : 'vazio') . ", Client Secret: " . (!empty($client_secret) ? 'presente' : 'vazio') . ", Certificado: " . (!empty($certificate_path) ? 'presente' : 'vazio'));
        return false;
    }
    
    // Verificar se certificado existe
    if (!file_exists($certificate_path)) {
        error_log("Efí: Certificado não encontrado em: " . $certificate_path);
        error_log("Efí: Caminho absoluto verificado: " . realpath(dirname($certificate_path)) . '/' . basename($certificate_path));
        return false;
    }
    
    // Verificar permissões e tamanho do certificado
    $cert_size = filesize($certificate_path);
    $cert_readable = is_readable($certificate_path);
    error_log("Efí: Certificado encontrado - Tamanho: " . $cert_size . " bytes, Legível: " . ($cert_readable ? 'sim' : 'não'));
    
    if (!$cert_readable) {
        error_log("Efí: Certificado não é legível. Verifique permissões do arquivo.");
        return false;
    }
    
    $url = 'https://pix.api.efipay.com.br/oauth/token';
    
    // Basic Auth: Client ID:Client Secret em Base64
    $auth = base64_encode($client_id . ':' . $client_secret);
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Basic ' . $auth,
        'Content-Type: application/json'
    ]);
    
    // Configurar certificado P12 para mutual TLS
    curl_setopt($ch, CURLOPT_SSLCERT, $certificate_path);
    curl_setopt($ch, CURLOPT_SSLCERTTYPE, 'P12');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['grant_type' => 'client_credentials']));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    
    error_log("Efí: Enviando requisição OAuth para: $url");
    
    $response = curl_exec($ch);
    $curl_error = curl_error($ch);
    $curl_errno = curl_errno($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_info = curl_getinfo($ch);
    curl_close($ch);
    
    if ($curl_error || $curl_errno) {
        error_log("Efí OAuth cURL Error (errno: $curl_errno): " . $curl_error);
        error_log("Efí: URL da requisição: " . $url);
        error_log("Efí: Certificado usado: " . $certificate_path);
        return false;
    }
    
    if ($http_code !== 200) {
        $response_preview = substr($response, 0, 1000);
        error_log("Efí OAuth HTTP Error ($http_code): " . $response_preview);
        
        // Tentar decodificar resposta para log mais detalhado
        $error_data = json_decode($response, true);
        if ($error_data) {
            error_log("Efí OAuth Error Details: " . json_encode($error_data));
            if (isset($error_data['error_description'])) {
                error_log("Efí OAuth Error Description: " . $error_data['error_description']);
            }
        }
        
        error_log("Efí: Informações da requisição - URL: $url, Certificado: $certificate_path, Client ID: $client_id_preview");
        
        // Mensagem específica para erro 401
        if ($http_code === 401) {
            error_log("Efí: ERRO 401 - Possíveis causas:");
            error_log("Efí: 1. Client ID ou Client Secret incorretos");
            error_log("Efí: 2. Certificado P12 não corresponde ao Client ID/Secret");
            error_log("Efí: 3. Credenciais inativas na conta Efí");
            error_log("Efí: 4. Certificado P12 incorreto ou corrompido");
            error_log("Efí: SOLUÇÃO: Verifique na conta Efí se o Client ID/Secret estão corretos e se o certificado P12 corresponde a essas credenciais");
        }
        
        return false;
    }
    
    $data = json_decode($response, true);
    
    if (!isset($data['access_token'])) {
        error_log("Efí OAuth: access_token não encontrado na resposta");
        error_log("Efí: Resposta completa: " . substr($response, 0, 500));
        return false;
    }
    
    error_log("Efí: Token obtido com sucesso - Expira em: " . ($data['expires_in'] ?? 3600) . " segundos");
    
    return [
        'access_token' => $data['access_token'],
        'expires_in' => $data['expires_in'] ?? 3600,
        'token_type' => $data['token_type'] ?? 'Bearer'
    ];
}

/**
 * Obtém access token da API de Cobranças (CHARGES) via OAuth2 com certificado P12
 * 
 * A API de Cobranças usa um endpoint diferente (/v1/authorize) e o token expira em 600 segundos
 * 
 * @param string $client_id Client ID da aplicação Efí
 * @param string $client_secret Client Secret da aplicação Efí
 * @param string $certificate_path Caminho completo do certificado P12
 * @return array ['access_token' => string, 'expires_in' => int] ou false em caso de erro
 */
function efi_get_charges_access_token($client_id, $client_secret, $certificate_path) {
    // Remover espaços em branco das credenciais
    $client_id = trim($client_id);
    $client_secret = trim($client_secret);
    $certificate_path = trim($certificate_path);
    
    // Normalizar caminho do certificado (Windows usa \, mas cURL precisa de /)
    $certificate_path = str_replace('\\', '/', $certificate_path);
    
    // Log inicial com informações de debug (sem expor credenciais completas)
    $client_id_preview = !empty($client_id) ? substr($client_id, 0, 8) . '...' . substr($client_id, -4) : 'VAZIO';
    error_log("Efí Cobranças: Iniciando obtenção de token - Client ID: $client_id_preview");
    error_log("Efí Cobranças: Caminho do certificado (normalizado): " . $certificate_path);
    
    if (empty($client_id) || empty($client_secret) || empty($certificate_path)) {
        error_log("Efí Cobranças: Credenciais ou certificado não fornecidos");
        return false;
    }
    
    // Verificar se certificado existe
    if (!file_exists($certificate_path)) {
        error_log("Efí Cobranças: Certificado não encontrado em: " . $certificate_path);
        return false;
    }
    
    // Verificar permissões e tamanho do certificado
    $cert_size = filesize($certificate_path);
    $cert_readable = is_readable($certificate_path);
    error_log("Efí Cobranças: Certificado encontrado - Tamanho: " . $cert_size . " bytes, Legível: " . ($cert_readable ? 'sim' : 'não'));
    
    if (!$cert_readable) {
        error_log("Efí Cobranças: Certificado não é legível. Verifique permissões do arquivo.");
        return false;
    }
    
    // URL da API de Cobranças para autenticação (produção)
    // Para sandbox, seria: https://cobrancas-h.api.efipay.com.br/v1/authorize
    $url = 'https://cobrancas.api.efipay.com.br/v1/authorize';
    
    // Basic Auth: Client ID:Client Secret em Base64
    $auth = base64_encode($client_id . ':' . $client_secret);
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Basic ' . $auth,
        'Content-Type: application/json'
    ]);
    
    // Configurar certificado P12 para mutual TLS
    curl_setopt($ch, CURLOPT_SSLCERT, $certificate_path);
    curl_setopt($ch, CURLOPT_SSLCERTTYPE, 'P12');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['grant_type' => 'client_credentials']));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    
    error_log("Efí Cobranças: Enviando requisição OAuth para: $url");
    
    $response = curl_exec($ch);
    $curl_error = curl_error($ch);
    $curl_errno = curl_errno($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($curl_error || $curl_errno) {
        error_log("Efí Cobranças OAuth cURL Error (errno: $curl_errno): " . $curl_error);
        error_log("Efí Cobranças: URL da requisição: " . $url);
        return false;
    }
    
    if ($http_code !== 200) {
        $response_preview = substr($response, 0, 1000);
        error_log("Efí Cobranças OAuth HTTP Error ($http_code): " . $response_preview);
        
        // Tentar decodificar resposta para log mais detalhado
        $error_data = json_decode($response, true);
        if ($error_data) {
            error_log("Efí Cobranças OAuth Error Details: " . json_encode($error_data));
        }
        return false;
    }
    
    $data = json_decode($response, true);
    
    if (!$data || !isset($data['access_token'])) {
        error_log("Efí Cobranças: Resposta inválida do servidor OAuth");
        error_log("Efí Cobranças: Resposta recebida: " . substr($response, 0, 500));
        return false;
    }
    
    error_log("Efí Cobranças: Token obtido com sucesso - Expira em: " . ($data['expires_in'] ?? 600) . " segundos");
    
    return [
        'access_token' => $data['access_token'],
        'expires_in' => $data['expires_in'] ?? 600 // API de Cobranças expira em 600 segundos (10 minutos)
    ];
}

/**
 * Cria uma cobrança Pix imediata
 * 
 * @param string $access_token Token de acesso OAuth2
 * @param float $amount Valor da cobrança em reais
 * @param string $pix_key Chave Pix do recebedor
 * @param array $payer_data Dados do pagador ['name' => string, 'cpf' => string, 'email' => string]
 * @param string $description Descrição da cobrança
 * @param int $expiration_minutes Minutos até expiração (padrão: 60)
 * @param string $certificate_path Caminho do certificado P12 (opcional, mas recomendado para mutual TLS)
 * @return array ['txid' => string, 'qr_code' => string, 'qr_code_base64' => string] ou false em caso de erro
 */
function efi_create_pix_charge($access_token, $amount, $pix_key, $payer_data, $description = '', $expiration_minutes = 60, $certificate_path = null) {
    // Remover espaços em branco
    $access_token = trim($access_token);
    $pix_key = trim($pix_key);
    if ($certificate_path) {
        $certificate_path = trim($certificate_path);
        $certificate_path = str_replace('\\', '/', $certificate_path);
    }
    
    if (empty($access_token) || empty($pix_key) || $amount <= 0) {
        error_log("Efí: Parâmetros inválidos para criar cobrança - access_token: " . (!empty($access_token) ? 'presente' : 'vazio') . ", pix_key: " . (!empty($pix_key) ? 'presente' : 'vazio') . ", amount: $amount");
        return false;
    }
    
    // Validar CPF (obrigatório para Efí)
    $cpf = preg_replace('/[^0-9]/', '', $payer_data['cpf'] ?? '');
    if (strlen($cpf) !== 11) {
        error_log("Efí: CPF inválido - CPF fornecido: " . ($payer_data['cpf'] ?? 'vazio') . ", CPF limpo: $cpf, Tamanho: " . strlen($cpf));
        return false;
    }
    
    // Validar se CPF não é uma sequência de zeros ou números repetidos (ex: 00000000000, 11111111111)
    if (preg_match('/^(\d)\1{10}$/', $cpf)) {
        error_log("Efí: CPF inválido - CPF é uma sequência repetida: $cpf");
        return false;
    }
    
    // Validar dígitos verificadores do CPF
    if (!function_exists('validarCPF')) {
        function validarCPF($cpf) {
            // Remove caracteres não numéricos
            $cpf = preg_replace('/[^0-9]/', '', $cpf);
            
            // Verifica se tem 11 dígitos
            if (strlen($cpf) != 11) {
                return false;
            }
            
            // Verifica se é uma sequência de números repetidos
            if (preg_match('/(\d)\1{10}/', $cpf)) {
                return false;
            }
            
            // Validação dos dígitos verificadores
            for ($t = 9; $t < 11; $t++) {
                for ($d = 0, $c = 0; $c < $t; $c++) {
                    $d += $cpf[$c] * (($t + 1) - $c);
                }
                $d = ((10 * $d) % 11) % 10;
                if ($cpf[$c] != $d) {
                    return false;
                }
            }
            
            return true;
        }
    }
    
    if (!validarCPF($cpf)) {
        error_log("Efí: CPF inválido - CPF não passa na validação de dígitos verificadores: $cpf");
        return false;
    }
    
    $url = 'https://pix.api.efipay.com.br/v2/cob';
    
    // Formatar valor (Efí espera string com 2 casas decimais)
    $amount_formatted = number_format((float)$amount, 2, '.', '');
    
    $devedor = [
        'nome' => $payer_data['name'] ?? 'Cliente',
        'cpf' => $cpf
    ];
    
    $payload = [
        'calendario' => [
            'expiracao' => $expiration_minutes * 60 // Converter minutos para segundos
        ],
        'devedor' => $devedor,
        'valor' => [
            'original' => $amount_formatted
        ],
        'chave' => $pix_key,
        'solicitacaoPagador' => !empty($description) ? $description : 'Pagamento via checkout'
    ];
    
    error_log("Efí: Criando cobrança Pix - Valor: $amount_formatted, Chave Pix: " . substr($pix_key, 0, 10) . '...');
    error_log("Efí: Payload completo: " . json_encode($payload));
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $access_token,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 60); // Aumentar timeout para 60 segundos
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30); // Aumentar timeout de conexão
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    
    // Usar certificado P12 se fornecido (mutual TLS pode ser necessário para algumas APIs)
    if (!empty($certificate_path) && file_exists($certificate_path)) {
        $cert_path_normalized = str_replace('\\', '/', $certificate_path);
        curl_setopt($ch, CURLOPT_SSLCERT, $cert_path_normalized);
        curl_setopt($ch, CURLOPT_SSLCERTTYPE, 'P12');
        error_log("Efí: Usando certificado P12 na criação de cobrança: " . $cert_path_normalized);
    } else {
        error_log("Efí: Certificado NÃO fornecido ou não encontrado para criação de cobrança");
    }
    
    $response = curl_exec($ch);
    $curl_error = curl_error($ch);
    $curl_errno = curl_errno($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_info = curl_getinfo($ch);
    curl_close($ch);
    
    if ($curl_error || $curl_errno) {
        error_log("Efí Create Charge cURL Error (errno: $curl_errno): " . $curl_error);
        error_log("Efí: URL da requisição: " . $url);
        error_log("Efí: Payload enviado: " . substr(json_encode($payload), 0, 500));
        return false;
    }
    
    if (empty($response)) {
        error_log("Efí Create Charge: Resposta vazia do servidor (HTTP $http_code)");
        error_log("Efí: URL da requisição: " . $url);
        error_log("Efí: Payload enviado: " . substr(json_encode($payload), 0, 500));
        return false;
    }
    
    if ($http_code < 200 || $http_code >= 300) {
        error_log("Efí Create Charge HTTP Error ($http_code): " . substr($response, 0, 1000));
        $error_data = json_decode($response, true);
        $error_message = 'Erro desconhecido';
        if ($error_data) {
            error_log("Efí Create Charge Error Details: " . json_encode($error_data));
            if (isset($error_data['mensagem'])) {
                $error_message = $error_data['mensagem'];
                error_log("Efí Error Message: " . $error_message);
            } elseif (isset($error_data['nome'])) {
                $error_message = $error_data['nome'] . ': ' . ($error_data['mensagem'] ?? 'Erro desconhecido');
            }
            if (isset($error_data['violacoes'])) {
                error_log("Efí Violações: " . json_encode($error_data['violacoes']));
            }
        }
        // Retornar array com erro para permitir tratamento específico
        return [
            'error' => true,
            'message' => $error_message,
            'http_code' => $http_code,
            'error_data' => $error_data
        ];
    }
    
    $data = json_decode($response, true);
    
    if (!isset($data['txid'])) {
        error_log("Efí: txid não encontrado na resposta");
        error_log("Efí: Resposta completa: " . substr($response, 0, 1000));
        return false;
    }
    
    // Log da resposta completa para debug
    error_log("Efí: Resposta da criação de cobrança - txid: " . ($data['txid'] ?? 'não encontrado'));
    error_log("Efí: Chaves disponíveis na resposta: " . implode(', ', array_keys($data)));
    
    // Extrair QR Code e QR Code Base64
    $qr_code = $data['pixCopiaECola'] ?? '';
    $qr_code_base64 = null;
    
    error_log("Efí: pixCopiaECola presente: " . (!empty($qr_code) ? 'sim (tamanho: ' . strlen($qr_code) . ')' : 'não'));
    
    // A API Efí não retorna o QR code base64 diretamente, apenas o código Pix
    // Precisamos gerar a imagem do QR code a partir do código Pix
    if (!empty($qr_code)) {
        $qr_code_base64 = efi_generate_qr_code_image($qr_code);
        if ($qr_code_base64) {
            error_log("Efí: QR code base64 gerado com sucesso (tamanho: " . strlen($qr_code_base64) . " caracteres)");
        } else {
            error_log("Efí: Falha ao gerar QR code base64 a partir do código Pix");
        }
    }
    
    return [
        'txid' => $data['txid'],
        'qr_code' => $qr_code,
        'qr_code_base64' => $qr_code_base64,
        'location' => $data['location'] ?? null
    ];
}

/**
 * Gera imagem do QR Code em base64 a partir do código Pix
 * 
 * @param string $pix_code Código Pix (pixCopiaECola)
 * @return string|false Base64 da imagem do QR code ou false em caso de erro
 */
function efi_generate_qr_code_image($pix_code) {
    if (empty($pix_code)) {
        return false;
    }
    
    // Usar API online para gerar QR code (alternativa simples sem dependências)
    // API: https://api.qrserver.com/v1/create-qr-code/
    $size = 300; // Tamanho da imagem
    $url = 'https://api.qrserver.com/v1/create-qr-code/?size=' . $size . 'x' . $size . '&data=' . urlencode($pix_code);
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    
    $image_data = curl_exec($ch);
    $curl_error = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($curl_error || $http_code !== 200 || empty($image_data)) {
        error_log("Efí: Erro ao gerar QR code via API externa (HTTP $http_code): " . $curl_error);
        return false;
    }
    
    // Converter imagem para base64
    $base64 = base64_encode($image_data);
    return 'data:image/png;base64,' . $base64;
}

/**
 * Obtém QR Code Base64 de uma cobrança (DEPRECATED - A API Efí não retorna QR code diretamente)
 * 
 * @param string $access_token Token de acesso OAuth2
 * @param string $location_id ID da location da cobrança
 * @param string|null $certificate_path Caminho do certificado P12 (opcional, mas recomendado para mutual TLS)
 * @return array|false Dados do QR Code ou false em caso de erro
 */
function efi_get_qr_code($access_token, $location_id, $certificate_path = null) {
    $access_token = trim($access_token);
    $location_id = trim($location_id);
    
    if (empty($access_token) || empty($location_id)) {
        error_log("Efí: Parâmetros inválidos para buscar QR code - access_token: " . (!empty($access_token) ? 'presente' : 'vazio') . ", location_id: " . (!empty($location_id) ? 'presente' : 'vazio'));
        return false;
    }
    
    $url = 'https://pix.api.efipay.com.br/v2/loc/' . $location_id . '/qrcode';
    error_log("Efí: Buscando QR code em: " . $url);
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $access_token,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    
    // Usar certificado P12 se fornecido (mutual TLS pode ser necessário)
    if (!empty($certificate_path) && file_exists($certificate_path)) {
        $cert_path_normalized = str_replace('\\', '/', $certificate_path);
        curl_setopt($ch, CURLOPT_SSLCERT, $cert_path_normalized);
        curl_setopt($ch, CURLOPT_SSLCERTTYPE, 'P12');
        error_log("Efí: Usando certificado P12 na busca de QR code: " . $cert_path_normalized);
    }
    
    $response = curl_exec($ch);
    $curl_error = curl_error($ch);
    $curl_errno = curl_errno($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($curl_error || $curl_errno) {
        error_log("Efí: Erro cURL ao buscar QR code (errno: $curl_errno): " . $curl_error);
        return false;
    }
    
    if ($http_code !== 200) {
        error_log("Efí: HTTP Error ao buscar QR code ($http_code): " . substr($response, 0, 500));
        return false;
    }
    
    $response_data = json_decode($response, true);
    if (!$response_data) {
        error_log("Efí: Resposta inválida ao buscar QR code: " . substr($response, 0, 500));
        return false;
    }
    
    error_log("Efí: QR code obtido com sucesso - imagemQrcode presente: " . (isset($response_data['imagemQrcode']) ? 'sim' : 'não'));
    
    return $response_data;
}

/**
 * Consulta o status de um pagamento Pix
 * 
 * @param string $access_token Token de acesso OAuth2
 * @param string $txid ID da transação (txid)
 * @param string|null $certificate_path Caminho do certificado P12 (opcional, mas recomendado para mutual TLS)
 * @return array|false ['status' => string, 'valor' => float, 'horario' => string] ou false
 */
function efi_get_payment_status($access_token, $txid, $certificate_path = null) {
    $access_token = trim($access_token);
    $txid = trim($txid);
    
    if (empty($access_token) || empty($txid)) {
        error_log("Efí: Parâmetros inválidos para consultar status - access_token: " . (!empty($access_token) ? 'presente' : 'vazio') . ", txid: " . (!empty($txid) ? 'presente' : 'vazio'));
        return false;
    }
    
    // Tentar primeiro com endpoint de pagamentos recebidos (/v2/pix/{txid})
    // Se falhar, tentar consultar a cobrança (/v2/cob/{txid})
    $urls = [
        'https://pix.api.efipay.com.br/v2/pix/' . urlencode($txid), // Pagamentos recebidos
        'https://pix.api.efipay.com.br/v2/cob/' . urlencode($txid)  // Cobrança (alternativa)
    ];
    
    error_log("Efí: Consultando status do pagamento - txid: " . substr($txid, 0, 20) . '...');
    error_log("Efí: Access token presente: " . (!empty($access_token) ? 'sim (tamanho: ' . strlen($access_token) . ')' : 'não'));
    error_log("Efí: Certificado path: " . ($certificate_path ?? 'não fornecido'));
    
    $last_error = null;
    $last_response = null;
    $last_http_code = null;
    
    foreach ($urls as $url_index => $url) {
        error_log("Efí: Tentativa " . ($url_index + 1) . " - Consultando status do pagamento - URL completo: " . $url);
    
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $access_token,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        
        // Usar certificado P12 se fornecido (mutual TLS pode ser necessário)
        if (!empty($certificate_path) && file_exists($certificate_path)) {
            $cert_path_normalized = str_replace('\\', '/', $certificate_path);
            curl_setopt($ch, CURLOPT_SSLCERT, $cert_path_normalized);
            curl_setopt($ch, CURLOPT_SSLCERTTYPE, 'P12');
            error_log("Efí: Usando certificado P12 na consulta de status: " . $cert_path_normalized);
        }
        
        $response = curl_exec($ch);
        $curl_error = curl_error($ch);
        $curl_errno = curl_errno($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_info = curl_getinfo($ch);
        curl_close($ch);
        
        // Logar informações da requisição
        error_log("Efí: Requisição concluída - HTTP Code: $http_code, cURL Error: " . ($curl_error ?: 'nenhum') . ", cURL Errno: " . ($curl_errno ?: 'nenhum'));
        error_log("Efí: Tamanho da resposta: " . strlen($response) . " bytes");
        
        // Guardar informações para possível retry
        $last_error = $curl_error;
        $last_response = $response;
        $last_http_code = $http_code;
        
        if ($curl_error || $curl_errno) {
            error_log("Efí Get Status cURL Error (errno: $curl_errno): " . $curl_error);
            error_log("Efí: URL da requisição: " . $url);
            error_log("Efí: Informações cURL: " . json_encode($curl_info));
            error_log("Efí: Resposta recebida (se houver): " . substr($response, 0, 500));
            // Continuar para próxima URL se houver
            if ($url_index < count($urls) - 1) {
                continue;
            }
            return false;
        }
        
        if ($http_code === 404) {
            // Pagamento ainda não foi realizado ou endpoint incorreto
            error_log("Efí: Resposta 404 - URL: " . $url);
            error_log("Efí: Resposta 404 completa: " . substr($response, 0, 500));
            // Tentar próxima URL se houver
            if ($url_index < count($urls) - 1) {
                continue;
            }
            // Se for a última tentativa, retornar pending
            return ['status' => 'pending'];
        }
        
        if ($http_code !== 200) {
            error_log("Efí Get Status HTTP Error ($http_code): " . substr($response, 0, 500));
            error_log("Efí: URL da requisição: " . $url);
            error_log("Efí: Resposta completa do erro HTTP: " . substr($response, 0, 1000));
            error_log("Efí: Informações cURL: " . json_encode($curl_info));
            // Tentar próxima URL se houver
            if ($url_index < count($urls) - 1) {
                continue;
            }
            return false;
        }
        
        $data = json_decode($response, true);
        $json_error = json_last_error();
        
        error_log("Efí: Resposta HTTP $http_code ao consultar status - Resposta completa: " . substr($response, 0, 1000));
        error_log("Efí: JSON decode error: " . ($json_error === JSON_ERROR_NONE ? 'nenhum' : json_last_error_msg()));
        
        if (!$data) {
            error_log("Efí: Resposta inválida ao consultar status - URL: " . $url);
            error_log("Efí: Resposta raw (primeiros 1000 chars): " . substr($response, 0, 1000));
            error_log("Efí: JSON decode error: " . json_last_error_msg());
            error_log("Efí: Tamanho da resposta: " . strlen($response) . " bytes");
            // Tentar próxima URL se houver
            if ($url_index < count($urls) - 1) {
                continue;
            }
            return false;
        }
        
        error_log("Efí: Tipo de dados retornados: " . gettype($data) . ", Chaves: " . (is_array($data) ? implode(', ', array_keys($data)) : 'não é array'));
        
        // Efí retorna array de pagamentos (endpoint /v2/pix) ou objeto de cobrança (endpoint /v2/cob)
        if (isset($data[0])) {
            // Formato array de pagamentos (endpoint /v2/pix)
            $payment = $data[0];
            error_log("Efí: Primeiro pagamento encontrado - Chaves: " . implode(', ', array_keys($payment)));
            // Se tem horario, significa que foi pago
            if (isset($payment['horario'])) {
                error_log("Efí: Pagamento aprovado - horario: " . ($payment['horario'] ?? 'não informado') . ", valor: " . ($payment['valor'] ?? 'não informado'));
                return [
                    'status' => 'approved',
                    'valor' => isset($payment['valor']) ? (float)$payment['valor'] : null,
                    'horario' => $payment['horario'] ?? null
                ];
            } else {
                // Pagamento ainda não foi realizado
                error_log("Efí: Pagamento ainda pendente - sem horario na resposta. Chaves disponíveis: " . implode(', ', array_keys($payment)));
                return ['status' => 'pending'];
            }
        } else {
            // Resposta não é um array - pode ser objeto único ou formato diferente
            error_log("Efí: Resposta não é array - tipo: " . gettype($data) . ", conteúdo: " . substr(json_encode($data), 0, 500));
            
            // Verificar se é objeto de cobrança (endpoint /v2/cob) com status
            if (isset($data['status'])) {
                $cob_status = strtolower($data['status']);
                error_log("Efí: Status da cobrança: " . $cob_status);
                // Se a cobrança tem status 'CONCLUIDA' ou similar, verificar se tem pagamento
                if (isset($data['pix']) && is_array($data['pix']) && count($data['pix']) > 0) {
                    // Tem pagamentos associados
                    $pix_payment = $data['pix'][0];
                    if (isset($pix_payment['horario'])) {
                        error_log("Efí: Pagamento aprovado via cobrança - horario: " . ($pix_payment['horario'] ?? 'não informado'));
                        return [
                            'status' => 'approved',
                            'valor' => isset($pix_payment['valor']) ? (float)$pix_payment['valor'] : null,
                            'horario' => $pix_payment['horario'] ?? null
                        ];
                    }
                }
                // Se status é 'ATIVA' ou similar, ainda está pendente
                if ($cob_status === 'ativa' || $cob_status === 'active') {
                    error_log("Efí: Cobrança ainda ativa (pendente)");
                    return ['status' => 'pending'];
                }
            }
            
            // Tenta verificar se é um objeto único com horario
            if (isset($data['horario'])) {
                error_log("Efí: Pagamento aprovado (formato objeto único) - horario: " . ($data['horario'] ?? 'não informado'));
                return [
                    'status' => 'approved',
                    'valor' => isset($data['valor']) ? (float)$data['valor'] : null,
                    'horario' => $data['horario'] ?? null
                ];
            }
        }
        
        // Se chegou aqui, não encontrou pagamento aprovado nesta tentativa
        // Tentar próxima URL se houver
        if ($url_index < count($urls) - 1) {
            continue;
        }
        
        error_log("Efí: Nenhum pagamento encontrado na resposta - retornando pending. Resposta completa: " . substr(json_encode($data), 0, 1000));
        return ['status' => 'pending'];
    }
    
    // Se todas as tentativas falharam, retornar false
    error_log("Efí: Todas as tentativas de consultar status falharam");
    error_log("Efí: Último HTTP Code: " . ($last_http_code ?? 'não disponível'));
    error_log("Efí: Último erro: " . ($last_error ?? 'nenhum'));
    return false;
}

/**
 * Registra webhook na Efí para uma chave Pix
 * 
 * @param string $access_token Token de acesso OAuth2
 * @param string $pix_key Chave Pix
 * @param string $webhook_url URL do webhook
 * @return bool true se sucesso, false caso contrário
 */
function efi_register_webhook($access_token, $pix_key, $webhook_url) {
    if (empty($access_token) || empty($pix_key) || empty($webhook_url)) {
        error_log("Efí: Parâmetros inválidos para registrar webhook");
        return false;
    }
    
    $url = 'https://pix.api.efipay.com.br/v2/webhook/' . urlencode($pix_key);
    
    $payload = [
        'webhookUrl' => $webhook_url
    ];
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_PUT, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $access_token,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    
    $response = curl_exec($ch);
    $curl_error = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($curl_error) {
        error_log("Efí Register Webhook Error: " . $curl_error);
        return false;
    }
    
    if ($http_code >= 200 && $http_code < 300) {
        return true;
    }
    
    error_log("Efí Register Webhook HTTP Error ($http_code): " . substr($response, 0, 500));
    return false;
}

/**
 * Cria uma cobrança de cartão de crédito via One Step
 * 
 * @param string $access_token Token de acesso OAuth2
 * @param float $amount Valor da cobrança em reais
 * @param string $payment_token Payment token gerado no frontend via biblioteca JavaScript Efí
 * @param array $customer_data Dados do cliente ['name' => string, 'email' => string, 'cpf' => string, 'phone' => string]
 * @param string $description Descrição da cobrança
 * @param string $webhook_url URL do webhook para notificações
 * @param string $certificate_path Caminho do certificado P12
 * @param int $installments Número de parcelas (padrão: 1)
 * @return array ['charge_id' => string, 'status' => 'approved'|'pending'|'rejected', 'message' => string] ou false em caso de erro
 */
function efi_create_card_charge($access_token, $amount, $payment_token, $customer_data, $description = '', $webhook_url = '', $certificate_path = null, $installments = 1) {
    // Remover espaços em branco
    $access_token = trim($access_token);
    $payment_token = trim($payment_token);
    if ($certificate_path) {
        $certificate_path = trim($certificate_path);
        $certificate_path = str_replace('\\', '/', $certificate_path);
    }
    
    if (empty($access_token) || empty($payment_token) || $amount <= 0) {
        error_log("Efí Cartão: Parâmetros inválidos - access_token: " . (!empty($access_token) ? 'presente' : 'vazio') . ", payment_token: " . (!empty($payment_token) ? 'presente' : 'vazio') . ", amount: $amount");
        return false;
    }
    
    // Validar CPF (remover formatação)
    $cpf = preg_replace('/[^0-9]/', '', $customer_data['cpf'] ?? '');
    if (strlen($cpf) !== 11) {
        error_log("Efí Cartão: CPF inválido - CPF fornecido: " . ($customer_data['cpf'] ?? 'vazio') . ", CPF limpo: $cpf, Tamanho: " . strlen($cpf));
        return false;
    }
    
    // Validar se CPF não é uma sequência de zeros ou números repetidos
    if (preg_match('/^(\d)\1{10}$/', $cpf)) {
        error_log("Efí Cartão: CPF inválido - CPF é uma sequência repetida: $cpf");
        return false;
    }
    
    // Validar dígitos verificadores do CPF (reutilizar função se já existir)
    if (!function_exists('validarCPF')) {
        function validarCPF($cpf) {
            $cpf = preg_replace('/[^0-9]/', '', $cpf);
            if (strlen($cpf) != 11) return false;
            if (preg_match('/(\d)\1{10}/', $cpf)) return false;
            for ($t = 9; $t < 11; $t++) {
                for ($d = 0, $c = 0; $c < $t; $c++) {
                    $d += $cpf[$c] * (($t + 1) - $c);
                }
                $d = ((10 * $d) % 11) % 10;
                if ($cpf[$c] != $d) return false;
            }
            return true;
        }
    }
    
    if (!validarCPF($cpf)) {
        error_log("Efí Cartão: CPF inválido - CPF não passa na validação de dígitos verificadores: $cpf");
        return false;
    }
    
    // Validar email
    $email = filter_var($customer_data['email'] ?? '', FILTER_VALIDATE_EMAIL);
    if (!$email) {
        error_log("Efí Cartão: Email inválido: " . ($customer_data['email'] ?? 'vazio'));
        return false;
    }
    
    // Validar parcelas (deve ser entre 1 e 12)
    $installments = (int)$installments;
    if ($installments < 1 || $installments > 12) {
        error_log("Efí Cartão: Número de parcelas inválido: $installments (deve ser entre 1 e 12)");
        $installments = 1;
    }
    
    // URL correta da API de Cobranças da Efí (produção)
    // Para sandbox, seria: https://cobrancas-h.api.efipay.com.br
    $url = 'https://cobrancas.api.efipay.com.br/v1/charge/one-step';
    
    // Formatar valor (Efí espera valor em centavos)
    $amount_cents = (int)round((float)$amount * 100);
    
    // Formatar telefone (remover caracteres não numéricos)
    $phone = preg_replace('/[^0-9]/', '', $customer_data['phone'] ?? '');
    if (strlen($phone) < 10) {
        $phone = '00000000000'; // Telefone padrão se inválido
    }
    
    // Preparar payload conforme documentação Efí
    $payload = [
        'items' => [
            [
                'name' => !empty($description) ? substr($description, 0, 200) : 'Produto',
                'value' => $amount_cents,
                'amount' => 1
            ]
        ],
        'payment' => [
            'credit_card' => [
                'payment_token' => $payment_token,
                'billing_address' => [
                    'street' => 'Rua',
                    'number' => '0',
                    'neighborhood' => 'Centro',
                    'zipcode' => '00000000',
                    'city' => 'São Paulo',
                    'state' => 'SP'
                ],
                'customer' => [
                    'name' => $customer_data['name'] ?? 'Cliente',
                    'email' => $email,
                    'cpf' => $cpf,
                    'phone_number' => $phone
                ],
                'installments' => $installments
            ]
        ]
    ];
    
    // Adicionar metadata com webhook URL e custom_id se fornecido
    if (!empty($webhook_url)) {
        // Gerar custom_id sem pontos (apenas letras, números, underscore e hífen)
        // A API Efí exige que custom_id corresponda a: ^[a-zA-Z0-9_-s]+$
        $custom_id = 'efi_' . uniqid('', true);
        // Remover pontos e substituir por underscore
        $custom_id = str_replace('.', '_', $custom_id);
        
        $payload['metadata'] = [
            'notification_url' => $webhook_url,
            'custom_id' => $custom_id
        ];
    }
    
    error_log("Efí Cartão: Criando cobrança - Valor: R$ " . number_format($amount, 2, ',', '.') . " ($amount_cents centavos), Parcelas: $installments");
    error_log("Efí Cartão: Payment Token (primeiros 30 chars): " . substr($payment_token, 0, 30) . "... (tamanho: " . strlen($payment_token) . ")");
    
    // Log do payload (sem dados sensíveis)
    $payload_for_log = $payload;
    if (isset($payload_for_log['payment']['credit_card']['payment_token'])) {
        $payload_for_log['payment']['credit_card']['payment_token'] = '[OCULTO - Tamanho: ' . strlen($payment_token) . ']';
    }
    error_log("Efí Cartão: Payload completo: " . json_encode($payload_for_log, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $access_token,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    
    // Usar certificado P12 se fornecido
    if (!empty($certificate_path) && file_exists($certificate_path)) {
        $cert_path_normalized = str_replace('\\', '/', $certificate_path);
        curl_setopt($ch, CURLOPT_SSLCERT, $cert_path_normalized);
        curl_setopt($ch, CURLOPT_SSLCERTTYPE, 'P12');
        error_log("Efí Cartão: Usando certificado P12: " . $cert_path_normalized);
    }
    
    $response = curl_exec($ch);
    $curl_error = curl_error($ch);
    $curl_errno = curl_errno($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($curl_error || $curl_errno) {
        error_log("Efí Cartão Create Charge cURL Error (errno: $curl_errno): " . $curl_error);
        error_log("Efí Cartão: URL da requisição: " . $url);
        return false;
    }
    
    if (empty($response)) {
        error_log("Efí Cartão Create Charge: Resposta vazia do servidor (HTTP $http_code)");
        return false;
    }
    
    if ($http_code < 200 || $http_code >= 300) {
        error_log("Efí Cartão Create Charge HTTP Error ($http_code): " . substr($response, 0, 1000));
        $error_data = json_decode($response, true);
        $error_message = 'Erro ao processar pagamento';
        if ($error_data) {
            error_log("Efí Cartão Create Charge Error Details: " . json_encode($error_data));
            if (isset($error_data['message'])) {
                $error_message = $error_data['message'];
            } elseif (isset($error_data['mensagem'])) {
                $error_message = $error_data['mensagem'];
            } elseif (isset($error_data['error_description'])) {
                $error_message = $error_data['error_description'];
            }
            // Verificar se há detalhes específicos de erro
            if (isset($error_data['errors']) && is_array($error_data['errors'])) {
                $error_messages = [];
                foreach ($error_data['errors'] as $error) {
                    if (isset($error['message'])) {
                        $error_messages[] = $error['message'];
                    }
                }
                if (!empty($error_messages)) {
                    $error_message = implode(', ', $error_messages);
                }
            }
        }
        return [
            'error' => true,
            'message' => $error_message,
            'http_code' => $http_code,
            'error_data' => $error_data
        ];
    }
    
    $data = json_decode($response, true);
    
    // Log completo da resposta para debug
    error_log("Efí Cartão: Resposta completa da criação (primeiros 3000 chars): " . substr($response, 0, 3000));
    error_log("Efí Cartão: Estrutura JSON decodificada: " . json_encode($data));
    
    // A resposta pode vir em $data['data'] ou diretamente em $data
    $response_data = $data['data'] ?? $data;
    
    if (!isset($response_data['charge_id'])) {
        error_log("Efí Cartão: charge_id não encontrado na resposta");
        error_log("Efí Cartão: Estrutura da resposta: " . json_encode($data));
        error_log("Efí Cartão: Resposta completa: " . substr($response, 0, 1000));
        return false;
    }
    
    $charge_id = $response_data['charge_id'];
    
    // IMPORTANTE: A API Efí pode retornar status em diferentes lugares na resposta de criação
    // Verificar múltiplos locais: response_data['status'], response_data['payment']['credit_card']['status'], data['status'], etc.
    $status_raw = 'unpaid'; // Padrão
    
    // Verificar também se há informações de pagamento processado em response_data['payment']
    $has_payment_info = isset($response_data['payment']);
    if ($has_payment_info) {
        error_log("Efí Cartão: Campo 'payment' encontrado na resposta: " . json_encode($response_data['payment']));
    }
    
    if (isset($response_data['status'])) {
        $status_raw = strtolower($response_data['status']);
        error_log("Efí Cartão: Status encontrado em response_data['status']: " . $status_raw);
    } elseif (isset($response_data['payment']['credit_card']['status'])) {
        $status_raw = strtolower($response_data['payment']['credit_card']['status']);
        error_log("Efí Cartão: Status encontrado em response_data['payment']['credit_card']['status']: " . $status_raw);
    } elseif (isset($response_data['payment']['status'])) {
        $status_raw = strtolower($response_data['payment']['status']);
        error_log("Efí Cartão: Status encontrado em response_data['payment']['status']: " . $status_raw);
    } elseif (isset($data['status'])) {
        $status_raw = strtolower($data['status']);
        error_log("Efí Cartão: Status encontrado em data['status']: " . $status_raw);
    } elseif (isset($data['data']['status'])) {
        $status_raw = strtolower($data['data']['status']);
        error_log("Efí Cartão: Status encontrado em data['data']['status']: " . $status_raw);
    } else {
        error_log("Efí Cartão: AVISO - Status não encontrado na resposta de criação. Estrutura completa: " . json_encode($response_data));
        error_log("Efí Cartão: Chaves disponíveis em response_data: " . implode(', ', array_keys($response_data)));
    }
    
    // IMPORTANTE: A API Efí retorna recusa em data.refusal.reason, não em data.reason
    // Verificar campo refusal.reason primeiro
    $reason = '';
    if (isset($response_data['refusal']['reason'])) {
        $reason = strtolower($response_data['refusal']['reason']);
        error_log("Efí Cartão: refusal.reason encontrado na criação: " . $reason);
    } elseif (isset($response_data['reason'])) {
        $reason = strtolower($response_data['reason']);
    } elseif (isset($data['refusal']['reason'])) {
        $reason = strtolower($data['refusal']['reason']);
        error_log("Efí Cartão: refusal.reason encontrado em data['refusal']['reason']: " . $reason);
    }
    
    $message = strtolower($response_data['message'] ?? ($data['message'] ?? ''));
    
    // Normalizar status: paid → approved, unpaid → pending, waiting → pending, refunded → refunded
    $status_normalized = 'pending';
    if ($status_raw === 'paid') {
        $status_normalized = 'approved';
        error_log("Efí Cartão: Status normalizado para 'approved' (status_raw: paid)");
    } elseif ($status_raw === 'unpaid' || $status_raw === 'waiting') {
        // Se unpaid mas tem refusal.reason, tratar como rejected
        if (!empty($reason)) {
            // Se existe refusal.reason, significa que foi recusado
            $status_normalized = 'rejected';
            error_log("Efí Cartão: Status normalizado para 'rejected' devido a refusal.reason: " . $reason);
        } else {
            $status_normalized = 'pending';
            error_log("Efí Cartão: Status normalizado para 'pending' (status_raw: $status_raw, sem refusal.reason)");
        }
    } elseif ($status_raw === 'refunded') {
        $status_normalized = 'refunded';
    } elseif (in_array($status_raw, ['canceled', 'expired'])) {
        $status_normalized = 'rejected';
    }
    
    error_log("Efí Cartão: Cobrança criada - charge_id: $charge_id, status_raw: $status_raw, status_normalized: $status_normalized, refusal.reason: " . ($reason ?: 'não informado') . ", message: " . ($message ?: 'não informado'));
    
    return [
        'charge_id' => $charge_id,
        'status' => $status_normalized,
        'message' => $response_data['message'] ?? ($data['message'] ?? ''),
        'status_raw' => $status_raw,
        'reason' => $reason
    ];
}

/**
 * Consulta o status de uma cobrança de cartão de crédito
 * 
 * @param string $access_token Token de acesso OAuth2
 * @param string $charge_id ID da cobrança (charge_id)
 * @param string|null $certificate_path Caminho do certificado P12 (opcional, mas recomendado)
 * @return array|false ['status' => string, 'valor' => float] ou false
 */
function efi_get_card_charge_status($access_token, $charge_id, $certificate_path = null) {
    $access_token = trim($access_token);
    $charge_id = trim($charge_id);
    
    if (empty($access_token) || empty($charge_id)) {
        error_log("Efí Cartão: Parâmetros inválidos para consultar status - access_token: " . (!empty($access_token) ? 'presente' : 'vazio') . ", charge_id: " . (!empty($charge_id) ? 'presente' : 'vazio'));
        return false;
    }
    
    // URL correta da API de Cobranças da Efí (produção)
    // Para sandbox, seria: https://cobrancas-h.api.efipay.com.br
    $url = 'https://cobrancas.api.efipay.com.br/v1/charge/' . urlencode($charge_id);
    
    error_log("Efí Cartão: Consultando status da cobrança - charge_id: " . substr($charge_id, 0, 20) . '...');
    error_log("Efí Cartão: URL da requisição: $url");
    error_log("Efí Cartão: Access token (primeiros 30 chars): " . substr($access_token, 0, 30) . '...');
    error_log("Efí Cartão: Certificado path fornecido: " . ($certificate_path ?: 'não fornecido'));
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $access_token,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    
    // Usar certificado P12 se fornecido (pode ser necessário para mutual TLS)
    if (!empty($certificate_path) && file_exists($certificate_path)) {
        $cert_path_normalized = str_replace('\\', '/', $certificate_path);
        curl_setopt($ch, CURLOPT_SSLCERT, $cert_path_normalized);
        curl_setopt($ch, CURLOPT_SSLCERTTYPE, 'P12');
        error_log("Efí Cartão: Usando certificado P12 na consulta de status: " . $cert_path_normalized);
    } else {
        error_log("Efí Cartão: AVISO - Certificado P12 não fornecido ou não encontrado. A requisição pode falhar se a API exigir mutual TLS.");
    }
    
    $response = curl_exec($ch);
    $curl_error = curl_error($ch);
    $curl_errno = curl_errno($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $effective_url = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);
    
    error_log("Efí Cartão: Resposta HTTP Code: $http_code");
    error_log("Efí Cartão: URL efetiva: " . ($effective_url ?: $url));
    error_log("Efí Cartão: Resposta (primeiros 500 chars): " . substr($response, 0, 500));
    
    if ($curl_error || $curl_errno) {
        error_log("Efí Cartão Get Status cURL Error (errno: $curl_errno): " . $curl_error);
        error_log("Efí Cartão: URL da requisição: $url");
        error_log("Efí Cartão: Access token (primeiros 20 chars): " . substr($access_token, 0, 20) . '...');
        // Retornar pending ao invés de false para que o polling continue
        return ['status' => 'pending', 'error' => true, 'curl_error' => $curl_error];
    }
    
    if ($http_code === 404) {
        error_log("Efí Cartão: Cobrança não encontrada (404) - charge_id: $charge_id");
        error_log("Efí Cartão: Resposta completa (404): " . substr($response, 0, 1000));
        return ['status' => 'pending'];
    }
    
    if ($http_code !== 200) {
        error_log("Efí Cartão Get Status HTTP Error ($http_code): " . substr($response, 0, 1000));
        error_log("Efí Cartão: URL da requisição: $url");
        error_log("Efí Cartão: Access token (primeiros 20 chars): " . substr($access_token, 0, 20) . '...');
        
        // Tentar decodificar resposta de erro para mais informações
        $error_data = json_decode($response, true);
        if ($error_data) {
            error_log("Efí Cartão: Dados do erro: " . json_encode($error_data));
        }
        
        // Mesmo com erro HTTP, tentar retornar status pending ao invés de false
        // para que o polling continue tentando
        return ['status' => 'pending', 'error' => true, 'http_code' => $http_code];
    }
    
    $data = json_decode($response, true);
    
    if (!$data) {
        error_log("Efí Cartão: Resposta inválida ao consultar status");
        error_log("Efí Cartão: Resposta raw (primeiros 1000 chars): " . substr($response, 0, 1000));
        error_log("Efí Cartão: JSON decode error: " . json_last_error_msg());
        
        // Retornar pending ao invés de false para continuar tentando
        return ['status' => 'pending', 'error' => true, 'message' => 'Resposta inválida da API'];
    }
    
    // Log da estrutura completa da resposta para debug
    error_log("Efí Cartão: Estrutura da resposta JSON (primeiros 2000 chars): " . substr(json_encode($data), 0, 2000));
    error_log("Efí Cartão: Chaves disponíveis na resposta: " . (is_array($data) ? implode(', ', array_keys($data)) : 'não é array'));
    
    // Extrair status da resposta - pode estar em data['data']['status'] ou data['status']
    $status_raw = 'unpaid'; // Padrão
    if (isset($data['data']['status'])) {
        $status_raw = strtolower($data['data']['status']);
        error_log("Efí Cartão: Status encontrado em data['data']['status']: " . $status_raw);
    } elseif (isset($data['status'])) {
        $status_raw = strtolower($data['status']);
        error_log("Efí Cartão: Status encontrado em data['status']: " . $status_raw);
    } else {
        error_log("Efí Cartão: AVISO - Status não encontrado na resposta. Estrutura completa: " . json_encode($data));
        // Se não encontrou status, retornar pending mas continuar processamento
    }
    $valor = null;
    
    // Verificar se há campo "reason" ou mensagem que indica recusa
    // A API Efí pode retornar reason em diferentes lugares: data['data']['reason'], data['data']['payment']['credit_card']['reason'], etc.
    $reason = '';
    $message = '';
    
    // IMPORTANTE: A API Efí retorna recusa em data.refusal.reason, não em data.reason
    // Verificar campo refusal.reason primeiro (prioridade)
    if (isset($data['data']['refusal']['reason'])) {
        $reason = strtolower($data['data']['refusal']['reason']);
        error_log("Efí Cartão: refusal.reason encontrado na consulta (data.data.refusal.reason): " . $reason);
    } elseif (isset($data['refusal']['reason'])) {
        $reason = strtolower($data['refusal']['reason']);
        error_log("Efí Cartão: refusal.reason encontrado na consulta (data.refusal.reason): " . $reason);
    } elseif (isset($data['data']['reason'])) {
        $reason = strtolower($data['data']['reason']);
    } elseif (isset($data['data']['payment']['credit_card']['reason'])) {
        $reason = strtolower($data['data']['payment']['credit_card']['reason']);
    } elseif (isset($data['reason'])) {
        $reason = strtolower($data['reason']);
    }
    
    // Tentar encontrar message em diferentes locais da resposta
    if (isset($data['data']['message'])) {
        $message = strtolower($data['data']['message']);
    } elseif (isset($data['data']['payment']['credit_card']['message'])) {
        $message = strtolower($data['data']['payment']['credit_card']['message']);
    } elseif (isset($data['message'])) {
        $message = strtolower($data['message']);
    }
    
    // Log detalhado para debug
    error_log("Efí Cartão: Estrutura completa da resposta (primeiros 2000 chars): " . substr(json_encode($data), 0, 2000));
    
    // Tentar extrair valor de diferentes campos possíveis
    if (isset($data['data']['total'])) {
        $valor = (float)($data['data']['total'] / 100); // Converter centavos para reais
    } elseif (isset($data['data']['value'])) {
        $valor = (float)($data['data']['value'] / 100);
    } elseif (isset($data['data']['amount'])) {
        $valor = (float)($data['data']['amount'] / 100);
    } elseif (isset($data['total'])) {
        $valor = (float)($data['total'] / 100);
    } elseif (isset($data['value'])) {
        $valor = (float)($data['value'] / 100);
    } elseif (isset($data['amount'])) {
        $valor = (float)($data['amount'] / 100);
    }
    
    // Verificar se há informações de pagamento processado em data['data']['payment']
    // A API EFI pode retornar o status do pagamento em payment.credit_card.status mesmo quando o status geral é 'unpaid'
    $payment_status_raw = null;
    if (isset($data['data']['payment']['credit_card']['status'])) {
        $payment_status_raw = strtolower($data['data']['payment']['credit_card']['status']);
        error_log("Efí Cartão: payment.credit_card.status encontrado na consulta: " . $payment_status_raw);
    } elseif (isset($data['data']['payment']['status'])) {
        $payment_status_raw = strtolower($data['data']['payment']['status']);
        error_log("Efí Cartão: payment.status encontrado na consulta: " . $payment_status_raw);
    }
    
    // Se payment_status_raw indica aprovação, usar ele ao invés de status_raw
    if ($payment_status_raw === 'paid' || $payment_status_raw === 'approved') {
        $status_raw = 'paid';
        error_log("Efí Cartão: Usando payment.status ('$payment_status_raw') como status principal - normalizando para 'paid'");
    }
    
    // Normalizar status
    $status_normalized = 'pending';
    if ($status_raw === 'paid') {
        $status_normalized = 'approved';
        error_log("Efí Cartão: Status normalizado para 'approved' (status_raw: paid)");
    } elseif ($status_raw === 'unpaid' || $status_raw === 'waiting') {
        // Se unpaid mas tem refusal.reason, significa que foi recusado
        // A API Efí retorna refusal.reason quando o pagamento é recusado
        if (!empty($reason)) {
            // Se existe refusal.reason, significa que foi recusado
            $status_normalized = 'rejected';
            error_log("Efí Cartão: Status normalizado para 'rejected' devido a refusal.reason: " . $reason);
        } else {
            $status_normalized = 'pending';
            error_log("Efí Cartão: Status normalizado para 'pending' (status_raw: $status_raw, sem refusal.reason)");
        }
    } elseif ($status_raw === 'refunded') {
        $status_normalized = 'refunded';
    } elseif (in_array($status_raw, ['canceled', 'expired'])) {
        $status_normalized = 'rejected';
    }
    
    error_log("Efí Cartão: Status consultado - charge_id: $charge_id, status_raw: $status_raw, payment_status_raw: " . ($payment_status_raw ?: 'N/A') . ", status_normalized: $status_normalized, reason: " . ($reason ?: 'não informado') . ", message: " . ($message ?: 'não informado') . ", valor: " . ($valor ?? 'não informado'));
    
    return [
        'status' => $status_normalized,
        'valor' => $valor,
        'status_raw' => $status_raw,
        'reason' => !empty($reason) ? $reason : null,
        'message' => !empty($message) ? $message : null
    ];
}

