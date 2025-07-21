<?php
session_start();

if (!isset($_SESSION['usuario_id'])) {
    header('Location: login.php');
    exit;
}

// NOVO: Verifica se a função (role) do usuário já está na sessão.
// Se não estiver (ex: após atualização do DB ou logout/login), busca do DB.
if (!isset($_SESSION['usuario_role'])) {
    // Certifique-se de que a conexão com o banco de dados ($conn) esteja disponível.
    // Isso é feito via config.php, mas para garantir que seja incluído aqui se ainda não foi.
    if (!isset($conn)) {
        require_once __DIR__ . '/config/config.php';
    }

    $stmt = $conn->prepare("SELECT role FROM usuarios WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $_SESSION['usuario_id']);
        $stmt->execute();
        $result = $stmt->get_result()->fetch_assoc();
        if ($result) {
            $_SESSION['usuario_role'] = $result['role'];
        } else {
            // Se o usuário não for encontrado no DB (raro, mas possível),
            // encerra a sessão e redireciona para login.
            session_unset();
            session_destroy();
            header('Location: login.php?error=Usuário inválido ou não encontrado.');
            exit;
        }
        $stmt->close();
    } else {
        // Loga erro de preparação da query. Para robustez, define um papel padrão.
        error_log("Erro ao preparar query para buscar role em auth_check.php: " . $conn->error);
        $_SESSION['usuario_role'] = 'atendente'; // Default seguro para evitar erros em cascata
    }
}
?>