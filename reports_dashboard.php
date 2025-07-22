<?php
require_once 'auth_check.php'; // Garante que o usuário está logado e a role está na sessão
require_once __DIR__ . '/config/config.php'; // Configurações do banco de dados

// Apenas administradores podem acessar esta página de dashboard de relatórios
if (!isset($_SESSION['usuario_role']) || $_SESSION['usuario_role'] !== 'admin') {
    header('Location: index.php?error=Acesso negado. Você não tem permissão para visualizar o painel de relatórios.');
    exit;
}
?>

<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Painel de Relatórios - Sistema de Chamados</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background-color: #f8f9fa; }
        .container { margin-top: 50px; margin-bottom: 50px; }
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
        }
        .report-card-link .card-body {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 150px; /* Garante altura mínima para os cards */
        }
        .report-card-link .card-body .bi {
            font-size: 3rem;
            margin-bottom: 10px;
            color: #0d6efd; /* Cor primária do Bootstrap */
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1>Painel de Relatórios</h1>
            <a href="index.php" class="btn btn-secondary"><i class="bi bi-arrow-left-circle me-1"></i> Voltar para Chamados</a>
        </div>

        <div class="row row-cols-1 row-cols-md-4 g-4">
            <div class="col">
                <a href="reports_status.php" class="report-card-link">
                    <div class="card h-100">
                        <div class="card-body">
                            <i class="bi bi-bar-chart-fill"></i>
                            <h5 class="card-title">Relatório por Status</h5>
                            <p class="card-text text-muted">Visão geral dos chamados por status (Aberto, Pendente, Fechado).</p>
                        </div>
                    </div>
                </a>
            </div>
            <div class="col">
                <a href="reports_agent.php" class="report-card-link">
                    <div class="card h-100">
                        <div class="card-body">
                            <i class="bi bi-person-check-fill"></i>
                            <h5 class="card-title">Relatório por Atendente</h5>
                            <p class="card-text text-muted">Desempenho e carga de trabalho de cada atendente.</p>
                        </div>
                    </div>
                </a>
            </div>
            <div class="col">
                <a href="reports_category.php" class="report-card-link">
                    <div class="card h-100">
                        <div class="card-body">
                            <i class="bi bi-tag-fill"></i>
                            <h5 class="card-title">Relatório por Categoria</h5>
                            <p class="card-text text-muted">Distribuição de chamados entre as categorias.</p>
                        </div>
                    </div>
                </a>
            </div>
            <div class="col">
                <a href="reports_time.php" class="report-card-link">
                    <div class="card h-100">
                        <div class="card-body">
                            <i class="bi bi-stopwatch-fill"></i>
                            <h5 class="card-title">Relatório de Tempos</h5>
                            <p class="card-text text-muted">Tempos médios de primeira resposta e resolução de chamados.</p>
                        </div>
                    </div>
                </a>
            </div>
            <div class="col">
                <a href="reports_client.php" class="report-card-link">
                    <div class="card h-100">
                        <div class="card-body">
                            <i class="bi bi-people-fill"></i>
                            <h5 class="card-title">Relatório por Cliente</h5>
                            <p class="card-text text-muted">Identifique os clientes que mais abrem chamados.</p>
                        </div>
                    </div>
                </a>
            </div>
            <div class="col">
    <a href="reports_agent_time.php" class="report-card-link">
        <div class="card h-100">
            <div class="card-body">
                <i class="bi bi-speedometer2"></i>
                <h5 class="card-title">Desempenho por Atendente</h5>
                <p class="card-text text-muted">Tempo médio de resolução dos chamados para cada atendente.</p>
            </div>
        </div>
    </a>
</div>
            <div class="col">
                <a href="reports_priority.php" class="report-card-link">
                    <div class="card h-100">
                        <div class="card-body">
                            <i class="bi bi-exclamation-triangle-fill"></i>
                            <h5 class="card-title">Relatório por Prioridade</h5>
                            <p class="card-text text-muted">Distribuição de chamados por nível de prioridade.</p>
                        </div>
                    </div>
                </a>
            </div>
            <div class="col">
                <a href="reports_messages.php" class="report-card-link">
                    <div class="card h-100">
                        <div class="card-body">
                            <i class="bi bi-chat-left-dots-fill"></i>
                            <h5 class="card-title">Relatório de Mensagens</h5>
                            <p class="card-text text-muted">Contagem de mensagens por tipo (recebidas, enviadas, internas).</p>
                        </div>
                    </div>
                </a>
            </div>
            <div class="col">
    <a href="reports_transfers.php" class="report-card-link">
        <div class="card h-100">
            <div class="card-body">
                <i class="bi bi-arrow-left-right"></i>
                <h5 class="card-title">Relatório de Transferências</h5>
                <p class="card-text text-muted">Veja quais atendentes mais transferem chamados.</p>
            </div>
        </div>
    </a>
</div>
            <div class="col">
                <a href="manage_auto_reply.php" class="report-card-link">
                    <div class="card h-100">
                        <div class="card-body">
                            <i class="bi bi-robot"></i>
                            <h5 class="card-title">Configurar Resposta Automática</h5>
                            <p class="card-text text-muted">Gerencie a mensagem e ativação da resposta inicial do sistema.</p>
                        </div>
                    </div>
                </a>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>