<?php
// Inicia o mecanismo de sessão do PHP.
session_start();
 
// Apaga todas as variáveis da sessão (limpa os dados como 'loggedin', 'id', 'usuario', 'nome', 'tipo').
$_SESSION = array();
 
// Destrói a sessão no servidor.
session_destroy();
 
// Redireciona o usuário de volta para a tela de login da área de membros.
// Clientes finais (membros) não devem ser redirecionados para o login do infoprodutor/admin.
header("location: /member_login");
exit;
?>