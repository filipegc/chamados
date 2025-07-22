<?php
require_once 'auth_check.php';
require_once __DIR__ . '/config/config.php';

header('Content-Type: application/json');

// Prepara a query base para buscar chamados que foram atualizados
$sql = "
    SELECT 
        c.id, c.assunto, c.status, c.prioridade, c.email_cliente, c.data_criacao, c.ultimo_update,
        u.nome as nome_usuario,
        cat.nome as nome_categoria,
        (SELECT COUNT(*) FROM mensagens m_count WHERE m_count.chamado_id = c.id) as message_count
    FROM chamados c
    LEFT JOIN usuarios u ON c.usuario_id = u.id
    LEFT JOIN categorias_chamado cat ON c.categoria_id = cat.id
    WHERE c.requer_atencao = 1
";

// Filtros adicionais baseados na visão do usuário (Meus Chamados / Todos)
$params = [];
$types = "";
if (($_GET['view'] ?? 'all') === 'mine') {
    $sql .= " AND c.usuario_id = ?";
    $params[] = $_SESSION['usuario_id'];
    $types .= "i";
}

$sql .= " ORDER BY c.ultimo_update DESC";

$stmt = $conn->prepare($sql);
if (!empty($types)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();
$updated_tickets = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Se encontrou chamados atualizados, "desliga" o sinal para não notificar de novo
if (!empty($updated_tickets)) {
    $ids_to_reset = array_column($updated_tickets, 'id');
    $placeholders = implode(',', array_fill(0, count($ids_to_reset), '?'));
    
    $reset_stmt = $conn->prepare("UPDATE chamados SET requer_atencao = 0 WHERE id IN ($placeholders)");
    $reset_stmt->bind_param(str_repeat('i', count($ids_to_reset)), ...$ids_to_reset);
    $reset_stmt->execute();
    $reset_stmt->close();
}

// Retorna os chamados que foram atualizados
echo json_encode(['updated_tickets' => $updated_tickets]);
?>