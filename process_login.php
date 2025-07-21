<?php
session_start();
// CORREÇÃO: Ajustado o caminho para incluir o arquivo da pasta /config
require_once __DIR__ . '/config/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: login.php');
    exit;
}

// Coleta os dados do formulário
$email = trim($_POST['email']);
$senha = $_POST['senha'];

if (empty($email) || empty($senha)) {
    header('Location: login.php?error=E-mail e senha são obrigatórios.');
    exit;
}

// MODIFICADO: Busca o usuário pelo e-mail, incluindo a coluna 'role'
$stmt = $conn->prepare("SELECT id, nome, senha, role FROM usuarios WHERE email = ? AND ativo = 1");
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 1) {
    $usuario = $result->fetch_assoc();

    // Verifica se a senha está correta
    if (password_verify($senha, $usuario['senha'])) {
        // Senha correta, inicia a sessão
        $_SESSION['usuario_id'] = $usuario['id'];
        $_SESSION['usuario_nome'] = $usuario['nome'];
        $_SESSION['usuario_email'] = $email;
        $_SESSION['usuario_role'] = $usuario['role']; // NOVO: Armazena a função (role) do usuário na sessão

        // Redireciona para a página principal
        header('Location: index.php');
        exit;
    }
}

// Se chegou até aqui, o login falhou
header('Location: login.php?error=E-mail ou senha inválidos.');
$stmt->close();
// Note: $conn->close() deve ser chamado apenas no final do script ou em um bloco finally
// Remover aqui para permitir que outras partes do código que usam $conn continuem funcionando.
// $conn->close();