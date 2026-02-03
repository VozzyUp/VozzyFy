<?php
// É crucial que não haja NENHUM espaço ou linha antes desta tag de abertura do PHP.

// 1. Inicia o mecanismo de sessão do PHP.
session_start();

// Remove o remember_token do banco de dados se o usuário estiver logado
if (isset($_SESSION["id"])) {
    require_once __DIR__ . '/config/config.php';
    try {
        $stmt = $pdo->prepare("UPDATE usuarios SET remember_token = NULL WHERE id = ?");
        $stmt->execute([$_SESSION["id"]]);
    } catch (PDOException $e) {
        error_log("Erro ao remover remember_token no logout: " . $e->getMessage());
    }
}
 
// 2. Apaga todas as variáveis da sessão (limpa os dados como 'loggedin', 'id', etc.).
$_SESSION = array();
 
// 3. Remove os cookies de "lembrar-me"
if (isset($_COOKIE['remember_token'])) {
    setcookie('remember_token', "", time() - 3600, "/");
}
if (isset($_COOKIE['remember_user'])) {
    setcookie('remember_user', "", time() - 3600, "/");
}
 
// 4. Destrói a sessão no servidor.
session_destroy();
 
// 5. Redireciona o usuário de volta para a tela de login.
header("location: /login");
exit;
?>

