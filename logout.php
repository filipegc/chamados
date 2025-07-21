<?php
session_start();

// Destrói todas as variáveis da sessão
$_SESSION = array();

// Apaga o cookie de sessão do navegador
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Finalmente, destrói a sessão no servidor
session_destroy();

// Redireciona para a página de login com uma mensagem de sucesso
header('Location: login.php?success=Você saiu do sistema com segurança.');
exit;
?>
