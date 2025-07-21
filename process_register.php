<?php
// CORREÇÃO: Ajustado o caminho para incluir o arquivo da pasta /config
require_once __DIR__ . '/config/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: register.php');
    exit;
}

// Coleta os dados do formulário
$nome = trim($_POST['nome']);
$email = trim($_POST['email']);
$senha = $_POST['senha'];
$confirmar_senha = $_POST['confirmar_senha'];

// --- Validações ---
if (empty($nome) || empty($email) || empty($senha)) {
    header('Location: register.php?error=Todos os campos são obrigatórios.');
    exit;
}

if ($senha !== $confirmar_senha) {
    header('Location: register.php?error=As senhas não coincidem.');
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    header('Location: register.php?error=O formato do e-mail é inválido.');
    exit;
}

// Verifica se o e-mail já existe
$stmt = $conn->prepare("SELECT id FROM usuarios WHERE email = ?");
$stmt->bind_param("s", $email);
$stmt->execute();
$stmt->store_result();

if ($stmt->num_rows > 0) {
    $stmt->close();
    header('Location: register.php?error=Este e-mail já está cadastrado.');
    exit;
}
$stmt->close();

// --- Insere o novo usuário ---
// Criptografa a senha para segurança
$senha_hash = password_hash($senha, PASSWORD_DEFAULT);

$stmt = $conn->prepare("INSERT INTO usuarios (nome, email, senha) VALUES (?, ?, ?)");
$stmt->bind_param("sss", $nome, $email, $senha_hash);

if ($stmt->execute()) {
    // Sucesso! Redireciona para a tela de login com uma mensagem.
    header('Location: login.php?success=Conta criada com sucesso! Faça o login.');
} else {
    // Erro ao inserir no banco
    header('Location: register.php?error=Ocorreu um erro ao criar a conta. Tente novamente.');
}

$stmt->close();
$conn->close();
