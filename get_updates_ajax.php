<?php
// get_updates_ajax.php

require_once 'auth_check.php';
require_once __DIR__ . '/config/config.php';

header('Content-Type: application/json');

// Pega os parâmetros da requisição AJAX
$last_id = isset($_GET['last_id']) ? (int)$_GET['last_id'] : 0;
$view_mode = $_GET['view'] ?? 'all';
$category_id = isset($_GET['category_id']) && is_numeric($_GET['category_id']) ? (int)$_GET['category_id'] : null;
$current_user_id = $_SESSION['usuario_id'];

// Monta a query para buscar chamados que são novos ou que receberam uma nova resposta de cliente
$sql = "
    SELECT 
        c.id, c.assunto, c.status, c.prioridade, c.email_cliente, c.data_criacao, c.requer_atencao,
        u.nome as nome_usuario,
        cat.nome as nome_categoria,
        (SELECT COUNT(*) FROM mensagens m_count WHERE m_count.chamado_id = c.id) as message_count,
        (SELECT MIN(m_first.data_envio) FROM mensagens m_first WHERE m_first.chamado_id = c.id) as arrival_date,
        (SELECT MIN(m_resp.data_envio) FROM mensagens m_resp WHERE m_resp.chamado_id = c.id AND m_resp.tipo = 'enviado') as first_reply_date
    FROM chamados c
    LEFT JOIN usuarios u ON c.usuario_id = u.id
    LEFT JOIN categorias_chamado cat ON c.categoria_id = cat.id
    WHERE c.requer_atencao = 1 AND c.id > ?
";

$params = [$last_id];
$types = "i";

// Adiciona os filtros que estão ativos na tela do usuário
if ($view_mode === 'mine') {
    $sql .= " AND c.usuario_id = ?";
    $params[] = $current_user_id;
    $types .= "i";
}
if ($category_id !== null) {
    $sql .= " AND c.categoria_id = ?";
    $params[] = $category_id;
    $types .= "i";
}

$sql .= " ORDER BY c.id DESC";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
$new_tickets = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

echo json_encode($new_tickets);
?>