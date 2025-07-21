<?php
require_once 'auth_check.php'; // Garante que o usuário está logado e a role está na sessão
require_once __DIR__ . '/config/config.php'; // Configurações do banco de dados

// Apenas administradores podem acessar esta página de relatório
if (!isset($_SESSION['usuario_role']) || $_SESSION['usuario_role'] !== 'admin') {
    header('Location: index.php?error=Acesso negado. Você não tem permissão para visualizar relatórios.');
    exit;
}

$message = '';
$client_reports = [];

// NOVO: Parâmetros de filtro de data
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';

// Constrói a cláusula WHERE para o filtro de período
$period_where_clause = ''; // Será prefixada com " WHERE " se não vazia
$period_params = [];
$period_types = '';

if (!empty($start_date) && !empty($end_date)) {
    $period_where_clause = " WHERE data_criacao BETWEEN ? AND ?";
    $period_params = [$start_date . ' 00:00:00', $end_date . ' 23:59:59'];
    $period_types = 'ss';

    if (strtotime($start_date) > strtotime($end_date)) {
        $message = "<div class='alert alert-warning'>A Data Inicial não pode ser maior que a Data Final.</div>";
        $period_where_clause = ''; 
        $period_params = [];
        $period_types = '';
    }
} elseif (!empty($start_date)) {
    $period_where_clause = " WHERE data_criacao >= ?";
    $period_params = [$start_date . ' 00:00:00'];
    $period_types = 's';
} elseif (!empty($end_date)) {
    $period_where_clause = " WHERE data_criacao <= ?";
    $period_params = [$end_date . ' 23:59:59'];
    $period_types = 's';
}


try {
    // Busca a contagem de chamados por cliente e seus respectivos status, aplicando o filtro de período
    $sql = "
        SELECT 
            email_cliente,
            COUNT(id) as total_chamados,
            COUNT(CASE WHEN status = 'Aberto' THEN 1 END) as abertos,
            COUNT(CASE WHEN status = 'Pendente' THEN 1 END) as pendentes,
            COUNT(CASE WHEN status = 'Fechado' THEN 1 END) as fechados
        FROM chamados 
        " . $period_where_clause . "
        GROUP BY email_cliente
        ORDER BY total_chamados DESC
    ";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if (!empty($period_params)) {
            $stmt->bind_param($period_types, ...$period_params);
        }
        $stmt->execute();
        $client_reports = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } else {
        error_log("Erro ao preparar query de relatório de cliente: " . $conn->error);
        $message = "<div class='alert alert-danger'>Erro ao preparar query de relatório de cliente.</div>";
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
    <title>Relatório por Cliente - Sistema de Chamados</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background-color: #f8f9fa; }
        .container { margin-top: 50px; margin-bottom: 50px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1>Relatório de Chamados por Cliente</h1>
            <a href="reports_dashboard.php" class="btn btn-secondary"><i class="bi bi-arrow-left-circle me-1"></i> Voltar para Painel de Relatórios</a>
        </div>

        <?php echo $message; ?>

        <div class="card mb-4">
            <div class="card-header">Filtrar por Período de Criação do Chamado</div>
            <div class="card-body">
                <form action="reports_client.php" method="GET" class="row g-3 align-items-end">
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
                        <a href="reports_client.php" class="btn btn-outline-secondary"><i class="bi bi-x-circle"></i> Limpar Filtro</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header">Clientes com Mais Chamados</div>
            <div class="card-body">
                <table class="table table-striped table-hover">
                    <thead>
                        <tr>
                            <th>E-mail do Cliente</th>
                            <th class="text-center">Total Chamados</th>
                            <th class="text-center">Abertos</th>
                            <th class="text-center">Pendentes</th>
                            <th class="text-center">Fechados</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($client_reports)): ?>
                            <tr>
                                <td colspan="5" class="text-center text-muted">Nenhum dado de cliente encontrado.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($client_reports as $report): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($report['email_cliente']); ?></td>
                                    <td class="text-center"><?php echo $report['total_chamados']; ?></td>
                                    <td class="text-center"><?php echo $report['abertos']; ?></td>
                                    <td class="text-center"><?php echo $report['pendentes']; ?></td>
                                    <td class="text-center"><?php echo $report['fechados']; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header">Observações do Relatório</div>
            <div class="card-body">
                <p>Este relatório lista os clientes por e-mail, ordenados pelo número total de chamados abertos, permitindo identificar os clientes mais ativos.</p>
                <p>O filtro por período considera a data de criação do chamado.</p>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>