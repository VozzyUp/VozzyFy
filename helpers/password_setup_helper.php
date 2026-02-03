<?php
/**
 * Helper para Criação de Senha (Área de Membros)
 * Funções auxiliares para gerenciar tokens de criação inicial de senha
 */

// Carrega PHPMailer se ainda não foi carregado
$phpmailer_path = __DIR__ . '/../PHPMailer/src/';
if (file_exists($phpmailer_path . 'Exception.php')) {
    require_once $phpmailer_path . 'Exception.php';
    require_once $phpmailer_path . 'PHPMailer.php';
    require_once $phpmailer_path . 'SMTP.php';
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

/**
 * Gera token único de criação de senha e salva no banco
 * @param int $usuario_id ID do usuário
 * @return string|false Token gerado ou false em caso de erro
 */
function generate_setup_token($usuario_id) {
    global $pdo;
    
    if (!isset($pdo) || !$usuario_id) {
        error_log("PASSWORD_SETUP: PDO não disponível ou usuario_id inválido");
        return false;
    }
    
    try {
        // Gera token único de 64 caracteres
        $token = bin2hex(random_bytes(32));
        
        // Define expiração de 7 dias
        $expires = date('Y-m-d H:i:s', strtotime('+7 days'));
        
        // Salva token no banco
        $stmt = $pdo->prepare("UPDATE usuarios SET password_setup_token = ?, password_setup_expires = ? WHERE id = ?");
        $stmt->execute([$token, $expires, $usuario_id]);
        
        error_log("PASSWORD_SETUP: Token gerado para usuario_id: $usuario_id");
        return $token;
    } catch (PDOException $e) {
        error_log("PASSWORD_SETUP: Erro ao gerar token: " . $e->getMessage());
        return false;
    }
}

/**
 * Valida token de criação de senha e retorna dados do usuário
 * @param string $token Token de criação de senha
 * @return array|false Array com dados do usuário ou false se inválido/expirado
 */
function validate_setup_token($token) {
    global $pdo;
    
    if (!isset($pdo) || empty($token)) {
        return false;
    }
    
    try {
        $stmt = $pdo->prepare("SELECT id, usuario, nome, tipo, password_setup_expires FROM usuarios WHERE password_setup_token = ? AND password_setup_expires > NOW()");
        $stmt->execute([$token]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user) {
            return $user;
        }
        
        return false;
    } catch (PDOException $e) {
        error_log("PASSWORD_SETUP: Erro ao validar token: " . $e->getMessage());
        return false;
    }
}

/**
 * Define senha do usuário e invalida token
 * @param string $token Token de criação de senha
 * @param string $password Nova senha (será hasheada)
 * @return bool True se sucesso, False caso contrário
 */
function setup_password($token, $password) {
    global $pdo;
    
    if (!isset($pdo) || empty($token) || empty($password)) {
        return false;
    }
    
    // Valida força da senha (mínimo 8 caracteres)
    if (strlen($password) < 8) {
        error_log("PASSWORD_SETUP: Senha muito curta (mínimo 8 caracteres)");
        return false;
    }
    
    // Valida token
    $user = validate_setup_token($token);
    if (!$user) {
        error_log("PASSWORD_SETUP: Token inválido ou expirado");
        return false;
    }
    
    try {
        // Hash da nova senha
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        
        // Atualiza senha e invalida token
        $stmt = $pdo->prepare("UPDATE usuarios SET senha = ?, password_setup_token = NULL, password_setup_expires = NULL WHERE id = ?");
        $stmt->execute([$hashed_password, $user['id']]);
        
        error_log("PASSWORD_SETUP: Senha criada com sucesso para usuario_id: " . $user['id']);
        return true;
    } catch (PDOException $e) {
        error_log("PASSWORD_SETUP: Erro ao criar senha: " . $e->getMessage());
        return false;
    }
}

