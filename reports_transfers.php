<?php
require_once 'auth_check.php';
require_once __DIR__ . '/config/config.php';

// Apenas administradores podem acessar
if (!isset($_SESSION['usuario_role']) || $_SESSION['usuario_role'] !== 'admin') {
    header('Location: index.php?error=Acesso negado.');
    exit;
}

$message = '';
$detailed_transfers = [];
$transfers_from = [];
$transfers_to = [];

$start_date = $_GET['start_date'] ?? '';
$end_date = $_GET['end_date'] ?? '';

try {
    // Monta a query de forma segura
    $where_conditions = ["m.tipo = 'interno'", "m.corpo_html LIKE '%<em>Chamado reatribuído de%'"];
    $params = [];
    $types = '';

    if (!empty($start_date)) {
        $where_conditions[] = "m.data_envio >= ?";
        $params[] = $start_date . ' 00:00:00';
        $types .= 's';
    }
    if (!empty($end_date)) {
        $where_conditions[] = "m.data_envio <= ?";
        $params[] = $end_date . ' 23:59:59';
        $types .= 's';
    }
    
    $where_clause = "WHERE " . implode(' AND ', $where_conditions);

    $sql = "
        SELECT 
            m.chamado_id,
            m.data_envio,
            m.corpo_html
        FROM mensagens m
        $where_clause
        ORDER BY m.data_envio DESC
    ";
    
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();

        // Processa os dados para extrair 'de' e 'para'
        while ($row = $result->fetch_assoc()) {
            // Usa expressão regular para extrair os nomes
            if (preg_match('/de (.*?) para (.*?)</', $row['corpo_html'], $matches)) {
                $from_agent = trim(strip_tags($matches[1]));
                $to_agent = trim(strip_tags($matches[2]));

                $detailed_transfers[] = [
                    'chamado_id' => $row['chamado_id'],
                    'data_envio' => $row['data_envio'],
                    'from' => $from_agent,
                    'to' => $to_agent
                ];

                // Contabiliza para os rankings
                $transfers_from[$from_agent] = ($transfers_from[$from_agent] ?? 0) + 1;
                $transfers_to[$to_agent] = ($transfers_to[$to_agent] ?? 0) + 1;
            }
        }
        $stmt->close();
        
        // Ordena os rankings
        arsort($transfers_from);
        arsort($transfers_to);

    } else {
        throw new Exception("Erro ao preparar query: " . $conn->error);
    }

} catch (Exception $e) {
    $message = "<div class='alert alert-danger'>Erro ao buscar dados do relatório: " . $e->getMessage() . "</div>";
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Relatório Detalhado de Transferências - Sistema de Chamados</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background-color: #f8f9fa; }
        .container { margin-top: 50px; margin-bottom: 50px; max-width: 1200px; }
        .table-responsive { max-height: 500px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1>Relatório Detalhado de Transferências</h1>
            <a href="reports_dashboard.php" class="btn btn-secondary"><i class="bi bi-arrow-left-circle me-1"></i> Voltar</a>
        </div>

        <?php echo $message; ?>

        <div class="card mb-4">
            <div class="card-header">Filtrar por Período</div>
            <div class="card-body">
                <form action="reports_transfers.php" method="GET" class="row g-3 align-items-end">
                    <div class="col-md-4"><label for="start_date" class="form-label">Data Inicial:</label><input type="date" class="form-control" id="start_date" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>"></div>
                    <div class="col-md-4"><label for="end_date" class="form-label">Data Final:</label><input type="date" class="form-control" id="end_date" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>"></div>
                    <div class="col-md-4"><button type="submit" class="btn btn-primary me-2"><i class="bi bi-funnel"></i> Aplicar</button><a href="reports_transfers.php" class="btn btn-outline-secondary"><i class="bi bi-x-circle"></i> Limpar</a></div>
                </form>
            </div>
        </div>

        <div class="row mb-4">
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-header fw-bold"><i class="bi bi-arrow-up-right-circle-fill text-danger me-2"></i>Ranking: Quem Mais Transfere Chamados</div>
                    <ul class="list-group list-group-flush">
                        <?php if(empty($transfers_from)): ?>
                            <li class="list-group-item text-muted">Nenhum dado no período.</li>
                        <?php else: ?>
                            <?php foreach($transfers_from as $agent => $count): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <?php echo htmlspecialchars($agent); ?>
                                    <span class="badge bg-danger rounded-pill"><?php echo $count; ?></span>
                                </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-header fw-bold"><i class="bi bi-arrow-down-left-circle-fill text-success me-2"></i>Ranking: Quem Mais Recebe Chamados</div>
                    <ul class="list-group list-group-flush">
                        <?php if(empty($transfers_to)): ?>
                            <li class="list-group-item text-muted">Nenhum dado no período.</li>
                        <?php else: ?>
                            <?php foreach($transfers_to as $agent => $count): ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center">
                                    <?php echo htmlspecialchars($agent); ?>
                                    <span class="badge bg-success rounded-pill"><?php echo $count; ?></span>
                                </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h5><i class="bi bi-list-ol me-2"></i>Log de Todas as Transferências</h5></div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>Data</th>
                                <th>Chamado ID</th>
                                <th>Transferido De</th>
                                <th>Transferido Para</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($detailed_transfers)): ?>
                                <tr><td colspan="4" class="text-center text-muted py-4">Nenhuma transferência encontrada no período.</td></tr>
                            <?php else: ?>
                                <?php foreach ($detailed_transfers as $transfer): ?>
                                    <tr>
                                        <td><?php echo date('d/m/Y H:i', strtotime($transfer['data_envio'])); ?></td>
                                        <td><a href="view_ticket.php?id=<?php echo $transfer['chamado_id']; ?>" target="_blank">#<?php echo $transfer['chamado_id']; ?></a></td>
                                        <td><span class="badge bg-secondary"><?php echo htmlspecialchars($transfer['from']); ?></span></td>
                                        <td><span class="badge bg-primary"><?php echo htmlspecialchars($transfer['to']); ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>