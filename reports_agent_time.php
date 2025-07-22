<?php
require_once 'auth_check.php';
require_once __DIR__ . '/config/config.php';

// Apenas administradores podem acessar
if (!isset($_SESSION['usuario_role']) || $_SESSION['usuario_role'] !== 'admin') {
    header('Location: index.php?error=Acesso negado.');
    exit;
}

$message = '';
$agent_metrics = [];

// Filtros
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';

// Função auxiliar para formatar segundos
function formatSecondsToTime($seconds) {
    if ($seconds === null || $seconds <= 0) return 'N/A';
    $seconds = (int)$seconds;
    $h = floor($seconds / 3600);
    $m = floor(($seconds % 3600) / 60);
    $s = $seconds % 60;
    $parts = [];
    if ($h > 0) $parts[] = "{$h}h";
    if ($m > 0) $parts[] = "{$m}m";
    if ($s > 0 || empty($parts)) $parts[] = "{$s}s";
    return implode(' ', $parts);
}

try {
    // Monta a query de forma segura
    $where_conditions = ["c.status = 'Fechado'"]; // Analisamos apenas o ciclo completo de chamados fechados
    $params = [];
    $types = '';

    if (!empty($start_date)) {
        $where_conditions[] = "c.data_criacao >= ?";
        $params[] = $start_date . ' 00:00:00';
        $types .= 's';
    }
    if (!empty($end_date)) {
        $where_conditions[] = "c.data_criacao <= ?";
        $params[] = $end_date . ' 23:59:59';
        $types .= 's';
    }
    
    $where_clause = "WHERE " . implode(' AND ', $where_conditions);

    // Query atualizada para calcular os dois tempos médios
    $sql = "
        SELECT 
            u.nome as atendente_nome,
            COUNT(DISTINCT c.id) as total_chamados_fechados,
            AVG(TIMESTAMPDIFF(SECOND, c.data_criacao, c.ultimo_update)) as avg_resolution_seconds,
            AVG(fr.first_reply_seconds) as avg_first_reply_seconds
        FROM chamados c
        JOIN usuarios u ON c.usuario_id = u.id
        LEFT JOIN (
            SELECT 
                m.chamado_id,
                TIMESTAMPDIFF(SECOND, c_inner.data_criacao, MIN(m.data_envio)) as first_reply_seconds
            FROM mensagens m
            JOIN chamados c_inner ON m.chamado_id = c_inner.id
            WHERE m.tipo = 'enviado'
            GROUP BY m.chamado_id, c_inner.data_criacao
        ) AS fr ON c.id = fr.chamado_id
        $where_clause
        GROUP BY u.id, u.nome
        ORDER BY avg_resolution_seconds ASC, avg_first_reply_seconds ASC
    ";
    
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $agent_metrics = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
    } else {
        throw new Exception("Erro ao preparar query: " . $conn->error);
    }

} catch (Exception $e) {
    $message = "<div class='alert alert-danger'>Erro ao buscar dados do relatório: " . $e->getMessage() . "</div>";
}

// Preparar dados para o gráfico
$chart_labels = [];
$chart_data_reply = [];
$chart_data_resolution = [];

foreach ($agent_metrics as $agent) {
    $chart_labels[] = $agent['atendente_nome'];
    // Converte para minutos para melhor visualização no gráfico
    $chart_data_reply[] = $agent['avg_first_reply_seconds'] ? round($agent['avg_first_reply_seconds'] / 60, 2) : 0;
    $chart_data_resolution[] = $agent['avg_resolution_seconds'] ? round($agent['avg_resolution_seconds'] / 60, 2) : 0;
}

$chart_labels_json = json_encode($chart_labels);
$chart_data_reply_json = json_encode($chart_data_reply);
$chart_data_resolution_json = json_encode($chart_data_resolution);
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Relatório de Desempenho por Atendente - Sistema de Chamados</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { background-color: #f8f9fa; }
        .container { margin-top: 50px; margin-bottom: 50px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1>Relatório de Desempenho por Atendente</h1>
            <a href="reports_dashboard.php" class="btn btn-secondary"><i class="bi bi-arrow-left-circle me-1"></i> Voltar</a>
        </div>

        <?php echo $message; ?>

        <div class="card mb-4">
            <div class="card-header">Filtrar por Período de Abertura do Chamado</div>
            <div class="card-body">
                <form action="reports_agent_time.php" method="GET" class="row g-3 align-items-end">
                    <div class="col-md-4"><label for="start_date" class="form-label">Data Inicial:</label><input type="date" class="form-control" id="start_date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>"></div>
                    <div class="col-md-4"><label for="end_date" class="form-label">Data Final:</label><input type="date" class="form-control" id="end_date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>"></div>
                    <div class="col-md-4"><button type="submit" class="btn btn-primary me-2"><i class="bi bi-funnel"></i> Aplicar</button><a href="reports_agent_time.php" class="btn btn-outline-secondary"><i class="bi bi-x-circle"></i> Limpar</a></div>
                </form>
            </div>
        </div>

        <div class="row">
            <div class="col-lg-7 mb-4">
                <div class="card h-100">
                    <div class="card-header">Ranking de Desempenho (Baseado em Chamados Fechados)</div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>Atendente</th>
                                        <th class="text-center">Chamados Fechados</th>
                                        <th class="text-center">Tempo Médio 1ª Resp.</th>
                                        <th class="text-center">Tempo Médio Resolução</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($agent_metrics)): ?>
                                        <tr><td colspan="4" class="text-center text-muted py-4">Nenhum chamado fechado encontrado no período.</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($agent_metrics as $agent): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($agent['atendente_nome']); ?></td>
                                                <td class="text-center"><?php echo $agent['total_chamados_fechados']; ?></td>
                                                <td class="text-center fw-bold text-primary"><?php echo formatSecondsToTime($agent['avg_first_reply_seconds']); ?></td>
                                                <td class="text-center fw-bold text-success"><?php echo formatSecondsToTime($agent['avg_resolution_seconds']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-5 mb-4">
                 <div class="card h-100">
                    <div class="card-header">Gráfico Comparativo (em minutos)</div>
                     <div class="card-body" style="min-height: 400px;">
                         <canvas id="agentTimeChart"></canvas>
                     </div>
                 </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const ctx = document.getElementById('agentTimeChart');
        if (ctx) {
            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: <?php echo $chart_labels_json; ?>,
                    datasets: [
                        {
                            label: 'Tempo 1ª Resposta (Minutos)',
                            data: <?php echo $chart_data_reply_json; ?>,
                            backgroundColor: 'rgba(54, 162, 235, 0.7)',
                            borderColor: 'rgba(54, 162, 235, 1)',
                            borderWidth: 1
                        },
                        {
                            label: 'Tempo Resolução (Minutos)',
                            data: <?php echo $chart_data_resolution_json; ?>,
                            backgroundColor: 'rgba(75, 192, 192, 0.7)',
                            borderColor: 'rgba(75, 192, 192, 1)',
                            borderWidth: 1
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: { beginAtZero: true },
                        x: { title: { display: true, text: 'Atendentes' } }
                    },
                    plugins: {
                        legend: { position: 'top' },
                        tooltip: {
                            mode: 'index',
                            intersect: false
                        }
                    }
                }
            });
        }
    </script>
</body>
</html>