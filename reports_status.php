<?php
require_once 'auth_check.php'; // Garante que o usuário está logado e a role está na sessão
require_once __DIR__ . '/config/config.php'; // Configurações do banco de dados

// Apenas administradores podem acessar esta página de relatório
if (!isset($_SESSION['usuario_role']) || $_SESSION['usuario_role'] !== 'admin') {
    header('Location: index.php?error=Acesso negado. Você não tem permissão para visualizar relatórios.');
    exit;
}

$message = '';
$status_counts = [
    'Aberto' => 0,
    'Pendente' => 0,
    'Fechado' => 0,
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
    // Para incluir o dia inteiro final, adicionamos ' 23:59:59'
    $period_where = " WHERE data_criacao BETWEEN ? AND ?";
    $period_params = [$start_date . ' 00:00:00', $end_date . ' 23:59:59'];
    $period_types = 'ss';

    // Validação básica da data (opcional, pode ser mais robusta)
    if (strtotime($start_date) > strtotime($end_date)) {
        $message = "<div class='alert alert-warning'>A Data Inicial não pode ser maior que a Data Final.</div>";
        // Resetar para não aplicar o filtro incorreto
        $period_where = ''; 
        $period_params = [];
        $period_types = '';
    }

} elseif (!empty($start_date)) {
    $period_where = " WHERE data_criacao >= ?";
    $period_params = [$start_date . ' 00:00:00'];
    $period_types = 's';
} elseif (!empty($end_date)) {
    $period_where = " WHERE data_criacao <= ?";
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
    // Arrays para armazenar as partes da query
    $queries = [
        'Aberto' => "SELECT COUNT(*) as count FROM chamados",
        'Pendente' => "SELECT COUNT(*) as count FROM chamados",
        'Fechado' => "SELECT COUNT(*) as count FROM chamados",
        'Total' => "SELECT COUNT(*) as count FROM chamados"
    ];

    foreach ($queries as $status_key => $base_sql) {
        $sql = $base_sql;
        $params = [];
        $types = '';

        if (!empty($period_where)) {
            $sql .= $period_where . ($status_key !== 'Total' ? " AND status = ?" : "");
            $params = array_merge([], $period_params); // Copia os parâmetros de período
            $types = $period_types;
            if ($status_key !== 'Total') {
                $params[] = $status_key;
                $types .= 's';
            }
        } else {
            if ($status_key !== 'Total') {
                $sql .= " WHERE status = ?";
                $params[] = $status_key;
                $types .= 's';
            }
        }
        
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            if (!empty($params)) {
                $stmt->bind_param($types, ...$params);
            }
            $stmt->execute();
            $status_counts[$status_key] = $stmt->get_result()->fetch_assoc()['count'];
            $stmt->close();
        } else {
            error_log("Erro ao preparar query para status {$status_key}: " . $conn->error);
            $message .= "<div class='alert alert-danger'>Erro ao preparar query para o status {$status_key}.</div>";
        }
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
    <title>Relatório de Status - Sistema de Chamados</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background-color: #f8f9fa; }
        .container { margin-top: 50px; margin-bottom: 50px; }
        .card-report { text-align: center; }
        .card-report .bi { font-size: 2.5rem; margin-bottom: 10px; }
        .status-aberto-card { background-color: #d1e7dd; color: #0f5132; }
        .status-pendente-card { background-color: #fff3cd; color: #664d03; }
        .status-fechado-card { background-color: #f8d7da; color: #842029; }
        .status-total-card { background-color: #e2e6ea; color: #343a40; }

        /* Estilo para tornar os cards clicáveis */
        .report-card-link { 
            text-decoration: none; 
            color: inherit; 
            display: block; 
            height: 100%;
        }
        .report-card-link:hover .card {
            transform: translateY(-5px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .report-card-link .card {
            transition: all 0.2s ease-in-out;
            cursor: pointer;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1>Relatório de Status de Chamados</h1>
            <a href="reports_dashboard.php" class="btn btn-secondary"><i class="bi bi-arrow-left-circle me-1"></i> Voltar para Painel de Relatórios</a>
        </div>

        <?php echo $message; ?>

        <div class="card mb-4">
            <div class="card-header">Filtrar por Período de Criação</div>
            <div class="card-body">
                <form action="reports_status.php" method="GET" class="row g-3 align-items-end">
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
                        <a href="reports_status.php" class="btn btn-outline-secondary"><i class="bi bi-x-circle"></i> Limpar Filtro</a>
                    </div>
                </form>
            </div>
        </div>

        <div class="row row-cols-1 row-cols-md-4 g-4 mb-4">
            <div class="col">
                <a href="index.php?tab=aberto&view=all&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?>" class="report-card-link">
                    <div class="card h-100 card-report status-aberto-card">
                        <div class="card-body">
                            <i class="bi bi-folder-open"></i>
                            <h3 class="card-title"><?php echo $status_counts['Aberto']; ?></h3>
                            <p class="card-text">Chamados Abertos</p>
                        </div>
                    </div>
                </a>
            </div>
            <div class="col">
                <a href="index.php?tab=pendente&view=all&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?>" class="report-card-link">
                    <div class="card h-100 card-report status-pendente-card">
                        <div class="card-body">
                            <i class="bi bi-hourglass-split"></i>
                            <h3 class="card-title"><?php echo $status_counts['Pendente']; ?></h3>
                            <p class="card-text">Chamados Pendentes</p>
                        </div>
                    </div>
                </a>
            </div>
            <div class="col">
                <a href="index.php?tab=fechado&view=all&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?>" class="report-card-link">
                    <div class="card h-100 card-report status-fechado-card">
                        <div class="card-body">
                            <i class="bi bi-check-circle"></i>
                            <h3 class="card-title"><?php echo $status_counts['Fechado']; ?></h3>
                            <p class="card-text">Chamados Fechados</p>
                        </div>
                    </div>
                </a>
            </div>
            <div class="col">
                <a href="index.php?view=all&start_date=<?php echo urlencode($start_date); ?>&end_date=<?php echo urlencode($end_date); ?>" class="report-card-link">
                    <div class="card h-100 card-report status-total-card">
                        <div class="card-body">
                            <i class="bi bi-list-task"></i>
                            <h3 class="card-title"><?php echo $status_counts['Total']; ?></h3>
                            <p class="card-text">Total de Chamados</p>
                        </div>
                    </div>
                </a>
            </div>
        </div>

        <div class="card">
            <div class="card-header">Detalhes por Status</div>
            <div class="card-body">
                <p>Este relatório apresenta uma visão geral da distribuição dos chamados por status atual.</p>
                <ul>
                    <li>**Chamados Abertos**: Tickets que foram criados e aguardam a primeira interação ou estão em andamento.</li>
                    <li>**Chamados Pendentes**: Tickets que tiveram alguma interação, mas ainda não foram resolvidos e aguardam alguma ação (do cliente ou do atendente).</li>
                    <li>**Chamados Fechados**: Tickets que foram resolvidos e encerrados.</li>
                </ul>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>