<?php
require_once 'auth_check.php'; // Garante que o usuário está logado e a role está na sessão
require_once __DIR__ . '/config/config.php'; // Configurações do banco de dados

// Apenas administradores podem acessar esta página de relatório
if (!isset($_SESSION['usuario_role']) || $_SESSION['usuario_role'] !== 'admin') {
    header('Location: index.php?error=Acesso negado. Você não tem permissão para visualizar relatórios.');
    exit;
}

$message = '';
$category_reports = [];
$unassigned_category_counts = [
    'total_chamados' => 0,
    'abertos' => 0,
    'pendentes' => 0,
    'fechados' => 0
];

// NOVO: Parâmetros de filtro de data
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';

// Constrói a cláusula WHERE para o filtro de período
$period_sql_condition = ''; // Usada para as queries, sem o WHERE inicial
$period_params = [];
$period_types = '';

if (!empty($start_date) && !empty($end_date)) {
    $period_sql_condition = " AND c.data_criacao BETWEEN ? AND ?";
    $period_params = [$start_date . ' 00:00:00', $end_date . ' 23:59:59'];
    $period_types = 'ss';

    if (strtotime($start_date) > strtotime($end_date)) {
        $message = "<div class='alert alert-warning'>A Data Inicial não pode ser maior que a Data Final.</div>";
        $period_sql_condition = ''; 
        $period_params = [];
        $period_types = '';
    }
} elseif (!empty($start_date)) {
    $period_sql_condition = " AND c.data_criacao >= ?";
    $period_params = [$start_date . ' 00:00:00'];
    $period_types = 's';
} elseif (!empty($end_date)) {
    $period_sql_condition = " AND c.data_criacao <= ?";
    $period_params = [$end_date . ' 23:59:59'];
    $period_types = 's';
}


try {
    // Busca a contagem de chamados por categoria ativa, aplicando o filtro de período
    $sql_category_report = "
        SELECT 
            cat.id,
            cat.nome, 
            COUNT(c.id) as total_chamados,
            COUNT(CASE WHEN c.status = 'Aberto' THEN 1 END) as abertos,
            COUNT(CASE WHEN c.status = 'Pendente' THEN 1 END) as pendentes,
            COUNT(CASE WHEN c.status = 'Fechado' THEN 1 END) as fechados
        FROM categorias_chamado cat 
        LEFT JOIN chamados c ON cat.id = c.categoria_id
        WHERE cat.ativa = 1 " . $period_sql_condition . "
        GROUP BY cat.id, cat.nome
        ORDER BY cat.nome ASC
    ";
    $stmt = $conn->prepare($sql_category_report);
    if ($stmt) {
        if (!empty($period_params)) {
            $stmt->bind_param($period_types, ...$period_params);
        }
        $stmt->execute();
        $category_reports = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } else {
        error_log("Erro ao preparar query de relatório por categoria: " . $conn->error);
        $message = "<div class='alert alert-danger'>Erro ao preparar query de relatório por categoria.</div>";
    }

    // Busca chamados sem categoria, aplicando o filtro de período
    $sql_unassigned = "
        SELECT 
            COUNT(c.id) as total_chamados,
            COUNT(CASE WHEN c.status = 'Aberto' THEN 1 END) as abertos,
            COUNT(CASE WHEN c.status = 'Pendente' THEN 1 END) as pendentes,
            COUNT(CASE WHEN c.status = 'Fechado' THEN 1 END) as fechados
        FROM chamados c
        WHERE c.categoria_id IS NULL " . $period_sql_condition . "
    ";
    $stmt_unassigned = $conn->prepare($sql_unassigned);
    if ($stmt_unassigned) {
        if (!empty($period_params)) {
            $stmt_unassigned->bind_param($period_types, ...$period_params);
        }
        $stmt_unassigned->execute();
        $unassigned_category_counts = $stmt_unassigned->get_result()->fetch_assoc();
        $stmt_unassigned->close();
    } else {
        error_log("Erro ao preparar query de chamados sem categoria: " . $conn->error);
        $message .= "<div class='alert alert-danger'>Erro ao preparar query de chamados sem categoria.</div>";
    }

} catch (mysqli_sql_exception $e) {
    $message = "<div class='alert alert-danger'>Erro ao buscar dados do relatório: " . $e->getMessage() . "</div>";
}

// --- NOVO: Preparar dados para o gráfico ---
$chart_labels = [];
$chart_data_total = [];
$chart_data_abertos = [];
$chart_data_pendentes = [];
$chart_data_fechados = [];

foreach ($category_reports as $report) {
    $chart_labels[] = htmlspecialchars($report['nome']);
    $chart_data_total[] = $report['total_chamados'];
    $chart_data_abertos[] = $report['abertos'];
    $chart_data_pendentes[] = $report['pendentes'];
    $chart_data_fechados[] = $report['fechados'];
}

// Adicionar "Sem Categoria" se houver chamados
if ($unassigned_category_counts['total_chamados'] > 0) {
    $chart_labels[] = "** Sem Categoria **";
    $chart_data_total[] = $unassigned_category_counts['total_chamados'];
    $chart_data_abertos[] = $unassigned_category_counts['abertos'];
    $chart_data_pendentes[] = $unassigned_category_counts['pendentes'];
    $chart_data_fechados[] = $unassigned_category_counts['fechados'];
}

// Codificar para JSON para passar ao JavaScript
$chart_labels_json = json_encode($chart_labels);
$chart_data_total_json = json_encode($chart_data_total);
$chart_data_abertos_json = json_encode($chart_data_abertos);
$chart_data_pendentes_json = json_encode($chart_data_pendentes);
$chart_data_fechados_json = json_encode($chart_data_fechados);

?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Relatório por Categoria - Sistema de Chamados</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { background-color: #f8f9fa; }
        .container { margin-top: 50px; margin-bottom: 50px; }
        .prioridade-baixa-card { background-color: #d1e7dd; color: #0f5132; }
        .prioridade-media-card { background-color: #fff3cd; color: #664d03; }
        .prioridade-alta-card { background-color: #f8d7da; color: #842029; }
        .total-card { background-color: #e2e6ea; color: #343a40; }
        .category-name-link {
            text-decoration: none;
            color: inherit; /* Mantém a cor do texto padrão da tabela */
            font-weight: bold;
        }
        .category-name-link:hover {
            text-decoration: underline;
            color: #0d6efd; /* Cor azul do Bootstrap para indicar clicável */
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1>Relatório de Chamados por Categoria</h1>
            <a href="reports_dashboard.php" class="btn btn-secondary"><i class="bi bi-arrow-left-circle me-1"></i> Voltar para Painel de Relatórios</a>
        </div>

        <?php echo $message; ?>

        <div class="card mb-4">
            <div class="card-header">Filtrar por Período de Criação do Chamado</div>
            <div class="card-body">
                <form action="reports_category.php" method="GET" class="row g-3 align-items-end">
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
                        <a href="reports_category.php" class="btn btn-outline-secondary"><i class="bi bi-x-circle"></i> Limpar Filtro</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header">Gráfico de Chamados por Categoria e Status</div>
            <div class="card-body">
                <canvas id="categoryChart"></canvas>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header">Distribuição por Categoria</div>
            <div class="card-body">
                <table class="table table-striped table-hover">
                    <thead>
                        <tr>
                            <th>Categoria</th>
                            <th class="text-center">Total Chamados</th>
                            <th class="text-center">Abertos</th>
                            <th class="text-center">Pendentes</th>
                            <th class="text-center">Fechados</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($category_reports) && $unassigned_category_counts['total_chamados'] == 0): ?>
                            <tr>
                                <td colspan="5" class="text-center text-muted">Nenhum dado de categoria encontrado.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($category_reports as $report): ?>
                                <tr>
                                    <td>
                                        <a href="index.php?category_id=<?php echo htmlspecialchars($report['id']); ?>&view=all&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?>" class="category-name-link">
                                            <?php echo htmlspecialchars($report['nome']); ?>
                                        </a>
                                    </td>
                                    <td class="text-center"><?php echo $report['total_chamados']; ?></td>
                                    <td class="text-center"><?php echo $report['abertos']; ?></td>
                                    <td class="text-center"><?php echo $report['pendentes']; ?></td>
                                    <td class="text-center"><?php echo $report['fechados']; ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if ($unassigned_category_counts['total_chamados'] > 0): ?>
                                <tr class="table-warning">
                                    <td>
                                        <a href="index.php?category_id=&view=all&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?>" class="category-name-link">
                                            ** Sem Categoria **
                                        </a>
                                    </td>
                                    <td class="text-center"><?php echo $unassigned_category_counts['total_chamados']; ?></td>
                                    <td class="text-center"><?php echo $unassigned_category_counts['abertos']; ?></td>
                                    <td class="text-center"><?php echo $unassigned_category_counts['pendentes']; ?></td>
                                    <td class="text-center"><?php echo $unassigned_category_counts['fechados']; ?></td>
                                </tr>
                            <?php endif; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card">
            <div class="card-header">Observações do Relatório</div>
            <div class="card-body">
                <p>Este relatório apresenta a distribuição dos chamados entre as categorias ativas do sistema, incluindo os chamados que ainda não foram categorizados.</p>
                <p>O filtro por período considera a data de criação do chamado.</p>
                <p>Clique no nome de uma categoria para ver os chamados correspondentes.</p>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const ctx = document.getElementById('categoryChart');
        const labels = <?php echo $chart_labels_json; ?>;
        const dataTotal = <?php echo $chart_data_total_json; ?>;
        const dataAbertos = <?php echo $chart_data_abertos_json; ?>;
        const dataPendentes = <?php echo $chart_data_pendentes_json; ?>;
        const dataFechados = <?php echo $chart_data_fechados_json; ?>;

        new Chart(ctx, {
            type: 'bar', // Tipo de gráfico: barras
            data: {
                labels: labels,
                datasets: [
                    {
                        label: 'Total de Chamados',
                        data: dataTotal,
                        backgroundColor: 'rgba(75, 192, 192, 0.6)',
                        borderColor: 'rgba(75, 192, 192, 1)',
                        borderWidth: 1
                    },
                    {
                        label: 'Chamados Abertos',
                        data: dataAbertos,
                        backgroundColor: 'rgba(255, 99, 132, 0.6)',
                        borderColor: 'rgba(255, 99, 132, 1)',
                        borderWidth: 1
                    },
                    {
                        label: 'Chamados Pendentes',
                        data: dataPendentes,
                        backgroundColor: 'rgba(255, 206, 86, 0.6)',
                        borderColor: 'rgba(255, 206, 86, 1)',
                        borderWidth: 1
                    },
                    {
                        label: 'Chamados Fechados',
                        data: dataFechados,
                        backgroundColor: 'rgba(54, 162, 235, 0.6)',
                        borderColor: 'rgba(54, 162, 235, 1)',
                        borderWidth: 1
                    }
                ]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Número de Chamados'
                        }
                    },
                    x: {
                        title: {
                            display: true,
                            text: 'Categorias'
                        }
                    }
                },
                plugins: {
                    title: {
                        display: true,
                        text: 'Chamados por Categoria e Status'
                    }
                }
            }
        });
    </script>
</body>
</html>