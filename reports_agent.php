<?php
require_once 'auth_check.php'; // Garante que o usuário está logado e a role está na sessão
require_once __DIR__ . '/config/config.php'; // Configurações do banco de dados

// Apenas administradores podem acessar esta página de relatório
if (!isset($_SESSION['usuario_role']) || $_SESSION['usuario_role'] !== 'admin') {
    header('Location: index.php?error=Acesso negado. Você não tem permissão para visualizar relatórios.');
    exit;
}

$message = '';
$agent_reports = [];

// NOVO: Parâmetros de filtro de data
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';

// Constrói a cláusula WHERE para o filtro de período
$period_where = '';
$period_params = [];
$period_types = '';

if (!empty($start_date) && !empty($end_date)) {
    $period_where = " AND c.data_criacao BETWEEN ? AND ?"; // Adiciona AND pois já há um WHERE na query principal
    $period_params = [$start_date . ' 00:00:00', $end_date . ' 23:59:59'];
    $period_types = 'ss';

    if (strtotime($start_date) > strtotime($end_date)) {
        $message = "<div class='alert alert-warning'>A Data Inicial não pode ser maior que a Data Final.</div>";
        $period_where = ''; 
        $period_params = [];
        $period_types = '';
    }
} elseif (!empty($start_date)) {
    $period_where = " AND c.data_criacao >= ?";
    $period_params = [$start_date . ' 00:00:00'];
    $period_types = 's';
} elseif (!empty($end_date)) {
    $period_where = " AND c.data_criacao <= ?";
    $period_params = [$end_date . ' 23:59:59'];
    $period_types = 's';
}


try {
    // Busca a contagem de chamados por atendente e seus respectivos status
    $sql = "
        SELECT 
            u.id,
            u.nome, 
            u.email,
            COUNT(c.id) as total_chamados,
            COUNT(CASE WHEN c.status = 'Aberto' THEN 1 END) as abertos,
            COUNT(CASE WHEN c.status = 'Pendente' THEN 1 END) as pendentes,
            COUNT(CASE WHEN c.status = 'Fechado' THEN 1 END) as fechados
        FROM usuarios u 
        LEFT JOIN chamados c ON u.id = c.usuario_id
        WHERE u.ativo = 1 " . $period_where . "
        GROUP BY u.id, u.nome, u.email
        ORDER BY u.nome ASC
    ";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if (!empty($period_params)) {
            $stmt->bind_param($period_types, ...$period_params);
        }
        $stmt->execute();
        $agent_reports = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } else {
        error_log("Erro ao preparar query de relatório por atendente: " . $conn->error);
        $message = "<div class='alert alert-danger'>Erro ao preparar query de relatório por atendente.</div>";
    }

} catch (mysqli_sql_exception $e) {
    $message = "<div class='alert alert-danger'>Erro ao buscar dados do relatório: " . $e->getMessage() . "</div>";
}

// --- NOVO: Preparar dados para o gráfico de pizza ---
$chart_labels = [];
$chart_data = [];
$chart_background_colors = [];
$chart_border_colors = [];

// Cores pré-definidas para o gráfico de pizza (pode adicionar mais se tiver muitos agentes)
$colors = [
    'rgba(255, 99, 132, 0.6)', 'rgba(54, 162, 235, 0.6)', 'rgba(255, 206, 86, 0.6)',
    'rgba(75, 192, 192, 0.6)', 'rgba(153, 102, 255, 0.6)', 'rgba(255, 159, 64, 0.6)',
    'rgba(199, 199, 199, 0.6)', 'rgba(83, 102, 255, 0.6)', 'rgba(40, 159, 64, 0.6)',
    'rgba(201, 153, 102, 0.6)'
];
$border_colors = [
    'rgba(255, 99, 132, 1)', 'rgba(54, 162, 235, 1)', 'rgba(255, 206, 86, 1)',
    'rgba(75, 192, 192, 1)', 'rgba(153, 102, 255, 1)', 'rgba(255, 159, 64, 1)',
    'rgba(199, 199, 199, 1)', 'rgba(83, 102, 255, 1)', 'rgba(40, 159, 64, 1)',
    'rgba(201, 153, 102, 1)'
];

foreach ($agent_reports as $index => $report) {
    $chart_labels[] = htmlspecialchars($report['nome']);
    $chart_data[] = $report['total_chamados'];
    $chart_background_colors[] = $colors[$index % count($colors)]; // Cicla pelas cores
    $chart_border_colors[] = $border_colors[$index % count($border_colors)];
}

// Codificar para JSON para passar ao JavaScript
$chart_labels_json = json_encode($chart_labels);
$chart_data_json = json_encode($chart_data);
$chart_background_colors_json = json_encode($chart_background_colors);
$chart_border_colors_json = json_encode($chart_border_colors);

?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Relatório por Atendente - Sistema de Chamados</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { background-color: #f8f9fa; }
        .container { margin-top: 50px; margin-bottom: 50px; }
        .chart-container { 
            position: relative; 
            height: 40vh; /* Altura responsiva */
            width: 80%; /* Largura um pouco menor para melhor aspecto */
            margin: auto; /* Centraliza */
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1>Relatório de Chamados por Atendente</h1>
            <a href="reports_dashboard.php" class="btn btn-secondary"><i class="bi bi-arrow-left-circle me-1"></i> Voltar para Painel de Relatórios</a>
        </div>

        <?php echo $message; ?>

        <div class="card mb-4">
            <div class="card-header">Filtrar por Período de Criação do Chamado</div>
            <div class="card-body">
                <form action="reports_agent.php" method="GET" class="row g-3 align-items-end">
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
                        <a href="reports_agent.php" class="btn btn-outline-secondary"><i class="bi bi-x-circle"></i> Limpar Filtro</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header">Distribuição de Chamados por Atendente (Total)</div>
            <div class="card-body">
                <div class="chart-container">
                    <canvas id="agentPieChart"></canvas>
                </div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header">Desempenho dos Atendentes</div>
            <div class="card-body">
                <table class="table table-striped table-hover">
                    <thead>
                        <tr>
                            <th>Atendente</th>
                            <th>E-mail</th>
                            <th class="text-center">Total Chamados</th>
                            <th class="text-center">Abertos</th>
                            <th class="text-center">Pendentes</th>
                            <th class="text-center">Fechados</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($agent_reports)): ?>
                            <tr>
                                <td colspan="6" class="text-center text-muted">Nenhum dado de atendente encontrado.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($agent_reports as $report): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($report['nome']); ?></td>
                                    <td><?php echo htmlspecialchars($report['email']); ?></td>
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
                <p>Este relatório apresenta a distribuição dos chamados entre os atendentes ativos do sistema, detalhando o total de chamados atribuídos e o número de chamados em cada status.</p>
                <p>Chamados não atribuídos a nenhum atendente (`usuario_id` = NULL) não são contabilizados aqui.</p>
                <p>O filtro por período considera a data de criação do chamado.</p>
                <p>O gráfico de pizza mostra a proporção do total de chamados atribuídos a cada atendente.</p>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const ctxAgentPie = document.getElementById('agentPieChart');
        const agentLabels = <?php echo $chart_labels_json; ?>;
        const agentData = <?php echo $chart_data_json; ?>;
        const agentBackgroundColors = <?php echo $chart_background_colors_json; ?>;
        const agentBorderColors = <?php echo $chart_border_colors_json; ?>;

        new Chart(ctxAgentPie, {
            type: 'pie', // Tipo de gráfico: pizza
            data: {
                labels: agentLabels,
                datasets: [{
                    label: 'Total de Chamados',
                    data: agentData,
                    backgroundColor: agentBackgroundColors,
                    borderColor: agentBorderColors,
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false, // Permite controlar a altura/largura via CSS
                plugins: {
                    title: {
                        display: true,
                        text: 'Proporção de Chamados por Atendente'
                    },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                let label = context.label || '';
                                if (label) {
                                    label += ': ';
                                }
                                if (context.parsed !== null) {
                                    label += context.parsed;
                                    // Adiciona porcentagem
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const percentage = total > 0 ? ((context.parsed / total) * 100).toFixed(2) : 0;
                                    label += ` (${percentage}%)`;
                                }
                                return label;
                            }
                        }
                    }
                }
            }
        });
    </script>
</body>
</html>