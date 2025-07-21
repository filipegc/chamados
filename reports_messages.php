<?php
require_once 'auth_check.php'; // Garante que o usuário está logado e a role está na sessão
require_once __DIR__ . '/config/config.php'; // Configurações do banco de dados

// Apenas administradores podem acessar esta página de relatório
if (!isset($_SESSION['usuario_role']) || $_SESSION['usuario_role'] !== 'admin') {
    header('Location: index.php?error=Acesso negado. Você não tem permissão para visualizar relatórios.');
    exit;
}

$message = '';
$message_type_counts = [
    'recebido' => 0,
    'enviado' => 0,
    'interno' => 0,
    'Total' => 0
];

// NOVO: Parâmetros de filtro de data
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';

// Constrói a cláusula WHERE para o filtro de período
$period_where = '';
$period_params = [];
$period_types = '';

if (!empty($start_date) && !empty($end_date)) {
    $period_where = " WHERE data_envio BETWEEN ? AND ?";
    $period_params = [$start_date . ' 00:00:00', $end_date . ' 23:59:59'];
    $period_types = 'ss';

    if (strtotime($start_date) > strtotime($end_date)) {
        $message = "<div class='alert alert-warning'>A Data Inicial não pode ser maior que a Data Final.</div>";
        $period_where = ''; 
        $period_params = [];
        $period_types = '';
    }
} elseif (!empty($start_date)) {
    $period_where = " WHERE data_envio >= ?";
    $period_params = [$start_date . ' 00:00:00'];
    $period_types = 's';
} elseif (!empty($end_date)) {
    $period_where = " WHERE data_envio <= ?";
    $period_params = [$end_date . ' 23:59:59'];
    $period_types = 's';
}


try {
    // Busca a contagem de mensagens por tipo, aplicando o filtro de período
    $sql = "SELECT tipo, COUNT(id) as count FROM mensagens";
    $params = [];
    $types = '';

    if (!empty($period_where)) {
        $sql .= $period_where;
        $params = array_merge($params, $period_params);
        $types .= $period_types;
    }
    $sql .= " GROUP BY tipo";

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();

        $total_messages = 0;
        while ($row = $result->fetch_assoc()) {
            $message_type_counts[$row['tipo']] = $row['count'];
            $total_messages += $row['count'];
        }
        $message_type_counts['Total'] = $total_messages;
        $stmt->close();
    } else {
        error_log("Erro ao preparar query de relatório de mensagens: " . $conn->error);
        $message = "<div class='alert alert-danger'>Erro ao preparar query de relatório de mensagens.</div>";
    }

} catch (mysqli_sql_exception $e) {
    $message = "<div class='alert alert-danger'>Erro ao buscar dados do relatório: " . $e->getMessage() . "</div>";
}

?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Relatório de Mensagens - Sistema de Chamados</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background-color: #f8f9fa; }
        .container { margin-top: 50px; margin-bottom: 50px; }
        .card-message-type { text-align: center; }
        .card-message-type .bi { font-size: 2.5rem; margin-bottom: 10px; }
        .type-recebido { background-color: #d1e7dd; color: #0f5132; }
        .type-enviado { background-color: #cff4fc; color: #055160; }
        .type-interno { background-color: #fff3cd; color: #664d03; }
        .type-total { background-color: #e2e6ea; color: #343a40; }
    </style>
</head>
<body>
    <div class="container">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1>Relatório de Atividade de Mensagens</h1>
            <a href="reports_dashboard.php" class="btn btn-secondary"><i class="bi bi-arrow-left-circle me-1"></i> Voltar para Painel de Relatórios</a>
        </div>

        <?php echo $message; ?>

        <div class="card mb-4">
            <div class="card-header">Filtrar por Período de Envio</div>
            <div class="card-body">
                <form action="reports_messages.php" method="GET" class="row g-3 align-items-end">
                    <div class="col-md-4">
                        <label for="start_date" class="form-label">Data Inicial:</label>
                        <input type="date" class="form-control" id="start_date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>">
                    </div>
                    <div class="col-md-4">
                        <label for="end_date" class="form-label">Data Final:</label>
                        <input type="date" class="form-control" id="end_date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>">
                    </div>
                    <div class="col-md-4">
                        <button type="submit" class="btn btn-primary me-2"><i class="bi bi-funnel"></i> Aplicar Filtro</button>
                        <a href="reports_messages.php" class="btn btn-outline-secondary"><i class="bi bi-x-circle"></i> Limpar Filtro</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="row row-cols-1 row-cols-md-4 g-4 mb-4">
            <div class="col">
                <div class="card h-100 card-message-type type-recebido">
                    <div class="card-body">
                        <i class="bi bi-envelope-arrow-down"></i>
                        <h3 class="card-title"><?php echo $message_type_counts['recebido']; ?></h3>
                        <p class="card-text">Mensagens Recebidas</p>
                    </div>
                </div>
            </div>
            <div class="col">
                <div class="card h-100 card-message-type type-enviado">
                    <div class="card-body">
                        <i class="bi bi-envelope-arrow-up"></i>
                        <h3 class="card-title"><?php echo $message_type_counts['enviado']; ?></h3>
                        <p class="card-text">Mensagens Enviadas</p>
                    </div>
                </div>
            </div>
            <div class="col">
                <div class="card h-100 card-message-type type-interno">
                    <div class="card-body">
                        <i class="bi bi-card-text"></i>
                        <h3 class="card-title"><?php echo $message_type_counts['interno']; ?></h3>
                        <p class="card-text">Notas Internas</p>
                    </div>
                </div>
            </div>
            <div class="col">
                <div class="card h-100 card-message-type type-total">
                    <div class="card-body">
                        <i class="bi bi-chat-dots"></i>
                        <h3 class="card-title"><?php echo $message_type_counts['Total']; ?></h3>
                        <p class="card-text">Total de Mensagens</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">Observações do Relatório</div>
            <div class="card-body">
                <p>Este relatório apresenta a contagem de mensagens no sistema, discriminadas por tipo: Recebidas (de clientes), Enviadas (respostas de atendentes) e Internas (notas entre a equipe).</p>
                <p>Os dados são filtrados com base na data de envio da mensagem.</p>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>