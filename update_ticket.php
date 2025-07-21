<?php
require_once __DIR__ . '/config/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['chamado_id'])) {
    header('Location: index.php');
    exit;
}

$chamado_id = (int)$_POST['chamado_id'];
$status = $_POST['status'];
$prioridade = $_POST['prioridade'];

// Validação simples
$allowed_status = ['Aberto', 'Pendente', 'Fechado'];
$allowed_priority = ['Baixa', 'Media', 'Alta'];

if (in_array($status, $allowed_status) && in_array($prioridade, $allowed_priority)) {
    $stmt = $conn->prepare("UPDATE chamados SET status = ?, prioridade = ? WHERE id = ?");
    $stmt->bind_param("ssi", $status, $prioridade, $chamado_id);
    $stmt->execute();
    $stmt->close();
}

// Redireciona de volta para a página do chamado
header('Location: view_ticket.php?id=' . $chamado_id);
exit;
