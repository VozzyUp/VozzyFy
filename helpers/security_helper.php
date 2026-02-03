<?php
/**
 * Security Helper Functions
 * Funções auxiliares para segurança: rate limiting, CSRF, logs de segurança
 */

if (!function_exists('get_client_ip')) {
    /**
     * Obtém o endereço IP real do cliente
     * @return string IP address
     */
    function get_client_ip() {
        $ip_keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
        $ip_found = null;
        
        foreach ($ip_keys as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = $_SERVER[$key];
                if ($key === 'HTTP_X_FORWARDED_FOR') {
                    $ips = explode(',', $ip);
                    $ip = trim($ips[0]);
                }
                // Primeiro tenta validar como IP público
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
                // Se não encontrou IP público, guarda o primeiro IP válido encontrado (pode ser privado/localhost)
                if ($ip_found === null && filter_var($ip, FILTER_VALIDATE_IP)) {
                    $ip_found = $ip;
                }
            }
        }
        
        // Retorna o IP encontrado (mesmo que seja privado) ou o REMOTE_ADDR como fallback
        return $ip_found ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}

if (!function_exists('check_login_attempts')) {
    /**
     * Verifica se o IP/email pode tentar fazer login (rate limiting)
     * @param string $ip Endereço IP
     * @param string|null $email Email do usuário (opcional)
     * @return array ['allowed' => bool, 'blocked_until' => datetime|null, 'attempts' => int]
     */
    function check_login_attempts($ip, $email = null) {
        global $pdo;
        
        try {
            // Limpa tentativas antigas (mais de 24 horas)
            $pdo->exec("DELETE FROM login_attempts WHERE last_attempt < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
            
            // Verifica bloqueio por IP
            $stmt = $pdo->prepare("
                SELECT attempts, blocked_until 
                FROM login_attempts 
                WHERE ip_address = ? 
                ORDER BY last_attempt DESC 
                LIMIT 1
            ");
            $stmt->execute([$ip]);
            $ip_record = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($ip_record) {
                // Verifica se está bloqueado
                if ($ip_record['blocked_until'] && strtotime($ip_record['blocked_until']) > time()) {
                    return [
                        'allowed' => false,
                        'blocked_until' => $ip_record['blocked_until'],
                        'attempts' => $ip_record['attempts'],
                        'reason' => 'IP bloqueado temporariamente'
                    ];
                }
            }
            
            // Se email fornecido, verifica também por email
            if ($email) {
                $stmt = $pdo->prepare("
                    SELECT attempts, blocked_until 
                    FROM login_attempts 
                    WHERE email = ? 
                    ORDER BY last_attempt DESC 
                    LIMIT 1
                ");
                $stmt->execute([$email]);
                $email_record = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($email_record && $email_record['blocked_until'] && strtotime($email_record['blocked_until']) > time()) {
                    return [
                        'allowed' => false,
                        'blocked_until' => $email_record['blocked_until'],
                        'attempts' => $email_record['attempts'],
                        'reason' => 'Email bloqueado temporariamente'
                    ];
                }
            }
            
            return ['allowed' => true, 'blocked_until' => null, 'attempts' => $ip_record['attempts'] ?? 0];
            
        } catch (PDOException $e) {
            error_log("Erro ao verificar tentativas de login: " . $e->getMessage());
            // Em caso de erro, permite tentativa (fail-open para não bloquear usuários legítimos)
            return ['allowed' => true, 'blocked_until' => null, 'attempts' => 0];
        }
    }
}

if (!function_exists('record_failed_login')) {
    /**
     * Registra uma tentativa de login falha
     * @param string $ip Endereço IP
     * @param string|null $email Email do usuário (opcional)
     * @return void
     */
    function record_failed_login($ip, $email = null) {
        global $pdo;
        
        try {
            // Busca registro existente
            $stmt = $pdo->prepare("
                SELECT id, attempts, blocked_until 
                FROM login_attempts 
                WHERE ip_address = ? " . ($email ? "AND email = ?" : "AND email IS NULL") . "
                ORDER BY last_attempt DESC 
                LIMIT 1
            ");
            
            if ($email) {
                $stmt->execute([$ip, $email]);
            } else {
                $stmt->execute([$ip]);
            }
            
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $new_attempts = ($existing ? $existing['attempts'] : 0) + 1;
            $blocked_until = null;
            
            // Define bloqueio baseado no número de tentativas (melhorado: 5 tentativas = 1 hora)
            if ($new_attempts >= 5) {
                // 5 tentativas = 1 hora de bloqueio (aumentado de 15 minutos)
                $blocked_until = date('Y-m-d H:i:s', strtotime('+1 hour'));
                
                // Notificar administrador de múltiplas tentativas (opcional)
                if ($new_attempts == 5) {
                    log_security_event('login_brute_force_detected', [
                        'ip' => $ip,
                        'email' => $email,
                        'attempts' => $new_attempts
                    ]);
                }
            } elseif ($new_attempts >= 3) {
                // 3 tentativas = 15 minutos de bloqueio (para alertar usuário)
                $blocked_until = date('Y-m-d H:i:s', strtotime('+15 minutes'));
            }
            
            if ($existing) {
                // Atualiza registro existente
                $stmt = $pdo->prepare("
                    UPDATE login_attempts 
                    SET attempts = ?, last_attempt = NOW(), blocked_until = ?
                    WHERE id = ?
                ");
                $stmt->execute([$new_attempts, $blocked_until, $existing['id']]);
            } else {
                // Cria novo registro
                $stmt = $pdo->prepare("
                    INSERT INTO login_attempts (ip_address, email, attempts, last_attempt, blocked_until)
                    VALUES (?, ?, ?, NOW(), ?)
                ");
                $stmt->execute([$ip, $email, $new_attempts, $blocked_until]);
            }
            
            // Log de segurança
            log_security_event('failed_login_attempt', [
                'ip' => $ip,
                'email' => $email,
                'attempts' => $new_attempts,
                'blocked_until' => $blocked_until
            ]);
            
        } catch (PDOException $e) {
            error_log("Erro ao registrar tentativa de login falha: " . $e->getMessage());
        }
    }
}

if (!function_exists('clear_login_attempts')) {
    /**
     * Limpa tentativas de login após login bem-sucedido
     * @param string $ip Endereço IP
     * @param string|null $email Email do usuário (opcional)
     * @return void
     */
    function clear_login_attempts($ip, $email = null) {
        global $pdo;
        
        try {
            if ($email) {
                $stmt = $pdo->prepare("DELETE FROM login_attempts WHERE ip_address = ? OR email = ?");
                $stmt->execute([$ip, $email]);
            } else {
                $stmt = $pdo->prepare("DELETE FROM login_attempts WHERE ip_address = ?");
                $stmt->execute([$ip]);
            }
        } catch (PDOException $e) {
            error_log("Erro ao limpar tentativas de login: " . $e->getMessage());
        }
    }
}

if (!function_exists('is_ip_blocked')) {
    /**
     * Verifica se um IP está bloqueado
     * @param string $ip Endereço IP
     * @return bool
     */
    function is_ip_blocked($ip) {
        global $pdo;
        
        try {
            $stmt = $pdo->prepare("
                SELECT blocked_until 
                FROM login_attempts 
                WHERE ip_address = ? AND blocked_until IS NOT NULL AND blocked_until > NOW()
                LIMIT 1
            ");
            $stmt->execute([$ip]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            error_log("Erro ao verificar bloqueio de IP: " . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('generate_csrf_token')) {
    /**
     * Gera um token CSRF único com double-submit cookie pattern
     * @return string Token CSRF
     */
    function generate_csrf_token() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // Gera novo token se não existir ou se expirou (7 dias)
        // Renova automaticamente se faltam menos de 1 dia (86400 segundos) para expirar
        $csrf_lifetime = 604800; // 7 dias
        $csrf_renewal_threshold = 518400; // 6 dias (renova 1 dia antes de expirar)
        $token_age = isset($_SESSION['csrf_token_time']) ? (time() - $_SESSION['csrf_token_time']) : ($csrf_lifetime + 1);
        $token_needs_renewal = empty($_SESSION['csrf_token']) || $token_age > $csrf_lifetime || $token_age > $csrf_renewal_threshold;
        
        if ($token_needs_renewal) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            $_SESSION['csrf_token_time'] = time();
            
            // Double-submit cookie pattern: também salva em cookie com SameSite
            // Usa 'Lax' ao invés de 'Strict' para melhor compatibilidade com requisições AJAX
            $cookie_options = [
                'expires' => time() + $csrf_lifetime, // 7 dias
                'path' => '/',
                'domain' => '',
                'secure' => is_https(), // Apenas HTTPS se disponível
                'httponly' => false, // Precisa ser acessível via JavaScript para double-submit
                'samesite' => 'Lax' // Lax oferece boa proteção e melhor compatibilidade
            ];
            
            // Usar setcookie com array de opções (PHP 7.3+)
            if (PHP_VERSION_ID >= 70300) {
                setcookie('csrf_token_cookie', $_SESSION['csrf_token'], $cookie_options);
            } else {
                // Fallback para versões antigas do PHP
                setcookie('csrf_token_cookie', $_SESSION['csrf_token'], $cookie_options['expires'], $cookie_options['path'], $cookie_options['domain'], $cookie_options['secure'], $cookie_options['httponly']);
            }
        }
        
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('verify_csrf_token')) {
    /**
     * Verifica se o token CSRF é válido usando double-submit cookie pattern
     * @param string $token Token a verificar
     * @return bool True se válido, False caso contrário
     */
    function verify_csrf_token($token) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        if (empty($_SESSION['csrf_token']) || empty($token)) {
            error_log("CSRF Verify: Token vazio ou ausente");
            return false;
        }
        
        // Verifica se token expirou (7 dias)
        if (isset($_SESSION['csrf_token_time']) && (time() - $_SESSION['csrf_token_time']) > 604800) {
            error_log("CSRF Verify: Token expirado");
            unset($_SESSION['csrf_token'], $_SESSION['csrf_token_time']);
            return false;
        }
        
        // Verificação básica: token da sessão deve corresponder
        if (!hash_equals($_SESSION['csrf_token'], $token)) {
            error_log("CSRF Verify: Token inválido");
            return false;
        }
        
        // Double-submit cookie pattern: verifica se cookie também corresponde (se presente)
        // IMPORTANTE: Tornar cookie opcional para compatibilidade mobile
        // Não bloquear se cookie não estiver presente (mobile pode não enviar cookies em algumas situações)
        // Bloquear apenas se cookie estiver presente e não corresponder ao token
        $cookie_token = $_COOKIE['csrf_token_cookie'] ?? null;
        if (!empty($cookie_token)) {
            if (!hash_equals($token, $cookie_token)) {
                log_security_event('csrf_cookie_mismatch', [
                    'ip' => get_client_ip(),
                    'endpoint' => $_SERVER['REQUEST_URI'] ?? 'unknown',
                    'method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown'
                ]);
                return false;
            }
        }
        // Se cookie não estiver presente, ainda permite (compatibilidade mobile)
        // A verificação principal do token da sessão já foi feita acima
        
        // Verificação de origem (Origin/Referer header) para requisições POST/PUT/DELETE
        // Importante: Alguns navegadores podem não enviar Origin em certas situações
        // Por isso, verificamos mas não bloqueamos se ausente (compatibilidade)
        if (in_array($_SERVER['REQUEST_METHOD'] ?? '', ['POST', 'PUT', 'DELETE', 'PATCH'])) {
            $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
            $referer = $_SERVER['HTTP_REFERER'] ?? '';
            $host = $_SERVER['HTTP_HOST'] ?? '';
            
            // Se Origin está presente, validar
            if (!empty($origin)) {
                $origin_host = parse_url($origin, PHP_URL_HOST);
                $expected_host = $host;
                
                // Normalizar hosts (remover porta se presente)
                $origin_host = preg_replace('/:\d+$/', '', $origin_host);
                $expected_host = preg_replace('/:\d+$/', '', $expected_host);
                
                if (strtolower($origin_host) !== strtolower($expected_host) && !empty($expected_host)) {
                    log_security_event('csrf_origin_mismatch', [
                        'ip' => get_client_ip(),
                        'origin' => $origin,
                        'expected_host' => $expected_host,
                        'endpoint' => $_SERVER['REQUEST_URI'] ?? 'unknown'
                    ]);
                    // Bloquear apenas se Origin está presente e não corresponde
                    // (não bloquear se Origin ausente para compatibilidade)
                    return false;
                }
            } elseif (!empty($referer)) {
                // Fallback: verificar Referer se Origin não estiver presente
                $referer_host = parse_url($referer, PHP_URL_HOST);
                $expected_host = preg_replace('/:\d+$/', '', $host);
                $referer_host = preg_replace('/:\d+$/', '', $referer_host);
                
                if (!empty($referer_host) && strtolower($referer_host) !== strtolower($expected_host)) {
                    log_security_event('csrf_referer_mismatch', [
                        'ip' => get_client_ip(),
                        'referer' => $referer,
                        'expected_host' => $expected_host,
                        'endpoint' => $_SERVER['REQUEST_URI'] ?? 'unknown'
                    ]);
                    // Bloquear apenas se Referer está presente e não corresponde
                    return false;
                }
            }
        }
        
        return true;
    }
}

if (!function_exists('log_security_event')) {
    /**
     * Registra um evento de segurança
     * @param string $event_type Tipo do evento (ex: 'failed_login', 'unauthorized_access')
     * @param array $details Detalhes do evento
     * @param int|null $user_id ID do usuário (se aplicável)
     * @return void
     */
    function log_security_event($event_type, $details = [], $user_id = null) {
        global $pdo;
        
        try {
            $ip = get_client_ip();
            $details_json = json_encode($details);
            
            $stmt = $pdo->prepare("
                INSERT INTO security_logs (event_type, user_id, ip_address, details, created_at)
                VALUES (?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$event_type, $user_id, $ip, $details_json]);
            
        } catch (PDOException $e) {
            error_log("Erro ao registrar evento de segurança: " . $e->getMessage());
        }
    }
}

if (!function_exists('check_session_timeout')) {
    /**
     * Verifica e atualiza timeout de sessão
     * @param int $timeout_seconds Tempo limite em segundos (padrão: 604800 = 7 dias)
     * @return bool True se sessão válida, False se expirada
     */
    function check_session_timeout($timeout_seconds = 604800) {
        if (session_status() === PHP_SESSION_NONE) {
            return false;
        }
        
        if (isset($_SESSION['last_activity'])) {
            if ((time() - $_SESSION['last_activity']) > $timeout_seconds) {
                // Sessão expirada
                session_destroy();
                return false;
            }
        }
        
        // Atualiza última atividade
        $_SESSION['last_activity'] = time();
        return true;
    }
}

if (!function_exists('is_https')) {
    /**
     * Verifica se a conexão é HTTPS
     * @return bool
     */
    function is_https() {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || 
               $_SERVER['SERVER_PORT'] == 443 ||
               (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
    }
}

if (!function_exists('validate_uploaded_file')) {
    /**
     * Valida e processa upload de arquivo de forma segura
     * @param array $file Array $_FILES['campo']
     * @param array $allowed_types Whitelist de tipos MIME permitidos
     * @param array $allowed_extensions Whitelist de extensões permitidas
     * @param int $max_size Tamanho máximo em bytes
     * @param string $upload_dir Diretório de destino (deve terminar com /)
     * @param string $prefix Prefixo para o nome do arquivo
     * @return array ['success' => bool, 'file_path' => string|null, 'error' => string|null]
     */
    function validate_uploaded_file($file, $allowed_types, $allowed_extensions, $max_size, $upload_dir, $prefix = 'file') {
        // Verifica se arquivo foi enviado
        if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) {
            $error_messages = [
                UPLOAD_ERR_INI_SIZE => 'Arquivo muito grande (excede upload_max_filesize)',
                UPLOAD_ERR_FORM_SIZE => 'Arquivo muito grande (excede MAX_FILE_SIZE)',
                UPLOAD_ERR_PARTIAL => 'Upload parcial',
                UPLOAD_ERR_NO_FILE => 'Nenhum arquivo enviado',
                UPLOAD_ERR_NO_TMP_DIR => 'Pasta temporária não encontrada',
                UPLOAD_ERR_CANT_WRITE => 'Falha ao escrever arquivo',
                UPLOAD_ERR_EXTENSION => 'Upload bloqueado por extensão'
            ];
            $error_code = $file['error'] ?? UPLOAD_ERR_NO_FILE;
            return [
                'success' => false,
                'file_path' => null,
                'error' => $error_messages[$error_code] ?? 'Erro desconhecido no upload'
            ];
        }

        // Valida tamanho
        if ($file['size'] > $max_size) {
            return [
                'success' => false,
                'file_path' => null,
                'error' => 'Arquivo muito grande. Máximo permitido: ' . round($max_size / 1024 / 1024, 2) . 'MB'
            ];
        }

        // Valida extensão
        $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($file_extension, $allowed_extensions)) {
            log_security_event('invalid_file_upload_attempt', [
                'filename' => $file['name'],
                'extension' => $file_extension,
                'ip' => get_client_ip()
            ]);
            return [
                'success' => false,
                'file_path' => null,
                'error' => 'Tipo de arquivo não permitido. Extensões permitidas: ' . implode(', ', $allowed_extensions)
            ];
        }

        // Valida MIME type real usando finfo_file (magic bytes)
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $real_mime_type = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($real_mime_type, $allowed_types)) {
            log_security_event('invalid_file_upload_attempt', [
                'filename' => $file['name'],
                'reported_mime' => $file['type'] ?? 'unknown',
                'real_mime' => $real_mime_type,
                'ip' => get_client_ip()
            ]);
            return [
                'success' => false,
                'file_path' => null,
                'error' => 'Tipo de arquivo não permitido. Tipo detectado: ' . $real_mime_type
            ];
        }

        // Valida também o MIME type reportado pelo navegador (deve corresponder)
        if (isset($file['type']) && !in_array($file['type'], $allowed_types)) {
            // Log mas não bloqueia, pois alguns servidores retornam MIME types diferentes
            error_log("Security: MIME type reportado não corresponde ao real. Reportado: {$file['type']}, Real: {$real_mime_type}");
        }

        // Sanitiza nome do arquivo e previne path traversal
        $sanitized_name = preg_replace('/[^a-zA-Z0-9._-]/', '', basename($file['name']));
        $sanitized_name = str_replace(['..', '/', '\\'], '', $sanitized_name);
        
        // Gera nome seguro com hash
        $safe_filename = $prefix . '_' . bin2hex(random_bytes(16)) . '_' . time() . '.' . $file_extension;
        
        // Garante que o diretório existe e é seguro
        $upload_dir = rtrim($upload_dir, '/') . '/';
        $upload_dir_absoluto = realpath(__DIR__ . '/../' . $upload_dir);
        
        // Previne path traversal - garante que está dentro do diretório permitido
        $base_dir = realpath(__DIR__ . '/../uploads');
        if (!$upload_dir_absoluto || strpos($upload_dir_absoluto, $base_dir) !== 0) {
            log_security_event('path_traversal_attempt', [
                'attempted_path' => $upload_dir,
                'ip' => get_client_ip()
            ]);
            return [
                'success' => false,
                'file_path' => null,
                'error' => 'Caminho de upload inválido'
            ];
        }

        if (!is_dir($upload_dir_absoluto)) {
            if (!mkdir($upload_dir_absoluto, 0755, true)) {
                return [
                    'success' => false,
                    'file_path' => null,
                    'error' => 'Erro ao criar diretório de upload'
                ];
            }
        }

        $target_path = $upload_dir_absoluto . '/' . $safe_filename;
        $relative_path = $upload_dir . $safe_filename;

        // Move arquivo
        if (move_uploaded_file($file['tmp_name'], $target_path)) {
            // Verifica se arquivo existe após mover
            if (file_exists($target_path)) {
                // Verificação tripla do MIME type (extensão + MIME reportado + MIME real após upload)
                $finfo_check = finfo_open(FILEINFO_MIME_TYPE);
                $final_mime = finfo_file($finfo_check, $target_path);
                finfo_close($finfo_check);
                
                // Validação rigorosa: MIME type real deve corresponder ao esperado
                if (!in_array($final_mime, $allowed_types)) {
                    // Remove arquivo se não passar na validação final
                    @unlink($target_path);
                    log_security_event('file_validation_failed_after_upload', [
                        'filename' => $safe_filename,
                        'reported_mime' => $file['type'] ?? 'unknown',
                        'real_mime' => $real_mime_type,
                        'final_mime' => $final_mime,
                        'extension' => $file_extension,
                        'ip' => get_client_ip()
                    ]);
                    return [
                        'success' => false,
                        'file_path' => null,
                        'error' => 'Validação de arquivo falhou após upload'
                    ];
                }
                
                // Validação adicional: MIME type reportado deve corresponder ao real (com tolerância)
                // Alguns servidores podem retornar variações (ex: image/jpeg vs image/jpg)
                $mime_variations = [
                    'image/jpeg' => ['image/jpg', 'image/jpeg'],
                    'image/jpg' => ['image/jpeg', 'image/jpg'],
                    'application/pdf' => ['application/pdf', 'application/x-pdf']
                ];
                
                $reported_mime = $file['type'] ?? '';
                if (!empty($reported_mime) && $reported_mime !== $final_mime) {
                    // Verificar se são variações aceitáveis
                    $is_valid_variation = false;
                    if (isset($mime_variations[$final_mime])) {
                        $is_valid_variation = in_array($reported_mime, $mime_variations[$final_mime]);
                    }
                    
                    if (!$is_valid_variation) {
                        // Log mas não bloqueia (alguns servidores têm variações)
                        error_log("Security: MIME type reportado não corresponde ao real. Reportado: {$reported_mime}, Real: {$final_mime}");
                    }
                }
                
                // Validação de conteúdo real (prevenção de upload de arquivos maliciosos)
                $content_validation = validate_file_content($target_path, $allowed_types, $file_extension);
                if (!$content_validation['valid']) {
                    @unlink($target_path);
                    log_security_event('file_content_validation_failed', [
                        'filename' => $safe_filename,
                        'error' => $content_validation['error'],
                        'ip' => get_client_ip()
                    ]);
                    return [
                        'success' => false,
                        'file_path' => null,
                        'error' => $content_validation['error']
                    ];
                }
                
                return [
                    'success' => true,
                    'file_path' => $relative_path,
                    'error' => null
                ];
            } else {
                return [
                    'success' => false,
                    'file_path' => null,
                    'error' => 'Arquivo não foi salvo corretamente'
                ];
            }
        } else {
            return [
                'success' => false,
                'file_path' => null,
                'error' => 'Erro ao salvar arquivo'
            ];
        }
    }
}

if (!function_exists('validate_image_upload')) {
    /**
     * Valida upload de imagem (helper específico para imagens)
     * @param array $file Array $_FILES['campo']
     * @param string $upload_dir Diretório de destino
     * @param string $prefix Prefixo para o nome do arquivo
     * @param int $max_size_mb Tamanho máximo em MB (padrão: 5MB)
     * @param bool $strict_jpeg_png_only Se true, aceita apenas JPEG e PNG (padrão: false)
     * @return array ['success' => bool, 'file_path' => string|null, 'error' => string|null]
     */
    function validate_image_upload($file, $upload_dir, $prefix = 'img', $max_size_mb = 5, $strict_jpeg_png_only = false) {
        if ($strict_jpeg_png_only) {
            // Apenas JPEG e PNG - mais seguro para áreas sensíveis
            $allowed_types = ['image/jpeg', 'image/jpg', 'image/png'];
            $allowed_extensions = ['jpg', 'jpeg', 'png'];
        } else {
            // Permite mais formatos (compatibilidade)
            $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/gif'];
            $allowed_extensions = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        }
        $max_size = $max_size_mb * 1024 * 1024;
        
        return validate_uploaded_file($file, $allowed_types, $allowed_extensions, $max_size, $upload_dir, $prefix);
    }
}

if (!function_exists('validate_pdf_upload')) {
    /**
     * Valida upload de PDF (helper específico para PDFs)
     * @param array $file Array $_FILES['campo']
     * @param string $upload_dir Diretório de destino
     * @param string $prefix Prefixo para o nome do arquivo
     * @param int $max_size_mb Tamanho máximo em MB (padrão: 10MB)
     * @return array ['success' => bool, 'file_path' => string|null, 'error' => string|null]
     */
    function validate_pdf_upload($file, $upload_dir, $prefix = 'pdf', $max_size_mb = 10) {
        $allowed_types = ['application/pdf'];
        $allowed_extensions = ['pdf'];
        $max_size = $max_size_mb * 1024 * 1024;
        
        return validate_uploaded_file($file, $allowed_types, $allowed_extensions, $max_size, $upload_dir, $prefix);
    }
}

if (!function_exists('validate_file_content')) {
    /**
     * Valida o conteúdo real do arquivo (não apenas extensão/MIME)
     * Previne upload de arquivos maliciosos disfarçados
     * @param string $file_path Caminho completo do arquivo
     * @param array $allowed_types Whitelist de tipos MIME permitidos
     * @param string $file_extension Extensão do arquivo
     * @return array ['valid' => bool, 'error' => string|null]
     */
    function validate_file_content($file_path, $allowed_types, $file_extension) {
        if (!file_exists($file_path) || !is_readable($file_path)) {
            return ['valid' => false, 'error' => 'Arquivo não encontrado ou não legível'];
        }
        
        // Ler primeiros bytes do arquivo para validar header
        $handle = @fopen($file_path, 'rb');
        if (!$handle) {
            return ['valid' => false, 'error' => 'Não foi possível ler o arquivo'];
        }
        
        $header = fread($handle, 512); // Ler primeiros 512 bytes
        fclose($handle);
        
        if (empty($header)) {
            return ['valid' => false, 'error' => 'Arquivo vazio ou corrompido'];
        }
        
        // Validação específica por tipo
        $is_image = in_array($file_extension, ['jpg', 'jpeg', 'png', 'gif', 'webp']);
        $is_pdf = ($file_extension === 'pdf');
        
        if ($is_image) {
            // Para imagens: usar getimagesize() para validar conteúdo real
            $image_info = @getimagesize($file_path);
            if ($image_info === false) {
                return ['valid' => false, 'error' => 'Arquivo não é uma imagem válida'];
            }
            
            // Verificar se o MIME type detectado corresponde ao esperado
            $detected_mime = $image_info['mime'];
            if (!in_array($detected_mime, $allowed_types)) {
                return ['valid' => false, 'error' => 'Tipo de imagem não corresponde ao esperado'];
            }
            
            // Verificar headers de arquivo por extensão
            $header_hex = bin2hex(substr($header, 0, 12));
            
            // JPEG: deve começar com FF D8 FF
            if (in_array($file_extension, ['jpg', 'jpeg']) && strpos($header_hex, 'ffd8ff') !== 0) {
                return ['valid' => false, 'error' => 'Arquivo JPEG inválido ou corrompido'];
            }
            
            // PNG: deve começar com 89 50 4E 47 0D 0A 1A 0A
            if ($file_extension === 'png' && strpos($header_hex, '89504e470d0a1a0a') !== 0) {
                return ['valid' => false, 'error' => 'Arquivo PNG inválido ou corrompido'];
            }
            
            // GIF: deve começar com GIF87a ou GIF89a
            if ($file_extension === 'gif' && strpos($header, 'GIF87a') !== 0 && strpos($header, 'GIF89a') !== 0) {
                return ['valid' => false, 'error' => 'Arquivo GIF inválido ou corrompido'];
            }
            
            // WEBP: deve começar com RIFF...WEBP
            if ($file_extension === 'webp' && (strpos($header, 'RIFF') !== 0 || strpos($header, 'WEBP', 8) === false)) {
                return ['valid' => false, 'error' => 'Arquivo WEBP inválido ou corrompido'];
            }
        }
        
        if ($is_pdf) {
            // PDF: deve começar com %PDF-
            if (strpos($header, '%PDF-') !== 0) {
                return ['valid' => false, 'error' => 'Arquivo PDF inválido ou corrompido'];
            }
            
            // Verificar se contém código PHP (prevenção de shell upload)
            if (stripos($header, '<?php') !== false || stripos($header, '<?=') !== false) {
                return ['valid' => false, 'error' => 'Arquivo contém código PHP malicioso'];
            }
        }
        
        // Prevenção geral: verificar se arquivo contém código PHP (independente da extensão)
        // Isso previne upload de .php.jpg ou outros disfarces
        $dangerous_patterns = [
            '<?php',
            '<?=',
            '<? ',
            '<script',
            'eval(',
            'base64_decode',
            'exec(',
            'system(',
            'shell_exec(',
            'passthru(',
            'proc_open(',
            'popen('
        ];
        
        $file_content_lower = strtolower($header);
        foreach ($dangerous_patterns as $pattern) {
            if (stripos($file_content_lower, strtolower($pattern)) !== false) {
                return ['valid' => false, 'error' => 'Arquivo contém código potencialmente malicioso'];
            }
        }
        
        return ['valid' => true, 'error' => null];
    }
}

if (!function_exists('sanitize_custom_script')) {
    /**
     * Sanitiza script customizado para prevenir XSS
     * Remove event handlers perigosos e tags não permitidas
     * @param string $script Script a ser sanitizado
     * @return string Script sanitizado
     */
    function sanitize_custom_script($script) {
        if (empty($script)) {
            return '';
        }
        
        // Remove event handlers perigosos (onclick, onerror, onload, etc)
        $script = preg_replace('/\s*on\w+\s*=\s*["\'][^"\']*["\']/i', '', $script);
        $script = preg_replace('/\s*on\w+\s*=\s*[^\s>]*/i', '', $script);
        
        // Remove javascript: em URLs
        $script = preg_replace('/javascript:/i', '', $script);
        
        // Remove tags perigosas (iframe, embed, object, etc) - mantém apenas script, noscript, link, meta
        $allowed_tags = ['script', 'noscript', 'link', 'meta'];
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        
        // Tenta carregar o HTML
        $wrapped_script = '<html><head>' . $script . '</head></html>';
        @$dom->loadHTML($wrapped_script, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        
        // Remove tags não permitidas
        $xpath = new DOMXPath($dom);
        $nodes_to_remove = $xpath->query('//*[not(self::script) and not(self::noscript) and not(self::link) and not(self::meta)]');
        foreach ($nodes_to_remove as $node) {
            $node->parentNode->removeChild($node);
        }
        
        // Remove atributos perigosos de tags permitidas
        $all_nodes = $xpath->query('//*');
        foreach ($all_nodes as $node) {
            if ($node->hasAttributes()) {
                $attrs_to_remove = [];
                foreach ($node->attributes as $attr) {
                    // Remove event handlers e atributos perigosos
                    if (preg_match('/^on/i', $attr->name) || 
                        in_array(strtolower($attr->name), ['href', 'src', 'action']) && 
                        preg_match('/javascript:/i', $attr->value)) {
                        $attrs_to_remove[] = $attr->name;
                    }
                }
                foreach ($attrs_to_remove as $attr_name) {
                    $node->removeAttribute($attr_name);
                }
            }
        }
        
        // Extrai apenas o conteúdo do head
        $head = $dom->getElementsByTagName('head')->item(0);
        if ($head) {
            $sanitized = '';
            foreach ($head->childNodes as $child) {
                $sanitized .= $dom->saveHTML($child);
            }
            return $sanitized;
        }
        
        // Fallback: se não conseguir parsear, retorna vazio
        return '';
    }
}

if (!function_exists('sanitize_url')) {
    /**
     * Sanitiza URL para prevenir XSS e redirecionamentos maliciosos
     * @param string $url URL a ser sanitizada
     * @param bool $allow_relative Se permite URLs relativas (padrão: true)
     * @return string URL sanitizada ou string vazia se inválida
     */
    function sanitize_url($url, $allow_relative = true) {
        if (empty($url)) {
            return '';
        }
        
        // Remove javascript: e data: URLs
        $url = preg_replace('/^(javascript|data|vbscript):/i', '', $url);
        
        // Se é URL absoluta, valida
        if (preg_match('/^https?:\/\//i', $url)) {
            $parsed = parse_url($url);
            if ($parsed && isset($parsed['scheme']) && in_array(strtolower($parsed['scheme']), ['http', 'https'])) {
                // Remove caracteres perigosos
                $url = filter_var($url, FILTER_SANITIZE_URL);
                return htmlspecialchars($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
            return '';
        }
        
        // Se é URL relativa
        if ($allow_relative) {
            // Normaliza caminho: se começa com uploads/ mas não tem / no início, adiciona
            if (preg_match('/^uploads\//', $url) && strpos($url, '/') !== 0) {
                $url = '/' . $url;
            }
            
            // Aceita caminhos que começam com / (após normalização)
            if (preg_match('/^\/[^\/]/', $url)) {
                // Remove path traversal
                $url = str_replace(['../', '..\\', './'], '', $url);
                // Garante que não tenha caracteres perigosos
                $url = filter_var($url, FILTER_SANITIZE_URL);
                return htmlspecialchars($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }
        
        return '';
    }
}

if (!function_exists('is_https')) {
    /**
     * Verifica se a requisição está usando HTTPS
     * @return bool True se HTTPS, False caso contrário
     */
    function is_https() {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') 
            || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
            || (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on')
            || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
    }
}

if (!function_exists('escape_html_output')) {
    /**
     * Escapa output HTML de forma segura
     * @param mixed $value Valor a ser escapado
     * @param bool $double_encode Se deve fazer double encoding (padrão: false)
     * @return string Valor escapado
     */
    function escape_html_output($value, $double_encode = false) {
        if (is_null($value)) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_array($value) || is_object($value)) {
            return htmlspecialchars(json_encode($value), ENT_QUOTES | ENT_HTML5, 'UTF-8', $double_encode);
        }
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8', $double_encode);
    }
}

if (!function_exists('require_admin_auth')) {
    /**
     * Verifica se o usuário está autenticado como administrador
     * Redireciona para login se não estiver autenticado ou não for admin
     * @param bool $return_json Se true, retorna JSON ao invés de redirecionar (para APIs)
     * @return bool True se autenticado como admin, False caso contrário (ou exit se $return_json=false)
     */
    function require_admin_auth($return_json = false) {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // Verifica se está logado
        if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
            if ($return_json) {
                http_response_code(403);
                header('Content-Type: application/json');
                ob_clean();
                echo json_encode(['success' => false, 'error' => 'Acesso não autorizado. Faça login primeiro.']);
                exit;
            } else {
                header("location: /login");
                exit;
            }
        }
        
        // Verifica se é admin
        if (!isset($_SESSION["tipo"]) || $_SESSION["tipo"] !== 'admin') {
            log_security_event('unauthorized_admin_access_attempt', [
                'user_id' => $_SESSION['id'] ?? null,
                'user_type' => $_SESSION['tipo'] ?? 'unknown',
                'ip' => get_client_ip(),
                'endpoint' => $_SERVER['REQUEST_URI'] ?? 'unknown'
            ]);
            
            if ($return_json) {
                http_response_code(403);
                header('Content-Type: application/json');
                ob_clean();
                echo json_encode(['success' => false, 'error' => 'Acesso negado. Apenas administradores podem acessar este recurso.']);
                exit;
            } else {
                header("location: /login");
                exit;
            }
        }
        
        // Verifica timeout de sessão (7 dias)
        if (isset($_SESSION['last_activity'])) {
            $session_timeout = 604800; // 7 dias
            if ((time() - $_SESSION['last_activity']) > $session_timeout) {
                session_destroy();
                if ($return_json) {
                    http_response_code(401);
                    header('Content-Type: application/json');
                    ob_clean();
                    echo json_encode(['success' => false, 'error' => 'Sessão expirada. Faça login novamente.']);
                    exit;
                } else {
                    header("location: /login");
                    exit;
                }
            }
        }
        
        // Atualiza última atividade
        $_SESSION['last_activity'] = time();
        
        return true;
    }
}

if (!function_exists('validate_url_for_ssrf')) {
    /**
     * Valida URL contra ataques SSRF (Server-Side Request Forgery)
     * Bloqueia IPs privados, reservados e protocolos perigosos
     * @param string $url URL a ser validada
     * @return array ['valid' => bool, 'error' => string|null, 'resolved_ip' => string|null]
     */
    function validate_url_for_ssrf($url) {
        if (empty($url)) {
            return ['valid' => false, 'error' => 'URL vazia', 'resolved_ip' => null];
        }
        
        // Parse da URL
        $parsed = parse_url($url);
        if (!$parsed || !isset($parsed['scheme']) || !isset($parsed['host'])) {
            return ['valid' => false, 'error' => 'URL inválida', 'resolved_ip' => null];
        }
        
        // Bloquear protocolos perigosos
        $allowed_schemes = ['http', 'https'];
        $scheme = strtolower($parsed['scheme']);
        if (!in_array($scheme, $allowed_schemes)) {
            log_security_event('ssrf_blocked_protocol', [
                'url' => $url,
                'scheme' => $scheme,
                'ip' => get_client_ip()
            ]);
            return ['valid' => false, 'error' => 'Protocolo não permitido: ' . $scheme, 'resolved_ip' => null];
        }
        
        $host = $parsed['host'];
        
        // Bloquear localhost e variações
        $blocked_hosts = [
            'localhost',
            '127.0.0.1',
            '0.0.0.0',
            '::1',
            '[::1]'
        ];
        
        // Normalizar host (remover portas, colchetes IPv6)
        $host_normalized = strtolower(preg_replace('/:.*$/', '', trim($host, '[]')));
        
        if (in_array($host_normalized, $blocked_hosts)) {
            log_security_event('ssrf_blocked_host', [
                'url' => $url,
                'host' => $host,
                'ip' => get_client_ip()
            ]);
            return ['valid' => false, 'error' => 'Host não permitido', 'resolved_ip' => null];
        }
        
        // Resolver DNS para obter IP
        $resolved_ip = null;
        if (filter_var($host_normalized, FILTER_VALIDATE_IP)) {
            $resolved_ip = $host_normalized;
        } else {
            // Resolver hostname para IP
            $dns_result = @dns_get_record($host_normalized, DNS_A);
            if (!empty($dns_result) && isset($dns_result[0]['ip'])) {
                $resolved_ip = $dns_result[0]['ip'];
            } else {
                // Tentar gethostbyname como fallback
                $ip = @gethostbyname($host_normalized);
                if ($ip && $ip !== $host_normalized) {
                    $resolved_ip = $ip;
                }
            }
        }
        
        if (!$resolved_ip) {
            log_security_event('ssrf_dns_resolution_failed', [
                'url' => $url,
                'host' => $host,
                'ip' => get_client_ip()
            ]);
            return ['valid' => false, 'error' => 'Não foi possível resolver o host', 'resolved_ip' => null];
        }
        
        // Validar se IP não é privado/reservado
        if (!filter_var($resolved_ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            log_security_event('ssrf_blocked_private_ip', [
                'url' => $url,
                'host' => $host,
                'resolved_ip' => $resolved_ip,
                'ip' => get_client_ip()
            ]);
            return ['valid' => false, 'error' => 'IP privado ou reservado não permitido', 'resolved_ip' => $resolved_ip];
        }
        
        // Verificar ranges específicos de IPs privados (redundância)
        $ip_parts = explode('.', $resolved_ip);
        if (count($ip_parts) === 4) {
            $first_octet = (int)$ip_parts[0];
            $second_octet = (int)$ip_parts[1];
            
            // 10.0.0.0/8
            if ($first_octet === 10) {
                return ['valid' => false, 'error' => 'IP privado não permitido', 'resolved_ip' => $resolved_ip];
            }
            
            // 192.168.0.0/16
            if ($first_octet === 192 && $second_octet === 168) {
                return ['valid' => false, 'error' => 'IP privado não permitido', 'resolved_ip' => $resolved_ip];
            }
            
            // 172.16.0.0/12 (172.16.0.0 - 172.31.255.255)
            if ($first_octet === 172 && $second_octet >= 16 && $second_octet <= 31) {
                return ['valid' => false, 'error' => 'IP privado não permitido', 'resolved_ip' => $resolved_ip];
            }
            
            // 169.254.0.0/16 (Link-local)
            if ($first_octet === 169 && $second_octet === 254) {
                return ['valid' => false, 'error' => 'IP link-local não permitido', 'resolved_ip' => $resolved_ip];
            }
        }
        
        return ['valid' => true, 'error' => null, 'resolved_ip' => $resolved_ip];
    }
}

if (!function_exists('validate_mercadopago_webhook')) {
    /**
     * Valida assinatura HMAC de webhook do Mercado Pago
     * @param string $x_signature Header X-Signature do webhook
     * @param string $x_request_id Header X-Request-Id do webhook
     * @param string $data_id ID do pagamento (data.id)
     * @param string $access_token Token de acesso do Mercado Pago
     * @return bool True se válido, False caso contrário
     */
    function validate_mercadopago_webhook($x_signature, $x_request_id, $data_id, $access_token) {
        if (empty($x_signature) || empty($x_request_id) || empty($data_id) || empty($access_token)) {
            return false;
        }
        
        // Mercado Pago usa HMAC SHA256 com o access_token
        // A assinatura é: sha256("id;request-id", access_token)
        $message = $data_id . ';' . $x_request_id;
        $expected_signature = hash_hmac('sha256', $message, $access_token);
        
        // Comparação segura de hash
        return hash_equals($expected_signature, $x_signature);
    }
}

if (!function_exists('validate_webhook_signature')) {
    /**
     * Valida assinatura de webhook baseado no gateway
     * @param string $gateway Nome do gateway (mercadopago, pushinpay, etc)
     * @param array $headers Headers HTTP da requisição
     * @param array $payload Dados do webhook
     * @param string $secret_key Chave secreta do gateway
     * @return bool True se válido, False caso contrário
     */
    function validate_webhook_signature($gateway, $headers, $payload, $secret_key) {
        if (empty($secret_key)) {
            // Se não há chave secreta configurada, não pode validar
            // Mas não bloqueia (compatibilidade com gateways que não usam assinatura)
            return true;
        }
        
        switch (strtolower($gateway)) {
            case 'mercadopago':
                $x_signature = $headers['X-Signature'] ?? $headers['HTTP_X_SIGNATURE'] ?? '';
                $x_request_id = $headers['X-Request-Id'] ?? $headers['HTTP_X_REQUEST_ID'] ?? '';
                $data_id = $payload['data']['id'] ?? $payload['id'] ?? '';
                
                if (empty($x_signature) || empty($x_request_id) || empty($data_id)) {
                    return false;
                }
                
                return validate_mercadopago_webhook($x_signature, $x_request_id, $data_id, $secret_key);
                
            case 'pushinpay':
                // PushinPay pode usar assinatura em header X-Signature
                $x_signature = $headers['X-Signature'] ?? $headers['HTTP_X_SIGNATURE'] ?? '';
                if (!empty($x_signature)) {
                    $payload_string = json_encode($payload);
                    $expected_signature = hash_hmac('sha256', $payload_string, $secret_key);
                    return hash_equals($expected_signature, $x_signature);
                }
                // Se não tem assinatura, permite (compatibilidade)
                return true;
                
            case 'efi':
            case 'efi_card':
                // Efí geralmente não envia assinatura em webhooks, mas valida via certificado
                // Webhooks são validados pela origem (IP da Efí)
                // Por enquanto, permite (validação adicional pode ser adicionada)
                return true;
                
            case 'beehive':
            case 'hypercash':
                // Verificar se gateway envia assinatura
                $x_signature = $headers['X-Signature'] ?? $headers['HTTP_X_SIGNATURE'] ?? '';
                if (!empty($x_signature)) {
                    $payload_string = json_encode($payload);
                    $expected_signature = hash_hmac('sha256', $payload_string, $secret_key);
                    return hash_equals($expected_signature, $x_signature);
                }
                return true;
                
            default:
                // Para gateways desconhecidos, não bloqueia (compatibilidade)
                return true;
        }
    }
}

if (!function_exists('sanitize_log_message')) {
    /**
     * Sanitiza mensagens de log removendo informações sensíveis
     * @param string $message Mensagem a ser sanitizada
     * @return string Mensagem sanitizada
     */
    function sanitize_log_message($message) {
        if (empty($message)) {
            return '';
        }
        
        // Padrões de dados sensíveis a remover/mascarar
        $sensitive_patterns = [
            // Tokens e chaves (geralmente longos e aleatórios)
            '/\b[A-Za-z0-9]{32,}\b/' => function($matches) {
                // Se parece com token/chave, mascarar
                $match = $matches[0];
                if (strlen($match) >= 32) {
                    return substr($match, 0, 8) . '***MASKED***';
                }
                return $match;
            },
            // Passwords e secrets em padrões comuns
            '/(password|senha|secret|token|key|credential)[\s:=]+["\']?([^"\'\s]{8,})["\']?/i' => '$1=***MASKED***',
            // CPF completo
            '/\b\d{3}\.?\d{3}\.?\d{3}-?\d{2}\b/' => '***.***.***-**',
            // Email (manter domínio, mascarar usuário)
            '/\b([a-zA-Z0-9._%+-]+)@([a-zA-Z0-9.-]+\.[a-zA-Z]{2,})\b/' => '***@$2',
            // Números de cartão de crédito
            '/\b\d{4}[\s-]?\d{4}[\s-]?\d{4}[\s-]?\d{4}\b/' => '****-****-****-****',
            // Caminhos de arquivos com credenciais
            '/[Cc]:\\[^\\s]+|\\/[^\\s]+(certificate|key|credential|secret)[^\\s]*/i' => '***PATH_MASKED***',
        ];
        
        $sanitized = $message;
        
        // Aplicar padrões de mascaramento
        foreach ($sensitive_patterns as $pattern => $replacement) {
            if (is_callable($replacement)) {
                $sanitized = preg_replace_callback($pattern, $replacement, $sanitized);
            } else {
                $sanitized = preg_replace($pattern, $replacement, $sanitized);
            }
        }
        
        // Remover possíveis vazamentos de stack trace com dados sensíveis
        $sanitized = preg_replace('/\["password"\s*=>\s*"[^"]+"\]/', '["password" => "***MASKED***"]', $sanitized);
        $sanitized = preg_replace('/\["secret"\s*=>\s*"[^"]+"\]/', '["secret" => "***MASKED***"]', $sanitized);
        $sanitized = preg_replace('/\["token"\s*=>\s*"[^"]+"\]/', '["token" => "***MASKED***"]', $sanitized);
        
        return $sanitized;
    }
}

if (!function_exists('get_log_level')) {
    /**
     * Obtém o nível de log configurado (produção, desenvolvimento, debug)
     * @return string Nível de log: 'production', 'development', 'debug'
     */
    function get_log_level() {
        // Verificar variável de ambiente primeiro
        if (isset($_ENV['LOG_LEVEL'])) {
            $level = strtolower($_ENV['LOG_LEVEL']);
            if (in_array($level, ['production', 'development', 'debug'])) {
                return $level;
            }
        }
        
        // Verificar constante definida
        if (defined('LOG_LEVEL')) {
            $level = strtolower(LOG_LEVEL);
            if (in_array($level, ['production', 'development', 'debug'])) {
                return $level;
            }
        }
        
        // Verificar configuração no banco de dados
        global $pdo;
        if (isset($pdo)) {
            try {
                $stmt = $pdo->prepare("SELECT valor FROM configuracoes_sistema WHERE chave = 'log_level' LIMIT 1");
                $stmt->execute();
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($result && in_array(strtolower($result['valor']), ['production', 'development', 'debug'])) {
                    return strtolower($result['valor']);
                }
            } catch (PDOException $e) {
                // Ignorar erro e usar padrão
            }
        }
        
        // Padrão: produção (mais seguro)
        return 'production';
    }
}

if (!function_exists('should_log')) {
    /**
     * Verifica se uma mensagem deve ser logada baseado no nível configurado
     * @param string $level Nível da mensagem: 'error', 'warning', 'info', 'debug'
     * @return bool True se deve logar, False caso contrário
     */
    function should_log($level) {
        $log_level = get_log_level();
        
        $levels = [
            'production' => ['error', 'warning'],
            'development' => ['error', 'warning', 'info'],
            'debug' => ['error', 'warning', 'info', 'debug']
        ];
        
        return isset($levels[$log_level]) && in_array(strtolower($level), $levels[$log_level]);
    }
}

if (!function_exists('secure_log')) {
    /**
     * Função de log segura que sanitiza mensagens automaticamente
     * @param string $file_path Caminho do arquivo de log
     * @param string $message Mensagem a ser logada
     * @param string $level Nível do log: 'error', 'warning', 'info', 'debug' (padrão: 'info')
     * @return void
     */
    function secure_log($file_path, $message, $level = 'info') {
        // Verificar se deve logar baseado no nível configurado
        if (!should_log($level)) {
            return;
        }
        
        $sanitized_message = sanitize_log_message($message);
        $log_entry = date('Y-m-d H:i:s') . " [" . strtoupper($level) . "] " . $sanitized_message . "\n";
        
        // Limitar tamanho do arquivo de log (rotação simples)
        if (file_exists($file_path) && filesize($file_path) > 10 * 1024 * 1024) { // 10MB
            $backup_path = $file_path . '.' . date('Y-m-d_His') . '.bak';
            @rename($file_path, $backup_path);
            
            // Manter apenas últimos 5 backups
            $backups = glob($file_path . '.*.bak');
            if (count($backups) > 5) {
                usort($backups, function($a, $b) {
                    return filemtime($a) - filemtime($b);
                });
                foreach (array_slice($backups, 0, -5) as $old_backup) {
                    @unlink($old_backup);
                }
            }
        }
        
        @file_put_contents($file_path, $log_entry, FILE_APPEND | LOCK_EX);
    }
}

if (!function_exists('check_rate_limit_db')) {
    /**
     * Verifica rate limiting usando banco de dados (mais seguro que arquivos)
     * @param string $key Chave única para identificar o limite
     * @param int $max_attempts Número máximo de tentativas
     * @param int $time_window Período de tempo em segundos
     * @param string|null $identifier Identificador adicional (IP, user_id, etc)
     * @return array ['allowed' => bool, 'remaining' => int, 'reset_at' => datetime|null]
     */
    function check_rate_limit_db($key, $max_attempts = 60, $time_window = 60, $identifier = null) {
        global $pdo;
        
        if (!isset($pdo)) {
            // Fallback para rate limiting por sessão se PDO não disponível
            return check_rate_limit($key, $max_attempts, $time_window);
        }
        
        try {
            // Criar tabela se não existir
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS rate_limits (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    rate_key VARCHAR(255) NOT NULL,
                    identifier VARCHAR(255),
                    attempts INT DEFAULT 1,
                    first_attempt DATETIME DEFAULT CURRENT_TIMESTAMP,
                    last_attempt DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    blocked_until DATETIME NULL,
                    INDEX idx_key_identifier (rate_key, identifier),
                    INDEX idx_last_attempt (last_attempt)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            ");
            
            // Limpar registros antigos (mais de 24 horas)
            $pdo->exec("DELETE FROM rate_limits WHERE last_attempt < DATE_SUB(NOW(), INTERVAL 24 HOUR)");
            
            $rate_key_full = $key . ($identifier ? '_' . $identifier : '');
            $current_time = date('Y-m-d H:i:s');
            
            // Buscar registro existente
            $stmt = $pdo->prepare("
                SELECT attempts, first_attempt, blocked_until 
                FROM rate_limits 
                WHERE rate_key = ? AND identifier = ?
                LIMIT 1
            ");
            $stmt->execute([$key, $identifier]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Verificar se está bloqueado
            if ($existing && $existing['blocked_until']) {
                $blocked_until = strtotime($existing['blocked_until']);
                if ($blocked_until > time()) {
                    return [
                        'allowed' => false,
                        'remaining' => 0,
                        'reset_at' => $existing['blocked_until']
                    ];
                }
            }
            
            // Verificar se está dentro da janela de tempo
            if ($existing) {
                $first_attempt = strtotime($existing['first_attempt']);
                $time_elapsed = time() - $first_attempt;
                
                if ($time_elapsed < $time_window) {
                    // Dentro da janela - verificar tentativas
                    if ($existing['attempts'] >= $max_attempts) {
                        // Bloquear por 15 minutos
                        $blocked_until = date('Y-m-d H:i:s', time() + 900);
                        $stmt = $pdo->prepare("
                            UPDATE rate_limits 
                            SET attempts = attempts + 1, blocked_until = ?, last_attempt = NOW()
                            WHERE rate_key = ? AND identifier = ?
                        ");
                        $stmt->execute([$blocked_until, $key, $identifier]);
                        
                        log_security_event('rate_limit_exceeded', [
                            'key' => $key,
                            'identifier' => $identifier,
                            'attempts' => $existing['attempts'] + 1
                        ]);
                        
                        return [
                            'allowed' => false,
                            'remaining' => 0,
                            'reset_at' => $blocked_until
                        ];
                    } else {
                        // Incrementar tentativas
                        $stmt = $pdo->prepare("
                            UPDATE rate_limits 
                            SET attempts = attempts + 1, last_attempt = NOW()
                            WHERE rate_key = ? AND identifier = ?
                        ");
                        $stmt->execute([$key, $identifier]);
                        
                        return [
                            'allowed' => true,
                            'remaining' => $max_attempts - ($existing['attempts'] + 1),
                            'reset_at' => date('Y-m-d H:i:s', $first_attempt + $time_window)
                        ];
                    }
                } else {
                    // Janela expirada - resetar
                    $stmt = $pdo->prepare("
                        UPDATE rate_limits 
                        SET attempts = 1, first_attempt = NOW(), last_attempt = NOW(), blocked_until = NULL
                        WHERE rate_key = ? AND identifier = ?
                    ");
                    $stmt->execute([$key, $identifier]);
                    
                    return [
                        'allowed' => true,
                        'remaining' => $max_attempts - 1,
                        'reset_at' => date('Y-m-d H:i:s', time() + $time_window)
                    ];
                }
            } else {
                // Criar novo registro
                $stmt = $pdo->prepare("
                    INSERT INTO rate_limits (rate_key, identifier, attempts, first_attempt, last_attempt)
                    VALUES (?, ?, 1, NOW(), NOW())
                ");
                $stmt->execute([$key, $identifier]);
                
                return [
                    'allowed' => true,
                    'remaining' => $max_attempts - 1,
                    'reset_at' => date('Y-m-d H:i:s', time() + $time_window)
                ];
            }
        } catch (PDOException $e) {
            error_log("Erro ao verificar rate limit no banco: " . $e->getMessage());
            // Fallback: permitir em caso de erro (fail-open para não bloquear usuários legítimos)
            return [
                'allowed' => true,
                'remaining' => $max_attempts,
                'reset_at' => null
            ];
        }
    }
}

