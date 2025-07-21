<?php
require_once 'auth_check.php'; // Garante que o usuário está logado e a role está na sessão
require_once __DIR__ . '/config/config.php'; // Configurações do banco de dados

// Apenas administradores podem acessar esta página de relatório
if (!isset($_SESSION['usuario_role']) || $_SESSION['usuario_role'] !== 'admin') {
    header('Location: index.php?error=Acesso negado. Você não tem permissão para visualizar relatórios.');
    exit;
}

$message = '';
$avg_response_time_seconds = null;
$avg_resolution_time_seconds = null;

// NOVO: Parâmetros de filtro de data
$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';

// Constrói a cláusula WHERE para o filtro de período
// Será prefixada com " AND " ao ser adicionada às queries existentes
$period_sql_condition = ''; 
$period_params = [];
$period_types = '';

if (!empty($start_date) && !empty($end_date)) {
    $period_sql_condition = " AND data_criacao BETWEEN ? AND ?";
    $period_params = [$start_date . ' 00:00:00', $end_date . ' 23:59:59'];
    $period_types = 'ss';

    if (strtotime($start_date) > strtotime($end_date)) {
        $message = "<div class='alert alert-warning'>A Data Inicial não pode ser maior que a Data Final.</div>";
        $period_sql_condition = ''; 
        $period_params = [];
        $period_types = '';
    }
} elseif (!empty($start_date)) {
    $period_sql_condition = " AND data_criacao >= ?";
    $period_params = [$start_date . ' 00:00:00'];
    $period_types = 's';
} elseif (!empty($end_date)) {
    $period_sql_condition = " AND data_criacao <= ?";
    $period_params = [$end_date . ' 23:59:59'];
    $period_types = 's';
}


// Função auxiliar para formatar segundos em um formato legível (Xh Ym Zs)
function formatSecondsToTime($seconds) {
    if ($seconds === null || $seconds < 0) return 'N/A';
    
    $seconds = (float)$seconds; 

    $hours = floor($seconds / 3600);
    $remaining_after_hours = $seconds - ($hours * 3600); 

    $minutes = floor($remaining_after_hours / 60);
    $remaining_after_minutes = $remaining_after_hours - ($minutes * 60); 

    $remaining_seconds = round($remaining_after_minutes); 

    $time_parts = [];
    if ($hours > 0) $time_parts[] = (int)$hours . 'h'; 
    if ($minutes > 0) $time_parts[] = (int)$minutes . 'm'; 
    if ($remaining_seconds > 0 || empty($time_parts)) $time_parts[] = (int)$remaining_seconds . 's'; 

    return implode(' ', $time_parts);
}

try {
    // --- Tempo Médio da Primeira Resposta ---
    // A query base já tem WHERE e AND, então o $period_sql_condition é apenas anexado
    // MODIFICADO: Adicionado "AND m.tipo != 'automatico'" na subquery
    $sql_first_response = "
        SELECT AVG(TIMESTAMPDIFF(SECOND, c.data_criacao, (
            SELECT MIN(m.data_envio) 
            FROM mensagens m 
            WHERE m.chamado_id = c.id 
            AND m.tipo = 'enviado' AND m.tipo != 'automatico' -- Exclui mensagens automáticas
        ))) as avg_seconds
        FROM chamados c
        WHERE c.status != 'Aberto' 
        AND (SELECT COUNT(*) FROM mensagens m_check WHERE m_check.chamado_id = c.id AND m_check.tipo = 'enviado' AND m_check.tipo != 'automatico') > 0 -- Garante que houve pelo menos uma resposta real enviada
        " . $period_sql_condition; 
    
    $stmt_first_response = $conn->prepare($sql_first_response);
    if ($stmt_first_response) {
        if (!empty($period_params)) {
            $stmt_first_response->bind_param($period_types, ...$period_params);
        }
        $stmt_first_response->execute();
        $result = $stmt_first_response->get_result()->fetch_assoc();
        $avg_response_time_seconds = $result['avg_seconds'] ?? null;
        $stmt_first_response->close();
    } else {
        error_log("Erro ao preparar query de tempo de primeira resposta: " . $conn->error);
        $message .= "<div class='alert alert-danger'>Erro ao preparar query de tempo de primeira resposta.</div>";
    }

    // --- Tempo Médio de Resolução ---
    // A query base já tem WHERE, então o $period_sql_condition é apenas anexado
    $sql_resolution_time = "
        SELECT AVG(TIMESTAMPDIFF(SECOND, data_criacao, ultimo_update)) as avg_seconds
        FROM chamados
        WHERE status = 'Fechado' " . $period_sql_condition; 
    
    $stmt_resolution_time = $conn->prepare($sql_resolution_time);
    if ($stmt_resolution_time) {
        if (!empty($period_params)) {
            $stmt_resolution_time->bind_param($period_types, ...$period_params);
        }
        $stmt_resolution_time->execute();
        $result = $stmt_resolution_time->get_result()->fetch_assoc();
        $avg_resolution_time_seconds = $result['avg_seconds'] ?? null;
        $stmt_resolution_time->close();
    } else {
        error_log("Erro ao preparar query de tempo de resolução: " . $conn->error);
        $message .= "<div class='alert alert-danger'>Erro ao preparar query de tempo de resolução.</div>";
    }

} catch (mysqli_sql_exception $e) {
    $message = "<div class='alert alert-danger'>Erro ao buscar dados do relatório: " . $e->getMessage() . "</div>";
}

// --- Preparar dados para o gráfico ---
$chart_labels = json_encode(['Primeira Resposta', 'Tempo de Resolução']);
$chart_data = json_encode([
    $avg_response_time_seconds ?? 0, 
    $avg_resolution_time_seconds ?? 0
]);

// Preparar rótulos formatados para tooltips (mostrar "1h 30m 5s" em vez de só o número)
$chart_formatted_data_labels = json_encode([
    formatSecondsToTime($avg_response_time_seconds),
    formatSecondsToTime($avg_resolution_time_seconds)
]);

?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Relatório de Tempos - Sistema de Chamados</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { background-color: #f8f9fa; }
        .container { margin-top: 50px; margin-bottom: 50px; }
        .card-time-metric { text-align: center; }
        .card-time-metric .bi { font-size: 2.5rem; margin-bottom: 10px; }
        .card-first-response { background-color: #d1e7dd; color: #0f5132; }
        .card-resolution { background-color: #cff4fc; color: #055160; }
        .chart-container { 
            position: relative; 
            height: 300px; /* Altura fixa para o gráfico */
            width: 80%; /* Largura responsiva */
            margin: auto; /* Centraliza */
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1>Relatório de Tempo Médio</h1>
            <a href="reports_dashboard.php" class="btn btn-secondary"><i class="bi bi-arrow-left-circle me-1"></i> Voltar para Painel de Relatórios</a>
        </div>

        <?php echo $message; ?>

        <div class="card mb-4">
            <div class="card-header">Filtrar por Período de Criação do Chamado</div>
            <div class="card-body">
                <form action="reports_time.php" method="GET" class="row g-3 align-items-end">
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
                        <a href="reports_time.php" class="btn btn-outline-secondary"><i class="bi bi-x-circle"></i> Limpar Filtro</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header">Comparativo de Tempos Médios</div>
            <div class="card-body">
                <div class="chart-container">
                    <canvas id="timeChart"></canvas>
                </div>
            </div>
        </div>

        <div class="row row-cols-1 row-cols-md-2 g-4 mb-4">
            <div class="col">
                <div class="card h-100 card-time-metric card-first-response">
                    <div class="card-body">
                        <i class="bi bi-chat-left-text"></i>
                        <h3 class="card-title"><?php echo formatSecondsToTime($avg_response_time_seconds); ?></h3>
                        <p class="card-text">Tempo Médio da Primeira Resposta</p>
                    </div>
                </div>
            </div>
            <div class="col">
                <div class="card h-100 card-time-metric card-resolution">
                    <div class="card-body">
                        <i class="bi bi-clock-history"></i>
                        <h3 class="card-title"><?php echo formatSecondsToTime($avg_resolution_time_seconds); ?></h3>
                        <p class="card-text">Tempo Médio de Resolução</p>
                    </div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">Observações sobre as Métricas de Tempo</div>
            <div class="card-body">
                <p>Este relatório fornece insights sobre a eficiência do atendimento:</p>
                <ul>
                    <li>**Tempo Médio da Primeira Resposta**: Indica a agilidade em iniciar a comunicação com o cliente após a abertura do chamado. Considera o tempo entre a criação do chamado e a primeira mensagem de tipo 'enviado' (resposta do atendente).</li>
                    <li>**Tempo Médio de Resolução**: Mostra a eficiência em fechar os chamados. Calcula o tempo entre a criação do chamado e a sua última atualização quando o status é definido como 'Fechado'.</li>
                </ul>
                <p>Os tempos são calculados em segundos e formatados para horas (h), minutos (m) e segundos (s).</p>
                <p>O filtro por período considera a data de criação do chamado.</p>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
    // PASSO 1: Recriar a função de formatação em JavaScript
    function formatSecondsToTimeJS(seconds) {
        if (seconds === null || seconds < 0) return 'N/A';
        
        seconds = parseFloat(seconds);

        const hours = Math.floor(seconds / 3600);
        const minutes = Math.floor((seconds % 3600) / 60);
        const remaining_seconds = Math.round(seconds % 60);

        const time_parts = [];
        if (hours > 0) time_parts.push(hours + 'h');
        if (minutes > 0) time_parts.push(minutes + 'm');
        if (remaining_seconds > 0 || time_parts.length === 0) {
            time_parts.push(remaining_seconds + 's');
        }

        return time_parts.join(' ');
    }

    // PASSO 2: Usar a nova função no seu gráfico
    const ctxTime = document.getElementById('timeChart');
    if (ctxTime) { // Garante que o elemento canvas existe antes de criar o gráfico
        const timeLabels = <?php echo $chart_labels; ?>;
        const timeData = <?php echo $chart_data; ?>;
        const formattedTimeLabels = <?php echo $chart_formatted_data_labels; ?>;

        new Chart(ctxTime, {
            type: 'bar',
            data: {
                labels: timeLabels,
                datasets: [{
                    label: 'Tempo Médio em Segundos',
                    data: timeData,
                    backgroundColor: ['rgba(75, 192, 192, 0.6)', 'rgba(54, 162, 235, 0.6)'],
                    borderColor: ['rgba(75, 192, 192, 1)', 'rgba(54, 162, 235, 1)'],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                // Usa o rótulo pré-formatado pelo PHP para o tooltip
                                return formattedTimeLabels[context.dataIndex];
                            }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        title: {
                            display: true,
                            text: 'Tempo Médio'
                        },
                        ticks: {
                            callback: function(value, index, ticks) {
                                // Usa a nova função JavaScript para formatar o eixo Y
                                return formatSecondsToTimeJS(value); 
                            }
                        }
                    }
                }
            }
        });
    }
</script>